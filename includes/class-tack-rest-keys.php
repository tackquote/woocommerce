<?php
/**
 * "Allow catalogue and order sync": WooCommerce REST API keys for TackQuote, created by
 * WooCommerce itself after the store administrator approves on WooCommerce's own screen.
 *
 * Why: a store connected with Connect with TackQuote or a pasted TackQuote key works
 * through this plugin (quotes, prices, forms, orders), but TackQuote cannot read the
 * catalogue, stock or categories, receive WooCommerce webhooks (Flows) or write orders
 * back without WooCommerce REST API keys. WooCommerce has an endpoint made for exactly
 * this (REST API docs, "Auto generating API keys using our Application Authentication
 * Endpoint"), so nothing has to be copied by hand.
 *
 * The flow, started by an administrator pressing the button (or right after Connect
 * with TackQuote):
 *
 *   1. start    POST admin-post.php?action=tackquote_rest_keys_start (capability +
 *               nonce). This site asks TackQuote, server to server, for an authorization
 *               (`POST /integrations/woocommerce/rest-keys/authorize`, with this site's
 *               own admin-post.php address). TackQuote answers WooCommerce's authorize
 *               address ON THIS SITE (`/?wc-auth-version=1&wc-auth-route=authorize&...`,
 *               scope read_write); the browser goes there.
 *   2. approve  WooCommerce shows "TackQuote would like to connect to your store".
 *               Approve: WooCommerce creates a Read/Write key and posts it to TackQuote
 *               directly (it never passes through this site's pages or the browser).
 *               Deny: nothing is created.
 *   3. return   WooCommerce sends the browser back to
 *               admin-post.php?action=tackquote_rest_keys_return with `success=1|0`.
 *               Nothing secret arrives here; this only shows the outcome and asks
 *               TackQuote for the status.
 *
 * TackQuote checks the new key with a live read before it uses it. Declining, or a key
 * TackQuote cannot use, changes nothing for the plugin: it keeps working as before.
 *
 * Offered only when the TackQuote server lists the `rest_keys` capability.
 *
 * @package TackQuotes
 * @since   1.12.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The button, its start and return handlers, and the status TackQuote reports.
 */
class Tack_Rest_Keys {

	const START_ACTION  = 'tackquote_rest_keys_start';
	const RETURN_ACTION = 'tackquote_rest_keys_return';

	const NONCE_ACTION = 'tack_quotes_rest_keys_start';
	const NONCE_FIELD  = '_tack_rest_keys_nonce';

	/**
	 * WooCommerce's own id of the key TackQuote holds (`key_id`), so uninstalling the
	 * plugin can delete that key. Not autoloaded.
	 */
	const OPTION_WC_KEY_ID = 'tack_quotes_wc_rest_key_id';

	/**
	 * Cached status answer, five minutes.
	 */
	const STATUS_TRANSIENT = 'tack_quotes_rest_keys_status';

	/**
	 * The capability a TackQuote server lists when it takes these routes.
	 */
	const CAPABILITY = 'rest_keys';

	/**
	 * HTTP client; injectable for tests.
	 *
	 * @var Tack_Api_Client|null
	 */
	private $client;

	/**
	 * Constructor.
	 *
	 * @param Tack_Api_Client|null $client Optional HTTP client.
	 */
	public function __construct( $client = null ) {
		$this->client = $client;
	}

	/**
	 * Hooks (admin-post.php; logged-in only).
	 */
	public function init() {
		add_action( 'admin_post_' . self::START_ACTION, array( $this, 'handle_start' ) );
		add_action( 'admin_post_' . self::RETURN_ACTION, array( $this, 'handle_return' ) );
	}

	/**
	 * The HTTP client.
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
	 * Does the saved key's server offer this?
	 *
	 * @return bool
	 */
	public function is_offered() {
		return '' !== (string) get_option( 'tack_quotes_api_key', '' ) && $this->client()->supports( self::CAPABILITY );
	}

	// ── Start ─────────────────────────────────────────────────────────────────

