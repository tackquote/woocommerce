<?php
/**
 * WooCommerce → TackQuote (1.12.0): the TackQuote dashboard inside WooCommerce admin.
 *
 * Wire contract (TackQuote API, lane WOO-EMBED, migration 480):
 *   POST /integrations/woocommerce/embed/open   {wpUserId, wpUserSecret, wpUserLabel?, purpose}
 *        -> {status:'linked', loginCode, frameTicket, frameWithheld, appOrigin, account}
 *         | {status:'unlinked', reason, appOrigin}
 *   POST /integrations/woocommerce/embed/claim  {code, wpUserId, wpUserLogin, wpUserSecret}
 *        -> {status:'linked'} | 400 wrong code | 429 paused | 403 refused | 409 no store
 *         | {status:'refused', code, message} | {status:'no_store', message}
 *   POST /integrations/woocommerce/embed/unlink {wpUserId, wpUserSecret} -> {removed}
 *   Gated on the ping capability `embedded_dashboard`.
 *
 * MUTATIONS (scratchpad woo-embed/mutate-php.py; each turns a check red):
 *   E1 menu capability manage_options->read                → capability check
 *   E2 skip the nonce check in guard()                      → nonce check
 *   E3 accept an http app origin for the default API URL    → origin check
 *   E4 frame without a frame ticket                         → no-ticket check
 *   E5 render a frame for an unlinked user                  → unlinked check
 *   E6 regenerate the secret on every open                  → secret check
 *   E7 keep the secret after unlink                         → unlink check
 *   E8 delete the secret when the unlink call failed (P1)   → keep-secret check
 *   E9 send another user's id or no secret with the claim    → claim body check
 *
 * Included from tests/run.php.
 *
 * @package TackQuotes
 */

require_once TACK_QUOTES_DIR . 'includes/class-tack-api-client.php';
require_once TACK_QUOTES_DIR . 'includes/class-tack-embed-admin.php';

if ( ! function_exists( 'wp_safe_redirect' ) ) {
	/** @param string $url URL. */
	function wp_safe_redirect( $url ) {
		$GLOBALS['TACK_EMBED_REDIRECT'] = $url;
	}
}

/** A client that answers from a script and records calls. */
class Tack_Test_Embed_Client extends Tack_Api_Client {
	/** @var array */
	public $calls = array();
	/** @var array */
	public $answers = array();
	/** @var array */
	public $caps = array( 'attachments', 'embedded_dashboard' );
	/** @var bool */
	public $forgot = false;
	/** @inheritDoc */
	public function request( $method, $path, $body = null, $timeout = null, $headers = array() ) {
		$this->calls[] = array( $method, $path, $body );
		foreach ( $this->answers as $fragment => $answer ) {
			if ( false !== strpos( $path, $fragment ) ) {
				return is_callable( $answer ) ? $answer() : $answer;
			}
		}
		return new WP_Error( 'tack_http_404', 'no route', array( 'status' => 404 ) );
	}
	/** @inheritDoc */
	public function server_capabilities( $refresh = false ) {
		return $this->caps;
	}
	/** @inheritDoc */
	public function forget_capabilities() {
		$this->forgot = true;
	}
}

/** Records redirects instead of ending the request. */
class Tack_Test_Embed_Admin extends Tack_Embed_Admin {
	/** @var array */
	public $redirects = array();
	/** @param string $url URL. @param string $host Host. */
	protected function redirect( $url, $host ) {
		$this->redirects[] = array( $url, $host );
	}
}

/** Render the page, capturing output. */
function tack_embed_render( $admin ) {
	ob_start();
	try {
		$admin->render_page();
	} catch ( Tack_Test_Wp_Die $e ) {
		ob_end_clean();
		return 'DIE:' . $e->getMessage();
	}
	return (string) ob_get_clean();
}

$embed_linked = array(
	'status'      => 'linked',
	'loginCode'   => '0a1b2c3d-1111-4111-8111-222222222222',
	'frameTicket' => str_repeat( 'F', 43 ),
	'appOrigin'   => 'https://app.tackquote.com',
	'framePath'   => '/woocommerce/app',
	'openPath'    => '/woocommerce/open',
	'account'     => array( 'name' => 'Rae <b>Rep</b>', 'email' => 'rae@example.test' ),
);
$embed_unlinked = array(
	'status'    => 'unlinked',
	'appOrigin' => 'https://app.tackquote.com',
	'reason'    => 'no_link',
);
$GLOBALS['TACK_USER_LOGIN'] = 'shopmanager';

