# Configuration Guide

## Requirements

- Magento 2.4.4 or later
- PHP 8.1 or later
- `Byte8_Core` module installed and enabled
- PHP Redis extension (recommended, for Redis health checks)

## Installation

### Via Composer (recommended)

```bash
composer require byte8/module-pulsar
bin/magento module:enable Byte8_Pulsar
bin/magento setup:upgrade
bin/magento cache:flush
```

### Manual Installation

Copy the module to `app/code/Byte8/Pulsar/`, then:

```bash
bin/magento module:enable Byte8_Pulsar
bin/magento setup:upgrade
bin/magento cache:flush
```

### Verify Installation

```bash
bin/magento module:status Byte8_Pulsar
```

---

## Admin Configuration

Navigate to **Stores > Configuration > Byte8 > Pulsar Health Monitoring**.

### General Settings

| Setting | Description | Default |
|---------|-------------|---------|
| **Enable Health Endpoint** | Turns the `/pulsar/health` route on or off. When disabled, the endpoint returns `503`. | Yes |
| **API Key** | The key used to authenticate requests. Auto-generated during installation. Stored encrypted. | *(auto-generated)* |

#### Getting Your API Key

The API key is automatically generated when the module is installed (`setup:upgrade`). The health endpoint works immediately — no manual configuration needed.

1. Go to **Stores > Configuration > Byte8 > Pulsar Health Monitoring > General Settings**
2. Copy the pre-populated key (format: `psk_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx`)
3. Enter this key in your Pulsar dashboard site settings

To regenerate the key, clear the field and click **Save Config**. A new key will be generated. Remember to update the key in your Pulsar dashboard as well.

### Health Check Options

Each collector can be independently enabled or disabled. All are enabled by default.

| Setting | Collector | What It Checks |
|---------|-----------|----------------|
| **Cron Status** | `cron` | Cron heartbeat, stuck/failed/pending jobs |
| **Indexer Status** | `indexer` | Invalid/stuck indexers, search index health |
| **Cache Status** | `cache` | Disabled and invalidated cache types |
| **Database Status** | `database` | DB connectivity and response time |
| **System Status** | `system` | PHP/Magento version, disk space, memory |
| **Search Engine Status** | `search` | Elasticsearch/OpenSearch cluster health |
| **Message Queue Status** | `queue` | Pending/failed messages in the queue |
| **Deploy Mode Status** | `deploy` | Deploy mode, maintenance, compilation |
| **Redis Status** | `redis` | Redis connectivity and memory per instance |
| **Log Health Status** | `log` | Log file sizes, debug logging, error reports |
| **Admin Security Status** | `admin_security` | Failed logins, locked accounts, 2FA status |
| **Config Hygiene Status** | `config_hygiene` | Template hints, minification, merge settings |
| **SSL Certificate Status** | `ssl` | SSL certificate validity and expiry |
| **Order Pipeline Status** | `orders` | Stuck orders and payment failures |
| **Integration Status** | `integrations` | API integrations and OAuth token health |
| **PHP-FPM Status** | `phpfpm` | PHP-FPM pool saturation (**off by default** — needs a status URL) |
| **Media Integrity Status** | `media_integrity` | `pub/media` webshells, polyshell images, suspicious upload-dir volume |
| **Upload Endpoint Status** | `upload_endpoint` | Automated/scripted POSTs to file-upload endpoints (access-log scan) |
| **Database Size Status** | `database_size` | Total DB size and largest tables (runaway growth) |
| **Log Error Monitoring** | `log_errors` | CRITICAL/ERROR signatures in `exception.log` and `system.log` |
| **Content Integrity Status** | `content_integrity` | Injected skimmer / obfuscated JS in `core_config_data` and CMS content |
| **Transactional Email Monitoring** | `transactional_email` | Order/invoice/shipment/creditmemo emails requested but never sent |
| **Applied Patches Status** | `applied_patches` | Declared security patches verified present in live code |
| **Checkout Funnel Monitoring** | `checkout_funnel` | Cart → shipping method → order conversion vs the store's own baseline |

There are **24 collectors** in total. Disabling a collector means it will not be included in the health response at all. The overall status is computed only from enabled collectors.

> Some collectors can report a fourth status, **`compromised`** (tamper evidence — e.g. a webshell or injected skimmer), which is surfaced as a security event distinct from the uptime axis. It is produced by `media_integrity`, `content_integrity`, and `upload_endpoint`.

---

