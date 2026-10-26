<?php
/**
 * 1.13.0: one place for every setting. TackQuote decides order sync, the four B2B
 * switches, the buyer groups and the wholesale form (Tack_Store_Settings); the plugin
 * keeps the store-local options, one menu entry, and sends the seller's registration
 * questions on the quote request.
 *
 * Wire contract (TackQuote API, API key; offered when the ping lists `store_settings`):
 *   GET  /integrations/woocommerce/store-settings
 *        -> {orderSync:{push}, features:{prices, orderLimits, buyerGroupBadge, taxExempt},
 *            buyerGroups:[{code,name}], wholesaleForms:[{slug,name,isDefault}],
 *            defaultWholesaleFormSlug, updatedAt}
 *   POST /integrations/woocommerce/store-settings/seed {orderSync, features} -> same shape
 *
 * Included from tests/run.php after connect-test.php (reuses tack_cx_routes()).
 *
 * @package TackQuotes
 */

$ss_key = 'tq_live_store_settings_0001';
$ss_fp  = substr( hash( 'sha256', $ss_key ), 0, 16 );

/**
 * Put the capability cache in a known state for the saved key.
 *
 * @param string[]|null $caps Capabilities, or null for no fresh cache.
 */
function tack_ss_caps( $caps ) {
	if ( null === $caps ) {
		unset( $GLOBALS['TACK_TRANSIENTS'][ Tack_Api_Client::CAPABILITIES_TRANSIENT ] );
		return;
	}
	$GLOBALS['TACK_TRANSIENTS'][ Tack_Api_Client::CAPABILITIES_TRANSIENT ] = array(
		'key'    => substr( hash( 'sha256', (string) get_option( 'tack_quotes_api_key', '' ) ), 0, 16 ),
		'caps'   => $caps,
		'until'  => time() + 3600,
		'latest' => '',
	);
}

/** Clear every store-settings option. */
function tack_ss_reset() {
	foreach ( array( Tack_Store_Settings::OPTION ) as $option ) {
		delete_option( $option );
	}
	foreach ( Tack_Store_Settings::features() as $def ) {
		delete_option( $def['legacy'] );
		delete_option( $def['off'] );
	}
	$GLOBALS['TACK_HTTP_REQUESTS'] = array();
	$GLOBALS['TACK_CRON']          = array();
}

/**
 * A TackQuote answer.
 *
 * @param array $over Overrides.
 * @return array
 */
function tack_ss_answer( array $over = array() ) {
	return array_replace_recursive(
		array(
			'orderSync'                => array( 'push' => true ),
			'features'                 => array(
				'prices'          => true,
				'orderLimits'     => false,
				'buyerGroupBadge' => true,
				'taxExempt'       => false,
			),
			'buyerGroups'              => array(
				array(
					'code' => 'gold',
					'name' => 'Gold trade',
				),
				array(
					'code' => 'TIER2',
					'name' => '<b>Tier 2</b>',
				),
			),
			'wholesaleForms'           => array(
				array(
					'slug'      => 'trade-signup',
					'name'      => 'Trade signup',
					'isDefault' => true,
				),
				array(
					'slug'      => 'distributor',
					'name'      => 'Distributors',
					'isDefault' => false,
				),
			),
			'defaultWholesaleFormSlug' => 'distributor',
			'updatedAt'                => '2026-10-26T10:00:00.000Z',
		),
		$over
	);
}

/**
 * Requests made to a path fragment.
 *
 * @param string $fragment Fragment.
 * @return array
 */
function tack_ss_calls( $fragment ) {
	return array_values(
		array_filter(
			$GLOBALS['TACK_HTTP_REQUESTS'],
			function ( $r ) use ( $fragment ) {
				return false !== strpos( $r['url'], $fragment );
			}
		)
	);
}

update_option( 'tack_quotes_api_key', $ss_key );
update_option( 'tack_quotes_api_url', 'https://api.tackquote.com/v1' );
tack_ss_reset();

// ── An older server: nothing changes ─────────────────────────────────────────
tack_ss_caps( array( 'standing', 'rest_keys' ) );
update_option( 'tack_quotes_enable_order_sync', 'yes' );
update_option( 'tack_quotes_enable_wholesale_pricing', 'no' );
tack_cx_routes( array( '/store-settings' => array( 200, tack_ss_answer() ) ) );
check( 'old server: refresh reads nothing', false === Tack_Store_Settings::refresh() && array() === tack_ss_calls( '/store-settings' ) );
check( 'old server: not server-driven', ! Tack_Store_Settings::is_server_driven() );
check( 'old server: order sync follows the plugin\'s own option (on)', Tack_Order_Sync::is_enabled() );
check( 'old server: prices follow the plugin\'s own option (off)', ! Tack_Wholesale_Pricing::is_enabled() );
update_option( Tack_Storefront_Forms::OPTION_FORM_SLUG, 'typed-form' );
check( 'old server: the typed form slug is used', 'typed-form' === Tack_Storefront_Forms::default_slug() );
check( 'old server: no buyer group list', null === Tack_Store_Settings::buyer_groups() );

// ── A store_settings server ─────────────────────────────────────────────────
tack_ss_reset();
tack_ss_caps( array( 'standing', Tack_Store_Settings::CAPABILITY, Tack_Store_Settings::WRITE_CAPABILITY ) );

update_option( 'tack_quotes_enable_order_sync', 'no' );
update_option( 'tack_quotes_enable_wholesale_pricing', 'no' );
update_option( 'tack_quotes_enable_order_limits', 'yes' );
tack_cx_routes( array( '/store-settings' => array( 200, tack_ss_answer() ) ) );
check( 'refresh: GET /integrations/woocommerce/store-settings and store the answer', true === Tack_Store_Settings::refresh() );
$ss_get = tack_ss_calls( '/integrations/woocommerce/store-settings' );
check( '...one GET with the API key', 1 === count( $ss_get ) && 'GET' === $ss_get[0]['args']['method'] && $ss_key === ( $ss_get[0]['args']['headers']['X-Api-Key'] ?? '' ) );
check( '...no seed when TackQuote already holds values', array() === tack_ss_calls( '/store-settings/seed' ) );
check( 'server-driven', Tack_Store_Settings::is_server_driven() );
check( 'TackQuote wins: order sync ON although the local option is off', Tack_Order_Sync::is_enabled() );
check( 'TackQuote wins: prices ON although the local option is off', Tack_Wholesale_Pricing::is_enabled() );
check( 'TackQuote wins: order limits OFF although the local option is on', ! Tack_B2B_Notices::order_limits_enabled() );
check( '...the badge is on, so the B2B notices attach', Tack_B2B_Notices::is_enabled() && Tack_B2B_Notices::buyer_group_enabled() );
check( 'TackQuote wins: tax exemption OFF', ! Tack_Tax_Exempt::is_enabled() );
check( 'the wholesale form TackQuote names is used (no typed slug)', 'distributor' === Tack_Storefront_Forms::default_slug() );
$ss_norm = Tack_Store_Settings::normalise( tack_ss_answer( array( 'wholesaleForms' => array( array( 'slug' => 'Distributor-EU', 'name' => 'EU', 'isDefault' => false ), array( 'slug' => 'bad slug!', 'name' => 'x', 'isDefault' => false ) ), 'defaultWholesaleFormSlug' => 'Distributor-EU' ) ) );
check( 'slugs: kept verbatim (capitals too) when they follow TackQuote\'s own pattern', 'Distributor-EU' === $ss_norm['defaultWholesaleFormSlug'] && in_array( 'Distributor-EU', array_column( $ss_norm['wholesaleForms'], 'slug' ), true ) );
check( 'slugs: one outside the pattern is dropped, not rewritten', ! in_array( 'bad slug!', array_column( $ss_norm['wholesaleForms'], 'slug' ), true ) && ! in_array( 'badslug', array_column( $ss_norm['wholesaleForms'], 'slug' ), true ) );
check( 'slugs: a posted or shortcode slug in that pattern is not lowered', 'Distributor-EU' === Tack_Storefront_Forms::clean_slug( 'Distributor-EU' ) && 'abc' === Tack_Storefront_Forms::clean_slug( 'a b!c' ) );
$ss_groups = Tack_Store_Settings::buyer_groups();
check( 'buyer groups: codes upper-cased, names stripped of markup, sorted', array( array( 'code' => 'GOLD', 'name' => 'Gold trade' ), array( 'code' => 'TIER2', 'name' => 'Tier 2' ) ) === $ss_groups, wp_json_encode( $ss_groups ) );

