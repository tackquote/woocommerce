<?php
/**
 * Net-terms gateway, its Checkout block integration and the checkout PO number.
 *
 * Required from tests/run.php after the storefront-forms and order-payload tests,
 * so `check()`, the WP/WC stubs, Tack_Storefront_Forms, Tack_B2B_Notices and
 * `tack_sync_call()` already exist. Every API answer goes through the real
 * Tack_Api_Client and the scripted HTTP stub, so what is asserted is what the
 * store would send and how it reads the reply.
 *
 * @package TackQuotes
 */

require_once __DIR__ . '/wc-stubs.php';
require_once TACK_QUOTES_DIR . 'includes/class-tack-po-number.php';
require_once TACK_QUOTES_DIR . 'includes/class-tack-quotes.php';

check( 'the gateway class loads once WC_Payment_Gateway exists', true === Tack_Quotes::load_gateway() );
$tack_nt_registered = Tack_Quotes::register_gateway( array( 'WC_Gateway_Cheque' ) );
check( 'woocommerce_payment_gateways gains Tack_Gateway_Net_Terms', in_array( 'Tack_Gateway_Net_Terms', $tack_nt_registered, true ) && in_array( 'WC_Gateway_Cheque', $tack_nt_registered, true ) );
check( 'gateway id is tackquote_net_terms (the order-sync payment.method tack keys on)', 'tackquote_net_terms' === Tack_Gateway_Net_Terms::ID );

/** An order that records what the gateway does to it. */
class Tack_NT_Test_Order extends WC_Order {
	/** @var string */
	public $status_set = '';
	/** @var string */
	public $note = '';
	/** @var int */
	public $saves = 0;
	/** @param string $status Status. @param string $note Note. */
	public function update_status( $status, $note = '' ) {
		$this->status_set = $status;
		$this->note       = $note;
		++$this->saves;
		return true;
	}
	/** @return int */
	public function save() {
		++$this->saves;
		return 1;
	}
}

/** The gateway with its two WooCommerce seams pointed at fixtures. */
class Tack_NT_Test_Gateway extends Tack_Gateway_Net_Terms {
	/** @var Tack_NT_Test_Order|false */
	public $order = false;
	/** @var int */
	public $carts_emptied = 0;
	/** @param int $order_id Id. @return Tack_NT_Test_Order|false */
	protected function load_order( $order_id ) {
		return $this->order;
	}
	/** Record instead of touching WC(). */
	protected function empty_cart() {
		++$this->carts_emptied;
	}
}

/** Collects errors like the WP_Error the additional-fields API passes. */
class Tack_NT_Test_Errors {
	/** @var array */
	public $codes = array();
	/** @param string $code Code. @param string $message Message. */
	public function add( $code, $message ) {
		$this->codes[] = $code;
	}
}

/**
 * A standing answer.
 *
 * @param array $account Account overrides; null for no account.
 * @return string JSON.
 */
function tack_nt_standing( $account = array() ) {
	$base = array(
		'status'      => 'active',
		'termsDays'   => 30,
		'creditLimit' => '5000.0000',
		'currency'    => 'USD',
	);
	return wp_json_encode(
		array(
			'status'      => 'standing',
			'state'       => 'approved',
			'application' => null,
			'account'     => null === $account ? null : array_merge( $base, $account ),
		)
	);
}

/**
 * Reset everything a scenario depends on.
 *
 * @param array $settings Gateway settings.
 */
function tack_nt_reset( $settings = array( 'enabled' => 'yes' ) ) {
	tack_test_reset_transients();
	tack_test_reset_notices();
	tack_test_clear_filter_returns();
	Tack_Gateway_Net_Terms::reset_request_cache();
	tack_test_set_option( 'tack_quotes_api_key', 'tk_test_key' );
	tack_test_set_option( 'tack_quotes_api_url', 'https://api.example/v1' );
	tack_test_set_option( 'woocommerce_tackquote_net_terms_settings', $settings );
	tack_test_set_logged_in( true, 'buyer@example.test' );
	unset( $GLOBALS['TACK_USER_META'][1][ Tack_B2B_Notices::META_EMAIL_UNVERIFIED ] );
	$GLOBALS['TACK_STORE_CURRENCY'] = 'USD';
	$GLOBALS['TACK_NT_CART_TOTAL']  = 1200.00;
	$GLOBALS['TACK_HTTP_CALLS']     = 0;
	$GLOBALS['TACK_HTTP_REQUESTS']  = array();
	tack_test_set_http_response( 200, tack_nt_standing() );
}

