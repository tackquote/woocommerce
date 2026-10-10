<?php
/**
 * 1.9.0 storefront parity: product-card and cart-page quote controls, the
 * launcher settings, the quote page, list re-pricing, per-product quote-only
 * (with its Store API hook), the approved-wholesale price gate, and the Store
 * API cart-errors twin of the order-limit check.
 *
 * Three properties carry the weight here, and each has a mutant in the PR:
 *
 *   · Every new storefront control is OFF by default. An update must not add a
 *     button to a merchant's shop that they never asked for.
 *   · A per-product "Quote only" is enforced at the data layer
 *     (`woocommerce_is_purchasable`), not just hidden.
 *   · The order-limit refusal reaches the Cart and Checkout BLOCKS through
 *     `woocommerce_store_api_cart_errors`, not only the classic hook.
 *
 * Included by tests/run.php. Uses check() from there.
 *
 * @package TackQuotes
 */

defined( 'ABSPATH' ) || exit;

// ── Stubs this file needs and no earlier test defined ───────────────────────
$GLOBALS['TACK_TRANSIENTS']   = array();
$GLOBALS['TACK_TEST_PRODUCTS'] = array();
$GLOBALS['TACK_BLOCK_THEME']  = false;
$GLOBALS['TACK_IS_CART']      = false;
$GLOBALS['TACK_IS_PRODUCT']   = false;

