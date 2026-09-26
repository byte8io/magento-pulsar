# Byte8 Pulsar — Magento 2 Extension

Deep health monitoring for Magento 2. This is the companion extension for **[Byte8 Pulsar](https://byte8.io/products/pulsar)** — a hosted monitoring service built specifically for Magento. Uptime checks tell you the homepage loads; Pulsar tells you cron is stuck, an indexer is stale, transactional emails are silently failing, or a payment skimmer just landed in your CMS blocks.

- 🛰 **Product**: [byte8.io/products/pulsar](https://byte8.io/products/pulsar)
- 📚 **Documentation**: [docs.byte8.io/pulsar](https://docs.byte8.io/pulsar/)
- 🖥 **Dashboard**: [pulsar.byte8.io](https://pulsar.byte8.io)

The extension is intentionally simple: it exposes a single secure JSON endpoint, `GET /pulsar/health`, that runs the enabled collectors and reports per-check status. The Pulsar service polls it on an interval, tracks history, applies alerting rules (with flap suppression and digests), and notifies your team — nothing long-lived runs on your server.

## What it watches — 23 collectors

**Infrastructure & platform**

| Collector | Catches |
|---|---|
| `cron` | Dead heartbeat, stuck/failed jobs, pending backlog |
| `indexer` | Invalidated/stale indexers |
| `cache` | Disabled cache types, backend connectivity |
| `database` / `database_size` | Connectivity, growth trends, runaway tables |
| `redis` | Connectivity, memory pressure |
| `search` | OpenSearch/Elasticsearch cluster health |
| `queue` | Message-queue consumer backlog |
| `system` | Disk space, load |
| `php_fpm` | Pool saturation |
| `deploy` | Deploy mode, config drift |
| `ssl` | Certificate expiry |
| `log` / `log_error` | Log growth, error bursts in `system.log`/`exception.log` |

**Commerce signals**

| Collector | Catches |
|---|---|
| `orders` | Order-flow anomalies (the "site is up but nobody can buy" class) |
| `transactional_email` | Silently unsent order/invoice emails — losses that a retry cron never sees |
| `integrations` | Broken API integrations |

**Security posture**

| Collector | Catches |
|---|---|
| `admin_security` | Weak admin URL, stale admin accounts, 2FA posture |
| `config_hygiene` | Risky configuration drift |
| `content_integrity` | Injected scripts / payment skimmers in CMS content and store config (signature feed kept fresh via cron) |
| `media_integrity` / `upload_endpoint` | Executable files in media, exposed upload endpoints |
| `applied_patches` | Verifies security patches are actually present in live code — so version-matched CVE alerts from Pulsar reflect your *patched* reality, and alert again if a patch disappears after a deploy |

Every collector is individually toggleable with tunable thresholds, and reports `healthy` / `degraded` / `critical`; the endpoint's top-level status is the worst across enabled collectors.

## Requirements

- Magento **2.4.x** (Open Source or Adobe Commerce)
- PHP **8.1 – 8.5**
- Outbound HTTPS to `*.byte8.io`
- A [Pulsar account](https://byte8.io/products/pulsar)

## Installation

The package ships from the Byte8 Composer registry ([Cargoman](https://cargoman.io)):

```bash
composer config repositories.byte8 composer https://cargoman.io
composer require byte8/module-pulsar
bin/magento setup:upgrade
bin/magento cache:flush
```

## Configuration

**Stores → Configuration → Byte8 → Pulsar** in Magento admin:

1. **Enabled** — master switch for the `/pulsar/health` endpoint.
2. **API key** — paste the key issued when you add the site at [pulsar.byte8.io](https://pulsar.byte8.io).
3. Per-collector toggles and thresholds (e.g. cron pending/failed warning levels, content-integrity script allowlist, applied-patches sources) — sensible defaults out of the box.

Requests authenticate with the `X-Pulsar-Key` header (or `Authorization: Bearer`), compared in constant time. The endpoint is read-only and returns metrics only — no customer data.

Full field-by-field reference: [`docs/CONFIGURATION.md`](docs/CONFIGURATION.md) · endpoint/response details: [`docs/FEATURES.md`](docs/FEATURES.md).

## How it fits together

```
┌──────────────────────┐   poll /pulsar/health    ┌─────────────────────┐
│  Pulsar service      │ ───────────────────────► │  Magento 2          │
│  pulsar.byte8.io     │      (X-Pulsar-Key)      │  (this module:      │
│  history · alerting  │ ◄─────────────────────── │   23 collectors)    │
│  advisories · digests│        JSON status       │                     │
└──────────┬───────────┘                          └─────────────────────┘
           │ alerts: email · Slack · webhooks
           ▼
      your ops team
```

The service side adds what a module can't: uptime/synthetic checks from outside, alert history, flap suppression, security-advisory matching against your exact Magento version *and* applied patches, and multi-site dashboards.

## Support

- 📚 [Documentation](https://docs.byte8.io/pulsar/) — setup, collectors reference, troubleshooting
- 🐛 [GitHub issues](https://github.com/byte8io/magento-pulsar/issues) — bug reports welcome
- ✉️ [helo@byte8.io](mailto:helo@byte8.io) — support

## License

Proprietary — © [Byte8 Ltd](https://byte8.io). The extension source is published for transparency and merchant review; use requires a Pulsar subscription. See [byte8.io/products/pulsar](https://byte8.io/products/pulsar#pricing) for plans.
