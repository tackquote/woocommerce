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

// Everything this suite sets is saved here and put back at the end, and every stub it
// needs is declared here (guarded), so it depends on no earlier suite.
$tack_cache_saved_globals = array();
foreach ( array( 'TACK_DOING_ACTION', 'TACK_DID_ACTIONS', 'TACK_IS_CART', 'TACK_IS_CHECKOUT', 'TACK_STORE_CURRENCY', 'TACK_EXT_OBJECT_CACHE', 'TACK_EXPIRED_SWEEPS' ) as $tack_cache_global ) {
	$tack_cache_saved_globals[ $tack_cache_global ] = array_key_exists( $tack_cache_global, $GLOBALS ) ? array( $GLOBALS[ $tack_cache_global ] ) : null;
}
$tack_cache_saved_options = array();
foreach ( array( 'tack_quotes_api_key', 'tack_quotes_enable_wholesale_pricing', Tack_Storefront_Cache::SWEEP_OPTION ) as $tack_cache_option ) {
	$tack_cache_saved_options[ $tack_cache_option ] = array_key_exists( $tack_cache_option, $GLOBALS['TACK_OPTIONS'] ) ? array( $GLOBALS['TACK_OPTIONS'][ $tack_cache_option ] ) : null;
}
$GLOBALS['TACK_DOING_ACTION']     = array();
$GLOBALS['TACK_DID_ACTIONS']      = array();
$GLOBALS['TACK_IS_CART']          = false;
$GLOBALS['TACK_IS_CHECKOUT']      = false;
$GLOBALS['TACK_EXT_OBJECT_CACHE'] = false;
$GLOBALS['TACK_EXPIRED_SWEEPS']   = 0;
if ( ! function_exists( 'doing_action' ) ) {
	/** @param string|null $hook Hook. @return bool */
	function doing_action( $hook = null ) {
		return in_array( $hook, (array) ( $GLOBALS['TACK_DOING_ACTION'] ?? array() ), true );
	}
}
if ( ! function_exists( 'did_action' ) ) {
	/** @param string $hook Hook. @return int */
	function did_action( $hook ) {
		return in_array( $hook, (array) ( $GLOBALS['TACK_DID_ACTIONS'] ?? array() ), true ) ? 1 : 0;
	}
}
if ( ! function_exists( 'is_cart' ) ) {
	/** @return bool */
	function is_cart() {
		return (bool) ( $GLOBALS['TACK_IS_CART'] ?? false );
	}
}
if ( ! function_exists( 'is_checkout' ) ) {
	/** @return bool */
	function is_checkout() {
		return (bool) ( $GLOBALS['TACK_IS_CHECKOUT'] ?? false );
	}
}
if ( ! function_exists( 'wp_using_ext_object_cache' ) ) {
	/** @return bool */
	function wp_using_ext_object_cache() {
		return (bool) ( $GLOBALS['TACK_EXT_OBJECT_CACHE'] ?? false );
	}
}
if ( ! function_exists( 'delete_expired_transients' ) ) {
	/** Counts the sweeps. */
	function delete_expired_transients() {
		$GLOBALS['TACK_EXPIRED_SWEEPS'] = ( $GLOBALS['TACK_EXPIRED_SWEEPS'] ?? 0 ) + 1;
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

check(
	'cache: the fresh hooks are exactly the nine money and enforcement hooks',
	array(
		'woocommerce_before_calculate_totals',
		'woocommerce_calculate_totals',
		'woocommerce_add_to_cart_validation',
		'woocommerce_update_cart_validation',
		'woocommerce_check_cart_items',
		'woocommerce_store_api_cart_errors',
		'woocommerce_checkout_process',
		'woocommerce_after_checkout_validation',
		'woocommerce_checkout_create_order',
	) === Tack_Storefront_Cache::FRESH_HOOKS
);

// ── The cart and checkout pages are always fresh (once the main query ran) ─────
$client->answers = array( '/storefront/v1/order-limits' => array( 'status' => 'none' ) );
$client->get_order_limits( 'SKU-P', 'buyer-a@trade.test' ); // Cached from a product page.
foreach ( array( 'TACK_IS_CART' => 'cart', 'TACK_IS_CHECKOUT' => 'checkout' ) as $tack_cache_flag => $tack_cache_page ) {
	$GLOBALS['TACK_DID_ACTIONS'] = array( 'wp' );
	$GLOBALS[ $tack_cache_flag ] = true;
	$before                      = count( $client->paths );
	$client->get_order_limits( 'SKU-P', 'buyer-a@trade.test' );
	$client->get_order_limits( 'SKU-P', 'buyer-a@trade.test' );
	// MUTATION: dropping the is_cart()/is_checkout() check serves the cached answer here.
	check( "cache: on the $tack_cache_page page every lookup asks TackQuote", count( $client->paths ) === $before + 2 );
	$GLOBALS['TACK_DID_ACTIONS'] = array();
	$before                      = count( $client->paths );
	$client->get_order_limits( 'SKU-P', 'buyer-a@trade.test' );
	check( "cache: before the main query ran, the $tack_cache_page conditional is not trusted (cached)", count( $client->paths ) === $before );
	$GLOBALS[ $tack_cache_flag ] = false;
}

// ── A null ("no such route") is never kept ─────────────────────────────────────
// (Through remember() itself: the client turns a v1 404 into null and then answers
// null from its own "v1 missing" note without asking, which would hide the cache.)
$tack_cache_null_calls = 0;
$tack_cache_null_fetch = function () use ( &$tack_cache_null_calls ) {
	++$tack_cache_null_calls;
	return null;
};
$tack_cache_null_first  = Tack_Storefront_Cache::remember( 'quantity-breaks', array( 'sku' => 'SKU-N' ), 'null-route@trade.test', $tack_cache_null_fetch );
$tack_cache_null_second = Tack_Storefront_Cache::remember( 'quantity-breaks', array( 'sku' => 'SKU-N' ), 'null-route@trade.test', $tack_cache_null_fetch );
// MUTATION: caching anything that is not a WP_Error (`! is_wp_error( $value )`) keeps the null.
check( 'cache: a null answer is never cached (asked again next time)', 2 === $tack_cache_null_calls && null === $tack_cache_null_first && null === $tack_cache_null_second );

// ── The bypass filter ──────────────────────────────────────────────────────────
$client->answers = array( '/storefront/v1/order-limits' => array( 'status' => 'none' ) );
$client->get_order_limits( 'SKU-BY', 'buyer-a@trade.test' );
$tack_cache_bypass = function () {
	return true;
};
add_filter( 'tackquote_storefront_cache_bypass', $tack_cache_bypass );
$before = count( $client->paths );
$client->get_order_limits( 'SKU-BY', 'buyer-a@trade.test' );
$client->get_order_limits( 'SKU-BY', 'buyer-a@trade.test' );
remove_filter( 'tackquote_storefront_cache_bypass', $tack_cache_bypass );
check( 'cache: tackquote_storefront_cache_bypass makes every lookup ask TackQuote', count( $client->paths ) === $before + 2 );

// ── A listing in another order is the same entry ──────────────────────────────
$tack_cache_price = 20.0;
$client->answers  = array(
	'/storefront-pricing/resolve' => function ( $body ) use ( &$tack_cache_price ) {
		$items = array();
		foreach ( $body['items'] as $item ) {
			$items[] = array( 'sku' => $item['sku'], 'quantity' => $item['quantity'], 'unitPrice' => $tack_cache_price + strlen( $item['sku'] ) );
		}
		return array( 'items' => $items );
	},
);
$tack_cache_ab = ( new Tack_Wholesale_Pricing( $client ) )->resolve( array( array( 'sku' => 'A', 'quantity' => 1 ), array( 'sku' => 'BB', 'quantity' => 2 ) ) );
$before        = count( $client->paths );
$tack_cache_ba = ( new Tack_Wholesale_Pricing( $client ) )->resolve( array( array( 'sku' => 'BB', 'quantity' => 2 ), array( 'sku' => 'A', 'quantity' => 1 ) ) );
// MUTATION: dropping the usort in canonical() makes the reordered listing ask again.
check( 'cache: the same listing in another order reads the same entry', count( $client->paths ) === $before, implode( ', ', array_slice( $client->paths, $before ) ) );
check( 'cache: ...and every line keeps its own price', 21.0 === ( $tack_cache_ba['A|1'] ?? null ) && 22.0 === ( $tack_cache_ba['BB|2'] ?? null ) && $tack_cache_ab == $tack_cache_ba ); // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- same keys, any order.
$before = count( $client->paths );
( new Tack_Wholesale_Pricing( $client ) )->resolve( array( array( 'sku' => 'A', 'quantity' => 2 ), array( 'sku' => 'BB', 'quantity' => 1 ) ) );
check( 'cache: other quantities are another entry', count( $client->paths ) === $before + 1 );

// ── The quote drawer re-prices fresh (wp_ajax_tack_quote_reprice) ──────────────
$tack_cache_price = 50.0;
( new Tack_Wholesale_Pricing( $client ) )->resolve( array( array( 'sku' => 'R', 'quantity' => 3 ) ) ); // A listing label: 51.
$tack_cache_price = 45.0; // The seller lowers the price in TackQuote.
$before           = count( $client->paths );
$tack_cache_drawer = Tack_Widget::resolve_fresh( new Tack_Wholesale_Pricing( $client ), array( array( 'sku' => 'R', 'quantity' => 3 ) ) );
// MUTATION: dropping the add_filter() in resolve_fresh() shows the cached 51 in the drawer.
check( 'cache: the drawer re-price asks TackQuote and shows the price the cart will charge (46, not the cached 51)', count( $client->paths ) === $before + 1 && 46.0 === ( $tack_cache_drawer['R|3'] ?? null ) );
check( 'cache: ...and the bypass is removed again afterwards', empty( $GLOBALS['TACK_FILTERS']['tackquote_storefront_cache_bypass'] ) );
$before = count( $client->paths );
( new Tack_Wholesale_Pricing( $client ) )->resolve( array( array( 'sku' => 'R', 'quantity' => 3 ) ) );
check( 'cache: ...so a page view after it reads the cache again', count( $client->paths ) === $before );
$widget_src = (string) file_get_contents( TACK_QUOTES_DIR . 'includes/class-tack-widget.php' );
check( 'cache: handle_reprice() prices through resolve_fresh()', 1 === preg_match( '/function handle_reprice\(\)(?:(?!\n\t}\n).)*?self::resolve_fresh\( new Tack_Wholesale_Pricing\(\), \$asks \)/s', $widget_src ) );

// ── Expired transients are swept at most hourly without an object cache ───────
unset( $GLOBALS['TACK_OPTIONS'][ Tack_Storefront_Cache::SWEEP_OPTION ] );
$GLOBALS['TACK_EXPIRED_SWEEPS'] = 0;
$tack_cache_first               = Tack_Storefront_Cache::maybe_sweep();
$tack_cache_second              = Tack_Storefront_Cache::maybe_sweep();
check( 'cache: without an object cache, expired transients are swept once, not on every request', $tack_cache_first && ! $tack_cache_second && 1 === $GLOBALS['TACK_EXPIRED_SWEEPS'] );
$GLOBALS['TACK_OPTIONS'][ Tack_Storefront_Cache::SWEEP_OPTION ] = time() - Tack_Storefront_Cache::SWEEP_INTERVAL - 1;
check( 'cache: ...and again once the hour is up', Tack_Storefront_Cache::maybe_sweep() && 2 === $GLOBALS['TACK_EXPIRED_SWEEPS'] );
$GLOBALS['TACK_EXT_OBJECT_CACHE']                               = true;
$GLOBALS['TACK_OPTIONS'][ Tack_Storefront_Cache::SWEEP_OPTION ] = 0;
check( 'cache: with a persistent object cache nothing is swept', ! Tack_Storefront_Cache::maybe_sweep() && 2 === $GLOBALS['TACK_EXPIRED_SWEEPS'] );
$GLOBALS['TACK_EXT_OBJECT_CACHE'] = false;

// ── Every settings-page option flushes on every request (WP-CLI, REST) ─────────
$tack_cache_saved_hooks    = $GLOBALS['TACK_HOOKS'];
$tack_cache_saved_settings = $GLOBALS['TACK_SETTINGS'] ?? array();
$GLOBALS['TACK_HOOKS']     = array();
$GLOBALS['TACK_SETTINGS']  = array();
( new Tack_Settings() )->register_settings();
$tack_cache_registered = array_unique( array_column( $GLOBALS['TACK_SETTINGS'], 'option' ) );
$GLOBALS['TACK_HOOKS'] = array();
$GLOBALS['TACK_EXT_OBJECT_CACHE'] = true; // No sweep while counting hooks.
Tack_Storefront_Cache::init();
$GLOBALS['TACK_EXT_OBJECT_CACHE'] = false;
$tack_cache_init_hooks    = array_column( $GLOBALS['TACK_HOOKS'], 'hook' );
$GLOBALS['TACK_HOOKS']    = $tack_cache_saved_hooks;
$GLOBALS['TACK_SETTINGS'] = $tack_cache_saved_settings;
$tack_cache_missing       = array_values( array_diff( $tack_cache_registered, Tack_Settings::option_names() ) );
check( 'cache: Tack_Settings::option_names() lists every option the settings page registers', count( $tack_cache_registered ) > 30 && array() === $tack_cache_missing, implode( ', ', $tack_cache_missing ) );
$tack_cache_unhooked = array();
foreach ( Tack_Settings::option_names() as $tack_cache_option ) {
	foreach ( array( 'add_option_', 'update_option_', 'delete_option_' ) as $tack_cache_prefix ) {
		if ( ! in_array( $tack_cache_prefix . $tack_cache_option, $tack_cache_init_hooks, true ) ) {
			$tack_cache_unhooked[] = $tack_cache_prefix . $tack_cache_option;
		}
	}
}
// MUTATION: init() without option_names() leaves only the connection options hooked.
check( 'cache: init() forgets the cache when any settings-page option changes, on every request', array() === $tack_cache_unhooked, implode( ', ', array_slice( $tack_cache_unhooked, 0, 5 ) ) );

// ── Uninstall removes the entries ──────────────────────────────────────────────
$uninstall_src = (string) file_get_contents( TACK_QUOTES_DIR . 'uninstall.php' );
check(
	'cache: uninstall deletes the _transient_tack_sfc_ and _transient_timeout_tack_sfc_ rows on every site, and both options',
	false !== strpos( $uninstall_src, "\$wpdb->esc_like( '_transient_tack_sfc_' ) . '%'" )
	&& false !== strpos( $uninstall_src, "\$wpdb->esc_like( '_transient_timeout_tack_sfc_' ) . '%'" )
	&& 1 === preg_match( '/function tack_quotes_delete_site_data\(.*?tack_quotes_delete_storefront_cache\(\);.*?\n}/s', $uninstall_src )
	&& false !== strpos( $uninstall_src, "'tack_quotes_storefront_cache_gen'," )
	&& false !== strpos( $uninstall_src, "'tack_quotes_storefront_cache_swept'," )
);

// Restore: every later suite runs with caching off, as before.
if ( null !== $tack_cache_saved_ttl_filter ) {
	$GLOBALS['TACK_PERSISTENT_FILTERS']['tackquote_storefront_cache_ttl'] = $tack_cache_saved_ttl_filter;
}
tack_test_reset_transients();
$GLOBALS['TACK_LOGGED_IN']    = false;
$GLOBALS['TACK_USER_EMAIL']   = '';
unset( $GLOBALS['TACK_CURRENT_USER_ID'] );
foreach ( $tack_cache_saved_globals as $tack_cache_global => $tack_cache_value ) {
	if ( null === $tack_cache_value ) {
		unset( $GLOBALS[ $tack_cache_global ] );
	} else {
		$GLOBALS[ $tack_cache_global ] = $tack_cache_value[0];
	}
}
foreach ( $tack_cache_saved_options as $tack_cache_option => $tack_cache_value ) {
	if ( null === $tack_cache_value ) {
		unset( $GLOBALS['TACK_OPTIONS'][ $tack_cache_option ] );
	} else {
		$GLOBALS['TACK_OPTIONS'][ $tack_cache_option ] = $tack_cache_value[0];
	}
}
