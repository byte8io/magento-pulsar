<?php

declare(strict_types=1);

namespace Byte8\Pulsar\Model\Collector;

use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Indexer\ConfigInterface;
use Magento\Indexer\Model\Indexer\State;

class IndexerCollector implements CollectorInterface
{
    private const STUCK_THRESHOLD_MINUTES = 60;
    private const SEARCH_INDEXER_ID = 'catalogsearch_fulltext';

    public function __construct(
        private readonly IndexerRegistry $indexerRegistry,
        private readonly ConfigInterface $indexerConfig
    ) {
    }

    public function getName(): string
    {
        return 'indexer';
    }

    public function collect(): array
    {
        $indexers = $this->indexerConfig->getIndexers();
        $stuckThreshold = (new \DateTime())->modify(sprintf('-%d minutes', self::STUCK_THRESHOLD_MINUTES));

        $invalid = [];
        $stuck = [];
        $working = [];
        $scheduled = [];
        $valid = [];

        foreach (array_keys($indexers) as $indexerId) {
            try {
                $indexer = $this->indexerRegistry->get($indexerId);
                $state = $indexer->getState();
                $status = $state->getStatus();

                switch ($status) {
                    case State::STATUS_INVALID:
                        $invalid[] = $indexerId;
                        break;
                    case State::STATUS_WORKING:
                        $updatedAt = $state->getUpdated();
                        if ($updatedAt && new \DateTime($updatedAt) < $stuckThreshold) {
                            $stuck[] = $indexerId;
                        } else {
                            $working[] = $indexerId;
                        }
                        break;
                    case State::STATUS_VALID:
                        if ($indexer->isScheduled()) {
                            $scheduled[] = $indexerId;
                        }
                        $valid[] = $indexerId;
                        break;
                }
            } catch (\Exception $e) {
                $invalid[] = $indexerId;
            }
        }

        // Search index is broken if catalogsearch_fulltext is invalid or stuck
        $searchIndexHealthy = !in_array(self::SEARCH_INDEXER_ID, $invalid)
            && !in_array(self::SEARCH_INDEXER_ID, $stuck);

        // Determine status
        $status = self::STATUS_HEALTHY;
        if (!$searchIndexHealthy || count($stuck) > 0 || count($invalid) > 3) {
            $status = self::STATUS_CRITICAL;
        } elseif (count($invalid) > 0 || count($working) > 3) {
            $status = self::STATUS_DEGRADED;
        }

        return [
            'status' => $status,
            'total' => count($indexers),
            'invalid' => $invalid,
            'stuck' => $stuck,
            'working' => $working,
            'scheduled' => $scheduled,
            'valid_count' => count($valid),
            'search_index_healthy' => $searchIndexHealthy,
        ];
    }
}
