<?php
/**
 * The smallest slice of WordPress the TackQuote admin surface actually touches,
 * so the plugin's menu registration and its Plugins-screen "Settings" link can be
 * exercised without a WordPress install, a database, or a web server.
 *
 * These are STUBS and are not a model of WordPress. The one behaviour they
 * reproduce faithfully is the one the bug lived in: `add_menu_page()` records a
 * page slug, and `admin.php` will only render a page whose slug was registered.
 * WordPress answers an UNREGISTERED slug with `wp_die( 'Sorry, you are not
 * allowed to access this page.' )` — the same sentence it uses for a capability
 * failure, which is why this defect reads like a permissions problem and is not
 * one. See `wp-admin/admin.php`, which checks `$_registered_pages`.
 */

$GLOBALS['TACK_REGISTERED_PAGES'] = array();

function admin_url( $path = '' ) { return 'https://shop.example/wp-admin/' . $path; }
function esc_url( $url ) { return $url; }
// WordPress escapes; an identity stub would let an unescaped renderer pass (W1-forms).
function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8', false ); }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8', false ); }
// A test can load a "translation" into TACK_TRANSLATIONS (msgid => msgstr) to prove a
// string really goes through gettext at render time; empty by default, so identity.
$GLOBALS['TACK_TRANSLATIONS'] = array();
function esc_html__( $text, $domain = null ) { return __( $text, $domain ); }
function __( $text, $domain = null ) { return $GLOBALS['TACK_TRANSLATIONS'][ $text ] ?? $text; }
$GLOBALS['TACK_HOOKS']   = array();
$GLOBALS['TACK_REMOVED'] = array();
$GLOBALS['TACK_FILTERS'] = array();

function add_action( $hook, $callback = null, $priority = 10, $args = 1 ) {
	$GLOBALS['TACK_HOOKS'][] = array( 'hook' => $hook, 'priority' => $priority, 'args' => $args );
}
function add_filter( $hook, $callback = null, $priority = 10, $args = 1 ) {
	$GLOBALS['TACK_HOOKS'][] = array( 'hook' => $hook, 'priority' => $priority, 'args' => $args );
	if ( null !== $callback ) {
		$GLOBALS['TACK_FILTERS'][ $hook ][] = $callback;
	}
}

/**
 * Dispatches whatever add_filter() registered, in registration order.
 *
 * Priority is recorded but not honoured: nothing under test registers two
 * callbacks on one filter, and a stub that pretended to order them would be
 * asserting its own invented rule rather than WordPress's.
 */
function apply_filters( $hook, $value ) {
	$args = array_slice( func_get_args(), 2 );
	foreach ( (array) ( $GLOBALS['TACK_FILTERS'][ $hook ] ?? array() ) as $callback ) {
		$value = call_user_func_array( $callback, array_merge( array( $value ), $args ) );
	}
	return $value;
}
function remove_action( $hook, $callback, $priority = 10 ) {
	$GLOBALS['TACK_REMOVED'][] = $hook . '|' . ( is_string( $callback ) ? $callback : 'closure' ) . '|' . $priority;
	return true;
}

/**
 * Current-visitor state the tests drive directly. Real WordPress resolves these
 * from the session; here they are plain globals so a test can say "now this is
 * a signed-out visitor" without a database.
 */
$GLOBALS['TACK_LOGGED_IN'] = false;
$GLOBALS['TACK_CAPS']      = array();
$GLOBALS['TACK_ROLES']     = array();
$GLOBALS['TACK_USER_EMAIL'] = '';

function is_user_logged_in() { return (bool) $GLOBALS['TACK_LOGGED_IN']; }
function wp_get_current_user() {
	$u             = new stdClass();
	$u->roles      = (array) $GLOBALS['TACK_ROLES'];
	// Added for the wholesale-pricing tests: the buyer email is what selects the
	// price book, so a stub without it would let a broken lookup pass.
	$u->user_email = (string) $GLOBALS['TACK_USER_EMAIL'];
	$u->ID         = 1;
	return $u;
}
function checked( $a, $b = true, $echo = true ) { return (string) $a === (string) $b ? "checked='checked'" : ''; }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function sanitize_text_field( $t ) { return trim( (string) $t ); }
function esc_attr__( $t, $d = null ) { return $t; }
function wc_add_notice( $msg, $type = 'success' ) { $GLOBALS['TACK_NOTICES'][] = $msg; }

class TackStubRoles {
	public function get_names() { return array( 'administrator' => 'Administrator', 'customer' => 'Customer', 'wholesale' => 'Wholesale' ); }
}
function wp_roles() { return new TackStubRoles(); }
function register_setting( $group = '', $option = '', $args = array() ) {
	$GLOBALS['TACK_SETTINGS'][] = array( 'group' => $group, 'option' => $option, 'args' => $args );
}

/*
 * These two used to be no-ops, which meant the page's STRUCTURE — which
 * settings are grouped together and in what order a merchant reads them —
 * was the one thing about the settings screen no test could see. Recording
 * the calls is what lets `settings-page-test.php` assert that no field is
 * orphaned outside a section.
 */
function add_settings_section( $id = '', $title = '', $cb = null, $page = '' ) {
	$GLOBALS['TACK_SECTIONS'][] = array( 'id' => $id, 'title' => $title, 'callback' => $cb, 'page' => $page );
}
function add_settings_field( $id = '', $title = '', $cb = null, $page = '', $section = 'default', $args = array() ) {
	$GLOBALS['TACK_FIELDS'][] = array( 'id' => $id, 'title' => $title, 'callback' => $cb, 'page' => $page, 'section' => $section, 'args' => $args );
}

/*
 * Rendering side of the Settings API, enough to draw one tab the way
 * wp-admin/includes/template.php does: each section of the page in registration
 * order, its intro callback, then its fields in a form-table.
 */
