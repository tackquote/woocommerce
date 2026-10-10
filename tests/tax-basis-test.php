<?php
/**
 * Tack_Tax_Basis: a TackQuote NET price lands on the net line through
 * WooCommerce's own cart maths (tests/wc-tax-stubs.php, from 11.2.1), whatever
 * the store's tax basis, rates, customer location or exemption.
 *
 * The bug this pins: 1.8 converted with `wc_get_price_including_tax()`, which on
 * a store entering prices inclusive of tax returns the price unchanged, so a
 * 10.00 net B2B price was charged as 8.33 + 1.67 tax.
 *
 * Run via tests/run.php.
 *
 * @package TackQuotes
 */

echo "\nTax basis (net TackQuote prices on inclusive / exclusive stores)\n";

/**
 * A product priced at $net through the helper, then run through the cart line.
 *
 * @param string $net     Net unit price.
 * @param int    $qty     Quantity.
 * @param bool   $taxable Taxable?
 * @return array{set:float,subtotal:float,tax:float}
 */
function tack_tb_line( $net, $qty, $taxable = true ) {
	$product          = new Tack_Test_Product( 'TB-1' );
	$product->taxable = $taxable;
	$product->set_price( Tack_Tax_Basis::entry_price( $net, $product, $qty ) );
	$line = tack_stub_cart_line( $product, $qty );
	return array(
		'set'      => $product->get_price(),
		'subtotal' => $line['subtotal'],
		'tax'      => $line['tax'],
	);
}

/**
 * Set the adjust-non-base-location filter (null removes it, i.e. default true).
 *
 * @param bool|null $value Value.
 */
function tack_tb_adjust( $value ) {
	unset( $GLOBALS['TACK_FILTERS']['woocommerce_adjust_non_base_location_prices'] );
	if ( null !== $value ) {
		$GLOBALS['TACK_FILTERS']['woocommerce_adjust_non_base_location_prices'][] = $value ? '__return_true' : '__return_false';
	}
}
if ( ! function_exists( '__return_true' ) ) {
	/** @return bool */
	function __return_true() {
		return true;
	}
}
if ( ! function_exists( '__return_false' ) ) {
	/** @return bool */
	function __return_false() {
		return false;
	}
}

/**
 * The worst cent drift between the net line owed and the cart's line subtotal.
 *
 * @return float
 */
function tack_tb_worst_drift() {
	$worst = 0.0;
	foreach ( array( array( '10.00', 1 ), array( '33.3333', 3 ), array( '19.99', 7 ), array( '0.0833', 12 ), array( '10.01', 100 ), array( '1234.56', 9 ) ) as $c ) {
		$line  = tack_tb_line( $c[0], $c[1] );
		$owed  = round( (float) $c[0] * $c[1], 2 );
		$worst = max( $worst, abs( round( ( $line['subtotal'] - $owed ) * 100 ) ) );
	}
	return $worst;
}

$tb_customer                 = new Tack_Stub_Customer();
$GLOBALS['TACK_WC_CUSTOMER'] = $tb_customer;
$tb_twenty                   = array(
	1 => array(
		'rate'     => 20.0,
		'compound' => 'no',
	),
);
$tb_ten                      = array(
	7 => array(
		'rate'     => 10.0,
		'compound' => 'no',
	),
);

// ── Prices entered EXCLUSIVE of tax: untouched ──────────────────────────────
tack_test_set_prices_include_tax( false );
$probe = new Tack_Test_Product( 'TB-1' );
check( 'exclusive store: the net price goes to set_price() unchanged', '10.00' === Tack_Tax_Basis::entry_price( '10.00', $probe, 1 ) );
$l = tack_tb_line( '10.00', 1 );
check( 'exclusive store: cart line 10.00 + 2.00 tax', 10.0 === $l['subtotal'] && 2.0 === $l['tax'], wp_json_encode( $l ) );

// ── Prices entered INCLUSIVE of tax, one 20 % base rate ─────────────────────
tack_test_set_prices_include_tax( true );
$l = tack_tb_line( '10.00', 1 );
check( 'inclusive store, 20 % base: net 10.00 is set as 12.00', abs( 12.0 - $l['set'] ) < 1e-9, wp_json_encode( $l ) );
check( 'inclusive store, 20 % base: cart line total excl. tax 10.00, tax 2.00', 10.0 === $l['subtotal'] && 2.0 === $l['tax'], wp_json_encode( $l ) );
check( 'inclusive store, 20 % base: no line drifts a cent from the net owed (qty 1..100)', 0.0 === tack_tb_worst_drift(), 'worst ' . tack_tb_worst_drift() );
$wc_said = wc_get_price_including_tax(
	$probe,
	array(
		'qty'   => 1,
		'price' => 10.0,
	)
);
check( 'control: wc_get_price_including_tax() returns the price UNCHANGED here (why 1.8 undercharged)', 10.0 === (float) $wc_said, var_export( $wc_said, true ) );

// ── Compound rates ──────────────────────────────────────────────────────────
$GLOBALS['TACK_BASE_RATES'] = array(
	1 => array(
		'rate'     => 10.0,
		'compound' => 'no',
	),
	2 => array(
		'rate'     => 5.0,
		'compound' => 'yes',
	),
);
$l                          = tack_tb_line( '10.00', 1 );
check( 'compound 10 % + 5 %: net 10.00 set as 11.55, cart 10.00 + 1.55 tax', abs( 11.55 - $l['set'] ) < 1e-9 && 10.0 === $l['subtotal'] && 1.55 === $l['tax'], wp_json_encode( $l ) );
check( 'compound 10 % + 5 %: no line drifts a cent', 0.0 === tack_tb_worst_drift(), 'worst ' . tack_tb_worst_drift() );
$GLOBALS['TACK_BASE_RATES'] = $tb_twenty;

