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
 *
 * So the controls are rendered through the Add to Cart blocks' own render
 * filters (`render_block_{$name}`, `wp-includes/class-wp-block.php`), and the
 * compatibility-layer firing of the summary hook is skipped so it cannot put
 * them above the excerpt, outside the form the JS reads. For a purchasable
 * product they then render inside the form (`woocommerce_after_add_to_cart_button`);
 * for a quote-only or price-less one, after the block.
 *
 * `woocommerce/add-to-cart-with-options` has a second, "blockified" mode for the
 * core product types (`AddToCartWithOptions::render()`, WooCommerce 11.2.1): it
 * renders its own `<form>` from a block template part, with an Interactivity API
 * variation selector, and fires the classic button hooks itself, directly from
 * `render()`. Anything with a form element printed there (our buttons) switches
 * that form to a plain posted form (`has_form_elements()`, "legacy mode"). So in
 * that mode the buttons are rendered after the block instead, with the data
 * `tack-quotes.js` needs to read the block's variation and quantity; see
 * `in_blockified_with_options()` and `variation_states()`.
 *
 * @package TackQuote
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shared helpers for the block-theme product-page mounts.
 *
 * @since 1.10.0
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
	 * WooCommerce's newer Add to Cart block.
	 *
	 * @since 1.10.0
	 */
	const WITH_OPTIONS = 'woocommerce/add-to-cart-with-options';

	/**
	 * How many `woocommerce/add-to-cart-with-options` blocks are rendering right now.
	 *
	 * @var int
	 */
	private static $with_options_depth = 0;

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
	 * `render_block_data`: note that a `woocommerce/add-to-cart-with-options`
	 * block starts rendering.
	 *
	 * `render_block_data` runs for top-level and inner blocks alike, after
	 * `pre_render_block` (wp-includes/blocks.php and class-wp-block.php,
	 * WordPress 7.1.3), so a short-circuited block is never counted. The matching
	 * `render_block_{$name}` filter, applied when the block finishes, is
	 * `leave_with_options()`.
	 *
	 * @since 1.10.0
	 *
	 * @param array|mixed $parsed_block Parsed block.
	 * @return array|mixed Unchanged.
	 */
	public static function enter_block( $parsed_block ) {
		if ( is_array( $parsed_block ) && isset( $parsed_block['blockName'] ) && self::WITH_OPTIONS === $parsed_block['blockName'] ) {
			++self::$with_options_depth;
		}
		return $parsed_block;
	}

	/**
	 * `render_block_woocommerce/add-to-cart-with-options`: the block finished.
	 *
	 * @since 1.10.0
	 *
	 * @param string|mixed $block_content Rendered block.
	 * @return string|mixed Unchanged.
	 */
	public static function leave_with_options( $block_content ) {
		if ( self::$with_options_depth > 0 ) {
			--self::$with_options_depth;
		}
		return $block_content;
	}

	/**
	 * Is a classic add-to-cart hook being fired by the Add to Cart with Options
	 * block in its blockified mode, i.e. from inside that block's own `<form>`?
	 *
	 * In that mode `AddToCartWithOptions::render()` fires the hooks itself. In its
	 * other mode (a product type with no block template part) it runs the classic
	 * template through `woocommerce_{type}_add_to_cart`, which renders a classic
	 * `form.cart` the buttons belong in. Read from WooCommerce 11.2.1.
	 *
	 * @since 1.10.0
	 *
	 * @param WC_Product|mixed $product The product the hook fired for.
	 * @return bool
	 */
	public static function in_blockified_with_options( $product ) {
		if ( self::$with_options_depth <= 0 || ! $product instanceof WC_Product ) {
			return false;
		}
		// WooCommerce renders a variation with the simple template part.
		$type = $product->is_type( 'variation' ) ? 'simple' : ( method_exists( $product, 'get_type' ) ? (string) $product->get_type() : '' );
		return ! ( function_exists( 'doing_action' ) && doing_action( 'woocommerce_' . $type . '_add_to_cart' ) );
	}

	/**
	 * Per-variation state for the quote buttons rendered after a blockified Add to
	 * Cart with Options block: `{ "<variation id>": { "q": 1|0, "l": "Blue, Large" } }`.
	 *
	 * The block's variation selector is an Interactivity API store, not the
	 * classic `form.variations_form` with its `found_variation` / `show_variation`
	 * events. What it does publish in the page is the hidden
	 * `input[name="variation_id"]` inside its form, bound to the selected
	 * variation's id ("used by extensions or Express Payment methods to gather
	 * information of the form state", `AddToCartWithOptions::render()`, 11.2.1).
	 * Its stock and visibility live only in WooCommerce's private
	 * `woocommerce/products` store, so they are given here instead.
	 *
	 * `q` is the classic contract (`tack-quotes.js`, `show_variation`): a variation
	 * is quotable when it is visible and in stock; purchasability is deliberately
	 * not part of it (a quote-only product is non-purchasable on purpose).
	 *
	 * @since 1.10.0
	 *
	 * @param WC_Product|mixed $product The variable product.
	 * @return array<string, array{q:int, l:string}> Empty for anything else.
	 */
	public static function variation_states( $product ) {
		$states = array();
		if ( ! $product instanceof WC_Product || ! $product->is_type( 'variable' ) || ! function_exists( 'wc_get_product' ) ) {
			return $states;
		}
		foreach ( (array) $product->get_children() as $child_id ) {
			$variation = wc_get_product( $child_id );
			if ( ! $variation instanceof WC_Product ) {
				continue;
			}
			$visible = method_exists( $variation, 'variation_is_visible' ) && $variation->variation_is_visible();
			$label   = function_exists( 'wc_get_formatted_variation' ) ? wp_strip_all_tags( (string) wc_get_formatted_variation( $variation, true, false, false ) ) : '';

			$states[ (string) (int) $child_id ] = array(
				'q' => ( $visible && $variation->is_in_stock() ) ? 1 : 0,
				'l' => $label,
			);
		}
		return $states;
	}

	/**
	 * Each variation's own SKU and unit price, for the quote-list row an "Add to
	 * Quote" click builds. The button carries the PARENT's SKU and
	 * price, which for a variable product is the cheapest variation's, so a Medium at
	 * 12.00 was listed as the parent SKU at 10.00.
	 *
	 * The price is excluding tax, the same basis as every other row in the list (the
	 * parent button, product cards, the cart snapshot), the quote page's "Unit price
	 * (excl. tax)" column and the `unitPrice` the request sends. `null` when the
	 * variation has no price. `get_sku()` falls back to the parent's SKU when the
	 * variation has none, as WooCommerce itself displays it. The server re-derives
	 * every value from the variation id; this is only what the shopper is shown.
	 *
	 * @since 1.10.0
	 *
	 * @param WC_Product|mixed $product The variable product.
	 * @return array<string, array{s:string, p:float|null}> Empty for anything else.
	 */
	public static function variation_lines( $product ) {
		$lines = array();
		if ( ! $product instanceof WC_Product || ! $product->is_type( 'variable' ) || ! function_exists( 'wc_get_product' ) ) {
			return $lines;
		}
		foreach ( (array) $product->get_children() as $child_id ) {
			$variation = wc_get_product( $child_id );
			if ( ! $variation instanceof WC_Product ) {
				continue;
			}
			$price = '' === (string) $variation->get_price() || ! function_exists( 'wc_get_price_excluding_tax' )
				? null
				: (float) wc_get_price_excluding_tax( $variation );

			$lines[ (string) (int) $child_id ] = array(
				's' => (string) $variation->get_sku(),
				'p' => $price,
			);
		}
		return $lines;
	}

	/**
	 * Does the Add to Cart with Options block render no quantity input for this
	 * product? Its Quantity Selector inner block returns nothing for a product it
	 * treats as not purchasable (`QuantitySelector::render()` and
	 * `Utils::is_not_purchasable_product()`, WooCommerce 11.2.1): a simple product
	 * out of stock or not purchasable, a variable product out of stock or with no
	 * purchasable variation (its Variation Selector then renders nothing either).
	 * A quote-only product is that case: a simple one gets a quantity input beside
	 * the buttons, a variable one WooCommerce's classic variation form
	 * (`Tack_Catalog_Mode::append_quote_only_variation_form()`).
	 *
	 * @since 1.10.0
	 *
	 * @param WC_Product $product The block's product.
	 * @return bool
	 */
	public static function with_options_omits_quantity( $product ) {
		if ( $product->is_type( 'simple' ) ) {
			return ! $product->is_in_stock() || ! $product->is_purchasable();
		}
		if ( $product->is_type( 'variable' ) ) {
			return ! $product->is_in_stock() || ! $product->has_purchasable_variations();
		}
		return false;
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
