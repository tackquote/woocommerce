<?php
/**
 * Tests for 1.10.0: catalogue visibility per buyer group, shipping discounts per
 * group (run after the restrictions, in the same callback), and the role mirror.
 *
 * Reuses Tack_Test_Group_Source from group-restrictions-test.php (loaded first).
 *
 * Run: php tests/run.php
 *
 * @package TackQuotes
 */

require_once TACK_QUOTES_DIR . 'includes/class-tack-catalog-visibility.php';
require_once TACK_QUOTES_DIR . 'includes/class-tack-role-mirror.php';

// ── Stubs this file needs (guarded: other files may define some) ────────────

$GLOBALS['TACK_PRODUCT_TERMS']  = array(); // product id => term ids.
$GLOBALS['TACK_TERM_CHILDREN']  = array(); // term id => child ids.
$GLOBALS['TACK_POST_PARENT']    = array(); // post id => parent id.
$GLOBALS['TACK_IS_SINGULAR']    = false;
$GLOBALS['TACK_STATUS_HEADER']  = 0;
$GLOBALS['TACK_WP_ROLES_DEFS']  = array();

if ( ! function_exists( 'wc_get_product_term_ids' ) ) {
	function wc_get_product_term_ids( $id, $taxonomy ) {
		return $GLOBALS['TACK_PRODUCT_TERMS'][ (int) $id ] ?? array();
	}
}
if ( ! function_exists( 'get_term_children' ) ) {
	function get_term_children( $id, $taxonomy ) {
		return $GLOBALS['TACK_TERM_CHILDREN'][ (int) $id ] ?? array();
	}
}
if ( ! function_exists( 'wp_get_post_parent_id' ) ) {
	function wp_get_post_parent_id( $id ) {
		return $GLOBALS['TACK_POST_PARENT'][ (int) $id ] ?? 0;
	}
}
if ( ! function_exists( 'is_singular' ) ) {
	function is_singular( $type = '' ) {
		return (bool) $GLOBALS['TACK_IS_SINGULAR'];
	}
}
if ( ! function_exists( 'status_header' ) ) {
	function status_header( $code ) {
		$GLOBALS['TACK_STATUS_HEADER'] = (int) $code;
	}
}
if ( ! function_exists( 'nocache_headers' ) ) {
	function nocache_headers() {}
}
if ( ! function_exists( 'is_object_in_taxonomy' ) ) {
	function is_object_in_taxonomy( $type, $taxonomy ) {
		return 'product' === $type && in_array( $taxonomy, array( 'product_cat', 'product_tag' ), true );
	}
}
if ( ! function_exists( 'get_role' ) ) {
	function get_role( $role ) {
		return isset( $GLOBALS['TACK_WP_ROLES_DEFS'][ $role ] ) ? (object) array( 'name' => $role ) : null;
	}
}
if ( ! function_exists( 'add_role' ) ) {
	function add_role( $role, $name, $caps = array() ) {
		$GLOBALS['TACK_WP_ROLES_DEFS'][ $role ] = $caps;
		return (object) array( 'name' => $role );
	}
}
if ( ! function_exists( 'wc_get_price_decimals' ) ) {
	function wc_get_price_decimals() {
		return 2;
	}
}
if ( ! function_exists( 'wc_format_decimal' ) ) {
	function wc_format_decimal( $n, $dp = false ) {
		return false === $dp ? (string) $n : number_format( (float) $n, (int) $dp, '.', '' );
	}
}
if ( ! function_exists( 'get_queried_object_id' ) ) {
	function get_queried_object_id() {
		return (int) ( $GLOBALS['TACK_QUERIED_OBJECT'] ?? 0 );
	}
}

