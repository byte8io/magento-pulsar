<?php

declare(strict_types=1);

namespace Byte8\Pulsar\Model\Collector;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;

/**
 * Watches sales transactional emails (order / invoice / shipment / creditmemo
 * confirmations) that were requested but never handed to the mail transport.
 *
 * Magento 2.4.8+ uses email_sent as an attempt counter, not a flag
 * (Magento\Sales\Model\EmailSenderHandler::sendEmails). Up to 2.4.7 the
 * async cron only picks up NULL and leaves a failed send at NULL, so it is
 * retried on every run and never reaches 0; the same classification holds.
 *
 *   NULL        async on:  queued, the send cron has not tried yet
 *               async off: the synchronous send threw after save — nothing
 *                          will ever retry it
 *   -N … -1     async on:  N attempts left; the cron retries these
 *   0           every attempt failed (async) — Magento has GIVEN UP.
 *               Also the legacy "send failed" value from older releases.
 *   1           sent
 *
 * So a permanent loss is email_sent = 0, plus email_sent IS NULL when async
 * sending is off. A pending retry is NULL or negative with async on. Earlier
 * module versions had this backwards (0 = "pending retry") and told merchants
 * Magento would still retry confirmations it had already abandoned.
 */
class TransactionalEmailCollector implements CollectorInterface
{
    private const XML_PATH_ASYNC_SENDING = 'sales_email/general/async_sending';

    /**
     * Ignore entities younger than this so an in-flight async send (queued,
     * cron not yet run, or mid-retry) is never flagged.
     */
    private const GRACE_MINUTES = 30;

    /**
     * Only look back this far. Older stuck rows are legacy data the operator
     * already knows about (or never will act on); a trailing window also means
     * an incident ages out of the alert once handled instead of pinning the
     * collector red forever. An alert that stays red for a week therefore
     * means the transport is still failing, not that old rows linger.
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
            $asyncEnabled = $this->scopeConfig->isSetFlag(self::XML_PATH_ASYNC_SENDING);

            $windowStart = (new \DateTime())
                ->modify(sprintf('-%d hours', self::WINDOW_HOURS))
                ->format('Y-m-d H:i:s');
            $graceCutoff = (new \DateTime())
                ->modify(sprintf('-%d minutes', self::GRACE_MINUTES))
                ->format('Y-m-d H:i:s');

            [$lostExpr, $pendingExpr] = $this->classificationSql($asyncEnabled);

            $lostByEntity = [];
            $pendingTotal = 0;
            $oldestPending = null;

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
                            COALESCE(SUM(%s), 0) AS lost,
                            COALESCE(SUM(%s), 0) AS pending,
                            MIN(CASE WHEN %s THEN created_at END) AS oldest_pending
                        FROM %s
                        WHERE send_email = 1
                            AND (email_sent IS NULL OR email_sent <= 0)
                            AND created_at BETWEEN :window_start AND :grace_cutoff%s',
                        $lostExpr,
                        $pendingExpr,
                        $pendingExpr,
                        $tableName,
                        $stateFilter
                    ),
                    ['window_start' => $windowStart, 'grace_cutoff' => $graceCutoff]
                );

                $lostByEntity[$entity] = (int) ($row['lost'] ?? 0);
                $pendingTotal += (int) ($row['pending'] ?? 0);

                $oldest = $row['oldest_pending'] ?? null;
                if ($oldest !== null && ($oldestPending === null || $oldest < $oldestPending)) {
                    $oldestPending = $oldest;
                }
            }

            $lostTotal = (int) array_sum($lostByEntity);

            $oldestPendingMinutes = 0;
            if ($oldestPending !== null) {
                $oldestPendingMinutes = (int) floor(
                    ((new \DateTime())->getTimestamp() - (new \DateTime($oldestPending))->getTimestamp()) / 60
                );
            }

            [$status, $note] = $this->evaluate($lostTotal, $pendingTotal, $oldestPendingMinutes, $asyncEnabled);

            $result = [
                'status' => $status,
                'order_unsent' => $lostByEntity['order'],
                'invoice_unsent' => $lostByEntity['invoice'],
                'shipment_unsent' => $lostByEntity['shipment'],
                'creditmemo_unsent' => $lostByEntity['creditmemo'],
                'pending_retry' => $pendingTotal,
                'oldest_pending_minutes' => $oldestPendingMinutes,
                'async_enabled' => $asyncEnabled,
            ];

            if ($lostByEntity['order'] > 0) {
                $result['sample_increment_ids'] = $this->sampleIncrementIds(
                    $connection,
                    $lostExpr,
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
     * SQL boolean expressions for "permanently lost" and "still being retried",
     * given the store's async setting. See the class docblock for the state
     * table these encode.
     *
     * @return array{0: string, 1: string}
     */
    private function classificationSql(bool $asyncEnabled): array
    {
        if ($asyncEnabled) {
            return ['email_sent = 0', '(email_sent IS NULL OR email_sent < 0)'];
        }
        // Without the async cron nothing is ever retried: NULL (sync send
        // threw) and 0 (legacy failure marker) are both final.
        return ['(email_sent IS NULL OR email_sent = 0)', '0'];
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private function evaluate(int $lostTotal, int $pendingTotal, int $oldestPendingMinutes, bool $asyncEnabled): array
    {
        if ($lostTotal >= self::LOST_CRITICAL_THRESHOLD) {
            return [
                self::STATUS_CRITICAL,
                sprintf(
                    '%d transactional emails permanently unsent — Magento has exhausted its retries '
                    . 'and will not try again. Check the SMTP transport, then resend manually.',
                    $lostTotal
                ),
            ];
        }

        if ($lostTotal > 0) {
            $note = sprintf(
                '%d transactional email(s) permanently unsent — Magento will not retry them.',
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
            if ($oldestPendingMinutes >= self::PENDING_CRITICAL_MINUTES) {
                return [
                    self::STATUS_CRITICAL,
                    sprintf(
                        '%d queued sales emails still retrying after %d min — sales_send_order_emails cron '
                        . 'or SMTP transport is failing. Once retries run out they become permanent losses.',
                        $pendingTotal,
                        $oldestPendingMinutes
                    ),
                ];
            }
            if ($oldestPendingMinutes >= self::PENDING_WARNING_MINUTES) {
                return [
                    self::STATUS_DEGRADED,
                    sprintf(
                        '%d queued sales emails waiting %d min for the async send cron.',
                        $pendingTotal,
                        $oldestPendingMinutes
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
        string $lostExpr,
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
                        AND %s
                        AND state != 'canceled'
                        AND created_at BETWEEN :window_start AND :grace_cutoff
                    ORDER BY created_at DESC
                    LIMIT %d",
                    $orderTable,
                    $lostExpr,
                    self::SAMPLE_LIMIT
                ),
                ['window_start' => $windowStart, 'grace_cutoff' => $graceCutoff]
            )
        );
    }
}
