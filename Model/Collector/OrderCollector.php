<?php

declare(strict_types=1);

namespace Byte8\Pulsar\Model\Collector;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;

class OrderCollector implements CollectorInterface
{
    private const STUCK_PENDING_HOURS = 4;
    private const STUCK_WARNING_THRESHOLD = 5;
    private const STUCK_CRITICAL_THRESHOLD = 20;
    private const FAILED_WARNING_THRESHOLD = 10;

    // Deferred-capture (authorize → ship → capture) monitoring.
    // Relevant to merchants whose flow captures payment at/after shipment —
    // e.g. German "capture on invoice/shipment", Mollie capture-on-shipment,
    // or a connector (PlentyONE) creating the invoice programmatically. If that
    // capture silently fails the goods leave the warehouse but money is never
    // collected. Opt-in: off by default, since immediate-capture stores never
    // see an authorization-without-capture order.
    private const XML_PATH_UNCAPTURED_ENABLED = 'byte8_pulsar/checks/orders_uncaptured_enabled';
    private const XML_PATH_UNCAPTURED_HOURS = 'byte8_pulsar/checks/orders_uncaptured_hours';
    private const XML_PATH_UNCAPTURED_WARNING = 'byte8_pulsar/checks/orders_uncaptured_warning';
    private const XML_PATH_UNCAPTURED_CRITICAL = 'byte8_pulsar/checks/orders_uncaptured_critical';

    private const DEFAULT_UNCAPTURED_HOURS = 24;
    private const DEFAULT_UNCAPTURED_WARNING = 1;
    private const DEFAULT_UNCAPTURED_CRITICAL = 5;

    /**
     * Only consider shipments from the last N days. Card authorizations expire
     * within ~7–28 days, so older uncaptured orders are usually unrecoverable
     * and would otherwise be permanent noise; this also bounds the join cost.
     */
    private const UNCAPTURED_LOOKBACK_DAYS = 30;
    private const UNCAPTURED_SAMPLE_LIMIT = 20;

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

            // Opt-in deferred-capture check: shipped but never captured.
            if ($this->scopeConfig->isSetFlag(self::XML_PATH_UNCAPTURED_ENABLED)) {
                $uncaptured = $this->collectUncapturedShipped($connection);
                $result['uncaptured_shipped'] = $uncaptured;

                $warning = max(1, (int) ($this->scopeConfig->getValue(self::XML_PATH_UNCAPTURED_WARNING)
                    ?: self::DEFAULT_UNCAPTURED_WARNING));
                $critical = max($warning, (int) ($this->scopeConfig->getValue(self::XML_PATH_UNCAPTURED_CRITICAL)
                    ?: self::DEFAULT_UNCAPTURED_CRITICAL));

                $count = $uncaptured['count'];
                if ($count >= $critical) {
                    $result['status'] = self::STATUS_CRITICAL;
                } elseif ($count >= $warning && $result['status'] !== self::STATUS_CRITICAL) {
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
     * Find orders that were shipped but whose payment was authorized and never
     * captured — the deferred-capture failure mode (authorize → ship → capture).
     *
     * Detection relies on payment *transactions*, not total_paid/total_invoiced:
     * an offline invoice inflates total_paid without any real gateway capture
     * (see the payment-integrity gap), so the reliable signal is an
     * `authorization` transaction present with no matching `capture`. Requiring
     * the authorization also excludes genuine pay-later methods (bank transfer,
     * open invoice) that never authorize online, keeping false positives near
     * zero for stores that don't use deferred capture.
     *
     * Age is measured from the first shipment: the goods have physically left
     * more than `hours` ago yet no money has been taken.
     *
     * @return array{count: int, window_hours: int, lookback_days: int, sample: list<array<string, mixed>>}
     */
    private function collectUncapturedShipped(\Magento\Framework\DB\Adapter\AdapterInterface $connection): array
    {
        $hours = (int) ($this->scopeConfig->getValue(self::XML_PATH_UNCAPTURED_HOURS)
            ?: self::DEFAULT_UNCAPTURED_HOURS);
        $hours = max(1, $hours);

        $now = new \DateTime();
        $threshold = (clone $now)->modify(sprintf('-%d hours', $hours))->format('Y-m-d H:i:s');
        $lookbackStart = (clone $now)
            ->modify(sprintf('-%d days', self::UNCAPTURED_LOOKBACK_DAYS))
            ->format('Y-m-d H:i:s');

        $orderTable = $this->resourceConnection->getTableName('sales_order');
        $paymentTable = $this->resourceConnection->getTableName('sales_order_payment');
        $shipmentTable = $this->resourceConnection->getTableName('sales_shipment');
        $txnTable = $this->resourceConnection->getTableName('sales_payment_transaction');

        $bind = [
            'lookback_start' => $lookbackStart,
            'threshold' => $threshold,
        ];

        // Shared FROM/JOIN/WHERE for both the count and the sample. The payment
        // join is 1:1 per order so it doesn't affect the count. HAVING on
        // MIN(shipment.created_at) ensures the FIRST shipment is older than the
        // window (not merely that some later shipment is).
        $core = sprintf(
            ' FROM %1$s o'
            . ' INNER JOIN %4$s pay ON pay.parent_id = o.entity_id'
            . ' INNER JOIN %2$s sh ON sh.order_id = o.entity_id'
            . ' INNER JOIN %3$s ta ON ta.order_id = o.entity_id AND ta.txn_type = \'authorization\''
            . ' LEFT JOIN %3$s tc ON tc.order_id = o.entity_id AND tc.txn_type = \'capture\''
            . ' WHERE o.base_grand_total > 0'
            . ' AND tc.transaction_id IS NULL'
            . ' AND sh.created_at >= :lookback_start',
            $orderTable,
            $shipmentTable,
            $txnTable,
            $paymentTable
        );

        $count = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM ('
            . ' SELECT o.entity_id' . $core
            . ' GROUP BY o.entity_id HAVING MIN(sh.created_at) < :threshold'
            . ') t',
            $bind
        );

        $sample = [];
        if ($count > 0) {
            $rows = $connection->fetchAll(
                'SELECT o.increment_id, o.store_id, o.base_grand_total, o.base_total_invoiced, o.order_currency_code,'
                . ' pay.method AS payment_method,'
                . ' MIN(sh.created_at) AS first_shipped_at, MAX(ta.created_at) AS authorized_at'
                . $core
                . ' GROUP BY o.entity_id, o.increment_id, o.store_id, o.base_grand_total,'
                . ' o.base_total_invoiced, o.order_currency_code, pay.method'
                . ' HAVING MIN(sh.created_at) < :threshold'
                . ' ORDER BY first_shipped_at ASC'
                . ' LIMIT ' . self::UNCAPTURED_SAMPLE_LIMIT,
                $bind
            );

            foreach ($rows as $row) {
                $sample[] = [
                    'increment_id' => $row['increment_id'],
                    'store_id' => (int) $row['store_id'],
                    'payment_method' => $row['payment_method'],
                    'base_grand_total' => (float) $row['base_grand_total'],
                    'base_total_invoiced' => $row['base_total_invoiced'] !== null
                        ? (float) $row['base_total_invoiced']
                        : null,
                    'currency' => $row['order_currency_code'],
                    'first_shipped_at' => $row['first_shipped_at'],
                    'authorized_at' => $row['authorized_at'],
                ];
            }
        }

        return [
            'count' => $count,
            'window_hours' => $hours,
            'lookback_days' => self::UNCAPTURED_LOOKBACK_DAYS,
            'sample' => $sample,
        ];
    }
}
