<?php
/**
 * PHPUnit bootstrap: minimal WordPress stubs, no database, no WordPress loaded.
 *
 * Unit tests run against this file alone. The stubs are deliberately small
 * and recorded: hooks really dispatch, options and transients live in arrays,
 * HTTP calls are queued and captured, and $wpdb is a FakeWpdb that records
 * every query and answers from a scripted queue (tests/FakeWpdb.php).
 *
 * Module-specific stubs go in tests/stubs/<module>.php — every file there is
 * included after this one — so parallel work on different modules never edits
 * the same file.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, WordPress.NamingConventions.ValidFunctionName, WordPress.WP.AlternativeFunctions, Squiz.Commenting, Generic.Files.OneObjectStructurePerFile, Universal.Files.SeparateFunctionsFromOO

$bfag_autoload = dirname( __DIR__ ) . '/vendor/autoload.php';
if ( file_exists( $bfag_autoload ) ) {
	require_once $bfag_autoload;
}
spl_autoload_register(
	static function ( string $class ): void {
		$map = [
			'BlueFairy\\ApiGateway\\Tests\\' => __DIR__ . '/',
			'BlueFairy\\ApiGateway\\'        => dirname( __DIR__ ) . '/includes/',
		];
		foreach ( $map as $prefix => $dir ) {
			if ( str_starts_with( $class, $prefix ) ) {
				$file = $dir . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
				if ( is_readable( $file ) ) {
					require $file;
				}
				return;
			}
		}
	}
);

// ------------------------------------------------------------------
// Constants.
// ------------------------------------------------------------------
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/fixtures/abspath/' );
}
if ( ! defined( 'BFAG_VERSION' ) ) {
	define( 'BFAG_VERSION', '1.0.0' );
}
if ( ! defined( 'BFAG_DB_VERSION' ) ) {
	define( 'BFAG_DB_VERSION', '1.0.0' );
}
if ( ! defined( 'BFAG_FILE' ) ) {
	define( 'BFAG_FILE', dirname( __DIR__ ) . '/bluefairy-api-gateway.php' );
}
if ( ! defined( 'BFAG_DIR' ) ) {
	define( 'BFAG_DIR', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'BFAG_URL' ) ) {
	define( 'BFAG_URL', 'https://example.com/wp-content/plugins/bluefairy-api-gateway/' );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'DAY_IN_SECONDS', 86400 );
	define( 'WEEK_IN_SECONDS', 604800 );
}
if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
	define( 'ARRAY_A', 'ARRAY_A' );
	define( 'ARRAY_N', 'ARRAY_N' );
}

// ------------------------------------------------------------------
// Test state. bfag_test_reset() returns everything to a known state and is
// called from every TestCase::setUp() via BlueFairy\ApiGateway\Tests\TestCase.
// ------------------------------------------------------------------
function bfag_test_reset(): void {
	$GLOBALS['bfag_test_options']       = [];
	$GLOBALS['bfag_test_transients']    = [];
	$GLOBALS['bfag_test_cache']         = [];
	$GLOBALS['bfag_test_hooks']         = [];
	$GLOBALS['bfag_test_http_queue']    = [];
	$GLOBALS['bfag_test_http_calls']    = [];
	$GLOBALS['bfag_test_mail']          = [];
	$GLOBALS['bfag_test_cron']          = [];
	$GLOBALS['bfag_test_single_events'] = [];
	$GLOBALS['bfag_test_user_id']       = 0;
	$GLOBALS['bfag_test_caps']          = [];
	$GLOBALS['bfag_test_ext_cache']     = false;
	$GLOBALS['bfag_test_is_admin']      = false;
	$GLOBALS['bfag_test_nonce_valid']   = true;
	$GLOBALS['bfag_test_time']          = null;
	$GLOBALS['bfag_test_roles']         = [ 'administrator' => new WP_Role( 'administrator', [] ) ];
	$GLOBALS['bfag_test_posts']         = [];
	$GLOBALS['bfag_test_db_delta']      = [];
	$GLOBALS['bfag_test_admin_pages']   = [];
	$GLOBALS['bfag_test_enqueued']      = [];
	$GLOBALS['bfag_test_output']        = [];
	$GLOBALS['bfag_test_screen']        = null;
	$GLOBALS['wpdb']                    = new BlueFairy\ApiGateway\Tests\FakeWpdb();
	$_SERVER['REMOTE_ADDR']             = '203.0.113.10';
	$_SERVER['REQUEST_METHOD']          = 'GET';
	unset( $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_X_REAL_IP'] );
	$_POST    = [];
	$_GET     = [];
	$_REQUEST = [];
	BlueFairy\ApiGateway\Plugin::reset();
	BlueFairy\ApiGateway\Logger::use_settings( null );
	BlueFairy\ApiGateway\Logger::clear();
}

// ------------------------------------------------------------------
// Options, transients, object cache.
// ------------------------------------------------------------------
function get_option( string $option, mixed $default_value = false ): mixed {
	return $GLOBALS['bfag_test_options'][ $option ] ?? $default_value;
}
function update_option( string $option, mixed $value, bool|string|null $autoload = null ): bool {
	$GLOBALS['bfag_test_options'][ $option ] = $value;
	return true;
}
function add_option( string $option, mixed $value = '', string $deprecated = '', bool|string $autoload = 'yes' ): bool {
	if ( array_key_exists( $option, $GLOBALS['bfag_test_options'] ) ) {
		return false;
	}
	$GLOBALS['bfag_test_options'][ $option ] = $value;
	return true;
}
function delete_option( string $option ): bool {
	$existed = array_key_exists( $option, $GLOBALS['bfag_test_options'] );
	unset( $GLOBALS['bfag_test_options'][ $option ] );
	return $existed;
}
function get_transient( string $transient ): mixed {
	$row = $GLOBALS['bfag_test_transients'][ $transient ] ?? null;
	if ( null === $row ) {
		return false;
	}
	if ( 0 !== $row['expires'] && $row['expires'] < bfag_test_now() ) {
		unset( $GLOBALS['bfag_test_transients'][ $transient ] );
		return false;
	}
	return $row['value'];
}
function set_transient( string $transient, mixed $value, int $expiration = 0 ): bool {
	$GLOBALS['bfag_test_transients'][ $transient ] = [
		'value'   => $value,
		'expires' => $expiration > 0 ? bfag_test_now() + $expiration : 0,
	];
	return true;
}
function delete_transient( string $transient ): bool {
	$existed = isset( $GLOBALS['bfag_test_transients'][ $transient ] );
	unset( $GLOBALS['bfag_test_transients'][ $transient ] );
	return $existed;
}
function wp_cache_get( int|string $key, string $group = '', bool $force = false, mixed &$found = null ): mixed {
	$found = isset( $GLOBALS['bfag_test_cache'][ $group ][ $key ] );
	return $GLOBALS['bfag_test_cache'][ $group ][ $key ] ?? false;
}
function wp_cache_set( int|string $key, mixed $data, string $group = '', int $expire = 0 ): bool {
	$GLOBALS['bfag_test_cache'][ $group ][ $key ] = $data;
	return true;
}
function wp_cache_add( int|string $key, mixed $data, string $group = '', int $expire = 0 ): bool {
	if ( isset( $GLOBALS['bfag_test_cache'][ $group ][ $key ] ) ) {
		return false;
	}
	return wp_cache_set( $key, $data, $group, $expire );
}
function wp_cache_delete( int|string $key, string $group = '' ): bool {
	$existed = isset( $GLOBALS['bfag_test_cache'][ $group ][ $key ] );
	unset( $GLOBALS['bfag_test_cache'][ $group ][ $key ] );
	return $existed;
}
function wp_cache_incr( int|string $key, int $offset = 1, string $group = '' ): int|false {
	if ( ! isset( $GLOBALS['bfag_test_cache'][ $group ][ $key ] ) ) {
		return false;
	}
	$GLOBALS['bfag_test_cache'][ $group ][ $key ] = (int) $GLOBALS['bfag_test_cache'][ $group ][ $key ] + $offset;
	return $GLOBALS['bfag_test_cache'][ $group ][ $key ];
}
function wp_cache_flush_group( string $group ): bool {
	unset( $GLOBALS['bfag_test_cache'][ $group ] );
	return true;
}
function wp_cache_flush(): bool {
	$GLOBALS['bfag_test_cache'] = [];
	return true;
}
function wp_cache_supports( string $feature ): bool {
	return 'flush_group' === $feature;
}
function wp_using_ext_object_cache( ?bool $using = null ): bool {
	if ( null !== $using ) {
		$GLOBALS['bfag_test_ext_cache'] = $using;
	}
	return (bool) $GLOBALS['bfag_test_ext_cache'];
}

// ------------------------------------------------------------------
// Hooks — a real, minimal dispatcher so EventBus and the purger can be tested
// by firing the hooks they subscribe to.
// ------------------------------------------------------------------
function add_filter( string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1 ): true {
	$GLOBALS['bfag_test_hooks'][ $hook_name ][ $priority ][] = [ $callback, $accepted_args ];
	return true;
}
function add_action( string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1 ): true {
	return add_filter( $hook_name, $callback, $priority, $accepted_args );
}
function remove_filter( string $hook_name, callable $callback, int $priority = 10 ): bool {
	if ( empty( $GLOBALS['bfag_test_hooks'][ $hook_name ][ $priority ] ) ) {
		return false;
	}
	$before = count( $GLOBALS['bfag_test_hooks'][ $hook_name ][ $priority ] );
	$GLOBALS['bfag_test_hooks'][ $hook_name ][ $priority ] = array_values(
		array_filter(
			$GLOBALS['bfag_test_hooks'][ $hook_name ][ $priority ],
			static fn( array $entry ): bool => $entry[0] != $callback // phpcs:ignore Universal.Operators.StrictComparisons -- closures compare by identity, arrays by value, as WP does.
		)
	);
	return count( $GLOBALS['bfag_test_hooks'][ $hook_name ][ $priority ] ) < $before;
}
function remove_action( string $hook_name, callable $callback, int $priority = 10 ): bool {
	return remove_filter( $hook_name, $callback, $priority );
}
function has_filter( string $hook_name, callable|false $callback = false ): bool|int {
	if ( empty( $GLOBALS['bfag_test_hooks'][ $hook_name ] ) ) {
		return false;
	}
	if ( false === $callback ) {
		return true;
	}
	foreach ( $GLOBALS['bfag_test_hooks'][ $hook_name ] as $priority => $entries ) {
		foreach ( $entries as $entry ) {
			if ( $entry[0] == $callback ) { // phpcs:ignore Universal.Operators.StrictComparisons
				return $priority;
			}
		}
	}
	return false;
}
function has_action( string $hook_name, callable|false $callback = false ): bool|int {
	return has_filter( $hook_name, $callback );
}
function apply_filters( string $hook_name, mixed $value, mixed ...$args ): mixed {
	$GLOBALS['bfag_test_fired'][ $hook_name ] = ( $GLOBALS['bfag_test_fired'][ $hook_name ] ?? 0 ) + 1;
	if ( empty( $GLOBALS['bfag_test_hooks'][ $hook_name ] ) ) {
		return $value;
	}
	$levels = $GLOBALS['bfag_test_hooks'][ $hook_name ];
	ksort( $levels );
	foreach ( $levels as $entries ) {
		foreach ( $entries as [ $callback, $accepted ] ) {
			$params = array_slice( array_merge( [ $value ], $args ), 0, max( 0, $accepted ) );
			$value  = $callback( ...$params );
		}
	}
	return $value;
}
function do_action( string $hook_name, mixed ...$args ): void {
	$GLOBALS['bfag_test_fired'][ $hook_name ] = ( $GLOBALS['bfag_test_fired'][ $hook_name ] ?? 0 ) + 1;
	if ( empty( $GLOBALS['bfag_test_hooks'][ $hook_name ] ) ) {
		return;
	}
	$levels = $GLOBALS['bfag_test_hooks'][ $hook_name ];
	ksort( $levels );
	foreach ( $levels as $entries ) {
		foreach ( $entries as [ $callback, $accepted ] ) {
			$callback( ...array_slice( $args, 0, max( 0, $accepted ) ) );
		}
	}
}
function did_action( string $hook_name ): int {
	return (int) ( $GLOBALS['bfag_test_fired'][ $hook_name ] ?? 0 );
}
function doing_action( ?string $hook_name = null ): bool {
	return false;
}

// ------------------------------------------------------------------
// Sanitising and escaping.
// ------------------------------------------------------------------
function sanitize_text_field( mixed $str ): string {
	$str = is_scalar( $str ) ? (string) $str : '';
	$str = strip_tags( $str );
	$str = preg_replace( '/[\r\n\t ]+/', ' ', $str ) ?? '';
	return trim( $str );
}
function sanitize_textarea_field( mixed $str ): string {
	return trim( strip_tags( is_scalar( $str ) ? (string) $str : '' ) );
}
function sanitize_key( mixed $key ): string {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( is_scalar( $key ) ? (string) $key : '' ) ) ?? '';
}
function sanitize_email( mixed $email ): string {
	return (string) filter_var( is_scalar( $email ) ? (string) $email : '', FILTER_SANITIZE_EMAIL );
}
function is_email( mixed $email ): string|false {
	return is_string( $email ) && false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false;
}
function sanitize_title( mixed $title ): string {
	return trim( preg_replace( '/[^a-z0-9\-]+/', '-', strtolower( is_scalar( $title ) ? (string) $title : '' ) ) ?? '', '-' );
}
function esc_url_raw( mixed $url, ?array $protocols = null ): string {
	$url = is_scalar( $url ) ? trim( (string) $url ) : '';
	if ( '' === $url ) {
		return '';
	}
	$allowed = $protocols ?? [ 'http', 'https' ];
	$scheme  = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );
	if ( '' !== $scheme && ! in_array( $scheme, $allowed, true ) ) {
		return '';
	}
	return $url;
}
function sanitize_url( mixed $url, ?array $protocols = null ): string {
	return esc_url_raw( $url, $protocols );
}
function esc_url( mixed $url, ?array $protocols = null ): string {
	return htmlspecialchars( esc_url_raw( $url, $protocols ), ENT_QUOTES, 'UTF-8' );
}
function esc_html( mixed $text ): string {
	return htmlspecialchars( is_scalar( $text ) ? (string) $text : '', ENT_QUOTES, 'UTF-8' );
}
function esc_attr( mixed $text ): string {
	return esc_html( $text );
}
function esc_js( mixed $text ): string {
	return addslashes( is_scalar( $text ) ? (string) $text : '' );
}
function esc_textarea( mixed $text ): string {
	return esc_html( $text );
}
function wp_kses_post( mixed $data ): string {
	return is_scalar( $data ) ? (string) $data : '';
}
function wp_kses( mixed $data, mixed $allowed ): string {
	return strip_tags( is_scalar( $data ) ? (string) $data : '' );
}
function __( string $text, string $domain = 'default' ): string {
	return $text;
}
function _x( string $text, string $context, string $domain = 'default' ): string {
	return $text;
}
function _n( string $single, string $plural, int $number, string $domain = 'default' ): string {
	return 1 === $number ? $single : $plural;
}
function esc_html__( string $text, string $domain = 'default' ): string {
	return esc_html( $text );
}
function esc_attr__( string $text, string $domain = 'default' ): string {
	return esc_attr( $text );
}
function esc_html_x( string $text, string $context, string $domain = 'default' ): string {
	return esc_html( $text );
}
function esc_attr_x( string $text, string $context, string $domain = 'default' ): string {
	return esc_attr( $text );
}
function _e( string $text, string $domain = 'default' ): void {
	bfag_test_echo( $text );
}
function esc_html_e( string $text, string $domain = 'default' ): void {
	bfag_test_echo( esc_html( $text ) );
}
function esc_attr_e( string $text, string $domain = 'default' ): void {
	bfag_test_echo( esc_attr( $text ) );
}
function bfag_test_echo( string $text ): void {
	$GLOBALS['bfag_test_output'][] = $text;
	echo $text; // phpcs:ignore WordPress.Security.EscapeOutput
}
function absint( mixed $maybeint ): int {
	return abs( (int) $maybeint );
}
function wp_unslash( mixed $value ): mixed {
	if ( is_array( $value ) ) {
		return array_map( 'wp_unslash', $value );
	}
	return is_string( $value ) ? stripslashes( $value ) : $value;
}
function wp_json_encode( mixed $data, int $options = 0, int $depth = 512 ): string|false {
	return json_encode( $data, $options, $depth );
}
function wp_parse_args( mixed $args, array $defaults = [] ): array {
	if ( is_object( $args ) ) {
		$args = get_object_vars( $args );
	}
	return array_merge( $defaults, is_array( $args ) ? $args : [] );
}
function wp_parse_url( string $url, int $component = -1 ): mixed {
	return parse_url( $url, $component );
}
function add_query_arg( mixed ...$args ): string {
	$url = is_string( $args[ count( $args ) - 1 ] ) ? array_pop( $args ) : '';
	$add = is_array( $args[0] ?? null ) ? $args[0] : ( isset( $args[1] ) ? [ $args[0] => $args[1] ] : [] );
	$sep = str_contains( $url, '?' ) ? '&' : '?';
	return $url . ( [] === $add ? '' : $sep . http_build_query( $add ) );
}
function remove_query_arg( mixed $key, string $url = '' ): string {
	return $url;
}
function wp_generate_password( int $length = 12, bool $special_chars = true, bool $extra_special_chars = false ): string {
	return substr( bin2hex( random_bytes( (int) ceil( $length / 2 ) ) ), 0, $length );
}
function wp_rand( int $min = 0, int $max = 0 ): int {
	return random_int( $min, 0 === $max ? PHP_INT_MAX : $max );
}
function wp_salt( string $scheme = 'auth' ): string {
	return 'test-salt-' . $scheme;
}
function wp_hash( string $data, string $scheme = 'auth' ): string {
	return hash_hmac( 'md5', $data, wp_salt( $scheme ) );
}
function size_format( int|string $bytes, int $decimals = 0 ): string|false {
	return $bytes . ' B';
}
function number_format_i18n( float|int $number, int $decimals = 0 ): string {
	return number_format( (float) $number, $decimals );
}
function wp_strip_all_tags( string $text ): string {
	return trim( strip_tags( $text ) );
}
function wp_normalize_path( string $path ): string {
	return str_replace( '\\', '/', $path );
}
function trailingslashit( string $value ): string {
	return rtrim( $value, '/\\' ) . '/';
}
function untrailingslashit( string $value ): string {
	return rtrim( $value, '/\\' );
}

// ------------------------------------------------------------------
// Time. Tests can freeze the clock with bfag_test_set_time().
// ------------------------------------------------------------------
function bfag_test_now(): int {
	return $GLOBALS['bfag_test_time'] ?? time();
}
function bfag_test_set_time( ?int $timestamp ): void {
	$GLOBALS['bfag_test_time'] = $timestamp;
}
function current_time( string $type, int|bool $gmt = 0 ): int|string {
	$now = bfag_test_now();
	if ( 'timestamp' === $type || 'U' === $type ) {
		return $now;
	}
	if ( 'mysql' === $type ) {
		return gmdate( 'Y-m-d H:i:s', $now );
	}
	return gmdate( $type, $now );
}
function wp_date( string $format, ?int $timestamp = null, mixed $timezone = null ): string|false {
	return gmdate( $format, $timestamp ?? bfag_test_now() );
}
function date_i18n( string $format, int|bool $timestamp = false, bool $gmt = false ): string {
	return gmdate( $format, false === $timestamp ? bfag_test_now() : (int) $timestamp );
}
function human_time_diff( int $from, int $to = 0 ): string {
	return abs( ( 0 === $to ? bfag_test_now() : $to ) - $from ) . ' secs';
}
function get_date_from_gmt( string $date, string $format = 'Y-m-d H:i:s' ): string {
	return gmdate( $format, (int) strtotime( $date ) );
}
function mysql2date( string $format, string $date, bool $translate = true ): string|int|false {
	return gmdate( $format, (int) strtotime( $date ) );
}

// ------------------------------------------------------------------
// Users, capabilities, roles.
// ------------------------------------------------------------------
class WP_Role {
	/** @var array<string, bool> */
	public array $capabilities = [];
	public function __construct( public string $name, array $capabilities ) {
		$this->capabilities = $capabilities;
	}
	public function add_cap( string $cap, bool $grant = true ): void {
		$this->capabilities[ $cap ] = $grant;
	}
	public function remove_cap( string $cap ): void {
		unset( $this->capabilities[ $cap ] );
	}
	public function has_cap( string $cap ): bool {
		return ! empty( $this->capabilities[ $cap ] );
	}
}
class WP_User {
	public int $ID       = 0;
	public string $user_login = '';
	public string $user_email = '';
	public string $display_name = '';
	/** @var list<string> */
	public array $roles = [];
	public function __construct( int $id = 0 ) {
		$this->ID = $id;
	}
	public function exists(): bool {
		return $this->ID > 0;
	}
	public function has_cap( string $cap ): bool {
		return current_user_can( $cap );
	}
}
function get_role( string $role ): ?WP_Role {
	return $GLOBALS['bfag_test_roles'][ $role ] ?? null;
}
function current_user_can( string $capability, mixed ...$args ): bool {
	return ! empty( $GLOBALS['bfag_test_caps'][ $capability ] );
}
function user_can( mixed $user, string $capability, mixed ...$args ): bool {
	return current_user_can( $capability );
}
function bfag_test_grant( string ...$caps ): void {
	foreach ( $caps as $cap ) {
		$GLOBALS['bfag_test_caps'][ $cap ] = true;
	}
}
function get_current_user_id(): int {
	return (int) $GLOBALS['bfag_test_user_id'];
}
function is_user_logged_in(): bool {
	return get_current_user_id() > 0;
}
function wp_get_current_user(): WP_User {
	return new WP_User( get_current_user_id() );
}
function wp_set_current_user( ?int $id, string $name = '' ): WP_User {
	$GLOBALS['bfag_test_user_id'] = (int) $id;
	return new WP_User( (int) $id );
}
function get_user_by( string $field, mixed $value ): WP_User|false {
	return 'id' === $field && (int) $value > 0 ? new WP_User( (int) $value ) : false;
}
function get_userdata( int $user_id ): WP_User|false {
	return $user_id > 0 ? new WP_User( $user_id ) : false;
}
function is_admin(): bool {
	return (bool) $GLOBALS['bfag_test_is_admin'];
}
function is_multisite(): bool {
	return false;
}
function switch_to_blog( int $new_blog_id ): bool {
	return true;
}
function restore_current_blog(): bool {
	return true;
}
function get_sites( array $args = [] ): array {
	return [];
}
function wp_doing_ajax(): bool {
	return false;
}
function wp_doing_cron(): bool {
	return false;
}
function wp_is_json_request(): bool {
	return false;
}

