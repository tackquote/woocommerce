<?php
/**
 * 1.16.0: the optional "Why are you deactivating?" question (Tack_Lifecycle).
 *
 * Wire contract (tack `WooPluginLifecycleDto`, POST /integrations/woocommerce/plugin-lifecycle):
 *   { event: 'deactivated'|'uninstalled', reason?: string (<=40), details?: string (<=4000),
 *     wantsHelp?: JSON boolean (a string is refused with 400), pluginVersion?: string (<=40) }
 * The extra fields only to a server whose ping lists `lifecycle_feedback`: the API refuses
 * unknown fields (forbidNonWhitelisted), so an older server would refuse the event too.
 *
 * Required by tests/run.php after every other suite; uses its check() and the tack_cx_*
 * helpers from connect-test.php.
 *
 * @package TackQuotes
 */

if ( ! function_exists( 'is_network_admin' ) ) {
	/** @return bool */
	function is_network_admin() {
		return ! empty( $GLOBALS['TACK_NETWORK_ADMIN'] );
	}
}

$lf_key       = 'tq_lifecycle_feedback_key_0123456789';
$lf_ping_fb   = array( 200, array( 'ok' => true, 'capabilities' => array( 'lifecycle', 'lifecycle_feedback' ) ) );
$lf_ping_life = array( 200, array( 'ok' => true, 'capabilities' => array( 'lifecycle' ) ) );
$lf_ok        = array( 200, array( 'stoodDown' => true, 'restKeysReleased' => false ) );
$lf_slot      = Tack_Lifecycle::FEEDBACK_TRANSIENT . '7';

/**
 * A clean administrator (user 7) with a saved key and the given ping answer cached.
 *
 * @param array|null $ping Ping answer to cache, or null for a cold cache.
 */
function tack_lf_reset( $ping ) {
	global $lf_key;
	tack_test_reset_transients();
	$_GET                            = array();
	$GLOBALS['TACK_CAPS']            = array( 'manage_options', 'manage_woocommerce', 'deactivate_plugin' );
	$GLOBALS['TACK_LOGGED_IN']       = true;
	$GLOBALS['TACK_CURRENT_USER_ID'] = 7;
	$GLOBALS['TACK_NETWORK_ADMIN']   = false;
	$GLOBALS['TACK_NONCE_VALID']     = true;
	tack_test_set_option( 'tack_quotes_api_key', $lf_key );
	tack_test_set_option( 'tack_quotes_api_url', 'https://api.tackquote.test/v1' );
	if ( null !== $ping ) {
		tack_cx_routes( array( '/ping' => $ping ) );
		( new Tack_Api_Client() )->server_capabilities( true );
	}
}

/**
 * The JSON bodies sent to plugin-lifecycle, decoded.
 *
 * @return array[]
 */
function tack_lf_bodies() {
	$out = array();
	foreach ( tack_cx_calls( 'plugin-lifecycle' ) as $call ) {
		$out[] = json_decode( (string) ( $call['args']['body'] ?? '' ), true );
	}
	return $out;
}

/**
 * A valid form post.
 *
 * @param array $fields Extra fields.
 * @return array
 */
function tack_lf_post( array $fields ) {
	return array_merge(
		array(
			'action'   => Tack_Lifecycle::FEEDBACK_ACTION,
			'_wpnonce' => wp_create_nonce( Tack_Lifecycle::FEEDBACK_ACTION ),
		),
		$fields
	);
}

/**
 * Send the form and follow the redirect as the browser would: the deactivation request's
 * query string becomes $_GET. Answers the redirect URL.
 *
 * @param array $fields Form fields.
 * @return string
 */
function tack_lf_send_and_follow( array $fields ) {
	$url = Tack_Lifecycle::process_feedback_request( tack_lf_post( $fields ) );
	$_GET = array();
	parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $_GET );
	return $url;
}

