<?php
/**
 * Block themes: the product-page controls reach the Add to Cart blocks.
 *
 * On a block theme the single-product page is a block template. The classic
 * `woocommerce_after_add_to_cart_button` fires only inside a rendered add-to-cart
 * form (nothing for a quote-only or price-less product), and the summary hook is
 * fired by WooCommerce's compatibility layer from inside a `render_block` filter,
 * where `global $product` is the product slug. See `Tack_Block_Product`.
 *
 * `render_block_{$name}` is invoked here the way `WP_Block::render()` does
 * (wp-includes/class-wp-block.php, WordPress 7.1.3): ( $content, $parsed_block,
 * $instance ), with the product in `$instance->context['postId']`.
 *
 * Runs after storefront-parity-test.php, whose product doubles and stubs it uses.
 *
 * Run: php tests/run.php
 *
 * @package TackQuotes
 */

$GLOBALS['TACK_DOING_FILTER'] = array();
if ( ! function_exists( 'doing_filter' ) ) {
	/** @param string|null $hook Hook. @return bool */
	function doing_filter( $hook = null ) {
		return in_array( $hook, $GLOBALS['TACK_DOING_FILTER'], true );
	}
}

/**
 * A `WP_Block` as far as the filter reads it.
 *
 * @param int $post_id Product id in context.
 * @return object
 */
function tack_block_instance( $post_id ) {
	$b          = new stdClass();
	$b->name    = 'woocommerce/add-to-cart-form';
	$b->context = array( 'postId' => $post_id, 'postType' => 'product' );
	return $b;
}

/**
 * Run every callback init() registered on a block's render filter, as WordPress would.
 *
 * @param string $block   Block name.
 * @param string $content Block output.
 * @param int    $post_id Product id.
 * @return string
 */
function tack_block_render( $block, $content, $post_id ) {
	return apply_filters( 'render_block_' . $block, $content, array( 'blockName' => $block ), tack_block_instance( $post_id ) );
}

$GLOBALS['TACK_OPTIONS']['tack_quotes_show_add_to_quote']  = 'yes';
$GLOBALS['TACK_OPTIONS']['tack_quotes_show_request_quote'] = 'yes';

// ── Registration ────────────────────────────────────────────────────────────
$GLOBALS['TACK_HOOKS']   = array();
$GLOBALS['TACK_FILTERS'] = array();
$w = new Tack_Widget();
$w->init();
foreach ( array( 'woocommerce/add-to-cart-form', 'woocommerce/add-to-cart-with-options' ) as $name ) {
	$found = false;
	foreach ( $GLOBALS['TACK_HOOKS'] as $h ) {
		if ( 'render_block_' . $name === $h['hook'] && 3 === (int) $h['args'] ) {
			$found = true;
		}
	}
	check( "block theme: buttons hooked on render_block_$name with 3 args", $found );
}

// ── Non-purchasable product: the block renders '' and the filter supplies the buttons ──
$out = tack_block_render( 'woocommerce/add-to-cart-form', '', 11 );
check( 'block theme: quote-only/no-price product gets exactly one set of buttons from the block filter', 1 === substr_count( $out, 'class="tack-quote-buttons"' ), $out );
check( 'block theme: the buttons name the BLOCK\'s product', false !== strpos( $out, 'data-product-id="11"' ), $out );
check( 'block theme: a second Add to Cart block for the same product adds nothing', '<x/>' === tack_block_render( 'woocommerce/add-to-cart-form', '<x/>', 11 ) );

// ── Purchasable product: buttons already inside the form; the filter must not add a second set ──
$GLOBALS['TACK_HOOKS']   = array();
$GLOBALS['TACK_FILTERS'] = array();
$w = new Tack_Widget();
$w->init();
$GLOBALS['product'] = $simple;
$inside             = tack_parity_capture( function () use ( $w ) { $w->render_product_button( '' ); } );
$form               = '<div class="wp-block-add-to-cart-form"><form class="cart">' . $inside . '</form></div>';
$out                = tack_block_render( 'woocommerce/add-to-cart-form', $form, 11 );
check( 'block theme: purchasable product renders ONE set (inside the form), not a second after it', 1 === substr_count( $out, 'class="tack-quote-buttons"' ) && $out === $form, $out );

// ── WooCommerce's compatibility layer fires the summary hook inside render_block ──
$GLOBALS['TACK_HOOKS']   = array();
$GLOBALS['TACK_FILTERS'] = array();
$w = new Tack_Widget();
$w->init();
$GLOBALS['product']           = $simple;
$GLOBALS['TACK_DOING_FILTER'] = array( 'render_block' );
$compat                       = tack_parity_capture( array( $w, 'render_product_button_fallback' ) );
$GLOBALS['TACK_DOING_FILTER'] = array();
check( 'block theme: the compatibility-layer summary hook (before the excerpt) prints nothing', '' === $compat, $compat );
$out = tack_block_render( 'woocommerce/add-to-cart-with-options', '', 11 );
check( 'and does not consume the once-flag: the Add to Cart block still gets the buttons', 1 === substr_count( $out, 'class="tack-quote-buttons"' ), $out );

