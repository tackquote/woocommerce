<?php
/**
 * Tests for Tack_Storefront_Forms, the storefront v1 reads in Tack_Api_Client /
 * Tack_Wholesale_Pricing, and the plugin-version header.
 *
 * What is pinned, and why each one matters:
 *
 *   · Every field kind the server can define renders, with the merchant's text
 *     ESCAPED. A form the seller designs is server-provided content on the
 *     storefront; a label is the one place a careless renderer injects markup.
 *   · A signed-out visitor is refused the net-terms form. A credit application
 *     is a decision about an ACCOUNT, and attaching one to a typed email would let
 *     anyone apply as anyone.
 *   · What leaves the store never contains the API key or a tenant id in the
 *     body — the key travels in headers only, the tenant is the key's.
 *   · `X-TackQuote-Plugin-Version` is on every request, GET and POST.
 *   · A 429 becomes "wait a few minutes", never a queue and never the raw body.
 *   · The markup passes through a wp_kses() allowlist with no script in it.
 *
 * Run: php tests/run.php   (no PHPUnit, no WordPress required)
 *
 * @package TackQuotes
 */

/**
 * An API client answering by path fragment and recording what was sent.
 */
class Tack_Test_Forms_Client extends Tack_Api_Client {

	/** @var array<string,mixed> Path fragment => response (array or WP_Error). */
	public $responses;

	/** @var array<int,array{method:string,path:string,body:mixed,headers:array}> */
	public $sent = array();

	/**
	 * Constructor.
	 *
	 * @param array $responses Path fragment => response.
	 */
	public function __construct( $responses ) {
		$this->responses = $responses;
	}

	/**
	 * Intercept the request.
	 *
	 * @param string     $method  HTTP method.
	 * @param string     $path    Path.
	 * @param mixed      $body    Body.
	 * @param int|null   $timeout Timeout.
	 * @param array      $headers Headers.
	 * @return mixed
	 */
	public function request( $method, $path, $body = null, $timeout = null, $headers = array() ) {
		$this->sent[] = array(
			'method'  => $method,
			'path'    => $path,
			'body'    => $body,
			'headers' => $headers,
		);
		foreach ( $this->responses as $fragment => $response ) {
			if ( false !== strpos( $path, $fragment ) ) {
				return $response;
			}
		}
		return new WP_Error( 'tack_http_404', 'not found', array( 'status' => 404, 'json' => false ) );
	}

	/**
	 * Paths requested so far.
	 *
	 * @return string[]
	 */
	public function paths() {
		return array_map(
			function ( $r ) {
				return $r['path'];
			},
			$this->sent
		);
	}
}

/**
 * A TackQuote-shaped WP_Error, as Tack_Api_Client::request() builds one.
 *
 * @param int    $status  HTTP status.
 * @param string $message Message.
 * @param bool   $own     Whether the body is TackQuote's own JSON (statusCode + code).
 * @param array  $scopes  requiredScopes.
 * @return WP_Error
 */
function tack_test_api_error( $status, $message, $own = true, $scopes = array() ) {
	return new WP_Error(
		'tack_http_' . $status,
		$message,
		array(
			'status'            => $status,
			'json'              => $own,
			'statusCode'        => $own ? $status : 0,
			'code'              => $own ? ( 403 === $status ? 'insufficient_scope' : 'BAD_REQUEST' ) : '',
			'requiredScopes'    => $scopes,
			'retryAfterSeconds' => 0,
			'retryAfterHeader'  => '',
		)
	);
}

/** Every field kind FORM_FIELD_TYPES lists, in one form. */
$tack_all_kinds_form = array(
	'tenantSlug'     => 'acme',
	'id'             => 'form-1',
	'name'           => 'Trade <b>account</b>',
	'description'    => 'Tell us about your business',
	'successMessage' => 'Thanks, we will be in touch.',
	'fields'         => array(
		array( 'key' => 'company', 'label' => 'Company <script>alert(1)</script> name', 'type' => 'text', 'required' => true, 'role' => 'buyer_company' ),
		array( 'key' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'role' => 'buyer_email' ),
		array( 'key' => 'phone', 'label' => 'Phone', 'type' => 'tel' ),
		array( 'key' => 'staff', 'label' => 'Employees', 'type' => 'number' ),
		array( 'key' => 'sector', 'label' => 'Sector', 'type' => 'select', 'options' => array( 'Retail', 'Trade "quoted"' ), 'help' => 'Pick <em>one</em>' ),
		array( 'key' => 'interests', 'label' => 'Interests', 'type' => 'multiselect', 'options' => array( 'Tools', 'Paint' ) ),
		array( 'key' => 'terms', 'label' => 'I accept the terms', 'type' => 'checkbox', 'required' => true ),
		array( 'key' => 'about', 'label' => 'About you', 'type' => 'textarea' ),
		array( 'key' => 'start', 'label' => 'Start date', 'type' => 'date', 'min' => 'today' ),
		array( 'key' => 'licence', 'label' => 'Trade licence', 'type' => 'file' ),
		array( 'key' => 'address', 'label' => 'Business address', 'type' => 'address', 'required' => true ),
		array( 'key' => 'vat', 'label' => 'VAT number', 'type' => 'tax_id' ),
		array( 'key' => 'reseller_id', 'label' => 'Reseller id', 'type' => 'text', 'required' => true, 'showIf' => array( 'field' => 'terms', 'equals' => true ) ),
	),
);

tack_test_reset_transients();
tack_test_set_option( 'tack_quotes_api_key', 'tk_test_key' );
tack_test_set_option( Tack_Storefront_Forms::OPTION_FORM_SLUG, 'default' );
$_GET = array();

// ── 1. Every field kind renders, escaped ────────────────────────────────────

tack_test_set_logged_in( false, '' );
$client = new Tack_Test_Forms_Client( array( 'wholesale-form?slug=default' => $tack_all_kinds_form ) );
$forms  = new Tack_Storefront_Forms( $client );
$html   = $forms->render_wholesale_form( 'default', 'https://shop.example/apply/' );