// The local box can only switch OFF.
update_option( 'tack_quotes_off_prices', 'yes' );
check( 'turned off on this store: prices off although TackQuote has them on', ! Tack_Wholesale_Pricing::is_enabled() );
update_option( 'tack_quotes_off_prices', 'no' );
update_option( 'tack_quotes_enable_order_limits', 'yes' );
update_option( 'tack_quotes_off_order_limits', 'no' );
check( 'the local box cannot switch ON what TackQuote has off', ! Tack_B2B_Notices::order_limits_enabled() );

// TackQuote unreachable: the last answer stays in force.
tack_cx_routes( array( '/store-settings' => 'TRANSPORT' ) );
check( 'unreachable: refresh keeps the last answer', false === Tack_Store_Settings::refresh() && Tack_Order_Sync::is_enabled() );
tack_cx_routes( array( '/store-settings' => array( 200, array( 'orderSync' => 'yes' ) ) ) );
check( 'a malformed answer is never half-read: the last answer stays', false === Tack_Store_Settings::refresh() && Tack_Wholesale_Pricing::is_enabled() );

// No fresh capability list (the last ping failed): the last answer still decides.
tack_ss_caps( null );
check( 'no fresh capability list: the last answer still decides (fail closed)', Tack_Store_Settings::is_server_driven() && Tack_Order_Sync::is_enabled() );
// The server stopped offering it (rolled back): back to the plugin's own options.
tack_ss_caps( array( 'standing' ) );
update_option( 'tack_quotes_enable_order_sync', 'no' );
check( 'server no longer lists store_settings: the plugin\'s own options again', ! Tack_Store_Settings::is_server_driven() && ! Tack_Order_Sync::is_enabled() );
tack_ss_caps( array( 'standing', Tack_Store_Settings::CAPABILITY, Tack_Store_Settings::WRITE_CAPABILITY ) );

// A different key: the stored answer belongs to the old one.
update_option( 'tack_quotes_api_key', 'tq_live_other_key_0002' );
tack_ss_caps( array( 'standing', Tack_Store_Settings::CAPABILITY, Tack_Store_Settings::WRITE_CAPABILITY ) );
check( 'another key: the stored answer is not used', ! Tack_Store_Settings::is_server_driven() );
update_option( 'tack_quotes_api_key', $ss_key );
tack_ss_caps( array( 'standing', Tack_Store_Settings::CAPABILITY, Tack_Store_Settings::WRITE_CAPABILITY ) );

// Cadence (Fable 2): the read rides ONLY the standing event, so background traffic stays
// one ping + one read per ten minutes and the ping never runs more often than before.
check( 'cadence: the freshness window is the standing refresh\'s own (600 s)', Tack_Store_Settings::TTL === Tack_Standing::TTL && 600 === Tack_Store_Settings::TTL );
$GLOBALS['TACK_HOOKS'] = array();
$ss_admin_was          = $GLOBALS['TACK_IS_ADMIN'];
$GLOBALS['TACK_IS_ADMIN'] = false;
Tack_Store_Settings::init();
$GLOBALS['TACK_IS_ADMIN'] = $ss_admin_was;
check( 'cadence: init hooks only the standing event, after its ping (priority 20), and schedules nothing of its own', array( array( 'hook' => Tack_Standing::REFRESH_HOOK, 'priority' => 20, 'args' => 1 ) ) === $GLOBALS['TACK_HOOKS'] && ! method_exists( 'Tack_Store_Settings', 'maybe_schedule_refresh' ) );
$ss_stored            = get_option( Tack_Store_Settings::OPTION );
$ss_stored['fetched'] = time() - Tack_Store_Settings::TTL - 1;
update_option( Tack_Store_Settings::OPTION, $ss_stored );
$GLOBALS['TACK_CRON'] = array();
check( 'cadence: a stale answer schedules no extra standing run (no extra ping)', ( function () {
	update_option( Tack_Standing::OPTION, array( 'state' => 'active', 'key' => Tack_Standing::fingerprint( (string) get_option( 'tack_quotes_api_key' ) ), 'supported' => true, 'checked' => time() ) );
	Tack_Standing::maybe_schedule_refresh();
	return empty( $GLOBALS['TACK_CRON'][ Tack_Standing::REFRESH_HOOK ] );
} )() );
tack_cx_routes(
	array(
		'/integrations/woocommerce/ping' => array( 200, array( 'ok' => true, 'capabilities' => array( 'standing', Tack_Store_Settings::CAPABILITY, Tack_Store_Settings::WRITE_CAPABILITY ) ) ),
		'/store-settings'                => array( 200, tack_ss_answer() ),
	)
);
Tack_Standing::refresh();
Tack_Store_Settings::on_refresh_event();
check( 'cadence: one standing run = exactly one ping and one store-settings read', 1 === count( tack_ss_calls( '/ping' ) ) && 1 === count( tack_ss_calls( '/store-settings' ) ) && 2 === count( $GLOBALS['TACK_HTTP_REQUESTS'] ) );