/** A WP_Query double: records `set()` and answers the conditionals it was given. */
class Tack_Test_Query {
	public $vars;
	public $flags;
	public $tax_query;
	public function __construct( $vars = array(), $flags = array(), $queried_taxonomies = array() ) {
		$this->vars      = $vars;
		$this->flags     = $flags;
		$this->tax_query = (object) array( 'queried_terms' => array_fill_keys( $queried_taxonomies, array() ) );
	}
	public function get( $k ) { return $this->vars[ $k ] ?? ''; }
	public function set( $k, $v ) { $this->vars[ $k ] = $v; }
	public function is_singular() { return ! empty( $this->flags['singular'] ); }
	public function is_main_query() { return ! empty( $this->flags['main'] ); }
	public function is_search() { return ! empty( $this->flags['search'] ); }
	public function is_tax() { return ! empty( $this->flags['tax'] ); }
	public function set_404() { $this->flags['is_404'] = true; }
}

/** A product double (id, optional parent). */
class Tack_Test_Visibility_Product {
	private $id;
	private $parent;
	private $name;
	public function __construct( $id, $parent = 0, $name = 'Product' ) {
		$this->id     = $id;
		$this->parent = $parent;
		$this->name   = $name;
	}
	public function get_id() { return $this->id; }
	public function get_parent_id() { return $this->parent; }
	public function get_name() { return $this->name; }
}

/** A WC_Shipping_Rate double. */
class Tack_Test_Rate {
	public $cost;
	public $taxes;
	public $method;
	public function __construct( $method, $cost, $taxes = array() ) {
		$this->method = $method;
		$this->cost   = $cost;
		$this->taxes  = $taxes;
	}
	public function get_cost() { return $this->cost; }
	public function set_cost( $c ) { $this->cost = $c; }
	public function get_taxes() { return $this->taxes; }
	public function set_taxes( $t ) { $this->taxes = $t; }
	public function get_method_id() { return $this->method; }
}

/** A WP_User double for the role mirror. */
class Tack_Test_User {
	public $ID = 7;
	public $roles;
	public function __construct( $roles ) { $this->roles = $roles; }
	public function add_role( $r ) { if ( ! in_array( $r, $this->roles, true ) ) { $this->roles[] = $r; } }
	public function remove_role( $r ) { $this->roles = array_values( array_diff( $this->roles, array( $r ) ) ); }
}

// Catalogue: category 10 "Trade" (child 11 "Trade pallets"), category 20 "Retail".
// Product 100 in 10, product 110 in 11, product 200 in 20, variation 101 of 100.
$GLOBALS['TACK_PRODUCT_TERMS'] = array(
	100 => array( 10 ),
	110 => array( 11 ),
	200 => array( 20 ),
);
$GLOBALS['TACK_TERM_CHILDREN'] = array( 10 => array( 11 ) );
$GLOBALS['TACK_POST_PARENT']   = array( 101 => 100 );
$GLOBALS['TACK_CAPS']          = array();
$GLOBALS['TACK_IS_ADMIN']      = false;

echo "\n-- 1.10.0 catalogue visibility per buyer group --\n";

tack_test_set_option( Tack_Catalog_Visibility::OPTION_MAP, "10: @GUESTS, TIER2\n20: TIER3" );
tack_test_set_option( Tack_Catalog_Visibility::OPTION_HIDE_WHEN_UNKNOWN, 'no' );

// Guest (signed out): category 10 and its child 11 hidden, 20 visible.
tack_test_set_logged_in( false, '' );
$v      = new Tack_Catalog_Visibility( new Tack_Test_Group_Source( null, 'anonymous' ) );
$hidden = $v->hidden_term_ids();
sort( $hidden );
check( 'guest: the ticked category and its child are hidden', array( 10, 11 ) === $hidden, implode( ',', $hidden ) );

$q = new Tack_Test_Query( array( 'post_type' => 'product' ), array( 'main' => true ) );
$v->filter_query( $q );
$tax = $q->get( 'tax_query' );
$last = is_array( $tax ) ? end( $tax ) : array();
check(
	'shop loop: hidden categories are excluded with tax_query NOT IN',
	is_array( $last ) && 'product_cat' === $last['taxonomy'] && 'NOT IN' === $last['operator'] && in_array( 10, $last['terms'], true ) && in_array( 11, $last['terms'], true )
);