check( 'the form fetches the definition by slug from wholesale-form', array( '/integrations/woocommerce/wholesale-form?slug=default' ) === $client->paths(), implode( ', ', $client->paths() ) );
check( 'text field renders as an input', (bool) preg_match( '/<input type="text" class="input-text" name="tack_sf\[company\]"[^>]*required="required"/', $html ) );
check( 'email field renders as type=email', (bool) preg_match( '/<input type="email"[^>]*name="tack_sf\[email\]"/', $html ) );
check( 'tel field renders as type=tel', (bool) preg_match( '/<input type="tel"[^>]*name="tack_sf\[phone\]"/', $html ) );
check( 'number field renders as type=number', (bool) preg_match( '/<input type="number"[^>]*name="tack_sf\[staff\]"/', $html ) );
check( 'select field renders its options', false !== strpos( $html, '<select name="tack_sf[sector]"' ) && false !== strpos( $html, '<option value="Retail">Retail</option>' ) );
check( 'select option values and text are attribute/HTML escaped', false !== strpos( $html, 'value="Trade &quot;quoted&quot;"' ), $html );
check( 'multiselect renders one checkbox per option, as an array name', 2 === substr_count( $html, 'name="tack_sf[interests][]"' ) );
check( 'checkbox renders as a single tick box', (bool) preg_match( '/<input type="checkbox"[^>]*name="tack_sf\[terms\]"[^>]*value="1"[^>]*required="required"/', $html ) );
check( 'textarea renders', false !== strpos( $html, '<textarea name="tack_sf[about]"' ) );
check( 'date field renders with min=today when the definition says so', (bool) preg_match( '/<input type="date"[^>]*name="tack_sf\[start\]"[^>]*min="' . gmdate( 'Y-m-d' ) . '"/', $html ) );
check( 'file field renders a notice, not an upload control', false !== strpos( $html, 'Attachments arrive in a later release' ) && false === strpos( $html, 'type="file"' ) );
check( 'address renders its six parts', false !== strpos( $html, 'name="tack_sf[address][line1]"' ) && false !== strpos( $html, 'name="tack_sf[address][postalCode]"' ) && false !== strpos( $html, 'name="tack_sf[address][country]"' ) );
check( 'tax_id renders as a text input', (bool) preg_match( '/<input type="text"[^>]*name="tack_sf\[vat\]"/', $html ) );
check( 'a conditional field carries its showIf as data attributes', false !== strpos( $html, 'data-tack-show-if-field="terms" data-tack-show-if-equals="true"' ) );
check( 'a merchant label with markup is escaped, not rendered', false !== strpos( $html, 'Company &lt;script&gt;alert(1)&lt;/script&gt; name' ) && false === strpos( $html, '<script' ), substr( $html, 0, 400 ) );
check( 'help text is escaped', false !== strpos( $html, 'Pick &lt;em&gt;one&lt;/em&gt;' ) );
check( 'the form name is escaped', false !== strpos( $html, 'Trade &lt;b&gt;account&lt;/b&gt;' ) );
check( 'the form posts to admin-post.php with its action, slug, redirect and nonce', false !== strpos( $html, 'action="https://shop.example/wp-admin/admin-post.php"' ) && false !== strpos( $html, 'name="action" value="tack_wholesale_application"' ) && false !== strpos( $html, 'name="tack_slug" value="default"' ) && false !== strpos( $html, 'name="_tack_nonce"' ) );
check( 'a submit button is offered', false !== strpos( $html, 'Submit application' ) );
check( 'the conditional-field script is enqueued', in_array( 'tackquote-storefront-forms', $GLOBALS['TACK_ENQUEUED'], true ) );
check( 'no file upload control anywhere (uploads are a later release)', false === strpos( $html, 'enctype' ) );

// The allowlist the markup goes through.
$allowed = Tack_Storefront_Forms::allowed_html();
check( 'the kses allowlist has no script, style, iframe or object', ! isset( $allowed['script'], $allowed['style'], $allowed['iframe'], $allowed['object'] ) );
$has_on = false;
foreach ( $allowed as $tag => $attrs ) {
	foreach ( array_keys( (array) $attrs ) as $attr ) {
		if ( 0 === strpos( (string) $attr, 'on' ) ) {
			$has_on = true;
		}
	}
}
check( 'the kses allowlist has no on* event attributes', ! $has_on );
check( 'a <script> smuggled through a server string is stripped by wp_kses', false === strpos( wp_kses( '<p>x</p><script>alert(1)</script>', $allowed ), '<script' ) );

// Signed in: prefilled from the account by role, never retyped.
tack_test_set_logged_in( true, 'buyer@trade-customer.test' );
$client = new Tack_Test_Forms_Client( array( 'wholesale-form?slug=default' => $tack_all_kinds_form ) );
$forms  = new Tack_Storefront_Forms( $client );
$html   = $forms->render_wholesale_form( 'default', 'https://shop.example/apply/' );
check( 'a signed-in customer has the buyer_email field prefilled', false !== strpos( $html, 'name="tack_sf[email]" id="tack_sf_email" value="buyer@trade-customer.test"' ), $html );

// A required file field blocks the form honestly.
tack_test_reset_transients();
$blocked_form                        = $tack_all_kinds_form;
$blocked_form['fields'][9]['required'] = true;
$client = new Tack_Test_Forms_Client( array( 'wholesale-form?slug=default' => $blocked_form ) );
$forms  = new Tack_Storefront_Forms( $client );
$html   = $forms->render_wholesale_form( 'default', 'https://shop.example/apply/' );
check( 'a REQUIRED file field withholds the submit button and says why', false === strpos( $html, 'Submit application' ) && false !== strpos( $html, 'requires an attachment' ) );

// The form definition could not be read: a friendly notice, not a PHP error.
tack_test_reset_transients();
$client = new Tack_Test_Forms_Client( array( 'wholesale-form?slug=default' => tack_test_api_error( 503, 'Service Unavailable', false ) ) );
$forms  = new Tack_Storefront_Forms( $client );
$html   = $forms->render_wholesale_form( 'default', 'https://shop.example/apply/' );
check( 'an unreachable server renders a friendly notice and no form', false !== strpos( $html, 'not available right now' ) && false === strpos( $html, '<form' ) );
check( '...and the failure is remembered briefly so the page is not slowed by a retry per view', is_array( get_transient( Tack_Api_Client::FORM_CACHE_TRANSIENT ) ) );
check( '...with the raw server message nowhere in the output', false === strpos( $html, 'Service Unavailable' ) );

// ── 2. Submitting: answers take the server's shapes ────────────────────────

tack_test_reset_transients();
tack_test_set_logged_in( true, 'buyer@trade-customer.test' );
$client = new Tack_Test_Forms_Client(
	array(
		'wholesale-form/submit' => array( 'id' => 'sub-1', 'status' => 'pending', 'message' => 'Thanks, <b>we</b> will be in touch.' ),
		'wholesale-form?slug='  => $tack_all_kinds_form,
	)
);
$forms  = new Tack_Storefront_Forms( $client );
$post   = array(
	'_tack_nonce'   => wp_create_nonce( Tack_Storefront_Forms::ACTION_WHOLESALE ),
	'tack_slug'     => 'default',
	'tack_redirect' => 'https://shop.example/apply/',
	'tack_sf'       => array(
		'company'     => '  Acme Ltd ',
		'email'       => 'buyer@trade-customer.test',
		'phone'       => '+44 20 7946 0000',
		'staff'       => '12',
		'sector'      => 'Retail',
		'interests'   => array( 'Paint', 'Not an option' ),
		'terms'       => '1',
		'about'       => "Line one\nLine two",
		'start'       => '2030-01-02',
		'address'     => array( 'line1' => '1 High St', 'line2' => '', 'city' => 'Leeds', 'postalCode' => 'LS1 1AA', 'country' => 'GB' ),
		'vat'         => 'GB123456789',
		'reseller_id' => 'R-77',
		'tenantId'    => 'evil',
	),
);
$outcome = $forms->process_wholesale_submission( $post );
$submit  = end( $client->sent );
$body    = $submit['body'];
$values  = (array) $body['values'];

