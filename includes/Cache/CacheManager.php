<?php
/**
 * Serve and store REST responses around the dispatcher.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Cache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use BlueFairy\ApiGateway\Settings;

/**
 * `rest_pre_dispatch` serves a stored response when there is one;
 * `rest_post_dispatch` stores a fresh one when the request and the
 * response both qualify. Every response that passes through gains an
 * `X-BF-Cache` header: HIT, MISS or BYPASS.
 *
 * Only anonymous GET/HEAD requests are ever cached — a logged-in user, or
 * any request carrying Authorization, an API key or a nonce, may see data
 * nobody else should. Responses that set cookies, stream, or ask not to be
 * stored are left alone. Routes are cached only when an enabled rule
 * matches them: rules are the allow-list, and the seeded `**` rule is what
 * makes the free plugin cache everything out of the box.
 */
final class CacheManager {

	/**
	 * The response header announcing the cache outcome.
	 */
	public const HEADER = 'X-BF-Cache';

	/**
	 * Request headers whose presence means the response is personal.
	 */
	private const PRIVATE_REQUEST_HEADERS = [ 'authorization', 'x-bf-api-key', 'x-wp-nonce' ];

	/**
	 * This plugin's own REST namespace, never cached.
	 */
	private const OWN_NAMESPACE = '/bfag/v1';

	/**
	 * Cache-Control directives that forbid storing the response.
	 *
	 * @var list<string>
	 */
	private const UNCACHEABLE_DIRECTIVES = [ 'no-store', 'private', 'no-cache' ];

	/**
	 * Constructor.
	 *
	 * @param CacheStore           $store    Response storage.
	 * @param CacheKeyGenerator    $keys     Key derivation.
	 * @param CacheRuleRepository  $rules    Rules.
	 * @param CacheStatsRepository $stats    Hit/miss counters.
	 * @param Settings             $settings Plugin settings.
	 */
	public function __construct(
		private readonly CacheStore $store,
		private readonly CacheKeyGenerator $keys,
		private readonly CacheRuleRepository $rules,
		private readonly CacheStatsRepository $stats,
		private readonly Settings $settings
	) {}

	/**
	 * Bind to the REST dispatcher.
	 */
	public function register_hooks(): void {
		add_filter( 'rest_pre_dispatch', [ $this, 'maybe_serve' ], 10, 3 );
		add_filter( 'rest_post_dispatch', [ $this, 'maybe_store' ], 10, 3 );
	}

	/**
	 * Serve a stored response, or pass `$result` through untouched.
	 *
	 * @param mixed            $result  Whatever an earlier filter returned (non-null short-circuits).
	 * @param \WP_REST_Server  $server  The server.
	 * @param \WP_REST_Request $request The request.
	 */
	public function maybe_serve( mixed $result, \WP_REST_Server $server, \WP_REST_Request $request ): mixed {
		if ( null !== $result || ! $this->is_cacheable_request( $request ) ) {
			return $result;
		}
		$rule = $this->rules->match( $request->get_route() );
		if ( null === $rule ) {
			return $result;
		}
		$entry = $this->store->get( $this->keys->for_request( $request, $this->rules->tags_of( $rule ) ) );
		if ( null === $entry ) {
			return $result;
		}

		$response = new \WP_REST_Response( $entry['data'], $entry['status'], $entry['headers'] );
		$response->header( self::HEADER, 'HIT' );
		$this->stats->record_hit();
		return $response;
	}

	/**
	 * Store the response when it qualifies and label it MISS; otherwise
	 * label it BYPASS. A response already labelled (a HIT served by
	 * maybe_serve, which WordPress still passes through this filter) is
	 * returned as is.
	 *
	 * Typed on WP_HTTP_Response because that is what rest_post_dispatch
	 * carries: another plugin may return a bare WP_HTTP_Response, and a
	 * narrower type here would turn that into a TypeError on every REST call.
	 *
	 * @param \WP_HTTP_Response $response The response.
	 * @param \WP_REST_Server   $server   The server.
	 * @param \WP_REST_Request  $request  The request.
	 */
	public function maybe_store( \WP_HTTP_Response $response, \WP_REST_Server $server, \WP_REST_Request $request ): \WP_HTTP_Response {
		if ( ! $response instanceof \WP_REST_Response ) {
			return $response;
		}
		if ( null !== $this->header_value( $response->get_headers(), self::HEADER ) ) {
			return $response;
		}
		if ( ! $this->is_cacheable_request( $request ) ) {
			$response->header( self::HEADER, 'BYPASS' );
			return $response;
		}
		$rule = $this->rules->match( $request->get_route() );
		if ( null === $rule ) {
			$response->header( self::HEADER, 'BYPASS' );
			return $response;
		}

		foreach ( $this->rule_headers( $rule ) as $name => $value ) {
			$response->header( $name, $value );
		}

		if ( ! $this->is_cacheable_response( $response ) ) {
			$response->header( self::HEADER, 'BYPASS' );
			return $response;
		}

		$key = $this->keys->for_request( $request, $this->rules->tags_of( $rule ) );
		$this->store->set(
			$key,
			[
				'status'  => $response->get_status(),
				'headers' => $response->get_headers(),
				'data'    => $response->get_data(),
			],
			$this->ttl_for( $rule )
		);
		$this->stats->record_miss();
		$response->header( self::HEADER, 'MISS' );
		return $response;
	}

