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
 * At least the Pro add-on's cap (500); a limit raised beyond MAX_ROWS through
 * `bfag_cache_rule_limit`, `bfag_rate_limit_rule_limit` or
 * `bfag_webhook_limit` stops at MAX_ROWS.
 */
final class Limits {

	/**
	 * The most rows of one kind that are read, and the most that may exist.
	 */
	public const MAX_ROWS = 1000;

	/**
	 * A filtered limit, clamped to 0..MAX_ROWS.
	 *
	 * @param mixed $limit What the limit filter returned.
	 */
	public static function clamp( mixed $limit ): int {
		return max( 0, min( self::MAX_ROWS, is_scalar( $limit ) ? (int) $limit : 0 ) );
	}
}