check( 'a valid submission is sent to wholesale-form/submit for the slug', 'POST' === $submit['method'] && '/integrations/woocommerce/wholesale-form/submit?slug=default' === $submit['path'], $submit['path'] );
check( 'the outcome is pending with the server message as plain text', 'pending' === $outcome['kind'] && 'Thanks, we will be in touch.' === $outcome['message'], wp_json_encode( $outcome ) );
check( 'text answers are trimmed strings', 'Acme Ltd' === $values['company'] );
check( 'a checkbox answer is boolean true', true === $values['terms'] );
check( 'a multiselect answer keeps only configured options', array( 'Paint' ) === $values['interests'] );
check( 'an address answer is an object of its parts, empty parts dropped', array( 'line1' => '1 High St', 'city' => 'Leeds', 'postalCode' => 'LS1 1AA', 'country' => 'GB' ) === $values['address'] );
check( 'a textarea answer keeps its newlines', "Line one\nLine two" === $values['about'] );
check( 'a conditional field shown by its controller is sent', 'R-77' === $values['reseller_id'] );
check( 'a key that is not on the form is never sent', ! array_key_exists( 'tenantId', $values ) );
check( 'the file field is not sent at all', ! array_key_exists( 'licence', $values ) );
check( 'the signed-in customer id travels as wooCustomerId', '1' === $body['wooCustomerId'] );
$encoded = wp_json_encode( $body );
check( 'the request body never contains the API key', false === strpos( $encoded, 'tk_test_key' ) );
check( 'the request body never contains a tenant id', false === stripos( $encoded, 'tenant' ) );

// Guest: no customer id.
tack_test_set_logged_in( false, '' );
$client = new Tack_Test_Forms_Client(
	array(
		'wholesale-form/submit' => array( 'id' => 'sub-2', 'status' => 'approved', 'message' => '' ),
		'wholesale-form?slug='  => $tack_all_kinds_form,
	)
);
$forms   = new Tack_Storefront_Forms( $client );
$outcome = $forms->process_wholesale_submission( $post );
$submit  = end( $client->sent );
check( 'a guest submission carries no wooCustomerId', ! isset( $submit['body']['wooCustomerId'] ) );
check( 'an auto-approved form answers success', 'success' === $outcome['kind'] && false !== strpos( $outcome['message'], 'approved' ) );

// Hidden-by-condition required field is NOT demanded; required shown field is.
$unticked = $post;
unset( $unticked['tack_sf']['terms'], $unticked['tack_sf']['reseller_id'] );
$result = $forms->collect_answers( $tack_all_kinds_form['fields'], $unticked['tack_sf'] );
check( 'an unticked REQUIRED checkbox is refused before any request', null !== $result['error'] && false !== strpos( $result['error'], 'must be ticked' ), (string) $result['error'] );
$fields_optional_terms                 = $tack_all_kinds_form['fields'];
$fields_optional_terms[6]['required'] = false;
$result                                = $forms->collect_answers( $fields_optional_terms, $unticked['tack_sf'] );
check( 'a required field hidden by an unticked controller is not demanded', null === $result['error'], (string) $result['error'] );
check( '...and an unticked optional checkbox is recorded as false', false === $result['values']['terms'] );
$no_company = $post['tack_sf'];
$no_company['company'] = '';
$result = $forms->collect_answers( $tack_all_kinds_form['fields'], $no_company );
check( 'a missing required text field is refused with its label', null !== $result['error'] && false !== strpos( $result['error'], 'is required' ) );
$bad_address = $post['tack_sf'];
unset( $bad_address['address']['postalCode'] );
$result = $forms->collect_answers( $tack_all_kinds_form['fields'], $bad_address );
check( 'an address missing a required part is refused naming the part', null !== $result['error'] && false !== strpos( $result['error'], 'postal code' ), (string) $result['error'] );

// A bad nonce never reaches the server.
$client  = new Tack_Test_Forms_Client( array( 'wholesale-form?slug=' => $tack_all_kinds_form ) );
$forms   = new Tack_Storefront_Forms( $client );
$bad     = $post;
$bad['_tack_nonce'] = 'stale';
$outcome = $forms->process_wholesale_submission( $bad );
check( 'a bad nonce is refused with no request made', 'error' === $outcome['kind'] && array() === $client->sent );

// ── 3. Server failures become friendly text, never the raw body ────────────

$cases = array(
	array( tack_test_api_error( 429, 'ThrottlerException: Too Many Requests' ), 'wait a few minutes', 'ThrottlerException' ),
	array( tack_test_api_error( 403, 'Missing scope buyers:write', true, array( 'buyers:write' ) ), 'contact the store', 'Missing scope' ),
	array( tack_test_api_error( 400, 'Company name is required' ), 'Company name is required', '' ),
	array( tack_test_api_error( 400, '<b>proxy</b> rejected {"x":1}', false ), 'could not be reached', 'proxy' ),
	array( tack_test_api_error( 500, 'Internal Server Error: stack trace', false ), 'could not be reached', 'stack' ),
	array( new WP_Error( 'http_request_failed', 'cURL error 28: timed out' ), 'could not be reached', 'cURL' ),
);
foreach ( $cases as $i => $case ) {
	list( $error, $expect, $forbidden ) = $case;
	$client  = new Tack_Test_Forms_Client(
		array(
			'wholesale-form/submit' => $error,
			'wholesale-form?slug='  => $tack_all_kinds_form,
		)
	);
	$forms   = new Tack_Storefront_Forms( $client );
	$outcome = $forms->process_wholesale_submission( $post );
	check(
		"failure case $i maps to friendly text ('$expect')",
		'error' === $outcome['kind'] && false !== strpos( $outcome['message'], $expect ),
		wp_json_encode( $outcome )
	);
	if ( '' !== $forbidden ) {
		check( "failure case $i never echoes the raw body ('$forbidden')", false === strpos( $outcome['message'], $forbidden ) );
	}
}
check( 'a failed submission is never queued for later', 0 === count( $GLOBALS['TACK_AS_ENQUEUED'] ) && 0 === count( $GLOBALS['TACK_AS_SCHEDULED'] ) );
check( 'a refused submission keeps what was typed for the refill', isset( $outcome['values']['company'] ) && 'Acme Ltd' === $outcome['values']['company'] );

// ── 4. Net terms: signed-in only ────────────────────────────────────────────

tack_test_set_logged_in( false, '' );
$client = new Tack_Test_Forms_Client( array() );
$forms  = new Tack_Storefront_Forms( $client );
$html   = $forms->render_net_terms_form( 'https://shop.example/my-account/net-terms/' );
check( 'a signed-out visitor gets a login link and NO form', false === strpos( $html, '<form' ) && false !== strpos( $html, 'wp-login.php' ) && false !== strpos( $html, 'log in' ), $html );
$outcome = $forms->process_credit_submission(
	array(
		'_tack_nonce'   => wp_create_nonce( Tack_Storefront_Forms::ACTION_CREDIT ),
		'tack_redirect' => 'https://shop.example/my-account/net-terms/',
		'tack_ct'       => array( 'legalBusinessName' => 'Acme' ),
	)
);
check( 'a signed-out POST to the credit handler is refused with no request made', 'error' === $outcome['kind'] && array() === $client->sent && false !== strpos( $outcome['redirect'], 'wp-login.php' ) );

tack_test_set_logged_in( true, 'buyer@trade-customer.test' );
$client = new Tack_Test_Forms_Client( array( 'credit-application' => array( 'status' => 'received', 'applicationId' => 'app-1', 'linkedToBuyer' => false ) ) );
$forms  = new Tack_Storefront_Forms( $client );
$html   = $forms->render_net_terms_form( 'https://shop.example/my-account/net-terms/' );
check( 'a signed-in customer sees the net-terms form', false !== strpos( $html, 'name="action" value="tack_credit_application"' ) );
check( 'the contact email is shown from the account and is not an editable field', false !== strpos( $html, 'value="buyer@trade-customer.test" disabled="disabled"' ) && false === strpos( $html, 'name="tack_ct[contactEmail]"' ) );
check( 'the terms-days choices are the fixed list', 5 === substr_count( $html, '<option value="' ) - substr_count( $html, '<option value=""' ) && false !== strpos( $html, '<option value="30" selected="selected">' ) );
check( 'three reference rows are offered', 3 === substr_count( $html, 'tack_ct[tradeReferences][' ) / 4 );