// ── 1. The reasons are exactly TackQuote's `UninstallReason` list ────────────────
check(
	'lifecycle feedback: the nine reasons are exactly the server\'s list',
	array( 'missing_feature', 'too_complex', 'bug_or_error', 'price', 'switched_app', 'temporary', 'store_closing', 'not_needed', 'other' ) === array_keys( Tack_Lifecycle::reasons() )
);
check( 'lifecycle feedback: an answer waits two minutes at most', 120 === Tack_Lifecycle::FEEDBACK_TTL );

// ── 2. Cleaning: only what TackQuote accepts, in the types it accepts ─────────────
$lf_clean = Tack_Lifecycle::clean_feedback( array( 'reason' => 'price', 'details' => '<b>Too</b> dear', 'wants_help' => '1' ) );
check( 'clean: a known reason is kept', 'price' === ( $lf_clean['reason'] ?? null ) );
check( 'clean: markup is stripped from the note', 'Too dear' === ( $lf_clean['details'] ?? null ) );
check( 'clean: a ticked help box is the boolean true, never a string', true === ( $lf_clean['wantsHelp'] ?? null ) );
check( 'clean: an unknown reason is left out', ! isset( Tack_Lifecycle::clean_feedback( array( 'reason' => 'hacked' ) )['reason'] ) );
check( 'clean: the string "false" is not a request for help', array() === Tack_Lifecycle::clean_feedback( array( 'wants_help' => 'false' ) ) );
check( 'clean: an unticked box sends no wantsHelp at all', ! array_key_exists( 'wantsHelp', Tack_Lifecycle::clean_feedback( array( 'reason' => 'other' ) ) ) );
check( 'clean: nothing chosen is an empty answer', array() === Tack_Lifecycle::clean_feedback( array( 'reason' => '', 'details' => '   ' ) ) );
$lf_long = Tack_Lifecycle::clean_feedback( array( 'details' => str_repeat( 'é', 5000 ) ) );
check( 'clean: the note is cut to 4000 characters (not bytes)', 4000 === mb_strlen( (string) ( $lf_long['details'] ?? '' ), 'UTF-8' ) );
check( 'clean: a non-string field is ignored', array() === Tack_Lifecycle::clean_feedback( array( 'reason' => array( 'price' ), 'details' => array( 'x' ) ) ) );

// ── 3. The admin-post handler: capability, nonce, then WordPress's own deactivation ─
tack_lf_reset( $lf_ping_fb );
$GLOBALS['TACK_CAPS'] = array( 'manage_woocommerce', 'activate_plugins' );
check( 'handler: without deactivate_plugin (for this plugin) nothing is kept and no address is given', '' === Tack_Lifecycle::process_feedback_request( tack_lf_post( array( 'reason' => 'price' ) ) ) && false === get_transient( $lf_slot ) );
tack_lf_reset( $lf_ping_fb );
check( 'handler: a wrong nonce is refused and nothing is kept', '' === Tack_Lifecycle::process_feedback_request( array( '_wpnonce' => 'forged', 'reason' => 'price' ) ) && false === get_transient( $lf_slot ) );
check( 'handler: a missing nonce is refused', '' === Tack_Lifecycle::process_feedback_request( array( 'reason' => 'price' ) ) );
$lf_url    = Tack_Lifecycle::process_feedback_request( tack_lf_post( array( 'reason' => 'price', 'wants_help' => '1' ) ) );
$lf_stored = get_transient( $lf_slot );
$lf_hop    = is_array( $lf_stored ) ? (string) ( $lf_stored['hop'] ?? '' ) : '';
check(
	'handler: the answer goes to WordPress\'s deactivation of THIS plugin, with its own nonce',
	0 === strpos( $lf_url, 'https://shop.example/wp-admin/plugins.php?action=deactivate&plugin=tackquote%2Ftackquote.php&' )
		&& false !== strpos( $lf_url, '&_wpnonce=' . wp_create_nonce( 'deactivate-plugin_tackquote/tackquote.php' ) ),
	$lf_url
);
check( 'handler: the answer is kept for this user only, with a token', is_array( $lf_stored ) && array( 'reason' => 'price', 'wantsHelp' => true ) === ( $lf_stored['answer'] ?? null ) && strlen( $lf_hop ) >= 20 );
check( 'handler: the redirect carries that token', false !== strpos( $lf_url, '&' . Tack_Lifecycle::HOP_PARAM . '=' . $lf_hop . '&' ) );
check( 'handler: sending the answer made no request to TackQuote by itself', 0 === count( tack_cx_calls( 'plugin-lifecycle' ) ) );
tack_lf_reset( $lf_ping_fb );
$lf_url = Tack_Lifecycle::process_feedback_request( tack_lf_post( array() ) );
check( 'handler: an empty form keeps nothing and the redirect carries no token', false === get_transient( $lf_slot ) && false === strpos( $lf_url, Tack_Lifecycle::HOP_PARAM ) );

