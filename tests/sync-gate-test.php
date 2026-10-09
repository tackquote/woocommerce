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

$slow = tack_gate_push( 429, wp_json_encode( array( 'statusCode' => 429, 'code' => 'TOO_MANY_REQUESTS', 'message' => 'slow down', 'retryAfterSeconds' => 120 ) ), array( 'retry-after' => '120' ) );
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

// A 429 that is TackQuote's throttle of a scope refusal is still terminal: same
// refusal, louder. Held at least an hour so the notice does not flicker.
$scope429 = tack_gate_push(
	429,
	wp_json_encode( array( 'statusCode' => 429, 'code' => 'insufficient_scope', 'message' => 'stop retrying', 'requiredScopes' => array( 'orders:write' ), 'retryAfterSeconds' => 300 ) ),
	array( 'retry-after' => '300' )
);
$b = Tack_Sync_Gate::classify( $scope429, $now );
check(
	'a 429 carrying insufficient_scope stays TERMINAL',
	is_array( $b ) && 'terminal' === $b['kind'] && array( 'orders:write' ) === $b['scopes'],
	var_export( $b, true )
);
check(
	'... held for max(Retry-After, TERMINAL_REPROBE)',
	is_array( $b ) && $now + Tack_Sync_Gate::TERMINAL_REPROBE === $b['until']
);

// Only TackQuote's OWN error body is terminal: JSON whose statusCode matches the
// HTTP status and which carries a non-empty code.
$proxy = tack_gate_push( 403, wp_json_encode( array( 'error' => 'forbidden', 'message' => 'Blocked by policy' ) ) );
check( 'a 403 with a proxy\'s JSON (no statusCode, no code) is TEMPORARY', null === Tack_Sync_Gate::classify( $proxy, $now ) );
$mismatch = tack_gate_push( 403, wp_json_encode( array( 'statusCode' => 200, 'code' => 'X', 'message' => 'x' ) ) );
check( 'a 403 whose body statusCode disagrees is TEMPORARY', null === Tack_Sync_Gate::classify( $mismatch, $now ) );
$nocode = tack_gate_push( 403, wp_json_encode( array( 'statusCode' => 403, 'code' => '', 'message' => 'x' ) ) );
check( 'a 403 with an empty code is TEMPORARY', null === Tack_Sync_Gate::classify( $nocode, $now ) );

$date429 = tack_gate_push( 429, '{}', array( 'retry-after' => gmdate( 'D, d M Y H:i:s', $now + 90 ) . ' GMT' ) );
$b       = Tack_Sync_Gate::classify( $date429, $now );
check( 'Retry-After as an HTTP-date is honoured', is_array( $b ) && $now + 90 === $b['until'], var_export( $b, true ) );

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
Tack_Sync_Gate::record_failure( $err, $key, $now + 4000 );
check(
	'a re-recorded block keeps the time pushes STARTED being held',
	$now === ( get_option( Tack_Sync_Gate::OPTION )['since'] ?? null )
);
$GLOBALS['TACK_DONE_ACTIONS'] = array();
Tack_Sync_Gate::record_success();
check( 'a successful push clears the block', null === get_option( Tack_Sync_Gate::OPTION, null ) );
check(
	'... and announces the unblock with that start time (drives the re-queue)',
	array( array( Tack_Sync_Gate::UNBLOCKED_ACTION, array( $now ) ) ) === $GLOBALS['TACK_DONE_ACTIONS'],
	var_export( $GLOBALS['TACK_DONE_ACTIONS'], true )
);

$GLOBALS['TACK_DONE_ACTIONS'] = array();
Tack_Sync_Gate::record_success();
check( 'a success with no block announces nothing', array() === $GLOBALS['TACK_DONE_ACTIONS'] );