// Classic theme: the summary fallback still renders (quote-only mode withdrew the form).
$w2                 = new Tack_Widget();
$GLOBALS['product'] = $simple;
$classic            = tack_parity_capture( array( $w2, 'render_product_button_fallback' ) );
check( 'classic theme: the summary fallback still renders the buttons', 1 === substr_count( $classic, 'class="tack-quote-buttons"' ), $classic );
$again = tack_parity_capture( function () use ( $w2 ) { $w2->render_product_button( '' ); } );
check( 'classic theme: both classic mounts firing still render once', '' === $again, $again );

// A hook firing with the product SLUG as `global $product` (block template, no main loop) renders nothing.
$w3                 = new Tack_Widget();
$GLOBALS['product'] = 'safety-gloves';
check( 'a slug in global $product is not mistaken for a product', '' === tack_parity_capture( function () use ( $w3 ) { $w3->render_product_button( '' ); } ) );

// ── Variable product: the data the JS needs ─────────────────────────────────
$w4  = new Tack_Widget();
$GLOBALS['TACK_FILTERS'] = array();
$w4->init();
$out = tack_block_render( 'woocommerce/add-to-cart-form', '', 20 );
check(
	'variable product markup carries the attributes tack-quotes.js reads',
	false !== strpos( $out, 'class="button tack-add-to-quote-btn"' ) && false !== strpos( $out, 'data-product-id="20"' )
		&& false !== strpos( $out, 'data-product-name="Work Boots"' ) && false !== strpos( $out, 'data-product-sku="WB"' )
		&& false !== strpos( $out, 'data-product-price="50"' ) && false !== strpos( $out, 'tack-quote-btn' ),
	$out
);

// ── Settings off / no product ───────────────────────────────────────────────
$GLOBALS['TACK_OPTIONS']['tack_quotes_show_add_to_quote']  = 'no';
$GLOBALS['TACK_OPTIONS']['tack_quotes_show_request_quote'] = 'no';
$GLOBALS['TACK_FILTERS']                                   = array();
( new Tack_Widget() )->init();
check( 'both buttons switched off: the block is returned untouched', '<b/>' === tack_block_render( 'woocommerce/add-to-cart-form', '<b/>', 11 ) );
$GLOBALS['TACK_OPTIONS']['tack_quotes_show_add_to_quote']  = 'yes';
$GLOBALS['TACK_OPTIONS']['tack_quotes_show_request_quote'] = 'yes';
$w5 = new Tack_Widget();
check( 'no postId context: the block is returned untouched', '<b/>' === $w5->append_to_add_to_cart_block( '<b/>', array(), new stdClass() ) );
check( 'unknown product id: the block is returned untouched', '<b/>' === $w5->append_to_add_to_cart_block( '<b/>', array(), tack_block_instance( 999 ) ) );

// ── Order-limit courtesy notice ─────────────────────────────────────────────
tack_test_set_option( 'tack_quotes_api_key', 'tk_test_key' );
tack_test_set_option( Tack_B2B_Notices::OPTION_ORDER_LIMITS, 'yes' );
tack_test_set_logged_in( true, 'buyer@trade-customer.test' );
$GLOBALS['TACK_TRANSIENTS'] = array();
$GLOBALS['TACK_HOOKS']      = array();
$GLOBALS['TACK_FILTERS']    = array();
$limits_client              = new Tack_Test_Notices_Client(
	array(
		'order-limits' => array(
			'status'          => 'limited',
			'accountSpecific' => true,
			'limits'          => array( array( 'limitType' => 'product_qty', 'min' => 25, 'max' => null ) ),
		),
	)
);
$n = new Tack_B2B_Notices( $limits_client );
$n->init();
$out = tack_block_render( 'woocommerce/add-to-cart-form', '<form class="cart"></form>', 11 );
check( 'block theme: the order-limit notice is printed ABOVE the Add to Cart block', 0 === strpos( $out, '<p class="tackquote-order-limit">' ) && 1 === substr_count( $out, 'tackquote-order-limit' ), $out );
$GLOBALS['product'] = $simple;
check( 'and the classic summary hook then prints it no second time', '' === tack_parity_capture( function () use ( $n ) { $n->render_order_limit_notice( '' ); } ) );

$n2                           = new Tack_B2B_Notices( $limits_client );
$GLOBALS['product']           = 'safety-gloves';
$GLOBALS['TACK_DOING_FILTER'] = array( 'render_block' );
$compat                       = tack_parity_capture( function () use ( $n2 ) { $n2->render_order_limit_notice( '' ); } );
$GLOBALS['TACK_DOING_FILTER'] = array();
check( 'block theme: the compatibility-layer summary hook prints no notice', '' === $compat, $compat );
$GLOBALS['product'] = $simple;
$classic            = tack_parity_capture( function () use ( $n2 ) { $n2->render_order_limit_notice( '' ); } );
check( 'classic theme: the summary hook still prints the notice', 1 === substr_count( $classic, 'tackquote-order-limit' ), $classic );

tack_test_set_option( Tack_B2B_Notices::OPTION_ORDER_LIMITS, 'no' );
unset( $GLOBALS['product'] );