// The list view the merchant was on comes back, allowlisted.
tack_lf_reset( $lf_ping_fb );
$lf_url = Tack_Lifecycle::process_feedback_request( tack_lf_post( array( 'plugin_status' => 'active', 'paged' => '2', 's' => 'quote list' ) ) );
check( 'handler: plugin_status, paged and s come back on the link, encoded', false !== strpos( $lf_url, '&plugin_status=active&paged=2&s=quote%20list&' ), $lf_url );
$lf_url = Tack_Lifecycle::process_feedback_request( tack_lf_post( array( 'plugin_status' => 'evil"><x', 'paged' => '0', 's' => array( 'x' ), 'redirect_to' => 'https://evil.example' ) ) );
check( 'handler: an unknown view, a page 0, a non-string search and any other field are dropped', false === strpos( $lf_url, 'plugin_status' ) && false === strpos( $lf_url, 'paged' ) && false === strpos( $lf_url, '&s=' ) && false === strpos( $lf_url, 'evil' ), $lf_url );
check( 'view: a page number with letters is dropped', array() === Tack_Lifecycle::view_args( array( 'paged' => '2abc' ) ) );
check( 'view: a search longer than 100 characters is cut', 100 === mb_strlen( Tack_Lifecycle::view_args( array( 's' => str_repeat( 'a', 300 ) ) )['s'] ?? '', 'UTF-8' ) );

// ── 4. The deactivation carries the answer, once, only on its own hop ───────────
tack_lf_reset( $lf_ping_fb );
tack_lf_send_and_follow( array( 'reason' => 'missing_feature', 'details' => 'Needs X', 'wants_help' => '1' ) );
tack_cx_routes( array( '/plugin-lifecycle' => $lf_ok ) );
check( 'deactivation with an answer: TackQuote accepted it', true === Tack_Lifecycle::notify_deactivated() );
$lf_sent = tack_lf_bodies();
check(
	'deactivation with an answer: one call, event + reason + details + wantsHelp true + pluginVersion',
	1 === count( $lf_sent ) && array(
		'event'         => 'deactivated',
		'pluginVersion' => TACK_QUOTES_VERSION,
		'reason'        => 'missing_feature',
		'details'       => 'Needs X',
		'wantsHelp'     => true,
	) === $lf_sent[0],
	wp_json_encode( $lf_sent )
);
check( 'deactivation with an answer: wantsHelp is a JSON boolean on the wire', 1 === count( tack_cx_calls( 'plugin-lifecycle' ) ) && false !== strpos( (string) tack_cx_calls( 'plugin-lifecycle' )[0]['args']['body'], '"wantsHelp":true' ) );
check( 'deactivation with an answer: the answer is read once (deleted)', false === get_transient( $lf_slot ) );

