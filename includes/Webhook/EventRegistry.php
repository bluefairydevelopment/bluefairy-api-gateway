<?php
/**
 * The catalogue of events a webhook can subscribe to, and their payloads.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Webhook;

/**
 * Maps an event key (`post.published`) to the WordPress hook that signals it
 * and builds the normalised payload a subscriber receives.
 *
 * The payload is the only thing a remote endpoint ever sees, so it is built
 * from an allowlist of fields per object: never a raw `$userdata` array (it
 * carries `user_pass`), never `$_SERVER`, never another user's e-mail.
 * `payload()` returning null means "do not dispatch" — the hook fired but the
 * event did not happen (a publish→publish transition, a non-public post type).
 */
final class EventRegistry {

	/**
	 * Hooks and labels for the built-in events. WooCommerce entries are only
	 * offered when WooCommerce is loaded, so a subscriber cannot be created for
	 * an event whose hook will never fire.
	 *
	 * @var array<string, array{hook:string, label:string, accepted_args:int, group:string}>
	 */
	private const BUILT_IN = [
		'post.published'  => [
			'hook'          => 'transition_post_status',
			'label'         => 'Post published',
			'accepted_args' => 3,
			'group'         => 'Content',
		],
		'post.updated'    => [
			'hook'          => 'post_updated',
			'label'         => 'Post updated',
			'accepted_args' => 3,
			'group'         => 'Content',
		],
		'post.deleted'    => [
			'hook'          => 'before_delete_post',
			'label'         => 'Post deleted',
			'accepted_args' => 2,
			'group'         => 'Content',
		],
		'user.registered' => [
			'hook'          => 'user_register',
			'label'         => 'User registered',
			'accepted_args' => 2,
			'group'         => 'Users',
		],
		'user.updated'    => [
			'hook'          => 'profile_update',
			'label'         => 'User profile updated',
			'accepted_args' => 3,
			'group'         => 'Users',
		],
		'comment.created' => [
			'hook'          => 'wp_insert_comment',
			'label'         => 'Comment created',
			'accepted_args' => 2,
			'group'         => 'Content',
		],
	];

	/**
	 * Events offered only when `class_exists( 'WooCommerce' )`.
	 *
	 * @var array<string, array{hook:string, label:string, accepted_args:int, group:string}>
	 */
	private const WOOCOMMERCE = [
		'woocommerce.order.created'        => [
			'hook'          => 'woocommerce_new_order',
			'label'         => 'Order created',
			'accepted_args' => 2,
			'group'         => 'WooCommerce',
		],
		'woocommerce.order.status_changed' => [
			'hook'          => 'woocommerce_order_status_changed',
			'label'         => 'Order status changed',
			'accepted_args' => 4,
			'group'         => 'WooCommerce',
		],
		'woocommerce.order.completed'      => [
			'hook'          => 'woocommerce_order_status_completed',
			'label'         => 'Order completed',
			'accepted_args' => 2,
			'group'         => 'WooCommerce',
		],
		'woocommerce.product.updated'      => [
			'hook'          => 'woocommerce_update_product',
			'label'         => 'Product updated',
			'accepted_args' => 2,
			'group'         => 'WooCommerce',
		],
	];

	/**
	 * Every event, keyed by event name: built-ins, WooCommerce when loaded,
	 * then whatever `bfag_webhook_events` adds. Entries without all four keys
	 * are dropped so a half-declared add-on event cannot break register_hooks().
	 *
	 * @return array<string, array{hook:string, label:string, accepted_args:int, group:string}>
	 */
	public function all(): array {
		$events = self::BUILT_IN;
		if ( class_exists( 'WooCommerce' ) ) {
			$events = array_merge( $events, self::WOOCOMMERCE );
		}

		/**
		 * Add webhook events. Each entry is keyed by event name and carries
		 * `hook`, `label`, `accepted_args` and `group`.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, array{hook:string, label:string, accepted_args:int, group:string}> $events Events.
		 */
		$filtered = apply_filters( 'bfag_webhook_events', $events );

		$clean = [];
		foreach ( is_array( $filtered ) ? $filtered : $events as $key => $spec ) {
			if ( ! is_string( $key ) || ! is_array( $spec ) ) {
				continue;
			}
			if ( ! isset( $spec['hook'], $spec['label'], $spec['accepted_args'] ) || ! is_string( $spec['hook'] ) || '' === $spec['hook'] ) {
				continue;
			}
			$clean[ $key ] = [
				'hook'          => $spec['hook'],
				'label'         => is_string( $spec['label'] ) ? $spec['label'] : $key,
				'accepted_args' => max( 1, (int) $spec['accepted_args'] ),
				'group'         => isset( $spec['group'] ) && is_string( $spec['group'] ) ? $spec['group'] : 'Other',
			];
		}
		return $clean;
	}