$GLOBALS['TACK_CAPS'] = array( 'manage_woocommerce' );
tack_test_set_logged_in( true, 'admin@example.test' );
tack_test_set_option( 'tack_quotes_api_key', 'tq_test_key_1234567890' );
tack_test_set_option( 'tack_quotes_api_url', 'https://api.tackquote.com/v1' );
$GLOBALS['TACK_USER_META'] = array();

// ── Menu ──────────────────────────────────────────────────────────────────
$GLOBALS['TACK_REGISTERED_PAGES'] = array();
( new Tack_Embed_Admin() )->add_menu();
check(
	'WooCommerce → TackQuote is registered for manage_woocommerce (E1)',
	'manage_woocommerce' === ( $GLOBALS['TACK_REGISTERED_PAGES'][ Tack_Embed_Admin::PAGE_SLUG ] ?? null )
);

// ── Linked: one frame, src from the ticket + code, the code in the FRAGMENT ──
$client                              = new Tack_Test_Embed_Client();
$client->answers['/embed/open']      = $embed_linked;
$out                                 = tack_embed_render( new Tack_Test_Embed_Admin( $client ) );
$expected_src                        = 'https://app.tackquote.com/woocommerce/app?frame=' . str_repeat( 'F', 43 ) . '#code=0a1b2c3d-1111-4111-8111-222222222222';
check( 'linked: the frame loads /woocommerce/app?frame=<ticket>#code=<code>', false !== strpos( $out, 'src="' . $expected_src . '"' ), $out );
check( 'linked: the frame is sandboxed without top navigation', false !== strpos( $out, 'sandbox="allow-scripts' ) && false === strpos( $out, 'allow-top-navigation' ) );
check( 'linked: the account name is escaped', false !== strpos( $out, 'Rae &lt;b&gt;Rep&lt;/b&gt;' ) && false === strpos( $out, '<b>Rep</b>' ) );
check( 'linked: Open in a new tab and Unlink are nonce-protected POST forms', 2 <= substr_count( $out, 'name="_tqnonce"' ) && false !== strpos( $out, 'target="_blank"' ) );
$open_call = $client->calls[0] ?? array();
check( 'the open goes server to server with the WordPress user id and a 64-hex secret', '/integrations/woocommerce/embed/open' === ( $open_call[1] ?? '' ) && '1' === ( $open_call[2]['wpUserId'] ?? '' ) && 1 === preg_match( '/^[0-9a-f]{64}$/', (string) ( $open_call[2]['wpUserSecret'] ?? '' ) ) && 'frame' === ( $open_call[2]['purpose'] ?? '' ) );
check( 'the secret never reaches the page', false === strpos( $out, (string) ( $open_call[2]['wpUserSecret'] ?? 'x' ) ) );

// The secret is created once and reused (E6).
$secret1 = $open_call[2]['wpUserSecret'] ?? '';
tack_embed_render( new Tack_Test_Embed_Admin( $client ) );
check( 'the per-user secret is stable across opens (E6)', $secret1 === ( $client->calls[1][2]['wpUserSecret'] ?? '' ) && $secret1 === ( $GLOBALS['TACK_USER_META'][1][ Tack_Embed_Admin::SECRET_META ] ?? '' ) );

// No frame ticket (TackQuote does not know the store's address): no frame (E4).
$client                         = new Tack_Test_Embed_Client();
$client->answers['/embed/open'] = array_merge( $embed_linked, array( 'frameTicket' => null ) );
$out                            = tack_embed_render( new Tack_Test_Embed_Admin( $client ) );
check( 'no frame ticket: no iframe, the new-tab advice (E4)', false === strpos( $out, '<iframe' ) && false !== strpos( $out, 'Open in a new tab' ) );

// An app origin that is not app.tackquote.com for the default API URL, or not https (E3).
foreach ( array( 'http://app.tackquote.com', 'https://evil.example', 'https://app.tackquote.com/x', 'javascript:alert(1)' ) as $bad ) {
	$client                         = new Tack_Test_Embed_Client();
	$client->answers['/embed/open'] = array_merge( $embed_linked, array( 'appOrigin' => $bad ) );
	$out                            = tack_embed_render( new Tack_Test_Embed_Admin( $client ) );
	check( "a bad app origin is never framed: $bad (E3)", false === strpos( $out, '<iframe' ) );
}
tack_test_set_option( 'tack_quotes_api_url', 'http://localhost:3001/v1' );
check( 'a development API URL may use an http development host', 'http://localhost:3000' === Tack_Embed_Admin::app_origin( 'http://localhost:3000' ) );
check( '...but never a public http host', '' === Tack_Embed_Admin::app_origin( 'http://evil.example' ) );
tack_test_set_option( 'tack_quotes_api_url', 'https://api.tackquote.com/v1' );

