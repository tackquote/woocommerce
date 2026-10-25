<?php
/**
 * Quick order: SKU and quantity rows (or a pasted list), checked against the
 * store's catalogue, then added to the cart or to the quote list. Output of the
 * `[tackquote_quick_order]` shortcode and the "TackQuote quick order" block.
 *
 * This template can be overridden by copying it to
 * yourtheme/woocommerce/tackquote/quick-order.php.
 *
 * Keep the `data-tack-quick-order*` attributes, the `tack-quick-order-*` classes
 * and the `hidden` attributes: assets/js/tack-quick-order.js binds to them, fills
 * the results table and shows it. The outer `woocommerce` class is the wrapper
 * WooCommerce puts around its own shortcodes, so the theme's table, form and
 * button styles apply here too.
 *
 * @package TackQuote\Templates
 * @version 1.12.0
 *
 * @var int    $rows        Empty SKU rows shown at first.
 * @var int    $max_lines   Most lines one order may carry.
 * @var string $button      Classes of the "Check items" button.
 * @var string $cart_button Classes of the "Add to cart" button.
 * @var string $quote       Classes of the quote-list button.
 * @var string $quote_label Label of the quote-list button (the store's "Add to Quote" wording).
 * @var string $secondary   Classes of the "Add a row" button.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="woocommerce tackquote">
<div class="tack-quick-order" data-tack-quick-order data-max-lines="<?php echo (int) $max_lines; ?>">
	<form class="tack-quick-order-form" novalidate>
		<table class="shop_table tack-quick-order-entry">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'SKU', 'tackquote' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Quantity', 'tackquote' ); ?></th>
				</tr>
			</thead>
			<tbody class="tack-quick-order-rows">
				<?php for ( $tack_qo_row = 1; $tack_qo_row <= (int) $rows; $tack_qo_row++ ) : ?>
				<tr class="tack-quick-order-row">
					<td>
						<label class="screen-reader-text" for="<?php echo esc_attr( 'tack-qo-sku-' . $tack_qo_row ); ?>">
							<?php
							/* translators: %d: row number. */
							echo esc_html( sprintf( __( 'SKU, row %d', 'tackquote' ), $tack_qo_row ) );
							?>
						</label>
						<input type="text" class="input-text tack-quick-order-sku" id="<?php echo esc_attr( 'tack-qo-sku-' . $tack_qo_row ); ?>" maxlength="64" autocomplete="off" />
					</td>
					<td>
						<label class="screen-reader-text" for="<?php echo esc_attr( 'tack-qo-qty-' . $tack_qo_row ); ?>">
							<?php
							/* translators: %d: row number. */
							echo esc_html( sprintf( __( 'Quantity, row %d', 'tackquote' ), $tack_qo_row ) );
							?>
						</label>
						<input type="number" class="input-text qty tack-quick-order-qty" id="<?php echo esc_attr( 'tack-qo-qty-' . $tack_qo_row ); ?>" min="1" step="1" value="1" inputmode="numeric" />
					</td>
				</tr>
				<?php endfor; ?>
			</tbody>
		</table>
		<p class="tack-quick-order-tools">
			<button type="button" class="<?php echo esc_attr( $secondary ); ?>"><?php esc_html_e( 'Add a row', 'tackquote' ); ?></button>
		</p>
		<p class="form-row form-row-wide tack-quick-order-paste">
			<label for="tack-qo-paste"><?php esc_html_e( 'Or paste a list', 'tackquote' ); ?> <span class="optional tack-quote-optional"><?php esc_html_e( '(optional)', 'tackquote' ); ?></span></label>
			<textarea id="tack-qo-paste" class="input-text tack-quick-order-paste-input" rows="4" aria-describedby="tack-qo-paste-help"></textarea>
			<span class="description" id="tack-qo-paste-help"><?php esc_html_e( 'One SKU and quantity per line, e.g. ABC-123, 10', 'tackquote' ); ?></span>
		</p>
		<p class="tack-quick-order-actions">
			<button type="submit" class="<?php echo esc_attr( $button ); ?>"><?php esc_html_e( 'Check items', 'tackquote' ); ?></button>
		</p>
	</form>
	<p class="tack-quick-order-message" role="status" aria-live="polite" hidden></p>
	<div class="tack-quick-order-results" hidden>
		<table class="shop_table shop_table_responsive tack-quick-order-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'SKU', 'tackquote' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Product', 'tackquote' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Price', 'tackquote' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Quantity', 'tackquote' ); ?></th>
				</tr>
			</thead>
			<tbody class="tack-quick-order-result-rows"></tbody>
		</table>
		<p class="tack-quick-order-result-actions">
			<button type="button" class="<?php echo esc_attr( $cart_button ); ?>" disabled><?php esc_html_e( 'Add to cart', 'tackquote' ); ?></button>
			<button type="button" class="<?php echo esc_attr( $quote ); ?>" disabled><?php echo esc_html( $quote_label ); ?></button>
		</p>
	</div>
</div>
</div>
