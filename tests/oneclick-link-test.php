<?php
/**
 * WooCommerce → TackQuote (1.13.0): "Link my TackQuote account" in ONE place, started in
 * WordPress. The typed link code stays as the fallback.
 *
 * Wire contract (TackQuote API, lane WOO-ONECLICK-LINK, migration 481; ping capability
 * `embedded_link_oauth`):
 *   POST /integrations/woocommerce/embed/link-requests
 *        {wpUserId, wpUserLogin, wpUserSecret, codeChallenge, codeChallengeMethod:'S256',
 *         state, returnUrl}
 *        -> {authorizeUrl: '<app>/auth/link/woocommerce#request=<43>', expiresInSeconds}
 *         | 409 {code: WOO_LINK_ONECLICK_ADDRESS_MOVED | _RETURN_MISMATCH | _NO_ADDRESS
 *                     | WOO_LINK_NO_STORE} | 403 forced SSO | 401 key refused
 *   (browser) <app>/auth/link/woocommerce -> admin-post.php?action=tackquote_link_return
 *        &code=<43>&state=<state> | &error=access_denied&state=<state>
 *   POST /integrations/woocommerce/embed/link-exchange
 *        {code, codeVerifier, wpUserId, wpUserSecret} -> {status:'linked', account}
 *         | 400 WOO_LINK_GRANT_INVALID | 403 account | 401 key refused
 *
 * MUTATIONS (scratchpad woo-oneclick/mutate-php.py; each turns a check red):
 *   P1 offer the one-click button without the server capability   → old-server check
 *   P2 skip the nonce/capability guard on start                    → start guard check
 *   P3 open an authorize URL on another host / path / without the fragment → authorize check
 *   P4 keep the pending meta after a failed start                   → failed-start check
 *   P5 accept a return whose state does not match                   → state check
 *   P6 keep the pending meta after a return (replay)                → replay check
 *   P7 exchange without the verifier / with another user's secret   → exchange body check
 *   P8 accept an expired pending link                               → expiry check
 *   P9 read "linked" from an exchange TackQuote did not confirm      → confirm check
 *   P10 no capability check on the return                           → return capability check
 *
 * Included from tests/run.php after embed-admin-test.php (reuses its client and fixtures).
 *
 * @package TackQuotes
 */

if ( ! function_exists( 'auth_redirect' ) ) {
	/** Records the call; WordPress would redirect to wp-login.php and exit. */
	function auth_redirect() {
		$GLOBALS['TACK_AUTH_REDIRECT'] = true;
	}
}

$ol_auth_url = 'https://app.tackquote.com/auth/link/woocommerce#request=' . str_repeat( 'R', 43 );
$ol_return   = 'https://shop.example/wp-admin/admin-post.php?action=tackquote_link_return';
$ol_caps     = array( 'attachments', 'embedded_dashboard', 'embedded_link_oauth' );

$GLOBALS['TACK_CAPS']        = array( 'manage_woocommerce' );
$GLOBALS['TACK_NONCE_VALID'] = true;
$GLOBALS['TACK_USER_META']   = array();
tack_test_set_logged_in( true, 'admin@example.test' );
tack_test_set_option( 'tack_quotes_api_key', 'tq_test_key_1234567890' );
tack_test_set_option( 'tack_quotes_api_url', 'https://api.tackquote.com/v1' );

// ── The unlinked page: ONE primary button; the code folded under "Have a link code?" ──
$client                         = new Tack_Test_Embed_Client();
$client->caps                   = $ol_caps;
$client->answers['/embed/open'] = $embed_unlinked;
$out                            = tack_embed_render( new Tack_Test_Embed_Admin( $client ) );
check(
	'one-click: the primary button is a nonce-protected POST to the start action, in a new tab',
	false !== strpos( $out, 'value="' . Tack_Embed_Admin::LINK_START_ACTION . '"' ) && false !== strpos( $out, 'button-primary">Link my TackQuote account</button>' ) && false !== strpos( $out, 'target="_blank" rel="noopener"' ),
	$out
);
check(
	'one-click: the link code stays, folded and secondary, under "Have a link code?"',
	false !== strpos( $out, '<summary>Have a link code?</summary>' ) && false !== strpos( $out, 'name="tq_link_code"' ) && false !== strpos( $out, 'button-secondary">Link with this code</button>' ) && 1 === substr_count( $out, 'button-primary' )
);
// An older server (no capability): the 1.12.0 page, code only (P1).
$client                         = new Tack_Test_Embed_Client();
$client->answers['/embed/open'] = $embed_unlinked;
$out                            = tack_embed_render( new Tack_Test_Embed_Admin( $client ) );
check(
	'an older server: no one-click button, the code form as before (P1)',
	false === strpos( $out, Tack_Embed_Admin::LINK_START_ACTION ) && false === strpos( $out, 'Have a link code?' ) && false !== strpos( $out, 'button-primary">Link my TackQuote account</button>' ) && false !== strpos( $out, 'name="tq_link_code"' )
);