$credit_post = array(
	'_tack_nonce'   => wp_create_nonce( Tack_Storefront_Forms::ACTION_CREDIT ),
	'tack_redirect' => 'https://shop.example/my-account/net-terms/',
	'tack_ct'       => array(
		'legalBusinessName'  => 'Acme Ltd',
		'contactEmail'       => 'attacker@evil.test',
		'contactPhone'       => '0113 000 0000',
		'taxId'              => 'GB123',
		'billingAddress'     => array( 'line1' => '1 High St', 'city' => 'Leeds', 'postalCode' => 'LS1 1AA', 'country' => 'GB' ),
		'requestedLimit'     => '5000.50',
		'requestedTermsDays' => '30',
		'tradeReferences'    => array(
			array( 'companyName' => 'Supplier A', 'email' => 'a@supplier.test' ),
			array( 'companyName' => '', 'contactName' => '' ),
			array( 'companyName' => 'Supplier C' ),
			array( 'companyName' => 'Supplier D' ),
			array( 'companyName' => 'Supplier E' ),
		),
		'notes'              => 'Please',
		'tenantId'           => 'evil',
	),
);
$outcome = $forms->process_credit_submission( $credit_post );
$submit  = end( $client->sent );
$payload = $submit['body'];
check( 'a valid credit application posts to /storefront/v1/credit-application with the asserted email and WP user id in the query', 'POST' === $submit['method'] && '/storefront/v1/credit-application?buyerEmail=buyer%40trade-customer.test&buyerExternalId=1' === $submit['path'], $submit['path'] );
check( '...with the key in X-Api-Key only (v1 refuses a second credential)', array_key_exists( 'Authorization', $submit['headers'] ) && null === $submit['headers']['Authorization'] );
check( 'contactEmail is the ACCOUNT email, whatever the form posted', 'buyer@trade-customer.test' === $payload['contactEmail'] );
check( 'requestedLimit is numeric and requestedTermsDays an int', 5000.5 === $payload['requestedLimit'] && 30 === $payload['requestedTermsDays'] );
check( 'references are capped at three and empty rows dropped', 3 === count( $payload['tradeReferences'] ) && 'Supplier D' === $payload['tradeReferences'][2]['companyName'] );
check( 'the credit payload carries only DTO fields', ! array_key_exists( 'tenantId', $payload ) && array() === array_diff( array_keys( $payload ), array( 'contactEmail', 'legalBusinessName', 'contactPhone', 'taxId', 'billingAddress', 'requestedLimit', 'requestedTermsDays', 'tradeReferences', 'notes' ) ), implode( ',', array_keys( $payload ) ) );
check( 'the credit body never contains the API key', false === strpos( wp_json_encode( $payload ), 'tk_test_key' ) );
check( 'a received application answers success', 'success' === $outcome['kind'] );

$v = $forms->validate_credit_payload( array( 'legalBusinessName' => '' ), 'buyer@trade-customer.test' );
check( 'legal business name is required', null !== $v['error'] );
$v = $forms->validate_credit_payload( array( 'legalBusinessName' => 'A', 'requestedTermsDays' => '31' ), 'buyer@trade-customer.test' );
check( 'terms days outside the fixed list are refused', null !== $v['error'] );
$v = $forms->validate_credit_payload( array( 'legalBusinessName' => 'A', 'requestedLimit' => '-1' ), 'buyer@trade-customer.test' );
check( 'a negative requested limit is refused', null !== $v['error'] );
$v = $forms->validate_credit_payload( array( 'legalBusinessName' => 'A', 'requestedLimit' => 'lots' ), 'buyer@trade-customer.test' );
check( 'a non-numeric requested limit is refused', null !== $v['error'] );
$v = $forms->validate_credit_payload( array( 'legalBusinessName' => 'A', 'tradeReferences' => array( array( 'email' => 'nope' ) ) ), 'buyer@trade-customer.test' );
check( 'an invalid reference email is refused', null !== $v['error'] );
$v = $forms->validate_credit_payload( array( 'legalBusinessName' => 'A', 'billingAddress' => array( 'line1' => 'x' ) ), 'buyer@trade-customer.test' );
check( 'a partial billing address is refused naming the missing part', null !== $v['error'] && false !== strpos( $v['error'], 'city' ) );
$v = $forms->validate_credit_payload( array( 'legalBusinessName' => str_repeat( 'a', 201 ) ), 'buyer@trade-customer.test' );
check( 'the DTO length caps are enforced locally', null !== $v['error'] );

$client  = new Tack_Test_Forms_Client( array( 'credit-application' => array( 'status' => 'already_pending', 'applicationId' => 'app-1', 'linkedToBuyer' => true ) ) );
$forms   = new Tack_Storefront_Forms( $client );
$outcome = $forms->process_credit_submission( $credit_post );
check( 'already_pending is told as pending, not as a new submission', 'pending' === $outcome['kind'] && false !== strpos( $outcome['message'], 'already' ) );

$client  = new Tack_Test_Forms_Client( array( 'credit-application' => tack_test_api_error( 429, 'Too Many Requests' ) ) );
$forms   = new Tack_Storefront_Forms( $client );
$outcome = $forms->process_credit_submission( $credit_post );
check( 'a throttled credit application asks the customer to wait, and is not queued', 'error' === $outcome['kind'] && false !== strpos( $outcome['message'], 'wait a few minutes' ) && 0 === count( $GLOBALS['TACK_AS_ENQUEUED'] ) );

// ── 5. Outcomes travel by token, and are read once ─────────────────────────

tack_test_reset_transients();
set_transient( Tack_Storefront_Forms::RESULT_PREFIX . 'tok1', array( 'form' => 'credit', 'kind' => 'success', 'message' => 'Done <b>x</b>' ), 300 );
$_GET['tack_form'] = 'tok1';
$client            = new Tack_Test_Forms_Client( array() );
$forms             = new Tack_Storefront_Forms( $client );
$html              = $forms->render_net_terms_form( 'https://shop.example/my-account/net-terms/' );
check( 'a success outcome replaces the form with a notice', false !== strpos( $html, 'woocommerce-message' ) && false === strpos( $html, '<form' ) );
check( 'the outcome message is escaped', false !== strpos( $html, 'Done &lt;b&gt;x&lt;/b&gt;' ) );
check( 'the outcome is consumed on first read', false === get_transient( Tack_Storefront_Forms::RESULT_PREFIX . 'tok1' ) );
set_transient( Tack_Storefront_Forms::RESULT_PREFIX . 'tok2', array( 'form' => 'wholesale', 'kind' => 'success', 'message' => 'other form' ), 300 );
$_GET['tack_form'] = 'tok2';
$html              = $forms->render_net_terms_form( 'https://shop.example/my-account/net-terms/' );
check( 'an outcome for the OTHER form is ignored by this one', false === strpos( $html, 'other form' ) && false !== strpos( $html, '<form' ) );
$_GET = array();

// ── 6. My Account wiring ───────────────────────────────────────────────────

