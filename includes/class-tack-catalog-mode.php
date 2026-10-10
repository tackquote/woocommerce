<?php
/**
 * Quote-only (B2B catalog) mode, store-wide and per product.
 *
 * Turns the whole storefront into a request-a-quote catalogue: WooCommerce's
 * "Add to cart" action is withdrawn and the TackQuote buttons become the only
 * way to transact. This is what a seller turns on to run their entire store
 * as B2B rather than mixing retail checkout with quoting.
 *
 * Since 1.9.0 the same control also exists PER PRODUCT ("Quote only" in the
 * product data panel, meta `_tackquote_quote_only`, inherited by variations),
 * and the store-wide mode gained a fourth scope, "approved wholesale accounts",
 * which keeps the cart only for buyers whose wholesale application TackQuote
 * has approved.
 *
 * ── Why this is not just "hide the button" ───────────────────────────────────
 *
 * Hiding a button with CSS, or removing only the template, leaves the store
 * fully purchasable to anyone who sends the request by hand:
 * `?add-to-cart=123`, the Store API, a cached page, a stale block. The
 * enforcement here is therefore the `woocommerce_is_purchasable` filter, which
 * `WC_Cart::add_to_cart()` checks before accepting any line
 * (woocommerce/includes/class-wc-cart.php:1331, WooCommerce 11.0.1), and which
 * the Store API's `CartController::validate_add_to_cart()` checks too. Removing
 * the templates is presentation on top of that, not the control itself. The
 * Store API additionally fires `woocommerce_store_api_validate_add_to_cart`
 * (`src/StoreApi/Utilities/CartController.php`, WooCommerce 11.2.1: "Functions
 * hooking into this should throw an \Exception to prevent add to cart"), which
 * is hooked as well so a Blocks shopper reads a sentence that says WHY.
 *
 * ── The pre-existing-cart hole ───────────────────────────────────────────────
 *
 * `WC_Cart::check_cart_item_validity()` only checks that a product still
 * exists and is not trashed — it does NOT re-check `is_purchasable()`
 * (class-wc-cart.php:826-839). So a customer who filled a cart BEFORE the
 * store was switched to quote-only, or before a product was marked quote-only,
 * could still walk that cart through checkout. `check_cart()` below closes
 * that: it is hooked to `woocommerce_check_cart_items`, which runs on both the
 * cart and checkout pages.
 *
 * ── Who is exempt ────────────────────────────────────────────────────────────
 *
 * Anyone who can `manage_woocommerce` is exempt from the STORE-WIDE mode, so
 * the seller can still see and test their own store while it is closed to
 * customers. A per-product "Quote only" is a fact about the product, like
 * "sold individually", and applies to everyone including the seller: a product
 * the seller could buy but a customer could not would make the seller's own
 * test pass for a flow the customer never gets.
 *
 * @package TackQuote
 */

defined( 'ABSPATH' ) || exit;

/**
 * Quote-only (B2B catalogue) store mode.
 *
 * Withdraws "Add to cart" at the data layer (`woocommerce_is_purchasable`),
 * empties carts filled before the switch, and optionally replaces prices with
 * "Price on request". Sellers who can `manage_woocommerce` are exempt. See the
 * file header for why each of those is needed.
 */
class Tack_Catalog_Mode {

	const OPT_MODE       = 'tack_quotes_store_mode';
	const OPT_SCOPE      = 'tack_quotes_quote_only_scope';
	const OPT_ROLES      = 'tack_quotes_quote_only_roles';
	const OPT_HIDE_PRICE = 'tack_quotes_hide_prices';
	const OPT_PRICE_TEXT = 'tack_quotes_hidden_price_text';

	const MODE_CART       = 'cart';
	const MODE_QUOTE_ONLY = 'quote_only';

	const SCOPE_EVERYONE = 'everyone';
	const SCOPE_GUESTS   = 'guests';
	const SCOPE_ROLES    = 'roles';

	/**
	 * Quote-only for everyone EXCEPT a signed-in buyer whose wholesale application
	 * TackQuote has approved (`GET /storefront/v1/price-access` answers
	 * `linked` + `wholesaleApproved: true`).
	 *
	 * @since 1.9.0
	 */
	const SCOPE_UNAPPROVED = 'unapproved';

	/**
	 * Product meta holding the per-product switch. `'yes'` when on; absent otherwise.
	 *
	 * @since 1.9.0
	 */
	const META_QUOTE_ONLY = '_tackquote_quote_only';