// ------------------------------------------------------------------
// Nonces and redirects. Redirects, wp_die and wp_send_json* throw so a
// handler stops exactly where WordPress would have exited.
// ------------------------------------------------------------------
class BfagTestExit extends RuntimeException {
	public function __construct( public string $kind, public mixed $payload = null, public int $status = 0 ) {
		parent::__construct( $kind );
	}
}
function wp_create_nonce( string|int $action = -1 ): string {
	return 'nonce-' . $action;
}
function wp_verify_nonce( mixed $nonce, string|int $action = -1 ): int|false {
	return $GLOBALS['bfag_test_nonce_valid'] && 'nonce-' . $action === $nonce ? 1 : false;
}
function check_admin_referer( string|int $action = -1, string $query_arg = '_wpnonce' ): int|false {
	$nonce  = $_REQUEST[ $query_arg ] ?? '';
	$result = wp_verify_nonce( $nonce, $action );
	if ( false === $result ) {
		wp_die( 'The link you followed has expired.', '', [ 'response' => 403 ] );
	}
	return $result;
}
function check_ajax_referer( string|int $action = -1, string|false $query_arg = false, bool $stop = true ): int|false {
	$nonce  = $_REQUEST[ $query_arg ?: '_ajax_nonce' ] ?? ( $_REQUEST['_wpnonce'] ?? '' );
	$result = wp_verify_nonce( $nonce, $action );
	if ( false === $result && $stop ) {
		wp_die( '-1', '', [ 'response' => 403 ] );
	}
	return $result;
}
function wp_nonce_field( string|int $action = -1, string $name = '_wpnonce', bool $referer = true, bool $display = true ): string {
	$field = '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( wp_create_nonce( $action ) ) . '" />';
	if ( $display ) {
		bfag_test_echo( $field );
	}
	return $field;
}
function wp_nonce_url( string $actionurl, string|int $action = -1, string $name = '_wpnonce' ): string {
	return add_query_arg( $name, wp_create_nonce( $action ), $actionurl );
}
function wp_die( mixed $message = '', mixed $title = '', mixed $args = [] ): never {
	throw new BfagTestExit( 'die', $message, is_array( $args ) ? (int) ( $args['response'] ?? 500 ) : 500 );
}
function wp_safe_redirect( string $location, int $status = 302, string $x_redirect_by = 'WordPress' ): never {
	throw new BfagTestExit( 'redirect', $location, $status );
}
function wp_redirect( string $location, int $status = 302, string $x_redirect_by = 'WordPress' ): never {
	throw new BfagTestExit( 'redirect', $location, $status );
}
function wp_send_json_success( mixed $value = null, ?int $status_code = null ): never {
	throw new BfagTestExit( 'json_success', $value, $status_code ?? 200 );
}
function wp_send_json_error( mixed $value = null, ?int $status_code = null ): never {
	throw new BfagTestExit( 'json_error', $value, $status_code ?? 200 );
}
function wp_send_json( mixed $response, ?int $status_code = null ): never {
	throw new BfagTestExit( 'json', $response, $status_code ?? 200 );
}
function admin_url( string $path = '', string $scheme = 'admin' ): string {
	return 'https://example.com/wp-admin/' . ltrim( $path, '/' );
}
function home_url( string $path = '', ?string $scheme = null ): string {
	return 'https://example.com' . ( '' === $path ? '' : '/' . ltrim( $path, '/' ) );
}
function site_url( string $path = '', ?string $scheme = null ): string {
	return home_url( $path, $scheme );
}
function rest_url( string $path = '', string $scheme = 'rest' ): string {
	return 'https://example.com/wp-json/' . ltrim( $path, '/' );
}
function get_rest_url( ?int $blog_id = null, string $path = '/', string $scheme = 'rest' ): string {
	return rest_url( $path );
}
function plugin_basename( string $file ): string {
	return 'bluefairy-api-gateway/' . basename( $file );
}
function plugins_url( string $path = '', string $plugin = '' ): string {
	return BFAG_URL . ltrim( $path, '/' );
}
function deactivate_plugins( mixed $plugins, bool $silent = false, ?bool $network_wide = null ): void {}
function is_plugin_active( string $plugin ): bool {
	return false;
}
function get_bloginfo( string $show = '', string $filter = 'raw' ): string {
	return match ( $show ) {
		'name'        => 'Test Site',
		'admin_email' => 'admin@example.com',
		'version'     => '7.1',
		'url'         => 'https://example.com',
		default       => '',
	};
}
function get_locale(): string {
	return 'en_US';
}
function wp_get_environment_type(): string {
	return 'production';
}

