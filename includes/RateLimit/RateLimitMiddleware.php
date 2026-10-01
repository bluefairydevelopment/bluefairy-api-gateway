<?php
/**
 * The rest_pre_dispatch filter that enforces rate-limit rules.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\RateLimit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use BlueFairy\ApiGateway\Logger;
use BlueFairy\ApiGateway\Settings;
use BlueFairy\ApiGateway\Support\RequestContext;

/**
 * Runs at priority 5 on `rest_pre_dispatch`, ahead of the cache (10), so a
 * limited client is refused before it can be served from cache.
 *
 * Responses are `WP_REST_Response` objects, not `WP_Error`, because a 429
 * needs `Retry-After` and the `X-BF-RateLimit-*` headers and only a response
 * can carry headers through `WP_REST_Server::dispatch()`.
 *
 * Three seams for the Pro add-on: `bfag_rate_limit_is_blocked` (its ban list,
 * checked before any rule, after the editor exemption),
 * `bfag_rate_limit_breached` (its DDoS mitigator, fired once per NEW breach)
 * and `bfag_rate_limit_refused` (fired on every request the limited marker
 * refuses, with the running count of refusals this window and no database
 * work, so a DDoS threshold can mean "requests over the limit").
 */
final class RateLimitMiddleware {

	/**
	 * Route prefix that is never limited: the admin's own status endpoint.
	 */
	private const EXEMPT_ROUTE = 'bfag/v1/status';

	/**
	 * Prefix of the hit-log bucket key a rule counts against.
	 */
	public const BUCKET_PREFIX = 'rule:';

	/**
	 * Object-cache group / transient prefix of the "already limited"
	 * marker a breach leaves behind.
	 */
	private const LIMITED_GROUP  = 'bfag';
	private const LIMITED_PREFIX = 'bfag_limited_';

	/**
	 * Constructor.
	 *
	 * @param RateLimitRuleRepository $rules    Rule source.
	 * @param HitCounter              $hits     Hit log.
	 * @param Settings                $settings Plugin settings.
	 * @param RequestContext          $context  Client IP resolution.
	 */
	public function __construct(
		private readonly RateLimitRuleRepository $rules,
		private readonly HitCounter $hits,
		private readonly Settings $settings,
		private readonly RequestContext $context
	) {}

	/**
	 * Attach to the REST dispatcher.
	 */
	public function register_hooks(): void {
		add_filter( 'rest_pre_dispatch', [ $this, 'check' ], 5, 3 );
	}

