<?php
/**
 * Short-lived cache for the TackQuote answers a storefront page view needs.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY
 * ─────────────────────────────────────────────────────────────────────────────
 * A signed-in trade buyer's product page asked TackQuote, on EVERY view, for the
 * order limits, the buyer group (twice: the badge and the tax-exemption check),
 * the quantity-break ladder and the account price; a listing asked for one
 * resolved price per product card. A guest's product page asked for the order
 * limits. Each is a blocking HTTPS call to the TackQuote API, so the page waited
 * for every one of them, on every view. These answers change when a seller edits
 * a price book or a rule, not between two page views a few seconds apart.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE RULES (each one is a check in tests/storefront-cache-test.php)
 * ─────────────────────────────────────────────────────────────────────────────
 *  1. ONE CUSTOMER'S ANSWER IS NEVER ANOTHER'S. The key holds the store (its
 *     address and a fingerprint of the connected API key), the customer (the
 *     WordPress user id AND the trusted email; a guest is "guest"), the store
 *     currency (a currency switcher changes it per visitor) and every request
 *     parameter. A guest's answer is shared only with other guests, which is the
 *     same answer TackQuote gives every guest.
 *  2. MONEY AND ENFORCEMENT ARE ALWAYS FRESH. Nothing is read from or written to
 *     the cache while WooCommerce prices the cart, validates an add-to-cart or a
 *     cart update, checks the cart, validates or processes checkout, on the
 *     configured cart/checkout pages, or in any REST request (the block cart and
 *     checkout run on the Store API). So the price CHARGED, the tax exemption applied and the
 *     limits ENFORCED are TackQuote's answer at that moment, never a cached one.
 *     Only what a product page or a listing DISPLAYS can be up to TTL old.
 *  3. ONLY ANSWERS ARE KEPT. A WP_Error (an outage, a 4xx) or a "this server has
 *     no such route" null is never cached; callers keep their own failure
 *     handling exactly as before.
 *  4. SAVING SETTINGS FORGETS EVERYTHING. Every option on the TackQuote settings
 *     page, the API key and URL, and a change in the store settings TackQuote
 *     sends, bump a generation number that is part of every key, so no entry
 *     written before the change can be read after it. (Bumping beats deleting:
 *     WordPress has no wildcard transient delete, and a LIKE sweep of the options
 *     table does nothing on a site with a persistent object cache.)
 *
 * Storage is the Transients API (https://developer.wordpress.org/apis/transients/):
 * values sit in the persistent object cache when the site has one, otherwise in
 * the options table. Per that page an entry can vanish before its expiry and
 * `false` means "nothing stored", so every value is stored wrapped in an array
 * and a miss simply asks TackQuote again. Names are hashed, so they stay far
 * under the 172-character limit and carry no email.
 *
 * Without a persistent object cache an expired transient row stays in the options
 * table until it is read again or WordPress's daily `delete_expired_transients`
 * event runs. Per-visitor keys are rarely read again, so init() also runs
 * delete_expired_transients() itself, at most once an hour (SWEEP_OPTION).
 *
 * @package TackQuotes
 * @since 1.14.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Per-customer, per-currency, short-TTL cache of storefront lookups.
 */
class Tack_Storefront_Cache {

	/** Transient name prefix (`tack_sfc_<hash>`); entries expire on their own. */
	const PREFIX = 'tack_sfc_';

	/** Option holding the generation number every key includes (autoloaded). */
	const GENERATION_OPTION = 'tack_quotes_storefront_cache_gen';

	/**
	 * Option holding when init() last removed expired transients (autoloaded). Only
	 * written on sites without a persistent object cache.
	 */
	const SWEEP_OPTION = 'tack_quotes_storefront_cache_swept';

	/** Seconds between two sweeps of expired transients. */
	const SWEEP_INTERVAL = 3600;

	/**
	 * Seconds an answer is kept, per route. Prices are kept the shortest: they are
	 * only ever DISPLAYED from the cache (rule 2), but a buyer should see a seller's
	 * price change within a minute.
	 */
	const TTLS = array(
		'pricing-resolve' => 60,
		'wholesale-price' => 60,
		'quantity-breaks' => 60,
		'order-limits'    => 120,
		'buyer-group'     => 120,
	);

