<?php

declare(strict_types=1);

namespace Byte8\Pulsar\Model\Collector;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;

class DatabaseSizeCollector implements CollectorInterface
{
    private const CACHE_KEY = 'pulsar_database_size';
    private const CACHE_TTL = 86400; // 24 hours
    private const TOP_TABLES_LIMIT = 10;

    // Default thresholds in MB
    private const WARNING_THRESHOLD_MB = 5120;  // 5 GB
    private const CRITICAL_THRESHOLD_MB = 10240; // 10 GB

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly CacheInterface $cache
    ) {
    }

    public function getName(): string
    {
        return 'database_size';
    }

    public function collect(): array
    {
        $cached = $this->cache->load(self::CACHE_KEY);
        if ($cached) {
            $data = json_decode($cached, true);
            if (is_array($data)) {
                $data['cached'] = true;
                return $data;
            }
        }

        try {
            $connection = $this->resourceConnection->getConnection();
            $dbName = $connection->fetchOne('SELECT DATABASE()');

            // Total database size
            $sizeResult = $connection->fetchRow(
                "SELECT
                    ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS total_mb,
                    COUNT(*) AS table_count
                FROM information_schema.TABLES
                WHERE table_schema = ?",
                [$dbName]
            );

            $totalMb = (float) ($sizeResult['total_mb'] ?? 0);
            $tableCount = (int) ($sizeResult['table_count'] ?? 0);

            // Top N largest tables
            $topTables = $connection->fetchAll(
                "SELECT
                    table_name,
                    ROUND((data_length + index_length) / 1024 / 1024, 2) AS size_mb,
                    table_rows
                FROM information_schema.TABLES
                WHERE table_schema = ?
                ORDER BY (data_length + index_length) DESC
                LIMIT " . self::TOP_TABLES_LIMIT,
                [$dbName]
            );

            $tables = [];
            foreach ($topTables as $row) {
                $tables[] = [
                    'table' => $row['table_name'] ?? $row['TABLE_NAME'],
                    'size_mb' => (float) ($row['size_mb'] ?? $row['SIZE_MB'] ?? 0),
                    'rows' => (int) ($row['table_rows'] ?? $row['TABLE_ROWS'] ?? 0),
                ];
            }

            $status = self::STATUS_HEALTHY;
            if ($totalMb >= self::CRITICAL_THRESHOLD_MB) {
                $status = self::STATUS_CRITICAL;
            } elseif ($totalMb >= self::WARNING_THRESHOLD_MB) {
                $status = self::STATUS_DEGRADED;
            }

            $data = [
                'status' => $status,
                'total_size_mb' => $totalMb,
                'table_count' => $tableCount,
                'top_tables' => $tables,
                'warning_threshold_mb' => self::WARNING_THRESHOLD_MB,
                'critical_threshold_mb' => self::CRITICAL_THRESHOLD_MB,
                'cached' => false,
            ];

            $this->cache->save(
                json_encode($data),
                self::CACHE_KEY,
                [],
                self::CACHE_TTL
            );

            return $data;
        } catch (\Exception $e) {
            return [
                'status' => self::STATUS_CRITICAL,
                'error' => 'Failed to query database size',
            ];
        }
    }
}
