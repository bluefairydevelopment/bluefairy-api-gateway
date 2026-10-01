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
  `GlobMatcher::*`, `IpUtils::*` are static.
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
| `Settings` | `all(): array`, `get( string $key ): mixed`, `update( array ): array`, `sanitize( array ): array`, `refresh()`, `Settings::DEFAULTS`, `Settings::to_bool()`, `Settings::sanitize_cidr_list()` |
| `Capabilities` | `MANAGE`, `SCOPES`, `add_caps()`, `remove_caps()`, `current_user_can_manage()`, `required_scope( string $route ): string`, `scope_satisfied( string $permissions, string $required ): bool` |
| `Installer` | `TABLES`, `OPTIONS`, `CRON`, `activate()`, `deactivate()`, `maybe_upgrade()`, `schema( $prefix, $charset ): array`, `create_tables()`, `seed_default_rules()`, `schedule_cron()`, `cron_schedules( array ): array` (adds `bfag_five_minutes`) |
| `Logger` | `use_settings( ?Settings )`, `enabled()`, `log( string, array = [] )`, `lines()`, `clear()` |
| `Support\GlobMatcher` | `compile( string $glob ): string`, `matches( string $glob, string $route ): bool`, `is_valid( string $glob ): bool` — `*` = one segment, `**` = anything, `?` = one char; routes compared with the leading `/` stripped |
| `Support\RequestContext` | `__construct( Settings )`, `client_ip(): string`, `reset()`, `RequestContext::normalize_route( string ): string` |
| `Support\IpUtils` | `is_valid_ip`, `is_valid_cidr_or_ip`, `in_range( $ip, $cidr )`, `in_any_range( $ip, array $ranges )` |
| `Plugin` | `boot()`, `service( string $id ): ?object`, `set( string $id, object )`, `reset()`, `public static bool $dispatching` |

Settings keys: `cache_enabled`, `default_cache_ttl`, `rate_limit_enabled`, `trusted_proxies`,
`max_payload_bytes`, `retention_days`, `failure_threshold`, `notification_email`,
`debug_logging`, `disable_cron_notice`, `delete_data_on_uninstall`.

Tables (see `Installer::schema()` for columns): `bfag_cache_rules`, `bfag_rate_limit_rules`,
`bfag_rate_limit_hits` (route_hash CHAR(16) = first 16 hex chars of sha256(route), computed in
PHP), `bfag_cache_stats` (5-minute buckets), `bfag_webhooks`, `bfag_webhook_deliveries`.

Options: `bfag_settings`, `bfag_db_version`, `bfag_version`, `bfag_cache_generation`, `bfag_kpis`.

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
- `set( string $key, array $entry, int $ttl ): void`
- `delete( string $key ): void`
- `generation(): int` — option `bfag_cache_generation` (object-cached in group `bfag`, key `generation`)
- `flush_all(): void` — increments `bfag_cache_generation`, clears the `bfag_responses` group when supported
- `tag_generation( string $tag ): int` — transient/option `bfag_tag_gen_<sanitized tag>` (default 1)
- `bump_tags( string[] $tags ): void`

**`CacheKeyGenerator`** — `__construct( CacheStore )`.
- `for_request( \WP_REST_Request $request, string[] $tags = [] ): string` — `md5()` of
  method + route + ksorted query params + lowercase `Accept` header + global generation +
  each tag's generation. Params `_wpnonce`, `_locale` excluded.

**`CacheRuleRepository`** — `__construct()`. Rows are associative arrays with the table's
columns, `enabled`/`id`/`ttl_seconds` cast to int, `tags` as a comma-separated string.
- `all_enabled(): array` — object cache key `cache_rules_enabled`, ordered by id
- `all( int $limit = 200 ): array`, `find( int $id ): ?array`, `count(): int`
- `create( array $data ): int|false` — returns false (and logs) when `count() >= limit()`; validates glob with `GlobMatcher::is_valid`, ttl 1–86400, tags sanitised (`[a-z0-9_:-]`, max 20; `post:7`, `type:product` are the Pro purge-tag shapes)
- `update( int $id, array $data ): bool`, `delete( int $id ): bool`, `set_enabled( int $id, bool ): bool`
- `limit(): int` — `(int) apply_filters( 'bfag_cache_rule_limit', 1 )`
- `match( string $route ): ?array` — first enabled rule whose glob matches (via `GlobMatcher`), null when none
- `tags_of( array $rule ): string[]`

