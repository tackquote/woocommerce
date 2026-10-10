<?php
/**
 * Connect with TackQuote (1.11.0): PKCE, the signed single-use state, the exchange,
 * every refusal's notice, the revoke on removal, and the Connection tab.
 *
 * Wire contract (TackQuote API, woocommerce-connect module):
 *   POST /woocommerce-connect/requests  {siteUrl, adminUrl, redirectUri, state,
 *        codeChallenge, codeChallengeMethod:'S256', siteName?, pluginVersion?,
 *        wcVersion?, wpVersion?, locale?} -> {requestId, connectUrl, expiresIn}
 *   POST /woocommerce-connect/exchange  {code, codeVerifier, siteUrl}
 *        -> {apiKey, scopes, integrationId, tenantName}
 *   POST /integrations/woocommerce/plugin-key/revoke (with the key) -> 204
 *
 * Included from tests/run.php after connection-test-test.php (it reuses the HTTP router
 * from attachments-test.php).
 *
 * @package TackQuotes
 */

if ( ! function_exists( 'get_bloginfo' ) ) {
	/** @param string $show Field. @return string */
	function get_bloginfo( $show = '' ) {
		return 'version' === $show ? '6.8.1' : 'Shop <b>Example</b>';
	}
}
if ( ! function_exists( 'get_locale' ) ) {
	/** @return string */
	function get_locale() {
		return 'en_GB';
	}
}

/** Records redirects instead of ending the request. */
class Tack_Test_Connect extends Tack_Connect {
	/** @var array */
	public $redirects = array();
	/** @param string $url URL. @param string $host Host. */
	protected function redirect( $url, $host ) {
		$this->redirects[] = array( $url, $host );
	}
}

/**
 * Answer per URL fragment; a value of 'TRANSPORT' answers a WP_Error (no response).
 *
 * @param array $routes fragment => [status, body] | 'TRANSPORT'.
 */
function tack_cx_routes( array $routes ) {
	$GLOBALS['TACK_HTTP_REQUESTS']  = array();
	$GLOBALS['TACK_HTTP_RESPONDER'] = function ( $url, $args ) use ( $routes ) {
		foreach ( $routes as $fragment => $answer ) {
			if ( false !== strpos( $url, $fragment ) ) {
				if ( 'TRANSPORT' === $answer ) {
					return new WP_Error( 'http_request_failed', 'cURL error 28: timed out' );
				}
				return array(
					'response' => array( 'code' => $answer[0] ),
					'body'     => is_string( $answer[1] ) ? $answer[1] : wp_json_encode( $answer[1] ),
					'headers'  => array(),
				);
			}
		}
		return array(
			'response' => array( 'code' => 404 ),
			'body'     => '{"statusCode":404,"message":"Cannot POST"}',
			'headers'  => array(),
		);
	};
}

/** @param string $fragment Fragment. @return array */
function tack_cx_calls( $fragment ) {
	return tack_test_attach_calls( $fragment );
}

$cx_request_id = 'wcr_' . str_repeat( 'A', 22 );
$cx_connect    = 'https://app.tackquote.com/auth/connect/woocommerce?request=' . $cx_request_id;
$cx_created    = array( 201, array( 'requestId' => $cx_request_id, 'connectUrl' => $cx_connect, 'expiresIn' => 900 ) );
$cx_new_key    = 'tq_connect_issued_key_' . str_repeat( 'a1', 20 );
$cx_old_key    = 'tq_pasted_old_key_9876543210';
$cx_exchanged  = array( 200, array( 'apiKey' => $cx_new_key, 'scopes' => array( 'quotes:write', 'orders:write', 'buyers:write' ), 'integrationId' => 'i-1', 'tenantName' => 'Northwind' ) );
$cx_ping_ok    = array( 200, array( 'ok' => true, 'tenantId' => 't-1' ) );
$cx_code       = str_repeat( 'C', 43 );

/**
 * Reset to a clean, signed-in administrator on the default API URL with a pasted key.
 */
function tack_cx_reset() {
	global $cx_old_key;
	tack_test_reset_transients();
	$GLOBALS['TACK_CAPS']      = array( 'manage_options', 'manage_woocommerce' );
	$GLOBALS['TACK_LOGGED_IN'] = true;
	$GLOBALS['TACK_HOME_URL']  = 'https://shop.example';
	$_POST                     = array();
	$_GET                      = array();
	tack_test_set_option( 'tack_quotes_api_url', Tack_Settings::DEFAULT_API_URL );
	tack_test_set_option( 'tack_quotes_api_key', $cx_old_key );
	unset( $GLOBALS['TACK_OPTIONS'][ Tack_Connect::OPTION_VIA ], $GLOBALS['TACK_OPTIONS'][ Tack_Connect::OPTION_AT ] );
}