// ── A product that is not taxable ───────────────────────────────────────────
$l = tack_tb_line( '10.00', 1, false );
check( 'non-taxable product on an inclusive store: set 10.00, cart 10.00, no tax', 10.0 === (float) $l['set'] && 10.0 === $l['subtotal'] && 0.0 === $l['tax'], wp_json_encode( $l ) );

// ── A VAT-exempt buyer (what Tack_Tax_Exempt sets for a taxExempt group) ────
$tb_customer->set_is_vat_exempt( true );
foreach ( array( true, false ) as $adjust ) {
	tack_tb_adjust( $adjust );
	$l = tack_tb_line( '10.00', 1 );
	check( 'tax-exempt buyer at the base location (adjust filter ' . var_export( $adjust, true ) . '): pays exactly 10.00, no tax', 10.0 === $l['subtotal'] && 0.0 === $l['tax'], wp_json_encode( $l ) );
	$GLOBALS['TACK_CUSTOMER_RATES'] = $tb_ten;
	$l                              = tack_tb_line( '10.00', 1 );
	check( 'tax-exempt buyer outside the base location (adjust filter ' . var_export( $adjust, true ) . '): pays exactly 10.00', 10.0 === $l['subtotal'] && 0.0 === $l['tax'], wp_json_encode( $l ) );
	$GLOBALS['TACK_CUSTOMER_RATES'] = null;
}
tack_tb_adjust( null );
$tb_customer->set_is_vat_exempt( false );

// ── A customer outside the base location (10 % there, 20 % at base) ─────────
$GLOBALS['TACK_CUSTOMER_RATES'] = $tb_ten;
tack_tb_adjust( true );
$l = tack_tb_line( '10.00', 1 );
check( 'non-base customer, adjust_non_base_location_prices true: set 12.00 (base), cart 10.00 + 1.00 local tax', abs( 12.0 - $l['set'] ) < 1e-9 && 10.0 === $l['subtotal'] && 1.0 === $l['tax'], wp_json_encode( $l ) );
tack_tb_adjust( false );
$l = tack_tb_line( '10.00', 1 );
check( 'non-base customer, adjust_non_base_location_prices false: set 11.00 (their rate), cart 10.00 + 1.00 tax', abs( 11.0 - $l['set'] ) < 1e-9 && 10.0 === $l['subtotal'] && 1.0 === $l['tax'], wp_json_encode( $l ) );
check( 'non-base customer, adjust false: no line drifts a cent', 0.0 === tack_tb_worst_drift(), 'worst ' . tack_tb_worst_drift() );
tack_tb_adjust( null );
$GLOBALS['TACK_CUSTOMER_RATES'] = null;

// ── What is SHOWN is what is charged ────────────────────────────────────────
$probe = new Tack_Test_Product( 'TB-1' );
tack_test_set_option( 'woocommerce_tax_display_shop', 'incl' );
check( 'inclusive store shown incl. tax: net 10.00 displays 12.00', 12.0 === Tack_Tax_Basis::display_price( '10.00', $probe ) );
$tb_customer->set_is_vat_exempt( true );
check( 'shown incl. tax to a VAT-exempt buyer: displays the net 10.00 they pay', 10.0 === Tack_Tax_Basis::display_price( '10.00', $probe ) );
$tb_customer->set_is_vat_exempt( false );
tack_test_set_option( 'woocommerce_tax_display_shop', 'excl' );
check( 'inclusive store shown excl. tax: net 10.00 displays 10.00', abs( 10.0 - Tack_Tax_Basis::display_price( '10.00', $probe ) ) < 1e-9 );
tack_test_set_prices_include_tax( false );
tack_test_set_option( 'woocommerce_tax_display_shop', 'incl' );
check( 'exclusive store shown incl. tax: net 10.00 displays 12.00, as the store shows its own prices', 12.0 === Tack_Tax_Basis::display_price( '10.00', $probe ) );
tack_test_set_option( 'woocommerce_tax_display_shop', 'excl' );

// ── The B2B listing label goes through the helper ───────────────────────────
tack_test_set_prices_include_tax( true );
tack_test_set_option( 'woocommerce_tax_display_shop', 'incl' );
tack_test_set_option( 'tack_quotes_api_key', 'tk_test_key' );
tack_test_set_option( Tack_Wholesale_Pricing::OPTION_ENABLED, 'yes' );
tack_test_set_logged_in( true, 'buyer@trade-customer.test' );
$tb_client = new Tack_Test_Pricing_Client( array( 'items' => array( array( 'sku' => 'TB-1', 'quantity' => 1, 'unitPrice' => 10.0 ) ) ) );
$tb_prod   = new Tack_Test_Product( 'TB-1' );
$tb_html   = ( new Tack_Wholesale_Pricing( $tb_client ) )->filter_price_html( 'store', $tb_prod );
check( 'listing label on an inclusive store shown incl. tax: 12.00, the amount the cart charges', false !== strpos( $tb_html, '12.00' ), $tb_html );
$tb_customer->set_is_vat_exempt( true );
$tb_html = ( new Tack_Wholesale_Pricing( $tb_client ) )->filter_price_html( 'store', $tb_prod );
check( 'listing label for a VAT-exempt buyer: 10.00', false !== strpos( $tb_html, '10.00' ) && false === strpos( $tb_html, '12.00' ), $tb_html );
$tb_customer->set_is_vat_exempt( false );

tack_test_set_option( Tack_Wholesale_Pricing::OPTION_ENABLED, 'no' );
tack_test_set_logged_in( false, '' );
tack_test_set_option( 'woocommerce_tax_display_shop', 'excl' );
tack_test_set_prices_include_tax( false );
$GLOBALS['TACK_WC_CUSTOMER'] = null;
