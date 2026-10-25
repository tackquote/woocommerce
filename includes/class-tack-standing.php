<?php
/**
 * Stand down when TackQuote has disconnected this store.
 *
 * When TackQuote revokes this store's API key (the merchant pressed Disconnect in
 * TackQuote for a key that Connect with TackQuote issued, or revoked a pasted key),
 * every request the plugin makes is answered 401, and the storefront would keep
 * showing quote buttons and forms that can only fail. This class remembers that
 * answer and the plugin then hides its storefront buttons, quote list, quote page
 * and application forms (Tack_Quotes::init()), until a working key is saved.
 *
 * Fail closed to HIDDEN only on TackQuote's explicit refusal of the KEY itself, never on
 * anything else. All of these must hold:
 *
 *   - the plugin's own `ping` answered HTTP 401 with `code: "api_key_invalid"` (the code
 *     TackQuote's key check sends for an unknown, revoked or expired key). No other 401
 *     counts: TackQuote's storefront routes also answer 401, for example "sign in to
 *     attach files" to a guest, and that must never hide a store;
 *   - the ping came from an administrator's connection test or the background refresh,
 *     never from a shopper's page view or a storefront call;
 *   - a second, fresh ping confirms it.
 *
 * TackQuote's API-key check sends `api_key_invalid` with EVERY refused key (unknown,
 * revoked or expired), so a key that was never accepted (for example one revoked before
 * this store ever pinged a server that lists `standing`) stands the store down too, once
 * confirmed. A server that does not send the code never causes it.
 *
 * A timeout, a 5xx, a 429, a 403 (a key without a scope) or a 401 from a proxy in front
 * of the API leaves the state as it was.
 *
 * The state is kept for the key it was observed with (a short fingerprint), so saving
 * a new key, or connecting again, ends it at once. While the storefront is running the
 * state is refreshed at most every ten minutes, in the background (WP-Cron), never on
 * the shopper's request.
 *
 * @package TackQuotes
 * @since   1.12.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether this store is stood down, and the observations that decide it.
 */
class Tack_Standing {

	/**
	 * `{state: active|stood_down|unknown, reason: key_revoked|'', key: fingerprint,
	 * supported: bool, checked: int}`. Small and read on every storefront request, so it
	 * is autoloaded. `supported` (the server listed `standing` for this key) is a record
	 * for diagnostics only: no decision reads it since 1.12.0's live QA.
	 */
	const OPTION = 'tack_quotes_standing';

	const STATE_ACTIVE     = 'active';
	const STATE_STOOD_DOWN = 'stood_down';

	/** Checked, but nothing is known yet about the key (a failed or inconclusive ping). */
	const STATE_UNKNOWN = 'unknown';

	const REASON_REVOKED = 'key_revoked';

	/**
	 * The machine code TackQuote's key check sends with a refused key.
	 */
	const KEY_REFUSED_CODE = 'api_key_invalid';

	/**
	 * WP-Cron hook that refreshes the state in the background.
	 */
	const REFRESH_HOOK = 'tackquote_refresh_standing';

	/**
	 * Seconds between background refreshes.
	 */
	const TTL = 600;

