<?php
/**
 * Regression tests for the 1.10.0 WordPress/WooCommerce standards audit (lane W7).
 *
 * Findings and evidence: tack-notes WOO_PLUGIN_AUDIT_2026-10-10.md.
 *   H-1  an email change on ANY path (REST `users/me`, not only My Account) is untrusted.
 *   M-1  the rate limit cannot be bypassed by rotating `X-Real-IP` / `X-Forwarded-For`.
 *   M-2  application forms and checkout links are rate limited.
 *   M-3  only published, unprotected, visible products can be quoted.
 *   M-4  uninstall removes every option the code uses, and unschedules order-sync jobs.
 *   L-1  the API key is never sent after a redirect.
 *   L-2  privacy exporter/eraser for the plugin's user meta.
 *
 * Run: php tests/run.php
 *
 * @package TackQuotes
 */

require_once TACK_QUOTES_DIR . 'includes/class-tack-rate-limit.php';
require_once TACK_QUOTES_DIR . 'includes/class-tack-widget.php';

if ( ! class_exists( 'WC_Geolocation' ) ) {
	/** WooCommerce's address resolution, which trusts the client's X-Real-IP header. */
	class WC_Geolocation {
		/** @return string */
		public static function get_ip_address() {
			return (string) ( $GLOBALS['TACK_TEST_CLIENT_IP'] ?? '' );
		}
	}
}
if ( ! function_exists( 'get_user_by' ) ) {
	/**
	 * @param string $field Field.
	 * @param string $value Value.
	 * @return object|false
	 */
	function get_user_by( $field, $value ) {
		return 'email' === $field && '' !== $value && $value === $GLOBALS['TACK_USER_EMAIL'] ? get_userdata( 1 ) : false;
	}
}

/** A product as Tack_Widget::is_quotable() reads it. */
class Tack_Test_W7_Product {
	/** @var int */
	public $id;
	/** @var string */
	public $status;
	/** @var string */
	public $password;
	/**
	 * @param int    $id       Id.
	 * @param string $status   Post status.
	 * @param string $password Post password.
	 */
	public function __construct( $id, $status = 'publish', $password = '' ) {
		$this->id       = $id;
		$this->status   = $status;
		$this->password = $password;
	}
	/** @return int */
	public function get_id() {
		return $this->id;
	}
	/** @return string */
	public function get_status() {
		return $this->status;
	}
	/** @return string */
	public function get_post_password() {
		return $this->password;
	}
}

// ── H-1: email change through any path ──────────────────────────────────────

$GLOBALS['TACK_HOOKS'] = array();
Tack_B2B_Notices::register_email_trust_guard();
$tack_w7_hooked = array_filter(
	(array) $GLOBALS['TACK_HOOKS'],
	function ( $h ) {
		return 'profile_update' === $h['hook'] && 2 === (int) $h['args'];
	}
);
check( 'H-1: the email-trust guard also listens on profile_update (2 args)', 1 === count( $tack_w7_hooked ) );

$tack_w7_old = (object) array( 'user_email' => 'mine@example.test' );

tack_test_reset_user_meta();
tack_test_set_logged_in( true, 'approved-buyer@example.test' );
$GLOBALS['TACK_CAPS'] = array();
Tack_B2B_Notices::flag_profile_email_change( 1, $tack_w7_old );
check( 'H-1: a customer changing their own email (REST users/me) is flagged unverified', '1' === (string) get_user_meta( 1, Tack_B2B_Notices::META_EMAIL_UNVERIFIED, true ) );
check( 'H-1: ...and TackQuote is no longer told that email', '' === Tack_B2B_Notices::trusted_buyer_email() );
check( 'H-1: ...and the new address is recorded as the known one', 'approved-buyer@example.test' === get_user_meta( 1, '_tack_known_email', true ) );

tack_test_reset_user_meta();
$GLOBALS['TACK_CAPS'] = array( 'edit_users' );
Tack_B2B_Notices::flag_profile_email_change( 1, $tack_w7_old );
check( 'H-1: a change by someone who manages users is vouched for (not flagged)', '' === (string) get_user_meta( 1, Tack_B2B_Notices::META_EMAIL_UNVERIFIED, true ) );
$GLOBALS['TACK_CAPS'] = array();

