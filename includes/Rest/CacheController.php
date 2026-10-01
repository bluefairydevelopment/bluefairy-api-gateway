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

use BlueFairy\ApiGateway\Cache\CachePurger;
use BlueFairy\ApiGateway\Cache\CacheStore;
use BlueFairy\ApiGateway\Capabilities;

/**
 * Purge the response cache: `tags` (array) bumps those tags, no tags
 * flushes everything. Gated by Capabilities::request_allowed(): the manage
 * capability, or an API key (Pro) whose scope satisfies `cache:purge`,
 * bound to a user or not (N3). The purge itself goes through CachePurger,
 * the one entry point (F11), so a tag no enabled rule carries is dropped
 * and logged here exactly as it is on the Cache tab.
 */
final class CacheController {

	public const REST_NAMESPACE = 'bfag/v1';

	/**
	 * Constructor.
	 *
	 * @param CachePurger $purger The one purge entry point.
	 */
	public function __construct( private readonly CachePurger $purger ) {}

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
	 * A gateway manager, or an API key holding `cache:purge`, may purge.
	 *
	 * @param \WP_REST_Request $request The request.
	 */
	public function permission( \WP_REST_Request $request ): bool {
		return Capabilities::request_allowed( $request );
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
			$this->purger->purge_all();
		} else {
			$tags = $this->purger->purge_tags( $tags );
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
