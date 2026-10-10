<?php
/**
 * Product categories hidden from particular TackQuote buyer groups (1.10.0).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHAT THIS IS FOR
 * ─────────────────────────────────────────────────────────────────────────────
 * "The trade-only range is for approved accounts", "retail customers should not
 * see the bulk packs". BigCommerce does this with category access per customer
 * group; WooCommerce has no such concept. TackQuote already knows which group a
 * buyer belongs to, so the group is what this keys on, read exactly the way the
 * payment/shipping restrictions read it: `Tack_B2B_Notices::buyer_group()` on
 * the SAME shared instance, so one lookup per request serves every feature.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE RULE
 * ─────────────────────────────────────────────────────────────────────────────
 * One line per product category, stored in the same `id: CODE, CODE` format the
 * 1.8.0 restriction grid writes (so the same parser and the same grid serve it):
 *
 *     <product_cat term id>: TIER2, @GUESTS
 *
 * A ticked group means "this category is HIDDEN from that group". The pseudo
 * code `@GUESTS` (which no typed group code can collide with: typed codes are
 * limited to letters, digits, `.`, `_` and `-`) stands for everybody outside a
 * group: signed-out visitors, signed-in customers TackQuote places in no group,
 * and customers whose self-changed email is not re-confirmed (the 1.7.1 trust
 * rule; `Tack_B2B_Notices` answers `anonymous` for them).
 *
 * Term IDs, not slugs: renaming a slug must not silently publish a trade range.
 * Child categories inherit the rule of their parent.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * AND WHEN THE GROUP CANNOT BE READ
 * ─────────────────────────────────────────────────────────────────────────────
 * TackQuote unreachable (or no API key): the catalogue is SHOWN by default, for
 * the same reason restrictions fail open. A merchant who needs the strict reading
 * ticks "Hide when the buyer group is unknown", which hides every category that
 * is hidden from anyone. Signed-out visitors never need a lookup: they are
 * guests by definition, so an outage cannot un-hide a guest rule.
 *
 * Shop managers (`manage_woocommerce`) are never filtered, and nothing here runs
 * in wp-admin or on the WooCommerce REST API (`/wc/v3`). The Store API
 * (`/wc/store`) is filtered, because that is what the product blocks read.
 *
 * @package TackQuotes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Hides product categories per buyer group across loops, search, blocks, the
 * product page, related/up-sell/cross-sell lists and the cart.
 */
class Tack_Catalog_Visibility {

	/** Master switch (default off). */
	const OPTION_ENABLED = 'tack_quotes_enable_catalog_visibility';

	/** `term_id: CODE, CODE` lines. */
	const OPTION_MAP = 'tack_quotes_catalog_visibility_map';

	/** "Hide when the buyer group is unknown" (default off: show). */
	const OPTION_HIDE_WHEN_UNKNOWN = 'tack_quotes_hide_catalog_when_group_unknown';

	/** Everybody outside a group: guests, ungrouped and untrusted customers. */
	const GUESTS = '@GUESTS';

	/** The taxonomy the rules are written against. */
	const TAXONOMY = 'product_cat';

	/**
	 * Supplies the buyer group (shared with the other group features).
	 *
	 * @var Tack_B2B_Notices
	 */
	private $notices;

	/**
	 * Hidden term ids for this request, or null when not yet resolved.
	 *
	 * @var int[]|null
	 */
	private $hidden = null;

