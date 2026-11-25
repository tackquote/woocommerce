<?php
/**
 * Runs the REAL uninstall.php once, in its own PHP process, against minimal WordPress
 * stubs, and prints what it sent as JSON. Used by lifecycle-feedback-test.php (1.16.0),
 * which starts one process per scenario so uninstall.php's top-level code runs as
 * WordPress runs it (included once, with WP_UNINSTALL_PLUGIN defined).
 *
 * Usage: php tests/uninstall-harness.php '<scenario JSON>'
 *   caps       null (no cached ping) or a capability list
 *   answers    HTTP answers in order: [code, body] or "TRANSPORT"
 *
 * Output: {"requests":[{url, timeout, redirection, body}], "keyDeleted": bool}
 *
 * @package TackQuotes
 */

// phpcs:disable -- a test harness, not plugin code.

define( 'WP_UNINSTALL_PLUGIN', 'tackquote/tackquote.php' );
define( 'ABSPATH', '/' );

$scenario = json_decode( (string) ( $argv[1] ?? '{}' ), true );
$scenario = is_array( $scenario ) ? $scenario : array();

$GLOBALS['H_OPTIONS']    = array(
	'tack_quotes_api_key'        => 'tq_harness_key_0123456789',
	'tack_quotes_api_url'        => 'https://api.tackquote.test/v1',
	'tack_quotes_wc_rest_key_id' => 42,
);
$GLOBALS['H_TRANSIENTS'] = array();
if ( isset( $scenario['caps'] ) && is_array( $scenario['caps'] ) ) {
	$GLOBALS['H_TRANSIENTS']['tack_quotes_server_capabilities'] = array( 'key' => 'x', 'caps' => $scenario['caps'], 'until' => time() + 60 );
}
$GLOBALS['H_ANSWERS']  = isset( $scenario['answers'] ) && is_array( $scenario['answers'] ) ? $scenario['answers'] : array( array( 200, array( 'restKeysReleased' => true ) ) );
$GLOBALS['H_REQUESTS'] = array();
$GLOBALS['H_KEY_DEL']  = false;

class WP_Error {
	public $code;
	public function __construct( $code = '' ) {
		$this->code = $code;
	}
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['H_OPTIONS'] ) ? $GLOBALS['H_OPTIONS'][ $k ] : $d; }
function delete_option( $k ) { unset( $GLOBALS['H_OPTIONS'][ $k ] ); return true; }
function get_transient( $k ) { return $GLOBALS['H_TRANSIENTS'][ $k ] ?? false; }
function delete_transient( $k ) { unset( $GLOBALS['H_TRANSIENTS'][ $k ] ); return true; }
function delete_site_transient( $k ) { return true; }
function wp_unschedule_hook( $h ) { return 0; }
function remove_role( $r ) {}
function delete_metadata( ...$a ) { return true; }
function delete_post_meta_by_key( $k ) { return true; }
function is_multisite() { return false; }
function home_url() { return 'https://shop.example'; }
function wp_json_encode( $v ) { return json_encode( $v ); }
/** Core's get_file_data(), reduced: the header value from the file's first 8 KB. */
function get_file_data( $file, $headers ) {
	$data = (string) file_get_contents( $file, false, null, 0, 8192 );
	$out  = array();
	foreach ( $headers as $field => $name ) {
		$out[ $field ] = preg_match( '/^(?:[ \t]*<\?php)?[ \t\/*#@]*' . preg_quote( $name, '/' ) . ':(.*)$/mi', $data, $m ) ? trim( $m[1] ) : '';
	}
	return $out;
}
function wp_remote_request( $url, $args ) {
	$GLOBALS['H_REQUESTS'][] = array(
		'url'         => $url,
		'timeout'     => $args['timeout'] ?? null,
		'redirection' => $args['redirection'] ?? null,
		'body'        => json_decode( (string) ( $args['body'] ?? '' ), true ),
	);
	$answer = array_shift( $GLOBALS['H_ANSWERS'] );
	if ( null === $answer || 'TRANSPORT' === $answer ) {
		return new WP_Error( 'http_request_failed' );
	}
	return array( 'response' => array( 'code' => (int) $answer[0] ), 'body' => json_encode( $answer[1] ) );
}
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? $r['response']['code'] : ''; }
function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? $r['body'] : ''; }

class H_Wpdb {
	public $prefix  = 'wp_';
	public $options = 'wp_options';
	public function esc_like( $t ) { return addcslashes( $t, '_%\\' ); }
	public function prepare( $q, ...$a ) { return $q; }
	public function query( $q ) {
		if ( false !== strpos( $q, 'woocommerce_api_keys' ) ) {
			$GLOBALS['H_KEY_DEL'] = true;
		}
		return 1;
	}
}
$GLOBALS['wpdb'] = new H_Wpdb();
$wpdb            = $GLOBALS['wpdb'];

require dirname( __DIR__ ) . '/uninstall.php';

echo json_encode(
	array(
		'requests'   => $GLOBALS['H_REQUESTS'],
		'keyDeleted' => $GLOBALS['H_KEY_DEL'],
	)
);
