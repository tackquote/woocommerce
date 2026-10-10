<?php
/**
 * Payment and shipping methods restricted by TackQuote buyer group.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHAT THIS IS FOR
 * ─────────────────────────────────────────────────────────────────────────────
 * "Net 30 is for approved trade accounts only." Every B2B store needs some
 * version of that, and WooCommerce has no concept of one: a gateway is either
 * enabled for everybody or nobody. Retail shoppers therefore see an invoice
 * option they cannot use, and the seller finds out when an unapproved buyer
 * places a 30-day order.
 *
 * TackQuote already knows which group a buyer belongs to — the badge added in
 * 1.7.0 asks for exactly that — so the group is the natural thing to gate on.
 * No new lookup is introduced here; this reuses `Tack_B2B_Notices::buyer_group()`
 * and the answer it has already cached for the request.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY THE RULE IS "ALLOW-LIST, AND EMPTY MEANS UNRESTRICTED"
 * ─────────────────────────────────────────────────────────────────────────────
 * Each restricted method names the groups that MAY use it. A method with no
 * groups named is left alone entirely.
 *
 * That default matters more than it looks. The alternative — "a method with no
 * groups is available to nobody" — turns activating this feature into an
 * immediate checkout outage, because every method starts unconfigured. A
 * merchant would switch it on, lose every payment option, and have no idea
 * why. So configuring nothing changes nothing.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * AND WHY IT FAILS OPEN
 * ─────────────────────────────────────────────────────────────────────────────
 * If TackQuote cannot be reached the buyer's group is unknown. Hiding every
 * restricted method in that case would mean a slow API removes the customer's
 * ability to pay. The unknown-group case therefore leaves the methods visible,
 * for the same reason order limits do not block on a timeout: an over-permissive
 * checkout costs a conversation, an unavailable one costs the day.
 *
 * A merchant who genuinely needs the strict reading can invert it with the
 * `tackquote_restrict_when_group_unknown` filter.
 *
 * @package TackQuotes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Filters the available payment gateways and shipping rates by buyer group.
 */
class Tack_Group_Restrictions {

	/** Master switch. */
	const OPTION_ENABLED = 'tack_quotes_enable_group_restrictions';

	/**
	 * Gateway id => comma-separated group codes allowed to use it.
	 *
	 * Stored as one option rather than one per gateway so the whole map is
	 * saved, read and removed as a unit.
	 */
	const OPTION_PAYMENT_MAP = 'tack_quotes_payment_group_map';

	/** Shipping method id => comma-separated group codes. */
	const OPTION_SHIPPING_MAP = 'tack_quotes_shipping_group_map';

	/**
	 * Supplies the buyer group.
	 *
	 * @var Tack_B2B_Notices
	 */
	private $notices;

	/**
	 * Constructor.
	 *
	 * @param Tack_B2B_Notices|null $notices Injected in tests; built here otherwise.
	 */
	public function __construct( $notices = null ) {
		$this->notices = $notices instanceof Tack_B2B_Notices ? $notices : new Tack_B2B_Notices();
	}

	/**
	 * Is the feature switched on?
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return 'yes' === get_option( self::OPTION_ENABLED, 'no' );
	}

	/**
	 * Shipping discounts per buyer group (1.10.0) master switch, default off.
	 */
	const OPTION_DISCOUNTS_ENABLED = 'tack_quotes_enable_shipping_discounts';

	/**
	 * Group code => shipping discount, one rule per line:
	 *
	 *     TIER3: free_only                 keep only the rates that already cost 0
	 *     TIER2: free | flat_rate          set these methods to 0 (no list = every method)
	 *     GOLD: percent=15 | flat_rate     take 15% off these methods (no list = every method)
	 *
	 * Plugin settings for now. Server-driven shipping rules from TackQuote are a
	 * later change; when they arrive they feed the same apply step.
	 */
	const OPTION_DISCOUNT_MAP = 'tack_quotes_shipping_discount_map';

	/** Package key that carries the buyer group into WooCommerce's rate-cache hash. */
	const PACKAGE_KEY = 'tackquote_buyer_group';

	/**
	 * Are shipping discounts switched on?
	 *
	 * @return bool
	 */
	public static function discounts_enabled() {
		return 'yes' === get_option( self::OPTION_DISCOUNTS_ENABLED, 'no' );
	}