/**
 * Press the button as the form would and return the outcome.
 *
 * @param Tack_Connect $connect Instance.
 * @return array
 */
function tack_cx_start( $connect ) {
	$_POST = array( Tack_Connect::NONCE_FIELD => wp_create_nonce( Tack_Connect::NONCE_ACTION ) );
	return $connect->start();
}

/**
 * The state and the pending transient a started connect left behind.
 *
 * @return array{state:string,pending:array,body:array}
 */
function tack_cx_last_start() {
	$calls = tack_cx_calls( '/woocommerce-connect/requests' );
	$body  = json_decode( $calls[ count( $calls ) - 1 ]['args']['body'], true );
	$pl    = Tack_Connect::verify_state( $body['state'] );
	return array(
		'state'   => $body['state'],
		'pending' => get_transient( Tack_Connect::pending_key( $pl['n'] ) ),
		'body'    => $body,
		'args'    => $calls[ count( $calls ) - 1 ]['args'],
	);
}

/**
 * Start (successfully) and return what the return needs.
 *
 * @return array{state:string,pending:array,body:array}
 */
function tack_cx_started() {
	global $cx_created;
	tack_cx_routes( array( '/woocommerce-connect/requests' => $cx_created ) );
	tack_cx_start( new Tack_Test_Connect() );
	return tack_cx_last_start();
}

// ── PKCE (RFC 7636) ────────────────────────────────────────────────────────────
check( 'PKCE: S256 matches the RFC 7636 Appendix B vector', 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM' === Tack_Connect::challenge( 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk' ) );
$cx_v = Tack_Connect::new_verifier();
check( 'PKCE: a verifier is 43 to 128 base64url characters', strlen( $cx_v ) >= 43 && strlen( $cx_v ) <= 128 && 1 === preg_match( '/^[A-Za-z0-9_-]+$/', $cx_v ) );
check( 'PKCE: a challenge is 43 base64url characters', 1 === preg_match( '/^[A-Za-z0-9_-]{43}$/', Tack_Connect::challenge( $cx_v ) ) );
check( 'PKCE: verifiers are random', Tack_Connect::new_verifier() !== Tack_Connect::new_verifier() );

// ── State signing ──────────────────────────────────────────────────────────────
tack_cx_reset();
$cx_payload = array( 'v' => 1, 'n' => 'nonce1', 'u' => 1, 's' => 'https://shop.example', 'iat' => time() );
$cx_state   = Tack_Connect::sign_state( $cx_payload );
check( 'state: round trip', $cx_payload === Tack_Connect::verify_state( $cx_state ) );
check( 'state: the per-site secret was created (not autoloaded, never empty)', strlen( (string) get_option( Tack_Connect::OPTION_SECRET, '' ) ) >= 32 );
list( $cx_b, $cx_m ) = explode( '.', $cx_state );
$cx_forged_body      = Tack_Connect::b64url( wp_json_encode( array_merge( $cx_payload, array( 'u' => 2 ) ) ) );
check( 'state: a changed payload with the old signature is refused', null === Tack_Connect::verify_state( $cx_forged_body . '.' . $cx_m ) );
check( 'state: a changed signature is refused', null === Tack_Connect::verify_state( $cx_b . '.' . strrev( $cx_m ) ) );
check( 'state: garbage is refused', null === Tack_Connect::verify_state( 'abc' ) && null === Tack_Connect::verify_state( '' ) );
$cx_saved_secret = get_option( Tack_Connect::OPTION_SECRET );
tack_test_set_option( Tack_Connect::OPTION_SECRET, str_repeat( 'f', 64 ) );
check( 'state: another site\'s secret does not verify it', null === Tack_Connect::verify_state( $cx_state ) );
tack_test_set_option( Tack_Connect::OPTION_SECRET, $cx_saved_secret );

// ── Start ──────────────────────────────────────────────────────────────────────
tack_cx_reset();
$GLOBALS['TACK_CAPS'] = array( 'manage_woocommerce' );
$cx_died              = false;
try {
	tack_cx_start( new Tack_Test_Connect() );
} catch ( Tack_Test_Wp_Die $e ) {
	$cx_died = true;
}
check( 'start: refused without manage_options', $cx_died );
$GLOBALS['TACK_CAPS'] = array( 'manage_options' );
$cx_died              = false;
try {
	tack_cx_start( new Tack_Test_Connect() );
} catch ( Tack_Test_Wp_Die $e ) {
	$cx_died = true;
}
check( 'start: refused without manage_woocommerce', $cx_died );

