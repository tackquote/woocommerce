<?php
/**
 * B2B pricing resolved by TackQuote, applied to the WooCommerce storefront.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHAT THIS CLOSES
 * ─────────────────────────────────────────────────────────────────────────────
 * Until now the plugin could SEND a quote request and RECEIVE Tack-resolved
 * prices back on the quote — but a signed-in trade customer browsing the shop
 * still saw the store's retail price, because nothing let the storefront ask
 * what Tack would charge before a quote existed. The wholesale price only
 * appeared after the fact.
 *
 * `POST /storefront-pricing/resolve` is the answer to that question. It takes a
 * buyer email and a set of SKUs and returns the price Tack's own price books,
 * buyer groups and quantity breaks resolve to — the same authority that prices
 * a quote. This class asks it, and applies the answer.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHAT IS CHARGED, AND WHAT IS SHOWN, ARE TWO DIFFERENT HOOKS
 * ─────────────────────────────────────────────────────────────────────────────
 * See `init()` for the full argument. In short: the cart is priced on
 * `woocommerce_before_calculate_totals`, because that is the only hook that
 * knows how many of each line the buyer actually ordered — and without a
 * quantity a quantity BREAK cannot exist. The label is a separate filter on
 * `woocommerce_get_price_html`.
 *
 * Money is involved either way, so three rules keep it honest, and each one is
 * a test in tests/wholesale-pricing-test.php:
 *
 *   1. NO ANSWER MEANS NO CHANGE. A network failure, a timeout, an unknown SKU
 *      or a `null` unitPrice all leave the store's own price untouched. The
 *      failure mode is "wholesale pricing did not apply", never "the product is
 *      free" — `0` is a legitimate resolved price and `null` is not, so the two
 *      are distinguished explicitly rather than through PHP's falsy rules.
 *   2. ANONYMOUS SHOPPERS ARE NEVER PRICED. Without a signed-in customer there
 *      is no buyer to resolve against, so no request is made at all. This also
 *      means a page cache holding a logged-out render can never contain one
 *      customer's negotiated price.
 *   3. ONE REQUEST PER PAGE, NOT ONE PER PRODUCT. A shop page renders dozens of
 *      products and each one calls `get_price()` several times. Every lookup is
 *      served from a request-scoped map, and the map is filled by a single
 *      batched call. Without this a category page would issue ~100 HTTP
 *      requests and time out.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * GATING
 * ─────────────────────────────────────────────────────────────────────────────
 * B2B pricing is a paid TackQuote capability. That is NOT enforced here — a
 * client-side plan check is a client-side plan check. The endpoint sits behind
 * `ApiKeyGuard` and `PlanGuard` on the API, so a store on a plan without it
 * receives an error and, by rule 1 above, simply keeps its own prices. What
 * this class does is avoid asking when there is obviously nothing to ask with:
 * no API key, or no signed-in customer.
 *
 * @package TackQuotes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Applies TackQuote-resolved B2B prices to WooCommerce products.
 */
class Tack_Wholesale_Pricing {

	/**
	 * Option that turns the whole feature on.
	 *
	 * Default 'no': switching a store's prices over is a decision a merchant
	 * makes deliberately, not something a plugin update does to them.
	 */
	const OPTION_ENABLED = 'tack_quotes_enable_wholesale_pricing';

	/** Option controlling the quantity-break table on the product page. */
	const OPTION_SHOW_BREAKS = 'tack_quotes_show_quantity_breaks';

	/**
	 * The API caps a resolve request at 50 items, so batches are chunked to match.
	 *
	 * Sending 51 is a 400 for the WHOLE batch, which would blank the prices on a
	 * large category page rather than degrade.
	 */
	const MAX_ITEMS_PER_REQUEST = 50;

	/**
	 * Interactive timeout, in seconds.
	 *
	 * A shop page must not hang on Tack being slow. Deliberately shorter than the
	 * client's 20s default: past a couple of seconds the right answer is "show
	 * the store price" rather than "show nothing yet".
	 */
	const TIMEOUT = 5;

	/**
	 * Resolved unit prices for this request, keyed `sku|quantity`.
	 *
	 * Request-scoped rather than a transient: a price is per BUYER, and a shared
	 * cache keyed only by SKU is how one customer is shown another's negotiated
	 * price. If this ever becomes a transient the key must include the buyer.
	 *
	 * @var array<string, float|null>
	 */
	private $resolved = array();