// S1: Send without the hop, then Skip (or a bulk deactivation): Skip sends nothing.
tack_lf_reset( $lf_ping_fb );
Tack_Lifecycle::process_feedback_request( tack_lf_post( array( 'reason' => 'price', 'details' => 'Too dear' ) ) );
$_GET = array(
	'action'   => 'deactivate',
	'plugin'   => 'tackquote/tackquote.php',
	'_wpnonce' => 'core',
);
tack_cx_routes( array( '/plugin-lifecycle' => $lf_ok ) );
Tack_Lifecycle::notify_deactivated();
$lf_sent = tack_lf_bodies();
check( 'Skip after an unsent answer: only the event goes, the answer does not ride along', 1 === count( $lf_sent ) && array( 'event' => 'deactivated', 'pluginVersion' => TACK_QUOTES_VERSION ) === $lf_sent[0], wp_json_encode( $lf_sent ) );
check( 'Skip after an unsent answer: the answer is deleted', false === get_transient( $lf_slot ) );

// A token that is not the stored one.
tack_lf_reset( $lf_ping_fb );
tack_lf_send_and_follow( array( 'reason' => 'price' ) );
$_GET[ Tack_Lifecycle::HOP_PARAM ] = 'NotTheStoredToken000000000000000';
tack_cx_routes( array( '/plugin-lifecycle' => $lf_ok ) );
Tack_Lifecycle::notify_deactivated();
$lf_sent = tack_lf_bodies();
check( 'a different token: the answer is not sent', 1 === count( $lf_sent ) && ! isset( $lf_sent[0]['reason'] ) );

// WP-CLI (`wp plugin deactivate`): no user, no token. The event still goes.
tack_lf_reset( $lf_ping_fb );
Tack_Lifecycle::process_feedback_request( tack_lf_post( array( 'reason' => 'price' ) ) );
$GLOBALS['TACK_CURRENT_USER_ID'] = 0;
$_GET                            = array();
tack_cx_routes( array( '/plugin-lifecycle' => $lf_ok ) );
Tack_Lifecycle::notify_deactivated();
$lf_sent = tack_lf_bodies();
check( 'WP-CLI: the event goes without an answer', 1 === count( $lf_sent ) && ! isset( $lf_sent[0]['reason'] ) );
check( 'WP-CLI: the administrator\'s unsent answer is left to expire, never sent', is_array( get_transient( $lf_slot ) ) );

// "Skip and deactivate" with nothing stored: only the event (and the version on a new server).
tack_lf_reset( $lf_ping_fb );
tack_cx_routes( array( '/plugin-lifecycle' => $lf_ok ) );
Tack_Lifecycle::notify_deactivated();
$lf_sent = tack_lf_bodies();
check( 'skip: only the event and the plugin version are sent', 1 === count( $lf_sent ) && array( 'event' => 'deactivated', 'pluginVersion' => TACK_QUOTES_VERSION ) === $lf_sent[0], wp_json_encode( $lf_sent ) );

// A server with `lifecycle` but not `lifecycle_feedback`: the bare 1.12 body, answer dropped.
tack_lf_reset( $lf_ping_life );
tack_lf_send_and_follow( array( 'reason' => 'price' ) );
tack_cx_routes( array( '/plugin-lifecycle' => $lf_ok ) );
Tack_Lifecycle::notify_deactivated();
$lf_sent = tack_lf_bodies();
check( 'older lifecycle server: exactly {event} (no field it would refuse)', 1 === count( $lf_sent ) && array( 'event' => 'deactivated' ) === $lf_sent[0], wp_json_encode( $lf_sent ) );
check( 'older lifecycle server: the unsent answer is still deleted', false === get_transient( $lf_slot ) );

// Another user's answer (and token) is never sent with this user's deactivation.
tack_lf_reset( $lf_ping_fb );
$GLOBALS['TACK_CURRENT_USER_ID'] = 8;
tack_lf_send_and_follow( array( 'reason' => 'price' ) );
$GLOBALS['TACK_CURRENT_USER_ID'] = 7;
tack_cx_routes( array( '/plugin-lifecycle' => $lf_ok ) );
Tack_Lifecycle::notify_deactivated();
$lf_sent = tack_lf_bodies();
check( 'another user\'s answer is not sent, even with its token', 1 === count( $lf_sent ) && ! isset( $lf_sent[0]['reason'] ) );

