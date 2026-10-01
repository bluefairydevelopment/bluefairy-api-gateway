<?php
/**
 * Cache key derivation for REST requests.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Cache;

/**
 * One request, one key: md5 of the method, the route, the sorted query
 * parameters, the lower-cased Accept header, the global generation and the
 * generation of every tag the matching rule carries.
 *
 * `_wpnonce` and `_locale` are dropped from the parameters — they vary per
 * visitor without changing the response. Every scalar parameter is cast to
 * string first: at rest_pre_dispatch the params are the raw query strings,
 * but by rest_post_dispatch core has sanitised them to their declared types
 * (`"2"` becomes `2`), and the key stored must equal the key looked up.
 * Because the generations are part of the key, a purge never has to find
 * the old entries: it only has to change the key every future request
 * computes.
 */
final class CacheKeyGenerator {

	/**
	 * Query parameters that never affect the response.
	 */
	private const IGNORED_PARAMS = [ '_wpnonce', '_locale' ];

	/**
	 * Constructor.
	 *
	 * @param CacheStore $store Source of the generations.
	 */
	public function __construct( private readonly CacheStore $store ) {}

	/**
	 * The key for a request under the given rule tags.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @param string[]         $tags    Tags of the matching rule, if any.
	 */
	public function for_request( \WP_REST_Request $request, array $tags = [] ): string {
		$params = $request->get_query_params();
		foreach ( self::IGNORED_PARAMS as $ignored ) {
			unset( $params[ $ignored ] );
		}
		$this->normalize( $params );

		$accept = strtolower( trim( (string) $request->get_header( 'accept' ) ) );

		$parts = [
			strtoupper( $request->get_method() ),
			'/' . ltrim( $request->get_route(), '/' ),
			(string) wp_json_encode( $params ),
			$accept,
			'g=' . $this->store->generation(),
		];

		$clean = [];
		foreach ( $tags as $tag ) {
			if ( is_string( $tag ) && '' !== $tag ) {
				$clean[ $tag ] = true;
			}
		}
		$clean = array_keys( $clean );
		sort( $clean );
		foreach ( $clean as $tag ) {
			$parts[] = 't=' . $tag . ':' . $this->store->tag_generation( $tag );
		}

		return md5( implode( "\n", $parts ) );
	}

	/**
	 * Sort by key at every depth, so parameter order never changes the key,
	 * and cast every scalar to the string form it had on the query string,
	 * so core's type coercion between pre- and post-dispatch does not either
	 * (booleans the way PHP casts them: true is "1", false is "").
	 *
	 * @param array<mixed> $value Array to normalise in place.
	 */
	private function normalize( array &$value ): void {
		ksort( $value );
		foreach ( $value as &$child ) {
			if ( is_array( $child ) ) {
				$this->normalize( $child );
			} elseif ( is_bool( $child ) ) {
				$child = $child ? '1' : '';
			} elseif ( null === $child || is_scalar( $child ) ) {
				$child = (string) $child;
			}
		}
		unset( $child );
	}
}