$q = new Tack_Test_Query( array( 'tax_query' => array( array( 'taxonomy' => 'product_tag', 'terms' => array( 5 ) ) ) ), array( 'main' => true, 'tax' => true ), array( 'product_tag' ) );
$v->filter_query( $q );
check( 'a product-tag archive keeps its own tax_query and gains the exclusion', 2 === count( $q->get( 'tax_query' ) ) );

$q = new Tack_Test_Query( array(), array( 'main' => true, 'search' => true ) );
$v->filter_query( $q );
check( 'site search is filtered too', is_array( $q->get( 'tax_query' ) ) && 1 === count( $q->get( 'tax_query' ) ) );

$q = new Tack_Test_Query( array( 'post_type' => 'post' ), array( 'main' => true ) );
$v->filter_query( $q );
check( 'a blog query is left alone', '' === $q->get( 'tax_query' ) );

$q = new Tack_Test_Query( array( 'post_type' => 'product' ), array( 'main' => true, 'singular' => true ) );
$v->filter_query( $q );
check( 'the single product query is left to the 404 path', '' === $q->get( 'tax_query' ) );

check( 'is_visible: hidden product reports invisible', false === $v->filter_is_visible( true, 100 ) );
check( 'is_visible: child-category product reports invisible', false === $v->filter_is_visible( true, 110 ) );
check( 'is_visible: other product stays visible', true === $v->filter_is_visible( true, 200 ) );
check( 'is_visible: never turns an invisible product visible', false === $v->filter_is_visible( false, 200 ) );
check( 'purchasable: hidden product is not purchasable', false === $v->filter_purchasable( true, new Tack_Test_Visibility_Product( 100 ) ) );
check( 'purchasable: a variation follows its parent', false === $v->filter_purchasable( true, new Tack_Test_Visibility_Product( 101, 100 ) ) );
check( 'purchasable: visible product stays purchasable', true === $v->filter_purchasable( true, new Tack_Test_Visibility_Product( 200 ) ) );
check( 'related products drop hidden ids', array( 200 ) === $v->filter_related( array( 100, 200, 110 ), 200 ) );

// 404 on the product's own URL.
global $wp_query;
$wp_query                         = new Tack_Test_Query();
$GLOBALS['TACK_IS_SINGULAR']      = true;
$GLOBALS['TACK_QUERIED_OBJECT']   = 100;
$GLOBALS['TACK_STATUS_HEADER']    = 0;
$did                              = $v->block_hidden_product();
check( 'direct URL of a hidden product answers 404', $did && 404 === $GLOBALS['TACK_STATUS_HEADER'] && ! empty( $wp_query->flags['is_404'] ) );
$GLOBALS['TACK_QUERIED_OBJECT'] = 200;
$GLOBALS['TACK_STATUS_HEADER']  = 0;
check( 'direct URL of a visible product is untouched', false === $v->block_hidden_product() && 0 === $GLOBALS['TACK_STATUS_HEADER'] );
$GLOBALS['TACK_IS_SINGULAR'] = false;

// Cart already holding a now-hidden product.
tack_test_reset_notices();
$GLOBALS['TACK_CART_REMOVED'] = array();
$cart                         = new Tack_Stub_Cart(
	array(
		'a' => array( 'data' => new Tack_Test_Visibility_Product( 100, 0, 'Trade pack' ) ),
		'b' => array( 'data' => new Tack_Test_Visibility_Product( 200, 0, 'Retail pack' ) ),
	)
);
$removed = $v->remove_hidden_cart_items( $cart );
check( 'cart: the hidden line is removed and the visible one kept', 1 === $removed && array( 'a' ) === $GLOBALS['TACK_CART_REMOVED'] && isset( $cart->get_cart()['b'] ) );
check( 'cart: the removal is announced by name', 1 === count( tack_test_notices() ) && false !== strpos( tack_test_notices()[0], 'Trade pack' ) );