// ── 5. Fail closed: never block, never pretend ───────────────────────────────────
tack_lf_reset( $lf_ping_fb );
tack_lf_send_and_follow( array( 'reason' => 'price' ) );
tack_cx_routes( array( '/plugin-lifecycle' => 'TRANSPORT' ) );
check( 'transport failure: reported as not sent, no exception', false === Tack_Lifecycle::notify_deactivated() );
check( 'transport failure: one attempt only (the 400 fallback is for 400 only)', 1 === count( tack_cx_calls( 'plugin-lifecycle' ) ) );
check( 'transport failure: the 3-second limit holds', 3 === (int) ( tack_cx_calls( 'plugin-lifecycle' )[0]['args']['timeout'] ?? 0 ) );

tack_lf_reset( $lf_ping_fb );
tack_lf_send_and_follow( array( 'reason' => 'price' ) );
tack_cx_routes( array( '/plugin-lifecycle' => array( 503, array( 'statusCode' => 503 ) ) ) );
check( 'a 503 answer: reported as not sent', false === Tack_Lifecycle::notify_deactivated() );
check( 'a 503 answer: exactly one call', 1 === count( tack_cx_calls( 'plugin-lifecycle' ) ) );

tack_lf_reset( $lf_ping_fb );
tack_lf_send_and_follow( array( 'reason' => 'price' ) );
tack_cx_routes( array( '/plugin-lifecycle' => array( 429, array( 'statusCode' => 429, 'message' => 'ThrottlerException: Too Many Requests' ) ) ) );
check( 'a 429 answer: reported as not sent', false === Tack_Lifecycle::notify_deactivated() );
check( 'a 429 answer: exactly one call', 1 === count( tack_cx_calls( 'plugin-lifecycle' ) ) );

// A 400 with the extra fields: the bare event once more, so the connection still pauses.
tack_lf_reset( $lf_ping_fb );
tack_lf_send_and_follow( array( 'reason' => 'price', 'wants_help' => '1' ) );
$GLOBALS['TACK_HTTP_REQUESTS']  = array();
$lf_calls                       = 0;
$GLOBALS['TACK_HTTP_RESPONDER'] = function ( $url, $args ) use ( &$lf_calls ) {
	++$lf_calls;
	$code = 1 === $lf_calls ? 400 : 200;
	return array(
		'response' => array( 'code' => $code ),
		'body'     => wp_json_encode( 400 === $code ? array( 'statusCode' => 400, 'message' => array( 'property x should not exist' ) ) : array( 'stoodDown' => true ) ),
		'headers'  => array(),
	);
};
$lf_result = Tack_Lifecycle::notify_deactivated();
$lf_sent   = tack_lf_bodies();
$lf_calls2 = tack_cx_calls( 'plugin-lifecycle' );
check( '400 on the answer: the bare event is sent once more', 2 === count( $lf_sent ) && isset( $lf_sent[0]['reason'] ) && array( 'event' => 'deactivated' ) === $lf_sent[1], wp_json_encode( $lf_sent ) );
check( '400 on the answer: the retry has the same 3-second limit', 2 === count( $lf_calls2 ) && 3 === (int) ( $lf_calls2[1]['args']['timeout'] ?? 0 ) );
check( '400 on the answer: the result is the second call\'s (the event got through)', true === $lf_result );

// No key: nothing is sent, and a stale answer is still dropped.
tack_lf_reset( $lf_ping_fb );
tack_lf_send_and_follow( array( 'reason' => 'price' ) );
tack_test_set_option( 'tack_quotes_api_key', '' );
tack_cx_routes( array( '/plugin-lifecycle' => $lf_ok ) );
check( 'no key: nothing is sent', false === Tack_Lifecycle::notify_deactivated() && 0 === count( tack_cx_calls( 'plugin-lifecycle' ) ) );
check( 'no key: the answer is dropped anyway', false === get_transient( $lf_slot ) );

