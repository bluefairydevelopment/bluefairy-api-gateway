<?php
/**
 * The Rate limit tab.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use BlueFairy\ApiGateway\RateLimit\RateLimitRuleRepository;
use BlueFairy\ApiGateway\Support\GlobMatcher;

/**
 * Rules table and add/edit form. The subject-type select offers only what
 * `subject_types()` allows and the penalty field appears only with Pro, so
 * the free form cannot even express what the free repository would refuse.
 */
final class RateLimitPage extends Page {

	public const TAB = 'ratelimit';

	/**
	 * Constructor.
	 *
	 * @param RateLimitRuleRepository $rules  Rate-limit rules.
	 * @param ProUpsell               $upsell Pro state and copy.
	 */
	public function __construct(
		private readonly RateLimitRuleRepository $rules,
		private readonly ProUpsell $upsell
	) {}

	/**
	 * Tab id.
	 */
	public function tab(): string {
		return self::TAB;
	}

	/**
	 * Actions this tab handles.
	 *
	 * @return list<string>
	 */
	public function actions(): array {
		return [ 'save_rate_limit_rule', 'delete_rate_limit_rule', 'toggle_rate_limit_rule' ];
	}

	/**
	 * Print the tab.
	 */
	public function render(): void {
		$rules    = $this->rules->all();
		$count    = $this->rules->count();
		$limit    = $this->rules->limit();
		$at_limit = $count >= $limit;

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display-only: which row to show in the edit form.
		$edit_id = isset( $_GET['action'], $_GET['id'] ) && 'edit' === sanitize_key( wp_unslash( $_GET['action'] ) ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
		// phpcs:enable
		$editing = $edit_id > 0 ? $this->rules->find( $edit_id ) : null;

		$this->view(
			'ratelimit',
			[
				'rules'  => $rules,
				'count'  => $count,
				'limit'  => $limit,
				'page'   => $this,
				'is_pro' => $this->upsell->is_pro(),
			]
		);

		if ( null === $editing && $at_limit ) {
			echo '<p class="bfag-limit-line">' . esc_html( $this->upsell->limit_line( sprintf( /* translators: %d: number of rules */ _n( '%d rate-limit rule', '%d rate-limit rules', $limit, 'bluefairy-api-gateway' ), $limit ) ) ) . '</p>';
		} else {
			// A failed save re-fills the form once with what was typed (U-11); otherwise the row, or the defaults.
			$rule = $editing ?? [
				'id'              => 0,
				'label'           => '',
				'subject_type'    => 'ip',
				'route_glob'      => '',
				'max_requests'    => 60,
				'window_seconds'  => 60,
				'penalty_seconds' => 0,
				'enabled'         => 1,
			];
			$this->view(
				'ratelimit-form',
				[
					'rule'          => array_merge( $rule, $this->take_form() ?? [] ),
					'subject_types' => $this->rules->subject_types(),
					'is_pro'        => $this->upsell->is_pro(),
					'form_url'      => $this->url(),
					'cancel_url'    => $this->url(),
				]
			);
		}

		/**
		 * Rendered below the rate-limit rules table and the add/edit form
		 * (Pro's Blocked IPs panel) (U-20).
		 *
		 * @since 1.0.0
		 */
		do_action( 'bfag_ratelimit_page_after' );
	}

	/**
	 * Dispatch a verified action.
	 *
	 * @param string               $action Action name.
	 * @param array<string, mixed> $fields Sanitised fields.
	 * @param array<string, mixed> $raw    Unslashed POST (unused here).
	 */
	protected function run_action( string $action, array $fields, array $raw ): void {
		unset( $raw );
		$id = (int) ( $fields['id'] ?? 0 );

		switch ( $action ) {
			case 'save_rate_limit_rule':
				$this->save( $id, $fields );
				// Unreachable: save() redirects.
			case 'delete_rate_limit_rule':
				if ( $id <= 0 || null === $this->rules->find( $id ) ) {
					$this->redirect( 'ratelimit_not_found' );
				}
				$this->redirect( $this->rules->delete( $id ) ? 'ratelimit_deleted' : 'error' );
				// Unreachable.
			case 'toggle_rate_limit_rule':
				if ( $id <= 0 || null === $this->rules->find( $id ) ) {
					$this->redirect( 'ratelimit_not_found' );
				}
				$this->redirect( $this->rules->set_enabled( $id, self::flag( $fields, 'enabled' ) ) ? 'ratelimit_toggled' : 'error' );
				// Unreachable.
			default:
				$this->redirect( 'error' );
		}
	}

	/**
	 * Create or update a rule from the form, then redirect with the outcome.
	 *
	 * @param int                  $id     Rule id, 0 to create.
	 * @param array<string, mixed> $fields Sanitised fields.
	 */
	private function save( int $id, array $fields ): never {
		if ( $id > 0 && null === $this->rules->find( $id ) ) {
			$this->redirect( 'ratelimit_not_found' );
		}
		$subject_type = sanitize_key( (string) ( $fields['subject_type'] ?? 'ip' ) );
		if ( ! in_array( $subject_type, $this->rules->subject_types(), true ) ) {
			$this->fail( 'ratelimit_invalid', $id, $fields );
		}
		// The notice names the failing field (U-11): the glob and the ranges are checked here, the repository re-checks on write.
		if ( ! GlobMatcher::is_valid( (string) ( $fields['route_glob'] ?? '' ) ) ) {
			$this->fail( 'ratelimit_glob_invalid', $id, $fields );
		}
		foreach ( RateLimitRuleRepository::BOUNDS as $column => [ $min, $max ] ) {
			$value = $fields[ $column ] ?? ( 'penalty_seconds' === $column ? '0' : '' );
			if ( ! is_numeric( $value ) || (int) $value < $min || (int) $value > $max ) {
				$this->fail( 'ratelimit_range_invalid', $id, $fields );
			}
		}

		$data = [
			'label'          => (string) ( $fields['label'] ?? '' ),
			'subject_type'   => $subject_type,
			'route_glob'     => (string) ( $fields['route_glob'] ?? '' ),
			'max_requests'   => (string) ( $fields['max_requests'] ?? '' ),
			'window_seconds' => (string) ( $fields['window_seconds'] ?? '' ),
			'enabled'        => self::flag( $fields, 'enabled' ) ? 1 : 0,
		];
		if ( $this->upsell->is_pro() && array_key_exists( 'penalty_seconds', $fields ) ) {
			// The Pro mitigator bans IP addresses only: a penalty on a per-user or per-key rule would never apply (U-08).
			$data['penalty_seconds'] = 'ip' === $subject_type ? (string) $fields['penalty_seconds'] : '0';
		}

		if ( $id > 0 ) {
			if ( ! $this->rules->update( $id, $data ) ) {
				$this->fail( 'ratelimit_invalid', $id, $fields );
			}
			$this->redirect( 'ratelimit_saved' );
		}

		if ( $this->rules->count() >= $this->rules->limit() ) {
			$this->redirect( 'ratelimit_limit' );
		}
		if ( false === $this->rules->create( $data ) ) {
			$this->fail( 'ratelimit_invalid', 0, $fields );
		}
		$this->redirect( 'ratelimit_saved' );
	}

	/**
	 * A refused save: keep what was typed for one re-render and go back to
	 * the form (the edit form when a row was being edited).
	 *
	 * @param string               $notice Notice code.
	 * @param int                  $id     Rule id being edited, 0 when adding.
	 * @param array<string, mixed> $fields The submitted fields.
	 */
	private function fail( string $notice, int $id, array $fields ): never {
		unset( $fields['id'] );
		$this->remember_form( $fields );
		$this->redirect(
			$notice,
			$id > 0 ? [
				'action' => 'edit',
				'id'     => $id,
			] : []
		);
	}
}