// ── The authorize URL this plugin will open (P3) ─────────────────────────────
check( 'authorize URL: the TackQuote app page with a one-time request', 'app.tackquote.com' === Tack_Embed_Admin::authorize_url_host( $ol_auth_url ) );
foreach ( array(
	'https://evil.example/auth/link/woocommerce#request=' . str_repeat( 'R', 43 ),
	'http://app.tackquote.com/auth/link/woocommerce#request=' . str_repeat( 'R', 43 ),
	'https://app.tackquote.com/auth/link/other#request=' . str_repeat( 'R', 43 ),
	'https://app.tackquote.com/auth/link/woocommerce?x=1#request=' . str_repeat( 'R', 43 ),
	'https://app.tackquote.com/auth/link/woocommerce#request=short',
	'https://u:p@app.tackquote.com/auth/link/woocommerce#request=' . str_repeat( 'R', 43 ),
	'javascript:alert(1)',
	42,
) as $bad ) {
	check( 'authorize URL refused: ' . ( is_string( $bad ) ? $bad : gettype( $bad ) ) . ' (P3)', '' === Tack_Embed_Admin::authorize_url_host( $bad ) );
}
check( 'the expected return address is this site’s admin-post.php return action', $ol_return === Tack_Embed_Admin::link_return_url() );

// ── Start ──────────────────────────────────────────────────────────────────
$client                                  = new Tack_Test_Embed_Client();
$client->answers['/embed/link-requests'] = array( 'authorizeUrl' => $ol_auth_url, 'expiresInSeconds' => 600 );
$admin                                   = new Tack_Test_Embed_Admin( $client );
$_POST['_tqnonce']                       = wp_create_nonce( Tack_Embed_Admin::LINK_START_ACTION );
$admin->handle_link_start();
$ol_start   = $client->calls[0] ?? array();
$ol_pending = $GLOBALS['TACK_USER_META'][1][ Tack_Embed_Admin::PENDING_META ] ?? null;
check( 'start: server to server, to /embed/link-requests', array( 'POST', '/integrations/woocommerce/embed/link-requests' ) === array( $ol_start[0] ?? '', $ol_start[1] ?? '' ) );
check(
	'start: this user id, login and secret, an S256 challenge of the stored verifier, and the expected return',
	'1' === ( $ol_start[2]['wpUserId'] ?? '' ) && 'shopmanager' === ( $ol_start[2]['wpUserLogin'] ?? '' )
		&& ( $GLOBALS['TACK_USER_META'][1][ Tack_Embed_Admin::SECRET_META ] ?? 'x' ) === ( $ol_start[2]['wpUserSecret'] ?? '' )
		&& 'S256' === ( $ol_start[2]['codeChallengeMethod'] ?? '' )
		&& is_array( $ol_pending ) && Tack_Connect::challenge( $ol_pending['verifier'] ) === ( $ol_start[2]['codeChallenge'] ?? '' )
		&& 1 === preg_match( '/^[A-Za-z0-9_-]{43}$/', (string) $ol_pending['verifier'] )
		&& $ol_return === ( $ol_start[2]['returnUrl'] ?? '' )
);
check(
	'start: the verifier never leaves WordPress; only a hash of the state is kept',
	false === strpos( (string) wp_json_encode( $ol_start[2] ?? array() ), (string) ( $ol_pending['verifier'] ?? 'x' ) )
		&& hash( 'sha256', (string) ( $ol_start[2]['state'] ?? '' ) ) === ( $ol_pending['state'] ?? '' )
		&& 1 === preg_match( '/^[A-Za-z0-9_-]{16,128}$/', (string) ( $ol_start[2]['state'] ?? '' ) )
);
check( 'start: the tab goes to the authorize URL, its host allowed', array( $ol_auth_url, 'app.tackquote.com' ) === ( $admin->redirects[0] ?? null ) );
$ol_state = (string) ( $ol_start[2]['state'] ?? '' );