	/**
	 * May this request be answered from, or stored in, the cache?
	 *
	 * @param \WP_REST_Request $request The request.
	 */
	public function is_cacheable_request( \WP_REST_Request $request ): bool {
		if ( ! Settings::to_bool( $this->settings->get( 'cache_enabled' ) ) ) {
			return false;
		}
		if ( ! in_array( strtoupper( $request->get_method() ), [ 'GET', 'HEAD' ], true ) ) {
			return false;
		}
		if ( is_user_logged_in() ) {
			return false;
		}
		foreach ( self::PRIVATE_REQUEST_HEADERS as $header ) {
			if ( null !== $request->get_header( $header ) ) {
				return false;
			}
		}
		// Lower-cased because WP_REST_Server matches routes case-insensitively: `/Bfag/v1/status` is our status route.
		if ( str_starts_with( strtolower( '/' . ltrim( $request->get_route(), '/' ) ), self::OWN_NAMESPACE ) ) {
			return false;
		}
		/**
		 * Force a request past the cache.
		 *
		 * @since 1.0.0
		 *
		 * @param bool             $bypass  True to bypass. Default false.
		 * @param \WP_REST_Request $request The request.
		 */
		return ! (bool) apply_filters( 'bfag_cache_bypass', false, $request );
	}

	/**
	 * May this response be stored?
	 *
	 * @param \WP_REST_Response $response The response.
	 */
	public function is_cacheable_response( \WP_REST_Response $response ): bool {
		$status = $response->get_status();
		if ( $status < 200 || $status > 299 ) {
			return false;
		}
		$headers = $response->get_headers();
		if ( str_contains( strtolower( (string) $this->header_value( $headers, 'Content-Type' ) ), 'text/event-stream' ) ) {
			return false;
		}
		if ( null !== $this->header_value( $headers, 'Set-Cookie' ) ) {
			return false;
		}
		return ! $this->cache_control_forbids( (string) $this->header_value( $headers, 'Cache-Control' ) );
	}

	/**
	 * Does a Cache-Control value say the response must not be stored and
	 * served as is? `no-store`, `private` and `no-cache` (we cannot
	 * revalidate), or a `max-age` / `s-maxage` of zero.
	 *
	 * @param string $value The header value.
	 */
	private function cache_control_forbids( string $value ): bool {
		foreach ( explode( ',', strtolower( $value ) ) as $directive ) {
			[ $name, $arg ] = array_pad( explode( '=', trim( $directive ), 2 ), 2, null );
			$name           = trim( $name );
			if ( in_array( $name, self::UNCACHEABLE_DIRECTIVES, true ) ) {
				return true;
			}
			if ( in_array( $name, [ 'max-age', 's-maxage' ], true ) && null !== $arg && (int) trim( $arg, " \t\"" ) <= 0 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Seconds to store a response: the rule's TTL, else the default setting.
	 *
	 * @param array<string, mixed>|null $rule The matching rule, if any.
	 */
	public function ttl_for( ?array $rule ): int {
		// A rule always carries a TTL (the repository clamps it to 1–86400 on
		// every write); `default_cache_ttl` only pre-fills the add form (U-09).
		return max( 1, min( 86400, (int) ( $rule['ttl_seconds'] ?? 0 ) ) );
	}

	/**
	 * Extra response headers configured on a rule (JSON object), if any.
	 *
	 * @param array<string, mixed> $rule The rule.
	 * @return array<string, string>
	 */
	private function rule_headers( array $rule ): array {
		$raw = $rule['headers'] ?? null;
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return [];
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return [];
		}
		$out = [];
		foreach ( $decoded as $name => $value ) {
			if ( is_string( $name ) && is_scalar( $value ) && 1 === preg_match( '/^[A-Za-z0-9-]+$/', $name ) && 0 !== strcasecmp( $name, self::HEADER ) ) {
				$out[ $name ] = (string) $value;
			}
		}
		return $out;
	}

	/**
	 * Case-insensitive header lookup.
	 *
	 * @param array<string, string> $headers Response headers.
	 * @param string                $name    Header name.
	 */
	private function header_value( array $headers, string $name ): ?string {
		foreach ( $headers as $key => $value ) {
			if ( 0 === strcasecmp( (string) $key, $name ) ) {
				return (string) $value;
			}
		}
		return null;
	}
}
