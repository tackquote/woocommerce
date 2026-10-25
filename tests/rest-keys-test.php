<?php
/**
 * 1.12.0: catalogue and order sync (WooCommerce REST keys through WooCommerce's own app
 * authorization), the disconnect stand-down, the deactivation notice, the update notice
 * and the GitHub-release updater of the zip build.
 *
 * Wire contract (TackQuote API, woocommerce/rest-keys module; all gated on `ping`
 * capabilities):
 *   GET  /integrations/woocommerce/ping -> {ok, tenantId, capabilities: [..., rest_keys,
 *        standing, lifecycle, plugin_release], standing: 'connected', latestPluginVersion}
 *   POST /integrations/woocommerce/rest-keys/authorize {adminPostUrl}
 *        -> {authorizeUrl: '<home>/?wc-auth-version=1&wc-auth-route=authorize&...', expiresInSeconds}
 *   GET  /integrations/woocommerce/rest-keys/status -> {status, message, wcKeyId}
 *   POST /integrations/woocommerce/plugin-lifecycle {event: deactivated|uninstalled}
 *
 * Included from tests/run.php after connect-test.php (reuses tack_cx_routes()).
 *
 * @package TackQuotes
 */

if ( ! function_exists( 'get_site_transient' ) ) {
	/** @param string $key Key. @return mixed */
	function get_site_transient( $key ) {
		return $GLOBALS['TACK_SITE_TRANSIENTS'][ $key ] ?? false;
	}
}
if ( ! function_exists( 'set_site_transient' ) ) {
	/** @param string $key Key. @param mixed $value Value. @param int $ttl TTL. @return bool */
	function set_site_transient( $key, $value, $ttl = 0 ) {
		$GLOBALS['TACK_SITE_TRANSIENTS'][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'wp_safe_remote_get' ) ) {
	/** @param string $url URL. @param array $args Args. @return array|WP_Error */
	function wp_safe_remote_get( $url, $args = array() ) {
		return wp_remote_request( $url, $args + array( 'method' => 'GET' ) );
	}
}
if ( ! function_exists( 'untrailingslashit' ) ) {
	/** @param string $value Value. @return string */
	function untrailingslashit( $value ) {
		return rtrim( (string) $value, '/\\' );
	}
}
if ( ! function_exists( 'wpautop' ) ) {
	/** @param string $text Text. @return string */
	function wpautop( $text ) {
		return '<p>' . $text . '</p>';
	}
}
if ( ! function_exists( 'self_admin_url' ) ) {
	/** @param string $path Path. @return string */
	function self_admin_url( $path = '' ) {
		return admin_url( $path );
	}
}
if ( ! function_exists( 'wp_next_scheduled' ) ) {
	/** @param string $hook Hook. @return int|false */
	function wp_next_scheduled( $hook ) {
		return $GLOBALS['TACK_CRON'][ $hook ] ?? false;
	}
}
if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	/** @param int $ts Time. @param string $hook Hook. @return bool */
	function wp_schedule_single_event( $ts, $hook ) {
		$GLOBALS['TACK_CRON'][ $hook ] = $ts;
		return true;
	}
}
if ( ! function_exists( '__return_empty_string' ) ) {
	/** @return string */
	function __return_empty_string() {
		return '';
	}
}
$GLOBALS['TACK_SITE_TRANSIENTS'] = array();
$GLOBALS['TACK_CRON']            = array();

require_once TACK_QUOTES_DIR . 'includes/class-tack-release-updates.php';

/** Records the redirect instead of ending the request. */
class Tack_Test_Rest_Keys extends Tack_Rest_Keys {
	/** @var string[] */
	public $redirects = array();
	/** @param string $url URL. */
	protected function redirect( $url ) {
		$this->redirects[] = $url;
	}
}

$rk_key      = 'tq_rest_keys_test_key_0123456789';
$rk_home     = 'https://shop.example';
$rk_callback = 'https://api.tackquote.com/v1/integrations/woocommerce/rest-keys/callback';
$rk_state    = 'wrk1.' . str_repeat( 'N', 43 ) . '.' . str_repeat( 'S', 43 );
$rk_authz    = $rk_home . '/?' . http_build_query(
	array(
		'wc-auth-version' => '1',
		'wc-auth-route'   => 'authorize',
		'app_name'        => 'TackQuote',
		'scope'           => 'read_write',
		'user_id'         => $rk_state,
		'return_url'      => $rk_home . '/wp-admin/admin-post.php?action=tackquote_rest_keys_return',
		'callback_url'    => $rk_callback,
	)
);
$rk_ping_new = array(
	200,
	array(
		'ok'                  => true,
		'capabilities'        => array( 'attachments', 'rest_keys', 'standing', 'lifecycle', 'plugin_release' ),
		'standing'            => 'connected',
		'latestPluginVersion' => '9.9.9',
	),
);
$rk_ping_old = array( 200, array( 'ok' => true, 'capabilities' => array( 'attachments' ) ) );

