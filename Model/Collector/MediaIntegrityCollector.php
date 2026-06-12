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
     * Per-subdirectory monitoring rules.
     *
     * For each subdir we count two things:
     *   - total_files: every regular file (raw count, for context)
     *   - unexpected_files: files whose extension is not in `allowed_extensions`
     *     and whose basename is not in `ignored_files`
     *
     * Thresholds (warning/critical) compare against unexpected_files only,
     * so legitimate bulk content (e.g. product images in import/) does not
     * trigger alerts as long as it matches the expected file types.
     *
     * `allowed_extensions = null` means "no extension is expected" — every
     * file (except those in `ignored_files`) counts as unexpected. Use this
     * for directories that should normally be empty (tmp/, captcha/).
     */
    private const SUBDIR_RULES = [
        'import' => [
            // Product import staging — large legitimate image/CSV uploads
            'allowed_extensions' => [
                'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg',
                'csv', 'tsv', 'txt', 'xml', 'json',
                'zip', 'tar', 'gz', 'tgz',
            ],
            'ignored_files' => ['.htaccess'],
            'warning' => 50,
            'critical' => 200,
        ],
        'customer_address' => [
            // Address proofs (rarely populated). Steelman24 case: 11,250 session files.
            'allowed_extensions' => ['jpg', 'jpeg', 'png', 'pdf'],
            'ignored_files' => ['.htaccess'],
            'warning' => 100,
            'critical' => 500,
        ],
        'customer' => [
            // Customer avatars
            'allowed_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
            'ignored_files' => ['.htaccess'],
            'warning' => 500,
            'critical' => 2000,
        ],
        'custom_options' => [
            // File-type product options uploaded by shoppers
            'allowed_extensions' => [
                'jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx',
                'xls', 'xlsx', 'txt', 'zip',
            ],
            'ignored_files' => ['.htaccess'],
            'warning' => 500,
            'critical' => 2000,
        ],
        'downloadable' => [
            // Downloadable product files — no fixed type, but anything
            // outside .htaccess is "unexpected" for alert-counting purposes.
            // Genuine downloadable products will produce a warning, which
            // is intentional: operators should review what's there.
            'allowed_extensions' => null,
            'ignored_files' => ['.htaccess'],
            'warning' => 200,
            'critical' => 1000,
        ],
        'tmp' => [
            // Temp uploads — should be drained regularly
            'allowed_extensions' => null,
            'ignored_files' => ['.htaccess'],
            'warning' => 100,
            'critical' => 500,
        ],
        'captcha' => [
            // Captcha images are short-lived; large counts indicate rotation issue
            'allowed_extensions' => ['png', 'gif', 'jpg', 'jpeg'],
            'ignored_files' => ['.htaccess'],
            'warning' => 500,
            'critical' => 2000,
        ],
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

    /** Hard cap on per-subdir file counting to bound walk cost. */
    private const FILE_COUNT_CAP = 100_000;

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

        // Per-subdirectory file counts (total + unexpected) with status
        $subdirReports = $this->scanMonitoredSubdirectories($mediaDir);

        // Determine status
        $status = self::STATUS_HEALTHY;
        $executableCount = count($suspiciousFiles);
        $polyshellCount = count($polyshellFiles);

        // Active tamper evidence — a PHP-executable file or an image with an
        // embedded PHP payload in pub/media/ — is a compromise, not a mere
        // operational critical. Surfaced as a security event, separate from uptime.
        if ($executableCount > 0 || $polyshellCount > 0) {
            $status = self::STATUS_COMPROMISED;
        } elseif (!empty($htaccessIssues)) {
            // A missing/weak PHP-execution guard is a hardening gap (webshells
            // *could* run) — a vulnerability, not proof of an actual breach.
            $status = self::STATUS_CRITICAL;
        }

        // Per-subdir volume thresholds are operational (legitimate bulk uploads
        // can trip them): they escalate up to CRITICAL but must never downgrade
        // an already-detected COMPROMISED status.
        if ($status !== self::STATUS_COMPROMISED) {
            foreach ($subdirReports as $report) {
                if ($report['status'] === self::STATUS_CRITICAL) {
                    $status = self::STATUS_CRITICAL;
                    break;
                }
                if ($report['status'] === self::STATUS_DEGRADED
                    && $status === self::STATUS_HEALTHY
                ) {
                    $status = self::STATUS_DEGRADED;
                }
            }
        }

        $result = [
            'status' => $status,
            'executable_files_found' => $executableCount,
            'polyshell_files_found' => $polyshellCount,
            'htaccess_issues' => count($htaccessIssues),
            'htaccess_skipped' => $this->isNginx(),
            'web_server' => $this->isNginx() ? 'nginx' : 'apache',
            'monitored_directories' => $subdirReports,
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
     * Walk each monitored subdirectory, counting total files and files whose
     * extension is not in the per-subdir allowlist (or whose basename is not
     * explicitly ignored). Per-subdir thresholds determine the local status.
     *
     * @return array<string, array{
     *     total: int,
     *     unexpected: int,
     *     status: string,
     *     warning_threshold: int,
     *     critical_threshold: int
     * }>
     */
    private function scanMonitoredSubdirectories(string $mediaDir): array
    {
        $reports = [];

        foreach (self::SUBDIR_RULES as $subdir => $rule) {
            $path = $mediaDir . '/' . $subdir;
            if (!is_dir($path)) {
                continue;
            }

            $allowedExt = $rule['allowed_extensions'];
            $ignored = $rule['ignored_files'] ?? [];
            $warning = $rule['warning'];
            $critical = $rule['critical'];

            $total = 0;
            $unexpected = 0;

            try {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator(
                        $path,
                        \FilesystemIterator::SKIP_DOTS
                    )
                );

                foreach ($iterator as $file) {
                    if (!$file->isFile()) {
                        continue;
                    }
                    $total++;
                    if ($total >= self::FILE_COUNT_CAP) {
                        break;
                    }

                    $basename = $file->getBasename();
                    if (in_array($basename, $ignored, true)) {
                        continue;
                    }

                    if ($allowedExt === null) {
                        // No extension is expected here — everything counts.
                        $unexpected++;
                        continue;
                    }

                    $ext = strtolower($file->getExtension());
                    if (!in_array($ext, $allowedExt, true)) {
                        $unexpected++;
                    }
                }
            } catch (\Exception) {
                // Skip unreadable directories
            }

            $localStatus = self::STATUS_HEALTHY;
            if ($unexpected >= $critical) {
                $localStatus = self::STATUS_CRITICAL;
            } elseif ($unexpected >= $warning) {
                $localStatus = self::STATUS_DEGRADED;
            }

            $reports[$subdir] = [
                'total' => $total,
                'unexpected' => $unexpected,
                'status' => $localStatus,
                'warning_threshold' => $warning,
                'critical_threshold' => $critical,
            ];
        }

        return $reports;
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
        foreach (array_keys(self::SUBDIR_RULES) as $subdir) {
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
     * Detect whether the server is running Nginx (htaccess is Apache-only).
     */
    private function isNginx(): bool
    {
        $serverSoftware = $_SERVER['SERVER_SOFTWARE'] ?? '';

        return stripos($serverSoftware, 'nginx') !== false;
    }

    /**
     * Verify .htaccess files exist in pub/media/ and subdirectories
     * and contain rules that block PHP execution.
     *
     * Missing or empty .htaccess files mean webshells could execute
     * on Apache — this is the last line of defense.
     * Skipped on Nginx where .htaccess files are not used.
     *
     * @return list<string> Issues found (empty = all OK)
     */
    private function checkHtaccessFiles(string $mediaDir): array
    {
        // Nginx does not process .htaccess files — PHP execution is controlled
        // via the server block config (e.g. location ~* \.php$ { deny all; })
        if ($this->isNginx()) {
            return [];
        }

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
            // "php_flag engine off/0", "SetHandler none", "RemoveHandler .php"
            $lower = strtolower($content);
            $hasProtection = str_contains($lower, 'require all denied')
                || str_contains($lower, 'deny from all')
                || (bool) preg_match('/php_flag\s+engine\s+(off|0)\b/i', $content)
                || str_contains($lower, 'removehandler')
                || str_contains($lower, 'sethandler none')
                || str_contains($lower, 'sethandler default-handler');

            if (!$hasProtection) {
                $issues[] = $label . ' lacks PHP execution deny rules';
            }
        }

        return $issues;
    }
}