tack_test_reset_user_meta();
tack_test_set_logged_in( false, 'approved-buyer@example.test' );
Tack_B2B_Notices::flag_profile_email_change( 1, $tack_w7_old );
check( 'H-1: a change made for another user (CLI, import, admin) is not a self-change', '' === (string) get_user_meta( 1, Tack_B2B_Notices::META_EMAIL_UNVERIFIED, true ) );

tack_test_reset_user_meta();
tack_test_set_logged_in( true, 'Mine@Example.test' );
Tack_B2B_Notices::flag_profile_email_change( 1, $tack_w7_old );
check( 'H-1: a profile save that keeps the address (case only) flags nothing', '' === (string) get_user_meta( 1, Tack_B2B_Notices::META_EMAIL_UNVERIFIED, true ) );
tack_test_reset_user_meta();
tack_test_set_logged_in( false, '' );

// ── M-1: forged client address ──────────────────────────────────────────────

tack_test_reset_transients();
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$tack_w7_blocked        = false;
for ( $tack_w7_i = 0; $tack_w7_i < 25; $tack_w7_i++ ) {
	// A new forged X-Real-IP on every request, from the same socket peer.
	$GLOBALS['TACK_TEST_CLIENT_IP'] = '198.51.100.' . $tack_w7_i;
	if ( Tack_Rate_Limit::exceeded( 'tack_qr_', 'quote-request', 2 ) ) {
		$tack_w7_blocked = $tack_w7_i;
		break;
	}
	Tack_Rate_Limit::hit( 'tack_qr_', 'quote-request', 2, 300 );
}
check( 'M-1: rotating X-Real-IP is stopped by the socket-peer counter (2 x 10 = 20 requests)', 20 === $tack_w7_blocked, 'blocked at ' . var_export( $tack_w7_blocked, true ) );

tack_test_reset_transients();
$GLOBALS['TACK_TEST_CLIENT_IP'] = '198.51.100.7';
Tack_Rate_Limit::hit( 'tack_qr_', 'quote-request', 2, 300 );
Tack_Rate_Limit::hit( 'tack_qr_', 'quote-request', 2, 300 );
check( 'M-1: one visitor is still held to the per-visitor allowance', true === Tack_Rate_Limit::exceeded( 'tack_qr_', 'quote-request', 2 ) );
check( 'M-1: the per-visitor key is unchanged from 1.9 (tack_qr_ + salted hash)', Tack_Rate_Limit::client_key( 'tack_qr_', 'quote-request' ) === 'tack_qr_' . substr( wp_hash( 'quote-request|198.51.100.7' ), 0, 20 ) );
check( 'M-1: no address is stored in a key', false === strpos( Tack_Rate_Limit::socket_key( 'tack_qr_', 'quote-request' ), '203.0.113' ) );
check( 'M-1: a limit of 0 disables the guard', false === Tack_Rate_Limit::exceeded( 'tack_qr_', 'quote-request', 0 ) );
tack_test_reset_transients();

// ── M-2: application forms and checkout links ───────────────────────────────

tack_test_without_rate_limits( false );

tack_test_reset_transients();
check( 'M-2: a fresh visitor may apply', false === Tack_Storefront_Forms::rate_limited() );
for ( $tack_w7_i = 0; $tack_w7_i < Tack_Storefront_Forms::RATE_LIMIT_MAX; $tack_w7_i++ ) {
	Tack_Rate_Limit::hit( 'tack_qf_', 'application', Tack_Storefront_Forms::RATE_LIMIT_MAX, Tack_Storefront_Forms::RATE_LIMIT_WINDOW );
}
check( 'M-2: after RATE_LIMIT_MAX applications the visitor is limited', true === Tack_Storefront_Forms::rate_limited() );