tack_test_set_option( 'tack_quotes_api_key', $rk_key );
tack_test_set_option( 'tack_quotes_api_url', 'https://api.tackquote.test/v1' );
$GLOBALS['TACK_HOME_URL'] = $rk_home;

// ── 1. The authorize address must be WooCommerce's endpoint on THIS site ─────────
check( 'authorize URL: the server-built address on this https site is accepted', Tack_Rest_Keys::is_own_authorize_url( $rk_authz ) );
check( 'authorize URL: another host is refused', ! Tack_Rest_Keys::is_own_authorize_url( str_replace( 'shop.example', 'evil.example', $rk_authz ) ) );
check( 'authorize URL: http is refused', ! Tack_Rest_Keys::is_own_authorize_url( str_replace( 'https://shop', 'http://shop', $rk_authz ) ) );
check( 'authorize URL: a read-only scope is refused', ! Tack_Rest_Keys::is_own_authorize_url( str_replace( 'scope=read_write', 'scope=read', $rk_authz ) ) );
check( 'authorize URL: an http callback is refused', ! Tack_Rest_Keys::is_own_authorize_url( str_replace( rawurlencode( 'https://api.' ), rawurlencode( 'http://api.' ), $rk_authz ) ) );
check( 'authorize URL: another path is refused', ! Tack_Rest_Keys::is_own_authorize_url( str_replace( $rk_home . '/?', $rk_home . '/wp-login.php?', $rk_authz ) ) );
check( 'authorize URL: a fragment is refused', ! Tack_Rest_Keys::is_own_authorize_url( $rk_authz . '#x' ) );
check( 'authorize URL: no state is refused', ! Tack_Rest_Keys::is_own_authorize_url( str_replace( 'user_id=', 'other=', $rk_authz ) ) );

// ── 2. Start: only on a server that lists rest_keys ──────────────────────────────
tack_test_reset_transients();
tack_cx_routes( array( '/ping' => $rk_ping_old ) );
$rk = new Tack_Test_Rest_Keys();
check( 'old server: the card is not offered', ! $rk->is_offered() );
$out = $rk->start();
check( 'old server: start refuses with a notice and asks nothing', isset( $out['type'] ) && 'error' === $out['type'] && 0 === count( tack_cx_calls( 'rest-keys' ) ) );

tack_test_reset_transients();
tack_cx_routes(
	array(
		'/ping'                => $rk_ping_new,
		'/rest-keys/authorize' => array( 201, array( 'authorizeUrl' => $rk_authz, 'expiresInSeconds' => 1800 ) ),
	)
);
$rk  = new Tack_Test_Rest_Keys();
$out = $rk->start();
$req = tack_cx_calls( '/rest-keys/authorize' );
check( 'new server: start redirects to WooCommerce on this site', ( $out['redirect'] ?? '' ) === $rk_authz, wp_json_encode( $out ) );
check( 'start sends this site\'s own admin-post.php address', 1 === count( $req ) && false !== strpos( (string) ( $req[0]['args']['body'] ?? '' ), 'admin-post.php' ) );

tack_test_reset_transients();
tack_cx_routes(
	array(
		'/ping'                => $rk_ping_new,
		'/rest-keys/authorize' => array( 201, array( 'authorizeUrl' => str_replace( 'shop.example', 'evil.example', $rk_authz ) ) ),
	)
);
$out = ( new Tack_Test_Rest_Keys() )->start();
check( 'a foreign address from the server is refused, never opened', ! isset( $out['redirect'] ) && 'error' === ( $out['type'] ?? '' ) );

$GLOBALS['TACK_HOME_URL'] = 'http://shop.example';
tack_test_reset_transients();
tack_cx_routes( array( '/ping' => $rk_ping_new ) );
$out = ( new Tack_Test_Rest_Keys() )->start();
check( 'an http site is refused before TackQuote is asked', 'error' === ( $out['type'] ?? '' ) && 0 === count( tack_cx_calls( 'rest-keys' ) ) );
$GLOBALS['TACK_HOME_URL'] = $rk_home;