	/**
	 * Per product id: hidden or not, for this request.
	 *
	 * @var array<int, bool>
	 */
	private $product_cache = array();

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
	 * Register hooks. Each was read in the installed WooCommerce 11.2.1 source:
	 * `woocommerce_product_is_visible` ( $visible, $product_id ) from
	 * `WC_Product::is_visible()`, which `wc_products_array_filter_visible()`
	 * applies to related, up-sell and cross-sell lists; `woocommerce_is_purchasable`
	 * ( $purchasable, $product ) and `woocommerce_variation_is_purchasable`;
	 * `woocommerce_related_products` ( $ids, $product_id, $args ).
	 */
	public function init() {
		add_action( 'pre_get_posts', array( $this, 'filter_query' ), 20 );
		add_filter( 'woocommerce_product_is_visible', array( $this, 'filter_is_visible' ), 20, 2 );
		add_filter( 'woocommerce_is_purchasable', array( $this, 'filter_purchasable' ), 20, 2 );
		add_filter( 'woocommerce_variation_is_purchasable', array( $this, 'filter_purchasable' ), 20, 2 );
		add_filter( 'woocommerce_related_products', array( $this, 'filter_related' ), 20, 2 );
		add_action( 'template_redirect', array( $this, 'block_hidden_product' ), 5 );

		/*
		 * WooCommerce itself drops a non-purchasable line when it loads the cart
		 * from the session (`WC_Cart_Session::get_cart_from_session()`, "...has
		 * been removed from your cart because it can no longer be purchased").
		 * This pass runs after it, so a line that survives that (a cart restored
		 * some other way) is still removed, with a notice naming why.
		 */
		add_action( 'woocommerce_cart_loaded_from_session', array( $this, 'remove_hidden_cart_items' ), 20 );

		/*
		 * The Store API single-product routes (`/wc/store/v1/products/{id}` and
		 * `/products/{slug}`) look the product up with `wc_get_product()` and
		 * check only `is_publicly_viewable()` (publish status), with no
		 * `woocommerce_store_api_*` filter in between (WooCommerce 11.2.1,
		 * `src/StoreApi/Routes/V1/ProductsById.php` and `ProductsBySlug.php`).
		 * So the guard sits on WordPress's own `rest_pre_dispatch`, which the
		 * REST server applies to every request and to every request inside a
		 * `/batch` call (`WP_REST_Server::dispatch()` and
		 * `serve_batch_request_v1()`), and answers the same 404 those routes
		 * give for a product that does not exist.
		 */
		add_filter( 'rest_pre_dispatch', array( $this, 'guard_store_api_product' ), 10, 3 );
	}

	/**
	 * Store API listing routes that live under `/products/` and are not a slug.
	 */
	const STORE_API_PRODUCT_SUBROUTES = array( 'attributes', 'brands', 'categories', 'collection-data', 'reviews', 'tags' );