$GLOBALS['TACK_NONCE_VALID'] = true;
tack_test_set_logged_in( true, 'buyer@example.test' );
$tack_w7_client = new Tack_Test_QC_Client( array( 'status' => 'received' ) );
$tack_w7_forms  = new Tack_Storefront_Forms( $tack_w7_client );
$tack_w7_out    = $tack_w7_forms->process_credit_submission(
	array(
		'_tack_nonce'   => wp_create_nonce( Tack_Storefront_Forms::ACTION_CREDIT ),
		'tack_redirect' => 'https://shop.example/apply/',
		'tack_ct'       => array( 'legalBusinessName' => 'Acme' ),
	)
);
check( 'M-2: a limited net-terms application is refused with the wait message', 'error' === $tack_w7_out['kind'] && false !== strpos( $tack_w7_out['message'], 'Too many applications' ) );
check( 'M-2: ...and makes no TackQuote request', array() === $tack_w7_client->paths );
$tack_w7_out = $tack_w7_forms->process_wholesale_submission(
	array(
		'_tack_nonce'   => wp_create_nonce( Tack_Storefront_Forms::ACTION_WHOLESALE ),
		'tack_redirect' => 'https://shop.example/apply/',
	)
);
check( 'M-2: a limited wholesale application is refused before the form is even read', 'error' === $tack_w7_out['kind'] && array() === $tack_w7_client->paths );
tack_test_set_logged_in( false, '' );

tack_test_reset_transients();
$tack_w7_qc = new Tack_Test_QC_Checkout( new Tack_Test_QC_Client( new WP_Error( 'tack_http_404', 'nope', array( 'status' => 404 ) ) ) );
for ( $tack_w7_i = 0; $tack_w7_i < Tack_Quote_Checkout::RATE_LIMIT_MAX; $tack_w7_i++ ) {
	$tack_w7_qc->process_token( TACK_QC_TOKEN );
}
check( 'M-2: each checkout-link exchange below the limit reaches TackQuote', Tack_Quote_Checkout::RATE_LIMIT_MAX === count( $tack_w7_qc->client_paths() ) );
$tack_w7_qc->process_token( TACK_QC_TOKEN );
check( 'M-2: the next checkout link from the same visitor makes NO request', Tack_Quote_Checkout::RATE_LIMIT_MAX === count( $tack_w7_qc->client_paths() ) );
$tack_w7_qc->process_token( 'not-a-token' );
check( 'M-2: a malformed token is still refused without a request (and without a charge)', Tack_Quote_Checkout::RATE_LIMIT_MAX === count( $tack_w7_qc->client_paths() ) );

tack_test_reset_transients();
tack_test_without_rate_limits( true );

// ── M-3: quotable products ──────────────────────────────────────────────────

$GLOBALS['TACK_CAPS'] = array();
tack_test_clear_filter_returns();
check( 'M-3: a published product is quotable', true === Tack_Widget::is_quotable( new Tack_Test_W7_Product( 5 ) ) );
foreach ( array( 'draft', 'pending', 'private', 'trash', 'future' ) as $tack_w7_status ) {
	check( "M-3: a $tack_w7_status product is NOT quotable", false === Tack_Widget::is_quotable( new Tack_Test_W7_Product( 5, $tack_w7_status ) ) );
}
check( 'M-3: a password-protected product is NOT quotable', false === Tack_Widget::is_quotable( new Tack_Test_W7_Product( 5, 'publish', 'secret' ) ) );
check( 'M-3: a published variation of a DRAFT parent is NOT quotable', false === Tack_Widget::is_quotable( new Tack_Test_W7_Product( 6 ), new Tack_Test_W7_Product( 5, 'draft' ) ) );
check( 'M-3: a disabled (private) variation is NOT quotable', false === Tack_Widget::is_quotable( new Tack_Test_W7_Product( 6, 'private' ), new Tack_Test_W7_Product( 5 ) ) );
$GLOBALS['TACK_CAPS'] = array( 'edit_post' );
check( 'M-3: someone who can edit a draft may quote it', true === Tack_Widget::is_quotable( new Tack_Test_W7_Product( 5, 'draft' ) ) );
$GLOBALS['TACK_CAPS'] = array();
tack_test_add_filter_return( 'woocommerce_product_is_visible', false );
check( 'M-3: a product hidden through woocommerce_product_is_visible (group catalogue) is NOT quotable', false === Tack_Widget::is_quotable( new Tack_Test_W7_Product( 5 ) ) );
tack_test_clear_filter_returns();

$tack_w7_widget  = ( new ReflectionClass( 'Tack_Widget' ) )->newInstanceWithoutConstructor();
$tack_w7_resolve = new ReflectionMethod( 'Tack_Widget', 'resolve_purchasable' );
$tack_w7_resolve->setAccessible( true );
check( 'M-3: the quote/reprice path drops a draft product', null === $tack_w7_resolve->invoke( $tack_w7_widget, new Tack_Test_W7_Product( 5, 'draft' ), 0 ) );
$tack_w7_live = new Tack_Test_W7_Product( 5 );
check( 'M-3: the quote/reprice path keeps a published product', $tack_w7_live === $tack_w7_resolve->invoke( $tack_w7_widget, $tack_w7_live, 0 ) );

