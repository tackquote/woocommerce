<?php
/**
 * IMPROVEMENTS 1450 (1.15.0): a key without the scope a route needs is named.
 *
 * TackQuote's ApiKeyGuard (tack apps/api/src/common/guards/api-key.guard.ts) answers
 *   403 {statusCode, code: "insufficient_scope", message, requiredScopes: [...]}
 * (429 with the same code once the same refusal repeats). The plugin's store-settings,
 * embed and REST-key routes require `quotes:write`
 * (woocommerce-plugin-route-scopes.spec.ts).
 *
 * Also 1406: the plural refusal joins feature names with WordPress's translated list
 * separators (wp_sprintf_l), not a literal ', '.
 *
 * Included from tests/run.php after store-settings-test.php (reuses its helpers).
 *
 * @package TackQuotes
 */

$sr_403 = array( 403, array( 'statusCode' => 403, 'code' => 'insufficient_scope', 'message' => 'This API key is missing the required scope(s): quotes:write. Create a new key with those scopes in Settings → Developer → API keys.', 'requiredScopes' => array( 'quotes:write' ) ) );
$sr_err = function ( $status, $data ) {
	return new WP_Error( 'tack_http_' . $status, 'x', $data + array( 'status' => $status ) );
};

// ── The helper ────────────────────────────────────────────────────────────
check( '1450 helper: a JSON 403 insufficient_scope names its scope', array( 'quotes:write' ) === Tack_Api_Client::refused_scopes( $sr_err( 403, array( 'json' => true, 'code' => 'insufficient_scope', 'requiredScopes' => array( 'quotes:write' ) ) ) ) );
check( '1450 helper: the throttled 429 with the same code counts too', array( 'quotes:write' ) === Tack_Api_Client::refused_scopes( $sr_err( 429, array( 'json' => true, 'code' => 'insufficient_scope', 'requiredScopes' => array( 'quotes:write' ) ) ) ) );
check( '1450 helper: no list -> quotes:write, the plugin routes\' scope', array( 'quotes:write' ) === Tack_Api_Client::refused_scopes( $sr_err( 403, array( 'json' => true, 'code' => 'insufficient_scope' ) ) ) );
check( '1450 helper: a list item that is not a scope name is dropped', array( 'orders:write' ) === Tack_Api_Client::refused_scopes( $sr_err( 403, array( 'json' => true, 'code' => 'insufficient_scope', 'requiredScopes' => array( '<b>x</b>', 'orders:write', 'orders:write' ) ) ) ) );
check( '1450 helper: another 403 (api_key_invalid, a proxy page) is not a scope refusal', null === Tack_Api_Client::refused_scopes( $sr_err( 403, array( 'json' => true, 'code' => 'api_key_invalid' ) ) ) && null === Tack_Api_Client::refused_scopes( $sr_err( 403, array( 'json' => false, 'code' => 'insufficient_scope' ) ) ) && null === Tack_Api_Client::refused_scopes( array( 'ok' => true ) ) );
$sr_two = Tack_Api_Client::missing_scope_message( $sr_err( 403, array( 'json' => true, 'code' => 'insufficient_scope', 'requiredScopes' => array( 'quotes:write', 'orders:write' ) ) ) );
check( '1450 helper: two scopes, plural, joined by the list helper', false !== strpos( $sr_two, 'quotes:write and orders:write scopes' ), $sr_two );
check( '1450 helper: not a scope refusal -> empty message', '' === Tack_Api_Client::missing_scope_message( $sr_err( 500, array( 'json' => true ) ) ) );

