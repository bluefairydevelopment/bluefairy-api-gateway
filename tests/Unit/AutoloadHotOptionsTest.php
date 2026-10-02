<?php
/**
 * P7 / WPMAP-106: the options read on every request are autoloaded, and the
 * enabled-event list EventBus hooks at boot comes from one of them.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Tests\Unit;

use BlueFairy\ApiGateway\Cache\CacheStore;
use BlueFairy\ApiGateway\Installer;
use BlueFairy\ApiGateway\Settings;
use BlueFairy\ApiGateway\Tests\TestCase;
use BlueFairy\ApiGateway\Webhook\WebhookRepository;

/**
 * The harness records the autoload flag the way core stores it
 * (`$GLOBALS['bfag_test_option_autoload']`, `on`/`off`/`auto`) and, as core,
 * changes the flag of an existing option only together with its value —
 * which is why the upgrade step is needed at all (pinned in HarnessTest).
 */
final class AutoloadHotOptionsTest extends TestCase {

	private const HOT = [ 'bfag_settings', 'bfag_db_version', 'bfag_version', 'bfag_cache_generation' ];

	/**
	 * @return array<string, string>
	 */
	private function flags(): array {
		return $GLOBALS['bfag_test_option_autoload'];
	}

	public function test_the_list_names_the_hot_options_and_uninstall_deletes_the_new_one(): void {
		$this->assertSame( [ ...self::HOT, WebhookRepository::EVENTS_OPTION ], Installer::AUTOLOAD_OPTIONS );
		$this->assertNotContains( 'bfag_tag_generations', Installer::AUTOLOAD_OPTIONS, 'grows with tags; read only for tagged rules' );
		$this->assertContains( WebhookRepository::EVENTS_OPTION, Installer::OPTIONS );
	}

	public function test_a_fresh_install_writes_every_hot_option_autoloaded(): void {
		Installer::provision_site();

		foreach ( self::HOT as $option ) {
			$this->assertSame( 'on', $this->flags()[ $option ] ?? null, $option );
		}
	}

	/**
	 * The upgrade step: an install from before 1.0.3 has the options with
	 * autoload off, and rewriting the same value would not change that.
	 */
	public function test_an_upgrade_from_1_0_2_turns_autoload_on_for_the_existing_options(): void {
		foreach ( self::HOT as $option ) {
			add_option( $option, 'bfag_db_version' === $option ? '1.0.2' : ( 'bfag_version' === $option ? BFAG_VERSION : 1 ), '', false );
		}
		add_option( WebhookRepository::EVENTS_OPTION, [], '', false );

		Installer::maybe_upgrade();

		$this->assertSame( BFAG_DB_VERSION, get_option( 'bfag_db_version' ) );
		foreach ( [ ...self::HOT, WebhookRepository::EVENTS_OPTION ] as $option ) {
			$this->assertSame( 'on', $this->flags()[ $option ], $option );
		}
		$this->assertArrayNotHasKey( 'bfag_tag_generations', $this->flags(), 'an option that does not exist is not created by the step' );
	}

	/**
	 * Negative twin: an install already at the current schema is not
	 * touched on boot (the step is version-gated, not run per request).
	 */
	public function test_a_current_install_is_not_rewritten_on_boot(): void {
		add_option( 'bfag_db_version', BFAG_DB_VERSION, '', false );
		add_option( 'bfag_version', BFAG_VERSION, '', false );
		add_option( 'bfag_settings', Settings::DEFAULTS, '', false );

		Installer::maybe_upgrade();

		$this->assertSame( 'off', $this->flags()['bfag_settings'] );
		$this->assertSame( 'off', $this->flags()['bfag_db_version'] );
	}

