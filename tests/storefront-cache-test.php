<?php
/**
 * 1.14.0 storefront cache (Tack_Storefront_Cache): repeat page views stop waiting on
 * TackQuote, and no customer ever sees another's answer or a cached price at checkout.
 *
 * Every other suite runs with the cache switched off (run.php), so their request
 * counts keep meaning what they say; this suite switches it on and restores that.
 *
 * @package TackQuotes
 */

echo "\n-- 1.14.0 storefront cache: per customer, per currency, never at checkout --\n";

$GLOBALS['TACK_DOING_ACTION'] = $GLOBALS['TACK_DOING_ACTION'] ?? array();
if ( ! function_exists( 'doing_action' ) ) {
	/** @param string|null $hook Hook. @return bool */
	function doing_action( $hook = null ) {
		return in_array( $hook, $GLOBALS['TACK_DOING_ACTION'], true );
	}
}

/** A client that counts what it asks TackQuote. */
class Tack_Cache_Test_Client extends Tack_Api_Client {

	/** @var string[] */
	public $paths = array();

	/** @var array<string,mixed> Path fragment => answer. */
	public $answers = array();

	/** Constructor. */
	public function __construct() {
	}

	/**
	 * Record and answer.
	 *
	 * @param string   $method  Method.
	 * @param string   $path    Path.
	 * @param mixed    $body    Body.
	 * @param int|null $timeout Timeout.
	 * @param array    $headers Headers.
	 * @return mixed
	 */
	public function request( $method, $path, $body = null, $timeout = null, $headers = array() ) {
		$this->paths[] = $path;
		foreach ( $this->answers as $fragment => $answer ) {
			if ( false !== strpos( $path, $fragment ) ) {
				return is_callable( $answer ) ? $answer( $body ) : $answer;
			}
		}
		return new WP_Error( 'tack_http_500', 'down', array( 'status' => 500 ) );
	}
}

// Caching ON for this suite (run.php switched it off for every other one).
$tack_cache_saved_ttl_filter = $GLOBALS['TACK_PERSISTENT_FILTERS']['tackquote_storefront_cache_ttl'] ?? null;
unset( $GLOBALS['TACK_PERSISTENT_FILTERS']['tackquote_storefront_cache_ttl'] );
tack_test_reset_transients();
$GLOBALS['TACK_STORE_CURRENCY']    = 'USD';
$GLOBALS['TACK_LOGGED_IN']         = true;
$GLOBALS['TACK_USER_EMAIL']        = 'buyer-a@trade.test';
$GLOBALS['TACK_CURRENT_USER_ID']   = 11;
$GLOBALS['TACK_OPTIONS']['tack_quotes_api_key'] = 'tq_live_cache_test';
$GLOBALS['TACK_DOING_ACTION']      = array();

$client = new Tack_Cache_Test_Client();
$client->answers = array(
	'/storefront/v1/order-limits'    => array( 'status' => 'none' ),
	'/storefront/v1/buyer-group'     => array( 'status' => 'grouped', 'name' => 'Gold', 'code' => 'G', 'taxExempt' => true ),
	'/storefront/v1/quantity-breaks' => array( 'status' => 'priced', 'currency' => 'USD', 'rows' => array() ),
	'/storefront/v1/wholesale-price' => array( 'status' => 'priced', 'unitPrice' => 9.5, 'currency' => 'USD' ),
);

// ── A repeat page view is served from the cache ────────────────────────────────
$client->get_order_limits( 'SKU-1', 'buyer-a@trade.test' );
$client->get_order_limits( 'SKU-1', 'buyer-a@trade.test' );
$client->get_buyer_group( 'buyer-a@trade.test' );
$client->get_buyer_group( 'buyer-a@trade.test' );
$client->get_quantity_breaks( 'SKU-1', 'buyer-a@trade.test' );
$client->get_quantity_breaks( 'SKU-1', 'buyer-a@trade.test' );
$client->get_wholesale_price( 'SKU-1', 1, 'buyer-a@trade.test' );
$second = $client->get_wholesale_price( 'SKU-1', 1, 'buyer-a@trade.test' );
// MUTATION: `remember()` that always calls $fetch makes this 8.
check( 'cache: four lookups, asked twice each, reach TackQuote once each', 4 === count( $client->paths ), implode( ', ', $client->paths ) );
check( 'cache: the cached answer is the answer', is_array( $second ) && 9.5 === $second['unitPrice'] );
check( 'cache: a different SKU is a different entry', ( function () use ( $client ) {
	$before = count( $client->paths );
	$client->get_order_limits( 'SKU-2', 'buyer-a@trade.test' );
	return count( $client->paths ) === $before + 1;
} )() );

