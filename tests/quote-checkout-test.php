<?php
/**
 * Tests for Tack_Quote_Checkout: accepted quote -> store checkout (parity row 10).
 *
 * Money moves through this path, so most checks are refusals: a token that is
 * not a token never reaches the network, a spent token is never exchanged
 * twice, a quote in another currency or with an unbuyable line leaves the cart
 * EMPTY, the quoted price beats the B2B price for quote lines only, and the
 * order names its quote only when a link built it.
 *
 * Run: php tests/run.php
 *
 * @package TackQuotes
 */

require_once TACK_QUOTES_DIR . 'includes/class-tack-po-number.php';
require_once TACK_QUOTES_DIR . 'includes/class-tack-quote-checkout.php';

/** API client recording every request and answering a script. */
class Tack_Test_QC_Client extends Tack_Api_Client {
	/** @var mixed */
	public $response;
	/** @var array */
	public $paths = array();

	/** @param mixed $response Scripted answer (array or WP_Error). */
	public function __construct( $response ) {
		$this->response = $response;
	}

	/**
	 * @param string $method  Method.
	 * @param string $path    Path.
	 * @param mixed  $body    Body.
	 * @param mixed  $timeout Timeout.
	 * @param array  $headers Headers.
	 * @return mixed
	 */
	public function request( $method, $path, $body = null, $timeout = null, $headers = array() ) {
		$this->paths[] = $method . ' ' . $path;
		return $this->response;
	}
}

/** A product as add_line() reads it. */
class Tack_Test_QC_Product {
	/** @var int */
	public $id;
	/** @var int */
	public $parent = 0;
	/** @var bool */
	public $purchasable = true;
	/** @var string */
	public $sku;
	/** @var mixed */
	public $price = '';

	/**
	 * @param int    $id  Id.
	 * @param string $sku SKU.
	 */
	public function __construct( $id, $sku = '' ) {
		$this->id  = $id;
		$this->sku = '' !== $sku ? $sku : 'SKU-' . $id;
	}
	/** @return int */
	public function get_id() {
		return $this->id;
	}
	/** @return int */
	public function get_parent_id() {
		return $this->parent;
	}
	/** @return bool */
	public function is_purchasable() {
		return $this->purchasable;
	}
	/** @return string */
	public function get_sku() {
		return $this->sku;
	}
	/** @return mixed */
	public function get_price() {
		return $this->price;
	}
	/** @param mixed $price Price. */
	public function set_price( $price ) {
		$this->price = $price;
	}
	/** @return string */
	public function get_name() {
		return 'Product ' . $this->id;
	}
	/** @return bool */
	public function is_taxable() {
		return true;
	}
	/**
	 * @param string $context Context.
	 * @return string
	 */
	public function get_tax_class( $context = 'view' ) {
		unset( $context );
		return '';
	}
}

if ( ! class_exists( 'WC_Tax' ) ) {
	/** WooCommerce's tax maths for ONE base rate, as WC_Tax::calc_tax() computes it (unrounded). */
	class WC_Tax {
		/**
		 * @param string $tax_class Class.
		 * @return array
		 */
		public static function get_base_tax_rates( $tax_class = '' ) {
			unset( $tax_class );
			return array( 1 => array( 'rate' => 20.0, 'compound' => 'no' ) );
		}
		/**
		 * @param float $price     Price.
		 * @param array $rates     Rates.
		 * @param bool  $inclusive Whether $price includes the tax.
		 * @return array
		 */
		public static function calc_tax( $price, $rates, $inclusive = false ) {
			$out = array();
			foreach ( $rates as $id => $rate ) {
				$r          = $rate['rate'] / 100;
				$out[ $id ] = $inclusive ? $price - $price / ( 1 + $r ) : $price * $r;
			}
			return $out;
		}
	}
}

/** A WC_Cart with the calls the class makes. */
class Tack_Test_QC_Cart {
	/** @var array */
	public $contents = array();
	/** @var array */
	public $removed_cart_contents = array();
	/** @var int */
	public $emptied = 0;
	/** @var array */
	public $adds = array();
	/** @var Tack_Test_QC_Checkout|null */
	public $owner;

