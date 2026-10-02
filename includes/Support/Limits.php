<?php
/**
 * The one row ceiling shared by every rule and webhook read.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every read of the cache-rule, rate-limit-rule and webhook tables — the
 * admin lists, the enforcement reads (`all_enabled()`), the webhook list and
 * the Pro add-on's `GET /bfag/v1/webhooks` — is bounded by MAX_ROWS, and every
 * `limit()` is clamped to it, so a row that may be created is always a row
 * that is listed, matched and enforced (WPMAP-138: the reads stopped at 200
 * while Pro allowed 500, so rules 201-500 counted toward the limit but were
 * never listed and never matched).
 *
 * Every site, with or without the Pro add-on, may keep DEFAULT_LIMIT rows of
 * each kind (WPMAP-142: the same ceiling everywhere — an engineering bound on
 * the admin lists and the per-request matching, not a paid tier). A site may
 * lower or raise its own limit through `bfag_cache_rule_limit`,
 * `bfag_rate_limit_rule_limit` or `bfag_webhook_limit`; a limit raised beyond
 * MAX_ROWS stops at MAX_ROWS.
 */
final class Limits {

	/**
	 * The most rows of one kind that are read, and the most that may exist.
	 */
	public const MAX_ROWS = 1000;

	/**
	 * How many cache rules, rate-limit rules and webhooks a site may keep
	 * unless a filter says otherwise. At most MAX_ROWS.
	 */
	public const DEFAULT_LIMIT = 500;

	/**
	 * A filtered limit, clamped to 0..MAX_ROWS.
	 *
	 * @param mixed $limit What the limit filter returned.
	 */
	public static function clamp( mixed $limit ): int {
		return max( 0, min( self::MAX_ROWS, is_scalar( $limit ) ? (int) $limit : 0 ) );
	}
}