// ── Seed: TackQuote holds nothing yet ────────────────────────────────────────
tack_ss_reset();
tack_ss_caps( array( Tack_Store_Settings::CAPABILITY, Tack_Store_Settings::WRITE_CAPABILITY ) );
update_option( 'tack_quotes_enable_order_sync', 'yes' );
update_option( 'tack_quotes_enable_wholesale_pricing', 'yes' );
update_option( 'tack_quotes_enable_order_limits', 'no' );
update_option( 'tack_quotes_enable_buyer_group', 'yes' );
update_option( 'tack_quotes_apply_tax_exempt', 'no' );
update_option( Tack_Storefront_Forms::OPTION_FORM_SLUG, 'Trade-Signup' );
$ss_empty  = array_merge( tack_ss_answer( array( 'orderSync' => array( 'push' => false ), 'features' => array( 'prices' => false, 'buyerGroupBadge' => false ) ) ), array( 'updatedAt' => null ) );
$ss_seeded = tack_ss_answer( array( 'features' => array( 'orderLimits' => false, 'buyerGroupBadge' => true ) ) );
tack_cx_routes(
	array(
		'/store-settings/seed' => array( 201, $ss_seeded ),
		'/store-settings'      => array( 200, $ss_empty ),
	)
);
check( 'empty in TackQuote: the plugin reports its options (seed) and keeps the answer', true === Tack_Store_Settings::refresh() );
$ss_seed = tack_ss_calls( '/store-settings/seed' );
check( '...one POST', 1 === count( $ss_seed ) && 'POST' === $ss_seed[0]['args']['method'] );
check(
	'...carrying exactly the plugin\'s current options',
	array(
		'orderSync' => array( 'push' => true ),
		'features'  => array(
			'prices'          => true,
			'orderLimits'     => false,
			'buyerGroupBadge' => true,
			'taxExempt'       => false,
		),
		'defaultWholesaleFormSlug' => 'Trade-Signup',
	) === json_decode( (string) $ss_seed[0]['args']['body'], true ),
	(string) ( $ss_seed[0]['args']['body'] ?? '' )
);
delete_option( Tack_Storefront_Forms::OPTION_FORM_SLUG );
check( '...with no slug saved in the plugin, the seed says null (TackQuote keeps its default)', array_key_exists( 'defaultWholesaleFormSlug', Tack_Store_Settings::local_values() ) && null === Tack_Store_Settings::local_values()['defaultWholesaleFormSlug'] );
check( '...so an existing store keeps its behaviour', Tack_Order_Sync::is_enabled() && Tack_Wholesale_Pricing::is_enabled() && Tack_B2B_Notices::buyer_group_enabled() );

tack_ss_reset();
tack_ss_caps( array( Tack_Store_Settings::CAPABILITY, Tack_Store_Settings::WRITE_CAPABILITY ) );
update_option( 'tack_quotes_enable_order_sync', 'yes' );
tack_cx_routes(
	array(
		'/store-settings/seed' => array( 500, array( 'message' => 'boom' ) ),
		'/store-settings'      => array( 200, $ss_empty ),
	)
);
check( 'seed failed: TackQuote\'s empty answer is NOT taken as "everything off"', false === Tack_Store_Settings::refresh() && ! Tack_Store_Settings::is_server_driven() && Tack_Order_Sync::is_enabled() );
tack_cx_routes(
	array(
		'/store-settings/seed' => array( 201, array_merge( $ss_seeded, array( 'updatedAt' => null ) ) ),
		'/store-settings'      => array( 200, $ss_empty ),
	)
);
check( 'a seed answer still empty is not taken either', false === Tack_Store_Settings::refresh() && ! Tack_Store_Settings::is_server_driven() );

// ── Settings screen on a store_settings server ──────────────────────────────
tack_ss_reset();
tack_ss_caps( array( Tack_Store_Settings::CAPABILITY, Tack_Store_Settings::WRITE_CAPABILITY ) );
tack_cx_routes( array( '/store-settings' => array( 200, tack_ss_answer() ) ) );
Tack_Store_Settings::refresh();
tack_test_reset_settings_api();
$ss_settings = new Tack_Settings();
$ss_settings->register_settings();
$ss_reg = function ( $tab ) {
	return tack_tabs_registered( Tack_Settings::option_group( $tab ) );
};
check( 'server mode: the pricing tab still saves the 1.12.0 switches (written through to TackQuote) and the "turn off" boxes', array( 'tack_quotes_apply_tax_exempt', 'tack_quotes_enable_buyer_group', 'tack_quotes_enable_order_limits', 'tack_quotes_enable_wholesale_pricing', 'tack_quotes_off_buyer_group', 'tack_quotes_off_order_limits', 'tack_quotes_off_prices', 'tack_quotes_off_tax_exempt', 'tack_quotes_show_quantity_breaks' ) === $ss_reg( 'pricing' ), implode( ', ', $ss_reg( 'pricing' ) ) );
check( 'server mode: the order-sync tab saves its switch and its "turn off" box', array( 'tack_quotes_enable_order_sync', 'tack_quotes_off_order_sync' ) === $ss_reg( 'sync' ) );
check( 'server mode: buyer groups are picked (still saved)', in_array( Tack_Settings::OPTION_GROUP_CODES, $ss_reg( 'groups' ), true ) );
check( 'server mode: the wholesale form is picked (still saved)', in_array( Tack_Storefront_Forms::OPTION_FORM_SLUG, $ss_reg( 'forms' ), true ) );
$GLOBALS['TACK_CAPS'] = array( 'manage_options', 'manage_woocommerce' );
tack_test_set_option( Tack_Settings::OPTION_GROUP_CODES, array() );
foreach ( array( 'connection', 'storefront', 'pricing', 'groups', 'forms', 'sync' ) as $ss_tab ) {
	$ss_drawn = tack_tabs_posted_names( tack_tabs_render_sections( $ss_tab ) );
	check( "server mode: tab '$ss_tab' draws exactly the options its group saves", $ss_drawn === $ss_reg( $ss_tab ), 'registered-not-drawn: ' . implode( ',', array_diff( $ss_reg( $ss_tab ), $ss_drawn ) ) . ' | drawn-not-registered: ' . implode( ',', array_diff( $ss_drawn, $ss_reg( $ss_tab ) ) ) );
}
$ss_html = tack_tabs_page( $ss_settings, 'pricing' );
check( 'pricing tab: the switch stays editable and shows TackQuote\'s value', (bool) preg_match( '/name="tack_quotes_enable_wholesale_pricing" value="yes" checked/', $ss_html ) && (bool) preg_match( '/name="tack_quotes_enable_order_limits" value="yes"\s*\/>/', $ss_html ) );
check( '...with the state in TackQuote', false !== strpos( $ss_html, 'tack-pill--ok">On in TackQuote<' ) && false !== strpos( $ss_html, 'tack-pill--off">Off in TackQuote<' ) );
check( '...and a "Turn off … on this store" box', false !== strpos( $ss_html, 'Turn off TackQuote prices on this store' ) );
$ss_html = tack_tabs_page( $ss_settings, 'groups' );
check( 'groups tab: TackQuote\'s groups to pick from, no code typed', false !== strpos( $ss_html, 'value="GOLD"' ) && false === strpos( $ss_html, 'type="text" id="' . Tack_Settings::OPTION_GROUP_CODES ) );
check( 'the restriction grids offer TackQuote\'s groups (none picked: all)', array( 'GOLD', 'TIER2' ) === array_values( array_intersect( array( 'GOLD', 'TIER2' ), $ss_settings->known_group_codes() ) ) );
check( 'picked groups: only codes TackQuote lists are kept', array( 'TIER2' ) === $ss_settings->sanitize_server_group_codes( array( '', 'tier2', 'NOPE' ) ) );
tack_test_set_option( Tack_Settings::OPTION_GROUP_CODES, array( 'TIER2' ) );
check( '...and the grids then offer only those', in_array( 'TIER2', $ss_settings->known_group_codes(), true ) && ! in_array( 'GOLD', $ss_settings->known_group_codes(), true ) );
tack_test_set_option( Tack_Settings::OPTION_GROUP_CODES, array() );
$ss_html = tack_tabs_page( $ss_settings, 'forms' );
check( 'forms tab: the wholesale form is picked from TackQuote\'s list', false !== strpos( $ss_html, '<select id="' . Tack_Storefront_Forms::OPTION_FORM_SLUG . '"' ) && false !== strpos( $ss_html, '<option value="distributor" selected=\'selected\'>Distributors (distributor)</option>' ) );
$ss_html = tack_tabs_page( $ss_settings, 'overview' );
check( 'overview: order sync shows what is in force (TackQuote on)', (bool) preg_match( '/tack-card-sync.{0,400}tack-pill--ok">On</s', $ss_html ) );

