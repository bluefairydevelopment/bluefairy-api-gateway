<?php
/**
 * The Cache tab.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Admin;

use BlueFairy\ApiGateway\Cache\CacheRuleRepository;
use BlueFairy\ApiGateway\Cache\CacheStore;

/**
 * Rules table, add/edit form, delete, toggle and Purge all.
 *
 * Validation lives in CacheRuleRepository; this page turns its refusals
 * into notices and never stores anything itself. When the rule limit is
 * reached the add form gives way to the upsell line.
 */
final class CachePage extends Page {

	public const TAB = 'cache';

	/**
	 * Constructor.
	 *
	 * @param CacheRuleRepository $rules  Cache rules.
	 * @param CacheStore          $store  Response store (Purge all).
	 * @param ProUpsell           $upsell Pro state and copy.
	 */
	public function __construct(
		private readonly CacheRuleRepository $rules,
		private readonly CacheStore $store,
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
		return [ 'save_cache_rule', 'delete_cache_rule', 'toggle_cache_rule', 'purge_cache' ];
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
			'cache',
			[
				'rules'     => $rules,
				'count'     => $count,
				'limit'     => $limit,
				'page'      => $this,
				'purge_url' => $this->action_url( 'purge_cache', 0 ),
			]
		);

		/**
		 * Rendered below the cache rules table.
		 *
		 * @since 1.0.0
		 */
		do_action( 'bfag_cache_page_after' );

		if ( null === $editing && $at_limit ) {
			echo '<p class="bfag-limit-line">' . esc_html( $this->upsell->limit_line( sprintf( /* translators: %d: number of rules */ _n( '%d cache rule', '%d cache rules', $limit, 'bluefairy-api-gateway' ), $limit ) ) ) . '</p>';
			return;
		}

		$this->view(
			'cache-form',
			[
				'rule'       => $editing ?? [
					'id'          => 0,
					'label'       => '',
					'route_glob'  => '',
					'ttl_seconds' => 60,
					'tags'        => '',
					'enabled'     => 1,
				],
				'form_url'   => $this->url(),
				'cancel_url' => $this->url(),
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
				$this->store->flush_all();
				/**
				 * The response cache was purged.
				 *
				 * @since 1.0.0
				 *
				 * @param string   $scope 'all' or 'tags'.
				 * @param string[] $tags  The tags bumped, empty for 'all'.
				 */
				do_action( 'bfag_cache_purged', 'all', [] );
				$this->redirect( 'cache_purged' );
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
			$this->redirect( $this->rules->update( $id, $data ) ? 'cache_saved' : 'cache_invalid' );
		}

		if ( $this->rules->count() >= $this->rules->limit() ) {
			$this->redirect( 'cache_limit' );
		}
		$created = $this->rules->create( $data );
		$this->redirect( false === $created ? 'cache_invalid' : 'cache_saved' );
	}
}
