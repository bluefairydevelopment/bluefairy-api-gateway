# BlueFairy API Gateway — architecture and module contracts

Base plugin (`bluefairy-api-gateway`, namespace `BlueFairy\ApiGateway`, prefix `bfag_`).
The Pro add-on (`bluefairy-api-gateway-pro`, `BlueFairy\ApiGatewayPro`, `bfagp_`) hooks the
seams listed under **Extension seams** and never ships inside this plugin.

Plan: `~/Sites/blueSites/plans/bluefairy-api-gateway.plan.md` (read "Revision v3" first).

## Fixed conventions

- `declare(strict_types=1);` on line 2 of every PHP file. WPCS (`phpcs.xml.dist`), PHPStan
  level 6 (`phpstan.neon`), PHPUnit 10 (`phpunit.xml.dist`). Tabs. Yoda conditions.
  Every function has a docblock with `@param` for every parameter.
- PSR-4 under `includes/`. Instance-based services wired in `Plugin::boot()`; only
  `Plugin::boot/service/set`, `Installer::*`, `Capabilities::*`, `Logger::*`,
  `GlobMatcher::*`, `IpUtils::*` and `CacheStore::sanitize_tag()` are static.
- All SQL through `$wpdb->prepare()`; reads via `get_results/get_row/get_var/get_col`;
  writes via `insert/update/delete` or prepared `query()`. Table names are
  `$wpdb->prefix . 'bfag_…'`. Every list query has a `LIMIT`.
- Hot-path reads (`all_enabled()`) are object-cached in group `bfag`, key named below,
  TTL 300, and every write to that table calls `wp_cache_delete()` on the key.
- Admin: every form handler calls `check_admin_referer( 'bfag_<action>' )` and
  `current_user_can( Capabilities::MANAGE )`; every AJAX handler calls
  `check_ajax_referer( 'bfag_ajax_<action>', 'nonce' )` and the same capability.
