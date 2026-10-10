<?php
/**
 * Connection test: only an authenticated ping is "Connected" (1.10.0, defect D1).
 *
 * `GET /health` is public and answers 200 without reading the key. The plugin used to
 * fall back to it on ANY ping failure, a 401 "Invalid API key" included, so a rejected
 * key showed "Connected to TackQuote successfully." and "Connected" on the Overview for
 * a day, and the same branch cached the server's capabilities as empty for a day.
 *
 * Included from tests/run.php after attachments-test.php (it reuses its HTTP router).
 *
 * @package TackQuotes
 */

$conn_key = 'tq_live_conn_test_key_5678';
tack_test_set_option( 'tack_quotes_api_key', $conn_key );
tack_test_set_option( 'tack_quotes_api_url', 'https://api.tackquote.test/v1' );

$GLOBALS['TACK_CAPS'] = array( 'manage_options', 'manage_woocommerce' );
$conn_settings        = new Tack_Settings();
$conn_run      = new ReflectionMethod( 'Tack_Settings', 'run_connection_test' );
$conn_run->setAccessible( true );

/**
 * Run "Test connection" as the settings page does, then render the Overview.
 *
 * @param Tack_Settings    $settings Settings page.
 * @param ReflectionMethod $run      run_connection_test.
 * @return array{notice:array,status:array,overview:string}
 */
function tack_conn_run( $settings, $run ) {
	unset( $GLOBALS['TACK_TRANSIENTS'][ Tack_Settings::CONNECTION_CHECK ] );
	$run->invoke( $settings );
	$prop = new ReflectionProperty( 'Tack_Settings', 'action_result' );
	$prop->setAccessible( true );
	$notice = $prop->getValue( $settings );
	$prop->setValue( $settings, null );

	$_GET['tab'] = 'overview';
	ob_start();
	$settings->render_page();
	$html = (string) ob_get_clean();
	unset( $_GET['tab'] );

	return array(
		'notice'   => $notice,
		'status'   => Tack_Settings::connection_status(),
		'overview' => $html,
	);
}

$conn_401 = array( 401, array( 'statusCode' => 401, 'message' => 'Invalid API key', 'error' => 'Unauthorized' ) );
$conn_403 = array( 403, array( 'statusCode' => 403, 'message' => 'Forbidden', 'code' => 'API_KEY_FORBIDDEN' ) );
$conn_ok  = array( 200, array( 'ok' => true, 'tenantId' => 't-1', 'capabilities' => array( 'attachments' ) ) );
$conn_hp  = array( 200, array( 'status' => 'ok' ) );

// ── 401: the key is refused. /health answers 200 and must NOT be asked. ──────────
tack_test_reset_transients();
// A capability list cached under an earlier passing test must not survive the refusal.
$GLOBALS['TACK_TRANSIENTS'][ Tack_Api_Client::CAPABILITIES_TRANSIENT ] = array(
	'key'   => substr( hash( 'sha256', $conn_key ), 0, 16 ),
	'caps'  => array( 'attachments' ),
	'until' => time() + DAY_IN_SECONDS,
);
tack_test_attach_routes( array( '/ping' => $conn_401, '/health' => $conn_hp ) );
$r = ( new Tack_Api_Client() )->test_connection();
check( '401: test_connection() is an error, never true', is_wp_error( $r ) );
check( '401: state is rejected', is_wp_error( $r ) && Tack_Api_Client::STATE_REJECTED === $r->get_error_data()['state'] );
check( '401: the public /health is never asked (it cannot check a key)', 0 === count( tack_test_attach_calls( '/health' ) ) );
check( '401: the message names the rejected key and the HTTP status', is_wp_error( $r ) && false !== strpos( $r->get_error_message(), 'rejected this API key' ) && false !== strpos( $r->get_error_message(), 'HTTP 401' ) );
check( '401: the message never contains the key', is_wp_error( $r ) && false === strpos( $r->get_error_message(), $conn_key ) );
check( '401: cached capabilities are cleared, none are written', false === get_transient( Tack_Api_Client::CAPABILITIES_TRANSIENT ) );

