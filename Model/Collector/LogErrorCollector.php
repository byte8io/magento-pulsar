<?php

declare(strict_types=1);

namespace Byte8\Pulsar\Model\Collector;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\FlagManager;

class LogErrorCollector implements CollectorInterface
{
    private const MAX_READ_BYTES = 2_097_152;       // 2 MB max read per file per check
    private const MAX_ENTRIES_PER_FILE = 500;        // parse cap per file
    private const MAX_UNIQUE_ERRORS = 20;            // payload cap
    private const MESSAGE_TRUNCATE_LENGTH = 200;     // first line truncation

    private const MONITORED_FILES = [
        'exception.log',
        'system.log',
    ];

    private const LEVELS_TO_CAPTURE = ['CRITICAL', 'ERROR'];

    private const ENTRY_PATTERN = '/^\[(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d+[+\-][\d:]+)\]\s+(\w+)\.(\w+):\s+(.*)$/';

    private const FLAG_PREFIX = 'pulsar_log_errors_offset_';

    public function __construct(
        private readonly DirectoryList $directoryList,
        private readonly FlagManager $flagManager
    ) {
    }

    public function getName(): string
    {
        return 'log_errors';
    }

    public function collect(): array
    {
        $varDir = $this->directoryList->getPath(DirectoryList::VAR_DIR);
        $logDir = $varDir . '/log';

        $allEntries = [];
        $filesScanned = [];
        $totalRawErrors = 0;

        foreach (self::MONITORED_FILES as $filename) {
            $filePath = $logDir . '/' . $filename;

            $stat = @stat($filePath);
            if ($stat === false) {
                $this->saveOffsetState($filename, 0, 0, 0);
                continue;
            }

            $filesScanned[] = $filename;
            $currentInode = $stat['ino'];
            $currentSize = $stat['size'];
            $saved = $this->getOffsetState($filename);

            // Detect log rotation: inode changed or file was truncated
            $rotated = ($currentInode !== $saved['inode'] && $saved['inode'] !== 0)
                    || $currentSize < $saved['offset'];

            $readOffset = $rotated ? 0 : $saved['offset'];

            // Nothing new to read
            if ($currentSize <= $readOffset) {
                continue;
            }

            $data = $this->readFromOffset($filePath, $readOffset, self::MAX_READ_BYTES);
            if ($data === '') {
                continue;
            }

            $newOffset = $readOffset + strlen($data);
            $this->saveOffsetState($filename, $newOffset, $currentInode, $currentSize);

            $entries = $this->parseLogEntries($data);
            $totalRawErrors += count($entries);
            $allEntries = array_merge($allEntries, $entries);
        }

        // Deduplicate by signature
        $uniqueErrors = $this->deduplicateEntries($allEntries);

        // Determine status
        $status = self::STATUS_HEALTHY;
        foreach ($uniqueErrors as $error) {
            if ($error['level'] === 'CRITICAL') {
                $status = self::STATUS_CRITICAL;
                break;
            }
            if ($error['level'] === 'ERROR') {
                $status = self::STATUS_DEGRADED;
            }
        }

        return [
            'status' => $status,
            'error_count' => $totalRawErrors,
            'unique_error_count' => count($uniqueErrors),
            'files_scanned' => $filesScanned,
            'errors' => array_values(array_map(
                fn(array $e) => [
                    'signature' => $e['signature'],
                    'level' => $e['level'],
                    'message' => mb_substr($e['message'], 0, self::MESSAGE_TRUNCATE_LENGTH),
                    'count' => $e['count'],
                    'first_seen' => $e['first_seen'],
                    'last_seen' => $e['last_seen'],
                ],
                array_slice($uniqueErrors, 0, self::MAX_UNIQUE_ERRORS)
            )),
        ];
    }

    private function readFromOffset(string $filePath, int $offset, int $maxBytes): string
    {
        $handle = @fopen($filePath, 'r');
        if ($handle === false) {
            return '';
        }

        try {
            fseek($handle, $offset);
            $data = fread($handle, $maxBytes);
            return $data === false ? '' : $data;
        } finally {
            fclose($handle);
        }
    }

