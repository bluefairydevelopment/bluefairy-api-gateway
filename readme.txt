=== BlueFairy API Gateway ===
Contributors: bluefairydevelopment
Tags: rest api, cache, rate limit, webhooks, headless
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

REST response caching, per-IP rate limiting and signed outbound webhooks — a hardened API layer for headless WordPress sites and integrations.

== Description ==

BlueFairy API Gateway sits in front of the WordPress REST API and does three things, each managed from one screen at **Settings → API Gateway**.

**Response cache.** Anonymous `GET` responses are stored and served back with an `X-BF-Cache: HIT` header; every REST response is labelled `HIT`, `MISS` or `BYPASS` so you can see the decision from `curl`. Requests from logged-in users, or carrying an `Authorization`, `X-WP-Nonce` or `X-BF-API-Key` header, are never cached. Responses that are not 2xx, that stream, that set a cookie or whose `Cache-Control` says `no-store`, `private`, `no-cache` or `max-age=0` are never stored. One cache rule, matched by route glob (`wp/v2/posts/**` — a trailing `/**` or `/*` matches the collection route too), seeded on activation as "all routes, 60 seconds". The cache is purged automatically when a post, term or comment changes, and by hand from the Cache tab or `POST /bfag/v1/cache/purge`. Responses live in your persistent object cache (Redis, Memcached) when you have one, otherwise in transients — capped there at 64 KB per response and one hour, and swept every five minutes.

**Rate limiting.** A sliding-window counter per client IP and rule. The breaching request gets `429 Too Many Requests` with `Retry-After`, `X-BF-RateLimit-Limit` and `X-BF-RateLimit-Window`. One rule, seeded as 300 requests per 60 seconds across every route; logged-in users who can edit posts are exempt by default. One forwarded-IP header of your choosing (`X-Forwarded-For` by default; `CF-Connecting-IP`, `X-Real-IP` or `True-Client-IP`) is honoured, and only from the proxy ranges you list, so a visitor cannot pick their own bucket. A client over the limit is refused from a marker for the rest of its window without touching the database; with a persistent object cache the count itself is an atomic increment rather than a query. The hit log is pruned every five minutes.

**Signed outbound webhooks.** Up to three webhooks, each subscribing to one event: post published, updated or deleted; user registered or updated; comment created; and, with WooCommerce, order created, order status changed, order completed, product updated. Each delivery is a JSON `POST` carrying `X-BF-Event`, `X-BF-Delivery`, `X-BF-Timestamp` and `X-BF-Signature: sha256=HMAC-SHA256(timestamp.body, secret)`, so the receiver can verify both origin and freshness. Deliveries are collected during the request and sent once after the response — twenty at most, and for fifteen seconds at most, the rest through a WP-Cron event so a bulk import or a dead endpoint never ties up the request that saved it — logged with their HTTP status and response body, and any failed delivery can be resent from the log. Targets must be `https://`; a secret is generated for you and shown once, right after saving, for you to copy into the receiver.

**Dashboard.** Cache hit rate and webhook success rate over the last 24 hours, the current size of the rate-limit log, the ten most recent failed deliveries with a Retry button, and the same figures at `GET /bfag/v1/status` for a monitoring tool — a route that is never rate-limited.

**Add API Gateway Pro** for up to 500 cache rules, rate-limit rules and webhooks; purges narrowed to the tags a change touches; rate limits per user and per API key with penalty blocks; DDoS auto-block with a blocked-IPs panel; automatic webhook retries with back-off and failure alerts by email; Salesforce, HubSpot and Mailchimp presets; a verified incoming-webhook endpoint (HubSpot signed; Salesforce and Mailchimp shared secret); a webhook REST API; API keys with scopes; and WPGraphQL query caching. Pro installs alongside this plugin rather than replacing it.

== Installation ==

1. Upload the `bluefairy-api-gateway` folder to `/wp-content/plugins/`, or install the zip from **Plugins → Add New → Upload Plugin**.
2. Activate the plugin from the **Plugins** screen. PHP 8.1 or newer is required; activation refuses otherwise.
3. Open **Settings → API Gateway**.

Activation creates six database tables, seeds one cache rule and one rate-limit rule, grants the `bfag_manage_gateway` capability to administrators and schedules four recurring WP-Cron jobs. The cache and the rate limiter are on from the first request; nothing is sent anywhere until you add a webhook.

== Admin Instructions ==

Everything lives under **Settings → API Gateway**, in five tabs.

