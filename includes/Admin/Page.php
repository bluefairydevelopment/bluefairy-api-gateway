<?php
/**
 * Base class for the admin tabs.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use BlueFairy\ApiGateway\Capabilities;

/**
 * One tab of the settings page.
 *
 * A tab owns a list of `bfag_action` names. handle_actions() is the ONLY
 * place a tab touches a superglobal: it verifies the nonce `bfag_<action>`
 * and the capability, reads the form into a sanitised array and hands that
 * to run_action(). A subclass therefore cannot forget the nonce — it never
 * sees the request.
 *
 * Every handler ends in redirect(), which sends the browser back to the tab
 * with `?bfag_notice=<code>` so a refresh cannot replay the POST.
 */
abstract class Page {

	public const MENU_SLUG = 'bfag-gateway';

	/**
	 * The tab id (`?tab=`).
	 */
	abstract public function tab(): string;

	/**
	 * The `bfag_action` names this tab handles.
	 *
	 * @return list<string>
	 */
	abstract public function actions(): array;

	/**
	 * Print the tab's content (inside the page wrapper).
	 */
	abstract public function render(): void;

	/**
	 * Run a verified action. $fields is `$_POST['bfag']` with every value
	 * passed through sanitize_textarea_field(), plus `id` (int) and `enabled`
	 * (string) when the request carried them as top-level query arguments.
	 * $raw is the whole unslashed `$_POST`, for the add-on's own fields.
	 *
	 * @param string               $action The action name, without the `bfag_` prefix.
	 * @param array<string, mixed> $fields Sanitised form fields.
	 * @param array<string, mixed> $raw    The whole unslashed POST body.
	 */
	abstract protected function run_action( string $action, array $fields, array $raw ): void;

	/**
	 * Hooked to admin_init: verify and dispatch this tab's `bfag_action`, if any.
	 */
	public function handle_actions(): void {
		$action = isset( $_REQUEST['bfag_action'] ) ? sanitize_key( wp_unslash( $_REQUEST['bfag_action'] ) ) : '';
		if ( '' === $action || ! in_array( $action, $this->actions(), true ) ) {
			return;
		}

		check_admin_referer( 'bfag_' . $action );
		if ( ! Capabilities::current_user_can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to manage the API Gateway.', 'bluefairy-api-gateway' ), '', [ 'response' => 403 ] );
		}

		$fields = isset( $_POST['bfag'] ) && is_array( $_POST['bfag'] )
			? array_map( 'sanitize_textarea_field', wp_unslash( $_POST['bfag'] ) )
			: [];
		if ( isset( $_REQUEST['id'] ) ) {
			$fields['id'] = absint( wp_unslash( $_REQUEST['id'] ) );
		}
		if ( isset( $_REQUEST['enabled'] ) ) {
			$fields['enabled'] = sanitize_key( wp_unslash( $_REQUEST['enabled'] ) );
		}

		// The add-on's `bfag_webhook_sanitize` / `bfag_settings_saved` callbacks sanitise their own keys (contract).
		$raw = wp_unslash( $_POST );

		$this->run_action( $action, $fields, is_array( $raw ) ? $raw : [] );
	}

	/**
	 * The URL of this tab with extra query arguments.
	 *
	 * @param array<string, string|int> $args Extra query arguments.
	 */
	public function url( array $args = [] ): string {
		return add_query_arg(
			array_merge(
				[
					'page' => self::MENU_SLUG,
					'tab'  => $this->tab(),
				],
				$args
			),
			admin_url( 'options-general.php' )
		);
	}

	/**
	 * A link that performs an action on one row, nonce included.
	 *
	 * @param string                    $action The action name, without the `bfag_` prefix.
	 * @param int                       $id     Row id.
	 * @param array<string, string|int> $args   Extra query arguments.
	 */
	public function action_url( string $action, int $id, array $args = [] ): string {
		return wp_nonce_url(
			$this->url(
				array_merge(
					[
						'bfag_action' => $action,
						'id'          => $id,
					],
					$args
				)
			),
			'bfag_' . $action
		);
	}

