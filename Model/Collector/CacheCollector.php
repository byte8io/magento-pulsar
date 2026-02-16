<?php

declare(strict_types=1);

namespace Byte8\Pulsar\Model\Collector;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Cache\StateInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\DeploymentConfig;

class CacheCollector implements CollectorInterface
{
    private const VARNISH_BACKEND = 2;
    private const VARNISH_SOCKET_TIMEOUT = 2;

    public function __construct(
        private readonly TypeListInterface $cacheTypeList,
        private readonly StateInterface $cacheState,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly DeploymentConfig $deploymentConfig
    ) {
    }

    public function getName(): string
    {
        return 'cache';
    }

    public function collect(): array
    {
        $types = $this->cacheTypeList->getTypes();
        $invalidatedTypes = $this->cacheTypeList->getInvalidated();

        $cacheStatus = [];
        $disabledCount = 0;
        $invalidatedCount = count($invalidatedTypes);

        foreach ($types as $type) {
            $typeId = $type->getId();
            $isEnabled = $this->cacheState->isEnabled($typeId);
            $isInvalidated = isset($invalidatedTypes[$typeId]);

            if (!$isEnabled) {
                $disabledCount++;
            }

            // Only include key cache types in the response to keep it lightweight
            if (in_array($typeId, ['config', 'layout', 'block_html', 'full_page', 'collections'])) {
                $cacheStatus[$typeId] = [
                    'enabled' => $isEnabled,
                    'invalidated' => $isInvalidated,
                ];
            }
        }

        // Determine status
        $status = self::STATUS_HEALTHY;
        if ($invalidatedCount > 3) {
            $status = self::STATUS_DEGRADED;
        }
        if ($disabledCount > 2 || $invalidatedCount > 5) {
            $status = self::STATUS_CRITICAL;
        }

        $result = [
            'status' => $status,
            'total_types' => count($types),
            'disabled_count' => $disabledCount,
            'invalidated_count' => $invalidatedCount,
            'types' => $cacheStatus,
        ];

        // Check Varnish reachability if configured as the FPC backend
        $varnishCheck = $this->checkVarnish();
        $result['varnish_configured'] = $varnishCheck['configured'];

        if ($varnishCheck['configured']) {
            $result['varnish_reachable'] = $varnishCheck['reachable'];
            $result['varnish_host'] = $varnishCheck['host'];
            $result['varnish_port'] = $varnishCheck['port'];

            if (!$varnishCheck['reachable']) {
                $result['status'] = self::STATUS_CRITICAL;
                $result['varnish_error'] = $varnishCheck['error'] ?? 'Connection refused';
            }
        }

        return $result;
    }

    /**
     * Check if Varnish is configured and reachable
     */
    private function checkVarnish(): array
    {
        $cachingApp = (int) $this->scopeConfig->getValue(
            'system/full_page_cache/caching_application'
        );

        if ($cachingApp !== self::VARNISH_BACKEND) {
            return ['configured' => false];
        }

        // Try to get Varnish backend host from deployment config or fall back to defaults
        $host = $this->getVarnishHost();
        $port = $this->getVarnishPort();

        $result = [
            'configured' => true,
            'host' => $host,
            'port' => $port,
            'reachable' => false,
        ];

        $socket = @fsockopen($host, $port, $errno, $errstr, self::VARNISH_SOCKET_TIMEOUT);

        if ($socket) {
            $result['reachable'] = true;
            fclose($socket);
        } else {
            $result['error'] = sprintf('Connection to %s:%d failed: %s', $host, $port, $errstr);
        }

        return $result;
    }

    /**
     * Resolve the Varnish host from config or deployment config
     */
    private function getVarnishHost(): string
    {
        // Check http_cache_hosts in env.php (deployment config)
        $httpCacheHosts = $this->deploymentConfig->get('http_cache_hosts');

        if (is_array($httpCacheHosts) && !empty($httpCacheHosts)) {
            $firstHost = reset($httpCacheHosts);
            if (is_array($firstHost) && !empty($firstHost['host'])) {
                return (string) $firstHost['host'];
            }
        }

        return 'localhost';
    }

    /**
     * Resolve the Varnish port from config or deployment config
     */
    private function getVarnishPort(): int
    {
        $httpCacheHosts = $this->deploymentConfig->get('http_cache_hosts');

        if (is_array($httpCacheHosts) && !empty($httpCacheHosts)) {
            $firstHost = reset($httpCacheHosts);
            if (is_array($firstHost) && !empty($firstHost['port'])) {
                return (int) $firstHost['port'];
            }
        }

        return 80;
    }
}
