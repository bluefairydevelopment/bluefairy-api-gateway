<?php
/**
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Tests\Unit\Admin;

use BlueFairy\ApiGateway\Admin\CachePage;
use BlueFairy\ApiGateway\Admin\ProUpsell;
use BlueFairy\ApiGateway\Cache\CachePurger;
use BlueFairy\ApiGateway\Cache\CacheRuleRepository;
use BlueFairy\ApiGateway\Cache\CacheStore;
use BlueFairy\ApiGateway\Capabilities;
use BlueFairy\ApiGateway\Settings;
use BlueFairy\ApiGateway\Tests\TestCase;

final class CachePageTest extends TestCase {

	private CacheStore $store;

	protected function setUp(): void {
		parent::setUp();
		$this->store = new CacheStore();
		bfag_test_grant( Capabilities::MANAGE );
	}

	private function page(): CachePage {
		return new CachePage( new CacheRuleRepository(), new CachePurger( $this->store, new CacheRuleRepository() ), new ProUpsell(), new Settings() );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function rule( int $id = 1, string $label = 'All <routes>', string $glob = 'wp/v2/**', int $ttl = 120, string $tags = 'posts,pages', int $enabled = 1 ): array {
		return [
			'id'          => $id,
			'label'       => $label,
			'route_glob'  => $glob,
			'ttl_seconds' => $ttl,
			'tags'        => $tags,
			'headers'     => null,
			'enabled'     => $enabled,
			'created_at'  => '2026-01-01 00:00:00',
		];
	}

	/**
	 * Script render()'s queries: all() → get_results, count() → get_var, then find() → get_row when editing.
	 *
	 * @param list<array<string, mixed>> $rules Rows.
	 */
	private function prime_render( array $rules, ?array $editing = null ): void {
		$this->wpdb->queue( 'get_results', $rules );
		$this->wpdb->queue( 'get_var', count( $rules ) );
		if ( null !== $editing ) {
			$this->wpdb->queue( 'get_row', $editing );
		}
	}

	private function redirect_of( callable $fn ): string {
		$exit = bfag_test_catch_exit( $fn );
		$this->assertNotNull( $exit, 'the handler must end in a redirect' );
		$this->assertSame( 'redirect', $exit->kind );
		return (string) $exit->payload;
	}

	public function test_tab_and_actions(): void {
		$page = $this->page();
		$this->assertSame( 'cache', $page->tab() );
		$this->assertSame( [ 'save_cache_rule', 'delete_cache_rule', 'toggle_cache_rule', 'purge_cache' ], $page->actions() );
		$this->assertSame( 'https://example.com/wp-admin/options-general.php?page=bfag-gateway&tab=cache', $page->url() );
		$this->assertSame( 'https://example.com/wp-admin/options-general.php?page=bfag-gateway&tab=cache&bfag_action=delete_cache_rule&id=4&_wpnonce=nonce-bfag_delete_cache_rule', $page->action_url( 'delete_cache_rule', 4 ) );
	}

	public function test_render_lists_rules_escaped_with_the_form_and_fires_the_after_hook_after_the_form(): void {
		$this->prime_render( [ $this->rule() ] );
		add_filter( 'bfag_cache_rule_limit', static fn(): int => 5 );
		$marker = '<!-- after -->';
		add_action(
			'bfag_cache_page_after',
			static function () use ( $marker ): void {
				echo $marker; // phpcs:ignore WordPress.Security.EscapeOutput
			}
		);

		$html = bfag_test_render( [ $this->page(), 'render' ] );

		$this->assertStringContainsString( 'All &lt;routes&gt;', $html, 'label escaped' );
		$this->assertStringNotContainsString( 'All <routes>', $html );
		$this->assertStringContainsString( '<code>wp/v2/**</code>', $html );
		$this->assertStringContainsString( '120 s', $html );
		$this->assertStringContainsString( 'posts, pages', $html );
		$this->assertStringContainsString( 'data-bfag-toggle="cache" data-bfag-id="1" data-bfag-enabled="1"', $html );
		$this->assertStringContainsString( 'bfag_action=toggle_cache_rule&amp;id=1&amp;enabled=0&amp;_wpnonce=nonce-bfag_toggle_cache_rule', $html );
		$this->assertStringContainsString( 'bfag_action=delete_cache_rule&amp;id=1&amp;_wpnonce=nonce-bfag_delete_cache_rule', $html );
		$this->assertStringContainsString( 'bfag_action=purge_cache&amp;id=0&amp;_wpnonce=nonce-bfag_purge_cache', $html );
		$this->assertStringContainsString( '1 of 5', $html );
		$this->assertStringContainsString( 'name="bfag_action" value="save_cache_rule"', $html, 'add form shown under the limit' );
		$this->assertStringContainsString( 'name="_wpnonce" value="nonce-bfag_save_cache_rule"', $html );
		$this->assertStringContainsString( 'Add cache rule', $html );
		$this->assertStringNotContainsString( 'Pro allows up to 500', $html );

		$table = strpos( $html, '</table>' );
		$after = strpos( $html, $marker );
		$form  = strpos( $html, '<form' );
		$this->assertNotFalse( $table );
		$this->assertNotFalse( $after );
		$this->assertNotFalse( $form );
		$this->assertTrue( $table < $form && $form < $after, 'U-20: the hook fires after the table AND after the form' );
	}

	/**
	 * U-09: the "New-rule TTL" setting pre-fills the add form; a rule being
	 * edited keeps its own TTL.
	 */
	public function test_the_add_form_ttl_is_prefilled_from_the_setting_and_an_edited_rule_keeps_its_own(): void {
		update_option( 'bfag_settings', [ 'default_cache_ttl' => 450 ] );
		$this->prime_render( [] );
		$html = bfag_test_render( [ $this->page(), 'render' ] );
		$this->assertStringContainsString( 'name="bfag[ttl_seconds]" class="small-text" min="1" max="86400" required value="450"', $html );

		$_GET = [
			'action' => 'edit',
			'id'     => '1',
		];
		$this->prime_render( [ $this->rule( 1, ttl: 120 ) ], $this->rule( 1, ttl: 120 ) );
		$html = bfag_test_render( [ $this->page(), 'render' ] );
		$this->assertStringContainsString( 'required value="120"', $html );
		$this->assertStringNotContainsString( 'required value="450"', $html );
	}

	/**
	 * U-11: a refused save keeps what was typed for exactly one re-render.
	 */
	public function test_a_failed_save_refills_the_form_once_with_what_was_typed(): void {
		bfag_test_admin_request(
			'save_cache_rule',
			[
				'label'       => 'Typed label',
				'route_glob'  => 'wp/v2/(posts|pages)',
				'ttl_seconds' => '77',
				'tags'        => 'kept',
			]
		);
		$location = $this->redirect_of( [ $this->page(), 'handle_actions' ] );
		$this->assertStringEndsWith( '&bfag_notice=cache_invalid', $location );
		$this->assertIsArray( get_transient( 'bfag_form_' . get_current_user_id() ) );

		$this->prime_render( [] );
		$html = bfag_test_render( [ $this->page(), 'render' ] );
		$this->assertStringContainsString( 'value="Typed label"', $html );
		$this->assertStringContainsString( 'value="wp/v2/(posts|pages)"', $html );
		$this->assertStringContainsString( 'required value="77"', $html );
		$this->assertStringContainsString( 'value="kept"', $html );
		$this->assertFalse( get_transient( 'bfag_form_' . get_current_user_id() ), 'deleted on read' );

		$this->prime_render( [] );
		$html = bfag_test_render( [ $this->page(), 'render' ] );
		$this->assertStringNotContainsString( 'Typed label', $html, 'shown once' );
		$this->assertStringContainsString( 'required value="60"', $html, 'back to the setting' );
	}

	public function test_at_the_limit_the_add_form_gives_way_to_the_upsell_line(): void {
		$this->prime_render( [ $this->rule() ] );
		$html = bfag_test_render( [ $this->page(), 'render' ] );
		$this->assertStringContainsString( 'The free plugin allows 1 cache rule — Pro allows up to 500', $html );
		$this->assertStringNotContainsString( 'value="save_cache_rule"', $html );
	}

	public function test_editing_prefills_the_form_even_at_the_limit(): void {
		$_GET = [
			'action' => 'edit',
			'id'     => '1',
		];
		$this->prime_render( [ $this->rule() ], $this->rule() );
		$html = bfag_test_render( [ $this->page(), 'render' ] );
		$this->assertStringContainsString( 'Edit cache rule', $html );
		$this->assertStringContainsString( 'name="id" value="1"', $html );
		$this->assertStringContainsString( 'value="wp/v2/**"', $html );
		$this->assertStringContainsString( 'value="120"', $html );
		$this->assertStringContainsString( 'value="posts,pages"', $html );
		$this->assertStringContainsString( 'checked="checked"', $html );
		$this->assertStringNotContainsString( 'Pro allows up to 500', $html );
	}

	public function test_the_table_lists_rules_in_match_order_and_says_the_most_specific_wins(): void {
		$this->prime_render( [ $this->rule( 1, 'All routes', '**' ), $this->rule( 2, 'Posts only', 'wp/v2/posts/**' ) ] );
		$html = bfag_test_render( [ $this->page(), 'render' ] );

		$this->assertStringContainsString( 'Most specific rule wins', $html );
		$specific = strpos( $html, 'data-bfag-id="2"' );
		$generic  = strpos( $html, 'data-bfag-id="1"' );
		$this->assertNotFalse( $specific );
		$this->assertNotFalse( $generic );
		$this->assertLessThan( $generic, $specific, 'the specific rule is listed above the seeded ** rule although its id is higher' );
	}

	public function test_an_empty_table_says_so(): void {
		$this->prime_render( [] );
		$html = bfag_test_render( [ $this->page(), 'render' ] );
		$this->assertStringContainsString( 'No cache rules yet.', $html );
	}

	public function test_handle_actions_ignores_requests_without_an_action_or_with_another_tabs_action(): void {
		$this->assertNull( bfag_test_catch_exit( [ $this->page(), 'handle_actions' ] ) );
		bfag_test_admin_request( 'save_settings' );
		$this->assertNull( bfag_test_catch_exit( [ $this->page(), 'handle_actions' ] ) );
		$this->assertSame( [], $this->wpdb->calls );
	}

	public function test_a_bad_nonce_dies_with_403_before_anything_is_read(): void {
		bfag_test_admin_request( 'delete_cache_rule', [], [ 'id' => 1 ], false );
		$exit = bfag_test_catch_exit( [ $this->page(), 'handle_actions' ] );
		$this->assertSame( 'die', $exit->kind );
		$this->assertSame( 403, $exit->status );
		$this->assertSame( [], $this->wpdb->calls );
	}

	public function test_a_user_without_the_capability_dies_with_403(): void {
		$GLOBALS['bfag_test_caps'] = [];
		bfag_test_admin_request( 'purge_cache' );
		$exit = bfag_test_catch_exit( [ $this->page(), 'handle_actions' ] );
		$this->assertSame( 'die', $exit->kind );
		$this->assertSame( 403, $exit->status );
		$this->assertSame( 1, (int) get_option( 'bfag_cache_generation', 1 ), 'nothing purged' );
	}

	public function test_save_creates_a_rule_and_redirects_with_cache_saved(): void {
		bfag_test_admin_request(
			'save_cache_rule',
			[
				'label'       => 'Posts',
				'route_glob'  => '/wp/v2/posts/**',
				'ttl_seconds' => '300',
				'tags'        => 'Posts, Pages',
				'enabled'     => '1',
			]
		);
		$this->wpdb->queue( 'get_var', 0 ); // count() in the page's limit guard.
		$this->wpdb->queue( 'get_var', 0 ); // count() in create().

		$location = $this->redirect_of( [ $this->page(), 'handle_actions' ] );

		$this->assertSame( 'https://example.com/wp-admin/options-general.php?page=bfag-gateway&tab=cache&bfag_notice=cache_saved', $location );
		$insert = $this->wpdb->calls_to( 'insert' )[0];
		$this->assertSame( 'bfw_bfag_cache_rules', $insert['table'] );
		$this->assertSame( 'Posts', $insert['data']['label'] );
		$this->assertSame( 'wp/v2/posts/**', $insert['data']['route_glob'] );
		$this->assertSame( 300, $insert['data']['ttl_seconds'] );
		$this->assertSame( 'posts,pages', $insert['data']['tags'] );
		$this->assertSame( 1, $insert['data']['enabled'] );
	}

	public function test_an_unticked_enabled_box_stores_a_disabled_rule(): void {
		bfag_test_admin_request(
			'save_cache_rule',
			[
				'label'       => 'Off',
				'route_glob'  => '**',
				'ttl_seconds' => '60',
			]
		);
		$this->redirect_of( [ $this->page(), 'handle_actions' ] );
		$this->assertSame( 0, $this->wpdb->calls_to( 'insert' )[0]['data']['enabled'] );
	}

	public function test_an_invalid_glob_is_a_notice_not_a_row(): void {
		bfag_test_admin_request(
			'save_cache_rule',
			[
				'label'       => 'Bad',
				'route_glob'  => 'wp/v2/(posts|pages)',
				'ttl_seconds' => '60',
			]
		);
		$location = $this->redirect_of( [ $this->page(), 'handle_actions' ] );
		$this->assertStringEndsWith( '&bfag_notice=cache_invalid', $location );
		$this->assertSame( [], $this->wpdb->calls_to( 'insert' ) );
	}

	public function test_creating_past_the_limit_is_refused_before_the_repository_is_asked(): void {
		bfag_test_admin_request(
			'save_cache_rule',
			[
				'label'      => 'Second',
				'route_glob' => '**',
			]
		);
		$this->wpdb->queue( 'get_var', 1 );
		$location = $this->redirect_of( [ $this->page(), 'handle_actions' ] );
		$this->assertStringEndsWith( '&bfag_notice=cache_limit', $location );
		$this->assertSame( [], $this->wpdb->calls_to( 'insert' ) );
	}

	public function test_save_with_an_id_updates_that_rule(): void {
		bfag_test_admin_request(
			'save_cache_rule',
			[
				'label'       => 'Renamed',
				'route_glob'  => '**',
				'ttl_seconds' => '99999',
				'tags'        => '',
				'enabled'     => '1',
			],
			[ 'id' => '7' ]
		);
		$this->wpdb->queue( 'get_row', $this->rule( 7 ) );
		$location = $this->redirect_of( [ $this->page(), 'handle_actions' ] );
		$this->assertStringEndsWith( '&bfag_notice=cache_saved', $location );
		$update = $this->wpdb->calls_to( 'update' )[0];
		$this->assertSame( [ 'id' => 7 ], $update['where'] );
		$this->assertSame( 'Renamed', $update['data']['label'] );
		$this->assertSame( 86400, $update['data']['ttl_seconds'], 'clamped by the repository' );
		$this->assertSame( [], $this->wpdb->calls_to( 'insert' ) );
	}

	public function test_save_with_an_unknown_id_is_cache_not_found(): void {
		bfag_test_admin_request( 'save_cache_rule', [ 'route_glob' => '**' ], [ 'id' => '7' ] );
		$location = $this->redirect_of( [ $this->page(), 'handle_actions' ] );
		$this->assertStringEndsWith( '&bfag_notice=cache_not_found', $location );
		$this->assertSame( [], $this->wpdb->calls_to( 'update' ) );
	}

	public function test_delete_removes_the_row_or_reports_not_found(): void {
		bfag_test_admin_request( 'delete_cache_rule', [], [ 'id' => '3' ] );
		$this->wpdb->queue( 'get_row', $this->rule( 3 ) );
		$location = $this->redirect_of( [ $this->page(), 'handle_actions' ] );
		$this->assertStringEndsWith( '&bfag_notice=cache_deleted', $location );
		$this->assertSame( [ 'id' => 3 ], $this->wpdb->calls_to( 'delete' )[0]['where'] );

		bfag_test_admin_request( 'delete_cache_rule', [], [ 'id' => '4' ] );
		$location = $this->redirect_of( [ $this->page(), 'handle_actions' ] );
		$this->assertStringEndsWith( '&bfag_notice=cache_not_found', $location );
		$this->assertCount( 1, $this->wpdb->calls_to( 'delete' ), 'no second delete' );
	}

	public function test_toggle_sets_enabled_from_the_link(): void {
		bfag_test_admin_request(
			'toggle_cache_rule',
			[],
			[
				'id'      => '3',
				'enabled' => '0',
			]
		);
		$this->wpdb->queue( 'get_row', $this->rule( 3 ) );
		$location = $this->redirect_of( [ $this->page(), 'handle_actions' ] );
		$this->assertStringEndsWith( '&bfag_notice=cache_toggled', $location );
		$this->assertSame( [ 'enabled' => 0 ], $this->wpdb->calls_to( 'update' )[0]['data'] );

		bfag_test_admin_request(
			'toggle_cache_rule',
			[],
			[
				'id'      => '3',
				'enabled' => '1',
			]
		);
		$this->wpdb->queue( 'get_row', $this->rule( 3, enabled: 0 ) );
		$this->redirect_of( [ $this->page(), 'handle_actions' ] );
		$this->assertSame( [ 'enabled' => 1 ], $this->wpdb->calls_to( 'update' )[1]['data'] );
	}

	public function test_purge_all_bumps_the_generation_and_fires_the_action(): void {
		$seen = [];
		add_action(
			'bfag_cache_purged',
			static function ( string $scope, array $tags ) use ( &$seen ): void {
				$seen[] = [ $scope, $tags ];
			},
			10,
			2
		);
		$before = $this->store->generation();
		bfag_test_admin_request( 'purge_cache' );
		$location = $this->redirect_of( [ $this->page(), 'handle_actions' ] );
		$this->assertStringEndsWith( '&bfag_notice=cache_purged', $location );
		$this->assertSame( $before + 1, $this->store->generation() );
		$this->assertSame( [ [ 'all', [] ] ], $seen );
	}
}
