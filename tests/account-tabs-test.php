<?php
/**
 * My Account tabs for a signed-in customer (1.10.2):
 *
 * - Net terms: an ACTIVE credit line is shown as the customer's terms (no form); an
 *   application under review is a pending notice (no form); anything else, and any
 *   failure to read, is the application form with no claim.
 * - Wholesale account: the standard form's `firstName` / `lastName` / `companyName` /
 *   `phone` fields are prefilled from WooCommerce billing, then the WordPress profile.
 *
 * Runs after storefront-forms-test.php (Tack_Test_Forms_Client) and e2e-fixes-test.php.
 *
 * Run: php tests/run.php
 *
 * @package TackQuotes
 */

/**
 * A `standing` answer as `GET /storefront/v1/net-terms` sends it.
 *
 * @param array       $account Account overrides; null for no account.
 * @param string      $state   `none`, `pending`, `approved` or `declined`.
 * @return array
 */
function tack_tabs_standing( $account = array(), $state = 'approved' ) {
	return array(
		'status'      => 'standing',
		'state'       => $state,
		'application' => null,
		'account'     => null === $account ? null : array_merge(
			array(
				'status'      => 'active',
				'termsDays'   => 30,
				'creditLimit' => '500.0000',
				'currency'    => 'USD',
				'available'   => '472.87',
			),
			$account
		),
	);
}

/**
 * Render the Net terms tab for a signed-in customer against one net-terms answer.
 *
 * @param mixed $answer What the net-terms route answers (array or WP_Error).
 * @return array{0:string,1:Tack_Test_Forms_Client}
 */
function tack_tabs_render_net_terms( $answer ) {
	tack_test_reset_transients();
	Tack_Net_Terms_Standing::reset_request_cache();
	$_GET   = array();
	$client = new Tack_Test_Forms_Client( array( 'storefront/v1/net-terms' => $answer ) );
	$forms  = new Tack_Storefront_Forms( $client );
	return array( $forms->render_net_terms_form( 'https://shop.example/my-account/net-terms/' ), $client );
}

/**
 * How many requests went to the net-terms route.
 *
 * @param Tack_Test_Forms_Client $client Client.
 * @return int
 */
function tack_tabs_net_terms_calls( $client ) {
	return count(
		array_filter(
			$client->paths(),
			function ( $p ) {
				return false !== strpos( $p, '/storefront/v1/net-terms?' );
			}
		)
	);
}

tack_test_reset_user_meta();
tack_test_set_option( 'tack_quotes_api_key', 'tk_test_key' );
tack_test_set_logged_in( true, 'buyer@trade-customer.test' );

// ── Net terms: active line ──────────────────────────────────────────────────

list( $tabs_html, $tabs_client ) = tack_tabs_render_net_terms( tack_tabs_standing() );
check( 'net terms ACTIVE: no application form', false === strpos( $tabs_html, '<form' ) && false === strpos( $tabs_html, 'tack_credit_application' ), $tabs_html );
check( 'net terms ACTIVE: the terms are shown', false !== strpos( $tabs_html, 'Net 30 days' ) );
check( 'net terms ACTIVE: the credit limit, as a price in the line\'s currency', false !== strpos( $tabs_html, 'Credit limit: <span class="amount">USD 500.00</span>' ), $tabs_html );
check( 'net terms ACTIVE: the available credit, when TackQuote sends it', false !== strpos( $tabs_html, 'Available credit: <span class="amount">USD 472.87</span>' ) );
check( 'net terms ACTIVE: rendered from the overridable template', false !== strpos( $tabs_html, 'tackquote-net-terms-account' ) && false !== strpos( $tabs_html, 'tackquote-net-terms-heading' ) );
check( 'net terms ACTIVE: one read, for the trusted email and the WP user id', 1 === tack_tabs_net_terms_calls( $tabs_client ) && false !== strpos( $tabs_client->paths()[0], 'buyerEmail=buyer%40trade-customer.test' ) && false !== strpos( $tabs_client->paths()[0], 'buyerExternalId=1' ) );
check( 'net terms ACTIVE: the answer is cached where the checkout gateway reads it', isset( $GLOBALS['TACK_TRANSIENTS'][ Tack_Net_Terms_Standing::CACHE_PREFIX . '1' ] ) );
check( 'net terms: the gateway and the tab share one cache', Tack_Net_Terms_Standing::CACHE_PREFIX === Tack_Gateway_Net_Terms::CACHE_PREFIX && Tack_Net_Terms_Standing::CACHE_TTL === Tack_Gateway_Net_Terms::CACHE_TTL );
$tabs_again = Tack_Net_Terms_Standing::read( $tabs_client, 1, 'buyer@trade-customer.test' );
check( 'net terms: a second reader in the same request makes no second call', is_array( $tabs_again ) && 1 === tack_tabs_net_terms_calls( $tabs_client ) );

