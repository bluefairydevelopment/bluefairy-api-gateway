<?php
/**
 * Rate-limit rules table.
 *
 * @var list<array<string, mixed>>                 $rules  Rule rows.
 * @var int                                        $count  Rules stored.
 * @var int                                        $limit  Rules allowed.
 * @var \BlueFairy\ApiGateway\Admin\RateLimitPage  $page   For url() / action_url().
 * @var bool                                       $is_pro Whether the penalty column is meaningful.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;
?>
<div class="bfag-toolbar">
	<h2><?php esc_html_e( 'Rate-limit rules', 'bluefairy-api-gateway' ); ?></h2>
	<span class="bfag-count">
		<?php
		printf(
			/* translators: 1: rules stored, 2: rules allowed */
			esc_html__( '%1$d of %2$d', 'bluefairy-api-gateway' ),
			(int) $count,
			(int) $limit
		);
		?>
	</span>
	<span class="bfag-status" data-bfag-status aria-live="polite"></span>
</div>
<p class="description"><?php esc_html_e( 'Every enabled rule whose glob matches the route counts the request; the first one exceeded answers 429 with Retry-After.', 'bluefairy-api-gateway' ); ?></p>

<table class="widefat striped bfag-table">
	<thead>
		<tr>
			<th><?php esc_html_e( 'Label', 'bluefairy-api-gateway' ); ?></th>
			<th><?php esc_html_e( 'Subject', 'bluefairy-api-gateway' ); ?></th>
			<th><?php esc_html_e( 'Route glob', 'bluefairy-api-gateway' ); ?></th>
			<th><?php esc_html_e( 'Limit', 'bluefairy-api-gateway' ); ?></th>
			<?php if ( $is_pro ) : ?>
				<th><?php esc_html_e( 'Penalty', 'bluefairy-api-gateway' ); ?></th>
			<?php endif; ?>
			<th><?php esc_html_e( 'Enabled', 'bluefairy-api-gateway' ); ?></th>
			<th><?php esc_html_e( 'Actions', 'bluefairy-api-gateway' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php if ( [] === $rules ) : ?>
			<tr><td colspan="<?php echo $is_pro ? '7' : '6'; ?>"><?php esc_html_e( 'No rate-limit rules yet.', 'bluefairy-api-gateway' ); ?></td></tr>
		<?php endif; ?>
		<?php foreach ( $rules as $bfag_rule ) : ?>
			<?php
			$bfag_id       = (int) $bfag_rule['id'];
			$bfag_enabled  = 1 === (int) $bfag_rule['enabled'];
			$bfag_edit_url = $page->url(
				[
					'action' => 'edit',
					'id'     => $bfag_id,
				]
			);
			?>
			<tr>
				<td><strong><?php echo esc_html( (string) $bfag_rule['label'] ); ?></strong></td>
				<td><?php echo esc_html( (string) $bfag_rule['subject_type'] ); ?></td>
				<td><code><?php echo esc_html( (string) $bfag_rule['route_glob'] ); ?></code></td>
				<td>
					<?php
					printf(
						/* translators: 1: requests, 2: seconds */
						esc_html__( '%1$d per %2$d s', 'bluefairy-api-gateway' ),
						(int) $bfag_rule['max_requests'],
						(int) $bfag_rule['window_seconds']
					);
					?>
				</td>
				<?php if ( $is_pro ) : ?>
					<td>
						<?php
						printf(
							/* translators: %d: seconds */
							esc_html__( '%d s', 'bluefairy-api-gateway' ),
							(int) ( $bfag_rule['penalty_seconds'] ?? 0 )
						);
						?>
					</td>
				<?php endif; ?>
				<td>
					<a href="<?php echo esc_url( $page->action_url( 'toggle_rate_limit_rule', $bfag_id, [ 'enabled' => $bfag_enabled ? 0 : 1 ] ) ); ?>"
						class="bfag-badge <?php echo $bfag_enabled ? 'bfag-badge--success' : 'bfag-badge--muted'; ?>"
						data-bfag-toggle="ratelimit" data-bfag-id="<?php echo esc_attr( (string) $bfag_id ); ?>" data-bfag-enabled="<?php echo $bfag_enabled ? '1' : '0'; ?>">
						<?php $bfag_enabled ? esc_html_e( 'Enabled', 'bluefairy-api-gateway' ) : esc_html_e( 'Disabled', 'bluefairy-api-gateway' ); ?>
					</a>
				</td>
				<td>
					<a href="<?php echo esc_url( $bfag_edit_url ); ?>"><?php esc_html_e( 'Edit', 'bluefairy-api-gateway' ); ?></a> |
					<a href="<?php echo esc_url( $page->action_url( 'delete_rate_limit_rule', $bfag_id ) ); ?>" class="bfag-delete" data-bfag-confirm><?php esc_html_e( 'Delete', 'bluefairy-api-gateway' ); ?></a>
				</td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>