	/**
	 * Does either half of this class have work to do?
	 *
	 * @return bool
	 */
	public static function needs_hooks() {
		return self::is_enabled() || self::discounts_enabled();
	}

	/**
	 * Register hooks.
	 *
	 * ONE `woocommerce_package_rates` callback runs both halves, in a fixed
	 * order: restrictions first (which methods this group may use at all), then
	 * discounts (what the remaining ones cost). Two independent filters would
	 * leave the order to priorities, and a discount computed on a rate the
	 * restriction then removes is harmless, but a restriction that runs after a
	 * "keep only the free rates" discount judges a list it never saw.
	 */
	public function init() {
		if ( self::is_enabled() ) {
			add_filter( 'woocommerce_available_payment_gateways', array( $this, 'filter_gateways' ), 20 );
		}
		add_filter( 'woocommerce_package_rates', array( $this, 'filter_package_rates' ), 20, 2 );
		add_filter( 'woocommerce_cart_shipping_packages', array( $this, 'tag_packages' ), 20 );
	}

	/**
	 * `woocommerce_package_rates`: restrictions, then discounts.
	 *
	 * @param array $rates   rate id => WC_Shipping_Rate.
	 * @param array $package Shipping package.
	 * @return array
	 */
	public function filter_package_rates( $rates, $package = array() ) {
		if ( self::is_enabled() ) {
			$rates = $this->filter_shipping_rates( $rates, $package );
		}
		if ( self::discounts_enabled() ) {
			$rates = $this->apply_shipping_discounts( $rates );
		}
		return $rates;
	}

	/**
	 * `woocommerce_cart_shipping_packages`: put the buyer group into each package.
	 *
	 * WooCommerce caches calculated rates in the session per package, keyed by
	 * `md5( wp_json_encode( $package ) . shipping transient version )` with only
	 * subtotal/total/package_id/package_name/rates/package_index ignored (read in
	 * `WC_Shipping::get_package_hash()`, WooCommerce 11.2.1). The package already
	 * carries the user id, so signing in recalculates; a group CHANGE for the same
	 * user would not, and the buyer would keep the previous group's rates. A key
	 * holding the group makes the hash change exactly when the answer changes.
	 * Added only when a group-dependent shipping rule exists.
	 *
	 * @param array $packages Packages.
	 * @return array
	 */
	public function tag_packages( $packages ) {
		if ( ! is_array( $packages ) || ! $this->has_shipping_rules() ) {
			return $packages;
		}
		$code  = $this->group_code();
		$value = null !== $code ? 'grouped:' . strtoupper( $code ) : $this->notices->buyer_group_status();
		foreach ( $packages as $i => $package ) {
			if ( is_array( $package ) ) {
				$packages[ $i ][ self::PACKAGE_KEY ] = $value;
			}
		}
		return $packages;
	}

	/**
	 * Is any rule configured whose outcome depends on the buyer group?
	 *
	 * @return bool
	 */
	private function has_shipping_rules() {
		if ( self::is_enabled() && '' !== trim( (string) get_option( self::OPTION_SHIPPING_MAP, '' ) ) ) {
			return true;
		}
		return self::discounts_enabled() && '' !== trim( (string) get_option( self::OPTION_DISCOUNT_MAP, '' ) );
	}

	/**
	 * Apply this buyer group's shipping discount.
	 *
	 * Only a buyer TackQuote places in a group gets one: a discount is a grant,
	 * so an unknown group, no group and guests all pay the normal rate.
	 *
	 * @param array $rates rate id => WC_Shipping_Rate.
	 * @return array
	 */
	public function apply_shipping_discounts( $rates ) {
		if ( ! is_array( $rates ) || empty( $rates ) ) {
			return $rates;
		}
		$rules = $this->parse_discount_map( (string) get_option( self::OPTION_DISCOUNT_MAP, '' ) );
		if ( empty( $rules ) ) {
			return $rates;
		}
		$code = $this->group_code();
		if ( null === $code || ! isset( $rules[ strtoupper( $code ) ] ) ) {
			return $rates;
		}
		$rule = $rules[ strtoupper( $code ) ];

		if ( 'free_only' === $rule['mode'] ) {
			$free = array();
			foreach ( $rates as $id => $rate ) {
				if ( is_object( $rate ) && method_exists( $rate, 'get_cost' ) && (float) $rate->get_cost() <= 0 ) {
					$free[ $id ] = $rate;
				}
			}
			if ( empty( $free ) ) {
				// Never empty the list: no free rate exists, so nothing is removed.
				$this->log( 'Shipping discount "free_only" found no rate that costs 0 for this package; all rates left in place.' );
				return $rates;
			}
			return $free;
		}

		foreach ( $rates as $id => $rate ) {
			if ( ! is_object( $rate ) || ! method_exists( $rate, 'get_cost' ) || ! method_exists( $rate, 'set_cost' ) ) {
				continue;
			}
			if ( ! $this->rule_covers_rate( $rule['methods'], (string) $id, $rate ) ) {
				continue;
			}
			$old = (float) $rate->get_cost();
			if ( $old <= 0 ) {
				continue;
			}
			$new = 'free' === $rule['mode'] ? 0.0 : $old * ( 100 - $rule['percent'] ) / 100;
			$this->reprice_rate( $rate, $old, $new );
		}
		return $rates;
	}

