<?php
/**
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Tests\Unit\Webhook;

use BlueFairy\ApiGateway\Tests\TestCase;
use BlueFairy\ApiGateway\Webhook\EventRegistry;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class EventRegistryTest extends TestCase {

	private const CORE = [ 'post.published', 'post.updated', 'post.deleted', 'user.registered', 'user.updated', 'comment.created' ];
	private const WOO  = [ 'woocommerce.order.created', 'woocommerce.order.status_changed', 'woocommerce.order.completed', 'woocommerce.product.updated' ];

	public function test_all_lists_the_core_events_with_their_hooks(): void {
		$this->assertFalse( class_exists( 'WooCommerce' ), 'precondition: WooCommerce is not loaded in this process' );
		$all = ( new EventRegistry() )->all();
		$this->assertSame( self::CORE, array_keys( $all ) );
		$this->assertSame( [ 'hook' => 'transition_post_status', 'label' => 'Post published', 'accepted_args' => 3, 'group' => 'Content' ], $all['post.published'] );
		$this->assertSame( 'post_updated', $all['post.updated']['hook'] );
		$this->assertSame( 'before_delete_post', $all['post.deleted']['hook'] );
		$this->assertSame( [ 'user_register', 2 ], [ $all['user.registered']['hook'], $all['user.registered']['accepted_args'] ] );
		$this->assertSame( [ 'profile_update', 3 ], [ $all['user.updated']['hook'], $all['user.updated']['accepted_args'] ] );
		$this->assertSame( [ 'wp_insert_comment', 2 ], [ $all['comment.created']['hook'], $all['comment.created']['accepted_args'] ] );
		foreach ( self::WOO as $event ) {
			$this->assertArrayNotHasKey( $event, $all );
		}
	}

	public function test_exists_and_label(): void {
		$registry = new EventRegistry();
		$this->assertTrue( $registry->exists( 'post.published' ) );
		$this->assertFalse( $registry->exists( 'post.exploded' ) );
		$this->assertFalse( $registry->exists( 'woocommerce.order.created' ) );
		$this->assertSame( 'Post published', $registry->label( 'post.published' ) );
		$this->assertSame( 'post.exploded', $registry->label( 'post.exploded' ) );
	}

	/**
	 * F17: every built-in event and group has a translatable label that is
	 * not the key itself.
	 */
	public function test_every_built_in_event_and_group_has_a_translatable_label(): void {
		$registry = new EventRegistry();
		foreach ( array_merge( self::CORE, self::WOO ) as $event ) {
			$label = $registry->label( $event );
			$this->assertNotSame( '', $label, $event );
			$this->assertNotSame( $event, $label, $event . ': a label, not the key' );
		}
		$this->assertSame( 'Order status changed', $registry->label( 'woocommerce.order.status_changed' ), 'WooCommerce labels are known even when WooCommerce is not loaded' );
		foreach ( [ 'Content', 'Users', 'WooCommerce', 'Other' ] as $group ) {
			$this->assertSame( $group, $registry->group_label( $group ) );
		}
		$this->assertSame( 'Forms', $registry->group_label( 'Forms' ), 'an add-on group shows the name it registered' );
	}

	public function test_filter_can_add_an_event_and_malformed_entries_are_dropped(): void {
		add_filter(
			'bfag_webhook_events',
			static function ( array $events ): array {
				$events['form.submitted'] = [ 'hook' => 'wpcf7_mail_sent', 'label' => 'Form submitted', 'accepted_args' => 1, 'group' => 'Forms' ];
				$events['broken']         = [ 'label' => 'no hook' ];
				$events['no-label']       = [ 'hook' => 'x', 'accepted_args' => 0 ];
				return $events;
			}
		);
		$registry = new EventRegistry();
		$all      = $registry->all();
		$this->assertTrue( $registry->exists( 'form.submitted' ) );
		$this->assertSame( 'wpcf7_mail_sent', $all['form.submitted']['hook'] );
		$this->assertSame( 'Form submitted', $registry->label( 'form.submitted' ) );
		$this->assertArrayNotHasKey( 'broken', $all );
		$this->assertArrayNotHasKey( 'no-label', $all, 'label is required' );
		$this->assertTrue( $registry->exists( 'post.published' ), 'built-ins survive the filter' );
	}

	public function test_post_published_fires_only_on_a_real_publish_of_a_public_type(): void {
		$registry = new EventRegistry();
		$post     = bfag_test_add_post( [ 'ID' => 5, 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Hello', 'post_name' => 'hello', 'post_author' => 2 ] );

		$payload = $registry->payload( 'post.published', [ 'publish', 'draft', $post ] );
		$this->assertNotNull( $payload );
		$this->assertSame( 'post.published', $payload['event'] );
		$this->assertSame( 'https://example.com', $payload['site'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/', $payload['occurred_at'] );
		$this->assertSame( 5, $payload['object']['id'] );
		$this->assertSame( 'Hello', $payload['object']['title'] );
		$this->assertSame( 'https://example.com/?p=5', $payload['object']['link'] );
		$this->assertSame( 2, $payload['object']['author_id'] );

		$this->assertNull( $registry->payload( 'post.published', [ 'publish', 'publish', $post ] ), 'publish→publish is an update, not a publish' );
		$this->assertNull( $registry->payload( 'post.published', [ 'draft', 'publish', $post ] ), 'unpublishing' );
		$this->assertNotNull( $registry->payload( 'post.published', [ 'publish', 'future', $post ] ), 'scheduled→publish counts' );

		$secret = bfag_test_add_post( [ 'ID' => 6, 'post_type' => 'secret_cpt', 'post_status' => 'publish' ] );
		$this->assertNull( $registry->payload( 'post.published', [ 'publish', 'draft', $secret ] ), 'non-public post type' );
		$this->assertNull( $registry->payload( 'post.published', [ 'publish', 'draft', null ] ) );
		$this->assertNull( $registry->payload( 'post.published', [] ) );
	}

	public function test_post_updated_and_deleted_use_the_post_argument(): void {
		$registry = new EventRegistry();
		$after    = bfag_test_add_post( [ 'ID' => 7, 'post_type' => 'page', 'post_title' => 'After' ] );
		$before   = new \WP_Post( [ 'ID' => 7, 'post_type' => 'page', 'post_title' => 'Before' ] );
		$updated  = $registry->payload( 'post.updated', [ 7, $after, $before ] );
		$this->assertSame( 'After', $updated['object']['title'] );
		$this->assertSame( 'page', $updated['object']['type'] );

		$deleted = $registry->payload( 'post.deleted', [ 7, $after ] );
		$this->assertSame( 7, $deleted['object']['id'] );
		$this->assertSame( 'post.deleted', $deleted['event'] );

		$this->assertNull( $registry->payload( 'post.deleted', [ 404 ] ), 'unknown post id' );
		$cpt = bfag_test_add_post( [ 'ID' => 8, 'post_type' => 'secret_cpt' ] );
		$this->assertNull( $registry->payload( 'post.updated', [ 8, $cpt, $cpt ] ) );
	}

	public function test_user_payloads_never_carry_the_password_or_the_raw_userdata(): void {
		$registry = new EventRegistry();
		$userdata = [ 'ID' => 9, 'user_login' => 'jen', 'user_pass' => 'hunter2-plaintext', 'user_email' => 'jen@example.com', 'user_url' => 'https://attacker.example' ];
		bfag_test_add_user(
			9,
			[
				'user_login'   => 'jen-stored',
				'user_email'   => 'jen@example.com',
				'display_name' => 'Jen',
				'roles'        => [ 'subscriber' ],
			]
		);

		$registered = $registry->payload( 'user.registered', [ 9, $userdata ] );
		$this->assertNotNull( $registered );
		$this->assertSame( 9, $registered['object']['id'] );
		$this->assertSame( 'jen-stored', $registered['object']['login'], 'read from the stored user, not the hook\'s array' );
		$this->assertSame( [ 'subscriber' ], $registered['object']['roles'] );
		$this->assertSame( [ 'id', 'login', 'email', 'display_name', 'roles' ], array_keys( $registered['object'] ) );
		$json = (string) wp_json_encode( $registered );
		$this->assertStringNotContainsString( 'user_pass', $json );
		$this->assertStringNotContainsString( 'hunter2', $json );
		$this->assertStringNotContainsString( 'attacker.example', $json, 'raw userdata is not copied' );
		$this->assertStringNotContainsString( 'REMOTE_ADDR', $json );

		$updated = $registry->payload( 'user.updated', [ 9, (object) [ 'user_pass' => 'old-hash' ], $userdata ] );
		$this->assertStringNotContainsString( 'old-hash', (string) wp_json_encode( $updated ) );
		$this->assertSame( 9, $updated['object']['id'] );

		$this->assertNull( $registry->payload( 'user.registered', [ 0, $userdata ] ) );
		$this->assertNull( $registry->payload( 'user.updated', [ 'x' ] ) );
	}

	/**
	 * T5 / WPMAP-114: a positive id with no user behind it (deleted between
	 * the hook and the flush, or a forged id) yields no payload. Unprovable
	 * while the harness answered every positive id with a user.
	 */
	public function test_a_user_event_for_an_id_with_no_user_is_not_dispatched(): void {
		$registry = new EventRegistry();
		bfag_test_add_user( 9 );
		$this->assertNotNull( $registry->payload( 'user.registered', [ 9, [] ] ), 'control: a stored user' );
		$this->assertNull( $registry->payload( 'user.registered', [ 404, [ 'ID' => 404, 'user_login' => 'ghost' ] ] ) );
		$this->assertNull( $registry->payload( 'user.updated', [ 404, null, [] ] ) );
	}

	public function test_comment_created_reads_the_comment_object(): void {
		$registry = new EventRegistry();
		$comment  = (object) [ 'comment_ID' => '31', 'comment_post_ID' => '5', 'comment_author' => 'Ann', 'comment_author_email' => 'ann@example.com', 'comment_approved' => '1', 'comment_date_gmt' => '2026-09-30 10:00:00', 'comment_type' => 'comment', 'comment_author_IP' => '203.0.113.9', 'comment_content' => 'secret body' ];
		$payload  = $registry->payload( 'comment.created', [ 31, $comment ] );
		$this->assertSame( [ 'id' => 31, 'post_id' => 5, 'author' => 'Ann', 'author_email' => 'ann@example.com', 'approved' => '1', 'date_gmt' => '2026-09-30 10:00:00', 'type' => 'comment' ], $payload['object'] );
		$this->assertStringNotContainsString( '203.0.113.9', (string) wp_json_encode( $payload ), 'author IP is not forwarded' );
		$this->assertNull( $registry->payload( 'comment.created', [ 0, null ] ) );
		$this->assertSame( 32, $registry->payload( 'comment.created', [ 32, null ] )['object']['id'], 'id alone is enough' );
	}

	public function test_payload_filter_can_rewrite_or_suppress(): void {
		$registry = new EventRegistry();
		$post     = bfag_test_add_post( [ 'ID' => 5, 'post_type' => 'post' ] );
		add_filter(
			'bfag_webhook_payload',
			static function ( ?array $payload, string $event, array $args ): ?array {
				if ( 5 === $args[2]->ID ) {
					$payload['object']['extra'] = $event;
					return $payload;
				}
				return null;
			},
			10,
			3
		);
		$this->assertSame( 'post.published', $registry->payload( 'post.published', [ 'publish', 'draft', $post ] )['object']['extra'] );
		$other = bfag_test_add_post( [ 'ID' => 6, 'post_type' => 'post' ] );
		$this->assertNull( $registry->payload( 'post.published', [ 'publish', 'draft', $other ] ), 'filter returning null suppresses' );
	}

	public function test_unknown_event_gets_a_generic_object_with_the_first_integer_argument(): void {
		$registry = new EventRegistry();
		$payload  = $registry->payload( 'form.submitted', [ 'contact', 77, [ 'secret' => 'x' ] ] );
		$this->assertSame( [ 'id' => 77 ], $payload['object'] );
		$this->assertStringNotContainsString( 'secret', (string) wp_json_encode( $payload ), 'arbitrary hook args are not forwarded' );
		$this->assertSame( [], $registry->payload( 'form.submitted', [ 'no ints' ] )['object'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_woocommerce_events_appear_only_when_woocommerce_is_loaded(): void {
		$registry = new EventRegistry();
		$this->assertFalse( $registry->exists( 'woocommerce.order.created' ), 'before' );
		bfag_test_enable_woocommerce();
		$this->assertTrue( class_exists( 'WooCommerce' ) );
		$all = $registry->all();
		$this->assertSame( array_merge( self::CORE, self::WOO ), array_keys( $all ) );
		$this->assertSame( [ 'woocommerce_order_status_changed', 4 ], [ $all['woocommerce.order.status_changed']['hook'], $all['woocommerce.order.status_changed']['accepted_args'] ] );
		$this->assertSame( 'WooCommerce', $all['woocommerce.product.updated']['group'] );

		$order   = new \WC_Order( [ 'id' => 501, 'status' => 'processing', 'currency' => 'USD', 'total' => '42.50', 'customer_id' => 9, 'email' => 'buyer@example.com', 'date_created' => new \DateTimeImmutable( '2026-09-30 12:00:00', new \DateTimeZone( 'UTC' ) ) ] );
		$created = $registry->payload( 'woocommerce.order.created', [ 501, $order ] );
		$this->assertSame( [ 'id' => 501, 'status' => 'processing', 'currency' => 'USD', 'total' => '42.50', 'customer_id' => 9, 'email' => 'buyer@example.com', 'created_gmt' => '2026-09-30T12:00:00+00:00' ], $created['object'] );

		$changed = $registry->payload( 'woocommerce.order.status_changed', [ 501, 'pending', 'processing', $order ] );
		$this->assertSame( 'pending', $changed['object']['from_status'] );
		$this->assertSame( 'processing', $changed['object']['to_status'] );
		$this->assertSame( 501, $registry->payload( 'woocommerce.order.completed', [ 501, $order ] )['object']['id'] );
		$this->assertNull( $registry->payload( 'woocommerce.order.created', [ 501, null ] ), 'no order object and no wc_get_order(): nothing to send' );

		$product = new \WC_Product( [ 'id' => 88, 'name' => 'Mug', 'sku' => 'MUG-1', 'price' => '9.99', 'type' => 'simple' ] );
		$updated = $registry->payload( 'woocommerce.product.updated', [ 88, $product ] );
		$this->assertSame( [ 'id' => 88, 'name' => 'Mug', 'sku' => 'MUG-1', 'status' => 'publish', 'price' => '9.99', 'type' => 'simple' ], $updated['object'] );
		$this->assertNull( $registry->payload( 'woocommerce.product.updated', [ 88, 'not a product' ] ) );
	}
}
