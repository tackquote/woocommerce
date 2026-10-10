<?php
/**
 * "Connect with TackQuote": connect this store by signing in to TackQuote and
 * approving, instead of copying an API key.
 *
 * The flow, all of it started by an administrator pressing the button:
 *
 *   1. start    POST admin-post.php?action=tackquote_connect_start (capability +
 *               nonce). This site makes a PKCE code verifier, a signed `state`, and
 *               asks TackQuote, server to server, to open a connect request
 *               (`POST /woocommerce-connect/requests`) naming this site, its own
 *               return address and the verifier's S256 challenge. The verifier and
 *               state are kept in a single-use transient for 15 minutes; the browser
 *               is sent to the consent page TackQuote answered with.
 *   2. consent  On TackQuote the administrator signs in (or creates an account) and
 *               approves or cancels. TackQuote sends the browser back to this site's
 *               `admin-post.php?action=tackquote_connect_return` with a one-time
 *               `code` (or `error=access_denied`) and the `state` unchanged.
 *   3. return   Capability, then the state: its HMAC (per-site secret, constant-time
 *               compare), the administrator who started it, this site's address, its
 *               age, and the transient, taken once. Only then is the code exchanged,
 *               server to server, with the verifier (`POST /woocommerce-connect/exchange`)
 *               for an API key, which is stored exactly like a pasted one and tested
 *               with the normal connection test.
 *
 * Why no WordPress nonce on the return: it is a redirect from another site and cannot
 * carry one. The signed state, bound to the starting administrator and consumed once,
 * plus the capability check, are the forgery defence (the OAuth `state` pattern). A
 * code on its own is useless: redeeming it needs the verifier, which never leaves this
 * site until the exchange.
 *
 * The paste-a-key form keeps working exactly as before; this only adds a second way in.
 *
 * @package TackQuotes
 * @since   1.11.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The "Connect with TackQuote" button, its return handler and the revoke on removal.
 */
class Tack_Connect {

	/**
	 * Per-site secret that signs `state`. Random, created on first use, never sent anywhere.
	 */
	const OPTION_SECRET = 'tack_quotes_connect_secret';

	/**
	 * How the saved key arrived: VIA_CONNECT or VIA_KEY. Absent on a store that saved
	 * a key before 1.11.0, which reads as VIA_KEY.
	 */
	const OPTION_VIA = 'tack_quotes_connected_via';

	/**
	 * Unix time of the last successful Connect.
	 */
	const OPTION_AT = 'tack_quotes_connected_at';

	const VIA_CONNECT = 'connect';
	const VIA_KEY     = 'key';

	/**
	 * `admin-post.php` actions.
	 */
	const START_ACTION  = 'tackquote_connect_start';
	const RETURN_ACTION = 'tackquote_connect_return';

	/**
	 * Nonce action and field of the button's form.
	 */
	const NONCE_ACTION = 'tack_quotes_connect_start';
	const NONCE_FIELD  = '_tack_connect_nonce';

	/**
	 * Single-use transient holding one pending connect, suffixed with a hash of the
	 * state's random part.
	 */
	const PENDING_PREFIX = 'tack_quotes_connect_';

	/**
	 * Per-administrator transient carrying the outcome across the final redirect.
	 */
	const NOTICE_PREFIX = 'tack_quotes_connect_notice_';

	/**
	 * How long a started connect stays usable, in seconds. TackQuote keeps its side of
	 * the request for the same 15 minutes.
	 */
	const TTL = 900;

	/**
	 * Consent-page host for the default API URL. A custom API URL (support or staging)
	 * may answer with another host; then any https host (or a development host) is
	 * accepted, because the administrator already chose to trust that server.
	 */
	const DEFAULT_CONNECT_HOST = 'app.tackquote.com';

	/**
	 * HTTP client; injectable for tests.
	 *
	 * @var Tack_Api_Client|null
	 */
	private $client;

	/**
	 * Constructor.
	 *
	 * @param Tack_Api_Client|null $client Optional HTTP client.
	 */
	public function __construct( $client = null ) {
		$this->client = $client;
	}

