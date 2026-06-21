<?php

declare(strict_types=1);

namespace Byte8\Pulsar\Model\Collector;

use Byte8\Pulsar\Model\Config;
use Magento\Framework\App\ResourceConnection;

/**
 * Scans DB-stored HTML — core_config_data head/footer "Miscellaneous HTML"
 * fields and CMS block/page content — for injected skimmer / obfuscated
 * JavaScript across ALL scopes.
 *
 * Born from the 2026-06-12 Werkzeugbilliger incident: a Magecart skimmer was
 * written directly into core_config_data (design/head/includes), rendered into
 * every page's <head>, via direct MySQL access. It left no web-log trace and
 * survived every filesystem cleanup. MediaIntegrityCollector covers pub/media/
 * webshells on disk; this collector covers the DB-content attack surface.
 *
 * Reads raw rows via ResourceConnection (NOT ScopeConfigInterface, which
 * collapses scopes and would hide a per-store/per-website injection).
 *
 * Detection is behaviour-based, not domain-based (incident lesson #6): one
 * payload pointed at one exfil domain, a second at another, so we match the
 * obfuscation technique (char-code/hex encoding, obfuscator hex vars, dynamic
 * import) rather than a hostname. Substring matching is done in PHP on full
 * values (incident lessons #4 — `LIKE '%_0x%'` mis-fires because `_` is a SQL
 * wildcard — and #5 — payloads sit *after* legit content, so never truncate
 * before scanning).
 *
 * NOTE: do NOT write live IOC hostnames as literal strings anywhere in this
 * module. External malware scanners (e.g. maxcluster) match their own IOC
 * database against our source and will flag this file as "suspicious" on a bare
 * exfil domain — a false positive on a security extension. The two domains from
 * the original incident were `tracktagapi[.]com` and `api[.]tracktagcenter[.]com`
 * (defanged on purpose). If real domain-based detection is ever added, store the
 * IOCs base64/rot13-encoded and decode at runtime so no live IOC literal ships.
 */
class ContentIntegrityCollector implements CollectorInterface
{
    /**
     * core_config_data paths that render HTML into the storefront. These are
     * scanned in full regardless of content; any other config row is scanned
     * only if it carries a <script>/http-equiv marker (see scanConfig()).
     */
    private const HTML_CONFIG_PATHS = [
        'design/head/includes',          // "Miscellaneous HTML" — the incident vector
        'design/head/demonotice',
        'design/footer/absolute_footer',
        'design/footer/copyright',
        'design/header/welcome',
    ];

    /**
     * Hostnames always trusted to appear in <script src> within stored content.
     * The store's own base-URL hosts are added at runtime, plus any operator
     * additions from admin config. A non-allowlisted external script (without
     * obfuscation) is reported as DEGRADED for review, never COMPROMISED.
     */
    private const DEFAULT_SCRIPT_ALLOWLIST = [
        // Google stack
        'googletagmanager.com',
        'google-analytics.com',
        'googletagservices.com',
        'google.com',
        'gstatic.com',
        'youtube.com',
        'recaptcha.net',
        // Consent / cookie management
        'consentmanager.net',
        'cookiebot.com',
        // CDNs
        'jquery.com',
        'jsdelivr.net',
        'cloudflare.com',
        // Payments (official SDK / button hosts loaded at checkout)
        'paypal.com',
        'paypalobjects.com',
        'klarna.com',
        'stripe.com',
        // Trust badges & reviews
        'trustedshops.com',
        'trustpilot.com',
        // Social sharing & marketing pixels
        'addthis.com',
        'facebook.net',
        // Product analytics / session replay
        'hotjar.com',
        'clarity.ms',
    ];

    /** Cap CMS rows scanned per table; truncation is reported, never silent. */
    private const CMS_SCAN_LIMIT = 5000;

