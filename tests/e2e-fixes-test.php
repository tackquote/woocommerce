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