// ── Write-through (owner 2026-10-26: nothing 1.12.0 could do becomes impossible) ──
$ss_put = function () {
	return array_values(
		array_filter(
			$GLOBALS['TACK_HTTP_REQUESTS'],
			function ( $r ) {
				return false !== strpos( $r['url'], '/store-settings/from-plugin' ) && 'PUT' === $r['args']['method'];
			}
		)
	);
};
/**
 * Replay one options.php request for a tab: its POST, a fresh request, then the save.
 *
 * @param string $tab  Tab.
 * @param array  $post Fields.
 */
function tack_ss_save( $tab, array $post ) {
	$_POST = $post + array( 'option_page' => Tack_Settings::option_group( $tab ) );
	Tack_Settings::reset_write_batch();
	tack_tabs_save( Tack_Settings::option_group( $tab ), $post );
}
$ss_pricing = function ( array $over ) {
	return $over + array( 'tack_quotes_enable_wholesale_pricing' => 'yes', 'tack_quotes_enable_order_limits' => 'no', 'tack_quotes_enable_buyer_group' => 'yes', 'tack_quotes_apply_tax_exempt' => 'no', 'tack_quotes_off_prices' => 'no' );
};
$GLOBALS['TACK_SETTINGS_ERRORS'] = array();
tack_cx_routes( array( '/store-settings/from-plugin' => array( 200, tack_ss_answer( array( 'features' => array( 'orderLimits' => true ) ) ) ) ) );
tack_ss_save( 'pricing', $ss_pricing( array( 'tack_quotes_enable_order_limits' => 'yes' ) ) );
$ss_puts = $ss_put();
check( 'write: only the changed switch is sent, once, to PUT …/store-settings/from-plugin', 1 === count( $ss_puts ) && array( 'features' => array( 'orderLimits' => true ) ) === json_decode( (string) $ss_puts[0]['args']['body'], true ), wp_json_encode( $ss_puts ) );
check( '...TackQuote\'s answer becomes the stored value, and the local option mirrors it', Tack_B2B_Notices::order_limits_enabled() && 'yes' === get_option( 'tack_quotes_enable_order_limits' ) );

// Follow-up 2: several changed switches in one save = ONE request.
tack_cx_routes( array( '/store-settings/from-plugin' => array( 200, tack_ss_answer( array( 'features' => array( 'orderLimits' => true, 'buyerGroupBadge' => false, 'taxExempt' => true ) ) ) ) ) );
tack_ss_save( 'pricing', $ss_pricing( array( 'tack_quotes_enable_order_limits' => 'yes', 'tack_quotes_enable_buyer_group' => 'no', 'tack_quotes_apply_tax_exempt' => 'yes' ) ) );
$ss_puts = $ss_put();
check( 'batch: two changed switches are ONE request carrying both', 1 === count( $ss_puts ) && array( 'features' => array( 'buyerGroupBadge' => false, 'taxExempt' => true ) ) === json_decode( (string) $ss_puts[0]['args']['body'], true ), wp_json_encode( $ss_puts ) );
check( '...and both are saved', 'no' === get_option( 'tack_quotes_enable_buyer_group' ) && 'yes' === get_option( 'tack_quotes_apply_tax_exempt' ) && Tack_Tax_Exempt::is_enabled() );

// Follow-up 4: a second sanitize of the same save (first-time add_option) reuses the outcome.
tack_cx_routes( array( '/store-settings/from-plugin' => array( 400, array( 'statusCode' => 400, 'message' => 'Nope from TackQuote' ) ) ) );
$GLOBALS['TACK_SETTINGS_ERRORS'] = array();
$_POST = $ss_pricing( array( 'tack_quotes_enable_wholesale_pricing' => 'no', 'tack_quotes_enable_order_limits' => 'yes', 'tack_quotes_enable_buyer_group' => 'no', 'tack_quotes_apply_tax_exempt' => 'yes' ) ) + array( 'option_page' => Tack_Settings::option_group( 'pricing' ) );
Tack_Settings::reset_write_batch();
tack_tabs_save( Tack_Settings::option_group( 'pricing' ), $_POST );
tack_tabs_save( Tack_Settings::option_group( 'pricing' ), $_POST );
check( 'first-time add_option replays the sanitizer: still one request and one error', 1 === count( $ss_put() ) && 1 === count( $GLOBALS['TACK_SETTINGS_ERRORS'] ) );
check( 'write refused: the previous value stays (here and in force)', 'yes' === get_option( 'tack_quotes_enable_wholesale_pricing' ) && Tack_Wholesale_Pricing::is_enabled() );
check( '...and the reason is shown, naming the switch', false !== strpos( $GLOBALS['TACK_SETTINGS_ERRORS'][0][2], 'TackQuote did not save it' ) && false !== strpos( $GLOBALS['TACK_SETTINGS_ERRORS'][0][2], 'TackQuote prices' ) );
// Fable 4: a refused save with TWO changed switches rolls both back and names both.
$ss_before_two = array( get_option( 'tack_quotes_enable_buyer_group' ), get_option( 'tack_quotes_apply_tax_exempt' ) );
$GLOBALS['TACK_SETTINGS_ERRORS'] = array();
tack_ss_save(
	'pricing',
	$ss_pricing(
		array(
			'tack_quotes_enable_wholesale_pricing' => true === Tack_Store_Settings::server_value( Tack_Store_Settings::PRICES ) ? 'yes' : 'no',
			'tack_quotes_enable_order_limits'      => true === Tack_Store_Settings::server_value( Tack_Store_Settings::ORDER_LIMITS ) ? 'yes' : 'no',
			'tack_quotes_enable_buyer_group'       => true === Tack_Store_Settings::server_value( Tack_Store_Settings::BUYER_GROUP ) ? 'no' : 'yes',
			'tack_quotes_apply_tax_exempt'         => true === Tack_Store_Settings::server_value( Tack_Store_Settings::TAX_EXEMPT ) ? 'no' : 'yes',
		)
	)
);
$ss_err = (string) ( $GLOBALS['TACK_SETTINGS_ERRORS'][0][2] ?? '' );
check( 'two refused: both roll back to TackQuote\'s values', ( true === Tack_Store_Settings::server_value( Tack_Store_Settings::BUYER_GROUP ) ? 'yes' : 'no' ) === get_option( 'tack_quotes_enable_buyer_group' ) && ( true === Tack_Store_Settings::server_value( Tack_Store_Settings::TAX_EXEMPT ) ? 'yes' : 'no' ) === get_option( 'tack_quotes_apply_tax_exempt' ) );
check( '...one error names both, plural', 1 === count( $GLOBALS['TACK_SETTINGS_ERRORS'] ) && false !== strpos( $ss_err, 'Buyer group badge' ) && false !== strpos( $ss_err, 'Tax-exempt buyers' ) && false !== strpos( $ss_err, 'were not changed: TackQuote did not save them' ), $ss_err );