// ── One customer's answer is never another's ───────────────────────────────────
$before                          = count( $client->paths );
$GLOBALS['TACK_USER_EMAIL']      = 'buyer-b@trade.test';
$GLOBALS['TACK_CURRENT_USER_ID'] = 12;
$client->get_wholesale_price( 'SKU-1', 1, 'buyer-b@trade.test' );
// MUTATION: dropping the customer from the key serves buyer A's price to buyer B.
check( 'cache: another customer asks TackQuote, never reads the first one\'s price', count( $client->paths ) === $before + 1 );
$before                          = count( $client->paths );
$GLOBALS['TACK_CURRENT_USER_ID'] = 13;
$client->get_wholesale_price( 'SKU-1', 1, 'buyer-b@trade.test' );
check( 'cache: the same email on another WordPress account is another customer', count( $client->paths ) === $before + 1 );
$before = count( $client->paths );
$client->get_order_limits( 'SKU-1', '' );
$client->get_order_limits( 'SKU-1', '' );
check( 'cache: a guest is one shared "guest" entry, apart from every signed-in customer', count( $client->paths ) === $before + 1 );

// ── Currency and store are part of the key ─────────────────────────────────────
$GLOBALS['TACK_USER_EMAIL']      = 'buyer-a@trade.test';
$GLOBALS['TACK_CURRENT_USER_ID'] = 11;
$before                          = count( $client->paths );
$GLOBALS['TACK_STORE_CURRENCY']  = 'EUR';
$client->get_wholesale_price( 'SKU-1', 1, 'buyer-a@trade.test' );
// MUTATION: dropping the currency from the key shows a USD answer to a EUR visitor.
check( 'cache: another currency is another entry', count( $client->paths ) === $before + 1 );
$GLOBALS['TACK_STORE_CURRENCY'] = 'USD';
$before                         = count( $client->paths );
$GLOBALS['TACK_OPTIONS']['tack_quotes_api_key'] = 'tq_live_other_workspace';
$client->get_wholesale_price( 'SKU-1', 1, 'buyer-a@trade.test' );
check( 'cache: a store connected to another TackQuote key never reads the old answers', count( $client->paths ) === $before + 1 );
$GLOBALS['TACK_OPTIONS']['tack_quotes_api_key'] = 'tq_live_cache_test';

// ── Money and enforcement are always fresh ─────────────────────────────────────
foreach ( array( 'woocommerce_before_calculate_totals', 'woocommerce_check_cart_items', 'woocommerce_add_to_cart_validation', 'woocommerce_store_api_cart_errors', 'woocommerce_checkout_process' ) as $tack_cache_hook ) {
	$GLOBALS['TACK_DOING_ACTION'] = array( $tack_cache_hook );
	$before                       = count( $client->paths );
	$client->get_order_limits( 'SKU-1', 'buyer-a@trade.test' );
	$client->get_buyer_group( 'buyer-a@trade.test' );
	// MUTATION: removing a hook from FRESH_HOOKS turns its line red.
	check( "cache: during $tack_cache_hook every lookup asks TackQuote", count( $client->paths ) === $before + 2 );
}
$GLOBALS['TACK_DOING_ACTION'] = array();

// The cart is priced from a fresh answer even when the label came from the cache.
$GLOBALS['TACK_OPTIONS']['tack_quotes_enable_wholesale_pricing'] = 'yes';
$tack_cache_price = 40.0;
$client->answers['/storefront-pricing/resolve'] = function ( $body ) use ( &$tack_cache_price ) {
	$items = array();
	foreach ( $body['items'] as $item ) {
		$items[] = array( 'sku' => $item['sku'], 'quantity' => $item['quantity'], 'unitPrice' => $tack_cache_price );
	}
	return array( 'items' => $items );
};
$pricing = new Tack_Wholesale_Pricing( $client );
$label   = $pricing->resolve( array( array( 'sku' => 'SKU-9', 'quantity' => 1 ) ) );
$tack_cache_price = 35.0; // The seller lowers the price in TackQuote.
$label_again = ( new Tack_Wholesale_Pricing( $client ) )->resolve( array( array( 'sku' => 'SKU-9', 'quantity' => 1 ) ) );
check( 'cache: a listing label within the minute is the cached one', 40.0 === ( $label_again['SKU-9|1'] ?? null ) );
$GLOBALS['TACK_DOING_ACTION'] = array( 'woocommerce_before_calculate_totals' );
$charged                      = ( new Tack_Wholesale_Pricing( $client ) )->resolve( array( array( 'sku' => 'SKU-9', 'quantity' => 1 ) ) );
// MUTATION: dropping the before_calculate_totals hook from FRESH_HOOKS charges 40.
check( 'cache: the cart is priced at TackQuote\'s answer now (35), never the cached label (40)', 35.0 === ( $charged['SKU-9|1'] ?? null ) );

