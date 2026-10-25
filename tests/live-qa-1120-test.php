<?php
/**
 * 1.12.0 live QA fixes: Cart block lines carry parent + variation (D1), shoppers never
 * see a connection error (D2), the connection status tells the truth (D4), the embed
 * page names the real reason (D7), the block-grid card control follows the card's
 * button (D8), and one variation formatter (D10). D3 (standing refresh floor) lives in
 * rest-keys-test.php beside the other standing checks.
 *
 * Runs last from tests/run.php; reuses the doubles of storefront-1120-test.php.
 *
 * @package TackQuotes
 */

defined( 'ABSPATH' ) || exit;

// ── D10: one variation formatter ────────────────────────────────────────────
tack_parity_reset();
$qa_parent             = new Tack_1120_Product( 301, 'variable', 'Glove', 'GLOVE', 5.0 );
$qa_var                = new Tack_1120_Variation( 302 );
$qa_var->parent_id     = 301;
$qa_var->sku           = 'GLOVE-LB';
$qa_var->name          = 'Glove - large, blue';
$qa_var->price         = 6.0;
$qa_var->attrs         = array( 'Large', 'Blue' );
$GLOBALS['TACK_TEST_PRODUCTS'] = array(
	301 => $qa_parent,
	302 => $qa_var,
);
check( 'D10: the variation label is WooCommerce\'s formatted variation ("Large, Blue")', 'Large, Blue' === Tack_Block_Product::variation_label( $qa_var ) );
check( 'D10: a line names a variation "Parent - Large, Blue"', 'Glove - Large, Blue' === Tack_Block_Product::line_name( $qa_parent, $qa_var ) );
$GLOBALS['TACK_SKUS'] = array( 'GLOVE-LB' => 302 );
$qa_qo                = ( new Tack_1120_Quick_Order() )->lookup( array( array( 'sku' => 'GLOVE-LB', 'quantity' => 1 ) ) );
check( 'D10: quick order names the variation with the same formatter', 'Glove - Large, Blue' === ( $qa_qo[0]['name'] ?? '' ) );

// ── D1: the Store API cart item carries the parent and the variation ────────
$qa_line = Tack_Quotes::cart_item_data(
	array(
		'product_id'   => 301,
		'variation_id' => 302,
		'quantity'     => 2,
		'data'         => $qa_var,
	)
);
check( 'D1: a variation cart item answers the parent as productId and the variation as variationId', 301 === $qa_line['productId'] && 302 === $qa_line['variationId'] );
check( 'D1: ... with the variation label in its name, and its own SKU', 'Glove - Large, Blue' === $qa_line['name'] && 'GLOVE-LB' === $qa_line['sku'] );
$qa_simple = new Tack_1120_Product( 303, 'simple', 'Bolt', 'BOLT', 0.4 );
$qa_line   = Tack_Quotes::cart_item_data(
	array(
		'product_id'   => 303,
		'variation_id' => 0,
		'data'         => $qa_simple,
	)
);
check( 'D1: a simple cart item answers its own id and 0', 303 === $qa_line['productId'] && 0 === $qa_line['variationId'] && 'Bolt' === $qa_line['name'] );
check( 'D1: a cart item with no product answers nothing', array() === Tack_Quotes::cart_item_data( array( 'product_id' => 0 ) ) );
check( 'D1: the schema names every field', array( 'productId', 'variationId', 'name', 'sku', 'price', 'attributes' ) === array_keys( Tack_Quotes::cart_item_schema() ) );
$qa_q_src = (string) file_get_contents( TACK_QUOTES_DIR . 'includes/class-tack-quotes.php' );
check( 'D1: registered on the Store API cart-item endpoint under the tackquote namespace', false !== strpos( $qa_q_src, "CartItemSchema::IDENTIFIER\n\t\t\t\t\t: 'cart-item'," ) && false !== strpos( $qa_q_src, "'data_callback'   => array( __CLASS__, 'cart_item_data' )," ) );

