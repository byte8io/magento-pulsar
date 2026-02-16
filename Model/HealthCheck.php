<?php

declare(strict_types=1);

namespace Byte8\Pulsar\Model;

use Byte8\Pulsar\Model\Collector\CollectorInterface;
use Psr\Log\LoggerInterface;

class HealthCheck
{
    private const MODULE_VERSION = '1.0.0';

    /**
     * @param CollectorInterface[] $collectors
     */
    public function __construct(
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly array $collectors = []
    ) {
    }

    public function check(): array
    {
        $checks = [];
        $overallStatus = CollectorInterface::STATUS_HEALTHY;
        $statusPriority = [
            CollectorInterface::STATUS_HEALTHY => 0,
            CollectorInterface::STATUS_DEGRADED => 1,
            CollectorInterface::STATUS_CRITICAL => 2,
        ];

        foreach ($this->collectors as $name => $collector) {
            if (!$this->config->isCheckEnabled($name)) {
                continue;
            }

            try {
                $result = $collector->collect();
                $checks[$name] = $result;

                $checkStatus = $result['status'] ?? CollectorInterface::STATUS_HEALTHY;
                if ($statusPriority[$checkStatus] > $statusPriority[$overallStatus]) {
                    $overallStatus = $checkStatus;
                }
            } catch (\Exception $e) {
                $this->logger->error('Pulsar health check failed', [
                    'collector' => $name,
                    'exception' => $e->getMessage(),
                ]);
                $checks[$name] = [
                    'status' => CollectorInterface::STATUS_CRITICAL,
                    'error' => 'Check failed',
                ];
                $overallStatus = CollectorInterface::STATUS_CRITICAL;
            }
        }

        return [
            'status' => $overallStatus,
            'timestamp' => (new \DateTime())->format(\DateTime::ATOM),
            'version' => self::MODULE_VERSION,
            'checks' => $checks,
        ];
    }
}