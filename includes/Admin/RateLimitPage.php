<?php
/**
 * The Rate limit tab.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Admin;

use BlueFairy\ApiGateway\RateLimit\RateLimitRuleRepository;

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

		/**
		 * Rendered below the rate-limit rules table (Pro's Blocked IPs panel).
		 *
		 * @since 1.0.0
		 */
		do_action( 'bfag_ratelimit_page_after' );

		if ( null === $editing && $at_limit ) {
			echo '<p class="bfag-limit-line">' . esc_html( $this->upsell->limit_line( sprintf( /* translators: %d: number of rules */ _n( '%d rate-limit rule', '%d rate-limit rules', $limit, 'bluefairy-api-gateway' ), $limit ) ) ) . '</p>';
			return;
		}

		$this->view(
			'ratelimit-form',
			[
				'rule'          => $editing ?? [
					'id'              => 0,
					'label'           => '',
					'subject_type'    => 'ip',
					'route_glob'      => '',
					'max_requests'    => 60,
					'window_seconds'  => 60,
					'penalty_seconds' => 0,
					'enabled'         => 1,
				],
				'subject_types' => $this->rules->subject_types(),
				'is_pro'        => $this->upsell->is_pro(),
				'form_url'      => $this->url(),
				'cancel_url'    => $this->url(),
			]
		);
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
		$subject_type = sanitize_key( (string) ( $fields['subject_type'] ?? 'ip' ) );
		if ( ! in_array( $subject_type, $this->rules->subject_types(), true ) ) {
			$this->redirect( 'ratelimit_invalid' );
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
			$data['penalty_seconds'] = (string) $fields['penalty_seconds'];
		}

		if ( $id > 0 ) {
			if ( null === $this->rules->find( $id ) ) {
				$this->redirect( 'ratelimit_not_found' );
			}
			$this->redirect( $this->rules->update( $id, $data ) ? 'ratelimit_saved' : 'ratelimit_invalid' );
		}

		if ( $this->rules->count() >= $this->rules->limit() ) {
			$this->redirect( 'ratelimit_limit' );
		}
		$created = $this->rules->create( $data );
		$this->redirect( false === $created ? 'ratelimit_invalid' : 'ratelimit_saved' );
	}
}
