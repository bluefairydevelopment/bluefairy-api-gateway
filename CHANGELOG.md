# Changelog

All notable changes to BlueFairy API Gateway are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).
Versioning follows [Semantic Versioning](https://semver.org/): major = architecture change, minor = new feature, patch = bug fix or security fix.

---

## [Unreleased]

### Changed
- The seeded rate-limit rule is now 300 requests per 60 s per IP (was 60), and logged-in users who can edit posts are exempt from rate limiting by default (new setting *Exempt editors*, `rate_limit_exempt_editors`).
- The limiter ignores dispatches that are not the top-level HTTP REST request — WP-CLI, WP-Cron and internal `rest_do_request()` calls from admin-ajax or the front end — so a penalty rule can no longer ban the server's own loopback address. Requests with no resolvable client IP are not counted.
- `AdminMenu::handle_actions()` acts only on the plugin's own screen for a user who can manage the gateway, checked before the nonce; a stray `bfag_action` on another admin page is ignored instead of ending in `wp_die`.

### Added
- Multisite: a network-wide activation provisions every site (tables, seeded rules, capability, settings, crons); a site created afterwards is provisioned on `wp_initialize_site`; a site with no DB version is provisioned on its first boot.
- Table creation is verified with `SHOW TABLES` after `dbDelta`. A missing table is written to the PHP error log, recorded in `bfag_install_error`, shown as an admin notice on every screen, and the DB version is not recorded so the next boot retries.
- A dismissible notice on the plugin's screen, the Plugins screen and the Dashboard when a forwarded-address header arrives and no trusted proxies are configured (every visitor would share one rate-limit bucket).
- Help text for the trusted-proxies setting and the seeded rule.

## [1.0.0] — 2026-09-30

Initial release. The free base plugin of a pair; BlueFairy API Gateway Pro is an add-on that attaches to the `bfag_*` seams listed in `FEATURES.md`.

### Added
- **REST response cache.** Anonymous `GET`/`HEAD` responses are stored and served from `rest_pre_dispatch`, and every REST response carries `X-BF-Cache: HIT`, `MISS` or `BYPASS`. Requests from logged-in users or carrying `Authorization`, `X-BF-API-Key` or `X-WP-Nonce` are never cached; responses that are not 2xx, that stream, that set a cookie or that say `no-store`/`private` are never stored. The plugin's own `/bfag/v1` routes are excluded.
- Cache rules matched by route glob (`*` one segment, `**` anything, `?` one character), with a TTL (1–86400 s) and tags; one rule in the free plugin, seeded as `**` / 60 s on activation. Rules are the allow-list.
- Storage in the object-cache group `bfag_responses` when a persistent object cache is present, otherwise in `bfag_resp_*` transients. Purging is O(1) through generation numbers embedded in every key; no entry is ever enumerated.
- Automatic purge when a post is saved, trashed or deleted (revisions and autosaves ignored), a term is created, edited or deleted, or a comment is inserted or changes status. Manual purge from the Cache tab (**Purge all**), by AJAX, and through `POST /bfag/v1/cache/purge` with optional `tags`.
- Cache hit and miss counters in five-minute buckets (`bfag_cache_stats`).
- **Rate limiting.** A sliding-window counter per client IP and route on `rest_pre_dispatch` at priority 5, ahead of the cache. Insert-then-count, so concurrent requests cannot all slip under the limit together. The breaching request answers `429 bfag_rate_limited` with `Retry-After`, `X-BF-RateLimit-Limit` and `X-BF-RateLimit-Window`. Fails open on a database fault.
- Rate-limit rules by route glob with a request count (1–65535) and a window (1–86400 s); out-of-range values are rejected, not clamped. One rule in the free plugin, seeded as 60 per 60 s on `**`. `GET /bfag/v1/status` is exempt.
- Client IP resolution behind trusted proxies: `CF-Connecting-IP`, `X-Real-IP` and `X-Forwarded-For` are honoured only when `REMOTE_ADDR` is inside a configured proxy range, taking the right-most forwarded address that is not itself a proxy.
- Hit-log pruning every five minutes (10,000 rows per batch, up to 50 batches) and a hard cap that truncates the table past ten million rows.
- **Outbound webhooks.** Up to three in the free plugin, each subscribing to one event: `post.published`, `post.updated`, `post.deleted`, `user.registered`, `user.updated`, `comment.created`, and — while WooCommerce is active — `woocommerce.order.created`, `woocommerce.order.status_changed`, `woocommerce.order.completed`, `woocommerce.product.updated`. Payloads are built from an allow-list of fields per object; no password hashes, no `$_SERVER`, no other users' emails.
- Each delivery is a JSON `POST` with `Content-Type`, `User-Agent: BlueFairy-API-Gateway/1.0.0`, `X-BF-Event`, `X-BF-Delivery`, `X-BF-Timestamp` and `X-BF-Signature: sha256=HMAC-SHA256(timestamp.body, secret)`. Sent through `wp_remote_post()` with a ten-second timeout, no redirects and `reject_unsafe_urls`.
- Webhook targets must be `https://` and pass `wp_http_validate_url()`; a blank secret is replaced by a random 64-character one and the stored secret is never displayed. Custom headers are sanitised at send time: `Host`, `Content-Length`, `Transfer-Encoding`, `Connection`, `Cookie`, `Authorization`, `Proxy-Authorization`, `X-Forwarded-*`, line breaks and the plugin's own header names are dropped.
- Events are collected during the request, deduped per event and object id, and delivered once on `shutdown` after the response is sent; a re-entrancy guard stops a receiver's write-back from triggering another delivery. WordPress actions are attached only for events that have an enabled webhook.
- A delivery log with status, HTTP status, the first 2,000 characters of the response, attempt number and timestamps; **Send test** on each webhook; **Retry** on any failed, non-truncated outgoing delivery. Stored payloads are capped at a configurable size and flagged *truncated* beyond it. Finished deliveries older than the retention setting are pruned daily.
- **Dashboard** with cache hit rate, webhook success rate and hit-log size over 24 hours (recomputed every five minutes into a transient, or on demand with **Refresh**), the ten most recent failed deliveries, row counts and a copyable status URL.
- `GET /bfag/v1/status` for monitors and `POST /bfag/v1/cache/purge`, both gated by the `bfag_manage_gateway` capability, which is granted to administrators on activation.
- Settings tab: notification email and failure threshold (stored for the Pro add-on), cache on/off and default TTL, rate limiting on/off and trusted proxies, max stored payload and delivery-log retention, debug logging, a switch to hide the WP-Cron warning, and *Delete data on uninstall*.
- Thirty-three documented `bfag_*` actions and filters as the add-on contract, and `Plugin::service()` for shared instances.
- `uninstall.php` removes options, transients, cron events and the capability on every site; drops the six tables only when *Delete data on uninstall* is on.
- Compatibility: tested against WordPress 7.1; requires PHP 8.1 or newer.
