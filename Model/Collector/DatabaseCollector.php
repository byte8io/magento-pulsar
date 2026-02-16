<?php

declare(strict_types=1);

namespace Byte8\Pulsar\Model\Collector;

use Magento\Framework\App\ResourceConnection;

class DatabaseCollector implements CollectorInterface
{
    private const SLOW_CONNECTION_THRESHOLD_MS = 100;

    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    public function getName(): string
    {
        return 'database';
    }

    public function collect(): array
    {
        $startTime = microtime(true);
        $status = self::STATUS_HEALTHY;
        $error = null;

        try {
            $connection = $this->resourceConnection->getConnection();

            // Simple connectivity test
            $connection->fetchOne('SELECT 1');

            $connectionTimeMs = (int) round((microtime(true) - $startTime) * 1000);

            if ($connectionTimeMs > self::SLOW_CONNECTION_THRESHOLD_MS) {
                $status = self::STATUS_DEGRADED;
            }

            // Get some basic stats
            $variables = $connection->fetchPairs("SHOW STATUS WHERE Variable_name IN ('Threads_connected', 'Max_used_connections', 'Uptime')");

            return [
                'status' => $status,
                'connection_time_ms' => $connectionTimeMs,
                'threads_connected' => (int) ($variables['Threads_connected'] ?? 0),
                'max_used_connections' => (int) ($variables['Max_used_connections'] ?? 0),
                'uptime_seconds' => (int) ($variables['Uptime'] ?? 0),
            ];
        } catch (\Exception $e) {
            return [
                'status' => self::STATUS_CRITICAL,
                'connection_time_ms' => null,
                'error' => 'Database connection failed',
            ];
        }
    }
}
