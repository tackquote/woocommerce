<?php
/**
 * Frontend quote buttons:
 *  - "Add to Quote" (product page) — adds the product to a separate,
 *    browser-side "quote list" (localStorage), kept deliberately apart from
 *    the WooCommerce purchase cart so quoting a product never touches stock,
 *    cart totals, or normal checkout.
 *  - "Request a Quote" (product page) — submits a quote for just that one
 *    product immediately.
 *  - "Checkout as Quote" (floating quote-list drawer, shown site-wide) —
 *    submits every item currently in the quote list as one TackQuote request.
 *  - Since 1.9.0, all OFF by default: "Add to Quote" on product cards in the
 *    shop/category/search loops, "Request a quote for your cart" on the cart
 *    page (classic template and Cart block), the launcher's position, size,
 *    label, pages and mobile behaviour, and a quote PAGE rendered by the
 *    `[tackquote_quote_page]` shortcode that shares the drawer's list and adds
 *    quantity editing, an optional target price per line and a message.
 * Plus the AJAX handlers that create the quote request either way and re-price
 * the list for a signed-in buyer.
 *
 * @package TackQuotes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the quote buttons and handles quote-request submissions.
 */
class Tack_Widget {

	/**
	 * Quote requests allowed per client per RATE_LIMIT_WINDOW seconds.
	 */
	const RATE_LIMIT_MAX = 5;

	/**
	 * Length of the rate-limit window, in seconds.
	 */
	const RATE_LIMIT_WINDOW = 300;

	/**
	 * Hard ceiling on the free-text note, in characters.
	 */
	const NOTE_MAX_LENGTH = 2000;

	/**
	 * Products whose quote buttons have already been rendered this request, so
	 * the two mount points in init() cannot double-render.
	 *
	 * @var int[]
	 */
	private $rendered_product_ids = array();

	/**
	 * Hard ceiling on line items accepted from one quote-list submission.
	 */
	const ITEMS_MAX = 100;

	/**
	 * Hard ceiling on the raw `items` JSON, in bytes, checked before json_decode().
	 */
	const ITEMS_MAX_BYTES = 65536;

	// ── 1.9.0 storefront layout options. Every default reproduces the 1.8.x storefront. ──

	/** "Add to Quote" on product cards in the shop, category and search loops. */
	const OPT_CARD_BUTTONS = 'tack_quotes_card_buttons';

	/** "Request a quote for your cart" on the cart page. */
	const OPT_CART_BUTTON = 'tack_quotes_cart_quote_button';

	/** Label of the cart-page button. */
	const OPT_CART_BUTTON_LABEL = 'tack_quotes_cart_button_label';

	/** What the launcher and the card/cart buttons open: `drawer` or `page`. */
	const OPT_OPENS = 'tack_quotes_quote_button_opens';

	/** URL of the merchant page carrying `[tackquote_quote_page]`. */
	const OPT_PAGE_URL = 'tack_quotes_quote_page_url';

	const OPT_FAB_POSITION    = 'tack_quotes_fab_position';
	const OPT_FAB_OFFSET_X    = 'tack_quotes_fab_offset_x';
	const OPT_FAB_OFFSET_Y    = 'tack_quotes_fab_offset_y';
	const OPT_FAB_PAGES       = 'tack_quotes_fab_pages';
	const OPT_FAB_LABEL       = 'tack_quotes_fab_label';
	const OPT_FAB_ICON_ONLY   = 'tack_quotes_fab_icon_only';
	const OPT_FAB_SHOW_COUNT  = 'tack_quotes_fab_show_count';
	const OPT_FAB_SIZE        = 'tack_quotes_fab_size';
	const OPT_FAB_HIDE_MOBILE = 'tack_quotes_fab_hide_mobile';

	/** Largest launcher offset accepted, in px (the shared widget's bound). */
	const FAB_OFFSET_MAX = 200;

	/**
	 * Set once the classic cart template rendered the cart-page button, so the
	 * `wp_footer` fallback for the Cart block does not render a second one.
	 *
	 * @var bool
	 */
	private $cart_button_rendered = false;

	/**
	 * Target prices collected while building the quote-list line items, so they can
	 * be written into the request note (the plugin DTO has no per-line field).
	 *
	 * @var array<int, array{name:string,sku:string,quantity:int,target:float}>
	 */
	private $target_prices = array();