// ── Unlinked: never a frame, only "Link my TackQuote account" (E5) ──────────
$client                         = new Tack_Test_Embed_Client();
$client->answers['/embed/open'] = $embed_unlinked;
$out                            = tack_embed_render( new Tack_Test_Embed_Admin( $client ) );
check( 'unlinked: no frame, no session, a nonce-protected code field (E5)', false === strpos( $out, '<iframe' ) && false !== strpos( $out, 'name="tq_link_code"' ) && false !== strpos( $out, 'value="' . Tack_Embed_Admin::CLAIM_ACTION . '"' ) && false !== strpos( $out, 'name="_tqnonce"' ) );

// The store address moved: no frame, the reason, new tab still offered.
$client                         = new Tack_Test_Embed_Client();
$client->answers['/embed/open'] = array_merge( $embed_linked, array( 'frameTicket' => null, 'frameWithheld' => 'store_address_moved' ) );
$out                            = tack_embed_render( new Tack_Test_Embed_Admin( $client ) );
check( 'a moved store address: no frame, says why, new tab offered', false === strpos( $out, '<iframe' ) && false !== strpos( $out, 'address changed recently' ) && false !== strpos( $out, 'Open in a new tab' ) );

// ── Disconnected: no code, a reconnect link, capabilities forgotten ─────────
$client                         = new Tack_Test_Embed_Client();
$client->answers['/embed/open'] = new WP_Error( 'tack_http_401', 'Invalid API key', array( 'status' => 401 ) );
$out                            = tack_embed_render( new Tack_Test_Embed_Admin( $client ) );
check( 'a revoked key: explains, links to reconnect, no frame', false !== strpos( $out, 'Reconnect to TackQuote' ) && false === strpos( $out, '<iframe' ) && $client->forgot );

// Refused (forced SSO, account off) and no_store: the API's sentence, escaped.
$client                         = new Tack_Test_Embed_Client();
$client->answers['/embed/open'] = array( 'status' => 'refused', 'code' => 'WOO_EMBED_FORCED_SSO', 'message' => 'Requires <SSO>.' );
$out                            = tack_embed_render( new Tack_Test_Embed_Admin( $client ) );
check( 'refused: the sentence, escaped, no frame', false !== strpos( $out, 'Requires &lt;SSO&gt;.' ) && false === strpos( $out, '<iframe' ) );

// No key yet, and an older server without the capability: nothing is called.
$client = new Tack_Test_Embed_Client();
tack_test_set_option( 'tack_quotes_api_key', '' );
$out = tack_embed_render( new Tack_Test_Embed_Admin( $client ) );
check( 'not connected: Connect link, no API call', false !== strpos( $out, 'Connect to TackQuote' ) && 0 === count( $client->calls ) );
tack_test_set_option( 'tack_quotes_api_key', 'tq_test_key_1234567890' );
$client       = new Tack_Test_Embed_Client();
$client->caps = array( 'attachments' );
$out          = tack_embed_render( new Tack_Test_Embed_Admin( $client ) );
check( 'an older server: no open call, a plain notice', 0 === count( $client->calls ) && false === strpos( $out, '<iframe' ) );

// Capability on the page itself.
$GLOBALS['TACK_CAPS'] = array();
$out                  = tack_embed_render( new Tack_Test_Embed_Admin( new Tack_Test_Embed_Client() ) );
check( 'the page refuses a user without manage_woocommerce', 0 === strpos( $out, 'DIE:' ) );
$GLOBALS['TACK_CAPS'] = array( 'manage_woocommerce' );

// ── The buttons ───────────────────────────────────────────────────────────
$GLOBALS['TACK_NONCE_VALID'] = true;
$client                      = new Tack_Test_Embed_Client();
$client->answers['/embed/open'] = array_merge( $embed_linked, array( 'frameTicket' => null ) );
$admin                       = new Tack_Test_Embed_Admin( $client );
$_POST['_tqnonce']           = wp_create_nonce( Tack_Embed_Admin::TAB_ACTION );
$admin->handle_tab();
check(
	'Open in a new tab: a fresh tab code, to /woocommerce/open#code=, host allowed',
	array( 'https://app.tackquote.com/woocommerce/open#code=0a1b2c3d-1111-4111-8111-222222222222', 'app.tackquote.com' ) === ( $admin->redirects[0] ?? null ) && 'tab' === ( $client->calls[0][2]['purpose'] ?? '' )
);

