<?php

declare(strict_types=1);

namespace Byte8\Pulsar\Cron;

use Byte8\Pulsar\Model\Config;
use Byte8\Pulsar\Model\SignatureFeed\Repository;
use Psr\Log\LoggerInterface;

/**
 * Hourly pull of the detection-signature bundle from pulsar-server. Keeps the
 * store's ContentIntegrity/ExploitProbe rules current between module releases.
 * Best-effort — the Repository leaves the last-known-good set intact on error.
 */
class FetchSignatures
{
    public function __construct(
        private readonly Config $config,
        private readonly Repository $repository,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isEnabled() || !$this->config->isSignatureFeedEnabled()) {
            return;
        }
        if ($this->config->getSignatureFeedEndpoint() === null) {
            return; // not configured — nothing to pull
        }

        $result = $this->repository->refresh();
        if ($result['stored']) {
            $this->logger->info(
                sprintf('Pulsar signature feed updated: %d signatures', $result['count'])
            );
        }
    }
}