	/**
	 * Does a discount rule's method list cover this rate?
	 *
	 * Same lookup as restrictions: the full rate id (`flat_rate:3`) or the method
	 * id (`flat_rate`). An empty list covers every rate.
	 *
	 * @param string[] $methods Method or rate ids.
	 * @param string   $id      Rate id.
	 * @param object   $rate    WC_Shipping_Rate.
	 * @return bool
	 */
	private function rule_covers_rate( $methods, $id, $rate ) {
		if ( empty( $methods ) ) {
			return true;
		}
		$method = method_exists( $rate, 'get_method_id' ) ? (string) $rate->get_method_id() : '';
		if ( '' === $method ) {
			$method = (string) strtok( $id, ':' );
		}
		return in_array( $id, $methods, true ) || in_array( $method, $methods, true );
	}

	/**
	 * Set a rate's cost and scale its taxes by the same factor.
	 *
	 * A rate's `taxes` is an array of tax-rate id => amount that WooCommerce
	 * computed from the ORIGINAL cost (`WC_Tax::calc_shipping_tax( $cost, $rates )`
	 * in `WC_Shipping_Method::add_rate()`). Every WooCommerce tax rate is a
	 * percentage of the cost, compound ones included, so the tax is proportional
	 * to the cost and scaling each amount by new/old reproduces what the method
	 * would have calculated, without re-deriving the customer's tax location here.
	 * Cost 0 therefore means taxes 0, which is the "clear the taxes" free
	 * shipping needs. The cost is rounded to the store's price decimals; the
	 * taxes stay unrounded, as WooCommerce keeps them until the totals step.
	 *
	 * @param object $rate WC_Shipping_Rate.
	 * @param float  $old  Original cost (> 0).
	 * @param float  $cost New cost.
	 */
	private function reprice_rate( $rate, $old, $cost ) {
		$decimals = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;
		$cost     = max( 0.0, round( $cost, $decimals ) );
		$rate->set_cost( function_exists( 'wc_format_decimal' ) ? wc_format_decimal( $cost, $decimals ) : (string) $cost );

		if ( method_exists( $rate, 'get_taxes' ) && method_exists( $rate, 'set_taxes' ) ) {
			$factor = $cost / $old;
			$taxes  = array();
			foreach ( (array) $rate->get_taxes() as $tax_id => $amount ) {
				$taxes[ $tax_id ] = 0.0 === $factor ? 0 : (float) $amount * $factor;
			}
			$rate->set_taxes( $taxes );
		}
	}