tack_cx_reset();
tack_cx_routes( array( '/woocommerce-connect/requests' => $cx_created ) );
$_POST   = array( Tack_Connect::NONCE_FIELD => 'forged' );
$cx_died = false;
try {
	( new Tack_Test_Connect() )->start();
} catch ( Tack_Test_Wp_Die $e ) {
	$cx_died = true;
}
check( 'start: refused without a valid nonce, and nothing is sent', $cx_died && 0 === count( $GLOBALS['TACK_HTTP_REQUESTS'] ) );

tack_cx_reset();
$GLOBALS['TACK_HOME_URL'] = 'http://shop.example';
tack_cx_routes( array( '/woocommerce-connect/requests' => $cx_created ) );
$cx_out = tack_cx_start( new Tack_Test_Connect() );
check( 'start: a public http site is refused locally with the https message', ! isset( $cx_out['redirect'] ) && false !== strpos( $cx_out['message'], 'needs your site to use https' ) );
check( 'start: ... and nothing is sent', 0 === count( $GLOBALS['TACK_HTTP_REQUESTS'] ) );
check( 'start: ... and the notice waits for the settings page', $cx_out === Tack_Connect::take_notice() && null === Tack_Connect::take_notice() );

tack_cx_reset();
$GLOBALS['TACK_HOME_URL'] = 'http://shop.test';
tack_cx_routes( array( '/woocommerce-connect/requests' => $cx_created ) );
$cx_out = tack_cx_start( new Tack_Test_Connect() );
check( 'start: an http development host may connect', isset( $cx_out['redirect'] ) );

tack_cx_reset();
$cx_s   = tack_cx_started();
$cx_out = ( new Tack_Test_Connect() );
check( 'start: the request is sent to /woocommerce-connect/requests', 1 === count( tack_cx_calls( '/woocommerce-connect/requests' ) ) );
check( 'start: the request carries NO API key (Authorization, X-Api-Key)', ! isset( $cx_s['args']['headers']['Authorization'] ) && ! isset( $cx_s['args']['headers']['X-Api-Key'] ) && false === strpos( wp_json_encode( $cx_s['args'] ), $cx_old_key ) );
check( 'start: the request carries the plugin version and site headers', isset( $cx_s['args']['headers']['X-TackQuote-Plugin-Version'], $cx_s['args']['headers']['X-TackQuote-Site-Url'] ) );
check( 'start: the request never follows a redirect', 0 === $cx_s['args']['redirection'] );
check( 'start: body siteUrl/adminUrl/redirectUri per the contract', 'https://shop.example' === $cx_s['body']['siteUrl'] && 'https://shop.example/wp-admin/' === $cx_s['body']['adminUrl'] && 'https://shop.example/wp-admin/admin-post.php?action=tackquote_connect_return' === $cx_s['body']['redirectUri'] );
check( 'start: S256 challenge of the stored verifier', 'S256' === $cx_s['body']['codeChallengeMethod'] && Tack_Connect::challenge( $cx_s['pending']['verifier'] ) === $cx_s['body']['codeChallenge'] );
check( 'start: the verifier never leaves the site on the request', false === strpos( $cx_s['args']['body'], $cx_s['pending']['verifier'] ) );
check( 'start: the state matches the DTO charset and length', strlen( $cx_s['body']['state'] ) <= 512 && 1 === preg_match( '/^[A-Za-z0-9_.-]+$/', $cx_s['body']['state'] ) );
check( 'start: the body has only DTO fields', array() === array_diff( array_keys( $cx_s['body'] ), array( 'siteUrl', 'adminUrl', 'redirectUri', 'state', 'codeChallenge', 'codeChallengeMethod', 'siteName', 'pluginVersion', 'wcVersion', 'wpVersion', 'locale' ) ) );
check( 'start: siteName is plain text', 'Shop Example' === $cx_s['body']['siteName'] );
check( 'start: the pending transient binds user, state and request id', 1 === $cx_s['pending']['u'] && hash( 'sha256', $cx_s['state'] ) === $cx_s['pending']['state'] && $cx_request_id === $cx_s['pending']['request_id'] );
$cx_t   = new Tack_Test_Connect();
$_POST  = array( Tack_Connect::NONCE_FIELD => wp_create_nonce( Tack_Connect::NONCE_ACTION ) );
$cx_out = $cx_t->start();
check( 'start: the browser goes to connectUrl, the app host allowed for that redirect only', $cx_connect === $cx_out['redirect'] && 'app.tackquote.com' === $cx_out['host'] );
$cx_t->handle_start();
check( 'handle_start: redirects to the consent page', array( $cx_connect, 'app.tackquote.com' ) === end( $cx_t->redirects ) );