/**
 * A fresh gateway.
 *
 * @return Tack_NT_Test_Gateway
 */
function tack_nt_gateway() {
	return new Tack_NT_Test_Gateway( new Tack_Api_Client() );
}

// ── Defaults: off everywhere ───────────────────────────────────────────────
tack_nt_reset( array() );
$gw = tack_nt_gateway();
check( 'gateway ships disabled (enabled default no)', 'no' === $gw->enabled );
check( 'PO field ships off', false === Tack_Po_Number::enabled() );
check( 'disabled gateway: hidden', false === $gw->is_available() );
check( 'disabled gateway: no standing read at all', 0 === $GLOBALS['TACK_HTTP_CALLS'] );

// ── Visible: active, room, same currency ───────────────────────────────────
tack_nt_reset();
$gw = tack_nt_gateway();
check( 'active line with room in the cart currency: offered', true === $gw->is_available() );
$tack_nt_url = $GLOBALS['TACK_HTTP_LAST_URL'];
check( 'reads GET /storefront/v1/net-terms', false !== strpos( $tack_nt_url, '/storefront/v1/net-terms?' ) );
check( 'asserts the trusted email and the WP user id', false !== strpos( $tack_nt_url, 'buyerEmail=buyer%40example.test' ) && false !== strpos( $tack_nt_url, 'buyerExternalId=1' ) );
$tack_nt_headers = $GLOBALS['TACK_HTTP_LAST_ARGS']['headers'];
check( 'key in X-Api-Key only (no Authorization on v1)', isset( $tack_nt_headers['X-Api-Key'] ) && ! isset( $tack_nt_headers['Authorization'] ) );
check( 'plugin version header sent', isset( $tack_nt_headers['X-TackQuote-Plugin-Version'] ) );
$gw->is_available();
( tack_nt_gateway() )->is_available();
check( 'standing cached for the request and the transient (one HTTP call for three checks)', 1 === $GLOBALS['TACK_HTTP_CALLS'] );
check( 'transient keyed by user id', isset( $GLOBALS['TACK_TRANSIENTS']['tack_nt_1'] ) );
$GLOBALS['TACK_NT_CART_TOTAL'] = 5000.00;
check( 'total exactly equal to the limit: offered', true === tack_nt_gateway()->is_available() );

// ── Hidden: who is asking ───────────────────────────────────────────────────
tack_nt_reset();
tack_test_set_logged_in( false, '' );
check( 'guest: hidden', false === tack_nt_gateway()->is_available() );
check( 'guest: no standing read', 0 === $GLOBALS['TACK_HTTP_CALLS'] );

tack_nt_reset();
$GLOBALS['TACK_USER_META'][1][ Tack_B2B_Notices::META_EMAIL_UNVERIFIED ] = '1';
check( 'self-changed unconfirmed email: hidden', false === tack_nt_gateway()->is_available() );
check( 'unconfirmed email: no standing read', 0 === $GLOBALS['TACK_HTTP_CALLS'] );
unset( $GLOBALS['TACK_USER_META'][1][ Tack_B2B_Notices::META_EMAIL_UNVERIFIED ] );

tack_nt_reset();
$GLOBALS['TACK_IS_ADMIN'] = true;
check( 'wp-admin screens: hidden, no read', false === tack_nt_gateway()->is_available() && 0 === $GLOBALS['TACK_HTTP_CALLS'] );
$GLOBALS['TACK_IS_ADMIN'] = false;