// ── 6. When the dialog is offered ────────────────────────────────────────────────
tack_lf_reset( $lf_ping_fb );
check( 'ask: a user who can deactivate this plugin, a key and a lifecycle_feedback server', Tack_Lifecycle::should_ask() );
tack_lf_reset( $lf_ping_life );
check( 'ask: not on a server that does not take the answer', ! Tack_Lifecycle::should_ask() );
tack_lf_reset( null );
tack_cx_routes( array( '/ping' => $lf_ping_fb ) );
check( 'ask: not with a cold capability cache, and no ping for a page view', ! Tack_Lifecycle::should_ask() && 0 === count( tack_cx_calls( '/ping' ) ) );
tack_lf_reset( $lf_ping_fb );
$GLOBALS['TACK_CAPS'] = array( 'manage_woocommerce', 'activate_plugins' );
check( 'ask: not for a user who cannot deactivate this plugin', ! Tack_Lifecycle::should_ask() );
tack_lf_reset( $lf_ping_fb );
$GLOBALS['TACK_NETWORK_ADMIN'] = true;
check( 'ask: not in the network admin', ! Tack_Lifecycle::should_ask() );
tack_lf_reset( $lf_ping_fb );
tack_test_set_option( 'tack_quotes_api_key', '' );
check( 'ask: not without a key (nothing could be sent)', ! Tack_Lifecycle::should_ask() );

$lf_core_link = '<a href="plugins.php?action=deactivate&amp;plugin=tackquote%2Ftackquote.php&amp;_wpnonce=abc" id="deactivate-tackquote" aria-label="Deactivate TackQuote">Deactivate</a>';
tack_lf_reset( $lf_ping_fb );
$lf_links = Tack_Lifecycle::mark_deactivate_link( array( 'deactivate' => $lf_core_link, 'settings' => '<a href="x">Settings</a>' ) );
check( 'link: Deactivate is marked for the script', 0 === strpos( $lf_links['deactivate'], '<a data-tackquote-deactivate="1" href=' ) );
check( 'link: its address is WordPress\'s own, unchanged', false !== strpos( $lf_links['deactivate'], 'href="plugins.php?action=deactivate&amp;plugin=tackquote%2Ftackquote.php&amp;_wpnonce=abc"' ) );
check( 'link: other links are untouched', '<a href="x">Settings</a>' === $lf_links['settings'] );
tack_lf_reset( $lf_ping_life );
check( 'link: not marked when the dialog is not offered', $lf_core_link === Tack_Lifecycle::mark_deactivate_link( array( 'deactivate' => $lf_core_link ) )['deactivate'] );

tack_lf_reset( $lf_ping_fb );
$GLOBALS['TACK_ENQUEUED'] = array();
Tack_Lifecycle::enqueue( 'toplevel_page_tackquote' );
check( 'assets: not loaded outside Plugins', ! in_array( 'tackquote-deactivate-feedback', (array) $GLOBALS['TACK_ENQUEUED'], true ) );
Tack_Lifecycle::enqueue( 'plugins.php' );
check( 'assets: loaded on Plugins', in_array( 'tackquote-deactivate-feedback', (array) $GLOBALS['TACK_ENQUEUED'], true ) );

