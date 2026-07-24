<?php

declare(strict_types=1);

namespace Byte8\Pulsar\Model\Collector;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;

/**
 * Watches sales transactional emails (order / invoice / shipment / creditmemo
 * confirmations) that were requested but never handed to the mail transport.
 *
 * Two distinct failure states, distinguished by the email_sent flag:
 *
 * - email_sent IS NULL with send_email = 1: the synchronous send threw (e.g.
 *   SMTP timeout) after the entity was saved. Magento's retry cron only picks
 *   up email_sent = 0, so NULL is a PERMANENT silent loss — the customer never
 *   gets the confirmation and nothing ever retries it.
 * - email_sent = 0: queued for the async sales_send_order_emails cron. Normal
 *   for a few minutes when async sending is on; a backlog older than the
 *   thresholds means the cron or the SMTP transport is failing.
 */
class TransactionalEmailCollector implements CollectorInterface
{
    private const XML_PATH_ASYNC_SENDING = 'sales_email/general/async_sending';

    /**
     * Ignore entities younger than this so an in-flight async send (queued,
     * cron not yet run) is never flagged.
     */
    private const GRACE_MINUTES = 30;

    /**
     * Only look back this far. Older stuck rows are legacy data the operator
     * already knows about (or never will act on); a trailing window also means
     * an incident ages out of the alert once handled instead of pinning the
     * collector red forever.
     */
    private const WINDOW_HOURS = 48;

    private const LOST_CRITICAL_THRESHOLD = 5;
    private const PENDING_WARNING_MINUTES = 30;
    private const PENDING_CRITICAL_MINUTES = 120;
    private const SAMPLE_LIMIT = 10;

    private const ENTITY_TABLES = [
        'order' => 'sales_order',
        'invoice' => 'sales_invoice',
        'shipment' => 'sales_shipment',
        'creditmemo' => 'sales_creditmemo',
    ];

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function getName(): string
    {
        return 'transactional_email';
    }