// ------------------------------------------------------------------
// HTTP: queued responses, recorded calls.
// ------------------------------------------------------------------
class WP_Error {
	/** @var array<string, list<string>> */
	public array $errors = [];
	/** @var array<string, mixed> */
	public array $error_data = [];
	public function __construct( string|int $code = '', string $message = '', mixed $data = '' ) {
		if ( '' !== $code ) {
			$this->add( $code, $message, $data );
		}
	}
	public function add( string|int $code, string $message, mixed $data = '' ): void {
		$this->errors[ $code ][] = $message;
		if ( '' !== $data ) {
			$this->error_data[ $code ] = $data;
		}
	}
	public function get_error_code(): string|int {
		return array_key_first( $this->errors ) ?? '';
	}
	/** @return list<string|int> */
	public function get_error_codes(): array {
		return array_keys( $this->errors );
	}
	public function get_error_message( string|int $code = '' ): string {
		$code = '' === $code ? $this->get_error_code() : $code;
		return $this->errors[ $code ][0] ?? '';
	}
	public function get_error_data( string|int $code = '' ): mixed {
		$code = '' === $code ? $this->get_error_code() : $code;
		return $this->error_data[ $code ] ?? null;
	}
	public function has_errors(): bool {
		return [] !== $this->errors;
	}
}
function is_wp_error( mixed $thing ): bool {
	return $thing instanceof WP_Error;
}
/**
 * Queue a response for the next HTTP call. Pass a WP_Error to simulate a
 * transport failure.
 *
 * @param array<string, string> $headers Response headers.
 */