Tack_Sync_Gate::record_failure( $err, $key, $now );
Tack_Sync_Gate::on_api_key_changed( $key, 'tk_live_cccccccccccccccccccccccccccccccc' );
check(
	'saving a different key lifts the block at once and announces it',
	null === get_option( Tack_Sync_Gate::OPTION, null )
		&& array( array( Tack_Sync_Gate::UNBLOCKED_ACTION, array( $now ) ) ) === $GLOBALS['TACK_DONE_ACTIONS']
);
$GLOBALS['TACK_DONE_ACTIONS'] = array();
Tack_Sync_Gate::record_failure( $err, $key, $now );
Tack_Sync_Gate::on_api_key_changed( $key, $key );
check( 're-saving the SAME key does not lift it', is_array( get_option( Tack_Sync_Gate::OPTION, null ) ) );
Tack_Sync_Gate::clear();

// ── The re-queue that follows an unblock ────────────────────────────────────
require_once TACK_QUOTES_DIR . 'includes/class-tack-order-sync.php';
require_once __DIR__ . '/wc-stubs.php';
tack_test_set_option( 'tack_quotes_enable_order_sync', 'yes' );
$sync                          = new Tack_Order_Sync();
$GLOBALS['TACK_HOOKS'] = array();
$sync->register_worker();
$hooked = array_column( $GLOBALS['TACK_HOOKS'], 'hook' );
check(
	'register_worker wires the unblock, the re-queue job and the key-change hook',
	in_array( Tack_Sync_Gate::UNBLOCKED_ACTION, $hooked, true )
		&& in_array( Tack_Order_Sync::REQUEUE_HOOK, $hooked, true )
		&& in_array( 'update_option_tack_quotes_api_key', $hooked, true ),
	implode( ', ', $hooked )
);
$GLOBALS['TACK_AS_ENQUEUED']   = array();
$GLOBALS['TACK_WC_ORDERS_Q']   = array();
$GLOBALS['TACK_WC_ORDER_IDS']  = array( 11, 12 );
$since                         = time() - 600;
$sync->schedule_requeue( $since );
check(
	'an unblock schedules ONE re-queue job off the request',
	array( array( Tack_Order_Sync::REQUEUE_HOOK, array( $since ), Tack_Order_Sync::SYNC_GROUP, true ) ) === $GLOBALS['TACK_AS_ENQUEUED']
);
$GLOBALS['TACK_AS_ENQUEUED'] = array();
$sync->requeue_unsynced( $since );
$q = $GLOBALS['TACK_WC_ORDERS_Q'][0] ?? array();
check(
	'the re-queue asks WooCommerce for orders modified since the block began',
	'>' . ( $since - 60 ) === ( $q['date_modified'] ?? null ) && 'ids' === ( $q['return'] ?? null ),
	var_export( $q, true )
);
check(
	'... and queues each through the normal worker',
	array( array( 11 ), array( 12 ) ) === array_map(
		function ( $e ) {
			return $e[1];
		},
		array_values(
			array_filter(
				$GLOBALS['TACK_AS_ENQUEUED'],
				function ( $e ) {
					return Tack_Order_Sync::SYNC_HOOK === $e[0];
				}
			)
		)
	)
);
$GLOBALS['TACK_WC_ORDERS_Q'] = array();
$sync->requeue_unsynced( 1 );
check(
	'it never reaches back more than 30 days',
	isset( $GLOBALS['TACK_WC_ORDERS_Q'][0]['date_modified'] )
		&& (int) substr( $GLOBALS['TACK_WC_ORDERS_Q'][0]['date_modified'], 1 ) >= time() - Tack_Order_Sync::REQUEUE_MAX_AGE - 5
);
tack_test_set_option( 'tack_quotes_enable_order_sync', 'no' );
$GLOBALS['TACK_AS_ENQUEUED'] = array();
$sync->requeue_unsynced( $since );
check( 'with order sync switched off, nothing is re-queued', array() === $GLOBALS['TACK_AS_ENQUEUED'] );

