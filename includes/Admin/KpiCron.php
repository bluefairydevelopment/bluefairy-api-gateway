<?php
/**
 * Dashboard KPIs, recomputed every five minutes.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Admin;

use BlueFairy\ApiGateway\Cache\CacheStatsRepository;
use BlueFairy\ApiGateway\RateLimit\HitCounter;
use BlueFairy\ApiGateway\Webhook\DeliveryRepository;

/**
 * The three dashboard cards read a transient, never the tables: an admin
 * opening the dashboard must not run aggregate queries on every load.
 * `bfag_cron_recompute_kpis` (every five minutes) and the Refresh button
 * both call run(); the transient outlives one cron interval by a minute so
 * a late cron does not leave the cards cold.
 */
final class KpiCron {

	public const HOOK      = 'bfag_cron_recompute_kpis';
	public const TRANSIENT = 'bfag_kpis';
	public const TTL       = 360;
	public const WINDOW    = DAY_IN_SECONDS;

	/**
	 * Constructor.
	 *
	 * @param CacheStatsRepository $stats      Cache hit/miss buckets.
	 * @param DeliveryRepository   $deliveries Webhook delivery log.
	 * @param HitCounter           $hits       Rate-limit hit log.
	 */
	public function __construct(
		private readonly CacheStatsRepository $stats,
		private readonly DeliveryRepository $deliveries,
		private readonly HitCounter $hits
	) {}

	/**
	 * Bind the cron hook.
	 */
	public function register_hooks(): void {
		add_action(
			self::HOOK,
			function (): void {
				$this->run();
			}
		);
	}

	/**
	 * Compute the KPIs over the last 24 hours.
	 *
	 * @return array<string, mixed>
	 */
	public function compute(): array {
		$since  = gmdate( 'Y-m-d H:i:s', time() - self::WINDOW );
		$totals = $this->stats->totals_since( $since );
		$total  = $totals['hits'] + $totals['misses'];

		$delivery  = $this->deliveries->stats_since( $since );
		$finished  = $delivery['success'] + $delivery['failed'];
		$delivered = $finished + $delivery['pending'] + $delivery['retrying'];

		$kpis = [
			'cache_hit_rate'       => $total > 0 ? (float) ( $totals['hits'] / $total ) : null,
			'cache_hits'           => $totals['hits'],
			'cache_misses'         => $totals['misses'],
			'webhook_success_rate' => $finished > 0 ? (float) ( $delivery['success'] / $finished ) : null,
			'webhook_failed'       => $delivery['failed'],
			'webhook_total'        => $delivered,
			/**
			 * Requests blocked today. Zero in the free plugin; the Pro add-on
			 * reports its ban list.
			 *
			 * @since 1.0.0
			 *
			 * @param int $blocked Default 0.
			 */
			'blocked_today'        => (int) apply_filters( 'bfag_kpi_blocked_today', 0 ),
			'hits_table_rows'      => $this->hits->total_rows(),
			'computed_at'          => time(),
		];

		/**
		 * Filter the dashboard KPIs. Add-ons add their own cards here.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $kpis The KPI array.
		 */
		$filtered = apply_filters( 'bfag_dashboard_kpis', $kpis );
		return is_array( $filtered ) ? $filtered : $kpis;
	}

	/**
	 * Recompute and store. Returns what was stored.
	 *
	 * @return array<string, mixed>
	 */
	public function run(): array {
		$kpis = $this->compute();
		set_transient( self::TRANSIENT, $kpis, self::TTL );
		return $kpis;
	}

	/**
	 * The stored KPIs, or null when cold.
	 *
	 * @return array<string, mixed>|null
	 */
	public function current(): ?array {
		$stored = get_transient( self::TRANSIENT );
		return is_array( $stored ) ? $stored : null;
	}
}
