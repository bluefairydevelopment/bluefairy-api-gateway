<?php
/**
 * Debug logging, on only when the admin switches it on.
 *
 * @package BlueFairy\ApiGateway
 */

declare(strict_types=1);

namespace BlueFairy\ApiGateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes to the PHP error log (WP_DEBUG_LOG) when `debug_logging` is enabled.
 *
 * A static facade so a header-sanitiser deep in the dispatcher can log a
 * rejection without being handed a dependency it otherwise has no use for.
 */
final class Logger {

	/**
	 * Source of the debug_logging flag.
	 *
	 * @var Settings|null
	 */
	private static ?Settings $settings = null;

	/**
	 * Lines written during this request, oldest first (tests read these).
	 *
	 * @var list<string>
	 */
	private static array $buffer = [];

	/**
	 * Supply the Settings instance (Plugin::boot). Without one, nothing logs.
	 *
	 * @param Settings|null $settings Settings, or null to disable.
	 */
	public static function use_settings( ?Settings $settings ): void {
		self::$settings = $settings;
	}

	/**
	 * Is logging switched on?
	 */
	public static function enabled(): bool {
		return null !== self::$settings && (bool) self::$settings->get( 'debug_logging' );
	}

	/**
	 * Log a line with optional context, JSON-encoded.
	 *
	 * @param string               $message What happened.
	 * @param array<string, mixed> $context Extra data.
	 */
	public static function log( string $message, array $context = [] ): void {
		if ( ! self::enabled() ) {
			return;
		}
		$line = '[bfag] ' . $message;
		if ( [] !== $context ) {
			$line .= ' ' . (string) wp_json_encode( $context );
		}
		self::$buffer[] = $line;
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- this IS the debug log, opt-in.
		error_log( $line );
	}

	/**
	 * Lines logged so far this request.
	 *
	 * @return list<string>
	 */
	public static function lines(): array {
		return self::$buffer;
	}

	/**
	 * Clear the buffer (tests).
	 */
	public static function clear(): void {
		self::$buffer = [];
	}
}
