<?php
/**
 * Tax-exempt buyers: no tax for a signed-in customer TackQuote marks exempt.
 *
 * TackQuote answers `taxExempt` on `GET /storefront/v1/buyer-group` for an
 * identified buyer. When it is TRUE this class calls
 * `WC()->customer->set_is_vat_exempt( true )` before the cart totals are built;
 * WooCommerce's `WC_Cart_Totals` then skips tax
 * (`$this->calculate_tax = wc_tax_enabled() && ! $is_customer_vat_exempt`), and
 * `WC_Cart::calculate_totals()` fires `woocommerce_before_calculate_totals`
 * immediately before constructing it (WooCommerce core, read via Context7
 * `/woocommerce/woocommerce`).
 *
 * Rules:
 *   - Off by default (`tack_quotes_apply_tax_exempt`): it changes what checkout
 *     charges, and the 1.6.0 / 1.7.0 precedent ships those switched off.
 *   - Asked at most once per request, only for a signed-in customer, only on the
 *     cart-totals path (cart, checkout, the Store API), never per page view.
 *   - Only an explicit `taxExempt: true` exempts. Missing, false, an outage or an
 *     older server that does not send the field: tax is charged. An exemption
 *     that cannot be confirmed is not granted.
 *   - Never persisted to the customer record (no `save()`). WooCommerce keeps the
 *     customer object in the session, so an exemption THIS class applied earlier
 *     in the session is withdrawn as soon as TackQuote stops confirming it; one
 *     set by anything else (an EU VAT plugin) is never touched.
 *
 * @package TackQuotes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Applies TackQuote's tax exemption to the signed-in customer.
 */
class Tack_Tax_Exempt {

	/** Option: apply TackQuote tax exemptions at checkout ('yes' / 'no'). */
	const OPTION_ENABLED = 'tack_quotes_apply_tax_exempt';

	/** WooCommerce session key remembering that THIS plugin set the exemption. */
	const SESSION_KEY = 'tack_quotes_vat_exempt_applied';

	/**
	 * API client.
	 *
	 * @var Tack_Api_Client
	 */
	private $client;

	/**
	 * Whether this request has already been decided.
	 *
	 * @var bool
	 */
	private $decided = false;

	/**
	 * Constructor.
	 *
	 * @param Tack_Api_Client|null $client Injected in tests; built here otherwise.
	 */
	public function __construct( $client = null ) {
		$this->client = $client instanceof Tack_Api_Client ? $client : new Tack_Api_Client();
	}

	/**
	 * Is the switch on?
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return 'yes' === get_option( self::OPTION_ENABLED, 'no' );
	}

	/**
	 * Register hooks. Priority 5: before pricing (20) and other totals filters.
	 */
	public function init() {
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply' ), 5 );
	}

	/**
	 * Decide this request's exemption from TackQuote's answer.
	 */
	public function apply() {
		if ( $this->decided ) {
			return;
		}
		$this->decided = true;

		if ( ! self::is_enabled() || ! is_user_logged_in() ) {
			return;
		}
		if ( ! function_exists( 'WC' ) ) {
			return;
		}
		$wc = WC();
		if ( ! is_object( $wc ) || ! isset( $wc->customer ) || ! is_object( $wc->customer ) || ! method_exists( $wc->customer, 'set_is_vat_exempt' ) ) {
			return;
		}
		// The same trust rule as pricing and the buyer group: a self-changed,
		// unconfirmed email resolves nobody (Tack_B2B_Notices::flag_email_change).
		$email = Tack_Storefront_Forms::trusted_account_email();
		if ( '' === $email ) {
			return;
		}

		$answer = $this->client->get_buyer_group( $email );
		$exempt = ! is_wp_error( $answer ) && is_array( $answer ) && isset( $answer['taxExempt'] ) && true === $answer['taxExempt'];
		if ( is_wp_error( $answer ) ) {
			$this->log( 'buyer-group (tax exemption) lookup failed; tax is charged: ' . $answer->get_error_message() );
		}

		$session = isset( $wc->session ) && is_object( $wc->session ) && method_exists( $wc->session, 'get' ) ? $wc->session : null;
		if ( $exempt ) {
			$wc->customer->set_is_vat_exempt( true );
			if ( null !== $session ) {
				$session->set( self::SESSION_KEY, 'yes' );
			}
			return;
		}
		if ( null !== $session && 'yes' === $session->get( self::SESSION_KEY ) ) {
			$wc->customer->set_is_vat_exempt( false );
			$session->set( self::SESSION_KEY, null );
		}
	}

	/**
	 * Write to WooCommerce's log under the plugin's own source.
	 *
	 * @param string $message Message.
	 */
	private function log( $message ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		$logger = wc_get_logger();
		if ( $logger ) {
			$logger->warning( $message, array( 'source' => 'tackquote' ) );
		}
	}
}