$GLOBALS['TACK_HOOKS'] = array();
$forms                 = new Tack_Storefront_Forms( new Tack_Test_Forms_Client( array() ) );
$forms->init();
$hooked = array_column( $GLOBALS['TACK_HOOKS'], 'hook' );
check( 'the endpoint query vars are filtered into WooCommerce', in_array( 'woocommerce_get_query_vars', $hooked, true ) );
check( 'both endpoint content hooks are registered', in_array( 'woocommerce_account_wholesale-account_endpoint', $hooked, true ) && in_array( 'woocommerce_account_net-terms_endpoint', $hooked, true ) );
check( 'the admin-post handlers are registered for signed-in and guest callers', in_array( 'admin_post_tack_wholesale_application', $hooked, true ) && in_array( 'admin_post_nopriv_tack_wholesale_application', $hooked, true ) && in_array( 'admin_post_nopriv_tack_credit_application', $hooked, true ) );
check( 'both shortcodes are registered', isset( $GLOBALS['TACK_SHORTCODES']['tackquote_wholesale_application'], $GLOBALS['TACK_SHORTCODES']['tackquote_net_terms_application'] ) );
$vars = Tack_Storefront_Forms::add_query_vars( array( 'orders' => 'orders' ) );
check( 'query vars gain both endpoint slugs', 'wholesale-account' === $vars['wholesale-account'] && 'net-terms' === $vars['net-terms'] && 'orders' === $vars['orders'] );

tack_test_set_option( Tack_Storefront_Forms::OPTION_WHOLESALE_TAB, 'no' );
tack_test_set_option( Tack_Storefront_Forms::OPTION_NET_TERMS_TAB, 'no' );
$menu = $forms->account_menu_items( array( 'dashboard' => 'Dashboard', 'customer-logout' => 'Log out' ) );
check( 'with both tabs off the menu is untouched', array( 'dashboard', 'customer-logout' ) === array_keys( $menu ) );
tack_test_set_option( Tack_Storefront_Forms::OPTION_WHOLESALE_TAB, 'yes' );
tack_test_set_option( Tack_Storefront_Forms::OPTION_NET_TERMS_TAB, 'yes' );
$menu = $forms->account_menu_items( array( 'dashboard' => 'Dashboard', 'customer-logout' => 'Log out' ) );
check( 'enabled tabs are inserted before Log out', array( 'dashboard', 'wholesale-account', 'net-terms', 'customer-logout' ) === array_keys( $menu ), implode( ',', array_keys( $menu ) ) );

$GLOBALS['TACK_REWRITE_ENDPOINTS'] = array();
Tack_Storefront_Forms::register_endpoints();
check( 'activation registers both endpoints on EP_ROOT | EP_PAGES', ( EP_ROOT | EP_PAGES ) === $GLOBALS['TACK_REWRITE_ENDPOINTS']['wholesale-account'] && ( EP_ROOT | EP_PAGES ) === $GLOBALS['TACK_REWRITE_ENDPOINTS']['net-terms'] );
$GLOBALS['TACK_REWRITE_FLUSHES'] = 0;
tack_test_set_option( Tack_Storefront_Forms::OPTION_REWRITE_VERSION, '' );
Tack_Storefront_Forms::maybe_flush_rewrite_rules();
Tack_Storefront_Forms::maybe_flush_rewrite_rules();
check( 'rewrite rules are flushed once per plugin version, not per request', 1 === $GLOBALS['TACK_REWRITE_FLUSHES'] );

// ── 7. Settings block ──────────────────────────────────────────────────────

$settings = new Tack_Settings();
tack_test_reset_settings_api();
$settings->register_settings();
$section_ids = array_map(
	function ( $s ) {
		return $s['id'];
	},
	$GLOBALS['TACK_SECTIONS']
);
$in_section  = array();
foreach ( $GLOBALS['TACK_FIELDS'] as $f ) {
	if ( 'tack_quotes_storefront_forms' === $f['section'] ) {
		$in_section[] = $f['id'];
	}
}
check( 'a "Storefront forms" section is registered', in_array( 'tack_quotes_storefront_forms', $section_ids, true ) );
check( 'it holds the slug and the two tab switches', array( Tack_Storefront_Forms::OPTION_FORM_SLUG, Tack_Storefront_Forms::OPTION_WHOLESALE_TAB, Tack_Storefront_Forms::OPTION_NET_TERMS_TAB ) === $in_section, implode( ',', $in_section ) );
// 1.10.0: the tax-exemption switch changes what checkout charges, so it moved to the B2B pricing tab.
$tax_section = '';
foreach ( $GLOBALS['TACK_FIELDS'] as $f ) {
	if ( Tack_Tax_Exempt::OPTION_ENABLED === $f['id'] ) {
		$tax_section = $f['section'];
	}
}
check( 'the tax-exemption switch sits with B2B pricing', 'tack_quotes_b2b_pricing' === $tax_section, $tax_section );
$GLOBALS['TACK_CAPS'] = array();
tack_test_set_option( Tack_Storefront_Forms::OPTION_FORM_SLUG, 'kept' );
check( 'without manage_woocommerce the slug keeps its stored value', 'kept' === $settings->sanitize_form_slug( 'Other Slug' ) );
check( 'without manage_woocommerce a tab cannot be switched on', 'no' === $settings->sanitize_storefront_forms_checkbox( 'yes' ) );
$GLOBALS['TACK_CAPS'] = array( 'manage_woocommerce' );
check( 'the slug is sanitised to a key, and never empty', 'other-slug' === $settings->sanitize_form_slug( 'Other-Slug!' ) && 'default' === $settings->sanitize_form_slug( '' ) );
check( 'with manage_woocommerce a tab switches on', 'yes' === $settings->sanitize_storefront_forms_checkbox( 'yes' ) );
$GLOBALS['TACK_CAPS'] = array();

// ── 8. The plugin-version header is on EVERY request ───────────────────────

$real = new Tack_Api_Client();
tack_test_set_http_response( 200, '{"ok":true}' );
$GLOBALS['TACK_HTTP_REQUESTS'] = array();
$real->request( 'GET', '/integrations/woocommerce/ping' );
$real->submit_credit_application( array( 'legalBusinessName' => 'A', 'contactEmail' => 'a@b.test' ) );
$real->submit_wholesale_form( 'default', array( 'company' => 'A' ), '7' );
$real->request( 'POST', '/integrations/woocommerce/order-sync', array( 'orderId' => 1 ), null, array( 'Idempotency-Key' => 'k' ) );
$missing = array();
foreach ( $GLOBALS['TACK_HTTP_REQUESTS'] as $r ) {
	$headers = isset( $r['args']['headers'] ) ? $r['args']['headers'] : array();
	if ( ! isset( $headers['X-TackQuote-Plugin-Version'] ) || TACK_QUOTES_VERSION !== $headers['X-TackQuote-Plugin-Version'] ) {
		$missing[] = $r['url'];
	}
}
check( 'every request (GET and POST, with and without extra headers) carries X-TackQuote-Plugin-Version', 4 === count( $GLOBALS['TACK_HTTP_REQUESTS'] ) && array() === $missing, implode( ', ', $missing ) );
$last = end( $GLOBALS['TACK_HTTP_REQUESTS'] );
check( '...without displacing a caller-supplied header', 'k' === $last['args']['headers']['Idempotency-Key'] );
check( 'the key still travels only in headers', false === strpos( (string) $last['args']['body'], 'tk_test_key' ) );
check( 'submit_wholesale_form sends the slug in the query and the id in the body', false !== strpos( $GLOBALS['TACK_HTTP_REQUESTS'][2]['url'], '/wholesale-form/submit?slug=default' ) && false !== strpos( $GLOBALS['TACK_HTTP_REQUESTS'][2]['args']['body'], '"wooCustomerId":"7"' ) );