- Output escaped at the boundary (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`).
- Text domain `bluefairy-api-gateway`. Prefix every global (`bfag_`), hook (`bfag_`),
  option (`bfag_`), transient (`bfag_`), cron (`bfag_cron_`), AJAX action (`bfag_ajax_`),
  handle (`bfag-`), capability (`bfag_manage_gateway`), REST namespace `bfag/v1`.
- Logging: `Logger::log( string $message, array $context )`, only when debug_logging is on.
- No third-party SDKs or Composer runtime dependencies. HTTP only via `wp_remote_*`.

## Shared scaffold (already written — do not change signatures)

| Class | Public API |
|---|---|
| `Settings` | `all(): array`, `get( string $key ): mixed`, `update( array ): array`, `sanitize( array ): array`, `refresh()`, `Settings::DEFAULTS`, `Settings::FORWARDED_HEADERS` (`X-Forwarded-For` (default), `CF-Connecting-IP`, `X-Real-IP`, `True-Client-IP`), `Settings::to_bool()`, `Settings::sanitize_cidr_list()`, `Settings::sanitize_forwarded_header()` (one of `FORWARDED_HEADERS` in canonical spelling, else the default) |
| `Capabilities` | `MANAGE`, `SCOPES` (`read`, `write`, `cache:purge`, `webhook:read`, `webhook:write`), `SCOPE_LABELS` (scope → one-line description, same keys and order), `add_caps()`, `remove_caps()`, `current_user_can_manage()`, `required_scope( string $route, ?string $method = null ): string` — `cache:purge` for `/bfag/v1/cache/purge`; `webhook:read`/`webhook:write` for `/bfag/v1/webhooks…` by method; `read` for anything else under `/bfag/v1/`; outside `/bfag/v1` `read` for GET/HEAD/OPTIONS and `write` for every other method (a key acts as its user, so a read-only key must not be able to `POST /wp/v2/posts`); the route is compared lower-cased, because `WP_REST_Server` matches case-insensitively and `/BFAG/v1/webhooks` reaches the webhooks controller, `scope_satisfied( string $permissions, string $required ): bool` — `webhook:write` implies `webhook:read`; nothing else implies anything (`write` does not imply `read`) |
| `Installer` | `TABLES`, `OPTIONS`, `CRON`, `INSTALL_ERROR_OPTION`, `activate( bool $network_wide = false )` (network-wide on multisite: `provision_site()` on every site via `get_sites`/`switch_to_blog`), `provision_site()` (caps, settings, tables, seed, versions, crons — stops after a failed `create_tables()`), `initialize_site( \WP_Site )` (hooked to `wp_initialize_site` at 20 by `Plugin::boot()`; provisions a new site when the plugin is network-active), `deactivate()`, `maybe_upgrade()` (no `bfag_db_version` → `provision_site()`; behind → `create_tables()` then `add_caps()` + `schedule_cron()`), `schema( $prefix, $charset ): array`, `create_tables(): bool` (dbDelta, then `SHOW TABLES LIKE` for every table; on a missing table: no version written, `error_log()` unconditionally, option `bfag_install_error` = `{tables, db_error, at}` which `AdminMenu` renders as an error notice on every admin screen; cleared by a later success), `seed_default_rules()` (cache `**`/60 s; rate limit *Global per-IP limit* 300 per 60 s), `schedule_cron()`, `cron_schedules( array ): array` (adds `bfag_five_minutes`), `SINGLE_EVENTS` (`bfag_cron_flush_pending`; `deactivate()` and `uninstall.php` clear it with `wp_unschedule_hook()`). `CRON` is `bfag_cron_prune_hits`, `bfag_cron_prune_deliveries` (daily), `bfag_cron_recompute_kpis`, `bfag_cron_prune_responses` (all others every five minutes) |
| `Logger` | `use_settings( ?Settings )`, `enabled()`, `log( string, array = [] )`, `lines()`, `clear()` |
| `Support\GlobMatcher` | `compile( string $glob ): string`, `matches( string $glob, string $route ): bool`, `is_valid( string $glob ): bool`, `specificity( string $glob ): int` — `*` = one segment, `**` = anything, `?` = one char; matching is case-insensitive (the compiled pattern carries the `i` flag) because `WP_REST_Server` matches routes that way; a TRAILING `/*` or `/**` is optional, so `wp/v2/posts/**` matches the collection `wp/v2/posts` as well as everything beneath it and `wc/v3/products/*` matches `wc/v3/products` and its direct children (mid-pattern wildcards are never optional); routes compared with the leading `/` stripped. `specificity()` = count of characters that are not `*` or `?` (leading slash excluded) |
| `Support\RequestContext` | `__construct( Settings )`, `client_ip(): string` — `REMOTE_ADDR`, unless it is inside `trusted_proxies`, in which case ONE header is read: the one named by `forwarded_header` (`X-Forwarded-For`: right-to-left walk skipping trusted proxies; the single-value headers: the value when it is a valid IP). Every other forwarding header is ignored whatever it says, so a visitor behind a proxy that only manages XFF cannot pick their own address with an `X-Real-IP`/`CF-Connecting-IP` of their own. `reset()`, `RequestContext::normalize_route( string ): string` |
| `Support\IpUtils` | `is_valid_ip`, `is_valid_cidr_or_ip`, `in_range( $ip, $cidr )`, `in_any_range( $ip, array $ranges )` |
| `Plugin` | `boot()`, `service( string $id ): ?object`, `set( string $id, object )`, `reset()`, `public static bool $dispatching` |

Settings keys: `cache_enabled`, `default_cache_ttl`, `rate_limit_enabled`, `rate_limit_exempt_editors`, `trusted_proxies`,
`forwarded_header`, `max_payload_bytes`, `retention_days`, `failure_threshold`,
`notification_email`, `debug_logging`, `disable_cron_notice`, `delete_data_on_uninstall`.

Tables (see `Installer::schema()` for columns): `bfag_cache_rules`, `bfag_rate_limit_rules`,
`bfag_rate_limit_hits` (`route_hash` CHAR(16) = first 16 hex chars of sha256 of the bucket key —
the middleware's `rule:<id>` — computed in PHP; `route` VARCHAR(500) = the lower-cased,
slash-stripped real route, for the admin, written capped at `HitCounter::ROUTE_COLUMN_LENGTH` = 191), `bfag_cache_stats` (5-minute buckets), `bfag_webhooks`,
`bfag_webhook_deliveries` (DB version 1.0.1 added `KEY idx_webhook_id (webhook_id)` — the per-webhook
`ORDER BY id` reads — and `KEY idx_created_at (created_at)` for `recent()`; `dbDelta` adds them on upgrade).

Options: `bfag_settings`, `bfag_db_version`, `bfag_version`, `bfag_cache_generation`, `bfag_tag_generations`, `bfag_kpis`, `bfag_install_error`.
Transients: `bfag_kpis`, `bfag_resp_<key>` (responses, no persistent object cache), `bfag_limited_<marker>` (a breached subject, no persistent object cache), `bfag_new_secret_<user_id>`. Object-cache group `bfag` also holds, with a persistent object cache, the rate-limit window counters `bfag:rl:<md5>:<window>`, the cache-stats counters `stats:<bucket>:hits|misses`, the limited markers `limited:<md5>` and `status_webhook_count`.

## Module contracts

Constructor signatures and the methods `Plugin::boot()` and the other modules call. Add
private helpers freely; do not rename or re-type anything here without updating
`Plugin.php`, this file and the tests.

### Cache (`includes/Cache/`)

**`CacheStore`** — `__construct()`. Stores serialised REST responses. Backend: WP object
cache group `bfag_responses` when `wp_using_ext_object_cache()`; otherwise transients
`bfag_resp_<key>`. Generations make purging O(1) on both backends: an entry's key embeds the
global generation and the generation of every tag the matching rule carries, so bumping a
generation orphans the old entries (transients expire by TTL; object-cache entries by TTL).
- `get( string $key ): ?array` — `['status'=>int,'headers'=>array,'data'=>mixed,'stored_at'=>int]` or null
- `set( string $key, array $entry, int $ttl ): void` — on the transient backend the TTL is capped at `MAX_TRANSIENT_TTL` (3600 s: a purge only orphans a transient, its md5 key hides the generation, and core sweeps expired transients once a day — the cap bounds how long an orphan occupies two options rows) and an entry whose serialised size exceeds `MAX_TRANSIENT_BYTES` (65536) is not stored (logged once per request). No cap on the object cache
- `prune_expired(): int` — transient backend only: `SELECT option_name … LIKE '_transient_timeout_bfag_resp_%' AND option_value < now LIMIT PRUNE_BATCH` (5000), then one `DELETE … WHERE option_name IN (…)` of both rows of each pair, looping until a short slice (max `PRUNE_MAX_ITERATIONS` = 20). Returns pairs deleted. Run by `Cache\PruneCron`
- `delete( string $key ): void`
- `generation(): int` — option `bfag_cache_generation` (object-cached in group `bfag`, key `generation`)
- `flush_all(): void` — increments `bfag_cache_generation`, clears the `bfag_responses` group when supported
- `tag_generation( string $tag ): int` — read from option `bfag_tag_generations` (array `tag => int`, autoload false; default 1), through a per-request memo and object-cache group `bfag` key `tag_generations`. One option so uninstall can delete it by name: a transient per tag lived in an external object cache's `transient` group, which `uninstall.php` cannot enumerate
- `bump_tags( string[] $tags, ?string[] $keep = null ): void` — increments each entry, writes the option once, deletes the object-cache key and the memo. With `$keep` (the tags enabled rules carry, which `CachePurger` passes) every stored generation not in it is dropped, so the option holds at most rules × tags entries instead of one per object ever saved; a dropped tag restarts at 1, so the global generation is bumped too (`flush_all()`), and a rule that later carries that tag again can never meet an entry keyed on the old generation 1
- `CacheStore::sanitize_tag( string $tag ): string` — static; the one tag sanitiser (`[a-z0-9_:-]`, lower-cased, max 50). Every purge path (REST, AJAX, rule tags) uses it; `sanitize_key()` must not be used on a tag because it strips the `:` of `post:42`.

**`Cache\PruneCron`** — `__construct( CacheStore )`. `register_hooks()` adds `bfag_cron_prune_responses` → `run()`: `prune_expired()`, logged.

**`CacheKeyGenerator`** — `__construct( CacheStore )`.
- `for_request( \WP_REST_Request $request, string[] $tags = [] ): string` — `md5()` of
  method + route + ksorted query params + lowercase `Accept` header + global generation +
  each tag's generation. Params `_wpnonce`, `_locale` excluded.
- `CacheKeyGenerator::IMPLICIT_TAG` = `'all'`. **An empty `$tags` is keyed as `[ 'all' ]`**: a rule with no tags is purged on every content change (the Pro purge-tag answer always includes `all`); add tags to narrow purging to those tags. Tagged rules do NOT get `all` added — that is what makes them narrower. `purge_all()` still bumps the global generation (flushes everything, tagged or not).

**`CacheRuleRepository`** — `__construct()`. Rows are associative arrays with the table's
columns, `enabled`/`id`/`ttl_seconds` cast to int, `tags` as a comma-separated string.
- `all_enabled(): array` — object cache key `cache_rules_enabled`, ordered by id
- `all( int $limit = 200 ): array`, `find( int $id ): ?array`, `count(): int`
- `create( array $data ): int|false` — returns false (and logs) when `count() >= limit()` or the glob fails `GlobMatcher::is_valid`; the TTL is **clamped** to 1–86400 (never refused), and `update()` clamps it the same way; tags sanitised through `CacheStore::sanitize_tag()` (`[a-z0-9_:-]`, lower-cased, max 50 chars, max 20 tags; `post:7`, `type:product` are the Pro purge-tag shapes)
- `update( int $id, array $data ): bool`, `delete( int $id ): bool`, `set_enabled( int $id, bool ): bool`
- `limit(): int` — `(int) apply_filters( 'bfag_cache_rule_limit', 1 )`
- `match( string $route ): ?array` — among enabled rules whose glob matches (via `GlobMatcher`) the most specific one: highest `GlobMatcher::specificity()`, tie → lowest id; null when none. So a `wp/v2/posts/**` rule added after the seeded `**` rule wins for posts
- `sort_by_specificity( array $rules ): array` — the same order, for the Cache tab ("Most specific rule wins")
- `tags_of( array $rule ): string[]`

**`CacheStatsRepository`** — `__construct()`.
- `record_hit(): void`, `record_miss(): void` — `INSERT … ON DUPLICATE KEY UPDATE` into the current 5-minute bucket (`gmdate` floored to 300 s). With a persistent object cache (`true === wp_using_ext_object_cache()`) no query: `wp_cache_add` + `wp_cache_incr` on `counter_key( $bucket_start, 'hits'|'misses' )` = `stats:<bucket>:<kind>` in group `bfag`, TTL `COUNTER_TTL` (900 s)
- `flush_counters(): int` — persistent cache only: for the previous and the current bucket, read both counters, one `INSERT … ON DUPLICATE KEY UPDATE hits = hits + %d, misses = misses + %d` when either is non-zero, then `wp_cache_decr` each by what was written (hits counted during the flush survive). A failed upsert leaves the counters for the next run. `KpiCron::run()` calls it before aggregating, so with a persistent cache `totals_since()`/`hit_rate()` lag the live traffic by at most one cron interval
- `totals_since( string $mysql_gmt_datetime ): array{hits:int,misses:int}`
- `hit_rate( int $buckets = 12 ): ?float` — null when there are no requests
- `prune( int $keep_days = 7 ): int`

**`CacheManager`** — `__construct( CacheStore, CacheKeyGenerator, CacheRuleRepository, CacheStatsRepository, Settings )`.
- `register_hooks(): void` — `rest_pre_dispatch` (priority 10, 3 args) → `maybe_serve()`, `rest_post_dispatch` (10, 3 args) → `maybe_store()`
- No matching enabled rule → BYPASS (rules are the allow-list; the seeded `**` rule covers everything).
- `maybe_serve( mixed $result, \WP_REST_Server $server, \WP_REST_Request $request ): mixed` — returns a `\WP_REST_Response` rebuilt from the entry (status, headers, data) with header `X-BF-Cache: HIT`, else `$result` unchanged
- `maybe_store( \WP_HTTP_Response $response, \WP_REST_Server $server, \WP_REST_Request $request ): \WP_HTTP_Response` — a bare `WP_HTTP_Response` is returned untouched; a `WP_REST_Response` is stored when cacheable, adds `X-BF-Cache: MISS` (or `BYPASS` when not cacheable), adds any `headers` JSON from the rule
- `is_cacheable_request( \WP_REST_Request ): bool` — `cache_enabled` on; GET or HEAD; not logged in (`is_user_logged_in()`); no `Authorization`, `X-BF-API-Key`, `X-WP-Nonce` header; route not under `/bfag/v1` (compared lower-cased: core matches routes case-insensitively); `apply_filters( 'bfag_cache_bypass', false, $request )` false
- `is_cacheable_response( \WP_REST_Response ): bool` — status 200–299, `Content-Type` not `text/event-stream`, no `Set-Cookie`, no `Cache-Control` directive `no-store`, `private` or `no-cache`, and no `max-age=0` / `s-maxage=0` (directives are split on commas and compared by name)
- `ttl_for( ?array $rule ): int` — rule ttl or `default_cache_ttl`

**`CachePurger`** — `__construct( CacheStore, CacheRuleRepository )`.
- `register_hooks(): void` — `save_post` (10, 3), `delete_post`, `trashed_post`, `edited_term` (10, 3), `created_term` (10, 3), `delete_term` (10, 3), `transition_comment_status`, `wp_insert_comment`
- `on_post_change( int $post_id ): void` — skips revisions/autosaves; `$tags = apply_filters( 'bfag_cache_purge_tags', null, 'post', $post_id )`; array → `purge_tags()`, null → `purge_all()`. Same for `on_term_change( int $term_id, string $taxonomy )` with type `'term'` and for the comment hooks with type `'comment'`.
- `purge_all(): void` — `flush_all()` + `do_action( 'bfag_cache_purged', 'all', [] )`
- `purge_tags( string[] $tags ): void` — keeps only the tags some enabled rule carries plus `all` (every untagged rule is keyed on it); the rest are logged and NOT bumped (no key embeds their generation, so a bump would purge nothing and only grow `bfag_tag_generations`); then `bump_tags( $known_requested, $known )` + `do_action( 'bfag_cache_purged', 'tags', $bumped )`

### Rate limiting (`includes/RateLimit/`)

**`RateLimitRuleRepository`** — same shape as CacheRuleRepository. Object cache key
`rl_rules_enabled`. `limit()` = `apply_filters( 'bfag_rate_limit_rule_limit', 1 )`.
`subject_types(): string[]` = `apply_filters( 'bfag_rate_limit_subject_types', [ 'ip' ] )`.
`create()` rejects a subject_type not in `subject_types()`, max_requests 1–65535,
window_seconds 1–86400, penalty_seconds 0–2592000. `matching( string $route ): array` —
every enabled rule whose glob matches, ordered by id. `max_window(): int` — largest
window_seconds among enabled rules (default 60).

**`HitCounter`** — `__construct()`.
- `consume( string $subject, string $route, int $window, int $max, ?string $display_route = null ): bool` — `$route` is the **bucket key** (whatever is counted together; the middleware passes `RateLimitMiddleware::bucket( $rule_id )` = `rule:<id>`), hashed into `route_hash`; `$display_route` is what the `route` column shows (the real route, capped at 191 chars), the bucket key when null. INSERT the hit, then `SELECT COUNT(*) … WHERE subject = %s AND route_hash = %s AND hit_at > %s` (window start as GMT datetime); returns true when count > max. `route_hash()` = `substr( hash( 'sha256', $route ), 0, 16 )`, public static. **With a persistent object cache** the INSERT still happens (the admin's hit log and the Pro add-on's cross-route DDoS `count_subject()` read the table) but the COUNT is replaced by `count_in_window()`.
- `count_in_window( string $subject, string $route, int $window ): int` — `wp_cache_add( key, 0, 'bfag', window × 2 )` then `wp_cache_incr`; `window_key()` = `bfag:rl:<md5(subject|route)>:<floor(time/window)>`, public static. Fixed windows (a subject may get up to 2 × max across one boundary); a failed incr counts as 0 (fail open, like a failed INSERT).
- `count( string $subject, string $route, int $window ): int` — `$route` is the bucket key, as for `consume()`
- `prune( int $max_window ): int` — `DELETE … WHERE hit_at < %s LIMIT 10000` in a loop until 0 rows (max 50 iterations); returns rows deleted
- `total_rows(): int` — exact `COUNT(*)`; the KPI cron's once-per-five-minutes figure
- `approximate_rows(): int` — `SHOW TABLE STATUS LIKE` → `Rows` (the engine's estimate; 0 when there is no such table)
- `rotate_if_huge( int $threshold = 10000000 ): bool` — `TRUNCATE` when `approximate_rows()` exceeds threshold (log it); no second COUNT(*) every five minutes

**`RateLimitMiddleware`** — `__construct( RateLimitRuleRepository, HitCounter, Settings, RequestContext )`.
- `register_hooks(): void` — `rest_pre_dispatch` priority 5 (3 args) → `check()`
- `check( mixed $result, \WP_REST_Server $server, \WP_REST_Request $request ): mixed` — if `$result` already non-null, or `rate_limit_enabled` off: return `$result`. Also returned untouched (nothing counted) when the dispatch is not the top-level HTTP REST request: `WP_CLI`, `wp_doing_cron()`, or `wp_is_serving_rest_request()` false AND `REST_REQUEST` unset (an internal `rest_do_request()` from admin-ajax or the front end); when the client IP is empty; and — after the block filter, so a ban still wins — when `rate_limit_exempt_editors` is on and the user is logged in with `edit_posts`. The route is normalised once — `strtolower( RequestContext::normalize_route( $route ) )` — because `WP_REST_Server` matches routes case-insensitively; a route under `bfag/v1/status` (any case) returns `$result`. IP = `RequestContext::client_ip()`; if `apply_filters( 'bfag_rate_limit_is_blocked', false, $ip, $request )` → `blocked_response()`. For each rule in `matching( $route )`: `$subject = apply_filters( 'bfag_rate_limit_subject', $default, $rule['subject_type'], $request, $ip )` where `$default` is the IP for type `ip` and null otherwise; skip rule when subject is null/''; `consume( $subject, RateLimitMiddleware::bucket( $rule['id'] ), $window, $max, $route )` — **the budget is per subject and per rule**, not per path, so `/wp/v2/Posts` and `/wp/v2/posts` share one bucket and a crawl of 10,000 distinct URLs under a `**` rule has one budget; on breach `do_action( 'bfag_rate_limit_breached', $subject, $rule, $request, $ip )` and return `limited_response( $rule )`. **Limited marker:** a breach stores `limited_key( $subject, $rule_id )` = `limited:<md5(subject|rule:<id>)>` for `window_seconds` — `wp_cache_set` in group `bfag` with a persistent object cache, else transient `bfag_limited_<key>` — and the marker is checked BEFORE `consume()`, so a known-limited subject gets its 429 (and the breach action still fires per request) with no INSERT and no COUNT until the window ends. Without a persistent object cache the probe is one options-table read per matching rule per request.
- `RateLimitMiddleware::bucket( int $rule_id ): string` — `'rule:' . $rule_id` (`BUCKET_PREFIX`), public static: the key under which a rule's hits are counted.
- `RateLimitMiddleware::limited_key( string $subject, int $rule_id ): string` — public static, the marker key above.
- `limited_response( array $rule ): \WP_REST_Response` — status 429, `['code'=>'bfag_rate_limited','message'=>…,'data'=>['status'=>429]]`, headers `Retry-After: <window_seconds>`, `X-BF-RateLimit-Limit`, `X-BF-RateLimit-Window`
- `blocked_response(): \WP_REST_Response` — status 403, code `bfag_blocked`

**`PruneCron`** — `__construct( HitCounter, RateLimitRuleRepository )`. `register_hooks()` adds
`bfag_cron_prune_hits` → `run()`: `prune( max_window() )`, then `rotate_if_huge()` (the engine's row estimate, not a COUNT(*)).

### Webhooks (`includes/Webhook/`)

**`EventRegistry`** — `__construct()`.
- `all(): array<string, array{hook:string, label:string, accepted_args:int, group:string}>` — built-in events then `apply_filters( 'bfag_webhook_events', $events )`. Built-ins: `post.published` (`transition_post_status`, 3 args, only new=publish & old≠publish, post types from `get_post_types(['public'=>true])`), `post.updated` (`post_updated`, 3), `post.deleted` (`before_delete_post`, 2), `user.registered` (`user_register`, 2), `user.updated` (`profile_update`, 3), `comment.created` (`wp_insert_comment`, 2), `woocommerce.order.created` (`woocommerce_new_order`, 2), `woocommerce.order.status_changed` (`woocommerce_order_status_changed`, 4), `woocommerce.order.completed` (`woocommerce_order_status_completed`, 2), `woocommerce.product.updated` (`woocommerce_update_product`, 2). WooCommerce events only when `class_exists( 'WooCommerce' )`.
- `exists( string $event ): bool`, `label( string $event ): string`
- `payload( string $event, array $hook_args ): ?array` — normalised array (`['event'=>…, 'site'=>home_url(), 'occurred_at'=>gmdate('c'), 'object'=>[...]]`); null means "do not dispatch" (e.g. post not public). Passed through `apply_filters( 'bfag_webhook_payload', $payload, $event, $hook_args )`. Never include user passwords, hashes, emails of other users beyond the object's own author email, or `$_SERVER`.

**`WebhookRepository`** — `__construct()`. Rows: `id`, `label`, `url`, `event`, `secret`, `headers` (JSON string, decoded to array by `headers_of()`), `preset`, `enabled` (int), `max_retries` (int), `created_at`.
- `all( int $limit = 200 ): array`, `all_enabled(): array` (object cache key `webhooks_enabled`), `find( int ): ?array`, `for_event( string $event ): array` (enabled only), `count(): int`, `enabled_events(): string[]`
- `create( array $data ): int|false` — false when `count() >= limit()`; `url` must pass `wp_http_validate_url()` and be https unless `apply_filters( 'bfag_webhook_allow_http', false )`; `label` 1–200 chars; `headers` must be a JSON object of string values (or an array, encoded on save); `event` is a sanitised key (the admin page checks it exists in `EventRegistry` — the repository stays dumb). `secret` defaults to `bin2hex( random_bytes( 32 ) )` when empty.
- `update( int $id, array $data ): bool`, `delete( int $id ): bool` — deletes deliveries first, then the webhook, inside `START TRANSACTION`/`COMMIT` (prepared `query()`); `set_enabled( int, bool )`
- `limit(): int` — `(int) apply_filters( 'bfag_webhook_limit', 3 )`
- `headers_of( array $row ): array<string,string>`

**`DeliveryRepository`** — `__construct( Settings )`.
- `create( array $data ): int|false` — `payload` is JSON string; truncated to `max_payload_bytes` with `truncated = 1` when longer; `status` default `pending`; `direction` `out`/`in`; `next_retry_at` (GMT datetime string, else null) marks a row queued for the pending-flush cron
- `update( int $id, array $data ): bool`, `find( int ): ?array`
- `recent( int $limit = 50, array $filters = [] ): array` — filters `webhook_id`, `status`, `direction`; ONE literal statement (Plugin Check / `PreparedSQL`): each filter is a `( %d = 0 OR webhook_id = %d )`-style pair the optimiser folds when the value is the neutral one; `ORDER BY created_at DESC, id DESC` (index `idx_created_at`)
- `pending( int $limit ): array` — `status = 'pending' AND direction = 'out' AND next_retry_at IS NOT NULL AND next_retry_at <= now ORDER BY id ASC LIMIT` (an in-flight delivery is `pending` too, with no `next_retry_at`)
- `prune( int $retention_days ): int` — `DELETE … WHERE status IN ('success','failed') AND created_at < %s LIMIT 5000` loop, then the same for `status IN ('pending','retrying')` older than one day whatever the retention (a row the cron never picked up, or a send the process died in, must not live for ever)
- `stats_since( string $mysql_gmt_datetime ): array{success:int,failed:int,pending:int,retrying:int}`
- `consecutive_failures( int $webhook_id ): int` — count of most recent rows with status failed before a success
- `last_status( int $webhook_id ): ?string`
- `last_statuses( int[] $webhook_ids ): array<int, string>` — one grouped query (`WHERE id IN ( SELECT MAX(id) … WHERE webhook_id IN (…) AND direction = 'out' GROUP BY webhook_id )`); `WebhookPage::render()` uses it so the Webhooks tab is one deliveries query however many rows

**`WebhookDispatcher`** — `__construct( DeliveryRepository, Settings )`.
- `dispatch( array $webhook, string $event, array $payload, int $attempt = 1, ?int $delivery_id = null ): array{delivery_id:int, success:bool, http_status:?int}` — body = `wp_json_encode( $payload )`; headers: `Content-Type: application/json`, `User-Agent: BlueFairy-API-Gateway/<ver>`, `X-BF-Event`, `X-BF-Delivery` (id), `X-BF-Timestamp` (unix), `X-BF-Signature: sha256=<hmac>` over `timestamp . '.' . body` with the webhook secret, plus `sanitize_headers( headers_of( $webhook ) )`; `apply_filters( 'bfag_webhook_request_args', $args, $webhook, $event, $payload )`; `wp_remote_post` with `timeout` `WebhookDispatcher::TIMEOUT` (5 s: up to `EventBus::FLUSH_LIMIT` are sent in a row on shutdown), `redirection` 0, `reject_unsafe_urls` true. Creates the delivery row before sending (status `pending`), updates it after (`success`/`failed`, `http_status`, `response_body` truncated to 2000 chars, `delivered_at`, `attempt`). On non-2xx or `WP_Error`: status `failed`, `do_action( 'bfag_webhook_delivery_failed', $delivery_id, $webhook, $attempt, $http_status )`. On success `do_action( 'bfag_webhook_delivered', $delivery_id, $webhook )`.
- `redeliver( int $delivery_id, array $webhook ): array` — resend a stored delivery's payload with `attempt + 1`. Refused (`success` false, logged, nothing sent) when the row is missing, `truncated`, or its `direction` is not `out`: an inbound row was written by whoever called the receiving endpoint, and signing it with the site's secret would launder an unverified sender's payload
- `dispatch_pending( array $row ): array` — send a row `EventBus` queued past its flush limit: looks the webhook up by `webhook_id` (missing, disabled, or a `truncated` payload → row `failed`, nothing sent, logged), clears `next_retry_at` (handed out once), then sends the stored bytes as the row's own attempt (1), through the same `send()` as `redeliver()`
- `sanitize_headers( array $raw ): array<string,string>` — keep names matching `/^[A-Za-z0-9-]+$/`, drop (case-insensitively) `Host`, `Content-Length`, `Transfer-Encoding`, `Connection`, `Cookie`, `Authorization`, `Proxy-Authorization`, anything starting `X-Forwarded-`, and any value containing `\r` or `\n`; log each rejection; `Authorization` may be re-added by `apply_filters( 'bfag_webhook_auth_headers', [], $webhook )` (Pro presets)
- `sign( string $secret, int $timestamp, string $body ): string` — `hash_hmac( 'sha256', $timestamp . '.' . $body, $secret )`, public static

**`EventBus`** — `__construct( WebhookRepository, WebhookDispatcher, EventRegistry, DeliveryRepository )`. `FLUSH_LIMIT` = 20, `HOOK` = `bfag_cron_flush_pending`.
- `register_hooks(): void` — for each event in `enabled_events()` that `exists()`, `add_action( hook, closure, 10, accepted_args )`; also `add_action( 'shutdown', [ $this, 'flush' ], 1 )` and `add_action( HOOK, [ $this, 'flush_pending' ] )`
- `handle( string $event, array $hook_args ): void` — return if `Plugin::$dispatching`; `payload()` null → return; push `[ $event, $payload ]` onto `$pending` (dedupe by event + object id within the request). **F9:** under `WP_CLI` or `wp_doing_cron()` a queue that has grown past `FLUSH_LIMIT` is flushed at once, so a long import never holds every payload in memory; a web request always waits for shutdown
- `flush(): void` — nothing pending → return. Otherwise first `fastcgi_finish_request()` when it exists and `apply_filters( 'bfag_webhook_finish_response', 'cli' !== PHP_SAPI )` is true (the response is handed to the web server before any delivery); then set `Plugin::$dispatching = true`; for each pending item, for each webhook `for_event()`: the first `FLUSH_LIMIT` deliveries are `dispatch()`ed now, every further one is written through `DeliveryRepository::create()` as `pending` with `next_retry_at = now` (attempt 1, the encoded payload) and ONE `wp_schedule_single_event( time(), HOOK )` is scheduled (deduped on `wp_next_scheduled()`); finally `Plugin::$dispatching = false`, `$pending = []`
- `flush_pending(): void` — the cron handler: `DeliveryRepository::pending( FLUSH_LIMIT )` → `WebhookDispatcher::dispatch_pending()` each under the guard; when the batch was full, schedule the single event again for the rest. Queued rows depend on WP-Cron; one the cron never reaches is pruned after a day
- `pending(): array` (tests)
- `invalidate(): void` — `wp_cache_delete( 'webhooks_enabled', 'bfag' )` (WebhookRepository writes call this through the repository's own invalidation; EventBus exposes it for completeness)

**`PruneCron`** — `__construct( DeliveryRepository, Settings )`. `register_hooks()` adds
`bfag_cron_prune_deliveries` → `run()`: `prune( retention_days )`.

### Admin and REST (`includes/Admin/`, `includes/Rest/`, `admin/views/`, `assets/`)

**`Admin\AdminMenu`** — `__construct( Settings, CacheStore, CacheRuleRepository, CacheStatsRepository, RateLimitRuleRepository, HitCounter, WebhookRepository, DeliveryRepository, WebhookDispatcher, EventRegistry )`.
- `register_hooks(): void` — `admin_menu` → `register_menu()` (`add_options_page( 'API Gateway', 'API Gateway', Capabilities::MANAGE, 'bfag-gateway', [ $this, 'render' ] )`), `admin_init` → each page's `handle_actions()`, `admin_enqueue_scripts` → `Assets`, `wp_ajax_bfag_ajax_*` handlers, `admin_notices` → cron-disabled notice (when `DISABLE_WP_CRON` true and `disable_cron_notice` off), `render_install_error_notice()` (every screen while `bfag_install_error` exists) and `render_proxy_notice()` (on `settings_page_bfag-gateway`, `plugins`, `dashboard` and their `-network` twins when `proxy_notice_due()`: rate limiting on, `trusted_proxies` empty, a forwarded-address header on the request, not dismissed — user meta `bfag_proxy_notice_dismissed`, written by `ajax_dismiss_notice()`, nonce `bfag_ajax_dismiss_notice`). `handle_actions()` acts only when `$_GET['page']` is `bfag-gateway` and the user can manage — checked before the nonce, so a stray `bfag_action` elsewhere is ignored, not a `wp_die`
- `tabs(): array<string, array{label:string, page:object}>` — `dashboard`, `cache`, `ratelimit`, `webhooks`, `settings`, then `apply_filters( 'bfag_admin_tabs', $tabs )` (Pro adds `apikeys`)
- `render(): void` — tab nav + current tab's `render()`; `?page=bfag-gateway&tab=<id>`
- Each page class (`DashboardPage`, `CachePage`, `RateLimitPage`, `WebhookPage`, `SettingsPage`) has `render(): void`, `handle_actions(): void` (POST via `admin_post`-style `admin_init` handling with nonce + capability, redirect back with `?bfag_notice=<code>`), and views under `admin/views/<name>.php` that receive variables and escape everything.
- `WebhookPage` — the stored secret is never rendered. A secret the plugin generates (blank on create, or **Generate a new secret**) is parked in transient `bfag_new_secret_<user_id>` for `NEW_SECRET_TTL` (120 s), the save redirects with `webhook_secret_shown`, and the next render of the tab shows it once in a copy box and deletes the transient. `headers_to_lines()` renders only keys matching `[A-Za-z0-9-]+` (the dispatcher would send nothing else); `save()` carries every other key of the stored headers (the Pro add-on's `preset_config`, which holds credentials) through an edit unchanged, so a base-form edit never drops or exposes it. **Send test** redirects a failure with `bfag_http=<status|none>`, which `AdminMenu::render_notices()` folds into the `webhook_test_failed` text. The delivery log view shows each finished row's `http_status` and `response_body` (or `Transport error: <message>`) in a `<details>` row, escaped, 2,000 characters max.
- `SettingsPage` — field types `checkbox`, `textarea`, `number`, `email`, and `select` (with `options` `value => label`; `forwarded_header` is one).
- `ProUpsell` — a right-column panel on every tab listing Pro features, hidden when `apply_filters( 'bfag_is_pro', false )` is true.
- AJAX: `bfag_ajax_purge_cache` (nonce `bfag_ajax_purge_cache`; optional `tags[]`), `bfag_ajax_refresh_kpis`, `bfag_ajax_retry_webhook` (`delivery_id`), `bfag_ajax_toggle_rule` (`kind` cache|ratelimit|webhook, `id`, `enabled`), `bfag_ajax_dismiss_notice` (`notice`; records the per-user dismissal).
- `Assets` — enqueue `bfag-admin-css` (`assets/css/admin.css`) and `bfag-admin-js` (`assets/js/admin.js`, localised `bfagAdmin` with `ajaxUrl` and nonces) only when `get_current_screen()->id === 'settings_page_bfag-gateway'`.

**`Admin\KpiCron`** — `__construct( CacheStatsRepository, DeliveryRepository, HitCounter )`.
`register_hooks()` adds `bfag_cron_recompute_kpis` → `run()`; `compute(): array` =
`['cache_hit_rate'=>?float, 'cache_hits'=>int, 'cache_misses'=>int, 'webhook_success_rate'=>?float, 'webhook_failed'=>int, 'webhook_total'=>int, 'blocked_today'=>int (filter 'bfag_kpi_blocked_today', 0), 'hits_table_rows'=>int (the one exact COUNT(*) of the hits table, every five minutes), 'computed_at'=>int, 'webhook_deliveries'=>array{success,failed,pending,retrying}]`
passed through `apply_filters( 'bfag_dashboard_kpis', $kpis )`; `run()` first calls `CacheStatsRepository::flush_counters()`, then stores the result with
`set_transient( 'bfag_kpis', $kpis, 360 )`; `current(): ?array` reads the transient. `DashboardPage` takes its hits-table row count from here (a dash when cold), never a live COUNT(*) per render.

**`Rest\StatusController`** — `__construct( WebhookRepository )`.
`register_routes()`: `GET /bfag/v1/status`, permission `current_user_can( Capabilities::MANAGE )`;
returns version, `kpis` (from transient), `counts` (`webhooks` live but object-cached 60 s under `bfag`/`status_webhook_count`; `cache_24h` and `deliveries_24h` taken from the KPI transient — `null` until the cron has run; a monitor polling this route never runs an aggregate), `pro` (`apply_filters( 'bfag_is_pro', false )`).

**`Rest\CacheController`** — `__construct( CacheStore )`. `POST /bfag/v1/cache/purge`,
permission `current_user_can( Capabilities::MANAGE )`; body `tags` (optional; an array of
strings or one comma-separated string, each sanitised by `CacheStore::sanitize_tag()` so
`post:42` keeps its colon) → `bump_tags` else `flush_all`; response
`['purged'=>'all'|'tags', 'tags'=>[]]`.

## Extension seams (the Pro add-on's contract)

| Hook | Type | Default | Purpose |
|---|---|---|---|
| `bfag_loaded` | action | — | fired before subsystems bind; add-on attaches filters here |
| `bfag_booted` | action | — | fired after; `Plugin::service()` available |
| `bfag_is_pro` | filter | `false` | hides the upsell, reported by `/status` |
| `bfag_cache_rule_limit` | filter | `1` | max cache rules |
| `bfag_cache_purge_tags` | filter | `null` | `( null, 'post'|'term'|'comment', $id )` → string[] of tags to bump instead of flushing all. Include `all` in every answer: untagged rules are keyed on it, so a rule with no tags is purged on every content change and only tagged rules are narrower |
| `bfag_cache_bypass` | filter | `false` | `( false, $request )` |
| `bfag_cache_purged` | action | — | `( 'all'|'tags', string[] )` |
| `bfag_rate_limit_rule_limit` | filter | `1` | |
| `bfag_rate_limit_subject_types` | filter | `['ip']` | Pro adds `user`, `apikey` |
| `bfag_rate_limit_max_window` | filter | largest rule window | seconds of hits the prune cron must keep; Pro raises it to its DDoS window |
| `bfag_rate_limit_subject` | filter | ip or null | `( $default, $type, $request, $ip )` |
| `bfag_rate_limit_is_blocked` | filter | `false` | `( false, $ip, $request )` — Pro's ban list |
| `bfag_rate_limit_breached` | action | — | `( $subject, $rule, $request, $ip )` — Pro's DDoS mitigator |
| `bfag_webhook_limit` | filter | `3` | |
| `bfag_webhook_events` | filter | built-ins | add events |
| `bfag_webhook_payload` | filter | payload | `( $payload, $event, $hook_args )` |
| `bfag_webhook_request_args` | filter | args | `( $args, $webhook, $event, $payload )` — Pro presets rewrite URL/body/auth |
| `bfag_webhook_auth_headers` | filter | `[]` | `( [], $webhook )` |
| `bfag_webhook_allow_http` | filter | `false` | |
| `bfag_webhook_delivery_failed` | action | — | `( $delivery_id, $webhook, $attempt, $http_status )` — Pro's retry scheduler + alerts |
| `bfag_webhook_delivered` | action | — | `( $delivery_id, $webhook )` |
| `bfag_webhook_finish_response` | filter | `'cli' !== PHP_SAPI` | whether `EventBus::flush()` calls `fastcgi_finish_request()` before sending; a plugin that prints on `shutdown` after this one returns false |
| `bfag_admin_tabs` | filter | 5 tabs | add admin tabs |
| `bfag_dashboard_kpis` | filter | kpis | add KPI cards |
| `bfag_kpi_blocked_today` | filter | `0` | |
| `bfag_settings_fields` | action | — | `( Settings )` — render extra settings rows (a `<table class="form-table">` body) inside the Settings form |
| `bfag_settings_saved` | action | — | `( array $clean, array $raw )` — fired after the base Settings form saves, with the full `$_POST` as `$raw` |
| `bfag_ratelimit_page_after` | action | — | rendered below the rate-limit rules table (Pro's Blocked IPs panel) |
| `bfag_cache_page_after` | action | — | rendered below the cache rules table |
| `bfag_webhook_form_fields` | action | — | `( array $webhook )` — extra rows in the add/edit webhook form |
| `bfag_webhook_presets` | filter | `[]` | `slug => label`; when non-empty the base form shows a Preset `<select>` and stores `preset` |
| `bfag_webhook_sanitize` | filter | data | `( array $data, array $raw )` — add-on sanitises its own form fields before `create()`/`update()` |
| `bfag_admin_notices` | filter | codes | `( array $messages )` — `code => [ 'type', 'text' ]` for `?bfag_notice=<code>` |

Admin page conventions the add-on relies on: the settings page is `options-general.php?page=bfag-gateway&tab=<id>`;
form POSTs go to the same URL with `bfag_action=<action>` and `wp_nonce_field( 'bfag_<action>' )`;
`AdminMenu::handle_actions()` (on `admin_init`) dispatches `bfag_action` to the active tab's page object AND to
`do_action( 'bfag_admin_action_<action>' )` for actions the base does not know, after verifying the
nonce `bfag_<action>` and the capability. Tabs added through `bfag_admin_tabs` supply
`[ 'label' => string, 'page' => object with render(): void and handle_actions(): void ]`.

## Testing

`tests/bootstrap.php` provides stubs: options/transients/object cache in arrays, real hook
dispatch (`add_action`, `do_action`, `apply_filters`, `remove_action`, `has_action`), queued
HTTP (`bfag_test_queue_http( code, body, headers )`, `bfag_test_queue_http_error()`, calls in
`$GLOBALS['bfag_test_http_calls']`), recorded cron/mail, `WP_REST_Request`/`WP_REST_Response`,
`WP_Error`, `WP_Role`, `WP_User`, `WP_Post`, `WP_Site`, nonces (`wp_create_nonce('x')` = `nonce-x`),
`wp_die`/redirects/`wp_send_json_*` throw `BfagTestExit`, `bfag_test_grant( cap )`,
`bfag_test_set_time( ts )`, `bfag_test_set_screen( id )`. `$GLOBALS['wpdb']` is a
`Tests\FakeWpdb`: `queue( method, value )`, `always( method, value )`, `calls`,
`calls_to( method )`, `queries_matching( needle )`; `prepare()` does real substitution; `SHOW TABLES LIKE` is answered with the table name without touching the queue unless `missing_tables([...])` says otherwise. The stubs model core where a product test depends on it (pinned by `tests/Unit/HarnessTest.php`): `get_option()` returns scalars as strings; `wp_cache_set()` honours `$expire` against `bfag_test_now()`; `wp_clear_scheduled_hook()` matches `$args` (`wp_unschedule_hook()` clears all); `wp_http_validate_url()` refuses userinfo and ports other than 80/443/8080; multisite via `$GLOBALS['bfag_test_multisite']`/`bfag_test_sites`, with `switch_to_blog()` swapping options, cron, roles and `$wpdb->prefix` per site; `wp_doing_cron()`/`wp_is_serving_rest_request()` read `bfag_test_doing_cron`/`bfag_test_serving_rest` (default false/true); the bootstrap's autoloader is prepended so a worktree with a symlinked `vendor/` tests its own `includes/`.

`tests/stubs/cache.php` adds `wp_cache_decr()`; `tests/stubs/webhook.php` adds a recording `fastcgi_finish_request()` (`$GLOBALS['bfag_test_finished']`, not reset by `bfag_test_reset()`); `tests/stubs/ratelimit.php`'s `SqliteWpdb` answers `SHOW TABLE STATUS LIKE` for the hits table with the real row count. `tests/Unit/HotPathQueryCountTest.php` is the performance instrument: it boots the real middleware against the FakeWpdb and asserts the exact `$wpdb` call sequence per request (cold: no persistent object cache; warm: persistent cache with every key seeded) — extend it whenever a hot-path query is added or removed. Option and transient reads are array lookups there and do not appear in its trace.

Every test extends `BlueFairy\ApiGateway\Tests\TestCase`. Add module stubs under
`tests/stubs/<module>.php` (auto-included), never by editing `bootstrap.php`. A test must be
able to fail: for each behaviour, assert the positive AND the negative (a request that is
cached and one that is bypassed; a header that survives and one that is stripped).