// Store managers and wp-admin are never filtered.
$GLOBALS['TACK_CAPS'] = array( 'manage_woocommerce' );
$v                    = new Tack_Catalog_Visibility( new Tack_Test_Group_Source( null, 'anonymous' ) );
check( 'a store manager sees hidden products', true === $v->filter_is_visible( true, 100 ) );
$GLOBALS['TACK_CAPS'] = array();

// Grouped buyers.
tack_test_set_logged_in( true, 'buyer@example.test' );
$v = new Tack_Catalog_Visibility( new Tack_Test_Group_Source( array( 'name' => 'Tier 3', 'code' => 'tier3' ) ) );
check( 'TIER3 (lower-case answer): only category 20 hidden', array( 20 ) === $v->hidden_term_ids() );
$v = new Tack_Catalog_Visibility( new Tack_Test_Group_Source( array( 'name' => 'Gold', 'code' => 'GOLD' ) ) );
check( 'a group with no rule sees everything', array() === $v->hidden_term_ids() );
$v = new Tack_Catalog_Visibility( new Tack_Test_Group_Source( null, 'none' ) );
check( 'signed in, in no group: treated like a guest', in_array( 10, $v->hidden_term_ids(), true ) );
$v = new Tack_Catalog_Visibility( new Tack_Test_Group_Source( null, 'anonymous' ) );
check( 'signed in with an unconfirmed self-changed email (anonymous): treated like a guest', in_array( 10, $v->hidden_term_ids(), true ) );

// Unknown group: show by default, hide everything ruled when the merchant asks.
$v = new Tack_Catalog_Visibility( new Tack_Test_Group_Source( null, 'unavailable' ) );
check( 'outage: shows everything by default', array() === $v->hidden_term_ids() );
tack_test_set_option( Tack_Catalog_Visibility::OPTION_HIDE_WHEN_UNKNOWN, 'yes' );
$v      = new Tack_Catalog_Visibility( new Tack_Test_Group_Source( null, 'unavailable' ) );
$hidden = $v->hidden_term_ids();
sort( $hidden );
check( 'outage + "hide when unknown": every ruled category hidden', array( 10, 11, 20 ) === $hidden, implode( ',', $hidden ) );
tack_test_set_option( Tack_Catalog_Visibility::OPTION_HIDE_WHEN_UNKNOWN, 'no' );

// Store API single-product routes (by id, by slug, versioned or not, in a batch).
if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $t ) { return strtolower( preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $t ) ); }
}
if ( ! function_exists( 'get_page_by_path' ) ) {
	function get_page_by_path( $slug, $output = 'OBJECT', $type = 'page' ) {
		$map = array( 'trade-pack' => 100, 'retail-pack' => 200 );
		return isset( $map[ $slug ] ) ? (object) array( 'ID' => $map[ $slug ] ) : null;
	}
}
if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( $args = array() ) {
		$GLOBALS['TACK_LAST_GET_POSTS'] = $args;
		return 'trade-pack-blue' === ( $args['name'] ?? '' ) ? array( 101 ) : array();
	}
}
if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}
class Tack_Test_Rest_Request {
	private $route;
	public function __construct( $route ) { $this->route = $route; }
	public function get_route() { return $this->route; }
}
tack_test_set_logged_in( false, '' );
$v   = new Tack_Catalog_Visibility( new Tack_Test_Group_Source( null, 'anonymous' ) );
$err = $v->guard_store_api_product( null, null, new Tack_Test_Rest_Request( '/wc/store/v1/products/100' ) );
check(
	'Store API by id: a hidden product answers the route\'s own 404',
	$err instanceof WP_Error && 'woocommerce_rest_product_invalid_id' === $err->get_error_code() && array( 'status' => 404 ) === $err->get_error_data()
);
$err = $v->guard_store_api_product( null, null, new Tack_Test_Rest_Request( '/wc/store/products/trade-pack' ) );
check( 'Store API by slug (unversioned namespace): 404 invalid slug', $err instanceof WP_Error && 'woocommerce_rest_product_invalid_slug' === $err->get_error_code() );
$err = $v->guard_store_api_product( null, null, new Tack_Test_Rest_Request( '/wc/store/v1/products/trade-pack-blue' ) );
check( 'Store API by variation slug: follows the parent', $err instanceof WP_Error );
check( 'variation slug lookup: no explicit suppress_filters (Plugin Check; get_posts() defaults it)', isset( $GLOBALS['TACK_LAST_GET_POSTS']['name'] ) && ! array_key_exists( 'suppress_filters', $GLOBALS['TACK_LAST_GET_POSTS'] ) );
check( 'Store API: a visible product passes through', null === $v->guard_store_api_product( null, null, new Tack_Test_Rest_Request( '/wc/store/v1/products/200' ) ) );
check( 'Store API: the categories listing is not mistaken for a slug', null === $v->guard_store_api_product( null, null, new Tack_Test_Rest_Request( '/wc/store/v1/products/categories' ) ) );
check( 'the admin REST API is not touched', null === $v->guard_store_api_product( null, null, new Tack_Test_Rest_Request( '/wc/v3/products/100' ) ) );
check( 'an earlier short-circuit is respected', 'x' === $v->guard_store_api_product( 'x', null, new Tack_Test_Rest_Request( '/wc/store/v1/products/100' ) ) );

