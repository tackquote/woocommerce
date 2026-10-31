<?php
/**
 * Core loader — wires the plugin's components onto WordPress/WooCommerce hooks.
 *
 * @package TackQuotes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once TACK_QUOTES_DIR . 'includes/class-tack-settings.php';
require_once TACK_QUOTES_DIR . 'includes/class-tack-connect.php';
require_once TACK_QUOTES_DIR . 'includes/class-tack-api-client.php';
require_once TACK_QUOTES_DIR . 'includes/class-tack-storefront-cache.php';
require_once TACK_QUOTES_DIR . 'includes/class-tack-rate-limit.php';
require_once TACK_QUOTES_DIR . 'includes/class-tack-block-product.php';
require_once TACK_QUOTES_DIR . 'includes/class-tack-templates.php';
require_once TACK_QUOTES_DIR . 'includes/class-tack-widget.php';
require_once TACK_QUOTES_DIR . 'includes/class-tack-sync-gate.php';
require_once TACK_QUOTES_DIR . 'includes/class-tack-order-sync.php';
require_once TACK_QUOTES_DIR . 'includes/class-tack-price-access.php';
require_once TACK_QUOTES_DIR . 'includes/class-tack-catalog-mode.php';
require_once TACK_QUOTES_DIR . 'includes/class-tack-tax-basis.php';
require_once TACK_QUOTES_DIR . 'includes/class-tack-wholesale-pricing.php';
require_once TACK_QUOTES_DIR . 'includes/class-tack-b2b-notices.php';
require_once TACK_QUOTES_DIR . 'includes/class-tack-group-restrictions.php';
require_once TACK_QUOTES_DIR . 'includes/class-tack-catalog-visibility.php';
require_once TACK_QUOTES_DIR . 'includes/class-tack-role-mirror.php';
require_once TACK_QUOTES_DIR . 'includes/class-tack-net-terms-standing.php';
require_once TACK_QUOTES_DIR . 'includes/class-tack-storefront-forms.php';
require_once TACK_QUOTES_DIR . 'includes/class-tack-attachments.php';
require_once TACK_QUOTES_DIR . 'includes/class-tack-tax-exempt.php';
require_once TACK_QUOTES_DIR . 'includes/class-tack-po-number.php';
require_once TACK_QUOTES_DIR . 'includes/class-tack-quote-checkout.php';
require_once TACK_QUOTES_DIR . 'includes/class-tack-quote-form.php';
require_once TACK_QUOTES_DIR . 'includes/class-tack-quick-order.php';
require_once TACK_QUOTES_DIR . 'includes/class-tack-standing.php';
require_once TACK_QUOTES_DIR . 'includes/class-tack-store-settings.php';
require_once TACK_QUOTES_DIR . 'includes/class-tack-rest-keys.php';
require_once TACK_QUOTES_DIR . 'includes/class-tack-lifecycle.php';
require_once TACK_QUOTES_DIR . 'includes/class-tack-update-notice.php';
require_once TACK_QUOTES_DIR . 'includes/class-tack-embed-admin.php';

/**
 * Main plugin class (singleton).
 */
final class Tack_Quotes {

	/**
	 * The single instance of this class.
	 *
	 * @var Tack_Quotes|null
	 */
	private static $instance = null;

	/**
	 * The settings screen handler.
	 *
	 * @var Tack_Settings
	 */
	public $settings;