function bfag_test_queue_http( int $code = 200, string $body = '', array $headers = [] ): void {
	$GLOBALS['bfag_test_http_queue'][] = [
		'response' => [
			'code'    => $code,
			'message' => 'OK',
		],
		'body'     => $body,
		'headers'  => $headers,
	];
}
function bfag_test_queue_http_error( string $code = 'http_request_failed', string $message = 'Connection refused' ): void {
	$GLOBALS['bfag_test_http_queue'][] = new WP_Error( $code, $message );
}
function wp_remote_request( string $url, array $args = [] ): array|WP_Error {
	$GLOBALS['bfag_test_http_calls'][] = [
		'url'  => $url,
		'args' => $args,
	];
	if ( [] === $GLOBALS['bfag_test_http_queue'] ) {
		return [
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
			'body'     => '',
			'headers'  => [],
		];
	}
	return array_shift( $GLOBALS['bfag_test_http_queue'] );
}
function wp_remote_post( string $url, array $args = [] ): array|WP_Error {
	$args['method'] = 'POST';
	return wp_remote_request( $url, $args );
}
function wp_remote_get( string $url, array $args = [] ): array|WP_Error {
	$args['method'] = 'GET';
	return wp_remote_request( $url, $args );
}
function wp_safe_remote_post( string $url, array $args = [] ): array|WP_Error {
	return wp_remote_post( $url, $args );
}
function wp_safe_remote_get( string $url, array $args = [] ): array|WP_Error {
	return wp_remote_get( $url, $args );
}
function wp_remote_retrieve_response_code( mixed $response ): int|string {
	return is_array( $response ) ? (int) ( $response['response']['code'] ?? 0 ) : '';
}
function wp_remote_retrieve_body( mixed $response ): string {
	return is_array( $response ) ? (string) ( $response['body'] ?? '' ) : '';
}
function wp_remote_retrieve_headers( mixed $response ): array {
	return is_array( $response ) ? (array) ( $response['headers'] ?? [] ) : [];
}
function wp_remote_retrieve_header( mixed $response, string $header ): string {
	$headers = array_change_key_case( wp_remote_retrieve_headers( $response ), CASE_LOWER );
	return (string) ( $headers[ strtolower( $header ) ] ?? '' );
}
function wp_http_validate_url( string $url ): string|false {
	$parts = parse_url( $url );
	if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ?? '' ), [ 'http', 'https' ], true ) ) {
		return false;
	}
	$host = strtolower( $parts['host'] );
	if ( 'localhost' === $host || str_ends_with( $host, '.local' ) ) {
		return false;
	}
	if ( filter_var( $host, FILTER_VALIDATE_IP ) && ! filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
		return false;
	}
	return $url;
}