	/** @return array */
	public function get_cart() {
		return $this->contents;
	}
	/** Empty it, firing woocommerce_cart_emptied the way WC_Cart::empty_cart() does. */
	public function empty_cart() {
		$this->contents = array();
		++$this->emptied;
		if ( $this->owner ) {
			$this->owner->clear_session();
		}
	}
	/**
	 * WC_Cart::add_to_cart() signature.
	 *
	 * @param int   $product_id   Product.
	 * @param int   $quantity     Quantity.
	 * @param int   $variation_id Variation.
	 * @param array $variation    Attributes.
	 * @param array $data         Cart item data.
	 * @return string|false
	 */
	public function add_to_cart( $product_id, $quantity = 1, $variation_id = 0, $variation = array(), $data = array() ) {
		$this->adds[] = func_get_args();
		$key          = md5( $product_id . '|' . $variation_id . '|' . wp_json_encode( $data ) );
		$this->contents[ $key ] = array_merge(
			$data,
			array(
				'product_id'   => $product_id,
				'variation_id' => $variation_id,
				'variation'    => $variation,
				'quantity'     => $quantity,
				'data'         => new Tack_Test_QC_Product( $variation_id ? $variation_id : $product_id ),
			)
		);
		return $key;
	}
	/**
	 * @param string $key Key.
	 * @return bool
	 */
	public function remove_cart_item( $key ) {
		$this->removed_cart_contents[ $key ] = $this->contents[ $key ];
		unset( $this->contents[ $key ] );
		if ( $this->owner ) {
			$this->owner->on_item_removed( $key, $this );
		}
		return true;
	}
}

/** The class with its WooCommerce seams pointed at the fakes above. */
class Tack_Test_QC_Checkout extends Tack_Quote_Checkout {
	/** @var Tack_Test_QC_Cart */
	public $fake_cart;
	/** @var Tack_Stub_Session */
	public $fake_session;
	/** @var array<int,Tack_Test_QC_Product> */
	public $products = array();
	/** @var array<int,array|null> */
	public $attributes = array();
	/** @var string|null */
	public $redirected = null;
	/** @var bool */
	public $nocache = false;

	/** @param Tack_Api_Client $client Client. */
	public function __construct( $client ) {
		parent::__construct( $client );
		$this->fake_cart        = new Tack_Test_QC_Cart();
		$this->fake_cart->owner = $this;
		$this->fake_session     = new Tack_Stub_Session();
	}
	/** @return object */
	protected function cart() {
		return $this->fake_cart;
	}
	/** @return object */
	protected function session() {
		return $this->fake_session;
	}
	/**
	 * @param int $id Id.
	 * @return object|null
	 */
	protected function product( $id ) {
		return isset( $this->products[ $id ] ) ? $this->products[ $id ] : null;
	}
	/**
	 * @param int $id Id.
	 * @return array|null
	 */
	protected function variation_attributes( $id ) {
		return array_key_exists( $id, $this->attributes ) ? $this->attributes[ $id ] : array( 'attribute_pa_size' => 'large' );
	}
	/** @return bool */
	protected function is_front_request() {
		return true;
	}
	/** No headers in a CLI test. */
	protected function no_cache() {
		$this->nocache = true;
	}
	/** @param string $url URL. */
	protected function redirect( $url ) {
		$this->redirected = $url;
	}
	/** @return string */
	protected function cart_url() {
		return 'https://shop.example/cart/';
	}
	/** @return string */
	protected function checkout_url() {
		return 'https://shop.example/checkout/';
	}
	/** @param string $email Email. */
	protected function prefill_email( $email ) {
		unset( $email );
	}
	/** @return array Requests the client was asked to make. */
	public function client_paths() {
		return $this->client()->paths;
	}
}

/** A valid 43-character token. */
const TACK_QC_TOKEN = 'AbCdEfGhIjKlMnOpQrStUvWxYz0123456789_-AbCdE';
/** A valid checkout reference. */
const TACK_QC_REF = 'tqco_AbCdEfGhIjKlMnOpQrStUv';

/**
 * A 200 payload with two lines: a simple product and a variation.
 *
 * @return array
 */
function tack_qc_payload() {
	return array(
		'quoteRef'    => TACK_QC_REF,
		'quoteNumber' => 'TK-2026-000123',
		'currency'    => 'USD',
		'buyerEmail'  => 'buyer@example.test',
		'poNumber'    => 'PO-777',
		'goodsTotal'  => '400.00',
		'lines'       => array(
			array(
				'wooProductId' => 11,
				'sku'          => 'WIDGET',
				'name'         => 'Widget',
				'quantity'     => 3,
				'unitPrice'    => '33.3333',
				'lineTotal'    => '100.00',
			),
			array(
				'wooProductId'   => 20,
				'wooVariationId' => 21,
				'sku'            => 'SHIRT-L',
				'name'           => 'Shirt - Large',
				'quantity'       => 10,
				'unitPrice'      => '30.00',
				'lineTotal'      => '300.00',
			),
		),
	);
}

/**
 * A checkout object with the payload's products in the catalogue.
 *
 * @param mixed $response Client answer.
 * @return Tack_Test_QC_Checkout
 */
