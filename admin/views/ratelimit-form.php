<?php
/**
 * Add / edit a rate-limit rule.
 *
 * @var array<string, mixed> $rule          The rule being edited, or defaults with id 0.
 * @var list<string>         $subject_types Allowed subject types.
 * @var bool                 $is_pro        Whether to show the penalty field.
 * @var string               $form_url      Where the form posts.
 * @var string               $cancel_url    Back to the tab.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

$bfag_editing = (int) $rule['id'] > 0;
$bfag_labels  = [
	'ip'     => __( 'IP address', 'bluefairy-api-gateway' ),
	'user'   => __( 'Logged-in user', 'bluefairy-api-gateway' ),
	'apikey' => __( 'API key', 'bluefairy-api-gateway' ),
];
?>
<h2><?php $bfag_editing ? esc_html_e( 'Edit rate-limit rule', 'bluefairy-api-gateway' ) : esc_html_e( 'Add rate-limit rule', 'bluefairy-api-gateway' ); ?></h2>
<form method="post" action="<?php echo esc_url( $form_url ); ?>" class="bfag-form">
	<input type="hidden" name="bfag_action" value="save_rate_limit_rule" />
	<input type="hidden" name="id" value="<?php echo esc_attr( (string) (int) $rule['id'] ); ?>" />
	<?php wp_nonce_field( 'bfag_save_rate_limit_rule' ); ?>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="bfag-rl-label"><?php esc_html_e( 'Label', 'bluefairy-api-gateway' ); ?></label></th>
			<td><input type="text" id="bfag-rl-label" name="bfag[label]" class="regular-text" maxlength="100" value="<?php echo esc_attr( (string) $rule['label'] ); ?>" /></td>
		</tr>
		<tr>
			<th scope="row"><label for="bfag-rl-subject"><?php esc_html_e( 'Count by', 'bluefairy-api-gateway' ); ?></label></th>
			<td>
				<select id="bfag-rl-subject" name="bfag[subject_type]">
					<?php foreach ( $subject_types as $bfag_type ) : ?>
						<option value="<?php echo esc_attr( $bfag_type ); ?>" <?php selected( $bfag_type, (string) $rule['subject_type'] ); ?>><?php echo esc_html( $bfag_labels[ $bfag_type ] ?? $bfag_type ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php if ( ! $is_pro ) : ?>
					<p class="description"><?php esc_html_e( 'Pro adds per-user and per-API-key limits.', 'bluefairy-api-gateway' ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="bfag-rl-glob"><?php esc_html_e( 'Route glob', 'bluefairy-api-gateway' ); ?></label></th>
			<td><input type="text" id="bfag-rl-glob" name="bfag[route_glob]" class="regular-text code" required value="<?php echo esc_attr( (string) $rule['route_glob'] ); ?>" placeholder="**" /></td>
		</tr>
		<tr>
			<th scope="row"><label for="bfag-rl-max"><?php esc_html_e( 'Requests', 'bluefairy-api-gateway' ); ?></label></th>
			<td>
				<input type="number" id="bfag-rl-max" name="bfag[max_requests]" class="small-text" min="1" max="65535" required value="<?php echo esc_attr( (string) (int) $rule['max_requests'] ); ?>" />
				<?php esc_html_e( 'per', 'bluefairy-api-gateway' ); ?>
				<input type="number" id="bfag-rl-window" name="bfag[window_seconds]" class="small-text" min="1" max="86400" required value="<?php echo esc_attr( (string) (int) $rule['window_seconds'] ); ?>" aria-label="<?php esc_attr_e( 'Window in seconds', 'bluefairy-api-gateway' ); ?>" />
				<?php esc_html_e( 'seconds', 'bluefairy-api-gateway' ); ?>
			</td>
		</tr>
		<?php if ( $is_pro ) : ?>
			<?php // The Pro mitigator bans IP addresses only: the row is shown, and the field enabled, for "Count by: IP address" alone (U-08; admin.js follows the select). ?>
			<tr data-bfag-penalty-row<?php echo 'ip' === (string) $rule['subject_type'] ? '' : ' hidden'; ?>>
				<th scope="row"><label for="bfag-rl-penalty"><?php esc_html_e( 'Penalty (seconds)', 'bluefairy-api-gateway' ); ?></label></th>
				<td>
					<input type="number" id="bfag-rl-penalty" name="bfag[penalty_seconds]" class="small-text" min="0" max="2592000" value="<?php echo esc_attr( (string) (int) ( $rule['penalty_seconds'] ?? 0 ) ); ?>"<?php disabled( 'ip' !== (string) $rule['subject_type'] ); ?> />
					<p class="description"><?php esc_html_e( 'IP-address rules only: how long the breaching address is banned. 0 disables.', 'bluefairy-api-gateway' ); ?></p>
				</td>
			</tr>
		<?php endif; ?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Enabled', 'bluefairy-api-gateway' ); ?></th>
			<td><label><input type="checkbox" name="bfag[enabled]" value="1" <?php checked( 1, (int) $rule['enabled'] ); ?> /> <?php esc_html_e( 'Apply this rule', 'bluefairy-api-gateway' ); ?></label></td>
		</tr>
	</table>
	<p class="submit">
		<?php submit_button( $bfag_editing ? __( 'Save rule', 'bluefairy-api-gateway' ) : __( 'Add rule', 'bluefairy-api-gateway' ), 'primary', 'bfag_save', false, [ 'id' => 'bfag-ratelimit-save' ] ); ?>
		<?php if ( $bfag_editing ) : ?>
			<a href="<?php echo esc_url( $cancel_url ); ?>" class="button"><?php esc_html_e( 'Cancel', 'bluefairy-api-gateway' ); ?></a>
		<?php endif; ?>
	</p>
</form>