	/**
	 * Is $event a known event?
	 *
	 * @param string $event Event key.
	 */
	public function exists( string $event ): bool {
		return isset( $this->all()[ $event ] );
	}

	/**
	 * Human label for an event; the key itself when unknown.
	 *
	 * @param string $event Event key.
	 */
	public function label( string $event ): string {
		return $this->all()[ $event ]['label'] ?? $event;
	}

	/**
	 * Build the normalised payload for an event from the hook's arguments.
	 *
	 * @param string       $event     Event key.
	 * @param array<mixed> $hook_args The arguments the WordPress hook passed.
	 * @return array<string, mixed>|null Null means "do not dispatch".
	 */
	public function payload( string $event, array $hook_args ): ?array {
		$object = match ( $event ) {
			'post.published'                   => $this->post_published( $hook_args ),
			'post.updated'                     => $this->post_object( $hook_args[1] ?? ( $hook_args[0] ?? null ) ),
			'post.deleted'                     => $this->post_object( $hook_args[1] ?? ( $hook_args[0] ?? null ) ),
			'user.registered', 'user.updated'  => $this->user_object( $hook_args[0] ?? null ),
			'comment.created'                  => $this->comment_object( $hook_args[0] ?? null, $hook_args[1] ?? null ),
			'woocommerce.order.created',
			'woocommerce.order.completed'      => $this->order_object( $hook_args[0] ?? null, $hook_args[1] ?? null ),
			'woocommerce.order.status_changed' => $this->order_status_changed( $hook_args ),
			'woocommerce.product.updated'      => $this->product_object( $hook_args[0] ?? null, $hook_args[1] ?? null ),
			default                            => $this->generic_object( $hook_args ),
		};
		if ( null === $object ) {
			return null;
		}

		$payload = [
			'event'       => $event,
			'site'        => home_url(),
			'occurred_at' => gmdate( 'c' ),
			'object'      => $object,
		];

		/**
		 * Filter the payload before it is signed and sent. Return null to
		 * suppress the dispatch.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed>|null $payload   The payload.
		 * @param string                    $event     Event key.
		 * @param array<mixed>              $hook_args The hook's arguments.
		 */
		$filtered = apply_filters( 'bfag_webhook_payload', $payload, $event, $hook_args );

		return is_array( $filtered ) ? $filtered : null;
	}

	/**
	 * `transition_post_status( $new, $old, $post )`: only a real publish.
	 *
	 * @param array<mixed> $args Hook arguments.
	 * @return array<string, mixed>|null
	 */
	private function post_published( array $args ): ?array {
		$new_status = $args[0] ?? null;
		$old_status = $args[1] ?? null;
		if ( 'publish' !== $new_status || 'publish' === $old_status ) {
			return null;
		}
		return $this->post_object( $args[2] ?? null );
	}

