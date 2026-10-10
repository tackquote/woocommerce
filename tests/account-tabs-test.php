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

// ── Wholesale account: approved / pending / everything else (1.11.0, D17) ───
//
// `GET /storefront/v1/price-access` answers `{status: linked, wholesaleApproved}` and
// carries NO pending state (tack storefront-results.ts PriceAccessResult), so "pending"
// comes from the store's own record of a submission TackQuote answered `pending`.

/**
 * Render the Wholesale account tab for a signed-in customer against one price-access answer.
 *
 * @param mixed $answer What the price-access route answers (array or WP_Error).
 * @return array{0:string,1:Tack_Test_Forms_Client}
 */
function tack_tabs_render_wholesale_standing( $answer ) {
	global $tabs_wholesale_form;
	tack_test_reset_transients();
	$_GET   = array();
	$client = new Tack_Test_Forms_Client(
		array(
			'storefront/v1/price-access' => $answer,
			'wholesale-form?slug=default' => $tabs_wholesale_form,
		)
	);
	$html   = ( new Tack_Storefront_Forms( $client ) )->render_wholesale_form( 'default', 'https://shop.example/my-account/wholesale-account/' );
	return array( $html, $client );
}

/**
 * How many requests went to the price-access route.
 *
 * @param Tack_Test_Forms_Client $client Client.
 * @return int
 */
function tack_tabs_price_access_calls( $client ) {
	return count(
		array_filter(
			$client->paths(),
			function ( $p ) {
				return false !== strpos( $p, '/storefront/v1/price-access?' );
			}
		)
	);
}

/**
 * Does the markup carry the wholesale application form?
 *
 * @param string $html Markup.
 * @return bool
 */
function tack_tabs_has_wholesale_form( $html ) {
	return false !== strpos( $html, '<form' ) && false !== strpos( $html, 'name="action" value="tack_wholesale_application"' );
}

tack_test_reset_user_meta();
tack_test_set_option( 'tack_quotes_api_key', 'tk_test_key' );
tack_test_set_logged_in( true, 'buyer@trade-customer.test' );
$tabs_approved = array(
	'status'            => 'linked',
	'wholesaleApproved' => true,
);

// Approved.
list( $tabs_html, $tabs_client ) = tack_tabs_render_wholesale_standing( $tabs_approved );
check( 'wholesale APPROVED: no application form', ! tack_tabs_has_wholesale_form( $tabs_html ) && false === strpos( $tabs_html, 'wholesale-form?slug=' ), $tabs_html );
check( 'wholesale APPROVED: "Your wholesale account is approved." from the overridable template', false !== strpos( $tabs_html, 'Your wholesale account is approved.' ) && false !== strpos( $tabs_html, 'tackquote-wholesale-account' ) && false !== strpos( $tabs_html, 'tackquote-wholesale-summary' ), $tabs_html );
check( 'wholesale APPROVED: the form definition is not even fetched', 0 === count( array_filter( $tabs_client->paths(), function ( $p ) { return false !== strpos( $p, 'wholesale-form' ); } ) ) );
check( 'wholesale APPROVED: one price-access read, for the trusted email and the WP user id', 1 === tack_tabs_price_access_calls( $tabs_client ) && false !== strpos( $tabs_client->paths()[0], 'buyerEmail=buyer%40trade-customer.test' ) && false !== strpos( $tabs_client->paths()[0], 'buyerExternalId=1' ) );
check( 'wholesale APPROVED: the answer is cached where the catalogue price gate reads it', isset( $GLOBALS['TACK_TRANSIENTS'][ Tack_Price_Access::cache_key( 1, 'buyer@trade-customer.test' ) ] ) && array( 'approved' => true ) === $GLOBALS['TACK_TRANSIENTS'][ Tack_Price_Access::cache_key( 1, 'buyer@trade-customer.test' ) ] );