// ── What the merchant is told ───────────────────────────────────────────────
Tack_Sync_Gate::record_failure( $err, $key, $now );
$GLOBALS['TACK_CAPS'] = array( 'manage_woocommerce' );
ob_start();
Tack_Sync_Gate::render_admin_notice();
$notice = ob_get_clean();
check( 'wp-admin shows an error notice while sync is blocked', false !== strpos( $notice, 'notice notice-error' ) );
check( 'the notice names the missing scope', false !== strpos( $notice, 'orders:write' ) );
check( 'the notice says orders are not being sent', false !== stripos( $notice, 'not being sent' ) );
check(
	'the notice links the settings page by its registered slug',
	false !== strpos( $notice, 'page=' . Tack_Settings::PAGE_SLUG )
);
check(
	'the lapsed-subscription copy names where to choose a plan (Profile > Billing & Plan)',
	false !== strpos( Tack_Sync_Gate::notice_text( array( 'code' => 'SUBSCRIPTION_INACTIVE', 'status' => 403 ) ), 'Profile > Billing & Plan' )
);

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

// ── Back-off on REPEATED 429s: exponential, jittered, Retry-After as the floor ─
//
// The audit of 2026-10-09 counted ~2,800 calls a day from one install, 94% answered
// 429. A single fixed wait per 429 is not enough when the server keeps saying no:
// each consecutive throttle (no success in between) must wait longer, and the held
// orders must not all come back in the same second.
echo "\n-- 429 back-off math (pure) --\n";
$ladder = array();
foreach ( range( 1, 8 ) as $n ) {
	$ladder[] = Tack_Sync_Gate::backoff_seconds( 0, $n, 0.0 );
}
check(
	'with no Retry-After and no jitter the wait doubles from 60s and stops at MAX_WAIT: 60,120,240,480,960,1920,3600,3600',
	array( 60, 120, 240, 480, 960, 1920, 3600, 3600 ) === $ladder,
	implode( ',', $ladder )
);
check( 'Retry-After larger than the ladder step is the floor (300 > 60 on attempt 1)', 300 === Tack_Sync_Gate::backoff_seconds( 300, 1, 0.0 ) );
check( 'the ladder step wins once it exceeds Retry-After (480 > 300 on attempt 4)', 480 === Tack_Sync_Gate::backoff_seconds( 300, 4, 0.0 ) );
check( 'jitter is ADDED: attempt 1, random 0.5 -> 60 + floor(60*0.5*0.5) = 75', 75 === Tack_Sync_Gate::backoff_seconds( 0, 1, 0.5 ) );
check( 'jitter never lands before Retry-After (300 with max random -> 449, >= 300)', 449 === Tack_Sync_Gate::backoff_seconds( 300, 1, 0.999999 ) );
check( 'jitter cannot exceed JITTER_FRACTION of the base (max random on 60 -> at most 89)', Tack_Sync_Gate::backoff_seconds( 0, 1, 0.999999 ) <= 89 );
check( 'nothing exceeds MAX_WAIT, jitter included', Tack_Sync_Gate::MAX_WAIT === Tack_Sync_Gate::backoff_seconds( 3000, 1, 0.999999 ) );
check( 'a huge attempt number does not overflow', Tack_Sync_Gate::MAX_WAIT === Tack_Sync_Gate::backoff_seconds( 0, 500, 0.0 ) );
check( 'a random outside [0,1) is clamped, never negative', Tack_Sync_Gate::backoff_seconds( 0, 1, -3.0 ) === 60 && Tack_Sync_Gate::backoff_seconds( 0, 1, 7.0 ) <= 89 );
check( 'attempt 0 or negative is treated as the first', 60 === Tack_Sync_Gate::backoff_seconds( 0, 0, 0.0 ) && 60 === Tack_Sync_Gate::backoff_seconds( 0, -2, 0.0 ) );
$drawn = Tack_Sync_Gate::backoff_seconds( 0, 1 );
check( 'with no random supplied one is drawn, inside the same bounds', $drawn >= 60 && $drawn <= 89, (string) $drawn );

