<?php
/**
 * GET /bfag/v1/status.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Rest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use BlueFairy\ApiGateway\Admin\KpiCron;
use BlueFairy\ApiGateway\Capabilities;
use BlueFairy\ApiGateway\Webhook\WebhookRepository;

/**
 * Version, the KPI transient, 24-hour counts and whether Pro is active.
 * Gated by the manage capability; the rate limiter skips this route so a
 * monitor polling it cannot lock itself out.
 *
 * A monitor polls this, so it must not run aggregates: the 24-hour counts
 * come from the KPI transient (null until the cron has run) and the one
 * live figure, the webhook count, is object-cached for a minute.
 */
final class StatusController {

	public const REST_NAMESPACE = 'bfag/v1';

	/**
	 * Object-cache key and TTL of the live webhook count.
	 */
	public const COUNT_KEY = 'status_webhook_count';
	public const COUNT_TTL = 60;

	/**
	 * Constructor.
	 *
	 * @param WebhookRepository $webhooks Webhooks (the one live count).
	 */
	public function __construct( private readonly WebhookRepository $webhooks ) {}

	/**
	 * Hooked to rest_api_init.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/status',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_status' ],
				'permission_callback' => [ $this, 'permission' ],
				'args'                => [],
			]
		);
	}

	/**
	 * Only a gateway manager may read the status.
	 */
	public function permission(): bool {
		return Capabilities::current_user_can_manage();
	}

	/**
	 * The status document.
	 *
	 * @param \WP_REST_Request $request The request (unused).
	 */
	public function get_status( \WP_REST_Request $request ): \WP_REST_Response {
		unset( $request );
		$kpis = get_transient( KpiCron::TRANSIENT );
		$kpis = is_array( $kpis ) ? $kpis : null;

		$cache_24h = null;
		if ( null !== $kpis && isset( $kpis['cache_hits'], $kpis['cache_misses'] ) ) {
			$cache_24h = [
				'hits'   => (int) $kpis['cache_hits'],
				'misses' => (int) $kpis['cache_misses'],
			];
		}
		$deliveries_24h = isset( $kpis['webhook_deliveries'] ) && is_array( $kpis['webhook_deliveries'] ) ? $kpis['webhook_deliveries'] : null;

		return new \WP_REST_Response(
			[
				'version' => BFAG_VERSION,
				'kpis'    => $kpis,
				'counts'  => [
					'webhooks'       => $this->webhook_count(),
					'cache_24h'      => $cache_24h,
					'deliveries_24h' => $deliveries_24h,
				],
				'pro'     => (bool) apply_filters( 'bfag_is_pro', false ),
			],
			200
		);
	}

	/**
	 * The webhook count, object-cached for COUNT_TTL seconds.
	 */
	private function webhook_count(): int {
		$found  = false;
		$cached = wp_cache_get( self::COUNT_KEY, 'bfag', false, $found );
		if ( $found && is_numeric( $cached ) ) {
			return (int) $cached;
		}
		$count = $this->webhooks->count();
		wp_cache_set( self::COUNT_KEY, $count, 'bfag', self::COUNT_TTL );
		return $count;
	}
}
