<?php
/**
 * Defects from the production E2E run, attempt 2 (2026-10-10, lane W11-e2e-fixes).
 *
 * D3  the guest's success message sat inside the form the success branch hides;
 * D4  a variation's quote-list row carried the parent's SKU and minimum price;
 * D5  target prices travel as `lineItems[].targetPrice` to servers that take it;
 * D6  the modal prefills a signed-in customer's first and last name;
 * D10 a wholesale form slug that matches no form is named to administrators;
 * W10 every request carries `X-TackQuote-Site-Url`.
 *
 * Runs after with-options-block-test.php, whose product doubles it reuses.
 *
 * Run: php tests/run.php
 *
 * @package TackQuotes
 */

// ── D3: the success message is outside the form ─────────────────────────────

$e2e_js    = (string) file_get_contents( TACK_QUOTES_DIR . 'assets/js/tack-quotes.js' );
$e2e_start = strpos( $e2e_js, 'function buildModal()' );
$e2e_end   = false === $e2e_start ? false : strpos( $e2e_js, 'function openModal(', $e2e_start );
$e2e_build = ( false !== $e2e_start && false !== $e2e_end ) ? substr( $e2e_js, $e2e_start, $e2e_end - $e2e_start ) : '';
$e2e_form  = strpos( $e2e_build, "'</form>'" );
$e2e_ok    = strpos( $e2e_build, 'tack-quote-modal-success' );
check( 'D3: buildModal() found and bounded (anchors matched)', '' !== $e2e_build && false !== $e2e_form, 'start ' . var_export( $e2e_start, true ) . ' end ' . var_export( $e2e_end, true ) );
check(
	'D3: the success message is built AFTER the form closes (hiding the form no longer hides it)',
	false !== $e2e_ok && false !== $e2e_form && $e2e_ok > $e2e_form,
	'success at ' . var_export( $e2e_ok, true ) . ', </form> at ' . var_export( $e2e_form, true )
);
check( 'D3: exactly one success node in the modal', 1 === substr_count( $e2e_build, 'tack-quote-modal-success' ) );
check( 'D3: the success node can take focus (tabindex -1) and is a status region', 1 === preg_match( '/<p class="tack-quote-modal-success" role="status" tabindex="-1" hidden><\/p>/', $e2e_build ) );
check( 'D3: the success branch moves focus to the message', false !== strpos( $e2e_js, "\$success.trigger('focus');" ) );
check( 'D3: opening the modal again hides the message and shows the form', false !== strpos( $e2e_js, "\$success.hide().text('');" ) && false !== strpos( $e2e_js, '$form.show();' ) );
check( 'D3: Escape still closes the dialog', 1 === preg_match( "/e\\.key === 'Escape' && modal && !modal\\.hasAttribute\\('hidden'\\)\\) \\{\\s*closeModal\\(\\);/", $e2e_js ) );

// ── D4: a variation's quote-list row carries the variation's SKU and price ──

// Variable "Bracket": S 10.00 (the parent's minimum), M 12.00, L with no price.
$e2e_parent           = new Tack_WO_Product( 203, 'variable', 'E2E Bracket', 'E2E-B', 10.0 );
$e2e_parent->children = array( 204, 205, 206 );
$e2e_s                = new Tack_WO_Product( 204, 'variation', 'E2E Bracket - S', 'E2E-B-S', 10.0, 203 );
$e2e_m                = new Tack_WO_Product( 205, 'variation', 'E2E Bracket - M', 'E2E-B-M', 12.0, 203 );
$e2e_l                = new Tack_WO_Product( 206, 'variation', 'E2E Bracket - L', 'E2E-B-L', null, 203 );
foreach ( array( $e2e_parent, $e2e_s, $e2e_m, $e2e_l ) as $e2e_p ) {
	$GLOBALS['TACK_TEST_PRODUCTS'][ $e2e_p->get_id() ] = $e2e_p;
}
check(
	'D4: variation_lines() maps each variation to its OWN SKU and price (null when unpriced)',
	array(
		'204' => array( 's' => 'E2E-B-S', 'p' => 10.0 ),
		'205' => array( 's' => 'E2E-B-M', 'p' => 12.0 ),
		'206' => array( 's' => 'E2E-B-L', 'p' => null ),
	) === Tack_Block_Product::variation_lines( $e2e_parent ),
	var_export( Tack_Block_Product::variation_lines( $e2e_parent ), true )
);
check( 'D4: variation_lines() is empty for a simple product', array() === Tack_Block_Product::variation_lines( $wo_simple ) );

