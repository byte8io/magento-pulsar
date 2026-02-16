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

            // Count messages by status in a single query
            $sql = sprintf('SELECT status, COUNT(*) as cnt FROM %s GROUP BY status', $statusTable);
            $rows = $connection->fetchPairs($sql);

            $pending = (int) ($rows[self::MSG_STATUS_NEW] ?? 0)
                + (int) ($rows[self::MSG_STATUS_RETRY_REQUIRED] ?? 0);
            $inProgress = (int) ($rows[self::MSG_STATUS_IN_PROGRESS] ?? 0);
            $errors = (int) ($rows[self::MSG_STATUS_ERROR] ?? 0);
            $completed = (int) ($rows[self::MSG_STATUS_COMPLETE] ?? 0);

            // Find the oldest unprocessed message to measure queue age
            $messageTable = $this->resourceConnection->getTableName('queue_message');
            $oldestPending = $connection->fetchOne(sprintf(
                'SELECT MIN(m.updated_at) FROM %s ms'
                . ' JOIN %s m ON ms.message_id = m.id'
                . ' WHERE ms.status IN (%d, %d)',
                $statusTable,
                $messageTable,
                self::MSG_STATUS_NEW,
                self::MSG_STATUS_RETRY_REQUIRED
            ));

            // Determine status
            $status = self::STATUS_HEALTHY;
            if ($pending > self::PENDING_CRITICAL_THRESHOLD || $errors > self::ERROR_CRITICAL_THRESHOLD) {
                $status = self::STATUS_CRITICAL;
            } elseif ($pending > self::PENDING_WARNING_THRESHOLD || $errors > self::ERROR_WARNING_THRESHOLD) {
                $status = self::STATUS_DEGRADED;
            }

            return [
                'status' => $status,
                'backend' => 'mysql',
                'pending' => $pending,
                'in_progress' => $inProgress,
                'errors' => $errors,
                'completed' => $completed,
                'oldest_pending' => $oldestPending ?: null,
            ];
        } catch (\Exception $e) {
            return [
                'status' => self::STATUS_CRITICAL,
                'error' => 'Queue check failed',
            ];
        }
    }
}
