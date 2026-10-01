<?php
/**
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Tests\Unit\Admin;

use BlueFairy\ApiGateway\Admin\ProUpsell;
use BlueFairy\ApiGateway\Admin\RateLimitPage;
use BlueFairy\ApiGateway\Capabilities;
use BlueFairy\ApiGateway\RateLimit\RateLimitRuleRepository;
use BlueFairy\ApiGateway\Tests\TestCase;

final class RateLimitPageTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		bfag_test_grant( Capabilities::MANAGE );
	}

	private function page(): RateLimitPage {
		return new RateLimitPage( new RateLimitRuleRepository(), new ProUpsell() );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function rule( int $id = 1, int $enabled = 1 ): array {
		return [
			'id'              => $id,
			'label'           => 'Global <limit>',
			'subject_type'    => 'ip',
			'route_glob'      => '**',
			'max_requests'    => 60,
			'window_seconds'  => 60,
			'penalty_seconds' => 900,
			'enabled'         => $enabled,
			'created_at'      => '2026-01-01 00:00:00',
		];
	}

	/**
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
		$this->assertSame( 'ratelimit', $page->tab() );
		$this->assertSame( [ 'save_rate_limit_rule', 'delete_rate_limit_rule', 'toggle_rate_limit_rule' ], $page->actions() );
	}

	public function test_render_lists_rules_without_the_penalty_column_and_fires_the_after_hook_after_the_table(): void {
		$this->prime_render( [ $this->rule() ] );
		add_filter( 'bfag_rate_limit_rule_limit', static fn(): int => 3 );
		$marker = '<!-- after -->';
		add_action(
			'bfag_ratelimit_page_after',
			static function () use ( $marker ): void {
				echo $marker; // phpcs:ignore WordPress.Security.EscapeOutput
			}
		);

		$html = bfag_test_render( [ $this->page(), 'render' ] );

		$this->assertStringContainsString( 'Global &lt;limit&gt;', $html );
		$this->assertStringContainsString( '60 per 60 s', $html );
		$this->assertStringNotContainsString( 'Penalty', $html, 'free: no penalty column or field' );
		$this->assertStringNotContainsString( 'name="bfag[penalty_seconds]"', $html );
		$this->assertMatchesRegularExpression( '/<option value="ip"\s+selected="selected">/', $html );
		$this->assertStringNotContainsString( 'value="user"', $html, 'only subject_types() are offered' );
		$this->assertStringContainsString( 'name="bfag_action" value="save_rate_limit_rule"', $html );
		$this->assertStringContainsString( 'name="_wpnonce" value="nonce-bfag_save_rate_limit_rule"', $html );
		$this->assertStringContainsString( 'data-bfag-toggle="ratelimit" data-bfag-id="1" data-bfag-enabled="1"', $html );
		$this->assertStringContainsString( '1 of 3', $html );

		$table = strpos( $html, '</table>' );
		$after = strpos( $html, $marker );
		$form  = strpos( $html, '<form' );
		$this->assertTrue( false !== $table && false !== $after && false !== $form && $table < $after && $after < $form );
	}

	public function test_pro_shows_the_penalty_column_and_field_and_extra_subject_types(): void {
		add_filter( 'bfag_is_pro', '__return_true' );
		add_filter( 'bfag_rate_limit_subject_types', static fn( array $t ): array => [ ...$t, 'user' ] );
		add_filter( 'bfag_rate_limit_rule_limit', static fn(): int => 3 );
		$this->prime_render( [ $this->rule() ] );
		$html = bfag_test_render( [ $this->page(), 'render' ] );
		$this->assertStringContainsString( 'Penalty', $html );
		$this->assertStringContainsString( '900 s', $html );
		$this->assertStringContainsString( 'name="bfag[penalty_seconds]"', $html );
		$this->assertMatchesRegularExpression( '/<option value="user"\s*>/', $html );
	}

	public function test_at_the_limit_the_add_form_gives_way_to_the_upsell_line(): void {
		$this->prime_render( [ $this->rule() ] );
		$html = bfag_test_render( [ $this->page(), 'render' ] );
		$this->assertStringContainsString( 'The free plugin allows 1 rate-limit rule — Pro removes the limit', $html );
		$this->assertStringNotContainsString( 'value="save_rate_limit_rule"', $html );

		$_GET = [
			'action' => 'edit',
			'id'     => '1',
		];
		$this->prime_render( [ $this->rule() ], $this->rule() );
		$html = bfag_test_render( [ $this->page(), 'render' ] );
		$this->assertStringContainsString( 'Edit rate-limit rule', $html );
		$this->assertStringContainsString( 'value="save_rate_limit_rule"', $html );
	}

	public function test_save_creates_a_rule(): void {
		bfag_test_admin_request(
			'save_rate_limit_rule',
			[
				'label'           => 'Products',
				'subject_type'    => 'ip',
				'route_glob'      => 'wc/v3/products/**',
				'max_requests'    => '30',
				'window_seconds'  => '10',
				'penalty_seconds' => '600',
				'enabled'         => 'on',
			]
		);
		$this->wpdb->queue( 'get_var', 0 );
		$this->wpdb->queue( 'get_var', 0 );
		$location = $this->redirect_of( [ $this->page(), 'handle_actions' ] );
		$this->assertSame( 'https://example.com/wp-admin/options-general.php?page=bfag-gateway&tab=ratelimit&bfag_notice=ratelimit_saved', $location );
		$insert = $this->wpdb->calls_to( 'insert' )[0];
		$this->assertSame( 'bfw_bfag_rate_limit_rules', $insert['table'] );
		$this->assertSame( 'Products', $insert['data']['label'] );
		$this->assertSame( 30, $insert['data']['max_requests'] );
		$this->assertSame( 10, $insert['data']['window_seconds'] );
		$this->assertSame( 1, $insert['data']['enabled'] );
		$this->assertSame( 0, $insert['data']['penalty_seconds'], 'free: the posted penalty is ignored' );
	}

	public function test_pro_saves_the_penalty(): void {
		add_filter( 'bfag_is_pro', '__return_true' );
		bfag_test_admin_request(
			'save_rate_limit_rule',
			[
				'subject_type'    => 'ip',
				'route_glob'      => '**',
				'max_requests'    => '30',
				'window_seconds'  => '10',
				'penalty_seconds' => '600',
			]
		);
		$this->redirect_of( [ $this->page(), 'handle_actions' ] );
		$this->assertSame( 600, $this->wpdb->calls_to( 'insert' )[0]['data']['penalty_seconds'] );
	}

	public function test_a_subject_type_outside_subject_types_is_refused_before_the_repository(): void {
		bfag_test_admin_request(
			'save_rate_limit_rule',
			[
				'subject_type'   => 'user',
				'route_glob'     => '**',
				'max_requests'   => '30',
				'window_seconds' => '10',
			]
		);
		$location = $this->redirect_of( [ $this->page(), 'handle_actions' ] );
		$this->assertStringEndsWith( '&bfag_notice=ratelimit_invalid', $location );
		$this->assertSame( [], $this->wpdb->calls );
	}

	public function test_out_of_range_values_are_a_notice_not_a_row(): void {
		bfag_test_admin_request(
			'save_rate_limit_rule',
			[
				'subject_type'   => 'ip',
				'route_glob'     => '**',
				'max_requests'   => '0',
				'window_seconds' => '10',
			]
		);
		$location = $this->redirect_of( [ $this->page(), 'handle_actions' ] );
		$this->assertStringEndsWith( '&bfag_notice=ratelimit_invalid', $location );
		$this->assertSame( [], $this->wpdb->calls_to( 'insert' ) );
	}

	public function test_creating_past_the_limit_is_refused(): void {
		bfag_test_admin_request(
			'save_rate_limit_rule',
			[
				'subject_type'   => 'ip',
				'route_glob'     => '**',
				'max_requests'   => '1',
				'window_seconds' => '1',
			]
		);
		$this->wpdb->queue( 'get_var', 1 );
		$location = $this->redirect_of( [ $this->page(), 'handle_actions' ] );
		$this->assertStringEndsWith( '&bfag_notice=ratelimit_limit', $location );
		$this->assertSame( [], $this->wpdb->calls_to( 'insert' ) );
	}

	public function test_save_with_an_id_updates_or_reports_not_found(): void {
		bfag_test_admin_request(
			'save_rate_limit_rule',
			[
				'label'          => 'Renamed',
				'subject_type'   => 'ip',
				'route_glob'     => '**',
				'max_requests'   => '5',
				'window_seconds' => '5',
			],
			[ 'id' => '2' ]
		);
		$this->wpdb->queue( 'get_row', $this->rule( 2 ) );
		$location = $this->redirect_of( [ $this->page(), 'handle_actions' ] );
		$this->assertStringEndsWith( '&bfag_notice=ratelimit_saved', $location );
		$update = $this->wpdb->calls_to( 'update' )[0];
		$this->assertSame( [ 'id' => 2 ], $update['where'] );
		$this->assertSame( 'Renamed', $update['data']['label'] );
		$this->assertSame( 0, $update['data']['enabled'], 'unticked box' );

		bfag_test_admin_request( 'save_rate_limit_rule', [ 'route_glob' => '**' ], [ 'id' => '9' ] );
		$location = $this->redirect_of( [ $this->page(), 'handle_actions' ] );
		$this->assertStringEndsWith( '&bfag_notice=ratelimit_not_found', $location );
		$this->assertCount( 1, $this->wpdb->calls_to( 'update' ) );
	}

	public function test_delete_and_toggle(): void {
		bfag_test_admin_request( 'delete_rate_limit_rule', [], [ 'id' => '2' ] );
		$this->wpdb->queue( 'get_row', $this->rule( 2 ) );
		$location = $this->redirect_of( [ $this->page(), 'handle_actions' ] );
		$this->assertStringEndsWith( '&bfag_notice=ratelimit_deleted', $location );
		$this->assertSame( [ 'id' => 2 ], $this->wpdb->calls_to( 'delete' )[0]['where'] );

		bfag_test_admin_request( 'delete_rate_limit_rule', [], [ 'id' => '2' ] );
		$location = $this->redirect_of( [ $this->page(), 'handle_actions' ] );
		$this->assertStringEndsWith( '&bfag_notice=ratelimit_not_found', $location );

		bfag_test_admin_request(
			'toggle_rate_limit_rule',
			[],
			[
				'id'      => '2',
				'enabled' => '0',
			]
		);
		$this->wpdb->queue( 'get_row', $this->rule( 2 ) );
		$location = $this->redirect_of( [ $this->page(), 'handle_actions' ] );
		$this->assertStringEndsWith( '&bfag_notice=ratelimit_toggled', $location );
		$this->assertSame( [ 'enabled' => 0 ], $this->wpdb->calls_to( 'update' )[0]['data'] );
	}

	public function test_a_bad_nonce_or_missing_capability_dies_with_403(): void {
		bfag_test_admin_request( 'delete_rate_limit_rule', [], [ 'id' => '2' ], false );
		$exit = bfag_test_catch_exit( [ $this->page(), 'handle_actions' ] );
		$this->assertSame( [ 'die', 403 ], [ $exit->kind, $exit->status ] );

		$GLOBALS['bfag_test_caps'] = [];
		bfag_test_admin_request( 'delete_rate_limit_rule', [], [ 'id' => '2' ] );
		$exit = bfag_test_catch_exit( [ $this->page(), 'handle_actions' ] );
		$this->assertSame( [ 'die', 403 ], [ $exit->kind, $exit->status ] );
		$this->assertSame( [], $this->wpdb->calls );
	}
}