	/**
	 * SKUs already asked about this request, so an unpriced one is not re-sent.
	 *
	 * Distinct from `$resolved`: a SKU Tack does not carry returns no line at
	 * all, and without this it would be re-requested on every `get_price()`.
	 *
	 * @var array<string, true>
	 */
	private $asked = array();

	/**
	 * `/storefront/v1/wholesale-price` answers for this request, keyed by SKU.
	 * Request-scoped for the same reason as `$resolved`: a price is per buyer.
	 *
	 * @var array<string, array|WP_Error|null>
	 */
	private $v1_prices = array();

	/**
	 * Products whose quantity-break table was already rendered (or decided
	 * against) this request, so the classic summary hook and the Add to Cart
	 * block mounts print it once. Keyed by product id.
	 *
	 * @var array<string, true>
	 */
	private $breaks_rendered = array();

	/**
	 * API client.
	 *
	 * @var Tack_Api_Client
	 */
	private $client;

	/**
	 * Constructor.
	 *
	 * @param Tack_Api_Client|null $client Injected in tests; built here otherwise.
	 */
	public function __construct( $client = null ) {
		$this->client = $client instanceof Tack_Api_Client ? $client : new Tack_Api_Client();
	}

	/**
	 * Is the feature switched on by the merchant?
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return 'yes' === get_option( self::OPTION_ENABLED, 'no' );
	}

	/**
	 * Register hooks.
	 *
	 * ── Two hooks, deliberately, rather than one ──────────────────────────────
	 *
	 * The obvious implementation is a single filter on
	 * `woocommerce_product_get_price`, and the first version of this class did
	 * exactly that. It is wrong twice over:
	 *
	 *   · THAT FILTER HAS NO QUANTITY. It is asked "what does this product
	 *     cost", not "what do 100 of them cost", so a quantity break can never
	 *     be applied through it — it always resolves at quantity 1. The volume
	 *     table would advertise "100+ £55.00" and the cart would charge £61.50.
	 *     Displaying a discount the checkout does not honour is worse than not
	 *     offering one.
	 *   · IT FIRES EVERYWHERE. Every product read, in admin, REST, cron, emails
	 *     and reports, several times per product per page.
	 *
	 * So the work is split the way WooCommerce intends, and the way the mature
	 * B2B plugins do it:
	 *
	 *   DISPLAY -> `woocommerce_get_price_html`, which is only about the label.
	 *   MONEY   -> `woocommerce_before_calculate_totals`, which is the one place
	 *              that knows the real quantity of each line, and where
	 *              `$item['data']->set_price()` is the documented way to price a
	 *              cart line dynamically.
	 */
	public function init() {
		// The money. Priority 20, after WooCommerce settles its own sale prices.
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_cart_prices' ), 20 );

		// The label.
		add_filter( 'woocommerce_get_price_html', array( $this, 'filter_price_html' ), 20, 2 );

		if ( 'yes' === get_option( self::OPTION_SHOW_BREAKS, 'yes' ) ) {
			add_action( 'woocommerce_single_product_summary', array( $this, 'render_quantity_breaks_summary' ), 25 );

			/*
			 * Block themes (1.9.0): WooCommerce fires the summary hook from its
			 * compatibility layer above the excerpt, away from the quantity box the
			 * table is about. The table goes after the Add to Cart block instead, at
			 * priority 5 so it sits before the quote buttons `Tack_Widget` appends at
			 * 10. See `Tack_Block_Product`.
			 */
			foreach ( Tack_Block_Product::ADD_TO_CART_BLOCKS as $block_name ) {
				add_filter( 'render_block_' . $block_name, array( $this, 'append_quantity_breaks_to_block' ), 5, 3 );
			}
		}
	}