	/**
	 * Decide whether this request may proceed.
	 *
	 * @param mixed            $result  Whatever an earlier filter decided; non-null is final.
	 * @param \WP_REST_Server  $server  REST server.
	 * @param \WP_REST_Request $request The request.
	 * @return mixed $result unchanged, or a 429/403 WP_REST_Response.
	 */
	public function check( mixed $result, \WP_REST_Server $server, \WP_REST_Request $request ): mixed {
		if ( null !== $result ) {
			return $result;
		}
		if ( ! Settings::to_bool( $this->settings->get( 'rate_limit_enabled' ) ) ) {
			return $result;
		}
		if ( $this->is_internal_dispatch() ) {
			return $result;
		}
		// Normalised once: no leading slash, lower-cased. WP_REST_Server matches
		// routes case-insensitively, so `/wp/v2/Posts` is the same endpoint as
		// `/wp/v2/posts` and must not get its own budget or dodge the exemption.
		$route = strtolower( RequestContext::normalize_route( $request->get_route() ) );
		if ( $this->is_exempt( $route ) ) {
			return $result;
		}

		$ip = $this->context->client_ip();
		if ( '' === $ip ) {
			return $result;
		}

		// The block editor, the media library and the customiser all drive the
		// REST API from one browser; an editor's own session must not be
		// throttled by the ceiling meant for anonymous traffic — nor refused
		// by a ban on a shared address (R2-05): the exemption is decided
		// before the ban list is asked and before any hits-table work.
		if ( Settings::to_bool( $this->settings->get( 'rate_limit_exempt_editors' ) ) && is_user_logged_in() && current_user_can( 'edit_posts' ) ) {
			return $result;
		}

		/**
		 * Filters whether the client is blocked outright (the Pro ban list).
		 *
		 * @since 1.0.0
		 *
		 * @param bool             $blocked Default false.
		 * @param string           $ip      Client IP ('' when unknown).
		 * @param \WP_REST_Request $request The request.
		 */
		if ( true === apply_filters( 'bfag_rate_limit_is_blocked', false, $ip, $request ) ) {
			return $this->blocked_response();
		}

		foreach ( $this->rules->matching( $route ) as $rule ) {
			$type    = (string) $rule['subject_type'];
			$default = 'ip' === $type ? $ip : null;

			/**
			 * Filters the subject a rule counts this request against.
			 *
			 * Null or '' means the rule does not apply to this request (an
			 * anonymous request under a `user` rule, for instance).
			 *
			 * @since 1.0.0
			 *
			 * @param string|null      $subject Default: the IP for type `ip`, null otherwise.
			 * @param string           $type    The rule's subject_type.
			 * @param \WP_REST_Request $request The request.
			 * @param string           $ip      Client IP.
			 */
			$subject = apply_filters( 'bfag_rate_limit_subject', $default, $type, $request, $ip );
			if ( ! is_string( $subject ) || '' === $subject ) {
				continue;
			}

			$window = (int) $rule['window_seconds'];
			$max    = (int) $rule['max_requests'];
			// A subject that breached this rule within the window is refused
			// from the marker alone: no INSERT, no COUNT, no breach action,
			// until it expires. The marker is keyed on the rule's limits too,
			// so editing the rule retires every marker it left (R2-07).
			$marker  = self::limited_key( $subject, (int) $rule['id'], $max, $window );
			$refused = $this->refuse_from_marker( $marker );
			if ( null !== $refused ) {
				/**
				 * A request was refused from the limited marker: the
				 * subject already breached this rule in the current
				 * window. Fired on EVERY refusal, with the running count
				 * of refusals this window — and with no database work, so
				 * the Pro DDoS mitigator can decide a block when the count
				 * crosses its threshold without a COUNT(*) of its own.
				 * The threshold therefore means "requests over the limit
				 * in the window"; the breach itself fired
				 * `bfag_rate_limit_breached` once, when the marker was set.
				 *
				 * @since 1.0.0
				 *
				 * @param string               $subject        The counted subject.
				 * @param array<string, mixed> $rule           The rule row.
				 * @param string               $ip             Client IP.
				 * @param int                  $refused_so_far Refusals of this subject under this rule this window, this one included.
				 */
				do_action( 'bfag_rate_limit_refused', $subject, $rule, $ip, $refused );
				return $this->limited_response( $rule );
			}
			// Counted against the rule, not the path: one rule = one budget per
			// subject, so neither a case variant nor a crawl of 10,000 distinct
			// URLs under a `**` rule gets a fresh allowance.
			if ( $this->hits->consume( $subject, self::bucket( (int) $rule['id'] ), $window, $max, $route ) ) {
				$this->mark_limited( $marker, $window );
				Logger::log(
					'rate limit breached',
					[
						'subject' => $subject,
						'rule'    => (int) $rule['id'],
						'route'   => $route,
						'max'     => $max,
						'window'  => $window,
					]
				);

				/**
				 * A subject newly exceeded a rule (once per window; a request
				 * refused from the limited marker does not fire this).
				 *
				 * @since 1.0.0
				 *
				 * @param string               $subject                 The counted subject.
				 * @param array<string, mixed> $rule                    The rule row.
				 * @param \WP_REST_Request     $request                 The request.
				 * @param string               $ip                      Client IP.
				 * @param bool                 $forwarded_without_proxy True when a forwarded-address
				 *                                                      header is present but REMOTE_ADDR
				 *                                                      is not a trusted proxy: the IP is a
				 *                                                      proxy's shared address and must not
				 *                                                      be banned (R2-05).
				 */
				do_action(
					'bfag_rate_limit_breached',
					$subject,
					$rule,
					$request,
					$ip,
					$this->context->forwarded_header_present() && ! $this->context->is_trusted_proxy()
				);
				return $this->limited_response( $rule );
			}
		}

		return $result;
	}

