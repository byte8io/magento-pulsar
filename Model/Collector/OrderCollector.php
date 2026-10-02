<?php

declare(strict_types=1);

namespace Byte8\Pulsar\Model\Collector;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

class OrderCollector implements CollectorInterface
{
    private const STUCK_PENDING_HOURS = 4;
    private const STUCK_WARNING_THRESHOLD = 5;
    private const STUCK_CRITICAL_THRESHOLD = 20;
    private const FAILED_WARNING_THRESHOLD = 10;

    // --- Capture reconciliation ("shipped but never captured") ---------------
    // For deferred-capture flows (authorize → fulfil → capture on invoice/
    // shipment): German "capture on invoice", Mollie/Klarna manual capture,
    // or a connector (PlentyONE) that fulfils in an ERP and captures back in
    // Magento. If that capture silently fails the goods leave but the money is
    // never collected — and the authorization expires (~28d), after which it is
    // permanently lost. Principle: watch the money, not the errors — detect the
    // state regardless of which bug caused it.
    //
    // Real incident (getkapp.de): a Mollie upgrade dropped capture ~100%→~0%;
    // orders shipped in PlentyONE (no Magento shipment row at all) with
    // total_paid NULL for days. Detection therefore must NOT require a Magento
    // shipment, and must treat the capture deadline as the urgency driver.
    private const XML_PATH_UNCAPTURED_ENABLED = 'byte8_pulsar/checks/orders_uncaptured_enabled';
    private const XML_PATH_UNCAPTURED_METHODS = 'byte8_pulsar/checks/orders_uncaptured_methods';
    private const XML_PATH_UNCAPTURED_WINDOW_DAYS = 'byte8_pulsar/checks/orders_uncaptured_window_days';
    private const XML_PATH_UNCAPTURED_WARNING_DAYS = 'byte8_pulsar/checks/orders_uncaptured_warning_days';
    private const XML_PATH_UNCAPTURED_CRITICAL_DAYS = 'byte8_pulsar/checks/orders_uncaptured_critical_days';

    // Manual-capture window (card auth lifetime). Default mirrors Klarna/Mollie
    // ≈ 28 days from authorization; the deadline shown is created_at + window.
    private const DEFAULT_WINDOW_DAYS = 28;
    private const DEFAULT_WARNING_DAYS = 7;
    private const DEFAULT_CRITICAL_DAYS = 21;

    /**
     * Only consider orders from the last N days. Beyond this the money is almost
     * always unrecoverable; also bounds the query. 90d keeps an order visible
     * well past its capture deadline so a loss doesn't silently scroll away.
     */
    private const UNCAPTURED_LOOKBACK_DAYS = 90;
    private const UNCAPTURED_SAMPLE_LIMIT = 50;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function getName(): string
    {
        return 'orders';
    }

    public function collect(): array
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $orderTable = $this->resourceConnection->getTableName('sales_order');

            if (!$connection->isTableExists($orderTable)) {
                return [
                    'status' => self::STATUS_HEALTHY,
                    'note' => 'Sales order table not found',
                ];
            }

            $oneDayAgo = (new \DateTime())->modify('-24 hours')->format('Y-m-d H:i:s');
            $stuckThreshold = (new \DateTime())
                ->modify(sprintf('-%d hours', self::STUCK_PENDING_HOURS))
                ->format('Y-m-d H:i:s');

            // Orders in last 24h grouped by status
            $byStatus = $connection->fetchPairs(
                sprintf(
                    'SELECT status, COUNT(*) FROM %s WHERE created_at >= :since GROUP BY status',
                    $orderTable
                ),
                ['since' => $oneDayAgo]
            );

            $totalOrders24h = (int) array_sum($byStatus);

            // Orders stuck in pending_payment beyond threshold
            $stuckOrders = (int) $connection->fetchOne(
                sprintf(
                    'SELECT COUNT(*) FROM %s WHERE status = :status AND created_at < :threshold',
                    $orderTable
                ),
                ['status' => 'pending_payment', 'threshold' => $stuckThreshold]
            );

            // Failed/canceled/fraud in last 24h
            $failedOrders = (int) ($byStatus['canceled'] ?? 0)
                + (int) ($byStatus['fraud'] ?? 0)
                + (int) ($byStatus['payment_review'] ?? 0);

            // Determine status
            $status = self::STATUS_HEALTHY;
            if ($stuckOrders > self::STUCK_CRITICAL_THRESHOLD) {
                $status = self::STATUS_CRITICAL;
            } elseif ($stuckOrders > self::STUCK_WARNING_THRESHOLD || $failedOrders > self::FAILED_WARNING_THRESHOLD) {
                $status = self::STATUS_DEGRADED;
            }

            $result = [
                'status' => $status,
                'orders_24h' => $totalOrders24h,
                'stuck_pending_payment' => $stuckOrders,
                'failed_canceled_24h' => $failedOrders,
                'by_status_24h' => $byStatus,
            ];

