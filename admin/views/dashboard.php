<?php
/**
 * Dashboard: KPI cards, failed deliveries, counts.
 *
 * @var array<string, mixed>|null  $kpis    KpiCron::current(), null when cold.
 * @var bool                       $is_pro  Whether the Pro add-on is active (the third card is its ban count).
 * @var list<array<string, mixed>> $failed  The most recent failed deliveries.
 * @var array<int, string>         $labels  Webhook id => label.
 * @var array<string, int|null>    $counts  cache_rules, rate_limit_rules, webhooks, hits_table_rows (null until the KPI cron has run).
 * @var string                     $log_url The delivery log URL.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

$bfag_rate = static function ( mixed $value ): string {
	return is_float( $value ) || is_int( $value ) ? number_format_i18n( $value * 100, 1 ) . '%' : '—';
};
$bfag_int  = static function ( mixed $value ): string {
	return is_int( $value ) || is_float( $value ) ? number_format_i18n( $value ) : '—';
};
?>
<div class="bfag-kpis" data-bfag-kpis>
	<div class="bfag-kpi">
		<span class="bfag-kpi__label"><?php esc_html_e( 'Cache hit rate (24 h)', 'bluefairy-api-gateway' ); ?></span>
		<span class="bfag-kpi__value" data-bfag-kpi="cache_hit_rate" data-bfag-format="rate"><?php echo esc_html( $bfag_rate( $kpis['cache_hit_rate'] ?? null ) ); ?></span>
		<span class="bfag-kpi__sub">
			<span data-bfag-kpi="cache_hits"><?php echo esc_html( $bfag_int( $kpis['cache_hits'] ?? null ) ); ?></span> <?php esc_html_e( 'hits', 'bluefairy-api-gateway' ); ?> ·
			<span data-bfag-kpi="cache_misses"><?php echo esc_html( $bfag_int( $kpis['cache_misses'] ?? null ) ); ?></span> <?php esc_html_e( 'misses', 'bluefairy-api-gateway' ); ?>
		</span>
	</div>
	<div class="bfag-kpi">
		<span class="bfag-kpi__label"><?php esc_html_e( 'Webhook success (24 h)', 'bluefairy-api-gateway' ); ?></span>
		<span class="bfag-kpi__value" data-bfag-kpi="webhook_success_rate" data-bfag-format="rate"><?php echo esc_html( $bfag_rate( $kpis['webhook_success_rate'] ?? null ) ); ?></span>
		<span class="bfag-kpi__sub">
			<span data-bfag-kpi="webhook_failed"><?php echo esc_html( $bfag_int( $kpis['webhook_failed'] ?? null ) ); ?></span> <?php esc_html_e( 'failed of', 'bluefairy-api-gateway' ); ?>
			<span data-bfag-kpi="webhook_total"><?php echo esc_html( $bfag_int( $kpis['webhook_total'] ?? null ) ); ?></span>
		</span>
	</div>
	<?php if ( $is_pro ) : ?>
		<?php // The Pro mitigator's figure is the count of active blocks; a `blocked_label` key in the KPI array may rename the card (U-19). ?>
		<div class="bfag-kpi">
			<span class="bfag-kpi__label" data-bfag-kpi-label="blocked"><?php echo esc_html( is_string( $kpis['blocked_label'] ?? null ) && '' !== $kpis['blocked_label'] ? $kpis['blocked_label'] : __( 'Active blocks', 'bluefairy-api-gateway' ) ); ?></span>
			<span class="bfag-kpi__value" data-bfag-kpi="blocked_today"><?php echo esc_html( $bfag_int( $kpis['blocked_today'] ?? null ) ); ?></span>
			<span class="bfag-kpi__sub">
				<span data-bfag-kpi="hits_table_rows"><?php echo esc_html( $bfag_int( $kpis['hits_table_rows'] ?? null ) ); ?></span> <?php esc_html_e( 'hits in the rate-limit log', 'bluefairy-api-gateway' ); ?>
			</span>
		</div>
	<?php else : ?>
		<div class="bfag-kpi bfag-kpi--pro">
			<span class="bfag-kpi__label"><?php esc_html_e( 'Blocked (Pro)', 'bluefairy-api-gateway' ); ?></span>
			<span class="bfag-kpi__value">—</span>
			<span class="bfag-kpi__sub"><?php esc_html_e( 'Blocks need the Pro add-on', 'bluefairy-api-gateway' ); ?></span>
		</div>
	<?php endif; ?>
</div>
<p class="bfag-kpi-actions">
	<button type="button" class="button" data-bfag-refresh><?php esc_html_e( 'Refresh', 'bluefairy-api-gateway' ); ?></button>
	<span class="bfag-kpi-updated">
		<?php if ( isset( $kpis['computed_at'] ) && is_int( $kpis['computed_at'] ) ) : ?>
			<?php
			printf(
				/* translators: %s: how long ago */
				esc_html__( 'Updated %s ago', 'bluefairy-api-gateway' ),
				esc_html( human_time_diff( $kpis['computed_at'] ) )
			);
			?>
		<?php else : ?>
			<?php esc_html_e( 'Not computed yet — the figures fill in after the first five-minute cron run, or press Refresh.', 'bluefairy-api-gateway' ); ?>
		<?php endif; ?>
	</span>
	<span class="bfag-status" data-bfag-status aria-live="polite"></span>