**`CacheStatsRepository`** — `__construct()`.
- `record_hit(): void`, `record_miss(): void` — `INSERT … ON DUPLICATE KEY UPDATE` into the current 5-minute bucket (`gmdate` floored to 300 s)
- `totals_since( string $mysql_gmt_datetime ): array{hits:int,misses:int}`
- `hit_rate( int $buckets = 12 ): ?float` — null when there are no requests
- `prune( int $keep_days = 7 ): int`

**`CacheManager`** — `__construct( CacheStore, CacheKeyGenerator, CacheRuleRepository, CacheStatsRepository, Settings )`.
- `register_hooks(): void` — `rest_pre_dispatch` (priority 10, 3 args) → `maybe_serve()`, `rest_post_dispatch` (10, 3 args) → `maybe_store()`
- No matching enabled rule → BYPASS (rules are the allow-list; the seeded `**` rule covers everything).
- `maybe_serve( mixed $result, \WP_REST_Server $server, \WP_REST_Request $request ): mixed` — returns a `\WP_REST_Response` rebuilt from the entry (status, headers, data) with header `X-BF-Cache: HIT`, else `$result` unchanged
- `maybe_store( \WP_HTTP_Response $response, \WP_REST_Server $server, \WP_REST_Request $request ): \WP_HTTP_Response` — a bare `WP_HTTP_Response` is returned untouched; a `WP_REST_Response` is stored when cacheable, adds `X-BF-Cache: MISS` (or `BYPASS` when not cacheable), adds any `headers` JSON from the rule
- `is_cacheable_request( \WP_REST_Request ): bool` — `cache_enabled` on; GET or HEAD; not logged in (`is_user_logged_in()`); no `Authorization`, `X-BF-API-Key`, `X-WP-Nonce` header; route not under `/bfag/v1`; `apply_filters( 'bfag_cache_bypass', false, $request )` false
- `is_cacheable_response( \WP_REST_Response ): bool` — status 200–299, `Content-Type` not `text/event-stream`, no `Set-Cookie`, `Cache-Control` not containing `no-store`/`private`
- `ttl_for( ?array $rule ): int` — rule ttl or `default_cache_ttl`

**`CachePurger`** — `__construct( CacheStore, CacheRuleRepository )`.
- `register_hooks(): void` — `save_post` (10, 3), `delete_post`, `trashed_post`, `edited_term` (10, 3), `created_term` (10, 3), `delete_term` (10, 3), `transition_comment_status`, `wp_insert_comment`
- `on_post_change( int $post_id ): void` — skips revisions/autosaves; `$tags = apply_filters( 'bfag_cache_purge_tags', null, 'post', $post_id )`; array → `purge_tags()`, null → `purge_all()`. Same for `on_term_change( int $term_id, string $taxonomy )` with type `'term'`.
- `purge_all(): void` — `flush_all()` + `do_action( 'bfag_cache_purged', 'all', [] )`
- `purge_tags( string[] $tags ): void` — `bump_tags()` + `do_action( 'bfag_cache_purged', 'tags', $tags )`

### Rate limiting (`includes/RateLimit/`)

**`RateLimitRuleRepository`** — same shape as CacheRuleRepository. Object cache key
`rl_rules_enabled`. `limit()` = `apply_filters( 'bfag_rate_limit_rule_limit', 1 )`.
`subject_types(): string[]` = `apply_filters( 'bfag_rate_limit_subject_types', [ 'ip' ] )`.
`create()` rejects a subject_type not in `subject_types()`, max_requests 1–65535,
window_seconds 1–86400, penalty_seconds 0–2592000. `matching( string $route ): array` —
every enabled rule whose glob matches, ordered by id. `max_window(): int` — largest
window_seconds among enabled rules (default 60).