function tack_qc_make( $response ) {
	$qc                 = new Tack_Test_QC_Checkout( new Tack_Test_QC_Client( $response ) );
	$qc->products[11]   = new Tack_Test_QC_Product( 11, 'WIDGET' );
	$qc->products[20]   = new Tack_Test_QC_Product( 20, 'SHIRT' );
	$variation          = new Tack_Test_QC_Product( 21, 'SHIRT-L' );
	$variation->parent  = 20;
	$qc->products[21]   = $variation;
	return $qc;
}

/** @return string Every notice joined. */
function tack_qc_notices() {
	return implode( ' | ', tack_test_notices() );
}

tack_test_set_option( 'tack_quotes_api_key', 'tk_test_key' );
$GLOBALS['TACK_STORE_CURRENCY'] = 'USD';

// ── Registration ────────────────────────────────────────────────────────────
$GLOBALS['TACK_HOOKS'] = array();
( new Tack_Quote_Checkout( new Tack_Test_QC_Client( array() ) ) )->init();
$qc_hooks = array();
foreach ( $GLOBALS['TACK_HOOKS'] as $h ) {
	$qc_hooks[ $h['hook'] ] = $h['priority'];
}
foreach ( array( 'template_redirect', 'woocommerce_before_calculate_totals', 'woocommerce_cart_item_quantity', 'woocommerce_update_cart_validation', 'woocommerce_store_api_product_quantity_editable', 'woocommerce_store_api_cart_item_quantity_validation', 'woocommerce_add_to_cart_validation', 'woocommerce_store_api_validate_add_to_cart', 'woocommerce_check_cart_items', 'woocommerce_cart_emptied', 'woocommerce_checkout_create_order', 'woocommerce_store_api_checkout_update_order_meta' ) as $hook ) {
	check( "quote checkout hooks $hook", isset( $qc_hooks[ $hook ] ) );
}
check(
	'quote prices run AFTER the B2B pricing callback (priority 20)',
	isset( $qc_hooks['woocommerce_before_calculate_totals'] ) && $qc_hooks['woocommerce_before_calculate_totals'] > 20
);
check( 'the feature is on for a connected store', Tack_Quote_Checkout::is_enabled() );
tack_test_set_option( 'tack_quotes_api_key', '' );
check( 'and off for a store with no API key', ! Tack_Quote_Checkout::is_enabled() );
tack_test_set_option( 'tack_quotes_api_key', 'tk_test_key' );

// ── 1. Token shape refused before any HTTP ─────────────────────────────────
foreach ( array( '', 'short', TACK_QC_TOKEN . 'x', str_replace( 'A', '/', TACK_QC_TOKEN ), "../../ping?x=1&y=aaaaaaaaaaaaaaaaaaaaaaaaaa" ) as $bad ) {
	tack_test_reset_notices();
	$qc = tack_qc_make( tack_qc_payload() );
	$ok = $qc->process_token( $bad );
	check(
		'a malformed token (' . strlen( $bad ) . ' chars) is refused with NO request',
		! $ok && array() === $qc->client_paths() && false !== strpos( tack_qc_notices(), 'not valid' ),
		var_export( $qc->client_paths(), true )
	);
}

// ── 2. 404 / 410 / outage: a friendly notice, ONE request, cart untouched ───
foreach ( array( 404 => 'not valid', 410 => 'already used or has expired', 500 => 'could not be opened right now', 0 => 'could not be opened right now' ) as $status => $phrase ) {
	tack_test_reset_notices();
	$error = new WP_Error( 'tack_http_' . $status, 'RAW SERVER TEXT', array( 'status' => $status ) );
	$qc    = tack_qc_make( $error );
	$qc->fake_cart->contents = array( 'keep' => array( 'quantity' => 1, 'data' => new Tack_Test_QC_Product( 99 ) ) );
	$ok    = $qc->process_token( TACK_QC_TOKEN );
	check( "HTTP $status: refused with a buyer-facing notice", ! $ok && false !== strpos( tack_qc_notices(), $phrase ), tack_qc_notices() );
	check( "HTTP $status: the server's own text is never shown", false === strpos( tack_qc_notices(), 'RAW SERVER TEXT' ) );
	check( "HTTP $status: exactly ONE exchange, never retried", 1 === count( $qc->client_paths() ), var_export( $qc->client_paths(), true ) );
	check( "HTTP $status: the buyer's cart is left as it was", 0 === $qc->fake_cart->emptied && isset( $qc->fake_cart->contents['keep'] ) );
	check( "HTTP $status: sends the buyer to the cart page", 'https://shop.example/cart/' === $qc->result_url );
}
$qc = tack_qc_make( new WP_Error( 'x', 'x', array( 'status' => 410 ) ) );
$qc->process_token( TACK_QC_TOKEN );
check(
	'the exchange path is GET /integrations/woocommerce/quote-checkout/<token>',
	array( 'GET /integrations/woocommerce/quote-checkout/' . TACK_QC_TOKEN ) === $qc->client_paths(),
	var_export( $qc->client_paths(), true )
);