	/**
	 * A re-activation (provision_site) of an install whose options predate
	 * 1.0.3 records the current version — so the upgrade step would never
	 * run — and must therefore flip the flags itself.
	 */
	public function test_a_reactivation_turns_autoload_on_for_options_written_before(): void {
		add_option( 'bfag_settings', Settings::DEFAULTS, '', false );
		add_option( 'bfag_cache_generation', 4, '', false );

		Installer::provision_site();

		$this->assertSame( 'on', $this->flags()['bfag_settings'] );
		$this->assertSame( 'on', $this->flags()['bfag_cache_generation'] );
		$this->assertSame( '4', get_option( 'bfag_cache_generation' ), 'value kept' );
	}

	public function test_settings_and_generation_writes_keep_autoload_on_and_tag_generations_off(): void {
		( new Settings() )->update( [ 'cache_enabled' => '1' ] );
		$store = new CacheStore();
		$store->flush_all();
		$store->bump_tags( [ 'post:1' ] );

		$this->assertSame( 'on', $this->flags()['bfag_settings'] );
		$this->assertSame( 'on', $this->flags()['bfag_cache_generation'] );
		$this->assertSame( 'off', $this->flags()['bfag_tag_generations'] );
	}

	public function test_every_webhook_write_rewrites_the_event_list_from_the_table(): void {
		$repo = new WebhookRepository();
		$this->wpdb->queue( 'get_col', [ 'post.published', 'user.registered' ] );
		$id = $repo->create(
			[
				'label' => 'A',
				'url'   => 'https://hooks.example.com/a',
				'event' => 'post.published',
			]
		);
		$this->assertIsInt( $id );

		$this->assertSame( [ 'post.published', 'user.registered' ], get_option( WebhookRepository::EVENTS_OPTION ) );
		$this->assertSame( 'on', $this->flags()[ WebhookRepository::EVENTS_OPTION ] );
		$rebuild = $this->wpdb->calls_to( 'get_col' );
		$this->assertCount( 1, $rebuild );
		$this->assertStringContainsString( 'SELECT DISTINCT event FROM bfw_bfag_webhooks WHERE enabled = 1', (string) $rebuild[0]['sql'] );

		// Disabling the last webhook of an event takes it out of the list.
		$this->wpdb->queue( 'get_col', [ 'user.registered' ] );
		$repo->set_enabled( (int) $id, false );
		$this->assertSame( [ 'user.registered' ], get_option( WebhookRepository::EVENTS_OPTION ) );

		$this->wpdb->queue( 'delete', 1 );
		$repo->delete( (int) $id );
		$this->assertSame( [], get_option( WebhookRepository::EVENTS_OPTION ), 'the delete rebuilt it too' );
	}

	public function test_enabled_events_reads_the_option_with_no_query(): void {
		add_option( WebhookRepository::EVENTS_OPTION, [ 'post.updated' ], '', true );
		wp_cache_set( WebhookRepository::CACHE_KEY, [ [ 'event' => 'post.published' ] ], 'bfag' ); // A different answer, to tell the sources apart.

		$this->assertSame( [ 'post.updated' ], ( new WebhookRepository() )->enabled_events() );
		$this->assertSame( [], $this->wpdb->calls );
	}

	public function test_a_missing_list_is_built_once_from_the_table_and_added_autoloaded(): void {
		$this->wpdb->queue(
			'get_results',
			[
				[
					'id'      => 1,
					'event'   => 'post.published',
					'enabled' => 1,
				],
				[
					'id'      => 2,
					'event'   => 'post.published',
					'enabled' => 1,
				],
			]
		);
		$repo = new WebhookRepository();

		$this->assertSame( [ 'post.published' ], $repo->enabled_events() );
		$this->assertSame( [ 'post.published' ], get_option( WebhookRepository::EVENTS_OPTION ) );
		$this->assertSame( 'on', $this->flags()[ WebhookRepository::EVENTS_OPTION ] );

		wp_cache_flush(); // A new request without a persistent object cache.
		$this->wpdb->calls = [];
		$this->assertSame( [ 'post.published' ], ( new WebhookRepository() )->enabled_events() );
		$this->assertSame( [], $this->wpdb->calls, 'the next boot reads the option' );
	}
}
