<?php
/**
 * Quick order (1.12.0): a trade buyer types or pastes SKUs and quantities, then
 * chooses where they go: the store's cart, or the quote list.
 *
 * Mounted as the `[tackquote_quick_order]` shortcode and the "TackQuote quick
 * order" block (`tackquote/quick-order`, a dynamic block rendered by the same
 * code). Both print one form; `assets/js/tack-quick-order.js` drives it.
 *
 * ── Where a SKU is resolved, and why not on TackQuote ──────────────────────
 * The store's own catalogue is the authority for what its cart can take, so a
 * SKU is resolved HERE with `wc_get_product_id_by_sku()`. TackQuote's shared
 * `GET /storefront/v1/quick-order` answers `501 Not Implemented` for an API-key
 * caller (the API-key identity resolver has no catalogue binding from a SKU to a
 * WooCommerce cart item; tack `storefront-v1.controller.ts` `quickOrder`), and its
 * catalogue rows exist only for stores that synced through the WooCommerce REST
 * API, so it could not resolve a SKU for a plugin-only store or a guest at all.
 *
 * What the buyer is charged is not decided here either: an added line is priced
 * by the cart, where the TackQuote wholesale price, quantity breaks, order limits
 * and the quote-only switch already apply (Tack_Wholesale_Pricing,
 * Tack_B2B_Notices, Tack_Catalog_Mode). The preview shows the product's price
 * HTML, which carries the same display filters (and the price gate).
 *
 * ── Variants ────────────────────────────────────────────────────────────────
 * A variation's SKU is orderable as it is. A variable product's own SKU names no
 * orderable item (which size?), the `needs_variant` rule TackQuote applies to a
 * variant parent: the row links to the product page to choose options, exactly
 * like the product-card button, and is never added.
 *
 * Both AJAX actions run on WooCommerce's `?wc-ajax=` endpoint, where the shopper's
 * cart session is loaded, with the storefront nonce (refreshable like the quote
 * modal's) and a per-client rate limit on the lookup.
 *
 * @package TackQuotes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Quick order shortcode, block and AJAX handlers.
 */
class Tack_Quick_Order {

	/** Shortcode tag. */
	const SHORTCODE = 'tackquote_quick_order';

	/** Block name. */
	const BLOCK = 'tackquote/quick-order';

	/** The wc-ajax action that resolves SKUs. */
	const ACTION_LOOKUP = 'tack_quick_order_lookup';

	/** The wc-ajax action that adds resolved lines to the cart. */
	const ACTION_ADD = 'tack_quick_order_add';

	/** Most lines one request may carry (TackQuote's quick-order cap is the same 50). */
	const MAX_LINES = 50;

	/** Longest SKU accepted (TackQuote's quick-order cap). */
	const MAX_SKU_LENGTH = 64;

	/** Largest quantity accepted on one line. */
	const MAX_QUANTITY = 100000;

	/** Lookups per client per window. */
	const RATE_LIMIT_MAX = 30;

	/** Window, in seconds. */
	const RATE_LIMIT_WINDOW = 300;

	/** Script handles. */
	const SCRIPT        = 'tackquote-quick-order';
	const EDITOR_SCRIPT = 'tackquote-quick-order-editor';

	/**
	 * Hooks.
	 */
	public function init() {
		add_shortcode( self::SHORTCODE, array( $this, 'render' ) );
		add_action( 'init', array( $this, 'register_block' ) );
		add_action( 'wc_ajax_' . self::ACTION_LOOKUP, array( $this, 'handle_lookup' ) );
		add_action( 'wc_ajax_' . self::ACTION_ADD, array( $this, 'handle_add' ) );
	}