$GLOBALS['TACK_OPTIONS']['tack_quotes_show_add_to_quote']  = 'yes';
$GLOBALS['TACK_OPTIONS']['tack_quotes_show_request_quote'] = 'yes';
$e2e_w   = new Tack_Widget();
$e2e_out = tack_parity_capture(
	function () use ( $e2e_w, $e2e_parent ) {
		$e2e_w->render_product_button( $e2e_parent );
	}
);
preg_match( '/data-tack-variation-lines="([^"]*)"/', $e2e_out, $e2e_m_attr );
$e2e_lines = isset( $e2e_m_attr[1] ) ? json_decode( html_entity_decode( $e2e_m_attr[1], ENT_QUOTES ), true ) : null;
check( 'D4: a variable product\'s buttons carry data-tack-variation-lines (escaped JSON)', is_array( $e2e_lines ) && 'E2E-B-M' === $e2e_lines['205']['s'] && 12 == $e2e_lines['205']['p'], $e2e_out );
check( 'D4: ...on the buttons container, the attribute the script reads', 1 === preg_match( '/<div class="tack-quote-buttons" data-tack-variation-lines="/', $e2e_out ), $e2e_out );
$e2e_w2  = new Tack_Widget();
$e2e_out = tack_parity_capture(
	function () use ( $e2e_w2, $wo_simple ) {
		$e2e_w2->render_product_button( $wo_simple );
	}
);
check( 'D4: a simple product carries no variation map', false === strpos( $e2e_out, 'data-tack-variation-lines' ), $e2e_out );
$GLOBALS['TACK_OPTIONS']['tack_quotes_show_add_to_quote'] = 'no';
$e2e_w3  = new Tack_Widget();
$e2e_out = tack_parity_capture(
	function () use ( $e2e_w3, $e2e_parent ) {
		$e2e_w3->render_product_button( $e2e_parent );
	}
);
check( 'D4: no map when "Add to Quote" is off (only that button reads it)', false === strpos( $e2e_out, 'data-tack-variation-lines' ), $e2e_out );
$GLOBALS['TACK_OPTIONS']['tack_quotes_show_add_to_quote'] = 'yes';
check( 'D4: the click handler builds the row through TackWithOptions.listRow with the map', false !== strpos( $e2e_js, "api.parseStates(\$btn.closest('.tack-quote-buttons').attr('data-tack-variation-lines'))" ) && false !== strpos( $e2e_js, 'var row = api.listRow(' ) );

// ── D5: target prices as lineItems[].targetPrice, gated on the server ───────

tack_test_set_option( 'tack_quotes_api_key', 'tq_live_secret_key' );
$e2e_rows   = wp_json_encode(
	array(
		array( 'product_id' => 11, 'quantity' => 5, 'target_price' => 3.25 ),
		array( 'product_id' => 51, 'quantity' => 3, 'target_price' => '9.5' ),
		array( 'product_id' => 11, 'quantity' => 1 ),
	)
);
$e2e_widget = ( new ReflectionClass( 'Tack_Widget' ) )->newInstanceWithoutConstructor();
$e2e_base   = array(
	'buyerEmail' => 'buyer@example.com',
	'note'       => 'Need by Friday',
	'source'     => 'woocommerce',
	'lineItems'  => tack_parity_call( $e2e_widget, 'quote_list_line_items', array( $e2e_rows ) ),
	'currency'   => 'USD',
);
check( 'D5: three lines built (two with a target, one without)', 3 === count( $e2e_base['lineItems'] ) && 'HH' === $e2e_base['lineItems'][1]['sku'], var_export( $e2e_base['lineItems'], true ) );