tack_test_attach_routes( array( '/ping' => $conn_401, '/health' => $conn_hp ) );
$out = tack_conn_run( $conn_settings, $conn_run );
check( '401: the settings notice is an error, not "Connected"', 'error' === $out['notice']['type'] && false === strpos( $out['notice']['message'], 'Connected' ) );
check( '401: the Overview state is rejected', 'rejected' === $out['status']['state'] );
check( '401: the Overview pill says Key rejected (red)', false !== strpos( $out['overview'], 'tack-pill--error">Key rejected<' ) );
check( '401: the Overview never says Connected', false === strpos( $out['overview'], '>Connected<' ) );
check( '401: the connection test wrote no capability cache', false === get_transient( Tack_Api_Client::CAPABILITIES_TRANSIENT ) );

// The storefront check after a refusal: nothing advertised, no capability list cached.
tack_test_attach_routes( array( '/ping' => $conn_401 ) );
$conn_client = new Tack_Api_Client();
check( '401: server_capabilities() answers nothing', array() === $conn_client->server_capabilities() );
$conn_cached = get_transient( Tack_Api_Client::CAPABILITIES_TRANSIENT );
check( '401: the storefront cache holds a refusal marker, no capability list', is_array( $conn_cached ) && ! isset( $conn_cached['caps'] ) && Tack_Api_Client::STATE_REJECTED === $conn_cached['failed'] );
check( '401: attachments are never derived from the refused ping', false === $conn_client->supports_attachments() );
check( '401: the refusal is remembered (one ping, not one per page view)', 1 === count( tack_test_attach_calls( '/ping' ) ) );

// ── 403: a key without permission ───────────────────────────────────────────────
tack_test_reset_transients();
tack_test_attach_routes( array( '/ping' => $conn_403, '/health' => $conn_hp ) );
$r = ( new Tack_Api_Client() )->test_connection();
check( '403: test_connection() is an error', is_wp_error( $r ) && Tack_Api_Client::STATE_REJECTED === $r->get_error_data()['state'] );
check( '403: the message says the key lacks permission and shows the server code', is_wp_error( $r ) && false !== strpos( $r->get_error_message(), 'lacks permission' ) && false !== strpos( $r->get_error_message(), 'code api_key_forbidden' ) );
check( '403: /health is never asked', 0 === count( tack_test_attach_calls( '/health' ) ) );
check( '403: no capability cache', false === get_transient( Tack_Api_Client::CAPABILITIES_TRANSIENT ) );
$out = tack_conn_run( $conn_settings, $conn_run );
check( '403: the Overview says Key rejected', 'rejected' === $out['status']['state'] && false !== strpos( $out['overview'], '>Key rejected<' ) );

// ── 404 ping + 200 health: reachable, key NOT verified ─────────────────────────
tack_test_reset_transients();
tack_test_attach_routes( array( '/health' => $conn_hp ) ); // Every other path answers 404.
$r = ( new Tack_Api_Client() )->test_connection();
check( '404 ping + 200 health: NOT true (the key was never checked)', true !== $r && is_wp_error( $r ) );
check( '404 ping + 200 health: state is unverified', is_wp_error( $r ) && Tack_Api_Client::STATE_UNVERIFIED === $r->get_error_data()['state'] );
check( '404 ping + 200 health: /health asked once', 1 === count( tack_test_attach_calls( '/health' ) ) );
check( '404 ping + 200 health: the message says reachable, key not verified', is_wp_error( $r ) && false !== strpos( $r->get_error_message(), 'Server reachable, key not verified' ) );
check( '404 ping + 200 health: no capability list cached', false === get_transient( Tack_Api_Client::CAPABILITIES_TRANSIENT ) );
$out = tack_conn_run( $conn_settings, $conn_run );
check( '404 fallback: the notice is a warning, not success', 'warning' === $out['notice']['type'] );
check( '404 fallback: the Overview state is unverified, not ok', 'unverified' === $out['status']['state'] );
check( '404 fallback: the Overview pill is amber "Reachable, key not verified"', false !== strpos( $out['overview'], 'tack-pill--warn">Reachable, key not verified<' ) );
check( '404 fallback: the Overview never says Connected', false === strpos( $out['overview'], '>Connected<' ) );

