<?php
/**
 * POST /bfag/v1/cache/purge.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Rest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use BlueFairy\ApiGateway\Cache\CacheStore;
use BlueFairy\ApiGateway\Capabilities;

/**
 * Purge the response cache: `tags` (array) bumps those tags, no tags
 * flushes everything. Gated by the manage capability (and, with Pro, by
 * the `cache:purge` API-key scope).
 */
final class CacheController {

	public const REST_NAMESPACE = 'bfag/v1';

	/**
	 * Constructor.
	 *
	 * @param CacheStore $store Response store.
	 */
	public function __construct( private readonly CacheStore $store ) {}

	/**
	 * Hooked to rest_api_init.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/cache/purge',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'purge' ],
				'permission_callback' => [ $this, 'permission' ],
				'args'                => [
					'tags' => [
						'description'       => __( 'Cache tags to bump. Omit to flush everything.', 'bluefairy-api-gateway' ),
						'type'              => 'array',
						'items'             => [ 'type' => 'string' ],
						'required'          => false,
						'sanitize_callback' => [ $this, 'sanitize_tags' ],
					],
				],
			]
		);
	}

	/**
	 * Only a gateway manager may purge.
	 */
	public function permission(): bool {
		return Capabilities::current_user_can_manage();
	}

	/**
	 * Accept an array or a comma-separated string; keep `[a-z0-9_:-]` tag
	 * names (CacheStore::sanitize_tag), lower-cased, unique, non-empty.
	 *
	 * @param mixed $value The raw parameter.
	 * @return list<string>
	 */
	public function sanitize_tags( mixed $value ): array {
		if ( is_string( $value ) ) {
			$value = explode( ',', $value );
		}
		if ( ! is_array( $value ) ) {
			return [];
		}
		$tags = [];
		foreach ( $value as $tag ) {
			if ( ! is_scalar( $tag ) ) {
				continue;
			}
			$tag = CacheStore::sanitize_tag( (string) $tag );
			if ( '' !== $tag ) {
				$tags[] = $tag;
			}
		}
		return array_values( array_unique( $tags ) );
	}

	/**
	 * The endpoint.
	 *
	 * @param \WP_REST_Request $request The request.
	 */
	public function purge( \WP_REST_Request $request ): \WP_REST_Response {
		$tags = $this->sanitize_tags( $request->get_param( 'tags' ) );
		if ( [] === $tags ) {
			$this->store->flush_all();
			/**
			 * The response cache was purged.
			 *
			 * @since 1.0.0
			 *
			 * @param string   $scope 'all' or 'tags'.
			 * @param string[] $tags  The tags bumped, empty for 'all'.
			 */
			do_action( 'bfag_cache_purged', 'all', [] );
		} else {
			$this->store->bump_tags( $tags );
			do_action( 'bfag_cache_purged', 'tags', $tags );
		}
		return new \WP_REST_Response(
			[
				'purged' => [] === $tags ? 'all' : 'tags',
				'tags'   => $tags,
			],
			200
		);
	}
}
