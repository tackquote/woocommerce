<?php
/**
 * Decides whether an order push may be sent, from what TackQuote last said.
 *
 * WHY THIS EXISTS. One store's API key lacked the `orders:write` scope. Every order
 * trigger — creation, each status change, each admin save — queued a push, every push
 * was refused with HTTP 403, and nothing remembered the refusal, so the next trigger
 * sent the same doomed request: 444 refusals in 50 minutes, roughly one every 3 seconds.
 * The merchant saw nothing; the reason sat only in WooCommerce → Status → Logs.
 *
 * Nothing here loops by itself. Action Scheduler does not re-run a callback that returns
 * normally (it marks an action failed only on a fatal error or a timeout), so the volume
 * came from triggers, and the fix is to remember the refusal between them.
 *
 * Classification — the same temporary/terminal split the TackQuote API applies to the
 * vendors it calls:
 *
 *   TERMINAL   HTTP 401 or 403 with TackQuote's own JSON error body: a missing scope
 *              (`insufficient_scope`), a lapsed subscription (`SUBSCRIPTION_INACTIVE`), a
 *              revoked or unknown key. No retry changes these; the merchant has to act.
 *              Pushes stop, wp-admin says why, and one re-probe is allowed per
 *              TERMINAL_REPROBE so a renewed subscription heals without a key change.
 *              Saving a different key lifts the block at once.
 *   THROTTLED  HTTP 429. Wait for Retry-After, capped at MAX_WAIT.
 *   TEMPORARY  Everything else, including a 401/403 whose body is NOT JSON: that is a
 *              firewall or challenge page in front of the API and it goes away on its
 *              own. Calling it terminal would switch sync off for a five-minute blip.
 *
 * An order skipped while blocked is not marked as sent, so its next trigger pushes it —
 * the same recovery a failed push has always had.
 *
 * @package TackQuotes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Order-sync circuit breaker.
 */
class Tack_Sync_Gate {

	/**
	 * Option holding the current block. Also listed in uninstall.php.
	 */
	const OPTION = 'tack_quotes_order_sync_block';

	/**
	 * Seconds between re-probes while a terminal refusal stands.
	 */
	const TERMINAL_REPROBE = 3600;

	/**
	 * Default wait for a 429 that names none.
	 */
	const DEFAULT_WAIT = 60;

	/**
	 * Longest wait any Retry-After may impose.
	 */
	const MAX_WAIT = 3600;

	/**
	 * Classify a failed push.
	 *
	 * @param mixed $error A WP_Error from Tack_Api_Client, or anything else.
	 * @param int   $now   Current Unix time.
	 * @return array|null A block (kind, status, code, scopes, message, at, until), or null
	 *                    when the failure is temporary and the next trigger may try again.
	 */
	public static function classify( $error, $now ) {
		if ( ! is_wp_error( $error ) ) {
			return null;
		}
		$data   = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
		$block  = array(
			'status'  => $status,
			'code'    => is_array( $data ) && isset( $data['code'] ) ? (string) $data['code'] : '',
			'scopes'  => is_array( $data ) && isset( $data['requiredScopes'] ) && is_array( $data['requiredScopes'] ) ? array_values( $data['requiredScopes'] ) : array(),
			'message' => (string) $error->get_error_message(),
			'at'      => (int) $now,
		);

		if ( 429 === $status ) {
			$block['kind']  = 'throttled';
			$block['until'] = (int) $now + self::wait_seconds( $data, $now );
			return $block;
		}

		if ( ( 401 === $status || 403 === $status ) && is_array( $data ) && ! empty( $data['json'] ) ) {
			$block['kind']  = 'terminal';
			$block['until'] = (int) $now + self::TERMINAL_REPROBE;
			return $block;
		}

		return null;
	}

	/**
	 * Remember a failed push, if it is one that must hold back the next.
	 *
	 * @param mixed  $error   The failure.
	 * @param string $api_key The key the push was made with. Only a hash is stored.
	 * @param int    $now     Current Unix time.
	 * @return array|null The stored block.
	 */
	public static function record_failure( $error, $api_key, $now ) {
		$block = self::classify( $error, $now );
		if ( null === $block ) {
			return null;
		}
		$block['key'] = self::key_fingerprint( $api_key );
		update_option( self::OPTION, $block, false );
		return $block;
	}

	/**
	 * A push succeeded: whatever was blocking has been fixed.
	 */
	public static function record_success() {
		if ( null !== get_option( self::OPTION, null ) ) {
			self::clear();
		}
	}

	/**
	 * Forget any block.
	 */
	public static function clear() {
		delete_option( self::OPTION );
	}

	/**
	 * The block that must hold back a push right now, if any.
	 *
	 * @param string $api_key The key the next push would use.
	 * @param int    $now     Current Unix time.
	 * @return array|null
	 */
	public static function active_block( $api_key, $now ) {
		$block = self::stored_for_key( $api_key );
		if ( null === $block ) {
			return null;
		}
		return (int) $now < (int) ( $block['until'] ?? 0 ) ? $block : null;
	}

