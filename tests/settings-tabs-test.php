<?php
/**
 * The TABBED settings screen (1.10.0): one form and one option group per tab.
 *
 * The danger this file guards is specific to tabs. `options.php` updates EVERY
 * option registered in the posted group and passes NULL for the ones missing from
 * the POST; a checkbox sanitizer turns NULL into 'no'. So if one tab's group held
 * an option that the tab's form did not draw, saving that tab would silently switch
 * the option off. The checks below prove, per tab, that "registered in this group"
 * and "drawn by this form" are the SAME set of options, and then replay an
 * options.php save to show the other tabs are left untouched.
 *
 * @package TackQuotes
 */

require_once TACK_QUOTES_DIR . 'includes/class-tack-widget.php';
require_once TACK_QUOTES_DIR . 'includes/class-tack-sync-gate.php';
require_once TACK_QUOTES_DIR . 'includes/class-tack-api-client.php';

/**
 * Base option names a chunk of HTML posts (`name="x"` and `name="x[...]"`).
 *
 * @param string $html Markup.
 * @return string[]
 */
function tack_tabs_posted_names( $html ) {
	preg_match_all( '/\bname="([A-Za-z0-9_\-]+)(?:\[[^"]*\])?"/', $html, $m );
	$names = array_values( array_unique( $m[1] ) );
	sort( $names );
	return $names;
}

/**
 * Options registered in a group.
 *
 * @param string $group Option group.
 * @return string[]
 */
function tack_tabs_registered( $group ) {
	$out = array();
	foreach ( $GLOBALS['TACK_SETTINGS'] as $setting ) {
		if ( $setting['group'] === $group ) {
			$out[] = $setting['option'];
		}
	}
	sort( $out );
	return $out;
}

/**
 * Replay wp-admin/options.php for one option group: every registered option of the
 * group is updated, with NULL for anything the POST does not carry, through its
 * sanitize callback.
 *
 * @param string $group Option group.
 * @param array  $post  Submitted fields.
 */
function tack_tabs_save( $group, array $post ) {
	foreach ( $GLOBALS['TACK_SETTINGS'] as $setting ) {
		if ( $setting['group'] !== $group ) {
			continue;
		}
		$value    = array_key_exists( $setting['option'], $post ) ? $post[ $setting['option'] ] : null;
		$callback = $setting['args']['sanitize_callback'] ?? null;
		if ( is_callable( $callback ) ) {
			$value = call_user_func( $callback, $value );
		}
		update_option( $setting['option'], $value );
	}
}

/**
 * Render one tab's sections the way render_tab_form() does.
 *
 * @param string $tab Tab slug.
 * @return string
 */
function tack_tabs_render_sections( $tab ) {
	ob_start();
	do_settings_sections( Tack_Settings::tab_page( $tab ) );
	return (string) ob_get_clean();
}

$tabs_settings = new Tack_Settings();
tack_test_reset_settings_api();
$tabs_settings->register_settings();

$tabs = Tack_Settings::tabs();
check( 'the page has an Overview plus six settings tabs', array( 'overview', 'connection', 'storefront', 'pricing', 'groups', 'forms', 'sync' ) === array_keys( $tabs ), implode( ',', array_keys( $tabs ) ) );

// Fixtures so every grid draws its rows rather than an empty state.
tack_test_set_option( Tack_Settings::OPTION_GROUP_CODES, array( 'TIER2', 'TIER3' ) );
tack_test_set_gateways( array( 'cod' => 'Cash on delivery' ) );
tack_test_set_shipping_methods( array( 'flat_rate' => 'Flat rate' ) );

// ── Every tab draws exactly the options its group owns ──────────────────────

