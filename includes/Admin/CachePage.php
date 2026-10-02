<?php
/**
 * The Cache tab.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use BlueFairy\ApiGateway\Cache\CachePurger;
use BlueFairy\ApiGateway\Cache\CacheRuleRepository;
use BlueFairy\ApiGateway\Settings;

/**
 * Rules table, add/edit form, delete, toggle and Purge all.
 *
 * Validation lives in CacheRuleRepository; this page turns its refusals
 * into notices and never stores anything itself. When the rule limit is
 * reached the add form gives way to Page::limit_line().
 */
final class CachePage extends Page {

	public const TAB = 'cache';

	/**
	 * Constructor.
	 *
	 * @param CacheRuleRepository $rules    Cache rules.
	 * @param CachePurger         $purger   The one purge entry point (Purge all).
	 * @param Settings            $settings `default_cache_ttl` pre-fills the add form (U-09).
	 */
	public function __construct(
		private readonly CacheRuleRepository $rules,
		private readonly CachePurger $purger,
		private readonly Settings $settings
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
		return [ 'save_cache_rule', 'delete_cache_rule', 'toggle_cache_rule', 'purge_cache' ];
	}

	/**
	 * Print the tab.
	 */
	public function render(): void {
		$rules    = $this->rules->sort_by_specificity( $this->rules->all() );
		$count    = $this->rules->count();
		$limit    = $this->rules->limit();
		$at_limit = $count >= $limit;

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display-only: which row to show in the edit form.
		$edit_id = isset( $_GET['action'], $_GET['id'] ) && 'edit' === sanitize_key( wp_unslash( $_GET['action'] ) ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
		// phpcs:enable
		$editing = $edit_id > 0 ? $this->rules->find( $edit_id ) : null;

		$this->view(
			'cache',
			[
				'rules'     => $rules,
				'count'     => $count,
				'limit'     => $limit,
				'page'      => $this,
				'purge_url' => $this->action_url( 'purge_cache', 0 ),
			]
		);

		if ( null === $editing && $at_limit ) {
			echo '<p class="bfag-limit-line">' . esc_html( self::limit_line( sprintf( /* translators: %d: number of rules */ _n( '%d cache rule', '%d cache rules', $limit, 'bluefairy-api-gateway' ), $limit ) ) ) . '</p>';
		} else {
			// A failed save re-fills the form once with what was typed (U-11); otherwise the row, or the defaults.
			$rule = $editing ?? [
				'id'          => 0,
				'label'       => '',
				'route_glob'  => '',
				'ttl_seconds' => (int) $this->settings->get( 'default_cache_ttl' ),
				'tags'        => '',
				'enabled'     => 1,
			];
			$this->view(
				'cache-form',
				[
					'rule'       => array_merge( $rule, $this->take_form() ?? [] ),
					'form_url'   => $this->url(),
					'cancel_url' => $this->url(),
				]
			);
		}

		/**
		 * Rendered below the cache rules table and the add/edit form (U-20).
		 *
		 * @since 1.0.0
		 */
		do_action( 'bfag_cache_page_after' );
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
			case 'save_cache_rule':
				$this->save( $id, $fields );
				// Unreachable: save() redirects.
			case 'delete_cache_rule':
				if ( $id <= 0 || null === $this->rules->find( $id ) ) {
					$this->redirect( 'cache_not_found' );
				}
				$this->redirect( $this->rules->delete( $id ) ? 'cache_deleted' : 'error' );
				// Unreachable.
			case 'toggle_cache_rule':
				if ( $id <= 0 || null === $this->rules->find( $id ) ) {
					$this->redirect( 'cache_not_found' );
				}
				$this->redirect( $this->rules->set_enabled( $id, self::flag( $fields, 'enabled' ) ) ? 'cache_toggled' : 'error' );
				// Unreachable.
			case 'purge_cache':
				$this->redirect( $this->purger->purge_all() ? 'cache_purged' : 'cache_purge_failed' );
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
		$data = [
			'label'       => (string) ( $fields['label'] ?? '' ),
			'route_glob'  => (string) ( $fields['route_glob'] ?? '' ),
			'ttl_seconds' => (int) ( $fields['ttl_seconds'] ?? 0 ),
			'tags'        => (string) ( $fields['tags'] ?? '' ),
			'enabled'     => self::flag( $fields, 'enabled' ),
		];

		if ( $id > 0 ) {
			if ( null === $this->rules->find( $id ) ) {
				$this->redirect( 'cache_not_found' );
			}
			if ( ! $this->rules->update( $id, $data ) ) {
				$this->fail( 'cache_invalid', $id, $fields );
			}
			$this->redirect( 'cache_saved' );
		}

		if ( $this->rules->count() >= $this->rules->limit() ) {
			$this->redirect( 'cache_limit' );
		}
		if ( false === $this->rules->create( $data ) ) {
			$this->fail( 'cache_invalid', 0, $fields );
		}
		$this->redirect( 'cache_saved' );
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
