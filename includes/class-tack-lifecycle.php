<?php
/**
 * Tell TackQuote when the plugin is deactivated (1.12.0), with the merchant's optional
 * answer to "Why are you deactivating?" (1.16.0).
 *
 * TackQuote then pauses this store's connection: nothing syncs to or from a store whose
 * plugin is off, and the connection page in TackQuote says so instead of showing a
 * store that silently stopped answering. When the plugin is activated again, its first
 * request to TackQuote resumes the SAME connection, so links between WordPress customers
 * and TackQuote buyers are kept.
 *
 * Deleting the plugin sends `uninstalled` from uninstall.php (which runs without the
 * plugin's own files, so it carries its own copy of this request). uninstall.php cannot
 * show anything: WordPress runs it after the merchant confirmed the deletion, so the
 * question is asked only here, on Plugins > Deactivate.
 *
 * Best effort and short: a deactivation must never hang on TackQuote. Sent only when the
 * server lists the `lifecycle` capability (an older server has no such route); with no
 * cached capability list it pings once with the same 3-second limit, never the longer
 * page-view timeout.
 *
 * The question (1.16.0). On Plugins, "Deactivate" on this plugin opens a small dialog:
 * a reason, an optional note and "I would like help from TackQuote". "Skip and
 * deactivate" has the focus and deactivates exactly as before; Cancel (or Esc)
 * deactivates nothing. Only "Send and deactivate" sends the answer, and only to a
 * TackQuote server that lists `lifecycle_feedback`; the dialog is not offered otherwise,
 * nor without an API key, nor in the network admin, nor without JavaScript or `<dialog>`
 * support (the link then works as it always did). The answer is posted to admin-post.php
 * (nonce + `activate_plugins`), kept for ten minutes in a transient for that user only,
 * and read once by the deactivation below, which carries it in the same
 * `plugin-lifecycle` call. Nothing is logged. A failed call never blocks the
 * deactivation and is never reported as sent.
 *
 * Sources (developer.wordpress.org, read 2026-11-25): Plugin Handbook "Activation /
 * Deactivation Hooks" and "Uninstall Methods" (deactivation runs on the deactivating
 * request; uninstall runs on deletion), the `plugin_action_links_{$plugin_file}` filter
 * (its `deactivate` key), WP_Plugins_List_Table::single_row(), "Checking User
 * Capabilities", the common-issues note on nonces (`wp_unslash` + `sanitize_text_field`
 * before `wp_verify_nonce`), and Detailed Plugin Guidelines 6 and 7 (a SaaS interface;
 * no external contact without explicit consent: the answer goes only on the merchant's
 * own click, and is documented in readme.txt, External services 16).
 *
 * @package TackQuotes
 * @since   1.12.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The deactivation notice and the optional deactivation question.
 */
class Tack_Lifecycle {

	/**
	 * Seconds the notice may take.
	 */
	const TIMEOUT = 3;

	/**
	 * The admin-post action (and nonce action) of the deactivation question.
	 *
	 * @since 1.16.0
	 */
	const FEEDBACK_ACTION = 'tack_quotes_deactivation_feedback';

	/**
	 * Transient prefix for one user's answer; the WordPress user ID follows.
	 *
	 * @since 1.16.0
	 */
	const FEEDBACK_TRANSIENT = 'tack_quotes_deactivation_feedback_';

	/**
	 * Seconds an answer waits for the deactivation it belongs to.
	 *
	 * @since 1.16.0
	 */
	const FEEDBACK_TTL = 600;

	/**
	 * The longest note TackQuote accepts (`details`, 4000 characters). TackQuote strips
	 * markup, removes email addresses and phone numbers, and keeps at most 1000.
	 *
	 * @since 1.16.0
	 */
	const DETAILS_MAX = 4000;

	/**
	 * The server capability that takes `reason`, `details`, `wantsHelp`, `pluginVersion`.
	 *
	 * @since 1.16.0
	 */
	const FEEDBACK_CAPABILITY = 'lifecycle_feedback';

	/**
	 * Whether this request's Plugins screen renders the dialog.
	 *
	 * @var bool
	 */
	private static $armed = false;

