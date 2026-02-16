<?php

declare(strict_types=1);

namespace Byte8\Pulsar\Model\Collector;

use Magento\Framework\App\ResourceConnection;

class OrderCollector implements CollectorInterface
{
    private const STUCK_PENDING_HOURS = 4;
    private const STUCK_WARNING_THRESHOLD = 5;
    private const STUCK_CRITICAL_THRESHOLD = 20;
    private const FAILED_WARNING_THRESHOLD = 10;

    public function __construct(
        private readonly ResourceConnection $resourceConnection
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

            return [
                'status' => $status,
                'orders_24h' => $totalOrders24h,
                'stuck_pending_payment' => $stuckOrders,
                'failed_canceled_24h' => $failedOrders,
                'by_status_24h' => $byStatus,
            ];
        } catch (\Exception $e) {
            return [
                'status' => self::STATUS_CRITICAL,
                'error' => 'Order check failed',
            ];
        }
    }
}