// The price gate on the same page asks nobody again.
$tabs_gate = new Tack_Catalog_Mode( $tabs_client );
check( 'wholesale: the catalogue price gate and the tab share one read', true === $tabs_gate->buyer_is_approved_wholesale() && 1 === tack_tabs_price_access_calls( $tabs_client ) );

// Approved clears a stale pending record.
update_user_meta( 1, Tack_Storefront_Forms::META_WHOLESALE_APPLIED, time() - 60 );
list( $tabs_html ) = tack_tabs_render_wholesale_standing( $tabs_approved );
check( 'wholesale APPROVED over a pending record: approved wins and the record is cleared', false !== strpos( $tabs_html, 'is approved' ) && false === strpos( $tabs_html, 'being reviewed' ) && '' === get_user_meta( 1, Tack_Storefront_Forms::META_WHOLESALE_APPLIED, true ) );

// Pending: TackQuote answered `pending` to a submission from this store.
update_user_meta( 1, Tack_Storefront_Forms::META_WHOLESALE_APPLIED, time() - DAY_IN_SECONDS );
list( $tabs_html ) = tack_tabs_render_wholesale_standing( array( 'status' => 'unlinked', 'reason' => 'customer_not_linked' ) );
check( 'wholesale PENDING: a "being reviewed" notice and no form', false !== strpos( $tabs_html, 'Your wholesale application is being reviewed.' ) && ! tack_tabs_has_wholesale_form( $tabs_html ), $tabs_html );
check( 'wholesale PENDING: approval is not claimed', false === strpos( $tabs_html, 'is approved' ) && false === strpos( $tabs_html, 'tackquote-wholesale-summary' ) );
list( $tabs_html ) = tack_tabs_render_wholesale_standing( array( 'status' => 'linked', 'wholesaleApproved' => false ) );
check( 'wholesale PENDING while linked by net terms (wholesaleApproved false): still the notice, no form', false !== strpos( $tabs_html, 'being reviewed' ) && ! tack_tabs_has_wholesale_form( $tabs_html ) );

// A pending record older than the window is the form again.
update_user_meta( 1, Tack_Storefront_Forms::META_WHOLESALE_APPLIED, time() - ( Tack_Storefront_Forms::WHOLESALE_PENDING_DAYS + 1 ) * DAY_IN_SECONDS );
list( $tabs_html ) = tack_tabs_render_wholesale_standing( array( 'status' => 'unlinked', 'reason' => 'customer_not_linked' ) );
check( 'wholesale: a pending record older than 30 days is the form again', tack_tabs_has_wholesale_form( $tabs_html ) && false === strpos( $tabs_html, 'being reviewed' ) );

// An API error claims nothing, even over a fresh pending record.
update_user_meta( 1, Tack_Storefront_Forms::META_WHOLESALE_APPLIED, time() - 60 );
foreach ( array(
	'TackQuote error (500)' => tack_test_api_error( 500, 'boom' ),
	'TackQuote unreachable' => new WP_Error( 'http_request_failed', 'timeout' ),
	'malformed answer'      => 'linked',
) as $tabs_label => $tabs_answer ) {
	list( $tabs_html ) = tack_tabs_render_wholesale_standing( $tabs_answer );
	check( "wholesale, $tabs_label: the application form, no claim", tack_tabs_has_wholesale_form( $tabs_html ) && false === strpos( $tabs_html, 'is approved' ) && false === strpos( $tabs_html, 'being reviewed' ), $tabs_html );
}
tack_test_reset_user_meta();