	/**
	 * The reasons TackQuote knows (its `UninstallReason` list). Anything else is never sent.
	 *
	 * @since 1.16.0
	 *
	 * @return array<string,string> value => label.
	 */
	public static function reasons() {
		return array(
			'missing_feature' => __( 'It is missing a feature I need', 'tackquote' ),
			'too_complex'     => __( 'It is too hard to set up or use', 'tackquote' ),
			'bug_or_error'    => __( 'Something does not work', 'tackquote' ),
			'price'           => __( 'The price', 'tackquote' ),
			'switched_app'    => __( 'I switched to another app', 'tackquote' ),
			'temporary'       => __( 'Only for a while (testing or troubleshooting)', 'tackquote' ),
			'store_closing'   => __( 'My store is closing', 'tackquote' ),
			'not_needed'      => __( 'I no longer need it', 'tackquote' ),
			'other'           => __( 'Another reason', 'tackquote' ),
		);
	}

	/**
	 * Register the deactivation question (admin requests only).
	 *
	 * @since 1.16.0
	 */
	public static function init() {
		add_filter( 'plugin_action_links_' . plugin_basename( TACK_QUOTES_FILE ), array( __CLASS__, 'mark_deactivate_link' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_footer', array( __CLASS__, 'render_dialog' ) );
		add_action( 'admin_post_' . self::FEEDBACK_ACTION, array( __CLASS__, 'handle_feedback' ) );
	}

	/**
	 * Whether to offer the question to the current user on this request: they can
	 * deactivate plugins, a key is saved, this is not the network admin, and the last
	 * cached ping listed `lifecycle_feedback`. No ping is made for a page view: with no
	 * cached list the question is simply not asked.
	 *
	 * @since 1.16.0
	 *
	 * @param Tack_Api_Client|null $client Optional HTTP client (tests).
	 * @return bool
	 */
	public static function should_ask( $client = null ) {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return false;
		}
		if ( function_exists( 'is_network_admin' ) && is_network_admin() ) {
			return false;
		}
		if ( '' === (string) get_option( 'tack_quotes_api_key', '' ) ) {
			return false;
		}
		$client = $client ? $client : new Tack_Api_Client();
		$caps   = $client->cached_capabilities();
		return is_array( $caps ) && in_array( self::FEEDBACK_CAPABILITY, $caps, true );
	}

	/**
	 * Mark this plugin's "Deactivate" link so the dialog script can find it. The link's
	 * own address (WordPress's nonce-protected deactivation) is not changed.
	 *
	 * @since 1.16.0
	 *
	 * @param array $actions Plugin action links.
	 * @return array
	 */
	public static function mark_deactivate_link( $actions ) {
		if ( ! is_array( $actions ) || ! isset( $actions['deactivate'] ) || ! is_string( $actions['deactivate'] ) || ! self::should_ask() ) {
			return $actions;
		}
		$actions['deactivate'] = (string) preg_replace( '/<a\s/', '<a data-tackquote-deactivate="1" ', $actions['deactivate'], 1 );
		return $actions;
	}

	/**
	 * Load the dialog script and style on Plugins only.
	 *
	 * @since 1.16.0
	 *
	 * @param string $hook_suffix The admin page.
	 */
	public static function enqueue( $hook_suffix ) {
		if ( 'plugins.php' !== $hook_suffix || ! self::should_ask() ) {
			return;
		}
		self::$armed = true;
		wp_enqueue_style( 'tackquote-deactivate-feedback', TACK_QUOTES_URL . 'assets/css/tack-deactivate-feedback.css', array(), TACK_QUOTES_VERSION );
		wp_enqueue_script( 'tackquote-deactivate-feedback', TACK_QUOTES_URL . 'assets/js/tack-deactivate-feedback.js', array(), TACK_QUOTES_VERSION, true );
	}

	/**
	 * The dialog, rendered once on Plugins when the script was loaded. Without
	 * JavaScript (or without `<dialog>` support) it stays hidden and Deactivate works as
	 * it always did.
	 *
	 * @since 1.16.0
	 */
	public static function render_dialog() {
		if ( ! self::$armed ) {
			return;
		}
		echo self::dialog_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every value is escaped inside dialog_html().
	}

	/**
	 * The dialog markup. Every text and attribute is escaped here.
	 *
	 * @since 1.16.0
	 *
	 * @return string
	 */
	public static function dialog_html() {
		$html  = '<dialog id="tackquote-deactivate-feedback" class="tackquote-deactivate-feedback" aria-labelledby="tackquote-deactivate-feedback-title">';
		$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$html .= '<input type="hidden" name="action" value="' . esc_attr( self::FEEDBACK_ACTION ) . '" />';
		$html .= '<input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( self::FEEDBACK_ACTION ) ) . '" />';
		$html .= '<h2 id="tackquote-deactivate-feedback-title">' . esc_html__( 'Before you deactivate TackQuote', 'tackquote' ) . '</h2>';
		$html .= '<p>' . esc_html__( 'If you have a moment, tell us why. This is optional: "Skip and deactivate" sends no answer.', 'tackquote' ) . '</p>';
		$html .= '<fieldset><legend>' . esc_html__( 'Why are you deactivating?', 'tackquote' ) . '</legend>';
		foreach ( self::reasons() as $value => $label ) {
			$html .= '<label><input type="radio" name="reason" value="' . esc_attr( $value ) . '" /> ' . esc_html( $label ) . '</label>';
		}
		$html .= '</fieldset>';
		$html .= '<label for="tackquote-deactivate-details">' . esc_html__( 'Anything else? (optional)', 'tackquote' ) . '</label>';
		$html .= '<textarea id="tackquote-deactivate-details" name="details" rows="3" maxlength="' . esc_attr( (string) self::DETAILS_MAX ) . '"></textarea>';
		$html .= '<label class="tackquote-deactivate-help"><input type="checkbox" name="wants_help" value="1" /> ' . esc_html__( 'I would like help from TackQuote with this store', 'tackquote' ) . '</label>';
		$html .= '<p class="description">' . esc_html__( 'Your answer is sent to TackQuote with this store\'s deactivation notice. Please do not include personal details such as names, email addresses or phone numbers.', 'tackquote' ) . '</p>';
		$html .= '<div class="tackquote-deactivate-actions">';
		$html .= '<button type="button" class="button button-primary" data-tackquote-skip autofocus>' . esc_html__( 'Skip and deactivate', 'tackquote' ) . '</button>';
		$html .= '<button type="submit" class="button">' . esc_html__( 'Send and deactivate', 'tackquote' ) . '</button>';
		$html .= '<button type="button" class="button-link" data-tackquote-cancel>' . esc_html__( 'Cancel', 'tackquote' ) . '</button>';
		$html .= '</div></form></dialog>';
		return $html;
	}

	/**
	 * The admin-post.php handler: keep the answer for the deactivation, then go to
	 * WordPress's own deactivation of this plugin.
	 *
	 * @since 1.16.0
	 */
	public static function handle_feedback() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the nonce is verified in process_feedback_request() before any field is used, and clean_feedback() sanitizes each field it keeps.
		$url = self::process_feedback_request( (array) wp_unslash( $_POST ) );
		if ( '' === $url ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to deactivate plugins, or this page expired. Go back to Plugins and try again.', 'tackquote' ), '', array( 'response' => 403 ) );
		}
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Check the capability and the nonce, keep the answer, and answer where to go next:
	 * WordPress's deactivation link for this plugin, or '' when refused.
	 *
	 * @since 1.16.0
	 *
	 * @param array $post The unslashed POST fields.
	 * @return string
	 */
	public static function process_feedback_request( array $post ) {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return '';
		}
		$nonce = isset( $post['_wpnonce'] ) && is_string( $post['_wpnonce'] ) ? sanitize_text_field( $post['_wpnonce'] ) : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::FEEDBACK_ACTION ) ) {
			return '';
		}
		self::store_feedback( $post );
		return self::deactivate_url();
	}

	/**
	 * WordPress's own deactivation link for this plugin (`plugins.php?action=deactivate`,
	 * nonce `deactivate-plugin_<file>`, the pattern WP_Plugins_List_Table::single_row()
	 * uses for its links). Built here rather than taken from the form, so the redirect
	 * can go nowhere else.
	 *
	 * @since 1.16.0
	 *
	 * @return string
	 */
	public static function deactivate_url() {
		$file = plugin_basename( TACK_QUOTES_FILE );
		// Written out as core writes it (`'&plugin=' . urlencode( $plugin_file )`): core's
		// add_query_arg() does not encode values, so the encoding is done here, once.
		return admin_url( 'plugins.php?action=deactivate&plugin=' . rawurlencode( $file ) . '&_wpnonce=' . rawurlencode( wp_create_nonce( 'deactivate-plugin_' . $file ) ) );
	}

	/**
	 * The answer, cleaned to exactly what TackQuote accepts. Empty when nothing was
	 * chosen or typed.
	 *
	 * - `reason`: one of reasons(), else left out.
	 * - `details`: plain text, at most DETAILS_MAX characters, else left out.
	 * - `wantsHelp`: the JSON boolean `true`, only when the box was ticked; never a
	 *   string and never `false` (TackQuote refuses a string with 400).
	 *
	 * @since 1.16.0
	 *
	 * @param array $post The unslashed POST fields, or a stored answer to re-check.
	 * @return array
	 */
	public static function clean_feedback( array $post ) {
		$out    = array();
		$reason = isset( $post['reason'] ) && is_string( $post['reason'] ) ? sanitize_key( $post['reason'] ) : '';
		if ( '' !== $reason && array_key_exists( $reason, self::reasons() ) ) {
			$out['reason'] = $reason;
		}
		$details = isset( $post['details'] ) && is_string( $post['details'] ) ? trim( sanitize_textarea_field( $post['details'] ) ) : '';
		if ( '' !== $details ) {
			$out['details'] = function_exists( 'mb_substr' ) ? mb_substr( $details, 0, self::DETAILS_MAX, 'UTF-8' ) : substr( $details, 0, self::DETAILS_MAX );
		}
		$help = $post['wants_help'] ?? ( $post['wantsHelp'] ?? null );
		if ( true === $help || '1' === $help ) {
			$out['wantsHelp'] = true;
		}
		return $out;
	}

	/**
	 * The transient that holds the current user's answer, or '' for no user.
	 *
	 * @return string
	 */
	private static function feedback_key() {
		$user = (int) get_current_user_id();
		return $user > 0 ? self::FEEDBACK_TRANSIENT . $user : '';
	}

	/**
	 * Keep the current user's answer for the deactivation that follows.
	 *
	 * @since 1.16.0
	 *
	 * @param array $post The unslashed POST fields.
	 * @return bool Whether an answer was kept.
	 */
	public static function store_feedback( array $post ) {
		$key = self::feedback_key();
		if ( '' === $key ) {
			return false;
		}
		$feedback = self::clean_feedback( $post );
		if ( empty( $feedback ) ) {
			delete_transient( $key );
			return false;
		}
		set_transient( $key, $feedback, self::FEEDBACK_TTL );
		return true;
	}

	/**
	 * Read the current user's answer once (it is deleted on read).
	 *
	 * @since 1.16.0
	 *
	 * @return array
	 */
	public static function take_feedback() {
		$key = self::feedback_key();
		if ( '' === $key ) {
			return array();
		}
		$stored = get_transient( $key );
		delete_transient( $key );
		return is_array( $stored ) ? self::clean_feedback( $stored ) : array();
	}

	/**
	 * Tell TackQuote the plugin was deactivated, with the merchant's answer when they
	 * gave one and the server takes it.
	 *
	 * @param Tack_Api_Client|null $client Optional HTTP client (tests).
	 * @return bool Whether TackQuote accepted it.
	 */
	public static function notify_deactivated( $client = null ) {
		// Read (and so delete) the answer first: it belongs to this deactivation only.
		$feedback = self::take_feedback();
		if ( '' === (string) get_option( 'tack_quotes_api_key', '' ) ) {
			return false;
		}
		$client = $client ? $client : new Tack_Api_Client();
		// A cold capability cache is not worth holding the Deactivate click for the
		// usual ping timeout: ask once, briefly, and give up on silence.
		$caps = $client->cached_capabilities();
		if ( null === $caps ) {
			$caps = $client->server_capabilities( true, false, self::TIMEOUT );
		}
		if ( ! in_array( 'lifecycle', $caps, true ) ) {
			return false;
		}
		$body = array( 'event' => 'deactivated' );
		// Only a server that lists `lifecycle_feedback` knows these fields; an older one
		// refuses the whole request over one unknown field (forbidNonWhitelisted).
		if ( in_array( self::FEEDBACK_CAPABILITY, $caps, true ) ) {
			if ( 1 === preg_match( '/^[0-9A-Za-z][0-9A-Za-z.+_-]{0,39}$/', (string) TACK_QUOTES_VERSION ) ) {
				$body['pluginVersion'] = (string) TACK_QUOTES_VERSION;
			}
			$body = array_merge( $body, $feedback );
		}
		$result = $client->request( 'POST', '/integrations/woocommerce/plugin-lifecycle', $body, self::TIMEOUT );
		// A 400 means TackQuote refused the extra fields. The deactivation itself still
		// matters (it pauses the connection), so the bare event is sent once more. The
		// answer is lost, and nothing says it was sent.
		if ( is_wp_error( $result ) && count( $body ) > 1 ) {
			$data = $result->get_error_data();
			if ( is_array( $data ) && 400 === (int) ( $data['status'] ?? 0 ) ) {
				$result = $client->request( 'POST', '/integrations/woocommerce/plugin-lifecycle', array( 'event' => 'deactivated' ), self::TIMEOUT );
			}
		}
		return ! is_wp_error( $result );
	}
}