// ── Hidden: what TackQuote says ─────────────────────────────────────────────
$tack_nt_refusals = array(
	'account on hold'                => array( tack_nt_standing( array( 'status' => 'hold' ) ), 'account_inactive' ),
	'account suspended'              => array( tack_nt_standing( array( 'status' => 'suspended' ) ), 'account_inactive' ),
	'no credit account yet'          => array( tack_nt_standing( null ), 'no_account' ),
	'insufficient credit'            => array( tack_nt_standing( array( 'creditLimit' => '1000.0000' ) ), 'over_limit' ),
	'one cent over the limit'        => array( tack_nt_standing( array( 'creditLimit' => '1199.9900' ) ), 'over_limit' ),
	'currency mismatch'              => array( tack_nt_standing( array( 'currency' => 'EUR' ) ), 'currency_mismatch' ),
	'no terms days'                  => array( tack_nt_standing( array( 'termsDays' => null ) ), 'no_terms_days' ),
	'no limit'                       => array( tack_nt_standing( array( 'creditLimit' => null ) ), 'no_limit' ),
	'unlinked buyer'                 => array( wp_json_encode( array( 'status' => 'unlinked', 'reason' => 'customer_not_linked' ) ), 'not_standing' ),
	'anonymous answer'               => array( wp_json_encode( array( 'status' => 'anonymous' ) ), 'not_standing' ),
	'unknown shape'                  => array( wp_json_encode( array( 'ok' => true ) ), 'not_standing' ),
);
foreach ( $tack_nt_refusals as $tack_nt_label => $tack_nt_case ) {
	tack_nt_reset();
	tack_test_set_http_response( 200, $tack_nt_case[0] );
	check( "$tack_nt_label: hidden", false === tack_nt_gateway()->is_available() );
	check(
		"$tack_nt_label: refusal named {$tack_nt_case[1]}",
		$tack_nt_case[1] === Tack_Gateway_Net_Terms::evaluate( json_decode( $tack_nt_case[0], true ), 1200.00, 'USD' )['reason']
	);
}
tack_nt_reset();
check( 'currency compare is case-insensitive (usd vs USD)', true === Tack_Gateway_Net_Terms::evaluate( json_decode( tack_nt_standing( array( 'currency' => 'usd' ) ), true ), 10, 'USD' )['eligible'] );

// ── Fail closed: errors, timeouts, 404 ──────────────────────────────────────
tack_nt_reset();
tack_test_set_http_response( 500, wp_json_encode( array( 'message' => 'boom' ) ) );
check( 'API 500: hidden', false === tack_nt_gateway()->is_available() );
check( 'an error is never cached', ! isset( $GLOBALS['TACK_TRANSIENTS']['tack_nt_1'] ) );
tack_test_set_http_response( 200, tack_nt_standing() );
Tack_Gateway_Net_Terms::reset_request_cache();
check( 'recovers as soon as TackQuote answers again', true === tack_nt_gateway()->is_available() );

tack_nt_reset();
$GLOBALS['TACK_HTTP_RESPONSE'] = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
check( 'timeout: hidden', false === tack_nt_gateway()->is_available() );

tack_nt_reset();
tack_test_set_http_response( 404, wp_json_encode( array( 'message' => 'Cannot GET' ) ) );
check( '404 (no v1 routes): hidden', false === tack_nt_gateway()->is_available() );
check( '404 is remembered and still hidden without another call', false === tack_nt_gateway()->is_available() && 1 === $GLOBALS['TACK_HTTP_CALLS'] );

tack_nt_reset();
tack_test_set_http_response( 200, 'not json' );
check( 'non-JSON 200: hidden', false === tack_nt_gateway()->is_available() );

tack_nt_reset();
tack_test_set_option( 'tack_quotes_api_key', '' );
check( 'no API key: hidden', false === tack_nt_gateway()->is_available() );

