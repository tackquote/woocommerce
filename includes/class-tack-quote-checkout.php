<?php
/**
 * Accepted quote to store checkout (parity row 10).
 *
 * The buyer accepts a quote in the TackQuote buyer portal and presses "Checkout
 * in the store". TackQuote mints a single-use token and sends the buyer to
 * `<store>/?tackquote_checkout=<token>`. This class:
 *
 *   1. exchanges the token SERVER-TO-SERVER (`GET /integrations/woocommerce/
 *      quote-checkout/<token>`, the store's API key) for the accepted lines,
 *      never twice for one token;
 *   2. refuses a quote in another currency than the store's;
 *   3. empties the cart and adds every line at its quoted quantity, carrying the
 *      quoted unit price and the quote reference as cart item data, all or
 *      nothing;
 *   4. prices those lines on `woocommerce_before_calculate_totals` (quote lines
 *      win over the plugin's B2B pricing, which skips them) and locks their
 *      quantities on the classic cart and the Store API;
 *   5. stores the quote reference on the order, so the order sync can send it
 *      back as `tackQuoteRef` and TackQuote settles the quote.
 *
 * Active only while the plugin holds an API key: a store that never connected
 * TackQuote cannot receive such a link, and nothing changes for it.
 *
 * Server contract: tack `woocommerce-quote-checkout.service.ts` (PR #733).
 *
 * @package TackQuotes
 * @since   1.10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns a TackQuote checkout link into a WooCommerce cart at the quoted prices.
 */
class Tack_Quote_Checkout {

	/** The query parameter TackQuote appends to the store URL. */
	const QUERY_VAR = 'tackquote_checkout';

	/** 32 random bytes, base64url: the only token shape TackQuote mints. */
	const TOKEN_PATTERN = '/^[A-Za-z0-9_-]{43}$/';

	/** The opaque checkout reference (`tqco_` + 16 random bytes, base64url). */
	const REF_PATTERN = '/^tqco_[A-Za-z0-9_-]{22}$/';

	/** A unit price as TackQuote sends it: a decimal string, at most 4 decimals. */
	const PRICE_PATTERN = '/^\d{1,12}(\.\d{1,4})?$/';

	/** Upper bound on lines read from one payload. */
	const MAX_LINES = 250;

	/** Checkout-link exchanges one visitor may make per RATE_LIMIT_WINDOW. */
	const RATE_LIMIT_MAX = 10;

	/** The exchange rate-limit window, in seconds. */
	const RATE_LIMIT_WINDOW = 600;

	/** WooCommerce session key holding the active quote checkout. */
	const SESSION_KEY = 'tackquote_quote_checkout';

	/** Cart item data keys. They make each quote line unique and survive the session. */
	const ITEM_REF      = 'tackquote_quote_ref';
	const ITEM_PRICE    = 'tackquote_unit_price';
	const ITEM_NUMBER   = 'tackquote_quote_number';
	const ITEM_QUANTITY = 'tackquote_quantity';

	/** Order meta. */
	const META_REF    = '_tackquote_quote_ref';
	const META_NUMBER = '_tackquote_quote_number';

	/**
	 * API client.
	 *
	 * @var Tack_Api_Client|null
	 */
	private $client;

	/**
	 * True while this class itself fills the cart, so the mixing guard lets it.
	 *
	 * @var bool
	 */
	private $building = false;

	/**
	 * True while this class removes quote lines, so the removal hook does not recurse.
	 *
	 * @var bool
	 */
	private $dropping = false;

	/**
	 * Where `process_token()` decided to send the buyer.
	 *
	 * @var string
	 */
	public $result_url = '';

	/**
	 * Constructor.
	 *
	 * @param Tack_Api_Client|null $client Injected in tests.
	 */
	public function __construct( $client = null ) {
		$this->client = $client instanceof Tack_Api_Client ? $client : null;
	}