// 404 ping + 404 health: nothing answered.
tack_test_reset_transients();
tack_test_attach_routes( array() );
$r = ( new Tack_Api_Client() )->test_connection();
check( '404 ping + 404 health: failed', is_wp_error( $r ) && Tack_Api_Client::STATE_FAILED === $r->get_error_data()['state'] );

// ── 200 ping: Connected, capabilities cached ───────────────────────────────────
tack_test_reset_transients();
tack_test_attach_routes( array( '/ping' => $conn_ok, '/health' => $conn_hp ) );
$r = ( new Tack_Api_Client() )->test_connection();
check( '200 ping: test_connection() is true', true === $r );
check( '200 ping: /health is not asked', 0 === count( tack_test_attach_calls( '/health' ) ) );
$conn_cached = get_transient( Tack_Api_Client::CAPABILITIES_TRANSIENT );
check( '200 ping: capabilities cached', is_array( $conn_cached ) && array( 'attachments' ) === $conn_cached['caps'] );
$out = tack_conn_run( $conn_settings, $conn_run );
check( '200 ping: the notice is success', 'success' === $out['notice']['type'] && false !== strpos( $out['notice']['message'], 'Connected to TackQuote' ) );
check( '200 ping: the Overview says Connected', 'ok' === $out['status']['state'] && false !== strpos( $out['overview'], 'tack-pill--ok">Connected<' ) );

// ── Transport error / 5xx / 429: failed, retryable, cache untouched ────────────
$GLOBALS['TACK_HTTP_REQUESTS']  = array();
$GLOBALS['TACK_HTTP_RESPONDER'] = function () {
	return new WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host' );
};
$r = ( new Tack_Api_Client() )->test_connection();
check( 'transport error: failed', is_wp_error( $r ) && Tack_Api_Client::STATE_FAILED === $r->get_error_data()['state'] );
check( 'transport error: /health is not asked', 0 === count( tack_test_attach_calls( '/health' ) ) );
check( 'transport error: the message says try again', is_wp_error( $r ) && false !== strpos( $r->get_error_message(), 'try again' ) );
$conn_cached = get_transient( Tack_Api_Client::CAPABILITIES_TRANSIENT );
check( 'transport error: an earlier passing answer is left as it was', is_array( $conn_cached ) && array( 'attachments' ) === $conn_cached['caps'] );
$out = tack_conn_run( $conn_settings, $conn_run );
check( 'transport error: the Overview says Connection failed', 'failed' === $out['status']['state'] && false !== strpos( $out['overview'], 'tack-pill--error">Connection failed<' ) && false === strpos( $out['overview'], '>Connected<' ) );

foreach ( array( 429, 500, 503 ) as $conn_code ) {
	tack_test_attach_routes( array( '/ping' => array( $conn_code, array( 'statusCode' => $conn_code, 'message' => 'x' ) ), '/health' => $conn_hp ) );
	$r = ( new Tack_Api_Client() )->test_connection();
	check( "HTTP $conn_code: failed, retryable, /health not asked", is_wp_error( $r ) && Tack_Api_Client::STATE_FAILED === $r->get_error_data()['state'] && false !== strpos( $r->get_error_message(), 'Try again' ) && 0 === count( tack_test_attach_calls( '/health' ) ) );
}

// An Overview check stored by an older 1.10.0 build (no `state`) still reads correctly.
$GLOBALS['TACK_TRANSIENTS'][ Tack_Settings::CONNECTION_CHECK ] = array(
	'ok'      => false,
	'key'     => substr( hash( 'sha256', $conn_key ), 0, 16 ),
	'at'      => time(),
	'message' => 'x',
);
check( 'a stored check without state reads as failed', 'failed' === Tack_Settings::connection_status()['state'] );

// Leave shared fixtures as later files expect them.
unset( $GLOBALS['TACK_TRANSIENTS'][ Tack_Settings::CONNECTION_CHECK ], $GLOBALS['TACK_HTTP_RESPONDER'] );
tack_test_reset_transients();
$GLOBALS['TACK_CAPS'] = array();
delete_option( 'tack_quotes_api_key' );
delete_option( 'tack_quotes_api_url' );
