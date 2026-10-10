<?php
/**
 * TackQuote prices are NET; this converts them to the basis the store enters
 * prices in, and to the basis it displays them in.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY ONE HELPER
 * ─────────────────────────────────────────────────────────────────────────────
 * Every TackQuote price is net of tax: `QuoteCalculationEngine` builds the
 * subtotal from `quantity * unitPrice` and adds tax ON TOP, and the storefront
 * pricing contract carries the same net figure. WooCommerce reads
 * `WC_Product::set_price()` in the basis the store ENTERS prices in. On a store
 * set to "Yes, I will enter prices inclusive of tax", a net figure handed over
 * unchanged is read as gross and the cart takes the tax back OUT of it: 10.00
 * net at 20 % became 8.33 + 1.67, so the seller absorbed the tax.
 *
 * Before 1.10.0 the B2B price path converted with `wc_get_price_including_tax()`.
 * That function is a no-op for this purpose: on an inclusive store it treats the
 * price it is given as ALREADY inclusive and returns it unchanged for a customer
 * at the base location (WooCommerce 11.2.1 `wc-product-functions.php`). The
 * quote checkout path (1.10.0) had the correct maths; this class is that maths,
 * shared by both paths so they cannot drift apart again.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE MATHS, AND WHY IT LANDS ON THE NET LINE
 * ─────────────────────────────────────────────────────────────────────────────
 * `WC_Cart_Totals::calculate_item_subtotals()` (11.2.1), for an inclusive store:
 *
 *   · customer VAT exempt  -> `remove_item_base_taxes()`: takes out the BASE
 *     rates when `woocommerce_adjust_non_base_location_prices` is true (the
 *     default), otherwise the CUSTOMER's rates (`$item->tax_rates`);
 *   · otherwise, filter true -> `adjust_non_base_location_price()`: takes out
 *     the BASE rates and adds the customer's rates on the net;
 *   · then the line tax is extracted inclusively at the customer's rates.
 *
 * So the gross we hand over is `net + WC_Tax::calc_tax( net, R, false )`, where
 * R is the BASE rates (filter true) or the customer's rates (filter false). Every
 * branch above then extracts exactly R from it and arrives back at the net line:
 * a base customer pays net + base tax, a non-base customer net + their own tax,
 * an exempt customer exactly net. `calc_tax()` is WooCommerce's own, so compound
 * rates are handled the way WooCommerce handles them.
 *
 * Nothing is rounded here. The cart works in cents to four decimals
 * (`wc_add_number_precision`) and rounds the line itself; pre-rounding a unit
 * gross to 2 dp is what puts a cent of drift on a quantity-100 line.
 *
 * @package TackQuotes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Converts TackQuote net prices to the store's entry and display basis.
 */
class Tack_Tax_Basis {

	/**
	 * A net UNIT price in the basis this store enters prices in: the value to
	 * hand to `set_price()`.
	 *
	 * Unchanged when the store enters prices exclusive of tax (or taxes are off),
	 * and for a product that is not taxable.
	 *
	 * @param string|float $net      Net unit price.
	 * @param object       $product  WC_Product (tax status and class).
	 * @param int          $quantity Line quantity; the tax is worked on the line,
	 *                               as the cart works it, then divided back.
	 * @return string|float The input unchanged, or the unrounded gross unit price.
	 */
	public static function entry_price( $net, $product, $quantity = 1 ) {
		if ( ! function_exists( 'wc_prices_include_tax' ) || ! wc_prices_include_tax() || ! class_exists( 'WC_Tax' ) ) {
			return $net;
		}
		if ( ! is_object( $product ) || ! method_exists( $product, 'is_taxable' ) || ! method_exists( $product, 'get_tax_class' ) ) {
			return $net;
		}
		if ( ! $product->is_taxable() ) {
			// WooCommerce extracts nothing from a non-taxable line, so nothing is added.
			return $net;
		}
		$quantity = max( 1, (int) $quantity );
		$line_net = (float) $net * $quantity;
		$taxes    = WC_Tax::calc_tax( $line_net, self::rates_the_cart_removes( $product ), false );
		return ( $line_net + array_sum( (array) $taxes ) ) / $quantity;
	}

	/**
	 * A net unit price as this store DISPLAYS prices in the shop: the entry-basis
	 * price through `wc_get_price_to_display()`, so the "Tax display" setting,
	 * the customer's location and a VAT-exempt buyer are honoured exactly as for
	 * the store's own prices. For a VAT-exempt buyer this is the net price.
	 *
	 * @param string|float $net     Net unit price.
	 * @param object       $product WC_Product.
	 * @return float
	 */
	public static function display_price( $net, $product ) {
		$entry = self::entry_price( $net, $product, 1 );
		if ( ! function_exists( 'wc_get_price_to_display' ) || ! is_object( $product ) || ! method_exists( $product, 'is_taxable' ) ) {
			return (float) $entry;
		}
		return (float) wc_get_price_to_display(
			$product,
			array(
				'qty'   => 1,
				'price' => $entry,
			)
		);
	}

	/**
	 * The store's own price for a product in the same display basis, for the
	 * struck-through "was" price beside a TackQuote price.
	 *
	 * @param float  $store   The store's entered price.
	 * @param object $product WC_Product.
	 * @return float
	 */
	public static function display_store_price( $store, $product ) {
		if ( ! function_exists( 'wc_get_price_to_display' ) || ! is_object( $product ) || ! method_exists( $product, 'is_taxable' ) ) {
			return (float) $store;
		}
		return (float) wc_get_price_to_display(
			$product,
			array(
				'qty'   => 1,
				'price' => $store,
			)
		);
	}

	/**
	 * The rates the cart will take back out of an inclusive price for this
	 * product (see the class comment): the BASE rates unless the store turned
	 * `woocommerce_adjust_non_base_location_prices` off, then the customer's.
	 *
	 * @param object $product WC_Product.
	 * @return array
	 */
	private static function rates_the_cart_removes( $product ) {
		/**
		 * Core WooCommerce filter, read exactly as WC_Cart_Totals reads it.
		 *
		 * @since 1.10.0
		 * @param bool $adjust Remove base taxes for customers outside the base location. Default true.
		 */
		if ( apply_filters( 'woocommerce_adjust_non_base_location_prices', true ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			return (array) WC_Tax::get_base_tax_rates( $product->get_tax_class( 'unfiltered' ) );
		}
		$customer = function_exists( 'WC' ) && is_object( WC() ) && isset( WC()->customer ) ? WC()->customer : null;
		return (array) WC_Tax::get_rates( $product->get_tax_class(), $customer );
	}
}
