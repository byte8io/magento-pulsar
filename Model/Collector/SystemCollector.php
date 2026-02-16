<?php

declare(strict_types=1);

namespace Byte8\Pulsar\Model\Collector;

use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\App\Filesystem\DirectoryList;

class SystemCollector implements CollectorInterface
{
    private const DISK_WARNING_THRESHOLD_PCT = 20;
    private const DISK_CRITICAL_THRESHOLD_PCT = 10;

    public function __construct(
        private readonly ProductMetadataInterface $productMetadata,
        private readonly DirectoryList $directoryList
    ) {
    }

    public function getName(): string
    {
        return 'system';
    }

    public function collect(): array
    {
        $status = self::STATUS_HEALTHY;

        // PHP Info
        $phpVersion = PHP_VERSION;
        $memoryLimit = ini_get('memory_limit');
        $maxExecutionTime = (int) ini_get('max_execution_time');

        // Magento Info
        $magentoVersion = $this->productMetadata->getVersion();
        $magentoEdition = $this->productMetadata->getEdition();

        // Disk space for var directory
        $varDir = $this->directoryList->getPath(DirectoryList::VAR_DIR);
        $diskFree = @disk_free_space($varDir);
        $diskTotal = @disk_total_space($varDir);

        $diskFreePct = null;
        if ($diskFree !== false && $diskTotal !== false && $diskTotal > 0) {
            $diskFreePct = round(($diskFree / $diskTotal) * 100, 1);

            if ($diskFreePct < self::DISK_CRITICAL_THRESHOLD_PCT) {
                $status = self::STATUS_CRITICAL;
            } elseif ($diskFreePct < self::DISK_WARNING_THRESHOLD_PCT) {
                $status = self::STATUS_DEGRADED;
            }
        }

        // Memory usage
        $memoryUsage = memory_get_usage(true);
        $memoryPeak = memory_get_peak_usage(true);

        return [
            'status' => $status,
            'php_version' => $phpVersion,
            'magento_version' => $magentoVersion,
            'magento_edition' => $magentoEdition,
            'memory_limit' => $memoryLimit,
            'max_execution_time' => $maxExecutionTime,
            'disk_free_pct' => $diskFreePct,
            'disk_free_gb' => $diskFree !== false ? round($diskFree / 1024 / 1024 / 1024, 2) : null,
            'memory_usage_mb' => round($memoryUsage / 1024 / 1024, 2),
            'memory_peak_mb' => round($memoryPeak / 1024 / 1024, 2),
        ];
    }
}