if ( ! class_exists( 'WC_Product_Variation' ) ) {
	/** A variation as resolve_purchasable() checks it (instanceof + parent id). */
	class WC_Product_Variation extends Tack_Test_W7_Product {
		/** @var int */
		public $parent_id = 0;
		/** @return int */
		public function get_parent_id() {
			return $this->parent_id;
		}
	}
}
$tack_w7_variation            = new WC_Product_Variation( 66 );
$tack_w7_variation->parent_id = 65;
$GLOBALS['TACK_TEST_PRODUCTS'][66] = $tack_w7_variation;
check( 'M-3: a published variation of a DRAFT parent is dropped on the quote path', null === $tack_w7_resolve->invoke( $tack_w7_widget, new Tack_Test_W7_Product( 65, 'draft' ), 66 ) );
check( 'M-3: a published variation of a published parent is quoted', $tack_w7_variation === $tack_w7_resolve->invoke( $tack_w7_widget, new Tack_Test_W7_Product( 65 ), 66 ) );
unset( $GLOBALS['TACK_TEST_PRODUCTS'][66] );

// ── L-1: no redirects with the key ──────────────────────────────────────────

tack_test_set_option( 'tack_quotes_api_key', 'tk_test_key' );
$GLOBALS['TACK_W7_ARGS']        = null;
$GLOBALS['TACK_HTTP_RESPONDER'] = function ( $url, $args ) {
	$GLOBALS['TACK_W7_ARGS'] = $args;
	return array(
		'response' => array( 'code' => 200 ),
		'body'     => '{}',
		'headers'  => array(),
	);
};
( new Tack_Api_Client() )->request( 'GET', '/integrations/woocommerce/ping' );
check( 'L-1: every API request sets redirection => 0', is_array( $GLOBALS['TACK_W7_ARGS'] ) && array_key_exists( 'redirection', $GLOBALS['TACK_W7_ARGS'] ) && 0 === $GLOBALS['TACK_W7_ARGS']['redirection'] );
unset( $GLOBALS['TACK_HTTP_RESPONDER'] );
tack_test_set_option( 'tack_quotes_api_key', '' );

// ── M-4: uninstall completeness ─────────────────────────────────────────────