	/**
	 * Allowlisted fields of a post, or null when it is not a public post.
	 *
	 * @param mixed $post A WP_Post or post id.
	 * @return array<string, mixed>|null
	 */
	private function post_object( mixed $post ): ?array {
		$post = $post instanceof \WP_Post ? $post : get_post( is_scalar( $post ) ? (int) $post : null );
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}
		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return null;
		}
		$public = get_post_types( [ 'public' => true ] );
		if ( ! in_array( $post->post_type, $public, true ) ) {
			return null;
		}
		$link = get_permalink( $post );
		return [
			'id'           => (int) $post->ID,
			'type'         => (string) $post->post_type,
			'status'       => (string) $post->post_status,
			'title'        => (string) $post->post_title,
			'slug'         => (string) $post->post_name,
			'link'         => is_string( $link ) ? $link : '',
			'author_id'    => (int) $post->post_author,
			'date_gmt'     => (string) $post->post_date_gmt,
			'modified_gmt' => (string) $post->post_modified_gmt,
		];
	}

	/**
	 * Allowlisted fields of a user — never the raw `$userdata` array.
	 *
	 * @param mixed $user_id The user id.
	 * @return array<string, mixed>|null
	 */
	private function user_object( mixed $user_id ): ?array {
		$user_id = is_scalar( $user_id ) ? (int) $user_id : 0;
		if ( $user_id <= 0 ) {
			return null;
		}
		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User ) {
			return null;
		}
		return [
			'id'           => (int) $user->ID,
			'login'        => (string) $user->user_login,
			'email'        => (string) $user->user_email,
			'display_name' => (string) $user->display_name,
			'roles'        => array_values( array_map( 'strval', (array) $user->roles ) ),
		];
	}

	/**
	 * Allowlisted fields of a comment.
	 *
	 * @param mixed $comment_id The comment id.
	 * @param mixed $comment    The WP_Comment, when the hook passed one.
	 * @return array<string, mixed>|null
	 */
	private function comment_object( mixed $comment_id, mixed $comment ): ?array {
		$comment_id = is_scalar( $comment_id ) ? (int) $comment_id : 0;
		if ( ! is_object( $comment ) && function_exists( 'get_comment' ) ) {
			$comment = get_comment( $comment_id );
		}
		if ( $comment_id <= 0 && ! is_object( $comment ) ) {
			return null;
		}
		$field = static fn( string $name ): string => is_object( $comment ) && isset( $comment->$name ) && is_scalar( $comment->$name ) ? (string) $comment->$name : '';
		$id    = (int) ( '' !== $field( 'comment_ID' ) ? $field( 'comment_ID' ) : $comment_id );
		return [
			'id'           => $id,
			'post_id'      => (int) $field( 'comment_post_ID' ),
			'author'       => $field( 'comment_author' ),
			'author_email' => $field( 'comment_author_email' ),
			'approved'     => $field( 'comment_approved' ),
			'date_gmt'     => $field( 'comment_date_gmt' ),
			'type'         => $field( 'comment_type' ),
		];
	}

	/**
	 * Allowlisted fields of a WooCommerce order.
	 *
	 * @param mixed $order_id The order id.
	 * @param mixed $order    The WC_Order, when the hook passed one.
	 * @return array<string, mixed>|null
	 */
	private function order_object( mixed $order_id, mixed $order ): ?array {
		if ( ! $order instanceof \WC_Order && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( is_scalar( $order_id ) ? (int) $order_id : 0 );
		}
		if ( ! $order instanceof \WC_Order ) {
			return null;
		}
		return [
			'id'          => (int) $order->get_id(),
			'status'      => (string) $order->get_status(),
			'currency'    => (string) $order->get_currency(),
			'total'       => (string) $order->get_total(),
			'customer_id' => (int) $order->get_customer_id(),
			'email'       => (string) $order->get_billing_email(),
			'created_gmt' => $this->wc_datetime( $order->get_date_created() ),
		];
	}

	/**
	 * `woocommerce_order_status_changed( $id, $from, $to, $order )`.
	 *
	 * @param array<mixed> $args Hook arguments.
	 * @return array<string, mixed>|null
	 */
	private function order_status_changed( array $args ): ?array {
		$object = $this->order_object( $args[0] ?? null, $args[3] ?? null );
		if ( null === $object ) {
			return null;
		}
		$object['from_status'] = is_scalar( $args[1] ?? null ) ? (string) $args[1] : '';
		$object['to_status']   = is_scalar( $args[2] ?? null ) ? (string) $args[2] : '';
		return $object;
	}

	/**
	 * Allowlisted fields of a WooCommerce product.
	 *
	 * @param mixed $product_id The product id.
	 * @param mixed $product    The WC_Product, when the hook passed one.
	 * @return array<string, mixed>|null
	 */
	private function product_object( mixed $product_id, mixed $product ): ?array {
		if ( ! $product instanceof \WC_Product && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( is_scalar( $product_id ) ? (int) $product_id : 0 );
		}
		if ( ! $product instanceof \WC_Product ) {
			return null;
		}
		return [
			'id'     => (int) $product->get_id(),
			'name'   => (string) $product->get_name(),
			'sku'    => (string) $product->get_sku(),
			'status' => (string) $product->get_status(),
			'price'  => (string) $product->get_price(),
			'type'   => (string) $product->get_type(),
		];
	}

	/**
	 * For an add-on event the registry knows nothing about: the first integer
	 * argument as `id` (so EventBus can dedupe) and nothing else. The add-on
	 * fills the object in through `bfag_webhook_payload`.
	 *
	 * @param array<mixed> $args Hook arguments.
	 * @return array<string, mixed>
	 */
	private function generic_object( array $args ): array {
		foreach ( $args as $arg ) {
			if ( is_int( $arg ) ) {
				return [ 'id' => $arg ];
			}
		}
		return [];
	}

	/**
	 * A WC_DateTime (or null) as an ISO-8601 GMT string.
	 *
	 * @param mixed $date The date object.
	 */
	private function wc_datetime( mixed $date ): string {
		if ( is_object( $date ) && method_exists( $date, 'getTimestamp' ) ) {
			return gmdate( 'c', (int) $date->getTimestamp() );
		}
		return '';
	}
}