	/**
	 * The 429 a limited client receives.
	 *
	 * @param array<string, mixed> $rule The rule that was breached.
	 */
	public function limited_response( array $rule ): \WP_REST_Response {
		$window = (int) $rule['window_seconds'];
		$max    = (int) $rule['max_requests'];

		$response = new \WP_REST_Response(
			[
				'code'    => 'bfag_rate_limited',
				'message' => __( 'Too many requests. Please slow down and try again shortly.', 'bluefairy-api-gateway' ),
				'data'    => [ 'status' => 429 ],
			],
			429
		);
		$response->header( 'Retry-After', (string) $window );
		$response->header( 'X-BF-RateLimit-Limit', (string) $max );
		$response->header( 'X-BF-RateLimit-Window', (string) $window );
		return $response;
	}

	/**
	 * The 403 a blocked client receives.
	 */
	public function blocked_response(): \WP_REST_Response {
		return new \WP_REST_Response(
			[
				'code'    => 'bfag_blocked',
				'message' => __( 'Access to this API has been blocked.', 'bluefairy-api-gateway' ),
				'data'    => [ 'status' => 403 ],
			],
			403
		);
	}

	/**
	 * The hit-log bucket key for a rule: `rule:<id>`.
	 *
	 * @param int $rule_id The rule's id.
	 */
	public static function bucket( int $rule_id ): string {
		return self::BUCKET_PREFIX . $rule_id;
	}

	/**
	 * The marker key for a subject under a rule. The separator keeps
	 * subject `10.0.0.1` + rule 12 apart from `10.0.0.11` + rule 2. The
	 * rule's limits are part of the key, so a marker left under the old
	 * `max_requests` or `window_seconds` no longer applies once the rule
	 * is edited.
	 *
	 * @param string $subject The counted subject.
	 * @param int    $rule_id The rule's id.
	 * @param int    $max     The rule's max_requests.
	 * @param int    $window  The rule's window_seconds.
	 */
	public static function limited_key( string $subject, int $rule_id, int $max, int $window ): string {
		return 'limited:' . md5( $subject . '|' . self::bucket( $rule_id ) . '|' . $max . '|' . $window );
	}

	/**
	 * How many requests of $subject the marker has refused under $rule_id
	 * in the current window; 0 when the subject is not limited or the rule
	 * is not enabled. Reads the enabled rules (object-cached) for the
	 * rule's limits, which are part of the marker key.
	 *
	 * @param string $subject The counted subject.
	 * @param int    $rule_id The rule's id.
	 */
	public function refused_count( string $subject, int $rule_id ): int {
		foreach ( $this->rules->all_enabled() as $rule ) {
			if ( (int) $rule['id'] === $rule_id ) {
				$marker = $this->read_marker( self::limited_key( $subject, $rule_id, (int) $rule['max_requests'], (int) $rule['window_seconds'] ) );
				return null === $marker ? 0 : $marker['refused'];
			}
		}
		return 0;
	}

	/**
	 * Refuse this request from the marker if the subject breached this rule
	 * within the current window: count the refusal in the marker and return
	 * the running total, or null when the subject is not limited.
	 *
	 * The count is a read-modify-write of the marker, so two refusals
	 * racing on a persistent object cache may lose one: it is a running
	 * figure for a threshold, not an audit count.
	 *
	 * @param string $marker Key from limited_key().
	 */
	private function refuse_from_marker( string $marker ): ?int {
		$value = $this->read_marker( $marker );
		if ( null === $value ) {
			return null;
		}
		++$value['refused'];
		$this->write_marker( $marker, $value, max( 1, $value['until'] - time() ) );
		return $value['refused'];
	}