// The consent URL the plugin will open.
check( 'connectUrl: plain http on a public host is refused', '' === Tack_Connect::connect_url_host( 'http://app.tackquote.com/auth/connect/woocommerce?request=' . $cx_request_id, $cx_request_id ) );
check( 'connectUrl: another host for the default API URL is refused', '' === Tack_Connect::connect_url_host( 'https://evil.example/auth/connect/woocommerce?request=' . $cx_request_id, $cx_request_id ) );
check( 'connectUrl: a different request id is refused', '' === Tack_Connect::connect_url_host( 'https://app.tackquote.com/auth/connect/woocommerce?request=wcr_' . str_repeat( 'B', 22 ), $cx_request_id ) );
check( 'connectUrl: another path is refused', '' === Tack_Connect::connect_url_host( 'https://app.tackquote.com/login?request=' . $cx_request_id, $cx_request_id ) );
check( 'connectUrl: userinfo is refused', '' === Tack_Connect::connect_url_host( 'https://u:p@app.tackquote.com/auth/connect/woocommerce?request=' . $cx_request_id, $cx_request_id ) );
tack_test_set_option( 'tack_quotes_api_url', 'https://api.staging.tackquote.test/v1' );
check( 'connectUrl: a custom API URL may answer with its own https app host', 'app.staging.tackquote.test' === Tack_Connect::connect_url_host( 'https://app.staging.tackquote.test/auth/connect/woocommerce?request=' . $cx_request_id, $cx_request_id ) );
tack_test_set_option( 'tack_quotes_api_url', Tack_Settings::DEFAULT_API_URL );

tack_cx_reset();
tack_cx_routes( array( '/woocommerce-connect/requests' => array( 201, array( 'requestId' => $cx_request_id, 'connectUrl' => 'https://evil.example/x' ) ) ) );
$cx_out = tack_cx_start( new Tack_Test_Connect() );
check( 'start: an unacceptable connectUrl is not opened and no transient is kept', ! isset( $cx_out['redirect'] ) && false !== strpos( $cx_out['message'], 'will not open' ) && array() === array_filter( array_keys( $GLOBALS['TACK_TRANSIENTS'] ), function ( $k ) { return 0 === strpos( $k, Tack_Connect::PENDING_PREFIX ) && false === strpos( $k, 'notice' ); } ) );

foreach ( array(
	'404 (old server)' => array( array( 404, array( 'statusCode' => 404, 'message' => 'Cannot POST /v1/woocommerce-connect/requests' ) ), 'does not support Connect' ),
	'400 site refused' => array( array( 400, array( 'statusCode' => 400, 'code' => 'BADREQUEST', 'message' => 'The return address is not on the same host as the site.' ) ), 'refused to start connecting this site, so nothing was changed. The return address is not on the same host' ),
	'transport'        => array( 'TRANSPORT', 'Could not reach TackQuote' ),
	'503'              => array( array( 503, array( 'statusCode' => 503, 'message' => 'down' ) ), 'busy or unavailable' ),
) as $cx_label => $cx_case ) {
	tack_cx_reset();
	tack_cx_routes( array( '/woocommerce-connect/requests' => $cx_case[0] ) );
	$cx_out = tack_cx_start( new Tack_Test_Connect() );
	check( "start $cx_label: the right notice", 'error' === $cx_out['type'] && false !== strpos( $cx_out['message'], $cx_case[1] ), $cx_out['message'] );
	check( "start $cx_label: the saved key is untouched", $cx_old_key === get_option( 'tack_quotes_api_key' ) );
}

// ── Return ─────────────────────────────────────────────────────────────────────
tack_cx_reset();
$cx_s = tack_cx_started();
tack_cx_routes( array( '/woocommerce-connect/exchange' => $cx_exchanged, '/ping' => $cx_ping_ok ) );
$cx_out = ( new Tack_Test_Connect() )->finish( array( 'code' => $cx_code, 'state' => $cx_s['state'] ) );
$cx_ex  = tack_cx_calls( '/woocommerce-connect/exchange' );
$cx_eb  = isset( $cx_ex[0] ) ? json_decode( $cx_ex[0]['args']['body'], true ) : array();
check( 'exchange: one call with code, the stored verifier and the site', 1 === count( $cx_ex ) && array( 'code' => $cx_code, 'codeVerifier' => $cx_s['pending']['verifier'], 'siteUrl' => 'https://shop.example' ) === $cx_eb );
check( 'exchange: sent without an API key', ! isset( $cx_ex[0]['args']['headers']['Authorization'] ) && ! isset( $cx_ex[0]['args']['headers']['X-Api-Key'] ) );
check( 'exchange success: the key is stored in the normal option', $cx_new_key === get_option( 'tack_quotes_api_key' ) );
check( 'exchange success: connected via connect, with a time', Tack_Connect::VIA_CONNECT === get_option( Tack_Connect::OPTION_VIA ) && (int) get_option( Tack_Connect::OPTION_AT ) > 0 );
check( 'exchange success: the existing ping ran with the new key', 1 === count( tack_cx_calls( '/ping' ) ) && 'Bearer ' . $cx_new_key === tack_cx_calls( '/ping' )[0]['args']['headers']['Authorization'] );
check( 'exchange success: the Overview test record is ok for the new key', 'ok' === Tack_Settings::connection_status()['state'] );
check( 'exchange success: the notice says connected and names the workspace', 'success' === $cx_out['type'] && false !== strpos( $cx_out['message'], 'Connected to TackQuote (workspace: Northwind). The connection test passed.' ) );
check( 'exchange success: the notice never shows the key', false === strpos( $cx_out['message'], $cx_new_key ) );
check( 'exchange success: the pending transient is gone', false === $cx_s['pending'] || false === get_transient( Tack_Connect::pending_key( Tack_Connect::verify_state( $cx_s['state'] )['n'] ) ) );