// ── 9. Storefront v1 reads with the legacy fallback ────────────────────────

tack_test_reset_transients();
tack_test_set_option( Tack_Wholesale_Pricing::OPTION_ENABLED, 'yes' );
tack_test_set_logged_in( true, 'buyer@trade-customer.test' );

$client  = new Tack_Test_Forms_Client(
	array(
		'/storefront/v1/quantity-breaks' => array(
			'status'          => 'priced',
			'currency'        => 'eur',
			'accountSpecific' => true,
			'checkoutApplied' => false,
			'rows'            => array(
				array( 'minQty' => 50, 'unitPrice' => 8, 'accountSpecific' => true ),
				array( 'minQty' => 1, 'unitPrice' => 10, 'accountSpecific' => true ),
				array( 'minQty' => 10, 'unitPrice' => 10, 'accountSpecific' => true ),
			),
		),
	)
);
$pricing = new Tack_Wholesale_Pricing( $client );
$product = new Tack_Test_Product( 'SKU-1' );
ob_start();
$pricing->render_v1_quantity_breaks( $client->responses['/storefront/v1/quantity-breaks'], $product );
$table = ob_get_clean();
check( 'a priced v1 ladder renders sorted, with only the rungs that change the price', (bool) preg_match( '/1\+.*50\+/s', $table ) && 2 === substr_count( $table, '<tr><td>' ), $table );
check( 'the caption says the ladder is this account\'s own', false !== strpos( $table, 'Volume pricing for your account' ) );
check( 'each rung is formatted in the answer\'s currency', false !== strpos( $table, 'EUR 8.00' ), $table );
ob_start();
$pricing->render_v1_quantity_breaks( array( 'status' => 'unpriced', 'reason' => 'currency_mismatch', 'currency' => 'EUR', 'presentmentCurrency' => 'USD' ), $product );
check( 'a currency mismatch renders nothing', '' === ob_get_clean() );
ob_start();
$pricing->render_v1_quantity_breaks( array( 'status' => 'anonymous' ), $product );
check( 'an anonymous answer renders nothing', '' === ob_get_clean() );

$GLOBALS['product'] = $product;
ob_start();
$pricing->render_quantity_breaks();
$table = ob_get_clean();
check( 'the product page asks /storefront/v1/quantity-breaks with the SKU and the asserted buyer', array( '/storefront/v1/quantity-breaks?sku=SKU-1&buyerEmail=buyer%40trade-customer.test&buyerExternalId=1' ) === $client->paths(), implode( ', ', $client->paths() ) );
check( '...and renders the ladder from it', false !== strpos( $table, 'Volume pricing for your account' ) );

// An older server: v1 answers 404, the probe ladder on /storefront-pricing/resolve takes over, once.
tack_test_reset_transients();
$client  = new Tack_Test_Forms_Client(
	array(
		'/storefront/v1/'            => tack_test_api_error( 404, 'Cannot GET /storefront/v1/quantity-breaks', false ),
		'/storefront-pricing/resolve' => array(
			'items' => array(
				array( 'sku' => 'SKU-1', 'quantity' => 1, 'unitPrice' => 10 ),
				array( 'sku' => 'SKU-1', 'quantity' => 10, 'unitPrice' => 10 ),
				array( 'sku' => 'SKU-1', 'quantity' => 25, 'unitPrice' => 9 ),
				array( 'sku' => 'SKU-1', 'quantity' => 50, 'unitPrice' => 9 ),
				array( 'sku' => 'SKU-1', 'quantity' => 100, 'unitPrice' => 8 ),
			),
		),
	)
);
$pricing = new Tack_Wholesale_Pricing( $client );
ob_start();
$pricing->render_quantity_breaks();
$table = ob_get_clean();
check( 'when v1 answers 404 the legacy probe ladder is used', array( '/storefront/v1/quantity-breaks?sku=SKU-1&buyerEmail=buyer%40trade-customer.test&buyerExternalId=1', '/storefront-pricing/resolve' ) === $client->paths(), implode( ', ', $client->paths() ) );
check( '...and renders the legacy table', false !== strpos( $table, 'Volume pricing' ) && 3 === substr_count( $table, '<tr><td>' ), $table );
check( 'the 404 is remembered so the next read goes straight to the legacy route', 1 === get_transient( Tack_Api_Client::V1_MISSING_TRANSIENT ) );
$client->sent = array();
$limits       = $client->get_order_limits( 'SKU-1', 'buyer@trade-customer.test' );
check( 'order-limits then reads the legacy route directly', array( '/storefront-b2b/order-limits?sku=SKU-1&buyerEmail=buyer%40trade-customer.test' ) === $client->paths(), implode( ', ', $client->paths() ) );
$client->sent = array();
$client->get_buyer_group( 'buyer@trade-customer.test' );
check( 'buyer-group then reads the legacy route directly', array( '/storefront-b2b/buyer-group?buyerEmail=buyer%40trade-customer.test' ) === $client->paths(), implode( ', ', $client->paths() ) );

tack_test_reset_transients();
$client = new Tack_Test_Forms_Client( array( '/storefront/v1/order-limits' => array( 'status' => 'limited', 'accountSpecific' => false, 'limits' => array( array( 'limitType' => 'product_qty', 'sku' => 'SKU-1', 'min' => 25, 'max' => null, 'currency' => null, 'message' => null ) ), 'checkoutApplied' => false ) ) );
$limits = $client->get_order_limits( 'SKU-1', 'buyer@trade-customer.test' );
check( 'order-limits prefers v1 and returns its shape (min/max per limit)', is_array( $limits ) && 'limited' === $limits['status'] && 25 === $limits['limits'][0]['min'], wp_json_encode( $limits ) );
$client = new Tack_Test_Forms_Client( array( '/storefront/v1/buyer-group' => array( 'status' => 'grouped', 'name' => 'Tier 2', 'code' => 'TIER2' ) ) );
$group  = $client->get_buyer_group( 'buyer@trade-customer.test' );
check( 'buyer-group prefers v1', is_array( $group ) && 'TIER2' === $group['code'] && array( '/storefront/v1/buyer-group?buyerEmail=buyer%40trade-customer.test&buyerExternalId=1' ) === $client->paths() );

// A real outage (not a 404) is NOT remembered as "v1 missing".
tack_test_reset_transients();
$client = new Tack_Test_Forms_Client( array( '/storefront/v1/' => tack_test_api_error( 503, 'down', false ) ) );
$client->get_buyer_group( 'buyer@trade-customer.test' );
check( 'a 503 from v1 does not switch the store to the legacy routes', false === get_transient( Tack_Api_Client::V1_MISSING_TRANSIENT ) );

tack_test_set_option( Tack_Wholesale_Pricing::OPTION_ENABLED, 'no' );
tack_test_set_logged_in( false, '' );
unset( $GLOBALS['product'] );

// ── 10. buyerExternalId: only beside an email, only while signed in ────────