// Follow-up 1: the acting WordPress user id, only to a server that lists store_settings_actor.
tack_ss_caps( array( Tack_Store_Settings::CAPABILITY, Tack_Store_Settings::WRITE_CAPABILITY, Tack_Store_Settings::ACTOR_CAPABILITY ) );
$GLOBALS['TACK_CURRENT_USER_ID'] = 7;
tack_cx_routes( array( '/store-settings/from-plugin' => array( 200, tack_ss_answer( array( 'features' => array( 'orderLimits' => true, 'buyerGroupBadge' => false, 'taxExempt' => true, 'prices' => false ) ) ) ) ) );
tack_ss_save( 'pricing', $ss_pricing( array( 'tack_quotes_enable_wholesale_pricing' => 'no', 'tack_quotes_enable_order_limits' => 'yes', 'tack_quotes_enable_buyer_group' => 'no', 'tack_quotes_apply_tax_exempt' => 'yes' ) ) );
$ss_body = json_decode( (string) ( $ss_put()[0]['args']['body'] ?? '' ), true );
check( 'actor: wpUserId (a number, nothing else about the user) goes to a server that lists store_settings_actor', is_array( $ss_body ) && 7 === ( $ss_body['wpUserId'] ?? null ) && array( 'features', 'wpUserId' ) === array_keys( $ss_body ), wp_json_encode( $ss_body ) );
tack_ss_caps( array( Tack_Store_Settings::CAPABILITY, Tack_Store_Settings::WRITE_CAPABILITY ) );
tack_cx_routes( array( '/store-settings/from-plugin' => array( 200, tack_ss_answer( array( 'features' => array( 'orderLimits' => true, 'buyerGroupBadge' => false, 'taxExempt' => true, 'prices' => true ) ) ) ) ) );
tack_ss_save( 'pricing', $ss_pricing( array( 'tack_quotes_enable_wholesale_pricing' => 'yes', 'tack_quotes_enable_order_limits' => 'yes', 'tack_quotes_enable_buyer_group' => 'no', 'tack_quotes_apply_tax_exempt' => 'yes' ) ) );
$ss_body = json_decode( (string) ( $ss_put()[0]['args']['body'] ?? '' ), true );
check( '...and never to a server without it', is_array( $ss_body ) && ! array_key_exists( 'wpUserId', $ss_body ) );
// Fable 2: no fresh capability list -> ask the server, do not drop the attribution.
tack_ss_caps( null );
$GLOBALS['TACK_CURRENT_USER_ID'] = 9;
tack_cx_routes(
	array(
		'/integrations/woocommerce/ping' => array( 200, array( 'ok' => true, 'capabilities' => array( Tack_Store_Settings::CAPABILITY, Tack_Store_Settings::WRITE_CAPABILITY, Tack_Store_Settings::ACTOR_CAPABILITY ) ) ),
		'/store-settings/from-plugin'    => array( 200, tack_ss_answer( array( 'features' => array( 'orderLimits' => true, 'buyerGroupBadge' => false, 'taxExempt' => true, 'prices' => false ) ) ) ),
	)
);
tack_ss_save( 'pricing', $ss_pricing( array( 'tack_quotes_enable_wholesale_pricing' => 'no', 'tack_quotes_enable_order_limits' => 'yes', 'tack_quotes_enable_buyer_group' => 'no', 'tack_quotes_apply_tax_exempt' => 'yes' ) ) );
$ss_body = json_decode( (string) ( $ss_put()[0]['args']['body'] ?? '' ), true );
check( 'actor: with no fresh capability list the server is asked, and wpUserId still goes', is_array( $ss_body ) && 9 === ( $ss_body['wpUserId'] ?? null ) );
// Fable 4: nobody signed in (user id 0) -> no wpUserId at all.
tack_ss_caps( array( Tack_Store_Settings::CAPABILITY, Tack_Store_Settings::WRITE_CAPABILITY, Tack_Store_Settings::ACTOR_CAPABILITY ) );
$GLOBALS['TACK_CURRENT_USER_ID'] = 0;
tack_cx_routes( array( '/store-settings/from-plugin' => array( 200, tack_ss_answer( array( 'features' => array( 'orderLimits' => true, 'buyerGroupBadge' => false, 'taxExempt' => true, 'prices' => true ) ) ) ) ) );
tack_ss_save( 'pricing', $ss_pricing( array( 'tack_quotes_enable_wholesale_pricing' => 'yes', 'tack_quotes_enable_order_limits' => 'yes', 'tack_quotes_enable_buyer_group' => 'no', 'tack_quotes_apply_tax_exempt' => 'yes' ) ) );
$ss_body = json_decode( (string) ( $ss_put()[0]['args']['body'] ?? '' ), true );
check( 'actor: user id 0 sends no wpUserId', is_array( $ss_body ) && ! array_key_exists( 'wpUserId', $ss_body ) );
tack_ss_caps( array( Tack_Store_Settings::CAPABILITY, Tack_Store_Settings::WRITE_CAPABILITY ) );
unset( $GLOBALS['TACK_CURRENT_USER_ID'] );

// Order sync ON needs the personal-data statement.
$ss_st                = get_option( Tack_Store_Settings::OPTION );
$ss_st['settings']['orderSync']['push'] = false;
update_option( Tack_Store_Settings::OPTION, $ss_st );
tack_cx_routes( array( '/store-settings/from-plugin' => array( 200, tack_ss_answer() ) ) );
$GLOBALS['TACK_SETTINGS_ERRORS'] = array();
tack_ss_save( 'sync', array( 'tack_quotes_enable_order_sync' => array( 'on' => 'yes' ) ) );
check( 'order sync ON without the statement: nothing sent, stays off, reason shown', array() === $ss_put() && ! Tack_Order_Sync::is_enabled() && 'no' === get_option( 'tack_quotes_enable_order_sync' ) && 1 === count( $GLOBALS['TACK_SETTINGS_ERRORS'] ) );
tack_ss_save( 'sync', array( 'tack_quotes_enable_order_sync' => array( 'on' => 'yes', 'ack' => 'yes' ) ) );
$ss_puts = $ss_put();
check( 'order sync ON with the statement: acknowledgePersonalData true is sent', 1 === count( $ss_puts ) && array( 'orderSync' => array( 'push' => true, 'acknowledgePersonalData' => true ) ) === json_decode( (string) $ss_puts[0]['args']['body'], true ) && Tack_Order_Sync::is_enabled() );

// Fable 3: after a save, the local option is what TackQuote stored, not what was posted.
$ss_before = get_option( Tack_Store_Settings::OPTION );
tack_cx_routes( array( '/store-settings/from-plugin' => array( 200, tack_ss_answer( array( 'features' => array( 'orderLimits' => false, 'buyerGroupBadge' => false, 'taxExempt' => true, 'prices' => true ) ) ) ) ) );
tack_ss_save( 'pricing', $ss_pricing( array( 'tack_quotes_enable_order_limits' => 'yes', 'tack_quotes_enable_wholesale_pricing' => 'yes', 'tack_quotes_enable_buyer_group' => 'no', 'tack_quotes_apply_tax_exempt' => 'yes' ) ) );
check( 'mirror: TackQuote answered order limits off, so the local option says off too', 1 === count( $ss_put() ) && 'no' === get_option( 'tack_quotes_enable_order_limits' ) && ! Tack_B2B_Notices::order_limits_enabled() );
update_option( Tack_Store_Settings::OPTION, $ss_before );

