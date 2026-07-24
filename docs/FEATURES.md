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
  "version": "1.4.0",
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
    "integrations": { "..." }
  }
}
```

The top-level `status` is the worst status across all enabled collectors: `healthy` > `degraded` > `critical`.

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

**Status:**
- Critical: developer mode
- Degraded: default mode, maintenance active, or missing compilation/static in production

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
| `report_count` | Number of files in `var/report/` (capped at 10,000) |

**Status:**
- Critical: any log > 2 GB or > 1,000 report files
- Degraded: any log > 500 MB, debug logging enabled, or > 100 report files

**Why it matters:** Runaway logging fills disks. Debug logging in production generates massive files and degrades performance. A growing `var/report/` directory indicates unhandled exceptions.

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

**Warning checks (should be ON):**
- CSS minification, JS minification
- CSS merge, JS merge
- Static content signing
- Async email sending
- URL rewrites

**Status:**
- Critical: any "must be off" setting is on (exposes debug info or security risk)
- Degraded: any "should be on" setting is off (performance or SEO impact)

**Why it matters:** Template hints in production leak internal file paths to visitors. Missing minification/merge degrades page load performance. Async email prevents checkout from blocking on SMTP.

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

| Metric | Description |
|--------|-------------|
| `order_unsent` | Orders with `send_email = 1` and `email_sent IS NULL` (permanently lost) |
| `invoice_unsent` | Same for invoices |
| `shipment_unsent` | Same for shipments |
| `creditmemo_unsent` | Same for credit memos |
| `pending_retry` | Entities queued for the async send cron (`email_sent = 0`) past the grace period |
| `oldest_unsent_minutes` | Age of the oldest unsent/pending entity in the window |
| `async_enabled` | Value of `sales_email/general/async_sending` |
| `sample_increment_ids` | Up to 10 order increment IDs with lost confirmations (newest first), for manual resend |

**Status:**
- Critical: ≥ 5 permanently lost emails, or an async retry backlog stuck > 2 hours
- Degraded: any permanently lost email, or an async backlog waiting > 30 minutes

**Why it matters:** When emails are sent synchronously (`async_sending` off) and the SMTP server times out during checkout, the exception is thrown *after* the order is saved — `email_sent` stays `NULL`. Magento's retry cron only re-sends `email_sent = 0`, so `NULL` rows are invisible to it: the confirmation is silently lost for good, and nobody notices until a customer complains weeks later. The collector distinguishes these permanent losses from a normal async retry backlog, and escalates harder when the async safety net is off.

**Edge cases:** Canceled orders are excluded (they legitimately get no confirmation). Missing sales tables report zero. Rows older than the 48-hour window age out so a handled incident doesn't pin the collector red.

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