function settings_fields( $group ) {
	echo '<input type="hidden" name="option_page" value="' . esc_attr( $group ) . '" />';
}
function do_settings_sections( $page ) {
	foreach ( $GLOBALS['TACK_SECTIONS'] as $section ) {
		if ( $section['page'] !== $page ) {
			continue;
		}
		echo '<h2>' . esc_html( $section['title'] ) . '</h2>';
		call_user_func( $section['callback'], $section );
		echo '<table class="form-table" role="presentation">';
		foreach ( $GLOBALS['TACK_FIELDS'] as $field ) {
			if ( $field['page'] === $page && $field['section'] === $section['id'] ) {
				echo '<tr><th scope="row">' . esc_html( $field['title'] ) . '</th><td>';
				call_user_func( $field['callback'], $field['args'] );
				echo '</td></tr>';
			}
		}
		echo '</table>';
	}
}
function submit_button( $text = null, $type = 'primary', $name = 'submit', $wrap = true ) {
	echo '<input type="submit" name="' . esc_attr( $name ) . '" class="button ' . esc_attr( $type ) . '" value="' . esc_attr( (string) $text ) . '" />';
}
function settings_errors() {
	echo '<!-- settings_errors -->';
}
function esc_html_e( $text, $domain = null ) {
	echo esc_html( __( $text, $domain ) );
}
function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false ) {
	$GLOBALS['TACK_ENQUEUED_STYLES'][ $handle ] = $src;
}
function human_time_diff( $from, $to = 0 ) {
	return max( 1, (int) round( abs( $to - $from ) / 60 ) ) . ' mins';
}
function plugins_url( $path = '', $plugin = '' ) {
	return 'https://shop.example/wp-content/plugins/tackquote/' . ltrim( $path, '/' );
}
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}
/** wp_die() ends the request; the stub throws so a test can observe it. */
class Tack_Test_Wp_Die extends Exception {}
function wp_die( $message = '' ) {
	throw new Tack_Test_Wp_Die( (string) $message );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

/** Plural form. The stub ignores locale rules; only the branch matters. */
function _n( $single, $plural, $number, $domain = null ) {
	return 1 === (int) $number ? $single : $plural;
}

/** Reset everything the Settings API stubs recorded. */
function tack_test_reset_settings_api() {
	$GLOBALS['TACK_SETTINGS'] = array();
	$GLOBALS['TACK_SECTIONS'] = array();
	$GLOBALS['TACK_FIELDS']   = array();
}
tack_test_reset_settings_api();
function plugin_basename( $file ) { return 'tackquote/tackquote.php'; }
$GLOBALS['TACK_OPTIONS'] = array();
function get_option( $key, $default = false ) {
	return array_key_exists( $key, (array) $GLOBALS['TACK_OPTIONS'] ) ? $GLOBALS['TACK_OPTIONS'][ $key ] : $default;
}
function update_option( $key, $value ) { $GLOBALS['TACK_OPTIONS'][ $key ] = $value; return true; }
function current_user_can( $cap ) { return in_array( $cap, (array) $GLOBALS['TACK_CAPS'], true ); }

function add_menu_page( $page_title, $menu_title, $capability, $menu_slug, $callback = null, $icon = null, $position = null ) {
	$GLOBALS['TACK_REGISTERED_PAGES'][ $menu_slug ] = $capability;
	return 'toplevel_page_' . $menu_slug;
}

function add_submenu_page( $parent, $page_title, $menu_title, $capability, $menu_slug, $callback = null ) {
	$GLOBALS['TACK_REGISTERED_PAGES'][ $menu_slug ] = $capability;
	return $parent . '_page_' . $menu_slug;
}

/**
 * `home_url()` scopes the idempotency key to one site. A fixed value is enough
 * here; the key is asserted for STABILITY, not for its contents.
 */
function home_url( $path = '' ) { return 'https://shop.example' . $path; }
function wp_json_encode( $data, $options = 0, $depth = 512 ) { return json_encode( $data, $options, $depth ); }
function wp_strip_all_tags( $text, $remove_breaks = false ) { return trim( strip_tags( (string) $text ) ); }


// ── Stubs added for Tack_Wholesale_Pricing ───────────────────────────────────
//
// Guarded with function_exists/class_exists so this file stays safe to include
// alongside any other harness, and so a real WordPress bootstrap would win.

if ( ! class_exists( 'WP_Error' ) ) {
	/** Minimal stand-in for WordPress's error object. */
	class WP_Error {
		/** @var string */
		private $code;
		/** @var string */
		private $message;
		/** @var mixed */
		private $data;

		/**
		 * Constructor. Same three parameters as core's `WP_Error::__construct( $code, $message, $data )`.
		 *
		 * @param string $code    Error code.
		 * @param string $message Error message.
		 * @param mixed  $data    Error data.
		 */
		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		/**
		 * Core returns the data for the first code, or null when none was added.
		 *
		 * @return mixed
		 */
		public function get_error_data() {
			return '' === $this->data ? null : $this->data;
		}

		/** @return string */
		public function get_error_message() {
			return $this->message;
		}

		/** @return string */
		public function get_error_code() {
			return $this->code;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * @param mixed $thing Value to test.
	 * @return bool
	 */
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'is_admin' ) ) {
	/** @return bool */
	function is_admin() {
		return (bool) $GLOBALS['TACK_IS_ADMIN'];
	}
}
$GLOBALS['TACK_IS_ADMIN'] = false;

if ( ! function_exists( 'wp_doing_ajax' ) ) {
	/** @return bool */
	function wp_doing_ajax() {
		return false;
	}
}

if ( ! function_exists( 'wp_kses_post' ) ) {
	/**
	 * @param string $html Markup.
	 * @return string
	 */
	function wp_kses_post( $html ) {
		return $html;
	}
}

if ( ! function_exists( 'wc_price' ) ) {
	/**
	 * @param float $amount Amount.
	 * @return string
	 */
	function wc_price( $amount, $args = array() ) {
		$symbol = is_array( $args ) && ! empty( $args['currency'] ) ? $args['currency'] . ' ' : '$';
		return '<span class="amount">' . $symbol . number_format( (float) $amount, 2 ) . '</span>';
	}
}

/** What the plugin logged, as ( level, message, context ) rows. */
$GLOBALS['TACK_LOGGED'] = array();
if ( ! class_exists( 'TackStubLogger' ) ) {
	/**
	 * The four WC_Logger methods the plugin calls. Recording, not printing: production
	 * code reaches `wc_get_logger()->error()` on a failed push, and a stub returning null
	 * there would turn a logged failure into a fatal error that only the harness can see.
	 */
	class TackStubLogger {
		public function error( $m, $c = array() ) { $GLOBALS['TACK_LOGGED'][] = array( 'error', $m, $c ); }
		public function warning( $m, $c = array() ) { $GLOBALS['TACK_LOGGED'][] = array( 'warning', $m, $c ); }
		public function info( $m, $c = array() ) { $GLOBALS['TACK_LOGGED'][] = array( 'info', $m, $c ); }
		public function debug( $m, $c = array() ) { $GLOBALS['TACK_LOGGED'][] = array( 'debug', $m, $c ); }
	}
}
if ( ! function_exists( 'wc_get_logger' ) ) {
	/** @return TackStubLogger */
	function wc_get_logger() {
		return new TackStubLogger();
	}
}

/**
 * Set an option from a test.
 *
 * @param string $key   Option name.
 * @param mixed  $value Value.
 */
function tack_test_set_option( $key, $value ) {
	$GLOBALS['TACK_OPTIONS'][ $key ] = $value;
}

/**
 * Set the signed-in state from a test.
 *
 * @param bool   $logged_in Whether a user is signed in.
 * @param string $email     That user's email.
 */
function tack_test_set_logged_in( $logged_in, $email ) {
	$GLOBALS['TACK_LOGGED_IN']  = (bool) $logged_in;
	$GLOBALS['TACK_USER_EMAIL'] = (string) $email;
}


// ── Tax stubs, for the net -> store-basis conversion ────────────────────────
$GLOBALS['TACK_PRICES_INCLUDE_TAX'] = false;

if ( ! function_exists( 'wc_prices_include_tax' ) ) {
	/** @return bool */
	function wc_prices_include_tax() {
		return (bool) $GLOBALS['TACK_PRICES_INCLUDE_TAX'];
	}
}

// wc_get_price_including_tax(), WC_Tax and the cart maths: tests/wc-tax-stubs.php,
// transcribed from WooCommerce 11.2.1. The stub that stood here grossed a price up
// on an inclusive store, which WooCommerce does not do: it encoded the bug.

/**
 * Switch the store's tax basis from a test.
 *
 * @param bool $include Whether entered prices include tax.
 */
function tack_test_set_prices_include_tax( $include ) {
	$GLOBALS['TACK_PRICES_INCLUDE_TAX'] = (bool) $include;
}


// ── Cart + notice stubs, for Tack_B2B_Notices ───────────────────────────────
$GLOBALS['TACK_CART_LINES'] = array();

if ( ! class_exists( 'Tack_Stub_WC' ) ) {
	/** Stands in for the WC() singleton's cart. */
	class Tack_Stub_WC {
		/** @var object|null */
		public $cart;

		/** @var object|null */
		public $customer;

		/** @var object|null */
		public $session;

		/** @return Tack_Stub_Payment_Gateways|null */
		public function payment_gateways() {
			return null === $GLOBALS['TACK_GATEWAYS'] ? null : new Tack_Stub_Payment_Gateways();
		}

		/** @return Tack_Stub_Shipping|null */
		public function shipping() {
			return null === $GLOBALS['TACK_SHIPPING'] ? null : new Tack_Stub_Shipping();
		}
	}
}

/*
 * ── The gateway / shipping-method lists the settings grid is built from ─────
 *
 * Shaped after WooCommerce 11.1.0, and only where the plugin actually touches
 * it: `WC()->payment_gateways()->payment_gateways()` returns gateways keyed by
 * `$gateway->id` (class-wc-payment-gateways.php), and
 * `WC()->shipping()->get_shipping_methods()` returns methods keyed by
 * `$method->id` (class-wc-shipping.php, register_shipping_method()).
 *
 * Setting either global to null stands for "WooCommerce told us nothing" —
 * the case the grid has to degrade on instead of rendering empty and wiping
 * the merchant's rules.
 */
$GLOBALS['TACK_GATEWAYS'] = array();
$GLOBALS['TACK_SHIPPING'] = array();

if ( ! class_exists( 'Tack_Stub_Method' ) ) {
	/** A payment gateway or shipping method, as far as the settings page cares. */
	class Tack_Stub_Method {
		/** @var string */
		public $id;
		/** @var string */
		private $method_title;

		/**
		 * @param string $id    Id.
		 * @param string $title Admin-facing title.
		 */
		public function __construct( $id, $title ) {
			$this->id           = $id;
			$this->method_title = $title;
		}

		/** @return string */
		public function get_method_title() {
			return $this->method_title;
		}

		/** @return string */
		public function get_title() {
			return $this->method_title;
		}
	}
}

if ( ! class_exists( 'Tack_Stub_Payment_Gateways' ) ) {
	/** Stands in for WC_Payment_Gateways. */
	class Tack_Stub_Payment_Gateways {
		/** @return array */
		public function payment_gateways() {
			$out = array();
			foreach ( (array) $GLOBALS['TACK_GATEWAYS'] as $id => $title ) {
				$out[ $id ] = new Tack_Stub_Method( $id, $title );
			}
			return $out;
		}
	}
}

if ( ! class_exists( 'Tack_Stub_Shipping' ) ) {
	/** Stands in for WC_Shipping. */
	class Tack_Stub_Shipping {
		/** @return array */
		public function get_shipping_methods() {
			$out = array();
			foreach ( (array) $GLOBALS['TACK_SHIPPING'] as $id => $title ) {
				$out[ $id ] = new Tack_Stub_Method( $id, $title );
			}
			return $out;
		}
	}
}

/**
 * Set the store's payment gateways. Pass null for "WooCommerce reported none".
 *
 * @param array|null $gateways id => admin title.
 */
function tack_test_set_gateways( $gateways ) {
	$GLOBALS['TACK_GATEWAYS'] = $gateways;
}

/**
 * Set the store's shipping methods. Pass null for "WooCommerce reported none".
 *
 * @param array|null $methods id => admin title.
 */
function tack_test_set_shipping_methods( $methods ) {
	$GLOBALS['TACK_SHIPPING'] = $methods;
}

if ( ! function_exists( 'WC' ) ) {
	/**
	 * @return Tack_Stub_WC
	 */
	function WC() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
		$wc       = new Tack_Stub_WC();
		$wc->cart = new Tack_Stub_Cart( $GLOBALS['TACK_CART_LINES'] );
		// W1-forms: the customer and session the tax-exemption tests read.
		$wc->customer = isset( $GLOBALS['TACK_WC_CUSTOMER'] ) ? $GLOBALS['TACK_WC_CUSTOMER'] : null;
		$wc->session  = isset( $GLOBALS['TACK_WC_SESSION'] ) ? $GLOBALS['TACK_WC_SESSION'] : null;
		return $wc;
	}
}

if ( ! class_exists( 'Tack_Stub_Cart' ) ) {
	/** A cart of stub lines. */
	class Tack_Stub_Cart {
		/** @var array */
		private $lines;

		/**
		 * @param array $lines Lines.
		 */
		public function __construct( $lines ) {
			$this->lines = $lines;
		}

		/** @return array */
		public function get_cart() {
			return $this->lines;
		}

		/**
		 * Records the removal the way `Tack_Catalog_Mode::check_cart()` relies on it:
		 * the line is gone from THIS cart and from the shared fixture.
		 *
		 * @param string $key Cart item key.
		 * @return bool
		 */
		public function remove_cart_item( $key ) {
			unset( $this->lines[ $key ], $GLOBALS['TACK_CART_LINES'][ $key ] );
			$GLOBALS['TACK_CART_REMOVED'][] = $key;
			return true;
		}
	}
}

if ( ! class_exists( 'Tack_Stub_Cart_Product' ) ) {
	/** A product on a stub cart line. */
	class Tack_Stub_Cart_Product {
		/** @var string */
		private $sku;
		/** @var string */
		private $name;

		/**
		 * @param string $sku  SKU.
		 * @param string $name Name.
		 */
		public function __construct( $sku, $name ) {
			$this->sku  = $sku;
			$this->name = $name;
		}

		/** @return string */
		public function get_sku() {
			return $this->sku;
		}

		/** @return string */
		public function get_name() {
			return $this->name;
		}
	}
}

/**
 * Replace the cart contents from a test.
 *
 * @param array $lines Array of array{sku:string,qty:int,name:string}.
 */
function tack_test_set_cart( $lines ) {
	$out = array();
	foreach ( $lines as $i => $line ) {
		$out[ 'line' . $i ] = array(
			'data'     => new Tack_Stub_Cart_Product( $line['sku'], isset( $line['name'] ) ? $line['name'] : $line['sku'] ),
			'quantity' => (int) $line['qty'],
		);
	}
	$GLOBALS['TACK_CART_LINES'] = $out;
}

/** Clear collected notices. */
function tack_test_reset_notices() {
	$GLOBALS['TACK_NOTICES'] = array();
}

/**
 * Notices collected since the last reset.
 *
 * @return array
 */
function tack_test_notices() {
	return (array) $GLOBALS['TACK_NOTICES'];
}


// ── Filter-return helpers, for testing `apply_filters` defaults ─────────────

if ( ! function_exists( 'esc_textarea' ) ) {
	/**
	 * @param string $t Text.
	 * @return string
	 */
	function esc_textarea( $t ) {
		return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	/**
	 * @param string $t Text.
	 * @return string
	 */
	function wp_strip_all_tags( $t ) {
		return strip_tags( (string) $t );
	}
}

/**
 * Force a filter to return a fixed value.
 *
 * @param string $hook  Filter name.
 * @param mixed  $value Value to return.
 */
function tack_test_add_filter_return( $hook, $value ) {
	$GLOBALS['TACK_FILTERS'][ $hook ][] = function () use ( $value ) {
		return $value;
	};
}

/** Remove every forced filter return. */
function tack_test_clear_filter_returns() {
	$GLOBALS['TACK_FILTERS'] = array();
}


// ── User-meta stubs, for the email-trust guard ──────────────────────────────
$GLOBALS['TACK_USER_META'] = array();

if ( ! function_exists( 'get_user_meta' ) ) {
	/**
	 * @param int    $user_id User id.
	 * @param string $key     Meta key.
	 * @param bool   $single  Single value.
	 * @return mixed
	 */
	function get_user_meta( $user_id, $key = '', $single = false ) {
		unset( $single );
		return $GLOBALS['TACK_USER_META'][ $user_id ][ $key ] ?? '';
	}
}

if ( ! function_exists( 'update_user_meta' ) ) {
	/**
	 * @param int    $user_id User id.
	 * @param string $key     Meta key.
	 * @param mixed  $value   Value.
	 * @return bool
	 */
	function update_user_meta( $user_id, $key, $value ) {
		$GLOBALS['TACK_USER_META'][ $user_id ][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_user_meta' ) ) {
	/**
	 * @param int    $user_id User id.
	 * @param string $key     Meta key.
	 * @return bool
	 */
	function delete_user_meta( $user_id, $key ) {
		unset( $GLOBALS['TACK_USER_META'][ $user_id ][ $key ] );
		return true;
	}
}

if ( ! function_exists( 'get_userdata' ) ) {
	/**
	 * @param int $user_id User id.
	 * @return object|false
	 */
	function get_userdata( $user_id ) {
		if ( (int) $user_id !== 1 ) {
			return false;
		}
		$u             = new stdClass();
		$u->ID         = 1;
		$u->user_email = (string) $GLOBALS['TACK_USER_EMAIL'];
		return $u;
	}
}

/** Clear all stubbed user meta. */
function tack_test_reset_user_meta() {
	$GLOBALS['TACK_USER_META'] = array();
}


// ── Stubs added for the order-sync gate (terminal 401/403, 429 back-off) ─────
//
// The HTTP functions below replay ONE scripted response and count calls, so a test can
// assert that a blocked push made no request at all. Shapes follow core: a response is an
// array with `response.code`, `body` and `headers`; `wp_remote_retrieve_header()` returns
// '' for an absent header (developer.wordpress.org/reference/functions/wp_remote_retrieve_header/).

$GLOBALS['TACK_HTTP_CALLS']    = 0;
$GLOBALS['TACK_HTTP_RESPONSE'] = null;

if ( ! function_exists( 'delete_option' ) ) {
	/**
	 * @param string $key Option name.
	 * @return bool
	 */
	function delete_option( $key ) {
		unset( $GLOBALS['TACK_OPTIONS'][ $key ] );
		return true;
	}
}

/**
 * Script the next HTTP response.
 *
 * @param int         $code    Status code.
 * @param string      $body    Raw body.
 * @param array       $headers Lower-case header name => value.
 */
function tack_test_set_http_response( $code, $body, $headers = array() ) {
	$GLOBALS['TACK_HTTP_RESPONSE'] = array(
		'response' => array( 'code' => $code ),
		'body'     => $body,
		'headers'  => $headers,
	);
}

if ( ! function_exists( 'wp_remote_request' ) ) {
	/**
	 * @param string $url  URL.
	 * @param array  $args Args.
	 * @return array|WP_Error
	 */
	function wp_remote_request( $url, $args = array() ) {
		$GLOBALS['TACK_HTTP_CALLS']++;
		if ( function_exists( 'tack_test_record_http' ) ) {
			tack_test_record_http( $url, $args );
		}
		// A test may answer per URL (1.10.0 attachments: ping, uploads, quote request).
		if ( isset( $GLOBALS['TACK_HTTP_RESPONDER'] ) && is_callable( $GLOBALS['TACK_HTTP_RESPONDER'] ) ) {
			return call_user_func( $GLOBALS['TACK_HTTP_RESPONDER'], $url, $args );
		}
		return $GLOBALS['TACK_HTTP_RESPONSE'];
	}
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	/** @param array $r Response. @return int|string */
	function wp_remote_retrieve_response_code( $r ) {
		return is_array( $r ) && isset( $r['response']['code'] ) ? $r['response']['code'] : '';
	}
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	/** @param array $r Response. @return string */
	function wp_remote_retrieve_body( $r ) {
		return is_array( $r ) && isset( $r['body'] ) ? $r['body'] : '';
	}
}
if ( ! function_exists( 'wp_remote_retrieve_header' ) ) {
	/** @param array $r Response. @param string $h Header. @return string */
	function wp_remote_retrieve_header( $r, $h ) {
		return is_array( $r ) && isset( $r['headers'][ strtolower( $h ) ] ) ? $r['headers'][ strtolower( $h ) ] : '';
	}
}

// Recorded so the gate tests can see the unblock event and the re-queue it drives.
$GLOBALS['TACK_DONE_ACTIONS'] = array();
$GLOBALS['TACK_AS_ENQUEUED']  = array();
$GLOBALS['TACK_WC_ORDERS_Q']  = array();
$GLOBALS['TACK_WC_ORDER_IDS'] = array();

if ( ! function_exists( 'do_action' ) ) {
	/**
	 * @param string $hook Hook.
	 * @param mixed  ...$args Args.
	 */
	function do_action( $hook, ...$args ) {
		$GLOBALS['TACK_DONE_ACTIONS'][] = array( $hook, $args );
	}
}
if ( ! function_exists( 'as_enqueue_async_action' ) ) {
	/**
	 * Action Scheduler's signature: ( $hook, $args, $group, $unique, $priority ).
	 *
	 * @return int
	 */
	function as_enqueue_async_action( $hook, $args = array(), $group = '', $unique = false, $priority = 10 ) {
		$GLOBALS['TACK_AS_ENQUEUED'][] = array( $hook, $args, $group, $unique );
		return count( $GLOBALS['TACK_AS_ENQUEUED'] );
	}
}
// Recorded so the back-off tests can see WHEN a throttled order is put back.
$GLOBALS['TACK_AS_SCHEDULED'] = array();
if ( ! function_exists( 'as_schedule_single_action' ) ) {
	/**
	 * Action Scheduler's signature: ( $timestamp, $hook, $args, $group, $unique, $priority )
	 * (docs/api.md, read through Context7 /woocommerce/action-scheduler).
	 *
	 * @return int
	 */
	function as_schedule_single_action( $timestamp, $hook, $args = array(), $group = '', $unique = false, $priority = 10 ) {
		$GLOBALS['TACK_AS_SCHEDULED'][] = array( $timestamp, $hook, $args, $group, $unique );
		return count( $GLOBALS['TACK_AS_SCHEDULED'] );
	}
}
if ( ! function_exists( 'absint' ) ) {
	/**
	 * Core: `abs( (int) $maybeint )`.
	 *
	 * @param mixed $maybeint Value.
	 * @return int
	 */
	function absint( $maybeint ) {
		return abs( (int) $maybeint );
	}
}
if ( ! function_exists( 'wp_rand' ) ) {
	/**
	 * Deterministic in tests: always the LOWER bound, so a scheduled time can be compared
	 * exactly. Core's wp_rand( $min, $max ) returns an int in [$min, $max].
	 *
	 * @param int $min Lower bound.
	 * @param int $max Upper bound.
	 * @return int
	 */
	function wp_rand( $min = 0, $max = 0 ) {
		return (int) $min;
	}
}
if ( ! function_exists( 'wc_get_orders' ) ) {
	/**
	 * @param array $args Query.
	 * @return array Scripted ids.
	 */
	function wc_get_orders( $args ) {
		$GLOBALS['TACK_WC_ORDERS_Q'][] = $args;
		return $GLOBALS['TACK_WC_ORDER_IDS'];
	}
}
if ( ! function_exists( 'wc_get_order' ) ) {
	/**
	 * @param int $id Order id.
	 * @return WC_Order|false
	 */
	function wc_get_order( $id ) {
		return class_exists( 'WC_Order' ) ? new WC_Order( array( 'id' => (int) $id ) ) : false;
	}
}


// ── Stubs added for Tack_Storefront_Forms (1.10.0) ────────────────────────────
//
// Same guard pattern as above. The HTTP stub now also records the URL and args of
// the last request, so a test can assert on what left the store (headers, body).

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'EP_ROOT' ) ) {
	define( 'EP_ROOT', 64 );
}
if ( ! defined( 'EP_PAGES' ) ) {
	define( 'EP_PAGES', 4096 );
}

$GLOBALS['TACK_HTTP_LAST_URL']  = '';
$GLOBALS['TACK_HTTP_LAST_ARGS'] = array();
$GLOBALS['TACK_HTTP_REQUESTS']  = array();

/**
 * Wrap the HTTP stub so every request is recorded. Declared as a filterable hook
 * on the existing stub by re-pointing the stub through this recorder.
 *
 * @param string $url  URL.
 * @param array  $args Args.
 */
function tack_test_record_http( $url, $args ) {
	$GLOBALS['TACK_HTTP_LAST_URL']  = $url;
	$GLOBALS['TACK_HTTP_LAST_ARGS'] = $args;
	$GLOBALS['TACK_HTTP_REQUESTS'][] = array(
		'url'  => $url,
		'args' => $args,
	);
}

$GLOBALS['TACK_TRANSIENTS'] = array();
if ( ! function_exists( 'get_transient' ) ) {
	/** @param string $key Key. @return mixed */
	function get_transient( $key ) {
		return isset( $GLOBALS['TACK_TRANSIENTS'][ $key ] ) ? $GLOBALS['TACK_TRANSIENTS'][ $key ] : false;
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	/** @param string $key Key. @param mixed $value Value. @param int $ttl TTL. @return bool */
	function set_transient( $key, $value, $ttl = 0 ) {
		$GLOBALS['TACK_TRANSIENTS'][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	/** @param string $key Key. @return bool */
	function delete_transient( $key ) {
		unset( $GLOBALS['TACK_TRANSIENTS'][ $key ] );
		return true;
	}
}
/** Forget every transient. */
function tack_test_reset_transients() {
	$GLOBALS['TACK_TRANSIENTS'] = array();
}

$GLOBALS['TACK_SHORTCODES'] = array();
if ( ! function_exists( 'add_shortcode' ) ) {
	/** @param string $tag Tag. @param callable $cb Callback. */
	function add_shortcode( $tag, $cb ) {
		$GLOBALS['TACK_SHORTCODES'][ $tag ] = $cb;
	}
}
if ( ! function_exists( 'shortcode_atts' ) ) {
	/** @param array $pairs Defaults. @param array|string $atts Given. @param string $tag Tag. @return array */
	function shortcode_atts( $pairs, $atts, $tag = '' ) {
		$atts = (array) $atts;
		$out  = array();
		foreach ( $pairs as $name => $default ) {
			$out[ $name ] = array_key_exists( $name, $atts ) ? $atts[ $name ] : $default;
		}
		return $out;
	}
}

if ( ! function_exists( 'wp_kses' ) ) {
	/**
	 * Keeps only the allowed TAGS (attributes are not filtered here). Enough to prove
	 * that a tag outside the allowlist — a <script> — is removed from the output.
	 *
	 * @param string $html    Markup.
	 * @param array  $allowed Allowed tags => attributes.
	 * @return string
	 */
	function wp_kses( $html, $allowed, $protocols = array() ) {
		return strip_tags( (string) $html, '<' . implode( '><', array_keys( (array) $allowed ) ) . '>' );
	}
}

$GLOBALS['TACK_NONCE_VALID'] = true;
if ( ! function_exists( 'wp_create_nonce' ) ) {
	/** @param string $action Action. @return string */
	function wp_create_nonce( $action = -1 ) {
		return 'nonce-' . md5( (string) $action );
	}
}
if ( ! function_exists( 'wp_verify_nonce' ) ) {
	/** @param string $nonce Nonce. @param string $action Action. @return int|false */
	function wp_verify_nonce( $nonce, $action = -1 ) {
		return $GLOBALS['TACK_NONCE_VALID'] && $nonce === wp_create_nonce( $action ) ? 1 : false;
	}
}
if ( ! function_exists( 'wp_nonce_field' ) ) {
	/** @return string */
	function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $display = true ) {
		$field = '<input type="hidden" name="' . $name . '" value="' . wp_create_nonce( $action ) . '" />';
		if ( $display ) {
			echo $field;
		}
		return $field;
	}
}

if ( ! function_exists( 'wp_login_url' ) ) {
	/** @param string $redirect Redirect. @return string */
	function wp_login_url( $redirect = '' ) {
		return 'https://shop.example/wp-login.php' . ( '' !== $redirect ? '?redirect_to=' . rawurlencode( $redirect ) : '' );
	}
}
if ( ! function_exists( 'wc_get_page_permalink' ) ) {
	/** @param string $page Page. @return string */
	function wc_get_page_permalink( $page ) {
		return 'https://shop.example/' . $page . '/';
	}
}
if ( ! function_exists( 'wc_get_account_endpoint_url' ) ) {
	/** @param string $endpoint Endpoint. @return string */
	function wc_get_account_endpoint_url( $endpoint ) {
		return 'https://shop.example/my-account/' . $endpoint . '/';
	}
}
$GLOBALS['TACK_REWRITE_ENDPOINTS'] = array();
$GLOBALS['TACK_REWRITE_FLUSHES']   = 0;
if ( ! function_exists( 'add_rewrite_endpoint' ) ) {
	/** @param string $name Name. @param int $places Mask. @param string|bool $query_var Query var. */
	function add_rewrite_endpoint( $name, $places, $query_var = true ) {
		$GLOBALS['TACK_REWRITE_ENDPOINTS'][ $name ] = $places;
	}
}
if ( ! function_exists( 'flush_rewrite_rules' ) ) {
	/** @param bool $hard Hard flush. */
	function flush_rewrite_rules( $hard = true ) {
		$GLOBALS['TACK_REWRITE_FLUSHES']++;
	}
}
if ( ! function_exists( 'sanitize_email' ) ) {
	/** @param string $email Email. @return string */
	function sanitize_email( $email ) {
		return trim( (string) $email );
	}
}
if ( ! function_exists( 'is_email' ) ) {
	/** @param string $email Email. @return string|false */
	function is_email( $email ) {
		return (bool) preg_match( '/^[^\s@]+@[^\s@]+\.[^\s@]+$/', (string) $email ) ? $email : false;
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	/** @param mixed $value Value. @return mixed */
	function wp_unslash( $value ) {
		return $value;
	}
}
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	/** @param string $t Text. @return string */
	function sanitize_textarea_field( $t ) {
		return trim( strip_tags( (string) $t ) );
	}
}
if ( ! function_exists( 'map_deep' ) ) {
	/** @param mixed $value Value. @param callable $callback Callback. @return mixed */
	function map_deep( $value, $callback ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				$value[ $k ] = map_deep( $v, $callback );
			}
			return $value;
		}
		return call_user_func( $callback, $value );
	}
}
if ( ! function_exists( 'wp_validate_redirect' ) ) {
	/** @param string $location URL. @param string $fallback Fallback. @return string */
	function wp_validate_redirect( $location, $fallback = '' ) {
		return 0 === strpos( (string) $location, 'https://shop.example/' ) ? $location : $fallback;
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	/** @param string $url URL. @return string */
	function esc_url_raw( $url ) {
		return (string) $url;
	}
}
if ( ! function_exists( 'add_query_arg' ) ) {
	/** @return string */
	function add_query_arg( ...$args ) {
		if ( is_array( $args[0] ) ) {
			$url   = $args[1];
			$pairs = $args[0];
		} else {
			$url   = $args[2];
			$pairs = array( $args[0] => $args[1] );
		}
		return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $pairs );
	}
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	/** @return int */
	function get_current_user_id() {
		return is_user_logged_in() ? 1 : 0;
	}
}
if ( ! function_exists( 'wp_generate_password' ) ) {
	/** @return string */
	function wp_generate_password( $length = 12, $special = true, $extra = false ) {
		return substr( str_repeat( 'abc123xyz789', 4 ), 0, (int) $length );
	}
}
$GLOBALS['TACK_ENQUEUED'] = array();
if ( ! function_exists( 'wp_enqueue_script' ) ) {
	/** @param string $handle Handle. */
	function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $args = false ) {
		$GLOBALS['TACK_ENQUEUED'][] = $handle;
	}
}
if ( ! function_exists( 'get_permalink' ) ) {
	/** @return string */
	function get_permalink( $post = 0 ) {
		return 'https://shop.example/apply/';
	}
}

// ── W1-forms: product-page context, store currency, WC customer + session ────

$GLOBALS['TACK_IS_PRODUCT']      = false;
$GLOBALS['TACK_QUERIED_OBJECT']  = 0;
$GLOBALS['TACK_STORE_CURRENCY']  = 'USD';
$GLOBALS['TACK_WC_CUSTOMER']     = null;
$GLOBALS['TACK_WC_SESSION']      = null;

if ( ! function_exists( 'is_product' ) ) {
	/** @return bool */
	function is_product() {
		return (bool) $GLOBALS['TACK_IS_PRODUCT'];
	}
}
if ( ! function_exists( 'get_queried_object_id' ) ) {
	/** @return int */
	function get_queried_object_id() {
		return (int) $GLOBALS['TACK_QUERIED_OBJECT'];
	}
}
if ( ! function_exists( 'get_woocommerce_currency' ) ) {
	/** @return string */
	function get_woocommerce_currency() {
		return (string) $GLOBALS['TACK_STORE_CURRENCY'];
	}
}

/** A WC_Customer recording set_is_vat_exempt() calls. */
class Tack_Stub_Customer {
	/** @var bool */
	public $vat_exempt = false;
	/** @var array<int,bool> */
	public $calls = array();
	/** @var int */
	public $saves = 0;

	/** @param bool $exempt Exempt? */
	public function set_is_vat_exempt( $exempt ) {
		$this->vat_exempt = (bool) $exempt;
		$this->calls[]    = (bool) $exempt;
	}

	/** @return bool */
	public function get_is_vat_exempt() {
		return $this->vat_exempt;
	}

	/** Persisting the customer: the tests assert this never happens. */
	public function save() {
		++$this->saves;
	}
}

/** A WC_Session holding values in memory. */
class Tack_Stub_Session {
	/** @var array */
	public $data = array();

	/**
	 * @param string $key Key.
	 * @return mixed
	 */
	public function get( $key ) {
		return isset( $this->data[ $key ] ) ? $this->data[ $key ] : null;
	}

	/**
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 */
	public function set( $key, $value ) {
		$this->data[ $key ] = $value;
	}
}

// ── Stubs added for the 1.10.0 storefront parity features ────────────────────
$GLOBALS['TACK_CART_REMOVED'] = array();

if ( ! function_exists( 'selected' ) ) {
	/**
	 * Core's `selected( $selected, $current, $echo )`.
	 *
	 * @param mixed $selected Selected value.
	 * @param mixed $current  Current value.
	 * @param bool  $echo     Echo.
	 * @return string
	 */
	function selected( $selected, $current = true, $echo = true ) {
		return (string) $selected === (string) $current ? "selected='selected'" : '';
	}
}

// ═══ BEGIN W2-net-terms stubs (net-terms gateway, Blocks integration, PO field) ═══
//
// Shaped after the members the plugin calls, and nothing more. WC_Payment_Gateway
// follows WooCommerce's WC_Settings_API: settings live in the option
// `woocommerce_{id}_settings`, init_settings() fills defaults from form_fields and
// sets `enabled`, and the base is_available() is `'yes' === $this->enabled` (plus a
// max_amount check this plugin does not use). get_order_total() reads a scripted
// cart total. These doubles prove the plugin's decisions, not WooCommerce's.

$GLOBALS['TACK_NT_CART_TOTAL']        = 0.0;
$GLOBALS['TACK_REGISTERED_SCRIPTS']   = array();
$GLOBALS['TACK_ADDITIONAL_FIELDS']    = array();
$GLOBALS['TACK_FORM_FIELDS_RENDERED'] = array();

if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
	/** Stand-in for WooCommerce's gateway base (WC_Settings_API). */
	class WC_Payment_Gateway {
		/** @var string */
		public $id = '';
		/** @var string */
		public $enabled = 'no';
		/** @var string */
		public $title = '';
		/** @var string */
		public $description = '';
		/** @var string */
		public $method_title = '';
		/** @var string */
		public $method_description = '';
		/** @var bool */
		public $has_fields = false;
		/** @var array */
		public $supports = array();
		/** @var array */
		public $form_fields = array();
		/** @var array */
		public $settings = array();
		/** @var float */
		public $max_amount = 0;

		/** Load settings from the option, defaults from form_fields. */
		public function init_settings() {
			$stored = get_option( 'woocommerce_' . $this->id . '_settings', null );
			$stored = is_array( $stored ) ? $stored : array();
			foreach ( $this->form_fields as $key => $field ) {
				if ( ! array_key_exists( $key, $stored ) ) {
					$stored[ $key ] = isset( $field['default'] ) ? $field['default'] : '';
				}
			}
			$this->settings = $stored;
			$this->enabled  = ! empty( $this->settings['enabled'] ) && 'yes' === $this->settings['enabled'] ? 'yes' : 'no';
		}

		/**
		 * @param string $key   Setting.
		 * @param mixed  $empty Default.
		 * @return mixed
		 */
		public function get_option( $key, $empty = null ) {
			return array_key_exists( $key, $this->settings ) ? $this->settings[ $key ] : $empty;
		}

		/** @return bool */
		public function is_available() {
			return 'yes' === $this->enabled;
		}

		/** @return float */
		protected function get_order_total() {
			return (float) $GLOBALS['TACK_NT_CART_TOTAL'];
		}

		/**
		 * @param WC_Order|null $order Order.
		 * @return string
		 */
		public function get_return_url( $order = null ) {
			return 'https://shop.example/checkout/order-received/' . ( $order ? $order->get_id() : 0 ) . '/';
		}

		/** @return bool */
		public function process_admin_options() {
			return true;
		}
	}
}