	/**
	 * Singleton accessor.
	 *
	 * @return Tack_Quotes
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register all hooks. Called once on plugins_loaded.
	 */
	public function init() {
		$this->settings = new Tack_Settings();
		$this->settings->init();

		// TackQuote → Dashboard (1.12.0 under WooCommerce; 1.13.0 in the TackQuote menu).
		if ( is_admin() ) {
			( new Tack_Embed_Admin() )->init();
		}

		// 1.12.0: catalogue and order sync through WooCommerce's own key approval, the
		// disconnect stand-down, and (in the GitHub release zip only) WordPress updates
		// from the GitHub release. The updater file is absent from the WordPress.org build.
		( new Tack_Rest_Keys() )->init();
		Tack_Standing::init();
		// 1.13.0: the settings TackQuote owns (order sync, features, groups, wholesale form),
		// read on the same background refresh, never on a shopper's request.
		Tack_Store_Settings::init();
		// 1.14.0: storefront lookups cached for page views are forgotten when the
		// connection or TackQuote's store settings change (Tack_Storefront_Cache).
		Tack_Storefront_Cache::init();
		// Why shoppers' quote requests fail, for administrators (the shopper sees a calm sentence).
		add_action( 'admin_notices', array( 'Tack_Widget', 'failure_notice' ) );
		$updater = TACK_QUOTES_DIR . 'includes/class-tack-release-updates.php';
		if ( is_readable( $updater ) ) {
			require_once $updater;
			Tack_Release_Updates::init();
		}

		// Stood down: TackQuote answered that this store's key is revoked or the store is
		// disconnected (Tack_Standing; never on a network error). The storefront buttons,
		// quote list, quote page, application forms and accepted-quote checkout links are
		// hidden until a working key is saved. Store policies the merchant set (quote-only
		// mode, prices, restrictions) are left exactly as they are.
		$stood_down = Tack_Standing::is_stood_down();
		if ( $stood_down ) {
			self::hide_storefront();
		}

		// Quote-only (B2B catalog) mode. Registered unconditionally; the class
		// re-checks whether it applies per request, because the current user is
		// not resolved this early and a page cache would freeze a wrong answer.
		( new Tack_Catalog_Mode() )->init();

		// B2B pricing resolved by Tack. Registered only when the merchant switched
		// it on: it filters `woocommerce_product_get_price`, which reaches the cart
		// and the order, so it must not attach itself by default on an update.
		if ( Tack_Wholesale_Pricing::is_enabled() ) {
			( new Tack_Wholesale_Pricing() )->init();
		}

		// Order limits and the buyer-group badge. Each half has its own switch;
		// the class registers only the hooks whose switch is on.
		// Armed unconditionally, and deliberately so. It protects an entitlement
		// read by both the pricing and the restriction features, and a store that
		// switches one of them on later must not inherit a customer base whose
		// email changes went unnoticed while it was off.
		Tack_B2B_Notices::register_email_trust_guard();

		$b2b_notices = new Tack_B2B_Notices();
		if ( Tack_B2B_Notices::is_enabled() ) {
			$b2b_notices->init();
		}

		// Payment/shipping methods gated by buyer group. Shares the notices
		// instance so the buyer group is looked up ONCE per request rather than
		// once for the badge and again for the gateway filter.
		// 1.10.0: shipping discounts per group run inside the SAME package-rates
		// callback, after the restrictions, so the class attaches when either is on.
		if ( Tack_Group_Restrictions::needs_hooks() ) {
			( new Tack_Group_Restrictions( $b2b_notices ) )->init();
		}

		// 1.10.0: product categories hidden per buyer group, and the optional
		// WordPress role mirror. Both off by default; both share the one lookup.
		if ( Tack_Catalog_Visibility::is_enabled() ) {
			( new Tack_Catalog_Visibility( $b2b_notices ) )->init();
		}
		if ( Tack_Role_Mirror::is_enabled() ) {
			( new Tack_Role_Mirror( $b2b_notices ) )->init();
		}

		// Wholesale and net-terms application forms: shortcodes, My Account tabs
		// and the admin-post handlers. Registered unconditionally so the rewrite
		// endpoints always exist; the tab switches only decide what the menu shows.
		( new Tack_Storefront_Forms() )->init();

		// 1.10.0: the quote-request attachment upload handler. Registered always; it
		// refuses unless "Allow attachments on quote requests" is on AND the server
		// advertises `attachments`.
		( new Tack_Attachments() )->init();

		// Tax exemption TackQuote grants a buyer. Changes what checkout charges,
		// so it attaches only when the merchant switched it on (default off).
		if ( Tack_Tax_Exempt::is_enabled() ) {
			( new Tack_Tax_Exempt() )->init();
		}

		// "Net terms (TackQuote)" payment gateway (classic + Checkout block) and the
		// optional checkout PO number. The gateway is always REGISTERED so the
		// merchant can find it under WooCommerce > Settings > Payments, but it ships
		// disabled and is offered only to buyers TackQuote confirms (fail closed).
		add_filter( 'woocommerce_payment_gateways', array( __CLASS__, 'register_gateway' ) );
		add_action( 'woocommerce_blocks_payment_method_type_registration', array( __CLASS__, 'register_block_payment_method' ) );
		// 1.12.0: the Checkout block re-checks net terms whenever the cart changes. The
		// Store API cart carries this store's own yes/no (never the credit line), and the
		// payment method's canMakePayment() reads it (assets/js/tack-net-terms-block.js).
		if ( did_action( 'woocommerce_blocks_loaded' ) ) {
			self::register_net_terms_store_api();
			self::register_cart_item_store_api();
		} else {
			add_action( 'woocommerce_blocks_loaded', array( __CLASS__, 'register_net_terms_store_api' ) );
			add_action( 'woocommerce_blocks_loaded', array( __CLASS__, 'register_cart_item_store_api' ) );
		}
		// 1.12.0: "Request a quote for your cart" inside the Cart block (Blocks Integration API).
		add_action( 'woocommerce_blocks_cart_block_registration', array( __CLASS__, 'register_cart_block_integration' ) );
		( new Tack_Po_Number() )->init();

		// Accepted quote -> store checkout (`?tackquote_checkout=<token>`). Only for a
		// connected store: without an API key no such link can exist.
		if ( ! $stood_down && Tack_Quote_Checkout::is_enabled() ) {
			( new Tack_Quote_Checkout() )->init();
		}

		// Frontend "Request a Quote" widget/button.
		if ( ! $stood_down && 'yes' === get_option( 'tack_quotes_enable_widget', 'yes' ) ) {
			( new Tack_Widget() )->init();
		}

		// Order sync to the Tack API. The deferred worker is registered unconditionally — see
		// Tack_Order_Sync::register_worker() — while the order hooks that queue work are only
		// attached when the merchant has switched sync on.
		$order_sync = new Tack_Order_Sync();
		$order_sync->register_worker();
		if ( Tack_Order_Sync::is_enabled() ) {
			$order_sync->init();
		}

		// Settings action link on the plugins list.
		add_filter( 'plugin_action_links_' . plugin_basename( TACK_QUOTES_FILE ), array( $this, 'action_links' ) );

		// Suggested privacy policy text. `wp_add_privacy_policy_content()` must be called on
		// admin_init or later — it errors out otherwise — which is why this is a separate
		// hook rather than an inline call here on plugins_loaded.
		add_action( 'admin_init', array( $this, 'add_privacy_policy_content' ) );

		// Tools > Export / Erase Personal Data: the user meta this plugin keeps.
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_privacy_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_privacy_eraser' ) );

		// The same tools, through WooCommerce's order exporter and eraser: the order meta
		// this plugin writes. Filters as named in WooCommerce's class-wc-privacy-exporters.php
		// and class-wc-privacy-erasers.php (`@since 3.4.0`).
		add_filter( 'woocommerce_privacy_export_order_personal_data_meta', array( __CLASS__, 'add_order_meta_to_export' ) );
		add_filter( 'woocommerce_privacy_export_order_personal_data_meta_value', array( __CLASS__, 'format_order_meta_export' ), 10, 2 );
		add_filter( 'woocommerce_privacy_remove_order_personal_data_meta', array( __CLASS__, 'add_order_meta_to_erase' ) );
		add_filter( 'woocommerce_privacy_remove_order_personal_data_meta_value', array( __CLASS__, 'erase_order_meta_value' ), 10, 2 );

		// The textdomain is registered on `init` by tack_quotes_load_textdomain() in
		// tackquote.php, not here: this runs on plugins_loaded, which WordPress 6.7+
		// reports as too early for translation loading.
	}