// Fable 1: register_settings() (admin_init) starts each request with an empty write cache.
$_POST = $ss_pricing( array( 'tack_quotes_enable_order_limits' => 'no' ) ) + array( 'option_page' => Tack_Settings::option_group( 'pricing' ) );
Tack_Settings::reset_write_batch();
tack_tabs_save( Tack_Settings::option_group( 'pricing' ), $_POST );
$GLOBALS['TACK_HTTP_REQUESTS'] = array();
tack_cx_routes( array( '/store-settings/from-plugin' => array( 200, tack_ss_answer( array( 'features' => array( 'orderLimits' => true ) ) ) ) ) );
$_POST = $ss_pricing( array( 'tack_quotes_enable_order_limits' => 'yes', 'tack_quotes_enable_wholesale_pricing' => true === Tack_Store_Settings::server_value( Tack_Store_Settings::PRICES ) ? 'yes' : 'no', 'tack_quotes_enable_buyer_group' => true === Tack_Store_Settings::server_value( Tack_Store_Settings::BUYER_GROUP ) ? 'yes' : 'no', 'tack_quotes_apply_tax_exempt' => true === Tack_Store_Settings::server_value( Tack_Store_Settings::TAX_EXEMPT ) ? 'yes' : 'no' ) ) + array( 'option_page' => Tack_Settings::option_group( 'pricing' ) );
$ss_settings->register_settings();
tack_tabs_save( Tack_Settings::option_group( 'pricing' ), $_POST );
check( 'a new request (admin_init) never reuses the previous request\'s write outcome', 1 === count( $ss_put() ) && 'yes' === get_option( 'tack_quotes_enable_order_limits' ) );
$ss_st = get_option( Tack_Store_Settings::OPTION );
$ss_st['settings']['features']['orderLimits'] = false;
update_option( Tack_Store_Settings::OPTION, $ss_st );
update_option( 'tack_quotes_enable_order_limits', 'no' );

// Follow-up 3: the statement is always in the form; JS shows it only while it is needed.
$ss_st                = get_option( Tack_Store_Settings::OPTION );
foreach ( array( true, false ) as $ss_push ) {
	$ss_st['settings']['orderSync']['push'] = $ss_push;
	update_option( Tack_Store_Settings::OPTION, $ss_st );
	ob_start();
	$ss_settings->field_server_feature( array( 'feature' => Tack_Store_Settings::ORDER_SYNC ) );
	$ss_field = (string) ob_get_clean();
	check(
		'consent: push ' . ( $ss_push ? 'ON shows the "already recorded" hint, no unticked box' : 'OFF renders the box, shown while the switch is ticked' ),
		$ss_push
			? ( false === strpos( $ss_field, 'name="tack_quotes_enable_order_sync[ack]"' ) && false !== strpos( $ss_field, 'already recorded in TackQuote' ) )
			: ( false !== strpos( $ss_field, 'name="tack_quotes_enable_order_sync[ack]"' ) && false !== strpos( $ss_field, 'data-tack-when="tack_quotes_enable_order_sync[on]" data-tack-when-value="yes"' ) )
	);
}

// Not the tab's own options.php save (activation's add_option, other code): never written.
unset( $_POST['option_page'] );
$GLOBALS['TACK_HTTP_REQUESTS'] = array();
$ss_cb = null;
foreach ( $GLOBALS['TACK_SETTINGS'] as $ss_setting ) {
	if ( 'tack_quotes_enable_order_limits' === $ss_setting['option'] ) {
		$ss_cb = $ss_setting['args']['sanitize_callback'];
	}
}
check( 'an update_option() outside the tab\'s own save never writes to TackQuote (TackQuote has it off; the call says on)', false === Tack_Store_Settings::server_value( Tack_Store_Settings::ORDER_LIMITS ) && 'yes' === call_user_func( $ss_cb, 'yes' ) && array() === $ss_put() );
$_POST['option_page']  = Tack_Settings::option_group( 'pricing' );
$GLOBALS['TACK_CAPS']  = array( 'read' );
check( '...nor a save by someone without the tab\'s capability', 'yes' === call_user_func( $ss_cb, 'yes' ) && array() === $ss_put() );
$GLOBALS['TACK_CAPS'] = array( 'manage_options', 'manage_woocommerce' );

