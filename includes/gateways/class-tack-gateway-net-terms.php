<?php
/**
 * "Net terms (TackQuote)": an offline WooCommerce payment gateway for buyers
 * TackQuote has approved to pay on account.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHAT IT DOES
 * ─────────────────────────────────────────────────────────────────────────────
 * Nothing is charged. A buyer whose TackQuote credit line is active, in the
 * cart's currency, with a limit that covers the order, may choose "pay on net
 * terms" at checkout. The order is placed ON HOLD with a note naming the terms;
 * order sync sends `payment.method = tackquote_net_terms` and TackQuote books it
 * as a terms order and invoices it. `payment_complete()` is deliberately never
 * called, because nothing was paid (WooCommerce Payment Gateway API, the Cheque
 * example: an unverifiable payment is `on-hold`; `payment_complete()` is for a
 * payment that was taken).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHEN IT IS OFFERED (FAIL CLOSED)
 * ─────────────────────────────────────────────────────────────────────────────
 * is_available() is true only when ALL of these hold:
 *   - the merchant enabled the gateway (off by default);
 *   - a customer is signed in and their email is trusted
 *     (Tack_Storefront_Forms::trusted_account_email(): a self-changed,
 *     unconfirmed address resolves nobody);
 *   - `GET /storefront/v1/net-terms` answered `standing` with an account whose
 *     status is `active`, a positive `termsDays`, a `currency` equal to the
 *     checkout currency, and remaining credit (`available`, or on an older
 *     server the whole `creditLimit`) that covers the total.
 * Any error, timeout, 404, unknown shape or missing field hides the gateway.
 *
 * REMAINING CREDIT WHEN KNOWN. A server that sends `available` (remaining
 * credit, account currency) is held to it: the total must be at most what is
 * left (`insufficient_available_credit`). An older server sends only the
 * credit LIMIT (tack `storefront-net-terms.ts` before W2-tack-orders), and then
 * an order larger than the whole line is refused (`over_limit`); whether earlier
 * open invoices leave room is TackQuote's check when it books the synced order.
 * Neither figure ever reaches the browser or the order.
 *
 * process_payment() reads the standing AGAIN, fresh, and refuses the order if
 * it no longer qualifies: the read that showed the method may be a minute old,
 * and the cart may have grown since.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * PRIVACY
 * ─────────────────────────────────────────────────────────────────────────────
 * The limit never reaches the browser: the Blocks integration receives a
 * server-computed boolean only (Tack_Net_Terms_Block). The standing read is
 * server to server with the store's secret key, like every other buyer read.
 *
 * @package TackQuotes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * The net-terms payment gateway.
 */
class Tack_Gateway_Net_Terms extends WC_Payment_Gateway {

	/** Gateway id. Also the order's `payment.method` that TackQuote keys terms orders on. */
	const ID = 'tackquote_net_terms';

	/** Order meta: {termsDays, checkedAt} recorded when the order was placed on terms. */
	const META_TERMS = '_tackquote_net_terms';

	/** Prefix of the per-customer standing transient (suffix: WordPress user id). */
	const CACHE_PREFIX = 'tack_nt_';

	/** Seconds a standing answer is reused for the same customer. */
	const CACHE_TTL = 60;

	/** Precision of a credit limit in TackQuote (`DECIMAL(14,4)`). Amounts compare in these units. */
	const MONEY_SCALE = 10000;

	/**
	 * API client.
	 *
	 * @var Tack_Api_Client
	 */
	private $client;

	/**
	 * Standing answers already read in THIS request, keyed by user id.
	 *
	 * @var array<int,array>
	 */
	private static $request_cache = array();

