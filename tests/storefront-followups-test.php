<?php
/**
 * Storefront follow-ups (1.10.0):
 *
 * 1. A variable product on quote (store-wide quote-only, or per product) keeps
 *    its variation form, with the TackQuote controls (quantity + buttons +
 *    `variation_id`) in place of WooCommerce's quantity + cart button. On a
 *    classic theme in store-wide mode the form is rendered back at the slot of
 *    the withdrawn template.
 * 2. The quantity-break table renders after the Add to Cart block on block
 *    themes, once per product, and never from the compatibility layer.
 *
 * Runs after block-theme-buttons-test.php (doing_filter stub, tack_block_render(),
 * the parity product doubles and Tack_Test_Forms_Client).
 *
 * Run: php tests/run.php
 *
 * @package TackQuotes
 */

if ( ! function_exists( 'woocommerce_quantity_input' ) ) {
	/**
	 * WooCommerce's quantity input, as far as the variation script reads it.
	 *
	 * @param array      $args    Args.
	 * @param WC_Product $product Product.
	 */
	function woocommerce_quantity_input( $args = array(), $product = null ) {
		echo '<div class="quantity"><input type="number" class="input-text qty text" name="quantity" min="' . (int) $args['min_value'] . '" value="' . (int) $args['input_value'] . '" /></div>';
	}
}

$GLOBALS['TACK_FU_FORMS'] = 0;
if ( ! function_exists( 'woocommerce_variable_add_to_cart' ) ) {
	/** WooCommerce's variable add-to-cart renderer: counts calls. */
	function woocommerce_variable_add_to_cart() {
		++$GLOBALS['TACK_FU_FORMS'];
	}
}
if ( ! function_exists( 'woocommerce_single_variation_add_to_cart_button' ) ) {
	/** WooCommerce's variation cart controls (only its existence is read). */
	function woocommerce_single_variation_add_to_cart_button() {}
}

/** A variable product with the purchase-quantity methods the controls call. */
class Tack_Followup_Product extends Tack_Parity_Product {
	/** @return int */
	public function get_min_purchase_quantity() {
		return 1;
	}
	/** @return int */
	public function get_max_purchase_quantity() {
		return -1;
	}
}

$fu_variable  = new Tack_Followup_Product( 30, 'variable', 'Hard Hat', 'HH', 20.0 );
$fu_variation = new Tack_Followup_Product( 31, 'variation', 'Hard Hat - Red', 'HH-R', 20.0, 30 );
$fu_simple    = new Tack_Followup_Product( 32, 'simple', 'Ear Plugs', 'EP', 2.0 );
$GLOBALS['TACK_TEST_PRODUCTS'][30] = $fu_variable;
$GLOBALS['TACK_TEST_PRODUCTS'][31] = $fu_variation;
$GLOBALS['TACK_TEST_PRODUCTS'][32] = $fu_simple;

/**
 * Was this action recorded by the do_action() stub?
 *
 * @param string $hook Hook.
 * @return array|null The recorded arguments, or null.
 */
function tack_fu_done( $hook ) {
	foreach ( $GLOBALS['TACK_DONE_ACTIONS'] as $done ) {
		if ( $hook === $done[0] ) {
			return $done[1];
		}
	}
	return null;
}

/**
 * Fresh visitor + catalog mode with its filters registered (is_purchasable goes through them).
 *
 * @param string $mode Store mode.
 * @return Tack_Catalog_Mode
 */
function tack_fu_mode( $mode ) {
	tack_parity_reset();
	$GLOBALS['TACK_REMOVED']      = array();
	$GLOBALS['TACK_DONE_ACTIONS'] = array();
	$GLOBALS['TACK_DOING_FILTER'] = array();
	$GLOBALS['TACK_FU_FORMS']     = 0;
	$GLOBALS['TACK_OPTIONS'][ Tack_Catalog_Mode::OPT_MODE ] = $mode;
	$m = new Tack_Catalog_Mode();
	$m->init();
	return $m;
}

$core_button = 'woocommerce_single_variation|woocommerce_single_variation_add_to_cart_button|20';

