<?php
/**
 * Add / edit a webhook. The secret is never in $webhook.
 *
 * @var array<string, mixed>                                                       $webhook    The row being edited (without `secret`), or defaults with id 0.
 * @var array<string, array{hook:string, label:string, accepted_args:int, group:string}> $events Known events.
 * @var \BlueFairy\ApiGateway\Webhook\EventRegistry                              $registry   Translated event and group labels.
 * @var array<string, string>                                                      $presets    Preset slug => label; empty hides the select.
 * @var string                                                                     $headers    Custom headers as `Name: value` lines.
 * @var bool                                                                       $is_pro     Whether to show max_retries.
 * @var string                                                                     $form_url   Where the form posts.
 * @var string                                                                     $cancel_url Back to the tab.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

$bfag_editing = (int) $webhook['id'] > 0;
$bfag_groups  = [];
foreach ( $events as $bfag_key => $bfag_spec ) {
	$bfag_groups[ $registry->group_label( $bfag_spec['group'] ) ][ $bfag_key ] = $registry->label( $bfag_key );
}
?>
<h2><?php $bfag_editing ? esc_html_e( 'Edit webhook', 'bluefairy-api-gateway' ) : esc_html_e( 'Add webhook', 'bluefairy-api-gateway' ); ?></h2>
<form method="post" action="<?php echo esc_url( $form_url ); ?>" class="bfag-form" autocomplete="off">
	<input type="hidden" name="bfag_action" value="save_webhook" />
	<input type="hidden" name="id" value="<?php echo esc_attr( (string) (int) $webhook['id'] ); ?>" />
	<?php wp_nonce_field( 'bfag_save_webhook' ); ?>
	<table class="form-table" role="presentation">
		<?php // Two row groups with the add-on hook between them, right under Preset (U-14 / WPMAP-113): an add-on may print <tr> rows or its own <tbody> groups. ?>
		<tbody>
		<tr>
			<th scope="row"><label for="bfag-wh-label"><?php esc_html_e( 'Label', 'bluefairy-api-gateway' ); ?></label></th>
			<td><input type="text" id="bfag-wh-label" name="bfag[label]" class="regular-text" maxlength="200" required value="<?php echo esc_attr( (string) $webhook['label'] ); ?>" /></td>
		</tr>
		<tr>
			<th scope="row"><label for="bfag-wh-url"><?php esc_html_e( 'URL', 'bluefairy-api-gateway' ); ?></label></th>
			<td>
				<?php // Required unless a preset is chosen: a preset builds its own request (U-14); admin.js follows the Preset select. ?>
				<input type="url" id="bfag-wh-url" name="bfag[url]" class="large-text code" data-bfag-preset-url<?php echo '' === (string) $webhook['preset'] ? ' required' : ''; ?> value="<?php echo esc_attr( (string) $webhook['url'] ); ?>" placeholder="https://" />
				<p class="description"><?php esc_html_e( 'https only. Redirects are not followed.', 'bluefairy-api-gateway' ); ?></p>
				<?php if ( [] !== $presets ) : ?>
					<p class="description"><?php esc_html_e( 'Optional with a preset. If you remove the preset later, deliveries go to this URL as plain signed JSON.', 'bluefairy-api-gateway' ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="bfag-wh-event"><?php esc_html_e( 'Event', 'bluefairy-api-gateway' ); ?></label></th>
			<td>
				<select id="bfag-wh-event" name="bfag[event]" required>
					<option value=""><?php esc_html_e( '— Select —', 'bluefairy-api-gateway' ); ?></option>
					<?php foreach ( $bfag_groups as $bfag_group => $bfag_options ) : ?>
						<optgroup label="<?php echo esc_attr( $bfag_group ); ?>">
							<?php foreach ( $bfag_options as $bfag_key => $bfag_label ) : ?>
								<option value="<?php echo esc_attr( $bfag_key ); ?>" <?php selected( $bfag_key, (string) $webhook['event'] ); ?>><?php echo esc_html( $bfag_label ); ?></option>
							<?php endforeach; ?>
						</optgroup>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="bfag-wh-secret"><?php esc_html_e( 'Signing secret', 'bluefairy-api-gateway' ); ?></label></th>
			<td>
				<input type="password" id="bfag-wh-secret" name="bfag[secret]" class="regular-text code" maxlength="255" value="" autocomplete="new-password" />
				<p class="description">
					<?php if ( $bfag_editing ) : ?>
						<?php esc_html_e( 'Leave blank to keep the current secret. The stored secret is never shown.', 'bluefairy-api-gateway' ); ?>
					<?php else : ?>
						<?php esc_html_e( 'Leave blank and a secret is generated and shown to you once after saving.', 'bluefairy-api-gateway' ); ?>
					<?php endif; ?>
				</p>
				<?php if ( $bfag_editing ) : ?>
					<label><input type="checkbox" name="bfag[regenerate_secret]" value="1" /> <?php esc_html_e( 'Generate a new secret', 'bluefairy-api-gateway' ); ?></label>
					<p class="description"><?php esc_html_e( 'Replaces the secret — deliveries fail at the receiver until it is updated. The new secret is shown once after saving.', 'bluefairy-api-gateway' ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="bfag-wh-headers"><?php esc_html_e( 'Extra headers', 'bluefairy-api-gateway' ); ?></label></th>
			<td>
				<textarea id="bfag-wh-headers" name="bfag[headers]" class="large-text code" rows="3" placeholder="X-Api-Key: …"><?php echo esc_textarea( $headers ); ?></textarea>
				<p class="description"><?php esc_html_e( 'One per line as Name: value. Host, Cookie, Authorization and hop-by-hop headers are dropped.', 'bluefairy-api-gateway' ); ?></p>
			</td>
		</tr>
		<?php if ( [] !== $presets ) : ?>
			<tr>
				<th scope="row"><label for="bfag-wh-preset"><?php esc_html_e( 'Preset', 'bluefairy-api-gateway' ); ?></label></th>
				<td>
					<select id="bfag-wh-preset" name="bfag[preset]" data-bfag-preset-select>
						<option value=""><?php esc_html_e( 'None (plain JSON)', 'bluefairy-api-gateway' ); ?></option>
						<?php foreach ( $presets as $bfag_slug => $bfag_label ) : ?>
							<option value="<?php echo esc_attr( (string) $bfag_slug ); ?>" <?php selected( (string) $bfag_slug, (string) $webhook['preset'] ); ?>><?php echo esc_html( (string) $bfag_label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
		<?php endif; ?>
		</tbody>
		<?php
		/**
		 * Extra rows in the add/edit webhook form, rendered directly under the
		 * Preset row (where the Preset row would be when no preset is
		 * registered) and before Max retries and Enabled, between two
		 * `<tbody>` row groups: print `<tr>` rows or `<tbody>` groups.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $webhook The row being edited (without its secret), or defaults with id 0.
		 */
		do_action( 'bfag_webhook_form_fields', $webhook );
		?>
		<tbody>
		<?php if ( $is_pro ) : ?>
			<tr>
				<th scope="row"><label for="bfag-wh-retries"><?php esc_html_e( 'Max retries', 'bluefairy-api-gateway' ); ?></label></th>
				<td>
					<input type="number" id="bfag-wh-retries" name="bfag[max_retries]" class="small-text" min="0" max="10" value="<?php echo esc_attr( (string) (int) $webhook['max_retries'] ); ?>" />
					<p class="description"><?php esc_html_e( 'Automatic retries after a failed delivery (Pro). 0 = none.', 'bluefairy-api-gateway' ); ?></p>
				</td>
			</tr>
		<?php endif; ?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Enabled', 'bluefairy-api-gateway' ); ?></th>
			<td><label><input type="checkbox" name="bfag[enabled]" value="1" <?php checked( 1, (int) $webhook['enabled'] ); ?> /> <?php esc_html_e( 'Deliver this webhook', 'bluefairy-api-gateway' ); ?></label></td>
		</tr>
		</tbody>
	</table>
	<p class="submit">
		<?php submit_button( $bfag_editing ? __( 'Save webhook', 'bluefairy-api-gateway' ) : __( 'Add webhook', 'bluefairy-api-gateway' ), 'primary', 'bfag_save', false, [ 'id' => 'bfag-webhook-save' ] ); ?>
		<?php if ( $bfag_editing ) : ?>
			<a href="<?php echo esc_url( $cancel_url ); ?>" class="button"><?php esc_html_e( 'Cancel', 'bluefairy-api-gateway' ); ?></a>
		<?php endif; ?>
	</p>
</form>