    private function parseLogEntries(string $data): array
    {
        $entries = [];
        $lines = explode("\n", $data);
        $count = 0;

        foreach ($lines as $line) {
            if (preg_match(self::ENTRY_PATTERN, $line, $m)) {
                $level = strtoupper($m[3]);
                if (in_array($level, self::LEVELS_TO_CAPTURE, true)) {
                    // Extract the message before the JSON context
                    $message = $this->extractMessage($m[4]);
                    $entries[] = [
                        'timestamp' => $m[1],
                        'level' => $level,
                        'message' => $message,
                    ];
                    $count++;
                    if ($count >= self::MAX_ENTRIES_PER_FILE) {
                        break;
                    }
                }
            }
            // Lines not matching the pattern are stack trace continuations - skip
        }

        return $entries;
    }

    /**
     * Extract the human-readable message, stripping trailing JSON context.
     *
     * Magento format: "Message text {"key":"value"} []"
     * We want just: "Message text"
     */
    private function extractMessage(string $raw): string
    {
        // Find the first { that starts a JSON context block
        $bracePos = strpos($raw, ' {');
        if ($bracePos !== false && $bracePos > 0) {
            return trim(substr($raw, 0, $bracePos));
        }

        // Fallback: strip trailing [] if present
        $raw = rtrim($raw);
        if (str_ends_with($raw, ' []')) {
            return substr($raw, 0, -3);
        }

        return $raw;
    }

    /**
     * Deduplicate entries by normalized signature, aggregating counts.
     *
     * @return array<array{signature: string, level: string, message: string, count: int, first_seen: string, last_seen: string}>
     */
    private function deduplicateEntries(array $entries): array
    {
        $grouped = [];

        foreach ($entries as $entry) {
            $normalized = $this->normalizeMessage($entry['message']);
            $signature = hash('sha256', $entry['level'] . ':' . $normalized);

            if (isset($grouped[$signature])) {
                $grouped[$signature]['count']++;
                if ($entry['timestamp'] < $grouped[$signature]['first_seen']) {
                    $grouped[$signature]['first_seen'] = $entry['timestamp'];
                }
                if ($entry['timestamp'] > $grouped[$signature]['last_seen']) {
                    $grouped[$signature]['last_seen'] = $entry['timestamp'];
                }
                // Escalate level: CRITICAL wins over ERROR
                if ($entry['level'] === 'CRITICAL') {
                    $grouped[$signature]['level'] = 'CRITICAL';
                }
            } else {
                $grouped[$signature] = [
                    'signature' => $signature,
                    'level' => $entry['level'],
                    'message' => $entry['message'],
                    'count' => 1,
                    'first_seen' => $entry['timestamp'],
                    'last_seen' => $entry['timestamp'],
                ];
            }
        }

        // Sort by count descending (most frequent first)
        usort($grouped, fn(array $a, array $b) => $b['count'] <=> $a['count']);

        return $grouped;
    }

    /**
     * Normalize a message by stripping dynamic values to produce a stable signature.
     */
    private function normalizeMessage(string $message): string
    {
        $msg = mb_substr($message, 0, self::MESSAGE_TRUNCATE_LENGTH);

        // Strip UUIDs (v4 pattern)
        $msg = preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', '{UUID}', $msg);

        // Strip hex/alphanumeric tokens (session IDs, quote IDs, etc.)
        $msg = preg_replace('/\b[0-9a-f]{16,}\b/i', '{TOKEN}', $msg);

        // Strip numeric IDs (standalone numbers 2+ digits)
        $msg = preg_replace('/\b\d{2,}\b/', '{N}', $msg);

        // Strip email addresses
        $msg = preg_replace('/[\w.+-]+@[\w.-]+/', '{EMAIL}', $msg);

        // Strip IP addresses
        $msg = preg_replace('/\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}/', '{IP}', $msg);

        // Collapse whitespace
        $msg = preg_replace('/\s+/', ' ', trim($msg));

        return $msg;
    }

    private function getOffsetState(string $filename): array
    {
        $key = self::FLAG_PREFIX . str_replace('.', '_', $filename);
        $data = $this->flagManager->getFlagData($key);
        if (is_array($data)) {
            return $data;
        }
        return ['offset' => 0, 'inode' => 0, 'size' => 0];
    }

    private function saveOffsetState(string $filename, int $offset, int $inode, int $size): void
    {
        $key = self::FLAG_PREFIX . str_replace('.', '_', $filename);
        $this->flagManager->saveFlag($key, [
            'offset' => $offset,
            'inode' => $inode,
            'size' => $size,
        ]);
    }
}
