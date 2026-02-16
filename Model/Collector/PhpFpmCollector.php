<?php

declare(strict_types=1);

namespace Byte8\Pulsar\Model\Collector;

use Magento\Framework\App\Config\ScopeConfigInterface;

class PhpFpmCollector implements CollectorInterface
{
    private const POOL_WARNING_PCT = 80;
    private const POOL_CRITICAL_PCT = 90;

    private const XML_PATH_STATUS_URL = 'byte8_pulsar/checks/phpfpm_status_url';
    private const DEFAULT_STATUS_URL = 'http://localhost/status?json';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function getName(): string
    {
        return 'phpfpm';
    }

    public function collect(): array
    {
        $statusUrl = $this->scopeConfig->getValue(self::XML_PATH_STATUS_URL)
            ?: self::DEFAULT_STATUS_URL;

        $context = stream_context_create([
            'http' => [
                'timeout' => 3,
                'method' => 'GET',
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($statusUrl, false, $context);

        if ($response === false) {
            return [
                'status' => self::STATUS_HEALTHY,
                'configured' => false,
                'note' => 'PHP-FPM status page not reachable (pm.status_path may not be configured)',
            ];
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            return [
                'status' => self::STATUS_DEGRADED,
                'configured' => true,
                'error' => 'PHP-FPM status page returned invalid JSON',
            ];
        }

        $activeProcesses = (int) ($data['active processes'] ?? 0);
        $maxChildren = (int) ($data['max children reached'] ?? 0);
        $totalProcesses = (int) ($data['total processes'] ?? 0);
        $listenQueueLen = (int) ($data['listen queue len'] ?? 0);
        $listenQueue = (int) ($data['listen queue'] ?? 0);
        $idleProcesses = (int) ($data['idle processes'] ?? 0);
        $maxActiveProcesses = (int) ($data['max active processes'] ?? 0);

        // pm.max_children is not directly in the status output.
        // We can infer the pool capacity from listen-queue-len (backlog) or max-active-processes.
        // The best proxy is: if total processes == max-active-processes AND listen-queue > 0,
        // the pool is saturated. Otherwise, use total processes as the capacity indicator.
        //
        // A more reliable approach: if 'max children reached' > 0, the pool has been
        // fully saturated at some point. For current saturation, use active/total ratio.
        $capacity = $totalProcesses > 0 ? $totalProcesses : 1;
        $usagePct = round(($activeProcesses / $capacity) * 100, 1);

        $status = self::STATUS_HEALTHY;
        if ($usagePct >= self::POOL_CRITICAL_PCT || $listenQueue > 0) {
            $status = self::STATUS_CRITICAL;
        } elseif ($usagePct >= self::POOL_WARNING_PCT) {
            $status = self::STATUS_DEGRADED;
        }

        return [
            'status' => $status,
            'configured' => true,
            'pool' => $data['pool'] ?? 'unknown',
            'process_manager' => $data['process manager'] ?? 'unknown',
            'active_processes' => $activeProcesses,
            'idle_processes' => $idleProcesses,
            'total_processes' => $totalProcesses,
            'max_active_processes' => $maxActiveProcesses,
            'max_children_reached' => $maxChildren,
            'listen_queue' => $listenQueue,
            'listen_queue_len' => $listenQueueLen,
            'usage_pct' => $usagePct,
        ];
    }
}
