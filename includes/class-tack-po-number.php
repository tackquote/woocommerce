<?php
/**
 * Optional purchase-order number at checkout, sent to TackQuote with the order.
 *
 * Off by default; switched on by the "Purchase order number" setting of the
 * "Net terms (TackQuote)" gateway (WooCommerce > Settings > Payments). When on,
 * the field is offered whatever payment method the buyer picks.
 *
 * Two checkouts, two mechanisms (WooCommerce docs, "Additional checkout fields"):
 *   - Checkout block: `woocommerce_register_additional_checkout_field` with
 *     `location: order`, validated on `woocommerce_validate_additional_field`
 *     and copied to this plugin's meta key on
 *     `woocommerce_set_additional_field_value` (WooCommerce also keeps its own
 *     `_wc_other/tackquote/po-number` copy).
 *   - Classic (shortcode) checkout: a field on `woocommerce_after_order_notes`,
 *     validated on `woocommerce_checkout_process`, saved on
 *     `woocommerce_checkout_create_order`.
 * Either way the value lands in order meta `_tackquote_po_number` through the
 * HPOS-safe order CRUD, and reaches TackQuote's `poNumber` through the existing
 * `tack_quotes_order_po_number` filter (Tack_Order_Sync::po_number()).
 *
 * UNVERIFIED: that WooCommerce does not ALSO render additional checkout fields
 * on the classic checkout. The vendor guide presents the API as a Checkout block
 * feature; if a future release renders it on the shortcode checkout, the classic
 * fallback here would show a second PO field.
 *
 * @package TackQuotes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * The checkout PO-number field.
 */
class Tack_Po_Number {

	/** Additional checkout field id (namespaced, as the API requires). */
	const FIELD_ID = 'tackquote/po-number';

	/** Classic checkout input name. */
	const CLASSIC_NAME = 'tackquote_po_number';

	/** Order meta key the value is saved under. */
	const META_KEY = '_tackquote_po_number';

	/** Longest PO number accepted. */
	const MAX_LENGTH = 64;

	/** The net-terms gateway's settings option, which holds the `po_number` switch. */
	const SETTINGS_OPTION = 'woocommerce_tackquote_net_terms_settings';

	/**
	 * Is the PO field switched on (net-terms gateway setting, off by default)?
	 *
	 * Read straight from the stored option, so it works before WooCommerce builds
	 * its gateway list and without loading the gateway class.
	 *
	 * @return bool
	 */
	public static function enabled() {
		$settings = get_option( self::SETTINGS_OPTION, array() );
		return is_array( $settings ) && isset( $settings['po_number'] ) && 'yes' === $settings['po_number'];
	}

