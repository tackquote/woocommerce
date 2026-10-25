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
 * Fail closed to HIDDEN only on an explicit answer, never on a network problem:
 *
 *   - a 401 that TackQuote itself sent (a JSON body carrying `statusCode: 401`), or
 *   - a ping answering `standing: "disconnected"`.
 *
 * A timeout, a 5xx, a 429, a 403 (a key without a scope) or a 401 from a proxy in
 * front of the API leaves the state as it was. And the plugin stands down only for a
 * key that a server listing the `standing` capability has answered before, so an
 * older TackQuote server never causes it.
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
	 * `{state: active|stood_down, reason: key_revoked|disconnected|'', key: fingerprint,
	 * supported: bool, checked: int}`. Small and read on every storefront request, so it
	 * is autoloaded.
	 */
	const OPTION = 'tack_quotes_standing';

	const STATE_ACTIVE     = 'active';
	const STATE_STOOD_DOWN = 'stood_down';

	const REASON_REVOKED      = 'key_revoked';
	const REASON_DISCONNECTED = 'disconnected';

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
	 * Why, for the admin notice: key_revoked | disconnected | ''.
	 *
	 * @return string
	 */
	public static function reason() {
		return self::is_stood_down() ? (string) ( self::record()['reason'] ?? '' ) : '';
	}

	/**
	 * A ping answered 2xx. Active, unless it says the store is disconnected (and the
	 * server lists `standing`).
	 *
	 * @param array    $answer       Decoded ping answer.
	 * @param string[] $capabilities The capabilities it listed.
	 * @param string   $key          The key it was asked with.
	 */
	public static function observe_ping( $answer, array $capabilities, $key ) {
		$supported = in_array( 'standing', $capabilities, true );
		$standing  = is_array( $answer ) && isset( $answer['standing'] ) && is_string( $answer['standing'] ) ? $answer['standing'] : '';
		$down      = $supported && self::REASON_DISCONNECTED === $standing;
		self::write(
			array(
				'state'     => $down ? self::STATE_STOOD_DOWN : self::STATE_ACTIVE,
				'reason'    => $down ? self::REASON_DISCONNECTED : '',
				'key'       => self::fingerprint( $key ),
				'supported' => $supported,
				'checked'   => time(),
			)
		);
	}

	/**
	 * A request was refused. Stands down only for TackQuote's OWN 401 (JSON carrying
	 * `statusCode: 401`) on a key a `standing` server has answered before.
	 *
	 * @param array  $error_data WP_Error data from Tack_Api_Client.
	 * @param string $key        The key it was sent with.
	 */
	public static function observe_refusal( $error_data, $key ) {
		$status = is_array( $error_data ) ? (int) ( $error_data['status'] ?? 0 ) : 0;
		$own    = is_array( $error_data ) && ! empty( $error_data['json'] ) && 401 === (int) ( $error_data['statusCode'] ?? 0 );
		if ( 401 !== $status || ! $own ) {
			return;
		}
		$fingerprint = self::fingerprint( $key );
		$record      = self::record();
		if ( empty( $record['supported'] ) || ! hash_equals( (string) ( $record['key'] ?? '' ), $fingerprint ) ) {
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
		( new Tack_Api_Client() )->server_capabilities( true );
	}

	/**
	 * Tell administrators, on every admin screen, that the storefront is hidden.
	 */
	public static function admin_notice() {
		if ( ! current_user_can( 'manage_options' ) || ! self::is_stood_down() ) {
			return;
		}
		$message = self::REASON_DISCONNECTED === self::reason()
			? __( 'TackQuote disconnected this store, so its quote buttons, quote list and application forms are hidden from shoppers.', 'tackquote' )
			: __( 'TackQuote no longer accepts this store\'s API key (it was revoked or the store was disconnected in TackQuote), so the quote buttons, quote list and application forms are hidden from shoppers.', 'tackquote' );
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