// ── 3. Currency mismatch ────────────────────────────────────────────────────
tack_test_reset_notices();
$GLOBALS['TACK_STORE_CURRENCY'] = 'EUR';
$qc = tack_qc_make( tack_qc_payload() );
$qc->fake_cart->contents = array( 'keep' => array( 'quantity' => 1, 'data' => new Tack_Test_QC_Product( 99 ) ) );
$ok = $qc->process_token( TACK_QC_TOKEN );
check( 'a quote in USD is refused by a EUR store', ! $ok && 0 === count( $qc->fake_cart->adds ) );
check( 'the refusal names both currencies and the quote', false !== strpos( tack_qc_notices(), 'USD' ) && false !== strpos( tack_qc_notices(), 'EUR' ) && false !== strpos( tack_qc_notices(), 'TK-2026-000123' ), tack_qc_notices() );
check( 'and the cart is not emptied for it', 0 === $qc->fake_cart->emptied && isset( $qc->fake_cart->contents['keep'] ) );
$GLOBALS['TACK_STORE_CURRENCY'] = 'USD';

// ── 4. The cart: exact quantities and cart item data ────────────────────────
tack_test_reset_notices();
$qc = tack_qc_make( tack_qc_payload() );
$qc->fake_cart->contents = array( 'old' => array( 'quantity' => 2, 'data' => new Tack_Test_QC_Product( 99 ) ) );
$ok = $qc->process_token( TACK_QC_TOKEN );
check( 'a valid link builds the cart', $ok, tack_qc_notices() );
check( 'the previous cart is emptied first', ! isset( $qc->fake_cart->contents['old'] ) && $qc->fake_cart->emptied >= 1 );
$adds = $qc->fake_cart->adds;
check( 'two lines added', 2 === count( $adds ) );
check(
	'line 1: product 11, quantity 3, no variation',
	isset( $adds[0] ) && 11 === $adds[0][0] && 3 === $adds[0][1] && 0 === $adds[0][2]
);
check(
	'line 2: parent 20 + variation 21 with its attributes, quantity 10',
	isset( $adds[1] ) && 20 === $adds[1][0] && 10 === $adds[1][1] && 21 === $adds[1][2] && array( 'attribute_pa_size' => 'large' ) === $adds[1][3]
);
check(
	'cart item data carries quote ref, quoted unit price, quote number and the locked quantity',
	isset( $adds[0][4] ) && array(
		'tackquote_quote_ref'    => TACK_QC_REF,
		'tackquote_unit_price'   => '33.3333',
		'tackquote_quote_number' => 'TK-2026-000123',
		'tackquote_quantity'     => 3,
	) === $adds[0][4],
	var_export( $adds[0][4] ?? null, true )
);
$state = $qc->fake_session->get( Tack_Quote_Checkout::SESSION_KEY );
check( 'the session holds the quote ref', is_array( $state ) && TACK_QC_REF === $state['ref'] );
check( 'the session never holds the raw token', is_array( $state ) && false === strpos( wp_json_encode( $state ), TACK_QC_TOKEN ) );
check( 'and records both cart lines with their quantities', is_array( $state ) && array( 3, 10 ) === array_values( $state['lines'] ) );

// Redirect: the token never travels on.
$_GET[ Tack_Quote_Checkout::QUERY_VAR ] = TACK_QC_TOKEN;
$qc = tack_qc_make( tack_qc_payload() );
$qc->handle_link();
unset( $_GET[ Tack_Quote_Checkout::QUERY_VAR ] );
check( 'handle_link() redirects to the checkout page', 'https://shop.example/checkout/' === $qc->redirected, var_export( $qc->redirected, true ) );
check( 'the redirect does NOT carry the token', is_string( $qc->redirected ) && false === strpos( $qc->redirected, TACK_QC_TOKEN ) && false === strpos( $qc->redirected, 'tackquote_checkout' ) );
check( 'the redirect is sent no-cache', $qc->nocache );

// Opening the same link again (back button) after it was spent here: no second exchange.
$qc->process_token( TACK_QC_TOKEN );
check( 'the same link reopened goes straight to checkout without a second exchange', 1 === count( $qc->client_paths() ) && 'https://shop.example/checkout/' === $qc->result_url, var_export( $qc->client_paths(), true ) );