tack_test_reset_transients();
tack_test_set_logged_in( true, 'buyer@trade-customer.test' );
// Every v1 route answers, so no 404 switches the client to the legacy routes mid-test.
$client = new Tack_Test_Forms_Client( array( '/storefront/v1/' => array( 'status' => 'none' ) ) );
$client->get_buyer_group( 'buyer@trade-customer.test' );
check( 'signed in: the WP user id travels as buyerExternalId beside buyerEmail', false !== strpos( $client->paths()[0], 'buyerEmail=buyer%40trade-customer.test&buyerExternalId=1' ), $client->paths()[0] );
$client->sent = array();
$client->get_quantity_breaks( 'SKU-1', '' );
check( 'no email: no buyerExternalId either (an id never travels alone)', false === strpos( $client->paths()[0], 'buyerExternalId' ) && false === strpos( $client->paths()[0], 'buyerEmail' ), $client->paths()[0] );
tack_test_set_logged_in( false, '' );
$client->sent = array();
$client->get_order_limits( 'SKU-1', '' );
check( 'a guest sends no buyerExternalId', false !== strpos( $client->paths()[0], '/storefront/v1/order-limits' ) && false === strpos( $client->paths()[0], 'buyerExternalId' ), $client->paths()[0] );
$client->sent = array();
$client->get_buyer_group( 'typed@guest.test' );
check( 'signed out, even beside an email, no buyerExternalId is sent', false !== strpos( $client->paths()[0], '/storefront/v1/' ) && false === strpos( $client->paths()[0], 'buyerExternalId' ) && false !== strpos( $client->paths()[0], 'buyerEmail=typed%40guest.test' ), $client->paths()[0] );

// The real client: v1 reads carry the key in X-Api-Key only; legacy routes keep both.
tack_test_reset_transients();
tack_test_set_logged_in( true, 'buyer@trade-customer.test' );
$real = new Tack_Api_Client();
tack_test_set_http_response( 200, '{"status":"none"}' );
$GLOBALS['TACK_HTTP_REQUESTS'] = array();
$real->get_buyer_group( 'buyer@trade-customer.test' );
$real->request( 'GET', '/integrations/woocommerce/ping' );
$v1_headers     = $GLOBALS['TACK_HTTP_REQUESTS'][0]['args']['headers'];
$legacy_headers = $GLOBALS['TACK_HTTP_REQUESTS'][1]['args']['headers'];
check( 'a /storefront/v1 read sends NO Authorization header', ! array_key_exists( 'Authorization', $v1_headers ) && 'tk_test_key' === $v1_headers['X-Api-Key'], implode( ',', array_keys( $v1_headers ) ) );
check( '...and still sends the plugin version', TACK_QUOTES_VERSION === $v1_headers['X-TackQuote-Plugin-Version'] );
check( 'a legacy plugin route keeps Authorization: Bearer', isset( $legacy_headers['Authorization'] ) && 'Bearer tk_test_key' === $legacy_headers['Authorization'] );
check( 'the v1 URL carries buyerExternalId=1', false !== strpos( $GLOBALS['TACK_HTTP_REQUESTS'][0]['url'], '/storefront/v1/buyer-group?buyerEmail=buyer%40trade-customer.test&buyerExternalId=1' ), $GLOBALS['TACK_HTTP_REQUESTS'][0]['url'] );

// ── 11. Credit application: v1 first, legacy only on 404 / 501 ────────────

$small = array( 'legalBusinessName' => 'A', 'contactEmail' => 'buyer@trade-customer.test' );
tack_test_reset_transients();
$client = new Tack_Test_Forms_Client(
	array(
		'/storefront/v1/credit-application'               => tack_test_api_error( 404, 'Cannot POST', false ),
		'/integrations/woocommerce/credit-application' => array( 'status' => 'received' ),
	)
);
$result = $client->submit_credit_application( $small );
check( 'v1 404: the legacy route takes the application, without the user id', 'received' === $result['status'] && 2 === count( $client->sent ) && '/integrations/woocommerce/credit-application' === $client->sent[1]['path'] && ! isset( $client->sent[1]['body']['buyerExternalId'] ) );
check( '...and the 404 is remembered, so the next one goes straight to legacy', 1 === get_transient( Tack_Api_Client::V1_MISSING_TRANSIENT ) );
tack_test_reset_transients();
$client = new Tack_Test_Forms_Client(
	array(
		'/storefront/v1/credit-application'               => tack_test_api_error( 501, 'Net-terms applications from a merchant API key need an active WooCommerce connection' ),
		'/integrations/woocommerce/credit-application' => array( 'status' => 'received' ),
	)
);
$client->submit_credit_application( $small );
check( 'v1 501 (no WooCommerce connection): the legacy route takes it', 2 === count( $client->sent ) && false === get_transient( Tack_Api_Client::V1_MISSING_TRANSIENT ) );
$client = new Tack_Test_Forms_Client(
	array(
		'/storefront/v1/credit-application'               => tack_test_api_error( 429, 'Too Many Requests' ),
		'/integrations/woocommerce/credit-application' => array( 'status' => 'received' ),
	)
);
$result = $client->submit_credit_application( $small );
check( 'v1 429 is NOT retried on the legacy route (that would double the application)', is_wp_error( $result ) && 1 === count( $client->sent ) );
check( 'the credit body never carries buyerExternalId (forbidNonWhitelisted would 400)', ! array_key_exists( 'buyerExternalId', $client->sent[0]['body'] ) );

// A self-changed, unconfirmed email cannot apply in someone else's name.
$GLOBALS['TACK_USER_META'][1][ Tack_B2B_Notices::META_EMAIL_UNVERIFIED ] = '1';
$client  = new Tack_Test_Forms_Client( array( 'credit-application' => array( 'status' => 'received' ) ) );
$forms   = new Tack_Storefront_Forms( $client );
$outcome = $forms->process_credit_submission( $credit_post );
check( 'an unconfirmed self-changed email is refused net terms with no request made', 'error' === $outcome['kind'] && array() === $client->sent && false !== strpos( $outcome['message'], 'confirmed' ), wp_json_encode( $outcome ) );
unset( $GLOBALS['TACK_USER_META'][1][ Tack_B2B_Notices::META_EMAIL_UNVERIFIED ] );

// ── 12. Tax exemption from buyer-group `taxExempt` ─────────────────────────

/**
 * Run one tax-exemption decision.
 *
 * @param array|WP_Error $answer  buyer-group answer.
 * @param bool           $enabled Switch on?
 * @param array          $session Session data before.
 * @return array{customer:Tack_Stub_Customer,session:Tack_Stub_Session,client:Tack_Test_Forms_Client}
 */
function tack_test_tax_exempt_run( $answer, $enabled = true, $session = array() ) {
	tack_test_reset_transients();
	tack_test_set_option( Tack_Tax_Exempt::OPTION_ENABLED, $enabled ? 'yes' : 'no' );
	$GLOBALS['TACK_WC_CUSTOMER'] = new Tack_Stub_Customer();
	$GLOBALS['TACK_WC_SESSION']  = new Tack_Stub_Session();
	$GLOBALS['TACK_WC_SESSION']->data = $session;
	$client = new Tack_Test_Forms_Client( array( '/storefront/v1/buyer-group' => $answer ) );
	$tax    = new Tack_Tax_Exempt( $client );
	$tax->apply();
	$tax->apply();
	return array(
		'customer' => $GLOBALS['TACK_WC_CUSTOMER'],
		'session'  => $GLOBALS['TACK_WC_SESSION'],
		'client'   => $client,
	);
}