	/**
	 * Print the wp-admin notice while order sync is refused.
	 *
	 * Shown to anyone who can manage WooCommerce, because they are the people whose
	 * orders are not arriving. It stays until a push succeeds or a different key is saved;
	 * a throttle on its own is not shown, since it clears itself.
	 */
	public static function render_admin_notice() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$block = self::stored_for_key( (string) get_option( 'tack_quotes_api_key', '' ) );
		if ( null === $block || 'terminal' !== ( $block['kind'] ?? '' ) ) {
			return;
		}

		$settings = admin_url( 'admin.php?page=' . ( class_exists( 'Tack_Settings' ) ? Tack_Settings::PAGE_SLUG : 'tackquote-for-woocommerce' ) );
		echo '<div class="notice notice-error"><p><strong>'
			. esc_html__( 'TackQuote: orders are not being sent.', 'tackquote' )
			. '</strong> '
			. esc_html( self::notice_text( $block ) )
			. ' <a href="' . esc_url( $settings ) . '">'
			. esc_html__( 'Open TackQuote settings', 'tackquote' )
			. '</a></p></div>';
	}

	/**
	 * The merchant-facing explanation for a terminal block.
	 *
	 * @param array $block Stored block.
	 * @return string
	 */
	public static function notice_text( array $block ) {
		$scopes = isset( $block['scopes'] ) && is_array( $block['scopes'] ) ? array_filter( $block['scopes'], 'is_string' ) : array();
		if ( 'insufficient_scope' === ( $block['code'] ?? '' ) || array() !== $scopes ) {
			$list = array() !== $scopes ? implode( ', ', $scopes ) : 'orders:write';
			return sprintf(
				/* translators: %1$s: comma-separated API key scopes, e.g. orders:write */
				__( 'TackQuote refused order sync because the API key saved here is missing the %1$s scope. In TackQuote, open Settings > API keys, create a key with the %1$s scope, paste it into TackQuote settings on this site, then revoke the old key. Orders placed until then are sent when they next change.', 'tackquote' ),
				$list
			);
		}
		if ( 'SUBSCRIPTION_INACTIVE' === ( $block['code'] ?? '' ) ) {
			return __( 'Your TackQuote subscription is not active, so TackQuote refuses new orders. Choose a plan in TackQuote under Settings > Billing; sync resumes within the hour.', 'tackquote' );
		}
		if ( 401 === (int) ( $block['status'] ?? 0 ) ) {
			return __( 'TackQuote does not accept the API key saved here (it may have been revoked). Create a new key in TackQuote under Settings > API keys with the orders:write scope and save it in TackQuote settings on this site.', 'tackquote' );
		}
		return sprintf(
			/* translators: %s: the refusal message TackQuote returned */
			__( 'TackQuote refused order sync: %s', 'tackquote' ),
			(string) ( $block['message'] ?? '' )
		);
	}

	/**
	 * The stored block, if it was recorded for this key.
	 *
	 * A block recorded for a different key is stale — the merchant has saved a new one —
	 * and is dropped rather than trusted.
	 *
	 * @param string $api_key Current key.
	 * @return array|null
	 */
	private static function stored_for_key( $api_key ) {
		$block = get_option( self::OPTION, null );
		if ( ! is_array( $block ) ) {
			return null;
		}
		if ( ( $block['key'] ?? '' ) !== self::key_fingerprint( $api_key ) ) {
			self::clear();
			return null;
		}
		return $block;
	}

	/**
	 * A short one-way fingerprint, so the option can tell keys apart without holding one.
	 *
	 * @param string $api_key Key.
	 * @return string
	 */
	private static function key_fingerprint( $api_key ) {
		return substr( hash( 'sha256', (string) $api_key ), 0, 16 );
	}

	/**
	 * Seconds to wait after a 429: Retry-After (delta-seconds or an HTTP-date, RFC 9110
	 * section 10.2.3), else the body's retryAfterSeconds, else DEFAULT_WAIT; at most MAX_WAIT.
	 *
	 * @param mixed $data Error data.
	 * @param int   $now  Current Unix time.
	 * @return int
	 */
	private static function wait_seconds( $data, $now ) {
		$wait   = 0;
		$header = is_array( $data ) && isset( $data['retryAfterHeader'] ) ? trim( (string) $data['retryAfterHeader'] ) : '';
		if ( '' !== $header && ctype_digit( $header ) ) {
			$wait = (int) $header;
		} elseif ( '' !== $header ) {
			$when = strtotime( $header );
			$wait = false === $when ? 0 : $when - (int) $now;
		}
		if ( $wait <= 0 && is_array( $data ) && ! empty( $data['retryAfterSeconds'] ) ) {
			$wait = (int) $data['retryAfterSeconds'];
		}
		if ( $wait <= 0 ) {
			$wait = self::DEFAULT_WAIT;
		}
		return min( $wait, self::MAX_WAIT );
	}
}
