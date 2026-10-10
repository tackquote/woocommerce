<?php
/**
 * Blocks checkout integration for the "Net terms (TackQuote)" gateway.
 *
 * WooCommerce's Checkout block lists only payment methods that register a
 * client-side integration; a classic `WC_Payment_Gateway` alone is invisible
 * there. This is the server half (WooCommerce docs, "Payment method
 * integration": a class extending `AbstractPaymentMethodType`, registered on
 * `woocommerce_blocks_payment_method_type_registration`); the client half is
 * `assets/js/tack-net-terms-block.js`.
 *
 * The browser receives the title, the description and ONE boolean, `canPay`,
 * computed here from the same fail-closed decision the classic checkout uses
 * (Tack_Gateway_Net_Terms::is_available()). Never the credit limit, the terms,
 * or anything else about the buyer's credit line. `canPay` decides only whether
 * the option is shown; the order itself is re-checked server-side in
 * process_payment(), so a tampered client cannot place a terms order.
 *
 * Loaded only when the Blocks classes exist (see Tack_Quotes::init()).
 *
 * @package TackQuotes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Net-terms payment method for the Cart and Checkout blocks.
 */
final class Tack_Net_Terms_Block extends AbstractPaymentMethodType {

	/** Script handle of the client-side registration. */
	const SCRIPT_HANDLE = 'tackquote-net-terms-block';

	/**
	 * Payment method name; must equal the gateway id and the JS registration.
	 *
	 * @var string
	 */
	protected $name = Tack_Gateway_Net_Terms::ID;

	/**
	 * The gateway whose decision `canPay` reports. Injected in tests.
	 *
	 * @var Tack_Gateway_Net_Terms|null
	 */
	private $gateway;

	/**
	 * Constructor.
	 *
	 * @param Tack_Gateway_Net_Terms|null $gateway Gateway; resolved from WooCommerce when null.
	 */
	public function __construct( $gateway = null ) {
		$this->gateway = $gateway instanceof Tack_Gateway_Net_Terms ? $gateway : null;
	}

	/**
	 * Load the gateway settings (cheap: one option read).
	 */
	public function initialize() {
		$settings       = get_option( 'woocommerce_' . Tack_Gateway_Net_Terms::ID . '_settings', array() );
		$this->settings = is_array( $settings ) ? $settings : array();
	}

	/**
	 * Mirrors the gateway's own Enable switch. When false, no script loads.
	 *
	 * @return bool
	 */
	public function is_active() {
		return 'yes' === $this->get_setting( 'enabled', 'no' );
	}

	/**
	 * Register the client-side script.
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles() {
		wp_register_script(
			self::SCRIPT_HANDLE,
			TACK_QUOTES_URL . 'assets/js/tack-net-terms-block.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ),
			TACK_QUOTES_VERSION,
			true
		);
		return array( self::SCRIPT_HANDLE );
	}

	/**
	 * What the client may know: title, description, and whether to offer the method.
	 *
	 * @return array{title:string,description:string,canPay:bool,supports:string[]}
	 */
	public function get_payment_method_data() {
		$gateway = $this->gateway();
		$can_pay = false;
		if ( null !== $gateway ) {
			try {
				$can_pay = true === $gateway->is_available();
			} catch ( Exception $e ) {
				$can_pay = false; // Fail closed.
			}
		}
		return array(
			'title'       => (string) $this->get_setting( 'title', '' ),
			'description' => (string) $this->get_setting( 'description', '' ),
			'canPay'      => $can_pay,
			'supports'    => array( 'products' ),
		);
	}

	/**
	 * The registered gateway instance, or null.
	 *
	 * @return Tack_Gateway_Net_Terms|null
	 */
	private function gateway() {
		if ( null !== $this->gateway ) {
			return $this->gateway;
		}
		if ( ! function_exists( 'WC' ) || ! is_object( WC() ) || ! method_exists( WC(), 'payment_gateways' ) ) {
			return null;
		}
		$registry = WC()->payment_gateways();
		if ( ! is_object( $registry ) || ! method_exists( $registry, 'payment_gateways' ) ) {
			return null;
		}
		$all = $registry->payment_gateways();
		return isset( $all[ Tack_Gateway_Net_Terms::ID ] ) && $all[ Tack_Gateway_Net_Terms::ID ] instanceof Tack_Gateway_Net_Terms
			? $all[ Tack_Gateway_Net_Terms::ID ]
			: null;
	}
}