tack_test_set_logged_in( true, 'buyer@trade-customer.test' );
$run = tack_test_tax_exempt_run( array( 'status' => 'grouped', 'name' => 'Schools', 'code' => 'EDU', 'taxExempt' => true ) );
check( 'taxExempt true: the customer is set VAT exempt', array( true ) === $run['customer']->calls && $run['customer']->get_is_vat_exempt() );
check( '...asked once per request, however often totals are calculated', 1 === count( $run['client']->sent ) );
check( '...and never saved to the customer record', 0 === $run['customer']->saves );
$run = tack_test_tax_exempt_run( array( 'status' => 'grouped', 'name' => 'Trade', 'code' => 'T' ) );
check( 'taxExempt missing: no exemption', array() === $run['customer']->calls );
$run = tack_test_tax_exempt_run( array( 'status' => 'none', 'taxExempt' => false ) );
check( 'taxExempt false: no exemption', array() === $run['customer']->calls );
$run = tack_test_tax_exempt_run( array( 'status' => 'grouped', 'taxExempt' => 'true' ) );
check( 'taxExempt as a string is not an exemption (only boolean true)', array() === $run['customer']->calls );
$run = tack_test_tax_exempt_run( tack_test_api_error( 503, 'down', false ) );
check( 'an outage grants no exemption', array() === $run['customer']->calls );
$run = tack_test_tax_exempt_run( array( 'status' => 'none' ), true, array( Tack_Tax_Exempt::SESSION_KEY => 'yes' ) );
check( 'an exemption THIS plugin applied earlier is withdrawn when no longer confirmed', array( false ) === $run['customer']->calls && null === $run['session']->get( Tack_Tax_Exempt::SESSION_KEY ) );
$run = tack_test_tax_exempt_run( array( 'status' => 'none' ) );
check( 'an exemption set by something else is never touched', array() === $run['customer']->calls );
$run = tack_test_tax_exempt_run( array( 'status' => 'grouped', 'taxExempt' => true ), false );
check( 'switch off: no request, no exemption', array() === $run['client']->sent && array() === $run['customer']->calls );
tack_test_set_logged_in( false, '' );
$run = tack_test_tax_exempt_run( array( 'status' => 'grouped', 'taxExempt' => true ) );
check( 'a guest is never asked about or exempted', array() === $run['client']->sent && array() === $run['customer']->calls );
tack_test_set_logged_in( true, 'buyer@trade-customer.test' );
$GLOBALS['TACK_USER_META'][1][ Tack_B2B_Notices::META_EMAIL_UNVERIFIED ] = '1';
$run = tack_test_tax_exempt_run( array( 'status' => 'grouped', 'taxExempt' => true ) );
check( 'an unconfirmed self-changed email is not exempted', array() === $run['client']->sent && array() === $run['customer']->calls );
unset( $GLOBALS['TACK_USER_META'][1][ Tack_B2B_Notices::META_EMAIL_UNVERIFIED ] );
$GLOBALS['TACK_WC_CUSTOMER'] = null;
$GLOBALS['TACK_WC_SESSION']  = null;
tack_test_set_option( Tack_Tax_Exempt::OPTION_ENABLED, 'no' );

// ── 13. Single-product price through /storefront/v1/wholesale-price ────────

/** A product with an id, for the page's-own-product check. */
class Tack_Test_Product_With_Id extends Tack_Test_Product {
	/** @return int */
	public function get_id() {
		return 42;
	}
}

tack_test_reset_transients();
tack_test_set_option( Tack_Wholesale_Pricing::OPTION_ENABLED, 'yes' );
$GLOBALS['TACK_IS_PRODUCT']     = true;
$GLOBALS['TACK_QUERIED_OBJECT'] = 42;
$product                        = new Tack_Test_Product_With_Id( 'SKU-9' );
$product->regular               = 100.0;
$client                         = new Tack_Test_Forms_Client(
	array(
		'/storefront/v1/wholesale-price' => array( 'status' => 'priced', 'unitPrice' => 80, 'currency' => 'usd', 'quantity' => 1, 'accountSpecific' => true ),
	)
);
$pricing = new Tack_Wholesale_Pricing( $client );
$html    = $pricing->filter_price_html( '<span>$100.00</span>', $product );
$pricing->filter_price_html( '<span>$100.00</span>', $product );
check( 'the page\'s own product reads /storefront/v1/wholesale-price with the asserted buyer', array( '/storefront/v1/wholesale-price?sku=SKU-9&quantity=1&buyerEmail=buyer%40trade-customer.test&buyerExternalId=1' ) === $client->paths(), implode( ', ', $client->paths() ) );
check( '...renders it in the answer\'s currency, struck through against the store price', false !== strpos( $html, '<del aria-hidden="true"><span class="amount">$100.00</span></del>' ) && false !== strpos( $html, 'USD 80.00' ), $html );
check( '...and says when the price is the account\'s own', false !== strpos( $html, 'Your account price' ) );
$client  = new Tack_Test_Forms_Client( array( '/storefront/v1/wholesale-price' => array( 'status' => 'unpriced' ) ) );
$pricing = new Tack_Wholesale_Pricing( $client );
check( 'an unpriced answer keeps the store\'s price markup', '<span>$100.00</span>' === $pricing->filter_price_html( '<span>$100.00</span>', $product ) );
$GLOBALS['TACK_QUERIED_OBJECT'] = 7;
$client  = new Tack_Test_Forms_Client( array( '/storefront-pricing/resolve' => array( 'items' => array() ) ) );
$pricing = new Tack_Wholesale_Pricing( $client );
$pricing->filter_price_html( '<span>$100.00</span>', $product );
check( 'a related product on the same page uses the batched legacy route, not one v1 call per card', array( '/storefront-pricing/resolve' ) === $client->paths(), implode( ', ', $client->paths() ) );
$GLOBALS['TACK_IS_PRODUCT']     = false;
$GLOBALS['TACK_QUERIED_OBJECT'] = 0;
tack_test_set_option( Tack_Wholesale_Pricing::OPTION_ENABLED, 'no' );

// ── 14. Order limits read `min`/`max` of a QUANTITY rule ───────────────────

tack_test_reset_transients();
$client  = new Tack_Test_Notices_Client(
	array(
		'order-limits' => array(
			'status'          => 'limited',
			'accountSpecific' => false,
			'limits'          => array(
				array( 'limitType' => 'order_total', 'sku' => null, 'min' => 500, 'max' => null, 'currency' => 'USD', 'message' => null ),
				array( 'limitType' => 'product_qty', 'sku' => 'SKU-1', 'min' => 6, 'max' => 60, 'currency' => null, 'message' => null ),
			),
		),
	)
);
$notices = new Tack_B2B_Notices( $client );
$limits  = $notices->limits_for( 'SKU-1' );
check( 'an order_total rule\'s money minimum is never read as a quantity', is_array( $limits ) && 6 === $limits['min'] && 60 === $limits['max'], wp_json_encode( $limits ) );
check( 'order limits are read through /storefront/v1/order-limits', false !== strpos( $client->paths[0], '/storefront/v1/order-limits?sku=SKU-1' ), $client->paths[0] );
$client  = new Tack_Test_Notices_Client( array( 'order-limits' => array( 'status' => 'limited', 'limits' => array( array( 'limitType' => 'order_total', 'min' => 500, 'max' => null ) ) ) ) );
$notices = new Tack_B2B_Notices( $client );
check( 'only money rules: no quantity limit', null === $notices->limits_for( 'SKU-1' ) );

tack_test_set_logged_in( false, '' );