	/**
	 * Send the browser back to this tab with a notice code, and stop.
	 *
	 * @param string                    $notice Notice code (see AdminMenu::notices()).
	 * @param array<string, string|int> $args   Extra query arguments.
	 */
	protected function redirect( string $notice, array $args = [] ): never {
		wp_safe_redirect( $this->url( array_merge( [ 'bfag_notice' => $notice ], $args ) ) );
		exit;
	}

	/**
	 * Include a view from admin/views/ with the given variables in scope.
	 *
	 * @param string               $name Basename of the view, without `.php`.
	 * @param array<string, mixed> $vars Variables the view documents in its docblock.
	 */
	protected function view( string $name, array $vars ): void {
		self::include_view( $name, $vars );
	}

	/**
	 * Include a view with variables in scope (also used by the menu shell).
	 *
	 * @param string               $name Basename of the view, without `.php`.
	 * @param array<string, mixed> $vars Variables the view documents in its docblock.
	 */
	public static function include_view( string $name, array $vars ): void {
		$file = BFAG_DIR . 'admin/views/' . $name . '.php';
		if ( ! is_readable( $file ) ) {
			return;
		}
		$render = static function () use ( $file, $vars ): void {
			foreach ( $vars as $key => $value ) {
				${ $key } = $value;
			}
			include $file;
		};
		$render();
	}

	/**
	 * Seconds a failed form submission is kept for one re-render (U-11).
	 */
	public const FORM_TTL = 60;

	/**
	 * Park the submitted fields so the tab can re-fill its form once after a
	 * failed save, instead of discarding what the admin typed. Keyed per
	 * user AND per tab (N-15: a refused webhook save must not re-fill the
	 * cache rule form); read back (and deleted) by take_form().
	 *
	 * @param array<string, mixed> $fields The sanitised form fields.
	 */
	protected function remember_form( array $fields ): void {
		set_transient( $this->form_transient(), $fields, self::FORM_TTL );
	}

	/**
	 * The fields parked by the current user's last failed save on this tab,
	 * deleted on read so a later visit shows a blank form again.
	 *
	 * @return array<string, mixed>|null
	 */
	protected function take_form(): ?array {
		$transient = $this->form_transient();
		$fields    = get_transient( $transient );
		if ( ! is_array( $fields ) ) {
			return null;
		}
		delete_transient( $transient );
		return $fields;
	}

	/**
	 * Transient carrying the current user's last failed submission on this
	 * tab: `bfag_form_<user_id>_<tab>`.
	 */
	private function form_transient(): string {
		return 'bfag_form_' . get_current_user_id() . '_' . $this->tab();
	}

	/**
	 * A stored GMT datetime rendered in the site's timezone and the site's
	 * date and time formats (U-12). Every timestamp a view prints goes
	 * through here so the admin never reads a bare GMT value as local time.
	 *
	 * @param string $gmt A `Y-m-d H:i:s` GMT datetime from a table.
	 */
	public static function format_datetime( string $gmt ): string {
		$format = (string) get_option( 'date_format', 'Y-m-d' ) . ' ' . (string) get_option( 'time_format', 'H:i' );
		$local  = strtotime( get_date_from_gmt( $gmt ) );
		return date_i18n( $format, false === $local ? 0 : $local );
	}

	/**
	 * Checkbox-tolerant boolean for a form field.
	 *
	 * @param array<string, mixed> $fields Sanitised fields.
	 * @param string               $key    Field name.
	 * @param bool                 $missing What an absent field means.
	 */
	protected static function flag( array $fields, string $key, bool $missing = false ): bool {
		if ( ! array_key_exists( $key, $fields ) ) {
			return $missing;
		}
		return \BlueFairy\ApiGateway\Settings::to_bool( $fields[ $key ] );
	}
}