// Replay: the same return again.
tack_cx_routes( array( '/woocommerce-connect/exchange' => $cx_exchanged, '/ping' => $cx_ping_ok ) );
$cx_out = ( new Tack_Test_Connect() )->finish( array( 'code' => $cx_code, 'state' => $cx_s['state'] ) );
check( 'replay: refused as expired or already used', 'error' === $cx_out['type'] && false !== strpos( $cx_out['message'], 'expired or was already used' ) );
check( 'replay: no second exchange', 0 === count( tack_cx_calls( '/woocommerce-connect/exchange' ) ) );

/**
 * Run a return and assert it changed nothing and called nothing.
 *
 * @param string $label  Label.
 * @param array  $query  Query.
 * @param string $expect Message fragment.
 * @param string $type   Notice type.
 */
function tack_cx_refused( $label, $query, $expect, $type = 'error' ) {
	global $cx_exchanged, $cx_old_key;
	tack_cx_routes( array( '/woocommerce-connect/exchange' => $cx_exchanged ) );
	$out = ( new Tack_Test_Connect() )->finish( $query );
	check( "$label: notice", $type === $out['type'] && false !== strpos( $out['message'], $expect ), $out['message'] );
	check( "$label: no exchange, key untouched", 0 === count( tack_cx_calls( '/woocommerce-connect/exchange' ) ) && $cx_old_key === get_option( 'tack_quotes_api_key' ) && Tack_Connect::VIA_CONNECT !== get_option( Tack_Connect::OPTION_VIA ) );
	return $out;
}

tack_cx_reset();
$cx_s = tack_cx_started();
list( $cx_b, $cx_m ) = explode( '.', $cx_s['state'] );
tack_cx_refused( 'tampered state', array( 'code' => $cx_code, 'state' => $cx_b . '.' . strrev( $cx_m ) ), 'could not be verified' );
tack_cx_refused( 'no state', array( 'code' => $cx_code ), 'could not be verified' );
$cx_pl = Tack_Connect::verify_state( $cx_s['state'] );
tack_cx_refused( 'state of another administrator', array( 'code' => $cx_code, 'state' => Tack_Connect::sign_state( array_merge( $cx_pl, array( 'u' => 2 ) ) ) ), 'another administrator' );
tack_cx_refused( 'state for another site address', array( 'code' => $cx_code, 'state' => Tack_Connect::sign_state( array_merge( $cx_pl, array( 's' => 'https://other.example' ) ) ) ), 'different site address' );
$GLOBALS['TACK_CAPS'] = array( 'manage_woocommerce' );
$cx_died              = false;
try {
	( new Tack_Test_Connect() )->finish( array( 'code' => $cx_code, 'state' => $cx_s['state'] ) );
} catch ( Tack_Test_Wp_Die $e ) {
	$cx_died = true;
}
check( 'return: refused without manage_options', $cx_died );
$GLOBALS['TACK_CAPS'] = array( 'manage_options', 'manage_woocommerce' );
// The genuine state still works after all those refusals (none consumed it).
check( 'return: refusals before the state checks pass did not consume the pending connect', is_array( get_transient( Tack_Connect::pending_key( $cx_pl['n'] ) ) ) );

// Expired: a state older than 15 minutes.
tack_cx_reset();
$cx_s  = tack_cx_started();
$cx_pl = Tack_Connect::verify_state( $cx_s['state'] );
$cx_old_state = Tack_Connect::sign_state( array_merge( $cx_pl, array( 'iat' => time() - Tack_Connect::TTL - 5 ) ) );
$cx_pend      = $cx_s['pending'];
$cx_pend['state'] = hash( 'sha256', $cx_old_state );
set_transient( Tack_Connect::pending_key( $cx_pl['n'] ), $cx_pend, Tack_Connect::TTL );
tack_cx_refused( 'expired state', array( 'code' => $cx_code, 'state' => $cx_old_state ), 'expired or was already used' );