// ── 7. The dialog: optional, Skip first, nothing pre-chosen, escaped ─────────────
$lf_html = Tack_Lifecycle::dialog_html();
check( 'dialog: "Skip and deactivate" has the focus', 1 === preg_match( '/<button type="button" class="button button-primary" data-tackquote-skip autofocus>Skip and deactivate<\/button>/', $lf_html ) );
check( 'dialog: exactly one autofocus', 1 === substr_count( $lf_html, 'autofocus' ) );
check( 'dialog: nine reasons, none chosen in advance', 9 === substr_count( $lf_html, 'type="radio" name="reason"' ) && false === strpos( $lf_html, 'checked' ) );
check( 'dialog: posts to admin-post.php with its own nonce', false !== strpos( $lf_html, 'action="https://shop.example/wp-admin/admin-post.php"' ) && false !== strpos( $lf_html, 'value="' . wp_create_nonce( Tack_Lifecycle::FEEDBACK_ACTION ) . '"' ) );
check( 'dialog: the note is capped at 4000 in the browser too', false !== strpos( $lf_html, 'maxlength="4000"' ) );
check( 'dialog: described by its intro (aria-describedby)', false !== strpos( $lf_html, 'aria-describedby="tackquote-deactivate-feedback-intro"' ) && false !== strpos( $lf_html, '<p id="tackquote-deactivate-feedback-intro">' ) );
check( 'dialog: the text tells the merchant what is sent and asks for no personal details', false !== strpos( $lf_html, 'sent to TackQuote' ) && false !== strpos( $lf_html, 'do not include personal details' ) );
$lf_view = Tack_Lifecycle::dialog_html( array( 'plugin_status' => 'inactive', 'paged' => '3', 's' => 'a"b<c' ) );
check( 'dialog: the list view rides as escaped hidden fields', false !== strpos( $lf_view, 'name="plugin_status" value="inactive"' ) && false !== strpos( $lf_view, 'name="paged" value="3"' ) && false !== strpos( $lf_view, 'name="s" value="a&quot;b' ) && false === strpos( $lf_view, 'a"b' ) );
$lf_bad_view = Tack_Lifecycle::dialog_html( array( 'plugin_status' => 'x" onfocus="y' ) );
check( 'dialog: an unknown list view is not rendered', false === strpos( $lf_bad_view, 'plugin_status' ) );

$lf_hostile_text = '<img src=x onerror=alert(1)>"';
$lf_hostile_out  = '&lt;img src=x onerror=alert(1)&gt;&quot;';
foreach ( array( 'The price', 'Before you deactivate TackQuote', 'Skip and deactivate' ) as $lf_msgid ) {
	$GLOBALS['TACK_TRANSLATIONS'][ $lf_msgid ] = $lf_hostile_text;
	$lf_hostile                                 = Tack_Lifecycle::dialog_html();
	unset( $GLOBALS['TACK_TRANSLATIONS'][ $lf_msgid ] );
	check( 'dialog: a hostile translation of "' . $lf_msgid . '" is escaped', false === strpos( $lf_hostile, '<img' ) && false !== strpos( $lf_hostile, $lf_hostile_out ) );
}

// ── 8. Wiring ────────────────────────────────────────────────────────────────────
$lf_quotes = (string) file_get_contents( TACK_QUOTES_DIR . 'includes/class-tack-quotes.php' );
check( 'wiring: the dialog is registered on admin requests', 1 === preg_match( '/if \( is_admin\(\) \) \{[^}]*Tack_Lifecycle::init\(\);/s', $lf_quotes ) );

// ── 9. uninstall.php, executed: the real file, in its own PHP process ────────────
/**
 * Run uninstall.php once with a scenario and answer what it sent.
 *
 * @param array $scenario Harness scenario.
 * @return array{requests: array, keyDeleted: bool}|null
 */