// A wrong nonce or no capability: refused before anything (P2).
$client                      = new Tack_Test_Embed_Client();
$admin                       = new Tack_Test_Embed_Admin( $client );
$_POST['_tqnonce']           = wp_create_nonce( Tack_Embed_Admin::CLAIM_ACTION );
$died                        = false;
try {
	$admin->handle_link_start();
} catch ( Tack_Test_Wp_Die $e ) {
	$died = true;
}
check( 'start: another action’s nonce is refused, nothing called (P2)', $died && 0 === count( $client->calls ) );
$GLOBALS['TACK_CAPS'] = array();
$_POST['_tqnonce']    = wp_create_nonce( Tack_Embed_Admin::LINK_START_ACTION );
$died                 = false;
try {
	$admin->handle_link_start();
} catch ( Tack_Test_Wp_Die $e ) {
	$died = true;
}
check( 'start: without manage_woocommerce, refused, nothing called (P2)', $died && 0 === count( $client->calls ) );
$GLOBALS['TACK_CAPS'] = array( 'manage_woocommerce' );

// Refused starts: a fixed flag, the pending link dropped (P4), never the API's text.
$ol_refusals = array(
	'moved'        => new WP_Error( 'tack_http_409', 'x <b>', array( 'status' => 409, 'code' => 'WOO_LINK_ONECLICK_ADDRESS_MOVED' ) ),
	'address'      => new WP_Error( 'tack_http_409', 'x', array( 'status' => 409, 'code' => 'WOO_LINK_ONECLICK_RETURN_MISMATCH' ) ),
	'no_oneclick'  => new WP_Error( 'tack_http_409', 'x', array( 'status' => 409, 'code' => 'WOO_LINK_ONECLICK_NO_ADDRESS' ) ),
	'no_store'     => new WP_Error( 'tack_http_409', 'x', array( 'status' => 409, 'code' => 'WOO_LINK_NO_STORE' ) ),
	'refused'      => new WP_Error( 'tack_http_403', 'x', array( 'status' => 403, 'code' => 'WOO_EMBED_FORCED_SSO' ) ),
	'disconnected' => new WP_Error( 'tack_http_401', 'x', array( 'status' => 401 ) ),
	'failed'       => new WP_Error( 'tack_http_503', 'x', array( 'status' => 503 ) ),
	'bad_open'     => array( 'authorizeUrl' => 'https://evil.example/auth/link/woocommerce#request=' . str_repeat( 'R', 43 ) ),
);
foreach ( $ol_refusals as $flag => $answer ) {
	$client                                  = new Tack_Test_Embed_Client();
	$client->answers['/embed/link-requests'] = $answer;
	$admin                                   = new Tack_Test_Embed_Admin( $client );
	$admin->handle_link_start();
	check(
		"start refused: reads as $flag, the pending link is dropped (P4)",
		array( Tack_Embed_Admin::page_url( $flag ), '' ) === ( $admin->redirects[0] ?? null ) && ! isset( $GLOBALS['TACK_USER_META'][1][ Tack_Embed_Admin::PENDING_META ] )
	);
	if ( 'disconnected' === $flag ) {
		check( 'start refused by the key guard: capabilities forgotten', $client->forgot );
	}
}
unset( $_POST['_tqnonce'] );

// Every flag the start or the return can produce has its own fixed message on the page.
foreach ( array( 'moved', 'address', 'no_oneclick', 'bad_open', 'expired', 'cancelled', 'grant' ) as $flag ) {
	$_GET['tq_embed']               = $flag;
	$client                         = new Tack_Test_Embed_Client();
	$client->caps                   = $ol_caps;
	$client->answers['/embed/open'] = $embed_unlinked;
	$out                            = tack_embed_render( new Tack_Test_Embed_Admin( $client ) );
	check( "the page explains the $flag result", 1 === substr_count( $out, 'notice notice-' ) );
}
unset( $_GET['tq_embed'] );

