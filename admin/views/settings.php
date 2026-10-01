<?php
/**
 * The Settings form.
 *
 * @var array<string, array{label:string, fields:array<string, array{label:string, type:string, description:string, min?:int, max?:int, options?:array<string, string>}>}> $groups Field groups.
 * @var array<string, mixed>                $values   Current settings.
 * @var \BlueFairy\ApiGateway\Settings      $settings The Settings instance, for `bfag_settings_fields`.
 * @var string                              $form_url Where the form posts.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;
?>
<form method="post" action="<?php echo esc_url( $form_url ); ?>" class="bfag-form">
	<input type="hidden" name="bfag_action" value="save_settings" />
	<?php wp_nonce_field( 'bfag_save_settings' ); ?>
	<table class="form-table" role="presentation">
		<?php foreach ( $groups as $bfag_group_id => $bfag_group ) : ?>
			<tr class="bfag-group-heading"><th colspan="2"><h2 id="bfag-group-<?php echo esc_attr( $bfag_group_id ); ?>"><?php echo esc_html( $bfag_group['label'] ); ?></h2></th></tr>
			<?php foreach ( $bfag_group['fields'] as $bfag_key => $bfag_field ) : ?>
				<?php
				$bfag_id    = 'bfag-setting-' . str_replace( '_', '-', $bfag_key );
				$bfag_value = $values[ $bfag_key ] ?? '';
				?>
				<tr>
					<th scope="row"><label for="<?php echo esc_attr( $bfag_id ); ?>"><?php echo esc_html( $bfag_field['label'] ); ?></label></th>
					<td>
						<?php if ( 'checkbox' === $bfag_field['type'] ) : ?>
							<label><input type="checkbox" id="<?php echo esc_attr( $bfag_id ); ?>" name="bfag[<?php echo esc_attr( $bfag_key ); ?>]" value="1" <?php checked( true, \BlueFairy\ApiGateway\Settings::to_bool( $bfag_value ) ); ?> /> <?php echo esc_html( $bfag_field['description'] ); ?></label>
						<?php elseif ( 'textarea' === $bfag_field['type'] ) : ?>
							<textarea id="<?php echo esc_attr( $bfag_id ); ?>" name="bfag[<?php echo esc_attr( $bfag_key ); ?>]" class="large-text code" rows="4"><?php echo esc_textarea( is_array( $bfag_value ) ? implode( "\n", $bfag_value ) : (string) $bfag_value ); ?></textarea>
							<p class="description"><?php echo esc_html( $bfag_field['description'] ); ?></p>
						<?php elseif ( 'select' === $bfag_field['type'] ) : ?>
							<select id="<?php echo esc_attr( $bfag_id ); ?>" name="bfag[<?php echo esc_attr( $bfag_key ); ?>]">
								<?php foreach ( $bfag_field['options'] ?? [] as $bfag_option => $bfag_option_label ) : ?>
									<option value="<?php echo esc_attr( $bfag_option ); ?>" <?php selected( $bfag_option, is_scalar( $bfag_value ) ? (string) $bfag_value : '' ); ?>><?php echo esc_html( $bfag_option_label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php echo esc_html( $bfag_field['description'] ); ?></p>
						<?php elseif ( 'number' === $bfag_field['type'] ) : ?>
							<input type="number" id="<?php echo esc_attr( $bfag_id ); ?>" name="bfag[<?php echo esc_attr( $bfag_key ); ?>]" class="regular-text" value="<?php echo esc_attr( (string) (int) $bfag_value ); ?>" min="<?php echo esc_attr( (string) ( $bfag_field['min'] ?? 0 ) ); ?>" max="<?php echo esc_attr( (string) ( $bfag_field['max'] ?? PHP_INT_MAX ) ); ?>" />
							<p class="description"><?php echo esc_html( $bfag_field['description'] ); ?></p>
						<?php else : ?>
							<input type="<?php echo esc_attr( $bfag_field['type'] ); ?>" id="<?php echo esc_attr( $bfag_id ); ?>" name="bfag[<?php echo esc_attr( $bfag_key ); ?>]" class="regular-text" value="<?php echo esc_attr( is_scalar( $bfag_value ) ? (string) $bfag_value : '' ); ?>" />
							<p class="description"><?php echo esc_html( $bfag_field['description'] ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		<?php endforeach; ?>
		<?php
		/**
		 * Extra settings rows (`<tr>`s inside the form table).
		 *
		 * @since 1.0.0
		 *
		 * @param \BlueFairy\ApiGateway\Settings $settings The settings.
		 */
		do_action( 'bfag_settings_fields', $settings );
		?>
	</table>
	<?php submit_button( __( 'Save settings', 'bluefairy-api-gateway' ), 'primary', 'bfag_save', true, [ 'id' => 'bfag-settings-save' ] ); ?>
</form>