// A server whose ping lists `attachments` (it also takes targetPrice).
tack_test_reset_transients();
tack_test_attach_routes( array( '/ping' => array( 200, array( 'ok' => true, 'capabilities' => array( 'attachments' ) ) ) ) );
$e2e_client = new Tack_Api_Client();
check( 'D5: a server that lists attachments takes targetPrice', true === $e2e_client->supports_target_price() );
$e2e_new = $e2e_widget->with_target_prices( $e2e_base, $e2e_client->supports_target_price() );
check(
	'D5: new server: each line carries ITS targetPrice; a line without one carries none',
	3.25 === $e2e_new['lineItems'][0]['targetPrice'] && 9.5 === $e2e_new['lineItems'][1]['targetPrice'] && ! array_key_exists( 'targetPrice', $e2e_new['lineItems'][2] ),
	var_export( $e2e_new['lineItems'], true )
);
check( 'D5: new server: the unit price stays the store price (the target is context, not the price)', 4.5 === $e2e_new['lineItems'][0]['unitPrice'] && 12.0 === $e2e_new['lineItems'][1]['unitPrice'] );
check( 'D5: new server: the note is the shopper\'s own, with no "Target prices" appendix', 'Need by Friday' === $e2e_new['note'], $e2e_new['note'] );

// An older server: the ping lists nothing new.
tack_test_reset_transients();
tack_test_attach_routes( array( '/ping' => array( 200, array( 'ok' => true ) ) ) );
$e2e_client = new Tack_Api_Client();
check( 'D5: an older server (no capabilities) is not sent targetPrice', false === $e2e_client->supports_target_price() );
$e2e_old  = $e2e_widget->with_target_prices( $e2e_base, $e2e_client->supports_target_price() );
$e2e_json = (string) wp_json_encode( $e2e_old );
check( 'D5: old server: NO targetPrice anywhere in the body (forbidNonWhitelisted would refuse it all)', false === strpos( $e2e_json, 'targetPrice' ), $e2e_json );
check( 'D5: old server: the targets fall back to the note, after the shopper\'s message', 0 === strpos( $e2e_old['note'], 'Need by Friday' ) && false !== strpos( $e2e_old['note'], 'Hard Hat (HH) x3: 9.50 USD' ), $e2e_old['note'] );

// A failed ping is "nothing new" as well.
tack_test_reset_transients();
tack_test_attach_routes( array( '/ping' => array( 503, array( 'message' => 'down' ) ) ) );
check( 'D5: a failed ping never enables targetPrice', false === ( new Tack_Api_Client() )->supports_target_price() );
unset( $GLOBALS['TACK_HTTP_RESPONDER'] );
tack_test_reset_transients();

check( 'D5: a 400 naming targetPrice is recognised (the cache is then dropped)', Tack_Widget::refused_target_price( new WP_Error( 'tack_http_400', 'property lineItems.0.targetPrice should not exist', array( 'status' => 400 ) ) ) );
check( 'D5: ...an unrelated 400 or a 500 naming it is not', ! Tack_Widget::refused_target_price( new WP_Error( 'tack_http_400', 'email must be an email', array( 'status' => 400 ) ) ) && ! Tack_Widget::refused_target_price( new WP_Error( 'tack_http_500', 'targetPrice', array( 'status' => 500 ) ) ) );
$e2e_rows_big = tack_parity_call( $e2e_widget, 'decode_rows', array( wp_json_encode( array( array( 'product_id' => 11, 'quantity' => 1, 'target_price' => 2e12 ) ) ) ) );
check( 'D5: a target above the server maximum (1e12) is dropped, not sent', null === $e2e_rows_big[0]['target_price'] );

$e2e_widget_src = (string) file_get_contents( TACK_QUOTES_DIR . 'includes/class-tack-widget.php' );
check( 'D5: handle_request() decides per line vs note from the server capability', false !== strpos( $e2e_widget_src, '$payload = $this->with_target_prices( $payload, $client->supports_target_price() );' ) );
check( 'D5: the stale "no per-line field" comment is gone', false === strpos( $e2e_widget_src, 'has no per-line field' ) );
check( 'D5: a refusal naming targetPrice drops the capability cache', false !== strpos( $e2e_widget_src, 'self::refused_attachment_fields( $result ) || self::refused_target_price( $result )' ) );