	/**
	 * Hook registration (wp-admin only; admin-post.php is an admin request).
	 *
	 * Only the logged-in `admin_post_*` actions are registered: for a signed-out
	 * browser WordPress has no handler and refuses the request.
	 */
	public function init() {
		add_action( 'admin_post_' . self::START_ACTION, array( $this, 'handle_start' ) );
		add_action( 'admin_post_' . self::RETURN_ACTION, array( $this, 'handle_return' ) );
		// A key saved through the settings form was pasted. Connect writes VIA_CONNECT
		// after it stores its key, so it wins over this.
		add_action( 'add_option_tack_quotes_api_key', array( __CLASS__, 'mark_pasted' ) );
		add_action( 'update_option_tack_quotes_api_key', array( __CLASS__, 'mark_pasted' ) );
	}

	/**
	 * Record that the saved key was pasted.
	 */
	public static function mark_pasted() {
		update_option( self::OPTION_VIA, self::VIA_KEY );
	}

	/**
	 * The HTTP client.
	 *
	 * @return Tack_Api_Client
	 */
	private function client() {
		if ( null === $this->client ) {
			$this->client = new Tack_Api_Client();
		}
		return $this->client;
	}

	/**
	 * Both capabilities: the settings page's own (`manage_options`, the key's blast
	 * radius) and WooCommerce's store-management one.
	 *
	 * @return bool
	 */
	public static function can_connect() {
		return current_user_can( 'manage_options' ) && current_user_can( 'manage_woocommerce' );
	}

	// ── Encoding helpers ──────────────────────────────────────────────────────

	/**
	 * Unpadded base64url.
	 *
	 * @param string $bytes Raw bytes.
	 * @return string
	 */
	public static function b64url( $bytes ) {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- RFC 7636 base64url, not obfuscation.
	}

