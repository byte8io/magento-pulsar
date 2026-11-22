<?php

declare(strict_types=1);

namespace Byte8\Pulsar\Cron;

use Byte8\Pulsar\Model\Config;
use Byte8\Pulsar\Model\Synthetic\SyntheticIdentity;
use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

/**
 * Hourly removal of the quotes Pulsar's checkout flow leaves behind. Each
 * flow run adds to cart as a fresh guest; with the shipping step it also
 * types an address. Those quotes are tagged with the synthetic shopper
 * email (see Observer\TagSyntheticQuote) and have no business value — this
 * deletes them once they are old enough that no run is still using them.
 * quote_item and quote_address rows go with them via foreign keys.
 */
class PruneSyntheticQuotes
{
    private const BATCH_SIZE = 500;

    public function __construct(
        private readonly Config $config,
        private readonly ResourceConnection $resourceConnection,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isEnabled() || !$this->config->isSyntheticPruneEnabled()) {
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('quote');
        $cutoff = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->sub(new \DateInterval('PT' . $this->config->getSyntheticPruneAfterHours() . 'H'))
            ->format('Y-m-d H:i:s');

        $deleted = 0;
        do {
            $ids = $connection->fetchCol(
                $connection->select()
                    ->from($table, 'entity_id')
                    ->where('customer_email LIKE ?', SyntheticIdentity::EMAIL_LIKE)
                    ->where('updated_at < ?', $cutoff)
                    ->limit(self::BATCH_SIZE)
            );
            if ($ids === []) {
                break;
            }
            $deleted += $connection->delete($table, ['entity_id IN (?)' => $ids]);
        } while (count($ids) === self::BATCH_SIZE);

        if ($deleted > 0) {
            $this->logger->info(sprintf('Pulsar pruned %d synthetic checkout quote(s)', $deleted));
        }
    }
}
