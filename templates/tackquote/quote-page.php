<?php
/**
 * The quote page: the shopper's quote list as a table, an optional message and
 * the submit button. Output of the `[tackquote_quote_page]` shortcode.
 *
 * This template can be overridden by copying it to
 * yourtheme/woocommerce/tackquote/quote-page.php.
 *
 * Keep every `id`, the `data-target-price` / `data-message` attributes and the
 * `hidden` attributes: the storefront script (assets/js/tack-quotes.js) fills
 * `#tack-quote-page-items` from the shopper's list and shows the table itself.
 * The outer `woocommerce` class is the wrapper WooCommerce puts around its own
 * shortcodes, so the theme's WooCommerce table and button styles apply here too.
 *
 * @package TackQuote\Templates
 * @version 1.10.0
 *
 * @var bool   $target_price    Show the "Target price" column.
 * @var bool   $message         Show the message field.
 * @var int    $message_max     Maximum message length.
 * @var string $shop_url        "Continue shopping" URL ('' to omit the link).
 * @var string $continue_class  Classes of the "Continue shopping" link.
 * @var string $submit_class    Classes of the submit button.
 * @var string $submit_label    Label of the submit button.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="woocommerce tackquote">
<div id="tack-quote-page" class="tack-quote-page" data-target-price="<?php echo $target_price ? 'yes' : 'no'; ?>" data-message="<?php echo $message ? 'yes' : 'no'; ?>">
	<p class="tack-quote-page-empty"><?php esc_html_e( 'No products added yet.', 'tackquote' ); ?></p>
	<table class="shop_table shop_table_responsive tack-quote-page-table" hidden>
		<thead>
			<tr>
				<th class="tack-quote-page-col-product" scope="col"><?php esc_html_e( 'Product', 'tackquote' ); ?></th>
				<th class="tack-quote-page-col-qty" scope="col"><?php esc_html_e( 'Quantity', 'tackquote' ); ?></th>
				<th class="tack-quote-page-col-price" scope="col"><?php esc_html_e( 'Unit price (excl. tax)', 'tackquote' ); ?></th>
				<?php if ( $target_price ) : ?>
				<th class="tack-quote-page-col-target" scope="col"><?php esc_html_e( 'Target price', 'tackquote' ); ?></th>
				<?php endif; ?>
				<th class="tack-quote-page-col-remove" scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Remove', 'tackquote' ); ?></span></th>
			</tr>
		</thead>
		<tbody id="tack-quote-page-items"></tbody>
	</table>
	<?php if ( $message ) : ?>
	<p class="form-row form-row-wide tack-quote-field tack-quote-page-message">
		<label for="tack-quote-page-message"><?php esc_html_e( 'Message', 'tackquote' ); ?> <span class="optional tack-quote-optional"><?php esc_html_e( '(optional)', 'tackquote' ); ?></span></label>
		<textarea id="tack-quote-page-message" class="input-text" rows="3" maxlength="<?php echo (int) $message_max; ?>"></textarea>
	</p>
	<?php endif; ?>
	<p class="tack-quote-page-actions">
		<?php if ( '' !== $shop_url ) : ?>
		<a class="<?php echo esc_attr( $continue_class ); ?>" href="<?php echo esc_url( $shop_url ); ?>"><?php esc_html_e( 'Continue shopping', 'tackquote' ); ?></a>
		<?php endif; ?>
		<button type="button" id="tack-quote-page-submit" class="<?php echo esc_attr( $submit_class ); ?>" disabled>
			<?php echo esc_html( $submit_label ); ?>
		</button>
	</p>
</div>
</div>