	/**
	 * Decode unpadded base64url; false on anything else.
	 *
	 * @param string $text Encoded text.
	 * @return string|false
	 */
	private static function b64url_decode( $text ) {
		if ( ! is_string( $text ) || ! preg_match( '/^[A-Za-z0-9_-]*$/', $text ) ) {
			return false;
		}
		return base64_decode( strtr( $text, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- RFC 7636 base64url, not obfuscation.
	}

	/**
	 * A new PKCE code verifier: 32 random bytes, base64url, 43 characters
	 * (RFC 7636 section 4.1 allows 43 to 128).
	 *
	 * @return string
	 */
	public static function new_verifier() {
		return self::b64url( random_bytes( 32 ) );
	}

	/**
	 * The S256 challenge of a verifier: base64url(SHA-256(verifier)) (RFC 7636 section 4.2).
	 *
	 * @param string $verifier Code verifier.
	 * @return string
	 */
	public static function challenge( $verifier ) {
		return self::b64url( hash( 'sha256', (string) $verifier, true ) );
	}

	/**
	 * This site's address as the state records it and TackQuote compares it.
	 *
	 * @return string
	 */
	public static function site_url() {
		return rtrim( (string) home_url(), '/' );
	}

	/**
	 * The per-site signing secret, created on first use (not autoloaded).
	 *
	 * @return string
	 */
	private static function secret() {
		$secret = (string) get_option( self::OPTION_SECRET, '' );
		if ( strlen( $secret ) < 32 ) {
			$secret = bin2hex( random_bytes( 32 ) );
			update_option( self::OPTION_SECRET, $secret, false );
		}
		return $secret;
	}

	/**
	 * Sign a state payload.
	 *
	 * @param array $payload v, n, u, s, iat.
	 * @return string `<base64url json>.<base64url hmac>`
	 */
	public static function sign_state( array $payload ) {
		$body = self::b64url( (string) wp_json_encode( $payload ) );
		return $body . '.' . self::b64url( hash_hmac( 'sha256', $body, self::secret(), true ) );
	}

	/**
	 * Verify a state's signature and decode it. Constant-time compare.
	 *
	 * @param string $state State as it came back.
	 * @return array|null The payload, or null when it is not one this site signed.
	 */
	public static function verify_state( $state ) {
		if ( ! is_string( $state ) || strlen( $state ) > 512 || 1 !== substr_count( $state, '.' ) ) {
			return null;
		}
		list( $body, $mac ) = explode( '.', $state );
		$expected           = self::b64url( hash_hmac( 'sha256', $body, self::secret(), true ) );
		if ( ! hash_equals( $expected, $mac ) ) {
			return null;
		}
		$json    = self::b64url_decode( $body );
		$payload = false !== $json ? json_decode( $json, true ) : null;
		if ( ! is_array( $payload ) || 1 !== ( $payload['v'] ?? 0 ) || ! is_string( $payload['n'] ?? null ) ) {
			return null;
		}
		return $payload;
	}

	/**
	 * Transient name of the pending connect whose state carries random part `$n`.
	 *
	 * @param string $n The state's random part.
	 * @return string
	 */
	public static function pending_key( $n ) {
		return self::PENDING_PREFIX . substr( hash( 'sha256', (string) $n ), 0, 32 );
	}

	// ── Notices ───────────────────────────────────────────────────────────────

	/**
	 * Remember an outcome for the current administrator's next settings page view.
	 *
	 * @param string $type    success | warning | error.
	 * @param string $message Translated text.
	 * @return array{type:string,message:string}
	 */
	private static function notice( $type, $message ) {
		$notice = array(
			'type'    => $type,
			'message' => $message,
		);
		set_transient( self::NOTICE_PREFIX . get_current_user_id(), $notice, 5 * MINUTE_IN_SECONDS );
		return $notice;
	}

	/**
	 * Take (read and delete) the current administrator's pending notice.
	 *
	 * @return array{type:string,message:string}|null
	 */
	public static function take_notice() {
		$key    = self::NOTICE_PREFIX . get_current_user_id();
		$notice = get_transient( $key );
		if ( false === $notice ) {
			return null;
		}
		delete_transient( $key );
		return is_array( $notice ) && isset( $notice['type'], $notice['message'] ) ? $notice : null;
	}

	// ── 1. Start ──────────────────────────────────────────────────────────────

	/**
	 * `admin_post_tackquote_connect_start`.
	 */
	public function handle_start() {
		$outcome = $this->start();
		if ( isset( $outcome['redirect'] ) ) {
			$this->redirect( $outcome['redirect'], $outcome['host'] );
			return;
		}
		$this->redirect( Tack_Settings::tab_url( 'connection' ), '' );
	}

	/**
	 * Open a connect request with TackQuote.
	 *
	 * @return array `{redirect, host}` on success; `{type, message}` (also stored as
	 *               the notice) on a refusal.
	 */
	public function start() {
		if ( ! self::can_connect() ) {
			wp_die( esc_html__( 'You do not have permission to connect this store to TackQuote.', 'tackquote' ), '', array( 'response' => 403 ) );
		}
		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'This Connect with TackQuote link has expired. Go back to TackQuote → Connection and press the button again.', 'tackquote' ), '', array( 'response' => 403 ) );
		}

		$site   = self::site_url();
		$scheme = strtolower( (string) wp_parse_url( $site, PHP_URL_SCHEME ) );
		$host   = strtolower( (string) wp_parse_url( $site, PHP_URL_HOST ) );
		if ( 'https' !== $scheme && ! ( 'http' === $scheme && Tack_Settings::is_non_public_host( $host ) ) ) {
			return self::notice( 'error', __( 'Connect needs your site to use https. Use an API key, or switch your site to https.', 'tackquote' ) );
		}

		$verifier = self::new_verifier();
		$n        = self::b64url( random_bytes( 16 ) );
		$state    = self::sign_state(
			array(
				'v'   => 1,
				'n'   => $n,
				'u'   => (int) get_current_user_id(),
				's'   => $site,
				'iat' => time(),
			)
		);