list( $tabs_html ) = tack_tabs_render_net_terms( tack_tabs_standing( array( 'available' => null ) ) );
check( 'net terms ACTIVE, no available figure: no "Available credit" line, the rest shown', false === strpos( $tabs_html, 'Available credit' ) && false !== strpos( $tabs_html, 'Credit limit:' ) && false === strpos( $tabs_html, '<form' ) );

list( $tabs_html ) = tack_tabs_render_net_terms( tack_tabs_standing( array(), 'pending' ) );
check( 'net terms ACTIVE with a new application under review: the terms AND a pending notice', false !== strpos( $tabs_html, 'Net 30 days' ) && false !== strpos( $tabs_html, 'being reviewed' ) && false === strpos( $tabs_html, '<form' ) );

list( $tabs_html ) = tack_tabs_render_net_terms( tack_tabs_standing( array( 'currency' => 'EUR' ) ) );
check( 'net terms ACTIVE in another currency: shown in that currency', false !== strpos( $tabs_html, 'EUR 500.00' ) );

// ── Net terms: pending application ──────────────────────────────────────────

list( $tabs_html ) = tack_tabs_render_net_terms( tack_tabs_standing( null, 'pending' ) );
check( 'net terms PENDING: a pending notice and no form', false !== strpos( $tabs_html, 'Your net-terms application is being reviewed.' ) && false === strpos( $tabs_html, '<form' ), $tabs_html );
check( 'net terms PENDING: no terms are claimed', false === strpos( $tabs_html, 'tackquote-net-terms-summary' ) && false === strpos( $tabs_html, 'Net 30 days' ) && false === strpos( $tabs_html, 'Credit limit' ) );

// ── Net terms: everything else is the form ─────────────────────────────────

$tabs_form_cases = array(
	'no terms and no application (unlinked)' => array( 'status' => 'unlinked', 'reason' => 'customer_not_linked' ),
	'application declined, no line'          => tack_tabs_standing( null, 'declined' ),
	'line on hold'                           => tack_tabs_standing( array( 'status' => 'hold' ) ),
	'line with no terms days'                => tack_tabs_standing( array( 'termsDays' => null ) ),
	'line with no currency'                  => tack_tabs_standing( array( 'currency' => '' ) ),
	'line with a malformed currency'         => tack_tabs_standing( array( 'currency' => 'U<S' ) ),
	'unknown status'                         => array( 'status' => 'something-new' ),
	'TackQuote error (500)'                  => tack_test_api_error( 500, 'boom' ),
	'TackQuote unreachable'                  => new WP_Error( 'http_request_failed', 'timeout' ),
);
foreach ( $tabs_form_cases as $tabs_label => $tabs_answer ) {
	list( $tabs_html ) = tack_tabs_render_net_terms( $tabs_answer );
	check( "net terms, $tabs_label: the application form", false !== strpos( $tabs_html, 'name="action" value="tack_credit_application"' ), $tabs_html );
	// "Net 30 days" is also one of the form's own choices, so the claim is the summary block.
	check( "net terms, $tabs_label: no claim about terms", false === strpos( $tabs_html, 'tackquote-net-terms-summary' ) && false === strpos( $tabs_html, 'Credit limit' ) && false === strpos( $tabs_html, 'being reviewed' ) );
}
check( 'net terms: an error is never cached', ! isset( $GLOBALS['TACK_TRANSIENTS'][ Tack_Net_Terms_Standing::CACHE_PREFIX . '1' ] ) );

