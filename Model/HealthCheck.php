<?php

declare(strict_types=1);

namespace Byte8\Pulsar\Model;

use Byte8\Pulsar\Model\Collector\CollectorInterface;
use Psr\Log\LoggerInterface;

class HealthCheck
{
    private const MODULE_VERSION = '1.8.0';

    /**
     * Tier 1: Site-critical collectors. A critical status here means the
     * storefront is likely unavailable or severely broken for customers.
     */
    private const TIER1_COLLECTORS = [
        'database',
        'search',
        'cache',
        'redis',
        'ssl',
        'system',
        'phpfpm',
    ];

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
                $effectiveStatus = $this->getEffectiveStatus($name, $checkStatus);

                if ($statusPriority[$effectiveStatus] > $statusPriority[$overallStatus]) {
                    $overallStatus = $effectiveStatus;
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

                $effectiveStatus = $this->getEffectiveStatus(
                    $name,
                    CollectorInterface::STATUS_CRITICAL
                );
                if ($statusPriority[$effectiveStatus] > $statusPriority[$overallStatus]) {
                    $overallStatus = $effectiveStatus;
                }
            }
        }

        return [
            'status' => $overallStatus,
            'timestamp' => (new \DateTime())->format(\DateTime::ATOM),
            'version' => self::MODULE_VERSION,
            'checks' => $checks,
        ];
    }

    /**
     * Get the effective status for overall health computation.
     *
     * Tier 1 (site-critical) collectors contribute their actual status.
     * Tier 2 (operational) collectors have their critical status capped
     * at degraded — they represent issues that need attention but don't
     * mean the storefront is down for customers.
     *
     * Note: individual collector statuses in $checks are NOT modified,
     * so per-collector alerts still fire as critical when appropriate.
     */
    private function getEffectiveStatus(string $collectorName, string $status): string
    {
        if ($status === CollectorInterface::STATUS_CRITICAL
            && !in_array($collectorName, self::TIER1_COLLECTORS, true)
        ) {
            return CollectorInterface::STATUS_DEGRADED;
        }

        return $status;
    }
}
