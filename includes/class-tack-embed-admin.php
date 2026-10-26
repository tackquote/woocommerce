<?php
/**
 * The TackQuote dashboard inside WooCommerce admin (WooCommerce → TackQuote).
 *
 * @package TackQuotes
 * @since   1.12.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce → TackQuote: the seller's TackQuote workspace in a frame, signed in as the
 * WordPress user's OWN linked TackQuote account.
 *
 * How it signs in (TackQuote API, `POST /integrations/woocommerce/embed/open`):
 *
 *   1. On every page view this PHP asks TackQuote SERVER TO SERVER, with the store's API
 *      key, for the current WordPress user (`get_current_user_id()`) and that user's own
 *      secret (random, kept in their user meta, `SECRET_META`). The browser never sees
 *      the key or the secret.
 *   2. A WordPress user linked to a TackQuote account gets a one-time login code (two
 *      minutes, single use) and a frame ticket. The frame loads
 *      `https://app.tackquote.com/woocommerce/app?frame=<ticket>#code=<code>`; TackQuote
 *      allows the frame only for the store address saved on its connection. The code is in
 *      the URL fragment, which no server receives. Two-factor sign-in is never skipped.
 *   3. A WordPress user who is NOT linked gets no session at all, only a field for the
 *      one-time code their own TackQuote user asks for under Integrations → WooCommerce →
 *      "Link a WordPress login" (10 minutes, works once). The plugin posts the code server to
 *      server with the key, this WordPress user's id, login and secret
 *      (`POST /integrations/woocommerce/embed/claim`); TackQuote links that TackQuote user to
 *      THIS WordPress user and emails the account. Nobody is ever signed in as someone else,
 *      and the role in TackQuote is the linked person's own. A stolen API key or a colleague
 *      with the same capability needs the person's own code.
 *
 * ONE-CLICK LINK (1.13.0, server capability `embedded_link_oauth`, TackQuote migration 481).
 * An unlinked WordPress user gets ONE primary button, "Link my TackQuote account"; the typed
 * code above stays as the fallback ("Have a link code?"):
 *
 *   a. START (`admin-post.php?action=tackquote_embed_link_start`, nonce + capability). This
 *      PHP makes an RFC 7636 code verifier and a state, keeps them ONLY in this WordPress
 *      user's meta (`PENDING_META`, 10 minutes, single use), and asks TackQuote server to
 *      server (`POST /integrations/woocommerce/embed/link-requests`) with the key, this user's
 *      id, login and secret, the S256 challenge, the state and the return address it expects.
 *      TackQuote answers a one-time authorize URL on the TackQuote app (only that host for the
 *      default API URL); the new tab goes there.
 *   b. The person signs in to TackQuote as themselves and confirms "Link WordPress login <login>
 *      (#id) on <store> to <you>?". TackQuote sends the tab back to THIS site's
 *      `admin-post.php?action=tackquote_link_return` (the address it has saved for the store,
 *      never one from the browser) with a one-time `code` and the `state`.
 *   c. RETURN. Capability, then the state against THIS user's pending meta (constant time; the
 *      meta is deleted first, so a replayed return finds nothing), then the code is exchanged
 *      server to server with the verifier and this user's secret
 *      (`POST /integrations/woocommerce/embed/link-exchange`). A link approved from a forwarded
 *      URL by someone else lands in THEIR browser, where no verifier waits, and links nothing.
 *
 * Why no WordPress nonce on the return: it is a redirect from another site and cannot carry
 * one. The state bound to this user's meta and consumed once, plus the capability, are the
 * forgery defence (the OAuth `state` pattern, as Tack_Connect).
 *
 * "Open in a new tab" mints a fresh code for a top-level tab, for browsers that block
 * sign-in inside frames (Safari, or Chrome with third-party cookies blocked) and for anyone
 * who prefers the full window.
 *
 * Shown only when the TackQuote server lists the `embedded_dashboard` capability, so an
 * older server never sees a call it does not know.
 */
class Tack_Embed_Admin {

	/** The submenu slug under WooCommerce. */
	const PAGE_SLUG = 'tackquote-dashboard';

	/**
	 * Who may open it. The same capability WooCommerce's own menu uses: the page signs a
	 * person in as THEIR linked TackQuote account only, so it grants nothing by itself.
	 */
	const CAPABILITY = 'manage_woocommerce';

	/** User meta holding the per-WordPress-user secret (64 hex characters). */
	const SECRET_META = '_tackquote_embed_secret';

	/** The server capability this page needs (ping `capabilities`). */
	const SERVER_CAPABILITY = 'embedded_dashboard';

	/**
	 * The server capability of the one-click link (TackQuote migration 481). Without it the
	 * page offers the typed code only, exactly as 1.12.0.
	 *
	 * @since 1.13.0
	 */
	const ONECLICK_CAPABILITY = 'embedded_link_oauth';

	/**
	 * User meta holding this WordPress user's pending one-click link: the PKCE verifier, a
	 * SHA-256 of the state and when it started. Single use, `LINK_TTL` seconds.
	 *
	 * @since 1.13.0
	 */
	const PENDING_META = '_tackquote_link_pending';

	/** How long a started one-click link stays usable (TackQuote keeps its side 10 minutes). */
	const LINK_TTL = 600;