    /** Cap findings returned in the payload (status is unaffected by the cap). */
    private const MAX_FINDINGS_REPORTED = 50;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly Config $config
    ) {
    }

    public function getName(): string
    {
        return 'content_integrity';
    }

    public function collect(): array
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $allowlist = $this->buildAllowlist($connection);

            $findings = [];
            $compromised = false;
            $truncated = false;

            foreach ($this->scanConfig($connection, $allowlist) as $finding) {
                $findings[] = $finding;
                $compromised = $compromised || $finding['severity'] === self::STATUS_COMPROMISED;
            }

            [$cmsFindings, $cmsCompromised, $cmsTruncated] =
                $this->scanCmsTables($connection, $allowlist);
            $findings = array_merge($findings, $cmsFindings);
            $compromised = $compromised || $cmsCompromised;
            $truncated = $truncated || $cmsTruncated;

            $status = self::STATUS_HEALTHY;
            if ($compromised) {
                $status = self::STATUS_COMPROMISED;
            } elseif ($findings !== []) {
                // Only lower-confidence signals (unrecognized external script,
                // stray CSP meta) — surface for review without crying breach.
                $status = self::STATUS_DEGRADED;
            }

            return [
                'status' => $status,
                'findings_total' => count($findings),
                'findings' => array_slice($findings, 0, self::MAX_FINDINGS_REPORTED),
                'findings_truncated' => $truncated || count($findings) > self::MAX_FINDINGS_REPORTED,
                'cms_scan_capped' => $cmsTruncated,
            ];
        } catch (\Exception $e) {
            return [
                'status' => self::STATUS_CRITICAL,
                'error' => 'Content integrity check failed',
            ];
        }
    }

    /**
     * Scan core_config_data for injected content across every scope.
     *
     * @return list<array<string, mixed>>
     */
    private function scanConfig(\Magento\Framework\DB\Adapter\AdapterInterface $connection, array $allowlist): array
    {
        $table = $this->resourceConnection->getTableName('core_config_data');

        // Candidate rows: the curated HTML paths (scanned regardless of content)
        // OR any row whose value carries a <script>/http-equiv marker (catches a
        // skimmer parked in an unexpected config path). The LIKE literals are
        // fixed and contain no `_` wildcard, so they are exact (lesson #4).
        $pathIn = $connection->quoteInto('path IN (?)', self::HTML_CONFIG_PATHS);
        $candidates = "($pathIn"
            . " OR value LIKE '%<script%'"
            . " OR value LIKE '%http-equiv%')";

        $select = $connection->select()
            ->from($table, ['config_id', 'scope', 'scope_id', 'path', 'value'])
            ->where('value IS NOT NULL')
            ->where("value != ''")
            ->where($candidates);

        $findings = [];
        foreach ($connection->fetchAll($select) as $row) {
            $value = (string) $row['value'];
            $hit = $this->scanValue($value, $allowlist);
            if ($hit === null) {
                continue;
            }
            $findings[] = $hit + [
                'source' => 'core_config_data',
                'config_id' => (int) $row['config_id'],
                'scope' => (string) $row['scope'],
                'scope_id' => (int) $row['scope_id'],
                'path' => (string) $row['path'],
            ];
        }

        return $findings;
    }

    /**
     * Scan cms_block.content and cms_page.content (capped, truncation reported).
     *
     * @return array{0: list<array<string, mixed>>, 1: bool, 2: bool}
     *         [findings, anyCompromised, truncated]
     */
    private function scanCmsTables(\Magento\Framework\DB\Adapter\AdapterInterface $connection, array $allowlist): array
    {
        $findings = [];
        $compromised = false;
        $truncated = false;

        $tables = [
            'cms_block' => ['id' => 'block_id', 'cols' => ['block_id', 'identifier', 'content']],
            'cms_page' => ['id' => 'page_id', 'cols' => ['page_id', 'identifier', 'content']],
        ];

        foreach ($tables as $logicalName => $meta) {
            $table = $this->resourceConnection->getTableName($logicalName);
            if (!$connection->isTableExists($table)) {
                continue;
            }

            $total = (int) $connection->fetchOne("SELECT COUNT(*) FROM {$table}");
            if ($total > self::CMS_SCAN_LIMIT) {
                $truncated = true;
            }

            $select = $connection->select()
                ->from($table, $meta['cols'])
                ->where('content IS NOT NULL')
                ->where("content != ''")
                ->limit(self::CMS_SCAN_LIMIT);

            foreach ($connection->fetchAll($select) as $row) {
                $hit = $this->scanValue((string) $row['content'], $allowlist);
                if ($hit === null) {
                    continue;
                }
                $compromised = $compromised || $hit['severity'] === self::STATUS_COMPROMISED;
                $findings[] = $hit + [
                    'source' => $logicalName,
                    'row_id' => (int) $row[$meta['id']],
                    'identifier' => (string) $row['identifier'],
                ];
            }
        }

        return [$findings, $compromised, $truncated];
    }

    /**
     * Classify a single stored value. Returns null when clean, otherwise a
     * finding stub: severity + matched signature labels + value metadata.
     * Never returns the payload itself.
     *
     * @return array{severity: string, signatures: list<string>, value_length: int, marker_offset: int}|null
     */
    private function scanValue(string $value, array $allowlist): ?array
    {
        $lower = strtolower($value);
        $compromise = [];
        $suspicious = [];

        // --- compromise-grade: obfuscated / encoded executable payloads ---
        if (str_contains($lower, 'fromcharcode')) {
            $compromise[] = 'String.fromCharCode char-code obfuscation';
        }
        if (str_contains($lower, 'import(_0x')) {
            $compromise[] = 'dynamic import of obfuscator variable';
        }
        if (str_contains($lower, "'68747470'") || str_contains($lower, '"68747470"')) {
            // hex for "http" — the tracktagcenter loader hid its URL this way
            $compromise[] = 'hex-encoded URL array';
        }
        if (str_contains($lower, '_0x')
            && (str_contains($lower, 'eval(')
                || str_contains($lower, 'atob(')
                || str_contains($lower, 'unescape('))
        ) {
            $compromise[] = 'obfuscator variables with dynamic execution';
        }

        // --- lower-confidence: review signals ---
        if (str_contains($lower, 'http-equiv="content-security-policy"')
            || str_contains($lower, "http-equiv='content-security-policy'")
        ) {
            // The incident injected a permissive CSP <meta> to override the
            // site's header CSP. A CSP belongs in an HTTP header, not stored HTML.
            $suspicious[] = 'inline Content-Security-Policy override';
        }
        foreach ($this->unrecognizedScriptHosts($value, $allowlist) as $host) {
            $suspicious[] = 'unrecognized external script host: ' . $host;
        }

        if ($compromise !== []) {
            return [
                'severity' => self::STATUS_COMPROMISED,
                'signatures' => array_values(array_unique(array_merge($compromise, $suspicious))),
                'value_length' => strlen($value),
                'marker_offset' => $this->firstMarkerOffset($lower),
            ];
        }
        if ($suspicious !== []) {
            return [
                'severity' => self::STATUS_DEGRADED,
                'signatures' => array_values(array_unique($suspicious)),
                'value_length' => strlen($value),
                'marker_offset' => 0,
            ];
        }

        return null;
    }

    /**
     * Offset of the first obfuscation marker — lets an operator LOCATE() the
     * payload without the collector ever transmitting it (lesson #5: it sits
     * after legitimate content, so a truncated preview would miss it).
     */
    private function firstMarkerOffset(string $lower): int
    {
        foreach (['fromcharcode', 'import(_0x', '_0x', '68747470'] as $marker) {
            $pos = strpos($lower, $marker);
            if ($pos !== false) {
                return $pos;
            }
        }

        return 0;
    }

    /**
     * Extract external <script src> hosts that are not on the allowlist.
     *
     * @return list<string>
     */
    private function unrecognizedScriptHosts(string $value, array $allowlist): array
    {
        if (!preg_match_all('/<script\b[^>]*\bsrc\s*=\s*["\']([^"\']+)["\']/i', $value, $matches)) {
            return [];
        }

        $unknown = [];
        foreach ($matches[1] as $src) {
            $host = strtolower((string) parse_url(trim($src), PHP_URL_HOST));
            if ($host === '') {
                continue; // relative / inline — internal, not an external script
            }
            if (!$this->hostAllowed($host, $allowlist)) {
                $unknown[$host] = true;
            }
        }

        return array_keys($unknown);
    }

    private function hostAllowed(string $host, array $allowlist): bool
    {
        foreach ($allowlist as $allowed) {
            if ($allowed !== '' && ($host === $allowed || str_ends_with($host, '.' . $allowed))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Default allowlist + the store's own base-URL hosts + operator additions.
     *
     * @return list<string>
     */
    private function buildAllowlist(\Magento\Framework\DB\Adapter\AdapterInterface $connection): array
    {
        $allowlist = self::DEFAULT_SCRIPT_ALLOWLIST;

        // The store's own hosts (all scopes) are trusted for self-hosted scripts.
        $table = $this->resourceConnection->getTableName('core_config_data');
        $select = $connection->select()
            ->from($table, ['value'])
            ->where('path IN (?)', ['web/secure/base_url', 'web/unsecure/base_url'])
            ->where('value IS NOT NULL');
        foreach ($connection->fetchCol($select) as $baseUrl) {
            $host = strtolower((string) parse_url((string) $baseUrl, PHP_URL_HOST));
            if ($host !== '') {
                $allowlist[] = $host;
            }
        }

        // Operator additions (comma / whitespace separated).
        $extra = preg_split('/[\s,]+/', strtolower($this->config->getContentIntegrityScriptAllowlist()));
        foreach ((array) $extra as $host) {
            $host = trim($host);
            if ($host !== '') {
                $allowlist[] = $host;
            }
        }

        return array_values(array_unique($allowlist));
    }
}