// A validly signed state whose transient was never written (or has lapsed).
tack_cx_reset();
tack_cx_refused( 'signed state, no pending connect', array( 'code' => $cx_code, 'state' => Tack_Connect::sign_state( array( 'v' => 1, 'n' => 'never', 'u' => 1, 's' => 'https://shop.example', 'iat' => time() ) ) ), 'expired or was already used' );

// Cancelled on TackQuote.
tack_cx_reset();
$cx_s = tack_cx_started();
tack_cx_refused( 'access_denied', array( 'error' => 'access_denied', 'state' => $cx_s['state'] ), 'TackQuote was not connected. Nothing changed.', 'warning' );
check( 'access_denied: the pending connect is consumed', false === get_transient( Tack_Connect::pending_key( Tack_Connect::verify_state( $cx_s['state'] )['n'] ) ) );

tack_cx_reset();
$cx_s = tack_cx_started();
tack_cx_refused( 'malformed code', array( 'code' => 'short', 'state' => $cx_s['state'] ), 'no usable code' );

// The exchange is refused or fails.
foreach ( array(
	'400 invalid_grant' => array( array( 400, array( 'statusCode' => 400, 'code' => 'BADREQUEST', 'message' => 'This connect code is invalid, expired or already used. Press Connect with TackQuote again.' ) ), 'refused the sign-in code' ),
	'403 plan limit'    => array( array( 403, array( 'statusCode' => 403, 'code' => 'PLAN_LIMIT_EXCEEDED', 'error' => 'PLAN_LIMIT_EXCEEDED', 'message' => "Your TackQuote plan's integration limit is reached." ) ), "plan does not allow it. Nothing was changed on this site. Your TackQuote plan's integration limit is reached." ),
	'404 old server'    => array( array( 404, array( 'statusCode' => 404, 'message' => 'Cannot POST' ) ), 'does not support Connect' ),
	'409 conflict'      => array( array( 409, array( 'statusCode' => 409, 'message' => 'This TackQuote workspace has several WooCommerce connections.' ) ), 'several WooCommerce connections' ),
	'429'               => array( array( 429, array( 'statusCode' => 429, 'message' => 'Too Many Requests' ) ), 'busy or unavailable' ),
	'transport'         => array( 'TRANSPORT', 'Could not reach TackQuote' ),
	'no apiKey'         => array( array( 200, array( 'scopes' => array() ) ), 'without a usable API key' ),
	'malformed apiKey'  => array( array( 200, array( 'apiKey' => '<script>x</script>' ) ), 'without a usable API key' ),
) as $cx_label => $cx_case ) {
	tack_cx_reset();
	$cx_s = tack_cx_started();
	tack_cx_routes( array( '/woocommerce-connect/exchange' => $cx_case[0], '/ping' => $cx_ping_ok ) );
	$cx_out = ( new Tack_Test_Connect() )->finish( array( 'code' => $cx_code, 'state' => $cx_s['state'] ) );
	check( "exchange $cx_label: the right error notice", 'error' === $cx_out['type'] && false !== strpos( $cx_out['message'], $cx_case[1] ), $cx_out['message'] );
	check( "exchange $cx_label: the old key is untouched, no ping, not marked connected", $cx_old_key === get_option( 'tack_quotes_api_key' ) && 0 === count( tack_cx_calls( '/ping' ) ) && Tack_Connect::VIA_CONNECT !== get_option( Tack_Connect::OPTION_VIA ) );
	check( "exchange $cx_label: the notice never shows the verifier", false === strpos( $cx_out['message'], $cx_s['pending']['verifier'] ) );
}

// The key arrives but the ping then fails: saved, and said so, never "connected".
tack_cx_reset();
$cx_s = tack_cx_started();
tack_cx_routes( array( '/woocommerce-connect/exchange' => $cx_exchanged, '/ping' => array( 401, array( 'statusCode' => 401, 'message' => 'Invalid API key' ) ) ) );
$cx_out = ( new Tack_Test_Connect() )->finish( array( 'code' => $cx_code, 'state' => $cx_s['state'] ) );
check( 'ping fails after exchange: a warning, not a success', 'warning' === $cx_out['type'] && false === strpos( $cx_out['message'], 'connection test passed' ) && false !== strpos( $cx_out['message'], 'did not pass' ) );
check( 'ping fails after exchange: the Overview says rejected', 'rejected' === Tack_Settings::connection_status()['state'] );

