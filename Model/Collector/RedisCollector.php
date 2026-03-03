<?php

declare(strict_types=1);

namespace Byte8\Pulsar\Model\Collector;

use Magento\Framework\App\DeploymentConfig;

class RedisCollector implements CollectorInterface
{
    private const CONNECTION_TIMEOUT = 2;
    private const MEMORY_WARNING_PCT = 80;
    private const MEMORY_CRITICAL_PCT = 90;

    public function __construct(
        private readonly DeploymentConfig $deploymentConfig
    ) {
    }

    public function getName(): string
    {
        return 'redis';
    }

    public function collect(): array
    {
        $instances = $this->getConfiguredInstances();

        if (empty($instances)) {
            return [
                'status' => self::STATUS_HEALTHY,
                'configured' => false,
            ];
        }

        if (!extension_loaded('redis')) {
            return [
                'status' => self::STATUS_HEALTHY,
                'configured' => true,
                'monitoring_available' => false,
                'note' => 'PHP Redis extension not available for health checks',
            ];
        }

        $results = [];
        $overallStatus = self::STATUS_HEALTHY;
        $statusPriority = [
            self::STATUS_HEALTHY => 0,
            self::STATUS_DEGRADED => 1,
            self::STATUS_CRITICAL => 2,
        ];

        foreach ($instances as $name => $config) {
            $result = $this->checkInstance($config);
            $results[$name] = $result;

            if ($statusPriority[$result['status']] > $statusPriority[$overallStatus]) {
                $overallStatus = $result['status'];
            }
        }

        return [
            'status' => $overallStatus,
            'configured' => true,
            'instances' => $results,
        ];
    }

    private function getConfiguredInstances(): array
    {
        $instances = [];

        // Session Redis
        if ($this->deploymentConfig->get('session/save') === 'redis') {
            $instances['session'] = [
                'host' => $this->deploymentConfig->get('session/redis/host') ?? '127.0.0.1',
                'port' => (int) ($this->deploymentConfig->get('session/redis/port') ?? 6379),
                'database' => (int) ($this->deploymentConfig->get('session/redis/database') ?? 0),
                'password' => $this->deploymentConfig->get('session/redis/password'),
            ];
        }

        // Default cache
        $cacheBackend = (string) $this->deploymentConfig->get('cache/frontend/default/backend');
        if (str_contains($cacheBackend, 'Redis')) {
            $opts = 'cache/frontend/default/backend_options';
            $instances['cache'] = [
                'host' => $this->deploymentConfig->get("$opts/server") ?? '127.0.0.1',
                'port' => (int) ($this->deploymentConfig->get("$opts/port") ?? 6379),
                'database' => (int) ($this->deploymentConfig->get("$opts/database") ?? 1),
                'password' => $this->deploymentConfig->get("$opts/password"),
            ];
        }

        // Page cache (FPC)
        $fpcBackend = (string) $this->deploymentConfig->get('cache/frontend/page_cache/backend');
        if (str_contains($fpcBackend, 'Redis')) {
            $opts = 'cache/frontend/page_cache/backend_options';
            $instances['page_cache'] = [
                'host' => $this->deploymentConfig->get("$opts/server") ?? '127.0.0.1',
                'port' => (int) ($this->deploymentConfig->get("$opts/port") ?? 6379),
                'database' => (int) ($this->deploymentConfig->get("$opts/database") ?? 2),
                'password' => $this->deploymentConfig->get("$opts/password"),
            ];
        }

        return $instances;
    }

    private function checkInstance(array $config): array
    {
        try {
            $redis = new \Redis();
            $redis->connect($config['host'], $config['port'], self::CONNECTION_TIMEOUT);

            if (!empty($config['password'])) {
                $redis->auth($config['password']);
            }

            $redis->select($config['database']);

            $info = $redis->info();
            $redis->close();

            $usedMemory = (int) ($info['used_memory'] ?? 0);
            $maxMemory = (int) ($info['maxmemory'] ?? 0);
            $evictedKeys = (int) ($info['evicted_keys'] ?? 0);
            $connectedClients = (int) ($info['connected_clients'] ?? 0);

            $memoryPct = null;
            if ($maxMemory > 0) {
                $memoryPct = round(($usedMemory / $maxMemory) * 100, 1);
            }

            $status = self::STATUS_HEALTHY;
            if ($memoryPct !== null && $memoryPct > self::MEMORY_CRITICAL_PCT) {
                $status = self::STATUS_CRITICAL;
            } elseif ($memoryPct !== null && $memoryPct > self::MEMORY_WARNING_PCT) {
                $status = self::STATUS_DEGRADED;
            } elseif ($evictedKeys > 1000) {
                $status = self::STATUS_DEGRADED;
            }

            return [
                'status' => $status,
                'connected' => true,
                'used_memory_mb' => round($usedMemory / 1024 / 1024, 2),
                'max_memory_mb' => $maxMemory > 0 ? round($maxMemory / 1024 / 1024, 2) : null,
                'memory_usage_pct' => $memoryPct,
                'evicted_keys' => $evictedKeys,
                'connected_clients' => $connectedClients,
                'redis_version' => $info['redis_version'] ?? null,
            ];
        } catch (\Exception $e) {
            return [
                'status' => self::STATUS_CRITICAL,
                'connected' => false,
                'error' => 'Connection failed',
            ];
        }
    }
}