if ( ! class_exists( 'Tack_Stub_Abstract_Payment_Method_Type' ) ) {
	/** Stand-in for Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType. */
	abstract class Tack_Stub_Abstract_Payment_Method_Type {
		/** @var string */
		protected $name = '';
		/** @var array */
		protected $settings = array();

		/**
		 * @param string $name    Setting.
		 * @param mixed  $default Default.
		 * @return mixed
		 */
		public function get_setting( $name, $default = '' ) {
			return isset( $this->settings[ $name ] ) ? $this->settings[ $name ] : $default;
		}

		/** @return string */
		public function get_name() {
			return $this->name;
		}
	}
	class_alias( 'Tack_Stub_Abstract_Payment_Method_Type', 'Automattic\\WooCommerce\\Blocks\\Payments\\Integrations\\AbstractPaymentMethodType' );
}

if ( ! function_exists( 'wp_register_script' ) ) {
	/** @param string $handle Handle. @param string $src Source. */
	function wp_register_script( $handle, $src = '', $deps = array(), $ver = false, $args = false ) {
		$GLOBALS['TACK_REGISTERED_SCRIPTS'][ $handle ] = array( 'src' => $src, 'deps' => $deps );
		return true;
	}
}
if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
	/** @param array $options Field definition. */
	function woocommerce_register_additional_checkout_field( $options ) {
		$GLOBALS['TACK_ADDITIONAL_FIELDS'][] = $options;
	}
}
if ( ! function_exists( 'woocommerce_form_field' ) ) {
	/** @param string $key Name. @param array $args Args. @param mixed $value Value. */
	function woocommerce_form_field( $key, $args, $value = null ) {
		$GLOBALS['TACK_FORM_FIELDS_RENDERED'][ $key ] = array( 'args' => $args, 'value' => $value );
	}
}
// ═══ END W2-net-terms stubs ═══

require_once __DIR__ . '/wc-tax-stubs.php';