	/**
	 * Register the dynamic block. No build step: the editor script is plain
	 * JavaScript using `wp.blocks`, `wp.element` and `wp.serverSideRender`, and the
	 * front end is rendered by `render()`.
	 */
	public function register_block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		wp_register_script(
			self::EDITOR_SCRIPT,
			TACK_QUOTES_URL . 'assets/js/tack-quick-order-block.js',
			array( 'wp-blocks', 'wp-element', 'wp-server-side-render', 'wp-block-editor' ),
			TACK_QUOTES_VERSION,
			true
		);
		register_block_type(
			self::BLOCK,
			array(
				'api_version'     => 3,
				'title'           => __( 'TackQuote quick order', 'tackquote' ),
				'description'     => __( 'Buyers enter SKUs and quantities, then add them to the cart or to the quote list.', 'tackquote' ),
				'icon'            => 'list-view',
				'category'        => 'woocommerce',
				'editor_script'   => self::EDITOR_SCRIPT,
				'render_callback' => array( $this, 'render_block' ),
				'supports'        => array(
					'html'  => false,
					'align' => array( 'wide', 'full' ),
				),
			)
		);
	}

	/**
	 * Block render callback.
	 *
	 * @return string
	 */
	public function render_block() {
		return $this->render();
	}

	/**
	 * The quick-order form.
	 *
	 * @return string
	 */
	public function render() {
		$this->enqueue();
		$args = array(
			'rows'        => 5,
			'max_lines'   => self::MAX_LINES,
			'button'      => Tack_Widget::button_class( 'tack-quick-order-lookup' ),
			'cart_button' => Tack_Widget::button_class( 'alt tack-quick-order-cart' ),
			'quote_label' => Tack_Widget::button_label( 'tack_quotes_button_label' ),
			'quote'       => Tack_Widget::button_class( 'tack-quick-order-quote' ),
			'secondary'   => Tack_Widget::button_class( 'tack-quick-order-add-row' ),
		);

		/**
		 * Filters what the quick-order template receives.
		 *
		 * @since 1.12.0
		 *
		 * @param array $args See templates/tackquote/quick-order.php.
		 */
		$args = array_merge( $args, (array) apply_filters( 'tackquote_quick_order_args', $args ) );
		return Tack_Templates::html( 'quick-order.php', $args );
	}

	/**
	 * The form's script and its settings.
	 */
	private function enqueue() {
		if ( ! function_exists( 'wp_enqueue_script' ) ) {
			return;
		}
		Tack_Widget::enqueue_styles();
		// tack-quotes.js owns the quote list (`window.TackQuoteList`); load it first.
		wp_enqueue_script( self::SCRIPT, TACK_QUOTES_URL . 'assets/js/tack-quick-order.js', array( 'jquery', 'wp-i18n', 'tackquote' ), TACK_QUOTES_VERSION, true );
		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( self::SCRIPT, 'tackquote' );
		}
		if ( ! function_exists( 'wp_localize_script' ) ) {
			return;
		}
		wp_localize_script(
			self::SCRIPT,
			'TackQuickOrder',
			array(
				'lookupUrl' => class_exists( 'WC_AJAX' ) ? WC_AJAX::get_endpoint( self::ACTION_LOOKUP ) : '',
				'addUrl'    => class_exists( 'WC_AJAX' ) ? WC_AJAX::get_endpoint( self::ACTION_ADD ) : '',
				'cartUrl'   => function_exists( 'wc_get_cart_url' ) ? (string) wc_get_cart_url() : '',
				'maxLines'  => self::MAX_LINES,
			)
		);
	}

	/**
	 * Refuse a request without the storefront nonce: JSON with `reload`, like the quote modal.
	 */
	private function verify_nonce() {
		if ( ! check_ajax_referer( 'tack_request_quote', 'nonce', false ) ) {
			wp_send_json_error(
				array(
					'code'    => 'tack_nonce_expired',
					'message' => __( 'This page has been open too long, or was served from a cache. Reload it and try again.', 'tackquote' ),
					'reload'  => true,
				),
				403
			);
		}
	}

	/**
	 * The wc-ajax handler that resolves the posted SKUs.
	 */
	public function handle_lookup() {
		$this->verify_nonce();
		if ( Tack_Rate_Limit::exceeded( 'tack_qo_', 'quick-order', self::RATE_LIMIT_MAX ) ) {
			wp_send_json_error(
				array(
					'code'    => 'tack_rate_limited',
					'message' => __( 'Too many look-ups from this connection. Please wait a few minutes and try again.', 'tackquote' ),
				),
				429
			);
		}
		Tack_Rate_Limit::hit( 'tack_qo_', 'quick-order', self::RATE_LIMIT_MAX, self::RATE_LIMIT_WINDOW );
		$raw = isset( $_POST['lines'] ) ? sanitize_textarea_field( wp_unslash( $_POST['lines'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked by verify_nonce() at the top of this handler.
		$out = $this->lookup( self::parse_lines( $raw ) );
		wp_send_json_success( array( 'lines' => $out ) );
	}

	/**
	 * The posted `[{sku, quantity}]` JSON as clean rows: trimmed SKUs, quantities 1..MAX,
	 * blank rows dropped, at most MAX_LINES.
	 *
	 * @param string $json Raw JSON.
	 * @return array<int, array{sku:string,quantity:int}>
	 */
	public static function parse_lines( $json ) {
		if ( strlen( (string) $json ) > 32768 ) {
			return array();
		}
		$decoded = json_decode( (string) $json, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}
		$rows = array();
		foreach ( $decoded as $row ) {
			if ( count( $rows ) >= self::MAX_LINES ) {
				break;
			}
			if ( ! is_array( $row ) || ! isset( $row['sku'] ) || ! is_scalar( $row['sku'] ) ) {
				continue;
			}
			$sku = trim( sanitize_text_field( (string) $row['sku'] ) );
			if ( '' === $sku || strlen( $sku ) > self::MAX_SKU_LENGTH ) {
				continue;
			}
			$qty    = isset( $row['quantity'] ) && is_numeric( $row['quantity'] ) ? (int) $row['quantity'] : 1;
			$rows[] = array(
				'sku'      => $sku,
				'quantity' => max( 1, min( self::MAX_QUANTITY, $qty ) ),
			);
		}
		return $rows;
	}

	/**
	 * Resolve rows against the store's catalogue.
	 *
	 * Statuses: `found` (orderable: the product, or the variation the SKU names),
	 * `needs_options` (a variable product's own SKU, or a grouped one: choose on its
	 * page), `not_found` (no product, a draft, private or password-protected one, one
	 * hidden from the buyer's group, or one WooCommerce's catalog visibility hides).
	 *
	 * @param array $rows parse_lines() output.
	 * @return array
	 */
	public function lookup( array $rows ) {
		$out = array();
		foreach ( $rows as $row ) {
			$out[] = $this->resolve( $row['sku'], $row['quantity'] );
		}
		return $out;
	}

	/**
	 * One SKU.
	 *
	 * @param string $sku      SKU as typed.
	 * @param int    $quantity Quantity.
	 * @return array
	 */
	private function resolve( $sku, $quantity ) {
		$base = array(
			'sku'      => $sku,
			'quantity' => (int) $quantity,
		);
		$id   = function_exists( 'wc_get_product_id_by_sku' ) ? (int) wc_get_product_id_by_sku( $sku ) : 0;
		$item = $id > 0 ? wc_get_product( $id ) : null;
		if ( ! self::is_product( $item ) ) {
			return $base + array( 'status' => 'not_found' );
		}
		$parent = null;
		if ( $item instanceof WC_Product_Variation ) {
			$parent = wc_get_product( $item->get_parent_id() );
			if ( ! self::is_product( $parent ) ) {
				return $base + array( 'status' => 'not_found' );
			}
		}
		if ( ! self::visible( $item, $parent ) ) {
			return $base + array( 'status' => 'not_found' );
		}
		$name = (string) $item->get_name();
		if ( $item->is_type( 'variable' ) || $item->is_type( 'grouped' ) || $item->is_type( 'external' ) ) {
			return $base + array(
				'status' => 'needs_options',
				'name'   => $name,
				'url'    => (string) get_permalink( $item->get_id() ),
			);
		}
		$purchasable = method_exists( $item, 'is_purchasable' ) && $item->is_purchasable()
			&& ( ! method_exists( $item, 'is_in_stock' ) || $item->is_in_stock() );
		return $base + array(
			'status'      => 'found',
			'productId'   => null !== $parent ? (int) $parent->get_id() : (int) $item->get_id(),
			'variationId' => null !== $parent ? (int) $item->get_id() : 0,
			'name'        => $name,
			'productSku'  => (string) $item->get_sku(),
			'priceHtml'   => wp_kses_post( (string) $item->get_price_html() ),
			// What the quote list shows for the row, like the card button's data-product-price;
			// withheld while the store hides prices from this visitor.
			'price'       => self::prices_hidden() ? null : (float) wc_get_price_excluding_tax( $item ),
			'purchasable' => $purchasable,
			'url'         => (string) get_permalink( null !== $parent ? $parent->get_id() : $item->get_id() ),
		);
	}

	/**
	 * May this visitor find the product by SKU here?
	 *
	 * The quote buttons' rule (Tack_Widget::is_quotable(): published, not
	 * password-protected, not hidden from the buyer's group), AND WooCommerce's own
	 * catalogue visibility (Fable P4): a product set to "Hidden" in Catalog
	 * visibility, or one WooCommerce hides out of stock, is not offered here, through
	 * `WC_Product::is_visible()` (the parent's, for a variation).
	 *
	 * @param object      $item   The product, or the variation.
	 * @param object|null $parent_product The variation's parent.
	 * @return bool
	 */
	private static function visible( $item, $parent_product ) {
		if ( ! Tack_Widget::is_quotable( $item, $parent_product ) ) {
			return false;
		}
		$listed = null !== $parent_product ? $parent_product : $item;
		return ! method_exists( $listed, 'is_visible' ) || (bool) $listed->is_visible();
	}

	/**
	 * Is this what wc_get_product() returns for a product (not false or null)?
	 *
	 * @param mixed $item wc_get_product() result.
	 * @return bool
	 */
	private static function is_product( $item ) {
		return is_object( $item ) && method_exists( $item, 'get_id' ) && method_exists( $item, 'is_type' );
	}

	/**
	 * Does quote-only mode hide prices from this visitor?
	 *
	 * @return bool
	 */
	private static function prices_hidden() {
		if ( ! class_exists( 'Tack_Catalog_Mode' ) || 'yes' !== get_option( Tack_Catalog_Mode::OPT_HIDE_PRICE, 'no' ) ) {
			return false;
		}
		return ( new Tack_Catalog_Mode() )->is_active();
	}

	/**
	 * The wc-ajax handler that adds the posted lines to the cart.
	 */
	public function handle_add() {
		$this->verify_nonce();
		// Each add runs WooCommerce's cart code per line: limited like the lookup (Fable P10).
		if ( Tack_Rate_Limit::exceeded( 'tack_qa_', 'quick-order-add', self::RATE_LIMIT_MAX ) ) {
			wp_send_json_error(
				array(
					'code'    => 'tack_rate_limited',
					'message' => __( 'Too many look-ups from this connection. Please wait a few minutes and try again.', 'tackquote' ),
				),
				429
			);
		}
		Tack_Rate_Limit::hit( 'tack_qa_', 'quick-order-add', self::RATE_LIMIT_MAX, self::RATE_LIMIT_WINDOW );
		if ( ! is_object( $this->cart() ) ) {
			wp_send_json_error( array( 'message' => __( 'The cart is not available right now. Please reload the page and try again.', 'tackquote' ) ), 503 );
		}
		$raw  = isset( $_POST['lines'] ) ? sanitize_textarea_field( wp_unslash( $_POST['lines'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked by verify_nonce() at the top of this handler.
		$rows = self::parse_add_lines( $raw );
		if ( empty( $rows ) ) {
			wp_send_json_error( array( 'message' => __( 'Nothing to add.', 'tackquote' ) ), 400 );
		}
		wp_send_json_success( $this->add_to_cart( $rows ) );
	}

	/**
	 * The posted `[{product_id, variation_id, quantity}]` JSON as clean rows.
	 *
	 * @param string $json Raw JSON.
	 * @return array<int, array{product_id:int,variation_id:int,quantity:int}>
	 */
	public static function parse_add_lines( $json ) {
		if ( strlen( (string) $json ) > 32768 ) {
			return array();
		}
		$decoded = json_decode( (string) $json, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}
		$rows = array();
		foreach ( $decoded as $row ) {
			if ( count( $rows ) >= self::MAX_LINES ) {
				break;
			}
			if ( ! is_array( $row ) || empty( $row['product_id'] ) ) {
				continue;
			}
			$qty    = isset( $row['quantity'] ) && is_numeric( $row['quantity'] ) ? (int) $row['quantity'] : 1;
			$rows[] = array(
				'product_id'   => absint( $row['product_id'] ),
				'variation_id' => isset( $row['variation_id'] ) ? absint( $row['variation_id'] ) : 0,
				'quantity'     => max( 1, min( self::MAX_QUANTITY, $qty ) ),
			);
		}
		return $rows;
	}

	/**
	 * Add rows to WooCommerce's cart, one by one, through WooCommerce's own
	 * validation: `woocommerce_add_to_cart_validation` (what the add-to-cart form
	 * handler applies; the quote-checkout and other plugins hook it) and
	 * `WC_Cart::add_to_cart()` itself (purchasable, so quote-only refuses; stock;
	 * sold individually). The ids are re-checked here: a variation must belong to
	 * the product named, and both must be quotable (visible) for this visitor.
	 *
	 * Notices WooCommerce raises are returned per line and then removed, so they
	 * do not also appear on the next page; the cart's own checks (order limits,
	 * quote-only) run once at the end and come back as `warnings`.
	 *
	 * @param array $rows parse_add_lines() output.
	 * @return array{added:int,lines:array,warnings:string[],cartUrl:string}
	 */
	public function add_to_cart( array $rows ) {
		$before = function_exists( 'wc_get_notices' ) ? wc_get_notices() : array();
		$lines  = array();
		$added  = 0;
		foreach ( $rows as $row ) {
			if ( function_exists( 'wc_clear_notices' ) ) {
				wc_clear_notices();
			}
			$result  = $this->add_row( $row );
			$errors  = self::error_notices();
			$lines[] = array(
				'productId'   => $row['product_id'],
				'variationId' => $row['variation_id'],
				'added'       => $result,
				'message'     => $result ? '' : ( '' !== $errors ? $errors : __( 'This product could not be added to the cart.', 'tackquote' ) ),
			);
			if ( $result ) {
				++$added;
			}
		}

		$warnings = array();
		$cart     = $this->cart();
		if ( $added > 0 && is_object( $cart ) && method_exists( $cart, 'check_cart_items' ) ) {
			if ( function_exists( 'wc_clear_notices' ) ) {
				wc_clear_notices();
			}
			$cart->check_cart_items();
			$text = self::error_notices();
			if ( '' !== $text ) {
				$warnings[] = $text;
			}
		}
		if ( function_exists( 'wc_clear_notices' ) ) {
			wc_clear_notices();
		}
		if ( function_exists( 'wc_set_notices' ) && ! empty( $before ) ) {
			wc_set_notices( $before );
		}

		return array(
			'added'    => $added,
			'lines'    => $lines,
			'warnings' => $warnings,
			'cartUrl'  => function_exists( 'wc_get_cart_url' ) ? (string) wc_get_cart_url() : '',
		);
	}

	/**
	 * Add one row; false when WooCommerce (or this check) refused it.
	 *
	 * @param array $row One parse_add_lines() row.
	 * @return bool
	 */
	private function add_row( array $row ) {
		$product = wc_get_product( $row['product_id'] );
		if ( ! self::is_product( $product ) ) {
			return false;
		}
		$attributes = array();
		if ( $row['variation_id'] > 0 ) {
			$variation = wc_get_product( $row['variation_id'] );
			if ( ! $variation instanceof WC_Product_Variation || (int) $variation->get_parent_id() !== (int) $product->get_id() ) {
				return false;
			}
			if ( ! self::visible( $variation, $product ) ) {
				return false;
			}
			$attributes = function_exists( 'wc_get_product_variation_attributes' ) ? (array) wc_get_product_variation_attributes( $variation->get_id() ) : array();
		} elseif ( $product->is_type( 'variable' ) || ! self::visible( $product, null ) ) {
			return false;
		}

		/**
		 * Filters whether a product may be added to the cart: WooCommerce's own filter
		 * (woocommerce/includes/class-wc-form-handler.php), applied as its add-to-cart
		 * handler applies it.
		 *
		 * @since 1.12.0
		 *
		 * @param bool  $passed       Passed so far.
		 * @param int   $product_id   Product.
		 * @param int   $quantity     Quantity.
		 * @param int   $variation_id Variation, or 0.
		 * @param array $variation    Variation attributes.
		 */
		$passed = apply_filters( 'woocommerce_add_to_cart_validation', true, $row['product_id'], $row['quantity'], $row['variation_id'], $attributes ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own filter, applied as its add-to-cart handler does.
		if ( ! $passed ) {
			return false;
		}
		try {
			$cart = $this->cart();
			return is_object( $cart ) && false !== $cart->add_to_cart( $row['product_id'], $row['quantity'], $row['variation_id'], $attributes );
		} catch ( Exception $e ) {
			if ( function_exists( 'wc_add_notice' ) ) {
				wc_add_notice( $e->getMessage(), 'error' );
			}
			return false;
		}
	}

	/**
	 * WooCommerce's cart for this session, or null.
	 *
	 * @return object|null
	 */
	protected function cart() {
		return function_exists( 'WC' ) && is_object( WC() ) && isset( WC()->cart ) && is_object( WC()->cart ) ? WC()->cart : null;
	}

	/**
	 * WooCommerce's current error notices as plain text, one per line.
	 *
	 * @return string
	 */
	private static function error_notices() {
		if ( ! function_exists( 'wc_get_notices' ) ) {
			return '';
		}
		$texts = array();
		foreach ( (array) wc_get_notices( 'error' ) as $notice ) {
			$message = is_array( $notice ) && isset( $notice['notice'] ) ? $notice['notice'] : $notice;
			$text    = trim( html_entity_decode( wp_strip_all_tags( (string) $message ), ENT_QUOTES, 'UTF-8' ) );
			if ( '' !== $text ) {
				$texts[] = $text;
			}
		}
		return implode( "\n", $texts );
	}
}
