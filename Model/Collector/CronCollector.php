<?php

declare(strict_types=1);

namespace Byte8\Pulsar\Model\Collector;

use Magento\Cron\Model\ResourceModel\Schedule\CollectionFactory as ScheduleCollectionFactory;
use Magento\Cron\Model\Schedule;
use Magento\Framework\App\Config\ScopeConfigInterface;

class CronCollector implements CollectorInterface
{
    private const STUCK_THRESHOLD_MINUTES = 60;
    private const HEARTBEAT_THRESHOLD_MINUTES = 15;
    private const DEFAULT_PENDING_WARNING = 200;
    private const DEFAULT_FAILED_WARNING = 5;

    public function __construct(
        private readonly ScheduleCollectionFactory $scheduleCollectionFactory,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function getName(): string
    {
        return 'cron';
    }

    public function collect(): array
    {
        $now = new \DateTime();
        $oneDayAgo = (new \DateTime())->modify('-24 hours');
        $stuckThreshold = (new \DateTime())->modify(sprintf('-%d minutes', self::STUCK_THRESHOLD_MINUTES));
        $heartbeatThreshold = (new \DateTime())->modify(sprintf('-%d minutes', self::HEARTBEAT_THRESHOLD_MINUTES));

        // Read configurable thresholds (fallback to defaults)
        $pendingWarning = (int) ($this->scopeConfig->getValue('byte8_pulsar/checks/cron_pending_warning') ?: self::DEFAULT_PENDING_WARNING);
        $failedWarning = (int) ($this->scopeConfig->getValue('byte8_pulsar/checks/cron_failed_warning') ?: self::DEFAULT_FAILED_WARNING);

        // Heartbeat: is cron:run still scheduling new jobs?
        $latestScheduled = $this->scheduleCollectionFactory->create()
            ->setOrder('created_at', 'DESC')
            ->setPageSize(1)
            ->getFirstItem();

        $lastCreatedAt = $latestScheduled->getId() ? $latestScheduled->getCreatedAt() : null;
        $heartbeatAlive = false;
        if ($lastCreatedAt !== null) {
            $heartbeatAlive = new \DateTime($lastCreatedAt) >= $heartbeatThreshold;
        }

        // Get stuck jobs (running for too long)
        $stuckJobs = $this->scheduleCollectionFactory->create()
            ->addFieldToFilter('status', Schedule::STATUS_RUNNING)
            ->addFieldToFilter('executed_at', ['lt' => $stuckThreshold->format('Y-m-d H:i:s')])
            ->getSize();

        // Get failed jobs in last 24 hours
        $failedJobs = $this->scheduleCollectionFactory->create()
            ->addFieldToFilter('status', Schedule::STATUS_ERROR)
            ->addFieldToFilter('finished_at', ['gteq' => $oneDayAgo->format('Y-m-d H:i:s')])
            ->getSize();

        // Get last successful cron run
        $lastSuccess = $this->scheduleCollectionFactory->create()
            ->addFieldToFilter('status', Schedule::STATUS_SUCCESS)
            ->setOrder('finished_at', 'DESC')
            ->setPageSize(1)
            ->getFirstItem();

        $lastRunAt = $lastSuccess->getId() ? $lastSuccess->getFinishedAt() : null;

        // Get pending jobs count
        $pendingJobs = $this->scheduleCollectionFactory->create()
            ->addFieldToFilter('status', Schedule::STATUS_PENDING)
            ->addFieldToFilter('scheduled_at', ['lteq' => $now->format('Y-m-d H:i:s')])
            ->getSize();

        // Determine status
        $status = self::STATUS_HEALTHY;
        if (!$heartbeatAlive || $stuckJobs > 0 || $lastRunAt === null) {
            $status = self::STATUS_CRITICAL;
        } elseif ($failedJobs > $failedWarning || $pendingJobs > $pendingWarning) {
            $status = self::STATUS_DEGRADED;
        }

        return [
            'status' => $status,
            'heartbeat_alive' => $heartbeatAlive,
            'last_schedule_created' => $lastCreatedAt,
            'last_run' => $lastRunAt,
            'stuck_jobs' => $stuckJobs,
            'failed_jobs_24h' => $failedJobs,
            'pending_jobs' => $pendingJobs,
        ];
    }
}