	/**
	 * Price every cart line at the buyer's rate FOR THE QUANTITY THEY ORDERED.
	 *
	 * This is the hook that makes a quantity break real. Each line is resolved
	 * at its own quantity, in ONE batched request for the whole cart, and only a
	 * line Tack actually priced is touched.
	 *
	 * @param object $cart WC_Cart.
	 */
	public function apply_cart_prices( $cart ) {
		if ( ! $this->should_apply() || ! is_object( $cart ) ) {
			return;
		}
		if ( ! method_exists( $cart, 'get_cart' ) ) {
			return;
		}

		$contents = $cart->get_cart();
		if ( empty( $contents ) ) {
			return;
		}

		// Batch first: one request for the whole cart rather than one per line.
		$wanted = array();
		foreach ( $contents as $item ) {
			if ( empty( $item['data'] ) || ! method_exists( $item['data'], 'get_sku' ) || self::is_quote_line( $item ) ) {
				continue;
			}
			$sku = (string) $item['data']->get_sku();
			$qty = isset( $item['quantity'] ) ? (int) $item['quantity'] : 1;
			if ( '' === $sku || $qty < 1 ) {
				continue;
			}
			$wanted[ $sku . '|' . $qty ] = array(
				'sku'      => $sku,
				'quantity' => $qty,
			);
		}
		if ( empty( $wanted ) ) {
			return;
		}
		$this->resolve( array_values( $wanted ) );

		foreach ( $contents as $key => $item ) {
			// A line of an accepted quote keeps its quoted price (Tack_Quote_Checkout).
			if ( empty( $item['data'] ) || ! method_exists( $item['data'], 'get_sku' ) || self::is_quote_line( $item ) ) {
				continue;
			}
			$sku = (string) $item['data']->get_sku();
			$qty = isset( $item['quantity'] ) ? (int) $item['quantity'] : 1;
			if ( '' === $sku || $qty < 1 ) {
				continue;
			}

			$unit = $this->unit_price( $sku, $qty );
			if ( null === $unit ) {
				// No answer: the line keeps the store's own price. Never zeroed.
				continue;
			}

			/**
			 * Filters the Tack-resolved NET unit price before it is applied to a cart line.
			 *
			 * Runs only for a SKU TackQuote actually priced; a line with no answer keeps
			 * the store's own price and never reaches this filter.
			 *
			 * @since 1.6.0
			 *
			 * @param float  $unit        Net unit price resolved by TackQuote for this quantity.
			 * @param mixed  $store_price The product's own price, as `WC_Product::get_price()` returns it.
			 * @param string $sku         Product SKU.
			 */
			$unit = apply_filters( 'tackquote_wholesale_price', $unit, $item['data']->get_price(), $sku );

			if ( method_exists( $item['data'], 'set_price' ) ) {
				$item['data']->set_price( Tack_Tax_Basis::entry_price( $unit, $item['data'], $qty ) );
			}
			unset( $key );
		}
	}

	/**
	 * Is this cart line part of an accepted TackQuote quote? Such a line is priced
	 * by `Tack_Quote_Checkout` at the quoted price and is never re-priced here.
	 *
	 * @param array $item Cart item.
	 * @return bool
	 */
	private static function is_quote_line( $item ) {
		return class_exists( 'Tack_Quote_Checkout' ) && Tack_Quote_Checkout::is_quote_item( $item );
	}

	/**
	 * Show the buyer's own unit price on the label.
	 *
	 * Quantity 1, because a product listing has no quantity — the volume table
	 * below carries the tiers. This only changes what is DISPLAYED; what is
	 * charged is settled in `apply_cart_prices()`.
	 *
	 * @param string $html    Price markup WooCommerce built.
	 * @param object $product WC_Product.
	 * @return string
	 */
	public function filter_price_html( $html, $product ) {
		if ( ! $this->should_apply() ) {
			return $html;
		}
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_sku' ) ) {
			return $html;
		}
		$sku = (string) $product->get_sku();
		if ( '' === $sku ) {
			return $html;
		}

		// The product page's own product reads the shared storefront contract.
		$single = $this->single_product_price_html( $html, $product, $sku );
		if ( null !== $single ) {
			return $single;
		}

		$unit = $this->unit_price( $sku, 1 );
		if ( null === $unit ) {
			return $html;
		}

		$unit  = Tack_Tax_Basis::display_price( $unit, $product );
		$store = method_exists( $product, 'get_regular_price' ) ? Tack_Tax_Basis::display_store_price( (float) $product->get_regular_price(), $product ) : null;