	/**
	 * `rest_pre_dispatch`: a hidden product read through the Store API by id or
	 * slug gets the route's own "invalid product" 404.
	 *
	 * @param mixed           $result  Response so far (null = not short-circuited).
	 * @param WP_REST_Server  $server  Server (unused).
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function guard_store_api_product( $result, $server = null, $request = null ) {
		unset( $server );
		if ( null !== $result || ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) {
			return $result;
		}
		if ( ! preg_match( '#^/wc/store(?:/v[0-9]+)?/products/([^/]+)/?$#', (string) $request->get_route(), $m ) ) {
			return $result;
		}
		$segment = rawurldecode( $m[1] );
		if ( in_array( $segment, self::STORE_API_PRODUCT_SUBROUTES, true ) ) {
			return $result;
		}
		// The route is the Store API by construction, so only the shopper context applies.
		if ( ! $this->shopper_context() ) {
			return $result;
		}

		if ( ctype_digit( $segment ) ) {
			$id   = (int) $segment;
			$code = 'woocommerce_rest_product_invalid_id';
			$text = __( 'Invalid product ID.', 'tackquote' );
		} else {
			$id   = $this->product_id_by_slug( $segment );
			$code = 'woocommerce_rest_product_invalid_slug';
			$text = __( 'Invalid product slug.', 'tackquote' );
		}
		if ( $id <= 0 || ! $this->is_hidden_product( $id ) ) {
			return $result;
		}
		return new WP_Error( $code, $text, array( 'status' => 404 ) );
	}

	/**
	 * Product (or variation) id for a slug, the way the Store API resolves it.
	 *
	 * @param string $slug Slug.
	 * @return int
	 */
	private function product_id_by_slug( $slug ) {
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			return 0;
		}
		$post = get_page_by_path( $slug, OBJECT, 'product' );
		if ( is_object( $post ) && ! empty( $post->ID ) ) {
			return (int) $post->ID;
		}
		$ids = get_posts(
			array(
				'name'             => $slug,
				'post_type'        => 'product_variation',
				'post_status'      => 'any',
				'fields'           => 'ids',
				'numberposts'      => 1,
				'suppress_filters' => true,
			)
		);
		return empty( $ids ) ? 0 : (int) $ids[0];
	}

	/**
	 * Should this request be filtered at all?
	 *
	 * @return bool
	 */
	public function applies() {
		if ( ! $this->shopper_context() ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST && ! $this->is_store_api_request() ) {
			return false;
		}
		return true;
	}

	/**
	 * The ONE context gate: is a shopper on the other end of this request?
	 *
	 * Every enforcement path goes through it (directly for the Store API
	 * route guard, through `applies()` for everything else), so there is a
	 * single definition rather than copies that drift.
	 *
	 * Not a shopper, so never filtered:
	 *   - wp-admin (outside admin-ajax);
	 *   - WP-Cron and Action Scheduler runs (`wp_doing_cron()`). No user is
	 *     signed in there, so the request reads as a GUEST, and every product
	 *     hidden from guests would vanish from other plugins' feeds and sync
	 *     jobs and from this plugin's own scheduled work;
	 *   - WP-CLI, for the same reason;
	 *   - store managers (`manage_woocommerce`).
	 *
	 * @return bool
	 */
	public function shopper_context() {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return false;
		}
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return false;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return false;
		}
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Is this a Store API (`/wc/store/`) request?
	 *
	 * @return bool
	 */
	private function is_store_api_request() {
		if ( function_exists( 'WC' ) && is_object( WC() ) && method_exists( WC(), 'is_store_api_request' ) ) {
			return (bool) WC()->is_store_api_request();
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared, never output.
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		return false !== strpos( $uri, '/wc/store/' );
	}

	/**
	 * The product category ids hidden from the current shopper.
	 *
	 * @return int[]
	 */
	public function hidden_term_ids() {
		if ( null !== $this->hidden ) {
			return $this->hidden;
		}
		$this->hidden = array();

		$restrictions = new Tack_Group_Restrictions( $this->notices );
		$map          = $restrictions->parse_map( (string) get_option( self::OPTION_MAP, '' ) );
		if ( empty( $map ) ) {
			return $this->hidden;
		}

		$audience = $this->audience();
		$ids      = array();
		foreach ( $map as $term_id => $codes ) {
			$term_id = (int) $term_id;
			if ( $term_id <= 0 ) {
				continue;
			}
			if ( null === $audience ) {
				// Unknown group, and the merchant asked for the strict reading.
				$ids[] = $term_id;
				continue;
			}
			if ( in_array( $audience, $codes, true ) ) {
				$ids[] = $term_id;
			}
		}

		if ( null === $audience && ! $this->hide_when_unknown() ) {
			$ids = array();
		}

		// A rule on a parent category covers its children.
		$all = array();
		foreach ( $ids as $id ) {
			$all[] = $id;
			if ( function_exists( 'get_term_children' ) ) {
				$children = get_term_children( $id, self::TAXONOMY );
				if ( is_array( $children ) ) {
					foreach ( $children as $child ) {
						$all[] = (int) $child;
					}
				}
			}
		}

		$this->hidden = array_values( array_unique( array_filter( $all ) ) );
		return $this->hidden;
	}

	/**
	 * Who is shopping: an upper-cased group code, `@GUESTS`, or null when the
	 * group could not be read.
	 *
	 * @return string|null
	 */
	private function audience() {
		if ( ! is_user_logged_in() ) {
			return self::GUESTS;
		}
		$status = $this->notices->buyer_group_status();
		if ( 'grouped' === $status ) {
			$group = $this->notices->buyer_group();
			if ( is_array( $group ) && ! empty( $group['code'] ) ) {
				return strtoupper( (string) $group['code'] );
			}
			// Grouped without a code cannot match a rule, so treat it as outside.
			return self::GUESTS;
		}
		if ( 'none' === $status || 'anonymous' === $status ) {
			return self::GUESTS;
		}
		return null;
	}

	/**
	 * Hide when the group is unknown? Option first, then the filter.
	 *
	 * @return bool
	 */
	private function hide_when_unknown() {
		$default = 'yes' === get_option( self::OPTION_HIDE_WHEN_UNKNOWN, 'no' );

		/**
		 * Filters whether hidden categories stay hidden when TackQuote could not
		 * be reached (or no API key is saved).
		 *
		 * @since 1.10.0
		 *
		 * @param bool $hide Default: the "Hide when the buyer group is unknown" setting.
		 */
		return (bool) apply_filters( 'tackquote_hide_catalog_when_group_unknown', $default );
	}

	/**
	 * Is this product (or variation) in a hidden category?
	 *
	 * @param WC_Product|int $product Product object or id.
	 * @return bool
	 */
	public function is_hidden_product( $product ) {
		$hidden = $this->hidden_term_ids();
		if ( empty( $hidden ) ) {
			return false;
		}

		$id = 0;
		if ( is_object( $product ) ) {
			// A variation carries no categories of its own: use its parent's.
			$parent = method_exists( $product, 'get_parent_id' ) ? (int) $product->get_parent_id() : 0;
			$id     = $parent > 0 ? $parent : ( method_exists( $product, 'get_id' ) ? (int) $product->get_id() : 0 );
		} else {
			$id = (int) $product;
		}
		if ( $id <= 0 ) {
			return false;
		}
		if ( isset( $this->product_cache[ $id ] ) ) {
			return $this->product_cache[ $id ];
		}

		$terms = function_exists( 'wc_get_product_term_ids' ) ? wc_get_product_term_ids( $id, self::TAXONOMY ) : array();
		if ( empty( $terms ) && function_exists( 'wp_get_post_parent_id' ) ) {
			// An id handed to us may itself be a variation.
			$parent = (int) wp_get_post_parent_id( $id );
			if ( $parent > 0 && function_exists( 'wc_get_product_term_ids' ) ) {
				$terms = wc_get_product_term_ids( $parent, self::TAXONOMY );
			}
		}

		$is_hidden                  = ! empty( array_intersect( array_map( 'intval', (array) $terms ), $hidden ) );
		$this->product_cache[ $id ] = $is_hidden;
		return $is_hidden;
	}

	/**
	 * `pre_get_posts`: exclude hidden categories from product lists.
	 *
	 * Applied to every front-end or Store API query that lists products, not
	 * only the main one: the Product Collection block and the Store API run
	 * their own `WP_Query`. Singular queries are left alone so the product page
	 * reaches `block_hidden_product()` and answers a deliberate 404.
	 *
	 * @param WP_Query $query The query.
	 */
	public function filter_query( $query ) {
		if ( ! is_object( $query ) || ! $this->applies() ) {
			return;
		}
		if ( $query->is_singular() || ! $this->targets_products( $query ) ) {
			return;
		}
		$hidden = $this->hidden_term_ids();
		if ( empty( $hidden ) ) {
			return;
		}

		$tax_query = $query->get( 'tax_query' );
		if ( ! is_array( $tax_query ) ) {
			$tax_query = array();
		}
		$tax_query[] = array(
			'taxonomy'         => self::TAXONOMY,
			'field'            => 'term_id',
			'terms'            => $hidden,
			'operator'         => 'NOT IN',
			// Children are already expanded in hidden_term_ids().
			'include_children' => false,
		);
		$query->set( 'tax_query', $tax_query );
	}

	/**
	 * Does this query list products?
	 *
	 * @param WP_Query $query The query.
	 * @return bool
	 */
	private function targets_products( $query ) {
		$post_type = $query->get( 'post_type' );
		if ( 'product' === $post_type || ( is_array( $post_type ) && in_array( 'product', $post_type, true ) ) ) {
			return true;
		}
		if ( $query->is_main_query() && $query->is_search() && ( '' === $post_type || 'any' === $post_type || null === $post_type ) ) {
			// A site search lists products too; NOT IN on product_cat leaves posts alone.
			return true;
		}
		if ( $query->is_tax() && isset( $query->tax_query ) && is_object( $query->tax_query ) && isset( $query->tax_query->queried_terms ) ) {
			foreach ( array_keys( (array) $query->tax_query->queried_terms ) as $taxonomy ) {
				if ( function_exists( 'is_object_in_taxonomy' ) && is_object_in_taxonomy( 'product', $taxonomy ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * `woocommerce_product_is_visible`.
	 *
	 * @param bool $visible    Visible so far.
	 * @param int  $product_id Product id.
	 * @return bool
	 */
	public function filter_is_visible( $visible, $product_id = 0 ) {
		if ( ! $visible || ! $this->applies() ) {
			return $visible;
		}
		return ! $this->is_hidden_product( (int) $product_id );
	}

	/**
	 * `woocommerce_is_purchasable` and `woocommerce_variation_is_purchasable`.
	 *
	 * @param bool       $purchasable Purchasable so far.
	 * @param WC_Product $product     Product.
	 * @return bool
	 */
	public function filter_purchasable( $purchasable, $product = null ) {
		if ( ! $purchasable || ! is_object( $product ) || ! $this->applies() ) {
			return $purchasable;
		}
		return ! $this->is_hidden_product( $product );
	}

	/**
	 * `woocommerce_related_products`: drop hidden ids before the list is sliced.
	 *
	 * @param int[] $ids        Related product ids.
	 * @param int   $product_id The product being viewed.
	 * @return int[]
	 */
	public function filter_related( $ids, $product_id = 0 ) {
		unset( $product_id );
		if ( ! is_array( $ids ) || ! $this->applies() ) {
			return $ids;
		}
		$out = array();
		foreach ( $ids as $id ) {
			if ( ! $this->is_hidden_product( (int) $id ) ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	/**
	 * `template_redirect`: a hidden product's own URL answers 404, not the page.
	 *
	 * @return bool Whether the request was turned into a 404 (for tests).
	 */
	public function block_hidden_product() {
		if ( ! $this->applies() || ! function_exists( 'is_singular' ) || ! is_singular( 'product' ) ) {
			return false;
		}
		$id = (int) get_queried_object_id();
		if ( $id <= 0 || ! $this->is_hidden_product( $id ) ) {
			return false;
		}

		global $wp_query;
		if ( is_object( $wp_query ) && method_exists( $wp_query, 'set_404' ) ) {
			$wp_query->set_404();
		}
		status_header( 404 );
		nocache_headers();
		return true;
	}

	/**
	 * `woocommerce_cart_loaded_from_session`: remove lines this shopper may no
	 * longer see, and say so.
	 *
	 * @param WC_Cart $cart The cart.
	 * @return int How many lines were removed.
	 */
	public function remove_hidden_cart_items( $cart ) {
		if ( ! is_object( $cart ) || ! method_exists( $cart, 'get_cart' ) || ! $this->applies() ) {
			return 0;
		}
		$removed = 0;
		foreach ( (array) $cart->get_cart() as $key => $item ) {
			$product = isset( $item['data'] ) ? $item['data'] : null;
			if ( ! is_object( $product ) || ! $this->is_hidden_product( $product ) ) {
				continue;
			}
			$cart->remove_cart_item( $key );
			++$removed;
			if ( function_exists( 'wc_add_notice' ) ) {
				$name = method_exists( $product, 'get_name' ) ? (string) $product->get_name() : '';
				wc_add_notice(
					sprintf(
						/* translators: %s: product name. */
						__( '%s was removed from your cart because it is not available to your account.', 'tackquote' ),
						$name
					),
					'notice'
				);
			}
		}
		return $removed;
	}
}