	/**
	 * How long a `price-access` answer is remembered, in seconds. Approval is a
	 * seller action that happens once; five minutes is the longest an approved
	 * buyer waits to see the cart, and a page view in between costs nothing.
	 */
	const ACCESS_CACHE_TTL = 300;

	/** How long a FAILED `price-access` call is remembered, so an outage does not retry per page view. */
	const ACCESS_FAIL_TTL = 60;

	/**
	 * API client, injected in tests.
	 *
	 * @var Tack_Api_Client|null
	 */
	private $client;

	/**
	 * Per-request memo of `is_quote_only()` by product id.
	 *
	 * @var array<int, bool>
	 */
	private $quote_only = array();

	/**
	 * Per-request memo of the price-access answer: null until asked.
	 *
	 * @var bool|null
	 */
	private $approved = null;

	/**
	 * Constructor.
	 *
	 * @param Tack_Api_Client|null $client Injected in tests; built lazily otherwise.
	 */
	public function __construct( $client = null ) {
		$this->client = $client instanceof Tack_Api_Client ? $client : null;
	}

	/**
	 * Register hooks.
	 *
	 * Every callback re-checks `is_active()` at call time rather than the hooks
	 * being registered conditionally. `is_active()` depends on the current user,
	 * and the current user is not reliably resolved this early (`plugins_loaded`)
	 * — deciding once at registration would evaluate the wrong user, and would be
	 * wrong again for any request served from a page cache.
	 */
	public function init() {
		// The control itself.
		add_filter( 'woocommerce_is_purchasable', array( $this, 'filter_is_purchasable' ), 99, 2 );

		// The Store API's own validation hook (Cart and Checkout blocks, `wc/store/v1/cart/add-item`).
		add_action( 'woocommerce_store_api_validate_add_to_cart', array( $this, 'refuse_store_api_add_to_cart' ), 10, 2 );

		// Close the pre-existing-cart hole on both cart and checkout.
		add_action( 'woocommerce_check_cart_items', array( $this, 'check_cart' ) );

		// Presentation: withdraw the add-to-cart templates.
		add_action( 'wp', array( $this, 'remove_add_to_cart_templates' ) );

		// Optional "price on request", and the per-product "available on quote" label.
		add_filter( 'woocommerce_get_price_html', array( $this, 'filter_price_html' ), 99, 2 );

		// The per-product switch in the product data panel (General tab) and its save.
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'render_quote_only_field' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save_quote_only_field' ) );
	}

	/**
	 * Is quote-only mode in force for THIS request and THIS visitor?
	 *
	 * @return bool
	 */
	public function is_active() {
		if ( self::MODE_QUOTE_ONLY !== get_option( self::OPT_MODE, self::MODE_CART ) ) {
			return false;
		}

		// The seller keeps a working store to test with.
		if ( function_exists( 'current_user_can' ) && current_user_can( 'manage_woocommerce' ) ) {
			return false;
		}

		$scope = (string) get_option( self::OPT_SCOPE, self::SCOPE_EVERYONE );

		if ( self::SCOPE_GUESTS === $scope ) {
			// Quote-only for logged-out visitors; approved B2B customers keep the cart.
			return ! is_user_logged_in();
		}

		if ( self::SCOPE_ROLES === $scope ) {
			$selected = (array) get_option( self::OPT_ROLES, array() );
			if ( ! is_user_logged_in() ) {
				return in_array( 'guest', $selected, true );
			}
			$user = wp_get_current_user();
			return (bool) array_intersect( $selected, (array) $user->roles );
		}

		if ( self::SCOPE_UNAPPROVED === $scope ) {
			return ! $this->buyer_is_approved_wholesale();
		}

		return true;
	}

