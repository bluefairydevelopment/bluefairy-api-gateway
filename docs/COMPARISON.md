# API Gateway: Pro and free

The source of truth for every comparison table on the storefront, in the
plugins, and in the guides. Change the code, change this file, then rebuild
everything downstream: the free plugin's `FEATURES.md` (which ends with this
table), the Pro plugin's `FEATURES.md`, both features-guide PDFs, the hub page,
the FAQ page and the product descriptions. This file is identical in both repos.

Pro is always the first column.

| Feature | API Gateway Pro | API Gateway (free) |
|---|---|---|
| REST response cache, every response labelled `X-BF-Cache: HIT` / `MISS` / `BYPASS` | Yes | Yes |
| Cache rules, rate-limit rules, webhooks | Up to 500 of each | Up to 500 of each — the same limit |
| What a content change purges | Only the tags it touches: `post:<id>`, `type:<post_type>`, `term:<id>`, `tax:<taxonomy>`, `all` | Everything |
| **Purge all** button and `POST /bfag/v1/cache/purge` | Yes | Yes |
| **WPGraphQL query caching** (anonymous, error-free queries) | Yes | — |
| Per-IP sliding-window rate limiting with `429` and `Retry-After` | Yes | Yes |
| **Count a rule by logged-in user or by API key** | Yes | — |
| **Penalty block** — an address that breaches an IP rule is blocked for N seconds | Yes | — |
| **DDoS auto-block**, with a never-block list | Yes | — |
| **Blocked IP addresses panel** — see, lift and add blocks by hand | Yes | — |
| Dashboard *Active blocks* card | Live count of blocked addresses | *Blocked (Pro)* placeholder |
| Real client address behind trusted proxies | Yes | Yes |
| Signed outbound webhooks (`X-BF-Signature`, HMAC-SHA256) | Yes | Yes |
| Events — six WordPress, four WooCommerce | Yes | Yes |
| Delivery log with a manual **Retry** on failed deliveries | Yes | Yes |
| **Automatic retries** — 1 min, 5 min, then 30 min, by a five-minute sweep, up to each webhook's *Max retries* (0–10) | Yes | — |
| **Retries per delivery** — a site-wide default for new webhooks | Yes | — |
| **Failure alert email** — on the first failure, after N in a row, or after the last retry | Yes | — (sends no alerts) |
| **Salesforce, HubSpot and Mailchimp presets** — the delivery becomes a CRM record | Yes | — |
| **Verified incoming webhooks** at `/bfag/v1/incoming/<preset>` | Yes | — |
| **Webhook REST API** — list, create, update, delete, read the log, retry | Yes | — |
| **Scoped API keys** (`read`, `write`, `cache:purge`, `webhook:read`, `webhook:write`) | Yes | — |
| `GET /bfag/v1/status` for a monitoring tool | Yes | Yes |
| **Updates through your licence key** (Licence tab); no feature depends on the licence | Yes | — (no automatic updates: new versions are a download from the shop) |
| Admin tabs | 7 (adds **API Keys** and **Licence**) | 5 |

## Where each row was verified

The **Licence** rows describe Pro's licence client: `Licence\Updater` offers updates through the `Update URI` header's `update_plugins_wordpress.bluefairydevelopment.com` filter only while `Licence::is_active()`, and nothing outside `includes/Licence/` and the Licence tab reads the licence (`PluginTest::test_no_licence_state_switches_off_any_pro_feature`).

Against the 1.0.1 code of both plugins, re-verified on 2026-10-02 at free 843e3b3 and Pro 8e41a8a:

- **Limits** — the free plugin's `bfag_cache_rule_limit`, `bfag_rate_limit_rule_limit` and `bfag_webhook_limit` all default to `Support\Limits::DEFAULT_LIMIT`, **500** (WPMAP-142; 1, 1 and 3 before 1.0.1). Pro attaches nothing to them on a 1.0.1 or newer base (`PluginTest::test_boot_attaches_is_pro_and_subject_types_and_leaves_the_limits_alone`); only on an older base does `Plugin::hold_legacy_base_limits()` hold them at the same 500.
- **No alerts in the free plugin** — no `wp_mail()` call anywhere in it; Pro's `Webhook\FailureNotifier` sends the alert, in the modes `Settings::ALERT_MODES` (`first`, `after_n`, `exhausted`; default `exhausted`).
- **Tag purges** — Pro's `Cache\TagPurgeProvider` on `bfag_cache_purge_tags`; the free plugin's default answer is to flush everything.
- **GraphQL** — Pro's `Cache\GraphQLCacheIntegration` on `pre_graphql_execute_request` and `graphql_return_response`.
- **Subjects** — Pro adds `user` and `apikey` to `bfag_rate_limit_subject_types`.
- **Blocks** — Pro's `RateLimit\DdosMitigator` (penalty on breach, DDoS on refusal) and `Admin\BlockedIpsPanel` (durations 1 hour, 6 hours, 1 day, 1 week, 30 days).
- **Retries** — `Settings::RETRY_SCHEDULE` is `[ 60, 300, 1800 ]`; the sweep runs on `bfag_five_minutes`; `default_max_retries` defaults to 3, bounded 0–10.
- **Presets** — `SalesforcePreset`, `HubSpotPreset`, `MailchimpPreset`.
- **REST** — Pro's `Rest\WebhookController` and `Rest\IncomingWebhookController`; scopes from the free plugin's `Capabilities::SCOPES`.

## How the two fit together

Pro is an add-on. It boots on `bfag_loaded`, which only the free plugin fires;
installed alone it shows a notice linking to the shop's downloads page and does
nothing. It carries no `Requires Plugins` header, because WordPress resolves that
slug on WordPress.org, where the free plugin is not listed. Every rule, webhook and delivery lives in the free
plugin's tables, so upgrading moves nothing and removing Pro loses nothing the
free plugin owns. Adding Pro to the cart adds the free plugin with it.

## Things that must never be claimed

- **"Unlimited."** The ceiling is 500 rules of each kind and 500 webhooks, in
  both plugins.
- **That Pro raises, lifts or removes a limit**, or that the free plugin allows
  fewer rules or webhooks than Pro. Both allow 500 of each (WPMAP-142), and
  WordPress.org guideline 5 forbids a free plugin whose functionality is
  locked until an upgrade. Sell Pro on the rows above that the free plugin
  does not have at all.
- **"Ban."** The screens say *block*, *Blocked IP addresses*, *Unblock*. Use
  their word.
- That the free plugin sends any alert, or retries anything on its own. It
  records a failure and offers **Retry**; nothing else happens until someone
  presses it.
- That an API key works outside the REST API. It is read on REST requests only.
- That tags narrow the purge of an **untagged** rule. A rule with no tags is
  keyed on `all` and is purged by every content change, with or without Pro.
