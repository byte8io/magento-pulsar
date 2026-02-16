<?php

declare(strict_types=1);

namespace Byte8\Pulsar\Model\Collector;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Client\CurlFactory;

class SearchCollector implements CollectorInterface
{
    private const CONNECTION_TIMEOUT = 5;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly CurlFactory $curlFactory
    ) {
    }

    public function getName(): string
    {
        return 'search';
    }

    public function collect(): array
    {
        $engine = $this->scopeConfig->getValue('catalog/search/engine') ?? 'elasticsearch7';
        $config = $this->getEngineConfig($engine);

        if (empty($config['hostname'])) {
            return [
                'status' => self::STATUS_CRITICAL,
                'engine' => $engine,
                'error' => 'Search engine hostname not configured',
            ];
        }

        $baseUrl = sprintf('http://%s:%s', $config['hostname'], $config['port']);

        // 1. Cluster health
        $health = $this->fetchJson($baseUrl . '/_cluster/health', $config);

        if ($health === null) {
            return [
                'status' => self::STATUS_CRITICAL,
                'engine' => $engine,
                'error' => 'Search engine unreachable',
            ];
        }

        $clusterStatus = $health['status'] ?? 'unknown';

        // 2. Check for catalog indices with the configured prefix
        $indexPrefix = $config['index_prefix'] ?: 'magento2';
        $indices = $this->fetchJson(
            $baseUrl . '/_cat/indices/' . urlencode($indexPrefix) . '*?format=json',
            $config
        );
        $indexCount = is_array($indices) ? count($indices) : 0;

        // 3. Determine status
        $numberOfNodes = (int) ($health['number_of_nodes'] ?? 0);
        $status = self::STATUS_HEALTHY;
        if ($clusterStatus === 'red' || $indexCount === 0) {
            $status = self::STATUS_CRITICAL;
        } elseif ($clusterStatus === 'yellow' && $numberOfNodes > 1) {
            // Yellow on multi-node = real issue (replicas should be assigned)
            $status = self::STATUS_DEGRADED;
        }
        // Yellow on single-node is expected (replicas can't be assigned)

        return [
            'status' => $status,
            'engine' => $engine,
            'cluster_status' => $clusterStatus,
            'number_of_nodes' => $numberOfNodes,
            'active_shards' => (int) ($health['active_shards'] ?? 0),
            'unassigned_shards' => (int) ($health['unassigned_shards'] ?? 0),
            'pending_tasks' => (int) ($health['number_of_pending_tasks'] ?? 0),
            'index_count' => $indexCount,
        ];
    }

    private function getEngineConfig(string $engine): array
    {
        $prefix = match ($engine) {
            'opensearch' => 'catalog/search/opensearch_',
            default => 'catalog/search/elasticsearch7_',
        };

        return [
            'hostname' => $this->scopeConfig->getValue($prefix . 'server_hostname') ?? 'localhost',
            'port' => $this->scopeConfig->getValue($prefix . 'server_port') ?? '9200',
            'enable_auth' => (bool) $this->scopeConfig->getValue($prefix . 'enable_auth'),
            'username' => $this->scopeConfig->getValue($prefix . 'username') ?? '',
            'password' => $this->scopeConfig->getValue($prefix . 'password') ?? '',
            'index_prefix' => $this->scopeConfig->getValue($prefix . 'index_prefix') ?? 'magento2',
        ];
    }

    private function fetchJson(string $url, array $config): ?array
    {
        try {
            $client = $this->curlFactory->create();
            $client->setTimeout(self::CONNECTION_TIMEOUT);

            if ($config['enable_auth'] && $config['username']) {
                $client->setCredentials($config['username'], $config['password']);
            }

            $client->get($url);

            if ($client->getStatus() >= 200 && $client->getStatus() < 300) {
                $body = json_decode($client->getBody(), true);
                return is_array($body) ? $body : null;
            }

            return null;
        } catch (\Exception $e) {
            return null;
        }
    }
}
