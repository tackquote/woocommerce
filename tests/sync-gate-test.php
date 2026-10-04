<?php
/**
 * Order sync must stop pushing when TackQuote's refusal is TERMINAL.
 *
 * WHY THIS EXISTS. A store whose API key lacked the `orders:write` scope sent
 * `POST /integrations/woocommerce/order-sync` about every 3 seconds — 444 refusals
 * (HTTP 403) in 50 minutes — and the merchant was never told. Nothing in the plugin
 * loops on its own: Action Scheduler does not re-run a callback that returns, and a
 * failed push only logged to WooCommerce → Status → Logs. The traffic was every order
 * trigger (creation, every status change, every admin save) pushing again with a key
 * that could never succeed, because a refusal was treated exactly like a timeout.
 *
 * The rule tested here, which mirrors how the TackQuote API classifies vendor
 * responses (temporary vs terminal):
 *
 *   TERMINAL   401 or 403 carrying TackQuote's own JSON error body. No retry can
 *              change it; the merchant must act. Stop pushing, say why in wp-admin,
 *              re-probe at most once an hour (a lapsed subscription can be renewed
 *              without touching the key), and resume at once when the key changes.
 *   THROTTLED  429. Wait for Retry-After (bounded), then try again.
 *   TEMPORARY  Everything else — timeouts, 5xx, and a 401/403 whose body is NOT
 *              JSON. That last one is a WAF or Cloudflare challenge page in front
 *              of the API, which goes away on its own; treating it as terminal
 *              would switch sync off for a five-minute blip.
 *
 * Loaded by tests/run.php; `check()` and $failures come from there.
 *
 * @package TackQuotes
 */

require_once TACK_QUOTES_DIR . 'includes/class-tack-api-client.php';
require_once TACK_QUOTES_DIR . 'includes/class-tack-sync-gate.php';

$now = 1790000000;
$key = 'tk_live_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
tack_test_set_option( 'tack_quotes_api_key', $key );

/**
 * Run one push through the real client against a scripted response.
 *
 * @param int    $code    Status.
 * @param string $body    Raw body.
 * @param array  $headers Headers.
 * @return mixed WP_Error or array.
 */
function tack_gate_push( $code, $body, $headers = array() ) {
	tack_test_set_http_response( $code, $body, $headers );
	return ( new Tack_Api_Client() )->sync_order( array( 'externalOrderId' => '1' ), 'k1' );
}

$scope_body = wp_json_encode(
	array(
		'statusCode'     => 403,
		'code'           => 'insufficient_scope',
		'message'        => 'This API key is missing the required scope(s): orders:write. Create a new key with those scopes in Settings -> API keys.',
		'requiredScopes' => array( 'orders:write' ),
	)
);

// ── The client keeps what the API said, not just a sentence ─────────────────
$err  = tack_gate_push( 403, $scope_body );
$data = is_wp_error( $err ) ? $err->get_error_data() : null;
check( 'a 403 comes back as a WP_Error', is_wp_error( $err ) );
check(
	'the WP_Error carries the HTTP status, the API code and the scopes it named',
	is_array( $data ) && 403 === ( $data['status'] ?? null )
		&& 'insufficient_scope' === ( $data['code'] ?? null )
		&& array( 'orders:write' ) === ( $data['requiredScopes'] ?? null )
		&& true === ( $data['json'] ?? null ),
	'data: ' . var_export( $data, true )
);

// ── Classification ──────────────────────────────────────────────────────────
$block = Tack_Sync_Gate::classify( $err, $now );
check( 'a JSON 403 for a missing scope is TERMINAL', is_array( $block ) && 'terminal' === $block['kind'] );
check(
	'the terminal block names the missing scope',
	is_array( $block ) && array( 'orders:write' ) === $block['scopes']
);

$sub = tack_gate_push( 403, wp_json_encode( array( 'statusCode' => 403, 'code' => 'SUBSCRIPTION_INACTIVE', 'error' => 'SUBSCRIPTION_INACTIVE', 'message' => 'Your subscription is inactive.' ) ) );
$b   = Tack_Sync_Gate::classify( $sub, $now );
check( 'a JSON 403 for a lapsed subscription is TERMINAL', is_array( $b ) && 'terminal' === $b['kind'] && 'SUBSCRIPTION_INACTIVE' === $b['code'] );

$bad = tack_gate_push( 401, wp_json_encode( array( 'statusCode' => 401, 'code' => 'UNAUTHORIZED', 'message' => 'Invalid API key' ) ) );
$b   = Tack_Sync_Gate::classify( $bad, $now );
check( 'a JSON 401 (invalid or revoked key) is TERMINAL', is_array( $b ) && 'terminal' === $b['kind'] );

$waf = tack_gate_push( 403, '<!DOCTYPE html><title>Just a moment...</title>' );
check(
	'a 403 whose body is NOT JSON (a WAF / challenge page) is TEMPORARY, not terminal',
	null === Tack_Sync_Gate::classify( $waf, $now )
);

$down = tack_gate_push( 502, '' );
check( 'a 5xx is TEMPORARY', null === Tack_Sync_Gate::classify( $down, $now ) );