	/**
	 * Hooks: the background refresh and the admin notice. Always registered, also while
	 * stood down, so the store can recover.
	 */
	public static function init() {
		add_action( self::REFRESH_HOOK, array( __CLASS__, 'refresh' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
		if ( ! is_admin() ) {
			add_action( 'init', array( __CLASS__, 'maybe_schedule_refresh' ), 20 );
		}
	}

	/**
	 * Fingerprint of a key, as Tack_Api_Client keys its capability cache.
	 *
	 * @param string $key API key.
	 * @return string
	 */
	public static function fingerprint( $key ) {
		return substr( hash( 'sha256', (string) $key ), 0, 16 );
	}

	/**
	 * Fingerprint of the saved key ('' when none is saved).
	 *
	 * @return string
	 */
	private static function current_fingerprint() {
		$key = (string) get_option( 'tack_quotes_api_key', '' );
		return '' === $key ? '' : self::fingerprint( $key );
	}

	/**
	 * The stored record.
	 *
	 * @return array
	 */
	private static function record() {
		$record = get_option( self::OPTION, array() );
		return is_array( $record ) ? $record : array();
	}

	/**
	 * Is the storefront stood down for the saved key?
	 *
	 * @return bool
	 */
	public static function is_stood_down() {
		$record = self::record();
		$key    = self::current_fingerprint();
		return '' !== $key
			&& self::STATE_STOOD_DOWN === ( $record['state'] ?? '' )
			&& hash_equals( (string) ( $record['key'] ?? '' ), $key );
	}

	/**
	 * A ping answered 2xx: active, and whether the server lists `standing`.
	 *
	 * @param array    $answer       Decoded ping answer (unused beyond the capabilities).
	 * @param string[] $capabilities The capabilities it listed.
	 * @param string   $key          The key it was asked with.
	 */
	public static function observe_ping( $answer, array $capabilities, $key ) {
		unset( $answer );
		if ( class_exists( 'Tack_Widget', false ) ) {
			Tack_Widget::clear_request_failure();
		}
		self::write(
			array(
				'state'     => self::STATE_ACTIVE,
				'reason'    => '',
				'key'       => self::fingerprint( $key ),
				'supported' => in_array( 'standing', $capabilities, true ),
				'checked'   => time(),
			)
		);
	}

	/**
	 * Is this WP_Error data TackQuote's refusal of the key itself (401 + api_key_invalid)?
	 *
	 * @param mixed $error_data WP_Error data from Tack_Api_Client.
	 * @return bool
	 */
	public static function is_key_refusal( $error_data ) {
		return is_array( $error_data )
			&& 401 === (int) ( $error_data['status'] ?? 0 )
			&& ! empty( $error_data['json'] )
			&& self::KEY_REFUSED_CODE === ( $error_data['code'] ?? '' );
	}

	/**
	 * A ping (admin test or background refresh only) failed. Stands down only on
	 * TackQuote's key refusal (`api_key_invalid`), and only after a second, fresh ping
	 * confirms it. Every outcome records the check time, so the background refresh keeps
	 * its ten-minute floor while the key is refused or TackQuote is unreachable.
	 *
	 * @param mixed           $error_data WP_Error data from the refused ping.
	 * @param string          $key        The key it was sent with.
	 * @param Tack_Api_Client $client     Client for the confirming ping.
	 */
	public static function observe_ping_refusal( $error_data, $key, $client ) {
		$fingerprint = self::fingerprint( $key );
		if ( ! self::is_key_refusal( $error_data )
			|| ! is_object( $client ) || ! method_exists( $client, 'key_refused_now' ) || ! $client->key_refused_now() ) {
			// Any other outcome (a timeout, a 5xx, a 403, an unconfirmed 401) leaves the state
			// as it was, but the check is RECORDED: without it the next page view saw an old
			// (or no) check time and scheduled another refresh at once, every time.
			self::touch( $fingerprint );
			return;
		}
		self::write(
			array(
				'state'     => self::STATE_STOOD_DOWN,
				'reason'    => self::REASON_REVOKED,
				'key'       => $fingerprint,
				'supported' => true,
				'checked'   => time(),
			)
		);
	}

	/**
	 * Record that the key was checked now, keeping what is known about it.
	 *
	 * @param string $fingerprint Fingerprint of the key the ping was sent with.
	 */
	private static function touch( $fingerprint ) {
		$record = self::record();
		$same   = hash_equals( (string) ( $record['key'] ?? '' ), (string) $fingerprint );
		self::write(
			array(
				'state'     => $same ? (string) ( $record['state'] ?? self::STATE_UNKNOWN ) : self::STATE_UNKNOWN,
				'reason'    => $same ? (string) ( $record['reason'] ?? '' ) : '',
				'key'       => (string) $fingerprint,
				'supported' => $same && ! empty( $record['supported'] ),
				'checked'   => time(),
			)
		);
	}

	/**
	 * Save the record (autoloaded: storefront requests read it).
	 *
	 * @param array $record Record.
	 */
	private static function write( array $record ) {
		$before = self::record();
		if ( ( $before['state'] ?? '' ) === $record['state'] && ( $before['reason'] ?? '' ) === $record['reason']
			&& ( $before['key'] ?? '' ) === $record['key'] && (bool) ( $before['supported'] ?? false ) === $record['supported']
			&& time() - (int) ( $before['checked'] ?? 0 ) < self::TTL / 2 ) {
			return;
		}
		update_option( self::OPTION, $record, true );
	}

	/**
	 * Schedule a background refresh when the last check is older than TTL. Never pings
	 * on the shopper's request.
	 */
	public static function maybe_schedule_refresh() {
		if ( '' === self::current_fingerprint() ) {
			return;
		}
		$checked = (int) ( self::record()['checked'] ?? 0 );
		if ( time() - $checked < self::TTL || wp_next_scheduled( self::REFRESH_HOOK ) ) {
			return;
		}
		wp_schedule_single_event( time(), self::REFRESH_HOOK );
	}

	/**
	 * The background refresh: one ping. Tack_Api_Client reports what it saw back here.
	 */
	public static function refresh() {
		( new Tack_Api_Client() )->server_capabilities( true, true );
	}

	/**
	 * Tell administrators, on every admin screen, that the storefront is hidden.
	 */
	public static function admin_notice() {
		if ( ! current_user_can( 'manage_options' ) || ! self::is_stood_down() ) {
			return;
		}
		$message = __( 'TackQuote no longer accepts this store\'s API key (it was revoked or the store was disconnected in TackQuote), so the quote buttons, quote list and application forms are hidden from shoppers.', 'tackquote' );
		if ( 'quote_only' === get_option( 'tack_quotes_store_mode', '' ) ) {
			$message .= ' ' . __( 'Quote-only mode is still on, so shoppers it applies to can neither buy nor request a quote.', 'tackquote' );
		}
		printf(
			'<div class="notice notice-warning"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
			esc_html( $message ),
			esc_url( Tack_Settings::tab_url( 'connection' ) ),
			esc_html__( 'Connect again', 'tackquote' )
		);
	}
}
