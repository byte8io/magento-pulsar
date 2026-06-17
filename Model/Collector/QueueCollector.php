<?php

declare(strict_types=1);

namespace Byte8\Pulsar\Model\Collector;

use Magento\Framework\App\ResourceConnection;

class QueueCollector implements CollectorInterface
{
    // Magento MySQL queue message status values
    private const MSG_STATUS_NEW = 2;
    private const MSG_STATUS_IN_PROGRESS = 3;
    private const MSG_STATUS_COMPLETE = 4;
    private const MSG_STATUS_RETRY_REQUIRED = 5;
    private const MSG_STATUS_ERROR = 6;

    private const PENDING_WARNING_THRESHOLD = 1000;
    private const PENDING_CRITICAL_THRESHOLD = 5000;
    private const ERROR_WARNING_THRESHOLD = 50;
    private const ERROR_CRITICAL_THRESHOLD = 200;

    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    public function getName(): string
    {
        return 'queue';
    }

    public function collect(): array
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $statusTable = $this->resourceConnection->getTableName('queue_message_status');

            // MySQL queue tables may not exist if only AMQP is configured
            if (!$connection->isTableExists($statusTable)) {
                return [
                    'status' => self::STATUS_HEALTHY,
                    'backend' => 'amqp',
                    'note' => 'MySQL queue tables not present, AMQP backend likely in use',
                ];
            }

            // Count messages by status in a single query.
            // pending / in_progress are point-in-time backlog (NOT windowed): a
            // message stuck for days is more concerning, not less.
            $sql = sprintf('SELECT status, COUNT(*) as cnt FROM %s GROUP BY status', $statusTable);
            $rows = $connection->fetchPairs($sql);

            $pending = (int) ($rows[self::MSG_STATUS_NEW] ?? 0)
                + (int) ($rows[self::MSG_STATUS_RETRY_REQUIRED] ?? 0);
            $inProgress = (int) ($rows[self::MSG_STATUS_IN_PROGRESS] ?? 0);
            $errorsTotal = (int) ($rows[self::MSG_STATUS_ERROR] ?? 0);
            $completedTotal = (int) ($rows[self::MSG_STATUS_COMPLETE] ?? 0);

            // Cumulative-event metrics ARE windowed to the last 24h. ERROR rows
            // are retained by Magento's queue cleanup (unlike COMPLETE/NEW), so an
            // all-time count never recovers and pins the collector at "degraded"
            // forever after a single past incident. updated_at advances on each
            // status transition, so it reliably dates the error.
            $windowed = $connection->fetchRow(sprintf(
                'SELECT'
                . ' SUM(CASE WHEN status = %1$d THEN 1 ELSE 0 END) AS errors_24h,'
                . ' SUM(CASE WHEN status = %2$d THEN 1 ELSE 0 END) AS completed_24h'
                . ' FROM %3$s'
                . ' WHERE updated_at >= (NOW() - INTERVAL 24 HOUR)',
                self::MSG_STATUS_ERROR,
                self::MSG_STATUS_COMPLETE,
                $statusTable
            ));
            $errors24h = (int) ($windowed['errors_24h'] ?? 0);
            $completed24h = (int) ($windowed['completed_24h'] ?? 0);

            // Find the oldest unprocessed message to measure queue age
            $oldestPending = $connection->fetchOne(sprintf(
                'SELECT MIN(ms.updated_at) FROM %s ms'
                . ' WHERE ms.status IN (%d, %d)',
                $statusTable,
                self::MSG_STATUS_NEW,
                self::MSG_STATUS_RETRY_REQUIRED
            ));

            // Determine status from the windowed error rate and current backlog.
            $status = self::STATUS_HEALTHY;
            if ($pending > self::PENDING_CRITICAL_THRESHOLD || $errors24h > self::ERROR_CRITICAL_THRESHOLD) {
                $status = self::STATUS_CRITICAL;
            } elseif ($pending > self::PENDING_WARNING_THRESHOLD || $errors24h > self::ERROR_WARNING_THRESHOLD) {
                $status = self::STATUS_DEGRADED;
            }

            // Dead-consumer heuristic: messages are waiting but nothing has
            // completed or is in progress in the last 24h => consumers likely
            // not running. Rising pending alone can't distinguish "slow" from
            // "stopped"; zero throughput confirms "stopped".
            $consumersStalled = $pending > 0 && $completed24h === 0 && $inProgress === 0;
            if ($consumersStalled && $status === self::STATUS_HEALTHY) {
                $status = self::STATUS_DEGRADED;
            }

            return [
                'status' => $status,
                'backend' => 'mysql',
                'pending' => $pending,
                'in_progress' => $inProgress,
                'errors_24h' => $errors24h,         // windowed — drives status
                'errors_total' => $errorsTotal,     // all-time, context only
                'completed_24h' => $completed24h,   // throughput / dead-consumer signal
                'completed' => $completedTotal,
                'consumers_stalled' => $consumersStalled,
                'oldest_pending' => $oldestPending ?: null,
            ];
        } catch (\Exception $e) {
            return [
                'status' => self::STATUS_CRITICAL,
                'error' => 'Queue check failed: ' . $e->getMessage(),
            ];
        }
    }
}