// Background contexts are never filtered (one gate: shopper_context()).
if ( ! function_exists( 'wp_doing_cron' ) ) {
	function wp_doing_cron() { return ! empty( $GLOBALS['TACK_DOING_CRON'] ); }
}
tack_test_set_logged_in( false, '' );
$GLOBALS['TACK_DOING_CRON'] = true;
$v                          = new Tack_Catalog_Visibility( new Tack_Test_Group_Source( null, 'anonymous' ) );
$q                          = new Tack_Test_Query( array( 'post_type' => 'product' ), array( 'main' => true ) );
$v->filter_query( $q );
check( 'cron / Action Scheduler: no tax_query is added', '' === $q->get( 'tax_query' ) );
check( 'cron: a guest-hidden product stays purchasable and visible', true === $v->filter_purchasable( true, new Tack_Test_Visibility_Product( 100 ) ) && true === $v->filter_is_visible( true, 100 ) );
check( 'cron: the Store API route guard passes through', null === $v->guard_store_api_product( null, null, new Tack_Test_Rest_Request( '/wc/store/v1/products/100' ) ) );
$cron_cart = new Tack_Stub_Cart( array( 'a' => array( 'data' => new Tack_Test_Visibility_Product( 100 ) ) ) );
check( 'cron: cart lines are not removed', 0 === $v->remove_hidden_cart_items( $cron_cart ) );
$GLOBALS['TACK_DOING_CRON'] = false;
$q                          = new Tack_Test_Query( array( 'post_type' => 'product' ), array( 'main' => true ) );
$v->filter_query( $q );
check( 'control: the same guest query outside cron IS filtered', is_array( $q->get( 'tax_query' ) ) );

// WP-CLI is a constant, so it is probed in a child process with WP_CLI defined.
$tack_probe = shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/catalog-visibility-cli-probe.php' ) );
$tack_probe = json_decode( trim( (string) $tack_probe ), true );
check(
	'WP-CLI: no tax_query is added and the context gate is closed',
	is_array( $tack_probe ) && false === $tack_probe['shopper_context'] && false === $tack_probe['tax_query_added'],
	var_export( $tack_probe, true )
);

