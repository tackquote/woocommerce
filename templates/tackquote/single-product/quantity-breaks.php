<?php
/**
 * Volume pricing: the buyer's unit price at each quantity break, on the product page.
 *
 * This template can be overridden by copying it to
 * yourtheme/woocommerce/tackquote/single-product/quantity-breaks.php.
 *
 * `shop_table` is WooCommerce's table class, so the theme's WooCommerce table
 * styles apply. Every price in `$rows` is already formatted by `wc_price()`.
 *
 * @package TackQuote\Templates
 * @version 1.10.0
 *
 * @var string   $classes Classes of the table (`tackquote-quantity-breaks` first).
 * @var string   $caption Table caption.
 * @var array[]  $rows    Each `{ qty: int, qty_label: string, price_html: string }`, ascending.
 * @var WC_Product|object $product The product.
 */

defined( 'ABSPATH' ) || exit;
?>
<table class="<?php echo esc_attr( $classes ); ?>"><caption><?php echo esc_html( $caption ); ?></caption><thead><tr><th scope="col"><?php esc_html_e( 'Quantity', 'tackquote' ); ?></th><th scope="col"><?php esc_html_e( 'Unit price', 'tackquote' ); ?></th></tr></thead><tbody>
<?php foreach ( $rows as $tackquote_row ) : ?>
<tr><td><?php echo esc_html( $tackquote_row['qty_label'] ); ?></td><td><?php echo wp_kses_post( $tackquote_row['price_html'] ); ?></td></tr>
<?php endforeach; ?>
</tbody></table>