echo "\n-- 429 back-off persisted across workers (the option) --\n";
Tack_Sync_Gate::clear();
tack_test_set_option( 'tack_quotes_api_key', $key );
$plain429 = tack_gate_push( 429, '{}' );
$b1       = Tack_Sync_Gate::record_failure( $plain429, $key, $now, 0.0 );
check( 'the first 429 with no Retry-After holds for DEFAULT_WAIT and is attempt 1', is_array( $b1 ) && $now + 60 === $b1['until'] && 1 === $b1['attempt'] );
$b2 = Tack_Sync_Gate::record_failure( $plain429, $key, $now + 61, 0.0 );
check( 'a second 429 before any success doubles the wait (attempt 2, 120s)', is_array( $b2 ) && $now + 61 + 120 === $b2['until'] && 2 === $b2['attempt'] );
$b3 = Tack_Sync_Gate::record_failure( $plain429, $key, $now + 200, 0.0 );
check( 'a third doubles again (attempt 3, 240s)', is_array( $b3 ) && $now + 200 + 240 === $b3['until'] && 3 === $b3['attempt'] );
check(
	'the attempt count is read back from the stored option, not from memory',
	3 === ( get_option( Tack_Sync_Gate::OPTION )['attempt'] ?? null )
);
check( 'the block keeps the time pushes STARTED being held across the escalation', $now === ( get_option( Tack_Sync_Gate::OPTION )['since'] ?? null ) );
check( 'while held, active_block() reports the throttle', 'throttled' === ( Tack_Sync_Gate::active_block( $key, $now + 300 )['kind'] ?? null ) );
$b4 = Tack_Sync_Gate::record_failure( $slow, $key, $now + 500, 0.0 );
check( 'Retry-After 120 on attempt 4 loses to the ladder (480s): the server floor is honoured, never undercut', is_array( $b4 ) && $now + 500 + 480 === $b4['until'] );
Tack_Sync_Gate::record_success();
$b5 = Tack_Sync_Gate::record_failure( $plain429, $key, $now + 1000, 0.0 );
check( 'a success resets the ladder: the next 429 is attempt 1 again', is_array( $b5 ) && 1 === $b5['attempt'] && $now + 1000 + 60 === $b5['until'] );
Tack_Sync_Gate::record_failure( $err, $key, $now + 1100 );
$b6 = Tack_Sync_Gate::record_failure( $plain429, $key, $now + 1200, 0.0 );
check( 'a throttle following a TERMINAL block starts the ladder at 1 (only consecutive throttles escalate)', is_array( $b6 ) && 1 === $b6['attempt'] );
Tack_Sync_Gate::clear();

echo "\n-- a throttled order is put back for when the pause ends --\n";
tack_test_set_option( 'tack_quotes_enable_order_sync', 'yes' );
$worker                       = new Tack_Order_Sync();
$GLOBALS['TACK_HOOKS']        = array();
$worker->register_worker();
$sync_hook = null;
foreach ( $GLOBALS['TACK_HOOKS'] as $h ) {
	if ( Tack_Order_Sync::SYNC_HOOK === $h['hook'] ) {
		$sync_hook = $h;
	}
}
check( 'the worker accepts TWO args, so a retry job carrying the marker reaches run_sync()', is_array( $sync_hook ) && 2 === (int) ( $sync_hook['args'] ?? 0 ), var_export( $sync_hook, true ) );

