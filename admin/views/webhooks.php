<?php
/**
 * Webhook list.
 *
 * @var list<array<string, mixed>>                                                 $webhooks       Webhook rows (secrets are never printed).
 * @var int                                                                        $count          Webhooks stored.
 * @var int                                                                        $limit          Webhooks allowed.
 * @var array<int, string|null>                                                    $last_status    Webhook id => last delivery status.
 * @var array<string, array{hook:string, label:string, accepted_args:int, group:string}> $events  Known events.
 * @var \BlueFairy\ApiGateway\Admin\WebhookPage                                    $page           For url() / action_url().
 * @var string                                                                     $deliveries_url The delivery log URL.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;
?>
<div class="bfag-toolbar">
	<h2><?php esc_html_e( 'Webhooks', 'bluefairy-api-gateway' ); ?></h2>
	<span class="bfag-count">
		<?php
		printf(
			/* translators: 1: webhooks stored, 2: webhooks allowed */
			esc_html__( '%1$d of %2$d', 'bluefairy-api-gateway' ),
			(int) $count,
			(int) $limit
		);
		?>
	</span>
	<a href="<?php echo esc_url( $deliveries_url ); ?>" class="button"><?php esc_html_e( 'Delivery log', 'bluefairy-api-gateway' ); ?></a>
	<span class="bfag-status" data-bfag-status aria-live="polite"></span>
</div>
<p class="description"><?php esc_html_e( 'Each delivery is a JSON POST signed with X-BF-Signature: sha256=HMAC(secret, timestamp.body). Verify the signature and the X-BF-Timestamp at the receiver.', 'bluefairy-api-gateway' ); ?></p>

<table class="widefat striped bfag-table">
	<thead>
		<tr>
			<th><?php esc_html_e( 'Label', 'bluefairy-api-gateway' ); ?></th>
			<th><?php esc_html_e( 'Host', 'bluefairy-api-gateway' ); ?></th>
			<th><?php esc_html_e( 'Event', 'bluefairy-api-gateway' ); ?></th>
			<th><?php esc_html_e( 'Preset', 'bluefairy-api-gateway' ); ?></th>
			<th><?php esc_html_e( 'Enabled', 'bluefairy-api-gateway' ); ?></th>
			<th><?php esc_html_e( 'Last delivery', 'bluefairy-api-gateway' ); ?></th>
			<th><?php esc_html_e( 'Actions', 'bluefairy-api-gateway' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php if ( [] === $webhooks ) : ?>
			<tr><td colspan="7"><?php esc_html_e( 'No webhooks yet.', 'bluefairy-api-gateway' ); ?></td></tr>
		<?php endif; ?>
		<?php foreach ( $webhooks as $bfag_webhook ) : ?>
			<?php
			$bfag_id       = (int) $bfag_webhook['id'];
			$bfag_enabled  = 1 === (int) $bfag_webhook['enabled'];
			$bfag_host     = wp_parse_url( (string) $bfag_webhook['url'], PHP_URL_HOST );
			$bfag_status   = $last_status[ $bfag_id ] ?? null;
			$bfag_event    = (string) $bfag_webhook['event'];
			$bfag_edit_url = $page->url(
				[
					'action' => 'edit',
					'id'     => $bfag_id,
				]
			);
			$bfag_log_url  = $page->url(
				[
					'view'       => 'deliveries',
					'webhook_id' => $bfag_id,
				]
			);
			?>
			<tr>
				<td><strong><?php echo esc_html( (string) $bfag_webhook['label'] ); ?></strong></td>
				<td><code><?php echo esc_html( is_string( $bfag_host ) ? $bfag_host : '—' ); ?></code></td>
				<td title="<?php echo esc_attr( $bfag_event ); ?>"><?php echo esc_html( $events[ $bfag_event ]['label'] ?? $bfag_event ); ?></td>
				<td><?php echo esc_html( '' === (string) $bfag_webhook['preset'] ? '—' : (string) $bfag_webhook['preset'] ); ?></td>
				<td>
					<a href="<?php echo esc_url( $page->action_url( 'toggle_webhook', $bfag_id, [ 'enabled' => $bfag_enabled ? 0 : 1 ] ) ); ?>"
						class="bfag-badge <?php echo $bfag_enabled ? 'bfag-badge--success' : 'bfag-badge--muted'; ?>"
						data-bfag-toggle="webhook" data-bfag-id="<?php echo esc_attr( (string) $bfag_id ); ?>" data-bfag-enabled="<?php echo $bfag_enabled ? '1' : '0'; ?>">
						<?php $bfag_enabled ? esc_html_e( 'Enabled', 'bluefairy-api-gateway' ) : esc_html_e( 'Disabled', 'bluefairy-api-gateway' ); ?>
					</a>
				</td>
				<td>
					<?php if ( null === $bfag_status ) : ?>
						<span class="bfag-badge bfag-badge--muted">—</span>
					<?php else : ?>
						<a href="<?php echo esc_url( $bfag_log_url ); ?>" class="bfag-badge bfag-badge--<?php echo esc_attr( $bfag_status ); ?>"><?php echo esc_html( $bfag_status ); ?></a>
					<?php endif; ?>
				</td>
				<td>
					<a href="<?php echo esc_url( $bfag_edit_url ); ?>"><?php esc_html_e( 'Edit', 'bluefairy-api-gateway' ); ?></a> |
					<a href="<?php echo esc_url( $page->action_url( 'test_webhook', $bfag_id ) ); ?>"><?php esc_html_e( 'Send test', 'bluefairy-api-gateway' ); ?></a> |
					<a href="<?php echo esc_url( $page->action_url( 'delete_webhook', $bfag_id ) ); ?>" class="bfag-delete" data-bfag-confirm><?php esc_html_e( 'Delete', 'bluefairy-api-gateway' ); ?></a>
				</td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>