**`HitCounter`** — `__construct()`.
- `consume( string $subject, string $route, int $window, int $max ): bool` — INSERT the hit, then `SELECT COUNT(*) … WHERE subject = %s AND route_hash = %s AND hit_at > %s` (window start as GMT datetime); returns true when count > max. `route_hash()` = `substr( hash( 'sha256', $route ), 0, 16 )`, public static.
- `count( string $subject, string $route, int $window ): int`
- `prune( int $max_window ): int` — `DELETE … WHERE hit_at < %s LIMIT 10000` in a loop until 0 rows (max 50 iterations); returns rows deleted
- `total_rows(): int`
- `rotate_if_huge( int $threshold = 10000000 ): bool` — `TRUNCATE` when `total_rows()` exceeds threshold (log it)

**`RateLimitMiddleware`** — `__construct( RateLimitRuleRepository, HitCounter, Settings, RequestContext )`.
- `register_hooks(): void` — `rest_pre_dispatch` priority 5 (3 args) → `check()`
- `check( mixed $result, \WP_REST_Server $server, \WP_REST_Request $request ): mixed` — if `$result` already non-null, or `rate_limit_enabled` off, or route under `/bfag/v1/status`: return `$result`. IP = `RequestContext::client_ip()`; if `apply_filters( 'bfag_rate_limit_is_blocked', false, $ip, $request )` → `blocked_response()`. For each matching rule: `$subject = apply_filters( 'bfag_rate_limit_subject', $default, $rule['subject_type'], $request, $ip )` where `$default` is the IP for type `ip` and null otherwise; skip rule when subject is null/''; `consume()`; on breach `do_action( 'bfag_rate_limit_breached', $subject, $rule, $request, $ip )` and return `limited_response( $rule )`.
- `limited_response( array $rule ): \WP_REST_Response` — status 429, `['code'=>'bfag_rate_limited','message'=>…,'data'=>['status'=>429]]`, headers `Retry-After: <window_seconds>`, `X-BF-RateLimit-Limit`, `X-BF-RateLimit-Window`
- `blocked_response(): \WP_REST_Response` — status 403, code `bfag_blocked`

**`PruneCron`** — `__construct( HitCounter, RateLimitRuleRepository )`. `register_hooks()` adds
`bfag_cron_prune_hits` → `run()`: `prune( max_window() )`, then `rotate_if_huge()`.

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
- `create( array $data ): int|false` — `payload` is JSON string; truncated to `max_payload_bytes` with `truncated = 1` when longer; `status` default `pending`; `direction` `out`/`in`
- `update( int $id, array $data ): bool`, `find( int ): ?array`
- `recent( int $limit = 50, array $filters = [] ): array` — filters `webhook_id`, `status`, `direction`
- `prune( int $retention_days ): int` — `DELETE … WHERE status IN ('success','failed') AND created_at < %s LIMIT 5000` loop
- `stats_since( string $mysql_gmt_datetime ): array{success:int,failed:int,pending:int,retrying:int}`
- `consecutive_failures( int $webhook_id ): int` — count of most recent rows with status failed before a success
- `last_status( int $webhook_id ): ?string`