		// Struck-through original only when Tack is genuinely cheaper. Showing a
		// "was" price that is lower than the "now" price reads as a price rise.
		if ( null !== $store && $store > $unit ) {
			return '<del aria-hidden="true">' . wp_kses_post( wc_price( $store ) ) . '</del> '
				. '<ins>' . wp_kses_post( wc_price( $unit ) ) . '</ins>'
				. '<span class="screen-reader-text">'
				. esc_html__( 'Your price', 'tackquote' ) . '</span>';
		}

		return wp_kses_post( wc_price( $unit ) );
	}

	/**
	 * The price label for the product a single-product page is ABOUT, from
	 * `GET /storefront/v1/wholesale-price`, or null to use the batched legacy path.
	 *
	 * Only the page's own product: v1 prices one SKU per request, and a listing or
	 * a "related products" row would turn into one request per card. Those keep
	 * the batched `/storefront-pricing/resolve` (also what the cart charges).
	 *
	 * Answers, in order:
	 *   null       not the page's own product, or the server has no v1 routes
	 *              (404, remembered for an hour by the client).
	 *   $html      v1 failed, or answered without a price (`anonymous`,
	 *              `unlinked`, `unpriced`, a currency mismatch): the store's own
	 *              price stands, exactly as when the legacy route has no answer.
	 *   markup     a `priced` answer, formatted in the `currency` it carries, with
	 *              "Your account price" when `accountSpecific` says the price is
	 *              this buyer's own and not a group or public tier.
	 *
	 * @param string $html    Price markup WooCommerce built.
	 * @param object $product WC_Product.
	 * @param string $sku     The product's SKU.
	 * @return string|null
	 */
	private function single_product_price_html( $html, $product, $sku ) {
		if ( ! function_exists( 'is_product' ) || ! is_product() || ! function_exists( 'get_queried_object_id' ) ) {
			return null;
		}
		if ( ! method_exists( $product, 'get_id' ) || (int) $product->get_id() !== (int) get_queried_object_id() ) {
			return null;
		}

		// A product page renders its price more than once (summary, sticky bar,
		// structured data); one request per SKU per page view.
		if ( ! array_key_exists( $sku, $this->v1_prices ) ) {
			$this->v1_prices[ $sku ] = $this->client->get_wholesale_price( $sku, 1, $this->buyer_email() );
		}
		$result = $this->v1_prices[ $sku ];
		if ( null === $result ) {
			return null;
		}
		if ( is_wp_error( $result ) ) {
			$this->log( 'storefront v1 wholesale-price failed: ' . $result->get_error_message() );
			return $html;
		}
		if ( ! isset( $result['status'] ) || 'priced' !== $result['status'] || ! isset( $result['unitPrice'] ) || ! is_numeric( $result['unitPrice'] ) ) {
			return $html;
		}

		$currency = isset( $result['currency'] ) && is_string( $result['currency'] ) ? strtoupper( $result['currency'] ) : '';
		$args     = '' !== $currency ? array( 'currency' => $currency ) : array();
		$unit     = Tack_Tax_Basis::display_price( (float) $result['unitPrice'], $product );
		$label    = ! empty( $result['accountSpecific'] )
			? ' <span class="tackquote-account-price">' . esc_html__( 'Your account price', 'tackquote' ) . '</span>'
			: '';

		// The struck-through store price only when both are in the same currency.
		$store_currency = function_exists( 'get_woocommerce_currency' ) ? strtoupper( (string) get_woocommerce_currency() ) : '';
		$store          = method_exists( $product, 'get_regular_price' ) ? Tack_Tax_Basis::display_store_price( (float) $product->get_regular_price(), $product ) : 0.0;
		if ( ( '' === $currency || $currency === $store_currency ) && $store > $unit ) {
			return '<del aria-hidden="true">' . wp_kses_post( wc_price( $store ) ) . '</del> '
				. '<ins>' . wp_kses_post( wc_price( $unit, $args ) ) . '</ins>'
				. '<span class="screen-reader-text">' . esc_html__( 'Your price', 'tackquote' ) . '</span>'
				. $label;
		}
		return wp_kses_post( wc_price( $unit, $args ) ) . $label;
	}

	/**
	 * The resolved unit price for a SKU, or null when Tack has no answer.
	 *
	 * @param string $sku      Product SKU.
	 * @param int    $quantity Quantity to price.
	 * @return float|null
	 */
	public function unit_price( $sku, $quantity = 1 ) {
		$key = $sku . '|' . (int) $quantity;
		if ( array_key_exists( $key, $this->resolved ) ) {
			return $this->resolved[ $key ];
		}
		if ( isset( $this->asked[ $key ] ) ) {
			return null;
		}

		$lines = $this->resolve(
			array(
				array(
					'sku'      => $sku,
					'quantity' => (int) $quantity,
				),
			)
		);
		return array_key_exists( $key, $lines ) ? $lines[ $key ] : null;
	}

	/**
	 * Ask Tack to price a batch, and remember every answer.
	 *
	 * @param array $items Array of array{sku:string,quantity:int}.
	 * @return array<string, float|null> Keyed `sku|quantity`.
	 */
	public function resolve( array $items ) {
		if ( empty( $items ) ) {
			return array();
		}

		foreach ( $items as $item ) {
			$this->asked[ $item['sku'] . '|' . (int) $item['quantity'] ] = true;
		}

		$body = array( 'items' => array_slice( $items, 0, self::MAX_ITEMS_PER_REQUEST ) );

		$email = $this->buyer_email();
		if ( '' !== $email ) {
			$body['buyerEmail'] = $email;
		}

		$response = $this->client->request( 'POST', '/storefront-pricing/resolve', $body, self::TIMEOUT );

		if ( is_wp_error( $response ) ) {
			/*
			 * Logged, not surfaced. The shopper sees the store's own price and
			 * nothing is broken from where they stand; the merchant needs to know
			 * their B2B pricing is not being applied, and WooCommerce's log is
			 * where the plugin's other failures already go.
			 */
			$this->log( 'storefront-pricing resolve failed: ' . $response->get_error_message() );
			return array();
		}

		$out   = array();
		$lines = isset( $response['items'] ) && is_array( $response['items'] ) ? $response['items'] : array();
		foreach ( $lines as $line ) {
			if ( ! isset( $line['sku'] ) ) {
				continue;
			}
			$key = (string) $line['sku'] . '|' . ( isset( $line['quantity'] ) ? (int) $line['quantity'] : 1 );

			/*
			 * `null` and `0` are different answers and must not be conflated.
			 * `null` is "Tack does not price this SKU" -> keep the store price.
			 * `0` is a real resolved price -> honour it. `isset()`/`empty()`
			 * would collapse both into "no answer".
			 */
			$unit                   = array_key_exists( 'unitPrice', $line ) ? $line['unitPrice'] : null;
			$out[ $key ]            = ( null === $unit || '' === $unit ) ? null : (float) $unit;
			$this->resolved[ $key ] = $out[ $key ];
		}

		return $out;
	}

	/**
	 * `woocommerce_single_product_summary` (classic themes): the table above the
	 * add-to-cart form, except when WooCommerce's block-template compatibility
	 * layer is firing the hook, where `append_quantity_breaks_to_block()` places
	 * it instead.
	 *
	 * @since 1.9.0
	 */
	public function render_quantity_breaks_summary() {
		if ( Tack_Block_Product::is_compat_hook() ) {
			return;
		}
		$this->render_quantity_breaks();
	}

	/**
	 * `render_block_woocommerce/add-to-cart-form` and `.../add-to-cart-with-options`:
	 * the table after the block, for the block's own product, once per product.
	 *
	 * @since 1.9.0
	 *
	 * @param string               $block_content Rendered block.
	 * @param array                $parsed_block  Parsed block (unused).
	 * @param WP_Block|object|null $instance      Block instance; its `postId` context names the product.
	 * @return string
	 */
	public function append_quantity_breaks_to_block( $block_content, $parsed_block = array(), $instance = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- render_block_{$name} passes ( $content, $parsed_block, $instance ); only the instance is read.
		$product = Tack_Block_Product::from_block( $instance );
		if ( null === $product ) {
			return $block_content;
		}
		ob_start();
		$this->render_quantity_breaks( $product );
		return (string) $block_content . (string) ob_get_clean();
	}

	/**
	 * Quantity-break table for the product page.
	 *
	 * Renders nothing at all when there is no ladder to show. An empty table
	 * under a "Volume pricing" heading tells a trade customer their discounts
	 * were removed.
	 *
	 * @param WC_Product|string|null $for_product The product to render for; anything else
	 *                                            (a hook's empty argument) means the page's
	 *                                            global product.
	 */
	public function render_quantity_breaks( $for_product = null ) {
		if ( ! $this->should_apply() ) {
			return;
		}

		$product = is_object( $for_product ) ? $for_product : ( $GLOBALS['product'] ?? null );
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_sku' ) ) {
			return;
		}
		$sku = (string) $product->get_sku();
		if ( '' === $sku ) {
			return;
		}

		/*
		 * Once per product, whichever mount fires first (classic summary hook, or
		 * one of the Add to Cart blocks; a template can hold both blocks). Marked
		 * before the request, so a second mount neither asks TackQuote again nor
		 * prints a second table.
		 */
		$once_key = method_exists( $product, 'get_id' ) ? 'id:' . (int) $product->get_id() : 'sku:' . $sku;
		if ( isset( $this->breaks_rendered[ $once_key ] ) ) {
			return;
		}
		$this->breaks_rendered[ $once_key ] = true;

		/*
		 * The shared storefront contract first: `GET /storefront/v1/quantity-breaks`
		 * answers the whole ladder in ONE request, with the currency it is priced
		 * in and whether it is this account's own. The probe ladder below stays as
		 * the fallback for a server without v1 routes (the client answers null
		 * after a 404 and remembers it for an hour).
		 */
		$v1 = $this->client->get_quantity_breaks( $sku, $this->buyer_email() );
		if ( null !== $v1 ) {
			if ( is_wp_error( $v1 ) ) {
				$this->log( 'storefront v1 quantity-breaks failed: ' . $v1->get_error_message() );
				return;
			}
			$this->render_v1_quantity_breaks( $v1, $product );
			return;
		}

		/**
		 * Quantities to price for the break table.
		 *
		 * A ladder is only visible by asking for each rung: the resolve endpoint
		 * answers "what does THIS quantity cost", so the tiers are discovered by
		 * pricing a few representative quantities and keeping the ones that
		 * differ.
		 *
		 * @since 1.6.0
		 *
		 * @param int[]  $quantities Quantities to probe.
		 * @param string $sku        Product SKU.
		 */
		$quantities = apply_filters( 'tackquote_quantity_break_steps', array( 1, 10, 25, 50, 100 ), $sku );

		$items = array();
		foreach ( $quantities as $qty ) {
			$items[] = array(
				'sku'      => $sku,
				'quantity' => (int) $qty,
			);
		}
		$prices = $this->resolve( $items );

		$rows     = array();
		$previous = null;
		foreach ( $quantities as $qty ) {
			$unit = isset( $prices[ $sku . '|' . (int) $qty ] ) ? $prices[ $sku . '|' . (int) $qty ] : null;
			if ( null === $unit ) {
				continue;
			}
			// Only rungs that actually change the price are worth a row.
			if ( null !== $previous && abs( $unit - $previous ) < 0.00001 ) {
				continue;
			}
			$rows[]   = array(
				'qty'  => (int) $qty,
				'unit' => $unit,
			);
			$previous = $unit;
		}

		if ( count( $rows ) < 2 ) {
			// One row is just the unit price, which is already on the page.
			return;
		}

		echo '<table class="tackquote-quantity-breaks"><caption>'
			. esc_html__( 'Volume pricing', 'tackquote' )
			. '</caption><thead><tr><th scope="col">'
			. esc_html__( 'Quantity', 'tackquote' )
			. '</th><th scope="col">'
			. esc_html__( 'Unit price', 'tackquote' )
			. '</th></tr></thead><tbody>';

		foreach ( $rows as $row ) {
			echo '<tr><td>'
				/* translators: %d: minimum quantity for this price tier. */
				. esc_html( sprintf( __( '%d+', 'tackquote' ), $row['qty'] ) )
				. '</td><td>'
				// wc_price() returns markup that is already escaped by WooCommerce.
				. wp_kses_post( wc_price( Tack_Tax_Basis::display_price( $row['unit'], $product ) ) )
				. '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Render a `QuantityBreakResult` from `/storefront/v1/quantity-breaks`.
	 *
	 * Only a `priced` answer with at least two distinct rungs renders anything —
	 * `anonymous`, `unlinked` and `unpriced` (including a currency mismatch) all
	 * mean "no ladder for this shopper", and one rung is the unit price already on
	 * the page. `currency` formats each rung; `accountSpecific` is said in the
	 * caption, because a negotiated ladder shown without that word reads as a
	 * public price list.
	 *
	 * @param array  $result  Decoded v1 response.
	 * @param object $product WC_Product, for its tax class.
	 */
	public function render_v1_quantity_breaks( array $result, $product ) {
		$status = isset( $result['status'] ) ? (string) $result['status'] : '';
		if ( 'priced' !== $status || empty( $result['rows'] ) || ! is_array( $result['rows'] ) ) {
			return;
		}
		$currency         = isset( $result['currency'] ) && is_string( $result['currency'] ) ? strtoupper( $result['currency'] ) : '';
		$account_specific = ! empty( $result['accountSpecific'] );

		$rows = array();
		foreach ( $result['rows'] as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['minQty'] ) || ! isset( $row['unitPrice'] ) || ! is_numeric( $row['unitPrice'] ) ) {
				continue;
			}
			$rows[] = array(
				'qty'  => max( 1, (int) $row['minQty'] ),
				'unit' => (float) $row['unitPrice'],
			);
		}
		usort(
			$rows,
			function ( $a, $b ) {
				return $a['qty'] - $b['qty'];
			}
		);

		$distinct = array();
		$previous = null;
		foreach ( $rows as $row ) {
			if ( null !== $previous && abs( $row['unit'] - $previous ) < 0.00001 ) {
				continue;
			}
			$distinct[] = $row;
			$previous   = $row['unit'];
		}
		if ( count( $distinct ) < 2 ) {
			return;
		}

		$caption = $account_specific
			? __( 'Volume pricing for your account', 'tackquote' )
			: __( 'Volume pricing', 'tackquote' );
		$args    = '' !== $currency ? array( 'currency' => $currency ) : array();

		echo '<table class="tackquote-quantity-breaks' . ( $account_specific ? ' tackquote-quantity-breaks-account' : '' ) . '"><caption>'
			. esc_html( $caption )
			. '</caption><thead><tr><th scope="col">'
			. esc_html__( 'Quantity', 'tackquote' )
			. '</th><th scope="col">'
			. esc_html__( 'Unit price', 'tackquote' )
			. '</th></tr></thead><tbody>';

		foreach ( $distinct as $row ) {
			echo '<tr><td>'
				/* translators: %d: minimum quantity for this price tier. */
				. esc_html( sprintf( __( '%d+', 'tackquote' ), $row['qty'] ) )
				. '</td><td>'
				// wc_price() returns markup that is already escaped by WooCommerce.
				. wp_kses_post( wc_price( Tack_Tax_Basis::display_price( $row['unit'], $product ), $args ) )
				. '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Should this request be priced at all?
	 *
	 * @return bool
	 */
	private function should_apply() {
		if ( ! self::is_enabled() ) {
			return false;
		}
		// Admin screens and REST/cron must keep the store's own prices: an order
		// edited in wp-admin should show what was charged, not what Tack would
		// charge the shop manager who happens to be logged in.
		if ( is_admin() && ! wp_doing_ajax() ) {
			return false;
		}
		if ( '' === $this->buyer_email() ) {
			return false;
		}
		return '' !== (string) get_option( 'tack_quotes_api_key', '' );
	}

	/**
	 * Email of the signed-in customer TackQuote may price, or '' for nobody.
	 *
	 * Delegates to `Tack_B2B_Notices::trusted_buyer_email()`, the rule the badge,
	 * restrictions and price gate already apply: '' for a guest AND for an account
	 * whose address was self-changed on My Account and not re-confirmed
	 * (`_tack_email_unverified`). Before 1.9.0 this read `user_email` directly, so
	 * a new account that retyped an approved buyer's address was charged that
	 * buyer's price book in listings and the cart. '' here means "priced as a
	 * guest" (`should_apply()` stands down), never an outage.
	 *
	 * @return string
	 */
	private function buyer_email() {
		return Tack_B2B_Notices::trusted_buyer_email();
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