	/**
	 * The marker as stored — `[ 'until' => <unix>, 'refused' => <int> ]` —
	 * or null when absent or expired. A marker written before this shape
	 * (a bare timestamp) reads as zero refusals.
	 *
	 * @param string $marker Key from limited_key().
	 * @return array{until:int, refused:int}|null
	 */
	private function read_marker( string $marker ): ?array {
		$raw = true === wp_using_ext_object_cache()
			? wp_cache_get( $marker, self::LIMITED_GROUP )
			: get_transient( self::LIMITED_PREFIX . $marker );
		if ( false === $raw || null === $raw ) {
			return null;
		}
		if ( is_array( $raw ) ) {
			return [
				'until'   => (int) ( $raw['until'] ?? 0 ),
				'refused' => max( 0, (int) ( $raw['refused'] ?? 0 ) ),
			];
		}
		return [
			'until'   => is_numeric( $raw ) ? (int) $raw : 0,
			'refused' => 0,
		];
	}

	/**
	 * Store the marker for $ttl seconds: in the object cache when it is
	 * persistent, else as a transient (one options row with its timeout,
	 * gone when the window ends).
	 *
	 * @param string                        $marker Key from limited_key().
	 * @param array{until:int, refused:int} $value  The marker.
	 * @param int                           $ttl    Seconds to keep it.
	 */
	private function write_marker( string $marker, array $value, int $ttl ): void {
		if ( true === wp_using_ext_object_cache() ) {
			wp_cache_set( $marker, $value, self::LIMITED_GROUP, $ttl );
			return;
		}
		set_transient( self::LIMITED_PREFIX . $marker, $value, $ttl );
	}

	/**
	 * Remember a breach for the rest of the window, with a refusal count
	 * of zero: the breaching request is counted by the hits table, not by
	 * the marker.
	 *
	 * @param string $marker Key from limited_key().
	 * @param int    $window Seconds until the subject may try again.
	 */
	private function mark_limited( string $marker, int $window ): void {
		$window = max( 1, $window );
		$this->write_marker(
			$marker,
			[
				'until'   => time() + $window,
				'refused' => 0,
			],
			$window
		);
	}

	/**
	 * Is this dispatch something other than the top-level HTTP REST request?
	 *
	 * `rest_pre_dispatch` also fires for every internal `rest_do_request()`
	 * — WP-CLI commands (WooCommerce's CLI runner issues several per
	 * command), cron jobs and wp-admin widgets that render through the REST
	 * server. Those run as the server itself, and counting them against
	 * 127.0.0.1 let a penalty rule ban the site's own loopback. On
	 * WordPress 6.5+ `wp_is_serving_rest_request()` is true only for the
	 * real REST request; earlier versions have only the REST_REQUEST
	 * constant, and both are false for an internal dispatch from
	 * admin-ajax or the front end.
	 */
	private function is_internal_dispatch(): bool {
		if ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) {
			return true;
		}
		if ( wp_doing_cron() ) {
			return true;
		}
		$not_serving = function_exists( 'wp_is_serving_rest_request' ) && ! wp_is_serving_rest_request();
		return $not_serving && ! ( defined( 'REST_REQUEST' ) && constant( 'REST_REQUEST' ) );
	}

	/**
	 * Is the route the status endpoint, or beneath it?
	 *
	 * @param string $route Normalised, lower-cased REST route.
	 */
	private function is_exempt( string $route ): bool {
		return self::EXEMPT_ROUTE === $route || str_starts_with( $route, self::EXEMPT_ROUTE . '/' );
	}
}