// ── 5. Any failing line: cart empty, refusal names the line ─────────────────
$cases = array(
	'a missing product'             => function ( $qc ) {
		unset( $qc->products[11] );
	},
	'a product that is not purchasable' => function ( $qc ) {
		$qc->products[11]->purchasable = false;
	},
	'a variation of another product'    => function ( $qc ) {
		$qc->products[21]->parent = 99;
	},
	'a variation with an "any" attribute' => function ( $qc ) {
		$qc->attributes[21] = null;
	},
);
foreach ( $cases as $label => $break ) {
	tack_test_reset_notices();
	$qc = tack_qc_make( tack_qc_payload() );
	$break( $qc );
	$ok = $qc->process_token( TACK_QC_TOKEN );
	check( "$label: the whole quote is refused", ! $ok );
	check( "$label: the cart is left EMPTY (no partial quote)", array() === $qc->fake_cart->get_cart(), var_export( array_keys( $qc->fake_cart->get_cart() ), true ) );
	check( "$label: the session forgets the quote", null === $qc->fake_session->get( Tack_Quote_Checkout::SESSION_KEY ) );
	$name = ( 'a missing product' === $label || 'a product that is not purchasable' === $label ) ? 'Widget' : 'Shirt - Large';
	check( "$label: the notice names the line", false !== strpos( tack_qc_notices(), $name ), tack_qc_notices() );
}

// A payload that cannot be read is refused before the cart is touched.
foreach ( array(
	'a bad quoteRef'      => array( 'quoteRef' => 'nope' ),
	'no lines'            => array( 'lines' => array() ),
	'a float quantity'    => array( 'lines' => array( array( 'wooProductId' => 11, 'quantity' => 1.5, 'unitPrice' => '1.00', 'name' => 'x' ) ) ),
	'a 5-decimal price'   => array( 'lines' => array( array( 'wooProductId' => 11, 'quantity' => 1, 'unitPrice' => '1.00001', 'name' => 'x' ) ) ),
	'a negative price'    => array( 'lines' => array( array( 'wooProductId' => 11, 'quantity' => 1, 'unitPrice' => '-1.00', 'name' => 'x' ) ) ),
	'a string product id' => array( 'lines' => array( array( 'wooProductId' => '11', 'quantity' => 1, 'unitPrice' => '1.00', 'name' => 'x' ) ) ),
) as $label => $override ) {
	$qc = tack_qc_make( array_merge( tack_qc_payload(), $override ) );
	$qc->fake_cart->contents = array( 'keep' => array( 'quantity' => 1, 'data' => new Tack_Test_QC_Product( 99 ) ) );
	check( "payload with $label is refused and the cart kept", ! $qc->process_token( TACK_QC_TOKEN ) && 0 === $qc->fake_cart->emptied );
}

// ── 6. Pricing: the quoted price wins, for quote lines only ─────────────────
$quote_product          = new Tack_Test_Product( 'WIDGET' );
$quote_product->price   = 50.0;
$quote_product->regular = 50.0;
$plain_product          = new Tack_Test_Product( 'BOLT' );
$plain_product->price   = 9.0;
$plain_product->regular = 9.0;
$mixed_cart             = new Tack_Test_Cart(
	array(
		'q' => array(
			'data'                   => $quote_product,
			'quantity'               => 3,
			'tackquote_quote_ref'    => TACK_QC_REF,
			'tackquote_unit_price'   => '33.3333',
			'tackquote_quantity'     => 3,
		),
		'p' => array(
			'data'     => $plain_product,
			'quantity' => 5,
		),
	)
);
tack_test_set_option( Tack_Wholesale_Pricing::OPTION_ENABLED, 'yes' );
tack_test_set_logged_in( true, 'buyer@trade-customer.test' );
$b2b = new Tack_Test_Pricing_Client(
	array(
		'buyerMatched' => true,
		'items'        => array(
			array( 'sku' => 'WIDGET', 'quantity' => 3, 'unitPrice' => 20.0 ),
			array( 'sku' => 'BOLT', 'quantity' => 5, 'unitPrice' => 7.5 ),
		),
	)
);
// The order WooCommerce runs them in: B2B pricing at 20, quote prices at 25.
( new Tack_Wholesale_Pricing( $b2b ) )->apply_cart_prices( $mixed_cart );
( new Tack_Quote_Checkout( new Tack_Test_QC_Client( array() ) ) )->apply_prices( $mixed_cart );
check( 'the quote line is charged the QUOTED unit price', 33.3333 === (float) $quote_product->get_price(), var_export( $quote_product->get_price(), true ) );
check( 'the B2B pricing still prices the other line', 7.5 === (float) $plain_product->get_price(), var_export( $plain_product->get_price(), true ) );
$asked = array();
foreach ( (array) ( $b2b->last_body['items'] ?? array() ) as $it ) {
	$asked[] = $it['sku'];
}
check( 'the B2B pricing does not even ask about the quote line', array( 'BOLT' ) === $asked, var_export( $b2b->last_body, true ) );
// The other order (a third-party re-sort) must still not let B2B win on the quote line.
$quote_product->price = 50.0;
( new Tack_Quote_Checkout( new Tack_Test_QC_Client( array() ) ) )->apply_prices( $mixed_cart );
( new Tack_Wholesale_Pricing( $b2b ) )->apply_cart_prices( $mixed_cart );
check( 'run in either order, B2B pricing never overrides a quote line', 33.3333 === (float) $quote_product->get_price(), var_export( $quote_product->get_price(), true ) );
tack_test_set_option( Tack_Wholesale_Pricing::OPTION_ENABLED, 'no' );
tack_test_set_logged_in( false, '' );

