<?php
/**
 * Regression tests for the 1.10.0 final polish (lane W9).
 *
 * Findings: tack-notes WOO_PLUGIN_AUDIT_2026-10-10.md.
 *   L-5  the version floors are WordPress 6.4 and WooCommerce 8.0, and every place that
 *        states them agrees.
 *   L-3  an application's error outcome (a `tack_sf_*` transient under a URL token) never
 *        holds a phone number or a tax / VAT / registration number, and lives 120 s.
 *
 * Run: php tests/run.php
 *
 * @package TackQuotes
 */

// ── L-5: one floor, stated the same way everywhere ─────────────────────────

$tack_w9_header = (string) file_get_contents( TACK_QUOTES_DIR . 'tackquote.php' );
$tack_w9_readme = (string) file_get_contents( TACK_QUOTES_DIR . 'readme.txt' );
$tack_w9_md     = (string) file_get_contents( TACK_QUOTES_DIR . 'README.md' );

check( 'L-5: the plugin header requires WordPress 6.4', 1 === preg_match( '/^ \* Requires at least: 6\.4$/m', $tack_w9_header ) );
check( 'L-5: the plugin header requires WooCommerce 8.0', 1 === preg_match( '/^ \* WC requires at least: 8\.0$/m', $tack_w9_header ) );
check( 'L-5: readme.txt states the same WordPress floor', 1 === preg_match( '/^Requires at least: 6\.4$/m', $tack_w9_readme ) );
check( 'L-5: README.md states both floors', false !== strpos( $tack_w9_md, "- WordPress 6.4+\n- WooCommerce 8.0+\n" ) );
check( 'L-5: no file still claims the old 6.0 floor', 0 === preg_match( '/(Requires at least|WC requires at least): 6\.0|(WordPress|WooCommerce) 6\.0\+/', $tack_w9_header . $tack_w9_readme . $tack_w9_md ) );

// ── L-3: the error refill keeps nothing that is costly to leak ─────────────

tack_test_without_rate_limits();
tack_test_reset_transients();
tack_test_set_logged_in( true, 'buyer@trade-customer.test' );

check( 'L-3: an outcome waits at most 120 seconds', 120 === Tack_Storefront_Forms::RESULT_TTL );

$tack_w9_private = array(
	array( 'key' => 'phone', 'label' => 'Phone', 'type' => 'tel' ),
	array( 'key' => 'vat', 'label' => 'VAT number', 'type' => 'tax_id' ),
	array( 'key' => 'contact', 'label' => 'Contact', 'type' => 'tel', 'role' => 'buyer_phone' ),
	array( 'key' => 'vatNumber', 'label' => 'Number', 'type' => 'text' ),
	array( 'key' => 'company_registration', 'label' => 'Company no.', 'type' => 'text' ),
	array( 'key' => 'x1', 'label' => 'Mobile', 'type' => 'text' ),
	array( 'key' => 'x2', 'label' => 'EIN', 'type' => 'text' ),
	array( 'key' => 'x3', 'label' => 'GST / HST number', 'type' => 'text' ),
);
foreach ( $tack_w9_private as $tack_w9_field ) {
	check( 'L-3: kept out of the refill: ' . $tack_w9_field['key'] . ' (' . $tack_w9_field['label'] . ')', Tack_Storefront_Forms::is_private_refill_field( $tack_w9_field ) );
}
$tack_w9_public = array(
	array( 'key' => 'company', 'label' => 'Company name', 'type' => 'text', 'role' => 'buyer_company' ),
	array( 'key' => 'private_label', 'label' => 'Private label brand', 'type' => 'text' ),
	array( 'key' => 'office', 'label' => 'Registered office', 'type' => 'address' ),
	array( 'key' => 'email', 'label' => 'Email', 'type' => 'email', 'role' => 'buyer_email' ),
);
foreach ( $tack_w9_public as $tack_w9_field ) {
	check( 'L-3: still refilled: ' . $tack_w9_field['key'] . ' (' . $tack_w9_field['label'] . ')', ! Tack_Storefront_Forms::is_private_refill_field( $tack_w9_field ) );
}