// ── 3. Status and return ─────────────────────────────────────────────────────────
tack_test_reset_transients();
tack_cx_routes(
	array(
		'/ping'              => $rk_ping_new,
		'/rest-keys/status'  => array( 200, array( 'status' => 'connected', 'message' => null, 'wcKeyId' => 42 ) ),
	)
);
$rk = new Tack_Test_Rest_Keys();
$st = $rk->status( true );
check( 'status: connected is read', 'connected' === $st['status'] );
check( 'status: WooCommerce\'s key id is kept for uninstall', 42 === get_option( Tack_Rest_Keys::OPTION_WC_KEY_ID ) );
$n = $rk->finish( true );
check( 'return approved + connected: success notice', 'success' === $n['type'] );
$n = $rk->finish( false );
check( 'return denied: a warning that the plugin keeps working', 'warning' === $n['type'] && false !== strpos( $n['message'], 'keep working' ) );

tack_test_reset_transients();
tack_cx_routes( array( '/rest-keys/status' => 'TRANSPORT' ) );
$st = ( new Tack_Test_Rest_Keys() )->status( true );
check( 'status: TackQuote unreachable reads as unknown, never as connected', 'unknown' === $st['status'] );

// ── 4. Stand-down: only TackQuote's key refusal (api_key_invalid), from the plugin's own
//    ping (admin test / background refresh), confirmed by a second ping ─────────────────
$rk_refused  = array( 401, array( 'statusCode' => 401, 'code' => 'api_key_invalid', 'message' => 'Invalid API key' ) );
$rk_signin   = array( 401, array( 'statusCode' => 401, 'code' => 'UNAUTHORIZED', 'message' => 'Sign in to your account to attach files' ) );
/**
 * Answer the ping with each listed answer in turn (the last one repeats).
 *
 * @param array $answers [status, body] pairs.
 * @param array $others  Other routes: fragment => [status, body].
 */
function tack_rk_ping_sequence( array $answers, array $others = array() ) {
	$GLOBALS['TACK_HTTP_REQUESTS'] = array();
	$GLOBALS['TACK_RK_PINGS']      = 0;
	$GLOBALS['TACK_HTTP_RESPONDER'] = function ( $url ) use ( $answers, $others ) {
		if ( false !== strpos( $url, '/ping' ) ) {
			$n      = $GLOBALS['TACK_RK_PINGS']++;
			$answer = $answers[ min( $n, count( $answers ) - 1 ) ];
		} else {
			$answer = array( 404, array( 'statusCode' => 404 ) );
			foreach ( $others as $fragment => $a ) {
				if ( false !== strpos( $url, $fragment ) ) {
					$answer = $a;
				}
			}
		}
		return array(
			'response' => array( 'code' => $answer[0] ),
			'body'     => is_string( $answer[1] ) ? $answer[1] : wp_json_encode( $answer[1] ),
			'headers'  => array(),
		);
	};
}

tack_test_set_option( Tack_Standing::OPTION, array() );
tack_test_reset_transients();
tack_rk_ping_sequence( array( $rk_ping_new ) );
( new Tack_Api_Client() )->server_capabilities( true );
check( 'a passing ping on a standing server: active', ! Tack_Standing::is_stood_down() );
$rec = get_option( Tack_Standing::OPTION );
check( 'the standing server is remembered for this key', ! empty( $rec['supported'] ) );

// A shopper's page view (no observe) never stands down, even on the key refusal.
tack_rk_ping_sequence( array( $rk_refused ) );
( new Tack_Api_Client() )->server_capabilities( true );
check( 'a page-view ping refused with api_key_invalid does NOT stand down', ! Tack_Standing::is_stood_down() );

// A storefront 401 ("sign in to attach files") never stands down, whatever route it came from.
tack_rk_ping_sequence( array( $rk_ping_new ), array( '/storefront/v1/' => $rk_signin ) );
( new Tack_Api_Client() )->request( 'POST', '/storefront/v1/wholesale-upload?form=x', 'bytes' );
Tack_Standing::refresh();
check( 'a storefront 401 (guest wholesale upload) does NOT stand down', ! Tack_Standing::is_stood_down() );