// ── D6: the quote modal prefills a signed-in customer's first and last name ──

/** A WooCommerce customer double with billing names. */
class Tack_E2E_Customer {
	/** @var string */
	public $first = '';
	/** @var string */
	public $last = '';
	/** @return string */
	public function get_billing_first_name() {
		return $this->first;
	}
	/** @return string */
	public function get_billing_last_name() {
		return $this->last;
	}
}
$e2e_w                            = new Tack_Widget();
$e2e_cust                         = new Tack_E2E_Customer();
$e2e_cust->first                  = 'Ada';
$e2e_cust->last                   = 'Byron';
$GLOBALS['TACK_WC_CUSTOMER']      = $e2e_cust;
$GLOBALS['TACK_USER_FIRST_NAME']  = 'Profile';
$GLOBALS['TACK_USER_LAST_NAME']   = 'Name';
tack_test_set_logged_in( false, '' );
check( 'D6: a guest gets no name (nothing personal in a cacheable page)', '' === $e2e_w->current_customer_name( 'first' ) && '' === $e2e_w->current_customer_name( 'last' ) );
tack_test_set_logged_in( true, 'ada@example.com' );
check( 'D6: signed in: the WooCommerce billing first and last name', 'Ada' === $e2e_w->current_customer_name( 'first' ) && 'Byron' === $e2e_w->current_customer_name( 'last' ) );
$e2e_cust->first = '';
$e2e_cust->last  = '';
check( 'D6: no billing name: the WordPress profile name', 'Profile' === $e2e_w->current_customer_name( 'first' ) && 'Name' === $e2e_w->current_customer_name( 'last' ) );
check( 'D6: any other part is refused', '' === $e2e_w->current_customer_name( 'user_email' ) );
unset( $GLOBALS['TACK_WC_CUSTOMER'], $GLOBALS['TACK_USER_FIRST_NAME'], $GLOBALS['TACK_USER_LAST_NAME'] );
tack_test_set_logged_in( false, '' );
check( 'D6: the names are localized to the script', false !== strpos( $e2e_widget_src, "'customerFirstName'   => \$this->current_customer_name( 'first' )," ) && false !== strpos( $e2e_widget_src, "'customerLastName'    => \$this->current_customer_name( 'last' )," ) );
check( 'D6: openModal() fills both name fields after the form reset', 1 === preg_match( "/\\\$form\\[0\\]\\.reset\\(\\);.*\\\$firstName\\.val\\(TackQuotes\\.customerFirstName \\|\\| ''\\);\\s*\\\$overlay\\.find\\('#tack-quote-last-name'\\)\\.val\\(TackQuotes\\.customerLastName \\|\\| ''\\);/s", $e2e_js ) );

// ── D8: modal, drawer and launcher strings mapped to the catalogue ──────────

