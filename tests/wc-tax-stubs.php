<?php
/**
 * WooCommerce 11.2.1 tax maths, transcribed for the tax-basis tests.
 *
 * WHAT THIS PROVES AND DOES NOT. Each function below is a line-for-line copy of
 * the WooCommerce 11.2.1 source it names (read from the installed plugin, not
 * inferred from our own code), reduced to one tax class and with rates set from
 * the test. They prove that `Tack_Tax_Basis` lands on the net line THROUGH
 * WooCommerce's arithmetic. They cannot prove WooCommerce still does this on a
 * later version; the Studio smoke in the PR is the real-store half of the pair.
 *
 * Driven by:
 *   $GLOBALS['TACK_BASE_RATES']      rates for the store's base location.
 *   $GLOBALS['TACK_CUSTOMER_RATES']  rates for the customer's location (null = base).
 *
 * @package TackQuotes
 */

$GLOBALS['TACK_BASE_RATES']     = array(
	1 => array(
		'rate'     => 20.0,
		'compound' => 'no',
	),
);
$GLOBALS['TACK_CUSTOMER_RATES'] = null;

if ( ! class_exists( 'WC_Tax' ) ) {
	/** WC_Tax (includes/class-wc-tax.php), the methods the plugin and the cart use. */
	class WC_Tax {
		/** @param mixed $in Value. @return float */
		public static function round( $in ) {
			return round( $in, 6 ); // wc_get_rounding_precision() = 6.
		}
		/** @param string $tax_class Class. @return array */
		public static function get_base_tax_rates( $tax_class = '' ) {
			unset( $tax_class );
			return $GLOBALS['TACK_BASE_RATES'];
		}
		/** @param string $tax_class Class. @param object $customer Customer. @return array */
		public static function get_rates( $tax_class = '', $customer = null ) {
			unset( $tax_class, $customer );
			return null === $GLOBALS['TACK_CUSTOMER_RATES'] ? $GLOBALS['TACK_BASE_RATES'] : $GLOBALS['TACK_CUSTOMER_RATES'];
		}
		/** @param float $price P. @param array $rates R. @param bool $price_includes_tax I. @return array */
		public static function calc_tax( $price, $rates, $price_includes_tax = false ) {
			return $price_includes_tax ? self::calc_inclusive_tax( $price, $rates ) : self::calc_exclusive_tax( $price, $rates );
		}
		/** @param float $price P. @param array $rates R. @return array */
		public static function calc_inclusive_tax( $price, $rates ) {
			$taxes          = array();
			$compound_rates = array();
			$regular_rates  = array();
			foreach ( $rates as $key => $rate ) {
				$taxes[ $key ] = 0;
				if ( 'yes' === $rate['compound'] ) {
					$compound_rates[ $key ] = $rate['rate'];
				} else {
					$regular_rates[ $key ] = $rate['rate'];
				}
			}
			$compound_rates     = array_reverse( $compound_rates, true );
			$non_compound_price = $price;
			foreach ( $compound_rates as $key => $compound_rate ) {
				$tax_amount         = $non_compound_price - ( $non_compound_price / ( 1 + ( $compound_rate / 100 ) ) );
				$taxes[ $key ]     += $tax_amount;
				$non_compound_price = $non_compound_price - $tax_amount;
			}
			$regular_tax_rate = 1 + ( array_sum( $regular_rates ) / 100 );
			foreach ( $regular_rates as $key => $regular_rate ) {
				$the_rate       = ( $regular_rate / 100 ) / $regular_tax_rate;
				$net_price      = $price - ( $the_rate * $non_compound_price );
				$taxes[ $key ] += $price - $net_price;
			}
			return array_map( array( __CLASS__, 'round' ), $taxes );
		}
		/** @param float $price P. @param array $rates R. @return array */
		public static function calc_exclusive_tax( $price, $rates ) {
			$taxes = array();
			$price = (float) $price;
			if ( ! empty( $rates ) ) {
				foreach ( $rates as $key => $rate ) {
					if ( 'yes' === $rate['compound'] ) {
						continue;
					}
					$taxes[ $key ] = ( isset( $taxes[ $key ] ) ? $taxes[ $key ] : 0.0 ) + $price * ( floatval( $rate['rate'] ) / 100 );
				}
				$pre_compound_total = array_sum( $taxes );
				foreach ( $rates as $key => $rate ) {
					if ( 'no' === $rate['compound'] ) {
						continue;
					}
					$taxes[ $key ]      = ( isset( $taxes[ $key ] ) ? $taxes[ $key ] : 0.0 ) + ( $price + $pre_compound_total ) * ( floatval( $rate['rate'] ) / 100 );
					$pre_compound_total = array_sum( $taxes );
				}
			}
			return array_map( array( __CLASS__, 'round' ), $taxes );
		}
	}
}

/** Is the WC() customer VAT exempt? */
function tack_stub_customer_exempt() {
	$wc = WC();
	return is_object( $wc ) && isset( $wc->customer ) && is_object( $wc->customer ) && $wc->customer->get_is_vat_exempt();
}

