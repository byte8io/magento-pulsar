<?php

declare(strict_types=1);

namespace Byte8\Pulsar\Model\Collector;

use Magento\Framework\App\Filesystem\DirectoryList;

class MediaIntegrityCollector implements CollectorInterface
{
    /**
     * PHP-executable extensions that should never exist in pub/media/.
     * Matches the extensions found in the Steelman24 compromise:
     * .php, .php3, .php4, .php5, .php8, .phtml, .inc
     */
    private const EXECUTABLE_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8',
        'phtml', 'phar', 'inc',
    ];

    /**
     * Subdirectories of pub/media/ that are common file-upload targets.
     * We count total files in each to detect mass-upload anomalies
     * (e.g. 11,250 session files in customer_address/).
     */
    private const MONITORED_SUBDIRECTORIES = [
        'customer_address',
        'customer',
        'downloadable',
        'import',
        'tmp',
        'captcha',
    ];

    private const FILE_COUNT_WARNING = 500;
    private const FILE_COUNT_CRITICAL = 2000;
    private const FILE_COUNT_CAP = 50_000;
    private const MAX_SUSPICIOUS_REPORTED = 20;
    private const SCAN_FILE_LIMIT = 200_000;

    public function __construct(
        private readonly DirectoryList $directoryList
    ) {
    }

    public function getName(): string
    {
        return 'media_integrity';
    }

    public function collect(): array
    {
        $mediaDir = $this->directoryList->getPath(DirectoryList::MEDIA);

        if (!is_dir($mediaDir)) {
            return [
                'status' => self::STATUS_HEALTHY,
                'media_dir_exists' => false,
            ];
        }

        // Scan for executable files in pub/media/
        $suspiciousFiles = $this->findExecutableFiles($mediaDir);

        // Count files in monitored upload-target subdirectories
        $subdirCounts = $this->countMonitoredSubdirectories($mediaDir);

        // Determine status
        $status = self::STATUS_HEALTHY;
        $executableCount = count($suspiciousFiles);

        if ($executableCount > 0) {
            $status = self::STATUS_CRITICAL;
        }

        // Check for anomalous file counts in upload directories
        $maxSubdirCount = 0;
        foreach ($subdirCounts as $count) {
            $maxSubdirCount = max($maxSubdirCount, $count);
        }

        if ($status !== self::STATUS_CRITICAL) {
            if ($maxSubdirCount >= self::FILE_COUNT_CRITICAL) {
                $status = self::STATUS_CRITICAL;
            } elseif ($maxSubdirCount >= self::FILE_COUNT_WARNING) {
                $status = self::STATUS_DEGRADED;
            }
        }

        $result = [
            'status' => $status,
            'executable_files_found' => $executableCount,
            'monitored_directories' => $subdirCounts,
        ];

        if ($executableCount > 0) {
            $result['suspicious_files'] = array_slice($suspiciousFiles, 0, self::MAX_SUSPICIOUS_REPORTED);
            if ($executableCount > self::MAX_SUSPICIOUS_REPORTED) {
                $result['suspicious_files_truncated'] = true;
            }
        }

        return $result;
    }

    /**
     * Recursively scan pub/media/ for files with PHP-executable extensions.
     *
     * @return list<string> Relative paths of suspicious files
     */
    private function findExecutableFiles(string $mediaDir): array
    {
        $suspicious = [];
        $scanned = 0;

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    $mediaDir,
                    \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS
                ),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $file) {
                if (++$scanned > self::SCAN_FILE_LIMIT) {
                    break;
                }

                if (!$file->isFile()) {
                    continue;
                }

                $extension = strtolower($file->getExtension());
                if (in_array($extension, self::EXECUTABLE_EXTENSIONS, true)) {
                    $suspicious[] = ltrim(
                        str_replace($mediaDir, '', $file->getPathname()),
                        '/'
                    );
                }

                // Also catch double extensions like "shell.php.jpg" or "file.php.log"
                $basename = $file->getBasename();
                if (!in_array($extension, self::EXECUTABLE_EXTENSIONS, true)
                    && preg_match('/\.(?:' . implode('|', self::EXECUTABLE_EXTENSIONS) . ')\./i', $basename)
                ) {
                    $suspicious[] = ltrim(
                        str_replace($mediaDir, '', $file->getPathname()),
                        '/'
                    );
                }
            }
        } catch (\Exception) {
            // Directory not readable — will be caught by status check
        }

        return array_unique($suspicious);
    }

    /**
     * Count total files in monitored upload-target subdirectories.
     *
     * @return array<string, int>
     */
    private function countMonitoredSubdirectories(string $mediaDir): array
    {
        $counts = [];

        foreach (self::MONITORED_SUBDIRECTORIES as $subdir) {
            $path = $mediaDir . '/' . $subdir;
            if (!is_dir($path)) {
                continue;
            }

            $count = 0;
            try {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator(
                        $path,
                        \FilesystemIterator::SKIP_DOTS
                    )
                );

                foreach ($iterator as $file) {
                    if ($file->isFile()) {
                        $count++;
                    }
                    if ($count >= self::FILE_COUNT_CAP) {
                        break;
                    }
                }
            } catch (\Exception) {
                // Skip unreadable directories
            }

            $counts[$subdir] = $count;
        }

        return $counts;
    }
}
