<?php
/**
 * Uninstall handler — removes everything this plugin stores. Runs only on delete.
 *
 * @package TackQuotes
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Every option the plugin creates.
 *
 * This list was missing five of them — the three button labels added in 1.2.0/1.3.0 and the
 * two product-page visibility toggles — all of which `Tack_Quotes::activate()` writes, so a
 * delete-and-reinstall left stale values behind while README.md claimed "no data left
 * behind". Anything added to `activate()` has to be added here in the same change.
 */
$tack_quotes_options = array(
	'tack_quotes_api_key',
	'tack_quotes_api_url',
	'tack_quotes_button_label',
	'tack_quotes_request_button_label',
	'tack_quotes_checkout_button_label',
	'tack_quotes_show_add_to_quote',
	'tack_quotes_show_request_quote',
	'tack_quotes_enable_widget',
	'tack_quotes_enable_order_sync',
	// Written by activation up to 1.12.0 and never read; removed so old rows go too.
	'tack_quotes_schema_version',

	/*
	 * Everything below was added in 1.6.0 and later and was never added HERE,
	 * so an uninstall left thirteen options behind while the readme promised
	 * it "removes every option above". Nothing visibly broke — orphaned rows
	 * in wp_options are silent — which is exactly why it went unnoticed
	 * through three releases.
	 *
	 * The names are written out literally rather than read from the class
	 * constants on purpose: WordPress loads uninstall.php on its own, with
	 * WP_UNINSTALL_PLUGIN defined and the plugin's own files NOT loaded, so
	 * `Tack_Group_Restrictions::OPTION_PAYMENT_MAP` would be a fatal error
	 * here. If you add an option, add it in both places.
	 */

	// Quote-only (B2B catalog) store mode — 1.5.0.
	'tack_quotes_store_mode',
	'tack_quotes_quote_only_scope',
	'tack_quotes_quote_only_roles',
	'tack_quotes_hide_prices',
	'tack_quotes_hidden_price_text',

	// B2B pricing — 1.6.0.
	'tack_quotes_enable_wholesale_pricing',
	'tack_quotes_show_quantity_breaks',

	// Order limits + buyer group badge — 1.7.0.
	'tack_quotes_enable_order_limits',
	'tack_quotes_enable_buyer_group',

	// Payment/shipping restrictions by buyer group — 1.7.0, 1.8.0.
	'tack_quotes_enable_group_restrictions',
	'tack_quotes_payment_group_map',
	'tack_quotes_shipping_group_map',
	'tack_quotes_buyer_group_codes',

	// Order-sync circuit breaker (Tack_Sync_Gate::OPTION) — added after 1.8.1.
	'tack_quotes_order_sync_block',

	// Storefront application forms (Tack_Storefront_Forms) — 1.10.0.
	'tack_quotes_wholesale_form_slug',
	'tack_quotes_enable_wholesale_account_tab',
	'tack_quotes_enable_net_terms_tab',
	'tack_quotes_rewrite_version',
	'tack_quotes_apply_tax_exempt',

	// "Net terms (TackQuote)" gateway settings, incl. the PO-number switch (Tack_Gateway_Net_Terms) — 1.10.0.
	// WooCommerce stores a gateway's settings as `woocommerce_{gateway id}_settings`.
	'woocommerce_tackquote_net_terms_settings',

	// Catalogue visibility, shipping discounts per group and the role mirror — 1.10.0.
	'tack_quotes_enable_catalog_visibility',
	'tack_quotes_catalog_visibility_map',
	'tack_quotes_hide_catalog_when_group_unknown',
	'tack_quotes_enable_shipping_discounts',
	'tack_quotes_shipping_discount_map',
	'tack_quotes_enable_role_mirror',
	'tack_quotes_mirror_roles_created',

	// The quote-request attachments switch (Tack_Attachments::OPTION_ENABLED), 1.10.0.
	'tack_quotes_enable_attachments',

	// Storefront layout: card and cart buttons, quote page, floating launcher
	// (Tack_Widget::OPT_*), 1.10.0.
	'tack_quotes_card_buttons',
	'tack_quotes_cart_quote_button',
	'tack_quotes_cart_button_label',
	'tack_quotes_quote_button_opens',
	'tack_quotes_quote_page_url',
	'tack_quotes_fab_position',
	'tack_quotes_fab_offset_x',
	'tack_quotes_fab_offset_y',
	'tack_quotes_fab_pages',
	'tack_quotes_fab_label',
	'tack_quotes_fab_icon_only',
	'tack_quotes_fab_show_count',
	'tack_quotes_fab_size',
	'tack_quotes_fab_hide_mobile',
	// 1.10.0 Storefront > Styling.
	'tack_quotes_theme_styles_only',
	'tack_quotes_accent_color',

	// Connect with TackQuote (Tack_Connect::OPTION_*), 1.11.0: the per-site secret
	// that signs the connect state, how the saved key arrived, and when.
	'tack_quotes_connect_secret',
	'tack_quotes_connected_via',
	'tack_quotes_connected_at',

	// 1.12.0: the disconnect stand-down state (Tack_Standing::OPTION) and WooCommerce's
	// id of the REST key TackQuote holds (Tack_Rest_Keys::OPTION_WC_KEY_ID).
	'tack_quotes_standing',
	'tack_quotes_wc_rest_key_id',

	// 1.13.0: the store settings TackQuote decides (Tack_Store_Settings::OPTION) and the
	// "turn off on this store" boxes (Tack_Store_Settings::features() `off`).
	'tack_quotes_store_settings',
	// 1.14.0: the storefront cache's generation (Tack_Storefront_Cache::GENERATION_OPTION)
	// and when it last swept expired transients (SWEEP_OPTION). Its `tack_sfc_*` entries
	// have no fixed name; tack_quotes_delete_storefront_cache() removes them.
	'tack_quotes_storefront_cache_gen',
	'tack_quotes_storefront_cache_swept',
	'tack_quotes_off_order_sync',
	'tack_quotes_off_prices',
	'tack_quotes_off_order_limits',
	'tack_quotes_off_buyer_group',
	'tack_quotes_off_tax_exempt',
);

