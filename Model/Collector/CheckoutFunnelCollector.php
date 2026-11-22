<?php

declare(strict_types=1);

namespace Byte8\Pulsar\Model\Collector;

use Byte8\Pulsar\Model\Config;
use Byte8\Pulsar\Model\Funnel\FunnelEvaluator;
use Byte8\Pulsar\Model\Synthetic\SyntheticIdentity;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * Checkout funnel: measures how many carts reach a chosen shipping method and
 * how many of those become orders, and compares the trailing window against
 * the store's own baseline.
 *
 * This is the collector for the class of incident a synthetic checkout flow
 * cannot see: the storefront is up, the flow passes, and yet one customer in
 * eight silently gives up at the shipping step because an age gate, a carrier
 * rate call or an address validator misbehaves for *their* input. Real case
 * (getkapp.de, 21–22 Nov 2026): cart → shipping-step completion fell from
 * 75–80% to 66% for two days with every technical check green.
 *
 * Counts come straight from the quote / quote_address / sales_order tables:
 *
 *   carts               quotes created in the window with at least one item
 *   shipping_completed  of those, quotes whose shipping address carries a
 *                       shipping_method (set when the customer picks a carrier
 *                       on the shipping step, or estimates shipping in cart)
 *   orders              orders placed in the window
 *
 * quote.created_at is not indexed, so the window is first narrowed to an
 * entity_id lower bound found by binary search over the primary key; the
 * range scan that follows touches only the window's rows. Results are cached
 * (window 5 min, baseline 6 h) so polling frequency never dictates DB load.
 */
