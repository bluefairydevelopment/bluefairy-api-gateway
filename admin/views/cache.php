<?php
/**
 * Cache rules table and Purge all.
 *
 * @var list<array<string, mixed>>               $rules     Rule rows.
 * @var int                                      $count     Rules stored.
 * @var int                                      $limit     Rules allowed.
 * @var \BlueFairy\ApiGateway\Admin\CachePage    $page      For url() / action_url().
 * @var string                                   $purge_url Nonce URL of the Purge all action.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;
?>
<div class="bfag-toolbar">
	<h2><?php esc_html_e( 'Cache rules', 'bluefairy-api-gateway' ); ?></h2>
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
	<a href="<?php echo esc_url( $purge_url ); ?>" class="button" data-bfag-purge><?php esc_html_e( 'Purge all', 'bluefairy-api-gateway' ); ?></a>
	<span class="bfag-status" data-bfag-status aria-live="polite"></span>
</div>
<p class="description"><?php esc_html_e( 'Anonymous GET responses are cached when a rule matches the route. Rules are the allow-list: with none enabled nothing is cached. Globs: * one segment, ** anything, ? one character.', 'bluefairy-api-gateway' ); ?></p>

<table class="widefat striped bfag-table">
	<thead>
		<tr>
			<th><?php esc_html_e( 'Label', 'bluefairy-api-gateway' ); ?></th>
			<th><?php esc_html_e( 'Route glob', 'bluefairy-api-gateway' ); ?></th>
			<th><?php esc_html_e( 'TTL', 'bluefairy-api-gateway' ); ?></th>
			<th><?php esc_html_e( 'Tags', 'bluefairy-api-gateway' ); ?></th>
			<th><?php esc_html_e( 'Enabled', 'bluefairy-api-gateway' ); ?></th>
			<th><?php esc_html_e( 'Actions', 'bluefairy-api-gateway' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php if ( [] === $rules ) : ?>
			<tr><td colspan="6"><?php esc_html_e( 'No cache rules yet.', 'bluefairy-api-gateway' ); ?></td></tr>
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
				<td><code><?php echo esc_html( (string) $bfag_rule['route_glob'] ); ?></code></td>
				<td>
					<?php
					printf(
						/* translators: %d: seconds */
						esc_html__( '%d s', 'bluefairy-api-gateway' ),
						(int) $bfag_rule['ttl_seconds']
					);
					?>
				</td>
				<td><?php echo esc_html( '' === (string) $bfag_rule['tags'] ? '—' : str_replace( ',', ', ', (string) $bfag_rule['tags'] ) ); ?></td>
				<td>
					<a href="<?php echo esc_url( $page->action_url( 'toggle_cache_rule', $bfag_id, [ 'enabled' => $bfag_enabled ? 0 : 1 ] ) ); ?>"
						class="bfag-badge <?php echo $bfag_enabled ? 'bfag-badge--success' : 'bfag-badge--muted'; ?>"
						data-bfag-toggle="cache" data-bfag-id="<?php echo esc_attr( (string) $bfag_id ); ?>" data-bfag-enabled="<?php echo $bfag_enabled ? '1' : '0'; ?>">
						<?php $bfag_enabled ? esc_html_e( 'Enabled', 'bluefairy-api-gateway' ) : esc_html_e( 'Disabled', 'bluefairy-api-gateway' ); ?>
					</a>
				</td>
				<td>
					<a href="<?php echo esc_url( $bfag_edit_url ); ?>"><?php esc_html_e( 'Edit', 'bluefairy-api-gateway' ); ?></a> |
					<a href="<?php echo esc_url( $page->action_url( 'delete_cache_rule', $bfag_id ) ); ?>" class="bfag-delete" data-bfag-confirm><?php esc_html_e( 'Delete', 'bluefairy-api-gateway' ); ?></a>
				</td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>