// ── process_payment ─────────────────────────────────────────────────────────
tack_nt_reset();
$gw = tack_nt_gateway();
check( 'pre: offered on the checkout page', true === $gw->is_available() );
$gw->order = new Tack_NT_Test_Order( array( 'id' => 77, 'customer_id' => 1, 'total' => 1200.00, 'currency' => 'USD' ) );
tack_test_set_http_response( 200, tack_nt_standing( array( 'status' => 'hold' ) ) );
$tack_nt_result = $gw->process_payment( 77 );
check( 'process_payment re-reads the standing (a fresh HTTP call)', 2 === $GLOBALS['TACK_HTTP_CALLS'] );
check( 'line put on hold since the page loaded: order refused', array( 'result' => 'failure' ) === $tack_nt_result );
check( 'refusal shows a notice', 1 === count( tack_test_notices() ) );
check( 'refused order untouched: no status, no meta, cart kept', '' === $gw->order->status_set && '' === $gw->order->get_meta( Tack_Gateway_Net_Terms::META_TERMS ) && 0 === $gw->carts_emptied );

tack_nt_reset();
$gw        = tack_nt_gateway();
$gw->order = new Tack_NT_Test_Order( array( 'id' => 78, 'customer_id' => 1, 'total' => 6000.00, 'currency' => 'USD' ) );
check( 'order grew past the limit: refused', array( 'result' => 'failure' ) === $gw->process_payment( 78 ) );
check( 'over-limit notice names the limit, not a figure', false !== strpos( (string) end( $GLOBALS['TACK_NOTICES'] ), 'credit limit' ) && false === strpos( (string) end( $GLOBALS['TACK_NOTICES'] ), '5000' ) );

tack_nt_reset();
$gw        = tack_nt_gateway();
$gw->order = new Tack_NT_Test_Order( array( 'id' => 79, 'customer_id' => 1, 'total' => 1200.00, 'currency' => 'EUR' ) );
check( 'order currency differs from the line: refused', array( 'result' => 'failure' ) === $gw->process_payment( 79 ) );

tack_nt_reset();
$gw        = tack_nt_gateway();
$gw->order = new Tack_NT_Test_Order( array( 'id' => 80, 'customer_id' => 2, 'total' => 10.00, 'currency' => 'USD' ) );
check( 'order of another customer: refused without a read', array( 'result' => 'failure' ) === $gw->process_payment( 80 ) && 0 === $GLOBALS['TACK_HTTP_CALLS'] );

tack_nt_reset();
$gw = tack_nt_gateway();
$gw->order = false;
check( 'missing order: refused', array( 'result' => 'failure' ) === $gw->process_payment( 81 ) );

tack_nt_reset();
$GLOBALS['TACK_HTTP_RESPONSE'] = new WP_Error( 'http_request_failed', 'timed out' );
$gw        = tack_nt_gateway();
$gw->order = new Tack_NT_Test_Order( array( 'id' => 82, 'customer_id' => 1, 'total' => 10.00, 'currency' => 'USD' ) );
check( 'standing unreadable at place-order: refused (fail closed)', array( 'result' => 'failure' ) === $gw->process_payment( 82 ) );

tack_nt_reset();
$gw        = tack_nt_gateway();
$gw->order = new Tack_NT_Test_Order( array( 'id' => 83, 'customer_id' => 1, 'total' => 1200.00, 'currency' => 'USD' ) );
$tack_nt_result = $gw->process_payment( 83 );
check( 'eligible: success with the thank-you URL', 'success' === $tack_nt_result['result'] && 'https://shop.example/checkout/order-received/83/' === $tack_nt_result['redirect'] );
check( 'eligible: order on-hold, never paid', 'on-hold' === $gw->order->status_set );
check( 'eligible: note names the terms', 'Awaiting payment on net terms (30 days).' === $gw->order->note );
$tack_nt_meta = $gw->order->get_meta( Tack_Gateway_Net_Terms::META_TERMS );
check( 'eligible: _tackquote_net_terms = {termsDays, checkedAt}', is_array( $tack_nt_meta ) && 30 === $tack_nt_meta['termsDays'] && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}T/', $tack_nt_meta['checkedAt'] ) && array( 'termsDays', 'checkedAt' ) === array_keys( $tack_nt_meta ) );
check( 'eligible: meta never holds the limit', false === strpos( wp_json_encode( $tack_nt_meta ), '5000' ) );
check( 'eligible: cart emptied once', 1 === $gw->carts_emptied );
check( 'eligible: no notice', 0 === count( tack_test_notices() ) );

