<?php

namespace WCPOS\WooCommercePOS\Vipps;

class Logger {

	/**
	 * Check if debug mode is enabled in gateway settings.
	 */
	public static function is_enabled(): bool {
		$settings = get_option( 'woocommerce_wcpos_vipps_settings', array() );
		return 'yes' === ( $settings['debug'] ?? 'no' );
	}

	/**
	 * Log a message. When order_id is provided, also buffers for frontend delivery.
	 *
	 * @param string $message  Log message.
	 * @param string $level    Log level: INFO, ERROR, or DEBUG.
	 * @param int    $order_id Optional order ID for per-order buffering.
	 */
	public static function log( string $message, string $level = 'INFO', int $order_id = 0 ): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		$timestamp = current_time( 'H:i:s' );
		$entry     = "{$timestamp} [{$level}] {$message}";

		// Write to WooCommerce logs.
		$wc_level = 'ERROR' === $level ? 'error' : 'info';
		$logger   = wc_get_logger();
		$logger->$wc_level( $message, array( 'source' => 'wcpos-vipps' ) );

		// Buffer for frontend delivery if order-specific.
		if ( $order_id > 0 ) {
			self::buffer( $order_id, $entry );
		}
	}

	/**
	 * Append an entry to the per-order transient buffer.
	 */
	private static function buffer( int $order_id, string $entry ): void {
		$key     = '_wcpos_vipps_log_' . $order_id;
		$entries = get_transient( $key );

		if ( ! is_array( $entries ) ) {
			$entries = array();
		}

		$entries[] = $entry;
		set_transient( $key, $entries, 10 * MINUTE_IN_SECONDS );
	}

	/**
	 * Read and clear the log buffer for an order.
	 *
	 * @return string[] Array of formatted log entries.
	 */
	public static function flush( int $order_id ): array {
		$key     = '_wcpos_vipps_log_' . $order_id;
		$entries = get_transient( $key );

		if ( ! is_array( $entries ) || empty( $entries ) ) {
			return array();
		}

		delete_transient( $key );

		return $entries;
	}
}
