<?php

declare(strict_types=1);

namespace Byte8\Pulsar\Model\Collector;

interface CollectorInterface
{
    public const STATUS_HEALTHY = 'healthy';
    public const STATUS_DEGRADED = 'degraded';
    public const STATUS_CRITICAL = 'critical';

    /**
     * Security state: tamper evidence found (injected skimmer, web-shell, etc.).
     * Distinct from CRITICAL — a compromised site is usually still reachable and
     * serving, so Pulsar surfaces this as a security event, separate from the
     * uptime axis. Ranked above CRITICAL in the overall rollup (HealthCheck.php).
     */
    public const STATUS_COMPROMISED = 'compromised';

    /**
     * Get the collector name (e.g., 'cron', 'indexer')
     */
    public function getName(): string;

    /**
     * Collect health data
     *
     * @return array{status: string, ...}
     */
    public function collect(): array;
}
