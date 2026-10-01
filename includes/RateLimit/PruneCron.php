<?php
/**
 * Five-minute cron that keeps the hits table small.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\RateLimit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use BlueFairy\ApiGateway\Logger;

/**
 * `bfag_cron_prune_hits` (review finding DB1): delete every hit older than
 * the longest enabled window, then apply the hard cap in case WP-Cron has
 * been asleep long enough for the table to outgrow what pruning can fix.
 */
final class PruneCron {

	/**
	 * Constructor.
	 *
	 * @param HitCounter              $hits  Hit log.
	 * @param RateLimitRuleRepository $rules Rule source, for the longest window.
	 */
	public function __construct(
		private readonly HitCounter $hits,
		private readonly RateLimitRuleRepository $rules
	) {}

	/**
	 * Attach to the cron hook Installer schedules.
	 */
	public function register_hooks(): void {
		add_action( 'bfag_cron_prune_hits', [ $this, 'run' ] );
	}

	/**
	 * Prune, then rotate if the table is still huge.
	 */
	public function run(): void {
		$window  = $this->rules->max_window();
		$deleted = $this->hits->prune( $window );
		$rotated = $this->hits->rotate_if_huge();
		Logger::log(
			'rate-limit hits pruned',
			[
				'window'  => $window,
				'deleted' => $deleted,
				'rotated' => $rotated,
			]
		);
	}
}