if ( ! function_exists( 'get_transient' ) ) {
	/** @param string $k Key. @return mixed */
	function get_transient( $k ) {
		return array_key_exists( $k, $GLOBALS['TACK_TRANSIENTS'] ) ? $GLOBALS['TACK_TRANSIENTS'][ $k ] : false;
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	/** @param string $k Key. @param mixed $v Value. @param int $ttl TTL. @return bool */
	function set_transient( $k, $v, $ttl = 0 ) {
		$GLOBALS['TACK_TRANSIENTS'][ $k ] = $v;
		return true;
	}
}
if ( ! function_exists( 'wp_hash' ) ) {
	/** @param string $d Data. @return string */
	function wp_hash( $d ) {
		return md5( 'salt' . $d );
	}
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	/** @return int */
	function get_current_user_id() {
		return $GLOBALS['TACK_LOGGED_IN'] ? 1 : 0;
	}
}
if ( ! function_exists( 'wc_get_product' ) ) {
	/** @param int $id Id. @return object|false */
	function wc_get_product( $id ) {
		return isset( $GLOBALS['TACK_TEST_PRODUCTS'][ (int) $id ] ) ? $GLOBALS['TACK_TEST_PRODUCTS'][ (int) $id ] : false;
	}
}
if ( ! function_exists( 'get_permalink' ) ) {
	/** @param int $id Id. @return string */
	function get_permalink( $id ) {
		return 'https://shop.example/product/' . (int) $id . '/';
	}
}
if ( ! function_exists( 'wc_get_price_excluding_tax' ) ) {
	/** @param object $p Product. @return float */
	function wc_get_price_excluding_tax( $p ) {
		return isset( $p->price ) ? (float) $p->price : 0.0;
	}
}
if ( ! function_exists( 'wp_is_block_theme' ) ) {
	/** @return bool */
	function wp_is_block_theme() {
		return (bool) $GLOBALS['TACK_BLOCK_THEME'];
	}
}
if ( ! function_exists( 'is_cart' ) ) {
	/** @return bool */
	function is_cart() {
		return (bool) $GLOBALS['TACK_IS_CART'];
	}
}
if ( ! function_exists( 'is_product' ) ) {
	/** @return bool */
	function is_product() {
		return (bool) $GLOBALS['TACK_IS_PRODUCT'];
	}
}
if ( ! function_exists( 'shortcode_atts' ) ) {
	/** @param array $pairs Defaults. @param array $atts Given. @return array */
	function shortcode_atts( $pairs, $atts ) {
		$out = array();
		foreach ( $pairs as $k => $v ) {
			$out[ $k ] = array_key_exists( $k, $atts ) ? $atts[ $k ] : $v;
		}
		return $out;
	}
}
if ( ! function_exists( 'esc_html_e' ) ) {
	/** @param string $t Text. */
	function esc_html_e( $t ) {
		echo $t; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- test stub.
	}
}
if ( ! function_exists( 'esc_attr_e' ) ) {
	/** @param string $t Text. */
	function esc_attr_e( $t ) {
		echo $t; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- test stub.
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	/** @param mixed $v Value. @return mixed */
	function wp_unslash( $v ) {
		return $v;
	}
}
if ( ! function_exists( 'wp_verify_nonce' ) ) {
	/** @param string $n Nonce. @param string $a Action. @return int|false */
	function wp_verify_nonce( $n, $a ) {
		return ( 'good' === $n && 'woocommerce_save_data' === $a ) ? 1 : false;
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	/** @param string $u URL. @param array $p Protocols. @return string */
	function esc_url_raw( $u, $p = array() ) {
		$scheme = strtolower( (string) wp_parse_url_scheme( $u ) );
		return in_array( $scheme, (array) $p, true ) ? $u : '';
	}
	/** @param string $u URL. @return string */
	function wp_parse_url_scheme( $u ) {
		$s = parse_url( $u, PHP_URL_SCHEME ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- test stub.
		return is_string( $s ) ? $s : '';
	}
}

/** A product double with exactly the methods the code under test calls. */
class Tack_Parity_Product extends WC_Product {
	/** @var int */
	public $id;
	/** @var string */
	public $type;
	/** @var string */
	public $name;
	/** @var string */
	public $psku;
	/** @var float */
	public $price;
	/** @var int */
	public $parent_id;
	/** @var array */
	public $meta = array();

	/**
	 * @param int    $id        Id.
	 * @param string $type      simple|variable|variation.
	 * @param string $name      Name.
	 * @param string $sku       SKU.
	 * @param float  $price     Price excl. tax.
	 * @param int    $parent_id Parent id (variations).
	 */
	public function __construct( $id, $type, $name, $sku = '', $price = 0.0, $parent_id = 0 ) {
		parent::__construct( $sku );
		$this->id        = $id;
		$this->type      = $type;
		$this->name      = $name;
		$this->psku      = $sku;
		$this->price     = $price;
		$this->parent_id = $parent_id;
	}
	/** @return int */
	public function get_id() {
		return $this->id;
	}
	/** @return int */
	public function get_parent_id() {
		return $this->parent_id;
	}
	/** @return string */
	public function get_name() {
		return $this->name;
	}
	/** @return string */
	public function get_sku() {
		return $this->psku;
	}
	/** @param string $t Type. @return bool */
	public function is_type( $t ) {
		return $t === $this->type;
	}
	/** @return bool */
	public function is_purchasable() {
		return (bool) apply_filters( 'woocommerce_is_purchasable', true, $this );
	}
	/** @param string $k Key. @param bool $s Single. @return mixed */
	public function get_meta( $k, $s = true ) {
		return isset( $this->meta[ $k ] ) ? $this->meta[ $k ] : '';
	}
	/** @param string $k Key. @param mixed $v Value. */
	public function update_meta_data( $k, $v ) {
		$this->meta[ $k ] = $v;
	}
	/** @param string $k Key. */
	public function delete_meta_data( $k ) {
		unset( $this->meta[ $k ] );
	}
}

/** Collects `add()` calls the way the Store API's WP_Error collector does. */
class Tack_Test_Error_Collector {
	/** @var array<int, array{0:string,1:string}> */
	public $errors = array();
	/** @param string $code Code. @param string $message Message. */
	public function add( $code, $message ) {
		$this->errors[] = array( $code, $message );
	}
}

/** An API client whose storefront read is scripted and recorded. */
class Tack_Test_Storefront_Client extends Tack_Api_Client {
	/** @var mixed */
	public $answer;
	/** @var array */
	public $calls = array();
	/** @param mixed $answer Response. */
	public function __construct( $answer ) {
		$this->answer = $answer;
	}
	/** @param string $email Buyer email. @return mixed */
	public function get_price_access( $email ) {
		$this->calls[] = $email;
		return $this->answer;
	}
}

/** Fresh visitor and options for each block. */
function tack_parity_reset() {
	$GLOBALS['TACK_LOGGED_IN']    = false;
	$GLOBALS['TACK_CAPS']         = array();
	$GLOBALS['TACK_ROLES']        = array();
	$GLOBALS['TACK_OPTIONS']      = array();
	$GLOBALS['TACK_HOOKS']        = array();
	$GLOBALS['TACK_FILTERS']      = array();
	$GLOBALS['TACK_SHORTCODES']   = array();
	$GLOBALS['TACK_TRANSIENTS']   = array();
	$GLOBALS['TACK_NOTICES']      = array();
	$GLOBALS['TACK_CART_LINES']   = array();
	$GLOBALS['TACK_CART_REMOVED'] = array();
	$GLOBALS['TACK_BLOCK_THEME']  = false;
	$GLOBALS['TACK_IS_CART']      = false;
	$GLOBALS['TACK_IS_PRODUCT']   = false;
	$GLOBALS['TACK_USER_EMAIL']   = '';
}

/**
 * Was this hook registered (optionally at this priority)?
 *
 * @param string   $hook     Hook.
 * @param int|null $priority Priority.
 * @return bool
 */
function tack_parity_hooked( $hook, $priority = null ) {
	foreach ( $GLOBALS['TACK_HOOKS'] as $h ) {
		if ( $h['hook'] === $hook && ( null === $priority || (int) $h['priority'] === (int) $priority ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Capture what a callable prints.
 *
 * @param callable $fn Callable.
 * @return string
 */
function tack_parity_capture( $fn ) {
	ob_start();
	$fn();
	return (string) ob_get_clean();
}

/**
 * Call a private method.
 *
 * @param object $obj    Object.
 * @param string $method Method.
 * @param array  $args   Args.
 * @return mixed
 */
function tack_parity_call( $obj, $method, $args = array() ) {
	$m = new ReflectionMethod( $obj, $method );
	$m->setAccessible( true );
	return $m->invokeArgs( $obj, $args );
}

$simple    = new Tack_Parity_Product( 11, 'simple', 'Safety Gloves', 'SG-100', 4.5 );
$variable  = new Tack_Parity_Product( 20, 'variable', 'Work Boots', 'WB', 50.0 );
$variation = new Tack_Parity_Product( 21, 'variation', 'Work Boots - 44', 'WB-44', 52.0, 20 );
$GLOBALS['TACK_TEST_PRODUCTS'] = array(
	11 => $simple,
	20 => $variable,
	21 => $variation,
);

// ── Widget hook registration ────────────────────────────────────────────────
tack_parity_reset();
$widget = new Tack_Widget();
$widget->init();
check( 'card button hooked after WooCommerce loop button (after_shop_loop_item @11)', tack_parity_hooked( 'woocommerce_after_shop_loop_item', 11 ) );
check( 'cart button hooked under Proceed (proceed_to_checkout @25)', tack_parity_hooked( 'woocommerce_proceed_to_checkout', 25 ) );
check( 'quote page shortcode [tackquote_quote_page] is registered', isset( $GLOBALS['TACK_SHORTCODES']['tackquote_quote_page'] ) );
check( 're-pricing route exists for signed-in buyers', tack_parity_hooked( 'wp_ajax_tack_quote_reprice' ) );
check( 're-pricing has NO guest (nopriv) route', ! tack_parity_hooked( 'wp_ajax_nopriv_tack_quote_reprice' ) );

// ── Defaults: nothing new appears until the merchant opts in ────────────────
tack_parity_reset();
$GLOBALS['product'] = $simple;
$out                = tack_parity_capture( array( $widget, 'render_card_button' ) );
check( 'DEFAULT OFF: no card button on a default install', '' === $out, $out );

$GLOBALS['TACK_CART_LINES'] = array(
	'k1' => array(
		'product_id'   => 11,
		'variation_id' => 0,
		'quantity'     => 3,
		'data'         => $simple,
	),
);
$GLOBALS['TACK_IS_CART']    = true;
$w2                         = new Tack_Widget();
$out                        = tack_parity_capture( array( $w2, 'render_cart_quote_button' ) ) . tack_parity_capture( array( $w2, 'render_cart_quote_button_footer' ) );
check( 'DEFAULT OFF: no cart-page quote button on a default install', '' === $out, $out );

$fab = Tack_Widget::fab_settings();
check( 'launcher defaults reproduce 1.8.x (bottom right, 20/20, all pages, regular, count shown)', Tack_Widget::fab_defaults() === $fab, var_export( $fab, true ) );
check( 'quote button opens the drawer by default', 'drawer' === Tack_Widget::opens() );
$out = tack_parity_capture( array( new Tack_Widget(), 'render_quote_list_drawer' ) );
check( 'default launcher carries no layout modifier class', false !== strpos( $out, 'class="tack-quote-list-widget"' ), $out );
check( 'default launcher sits 20px from each edge', false !== strpos( $out, '--tack-fab-x:20px;--tack-fab-y:20px' ) );
check( 'default launcher still says "Quote list"', false !== strpos( $out, 'Quote list' ) );

// ── Product cards, switched on ──────────────────────────────────────────────
tack_parity_reset();
$GLOBALS['TACK_OPTIONS'][ Tack_Widget::OPT_CARD_BUTTONS ] = 'yes';
$GLOBALS['product'] = $simple;
$out                = tack_parity_capture( array( $widget, 'render_card_button' ) );
check( 'a simple product card gets an Add to Quote button', false !== strpos( $out, 'tack-card-quote-btn' ) && false !== strpos( $out, 'data-product-id="11"' ), $out );
check( 'classic theme: the card button wears WooCommerce\'s .button', false !== strpos( $out, 'class="button tack-card-quote-btn"' ), $out );

$GLOBALS['product'] = $variable;
$out                = tack_parity_capture( array( $widget, 'render_card_button' ) );
check( 'a variable product card LINKS to its page instead of quoting the parent', false !== strpos( $out, '<a href="' . get_permalink( 20 ) . '"' ) && false === strpos( $out, 'tack-card-quote-btn' ), $out );

$GLOBALS['TACK_BLOCK_THEME'] = true;
check( 'block theme: injected controls add wp-element-button', 'button wp-element-button x' === Tack_Widget::button_class( 'x' ) );
$GLOBALS['TACK_BLOCK_THEME'] = false;
check( 'classic theme: injected controls are plain .button', 'button x' === Tack_Widget::button_class( 'x' ) );

// ── Cart page, switched on ──────────────────────────────────────────────────
tack_parity_reset();
$GLOBALS['TACK_OPTIONS'][ Tack_Widget::OPT_CART_BUTTON ] = 'yes';
$GLOBALS['TACK_CART_LINES'] = array(
	'k1' => array(
		'product_id'   => 20,
		'variation_id' => 21,
		'quantity'     => 2,
		'data'         => $variation,
	),
);
$GLOBALS['TACK_IS_CART'] = true;
$w3                      = new Tack_Widget();
$classic                 = tack_parity_capture( array( $w3, 'render_cart_quote_button' ) );
$footer                  = tack_parity_capture( array( $w3, 'render_cart_quote_button_footer' ) );
check( 'classic cart renders the cart quote button', false !== strpos( $classic, 'tack-quote-cart-btn' ), $classic );
check( 'the button carries the cart lines with the variation id and quantity', false !== strpos( html_entity_decode( $classic, ENT_QUOTES ), '"variation_id":21' ) && false !== strpos( html_entity_decode( $classic, ENT_QUOTES ), '"quantity":2' ), $classic );
check( 'the Cart-block footer fallback does not render a SECOND button', '' === $footer, $footer );

$w4     = new Tack_Widget();
$footer = tack_parity_capture( array( $w4, 'render_cart_quote_button_footer' ) );
check( 'Cart block (classic hook never fired): footer renders a fixed button on the cart page', false !== strpos( $footer, 'tack-quote-cart-fixed' ) && false !== strpos( $footer, 'tack-quote-cart-btn' ), $footer );

$GLOBALS['TACK_IS_CART'] = false;
$footer                  = tack_parity_capture( array( new Tack_Widget(), 'render_cart_quote_button_footer' ) );
check( 'the footer fallback stays off every page that is not the cart', '' === $footer, $footer );

$GLOBALS['TACK_IS_CART']    = true;
$GLOBALS['TACK_CART_LINES'] = array();
$footer                     = tack_parity_capture( array( new Tack_Widget(), 'render_cart_quote_button_footer' ) );
check( 'an empty cart gets no cart quote button', '' === $footer, $footer );

// ── Launcher settings ───────────────────────────────────────────────────────
tack_parity_reset();
$GLOBALS['TACK_OPTIONS'][ Tack_Widget::OPT_FAB_POSITION ]    = 'bottom-left';
$GLOBALS['TACK_OPTIONS'][ Tack_Widget::OPT_FAB_OFFSET_X ]    = '32';
$GLOBALS['TACK_OPTIONS'][ Tack_Widget::OPT_FAB_OFFSET_Y ]    = '999';
$GLOBALS['TACK_OPTIONS'][ Tack_Widget::OPT_FAB_SIZE ]        = 'compact';
$GLOBALS['TACK_OPTIONS'][ Tack_Widget::OPT_FAB_ICON_ONLY ]   = 'yes';
$GLOBALS['TACK_OPTIONS'][ Tack_Widget::OPT_FAB_SHOW_COUNT ]  = 'no';
$GLOBALS['TACK_OPTIONS'][ Tack_Widget::OPT_FAB_HIDE_MOBILE ] = 'yes';
$GLOBALS['TACK_OPTIONS'][ Tack_Widget::OPT_FAB_LABEL ]       = 'My quote';
$out = tack_parity_capture( array( new Tack_Widget(), 'render_quote_list_drawer' ) );
foreach ( array( 'tack-fab-left', 'tack-fab-compact', 'tack-fab-icon-only', 'tack-fab-no-count', 'tack-fab-hide-mobile' ) as $cls ) {
	check( "launcher setting renders class $cls", false !== strpos( $out, $cls ), $out );
}
check( 'an out-of-range offset falls back to 20px, an in-range one is kept', false !== strpos( $out, '--tack-fab-x:32px;--tack-fab-y:20px' ), $out );
check( 'the launcher label is the merchant\'s', false !== strpos( $out, 'aria-label="My quote"' ), $out );

$GLOBALS['TACK_OPTIONS'][ Tack_Widget::OPT_FAB_PAGES ] = 'none';
check( 'Show on: nowhere prints no launcher', '' === tack_parity_capture( array( new Tack_Widget(), 'render_quote_list_drawer' ) ) );
$GLOBALS['TACK_OPTIONS'][ Tack_Widget::OPT_FAB_PAGES ] = 'product';
check( 'Show on: product pages only, off a product page prints nothing', '' === tack_parity_capture( array( new Tack_Widget(), 'render_quote_list_drawer' ) ) );
$GLOBALS['TACK_IS_PRODUCT'] = true;
check( 'Show on: product pages only, on a product page prints the launcher', '' !== tack_parity_capture( array( new Tack_Widget(), 'render_quote_list_drawer' ) ) );

// ── Drawer or page ──────────────────────────────────────────────────────────
tack_parity_reset();
$GLOBALS['TACK_OPTIONS'][ Tack_Widget::OPT_OPENS ] = 'page';
check( '"page" without an address still opens the drawer (never a dead launcher)', 'drawer' === Tack_Widget::opens() );
$GLOBALS['TACK_OPTIONS'][ Tack_Widget::OPT_PAGE_URL ] = 'https://shop.example/quote/';
check( '"page" with an address opens the page', 'page' === Tack_Widget::opens() );
$out = tack_parity_capture( array( new Tack_Widget(), 'render_quote_list_drawer' ) );
check( 'the launcher carries the page address', false !== strpos( $out, 'data-href="https://shop.example/quote/"' ), $out );

// ── Quote page shortcode ────────────────────────────────────────────────────
$html = $widget->render_quote_page( array() );
check( 'the quote page renders its shell', false !== strpos( $html, 'id="tack-quote-page"' ) && false !== strpos( $html, 'tack-quote-page-col-target' ) && false !== strpos( $html, 'tack-quote-page-message' ), $html );
$html = $widget->render_quote_page(
	array(
		'target_price' => 'no',
		'message'      => 'no',
	)
);
check( 'target_price="no" message="no" drops both', false === strpos( $html, 'tack-quote-page-col-target' ) && false === strpos( $html, 'tack-quote-page-message' ), $html );

// ── Target prices travel in the note ────────────────────────────────────────
$rows = tack_parity_call(
	$widget,
	'decode_rows',
	array(
		wp_json_encode(
			array(
				array(
					'product_id'   => 11,
					'quantity'     => 5,
					'target_price' => '3.25',
				),
				array(
					'product_id'   => 11,
					'quantity'     => 1,
					'target_price' => -2,
				),
				array(
					'product_id'   => 11,
					'quantity'     => 1,
					'target_price' => 'cheap',
				),
			)
		),
	)
);
check( 'a numeric target price is accepted', 3.25 === $rows[0]['target_price'], var_export( $rows, true ) );
check( 'a negative or non-numeric target price is dropped, not sent', null === $rows[1]['target_price'] && null === $rows[2]['target_price'] );

$items = tack_parity_call( $widget, 'quote_list_line_items', array( wp_json_encode( array( array( 'product_id' => 11, 'quantity' => 5, 'target_price' => 3.25 ) ) ) ) );
check( 'line items keep the store price, never the target', 1 === count( $items ) && 4.5 === $items[0]['unitPrice'], var_export( $items, true ) );
$note = $widget->note_with_target_prices( 'Need by Friday', 'EUR' );
check( 'the note keeps the shopper\'s message first', 0 === strpos( $note, 'Need by Friday' ), $note );
check( 'the note carries a "Target prices" line per product', false !== strpos( $note, 'Target prices:' ) && false !== strpos( $note, 'Safety Gloves (SG-100) x5: 3.25 EUR' ), $note );

// ── Re-pricing gate ─────────────────────────────────────────────────────────
tack_parity_reset();
check( 're-pricing is off for a guest', ! $widget->reprice_enabled() );

// ── Per-product quote-only ──────────────────────────────────────────────────
tack_parity_reset();
$catalog = new Tack_Catalog_Mode();
$catalog->init();
check( 'quote-only is enforced through woocommerce_is_purchasable', tack_parity_hooked( 'woocommerce_is_purchasable' ) );
check( 'the Store API add-to-cart validation hook is registered', tack_parity_hooked( 'woocommerce_store_api_validate_add_to_cart' ) );
check( 'the product data panel field and its save are registered', tack_parity_hooked( 'woocommerce_product_options_general_product_data' ) && tack_parity_hooked( 'woocommerce_admin_process_product_object' ) );

check( 'a product without the switch stays purchasable', true === $catalog->filter_is_purchasable( true, $simple ) );
$simple->meta[ Tack_Catalog_Mode::META_QUOTE_ONLY ]   = 'yes';
$variable->meta[ Tack_Catalog_Mode::META_QUOTE_ONLY ] = 'yes';
$catalog = new Tack_Catalog_Mode();
check( 'THE CONTROL: a quote-only product is not purchasable', false === $catalog->filter_is_purchasable( true, $simple ) );
check( 'a variation inherits its parent\'s quote-only', false === $catalog->filter_is_purchasable( true, $variation ) );
check( 'the seller (manage_woocommerce) is NOT exempt from a per-product switch', false === ( function () use ( $simple ) {
	$GLOBALS['TACK_CAPS'] = array( 'manage_woocommerce' );
	$r                    = ( new Tack_Catalog_Mode() )->filter_is_purchasable( true, $simple );
	$GLOBALS['TACK_CAPS'] = array();
	return $r;
} )() );

$threw = '';
try {
	$catalog->refuse_store_api_add_to_cart( $simple, array() );
} catch ( Exception $e ) {
	$threw = $e->getMessage();
}
check( 'the Store API add-to-cart THROWS for a quote-only product, naming it', false !== strpos( $threw, 'Safety Gloves' ), $threw );

$plain = new Tack_Parity_Product( 30, 'simple', 'Hard Hat', 'HH', 9.0 );
$threw = '';
try {
	$catalog->refuse_store_api_add_to_cart( $plain, array() );
} catch ( Exception $e ) {
	$threw = $e->getMessage();
}
check( 'and lets an ordinary product through', '' === $threw, $threw );

$GLOBALS['TACK_CART_LINES'] = array(
	'a' => array( 'data' => $simple ),
	'b' => array( 'data' => $plain ),
);
$catalog->check_cart();
check( 'a cart filled before the switch loses the quote-only line, and only that one', array( 'a' ) === $GLOBALS['TACK_CART_REMOVED'], var_export( $GLOBALS['TACK_CART_REMOVED'], true ) );
check( 'and the shopper is told why', 1 === count( $GLOBALS['TACK_NOTICES'] ) && false !== strpos( $GLOBALS['TACK_NOTICES'][0], 'Safety Gloves' ) );

$html = $catalog->filter_price_html( '$4.50', $simple );
check( 'a quote-only product shows "Available on quote" beside its price', false !== strpos( $html, 'tack-quote-only-cta' ) && 0 === strpos( $html, '$4.50' ), $html );

// Saving the checkbox: on, then off.
$_POST                                      = array(
	'woocommerce_meta_nonce'            => wp_create_nonce( 'woocommerce_save_data' ),
	Tack_Catalog_Mode::META_QUOTE_ONLY => 'yes',
);
$GLOBALS['TACK_CAPS']                       = array( 'edit_post' );
$fresh                                      = new Tack_Parity_Product( 40, 'simple', 'Gloves XL', 'GX', 1.0 );
$catalog->save_quote_only_field( $fresh );
check( 'saving with the box ticked stores the meta', 'yes' === $fresh->get_meta( Tack_Catalog_Mode::META_QUOTE_ONLY ) );
$_POST = array( 'woocommerce_meta_nonce' => wp_create_nonce( 'woocommerce_save_data' ) );
$catalog->save_quote_only_field( $fresh );
check( 'saving with the box cleared removes it', '' === $fresh->get_meta( Tack_Catalog_Mode::META_QUOTE_ONLY ) );
$_POST = array(
	'woocommerce_meta_nonce'            => 'forged',
	Tack_Catalog_Mode::META_QUOTE_ONLY => 'yes',
);
$catalog->save_quote_only_field( $fresh );
check( 'a bad nonce saves nothing', '' === $fresh->get_meta( Tack_Catalog_Mode::META_QUOTE_ONLY ) );
$_POST = array();
unset( $simple->meta[ Tack_Catalog_Mode::META_QUOTE_ONLY ], $variable->meta[ Tack_Catalog_Mode::META_QUOTE_ONLY ] );

// ── Price gate: approved wholesale accounts ─────────────────────────────────
tack_parity_reset();
$GLOBALS['TACK_OPTIONS'][ Tack_Catalog_Mode::OPT_MODE ]  = Tack_Catalog_Mode::MODE_QUOTE_ONLY;
$GLOBALS['TACK_OPTIONS'][ Tack_Catalog_Mode::OPT_SCOPE ] = Tack_Catalog_Mode::SCOPE_UNAPPROVED;
$GLOBALS['TACK_OPTIONS']['tack_quotes_api_key']          = 'tk_test_key';

$client = new Tack_Test_Storefront_Client(
	array(
		'status'            => 'linked',
		'wholesaleApproved' => true,
	)
);
check( 'price gate: a signed-out visitor sees the catalogue', ( new Tack_Catalog_Mode( $client ) )->is_active() );
check( 'and TackQuote is not asked about nobody', 0 === count( $client->calls ) );

tack_test_set_logged_in( true, 'buyer@trade-customer.test' );
$gate = new Tack_Catalog_Mode( $client );
check( 'price gate: an APPROVED wholesale buyer keeps the cart', ! $gate->is_active() );
check( 'the gate asks with the trusted account email', array( 'buyer@trade-customer.test' ) === $client->calls );
( new Tack_Catalog_Mode( $client ) )->is_active();
check( 'the answer is cached across requests (one call, not one per page view)', 1 === count( $client->calls ) );

$GLOBALS['TACK_TRANSIENTS'] = array();
$client                     = new Tack_Test_Storefront_Client(
	array(
		'status'            => 'linked',
		'wholesaleApproved' => false,
	)
);
check( 'price gate: a linked but UNapproved buyer sees the catalogue', ( new Tack_Catalog_Mode( $client ) )->is_active() );

$GLOBALS['TACK_TRANSIENTS'] = array();
$client                     = new Tack_Test_Storefront_Client( array( 'status' => 'unlinked' ) );
check( 'price gate: an unlinked buyer sees the catalogue', ( new Tack_Catalog_Mode( $client ) )->is_active() );

$GLOBALS['TACK_TRANSIENTS'] = array();
$client                     = new Tack_Test_Storefront_Client( new WP_Error( 'tack_http_503', 'down' ) );
check( 'price gate FAILS CLOSED: TackQuote unreachable -> catalogue', ( new Tack_Catalog_Mode( $client ) )->is_active() );

$GLOBALS['TACK_CAPS'] = array( 'manage_woocommerce' );
check( 'the shop manager stays exempt from the store-wide gate', ! ( new Tack_Catalog_Mode( $client ) )->is_active() );
$GLOBALS['TACK_CAPS'] = array();

// The wire: the real client, through the shared request().
$GLOBALS['TACK_TRANSIENTS'] = array();
tack_test_set_http_response(
	200,
	wp_json_encode(
		array(
			'status'            => 'linked',
			'wholesaleApproved' => true,
		)
	)
);
$answer  = ( new Tack_Api_Client() )->get_price_access( 'buyer@trade-customer.test' );
$sent    = (string) $GLOBALS['TACK_HTTP_LAST_URL'];
$headers = isset( $GLOBALS['TACK_HTTP_LAST_ARGS']['headers'] ) ? (array) $GLOBALS['TACK_HTTP_LAST_ARGS']['headers'] : array();
check( 'price-access goes to /storefront/v1/price-access', false !== strpos( $sent, '/storefront/v1/price-access?' ), $sent );
check( 'with buyerEmail and buyerExternalId = the WordPress user id', false !== strpos( $sent, 'buyerEmail=buyer%40trade-customer.test' ) && false !== strpos( $sent, 'buyerExternalId=1' ), $sent );
check( 'key in X-Api-Key ONLY (the v1 guard refuses a second credential)', isset( $headers['X-Api-Key'] ) && ! isset( $headers['Authorization'] ), var_export( array_keys( $headers ), true ) );
check( 'and the plugin version header', isset( $headers['X-TackQuote-Plugin-Version'] ), var_export( array_keys( $headers ), true ) );
check( 'the answer is passed through', is_array( $answer ) && ! empty( $answer['wholesaleApproved'] ), var_export( $answer, true ) );
tack_test_set_http_response( 404, '{}' );
$GLOBALS['TACK_TRANSIENTS'] = array();
check( 'a server without v1 routes is an ERROR for the gate (so it fails closed)', is_wp_error( ( new Tack_Api_Client() )->get_price_access( 'buyer@trade-customer.test' ) ) );
$GLOBALS['TACK_TRANSIENTS'] = array();

check( 'settings accept the new scope', Tack_Catalog_Mode::SCOPE_UNAPPROVED === ( new Tack_Settings() )->sanitize_scope( Tack_Catalog_Mode::SCOPE_UNAPPROVED ) );

// ── Order limits on the Cart and Checkout BLOCKS ────────────────────────────
tack_parity_reset();
tack_test_set_option( 'tack_quotes_api_key', 'tk_test_key' );
tack_test_set_logged_in( true, 'buyer@trade-customer.test' );
$GLOBALS['TACK_OPTIONS'][ Tack_B2B_Notices::OPTION_ORDER_LIMITS ] = 'yes';
$limits = new Tack_B2B_Notices(
	new Tack_Test_Notices_Client(
		array(
			'order-limits' => array(
				'status'          => 'limited',
				'accountSpecific' => true,
				'limits'          => array(
					array(
						'limitType' => 'product_qty',
						'sku'       => 'SG-100',
						'min'       => 25,
						'max'       => null,
					),
				),
			),
		)
	)
);
$limits->init();
check( 'classic enforcement hook registered', tack_parity_hooked( 'woocommerce_check_cart_items' ) );
check( 'BLOCKS enforcement: woocommerce_store_api_cart_errors registered beside it', tack_parity_hooked( 'woocommerce_store_api_cart_errors' ) );

tack_test_set_cart( array( array( 'sku' => 'SG-100', 'qty' => 3, 'name' => 'Safety Gloves' ) ) );
$collector = new Tack_Test_Error_Collector();
$limits->add_store_api_cart_errors( $collector, WC()->cart );
tack_test_reset_notices();
$limits->enforce_order_limits();
check( 'the Store API gets the refusal under code tackquote_order_limit', 1 === count( $collector->errors ) && 'tackquote_order_limit' === $collector->errors[0][0], var_export( $collector->errors, true ) );
check( 'in the SAME words as the classic cart', isset( tack_test_notices()[0] ) && $collector->errors[0][1] === tack_test_notices()[0] );

tack_test_set_cart( array( array( 'sku' => 'SG-100', 'qty' => 30, 'name' => 'Safety Gloves' ) ) );
$collector = new Tack_Test_Error_Collector();
$limits->add_store_api_cart_errors( $collector, WC()->cart );
check( 'a cart that meets the limit adds no Store API error', 0 === count( $collector->errors ) );

// ── Settings sanitizers ─────────────────────────────────────────────────────
$s = new Tack_Settings();
check( 'quote page address: javascript: is dropped', '' === $s->sanitize_quote_page_url( 'javascript:alert(1)' ) );
check( 'quote page address: https is kept', 'https://shop.example/quote/' === $s->sanitize_quote_page_url( 'https://shop.example/quote/' ) );
check( 'offset outside 0..200 falls back to 20', 20 === $s->sanitize_fab_offset( '500' ) && 20 === $s->sanitize_fab_offset( '-1' ) && 64 === $s->sanitize_fab_offset( '64' ) );
check( 'unknown position / pages / size / opens fall back to the defaults', 'bottom-right' === $s->sanitize_fab_position( 'top' ) && 'all' === $s->sanitize_fab_pages( 'x' ) && 'regular' === $s->sanitize_fab_size( 'huge' ) && 'drawer' === $s->sanitize_quote_opens( 'modal' ) );

tack_parity_reset();
