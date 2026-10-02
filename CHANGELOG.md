# Changelog

All notable changes to BlueFairy API Gateway are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).
Versioning follows [Semantic Versioning](https://semver.org/): major = architecture change, minor = new feature, patch = bug fix or security fix.

---

## [1.0.0] — 2026-10-01

First release. The free base plugin of a pair: BlueFairy API Gateway Pro is an add-on that attaches to the `bfag_*` hooks listed in `FEATURES.md` and does not replace this plugin.

### Added
- **REST response cache.** Anonymous `GET`/`HEAD` responses are served from `rest_pre_dispatch`, and every REST response carries `X-BF-Cache: HIT`, `MISS` or `BYPASS`. One cache rule matched by route glob (`*` one segment, `**` anything, `?` one character; a trailing `/*` or `/**` also matches the collection), seeded as `**` / 60 s. Stored in the persistent object cache when there is one, otherwise as transients capped at 64 KB and one hour and swept every five minutes. Purged by generation number — no entry is ever enumerated — when a post, term or comment changes, from the Cache tab, by AJAX, or through `POST /bfag/v1/cache/purge` with optional `tags`.
- **Rate limiting.** A sliding-window counter per client IP and rule on `rest_pre_dispatch` at priority 5, ahead of the cache; insert-then-count, so concurrent requests cannot slip under the limit together. One rule, seeded as 300 requests per 60 s on every route (the add form pre-fills the same figures); logged-in users who can edit posts are exempt by default. The breaching request answers `429 bfag_rate_limited` with `Retry-After`, `X-BF-RateLimit-Limit` and `X-BF-RateLimit-Window`; further requests in that window are refused from a marker with no database work. Only the top-level HTTP REST request is counted — never WP-CLI, WP-Cron or an internal `rest_do_request()`. `GET /bfag/v1/status` is exempt. Fails open on a database fault.
- **Trusted proxies.** One forwarded-address header of your choosing (`X-Forwarded-For` by default; `CF-Connecting-IP`, `X-Real-IP` or `True-Client-IP`) is honoured, and only when `REMOTE_ADDR` is inside a listed proxy range. A dismissible notice appears while a forwarded header arrives and no proxies are configured.
- **Outbound webhooks.** Up to three, each on one event: `post.published`, `post.updated`, `post.deleted`, `user.registered`, `user.updated`, `comment.created` and, with WooCommerce, `woocommerce.order.created`, `woocommerce.order.status_changed`, `woocommerce.order.completed`, `woocommerce.product.updated`. Payloads are allow-listed fields only. Each delivery is a JSON `POST` with `X-BF-Event`, `X-BF-Delivery`, `X-BF-Timestamp` and `X-BF-Signature: sha256=HMAC-SHA256(timestamp.body, secret)`, sent with a five-second timeout, no redirects and `reject_unsafe_urls`. Targets must be public `https://` (blank only when a Pro preset builds the request); a blank secret becomes a random 64-character one, shown once.
- Deliveries are collected during the request, deduplicated per event and object, and sent on `shutdown` after the response — twenty at most and for fifteen seconds at most; the rest are queued for `bfag_cron_flush_pending`, which drains them in batches for up to twenty seconds a run and re-arms while rows remain.
- **Delivery log** with status, HTTP status, the first 2,000 characters of the response, attempt and site-time timestamps; **Send test** per webhook; **Retry** on failed, non-truncated outgoing deliveries only (any other row is refused with 400). Stored payloads are capped at *Max stored payload*; finished rows are pruned after *Delivery log retention* days.
- **Dashboard**: cache hit rate, webhook success rate (outgoing deliveries only) and the Pro *Active blocks* card (*Blocked (Pro)* without the add-on), recomputed every five minutes into the `bfag_kpis` transient or on **Refresh**; the ten newest failed deliveries with Retry; row counts; a copyable status URL.
- `GET /bfag/v1/status` and `POST /bfag/v1/cache/purge`, gated by the `bfag_manage_gateway` capability (granted to administrators on activation) or, with Pro, an API key whose scope covers the route.
- Settings: cache on/off and new-rule TTL; rate limiting on/off, exempt editors, trusted proxies and client address header; max stored payload and delivery-log retention; debug logging; the WP-Cron notice switch; *Delete data on uninstall*. Notification email and failure threshold are stored for the Pro add-on and shown only with it.
- Four recurring WP-Cron jobs (hit-log prune, delivery-log prune, KPI recompute and transient prune) on a five-minute or daily schedule. Multisite: network activation provisions every site, and a site created later is provisioned as it is born.
- Thirty-six documented `bfag_*` actions and filters as the add-on contract, and `Plugin::service()` for shared instances.

### Security
- Requests from logged-in users or carrying `Authorization`, `X-BF-API-Key` or `X-WP-Nonce` are never cached; responses that are not 2xx, that stream, that set a cookie (on the response or through PHP's `setcookie()`, as a WooCommerce guest cart session does) or that say `no-store`, `private`, `no-cache` or `max-age=0` are never stored.
- Custom webhook headers are sanitised at send time: `Host`, `Content-Length`, `Transfer-Encoding`, `Connection`, `Cookie`, `Authorization`, `Proxy-Authorization`, `X-Forwarded-*`, the plugin's own header names and any value containing a line break are dropped. `Authorization` is accepted only from the `bfag_webhook_request_args` filter.
- A webhook's URL, signing secret and custom headers never enter the object cache: the cached list of enabled webhooks holds ids, labels, events and presets only, and each delivery reads its webhook from the database.
- An API-key scope is read only through the `bfag_request_key_scope` filter, never from a request parameter.
- Every admin action checks the screen and the capability before the nonce; every output is escaped at the boundary.
- A settings save or cache purge the database refuses is reported — an error notice, or `500 bfag_purge_failed` from the purge endpoint and the AJAX button — never as saved or purged.
- Table creation is verified after `dbDelta`; a missing table is logged, recorded in `bfag_install_error`, shown as an admin notice and retried at most once every five minutes.

### Notes
- Database schema version **1.0.3** — an internal counter; the first release ships at it.
- The options read on every request (`bfag_settings`, `bfag_db_version`, `bfag_version`, `bfag_cache_generation`, `bfag_webhook_events`) are autoloaded, so without a persistent object cache a page view costs no query for them and none for the webhook list.
- `uninstall.php` removes options, transients (`bfag_kpis` by name as well as by sweep), cron events, the per-user proxy-notice dismissal and the capability on every site; the six tables are dropped only when *Delete data on uninstall* is on.
- Tested against WordPress 7.1; requires WordPress 6.6 and PHP 8.1 or newer.
