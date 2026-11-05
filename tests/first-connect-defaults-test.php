<?php
/**
 * IMPROVEMENTS 1440 (owner 2026-11-05): after the FIRST successful Connect, the quote
 * buttons that make the plugin work out of the box switch on. Only options the merchant
 * never saved are written (add_option), only once per site, and never on a store that
 * was already connected before this release.
 *
 * Included from tests/run.php after connect-test.php (reuses its routes and reset).
 *
 * @package TackQuotes
 */

/** Forget every option the first-connect defaults touch, and the marker. */
function tack_fcd_clean() {
	foreach ( array_keys( Tack_Connect::first_connect_defaults() ) as $fcd_opt ) {
		unset( $GLOBALS['TACK_OPTIONS'][ $fcd_opt ] );
	}
	unset( $GLOBALS['TACK_OPTIONS'][ Tack_Connect::OPTION_FIRST_DEFAULTS ] );
}

$fcd_set = Tack_Connect::first_connect_defaults();
check( '1440: the set is the quote buttons and nothing that touches prices, checkout, email or data', array(
	'tack_quotes_enable_widget',
	'tack_quotes_show_add_to_quote',
	'tack_quotes_show_request_quote',
	Tack_Widget::OPT_CARD_BUTTONS,
	Tack_Widget::OPT_CART_BUTTON,
) === array_keys( $fcd_set ) && array( 'yes' ) === array_values( array_unique( $fcd_set ) ), implode( ',', array_keys( $fcd_set ) ) );
foreach ( array( 'tack_quotes_enable_order_sync', 'tack_quotes_enable_wholesale_pricing', 'tack_quotes_apply_tax_exempt', 'tack_quotes_enable_order_limits', Tack_Attachments::OPTION_ENABLED, Tack_Storefront_Forms::OPTION_WHOLESALE_TAB, Tack_Storefront_Forms::OPTION_NET_TERMS_TAB, Tack_Catalog_Visibility::OPTION_ENABLED, Tack_Role_Mirror::OPTION_ENABLED, Tack_Group_Restrictions::OPTION_DISCOUNTS_ENABLED ) as $fcd_never ) {
	check( "1440: $fcd_never is never switched on by a connect", ! isset( $fcd_set[ $fcd_never ] ) );
}

// 1. A fresh store: Connect stores the first key, the test passes -> the buttons are on.
tack_cx_reset();
$cx_s = tack_cx_started();
tack_fcd_clean();
unset( $GLOBALS['TACK_OPTIONS']['tack_quotes_api_key'] );
tack_test_set_option( Tack_Widget::OPT_CART_BUTTON, 'no' ); // Saved by the merchant before connecting.
tack_cx_routes( array( '/woocommerce-connect/exchange' => $cx_exchanged, '/ping' => $cx_ping_ok ) );
$fcd_out = ( new Tack_Test_Connect() )->finish( array( 'code' => $cx_code, 'state' => $cx_s['state'] ) );
check( '1440 fresh connect: success', 'success' === $fcd_out['type'], $fcd_out['message'] );
check( '1440 fresh connect: product-card buttons switched on', 'yes' === get_option( Tack_Widget::OPT_CARD_BUTTONS ) );
check( '1440 fresh connect: the quote buttons master switch is on', 'yes' === get_option( 'tack_quotes_enable_widget' ) );
check( '1440 fresh connect: a value the merchant saved before connecting is kept', 'no' === get_option( Tack_Widget::OPT_CART_BUTTON ) );
check( '1440 fresh connect: order sync is not touched', false === get_option( 'tack_quotes_enable_order_sync' ) );
check( '1440 fresh connect: the marker says done', 'done' === get_option( Tack_Connect::OPTION_FIRST_DEFAULTS ) );
check( '1440 fresh connect: the notice says what was switched on and where', false !== strpos( $fcd_out['message'], 'Storefront' ), $fcd_out['message'] );

