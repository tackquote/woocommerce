<?php
/**
 * The customer's approved wholesale account (My Account > Wholesale account, and the
 * `[tackquote_wholesale_application]` shortcode), shown instead of the application
 * form when TackQuote reports the signed-in customer's wholesale application as
 * approved (`GET /storefront/v1/price-access` answers `linked` with
 * `wholesaleApproved: true`).
 *
 * This template can be overridden by copying it to
 * yourtheme/woocommerce/tackquote/myaccount/wholesale-account.php.
 *
 * The whole output is passed through the plugin's allowed-HTML list
 * (Tack_Storefront_Forms::allowed_html()).
 *
 * @package TackQuote\Templates
 * @version 1.11.0
 *
 * @var string $notices     Notices to show first (escaped markup).
 * @var string $heading     Heading text.
 * @var string $description "Your wholesale account is approved."
 * @var string $shop_url    The shop page ('' when WooCommerce has none).
 * @var string $shop_label  Text of the link to the shop.
 * @var string $shop_class  The theme's button classes for that link.
 */

defined( 'ABSPATH' ) || exit;

echo $notices; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_html() by Tack_Storefront_Forms::notice().
echo '<h2 class="tackquote-wholesale-heading">' . esc_html( $heading ) . '</h2>';
echo '<div class="tackquote-wholesale-summary">';
echo '<p class="tackquote-wholesale-approved">' . esc_html( $description ) . '</p>';
if ( '' !== $shop_url ) {
	echo '<p class="tackquote-wholesale-shop"><a class="' . esc_attr( $shop_class ) . '" href="' . esc_url( $shop_url ) . '">' . esc_html( $shop_label ) . '</a></p>';
}
echo '</div>';