// The wholesale form, picked and saved in TackQuote.
$_POST['option_page'] = Tack_Settings::option_group( 'forms' );
tack_cx_routes( array( '/store-settings/from-plugin' => array( 200, tack_ss_answer( array( 'defaultWholesaleFormSlug' => 'trade-signup' ) ) ) ) );
Tack_Settings::reset_write_batch();
check( 'form: a listed form is saved in TackQuote and used', 'trade-signup' === $ss_settings->sanitize_server_form_slug( 'trade-signup' ) && 1 === count( $ss_put() ) && array( 'defaultWholesaleFormSlug' => 'trade-signup' ) === json_decode( (string) $ss_put()[0]['args']['body'], true ) && 'trade-signup' === Tack_Storefront_Forms::default_slug() );
$GLOBALS['TACK_HTTP_REQUESTS'] = array();
Tack_Settings::reset_write_batch();
check( 'form: one TackQuote does not list is refused, nothing sent', 'trade-signup' !== $ss_settings->sanitize_server_form_slug( 'made-up' ) && array() === $ss_put() );
check( 'form: TackQuote\'s default is sent as null', ( function () use ( $ss_settings, $ss_put ) {
	Tack_Settings::reset_write_batch();
	$ss_settings->sanitize_server_form_slug( '' );
	$p = $ss_put();
	return 1 === count( $p ) && array( 'defaultWholesaleFormSlug' => null ) === json_decode( (string) $p[0]['args']['body'], true );
} )() );
Tack_Settings::reset_write_batch();
tack_cx_routes( array( '/store-settings/from-plugin' => array( 500, array( 'message' => 'down' ) ) ) );
$GLOBALS['TACK_SETTINGS_ERRORS'] = array();
$ss_settings->sanitize_server_form_slug( 'distributor' );
$ss_settings->sanitize_server_form_slug( 'distributor' );
check( 'form: a replayed sanitize in the same save sends once and reports once', 1 === count( $ss_put() ) && 1 === count( $GLOBALS['TACK_SETTINGS_ERRORS'] ) );
// "TackQuote's default" that TackQuote did NOT save: the local form stays what it was.
Tack_Settings::reset_write_batch();
$GLOBALS['TACK_HTTP_REQUESTS']   = array();
$GLOBALS['TACK_SETTINGS_ERRORS'] = array();
update_option( Tack_Storefront_Forms::OPTION_FORM_SLUG, 'old-typed-slug' );
check( 'form: TackQuote\'s default refused by TackQuote keeps the previous local form and says so', '' !== Tack_Store_Settings::chosen_wholesale_form_slug() && 'old-typed-slug' === $ss_settings->sanitize_server_form_slug( '' ) && 1 === count( $ss_put() ) && 1 === count( $GLOBALS['TACK_SETTINGS_ERRORS'] ) );
// "TackQuote's default" saved: the local option mirrors the form TackQuote now uses (its
// default form), never the slug picked before, so a later fallback cannot revive it.
Tack_Settings::reset_write_batch();
tack_cx_routes( array( '/store-settings/from-plugin' => array( 200, tack_ss_answer( array( 'defaultWholesaleFormSlug' => null ) ) ) ) );
$GLOBALS['TACK_SETTINGS_ERRORS'] = array();
update_option( Tack_Storefront_Forms::OPTION_FORM_SLUG, 'old-typed-slug' );
$ss_first = $ss_settings->sanitize_server_form_slug( '' );
$ss_settings->sanitize_server_form_slug( $ss_first );
check( 'form: TackQuote\'s default saved mirrors TackQuote\'s default form locally (not the old slug); the replay gets it and reports nothing', 'trade-signup' === $ss_first && '' === Tack_Store_Settings::chosen_wholesale_form_slug() && 'trade-signup' === $ss_settings->sanitize_server_form_slug( $ss_first ) && array() === $GLOBALS['TACK_SETTINGS_ERRORS'] );
// TackQuote names no default form: the plugin's own default form (1.12.0's).
Tack_Settings::reset_write_batch();
$ss_st = get_option( Tack_Store_Settings::OPTION );
$ss_st['settings']['defaultWholesaleFormSlug'] = 'distributor';
update_option( Tack_Store_Settings::OPTION, $ss_st );
tack_cx_routes( array( '/store-settings/from-plugin' => array( 200, tack_ss_answer( array( 'defaultWholesaleFormSlug' => null, 'wholesaleForms' => array( array( 'isDefault' => false ) ) ) ) ) ) );
update_option( Tack_Storefront_Forms::OPTION_FORM_SLUG, 'old-typed-slug' );
check( 'form: TackQuote\'s default saved with no default form named mirrors the plugin\'s own default form', Tack_Storefront_Forms::DEFAULT_SLUG === $ss_settings->sanitize_server_form_slug( '' ) && array() === $GLOBALS['TACK_SETTINGS_ERRORS'] );
// TackQuote accepts the PUT but answers something that is not the settings: they are
// read again. When that read fails as well, the plugin cannot say what TackQuote holds,
// so nothing local changes and the seller is told (fail closed, never a silent success).
$ss_ping = array( 200, array( 'ok' => true, 'capabilities' => array( Tack_Store_Settings::CAPABILITY, Tack_Store_Settings::WRITE_CAPABILITY ) ) );
Tack_Settings::reset_write_batch();
$GLOBALS['TACK_SETTINGS_ERRORS'] = array();
update_option( Tack_Storefront_Forms::OPTION_FORM_SLUG, 'old-typed-slug' );
$ss_before = get_option( Tack_Store_Settings::OPTION );
tack_cx_routes(
	array(
		'/integrations/woocommerce/ping'           => $ss_ping,
		'/store-settings/from-plugin'              => array( 200, array( 'ok' => true ) ),
		'/integrations/woocommerce/store-settings' => array( 500, array( 'message' => 'down' ) ),
	)
);
$ss_unconfirmed = Tack_Store_Settings::write( array( 'defaultWholesaleFormSlug' => 'distributor' ) );
check( 'write: an accepted PUT whose settings cannot be read back is a WP_Error, not true', is_wp_error( $ss_unconfirmed ) && 'tack_store_settings_unconfirmed' === $ss_unconfirmed->get_error_code() && $ss_before === get_option( Tack_Store_Settings::OPTION ) );
Tack_Settings::reset_write_batch();
$GLOBALS['TACK_SETTINGS_ERRORS'] = array();
check( 'form: ...so the sanitizer keeps the previous local form and reports it', 'old-typed-slug' === $ss_settings->sanitize_server_form_slug( 'distributor' ) && 1 === count( $GLOBALS['TACK_SETTINGS_ERRORS'] ) && false !== strpos( (string) wp_json_encode( $GLOBALS['TACK_SETTINGS_ERRORS'] ), 'did not confirm' ) );
// The same accepted PUT with a successful re-read: saved, and mirrored from the re-read.
Tack_Settings::reset_write_batch();
$GLOBALS['TACK_SETTINGS_ERRORS'] = array();
tack_cx_routes(
	array(
		'/integrations/woocommerce/ping'           => $ss_ping,
		'/store-settings/from-plugin'              => array( 200, array( 'ok' => true ) ),
		'/integrations/woocommerce/store-settings' => array( 200, tack_ss_answer( array( 'defaultWholesaleFormSlug' => 'distributor' ) ) ),
	)
);
check( 'form: an accepted PUT re-read successfully is saved and mirrored, no error (positive control)', 'distributor' === $ss_settings->sanitize_server_form_slug( 'distributor' ) && 'distributor' === Tack_Store_Settings::chosen_wholesale_form_slug() && array() === $GLOBALS['TACK_SETTINGS_ERRORS'] );
$_POST = array();

// A server with store_settings but without store_settings_write: exactly 1.12.0.
tack_ss_caps( array( Tack_Store_Settings::CAPABILITY ) );
check( 'no store_settings_write: TackQuote does not decide (local 1.12.0 options)', ! Tack_Store_Settings::is_server_driven() );
tack_ss_caps( array( Tack_Store_Settings::CAPABILITY, Tack_Store_Settings::WRITE_CAPABILITY ) );

// "Change in TackQuote": the embedded dashboard when the server offers it, else a new tab.
check( 'change link: TackQuote\'s WooCommerce page in a new tab without the embedded dashboard', array( 'url' => 'https://app.tackquote.com/settings/integrations/woocommerce', 'new_tab' => true ) === Tack_Store_Settings::change_link() );
tack_ss_caps( array( Tack_Store_Settings::CAPABILITY, Tack_Store_Settings::WRITE_CAPABILITY, 'embedded_dashboard' ) );
check( 'change link: TackQuote → Dashboard when the server offers it', false === Tack_Store_Settings::change_link()['new_tab'] && false !== strpos( Tack_Store_Settings::change_link()['url'], 'page=' . Tack_Embed_Admin::PAGE_SLUG ) );

// ── Old server: the 1.12.0 screen, with the order-sync default fixed ────────
tack_ss_reset();
tack_ss_caps( array( 'standing' ) );
tack_test_reset_settings_api();
$ss_settings = new Tack_Settings();
$ss_settings->register_settings();
check( 'old server: the 1.12.0 pricing switches are saved as before', in_array( 'tack_quotes_enable_wholesale_pricing', $ss_reg( 'pricing' ), true ) && ! in_array( 'tack_quotes_off_prices', $ss_reg( 'pricing' ), true ) );
check( 'old server: group codes and form slug are typed as before', in_array( Tack_Settings::OPTION_GROUP_CODES, $ss_reg( 'groups' ), true ) && in_array( Tack_Storefront_Forms::OPTION_FORM_SLUG, $ss_reg( 'forms' ), true ) );
delete_option( 'tack_quotes_enable_order_sync' );
$ss_html = tack_tabs_page( $ss_settings, 'sync' );
check( 'issue 7: with the option absent the order-sync box renders UNticked (what is in force)', (bool) preg_match( '/name="tack_quotes_enable_order_sync" value="yes"\s*\/>/', $ss_html ) && ! Tack_Order_Sync::is_enabled() );

// ── One menu entry: the dashboard and the settings share one tab bar ─────────
ob_start();
Tack_Settings::render_nav( 'dashboard' );
$ss_nav = (string) ob_get_clean();
check( 'nav: Dashboard first and current, then every settings tab', (bool) preg_match( '/page=tackquote-dashboard" class="nav-tab nav-tab-active" aria-current="page">Dashboard<.*tab=connection.*tab=sync/s', $ss_nav ) );
check( 'the dashboard page prints the shared tab bar', false !== strpos( (string) file_get_contents( TACK_QUOTES_DIR . 'includes/class-tack-embed-admin.php' ), "Tack_Settings::render_nav( 'dashboard' );" ) );
check( 'menu: no WooCommerce highlight filters remain (the TackQuote menu is its own)', ! method_exists( 'Tack_Settings', 'menu_parent' ) && ! method_exists( 'Tack_Settings', 'menu_submenu' ) && ! method_exists( 'Tack_Settings', 'hide_menu_entry' ) );