// Link my TackQuote account: the typed code goes server to server with THIS user's id,
// login and secret; only a fixed flag comes back to the page (E9).
$client                          = new Tack_Test_Embed_Client();
$client->answers['/embed/claim'] = array( 'status' => 'linked' );
$admin                           = new Tack_Test_Embed_Admin( $client );
$_POST['_tqnonce']               = wp_create_nonce( Tack_Embed_Admin::CLAIM_ACTION );
$_POST['tq_link_code']           = ' abcd-2345 ';
$admin->handle_claim();
$claim = $client->calls[0] ?? array();
check(
	'claim: code, this user id, login and secret go to /embed/claim (E9)',
	'/integrations/woocommerce/embed/claim' === ( $claim[1] ?? '' ) && 'ABCD-2345' === ( $claim[2]['code'] ?? '' ) && '1' === ( $claim[2]['wpUserId'] ?? '' ) && 'shopmanager' === ( $claim[2]['wpUserLogin'] ?? '' ) && ( $GLOBALS['TACK_USER_META'][1][ Tack_Embed_Admin::SECRET_META ] ?? 'x' ) === ( $claim[2]['wpUserSecret'] ?? '' )
);
check( 'claim: back to the page with "linked"', array( Tack_Embed_Admin::page_url( 'linked' ), '' ) === ( $admin->redirects[0] ?? null ) );
foreach ( array( 400 => 'wrong_code', 429 => 'throttled', 403 => 'refused', 409 => 'no_store', 503 => 'failed' ) as $status => $flag ) {
	$client                          = new Tack_Test_Embed_Client();
	$client->answers['/embed/claim'] = new WP_Error( 'tack_http_' . $status, 'API said <b>no</b>', array( 'status' => $status ) );
	$admin                           = new Tack_Test_Embed_Admin( $client );
	$admin->handle_claim();
	check( "claim: HTTP $status reads as $flag, never the API's text", array( Tack_Embed_Admin::page_url( $flag ), '' ) === ( $admin->redirects[0] ?? null ) );
}
$client                = new Tack_Test_Embed_Client();
$admin                 = new Tack_Test_Embed_Admin( $client );
$_POST['tq_link_code'] = 'x';
$admin->handle_claim();
check( 'claim: an impossible code is not sent at all', 0 === count( $client->calls ) && array( Tack_Embed_Admin::page_url( 'wrong_code' ), '' ) === ( $admin->redirects[0] ?? null ) );
unset( $_POST['tq_link_code'] );

// A wrong nonce, or another action's nonce: refused before any API call (E2).
$client            = new Tack_Test_Embed_Client();
$admin             = new Tack_Test_Embed_Admin( $client );
$_POST['_tqnonce'] = wp_create_nonce( Tack_Embed_Admin::CLAIM_ACTION );
$died              = false;
try {
	$admin->handle_tab();
} catch ( Tack_Test_Wp_Die $e ) {
	$died = true;
}
check( 'a nonce of another action is refused, nothing called (E2)', $died && 0 === count( $client->calls ) );

// Unlink: TackQuote first, then the secret is replaced (E7).
$client                           = new Tack_Test_Embed_Client();
$client->answers['/embed/unlink'] = array( 'removed' => true );
$admin                            = new Tack_Test_Embed_Admin( $client );
$before                           = $GLOBALS['TACK_USER_META'][1][ Tack_Embed_Admin::SECRET_META ] ?? '';
$_POST['_tqnonce']                = wp_create_nonce( Tack_Embed_Admin::UNLINK_ACTION );
$admin->handle_unlink();
check(
	'Unlink: the API gets this user and secret, then the secret is gone (E7)',
	'/integrations/woocommerce/embed/unlink' === ( $client->calls[0][1] ?? '' ) && $before === ( $client->calls[0][2]['wpUserSecret'] ?? '' ) && '' === ( $GLOBALS['TACK_USER_META'][1][ Tack_Embed_Admin::SECRET_META ] ?? '' )
);

// P1: an unlink TackQuote did not confirm keeps the secret (E8).
foreach ( array( new WP_Error( 'tack_http_503', 'down', array( 'status' => 503 ) ), array( 'removed' => false ) ) as $answer ) {
	$GLOBALS['TACK_USER_META'][1][ Tack_Embed_Admin::SECRET_META ] = str_repeat( 'c', 64 );
	$client                           = new Tack_Test_Embed_Client();
	$client->answers['/embed/unlink'] = $answer;
	$admin                            = new Tack_Test_Embed_Admin( $client );
	$_POST['_tqnonce']                = wp_create_nonce( Tack_Embed_Admin::UNLINK_ACTION );
	$admin->handle_unlink();
	check( 'Unlink not confirmed: the secret stays, "failed" (E8)', str_repeat( 'c', 64 ) === ( $GLOBALS['TACK_USER_META'][1][ Tack_Embed_Admin::SECRET_META ] ?? '' ) && array( Tack_Embed_Admin::page_url( 'failed' ), '' ) === ( $admin->redirects[0] ?? null ) );
}
unset( $_POST['_tqnonce'] );
