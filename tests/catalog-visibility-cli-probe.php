<?php
/**
 * Child-process probe for group-catalog-test.php: WP_CLI is a constant, so it
 * cannot be set and unset inside the main test run. This defines it, then
 * prints what the catalogue-visibility gate does for a guest under WP-CLI.
 *
 * Run by the test via PHP_BINARY; prints one JSON line.
 *
 * @package TackQuotes
 */

define( 'WP_CLI', true );
require __DIR__ . '/wp-stubs.php';
define( 'ABSPATH', '/' );
define( 'TACK_QUOTES_DIR', dirname( __DIR__ ) . '/' );
require TACK_QUOTES_DIR . 'includes/class-tack-api-client.php';
require TACK_QUOTES_DIR . 'includes/class-tack-b2b-notices.php';
require TACK_QUOTES_DIR . 'includes/class-tack-group-restrictions.php';
require TACK_QUOTES_DIR . 'includes/class-tack-catalog-visibility.php';

/** A query double that only records set(). */
class Tack_Probe_Query {
	public $vars = array( 'post_type' => 'product' );
	public function get( $k ) { return $this->vars[ $k ] ?? ''; }
	public function set( $k, $v ) { $this->vars[ $k ] = $v; }
	public function is_singular() { return false; }
	public function is_main_query() { return true; }
	public function is_search() { return false; }
	public function is_tax() { return false; }
}

tack_test_set_option( Tack_Catalog_Visibility::OPTION_MAP, '10: @GUESTS' );
tack_test_set_logged_in( false, '' );
$v = new Tack_Catalog_Visibility( new Tack_B2B_Notices() );
$q = new Tack_Probe_Query();
$v->filter_query( $q );
echo json_encode(
	array(
		'shopper_context' => $v->shopper_context(),
		'tax_query_added' => '' !== $q->get( 'tax_query' ),
	)
), "\n";