// ── Store settings: the read ──────────────────────────────────────────────
tack_ss_reset();
tack_test_set_option( 'tack_quotes_api_key', $ss_key );
tack_ss_caps( array( Tack_Store_Settings::CAPABILITY, Tack_Store_Settings::WRITE_CAPABILITY ) );
tack_cx_routes( array( '/integrations/woocommerce/store-settings' => array( 200, tack_ss_answer() ) ) );
Tack_Store_Settings::refresh();
$sr_before = get_option( Tack_Store_Settings::OPTION );
tack_cx_routes( array( '/integrations/woocommerce/store-settings' => $sr_403 ) );
check( '1450 read refused: refresh reports no fresh answer', false === Tack_Store_Settings::refresh() );
check( '1450 read refused: the last answer stays in force (fail closed)', $sr_before === get_option( Tack_Store_Settings::OPTION ) );
$sr_msg = Tack_Store_Settings::refusal_message();
check( '1450 read refused: the settings page names quotes:write', false !== strpos( $sr_msg, 'quotes:write scope' ) && false !== strpos( $sr_msg, 'Connect with TackQuote' ), $sr_msg );
ob_start();
Tack_Store_Settings::render_refusal_notice();
$sr_html = (string) ob_get_clean();
check( '1450 read refused: printed as an error notice, escaped', false !== strpos( $sr_html, 'notice-error' ) && false !== strpos( $sr_html, 'quotes:write' ) && false === strpos( $sr_html, '<b>' ) );
check( '1450 wiring: the settings page prints it', false !== strpos( (string) file_get_contents( TACK_QUOTES_DIR . 'includes/class-tack-settings.php' ), 'Tack_Store_Settings::render_refusal_notice();' ) );
tack_test_set_option( 'tack_quotes_api_key', 'tq_another_key_000000000000' );
check( '1450 read refused: a refusal recorded for another key is not shown', '' === Tack_Store_Settings::refusal_message() );
tack_test_set_option( 'tack_quotes_api_key', $ss_key );
tack_ss_caps( array( Tack_Store_Settings::CAPABILITY, Tack_Store_Settings::WRITE_CAPABILITY ) );
tack_cx_routes( array( '/integrations/woocommerce/store-settings' => array( 200, tack_ss_answer() ) ) );
Tack_Store_Settings::refresh();
check( '1450 read: the next good answer clears it', '' === Tack_Store_Settings::refusal_message() && false === get_option( Tack_Store_Settings::REFUSAL_OPTION ) );
check( '1450 uninstall deletes the refusal record', false !== strpos( (string) file_get_contents( TACK_QUOTES_DIR . 'uninstall.php' ), "'tack_quotes_store_settings_refused'" ) );

// ── Store settings: a save ────────────────────────────────────────────────
$GLOBALS['TACK_CAPS'] = array( 'manage_options', 'manage_woocommerce' );
$ss_settings->register_settings();
tack_cx_routes( array( '/store-settings/from-plugin' => $sr_403 ) );
$sr_w = Tack_Store_Settings::write( array( 'features' => array( 'orderLimits' => true ) ) );
check( '1450 write refused: the error names quotes:write in the plugin\'s words', is_wp_error( $sr_w ) && 'tack_insufficient_scope' === $sr_w->get_error_code() && false !== strpos( $sr_w->get_error_message(), 'quotes:write scope' ), is_wp_error( $sr_w ) ? $sr_w->get_error_message() : 'no error' );
$GLOBALS['TACK_SETTINGS_ERRORS'] = array();
tack_ss_save(
	'pricing',
	$ss_pricing(
		array(
			'tack_quotes_enable_wholesale_pricing' => true === Tack_Store_Settings::server_value( Tack_Store_Settings::PRICES ) ? 'yes' : 'no',
			'tack_quotes_enable_order_limits'      => true === Tack_Store_Settings::server_value( Tack_Store_Settings::ORDER_LIMITS ) ? 'no' : 'yes',
			'tack_quotes_enable_buyer_group'       => true === Tack_Store_Settings::server_value( Tack_Store_Settings::BUYER_GROUP ) ? 'no' : 'yes',
			'tack_quotes_apply_tax_exempt'         => true === Tack_Store_Settings::server_value( Tack_Store_Settings::TAX_EXEMPT ) ? 'yes' : 'no',
		)
	)
);
$sr_e = (string) ( $GLOBALS['TACK_SETTINGS_ERRORS'][0][2] ?? '' );
check( '1450 + 1406 save refused: names both switches with the list helper and the scope', false !== strpos( $sr_e, 'Order limits and Buyer group badge were not changed' ) && false !== strpos( $sr_e, 'quotes:write scope' ), $sr_e . ' | puts=' . count( tack_ss_calls( '/store-settings/from-plugin' ) ) );
// 1406: the separator follows the site language (the wp_sprintf_l filter, as a translation would).
$GLOBALS['TACK_PERSISTENT_FILTERS']['wp_sprintf_l'] = array(
	function () {
		return array( 'between' => '、', 'between_last_two' => '、', 'between_only_two' => ' et ' );
	},
);
$GLOBALS['TACK_SETTINGS_ERRORS'] = array();
tack_ss_save(
	'pricing',
	$ss_pricing(
		array(
			'tack_quotes_enable_wholesale_pricing' => true === Tack_Store_Settings::server_value( Tack_Store_Settings::PRICES ) ? 'yes' : 'no',
			'tack_quotes_enable_order_limits'      => true === Tack_Store_Settings::server_value( Tack_Store_Settings::ORDER_LIMITS ) ? 'no' : 'yes',
			'tack_quotes_enable_buyer_group'       => true === Tack_Store_Settings::server_value( Tack_Store_Settings::BUYER_GROUP ) ? 'no' : 'yes',
			'tack_quotes_apply_tax_exempt'         => true === Tack_Store_Settings::server_value( Tack_Store_Settings::TAX_EXEMPT ) ? 'yes' : 'no',
		)
	)
);
unset( $GLOBALS['TACK_PERSISTENT_FILTERS']['wp_sprintf_l'] );
$sr_e = (string) ( $GLOBALS['TACK_SETTINGS_ERRORS'][0][2] ?? '' );
check( '1406 the list separator is the translated one, not a literal ", "', false !== strpos( $sr_e, 'Order limits et Buyer group badge' ) && false === strpos( $sr_e, 'Order limits, Buyer' ), $sr_e );
check( '1406 no literal implode( \', \' ) left in the plural refusal', false === strpos( (string) file_get_contents( TACK_QUOTES_DIR . 'includes/class-tack-settings.php' ), "implode( ', ', \$names )" ) );