	/**
	 * Register hooks. The sync filter is always attached, so a PO saved while the
	 * field was on still syncs after it is switched off; the field hooks attach
	 * only when the setting is on.
	 */
	public function init() {
		add_filter( 'tack_quotes_order_po_number', array( __CLASS__, 'po_for_sync' ), 5, 2 );

		if ( ! self::enabled() ) {
			return;
		}
		add_action( 'woocommerce_init', array( $this, 'register_block_field' ) );
		add_action( 'woocommerce_validate_additional_field', array( $this, 'validate_block_field' ), 10, 3 );
		add_action( 'woocommerce_set_additional_field_value', array( $this, 'save_block_field' ), 10, 4 );

		add_action( 'woocommerce_after_order_notes', array( $this, 'render_classic_field' ) );
		add_action( 'woocommerce_checkout_process', array( $this, 'validate_classic_field' ) );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'save_classic_field' ) );
	}

	/**
	 * The PO number TackQuote receives: a value another filter already supplied wins,
	 * otherwise the one saved at checkout.
	 *
	 * @param mixed    $po_number Value so far ('' by default).
	 * @param WC_Order $order     Order being synced.
	 * @return string
	 */
	public static function po_for_sync( $po_number, $order ) {
		if ( is_scalar( $po_number ) && '' !== trim( (string) $po_number ) ) {
			return (string) $po_number;
		}
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
			return '';
		}
		$saved = $order->get_meta( self::META_KEY, true );
		return is_scalar( $saved ) ? self::clean( (string) $saved ) : '';
	}

	/**
	 * Checkout block: register the optional field in the order section.
	 */
	public function register_block_field() {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}
		woocommerce_register_additional_checkout_field(
			array(
				'id'         => self::FIELD_ID,
				'label'      => __( 'Purchase order number', 'tackquote' ),
				'location'   => 'order',
				'type'       => 'text',
				'required'   => false,
				'attributes' => array(
					'maxLength'    => self::MAX_LENGTH,
					'autocomplete' => 'off',
				),
			)
		);
	}

	/**
	 * Checkout block: refuse a PO longer than MAX_LENGTH.
	 *
	 * @param WP_Error $errors Errors to add to (the API reads this object).
	 * @param string   $key    Field id.
	 * @param mixed    $value  Submitted value.
	 */
	public function validate_block_field( $errors, $key, $value ) {
		if ( self::FIELD_ID !== $key || ! is_object( $errors ) ) {
			return;
		}
		if ( self::too_long( $value ) ) {
			$errors->add( 'tackquote_po_number_too_long', self::too_long_message() );
		}
	}

	/**
	 * Checkout block: copy the value to this plugin's order meta key.
	 *
	 * @param string $key       Field id.
	 * @param mixed  $value     Value.
	 * @param string $group     'other' for an order-location field.
	 * @param object $wc_object The order (or customer, for other locations).
	 */
	public function save_block_field( $key, $value, $group, $wc_object ) {
		if ( self::FIELD_ID !== $key || ! ( $wc_object instanceof WC_Order ) ) {
			return;
		}
		$wc_object->update_meta_data( self::META_KEY, self::clean( $value ) );
	}

	/**
	 * Classic checkout: render the field under the order notes.
	 *
	 * @param WC_Checkout|null $checkout Checkout object.
	 */
	public function render_classic_field( $checkout = null ) {
		$value = is_object( $checkout ) && method_exists( $checkout, 'get_value' ) ? $checkout->get_value( self::CLASSIC_NAME ) : '';
		woocommerce_form_field(
			self::CLASSIC_NAME,
			array(
				'type'              => 'text',
				'label'             => __( 'Purchase order number', 'tackquote' ),
				'required'          => false,
				'class'             => array( 'form-row-wide' ),
				'maxlength'         => self::MAX_LENGTH,
				'custom_attributes' => array( 'autocomplete' => 'off' ),
			),
			$value
		);
	}

	/**
	 * Classic checkout: refuse a PO longer than MAX_LENGTH.
	 */
	public function validate_classic_field() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC_Checkout::process_checkout() verifies its own nonce before this action fires.
		$raw = isset( $_POST[ self::CLASSIC_NAME ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::CLASSIC_NAME ] ) ) : '';
		if ( self::too_long( $raw ) ) {
			wc_add_notice( self::too_long_message(), 'error' );
		}
	}

	/**
	 * Classic checkout: save the value on the order being created (WooCommerce saves it).
	 *
	 * @param WC_Order $order Order (the hook also passes the posted data, which is not needed).
	 */
	public function save_classic_field( $order ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC_Checkout::process_checkout() verifies its own nonce before this action fires.
		$raw = isset( $_POST[ self::CLASSIC_NAME ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::CLASSIC_NAME ] ) ) : '';
		$po  = self::clean( $raw );
		if ( '' !== $po && is_object( $order ) && method_exists( $order, 'update_meta_data' ) ) {
			$order->update_meta_data( self::META_KEY, $po );
		}
	}

	/**
	 * Sanitised, length-capped PO number.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function clean( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$text = sanitize_text_field( (string) $value );
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, self::MAX_LENGTH ) : substr( $text, 0, self::MAX_LENGTH );
	}

	/**
	 * Is this value longer than MAX_LENGTH characters?
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function too_long( $value ) {
		if ( ! is_scalar( $value ) ) {
			return false;
		}
		$text = trim( (string) $value );
		$len  = function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
		return $len > self::MAX_LENGTH;
	}

	/**
	 * The "too long" message.
	 *
	 * @return string
	 */
	private static function too_long_message() {
		/* translators: %d: maximum number of characters. */
		return sprintf( __( 'The purchase order number can be at most %d characters.', 'tackquote' ), self::MAX_LENGTH );
	}
}