// ── Blocks integration ──────────────────────────────────────────────────────
require_once TACK_QUOTES_DIR . 'includes/blocks/class-tack-net-terms-block.php';
tack_nt_reset(
	array(
		'enabled'     => 'yes',
		'title'       => 'Pay on net terms',
		'description' => 'Invoice to follow.',
	)
);
$tack_nt_block = new Tack_Net_Terms_Block( tack_nt_gateway() );
$tack_nt_block->initialize();
check( 'block name equals the gateway id', Tack_Gateway_Net_Terms::ID === $tack_nt_block->get_name() );
check( 'block is_active mirrors the gateway Enable switch (on)', true === $tack_nt_block->is_active() );
$tack_nt_data = $tack_nt_block->get_payment_method_data();
check( 'block data: canPay true for an eligible buyer', true === $tack_nt_data['canPay'] );
check( 'block data: exactly title, description, canPay, supports', array( 'canPay', 'description', 'supports', 'title' ) === ( function ( $k ) { sort( $k ); return $k; } )( array_keys( $tack_nt_data ) ) );
$tack_nt_json = wp_json_encode( $tack_nt_data );
check( 'block data never contains the limit, the terms or the currency', false === strpos( $tack_nt_json, '5000' ) && false === stripos( $tack_nt_json, 'limit' ) && false === strpos( $tack_nt_json, '30' ) && false === strpos( $tack_nt_json, 'USD' ) );
$tack_nt_handles = $tack_nt_block->get_payment_method_script_handles();
check( 'block script registered with the blocks registry dependency', array( Tack_Net_Terms_Block::SCRIPT_HANDLE ) === $tack_nt_handles && in_array( 'wc-blocks-registry', $GLOBALS['TACK_REGISTERED_SCRIPTS'][ Tack_Net_Terms_Block::SCRIPT_HANDLE ]['deps'], true ) );

tack_nt_reset( array( 'enabled' => 'yes' ) );
tack_test_set_http_response( 500, '{}' );
$tack_nt_block = new Tack_Net_Terms_Block( tack_nt_gateway() );
$tack_nt_block->initialize();
check( 'block data: canPay false when TackQuote cannot confirm', false === $tack_nt_block->get_payment_method_data()['canPay'] );

tack_nt_reset( array( 'enabled' => 'no' ) );
$tack_nt_block = new Tack_Net_Terms_Block( tack_nt_gateway() );
$tack_nt_block->initialize();
check( 'block is_active false when the gateway is disabled', false === $tack_nt_block->is_active() );
$tack_nt_block_none = new Tack_Net_Terms_Block();
$tack_nt_block_none->initialize();
check( 'block with no registered gateway: canPay false', false === $tack_nt_block_none->get_payment_method_data()['canPay'] );

$tack_nt_js = (string) file_get_contents( TACK_QUOTES_DIR . 'assets/js/tack-net-terms-block.js' );
check( 'JS registers name tackquote_net_terms through wcBlocksRegistry', false !== strpos( $tack_nt_js, "name: 'tackquote_net_terms'" ) && false !== strpos( $tack_nt_js, 'registerPaymentMethod' ) );
check( 'JS canMakePayment returns the server boolean only', false !== strpos( $tack_nt_js, 'return true === data.canPay;' ) );

// ── PO number ───────────────────────────────────────────────────────────────
tack_nt_reset( array( 'po_number' => 'no' ) );
$GLOBALS['TACK_HOOKS'] = array();
( new Tack_Po_Number() )->init();
$tack_nt_hooks = array_column( $GLOBALS['TACK_HOOKS'], 'hook' );
check( 'PO off: only the sync filter is attached', array( 'tack_quotes_order_po_number' ) === $tack_nt_hooks );

tack_nt_reset( array( 'po_number' => 'yes' ) );
$GLOBALS['TACK_HOOKS'] = array();
( new Tack_Po_Number() )->init();
$tack_nt_hooks = array_column( $GLOBALS['TACK_HOOKS'], 'hook' );
foreach ( array( 'woocommerce_init', 'woocommerce_validate_additional_field', 'woocommerce_set_additional_field_value', 'woocommerce_after_order_notes', 'woocommerce_checkout_process', 'woocommerce_checkout_create_order' ) as $tack_nt_hook ) {
	check( "PO on: $tack_nt_hook attached", in_array( $tack_nt_hook, $tack_nt_hooks, true ) );
}