// Inclusive-of-tax store: the NET quote line is grossed up by the BASE rate, unrounded,
// so WooCommerce's own extraction (WC_Tax::calc_tax inclusive) lands on the net line.
tack_test_set_prices_include_tax( true );
$tax_product = new Tack_Test_QC_Product( 11 );
$worst       = 0.0;
foreach ( array( array( '33.3333', 3, 10000 ), array( '30.00', 10, 30000 ), array( '0.0833', 12, 100 ), array( '19.99', 7, 13993 ) ) as $c ) {
	$unit      = Tack_Quote_Checkout::store_basis_price( $c[0], $tax_product, $c[1] );
	$gross     = round( $unit * $c[1] * 100, 4 );            // wc_add_number_precision.
	$extracted = WC_Tax::calc_tax( $gross, WC_Tax::get_base_tax_rates(), true );
	$net_cents = round( $gross - array_sum( $extracted ) ); // remove_item_base_taxes + line rounding.
	$worst     = max( $worst, abs( $net_cents - $c[2] ) );
}
check( 'prices entered incl. tax (20% base): every grossed-up quote line comes back to its quoted NET line total', 0.0 === (float) $worst, "worst drift $worst cent(s)" );
tack_test_set_prices_include_tax( false );
check( 'prices entered excl. tax: the quoted string goes to set_price() unchanged', '33.3333' === Tack_Quote_Checkout::store_basis_price( '33.3333', $quote_product, 3 ) );

// ── 7. Quantities are locked ────────────────────────────────────────────────
$qline = array( 'quantity' => 3, 'tackquote_quote_ref' => TACK_QC_REF, 'tackquote_unit_price' => '33.3333', 'tackquote_quantity' => 3 );
$pline = array( 'quantity' => 5 );
$qc    = tack_qc_make( array() );
$html  = $qc->quantity_html( '<input name="cart[q][qty]">', 'q', $qline );
check( 'classic cart: a quote line shows its quantity as text, no input', false === strpos( $html, '<input' ) && false !== strpos( $html, '>3<' ), $html );
check( 'classic cart: other lines keep the input', '<input name="cart[p][qty]">' === $qc->quantity_html( '<input name="cart[p][qty]">', 'p', $pline ) );
tack_test_reset_notices();
check( 'classic cart update: a new quantity for a quote line is refused', false === $qc->refuse_quantity_update( true, 'q', $qline, 4 ) && '' !== tack_qc_notices() );
check( 'classic cart update: the same quantity passes', true === $qc->refuse_quantity_update( true, 'q', $qline, 3 ) );
check( 'classic cart update: other lines are not touched', true === $qc->refuse_quantity_update( true, 'p', $pline, 9 ) );
check( 'Store API: a quote line is not editable', false === $qc->store_api_editable( true, null, $qline ) );
check( 'Store API: other lines keep WooCommerce\'s answer', true === $qc->store_api_editable( true, null, $pline ) );
check( 'Store API: min and max of a quote line are its quantity', 3 === $qc->store_api_limit( 1, null, $qline ) && 9999 === $qc->store_api_limit( 9999, null, $pline ) );
check( 'Store API: product limits without a cart item are untouched', 1 === $qc->store_api_limit( 1, null, null ) );
check( 'Store API 11.2 validation: another quantity is a WP_Error', $qc->store_api_validate_quantity( true, 2, null, $qline ) instanceof WP_Error );
check( 'Store API 11.2 validation: the same quantity is valid', true === $qc->store_api_validate_quantity( true, 3, null, $qline ) );

// ── 8. No mixing ────────────────────────────────────────────────────────────
tack_test_reset_notices();
$qc = tack_qc_make( tack_qc_payload() );
$qc->process_token( TACK_QC_TOKEN );
check( 'adding another product beside a quote is refused', false === $qc->refuse_mixing( true, 55 ) && false !== strpos( tack_qc_notices(), 'accepted quote' ) );
$threw = false;
try {
	$qc->refuse_store_api_mixing( null, array() );
} catch ( Exception $e ) {
	$threw = true;
}
check( 'and the Store API add-to-cart throws', $threw );
$empty = tack_qc_make( array() );
check( 'a cart without a quote adds normally', true === $empty->refuse_mixing( true, 55 ) );