$e2e_jed  = json_decode( (string) file_get_contents( TACK_QUOTES_DIR . 'languages/tackquote-de_DE-' . md5( 'assets/js/tack-quotes.js' ) . '.json' ), true );
$e2e_msgs = is_array( $e2e_jed ) && isset( $e2e_jed['locale_data']['messages'] ) ? $e2e_jed['locale_data']['messages'] : array();
$e2e_de   = function ( $msgid ) use ( $e2e_msgs ) {
	return isset( $e2e_msgs[ $msgid ][0] ) ? $e2e_msgs[ $msgid ][0] : null;
};
check( 'D8: German modal buttons: "Send request" and "Sending…" are translated', 'Angebotsanfrage absenden' === $e2e_de( 'Send request' ) && 'Wird gesendet...' === $e2e_de( 'Sending…' ), var_export( array( $e2e_de( 'Send request' ), $e2e_de( 'Sending…' ) ), true ) );
check( 'D8: German modal labels: Email address, Note, Company name', 'E-Mail' === $e2e_de( 'Email address' ) && 'Anmerkungen' === $e2e_de( 'Note' ) && 'Unternehmen' === $e2e_de( 'Company name' ) );
check( 'D8: German company field labels: Legal name, Address, State / Province', null !== $e2e_de( 'Legal name' ) && 'Adresszeile 1' === $e2e_de( 'Address' ) && null !== $e2e_de( 'State / Province' ) );
$e2e_po = (string) file_get_contents( TACK_QUOTES_DIR . 'languages/tackquote-de_DE.po' );
check( 'D8: the drawer title and launcher label (PHP) are in the German .po', false !== strpos( $e2e_po, "msgid \"Your quote list\"\nmsgstr \"Angebotskorb\"" ) && false !== strpos( $e2e_po, "msgid \"Quote list\"\nmsgstr \"Angebotskorb\"" ), 'see languages/tackquote-de_DE.po' );
// 1.11.0: the rest of the modal comes from the plugin's own catalogue (languages/source/local).
check( 'D8 (1.11.0): the remaining modal strings are German too (Cancel, First name, Last name, I am buying as)', 'Abbrechen' === $e2e_de( 'Cancel' ) && 'Vorname' === $e2e_de( 'First name' ) && 'Nachname' === $e2e_de( 'Last name' ) && 'Ich kaufe als' === $e2e_de( 'I am buying as' ) );

// ── D10: a wholesale form slug that matches no form ─────────────────────────

tack_test_reset_transients();
$GLOBALS['TACK_LOGGED'] = array();
$GLOBALS['TACK_CAPS']   = array();
$e2e_forms = new Tack_Storefront_Forms( new Tack_Test_Forms_Client( array( 'wholesale-form?slug=default' => tack_test_api_error( 404, 'Form not found' ) ) ) );
$e2e_html  = $e2e_forms->render_wholesale_form( 'default', 'https://shop.example/apply/' );
check( 'D10: the shopper is told the form isn\'t available right now (not "try again later")', false !== strpos( $e2e_html, 'This form isn' ) && false === strpos( $e2e_html, 'try again later' ), $e2e_html );
check( 'D10: a shopper sees no slug and no settings hint', false === strpos( $e2e_html, 'tackquote-admin-hint' ) && false === strpos( $e2e_html, 'default' ), $e2e_html );

$GLOBALS['TACK_CAPS'] = array( 'manage_options' );
$e2e_html             = $e2e_forms->render_wholesale_form( 'default', 'https://shop.example/apply/' );
check( 'D10: a CACHED 404 (second render within the minute) still reads as "no such form"', false !== strpos( $e2e_html, 'This form isn' ) && false === strpos( $e2e_html, 'try again later' ), $e2e_html );
check( 'D10: an administrator also sees the slug that matched no form', false !== strpos( $e2e_html, 'tackquote-admin-hint' ) && false !== strpos( $e2e_html, 'slug &quot;default&quot;' ), $e2e_html );
check( 'D10: ...with a link to the Forms tab', false !== strpos( $e2e_html, 'href="https://shop.example/wp-admin/admin.php?page=' . Tack_Settings::PAGE_SLUG . '&tab=forms"' ), $e2e_html );
$e2e_hint = ( new Tack_Storefront_Forms( new Tack_Test_Forms_Client( array() ) ) )->missing_form_hint( 'x"><script>alert(1)</script>' );
check( 'D10: the slug is escaped in the hint', false === strpos( $e2e_hint, '<script>' ), $e2e_hint );
$GLOBALS['TACK_CAPS'] = array( 'manage_woocommerce' );
check( 'D10: a shop manager without manage_options sees no hint', '' === $e2e_forms->missing_form_hint( 'default' ) );
$GLOBALS['TACK_CAPS'] = array();