$tabs_owner = array();
foreach ( array_keys( $tabs ) as $tab ) {
	$registered = tack_tabs_registered( Tack_Settings::option_group( $tab ) );
	foreach ( $registered as $option ) {
		$tabs_owner[ $option ][] = $tab;
	}
	if ( Tack_Settings::DEFAULT_TAB === $tab ) {
		check( 'the Overview owns no options (it has no form)', array() === $registered, implode( ',', $registered ) );
		continue;
	}
	$drawn = tack_tabs_posted_names( tack_tabs_render_sections( $tab ) );
	check(
		"tab '$tab' draws every option its group registers, and nothing else",
		$drawn === $registered,
		'registered-not-drawn: ' . implode( ',', array_diff( $registered, $drawn ) ) . ' | drawn-not-registered: ' . implode( ',', array_diff( $drawn, $registered ) )
	);
}

$tabs_shared = array();
foreach ( $tabs_owner as $option => $owners ) {
	if ( 1 !== count( $owners ) ) {
		$tabs_shared[] = $option . ' (' . implode( '+', $owners ) . ')';
	}
}
check( 'every option belongs to exactly one tab', empty( $tabs_shared ), implode( ', ', $tabs_shared ) );
check( 'the tabs together still register every option (none lost in the split)', count( $tabs_owner ) === count( $GLOBALS['TACK_SETTINGS'] ), count( $tabs_owner ) . ' vs ' . count( $GLOBALS['TACK_SETTINGS'] ) );

// ── Each tab renders only its own sections ──────────────────────────────────

$tabs_pricing_html = tack_tabs_render_sections( 'pricing' );
check( 'the B2B pricing tab shows the pricing switches', false !== strpos( $tabs_pricing_html, 'name="' . Tack_Wholesale_Pricing::OPTION_ENABLED . '"' ) );
check( '...and not the API key or the store mode', false === strpos( $tabs_pricing_html, 'name="tack_quotes_api_key"' ) && false === strpos( $tabs_pricing_html, 'name="' . Tack_Catalog_Mode::OPT_MODE . '"' ) );
check( 'the Overview has no settings sections', '' === tack_tabs_render_sections( 'overview' ) );

// ── Saving one tab leaves every other tab alone ─────────────────────────────

$GLOBALS['TACK_CAPS'] = array( 'manage_options', 'manage_woocommerce' );
tack_test_set_option( 'tack_quotes_api_key', 'tq_live_abcd1234' );
tack_test_set_option( Tack_Wholesale_Pricing::OPTION_ENABLED, 'yes' );
tack_test_set_option( Tack_B2B_Notices::OPTION_ORDER_LIMITS, 'yes' );
tack_test_set_option( Tack_Tax_Exempt::OPTION_ENABLED, 'yes' );
tack_test_set_option( 'tack_quotes_enable_order_sync', 'yes' );
tack_test_set_option( Tack_Storefront_Forms::OPTION_WHOLESALE_TAB, 'yes' );
tack_test_set_option( Tack_Group_Restrictions::OPTION_ENABLED, 'yes' );
tack_test_set_option( Tack_Group_Restrictions::OPTION_PAYMENT_MAP, 'cod: TIER2' );
tack_test_set_option( Tack_Catalog_Mode::OPT_MODE, Tack_Catalog_Mode::MODE_CART );

tack_tabs_save(
	Tack_Settings::option_group( 'storefront' ),
	array(
		Tack_Catalog_Mode::OPT_MODE  => Tack_Catalog_Mode::MODE_QUOTE_ONLY,
		'tack_quotes_enable_widget' => 'yes',
	)
);
check( 'the Storefront save took effect', Tack_Catalog_Mode::MODE_QUOTE_ONLY === get_option( Tack_Catalog_Mode::OPT_MODE ) );
check(
	'saving Storefront does NOT switch off B2B pricing, tax exemption, order sync, forms or group rules',
	'yes' === get_option( Tack_Wholesale_Pricing::OPTION_ENABLED )
		&& 'yes' === get_option( Tack_B2B_Notices::OPTION_ORDER_LIMITS )
		&& 'yes' === get_option( Tack_Tax_Exempt::OPTION_ENABLED )
		&& 'yes' === get_option( 'tack_quotes_enable_order_sync' )
		&& 'yes' === get_option( Tack_Storefront_Forms::OPTION_WHOLESALE_TAB )
		&& 'yes' === get_option( Tack_Group_Restrictions::OPTION_ENABLED )
		&& 'cod: TIER2' === get_option( Tack_Group_Restrictions::OPTION_PAYMENT_MAP ),
	wp_json_encode( $GLOBALS['TACK_OPTIONS'] )
);
check( '...and does not touch the API key', 'tq_live_abcd1234' === get_option( 'tack_quotes_api_key' ) );

