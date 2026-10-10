<?php
/**
 * The customer's net terms (My Account > Net terms, and the
 * `[tackquote_net_terms_application]` shortcode), shown instead of the application
 * form when TackQuote reports an active credit line for the signed-in customer.
 *
 * This template can be overridden by copying it to
 * yourtheme/woocommerce/tackquote/myaccount/net-terms-account.php.
 *
 * The whole output is passed through the plugin's allowed-HTML list
 * (Tack_Storefront_Forms::allowed_html()).
 *
 * @package TackQuote\Templates
 * @version 1.10.2
 *
 * @var string                $notices      Notices to show first (escaped markup).
 * @var string                $heading      Heading text.
 * @var string                $description  Introductory text.
 * @var array<string,string>  $lines        Escaped markup per line, keyed `terms`, `credit_limit`
 *                                          and `available` (the last two only when TackQuote sent them).
 * @var int                   $terms_days   Payment terms in days.
 * @var string                $credit_limit Credit limit as sent ('' when not sent).
 * @var string                $available    Credit still available as sent ('' when not sent).
 * @var string                $currency     ISO 4217 code of both amounts.
 */

defined( 'ABSPATH' ) || exit;

echo $notices; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_html() by Tack_Storefront_Forms::notice().
echo '<h2 class="tackquote-net-terms-heading">' . esc_html( $heading ) . '</h2>';
echo '<p class="tackquote-form-description">' . esc_html( $description ) . '</p>';
echo '<div class="tackquote-net-terms-summary">';
foreach ( $lines as $tackquote_key => $tackquote_line ) {
	echo '<p class="tackquote-net-terms-' . esc_attr( str_replace( '_', '-', $tackquote_key ) ) . '">' . $tackquote_line . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_html() text and wc_price() markup built by Tack_Storefront_Forms.
}
echo '</div>';
