<?php
/**
 * The floating quote-list launcher and its drawer, printed in the footer.
 *
 * This template can be overridden by copying it to
 * yourtheme/woocommerce/tackquote/quote-list-drawer.php.
 *
 * Keep every `id` and `data-*` attribute and the `hidden` attributes: the
 * storefront script (assets/js/tack-quotes.js) finds the launcher, the drawer,
 * the list, the count and the checkout button by id, and shows them itself.
 *
 * @package TackQuote\Templates
 * @version 1.10.0
 *
 * @var string[] $classes        Classes of the launcher container (`tack-quote-list-widget` first).
 * @var array    $fab            Launcher settings, see Tack_Widget::fab_settings().
 * @var string   $opens          `drawer` or `page`.
 * @var string   $page_url       The quote page URL ('' when none).
 * @var string   $style          Inline custom properties for the merchant's offsets.
 * @var string   $toggle_class   Classes of the launcher button (the theme's button classes first).
 * @var string   $checkout_class Classes of the drawer's checkout button.
 * @var string   $checkout_label Label of the drawer's checkout button.
 */

defined( 'ABSPATH' ) || exit;
?>
<div id="tack-quote-list-widget" class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" data-position="<?php echo esc_attr( $fab['position'] ); ?>" data-size="<?php echo esc_attr( $fab['size'] ); ?>" data-pages="<?php echo esc_attr( $fab['pages'] ); ?>" data-opens="<?php echo esc_attr( $opens ); ?>" style="<?php echo esc_attr( $style ); ?>" hidden>
	<button type="button" id="tack-quote-list-toggle" class="<?php echo esc_attr( $toggle_class ); ?>" aria-label="<?php echo esc_attr( $fab['label'] ); ?>" aria-expanded="false" aria-controls="tack-quote-list-drawer"<?php echo 'page' === $opens ? ' data-href="' . esc_url( $page_url ) . '"' : ''; ?>>
		<svg class="tack-fab-icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M9 2a2 2 0 0 0-2 2H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-1a2 2 0 0 0-2-2H9zm0 2h6v2H9V4zM8 11h8v2H8v-2zm0 4h5v2H8v-2z"/></svg>
		<span class="tack-fab-label"><?php echo esc_html( $fab['label'] ); ?></span>
		<span class="tack-fab-count">(<span id="tack-quote-list-count">0</span>)</span>
	</button>
	<div id="tack-quote-list-drawer" class="tack-quote-list-drawer" role="region" aria-labelledby="tack-quote-list-title" hidden>
		<div class="tack-quote-list-drawer-header">
			<strong id="tack-quote-list-title" class="tack-quote-list-title"><?php esc_html_e( 'Your quote list', 'tackquote' ); ?></strong>
			<button type="button" id="tack-quote-list-close" class="tack-quote-list-close" aria-label="<?php esc_attr_e( 'Close', 'tackquote' ); ?>">&times;</button>
		</div>
		<ul id="tack-quote-list-items" class="tack-quote-list-items"></ul>
		<button type="button" id="tack-quote-list-checkout" class="<?php echo esc_attr( $checkout_class ); ?>">
			<?php echo esc_html( $checkout_label ); ?>
		</button>
	</div>
</div>