function tack_lf_uninstall( array $scenario ) {
	$cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/uninstall-harness.php' ) . ' ' . escapeshellarg( (string) wp_json_encode( $scenario ) ) . ' 2>&1';
	$out = shell_exec( $cmd ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- test runner.
	$res = json_decode( (string) $out, true );
	if ( ! is_array( $res ) ) {
		echo '        harness output: ' . substr( (string) $out, 0, 400 ) . "\n";
		return null;
	}
	return $res;
}
preg_match( '/^ \* Version:\s+(\S+)$/m', (string) file_get_contents( TACK_QUOTES_DIR . 'tackquote.php' ), $lf_m );
$lf_version = $lf_m[1] ?? '';

$lf_u = tack_lf_uninstall( array( 'caps' => array( 'lifecycle', 'lifecycle_feedback' ), 'answers' => array( array( 200, array( 'restKeysReleased' => true ) ) ) ) );
check( 'uninstall (feedback server): one call, {event: uninstalled, pluginVersion from the header}', is_array( $lf_u ) && 1 === count( $lf_u['requests'] ) && array( 'event' => 'uninstalled', 'pluginVersion' => $lf_version ) === $lf_u['requests'][0]['body'] && '' !== $lf_version, wp_json_encode( $lf_u ) );
check( 'uninstall: 3 s, no redirects, to plugin-lifecycle', is_array( $lf_u ) && 3 === $lf_u['requests'][0]['timeout'] && 0 === $lf_u['requests'][0]['redirection'] && 'https://api.tackquote.test/v1/integrations/woocommerce/plugin-lifecycle' === $lf_u['requests'][0]['url'] );
check( 'uninstall: released -> the WooCommerce key is deleted', is_array( $lf_u ) && true === $lf_u['keyDeleted'] );

$lf_u = tack_lf_uninstall( array( 'caps' => array( 'lifecycle' ), 'answers' => array( array( 200, array( 'restKeysReleased' => true ) ) ) ) );
check( 'uninstall (lifecycle-only server): exactly {event: uninstalled}', is_array( $lf_u ) && 1 === count( $lf_u['requests'] ) && array( 'event' => 'uninstalled' ) === $lf_u['requests'][0]['body'], wp_json_encode( $lf_u ) );

$lf_u = tack_lf_uninstall( array( 'caps' => null, 'answers' => array( array( 200, array( 'restKeysReleased' => false ) ) ) ) );
check( 'uninstall (no cached ping): exactly {event}, and a key TackQuote kept is not deleted', is_array( $lf_u ) && 1 === count( $lf_u['requests'] ) && array( 'event' => 'uninstalled' ) === $lf_u['requests'][0]['body'] && false === $lf_u['keyDeleted'] );

$lf_u = tack_lf_uninstall( array( 'caps' => array( 'attachments' ) ) );
check( 'uninstall (server without lifecycle): nothing is sent', is_array( $lf_u ) && 0 === count( $lf_u['requests'] ) && false === $lf_u['keyDeleted'] );

$lf_u = tack_lf_uninstall( array( 'caps' => array( 'lifecycle', 'lifecycle_feedback' ), 'answers' => array( array( 400, array( 'statusCode' => 400 ) ), array( 200, array( 'restKeysReleased' => true ) ) ) ) );
check( 'uninstall 400 on the version: the bare {event: uninstalled} once more, 3 s', is_array( $lf_u ) && 2 === count( $lf_u['requests'] ) && array( 'event' => 'uninstalled' ) === $lf_u['requests'][1]['body'] && 3 === $lf_u['requests'][1]['timeout'], wp_json_encode( $lf_u ) );
check( 'uninstall 400 then released: the key is deleted on the retry\'s answer', is_array( $lf_u ) && true === $lf_u['keyDeleted'] );

$lf_u = tack_lf_uninstall( array( 'caps' => array( 'lifecycle' ), 'answers' => array( array( 400, array( 'statusCode' => 400 ) ) ) ) );
check( 'uninstall 400 on the bare event: no retry (nothing to drop)', is_array( $lf_u ) && 1 === count( $lf_u['requests'] ) && false === $lf_u['keyDeleted'] );

$lf_u = tack_lf_uninstall( array( 'caps' => array( 'lifecycle', 'lifecycle_feedback' ), 'answers' => array( 'TRANSPORT' ) ) );
check( 'uninstall transport failure: one call, the key is kept', is_array( $lf_u ) && 1 === count( $lf_u['requests'] ) && false === $lf_u['keyDeleted'] );

$lf_u = tack_lf_uninstall( array( 'caps' => array( 'lifecycle', 'lifecycle_feedback' ), 'answers' => array( array( 503, array( 'statusCode' => 503 ) ) ) ) );
check( 'uninstall 503: one call, the key is kept', is_array( $lf_u ) && 1 === count( $lf_u['requests'] ) && false === $lf_u['keyDeleted'] );

$lf_uninstall = (string) file_get_contents( TACK_QUOTES_DIR . 'uninstall.php' );
check( 'uninstall: an unread answer is deleted', false !== strpos( $lf_uninstall, "esc_like( '_transient_tack_quotes_deactivation_feedback_' )" ) );

$_GET = array();
