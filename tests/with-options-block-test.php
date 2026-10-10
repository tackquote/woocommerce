<?php
/**
 * WooCommerce's Add to Cart with Options block, blockified mode (1.10.0).
 *
 * In that mode `AddToCartWithOptions::render()` (WooCommerce 11.2.1) renders its
 * own form and fires `woocommerce_after_add_to_cart_button` itself, from inside
 * it; a button printed there switches the form to a plain posted form
 * (`has_form_elements()`). In its other mode (no block template part for the
 * product type) the classic template runs through `woocommerce_{type}_add_to_cart`.
 *
 * The block lifecycle is replayed the way WordPress runs it: `render_block_data`
 * when the block starts (wp-includes/class-wp-block.php / blocks.php), the
 * classic hook from inside `render()`, then `render_block_{$name}` with
 * ( $content, $parsed_block, $instance ).
 *
 * Runs after block-theme-buttons-test.php, whose helpers and product doubles it uses.
 *
 * Run: php tests/run.php
 *
 * @package TackQuotes
 */

$GLOBALS['TACK_DOING_ACTION'] = array();
if ( ! function_exists( 'doing_action' ) ) {
	/** @param string|null $hook Hook. @return bool */
	function doing_action( $hook = null ) {
		return in_array( $hook, $GLOBALS['TACK_DOING_ACTION'], true );
	}
}
if ( ! function_exists( 'wc_get_formatted_variation' ) ) {
	/** @param object $v Variation. @return string */
	function wc_get_formatted_variation( $v, $flat = false, $include_names = true, $skip = false ) {
		return implode( ', ', $v->attrs );
	}
}

/** A typed product double: the parity double plus what the with-options code reads. */
class Tack_WO_Product extends Tack_Parity_Product {
	/** @var int[] */
	public $children = array();
	/** @var bool */
	public $visible = true;
	/** @var bool */
	public $in_stock = true;
	/** @var string[] */
	public $attrs = array();
	/** @var bool False: quote only (WooCommerce then shows no cart button and no quantity). */
	public $purchasable = true;
	/** @return bool */
	public function is_purchasable() {
		return $this->purchasable;
	}
	/** @return bool */
	public function has_purchasable_variations() {
		return $this->purchasable;
	}
	/** @return int */
	public function get_min_purchase_quantity() {
		return 1;
	}
	/** @return int */
	public function get_max_purchase_quantity() {
		return -1;
	}
	/** @return string */
	public function get_type() {
		return $this->type;
	}
	/** @return int[] */
	public function get_children() {
		return $this->children;
	}
	/** @return bool */
	public function variation_is_visible() {
		return $this->visible;
	}
	/** @return bool */
	public function is_in_stock() {
		return $this->in_stock;
	}
}

$wo_simple            = new Tack_WO_Product( 51, 'simple', 'Hard Hat', 'HH', 12.0 );
$wo_variable          = new Tack_WO_Product( 60, 'variable', 'Hi-Vis Vest', 'HV', 20.0 );
$wo_variable->children = array( 61, 62, 63 );
$wo_v41               = new Tack_WO_Product( 61, 'variation', 'Hi-Vis Vest - Yellow, L', 'HV-Y-L', 20.0, 60 );
$wo_v41->attrs        = array( 'Yellow', 'L' );
$wo_v42               = new Tack_WO_Product( 62, 'variation', 'Hi-Vis Vest - Orange, L', 'HV-O-L', 20.0, 60 );
$wo_v42->attrs        = array( 'Orange', 'L' );
$wo_v42->in_stock     = false;
$wo_v43               = new Tack_WO_Product( 63, 'variation', 'Hi-Vis Vest - Orange, S', 'HV-O-S', 20.0, 60 );
$wo_v43->attrs        = array( 'Orange <b>S</b>' );
$wo_v43->visible      = false;
foreach ( array( $wo_simple, $wo_variable, $wo_v41, $wo_v42, $wo_v43 ) as $wo_p ) {
	$GLOBALS['TACK_TEST_PRODUCTS'][ $wo_p->get_id() ] = $wo_p;
}

