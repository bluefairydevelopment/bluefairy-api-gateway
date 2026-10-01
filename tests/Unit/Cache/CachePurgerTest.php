<?php
/**
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Tests\Unit\Cache;

use BlueFairy\ApiGateway\Cache\CachePurger;
use BlueFairy\ApiGateway\Cache\CacheRuleRepository;
use BlueFairy\ApiGateway\Cache\CacheStore;
use BlueFairy\ApiGateway\Logger;
use BlueFairy\ApiGateway\Settings;
use BlueFairy\ApiGateway\Tests\TestCase;

final class CachePurgerTest extends TestCase {

	private CacheStore $store;
	private CachePurger $purger;

	/**
	 * Every `bfag_cache_purged` firing: [ scope, tags ].
	 *
	 * @var list<array{0: string, 1: array<int, string>}>
	 */
	private array $purged = [];

	/**
	 * Every `bfag_cache_purge_tags` call: [ type, id ].
	 *
	 * @var list<array{0: string, 1: int}>
	 */
	private array $asked = [];

	protected function setUp(): void {
		parent::setUp();
		$this->store  = new CacheStore();
		$this->purger = new CachePurger( $this->store, new CacheRuleRepository() );
		$this->purged = [];
		$this->asked  = [];
		add_action(
			'bfag_cache_purged',
			function ( string $scope, array $tags ): void {
				$this->purged[] = [ $scope, $tags ];
			},
			10,
			2
		);
	}

	/**
	 * Answer the purge-tags filter with $tags, recording what was asked.
	 *
	 * @param string[]|null $tags Tags to return.
	 */
	private function answer_tags( ?array $tags ): void {
		add_filter(
			'bfag_cache_purge_tags',
			function ( ?array $current, string $type, int $id ) use ( $tags ): ?array {
				$this->asked[] = [ $type, $id ];
				return $tags ?? $current;
			},
			10,
			3
		);
	}

	public function test_a_post_change_flushes_everything_by_default(): void {
		$this->answer_tags( null );
		$this->assertSame( 1, $this->store->generation() );

		$this->purger->on_post_change( 7 );

		$this->assertSame( 2, $this->store->generation() );
		$this->assertSame( [ [ 'post', 7 ] ], $this->asked );
		$this->assertSame( [ [ 'all', [] ] ], $this->purged );
	}

	public function test_a_post_change_bumps_only_tags_when_the_filter_returns_an_array(): void {
		$this->answer_tags( [ 'products', 'post-7' ] );

		$this->purger->on_post_change( 7 );

		$this->assertSame( 1, $this->store->generation(), 'global generation untouched' );
		$this->assertSame( 2, $this->store->tag_generation( 'products' ) );
		$this->assertSame( 2, $this->store->tag_generation( 'post-7' ) );
		$this->assertSame( 1, $this->store->tag_generation( 'posts' ) );
		$this->assertSame( [ [ 'tags', [ 'products', 'post-7' ] ] ], $this->purged );
	}

	public function test_an_empty_array_from_the_filter_bumps_nothing_and_does_not_flush(): void {
		$this->answer_tags( [] );
		$this->purger->on_post_change( 7 );
		$this->assertSame( 1, $this->store->generation() );
		$this->assertSame( [ [ 'tags', [] ] ], $this->purged );
	}

	public function test_revisions_and_autosaves_do_not_purge_but_a_real_post_does(): void {
		bfag_test_add_post(
			[
				'ID'        => 9,
				'post_type' => 'revision',
				'post_name' => '7-revision-v1',
			]
		);
		bfag_test_add_post(
			[
				'ID'        => 10,
				'post_type' => 'revision',
				'post_name' => '7-autosave-v1',
			]
		);
		bfag_test_add_post(
			[
				'ID'        => 11,
				'post_type' => 'post',
			]
		);

		$this->purger->on_post_change( 9 );
		$this->purger->on_post_change( 10 );
		$this->assertSame( 1, $this->store->generation() );
		$this->assertSame( [], $this->purged );

		$this->purger->on_post_change( 11 );
		$this->assertSame( 2, $this->store->generation() );
		$this->assertCount( 1, $this->purged );
	}

	public function test_a_term_change_asks_with_type_term(): void {
		$this->answer_tags( [ 'term-3' ] );
		$this->purger->on_term_change( 3, 'category' );
		$this->assertSame( [ [ 'term', 3 ] ], $this->asked );
		$this->assertSame( 2, $this->store->tag_generation( 'term-3' ) );
		$this->assertSame( 1, $this->store->generation() );

		$this->purger->on_term_change( 4, 'post_tag' );
		$this->assertSame( [ [ 'term', 3 ], [ 'term', 4 ] ], $this->asked );
	}

	public function test_a_term_change_flushes_everything_by_default(): void {
		$this->purger->on_term_change( 3, 'category' );
		$this->assertSame( 2, $this->store->generation() );
		$this->assertSame( [ [ 'all', [] ] ], $this->purged );
	}

	public function test_a_comment_change_asks_with_type_comment(): void {
		$this->answer_tags( null );
		$this->purger->on_comment_change( 12 );
		$this->assertSame( [ [ 'comment', 12 ] ], $this->asked );
		$this->assertSame( 2, $this->store->generation() );
	}

	public function test_purge_all_flushes_and_announces(): void {
		$this->store->set( 'k', [ 'status' => 200, 'headers' => [], 'data' => 1 ], 60 );
		update_option( 'bfag_cache_generation', 5 );
		wp_cache_delete( 'generation', 'bfag' );

		$this->purger->purge_all();

		$this->assertSame( 6, get_option( 'bfag_cache_generation' ) );
		$this->assertSame( [ [ 'all', [] ] ], $this->purged );
	}

	public function test_purge_tags_bumps_announces_and_drops_non_strings(): void {
		$this->purger->purge_tags( [ 'products', '', 'posts' ] );
		$this->assertSame( 2, $this->store->tag_generation( 'products' ) );
		$this->assertSame( 2, $this->store->tag_generation( 'posts' ) );
		$this->assertSame( 1, $this->store->generation() );
		$this->assertSame( [ [ 'tags', [ 'products', 'posts' ] ] ], $this->purged );
	}

	public function test_purge_tags_logs_tags_no_enabled_rule_carries(): void {
		update_option( 'bfag_settings', [ 'debug_logging' => true ] );
		Logger::use_settings( new Settings() );
		ini_set( 'error_log', '/dev/null' );
		wp_cache_set(
			CacheRuleRepository::CACHE_KEY,
			[
				[
					'id'         => 1,
					'route_glob' => '**',
					'tags'       => 'products,posts',
				],
			],
			'bfag'
		);

		$this->purger->purge_tags( [ 'products' ] );
		$this->assertSame( [], Logger::lines(), 'a known tag is not logged' );

		$this->purger->purge_tags( [ 'posts', 'ghost' ] );
		$this->assertCount( 1, Logger::lines() );
		$this->assertStringContainsString( '"ghost"', Logger::lines()[0] );
		$this->assertStringNotContainsString( '"posts"', Logger::lines()[0] );
		$this->assertSame( 2, $this->store->tag_generation( 'ghost' ), 'logged, but still bumped' );
	}

	public function test_register_hooks_binds_every_content_hook(): void {
		foreach ( [ 'save_post', 'delete_post', 'trashed_post', 'edited_term', 'created_term', 'delete_term', 'transition_comment_status', 'wp_insert_comment' ] as $hook ) {
			$this->assertFalse( has_action( $hook ), $hook . ' not bound before register_hooks' );
		}
		$this->purger->register_hooks();
		foreach ( [ 'save_post', 'delete_post', 'trashed_post', 'edited_term', 'created_term', 'delete_term', 'transition_comment_status', 'wp_insert_comment' ] as $hook ) {
			$this->assertTrue( has_action( $hook ), $hook );
			$this->assertArrayHasKey( 10, $GLOBALS['bfag_test_hooks'][ $hook ], $hook . ' bound at priority 10' );
		}
		$this->assertSame( 10, has_action( 'delete_post', [ $this->purger, 'on_post_change' ] ) );
		$this->assertSame( 10, has_action( 'trashed_post', [ $this->purger, 'on_post_change' ] ) );
	}

	public function test_hooks_fire_the_right_handler_with_the_right_id(): void {
		$this->answer_tags( [ 'x' ] );
		$this->purger->register_hooks();
		bfag_test_add_post(
			[
				'ID'        => 21,
				'post_type' => 'revision',
			]
		);

		$this->fire( 'save_post', 20, new \WP_Post( [ 'ID' => 20 ] ), true );
		$this->fire( 'save_post', 21, new \WP_Post( [ 'ID' => 21 ] ), true );
		$this->fire( 'delete_post', 22, new \WP_Post( [ 'ID' => 22 ] ) );
		$this->fire( 'trashed_post', 23, 'publish' );
		$this->fire( 'edited_term', 30, 31, 'category', [] );
		$this->fire( 'created_term', 32, 33, 'post_tag', [] );
		$this->fire( 'delete_term', 34, 35, 'category', (object) [], [] );
		$this->fire( 'transition_comment_status', 'approved', 'unapproved', (object) [ 'comment_ID' => '40' ] );
		$this->fire( 'wp_insert_comment', 41, (object) [ 'comment_ID' => '41' ] );

		$this->assertSame(
			[
				[ 'post', 20 ],
				[ 'post', 22 ],
				[ 'post', 23 ],
				[ 'term', 30 ],
				[ 'term', 32 ],
				[ 'term', 34 ],
				[ 'comment', 40 ],
				[ 'comment', 41 ],
			],
			$this->asked,
			'the revision (21) never reached the filter'
		);
		$this->assertSame( 9, $this->store->tag_generation( 'x' ) );
		$this->assertSame( 1, $this->store->generation() );
	}
}