**Dashboard** — three cards (cache hit rate, webhook success rate for outgoing deliveries, and the Pro *Active blocks* count — shown as *Blocked (Pro)* without the add-on) recomputed every five minutes or when you press **Refresh**; the ten newest failed deliveries with **Retry**; the raw counts; and the status endpoint URL with a **Copy** button. Timestamps are shown in the site's timezone.

**Cache** — the rules table with an Enabled/Disabled badge you can click, **Edit** and **Delete**, and **Purge all**. The add/edit form takes a label, a route glob (matched without the leading slash: `wc/v3/products/*` matches `wc/v3/products` and `wc/v3/products/12`), a TTL in seconds and optional comma-separated tags. A rule with no tags is purged on every content change; add tags to narrow purging to those tags. Edit the seeded "All routes" rule to narrow it. With more than one rule the most specific one wins, and the table is listed in that order.

**Rate limit** — the rules table and a form with a label, *Count by* (IP address), a route glob, and *Requests per seconds*. Values outside 1–65535 requests or 1–86400 seconds are refused rather than silently changed.

**Webhooks** — the webhooks table with **Edit**, **Send test** and **Delete**, the **Delivery log** button, and the add/edit form: label, `https://` URL, event, signing secret (blank generates one and shows it once after saving), extra headers as `Name: value` lines, and an Enabled box. Press **Send test** on a new webhook: the notice reports the HTTP status, and the delivery log shows the receiver's response under each row.

**Settings** — the cache switch and *New-rule TTL*; the rate-limiting switch, *Exempt editors*, *Trusted proxies* and *Client address header*; the stored-payload cap and delivery-log retention; debug logging, the WP-Cron notice switch and *Delete data on uninstall*. The notification email and failure threshold appear only with the Pro add-on, which is what sends the alerts.

To confirm the cache from the command line, request a public route twice and read the header: `curl -sI https://example.com/wp-json/wp/v2/posts | grep -i x-bf-cache` — `MISS` then `HIT`.

== Frequently Asked Questions ==

= Why am I seeing X-BF-Cache: BYPASS? =