/**
 * User meta the plugin writes (Tack_B2B_Notices email-trust guard, Tack_Role_Mirror,
 * Tack_Storefront_Forms' pending wholesale application date).
 * `_tack_known_email` is a copy of the customer's own address, so it must not outlive
 * the plugin.
 *
 * `_tack_email_unverified` is deliberately KEPT. It holds no personal data, and deleting
 * it would make a reinstalled plugin trust every self-changed address again, handing that
 * account another buyer's prices and payment terms.
 */
$tack_quotes_user_meta = array(
	'_tack_known_email',
	'_tack_mirrored_roles',
	'_tack_role_mirror_checked',
	'_tack_wholesale_applied',
	// 1.12.0: the per-user secret of the embedded TackQuote dashboard (Tack_Embed_Admin).
	// Deleting it makes every WordPress user link their TackQuote login again after a reinstall.
	'_tackquote_embed_secret',
	// 1.13.0: a one-click link this user started and did not finish (Tack_Embed_Admin::PENDING_META).
	'_tackquote_link_pending',
);

/**
 * Product meta: the per-product "quote only" switch (Tack_Catalog_Mode::META_QUOTE_ONLY).
 * Products are posts in every storage mode, so the post-meta API is right here.
 * Order meta is deliberately kept (see readme): orders are financial records.
 */
$tack_quotes_post_meta = array( '_tackquote_quote_only' );

/**
 * Background jobs (Tack_Order_Sync::SYNC_HOOK / REQUEUE_HOOK), queued in Action Scheduler
 * group `tackquote` or, without it, in WP-Cron. Left behind, Action Scheduler would fail
 * each one later with "no callbacks are registered".
 */
$tack_quotes_hooks = array( 'tack_quotes_sync_order', 'tack_quotes_requeue_unsynced' );

/**
 * Transients the plugin creates under a fixed name.
 *
 * The per-visitor rate-limit counters (`tack_qr_*`, `tack_qu_*` uploads, `tack_qf_*` applications, `tack_qc_*` checkout links) and the five-minute form-outcome tokens
 * (`tack_sf_*`, Tack_Storefront_Forms::RESULT_PREFIX) are not listed, nor are the one-minute
 * net-terms standing answers (`tack_nt_<user id>`, Tack_Gateway_Net_Terms::CACHE_PREFIX), nor the
 * Connect with TackQuote transients (`tack_quotes_connect_<hash>`, 15 minutes, and
 * `tack_quotes_connect_notice_<user id>`, 5 minutes; Tack_Connect): they
 * are keyed per visitor so there is no fixed name to delete, there is no WordPress API for wildcard transient deletion,
 * and they expire within fifteen minutes on their own. Sweeping them would mean a direct
 * LIKE query against the options table that also silently does nothing on a site using an
 * external object cache, which is a worse trade than letting them lapse.
 */