	/**
	 * Hooks during which nothing is cached or read from the cache (rule 2).
	 * Filters and actions alike: `doing_action()` answers for both.
	 */
	const FRESH_HOOKS = array(
		'woocommerce_before_calculate_totals',
		'woocommerce_calculate_totals',
		'woocommerce_add_to_cart_validation',
		'woocommerce_update_cart_validation',
		'woocommerce_check_cart_items',
		'woocommerce_store_api_cart_errors',
		'woocommerce_checkout_process',
		'woocommerce_after_checkout_validation',
		'woocommerce_checkout_create_order',
	);

	/**
	 * Register the invalidation hooks that must work on every request, not only on
	 * the settings page: the connection options, every option the settings page
	 * saves (so a change made with WP-CLI or the REST API forgets the cache too), and
	 * TackQuote's store settings. On a site without a persistent object cache, also
	 * remove expired transients at most hourly.
	 */
	public static function init() {
		$options = array( 'tack_quotes_api_key', 'tack_quotes_api_url' );
		if ( class_exists( 'Tack_Settings' ) ) {
			$options = array_merge( $options, Tack_Settings::option_names() );
		}
		foreach ( array_unique( $options ) as $option ) {
			self::flush_on_change( $option );
		}
		add_action( 'update_option_tack_quotes_store_settings', array( __CLASS__, 'on_store_settings_update' ), 10, 2 );
		self::maybe_sweep();
	}

	/**
	 * Remove expired transients, at most once per SWEEP_INTERVAL, on a site whose
	 * transients live in the options table. With a persistent object cache the
	 * cache evicts them itself and delete_expired_transients() would do nothing.
	 *
	 * @return bool Whether a sweep ran.
	 */
	public static function maybe_sweep() {
		if ( ! function_exists( 'wp_using_ext_object_cache' ) || wp_using_ext_object_cache() || ! function_exists( 'delete_expired_transients' ) ) {
			return false;
		}
		$now  = time();
		$last = (int) get_option( self::SWEEP_OPTION, 0 );
		if ( $last > 0 && $now - $last < self::SWEEP_INTERVAL && $last <= $now ) {
			return false;
		}
		// Written first, so concurrent requests in the same second mostly skip it.
		update_option( self::SWEEP_OPTION, $now, true );
		delete_expired_transients();
		return true;
	}

	/**
	 * Forget the cache whenever this option is added, changed or deleted.
	 *
	 * @param string $option Option name.
	 */
	public static function flush_on_change( $option ) {
		add_action( 'add_option_' . $option, array( __CLASS__, 'flush' ) );
		add_action( 'update_option_' . $option, array( __CLASS__, 'flush' ) );
		add_action( 'delete_option_' . $option, array( __CLASS__, 'flush' ) );
	}

	/**
	 * The store-settings snapshot is rewritten on every refresh (its `fetched` time
	 * moves); only a change in the SETTINGS themselves forgets the cache.
	 *
	 * @param mixed $old_value Previous snapshot.
	 * @param mixed $new_value New snapshot.
	 */
	public static function on_store_settings_update( $old_value, $new_value ) {
		$old = is_array( $old_value ) && isset( $old_value['settings'] ) ? $old_value['settings'] : null;
		$new = is_array( $new_value ) && isset( $new_value['settings'] ) ? $new_value['settings'] : null;
		if ( $old !== $new ) {
			self::flush();
		}
	}

	/**
	 * Make every entry written so far unreadable (rule 4).
	 */
	public static function flush() {
		update_option( self::GENERATION_OPTION, self::generation() + 1, true );
	}

	/**
	 * The current generation.
	 *
	 * @return int
	 */
	public static function generation() {
		return (int) get_option( self::GENERATION_OPTION, 0 );
	}

	/**
	 * The cached answer, or the one `$fetch` gets (and keeps when it is an answer).
	 *
	 * @param string   $route       One of the TTLS keys.
	 * @param array    $params      Every request parameter that changes the answer.
	 * @param string   $buyer_email The trusted email sent as the buyer, or '' for a guest.
	 * @param callable $fetch       Asks TackQuote; returns array|WP_Error|null.
	 * @return mixed What `$fetch` returns, possibly from the cache.
	 */
	public static function remember( $route, array $params, $buyer_email, callable $fetch ) {
		$ttl = self::ttl( $route );
		if ( $ttl <= 0 || self::must_be_fresh() ) {
			return $fetch();
		}
		$key = self::key( $route, $params, $buyer_email );
		$hit = get_transient( $key );
		if ( is_array( $hit ) && array_key_exists( 'v', $hit ) ) {
			return $hit['v'];
		}
		$value = $fetch();
		// Rule 3: only an answer. A WP_Error, a null or a scalar is never kept.
		if ( is_array( $value ) ) {
			set_transient( $key, array( 'v' => $value ), $ttl );
		}
		return $value;
	}

