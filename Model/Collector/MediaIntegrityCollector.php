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
        'custom_options',
        'downloadable',
        'import',
        'tmp',
        'captcha',
    ];

    /**
     * Image extensions to scan for embedded PHP code (polyshell detection).
     * Attackers prepend GIF89a or other magic bytes to trick MIME validation
     * while embedding PHP backdoors: GIF89a<?php eval(...)
     */
    private const IMAGE_EXTENSIONS = [
        'gif', 'jpg', 'jpeg', 'png', 'webp', 'svg', 'ico', 'bmp',
    ];

    /**
     * PHP code signatures to detect inside image files.
     * These patterns catch polyshell payloads like "GIF89a<?php echo..."
     */
    private const PHP_SIGNATURES = [
        '<?php',
        '<?=',
        '<? ',
        '__HALT_COMPILER',
    ];

    /**
     * Max bytes to read from each image file for polyshell scanning.
     * Webshells are small — reading the first 2KB is sufficient.
     */
    private const POLYSHELL_READ_BYTES = 2048;

    /**
     * Max number of image files to scan for polyshell content per run.
     * Limits I/O impact — scans newest files in upload-target directories.
     */
    private const POLYSHELL_SCAN_LIMIT = 5000;

    /**
     * Expected .htaccess rules that block PHP execution in pub/media/.
     * If any of these files are missing or lack the deny pattern, it's critical.
     */
    private const HTACCESS_PATHS = [
        '',                  // pub/media/.htaccess
        'customer_address',  // pub/media/customer_address/.htaccess
        'custom_options',    // pub/media/custom_options/.htaccess
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

        // Scan image files for embedded PHP code (polyshell detection)
        $polyshellFiles = $this->findPolyshellFiles($mediaDir);

        // Check .htaccess files that block PHP execution
        $htaccessIssues = $this->checkHtaccessFiles($mediaDir);

        // Count files in monitored upload-target subdirectories
        $subdirCounts = $this->countMonitoredSubdirectories($mediaDir);

        // Determine status
        $status = self::STATUS_HEALTHY;
        $executableCount = count($suspiciousFiles);
        $polyshellCount = count($polyshellFiles);

        if ($executableCount > 0 || $polyshellCount > 0 || !empty($htaccessIssues)) {
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
            'polyshell_files_found' => $polyshellCount,
            'htaccess_issues' => count($htaccessIssues),
            'monitored_directories' => $subdirCounts,
        ];

        if ($executableCount > 0) {
            $result['suspicious_files'] = array_slice($suspiciousFiles, 0, self::MAX_SUSPICIOUS_REPORTED);
            if ($executableCount > self::MAX_SUSPICIOUS_REPORTED) {
                $result['suspicious_files_truncated'] = true;
            }
        }

        if ($polyshellCount > 0) {
            $result['polyshell_files'] = array_slice($polyshellFiles, 0, self::MAX_SUSPICIOUS_REPORTED);
        }

        if (!empty($htaccessIssues)) {
            $result['htaccess_details'] = $htaccessIssues;
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

    /**
     * Scan image files in upload-target subdirectories for embedded PHP code.
     *
     * Polyshell attacks prepend image magic bytes (e.g. GIF89a) to PHP backdoors
     * so they pass MIME validation but execute as PHP if the web server is misconfigured.
     * Example: "GIF89a<?php eval(base64_decode($_REQUEST[id]));"
     *
     * @return list<string> Relative paths of polyshell files
     */
    private function findPolyshellFiles(string $mediaDir): array
    {
        $polyshells = [];
        $scanned = 0;

        // Only scan upload-target directories (not all of pub/media/)
        foreach (self::MONITORED_SUBDIRECTORIES as $subdir) {
            $path = $mediaDir . '/' . $subdir;
            if (!is_dir($path)) {
                continue;
            }

            try {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator(
                        $path,
                        \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS
                    ),
                    \RecursiveIteratorIterator::SELF_FIRST
                );

                foreach ($iterator as $file) {
                    if (++$scanned > self::POLYSHELL_SCAN_LIMIT) {
                        break 2;
                    }

                    if (!$file->isFile()) {
                        continue;
                    }

                    $extension = strtolower($file->getExtension());
                    if (!in_array($extension, self::IMAGE_EXTENSIONS, true)) {
                        continue;
                    }

                    // Read first bytes and check for PHP signatures
                    if ($this->containsPhpCode($file->getPathname())) {
                        $polyshells[] = ltrim(
                            str_replace($mediaDir, '', $file->getPathname()),
                            '/'
                        );

                        if (count($polyshells) >= self::MAX_SUSPICIOUS_REPORTED) {
                            break 2;
                        }
                    }
                }
            } catch (\Exception) {
                // Skip unreadable directories
            }
        }

        return $polyshells;
    }

    /**
     * Check if a file contains PHP code signatures in its first bytes.
     */
    private function containsPhpCode(string $filePath): bool
    {
        $handle = @fopen($filePath, 'r');
        if ($handle === false) {
            return false;
        }

        try {
            $content = fread($handle, self::POLYSHELL_READ_BYTES);
        } finally {
            fclose($handle);
        }

        if ($content === false || $content === '') {
            return false;
        }

        $lower = strtolower($content);
        foreach (self::PHP_SIGNATURES as $signature) {
            if (str_contains($lower, strtolower($signature))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verify .htaccess files exist in pub/media/ and subdirectories
     * and contain rules that block PHP execution.
     *
     * Missing or empty .htaccess files mean webshells could execute
     * on Apache — this is the last line of defense.
     *
     * @return list<string> Issues found (empty = all OK)
     */
    private function checkHtaccessFiles(string $mediaDir): array
    {
        $issues = [];

        foreach (self::HTACCESS_PATHS as $subdir) {
            $path = $subdir === ''
                ? $mediaDir . '/.htaccess'
                : $mediaDir . '/' . $subdir . '/.htaccess';

            $label = $subdir === '' ? 'media/.htaccess' : 'media/' . $subdir . '/.htaccess';

            if (!file_exists($path)) {
                $issues[] = $label . ' is missing';
                continue;
            }

            $content = @file_get_contents($path);
            if ($content === false || $content === '') {
                $issues[] = $label . ' is empty';
                continue;
            }

            // Check for PHP execution deny rules
            // Common patterns: "Require all denied", "deny from all",
            // "php_flag engine off", "SetHandler none", "RemoveHandler .php"
            $lower = strtolower($content);
            $hasProtection = str_contains($lower, 'require all denied')
                || str_contains($lower, 'deny from all')
                || str_contains($lower, 'php_flag engine off')
                || str_contains($lower, 'removehandler')
                || str_contains($lower, 'sethandler none');

            if (!$hasProtection) {
                $issues[] = $label . ' lacks PHP execution deny rules';
            }
        }

        return $issues;
    }
}