// ── D2: shoppers never see a connection error ───────────────────────────────
$qa_refused = new WP_Error( 'tack_http_401', 'Invalid API key', array( 'status' => 401, 'json' => true, 'statusCode' => 401, 'code' => 'api_key_invalid' ) );
check( 'D2: a refused key is NOT shown to the shopper', 'We could not send your quote request right now. Please try again in a few minutes.' === Tack_Widget::shopper_error( $qa_refused ) );
check( 'D2: nor an outage or a transport error', Tack_Widget::shopper_error( new WP_Error( 'tack_http_503', '<html>Bad gateway</html>', array( 'status' => 503 ) ) ) === Tack_Widget::shopper_error( new WP_Error( 'http_request_failed', 'cURL error 28', array() ) ) );
check( 'D2: TackQuote\'s own 400 about the request IS shown (the shopper can fix it)', 'Company name is required' === Tack_Widget::shopper_error( new WP_Error( 'tack_http_400', 'Company name is required', array( 'status' => 400, 'json' => true, 'statusCode' => 400 ) ) ) );
check( 'D2: a proxy\'s 400 (not TackQuote\'s JSON) is not', 'We could not send your quote request right now. Please try again in a few minutes.' === Tack_Widget::shopper_error( new WP_Error( 'tack_http_400', 'nginx says no', array( 'status' => 400 ) ) ) );
check( 'D2: a 429 says wait', false !== strpos( Tack_Widget::shopper_error( new WP_Error( 'tack_http_429', 'x', array( 'status' => 429 ) ) ), 'wait a few minutes' ) );

tack_test_set_option( 'tack_quotes_api_key', 'tk_live_qa' );
$GLOBALS['TACK_TRANSIENTS'] = array();
Tack_Widget::record_request_failure( $qa_refused );
$qa_failure = Tack_Widget::last_request_failure();
check( 'D2: the reason is kept for the administrators (status, code, message)', is_array( $qa_failure ) && 401 === $qa_failure['status'] && 'api_key_invalid' === $qa_failure['code'] && 'Invalid API key' === $qa_failure['message'] );
$GLOBALS['TACK_CAPS'] = array( 'manage_options' );
ob_start();
Tack_Widget::failure_notice();
$qa_notice = (string) ob_get_clean();
check( 'D2: administrators see an error notice naming the refused key, with a link to the connection', false !== strpos( $qa_notice, 'notice-error' ) && false !== strpos( $qa_notice, 'refused this store' ) );
$GLOBALS['TACK_CAPS'] = array();
ob_start();
Tack_Widget::failure_notice();
check( 'D2: nobody else does', '' === (string) ob_get_clean() );
$GLOBALS['TACK_TRANSIENTS'] = array();
Tack_Widget::record_request_failure( new WP_Error( 'tack_http_400', 'Company name is required', array( 'status' => 400, 'json' => true, 'statusCode' => 400 ) ) );
check( 'D2: a request the shopper can fix is not an administrator\'s problem', null === Tack_Widget::last_request_failure() );
tack_test_set_option( 'tack_quotes_api_key', 'tk_other' );
$GLOBALS['TACK_TRANSIENTS'] = array();
Tack_Widget::record_request_failure( $qa_refused );
tack_test_set_option( 'tack_quotes_api_key', 'tk_live_qa' );
check( 'D2: a failure recorded for another key is forgotten once a new key is saved', null === Tack_Widget::last_request_failure() );
$qa_w_src = (string) file_get_contents( TACK_QUOTES_DIR . 'includes/class-tack-widget.php' );
check( 'D2: the handler sends the shopper message, never the raw server text', false !== strpos( $qa_w_src, "wp_send_json_error( array( 'message' => self::shopper_error( \$result ) ), 502 );" ) && false === strpos( $qa_w_src, "array( 'message' => \$result->get_error_message() ), 502" ) );