		$body = array(
			'siteUrl'             => $site,
			'adminUrl'            => admin_url(),
			'redirectUri'         => admin_url( 'admin-post.php?action=' . self::RETURN_ACTION ),
			'state'               => $state,
			'codeChallenge'       => self::challenge( $verifier ),
			'codeChallengeMethod' => 'S256',
			'siteName'            => self::clip( wp_strip_all_tags( (string) get_bloginfo( 'name' ) ), 120 ),
			'pluginVersion'       => self::clip( TACK_QUOTES_VERSION, 32 ),
			'wpVersion'           => self::clip( (string) get_bloginfo( 'version' ), 20 ),
			'locale'              => self::clip( (string) get_locale(), 20 ),
		);
		if ( defined( 'WC_VERSION' ) ) {
			$body['wcVersion'] = self::clip( (string) WC_VERSION, 20 );
		}
		$body = array_filter( $body, 'strlen' );

		$answer = $this->client()->public_request( 'POST', '/woocommerce-connect/requests', $body );
		if ( is_wp_error( $answer ) ) {
			return self::notice( 'error', self::error_message( $answer, 'start' ) );
		}

		$request_id  = isset( $answer['requestId'] ) && is_string( $answer['requestId'] ) ? $answer['requestId'] : '';
		$connect_url = isset( $answer['connectUrl'] ) && is_string( $answer['connectUrl'] ) ? $answer['connectUrl'] : '';
		$target_host = self::connect_url_host( $connect_url, $request_id );
		if ( '' === $target_host ) {
			return self::notice( 'error', __( 'TackQuote answered with a sign-in address this plugin will not open, so nothing was changed. Check the API URL, or paste an API key instead.', 'tackquote' ) );
		}

		set_transient(
			self::pending_key( $n ),
			array(
				'u'          => (int) get_current_user_id(),
				'verifier'   => $verifier,
				'state'      => hash( 'sha256', $state ),
				'request_id' => $request_id,
				'iat'        => time(),
			),
			self::TTL
		);