$tack_quotes_transients = array(
	'tack_quotes_registration_config',
	// Wholesale form definitions, every slug in one transient (Tack_Api_Client::FORM_CACHE_TRANSIENT) — 1.10.0.
	'tack_quotes_wholesale_form_cache',
	// "The server has no /storefront/v1 routes" memory (Tack_Api_Client::V1_MISSING_TRANSIENT) — 1.10.0.
	'tack_quotes_storefront_v1_missing',
	// Outcome of the settings page's last "Test connection" (Tack_Settings::CONNECTION_CHECK) — 1.10.0.
	'tack_quotes_connection_check',
	// What the server advertised on ping (Tack_Api_Client::CAPABILITIES_TRANSIENT) — 1.10.0.
	'tack_quotes_server_capabilities',
	// Wholesale form slugs already logged today as matching no form (Tack_Storefront_Forms::MISSING_FORM_LOGGED) — 1.10.0.
	'tack_quotes_wholesale_form_missing',
	// The seller-designed quote form, every locale in one transient (Tack_Quote_Form::CACHE_TRANSIENT) — 1.12.0.
	'tack_quotes_quote_form_cache',
	// "Required file field, files off" already logged today (Tack_Quote_Form::FILE_FALLBACK_LOGGED) — 1.12.0.
	'tack_quotes_quote_form_file_fallback',
	// What TackQuote said about catalogue and order sync (Tack_Rest_Keys::STATUS_TRANSIENT) — 1.12.0.
	'tack_quotes_rest_keys_status',
	// Why the last shopper's quote request failed, for administrators (Tack_Widget::FAILURE_TRANSIENT) — 1.12.0.
	'tack_quotes_storefront_failure',
);

/**
 * Tell TackQuote the plugin was deleted (1.12.0), so it stands this store's connection
 * down and revokes a key that Connect with TackQuote issued.
 *
 * The same request Tack_Lifecycle sends on deactivation, written out here because
 * uninstall.php runs without the plugin's own classes. Best effort and short (3 s).
 * Skipped when the server's last cached capability list exists and lacks `lifecycle`
 * (an older server has no such route); with no cached list it is sent anyway, because a
 * server without the route only answers 404.
 *
 * @return bool Whether TackQuote answered 2xx and released the REST keys it held.
 */
function tack_quotes_notify_uninstalled() {
	$key = (string) get_option( 'tack_quotes_api_key', '' );
	$api = rtrim( (string) get_option( 'tack_quotes_api_url', 'https://api.tackquote.com/v1' ), '/' );
	if ( '' === $key || 0 !== strpos( $api, 'https://' ) ) {
		return false;
	}
	$caps = get_transient( 'tack_quotes_server_capabilities' );
	if ( is_array( $caps ) && isset( $caps['caps'] ) && is_array( $caps['caps'] ) && ! in_array( 'lifecycle', $caps['caps'], true ) ) {
		return false;
	}
	$response = wp_remote_request(
		$api . '/integrations/woocommerce/plugin-lifecycle',
		array(
			'method'      => 'POST',
			'timeout'     => 3,
			'redirection' => 0,
			'headers'     => array(
				'X-Api-Key'            => $key,
				'Content-Type'         => 'application/json',
				'Accept'               => 'application/json',
				'X-TackQuote-Site-Url' => home_url(),
			),
			'body'        => wp_json_encode( array( 'event' => 'uninstalled' ) ),
		)
	);
	if ( is_wp_error( $response ) ) {
		return false;
	}
	$code = (int) wp_remote_retrieve_response_code( $response );
	$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	return $code >= 200 && $code < 300 && is_array( $body ) && true === ( $body['restKeysReleased'] ?? false );
}

/**
 * Delete the WooCommerce REST API key this store approved for TackQuote (1.12.0), so no
 * key outlives the plugin. Only the one key WooCommerce reported to TackQuote
 * (`tack_quotes_wc_rest_key_id`), and only while its description still starts with the
 * name WooCommerce gave it (`TackQuote - API (<date>)`, WC_Auth::create_keys()): a key
 * the merchant made or renamed is never touched. WooCommerce has no API for deleting
 * a key, so this is the same statement its own REST API key screen runs.
 *
 * @return void
 */
