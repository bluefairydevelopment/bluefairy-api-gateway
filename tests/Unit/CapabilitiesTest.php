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
		$this->assertSame( 'webhook:read', Capabilities::required_scope( '/bfag/v1/webhooks', 'get' ), 'an explicit method wins over REQUEST_METHOD' );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$this->assertSame( 'webhook:write', Capabilities::required_scope( '/bfag/v1/webhooks', 'PUT' ) );
		$this->assertSame( 'read', Capabilities::required_scope( '/bfag/v1/status' ) );
		$this->assertSame( 'read', Capabilities::required_scope( '/bfag/v1/status', 'POST' ), 'inside bfag/v1 an unmapped route stays read' );
		$this->assertSame( 'read', Capabilities::required_scope( '/wp/v2/posts' ) );
	}

	/**
	 * A key acts as its user, so without this a `read` key bound to an
	 * administrator could create posts through core's REST API.
	 */
	public function test_routes_outside_bfag_v1_need_write_for_anything_but_a_read_method(): void {
		$this->assertSame( 'read', Capabilities::required_scope( '/wp/v2/posts', 'GET' ) );
		$this->assertSame( 'read', Capabilities::required_scope( '/wp/v2/posts', 'HEAD' ) );
		$this->assertSame( 'read', Capabilities::required_scope( '/wp/v2/posts', 'OPTIONS' ) );
		$this->assertSame( 'write', Capabilities::required_scope( '/wp/v2/posts', 'POST' ) );
		$this->assertSame( 'write', Capabilities::required_scope( '/wp/v2/posts/1', 'PUT' ) );
		$this->assertSame( 'write', Capabilities::required_scope( '/wp/v2/posts/1', 'PATCH' ) );
		$this->assertSame( 'write', Capabilities::required_scope( '/wp/v2/posts/1', 'DELETE' ) );
		$this->assertSame( 'write', Capabilities::required_scope( 'wc/v3/orders', 'post' ), 'any namespace, any case' );
		$_SERVER['REQUEST_METHOD'] = 'DELETE';
		$this->assertSame( 'write', Capabilities::required_scope( '/wp/v2/posts/1' ), 'REQUEST_METHOD when no method is passed' );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$this->assertSame( 'read', Capabilities::required_scope( '/wp/v2/posts/1' ) );
	}

	public function test_scope_satisfied(): void {
		$this->assertTrue( Capabilities::scope_satisfied( 'read cache:purge', 'cache:purge' ) );
		$this->assertFalse( Capabilities::scope_satisfied( 'read', 'cache:purge' ) );
		$this->assertTrue( Capabilities::scope_satisfied( 'webhook:write', 'webhook:read' ), 'write implies read' );
		$this->assertFalse( Capabilities::scope_satisfied( 'webhook:read', 'webhook:write' ) );
		$this->assertFalse( Capabilities::scope_satisfied( '', 'read' ) );
		$this->assertFalse( Capabilities::scope_satisfied( 'webhook:write', 'cache:purge' ), 'nothing implies purge' );
		$this->assertTrue( Capabilities::scope_satisfied( 'read write', 'write' ) );
		$this->assertFalse( Capabilities::scope_satisfied( 'read', 'write' ), 'read does not imply write' );
		$this->assertFalse( Capabilities::scope_satisfied( 'write', 'read' ), 'write does not imply read' );
		$this->assertFalse( Capabilities::scope_satisfied( 'write', 'cache:purge' ) );
		$this->assertFalse( Capabilities::scope_satisfied( 'write', 'webhook:write' ) );
	}

	public function test_every_scope_has_a_label_and_write_is_a_scope(): void {
		$this->assertSame( [ 'read', 'write', 'cache:purge', 'webhook:read', 'webhook:write' ], Capabilities::SCOPES );
		$this->assertSame( Capabilities::SCOPES, array_keys( Capabilities::SCOPE_LABELS ), 'labels are keyed in SCOPES order, no extras' );
		foreach ( Capabilities::SCOPES as $scope ) {
			$this->assertArrayHasKey( $scope, Capabilities::SCOPE_LABELS );
			$this->assertNotSame( '', trim( Capabilities::SCOPE_LABELS[ $scope ] ), $scope );
		}
		$this->assertSame( 'Read any route', Capabilities::SCOPE_LABELS['read'] );
		$this->assertSame( "Create, update and delete through any REST route (acts as the key's user)", Capabilities::SCOPE_LABELS['write'] );
	}

	public function test_scopes_constant_lists_every_scope_the_map_can_require(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		foreach ( [ '/bfag/v1/cache/purge', '/bfag/v1/webhooks', '/bfag/v1/status', '/x' ] as $route ) {
			$this->assertContains( Capabilities::required_scope( $route ), Capabilities::SCOPES );
			$this->assertContains( Capabilities::required_scope( $route, 'GET' ), Capabilities::SCOPES );
		}
	}
}
