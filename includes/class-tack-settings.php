<?php
/**
 * Settings page (WordPress Settings API) — TackQuote API key, API URL, and feature toggles.
 *
 * Since 1.10.0 the page is split into tabs: an Overview dashboard plus one tab per
 * area, each tab its own form with its own Settings API option group.
 *
 * @package TackQuotes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the admin settings screen and options.
 */
class Tack_Settings {

	/**
	 * Prefix of every option group. Each tab registers `OPTION_GROUP . '_' . $tab`.
	 *
	 * WHY ONE GROUP PER TAB, AND NOT ONE GROUP FOR THE WHOLE PAGE. `options.php`
	 * walks every option registered in the posted group and calls
	 * `update_option( $option, $value )`, passing NULL for any option absent from
	 * the POST. With a single group and one form per tab, saving the Storefront tab
	 * would post nothing for the B2B pricing switches, and `sanitize_checkbox( null )`
	 * answers 'no': every switch on every other tab would be turned off by an
	 * unrelated save. A group per tab means a save only ever touches the options
	 * its own form drew. `settings-page-test.php` pins both directions.
	 */
	const OPTION_GROUP = 'tack_quotes_settings';
	const PAGE_SLUG    = 'tackquote';

	/**
	 * The tab shown when none (or an unknown one) is requested.
	 */
	const DEFAULT_TAB = 'overview';

	/**
	 * Transient remembering the outcome of the last "Test connection" press.
	 *
	 * Holds `ok` (bool), `key` (a 16-character SHA-256 prefix of the key that was
	 * tested, never the key), `at` (Unix time) and `message`. The Overview only says
	 * "Connected" when this exists, passed, AND was recorded for the key saved now.
	 */
	const CONNECTION_CHECK = 'tack_quotes_connection_check';

	/**
	 * The buyer group codes this store uses, as the merchant declared them.
	 *
	 * WHY THIS OPTION EXISTS AT ALL. Restricting a gateway to a buyer group
	 * needs the group's CODE, and the plugin cannot ask TackQuote what codes
	 * exist: the only endpoint that lists buyer groups
	 * (`GET /v1/buyer-groups`) is behind a seller JWT and the RolesGuard, and
	 * the one route a store API key can reach — `GET /v1/storefront-b2b/buyer-group`
	 * — answers "which group is THIS ONE BUYER in" and returns a single row.
	 * There is no API-key-authenticated way to enumerate them. (Verified
	 * against the TackQuote API source, not against this plugin's own tests.)
	 *
	 * So the merchant states their codes ONCE, here, and every rule below is
	 * then a checkbox. The alternative — which this replaces — was retyping
	 * the codes on every line of two free-text boxes, where a typo silently
	 * hid a payment method from every customer.
	 *
	 * Seeded automatically from rules that already exist, so nobody upgrading
	 * has to retype anything. See `known_group_codes()`.
	 */
	const OPTION_GROUP_CODES = 'tack_quotes_buyer_group_codes';

	/**
	 * Fallback API base URL, used when the option is unset or a submitted value is
	 * rejected and nothing valid was stored before.
	 */
	const DEFAULT_API_URL = 'https://api.tackquote.com/v1';

	/**
	 * Public setup guide.
	 */
	/**
	 * Admin menu icon: the TackQuote mark alone (no app-icon tile), `fill="black"`, as a
	 * base64 SVG data URI. WordPress's svg-painter recolours a data-URI menu icon to the
	 * current admin colour scheme, which it can only do for a single-colour SVG.
	 * Source: the two paths of the TackQuote brand mark, viewBox cropped to
	 * the mark. Same paths as `assets/images/tackquote-mark.svg`.
	 */
	const MENU_ICON = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9Ijk1IDg3IDMyMiAzMjIiIHdpZHRoPSIyMCIgaGVpZ2h0PSIyMCI+PHBhdGggZmlsbD0iYmxhY2siIGQ9Ik0gMzA1LjMgODkuMCBMIDMwNy4zIDg5LjAgTCAzMDYuMyA5My4wIEwgMzAyLjQgOTYuMCBMIDMwMC40IDEwMC45IEwgMjk2LjQgMTAzLjkgTCAyOTYuNCAxMDUuOSBMIDI5NC40IDEwNi45IEwgMjk0LjQgMTA4LjggTCAyODguNSAxMTUuOCBMIDI4Ny41IDExOS44IEwgMjgzLjUgMTIzLjcgTCAyODMuNSAxMjYuNiBMIDI3OC42IDEzMi42IEwgMjc3LjYgMTM3LjYgTCAyNjguNyAxNTQuNSBMIDI2OC43IDE1OC40IEwgMjY2LjcgMTYwLjQgTCAyNjUuNyAxNjcuNCBMIDI2My43IDE2OS4zIEwgMjU5LjcgMTg3LjIgTCAyNTguNyAxODcuMiBMIDI1OC43IDE5My4xIEwgMjU3LjcgMTkzLjEgTCAyNTYuOCAyMDMuMSBMIDI1NS44IDIwMy4xIEwgMjU0LjggMjE5LjAgTCAyNTMuOCAyMTkuMCBMIDI1NC44IDI1Ni43IEwgMjU1LjggMjU2LjcgTCAyNTYuOCAyNjkuNiBMIDI1Ny43IDI2OS42IEwgMjU4LjcgMjc5LjUgTCAyNjAuNyAyODIuNCBMIDI2Mi44IDI5My40IEwgMjY1LjcgMjk4LjMgTCAyNjguNyAzMDkuMiBMIDI3OC42IDMyOS4xIEwgMjgwLjYgMzMwLjEgTCAyODEuNSAzMzQuMCBMIDI4My41IDMzNS4xIEwgMjgzLjUgMzM3LjAgTCAyODUuNiAzMzguMCBMIDI4NS42IDM0MC4wIEwgMjg3LjUgMzQxLjAgTCAyODcuNSAzNDIuOSBMIDI5MC41IDM0NC45IEwgMjkwLjUgMzQ3LjAgTCAyOTMuNCAzNDguOSBMIDI5NS40IDM1Mi45IEwgMjkyLjUgMzUzLjkgTCAyOTIuNSAzNTIuOSBMIDI3Mi42IDM1MC45IEwgMjcyLjYgMzQ5LjkgTCAyMzguOCAzNDguOSBMIDIzOC44IDM0OS45IEwgMjE2LjEgMzUwLjkgTCAyMTYuMSAzNTEuOSBMIDIwOS4yIDM1MS45IEwgMjA5LjIgMzUyLjkgTCAxOTUuMyAzNTQuOCBMIDE5Mi4yIDM1Ni44IEwgMTg4LjMgMzU2LjggTCAxODguMyAzNTcuOSBMIDE3MC41IDM2Mi44IEwgMTY4LjQgMzY0LjggTCAxNjUuNSAzNjQuOCBMIDE2MS42IDM2Ny43IEwgMTU0LjYgMzY5LjggTCAxNTMuNiAzNzEuOCBMIDE0Ni42IDM3My43IEwgMTQ1LjYgMzc1LjcgTCAxNDEuNyAzNzYuNyBMIDE0MC43IDM3OC42IEwgMTMwLjggMzgzLjcgTCAxMjguOCAzODYuNiBMIDEyNC45IDM4Ny42IEwgMTIxLjggMzkxLjUgTCAxMTkuOCAzOTEuNSBMIDExNi45IDM5NS42IEwgMTE0LjkgMzk1LjYgTCAxMDcuOSA0MDMuNSBMIDEwNi4wIDQwMy41IEwgMTA1LjAgNDA2LjUgTCAxMDMuMCA0MDYuNSBMIDEwMi4wIDQwMy41IEwgMTAzLjAgNDAzLjUgTCAxMDUuMCAzODMuNyBMIDEwNi4wIDM4My43IEwgMTA3LjAgMzczLjcgTCAxMDcuOSAzNzMuNyBMIDEwNy45IDM2OC44IEwgMTA4LjkgMzY4LjggTCAxMDguOSAzNjMuOCBMIDEwOS45IDM2My44IEwgMTA5LjkgMzU3LjkgTCAxMTEuOSAzNTQuOCBMIDExNC45IDMzOS4wIEwgMTE2LjkgMzM2LjEgTCAxMTYuOSAzMzIuMCBMIDExOC45IDMyOS4xIEwgMTE4LjkgMzI1LjEgTCAxMTkuOCAzMjUuMSBMIDEyNC45IDMwNy4yIEwgMTI2LjggMzA1LjMgTCAxMjkuOCAyOTMuNCBMIDEzMi43IDI4OS40IEwgMTMyLjcgMjg2LjQgTCAxMzcuNyAyNzcuNSBMIDEzNy43IDI3NC41IEwgMTQ0LjYgMjYyLjYgTCAxNDQuNiAyNTkuNiBMIDE1Mi42IDI0My44IEwgMTU3LjUgMjM3LjggTCAxNTkuNiAyMzEuOSBMIDE2NC41IDIyNS45IEwgMTY1LjUgMjIyLjAgTCAxNjcuNCAyMjEuMCBMIDE2Ny40IDIxOS4wIEwgMTY5LjQgMjE3LjkgTCAxNjkuNCAyMTYuMCBMIDE3MS41IDIxNS4wIEwgMTcxLjUgMjEzLjAgTCAxNzMuNSAyMTIuMCBMIDE3NC40IDIwOC4xIEwgMTc3LjQgMjA2LjAgTCAxNzkuMyAyMDEuMSBMIDE4Mi40IDE5OS4xIEwgMTg0LjQgMTk0LjEgTCAxODkuMyAxOTAuMiBMIDE5Mi4yIDE4NC4yIEwgMTk2LjMgMTgxLjIgTCAxOTYuMyAxNzkuMyBMIDIwMi4yIDE3NC40IEwgMjAyLjIgMTcyLjMgTCAyMDkuMiAxNjYuNCBMIDIwOS4yIDE2NC40IEwgMjMzLjkgMTM5LjYgTCAyMzUuOSAxMzkuNiBMIDI0MS45IDEzMi42IEwgMjQzLjkgMTMyLjYgTCAyNDcuOCAxMjcuNyBMIDI0OS44IDEyNy43IEwgMjU3LjcgMTE5LjggTCAyNjIuOCAxMTcuOCBMIDI2NS43IDExMy44IEwgMjY3LjcgMTEzLjggTCAyNjguNyAxMTEuOCBMIDI3Ni42IDEwNy45IEwgMjgxLjUgMTAyLjggTCAyODUuNiAxMDEuOSBMIDI4OS41IDk3LjkgTCAzMDIuNCA5MS45IFoiLz48cGF0aCBmaWxsPSJibGFjayIgZD0iTSAzNDkuMCAyMDUuMCBMIDM2Ny45IDIwNi4wIEwgMzY3LjkgMjA3LjAgTCAzNzQuOCAyMDguMSBMIDM3OC44IDIxMS4wIEwgMzgxLjggMjExLjAgTCAzODMuNyAyMTQuMCBMIDM4OC44IDIxNi4wIEwgNDAwLjcgMjI5LjggTCA0MDEuNiAyMzMuOSBMIDQwMy42IDIzNC44IEwgNDA2LjYgMjQ3LjcgTCA0MDcuNSAyNDcuNyBMIDQwNy41IDI1MS43IEwgNDA4LjUgMjUxLjcgTCA0MDguNSAyNTYuNyBMIDQwOS41IDI1Ni43IEwgNDEwLjUgMjc3LjUgTCA0MDkuNSAyNzcuNSBMIDQwOC41IDI5Ni4zIEwgNDA3LjUgMjk2LjMgTCA0MDQuNiAzMTMuMiBMIDQwMy42IDMxMy4yIEwgNDAxLjYgMzIyLjEgTCAzOTguNiAzMjYuMSBMIDM5OC42IDMyOS4xIEwgMzkzLjcgMzM5LjAgTCAzODkuNyAzNDIuOSBMIDM4OC44IDM0Ny4wIEwgMzgyLjggMzUyLjkgTCAzODIuOCAzNTQuOCBMIDM3OC44IDM1Ny45IEwgMzc4LjggMzU5LjkgTCAzNzYuNyAzNTkuOSBMIDM3Ni43IDM2MS44IEwgMzY0LjggMzczLjcgTCAzNjIuOSAzNzMuNyBMIDM1OS45IDM3Ny43IEwgMzU4LjAgMzc3LjcgTCAzNTIuMCAzODMuNyBMIDM0OC4wIDM4NC42IEwgMzQ0LjEgMzg4LjYgTCAzNDAuMSAzODkuNiBMIDMzOS4xIDM5MS41IEwgMzIzLjMgMzk5LjUgTCAzMTAuNCA0MDMuNSBMIDMwMi40IDM4Ny42IEwgMzAwLjQgMzg2LjYgTCAzMDAuNCAzODMuNyBMIDMwOC40IDM3OS42IEwgMzA5LjQgMzc3LjcgTCAzMTMuMyAzNzYuNyBMIDMxNy4yIDM3Mi43IEwgMzE5LjIgMzcyLjcgTCAzMzkuMSAzNTQuOCBMIDMzOS4xIDM1Mi45IEwgMzQ1LjEgMzQ3LjAgTCAzNDUuMSAzNDQuOSBMIDM0OS4wIDM0MS4wIEwgMzQ5LjAgMzM4LjAgTCAzNTIuMCAzMzUuMSBMIDM1Mi4wIDMzMi4wIEwgMzUzLjkgMzMwLjEgTCAzNTYuMCAzMjAuMSBMIDM1Ny4wIDMyMC4xIEwgMzU4LjAgMzA5LjIgTCAzNTcuMCAzMDguMiBMIDM0NS4xIDMwOC4yIEwgMzQ1LjEgMzA3LjIgTCAzMzkuMSAzMDcuMiBMIDMzNi4xIDMwNS4zIEwgMzMyLjIgMzA1LjMgTCAzMjIuMyAzMDAuNCBMIDMxOC4yIDI5NS4zIEwgMzE1LjMgMjk0LjMgTCAzMTUuMyAyOTIuNCBMIDMwOC40IDI4NS40IEwgMzAzLjQgMjc1LjUgTCAzMDIuNCAyNjUuNiBMIDMwMS40IDI2NS42IEwgMzAyLjQgMjQ0LjggTCAzMDMuNCAyNDQuOCBMIDMwMy40IDI0MC43IEwgMzEwLjQgMjI2LjkgTCAzMjMuMyAyMTQuMCBMIDMyNy4yIDIxMy4wIEwgMzI4LjIgMjExLjAgTCAzMzEuMiAyMTEuMCBMIDMzMi4yIDIwOS4xIEwgMzQyLjAgMjA3LjAgTCAzNDIuMCAyMDYuMCBMIDM0OS4wIDIwNi4wIFoiLz48L3N2Zz4=';

	const DOCS_URL = 'https://tackquote.com/docs/integrations/woocommerce';

	/**
	 * Where merchants get help.
	 */
	const SUPPORT_URL = 'https://tackquote.com/contact';

	/**
	 * Outcome of this request's "Test connection" / "Remove saved API key" press.
	 *
	 * @var array|null array{type:string,message:string}
	 */
	private $action_result = null;

	/**
	 * Hook registration.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		( new Tack_Connect() )->init();
	}

	/**
	 * Add the top-level admin menu page.
	 *
	 * `manage_options`, not the shop-manager capability, and deliberately so.
	 *
	 * The form on this page posts to `options.php`, and `options.php` requires
	 * `manage_options` for every registered option group unless the
	 * `option_page_capability_{$option_page}` filter says otherwise — WordPress documents
	 * that filter as "required to change the capability required for a certain options page".
	 * No such filter was registered here, so a shop_manager could see the page, fill it in,
	 * press save, and be told "Sorry, you are not allowed to access this page."
	 *
	 * Of the two ways to close that gap — filter the requirement down to the shop-manager
	 * capability, or raise the page to `manage_options` — this page holds the TackQuote API
	 * key, which authenticates the whole store to TackQuote. A secret of that blast radius
	 * belongs behind the administrator capability, so the page is raised rather than the
	 * requirement lowered.
	 */
	public function add_menu() {
		add_menu_page(
			__( 'TackQuote', 'tackquote' ),
			__( 'TackQuote', 'tackquote' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' ),
			self::MENU_ICON,
			56
		);
	}

	/**
	 * Load the admin stylesheet and script on this page only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( 'toplevel_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}
		wp_enqueue_style( 'tackquote-admin', TACK_QUOTES_URL . 'assets/css/tack-admin.css', array(), TACK_QUOTES_VERSION );
		wp_enqueue_script( 'tackquote-admin', TACK_QUOTES_URL . 'assets/js/tack-admin.js', array(), TACK_QUOTES_VERSION, true );

		// 1.10.0: WordPress's own colour picker for the storefront accent colour.
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
		wp_add_inline_script( 'wp-color-picker', 'jQuery(function($){$(".tack-color-field").wpColorPicker();});' );
	}

	// ── Tabs ──────────────────────────────────────────────────────────────────

	/**
	 * Every tab, in reading order: slug => label.
	 *
	 * @return array<string,string>
	 */
	public static function tabs() {
		return array(
			'overview'   => __( 'Overview', 'tackquote' ),
			'connection' => __( 'Connection', 'tackquote' ),
			'storefront' => __( 'Storefront', 'tackquote' ),
			'pricing'    => __( 'B2B pricing', 'tackquote' ),
			'groups'     => __( 'Buyer groups', 'tackquote' ),
			'forms'      => __( 'Forms', 'tackquote' ),
			'sync'       => __( 'Order sync', 'tackquote' ),
		);
	}

	/**
	 * The Settings API option group a tab's form posts.
	 *
	 * @param string $tab Tab slug.
	 * @return string
	 */
	public static function option_group( $tab ) {
		return self::OPTION_GROUP . '_' . $tab;
	}

	/**
	 * The Settings API "page" a tab's sections are registered on.
	 *
	 * @param string $tab Tab slug.
	 * @return string
	 */
	public static function tab_page( $tab ) {
		return self::PAGE_SLUG . '_' . $tab;
	}

	/**
	 * Admin URL of a tab.
	 *
	 * @param string $tab Tab slug.
	 * @return string
	 */
	public static function tab_url( $tab ) {
		return admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=' . rawurlencode( $tab ) );
	}

	/**
	 * The requested tab, or the Overview for anything unknown.
	 *
	 * Read-only navigation: the value only selects which registered tab to draw and is
	 * compared against a fixed list, so no nonce applies.
	 *
	 * @return string
	 */
	public static function current_tab() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only, allow-listed below.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		return array_key_exists( $tab, self::tabs() ) ? $tab : self::DEFAULT_TAB;
	}

	/**
	 * Register one option in a tab's group.
	 *
	 * @param string   $tab      Tab slug.
	 * @param string   $option   Option name.
	 * @param callable $callback Sanitize callback.
	 */
	private function setting( $tab, $option, $callback ) {
		register_setting( self::option_group( $tab ), $option, array( 'sanitize_callback' => $callback ) );
	}

	/**
	 * Register a section on a tab.
	 *
	 * @param string   $tab      Tab slug.
	 * @param string   $id       Section id.
	 * @param string   $title    Heading.
	 * @param callable $callback Intro renderer.
	 */
	private function section( $tab, $id, $title, $callback ) {
		add_settings_section( $id, $title, $callback, self::tab_page( $tab ) );
	}

	/**
	 * Register a field row on a tab.
	 *
	 * @param string   $tab      Tab slug.
	 * @param string   $section  Section id.
	 * @param string   $id       Field id.
	 * @param string   $title    Row label.
	 * @param callable $callback Renderer.
	 * @param array    $args     Optional: `label_for`, `class`.
	 */
	private function field( $tab, $section, $id, $title, $callback, $args = array() ) {
		add_settings_field( $id, $title, $callback, self::tab_page( $tab ), $section, $args );
	}

