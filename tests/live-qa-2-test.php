<?php
/**
 * 1.12.0 live QA re-run (2026-10-26, lane WOO-QA-2) fixes on the PHP side:
 *
 *   - An optional address counts as started only when the shopper typed a street,
 *     city or postal code: the country and region are prefilled (WooCommerce account,
 *     store default), and on their own they made the net-terms application demand a
 *     street address nobody had begun.
 *   - An embed claim TackQuote refuses because of the store's key shows one message
 *     (the refused key, and Reconnect), not "did not answer" beside it.
 *   - The date hint reads as a sentence; the WordPress link page names TackQuote's
 *     Integrations page; an http site's "Allow catalogue and order sync" is disabled
 *     with the reason up front.
 *
 * Runs from tests/run.php after product-types-test.php. Uses check() from there.
 *
 * @package TackQuotes
 */

defined( 'ABSPATH' ) || exit;

// ── Address started ─────────────────────────────────────────────────────────
check( 'address: country and region alone are not a started address', false === Tack_Storefront_Forms::address_started( array( 'country' => 'US', 'region' => 'CA' ) ) );
check( 'address: nothing at all is not started', false === Tack_Storefront_Forms::address_started( array() ) );
check( 'address: whitespace is not typing', false === Tack_Storefront_Forms::address_started( array( 'line1' => '   ', 'country' => 'US' ) ) );
foreach ( array( 'line1', 'line2', 'city', 'postalCode' ) as $qa2_part ) {
	check( "address: a typed $qa2_part starts it", true === Tack_Storefront_Forms::address_started( array( $qa2_part => 'x', 'country' => 'US' ) ) );
}

$qa2_forms = new Tack_Storefront_Forms( new Tack_Test_Forms_Client( array() ) );
$qa2_v     = $qa2_forms->validate_credit_payload(
	array(
		'legalBusinessName' => 'Acme Ltd',
		'billingAddress'    => array( 'country' => 'US', 'region' => 'CA' ),
	),
	'buyer@trade-customer.test'
);
check( 'net terms: a prefilled country/region alone is no error (was "street address is required")', null === $qa2_v['error'], (string) $qa2_v['error'] );
check( 'net terms: and no billing address is sent', ! array_key_exists( 'billingAddress', $qa2_v['payload'] ), var_export( $qa2_v['payload'], true ) );
$qa2_v = $qa2_forms->validate_credit_payload(
	array(
		'legalBusinessName' => 'Acme Ltd',
		'billingAddress'    => array( 'city' => 'Leeds', 'country' => 'GB' ),
	),
	'buyer@trade-customer.test'
);
check( 'net terms: a started address is still checked (street missing)', null !== $qa2_v['error'] && false !== strpos( $qa2_v['error'], 'street address' ), (string) $qa2_v['error'] );

// The designed (wholesale) form: an optional address field holding only the prefill.
$qa2_fields = array(
	array(
		'key'      => 'billing',
		'type'     => 'address',
		'label'    => 'Billing address',
		'required' => false,
	),
);
$qa2_r      = $qa2_forms->collect_answers( $qa2_fields, array( 'billing' => array( 'country' => 'US', 'region' => 'TX' ) ) );
check( 'designed form: an optional address with only the prefill is unanswered, not an error', null === $qa2_r['error'] && ! array_key_exists( 'billing', $qa2_r['values'] ), var_export( $qa2_r, true ) );
$qa2_fields[0]['required'] = true;
$qa2_r                     = $qa2_forms->collect_answers( $qa2_fields, array( 'billing' => array( 'country' => 'US', 'region' => 'TX' ) ) );
check( 'designed form: a REQUIRED address with only the prefill still asks for it', null !== $qa2_r['error'] && false !== strpos( (string) $qa2_r['error'], 'Billing address' ), var_export( $qa2_r, true ) );

// ── Embed claim with a refused key ──────────────────────────────────────────
$qa2_claim = new ReflectionMethod( 'Tack_Embed_Admin', 'claim_result' );
$qa2_claim->setAccessible( true );
check( 'embed claim: a 401 (the store\'s key refused) is "disconnected", not "did not answer"', 'disconnected' === $qa2_claim->invoke( null, new WP_Error( 'tack_http', 'x', array( 'status' => 401 ) ) ) );
check( 'embed claim: no key is "disconnected"', 'disconnected' === $qa2_claim->invoke( null, new WP_Error( 'tack_no_key', 'x' ) ) );
check( 'embed claim: a 403 stays the claim\'s own refusal', 'refused' === $qa2_claim->invoke( null, new WP_Error( 'tack_http', 'x', array( 'status' => 403 ) ) ) );
check( 'embed claim: an outage stays "failed"', 'failed' === $qa2_claim->invoke( null, new WP_Error( 'tack_http', 'x', array( 'status' => 503 ) ) ) );
$qa2_embed = (string) file_get_contents( TACK_QUOTES_DIR . 'includes/class-tack-embed-admin.php' );
$qa2_flag  = strpos( $qa2_embed, "if ( 'disconnected' === \$flag ) {\n\t\t\tself::disconnected_notice( \$settings_url );\n\t\t\techo '</div>';\n\t\t\treturn;" );
$qa2_other = strpos( $qa2_embed, "if ( isset( \$results[ \$flag ] ) ) {" );
check( 'embed page: the "disconnected" flag shows only the refused-key notice and stops, before any other result message', false !== $qa2_flag && false !== $qa2_other && $qa2_flag < $qa2_other );

// ── Wording ─────────────────────────────────────────────────────────────────
check( 'link page: TackQuote\'s page is Integrations → WooCommerce (the app has no "Connections")', false === strpos( $qa2_embed, 'Connections →' ) && 3 === substr_count( $qa2_embed, 'Integrations → WooCommerce' ) );
$qa2_forms_src = (string) file_get_contents( TACK_QUOTES_DIR . 'includes/class-tack-storefront-forms.php' );
check( 'date hint: "…calendar (%1$s), where today is %2$s."', false !== strpos( $qa2_forms_src, "Dates follow the seller\\'s calendar (%1\$s), where today is %2\$s." ) && false === strpos( $qa2_forms_src, 'Today there is' ) );

// ── Catalogue and order sync on an http site ────────────────────────────────
$GLOBALS['TACK_HOME_URL'] = 'http://shop.example';
check( 'sync: an http site is not https', false === Tack_Rest_Keys::site_is_https() );
$GLOBALS['TACK_HOME_URL'] = 'https://shop.example';
check( 'sync: an https site is', true === Tack_Rest_Keys::site_is_https() );
unset( $GLOBALS['TACK_HOME_URL'] );
$qa2_rk = preg_replace( '/\s+/', '', (string) file_get_contents( TACK_QUOTES_DIR . 'includes/class-tack-rest-keys.php' ) );
check(
	'sync: on http the button is secondary and disabled, with the reason before it',
	false !== strpos( $qa2_rk, "submit_button(__('Allowcatalogueandordersync','tackquote'),\$https?'primary':'secondary','tack_rest_keys',false,\$https?null:array('disabled'=>'disabled'));" )
	&& false !== strpos( $qa2_rk, "if(!\$https){echo'<pclass=\"tack-field-error\">'" )
);
