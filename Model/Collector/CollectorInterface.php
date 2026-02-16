<?php

declare(strict_types=1);

namespace Byte8\Pulsar\Model\Collector;

interface CollectorInterface
{
    public const STATUS_HEALTHY = 'healthy';
    public const STATUS_DEGRADED = 'degraded';
    public const STATUS_CRITICAL = 'critical';

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
