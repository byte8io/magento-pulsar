<?php

declare(strict_types=1);

namespace Byte8\Pulsar\Model\Collector;

use Magento\Framework\App\ResourceConnection;

class AdminSecurityCollector implements CollectorInterface
{
    private const FAILED_LOGIN_WARNING = 20;
    private const FAILED_LOGIN_CRITICAL = 100;

    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    public function getName(): string
    {
        return 'admin_security';
    }

    public function collect(): array
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $adminUserTable = $this->resourceConnection->getTableName('admin_user');
            $oneDayAgo = (new \DateTime())->modify('-24 hours')->format('Y-m-d H:i:s');

            // Total active admin users
            $totalAdmins = (int) $connection->fetchOne(
                sprintf('SELECT COUNT(*) FROM %s WHERE is_active = 1', $adminUserTable)
            );

            // Failed logins in last 24h (sum of per-user failure counts where window started recently)
            $failedLogins = (int) $connection->fetchOne(
                sprintf(
                    'SELECT COALESCE(SUM(failures_num), 0) FROM %s'
                    . ' WHERE first_failure >= :since AND failures_num > 0',
                    $adminUserTable
                ),
                ['since' => $oneDayAgo]
            );

            // Currently locked accounts
            $lockedAccounts = (int) $connection->fetchOne(
                sprintf(
                    'SELECT COUNT(*) FROM %s WHERE lock_expires IS NOT NULL AND lock_expires > NOW()',
                    $adminUserTable
                )
            );

            // Active admin sessions
            $activeSessions = 0;
            $sessionTable = $this->resourceConnection->getTableName('admin_user_session');
            if ($connection->isTableExists($sessionTable)) {
                $activeSessions = (int) $connection->fetchOne(
                    sprintf('SELECT COUNT(*) FROM %s WHERE status = 1', $sessionTable)
                );
            }

            // Admin users without 2FA configured
            $usersWithout2fa = 0;
            $tfaTable = $this->resourceConnection->getTableName('tfa_user_config');
            if ($connection->isTableExists($tfaTable)) {
                $usersWithout2fa = (int) $connection->fetchOne(
                    sprintf(
                        'SELECT COUNT(*) FROM %s u'
                        . ' WHERE u.is_active = 1'
                        . ' AND u.user_id NOT IN (SELECT DISTINCT user_id FROM %s)',
                        $adminUserTable,
                        $tfaTable
                    )
                );
            }

            // Determine status
            $status = self::STATUS_HEALTHY;
            if ($failedLogins > self::FAILED_LOGIN_CRITICAL || $lockedAccounts > 3) {
                $status = self::STATUS_CRITICAL;
            } elseif ($failedLogins > self::FAILED_LOGIN_WARNING || $usersWithout2fa > 0) {
                $status = self::STATUS_DEGRADED;
            }

            return [
                'status' => $status,
                'active_admins' => $totalAdmins,
                'failed_logins_24h' => $failedLogins,
                'locked_accounts' => $lockedAccounts,
                'active_sessions' => $activeSessions,
                'users_without_2fa' => $usersWithout2fa,
            ];
        } catch (\Exception $e) {
            return [
                'status' => self::STATUS_CRITICAL,
                'error' => 'Admin security check failed',
            ];
        }
    }
}
