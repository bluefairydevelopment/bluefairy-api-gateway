<?php
/**
 * Five-minute cron that removes expired response transients.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Cache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use BlueFairy\ApiGateway\Logger;

/**
 * `bfag_cron_prune_responses`: without a persistent object cache every
 * stored response is two rows in the options table, and a purge only
 * orphans them — core deletes expired transients once a day. This keeps the
 * table to roughly one hour of entries (CacheStore::MAX_TRANSIENT_TTL). A
 * no-op on the object-cache backend.
 */
final class PruneCron {

	public const HOOK = 'bfag_cron_prune_responses';

	/**
	 * Constructor.
	 *
	 * @param CacheStore $store Response storage.
	 */
	public function __construct( private readonly CacheStore $store ) {}

	/**
	 * Attach to the cron hook Installer schedules.
	 */
	public function register_hooks(): void {
		add_action( self::HOOK, [ $this, 'run' ] );
	}

	/**
	 * Delete expired response transients.
	 */
	public function run(): void {
		$deleted = $this->store->prune_expired();
		Logger::log( 'expired response transients pruned', [ 'deleted' => $deleted ] );
	}
}