// A self-changed, unconfirmed email resolves nobody: no read, the form.
update_user_meta( 1, Tack_B2B_Notices::META_EMAIL_UNVERIFIED, '1' );
list( $tabs_html, $tabs_client ) = tack_tabs_render_net_terms( tack_tabs_standing() );
check( 'net terms, unconfirmed email: no standing read and the form', 0 === tack_tabs_net_terms_calls( $tabs_client ) && false !== strpos( $tabs_html, 'tack_credit_application' ) && false === strpos( $tabs_html, 'tackquote-net-terms-summary' ) );
tack_test_reset_user_meta();

// The outcome of a submission still wins, with no read.
tack_test_reset_transients();
Tack_Net_Terms_Standing::reset_request_cache();
set_transient( Tack_Storefront_Forms::RESULT_PREFIX . 'tabs1', array( 'form' => 'credit', 'kind' => 'success', 'message' => 'Submitted.' ), 300 );
$_GET['tack_form'] = 'tabs1';
$tabs_client       = new Tack_Test_Forms_Client( array( 'storefront/v1/net-terms' => tack_tabs_standing() ) );
$tabs_html         = ( new Tack_Storefront_Forms( $tabs_client ) )->render_net_terms_form( 'https://shop.example/my-account/net-terms/' );
check( 'net terms: a submission outcome is shown as before, with no standing read', false !== strpos( $tabs_html, 'Submitted.' ) && 0 === tack_tabs_net_terms_calls( $tabs_client ) );
$_GET = array();

check( 'net terms: Tack_Storefront_Forms::net_terms_view() refuses a non-array', 'none' === Tack_Storefront_Forms::net_terms_view( 'standing' )['state'] );

// ── Wholesale account: names, company and phone prefilled ──────────────────

/** WooCommerce customer billing double (the plugin checks method_exists()). */
class Tack_Tabs_Customer {
	/** @var array<string,string> */
	public $billing = array();
	/**
	 * One billing field.
	 *
	 * @param string $field Field.
	 * @return string
	 */
	private function get( $field ) {
		return isset( $this->billing[ $field ] ) ? $this->billing[ $field ] : '';
	}
	/** @return string */
	public function get_billing_first_name() {
		return $this->get( 'first_name' );
	}
	/** @return string */
	public function get_billing_last_name() {
		return $this->get( 'last_name' );
	}
	/** @return string */
	public function get_billing_company() {
		return $this->get( 'company' );
	}
	/** @return string */
	public function get_billing_phone() {
		return $this->get( 'phone' );
	}
}

$tabs_wholesale_form = array(
	'name'   => 'Wholesale account application',
	'fields' => array(
		array( 'key' => 'companyName', 'label' => 'Company name', 'type' => 'text', 'required' => true ),
		array( 'key' => 'firstName', 'label' => 'First name', 'type' => 'text', 'required' => true ),
		array( 'key' => 'lastName', 'label' => 'Last name', 'type' => 'text', 'required' => true ),
		array( 'key' => 'email', 'label' => 'Work email', 'type' => 'email', 'required' => true ),
		array( 'key' => 'phone', 'label' => 'Phone', 'type' => 'tel' ),
		array( 'key' => 'taxId', 'label' => 'Tax ID', 'type' => 'text' ),
	),
);

/**
 * The value an input was rendered with.
 *
 * @param string $html Markup.
 * @param string $key  Field key.
 * @return string|null
 */
