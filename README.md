# BlueFairy API Gateway

REST response caching, per-IP rate limiting and signed outbound webhooks for WordPress — a hardened API layer for headless sites and integrations.

**Version:** 1.0.0  
**Requires WordPress:** 6.6+  
**Requires PHP:** 8.1+  
**License:** GPL-2.0-or-later

This is the free base plugin of a pair. [BlueFairy API Gateway Pro](https://wordpress.bluefairydevelopment.com/product/bluefairy-api-gateway-pro/) is an add-on that attaches to the extension seams listed at the end of the features guide (`docs/ApiGatewayFeaturesGuide.pdf`); it does not replace this plugin and cannot run without it.

For the customer-facing feature guide, including the Pro/free comparison table, see the features guide (`docs/ApiGatewayFeaturesGuide.pdf`).

---

## What it does

- **Response cache** — anonymous `GET`/`HEAD` requests to the REST API are stored and served back with an `X-BF-Cache: HIT` header. Every REST response passing through is labelled `HIT`, `MISS` or `BYPASS`, so you can see the decision from `curl`. One cache rule in the free plugin (seeded as `**`, "All routes", 60 s). Purged automatically when a post, term or comment changes; purged by hand from the Cache tab or `POST /bfag/v1/cache/purge`.
- **Rate limiting** — a sliding-window counter per client IP and rule. The breaching request gets `429` with `Retry-After`, `X-BF-RateLimit-Limit` and `X-BF-RateLimit-Window`. One rule in the free plugin (seeded as 300 requests per 60 s on `**`; logged-in users who can edit posts are exempt by default). One forwarded-IP header of your choosing is honoured, only from the proxies you list.
- **Outbound webhooks** — up to three webhooks subscribing to six WordPress events (ten with WooCommerce). Each delivery is a JSON `POST` signed with `X-BF-Signature: sha256=HMAC-SHA256(timestamp.body, secret)`, logged with its HTTP status and response, and can be resent from the delivery log.
- **Dashboard** — cache hit rate and webhook success rate over the last 24 hours, the current size of the rate-limit log, the ten most recent failed deliveries with a Retry button, and the same figures at `GET /bfag/v1/status` for a monitoring tool authenticated with an Application Password (or, with Pro, an API key).

## Installation

1. Upload the `bluefairy-api-gateway` folder to `/wp-content/plugins/`, or install the zip from **Plugins → Add New → Upload Plugin**.
2. Activate it from the **Plugins** screen. Activation refuses to proceed on PHP below 8.1.
3. Open **Settings → API Gateway**.

Activation creates six database tables (listed below), seeds the one cache rule and the one rate-limit rule, grants the `bfag_manage_gateway` capability to the Administrator role, and schedules four recurring WP-Cron jobs (a fifth, `bfag_cron_flush_pending`, is a single event scheduled on demand). Nothing is sent anywhere until you add a webhook.

No Composer install is needed at runtime: the plugin autoloads its own namespace. `composer install` in the plugin folder pulls in the development tools only (PHPUnit, PHPCS, PHPStan).

## Quick start

**See the cache working.** Request any public REST route twice as an anonymous client and read the header:

```
curl -sI https://example.com/wp-json/wp/v2/posts | grep -i x-bf-cache
X-BF-Cache: MISS
curl -sI https://example.com/wp-json/wp/v2/posts | grep -i x-bf-cache
X-BF-Cache: HIT
```

A `BYPASS` means the request or the response was not cacheable — the list of reasons is in the features guide (`docs/ApiGatewayFeaturesGuide.pdf`) under *How the cache decides*.

**See the rate limit working.** The seeded rule allows 300 requests per 60 seconds per IP on every route. The 301st request within the window answers:

```
HTTP/1.1 429 Too Many Requests
Retry-After: 60
X-BF-RateLimit-Limit: 300
X-BF-RateLimit-Window: 60

{"code":"bfag_rate_limited","message":"Too many requests. Please slow down and try again shortly.","data":{"status":429}}
```

`GET /bfag/v1/status` is never rate-limited, so a monitor polling it cannot lock itself out.

**Send a webhook.** On the **Webhooks** tab add a webhook: a label, an `https://` URL, an event (for example *Post published*) and, optionally, a signing secret — leave it blank and a random 64-character secret is generated and shown to you once after saving — copy it into the receiver then. Click **Send test** on the new row; the receiver gets a `test` event, the notice reports the HTTP status, and the response appears in the delivery log. The receiver verifies `X-BF-Signature` with the shared secret; PHP and Node examples are in the features guide (`docs/ApiGatewayFeaturesGuide.pdf`).

## Settings reference

All settings live in the single option `bfag_settings` and are edited on **Settings → API Gateway → Settings**. Integer values outside their bounds are clamped, not rejected, so a typo cannot leave the form unsaved. Unknown keys are dropped.

| Setting | Key | Default | Bounds / values | What it does |
|---|---|---|---|---|
| Notification email | `notification_email` | `''` | A valid email, else `''` | The address the Pro add-on's failure alerts go to. Shown only with the Pro add-on; the free plugin keeps the value hidden and sends no mail. |
| Failure threshold | `failure_threshold` | `5` | 1–100 | Consecutive failed deliveries before the Pro add-on raises an alert. Shown only with the Pro add-on. |
| Enable response cache | `cache_enabled` | `true` | checkbox | Off: nothing is served from or stored in the cache; every response is `BYPASS`. |
| New-rule TTL (seconds) | `default_cache_ttl` | `60` | 1–86400 | Pre-fills the TTL of a new cache rule in the add form. Not a fallback: every rule carries its own TTL and the rule's TTL is the TTL. |
| Enable rate limiting | `rate_limit_enabled` | `true` | checkbox | Off: no rule is counted and no `429` is ever returned. |
| Exempt editors | `rate_limit_exempt_editors` | `true` | checkbox | Do not rate-limit logged-in users who can edit posts (the block editor's own REST calls). |
| Trusted proxies | `trusted_proxies` | `[]` | IPs or CIDRs, one per line; invalid entries discarded | When `REMOTE_ADDR` is one of these, the client IP is taken from the *Client address header* below. Needed behind Cloudflare, a load balancer or a CDN; without it every visitor shares the proxy's bucket, and a notice says so while a forwarded-address header is arriving. |
| Client address header | `forwarded_header` | `X-Forwarded-For` | `X-Forwarded-For`, `CF-Connecting-IP`, `X-Real-IP` or `True-Client-IP`; anything else saves as the default | The one header read from a trusted proxy (`X-Forwarded-For`: right-most address that is not itself a trusted proxy). Every other header is ignored, so a visitor cannot pick their own address. |
| Max stored payload (bytes) | `max_payload_bytes` | `65535` | 1024–16777215 | A delivery whose JSON body is longer is still sent in full, but the stored copy is cut here and flagged *truncated*, and cannot be retried. |
| Delivery log retention (days) | `retention_days` | `30` | 1–365 | Finished (`success`/`failed`) deliveries older than this are deleted by the daily prune; `pending`/`retrying` rows older than a day are deleted regardless. |
| Debug logging | `debug_logging` | `false` | checkbox | Writes gateway decisions (rejected headers, breached rules, refused saves, prune counts) to the PHP error log, prefixed `[bfag]`. |
| Hide the WP-Cron notice | `disable_cron_notice` | `false` | checkbox | Suppresses the warning shown on the plugin's screen when `DISABLE_WP_CRON` is true. |
| Delete data on uninstall | `delete_data_on_uninstall` | `false` | checkbox | When the plugin is deleted, also drop the six tables. Options, transients, cron events and the capability are removed regardless. The Pro add-on's uninstall reads this same switch. |

## REST endpoints

Both endpoints require the `bfag_manage_gateway` capability — a cookie-authenticated administrator with an `X-WP-Nonce`, an Application Password, or (with Pro) an API key holding the right scope. Neither is ever cached: the whole `/bfag/v1` namespace is excluded from the response cache.

### `GET /wp-json/bfag/v1/status`

Exempt from rate limiting. Returns:

```json
{
  "version": "1.0.0",
  "kpis": {
    "cache_hit_rate": 0.83, "cache_hits": 412, "cache_misses": 84,
    "webhook_success_rate": 1.0, "webhook_failed": 0, "webhook_total": 12,
    "blocked_today": 0, "hits_table_rows": 1532, "computed_at": 1790812800,
    "webhook_deliveries": { "success": 12, "failed": 0, "pending": 0, "retrying": 0 }
  },
  "counts": {
    "webhooks": 2,
    "cache_24h": { "hits": 412, "misses": 84 },
    "deliveries_24h": { "success": 12, "failed": 0, "pending": 0, "retrying": 0 }
  },
  "pro": false
}
```

`kpis` is `null` until the five-minute KPI cron has run once (or the dashboard's **Refresh** button has been pressed), and so are `counts.cache_24h` and `counts.deliveries_24h`, which are copied from it: a monitor polling this route never runs an aggregate query. `counts.webhooks` is live, cached for a minute. `blocked_today` is always `0` in the free plugin (the dashboard card says *Blocked (Pro)*); with Pro it is the number of addresses currently blocked. The route's permission callback accepts the `bfag_manage_gateway` capability or, with Pro, an API key whose scope covers `read`.

### `POST /wp-json/bfag/v1/cache/purge`

Body (JSON or form): optional `tags` — an array of tag names, or a comma-separated string. With no tags the whole response cache is purged; with tags only responses cached under those tags are orphaned.

```json
{ "purged": "all", "tags": [] }
{ "purged": "tags", "tags": ["products"] }
```

Tag names are lower-cased and reduced to `[a-z0-9_:-]`, so `post:42` arrives intact; a purge naming a tag that no enabled rule carries is harmless (and logged when debug logging is on).

If the database refuses to store the new generation, nothing was purged and the answer says so — `500` with `{ "code": "bfag_purge_failed", … }` — never a `200` claiming a purge. The Cache tab's **Purge all** and the Settings form report a refused write the same way, as an error notice instead of *purged* / *saved*.

### Response headers on every REST request

| Header | Set by | Values |
|---|---|---|
| `X-BF-Cache` | the cache | `HIT`, `MISS` or `BYPASS` |
| `Retry-After`, `X-BF-RateLimit-Limit`, `X-BF-RateLimit-Window` | a `429` from the rate limiter | the breached rule's window and limit, in seconds and requests |

## Cron jobs

| Hook | Schedule | Work |
|---|---|---|
| `bfag_cron_prune_hits` | every five minutes (`bfag_five_minutes`, registered by the plugin) | Deletes rate-limit hits older than the longest enabled window, 10,000 rows at a time; if the `MAX(id) - MIN(id)` bound has passed ten million, deletes the oldest rows by id range (10,000 at a time, at most fifty slices a run) so the newest ten million remain — never a `TRUNCATE`, which would stall concurrent inserts and reset every client's window at once. |
| `bfag_cron_prune_deliveries` | daily | Deletes finished deliveries older than *Delivery log retention*, and `pending`/`retrying` rows older than a day, 5,000 rows at a time. |
| `bfag_cron_recompute_kpis` | every five minutes | Flushes the object-cache hit/miss counters (with a persistent object cache), then recomputes the dashboard figures into the `bfag_kpis` transient (six-minute TTL). |
| `bfag_cron_prune_responses` | every five minutes | Without a persistent object cache: deletes every expired `bfag…` transient (stored responses, limited markers, parked forms, the Pro add-on's `bfagp_` ones), 1,000 pairs at a time. |
| `bfag_cron_flush_pending` | single event, scheduled on demand | Sends webhook deliveries queued past the twenty-per-flush limit (or past the flush's fifteen-second budget), batch after batch of twenty for up to twenty seconds a run, re-arming itself only while rows remain. |

The four recurring events are scheduled on activation, cleared on deactivation (with any pending flush event), and run through WP-Cron. If `DISABLE_WP_CRON` is set, the plugin shows a notice on its own screen until you tick *Hide the WP-Cron notice*; make sure a system cron runs `wp-cron.php`. There are no WP-CLI commands; `wp cron event run bfag_cron_prune_hits` runs a job on demand.

## Database

Six tables, all prefixed with `$wpdb->prefix`, created with `dbDelta` on activation and on a database-version change:

| Table | Holds |
|---|---|
| `bfag_cache_rules` | cache rules — label, route glob, TTL, tags, optional response headers (JSON), enabled |
| `bfag_rate_limit_rules` | rate-limit rules — label, subject type, route glob, max requests, window, penalty (used by Pro), enabled |
| `bfag_rate_limit_hits` | one row per counted request: subject, route (first 191 characters), 16-hex route hash, GMT timestamp |
| `bfag_cache_stats` | hit and miss counters in five-minute buckets |
| `bfag_webhooks` | webhook subscriptions — label, URL, event, secret, headers (JSON), preset, enabled, max retries |
| `bfag_webhook_deliveries` | the delivery log — payload, status, HTTP status, response body (first 2,000 characters), attempt, timestamps. DB version 1.0.1 adds `idx_webhook_id` and `idx_created_at`; 1.0.2 adds `idx_status_retry (status, next_retry_at)` and `idx_webhook_dir_id (webhook_id, direction, id)` and nulls `next_retry_at` on finished rows; 1.0.3 changes no table (it turns autoload on for the options read on every request); `dbDelta` adds the keys on the first boot after upgrading |

Options: `bfag_settings`, `bfag_db_version`, `bfag_version`, `bfag_cache_generation`, `bfag_webhook_events` (the events that have an enabled webhook, rewritten after every webhook change; read at boot so a page view costs no webhook query) — these five are autoloaded, being read on every request — then `bfag_tag_generations` (the generation of every tag an enabled rule carries, `tag => int` — entries for tags no rule carries any more are dropped on the next purge), `bfag_install_error` (written when a table could not be created — the missing tables, the first database error and the time of the last attempt — rendered as an error notice on every admin screen, retried at most once per five minutes until the next successful install clears it). Transients: `bfag_kpis`, `bfag_form_<user_id>_<tab>` (a refused form, 60 s), `bfag_new_secret_<user_id>`, and without a persistent object cache `bfag_resp_<key>` (stored responses) and `bfag_limited_<key>` (a client over a limit, for the rest of its window, with the count of requests refused). Object-cache groups: `bfag` (rules, the generations, a summary of each enabled webhook — id, label, event, preset, enabled, retries; never its URL, signing secret or headers, which are read from the table for each delivery sent — and with a persistent cache the rate-limit window counters, the cache-stats counters and the limited markers; five-minute TTL for the rule lists) and `bfag_responses` (stored responses, when a persistent object cache is present).

Cached responses go to the WordPress object cache group `bfag_responses` when `wp_using_ext_object_cache()` is true (Redis, Memcached), otherwise to transients — capped there at 64 KB per response and one hour, and swept every five minutes by `bfag_cron_prune_responses`. Purging never enumerates entries: every key embeds a generation number, and a purge bumps the generation so old entries are simply never looked up again and expire by their TTL. With a persistent object cache the rate-limit count and the cache hit/miss counters are `wp_cache_incr()` calls (the hit row is still written; the counters are flushed to `bfag_cache_stats` by the KPI cron), so a cached response costs one query — the hit-log INSERT.

## File structure

```
bluefairy-api-gateway.php        Plugin header, constants, autoloader, lifecycle hooks, bfag_plugin()
includes/
  Plugin.php                     Bootstrap on plugins_loaded; fires bfag_loaded, wires subsystems, fires bfag_booted
  Settings.php                   The bfag_settings option: defaults, bounds, sanitiser
  Installer.php                  Tables, seeded rules, capability, cron schedules
  Capabilities.php               bfag_manage_gateway; the route → API-key scope map used by Pro
  Logger.php                     Opt-in debug logging to the PHP error log
  Cache/
    CacheManager.php             rest_pre_dispatch / rest_post_dispatch: serve, store, label
    CacheStore.php               Object cache or transients; generations for O(1) purge
    CacheKeyGenerator.php        md5 of method, route, sorted query, Accept, generations
    CacheRuleRepository.php      bfag_cache_rules CRUD and glob matching
    CachePurger.php              Post, term and comment hooks → purge
    CacheStatsRepository.php     Five-minute hit/miss buckets
  RateLimit/
    RateLimitMiddleware.php      rest_pre_dispatch at priority 5: block check, count, 429
    HitCounter.php               Insert-then-count sliding window; prune; rotate
    RateLimitRuleRepository.php  bfag_rate_limit_rules CRUD, subject types, matching
    PruneCron.php                bfag_cron_prune_hits
  Webhook/
    EventRegistry.php            Event catalogue, WordPress hooks, allow-listed payloads
    EventBus.php                 Queues events during the request, delivers on shutdown
    WebhookDispatcher.php        Builds, signs, sends and records one delivery
    WebhookRepository.php        bfag_webhooks CRUD; URL and header validation
    DeliveryRepository.php       bfag_webhook_deliveries; truncation, retention, stats
    PruneCron.php                bfag_cron_prune_deliveries
  Support/
    GlobMatcher.php              Route globs: * one segment, ** anything, ? one character
    RequestContext.php           Client IP behind trusted proxies
    IpUtils.php                  IPv4/IPv6 and CIDR helpers
    WritesOptions.php            An option write whose outcome is known (refused vs unchanged)
  Admin/
    AdminMenu.php                Settings → API Gateway: tabs, actions, notices, AJAX
    Page.php                     Base class: nonce + capability on every action
    DashboardPage.php, CachePage.php, RateLimitPage.php, WebhookPage.php, SettingsPage.php
    KpiCron.php                  bfag_cron_recompute_kpis and the bfag_kpis transient
    ProUpsell.php                Side panel, hidden when Pro is active
    Assets.php                   admin.css / admin.js on the plugin's screen only
  Rest/
    StatusController.php         GET /bfag/v1/status
    CacheController.php          POST /bfag/v1/cache/purge
admin/views/                     Templates, every value escaped
assets/css/admin.css, assets/js/admin.js
uninstall.php                    Standalone cleanup; tables only with "Delete data on uninstall"
docs/ARCHITECTURE.md             Module contracts and the extension seams
```

## Extending

The Pro add-on is built entirely on the `bfag_*` actions and filters, which are public API. Every hook, with its signature, is listed at the end of the features guide (`docs/ApiGatewayFeaturesGuide.pdf`) under *Extending*; the module-level contracts are in `docs/ARCHITECTURE.md`.

## Uninstalling

Deleting the plugin through the Plugins screen always removes its options, its transients, its cron events and the `bfag_manage_gateway` capability, on every site of a multisite network. The six tables — your rules, webhooks and delivery log — are dropped only when *Delete data on uninstall* was ticked. Deactivating removes the cron events and the capability and leaves everything else in place.

## Development

```
vendor/bin/phpunit
vendor/bin/phpcs --standard=phpcs.xml.dist
vendor/bin/phpstan analyse --memory-limit=1G --no-progress
node tests/js/admin.test.js
```

The last line runs `assets/js/admin.js` against a dependency-free fake DOM (no npm): the dashboard Refresh (including the blocked card's label from `blocked_label`), the notice-dismiss handler, the rate-limit Penalty-row toggle and the webhook form's URL-required toggle that follows the Preset select.

## Coding standards

- `declare(strict_types=1)` in every PHP file; PHPStan level 6; WordPress Coding Standards.
- Every SQL statement goes through `$wpdb->prepare()`; every list query carries a `LIMIT`.
- Every admin action verifies a nonce and the capability; every output is escaped at the boundary.
- Outbound HTTP only through `wp_remote_post()`, with `reject_unsafe_urls`, no redirects and a five-second timeout.
- No third-party runtime dependencies.

## Support

Documentation ships with the plugin (`docs/ApiGatewayFeaturesGuide.pdf`, `CHANGELOG.md`, `docs/ARCHITECTURE.md`). For questions and bug reports, start from the product page at https://wordpress.bluefairydevelopment.com/product/bluefairy-api-gateway/ or https://bluefairydevelopment.com.