	/**
	 * On only for a connected store (an API key is saved).
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$on = '' !== (string) get_option( 'tack_quotes_api_key', '' );
		/**
		 * Filters whether the plugin handles `?tackquote_checkout=` links.
		 *
		 * @since 1.10.0
		 *
		 * @param bool $on True when the store holds a TackQuote API key.
		 */
		return (bool) apply_filters( 'tackquote_quote_checkout_enabled', $on );
	}

	/**
	 * Register hooks.
	 */
	public function init() {
		// The link. Early, before any template output.
		add_action( 'template_redirect', array( $this, 'handle_link' ), 5 );

		// The money. After the B2B pricing (20), which skips quote lines anyway.
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_prices' ), 25 );

		// Locked quantities: classic cart and the Store API (Cart/Checkout blocks).
		add_filter( 'woocommerce_cart_item_quantity', array( $this, 'quantity_html' ), 20, 3 );
		add_filter( 'woocommerce_update_cart_validation', array( $this, 'refuse_quantity_update' ), 20, 4 );
		add_filter( 'woocommerce_store_api_product_quantity_editable', array( $this, 'store_api_editable' ), 20, 3 );
		add_filter( 'woocommerce_store_api_product_quantity_minimum', array( $this, 'store_api_limit' ), 20, 3 );
		add_filter( 'woocommerce_store_api_product_quantity_maximum', array( $this, 'store_api_limit' ), 20, 3 );
		add_filter( 'woocommerce_store_api_cart_item_quantity_validation', array( $this, 'store_api_validate_quantity' ), 20, 4 );

		// No other products beside an accepted quote.
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'refuse_mixing' ), 20, 2 );
		add_action( 'woocommerce_store_api_validate_add_to_cart', array( $this, 'refuse_store_api_mixing' ), 20, 2 );

		// The quote is checked out whole, or not at all.
		add_action( 'woocommerce_cart_item_removed', array( $this, 'on_item_removed' ), 20, 2 );
		add_action( 'woocommerce_check_cart_items', array( $this, 'check_cart' ), 20 );
		add_action( 'woocommerce_cart_emptied', array( $this, 'clear_session' ) );

		// The order carries the quote reference (classic, then Checkout block).
		add_action( 'woocommerce_checkout_create_order', array( $this, 'save_order_meta' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( $this, 'save_order_meta' ), 20, 1 );
		// Block checkout saves its additional fields (the PO) after update_order_meta,
		// so the quote's PO is filled in once the order is complete.
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'fill_block_po' ), 5, 1 );
	}

	// ── The link ────────────────────────────────────────────────────────────

	/**
	 * `template_redirect`: exchange the token, build the cart, go to checkout.
	 */
	public function handle_link() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the single-use token IS the credential; it is checked by TackQuote, not a WordPress form.
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) || ! $this->is_front_request() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
		$token = sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR ] ) );
		$this->process_token( $token );
		$this->no_cache();
		$this->redirect( $this->result_url );
	}

	/**
	 * Everything `handle_link()` does except the redirect. Sets `$result_url`.
	 *
	 * @param string $token Raw token from the URL.
	 * @return bool True when the cart now holds the quote.
	 */
	public function process_token( $token ) {
		$this->result_url = $this->cart_url();

		// Shape first: nothing that cannot be a token ever reaches the network.
		if ( ! is_string( $token ) || 1 !== preg_match( self::TOKEN_PATTERN, $token ) ) {
			$this->notice( __( 'This checkout link is not valid. Please open the quote again and use its checkout button.', 'tackquote' ) );
			return false;
		}

		$cart    = $this->cart();
		$session = $this->session();
		if ( ! is_object( $cart ) || ! is_object( $session ) ) {
			$this->notice( __( 'The cart is not available right now, so this quote could not be opened. Please try again from the quote.', 'tackquote' ) );
			return false;
		}

		// The same link opened again (back button, a second tab) after it was
		// spent here: the cart already holds that quote, so go on without asking.
		$active = $this->active();
		if ( is_array( $active ) && isset( $active['token'] ) && hash_equals( (string) $active['token'], hash( 'sha256', $token ) ) && $this->cart_matches( $active ) ) {
			$this->result_url = $this->checkout_url();
			return true;
		}

		/*
		 * Anyone can open `?tackquote_checkout=<43 characters>`, and each opening costs one
		 * TackQuote request holding this PHP worker. Until 1.10.0 nothing limited that, so a
		 * script could probe tokens at full speed. Checked after the shapes and the
		 * already-active shortcut above, which make no request.
		 */
		$max = self::rate_limit_max();
		if ( Tack_Rate_Limit::exceeded( 'tack_qc_', 'quote-checkout', $max ) ) {
			$this->notice( __( 'Too many checkout links were opened from this connection. Please wait a few minutes and try again.', 'tackquote' ) );
			return false;
		}
		Tack_Rate_Limit::hit( 'tack_qc_', 'quote-checkout', $max, self::RATE_LIMIT_WINDOW );

		// Exactly one exchange per token: TackQuote spends it on the first that
		// passes its checks, so a retry could only ever answer "already used".
		$result = $this->client()->exchange_quote_checkout( $token );
		if ( is_wp_error( $result ) ) {
			$this->notice( $this->exchange_error_message( $result ) );
			return false;
		}

		$payload = $this->read_payload( $result );
		if ( null === $payload ) {
			$this->log( 'quote checkout: the exchange answered a payload this plugin cannot read' );
			$this->notice( __( 'This quote could not be opened in the store. Please contact the seller.', 'tackquote' ) );
			return false;
		}

		$store_currency = strtoupper( (string) get_woocommerce_currency() );
		if ( $payload['currency'] !== $store_currency ) {
			$this->notice(
				sprintf(
					/* translators: 1: quote number, 2: the quote's currency code, 3: the store's currency code. */
					__( 'Quote %1$s is priced in %2$s but this store sells in %3$s, so it cannot be checked out here. Please contact the seller.', 'tackquote' ),
					$payload['quoteNumber'],
					$payload['currency'],
					$store_currency
				)
			);
			return false;
		}

		return $this->build_cart( $payload, hash( 'sha256', $token ) );
	}

	/**
	 * Checkout-link exchanges allowed per visitor per window.
	 *
	 * @since 1.10.0
	 *
	 * @return int Zero or less disables the limit.
	 */
	private static function rate_limit_max() {
		/**
		 * Filters how many TackQuote checkout links one visitor may open per ten minutes.
		 *
		 * @since 1.10.0
		 *
		 * @param int $max Maximum exchanges. Zero or less disables the limit.
		 */
		return (int) apply_filters( 'tack_quotes_checkout_rate_limit_max', self::RATE_LIMIT_MAX );
	}

	/**
	 * Empty the cart and add every quote line; all or nothing.
	 *
	 * @param array  $payload    Validated payload.
	 * @param string $token_hash SHA-256 of the spent token.
	 * @return bool
	 */
	private function build_cart( array $payload, $token_hash ) {
		$cart    = $this->cart();
		$session = $this->session();

		$cart->empty_cart();

		// Recorded BEFORE the lines are added: quote-only products are
		// purchasable for the lines of this quote (see holds_product()).
		$products = array();
		foreach ( $payload['lines'] as $line ) {
			$products[] = $line['wooProductId'];
			if ( $line['wooVariationId'] > 0 ) {
				$products[] = $line['wooVariationId'];
			}
		}
		$state = array(
			'ref'      => $payload['quoteRef'],
			'number'   => $payload['quoteNumber'],
			'po'       => $payload['poNumber'],
			'token'    => $token_hash,
			'products' => array_values( array_unique( $products ) ),
			'lines'    => array(),
		);
		$session->set( self::SESSION_KEY, $state );

		$failed         = array();
		$this->building = true;
		foreach ( $payload['lines'] as $line ) {
			$key = $this->add_line( $payload, $line );
			if ( '' === $key ) {
				$failed[] = $line['name'];
				continue;
			}
			$state['lines'][ $key ] = $line['quantity'];
		}
		$this->building = false;

		if ( ! empty( $failed ) ) {
			// Never a partial quote: the buyer would pay for less than they accepted.
			$cart->empty_cart();
			$session->set( self::SESSION_KEY, null );
			$this->notice(
				sprintf(
					/* translators: 1: quote number, 2: comma-separated product names. */
					__( 'Quote %1$s could not be checked out because these products are not available to buy in this store right now: %2$s. Please contact the seller.', 'tackquote' ),
					$payload['quoteNumber'],
					implode( ', ', $failed )
				)
			);
			return false;
		}

		$session->set( self::SESSION_KEY, $state );
		if ( method_exists( $session, 'has_session' ) && ! $session->has_session() && method_exists( $session, 'set_customer_session_cookie' ) ) {
			// A guest arriving from the portal has no cart cookie yet; without one the
			// cart built here would be gone on the next request.
			$session->set_customer_session_cookie( true );
		}
		$this->prefill_email( $payload['buyerEmail'] );
		$this->result_url = $this->checkout_url();
		return true;
	}

	/**
	 * Add one quote line. Returns the cart item key, or '' when it cannot be bought.
	 *
	 * @param array $payload Payload.
	 * @param array $line    One validated line.
	 * @return string
	 */
	private function add_line( array $payload, array $line ) {
		$product_id   = $line['wooProductId'];
		$variation_id = $line['wooVariationId'];
		$attributes   = array();

		$product = $this->product( $product_id );
		if ( ! is_object( $product ) ) {
			return '';
		}
		$buy = $product;
		if ( $variation_id > 0 ) {
			$buy = $this->product( $variation_id );
			if ( ! is_object( $buy ) || ! method_exists( $buy, 'get_parent_id' ) || (int) $buy->get_parent_id() !== $product_id ) {
				return '';
			}
			$attributes = $this->variation_attributes( $variation_id );
			if ( null === $attributes ) {
				// An "any value" attribute the quote did not record: WooCommerce
				// would ask the buyer to choose, and the choice is not ours to make.
				return '';
			}
		}
		if ( ! method_exists( $buy, 'is_purchasable' ) || ! $buy->is_purchasable() ) {
			return '';
		}
		if ( method_exists( $buy, 'has_enough_stock' ) && method_exists( $buy, 'managing_stock' ) && $buy->managing_stock() && ! $buy->has_enough_stock( $line['quantity'] ) ) {
			return '';
		}

		$key = $this->cart()->add_to_cart(
			$product_id,
			$line['quantity'],
			$variation_id,
			$attributes,
			array(
				self::ITEM_REF      => $payload['quoteRef'],
				self::ITEM_PRICE    => $line['unitPrice'],
				self::ITEM_NUMBER   => $payload['quoteNumber'],
				self::ITEM_QUANTITY => $line['quantity'],
			)
		);
		if ( ! is_string( $key ) || '' === $key ) {
			return '';
		}
		// A filter on the added quantity must not change what was accepted.
		$contents = $this->cart()->get_cart();
		if ( ! isset( $contents[ $key ]['quantity'] ) || (int) $contents[ $key ]['quantity'] !== $line['quantity'] ) {
			$this->cart()->remove_cart_item( $key );
			return '';
		}
		return $key;
	}

	/**
	 * The exchange answer, validated, or null.
	 *
	 * @param mixed $data Decoded JSON.
	 * @return array|null
	 */
	public function read_payload( $data ) {
		if ( ! is_array( $data ) ) {
			return null;
		}
		$ref      = isset( $data['quoteRef'] ) && is_string( $data['quoteRef'] ) ? $data['quoteRef'] : '';
		$number   = isset( $data['quoteNumber'] ) && is_scalar( $data['quoteNumber'] ) ? sanitize_text_field( (string) $data['quoteNumber'] ) : '';
		$currency = isset( $data['currency'] ) && is_string( $data['currency'] ) ? strtoupper( $data['currency'] ) : '';
		$lines    = isset( $data['lines'] ) && is_array( $data['lines'] ) ? $data['lines'] : array();
		if ( 1 !== preg_match( self::REF_PATTERN, $ref ) || '' === $number || 1 !== preg_match( '/^[A-Z]{3}$/', $currency ) ) {
			return null;
		}
		if ( empty( $lines ) || count( $lines ) > self::MAX_LINES ) {
			return null;
		}

		$out = array();
		foreach ( $lines as $line ) {
			if ( ! is_array( $line ) ) {
				return null;
			}
			$product_id   = isset( $line['wooProductId'] ) && is_int( $line['wooProductId'] ) ? $line['wooProductId'] : 0;
			$variation_id = isset( $line['wooVariationId'] ) && is_int( $line['wooVariationId'] ) ? $line['wooVariationId'] : 0;
			$quantity     = isset( $line['quantity'] ) && is_int( $line['quantity'] ) ? $line['quantity'] : 0;
			$price        = isset( $line['unitPrice'] ) && is_string( $line['unitPrice'] ) ? $line['unitPrice'] : '';
			if ( $product_id < 1 || $variation_id < 0 || $quantity < 1 || 1 !== preg_match( self::PRICE_PATTERN, $price ) ) {
				return null;
			}
			$name  = isset( $line['name'] ) && is_scalar( $line['name'] ) ? sanitize_text_field( (string) $line['name'] ) : '';
			$out[] = array(
				'wooProductId'   => $product_id,
				'wooVariationId' => $variation_id,
				'quantity'       => $quantity,
				'unitPrice'      => $price,
				'name'           => '' !== $name ? $name : '#' . $product_id,
			);
		}

		$email = isset( $data['buyerEmail'] ) && is_string( $data['buyerEmail'] ) ? sanitize_email( $data['buyerEmail'] ) : '';
		$po    = isset( $data['poNumber'] ) && is_scalar( $data['poNumber'] ) ? Tack_Po_Number::clean( (string) $data['poNumber'] ) : '';
		return array(
			'quoteRef'    => $ref,
			'quoteNumber' => $number,
			'currency'    => $currency,
			'buyerEmail'  => is_email( $email ) ? $email : '',
			'poNumber'    => $po,
			'lines'       => $out,
		);
	}

	/**
	 * A buyer-facing sentence for a failed exchange. Never the server's own text.
	 *
	 * @param WP_Error $error Failure.
	 * @return string
	 */
	private function exchange_error_message( $error ) {
		$data   = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
		if ( 410 === $status ) {
			return __( 'This checkout link was already used or has expired. Please open the quote again to get a new link.', 'tackquote' );
		}
		if ( 404 === $status ) {
			return __( 'This checkout link is not valid. Please open the quote again and use its checkout button.', 'tackquote' );
		}
		$this->log( 'quote checkout: exchange failed (HTTP ' . $status . '): ' . $error->get_error_message() );
		return __( 'This quote could not be opened right now. Please open the quote again and use its checkout button.', 'tackquote' );
	}

	// ── Pricing ─────────────────────────────────────────────────────────────

	/**
	 * Is this cart line part of an accepted quote?
	 *
	 * @param mixed $item Cart item array.
	 * @return bool
	 */
	public static function is_quote_item( $item ) {
		return is_array( $item ) && isset( $item[ self::ITEM_REF ], $item[ self::ITEM_PRICE ] )
			&& is_string( $item[ self::ITEM_REF ] ) && '' !== $item[ self::ITEM_REF ];
	}

	/**
	 * `woocommerce_before_calculate_totals`: every quote line at its quoted unit price.
	 *
	 * @param object $cart WC_Cart.
	 */
	public function apply_prices( $cart ) {
		if ( ! is_object( $cart ) || ! method_exists( $cart, 'get_cart' ) ) {
			return;
		}
		foreach ( $cart->get_cart() as $item ) {
			if ( ! self::is_quote_item( $item ) || empty( $item['data'] ) || ! method_exists( $item['data'], 'set_price' ) ) {
				continue;
			}
			$quantity = isset( $item['quantity'] ) ? (int) $item['quantity'] : 1;
			$item['data']->set_price( self::store_basis_price( (string) $item[ self::ITEM_PRICE ], $item['data'], $quantity ) );
		}
	}

	/**
	 * The quoted NET unit price in the basis this store enters prices in.
	 *
	 * `Tack_Tax_Basis::entry_price()`, shared with the B2B price path so the two
	 * cannot drift: on a store that enters prices inclusive of tax the net line is
	 * grossed up by exactly the rates the cart takes back out, unrounded.
	 *
	 * @param string $net      Net unit price, decimal string.
	 * @param object $product  WC_Product (tax status and class).
	 * @param int    $quantity Line quantity.
	 * @return string|float
	 */
	public static function store_basis_price( $net, $product, $quantity ) {
		return Tack_Tax_Basis::entry_price( $net, $product, $quantity );
	}

	// ── Locked quantities ───────────────────────────────────────────────────

	/**
	 * Classic cart: the quantity of a quote line as plain text (no input, so a
	 * cart update never carries a new value for it).
	 *
	 * @param string $html          Quantity input markup.
	 * @param string $cart_item_key Cart item key.
	 * @param array  $cart_item     Cart item.
	 * @return string
	 */
	public function quantity_html( $html, $cart_item_key = '', $cart_item = array() ) {
		unset( $cart_item_key );
		if ( ! self::is_quote_item( $cart_item ) ) {
			return $html;
		}
		$quantity = isset( $cart_item['quantity'] ) ? (int) $cart_item['quantity'] : 0;
		return '<span class="tackquote-quote-quantity" title="' . esc_attr__( 'Quantity set by your accepted quote', 'tackquote' ) . '">' . esc_html( (string) $quantity ) . '</span>';
	}

	/**
	 * Classic cart update: refuse a new quantity for a quote line.
	 *
	 * @param bool   $passed        Validation so far.
	 * @param string $cart_item_key Key.
	 * @param array  $values        Cart item.
	 * @param int    $quantity      Requested quantity.
	 * @return bool
	 */
	public function refuse_quantity_update( $passed, $cart_item_key = '', $values = array(), $quantity = 0 ) {
		unset( $cart_item_key );
		if ( ! self::is_quote_item( $values ) ) {
			return $passed;
		}
		if ( self::locked_quantity( $values ) === (int) $quantity ) {
			return $passed;
		}
		$this->notice( __( 'The quantities of an accepted quote cannot be changed in the store. Please ask the seller to revise the quote.', 'tackquote' ) );
		return false;
	}

	/**
	 * Store API: a quote line's quantity is not editable.
	 *
	 * @param bool       $editable  Value so far.
	 * @param object     $product   Product.
	 * @param array|null $cart_item Cart item.
	 * @return bool
	 */
	public function store_api_editable( $editable, $product = null, $cart_item = null ) {
		unset( $product );
		return self::is_quote_item( $cart_item ) ? false : $editable;
	}

	/**
	 * Store API: minimum and maximum of a quote line are both its quantity.
	 *
	 * @param int|float  $value     Value so far.
	 * @param object     $product   Product.
	 * @param array|null $cart_item Cart item.
	 * @return int|float
	 */
	public function store_api_limit( $value, $product = null, $cart_item = null ) {
		unset( $product );
		return self::is_quote_item( $cart_item ) ? self::locked_quantity( $cart_item ) : $value;
	}

	/**
	 * Store API (WooCommerce 11.2+): refuse any other quantity for a quote line.
	 *
	 * @param true|WP_Error $valid     Value so far.
	 * @param int|float     $quantity  Requested quantity.
	 * @param object        $product   Product.
	 * @param array         $cart_item Cart item.
	 * @return true|WP_Error
	 */
	public function store_api_validate_quantity( $valid, $quantity = 0, $product = null, $cart_item = array() ) {
		unset( $product );
		if ( ! self::is_quote_item( $cart_item ) || self::locked_quantity( $cart_item ) === (int) $quantity ) {
			return $valid;
		}
		return new WP_Error(
			'tackquote_quote_quantity_locked',
			__( 'The quantities of an accepted quote cannot be changed in the store. Please ask the seller to revise the quote.', 'tackquote' )
		);
	}

	/**
	 * The quantity the quote accepted for a line.
	 *
	 * @param array $item Cart item.
	 * @return int
	 */
	private static function locked_quantity( $item ) {
		if ( isset( $item[ self::ITEM_QUANTITY ] ) && (int) $item[ self::ITEM_QUANTITY ] > 0 ) {
			return (int) $item[ self::ITEM_QUANTITY ];
		}
		return isset( $item['quantity'] ) ? (int) $item['quantity'] : 0;
	}

	// ── Mixing, removal, integrity ──────────────────────────────────────────

	/**
	 * Does the cart hold quote lines?
	 *
	 * @return bool
	 */
	private function cart_has_quote() {
		$cart = $this->cart();
		if ( ! is_object( $cart ) || ! method_exists( $cart, 'get_cart' ) ) {
			return false;
		}
		foreach ( $cart->get_cart() as $item ) {
			if ( self::is_quote_item( $item ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Classic add to cart: refuse another product beside an accepted quote.
	 *
	 * @param bool $passed     Validation so far.
	 * @param int  $product_id Product being added.
	 * @return bool
	 */
	public function refuse_mixing( $passed, $product_id = 0 ) {
		unset( $product_id );
		if ( $this->building || ! $this->cart_has_quote() ) {
			return $passed;
		}
		$this->notice( self::mixing_message() );
		return false;
	}

	/**
	 * Store API add to cart: the same refusal, thrown as the hook documents.
	 *
	 * @param object $product Product.
	 * @param array  $request Request.
	 * @throws Exception When the cart holds an accepted quote.
	 */
	public function refuse_store_api_mixing( $product = null, $request = array() ) {
		unset( $product, $request );
		if ( ! $this->building && $this->cart_has_quote() ) {
			throw new Exception( esc_html( self::mixing_message() ) );
		}
	}

	/**
	 * The mixing refusal.
	 *
	 * @return string
	 */
	private static function mixing_message() {
		return __( 'Your cart holds an accepted quote, which is checked out on its own. Complete or remove it before adding other products.', 'tackquote' );
	}

	/**
	 * One quote line removed: the quote leaves the cart whole.
	 *
	 * @param string $cart_item_key Removed key.
	 * @param object $cart          WC_Cart.
	 */
	public function on_item_removed( $cart_item_key, $cart = null ) {
		if ( $this->dropping || $this->building || ! is_object( $cart ) ) {
			return;
		}
		$removed = isset( $cart->removed_cart_contents[ $cart_item_key ] ) ? $cart->removed_cart_contents[ $cart_item_key ] : null;
		if ( ! self::is_quote_item( $removed ) ) {
			return;
		}
		$this->drop_quote_lines( $cart );
		$this->notice( __( 'The accepted quote was removed from your cart. Open the quote again to check it out.', 'tackquote' ), 'notice' );
	}

	/**
	 * `woocommerce_check_cart_items` (cart, checkout, place order): the cart
	 * holds exactly the quote this session opened, at its quantities, or none of it.
	 */
	public function check_cart() {
		$cart = $this->cart();
		if ( ! is_object( $cart ) || ! $this->cart_has_quote() ) {
			return;
		}
		$active = $this->active();
		if ( ! is_array( $active ) || ! $this->cart_matches( $active ) ) {
			$this->drop_quote_lines( $cart );
			$this->notice( __( 'Your cart no longer matched the accepted quote, so the quote was removed. Open the quote again to check it out.', 'tackquote' ) );
			return;
		}
		foreach ( $cart->get_cart() as $item ) {
			if ( ! self::is_quote_item( $item ) ) {
				$this->notice( self::mixing_message() );
				return;
			}
		}
	}

	/**
	 * Do the quote lines in the cart equal the session's record?
	 *
	 * @param array $active Session state.
	 * @return bool
	 */
	private function cart_matches( array $active ) {
		$cart  = $this->cart();
		$lines = isset( $active['lines'] ) && is_array( $active['lines'] ) ? $active['lines'] : array();
		if ( ! is_object( $cart ) || empty( $lines ) ) {
			return false;
		}
		$seen = array();
		foreach ( $cart->get_cart() as $key => $item ) {
			if ( ! self::is_quote_item( $item ) ) {
				continue;
			}
			$active_ref = isset( $active['ref'] ) ? (string) $active['ref'] : '';
			if ( $active_ref !== $item[ self::ITEM_REF ] || ! isset( $lines[ $key ] ) ) {
				return false;
			}
			if ( (int) ( $item['quantity'] ?? 0 ) !== (int) $lines[ $key ] ) {
				return false;
			}
			$seen[ $key ] = true;
		}
		return count( $seen ) === count( $lines );
	}

	/**
	 * Remove every quote line and forget the quote.
	 *
	 * @param object $cart WC_Cart.
	 */
	private function drop_quote_lines( $cart ) {
		$this->dropping = true;
		foreach ( $cart->get_cart() as $key => $item ) {
			if ( self::is_quote_item( $item ) ) {
				$cart->remove_cart_item( $key );
			}
		}
		$this->dropping = false;
		$this->clear_session();
	}

	/**
	 * Forget the active quote (cart emptied: after a paid order, or by the buyer).
	 */
	public function clear_session() {
		$session = $this->session();
		if ( is_object( $session ) ) {
			$session->set( self::SESSION_KEY, null );
		}
	}

	/**
	 * The session's active quote checkout, or null.
	 *
	 * @return array|null
	 */
	private function active() {
		$session = $this->session();
		if ( ! is_object( $session ) ) {
			return null;
		}
		$state = $session->get( self::SESSION_KEY );
		return is_array( $state ) && isset( $state['ref'] ) && 1 === preg_match( self::REF_PATTERN, (string) $state['ref'] ) ? $state : null;
	}

	/**
	 * Is this product one of the active quote's? Lets quote-only (catalogue
	 * mode) products be bought through their accepted quote, and nothing else.
	 *
	 * @param object $product WC_Product.
	 * @return bool
	 */
	public static function holds_product( $product ) {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) || ! self::is_enabled() ) {
			return false;
		}
		if ( ! function_exists( 'WC' ) ) {
			return false;
		}
		$wc = WC();
		if ( ! is_object( $wc ) || empty( $wc->session ) || ! is_object( $wc->session ) ) {
			return false;
		}
		$state = $wc->session->get( self::SESSION_KEY );
		if ( ! is_array( $state ) || empty( $state['products'] ) || ! is_array( $state['products'] ) ) {
			return false;
		}
		return in_array( (int) $product->get_id(), array_map( 'intval', $state['products'] ), true );
	}

	// ── The order ───────────────────────────────────────────────────────────

	/**
	 * Store the quote reference (and number) on the order being created, and
	 * the quote's PO when the buyer typed none (classic checkout, where the PO
	 * field saved at priority 10).
	 *
	 * Through the order CRUD only, so it is HPOS-safe; WooCommerce saves the order.
	 *
	 * @param WC_Order $order Order.
	 */
	public function save_order_meta( $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'update_meta_data' ) ) {
			return;
		}
		$active = $this->active();
		if ( ! is_array( $active ) || ! $this->cart_matches( $active ) ) {
			return;
		}
		$order->update_meta_data( self::META_REF, (string) $active['ref'] );
		$order->update_meta_data( self::META_NUMBER, (string) ( $active['number'] ?? '' ) );
		$this->fill_po( $order, $active );
	}

	/**
	 * Checkout block: the quote's PO when the buyer typed none, once the
	 * block's own fields are saved.
	 *
	 * @param WC_Order $order Order.
	 */
	public function fill_block_po( $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
			return;
		}
		$active = $this->active();
		if ( ! is_array( $active ) || (string) $order->get_meta( self::META_REF, true ) !== (string) $active['ref'] ) {
			return;
		}
		if ( $this->fill_po( $order, $active ) && method_exists( $order, 'save' ) ) {
			$order->save();
		}
	}

	/**
	 * Copy the quote's PO into `_tackquote_po_number` unless one is there.
	 *
	 * @param WC_Order $order  Order.
	 * @param array    $active Session state.
	 * @return bool Whether a value was written.
	 */
	private function fill_po( $order, array $active ) {
		$po = isset( $active['po'] ) ? (string) $active['po'] : '';
		if ( '' === $po || '' !== trim( (string) $order->get_meta( Tack_Po_Number::META_KEY, true ) ) ) {
			return false;
		}
		$order->update_meta_data( Tack_Po_Number::META_KEY, $po );
		return true;
	}

	/**
	 * The `tackQuoteRef` an order sync sends: the stored reference, or ''.
	 *
	 * Sent ONLY when set: TackQuote validates the order body with
	 * `forbidNonWhitelisted`, and only a server that minted the link (and so
	 * knows the field) can have caused it to be set.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function order_ref( $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
			return '';
		}
		$ref = $order->get_meta( self::META_REF, true );
		return is_string( $ref ) && 1 === preg_match( self::REF_PATTERN, $ref ) ? $ref : '';
	}

	// ── Seams (overridden in tests) ─────────────────────────────────────────

	/**
	 * API client.
	 *
	 * @return Tack_Api_Client
	 */
	protected function client() {
		if ( null === $this->client ) {
			$this->client = new Tack_Api_Client();
		}
		return $this->client;
	}

	/**
	 * WC()->cart.
	 *
	 * @return object|null
	 */
	protected function cart() {
		return function_exists( 'WC' ) && is_object( WC() ) && isset( WC()->cart ) ? WC()->cart : null;
	}

	/**
	 * WC()->session.
	 *
	 * @return object|null
	 */
	protected function session() {
		return function_exists( 'WC' ) && is_object( WC() ) && isset( WC()->session ) ? WC()->session : null;
	}

	/**
	 * A product by id.
	 *
	 * @param int $id Id.
	 * @return object|null
	 */
	protected function product( $id ) {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;
		return is_object( $product ) ? $product : null;
	}

	/**
	 * A variation's attributes, or null when one of them is "any value".
	 *
	 * @param int $variation_id Variation id.
	 * @return array|null
	 */
	protected function variation_attributes( $variation_id ) {
		$attributes = function_exists( 'wc_get_product_variation_attributes' ) ? wc_get_product_variation_attributes( $variation_id ) : array();
		foreach ( (array) $attributes as $value ) {
			if ( '' === (string) $value ) {
				return null;
			}
		}
		return (array) $attributes;
	}

	/**
	 * Front end, not admin, AJAX, REST or cron.
	 *
	 * @return bool
	 */
	protected function is_front_request() {
		if ( is_admin() || wp_doing_ajax() || ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) ) {
			return false;
		}
		return ! ( defined( 'REST_REQUEST' ) && REST_REQUEST );
	}

	/**
	 * The redirect never lands in a page cache.
	 */
	protected function no_cache() {
		if ( function_exists( 'nocache_headers' ) ) {
			nocache_headers();
		}
	}

	/**
	 * Redirect and stop.
	 *
	 * @param string $url Target (cart or checkout, never carrying the token).
	 */
	protected function redirect( $url ) {
		wp_safe_redirect( $url, 302 );
		exit;
	}

	/**
	 * Cart page URL.
	 *
	 * @return string
	 */
	protected function cart_url() {
		return function_exists( 'wc_get_cart_url' ) ? (string) wc_get_cart_url() : home_url( '/' );
	}

	/**
	 * Checkout page URL.
	 *
	 * @return string
	 */
	protected function checkout_url() {
		return function_exists( 'wc_get_checkout_url' ) ? (string) wc_get_checkout_url() : home_url( '/' );
	}

	/**
	 * Prefill the checkout email for a buyer who has none yet.
	 *
	 * @param string $email Buyer email from the quote.
	 */
	protected function prefill_email( $email ) {
		if ( '' === $email || ! function_exists( 'WC' ) || ! is_object( WC() ) || empty( WC()->customer ) ) {
			return;
		}
		$customer = WC()->customer;
		if ( method_exists( $customer, 'get_billing_email' ) && method_exists( $customer, 'set_billing_email' ) && '' === (string) $customer->get_billing_email() ) {
			$customer->set_billing_email( $email );
		}
	}

	/**
	 * A storefront notice.
	 *
	 * @param string $message Plain text.
	 * @param string $type    error|notice.
	 */
	protected function notice( $message, $type = 'error' ) {
		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( esc_html( $message ), $type );
		}
	}

	/**
	 * Log under the plugin's source. Never the token or the buyer's email.
	 *
	 * @param string $message Message.
	 */
	private function log( $message ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			$logger = wc_get_logger();
			if ( $logger ) {
				$logger->warning( $message, array( 'source' => 'tackquote' ) );
			}
		}
	}
}