// ── 1a. Registration ────────────────────────────────────────────────────────
$m = tack_fu_mode( Tack_Catalog_Mode::MODE_CART );
check( 'variation form: classic store-wide re-render hooked on the summary @29 (before the quote-button fallback @30)', tack_parity_hooked( 'woocommerce_single_product_summary', 29 ) );
check( 'variation form: the swap is hooked inside the form (before/after_single_variation)', tack_parity_hooked( 'woocommerce_before_single_variation' ) && tack_parity_hooked( 'woocommerce_after_single_variation' ) );
$GLOBALS['TACK_HOOKS'] = array();
( new Tack_Widget() )->init();
check( 'variation form: the widget prints its buttons on tackquote_variation_quote_controls', tack_parity_hooked( 'tackquote_variation_quote_controls' ) );

// ── 1b. Who is swapped ──────────────────────────────────────────────────────
$m = tack_fu_mode( Tack_Catalog_Mode::MODE_CART );
check( 'cart mode: a normal variable product keeps WooCommerce\'s cart controls', false === $m->quotes_instead_of_cart( $fu_variable ) );
$fu_variable->meta[ Tack_Catalog_Mode::META_QUOTE_ONLY ] = 'yes';
$m = tack_fu_mode( Tack_Catalog_Mode::MODE_CART );
check( 'per-product quote only: the variable product is on quote', true === $m->quotes_instead_of_cart( $fu_variable ) );
unset( $fu_variable->meta[ Tack_Catalog_Mode::META_QUOTE_ONLY ] );
$m = tack_fu_mode( Tack_Catalog_Mode::MODE_QUOTE_ONLY );
check( 'store-wide quote-only: the variable product is on quote', true === $m->quotes_instead_of_cart( $fu_variable ) );
$GLOBALS['TACK_CAPS'] = array( 'manage_woocommerce' );
check( 'store-wide quote-only: the seller (exempt) keeps the cart controls', false === $m->quotes_instead_of_cart( $fu_variable ) );
$GLOBALS['TACK_CAPS'] = array();
check( 'a non-product is never swapped', false === $m->quotes_instead_of_cart( 'hard-hat' ) );

// ── 1c. THE SWAP: cart controls out, quote controls in, cart controls back ──
$m                  = tack_fu_mode( Tack_Catalog_Mode::MODE_QUOTE_ONLY );
$GLOBALS['product'] = $fu_variable;
$m->swap_variation_cart_controls();
check( 'swap: WooCommerce\'s quantity + "Add to cart" block is removed from the variation form', in_array( $core_button, $GLOBALS['TACK_REMOVED'], true ), implode( ', ', $GLOBALS['TACK_REMOVED'] ) );
check( 'swap: the TackQuote controls take its place (woocommerce_single_variation @20)', tack_parity_hooked( 'woocommerce_single_variation', 20 ) );

$controls = tack_parity_capture( array( $m, 'render_variation_quote_controls' ) );
check( 'controls: a quantity input the variation script and the quote JS read', 1 === substr_count( $controls, 'name="quantity"' ) && false !== strpos( $controls, 'class="input-text qty text"' ), $controls );
check( 'controls: the hidden variation_id WooCommerce fills in on selection', false !== strpos( $controls, '<input type="hidden" name="variation_id" class="variation_id" value="0" />' ), $controls );
check( 'controls: WooCommerce\'s wrapper classes, so its script toggles them', false !== strpos( $controls, 'class="woocommerce-variation-add-to-cart variations_button' ), $controls );
check( 'controls: NO add-to-cart field and NO cart submit button', false === strpos( $controls, 'name="add-to-cart"' ) && false === strpos( $controls, 'single_add_to_cart_button' ) && false === strpos( $controls, 'type="submit"' ), $controls );
$args = tack_fu_done( 'tackquote_variation_quote_controls' );
check( 'controls: the quote buttons are asked for, for this product', is_array( $args ) && isset( $args[0] ) && $fu_variable === $args[0] );

$GLOBALS['TACK_HOOKS']   = array();
$GLOBALS['TACK_REMOVED'] = array();
$m->restore_variation_cart_controls();
check( 'restore: the TackQuote controls are taken off again', 1 === count( $GLOBALS['TACK_REMOVED'] ) );
check( 'restore: WooCommerce\'s cart controls are put back for the next form on the page', tack_parity_hooked( 'woocommerce_single_variation', 20 ) );
$GLOBALS['TACK_HOOKS']   = array();
$GLOBALS['TACK_REMOVED'] = array();
$m->restore_variation_cart_controls();
check( 'restore: a second restore does nothing', array() === $GLOBALS['TACK_HOOKS'] && array() === $GLOBALS['TACK_REMOVED'] );