// handle_return reads only code/state/error and redirects to the clean Connection tab.
tack_cx_reset();
$cx_s = tack_cx_started();
tack_cx_routes( array( '/woocommerce-connect/exchange' => $cx_exchanged, '/ping' => $cx_ping_ok ) );
$_GET  = array( 'action' => Tack_Connect::RETURN_ACTION, 'code' => $cx_code, 'state' => $cx_s['state'], 'tenantId' => 'evil' );
$cx_t  = new Tack_Test_Connect();
@$cx_t->handle_return(); // phpcs:ignore -- header() after test output.
check( 'handle_return: exchanged and redirected to the Connection tab without the code', $cx_new_key === get_option( 'tack_quotes_api_key' ) && array( Tack_Settings::tab_url( 'connection' ), '' ) === end( $cx_t->redirects ) && false === strpos( end( $cx_t->redirects )[0], 'code=' ) );
check( 'handle_return: the notice is shown on the next settings page view', 'success' === ( Tack_Connect::take_notice()['type'] ?? '' ) );
$_GET = array();

// ── Hooks ──────────────────────────────────────────────────────────────────────
$cx_before = count( (array) ( $GLOBALS['TACK_HOOKS'] ?? array() ) );
( new Tack_Connect() )->init();
$cx_hooks = array_column( array_slice( (array) $GLOBALS['TACK_HOOKS'], $cx_before ), 'hook' );
check( 'hooks: logged-in admin-post actions only (no nopriv)', in_array( 'admin_post_tackquote_connect_start', $cx_hooks, true ) && in_array( 'admin_post_tackquote_connect_return', $cx_hooks, true ) && array() === array_filter( $cx_hooks, function ( $h ) { return false !== strpos( $h, 'nopriv' ); } ) );
check( 'hooks: a key saved through the form is marked pasted', in_array( 'update_option_tack_quotes_api_key', $cx_hooks, true ) && in_array( 'add_option_tack_quotes_api_key', $cx_hooks, true ) );
tack_test_set_option( Tack_Connect::OPTION_VIA, Tack_Connect::VIA_CONNECT );
Tack_Connect::mark_pasted();
check( 'hooks: mark_pasted records an API key', Tack_Connect::VIA_KEY === get_option( Tack_Connect::OPTION_VIA ) );

// ── Remove saved API key: revoke only a Connect key ───────────────────────────
/**
 * Press "Remove saved API key" on the Connection tab.
 *
 * @return array The action result.
 */
function tack_cx_remove() {
	$_POST       = array(
		'tack_quotes_action'           => 'remove_api_key',
		'tack_quotes_remove_key_nonce' => wp_create_nonce( 'tack_quotes_remove_key' ),
	);
	$_GET['tab'] = 'connection';
	$settings    = new Tack_Settings();
	ob_start();
	$settings->render_page();
	ob_end_clean();
	$prop = new ReflectionProperty( 'Tack_Settings', 'action_result' );
	$prop->setAccessible( true );
	$_POST = array();
	unset( $_GET['tab'] );
	return $prop->getValue( $settings );
}

tack_cx_reset();
tack_test_set_option( 'tack_quotes_api_key', $cx_new_key );
tack_test_set_option( Tack_Connect::OPTION_VIA, Tack_Connect::VIA_CONNECT );
tack_cx_routes( array( '/plugin-key/revoke' => array( 204, '' ) ) );
$cx_res = tack_cx_remove();
$cx_rv  = tack_cx_calls( '/integrations/woocommerce/plugin-key/revoke' );
check( 'remove (Connect key): plugin-key/revoke is called once, with that key, by POST', 1 === count( $cx_rv ) && 'POST' === $cx_rv[0]['args']['method'] && 'Bearer ' . $cx_new_key === $cx_rv[0]['args']['headers']['Authorization'] );
check( 'remove (Connect key): the key and the connect markers are deleted', '' === (string) get_option( 'tack_quotes_api_key', '' ) && false === get_option( Tack_Connect::OPTION_VIA ) );
check( 'remove (Connect key): the notice says removed and revoked', 'success' === $cx_res['type'] && false !== strpos( $cx_res['message'], 'revoked in TackQuote' ) );

tack_cx_reset();
tack_test_set_option( 'tack_quotes_api_key', $cx_new_key );
tack_test_set_option( Tack_Connect::OPTION_VIA, Tack_Connect::VIA_CONNECT );
tack_cx_routes( array( '/plugin-key/revoke' => 'TRANSPORT' ) );
$cx_res = tack_cx_remove();
check( 'remove (Connect key, TackQuote unreachable): the key is still deleted here', '' === (string) get_option( 'tack_quotes_api_key', '' ) );
check( 'remove (Connect key, TackQuote unreachable): a warning says to revoke it in TackQuote', 'warning' === $cx_res['type'] && false !== strpos( $cx_res['message'], 'could not be reached to revoke it' ) );