	/**
	 * `admin_post_tackquote_rest_keys_start`.
	 */
	public function handle_start() {
		if ( ! Tack_Connect::can_connect() ) {
			wp_die( esc_html__( 'You do not have permission to change how this store connects to TackQuote.', 'tackquote' ), '', array( 'response' => 403 ) );
		}
		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'This link has expired. Go back to TackQuote → Settings → Connection and press the button again.', 'tackquote' ), '', array( 'response' => 403 ) );
		}
		$outcome = $this->start();
		$this->redirect( isset( $outcome['redirect'] ) ? $outcome['redirect'] : Tack_Settings::tab_url( 'connection' ) );
	}

	/**
	 * Ask TackQuote for an authorization and check the address it answers.
	 *
	 * @return array `{redirect}` on success; `{type, message}` (also stored as the
	 *               notice) on a refusal.
	 */
	public function start() {
		if ( ! $this->is_offered() ) {
			return self::notice( 'error', __( 'This TackQuote server cannot set up catalogue and order sync yet. Nothing was changed.', 'tackquote' ) );
		}
		if ( ! self::site_is_https() ) {
			return self::notice( 'error', __( 'Catalogue and order sync needs your site to use https. Nothing was changed.', 'tackquote' ) );
		}
		$answer = $this->client()->request(
			'POST',
			'/integrations/woocommerce/rest-keys/authorize',
			array( 'adminPostUrl' => admin_url( 'admin-post.php' ) ),
			Tack_Api_Client::INTERACTIVE_TIMEOUT
		);
		if ( is_wp_error( $answer ) ) {
			$data   = $answer->get_error_data();
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
			$said   = 0 !== $status && is_array( $data ) && ! empty( $data['json'] ) ? self::clip( wp_strip_all_tags( $answer->get_error_message() ), 300 ) : '';
			if ( 0 === $status ) {
				return self::notice( 'error', __( 'Could not reach TackQuote, so nothing was changed. Try again in a few minutes.', 'tackquote' ) );
			}
			$message = __( 'TackQuote did not start catalogue and order sync, so nothing was changed.', 'tackquote' );
			return self::notice( 'error', '' !== $said ? $message . ' ' . $said : $message );
		}
		$url = isset( $answer['authorizeUrl'] ) && is_string( $answer['authorizeUrl'] ) ? $answer['authorizeUrl'] : '';
		if ( ! self::is_own_authorize_url( $url ) ) {
			return self::notice( 'error', __( 'TackQuote answered with an address this plugin will not open, so nothing was changed.', 'tackquote' ) );
		}
		delete_transient( self::STATUS_TRANSIENT );
		return array( 'redirect' => $url );
	}

	/**
	 * Is `$url` WooCommerce's authorize endpoint on THIS site, asking Read/Write for
	 * TackQuote with an https callback? The browser must never be sent anywhere else.
	 *
	 * @param string $url Address TackQuote answered.
	 * @return bool
	 */
	public static function is_own_authorize_url( $url ) {
		$parts = wp_parse_url( (string) $url );
		$home  = wp_parse_url( home_url() );
		if ( ! is_array( $parts ) || ! is_array( $home ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) ) {
			return false;
		}
		if ( 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) )
			|| strtolower( (string) ( $parts['host'] ?? '' ) ) !== strtolower( (string) ( $home['host'] ?? '' ) )
			|| (int) ( $parts['port'] ?? 443 ) !== (int) ( $home['port'] ?? 443 ) ) {
			return false;
		}
		$home_path = rtrim( (string) ( $home['path'] ?? '' ), '/' );
		if ( rtrim( (string) ( $parts['path'] ?? '/' ), '/' ) !== $home_path ) {
			return false;
		}
		parse_str( (string) ( $parts['query'] ?? '' ), $query );
		$callback = isset( $query['callback_url'] ) && is_string( $query['callback_url'] ) ? $query['callback_url'] : '';
		return '1' === ( $query['wc-auth-version'] ?? '' )
			&& 'authorize' === ( $query['wc-auth-route'] ?? '' )
			&& 'read_write' === ( $query['scope'] ?? '' )
			&& 0 === strpos( $callback, 'https://' )
			&& '' !== ( $query['user_id'] ?? '' );
	}

	/**
	 * Right after a successful Connect with TackQuote: the authorize address to continue
	 * to, or '' when the server does not offer it or TackQuote already holds keys.
	 *
	 * @return string
	 */
	public function url_after_connect() {
		if ( ! $this->is_offered() ) {
			return '';
		}
		$status = $this->status( true );
		if ( in_array( $status['status'], array( 'connected', 'pending' ), true ) ) {
			return '';
		}
		$outcome = $this->start();
		return isset( $outcome['redirect'] ) ? $outcome['redirect'] : '';
	}

	// ── Return ────────────────────────────────────────────────────────────────

	/**
	 * `admin_post_tackquote_rest_keys_return`. WooCommerce appends `success` (1 approved,
	 * 0 denied) and the opaque `user_id` it was given; only `success` is read, and only
	 * to choose the message. The keys never come this way.
	 */
	public function handle_return() {
		if ( ! Tack_Connect::can_connect() ) {
			wp_die( esc_html__( 'You do not have permission to change how this store connects to TackQuote.', 'tackquote' ), '', array( 'response' => 403 ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a redirect from WooCommerce's own consent screen cannot carry a nonce. Behind can_connect() above, and all it changes is a cache: the cached sync status (kept at most five minutes) is cleared and re-read, and this admin's one-time notice is set.
		$success = isset( $_GET['success'] ) && '1' === sanitize_key( wp_unslash( $_GET['success'] ) );
		$this->finish( $success );
		$this->redirect( Tack_Settings::tab_url( 'connection' ) );
	}

	/**
	 * The notice for a return.
	 *
	 * @param bool $approved WooCommerce's `success`.
	 * @return array{type:string,message:string}
	 */
	public function finish( $approved ) {
		delete_transient( self::STATUS_TRANSIENT );
		if ( ! $approved ) {
			return self::notice( 'warning', __( 'Catalogue and order sync was not allowed. Quotes, prices, forms and orders keep working through this plugin.', 'tackquote' ) );
		}
		$status = $this->status( true );
		if ( 'connected' === $status['status'] ) {
			return self::notice( 'success', __( 'Catalogue and order sync is on. TackQuote checked the new WooCommerce key and starts the first catalogue sync.', 'tackquote' ) );
		}
		if ( 'failed' === $status['status'] ) {
			return self::notice( 'error', __( 'WooCommerce created the key, but TackQuote could not use it:', 'tackquote' ) . ' ' . ( '' !== $status['message'] ? $status['message'] : __( 'no reason was given.', 'tackquote' ) ) );
		}
		return self::notice( 'success', __( 'WooCommerce approved catalogue and order sync. TackQuote is checking the new key; this page shows when it is on.', 'tackquote' ) );
	}

	// ── Status ────────────────────────────────────────────────────────────────

	/**
	 * What TackQuote holds: `{status: none|pending|connected|failed|unknown, message}`.
	 * Cached for five minutes; `unknown` when TackQuote could not be asked.
	 *
	 * @param bool $refresh Ignore the cache.
	 * @return array{status:string,message:string}
	 */
	public function status( $refresh = false ) {
		$cached = get_transient( self::STATUS_TRANSIENT );
		if ( ! $refresh && is_array( $cached ) && isset( $cached['status'], $cached['message'] ) ) {
			return $cached;
		}
		$answer = $this->client()->request( 'GET', '/integrations/woocommerce/rest-keys/status', null, Tack_Api_Client::INTERACTIVE_TIMEOUT );
		if ( is_wp_error( $answer ) ) {
			return array(
				'status'  => 'unknown',
				'message' => '',
			);
		}
		$status = isset( $answer['status'] ) && in_array( $answer['status'], array( 'none', 'pending', 'connected', 'failed' ), true ) ? $answer['status'] : 'unknown';
		$result = array(
			'status'  => $status,
			'message' => isset( $answer['message'] ) && is_string( $answer['message'] ) ? self::clip( wp_strip_all_tags( $answer['message'] ), 300 ) : '',
		);
		if ( isset( $answer['wcKeyId'] ) && is_int( $answer['wcKeyId'] ) && $answer['wcKeyId'] > 0 ) {
			update_option( self::OPTION_WC_KEY_ID, $answer['wcKeyId'], false );
		}
		set_transient( self::STATUS_TRANSIENT, $result, 'pending' === $status ? MINUTE_IN_SECONDS : 5 * MINUTE_IN_SECONDS );
		return $result;
	}

	// ── Presentation ──────────────────────────────────────────────────────────

	/**
	 * The card on the Connection tab. Shown only when the server offers it.
	 */
	public function render_card() {
		if ( ! $this->is_offered() ) {
			return;
		}
		// 1.13.0: shop managers see the status; only someone who may connect the store
		// (Tack_Connect::can_connect()) gets the button, which would refuse them anyway.
		$can    = Tack_Connect::can_connect();
		$status = $this->status();

		echo '<section class="tack-card tack-rest-keys" aria-labelledby="tack-rest-keys-heading"><div class="tack-card__head"><h2 id="tack-rest-keys-heading">' . esc_html__( 'Catalogue and order sync', 'tackquote' ) . '</h2>';
		if ( 'connected' === $status['status'] ) {
			echo '<span class="tack-pill tack-pill--ok">' . esc_html__( 'On', 'tackquote' ) . '</span>';
		} elseif ( 'pending' === $status['status'] ) {
			echo '<span class="tack-pill">' . esc_html__( 'Checking', 'tackquote' ) . '</span>';
		}
		echo '</div><div class="tack-card__body">';

		if ( 'connected' === $status['status'] ) {
			echo '<p>' . esc_html__( 'TackQuote holds a WooCommerce REST API key for this store: it syncs your products, stock and categories, receives store webhooks for Flows, and writes orders back. The key is listed under WooCommerce → Settings → Advanced → REST API; revoking it there stops these.', 'tackquote' ) . '</p>';
		} elseif ( 'pending' === $status['status'] ) {
			echo '<p>' . esc_html__( 'WooCommerce sent TackQuote the key. TackQuote is checking it with a live read; reload this page in a minute.', 'tackquote' ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'Quotes, prices, forms and orders already work through this plugin. Allow catalogue and order sync so TackQuote can also bring in your products, stock and categories, receive store webhooks for Flows, and write orders back to WooCommerce.', 'tackquote' ) . '</p>';
			if ( 'failed' === $status['status'] ) {
				echo '<p class="tack-field-error">' . esc_html__( 'The last attempt did not work:', 'tackquote' ) . ' ' . esc_html( '' !== $status['message'] ? $status['message'] : __( 'no reason was given.', 'tackquote' ) ) . '</p>';
			}

			/*
			 * An http site can only be refused (start() checks the same), so it gets the
			 * reason up front and a disabled, secondary button instead of a live primary
			 * one that leads to an error (live QA 2026-10-26).
			 */
			$https = self::site_is_https();
			if ( ! $https ) {
				echo '<p class="tack-field-error">' . esc_html__( 'Catalogue and order sync needs your site to use https: WooCommerce sends the key only to an https site. Switch the site address to https (Settings → General), then come back here.', 'tackquote' ) . '</p>';
			}
			if ( $can ) {
				printf( '<form method="post" action="%s" class="tack-rest-keys__form">', esc_url( admin_url( 'admin-post.php' ) ) );
				wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
				printf( '<input type="hidden" name="action" value="%s" />', esc_attr( self::START_ACTION ) );
				submit_button( __( 'Allow catalogue and order sync', 'tackquote' ), $https ? 'primary' : 'secondary', 'tack_rest_keys', false, $https ? null : array( 'disabled' => 'disabled' ) );
				echo '</form>';
				echo '<p class="description">' . esc_html__( 'Opens WooCommerce\'s own approval screen. WooCommerce creates a Read/Write REST API key and sends it to TackQuote directly; it is never shown here. Needs https.', 'tackquote' ) . '</p>';
			} else {
				echo '<p class="description">' . esc_html__( 'Only an administrator can allow catalogue and order sync.', 'tackquote' ) . '</p>';
			}
		}
		echo '</div></section>';
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Is the site's address https? WooCommerce's key approval sends the key only to one,
	 * so the start refuses an http site and the card says so before the click.
	 *
	 * @since 1.12.0
	 *
	 * @return bool
	 */
	public static function site_is_https() {
		return 'https' === strtolower( (string) wp_parse_url( home_url(), PHP_URL_SCHEME ) );
	}

	/**
	 * Remember an outcome for the current administrator's next settings page view (the
	 * same notice Connect with TackQuote uses).
	 *
	 * @param string $type    success | warning | error.
	 * @param string $message Translated text.
	 * @return array{type:string,message:string}
	 */
	private static function notice( $type, $message ) {
		$notice = array(
			'type'    => $type,
			'message' => $message,
		);
		set_transient( Tack_Connect::NOTICE_PREFIX . get_current_user_id(), $notice, 5 * MINUTE_IN_SECONDS );
		return $notice;
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

	/**
	 * Redirect and end the request. Only ever to this site (the authorize address was
	 * checked to be on it), so wp_safe_redirect() needs no extra host.
	 *
	 * @param string $url Target.
	 */
	protected function redirect( $url ) {
		wp_safe_redirect( $url );
		exit;
	}
}
