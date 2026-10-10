<?php
/**
 * Product-page mounts on BLOCK themes.
 *
 * On a block theme the single-product page is a block template, and the classic
 * hooks the product-page controls were attached to behave differently there
 * (read from WooCommerce 11.2.1 and WordPress 7.1.3 source):
 *
 * - `woocommerce_after_add_to_cart_button` still fires, but only INSIDE the
 *   Add to Cart blocks (`woocommerce/add-to-cart-form` runs the classic
 *   `woocommerce_{type}_add_to_cart` templates; `woocommerce/add-to-cart-with-options`
 *   fires the hook itself). The classic templates return nothing for a product
 *   that is not purchasable (`templates/single-product/add-to-cart/simple.php`),
 *   which is exactly the quote-only and no-price case.
 * - `woocommerce_single_product_summary` is fired only by WooCommerce's block
 *   template compatibility layer (`SingleProductTemplateCompatibility::inject_hooks()`,
 *   a `render_block` filter), BEFORE the post excerpt. `global $product` is set
 *   there (`SingleProductTemplate::update_single_product_content()` calls
 *   `wc_setup_product_data()`), so the summary fallback rendered the buttons
 *   above the excerpt, outside the add-to-cart form, and the once-per-product
 *   flag then suppressed them inside it. Outside the form `tack-quotes.js` cannot
 *   read the quantity or the chosen variation: a variable product's "Request a
 *   Quote" was always refused, and "Add to Quote" added the parent at quantity 1.
 *   Verified on Studio (WooCommerce 11.2.1, Twenty Twenty-Five).
 *
 * So the controls are rendered through the Add to Cart blocks' own render
 * filters (`render_block_{$name}`, `wp-includes/class-wp-block.php`), and the
 * compatibility-layer firing of the summary hook is skipped so it cannot put
 * them above the excerpt, outside the form the JS reads. For a purchasable
 * product they then render inside the form (`woocommerce_after_add_to_cart_button`);
 * for a quote-only or price-less one, after the block.
 *
 * @package TackQuote
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shared helpers for the block-theme product-page mounts.
 *
 * @since 1.9.0
 */
class Tack_Block_Product {

	/**
	 * The Add to Cart blocks that can stand in for the classic add-to-cart form.
	 * `woocommerce/add-to-cart-with-options` is registered only on block themes
	 * (`BlockTypesController`, WooCommerce 11.2.1); filtering a name that is not
	 * registered is harmless.
	 */
	const ADD_TO_CART_BLOCKS = array( 'woocommerce/add-to-cart-form', 'woocommerce/add-to-cart-with-options' );

	/**
	 * Is the current action being fired by WooCommerce's block-template
	 * compatibility layer?
	 *
	 * That layer fires the classic summary hooks from inside a `render_block`
	 * filter. A classic theme never does, and the `woocommerce/legacy-template`
	 * block renders the classic template from its render callback, which runs
	 * before any `render_block` filter on the stack.
	 *
	 * @return bool
	 */
	public static function is_compat_hook() {
		return function_exists( 'doing_filter' ) && doing_filter( 'render_block' );
	}

	/**
	 * The product an Add to Cart block renders, from its `postId` context.
	 *
	 * @param WP_Block|object|null $instance Block instance passed to `render_block_{$name}`.
	 * @return WC_Product|null
	 */
	public static function from_block( $instance ) {
		if ( ! is_object( $instance ) || ! isset( $instance->context ) || ! is_array( $instance->context ) ) {
			return null;
		}
		$id = isset( $instance->context['postId'] ) ? (int) $instance->context['postId'] : 0;
		if ( $id <= 0 || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}
		$product = wc_get_product( $id );
		return $product instanceof WC_Product ? $product : null;
	}
}