// Wholesale: the outcome a refused submission would store.
$tack_w9_client = new Tack_Test_Forms_Client(
	array(
		'wholesale-form/submit' => tack_test_api_error( 400, 'Company name is required' ),
		'wholesale-form?slug='  => $tack_all_kinds_form,
	)
);
$tack_w9_forms   = new Tack_Storefront_Forms( $tack_w9_client );
$tack_w9_outcome = $tack_w9_forms->process_wholesale_submission(
	array(
		'_tack_nonce'   => wp_create_nonce( Tack_Storefront_Forms::ACTION_WHOLESALE ),
		'tack_slug'     => 'default',
		'tack_redirect' => 'https://shop.example/apply/',
		'tack_sf'       => array(
			'company'     => 'Acme Ltd',
			'email'       => 'buyer@trade-customer.test',
			'phone'       => '+44 20 7946 0000',
			'terms'       => '1',
			'address'     => array( 'line1' => '1 High St', 'city' => 'Leeds', 'postalCode' => 'LS1 1AA', 'country' => 'GB' ),
			'vat'         => 'GB123456789',
			'reseller_id' => 'R-77',
		),
	)
);
$tack_w9_sent    = end( $tack_w9_client->sent );
$tack_w9_values  = isset( $tack_w9_outcome['values'] ) ? (array) $tack_w9_outcome['values'] : array();
$tack_w9_encoded = (string) wp_json_encode( $tack_w9_values );
$tack_w9_sent_values = isset( $tack_w9_sent['body']['values'] ) ? (array) $tack_w9_sent['body']['values'] : array();
check( 'L-3: the refused wholesale submission did send the tax ID and phone to TackQuote', isset( $tack_w9_sent_values['vat'], $tack_w9_sent_values['phone'] ) );
check( 'L-3: its stored refill keeps the ordinary answers', 'error' === $tack_w9_outcome['kind'] && 'Acme Ltd' === ( $tack_w9_values['company'] ?? '' ) && 'R-77' === ( $tack_w9_values['reseller_id'] ?? '' ), $tack_w9_encoded );
check( 'L-3: ...but not the tax ID', ! array_key_exists( 'vat', $tack_w9_values ) && false === strpos( $tack_w9_encoded, 'GB123456789' ), $tack_w9_encoded );
check( 'L-3: ...and not the phone number', ! array_key_exists( 'phone', $tack_w9_values ) && false === strpos( $tack_w9_encoded, '7946' ), $tack_w9_encoded );

// Net terms: refused at the last check (notes too long), so every earlier field is in the refill.
$tack_w9_credit = $tack_w9_forms->validate_credit_payload(
	array(
		'legalBusinessName' => 'Acme Ltd',
		'contactPhone'      => '0113 496 0000',
		'taxId'             => 'GB987654321',
		'billingAddress'    => array( 'line1' => '1 High St', 'city' => 'Leeds', 'postalCode' => 'LS1 1AA', 'country' => 'GB' ),
		'tradeReferences'   => array( array( 'companyName' => 'Supplier A', 'phone' => '0113 496 1111' ) ),
		'notes'             => str_repeat( 'n', 2001 ),
	),
	'buyer@trade-customer.test'
);
$tack_w9_credit_refill = (string) wp_json_encode( $tack_w9_credit['refill'] );
check( 'L-3: a refused net-terms application keeps the business name, address and references', null !== $tack_w9_credit['error'] && 'Acme Ltd' === ( $tack_w9_credit['refill']['legalBusinessName'] ?? '' ) && isset( $tack_w9_credit['refill']['billingAddress']['city'] ) && 'Supplier A' === ( $tack_w9_credit['refill']['tradeReferences'][0]['companyName'] ?? '' ), $tack_w9_credit_refill );
check( 'L-3: ...but not the tax ID', ! array_key_exists( 'taxId', $tack_w9_credit['refill'] ) && false === strpos( $tack_w9_credit_refill, 'GB987654321' ), $tack_w9_credit_refill );
check( 'L-3: ...nor the contact phone or a reference phone', ! array_key_exists( 'contactPhone', $tack_w9_credit['refill'] ) && false === strpos( $tack_w9_credit_refill, '0113 496' ), $tack_w9_credit_refill );