	/**
	 * Constructor.
	 *
	 * @param Tack_Api_Client|null $client Injected in tests; built here otherwise.
	 */
	public function __construct( $client = null ) {
		$this->client             = $client instanceof Tack_Api_Client ? $client : new Tack_Api_Client();
		$this->id                 = self::ID;
		$this->has_fields         = false;
		$this->method_title       = __( 'Net terms (TackQuote)', 'tackquote' );
		$this->method_description = __( 'Lets buyers TackQuote has approved for net terms place an order without paying now. The order is put on hold and TackQuote invoices it on the buyer\'s terms. Shown only to a signed-in buyer whose TackQuote credit line is active, in the checkout currency, with a limit that covers the order; hidden whenever TackQuote cannot confirm that.', 'tackquote' );
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = (string) $this->get_option( 'title' );
		$this->description = (string) $this->get_option( 'description' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Settings shown in WooCommerce > Settings > Payments > Net terms (TackQuote).
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'     => array(
				'title'   => __( 'Enable/Disable', 'tackquote' ),
				'type'    => 'checkbox',
				'label'   => __( 'Offer net terms to approved TackQuote buyers', 'tackquote' ),
				'default' => 'no',
			),
			'title'       => array(
				'title'       => __( 'Title', 'tackquote' ),
				'type'        => 'text',
				'description' => __( 'What the buyer sees at checkout.', 'tackquote' ),
				'default'     => __( 'Pay on net terms', 'tackquote' ),
				'desc_tip'    => true,
			),
			'description' => array(
				'title'       => __( 'Description', 'tackquote' ),
				'type'        => 'textarea',
				'description' => __( 'Shown under the title when the buyer chooses this method.', 'tackquote' ),
				'default'     => __( 'Place this order on your approved payment terms. We will send an invoice.', 'tackquote' ),
				'desc_tip'    => true,
			),
			'po_number'   => array(
				'title'   => __( 'Purchase order number', 'tackquote' ),
				'type'    => 'checkbox',
				'label'   => __( 'Ask for an optional PO number at checkout (any payment method) and send it to TackQuote with the order', 'tackquote' ),
				'default' => 'no',
			),
		);
	}

	/**
	 * Offer the method only when TackQuote confirms the buyer may use it.
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( ! parent::is_available() ) {
			return false;
		}
		// The admin gateway list and settings screens need no standing read.
		if ( is_admin() && ! wp_doing_ajax() ) {
			return false;
		}
		$decision = $this->decide( (float) $this->get_order_total(), (string) get_woocommerce_currency(), false );
		return $decision['eligible'];
	}

	/**
	 * Place the order on terms, after reading the standing again.
	 *
	 * @param int $order_id Order id.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = $this->load_order( $order_id );
		if ( ! $order ) {
			return $this->refuse( 'order_missing' );
		}
		// The order must belong to the customer whose standing is read.
		if ( (int) $order->get_customer_id() !== (int) get_current_user_id() ) {
			return $this->refuse( 'customer_mismatch' );
		}

		// FRESH: never the read that showed the method on the checkout page.
		$decision = $this->decide( (float) $order->get_total(), (string) $order->get_currency(), true );
		if ( ! $decision['eligible'] ) {
			return $this->refuse( $decision['reason'] );
		}

		$days = (int) $decision['termsDays'];
		$order->update_meta_data(
			self::META_TERMS,
			array(
				'termsDays' => $days,
				'checkedAt' => gmdate( 'c' ),
			)
		);
		$order->save();
		$order->update_status(
			'on-hold',
			/* translators: %d: net payment terms in days, for example 30. */
			sprintf( __( 'Awaiting payment on net terms (%d days).', 'tackquote' ), $days )
		);

		$this->empty_cart();

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * May the signed-in customer pay `$amount` in `$currency` on terms?
	 *
	 * @param float  $amount   Order or cart total.
	 * @param string $currency ISO 4217 code of that total.
	 * @param bool   $fresh    Skip both caches (process_payment).
	 * @return array{eligible:bool,reason:string,termsDays:int}
	 */
	public function decide( $amount, $currency, $fresh ) {
		if ( ! is_user_logged_in() ) {
			return self::verdict( 'not_logged_in' );
		}
		$email = Tack_Storefront_Forms::trusted_account_email();
		if ( '' === $email ) {
			return self::verdict( 'untrusted_email' );
		}
		$answer = $this->standing( (int) get_current_user_id(), $email, $fresh );
		if ( is_wp_error( $answer ) ) {
			$this->log( 'net-terms standing lookup failed; gateway hidden: ' . $answer->get_error_code() );
			return self::verdict( 'lookup_failed' );
		}
		return self::evaluate( $answer, $amount, $currency );
	}

	/**
	 * Judge a `NetTermsResult` against an amount. Pure; every refusal is named.
	 *
	 * @param mixed  $answer   The decoded answer.
	 * @param float  $amount   Total to place on terms.
	 * @param string $currency ISO 4217 code of that total.
	 * @return array{eligible:bool,reason:string,termsDays:int}
	 */
	public static function evaluate( $answer, $amount, $currency ) {
		if ( ! is_array( $answer ) || ! isset( $answer['status'] ) || 'standing' !== $answer['status'] ) {
			return self::verdict( 'not_standing' );
		}
		$account = isset( $answer['account'] ) && is_array( $answer['account'] ) ? $answer['account'] : null;
		if ( null === $account ) {
			return self::verdict( 'no_account' );
		}
		if ( ! isset( $account['status'] ) || 'active' !== $account['status'] ) {
			return self::verdict( 'account_inactive' );
		}
		$days = isset( $account['termsDays'] ) && is_int( $account['termsDays'] ) ? $account['termsDays'] : 0;
		if ( $days <= 0 ) {
			return self::verdict( 'no_terms_days' );
		}
		$account_currency = isset( $account['currency'] ) && is_string( $account['currency'] ) ? strtoupper( trim( $account['currency'] ) ) : '';
		if ( '' === $account_currency || strtoupper( trim( (string) $currency ) ) !== $account_currency ) {
			return self::verdict( 'currency_mismatch' );
		}
		if ( ! is_numeric( $amount ) || ! is_finite( (float) $amount ) || (float) $amount < 0 ) {
			return self::verdict( 'bad_amount' );
		}
		$amount_units = (int) round( (float) $amount * self::MONEY_SCALE );

		// Remaining credit, when the server sends it: the total must fit what is LEFT.
		$available = self::available_credit( $answer, $account );
		if ( null !== $available ) {
			if ( $amount_units > (int) round( $available * self::MONEY_SCALE ) ) {
				return self::verdict( 'insufficient_available_credit' );
			}
		} else {
			// Older server: only the whole line is known.
			if ( ! isset( $account['creditLimit'] ) || ! is_numeric( $account['creditLimit'] ) ) {
				return self::verdict( 'no_limit' );
			}
			if ( $amount_units > (int) round( (float) $account['creditLimit'] * self::MONEY_SCALE ) ) {
				return self::verdict( 'over_limit' );
			}
		}
		return array(
			'eligible'  => true,
			'reason'    => 'eligible',
			'termsDays' => $days,
		);
	}

	/**
	 * Remaining credit in the account currency, or null when the server did not send it.
	 *
	 * Read from `account.available` (beside `creditLimit`, same currency), else a
	 * top-level `available`. UNVERIFIED: the exact location is set by the tack lane
	 * adding the field (W2-tack-orders); an older server sends neither, and the
	 * gateway then compares against `creditLimit` as before. A non-numeric value
	 * counts as absent.
	 *
	 * @param array $answer  The standing answer.
	 * @param array $account Its account block.
	 * @return float|null
	 */
	private static function available_credit( array $answer, array $account ) {
		foreach ( array( $account, $answer ) as $source ) {
			if ( isset( $source['available'] ) && is_numeric( $source['available'] ) && is_finite( (float) $source['available'] ) ) {
				return (float) $source['available'];
			}
		}
		return null;
	}

	/**
	 * The customer's standing answer, cached for the request and for CACHE_TTL seconds.
	 *
	 * Only a successful answer is cached, and only for the email it was read for. An
	 * error is never cached, so the method reappears as soon as TackQuote answers.
	 *
	 * @param int    $user_id WordPress user id.
	 * @param string $email   Trusted email.
	 * @param bool   $fresh   Bypass both caches.
	 * @return array|WP_Error
	 */
	private function standing( $user_id, $email, $fresh ) {
		$key  = self::CACHE_PREFIX . $user_id;
		$hash = md5( strtolower( $email ) );
		if ( ! $fresh ) {
			if ( isset( self::$request_cache[ $user_id ] ) && $hash === self::$request_cache[ $user_id ]['hash'] ) {
				return self::$request_cache[ $user_id ]['answer'];
			}
			$cached = get_transient( $key );
			if ( is_array( $cached ) && isset( $cached['hash'], $cached['answer'] ) && $hash === $cached['hash'] && is_array( $cached['answer'] ) ) {
				self::$request_cache[ $user_id ] = $cached;
				return $cached['answer'];
			}
		}
		$answer = $this->client->get_net_terms( $email );
		if ( is_wp_error( $answer ) ) {
			unset( self::$request_cache[ $user_id ] );
			return $answer;
		}
		if ( ! is_array( $answer ) ) {
			return new WP_Error( 'tack_net_terms_shape', 'Unexpected net-terms answer.' );
		}
		$entry                           = array(
			'hash'   => $hash,
			'answer' => $answer,
		);
		self::$request_cache[ $user_id ] = $entry;
		set_transient( $key, $entry, self::CACHE_TTL );
		return $answer;
	}

	/**
	 * Forget every cached standing in this request (tests; a long-running worker).
	 */
	public static function reset_request_cache() {
		self::$request_cache = array();
	}

	/**
	 * A refusal verdict.
	 *
	 * @param string $reason Machine reason.
	 * @return array{eligible:bool,reason:string,termsDays:int}
	 */
	private static function verdict( $reason ) {
		return array(
			'eligible'  => false,
			'reason'    => $reason,
			'termsDays' => 0,
		);
	}

	/**
	 * Refuse checkout with a notice the buyer can act on.
	 *
	 * @param string $reason Machine reason, logged (no personal data).
	 * @return array
	 */
	private function refuse( $reason ) {
		$this->log( 'net-terms order refused at checkout: ' . $reason );
		$message = in_array( $reason, array( 'over_limit', 'insufficient_available_credit' ), true )
			? __( 'This order is more than the net-terms credit you have available. Please choose another payment method or contact us.', 'tackquote' )
			: __( 'Net terms are not available for this order right now. Please choose another payment method or contact us.', 'tackquote' );
		wc_add_notice( $message, 'error' );
		return array( 'result' => 'failure' );
	}

	/**
	 * The order being paid for. A seam for tests.
	 *
	 * @param int $order_id Order id.
	 * @return WC_Order|false
	 */
	protected function load_order( $order_id ) {
		return wc_get_order( $order_id );
	}

	/**
	 * Empty the cart once the order is placed (the Cheque example does the same).
	 */
	protected function empty_cart() {
		if ( function_exists( 'WC' ) && is_object( WC() ) && isset( WC()->cart ) && is_object( WC()->cart ) && method_exists( WC()->cart, 'empty_cart' ) ) {
			WC()->cart->empty_cart();
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
