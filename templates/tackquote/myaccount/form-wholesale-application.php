<?php
/**
 * The wholesale application form (My Account > Wholesale account, and the
 * `[tackquote_wholesale_application]` shortcode).
 *
 * This template can be overridden by copying it to
 * yourtheme/woocommerce/tackquote/myaccount/form-wholesale-application.php.
 *
 * The fields come from the seller's form in TackQuote and are rendered with
 * WooCommerce's form markup (`form-row`, `input-text`). Keep the form's method,
 * action, `data-tack-form` attribute and `$hidden` (the action, slug, return URL
 * and nonce), or submissions are refused. The whole output is passed through the
 * plugin's allowed-HTML list (Tack_Storefront_Forms::allowed_html()).
 *
 * @package TackQuote\Templates
 * @version 1.10.0
 *
 * @var string $notices      Notices to show above the form (escaped markup).
 * @var string $title        Form name ('' for none).
 * @var string $description  Form description ('' for none).
 * @var string $action_url   Where the form posts (admin-post.php).
 * @var bool   $multipart    The form carries files.
 * @var string $fields       The fields (escaped markup).
 * @var string $blocked      Why the form cannot be sent from here (escaped markup), or ''.
 * @var string $hidden       Hidden inputs and the nonce (escaped markup); '' when blocked.
 * @var string $submit_label Submit button label.
 * @var string $submit_class Submit button classes.
 */

defined( 'ABSPATH' ) || exit;

echo $notices; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_html() by Tack_Storefront_Forms::notice().
if ( '' !== $title ) {
	echo '<h2 class="tackquote-form-title">' . esc_html( $title ) . '</h2>';
}
if ( '' !== $description ) {
	echo '<p class="tackquote-form-description">' . esc_html( $description ) . '</p>';
}
echo '<form method="post" action="' . esc_url( $action_url ) . '"' . ( $multipart ? ' enctype="multipart/form-data"' : '' ) . ' class="woocommerce-form tackquote-form" data-tack-form="wholesale">';
echo $fields; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped per attribute by Tack_Storefront_Forms::render_field().
if ( '' !== $blocked ) {
	echo $blocked; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by Tack_Storefront_Forms.
} else {
	echo $hidden; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_attr() values and wp_nonce_field().
	echo '<p class="form-row"><button type="submit" class="' . esc_attr( $submit_class ) . '">' . esc_html( $submit_label ) . '</button></p>';
}
echo '</form>';