if ( ! function_exists( 'wc_get_price_including_tax' ) ) {
	/**
	 * Wc_get_price_including_tax() (wc-product-functions.php, 11.2.1), round-at-subtotal off.
	 *
	 * @param object $product Product.
	 * @param array  $args    qty/price.
	 * @return float
	 */
	function wc_get_price_including_tax( $product, $args = array() ) {
		$price      = isset( $args['price'] ) && '' !== $args['price'] ? max( 0.0, (float) $args['price'] ) : (float) $product->get_price();
		$qty        = isset( $args['qty'] ) && '' !== $args['qty'] ? max( 0.0, (float) $args['qty'] ) : 1;
		$line_price = $price * $qty;
		$return     = $line_price;
		if ( $product->is_taxable() ) {
			if ( ! wc_prices_include_tax() ) {
				$taxes_total = tack_stub_customer_exempt() ? 0.0 : array_sum( array_map( 'tack_stub_round_tax_total', WC_Tax::calc_tax( $line_price, WC_Tax::get_rates(), false ) ) );
				$return      = round( $line_price + $taxes_total, 2 );
			} else {
				$tax_rates = WC_Tax::get_rates();
				$base      = WC_Tax::get_base_tax_rates();
				if ( tack_stub_customer_exempt() ) {
					$remove = apply_filters( 'woocommerce_adjust_non_base_location_prices', true ) ? WC_Tax::calc_tax( $line_price, $base, true ) : WC_Tax::calc_tax( $line_price, $tax_rates, true );
					$return = round( $line_price - array_sum( array_map( 'tack_stub_round_tax_total', $remove ) ), 2 );
				} elseif ( $tax_rates !== $base && apply_filters( 'woocommerce_adjust_non_base_location_prices', true ) ) {
					$base_taxes   = WC_Tax::calc_tax( $line_price, $base, true );
					$modded_taxes = WC_Tax::calc_tax( $line_price - array_sum( $base_taxes ), $tax_rates, false );
					$return       = round( $line_price - array_sum( array_map( 'tack_stub_round_tax_total', $base_taxes ) ) + array_sum( array_map( 'tack_stub_round_tax_total', $modded_taxes ) ), 2 );
				}
			}
		}
		return $return;
	}
}

/** @param float $v Value. @return float wc_round_tax_total() at 2 dp. */
function tack_stub_round_tax_total( $v ) {
	return round( $v, 2 );
}

if ( ! function_exists( 'wc_get_price_to_display' ) ) {
	/**
	 * Wc_get_price_to_display() + wc_get_price_excluding_tax() (11.2.1, shop context, no order).
	 *
	 * @param object $product Product.
	 * @param array  $args    qty/price.
	 * @return float
	 */
	function wc_get_price_to_display( $product, $args = array() ) {
		$price = (float) $args['price'];
		$qty   = isset( $args['qty'] ) ? (float) $args['qty'] : 1.0;
		if ( 'incl' === get_option( 'woocommerce_tax_display_shop', 'excl' ) ) {
			return wc_get_price_including_tax(
				$product,
				array(
					'qty'   => $qty,
					'price' => $price,
				)
			);
		}
		$line = $price * $qty;
		if ( $product->is_taxable() && wc_prices_include_tax() ) {
			$rates = apply_filters( 'woocommerce_adjust_non_base_location_prices', true ) ? WC_Tax::get_base_tax_rates() : WC_Tax::get_rates();
			return $line - array_sum( WC_Tax::calc_tax( $line, $rates, true ) );
		}
		return $line;
	}
}

/**
 * WC_Cart_Totals::calculate_item_subtotals() for ONE line (11.2.1), in cents,
 * round-at-subtotal off. Returns what the cart stores on the line.
 *
 * @param object $product Product whose price set_price() set.
 * @param int    $qty     Quantity.
 * @return array{subtotal:float,tax:float}
 */
function tack_stub_cart_line( $product, $qty ) {
	$exempt             = tack_stub_customer_exempt();
	$calculate_tax      = ! $exempt;
	$price_includes_tax = wc_prices_include_tax();
	$taxable            = $product->is_taxable();
	$tax_rates          = WC_Tax::get_rates();
	$base               = WC_Tax::get_base_tax_rates();
	$adjust             = apply_filters( 'woocommerce_adjust_non_base_location_prices', true );
	$price              = round( (float) $product->get_price() * (float) $qty * 100, 4 ); // wc_add_number_precision_deep.

	if ( $price_includes_tax && $taxable ) {
		if ( $exempt ) { // remove_item_base_taxes().
			$taxes              = WC_Tax::calc_tax( $price, $adjust ? $base : $tax_rates, true );
			$price              = round( $price - array_sum( $taxes ) );
			$price_includes_tax = false;
		} elseif ( $adjust && $tax_rates !== $base ) { // adjust_non_base_location_price().
			$taxes     = WC_Tax::calc_tax( $price, $base, true );
			$new_taxes = WC_Tax::calc_tax( $price - array_sum( $taxes ), $tax_rates, false );
			$price     = $price - array_sum( $taxes ) + array_sum( $new_taxes );
		}
	}
	$subtotal = $price;
	$tax      = 0.0;
	if ( $calculate_tax && $taxable ) {
		$taxes = WC_Tax::calc_tax( $subtotal, $tax_rates, $price_includes_tax );
		$tax   = array_sum( array_map( 'round', $taxes ) ); // round_line_tax(): to the cent.
		if ( $price_includes_tax ) {
			$subtotal = $subtotal - array_sum( $taxes );
		}
	}
	return array(
		'subtotal' => round( $subtotal ) / 100, // get_rounded_items_total().
		'tax'      => $tax / 100,
	);
}