	/**
	 * Seconds to keep an answer for this route; 0 switches caching off.
	 *
	 * @param string $route Route name.
	 * @return int
	 */
	public static function ttl( $route ) {
		$ttl = isset( self::TTLS[ $route ] ) ? self::TTLS[ $route ] : 0;
		/**
		 * Filters how long a storefront lookup is cached, in seconds (0 = never).
		 *
		 * Prices are only ever DISPLAYED from this cache: the cart, checkout and
		 * every validation always ask TackQuote afresh.
		 *
		 * @since 1.14.0
		 *
		 * @param int    $ttl   Seconds.
		 * @param string $route `pricing-resolve`, `wholesale-price`, `quantity-breaks`,
		 *                      `order-limits` or `buyer-group`.
		 */
		return max( 0, (int) apply_filters( 'tackquote_storefront_cache_ttl', $ttl, $route ) );
	}

	/**
	 * Is this a moment where only a fresh answer will do (rule 2)?
	 *
	 * @return bool
	 */
	public static function must_be_fresh() {
		if ( function_exists( 'doing_action' ) ) {
			foreach ( self::FRESH_HOOKS as $hook ) {
				if ( doing_action( $hook ) ) {
					return true;
				}
			}
		}
		// The Store API (block cart and checkout) and every other REST request.
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true;
		}
		// Conditional tags only answer once the main query ran (`wp`); asked earlier
		// they are wrong and WordPress flags the call.
		if ( function_exists( 'did_action' ) && did_action( 'wp' ) ) {
			if ( ( function_exists( 'is_cart' ) && is_cart() ) || ( function_exists( 'is_checkout' ) && is_checkout() ) ) {
				return true;
			}
		}
		/**
		 * Filters whether this lookup must skip the storefront cache.
		 *
		 * @since 1.14.0
		 *
		 * @param bool $fresh True to ask TackQuote directly.
		 */
		return (bool) apply_filters( 'tackquote_storefront_cache_bypass', false );
	}

	/**
	 * The transient name for one lookup (rule 1).
	 *
	 * @param string $route       Route name.
	 * @param array  $params      Request parameters.
	 * @param string $buyer_email Trusted buyer email, or '' for a guest.
	 * @return string
	 */
	public static function key( $route, array $params, $buyer_email ) {
		$buyer_email = strtolower( trim( (string) $buyer_email ) );
		$user_id     = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$customer    = '' === $buyer_email ? 'guest' : 'u' . $user_id . '|' . $buyer_email;
		$currency    = function_exists( 'get_woocommerce_currency' ) ? strtoupper( (string) get_woocommerce_currency() ) : '';
		$store       = home_url( '/' ) . '|' . md5( (string) get_option( 'tack_quotes_api_key', '' ) ) . '|' . (string) get_option( 'tack_quotes_api_url', '' );
		$parts       = array(
			'g' => self::generation(),
			's' => $store,
			'c' => $customer,
			'$' => $currency,
			'r' => (string) $route,
			'p' => self::canonical( $route, $params ),
		);
		return self::PREFIX . wp_hash( (string) wp_json_encode( $parts ) );
	}

	/**
	 * Parameters in a fixed order, so the same lookup always has the same key.
	 *
	 * A `pricing-resolve` batch is also sorted by `sku|quantity`: TackQuote's answer
	 * names each line by its SKU and quantity and is matched that way, so the same
	 * listing shown in another order (a sort, a filter) is the same question.
	 *
	 * @param string $route  Route name.
	 * @param array  $params Parameters.
	 * @return array
	 */
	private static function canonical( $route, array $params ) {
		ksort( $params );
		if ( 'pricing-resolve' === $route && isset( $params['items'] ) && is_array( $params['items'] ) ) {
			$items = array_values( $params['items'] );
			usort(
				$items,
				function ( $a, $b ) {
					return strcmp( self::line_key( $a ), self::line_key( $b ) );
				}
			);
			$params['items'] = $items;
		}
		return $params;
	}

	/**
	 * `sku|quantity` of one batch line, the way the answer is matched.
	 *
	 * @param mixed $item Line.
	 * @return string
	 */
	private static function line_key( $item ) {
		$sku      = is_array( $item ) && isset( $item['sku'] ) ? (string) $item['sku'] : '';
		$quantity = is_array( $item ) && isset( $item['quantity'] ) ? (int) $item['quantity'] : 0;
		return $sku . '|' . $quantity;
	}
}