	/**
	 * Register settings + fields with sanitization callbacks.
	 *
	 * ── SECTION ORDER IS THE INSTRUCTIONS ─────────────────────────────────────
	 *
	 * Tabs follow the setup sequence a merchant actually needs, each depending only
	 * on the ones before it: Connection (nothing works without a key), Storefront
	 * (the store-wide "how do customers buy" decision and the buttons a shopper
	 * sees), B2B pricing and Buyer groups (both need a key, and groups need codes),
	 * Forms, and Order sync (independent, off by default). Inside a tab,
	 * `do_settings_sections()` renders sections in registration order.
	 *
	 * Every option is registered in the group of the tab whose form draws it, and
	 * only there. A tab whose group held an option its form did not draw would
	 * reset that option on every save of that tab — see OPTION_GROUP.
	 */
	public function register_settings() {
		$checkbox = array( $this, 'sanitize_checkbox' );

		// ── Connection ──────────────────────────────────────────────────────────
		$this->setting( 'connection', 'tack_quotes_api_key', array( $this, 'sanitize_api_key' ) );
		$this->setting( 'connection', 'tack_quotes_api_url', array( $this, 'sanitize_url' ) );

		$this->section( 'connection', 'tack_quotes_connection', __( 'Or paste an API key', 'tackquote' ), array( $this, 'section_connection' ) );
		$this->field( 'connection', 'tack_quotes_connection', 'tack_quotes_api_key', __( 'API key', 'tackquote' ), array( $this, 'field_api_key' ), array( 'label_for' => 'tack_quotes_api_key' ) );
		$this->field( 'connection', 'tack_quotes_connection', 'tack_quotes_api_url', __( 'API URL', 'tackquote' ), array( $this, 'field_api_url' ) );

		// ── Storefront: how customers buy ───────────────────────────────────────
		$this->setting( 'storefront', Tack_Catalog_Mode::OPT_MODE, array( $this, 'sanitize_store_mode' ) );
		$this->setting( 'storefront', Tack_Catalog_Mode::OPT_SCOPE, array( $this, 'sanitize_scope' ) );
		$this->setting( 'storefront', Tack_Catalog_Mode::OPT_ROLES, array( $this, 'sanitize_roles' ) );
		$this->setting( 'storefront', Tack_Catalog_Mode::OPT_HIDE_PRICE, $checkbox );
		$this->setting( 'storefront', Tack_Catalog_Mode::OPT_PRICE_TEXT, 'sanitize_text_field' );

		$this->section( 'storefront', 'tack_quotes_store_mode', __( 'How customers buy', 'tackquote' ), array( $this, 'section_store_mode' ) );
		$this->field( 'storefront', 'tack_quotes_store_mode', Tack_Catalog_Mode::OPT_MODE, __( 'Store mode', 'tackquote' ), array( $this, 'field_store_mode' ) );
		$this->field( 'storefront', 'tack_quotes_store_mode', Tack_Catalog_Mode::OPT_SCOPE, __( 'Applies to', 'tackquote' ), array( $this, 'field_quote_only_scope' ) );
		$this->field( 'storefront', 'tack_quotes_store_mode', Tack_Catalog_Mode::OPT_HIDE_PRICE, __( 'Prices', 'tackquote' ), array( $this, 'field_hide_prices' ) );

		// ── Storefront: buttons ─────────────────────────────────────────────────
		$this->setting( 'storefront', 'tack_quotes_enable_widget', $checkbox );
		$this->setting( 'storefront', 'tack_quotes_show_add_to_quote', $checkbox );
		$this->setting( 'storefront', 'tack_quotes_show_request_quote', $checkbox );
		$this->setting( 'storefront', 'tack_quotes_button_label', array( $this, 'sanitize_button_label' ) );
		$this->setting( 'storefront', 'tack_quotes_request_button_label', array( $this, 'sanitize_button_label' ) );
		$this->setting( 'storefront', 'tack_quotes_checkout_button_label', array( $this, 'sanitize_button_label' ) );

		$this->section( 'storefront', 'tack_quotes_storefront', __( 'Buttons', 'tackquote' ), array( $this, 'section_storefront' ) );
		$this->field( 'storefront', 'tack_quotes_storefront', 'tack_quotes_enable_widget', __( 'Quote buttons', 'tackquote' ), array( $this, 'field_enable_widget' ) );
		$this->field( 'storefront', 'tack_quotes_storefront', 'tack_quotes_pdp_buttons', __( 'Product page', 'tackquote' ), array( $this, 'field_pdp_buttons' ) );
		$this->field( 'storefront', 'tack_quotes_storefront', 'tack_quotes_button_label', __( '"Add to Quote" label', 'tackquote' ), array( $this, 'field_button_label' ), array( 'label_for' => 'tack_quotes_button_label' ) );
		$this->field( 'storefront', 'tack_quotes_storefront', 'tack_quotes_request_button_label', __( '"Request a Quote" label', 'tackquote' ), array( $this, 'field_request_button_label' ), array( 'label_for' => 'tack_quotes_request_button_label' ) );

		// ── Storefront: card/cart buttons, quote list, launcher, quote page ─────
		$this->register_storefront_layout_settings();

		// ── B2B pricing ─────────────────────────────────────────────────────────
		$this->setting( 'pricing', Tack_Wholesale_Pricing::OPTION_ENABLED, $checkbox );
		$this->setting( 'pricing', Tack_Wholesale_Pricing::OPTION_SHOW_BREAKS, $checkbox );
		$this->setting( 'pricing', Tack_B2B_Notices::OPTION_ORDER_LIMITS, $checkbox );
		$this->setting( 'pricing', Tack_B2B_Notices::OPTION_BUYER_GROUP, $checkbox );
		$this->setting( 'pricing', Tack_Tax_Exempt::OPTION_ENABLED, array( $this, 'sanitize_storefront_forms_checkbox' ) );

		$this->section( 'pricing', 'tack_quotes_b2b_pricing', __( 'B2B pricing', 'tackquote' ), array( $this, 'section_b2b_pricing' ) );
		$this->field( 'pricing', 'tack_quotes_b2b_pricing', Tack_Wholesale_Pricing::OPTION_ENABLED, __( 'TackQuote prices', 'tackquote' ), array( $this, 'field_enable_wholesale_pricing' ) );
		$this->field( 'pricing', 'tack_quotes_b2b_pricing', Tack_Wholesale_Pricing::OPTION_SHOW_BREAKS, __( 'Volume pricing table', 'tackquote' ), array( $this, 'field_show_quantity_breaks' ) );
		$this->field( 'pricing', 'tack_quotes_b2b_pricing', Tack_B2B_Notices::OPTION_ORDER_LIMITS, __( 'Order limits', 'tackquote' ), array( $this, 'field_enable_order_limits' ) );
		$this->field( 'pricing', 'tack_quotes_b2b_pricing', Tack_B2B_Notices::OPTION_BUYER_GROUP, __( 'Buyer group badge', 'tackquote' ), array( $this, 'field_enable_buyer_group' ) );
		$this->field( 'pricing', 'tack_quotes_b2b_pricing', Tack_Tax_Exempt::OPTION_ENABLED, __( 'Tax-exempt buyers', 'tackquote' ), array( $this, 'field_apply_tax_exempt' ) );

		// ── Buyer groups: codes and checkout restrictions ───────────────────────
		$this->setting( 'groups', self::OPTION_GROUP_CODES, array( $this, 'sanitize_group_codes' ) );
		$this->setting( 'groups', Tack_Group_Restrictions::OPTION_ENABLED, $checkbox );

		/*
		 * One sanitizer per map rather than one shared callback, because
		 * `register_setting()` registers the callback on `sanitize_option_{$option}`
		 * with accepted_args = 1 — the callback is never told WHICH option it is
		 * sanitizing. Both maps need that name, to read the currently stored rules
		 * back and merge onto them, so each gets a thin wrapper that supplies it.
		 */
		$this->setting( 'groups', Tack_Group_Restrictions::OPTION_PAYMENT_MAP, array( $this, 'sanitize_payment_group_map' ) );
		$this->setting( 'groups', Tack_Group_Restrictions::OPTION_SHIPPING_MAP, array( $this, 'sanitize_shipping_group_map' ) );

		$this->section( 'groups', 'tack_quotes_group_rules', __( 'Buyer groups and checkout restrictions', 'tackquote' ), array( $this, 'section_group_rules' ) );
		$this->field( 'groups', 'tack_quotes_group_rules', self::OPTION_GROUP_CODES, __( 'Your buyer group codes', 'tackquote' ), array( $this, 'field_group_codes' ), array( 'label_for' => self::OPTION_GROUP_CODES ) );
		$this->field( 'groups', 'tack_quotes_group_rules', Tack_Group_Restrictions::OPTION_ENABLED, __( 'Restrict methods by group', 'tackquote' ), array( $this, 'field_enable_group_restrictions' ) );
		$this->field( 'groups', 'tack_quotes_group_rules', Tack_Group_Restrictions::OPTION_PAYMENT_MAP, __( 'Payment methods', 'tackquote' ), array( $this, 'field_payment_group_map' ) );
		$this->field( 'groups', 'tack_quotes_group_rules', Tack_Group_Restrictions::OPTION_SHIPPING_MAP, __( 'Shipping methods', 'tackquote' ), array( $this, 'field_shipping_group_map' ) );

		// ── Buyer groups: catalogue, shipping discounts, role mirror (1.10.0) ───
		$this->register_group_catalog_settings();

		// ── Forms ───────────────────────────────────────────────────────────────
		$this->setting( 'forms', Tack_Storefront_Forms::OPTION_FORM_SLUG, array( $this, 'sanitize_form_slug' ) );
		$this->setting( 'forms', Tack_Storefront_Forms::OPTION_WHOLESALE_TAB, array( $this, 'sanitize_storefront_forms_checkbox' ) );
		$this->setting( 'forms', Tack_Storefront_Forms::OPTION_NET_TERMS_TAB, array( $this, 'sanitize_storefront_forms_checkbox' ) );

		$this->section( 'forms', 'tack_quotes_storefront_forms', __( 'Storefront forms', 'tackquote' ), array( $this, 'section_storefront_forms' ) );
		$this->field( 'forms', 'tack_quotes_storefront_forms', Tack_Storefront_Forms::OPTION_FORM_SLUG, __( 'Wholesale form', 'tackquote' ), array( $this, 'field_wholesale_form_slug' ), array( 'label_for' => Tack_Storefront_Forms::OPTION_FORM_SLUG ) );
		$this->field( 'forms', 'tack_quotes_storefront_forms', Tack_Storefront_Forms::OPTION_WHOLESALE_TAB, __( '"Wholesale account" tab', 'tackquote' ), array( $this, 'field_enable_wholesale_tab' ) );
		$this->field( 'forms', 'tack_quotes_storefront_forms', Tack_Storefront_Forms::OPTION_NET_TERMS_TAB, __( '"Net terms" tab', 'tackquote' ), array( $this, 'field_enable_net_terms_tab' ) );

		// ── Order sync ──────────────────────────────────────────────────────────
		$this->setting( 'sync', 'tack_quotes_enable_order_sync', $checkbox );
		$this->section( 'sync', 'tack_quotes_sync', __( 'Order sync', 'tackquote' ), array( $this, 'section_sync' ) );
		$this->field( 'sync', 'tack_quotes_sync', 'tack_quotes_enable_order_sync', __( 'Sync orders to TackQuote', 'tackquote' ), array( $this, 'field_enable_order_sync' ) );
	}

	// ── Sanitizers ────────────────────────────────────────────────────────────

	/**
	 * Sanitize the merchant's list of buyer group codes.
	 *
	 * Accepts either the comma/newline separated text the field posts, or an
	 * array. Codes are upper-cased because that is the single normalised form
	 * `Tack_Group_Restrictions::parse_map()` stores and compares against —
	 * matching is case-insensitive either way, so this only decides how they
	 * are shown back.
	 *
	 * @param mixed $value Submitted value.
	 * @return string[]
	 */
	public function sanitize_group_codes( $value ) {
		if ( is_string( $value ) ) {
			$value = preg_split( '/[\s,]+/', $value );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}

		$clean = array();
		foreach ( $value as $code ) {
			$code = self::clean_group_code( $code );
			if ( '' !== $code && ! in_array( $code, $clean, true ) ) {
				$clean[] = $code;
			}
		}
		sort( $clean );
		return $clean;
	}

	/**
	 * Normalise a group code a merchant just TYPED.
	 *
	 * Allow-list, because this is new input and an allow-list is the safe way
	 * to treat it. Real TackQuote codes are plain identifiers — the seeder
	 * ships `standard`, `silver`, `gold`, `platinum` — so nothing legitimate
	 * is lost.
	 *
	 * @param mixed $code Raw code.
	 * @return string
	 */
	private static function clean_group_code( $code ) {
		// TackQuote's `buyer_groups.code` column is varchar(100).
		$code = substr( (string) $code, 0, 100 );
		return strtoupper( preg_replace( '/[^A-Za-z0-9._\-]/', '', $code ) );
	}

	/**
	 * Normalise a group code that is ALREADY STORED in a rule.
	 *
	 * Deliberately looser than `clean_group_code()`, and the difference is a
	 * bug fix rather than an oversight. Running an existing code through the
	 * allow-list would rewrite it — `TIER~2` becomes `TIER2` — and a rewritten
	 * code no longer matches the one in the stored rule, so its checkbox
	 * renders unticked and the next save drops the rule. The merchant loses a
	 * restriction by opening a page and pressing Save.
	 *
	 * So an existing code is left alone apart from the three things that would
	 * genuinely corrupt the storage format: a comma (which separates codes), a
	 * line break (which separates rules), and control characters.
	 *
	 * @param mixed $code Raw code.
	 * @return string
	 */
	private static function clean_stored_group_code( $code ) {
		$code = substr( (string) $code, 0, 100 );
		$code = preg_replace( '/[,\x00-\x1F\x7F]+/', '', $code );
		return strtoupper( trim( $code ) );
	}

	/**
	 * Sanitize callback for the payment map. See register_settings() for why
	 * the option name is baked into a wrapper rather than passed in.
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	public function sanitize_payment_group_map( $value ) {
		return $this->sanitize_group_map_for( Tack_Group_Restrictions::OPTION_PAYMENT_MAP, $value );
	}

	/**
	 * Sanitize callback for the shipping map.
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	public function sanitize_shipping_group_map( $value ) {
		return $this->sanitize_group_map_for( Tack_Group_Restrictions::OPTION_SHIPPING_MAP, $value );
	}

	/**
	 * Turn whatever the rules field posted back into the stored line format.
	 *
	 * ─────────────────────────────────────────────────────────────────────────
	 * THE STORED FORMAT DOES NOT CHANGE, AND IS NOT ALLOWED TO
	 * ─────────────────────────────────────────────────────────────────────────
	 * `Tack_Group_Restrictions::parse_map()` stays the only reader, and it
	 * still reads `method_id: CODE, CODE`, one rule per line. Everything the
	 * checkbox grid does is converted back into exactly that text on the way
	 * in, so a store that upgrades keeps working with no migration, and a
	 * merchant who configured rules by hand keeps them.
	 *
	 * Three submitted shapes are handled, and the differences matter:
	 *
	 *   array   The checkbox grid. Merged onto the stored rules — see below.
	 *   string  The textarea fallback, and any programmatic `update_option()`.
	 *           Behaves exactly as it did before 1.8.0.
	 *   neither `null`, in particular. WordPress's own options.php calls
	 *           `update_option( $option, null )` for every registered option
	 *           that is ABSENT from the POST, so a field that failed to render
	 *           arrives here as null. Returning '' for that would delete a
	 *           merchant's entire rule set because a page did not draw a
	 *           widget. The stored value is kept instead.
	 *
	 * ─────────────────────────────────────────────────────────────────────────
	 * WHY THE GRID MERGES INSTEAD OF REPLACING
	 * ─────────────────────────────────────────────────────────────────────────
	 * The grid can only draw a row for a gateway or shipping method that
	 * exists on this store RIGHT NOW. Rebuilding the option from the grid
	 * alone would therefore silently delete a rule whenever the plugin
	 * providing that gateway is deactivated — deactivate Stripe for ten
	 * minutes, save any TackQuote setting, and the Net-30 rule is gone with
	 * no message. So the form posts the list of ids it actually rendered, and
	 * only those lines are rewritten. Everything else in the stored text —
	 * rules for absent methods, `#` comments, blank lines, lines the parser
	 * cannot read — is carried through untouched.
	 *
	 * @param string $option Option being sanitized.
	 * @param mixed  $value  Submitted value.
	 * @return string
	 */
	private function sanitize_group_map_for( $option, $value ) {
		$stored = (string) get_option( $option, '' );

		if ( is_string( $value ) ) {
			return $this->sanitize_group_map( $value );
		}

		if ( ! is_array( $value ) ) {
			return $stored;
		}

		// The textarea fallback posts its text under `text`.
		if ( isset( $value['mode'] ) && 'text' === $value['mode'] ) {
			return $this->sanitize_group_map( isset( $value['text'] ) ? $value['text'] : '' );
		}

		$rendered = array();
		foreach ( (array) ( isset( $value['rendered'] ) ? $value['rendered'] : array() ) as $id ) {
			$id = $this->clean_method_id( $id );
			if ( '' !== $id && ! in_array( $id, $rendered, true ) ) {
				$rendered[] = $id;
			}
		}

		/*
		 * A grid that rendered no rows tells us nothing about the stored rules,
		 * so it must not be read as "the merchant cleared everything".
		 */
		if ( empty( $rendered ) ) {
			return $stored;
		}

		$submitted = array();
		foreach ( (array) ( isset( $value['groups'] ) ? $value['groups'] : array() ) as $id => $codes ) {
			$id = $this->clean_method_id( $id );
			if ( '' === $id || ! in_array( $id, $rendered, true ) ) {
				// Only ids the form actually offered may be written through it.
				continue;
			}
			$clean = array();
			foreach ( (array) $codes as $code ) {
				// Looser on purpose — see clean_stored_group_code(). These
				// values came from checkboxes this page rendered from the
				// stored rules, so rewriting them here would silently change
				// or drop a rule the merchant never touched.
				$code = self::clean_stored_group_code( $code );
				if ( '' !== $code && ! in_array( $code, $clean, true ) ) {
					$clean[] = $code;
				}
			}
			if ( ! empty( $clean ) ) {
				$submitted[ $id ] = $clean;
			}
		}

		return $this->merge_group_map( $stored, $rendered, $submitted );
	}

	/**
	 * Rewrite only the lines the form was responsible for.
	 *
	 * @param string   $stored    Currently stored raw text.
	 * @param string[] $rendered  Ids the form drew a row for.
	 * @param array    $submitted id => string[] of codes, empty ids omitted.
	 * @return string
	 */
	private function merge_group_map( $stored, array $rendered, array $submitted ) {
		$lines = ( '' === trim( $stored ) )
			? array()
			: preg_split( '/\r\n|\r|\n/', $stored );

		$out  = array();
		$done = array();

		foreach ( $lines as $line ) {
			$id      = '';
			$trimmed = trim( $line );
			if ( '' !== $trimmed && 0 !== strpos( $trimmed, '#' ) ) {
				$parts = explode( ':', $trimmed, 2 );
				if ( 2 === count( $parts ) ) {
					$id = $this->clean_method_id( $parts[0] );
				}
			}

			if ( '' === $id || ! in_array( $id, $rendered, true ) ) {
				// Comment, blank line, unparsable line, or a rule for a method
				// this store no longer has. None of it was the form's to touch.
				$out[] = $line;
				continue;
			}

			if ( isset( $done[ $id ] ) ) {
				// parse_map() lets a later duplicate win, so a second line for
				// the same id is already dead weight. Dropping it here stops
				// the grid turning one rule into two identical ones.
				continue;
			}
			$done[ $id ] = true;

			// Absent from $submitted means every box was cleared, which means
			// unrestricted — so the line goes away rather than becoming
			// "allowed to nobody".
			if ( isset( $submitted[ $id ] ) ) {
				$out[] = $id . ': ' . implode( ', ', $submitted[ $id ] );
			}
		}

		foreach ( $rendered as $id ) {
			if ( ! isset( $done[ $id ] ) && isset( $submitted[ $id ] ) ) {
				$out[] = $id . ': ' . implode( ', ', $submitted[ $id ] );
			}
		}

		return trim( implode( "\n", $out ) );
	}

