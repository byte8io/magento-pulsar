<?php

declare(strict_types=1);

namespace Byte8\Pulsar\Model\Collector;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Filesystem\DirectoryList;

class LogCollector implements CollectorInterface
{
    private const LOG_WARNING_BYTES = 524_288_000;    // 500 MB
    private const LOG_CRITICAL_BYTES = 2_147_483_648; // 2 GB
    private const REPORT_WARNING_COUNT = 100;
    private const REPORT_CRITICAL_COUNT = 1000;
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

        // Count error report files with a safety cap
        $reportCount = $this->countReportFiles($reportDir);

        // Determine status
        $status = self::STATUS_HEALTHY;
        if ($maxSize > self::LOG_CRITICAL_BYTES || $reportCount > self::REPORT_CRITICAL_COUNT) {
            $status = self::STATUS_CRITICAL;
        } elseif ($maxSize > self::LOG_WARNING_BYTES || $debugLogging || $reportCount > self::REPORT_WARNING_COUNT) {
            $status = self::STATUS_DEGRADED;
        }

        return [
            'status' => $status,
            'debug_logging_enabled' => $debugLogging,
            'log_sizes_mb' => $logSizes,
            'report_count' => $reportCount,
        ];
    }

    private function countReportFiles(string $reportDir): int
    {
        if (!is_dir($reportDir)) {
            return 0;
        }

        $count = 0;
        $iterator = new \FilesystemIterator($reportDir, \FilesystemIterator::SKIP_DOTS);
        foreach ($iterator as $_) {
            $count++;
            if ($count >= self::REPORT_COUNT_CAP) {
                break;
            }
        }

        return $count;
    }
}
