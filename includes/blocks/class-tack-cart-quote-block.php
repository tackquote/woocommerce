<?php
/**
 * "Request a quote for your cart" inside the Cart block (1.12.0).
 *
 * The classic cart template fires `woocommerce_proceed_to_checkout`, where the
 * plugin prints the control under the Proceed button. The Cart block fires no PHP
 * hook there; until 1.12.0 a fixed control in the page footer stood in for it.
 * This places it where the classic one sits, through WooCommerce's documented
 * extension points for the Cart block:
 *
 *   - the Blocks Integration API: an `IntegrationInterface` registered on
 *     `woocommerce_blocks_cart_block_registration`, whose script is enqueued with
 *     the Cart block and whose `get_script_data()` reaches the client as the
 *     `<name>_data` setting (IntegrationRegistry::get_all_registered_script_data(),
 *     WooCommerce 11.2.1; developer.woocommerce.com, "IntegrationInterface");
 *   - the `ExperimentalOrderMeta` slot, which in the Cart block renders "below the
 *     checkout summary section and above the Proceed to Checkout button"
 *     (developer.woocommerce.com, "Available Slots"), filled with `registerPlugin`
 *     in the `woocommerce-checkout` scope. The slot is EXPERIMENTAL in WooCommerce's
 *     own naming: should it go, the script registers nothing and the footer
 *     control is used again (see Tack_Widget::render_cart_quote_button_footer()).
 *
 * The control is the same `.tack-quote-cart-btn` the classic cart prints, so the
 * storefront script's handler (which re-reads the live cart from the Store API)
 * drives both. Nothing about the cart is computed here.
 *
 * Loaded only when WooCommerce's IntegrationInterface exists (Tack_Quotes::init()).
 *
 * @package TackQuotes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;

/**
 * Cart block integration for the cart quote control.
 */
final class Tack_Cart_Quote_Block implements IntegrationInterface {

	/** Integration name; its script data is the `tackquote-cart-quote_data` setting. */
	const NAME = 'tackquote-cart-quote';

	/** Script handle. */
	const SCRIPT_HANDLE = 'tackquote-cart-quote-block';

	/**
	 * Set when WooCommerce initialised this integration for a Cart block: the footer
	 * control then stands down on the cart page.
	 *
	 * @var bool
	 */
	private static $initialized = false;

	/**
	 * Register on the Cart block's integration registry.
	 *
	 * @param object $registry WooCommerce's IntegrationRegistry.
	 */
	public static function register( $registry ) {
		if ( is_object( $registry ) && method_exists( $registry, 'register' ) && 'yes' === get_option( Tack_Widget::OPT_CART_BUTTON, 'no' ) ) {
			$registry->register( new self() );
		}
	}

	/**
	 * Did WooCommerce initialise the integration in this request?
	 *
	 * @return bool
	 */
	public static function active() {
		return self::$initialized;
	}

	/**
	 * Forget the flag (tests).
	 */
	public static function reset() {
		self::$initialized = false;
	}

	/**
	 * Integration name.
	 *
	 * @return string
	 */
	public function get_name() {
		return self::NAME;
	}

	/**
	 * Register the script; WooCommerce enqueues it with the Cart block.
	 */
	public function initialize() {
		self::$initialized = true;
		wp_register_script(
			self::SCRIPT_HANDLE,
			TACK_QUOTES_URL . 'assets/js/tack-cart-quote-block.js',
			array( 'wc-blocks-checkout', 'wp-plugins', 'wp-element', 'tackquote' ),
			TACK_QUOTES_VERSION,
			true
		);
	}

	/**
	 * Front-end script handles.
	 *
	 * @return string[]
	 */
	public function get_script_handles() {
		return array( self::SCRIPT_HANDLE );
	}

	/**
	 * Editor script handles: none (the control shows on the live cart only).
	 *
	 * @return string[]
	 */
	public function get_editor_script_handles() {
		return array();
	}

	/**
	 * What the client needs: the label and the theme's button classes.
	 *
	 * @return array{label:string,buttonClass:string}
	 */
	public function get_script_data() {
		$label = trim( (string) get_option( Tack_Widget::OPT_CART_BUTTON_LABEL, '' ) );
		return array(
			'label'       => '' !== $label ? $label : __( 'Request a quote for your cart', 'tackquote' ),
			'buttonClass' => Tack_Widget::button_class( 'tack-quote-cart-btn' ),
		);
	}
}
