<?php

declare(strict_types=1);

namespace Byte8\Pulsar\Model\Collector;

use Magento\Framework\App\Config\ScopeConfigInterface;

class UploadEndpointCollector implements CollectorInterface
{
    /**
     * Magento endpoints that accept file uploads and are known attack targets.
     * These are checked against the request URI in access log entries.
     */
    private const MONITORED_ENDPOINTS = [
        '/customer/address_file/upload',
        '/customer/file/upload',
        '/checkout/cart/updateItemOptions',
        '/contact/index/post',
        // REST API endpoints used in polyshell attacks via custom options file upload.
        // Attackers create guest carts and add items with file custom options
        // to upload webshells to pub/media/custom_options/quote/.
        '/rest/V1/guest-carts',
        '/rest/all/V1/guest-carts',
        '/rest/default/V1/guest-carts',
    ];

    /**
     * User agents commonly used in automated upload attacks.
     */
    private const SCRIPTED_USER_AGENTS = [
        'python-requests',
        'python-urllib',
        'curl/',
        'wget/',
        'Go-http-client',
        'libwww-perl',
        'Mechanize',
        'scrapy',
    ];

    private const POST_WARNING_THRESHOLD = 10;
    private const POST_CRITICAL_THRESHOLD = 50;
    private const SCRIPTED_WARNING_THRESHOLD = 3;
    private const SCRIPTED_CRITICAL_THRESHOLD = 10;
    private const READ_BYTES = 1_048_576; // 1 MB tail of log
    private const MAX_IPS_REPORTED = 10;

    private const XML_PATH_LOG_PATH = 'byte8_pulsar/checks/upload_endpoint_log_path';

    private const DEFAULT_LOG_PATHS = [
        '/var/log/nginx/access.log',
        '/var/log/apache2/access.log',
        '/var/log/httpd/access_log',
    ];

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function getName(): string
    {
        return 'upload_endpoint';
    }

    public function collect(): array
    {
        $logPath = $this->resolveLogPath();

        if ($logPath === null) {
            return [
                'status' => self::STATUS_HEALTHY,
                'log_readable' => false,
                'message' => 'No readable access log found. Configure the log path in admin.',
            ];
        }

        $lines = $this->readLogTail($logPath);

        if ($lines === []) {
            return [
                'status' => self::STATUS_HEALTHY,
                'log_readable' => true,
                'log_path' => $logPath,
                'upload_posts_found' => 0,
            ];
        }

        // Parse log lines for POST requests to monitored endpoints
        $uploadRequests = [];
        $scriptedRequests = [];
        $ipCounts = [];

        foreach ($lines as $line) {
            $parsed = $this->parseLogLine($line);
            if ($parsed === null) {
                continue;
            }

            if ($parsed['method'] !== 'POST') {
                continue;
            }

            $matchedEndpoint = $this->matchesMonitoredEndpoint($parsed['uri']);
            if ($matchedEndpoint === null) {
                continue;
            }

            $uploadRequests[] = $parsed;
            $ip = $parsed['ip'];
            $ipCounts[$ip] = ($ipCounts[$ip] ?? 0) + 1;

            if ($this->isScriptedUserAgent($parsed['user_agent'])) {
                $scriptedRequests[] = $parsed;
            }
        }

        $totalUploads = count($uploadRequests);
        $totalScripted = count($scriptedRequests);

        // Determine status
        $status = self::STATUS_HEALTHY;

        if ($totalScripted >= self::SCRIPTED_CRITICAL_THRESHOLD
            || $totalUploads >= self::POST_CRITICAL_THRESHOLD
        ) {
            $status = self::STATUS_CRITICAL;
        } elseif ($totalScripted >= self::SCRIPTED_WARNING_THRESHOLD
            || $totalUploads >= self::POST_WARNING_THRESHOLD
        ) {
            $status = self::STATUS_DEGRADED;
        }

        // Sort IPs by request count descending
        arsort($ipCounts);
        $topIps = array_slice($ipCounts, 0, self::MAX_IPS_REPORTED, true);

        $result = [
            'status' => $status,
            'log_readable' => true,
            'log_path' => $logPath,
            'upload_posts_found' => $totalUploads,
            'scripted_upload_posts' => $totalScripted,
        ];

        if ($totalUploads > 0) {
            $result['top_ips'] = $topIps;

            // Collect unique scripted user agents seen
            $scriptedUAs = [];
            foreach ($scriptedRequests as $req) {
                $ua = $req['user_agent'];
                $scriptedUAs[$ua] = ($scriptedUAs[$ua] ?? 0) + 1;
            }
            if ($scriptedUAs !== []) {
                $result['scripted_user_agents'] = $scriptedUAs;
            }
        }

        return $result;
    }

    private function resolveLogPath(): ?string
    {
        // Check configured path first
        $configured = $this->scopeConfig->getValue(self::XML_PATH_LOG_PATH);
        if ($configured && is_readable($configured)) {
            return $configured;
        }

        // Fall back to common default locations
        foreach (self::DEFAULT_LOG_PATHS as $path) {
            if (is_readable($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Read the last READ_BYTES of the access log and split into lines.
     *
     * @return list<string>
     */
    private function readLogTail(string $logPath): array
    {
        $size = @filesize($logPath);
        if ($size === false || $size === 0) {
            return [];
        }

        $handle = @fopen($logPath, 'r');
        if ($handle === false) {
            return [];
        }

        try {
            $offset = max(0, $size - self::READ_BYTES);
            fseek($handle, $offset);
            $data = fread($handle, self::READ_BYTES);
        } finally {
            fclose($handle);
        }

        if ($data === false || $data === '') {
            return [];
        }

        // If we started mid-file, discard the first (likely partial) line
        if ($offset > 0) {
            $newlinePos = strpos($data, "\n");
            if ($newlinePos !== false) {
                $data = substr($data, $newlinePos + 1);
            }
        }

        $lines = explode("\n", trim($data));

        return array_filter($lines, fn(string $line) => $line !== '');
    }

    /**
     * Parse a combined/common log format line.
     *
     * @return array{ip: string, method: string, uri: string, status: int, user_agent: string}|null
     */
    private function parseLogLine(string $line): ?array
    {
        // Combined log format:
        // IP - - [date] "METHOD /uri HTTP/x.x" status size "referer" "user-agent"
        $pattern = '/^(\S+) \S+ \S+ \[.*?\] "(\S+) (\S+) [^"]*" (\d+) \S+ "[^"]*" "([^"]*)"/';

        if (!preg_match($pattern, $line, $matches)) {
            return null;
        }

        return [
            'ip' => $matches[1],
            'method' => strtoupper($matches[2]),
            'uri' => strtok($matches[3], '?'), // Strip query string
            'status' => (int) $matches[4],
            'user_agent' => $matches[5],
        ];
    }

    private function matchesMonitoredEndpoint(string $uri): ?string
    {
        $normalised = rtrim(strtolower($uri), '/');

        foreach (self::MONITORED_ENDPOINTS as $endpoint) {
            if (str_contains($normalised, strtolower($endpoint))) {
                return $endpoint;
            }
        }

        return null;
    }

    private function isScriptedUserAgent(string $userAgent): bool
    {
        $lower = strtolower($userAgent);

        foreach (self::SCRIPTED_USER_AGENTS as $pattern) {
            if (str_contains($lower, strtolower($pattern))) {
                return true;
            }
        }

        return false;
    }
}