// Everything else is the form.
foreach ( array(
	'unlinked'                         => array( 'status' => 'unlinked', 'reason' => 'customer_not_linked' ),
	'linked, not approved'             => array( 'status' => 'linked', 'wholesaleApproved' => false ),
	'linked, wholesaleApproved absent' => array( 'status' => 'linked' ),
	'linked, wholesaleApproved "true"' => array( 'status' => 'linked', 'wholesaleApproved' => 'true' ),
	'anonymous'                        => array( 'status' => 'anonymous' ),
	'unknown status'                   => array( 'status' => 'something-new', 'wholesaleApproved' => true ),
) as $tabs_label => $tabs_answer ) {
	list( $tabs_html ) = tack_tabs_render_wholesale_standing( $tabs_answer );
	check( "wholesale, $tabs_label: the application form, no claim", tack_tabs_has_wholesale_form( $tabs_html ) && false === strpos( $tabs_html, 'is approved' ) && false === strpos( $tabs_html, 'being reviewed' ), $tabs_html );
}

// No trusted email, or no key: no read at all.
update_user_meta( 1, Tack_B2B_Notices::META_EMAIL_UNVERIFIED, '1' );
list( $tabs_html, $tabs_client ) = tack_tabs_render_wholesale_standing( $tabs_approved );
check( 'wholesale, unconfirmed email: no price-access read and the form', 0 === tack_tabs_price_access_calls( $tabs_client ) && tack_tabs_has_wholesale_form( $tabs_html ) );
tack_test_reset_user_meta();
tack_test_set_logged_in( false, '' );
list( $tabs_html, $tabs_client ) = tack_tabs_render_wholesale_standing( $tabs_approved );
check( 'wholesale, guest: no price-access read and the form', 0 === tack_tabs_price_access_calls( $tabs_client ) && tack_tabs_has_wholesale_form( $tabs_html ) );
tack_test_set_logged_in( true, 'buyer@trade-customer.test' );

// A submission answered `pending` records the date for a signed-in customer only.
tack_test_reset_transients();
$tabs_submit_client = new Tack_Test_Forms_Client(
	array(
		'wholesale-form/submit'      => array( 'status' => 'pending' ),
		'wholesale-form?slug=default' => $tabs_wholesale_form,
	)
);
$tabs_outcome       = ( new Tack_Storefront_Forms( $tabs_submit_client ) )->process_wholesale_submission(
	array(
		'_tack_nonce'   => wp_create_nonce( Tack_Storefront_Forms::ACTION_WHOLESALE ),
		'tack_slug'     => 'default',
		'tack_redirect' => 'https://shop.example/my-account/wholesale-account/',
		'tack_sf'       => array(
			'companyName' => 'Analytical Engines Ltd',
			'firstName'   => 'Ada',
			'lastName'    => 'Byron',
			'email'       => 'buyer@trade-customer.test',
		),
	)
);
check( 'wholesale submit answered pending: the outcome is pending', 'pending' === $tabs_outcome['kind'], wp_json_encode( $tabs_outcome ) );
check( 'wholesale submit answered pending: the date is recorded for the customer', Tack_Storefront_Forms::wholesale_pending_since( 1 ) > 0 );
tack_test_reset_user_meta();
tack_test_set_logged_in( false, '' );
( new Tack_Storefront_Forms( $tabs_submit_client ) )->process_wholesale_submission(
	array(
		'_tack_nonce' => wp_create_nonce( Tack_Storefront_Forms::ACTION_WHOLESALE ),
		'tack_slug'   => 'default',
		'tack_sf'     => array(
			'companyName' => 'Analytical Engines Ltd',
			'firstName'   => 'Ada',
			'lastName'    => 'Byron',
			'email'       => 'guest@trade-customer.test',
		),
	)
);
check( 'wholesale submit by a guest: nothing recorded', empty( $GLOBALS['TACK_USER_META'] ) );
check( 'uninstall and the privacy export know the pending-application record', false !== strpos( (string) file_get_contents( TACK_QUOTES_DIR . 'uninstall.php' ), "'" . Tack_Storefront_Forms::META_WHOLESALE_APPLIED . "'" ) && isset( Tack_Quotes::privacy_user_meta()[ Tack_Storefront_Forms::META_WHOLESALE_APPLIED ] ) );
tack_test_reset_transients();