$e2e_lines = array_values(
	array_filter(
		$GLOBALS['TACK_LOGGED'],
		function ( $l ) {
			return false !== strpos( $l[1], 'matches no TackQuote wholesale form' );
		}
	)
);
check( 'D10: two renders in a day log the missing slug ONCE', 1 === count( $e2e_lines ), var_export( $GLOBALS['TACK_LOGGED'], true ) );
check( 'D10: the log line names the slug and the status, never a key', 1 === count( $e2e_lines ) && false !== strpos( $e2e_lines[0][1], '"default"' ) && false !== strpos( $e2e_lines[0][1], 'HTTP 404' ) );
$e2e_forms->render_wholesale_form( 'trade', 'https://shop.example/apply/' );
check( 'D10: another missing slug is logged on its own', 2 === count( array_filter( $GLOBALS['TACK_LOGGED'], function ( $l ) { return false !== strpos( $l[1], 'matches no TackQuote wholesale form' ); } ) ) ); // phpcs:ignore

tack_test_reset_transients();
$e2e_forms = new Tack_Storefront_Forms( new Tack_Test_Forms_Client( array( 'wholesale-form?slug=default' => tack_test_api_error( 503, 'down', false ) ) ) );
$e2e_html  = $e2e_forms->render_wholesale_form( 'default', 'https://shop.example/apply/' );
check( 'D10: any other failure keeps "not available right now. Please try again later."', false !== strpos( $e2e_html, 'Please try again later.' ) && false === strpos( $e2e_html, 'tackquote-admin-hint' ), $e2e_html );
check( 'D10: uninstall removes the once-a-day log marker', false !== strpos( (string) file_get_contents( TACK_QUOTES_DIR . 'uninstall.php' ), "'" . Tack_Storefront_Forms::MISSING_FORM_LOGGED . "'" ) );
tack_test_reset_transients();

// ── W10: X-TackQuote-Site-Url on every request ───────────────────────────────

tack_test_reset_transients();
tack_test_set_option( 'tack_quotes_api_key', 'tq_live_secret_key' );
tack_test_attach_routes( array( '/' => array( 200, array( 'ok' => true ) ) ) );
$e2e_real = new Tack_Api_Client();
$e2e_real->request( 'GET', '/integrations/woocommerce/ping' );
$e2e_real->request( 'POST', '/integrations/woocommerce/quote-requests', array( 'buyerEmail' => 'a@example.com' ) );
$e2e_real->request( 'POST', '/integrations/woocommerce/order-sync', array( 'orderId' => 1 ), null, array( 'Idempotency-Key' => 'k' ) );
$e2e_real->request( 'GET', '/storefront/v1/buyer-group?buyerEmail=a%40example.com', null, null, array( 'Authorization' => null ) );
$e2e_real->request( 'POST', '/storefront/v1/quote-upload?name=a.pdf', '%PDF-1.4', null, array( 'Content-Type' => 'application/octet-stream', 'Authorization' => null ) );
$e2e_missing = array();
foreach ( $GLOBALS['TACK_HTTP_REQUESTS'] as $e2e_r ) {
	$e2e_h = isset( $e2e_r['args']['headers'] ) ? $e2e_r['args']['headers'] : array();
	if ( ! isset( $e2e_h['X-TackQuote-Site-Url'] ) || home_url() !== $e2e_h['X-TackQuote-Site-Url'] ) {
		$e2e_missing[] = $e2e_r['url'];
	}
}
check( 'W10: every request (GET, POST, raw upload, v1 with Authorization removed, extra headers) carries X-TackQuote-Site-Url = home_url()', 5 === count( $GLOBALS['TACK_HTTP_REQUESTS'] ) && array() === $e2e_missing, 'missing on: ' . implode( ', ', $e2e_missing ) );
check( 'W10: ...beside the plugin version header, which is unchanged', TACK_QUOTES_VERSION === $GLOBALS['TACK_HTTP_REQUESTS'][0]['args']['headers']['X-TackQuote-Plugin-Version'] );
check( 'W10: the site URL is never in a body', false === strpos( (string) $GLOBALS['TACK_HTTP_REQUESTS'][1]['args']['body'], 'shop.example' ) );
unset( $GLOBALS['TACK_HTTP_RESPONDER'] );
tack_test_reset_transients();