// Off by default; init registers the documented hooks.
tack_test_set_option( Tack_Catalog_Visibility::OPTION_ENABLED, null );
unset( $GLOBALS['TACK_OPTIONS'][ Tack_Catalog_Visibility::OPTION_ENABLED ] );
check( 'catalogue visibility is off by default', false === Tack_Catalog_Visibility::is_enabled() );
$tack_hooks_before   = count( $GLOBALS['TACK_HOOKS'] );
$tack_filters_before = $GLOBALS['TACK_FILTERS'];
( new Tack_Catalog_Visibility( new Tack_Test_Group_Source( null ) ) )->init();
// The stub dispatches what init() registered: put the dispatch table back.
$GLOBALS['TACK_FILTERS'] = $tack_filters_before;
$hooks = array_column( array_slice( $GLOBALS['TACK_HOOKS'], $tack_hooks_before ), 'hook' );
foreach ( array( 'pre_get_posts', 'woocommerce_product_is_visible', 'woocommerce_is_purchasable', 'woocommerce_variation_is_purchasable', 'woocommerce_related_products', 'template_redirect', 'woocommerce_cart_loaded_from_session', 'rest_pre_dispatch' ) as $hook ) {
	check( "visibility hooks $hook", in_array( $hook, $hooks, true ) );
}

echo "\n-- 1.10.0 shipping discounts per buyer group --\n";

tack_test_set_option( Tack_Group_Restrictions::OPTION_ENABLED, 'yes' );
tack_test_set_option( Tack_Group_Restrictions::OPTION_DISCOUNTS_ENABLED, 'yes' );
tack_test_set_option( Tack_Group_Restrictions::OPTION_SHIPPING_MAP, '' );
tack_test_set_option(
	Tack_Group_Restrictions::OPTION_DISCOUNT_MAP,
	"TIER3: free | flat_rate\nTIER2: percent=25 | flat_rate\nGOLD: free_only\nSILVER: percent=150"
);

$rates_for = function () {
	return array(
		'flat_rate:1'     => new Tack_Test_Rate( 'flat_rate', '20.00', array( 1 => 4.0, 2 => 1.0 ) ),
		'local_pickup:2'  => new Tack_Test_Rate( 'local_pickup', '5.00', array( 1 => 1.0 ) ),
		'free_shipping:3' => new Tack_Test_Rate( 'free_shipping', '0', array() ),
	);
};

$r   = new Tack_Group_Restrictions( new Tack_Test_Group_Source( array( 'name' => 'Tier 3', 'code' => 'TIER3' ) ) );
$out = $r->filter_package_rates( $rates_for() );
check( 'free: the chosen method costs 0 and its taxes are 0', '0.00' === $out['flat_rate:1']->get_cost() && array( 1 => 0, 2 => 0 ) === $out['flat_rate:1']->get_taxes() );
check( 'free: other methods keep their price', '5.00' === $out['local_pickup:2']->get_cost() && array( 1 => 1.0 ) === $out['local_pickup:2']->get_taxes() );

$r   = new Tack_Group_Restrictions( new Tack_Test_Group_Source( array( 'name' => 'Tier 2', 'code' => 'TIER2' ) ) );
$out = $r->filter_package_rates( $rates_for() );
check( 'percent: 25% off 20.00 is 15.00', '15.00' === $out['flat_rate:1']->get_cost(), (string) $out['flat_rate:1']->get_cost() );
check( 'percent: each tax scales with the cost (4.00 -> 3.00, 1.00 -> 0.75)', abs( $out['flat_rate:1']->get_taxes()[1] - 3.0 ) < 1e-9 && abs( $out['flat_rate:1']->get_taxes()[2] - 0.75 ) < 1e-9 );

$r   = new Tack_Group_Restrictions( new Tack_Test_Group_Source( array( 'name' => 'Gold', 'code' => 'GOLD' ) ) );
$out = $r->filter_package_rates( $rates_for() );
check( 'free_only: only the rate that costs 0 is kept', array( 'free_shipping:3' ) === array_keys( $out ) );
$no_free = $rates_for();
unset( $no_free['free_shipping:3'] );
$out = $r->filter_package_rates( $no_free );
check( 'free_only with no free rate: nothing is removed', 2 === count( $out ) );

$r   = new Tack_Group_Restrictions( new Tack_Test_Group_Source( array( 'name' => 'Silver', 'code' => 'SILVER' ) ) );
$out = $r->filter_package_rates( $rates_for() );
check( 'a percentage above 100 is ignored, not applied', '20.00' === $out['flat_rate:1']->get_cost() );

