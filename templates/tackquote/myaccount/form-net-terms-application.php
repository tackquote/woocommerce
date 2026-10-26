<?php
/**
 * The net-terms (credit) application form (My Account > Net terms, and the
 * `[tackquote_net_terms_application]` shortcode). Shown to signed-in customers only.
 *
 * This template can be overridden by copying it to
 * yourtheme/woocommerce/tackquote/myaccount/form-net-terms-application.php.
 *
 * Keep the form's method, action, `data-tack-form` attribute, the field names
 * (`tack_ct[...]`) and `$hidden` (the action, return URL and nonce), or
 * submissions are refused. The whole output is passed through the plugin's
 * allowed-HTML list (Tack_Storefront_Forms::allowed_html()).
 *
 * @package TackQuote\Templates
 * @version 1.12.0
 *
 * @var string $notices      Notices to show above the form (escaped markup).
 * @var string $description  Introductory text.
 * @var string $action_url   Where the form posts (admin-post.php).
 * @var string $fields       The fields (escaped markup).
 * @var string $hidden       Hidden inputs and the nonce (escaped markup).
 * @var string $submit_label Submit button label.
 * @var string $submit_class Submit button classes.
 */

defined( 'ABSPATH' ) || exit;

echo wp_kses( $notices, Tack_Storefront_Forms::allowed_html() );
echo '<p class="tackquote-form-description">' . esc_html( $description ) . '</p>';
echo '<form method="post" action="' . esc_url( $action_url ) . '" class="woocommerce-form tackquote-form" data-tack-form="credit">';
echo wp_kses( $fields, Tack_Storefront_Forms::allowed_html() );
echo wp_kses( $hidden, Tack_Storefront_Forms::allowed_html() );
echo '<p class="form-row"><button type="submit" class="' . esc_attr( $submit_class ) . '">' . esc_html( $submit_label ) . '</button></p></form>';
