<?php
/**
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Tests\Unit;

use BlueFairy\ApiGateway\Logger;
use BlueFairy\ApiGateway\Settings;
use BlueFairy\ApiGateway\Tests\TestCase;

final class LoggerTest extends TestCase {

	public function test_nothing_logs_without_settings_or_when_disabled(): void {
		Logger::log( 'hello' );
		$this->assertSame( [], Logger::lines() );
		$this->assertFalse( Logger::enabled() );

		Logger::use_settings( new Settings() );
		Logger::log( 'hello' );
		$this->assertSame( [], Logger::lines() );
	}

	public function test_logs_with_json_context_when_enabled(): void {
		update_option( 'bfag_settings', [ 'debug_logging' => true ] );
		Logger::use_settings( new Settings() );
		$this->assertTrue( Logger::enabled() );
		ini_set( 'error_log', '/dev/null' );
		Logger::log( 'rejected header', [ 'name' => 'Host' ] );
		Logger::log( 'plain' );
		$this->assertSame( [ '[bfag] rejected header {"name":"Host"}', '[bfag] plain' ], Logger::lines() );
		Logger::clear();
		$this->assertSame( [], Logger::lines() );
	}

	/**
	 * F12: the buffer is a ring of BUFFER_LIMIT lines; the oldest go first.
	 */
	public function test_the_buffer_keeps_only_the_newest_two_hundred_lines(): void {
		update_option( 'bfag_settings', [ 'debug_logging' => true ] );
		Logger::use_settings( new Settings() );
		ini_set( 'error_log', '/dev/null' );
		for ( $i = 1; $i <= Logger::BUFFER_LIMIT + 5; $i++ ) {
			Logger::log( 'line ' . $i );
		}
		$lines = Logger::lines();
		$this->assertCount( 200, $lines );
		$this->assertSame( 200, Logger::BUFFER_LIMIT );
		$this->assertSame( '[bfag] line 6', $lines[0], 'the five oldest were dropped' );
		$this->assertSame( '[bfag] line 205', $lines[199] );
		$this->assertNotContains( '[bfag] line 1', $lines );
	}
}
