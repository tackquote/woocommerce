<?php
/**
 * 1.12.0 back office (tack lane WOO-BACKOFFICE): the store profile rides on the ping
 * (`store_profile`) and a chosen variation is named `{parent}_{variation}`
 * (`variation_keys`), each only to a server that lists the capability.
 *
 * Runs from tests/run.php after live-qa-1120-test.php (reuses its product doubles).
 *
 * @package TackQuotes
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'wp_timezone_string' ) ) {
	/** @return string */
	function wp_timezone_string() {
		return $GLOBALS['TACK_TIMEZONE_STRING'] ?? 'America/Chicago';
	}
}

/** WooCommerce's store base address (WC_Countries getters). */
class Tack_Bo_Countries {
	/** @return string */
	public function get_base_address() {
		return '1 Main St';
	}
	/** @return string */
	public function get_base_address_2() {
		return '';
	}
	/** @return string */
	public function get_base_city() {
		return 'Austin';
	}
	/** @return string */
	public function get_base_state() {
		return 'TX';
	}
	/** @return string */
	public function get_base_postcode() {
		return '78701';
	}
	/** @return string */
	public function get_base_country() {
		return 'US';
	}
}

// ── store_profile ───────────────────────────────────────────────────────────
$GLOBALS['TACK_WC_COUNTRIES'] = new Tack_Bo_Countries();
$bo_header                    = Tack_Api_Client::store_profile_header();
$bo_decoded                   = json_decode( base64_decode( strtr( $bo_header, '-_', '+/' ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- test decodes the header.
check( 'store profile: one base64url token', 1 === preg_match( '/^[A-Za-z0-9_-]+$/', $bo_header ) );
check(
	'store profile: name, URL, time zone, currency and base address',
	is_array( $bo_decoded )
	&& get_bloginfo( 'name' ) === ( $bo_decoded['name'] ?? null )
	&& 'America/Chicago' === ( $bo_decoded['timezone'] ?? '' )
	&& 'Austin' === ( $bo_decoded['address']['city'] ?? '' )
	&& 'US' === ( $bo_decoded['address']['country'] ?? '' )
	&& isset( $bo_decoded['currency'], $bo_decoded['url'] )
);

$bo_key = 'tack_sk_backoffice_test_key_0001';
tack_test_set_option( 'tack_quotes_api_key', $bo_key );
$GLOBALS['TACK_BO_HEADERS']     = array();
$GLOBALS['TACK_HTTP_RESPONDER'] = function ( $url, $args ) {
	if ( false !== strpos( $url, '/ping' ) ) {
		$GLOBALS['TACK_BO_HEADERS'][] = $args['headers'] ?? array();
	}
	return array(
		'response' => array( 'code' => 200 ),
		'body'     => wp_json_encode( array( 'ok' => true, 'capabilities' => $GLOBALS['TACK_BO_CAPS'] ) ),
		'headers'  => array(),
	);
};

// A server that does not list store_profile: the ping carries no profile.
tack_test_reset_transients();
$GLOBALS['TACK_BO_CAPS'] = array( 'attachments' );
( new Tack_Api_Client() )->server_capabilities( true );
( new Tack_Api_Client() )->server_capabilities( true );
$bo_last = end( $GLOBALS['TACK_BO_HEADERS'] );
check( 'store profile: never sent to a server without store_profile', ! isset( $bo_last['X-TackQuote-Store-Profile'] ) );

// It lists it: the next ping carries the profile (the capability is read from the cache).
tack_test_reset_transients();
$GLOBALS['TACK_BO_CAPS'] = array( 'attachments', 'store_profile' );
( new Tack_Api_Client() )->server_capabilities( true );
( new Tack_Api_Client() )->server_capabilities( true );
$bo_last = end( $GLOBALS['TACK_BO_HEADERS'] );
check( 'store profile: sent on the ping once the server lists store_profile', isset( $bo_last['X-TackQuote-Store-Profile'] ) && $bo_header === $bo_last['X-TackQuote-Store-Profile'] );
unset( $GLOBALS['TACK_HTTP_RESPONDER'], $GLOBALS['TACK_WC_COUNTRIES'] );
tack_test_reset_transients();

// ── variation_keys ──────────────────────────────────────────────────────────
$bo_lines = array(
	array( 'sku' => 'GLOVE-LB', 'externalProductId' => '302' ),
	array( 'sku' => 'GLOVE', 'externalProductId' => '301' ),
	array( 'sku' => 'NONE' ),
);
$bo_keyed = Tack_Widget::with_variation_keys( $bo_lines, true );
check( 'variation keys: a variation becomes {parent}_{variation}', '301_302' === ( $bo_keyed[0]['externalProductId'] ?? '' ) );
check( 'variation keys: a product that is not a variation is unchanged', '301' === ( $bo_keyed[1]['externalProductId'] ?? '' ) );
check( 'variation keys: a line without an id is unchanged', ! isset( $bo_keyed[2]['externalProductId'] ) );
check( 'variation keys: an older server gets the bare id', Tack_Widget::with_variation_keys( $bo_lines, false ) === $bo_lines );

// The request handler applies it, to the payload it posts (the same source pin e2e-fixes-test.php D5 uses for target prices).
$bo_widget_src = (string) file_get_contents( TACK_QUOTES_DIR . 'includes/class-tack-widget.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- test reads plugin source.
check( 'variation keys: handle_request() names variations by key for a variation_keys server', false !== strpos( $bo_widget_src, "\$payload['lineItems'] = self::with_variation_keys( \$payload['lineItems'], \$client->supports( 'variation_keys' ) );" ) );