	/**
	 * Hook registration.
	 */
	public function init() {
		add_action( 'woocommerce_after_add_to_cart_button', array( $this, 'render_product_button' ) );

		/*
		 * Fallback mount point for quote-only mode.
		 *
		 * `woocommerce_after_add_to_cart_button` fires INSIDE WooCommerce's
		 * add-to-cart templates (templates/single-product/add-to-cart/*.php). In
		 * quote-only mode `Tack_Catalog_Mode` withdraws those templates, so that
		 * hook never fires and the quote button would vanish with the cart button
		 * — leaving a storefront with NO way to transact at all. This re-mounts it
		 * at the position the add-to-cart form used to occupy (priority 30 on
		 * `woocommerce_single_product_summary`).
		 *
		 * `render_product_button()` is idempotent per product, so on a normal
		 * store — where both hooks fire — the button still renders exactly once.
		 */
		add_action( 'woocommerce_single_product_summary', array( $this, 'render_product_button_fallback' ), 30 );

		/*
		 * Block themes (1.9.0). The single-product template is blocks, and neither
		 * hook above is a reliable mount there: see `Tack_Block_Product`. The Add to
		 * Cart blocks' own render filter puts the buttons after the form; when the
		 * form was rendered (a purchasable product) the buttons are already inside it
		 * from `woocommerce_after_add_to_cart_button`, and the once-per-product flag
		 * makes this a no-op.
		 */
		foreach ( Tack_Block_Product::ADD_TO_CART_BLOCKS as $block_name ) {
			add_filter( 'render_block_' . $block_name, array( $this, 'append_to_add_to_cart_block' ), 10, 3 );
		}

		/*
		 * Variable product on quote (1.9.0): `Tack_Catalog_Mode` replaces the
		 * variation form's cart controls and fires this where the buttons go, inside
		 * the form, beside the quantity, so the JS reads the chosen variation.
		 */
		add_action( 'tackquote_variation_quote_controls', array( $this, 'render_product_button' ) );
		add_action( 'wp_footer', array( $this, 'render_quote_list_drawer' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		/*
		 * Product cards (1.9.0, off by default). `woocommerce_after_shop_loop_item`
		 * is the hook WooCommerce's own loop button uses, at priority 10
		 * (templates/content-product.php: "@hooked woocommerce_template_loop_add_to_cart
		 * - 10"); 11 puts the quote control directly after it. The callback checks the
		 * option itself, so a cached decision cannot outlive a settings change.
		 */
		add_action( 'woocommerce_after_shop_loop_item', array( $this, 'render_card_button' ), 11 );

		/*
		 * Cart page (1.9.0, off by default). The classic cart fires
		 * `woocommerce_proceed_to_checkout` inside `.wc-proceed-to-checkout`
		 * (templates/cart/cart-totals.php) with the Proceed button at priority 20
		 * (includes/wc-template-hooks.php); 25 places the quote control under it. The
		 * Cart BLOCK renders no PHP hook at that spot — UNVERIFIED: no Cart block
		 * extensibility slot for a server-rendered control was confirmed — so a
		 * `wp_footer` fallback renders a fixed control on `is_cart()` when the classic
		 * hook did not fire. The fallback does not depend on such a slot existing.
		 */
		add_action( 'woocommerce_proceed_to_checkout', array( $this, 'render_cart_quote_button' ), 25 );
		add_action( 'wp_footer', array( $this, 'render_cart_quote_button_footer' ), 5 );

		// The quote page (1.9.0): a shortcode the merchant places on a page of their own.
		add_shortcode( 'tackquote_quote_page', array( $this, 'render_quote_page' ) );

		// AJAX (logged-in and guest).
		add_action( 'wp_ajax_tack_request_quote', array( $this, 'handle_request' ) );
		add_action( 'wp_ajax_nopriv_tack_request_quote', array( $this, 'handle_request' ) );

		// Re-pricing the list at the line quantity: signed-in buyers only, so no nopriv route.
		add_action( 'wp_ajax_tack_quote_reprice', array( $this, 'handle_reprice' ) );

		/*
		 * Nonce refresh, on WooCommerce's own `?wc-ajax=` endpoint rather than
		 * admin-ajax.php.
		 *
		 * This is the actual cure for the cached-nonce failure. The submit nonce is printed
		 * into the page by wp_localize_script(), so on a full-page-cached store it is baked
		 * into cached HTML and stops verifying once it ages past the nonce lifetime — after
		 * which every quote request from that cached page failed identically and forever.
		 *
		 * `WC_AJAX::do_wc_ajax()` sends `wc_nocache_headers()` before firing
		 * `wc_ajax_{action}` (woocommerce/includes/class-wc-ajax.php), which is why the
		 * refresh itself cannot be served from the same cache that staled the nonce.
		 * admin-ajax.php would usually work too, but page caches are configured to bypass
		 * wc-ajax specifically, and this plugin already requires WooCommerce.
		 */
		add_action( 'wc_ajax_tack_quote_nonce', array( $this, 'handle_nonce_refresh' ) );
		// Same handler on admin-ajax, so the fallback URL below is a real route rather
		// than a 400 waiting to happen.
		add_action( 'wp_ajax_tack_quote_nonce', array( $this, 'handle_nonce_refresh' ) );
		add_action( 'wp_ajax_nopriv_tack_quote_nonce', array( $this, 'handle_nonce_refresh' ) );
	}

	/**
	 * The three merchant-renamable button labels: option => English default.
	 *
	 * @since 1.9.0
	 *
	 * @return array<string, string>
	 */
	public static function label_defaults() {
		return array(
			'tack_quotes_button_label'          => 'Add to Quote',
			'tack_quotes_request_button_label'  => 'Request a Quote',
			'tack_quotes_checkout_button_label' => 'Checkout as Quote',
		);
	}

	/**
	 * The default label in the visitor's language.
	 *
	 * Literal __() calls per option so the strings are extractable.
	 *
	 * @since 1.9.0
	 *
	 * @param string $option One of label_defaults()'s keys.
	 * @return string
	 */
	public static function default_label( $option ) {
		switch ( $option ) {
			case 'tack_quotes_request_button_label':
				return __( 'Request a Quote', 'tackquote' );
			case 'tack_quotes_checkout_button_label':
				return __( 'Checkout as Quote', 'tackquote' );
			default:
				return __( 'Add to Quote', 'tackquote' );
		}
	}

	/**
	 * A button label: the merchant's own wording, or the translated default.
	 *
	 * Before 1.9.0 activation STORED the default (`add_option( …, __( 'Add to Quote' ) )`),
	 * so every store held the English text in the database and no translation could
	 * ever reach the button. A blank value, or one equal to the English default, now
	 * means "the default" and follows the visitor's language; only a label the merchant
	 * actually changed is shown verbatim.
	 *
	 * @since 1.9.0
	 *
	 * @param string $option One of label_defaults()'s keys.
	 * @return string
	 */
	public static function button_label( $option ) {
		$defaults = self::label_defaults();
		$stored   = trim( (string) get_option( $option, '' ) );
		if ( '' === $stored || ( isset( $defaults[ $option ] ) && $defaults[ $option ] === $stored ) ) {
			return self::default_label( $option );
		}
		return $stored;
	}

	/**
	 * URL that issues a fresh submit nonce.
	 *
	 * Prefers WooCommerce's `?wc-ajax=` endpoint because page caches are configured to
	 * bypass it. Falls back to admin-ajax.php if `WC_AJAX` is somehow unavailable — the
	 * plugin already refuses to load without WooCommerce, so that is defence in depth
	 * rather than a supported configuration.
	 *
	 * @return string
	 */
	private function nonce_endpoint() {
		if ( class_exists( 'WC_AJAX' ) ) {
			return WC_AJAX::get_endpoint( 'tack_quote_nonce' );
		}
		return add_query_arg( 'action', 'tack_quote_nonce', admin_url( 'admin-ajax.php' ) );
	}

	/**
	 * Issue a fresh `tack_request_quote` nonce.
	 *
	 * Deliberately NOT nonce-protected: this endpoint exists to hand out a nonce, so
	 * requiring one would be circular. It is safe because a nonce is bound to the
	 * requester's own session and user id, so a token fetched here is only usable by
	 * whoever asked for it — handing one to an attacker gains them nothing they could not
	 * mint by loading the page.
	 *
	 * @return void
	 */
	public function handle_nonce_refresh() {
		if ( class_exists( 'WC_Cache_Helper' ) ) {
			WC_Cache_Helper::set_nocache_constants();
		}
		nocache_headers();

		wp_send_json_success( array( 'nonce' => wp_create_nonce( 'tack_request_quote' ) ) );
	}

	/**
	 * Enqueue the small JS/CSS the buttons need — loaded on every front-end
	 * page (not just product/cart) so the floating quote-list button/drawer
	 * is always reachable, the same way a theme's mini-cart usually is.
	 */
	public function enqueue_assets() {
		if ( is_admin() ) {
			return;
		}
		// Asset version: the plugin version in production, the file's mtime when WP_DEBUG is
		// on. TACK_QUOTES_VERSION is a hardcoded constant, so during development every edit
		// to these files kept the SAME ?ver= and browsers served the cached copy — a fix
		// applied to the JS looked like a fix that did not work, which cost real debugging
		// time. Production behaviour is unchanged: released versions still bust the cache
		// through the version bump.
		$css     = TACK_QUOTES_DIR . 'assets/css/tack-quotes.css';
		$js      = TACK_QUOTES_DIR . 'assets/js/tack-quotes.js';
		$css_ver = ( defined( 'WP_DEBUG' ) && WP_DEBUG && file_exists( $css ) )
			? (string) filemtime( $css )
			: TACK_QUOTES_VERSION;
		$js_ver  = ( defined( 'WP_DEBUG' ) && WP_DEBUG && file_exists( $js ) )
			? (string) filemtime( $js )
			: TACK_QUOTES_VERSION;

		wp_enqueue_style( 'tackquote', TACK_QUOTES_URL . 'assets/css/tack-quotes.css', array(), $css_ver );
		wp_enqueue_script( 'tackquote', TACK_QUOTES_URL . 'assets/js/tack-quotes.js', array( 'jquery', 'wp-i18n' ), $js_ver, true );

		/*
		 * The storefront text lives in the script as wp.i18n __() calls. No path is
		 * passed ON PURPOSE: with an explicit path WordPress reads that folder's JSON
		 * BEFORE the translate.wordpress.org language pack (load_script_textdomain()),
		 * the opposite of the PHP side. Without one it asks the textdomain registry,
		 * which prefers wp-content/languages/plugins/ and falls back to this plugin's
		 * languages/ folder registered by Tack_Quotes::load_textdomain(). Same order
		 * for PHP and JS: a language pack, when one exists, replaces the bundled file.
		 */
		wp_set_script_translations( 'tackquote', 'tackquote' );
		wp_localize_script(
			'tackquote',
			'TackQuotes',
			array(
				'ajaxUrl'             => admin_url( 'admin-ajax.php' ),
				'nonce'               => wp_create_nonce( 'tack_request_quote' ),
				// Where to get a FRESH nonce when the one above has been cached past its
				// lifetime. Only the URL is baked into the page, never a token, so this
				// stays valid no matter how long the HTML sits in a cache.
				'nonceUrl'            => $this->nonce_endpoint(),
				'customerEmail'       => $this->current_customer_email(),
				'checkoutButtonLabel' => self::button_label( 'tack_quotes_checkout_button_label' ),
				// The seller's registration policy drives which fields the form renders. Null
				// when Tack is unreachable, in which case the JS falls back to a minimal
				// name+email form rather than rendering nothing — a shopper must still be able
				// to ask for a quote when our own API is having a bad day.
				'registration'        => $this->registration_config(),
				// 1.9.0 storefront layout. Defaults reproduce the 1.8.x launcher exactly.
				'fab'                 => self::fab_settings(),
				'opens'               => self::opens(),
				'pageUrl'             => self::quote_page_url(),
				// The list is re-priced at the line quantity only for a signed-in buyer whose
				// store uses TackQuote prices; guests keep the store price (nothing to resolve).
				'repriceEnabled'      => $this->reprice_enabled(),
				// The Cart block changes the cart client-side without a page load, so the
				// cart-page button re-reads the live cart from WooCommerce's Store API before
				// falling back to the server-rendered snapshot. Same site, shopper's own
				// session cookie: nothing leaves the store.
				'storeCartUrl'        => function_exists( 'rest_url' ) ? rest_url( 'wc/store/v1/cart' ) : '',
				'price'               => $this->price_format(),
				// 1.10.0: the optional "Attach files" control. Null (no control, no
				// upload calls, no fields on the request) unless the merchant switched
				// attachments on AND the TackQuote server advertises `attachments`.
				'attachments'         => ( new Tack_Attachments() )->script_config(),
			)
		);
	}

	/**
	 * Best-effort email for pre-filling the modal: WooCommerce customer billing
	 * email first (covers logged-in and session-persisted guest checkouts),
	 * then the current WP user's account email.
	 *
	 * @return string
	 */
	private function current_customer_email() {
		if ( function_exists( 'WC' ) && WC()->customer ) {
			$billing_email = WC()->customer->get_billing_email();
			if ( $billing_email ) {
				return $billing_email;
			}
		}
		if ( is_user_logged_in() ) {
			$user = wp_get_current_user();
			if ( $user && $user->user_email ) {
				return $user->user_email;
			}
		}
		return '';
	}

	/**
	 * Fetch the seller's registration policy for the storefront form.
	 *
	 * Deliberately tolerant: a null return means "render the minimal form", never "render
	 * nothing". The alternative — hiding the button when Tack is unreachable — loses a lead
	 * for a reason the shopper cannot see or fix.
	 *
	 * @return array|null
	 */
	private function registration_config() {
		if ( ! class_exists( 'Tack_Api_Client' ) ) {
			return null;
		}
		$client = new Tack_Api_Client();
		return $client->get_registration_config();
	}

	/**
	 * Product-page buttons — merchant-configurable: "Add to Quote" (adds the
	 * product to the browser-side quote list — never the WooCommerce cart),
	 * "Request a Quote" (submits a quote for just this product immediately),
	 * both, or neither.
	 *
	 * @param WC_Product|string|null $for_product The product to render for; anything else
	 *                                            (a hook's empty argument) means the page's
	 *                                            global product.
	 */
	public function render_product_button( $for_product = null ) {
		// Hooks fire this with no argument (WordPress passes ''), so the page's
		// product is the fallback; the block filter passes the block's product.
		$product = $for_product instanceof WC_Product ? $for_product : ( $GLOBALS['product'] ?? null );
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		/*
		 * Rendered once per product, whichever mount point fires first. Both are
		 * registered (see init()) because only one of them exists in any given
		 * mode, and on a normal store both fire.
		 */
		$product_id = $product->get_id();
		if ( in_array( $product_id, $this->rendered_product_ids, true ) ) {
			return;
		}
		$this->rendered_product_ids[] = $product_id;

		$show_add_to_quote  = 'yes' === get_option( 'tack_quotes_show_add_to_quote', 'yes' );
		$show_request_quote = 'yes' === get_option( 'tack_quotes_show_request_quote', 'yes' );
		if ( ! $show_add_to_quote && ! $show_request_quote ) {
			return;
		}

		echo '<div class="tack-quote-buttons">';

		if ( $show_add_to_quote ) {
			$label = self::button_label( 'tack_quotes_button_label' );
			$this->button(
				array(
					'product-id'    => $product->get_id(),
					'product-name'  => $product->get_name(),
					'product-sku'   => $product->get_sku(),
					'product-price' => wc_get_price_excluding_tax( $product ),
				),
				'tack-add-to-quote-btn',
				$label
			);
		}

		if ( $show_request_quote ) {
			$label = self::button_label( 'tack_quotes_request_button_label' );
			$this->button( array( 'product-id' => $product->get_id() ), 'tack-quote-btn', $label );
		}

		echo '</div>';
	}

	/**
	 * The quote-only mount (`woocommerce_single_product_summary`), except when
	 * WooCommerce's block-template compatibility layer is the one firing it: that
	 * happens before the post excerpt, outside the add-to-cart form the JS reads
	 * quantity and variation from, and the Add to Cart block filter renders the
	 * buttons in the right place instead.
	 *
	 * @since 1.9.0
	 */
	public function render_product_button_fallback() {
		if ( Tack_Block_Product::is_compat_hook() ) {
			return;
		}
		$this->render_product_button();
	}

	/**
	 * `render_block_woocommerce/add-to-cart-form` and `.../add-to-cart-with-options`:
	 * append the product-page buttons after the block when they were not already
	 * rendered inside it.
	 *
	 * The block returns an empty string for a product that is not purchasable
	 * (quote-only, or no price), which is the case this exists for.
	 *
	 * @since 1.9.0
	 *
	 * @param string               $block_content Rendered block.
	 * @param array                $parsed_block  Parsed block (unused).
	 * @param WP_Block|object|null $instance      Block instance; its `postId` context names the product.
	 * @return string
	 */
	public function append_to_add_to_cart_block( $block_content, $parsed_block = array(), $instance = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- render_block_{$name} passes ( $content, $parsed_block, $instance ); only the instance is read.
		$product = Tack_Block_Product::from_block( $instance );
		if ( null === $product ) {
			return $block_content;
		}
		ob_start();
		$this->render_product_button( $product );
		return (string) $block_content . (string) ob_get_clean();
	}

	/**
	 * Floating "quote list" launcher + drawer, printed once in the footer of
	 * every front-end page the merchant chose. Empty/hidden by JS until at least
	 * one product has been added. This — not the WooCommerce cart page — is where
	 * shoppers review what they've added and submit "Checkout as Quote".
	 *
	 * Since 1.9.0 the launcher's side, offsets, label, size, pages and mobile
	 * behaviour are settings (see `fab_settings()`); every default reproduces the
	 * 1.8.x launcher. The markup carries the choices as classes, data attributes
	 * and two CSS custom properties; nothing is positioned inline.
	 */
	public function render_quote_list_drawer() {
		if ( is_admin() ) {
			return;
		}
		$fab = self::fab_settings();
		if ( ! $this->fab_shows_here( $fab['pages'] ) ) {
			return;
		}

		$classes = array( 'tack-quote-list-widget' );
		if ( 'bottom-left' === $fab['position'] ) {
			$classes[] = 'tack-fab-left';
		}
		if ( 'compact' === $fab['size'] ) {
			$classes[] = 'tack-fab-compact';
		}
		if ( $fab['iconOnly'] ) {
			$classes[] = 'tack-fab-icon-only';
		}
		if ( ! $fab['showCount'] ) {
			$classes[] = 'tack-fab-no-count';
		}
		if ( $fab['hideMobile'] ) {
			$classes[] = 'tack-fab-hide-mobile';
		}
		$style = sprintf( '--tack-fab-x:%dpx;--tack-fab-y:%dpx', $fab['offsetX'], $fab['offsetY'] );
		$opens = self::opens();
		?>
		<div id="tack-quote-list-widget" class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" data-position="<?php echo esc_attr( $fab['position'] ); ?>" data-size="<?php echo esc_attr( $fab['size'] ); ?>" data-pages="<?php echo esc_attr( $fab['pages'] ); ?>" data-opens="<?php echo esc_attr( $opens ); ?>" style="<?php echo esc_attr( $style ); ?>" hidden>
			<button type="button" id="tack-quote-list-toggle" class="tack-quote-list-toggle" aria-label="<?php echo esc_attr( $fab['label'] ); ?>"<?php echo 'page' === $opens ? ' data-href="' . esc_url( self::quote_page_url() ) . '"' : ''; ?>>
				<svg class="tack-fab-icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M9 2a2 2 0 0 0-2 2H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-1a2 2 0 0 0-2-2H9zm0 2h6v2H9V4zM8 11h8v2H8v-2zm0 4h5v2H8v-2z"/></svg>
				<span class="tack-fab-label"><?php echo esc_html( $fab['label'] ); ?></span>
				<span class="tack-fab-count">(<span id="tack-quote-list-count">0</span>)</span>
			</button>
			<div id="tack-quote-list-drawer" class="tack-quote-list-drawer" hidden>
				<div class="tack-quote-list-drawer-header">
					<strong><?php esc_html_e( 'Your quote list', 'tackquote' ); ?></strong>
					<button type="button" id="tack-quote-list-close" aria-label="<?php esc_attr_e( 'Close', 'tackquote' ); ?>">&times;</button>
				</div>
				<ul id="tack-quote-list-items" class="tack-quote-list-items"></ul>
				<button type="button" id="tack-quote-list-checkout" class="<?php echo esc_attr( self::button_class( 'tack-quote-btn tack-quote-list-checkout' ) ); ?>">
					<?php echo esc_html( self::button_label( 'tack_quotes_checkout_button_label' ) ); ?>
				</button>
			</div>
		</div>
		<?php
	}

	/**
	 * Does the launcher belong on THIS page, per the "Show on" setting?
	 *
	 * @param string $pages One of all|product|cart|none.
	 * @return bool
	 */
	private function fab_shows_here( $pages ) {
		switch ( $pages ) {
			case 'none':
				return false;
			case 'product':
				return function_exists( 'is_product' ) && is_product();
			case 'cart':
				return function_exists( 'is_cart' ) && is_cart();
			default:
				return true;
		}
	}

	/**
	 * The launcher defaults — the 1.8.x launcher, exactly: bottom right, 20 px in
	 * from each edge, "Quote list (n)", regular size, every page, shown on mobile.
	 *
	 * @since 1.9.0
	 *
	 * @return array
	 */
	public static function fab_defaults() {
		return array(
			'position'   => 'bottom-right',
			'offsetX'    => 20,
			'offsetY'    => 20,
			'pages'      => 'all',
			'label'      => __( 'Quote list', 'tackquote' ),
			'iconOnly'   => false,
			'showCount'  => true,
			'size'       => 'regular',
			'hideMobile' => false,
		);
	}

	/**
	 * The launcher settings as saved, each checked against its allowed values;
	 * anything unrecognised is the default. The ids mirror the Shopify quote
	 * launcher block (`quote-fab.liquid`: position, offset_x, offset_y, pages,
	 * label, icon_only, show_count, size, hide_on_mobile) so a merchant moving
	 * between platforms meets the same choices.
	 *
	 * @since 1.9.0
	 *
	 * @return array{position:string,offsetX:int,offsetY:int,pages:string,label:string,iconOnly:bool,showCount:bool,size:string,hideMobile:bool}
	 */
	public static function fab_settings() {
		$d        = self::fab_defaults();
		$position = (string) get_option( self::OPT_FAB_POSITION, $d['position'] );
		$pages    = (string) get_option( self::OPT_FAB_PAGES, $d['pages'] );
		$size     = (string) get_option( self::OPT_FAB_SIZE, $d['size'] );
		$label    = trim( (string) get_option( self::OPT_FAB_LABEL, '' ) );

		return array(
			'position'   => in_array( $position, array( 'bottom-right', 'bottom-left' ), true ) ? $position : $d['position'],
			'offsetX'    => self::offset_px( get_option( self::OPT_FAB_OFFSET_X, $d['offsetX'] ), $d['offsetX'] ),
			'offsetY'    => self::offset_px( get_option( self::OPT_FAB_OFFSET_Y, $d['offsetY'] ), $d['offsetY'] ),
			'pages'      => in_array( $pages, array( 'all', 'product', 'cart', 'none' ), true ) ? $pages : $d['pages'],
			'label'      => '' === $label ? $d['label'] : $label,
			'iconOnly'   => 'yes' === get_option( self::OPT_FAB_ICON_ONLY, 'no' ),
			'showCount'  => 'yes' === get_option( self::OPT_FAB_SHOW_COUNT, 'yes' ),
			'size'       => in_array( $size, array( 'compact', 'regular' ), true ) ? $size : $d['size'],
			'hideMobile' => 'yes' === get_option( self::OPT_FAB_HIDE_MOBILE, 'no' ),
		);
	}

	/**
	 * A launcher offset in whole pixels within [0, FAB_OFFSET_MAX], else the default.
	 *
	 * @param mixed $value    Stored value.
	 * @param int   $fallback Default.
	 * @return int
	 */
	private static function offset_px( $value, $fallback ) {
		if ( ! is_numeric( $value ) ) {
			return (int) $fallback;
		}
		$n = (int) $value;
		return ( $n >= 0 && $n <= self::FAB_OFFSET_MAX ) ? $n : (int) $fallback;
	}

	/**
	 * What the launcher and the card/cart buttons open: `drawer` (default) or
	 * `page`. `page` needs a URL; without one the drawer is used so a saved
	 * setting with a blank URL cannot make the launcher do nothing.
	 *
	 * @since 1.9.0
	 *
	 * @return string
	 */
	public static function opens() {
		return 'page' === get_option( self::OPT_OPENS, 'drawer' ) && '' !== self::quote_page_url() ? 'page' : 'drawer';
	}

	/**
	 * URL of the merchant page carrying `[tackquote_quote_page]`, or ''.
	 *
	 * @since 1.9.0
	 *
	 * @return string
	 */
	public static function quote_page_url() {
		return trim( (string) get_option( self::OPT_PAGE_URL, '' ) );
	}

	/**
	 * Is the quote list re-priced at the line quantity for this visitor?
	 *
	 * Only a signed-in buyer on a store that uses TackQuote prices: the resolve
	 * call needs a buyer email and the merchant's switch, and a guest's list keeps
	 * the store price it was added at (there is nothing to resolve for them).
	 *
	 * @since 1.9.0
	 *
	 * @return bool
	 */
	public function reprice_enabled() {
		return class_exists( 'Tack_Wholesale_Pricing' )
			&& Tack_Wholesale_Pricing::is_enabled()
			&& is_user_logged_in()
			&& '' !== (string) get_option( 'tack_quotes_api_key', '' );
	}

	/**
	 * The store's price format, so the storefront script can print a unit price
	 * the way the store does (symbol, decimals, separators, position). Read from
	 * WooCommerce's own settings helpers; nothing here is invented.
	 *
	 * @since 1.9.0
	 *
	 * @return array{symbol:string,decimals:int,decimalSep:string,thousandSep:string,position:string}
	 */
	private function price_format() {
		return array(
			'symbol'      => function_exists( 'get_woocommerce_currency_symbol' )
				? html_entity_decode( (string) get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' )
				: '',
			'decimals'    => function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2,
			'decimalSep'  => function_exists( 'wc_get_price_decimal_separator' ) ? (string) wc_get_price_decimal_separator() : '.',
			'thousandSep' => function_exists( 'wc_get_price_thousand_separator' ) ? (string) wc_get_price_thousand_separator() : ',',
			'position'    => (string) get_option( 'woocommerce_currency_pos', 'left' ),
		);
	}

	/**
	 * The class list for an injected control: WooCommerce's own button classes,
	 * so the theme paints it like its own buttons.
	 *
	 * Block themes style `.wp-element-button` (the class WooCommerce adds to its
	 * own loop and cart buttons through `wc_wp_theme_get_element_class_name( 'button' )`,
	 * includes/wc-conditional-functions.php); classic themes style `.button`.
	 * No colour is set by the plugin for the 1.9.0 controls.
	 *
	 * @since 1.9.0
	 *
	 * @param string $extra Plugin classes to append (space-separated).
	 * @return string
	 */
	public static function button_class( $extra = '' ) {
		$classes = array( 'button' );
		$element = '';
		if ( function_exists( 'wc_wp_theme_get_element_class_name' ) ) {
			$element = (string) wc_wp_theme_get_element_class_name( 'button' );
		} elseif ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
			$element = 'wp-element-button';
		}
		if ( '' !== $element ) {
			$classes[] = $element;
		}
		if ( '' !== (string) $extra ) {
			$classes[] = (string) $extra;
		}
		return implode( ' ', $classes );
	}

	/**
	 * "Add to Quote" on a product card in the shop, category and search loops
	 * (off by default; `OPT_CARD_BUTTONS`).
	 *
	 * A simple product is added to the quote list directly, quantity 1. A product
	 * whose card cannot name what would be quoted — variable (which size?),
	 * grouped (which child?), external (not sold here) — links to its page
	 * instead, where the product-page buttons take over. That is what the
	 * BigCommerce widget does on its cards too, and the alternative — quoting a
	 * variable parent — records the wrong SKU at the cheapest variation's price.
	 *
	 * @since 1.9.0
	 */
	public function render_card_button() {
		if ( 'yes' !== get_option( self::OPT_CARD_BUTTONS, 'no' ) ) {
			return;
		}
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$name = (string) $product->get_name();

		if ( $product->is_type( 'simple' ) ) {
			printf(
				'<button type="button" class="%1$s" data-product-id="%2$d" data-product-name="%3$s" data-product-sku="%4$s" data-product-price="%5$s" aria-label="%6$s">%7$s</button>',
				esc_attr( self::button_class( 'tack-card-quote-btn' ) ),
				(int) $product->get_id(),
				esc_attr( $name ),
				esc_attr( (string) $product->get_sku() ),
				esc_attr( (string) wc_get_price_excluding_tax( $product ) ),
				esc_attr(
					sprintf(
						/* translators: %s: product name. */
						__( 'Add %s to quote', 'tackquote' ),
						$name
					)
				),
				esc_html( self::button_label( 'tack_quotes_button_label' ) )
			);
			return;
		}

		$url = get_permalink( $product->get_id() );
		if ( ! $url ) {
			return;
		}
		/**
		 * Filters the card label for a product that must be configured before quoting.
		 *
		 * @since 1.9.0
		 *
		 * @param string     $label   Default "Choose options to quote".
		 * @param WC_Product $product The product.
		 */
		$label = (string) apply_filters( 'tack_quotes_card_options_label', __( 'Choose options to quote', 'tackquote' ), $product );
		printf(
			'<a href="%1$s" class="%2$s" data-product-id="%3$d" aria-label="%4$s">%5$s</a>',
			esc_url( $url ),
			esc_attr( self::button_class( 'tack-card-quote-link' ) ),
			(int) $product->get_id(),
			esc_attr(
				sprintf(
					/* translators: %s: product name. */
					__( 'Choose options for %s to quote', 'tackquote' ),
					$name
				)
			),
			esc_html( $label )
		);
	}

	/**
	 * The shopper's cart as quote-list lines: product and variation ids, SKU,
	 * name, quantity and the unit price excluding tax. Read from `WC()->cart`
	 * (`get_cart()` rows carry `product_id`, `variation_id`, `quantity` and the
	 * product under `data`; includes/class-wc-cart.php).
	 *
	 * @since 1.9.0
	 *
	 * @return array<int, array{product_id:int,variation_id:int,quantity:int,sku:string,name:string,price:float}>
	 */
	public function cart_lines() {
		if ( ! function_exists( 'WC' ) || ! is_object( WC()->cart ) || ! method_exists( WC()->cart, 'get_cart' ) ) {
			return array();
		}
		$lines = array();
		foreach ( WC()->cart->get_cart() as $item ) {
			$data = isset( $item['data'] ) ? $item['data'] : null;
			if ( ! is_object( $data ) || empty( $item['product_id'] ) ) {
				continue;
			}
			$lines[] = array(
				'product_id'   => (int) $item['product_id'],
				'variation_id' => isset( $item['variation_id'] ) ? (int) $item['variation_id'] : 0,
				'quantity'     => isset( $item['quantity'] ) ? max( 1, (int) $item['quantity'] ) : 1,
				'sku'          => method_exists( $data, 'get_sku' ) ? (string) $data->get_sku() : '',
				'name'         => method_exists( $data, 'get_name' ) ? (string) $data->get_name() : '',
				'price'        => function_exists( 'wc_get_price_excluding_tax' ) ? (float) wc_get_price_excluding_tax( $data ) : 0.0,
			);
		}
		return $lines;
	}

	/**
	 * "Request a quote for your cart" under the classic cart's Proceed button
	 * (off by default; `OPT_CART_BUTTON`).
	 *
	 * @since 1.9.0
	 */
	public function render_cart_quote_button() {
		if ( 'yes' !== get_option( self::OPT_CART_BUTTON, 'no' ) ) {
			return;
		}
		$lines = $this->cart_lines();
		if ( empty( $lines ) ) {
			return;
		}
		$this->cart_button_rendered = true;
		$this->print_cart_quote_button( $lines );
	}

	/**
	 * The same control for the Cart BLOCK, which renders no PHP hook beside its
	 * Proceed button: a fixed control printed in the footer of the cart page when
	 * the classic hook did not fire and the cart has lines.
	 *
	 * @since 1.9.0
	 */
	public function render_cart_quote_button_footer() {
		if ( is_admin() || $this->cart_button_rendered || 'yes' !== get_option( self::OPT_CART_BUTTON, 'no' ) ) {
			return;
		}
		if ( ! function_exists( 'is_cart' ) || ! is_cart() ) {
			return;
		}
		$lines = $this->cart_lines();
		if ( empty( $lines ) ) {
			return;
		}
		$this->cart_button_rendered = true;
		echo '<div class="tack-quote-cart-fixed">';
		$this->print_cart_quote_button( $lines );
		echo '</div>';
	}

	/**
	 * The cart-page control. The lines travel on the element as JSON; the
	 * server re-derives every value from the ids on submit, as it does for the
	 * drawer, so the snapshot is only what the shopper sees added.
	 *
	 * @param array $lines Cart lines from `cart_lines()`.
	 */
	private function print_cart_quote_button( $lines ) {
		$label = trim( (string) get_option( self::OPT_CART_BUTTON_LABEL, '' ) );
		if ( '' === $label ) {
			$label = __( 'Request a quote for your cart', 'tackquote' );
		}
		printf(
			'<button type="button" class="%1$s" data-lines="%2$s">%3$s</button>',
			esc_attr( self::button_class( 'tack-quote-cart-btn' ) ),
			esc_attr( (string) wp_json_encode( $lines ) ),
			esc_html( $label )
		);
	}

	/**
	 * `[tackquote_quote_page target_price="yes" message="yes"]` — the quote page.
	 *
	 * Renders the shell; the storefront script fills it from the same
	 * `localStorage` list the drawer uses, so a product added anywhere on the
	 * store is on this page too. Quantities are editable, a target price per line
	 * is optional, and the message becomes the request note. Submit goes through
	 * the same `tack_request_quote` handler as the drawer.
	 *
	 * @since 1.9.0
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render_quote_page( $atts = array() ) {
		$atts    = shortcode_atts(
			array(
				'target_price' => 'yes',
				'message'      => 'yes',
			),
			is_array( $atts ) ? $atts : array(),
			'tackquote_quote_page'
		);
		$target  = 'no' !== strtolower( (string) $atts['target_price'] );
		$message = 'no' !== strtolower( (string) $atts['message'] );
		$shop    = function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'shop' ) : '';

		ob_start();
		?>
		<div id="tack-quote-page" class="tack-quote-page" data-target-price="<?php echo $target ? 'yes' : 'no'; ?>" data-message="<?php echo $message ? 'yes' : 'no'; ?>">
			<p class="tack-quote-page-empty"><?php esc_html_e( 'No products added yet.', 'tackquote' ); ?></p>
			<table class="shop_table tack-quote-page-table" hidden>
				<thead>
					<tr>
						<th class="tack-quote-page-col-product"><?php esc_html_e( 'Product', 'tackquote' ); ?></th>
						<th class="tack-quote-page-col-qty"><?php esc_html_e( 'Quantity', 'tackquote' ); ?></th>
						<th class="tack-quote-page-col-price"><?php esc_html_e( 'Unit price (excl. tax)', 'tackquote' ); ?></th>
						<?php if ( $target ) : ?>
						<th class="tack-quote-page-col-target"><?php esc_html_e( 'Target price', 'tackquote' ); ?></th>
						<?php endif; ?>
						<th class="tack-quote-page-col-remove"><span class="screen-reader-text"><?php esc_html_e( 'Remove', 'tackquote' ); ?></span></th>
					</tr>
				</thead>
				<tbody id="tack-quote-page-items"></tbody>
			</table>
			<?php if ( $message ) : ?>
			<div class="tack-quote-field tack-quote-page-message">
				<label for="tack-quote-page-message"><?php esc_html_e( 'Message', 'tackquote' ); ?> <span class="tack-quote-optional"><?php esc_html_e( '(optional)', 'tackquote' ); ?></span></label>
				<textarea id="tack-quote-page-message" rows="3" maxlength="<?php echo (int) self::NOTE_MAX_LENGTH; ?>"></textarea>
			</div>
			<?php endif; ?>
			<p class="tack-quote-page-actions">
				<?php if ( '' !== $shop ) : ?>
				<a class="<?php echo esc_attr( self::button_class( 'tack-quote-page-continue' ) ); ?>" href="<?php echo esc_url( $shop ); ?>"><?php esc_html_e( 'Continue shopping', 'tackquote' ); ?></a>
				<?php endif; ?>
				<button type="button" id="tack-quote-page-submit" class="<?php echo esc_attr( self::button_class( 'alt tack-quote-page-submit' ) ); ?>" disabled>
					<?php echo esc_html( self::button_label( 'tack_quotes_checkout_button_label' ) ); ?>
				</button>
			</p>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Output button markup with data attributes.
	 *
	 * @param array  $data      Data attributes (key is used verbatim as data-{key}).
	 * @param string $css_class CSS class selector the JS binds its click handler to.
	 * @param string $label     Visible button text.
	 */
	private function button( $data, $css_class, $label ) {
		$attrs = '';
		foreach ( $data as $k => $v ) {
			$attrs .= sprintf( ' data-%s="%s"', esc_attr( $k ), esc_attr( $v ) );
		}
		printf(
			'<button type="button" class="%s"%s>%s</button>',
			esc_attr( self::button_class( $css_class ) ),
			$attrs, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_attr above.
			esc_html( $label )
		);
	}

	/**
	 * Count this caller's recent quote requests and say whether they are over the limit.
	 *
	 * Deliberately coarse. It exists to make flooding expensive, not to be an authorization
	 * boundary: the client address is only ever a hint (behind a CDN or load balancer it is
	 * whatever the proxy chain reports, and that chain is forgeable unless the host is
	 * configured to trust it), so a determined attacker rotates addresses. What it does buy
	 * is that a single script cannot hold every PHP worker on the site with a loop.
	 *
	 * The counter is keyed on a SALTED HASH of the address rather than the address itself: an
	 * IP is personal data, transients live in the options table or a shared object cache, and
	 * a counter does not need to be able to name anybody.
	 *
	 * @return bool True when the caller is over the limit.
	 */
	private function rate_limit_exceeded() {
		$max = $this->rate_limit_max();
		if ( $max <= 0 ) {
			return false;
		}
		return (int) get_transient( $this->rate_limit_key() ) >= $max;
	}

	/**
	 * Count one quote request against this caller's allowance.
	 *
	 * Called immediately before the outbound API call, not at the top of the handler, and
	 * deliberately so. The expensive and abusable thing here is the request to TackQuote —
	 * it is what holds a worker open and what creates a lead — while a submission that fails
	 * validation returns in milliseconds with no outbound call at all. Charging quota for
	 * those would mean a shopper who mistypes their email address a few times locks
	 * themselves out of a form they are actively trying to use.
	 */
	private function record_rate_limit_hit() {
		if ( $this->rate_limit_max() <= 0 ) {
			return;
		}
		$key = $this->rate_limit_key();
		set_transient( $key, (int) get_transient( $key ) + 1, self::RATE_LIMIT_WINDOW );
	}

	/**
	 * The configured allowance.
	 *
	 * @return int Maximum requests per window. Zero or less disables the limit.
	 */
	private function rate_limit_max() {
		/**
		 * Filter the number of quote requests allowed per client per window.
		 *
		 * @since 1.3.1
		 *
		 * @param int $max Maximum requests. Zero or less disables the limit.
		 */
		return (int) apply_filters( 'tack_quotes_rate_limit_max', self::RATE_LIMIT_MAX );
	}

	/**
	 * Transient key identifying this caller's counter.
	 *
	 * @return string
	 */
	private function rate_limit_key() {
		// WC_Geolocation::get_ip_address() is WooCommerce's own client-address resolution, so
		// this agrees with how the rest of the store identifies a visitor instead of inventing
		// a second answer.
		$ip = '';
		if ( class_exists( 'WC_Geolocation' ) ) {
			$ip = (string) WC_Geolocation::get_ip_address();
		}
		if ( '' === $ip && isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		return 'tack_qr_' . substr( wp_hash( 'quote-request|' . $ip ), 0, 20 );
	}

	/**
	 * AJAX handler: build line items from a single product or the submitted
	 * quote list, then call the Tack API.
	 */
	public function handle_request() {
		/*
		 * `$die` is passed as false deliberately.
		 *
		 * At its default of true, `check_ajax_referer()` answers a failed check with
		 * `wp_die( -1, 403 )` — HTTP 403 and a body of literally `-1`, no JSON. The storefront
		 * JS has nothing to read there, so it fell back to its generic "Could not create the
		 * quote. Please try again." — advice that can never work, for the one failure the
		 * shopper could actually fix.
		 *
		 * And this is not an edge case. The nonce is printed into the page by
		 * wp_localize_script(), so on any store with full-page caching it is baked into cached
		 * HTML and stops verifying once it ages past the nonce lifetime (24h by default). From
		 * then on every quote request from that cached page fails identically and permanently,
		 * with no signal to the shopper or the merchant that a cache purge is the fix.
		 *
		 * A distinguishable, actionable answer does not make the cached-nonce problem go away
		 * — the real cure is to stop baking the nonce into cacheable HTML — but it turns
		 * "silently broken forever" into "reload the page".
		 */
		if ( ! check_ajax_referer( 'tack_request_quote', 'nonce', false ) ) {
			wp_send_json_error(
				array(
					'code'    => 'tack_nonce_expired',
					'message' => __( 'This page has been open too long, or was served from a cache. Reload it and request the quote again.', 'tackquote' ),
					'reload'  => true,
				),
				403
			);
		}

		/*
		 * A nonce prevents CSRF. It does not prevent abuse.
		 *
		 * This handler is registered for `wp_ajax_nopriv_tack_request_quote` and the nonce it
		 * checks is printed into every page a logged-out visitor can load, so anybody can
		 * obtain one and replay this endpoint as fast as they like. Each hit holds a PHP-FPM
		 * worker open for the length of an outbound HTTP call — which is a store-wide denial
		 * of service on a small host, and an open channel for flooding the seller's TackQuote
		 * account with fake leads. Neither is a CSRF problem, so no nonce could have stopped
		 * either.
		 */
		if ( $this->rate_limit_exceeded() ) {
			wp_send_json_error(
				array(
					'code'    => 'tack_rate_limited',
					'message' => __( 'Too many quote requests from this connection. Please wait a few minutes and try again.', 'tackquote' ),
				),
				429
			);
		}

		$email        = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$note         = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
		$first_name   = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
		$last_name    = isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '';
		$phone        = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$company_name = isset( $_POST['company_name'] ) ? sanitize_text_field( wp_unslash( $_POST['company_name'] ) ) : '';

		/*
		 * Company details arrive as company[key]=value.
		 *
		 * Values are sanitised as the array is read — `map_deep()` applies
		 * `sanitize_text_field()` to every leaf — rather than one at a time inside the loop.
		 * Same result, but the raw superglobal is never the thing being iterated, which is
		 * what the coding standards are asking for and what makes it obvious by inspection
		 * that no unsanitised value can escape this block.
		 *
		 * Keys are then restricted to a safe charset. That allowlist is mirrored in the
		 * storefront JS, which renders these same names into HTML attributes.
		 */
		$company = array();
		if ( isset( $_POST['company'] ) && is_array( $_POST['company'] ) ) {
			$raw_company = map_deep( wp_unslash( (array) $_POST['company'] ), 'sanitize_text_field' );
			foreach ( $raw_company as $key => $value ) {
				if ( ! is_string( $key ) || ! preg_match( '/^[A-Za-z0-9_]{1,40}$/', $key ) ) {
					continue;
				}
				if ( is_scalar( $value ) ) {
					$company[ $key ] = (string) $value;
				}
			}
		}
		// The quote list, as JSON. sanitize_textarea_field() is safe to apply to it — the
		// payload is a flat array of integers keyed by product_id/variation_id/quantity, so
		// there is nothing in a well-formed body for it to strip — and a body that it does
		// alter would not have decoded into usable rows anyway.
		$items_json = isset( $_POST['items'] ) ? sanitize_textarea_field( wp_unslash( $_POST['items'] ) ) : '';
		$product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
		$quantity   = isset( $_POST['quantity'] ) ? max( 1, absint( wp_unslash( $_POST['quantity'] ) ) ) : 1;
		// Which VARIATION the shopper chose. A variable product's button carries the parent
		// id, so without this a quote for "X-Large" was recorded against the parent — wrong
		// SKU and the parent's (cheapest) price. Validated against the parent below, so it
		// cannot be used to quote some unrelated product.
		$variation_id = isset( $_POST['variation_id'] ) ? absint( wp_unslash( $_POST['variation_id'] ) ) : 0;

		// Free text from an unauthenticated endpoint needs a ceiling, or a single request can
		// post megabytes that we then forward to TackQuote and it stores. `mb_substr()` is
		// safe to call unconditionally: WordPress polyfills it in wp-includes/compat.php when
		// the mbstring extension is missing.
		$note = mb_substr( $note, 0, self::NOTE_MAX_LENGTH );

		if ( '' === $email || ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'A valid email address is required.', 'tackquote' ) ), 400 );
		}

		if ( $items_json ) {
			$line_items = $this->quote_list_line_items( $items_json );
		} else {
			$line_items = $this->product_line_items( $product_id, $quantity, $variation_id );
		}

		if ( empty( $line_items ) ) {
			// Distinguish "you have not chosen options yet" from "there is nothing here".
			// Both produce no line items, but only one is the shopper's to fix, and the
			// generic message left them re-clicking a button that could never succeed.
			$parent          = $product_id ? wc_get_product( $product_id ) : null;
			$needs_variation = ! $items_json
				&& $parent instanceof WC_Product_Variable;

			wp_send_json_error(
				array(
					'message' => $needs_variation
						? __( 'Please choose the product options before requesting a quote.', 'tackquote' )
						: __( 'No products to quote.', 'tackquote' ),
				),
				400
			);
		}

		$payload = array(
			'buyerEmail' => $email,
			'note'       => $note,
			'source'     => 'woocommerce',
			'lineItems'  => $line_items,
		);

		// Buyer identity. Sent only when non-empty so an older storefront cache that still
		// posts email-only does not overwrite a stored name with blanks.
		if ( '' !== $first_name ) {
			$payload['firstName'] = $first_name;
		}
		if ( '' !== $last_name ) {
			$payload['lastName'] = $last_name;
		}
		if ( '' !== $phone ) {
			$payload['phone'] = $phone;
		}
		if ( '' !== $company_name ) {
			$payload['companyName'] = $company_name;
		}
		if ( ! empty( $company ) ) {
			$payload['company'] = $company;
		}

		/*
		 * The prices in $line_items are in the store's currency, so the quote has to say
		 * which currency that is. Without this Tack fell back to a hardcoded 'USD' and a
		 * store selling in EUR produced USD quotes with no error anywhere.
		 *
		 * `get_woocommerce_currency()` returns the currently selected currency code (per
		 * WooCommerce's multi-currency developer docs), which is the one the prices above
		 * were rendered in. Guarded with function_exists because this file is also
		 * reachable when WooCommerce is inactive. Sent only when it looks like an ISO 4217
		 * alpha-3 code — otherwise omitted, so Tack applies the tenant's configured
		 * currency instead of receiving junk.
		 */
		if ( function_exists( 'get_woocommerce_currency' ) ) {
			$currency = strtoupper( trim( (string) get_woocommerce_currency() ) );

			if ( preg_match( '/^[A-Z]{3}$/', $currency ) ) {
				$payload['currency'] = $currency;
			}
		}

		/*
		 * Target prices (1.9.0, quote page). The plugin's request DTO
		 * (`StorefrontPluginLineItemDto` in tack) has no per-line field for them and no
		 * per-line note either, so they travel INSIDE THE REQUEST NOTE, one line per
		 * product, after the shopper's own message. Two-repo follow-up recorded in the
		 * 1.9.0 PR: add an optional `targetPrice` to that DTO and move these there.
		 */
		if ( ! empty( $this->target_prices ) ) {
			$payload['note'] = $this->note_with_target_prices( $note, isset( $payload['currency'] ) ? $payload['currency'] : '' );
		}

		/*
		 * Attachments (1.10.0): upload ids the browser got from `tack_quote_upload`,
		 * and a guest's upload token. Checked before any outbound call, and only ever
		 * forwarded to a server that advertises `attachments`.
		 */
		$client  = new Tack_Api_Client();
		$payload = $this->attach_uploads(
			$payload,
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified at the top of this handler.
			isset( $_POST['upload_ids'] ) ? map_deep( wp_unslash( $_POST['upload_ids'] ), 'sanitize_text_field' ) : array(),
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified at the top of this handler.
			isset( $_POST['upload_token'] ) ? sanitize_text_field( wp_unslash( $_POST['upload_token'] ) ) : '',
			new Tack_Attachments( $client )
		);
		if ( is_wp_error( $payload ) ) {
			wp_send_json_error(
				array(
					'code'    => $payload->get_error_code(),
					'message' => $payload->get_error_message(),
				),
				400
			);
		}

		$this->record_rate_limit_hit();

		$result = $client->create_quote_request( $payload );

		if ( is_wp_error( $result ) ) {
			if ( self::refused_attachment_fields( $result ) ) {
				// The cache said "attachments" but the server refused the fields: it is
				// an older server now. Ask it again next time instead of failing every request.
				$client->forget_capabilities();
			}
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 502 );
		}