$tack_po = new Tack_Po_Number();
$GLOBALS['TACK_ADDITIONAL_FIELDS'] = array();
$tack_po->register_block_field();
$tack_nt_field = $GLOBALS['TACK_ADDITIONAL_FIELDS'][0];
check( 'Blocks field: id tackquote/po-number, location order, optional, maxLength 64', 'tackquote/po-number' === $tack_nt_field['id'] && 'order' === $tack_nt_field['location'] && false === $tack_nt_field['required'] && 64 === $tack_nt_field['attributes']['maxLength'] );

$tack_nt_errors = new Tack_NT_Test_Errors();
$tack_po->validate_block_field( $tack_nt_errors, 'tackquote/po-number', str_repeat( 'A', 65 ) );
check( 'Blocks validation: 65 characters refused', array( 'tackquote_po_number_too_long' ) === $tack_nt_errors->codes );
$tack_nt_errors = new Tack_NT_Test_Errors();
$tack_po->validate_block_field( $tack_nt_errors, 'tackquote/po-number', str_repeat( 'A', 64 ) );
$tack_po->validate_block_field( $tack_nt_errors, 'other/field', str_repeat( 'A', 99 ) );
check( 'Blocks validation: 64 accepted, other fields ignored', array() === $tack_nt_errors->codes );

$tack_nt_order = new WC_Order( array( 'id' => 90 ) );
$tack_po->save_block_field( 'tackquote/po-number', ' PO-1234 ', 'other', $tack_nt_order );
check( 'Blocks save: copied to _tackquote_po_number', 'PO-1234' === $tack_nt_order->get_meta( '_tackquote_po_number' ) );

$_POST['tackquote_po_number'] = str_repeat( 'B', 70 );
tack_test_reset_notices();
$tack_po->validate_classic_field();
check( 'classic validation: 70 characters refused with a notice', 1 === count( tack_test_notices() ) );
$_POST['tackquote_po_number'] = 'PO-5678';
tack_test_reset_notices();
$tack_po->validate_classic_field();
check( 'classic validation: a normal PO passes', 0 === count( tack_test_notices() ) );
$tack_nt_classic = new WC_Order( array( 'id' => 91 ) );
$tack_po->save_classic_field( $tack_nt_classic, array() );
check( 'classic save: _tackquote_po_number set', 'PO-5678' === $tack_nt_classic->get_meta( '_tackquote_po_number' ) );
unset( $_POST['tackquote_po_number'] );
$tack_po->render_classic_field( null );
check( 'classic field rendered with maxlength 64', isset( $GLOBALS['TACK_FORM_FIELDS_RENDERED']['tackquote_po_number'] ) && 64 === $GLOBALS['TACK_FORM_FIELDS_RENDERED']['tackquote_po_number']['args']['maxlength'] );

// Exposed to order sync through the existing filter.
tack_test_clear_filter_returns();
add_filter( 'tack_quotes_order_po_number', array( 'Tack_Po_Number', 'po_for_sync' ), 5, 2 );
check( 'sync filter: saved PO reaches tack_quotes_order_po_number', 'PO-5678' === apply_filters( 'tack_quotes_order_po_number', '', $tack_nt_classic ) );
if ( function_exists( 'tack_sync_call' ) ) {
	check( 'order-sync payload poNumber carries the saved PO', 'PO-5678' === tack_sync_call( 'po_number', array( $tack_nt_classic ) ) );
}
check( 'sync filter: a value supplied earlier wins', 'MERCHANT-PO' === Tack_Po_Number::po_for_sync( 'MERCHANT-PO', $tack_nt_classic ) );
check( 'sync filter: no PO saved -> empty', '' === apply_filters( 'tack_quotes_order_po_number', '', new WC_Order( array( 'id' => 92 ) ) ) );
tack_test_clear_filter_returns();

// Leave nothing behind for later suites.
tack_nt_reset( array() );
tack_test_set_logged_in( false, '' );