// The push itself is throttled.
$GLOBALS['TACK_AS_SCHEDULED'] = array();
$GLOBALS['TACK_HTTP_CALLS']   = 0;
tack_test_set_http_response( 429, '{}', array( 'retry-after' => '90' ) );
$t0 = time();
$worker->run_sync( 501 );
$put_back = $GLOBALS['TACK_AS_SCHEDULED'][0] ?? null;
check( 'a 429 on the push makes exactly one request and puts the order back once', 1 === $GLOBALS['TACK_HTTP_CALLS'] && 1 === count( $GLOBALS['TACK_AS_SCHEDULED'] ) );
check(
	'the retry carries the order id and the retry marker, in the tackquote group, unique',
	is_array( $put_back ) && Tack_Order_Sync::SYNC_HOOK === $put_back[1]
		&& array( 501, Tack_Order_Sync::RETRY_MARKER ) === $put_back[2]
		&& Tack_Order_Sync::SYNC_GROUP === $put_back[3] && true === $put_back[4],
	var_export( $put_back, true )
);
// run_sync() draws REAL jitter here (record_failure() with no $random), so the block
// ends anywhere in [t0 + 90, t0 + backoff_seconds( 90, 1, max )]; the retry may not be
// earlier than the lower edge and may not exceed the upper edge plus the spread.
check(
	'the retry is scheduled for the block end, never earlier (Retry-After 90s; wp_rand stubbed to the lower bound)',
	is_array( $put_back ) && $put_back[0] >= $t0 + 90
		&& $put_back[0] <= time() + Tack_Sync_Gate::backoff_seconds( 90, 1, 0.999999 ) + Tack_Order_Sync::RETRY_SPREAD,
	'scheduled ' . ( $put_back[0] ?? 'nothing' ) . ' vs t0 ' . $t0
);

// A second order arrives while the pause stands: no request, put back for the same end.
$GLOBALS['TACK_AS_SCHEDULED'] = array();
$GLOBALS['TACK_HTTP_CALLS']   = 0;
$worker->run_sync( 502 );
check( 'an order arriving while throttled makes NO request', 0 === $GLOBALS['TACK_HTTP_CALLS'] );
check(
	'... and is put back for the same block end with its own id',
	array( 502, Tack_Order_Sync::RETRY_MARKER ) === ( $GLOBALS['TACK_AS_SCHEDULED'][0][2] ?? null )
		&& ( $GLOBALS['TACK_AS_SCHEDULED'][0][0] ?? 0 ) >= $t0 + 90
);

// The marker reaches run_sync as the second arg without changing the work.
$GLOBALS['TACK_AS_SCHEDULED'] = array();
$GLOBALS['TACK_HTTP_CALLS']   = 0;
$worker->run_sync( 502, Tack_Order_Sync::RETRY_MARKER );
check( 'a retry job that runs while still throttled is held and put back again, not sent', 0 === $GLOBALS['TACK_HTTP_CALLS'] && 1 === count( $GLOBALS['TACK_AS_SCHEDULED'] ) );

// A TERMINAL hold is NOT rescheduled per order (the re-queue on unblock covers it).
Tack_Sync_Gate::clear();
Tack_Sync_Gate::record_failure( $err, $key, time() );
$GLOBALS['TACK_AS_SCHEDULED'] = array();
$GLOBALS['TACK_HTTP_CALLS']   = 0;
$worker->run_sync( 503 );
check( 'an order held by a TERMINAL refusal makes no request and schedules no per-order retry', 0 === $GLOBALS['TACK_HTTP_CALLS'] && array() === $GLOBALS['TACK_AS_SCHEDULED'] );

// A temporary failure (5xx) is neither held nor put back: the next trigger retries it.
Tack_Sync_Gate::clear();
$GLOBALS['TACK_AS_SCHEDULED'] = array();
tack_test_set_http_response( 502, '' );
$worker->run_sync( 504 );
check( 'a 5xx schedules no retry (unchanged: the next trigger pushes again)', array() === $GLOBALS['TACK_AS_SCHEDULED'] );

Tack_Sync_Gate::clear();
tack_test_set_option( 'tack_quotes_enable_order_sync', 'no' );
tack_test_set_option( 'tack_quotes_api_key', '' );
$GLOBALS['TACK_CAPS'] = array();
