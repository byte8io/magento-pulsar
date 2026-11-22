<?php

declare(strict_types=1);

namespace Byte8\Pulsar\Test\Unit\Model\Funnel;

use Byte8\Pulsar\Model\Collector\CollectorInterface;
use Byte8\Pulsar\Model\Funnel\FunnelEvaluator;
use PHPUnit\Framework\TestCase;

class FunnelEvaluatorTest extends TestCase
{
    private FunnelEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new FunnelEvaluator();
    }

    /**
     * The getkapp.de 21–22 Nov 2026 incident: cart → shipping completion fell
     * from ~78% to ~66% for two days while every technical check was green.
     * On a typical day of ~300 carts that is a 15% relative drop at z ≈ 5 —
     * degraded, pointing at the shipping stage.
     */
    public function testGetkappShippingStepDropIsDegradedAtShippingStage(): void
    {
        $current = ['carts' => 300, 'shipping_completed' => 198, 'orders' => 150];
        $baseline = ['carts' => 8400, 'shipping_completed' => 6552, 'orders' => 4956];

        $r = $this->evaluator->evaluate($current, $baseline, 50, 10.0, 25.0);

        self::assertSame(CollectorInterface::STATUS_DEGRADED, $r['status']);
        self::assertSame(FunnelEvaluator::STAGE_CART_TO_SHIPPING, $r['worst_stage']);
        self::assertSame(66.0, $r['stages'][FunnelEvaluator::STAGE_CART_TO_SHIPPING]['current_pct']);
        self::assertSame(78.0, $r['stages'][FunnelEvaluator::STAGE_CART_TO_SHIPPING]['baseline_pct']);
        self::assertGreaterThan(2.0, $r['stages'][FunnelEvaluator::STAGE_CART_TO_SHIPPING]['z']);
        self::assertStringContainsString('cart → shipping method chosen', (string) $r['note']);
    }

    public function testSameDropOnThinTrafficIsNotSignificant(): void
    {
        // 66% of 50 carts vs 78% baseline: the drop is 15% relative but z ≈ 2.0,
        // and the degraded gate wants both the drop AND z >= 2 — borderline, so
        // make it clearly thin: 40 carts is under the minimum sample.
        $current = ['carts' => 40, 'shipping_completed' => 26, 'orders' => 20];
        $baseline = ['carts' => 8400, 'shipping_completed' => 6552, 'orders' => 4956];

        $r = $this->evaluator->evaluate($current, $baseline, 50, 10.0, 25.0);

        self::assertSame(CollectorInterface::STATUS_HEALTHY, $r['status']);
        self::assertFalse($r['sample_sufficient']);
        self::assertStringContainsString('too few', (string) $r['note']);
    }

    public function testSmallNoiseDropStaysHealthy(): void
    {
        // 76% vs 78%: 2.6% relative — well under the 10% gate.
        $current = ['carts' => 300, 'shipping_completed' => 228, 'orders' => 170];
        $baseline = ['carts' => 8400, 'shipping_completed' => 6552, 'orders' => 4956];

        $r = $this->evaluator->evaluate($current, $baseline, 50, 10.0, 25.0);

        self::assertSame(CollectorInterface::STATUS_HEALTHY, $r['status']);
        self::assertNull($r['worst_stage']);
        self::assertNull($r['note']);
    }

    public function testCollapseAtPaymentIsCriticalAtShippingToOrderStage(): void
    {
        // Shipping step fine, payment broken: 76% of carts choose shipping,
        // but only 20% of those place an order against a 75% baseline.
        $current = ['carts' => 300, 'shipping_completed' => 228, 'orders' => 46];
        $baseline = ['carts' => 8400, 'shipping_completed' => 6552, 'orders' => 4914];

        $r = $this->evaluator->evaluate($current, $baseline, 50, 10.0, 25.0);

        self::assertSame(CollectorInterface::STATUS_CRITICAL, $r['status']);
        self::assertSame(FunnelEvaluator::STAGE_SHIPPING_TO_ORDER, $r['worst_stage']);
    }

    public function testBaselineStillBuildingReportsHealthyWithNote(): void
    {
        $current = ['carts' => 300, 'shipping_completed' => 100, 'orders' => 10];
        $baseline = ['carts' => 120, 'shipping_completed' => 100, 'orders' => 80];

        $r = $this->evaluator->evaluate($current, $baseline, 50, 10.0, 25.0);

        self::assertSame(CollectorInterface::STATUS_HEALTHY, $r['status']);
        self::assertFalse($r['baseline_sufficient']);
        self::assertStringContainsString('Baseline still building', (string) $r['note']);
    }

    public function testImprovementIsNeverFlagged(): void
    {
        $current = ['carts' => 300, 'shipping_completed' => 270, 'orders' => 250];
        $baseline = ['carts' => 8400, 'shipping_completed' => 6552, 'orders' => 4956];

        $r = $this->evaluator->evaluate($current, $baseline, 50, 10.0, 25.0);

        self::assertSame(CollectorInterface::STATUS_HEALTHY, $r['status']);
        self::assertLessThan(0, $r['stages'][FunnelEvaluator::STAGE_CART_TO_SHIPPING]['drop_pct']);
    }

    public function testZeroDenominatorsDoNotDivide(): void
    {
        $r = $this->evaluator->evaluate(
            ['carts' => 0, 'shipping_completed' => 0, 'orders' => 0],
            ['carts' => 0, 'shipping_completed' => 0, 'orders' => 0],
            50,
            10.0,
            25.0
        );

        self::assertSame(CollectorInterface::STATUS_HEALTHY, $r['status']);
        self::assertNull($r['stages'][FunnelEvaluator::STAGE_CART_TO_ORDER]['current_pct']);
    }
}