	/**
	 * Parse the stored discount rules.
	 *
	 * Unreadable lines are skipped (a typo grants nothing rather than breaking
	 * checkout); a later line for the same code wins, as in `parse_map()`.
	 *
	 * @param string $raw Stored value.
	 * @return array<string, array{mode:string,percent:float,methods:string[]}>
	 */
	public function parse_discount_map( $raw ) {
		$out = array();
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return $out;
		}
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || 0 === strpos( $line, '#' ) ) {
				continue;
			}
			$parts = explode( ':', $line, 2 );
			if ( 2 !== count( $parts ) ) {
				continue;
			}
			$code = strtoupper( trim( $parts[0] ) );
			if ( '' === $code ) {
				continue;
			}
			$halves  = explode( '|', $parts[1], 2 );
			$spec    = strtolower( trim( $halves[0] ) );
			$methods = array();
			if ( isset( $halves[1] ) ) {
				foreach ( explode( ',', $halves[1] ) as $method ) {
					$method = trim( $method );
					if ( '' !== $method ) {
						$methods[] = $method;
					}
				}
			}

			if ( 'free_only' === $spec || 'free' === $spec ) {
				$out[ $code ] = array(
					'mode'    => $spec,
					'percent' => 100.0,
					'methods' => $methods,
				);
				continue;
			}
			if ( preg_match( '/^percent\s*=\s*([0-9]+(?:\.[0-9]+)?)$/', $spec, $m ) ) {
				$pct = (float) $m[1];
				if ( $pct <= 0 || $pct > 100 ) {
					continue;
				}
				$out[ $code ] = array(
					'mode'    => 100.0 === $pct ? 'free' : 'percent',
					'percent' => $pct,
					'methods' => $methods,
				);
			}
		}
		return $out;
	}

	/**
	 * Format one rule back into its stored line.
	 *
	 * @param string $code Group code.
	 * @param array  $rule array{mode:string,percent:float,methods:string[]}.
	 * @return string
	 */
	public static function format_discount_line( $code, $rule ) {
		$spec = 'percent' === $rule['mode'] ? 'percent=' . rtrim( rtrim( number_format( (float) $rule['percent'], 2, '.', '' ), '0' ), '.' ) : $rule['mode'];
		$line = $code . ': ' . $spec;
		if ( 'free_only' !== $rule['mode'] && ! empty( $rule['methods'] ) ) {
			$line .= ' | ' . implode( ', ', $rule['methods'] );
		}
		return $line;
	}

	/**
	 * Remove payment gateways this buyer's group may not use.
	 *
	 * @param array $gateways id => WC_Payment_Gateway.
	 * @return array
	 */
	public function filter_gateways( $gateways ) {
		if ( ! is_array( $gateways ) || empty( $gateways ) ) {
			return $gateways;
		}
		$map = $this->parse_map( get_option( self::OPTION_PAYMENT_MAP, '' ) );
		if ( empty( $map ) ) {
			return $gateways;
		}

		$allowed = $this->group_code();

		$out = array();
		foreach ( $gateways as $id => $gateway ) {
			if ( $this->permitted( (string) $id, $map, $allowed ) ) {
				$out[ $id ] = $gateway;
			}
		}

		/*
		 * NEVER return an empty gateway list. A checkout with no payment method
		 * is a checkout nobody can complete, and a misconfiguration should
		 * degrade to "too many options" rather than "no way to pay". If the
		 * rules would remove everything, the rules are wrong — keep the
		 * original set and say so in the log.
		 */
		if ( empty( $out ) ) {
			$this->log(
				'Buyer-group restrictions would have removed EVERY payment gateway; leaving them all enabled. Check the group codes on the TackQuote settings screen.'
			);
			return $gateways;
		}

		return $out;
	}

	/**
	 * Remove shipping rates this buyer's group may not use.
	 *
	 * @param array $rates   rate id => WC_Shipping_Rate.
	 * @param array $package Shipping package.
	 * @return array
	 */
	public function filter_shipping_rates( $rates, $package = array() ) {
		unset( $package );
		if ( ! is_array( $rates ) || empty( $rates ) ) {
			return $rates;
		}
		$map = $this->parse_map( get_option( self::OPTION_SHIPPING_MAP, '' ) );
		if ( empty( $map ) ) {
			return $rates;
		}

		$allowed = $this->group_code();

		$out = array();
		foreach ( $rates as $id => $rate ) {
			/*
			 * A rate id is `method_id:instance_id`, and merchants configure the
			 * METHOD. So the rule is looked up by full id first and by method
			 * id second, then evaluated ONCE.
			 *
			 * The obvious `permitted(id) || permitted(method)` is wrong, and
			 * this file's own test caught it: an unconfigured id is "permitted"
			 * by design, so the OR short-circuits to true and the method's rule
			 * is never consulted — every restriction on a method id silently
			 * did nothing.
			 */
			$method = strtok( (string) $id, ':' );
			$key    = isset( $map[ (string) $id ] ) ? (string) $id : (string) $method;
			if ( $this->permitted( $key, $map, $allowed ) ) {
				$out[ $id ] = $rate;
			}
		}

		// Same reasoning as gateways: no shipping rate at all blocks checkout.
		if ( empty( $out ) ) {
			$this->log(
				'Buyer-group restrictions would have removed EVERY shipping rate; leaving them all enabled. Check the group codes on the TackQuote settings screen.'
			);
			return $rates;
		}

		return $out;
	}

	/**
	 * May this method be used?
	 *
	 * @param string      $id      Gateway or shipping method id.
	 * @param array       $map     id => array of group codes.
	 * @param string|null $allowed The buyer's group code, or null when unknown.
	 * @return bool
	 */
	private function permitted( $id, $map, $allowed ) {
		if ( ! isset( $map[ $id ] ) || empty( $map[ $id ] ) ) {
			// Unconfigured means unrestricted — see the class docblock.
			return true;
		}

		if ( null === $allowed ) {
			/*
			 * ── "WE COULD NOT ASK" AND "WE ASKED, AND THE ANSWER IS NO" ────────
			 *
			 * These need OPPOSITE defaults, and collapsing them was a real hole:
			 * a buyer TackQuote had definitively placed in no group was treated
			 * exactly like an outage, and therefore handed every restricted
			 * payment method. Net-30 for anyone who registers.
			 *
			 *   unavailable  TackQuote unreachable, or no API key. FAIL OPEN —
			 *                a checkout that dies because a supplier's API is
			 *                slow costs the day's revenue.
			 *   none         TackQuote answered: this buyer is in no group.
			 *   anonymous    Nobody is signed in, or the identity is not
			 *                trusted (see Tack_B2B_Notices::buyer_email()).
			 *                Both are real answers, so a method restricted to a
			 *                named group is NOT for them. FAIL CLOSED.
			 */
			$status = $this->notices->buyer_group_status();
			if ( 'unavailable' !== $status ) {
				return false;
			}

			/**
			 * Filters whether a restricted method is hidden when TackQuote could
			 * not be reached at all.
			 *
			 * Default false: leave it visible, so an outage does not remove the
			 * customer's ability to pay. This no longer covers "the buyer has no
			 * group" — that is a real answer and is refused regardless.
			 *
			 * @since 1.7.1
			 *
			 * @param bool   $restrict Whether to hide the method.
			 * @param string $id       Gateway or shipping method id.
			 */
			return ! apply_filters( 'tackquote_restrict_when_group_unknown', false, $id );
		}

		/*
		 * Compared case-INSENSITIVELY, and the codes were upper-cased when the
		 * map was parsed. A merchant typing `bacs: tier3` while TackQuote
		 * returns `TIER3` would otherwise match nothing — and because the rule
		 * then fails for EVERY group, that gateway silently disappears for
		 * every customer, permanently. The "never empty the list" guard does
		 * not catch it either: that only fires when a rule removes every
		 * gateway, not when one gateway is wrongly blocked for everyone.
		 */
		return in_array( strtoupper( $allowed ), $map[ $id ], true );
	}

	/**
	 * The buyer's group code, or null when unknown.
	 *
	 * @return string|null
	 */
	private function group_code() {
		$group = $this->notices->buyer_group();
		if ( ! is_array( $group ) || empty( $group['code'] ) ) {
			return null;
		}
		return (string) $group['code'];
	}

	/**
	 * Parse the stored map.
	 *
	 * Stored one rule per line as `method_id: GROUP_A, GROUP_B`, because a
	 * merchant edits this in a textarea and JSON in a textarea is a support
	 * ticket. Blank lines and unparsable lines are skipped rather than
	 * throwing — a typo must not take the checkout down.
	 *
	 * @param string $raw Stored value.
	 * @return array<string, string[]>
	 */
	public function parse_map( $raw ) {
		$out = array();
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return $out;
		}

		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || 0 === strpos( $line, '#' ) ) {
				continue;
			}
			$parts = explode( ':', $line, 2 );
			if ( count( $parts ) < 2 ) {
				continue;
			}
			$id = trim( $parts[0] );
			if ( '' === $id ) {
				continue;
			}
			$groups = array();
			foreach ( explode( ',', $parts[1] ) as $code ) {
				// Upper-cased here so the comparison in `permitted()` can be a
				// strict in_array against a single normalised form.
				$code = strtoupper( trim( $code ) );
				if ( '' !== $code ) {
					$groups[] = $code;
				}
			}
			if ( ! empty( $groups ) ) {
				$out[ $id ] = $groups;
			}
		}

		return $out;
	}

	/**
	 * Write to WooCommerce's log under the plugin's own source.
	 *
	 * @param string $message Message.
	 */
	private function log( $message ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		$logger = wc_get_logger();
		if ( $logger ) {
			$logger->warning( $message, array( 'source' => 'tackquote' ) );
		}
	}
}