		return array(
			'redirect' => $connect_url,
			'host'     => $target_host,
		);
	}

	/**
	 * The host of the consent page TackQuote answered with, or '' when it must not be
	 * opened: not https (http only on a development host), not the TackQuote app for
	 * the default API URL, or not the consent page for this very request.
	 *
	 * @param string $url        connectUrl.
	 * @param string $request_id requestId.
	 * @return string
	 */
	public static function connect_url_host( $url, $request_id ) {
		if ( ! preg_match( '/^wcr_[A-Za-z0-9_-]{22}$/', (string) $request_id ) ) {
			return '';
		}
		$parts = wp_parse_url( (string) $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) ) {
			return '';
		}
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( (string) $parts['host'] );
		if ( 'https' !== $scheme && ! ( 'http' === $scheme && Tack_Settings::is_non_public_host( $host ) ) ) {
			return '';
		}
		$api = rtrim( (string) get_option( 'tack_quotes_api_url', Tack_Settings::DEFAULT_API_URL ), '/' );
		if ( ( '' === $api || Tack_Settings::DEFAULT_API_URL === $api ) && self::DEFAULT_CONNECT_HOST !== $host ) {
			return '';
		}
		$path = (string) ( $parts['path'] ?? '' );
		if ( '/connect/woocommerce' !== substr( $path, -strlen( '/connect/woocommerce' ) ) ) {
			return '';
		}
		parse_str( (string) ( $parts['query'] ?? '' ), $query );
		if ( ( $query['request'] ?? '' ) !== $request_id ) {
			return '';
		}
		return $host;
	}

	// ── 3. Return ─────────────────────────────────────────────────────────────

	/**
	 * `admin_post_tackquote_connect_return`.
	 */
	public function handle_return() {
		// The address bar holds a one-time code until the redirect below replaces it.
		if ( ! headers_sent() ) {
			header( 'Referrer-Policy: no-referrer' );
		}

		/*
		 * A redirect from TackQuote cannot carry a WordPress nonce; finish() verifies the
		 * signed, single-use state instead. Only these three parameters are read.
		 */
		$query = array();
		foreach ( array( 'code', 'state', 'error' ) as $name ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above: the signed state is the forgery check.
			if ( isset( $_GET[ $name ] ) && is_string( $_GET[ $name ] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above: the signed state is the forgery check.
				$query[ $name ] = sanitize_text_field( wp_unslash( $_GET[ $name ] ) );
			}
		}
		$this->finish( $query );
		$this->redirect( Tack_Settings::tab_url( 'connection' ), '' );
	}

	/**
	 * Verify the return and exchange the code. Never reports success it did not get.
	 *
	 * @param array $query The return's query parameters (only code, state, error are read).
	 * @return array{type:string,message:string} The notice shown next.
	 */
	public function finish( $query ) {
		if ( ! self::can_connect() ) {
			wp_die( esc_html__( 'You do not have permission to connect this store to TackQuote.', 'tackquote' ), '', array( 'response' => 403 ) );
		}
		$query = is_array( $query ) ? $query : array();
		$code  = isset( $query['code'] ) && is_string( $query['code'] ) ? sanitize_text_field( $query['code'] ) : '';
		$state = isset( $query['state'] ) && is_string( $query['state'] ) ? sanitize_text_field( $query['state'] ) : '';
		$error = isset( $query['error'] ) && is_string( $query['error'] ) ? sanitize_key( $query['error'] ) : '';

		$restart = __( 'Press Connect with TackQuote again.', 'tackquote' );

		$payload = self::verify_state( $state );
		if ( null === $payload ) {
			return self::notice( 'error', __( 'TackQuote was not connected: this sign-in response could not be verified, so nothing was changed.', 'tackquote' ) . ' ' . $restart );
		}
		if ( (int) ( $payload['u'] ?? 0 ) !== (int) get_current_user_id() ) {
			return self::notice( 'error', __( 'TackQuote was not connected: this sign-in was started by another administrator. Nothing was changed.', 'tackquote' ) . ' ' . $restart );
		}
		if ( ( $payload['s'] ?? '' ) !== self::site_url() ) {
			return self::notice( 'error', __( 'TackQuote was not connected: this sign-in was started for a different site address. Nothing was changed.', 'tackquote' ) . ' ' . $restart );
		}

		// Taken once, before anything else can use it: a replayed return finds nothing.
		$key     = self::pending_key( $payload['n'] );
		$pending = get_transient( $key );
		delete_transient( $key );
		$iat = (int) ( $payload['iat'] ?? 0 );
		if ( ! is_array( $pending ) || time() - $iat > self::TTL || $iat > time() + 60
			|| (int) ( $pending['u'] ?? 0 ) !== (int) get_current_user_id()
			|| ! hash_equals( (string) ( $pending['state'] ?? '' ), hash( 'sha256', $state ) )
			|| ! is_string( $pending['verifier'] ?? null ) ) {
			return self::notice( 'error', __( 'TackQuote was not connected: this sign-in expired or was already used. Nothing was changed.', 'tackquote' ) . ' ' . $restart );
		}

		if ( '' !== $error ) {
			if ( 'access_denied' === $error ) {
				return self::notice( 'warning', __( 'TackQuote was not connected. Nothing changed.', 'tackquote' ) );
			}
			return self::notice( 'error', __( 'TackQuote was not connected: the sign-in did not finish. Nothing was changed.', 'tackquote' ) . ' ' . $restart );
		}
		if ( ! preg_match( '/^[A-Za-z0-9_-]{43}$/', $code ) ) {
			return self::notice( 'error', __( 'TackQuote was not connected: the sign-in response carried no usable code. Nothing was changed.', 'tackquote' ) . ' ' . $restart );
		}

		$answer = $this->client()->public_request(
			'POST',
			'/woocommerce-connect/exchange',
			array(
				'code'         => $code,
				'codeVerifier' => $pending['verifier'],
				'siteUrl'      => self::site_url(),
			)
		);
		if ( is_wp_error( $answer ) ) {
			return self::notice( 'error', self::error_message( $answer, 'exchange' ) );
		}

		$api_key = isset( $answer['apiKey'] ) && is_string( $answer['apiKey'] ) ? $answer['apiKey'] : '';
		if ( ! self::is_usable_key( $api_key ) ) {
			return self::notice( 'error', __( 'TackQuote answered without a usable API key, so nothing was changed. Press Connect with TackQuote again, or paste an API key.', 'tackquote' ) );
		}

		// The same option, sanitiser and masked display as a pasted key.
		update_option( 'tack_quotes_api_key', $api_key );
		update_option( self::OPTION_VIA, self::VIA_CONNECT );
		update_option( self::OPTION_AT, time() );
		delete_transient( 'tack_quotes_registration_config' );
		delete_transient( Tack_Settings::CONNECTION_CHECK );
		$this->client()->forget_capabilities();

		$workspace = isset( $answer['tenantName'] ) && is_string( $answer['tenantName'] ) ? self::clip( wp_strip_all_tags( $answer['tenantName'] ), 120 ) : '';
		$test      = Tack_Settings::record_connection_test();
		if ( ! empty( $test['ok'] ) ) {
			$message = '' !== $workspace
				/* translators: %s: TackQuote workspace name. */
				? sprintf( __( 'Connected to TackQuote (workspace: %s). The connection test passed.', 'tackquote' ), $workspace )
				: __( 'Connected to TackQuote. The connection test passed.', 'tackquote' );
			return self::notice( 'success', $message );
		}
		return self::notice(
			'warning',
			__( 'TackQuote sent an API key and it was saved, but the connection test did not pass:', 'tackquote' ) . ' ' . $test['message']
		);
	}

	/**
	 * A key shape worth storing: the characters the settings sanitiser keeps, of a
	 * plausible length. The connection test then proves it works.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	public static function is_usable_key( $key ) {
		return is_string( $key ) && strlen( $key ) >= 20 && strlen( $key ) <= 200 && 1 === preg_match( '/^[A-Za-z0-9._\-]+$/', $key );
	}

	/**
	 * The notice for a failed request to TackQuote. Names what happened and what to do;
	 * appends TackQuote's own sentence when it sent one.
	 *
	 * @param WP_Error $error Error from Tack_Api_Client.
	 * @param string   $step  start | exchange.
	 * @return string
	 */
	private static function error_message( $error, $step ) {
		$data   = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
		$said   = 0 !== $status && is_array( $data ) && ! empty( $data['json'] ) ? self::clip( wp_strip_all_tags( $error->get_error_message() ), 300 ) : '';

		if ( 0 === $status ) {
			$message = __( 'Could not reach TackQuote, so nothing was changed. Check the API URL, then try again in a few minutes.', 'tackquote' );
			$said    = '';
		} elseif ( 404 === $status ) {
			$message = __( 'This TackQuote server does not support Connect with TackQuote. Paste an API key instead.', 'tackquote' );
			$said    = '';
		} elseif ( 429 === $status || $status >= 500 ) {
			$message = __( 'TackQuote is busy or unavailable right now, so nothing was changed. Try again in a few minutes.', 'tackquote' );
		} elseif ( 403 === $status ) {
			$message = __( 'TackQuote did not connect this store: your TackQuote plan does not allow it. Nothing was changed on this site.', 'tackquote' );
		} elseif ( 'start' === $step ) {
			$message = __( 'TackQuote refused to start connecting this site, so nothing was changed.', 'tackquote' );
		} else {
			$message = __( 'TackQuote refused the sign-in code: it is invalid, expired or already used. Nothing was changed.', 'tackquote' );
		}
		return '' !== $said && $said !== $message ? $message . ' ' . $said : $message;
	}

	// ── Disconnect ────────────────────────────────────────────────────────────

	/**
	 * Revoke the saved key in TackQuote when "Connect with TackQuote" issued it. Best
	 * effort: the caller deletes the key from this site whatever this answers.
	 *
	 * @return bool|null null when the key was pasted (nothing to revoke), true when
	 *                   TackQuote revoked it, false when it could not be reached or refused.
	 */
	public static function revoke_saved_key() {
		if ( self::VIA_CONNECT !== get_option( self::OPTION_VIA, '' ) || '' === (string) get_option( 'tack_quotes_api_key', '' ) ) {
			return null;
		}
		$result = ( new Tack_Api_Client() )->request( 'POST', '/integrations/woocommerce/plugin-key/revoke', array(), Tack_Api_Client::INTERACTIVE_TIMEOUT );
		return ! is_wp_error( $result );
	}

	// ── Presentation ──────────────────────────────────────────────────────────

	/**
	 * "Connected via" for the Overview and the Connection tab.
	 *
	 * @return string
	 */
	public static function connected_via_label() {
		return self::VIA_CONNECT === get_option( self::OPTION_VIA, '' )
			? __( 'Connect with TackQuote', 'tackquote' )
			: __( 'API key', 'tackquote' );
	}

	/**
	 * The Connect card at the top of the Connection tab.
	 */
	public static function render_card() {
		$has_key = '' !== (string) get_option( 'tack_quotes_api_key', '' );
		$via     = self::VIA_CONNECT === get_option( self::OPTION_VIA, '' );

		echo '<section class="tack-card tack-connect" aria-labelledby="tack-connect-heading"><div class="tack-card__head"><h2 id="tack-connect-heading">' . esc_html__( 'Connect with TackQuote', 'tackquote' ) . '</h2>';
		if ( $has_key ) {
			printf( '<span class="tack-pill tack-pill--ok">%s</span>', esc_html( sprintf( /* translators: %s: Connect with TackQuote, or API key. */ __( 'Connected via %s', 'tackquote' ), self::connected_via_label() ) ) );
		}
		echo '</div><div class="tack-card__body">';

		if ( $has_key && $via ) {
			echo '<p>' . esc_html__( 'This store is connected with TackQuote. Connect again to issue a new key for this site; the previous one is revoked.', 'tackquote' ) . '</p>';
		} elseif ( $has_key ) {
			echo '<p>' . esc_html__( 'This store uses a pasted API key. Connecting with TackQuote replaces the saved key on this site. The old key keeps working until you revoke it in TackQuote.', 'tackquote' ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'Connect this store to your TackQuote account. Press Connect with TackQuote, sign in or create an account, and approve; nothing to copy.', 'tackquote' ) . '</p>';
		}

		printf( '<form method="post" action="%s" class="tack-connect__form">', esc_url( admin_url( 'admin-post.php' ) ) );
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( self::START_ACTION ) );
		submit_button( $has_key && $via ? __( 'Connect again', 'tackquote' ) : __( 'Connect with TackQuote', 'tackquote' ), $has_key ? 'secondary' : 'primary', 'tack_connect', false );
		echo '</form>';
		echo '<p class="description">' . esc_html__( 'Opens TackQuote in this window. TackQuote creates an API key for this plugin and sends it back to this site directly.', 'tackquote' ) . '</p>';
		echo '<details class="tack-more"><summary>' . esc_html__( 'Learn more', 'tackquote' ) . '</summary>';
		echo '<p>' . esc_html__( 'Pressing the button sends TackQuote this site\'s address, its WordPress admin address, the site name, the plugin, WordPress and WooCommerce versions, the site language, a random signed value and a one-way code challenge. No customer, order or product data.', 'tackquote' ) . '</p>';
		echo '<p>' . esc_html__( 'TackQuote will be able to: create quote requests from this store, receive orders when you switch order sync on, and receive wholesale and net-terms applications. The key is stored on this site like a pasted key. Removing the saved key below also revokes a key that Connect created.', 'tackquote' ) . '</p>';
		echo '</details>';
		echo '</div></section>';
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Trim text to at most `$max` characters.
	 *
	 * @param string $text Text.
	 * @param int    $max  Maximum length.
	 * @return string
	 */
	private static function clip( $text, $max ) {
		$text = trim( (string) $text );
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max ) : substr( $text, 0, $max );
	}

	/**
	 * Redirect and end the request. To TackQuote's consent page the host is allowed for
	 * this one redirect only; everything else stays on this site.
	 *
	 * @param string $url  Target.
	 * @param string $host External host to allow, or ''.
	 */
	protected function redirect( $url, $host ) {
		if ( '' !== $host ) {
			add_filter(
				'allowed_redirect_hosts',
				function ( $hosts ) use ( $host ) {
					$hosts[] = $host;
					return $hosts;
				}
			);
		}
		wp_safe_redirect( $url );
		exit;
	}
}
