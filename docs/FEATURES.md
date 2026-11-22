# Byte8 Pulsar — Magento 2 Extension

Pulsar is a health monitoring extension for Magento 2 that exposes internal system metrics via a secure JSON endpoint. It is designed to be consumed by the [Pulsar monitoring service](https://pulsar.byte8.io), giving operations teams real-time visibility into the health of their Magento stores.

## How It Works

The extension registers a single frontend route:

```
GET /pulsar/health
```

When called with a valid API key, it runs all enabled collectors and returns a JSON response with the overall status and per-collector details. The Pulsar monitoring service polls this endpoint on a configurable interval and triggers alerts when collectors report degraded or critical status.

### Response Format

```json
{
  "status": "healthy",
  "timestamp": "2026-02-16T14:30:00+00:00",
  "version": "1.15.1",
  "checks": {
    "cron": {
      "status": "healthy",
      "heartbeat_alive": true,
      "last_schedule_created": "2026-02-16 14:29:00",
      "last_run": "2026-02-16 14:28:45",
      "stuck_jobs": 0,
      "failed_jobs_24h": 2,
      "pending_jobs": 15
    },
    "indexer": { "..." },
    "cache": { "..." },
    "database": { "..." },
    "system": { "..." },
    "search": { "..." },
    "queue": { "..." },
    "deploy": { "..." },
    "redis": { "..." },
    "log": { "..." },
    "admin_security": { "..." },
    "config_hygiene": { "..." },
    "ssl": { "..." },
    "orders": { "..." },
    "integrations": { "..." },
    "phpfpm": { "..." },
    "media_integrity": { "..." },
    "upload_endpoint": { "..." },
    "database_size": { "..." },
    "log_errors": { "..." },
    "content_integrity": { "..." },
    "transactional_email": { "..." },
    "applied_patches": { "..." },
    "checkout_funnel": { "..." }
  }
}
```

The top-level `status` is the worst status across all enabled collectors. The ranking, worst-first, is `compromised` > `critical` > `degraded` > `healthy` — `compromised` is a security state (tamper evidence) surfaced separately from the uptime axis, emitted by `media_integrity`, `content_integrity`, and `upload_endpoint`.

### Authentication

Every request must include the API key in one of two ways:

- **Header:** `X-Pulsar-Key: psk_your-api-key`
- **Bearer token:** `Authorization: Bearer psk_your-api-key`

Requests without a valid key receive a `401 Unauthorized` response. The API key comparison uses `hash_equals()` to prevent timing attacks. Keys are stored encrypted in the database.

### Security

- The API key is encrypted at rest using Magento's `EncryptorInterface`
- CSRF validation is bypassed for this endpoint (it implements `CsrfAwareActionInterface`) since it is called by an external service, not a browser
- Error messages returned to the caller never contain internal details (stack traces, file paths, connection strings) — those are logged server-side instead
- The endpoint returns `503 Service Unavailable` when the module is disabled or not configured, so no health data leaks

---

## Collectors

### Cron

Monitors the Magento cron system, including a heartbeat that detects when `cron:run` has stopped entirely.

| Metric | Description |
|--------|-------------|
| `heartbeat_alive` | Whether new jobs were scheduled in the last 15 minutes |
| `last_schedule_created` | Timestamp of the most recently created schedule entry |
| `last_run` | Timestamp of the last successful cron execution |
| `stuck_jobs` | Jobs in `running` state for over 60 minutes |
| `failed_jobs_24h` | Jobs that failed in the last 24 hours |
| `pending_jobs` | Jobs past their scheduled time that haven't started |

**Status:**
- Critical: heartbeat dead (no jobs created in 15 min), stuck jobs, or no successful run ever
- Degraded: > 5 failed jobs or > 100 pending jobs

**Why it matters:** If `cron:run` dies, no new jobs are scheduled. Queues stop processing, indexers stop updating, emails stop sending — but nothing looks obviously broken until customers notice stale data.

---

### Indexer

Monitors all Magento indexers with stuck detection and explicit `catalogsearch_fulltext` tracking.

| Metric | Description |
|--------|-------------|
| `total` | Total number of configured indexers |
| `invalid` | List of indexer IDs in `invalid` state |
| `stuck` | List of indexer IDs in `working` state for over 1 hour |
| `working` | List of indexer IDs actively working (< 1 hour) |
| `scheduled` | List of indexer IDs using "Update on Schedule" mode |
| `valid_count` | Number of indexers in `valid` state |
| `search_index_healthy` | Whether `catalogsearch_fulltext` is valid and not stuck |

**Status:**
- Critical: `catalogsearch_fulltext` invalid/stuck, any stuck indexer, or > 3 invalid
- Degraded: any invalid indexer or > 3 actively working

**Why it matters:** A stuck `catalogsearch_fulltext` indexer means customers are searching a stale catalog — products with wrong prices, missing inventory, or removed items still appearing.

---

### Cache

Monitors Magento cache types for disabled or invalidated states.

| Metric | Description |
|--------|-------------|
| `total_types` | Total number of cache types |
| `disabled_count` | Number of disabled cache types |
| `invalidated_count` | Number of invalidated cache types |
| `types` | Status of key types: `config`, `layout`, `block_html`, `full_page`, `collections` |

**Status:**
- Critical: > 2 disabled types or > 5 invalidated types
- Degraded: > 3 invalidated types

---

### Database

Monitors database connectivity and response time.

| Metric | Description |
|--------|-------------|
| `connection_time_ms` | Time to execute `SELECT 1` |
| `threads_connected` | Current number of database connections |
| `max_used_connections` | Peak connection count since server start |
| `uptime_seconds` | Database server uptime |

**Status:**
- Critical: connection failed
- Degraded: connection time > 100ms

---

### System

Reports PHP and Magento version info, disk space, and memory usage.

| Metric | Description |
|--------|-------------|
| `php_version` | Running PHP version |
| `magento_version` | Magento application version |
| `magento_edition` | Community or Enterprise |
| `memory_limit` | PHP memory limit setting |
| `max_execution_time` | PHP max execution time |
| `disk_free_pct` | Free disk space percentage (var directory) |
| `disk_free_gb` | Free disk space in GB |
| `memory_usage_mb` | Current PHP memory usage |
| `memory_peak_mb` | Peak PHP memory usage |

**Status:**
- Critical: disk free < 10%
- Degraded: disk free < 20%

---

### Search Engine

Monitors the Elasticsearch or OpenSearch cluster that powers catalog search.

| Metric | Description |
|--------|-------------|
| `engine` | Detected engine type (`elasticsearch7`, `opensearch`) |
| `cluster_status` | Cluster health: `green`, `yellow`, `red` |
| `number_of_nodes` | Nodes in the cluster |
| `active_shards` | Active shard count |
| `unassigned_shards` | Unassigned shard count |
| `pending_tasks` | Pending cluster tasks |
| `index_count` | Number of indices matching the configured prefix |

**Status:**
- Critical: cluster red, unreachable, no indices, or hostname not configured
- Degraded: cluster yellow

Auto-detects engine type and connection details (including auth) from Magento's `catalog/search` configuration.

---

### Message Queue

Monitors the MySQL message queue for stuck or failed async operations.

| Metric | Description |
|--------|-------------|
| `backend` | Queue backend type (`mysql` or `amqp`) |
| `pending` | Messages waiting to be processed (new + retry) |
| `in_progress` | Messages currently being processed |
| `errors` | Messages in error state |
| `completed` | Messages successfully processed |
| `oldest_pending` | Timestamp of the oldest unprocessed message |

**Status:**
- Critical: > 5,000 pending or > 200 errors
- Degraded: > 1,000 pending or > 50 errors

Gracefully handles AMQP-only setups where MySQL queue tables don't exist.

---

### Deploy Mode

Detects deploy mode misconfigurations, maintenance status, and compilation state.

| Metric | Description |
|--------|-------------|
| `mode` | Current deploy mode: `production`, `developer`, `default` |
| `maintenance` | Whether maintenance mode is active |
| `compiled` | Whether DI compilation exists (`generated/metadata/global.php`) |
| `static_deployed` | Whether static content has been deployed |
| `static_version` | Deployed static content version string |
| `var_writable` | Whether `var/` is writable (write-probe) |
| `static_writable` | Whether `pub/static` is writable (write-probe) |

**Status:**
- Critical: developer mode, or `var/` not writable (cache, sessions, logs all need it)
- Degraded: default mode, maintenance active, missing compilation/static in production, or `pub/static` not writable in production

**Why it matters:** Developer mode in production causes severe performance degradation and exposes debug information to visitors. Maintenance mode may be left on accidentally after deployments.

---

### Redis

Monitors Redis instances used for sessions, cache, and full page cache.

| Metric (per instance) | Description |
|------------------------|-------------|
| `connected` | Whether the connection succeeded |
| `used_memory_mb` | Current memory usage in MB |
| `max_memory_mb` | Configured memory limit in MB |
| `memory_usage_pct` | Memory usage as percentage of max |
| `evicted_keys` | Total keys evicted due to memory pressure |
| `connected_clients` | Number of connected clients |
| `redis_version` | Redis server version |

**Instances checked:** `session`, `cache`, `page_cache` — auto-discovered from `env.php`.

**Status (per instance, rolled up to worst):**
- Critical: connection failed or memory > 90%
- Degraded: memory 80–90% or evicted keys > 1,000

Reports `configured: false` (healthy) if Redis is not used at all. Reports a degraded note if the PHP Redis extension is not available.

---

### Log Health

Monitors log file sizes and error report accumulation.

| Metric | Description |
|--------|-------------|
| `debug_logging_enabled` | Whether debug logging is turned on |
| `log_sizes_mb` | Size in MB of `exception.log`, `system.log`, `debug.log` |
| `report_count` | Total files in `var/report/` (capped at 10,000) — informational |
| `report_count_24h` | Error reports written in the last 24 hours — drives the status |
| `note` | Housekeeping hint when ≥ 1,000 old reports have accumulated |

**Status:**
- Critical: any log > 2 GB or > 200 error reports in the last 24 h
- Degraded: any log > 500 MB, debug logging enabled, or > 50 error reports in the last 24 h

**Why it matters:** Runaway logging fills disks. Debug logging in production generates massive files and degrades performance. A burst of fresh error reports means customers are hitting unhandled exceptions *now*; a large but stale `var/report/` only means nobody has cleaned up, so it no longer colours the status.

---

### Admin Security

Monitors admin panel security posture and brute-force indicators.

| Metric | Description |
|--------|-------------|
| `active_admins` | Total active admin user accounts |
| `failed_logins_24h` | Failed login attempts in the last 24 hours |
| `locked_accounts` | Accounts currently locked (`lock_expires` in the future) |
| `active_sessions` | Active admin sessions (from `admin_user_session` table) |
| `users_without_2fa` | Admin users without Two-Factor Authentication configured |

**Status:**
- Critical: > 100 failed logins in 24h or > 3 locked accounts
- Degraded: > 20 failed logins in 24h or any user without 2FA

**Why it matters:** A spike in failed logins indicates a brute-force attack in progress. Locked accounts may mean admin credentials have been compromised. Users without 2FA are vulnerable to credential stuffing.

**Edge cases:** If the `admin_user_session` table is missing, active sessions report as 0. If the `tfa_user_config` table is missing (TFA module disabled), the 2FA check is skipped entirely.

---

### Config Hygiene

Audits production configuration against best practices. Pure config reads — no database queries.

| Metric | Description |
|--------|-------------|
| `checks_passed` | Number of config checks that passed |
| `checks_total` | Total number of config checks |
| `critical_issues` | Array of settings that must be OFF but are ON |
| `warnings` | Array of settings that should be ON but are OFF |

**Critical checks (must be OFF):**
- Template hints on storefront (`dev/debug/template_hints_storefront`)
- Template hints in admin (`dev/debug/template_hints_admin`)
- Template symlinks (`dev/template/allow_symlink`)

**Warning checks (should be ON — being off triggers Degraded):**
- CSS minification (`dev/css/minify_files`), JS minification (`dev/js/minify_files`)
- Static content signing (`dev/static/sign`)
- URL rewrites (`web/seo/use_rewrites`)

**Advisory checks (reported in the `advisory` field, but do NOT change status):**
- CSS merge (`dev/css/merge_css_files`), JS merge (`dev/js/merge_files`) — can hurt performance under HTTP/2
- Async email sending (`sales_email/general/async_sending`) — best practice, but not a storefront performance concern

**Status:**
- Critical: any "must be off" setting is on (exposes debug info or security risk)
- Degraded: any "should be on" setting is off (performance or SEO impact)
- Advisory items appear in the payload for visibility only and never move the status

**Why it matters:** Template hints in production leak internal file paths to visitors. Missing minification degrades page load performance. The advisory items are flagged for awareness without raising a false alarm.

---

### SSL / Certificate

Checks SSL certificate validity for the store's secure base URL.

| Metric | Description |
|--------|-------------|
| `host` | Hostname parsed from `web/secure/base_url` |
| `https_configured` | Whether HTTPS is configured in Magento |
| `issuer` | Certificate issuer name |
| `valid_from` | Certificate start date |
| `valid_to` | Certificate expiry date |
| `days_until_expiry` | Days remaining before certificate expires |

**Status:**
- Critical: certificate expired, < 7 days remaining, or certificate unretrievable
- Degraded: < 30 days remaining or HTTPS not configured

**Why it matters:** An expired SSL certificate breaks the site for all visitors with a browser security warning. Let's Encrypt certificates renew every 90 days — a misconfigured renewal process can silently fail, only detected when it's too late.

**Implementation:** Uses `stream_socket_client` with `ssl://` and `openssl_x509_parse()`. Sets `verify_peer: false` to handle self-signed certificates without failing. 5-second connection timeout.

---

### Order Pipeline

Revenue-affecting monitoring for stuck or failed orders.

| Metric | Description |
|--------|-------------|
| `total_24h` | Total orders placed in the last 24 hours |
| `stuck_pending_payment` | Orders in `pending_payment` state for > 4 hours |
| `failed_canceled_24h` | Failed or canceled orders in the last 24 hours |
| `by_status_24h` | Full status breakdown map for orders in the last 24 hours |

**Status:**
- Critical: > 20 stuck pending_payment orders
- Degraded: > 5 stuck orders or > 10 failed/canceled in 24h

**Why it matters:** Orders stuck in `pending_payment` usually indicate a payment gateway issue — the customer was charged but the callback never arrived, leaving the order in limbo. A spike in failed/canceled orders may indicate a checkout or payment integration problem.

---

### Integration & API Tokens

Monitors third-party integration health and OAuth token status.

| Metric | Description |
|--------|-------------|
| `total` | Total number of integrations |
| `active` | Active integrations |
| `inactive` | Inactive integrations |
| `broken` | Active integrations with no valid (non-revoked) access token |
| `revoked_tokens` | Count of revoked OAuth tokens |

**Status:**
- Critical: any active integration without a valid access token (broken)
- Degraded: > 50 revoked tokens (stale tokens, needs cleanup)

**Why it matters:** A broken integration (active but no valid token) means an ERP, PIM, or warehouse system has silently lost its connection to Magento. Orders may not sync, inventory may go stale, and fulfillment may stop — all without any obvious error in the Magento admin.

**Edge cases:** If no integrations exist, the collector reports healthy with `total: 0`. If the `integration` or `oauth_token` table is missing, the collector reports healthy.

---

### Transactional Email

Detects sales confirmation emails (order, invoice, shipment, creditmemo) that were requested but never handed to the mail transport. Looks at a trailing 48-hour window, ignoring entities younger than 30 minutes so in-flight async sends are never flagged.

Magento 2.4.7+ keeps `email_sent` as an **attempt counter**, not a flag: `NULL` = queued (async) or failed-with-no-retry (sync); `-3 … -1` = attempts remaining, the async cron retries these; `0` = every attempt failed, Magento has given up; `1` = sent.

| Metric | Description |
|--------|-------------|
| `order_unsent` | Orders Magento will never email: `email_sent = 0`, plus `email_sent IS NULL` when async sending is off |
| `invoice_unsent` | Same for invoices |
| `shipment_unsent` | Same for shipments |
| `creditmemo_unsent` | Same for credit memos |
| `pending_retry` | Entities the async cron is still retrying (`NULL` or negative) past the grace period |
| `oldest_pending_minutes` | Age of the oldest entity still being retried |
| `async_enabled` | Value of `sales_email/general/async_sending` |
| `sample_increment_ids` | Up to 10 order increment IDs with lost confirmations (newest first), for manual resend |

**Status:**
- Critical: ≥ 5 permanently lost emails, or an async retry backlog stuck > 2 hours
- Degraded: any permanently lost email, or an async backlog waiting > 30 minutes

**Why it matters:** An SMTP outage during checkout costs confirmations either way. With async sending off the exception fires *after* the order is saved and nothing retries it. With async sending on, the cron retries three times and then writes `email_sent = 0` and moves on — the customer still gets nothing, and nobody notices until they complain weeks later. The collector reports exactly the rows Magento has abandoned, separately from the ones it is still working on, so "pending" really means pending. Resend lost confirmations manually (e.g. `n98-magerun2 sys:…` / `utils:send:email`, or re-queue by setting `email_sent = NULL` with async on).

**Edge cases:** Canceled orders are excluded (they legitimately get no confirmation). Missing sales tables report zero. Rows older than the 48-hour window age out so a handled incident doesn't pin the collector red.

---

### Log Errors

Parses recent `CRITICAL` and `ERROR` entries from `exception.log` and `system.log`, reading incrementally from the last byte offset (so each check only sees new content) and de-duplicating by a normalized signature. **WARNING, INFO and DEBUG lines are never captured.**

| Metric | Description |
|--------|-------------|
| `error_count` | Captured ERROR/CRITICAL entries this run (after noise suppression) |
| `unique_error_count` | Distinct error signatures |
| `suppressed_count` | Entries dropped by the known-noise allowlist this run |
| `files_scanned` | Which of `exception.log` / `system.log` were present |
| `errors` | Up to 20 unique errors: signature, level, truncated message, count, first/last seen |

Signatures are `sha256(level . ':' . normalized message)`. Normalisation strips
everything a request can vary — timestamps, UUIDs, hex tokens, emails, IPs,
digit runs, quoted literals, GraphQL variable values (`got invalid value …`,
`cannot represent … value: …`, `Value … does not exist in "Enum" enum`),
`with ID "…"` / `mit der ID "…"` slots and the `Requested store is not found (…)`
store code — while keeping the structural parts (variable name, `input.path`,
enum/type/field names). One injection scan against `/graphql` therefore yields
one signature per variable slot, not one per payload. Rules live in
`Model/Collector/LogError/MessageNormalizer.php` and are pinned by
`Test/Unit/Model/Collector/LogError/MessageNormalizerTest.php`.

**Status:**
- Critical: any captured `CRITICAL` entry
- Degraded: any captured `ERROR` entry

**Known-noise allowlist:** operators can suppress specific benign messages via `byte8_pulsar/checks/log_errors_ignore_patterns` (one case-insensitive substring per line — log messages contain commas, so not comma-separated). A matching ERROR/CRITICAL is dropped before it counts toward status or the error list, and tallied in `suppressed_count` so suppression stays observable. No wildcards are needed — a substring matches anywhere (e.g. `Could not acquire lock for cron job: plenty_` silences all `plenty_*` cron lock-contention lines).

**Why it matters:** A climbing `exception.log` while most requests still return HTTP 200 is a classic silent failure — a background subsystem is throwing while the storefront looks fine. The allowlist lets teams quiet genuinely benign recurring errors without disabling the whole check.

---

### Content Integrity

Scans `core_config_data` (head/footer/Misc HTML, all scopes) and CMS pages and blocks for injected JavaScript — the stored-XSS / Magecart skimmer surface that uptime checks are blind to.

| Metric | Description |
|--------|-------------|
| `findings_total` | Number of suspicious findings |
| `findings` | Up to 50 findings: location, severity, what matched |
| `findings_truncated` | Whether the findings list was capped |
| `cms_scan_capped` | Whether the CMS row scan hit its 5,000-row cap |

**Status:**
- Compromised: a high-confidence skimmer / obfuscation signature (hex-encoded URL array, `_0x…` + `eval`/`atob`/`unescape`, `import(_0x…)`, `String.fromCharCode`)
- Degraded: a lower-confidence signal — an `<script src>` from a host not on the allowlist, or a stray CSP `<meta>` tag (surfaced for review, not a breach claim)

**Allowlist:** `byte8_pulsar/checks/content_integrity_script_allowlist` adds trusted `<script src>` hostnames beyond the built-in list and the store's own domain (parent domain trusts all subdomains; no wildcards). When the detection-signature feed is enabled, new signatures are pulled from the Pulsar server at runtime, so emerging zero-days are caught without a module release.

**Why it matters:** A hacked store can report 100% uptime while a skimmer in a CMS block exfiltrates card data on every checkout. This collector treats that as `compromised` — a security event separate from the uptime SLA.

---

### Media Integrity

Scans `pub/media/` for tamper evidence (webshells) and suspicious upload accumulation.

| Metric | Description |
|--------|-------------|
| `executable_files_found` | PHP-executable files (`.php`, `.phtml`, `.phar`, double-extensions) under `pub/media/` |
| `polyshell_files_found` | Image files carrying an embedded PHP payload (e.g. `GIF89a<?php …`) |
| `htaccess_issues` | Missing/weak PHP-execution `.htaccess` guards (Apache) |
| `htaccess_skipped` | True on nginx, where `.htaccess` is not used |
| `web_server` | Detected server (`nginx` / `apache`) |
| `monitored_directories` | Per-subdir file counts with unexpected-file tallies (`import`, `tmp`, `customer`, …) |

**Status:**
- Compromised: any PHP-executable file, or an image with an embedded PHP payload
- Critical: a missing/weak PHP-execution `.htaccess` guard (Apache only)
- Degraded → Critical: per-subdirectory unexpected-file volume over threshold

**Why it matters:** A PHP file written into `pub/media/` is a webshell — remote code execution. Per-subdir rules count only *unexpected* file types, so legitimate bulk content (product images in `import/`, staged admin uploads in `tmp/`) never trips an alert.

---

### Upload Endpoint

Tails the web-server access log for automated POSTs to file-upload endpoints — an early webshell-drop indicator.

| Metric | Description |
|--------|-------------|
| `log_readable` | Whether a readable access log was found |
| `log_path` | The access log that was scanned |
| `upload_posts_found` | POST requests to monitored upload endpoints |
| `scripted_upload_posts` | Of those, how many came from scripted user agents |

**Status:**
- Compromised: a scripted upload POST that returned HTTP 200, or ≥ 10 scripted POSTs
- Critical: ≥ 50 upload POSTs or ≥ 10 scripted POSTs (24h)
- Degraded: ≥ 10 upload POSTs or ≥ 3 scripted POSTs (24h)

Set the log path in admin if it isn't auto-detected; with no readable log the collector reports healthy with `log_readable: false`.

**Why it matters:** Automated POSTs from `curl`/`python`/`wget`-style agents hammering an upload endpoint are what a webshell drop looks like in the access log, often before the file ever lands.

---

### Database Size

Reports total schema size and the largest tables so runaway growth is caught before it fills the disk. Cached for 24 hours (the query is heavier than the others).

| Metric | Description |
|--------|-------------|
| `total_size_mb` | Total database size in MB |
| `table_count` | Number of tables |
| `top_tables` | The 10 largest tables (name, size, row count) |
| `warning_threshold_mb` / `critical_threshold_mb` | The thresholds in effect (5 GB / 10 GB) |
| `cached` | Whether this result came from the 24h cache |

**Status:**
- Critical: total DB size ≥ 10 GB
- Degraded: total DB size ≥ 5 GB

**Why it matters:** `quote`/`quote_*`, `report_*`, and `*_log` tables grow unbounded without cleanup, and a full disk takes the whole store down. Seeing the largest tables tells you exactly what to trim.

---

### Applied Patches

Inventories the security patches a store *declares* and verifies each is actually present in the live code — catching a CVE fix silently reverted by a `composer update` or redeploy.

| Metric | Description |
|--------|-------------|
| `identifiers` | Patch identifiers verified present in code |
| `declared_identifiers` | Patch identifiers the store declares |
| `patches` | Per-patch detail: file, identifiers, sources, package, verification result |
| `summary` | Counts of `applied` / `superseded` / `not_applied` / `partial` / `target_missing` / `unreferenced` / `unknown` |
| `patch_dirs` | Directories scanned for `.patch`/`.diff` files |
| `note` | Housekeeping hint when unreferenced patch files are found |

**Status:**
- Degraded: a declared patch is not applied or only partially applied, and no newer patch covers the same file
- Healthy: every patch is applied or superseded by a newer applied patch

A patch whose hunks are gone but whose files are all covered by another *applied* patch is `superseded` (Adobe's cumulative monthly patches rewrite the same `Escaper.php`); its advisory identifiers still count as mitigated. A `.patch` file found only by the directory scan, declared by nothing, matching no file is `unreferenced` — stale housekeeping, not a lost fix. Hunks against composer's `vendor/bin/*` proxies are ignored.

Declared patches are read from `composer.json`, `vendor/composer/installed.json`, and `.patch`/`.diff` files — configurable via `applied_patches_sources` and `applied_patches_dirs`.

**Why it matters:** Applying a patch and keeping it applied are different things. A `composer update` can quietly pull a package version that drops the fix, re-opening a CVE with no obvious signal.

---

### Checkout Funnel

Measures how many carts reach a chosen shipping method and how many of those become orders, and compares the trailing window with the store's own baseline. This is the collector for the class of incident a synthetic checkout flow cannot see: the storefront is up, the flow passes, and yet one shopper in eight silently gives up at the shipping step because an age gate, a carrier rate call or an address validator misbehaves for *their* input.

| Metric | Description |
|--------|-------------|
| `carts` | Quotes created in the window with at least one item |
| `shipping_completed` | Of those, quotes whose shipping address carries a `shipping_method` |
| `orders` | Orders placed in the window |
| `cart_to_shipping_pct` / `shipping_to_order_pct` / `cart_to_order_pct` | Current stage conversion rates |
| `baseline_*_pct` | The same rates over the baseline period |
| `worst_stage` / `worst_stage_drop_pct` | The stage driving the status and its relative drop |
| `sample_sufficient` / `baseline_sufficient` | Whether there was enough traffic to judge |
| `stages` | Per-stage detail incl. z-score |
| `window_hours` / `baseline_days` | Effective settings |

**Status:**
- Critical: a stage ≥ 25 % below baseline (relative) with z ≥ 3
- Degraded: a stage ≥ 10 % below baseline (relative) with z ≥ 2
- Healthy with a note: fewer than 50 carts in the window, or the baseline is still building

**Why it matters:** A synthetic test proves the checkout works for one fixture address; it cannot see a partial failure. Real case: a German store's cart → shipping completion fell from 75–80 % to 66 % for two days while PayPal, the error logs, the checkout page and the age-verification script all reported fine. The funnel is the only signal that moves when customers, not robots, fail.

Pulsar's own synthetic checkout runs are excluded: quotes and orders whose `customer_email` is on the synthetic domain (below) never count as carts, so the flow's cadence cannot move the funnel.

---

### Synthetic Checkout Traffic

Not a collector — the module's side of Pulsar's browser-based checkout flow. Every flow run adds a product to cart as a fresh guest; with the *shipping step* enabled it also types a fixture address and waits for carrier rates. That leaves traces on the store, and this module makes them recognisable and harmless.

| Piece | What it does |
|-------|--------------|
| `X-Pulsar-Synthetic: <site id>` request header | Sent on every request of a run. |
| `Byte8\Pulsar\Api\IsSyntheticRequestInterface` | Inject it to skip calls that should not fire for monitoring traffic: age/ID verification SDKs, fraud scoring, ERP cart sync, marketing automation. |
| Quote tagging (`sales_quote_save_before`) | Every quote a run touches gets `customer_email = pulsar+<site id>@synthetic.byte8.io` from the first add-to-cart on, so even the base flow's anonymous carts are identifiable. Logged-in customers are never touched. |
| Pruning cron (`byte8_pulsar_prune_synthetic_quotes`, hourly) | Deletes synthetic quotes older than *Delete After* (default 1 h) in batches of 500; `quote_item` and `quote_address` follow by foreign key. |
| Funnel exclusion | The Checkout Funnel collector ignores synthetic quotes and orders. |

**What the merchant still has to do:** exclude `pulsar+<site id>@synthetic.byte8.io` (or the whole `@synthetic.byte8.io` domain) in Klaviyo, Mailchimp, Dotdigital and any abandoned-cart or analytics tool keyed on the shopper email. The site id is shown in the Pulsar dashboard's checkout-flow card.

---

### PHP-FPM

Monitors PHP-FPM pool saturation via the FPM status page. **Disabled by default** — it needs a reachable status URL.

| Metric | Description |
|--------|-------------|
| `configured` | Whether the status page was reachable |
| `active_processes` / `idle_processes` / `total_processes` | Current pool state |
| `max_active_processes` | Peak active processes |
| `max_children_reached` | Times the pool hit its `pm.max_children` ceiling |
| `listen_queue` / `listen_queue_len` | Requests waiting for a free worker |
| `usage_pct` | Active processes as a percentage of the max |

**Status:**
- Critical: pool usage ≥ 90%, or any requests sitting in the listen queue
- Degraded: pool usage ≥ 80%
- Healthy (`configured: false`): status page unreachable — no false alarm

Enable it, set the **PHP-FPM Status URL** (default `http://localhost/status?json`), and ensure `pm.status_path = /status` in your pool config.

**Why it matters:** When every FPM worker is busy, new requests queue and the store feels down even though the server is "up". The listen queue filling is the leading indicator of a saturation incident.

---

## Architecture

```
GET /pulsar/health
     │
     ▼
Controller/Health/Index.php
     │
     ├─ Check: module enabled?
     ├─ Check: API key valid? (hash_equals)
     │
     ▼
Model/HealthCheck.php
     │
     ├─ For each collector in di.xml pool:
     │    ├─ Check if enabled in admin config
     │    ├─ Run collector->collect()
     │    └─ Catch exceptions (log, don't expose)
     │
     ▼
JSON Response
  {status, timestamp, version, checks: {...}}
```

### Adding Custom Collectors

Third-party modules can add collectors via `di.xml`:

```xml
<type name="Byte8\Pulsar\Model\HealthCheck">
    <arguments>
        <argument name="collectors" xsi:type="array">
            <item name="my_check" xsi:type="object">Vendor\Module\Model\Collector\MyCollector</item>
        </argument>
    </arguments>
</type>
```

The collector must implement `Byte8\Pulsar\Model\Collector\CollectorInterface` with two methods:

```php
public function getName(): string;  // e.g. 'my_check'
public function collect(): array;   // must include 'status' key
```

To make it toggleable, add a config path `byte8_pulsar/checks/my_check_enabled` and extend the `Config` model.