function tack_quotes_delete_wc_rest_key() {
	global $wpdb;
	$id = (int) get_option( 'tack_quotes_wc_rest_key_id', 0 );
	if ( $id <= 0 ) {
		return;
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WooCommerce stores REST keys in its own table and offers no deletion API; one row, by id.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}woocommerce_api_keys WHERE key_id = %d AND description LIKE %s",
			$id,
			$wpdb->esc_like( 'TackQuote - API' ) . '%'
		)
	);
}

/**
 * Delete the 1.14.0 storefront cache entries (`tack_sfc_<hash>` transients) on the
 * current site.
 *
 * They are per visitor, so they have no fixed names for delete_transient(). Without a
 * persistent object cache they are `_transient_tack_sfc_*` and
 * `_transient_timeout_tack_sfc_*` rows in the options table; with one, they live in
 * that cache, expire within two minutes, and the generation option deleted with the
 * other options already makes every one of them unreadable.
 *
 * @return void
 */
function tack_quotes_delete_storefront_cache() {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WordPress has no API to delete transients by prefix; one bounded DELETE at uninstall.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_tack_sfc_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_tack_sfc_' ) . '%'
		)
	);
}

/**
 * Delete this plugin's stored values on the current site.
 *
 * @param array $options    Option names.
 * @param array $transients Transient names.
 * @param array $post_meta  Product meta keys.
 * @param array $hooks      Order-sync hooks to unschedule.
 * @return void
 */
function tack_quotes_delete_site_data( $options, $transients, $post_meta = array(), $hooks = array() ) {
	// 1.12.0, BEFORE the options that hold the key and the key id are deleted.
	// The WooCommerce key is deleted ONLY when TackQuote answered 2xx AND said it let go of
	// the REST keys (`restKeysReleased`). Otherwise TackQuote may still be using that key
	// (a pasted TackQuote key, a key it kept, or no answer at all), and deleting it would
	// silently break its sync: the key then stays listed under WooCommerce > Settings >
	// Advanced > REST API for the merchant to revoke.
	if ( tack_quotes_notify_uninstalled() ) {
		tack_quotes_delete_wc_rest_key();
	}
	delete_site_transient( 'tack_quotes_github_release' );
	wp_unschedule_hook( 'tackquote_refresh_standing' );

	// Roles the 1.10.0 role mirror created (Tack_Role_Mirror::OPTION_CREATED_ROLES),
	// removed before the option that lists them. Only names with our prefix.
	foreach ( (array) get_option( 'tack_quotes_mirror_roles_created', array() ) as $role ) {
		if ( is_string( $role ) && 0 === strpos( $role, 'tackquote_' ) ) {
			remove_role( $role );
		}
	}
	foreach ( $options as $option ) {
		delete_option( $option );
	}
	foreach ( $transients as $transient ) {
		delete_transient( $transient );
	}
	tack_quotes_delete_storefront_cache();
	foreach ( $post_meta as $key ) {
		delete_post_meta_by_key( $key );
	}
	foreach ( $hooks as $hook ) {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( $hook, array(), 'tackquote' );
		}
		wp_unschedule_hook( $hook );
	}
}

// User meta lives in the network-wide users table, so it is deleted once, not per site.
foreach ( $tack_quotes_user_meta as $tack_quotes_key ) {
	delete_metadata( 'user', 0, $tack_quotes_key, '', true );
}

tack_quotes_delete_site_data( $tack_quotes_options, $tack_quotes_transients, $tack_quotes_post_meta, $tack_quotes_hooks );

/*
 * Multisite: clean every site in the network.
 *
 * `get_sites()` is the documented API for this and replaces a raw
 * `SELECT blog_id FROM {$wpdb->blogs}` that needed a phpcs suppression to exist, assigned to
 * `$blog_id` (a WordPress global, which the coding standards prohibit overwriting), and read
 * every site on the network in one unbounded query. get_sites() is paged deliberately: a
 * large network is exactly where an unbounded query hurts, and 'fields' => 'ids' keeps this
 * from hydrating a site object per row.
 */
if ( is_multisite() ) {
	$tack_quotes_page = 0;
	do {
		$tack_quotes_site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 200,
				'offset' => $tack_quotes_page * 200,
			)
		);

		$tack_quotes_found = count( $tack_quotes_site_ids );

		foreach ( $tack_quotes_site_ids as $tack_quotes_site_id ) {
			switch_to_blog( (int) $tack_quotes_site_id );
			tack_quotes_delete_site_data( $tack_quotes_options, $tack_quotes_transients, $tack_quotes_post_meta, $tack_quotes_hooks );
			restore_current_blog();
		}

		++$tack_quotes_page;
	} while ( 200 === $tack_quotes_found );
}