// A purchasable variable product (cart mode) is not touched.
$m                  = tack_fu_mode( Tack_Catalog_Mode::MODE_CART );
$GLOBALS['product'] = $fu_variable;
$m->swap_variation_cart_controls();
check( 'cart mode: the variation form keeps its cart controls (nothing removed)', ! in_array( $core_button, $GLOBALS['TACK_REMOVED'], true ) && ! tack_parity_hooked( 'woocommerce_single_variation' ) );

// Per-product quote only, cart mode: same swap (block and classic templates alike).
$fu_variable->meta[ Tack_Catalog_Mode::META_QUOTE_ONLY ] = 'yes';
$m                  = tack_fu_mode( Tack_Catalog_Mode::MODE_CART );
$GLOBALS['product'] = $fu_variable;
$m->swap_variation_cart_controls();
check( 'per-product quote only: the cart controls are swapped too', in_array( $core_button, $GLOBALS['TACK_REMOVED'], true ) );
$m->restore_variation_cart_controls();

// ── 1d. A quote-only variation still cannot reach the cart ──────────────────
check( 'a variation of a quote-only parent is NOT purchasable (classic ?add-to-cart= refused)', false === $fu_variation->is_purchasable() );
$threw = false;
try {
	$m->refuse_store_api_add_to_cart( $fu_variation, array() );
} catch ( Exception $e ) {
	$threw = true;
}
check( 'and the Store API add-item for it is refused', $threw );
unset( $fu_variable->meta[ Tack_Catalog_Mode::META_QUOTE_ONLY ] );
$m = tack_fu_mode( Tack_Catalog_Mode::MODE_QUOTE_ONLY );
check( 'store-wide quote-only: the variation is not purchasable either', false === $fu_variation->is_purchasable() );

// ── 1e. Classic theme, store-wide: the form comes back at the template's slot ──
$m                  = tack_fu_mode( Tack_Catalog_Mode::MODE_QUOTE_ONLY );
$GLOBALS['product'] = $fu_variable;
$m->render_quote_only_variation_form();
check( 'classic store-wide: the variable product\'s form is rendered (woocommerce_variable_add_to_cart)', 1 === $GLOBALS['TACK_FU_FORMS'] );

$m                            = tack_fu_mode( Tack_Catalog_Mode::MODE_QUOTE_ONLY );
$GLOBALS['product']           = $fu_variable;
$GLOBALS['TACK_DOING_FILTER'] = array( 'render_block' );
$m->render_quote_only_variation_form();
$GLOBALS['TACK_DOING_FILTER'] = array();
check( 'block theme: not from the compatibility layer (the Add to Cart block renders the form itself)', 0 === $GLOBALS['TACK_FU_FORMS'] );

$m                  = tack_fu_mode( Tack_Catalog_Mode::MODE_QUOTE_ONLY );
$GLOBALS['product'] = $fu_simple;
$m->render_quote_only_variation_form();
check( 'a simple product gets no variation form', 0 === $GLOBALS['TACK_FU_FORMS'] );

$m                  = tack_fu_mode( Tack_Catalog_Mode::MODE_CART );
$GLOBALS['product'] = $fu_variable;
$m->render_quote_only_variation_form();
check( 'cart mode: WooCommerce\'s own template renders the form, not this', 0 === $GLOBALS['TACK_FU_FORMS'] );

$fu_variable->meta[ Tack_Catalog_Mode::META_QUOTE_ONLY ] = 'yes';
$m                  = tack_fu_mode( Tack_Catalog_Mode::MODE_CART );
$GLOBALS['product'] = $fu_variable;
$m->render_quote_only_variation_form();
check( 'per-product quote only: the template was not withdrawn, so no second form', 0 === $GLOBALS['TACK_FU_FORMS'] );
unset( $fu_variable->meta[ Tack_Catalog_Mode::META_QUOTE_ONLY ] );

// ── 2. Quantity-break table on block themes ─────────────────────────────────
tack_parity_reset();
$GLOBALS['TACK_DOING_FILTER'] = array();
tack_test_reset_transients();
tack_test_set_option( 'tack_quotes_api_key', 'tk_test_key' );
tack_test_set_option( Tack_Wholesale_Pricing::OPTION_ENABLED, 'yes' );
tack_test_set_logged_in( true, 'buyer@trade-customer.test' );