	/**
	 * While stood down (Tack_Standing): the shortcodes render nothing (the quote page's and
	 * quick order's are registered empty, because the widget that owns them is not loaded, and WordPress would
	 * otherwise print the bare shortcode), and the Wholesale account and Net terms tabs
	 * leave the My Account menu.
	 *
	 * @since 1.12.0
	 */
	public static function hide_storefront() {
		add_shortcode( 'tackquote_quote_page', '__return_empty_string' );
		// Quick order boots inside the widget too (1.12.0), so its shortcode is registered
		// empty the same way. Its block renders nothing unregistered: it saves no markup.
		add_shortcode( Tack_Quick_Order::SHORTCODE, '__return_empty_string' );
		add_filter(
			'pre_do_shortcode_tag',
			function ( $output, $tag ) {
				return in_array( $tag, array( 'tackquote_wholesale_application', 'tackquote_net_terms_application' ), true ) ? '' : $output;
			},
			10,
			2
		);
		add_filter(
			'woocommerce_account_menu_items',
			function ( $items ) {
				if ( is_array( $items ) ) {
					unset( $items[ Tack_Storefront_Forms::ENDPOINT_WHOLESALE ], $items[ Tack_Storefront_Forms::ENDPOINT_NET_TERMS ] );
				}
				return $items;
			},
			99
		);
	}