            // Opt-in capture reconciliation.
            if ($this->scopeConfig->isSetFlag(self::XML_PATH_UNCAPTURED_ENABLED)) {
                $uncaptured = $this->collectUncapturedShipped($connection);
                $result['uncaptured_shipped'] = $uncaptured;

                if ($uncaptured['critical_count'] > 0) {
                    $result['status'] = self::STATUS_CRITICAL;
                } elseif ($uncaptured['warning_count'] > 0 && $result['status'] !== self::STATUS_CRITICAL) {
                    $result['status'] = self::STATUS_DEGRADED;
                }
            }

            return $result;
        } catch (\Exception $e) {
            return [
                'status' => self::STATUS_CRITICAL,
                'error' => 'Order check failed',
            ];
        }
    }

    /**
     * Find open orders that were authorized/fulfilled but never captured.
     *
     * An order is "at risk" when ALL of:
     *   - uncaptured: total_paid IS NULL/0 AND no `capture` payment transaction
     *     (never trust total_paid/total_invoiced alone — an offline Invoice::pay()
     *     sets them without any real gateway capture);
     *   - still open: state NOT IN (canceled, closed);
     *   - within the lookback window (default 90d);
     * AND there is positive evidence it *should* have been captured by now
     * (otherwise we can't distinguish a legitimate pay-later / open-invoice
     * order). Evidence is any of:
     *   - a Magento shipment exists, OR
     *   - the PlentyONE connector marks it shipped (plenty_order_entity.status_id
     *     in [7,8) — auto-detected only when that table is present), OR
     *   - an `authorization` payment transaction exists (money was reserved), OR
     *   - the payment method matches a configured deferred-capture pattern.
     *
     * Severity is keyed to age vs the manual-capture deadline, not to a count:
     * one order a day from losing its authorization is already critical.
     *
     * @return array{
     *   count:int, critical_count:int, warning_count:int,
     *   sum_at_risk:float, critical_sum_at_risk:float, base_currency:?string,
     *   window_days:int, warning_days:int, critical_days:int, lookback_days:int,
     *   sample:list<array<string,mixed>>
     * }
     */
    private function collectUncapturedShipped(AdapterInterface $connection): array
    {
        $windowDays = $this->positiveInt($this->scopeConfig->getValue(self::XML_PATH_UNCAPTURED_WINDOW_DAYS), self::DEFAULT_WINDOW_DAYS);
        $warningDays = $this->positiveInt($this->scopeConfig->getValue(self::XML_PATH_UNCAPTURED_WARNING_DAYS), self::DEFAULT_WARNING_DAYS);
        $criticalDays = $this->positiveInt($this->scopeConfig->getValue(self::XML_PATH_UNCAPTURED_CRITICAL_DAYS), self::DEFAULT_CRITICAL_DAYS);
        if ($criticalDays < $warningDays) {
            $criticalDays = $warningDays;
        }

        $lookbackStart = (new \DateTime())
            ->modify(sprintf('-%d days', self::UNCAPTURED_LOOKBACK_DAYS))
            ->format('Y-m-d H:i:s');

        $orderTable = $this->resourceConnection->getTableName('sales_order');
        $paymentTable = $this->resourceConnection->getTableName('sales_order_payment');
        $shipmentTable = $this->resourceConnection->getTableName('sales_shipment');
        $txnTable = $this->resourceConnection->getTableName('sales_payment_transaction');
        $plentyTable = $this->resourceConnection->getTableName('plenty_order_entity');

        $hasPlenty = $connection->isTableExists($plentyTable);
        $hasMollieColumn = $connection->tableColumnExists($orderTable, 'mollie_transaction_id');

        $bind = ['lookback_start' => $lookbackStart];

        // Positive-evidence clauses (OR'd together).
        $evidence = [
            sprintf('EXISTS (SELECT 1 FROM %s s WHERE s.order_id = o.entity_id)', $shipmentTable),
            sprintf(
                "EXISTS (SELECT 1 FROM %s ta WHERE ta.order_id = o.entity_id AND ta.txn_type = 'authorization')",
                $txnTable
            ),
        ];
        if ($hasPlenty) {
            $evidence[] = 'pe.status_id >= 7 AND pe.status_id < 8';
        }
        foreach ($this->configuredMethods() as $i => $pattern) {
            $key = 'method_' . $i;
            $evidence[] = sprintf('pay.method LIKE :%s', $key);
            $bind[$key] = '%' . $pattern . '%';
        }

        $plentyJoin = $hasPlenty
            ? sprintf(' LEFT JOIN %s pe ON pe.order_id = o.entity_id', $plentyTable)
            : '';

        // Shared FROM + WHERE for both the aggregate and the sample.
        $core = sprintf(
            ' FROM %1$s o'
            . ' INNER JOIN %2$s pay ON pay.parent_id = o.entity_id'
            . '%3$s'
            . ' WHERE o.base_grand_total > 0'
            . ' AND (o.total_paid IS NULL OR o.total_paid = 0)'
            . " AND o.state NOT IN ('canceled','closed')"
            . ' AND o.created_at > :lookback_start'
            . sprintf(
                " AND NOT EXISTS (SELECT 1 FROM %s tc WHERE tc.order_id = o.entity_id AND tc.txn_type = 'capture')",
                $txnTable
            )
            . ' AND (' . implode(' OR ', $evidence) . ')',
            $orderTable,
            $paymentTable,
            $plentyJoin
        );

        // $criticalDays/$warningDays are validated positive ints, inlined here
        // because a named placeholder reused across the query can trip PDO binding.
        $agg = $connection->fetchRow(
            'SELECT COUNT(*) AS total,'
            . ' COALESCE(SUM(o.base_grand_total), 0) AS sum_at_risk,'
            . sprintf(
                ' COALESCE(SUM(CASE WHEN DATEDIFF(NOW(), o.created_at) >= %1$d THEN 1 ELSE 0 END), 0) AS critical_count,'
                . ' COALESCE(SUM(CASE WHEN DATEDIFF(NOW(), o.created_at) >= %2$d'
                . ' AND DATEDIFF(NOW(), o.created_at) < %1$d THEN 1 ELSE 0 END), 0) AS warning_count,'
                . ' COALESCE(SUM(CASE WHEN DATEDIFF(NOW(), o.created_at) >= %1$d'
                . ' THEN o.base_grand_total ELSE 0 END), 0) AS critical_sum',
                $criticalDays,
                $warningDays
            )
            . $core,
            $bind
        ) ?: [];

        $total = (int) ($agg['total'] ?? 0);

        $sample = [];
        if ($total > 0) {
            $mollieSelect = $hasMollieColumn ? ', o.mollie_transaction_id' : '';
            $plentySelect = $hasPlenty ? ', pe.plenty_id, pe.status_id AS plenty_status' : '';

            $rows = $connection->fetchAll(
                'SELECT o.increment_id, o.store_id, o.created_at, o.base_grand_total,'
                . ' o.grand_total, o.order_currency_code, o.state, pay.method AS payment_method,'
                . ' DATEDIFF(NOW(), o.created_at) AS age_days,'
                . sprintf(
                    ' EXISTS (SELECT 1 FROM %s s WHERE s.order_id = o.entity_id) AS has_magento_shipment',
                    $shipmentTable
                )
                . $mollieSelect
                . $plentySelect
                . $core
                . ' ORDER BY o.created_at ASC'
                . ' LIMIT ' . self::UNCAPTURED_SAMPLE_LIMIT,
                $bind
            );

            foreach ($rows as $row) {
                $age = (int) $row['age_days'];
                $severity = $age >= $criticalDays
                    ? self::STATUS_CRITICAL
                    : ($age >= $warningDays ? self::STATUS_DEGRADED : 'info');

                $deadline = (new \DateTime($row['created_at']))
                    ->modify(sprintf('+%d days', $windowDays))
                    ->format('Y-m-d');

                $entry = [
                    'increment_id' => $row['increment_id'],
                    'store_id' => (int) $row['store_id'],
                    'payment_method' => $row['payment_method'],
                    'base_grand_total' => (float) $row['base_grand_total'],
                    'grand_total' => (float) $row['grand_total'],
                    'currency' => $row['order_currency_code'],
                    'state' => $row['state'],
                    'created_at' => $row['created_at'],
                    'age_days' => $age,
                    'capture_deadline' => $deadline,
                    'has_magento_shipment' => (bool) $row['has_magento_shipment'],
                    'severity' => $severity,
                ];
                if ($hasMollieColumn) {
                    $entry['mollie_transaction_id'] = $row['mollie_transaction_id'];
                }
                if ($hasPlenty) {
                    $entry['plenty_id'] = $row['plenty_id'];
                    $entry['plenty_status'] = $row['plenty_status'];
                }
                $sample[] = $entry;
            }
        }

        return [
            'count' => $total,
            'critical_count' => (int) ($agg['critical_count'] ?? 0),
            'warning_count' => (int) ($agg['warning_count'] ?? 0),
            'sum_at_risk' => round((float) ($agg['sum_at_risk'] ?? 0), 2),
            'critical_sum_at_risk' => round((float) ($agg['critical_sum'] ?? 0), 2),
            'base_currency' => $this->scopeConfig->getValue('currency/options/base') ?: null,
            'window_days' => $windowDays,
            'warning_days' => $warningDays,
            'critical_days' => $criticalDays,
            'lookback_days' => self::UNCAPTURED_LOOKBACK_DAYS,
            'sample' => $sample,
        ];
    }

    /**
     * Deferred-capture payment-method patterns the operator configured
     * (comma/newline separated, e.g. "mollie,klarna"). Used as one of the
     * positive-evidence signals; empty when unset.
     *
     * @return list<string>
     */
    private function configuredMethods(): array
    {
        $raw = (string) $this->scopeConfig->getValue(self::XML_PATH_UNCAPTURED_METHODS);
        return array_values(array_filter(array_map(
            'trim',
            (array) preg_split('/[,\n\r]+/', $raw)
        )));
    }

    private function positiveInt(mixed $value, int $default): int
    {
        $int = (int) $value;
        return $int > 0 ? $int : $default;
    }
}