foreach (
	array(
		'a network error'                       => 'TRANSPORT',
		'a 503'                                 => array( 503, array( 'statusCode' => 503, 'message' => 'down' ) ),
		'a proxy\'s 401 (not JSON)'             => array( 401, '<html>proxy says no</html>' ),
		'a 401 without the key-refusal code'    => $rk_signin,
		'a 403 (a missing scope)'               => array( 403, array( 'statusCode' => 403, 'code' => 'insufficient_scope', 'message' => 'scope' ) ),
	) as $label => $answer
) {
	if ( 'TRANSPORT' === $answer ) {
		$GLOBALS['TACK_HTTP_RESPONDER'] = function () {
			return new WP_Error( 'http_request_failed', 'cURL error 28: timed out' );
		};
	} else {
		tack_rk_ping_sequence( array( $answer ) );
	}
	Tack_Standing::refresh();
	check( "background refresh: $label never hides the storefront", ! Tack_Standing::is_stood_down() );
}

// The key refusal on the background refresh, NOT confirmed by the second ping.
tack_rk_ping_sequence( array( $rk_refused, $rk_ping_new ) );
Tack_Standing::refresh();
check( 'a refusal the confirming ping does not repeat: no stand-down', ! Tack_Standing::is_stood_down() );

// Confirmed: two refusals.
tack_test_set_option( Tack_Standing::OPTION, array() );
tack_rk_ping_sequence( array( $rk_ping_new ) );
( new Tack_Api_Client() )->server_capabilities( true );
tack_rk_ping_sequence( array( $rk_refused ) );
Tack_Standing::refresh();
check( 'background refresh: api_key_invalid, confirmed by a fresh ping: stood down', Tack_Standing::is_stood_down() );
check( 'it took exactly two pings (the refusal and its confirmation)', 2 === $GLOBALS['TACK_RK_PINGS'] );

tack_test_set_option( 'tack_quotes_api_key', $rk_key . 'NEW' );
check( 'saving a new key ends the stand-down at once', ! Tack_Standing::is_stood_down() );
tack_test_set_option( 'tack_quotes_api_key', $rk_key );

// The administrator's own connection test is a valid observer too.
tack_test_set_option( Tack_Standing::OPTION, array() );
tack_rk_ping_sequence( array( $rk_ping_new ) );
( new Tack_Api_Client() )->server_capabilities( true );
tack_rk_ping_sequence( array( $rk_refused ) );
( new Tack_Api_Client() )->test_connection();
check( 'admin connection test: api_key_invalid confirmed: stood down', Tack_Standing::is_stood_down() );

// An older server (no `standing` capability) never causes it.
tack_test_set_option( Tack_Standing::OPTION, array() );
tack_test_reset_transients();
tack_rk_ping_sequence( array( $rk_ping_old ) );
( new Tack_Api_Client() )->server_capabilities( true );
tack_rk_ping_sequence( array( $rk_refused ) );
Tack_Standing::refresh();
check( 'a server without `standing`: no stand-down', ! Tack_Standing::is_stood_down() );

// Make it stood down again for the storefront checks below.
tack_rk_ping_sequence( array( $rk_ping_new ) );
( new Tack_Api_Client() )->server_capabilities( true );
tack_rk_ping_sequence( array( $rk_refused ) );
Tack_Standing::refresh();
check( 'stood down again for the next checks', Tack_Standing::is_stood_down() );

// Stood down: the storefront hooks are not attached, the shortcodes render nothing.
$GLOBALS['TACK_HOOKS']      = array();
$GLOBALS['TACK_SHORTCODES'] = array();
$GLOBALS['TACK_FILTERS']    = array();
Tack_Quotes::hide_storefront();
check( 'stood down: the quote page shortcode renders nothing', isset( $GLOBALS['TACK_SHORTCODES']['tackquote_quote_page'] ) && '' === call_user_func( $GLOBALS['TACK_SHORTCODES']['tackquote_quote_page'] ) );
$pre = $GLOBALS['TACK_FILTERS']['pre_do_shortcode_tag'][0] ?? null;
check( 'stood down: the application form shortcodes render nothing', is_callable( $pre ) && '' === $pre( false, 'tackquote_wholesale_application' ) && '' === $pre( false, 'tackquote_net_terms_application' ) && false === $pre( false, 'gallery' ) );
$menu = $GLOBALS['TACK_FILTERS']['woocommerce_account_menu_items'][0] ?? null;
$left = is_callable( $menu ) ? $menu( array( 'dashboard' => 'D', Tack_Storefront_Forms::ENDPOINT_WHOLESALE => 'W', Tack_Storefront_Forms::ENDPOINT_NET_TERMS => 'N' ) ) : array();
check( 'stood down: the account tabs leave the menu', array( 'dashboard' => 'D' ) === $left );