function tack_tabs_value( $html, $key ) {
	return preg_match( '/<input[^>]*name="tack_sf\[' . preg_quote( $key, '/' ) . '\]"[^>]*value="([^"]*)"/', $html, $m ) ? html_entity_decode( $m[1], ENT_QUOTES ) : null;
}

/**
 * Render the Wholesale account tab.
 *
 * @return string
 */
function tack_tabs_render_wholesale() {
	global $tabs_wholesale_form;
	tack_test_reset_transients();
	$_GET = array();
	return ( new Tack_Storefront_Forms( new Tack_Test_Forms_Client( array( 'wholesale-form?slug=default' => $tabs_wholesale_form ) ) ) )
		->render_wholesale_form( 'default', 'https://shop.example/my-account/wholesale/' );
}

$tabs_customer          = new Tack_Tabs_Customer();
$tabs_customer->billing = array(
	'first_name' => 'Ada',
	'last_name'  => 'Byron',
	'company'    => 'Analytical Engines Ltd',
	'phone'      => '+44 20 7946 0000',
);
$GLOBALS['TACK_WC_CUSTOMER']     = $tabs_customer;
$GLOBALS['TACK_USER_FIRST_NAME'] = 'Profile';
$GLOBALS['TACK_USER_LAST_NAME']  = 'Name';

$tabs_html = tack_tabs_render_wholesale();
check( 'wholesale prefill: first name from WooCommerce billing', 'Ada' === tack_tabs_value( $tabs_html, 'firstName' ), $tabs_html );
check( 'wholesale prefill: last name from WooCommerce billing', 'Byron' === tack_tabs_value( $tabs_html, 'lastName' ) );
check( 'wholesale prefill: company name from WooCommerce billing', 'Analytical Engines Ltd' === tack_tabs_value( $tabs_html, 'companyName' ) );
check( 'wholesale prefill: phone from WooCommerce billing', '+44 20 7946 0000' === tack_tabs_value( $tabs_html, 'phone' ) );
check( 'wholesale prefill: the email as before', 'buyer@trade-customer.test' === tack_tabs_value( $tabs_html, 'email' ) );
check( 'wholesale prefill: an unrelated field stays empty', '' === tack_tabs_value( $tabs_html, 'taxId' ) );

$tabs_customer->billing = array();
$tabs_html              = tack_tabs_render_wholesale();
check( 'wholesale prefill: no billing name -> the WordPress profile name', 'Profile' === tack_tabs_value( $tabs_html, 'firstName' ) && 'Name' === tack_tabs_value( $tabs_html, 'lastName' ) );
check( 'wholesale prefill: no billing company -> left empty', '' === tack_tabs_value( $tabs_html, 'companyName' ) );

// The key is matched only on the field type the standard form uses.
$tabs_wholesale_form['fields'][1] = array( 'key' => 'firstName', 'label' => 'First name', 'type' => 'select', 'options' => array( 'Profile', 'Other' ) );
$tabs_html                        = tack_tabs_render_wholesale();
check( 'wholesale prefill: a firstName key on another field type is left alone', null === tack_tabs_value( $tabs_html, 'firstName' ) && false === strpos( $tabs_html, '<option value="Profile" selected' ) );

tack_test_set_logged_in( false, '' );
$tabs_wholesale_form['fields'][1] = array( 'key' => 'firstName', 'label' => 'First name', 'type' => 'text', 'required' => true );
$tabs_html                        = tack_tabs_render_wholesale();
check( 'wholesale prefill: a guest gets nothing prefilled', '' === tack_tabs_value( $tabs_html, 'firstName' ) && '' === tack_tabs_value( $tabs_html, 'email' ) );

unset( $GLOBALS['TACK_USER_FIRST_NAME'], $GLOBALS['TACK_USER_LAST_NAME'] );
$GLOBALS['TACK_WC_CUSTOMER'] = null;
tack_test_reset_transients();
