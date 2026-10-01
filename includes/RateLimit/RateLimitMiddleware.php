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
 * Two seams for the Pro add-on: `bfag_rate_limit_is_blocked` (its ban list,
 * checked before any rule) and `bfag_rate_limit_breached` (its DDoS
 * mitigator, fired once per limited request).
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
		// Normalised once: no leading slash, lower-cased. WP_REST_Server matches
		// routes case-insensitively, so `/wp/v2/Posts` is the same endpoint as
		// `/wp/v2/posts` and must not get its own budget or dodge the exemption.
		$route = strtolower( RequestContext::normalize_route( $request->get_route() ) );
		if ( $this->is_exempt( $route ) ) {
			return $result;
		}

		$ip = $this->context->client_ip();

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
			// Counted against the rule, not the path: one rule = one budget per
			// subject, so neither a case variant nor a crawl of 10,000 distinct
			// URLs under a `**` rule gets a fresh allowance.
			if ( $this->hits->consume( $subject, self::bucket( (int) $rule['id'] ), $window, $max, $route ) ) {
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
				 * A subject exceeded a rule.
				 *
				 * @since 1.0.0
				 *
				 * @param string               $subject The counted subject.
				 * @param array<string, mixed> $rule    The rule row.
				 * @param \WP_REST_Request     $request The request.
				 * @param string               $ip      Client IP.
				 */
				do_action( 'bfag_rate_limit_breached', $subject, $rule, $request, $ip );
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
	 * Is the route the status endpoint, or beneath it?
	 *
	 * @param string $route Normalised, lower-cased REST route.
	 */
	private function is_exempt( string $route ): bool {
		return self::EXEMPT_ROUTE === $route || str_starts_with( $route, self::EXEMPT_ROUTE . '/' );
	}
}
