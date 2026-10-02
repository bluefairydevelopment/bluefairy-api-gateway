<?php
/**
 * WPMAP-138: one row ceiling for every rule and webhook read, and every limit
 * clamped to it.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Tests\Unit\Support;

use BlueFairy\ApiGateway\Cache\CacheRuleRepository;
use BlueFairy\ApiGateway\RateLimit\RateLimitRuleRepository;
use BlueFairy\ApiGateway\Support\Limits;
use BlueFairy\ApiGateway\Tests\TestCase;
use BlueFairy\ApiGateway\Webhook\WebhookRepository;

/**
 * Before the fix the enabled-rule reads and the admin lists stopped at
 * LIMIT 200 while the Pro add-on allowed 500 rules, so rules 201-500 counted
 * toward the limit but were never listed, matched or enforced. FakeWpdb runs
 * no SQL, so these tests pin the LIMIT each read sends and the clamp on each
 * limit(); the rig (api-gateway-pro T-02/T-03/T-04 "201+") proves the real
 * table answers past row 200.
 */
final class LimitsTest extends TestCase {

	/**
	 * The three repositories, each with its limit filter.
	 *
	 * @return iterable<string, array{object, string}>
	 */
	public static function repositories(): iterable {
		yield 'cache rules' => [ new CacheRuleRepository(), 'bfag_cache_rule_limit' ];
		yield 'rate-limit rules' => [ new RateLimitRuleRepository(), 'bfag_rate_limit_rule_limit' ];
		yield 'webhooks' => [ new WebhookRepository(), 'bfag_webhook_limit' ];
	}

	public function test_the_ceiling_is_at_least_the_pro_cap(): void {
		// The Pro add-on's BlueFairy\ApiGatewayPro\Plugin::UNLIMITED; its suite asserts the same from its side.
		$this->assertGreaterThanOrEqual( 500, Limits::MAX_ROWS );
	}

	public function test_clamp_bounds_a_filtered_limit_to_zero_through_the_ceiling(): void {
		$this->assertSame( 500, Limits::clamp( 500 ) );
		$this->assertSame( 25, Limits::clamp( '25' ) );
		$this->assertSame( Limits::MAX_ROWS, Limits::clamp( PHP_INT_MAX ) );
		$this->assertSame( 0, Limits::clamp( -4 ) );
		$this->assertSame( 0, Limits::clamp( [ 7 ] ) );
		$this->assertSame( 0, Limits::clamp( null ) );
	}

	/**
	 * @dataProvider repositories
	 */
	public function test_every_read_asks_for_the_ceiling( object $repo, string $filter ): void {
		unset( $filter );
		$repo->all_enabled();
		$repo->all();
		$sql = array_map( static fn( array $c ): string => (string) $c['sql'], $this->wpdb->calls_to( 'get_results' ) );

		$this->assertCount( 2, $sql );
		$this->assertStringEndsWith( 'LIMIT ' . Limits::MAX_ROWS, $sql[0], 'the enforcement read (all_enabled)' );
		$this->assertStringEndsWith( 'LIMIT ' . Limits::MAX_ROWS, $sql[1], 'the admin list (all) by default' );
	}

	/**
	 * @dataProvider repositories
	 */
	public function test_an_explicit_list_size_is_clamped_to_the_ceiling( object $repo, string $filter ): void {
		unset( $filter );
		$repo->all( 50 );
		$repo->all( Limits::MAX_ROWS * 10 );
		$sql = array_map( static fn( array $c ): string => (string) $c['sql'], $this->wpdb->calls_to( 'get_results' ) );

		$this->assertStringEndsWith( 'LIMIT 50', $sql[0] );
		$this->assertStringEndsWith( 'LIMIT ' . Limits::MAX_ROWS, $sql[1] );
	}

	/**
	 * @dataProvider repositories
	 */
	public function test_no_limit_admits_a_row_the_reads_would_not_return( object $repo, string $filter ): void {
		$cap = 500;
		add_filter(
			$filter,
			static function () use ( &$cap ): int {
				return $cap;
			}
		);
		$this->assertSame( 500, $repo->limit(), 'the Pro cap passes through unchanged' );

		$cap = Limits::MAX_ROWS * 5;
		$this->assertSame( Limits::MAX_ROWS, $repo->limit(), 'a filter past the ceiling stops at it' );

		$repo->all_enabled();
		$repo->all();
		foreach ( $this->wpdb->calls_to( 'get_results' ) as $call ) {
			$this->assertSame( 1, preg_match( '/LIMIT (\d+)$/', (string) $call['sql'], $m ) );
			$this->assertGreaterThanOrEqual( $repo->limit(), (int) $m[1], 'every row the limit admits is one the read returns' );
		}
	}
}