// ------------------------------------------------------------------
// Mail and cron, recorded.
// ------------------------------------------------------------------
function wp_mail( mixed $to, string $subject, string $message, mixed $headers = '', mixed $attachments = [] ): bool {
	$GLOBALS['bfag_test_mail'][] = compact( 'to', 'subject', 'message', 'headers' );
	return true;
}
function wp_schedule_event( int $timestamp, string $recurrence, string $hook, array $args = [], bool $wp_error = false ): bool {
	$GLOBALS['bfag_test_cron'][ $hook ] = compact( 'timestamp', 'recurrence', 'args' );
	return true;
}
function wp_schedule_single_event( int $timestamp, string $hook, array $args = [], bool $wp_error = false ): bool {
	$GLOBALS['bfag_test_single_events'][] = compact( 'timestamp', 'hook', 'args' );
	return true;
}
function wp_next_scheduled( string $hook, array $args = [] ): int|false {
	if ( isset( $GLOBALS['bfag_test_cron'][ $hook ] ) ) {
		return (int) $GLOBALS['bfag_test_cron'][ $hook ]['timestamp'];
	}
	foreach ( $GLOBALS['bfag_test_single_events'] as $event ) {
		if ( $event['hook'] === $hook && $event['args'] === $args ) {
			return (int) $event['timestamp'];
		}
	}
	return false;
}
function wp_clear_scheduled_hook( string $hook, array $args = [], bool $wp_error = false ): int|false {
	$count = isset( $GLOBALS['bfag_test_cron'][ $hook ] ) ? 1 : 0;
	unset( $GLOBALS['bfag_test_cron'][ $hook ] );
	$before                             = count( $GLOBALS['bfag_test_single_events'] );
	$GLOBALS['bfag_test_single_events'] = array_values( array_filter( $GLOBALS['bfag_test_single_events'], static fn( array $e ): bool => $e['hook'] !== $hook ) );
	return $count + ( $before - count( $GLOBALS['bfag_test_single_events'] ) );
}
function wp_unschedule_event( int $timestamp, string $hook, array $args = [], bool $wp_error = false ): bool {
	$GLOBALS['bfag_test_single_events'] = array_values( array_filter( $GLOBALS['bfag_test_single_events'], static fn( array $e ): bool => ! ( $e['hook'] === $hook && $e['timestamp'] === $timestamp ) ) );
	return true;
}
function wp_get_schedules(): array {
	return apply_filters( 'cron_schedules', [ 'daily' => [ 'interval' => DAY_IN_SECONDS, 'display' => 'Daily' ] ] );
}

