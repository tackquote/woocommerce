<?php
/**
 * Per-visitor flood guard shared by every public handler that calls TackQuote.
 *
 * @package TackQuotes
 * @since   1.10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Counts requests per visitor in short-lived transients.
 *
 * WHY TWO COUNTERS. The visitor address comes from `WC_Geolocation::get_ip_address()`
 * so the store agrees with WooCommerce about who a visitor is behind a proxy. That
 * function returns `X-Real-IP` unvalidated, or else the first `X-Forwarded-For` entry
 * (read in WooCommerce 11.2.1 `includes/class-wc-geolocation.php`). Both are headers the
 * client writes, so a limit keyed only on them is bypassed by sending a new value per
 * request. Every hit is therefore ALSO charged to the socket peer (`REMOTE_ADDR`), which
 * the client cannot choose. That second allowance is wider (SOCKET_FACTOR times) because
 * behind a CDN or load balancer many real visitors share one peer address. The
 * `tack_quotes_rate_limit_socket_factor` filter tunes it, and 0 turns it off.
 *
 * Keys are salted hashes (`wp_hash()`): an IP address is personal data and is never stored.
 */
class Tack_Rate_Limit {

	/** How many times the per-visitor allowance one socket peer address may use. */
	const SOCKET_FACTOR = 10;

	/**
	 * Is this visitor over the allowance for a bucket?
	 *
	 * @param string $prefix Transient prefix for the bucket (for example `tack_qr_`).
	 * @param string $salt   Bucket name mixed into the hash.
	 * @param int    $max    Requests allowed per window. Zero or less: no limit.
	 * @return bool
	 */
	public static function exceeded( $prefix, $salt, $max ) {
		$max = (int) $max;
		if ( $max <= 0 ) {
			return false;
		}
		if ( (int) get_transient( self::client_key( $prefix, $salt ) ) >= $max ) {
			return true;
		}
		$socket_max = self::socket_max( $max );
		return $socket_max > 0 && (int) get_transient( self::socket_key( $prefix, $salt ) ) >= $socket_max;
	}

	/**
	 * Charge one request to this visitor (both counters).
	 *
	 * @param string $prefix Transient prefix for the bucket.
	 * @param string $salt   Bucket name mixed into the hash.
	 * @param int    $max    Requests allowed per window. Zero or less: nothing is counted.
	 * @param int    $window Window length in seconds.
	 */
	public static function hit( $prefix, $salt, $max, $window ) {
		if ( (int) $max <= 0 ) {
			return;
		}
		$keys = array( self::client_key( $prefix, $salt ) );
		if ( self::socket_max( (int) $max ) > 0 ) {
			$keys[] = self::socket_key( $prefix, $salt );
		}
		foreach ( $keys as $key ) {
			set_transient( $key, (int) get_transient( $key ) + 1, (int) $window );
		}
	}

	/**
	 * The socket peer's allowance for a bucket whose per-visitor allowance is $max.
	 *
	 * @param int $max Per-visitor allowance.
	 * @return int Zero when the socket counter is switched off.
	 */
	private static function socket_max( $max ) {
		/**
		 * Filters how many times the per-visitor allowance one socket peer may use.
		 *
		 * @since 1.10.0
		 *
		 * @param int $factor Multiplier. Zero or less turns the socket counter off.
		 */
		$factor = (int) apply_filters( 'tack_quotes_rate_limit_socket_factor', self::SOCKET_FACTOR );
		return $factor > 0 ? $max * $factor : 0;
	}

	/**
	 * Counter key for the visitor as WooCommerce resolves them.
	 *
	 * @param string $prefix Transient prefix.
	 * @param string $salt   Bucket name.
	 * @return string
	 */
	public static function client_key( $prefix, $salt ) {
		$ip = '';
		if ( class_exists( 'WC_Geolocation' ) ) {
			$ip = (string) WC_Geolocation::get_ip_address();
		}
		if ( '' === $ip ) {
			$ip = self::remote_addr();
		}
		return $prefix . substr( wp_hash( $salt . '|' . $ip ), 0, 20 );
	}

	/**
	 * Counter key for the socket peer address.
	 *
	 * @param string $prefix Transient prefix.
	 * @param string $salt   Bucket name.
	 * @return string
	 */
	public static function socket_key( $prefix, $salt ) {
		return $prefix . 's' . substr( wp_hash( $salt . '|socket|' . self::remote_addr() ), 0, 19 );
	}

	/**
	 * `REMOTE_ADDR`, or '' when there is none (CLI).
	 *
	 * @return string
	 */
	private static function remote_addr() {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}
}
