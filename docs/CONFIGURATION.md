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

Disabling a collector means it will not be included in the health response at all. The overall status is computed only from enabled collectors.

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
  "version": "1.4.0",
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
| Default mode | Degraded |
| Maintenance mode active | Degraded |
| Production mode without DI compilation | Degraded |
| Production mode without static content | Degraded |

**Troubleshooting:** Switch to production mode:

```bash
bin/magento deploy:mode:set production
```

### Log Health — Thresholds

| Condition | Status |
|-----------|--------|
| Any log file > 2 GB | Critical |
| > 1,000 files in `var/report/` | Critical |
| Any log file > 500 MB | Degraded |
| Debug logging enabled | Degraded |
| > 100 files in `var/report/` | Degraded |

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
| `dev/css/merge_css_files` | CSS merge |
| `dev/js/merge_files` | JS merge |
| `dev/static/sign` | Static content signing |
| `sales_email/general/async_sending` | Async email sending |
| `web/seo/use_rewrites` | URL rewrites |

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
