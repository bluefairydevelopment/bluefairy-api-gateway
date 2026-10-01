<?php
/**
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Tests\Unit;

use BlueFairy\ApiGateway\Capabilities;
use BlueFairy\ApiGateway\Tests\TestCase;

final class CapabilitiesTest extends TestCase {

	public function test_add_and_remove_caps_on_administrator(): void {
		$role = get_role( 'administrator' );
		$this->assertNotNull( $role );
		$this->assertFalse( $role->has_cap( Capabilities::MANAGE ) );
		Capabilities::add_caps();
		$this->assertTrue( $role->has_cap( Capabilities::MANAGE ) );
		Capabilities::remove_caps();
		$this->assertFalse( $role->has_cap( Capabilities::MANAGE ) );
	}

	public function test_add_caps_tolerates_a_missing_role(): void {
		$GLOBALS['bfag_test_roles'] = [];
		Capabilities::add_caps();
		Capabilities::remove_caps();
		$this->addToAssertionCount( 1 );
	}

	public function test_current_user_can_manage(): void {
		$this->assertFalse( Capabilities::current_user_can_manage() );
		bfag_test_grant( Capabilities::MANAGE );
		$this->assertTrue( Capabilities::current_user_can_manage() );
	}

	public function test_required_scope_map(): void {
		$this->assertSame( 'cache:purge', Capabilities::required_scope( '/bfag/v1/cache/purge' ) );
		$this->assertSame( 'cache:purge', Capabilities::required_scope( 'bfag/v1/cache/purge' ) );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$this->assertSame( 'webhook:read', Capabilities::required_scope( '/bfag/v1/webhooks' ) );
		$this->assertSame( 'webhook:read', Capabilities::required_scope( '/bfag/v1/webhooks/3' ) );
		$_SERVER['REQUEST_METHOD'] = 'DELETE';
		$this->assertSame( 'webhook:write', Capabilities::required_scope( '/bfag/v1/webhooks/3' ) );
		$this->assertSame( 'read', Capabilities::required_scope( '/bfag/v1/status' ) );
		$this->assertSame( 'read', Capabilities::required_scope( '/wp/v2/posts' ) );
	}

	public function test_scope_satisfied(): void {
		$this->assertTrue( Capabilities::scope_satisfied( 'read cache:purge', 'cache:purge' ) );
		$this->assertFalse( Capabilities::scope_satisfied( 'read', 'cache:purge' ) );
		$this->assertTrue( Capabilities::scope_satisfied( 'webhook:write', 'webhook:read' ), 'write implies read' );
		$this->assertFalse( Capabilities::scope_satisfied( 'webhook:read', 'webhook:write' ) );
		$this->assertFalse( Capabilities::scope_satisfied( '', 'read' ) );
		$this->assertFalse( Capabilities::scope_satisfied( 'webhook:write', 'cache:purge' ), 'nothing implies purge' );
	}

	public function test_scopes_constant_lists_every_scope_the_map_can_require(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		foreach ( [ '/bfag/v1/cache/purge', '/bfag/v1/webhooks', '/x' ] as $route ) {
			$this->assertContains( Capabilities::required_scope( $route ), Capabilities::SCOPES );
		}
	}
}