// ------------------------------------------------------------------
// Posts (minimal), dbDelta, admin registration — recorded.
// ------------------------------------------------------------------
class WP_Post {
	public int $ID             = 0;
	public string $post_type   = 'post';
	public string $post_status = 'publish';
	public string $post_title  = '';
	public string $post_name   = '';
	public int $post_author    = 0;
	public string $post_date_gmt = '';
	public string $post_modified_gmt = '';
	public string $post_content = '';
	public function __construct( array $data = [] ) {
		foreach ( $data as $key => $value ) {
			$this->$key = $value;
		}
	}
}
function bfag_test_add_post( array $data ): WP_Post {
	$post                                       = new WP_Post( $data );
	$GLOBALS['bfag_test_posts'][ $post->ID ]    = $post;
	return $post;
}
function get_post( mixed $post = null, string $output = OBJECT ): ?WP_Post {
	if ( $post instanceof WP_Post ) {
		return $post;
	}
	return $GLOBALS['bfag_test_posts'][ (int) $post ] ?? null;
}
function get_post_type( mixed $post = null ): string|false {
	$p = get_post( $post );
	return $p ? $p->post_type : false;
}
function get_post_status( mixed $post = null ): string|false {
	$p = get_post( $post );
	return $p ? $p->post_status : false;
}
function get_permalink( mixed $post = 0 ): string|false {
	$p = get_post( $post );
	return $p ? home_url( '/?p=' . $p->ID ) : false;
}
function wp_is_post_revision( mixed $post ): int|false {
	return false;
}
function wp_is_post_autosave( mixed $post ): int|false {
	return false;
}
function get_post_types( array $args = [], string $output = 'names' ): array {
	return [ 'post' => 'post', 'page' => 'page' ];
}
function dbDelta( mixed $queries = '', bool $execute = true ): array { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
	$GLOBALS['bfag_test_db_delta'][] = $queries;
	return [];
}
function add_menu_page( string $page_title, string $menu_title, string $capability, string $menu_slug, mixed $callback = '', string $icon_url = '', ?int $position = null ): string {
	$GLOBALS['bfag_test_admin_pages'][] = compact( 'page_title', 'menu_title', 'capability', 'menu_slug', 'callback' );
	return 'toplevel_page_' . $menu_slug;
}
function add_submenu_page( string $parent_slug, string $page_title, string $menu_title, string $capability, string $menu_slug, mixed $callback = '', ?int $position = null ): string|false {
	$GLOBALS['bfag_test_admin_pages'][] = compact( 'parent_slug', 'page_title', 'menu_title', 'capability', 'menu_slug', 'callback' );
	return 'settings_page_' . $menu_slug;
}
function add_options_page( string $page_title, string $menu_title, string $capability, string $menu_slug, mixed $callback = '', ?int $position = null ): string|false {
	return add_submenu_page( 'options-general.php', $page_title, $menu_title, $capability, $menu_slug, $callback, $position );
}
function wp_enqueue_style( string $handle, string $src = '', array $deps = [], mixed $ver = false, string $media = 'all' ): void {
	$GLOBALS['bfag_test_enqueued']['style'][] = $handle;
}
function wp_enqueue_script( string $handle, string $src = '', array $deps = [], mixed $ver = false, mixed $args = [] ): void {
	$GLOBALS['bfag_test_enqueued']['script'][] = $handle;
}
function wp_register_script( string $handle, mixed $src, array $deps = [], mixed $ver = false, mixed $args = [] ): bool {
	return true;
}
function wp_register_style( string $handle, mixed $src, array $deps = [], mixed $ver = false, string $media = 'all' ): bool {
	return true;
}
function wp_localize_script( string $handle, string $object_name, array $l10n ): bool {
	$GLOBALS['bfag_test_enqueued']['localize'][ $handle ][ $object_name ] = $l10n;
	return true;
}
function wp_add_inline_script( string $handle, string $data, string $position = 'after' ): bool {
	return true;
}
function wp_script_is( string $handle, string $status = 'enqueued' ): bool {
	return in_array( $handle, $GLOBALS['bfag_test_enqueued']['script'] ?? [], true );
}
function get_current_screen(): ?object {
	return $GLOBALS['bfag_test_screen'];
}
function bfag_test_set_screen( ?string $id ): void {
	$GLOBALS['bfag_test_screen'] = null === $id ? null : (object) [ 'id' => $id, 'base' => $id ];
}
function register_setting( string $option_group, string $option_name, array $args = [] ): void {}
function add_settings_error( string $setting, string $code, string $message, string $type = 'error' ): void {
	$GLOBALS['bfag_test_settings_errors'][] = compact( 'setting', 'code', 'message', 'type' );
}
function get_settings_errors( string $setting = '', bool $sanitize = false ): array {
	return $GLOBALS['bfag_test_settings_errors'] ?? [];
}
function settings_errors( string $setting = '', bool $sanitize = false, bool $hide_on_update = false ): void {}
function submit_button( ?string $text = null, string $type = 'primary', string $name = 'submit', bool $wrap = true, mixed $other_attributes = null ): void {
	bfag_test_echo( '<button type="submit" name="' . esc_attr( $name ) . '">' . esc_html( $text ?? 'Save Changes' ) . '</button>' );
}
function selected( mixed $selected, mixed $current = true, bool $display = true ): string {
	$out = (string) $selected === (string) $current ? ' selected="selected"' : '';
	if ( $display ) {
		bfag_test_echo( $out );
	}
	return $out;
}
function checked( mixed $checked, mixed $current = true, bool $display = true ): string {
	$out = (string) $checked === (string) $current ? ' checked="checked"' : '';
	if ( $display ) {
		bfag_test_echo( $out );
	}
	return $out;
}
function disabled( mixed $disabled, mixed $current = true, bool $display = true ): string {
	$out = (string) $disabled === (string) $current ? ' disabled="disabled"' : '';
	if ( $display ) {
		bfag_test_echo( $out );
	}
	return $out;
}
function wp_kses_allowed_html( string $context = 'basic' ): array {
	return [];
}
function get_admin_page_title(): string {
	return 'BlueFairy API Gateway';
}
function get_plugin_data( string $plugin_file, bool $markup = true, bool $translate = true ): array {
	return [ 'Version' => BFAG_VERSION, 'Name' => 'BlueFairy API Gateway' ];
}
function load_plugin_textdomain( string $domain, mixed $deprecated = false, mixed $plugin_rel_path = false ): bool {
	return true;
}

