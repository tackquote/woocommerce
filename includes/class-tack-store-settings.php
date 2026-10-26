<?php
/**
 * Store settings TackQuote owns (1.13.0): order sync on or off, which TackQuote features
 * apply in this store, the buyer groups and the wholesale form.
 *
 * @package TackQuotes
 * @since   1.13.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The settings a seller now changes once, in TackQuote, instead of in two places.
 *
 * Wire contract (TackQuote API, API key auth, tenant from the key; offered only when the
 * ping lists the `store_settings` capability):
 *
 *   GET  /integrations/woocommerce/store-settings
 *        -> { orderSync: { push }, features: { prices, orderLimits, buyerGroupBadge,
 *             taxExempt }, buyerGroups: [{ code, name }], wholesaleForms: [{ slug, name,
 *             isDefault }], defaultWholesaleFormSlug, updatedAt }
 *   PUT  /integrations/woocommerce/store-settings/from-plugin (capability
 *        `store_settings_write`) { orderSync?: { push, acknowledgePersonalData },
 *        features?: { … }, defaultWholesaleFormSlug?: string|null } -> the same shape.
 *        The plugin's own settings page writes through it, so everything 1.12.0 could
 *        change from WordPress still can be.
 *   POST /integrations/woocommerce/store-settings/seed
 *        { orderSync: { push }, features: { ... } } -> the same shape as GET. TackQuote
 *        stores the values only when it holds none for this store.
 *
 * The rules:
 *
 *   - TackQuote decides only on a server that lists BOTH `store_settings` and
 *     `store_settings_write`. Any other server (its fresh capability list lacks one) changes nothing:
 *     every feature reads the plugin's own option, exactly as in 1.12.0.
 *   - TackQuote's value wins. The plugin's own "Turn off … on this store" box can only
 *     switch a feature OFF here; it can never switch on what TackQuote has off.
 *   - The settings are read on the background refresh (the same WP-Cron event that checks
 *     the store's standing, at most every ten minutes, one ping plus this read), and by an
 *     administrator opening a stale settings page. Never on a shopper's request.
 *   - TackQuote unreachable: the last answer it gave stays in force (fail closed to the
 *     last known value), and with no answer yet the plugin's own option decides.
 *   - When TackQuote answers with nothing stored (`updatedAt` null), the plugin reports its
 *     current options (the seed), so an existing store keeps its behaviour. TackQuote keeps
 *     them only while it holds nothing, so this happens once.
 */
class Tack_Store_Settings {

	/** The ping capability a server lists when it serves the read and seed routes. */
	const CAPABILITY = 'store_settings';

	/**
	 * The capability for the plugin's own write route (`PUT …/store-settings/from-plugin`).
	 * TackQuote decides these settings ONLY on a server that lists both: the plugin must
	 * be able to change everything it could change in 1.12.0, from inside WordPress.
	 */
	const WRITE_CAPABILITY = 'store_settings_write';

	/** The capability for `wpUserId` (the acting WordPress user) on the write route. */
	const ACTOR_CAPABILITY = 'store_settings_actor';

	/** The last answer TackQuote gave, for the saved key (autoloaded: storefront reads it). */
	const OPTION = 'tack_quotes_store_settings';

	/**
	 * Seconds an answer counts as fresh: the standing refresh's own cadence
	 * (Tack_Standing::TTL), because the read rides only that event. A shorter TTL here
	 * would have to schedule the event more often, and every run of it also pings.
	 */
	const TTL = 600;

