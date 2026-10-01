<?php
/**
 * The Dashboard tab.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Admin;

use BlueFairy\ApiGateway\Cache\CacheRuleRepository;
use BlueFairy\ApiGateway\RateLimit\HitCounter;
use BlueFairy\ApiGateway\RateLimit\RateLimitRuleRepository;
use BlueFairy\ApiGateway\Webhook\DeliveryRepository;
use BlueFairy\ApiGateway\Webhook\WebhookRepository;

/**
 * Three KPI cards from the KPI transient (a dash when cold), a Refresh
 * button, the last ten failed deliveries with Retry, and the row counts.
 * It has no form actions: Refresh and Retry are AJAX, handled by AdminMenu.
 */
final class DashboardPage extends Page {

	public const TAB = 'dashboard';

	public const FAILED_LIMIT = 10;

	/**
	 * Constructor.
	 *
	 * @param CacheRuleRepository     $cache_rules Cache rules.
	 * @param RateLimitRuleRepository $rl_rules    Rate-limit rules.
	 * @param WebhookRepository       $webhooks    Webhooks.
	 * @param DeliveryRepository      $deliveries  Delivery log.
	 * @param HitCounter              $hits        Rate-limit hits.
	 * @param KpiCron                 $kpis        KPI transient.
	 */
	public function __construct(
		private readonly CacheRuleRepository $cache_rules,
		private readonly RateLimitRuleRepository $rl_rules,
		private readonly WebhookRepository $webhooks,
		private readonly DeliveryRepository $deliveries,
		private readonly HitCounter $hits,
		private readonly KpiCron $kpis
	) {}

	/**
	 * Tab id.
	 */
	public function tab(): string {
		return self::TAB;
	}

	/**
	 * No form actions.
	 *
	 * @return list<string>
	 */
	public function actions(): array {
		return [];
	}

	/**
	 * Print the tab.
	 */
	public function render(): void {
		$labels = [];
		foreach ( $this->webhooks->all() as $row ) {
			$labels[ (int) $row['id'] ] = (string) $row['label'];
		}
		$this->view(
			'dashboard',
			[
				'kpis'    => $this->kpis->current(),
				'failed'  => $this->deliveries->recent( self::FAILED_LIMIT, [ 'status' => 'failed' ] ),
				'labels'  => $labels,
				'counts'  => [
					'cache_rules'      => $this->cache_rules->count(),
					'rate_limit_rules' => $this->rl_rules->count(),
					'webhooks'         => $this->webhooks->count(),
					'hits_table_rows'  => $this->hits->total_rows(),
				],
				'log_url' => add_query_arg(
					[
						'page' => self::MENU_SLUG,
						'tab'  => WebhookPage::TAB,
						'view' => 'deliveries',
					],
					admin_url( 'options-general.php' )
				),
			]
		);
	}

	/**
	 * Never called: actions() is empty.
	 *
	 * @param string               $action Action name.
	 * @param array<string, mixed> $fields Sanitised fields.
	 * @param array<string, mixed> $raw    Unslashed POST.
	 */
	protected function run_action( string $action, array $fields, array $raw ): void {
		unset( $action, $fields, $raw );
		$this->redirect( 'error' );
	}
}