// 2. The merchant switches one off afterwards; a later passing test leaves it off.
tack_test_set_option( Tack_Widget::OPT_CARD_BUTTONS, 'no' );
tack_cx_routes( array( '/ping' => $cx_ping_ok ) );
$fcd_test = Tack_Settings::record_connection_test();
check( '1440 later test: passes', ! empty( $fcd_test['ok'] ) );
check( '1440 later test: the merchant\'s later "off" stays off', 'no' === get_option( Tack_Widget::OPT_CARD_BUTTONS ) );
check( '1440 later test: no defaults sentence', false === strpos( $fcd_test['message'], 'Storefront' ), $fcd_test['message'] );

// 3. A store connected before this release (no marker, key already saved): nothing changes.
tack_cx_reset();
tack_fcd_clean();
tack_cx_routes( array( '/ping' => $cx_ping_ok ) );
Tack_Settings::record_connection_test();
check( '1440 existing store: a passing test switches nothing on', false === get_option( Tack_Widget::OPT_CARD_BUTTONS ) );
check( '1440 existing store: no marker written', false === get_option( Tack_Connect::OPTION_FIRST_DEFAULTS ) );
// A reconnect (new key over an old one) is not a first connect either.
$cx_s = tack_cx_started();
tack_fcd_clean();
tack_cx_routes( array( '/woocommerce-connect/exchange' => $cx_exchanged, '/ping' => $cx_ping_ok ) );
( new Tack_Test_Connect() )->finish( array( 'code' => $cx_code, 'state' => $cx_s['state'] ) );
check( '1440 reconnect over a saved key: nothing switched on', false === get_option( Tack_Widget::OPT_CARD_BUTTONS ) );

// 4. A pasted first key: marked on add_option, applied by the first PASSING test only.
tack_cx_reset();
tack_fcd_clean();
Tack_Connect::mark_first_key();
check( '1440 pasted first key: marked pending', 'pending' === get_option( Tack_Connect::OPTION_FIRST_DEFAULTS ) );
tack_cx_routes( array( '/ping' => array( 500, array( 'statusCode' => 500 ) ) ) );
Tack_Settings::record_connection_test();
check( '1440 pasted first key, failing test: nothing switched on, still pending', false === get_option( Tack_Widget::OPT_CARD_BUTTONS ) && 'pending' === get_option( Tack_Connect::OPTION_FIRST_DEFAULTS ) );
tack_cx_routes( array( '/ping' => $cx_ping_ok ) );
Tack_Settings::record_connection_test();
check( '1440 pasted first key, passing test: switched on', 'yes' === get_option( Tack_Widget::OPT_CARD_BUTTONS ) && 'done' === get_option( Tack_Connect::OPTION_FIRST_DEFAULTS ) );

// 5. Removing the key of a store that predates the marker closes the door for good.
tack_fcd_clean();
Tack_Connect::note_key_removed();
Tack_Connect::mark_first_key();
check( '1440 removal on an old store: a later first key is not "first"', 'done' === get_option( Tack_Connect::OPTION_FIRST_DEFAULTS ) );
// ...but a fresh store whose first key failed and was removed is still waiting.
tack_fcd_clean();
Tack_Connect::mark_first_key();
Tack_Connect::note_key_removed();
check( '1440 removal while pending: still pending', 'pending' === get_option( Tack_Connect::OPTION_FIRST_DEFAULTS ) );

// Wiring: the paste path marks on add_option, the remove path notes the removal, and
// uninstall deletes the marker.
$fcd_connect_src  = (string) file_get_contents( TACK_QUOTES_DIR . 'includes/class-tack-connect.php' );
$fcd_settings_src = (string) file_get_contents( TACK_QUOTES_DIR . 'includes/class-tack-settings.php' );
check( '1440 wiring: add_option_tack_quotes_api_key marks the first key', false !== strpos( $fcd_connect_src, "add_action( 'add_option_tack_quotes_api_key', array( __CLASS__, 'mark_first_key' ) );" ) );
check( '1440 wiring: the remove-key action notes the removal before deleting', preg_match( "/Tack_Connect::note_key_removed\(\);\s*delete_option\( 'tack_quotes_api_key' \);/", $fcd_settings_src ) );
check( '1440 wiring: uninstall deletes the marker', false !== strpos( (string) file_get_contents( TACK_QUOTES_DIR . 'uninstall.php' ), "'tack_quotes_first_connect_defaults'" ) );
tack_fcd_clean();
tack_cx_reset();
