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