check(
	'a transport error (no HTTP status) is TEMPORARY',
	null === Tack_Sync_Gate::classify( new WP_Error( 'http_request_failed', 'cURL error 28' ), $now )
);

$slow = tack_gate_push( 429, wp_json_encode( array( 'statusCode' => 429, 'code' => 'insufficient_scope', 'message' => 'slow down', 'retryAfterSeconds' => 120 ) ), array( 'retry-after' => '120' ) );
$b    = Tack_Sync_Gate::classify( $slow, $now );
check(
	'a 429 is THROTTLED until Retry-After',
	is_array( $b ) && 'throttled' === $b['kind'] && $now + 120 === $b['until'],
	var_export( $b, true )
);

$huge = tack_gate_push( 429, '{}', array( 'retry-after' => '999999' ) );
$b    = Tack_Sync_Gate::classify( $huge, $now );
check(
	'a 429 Retry-After is capped at one hour',
	is_array( $b ) && $now + Tack_Sync_Gate::MAX_WAIT === $b['until']
);

// ── The gate: what stops the storm ──────────────────────────────────────────
Tack_Sync_Gate::clear();
check( 'no block before any refusal', null === Tack_Sync_Gate::active_block( $key, $now ) );

Tack_Sync_Gate::record_failure( $err, $key, $now );
check(
	'after a terminal refusal the very next push is held',
	is_array( Tack_Sync_Gate::active_block( $key, $now + 3 ) )
);
check(
	'it is still held 59 minutes later (no 3-second retry storm)',
	is_array( Tack_Sync_Gate::active_block( $key, $now + 59 * 60 ) )
);
check(
	'one re-probe is allowed after an hour (a renewed subscription heals without a key change)',
	null === Tack_Sync_Gate::active_block( $key, $now + Tack_Sync_Gate::TERMINAL_REPROBE + 1 )
);
$stored = get_option( Tack_Sync_Gate::OPTION );
check(
	'the stored block never contains the API key itself',
	is_array( $stored ) && false === strpos( wp_json_encode( $stored ), $key )
);

check(
	'a NEW API key lifts the block immediately',
	null === Tack_Sync_Gate::active_block( 'tk_live_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', $now + 3 )
);

Tack_Sync_Gate::clear();
Tack_Sync_Gate::record_failure( $down, $key, $now );
check( 'a temporary failure does not block the next push', null === Tack_Sync_Gate::active_block( $key, $now + 1 ) );

Tack_Sync_Gate::record_failure( $err, $key, $now );
Tack_Sync_Gate::record_success();
check( 'a successful push clears the block', null === get_option( Tack_Sync_Gate::OPTION, null ) );

// ── What the merchant is told ───────────────────────────────────────────────
Tack_Sync_Gate::record_failure( $err, $key, $now );
$GLOBALS['TACK_CAPS'] = array( 'manage_woocommerce' );
ob_start();
Tack_Sync_Gate::render_admin_notice();
$notice = ob_get_clean();
check( 'wp-admin shows an error notice while sync is blocked', false !== strpos( $notice, 'notice notice-error' ) );
check( 'the notice names the missing scope', false !== strpos( $notice, 'orders:write' ) );
check( 'the notice says orders are not being sent', false !== stripos( $notice, 'not being sent' ) );

$GLOBALS['TACK_CAPS'] = array();
ob_start();
Tack_Sync_Gate::render_admin_notice();
check( 'users who cannot manage WooCommerce do not see it', '' === ob_get_clean() );

tack_test_set_option( 'tack_quotes_api_key', 'tk_live_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb' );
$GLOBALS['TACK_CAPS'] = array( 'manage_woocommerce' );
ob_start();
Tack_Sync_Gate::render_admin_notice();
check( 'the notice disappears once a different key is saved', '' === ob_get_clean() );

// ── The worker consults the gate BEFORE it makes the request ────────────────
$src    = (string) file_get_contents( TACK_QUOTES_DIR . 'includes/class-tack-order-sync.php' );
$start  = strpos( $src, 'public function run_sync(' );
$end    = false === $start ? false : strpos( $src, "\n\t}\n", $start );
$body   = ( false === $start || false === $end ) ? '' : substr( $src, $start, $end - $start );
$gate   = strpos( $body, 'Tack_Sync_Gate::active_block(' );
$send   = strpos( $body, '->sync_order(' );
check(
	'run_sync() checks Tack_Sync_Gate::active_block() before ->sync_order()',
	false !== $gate && false !== $send && $gate < $send
);
check( 'run_sync() records a failed push with the gate', false !== strpos( $body, 'Tack_Sync_Gate::record_failure(' ) );
check( 'run_sync() clears the gate on success', false !== strpos( $body, 'Tack_Sync_Gate::record_success(' ) );

$uninstall = (string) file_get_contents( TACK_QUOTES_DIR . 'uninstall.php' );
check( 'uninstall.php removes the block option', false !== strpos( $uninstall, "'" . Tack_Sync_Gate::OPTION . "'" ) );

Tack_Sync_Gate::clear();
tack_test_set_option( 'tack_quotes_api_key', '' );
$GLOBALS['TACK_CAPS'] = array();