**`WebhookDispatcher`** — `__construct( DeliveryRepository, Settings )`.
- `dispatch( array $webhook, string $event, array $payload, int $attempt = 1, ?int $delivery_id = null ): array{delivery_id:int, success:bool, http_status:?int}` — body = `wp_json_encode( $payload )`; headers: `Content-Type: application/json`, `User-Agent: BlueFairy-API-Gateway/<ver>`, `X-BF-Event`, `X-BF-Delivery` (id), `X-BF-Timestamp` (unix), `X-BF-Signature: sha256=<hmac>` over `timestamp . '.' . body` with the webhook secret, plus `sanitize_headers( headers_of( $webhook ) )`; `apply_filters( 'bfag_webhook_request_args', $args, $webhook, $event, $payload )`; `wp_remote_post` with `timeout` 10, `redirection` 0, `reject_unsafe_urls` true. Creates the delivery row before sending (status `pending`), updates it after (`success`/`failed`, `http_status`, `response_body` truncated to 2000 chars, `delivered_at`, `attempt`). On non-2xx or `WP_Error`: status `failed`, `do_action( 'bfag_webhook_delivery_failed', $delivery_id, $webhook, $attempt, $http_status )`. On success `do_action( 'bfag_webhook_delivered', $delivery_id, $webhook )`.
- `redeliver( int $delivery_id, array $webhook ): array` — resend a stored delivery's payload with `attempt + 1`
- `sanitize_headers( array $raw ): array<string,string>` — keep names matching `/^[A-Za-z0-9-]+$/`, drop (case-insensitively) `Host`, `Content-Length`, `Transfer-Encoding`, `Connection`, `Cookie`, `Authorization`, `Proxy-Authorization`, anything starting `X-Forwarded-`, and any value containing `\r` or `\n`; log each rejection; `Authorization` may be re-added by `apply_filters( 'bfag_webhook_auth_headers', [], $webhook )` (Pro presets)
- `sign( string $secret, int $timestamp, string $body ): string` — `hash_hmac( 'sha256', $timestamp . '.' . $body, $secret )`, public static