	/**
	 * Load the net-terms gateway class. Only once WooCommerce's gateway base exists.
	 *
	 * @return bool Whether Tack_Gateway_Net_Terms is available.
	 */
	public static function load_gateway() {
		if ( class_exists( 'Tack_Gateway_Net_Terms', false ) ) {
			return true;
		}
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			return false;
		}
		require_once TACK_QUOTES_DIR . 'includes/gateways/class-tack-gateway-net-terms.php';
		return true;
	}

	/**
	 * Add the net-terms gateway to WooCommerce's list (`woocommerce_payment_gateways`).
	 *
	 * @param array $gateways Gateway class names or instances.
	 * @return array
	 */
	public static function register_gateway( $gateways ) {
		$gateways = is_array( $gateways ) ? $gateways : array();
		if ( self::load_gateway() ) {
			$gateways[] = 'Tack_Gateway_Net_Terms';
		}
		return $gateways;
	}

	/**
	 * Register the Checkout block integration, only when the Blocks classes exist.
	 *
	 * @param object $registry Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry.
	 */
	public static function register_block_payment_method( $registry ) {
		if ( ! is_object( $registry ) || ! method_exists( $registry, 'register' ) ) {
			return;
		}
		if ( ! class_exists( 'Automattic\\WooCommerce\\Blocks\\Payments\\Integrations\\AbstractPaymentMethodType' ) || ! self::load_gateway() ) {
			return;
		}
		require_once TACK_QUOTES_DIR . 'includes/blocks/class-tack-net-terms-block.php';
		$registry->register( new Tack_Net_Terms_Block() );
	}

	/** Store API namespace of the plugin's cart data (`extensions.tackquote`). */
	const STORE_API_NAMESPACE = 'tackquote';

	/**
	 * Add `extensions.tackquote.netTermsAvailable` to the Store API cart
	 * (`woocommerce_store_api_register_endpoint_data`, ExtendSchema; WooCommerce
	 * 11.2.1 src/StoreApi/functions.php). The Cart and Checkout blocks re-read the
	 * cart after every change, and WooCommerce re-runs each payment method's
	 * canMakePayment() with that cart (`extensions` included) when the cart store
	 * changes (wc-blocks-data, 11.2.1).
	 */
	public static function register_net_terms_store_api() {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
			return;
		}
		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => class_exists( 'Automattic\\WooCommerce\\StoreApi\\Schemas\\V1\\CartSchema' )
					? \Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema::IDENTIFIER
					: 'cart',
				'namespace'       => self::STORE_API_NAMESPACE,
				'data_callback'   => array( __CLASS__, 'net_terms_cart_data' ),
				'schema_callback' => array( __CLASS__, 'net_terms_cart_schema' ),
				'schema_type'     => ARRAY_A,
			)
		);
	}

	/**
	 * Add `extensions.tackquote` to each Store API cart item: the product and variation
	 * ids and the line's name as a quote line names it (1.12.0).
	 *
	 * The Store API's own cart item gives `id` = the VARIATION id for a variation and no
	 * parent id (CartItemSchema::get_item_response(), WooCommerce 11.2.1), so "Request a
	 * quote for your cart" in the Cart block could only send the bare variation id: the
	 * line lost its variation label and did not merge with the same variation added from
	 * the product page. The cart line itself knows both ids (`product_id`, `variation_id`).
	 */
	public static function register_cart_item_store_api() {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
			return;
		}
		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => class_exists( 'Automattic\\WooCommerce\\StoreApi\\Schemas\\V1\\CartItemSchema' )
					? \Automattic\WooCommerce\StoreApi\Schemas\V1\CartItemSchema::IDENTIFIER
					: 'cart-item',
				'namespace'       => self::STORE_API_NAMESPACE,
				'data_callback'   => array( __CLASS__, 'cart_item_data' ),
				'schema_callback' => array( __CLASS__, 'cart_item_schema' ),
				'schema_type'     => ARRAY_A,
			)
		);
	}

	/**
	 * One cart line as the quote list keys it.
	 *
	 * @param array $cart_item WooCommerce cart item.
	 * @return array{productId:int,variationId:int,name:string,sku:string,price:float,attributes:object}|array
	 */
	public static function cart_item_data( $cart_item = array() ) {
		$product = is_array( $cart_item ) && isset( $cart_item['data'] ) && is_object( $cart_item['data'] ) ? $cart_item['data'] : null;
		if ( null === $product || empty( $cart_item['product_id'] ) ) {
			return array();
		}
		$variation_id = (int) ( $cart_item['variation_id'] ?? 0 );
		return array(
			'productId'   => (int) $cart_item['product_id'],
			'variationId' => $variation_id,
			'name'        => $variation_id > 0 ? Tack_Block_Product::cart_line_name( $cart_item, $product ) : ( method_exists( $product, 'get_name' ) ? (string) $product->get_name() : '' ),
			'sku'         => method_exists( $product, 'get_sku' ) ? (string) $product->get_sku() : '',
			'price'       => function_exists( 'wc_get_price_excluding_tax' ) ? (float) wc_get_price_excluding_tax( $product ) : 0.0,
			// The line's "Any …" attribute values (1.12.0), so the quote line keeps them.
			'attributes'  => (object) Tack_Widget::cart_any_attributes( $cart_item, $product ),
		);
	}

	/**
	 * Schema of cart_item_data().
	 *
	 * @return array
	 */
	public static function cart_item_schema() {
		$field = function ( $type, $description ) {
			return array(
				'description' => $description,
				'type'        => $type,
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			);
		};
		return array(
			'productId'   => $field( 'integer', 'Product id (the parent, for a variation).' ),
			'variationId' => $field( 'integer', 'Variation id, or 0.' ),
			'name'        => $field( 'string', 'The line name as a quote line names it.' ),
			'sku'         => $field( 'string', 'SKU.' ),
			'price'       => $field( 'number', 'Unit price excluding tax.' ),
			'attributes'  => $field( 'object', 'Chosen values of the variation\'s "Any" attributes, keyed attribute_*.' ),
		);
	}

	/**
	 * The cart's net-terms answer: whether the gateway would be offered for THIS cart,
	 * the same fail-closed decision as the classic checkout (is_available()). Nothing
	 * else about the buyer's credit line. Empty when the gateway is off, so a store that
	 * never enabled it pays no standing read.
	 *
	 * @return array
	 */
	public static function net_terms_cart_data() {
		if ( ! self::load_gateway() ) {
			return array();
		}
		$settings = get_option( 'woocommerce_' . Tack_Gateway_Net_Terms::ID . '_settings', array() );
		if ( ! is_array( $settings ) || ! isset( $settings['enabled'] ) || 'yes' !== $settings['enabled'] ) {
			return array();
		}
		$gateway  = null;
		$registry = function_exists( 'WC' ) && is_object( WC() ) && method_exists( WC(), 'payment_gateways' ) ? WC()->payment_gateways() : null;
		if ( is_object( $registry ) && method_exists( $registry, 'payment_gateways' ) ) {
			$all     = (array) $registry->payment_gateways();
			$gateway = isset( $all[ Tack_Gateway_Net_Terms::ID ] ) ? $all[ Tack_Gateway_Net_Terms::ID ] : null;
		}
		$available = false;
		if ( $gateway instanceof Tack_Gateway_Net_Terms ) {
			try {
				$available = true === $gateway->is_available();
			} catch ( Exception $e ) {
				$available = false; // Fail closed.
			}
		}
		return array( 'netTermsAvailable' => $available );
	}

	/**
	 * Schema of net_terms_cart_data().
	 *
	 * @return array
	 */
	public static function net_terms_cart_schema() {
		return array(
			'netTermsAvailable' => array(
				'description' => 'Whether TackQuote net terms would be offered for this cart.',
				'type'        => 'boolean',
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
		);
	}

	/**
	 * Register the Cart block integration (`woocommerce_blocks_cart_block_registration`),
	 * only when WooCommerce's IntegrationInterface exists.
	 *
	 * @param object $registry Automattic\WooCommerce\Blocks\Integrations\IntegrationRegistry.
	 */
	public static function register_cart_block_integration( $registry ) {
		if ( ! interface_exists( 'Automattic\\WooCommerce\\Blocks\\Integrations\\IntegrationInterface' ) || 'yes' !== get_option( 'tack_quotes_enable_widget', 'yes' ) ) {
			return;
		}
		require_once TACK_QUOTES_DIR . 'includes/blocks/class-tack-cart-quote-block.php';
		Tack_Cart_Quote_Block::register( $registry );
	}

	/**
	 * Register suggested privacy policy text on the Privacy Settings screen.
	 *
	 * This plugin sends personal data to a third party, so a merchant needs to be able to
	 * disclose exactly what and to where. Enumerating the fields rather than gesturing at
	 * "order data" is the point: a policy that says less than the plugin sends is not a
	 * policy.
	 */
	public function add_privacy_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$api_url = (string) get_option( 'tack_quotes_api_url', 'https://api.tackquote.com/v1' );

		$content =
			'<p class="privacy-policy-tutorial">'
			. esc_html__( 'Suggested text for stores using TackQuote: Request a Quote & B2B Wholesale for WooCommerce. Edit it to match how your store actually uses the plugin.', 'tackquote' )
			. '</p><p><strong>' . esc_html__( 'Quote requests', 'tackquote' ) . '</strong><br />'
			. esc_html__( 'When you request a quote, we send the details you enter in the quote form to our quoting provider, TackQuote: your email address, first and last name, phone number, and — if you are buying on behalf of a company — your company name and any company details the form asks for, together with any note you write. We also send the products, quantities, prices and currency you are asking to be quoted.', 'tackquote' )
			. '</p><p><strong>' . esc_html__( 'Files you attach (only if the store owner has switched attachments on)', 'tackquote' ) . '</strong><br />'
			. esc_html__( 'If you attach files to a quote request or to a wholesale application, each file (a PDF, JPEG or PNG of at most 5 MB) and its file name are sent to TackQuote, together with your account email address and your customer account number on this store when you are signed in. The file is not stored on this website. TackQuote keeps it for the seller to review; a file that is never attached to a request is deleted after 24 hours (quote requests) or 7 days (applications).', 'tackquote' )
			. '</p><p><strong>' . esc_html__( 'Order sync (only if the store owner has switched it on)', 'tackquote' ) . '</strong><br />'
			. esc_html__( 'When an order is placed or its status changes, we send that order to TackQuote. This includes your full billing address and your full shipping address — name, company, street, city, state or county, postal code, country, email address and phone number — along with any note you left with the order. It also includes the order number and internal order ID, its status, currency, item subtotal, discount, shipping cost, tax and total, any coupon codes used, the payment method and the payment reference our gateway issued, and the created, modified, paid and completed dates. Each line includes the product name, SKU, product and variation IDs, quantity, price, tax and the options chosen (for example size or colour). No card number or card details are ever sent.', 'tackquote' )
			. '</p><p><strong>' . esc_html__( 'Net terms at checkout (only if the store owner has switched it on)', 'tackquote' ) . '</strong><br />'
			. esc_html__( 'When you are signed in and view the checkout, and again when you place an order on net terms, we ask TackQuote whether your account may pay on net terms. We send your account email address and your customer account number on this store. TackQuote answers with your terms and credit status; that answer is kept on this store for at most one minute and is never shown in your browser. If you enter a purchase order number at checkout, it is saved on your order and sent to TackQuote with the order.', 'tackquote' )
			. '</p><p><strong>' . esc_html__( 'Where it goes', 'tackquote' ) . '</strong><br />'
			. sprintf(
				/* translators: %s: the configured TackQuote API base URL. */
				esc_html__( 'Data is sent over HTTPS to the TackQuote API at %s. No payment card data is sent. Nothing is shared with any other third party by this plugin.', 'tackquote' ),
				'<code>' . esc_html( $api_url ) . '</code>'
			)
			. '</p>';

		wp_add_privacy_policy_content( __( 'TackQuote: Request a Quote & B2B Wholesale for WooCommerce', 'tackquote' ), $content );
	}

	/**
	 * User meta this plugin writes about a customer, key => label for an export.
	 *
	 * `_tack_known_email` is a copy of the account's email address (the email-trust
	 * guard compares against it); the rest are flags and the roles the role mirror added.
	 *
	 * @since 1.10.0
	 *
	 * @return array<string,string>
	 */
	public static function privacy_user_meta() {
		return array(
			'_tack_known_email'         => __( 'Email address last confirmed for TackQuote', 'tackquote' ),
			'_tack_email_unverified'    => __( 'Email change awaiting confirmation', 'tackquote' ),
			'_tack_mirrored_roles'      => __( 'Roles added from your TackQuote buyer group', 'tackquote' ),
			'_tack_role_mirror_checked' => __( 'Buyer group last checked', 'tackquote' ),
			'_tack_wholesale_applied'   => __( 'Wholesale application sent for review', 'tackquote' ),
		);
	}

	/**
	 * `wp_privacy_personal_data_exporters`.
	 *
	 * @param array $exporters Registered exporters.
	 * @return array
	 */
	public static function register_privacy_exporter( $exporters ) {
		$exporters              = is_array( $exporters ) ? $exporters : array();
		$exporters['tackquote'] = array(
			'exporter_friendly_name' => __( 'TackQuote: Request a Quote & B2B Wholesale for WooCommerce', 'tackquote' ),
			'callback'               => array( __CLASS__, 'export_personal_data' ),
		);
		return $exporters;
	}

	/**
	 * `wp_privacy_personal_data_erasers`.
	 *
	 * @param array $erasers Registered erasers.
	 * @return array
	 */
	public static function register_privacy_eraser( $erasers ) {
		$erasers              = is_array( $erasers ) ? $erasers : array();
		$erasers['tackquote'] = array(
			'eraser_friendly_name' => __( 'TackQuote: Request a Quote & B2B Wholesale for WooCommerce', 'tackquote' ),
			'callback'             => array( __CLASS__, 'erase_personal_data' ),
		);
		return $erasers;
	}

	/**
	 * Export the user meta of the account that owns this email address.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page (one page is always enough).
	 * @return array{data:array,done:bool}
	 */
	public static function export_personal_data( $email, $page = 1 ) {
		unset( $page );
		$user = get_user_by( 'email', (string) $email );
		$data = array();
		foreach ( $user ? self::privacy_user_meta() : array() as $key => $label ) {
			$value = get_user_meta( $user->ID, $key, true );
			if ( '' === $value || array() === $value || null === $value ) {
				continue;
			}
			if ( in_array( $key, array( '_tack_role_mirror_checked', '_tack_wholesale_applied' ), true ) && is_numeric( $value ) ) {
				$value = gmdate( 'c', (int) $value );
			}
			$data[] = array(
				'name'  => $label,
				'value' => is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value,
			);
		}
		$items = array();
		if ( ! empty( $data ) ) {
			$items[] = array(
				'group_id'    => 'tackquote',
				'group_label' => __( 'TackQuote', 'tackquote' ),
				'item_id'     => 'tackquote-user-' . (int) $user->ID,
				'data'        => $data,
			);
		}
		return array(
			'data' => $items,
			'done' => true,
		);
	}

	/**
	 * Erase the user meta of the account that owns this email address.
	 *
	 * Roles the mirror added stay on the account (removing a role is an access change the
	 * store makes, not data erasure); only this plugin's record of them is deleted.
	 *
	 * The "email change awaiting confirmation" flag is RETAINED. It holds no personal data,
	 * and deleting it would make TackQuote trust a self-changed address again: an erasure
	 * must never grant another buyer's prices or payment terms.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page (one page is always enough).
	 * @return array{items_removed:bool,items_retained:bool,messages:array,done:bool}
	 */
	public static function erase_personal_data( $email, $page = 1 ) {
		unset( $page );
		$user     = get_user_by( 'email', (string) $email );
		$removed  = false;
		$retained = false;
		$messages = array();
		foreach ( $user ? array_keys( self::privacy_user_meta() ) : array() as $key ) {
			if ( '' === get_user_meta( $user->ID, $key, true ) ) {
				continue;
			}
			if ( Tack_B2B_Notices::META_EMAIL_UNVERIFIED === $key ) {
				$retained   = true;
				$messages[] = __( 'TackQuote kept a flag saying this account\'s email change is unconfirmed. It holds no personal data and stops the account from receiving another buyer\'s prices or payment terms.', 'tackquote' );
				continue;
			}
			delete_user_meta( $user->ID, $key );
			$removed = true;
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => $messages,
			'done'           => true,
		);
	}

	/**
	 * Order meta this plugin writes, key => label for WooCommerce's order export.
	 *
	 * Literals rather than the owning classes' constants (Tack_Po_Number::META_KEY,
	 * Tack_Quote_Checkout::META_REF / META_NUMBER, Tack_Gateway_Net_Terms::META_TERMS),
	 * because the gateway class is loaded only once WooCommerce's gateway base exists;
	 * tests/final-polish-test.php pins each literal to its constant.
	 *
	 * @since 1.10.0
	 *
	 * @return array<string,string>
	 */
	public static function privacy_order_meta() {
		return array(
			'_tackquote_po_number'    => __( 'Purchase order number', 'tackquote' ),
			'_tackquote_quote_ref'    => __( 'TackQuote quote reference', 'tackquote' ),
			'_tackquote_quote_number' => __( 'TackQuote quote number', 'tackquote' ),
			'_tackquote_net_terms'    => __( 'Net payment terms', 'tackquote' ),
		);
	}

	/**
	 * Order meta erased on request, key => wp_privacy_anonymize_data() type.
	 *
	 * Only the purchase-order number: the buyer typed it, so it is personal data. The
	 * quote reference and number are the seller's business identifiers and the net terms
	 * are the payment terms of the sale; they stay with the order like its totals do.
	 *
	 * @since 1.10.0
	 *
	 * @return array<string,string>
	 */
	public static function privacy_order_meta_erased() {
		return array( '_tackquote_po_number' => 'text' );
	}

	/**
	 * `woocommerce_privacy_export_order_personal_data_meta`.
	 *
	 * @param array $meta Meta key => label.
	 * @return array
	 */
	public static function add_order_meta_to_export( $meta ) {
		return array_merge( is_array( $meta ) ? $meta : array(), self::privacy_order_meta() );
	}

	/**
	 * `woocommerce_privacy_export_order_personal_data_meta_value`: the net-terms record
	 * is an array, and an export holds text.
	 *
	 * @param mixed  $value    Stored value.
	 * @param string $meta_key Meta key.
	 * @return mixed
	 */
	public static function format_order_meta_export( $value, $meta_key ) {
		if ( '_tackquote_net_terms' !== $meta_key || ! is_array( $value ) ) {
			return $value;
		}
		$days    = isset( $value['termsDays'] ) ? (int) $value['termsDays'] : 0;
		$checked = isset( $value['checkedAt'] ) ? (string) $value['checkedAt'] : '';
		if ( $days <= 0 ) {
			return '';
		}
		return '' === $checked
			/* translators: %d: net payment terms in days, for example 30. */
			? sprintf( __( '%d days', 'tackquote' ), $days )
			/* translators: 1: net payment terms in days, for example 30. 2: when the terms were checked, an ISO 8601 date and time. */
			: sprintf( __( '%1$d days (checked %2$s)', 'tackquote' ), $days, $checked );
	}

	/**
	 * `woocommerce_privacy_remove_order_personal_data_meta`.
	 *
	 * Runs only when the store has "Remove personal data from orders on request" switched
	 * on (WooCommerce → Settings → Accounts & Privacy), like the rest of the order eraser.
	 *
	 * @param array $meta Meta key => data type.
	 * @return array
	 */
	public static function add_order_meta_to_erase( $meta ) {
		return array_merge( is_array( $meta ) ? $meta : array(), self::privacy_order_meta_erased() );
	}

	/**
	 * `woocommerce_privacy_remove_order_personal_data_meta_value`: delete the PO number
	 * rather than store WordPress's "[deleted]" placeholder, which order sync would then
	 * send to TackQuote as the purchase-order number. An empty value makes WooCommerce
	 * call delete_meta_data().
	 *
	 * @param string $anon_value Anonymised value.
	 * @param string $meta_key   Meta key.
	 * @return string
	 */
	public static function erase_order_meta_value( $anon_value, $meta_key ) {
		return array_key_exists( (string) $meta_key, self::privacy_order_meta_erased() ) ? '' : $anon_value;
	}

	/**
	 * Add a "Settings" link on the plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function action_links( $links ) {
		// Derive the slug from the constant the menu is actually registered with.
		// This literal was correct when PAGE_SLUG was 'tack-quotes'; the later
		// renames (to 'tackquote-for-woocommerce', then to the WordPress.org slug
		// 'tackquote') moved the constant and left the literal behind. The link then
		// pointed at an unregistered page, and wp-admin/admin.php answers that with
		// "Sorry, you are not allowed to access this page." — the same sentence it
		// uses for a capability failure, which sends the diagnosis the wrong way.
		$url  = admin_url( 'admin.php?page=' . rawurlencode( Tack_Settings::PAGE_SLUG ) );
		$link = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'tackquote' ) . '</a>';
		array_unshift( $links, $link );
		return $links;
	}

	/**
	 * Activation: set sane defaults. Does not overwrite existing values.
	 */
	public static function activate() {
		add_option( 'tack_quotes_api_url', 'https://api.tackquote.com/v1' );
		add_option( 'tack_quotes_enable_widget', 'yes' );
		// Order sync ships OFF. It sends personal data to a third party, so it is the
		// merchant's decision to make, not a default to be discovered later.
		add_option( 'tack_quotes_enable_order_sync', 'no' );
		// The button labels are NOT stored here (they were until 1.10.0): a stored default
		// freezes the activation-time language. Blank means the translated default; see
		// Tack_Widget::button_label().
		add_option( 'tack_quotes_show_add_to_quote', 'yes' );
		add_option( 'tack_quotes_show_request_quote', 'yes' );
		// `tack_quotes_schema_version` is no longer written (1.13.0): nothing ever read it,
		// and a value frozen at the first activation's version only misled. uninstall.php
		// still deletes rows earlier versions left.
		// Storefront application forms ship OFF: a merchant adds the tabs deliberately.
		add_option( Tack_Storefront_Forms::OPTION_FORM_SLUG, Tack_Storefront_Forms::DEFAULT_SLUG );
		add_option( Tack_Storefront_Forms::OPTION_WHOLESALE_TAB, 'no' );
		add_option( Tack_Storefront_Forms::OPTION_NET_TERMS_TAB, 'no' );
		add_option( Tack_Tax_Exempt::OPTION_ENABLED, 'no' );
		add_option( Tack_Catalog_Visibility::OPTION_ENABLED, 'no' );
		add_option( Tack_Group_Restrictions::OPTION_DISCOUNTS_ENABLED, 'no' );
		add_option( Tack_Role_Mirror::OPTION_ENABLED, 'no' );

		// The My Account endpoints must be in the rewrite rules before they are
		// flushed (WooCommerce's own guide for custom account tabs), and
		// WooCommerce's init already ran without this plugin's filter attached.
		Tack_Storefront_Forms::register_endpoints();
		flush_rewrite_rules();
		update_option( Tack_Storefront_Forms::OPTION_REWRITE_VERSION, TACK_QUOTES_VERSION, false );
	}

	/**
	 * Deactivation: clear scheduled events and the account endpoints' rewrite rules.
	 */
	public static function deactivate() {
		// 1.12.0: tell TackQuote (best effort, short) so it pauses this store's connection;
		// the next request after reactivation resumes the same connection.
		Tack_Lifecycle::notify_deactivated();
		wp_unschedule_hook( Tack_Standing::REFRESH_HOOK );
		// The order-sync jobs, in Action Scheduler (group `tackquote`) and the WP-Cron
		// fallback. This used to clear `tack_quotes_retry_sync`, a hook nothing schedules,
		// and left the real jobs queued.
		foreach ( array( Tack_Order_Sync::SYNC_HOOK, Tack_Order_Sync::REQUEUE_HOOK ) as $hook ) {
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( $hook, array(), Tack_Order_Sync::SYNC_GROUP );
			}
			wp_unschedule_hook( $hook );
		}
		// The account endpoints leave the rewrite rules with the plugin.
		flush_rewrite_rules();
		delete_option( Tack_Storefront_Forms::OPTION_REWRITE_VERSION );
	}
}