$GLOBALS['TACK_OPTIONS']['tack_quotes_show_add_to_quote']  = 'yes';
$GLOBALS['TACK_OPTIONS']['tack_quotes_show_request_quote'] = 'yes';

/**
 * Render one Add to Cart with Options block the way WordPress and WooCommerce do.
 *
 * @param WC_Product  $product      The block's product (also `global $product` during render()).
 * @param string|null $classic_type When set, the block is in its classic mode and the hook
 *                                  fires inside `woocommerce_{type}_add_to_cart`.
 * @param bool        $hooks_fire   False for a non-purchasable product: WooCommerce skips the hooks.
 * @return string
 */
function tack_wo_render( $product, $classic_type = null, $hooks_fire = true ) {
	$name = 'woocommerce/add-to-cart-with-options';
	apply_filters( 'render_block_data', array( 'blockName' => $name ), array( 'blockName' => $name ), null );
	$GLOBALS['product']           = $product;
	$GLOBALS['TACK_DOING_ACTION'] = $classic_type ? array( 'woocommerce_' . $classic_type . '_add_to_cart' ) : array();
	$inside                       = '';
	if ( $hooks_fire ) {
		$inside = tack_parity_capture(
			function () {
				foreach ( $GLOBALS['TACK_WO_AFTER_BUTTON'] as $cb ) {
					call_user_func( $cb, '' );
				}
			}
		);
	}
	$GLOBALS['TACK_DOING_ACTION'] = array();
	$form                         = $classic_type
		? '<div class="wp-block-add-to-cart-with-options"><form class="cart">' . $inside . '</form></div>'
		: '<form class="wp-block-add-to-cart-with-options wc-block-add-to-cart-with-options" data-wp-on--submit="actions.addToCart">' . $inside . '<input type="hidden" name="add-to-cart" value="' . $product->get_id() . '" /></form>';
	$b                            = new stdClass();
	$b->name                      = $name;
	$b->context                   = array( 'postId' => $product->get_id(), 'postType' => 'product' );
	return apply_filters( 'render_block_' . $name, $form, array( 'blockName' => $name ), $b );
}

/** Fresh widget wired as init() wires it; the classic button hook callback is kept aside. */
function tack_wo_fresh_widget() {
	$GLOBALS['TACK_HOOKS']   = array();
	$GLOBALS['TACK_FILTERS'] = array();
	$w                       = new Tack_Widget();
	$w->init();
	$GLOBALS['TACK_WO_AFTER_BUTTON'] = array( array( $w, 'render_product_button' ) );
	return $w;
}

// ── Registration ────────────────────────────────────────────────────────────
tack_wo_fresh_widget();
check( 'with-options: render_block_data is watched', tack_parity_hooked( 'render_block_data' ) );
check( 'with-options: the block\'s end is watched before the buttons are appended (priority 1)', tack_parity_hooked( 'render_block_woocommerce/add-to-cart-with-options', 1 ) );

// ── Blockified, purchasable simple product ──────────────────────────────────
tack_wo_fresh_widget();
$out   = tack_wo_render( $wo_simple );
$split = strpos( $out, '</form>' );
check( 'blockified: NO buttons inside the block\'s form (WooCommerce keeps its Interactivity API form)', false !== $split && false === strpos( substr( $out, 0, $split ), 'tack-quote-buttons' ), $out );
check( 'blockified: exactly one set of buttons, AFTER the form', 1 === substr_count( $out, 'class="tack-quote-buttons"' ) && strpos( $out, 'tack-quote-buttons' ) > $split, $out );
check( 'blockified: the set names the block so the JS reads that form', false !== strpos( $out, 'class="tack-quote-buttons" data-tack-scope="add-to-cart-with-options"' ), $out );
check( 'blockified simple product: no variation states', false === strpos( $out, 'data-tack-variations' ), $out );
check( 'blockified: the block is closed afterwards (a later classic hook renders normally)', false === Tack_Block_Product::in_blockified_with_options( $wo_simple ) );

