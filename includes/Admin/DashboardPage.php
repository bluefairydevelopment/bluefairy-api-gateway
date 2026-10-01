<?php
/**
 * The Dashboard tab.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use BlueFairy\ApiGateway\Cache\CacheRuleRepository;
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
	 * @param KpiCron                 $kpis        KPI transient (also the source of the hits-table row count).
	 * @param ProUpsell|null          $upsell      Pro state (the third card differs); null builds one.
	 */
	public function __construct(
		private readonly CacheRuleRepository $cache_rules,
		private readonly RateLimitRuleRepository $rl_rules,
		private readonly WebhookRepository $webhooks,
		private readonly DeliveryRepository $deliveries,
		private readonly KpiCron $kpis,
		?ProUpsell $upsell = null
	) {
		$this->upsell = $upsell ?? new ProUpsell();
	}

	/**
	 * Pro state.
	 *
	 * @var ProUpsell
	 */
	private ProUpsell $upsell;

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
		$kpis = $this->kpis->current();
		$this->view(
			'dashboard',
			[
				'kpis'    => $kpis,
				'is_pro'  => $this->upsell->is_pro(),
				'failed'  => $this->deliveries->recent( self::FAILED_LIMIT, [ 'status' => 'failed' ] ),
				'labels'  => $labels,
				'counts'  => [
					'cache_rules'      => $this->cache_rules->count(),
					'rate_limit_rules' => $this->rl_rules->count(),
					'webhooks'         => $this->webhooks->count(),
					// From the KPI transient, never a live COUNT(*) of the hits table on every render; null (a dash) when cold.
					'hits_table_rows'  => isset( $kpis['hits_table_rows'] ) && is_numeric( $kpis['hits_table_rows'] ) ? (int) $kpis['hits_table_rows'] : null,
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