// Tack_Quotes::init while stood down attaches no product button and no drawer.
$GLOBALS['TACK_HOOKS'] = array();
Tack_Quotes::instance()->init();
$hooked = array_column( $GLOBALS['TACK_HOOKS'], 'hook' );
check( 'stood down: no product-page button is hooked', ! in_array( 'woocommerce_after_add_to_cart_button', $hooked, true ) );
check( 'stood down: no quote list drawer is hooked', ! in_array( 'wp_footer', $hooked, true ) );
check( 'stood down: the background refresh stays registered so the store can recover', in_array( Tack_Standing::REFRESH_HOOK, $hooked, true ) );

tack_test_set_option( Tack_Standing::OPTION, array() );
$GLOBALS['TACK_HOOKS'] = array();
Tack_Quotes::instance()->init();
$hooked = array_column( $GLOBALS['TACK_HOOKS'], 'hook' );
check( 'not stood down: the product-page button is hooked as before', in_array( 'woocommerce_after_add_to_cart_button', $hooked, true ) );

// ── 5. Deactivation notice ───────────────────────────────────────────────────────
tack_test_reset_transients();
tack_cx_routes( array( '/ping' => $rk_ping_old ) );
check( 'deactivation on an old server: nothing is sent', false === Tack_Lifecycle::notify_deactivated() && 0 === count( tack_cx_calls( 'plugin-lifecycle' ) ) );
tack_test_reset_transients();
tack_cx_routes(
	array(
		'/ping'             => $rk_ping_new,
		'/plugin-lifecycle' => array( 200, array( 'stoodDown' => true ) ),
	)
);
check( 'deactivation on a lifecycle server: sent', true === Tack_Lifecycle::notify_deactivated() );
$pings = tack_cx_calls( '/ping' );
check( 'cold capability cache: ONE ping, with the short 3 s limit', 1 === count( $pings ) && 3 === (int) ( $pings[0]['args']['timeout'] ?? 0 ) );
$sent = tack_cx_calls( 'plugin-lifecycle' );
check( 'the event is `deactivated`', 1 === count( $sent ) && false !== strpos( (string) $sent[0]['args']['body'], '"deactivated"' ) );

// ── 6. Update notice ─────────────────────────────────────────────────────────────
check( 'is_newer compares numerically', Tack_Update_Notice::is_newer( '1.10.0', '1.9.9' ) && ! Tack_Update_Notice::is_newer( '1.9.9', '1.10.0' ) && ! Tack_Update_Notice::is_newer( 'v2', '1.0.0' ) );
tack_test_reset_transients();
tack_cx_routes( array( '/ping' => $rk_ping_new ) );
$GLOBALS['TACK_SITE_TRANSIENTS']['update_plugins'] = (object) array( 'response' => array() );
$avail = Tack_Update_Notice::available( null, '1.11.1' );
check( 'the newest release TackQuote names is announced', is_array( $avail ) && '9.9.9' === $avail['version'] && ! $avail['wordpress_knows'] );
$GLOBALS['TACK_SITE_TRANSIENTS']['update_plugins'] = (object) array(
	'response' => array( plugin_basename( TACK_QUOTES_FILE ) => (object) array( 'new_version' => '9.9.9' ) ),
);
$avail = Tack_Update_Notice::available( null, '1.11.1' );
check( 'when WordPress knows the update, the notice links to Dashboard > Updates', is_array( $avail ) && $avail['wordpress_knows'] );
check( 'nothing newer: no notice', null === Tack_Update_Notice::available( null, '9.9.9' ) );