	/**
	 * Normalise a gateway or shipping method id.
	 *
	 * Deliberately permissive about the characters gateways actually use, and
	 * deliberately strict about the two that would corrupt the stored format:
	 * a newline would split one rule into two, and a `:` past the first would
	 * confuse the id/codes boundary. A full rate id (`flat_rate:3`) still has
	 * to survive, so the colon is allowed but a line break never is.
	 *
	 * @param mixed $id Raw id.
	 * @return string
	 */
	private function clean_method_id( $id ) {
		return trim( preg_replace( '/[^A-Za-z0-9._:\-]/', '', (string) $id ) );
	}

	/**
	 * Sanitize a group map supplied as raw text.
	 *
	 * Kept as free text rather than parsed-and-rejected: a merchant mid-edit
	 * should not lose the whole box because one line is incomplete. The parser
	 * in Tack_Group_Restrictions skips lines it cannot read, so an unparsable
	 * line restricts nothing rather than breaking checkout. What is stripped
	 * here is markup, which has no business in an id list.
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	public function sanitize_group_map( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}
		return trim( wp_strip_all_tags( $value ) );
	}

	/**
	 * Sanitize the API key, treating an empty submission as "keep what is stored".
	 *
	 * The field renders with an EMPTY value on purpose — see field_api_key() — so an
	 * untouched form posts nothing for it. Without this, saving any other setting on the page
	 * would silently wipe the key and disconnect the store, with the only symptom being
	 * "No TackQuote API key configured" on the next quote request.
	 *
	 * Clearing the key is still possible, explicitly, through the "Remove saved API key"
	 * button on the settings page.
	 *
	 * @param mixed $value Raw option value.
	 * @return string
	 */
	public function sanitize_api_key( $value ) {
		$clean = preg_replace( '/[^A-Za-z0-9._\-]/', '', (string) $value );
		if ( '' === $clean ) {
			return (string) get_option( 'tack_quotes_api_key', '' );
		}
		return $clean;
	}

	/**
	 * Sanitize the API base URL, falling back to the default when it is unusable.
	 *
	 * @param mixed $value Raw option value.
	 * @return string
	 */
	public function sanitize_url( $value ) {
		$stored = (string) get_option( 'tack_quotes_api_url', self::DEFAULT_API_URL );
		if ( '' === $stored ) {
			$stored = self::DEFAULT_API_URL;
		}

		$raw = rtrim( trim( (string) $value ), '/' );

		if ( '' === $raw ) {
			return $stored;
		}

		// The scheme must be present in what the administrator actually typed.
		// `esc_url_raw()` helpfully PREPENDS `http://` to a bare string, so `not-a-url`
		// became `http://not-a-url` — a single-label host, which the development-host
		// allowance below then accepted. A typo would have been stored as valid-looking
		// configuration and only failed later, at request time, with a confusing error.
		if ( ! preg_match( '#^https?://#i', $raw ) ) {
			add_settings_error(
				'tack_quotes_api_url',
				'tack_quotes_api_url_invalid',
				__( 'The TackQuote API URL must begin with https:// (or http:// for a local development host). The previous value was kept.', 'tackquote' )
			);
			return $stored;
		}

		$candidate = esc_url_raw( $raw, array( 'http', 'https' ) );

		if ( '' === $candidate ) {
			return $stored;
		}

		$parts  = wp_parse_url( $candidate );
		$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : '';
		$host   = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';

		if ( '' === $host || ( 'http' !== $scheme && 'https' !== $scheme ) ) {
			add_settings_error(
				'tack_quotes_api_url',
				'tack_quotes_api_url_invalid',
				__( 'The TackQuote API URL must be a full http:// or https:// address including a host. The previous value was kept.', 'tackquote' )
			);
			return $stored;
		}

		if ( 'https' !== $scheme && ! self::is_non_public_host( $host ) ) {
			add_settings_error(
				'tack_quotes_api_url',
				'tack_quotes_api_url_insecure',
				__( 'The TackQuote API URL must use https:// — your API key and your buyers\' details are sent to it. Plain http:// is accepted only for local development hosts. The previous value was kept.', 'tackquote' )
			);
			return $stored;
		}

		return $candidate;
	}

	/**
	 * True for hosts unreachable from the public internet, which therefore cannot be
	 * expected to present a valid TLS certificate.
	 *
	 * Covers loopback, RFC1918 and link-local addresses, the reserved development TLDs,
	 * and single-label names such as a container or service name (`api`).
	 *
	 * Public since 1.11.0: "Connect with TackQuote" applies the same rule to this
	 * site's own address, and TackQuote checks the return address with a port of it.
	 *
	 * @param string $host Lower-cased host component.
	 * @return bool
	 */
	public static function is_non_public_host( $host ) {
		if ( 'localhost' === $host || false === strpos( $host, '.' ) ) {
			return true;
		}

		foreach ( array( '.local', '.localhost', '.test', '.internal' ) as $suffix ) {
			if ( substr( $host, -strlen( $suffix ) ) === $suffix ) {
				return true;
			}
		}

		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			// Public routable space fails this check, so a false result means private.
			return false === filter_var(
				$host,
				FILTER_VALIDATE_IP,
				FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
			);
		}