// ── The embedded dashboard ────────────────────────────────────────────────
$sr_client          = new Tack_Test_Embed_Client();
$sr_client->answers = array( '/embed/open' => $sr_err( 403, array( 'json' => true, 'code' => 'insufficient_scope', 'requiredScopes' => array( 'quotes:write' ) ) ) );
$GLOBALS['TACK_CAPS'] = array( 'manage_woocommerce', 'manage_options' );
$sr_out = tack_embed_render( new Tack_Test_Embed_Admin( $sr_client ) );
check( '1450 embed: a scope refusal names quotes:write', false !== strpos( $sr_out, 'quotes:write scope' ) && false !== strpos( $sr_out, 'refused to open the dashboard' ), substr( strip_tags( $sr_out ), 0, 400 ) );
check( '1450 embed: ...and is NOT reported as a disconnected store', false === strpos( $sr_out, 'no longer accepts this store' ) && ! $sr_client->forgot );
$sr_client->answers = array( '/embed/open' => $sr_err( 403, array( 'json' => true, 'code' => 'api_key_invalid' ) ) );
$sr_out = tack_embed_render( new Tack_Test_Embed_Admin( $sr_client ) );
check( '1450 embed: any other 403 still reads as disconnected (unchanged)', false !== strpos( $sr_out, 'no longer accepts this store' ) );

// ── REST-key status ───────────────────────────────────────────────────────
delete_transient( Tack_Rest_Keys::STATUS_TRANSIENT );
$sr_rk          = new Tack_Test_Embed_Client();
$sr_rk->answers = array( '/rest-keys/status' => $sr_err( 403, array( 'json' => true, 'code' => 'insufficient_scope', 'requiredScopes' => array( 'quotes:write' ) ) ) );
$sr_st = ( new Tack_Rest_Keys( $sr_rk ) )->status( true );
check( '1450 rest-keys status: unknown, with the scope named', 'unknown' === $sr_st['status'] && false !== strpos( $sr_st['message'], 'quotes:write scope' ) );
check( '1450 rest-keys status: a scope refusal is not cached', false === get_transient( Tack_Rest_Keys::STATUS_TRANSIENT ) );

// Clean up for later suites.
tack_ss_reset();
tack_ss_caps( null );
$GLOBALS['TACK_HTTP_RESPONDER'] = null;