// ── 7. GitHub-release updater (zip build) ────────────────────────────────────────
$release = array(
	'tag_name'   => 'v1.12.0',
	'html_url'   => 'https://github.com/tackquote/woocommerce/releases/tag/v1.12.0',
	'draft'      => false,
	'prerelease' => false,
	'body'       => 'Notes',
	'assets'     => array(
		array(
			'name'                 => 'tackquote.zip',
			'browser_download_url' => 'https://github.com/tackquote/woocommerce/releases/download/v1.12.0/tackquote.zip',
		),
	),
);
$parsed = Tack_Release_Updates::parse( $release );
check( 'release: version and package from the tag and the tackquote.zip asset', '1.12.0' === ( $parsed['version'] ?? '' ) && 'https://github.com/tackquote/woocommerce/releases/download/v1.12.0/tackquote.zip' === ( $parsed['package'] ?? '' ) );
$bad                                      = $release;
$bad['assets'][0]['browser_download_url'] = 'https://evil.example/tackquote.zip';
check( 'release: a package from anywhere else is refused', null === Tack_Release_Updates::parse( $bad ) );
$bad              = $release;
$bad['prerelease'] = true;
check( 'release: a pre-release is refused', null === Tack_Release_Updates::parse( $bad ) );
$bad             = $release;
$bad['tag_name'] = 'latest';
check( 'release: a tag that is not vX.Y.Z is refused', null === Tack_Release_Updates::parse( $bad ) );

$GLOBALS['TACK_SITE_TRANSIENTS'][ Tack_Release_Updates::CACHE ] = $parsed + array( 'until' => time() + 3600 );
$ours  = array( 'UpdateURI' => Tack_Release_Updates::UPDATE_URI, 'Version' => '1.11.1' );
$file  = plugin_basename( TACK_QUOTES_FILE );
$upd   = Tack_Release_Updates::filter_update( false, $ours, $file );
check( 'update_plugins_github.com answers for this plugin with the GitHub package', is_array( $upd ) && '1.12.0' === $upd['version'] && $parsed['package'] === $upd['package'] );
check( 'another plugin with a github.com Update URI passes through untouched', false === Tack_Release_Updates::filter_update( false, array( 'UpdateURI' => 'https://github.com/someone/else' ), 'else/else.php' ) );
check( 'this plugin WITHOUT the Update URI (the WordPress.org build) is not answered', false === Tack_Release_Updates::filter_update( false, array( 'UpdateURI' => '' ), $file ) );
$info = Tack_Release_Updates::filter_details( false, 'plugin_information', (object) array( 'slug' => 'tackquote' ) );
check( 'plugins_api answers View details for this plugin', is_object( $info ) && '1.12.0' === $info->version );
check( 'plugins_api leaves other plugins alone', false === Tack_Release_Updates::filter_details( false, 'plugin_information', (object) array( 'slug' => 'akismet' ) ) );

// ── 8. Right after Connect: continue to WooCommerce, unless TackQuote holds keys ─
tack_test_set_option( 'tack_quotes_api_key', $rk_key );
tack_test_reset_transients();
tack_cx_routes(
	array(
		'/ping'                => $rk_ping_new,
		'/rest-keys/status'    => array( 200, array( 'status' => 'none', 'message' => null, 'wcKeyId' => null ) ),
		'/rest-keys/authorize' => array( 201, array( 'authorizeUrl' => $rk_authz ) ),
	)
);
check( 'after Connect, a store without keys continues to WooCommerce\'s approval', $rk_authz === ( new Tack_Test_Rest_Keys() )->url_after_connect() );
tack_test_reset_transients();
tack_cx_routes(
	array(
		'/ping'             => $rk_ping_new,
		'/rest-keys/status' => array( 200, array( 'status' => 'connected', 'message' => null, 'wcKeyId' => 7 ) ),
	)
);
check( 'after Connect, a store whose keys TackQuote already holds stays on the Connection tab', '' === ( new Tack_Test_Rest_Keys() )->url_after_connect() && 0 === count( tack_cx_calls( '/rest-keys/authorize' ) ) );
tack_test_reset_transients();
tack_cx_routes( array( '/ping' => $rk_ping_old ) );
check( 'after Connect, an older server: no detour', '' === ( new Tack_Test_Rest_Keys() )->url_after_connect() );

// ── 9. Uninstall deletes the WooCommerce key only after TackQuote released it ───
$tack_rk_uninstall = (string) file_get_contents( TACK_QUOTES_DIR . 'uninstall.php' );
check( 'uninstall: the WooCommerce key is deleted only inside the released-branch', 1 === preg_match( '/if \( tack_quotes_notify_uninstalled\(\) \) \{\s*tack_quotes_delete_wc_rest_key\(\);/', $tack_rk_uninstall ) );
check( 'uninstall: released means 2xx AND restKeysReleased true', false !== strpos( $tack_rk_uninstall, "true === ( \$body['restKeysReleased'] ?? false )" ) );