foreach ( array( 'none', 'anonymous', 'unavailable' ) as $status ) {
	$r   = new Tack_Group_Restrictions( new Tack_Test_Group_Source( null, $status ) );
	$out = $r->filter_package_rates( $rates_for() );
	check( "no discount for a buyer whose group status is $status", '20.00' === $out['flat_rate:1']->get_cost() );
}

// Restrictions FIRST, then discounts: "free_only" must see the list AFTER the
// restriction removed free shipping from TIER2-only... here GOLD may not use it.
tack_test_set_option( Tack_Group_Restrictions::OPTION_SHIPPING_MAP, 'free_shipping: TIER3' );
$r   = new Tack_Group_Restrictions( new Tack_Test_Group_Source( array( 'name' => 'Gold', 'code' => 'GOLD' ) ) );
$out = $r->filter_package_rates( $rates_for() );
check(
	'order: restrictions run before discounts (GOLD may not use free_shipping, so free_only keeps the paid rates)',
	! isset( $out['free_shipping:3'] ) && 2 === count( $out ),
	implode( ',', array_keys( $out ) )
);
tack_test_set_option( Tack_Group_Restrictions::OPTION_SHIPPING_MAP, '' );

// Discounts off: nothing changes.
tack_test_set_option( Tack_Group_Restrictions::OPTION_DISCOUNTS_ENABLED, 'no' );
$r   = new Tack_Group_Restrictions( new Tack_Test_Group_Source( array( 'name' => 'Tier 3', 'code' => 'TIER3' ) ) );
$out = $r->filter_package_rates( $rates_for() );
check( 'discounts switched off: rates untouched', '20.00' === $out['flat_rate:1']->get_cost() );
tack_test_set_option( Tack_Group_Restrictions::OPTION_DISCOUNTS_ENABLED, 'yes' );

// The package carries the group, so WooCommerce's rate cache follows a group change.
$r        = new Tack_Group_Restrictions( new Tack_Test_Group_Source( array( 'name' => 'Tier 3', 'code' => 'TIER3' ) ) );
$tagged3  = $r->tag_packages( array( array( 'contents' => array() ) ) );
$r        = new Tack_Group_Restrictions( new Tack_Test_Group_Source( array( 'name' => 'Tier 2', 'code' => 'TIER2' ) ) );
$tagged2  = $r->tag_packages( array( array( 'contents' => array() ) ) );
check(
	'package hash input differs per group',
	isset( $tagged3[0][ Tack_Group_Restrictions::PACKAGE_KEY ] ) && $tagged3[0][ Tack_Group_Restrictions::PACKAGE_KEY ] !== $tagged2[0][ Tack_Group_Restrictions::PACKAGE_KEY ]
);

// Round trip of the stored line format.
$parsed = $r->parse_discount_map( Tack_Group_Restrictions::format_discount_line( 'TIER2', array( 'mode' => 'percent', 'percent' => 12.5, 'methods' => array( 'flat_rate', 'flat_rate:3' ) ) ) );
check( 'discount line round-trips', isset( $parsed['TIER2'] ) && 12.5 === $parsed['TIER2']['percent'] && array( 'flat_rate', 'flat_rate:3' ) === $parsed['TIER2']['methods'] );

// Settings sanitizer: only rendered codes rewritten; out-of-range percent dropped.
tack_test_set_option( Tack_Group_Restrictions::OPTION_DISCOUNT_MAP, "# keep me\nOLD: free\nTIER2: free" );
$settings = new Tack_Settings();
$saved    = $settings->sanitize_shipping_discount_map(
	array(
		'mode'     => 'matrix',
		'rendered' => array( 'TIER2', 'TIER3' ),
		'rules'    => array(
			'TIER2' => array( 'mode' => 'percent', 'percent' => '10', 'methods' => array( 'flat_rate' ) ),
			'TIER3' => array( 'mode' => 'percent', 'percent' => '500' ),
		),
	)
);
check( 'discount sanitizer keeps other lines and rewrites rendered codes', "# keep me\nOLD: free\nTIER2: percent=10 | flat_rate" === $saved, $saved );
check( 'discount sanitizer keeps the stored rules when the field posts nothing', "# keep me\nOLD: free\nTIER2: free" === $settings->sanitize_shipping_discount_map( null ) );