// ── Return ─────────────────────────────────────────────────────────────────
/** A pending one-click link of user 1, as start leaves it. */
function tack_ol_pending( $state, $age = 0 ) {
	$GLOBALS['TACK_USER_META'][1][ Tack_Embed_Admin::PENDING_META ] = array(
		'verifier' => str_repeat( 'v', 43 ),
		'state'    => hash( 'sha256', $state ),
		'iat'      => time() - $age,
	);
}
$ol_code = str_repeat( 'G', 43 );

tack_ol_pending( $ol_state );
$client                                  = new Tack_Test_Embed_Client();
$client->answers['/embed/link-exchange'] = array( 'status' => 'linked', 'account' => array( 'email' => 'rae@example.test' ) );
$admin                                   = new Tack_Test_Embed_Admin( $client );
$flag                                    = $admin->finish_link( array( 'code' => $ol_code, 'state' => $ol_state ) );
$ol_ex                                   = $client->calls[0] ?? array();
check( 'return: linked', 'linked' === $flag );
check(
	'return: the code, the stored verifier, this user id and secret go to /embed/link-exchange (P7)',
	'/integrations/woocommerce/embed/link-exchange' === ( $ol_ex[1] ?? '' ) && $ol_code === ( $ol_ex[2]['code'] ?? '' ) && str_repeat( 'v', 43 ) === ( $ol_ex[2]['codeVerifier'] ?? '' )
		&& '1' === ( $ol_ex[2]['wpUserId'] ?? '' ) && ( $GLOBALS['TACK_USER_META'][1][ Tack_Embed_Admin::SECRET_META ] ?? 'x' ) === ( $ol_ex[2]['wpUserSecret'] ?? '' )
);
check( 'return: the pending link is gone after use (P6)', ! isset( $GLOBALS['TACK_USER_META'][1][ Tack_Embed_Admin::PENDING_META ] ) );
$client = new Tack_Test_Embed_Client();
$flag   = ( new Tack_Test_Embed_Admin( $client ) )->finish_link( array( 'code' => $ol_code, 'state' => $ol_state ) );
check( 'return replayed: expired, nothing exchanged (P6)', 'expired' === $flag && 0 === count( $client->calls ) );

// A state that does not match this user's pending link: a forwarded approval, a forged
// return, or another WordPress user (P5).
tack_ol_pending( $ol_state );
$client = new Tack_Test_Embed_Client();
$flag   = ( new Tack_Test_Embed_Admin( $client ) )->finish_link( array( 'code' => $ol_code, 'state' => 'someone-elses-state-xx' ) );
check( 'return with another state: expired, nothing exchanged, the pending link burned (P5)', 'expired' === $flag && 0 === count( $client->calls ) && ! isset( $GLOBALS['TACK_USER_META'][1][ Tack_Embed_Admin::PENDING_META ] ) );
$client = new Tack_Test_Embed_Client();
$flag   = ( new Tack_Test_Embed_Admin( $client ) )->finish_link( array( 'code' => $ol_code, 'state' => $ol_state ) );
check( 'return with no pending link (a colleague’s browser): expired, nothing exchanged (P5)', 'expired' === $flag && 0 === count( $client->calls ) );

// Expired (P8), cancelled, malformed code.
tack_ol_pending( $ol_state, Tack_Embed_Admin::LINK_TTL + 5 );
$client = new Tack_Test_Embed_Client();
$flag   = ( new Tack_Test_Embed_Admin( $client ) )->finish_link( array( 'code' => $ol_code, 'state' => $ol_state ) );
check( 'return after 10 minutes: expired, nothing exchanged (P8)', 'expired' === $flag && 0 === count( $client->calls ) );
tack_ol_pending( $ol_state );
$client = new Tack_Test_Embed_Client();
$flag   = ( new Tack_Test_Embed_Admin( $client ) )->finish_link( array( 'error' => 'access_denied', 'state' => $ol_state ) );
check( 'cancelled in TackQuote: cancelled, nothing exchanged', 'cancelled' === $flag && 0 === count( $client->calls ) );
tack_ol_pending( $ol_state );
$client = new Tack_Test_Embed_Client();
$flag   = ( new Tack_Test_Embed_Admin( $client ) )->finish_link( array( 'code' => '<script>', 'state' => $ol_state ) );
check( 'a malformed code is never sent', 'expired' === $flag && 0 === count( $client->calls ) );