	/**
	 * Has TackQuote approved the signed-in buyer's wholesale application?
	 *
	 * Asks `GET /storefront/v1/price-access?buyerEmail=&buyerExternalId=` (`Tack_Api_Client::get_price_access()`).
	 * The server resolves an asserted email against its buyer records and answers
	 * `anonymous`, `unlinked`, or `linked` with `wholesaleApproved` — true only for a
	 * wholesale application in status `approved`; a net-terms approval or a checkout
	 * link alone is not approval (tack `storefront-price-access.spec.ts`,
	 * `storefront-buyer.ts` `findStorefrontBuyer`, case `asserted`).
	 *
	 * FAILS CLOSED. If TackQuote cannot be reached the buyer is treated as not
	 * approved and sees the quote-only catalogue. The other B2B lookups in this
	 * plugin fail open because an outage must not block checkout; a price gate is
	 * the one place the opposite holds, because the gate's purpose is to keep the
	 * cart from buyers the seller has not approved, and showing it to them during
	 * an outage cannot be undone. An approved buyer who hits the outage can still
	 * request a quote, and the failure is remembered for only ACCESS_FAIL_TTL.
	 *
	 * @since 1.9.0
	 *
	 * @return bool
	 */
	public function buyer_is_approved_wholesale() {
		if ( null !== $this->approved ) {
			return $this->approved;
		}
		$this->approved = false;

		$email = class_exists( 'Tack_B2B_Notices' ) ? Tack_B2B_Notices::trusted_buyer_email() : '';
		if ( '' === $email ) {
			return false;
		}
		if ( '' === (string) get_option( 'tack_quotes_api_key', '' ) ) {
			return false;
		}

		// Keyed on a salted hash of the address, never the address: a transient key is
		// visible in the options table and a shared object cache.
		$user_id = (int) get_current_user_id();
		$key     = 'tack_pa_' . substr( wp_hash( 'price-access|' . strtolower( $email ) . '|' . $user_id ), 0, 20 );
		$cached  = get_transient( $key );
		if ( is_array( $cached ) && array_key_exists( 'approved', $cached ) ) {
			$this->approved = (bool) $cached['approved'];
			return $this->approved;
		}
		if ( 'unavailable' === $cached ) {
			return false;
		}

		// `buyerEmail` + `buyerExternalId` (the WordPress user id) are added by the
		// client's shared asserted-buyer query, key in `X-Api-Key` only.
		$response = $this->client()->get_price_access( $email );
		if ( is_wp_error( $response ) ) {
			$this->log( 'price-access lookup failed: ' . $response->get_error_message() );
			set_transient( $key, 'unavailable', self::ACCESS_FAIL_TTL );
			return false;
		}

		$this->approved = isset( $response['status'] ) && 'linked' === $response['status']
			&& ! empty( $response['wholesaleApproved'] );
		set_transient( $key, array( 'approved' => $this->approved ), self::ACCESS_CACHE_TTL );
		return $this->approved;
	}

	/**
	 * Is THIS product sold on quote only?
	 *
	 * Reads the product's own meta first; a variation without its own answer
	 * inherits the parent's, so marking a variable product marks every size and
	 * colour of it. Memoised per request: `woocommerce_is_purchasable` fires for
	 * every product in a loop and for every line in a cart.
	 *
	 * @since 1.9.0
	 *
	 * @param WC_Product $product Product or variation.
	 * @return bool
	 */
	public function is_quote_only( $product ) {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_meta' ) ) {
			return false;
		}
		$id = method_exists( $product, 'get_id' ) ? (int) $product->get_id() : 0;
		if ( $id && array_key_exists( $id, $this->quote_only ) ) {
			return $this->quote_only[ $id ];
		}

		$own = (string) $product->get_meta( self::META_QUOTE_ONLY, true );
		$on  = ( 'yes' === $own );

		if ( ! $on && method_exists( $product, 'get_parent_id' ) ) {
			$parent_id = (int) $product->get_parent_id();
			if ( $parent_id > 0 && function_exists( 'wc_get_product' ) ) {
				$parent = wc_get_product( $parent_id );
				if ( is_object( $parent ) && method_exists( $parent, 'get_meta' ) ) {
					$on = ( 'yes' === (string) $parent->get_meta( self::META_QUOTE_ONLY, true ) );
				}
			}
		}

