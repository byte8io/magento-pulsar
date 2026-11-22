<?php

declare(strict_types=1);

namespace Byte8\Pulsar\Model\Funnel;

use Byte8\Pulsar\Model\Collector\CollectorInterface;

/**
 * Pure comparison of a checkout funnel window against its baseline.
 *
 * Three stages are measured as conversion rates between adjacent counts:
 *
 *   carts ──► shipping_completed ──► orders
 *
 * A stage is flagged when its rate fell below the baseline rate by at least
 * the configured relative drop AND the fall is statistically unlikely to be
 * noise (two-proportion z-test). The second condition is what keeps a slow
 * Sunday morning from paging anyone: a 12-point drop on 30 carts is weather,
 * the same drop on 300 carts is an incident.
 *
 * Kept free of Magento dependencies so it can be unit tested with plain
 * PHPUnit and reused by the dashboard if the evaluation ever moves server-side.
 */
class FunnelEvaluator
{
    public const STAGE_CART_TO_SHIPPING = 'cart_to_shipping';
    public const STAGE_SHIPPING_TO_ORDER = 'shipping_to_order';
    public const STAGE_CART_TO_ORDER = 'cart_to_order';

    /** Minimum z-score for a drop to count as degraded / critical. */
    private const Z_DEGRADED = 2.0;
    private const Z_CRITICAL = 3.0;

    /**
     * @param array{carts: int, shipping_completed: int, orders: int} $current
     * @param array{carts: int, shipping_completed: int, orders: int} $baseline
     * @return array{
     *     status: string,
     *     sample_sufficient: bool,
     *     baseline_sufficient: bool,
     *     stages: array<string, array{
     *         current_pct: ?float, baseline_pct: ?float, drop_pct: ?float, z: ?float, status: string
     *     }>,
     *     worst_stage: ?string,
     *     note: ?string
     * }
     */
    public function evaluate(
        array $current,
        array $baseline,
        int $minCarts,
        float $degradedDropPct,
        float $criticalDropPct
    ): array {
        $sampleSufficient = $current['carts'] >= $minCarts;
        // The baseline has to be meaningfully bigger than one window, otherwise
        // we are comparing noise with noise. Ten windows' worth is the floor.
        $baselineSufficient = $baseline['carts'] >= max($minCarts * 10, 1);

        $pairs = [
            self::STAGE_CART_TO_SHIPPING => ['carts', 'shipping_completed'],
            self::STAGE_SHIPPING_TO_ORDER => ['shipping_completed', 'orders'],
            self::STAGE_CART_TO_ORDER => ['carts', 'orders'],
        ];

        $stages = [];
        $worst = CollectorInterface::STATUS_HEALTHY;
        $worstStage = null;
        $worstDrop = 0.0;

        foreach ($pairs as $stage => [$from, $to]) {
            $currentRate = self::rate($current[$to], $current[$from]);
            $baselineRate = self::rate($baseline[$to], $baseline[$from]);
            $entry = [
                'current_pct' => $currentRate === null ? null : round($currentRate * 100, 1),
                'baseline_pct' => $baselineRate === null ? null : round($baselineRate * 100, 1),
                'drop_pct' => null,
                'z' => null,
                'status' => CollectorInterface::STATUS_HEALTHY,
            ];

            if ($sampleSufficient && $baselineSufficient
                && $currentRate !== null && $baselineRate !== null && $baselineRate > 0
            ) {
                $drop = ($baselineRate - $currentRate) / $baselineRate * 100;
                $z = self::zScore(
                    $current[$to],
                    $current[$from],
                    $baseline[$to],
                    $baseline[$from]
                );
                $entry['drop_pct'] = round($drop, 1);
                $entry['z'] = round($z, 2);

                if ($drop >= $criticalDropPct && $z >= self::Z_CRITICAL) {
                    $entry['status'] = CollectorInterface::STATUS_CRITICAL;
                } elseif ($drop >= $degradedDropPct && $z >= self::Z_DEGRADED) {
                    $entry['status'] = CollectorInterface::STATUS_DEGRADED;
                }

                // The composite cart → order stage always shows the biggest
                // drop (it compounds the other two), so on a tie it must not
                // hide the adjacent stage that actually names the broken step.
                $isComposite = $stage === self::STAGE_CART_TO_ORDER;
                if (self::rank($entry['status']) > self::rank($worst)
                    || (!$isComposite
                        && self::rank($entry['status']) === self::rank($worst)
                        && $entry['status'] !== CollectorInterface::STATUS_HEALTHY
                        && $drop > $worstDrop)
                ) {
                    $worst = $entry['status'];
                    $worstStage = $stage;
                    $worstDrop = $drop;
                }
            }

            $stages[$stage] = $entry;
        }

        return [
            'status' => $worst,
            'sample_sufficient' => $sampleSufficient,
            'baseline_sufficient' => $baselineSufficient,
            'stages' => $stages,
            'worst_stage' => $worstStage,
            'note' => $this->note($worst, $worstStage, $stages, $sampleSufficient, $baselineSufficient, $current, $minCarts),
        ];
    }

    private static function rate(int $numerator, int $denominator): ?float
    {
        if ($denominator <= 0) {
            return null;
        }
        return min(1.0, $numerator / $denominator);
    }

    /**
     * Two-proportion z-test: how many standard errors the current rate sits
     * below the baseline rate. Positive = worse than baseline.
     */
    private static function zScore(int $k1, int $n1, int $k2, int $n2): float
    {
        if ($n1 <= 0 || $n2 <= 0) {
            return 0.0;
        }
        $p1 = min(1.0, $k1 / $n1);
        $p2 = min(1.0, $k2 / $n2);
        $pooled = min(1.0, ($k1 + $k2) / ($n1 + $n2));
        $se = sqrt($pooled * (1 - $pooled) * (1 / $n1 + 1 / $n2));
        if ($se <= 0.0) {
            return 0.0;
        }
        return ($p2 - $p1) / $se;
    }

    private static function rank(string $status): int
    {
        return match ($status) {
            CollectorInterface::STATUS_CRITICAL => 2,
            CollectorInterface::STATUS_DEGRADED => 1,
            default => 0,
        };
    }

    private static function label(string $stage): string
    {
        return match ($stage) {
            self::STAGE_CART_TO_SHIPPING => 'cart → shipping method chosen',
            self::STAGE_SHIPPING_TO_ORDER => 'shipping method chosen → order placed',
            default => 'cart → order placed',
        };
    }

    /**
     * @param array<string, array<string, mixed>> $stages
     * @param array{carts: int, shipping_completed: int, orders: int} $current
     */
    private function note(
        string $status,
        ?string $worstStage,
        array $stages,
        bool $sampleSufficient,
        bool $baselineSufficient,
        array $current,
        int $minCarts
    ): ?string {
        if (!$sampleSufficient) {
            return sprintf(
                'Only %d carts in the window (minimum %d) — too few to judge conversion.',
                $current['carts'],
                $minCarts
            );
        }
        if (!$baselineSufficient) {
            return 'Baseline still building — the collector needs more history before it can flag a drop.';
        }
        if ($status === CollectorInterface::STATUS_HEALTHY || $worstStage === null) {
            return null;
        }
        $s = $stages[$worstStage];
        return sprintf(
            'Conversion %s fell to %.1f%% from a %.1f%% baseline (−%.0f%% relative, z=%.1f). '
            . 'Customers are dropping out at this step; check the storefront for that stage.',
            self::label($worstStage),
            $s['current_pct'],
            $s['baseline_pct'],
            $s['drop_pct'],
            $s['z']
        );
    }
}