// ── 9. Removal and integrity ────────────────────────────────────────────────
tack_test_reset_notices();
$keys = array_keys( $qc->fake_cart->get_cart() );
$qc->fake_cart->remove_cart_item( $keys[0] );
check( 'removing one quote line removes the whole quote', array() === $qc->fake_cart->get_cart() );
check( 'and forgets it', null === $qc->fake_session->get( Tack_Quote_Checkout::SESSION_KEY ) );

tack_test_reset_notices();
$qc = tack_qc_make( tack_qc_payload() );
$qc->process_token( TACK_QC_TOKEN );
$keys = array_keys( $qc->fake_cart->contents );
$qc->fake_cart->contents[ $keys[0] ]['quantity'] = 7; // Changed behind our back.
$qc->check_cart();
check( 'a quote line whose quantity changed by any route is caught at checkout and the quote removed', array() === $qc->fake_cart->get_cart() && '' !== tack_qc_notices() );

$qc = tack_qc_make( tack_qc_payload() );
$qc->fake_cart->contents = array( 'stale' => $qline + array( 'data' => new Tack_Test_QC_Product( 11 ) ) );
$qc->check_cart();
check( 'a quote line with no quote in this session (restored, stale) is removed', array() === $qc->fake_cart->get_cart() );

// ── 10. The order ───────────────────────────────────────────────────────────
$qc = tack_qc_make( tack_qc_payload() );
$qc->process_token( TACK_QC_TOKEN );
$order = new WC_Order( array( 'id' => 501 ) );
$qc->save_order_meta( $order );
check( 'the order stores the quote ref', TACK_QC_REF === $order->get_meta( '_tackquote_quote_ref' ) );
check( 'and the quote number', 'TK-2026-000123' === $order->get_meta( '_tackquote_quote_number' ) );
check( 'the quote PO fills _tackquote_po_number when the buyer typed none', 'PO-777' === $order->get_meta( '_tackquote_po_number' ) );
$typed = new WC_Order( array( 'id' => 502 ) );
$typed->update_meta_data( '_tackquote_po_number', 'BUYER-PO' );
$qc->save_order_meta( $typed );
check( 'a PO the buyer typed is kept', 'BUYER-PO' === $typed->get_meta( '_tackquote_po_number' ) );
$block = new WC_Order( array( 'id' => 503 ) );
$block->update_meta_data( '_tackquote_quote_ref', TACK_QC_REF );
$qc->fill_block_po( $block );
check( 'Checkout block: the quote PO is filled after the block saved its fields', 'PO-777' === $block->get_meta( '_tackquote_po_number' ) );

$plain_qc = tack_qc_make( array() );
$plain    = new WC_Order( array( 'id' => 504 ) );
$plain_qc->save_order_meta( $plain );
check( 'an ordinary order gets no quote ref', '' === $plain->get_meta( '_tackquote_quote_ref' ) );

// ── 11. Order sync carries tackQuoteRef only when set ───────────────────────
$sync = new Tack_Order_Sync();
$rm   = new ReflectionMethod( $sync, 'build_payload' );
$rm->setAccessible( true );
$linked = new WC_Order( array( 'id' => 601 ) );
$linked->update_meta_data( '_tackquote_quote_ref', TACK_QC_REF );
$payload = $rm->invoke( $sync, $linked );
check( 'order-sync sends tackQuoteRef for an order built from a link', isset( $payload['tackQuoteRef'] ) && TACK_QC_REF === $payload['tackQuoteRef'] );
$payload = $rm->invoke( $sync, new WC_Order( array( 'id' => 602 ) ) );
check( 'order-sync sends NO tackQuoteRef key for any other order (old servers refuse unknown fields)', ! array_key_exists( 'tackQuoteRef', $payload ) );
$junk = new WC_Order( array( 'id' => 603 ) );
$junk->update_meta_data( '_tackquote_quote_ref', 'not-a-ref' );
$payload = $rm->invoke( $sync, $junk );
check( 'a malformed stored ref is not sent', ! array_key_exists( 'tackQuoteRef', $payload ) );