		if ( $id ) {
			$this->quote_only[ $id ] = $on;
		}
		return $on;
	}

	/**
	 * THE control. Refuses the line at the data layer, so a hand-crafted
	 * `?add-to-cart=` request, the Store API and any cached markup are all
	 * refused the same way the button is.
	 *
	 * @param bool       $purchasable Current value.
	 * @param WC_Product $product     Product being tested. Read for the per-product switch;
	 *                                the store-wide decision ignores it.
	 * @return bool
	 */
	public function filter_is_purchasable( $purchasable, $product = null ) {
		if ( $this->is_active() ) {
			return false;
		}
		if ( null !== $product && $this->is_quote_only( $product ) ) {
			return false;
		}
		return $purchasable;
	}

	/**
	 * The Store API's add-to-cart validation (Cart and Checkout blocks).
	 *
	 * `is_purchasable()` already refuses the line one step earlier in
	 * `CartController::validate_add_to_cart()`, with WooCommerce's generic
	 * "cannot be purchased" sentence. Throwing here is what lets a Blocks shopper
	 * read WHY, and it is the hook WooCommerce documents for refusing a Store API
	 * add-to-cart, so the refusal does not rest on one filter alone.
	 *
	 * @since 1.9.0
	 *
	 * @param WC_Product $product Product being added.
	 * @param array      $request Add-to-cart request (id, quantity, variation). Part of the
	 *                            documented signature; unused.
	 * @throws Exception When the product is quote-only for this visitor.
	 */
	public function refuse_store_api_add_to_cart( $product, $request = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $request is the second argument WooCommerce passes to woocommerce_store_api_validate_add_to_cart; kept so the callback matches the documented signature.
		if ( $this->is_active() ) {
			throw new Exception(
				esc_html__( 'This store is currently quote-only. Request a quote instead and we will get back to you.', 'tackquote' )
			);
		}
		if ( $this->is_quote_only( $product ) ) {
			throw new Exception(
				esc_html(
					sprintf(
						/* translators: %s: product name. */
						__( '%s is available on quote only. Request a quote instead and we will get back to you.', 'tackquote' ),
						method_exists( $product, 'get_name' ) ? (string) $product->get_name() : ''
					)
				)
			);
		}
	}

	/**
	 * Refuse a cart that was filled before the store, or a product in it, was
	 * switched to quote-only.
	 *
	 * Runs on `woocommerce_check_cart_items`, which fires on both the cart and
	 * the checkout page, so this cannot be walked past by going straight to
	 * checkout.
	 */
	public function check_cart() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		$store_wide = $this->is_active();
		$removed    = 0;
		$named      = array();
		foreach ( WC()->cart->get_cart() as $key => $item ) {
			$product = isset( $item['data'] ) ? $item['data'] : null;
			if ( ! is_object( $product ) ) {
				continue;
			}
			if ( $store_wide && method_exists( $product, 'is_purchasable' ) && ! $product->is_purchasable() ) {
				WC()->cart->remove_cart_item( $key );
				++$removed;
				continue;
			}
			if ( $this->is_quote_only( $product ) ) {
				WC()->cart->remove_cart_item( $key );
				$named[] = method_exists( $product, 'get_name' ) ? (string) $product->get_name() : '';
			}
		}

		if ( $removed > 0 ) {
			wc_add_notice(
				esc_html__( 'This store is currently quote-only, so those items were removed from your cart. Request a quote instead and we will get back to you.', 'tackquote' ),
				'notice'
			);
		}
		if ( ! empty( $named ) ) {
			wc_add_notice(
				esc_html(
					sprintf(
						/* translators: %s: comma-separated product names. */
						__( 'Available on quote only, so removed from your cart: %s. Request a quote instead and we will get back to you.', 'tackquote' ),
						implode( ', ', array_filter( $named ) )
					)
				),
				'notice'
			);
		}
	}

	/**
	 * Withdraw WooCommerce's add-to-cart templates on the shop loop and the
	 * product page.
	 *
	 * Hooked to `wp` rather than `init` because `is_active()` needs the resolved
	 * current user, and early enough that the storefront templates have not run.
	 *
	 * NOTE for anyone editing this: `woocommerce_after_add_to_cart_button` —
	 * where the TackQuote buttons normally render — fires INSIDE these very
	 * templates (see woocommerce/templates/single-product/add-to-cart/*.php).
	 * Removing the single-product template therefore also removes the quote
	 * button unless something re-hooks it, which would leave the store with no
	 * way to transact at all. `Tack_Widget` registers a fallback on
	 * `woocommerce_single_product_summary` for exactly this reason. Do not
	 * remove one without the other.
	 *
	 * A per-product quote-only needs no template work: WooCommerce's own
	 * add-to-cart templates return nothing for a product that is not purchasable,
	 * and the loop button becomes "Read more".
	 */
	public function remove_add_to_cart_templates() {
		if ( ! $this->is_active() ) {
			return;
		}

		remove_action( 'woocommerce_after_shop_loop_item', 'woocommerce_template_loop_add_to_cart', 10 );
		remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30 );
	}

	/**
	 * Optional "price on request" — some B2B sellers do not publish list prices —
	 * and, for a quote-only product, the "available on quote" label beside the price.
	 *
	 * @param string     $html    Rendered price HTML.
	 * @param WC_Product $product Product. Read for the per-product label; the store-wide
	 *                            replacement text is the same for every product.
	 * @return string
	 */
	public function filter_price_html( $html, $product = null ) {
		if ( $this->is_active() && 'yes' === get_option( self::OPT_HIDE_PRICE, 'no' ) ) {
			$text = (string) get_option( self::OPT_PRICE_TEXT, '' );
			if ( '' === trim( $text ) ) {
				$text = __( 'Price on request', 'tackquote' );
			}

			return '<span class="tack-price-on-request">' . esc_html( $text ) . '</span>';
		}

		if ( null !== $product && $this->is_quote_only( $product ) ) {
			/**
			 * Filters the label shown beside the price of a quote-only product.
			 *
			 * @since 1.9.0
			 *
			 * @param string     $label   Default "Available on quote".
			 * @param WC_Product $product The product.
			 */
			$label = (string) apply_filters( 'tack_quotes_quote_only_label', __( 'Available on quote', 'tackquote' ), $product );
			if ( '' !== trim( $label ) ) {
				$html .= ' <span class="tack-quote-only-cta">' . esc_html( $label ) . '</span>';
			}
		}

		return $html;
	}

	/**
	 * The "Quote only" checkbox in the product data panel (General tab).
	 *
	 * `woocommerce_wp_checkbox()` is WooCommerce's own field helper for this panel;
	 * `$product_object` is the global the panel's own fields read.
	 *
	 * @since 1.9.0
	 */
	public function render_quote_only_field() {
		global $product_object;
		if ( ! function_exists( 'woocommerce_wp_checkbox' ) ) {
			return;
		}
		$value = is_object( $product_object ) && method_exists( $product_object, 'get_meta' )
			? (string) $product_object->get_meta( self::META_QUOTE_ONLY, true )
			: '';

		echo '<div class="options_group tack-quote-only-field">';
		woocommerce_wp_checkbox(
			array(
				'id'          => self::META_QUOTE_ONLY,
				'label'       => __( 'Quote only', 'tackquote' ),
				'description' => __( 'Hide Add to cart for this product and take quote requests instead. Variations follow the parent.', 'tackquote' ),
				'value'       => 'yes' === $value ? 'yes' : 'no',
				'cbvalue'     => 'yes',
			)
		);
		echo '</div>';
	}

	/**
	 * Save the checkbox when the product is saved from its edit screen.
	 *
	 * Hooked to `woocommerce_admin_process_product_object`, which WooCommerce fires
	 * with the product object just before `$product->save()`
	 * (includes/admin/meta-boxes/class-wc-meta-box-product-data.php, 11.2.1), so an
	 * `update_meta_data()` here is persisted by that save. WooCommerce has already
	 * verified `woocommerce_meta_nonce` and the user's capability before firing it;
	 * both are checked again here because this method is public and the cost is nil.
	 *
	 * @since 1.9.0
	 *
	 * @param WC_Product $product The product being saved.
	 */
	public function save_quote_only_field( $product ) {
		if ( ! is_object( $product ) || ! method_exists( $product, 'update_meta_data' ) ) {
			return;
		}
		if ( ! isset( $_POST['woocommerce_meta_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ), 'woocommerce_save_data' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', (int) $product->get_id() ) ) {
			return;
		}

		$on = isset( $_POST[ self::META_QUOTE_ONLY ] )
			&& 'yes' === sanitize_text_field( wp_unslash( $_POST[ self::META_QUOTE_ONLY ] ) );

		if ( $on ) {
			$product->update_meta_data( self::META_QUOTE_ONLY, 'yes' );
		} elseif ( method_exists( $product, 'delete_meta_data' ) ) {
			$product->delete_meta_data( self::META_QUOTE_ONLY );
		}
		unset( $this->quote_only[ (int) $product->get_id() ] );
	}

	/**
	 * The API client, built on first use so a store that never selects the
	 * approved-wholesale scope never constructs one.
	 *
	 * @return Tack_Api_Client
	 */
	private function client() {
		if ( null === $this->client ) {
			$this->client = new Tack_Api_Client();
		}
		return $this->client;
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