// ── D4: the connection status tells the truth ───────────────────────────────
$GLOBALS['TACK_TRANSIENTS'] = array();
tack_test_set_option( Tack_Standing::OPTION, array() );
check( 'D4: an untested key with no failure: untested (never "connected")', 'untested' === Tack_Settings::connection_status()['state'] );
Tack_Widget::record_request_failure( $qa_refused );
check( 'D4: an untested key the storefront saw refused: rejected', 'rejected' === Tack_Settings::connection_status()['state'] );
$GLOBALS['TACK_TRANSIENTS'] = array();
set_transient(
	Tack_Settings::CONNECTION_CHECK,
	array(
		'ok'      => true,
		'state'   => 'ok',
		'key'     => substr( hash( 'sha256', 'tk_live_qa' ), 0, 16 ),
		'at'      => time(),
		'message' => '',
	),
	DAY_IN_SECONDS
);
check( 'D4: a passing test of the saved key: ok', 'ok' === Tack_Settings::connection_status()['state'] );
tack_test_set_option(
	Tack_Standing::OPTION,
	array(
		'state'     => Tack_Standing::STATE_STOOD_DOWN,
		'reason'    => Tack_Standing::REASON_REVOKED,
		'key'       => Tack_Standing::fingerprint( 'tk_live_qa' ),
		'supported' => true,
		'checked'   => time(),
	)
);
check( 'D4: stood down overrides an older passing test: rejected', 'rejected' === Tack_Settings::connection_status()['state'] );
tack_test_set_option( Tack_Standing::OPTION, array() );

// ── D8: the block-grid card control follows the card's button ───────────────
$qa_wrap = Tack_Widget::card_wrapper_class( '<div class="wp-block-button wc-block-components-product-button align-center has-small-font-size wp-block-woocommerce-product-button" data-x="1"><a class="wp-block-button__link">Add</a></div>' );
check( 'D8: the wrapper wears the Product Button\'s alignment and font size', 'wp-block-button tack-card-quote align-center has-small-font-size' === $qa_wrap, $qa_wrap );
check( 'D8: nothing else of the block (no block identity, no interactivity)', false === strpos( $qa_wrap, 'wc-block-components-product-button ' ) && false === strpos( $qa_wrap, 'wp-block-woocommerce-product-button' ) );
tack_test_set_option( Tack_Widget::OPT_CARD_BUTTONS, 'yes' );
$GLOBALS['TACK_TEST_PRODUCTS'][303] = $qa_simple;
$qa_block          = new stdClass();
$qa_block->context = array( 'postId' => 303 );
$qa_out            = ( new Tack_Widget() )->append_card_to_product_button( '<div class="wp-block-button align-right has-large-font-size"><a>Add</a></div>', array(), $qa_block );
check( 'D8: the control sits in that wrapper, and the button carries the font size too', false !== strpos( $qa_out, '<div class="wp-block-button tack-card-quote align-right has-large-font-size"><button' ) && 1 === preg_match( '/<button[^>]*class="(?=[^"]*tack-card-quote-btn)(?=[^"]*has-large-font-size)[^"]*"/', $qa_out ), $qa_out );
tack_test_set_option( Tack_Widget::OPT_CARD_BUTTONS, 'no' );

// ── D7: the embed page names the real reason ────────────────────────────────
$qa_e_src = (string) file_get_contents( TACK_QUOTES_DIR . 'includes/class-tack-embed-admin.php' );
check( 'D7: a refused key reads as refused (with a reconnect link), not as "does not offer the dashboard"', false !== strpos( $qa_e_src, 'if ( Tack_Api_Client::STATE_REJECTED === $failure ) {' ) && false !== strpos( $qa_e_src, 'TackQuote refused this store’s API key' ) );
check( 'D7: app.tackquote.com is a link', 2 === substr_count( $qa_e_src, "'https://' . self::DEFAULT_APP_HOST . '/'," ) );
$GLOBALS['TACK_TRANSIENTS'] = array();
$qa_client                  = new Tack_Api_Client();
check( 'D7: no cached failure -> \'\'', '' === $qa_client->last_ping_failure() );
set_transient(
	Tack_Api_Client::CAPABILITIES_TRANSIENT,
	array(
		'key'    => substr( hash( 'sha256', 'tk_live_qa' ), 0, 16 ),
		'failed' => Tack_Api_Client::STATE_REJECTED,
		'until'  => time() + 600,
	),
	600
);
check( 'D7: the cached refusal of the saved key reads as rejected', Tack_Api_Client::STATE_REJECTED === $qa_client->last_ping_failure() );
tack_test_set_option( 'tack_quotes_api_key', 'tk_another' );
check( 'D7: ... but not for another key', '' === ( new Tack_Api_Client() )->last_ping_failure() );