// ── Registration questions (customFields) ───────────────────────────────────
$ss_config = array(
	'allowCompany'    => true,
	'allowIndividual' => true,
	'customFields'    => array(
		array( 'key' => 'heardFrom', 'label' => 'How did you hear about us?', 'type' => 'text', 'required' => true, 'appliesTo' => 'both' ),
		array( 'key' => 'vatNumber', 'label' => 'VAT number', 'type' => 'text', 'required' => true, 'appliesTo' => 'company' ),
		array( 'key' => 'terms', 'label' => 'I accept the terms', 'type' => 'checkbox', 'required' => false, 'appliesTo' => 'buyer' ),
		array( 'key' => 'bad key"', 'label' => 'Hostile', 'type' => 'text', 'required' => true ),
	),
);
$ss_a = Tack_Widget::registration_answers( array( 'heardFrom' => '  A friend ', 'terms' => 'true', 'vatNumber' => 'ignored', 'evil' => 'x' ), $ss_config, false );
check( 'questions: an individual sends both + buyer questions, trimmed; company-only and unknown keys dropped', array( 'heardFrom' => 'A friend', 'terms' => 'true' ) === $ss_a['values'] && '' === $ss_a['missing'], wp_json_encode( $ss_a ) );
$ss_a = Tack_Widget::registration_answers( array( 'heardFrom' => 'Ad' ), $ss_config, true );
check( 'questions: a company request must answer the company question', 'VAT number' === $ss_a['missing'] && ! array_key_exists( 'terms', $ss_a['values'] ) );
$ss_a = Tack_Widget::registration_answers( array( 'heardFrom' => 'Ad', 'vatNumber' => 'DE123' ), array_merge( $ss_config, array( 'allowCompany' => false ) ), true );
check( 'questions: a buyer_only policy treats the request as an individual one (the server\'s rule)', ! array_key_exists( 'vatNumber', $ss_a['values'] ) && array_key_exists( 'terms', $ss_a['values'] ) );
$ss_a = Tack_Widget::registration_answers( array( 'terms' => 'on' ), $ss_config, false );
check( 'questions: a required question left empty is named', 'How did you hear about us?' === $ss_a['missing'] );
check( 'questions: a checkbox is "true" or ""', 'true' === $ss_a['values']['terms'] && '' === Tack_Widget::registration_answers( array( 'terms' => 'nope' ), $ss_config, false )['values']['terms'] );
check( 'questions: no config, nothing sent', array( 'values' => array(), 'missing' => '' ) === Tack_Widget::registration_answers( array( 'heardFrom' => 'x' ), null, false ) );
$ss_long = Tack_Widget::registration_answers( array( 'heardFrom' => str_repeat( 'x', 900 ) ), $ss_config, false );
check( 'questions: a text answer is capped at 500 characters (the server\'s bound)', 500 === strlen( $ss_long['values']['heardFrom'] ) );
$ss_src = (string) file_get_contents( TACK_QUOTES_DIR . 'includes/class-tack-widget.php' );
check( 'the quote request carries customFields when there are answers', false !== strpos( $ss_src, "\$payload['customFields'] = \$questions['values'];" ) );

// REST keys (Fable 4): a shop manager sees the status, not the button.
tack_ss_caps( array( 'rest_keys' ) );
tack_cx_routes( array( '/rest-keys/status' => array( 200, array( 'status' => 'none', 'message' => '' ) ) ) );
delete_transient( Tack_Rest_Keys::STATUS_TRANSIENT );
$GLOBALS['TACK_CAPS'] = array( 'manage_woocommerce' );
ob_start();
( new Tack_Rest_Keys() )->render_card();
$ss_rk = (string) ob_get_clean();
check( 'REST keys: no "Allow catalogue and order sync" button for a shop manager', false === strpos( $ss_rk, Tack_Rest_Keys::START_ACTION ) && false !== strpos( $ss_rk, 'Only an administrator can allow catalogue and order sync.' ) );
$GLOBALS['TACK_CAPS'] = array( 'manage_options', 'manage_woocommerce' );
ob_start();
( new Tack_Rest_Keys() )->render_card();
check( 'REST keys: an administrator gets the button', false !== strpos( (string) ob_get_clean(), Tack_Rest_Keys::START_ACTION ) );

// Uninstall removes every option this feature writes.
$ss_uninstall = (string) file_get_contents( TACK_QUOTES_DIR . 'uninstall.php' );
$ss_missing   = array();
foreach ( array_merge( array( Tack_Store_Settings::OPTION ), array_column( Tack_Store_Settings::features(), 'off' ), array_column( Tack_Store_Settings::features(), 'legacy' ) ) as $ss_option ) {
	if ( false === strpos( $ss_uninstall, "'" . $ss_option . "'" ) ) {
		$ss_missing[] = $ss_option;
	}
}
check( 'uninstall.php deletes the store settings and every "turn off" box', array() === $ss_missing, implode( ', ', $ss_missing ) );

// D1 (Studio QA): Tack_Settings::init() runs on plugins_loaded and must not translate
// anything (WordPress 6.7+ notices a text domain loaded before `init`).
$GLOBALS['TACK_I18N_CALLS'] = 0;
( new Tack_Settings() )->init();
check( 'D1: Tack_Settings::init() calls no translation function', 0 === $GLOBALS['TACK_I18N_CALLS'], (string) $GLOBALS['TACK_I18N_CALLS'] );
check( 'D1: the untranslated tab slugs match the tabs, in order', Tack_Settings::TAB_SLUGS === array_keys( Tack_Settings::tabs() ) );
// Fable nit: the whole bootstrap, front end and admin, translates nothing either.
foreach ( array( false, true ) as $ss_admin ) {
	$GLOBALS['TACK_IS_ADMIN']   = $ss_admin;
	$GLOBALS['TACK_I18N_CALLS'] = 0;
	( new ReflectionClass( 'Tack_Quotes' ) )->newInstanceWithoutConstructor()->init();
	check( 'D1: the whole Tack_Quotes::init() makes no translation call (' . ( $ss_admin ? 'admin' : 'front end' ) . ')', 0 === $GLOBALS['TACK_I18N_CALLS'], (string) $GLOBALS['TACK_I18N_CALLS'] );
}
$GLOBALS['TACK_IS_ADMIN'] = false;
$GLOBALS['TACK_I18N_CALLS'] = 0;
_n( 'a', 'b', 2 );
_x( 'a', 'ctx' );
ob_start();
_e( 'a' );
esc_attr_e( 'a' );
ob_end_clean();
check( 'the i18n counter sees _n, _x, _e and esc_attr_e (positive control)', 4 === $GLOBALS['TACK_I18N_CALLS'] );
check( 'schema version: activation no longer writes it, uninstall still deletes old rows', false === strpos( (string) file_get_contents( TACK_QUOTES_DIR . 'includes/class-tack-quotes.php' ), "add_option( 'tack_quotes_schema_version'" ) && false !== strpos( (string) file_get_contents( TACK_QUOTES_DIR . 'uninstall.php' ), "'tack_quotes_schema_version'" ) );

// Clean up for later suites.
tack_ss_reset();
tack_ss_caps( null );
$GLOBALS['TACK_HTTP_RESPONDER'] = null;