	/** The page TackQuote's authorize URL must name (app origin + this path + `#request=`). */
	const AUTHORIZE_PATH = '/auth/link/woocommerce';

	/** The admin-post actions, each with its own nonce. */
	const TAB_ACTION    = 'tackquote_embed_tab';
	const CLAIM_ACTION  = 'tackquote_embed_claim';
	const UNLINK_ACTION = 'tackquote_embed_unlink';

	/** The one-click link's start action (1.13.0). */
	const LINK_START_ACTION = 'tackquote_embed_link_start';

	/**
	 * The one-click return action. TackQuote builds the return address as `<saved store
	 * address>/wp-admin/admin-post.php?action=` + this, so it must not change.
	 */
	const LINK_RETURN_ACTION = 'tackquote_link_return';

	/** Timeout for the open call: an admin is waiting for the page. */
	const OPEN_TIMEOUT = 10;

	/** The TackQuote app host for the default API URL. */
	const DEFAULT_APP_HOST = 'app.tackquote.com';

	/**
	 * API client.
	 *
	 * @var Tack_Api_Client|null
	 */
	private $client;

	/**
	 * Constructor.
	 *
	 * @param Tack_Api_Client|null $client Optional client (tests).
	 */
	public function __construct( $client = null ) {
		$this->client = $client;
	}

	/**
	 * Hook registration.
	 */
	public function init() {
		// After WooCommerce registers its own menu (priority 9 and 50), so the parent exists.
		add_action( 'admin_menu', array( $this, 'add_menu' ), 60 );
		add_action( 'admin_post_' . self::TAB_ACTION, array( $this, 'handle_tab' ) );
		add_action( 'admin_post_' . self::CLAIM_ACTION, array( $this, 'handle_claim' ) );
		add_action( 'admin_post_' . self::UNLINK_ACTION, array( $this, 'handle_unlink' ) );
		add_action( 'admin_post_' . self::LINK_START_ACTION, array( $this, 'handle_link_start' ) );
		add_action( 'admin_post_' . self::LINK_RETURN_ACTION, array( $this, 'handle_link_return' ) );
		// Signed out when TackQuote sends the tab back (an expired WordPress session): WordPress's
		// own login screen first, then this same return as the signed-in user.
		add_action( 'admin_post_nopriv_' . self::LINK_RETURN_ACTION, array( $this, 'handle_link_return_signed_out' ) );
	}

	/**
	 * The API client.
	 *
	 * @return Tack_Api_Client
	 */
	private function client() {
		if ( null === $this->client ) {
			$this->client = new Tack_Api_Client();
		}
		return $this->client;
	}

