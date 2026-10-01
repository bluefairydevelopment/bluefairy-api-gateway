<?php
/**
 * Add / edit a cache rule.
 *
 * @var array<string, mixed> $rule       The rule being edited, or defaults with id 0.
 * @var string               $form_url   Where the form posts.
 * @var string               $cancel_url Back to the tab.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

$bfag_editing = (int) $rule['id'] > 0;
?>
<h2><?php $bfag_editing ? esc_html_e( 'Edit cache rule', 'bluefairy-api-gateway' ) : esc_html_e( 'Add cache rule', 'bluefairy-api-gateway' ); ?></h2>
<form method="post" action="<?php echo esc_url( $form_url ); ?>" class="bfag-form">
	<input type="hidden" name="bfag_action" value="save_cache_rule" />
	<input type="hidden" name="id" value="<?php echo esc_attr( (string) (int) $rule['id'] ); ?>" />
	<?php wp_nonce_field( 'bfag_save_cache_rule' ); ?>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="bfag-cache-label"><?php esc_html_e( 'Label', 'bluefairy-api-gateway' ); ?></label></th>
			<td><input type="text" id="bfag-cache-label" name="bfag[label]" class="regular-text" maxlength="100" value="<?php echo esc_attr( (string) $rule['label'] ); ?>" /></td>
		</tr>
		<tr>
			<th scope="row"><label for="bfag-cache-glob"><?php esc_html_e( 'Route glob', 'bluefairy-api-gateway' ); ?></label></th>
			<td>
				<input type="text" id="bfag-cache-glob" name="bfag[route_glob]" class="regular-text code" required value="<?php echo esc_attr( (string) $rule['route_glob'] ); ?>" placeholder="wp/v2/posts/**" />
				<p class="description"><?php esc_html_e( 'Matched against the REST route without its leading slash. A trailing /* or /** also matches the collection itself: wc/v3/products/* matches wc/v3/products and wc/v3/products/12; wp/v2/posts/** matches wp/v2/posts and everything beneath it.', 'bluefairy-api-gateway' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="bfag-cache-ttl"><?php esc_html_e( 'TTL (seconds)', 'bluefairy-api-gateway' ); ?></label></th>
			<td><input type="number" id="bfag-cache-ttl" name="bfag[ttl_seconds]" class="small-text" min="1" max="86400" required value="<?php echo esc_attr( (string) (int) $rule['ttl_seconds'] ); ?>" /></td>
		</tr>
		<tr>
			<th scope="row"><label for="bfag-cache-tags"><?php esc_html_e( 'Tags', 'bluefairy-api-gateway' ); ?></label></th>
			<td>
				<input type="text" id="bfag-cache-tags" name="bfag[tags]" class="regular-text code" value="<?php echo esc_attr( (string) $rule['tags'] ); ?>" placeholder="products, posts" />
				<p class="description"><?php esc_html_e( 'Comma-separated. Purging a tag orphans every response cached under it. A rule with no tags is purged on every content change; add tags to narrow purging to those tags.', 'bluefairy-api-gateway' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Enabled', 'bluefairy-api-gateway' ); ?></th>
			<td><label><input type="checkbox" name="bfag[enabled]" value="1" <?php checked( 1, (int) $rule['enabled'] ); ?> /> <?php esc_html_e( 'Apply this rule', 'bluefairy-api-gateway' ); ?></label></td>
		</tr>
	</table>
	<p class="submit">
		<?php submit_button( $bfag_editing ? __( 'Save rule', 'bluefairy-api-gateway' ) : __( 'Add rule', 'bluefairy-api-gateway' ), 'primary', 'bfag_save', false, [ 'id' => 'bfag-cache-save' ] ); ?>
		<?php if ( $bfag_editing ) : ?>
			<a href="<?php echo esc_url( $cancel_url ); ?>" class="button"><?php esc_html_e( 'Cancel', 'bluefairy-api-gateway' ); ?></a>
		<?php endif; ?>
	</p>
</form>