// ── Blockified variable product: purchasable (hooks fire) and quote-only (hooks skipped) ──
foreach ( array( 'purchasable' => true, 'quote-only' => false ) as $wo_case => $wo_hooks ) {
	tack_wo_fresh_widget();
	$wo_variable->purchasable = $wo_hooks;
	$out                      = tack_wo_render( $wo_variable, null, $wo_hooks );
	$split                    = strpos( $out, '</form>' );
	check( "blockified variable ($wo_case): one set, after the form", 1 === substr_count( $out, 'class="tack-quote-buttons"' ) && strpos( $out, 'tack-quote-buttons' ) > $split, $out );
	preg_match( '/data-tack-variations="([^"]*)"/', $out, $wo_m );
	$wo_states = isset( $wo_m[1] ) ? json_decode( html_entity_decode( $wo_m[1], ENT_QUOTES ), true ) : null;
	check(
		"blockified variable ($wo_case): variation states = in stock + visible quotable, out of stock / hidden not",
		array(
			'61' => array( 'q' => 1, 'l' => 'Yellow, L' ),
			'62' => array( 'q' => 0, 'l' => 'Orange, L' ),
			'63' => array( 'q' => 0, 'l' => 'Orange S' ),
		) === $wo_states,
		$out
	);
	check(
		"blockified variable ($wo_case): a quantity input beside the buttons only when the block shows none",
		( $wo_hooks ? 0 : 1 ) === substr_count( $out, 'name="quantity"' ) && ( $wo_hooks || strpos( $out, 'name="quantity"' ) > strpos( $out, 'tack-quote-buttons' ) ),
		$out
	);
}
$wo_variable->purchasable = true;
check( 'the states attribute is escaped (no raw quote breaks out of it)', false === strpos( $out, 'data-tack-variations="{"' ), $out );

// ── Quote-only simple product: no cart button, no quantity in the block ──────
tack_wo_fresh_widget();
$wo_simple->purchasable = false;
$out                    = tack_wo_render( $wo_simple, null, false );
$wo_simple->purchasable = true;
check( 'blockified quote-only simple: one set after the form, with its own quantity input', 1 === substr_count( $out, 'class="tack-quote-buttons"' ) && 1 === substr_count( $out, 'name="quantity"' ) && strpos( $out, 'name="quantity"' ) > strpos( $out, '</form>' ), $out );
tack_wo_fresh_widget();
$out = tack_wo_render( $wo_simple );
check( 'blockified purchasable simple: no extra quantity input (the block has its own)', 0 === substr_count( $out, 'name="quantity"' ), $out );