// The replay is faithful: an unticked switch on the tab being saved IS turned off.
tack_tabs_save( Tack_Settings::option_group( 'pricing' ), array( Tack_Wholesale_Pricing::OPTION_ENABLED => 'yes' ) );
check( 'saving B2B pricing with order limits unticked turns them off (the replay is real)', 'no' === get_option( Tack_B2B_Notices::OPTION_ORDER_LIMITS ) );
check( '...while the Storefront mode saved earlier is untouched', Tack_Catalog_Mode::MODE_QUOTE_ONLY === get_option( Tack_Catalog_Mode::OPT_MODE ) );
check( '...and order sync is untouched', 'yes' === get_option( 'tack_quotes_enable_order_sync' ) );

// The Connection tab's empty key field must keep the key (the field renders blank).
tack_tabs_save( Tack_Settings::option_group( 'connection' ), array( 'tack_quotes_api_key' => '' ) );
check( 'saving Connection with the key field blank keeps the saved key', 'tq_live_abcd1234' === get_option( 'tack_quotes_api_key' ) );

// ── Unknown tab, capability, the Overview's honesty ─────────────────────────

$_GET['tab'] = 'no-such-tab';
check( 'an unknown tab falls back to the Overview', 'overview' === Tack_Settings::current_tab() );
$_GET['tab'] = 'groups';
check( 'a known tab is selected', 'groups' === Tack_Settings::current_tab() );
unset( $_GET['tab'] );
check( 'no tab means the Overview', 'overview' === Tack_Settings::current_tab() );

$GLOBALS['TACK_CAPS'] = array( 'manage_woocommerce' );
$tabs_died            = false;
ob_start();
try {
	$tabs_settings->render_page();
} catch ( Tack_Test_Wp_Die $e ) {
	$tabs_died = true;
}
ob_end_clean();
check( 'the page refuses anyone without manage_options', $tabs_died );

$GLOBALS['TACK_CAPS'] = array( 'manage_options', 'manage_woocommerce' );

/**
 * Render the whole page for a tab.
 *
 * @param Tack_Settings $settings Instance.
 * @param string        $tab      Tab.
 * @return string
 */
function tack_tabs_page( $settings, $tab ) {
	$_GET['tab'] = $tab;
	ob_start();
	$settings->render_page();
	$html = (string) ob_get_clean();
	unset( $_GET['tab'] );
	return $html;
}

$tabs_html = tack_tabs_page( $tabs_settings, 'pricing' );
check( 'a settings tab posts its OWN option group', false !== strpos( $tabs_html, 'name="option_page" value="' . Tack_Settings::option_group( 'pricing' ) . '"' ) );
check( '...has a save button', false !== strpos( $tabs_html, 'type="submit"' ) && false !== strpos( $tabs_html, 'tack-savebar' ) );
check( '...and marks itself as the current tab', (bool) preg_match( '/tab=pricing" class="nav-tab nav-tab-active" aria-current="page"/', $tabs_html ) );

