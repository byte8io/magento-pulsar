<?php

declare(strict_types=1);

namespace Byte8\Pulsar\Model\Collector;

use Magento\Framework\App\ResourceConnection;

class IntegrationCollector implements CollectorInterface
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    public function getName(): string
    {
        return 'integrations';
    }

    public function collect(): array
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $integrationTable = $this->resourceConnection->getTableName('integration');

            if (!$connection->isTableExists($integrationTable)) {
                return [
                    'status' => self::STATUS_HEALTHY,
                    'total' => 0,
                ];
            }

            // Count integrations by status (0 = inactive, 1 = active)
            $byStatus = $connection->fetchPairs(
                sprintf('SELECT status, COUNT(*) FROM %s GROUP BY status', $integrationTable)
            );

            $active = (int) ($byStatus[1] ?? 0);
            $inactive = (int) ($byStatus[0] ?? 0);
            $total = $active + $inactive;

            if ($total === 0) {
                return [
                    'status' => self::STATUS_HEALTHY,
                    'total' => 0,
                ];
            }

            // Check for broken integrations: active but no valid access token
            $broken = 0;
            $oauthTokenTable = $this->resourceConnection->getTableName('oauth_token');
            if ($connection->isTableExists($oauthTokenTable) && $active > 0) {
                $broken = (int) $connection->fetchOne(
                    sprintf(
                        'SELECT COUNT(*) FROM %s i'
                        . ' WHERE i.status = 1 AND i.consumer_id IS NOT NULL'
                        . ' AND NOT EXISTS ('
                        . '   SELECT 1 FROM %s t'
                        . '   WHERE t.consumer_id = i.consumer_id'
                        . "   AND t.type = 'access'"
                        . '   AND t.revoked = 0'
                        . ' )',
                        $integrationTable,
                        $oauthTokenTable
                    )
                );
            }

            // Stale revoked tokens that could be cleaned up
            $revokedTokens = 0;
            if ($connection->isTableExists($oauthTokenTable)) {
                $revokedTokens = (int) $connection->fetchOne(
                    sprintf('SELECT COUNT(*) FROM %s WHERE revoked = 1', $oauthTokenTable)
                );
            }

            // Determine status
            $status = self::STATUS_HEALTHY;
            if ($broken > 0) {
                $status = self::STATUS_CRITICAL;
            } elseif ($revokedTokens > 50) {
                $status = self::STATUS_DEGRADED;
            }

            return [
                'status' => $status,
                'total' => $total,
                'active' => $active,
                'inactive' => $inactive,
                'broken' => $broken,
                'revoked_tokens' => $revokedTokens,
            ];
        } catch (\Exception $e) {
            return [
                'status' => self::STATUS_CRITICAL,
                'error' => 'Integration check failed',
            ];
        }
    }
}