	/**
	 * WooCommerce → TackQuote.
	 */
	public function add_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'TackQuote', 'tackquote' ),
			__( 'TackQuote', 'tackquote' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * This page's admin URL, with an optional result flag.
	 *
	 * @param string $result One of the RESULT_* keys, or ''.
	 * @return string
	 */
	public static function page_url( $result = '' ) {
		$url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		return '' === $result ? $url : $url . '&tq_embed=' . rawurlencode( $result );
	}

	// ── The per-user secret ──────────────────────────────────────────────────

	/**
	 * The current WordPress user's secret, created on first use. Kept in THEIR user meta
	 * only; TackQuote stores a hash of it on the link. Never shown, never logged.
	 *
	 * @param int $user_id WordPress user id.
	 * @return string 64 hex characters.
	 */
	public static function user_secret( $user_id ) {
		$secret = (string) get_user_meta( $user_id, self::SECRET_META, true );
		if ( 1 === preg_match( '/^[0-9a-f]{64}$/', $secret ) ) {
			return $secret;
		}
		$secret = bin2hex( random_bytes( 32 ) );
		update_user_meta( $user_id, self::SECRET_META, $secret );
		return $secret;
	}

	// ── The open (server to server) ──────────────────────────────────────────

	/**
	 * Ask TackQuote to open the dashboard for the current WordPress user.
	 *
	 * @param string $purpose 'frame' or 'tab'.
	 * @return array|WP_Error The API's answer (`status` linked|unlinked|refused|no_store).
	 */
	public function open( $purpose ) {
		$user_id = (int) get_current_user_id();
		if ( $user_id <= 0 ) {
			return new WP_Error( 'tack_embed_no_user', __( 'Sign in to WordPress first.', 'tackquote' ) );
		}
		$body = array(
			'wpUserId'     => (string) $user_id,
			'wpUserSecret' => self::user_secret( $user_id ),
			'purpose'      => 'tab' === $purpose ? 'tab' : 'frame',
		);
		return $this->client()->request( 'POST', '/integrations/woocommerce/embed/open', $body, self::OPEN_TIMEOUT );
	}

	/**
	 * Is TackQuote's app origin one this page may frame or send a person to? https, or
	 * http on a development host; and for the default API URL, exactly app.tackquote.com.
	 * No path, query, fragment or credentials.
	 *
	 * @param mixed $origin `appOrigin` from the API.
	 * @return string The origin, or '' when it must not be used.
	 */
	public static function app_origin( $origin ) {
		if ( ! is_string( $origin ) || ! preg_match( '#^(https?)://([a-z0-9.-]+)(:\d{1,5})?$#', $origin, $m ) ) {
			return '';
		}
		$scheme = $m[1];
		$host   = $m[2];
		if ( 'https' !== $scheme && ! Tack_Settings::is_non_public_host( $host ) ) {
			return '';
		}
		$api = rtrim( (string) get_option( 'tack_quotes_api_url', Tack_Settings::DEFAULT_API_URL ), '/' );
		if ( ( '' === $api || Tack_Settings::DEFAULT_API_URL === $api ) && self::DEFAULT_APP_HOST !== $host ) {
			return '';
		}
		return $origin;
	}

	/**
	 * A one-time value from the API, in the shape TackQuote mints (UUID or base64url).
	 *
	 * @param mixed $value Value.
	 * @return string The value, or ''.
	 */
	private static function token( $value ) {
		return is_string( $value ) && preg_match( '/^[A-Za-z0-9_-]{16,128}$/', $value ) ? $value : '';
	}

	/**
	 * The frame URL for a linked answer, or '' when it cannot be framed safely.
	 *
	 * @param array $answer API answer.
	 * @return string
	 */
	public static function frame_url( array $answer ) {
		$origin = self::app_origin( $answer['appOrigin'] ?? '' );
		$code   = self::token( $answer['loginCode'] ?? '' );
		$ticket = self::token( $answer['frameTicket'] ?? '' );
		if ( '' === $origin || '' === $code || '' === $ticket ) {
			return '';
		}
		return $origin . '/woocommerce/app?frame=' . rawurlencode( $ticket ) . '#code=' . rawurlencode( $code );
	}

	/**
	 * The top-level "Open in a new tab" URL for a linked (`tab`) answer, or ''.
	 *
	 * @param array $answer API answer.
	 * @return string
	 */
	public static function tab_url( array $answer ) {
		$origin = self::app_origin( $answer['appOrigin'] ?? '' );
		if ( '' === $origin ) {
			return '';
		}
		$status = $answer['status'] ?? '';
		if ( 'linked' === $status ) {
			$code = self::token( $answer['loginCode'] ?? '' );
			return '' === $code ? '' : $origin . '/woocommerce/open#code=' . rawurlencode( $code );
		}
		return '';
	}

	/**
	 * Is this answer a refused key (revoked, store disconnected)?
	 *
	 * @param mixed $result request() result.
	 * @return bool
	 */
	private static function is_disconnected( $result ) {
		if ( ! is_wp_error( $result ) ) {
			return false;
		}
		$data = $result->get_error_data();
		$code = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
		return 401 === $code || 403 === $code || 'tack_no_key' === $result->get_error_code();
	}

	// ── The page ─────────────────────────────────────────────────────────────

	/**
	 * Messages for the result flag after a redirect back here (fixed text, never echoed input).
	 *
	 * @return array<string,array{0:string,1:string}> key => [type, message].
	 */
	private static function results() {
		return array(
			'unlinked'    => array( 'success', __( 'Your WordPress login is no longer linked to TackQuote.', 'tackquote' ) ),
			'linked'      => array( 'success', __( 'Your WordPress login is now linked to your TackQuote account.', 'tackquote' ) ),
			'wrong_code'  => array( 'error', __( 'That code is not right, or it has expired. Check the code TackQuote shows under Integrations → WooCommerce, or ask for a new one there.', 'tackquote' ) ),
			'throttled'   => array( 'error', __( 'Too many wrong codes from your WordPress login. Wait 15 minutes, then try again.', 'tackquote' ) ),
			'refused'     => array( 'error', __( 'TackQuote could not link that account. Ask an admin of your TackQuote workspace for help.', 'tackquote' ) ),
			'no_store'    => array( 'error', __( 'TackQuote cannot tell which of your connected stores this is. Use one API key per WordPress site.', 'tackquote' ) ),
			'failed'      => array( 'error', __( 'TackQuote did not answer just now. Your link was not changed. Try again in a minute.', 'tackquote' ) ),
			'unavailable' => array( 'error', __( 'TackQuote could not open a new tab for you. Reload this page to see why.', 'tackquote' ) ),
			// 1.13.0: the one-click link.
			'expired'     => array( 'error', __( 'Nothing was linked: this link request expired, was already used, or was started from another WordPress login. Press Link my TackQuote account again.', 'tackquote' ) ),
			'cancelled'   => array( 'warning', __( 'Linking was cancelled in TackQuote. Nothing changed.', 'tackquote' ) ),
			'grant'       => array( 'error', __( 'Nothing was linked: TackQuote could not finish, because the approval expired or was already used. Press Link my TackQuote account again.', 'tackquote' ) ),
			'moved'       => array( 'warning', __( 'This store’s address changed in the last day, so one-click linking is paused until tomorrow. Use a link code instead.', 'tackquote' ) ),
			'address'     => array( 'warning', __( 'TackQuote cannot send you back to this WordPress admin, because it has another address saved for this store. Use a link code instead.', 'tackquote' ) ),
			'no_oneclick' => array( 'warning', __( 'One-click linking is not available for this store right now. Use a link code instead.', 'tackquote' ) ),
			'bad_open'    => array( 'error', __( 'TackQuote answered with a sign-in address this plugin will not open, so nothing was changed. Use a link code instead.', 'tackquote' ) ),
		);
	}

	/**
	 * Render WooCommerce → TackQuote.
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to open TackQuote.', 'tackquote' ), '', array( 'response' => 403 ) );
		}
		echo '<div class="wrap tackquote-embed">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'TackQuote', 'tackquote' ) . '</h1>';
		// 1.13.0: one menu entry, one tab bar: Dashboard, then the plugin's settings tabs.
		Tack_Settings::render_nav( 'dashboard' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: selects one of a fixed set of messages after a redirect; there is no form to carry a nonce.
		$flag         = isset( $_GET['tq_embed'] ) ? sanitize_key( wp_unslash( $_GET['tq_embed'] ) ) : '';
		$results      = self::results();
		$settings_url = Tack_Settings::tab_url( 'connection' );
		// A claim TackQuote refused because of the store's key (live QA 2026-10-26): only
		// that reason and the way out, not "did not answer" beside "no longer accepts".
		if ( 'disconnected' === $flag ) {
			self::disconnected_notice( $settings_url );
			echo '</div>';
			return;
		}
		if ( isset( $results[ $flag ] ) ) {
			self::notice( $results[ $flag ][0], $results[ $flag ][1] );
		}

		if ( '' === (string) get_option( 'tack_quotes_api_key', '' ) ) {
			self::notice(
				'warning',
				__( 'This store is not connected to TackQuote yet.', 'tackquote' ),
				$settings_url,
				__( 'Connect to TackQuote', 'tackquote' )
			);
			echo '</div>';
			return;
		}
		// Stood down (Tack_Standing, 1.12.0): TackQuote already said this store's key is
		// revoked or the store is disconnected. Say so and link to reconnect; nothing is
		// asked of TackQuote and no code is minted.
		if ( class_exists( 'Tack_Standing' ) && Tack_Standing::is_stood_down() ) {
			self::disconnected_notice( $settings_url );
			echo '</div>';
			return;
		}
		if ( ! in_array( self::SERVER_CAPABILITY, $this->client()->server_capabilities(), true ) ) {
			// No capability list can mean a refused key or an unreachable server, not an
			// older server: say which, from the ping that just failed.
			$failure = $this->client()->last_ping_failure();
			if ( Tack_Api_Client::STATE_REJECTED === $failure ) {
				self::notice(
					'error',
					__( 'TackQuote refused this store’s API key, so the dashboard cannot open here. Connect the store again.', 'tackquote' ),
					$settings_url,
					__( 'Reconnect to TackQuote', 'tackquote' )
				);
			} elseif ( Tack_Api_Client::STATE_FAILED === $failure ) {
				self::notice(
					'warning',
					__( 'TackQuote could not be reached just now. Try again in a few minutes, or open TackQuote in a new tab.', 'tackquote' ),
					'https://' . self::DEFAULT_APP_HOST . '/',
					self::DEFAULT_APP_HOST
				);
			} else {
				self::notice(
					'warning',
					__( 'Your TackQuote server does not offer the dashboard inside WooCommerce yet. Open TackQuote instead:', 'tackquote' ),
					'https://' . self::DEFAULT_APP_HOST . '/',
					self::DEFAULT_APP_HOST
				);
			}
			echo '</div>';
			return;
		}

		$answer = $this->open( 'frame' );
		if ( self::is_disconnected( $answer ) ) {
			// The key was revoked or the store was disconnected: no code was minted.
			$this->client()->forget_capabilities();
			self::disconnected_notice( $settings_url );
			echo '</div>';
			return;
		}
		if ( is_wp_error( $answer ) ) {
			self::notice(
				'error',
				__( 'Could not reach TackQuote. Reload this page in a minute.', 'tackquote' )
			);
			echo '</div>';
			return;
		}

		$status = isset( $answer['status'] ) ? (string) $answer['status'] : '';
		if ( 'linked' === $status ) {
			$this->render_linked( $answer );
		} elseif ( 'unlinked' === $status ) {
			$this->render_unlinked( $answer );
		} else {
			$message = isset( $answer['message'] ) && is_string( $answer['message'] ) ? $answer['message'] : __( 'TackQuote cannot open here right now.', 'tackquote' );
			self::notice( 'warning', $message );
		}
		echo '</div>';
	}

	/**
	 * The store's key is refused (revoked, or the store disconnected): say so and link to
	 * reconnect. One wording for every place the page learns it.
	 *
	 * @since 1.12.0
	 *
	 * @param string $settings_url The Connection tab.
	 */
	private static function disconnected_notice( $settings_url ) {
		self::notice(
			'error',
			__( 'TackQuote no longer accepts this store’s connection (the store was disconnected or its API key was revoked). Reconnect it, then reopen this page.', 'tackquote' ),
			$settings_url,
			__( 'Reconnect to TackQuote', 'tackquote' )
		);
	}

	/**
	 * A linked WordPress user: the bar above the frame, then the frame.
	 *
	 * @param array $answer API answer.
	 */
	private function render_linked( array $answer ) {
		$account = isset( $answer['account'] ) && is_array( $answer['account'] ) ? $answer['account'] : array();
		$who     = '';
		foreach ( array( 'name', 'email' ) as $field ) {
			if ( '' === $who && isset( $account[ $field ] ) && is_string( $account[ $field ] ) ) {
				$who = $account[ $field ];
			}
		}
		echo '<div class="tackquote-embed-bar" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:12px 0;">';
		echo '<span>' . esc_html(
			'' === $who
				? __( 'Signed in to TackQuote through your linked login.', 'tackquote' )
				/* translators: %s: the linked TackQuote account's name or email. */
				: sprintf( __( 'Signed in to TackQuote as %s.', 'tackquote' ), $who )
		) . '</span>';
		self::action_form( self::TAB_ACTION, __( 'Open in a new tab', 'tackquote' ), true, 'button-secondary' );
		self::action_form( self::UNLINK_ACTION, __( 'Unlink my login', 'tackquote' ), false, 'button-link' );
		echo '</div>';

		$src = self::frame_url( $answer );
		if ( '' === $src ) {
			$moved = isset( $answer['frameWithheld'] ) && 'store_address_moved' === $answer['frameWithheld'];
			self::notice(
				'info',
				$moved
					? __( 'This store’s address changed recently, so TackQuote is not shown on this page for a day. Use “Open in a new tab”.', 'tackquote' )
					: __( 'TackQuote does not know this store’s address, so it cannot be shown on this page. Use “Open in a new tab”.', 'tackquote' )
			);
			return;
		}

		/*
		 * `sandbox` keeps the frame from navigating this admin page (no allow-top-navigation);
		 * popups may escape it so "open in a new tab" links inside TackQuote work. The code in
		 * the fragment is redeemed once, by the frame, within two minutes.
		 */
		printf(
			'<iframe class="tackquote-embed-frame" title="%1$s" src="%2$s" referrerpolicy="strict-origin" allow="clipboard-write" sandbox="allow-scripts allow-same-origin allow-forms allow-popups allow-popups-to-escape-sandbox allow-modals allow-downloads allow-storage-access-by-user-activation" style="display:block;width:100%%;min-height:640px;height:calc(100vh - 200px);border:1px solid #c3c4c7;border-radius:4px;background:#fff;"></iframe>',
			esc_attr__( 'TackQuote', 'tackquote' ),
			esc_url( $src )
		);
		echo '<p class="description">' . esc_html__( 'If TackQuote stays blank or asks you to sign in, your browser is blocking sign-in inside this page: use “Open in a new tab”.', 'tackquote' ) . '</p>';
	}

	/**
	 * An unlinked WordPress user: never a session. With the one-click link (1.13.0) ONE primary
	 * button, and the typed code folded under "Have a link code?"; without it (an older
	 * server) the code field only, as 1.12.0.
	 *
	 * @param array $answer API answer.
	 */
	private function render_unlinked( array $answer ) {
		$mismatch = isset( $answer['reason'] ) && 'secret_mismatch' === $answer['reason'];
		$oneclick = in_array( self::ONECLICK_CAPABILITY, $this->client()->server_capabilities(), true );
		echo '<div class="card" style="max-width:640px;">';
		echo '<h2>' . esc_html__( 'Link your TackQuote account', 'tackquote' ) . '</h2>';
		echo '<p>' . esc_html(
			$mismatch
				? __( 'Your WordPress login needs to be linked to TackQuote again on this site.', 'tackquote' )
				: __( 'To use TackQuote here, link your WordPress login to your own TackQuote login. You do this once.', 'tackquote' )
		) . '</p>';
		if ( ! $oneclick ) {
			self::render_code_steps();
			self::render_code_form( __( 'Link my TackQuote account', 'tackquote' ), 'button-primary' );
			echo '</div>';
			return;
		}
		echo '<p>' . esc_html__( 'TackQuote opens in a new tab: sign in as yourself and confirm. Then this page opens TackQuote for you.', 'tackquote' ) . '</p>';
		self::action_form( self::LINK_START_ACTION, __( 'Link my TackQuote account', 'tackquote' ), true, 'button-primary' );
		echo '<p class="description">' . esc_html__( 'TackQuote opens as you, with your own role, and emails you about the new link. If you have no TackQuote login, ask an admin of your TackQuote workspace to invite you.', 'tackquote' ) . '</p>';
		echo '<details style="margin-top:12px;"><summary>' . esc_html__( 'Have a link code?', 'tackquote' ) . '</summary>';
		self::render_code_steps();
		self::render_code_form( __( 'Link with this code', 'tackquote' ), 'button-secondary' );
		echo '</details>';
		echo '</div>';
	}

	/**
	 * Where a link code comes from (the fallback).
	 */
	private static function render_code_steps() {
		echo '<ol>';
		echo '<li>' . esc_html__( 'In TackQuote, open Integrations → WooCommerce and choose “Link a WordPress login”.', 'tackquote' ) . '</li>';
		echo '<li>' . esc_html__( 'Enter the code it shows here. It works once, for 10 minutes.', 'tackquote' ) . '</li>';
		echo '</ol>';
	}

	/**
	 * The link-code form (posts to `CLAIM_ACTION`).
	 *
	 * @param string $label        Button label.
	 * @param string $button_class Button class.
	 */
	private static function render_code_form( $label, $button_class ) {
		printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::CLAIM_ACTION ) . '" />';
		wp_nonce_field( self::CLAIM_ACTION, '_tqnonce' );
		echo '<label for="tackquote-link-code">' . esc_html__( 'Link code', 'tackquote' ) . '</label> ';
		echo '<input type="text" id="tackquote-link-code" name="tq_link_code" class="regular-text" autocomplete="off" spellcheck="false" maxlength="12" placeholder="ABCD-2345" required /> ';
		echo '<button type="submit" class="button ' . esc_attr( $button_class ) . '">' . esc_html( $label ) . '</button>';
		echo '</form>';
	}

	/**
	 * One button posting to admin-post.php with its own nonce.
	 *
	 * @param string $action  admin-post action.
	 * @param string $label   Button label.
	 * @param bool   $new_tab Open the result in a new tab.
	 * @param string $button_class Button class.
	 */
	private static function action_form( $action, $label, $new_tab, $button_class ) {
		printf(
			'<form method="post" action="%1$s" style="display:inline;margin:0;"%2$s>',
			esc_url( admin_url( 'admin-post.php' ) ),
			$new_tab ? ' target="_blank" rel="noopener"' : ''
		);
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />';
		wp_nonce_field( $action, '_tqnonce' );
		echo '<button type="submit" class="button ' . esc_attr( $button_class ) . '">' . esc_html( $label ) . '</button>';
		echo '</form>';
	}

	/**
	 * An admin notice inside the page.
	 *
	 * @param string $type       success|info|warning|error.
	 * @param string $message    Text.
	 * @param string $link_url   Optional link.
	 * @param string $link_label Optional link text.
	 */
	private static function notice( $type, $message, $link_url = '', $link_label = '' ) {
		echo '<div class="notice notice-' . esc_attr( $type ) . ' inline"><p>' . esc_html( $message );
		if ( '' !== $link_url && '' !== $link_label ) {
			echo ' <a href="' . esc_url( $link_url ) . '">' . esc_html( $link_label ) . '</a>';
		}
		echo '</p></div>';
	}

	// ── The buttons (admin-post) ─────────────────────────────────────────────

	/**
	 * Capability and nonce for one admin-post action; dies otherwise.
	 *
	 * @param string $action Action.
	 */
	private static function guard( $action ) {
		self::require_capability();
		$nonce = isset( $_POST['_tqnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_tqnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, $action ) ) {
			self::link_expired();
		}
	}

	/**
	 * Dies unless the current user may open TackQuote.
	 */
	private static function require_capability() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to open TackQuote.', 'tackquote' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Dies with the expired-link message (a missing or stale nonce).
	 */
	private static function link_expired() {
		wp_die( esc_html__( 'This link has expired. Reload the TackQuote page and try again.', 'tackquote' ), '', array( 'response' => 403 ) );
	}

	/**
	 * "Open in a new tab": a fresh one-time code, straight to TackQuote's top-level page.
	 */
	public function handle_tab() {
		self::guard( self::TAB_ACTION );
		$answer = $this->open( 'tab' );
		$url    = is_array( $answer ) && 'linked' === ( $answer['status'] ?? '' ) ? self::tab_url( $answer ) : '';
		if ( '' === $url ) {
			$this->redirect( self::page_url( is_wp_error( $answer ) ? 'failed' : 'unavailable' ), '' );
			return;
		}
		$this->redirect( $url, (string) wp_parse_url( $url, PHP_URL_HOST ) );
	}

	/**
	 * "Link my TackQuote account": post the code the person typed, server to server, with
	 * this WordPress user's id, login and secret. Only fixed messages come back to the page.
	 */
	public function handle_claim() {
		// guard(), written out: the nonce check sits in the function that reads the form.
		self::require_capability();
		if ( ! isset( $_POST['_tqnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_tqnonce'] ) ), self::CLAIM_ACTION ) ) {
			self::link_expired();
		}
		$user_id = (int) get_current_user_id();
		$user    = wp_get_current_user();
		$login   = isset( $user->user_login ) ? (string) $user->user_login : '';
		$code    = isset( $_POST['tq_link_code'] ) ? sanitize_text_field( wp_unslash( $_POST['tq_link_code'] ) ) : '';
		$code    = strtoupper( preg_replace( '/[^A-Za-z0-9-]/', '', $code ) );
		if ( $user_id <= 0 || '' === $login || strlen( $code ) < 8 || strlen( $code ) > 12 ) {
			$this->redirect( self::page_url( 'wrong_code' ), '' );
			return;
		}
		$result = $this->client()->request(
			'POST',
			'/integrations/woocommerce/embed/claim',
			array(
				'code'         => $code,
				'wpUserId'     => (string) $user_id,
				'wpUserLogin'  => function_exists( 'mb_substr' ) ? mb_substr( $login, 0, 60 ) : substr( $login, 0, 60 ),
				'wpUserSecret' => self::user_secret( $user_id ),
			),
			self::OPEN_TIMEOUT
		);
		$flag   = self::claim_result( $result );
		if ( 'disconnected' === $flag ) {
			// As on the page itself: the capabilities were answered for a key that no
			// longer works.
			$this->client()->forget_capabilities();
		}
		$this->redirect( self::page_url( $flag ), '' );
	}

	/**
	 * The result flag for a claim answer.
	 *
	 * @param array|WP_Error $result request() result.
	 * @return string
	 */
	private static function claim_result( $result ) {
		if ( ! is_wp_error( $result ) ) {
			return isset( $result['status'] ) && 'linked' === $result['status'] ? 'linked' : 'failed';
		}
		$data   = $result->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
		// The store's key is refused (401, the API key guard), or there is none: not the
		// code, and not a TackQuote outage. A 403 stays the claim's own refusal.
		if ( 401 === $status || 'tack_no_key' === $result->get_error_code() ) {
			return 'disconnected';
		}
		if ( 400 === $status ) {
			return 'wrong_code';
		}
		if ( 429 === $status ) {
			return 'throttled';
		}
		if ( 403 === $status ) {
			return 'refused';
		}
		// Only TackQuote's own "which store is this" 409 is no_store; the other 409 (this
		// login was linked by another request a moment ago) reads as "try again".
		if ( 409 === $status ) {
			$code = is_array( $data ) && isset( $data['code'] ) ? (string) $data['code'] : '';
			return 'WOO_LINK_NO_STORE' === $code ? 'no_store' : 'failed';
		}
		return 'failed';
	}

	/**
	 * "Unlink my login": TackQuote removes this WordPress user's link (only with their own
	 * secret). Only when it confirms the removal is the secret replaced; on any failure the
	 * secret stays.
	 */
	public function handle_unlink() {
		self::guard( self::UNLINK_ACTION );
		$user_id = (int) get_current_user_id();
		$result  = $this->client()->request(
			'POST',
			'/integrations/woocommerce/embed/unlink',
			array(
				'wpUserId'     => (string) $user_id,
				'wpUserSecret' => self::user_secret( $user_id ),
			),
			self::OPEN_TIMEOUT
		);
		if ( is_wp_error( $result ) || empty( $result['removed'] ) ) {
			// Nothing was unlinked (TackQuote unreachable, or no link for this login): keep
			// the secret, so a live link keeps opening and a retry can still prove it.
			$this->redirect( self::page_url( 'failed' ), '' );
			return;
		}
		delete_user_meta( $user_id, self::SECRET_META );
		$this->redirect( self::page_url( 'unlinked' ), '' );
	}

	// ── One-click link (1.13.0) ──────────────────────────────────────────────

	/**
	 * The current WordPress user's login, clipped to what TackQuote stores.
	 *
	 * @return string
	 */
	private static function current_login() {
		$user  = wp_get_current_user();
		$login = isset( $user->user_login ) ? (string) $user->user_login : '';
		return function_exists( 'mb_substr' ) ? mb_substr( $login, 0, 60 ) : substr( $login, 0, 60 );
	}

	/**
	 * This site's return address for the one-click link, as TackQuote derives it from the
	 * store address it has saved. Sent only so TackQuote can refuse early (and this page offer
	 * the code) when the two differ; TackQuote never sends the browser to this value.
	 *
	 * @return string
	 */
	public static function link_return_url() {
		return admin_url( 'admin-post.php?action=' . self::LINK_RETURN_ACTION );
	}

	/**
	 * The host of the authorize URL TackQuote answered with, or '' when it must not be
	 * opened: not https (http only on a development host), not the TackQuote app for the
	 * default API URL, a query, credentials, another path, or no one-time request in the
	 * fragment.
	 *
	 * @param mixed $url authorizeUrl.
	 * @return string
	 */
	public static function authorize_url_host( $url ) {
		if ( ! is_string( $url ) || strlen( $url ) > 512 ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) ) {
			return '';
		}
		$port   = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		$origin = strtolower( (string) ( $parts['scheme'] ?? '' ) ) . '://' . strtolower( (string) $parts['host'] ) . $port;
		if ( '' === self::app_origin( $origin ) ) {
			return '';
		}
		if ( self::AUTHORIZE_PATH !== ( $parts['path'] ?? '' ) || 1 !== preg_match( '/^request=[A-Za-z0-9_-]{43}$/', (string) ( $parts['fragment'] ?? '' ) ) ) {
			return '';
		}
		return strtolower( (string) $parts['host'] );
	}

	/**
	 * "Link my TackQuote account": start a one-click link for the current WordPress user and
	 * send this (new) tab to TackQuote's authorize page.
	 */
	public function handle_link_start() {
		self::guard( self::LINK_START_ACTION );
		$user_id = (int) get_current_user_id();
		$login   = self::current_login();
		if ( $user_id <= 0 || '' === $login ) {
			$this->redirect( self::page_url( 'failed' ), '' );
			return;
		}
		$verifier = Tack_Connect::new_verifier();
		$state    = Tack_Connect::b64url( random_bytes( 24 ) );
		// Kept with THIS WordPress user only; a newer start replaces it.
		update_user_meta(
			$user_id,
			self::PENDING_META,
			array(
				'verifier' => $verifier,
				'state'    => hash( 'sha256', $state ),
				'iat'      => time(),
			)
		);
		$answer = $this->client()->request(
			'POST',
			'/integrations/woocommerce/embed/link-requests',
			array(
				'wpUserId'            => (string) $user_id,
				'wpUserLogin'         => $login,
				'wpUserSecret'        => self::user_secret( $user_id ),
				'codeChallenge'       => Tack_Connect::challenge( $verifier ),
				'codeChallengeMethod' => 'S256',
				'state'               => $state,
				'returnUrl'           => self::link_return_url(),
			),
			self::OPEN_TIMEOUT
		);

		$url  = is_array( $answer ) ? ( $answer['authorizeUrl'] ?? '' ) : '';
		$host = is_wp_error( $answer ) ? '' : self::authorize_url_host( $url );
		if ( '' === $host ) {
			delete_user_meta( $user_id, self::PENDING_META );
			$flag = is_wp_error( $answer ) ? self::link_start_result( $answer ) : 'bad_open';
			if ( 'disconnected' === $flag ) {
				$this->client()->forget_capabilities();
			}
			$this->redirect( self::page_url( $flag ), '' );
			return;
		}
		$this->redirect( (string) $url, $host );
	}

	/**
	 * The result flag for a refused start.
	 *
	 * @param WP_Error $error request() error.
	 * @return string
	 */
	private static function link_start_result( $error ) {
		$data   = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
		$code   = is_array( $data ) && isset( $data['code'] ) ? (string) $data['code'] : '';
		if ( 401 === $status || 'tack_no_key' === $error->get_error_code() ) {
			return 'disconnected';
		}
		$by_code = array(
			'WOO_LINK_ONECLICK_ADDRESS_MOVED'   => 'moved',
			'WOO_LINK_ONECLICK_RETURN_MISMATCH' => 'address',
			'WOO_LINK_ONECLICK_NO_ADDRESS'      => 'no_oneclick',
			'WOO_LINK_NO_STORE'                 => 'no_store',
		);
		if ( isset( $by_code[ $code ] ) ) {
			return $by_code[ $code ];
		}
		if ( 403 === $status ) {
			return 'refused';
		}
		if ( 409 === $status ) {
			return 'no_oneclick';
		}
		return 'failed';
	}

	/**
	 * `admin_post_nopriv_tackquote_link_return`: the tab came back without a WordPress
	 * session. WordPress's login screen, then back here signed in (`auth_redirect()`).
	 */
	public function handle_link_return_signed_out() {
		auth_redirect();
	}

	/**
	 * `admin_post_tackquote_link_return`: TackQuote sent this tab back with a one-time code.
	 */
	public function handle_link_return() {
		// The address bar holds a one-time code until the redirect below replaces it.
		if ( ! headers_sent() ) {
			header( 'Referrer-Policy: no-referrer' );
		}

		/*
		 * A redirect from TackQuote cannot carry a WordPress nonce; finish_link() checks the
		 * state against this user's single-use pending meta instead. Only these three
		 * parameters are read.
		 */
		$query = array();
		foreach ( array( 'code', 'state', 'error' ) as $name ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above: the single-use state is the forgery check.
			if ( isset( $_GET[ $name ] ) && is_string( $_GET[ $name ] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above: the single-use state is the forgery check.
				$query[ $name ] = sanitize_text_field( wp_unslash( $_GET[ $name ] ) );
			}
		}
		$this->redirect( self::page_url( $this->finish_link( $query ) ), '' );
	}

	/**
	 * Check the return and exchange the code. Never reports a link TackQuote did not confirm.
	 *
	 * @param array $query code, state, error.
	 * @return string The result flag for the page.
	 */
	public function finish_link( $query ) {
		self::require_capability();
		$user_id = (int) get_current_user_id();
		$query   = is_array( $query ) ? $query : array();
		$code    = isset( $query['code'] ) && is_string( $query['code'] ) ? $query['code'] : '';
		$state   = isset( $query['state'] ) && is_string( $query['state'] ) ? $query['state'] : '';
		$error   = isset( $query['error'] ) && is_string( $query['error'] ) ? sanitize_key( $query['error'] ) : '';

		// Taken once, before anything else can use it: a replayed return finds nothing.
		$pending = get_user_meta( $user_id, self::PENDING_META, true );
		delete_user_meta( $user_id, self::PENDING_META );
		$iat = is_array( $pending ) ? (int) ( $pending['iat'] ?? 0 ) : 0;
		if ( $user_id <= 0 || ! is_array( $pending ) || '' === $state
			|| time() - $iat > self::LINK_TTL || $iat > time() + 60
			|| ! hash_equals( (string) ( $pending['state'] ?? '' ), hash( 'sha256', $state ) )
			|| ! is_string( $pending['verifier'] ?? null ) ) {
			return 'expired';
		}
		if ( '' !== $error ) {
			return 'access_denied' === $error ? 'cancelled' : 'expired';
		}
		if ( 1 !== preg_match( '/^[A-Za-z0-9_-]{43}$/', $code ) ) {
			return 'expired';
		}
		$result = $this->client()->request(
			'POST',
			'/integrations/woocommerce/embed/link-exchange',
			array(
				'code'         => $code,
				'codeVerifier' => $pending['verifier'],
				'wpUserId'     => (string) $user_id,
				'wpUserSecret' => self::user_secret( $user_id ),
			),
			self::OPEN_TIMEOUT
		);
		if ( ! is_wp_error( $result ) ) {
			return isset( $result['status'] ) && 'linked' === $result['status'] ? 'linked' : 'failed';
		}
		$data   = $result->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
		if ( 401 === $status || 'tack_no_key' === $result->get_error_code() ) {
			$this->client()->forget_capabilities();
			return 'disconnected';
		}
		if ( 400 === $status ) {
			return 'grant';
		}
		if ( 403 === $status ) {
			return 'refused';
		}
		return 'failed';
	}

	/**
	 * Redirect and end the request. Overridden by the tests.
	 *
	 * @param string $url  Target.
	 * @param string $host External host to allow, or ''.
	 */
	protected function redirect( $url, $host ) {
		if ( '' !== $host ) {
			add_filter(
				'allowed_redirect_hosts',
				function ( $hosts ) use ( $host ) {
					$hosts[] = $host;
					return $hosts;
				}
			);
		}
		wp_safe_redirect( $url );
		exit;
	}
}