// No key, but a passing test left behind for an earlier key: still NOT connected.
delete_option( 'tack_quotes_api_key' );
$GLOBALS['TACK_TRANSIENTS'][ Tack_Settings::CONNECTION_CHECK ] = array(
	'ok'      => true,
	'key'     => substr( hash( 'sha256', 'tq_live_abcd1234' ), 0, 16 ),
	'at'      => time(),
	'message' => 'Connected to TackQuote successfully.',
);
check( 'with no key saved the connection state is none, whatever an old test said', 'none' === Tack_Settings::connection_status()['state'] );
$tabs_html = tack_tabs_page( $tabs_settings, 'overview' );
check( 'the Overview says Not connected when no key is saved', false !== strpos( $tabs_html, 'tack-pill--error">Not connected<' ), substr( $tabs_html, 0, 400 ) );
check( '...never Connected', false === strpos( $tabs_html, '>Connected<' ) );
check( '...shows the first-run checklist', false !== strpos( $tabs_html, 'Get started' ) );
check( '...and has no options.php form', false === strpos( $tabs_html, 'action="options.php"' ) );

tack_test_set_option( 'tack_quotes_api_key', 'tq_live_other999' );
check( 'a key the last test did not cover reads as untested, not connected', 'untested' === Tack_Settings::connection_status()['state'] );
tack_test_set_option( 'tack_quotes_api_key', 'tq_live_abcd1234' );
check( 'the key that passed the last test reads as connected', 'ok' === Tack_Settings::connection_status()['state'] );
$tabs_html = tack_tabs_page( $tabs_settings, 'overview' );
check( '...and the Overview shows the host and only the last four characters of the key', false !== strpos( $tabs_html, 'api.tackquote.com' ) && false !== strpos( $tabs_html, '1234' ) && false === strpos( $tabs_html, 'tq_live_abcd1234' ) );

$tabs_html = tack_tabs_page( $tabs_settings, 'connection' );
check( 'the Connection tab never prints the saved key', false === strpos( $tabs_html, 'tq_live_abcd1234' ) );
check( '...and asks before removing it', false !== strpos( $tabs_html, 'data-tack-confirm=' ) );
check( 'the header shows the TackQuote mark as a decorative image beside the visible name', (bool) preg_match( '#<img class="tack-logo" src="[^"]*assets/images/tackquote-mark\.svg" alt=""#', $tabs_html ) && false !== strpos( $tabs_html, '>TackQuote</h1>' ) );
check( 'the shipped mark exists', is_readable( TACK_QUOTES_DIR . 'assets/images/tackquote-mark.svg' ) );
$tabs_icon = base64_decode( substr( Tack_Settings::MENU_ICON, strlen( 'data:image/svg+xml;base64,' ) ), true );
check( 'the menu icon is a single-colour (black) SVG data URI, so wp-admin can recolour it', 0 === strpos( Tack_Settings::MENU_ICON, 'data:image/svg+xml;base64,' ) && is_string( $tabs_icon ) && false !== strpos( $tabs_icon, '<svg' ) && 2 === substr_count( $tabs_icon, 'fill="black"' ) && false === strpos( $tabs_icon, '<rect' ) );

// Leave shared fixtures as later files expect them.
unset( $GLOBALS['TACK_TRANSIENTS'][ Tack_Settings::CONNECTION_CHECK ] );
foreach ( array(
	'tack_quotes_api_key',
	Tack_Wholesale_Pricing::OPTION_ENABLED,
	Tack_B2B_Notices::OPTION_ORDER_LIMITS,
	Tack_Tax_Exempt::OPTION_ENABLED,
	'tack_quotes_enable_order_sync',
	Tack_Storefront_Forms::OPTION_WHOLESALE_TAB,
	Tack_Group_Restrictions::OPTION_ENABLED,
	Tack_Group_Restrictions::OPTION_PAYMENT_MAP,
	Tack_Catalog_Mode::OPT_MODE,
	Tack_Settings::OPTION_GROUP_CODES,
) as $tabs_option ) {
	unset( $GLOBALS['TACK_OPTIONS'][ $tabs_option ] );
}
$GLOBALS['TACK_CAPS'] = array();