/**
 * A client with a two-rung v1 ladder.
 *
 * @return Tack_Test_Forms_Client
 */
function tack_fu_breaks_client() {
	return new Tack_Test_Forms_Client(
		array(
			'/storefront/v1/quantity-breaks' => array(
				'status'          => 'priced',
				'currency'        => 'USD',
				'accountSpecific' => false,
				'rows'            => array(
					array( 'minQty' => 1, 'unitPrice' => 10 ),
					array( 'minQty' => 50, 'unitPrice' => 8 ),
				),
			),
		)
	);
}

$GLOBALS['TACK_HOOKS']   = array();
$GLOBALS['TACK_FILTERS'] = array();
$client                  = tack_fu_breaks_client();
$pricing                 = new Tack_Wholesale_Pricing( $client );
$pricing->init();
foreach ( Tack_Block_Product::ADD_TO_CART_BLOCKS as $name ) {
	$found = false;
	foreach ( $GLOBALS['TACK_HOOKS'] as $h ) {
		if ( 'render_block_' . $name === $h['hook'] && 5 === (int) $h['priority'] && 3 === (int) $h['args'] ) {
			$found = true;
		}
	}
	check( "volume table: hooked on render_block_$name @5 with 3 args", $found );
}
check( 'volume table: the classic summary mount stays @25', tack_parity_hooked( 'woocommerce_single_product_summary', 25 ) );

// The compatibility layer fires the summary hook above the excerpt: nothing, and the flag is not used up.
$GLOBALS['product']           = $fu_simple;
$GLOBALS['TACK_DOING_FILTER'] = array( 'render_block' );
$compat                       = tack_parity_capture( array( $pricing, 'render_quantity_breaks_summary' ) );
$GLOBALS['TACK_DOING_FILTER'] = array();
check( 'block theme: the compatibility-layer summary hook prints no table and asks nothing', '' === $compat && array() === $client->sent, $compat );

$out = tack_block_render( 'woocommerce/add-to-cart-form', '<form class="cart"></form>', 32 );
check( 'block theme: the table follows the Add to Cart block', 0 === strpos( $out, '<form class="cart"></form><table class="tackquote-quantity-breaks' ) && 1 === substr_count( $out, 'tackquote-quantity-breaks' ), $out );
check( 'block theme: for the block\'s product', 1 === count( $client->sent ) && false !== strpos( $client->sent[0]['path'], 'sku=EP' ), wp_json_encode( $client->sent ) );

$again = tack_block_render( 'woocommerce/add-to-cart-with-options', '<div/>', 32 );
check( 'ONCE: a second Add to Cart block for the same product adds no second table', '<div/>' === $again, $again );
$classic = tack_parity_capture( array( $pricing, 'render_quantity_breaks_summary' ) );
check( 'ONCE: nor does the classic summary hook afterwards', '' === $classic, $classic );
check( 'ONCE: and TackQuote is asked once', 1 === count( $client->sent ) );

// Classic theme: the summary hook still renders it (and the blocks then add nothing).
$client2  = tack_fu_breaks_client();
$pricing2 = new Tack_Wholesale_Pricing( $client2 );
$classic  = tack_parity_capture( array( $pricing2, 'render_quantity_breaks_summary' ) );
check( 'classic theme: the summary hook renders the table', 1 === substr_count( $classic, 'tackquote-quantity-breaks' ), $classic );
check( 'classic theme: a block mount for the same product then adds nothing', '<p/>' === $pricing2->append_quantity_breaks_to_block( '<p/>', array(), tack_block_instance( 32 ) ) );
check( 'no postId context: the block is returned untouched', '<p/>' === $pricing2->append_quantity_breaks_to_block( '<p/>', array(), new stdClass() ) );

$GLOBALS['TACK_FILTERS'] = array();
$GLOBALS['TACK_HOOKS']   = array();
tack_test_set_option( Tack_Wholesale_Pricing::OPTION_SHOW_BREAKS, 'no' );
( new Tack_Wholesale_Pricing( tack_fu_breaks_client() ) )->init();
check( 'table switched off: no block mount either', ! tack_parity_hooked( 'render_block_woocommerce/add-to-cart-form' ) );
tack_test_set_option( Tack_Wholesale_Pricing::OPTION_SHOW_BREAKS, 'yes' );

unset( $GLOBALS['product'] );