class CheckoutFunnelCollector implements CollectorInterface
{
    private const CACHE_KEY_WINDOW = 'pulsar_checkout_funnel_window';
    private const CACHE_KEY_BASELINE = 'pulsar_checkout_funnel_baseline';
    private const CACHE_TTL_WINDOW = 300;
    private const CACHE_TTL_BASELINE = 21600;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly Config $config,
        private readonly CacheInterface $cache,
        private readonly FunnelEvaluator $evaluator
    ) {
    }

    public function getName(): string
    {
        return 'checkout_funnel';
    }

    public function collect(): array
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $quoteTable = $this->resourceConnection->getTableName('quote');
            $addressTable = $this->resourceConnection->getTableName('quote_address');
            $orderTable = $this->resourceConnection->getTableName('sales_order');

            foreach ([$quoteTable, $addressTable, $orderTable] as $table) {
                if (!$connection->isTableExists($table)) {
                    return ['status' => self::STATUS_HEALTHY, 'note' => sprintf('Table %s not found', $table)];
                }
            }

            $windowHours = $this->config->getCheckoutFunnelWindowHours();
            $baselineDays = $this->config->getCheckoutFunnelBaselineDays();
            $minCarts = $this->config->getCheckoutFunnelMinCarts();
            $degradedPct = $this->config->getCheckoutFunnelDegradedDropPct();
            $criticalPct = $this->config->getCheckoutFunnelCriticalDropPct();

            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $windowStart = $now->modify(sprintf('-%d hours', $windowHours));
            $baselineStart = $windowStart->modify(sprintf('-%d days', $baselineDays));

            $window = $this->cached(
                self::CACHE_KEY_WINDOW,
                self::CACHE_TTL_WINDOW,
                fn (): array => $this->counts($connection, $quoteTable, $addressTable, $orderTable, $windowStart, $now)
            );
            $baseline = $this->cached(
                self::CACHE_KEY_BASELINE,
                self::CACHE_TTL_BASELINE,
                fn (): array => $this->counts(
                    $connection,
                    $quoteTable,
                    $addressTable,
                    $orderTable,
                    $baselineStart,
                    $windowStart
                )
            );

            $evaluation = $this->evaluator->evaluate($window, $baseline, $minCarts, $degradedPct, $criticalPct);

            $result = [
                'status' => $evaluation['status'],
                'window_hours' => $windowHours,
                'baseline_days' => $baselineDays,
                'carts' => $window['carts'],
                'shipping_completed' => $window['shipping_completed'],
                'orders' => $window['orders'],
                'cart_to_shipping_pct' => $evaluation['stages'][FunnelEvaluator::STAGE_CART_TO_SHIPPING]['current_pct'],
                'shipping_to_order_pct' => $evaluation['stages'][FunnelEvaluator::STAGE_SHIPPING_TO_ORDER]['current_pct'],
                'cart_to_order_pct' => $evaluation['stages'][FunnelEvaluator::STAGE_CART_TO_ORDER]['current_pct'],
                'baseline_cart_to_shipping_pct' => $evaluation['stages'][FunnelEvaluator::STAGE_CART_TO_SHIPPING]['baseline_pct'],
                'baseline_shipping_to_order_pct' => $evaluation['stages'][FunnelEvaluator::STAGE_SHIPPING_TO_ORDER]['baseline_pct'],
                'baseline_cart_to_order_pct' => $evaluation['stages'][FunnelEvaluator::STAGE_CART_TO_ORDER]['baseline_pct'],
                'worst_stage' => $evaluation['worst_stage'],
                'worst_stage_drop_pct' => $evaluation['worst_stage'] === null
                    ? null
                    : $evaluation['stages'][$evaluation['worst_stage']]['drop_pct'],
                'sample_sufficient' => $evaluation['sample_sufficient'],
                'baseline_sufficient' => $evaluation['baseline_sufficient'],
                'stages' => $evaluation['stages'],
            ];
            if ($evaluation['note'] !== null) {
                $result['note'] = $evaluation['note'];
            }

            return $result;
        } catch (\Exception $e) {
            return [
                'status' => self::STATUS_DEGRADED,
                'error' => 'Checkout funnel check failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * @return array{carts: int, shipping_completed: int, orders: int}
     */
    private function counts(
        AdapterInterface $connection,
        string $quoteTable,
        string $addressTable,
        string $orderTable,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to
    ): array {
        $fromStr = $from->format('Y-m-d H:i:s');
        $toStr = $to->format('Y-m-d H:i:s');

        $lowerQuoteId = $this->lowerBoundId($connection, $quoteTable, 'entity_id', 'created_at', $fromStr);

        $quoteRow = $connection->fetchRow(
            sprintf(
                'SELECT
                    COUNT(*) AS carts,
                    COALESCE(SUM(a.shipping_method IS NOT NULL AND a.shipping_method <> \'\'), 0) AS shipping_completed
                 FROM %s q
                 LEFT JOIN %s a ON a.quote_id = q.entity_id AND a.address_type = \'shipping\'
                 WHERE q.entity_id >= :lower_id
                   AND q.created_at >= :from_at AND q.created_at < :to_at
                   AND q.items_count > 0
                   AND (q.customer_email IS NULL OR q.customer_email NOT LIKE :synthetic)',
                $quoteTable,
                $addressTable
            ),
            [
                'lower_id' => $lowerQuoteId,
                'from_at' => $fromStr,
                'to_at' => $toStr,
                'synthetic' => SyntheticIdentity::EMAIL_LIKE,
            ]
        );

        // Pulsar's own checkout runs are tagged with the synthetic shopper
        // email (Observer\TagSyntheticQuote) and must not count as carts.
        $orders = (int) $connection->fetchOne(
            sprintf(
                'SELECT COUNT(*) FROM %s
                 WHERE created_at >= :from_at AND created_at < :to_at
                   AND (customer_email IS NULL OR customer_email NOT LIKE :synthetic)',
                $orderTable
            ),
            ['from_at' => $fromStr, 'to_at' => $toStr, 'synthetic' => SyntheticIdentity::EMAIL_LIKE]
        );

        return [
            'carts' => (int) ($quoteRow['carts'] ?? 0),
            'shipping_completed' => (int) ($quoteRow['shipping_completed'] ?? 0),
            'orders' => $orders,
        ];
    }

    /**
     * Smallest primary key whose timestamp column is at or after $since,
     * found by binary search using only primary-key reads. Auto-increment ids
     * grow with creation time, so this bounds an un-indexed created_at range
     * scan to the rows that can possibly match. Falls back to 0 (full range)
     * if the table is empty.
     */
    private function lowerBoundId(
        AdapterInterface $connection,
        string $table,
        string $pk,
        string $column,
        string $since
    ): int {
        $bounds = $connection->fetchRow(sprintf('SELECT MIN(%1$s) AS lo, MAX(%1$s) AS hi FROM %2$s', $pk, $table));
        if (!$bounds || $bounds['lo'] === null) {
            return 0;
        }
        $lo = (int) $bounds['lo'];
        $hi = (int) $bounds['hi'];

        $probe = sprintf(
            'SELECT %2$s FROM %3$s WHERE %1$s >= :id ORDER BY %1$s ASC LIMIT 1',
            $pk,
            $column,
            $table
        );

        // Invariant: every row with id < lo is older than $since (or lo is MIN).
        $iterations = 0;
        while ($lo < $hi && $iterations < 64) {
            $iterations++;
            $mid = $lo + intdiv($hi - $lo, 2);
            $at = $connection->fetchOne($probe, ['id' => $mid]);
            if ($at === false || $at === null || (string) $at < $since) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }

        return $lo;
    }

    /**
     * @param callable(): array{carts: int, shipping_completed: int, orders: int} $compute
     * @return array{carts: int, shipping_completed: int, orders: int}
     */
    private function cached(string $key, int $ttl, callable $compute): array
    {
        $raw = $this->cache->load($key);
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && isset($decoded['carts'], $decoded['shipping_completed'], $decoded['orders'])) {
                return [
                    'carts' => (int) $decoded['carts'],
                    'shipping_completed' => (int) $decoded['shipping_completed'],
                    'orders' => (int) $decoded['orders'],
                ];
            }
        }
        $value = $compute();
        $this->cache->save(json_encode($value), $key, [], $ttl);
        return $value;
    }
}