**`EventBus`** — `__construct( WebhookRepository, WebhookDispatcher, EventRegistry )`.
- `register_hooks(): void` — for each event in `enabled_events()` that `exists()`, `add_action( hook, closure, 10, accepted_args )`; also `add_action( 'shutdown', [ $this, 'flush' ], 1 )`
- `handle( string $event, array $hook_args ): void` — return if `Plugin::$dispatching`; `payload()` null → return; push `[ $event, $payload ]` onto `$pending` (dedupe by event + object id within the request)
- `flush(): void` — set `Plugin::$dispatching = true`; for each pending item, for each webhook `for_event()`: `dispatch()`; finally `Plugin::$dispatching = false`, `$pending = []`
- `pending(): array` (tests)
- `invalidate(): void` — `wp_cache_delete( 'webhooks_enabled', 'bfag' )` (WebhookRepository writes call this through the repository's own invalidation; EventBus exposes it for completeness)

**`PruneCron`** — `__construct( DeliveryRepository, Settings )`. `register_hooks()` adds
`bfag_cron_prune_deliveries` → `run()`: `prune( retention_days )`.

### Admin and REST (`includes/Admin/`, `includes/Rest/`, `admin/views/`, `assets/`)

**`Admin\AdminMenu`** — `__construct( Settings, CacheStore, CacheRuleRepository, CacheStatsRepository, RateLimitRuleRepository, HitCounter, WebhookRepository, DeliveryRepository, WebhookDispatcher, EventRegistry )`.
- `register_hooks(): void` — `admin_menu` → `register_menu()` (`add_options_page( 'API Gateway', 'API Gateway', Capabilities::MANAGE, 'bfag-gateway', [ $this, 'render' ] )`), `admin_init` → each page's `handle_actions()`, `admin_enqueue_scripts` → `Assets`, `wp_ajax_bfag_ajax_*` handlers, `admin_notices` → cron-disabled notice (when `DISABLE_WP_CRON` true and `disable_cron_notice` off)
- `tabs(): array<string, array{label:string, page:object}>` — `dashboard`, `cache`, `ratelimit`, `webhooks`, `settings`, then `apply_filters( 'bfag_admin_tabs', $tabs )` (Pro adds `apikeys`)
- `render(): void` — tab nav + current tab's `render()`; `?page=bfag-gateway&tab=<id>`
- Each page class (`DashboardPage`, `CachePage`, `RateLimitPage`, `WebhookPage`, `SettingsPage`) has `render(): void`, `handle_actions(): void` (POST via `admin_post`-style `admin_init` handling with nonce + capability, redirect back with `?bfag_notice=<code>`), and views under `admin/views/<name>.php` that receive variables and escape everything.
- `ProUpsell` — a right-column panel on every tab listing Pro features, hidden when `apply_filters( 'bfag_is_pro', false )` is true.
- AJAX: `bfag_ajax_purge_cache` (nonce `bfag_ajax_purge_cache`; optional `tags[]`), `bfag_ajax_refresh_kpis`, `bfag_ajax_retry_webhook` (`delivery_id`), `bfag_ajax_toggle_rule` (`kind` cache|ratelimit|webhook, `id`, `enabled`).
- `Assets` — enqueue `bfag-admin-css` (`assets/css/admin.css`) and `bfag-admin-js` (`assets/js/admin.js`, localised `bfagAdmin` with `ajaxUrl` and nonces) only when `get_current_screen()->id === 'settings_page_bfag-gateway'`.

**`Admin\KpiCron`** — `__construct( CacheStatsRepository, DeliveryRepository, HitCounter )`.
`register_hooks()` adds `bfag_cron_recompute_kpis` → `run()`; `compute(): array` =
`['cache_hit_rate'=>?float, 'cache_hits'=>int, 'cache_misses'=>int, 'webhook_success_rate'=>?float, 'webhook_failed'=>int, 'webhook_total'=>int, 'blocked_today'=>int (filter 'bfag_kpi_blocked_today', 0), 'hits_table_rows'=>int, 'computed_at'=>int]`
passed through `apply_filters( 'bfag_dashboard_kpis', $kpis )`; `run()` stores it with
`set_transient( 'bfag_kpis', $kpis, 360 )`; `current(): ?array` reads the transient.

**`Rest\StatusController`** — `__construct( CacheStatsRepository, DeliveryRepository, WebhookRepository )`.
`register_routes()`: `GET /bfag/v1/status`, permission `current_user_can( Capabilities::MANAGE )`;
returns version, `kpis` (from transient), counts, `pro` (`apply_filters( 'bfag_is_pro', false )`).

**`Rest\CacheController`** — `__construct( CacheStore )`. `POST /bfag/v1/cache/purge`,
permission `current_user_can( Capabilities::MANAGE )`; body `tags` (array, optional) →
`bump_tags` else `flush_all`; response `['purged'=>'all'|'tags', 'tags'=>[]]`.

## Extension seams (the Pro add-on's contract)

| Hook | Type | Default | Purpose |
|---|---|---|---|
| `bfag_loaded` | action | — | fired before subsystems bind; add-on attaches filters here |
| `bfag_booted` | action | — | fired after; `Plugin::service()` available |
| `bfag_is_pro` | filter | `false` | hides the upsell, reported by `/status` |
| `bfag_cache_rule_limit` | filter | `1` | max cache rules |
| `bfag_cache_purge_tags` | filter | `null` | `( null, 'post'|'term', $id )` → string[] of tags to bump instead of flushing all |
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
`WP_Error`, `WP_Role`, `WP_User`, `WP_Post`, nonces (`wp_create_nonce('x')` = `nonce-x`),
`wp_die`/redirects/`wp_send_json_*` throw `BfagTestExit`, `bfag_test_grant( cap )`,
`bfag_test_set_time( ts )`, `bfag_test_set_screen( id )`. `$GLOBALS['wpdb']` is a
`Tests\FakeWpdb`: `queue( method, value )`, `always( method, value )`, `calls`,
`calls_to( method )`, `queries_matching( needle )`; `prepare()` does real substitution.

Every test extends `BlueFairy\ApiGateway\Tests\TestCase`. Add module stubs under
`tests/stubs/<module>.php` (auto-included), never by editing `bootstrap.php`. A test must be
able to fail: for each behaviour, assert the positive AND the negative (a request that is
cached and one that is bypassed; a header that survives and one that is stripped).