// ── Quote-only variable product: the block shows no selector, so the classic variation form follows it ──
$GLOBALS['TACK_HOOKS']   = array();
$GLOBALS['TACK_FILTERS'] = array();
$wo_catalog              = new Tack_Catalog_Mode();
$wo_catalog->init();
check( 'catalog mode: the quote-only variation form is hooked on the with-options block before the buttons (priority 4)', tack_parity_hooked( 'render_block_woocommerce/add-to-cart-with-options', 4 ) );
$wo_block          = new stdClass();
$wo_block->context = array( 'postId' => 60 );
$wo_qo_variable    = $wo_variable;
$wo_qo_variable->update_meta_data( '_tackquote_quote_only', 'yes' );
$wo_qo_variable->purchasable = false;
$GLOBALS['product']          = 'page-global';
$GLOBALS['TACK_FU_FORMS']    = 0;
$wo_catalog_fresh            = new Tack_Catalog_Mode();
$out                         = $wo_catalog_fresh->append_quote_only_variation_form( '<form class="wc-block-add-to-cart-with-options"></form>', array(), $wo_block );
check( 'quote-only variable: WooCommerce\'s variation form is rendered after the block (woocommerce_variable_add_to_cart)', 1 === $GLOBALS['TACK_FU_FORMS'] && 0 === strpos( $out, '<form class="wc-block-add-to-cart-with-options"></form>' ) );
check( 'and the page\'s global product is restored afterwards', 'page-global' === $GLOBALS['product'] );
$wo_qo_variable->purchasable = true;
$wo_qo_variable->update_meta_data( '_tackquote_quote_only', '' );
$GLOBALS['TACK_FU_FORMS'] = 0;
$wo_catalog_fresh         = new Tack_Catalog_Mode();
$wo_catalog_fresh->append_quote_only_variation_form( '', array(), $wo_block );
check( 'purchasable variable: no extra form (the block has its own selector)', 0 === $GLOBALS['TACK_FU_FORMS'] );
$wo_simple->update_meta_data( '_tackquote_quote_only', 'yes' );
$wo_simple->purchasable = false;
$wo_block->context      = array( 'postId' => 51 );
( new Tack_Catalog_Mode() )->append_quote_only_variation_form( '', array(), $wo_block );
check( 'quote-only simple: no variation form', 0 === $GLOBALS['TACK_FU_FORMS'] );
$wo_simple->purchasable = true;
$wo_simple->update_meta_data( '_tackquote_quote_only', '' );

// ── Classic mode of the same block (a product type with no template part) ──
tack_wo_fresh_widget();
$out = tack_wo_render( $wo_simple, 'simple' );
check( 'classic mode of with-options: the buttons stay INSIDE its classic form.cart, once', 1 === substr_count( $out, 'class="tack-quote-buttons"' ) && false !== strpos( $out, '<form class="cart"><div class="tack-quote-buttons">' ), $out );

// ── Byte-identical elsewhere ────────────────────────────────────────────────
$w                  = tack_wo_fresh_widget();
$GLOBALS['product'] = $wo_simple;
$classic            = tack_parity_capture( function () use ( $w ) { $w->render_product_button( '' ); } );
check( 'classic theme / add-to-cart-form: the container is unchanged (no data attributes)', 0 === strpos( $classic, '<div class="tack-quote-buttons"><' ), $classic );
$w   = tack_wo_fresh_widget();
$out = tack_block_render( 'woocommerce/add-to-cart-form', '', 51 );
check( 'add-to-cart-form block: appended container is unchanged too', 0 === strpos( $out, '<div class="tack-quote-buttons"><' ), $out );

// ── Depth bookkeeping ───────────────────────────────────────────────────────
Tack_Block_Product::enter_block( array( 'blockName' => 'woocommerce/add-to-cart-with-options' ) );
Tack_Block_Product::enter_block( array( 'blockName' => 'core/paragraph' ) );
check( 'depth: inside the block, a hook firing for a product is blockified', true === Tack_Block_Product::in_blockified_with_options( $wo_simple ) );
check( 'depth: a non-product is never treated as blockified', false === Tack_Block_Product::in_blockified_with_options( 'safety-gloves' ) );
Tack_Block_Product::leave_with_options( '' );
Tack_Block_Product::leave_with_options( '' );
check( 'depth: an extra leave never goes negative', false === Tack_Block_Product::in_blockified_with_options( $wo_simple ) );
Tack_Block_Product::enter_block( array( 'blockName' => 'woocommerce/add-to-cart-with-options' ) );
check( 'depth: and the next block is counted again', true === Tack_Block_Product::in_blockified_with_options( $wo_simple ) );
Tack_Block_Product::leave_with_options( '' );

// ── The storefront script uses the resolver for this block ──────────────────
$wo_js = (string) file_get_contents( TACK_QUOTES_DIR . 'assets/js/tack-quotes.js' );
check( 'tack-quotes.js resolves the block\'s variation through TackWithOptions', false !== strpos( $wo_js, 'api.resolve(' ) && false !== strpos( $wo_js, "form.wc-block-add-to-cart-with-options" ) );

unset( $GLOBALS['product'] );