		wp_send_json_success(
			array(
				'quoteId'          => isset( $result['id'] ) ? $result['id'] : null,
				'quoteNumber'      => isset( $result['quoteNumber'] ) ? sanitize_text_field( (string) $result['quoteNumber'] ) : null,
				'portalUrl'        => isset( $result['portalUrl'] ) ? esc_url_raw( $result['portalUrl'] ) : ( isset( $result['quoteUrl'] ) ? esc_url_raw( $result['quoteUrl'] ) : '' ),
				// Forwarded so the storefront can say "awaiting approval" instead of implying
				// the buyer portal is ready to use. Without this the shopper is redirected to a
				// login they cannot pass yet.
				'awaitingApproval' => ! empty( $result['awaitingApproval'] ),
				// How many attached files TackQuote claimed onto the quote (1.10.0).
				'attachmentCount'  => isset( $result['attachmentCount'] ) ? absint( $result['attachmentCount'] ) : 0,
				'company'          => isset( $result['company'] ) && is_array( $result['company'] )
					? array(
						'name'   => isset( $result['company']['name'] ) ? sanitize_text_field( (string) $result['company']['name'] ) : '',
						'status' => isset( $result['company']['status'] ) ? sanitize_text_field( (string) $result['company']['status'] ) : '',
					)
					: null,
			)
		);
	}

	/**
	 * Add the attachment fields to a quote-request payload, or say why not.
	 *
	 * `uploadIds` (and a guest's `uploadToken`) are added only when the browser
	 * sent ids AND attachments are on AND the server advertised them; with no ids
	 * the payload is returned unchanged, so a request without files is byte-for-byte
	 * what it was before 1.10.0.
	 *
	 * @since 1.10.0
	 *
	 * @param array            $payload     The request payload (buyerEmail already set).
	 * @param mixed            $ids         Posted upload ids.
	 * @param string           $token       Posted guest upload token.
	 * @param Tack_Attachments $attachments The attachment service.
	 * @return array|WP_Error
	 */
	public function attach_uploads( array $payload, $ids, $token, $attachments ) {
		$fields = $attachments->quote_request_fields( $ids, $token, isset( $payload['buyerEmail'] ) ? (string) $payload['buyerEmail'] : '' );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}
		return array_merge( $payload, $fields );
	}

	/**
	 * Did TackQuote refuse the request BECAUSE of the attachment fields (a 400 naming them)?
	 *
	 * @since 1.10.0
	 *
	 * @param WP_Error $error From Tack_Api_Client.
	 * @return bool
	 */
	public static function refused_attachment_fields( $error ) {
		$data = $error->get_error_data();
		if ( ! is_array( $data ) || ! isset( $data['status'] ) || 400 !== (int) $data['status'] ) {
			return false;
		}
		$message = (string) $error->get_error_message();
		return false !== strpos( $message, 'uploadIds' ) || false !== strpos( $message, 'uploadToken' );
	}

	/**
	 * Narrow a product to the thing actually being quoted, or reject it.
	 *
	 * Shared by both entry points — the single-product button and the quote list — because
	 * they had drifted: the single-product path was fixed to honour the chosen variation
	 * while the quote list still resolved the parent, so the same product quoted correctly
	 * one way and incorrectly the other.
	 *
	 * Returns the variation when one was chosen and it really belongs to this product, the
	 * product itself when it is not variable, or NULL when it is variable and no valid
	 * variation was supplied.
	 *
	 * Rejecting rather than falling back to the parent is deliberate. A variable parent's
	 * SKU is not something the store can fulfil, and its price is the cheapest variation's,
	 * so quoting it is both the wrong item and an underquote. WooCommerce takes the same
	 * position in its own UI: variation-add-to-cart-button.php ships
	 * `<input type="hidden" name="variation_id" value="0">` and core's frontend JS holds the
	 * add-to-cart button in `disabled wc-variation-selection-needed` until a purchasable
	 * variation is found.
	 *
	 * The variation id is caller-supplied, so it is only honoured after confirming its
	 * parent — otherwise any product's id could be passed as a "variation" of another and
	 * have its price quoted under that product's name.
	 *
	 * @param WC_Product $product      The product (a parent, for a variable product).
	 * @param int        $variation_id Caller-supplied variation id, or 0.
	 * @return WC_Product|null
	 */
	private function resolve_purchasable( $product, $variation_id ) {
		if ( $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( $variation instanceof WC_Product_Variation
				&& (int) $variation->get_parent_id() === (int) $product->get_id() ) {
				// get_name() on a variation already carries the attribute summary
				// ("Cut-Resistant Gloves - X-Large"), which is what a salesperson needs on
				// the quote line.
				return $variation;
			}
		}

		if ( $product instanceof WC_Product_Variable ) {
			return null;
		}

		return $product;
	}

	/**
	 * Build a single-product line item.
	 *
	 * When the shopper picked a variation, THAT is what gets quoted: a variation carries
	 * its own SKU and its own price, and the button on a variable product page can only
	 * carry the parent id. Quoting the parent recorded the wrong SKU at the parent's
	 * price — on this devstore's gloves, "X-Large" (TQ-GLOVE-XL, 47.50) was quoted as
	 * TQ-GLOVE-PARENT at 42.00, i.e. the wrong item underpriced by 5.50 a unit.
	 *
	 * The variation id is caller-supplied, so it is only honoured after confirming it is
	 * really a variation OF THIS PRODUCT. Otherwise anyone could post any product's id as
	 * a "variation" and have its price quoted under another product's page.
	 *
	 * @param int $product_id   Product ID (the parent, for a variable product).
	 * @param int $quantity     Quantity.
	 * @param int $variation_id Selected variation ID, or 0.
	 * @return array
	 */
	private function product_line_items( $product_id, $quantity, $variation_id = 0 ) {
		$product = $product_id ? wc_get_product( $product_id ) : null;
		if ( ! $product instanceof WC_Product ) {
			return array();
		}

		$product = $this->resolve_purchasable( $product, $variation_id );
		if ( ! $product ) {
			return array();
		}

		return array(
			array(
				'sku'               => $product->get_sku(),
				'name'              => $product->get_name(),
				'quantity'          => $quantity,
				'unitPrice'         => (float) wc_get_price_excluding_tax( $product ),
				'externalProductId' => (string) $product->get_id(),
			),
		);
	}

	/**
	 * Build line items from the browser-submitted quote list. Only
	 * `product_id` + `quantity` (and, since 1.9.0, an optional `target_price`)
	 * are trusted from the client — name/SKU/price are always re-derived from the
	 * live product record here, the same way `product_line_items()` already does,
	 * so a tampered client payload can't misstate what's actually being quoted.
	 *
	 * @param string $items_json JSON-encoded array of {product_id, quantity, variation_id, target_price}.
	 * @return array
	 */
	private function quote_list_line_items( $items_json ) {
		$this->target_prices = array();

		$items = array();
		foreach ( $this->decode_rows( $items_json ) as $row ) {
			$product = wc_get_product( $row['product_id'] );
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			// Same rule as the single-product path: quote the chosen variation, and skip a
			// variable product whose variation is missing or does not belong to it rather
			// than silently quoting the parent.
			$product = $this->resolve_purchasable( $product, $row['variation_id'] );
			if ( ! $product ) {
				continue;
			}
			$items[] = array(
				'sku'               => $product->get_sku(),
				'name'              => $product->get_name(),
				'quantity'          => $row['quantity'],
				'unitPrice'         => (float) wc_get_price_excluding_tax( $product ),
				'externalProductId' => (string) $product->get_id(),
			);
			if ( null !== $row['target_price'] ) {
				$this->target_prices[] = array(
					'name'     => (string) $product->get_name(),
					'sku'      => (string) $product->get_sku(),
					'quantity' => (int) $row['quantity'],
					'target'   => (float) $row['target_price'],
				);
			}
		}
		return $items;
	}

	/**
	 * Decode and bound the browser-submitted rows. Shared by the quote-list
	 * submission and the re-pricing call so the two accept exactly the same shape.
	 *
	 * Bounded before decoding, because json_decode() on an unbounded string from an
	 * unauthenticated endpoint is the cheap half of the attack; the expensive half is
	 * the wc_get_product() call the callers do per row.
	 *
	 * @since 1.9.0
	 *
	 * @param string $items_json Raw JSON.
	 * @return array<int, array{product_id:int,quantity:int,variation_id:int,target_price:float|null}>
	 */
	private function decode_rows( $items_json ) {
		if ( strlen( (string) $items_json ) > self::ITEMS_MAX_BYTES ) {
			return array();
		}
		$decoded = json_decode( (string) $items_json, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$rows = array();
		foreach ( $decoded as $row ) {
			if ( count( $rows ) >= self::ITEMS_MAX ) {
				break;
			}
			if ( ! is_array( $row ) || empty( $row['product_id'] ) ) {
				continue;
			}
			$target = null;
			if ( isset( $row['target_price'] ) && is_numeric( $row['target_price'] ) && (float) $row['target_price'] >= 0 ) {
				$target = round( (float) $row['target_price'], 4 );
			}
			$rows[] = array(
				'product_id'   => absint( $row['product_id'] ),
				'quantity'     => isset( $row['quantity'] ) ? max( 1, absint( $row['quantity'] ) ) : 1,
				'variation_id' => isset( $row['variation_id'] ) ? absint( $row['variation_id'] ) : 0,
				'target_price' => $target,
			);
		}
		return $rows;
	}

	/**
	 * The request note with the shopper's target prices appended, one line per
	 * product, bounded so the whole note stays under the API's 10,000-character limit.
	 *
	 * @since 1.9.0
	 *
	 * @param string $note     The shopper's note (already capped at NOTE_MAX_LENGTH).
	 * @param string $currency ISO 4217 code, or ''.
	 * @return string
	 */
	public function note_with_target_prices( $note, $currency ) {
		if ( empty( $this->target_prices ) ) {
			return $note;
		}
		$decimals = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;
		$lines    = array( __( 'Target prices:', 'tackquote' ) );
		foreach ( $this->target_prices as $t ) {
			$lines[] = sprintf(
				/* translators: 1: product name, 2: SKU, 3: quantity, 4: target unit price, 5: currency code. */
				__( '- %1$s (%2$s) x%3$d: %4$s %5$s', 'tackquote' ),
				$t['name'],
				'' === $t['sku'] ? '-' : $t['sku'],
				$t['quantity'],
				number_format( $t['target'], $decimals, '.', '' ),
				$currency
			);
		}
		$appendix = implode( "\n", $lines );
		$out      = '' === trim( $note ) ? $appendix : $note . "\n\n" . $appendix;
		// 2000 (note) + 100 lines of ~70 characters stays well inside the API's 10,000; the
		// cap below is a guard for a hostile payload, never a path a real shopper reaches.
		return mb_substr( $out, 0, 9000 );
	}

	/**
	 * AJAX: re-price the quote list for a signed-in buyer at the line quantities.
	 *
	 * One batched `POST /storefront-pricing/resolve` through `Tack_Wholesale_Pricing`
	 * — the same read that prices the cart, so the drawer and the cart cannot
	 * disagree. Signed-in only (no `nopriv` route), and a no-op when the merchant
	 * has not switched TackQuote prices on. A line TackQuote does not price answers
	 * `null`, which the script shows as the store price it already had.
	 *
	 * @since 1.9.0
	 */
	public function handle_reprice() {
		if ( ! check_ajax_referer( 'tack_request_quote', 'nonce', false ) ) {
			wp_send_json_error(
				array(
					'code'    => 'tack_nonce_expired',
					'message' => __( 'This page has been open too long, or was served from a cache. Reload it and try again.', 'tackquote' ),
				),
				403
			);
		}
		if ( ! $this->reprice_enabled() ) {
			wp_send_json_success( array( 'items' => array() ) );
		}

		$items_json = isset( $_POST['items'] ) ? sanitize_textarea_field( wp_unslash( $_POST['items'] ) ) : '';
		$asks       = array();
		$resolved   = array();
		foreach ( $this->decode_rows( $items_json ) as $row ) {
			$product = wc_get_product( $row['product_id'] );
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			$product = $this->resolve_purchasable( $product, $row['variation_id'] );
			if ( ! $product ) {
				continue;
			}
			$sku = (string) $product->get_sku();
			if ( '' === $sku ) {
				continue;
			}
			$asks[]     = array(
				'sku'      => $sku,
				'quantity' => $row['quantity'],
			);
			$resolved[] = array(
				'product_id'   => $row['product_id'],
				'variation_id' => $row['variation_id'],
				'quantity'     => $row['quantity'],
				'sku'          => $sku,
				'product'      => $product,
			);
		}
		if ( empty( $asks ) ) {
			wp_send_json_success( array( 'items' => array() ) );
		}

		$pricing = new Tack_Wholesale_Pricing();
		$prices  = $pricing->resolve( $asks );

		$out = array();
		foreach ( $resolved as $line ) {
			$key   = $line['sku'] . '|' . (int) $line['quantity'];
			$unit  = array_key_exists( $key, $prices ) ? $prices[ $key ] : null;
			$out[] = array(
				'product_id'   => $line['product_id'],
				'variation_id' => $line['variation_id'],
				'quantity'     => $line['quantity'],
				'unitPrice'    => $unit,
				'formatted'    => null === $unit || ! function_exists( 'wc_price' )
					? ''
					: html_entity_decode( wp_strip_all_tags( wc_price( Tack_Tax_Basis::display_price( $unit, $line['product'] ) ) ), ENT_QUOTES, 'UTF-8' ),
			);
		}

		wp_send_json_success( array( 'items' => $out ) );
	}
}