    public function collect(): array
    {
        try {
            $connection = $this->resourceConnection->getConnection();

            $windowStart = (new \DateTime())
                ->modify(sprintf('-%d hours', self::WINDOW_HOURS))
                ->format('Y-m-d H:i:s');
            $graceCutoff = (new \DateTime())
                ->modify(sprintf('-%d minutes', self::GRACE_MINUTES))
                ->format('Y-m-d H:i:s');

            $lostByEntity = [];
            $pendingTotal = 0;
            $oldestUnsent = null;

            foreach (self::ENTITY_TABLES as $entity => $table) {
                $tableName = $this->resourceConnection->getTableName($table);
                if (!$connection->isTableExists($tableName)) {
                    $lostByEntity[$entity] = 0;
                    continue;
                }

                // Canceled orders legitimately never get a confirmation.
                $stateFilter = $entity === 'order' ? " AND state != 'canceled'" : '';

                $row = $connection->fetchRow(
                    sprintf(
                        'SELECT
                            COALESCE(SUM(email_sent IS NULL), 0) AS lost,
                            COALESCE(SUM(email_sent = 0), 0) AS pending,
                            MIN(created_at) AS oldest
                        FROM %s
                        WHERE send_email = 1
                            AND (email_sent IS NULL OR email_sent = 0)
                            AND created_at BETWEEN :window_start AND :grace_cutoff%s',
                        $tableName,
                        $stateFilter
                    ),
                    ['window_start' => $windowStart, 'grace_cutoff' => $graceCutoff]
                );

                $lostByEntity[$entity] = (int) ($row['lost'] ?? 0);
                $pendingTotal += (int) ($row['pending'] ?? 0);

                $oldest = $row['oldest'] ?? null;
                if ($oldest !== null && ($oldestUnsent === null || $oldest < $oldestUnsent)) {
                    $oldestUnsent = $oldest;
                }
            }

            $lostTotal = (int) array_sum($lostByEntity);
            $asyncEnabled = $this->scopeConfig->isSetFlag(self::XML_PATH_ASYNC_SENDING);

            $oldestMinutes = 0;
            if ($oldestUnsent !== null) {
                $oldestMinutes = (int) floor(
                    ((new \DateTime())->getTimestamp() - (new \DateTime($oldestUnsent))->getTimestamp()) / 60
                );
            }

            [$status, $note] = $this->evaluate($lostTotal, $pendingTotal, $oldestMinutes, $asyncEnabled);

            $result = [
                'status' => $status,
                'order_unsent' => $lostByEntity['order'],
                'invoice_unsent' => $lostByEntity['invoice'],
                'shipment_unsent' => $lostByEntity['shipment'],
                'creditmemo_unsent' => $lostByEntity['creditmemo'],
                'pending_retry' => $pendingTotal,
                'oldest_unsent_minutes' => $oldestMinutes,
                'async_enabled' => $asyncEnabled,
            ];

            if ($lostByEntity['order'] > 0) {
                $result['sample_increment_ids'] = $this->sampleIncrementIds(
                    $connection,
                    $windowStart,
                    $graceCutoff
                );
            }
            if ($note !== null) {
                $result['note'] = $note;
            }

            return $result;
        } catch (\Exception $e) {
            return [
                'status' => self::STATUS_CRITICAL,
                'error' => 'Transactional email check failed',
            ];
        }
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private function evaluate(int $lostTotal, int $pendingTotal, int $oldestMinutes, bool $asyncEnabled): array
    {
        // email_sent NULL is never retried by Magento regardless of the async
        // setting — every one is a confirmation the customer will not receive.
        if ($lostTotal >= self::LOST_CRITICAL_THRESHOLD) {
            return [
                self::STATUS_CRITICAL,
                sprintf(
                    '%d transactional emails permanently unsent (sync send failed, no retry). '
                    . 'Check SMTP transport; resend manually.',
                    $lostTotal
                ),
            ];
        }

        if ($lostTotal > 0) {
            $note = sprintf(
                '%d transactional email(s) permanently unsent (sync send failed, no retry).',
                $lostTotal
            );
            if (!$asyncEnabled) {
                $note .= ' Async sending (sales_email/general/async_sending) is OFF — '
                    . 'every SMTP blip during checkout loses the confirmation with no retry.';
            }
            return [self::STATUS_DEGRADED, $note];
        }

        // Async backlog: entries the retry cron should have drained by now.
        if ($asyncEnabled && $pendingTotal > 0) {
            if ($oldestMinutes >= self::PENDING_CRITICAL_MINUTES) {
                return [
                    self::STATUS_CRITICAL,
                    sprintf(
                        '%d queued sales emails stuck for %d min — sales_send_order_emails cron '
                        . 'or SMTP transport is failing.',
                        $pendingTotal,
                        $oldestMinutes
                    ),
                ];
            }
            if ($oldestMinutes >= self::PENDING_WARNING_MINUTES) {
                return [
                    self::STATUS_DEGRADED,
                    sprintf(
                        '%d queued sales emails waiting %d min for the async send cron.',
                        $pendingTotal,
                        $oldestMinutes
                    ),
                ];
            }
        }

        return [self::STATUS_HEALTHY, null];
    }

    /**
     * Increment IDs of permanently-lost order confirmations, newest first, so
     * an operator can resend without re-querying.
     *
     * @return string[]
     */
    private function sampleIncrementIds(
        \Magento\Framework\DB\Adapter\AdapterInterface $connection,
        string $windowStart,
        string $graceCutoff
    ): array {
        $orderTable = $this->resourceConnection->getTableName('sales_order');
        if (!$connection->isTableExists($orderTable)) {
            return [];
        }

        return array_map(
            'strval',
            $connection->fetchCol(
                sprintf(
                    "SELECT increment_id FROM %s
                    WHERE send_email = 1
                        AND email_sent IS NULL
                        AND state != 'canceled'
                        AND created_at BETWEEN :window_start AND :grace_cutoff
                    ORDER BY created_at DESC
                    LIMIT %d",
                    $orderTable,
                    self::SAMPLE_LIMIT
                ),
                ['window_start' => $windowStart, 'grace_cutoff' => $graceCutoff]
            )
        );
    }
}