// A line the fresh answer leaves out falls back to the store price, not the cached label.
$GLOBALS['TACK_DOING_ACTION'] = array();
$same    = new Tack_Wholesale_Pricing( $client );
$same->resolve( array( array( 'sku' => 'SKU-9', 'quantity' => 1 ) ) );
$client->answers['/storefront-pricing/resolve'] = array( 'items' => array() );
$GLOBALS['TACK_DOING_ACTION'] = array( 'woocommerce_before_calculate_totals' );
$same->resolve( array( array( 'sku' => 'SKU-9', 'quantity' => 1 ) ) );
// MUTATION: dropping `unset( $this->resolved[ $key ] )` charges the cached 40.
check( 'cache: a line TackQuote no longer prices is charged the store price, not the cached label', null === $same->unit_price( 'SKU-9', 1 ) );
$GLOBALS['TACK_DOING_ACTION'] = array();

// ── Only answers are kept ──────────────────────────────────────────────────────
$client->answers = array( '/storefront/v1/buyer-group' => new WP_Error( 'tack_http_503', 'down', array( 'status' => 503 ) ) );
$before          = count( $client->paths );
$client->get_buyer_group( 'outage@trade.test' );
$client->get_buyer_group( 'outage@trade.test' );
check( 'cache: an error is never cached (asked again next time)', count( $client->paths ) === $before + 2 );

// ── Saving settings forgets everything ─────────────────────────────────────────
$client->answers = array( '/storefront/v1/order-limits' => array( 'status' => 'none' ) );
$client->get_order_limits( 'SKU-5', 'buyer-a@trade.test' );
$before = count( $client->paths );
$gen    = Tack_Storefront_Cache::generation();
Tack_Storefront_Cache::flush();
$client->get_order_limits( 'SKU-5', 'buyer-a@trade.test' );
check( 'cache: flush() bumps the generation, and the next view asks TackQuote', Tack_Storefront_Cache::generation() === $gen + 1 && count( $client->paths ) === $before + 1 );
$before = count( $client->paths );
Tack_Storefront_Cache::on_store_settings_update( array( 'fetched' => 1, 'settings' => array( 'a' => 1 ) ), array( 'fetched' => 2, 'settings' => array( 'a' => 1 ) ) );
$client->get_order_limits( 'SKU-5', 'buyer-a@trade.test' );
check( 'cache: a store-settings refresh that changed nothing keeps the cache', count( $client->paths ) === $before );
Tack_Storefront_Cache::on_store_settings_update( array( 'fetched' => 2, 'settings' => array( 'a' => 1 ) ), array( 'fetched' => 3, 'settings' => array( 'a' => 2 ) ) );
$client->get_order_limits( 'SKU-5', 'buyer-a@trade.test' );
check( 'cache: a store-settings change forgets it', count( $client->paths ) === $before + 1 );

$tack_cache_saved_hooks = $GLOBALS['TACK_HOOKS'];
$GLOBALS['TACK_HOOKS']  = array();
Tack_Storefront_Cache::flush_on_change( 'tack_quotes_show_quantity_breaks' );
$tack_cache_hooks      = array_column( $GLOBALS['TACK_HOOKS'], 'hook' );
$GLOBALS['TACK_HOOKS'] = $tack_cache_saved_hooks;
check( 'cache: a settings-page option flushes on add, update and delete', array( 'add_option_tack_quotes_show_quantity_breaks', 'update_option_tack_quotes_show_quantity_breaks', 'delete_option_tack_quotes_show_quantity_breaks' ) === $tack_cache_hooks, implode( ', ', $tack_cache_hooks ) );
$settings_src = (string) file_get_contents( TACK_QUOTES_DIR . 'includes/class-tack-settings.php' );
check( 'cache: every option the settings page registers flushes the cache', 1 === preg_match( '/register_setting\( self::option_group\( \$tab \), \$option.*?Tack_Storefront_Cache::flush_on_change\( \$option \)/s', $settings_src ) );

// ── Keys carry no email and fit the Transients API limit ───────────────────────
$tack_cache_key = Tack_Storefront_Cache::key( 'order-limits', array( 'sku' => 'SKU-1' ), 'buyer-a@trade.test' );
check( 'cache: the transient name is short and carries no email', strlen( $tack_cache_key ) <= 172 && false === strpos( $tack_cache_key, '@' ) && 0 === strpos( $tack_cache_key, 'tack_sfc_' ) );

// ── The TTL filter switches it off ─────────────────────────────────────────────
check( 'cache: prices are kept at most 60 s', 60 === Tack_Storefront_Cache::ttl( 'pricing-resolve' ) && 60 === Tack_Storefront_Cache::ttl( 'wholesale-price' ) );
check( 'cache: a route it does not know is never cached', 0 === Tack_Storefront_Cache::ttl( 'net-terms' ) );

// Restore: every later suite runs with caching off, as before.
if ( null !== $tack_cache_saved_ttl_filter ) {
	$GLOBALS['TACK_PERSISTENT_FILTERS']['tackquote_storefront_cache_ttl'] = $tack_cache_saved_ttl_filter;
}
tack_test_reset_transients();
$GLOBALS['TACK_LOGGED_IN']    = false;
$GLOBALS['TACK_USER_EMAIL']   = '';
unset( $GLOBALS['TACK_CURRENT_USER_ID'] );