</p>

<h2><?php esc_html_e( 'Recent failed deliveries', 'bluefairy-api-gateway' ); ?></h2>
<?php if ( [] === $failed ) : ?>
	<p><?php esc_html_e( 'No failed deliveries.', 'bluefairy-api-gateway' ); ?></p>
<?php else : ?>
	<table class="widefat striped bfag-table">
		<caption class="screen-reader-text"><?php esc_html_e( 'Recent failed deliveries', 'bluefairy-api-gateway' ); ?></caption>
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Webhook', 'bluefairy-api-gateway' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Event', 'bluefairy-api-gateway' ); ?></th>
				<th scope="col"><?php esc_html_e( 'HTTP', 'bluefairy-api-gateway' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Attempt', 'bluefairy-api-gateway' ); ?></th>
				<th scope="col"><?php esc_html_e( 'When (site time)', 'bluefairy-api-gateway' ); ?></th>
				<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Retry', 'bluefairy-api-gateway' ); ?></span></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $failed as $bfag_row ) : ?>
				<?php
				// The same rule as the delivery log (U-10 / D5): Retry only on a failed (or Pro-retrying) OUTGOING row that was not truncated.
				$bfag_truncated = 1 === (int) $bfag_row['truncated'];
				$bfag_retryable = ! $bfag_truncated
					&& 'out' === (string) ( $bfag_row['direction'] ?? '' )
					&& in_array( (string) $bfag_row['status'], \BlueFairy\ApiGateway\Admin\AdminMenu::RETRYABLE_STATUSES, true );
				?>
				<tr data-bfag-delivery="<?php echo esc_attr( (string) $bfag_row['id'] ); ?>">
					<td><?php echo esc_html( $labels[ (int) $bfag_row['webhook_id'] ] ?? '#' . (int) $bfag_row['webhook_id'] ); ?></td>
					<td><code><?php echo esc_html( (string) $bfag_row['event'] ); ?></code></td>
					<td><?php echo esc_html( null === $bfag_row['http_status'] ? '—' : (string) $bfag_row['http_status'] ); ?></td>
					<td><?php echo esc_html( (string) $bfag_row['attempt'] ); ?></td>
					<td><?php echo esc_html( \BlueFairy\ApiGateway\Admin\Page::format_datetime( (string) $bfag_row['created_at'] ) ); ?></td>
					<td>
						<?php if ( $bfag_truncated ) : ?>
							<span class="bfag-badge bfag-badge--muted"><?php esc_html_e( 'Truncated — cannot retry', 'bluefairy-api-gateway' ); ?></span>
						<?php elseif ( $bfag_retryable ) : ?>
							<button type="button" class="button button-small" data-bfag-retry="<?php echo esc_attr( (string) $bfag_row['id'] ); ?>"><?php esc_html_e( 'Retry', 'bluefairy-api-gateway' ); ?></button>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<p><a href="<?php echo esc_url( $log_url ); ?>"><?php esc_html_e( 'Open the delivery log', 'bluefairy-api-gateway' ); ?></a></p>
<?php endif; ?>

<h2><?php esc_html_e( 'At a glance', 'bluefairy-api-gateway' ); ?></h2>
<table class="widefat bfag-table bfag-counts">
	<caption class="screen-reader-text"><?php esc_html_e( 'At a glance', 'bluefairy-api-gateway' ); ?></caption>
	<tbody>
		<tr><th><?php esc_html_e( 'Cache rules', 'bluefairy-api-gateway' ); ?></th><td><?php echo esc_html( number_format_i18n( $counts['cache_rules'] ) ); ?></td></tr>
		<tr><th><?php esc_html_e( 'Rate-limit rules', 'bluefairy-api-gateway' ); ?></th><td><?php echo esc_html( number_format_i18n( $counts['rate_limit_rules'] ) ); ?></td></tr>
		<tr><th><?php esc_html_e( 'Webhooks', 'bluefairy-api-gateway' ); ?></th><td><?php echo esc_html( number_format_i18n( $counts['webhooks'] ) ); ?></td></tr>
		<tr><th><?php esc_html_e( 'Rows in the rate-limit hits table', 'bluefairy-api-gateway' ); ?></th><td><span data-bfag-kpi="hits_table_rows"><?php echo esc_html( null === $counts['hits_table_rows'] ? '—' : number_format_i18n( $counts['hits_table_rows'] ) ); ?></span></td></tr>
	</tbody>
</table>

<h2><?php esc_html_e( 'Status endpoint', 'bluefairy-api-gateway' ); ?></h2>
<p class="description"><?php esc_html_e( 'A monitoring tool authenticated with an Application Password (or, with Pro, an API key) can read the same figures here:', 'bluefairy-api-gateway' ); ?></p>
<p class="bfag-keybox">
	<code data-bfag-copy-target><?php echo esc_html( rest_url( 'bfag/v1/status' ) ); ?></code>
	<button type="button" class="button button-small" data-bfag-copy="<?php echo esc_attr( rest_url( 'bfag/v1/status' ) ); ?>"><?php esc_html_e( 'Copy', 'bluefairy-api-gateway' ); ?></button>
</p>