	/** TackQuote's own rule for a wholesale form slug (WOO_WHOLESALE_FORM_SLUG). */
	const SLUG_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,119}$/';

	/** Timeout for the background read; nobody waits on it. */
	const CRON_TIMEOUT = 10;

	const ORDER_SYNC   = 'order_sync';
	const PRICES       = 'prices';
	const ORDER_LIMITS = 'order_limits';
	const BUYER_GROUP  = 'buyer_group';
	const TAX_EXEMPT   = 'tax_exempt';

	/** The settings paths. */
	const GET_PATH   = '/integrations/woocommerce/store-settings';
	const SEED_PATH  = '/integrations/woocommerce/store-settings/seed';
	const WRITE_PATH = '/integrations/woocommerce/store-settings/from-plugin';

	/**
	 * Every TackQuote-owned switch: the plugin's own (1.12.0) option, the local
	 * "turn off on this store" option, and where the value sits in TackQuote's answer.
	 *
	 * @return array<string, array{legacy:string, off:string, group:string, field:string}>
	 */
	public static function features() {
		return array(
			self::ORDER_SYNC   => array(
				'legacy' => 'tack_quotes_enable_order_sync',
				'off'    => 'tack_quotes_off_order_sync',
				'group'  => 'orderSync',
				'field'  => 'push',
			),
			self::PRICES       => array(
				'legacy' => 'tack_quotes_enable_wholesale_pricing',
				'off'    => 'tack_quotes_off_prices',
				'group'  => 'features',
				'field'  => 'prices',
			),
			self::ORDER_LIMITS => array(
				'legacy' => 'tack_quotes_enable_order_limits',
				'off'    => 'tack_quotes_off_order_limits',
				'group'  => 'features',
				'field'  => 'orderLimits',
			),
			self::BUYER_GROUP  => array(
				'legacy' => 'tack_quotes_enable_buyer_group',
				'off'    => 'tack_quotes_off_buyer_group',
				'group'  => 'features',
				'field'  => 'buyerGroupBadge',
			),
			self::TAX_EXEMPT   => array(
				'legacy' => 'tack_quotes_apply_tax_exempt',
				'off'    => 'tack_quotes_off_tax_exempt',
				'group'  => 'features',
				'field'  => 'taxExempt',
			),
		);
	}

	/**
	 * Hooks: the read rides ONLY on the standing refresh event (Tack_Standing::REFRESH_HOOK,
	 * at most every ten minutes), after its ping. It schedules nothing of its own, so the
	 * background traffic is one ping plus one read per ten minutes, never a request on a
	 * shopper's page view.
	 */
	public static function init() {
		add_action( Tack_Standing::REFRESH_HOOK, array( __CLASS__, 'on_refresh_event' ), 20 );
	}

	// ── Reading ───────────────────────────────────────────────────────────────

	/**
	 * Fingerprint of the saved key ('' when none), as the capability cache keys it.
	 *
	 * @return string
	 */
	private static function fingerprint() {
		$key = (string) get_option( 'tack_quotes_api_key', '' );
		return '' === $key ? '' : substr( hash( 'sha256', $key ), 0, 16 );
	}

	/**
	 * The stored answer for the saved key, or null.
	 *
	 * @return array|null array{fetched:int, settings:array}
	 */
	public static function snapshot() {
		$fingerprint = self::fingerprint();
		$stored      = get_option( self::OPTION, array() );
		if ( '' === $fingerprint || ! is_array( $stored ) || ! isset( $stored['key'], $stored['settings'] )
			|| ! hash_equals( (string) $stored['key'], $fingerprint ) || ! is_array( $stored['settings'] ) ) {
			return null;
		}
		return array(
			'fetched'  => (int) ( $stored['fetched'] ?? 0 ),
			'settings' => $stored['settings'],
		);
	}

	/**
	 * Does the server, as last seen, serve store settings? `null` when the plugin has no
	 * fresh capability list (no ping yet today, or the last ping failed).
	 *
	 * @return bool|null
	 */
	private static function server_offers() {
		$caps = ( new Tack_Api_Client() )->cached_capabilities();
		if ( null === $caps ) {
			return null;
		}
		return self::offers_all( $caps );
	}

	/**
	 * Does a capability list carry both the read and the write capability?
	 *
	 * @param string[] $caps Capabilities.
	 * @return bool
	 */
	private static function offers_all( array $caps ) {
		return in_array( self::CAPABILITY, $caps, true ) && in_array( self::WRITE_CAPABILITY, $caps, true );
	}

	/**
	 * Is TackQuote deciding these settings for this store? Yes when TackQuote has answered
	 * for the saved key and the server has not since said it no longer offers them.
	 *
	 * @return bool
	 */
	public static function is_server_driven() {
		return null !== self::snapshot() && false !== self::server_offers();
	}

	/**
	 * TackQuote's own value for a feature, or null when it is not deciding.
	 *
	 * @param string $feature One of the feature constants.
	 * @return bool|null
	 */
	public static function server_value( $feature ) {
		$features = self::features();
		if ( ! isset( $features[ $feature ] ) || ! self::is_server_driven() ) {
			return null;
		}
		$snapshot = self::snapshot();
		$def      = $features[ $feature ];
		return ! empty( $snapshot['settings'][ $def['group'] ][ $def['field'] ] );
	}

	/**
	 * Has an administrator switched the feature off on this store?
	 *
	 * @param string $feature One of the feature constants.
	 * @return bool
	 */
	public static function is_turned_off_here( $feature ) {
		$features = self::features();
		return isset( $features[ $feature ] ) && 'yes' === get_option( $features[ $feature ]['off'], 'no' );
	}

	/**
	 * Is a feature on in this store, right now?
	 *
	 * Server-driven: TackQuote's value, AND not switched off here. Otherwise the plugin's
	 * own option (1.12.0 behaviour, default off).
	 *
	 * @param string $feature One of the feature constants.
	 * @return bool
	 */
	public static function enabled( $feature ) {
		$features = self::features();
		if ( ! isset( $features[ $feature ] ) ) {
			return false;
		}
		$server = self::server_value( $feature );
		if ( null === $server ) {
			return 'yes' === get_option( $features[ $feature ]['legacy'], 'no' );
		}
		return $server && ! self::is_turned_off_here( $feature );
	}

	/**
	 * The buyer groups TackQuote listed (code, name), or null when it is not deciding.
	 *
	 * @return array<int, array{code:string, name:string}>|null
	 */
	public static function buyer_groups() {
		if ( ! self::is_server_driven() ) {
			return null;
		}
		$snapshot = self::snapshot();
		return $snapshot['settings']['buyerGroups'];
	}

	/**
	 * The wholesale forms TackQuote listed, or null when it is not deciding.
	 *
	 * @return array<int, array{slug:string, name:string, isDefault:bool}>|null
	 */
	public static function wholesale_forms() {
		if ( ! self::is_server_driven() ) {
			return null;
		}
		$snapshot = self::snapshot();
		return $snapshot['settings']['wholesaleForms'];
	}

	/**
	 * The form chosen for this store in TackQuote, as stored there ('' = TackQuote's
	 * default), or '' when TackQuote is not deciding.
	 *
	 * @return string
	 */
	public static function chosen_wholesale_form_slug() {
		return self::is_server_driven() ? self::snapshot()['settings']['defaultWholesaleFormSlug'] : '';
	}

	/**
	 * The wholesale form TackQuote chose for this store: its named default, else the form
	 * it marks as default, else '' (the plugin's own option then decides).
	 *
	 * @return string
	 */
	public static function server_wholesale_form_slug() {
		if ( ! self::is_server_driven() ) {
			return '';
		}
		$settings = self::snapshot()['settings'];
		if ( '' !== $settings['defaultWholesaleFormSlug'] ) {
			return $settings['defaultWholesaleFormSlug'];
		}
		foreach ( $settings['wholesaleForms'] as $form ) {
			if ( $form['isDefault'] ) {
				return $form['slug'];
			}
		}
		return '';
	}

	// ── Normalising TackQuote's answer ────────────────────────────────────────

	/**
	 * Keep only the documented fields, typed. A malformed answer is null (never half-read).
	 *
	 * @param mixed $answer Decoded answer.
	 * @return array|null
	 */
	public static function normalise( $answer ) {
		if ( ! is_array( $answer ) || ! isset( $answer['orderSync'], $answer['features'] )
			|| ! is_array( $answer['orderSync'] ) || ! is_array( $answer['features'] ) ) {
			return null;
		}
		$features = array();
		foreach ( array( 'prices', 'orderLimits', 'buyerGroupBadge', 'taxExempt' ) as $field ) {
			$features[ $field ] = true === ( $answer['features'][ $field ] ?? null );
		}

		$groups = array();
		foreach ( ( isset( $answer['buyerGroups'] ) && is_array( $answer['buyerGroups'] ) ) ? $answer['buyerGroups'] : array() as $group ) {
			if ( ! is_array( $group ) || ! isset( $group['code'] ) || ! is_string( $group['code'] ) ) {
				continue;
			}
			// The rule maps store codes upper-cased, with no comma or line break (see
			// Tack_Settings::clean_stored_group_code()); the same normal form here.
			$code = strtoupper( trim( (string) preg_replace( '/[,\x00-\x1F\x7F]+/', '', substr( $group['code'], 0, 100 ) ) ) );
			if ( '' === $code || isset( $groups[ $code ] ) ) {
				continue;
			}
			$name            = isset( $group['name'] ) && is_string( $group['name'] ) ? self::clip( wp_strip_all_tags( $group['name'] ), 120 ) : '';
			$groups[ $code ] = array(
				'code' => $code,
				'name' => '' !== $name ? $name : $code,
			);
		}
		ksort( $groups );

		$forms = array();
		foreach ( ( isset( $answer['wholesaleForms'] ) && is_array( $answer['wholesaleForms'] ) ) ? $answer['wholesaleForms'] : array() as $form ) {
			if ( ! is_array( $form ) || ! isset( $form['slug'] ) || ! is_string( $form['slug'] ) ) {
				continue;
			}
			// Verbatim (TackQuote's slugs may carry capitals); refused when not its own form.
			$slug = $form['slug'];
			if ( ! self::is_slug( $slug ) || isset( $forms[ $slug ] ) ) {
				continue;
			}
			$name           = isset( $form['name'] ) && is_string( $form['name'] ) ? self::clip( wp_strip_all_tags( $form['name'] ), 120 ) : '';
			$forms[ $slug ] = array(
				'slug'      => $slug,
				'name'      => '' !== $name ? $name : $slug,
				'isDefault' => true === ( $form['isDefault'] ?? null ),
			);
		}

		$default = isset( $answer['defaultWholesaleFormSlug'] ) && self::is_slug( $answer['defaultWholesaleFormSlug'] )
			? $answer['defaultWholesaleFormSlug']
			: '';

		return array(
			'orderSync'                => array( 'push' => true === ( $answer['orderSync']['push'] ?? null ) ),
			'features'                 => $features,
			'buyerGroups'              => array_values( $groups ),
			'wholesaleForms'           => array_values( $forms ),
			'defaultWholesaleFormSlug' => $default,
			'updatedAt'                => isset( $answer['updatedAt'] ) && is_string( $answer['updatedAt'] ) && '' !== $answer['updatedAt'] ? self::clip( $answer['updatedAt'], 40 ) : '',
		);
	}

	/**
	 * What the plugin's own options say today, in the seed's shape.
	 *
	 * @return array
	 */
	public static function local_values() {
		// The wholesale form slug typed in the plugin (any text TackQuote takes, up to 200
		// characters), or null when none was saved. TackQuote keeps it only when it names
		// one of the workspace's enabled forms.
		$typed    = get_option( 'tack_quotes_wholesale_form_slug', null );
		$typed    = is_string( $typed ) ? trim( $typed ) : '';
		$features = self::features();
		$on       = static function ( $feature ) use ( $features ) {
			return 'yes' === get_option( $features[ $feature ]['legacy'], 'no' );
		};
		return array(
			'orderSync'                => array( 'push' => $on( self::ORDER_SYNC ) ),
			'features'                 => array(
				'prices'          => $on( self::PRICES ),
				'orderLimits'     => $on( self::ORDER_LIMITS ),
				'buyerGroupBadge' => $on( self::BUYER_GROUP ),
				'taxExempt'       => $on( self::TAX_EXEMPT ),
			),
			'defaultWholesaleFormSlug' => '' !== $typed ? self::clip( $typed, 200 ) : null,
		);
	}

	/**
	 * Is this one of TackQuote's wholesale form slugs (its own pattern)?
	 *
	 * @param mixed $slug Value.
	 * @return bool
	 */
	public static function is_slug( $slug ) {
		return is_string( $slug ) && 1 === preg_match( self::SLUG_PATTERN, $slug );
	}

	// ── Refreshing ────────────────────────────────────────────────────────────

	/**
	 * Is the stored answer older than TTL (or missing)?
	 *
	 * @return bool
	 */
	public static function is_stale() {
		$snapshot = self::snapshot();
		return null === $snapshot || time() - $snapshot['fetched'] >= self::TTL;
	}

	/**
	 * The standing refresh event: read the settings (the action callback returns nothing).
	 */
	public static function on_refresh_event() {
		self::refresh();
	}

	/**
	 * Read the settings from TackQuote and keep the answer.
	 *
	 * Runs after the standing ping on the background event (so the capability list is
	 * fresh), and from the settings page for an administrator (`$timeout` = interactive).
	 * Any failure keeps the last answer: fail closed to the last known value.
	 *
	 * @param Tack_Api_Client|null $client  Client (tests).
	 * @param int|null             $timeout Seconds; CRON_TIMEOUT by default.
	 * @return bool Whether a fresh answer was stored.
	 */
	public static function refresh( $client = null, $timeout = null ) {
		if ( '' === self::fingerprint() ) {
			return false;
		}
		$client  = $client instanceof Tack_Api_Client ? $client : new Tack_Api_Client();
		$timeout = null === $timeout ? self::CRON_TIMEOUT : (int) $timeout;
		if ( ! self::offers_all( $client->server_capabilities() ) ) {
			return false;
		}

		$settings = self::normalise( $client->request( 'GET', self::GET_PATH, null, $timeout ) );
		if ( null === $settings ) {
			return false;
		}

		// Nothing stored in TackQuote yet: report what this store does today. TackQuote keeps
		// it only while it holds nothing, so a repeat is harmless. TackQuote's empty answer is
		// never taken as "everything off": that would switch off what the store has on.
		if ( '' === $settings['updatedAt'] ) {
			$settings = self::normalise( $client->request( 'POST', self::SEED_PATH, self::local_values(), $timeout ) );
			if ( null === $settings || '' === $settings['updatedAt'] ) {
				return false;
			}
		}

		update_option(
			self::OPTION,
			array(
				'key'      => self::fingerprint(),
				'fetched'  => time(),
				'settings' => $settings,
			),
			true
		);
		return true;
	}

	/**
	 * Change settings in TackQuote from the plugin's own settings page (1.13.0):
	 * `PUT /integrations/woocommerce/store-settings/from-plugin` with the seller route's
	 * field shape (`orderSync {push, acknowledgePersonalData}`, `features {…}`,
	 * `defaultWholesaleFormSlug`). On success the stored answer is replaced by
	 * TackQuote's (or read again); on failure nothing local changes.
	 *
	 * @param array                $change Fields to change.
	 * @param Tack_Api_Client|null $client Client (tests).
	 * @return true|WP_Error
	 */
	public static function write( array $change, $client = null ) {
		if ( ! self::is_server_driven() ) {
			return new WP_Error( 'tack_store_settings_unavailable', __( 'TackQuote does not take these settings from the plugin right now. Reload the page and try again.', 'tackquote' ) );
		}
		$client = $client instanceof Tack_Api_Client ? $client : new Tack_Api_Client();
		// Who changed it: the WordPress user id only (an opaque number, no name or email),
		// to a server that lists `store_settings_actor` (an older one would refuse the field).
		$caps = $client->cached_capabilities();
		if ( is_array( $caps ) && in_array( self::ACTOR_CAPABILITY, $caps, true ) && get_current_user_id() > 0 ) {
			$change['wpUserId'] = (int) get_current_user_id();
		}
		$answer = $client->request( 'PUT', self::WRITE_PATH, $change, Tack_Api_Client::INTERACTIVE_TIMEOUT );
		if ( is_wp_error( $answer ) ) {
			return $answer;
		}
		$settings = self::normalise( $answer );
		if ( null === $settings ) {
			// Saved, but the answer is not the settings: read them again.
			self::refresh( $client, Tack_Api_Client::INTERACTIVE_TIMEOUT );
			return true;
		}
		update_option(
			self::OPTION,
			array(
				'key'      => self::fingerprint(),
				'fetched'  => time(),
				'settings' => $settings,
			),
			true
		);
		return true;
	}

	/**
	 * For the settings page: refresh when stale (an administrator is looking, not a shopper).
	 */
	public static function refresh_for_admin() {
		if ( self::is_stale() && false !== self::server_offers() ) {
			// An administrator looking, never a shopper; the standing ping cache serves the
			// capability check, so this costs one read.
			self::refresh( null, Tack_Api_Client::INTERACTIVE_TIMEOUT );
		}
	}

	// ── Where to change them ──────────────────────────────────────────────────

	/**
	 * Where "Change in TackQuote" goes: the dashboard inside WooCommerce when this server
	 * offers it, else TackQuote's WooCommerce page in a new tab.
	 *
	 * @return array{url:string, new_tab:bool}
	 */
	public static function change_link() {
		if ( class_exists( 'Tack_Embed_Admin', false ) && ( new Tack_Api_Client() )->supports( Tack_Embed_Admin::SERVER_CAPABILITY ) ) {
			return array(
				'url'     => Tack_Embed_Admin::page_url(),
				'new_tab' => false,
			);
		}
		return array(
			'url'     => 'https://' . Tack_Connect::DEFAULT_CONNECT_HOST . '/settings/integrations/woocommerce',
			'new_tab' => true,
		);
	}

	/**
	 * Print the "Change in TackQuote" link.
	 *
	 * @param string $what Accessible name of what is changed, already translated.
	 */
	public static function render_change_link( $what = '' ) {
		$link = self::change_link();
		printf(
			'<a class="button" href="%1$s"%2$s>%3$s<span class="screen-reader-text"> %4$s</span></a>',
			esc_url( $link['url'] ),
			$link['new_tab'] ? ' target="_blank" rel="noopener noreferrer"' : '',
			esc_html__( 'Change in TackQuote', 'tackquote' ),
			esc_html( trim( $what . ( $link['new_tab'] ? ' ' . __( '(opens in a new tab)', 'tackquote' ) : '' ) ) )
		);
	}

	/**
	 * Trim text to at most `$max` characters.
	 *
	 * @param string $text Text.
	 * @param int    $max  Maximum length.
	 * @return string
	 */
	private static function clip( $text, $max ) {
		$text = trim( (string) $text );
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max ) : substr( $text, 0, $max );
	}
}