foreach ( array( 'pasted (via=key)' => Tack_Connect::VIA_KEY, 'pasted before 1.11 (no marker)' => null ) as $cx_label => $cx_via ) {
	tack_cx_reset();
	if ( null !== $cx_via ) {
		tack_test_set_option( Tack_Connect::OPTION_VIA, $cx_via );
	}
	tack_cx_routes( array( '/plugin-key/revoke' => array( 204, '' ) ) );
	$cx_res = tack_cx_remove();
	check( "remove ($cx_label): no revoke call", 0 === count( tack_cx_calls( '/plugin-key/revoke' ) ) && 0 === count( $GLOBALS['TACK_HTTP_REQUESTS'] ) );
	check( "remove ($cx_label): unchanged behaviour and message", '' === (string) get_option( 'tack_quotes_api_key', '' ) && 'success' === $cx_res['type'] && 'The saved TackQuote API key has been removed.' === $cx_res['message'] );
}

// ── The Connection tab and the Overview ────────────────────────────────────────
/**
 * Render a tab.
 *
 * @param string $tab Tab.
 * @return string
 */
function tack_cx_render( $tab ) {
	$_GET['tab'] = $tab;
	ob_start();
	( new Tack_Settings() )->render_page();
	$html = (string) ob_get_clean();
	unset( $_GET['tab'] );
	return $html;
}

tack_cx_reset();
tack_test_set_option( 'tack_quotes_api_key', '' );
$cx_html = tack_cx_render( 'connection' );
check( 'tab: the Connect card posts to admin-post.php with the start action and nonce', false !== strpos( $cx_html, 'action="https://shop.example/wp-admin/admin-post.php"' ) && false !== strpos( $cx_html, 'name="action" value="tackquote_connect_start"' ) && false !== strpos( $cx_html, 'name="' . Tack_Connect::NONCE_FIELD . '"' ) );
check( 'tab: Connect comes first, the paste-a-key field is still there', false !== strpos( $cx_html, 'id="tack_quotes_api_key"' ) && strpos( $cx_html, 'tackquote_connect_start' ) < strpos( $cx_html, 'id="tack_quotes_api_key"' ) );
check( 'tab: the button reads Connect with TackQuote', false !== strpos( $cx_html, 'Connect with TackQuote' ) );

tack_cx_reset();
tack_test_set_option( Tack_Connect::OPTION_VIA, Tack_Connect::VIA_CONNECT );
$cx_html = tack_cx_render( 'connection' );
check( 'tab: a Connect key shows "Connected via Connect with TackQuote" and Connect again', false !== strpos( $cx_html, 'Connected via Connect with TackQuote' ) && false !== strpos( $cx_html, 'Connect again' ) );
$cx_html = tack_cx_render( 'overview' );
check( 'overview: Connected via Connect with TackQuote', false !== strpos( $cx_html, '<dt>Connected via</dt><dd>Connect with TackQuote</dd>' ) );
tack_test_set_option( Tack_Connect::OPTION_VIA, Tack_Connect::VIA_KEY );
check( 'overview: Connected via API key', false !== strpos( tack_cx_render( 'overview' ), '<dt>Connected via</dt><dd>API key</dd>' ) );
check( 'tab: a pasted key shows "Connected via API key"', false !== strpos( tack_cx_render( 'connection' ), 'Connected via API key' ) );

// The notice of a finished connect is printed once.
Tack_Connect::take_notice();
set_transient( Tack_Connect::NOTICE_PREFIX . get_current_user_id(), array( 'type' => 'warning', 'message' => 'TackQuote was not connected. Nothing changed.' ), 300 );
$cx_html = tack_cx_render( 'connection' );
check( 'notice: shown once on the settings page', false !== strpos( $cx_html, 'notice-warning' ) && false !== strpos( $cx_html, 'TackQuote was not connected. Nothing changed.' ) && false === strpos( tack_cx_render( 'connection' ), 'Nothing changed.' ) );

// ── Shipped code ───────────────────────────────────────────────────────────────
$cx_src = (string) file_get_contents( TACK_QUOTES_DIR . 'includes/class-tack-connect.php' );
check( 'source: the state is compared in constant time', false !== strpos( $cx_src, 'hash_equals( $expected, $mac )' ) );
check( 'source: no admin_post_nopriv handler', false === strpos( $cx_src, 'admin_post_nopriv' ) );
check( 'source: the key is never logged', false === strpos( $cx_src, 'wc_get_logger' ) && false === strpos( $cx_src, 'error_log' ) );

tack_cx_reset();
unset( $GLOBALS['TACK_HOME_URL'], $GLOBALS['TACK_HTTP_RESPONDER'] );
$GLOBALS['TACK_LOGGED_IN'] = false;