// ------------------------------------------------------------------
// REST classes: enough of WP_REST_Request / WP_REST_Response for the cache
// and rate-limit layers, which only read route, method, params and headers.
// ------------------------------------------------------------------
class WP_REST_Server {
	public const READABLE  = 'GET';
	public const CREATABLE = 'POST';
	public const EDITABLE  = 'POST, PUT, PATCH';
	public const DELETABLE = 'DELETE';
	public const ALLMETHODS = 'GET, POST, PUT, PATCH, DELETE';
}
class WP_HTTP_Response {
	/** @var array<string, string> */
	protected array $headers = [];
	public function __construct( protected mixed $data = null, protected int $status = 200, array $headers = [] ) {
		$this->headers = $headers;
	}
	public function get_data(): mixed {
		return $this->data;
	}
	public function set_data( mixed $data ): void {
		$this->data = $data;
	}
	public function get_status(): int {
		return $this->status;
	}
	public function set_status( int $code ): void {
		$this->status = $code;
	}
	/** @return array<string, string> */
	public function get_headers(): array {
		return $this->headers;
	}
	public function set_headers( array $headers ): void {
		$this->headers = $headers;
	}
	public function header( string $key, string $value, bool $replace = true ): void {
		if ( $replace || ! isset( $this->headers[ $key ] ) ) {
			$this->headers[ $key ] = $value;
		} else {
			$this->headers[ $key ] .= ', ' . $value;
		}
	}
	public function remove_header( string $key ): void {
		unset( $this->headers[ $key ] );
	}
}
class WP_REST_Response extends WP_HTTP_Response {
	/** @var array<string, mixed> */
	public array $links = [];
	public function as_error(): ?WP_Error {
		return $this->status >= 400 && is_array( $this->data ) && isset( $this->data['code'] ) ? new WP_Error( $this->data['code'], $this->data['message'] ?? '' ) : null;
	}
	public function is_error(): bool {
		return $this->status >= 400;
	}
}
class WP_REST_Request implements ArrayAccess {
	/** @var array<string, string> */
	private array $headers = [];
	/** @var array<string, mixed> */
	private array $params = [];
	/** @var array<string, mixed> */
	private array $query = [];
	/** @var array<string, mixed> */
	private array $body_params = [];
	/** @var array<string, mixed> */
	private array $url_params = [];
	private string $body = '';
	/** @var array<string, mixed> */
	private array $attributes = [];
	public function __construct( private string $method = 'GET', private string $route = '', array $attributes = [] ) {
		$this->attributes = $attributes;
	}
	public function get_method(): string {
		return $this->method;
	}
	public function set_method( string $method ): void {
		$this->method = $method;
	}
	public function get_route(): string {
		return $this->route;
	}
	public function set_route( string $route ): void {
		$this->route = $route;
	}
	public function get_header( string $key ): ?string {
		$key = strtolower( str_replace( '-', '_', $key ) );
		return $this->headers[ $key ] ?? null;
	}
	/** @return array<string, string> */
	public function get_headers(): array {
		return $this->headers;
	}
	public function set_header( string $key, string $value ): void {
		$this->headers[ strtolower( str_replace( '-', '_', $key ) ) ] = $value;
	}
	public function remove_header( string $key ): void {
		unset( $this->headers[ strtolower( str_replace( '-', '_', $key ) ) ] );
	}
	public function get_param( string $key ): mixed {
		return $this->params[ $key ] ?? $this->body_params[ $key ] ?? $this->query[ $key ] ?? $this->url_params[ $key ] ?? null;
	}
	public function set_param( string $key, mixed $value ): void {
		$this->params[ $key ] = $value;
	}
	public function has_param( string $key ): bool {
		return null !== $this->get_param( $key );
	}
	/** @return array<string, mixed> */
	public function get_params(): array {
		return array_merge( $this->url_params, $this->query, $this->body_params, $this->params );
	}
	/** @return array<string, mixed> */
	public function get_query_params(): array {
		return $this->query;
	}
	public function set_query_params( array $params ): void {
		$this->query = $params;
	}
	/** @return array<string, mixed> */
	public function get_body_params(): array {
		return $this->body_params;
	}
	public function set_body_params( array $params ): void {
		$this->body_params = $params;
	}
	/** @return array<string, mixed> */
	public function get_url_params(): array {
		return $this->url_params;
	}
	public function set_url_params( array $params ): void {
		$this->url_params = $params;
	}
	/** @return array<string, mixed> */
	public function get_json_params(): array {
		$decoded = json_decode( $this->body, true );
		return is_array( $decoded ) ? $decoded : [];
	}
	public function get_body(): string {
		return $this->body;
	}
	public function set_body( string $body ): void {
		$this->body = $body;
	}
	/** @return array<string, mixed> */
	public function get_attributes(): array {
		return $this->attributes;
	}
	public function set_attributes( array $attributes ): void {
		$this->attributes = $attributes;
	}
	public function get_content_type(): ?array {
		$value = $this->get_header( 'content_type' );
		if ( null === $value ) {
			return null;
		}
		[ $type ] = array_pad( explode( ';', $value, 2 ), 2, '' );
		[ $t, $s ] = array_pad( explode( '/', trim( $type ), 2 ), 2, '' );
		return [ 'value' => trim( $type ), 'type' => $t, 'subtype' => $s, 'parameters' => '' ];
	}
	public function offsetExists( mixed $offset ): bool {
		return $this->has_param( (string) $offset );
	}
	public function offsetGet( mixed $offset ): mixed {
		return $this->get_param( (string) $offset );
	}
	public function offsetSet( mixed $offset, mixed $value ): void {
		$this->set_param( (string) $offset, $value );
	}
	public function offsetUnset( mixed $offset ): void {
		unset( $this->params[ (string) $offset ] );
	}
}
function rest_ensure_response( mixed $response ): WP_REST_Response|WP_Error {
	if ( $response instanceof WP_Error || $response instanceof WP_REST_Response ) {
		return $response;
	}
	return new WP_REST_Response( $response );
}
function register_rest_route( string $route_namespace, string $route, array $args = [], bool $override = false ): bool {
	$GLOBALS['bfag_test_rest_routes'][ '/' . trim( $route_namespace, '/' ) . '/' . ltrim( $route, '/' ) ] = $args;
	return true;
}
function rest_get_server(): object {
	return new class() {
		public function get_routes(): array {
			return $GLOBALS['bfag_test_rest_routes'] ?? [];
		}
	};
}
function rest_authorization_required_code(): int {
	return is_user_logged_in() ? 403 : 401;
}
function rest_sanitize_boolean( mixed $value ): bool {
	return BlueFairy\ApiGateway\Settings::to_bool( $value );
}

// ------------------------------------------------------------------
// Module-specific stubs and the shared TestCase.
// ------------------------------------------------------------------
require_once __DIR__ . '/FakeWpdb.php';
require_once __DIR__ . '/TestCase.php';
foreach ( glob( __DIR__ . '/stubs/*.php' ) ?: [] as $bfag_stub_file ) {
	require_once $bfag_stub_file;
}

bfag_test_reset();
