<?php

declare(strict_types=1);

namespace Byte8\Pulsar\Model\Collector;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Filesystem\DirectoryList;

/**
 * Log growth and error-report accumulation.
 *
 * Error reports are judged on how many were written *recently*, not on how
 * many sit in var/report in total: one bad afternoon months ago should not
 * keep the collector amber until somebody remembers to run find -delete.
 * The total is still reported, with a housekeeping note when it is large.
 */
class LogCollector implements CollectorInterface
{
    private const LOG_WARNING_BYTES = 524_288_000;    // 500 MB
    private const LOG_CRITICAL_BYTES = 2_147_483_648; // 2 GB

    /** Error reports written in the last REPORT_RECENT_HOURS. */
    private const REPORT_RECENT_HOURS = 24;
    private const REPORT_RECENT_WARNING_COUNT = 50;
    private const REPORT_RECENT_CRITICAL_COUNT = 200;

    /** Total reports on disk — informational; nudges housekeeping. */
    private const REPORT_TOTAL_HOUSEKEEPING_COUNT = 1000;
    private const REPORT_COUNT_CAP = 10_000;

    private const MONITORED_LOGS = [
        'exception.log',
        'system.log',
        'debug.log',
    ];

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly DirectoryList $directoryList
    ) {
    }

    public function getName(): string
    {
        return 'log';
    }

    public function collect(): array
    {
        $varDir = $this->directoryList->getPath(DirectoryList::VAR_DIR);
        $logDir = $varDir . '/log';
        $reportDir = $varDir . '/report';

        // Check key log file sizes
        $logSizes = [];
        $maxSize = 0;
        foreach (self::MONITORED_LOGS as $logFile) {
            $path = $logDir . '/' . $logFile;
            $size = @filesize($path);
            if ($size !== false) {
                $logSizes[$logFile] = round($size / 1024 / 1024, 2);
                $maxSize = max($maxSize, $size);
            }
        }

        // Check if debug logging is enabled (should be off in production)
        $debugLogging = $this->scopeConfig->isSetFlag('dev/debug/debug_logging_enabled');

        [$reportCount, $recentReportCount] = $this->countReportFiles(
            $reportDir,
            time() - self::REPORT_RECENT_HOURS * 3600
        );

        // Determine status
        $status = self::STATUS_HEALTHY;
        if ($maxSize > self::LOG_CRITICAL_BYTES || $recentReportCount > self::REPORT_RECENT_CRITICAL_COUNT) {
            $status = self::STATUS_CRITICAL;
        } elseif ($maxSize > self::LOG_WARNING_BYTES
            || $debugLogging
            || $recentReportCount > self::REPORT_RECENT_WARNING_COUNT
        ) {
            $status = self::STATUS_DEGRADED;
        }

        $result = [
            'status' => $status,
            'debug_logging_enabled' => $debugLogging,
            'log_sizes_mb' => $logSizes,
            'report_count' => $reportCount,
            'report_count_24h' => $recentReportCount,
        ];

        if ($reportCount >= self::REPORT_TOTAL_HOUSEKEEPING_COUNT
            && $recentReportCount <= self::REPORT_RECENT_WARNING_COUNT
        ) {
            $result['note'] = sprintf(
                '%d error reports accumulated in var/report (only %d in the last %dh). '
                . 'Old reports no longer affect status; clear them with '
                . 'find var/report -type f -mtime +30 -delete.',
                $reportCount,
                $recentReportCount,
                self::REPORT_RECENT_HOURS
            );
        }

        return $result;
    }

    /**
     * Total report files (capped) and how many were modified since $recentSince.
     * Recurses because REST/GraphQL reports land in var/report/api/.
     *
     * @return array{0: int, 1: int}
     */
    private function countReportFiles(string $reportDir, int $recentSince): array
    {
        if (!is_dir($reportDir)) {
            return [0, 0];
        }

        $count = 0;
        $recent = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($reportDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $count++;
            if ($file->getMTime() >= $recentSince) {
                $recent++;
            }
            if ($count >= self::REPORT_COUNT_CAP) {
                break;
            }
        }

        return [$count, $recent];
    }
}