// ── 12. Catalogue mode stands down for the quote's own products ─────────────
$GLOBALS['TACK_WC_SESSION'] = new Tack_Stub_Session();
$GLOBALS['TACK_WC_SESSION']->set( Tack_Quote_Checkout::SESSION_KEY, array( 'ref' => TACK_QC_REF, 'products' => array( 11, 21 ) ) );
check( 'holds_product: a product of the active quote', Tack_Quote_Checkout::holds_product( new Tack_Test_QC_Product( 21 ) ) );
check( 'holds_product: any other product is not', ! Tack_Quote_Checkout::holds_product( new Tack_Test_QC_Product( 12 ) ) );
tack_test_set_option( 'tack_quotes_api_key', '' );
check( 'holds_product: nothing once the store is disconnected', ! Tack_Quote_Checkout::holds_product( new Tack_Test_QC_Product( 21 ) ) );
tack_test_set_option( 'tack_quotes_api_key', 'tk_test_key' );
tack_test_set_option( Tack_Catalog_Mode::OPT_MODE, Tack_Catalog_Mode::MODE_QUOTE_ONLY );
$catalog = new Tack_Catalog_Mode();
check( 'a store-wide quote-only store still sells the quote\'s product through its quote', true === $catalog->filter_is_purchasable( true, new Tack_Test_QC_Product( 21 ) ) );
check( 'and keeps every other product quote-only', false === $catalog->filter_is_purchasable( true, new Tack_Test_QC_Product( 12 ) ) );
tack_test_set_option( Tack_Catalog_Mode::OPT_MODE, Tack_Catalog_Mode::MODE_CART );
unset( $GLOBALS['TACK_WC_SESSION'] );

// ── 13. Rounding: does a 4-decimal set_price() reproduce the quoted line total? ──
//
// UNVERIFIED in tack (#733). WooCommerce (11.2.1, includes/class-wc-cart-totals.php
// get_items_from_cart + calculate_item_subtotals, includes/traits/trait-wc-item-totals.php):
//   cents = round( price * qty * 10^dp, rounding_precision - dp )   (wc_add_number_precision)
//   per line: round( cents ) half-up   UNLESS woocommerce_tax_round_at_subtotal = yes,
//   in which case lines are summed unrounded and only the sum is rounded.
// tack picks unit4 so that round(unit4 * qty / 100) == netMinor (reproducingUnitPrice).

/**
 * tack's reproducingUnitPrice(), ported: the 4-decimal unit for a net line, or null.
 *
 * @param int $net_minor Line total in cents.
 * @param int $qty       Quantity.
 * @return string|null
 */
function tack_qc_unit4( $net_minor, $qty ) {
	$unit4 = (int) round( ( $net_minor * 100 ) / $qty );
	if ( (int) round( ( $unit4 * $qty ) / 100 ) !== $net_minor ) {
		return null;
	}
	return number_format( $unit4 / 10000, 4, '.', '' );
}

/**
 * WooCommerce's line amount in cents before line rounding (dp = 2, precision 6).
 *
 * @param string $unit Unit price string passed to set_price().
 * @param int    $qty  Quantity.
 * @return float
 */
function tack_qc_wc_cents( $unit, $qty ) {
	return round( (float) $unit * (float) $qty * 100, 6 - 2 );
}

$lines_checked = 0;
$line_drift    = 0;
for ( $qty = 1; $qty <= 60; $qty++ ) {
	for ( $net = 1; $net <= 30000; $net += 7 ) {
		$unit = tack_qc_unit4( $net, $qty );
		if ( null === $unit ) {
			continue;
		}
		++$lines_checked;
		if ( (int) round( tack_qc_wc_cents( $unit, $qty ) ) !== $net ) {
			++$line_drift;
		}
	}
}
check(
	"rounding, per-line (WooCommerce default): $lines_checked reproducible lines, every WooCommerce line total equals the quoted line total",
	$lines_checked > 1000 && 0 === $line_drift,
	"$line_drift line(s) drift"
);

// round-at-subtotal ON: lines are summed UNROUNDED, so each line's sub-cent remainder
// (under half a cent, by tack's choice of unit4) can add up across lines. Find the
// worst remainder per line and show two such lines move the goods total by a cent.
$worst_line = null;
for ( $qty = 2; $qty <= 200 && null === $worst_line; $qty++ ) {
	for ( $net = 100; $net <= 30000; $net++ ) {
		$unit = tack_qc_unit4( $net, $qty );
		if ( null !== $unit && tack_qc_wc_cents( $unit, $qty ) - $net >= 0.26 ) {
			$worst_line = array( $net, $qty, $unit, tack_qc_wc_cents( $unit, $qty ) - $net );
			break;
		}
	}
}
$drift = null === $worst_line ? 0 : (int) round( 2 * tack_qc_wc_cents( $worst_line[2], $worst_line[1] ) ) - 2 * $worst_line[0];
check(
	'rounding, round-at-subtotal ON: two quote lines can move the goods total by ONE cent (documented in the PR; tack may send 2-decimal prices)',
	null !== $worst_line && 1 === $drift,
	null === $worst_line ? 'no example found' : sprintf( '2 x (%d @ %s): quoted %.2f each, drift %d cent(s)', $worst_line[1], $worst_line[2], $worst_line[0] / 100, $drift )
);
if ( null !== $worst_line ) {
	echo sprintf( "        example: 2 lines of %d @ %s (quoted %.2f each) -> WooCommerce goods total off by %+d cent\n", $worst_line[1], $worst_line[2], $worst_line[0] / 100, $drift );
}