`BYPASS` means the request or the response was not cacheable. On the request side: the cache is switched off; the method is not GET or HEAD; you are logged in (a browser with a WordPress cookie, including the admin's own browser); the request carries an `Authorization`, `X-WP-Nonce` or `X-BF-API-Key` header; the route is under `/bfag/v1`; or no enabled cache rule matches the route. On the response side: the status is not 2xx; the response streams (`text/event-stream`); it sets a cookie (including through PHP's `setcookie()`, as WooCommerce's guest cart session does — a plugin that sets a cookie on every request keeps responses from being stored); or its `Cache-Control` says `no-store`, `private`, `no-cache` or `max-age=0`. Test with `curl` from a fresh shell, not from a logged-in browser tab.

= Does it work without Redis or Memcached? =

Yes. With a persistent object cache responses are stored in the object-cache group `bfag_responses`; without one they are stored as transients in the options table — capped at 64 KB per response and one hour, with expired entries swept every five minutes — and purging works the same way on both. A persistent object cache is faster, has no size cap, keeps the options table small and turns the rate-limit count and the cache statistics into in-memory increments, so use one on a busy site.

= What happens behind Cloudflare, a load balancer or a reverse proxy? =

By default the client IP is `REMOTE_ADDR`, which behind a proxy is the proxy's own address — so every visitor would share one rate-limit bucket. List the proxy's addresses or CIDR ranges under **Settings → Rate limit → Trusted proxies** (for Cloudflare, its published IP ranges) and pick the **Client address header** your proxy writes (`X-Forwarded-For` by default; for Cloudflare, `CF-Connecting-IP`). Only when `REMOTE_ADDR` is inside one of those ranges is that one header read — for `X-Forwarded-For`, taking the right-most address that is not itself a trusted proxy. Every other header, and headers from anywhere else, are ignored, so a visitor cannot forge their way into a different bucket, not even by adding an `X-Real-IP` of their own in front of a proxy that only manages `X-Forwarded-For`.

= Does uninstalling delete my data? =

Deleting the plugin always removes its options, transients, cron events and the `bfag_manage_gateway` capability. Your rules, webhooks and delivery log — the six `bfag_` database tables — are dropped only if you ticked **Delete data on uninstall** under Settings → Advanced first. By default they stay, because a delivery log is a record of what was sent, not a cache. Deactivating removes only the cron events and the capability.

= How do retries work? =

In the free plugin a failed delivery — any non-2xx answer, or no answer within five seconds — is marked `failed` in the log and stays there. Press **Retry** — offered on failed deliveries only — on the dashboard or in the delivery log to resend the stored payload bytes as the next attempt, with a fresh timestamp and signature and the same `X-BF-Delivery` id. A delivery whose stored payload was truncated at the *Max stored payload* setting cannot be retried, because the stored bytes are no longer what was signed. Automatic retries with back-off (one minute, five minutes, thirty minutes, up to a per-webhook limit) and failure alerts are part of API Gateway Pro.

= How does my receiver verify the signature? =

Compute `HMAC-SHA256` with the webhook's secret over the string `X-BF-Timestamp + "." + raw request body`, hex-encode it, prefix `sha256=`, and compare it to `X-BF-Signature` in constant time (`hash_equals()` in PHP, `crypto.timingSafeEqual()` in Node). Read the raw body bytes before any framework parses them. Reject timestamps more than a few minutes old to defeat replay. Full PHP and Node examples are in the features guide shipped with the plugin, `docs/ApiGatewayFeaturesGuide.pdf`, under *Webhooks → Verifying at the receiver*.

= Why does editing a blog post empty the whole cache? =

Because the free plugin has one rule and no way to tell which cached responses a change touches, so any post, term or comment change flushes everything. API Gateway Pro answers the `bfag_cache_purge_tags` filter with the tags each change touches (`post:42`, `type:product`, `term:7`, `tax:category`, `all`), so a rule tagged `type:product` survives a blog post edit. A rule with no tags is purged on every content change even with Pro; add tags to narrow purging to those tags.

= Will my logged-in users or my app's authenticated API calls be cached? =

No. A request with a WordPress login cookie, an `Authorization` header (Application Passwords, bearer tokens), an `X-WP-Nonce` or an `X-BF-API-Key` is never served from or stored in the cache, because such a response may contain data nobody else should see. Only anonymous public reads are cached.

= What are the limits in the free plugin? =

One cache rule, one rate-limit rule and three webhooks. Rate limiting counts by IP address only. The admin page tells you when you have reached a limit. Pro raises each to 500 and adds per-user and per-API-key counting.

= The webhook will not save — "URL refused: it must start with https:// and resolve to a public address". =

Each refused field has its own notice: *URL refused…* for the URL, *Webhook not saved: the label must be 1–200 characters* for the label, *Webhook not saved: choose an event from the list* for the event, and *Webhook not saved: check the extra headers (one Name: value per line) and the secret (255 characters at most)* for the rest. Your entries are kept in the form.

The URL must start with `https://` and must pass WordPress's own `wp_http_validate_url()` check, which refuses loopback and private addresses such as `localhost`, `127.0.0.1` or `10.x.x.x`. For local development a developer can allow `http://` with the `bfag_webhook_allow_http` filter. The event must be one offered in the dropdown — a WooCommerce event disappears from the list when WooCommerce is deactivated.

= I let the plugin generate the signing secret and did not copy it. Where is it? =

A generated secret is shown exactly once, on the Webhooks tab right after you save, with a **Copy** button; after that the stored secret cannot be read back from the admin page. If you missed it, edit the webhook and either type your own secret into the field, or tick **Generate a new secret** and save — the new one is shown once in the same way. Either way, update the receiver: deliveries fail there until it has the new secret.

= Does it need WP-Cron? =

The hit-log prune, the delivery-log prune and the dashboard figures run on WP-Cron. The first 20 webhook deliveries of a request are sent at the end of that request; the rest are queued and sent by `bfag_cron_flush_pending`, a single event scheduled on demand. If `DISABLE_WP_CRON` is set the plugin shows a notice on its own screen; make sure a system cron runs `wp-cron.php`, then tick **Hide the WP-Cron notice**. There are no WP-CLI commands; `wp cron event run bfag_cron_prune_hits` runs a job on demand.

= Does it work with WooCommerce? =

Yes. While WooCommerce is active four more events are offered: order created, order status changed, order completed and product updated, each carrying the order or product fields (id, status, currency, total, customer id, billing email; id, name, SKU, status, price, type). WooCommerce's own REST routes under `wc/v3/…` can be cached and rate-limited like any other, subject to the same rule that authenticated requests are never cached.

= Does it support multisite? =

Network activation provisions every site — tables, the seeded rules, the capability and the cron jobs — and a site added later is provisioned when it is created. Each site keeps its own rules, webhooks and settings. Uninstalling cleans up on every site of the network.

== External services ==

This plugin connects to services outside your site in one way only: **outbound webhooks that you configure**. Nothing is sent anywhere until an administrator adds a webhook on **Settings → API Gateway → Webhooks**, and the plugin never contacts Blue Fairy Development or any other fixed address — there is no telemetry, licence check or update server.

* **What it is used for:** notifying another system (your own app, an automation service, a CRM relay — whatever endpoint you choose) that something happened on your site.
* **Where data is sent:** only to the `https://` URL you enter for each webhook, and only for the one event that webhook subscribes to. **Send test** sends a sample `test` event to that URL when you press it.
* **What is sent, and when:** a JSON `POST` each time the subscribed event happens, carrying the event name, your site URL, a timestamp and the object's allow-listed fields — for a post: id, type, status, title, slug, link, author id and dates; for a user: id, login, email, display name and roles; for a comment: id, post id, author name, author email, approval status, date and type; for a WooCommerce order: id, status, currency, total, customer id, billing email and date created; for a product: id, name, SKU, status, price and type. Each request also carries the `X-BF-Event`, `X-BF-Delivery`, `X-BF-Timestamp` and `X-BF-Signature` headers and any extra headers you added to the webhook.
* **Whose terms apply:** the receiving service is one you choose and control, so its terms of service and privacy policy are the ones that govern the data. Review them before sending personal data such as email addresses to it, and mention the transfer in your own site's privacy policy where the law requires.

== Screenshots ==

1. Dashboard — cache hit rate and webhook success rate over 24 hours, the recent failed deliveries with Retry, the row counts and the status endpoint.
2. Cache — the seeded "All routes" rule with its route glob, TTL and Enabled badge, and Purge all.
3. Rate limit — the seeded per-IP rule: 300 requests per 60 seconds on every route.
4. Webhooks — three signed webhooks with their events and last-delivery status, Send test, and the Delivery log button.
5. Settings — the cache, rate-limit, webhooks and advanced groups.
6. Delivery log — every delivery with its HTTP status; a failed delivery's response opened, with Retry.

== Changelog ==

= 1.0.1 =
* Packaging for WordPress.org; no change to behaviour, settings or data.
* The readme gains an *External services* section describing the outbound webhooks you configure.
* The Markdown feature reference is no longer in the plugin zip; everything it held — including the receiver signature examples and the Pro comparison — is in the features guide, `docs/ApiGatewayFeaturesGuide.pdf`.
* The time-limit lift used while a network-wide activation provisions every site is documented in the code.
* Screenshots retaken on this version; a sixth shows the delivery log. The features guide is rebuilt with the new dashboard and delivery-log images.

= 1.0.0 =
* First release.
* REST response cache with `X-BF-Cache: HIT` / `MISS` / `BYPASS`, one route-glob rule, object-cache or transient storage (64 KB / one-hour cap on transients), generation-based purging on post, term and comment changes, Purge all, and `POST /bfag/v1/cache/purge`.
* Sliding-window rate limiting per IP and rule with `429`, `Retry-After` and `X-BF-RateLimit-*` headers; one rule seeded at 300 per 60 s; editors exempt by default; trusted-proxy IP resolution from one chosen header; refused requests served from a marker with no database work.
* Up to three signed outbound webhooks on six WordPress events and four WooCommerce events, HMAC-SHA256 `X-BF-Signature` over `timestamp.body`, delivery after the response (twenty at most, fifteen seconds at most, the rest by WP-Cron), a delivery log with Send test and Retry on failed outgoing deliveries, payload truncation and retention pruning.
* Dashboard figures recomputed every five minutes, `GET /bfag/v1/status` for monitors, the `bfag_manage_gateway` capability, multisite provisioning.
* Thirty-six `bfag_*` actions and filters as the add-on contract.
* Compatibility: tested against WordPress 7.1; requires PHP 8.1 or newer. Database schema version 1.0.3 (internal).

== Upgrade Notice ==

= 1.0.1 =
Packaging and documentation only — no change to behaviour, settings or data.

= 1.0.0 =
First release — nothing to upgrade from. Rate limiting is on from activation at 300 requests per minute per IP on every REST route; behind Cloudflare or a load balancer, add the proxy ranges under Settings → Rate limit → Trusted proxies before relying on it.