// What TackQuote answered (P9): only a confirmed link reads as linked.
$ol_answers = array(
	'grant'        => new WP_Error( 'tack_http_400', 'x', array( 'status' => 400, 'code' => 'WOO_LINK_GRANT_INVALID' ) ),
	'refused'      => new WP_Error( 'tack_http_403', 'x', array( 'status' => 403 ) ),
	'disconnected' => new WP_Error( 'tack_http_401', 'x', array( 'status' => 401 ) ),
	'failed'       => new WP_Error( 'tack_http_429', 'x', array( 'status' => 429 ) ),
);
foreach ( $ol_answers as $want => $answer ) {
	tack_ol_pending( $ol_state );
	$client                                  = new Tack_Test_Embed_Client();
	$client->answers['/embed/link-exchange'] = $answer;
	$flag                                    = ( new Tack_Test_Embed_Admin( $client ) )->finish_link( array( 'code' => $ol_code, 'state' => $ol_state ) );
	check( "exchange answered $want", $want === $flag );
}
tack_ol_pending( $ol_state );
$client                                  = new Tack_Test_Embed_Client();
$client->answers['/embed/link-exchange'] = array( 'status' => 'weird' );
check( 'an exchange without status linked is not a link (P9)', 'failed' === ( new Tack_Test_Embed_Admin( $client ) )->finish_link( array( 'code' => $ol_code, 'state' => $ol_state ) ) );

// The return handler: capability (P10), reads $_GET, redirects to the page with the flag.
tack_ol_pending( $ol_state );
$GLOBALS['TACK_CAPS'] = array();
$client               = new Tack_Test_Embed_Client();
$died                 = false;
try {
	( new Tack_Test_Embed_Admin( $client ) )->finish_link( array( 'code' => $ol_code, 'state' => $ol_state ) );
} catch ( Tack_Test_Wp_Die $e ) {
	$died = true;
}
check( 'return without manage_woocommerce: refused, nothing exchanged (P10)', $died && 0 === count( $client->calls ) );
$GLOBALS['TACK_CAPS']                    = array( 'manage_woocommerce' );
$_GET                                    = array( 'action' => 'tackquote_link_return', 'code' => $ol_code, 'state' => $ol_state );
$client                                  = new Tack_Test_Embed_Client();
$client->answers['/embed/link-exchange'] = array( 'status' => 'linked' );
$admin                                   = new Tack_Test_Embed_Admin( $client );
$admin->handle_link_return();
check( 'the return handler lands on WooCommerce → TackQuote with "linked"', array( Tack_Embed_Admin::page_url( 'linked' ), '' ) === ( $admin->redirects[0] ?? null ) );
$_GET = array();

// Signed out on the return: WordPress's own login first.
$GLOBALS['TACK_AUTH_REDIRECT'] = false;
( new Tack_Test_Embed_Admin( new Tack_Test_Embed_Client() ) )->handle_link_return_signed_out();
check( 'signed out on the return: auth_redirect() to wp-login.php', true === $GLOBALS['TACK_AUTH_REDIRECT'] );

// Hooks, and uninstall removes the pending meta.
$ol_src = (string) file_get_contents( TACK_QUOTES_DIR . 'includes/class-tack-embed-admin.php' );
check(
	'the start and both return hooks are registered',
	false !== strpos( $ol_src, "add_action( 'admin_post_' . self::LINK_START_ACTION" ) && false !== strpos( $ol_src, "add_action( 'admin_post_' . self::LINK_RETURN_ACTION" ) && false !== strpos( $ol_src, "add_action( 'admin_post_nopriv_' . self::LINK_RETURN_ACTION" )
);
check( 'uninstall removes the pending one-click meta', false !== strpos( (string) file_get_contents( TACK_QUOTES_DIR . 'uninstall.php' ), "'_tackquote_link_pending'" ) );
check( 'the return action is the one TackQuote builds the return address with', 'tackquote_link_return' === Tack_Embed_Admin::LINK_RETURN_ACTION );