echo "\n-- 1.10.0 WordPress role mirror --\n";

tack_test_reset_user_meta();
$GLOBALS['TACK_WP_ROLES_DEFS'] = array();
unset( $GLOBALS['TACK_OPTIONS'][ Tack_Role_Mirror::OPTION_ENABLED ] );
check( 'role mirror is off by default', false === Tack_Role_Mirror::is_enabled() );

$user = new Tack_Test_User( array( 'customer', 'tackquote_vip' ) ); // tackquote_vip assigned by hand.
$m    = new Tack_Role_Mirror( new Tack_Test_Group_Source( array( 'name' => 'Tier 2', 'code' => 'TIER2' ) ) );
check( 'grouped: role added', 'added' === $m->sync_user( $user ) && in_array( 'tackquote_tier2', $user->roles, true ) );
check( 'the role is created with read only', array( 'read' => true ) === ( $GLOBALS['TACK_WP_ROLES_DEFS']['tackquote_tier2'] ?? null ) );

$m = new Tack_Role_Mirror( new Tack_Test_Group_Source( array( 'name' => 'Tier 3', 'code' => 'TIER3' ) ) );
$m->sync_user( $user );
check( 'group change: old mirrored role removed, new one added', ! in_array( 'tackquote_tier2', $user->roles, true ) && in_array( 'tackquote_tier3', $user->roles, true ) );

$m = new Tack_Role_Mirror( new Tack_Test_Group_Source( null, 'unavailable' ) );
check( 'outage: nothing changes', 'outage' === $m->sync_user( $user ) && in_array( 'tackquote_tier3', $user->roles, true ) );

$m = new Tack_Role_Mirror( new Tack_Test_Group_Source( null, 'none' ) );
$m->sync_user( $user );
check( 'no group: the mirrored role is removed', ! in_array( 'tackquote_tier3', $user->roles, true ) );
check( 'roles the plugin did not add are never removed', in_array( 'customer', $user->roles, true ) && in_array( 'tackquote_vip', $user->roles, true ) );

// A hand-assigned role equal to the desired one is not adopted, so not removed later.
$user = new Tack_Test_User( array( 'customer', 'tackquote_gold' ) );
tack_test_reset_user_meta();
( new Tack_Role_Mirror( new Tack_Test_Group_Source( array( 'name' => 'Gold', 'code' => 'GOLD' ) ) ) )->sync_user( $user );
( new Tack_Role_Mirror( new Tack_Test_Group_Source( null, 'none' ) ) )->sync_user( $user );
check( 'a hand-assigned tackquote_ role matching the group is kept when the group goes', in_array( 'tackquote_gold', $user->roles, true ) );

// Leave the shared fixtures as later files expect them.
foreach ( array(
	Tack_Group_Restrictions::OPTION_ENABLED,
	Tack_Group_Restrictions::OPTION_DISCOUNTS_ENABLED,
	Tack_Group_Restrictions::OPTION_DISCOUNT_MAP,
	Tack_Group_Restrictions::OPTION_SHIPPING_MAP,
	Tack_Catalog_Visibility::OPTION_MAP,
	Tack_Catalog_Visibility::OPTION_HIDE_WHEN_UNKNOWN,
) as $tack_opt ) {
	unset( $GLOBALS['TACK_OPTIONS'][ $tack_opt ] );
}
tack_test_set_logged_in( false, '' );
tack_test_reset_user_meta();
tack_test_reset_notices();
$GLOBALS['TACK_CART_REMOVED'] = array();
$wp_query                     = null;
