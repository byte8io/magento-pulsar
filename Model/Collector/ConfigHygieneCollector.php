<?php

declare(strict_types=1);

namespace Byte8\Pulsar\Model\Collector;

use Magento\Framework\App\Config\ScopeConfigInterface;

class ConfigHygieneCollector implements CollectorInterface
{
    /**
     * Settings that MUST be off — being on is a security/debug risk.
     * Maps config path => human-readable label.
     */
    private const MUST_BE_OFF = [
        'dev/debug/template_hints_storefront' => 'Storefront template hints enabled',
        'dev/debug/template_hints_admin' => 'Admin template hints enabled',
        'dev/template/allow_symlink' => 'Template symlinks allowed',
    ];

    /**
     * Settings that SHOULD be on — being off degrades performance.
     * Maps config path => human-readable label.
     */
    private const SHOULD_BE_ON = [
        'dev/css/minify_files' => 'CSS minification disabled',
        'dev/js/minify_files' => 'JS minification disabled',
        'dev/static/sign' => 'Static content signing disabled',
        'web/seo/use_rewrites' => 'URL rewrites disabled',
    ];

    /**
     * Settings that are advisory — reported for visibility but don't affect status.
     * With HTTP/2, merge can hurt performance; async email is a best practice
     * but not a performance concern for storefront.
     * Maps config path => human-readable label.
     */
    private const ADVISORY_ON = [
        'dev/css/merge_css_files' => 'CSS merge disabled',
        'dev/js/merge_files' => 'JS merge disabled',
        'sales_email/general/async_sending' => 'Async email sending disabled',
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
        $advisory = [];

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

        // Advisory — reported but don't affect status
        foreach (self::ADVISORY_ON as $path => $label) {
            if (!$this->scopeConfig->isSetFlag($path)) {
                $advisory[] = $label;
            }
        }

        $totalChecks = count(self::MUST_BE_OFF) + count(self::SHOULD_BE_ON) + count(self::ADVISORY_ON);
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
            'advisory' => $advisory,
            'checks_passed' => $totalChecks - $issueCount - count($advisory),
            'checks_total' => $totalChecks,
        ];
    }
}
