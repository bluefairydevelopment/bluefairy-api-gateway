<?php
/**
 * GET /bfag/v1/status.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Rest;

use BlueFairy\ApiGateway\Admin\KpiCron;
use BlueFairy\ApiGateway\Cache\CacheStatsRepository;
use BlueFairy\ApiGateway\Capabilities;
use BlueFairy\ApiGateway\Webhook\DeliveryRepository;
use BlueFairy\ApiGateway\Webhook\WebhookRepository;

/**
 * Version, the KPI transient, 24-hour counts and whether Pro is active.
 * Gated by the manage capability; the rate limiter skips this route so a
 * monitor polling it cannot lock itself out.
 */
final class StatusController {

	public const REST_NAMESPACE = 'bfag/v1';

	/**
	 * Constructor.
	 *
	 * @param CacheStatsRepository $stats      Cache statistics.
	 * @param DeliveryRepository   $deliveries Delivery log.
	 * @param WebhookRepository    $webhooks   Webhooks.
	 */
	public function __construct(
		private readonly CacheStatsRepository $stats,
		private readonly DeliveryRepository $deliveries,
		private readonly WebhookRepository $webhooks
	) {}

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
		$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$kpis  = get_transient( KpiCron::TRANSIENT );

		return new \WP_REST_Response(
			[
				'version' => BFAG_VERSION,
				'kpis'    => is_array( $kpis ) ? $kpis : null,
				'counts'  => [
					'webhooks'       => $this->webhooks->count(),
					'cache_24h'      => $this->stats->totals_since( $since ),
					'deliveries_24h' => $this->deliveries->stats_since( $since ),
				],
				'pro'     => (bool) apply_filters( 'bfag_is_pro', false ),
			],
			200
		);
	}
}
