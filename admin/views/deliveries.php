<?php
/**
 * The delivery log.
 *
 * @var list<array<string, mixed>>                 $deliveries Delivery rows, newest first.
 * @var array<int, string>                         $labels     Webhook id => label.
 * @var int                                        $webhook_id The filter, 0 for all.
 * @var string                                     $back_url   Back to the webhook list.
 * @var \BlueFairy\ApiGateway\Admin\WebhookPage    $page       For url().
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;
?>
<div class="bfag-toolbar">
	<h2><?php esc_html_e( 'Delivery log', 'bluefairy-api-gateway' ); ?></h2>
	<?php if ( $webhook_id > 0 ) : ?>
		<span class="bfag-count"><?php echo esc_html( $labels[ $webhook_id ] ?? '#' . $webhook_id ); ?></span>
		<a href="<?php echo esc_url( $page->url( [ 'view' => 'deliveries' ] ) ); ?>" class="button"><?php esc_html_e( 'All webhooks', 'bluefairy-api-gateway' ); ?></a>
	<?php endif; ?>
	<a href="<?php echo esc_url( $back_url ); ?>" class="button"><?php esc_html_e( 'Back to webhooks', 'bluefairy-api-gateway' ); ?></a>
	<span class="bfag-status" data-bfag-status aria-live="polite"></span>
</div>

<table class="widefat striped bfag-table">
	<thead>
		<tr>
			<th><?php esc_html_e( 'ID', 'bluefairy-api-gateway' ); ?></th>
			<th><?php esc_html_e( 'Webhook', 'bluefairy-api-gateway' ); ?></th>
			<th><?php esc_html_e( 'Event', 'bluefairy-api-gateway' ); ?></th>
			<th><?php esc_html_e( 'Status', 'bluefairy-api-gateway' ); ?></th>
			<th><?php esc_html_e( 'HTTP', 'bluefairy-api-gateway' ); ?></th>
			<th><?php esc_html_e( 'Attempt', 'bluefairy-api-gateway' ); ?></th>
			<th><?php esc_html_e( 'Created', 'bluefairy-api-gateway' ); ?></th>
			<th><?php esc_html_e( 'Delivered', 'bluefairy-api-gateway' ); ?></th>
			<th></th>
		</tr>
	</thead>
	<tbody>
		<?php if ( [] === $deliveries ) : ?>
			<tr><td colspan="9"><?php esc_html_e( 'No deliveries yet.', 'bluefairy-api-gateway' ); ?></td></tr>
		<?php endif; ?>
		<?php foreach ( $deliveries as $bfag_row ) : ?>
			<?php
			$bfag_id        = (int) $bfag_row['id'];
			$bfag_status    = (string) $bfag_row['status'];
			$bfag_truncated = 1 === (int) $bfag_row['truncated'];
			?>
			<tr data-bfag-delivery="<?php echo esc_attr( (string) $bfag_id ); ?>">
				<td><?php echo esc_html( (string) $bfag_id ); ?></td>
				<td><?php echo esc_html( $labels[ (int) $bfag_row['webhook_id'] ] ?? '#' . (int) $bfag_row['webhook_id'] ); ?></td>
				<td><code><?php echo esc_html( (string) $bfag_row['event'] ); ?></code></td>
				<td>
					<span class="bfag-badge bfag-badge--<?php echo esc_attr( $bfag_status ); ?>" data-bfag-delivery-status><?php echo esc_html( $bfag_status ); ?></span>
					<?php if ( $bfag_truncated ) : ?>
						<span class="bfag-badge bfag-badge--muted" title="<?php esc_attr_e( 'The stored payload was cut to the configured maximum; it cannot be resent.', 'bluefairy-api-gateway' ); ?>"><?php esc_html_e( 'truncated', 'bluefairy-api-gateway' ); ?></span>
					<?php endif; ?>
				</td>
				<td><?php echo esc_html( null === $bfag_row['http_status'] ? '—' : (string) $bfag_row['http_status'] ); ?></td>
				<td><?php echo esc_html( (string) $bfag_row['attempt'] ); ?></td>
				<td><?php echo esc_html( (string) $bfag_row['created_at'] ); ?></td>
				<td><?php echo esc_html( null === $bfag_row['delivered_at'] ? '—' : (string) $bfag_row['delivered_at'] ); ?></td>
				<td>
					<?php if ( ! $bfag_truncated && 'out' === (string) $bfag_row['direction'] ) : ?>
						<button type="button" class="button button-small" data-bfag-retry="<?php echo esc_attr( (string) $bfag_id ); ?>"><?php esc_html_e( 'Retry', 'bluefairy-api-gateway' ); ?></button>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>