## Connecting to Pulsar

### 1. Add Your Site in Pulsar

In the [Pulsar dashboard](https://pulsar.byte8.io), create a new site with your Magento store's URL.

### 2. Configure the Health Endpoint

In the site's settings, set:

- **Health endpoint path:** `/pulsar/health`
- **API key:** the key from your Magento admin (see above)

### 3. Test the Connection

You can test the endpoint manually:

```bash
curl -s -H "X-Pulsar-Key: psk_your-key-here" \
  https://your-store.com/pulsar/health | python3 -m json.tool
```

Expected response:

```json
{
  "status": "healthy",
  "timestamp": "2026-02-16T14:30:00+00:00",
  "version": "1.15.1",
  "checks": {
    "cron": { "status": "healthy", "..." },
    "indexer": { "status": "healthy", "..." },
    "..."
  }
}
```

### Common Test Responses

| HTTP Code | Meaning |
|-----------|---------|
| `200` | Health check ran successfully (check `status` field for health state) |
| `401` | Invalid or missing API key |
| `503` | Module disabled or API key not configured |
| `500` | Unexpected error (check Magento logs) |

---

## Status Levels

Each collector reports one of three statuses:

| Status | Meaning |
|--------|---------|
| `healthy` | Everything is operating normally |
| `degraded` | Something needs attention but is not critical |
| `critical` | Immediate action required |

The top-level `status` in the response is the **worst** status across all enabled collectors. For example, if 9 collectors report `healthy` and 1 reports `critical`, the overall status is `critical`.

---

## Collector Details

### Cron — Thresholds

| Condition | Status |
|-----------|--------|
| No new jobs scheduled in 15 minutes | Critical |
| Any job running for > 60 minutes | Critical |
| No successful cron run ever recorded | Critical |
| > 5 failed jobs in last 24 hours | Degraded |
| > 100 pending overdue jobs | Degraded |

**Troubleshooting:** If `heartbeat_alive` is `false`, the `cron:run` process has stopped. Check your system crontab:

```bash
crontab -l | grep magento
```

Expected entry:

```
* * * * * /usr/bin/php /path/to/magento/bin/magento cron:run >> /path/to/magento/var/log/cron.log 2>&1
```

### Indexer — Thresholds

| Condition | Status |
|-----------|--------|
| `catalogsearch_fulltext` invalid or stuck | Critical |
| Any indexer stuck in `working` > 1 hour | Critical |
| > 3 invalid indexers | Critical |
| Any invalid indexer | Degraded |

**Troubleshooting:** Reset stuck indexers:

```bash
bin/magento indexer:reset catalogsearch_fulltext
bin/magento indexer:reindex catalogsearch_fulltext
```

### Search Engine — Requirements

The collector auto-detects the engine type from `catalog/search/engine`:

- `elasticsearch7` — reads from `catalog/search/elasticsearch7_*` config paths
- `opensearch` — reads from `catalog/search/opensearch_*` config paths

It connects to `http://{hostname}:{port}` and queries `/_cluster/health` and `/_cat/indices/{prefix}*`. If your search engine uses HTTPS or a non-standard setup, consider disabling this collector and relying on external monitoring for the search cluster.

**Auth:** If `enable_auth` is set in Magento's search config, the collector will use the configured username and password.

### Redis — Requirements

The Redis collector requires the **PHP Redis extension** (`phpredis`). Without it, the collector reports a degraded note but does not fail.

Instances are auto-discovered from `app/etc/env.php`:

| Instance | Config Path |
|----------|-------------|
| `session` | `session.save = 'redis'` → `session.redis.*` |
| `cache` | `cache.frontend.default.backend` contains `Redis` → `backend_options.*` |
| `page_cache` | `cache.frontend.page_cache.backend` contains `Redis` → `backend_options.*` |

If Redis is not configured for any of these, the collector reports `configured: false` with a healthy status.

| Condition | Status |
|-----------|--------|
| Connection failed | Critical |
| Memory usage > 90% of max | Critical |
| Memory usage 80–90% of max | Degraded |
| Evicted keys > 1,000 | Degraded |

**Troubleshooting:** Check Redis memory:

```bash
redis-cli INFO memory | grep -E "used_memory_human|maxmemory_human"
```

### Deploy Mode — Thresholds

| Condition | Status |
|-----------|--------|
| Developer mode | Critical |
| `var/` directory not writable | Critical |
| Default mode | Degraded |
| Maintenance mode active | Degraded |
| Production mode without DI compilation | Degraded |
| Production mode without static content | Degraded |
| `pub/static` not writable (production) | Degraded |

**Troubleshooting:** Switch to production mode:

```bash
bin/magento deploy:mode:set production
```

### Log Health — Thresholds

| Condition | Status |
|-----------|--------|
| Any log file > 2 GB | Critical |
| > 200 error reports written to `var/report/` in the last 24 h | Critical |
| Any log file > 500 MB | Degraded |
| Debug logging enabled | Degraded |
| > 50 error reports written to `var/report/` in the last 24 h | Degraded |

Only *recent* reports drive the status — an error storm from months ago no longer keeps the collector amber. The total on disk is still reported (`report_count`) and, past 1,000 files, the collector adds a housekeeping note.

**Troubleshooting:** Rotate large logs:

```bash
# Truncate (keeps the file handle valid for running processes)
> var/log/exception.log
> var/log/debug.log

# Disable debug logging
bin/magento config:set dev/debug/debug_logging_enabled 0
bin/magento cache:flush
```

Clean old error reports:

```bash
find var/report/ -type f -mtime +30 -delete
```

### Admin Security — Thresholds

| Condition | Status |
|-----------|--------|
| > 100 failed logins in 24 hours | Critical |
| > 3 locked accounts | Critical |
| > 20 failed logins in 24 hours | Degraded |
| Any admin user without 2FA configured | Degraded |

**Troubleshooting:** Check for brute-force attacks:

```bash
# View recent failed login attempts
bin/magento admin:user:unlock --all  # Unlock all locked accounts

# Check admin_user table for locked accounts
mysql -e "SELECT username, failures_num, lock_expires FROM admin_user WHERE lock_expires IS NOT NULL AND lock_expires > NOW();" magento
```

If you see persistent brute-force attempts, consider:
- Enabling CAPTCHA on admin login
- Restricting admin URL access by IP at the web server level
- Using a custom admin URL path

### Config Hygiene — Checks

**Critical issues (any of these ON will trigger Critical status):**

| Config Path | Setting |
|-------------|---------|
| `dev/debug/template_hints_storefront` | Storefront template hints |
| `dev/debug/template_hints_admin` | Admin template hints |
| `dev/template/allow_symlink` | Template symlinks |

**Warnings (any of these OFF will trigger Degraded status):**

| Config Path | Setting |
|-------------|---------|
| `dev/css/minify_files` | CSS minification |
| `dev/js/minify_files` | JS minification |
| `dev/static/sign` | Static content signing |
| `web/seo/use_rewrites` | URL rewrites |

**Advisory (reported in the `advisory` field for visibility, but do NOT change status):**

| Config Path | Setting |
|-------------|---------|
| `dev/css/merge_css_files` | CSS merge (can hurt performance under HTTP/2) |
| `dev/js/merge_files` | JS merge (can hurt performance under HTTP/2) |
| `sales_email/general/async_sending` | Async email sending (best practice, not a storefront concern) |

**Troubleshooting:** Fix common config issues:

```bash
# Disable template hints
bin/magento config:set dev/debug/template_hints_storefront 0
bin/magento config:set dev/debug/template_hints_admin 0

# Enable minification and merge
bin/magento config:set dev/css/minify_files 1
bin/magento config:set dev/js/minify_files 1
bin/magento config:set dev/css/merge_css_files 1
bin/magento config:set dev/js/merge_files 1

# Enable async email
bin/magento config:set sales_email/general/async_sending 1

bin/magento cache:flush
```

### SSL / Certificate — Thresholds

| Condition | Status |
|-----------|--------|
| Certificate expired | Critical |
| < 7 days until expiry | Critical |
| Certificate unretrievable | Critical |
| < 30 days until expiry | Degraded |
| HTTPS not configured | Degraded |

**Troubleshooting:** Check your certificate manually:

```bash
# Check certificate expiry from the command line
echo | openssl s_client -connect your-store.com:443 2>/dev/null | openssl x509 -noout -dates

# Check Let's Encrypt renewal
certbot certificates
```

If the collector reports "unretrievable", ensure the store's `web/secure/base_url` is correctly configured:

```bash
bin/magento config:show web/secure/base_url
```

### Order Pipeline — Thresholds

| Condition | Status |
|-----------|--------|
| > 20 orders stuck in `pending_payment` > 4 hours | Critical |
| > 5 orders stuck in `pending_payment` > 4 hours | Degraded |
| > 10 failed/canceled orders in 24 hours | Degraded |

**Troubleshooting:** Investigate stuck pending_payment orders:

```bash
# Check stuck orders
mysql -e "SELECT entity_id, increment_id, created_at, grand_total FROM sales_order WHERE status = 'pending_payment' AND created_at < DATE_SUB(NOW(), INTERVAL 4 HOUR) ORDER BY created_at;" magento
```

Common causes:
- Payment gateway callback URL misconfigured or unreachable
- Payment provider experiencing downtime
- Order placed but customer abandoned during 3DS/redirect flow

### Integration & API Tokens — Thresholds

| Condition | Status |
|-----------|--------|
| Any active integration without a valid access token | Critical |
| > 50 revoked OAuth tokens | Degraded |

**Troubleshooting:** Check integration status:

```bash
# List all integrations and their status
mysql -e "SELECT name, status, setup_type FROM integration ORDER BY name;" magento

# Check for broken integrations (active but no valid token)
mysql -e "SELECT i.name FROM integration i WHERE i.status = 1 AND NOT EXISTS (SELECT 1 FROM oauth_token t WHERE t.consumer_id = i.consumer_id AND t.type = 'access' AND t.revoked = 0);" magento
```

To fix a broken integration:
1. Go to **System > Integrations** in the Magento admin
2. Find the affected integration and click **Reauthorize**
3. Complete the OAuth flow to generate a new access token

To clean up revoked tokens:

```bash
mysql -e "DELETE FROM oauth_token WHERE revoked = 1;" magento
```

### Log Errors — Thresholds & known-noise allowlist

Parses **only** `CRITICAL` and `ERROR` lines from `exception.log` and `system.log` (WARNING/INFO/DEBUG are never captured), reads incrementally from the last offset, and de-duplicates by a normalized signature.

| Condition | Status |
|-----------|--------|
| Any captured `CRITICAL` entry | Critical |
| Any captured `ERROR` entry | Degraded |

**Known-Noise Ignore Patterns** (`byte8_pulsar/checks/log_errors_ignore_patterns`): a CRITICAL/ERROR whose message contains one of these (case-insensitive substring) is dropped *before* it counts toward status or the error list. For log lines you've triaged as benign.

- **One pattern per line** — not comma-separated, because log messages routinely contain commas.
- **No wildcards needed** — a substring matches anywhere. For example, to silence the benign cron lock-contention warnings (`Could not acquire lock for cron job: plenty_item_map`), enter:

  ```
  Could not acquire lock for cron job: plenty_
  ```

  That substring matches every `plenty_*` variant. (Note: those particular lines are logged at `WARNING`, which `log_errors` already ignores — the allowlist matters when a benign message is logged at ERROR/CRITICAL.)
- Suppressed entries are tallied as `suppressed_count` in the health payload, so the filter is observable and can't silently hide a flood.

### Database Size — Thresholds

Cached for 24 hours (the query is heavier than the others). Reports total schema size and the 10 largest tables.

| Condition | Status |
|-----------|--------|
| Total DB size ≥ 10 GB | Critical |
| Total DB size ≥ 5 GB | Degraded |

Common runaway tables: `quote` / `quote_*`, `report_event`, `report_viewed_product_index`, `*_log`. Trim with the `log:clean` / `quote cleanup` jobs or the maintenance scripts for report tables.

### Media Integrity — Checks

Scans `pub/media/` for tamper evidence and suspicious upload accumulation.

| Condition | Status |
|-----------|--------|
| A PHP-executable file (`.php`, `.phtml`, `.phar`, double-extension, …) in `pub/media/` | **Compromised** |
| An image carrying an embedded PHP payload (polyshell, e.g. `GIF89a<?php …`) | **Compromised** |
| Missing/weak PHP-execution `.htaccess` guard (Apache only — skipped on nginx) | Critical |
| Per-subdirectory unexpected-file volume over threshold (`import`, `tmp`, `customer`, …) | Degraded → Critical |

`.htaccess` checks are automatically skipped when the server is nginx (reported as `htaccess_skipped: true`). Per-subdirectory rules count only files whose type is unexpected for that directory, so legitimate bulk content (product images in `import/`, staged uploads in `tmp/`) does not trip an alert.

### Content Integrity — Checks

Scans `core_config_data` (head/footer HTML, Misc HTML) and CMS pages/blocks across all scopes for injected JavaScript.

| Condition | Status |
|-----------|--------|
| A high-confidence skimmer / obfuscation signature (hex-encoded URL, `_0x…` + `eval`/`atob`, `import(_0x…)`, `fromCharCode`) | **Compromised** |
| An `<script src>` from a host not on the allowlist, or a stray CSP `<meta>` | Degraded (review) |

**Trusted Script Hosts** (`byte8_pulsar/checks/content_integrity_script_allowlist`): extra hostnames allowed in `<script src>`, beyond the built-in list and your own store domain. Comma **or** newline separated; parent domain trusts all subdomains (suffix match — no wildcards). Detection signatures are also pulled at runtime from the Pulsar feed when the signature feed is enabled, so new zero-days are caught without a module release.

### Upload Endpoint — Checks

Tails the web-server access log looking for automated POSTs to file-upload endpoints (a webshell-drop indicator).

| Condition | Status |
|-----------|--------|
| ≥ 10 scripted-user-agent POSTs, or a scripted POST that got an HTTP 200 on an upload endpoint | **Compromised** |
| ≥ 50 upload POSTs, or ≥ 10 scripted POSTs (24h) | Critical |
| ≥ 10 upload POSTs, or ≥ 3 scripted POSTs (24h) | Degraded |

Set the access-log path in admin (`Access Log Path`) if it isn't auto-detected. If no readable log is found, the collector reports healthy with `log_readable: false`.

### Applied Patches — Verification

Inventories security patches the store *declares* (composer.json, `vendor/composer/installed.json`, and `.patch`/`.diff` files in the configured patch dirs) and **verifies each is actually present in the live code**.

| Condition | Status |
|-----------|--------|
| A declared patch is not applied or only partially applied | Degraded |
| All declared patches verified present (or superseded by a newer applied patch) | Healthy |

Configurable via **Applied Patches Directories** (`applied_patches_dirs`, default `patches`) and **Applied Patches Sources** (`applied_patches_sources` — any of `composer_json`, `installed_json`, `patch_dirs`). This catches a CVE patch silently reverted by a `composer update` or redeploy.

Each patch is classified as one of:

| Verification | Meaning | Affects status |
|---|---|---|
| `applied` | Every hunk present in the live file | — |
| `superseded` | Hunks gone, but a newer patch in the inventory touching the same file is applied (Adobe's cumulative monthly patches rewrite the same files) — the fix lives on in its newer form | — |
| `partial` / `not_applied` | Hunks missing from the live file and nothing else covers it — the fix was lost | Degraded |
| `target_missing` | A declared patch whose target file does not exist in this install | — |
| `unreferenced` | A `.patch` file found by the directory scan that nothing declares and that matches no file — a leftover from a previous version or a removed module. The collector adds a housekeeping note; move these files out of the patch directories | — |
| `unknown` | Remote URL patch, unreadable file, or a diff with no verifiable lines | — |

Hunks against composer's `vendor/bin/*` proxies are ignored; composer regenerates those on every install.

### Checkout Funnel — Thresholds

Compares the trailing window's conversion with the store's own baseline, per stage:

| Stage | Measured as |
|---|---|
| cart → shipping method chosen | quotes with items whose shipping address carries a `shipping_method` |
| shipping method chosen → order placed | orders / quotes with a shipping method |
| cart → order placed | orders / carts (composite; never named as the worst stage when an adjacent one explains it) |

| Condition | Status |
|-----------|--------|
| A stage's rate ≥ **25 %** below baseline (relative) and z ≥ 3 | Critical |
| A stage's rate ≥ **10 %** below baseline (relative) and z ≥ 2 | Degraded |
| Fewer than **50** carts in the window, or baseline under 10× that | Healthy, with a note |

"Relative" means 78 % → 70 % is a 10 % drop. The z-score (two-proportion test) stops thin traffic from paging: a 12-point drop on 30 carts is weather, the same drop on 300 carts is an incident.

Settings: **Window (hours)** `checkout_funnel_window_hours` (24), **Baseline (days)** `checkout_funnel_baseline_days` (28 — keep below *Sales › Checkout › Quote Lifetime*, default 30 days, or the baseline shrinks as quotes are purged), **Minimum Carts per Window** `checkout_funnel_min_carts` (50), **Degraded / Critical at Relative Drop (%)** `checkout_funnel_degraded_drop_pct` (10) / `checkout_funnel_critical_drop_pct` (25).

The collector reads `quote`, `quote_address` and `sales_order` only. `quote.created_at` is unindexed, so the window is first narrowed to an `entity_id` lower bound found by binary search on the primary key; results are cached for 5 minutes (window) and 6 hours (baseline), so polling frequency does not add load. Quotes and orders carrying the synthetic shopper email (next section) are excluded.

### Synthetic Checkout Traffic

**Stores › Configuration › Byte8 › Pulsar › Synthetic Checkout Traffic**

Pulsar's checkout flow runs against the storefront as a guest and, with its shipping step enabled, types a fixture address. The module tags every quote such a run touches with `pulsar+<site id>@synthetic.byte8.io` and removes them again:

| Setting | Path | Default | Meaning |
|---|---|---|---|
| Delete Synthetic Quotes | `byte8_pulsar/synthetic/prune_enabled` | Yes | Hourly cron deletes tagged quotes |
| Delete After (hours) | `byte8_pulsar/synthetic/prune_after_hours` | 1 (max 168) | Minimum age since the quote's last update |

No setting is needed for the request marker or the quote tagging; both are always on while the module is enabled. To short-circuit third-party calls for synthetic runs, inject `Byte8\Pulsar\Api\IsSyntheticRequestInterface` and check `execute()` before calling out.

### PHP-FPM — Thresholds (off by default)

Disabled by default because it needs the PHP-FPM status page. Enable it, then set **PHP-FPM Status URL** (`phpfpm_status_url`, default `http://localhost/status?json`) and ensure `pm.status_path = /status` in your pool config.

| Condition | Status |
|-----------|--------|
| Pool usage ≥ 90%, or any requests in the listen queue | Critical |
| Pool usage ≥ 80% | Degraded |

If the status page isn't reachable, the collector reports healthy with `configured: false` (no false alarm).

---

## Maintenance Mode Considerations

When Magento is in maintenance mode, it returns a `503` response for all frontend routes **before** the health controller runs. This means:

- If the Pulsar checker's IP is **not** in the maintenance allowlist, it will receive a `503` and correctly flag the site as down
- If the Pulsar checker's IP **is** allowlisted, the health endpoint works normally and the `deploy` collector will report `maintenance: true`

To allowlist the Pulsar checker IP during planned maintenance:

```bash
bin/magento maintenance:allow-ips 203.0.113.50
```

---

## Performance

The health endpoint is designed to be lightweight:

- All collectors use read-only queries
- Database queries are minimal (single `SELECT 1`, aggregation counts)
- Redis checks use a 2-second connection timeout
- Search engine checks use a 5-second connection timeout
- Report file counting is capped at 10,000 entries
- Cache collector only reports 5 key cache types to keep the response small
- SSL collector uses a 5-second connection timeout
- Config Hygiene collector is pure config reads — zero database queries
- Admin Security queries are lightweight aggregation counts

Typical response time is **50–200ms** depending on the number of enabled collectors and network latency to Redis/Elasticsearch.

If you observe performance impact, disable the heavier collectors (`search`, `redis`, `queue`, `ssl`) and monitor those services externally.

---

## Troubleshooting

### Endpoint returns 404

The module may not be enabled or the route may not be registered:

```bash
bin/magento module:status Byte8_Pulsar
bin/magento setup:upgrade
bin/magento cache:flush
```

### Endpoint returns 503 "API key not configured"

The API key is normally auto-generated during installation. If you see this error, the key may have been deleted from the database. Regenerate it:

1. Go to **Stores > Configuration > Byte8 > Pulsar Health Monitoring**
2. Clear the API Key field and click **Save Config**
3. A new API key will be generated

### Endpoint returns 401

The API key in the request doesn't match the configured key. Verify:

```bash
# Check that the header is being sent correctly
curl -v -H "X-Pulsar-Key: psk_your-key" https://your-store.com/pulsar/health
```

### Redis collector shows "PHP Redis extension not available"

Install the phpredis extension:

```bash
# Ubuntu/Debian
apt-get install php-redis

# CentOS/RHEL
yum install php-pecl-redis

# Restart PHP-FPM
systemctl restart php-fpm
```

### Search collector shows "unreachable"

Verify the search engine is running and accessible from the Magento server:

```bash
curl http://localhost:9200/_cluster/health
```

Check Magento's search configuration:

```bash
bin/magento config:show catalog/search/engine
bin/magento config:show catalog/search/elasticsearch7_server_hostname
bin/magento config:show catalog/search/elasticsearch7_server_port
```
