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
 *      one-time code their own TackQuote user asks for under Connections → WooCommerce →
 *      "Link a WordPress login" (10 minutes, works once). The plugin posts the code server to
 *      server with the key, this WordPress user's id, login and secret
 *      (`POST /integrations/woocommerce/embed/claim`); TackQuote links that TackQuote user to
 *      THIS WordPress user and emails the account. Nobody is ever signed in as someone else,
 *      and the role in TackQuote is the linked person's own. A stolen API key or a colleague
 *      with the same capability needs the person's own code.
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

	/** The admin-post actions, each with its own nonce. */
	const TAB_ACTION    = 'tackquote_embed_tab';
	const CLAIM_ACTION  = 'tackquote_embed_claim';
	const UNLINK_ACTION = 'tackquote_embed_unlink';

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
			'wrong_code'  => array( 'error', __( 'That code is not right, or it has expired. Check the code TackQuote shows under Connections → WooCommerce, or ask for a new one there.', 'tackquote' ) ),
			'throttled'   => array( 'error', __( 'Too many wrong codes from your WordPress login. Wait 15 minutes, then try again.', 'tackquote' ) ),
			'refused'     => array( 'error', __( 'TackQuote could not link that account. Ask an admin of your TackQuote workspace for help.', 'tackquote' ) ),
			'no_store'    => array( 'error', __( 'TackQuote cannot tell which of your connected stores this is. Use one API key per WordPress site.', 'tackquote' ) ),
			'failed'      => array( 'error', __( 'TackQuote did not answer just now. Your link was not changed. Try again in a minute.', 'tackquote' ) ),
			'unavailable' => array( 'error', __( 'TackQuote could not open a new tab for you. Reload this page to see why.', 'tackquote' ) ),
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

		// Read-only: selects one of a fixed set of messages, so no nonce applies.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$flag    = isset( $_GET['tq_embed'] ) ? sanitize_key( wp_unslash( $_GET['tq_embed'] ) ) : '';
		$results = self::results();
		if ( isset( $results[ $flag ] ) ) {
			self::notice( $results[ $flag ][0], $results[ $flag ][1] );
		}

		$settings_url = Tack_Settings::tab_url( 'connection' );
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
			self::notice(
				'error',
				__( 'TackQuote no longer accepts this store’s connection (the store was disconnected or its API key was revoked). Reconnect it, then reopen this page.', 'tackquote' ),
				$settings_url,
				__( 'Reconnect to TackQuote', 'tackquote' )
			);
			echo '</div>';
			return;
		}
		if ( ! in_array( self::SERVER_CAPABILITY, $this->client()->server_capabilities(), true ) ) {
			self::notice(
				'warning',
				__( 'Your TackQuote server does not offer the dashboard inside WooCommerce yet. Open TackQuote at app.tackquote.com instead.', 'tackquote' )
			);
			echo '</div>';
			return;
		}

		$answer = $this->open( 'frame' );
		if ( self::is_disconnected( $answer ) ) {
			// The key was revoked or the store was disconnected: no code was minted.
			$this->client()->forget_capabilities();
			self::notice(
				'error',
				__( 'TackQuote no longer accepts this store’s connection (the store was disconnected or its API key was revoked). Reconnect it, then reopen this page.', 'tackquote' ),
				$settings_url,
				__( 'Reconnect to TackQuote', 'tackquote' )
			);
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
	 * An unlinked WordPress user: never a session, only the code field.
	 *
	 * @param array $answer API answer.
	 */
	private function render_unlinked( array $answer ) {
		$mismatch = isset( $answer['reason'] ) && 'secret_mismatch' === $answer['reason'];
		echo '<div class="card" style="max-width:640px;">';
		echo '<h2>' . esc_html__( 'Link your TackQuote account', 'tackquote' ) . '</h2>';
		echo '<p>' . esc_html(
			$mismatch
				? __( 'Your WordPress login needs to be linked to TackQuote again on this site.', 'tackquote' )
				: __( 'To use TackQuote here, link your WordPress login to your own TackQuote login. You do this once.', 'tackquote' )
		) . '</p>';
		echo '<ol>';
		echo '<li>' . esc_html__( 'In TackQuote, open Connections → WooCommerce and choose “Link a WordPress login”.', 'tackquote' ) . '</li>';
		echo '<li>' . esc_html__( 'Enter the code it shows here. It works once, for 10 minutes.', 'tackquote' ) . '</li>';
		echo '</ol>';
		echo '<p class="description">' . esc_html__( 'TackQuote then opens here as you, with your own role, and emails you about the new link. If you have no TackQuote login, ask an admin of your TackQuote workspace to invite you.', 'tackquote' ) . '</p>';
		printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::CLAIM_ACTION ) . '" />';
		wp_nonce_field( self::CLAIM_ACTION, '_tqnonce' );
		echo '<label for="tackquote-link-code">' . esc_html__( 'Link code', 'tackquote' ) . '</label> ';
		echo '<input type="text" id="tackquote-link-code" name="tq_link_code" class="regular-text" autocomplete="off" spellcheck="false" maxlength="12" placeholder="ABCD-2345" required /> ';
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Link my TackQuote account', 'tackquote' ) . '</button>';
		echo '</form>';
		echo '</div>';
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
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to open TackQuote.', 'tackquote' ), '', array( 'response' => 403 ) );
		}
		$nonce = isset( $_POST['_tqnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_tqnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, $action ) ) {
			wp_die( esc_html__( 'This link has expired. Reload the TackQuote page and try again.', 'tackquote' ), '', array( 'response' => 403 ) );
		}
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
		self::guard( self::CLAIM_ACTION );
		$user_id = (int) get_current_user_id();
		$user    = wp_get_current_user();
		$login   = isset( $user->user_login ) ? (string) $user->user_login : '';
		$code    = isset( $_POST['tq_link_code'] ) ? sanitize_text_field( wp_unslash( $_POST['tq_link_code'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
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
		$this->redirect( self::page_url( self::claim_result( $result ) ), '' );
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
		if ( 400 === $status ) {
			return 'wrong_code';
		}
		if ( 429 === $status ) {
			return 'throttled';
		}
		if ( 403 === $status ) {
			return 'refused';
		}
		if ( 409 === $status ) {
			return 'no_store';
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