		return false;
	}

	/**
	 * Checkbox options: unchecked fields are omitted from POST, so treat empty as "no".
	 *
	 * @param mixed $value Raw option value.
	 * @return string "yes" or "no".
	 */
	public function sanitize_checkbox( $value ) {
		return ( 'yes' === $value || '1' === $value || 'on' === $value || true === $value ) ? 'yes' : 'no';
	}

	// ── Presentation helpers ──────────────────────────────────────────────────

	/**
	 * One short sentence of help under a field, plus an optional "Learn more"
	 * disclosure holding the full explanation.
	 *
	 * The long explanations are kept word for word inside the disclosure: several of
	 * them are safety statements (fail-closed behaviour, what data leaves the store),
	 * and shortening the page must not drop a single one of those facts.
	 *
	 * @param string          $text Short help, already translated.
	 * @param string|string[] $more Optional longer paragraphs, already translated.
	 */
	private function help( $text, $more = array() ) {
		echo '<p class="description">' . esc_html( $text ) . '</p>';
		$this->learn_more( $more );
	}

	/**
	 * A native `<details>` disclosure: keyboard operable, announced by screen
	 * readers, and readable with JavaScript off.
	 *
	 * @param string|string[] $more    Paragraphs, already translated.
	 * @param string          $summary Optional summary text.
	 */
	private function learn_more( $more, $summary = '' ) {
		$more = array_filter( (array) $more, 'strlen' );
		if ( empty( $more ) ) {
			return;
		}
		echo '<details class="tack-more"><summary>' . esc_html( '' !== $summary ? $summary : __( 'Learn more', 'tackquote' ) ) . '</summary>';
		foreach ( $more as $paragraph ) {
			echo '<p>' . esc_html( $paragraph ) . '</p>';
		}
		echo '</details>';
	}

	/**
	 * Mark the current form-table ROW as shown only while a controller has one of
	 * the given values. Rendered as an inert marker inside the row; tack-admin.js
	 * hides the enclosing `<tr>`. With JavaScript off every row stays visible, and a
	 * hidden row's inputs are still submitted, so hiding never changes what is saved.
	 *
	 * @param string          $controller Option name of the controlling input.
	 * @param string|string[] $values     Values that show the row ('yes' = ticked).
	 */
	private function show_row_when( $controller, $values ) {
		printf(
			'<span class="tack-when tack-when--row" data-tack-when="%1$s" data-tack-when-value="%2$s" hidden></span>',
			esc_attr( $controller ),
			esc_attr( implode( ' ', (array) $values ) )
		);
	}

	/**
	 * Open a block shown only while a controller has one of the given values.
	 *
	 * @param string          $controller Option name of the controlling input.
	 * @param string|string[] $values     Values that show the block.
	 * @param string          $extra_class Extra class.
	 */
	private function open_when( $controller, $values, $extra_class = '' ) {
		printf(
			'<div class="tack-when %3$s" data-tack-when="%1$s" data-tack-when-value="%2$s">',
			esc_attr( $controller ),
			esc_attr( implode( ' ', (array) $values ) ),
			esc_attr( $extra_class )
		);
	}

	/**
	 * A status pill. Always carries a text label: colour is never the only signal.
	 *
	 * @param string $state ok | off | warn | error.
	 * @param string $label Visible text, already translated.
	 */
	private function pill( $state, $label ) {
		printf( '<span class="tack-pill tack-pill--%1$s">%2$s</span>', esc_attr( $state ), esc_html( $label ) );
	}

	// ── Section intros ────────────────────────────────────────────────────────

	/**
	 * Intro copy for the Connection section.
	 */
	public function section_connection() {
		echo '<p>' . esc_html__( 'Prefer to do it by hand? Paste an API key from TackQuote (Settings → Developer → API Keys), save, then test the connection.', 'tackquote' ) . '</p>';
		$this->learn_more(
			array(
				__( 'Quote requests, order sync and everything on the B2B pricing and Buyer groups tabs need this key. The Storefront tab can be set up first, but a shopper who presses a quote button before the store is connected gets an error.', 'tackquote' ),
				__( 'The test uses an unscoped route, so it passes for any valid key. Quote requests need the quotes:write scope and order sync needs orders:write.', 'tackquote' ),
			)
		);
	}

	/**
	 * Intro copy for the storefront-buttons section.
	 */
	public function section_storefront() {
		echo '<p>' . esc_html__( 'Which quote buttons shoppers see on product pages.', 'tackquote' ) . '</p>';
		$this->learn_more( __( 'A floating “quote list” — separate from the WooCommerce cart — appears once a shopper adds a product, letting them review it and click “Checkout as Quote” to submit everything as one TackQuote request. On product pages, choose below whether shoppers see “Add to Quote” (adds the product to that quote list — never the WooCommerce cart, so it never touches stock or checkout), “Request a Quote” (submits a quote for just that product immediately), both, or neither.', 'tackquote' ) );
	}

	/**
	 * Intro copy for the order-sync section, including what data leaves the store.
	 */
	public function section_sync() {
		echo '<p>' . esc_html__( 'Off by default. When enabled, this plugin pushes order data one-way to TackQuote when an order is created or its status changes. It does not import orders, sync the product catalog, or update inventory.', 'tackquote' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Personal data leaves your store when this is on.', 'tackquote' ) . '</p>';
		$this->learn_more(
			__( 'Personal data leaves your store when this is on. Each order sends the whole order: the buyer\'s full billing and shipping addresses, email address and phone numbers, their WooCommerce customer ID and order note, the order number and ID, status, currency, subtotal, discount, shipping, tax and total, coupon codes, the created/modified/paid/completed dates, the payment method and the payment gateway\'s transaction reference, and every line item with its name, SKU, product and variation IDs, quantity, subtotal, total, tax and item meta (for example “Size: Large”). No card numbers, card details or gateway credentials are ever sent.', 'tackquote' ),
			__( 'What data is sent', 'tackquote' )
		);
		$this->learn_more(
			__( 'Each push is queued and sent on a background request through WooCommerce\'s Action Scheduler, so it never blocks checkout — queued jobs are visible under WooCommerce → Status → Scheduled Actions, and failures are logged under WooCommerce → Status → Logs (source: tackquote).', 'tackquote' ),
			__( 'How sending works', 'tackquote' )
		);
	}

	// ── Field renderers (escape all output) ─────────────────────────────────────

	/**
	 * Intro copy for the Store mode section.
	 */
	public function section_store_mode() {
		echo '<p>' . esc_html__( 'Choose whether this is a normal shop that also takes quotes, or a B2B catalogue where every order starts as a quote.', 'tackquote' ) . '</p>';
	}

	/**
	 * The store-mode switch, rendered as two explained choices rather than a
	 * bare checkbox — turning off checkout store-wide is a big, scary action and
	 * the consequence should be readable before it is taken, not after.
	 */
	public function field_store_mode() {
		$mode = get_option( Tack_Catalog_Mode::OPT_MODE, Tack_Catalog_Mode::MODE_CART );

		$choices = array(
			Tack_Catalog_Mode::MODE_CART       => array(
				'label' => __( 'Shop and quotes', 'tackquote' ),
				'desc'  => __( 'Normal WooCommerce checkout, with the quote buttons alongside it. Customers choose which they want.', 'tackquote' ),
			),
			Tack_Catalog_Mode::MODE_QUOTE_ONLY => array(
				'label' => __( 'Quote only (B2B catalogue)', 'tackquote' ),
				'desc'  => __( 'Add to cart is switched off across the whole store and customers request a quote instead. Your products, categories and search all keep working — only checkout goes away.', 'tackquote' ),
			),
		);

		echo '<fieldset class="tack-choices"><legend class="screen-reader-text">' . esc_html__( 'Store mode', 'tackquote' ) . '</legend>';
		foreach ( $choices as $value => $choice ) {
			printf(
				'<label class="tack-choice"><input type="radio" name="%1$s" value="%2$s" %3$s /> <span class="tack-choice__text"><strong>%4$s</strong><span class="description">%5$s</span></span></label>',
				esc_attr( Tack_Catalog_Mode::OPT_MODE ),
				esc_attr( $value ),
				checked( $mode, $value, false ),
				esc_html( $choice['label'] ),
				esc_html( $choice['desc'] )
			);
		}
		echo '</fieldset>';

		echo '<p class="description"><strong>' . esc_html__( 'You are not locked out.', 'tackquote' ) . '</strong> '
			. esc_html__( 'Anyone who can manage WooCommerce still sees a working cart, so you can test the store while it is closed to customers. Switching back restores checkout immediately.', 'tackquote' )
			. '</p>';
	}

	/**
	 * Who quote-only mode applies to.
	 *
	 * "Signed-out visitors only" is the option most B2B sellers actually want:
	 * the public sees a catalogue, approved trade customers keep a real cart.
	 */
	public function field_quote_only_scope() {
		$this->show_row_when( Tack_Catalog_Mode::OPT_MODE, Tack_Catalog_Mode::MODE_QUOTE_ONLY );
		$scope = get_option( Tack_Catalog_Mode::OPT_SCOPE, Tack_Catalog_Mode::SCOPE_EVERYONE );

		$choices = array(
			Tack_Catalog_Mode::SCOPE_EVERYONE   => __( 'Every customer', 'tackquote' ),
			Tack_Catalog_Mode::SCOPE_GUESTS     => __( 'Signed-out visitors only — approved customers keep a normal cart', 'tackquote' ),
			Tack_Catalog_Mode::SCOPE_ROLES      => __( 'Only the roles I choose below', 'tackquote' ),
			// 1.10.0: the price gate. Needs the API key; TackQuote answers whether the signed-in
			// buyer's wholesale application is approved (GET /storefront/v1/price-access).
			Tack_Catalog_Mode::SCOPE_UNAPPROVED => __( 'Everyone except approved wholesale accounts', 'tackquote' ),
		);

		echo '<fieldset class="tack-choices"><legend class="screen-reader-text">' . esc_html__( 'Applies to', 'tackquote' ) . '</legend>';
		foreach ( $choices as $value => $label ) {
			printf(
				'<label class="tack-choice tack-choice--compact"><input type="radio" name="%1$s" value="%2$s" %3$s /> <span class="tack-choice__text">%4$s</span></label>',
				esc_attr( Tack_Catalog_Mode::OPT_SCOPE ),
				esc_attr( $value ),
				checked( $scope, $value, false ),
				esc_html( $label )
			);
		}

		$selected = (array) get_option( Tack_Catalog_Mode::OPT_ROLES, array() );
		$roles    = function_exists( 'wp_roles' ) ? wp_roles()->get_names() : array();

		$this->open_when( Tack_Catalog_Mode::OPT_SCOPE, Tack_Catalog_Mode::SCOPE_ROLES, 'tack-indent tack-checklist' );
		printf(
			'<label><input type="checkbox" name="%1$s[]" value="guest" %2$s /> %3$s</label>',
			esc_attr( Tack_Catalog_Mode::OPT_ROLES ),
			checked( in_array( 'guest', $selected, true ), true, false ),
			esc_html__( 'Signed-out visitors', 'tackquote' )
		);
		foreach ( $roles as $slug => $name ) {
			printf(
				'<label><input type="checkbox" name="%1$s[]" value="%2$s" %3$s /> %4$s</label>',
				esc_attr( Tack_Catalog_Mode::OPT_ROLES ),
				esc_attr( $slug ),
				checked( in_array( $slug, $selected, true ), true, false ),
				esc_html( $name )
			);
		}
		echo '</div>';
		echo '</fieldset>';
		$this->help(
			__( 'Only used when "Quote only" is selected above.', 'tackquote' ),
			__( 'Everyone except approved wholesale accounts — a signed-in customer whose wholesale application TackQuote has approved keeps a normal cart (needs the API key; if TackQuote cannot be reached, the catalogue is shown)', 'tackquote' )
		);
	}

	/**
	 * Optional "price on request".
	 */
	public function field_hide_prices() {
		$this->show_row_when( Tack_Catalog_Mode::OPT_MODE, Tack_Catalog_Mode::MODE_QUOTE_ONLY );
		$this->checkbox(
			Tack_Catalog_Mode::OPT_HIDE_PRICE,
			__( 'Hide prices while the store is quote-only.', 'tackquote' )
		);
		$this->open_when( Tack_Catalog_Mode::OPT_HIDE_PRICE, 'yes', 'tack-indent' );
		printf(
			'<p><label for="%1$s" class="tack-inline-label">%4$s</label> <input type="text" class="regular-text" id="%1$s" name="%1$s" value="%2$s" placeholder="%3$s" /></p>',
			esc_attr( Tack_Catalog_Mode::OPT_PRICE_TEXT ),
			esc_attr( (string) get_option( Tack_Catalog_Mode::OPT_PRICE_TEXT, '' ) ),
			esc_attr__( 'Price on request', 'tackquote' ),
			esc_html__( 'Text instead of the price', 'tackquote' )
		);
		echo '<p class="description">' . esc_html__( 'Shown in place of the price. Leave blank for "Price on request".', 'tackquote' ) . '</p>';
		echo '</div>';
	}

	/**
	 * Only the two known modes are storable — anything else falls back to the
	 * safe one (a working shop), so a malformed POST can never silently close
	 * a store's checkout.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_store_mode( $value ) {
		$value = is_string( $value ) ? $value : '';
		return Tack_Catalog_Mode::MODE_QUOTE_ONLY === $value
			? Tack_Catalog_Mode::MODE_QUOTE_ONLY
			: Tack_Catalog_Mode::MODE_CART;
	}

	/**
	 * Only the four known scopes are storable; anything else falls back to the
	 * widest one, which is the value the mode switch itself defaults to.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_scope( $value ) {
		$allowed = array(
			Tack_Catalog_Mode::SCOPE_EVERYONE,
			Tack_Catalog_Mode::SCOPE_GUESTS,
			Tack_Catalog_Mode::SCOPE_ROLES,
			Tack_Catalog_Mode::SCOPE_UNAPPROVED,
		);
		return in_array( $value, $allowed, true ) ? (string) $value : Tack_Catalog_Mode::SCOPE_EVERYONE;
	}

	/**
	 * Role slugs must be real roles (or the pseudo-role `guest`), so a crafted
	 * POST cannot store arbitrary strings into the option.
	 *
	 * @param mixed $value Raw value.
	 * @return string[]
	 */
	public function sanitize_roles( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$known   = function_exists( 'wp_roles' ) ? array_keys( wp_roles()->get_names() ) : array();
		$known[] = 'guest';

		$clean = array();
		foreach ( $value as $slug ) {
			$slug = sanitize_key( (string) $slug );
			if ( in_array( $slug, $known, true ) && ! in_array( $slug, $clean, true ) ) {
				$clean[] = $slug;
			}
		}
		return $clean;
	}

	/**
	 * The API key field. Renders EMPTY — never the stored key.
	 *
	 * `type="password"` only hides characters on screen. The value still sits in the page
	 * source, so the key leaked into browser "save page" output, password-manager autofill,
	 * screen shares and recordings, proxy caches, and anything able to read the DOM of an
	 * admin page. A masked hint in the description gives an administrator the one thing they
	 * actually need — confirmation of WHICH key is stored — without shipping the secret.
	 */
	public function field_api_key() {
		$value  = (string) get_option( 'tack_quotes_api_key', '' );
		$masked = '' !== $value ? str_repeat( '•', 8 ) . substr( $value, -4 ) : '';
		printf(
			'<input type="password" id="tack_quotes_api_key" name="tack_quotes_api_key" value="" class="regular-text code" autocomplete="new-password" placeholder="%s" />',
			esc_attr(
				'' !== $masked
					? __( 'Leave blank to keep the saved key', 'tackquote' )
					: __( 'Paste your TackQuote API key', 'tackquote' )
			)
		);
		if ( '' !== $masked ) {
			echo '<p class="description">'
				. esc_html__( 'A key is saved:', 'tackquote' ) . ' <code>' . esc_html( $masked ) . '</code>. '
				. esc_html__( 'Leave this field blank to keep it. Paste a new key to replace it.', 'tackquote' )
				. '</p>';
		} else {
			echo '<p class="description">' . esc_html__( 'Required for quote requests and order sync. Leave blank only while configuring the store.', 'tackquote' ) . '</p>';
		}
	}

	/**
	 * The API base URL field, folded away: almost nobody should change it.
	 */
	public function field_api_url() {
		$value = (string) get_option( 'tack_quotes_api_url', self::DEFAULT_API_URL );
		$open  = ( '' !== $value && self::DEFAULT_API_URL !== $value ) ? ' open' : '';
		echo '<details class="tack-advanced"' . esc_attr( $open ) . '><summary>' . esc_html__( 'Advanced: change the API URL', 'tackquote' ) . '</summary>';
		printf(
			'<p><label for="tack_quotes_api_url" class="screen-reader-text">%2$s</label><input type="url" id="tack_quotes_api_url" name="tack_quotes_api_url" value="%1$s" class="regular-text code" placeholder="https://api.tackquote.com/v1" /></p>',
			esc_attr( $value ),
			esc_html__( 'API URL', 'tackquote' )
		);
		echo '<p class="description">' . esc_html__( 'Default is https://api.tackquote.com/v1. Change only if TackQuote support gives you a custom or staging API base URL (include the /v1 path, no trailing slash). Must use https:// — your API key and your buyers\' details are sent to this address.', 'tackquote' ) . '</p>';
		echo '</details>';
	}

	/**
	 * Which button(s) appear on the product page — independent checkboxes so
	 * a merchant can show "Add to Quote", "Request a Quote", both, or hide
	 * product-page buttons entirely while keeping "Checkout as Quote" on cart.
	 */
	public function field_pdp_buttons() {
		$this->show_row_when( 'tack_quotes_enable_widget', 'yes' );
		echo '<fieldset class="tack-stack"><legend class="screen-reader-text">' . esc_html__( 'Product page', 'tackquote' ) . '</legend>';
		$this->checkbox( 'tack_quotes_show_add_to_quote', __( 'Show "Add to Quote" (adds to the quote list)', 'tackquote' ) );
		$this->checkbox( 'tack_quotes_show_request_quote', __( 'Show "Request a Quote" (submits a quote for just this product immediately)', 'tackquote' ) );
		echo '</fieldset>';
		$this->help( __( 'Both can be shown at once, or either alone. If neither is checked, product pages show no quote button (the cart page\'s "Checkout as Quote" is unaffected).', 'tackquote' ) );
	}

	/**
	 * The label the merchant typed, or '' while the translated default applies.
	 *
	 * @since 1.10.0
	 *
	 * @param string $option Label option.
	 * @return string
	 */
	private static function custom_label( $option ) {
		$defaults = Tack_Widget::label_defaults();
		$stored   = trim( (string) get_option( $option, '' ) );
		return ( isset( $defaults[ $option ] ) && $defaults[ $option ] === $stored ) ? '' : $stored;
	}

	/**
	 * Save a button label; the default text (English, or as translated for the admin
	 * saving the form) is stored as '' so it keeps following the visitor's language.
	 *
	 * @since 1.10.0
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	public function sanitize_button_label( $value ) {
		$value = trim( sanitize_text_field( (string) $value ) );
		foreach ( Tack_Widget::label_defaults() as $option => $english ) {
			if ( $english === $value || Tack_Widget::default_label( $option ) === $value ) {
				return '';
			}
		}
		return $value;
	}

	/**
	 * A label text input with the translated default as placeholder.
	 *
	 * @param string $option Label option.
	 */
	private function label_input( $option ) {
		printf(
			'<input type="text" id="%3$s" name="%3$s" value="%1$s" placeholder="%2$s" class="regular-text" />',
			esc_attr( self::custom_label( $option ) ),
			esc_attr( Tack_Widget::default_label( $option ) ),
			esc_attr( $option )
		);
	}

	/**
	 * The "Add to Quote" button label field.
	 */
	public function field_button_label() {
		$this->show_row_when( 'tack_quotes_enable_widget', 'yes' );
		$this->label_input( 'tack_quotes_button_label' );
		$this->help(
			__( 'Leave blank for the default, shown in the visitor\'s language.', 'tackquote' ),
			__( 'Shown next to Add to Cart on product pages. Clicking it adds the product to a separate quote list — never the WooCommerce cart — and does not submit a quote by itself.', 'tackquote' )
		);
	}

	/**
	 * The "Request a Quote" button label field.
	 */
	public function field_request_button_label() {
		$this->show_row_when( 'tack_quotes_enable_widget', 'yes' );
		$this->label_input( 'tack_quotes_request_button_label' );
		$this->help(
			__( 'Leave blank for the default, shown in the visitor\'s language.', 'tackquote' ),
			__( 'Shown on product pages when enabled above. Clicking it immediately submits a quote request for just that product (does not add it to the cart).', 'tackquote' )
		);
	}

	/**
	 * The "Checkout as Quote" button label field.
	 */
	public function field_checkout_button_label() {
		$this->show_row_when( 'tack_quotes_enable_widget', 'yes' );
		$this->label_input( 'tack_quotes_checkout_button_label' );
		$this->help(
			__( 'Leave blank for the default, shown in the visitor\'s language.', 'tackquote' ),
			__( 'Shown in the floating quote-list drawer (bottom-right of every page, once at least one product is added). Clicking it submits every item in the quote list as a single TackQuote quote request.', 'tackquote' )
		);
	}

	/**
	 * The master on/off switch for the storefront quote buttons.
	 */
	public function field_enable_widget() {
		$this->checkbox(
			'tack_quotes_enable_widget',
			__( 'Show quote buttons and the floating quote list', 'tackquote' )
		);
		$this->help( __( 'Turn off to hide all of them at once. The options below apply only while this is on.', 'tackquote' ) );
	}

	/**
	 * Explains what switching store prices over actually does.
	 */
	public function section_b2b_pricing() {
		echo '<p>' . esc_html__( 'Price signed-in trade customers from TackQuote. Anonymous shoppers see your normal prices.', 'tackquote' ) . '</p>';
		$this->learn_more(
			array(
				__( 'Price signed-in trade customers using their TackQuote price book, buyer group and quantity breaks — the same pricing that would appear on a quote. Prices are resolved per customer, so nothing changes for anonymous shoppers.', 'tackquote' ),
				__( 'Requires a TackQuote plan that includes B2B pricing. If your plan does not include it, or TackQuote cannot be reached, your store keeps its own prices — no product is ever left unpriced.', 'tackquote' ),
			)
		);

		$this->prerequisite_notice();
	}

	/**
	 * The B2B pricing on/off switch.
	 */
	public function field_enable_wholesale_pricing() {
		$this->checkbox_default_off(
			Tack_Wholesale_Pricing::OPTION_ENABLED,
			__( 'Replace store prices with TackQuote prices for signed-in customers.', 'tackquote' )
		);
		$this->help( __( 'This changes the price used at checkout, not just the price shown. Off by default.', 'tackquote' ) );
	}

	/**
	 * The quantity-break table switch.
	 */
	public function field_show_quantity_breaks() {
		$this->checkbox(
			Tack_Wholesale_Pricing::OPTION_SHOW_BREAKS,
			__( 'Show a "Volume pricing" table on product pages.', 'tackquote' )
		);
		$this->help( __( 'Only appears when the customer actually has more than one price tier for that product.', 'tackquote' ) );
	}

	/**
	 * The order-limits switch.
	 */
	public function field_enable_order_limits() {
		$this->checkbox_default_off(
			Tack_B2B_Notices::OPTION_ORDER_LIMITS,
			__( 'Show and enforce TackQuote minimum/maximum order quantities.', 'tackquote' )
		);
		$this->help(
			__( 'Cart and checkout refuse an order that breaks a limit.', 'tackquote' ),
			__( 'The notice on the product page is a courtesy; the cart and checkout are what actually refuse an order that breaks a limit. If TackQuote cannot be reached, nothing is blocked — a checkout that fails on a slow API is worse than an unenforced minimum.', 'tackquote' )
		);
	}

	/**
	 * The buyer-group badge switch.
	 */
	public function field_enable_buyer_group() {
		$this->checkbox_default_off(
			Tack_B2B_Notices::OPTION_BUYER_GROUP,
			__( 'Show the signed-in customer which pricing group they are on.', 'tackquote' )
		);
		$this->help(
			__( 'Explains why a customer sees a negotiated price.', 'tackquote' ),
			__( 'Without it a discounted price appears with no explanation, which reads as a pricing error rather than the negotiated rate it is.', 'tackquote' )
		);
	}

	/**
	 * The tax-exemption switch.
	 */
	public function field_apply_tax_exempt() {
		$this->checkbox_default_off(
			Tack_Tax_Exempt::OPTION_ENABLED,
			__( 'Charge no tax to signed-in customers whose TackQuote buyer group is tax exempt.', 'tackquote' )
		);
		$this->help(
			__( 'Off by default: it changes what checkout charges.', 'tackquote' ),
			__( 'Charge no tax at checkout to signed-in customers whose TackQuote buyer group is marked tax exempt. Off by default: it changes what checkout charges.', 'tackquote' )
		);
	}

	/**
	 * The method-restriction switch.
	 */
	public function field_enable_group_restrictions() {
		$this->checkbox_default_off(
			Tack_Group_Restrictions::OPTION_ENABLED,
			__( 'Limit payment and shipping methods to particular TackQuote buyer groups.', 'tackquote' )
		);
		$this->help(
			__( 'While this is off, the rules below are saved but ignored.', 'tackquote' ),
			__( 'Leave this off and the rules below are saved but ignored. A method with no groups ticked stays available to everyone either way, so switching this on changes nothing until you tick something. If a rule would remove every payment or shipping option, it is ignored and logged — a checkout nobody can complete is never the right answer to a misconfiguration.', 'tackquote' )
		);
	}

	/**
	 * Intro copy for the buyer-group rules section.
	 */
	public function section_group_rules() {
		echo '<p>' . esc_html__( 'Keep a payment or shipping method for chosen buyer groups only, for example "Net 30 for approved accounts".', 'tackquote' ) . '</p>';
		echo '<p class="description"><strong>' . esc_html__( 'A method with nothing ticked stays available to everyone.', 'tackquote' ) . '</strong></p>';
		$this->learn_more(
			array(
				__( 'Keep a payment or shipping method for approved trade accounts only — "Net 30 is for approved accounts", "pallet delivery is for wholesale". Tick the buyer groups allowed to use each method below.', 'tackquote' ),
				__( 'That is the safe default and it is deliberate: if an unticked method meant "nobody", switching this feature on would remove every payment option at once. Tick a group only where you actually want to narrow who can use the method.', 'tackquote' ),
			)
		);

		$this->prerequisite_notice();
	}

	/**
	 * Warn, in place, when a section cannot do anything yet because the store
	 * is not connected.
	 *
	 * A settings page that silently does nothing is the most expensive kind of
	 * confusion: the merchant configures it correctly, sees no effect, and
	 * concludes the feature is broken. Saying so where they are reading is
	 * cheaper than any amount of documentation.
	 */
	private function prerequisite_notice() {
		if ( '' !== (string) get_option( 'tack_quotes_api_key', '' ) ) {
			return;
		}
		echo '<div class="tack-callout tack-callout--warn"><p>'
			. '<strong>' . esc_html__( 'Nothing in this section takes effect yet.', 'tackquote' ) . '</strong> '
			. esc_html__( 'These settings depend on TackQuote knowing who your buyers are, and no API key is saved. Your settings here are still saved in the meantime.', 'tackquote' )
			. ' <a href="' . esc_url( self::tab_url( 'connection' ) ) . '">' . esc_html__( 'Add an API key', 'tackquote' ) . '</a>'
			. '</p></div>';
	}


	/**
	 * Every buyer group code this store knows about.
	 *
	 * Three sources, unioned, because none of them is complete on its own:
	 *
	 *   1. What the merchant typed into the codes field.
	 *   2. Every code already used by a stored rule. This is what makes the
	 *      upgrade from the old free-text boxes seamless — an existing rule's
	 *      codes appear as ticked checkboxes without anyone retyping them —
	 *      and, more importantly, it guarantees no stored code can ever be
	 *      MISSING from the grid. A code that had no checkbox could not be
	 *      re-ticked, and saving the page would quietly drop it.
	 *   3. The `tackquote_buyer_group_codes` filter, so a site can supply the
	 *      list from somewhere else.
	 *
	 * There is deliberately no TackQuote lookup here. See OPTION_GROUP_CODES:
	 * no API-key-authenticated endpoint to list a tenant's buyer groups
	 * exists, and calling one that does not exist would spend a request on a
	 * 404 every time this page loads.
	 *
	 * @return string[] Upper-cased, unique, sorted.
	 */
	public function known_group_codes() {
		$codes = $this->sanitize_group_codes( get_option( self::OPTION_GROUP_CODES, array() ) );

		$restrictions = new Tack_Group_Restrictions();
		$harvest      = array();
		foreach ( array( Tack_Group_Restrictions::OPTION_PAYMENT_MAP, Tack_Group_Restrictions::OPTION_SHIPPING_MAP, Tack_Catalog_Visibility::OPTION_MAP ) as $option ) {
			foreach ( $restrictions->parse_map( (string) get_option( $option, '' ) ) as $rule_codes ) {
				$harvest[] = $rule_codes;
			}
		}
		// 1.10.0: a shipping discount's code is the line's KEY, not its value.
		$harvest[] = array_keys( $restrictions->parse_discount_map( (string) get_option( Tack_Group_Restrictions::OPTION_DISCOUNT_MAP, '' ) ) );
		foreach ( $harvest as $rule_codes ) {
			foreach ( $rule_codes as $code ) {
				if ( Tack_Catalog_Visibility::GUESTS === strtoupper( (string) $code ) ) {
					// The "guests" pseudo code is its own checkbox, never a group.
					continue;
				}
				// Harvested verbatim, NOT through the typed-input
				// allow-list: a code that came back altered would not
				// match the rule it came from, so its checkbox would
				// render unticked and saving would delete the rule.
				$code = self::clean_stored_group_code( $code );
				if ( '' !== $code && ! in_array( $code, $codes, true ) ) {
					$codes[] = $code;
				}
			}
		}

		$filtered = array();

		/**
		 * Filters the buyer group codes offered as checkboxes on the settings page.
		 *
		 * @since 1.8.0
		 *
		 * @param string[] $codes Upper-cased group codes.
		 */
		foreach ( (array) apply_filters( 'tackquote_buyer_group_codes', $codes ) as $code ) {
			$code = self::clean_stored_group_code( $code );
			if ( '' !== $code && ! in_array( $code, $filtered, true ) ) {
				$filtered[] = $code;
			}
		}

		sort( $filtered );
		return $filtered;
	}

	/**
	 * The codes field: the one place a merchant types a group code.
	 */
	public function field_group_codes() {
		$stored = $this->sanitize_group_codes( get_option( self::OPTION_GROUP_CODES, array() ) );
		$known  = $this->known_group_codes();

		printf(
			'<input type="text" id="%1$s" name="%1$s" value="%2$s" class="regular-text code" placeholder="%3$s" />',
			esc_attr( self::OPTION_GROUP_CODES ),
			esc_attr( implode( ', ', $stored ) ),
			// NOT translatable: an example of the VALUES typed into this box.
			// A translator localising these turns a working example into a
			// broken one. Escaped anyway, at the point of output, so the sniff
			// does not have to reason about where the literal came from.
			esc_attr( 'TIER2, TIER3' )
		);
		$this->help(
			__( 'Separate codes with commas. The grids on this tab use them.', 'tackquote' ),
			__( 'Separate codes with commas. Copy them from TackQuote under Buyer Groups — use the group\'s code, not its display name. Matching ignores case.', 'tackquote' )
		);

		$inherited = array_values( array_diff( $known, $stored ) );
		if ( ! empty( $inherited ) ) {
			echo '<p class="description">' . esc_html__( 'Also in use by rules you already saved:', 'tackquote' ) . ' ';
			$first = true;
			foreach ( $inherited as $code ) {
				if ( ! $first ) {
					echo ', ';
				}
				// Escaped inline, one item at a time: see the note in
				// render_group_map_field() for why this is not hoisted.
				echo '<code>' . esc_html( $code ) . '</code>';
				$first = false;
			}
			echo '</p>';
		}
	}

	/**
	 * Which payment gateways this store has, as rows for the grid.
	 *
	 * `WC()->payment_gateways()->payment_gateways()` returns every REGISTERED
	 * gateway keyed by `$gateway->id`, which is the id the stored rules use.
	 * (See WooCommerce's
	 * `includes/class-wc-payment-gateways.php::payment_gateways()`.) The
	 * available-gateway list is not used on purpose: it is the checkout's
	 * filtered view, and a gateway a rule already restricts could be missing
	 * from it precisely because the rule is working.
	 *
	 * @return array<int, array{id:string,title:string}>
	 */
	private function payment_gateway_rows() {
		if ( ! function_exists( 'WC' ) || ! method_exists( WC(), 'payment_gateways' ) || ! WC()->payment_gateways() ) {
			return array();
		}
		$rows = array();
		foreach ( WC()->payment_gateways()->payment_gateways() as $id => $gateway ) {
			// `get_method_title()` is the ADMIN-screen name — "Direct bank
			// transfer" rather than whatever the merchant renamed it to for
			// customers — which is what makes a row recognisable against
			// WooCommerce → Settings → Payments. Falls back to the customer
			// title, then the bare id, so a third-party gateway that sets
			// neither still gets a row.
			$title = is_object( $gateway ) && method_exists( $gateway, 'get_method_title' ) ? (string) $gateway->get_method_title() : '';
			if ( '' === trim( $title ) && is_object( $gateway ) && method_exists( $gateway, 'get_title' ) ) {
				$title = (string) $gateway->get_title();
			}
			$rows[] = array(
				'id'    => (string) $id,
				'title' => '' !== trim( $title ) ? $title : (string) $id,
			);
		}
		return $rows;
	}

	/**
	 * Which shipping methods this store has, as rows for the grid.
	 *
	 * `WC()->shipping()->get_shipping_methods()` returns the registered
	 * shipping METHODS keyed by `$method->id` — `flat_rate`, `free_shipping`,
	 * `local_pickup` plus anything added through the
	 * `woocommerce_shipping_methods` filter. (See WooCommerce's
	 * `includes/class-wc-shipping.php::register_shipping_method()`,
	 * which assigns `$this->shipping_methods[ $method->id ]`.)
	 *
	 * Methods, not per-zone rate instances, and that is the right level: a
	 * rate id is `method_id:instance_id`, and `filter_shipping_rates()`
	 * already looks a rate up by its full id first and its method id second,
	 * so a rule written against the method covers every zone that offers it.
	 * A merchant who genuinely needs one zone only can still write the full
	 * rate id by hand — such a rule is preserved untouched by the grid.
	 *
	 * @return array<int, array{id:string,title:string}>
	 */
	private function shipping_method_rows() {
		if ( ! function_exists( 'WC' ) || ! method_exists( WC(), 'shipping' ) || ! WC()->shipping() ) {
			return array();
		}
		$methods = WC()->shipping()->get_shipping_methods();
		if ( ! is_array( $methods ) ) {
			return array();
		}
		$rows = array();
		foreach ( $methods as $id => $method ) {
			$title  = is_object( $method ) && method_exists( $method, 'get_method_title' ) ? (string) $method->get_method_title() : '';
			$rows[] = array(
				'id'    => (string) $id,
				'title' => '' !== trim( $title ) ? $title : (string) $id,
			);
		}
		return $rows;
	}

	/**
	 * Payment gateways, one row each.
	 */
	public function field_payment_group_map() {
		$this->render_group_map_field(
			Tack_Group_Restrictions::OPTION_PAYMENT_MAP,
			$this->payment_gateway_rows(),
			__( 'Payment method', 'tackquote' ),
			// NOT translatable: syntax the merchant would type verbatim.
			"cod: TIER2, TIER3\nbacs: TIER3"
		);
	}

	/**
	 * Shipping methods, one row each.
	 */
	public function field_shipping_group_map() {
		$this->render_group_map_field(
			Tack_Group_Restrictions::OPTION_SHIPPING_MAP,
			$this->shipping_method_rows(),
			__( 'Shipping method', 'tackquote' ),
			// Not translatable, for the same reason.
			"free_shipping: TIER3\nlocal_pickup: TIER2, TIER3"
		);
	}

	/**
	 * Render one rules field: a checkbox grid when we can, a textarea when we
	 * cannot.
	 *
	 * ─────────────────────────────────────────────────────────────────────────
	 * THE FALLBACK IS THE POINT, NOT AN AFTERTHOUGHT
	 * ─────────────────────────────────────────────────────────────────────────
	 * If the store has no gateways or shipping methods to list — WooCommerce
	 * not loaded on this request, a fatal in a gateway plugin, a filter that
	 * emptied the list — the grid cannot be drawn. It must NOT then render as
	 * an empty grid, because an empty grid posts an empty set of rules and
	 * would delete the merchant's configuration on the next save. So the field
	 * degrades to the textarea it used to be, pre-filled with exactly what is
	 * stored, and the merchant can still read and edit every rule.
	 *
	 * Only ONE of the two ever renders, and the mode is stated in a hidden
	 * field, so there is never a question of which input the save should
	 * believe.
	 *
	 * @param string $option      Option name.
	 * @param array  $rows        array{id:string,title:string}[].
	 * @param string $column      Heading for the first column.
	 * @param string $placeholder Fallback textarea example. Not translatable.
	 * @param array  $copy        Optional wording (1.10.0): heading, untouched, ticked, no_codes.
	 * @param array  $extra       Optional pseudo codes drawn before the groups, code => label.
	 */
	private function render_group_map_field( $option, $rows, $column, $placeholder, $copy = array(), $extra = array() ) {
		$copy         = array_merge(
			array(
				'heading'   => __( 'Allowed buyer groups', 'tackquote' ),
				'untouched' => __( 'Available to everyone.', 'tackquote' ),
				'ticked'    => __( 'Hidden from everyone except the ticked groups.', 'tackquote' ),
				'no_codes'  => __( 'Add your buyer group codes above to restrict this method.', 'tackquote' ),
			),
			$copy
		);
		$restrictions = new Tack_Group_Restrictions();
		$stored_raw   = (string) get_option( $option, '' );
		$stored       = $restrictions->parse_map( $stored_raw );

		if ( empty( $rows ) ) {
			$this->render_group_map_fallback( $option, $placeholder, count( $stored ) );
			return;
		}

		$known = $this->known_group_codes();

		printf( '<input type="hidden" name="%s[mode]" value="matrix" />', esc_attr( $option ) );

		if ( empty( $known ) && empty( $extra ) ) {
			echo '<div class="tack-callout tack-callout--info"><p>' . esc_html( $copy['no_codes'] ) . '</p></div>';
		}

		/*
		 * One column per group code, so a rule reads across a row and a group reads
		 * down a column. Every checkbox carries an aria-label naming both the row and
		 * the column: a screen reader user tabbing through the grid hears
		 * "Cash on delivery: TIER2", not "checkbox, checkbox".
		 */
		echo '<div class="tack-table-wrap"><table class="widefat striped tack-grid">';
		$columns = count( $extra ) + count( $known );
		$rowspan = $columns > 0 ? ' rowspan="2"' : '';
		echo '<thead><tr><th scope="col"' . $rowspan . ' class="tack-grid__row-head">' . esc_html( $column ) . '</th>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $rowspan is one of two literals above.
		if ( $columns > 0 ) {
			printf( '<th scope="colgroup" colspan="%1$d" class="tack-grid__group">%2$s</th>', (int) $columns, esc_html( $copy['heading'] ) );
		}
		echo '<th scope="col"' . $rowspan . '>' . esc_html__( 'Result', 'tackquote' ) . '</th></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $rowspan is one of two literals above.
		if ( $columns > 0 ) {
			echo '<tr>';
			foreach ( $extra as $code => $label ) {
				echo '<th scope="col" class="tack-grid__col">' . esc_html( $label ) . '</th>';
			}
			foreach ( $known as $code ) {
				echo '<th scope="col" class="tack-grid__col"><code>' . esc_html( $code ) . '</code></th>';
			}
			echo '</tr>';
		}
		echo '</thead><tbody>';

		foreach ( $rows as $row ) {
			$id      = (string) $row['id'];
			$allowed = isset( $stored[ $id ] ) ? $stored[ $id ] : array();

			echo '<tr><th scope="row" class="tack-grid__row-head">';
			echo '<strong>' . esc_html( $row['title'] ) . '</strong> ';
			echo '<code>' . esc_html( $id ) . '</code>';
			printf( '<input type="hidden" name="%1$s[rendered][]" value="%2$s" />', esc_attr( $option ), esc_attr( $id ) );
			echo '</th>';

			foreach ( $extra as $code => $label ) {
				printf(
					'<td class="tack-grid__col"><input type="checkbox" name="%1$s[groups][%2$s][]" value="%3$s" %4$s aria-label="%5$s" /></td>',
					esc_attr( $option ),
					esc_attr( $id ),
					esc_attr( $code ),
					checked( in_array( $code, $allowed, true ), true, false ),
					esc_attr( $row['title'] . ': ' . $label )
				);
			}
			foreach ( $known as $code ) {
				// Escaped at the point of output, one value at a time: pre-escaping into a
				// variable fails Plugin Check's EscapeOutput sniff, which cannot see through it.
				printf(
					'<td class="tack-grid__col"><input type="checkbox" name="%1$s[groups][%2$s][]" value="%3$s" %4$s aria-label="%5$s" /></td>',
					esc_attr( $option ),
					esc_attr( $id ),
					esc_attr( $code ),
					checked( in_array( $code, $allowed, true ), true, false ),
					esc_attr( $row['title'] . ': ' . $code )
				);
			}

			echo '<td class="tack-grid__state"><span class="description">';
			echo empty( $allowed ) ? esc_html( $copy['untouched'] ) : esc_html( $copy['ticked'] );
			echo '</span></td></tr>';
		}

		echo '</tbody></table></div>';

		$this->render_preserved_rules_note( $rows, $stored );
	}

	/**
	 * Tell the merchant about stored rules the grid could not show, so a rule
	 * that still affects checkout is never invisible on the page that owns it.
	 *
	 * @param array $rows   Rendered rows.
	 * @param array $stored Parsed stored rules.
	 */
	private function render_preserved_rules_note( $rows, $stored ) {
		$rendered = array();
		foreach ( $rows as $row ) {
			$rendered[] = (string) $row['id'];
		}
		$extra = array_diff( array_keys( $stored ), $rendered );
		if ( empty( $extra ) ) {
			return;
		}

		echo '<p class="description">' . esc_html__(
			'You also have rules for methods this store does not currently offer. They are kept exactly as they are and will apply again if the method comes back:',
			'tackquote'
		) . ' ';
		$first = true;
		foreach ( $extra as $id ) {
			if ( ! $first ) {
				echo ', ';
			}
			echo '<code>' . esc_html( $id ) . '</code>';
			$first = false;
		}
		echo '</p>';
	}

	/**
	 * The textarea the grid falls back to. Posts under `[text]` with the mode
	 * set, so the sanitizer knows to read it as raw rules.
	 *
	 * @param string $option      Option name.
	 * @param string $placeholder Example. Not translatable.
	 * @param int    $rule_count  How many rules are currently stored.
	 */
	private function render_group_map_fallback( $option, $placeholder, $rule_count ) {
		printf( '<input type="hidden" name="%s[mode]" value="text" />', esc_attr( $option ) );
		printf(
			'<textarea name="%1$s[text]" rows="4" cols="50" class="large-text code" placeholder="%2$s">%3$s</textarea>',
			esc_attr( $option ),
			esc_attr( $placeholder ),
			esc_textarea( (string) get_option( $option, '' ) )
		);
		echo '<p class="description">' . esc_html__(
			'This store reported no methods to list, so the rules are shown as text. One rule per line, as "method id: GROUP_CODE, GROUP_CODE". Your existing rules are shown above and are not changed by this screen.',
			'tackquote'
		) . '</p>';
		if ( $rule_count > 0 ) {
			echo '<p class="description">' . esc_html(
				sprintf(
					/* translators: %d: number of rules currently saved. */
					_n( '%d rule is currently saved.', '%d rules are currently saved.', $rule_count, 'tackquote' ),
					$rule_count
				)
			) . '</p>';
		}
	}

	/**
	 * A checkbox whose stored default is OFF.
	 *
	 * `checkbox()` defaults to 'yes', which is right for the switches that ship
	 * enabled. Anything that changes what a customer is CHARGED must default to
	 * off, so it is opted into rather than inherited from a plugin update.
	 *
	 * @param string $option Option name.
	 * @param string $label  Visible label.
	 */
	private function checkbox_default_off( $option, $label ) {
		$checked = ( 'yes' === get_option( $option, 'no' ) );
		$this->toggle( $option, $label, $checked );
	}

	/**
	 * The order-sync on/off switch.
	 */
	public function field_enable_order_sync() {
		$this->checkbox(
			'tack_quotes_enable_order_sync',
			__( 'Push new and updated WooCommerce orders to TackQuote (one-way).', 'tackquote' )
		);
		$this->help( __( 'Uncheck to stop outbound sync immediately. Existing quotes in TackQuote are not deleted.', 'tackquote' ) );
	}

	/**
	 * Render a yes/no checkbox with a hidden "no" fallback so unchecking saves correctly.
	 *
	 * @param string $option Option name.
	 * @param string $label  Visible label.
	 */
	private function checkbox( $option, $label ) {
		$checked = ( 'yes' === get_option( $option, 'yes' ) );
		$this->toggle( $option, $label, $checked );
	}

	/**
	 * A real checkbox styled as a switch. The hidden "no" comes first, so an
	 * unticked box still posts a value and unchecking saves.
	 *
	 * @param string $option  Option name.
	 * @param string $label   Visible label.
	 * @param bool   $checked Current state.
	 */
	private function toggle( $option, $label, $checked ) {
		printf(
			'<input type="hidden" name="%1$s" value="no" />' .
			'<label class="tack-toggle"><input type="checkbox" class="tack-toggle__input" name="%1$s" value="yes" %2$s /> <span class="tack-toggle__label">%3$s</span></label>',
			esc_attr( $option ),
			checked( $checked, true, false ),
			esc_html( $label )
		);
	}

	// ── BEGIN Storefront forms ─────────────────────────────────────────────────

	/**
	 * Forms section intro.
	 */
	public function section_storefront_forms() {
		echo '<p>' . esc_html__( 'Let customers apply for a wholesale account or for net payment terms from your store.', 'tackquote' ) . '</p>';
		$this->learn_more(
			array(
				__( 'Let customers apply for a wholesale (trade) account or for net payment terms from your store. The wholesale form is the one you design in TackQuote under Settings → Wholesale forms; the net-terms application goes to your TackQuote review queue.', 'tackquote' ),
				__( 'The shortcode [tackquote_wholesale_application] renders the wholesale form on any page; add slug="…" to render a different form. Both tabs below appear on My Account only when ticked. The API key needs the buyers:write scope for applications to be accepted.', 'tackquote' ),
			)
		);
	}

	/**
	 * The slug of the wholesale form to render.
	 */
	public function field_wholesale_form_slug() {
		$value = (string) get_option( Tack_Storefront_Forms::OPTION_FORM_SLUG, Tack_Storefront_Forms::DEFAULT_SLUG );
		printf(
			'<input type="text" class="regular-text code" id="%1$s" name="%1$s" value="%2$s" placeholder="%3$s" />',
			esc_attr( Tack_Storefront_Forms::OPTION_FORM_SLUG ),
			esc_attr( $value ),
			esc_attr( Tack_Storefront_Forms::DEFAULT_SLUG )
		);
		$this->help( __( 'The form slug from TackQuote → Settings → Wholesale forms. Used by the My Account tab and by the shortcode when it names no slug.', 'tackquote' ) );
	}

	/**
	 * The "Wholesale account" tab switch.
	 */
	public function field_enable_wholesale_tab() {
		$this->checkbox_default_off(
			Tack_Storefront_Forms::OPTION_WHOLESALE_TAB,
			__( 'Add a "Wholesale account" tab to My Account with the wholesale application form.', 'tackquote' )
		);
	}

	/**
	 * The "Net terms" tab switch.
	 */
	public function field_enable_net_terms_tab() {
		$this->checkbox_default_off(
			Tack_Storefront_Forms::OPTION_NET_TERMS_TAB,
			__( 'Add a "Net terms" tab to My Account where signed-in customers can apply to pay on account.', 'tackquote' )
		);
		$this->help( __( 'To let approved buyers pay on net terms at checkout, enable "Net terms (TackQuote)" under WooCommerce → Settings → Payments.', 'tackquote' ) );
	}

	/**
	 * Sanitize the wholesale form slug: a key, never empty.
	 *
	 * Guarded by `manage_woocommerce` as well as the page's own `manage_options`:
	 * a store-management capability is the one this storefront behaviour belongs
	 * to, and a caller without it keeps the stored value.
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	public function sanitize_form_slug( $value ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return (string) get_option( Tack_Storefront_Forms::OPTION_FORM_SLUG, Tack_Storefront_Forms::DEFAULT_SLUG );
		}
		$slug = sanitize_key( (string) $value );
		return '' === $slug ? Tack_Storefront_Forms::DEFAULT_SLUG : $slug;
	}

	/**
	 * Sanitize a storefront-forms yes/no switch, with the same capability guard.
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	public function sanitize_storefront_forms_checkbox( $value ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return 'no';
		}
		return $this->sanitize_checkbox( $value );
	}

	// ── END Storefront forms ──────────────────────────────────────────────────

	// ── Page ────────────────────────────────────────────────────────────────────

	/**
	 * Render the settings screen: header, tabs, then the current tab.
	 */
	public function render_page() {
		// Must match add_menu()'s capability and options.php's own requirement — see the note
		// on add_menu() for why that is manage_options.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'tackquote' ) );
		}
		$tab = self::current_tab();
		$this->maybe_handle_post_actions();
		?>
		<div class="wrap tack-admin">
			<header class="tack-header">
				<div class="tack-header__brand">
					<img class="tack-logo" src="<?php echo esc_url( plugins_url( 'assets/images/tackquote-mark.svg', TACK_QUOTES_FILE ) ); ?>" alt="" width="40" height="40" />
					<div>
						<h1 class="tack-header__title"><?php esc_html_e( 'TackQuote', 'tackquote' ); ?></h1>
						<p class="tack-header__tagline"><?php esc_html_e( 'Quotes, B2B pricing and order sync for WooCommerce.', 'tackquote' ); ?></p>
					</div>
				</div>
				<div class="tack-header__meta">
					<span class="tack-badge">
						<?php
						/* translators: %s: plugin version number. */
						echo esc_html( sprintf( __( 'Version %s', 'tackquote' ), TACK_QUOTES_VERSION ) );
						?>
					</span>
					<a href="<?php echo esc_url( self::DOCS_URL ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Docs', 'tackquote' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'tackquote' ); ?></span></a>
				</div>
			</header>
			<?php // WordPress moves admin notices to just after this marker instead of into the header. ?>
			<hr class="wp-header-end" />
			<?php
			// This page sits outside Settings, so WordPress does not print the "Settings
			// saved." notice or a sanitizer's add_settings_error() on its own.
			settings_errors();
			if ( null === $this->action_result ) {
				// The outcome of a "Connect with TackQuote" round trip, carried across its redirects.
				$this->action_result = Tack_Connect::take_notice();
			}
			$this->render_action_result();
			$this->render_tabs( $tab );

			if ( self::DEFAULT_TAB === $tab ) {
				$this->render_overview();
			} else {
				if ( 'connection' === $tab ) {
					Tack_Connect::render_card();
				}
				$this->render_tab_form( $tab );
			}
			if ( 'connection' === $tab ) {
				$this->render_connection_tools();
			}
			?>
		</div>
		<?php
	}

	/**
	 * The tab bar.
	 *
	 * @param string $current Current tab.
	 */
	private function render_tabs( $current ) {
		echo '<nav class="nav-tab-wrapper tack-tabs" aria-label="' . esc_attr__( 'TackQuote settings', 'tackquote' ) . '">';
		foreach ( self::tabs() as $slug => $label ) {
			$active = $slug === $current;
			printf(
				'<a href="%1$s" class="nav-tab%2$s"%3$s>%4$s</a>',
				esc_url( self::tab_url( $slug ) ),
				$active ? ' nav-tab-active' : '',
				$active ? ' aria-current="page"' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';
	}

	/**
	 * One tab's form: its own option group, its own sections, a sticky save bar.
	 *
	 * @param string $tab Tab slug.
	 */
	private function render_tab_form( $tab ) {
		echo '<form method="post" action="options.php" class="tack-form" novalidate>';
		settings_fields( self::option_group( $tab ) );
		do_settings_sections( self::tab_page( $tab ) );
		echo '<div class="tack-savebar">';
		submit_button( __( 'Save changes', 'tackquote' ), 'primary', 'submit', false );
		echo '</div></form>';
	}

	/**
	 * Test connection and Remove saved API key, below the Connection form.
	 */
	private function render_connection_tools() {
		echo '<div class="tack-cards tack-cards--two">';
		echo '<section class="tack-card" aria-labelledby="tack-test-heading"><div class="tack-card__head"><h2 id="tack-test-heading">' . esc_html__( 'Test connection', 'tackquote' ) . '</h2></div>';
		echo '<div class="tack-card__body"><p class="description">' . esc_html__( 'Uses the saved API URL and key to call TackQuote. Save settings first if you just changed them.', 'tackquote' ) . '</p>';
		$this->test_connection_form( 'connection' );
		echo '</div></section>';

		if ( '' !== (string) get_option( 'tack_quotes_api_key', '' ) ) {
			echo '<section class="tack-card tack-card--danger" aria-labelledby="tack-remove-heading"><div class="tack-card__head"><h2 id="tack-remove-heading">' . esc_html__( 'Remove saved API key', 'tackquote' ) . '</h2></div>';
			echo '<div class="tack-card__body"><p class="description">' . esc_html__( 'Deletes the stored key from this site. Quote requests and order sync stop working until a new key is saved. Nothing in your TackQuote account is deleted.', 'tackquote' ) . '</p>';
			printf(
				'<form method="post" action="%1$s" data-tack-confirm="%2$s">',
				esc_url( self::tab_url( 'connection' ) ),
				esc_attr__( 'Remove the saved TackQuote API key? Quote requests and order sync stop until a new key is saved.', 'tackquote' )
			);
			wp_nonce_field( 'tack_quotes_remove_key', 'tack_quotes_remove_key_nonce' );
			echo '<input type="hidden" name="tack_quotes_action" value="remove_api_key" />';
			submit_button( __( 'Remove saved API key', 'tackquote' ), 'delete tack-button-danger', 'submit', false );
			echo '</form></div></section>';
		}
		echo '</div>';
	}

	/**
	 * The "Test TackQuote connection" button, posting back to the given tab.
	 *
	 * @param string $tab Tab to return to.
	 */
	private function test_connection_form( $tab ) {
		printf( '<form method="post" action="%s" class="tack-inline-form">', esc_url( self::tab_url( $tab ) ) );
		wp_nonce_field( 'tack_quotes_test', 'tack_quotes_test_nonce' );
		echo '<input type="hidden" name="tack_quotes_action" value="test_connection" />';
		submit_button( __( 'Test TackQuote connection', 'tackquote' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * Handle the page's own POST buttons: "Test connection" and "Remove saved API key".
	 *
	 * Capability first, then the nonce, then the submitted action — in that order. Reading
	 * `$_POST['tack_quotes_action']` before the nonce check (as this did) is not exploitable
	 * by itself, but it is the read order that lets an unverified value decide what runs next,
	 * and it is what WordPress' coding standards flag.
	 */
	private function maybe_handle_post_actions() {
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['tack_quotes_action'] ) ) {
			return;
		}

		if ( isset( $_POST['tack_quotes_test_nonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tack_quotes_test_nonce'] ) ), 'tack_quotes_test' )
			&& 'test_connection' === sanitize_key( wp_unslash( $_POST['tack_quotes_action'] ) ) ) {
			$this->run_connection_test();
			return;
		}

		if ( isset( $_POST['tack_quotes_remove_key_nonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tack_quotes_remove_key_nonce'] ) ), 'tack_quotes_remove_key' )
			&& 'remove_api_key' === sanitize_key( wp_unslash( $_POST['tack_quotes_action'] ) ) ) {
			// A key "Connect with TackQuote" issued is also revoked in TackQuote, best
			// effort, BEFORE it is deleted here (the revoke authenticates with the key
			// itself). A pasted key is only deleted from this site, as before: the
			// merchant made it in TackQuote and may use it elsewhere.
			$revoked = Tack_Connect::revoke_saved_key();
			delete_option( 'tack_quotes_api_key' );
			delete_option( Tack_Connect::OPTION_VIA );
			delete_option( Tack_Connect::OPTION_AT );
			delete_transient( 'tack_quotes_registration_config' );
			delete_transient( self::CONNECTION_CHECK );
			if ( null === $revoked ) {
				$this->action_result = array(
					'type'    => 'success',
					'message' => __( 'The saved TackQuote API key has been removed.', 'tackquote' ),
				);
			} elseif ( true === $revoked ) {
				$this->action_result = array(
					'type'    => 'success',
					'message' => __( 'The saved TackQuote API key has been removed from this site and revoked in TackQuote.', 'tackquote' ),
				);
			} else {
				$this->action_result = array(
					'type'    => 'warning',
					'message' => __( 'The saved TackQuote API key has been removed from this site, but TackQuote could not be reached to revoke it. Revoke it in TackQuote under Settings → Developer → API Keys.', 'tackquote' ),
				);
			}
		}
	}

	/**
	 * Call TackQuote with the saved credentials and remember the outcome for the
	 * Overview, against a fingerprint of the key that was tested.
	 */
	private function run_connection_test() {
		$this->action_result = self::record_connection_test();
	}

	/**
	 * Run the connection test and remember its outcome for the Overview.
	 *
	 * Shared by the "Test TackQuote connection" button and "Connect with TackQuote",
	 * which runs it right after storing the key it received.
	 *
	 * @since 1.11.0
	 *
	 * @return array{type:string,message:string,ok:bool}
	 */
	public static function record_connection_test() {
		$client = new Tack_Api_Client();
		$result = $client->test_connection();
		$ok     = true === $result;
		$state  = 'ok';
		if ( ! $ok ) {
			$data  = is_wp_error( $result ) ? $result->get_error_data() : null;
			$state = is_array( $data ) && in_array( $data['state'] ?? '', array( Tack_Api_Client::STATE_REJECTED, Tack_Api_Client::STATE_UNVERIFIED ), true )
				? $data['state']
				: Tack_Api_Client::STATE_FAILED;
		}

		$outcome = array(
			'type'    => $ok ? 'success' : ( Tack_Api_Client::STATE_UNVERIFIED === $state ? 'warning' : 'error' ),
			'message' => $ok ? __( 'Connected to TackQuote successfully.', 'tackquote' ) : ( is_wp_error( $result ) ? $result->get_error_message() : __( 'TackQuote did not accept the connection test.', 'tackquote' ) ),
			'ok'      => $ok,
		);
		set_transient(
			self::CONNECTION_CHECK,
			array(
				'ok'      => $ok,
				'state'   => $state,
				'key'     => self::key_fingerprint( (string) get_option( 'tack_quotes_api_key', '' ) ),
				'at'      => time(),
				'message' => $outcome['message'],
			),
			DAY_IN_SECONDS
		);
		return $outcome;
	}

	/**
	 * Print this request's test/remove outcome as an admin notice.
	 */
	private function render_action_result() {
		if ( null === $this->action_result ) {
			return;
		}
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			in_array( $this->action_result['type'], array( 'success', 'warning' ), true ) ? esc_attr( $this->action_result['type'] ) : 'error',
			esc_html( $this->action_result['message'] )
		);
	}

	/**
	 * A one-way fingerprint, so the stored test result can tell keys apart without
	 * holding one.
	 *
	 * @param string $api_key Key.
	 * @return string
	 */
	private static function key_fingerprint( $api_key ) {
		return substr( hash( 'sha256', (string) $api_key ), 0, 16 );
	}

	/**
	 * What the Overview may truthfully say about the connection.
	 *
	 * States:
	 *   none      no key is saved. NEVER reported as connected.
	 *   ok         the last test's authenticated ping passed, for the key saved now.
	 *   rejected   TackQuote refused the key saved now (401/403).
	 *   unverified the server is reachable but has no ping route: key NOT checked.
	 *   failed     the last test failed otherwise (transport, 429, 5xx, ...).
	 *   untested  a key is saved but has not been tested (or a different key was).
	 *
	 * @return array{state:string,checked_at:int,message:string}
	 */
	public static function connection_status() {
		$key = (string) get_option( 'tack_quotes_api_key', '' );
		if ( '' === $key ) {
			return array(
				'state'      => 'none',
				'checked_at' => 0,
				'message'    => '',
			);
		}
		$check = get_transient( self::CONNECTION_CHECK );
		if ( ! is_array( $check ) || ( $check['key'] ?? '' ) !== self::key_fingerprint( $key ) ) {
			return array(
				'state'      => 'untested',
				'checked_at' => 0,
				'message'    => '',
			);
		}
		$state = ! empty( $check['ok'] ) ? 'ok' : (string) ( $check['state'] ?? 'failed' );
		if ( ! in_array( $state, array( 'ok', 'rejected', 'unverified', 'failed' ), true ) || ( 'ok' === $state && empty( $check['ok'] ) ) ) {
			$state = 'failed';
		}
		return array(
			'state'      => $state,
			'checked_at' => (int) ( $check['at'] ?? 0 ),
			'message'    => (string) ( $check['message'] ?? '' ),
		);
	}

	// ── Overview ──────────────────────────────────────────────────────────────

	/**
	 * The Overview: a first-run checklist until the store is connected, then a card
	 * per area with its live state and a link to the tab that configures it.
	 */
	private function render_overview() {
		$status = self::connection_status();
		if ( 'none' === $status['state'] ) {
			$this->render_checklist();
		}
		echo '<div class="tack-cards">';
		$this->card_connection( $status );
		$this->card_storefront();
		$this->card_sync();
		$this->card_b2b();
		$this->card_about();
		echo '</div>';
	}

	/**
	 * Open a card.
	 *
	 * @param string $id    Heading id.
	 * @param string $title Heading.
	 * @param string $state Pill state, or '' for none.
	 * @param string $label Pill label.
	 */
	private function card_open( $id, $title, $state = '', $label = '' ) {
		printf( '<section class="tack-card" aria-labelledby="%1$s"><div class="tack-card__head"><h2 id="%1$s">%2$s</h2>', esc_attr( $id ), esc_html( $title ) );
		if ( '' !== $state ) {
			$this->pill( $state, $label );
		}
		echo '</div><div class="tack-card__body">';
	}

	/**
	 * Close a card, with a "Configure" link to its tab.
	 *
	 * @param string $tab  Tab slug, or '' for no link.
	 * @param string $what Accessible name of what is configured.
	 */
	private function card_close( $tab = '', $what = '' ) {
		echo '</div>';
		if ( '' !== $tab ) {
			printf(
				'<div class="tack-card__foot"><a class="button" href="%1$s">%2$s<span class="screen-reader-text"> %3$s</span></a></div>',
				esc_url( self::tab_url( $tab ) ),
				esc_html__( 'Configure', 'tackquote' ),
				esc_html( $what )
			);
		}
		echo '</section>';
	}

	/**
	 * A definition-list row inside a card.
	 *
	 * @param string $term  Label.
	 * @param string $value Value, plain text.
	 */
	private function card_row( $term, $value ) {
		echo '<div class="tack-dl__row"><dt>' . esc_html( $term ) . '</dt><dd>' . esc_html( $value ) . '</dd></div>';
	}

	/**
	 * First-run steps, shown until a key is saved.
	 */
	private function render_checklist() {
		$steps = array(
			array(
				'done'  => false,
				'label' => __( 'Connect your TackQuote account', 'tackquote' ),
				'tab'   => 'connection',
			),
			array(
				'done'  => null !== get_option( Tack_Catalog_Mode::OPT_MODE, null ),
				'label' => __( 'Choose how customers buy', 'tackquote' ),
				'tab'   => 'storefront',
			),
			array(
				'done'  => 'yes' === get_option( 'tack_quotes_enable_widget', 'yes' ),
				'label' => __( 'Turn on quote buttons', 'tackquote' ),
				'tab'   => 'storefront',
			),
		);
		echo '<section class="tack-card tack-checklist-card" aria-labelledby="tack-start-heading"><div class="tack-card__head"><h2 id="tack-start-heading">' . esc_html__( 'Get started', 'tackquote' ) . '</h2></div><div class="tack-card__body"><ol class="tack-steps">';
		foreach ( $steps as $step ) {
			printf(
				'<li class="tack-step%1$s"><span class="tack-step__mark" aria-hidden="true"></span><a href="%2$s">%3$s</a> <span class="screen-reader-text">%4$s</span></li>',
				$step['done'] ? ' is-done' : '',
				esc_url( self::tab_url( $step['tab'] ) ),
				esc_html( $step['label'] ),
				esc_html( $step['done'] ? __( '(done)', 'tackquote' ) : __( '(to do)', 'tackquote' ) )
			);
		}
		echo '</ol></div></section>';
	}

	/**
	 * Connection card.
	 *
	 * @param array $status connection_status().
	 */
	private function card_connection( $status ) {
		$labels = array(
			'none'       => array( 'error', __( 'Not connected', 'tackquote' ) ),
			'ok'         => array( 'ok', __( 'Connected', 'tackquote' ) ),
			'rejected'   => array( 'error', __( 'Key rejected', 'tackquote' ) ),
			'unverified' => array( 'warn', __( 'Reachable, key not verified', 'tackquote' ) ),
			'failed'     => array( 'error', __( 'Connection failed', 'tackquote' ) ),
			'untested'   => array( 'warn', __( 'Key saved, not tested', 'tackquote' ) ),
		);
		$pill   = $labels[ $status['state'] ];
		$this->card_open( 'tack-card-connection', __( 'Connection', 'tackquote' ), $pill[0], $pill[1] );

		$url  = (string) get_option( 'tack_quotes_api_url', self::DEFAULT_API_URL );
		$host = wp_parse_url( '' !== $url ? $url : self::DEFAULT_API_URL, PHP_URL_HOST );
		$key  = (string) get_option( 'tack_quotes_api_key', '' );

		echo '<dl class="tack-dl">';
		$this->card_row( __( 'API host', 'tackquote' ), is_string( $host ) ? $host : '' );
		$this->card_row( __( 'API key', 'tackquote' ), '' !== $key ? str_repeat( '•', 4 ) . substr( $key, -4 ) : __( 'None saved', 'tackquote' ) );
		if ( '' !== $key ) {
			$this->card_row( __( 'Connected via', 'tackquote' ), Tack_Connect::connected_via_label() );
		}
		if ( $status['checked_at'] > 0 ) {
			$this->card_row(
				__( 'Last test', 'tackquote' ),
				sprintf(
					/* translators: %s: how long ago, e.g. "5 mins". */
					__( '%s ago', 'tackquote' ),
					human_time_diff( $status['checked_at'], time() )
				)
			);
		}
		echo '</dl>';

		if ( in_array( $status['state'], array( 'rejected', 'unverified', 'failed' ), true ) && '' !== $status['message'] ) {
			echo '<p class="description">' . esc_html( $status['message'] ) . '</p>';
		}
		if ( 'none' === $status['state'] ) {
			echo '<p class="description">' . esc_html__( 'Press Connect with TackQuote on the Connection tab, or add an API key, to send quotes and orders to TackQuote.', 'tackquote' ) . '</p>';
		} else {
			$this->test_connection_form( 'overview' );
			echo '<p class="description">' . esc_html__( 'The test checks the key is valid, not its scopes: quotes need quotes:write, order sync needs orders:write.', 'tackquote' ) . '</p>';
		}
		$this->card_close( 'connection', __( 'connection', 'tackquote' ) );
	}

	/**
	 * Storefront card.
	 */
	private function card_storefront() {
		$on         = 'yes' === get_option( 'tack_quotes_enable_widget', 'yes' );
		$quote_only = Tack_Catalog_Mode::MODE_QUOTE_ONLY === get_option( Tack_Catalog_Mode::OPT_MODE, Tack_Catalog_Mode::MODE_CART );
		$this->card_open( 'tack-card-storefront', __( 'Storefront', 'tackquote' ), $on ? 'ok' : 'off', $on ? __( 'Quote buttons on', 'tackquote' ) : __( 'Quote buttons off', 'tackquote' ) );
		echo '<dl class="tack-dl">';
		$this->card_row( __( 'How customers buy', 'tackquote' ), $quote_only ? __( 'Quote only (B2B catalogue)', 'tackquote' ) : __( 'Shop and quotes', 'tackquote' ) );
		$this->card_row(
			__( 'Quote button opens', 'tackquote' ),
			'page' === get_option( Tack_Widget::OPT_OPENS, 'drawer' ) && '' !== (string) get_option( Tack_Widget::OPT_PAGE_URL, '' )
				? __( 'Your quote page', 'tackquote' )
				: __( 'The quote-list drawer', 'tackquote' )
		);
		echo '</dl>';
		$this->card_close( 'storefront', __( 'storefront', 'tackquote' ) );
	}

	/**
	 * Order sync card. Shows only what the plugin actually records: the switch,
	 * a refusal TackQuote answered (Tack_Sync_Gate), and the queue length when
	 * Action Scheduler is present. Failed pushes are logged, not marked failed in
	 * the queue, so no "failed" count is shown: it would always read zero.
	 */
	private function card_sync() {
		$on    = 'yes' === get_option( 'tack_quotes_enable_order_sync', 'no' );
		$block = null;
		if ( $on && class_exists( 'Tack_Sync_Gate' ) ) {
			$block = Tack_Sync_Gate::active_block( (string) get_option( 'tack_quotes_api_key', '' ), time() );
		}
		if ( ! $on ) {
			$this->card_open( 'tack-card-sync', __( 'Order sync', 'tackquote' ), 'off', __( 'Off', 'tackquote' ) );
		} elseif ( is_array( $block ) && 'terminal' === ( $block['kind'] ?? '' ) ) {
			$this->card_open( 'tack-card-sync', __( 'Order sync', 'tackquote' ), 'error', __( 'Refused by TackQuote', 'tackquote' ) );
		} elseif ( is_array( $block ) ) {
			$this->card_open( 'tack-card-sync', __( 'Order sync', 'tackquote' ), 'warn', __( 'Paused briefly', 'tackquote' ) );
		} else {
			$this->card_open( 'tack-card-sync', __( 'Order sync', 'tackquote' ), 'ok', __( 'On', 'tackquote' ) );
		}

		if ( is_array( $block ) && 'terminal' === ( $block['kind'] ?? '' ) ) {
			echo '<p class="description">' . esc_html( Tack_Sync_Gate::notice_text( $block ) ) . '</p>';
		} elseif ( is_array( $block ) ) {
			echo '<p class="description">' . esc_html__( 'TackQuote asked the store to slow down. Sending resumes on its own.', 'tackquote' ) . '</p>';
		} elseif ( ! $on ) {
			echo '<p class="description">' . esc_html__( 'Orders stay in WooCommerce only.', 'tackquote' ) . '</p>';
		}

		if ( $on && function_exists( 'as_get_scheduled_actions' ) && class_exists( 'Tack_Order_Sync' ) ) {
			$pending = as_get_scheduled_actions(
				array(
					'hook'     => Tack_Order_Sync::SYNC_HOOK,
					'group'    => Tack_Order_Sync::SYNC_GROUP,
					'status'   => 'pending',
					'per_page' => 100,
				),
				'ids'
			);
			$count   = is_array( $pending ) ? count( $pending ) : 0;
			echo '<dl class="tack-dl">';
			$this->card_row( __( 'Waiting to send', 'tackquote' ), $count >= 100 ? '100+' : (string) $count );
			echo '</dl>';
		}
		if ( $on ) {
			printf(
				'<p class="description"><a href="%1$s">%2$s</a></p>',
				esc_url( admin_url( 'admin.php?page=wc-status&tab=logs&source=tackquote' ) ),
				esc_html__( 'View sync logs', 'tackquote' )
			);
		}
		$this->card_close( 'sync', __( 'order sync', 'tackquote' ) );
	}

	/**
	 * B2B features card: every switch on the pricing, groups and forms tabs.
	 */
	private function card_b2b() {
		$features = array(
			array( Tack_Wholesale_Pricing::OPTION_ENABLED, __( 'TackQuote prices', 'tackquote' ) ),
			array( Tack_B2B_Notices::OPTION_ORDER_LIMITS, __( 'Order limits', 'tackquote' ) ),
			array( Tack_B2B_Notices::OPTION_BUYER_GROUP, __( 'Buyer group badge', 'tackquote' ) ),
			array( Tack_Tax_Exempt::OPTION_ENABLED, __( 'Tax-exempt buyers', 'tackquote' ) ),
			array( Tack_Group_Restrictions::OPTION_ENABLED, __( 'Payment and shipping by group', 'tackquote' ) ),
			array( Tack_Catalog_Visibility::OPTION_ENABLED, __( 'Hidden categories by group', 'tackquote' ) ),
			array( Tack_Group_Restrictions::OPTION_DISCOUNTS_ENABLED, __( 'Shipping discounts by group', 'tackquote' ) ),
			array( Tack_Role_Mirror::OPTION_ENABLED, __( 'WordPress role per group', 'tackquote' ) ),
			array( Tack_Storefront_Forms::OPTION_WHOLESALE_TAB, __( 'Wholesale account tab', 'tackquote' ) ),
			array( Tack_Storefront_Forms::OPTION_NET_TERMS_TAB, __( 'Net terms tab', 'tackquote' ) ),
		);
		$on       = 0;
		foreach ( $features as $feature ) {
			if ( 'yes' === get_option( $feature[0], 'no' ) ) {
				++$on;
			}
		}
		$this->card_open(
			'tack-card-b2b',
			__( 'B2B features', 'tackquote' ),
			$on > 0 ? 'ok' : 'off',
			sprintf(
				/* translators: 1: number of features switched on, 2: number of features. */
				__( '%1$d of %2$d on', 'tackquote' ),
				$on,
				count( $features )
			)
		);
		echo '<ul class="tack-features">';
		foreach ( $features as $feature ) {
			$enabled = 'yes' === get_option( $feature[0], 'no' );
			printf(
				'<li class="tack-feature%1$s"><span class="tack-feature__name">%2$s</span> <span class="tack-feature__state">%3$s</span></li>',
				$enabled ? ' is-on' : '',
				esc_html( $feature[1] ),
				esc_html( $enabled ? __( 'On', 'tackquote' ) : __( 'Off', 'tackquote' ) )
			);
		}
		echo '</ul>';
		if ( '' === (string) get_option( 'tack_quotes_api_key', '' ) && $on > 0 ) {
			echo '<p class="description">' . esc_html__( 'These need an API key before they take effect.', 'tackquote' ) . '</p>';
		}
		echo '</div><div class="tack-card__foot">';
		foreach ( array(
			'pricing' => __( 'B2B pricing', 'tackquote' ),
			'groups'  => __( 'Buyer groups', 'tackquote' ),
			'forms'   => __( 'Forms', 'tackquote' ),
		) as $tab => $label ) {
			printf( '<a class="button" href="%1$s">%2$s</a> ', esc_url( self::tab_url( $tab ) ), esc_html( $label ) );
		}
		echo '</div></section>';
	}

	/**
	 * About card: version and help links.
	 */
	private function card_about() {
		$this->card_open( 'tack-card-about', __( 'Help', 'tackquote' ) );
		echo '<dl class="tack-dl">';
		$this->card_row( __( 'Plugin version', 'tackquote' ), TACK_QUOTES_VERSION );
		echo '</dl><ul class="tack-links">';
		printf( '<li><a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s<span class="screen-reader-text"> %3$s</span></a></li>', esc_url( self::DOCS_URL ), esc_html__( 'Setup guide', 'tackquote' ), esc_html__( '(opens in a new tab)', 'tackquote' ) );
		printf( '<li><a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s<span class="screen-reader-text"> %3$s</span></a></li>', esc_url( self::SUPPORT_URL ), esc_html__( 'Support', 'tackquote' ), esc_html__( '(opens in a new tab)', 'tackquote' ) );
		echo '</ul>';
		$this->card_close();
	}

	// ── Storefront: card/cart buttons, quote list and launcher, quote page (1.10.0) ─
	//
	// Every option below is additive, OFF by default or defaulting to the 1.8.x
	// layout, so an update changes nothing a shopper sees until the merchant chooses to.

	/**
	 * Register the storefront-layout settings and their sections on the Storefront tab.
	 *
	 * @since 1.10.0
	 */
	public function register_storefront_layout_settings() {
		$checkbox = array( $this, 'sanitize_checkbox' );

		$this->setting( 'storefront', Tack_Widget::OPT_CARD_BUTTONS, $checkbox );
		$this->setting( 'storefront', Tack_Widget::OPT_CART_BUTTON, $checkbox );
		$this->setting( 'storefront', Tack_Widget::OPT_CART_BUTTON_LABEL, 'sanitize_text_field' );
		$this->setting( 'storefront', Tack_Widget::OPT_OPENS, array( $this, 'sanitize_quote_opens' ) );
		$this->setting( 'storefront', Tack_Widget::OPT_PAGE_URL, array( $this, 'sanitize_quote_page_url' ) );
		$this->setting( 'storefront', Tack_Widget::OPT_FAB_POSITION, array( $this, 'sanitize_fab_position' ) );
		$this->setting( 'storefront', Tack_Widget::OPT_FAB_OFFSET_X, array( $this, 'sanitize_fab_offset' ) );
		$this->setting( 'storefront', Tack_Widget::OPT_FAB_OFFSET_Y, array( $this, 'sanitize_fab_offset' ) );
		$this->setting( 'storefront', Tack_Widget::OPT_FAB_PAGES, array( $this, 'sanitize_fab_pages' ) );
		$this->setting( 'storefront', Tack_Widget::OPT_FAB_LABEL, 'sanitize_text_field' );
		$this->setting( 'storefront', Tack_Widget::OPT_FAB_ICON_ONLY, $checkbox );
		$this->setting( 'storefront', Tack_Widget::OPT_FAB_SHOW_COUNT, $checkbox );
		$this->setting( 'storefront', Tack_Widget::OPT_FAB_SIZE, array( $this, 'sanitize_fab_size' ) );
		$this->setting( 'storefront', Tack_Widget::OPT_FAB_HIDE_MOBILE, $checkbox );
		$this->setting( 'storefront', Tack_Attachments::OPTION_ENABLED, $checkbox );

		// Product cards and the cart page belong with the other buttons.
		$this->field( 'storefront', 'tack_quotes_storefront', Tack_Widget::OPT_CARD_BUTTONS, __( 'Product cards', 'tackquote' ), array( $this, 'field_card_buttons' ) );
		$this->field( 'storefront', 'tack_quotes_storefront', Tack_Widget::OPT_CART_BUTTON, __( 'Cart page', 'tackquote' ), array( $this, 'field_cart_button' ) );

		$this->section( 'storefront', 'tack_quotes_storefront_layout', __( 'Quote list & launcher', 'tackquote' ), array( $this, 'section_storefront_layout' ) );
		$this->field( 'storefront', 'tack_quotes_storefront_layout', 'tack_quotes_checkout_button_label', __( '"Checkout as Quote" label', 'tackquote' ), array( $this, 'field_checkout_button_label' ), array( 'label_for' => 'tack_quotes_checkout_button_label' ) );
		$this->field( 'storefront', 'tack_quotes_storefront_layout', Tack_Widget::OPT_FAB_PAGES, __( 'Show launcher on', 'tackquote' ), array( $this, 'field_fab_pages' ), array( 'label_for' => Tack_Widget::OPT_FAB_PAGES ) );
		$this->field( 'storefront', 'tack_quotes_storefront_layout', Tack_Widget::OPT_FAB_POSITION, __( 'Position', 'tackquote' ), array( $this, 'field_fab_position' ), array( 'label_for' => Tack_Widget::OPT_FAB_POSITION ) );
		$this->field( 'storefront', 'tack_quotes_storefront_layout', Tack_Widget::OPT_FAB_OFFSET_X, __( 'Distance from edges', 'tackquote' ), array( $this, 'field_fab_offsets' ) );
		$this->field( 'storefront', 'tack_quotes_storefront_layout', Tack_Widget::OPT_FAB_LABEL, __( 'Button text', 'tackquote' ), array( $this, 'field_fab_label' ), array( 'label_for' => Tack_Widget::OPT_FAB_LABEL ) );
		$this->field( 'storefront', 'tack_quotes_storefront_layout', Tack_Widget::OPT_FAB_SIZE, __( 'Size', 'tackquote' ), array( $this, 'field_fab_size' ), array( 'label_for' => Tack_Widget::OPT_FAB_SIZE ) );
		$this->field( 'storefront', 'tack_quotes_storefront_layout', Tack_Widget::OPT_FAB_ICON_ONLY, __( 'Display', 'tackquote' ), array( $this, 'field_fab_display' ) );

		$this->section( 'storefront', 'tack_quotes_quote_page', __( 'Quote page', 'tackquote' ), array( $this, 'section_quote_page' ) );
		$this->field( 'storefront', 'tack_quotes_quote_page', Tack_Widget::OPT_OPENS, __( 'Quote button opens', 'tackquote' ), array( $this, 'field_quote_opens' ) );

		// 1.10.0: files on quote requests.
		$this->section( 'storefront', 'tack_quotes_attachments', __( 'Attachments', 'tackquote' ), array( $this, 'section_attachments' ) );
		$this->field( 'storefront', 'tack_quotes_attachments', Tack_Attachments::OPTION_ENABLED, __( 'Quote requests', 'tackquote' ), array( $this, 'field_enable_attachments' ) );

		// 1.10.0: how the storefront controls look.
		$this->setting( 'storefront', Tack_Widget::OPT_THEME_STYLES_ONLY, $checkbox );
		$this->setting( 'storefront', Tack_Widget::OPT_ACCENT_COLOR, array( $this, 'sanitize_accent_color' ) );
		$this->section( 'storefront', 'tack_quotes_styling', __( 'Styling', 'tackquote' ), array( $this, 'section_styling' ) );
		$this->field( 'storefront', 'tack_quotes_styling', Tack_Widget::OPT_THEME_STYLES_ONLY, __( 'Theme styles', 'tackquote' ), array( $this, 'field_theme_styles_only' ) );
		$this->field( 'storefront', 'tack_quotes_styling', Tack_Widget::OPT_ACCENT_COLOR, __( 'Accent colour', 'tackquote' ), array( $this, 'field_accent_color' ), array( 'label_for' => Tack_Widget::OPT_ACCENT_COLOR ) );
	}

	/**
	 * Intro copy for the styling section.
	 *
	 * @since 1.10.0
	 */
	public function section_styling() {
		echo '<p>' . esc_html__( 'The quote buttons, launcher, drawer, quote page and forms use your theme\'s own buttons, fields, fonts and colours.', 'tackquote' ) . '</p>';
		$this->learn_more(
			array(
				__( 'To change one detail, set a --tackquote-* CSS variable in Appearance > Customize > Additional CSS (classic themes) or in the Site Editor\'s custom CSS (block themes), for example :root { --tackquote-radius: 0; }. Developers can copy the templates in the plugin\'s templates/tackquote/ folder into yourtheme/woocommerce/tackquote/ and edit them there, like WooCommerce\'s own templates.', 'tackquote' ),
			)
		);
	}

	/**
	 * "Use the theme's styles only".
	 *
	 * @since 1.10.0
	 */
	public function field_theme_styles_only() {
		$this->checkbox_default_off(
			Tack_Widget::OPT_THEME_STYLES_ONLY,
			__( 'Use the theme\'s styles only', 'tackquote' )
		);
		$this->help( __( 'Loads only the layout rules the launcher, drawer and request form need to work; your theme styles everything else.', 'tackquote' ) );
	}

	/**
	 * The accent colour picker.
	 *
	 * @since 1.10.0
	 */
	public function field_accent_color() {
		printf(
			'<input type="text" class="tack-color-field" id="%1$s" name="%1$s" value="%2$s" data-default-color="" maxlength="7" />',
			esc_attr( Tack_Widget::OPT_ACCENT_COLOR ),
			esc_attr( Tack_Widget::accent_color() )
		);
		$this->help( __( 'Optional. Repaints the quote buttons and the launcher in this colour. Leave it empty to use your theme\'s button colours.', 'tackquote' ) );
	}

	/**
	 * Sanitize the accent colour: a hex colour, or '' for the theme's.
	 *
	 * @since 1.10.0
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	public function sanitize_accent_color( $value ) {
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( '' === $value ) {
			return '';
		}
		$hex = sanitize_hex_color( $value );
		if ( ! is_string( $hex ) || '' === $hex ) {
			add_settings_error( Tack_Widget::OPT_ACCENT_COLOR, 'tack_accent_color', __( 'The accent colour must be a hex colour such as #1e73be. It was left unchanged.', 'tackquote' ) );
			return (string) get_option( Tack_Widget::OPT_ACCENT_COLOR, '' );
		}
		return $hex;
	}

	/**
	 * Intro copy for the attachments section.
	 *
	 * @since 1.10.0
	 */
	public function section_attachments() {
		echo '<p>' . esc_html__( 'Let shoppers add drawings, specifications or purchase orders to a quote request.', 'tackquote' ) . '</p>';
	}

	/**
	 * "Allow attachments on quote requests", with what the server said about it.
	 *
	 * Reads the cached capability answer only: rendering this page never calls TackQuote.
	 *
	 * @since 1.10.0
	 */
	public function field_enable_attachments() {
		$this->show_row_when( 'tack_quotes_enable_widget', 'yes' );
		$this->checkbox_default_off(
			Tack_Attachments::OPTION_ENABLED,
			__( 'Allow attachments on quote requests', 'tackquote' )
		);
		$cached = get_transient( Tack_Api_Client::CAPABILITIES_TRANSIENT );
		if ( is_array( $cached ) && Tack_Api_Client::STATE_REJECTED === ( $cached['failed'] ?? '' ) ) {
			$state = __( 'TackQuote rejected the saved API key, so attachments stay off. Run "Test connection" on the Connection tab after fixing the key.', 'tackquote' );
		} elseif ( is_array( $cached ) && isset( $cached['caps'] ) && is_array( $cached['caps'] ) ) {
			$state = in_array( 'attachments', $cached['caps'], true )
				? __( 'Your TackQuote server accepts attachments.', 'tackquote' )
				: __( 'Your TackQuote server does not accept attachments yet, so the control stays hidden even when this is on.', 'tackquote' );
		} else {
			$state = __( 'Run "Test connection" on the Connection tab to check that your TackQuote server accepts attachments.', 'tackquote' );
		}
		echo '<p class="description">' . esc_html( $state ) . '</p>';
		$this->help(
			__( 'Up to 3 PDF, JPEG or PNG files of 5 MB each. Files go straight to TackQuote and are never stored on this site.', 'tackquote' ),
			array(
				__( 'An optional "Attach files" control appears in the quote form (drawer and quote page). Each file is checked here first (type by extension and by content, size, count), then streamed from this server to TackQuote; nothing is saved in your media library or uploads folder. The seller sees the files on the quote.', 'tackquote' ),
				__( 'Signed-in customers\' files are linked to their account email; a guest\'s files to a one-time token, so nobody else can attach them to a request. Files never attached to a request are deleted by TackQuote after 24 hours. The API key needs the quotes:write scope.', 'tackquote' ),
				__( 'Wholesale application forms with file fields accept files from signed-in customers whenever the server supports attachments; this switch is about quote requests only.', 'tackquote' ),
			)
		);
	}

	/**
	 * Intro copy for the quote list and launcher section.
	 *
	 * @since 1.10.0
	 */
	public function section_storefront_layout() {
		echo '<p>' . esc_html__( 'The floating button that opens the shopper\'s quote list. Defaults match the launcher the plugin always had.', 'tackquote' ) . '</p>';
		$this->learn_more(
			array(
				__( 'Where else shoppers can start a quote, and how the floating quote-list launcher looks. Everything here is off, or set to the layout the plugin always had, until you change it. Every control uses your theme\'s own button styling.', 'tackquote' ),
				__( 'Defaults match the launcher the plugin always had: bottom right, 20 px from each edge, "Quote list (n)", regular size, every page. On phones narrower than 480 px the launcher is always compact and sits above the device\'s home indicator.', 'tackquote' ),
			)
		);
	}

	/**
	 * Intro copy for the quote page section.
	 */
	public function section_quote_page() {
		echo '<p>' . esc_html__( 'Send shoppers to a full quote page instead of the drawer.', 'tackquote' ) . '</p>';
	}

	/**
	 * "Add to Quote" on product cards.
	 *
	 * @since 1.10.0
	 */
	public function field_card_buttons() {
		$this->show_row_when( 'tack_quotes_enable_widget', 'yes' );
		$this->checkbox_default_off(
			Tack_Widget::OPT_CARD_BUTTONS,
			__( 'Show "Add to Quote" on product cards in the shop, category and search lists.', 'tackquote' )
		);
		$this->help(
			__( 'Uses the "Add to Quote" label above.', 'tackquote' ),
			__( 'Simple products are added to the quote list straight from the card. Variable, grouped and external products link to their page, where the shopper chooses options first.', 'tackquote' )
		);
	}

	/**
	 * "Request a quote for your cart" on the cart page, with its label.
	 *
	 * @since 1.10.0
	 */
	public function field_cart_button() {
		$this->show_row_when( 'tack_quotes_enable_widget', 'yes' );
		$this->checkbox_default_off(
			Tack_Widget::OPT_CART_BUTTON,
			__( 'Show "Request a quote for your cart" on the cart page.', 'tackquote' )
		);
		$this->open_when( Tack_Widget::OPT_CART_BUTTON, 'yes', 'tack-indent' );
		printf(
			'<p><label for="%1$s" class="tack-inline-label">%4$s</label> <input type="text" class="regular-text" id="%1$s" name="%1$s" value="%2$s" placeholder="%3$s" /></p>',
			esc_attr( Tack_Widget::OPT_CART_BUTTON_LABEL ),
			esc_attr( (string) get_option( Tack_Widget::OPT_CART_BUTTON_LABEL, '' ) ),
			esc_attr__( 'Request a quote for your cart', 'tackquote' ),
			esc_html__( 'Button label', 'tackquote' )
		);
		echo '</div>';
		$this->help(
			__( 'Copies the cart into the quote list; the cart itself is untouched. Leave the label blank for the default.', 'tackquote' ),
			__( 'Shown under "Proceed to checkout" on the classic cart page and as a fixed button on the Cart block. It copies every line of the cart into the quote list; the WooCommerce cart itself is untouched. Leave the label blank for the default.', 'tackquote' )
		);
	}

	/**
	 * Drawer or page, and the page URL.
	 *
	 * @since 1.10.0
	 */
	public function field_quote_opens() {
		$this->show_row_when( 'tack_quotes_enable_widget', 'yes' );
		$opens   = (string) get_option( Tack_Widget::OPT_OPENS, 'drawer' );
		$choices = array(
			'drawer' => __( 'The quote-list drawer (default)', 'tackquote' ),
			'page'   => __( 'A quote page of my own, at this address:', 'tackquote' ),
		);
		echo '<fieldset class="tack-choices"><legend class="screen-reader-text">' . esc_html__( 'Quote button opens', 'tackquote' ) . '</legend>';
		foreach ( $choices as $value => $label ) {
			printf(
				'<label class="tack-choice tack-choice--compact"><input type="radio" name="%1$s" value="%2$s" %3$s /> <span class="tack-choice__text">%4$s</span></label>',
				esc_attr( Tack_Widget::OPT_OPENS ),
				esc_attr( $value ),
				checked( $opens, $value, false ),
				esc_html( $label )
			);
		}
		$this->open_when( Tack_Widget::OPT_OPENS, 'page', 'tack-indent' );
		printf(
			'<p><label for="%1$s" class="screen-reader-text">%4$s</label><input type="url" class="regular-text" id="%1$s" name="%1$s" value="%2$s" placeholder="%3$s" /></p>',
			esc_attr( Tack_Widget::OPT_PAGE_URL ),
			esc_attr( (string) get_option( Tack_Widget::OPT_PAGE_URL, '' ) ),
			esc_attr( home_url( '/quote/' ) ),
			esc_html__( 'Quote page address', 'tackquote' )
		);
		echo '</div></fieldset>';
		$this->help(
			__( 'Create a page containing the shortcode [tackquote_quote_page] and paste its address here.', 'tackquote' ),
			__( 'Create a page containing the shortcode [tackquote_quote_page] and paste its address here. The launcher and the card and cart buttons then open that page, where shoppers can change quantities, add a target price per line and a message before sending. Without an address the drawer is used.', 'tackquote' )
		);
	}

	/**
	 * Launcher pages.
	 */
	public function field_fab_pages() {
		$this->show_row_when( 'tack_quotes_enable_widget', 'yes' );
		$fab = Tack_Widget::fab_settings();
		$this->select(
			Tack_Widget::OPT_FAB_PAGES,
			array(
				'all'     => __( 'All pages', 'tackquote' ),
				'product' => __( 'Product pages only', 'tackquote' ),
				'cart'    => __( 'Cart page only', 'tackquote' ),
				'none'    => __( 'Nowhere (use the quote page instead)', 'tackquote' ),
			),
			$fab['pages']
		);
	}

	/**
	 * Launcher side.
	 */
	public function field_fab_position() {
		$this->show_row_when( 'tack_quotes_enable_widget', 'yes' );
		$this->show_row_when( Tack_Widget::OPT_FAB_PAGES, array( 'all', 'product', 'cart' ) );
		$fab = Tack_Widget::fab_settings();
		$this->select(
			Tack_Widget::OPT_FAB_POSITION,
			array(
				'bottom-right' => __( 'Bottom right', 'tackquote' ),
				'bottom-left'  => __( 'Bottom left', 'tackquote' ),
			),
			$fab['position']
		);
	}

	/**
	 * Launcher offsets.
	 */
	public function field_fab_offsets() {
		$this->show_row_when( 'tack_quotes_enable_widget', 'yes' );
		$this->show_row_when( Tack_Widget::OPT_FAB_PAGES, array( 'all', 'product', 'cart' ) );
		$fab = Tack_Widget::fab_settings();
		echo '<div class="tack-inline-fields">';
		printf(
			'<label>%1$s <input type="number" class="small-text" min="0" max="%4$d" step="1" name="%2$s" value="%3$d" /> px</label> <label>%5$s <input type="number" class="small-text" min="0" max="%4$d" step="1" name="%6$s" value="%7$d" /> px</label>',
			esc_html__( 'Side offset', 'tackquote' ),
			esc_attr( Tack_Widget::OPT_FAB_OFFSET_X ),
			(int) $fab['offsetX'],
			(int) Tack_Widget::FAB_OFFSET_MAX,
			esc_html__( 'Bottom offset', 'tackquote' ),
			esc_attr( Tack_Widget::OPT_FAB_OFFSET_Y ),
			(int) $fab['offsetY']
		);
		echo '</div>';
	}

	/**
	 * Launcher text.
	 */
	public function field_fab_label() {
		$this->show_row_when( 'tack_quotes_enable_widget', 'yes' );
		$this->show_row_when( Tack_Widget::OPT_FAB_PAGES, array( 'all', 'product', 'cart' ) );
		printf(
			'<input type="text" class="regular-text" id="%1$s" name="%1$s" value="%2$s" placeholder="%3$s" />',
			esc_attr( Tack_Widget::OPT_FAB_LABEL ),
			esc_attr( (string) get_option( Tack_Widget::OPT_FAB_LABEL, '' ) ),
			esc_attr__( 'Quote list', 'tackquote' )
		);
	}

	/**
	 * Launcher size.
	 */
	public function field_fab_size() {
		$this->show_row_when( 'tack_quotes_enable_widget', 'yes' );
		$this->show_row_when( Tack_Widget::OPT_FAB_PAGES, array( 'all', 'product', 'cart' ) );
		$fab = Tack_Widget::fab_settings();
		$this->select(
			Tack_Widget::OPT_FAB_SIZE,
			array(
				'regular' => __( 'Regular', 'tackquote' ),
				'compact' => __( 'Compact', 'tackquote' ),
			),
			$fab['size']
		);
	}

	/**
	 * Launcher icon only, item count, hide on mobile.
	 */
	public function field_fab_display() {
		$this->show_row_when( 'tack_quotes_enable_widget', 'yes' );
		$this->show_row_when( Tack_Widget::OPT_FAB_PAGES, array( 'all', 'product', 'cart' ) );
		echo '<fieldset class="tack-stack"><legend class="screen-reader-text">' . esc_html__( 'Display', 'tackquote' ) . '</legend>';
		$this->checkbox_default_off( Tack_Widget::OPT_FAB_ICON_ONLY, __( 'Icon only (the text stays for screen readers)', 'tackquote' ) );
		$this->checkbox( Tack_Widget::OPT_FAB_SHOW_COUNT, __( 'Show item count', 'tackquote' ) );
		$this->checkbox_default_off( Tack_Widget::OPT_FAB_HIDE_MOBILE, __( 'Hide on mobile', 'tackquote' ) );
		echo '</fieldset>';
	}

	/**
	 * A select whose label is the form-table row heading (`label_for`).
	 *
	 * @param string $option  Option name, also the element id.
	 * @param array  $choices value => label.
	 * @param string $current Current value.
	 */
	private function select( $option, $choices, $current ) {
		printf( '<select id="%1$s" name="%1$s">', esc_attr( $option ) );
		foreach ( $choices as $value => $text ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $value ),
				selected( (string) $current, (string) $value, false ),
				esc_html( $text )
			);
		}
		echo '</select>';
	}

	/**
	 * `drawer` or `page`; anything else is `drawer`.
	 *
	 * @since 1.10.0
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_quote_opens( $value ) {
		return 'page' === $value ? 'page' : 'drawer';
	}

	/**
	 * The quote page address: an http(s) URL, or ''. Anything else (another
	 * scheme, a bare word) is dropped rather than stored, so the launcher can
	 * never be sent to a `javascript:` address.
	 *
	 * @since 1.10.0
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_quote_page_url( $value ) {
		$url = is_string( $value ) ? esc_url_raw( trim( $value ), array( 'http', 'https' ) ) : '';
		if ( '' === $url || ! preg_match( '#^https?://#i', $url ) ) {
			return '';
		}
		return $url;
	}

	/**
	 * `bottom-right` or `bottom-left`.
	 *
	 * @since 1.10.0
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_fab_position( $value ) {
		return in_array( $value, array( 'bottom-right', 'bottom-left' ), true ) ? (string) $value : 'bottom-right';
	}

	/**
	 * A whole number of pixels within [0, FAB_OFFSET_MAX]; anything else is the 20 px default.
	 *
	 * @since 1.10.0
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public function sanitize_fab_offset( $value ) {
		if ( ! is_numeric( $value ) ) {
			return 20;
		}
		$n = (int) $value;
		return ( $n >= 0 && $n <= Tack_Widget::FAB_OFFSET_MAX ) ? $n : 20;
	}

	/**
	 * `all`, `product`, `cart` or `none`.
	 *
	 * @since 1.10.0
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_fab_pages( $value ) {
		return in_array( $value, array( 'all', 'product', 'cart', 'none' ), true ) ? (string) $value : 'all';
	}

	/**
	 * `regular` or `compact`.
	 *
	 * @since 1.10.0
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public function sanitize_fab_size( $value ) {
		return in_array( $value, array( 'regular', 'compact' ), true ) ? (string) $value : 'regular';
	}

	// ── Buyer groups: catalogue and shipping per buyer group (1.10.0) ─────────

	/**
	 * Register catalogue visibility, shipping discounts and the role mirror on the
	 * Buyer groups tab, beside the group codes they key on.
	 */
	public function register_group_catalog_settings() {
		$checkbox = array( $this, 'sanitize_checkbox' );

		$this->setting( 'groups', Tack_Catalog_Visibility::OPTION_ENABLED, $checkbox );
		$this->setting( 'groups', Tack_Catalog_Visibility::OPTION_HIDE_WHEN_UNKNOWN, $checkbox );
		$this->setting( 'groups', Tack_Catalog_Visibility::OPTION_MAP, array( $this, 'sanitize_catalog_visibility_map' ) );
		$this->setting( 'groups', Tack_Group_Restrictions::OPTION_DISCOUNTS_ENABLED, $checkbox );
		$this->setting( 'groups', Tack_Group_Restrictions::OPTION_DISCOUNT_MAP, array( $this, 'sanitize_shipping_discount_map' ) );
		$this->setting( 'groups', Tack_Role_Mirror::OPTION_ENABLED, $checkbox );

		$this->section( 'groups', 'tack_quotes_group_catalog', __( 'Catalogue and shipping per buyer group', 'tackquote' ), array( $this, 'section_group_catalog' ) );
		$this->field( 'groups', 'tack_quotes_group_catalog', Tack_Catalog_Visibility::OPTION_ENABLED, __( 'Hide categories by group', 'tackquote' ), array( $this, 'field_enable_catalog_visibility' ) );
		$this->field( 'groups', 'tack_quotes_group_catalog', Tack_Catalog_Visibility::OPTION_MAP, __( 'Product categories', 'tackquote' ), array( $this, 'field_catalog_visibility_map' ) );
		$this->field( 'groups', 'tack_quotes_group_catalog', Tack_Group_Restrictions::OPTION_DISCOUNTS_ENABLED, __( 'Shipping discounts by group', 'tackquote' ), array( $this, 'field_enable_shipping_discounts' ) );
		$this->field( 'groups', 'tack_quotes_group_catalog', Tack_Group_Restrictions::OPTION_DISCOUNT_MAP, __( 'Discount per group', 'tackquote' ), array( $this, 'field_shipping_discount_map' ) );
		$this->field( 'groups', 'tack_quotes_group_catalog', Tack_Role_Mirror::OPTION_ENABLED, __( 'WordPress role per group', 'tackquote' ), array( $this, 'field_enable_role_mirror' ) );
	}

	/**
	 * Intro copy for the catalogue section.
	 */
	public function section_group_catalog() {
		echo '<p>' . esc_html__( 'Off by default. Uses the group codes above; these rules live in this plugin, not in TackQuote.', 'tackquote' ) . '</p>';
		$this->learn_more( __( 'Everything here is off by default and keys on the buyer group TackQuote reports, using the group codes on this tab. These rules are plugin settings; TackQuote does not send them.', 'tackquote' ) );
		$this->prerequisite_notice();
	}

	/**
	 * Catalogue visibility switches.
	 */
	public function field_enable_catalog_visibility() {
		echo '<fieldset class="tack-stack"><legend class="screen-reader-text">' . esc_html__( 'Hide categories by group', 'tackquote' ) . '</legend>';
		$this->checkbox_default_off(
			Tack_Catalog_Visibility::OPTION_ENABLED,
			__( 'Hide the ticked product categories from the ticked buyer groups.', 'tackquote' )
		);
		$this->checkbox_default_off(
			Tack_Catalog_Visibility::OPTION_HIDE_WHEN_UNKNOWN,
			__( 'Also hide them when the buyer group is unknown (TackQuote cannot be reached).', 'tackquote' )
		);
		echo '</fieldset>';
		$this->help(
			__( 'Store managers always see everything.', 'tackquote' ),
			__( 'Hidden products leave the shop, category and search pages, product blocks, related products, up-sells and cross-sells; their own page answers "not found", they cannot be bought, and a hidden product already in a cart is removed with a notice. Store managers always see everything.', 'tackquote' )
		);
	}

	/**
	 * Product categories as rows of the shared grid.
	 *
	 * @return array<int, array{id:string,title:string}>
	 */
	private function product_category_rows() {
		if ( ! function_exists( 'get_terms' ) ) {
			return array();
		}
		$terms = get_terms(
			array(
				'taxonomy'   => Tack_Catalog_Visibility::TAXONOMY,
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);
		if ( ! is_array( $terms ) ) {
			return array();
		}
		$rows = array();
		foreach ( $terms as $term ) {
			if ( ! is_object( $term ) || empty( $term->term_id ) ) {
				continue;
			}
			$rows[] = array(
				'id'    => (string) (int) $term->term_id,
				'title' => (string) $term->name,
			);
		}
		return $rows;
	}

	/**
	 * The visibility grid: one row per category, a "Guests" box plus the groups.
	 */
	public function field_catalog_visibility_map() {
		$this->show_row_when( Tack_Catalog_Visibility::OPTION_ENABLED, 'yes' );
		$this->render_group_map_field(
			Tack_Catalog_Visibility::OPTION_MAP,
			$this->product_category_rows(),
			__( 'Product category', 'tackquote' ),
			// NOT translatable: syntax typed verbatim (category id: codes).
			"15: @GUESTS, TIER2\n22: @GUESTS",
			array(
				'heading'   => __( 'Hidden from', 'tackquote' ),
				'untouched' => __( 'Visible to everyone.', 'tackquote' ),
				'ticked'    => __( 'Hidden from the ticked buyers. Sub-categories follow.', 'tackquote' ),
			),
			array( Tack_Catalog_Visibility::GUESTS => __( 'Guests and customers in no group', 'tackquote' ) )
		);
	}

	/**
	 * Sanitize the visibility map with the same merge rules as the 1.8.0 grid.
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	public function sanitize_catalog_visibility_map( $value ) {
		return $this->sanitize_group_map_for( Tack_Catalog_Visibility::OPTION_MAP, $value );
	}

	/**
	 * Shipping discount switch.
	 */
	public function field_enable_shipping_discounts() {
		$this->checkbox_default_off(
			Tack_Group_Restrictions::OPTION_DISCOUNTS_ENABLED,
			__( 'Give buyer groups free or discounted shipping.', 'tackquote' )
		);
		$this->help(
			__( 'Only buyers TackQuote places in the group get it.', 'tackquote' ),
			__( 'Applied after the payment and shipping restrictions above. Only buyers TackQuote places in the group get it; guests and unknown buyers pay the normal rate. Shipping tax is reduced in proportion. "Only free methods" keeps the rates that already cost nothing, and changes nothing when there are none.', 'tackquote' )
		);
	}

	/**
	 * Per-group discount rows: mode, percentage, methods.
	 */
	public function field_shipping_discount_map() {
		$this->show_row_when( Tack_Group_Restrictions::OPTION_DISCOUNTS_ENABLED, 'yes' );
		$option       = Tack_Group_Restrictions::OPTION_DISCOUNT_MAP;
		$restrictions = new Tack_Group_Restrictions();
		$rules        = $restrictions->parse_discount_map( (string) get_option( $option, '' ) );
		$known        = $this->known_group_codes();
		$methods      = $this->shipping_method_rows();

		if ( empty( $known ) ) {
			// Nothing to draw: say so, and post nothing, so stored rules are kept.
			echo '<p class="description">' . esc_html__( 'Add your buyer group codes at the top of this tab to set a shipping discount.', 'tackquote' ) . '</p>';
			return;
		}

		printf( '<input type="hidden" name="%s[mode]" value="matrix" />', esc_attr( $option ) );
		echo '<div class="tack-table-wrap"><table class="widefat striped tack-grid"><thead><tr><th scope="col">'
			. esc_html__( 'Buyer group', 'tackquote' ) . '</th><th scope="col">'
			. esc_html__( 'Shipping', 'tackquote' ) . '</th><th scope="col">'
			. esc_html__( 'Methods (none ticked: all)', 'tackquote' ) . '</th></tr></thead><tbody>';

		$modes = array(
			''          => __( 'Normal rates', 'tackquote' ),
			'free'      => __( 'Free', 'tackquote' ),
			'percent'   => __( 'Percentage off', 'tackquote' ),
			'free_only' => __( 'Only free methods', 'tackquote' ),
		);

		foreach ( $known as $code ) {
			$rule = isset( $rules[ $code ] ) ? $rules[ $code ] : array(
				'mode'    => '',
				'percent' => 0,
				'methods' => array(),
			);
			echo '<tr><td><code>' . esc_html( $code ) . '</code>';
			printf( '<input type="hidden" name="%1$s[rendered][]" value="%2$s" />', esc_attr( $option ), esc_attr( $code ) );
			echo '</td><td>';
			printf(
				'<select name="%1$s[rules][%2$s][mode]" aria-label="%3$s">',
				esc_attr( $option ),
				esc_attr( $code ),
				/* translators: %s: buyer group code. */
				esc_attr( sprintf( __( 'Shipping for %s', 'tackquote' ), $code ) )
			);
			foreach ( $modes as $value => $label ) {
				printf(
					'<option value="%1$s" %2$s>%3$s</option>',
					esc_attr( $value ),
					selected( $rule['mode'], $value, false ),
					esc_html( $label )
				);
			}
			echo '</select> ';
			printf(
				'<input type="number" min="0" max="100" step="0.01" name="%1$s[rules][%2$s][percent]" value="%3$s" class="small-text" aria-label="%4$s" /> %%',
				esc_attr( $option ),
				esc_attr( $code ),
				esc_attr( 'percent' === $rule['mode'] ? (string) $rule['percent'] : '' ),
				esc_attr__( 'Percentage off', 'tackquote' )
			);
			echo '</td><td>';
			$offered = array();
			foreach ( $methods as $method ) {
				$offered[] = $method['id'];
				printf(
					'<label class="tack-chip"><input type="checkbox" name="%1$s[rules][%2$s][methods][]" value="%3$s" %4$s /> %5$s</label>',
					esc_attr( $option ),
					esc_attr( $code ),
					esc_attr( $method['id'] ),
					checked( in_array( $method['id'], $rule['methods'], true ), true, false ),
					esc_html( $method['title'] )
				);
			}
			// A method id this store no longer lists (or a full rate id typed by
			// hand) is carried through as a hidden field so a save never drops it.
			foreach ( array_diff( $rule['methods'], $offered ) as $kept ) {
				printf(
					'<input type="hidden" name="%1$s[rules][%2$s][methods][]" value="%3$s" /><code>%4$s</code> ',
					esc_attr( $option ),
					esc_attr( $code ),
					esc_attr( $kept ),
					esc_html( $kept )
				);
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Sanitize the discount rows back into the stored line format.
	 *
	 * Same safety rules as the restriction grid: a string is raw rules; null or
	 * a form that rendered no rows keeps what is stored; only the codes the form
	 * drew are rewritten, every other line (comments, other codes) is kept.
	 *
	 * @param mixed $value Submitted value.
	 * @return string
	 */
	public function sanitize_shipping_discount_map( $value ) {
		$option = Tack_Group_Restrictions::OPTION_DISCOUNT_MAP;
		$stored = (string) get_option( $option, '' );
		if ( is_string( $value ) ) {
			return $this->sanitize_group_map( $value );
		}
		if ( ! is_array( $value ) ) {
			return $stored;
		}

		$rendered = array();
		foreach ( (array) ( isset( $value['rendered'] ) ? $value['rendered'] : array() ) as $code ) {
			$code = self::clean_stored_group_code( $code );
			if ( '' !== $code && ! in_array( $code, $rendered, true ) ) {
				$rendered[] = $code;
			}
		}
		if ( empty( $rendered ) ) {
			return $stored;
		}

		$lines = array();
		$rules = isset( $value['rules'] ) && is_array( $value['rules'] ) ? $value['rules'] : array();
		foreach ( $rendered as $code ) {
			$row  = isset( $rules[ $code ] ) && is_array( $rules[ $code ] ) ? $rules[ $code ] : array();
			$mode = isset( $row['mode'] ) ? (string) $row['mode'] : '';
			if ( ! in_array( $mode, array( 'free', 'percent', 'free_only' ), true ) ) {
				continue;
			}
			$pct = isset( $row['percent'] ) ? (float) $row['percent'] : 0.0;
			if ( 'percent' === $mode && ( $pct <= 0 || $pct > 100 ) ) {
				// A percentage outside 0-100 is not a discount; nothing is saved for it.
				continue;
			}
			$methods = array();
			foreach ( (array) ( isset( $row['methods'] ) ? $row['methods'] : array() ) as $method ) {
				$method = $this->clean_method_id( $method );
				if ( '' !== $method && ! in_array( $method, $methods, true ) ) {
					$methods[] = $method;
				}
			}
			$lines[ $code ] = Tack_Group_Restrictions::format_discount_line(
				$code,
				array(
					'mode'    => $mode,
					'percent' => $pct,
					'methods' => $methods,
				)
			);
		}

		$out = array();
		foreach ( '' === trim( $stored ) ? array() : preg_split( '/\r\n|\r|\n/', $stored ) as $line ) {
			$parts = explode( ':', trim( $line ), 2 );
			$code  = 2 === count( $parts ) && 0 !== strpos( trim( $line ), '#' ) ? strtoupper( trim( $parts[0] ) ) : '';
			if ( '' !== $code && in_array( $code, $rendered, true ) ) {
				continue; // Rewritten below from the form.
			}
			$out[] = $line;
		}
		foreach ( $lines as $line ) {
			$out[] = $line;
		}
		return trim( implode( "\n", $out ) );
	}

	/**
	 * Role mirror switch.
	 */
	public function field_enable_role_mirror() {
		$this->checkbox_default_off(
			Tack_Role_Mirror::OPTION_ENABLED,
			__( 'Give signed-in buyers the extra WordPress role "tackquote_<group code>".', 'tackquote' )
		);
		$this->help(
			__( 'For themes and plugins that check roles. Never affects prices.', 'tackquote' ),
			__( 'For themes and plugins that check roles. The role has only the "read" capability, is removed when the group changes, and never affects prices. Roles you assign yourself are never removed.', 'tackquote' )
		);
	}
}
