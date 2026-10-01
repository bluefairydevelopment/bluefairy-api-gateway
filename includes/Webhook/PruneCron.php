<?php
/**
 * Daily pruning of the webhook delivery log.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway\Webhook;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use BlueFairy\ApiGateway\Logger;
use BlueFairy\ApiGateway\Settings;

/**
 * Runs on `bfag_cron_prune_deliveries` (scheduled daily by Installer) and
 * removes finished deliveries older than the `retention_days` setting.
 */
final class PruneCron {

	public const HOOK = 'bfag_cron_prune_deliveries';

	/**
	 * Constructor.
	 *
	 * @param DeliveryRepository $deliveries Delivery log.
	 * @param Settings           $settings   Plugin settings (retention_days).
	 */
	public function __construct(
		private readonly DeliveryRepository $deliveries,
		private readonly Settings $settings
	) {}

	/**
	 * Attach the cron callback.
	 */
	public function register_hooks(): void {
		add_action( self::HOOK, [ $this, 'run' ] );
	}

	/**
	 * Prune finished deliveries older than `retention_days`.
	 */
	public function run(): void {
		$days    = max( 1, (int) $this->settings->get( 'retention_days' ) );
		$removed = $this->deliveries->prune( $days );
		Logger::log(
			'webhook deliveries pruned',
			[
				'retention_days' => $days,
				'removed'        => $removed,
			]
		);
	}
}
