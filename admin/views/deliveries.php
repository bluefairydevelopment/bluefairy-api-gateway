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

<div class="bfag-table-scroll">
<table class="widefat striped bfag-table">
	<caption class="screen-reader-text"><?php esc_html_e( 'Delivery log, newest first', 'bluefairy-api-gateway' ); ?></caption>
	<thead>
		<tr>
			<th scope="col"><?php esc_html_e( 'ID', 'bluefairy-api-gateway' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Webhook', 'bluefairy-api-gateway' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Event', 'bluefairy-api-gateway' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Status', 'bluefairy-api-gateway' ); ?></th>
			<th scope="col"><?php esc_html_e( 'HTTP', 'bluefairy-api-gateway' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Attempt', 'bluefairy-api-gateway' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Created (site time)', 'bluefairy-api-gateway' ); ?></th>
			<th scope="col"><?php esc_html_e( 'Delivered (site time)', 'bluefairy-api-gateway' ); ?></th>
			<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Retry', 'bluefairy-api-gateway' ); ?></span></th>
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
			$bfag_finished  = in_array( $bfag_status, [ 'success', 'failed' ], true );
			// Retry is offered only where it means something: a failed (or Pro-retrying) outgoing delivery (U-10).
			$bfag_retryable = ! $bfag_truncated && 'out' === (string) $bfag_row['direction'] && in_array( $bfag_status, \BlueFairy\ApiGateway\Admin\AdminMenu::RETRYABLE_STATUSES, true );
			$bfag_body      = substr( is_scalar( $bfag_row['response_body'] ?? null ) ? (string) $bfag_row['response_body'] : '', 0, 2000 );
			$bfag_response  = null === $bfag_row['http_status']
				/* translators: %s: the transport error message */
				? sprintf( __( 'Transport error: %s', 'bluefairy-api-gateway' ), $bfag_body )
				/* translators: 1: HTTP status code, 2: response body (empty or preceded by a line break) */
				: sprintf( __( 'HTTP %1$s%2$s', 'bluefairy-api-gateway' ), (string) $bfag_row['http_status'], '' === $bfag_body ? '' : "\n" . $bfag_body );
			?>
			<tr data-bfag-delivery="<?php echo esc_attr( (string) $bfag_id ); ?>">
				<td><?php echo esc_html( (string) $bfag_id ); ?></td>
				<td><?php echo esc_html( $labels[ (int) $bfag_row['webhook_id'] ] ?? '#' . (int) $bfag_row['webhook_id'] ); ?></td>
				<td><code><?php echo esc_html( (string) $bfag_row['event'] ); ?></code></td>
				<td>
					<span class="bfag-badge bfag-badge--<?php echo esc_attr( $bfag_status ); ?>" data-bfag-delivery-status><?php echo esc_html( $bfag_status ); ?></span>
					<?php if ( 'retrying' === $bfag_status && is_string( $bfag_row['next_retry_at'] ?? null ) && '' !== $bfag_row['next_retry_at'] ) : ?>
						<span class="bfag-next-attempt description">
							<?php
							printf(
								/* translators: %s: the next attempt's date and time in the site's timezone */
								esc_html__( 'next attempt %s', 'bluefairy-api-gateway' ),
								esc_html( \BlueFairy\ApiGateway\Admin\Page::format_datetime( (string) $bfag_row['next_retry_at'] ) )
							);
							?>
						</span>
					<?php endif; ?>
					<?php if ( $bfag_truncated ) : ?>
						<span class="bfag-badge bfag-badge--muted" title="<?php esc_attr_e( 'The stored payload was cut to the configured maximum; it cannot be resent.', 'bluefairy-api-gateway' ); ?>"><?php esc_html_e( 'truncated', 'bluefairy-api-gateway' ); ?></span>
					<?php endif; ?>
				</td>
				<td><?php echo esc_html( null === $bfag_row['http_status'] ? '—' : (string) $bfag_row['http_status'] ); ?></td>
				<td><?php echo esc_html( (string) $bfag_row['attempt'] ); ?></td>
				<td><?php echo esc_html( \BlueFairy\ApiGateway\Admin\Page::format_datetime( (string) $bfag_row['created_at'] ) ); ?></td>
				<td><?php echo esc_html( null === $bfag_row['delivered_at'] ? '—' : \BlueFairy\ApiGateway\Admin\Page::format_datetime( (string) $bfag_row['delivered_at'] ) ); ?></td>
				<td>
					<?php if ( $bfag_retryable ) : ?>
						<button type="button" class="button button-small" data-bfag-retry="<?php echo esc_attr( (string) $bfag_id ); ?>"><?php esc_html_e( 'Retry', 'bluefairy-api-gateway' ); ?></button>
					<?php endif; ?>
				</td>
			</tr>
			<?php if ( $bfag_finished ) : ?>
				<tr class="bfag-row-detail">
					<td colspan="9">
						<details>
							<summary>
								<?php
								printf(
									/* translators: %d: the delivery id */
									esc_html__( 'Response to delivery #%d', 'bluefairy-api-gateway' ),
									(int) $bfag_id
								);
								?>
							</summary>
							<pre class="bfag-response"><?php echo esc_html( $bfag_response ); ?></pre>
						</details>
					</td>
				</tr>
			<?php endif; ?>
		<?php endforeach; ?>
	</tbody>
</table>
</div>
