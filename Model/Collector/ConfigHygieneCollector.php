<?php

declare(strict_types=1);

namespace Byte8\Pulsar\Model\Collector;

use Magento\Framework\App\Config\ScopeConfigInterface;

class ConfigHygieneCollector implements CollectorInterface
{
    /**
     * Settings that SHOULD be off — being on is a security/debug risk.
     * Maps config path => human-readable label.
     */
    private const MUST_BE_OFF = [
        'dev/debug/template_hints_storefront' => 'Storefront template hints enabled',
        'dev/debug/template_hints_admin' => 'Admin template hints enabled',
        'dev/template/allow_symlink' => 'Template symlinks allowed',
    ];

    /**
     * Settings that SHOULD be on — being off degrades performance or best practices.
     * Maps config path => human-readable label.
     */
    private const SHOULD_BE_ON = [
        'dev/css/minify_files' => 'CSS minification disabled',
        'dev/js/minify_files' => 'JS minification disabled',
        'dev/css/merge_css_files' => 'CSS merge disabled',
        'dev/js/merge_files' => 'JS merge disabled',
        'dev/static/sign' => 'Static content signing disabled',
        'sales_email/general/async_sending' => 'Async email sending disabled',
        'web/seo/use_rewrites' => 'URL rewrites disabled',
    ];

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function getName(): string
    {
        return 'config_hygiene';
    }

    public function collect(): array
    {
        $critical = [];
        $warnings = [];

        // These must be OFF — flag if they're ON
        foreach (self::MUST_BE_OFF as $path => $label) {
            if ($this->scopeConfig->isSetFlag($path)) {
                $critical[] = $label;
            }
        }

        // These should be ON — flag if they're OFF
        foreach (self::SHOULD_BE_ON as $path => $label) {
            if (!$this->scopeConfig->isSetFlag($path)) {
                $warnings[] = $label;
            }
        }

        $totalChecks = count(self::MUST_BE_OFF) + count(self::SHOULD_BE_ON);
        $issueCount = count($critical) + count($warnings);

        $status = self::STATUS_HEALTHY;
        if (!empty($critical)) {
            $status = self::STATUS_CRITICAL;
        } elseif (!empty($warnings)) {
            $status = self::STATUS_DEGRADED;
        }

        return [
            'status' => $status,
            'critical_issues' => $critical,
            'warnings' => $warnings,
            'checks_passed' => $totalChecks - $issueCount,
            'checks_total' => $totalChecks,
        ];
    }
}