// ── Re-review: uninstall, clearing the failure, validator text, "Any" attributes ──
$qa_un = (string) file_get_contents( TACK_QUOTES_DIR . 'uninstall.php' );
check( 'R-1: uninstall removes the storefront-failure transient', false !== strpos( $qa_un, "'" . Tack_Widget::FAILURE_TRANSIENT . "'" ) );
check( 'R-1: the readme lists it with what it holds', false !== strpos( (string) file_get_contents( TACK_QUOTES_DIR . 'readme.txt' ), '`' . Tack_Widget::FAILURE_TRANSIENT . '`' ) );

tack_test_set_option( 'tack_quotes_api_key', 'tk_live_qa' );
$GLOBALS['TACK_TRANSIENTS'] = array();
Tack_Widget::record_request_failure( $qa_refused );
Tack_Standing::observe_ping( array(), array( 'standing' ), 'tk_live_qa' );
check( 'R-2: a passing ping (Tack_Standing active) clears the failure', null === Tack_Widget::last_request_failure() );
$qa_w_src = (string) file_get_contents( TACK_QUOTES_DIR . 'includes/class-tack-widget.php' );
check( 'R-2: a quote request TackQuote took clears it too', false !== strpos( $qa_w_src, "self::clear_request_failure();\n\t\twp_send_json_success(" ) );
check( 'R-2: and the client\'s passing ping', false !== strpos( (string) file_get_contents( TACK_QUOTES_DIR . 'includes/class-tack-api-client.php' ), "Tack_Widget::clear_request_failure();\n\t\t}\n\t\treturn \$caps;" ) );

$qa_generic = 'We could not send your quote request right now. Please try again in a few minutes.';
$qa_v       = function ( $message ) {
	return new WP_Error( 'tack_http_400', $message, array( 'status' => 400, 'json' => true, 'statusCode' => 400 ) );
};
check( 'R-3: class-validator wording is never shown ("property answers should not exist")', $qa_generic === Tack_Widget::shopper_error( $qa_v( 'property answers should not exist' ) ) );
check( 'R-3: nor "must be a string"', $qa_generic === Tack_Widget::shopper_error( $qa_v( 'note must be a string' ) ) );
check( 'R-3: nor a refusal of a field the plugin sent (uploadIds)', $qa_generic === Tack_Widget::shopper_error( $qa_v( 'uploadIds: each value is not a valid id' ) ) );
foreach ( array( 'items must contain at least 1 elements', 'email must match /^[^@]+@[^@]+$/ regular expression', 'note must not be empty', 'company should not be empty', 'quantity must be a positive number', 'tags must be an array' ) as $qa_validator ) {
	check( 'R-3 (Fable nit): validator wording "' . $qa_validator . '" is never shown', $qa_generic === Tack_Widget::shopper_error( $qa_v( $qa_validator ) ) );
}
check( 'R-3 (Fable nit): a word that only contains "must" or "should" is not mistaken for one', 'Mustard-coloured items ship in 3 days; you should notify us of changes.' === Tack_Widget::shopper_error( $qa_v( 'Mustard-coloured items ship in 3 days; you should notify us of changes.' ) ) );
check( 'R-3: a plain shopper-fixable sentence still is', 'An attached file has expired or was not uploaded from this session. Please upload it again.' === Tack_Widget::shopper_error( $qa_v( 'An attached file has expired or was not uploaded from this session. Please upload it again.' ) ) );

$qa_any = Tack_Quotes::cart_item_data(
	array(
		'product_id'   => 301,
		'variation_id' => 302,
		'variation'    => array( 'attribute_pa_size' => 'large', 'attribute_engraving' => 'yes please' ),
		'data'         => $qa_var,
	)
);
check( 'R-4: the cart line\'s own attributes name it (an "Any" value is kept as typed)', 'Glove - Large, yes please' === $qa_any['name'], $qa_any['name'] );
$qa_none = Tack_Quotes::cart_item_data(
	array(
		'product_id'   => 301,
		'variation_id' => 302,
		'variation'    => array(),
		'data'         => $qa_var,
	)
);
check( 'R-4: without them, the variation\'s own label', 'Glove - Large, Blue' === $qa_none['name'] );