$tack_w7_uninstall = (string) file_get_contents( TACK_QUOTES_DIR . 'uninstall.php' );
$tack_w7_options   = array();
foreach ( get_declared_classes() as $tack_w7_class ) {
	if ( 0 !== strpos( $tack_w7_class, 'Tack_' ) || 0 === strpos( $tack_w7_class, 'Tack_Test' ) || 0 === strpos( $tack_w7_class, 'Tack_Stub' ) ) {
		continue;
	}
	foreach ( ( new ReflectionClass( $tack_w7_class ) )->getConstants() as $tack_w7_name => $tack_w7_value ) {
		if ( is_string( $tack_w7_value ) && preg_match( '/^OPT(ION)?_/', $tack_w7_name ) && 'OPTION_GROUP' !== $tack_w7_name && 0 === strpos( $tack_w7_value, 'tack_quotes_' ) ) {
			$tack_w7_options[ $tack_w7_value ] = $tack_w7_class . '::' . $tack_w7_name;
		}
	}
}
foreach ( glob( TACK_QUOTES_DIR . 'includes/{*.php,*/*.php}', GLOB_BRACE ) as $tack_w7_file ) {
	preg_match_all( "/(?:get_option|update_option|add_option|delete_option|register_setting|setting)\(\s*(?:[^,]*,\s*)?'(tack_quotes_[a-z0-9_]+)'/", (string) file_get_contents( $tack_w7_file ), $tack_w7_m );
	foreach ( $tack_w7_m[1] as $tack_w7_option ) {
		$tack_w7_options[ $tack_w7_option ] = basename( $tack_w7_file );
	}
}
unset( $tack_w7_options['tack_quotes_settings'] ); // An option GROUP name, not an option.
$tack_w7_missing = array();
foreach ( $tack_w7_options as $tack_w7_option => $tack_w7_where ) {
	if ( false === strpos( $tack_w7_uninstall, "'" . $tack_w7_option . "'" ) ) {
		$tack_w7_missing[] = $tack_w7_option . ' (' . $tack_w7_where . ')';
	}
}
check( 'M-4: the scan found the options it should (more than 40)', count( $tack_w7_options ) > 40, (string) count( $tack_w7_options ) );
check( 'M-4: uninstall.php deletes EVERY option the plugin uses', array() === $tack_w7_missing, 'missing: ' . implode( ', ', $tack_w7_missing ) );
foreach ( array( Tack_Order_Sync::SYNC_HOOK, Tack_Order_Sync::REQUEUE_HOOK ) as $tack_w7_hook ) {
	check( "M-4: uninstall.php unschedules $tack_w7_hook", false !== strpos( $tack_w7_uninstall, "'" . $tack_w7_hook . "'" ) && false !== strpos( $tack_w7_uninstall, "as_unschedule_all_actions( $" . "hook, array(), 'tackquote' );" ) && false !== strpos( $tack_w7_uninstall, 'wp_unschedule_hook( $' . 'hook );' ) );
}
check( 'M-4: uninstall.php removes the quote-only product meta', false !== strpos( $tack_w7_uninstall, "'" . Tack_Catalog_Mode::META_QUOTE_ONLY . "'" ) );
check( 'M-4: uninstall.php removes the stored copy of the customer email', false !== strpos( $tack_w7_uninstall, "'_tack_known_email'" ) );
check( 'M-4: uninstall.php KEEPS the unverified-email flag (deleting it would re-trust)', 1 !== preg_match( "/user_meta = array\([^)]*_tack_email_unverified/s", $tack_w7_uninstall ) );
$tack_w7_quotes = (string) file_get_contents( TACK_QUOTES_DIR . 'includes/class-tack-quotes.php' );
check( 'M-4: deactivation unschedules the real order-sync hooks, not a dead one', false !== strpos( $tack_w7_quotes, 'Tack_Order_Sync::SYNC_HOOK, Tack_Order_Sync::REQUEUE_HOOK' ) && false === strpos( $tack_w7_quotes, "'tack_quotes_retry_sync'" ) );

// ── L-2: privacy exporter and eraser ────────────────────────────────────────

check( 'L-2: an exporter is registered', isset( Tack_Quotes::register_privacy_exporter( array() )['tackquote']['callback'] ) );
check( 'L-2: an eraser is registered', isset( Tack_Quotes::register_privacy_eraser( array() )['tackquote']['callback'] ) );
tack_test_reset_user_meta();
tack_test_set_logged_in( false, 'buyer@example.test' );
update_user_meta( 1, '_tack_known_email', 'buyer@example.test' );
update_user_meta( 1, Tack_B2B_Notices::META_EMAIL_UNVERIFIED, '1' );
$tack_w7_export = Tack_Quotes::export_personal_data( 'buyer@example.test' );
check( 'L-2: the export lists the stored email copy', isset( $tack_w7_export['data'][0]['data'] ) && in_array( 'buyer@example.test', wp_list_pluck_w7( $tack_w7_export['data'][0]['data'] ), true ) && true === $tack_w7_export['done'] );
check( 'L-2: nothing is exported for an address with no account', array() === Tack_Quotes::export_personal_data( 'nobody@example.test' )['data'] );
$tack_w7_erase = Tack_Quotes::erase_personal_data( 'buyer@example.test' );
check( 'L-2: erasure removes the stored email copy', true === $tack_w7_erase['items_removed'] && '' === get_user_meta( 1, '_tack_known_email', true ) );
check( 'L-2: erasure RETAINS the unverified flag and says so (never re-trusts)', '1' === (string) get_user_meta( 1, Tack_B2B_Notices::META_EMAIL_UNVERIFIED, true ) && true === $tack_w7_erase['items_retained'] && 1 === count( $tack_w7_erase['messages'] ) );
tack_test_reset_user_meta();
tack_test_set_logged_in( false, '' );

/**
 * The `value` column of an export's data rows.
 *
 * @param array $rows Rows.
 * @return string[]
 */
function wp_list_pluck_w7( array $rows ) {
	return array_map(
		function ( $row ) {
			return (string) $row['value'];
		},
		$rows
	);
}
