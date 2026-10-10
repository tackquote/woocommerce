<?php
/**
 * Storefront application forms: the wholesale (trade account) application and
 * the net-terms (credit) application, as a shortcode and as My Account tabs.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHAT THIS CLOSES
 * ─────────────────────────────────────────────────────────────────────────────
 * TackQuote's seller settings hold a wholesale application form (fields, approval
 * mode) and a review queue for net-terms applications, and the Shopify and
 * BigCommerce storefronts render both. A WooCommerce store had neither: the server
 * routes existed (`/integrations/woocommerce/wholesale-form`, `.../credit-application`)
 * and nothing in the plugin called them. This class renders the seller's form from
 * the definition the server returns and posts the answers back, server to server.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * HOW A SUBMISSION TRAVELS
 * ─────────────────────────────────────────────────────────────────────────────
 * The browser posts to admin-post.php (`admin_post_{action}`), the handler verifies
 * the nonce, sanitises every answer by its field type, calls TackQuote with the
 * store's secret key, and redirects back to the page the form was on with a
 * one-time token. The outcome (success, pending, or a friendly error) sits in a
 * five-minute transient under that token, never in the URL: a server message in a
 * query string is a reflected-content hazard and a bookmarkable "success" page.
 *
 * Nothing the server says is echoed raw. A TackQuote validation sentence
 * ("Company name is required") is shown escaped; every other failure is mapped to
 * plain text that names what the shopper can do about it.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * IDENTITY
 * ─────────────────────────────────────────────────────────────────────────────
 * A wholesale application may come from a guest. When a customer is signed in,
 * their WooCommerce customer id travels as `wooCustomerId`, which the server stores
 * as the application's source identity and links to the buyer on approval. A
 * net-terms application is a credit decision about an ACCOUNT, so it is signed-in
 * only, and the contact email is the account's own address, never a typed one.
 *
 * File-type fields (1.10.0) take a file from a SIGNED-IN customer when the TackQuote
 * server advertises `attachments`: the handler validates each file, streams it to
 * `/storefront/v1/wholesale-upload`, deletes the PHP temp file, and submits through
 * `/storefront/v1/wholesale-signup/<slug>`, which claims the uploads. Without files the
 * legacy route is used exactly as before. A guest sees "sign in to attach files"; a
 * server without the capability gets the old notice, and a REQUIRED file field then
 * blocks the form before it renders a submit button.
 *
 * @package TackQuotes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Wholesale and net-terms application forms on the storefront.
 */
class Tack_Storefront_Forms {

	/** Option: the slug of the wholesale form to render (TackQuote -> Settings -> Wholesale forms). */
	const OPTION_FORM_SLUG = 'tack_quotes_wholesale_form_slug';

	/** Option: show the "Wholesale account" tab on My Account. */
	const OPTION_WHOLESALE_TAB = 'tack_quotes_enable_wholesale_account_tab';

	/** Option: show the "Net terms" tab on My Account. */
	const OPTION_NET_TERMS_TAB = 'tack_quotes_enable_net_terms_tab';

	/** Option: the plugin version whose rewrite rules were last flushed. */
	const OPTION_REWRITE_VERSION = 'tack_quotes_rewrite_version';

	/** My Account endpoint slug for the wholesale application. */
	const ENDPOINT_WHOLESALE = 'wholesale-account';

	/** My Account endpoint slug for the net-terms application. */
	const ENDPOINT_NET_TERMS = 'net-terms';

	/** The admin-post action (and nonce action) for the wholesale form. */
	const ACTION_WHOLESALE = 'tack_wholesale_application';

	/** The admin-post action (and nonce action) for the net-terms form. */
	const ACTION_CREDIT = 'tack_credit_application';

	/** Prefix of the transient that carries a submission's outcome back to the page. */
	const RESULT_PREFIX = 'tack_sf_';

	/**
	 * How long an outcome waits to be shown, in seconds.
	 *
	 * The browser follows the redirect at once, so two minutes is ample. Kept short
	 * because an error outcome holds what the shopper typed, under a token carried in
	 * the URL.
	 */
	const RESULT_TTL = 120;

	/** Applications (wholesale + net terms together) one visitor may send per RATE_LIMIT_WINDOW. */
	const RATE_LIMIT_MAX = 5;

	/** The application rate-limit window, in seconds. */
	const RATE_LIMIT_WINDOW = 600;

	/** The form slug used when the merchant has not chosen one. */
	const DEFAULT_SLUG = 'default';

	/** Slugs already logged today as matching no wholesale form. */
	const MISSING_FORM_LOGGED = 'tack_quotes_wholesale_form_missing';

	/** Payment-terms lengths offered on the net-terms form (days). All within the DTO's 0..365. */
	const TERMS_DAYS = array( 15, 30, 45, 60, 90 );

	/** Trade references accepted on the net-terms form. The DTO caps at 10; three is what a reviewer reads. */
	const MAX_REFERENCES = 3;

	/** Longest single-line answer, mirroring the server's MAX_FIELD_VALUE_LENGTH. */
	const MAX_TEXT = 320;

	/** Longest textarea answer, mirroring the server's MAX_TEXTAREA_VALUE_LENGTH. */
	const MAX_TEXTAREA = 2000;

	/** Highest requestedLimit the DTO accepts. */
	const MAX_REQUESTED_LIMIT = 100000000;

	/** Address parts, in the order the server's `WholesaleAddressValue` lists them. */
	const ADDRESS_PARTS = array( 'line1', 'line2', 'city', 'region', 'postalCode', 'country' );

	/** Address parts the server requires once an address is answered. */
	const ADDRESS_REQUIRED = array( 'line1', 'city', 'postalCode', 'country' );

	/**
	 * API client.
	 *
	 * @var Tack_Api_Client
	 */
	private $client;

	/**
	 * File validation and streaming (1.10.0).
	 *
	 * @var Tack_Attachments
	 */
	private $attachments;

	/**
	 * Constructor.
	 *
	 * @param Tack_Api_Client|null  $client      Injected in tests; built here otherwise.
	 * @param Tack_Attachments|null $attachments Injected in tests; built here otherwise.
	 */
	public function __construct( $client = null, $attachments = null ) {
		$this->client      = $client instanceof Tack_Api_Client ? $client : new Tack_Api_Client();
		$this->attachments = $attachments instanceof Tack_Attachments ? $attachments : new Tack_Attachments( $this->client );
	}

	/**
	 * The wholesale form slug the merchant configured, or the default.
	 *
	 * @return string
	 */
	public static function default_slug() {
		$slug = sanitize_key( (string) get_option( self::OPTION_FORM_SLUG, self::DEFAULT_SLUG ) );
		return '' === $slug ? self::DEFAULT_SLUG : $slug;
	}

	/**
	 * The signed-in customer's email, or '' when there is nobody TackQuote may
	 * resolve from it.
	 *
	 * '' for a guest, and for an account whose address was self-changed on My
	 * Account and not re-confirmed (`Tack_B2B_Notices::META_EMAIL_UNVERIFIED`,
	 * set by the guard `Tack_B2B_Notices::flag_email_change()`), unless the store
	 * vouches through the existing `tackquote_trust_unverified_email` filter.
	 * WooCommerce changes an email with no verification, so without this a new
	 * account could take an approved buyer's address and apply for net terms, or
	 * receive their tax exemption, in that buyer's name.
	 *
	 * @return string
	 */
	public static function trusted_account_email() {
		if ( ! is_user_logged_in() ) {
			return '';
		}
		$user = wp_get_current_user();
		if ( ! $user || empty( $user->ID ) || ! isset( $user->user_email ) ) {
			return '';
		}
		if ( '1' === (string) get_user_meta( $user->ID, Tack_B2B_Notices::META_EMAIL_UNVERIFIED, true ) ) {
			/**
			 * Filters whether a self-changed, unconfirmed email may still resolve a
			 * TackQuote buyer. Documented at Tack_B2B_Notices::buyer_email().
			 *
			 * @since 1.7.1
			 *
			 * @param bool $trust   Whether to trust it anyway.
			 * @param int  $user_id The customer.
			 */
			if ( ! apply_filters( 'tackquote_trust_unverified_email', false, $user->ID ) ) {
				return '';
			}
		}
		return (string) $user->user_email;
	}

	/**
	 * Is the "Wholesale account" tab switched on?
	 *
	 * @return bool
	 */
	public static function wholesale_tab_enabled() {
		return 'yes' === get_option( self::OPTION_WHOLESALE_TAB, 'no' );
	}

	/**
	 * Is the "Net terms" tab switched on?
	 *
	 * @return bool
	 */
	public static function net_terms_tab_enabled() {
		return 'yes' === get_option( self::OPTION_NET_TERMS_TAB, 'no' );
	}

	/**
	 * Register hooks.
	 *
	 * The shortcodes, the endpoints and the submit handlers are registered
	 * unconditionally: a shortcode placed on a page must render whatever the tab
	 * switches say, and the rewrite rules must exist before a merchant switches a
	 * tab on, or the tab would 404 until the next flush. The switches decide only
	 * whether a menu item appears.
	 */
	public function init() {
		add_shortcode( 'tackquote_wholesale_application', array( $this, 'shortcode_wholesale' ) );
		add_shortcode( 'tackquote_net_terms_application', array( $this, 'shortcode_net_terms' ) );

		// WooCommerce registers every entry of this list as a rewrite endpoint on
		// `init` (WC_Query::add_endpoints) and as a query var, and resolves
		// wc_get_account_endpoint_url() from it. Read from WooCommerce 11.2's
		// includes/class-wc-query.php, not assumed.
		add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'add_query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'account_menu_items' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT_WHOLESALE . '_endpoint', array( $this, 'endpoint_wholesale' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT_NET_TERMS . '_endpoint', array( $this, 'endpoint_net_terms' ) );
		add_filter( 'woocommerce_endpoint_' . self::ENDPOINT_WHOLESALE . '_title', array( $this, 'wholesale_title' ) );
		add_filter( 'woocommerce_endpoint_' . self::ENDPOINT_NET_TERMS . '_title', array( $this, 'net_terms_title' ) );

		add_action( 'admin_post_' . self::ACTION_WHOLESALE, array( $this, 'handle_wholesale_submit' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION_WHOLESALE, array( $this, 'handle_wholesale_submit' ) );
		add_action( 'admin_post_' . self::ACTION_CREDIT, array( $this, 'handle_credit_submit' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION_CREDIT, array( $this, 'handle_credit_submit' ) );

		// After WooCommerce's own add_endpoints (init, priority 10).
		add_action( 'init', array( __CLASS__, 'maybe_flush_rewrite_rules' ), 20 );
	}

	/**
	 * Add the two endpoints to WooCommerce's query-var list.
	 *
	 * @param array $vars WooCommerce query vars (key => endpoint slug).
	 * @return array
	 */
	public static function add_query_vars( $vars ) {
		$vars[ self::ENDPOINT_WHOLESALE ] = self::ENDPOINT_WHOLESALE;
		$vars[ self::ENDPOINT_NET_TERMS ] = self::ENDPOINT_NET_TERMS;
		return $vars;
	}

	/**
	 * Register the endpoints directly, for activation.
	 *
	 * On activation WooCommerce's `init` has already run without this plugin's
	 * filter attached, so the endpoints are added here by hand before the flush —
	 * the sequence WooCommerce's own "Customising account page tabs" guide gives.
	 */
	public static function register_endpoints() {
		add_rewrite_endpoint( self::ENDPOINT_WHOLESALE, EP_ROOT | EP_PAGES );
		add_rewrite_endpoint( self::ENDPOINT_NET_TERMS, EP_ROOT | EP_PAGES );
	}

	/**
	 * Flush rewrite rules once per plugin version.
	 *
	 * An update does not re-run activation, so a store upgrading to the first
	 * version with these endpoints would 404 on them until someone re-saved
	 * permalinks. Guarded by an option so the flush — an expensive write — happens
	 * once, not on every request.
	 */
	public static function maybe_flush_rewrite_rules() {
		if ( (string) get_option( self::OPTION_REWRITE_VERSION, '' ) === TACK_QUOTES_VERSION ) {
			return;
		}
		flush_rewrite_rules( false );
		update_option( self::OPTION_REWRITE_VERSION, TACK_QUOTES_VERSION, false );
	}

	/**
	 * Insert the enabled tabs before "Log out".
	 *
	 * @param array $items Menu items (endpoint => label).
	 * @return array
	 */
	public function account_menu_items( $items ) {
		if ( ! self::wholesale_tab_enabled() && ! self::net_terms_tab_enabled() ) {
			return $items;
		}
		$logout = null;
		if ( isset( $items['customer-logout'] ) ) {
			$logout = $items['customer-logout'];
			unset( $items['customer-logout'] );
		}
		if ( self::wholesale_tab_enabled() ) {
			$items[ self::ENDPOINT_WHOLESALE ] = __( 'Wholesale account', 'tackquote' );
		}
		if ( self::net_terms_tab_enabled() ) {
			$items[ self::ENDPOINT_NET_TERMS ] = __( 'Net terms', 'tackquote' );
		}
		if ( null !== $logout ) {
			$items['customer-logout'] = $logout;
		}
		return $items;
	}

	/**
	 * Page title for the wholesale endpoint.
	 *
	 * @return string
	 */
	public function wholesale_title() {
		return __( 'Wholesale account', 'tackquote' );
	}

	/**
	 * Page title for the net-terms endpoint.
	 *
	 * @return string
	 */
	public function net_terms_title() {
		return __( 'Net terms', 'tackquote' );
	}

	/**
	 * My Account tab content: the wholesale application.
	 */
	public function endpoint_wholesale() {
		if ( ! self::wholesale_tab_enabled() ) {
			return;
		}
		echo $this->render_wholesale_form( self::default_slug(), $this->account_url( self::ENDPOINT_WHOLESALE ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_wholesale_form() returns wp_kses()-filtered markup.
	}

	/**
	 * My Account tab content: the net-terms application.
	 */
	public function endpoint_net_terms() {
		if ( ! self::net_terms_tab_enabled() ) {
			return;
		}
		echo $this->render_net_terms_form( $this->account_url( self::ENDPOINT_NET_TERMS ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_net_terms_form() returns wp_kses()-filtered markup.
	}

	/**
	 * `[tackquote_wholesale_application slug="…"]`.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function shortcode_wholesale( $atts ) {
		$atts = shortcode_atts( array( 'slug' => '' ), $atts, 'tackquote_wholesale_application' );
		$slug = sanitize_key( (string) $atts['slug'] );
		return $this->render_wholesale_form( '' === $slug ? self::default_slug() : $slug, $this->current_url() );
	}

	/**
	 * `[tackquote_net_terms_application]`.
	 *
	 * @return string
	 */
	public function shortcode_net_terms() {
		return $this->render_net_terms_form( $this->current_url() );
	}

	// ── Wholesale application ───────────────────────────────────────────────

	/**
	 * The wholesale application form, or the outcome of the one just submitted.
	 *
	 * @param string $slug       Form slug.
	 * @param string $return_url Where the handler sends the shopper back to.
	 * @return string wp_kses()-filtered markup.
	 */
	public function render_wholesale_form( $slug, $return_url ) {
		$outcome = $this->consume_outcome( 'wholesale' );
		$html    = '';
		if ( null !== $outcome ) {
			$html .= $this->notice( $outcome['kind'], $outcome['message'] );
			if ( 'error' !== $outcome['kind'] ) {
				// Approved, pending or received: the form has done its job.
				return $this->kses( '<div class="tackquote-storefront-form tackquote-wholesale-application">' . $html . '</div>' );
			}
		}

		$form = $this->client->get_wholesale_form( $slug );
		if ( is_wp_error( $form ) && self::is_missing_form( $form ) ) {
			// The slug matches no form: trying again later will not
			// help the shopper, and only the store's administrator can fix it.
			$this->log_missing_form( $slug, $form );
			$html .= $this->notice( 'info', __( 'This form isn\'t available right now.', 'tackquote' ) );
			$html .= $this->missing_form_hint( $slug );
			return $this->kses( '<div class="tackquote-storefront-form tackquote-wholesale-application">' . $html . '</div>' );
		}
		if ( is_wp_error( $form ) ) {
			$this->log( 'wholesale-form read failed: ' . $this->error_summary( $form ) );
			$html .= $this->notice( 'info', __( 'The wholesale application form is not available right now. Please try again later.', 'tackquote' ) );
			return $this->kses( '<div class="tackquote-storefront-form tackquote-wholesale-application">' . $html . '</div>' );
		}

		$fields    = isset( $form['fields'] ) && is_array( $form['fields'] ) ? $form['fields'] : array();
		$refill    = null !== $outcome && isset( $outcome['values'] ) && is_array( $outcome['values'] ) ? $outcome['values'] : array();
		$prefill   = $this->wholesale_prefill( $fields );
		$blocking  = $this->required_file_field( $fields );
		$file_mode = $this->file_mode( $fields );

		$fields_html = '';
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) || empty( $field['key'] ) || empty( $field['type'] ) ) {
				continue;
			}
			$key          = (string) $field['key'];
			$value        = array_key_exists( $key, $refill ) ? $refill[ $key ] : ( isset( $prefill[ $key ] ) ? $prefill[ $key ] : '' );
			$fields_html .= $this->render_field( $field, 'tack_sf', $value, $file_mode );
		}

		$blocked = '';
		$hidden  = '';
		if ( null !== $blocking && 'signin' === $file_mode ) {
			$blocked = $this->notice( 'info', __( 'This application asks for a document, so you need to be signed in to apply.', 'tackquote' ) )
				. '<p class="tackquote-signin"><a class="' . esc_attr( self::button_class( 'tackquote-signin-link' ) ) . '" href="' . esc_url( $this->account_url( 'dashboard' ) ) . '">' . esc_html__( 'Sign in', 'tackquote' ) . '</a></p>';
		} elseif ( null !== $blocking && 'upload' !== $file_mode ) {
			$blocked = $this->notice(
				'info',
				sprintf(
					/* translators: %s: the label of the attachment field the form requires. */
					__( 'This form requires an attachment ("%s"), which cannot be sent from this store yet. Please contact the store to apply.', 'tackquote' ),
					(string) $blocking
				)
			);
		} else {
			$hidden = '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_WHOLESALE ) . '" />'
				. '<input type="hidden" name="tack_slug" value="' . esc_attr( $slug ) . '" />'
				. '<input type="hidden" name="tack_redirect" value="' . esc_url( $return_url ) . '" />'
				. wp_nonce_field( self::ACTION_WHOLESALE, '_tack_nonce', true, false );
		}

		$html .= $this->form_template(
			'myaccount/form-wholesale-application.php',
			array(
				'notices'      => '',
				'title'        => ! empty( $form['name'] ) ? (string) $form['name'] : '',
				'description'  => ! empty( $form['description'] ) ? (string) $form['description'] : '',
				'action_url'   => admin_url( 'admin-post.php' ),
				'multipart'    => 'upload' === $file_mode,
				'fields'       => $fields_html,
				'blocked'      => $blocked,
				'hidden'       => $hidden,
				'submit_label' => __( 'Submit application', 'tackquote' ),
				'submit_class' => self::button_class( 'tackquote-submit' ),
			),
			'wholesale'
		);

		$this->enqueue_assets();
		return $this->kses( '<div class="tackquote-storefront-form tackquote-wholesale-application">' . $html . '</div>' );
	}

	/**
	 * The admin-post handler for the wholesale form. Redirects and exits.
	 */
	public function handle_wholesale_submit() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified inside process_wholesale_submission().
		$post = wp_unslash( $_POST );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified inside process_wholesale_submission(); each file is validated there by path, size and content.
		$files   = isset( $_FILES['tack_sf_files'] ) ? self::files_by_field( $_FILES['tack_sf_files'] ) : array();
		$outcome = $this->process_wholesale_submission( is_array( $post ) ? $post : array(), $files );
		$this->respond( $outcome );
	}

	/**
	 * `$_FILES['tack_sf_files']` (posted as `tack_sf_files[<field key>]`) as one file per field key.
	 *
	 * @since 1.10.0
	 *
	 * @param mixed $entry The `$_FILES` entry.
	 * @return array<string, array{name:string,tmp_name:string,size:int,error:int}>
	 */
	public static function files_by_field( $entry ) {
		if ( ! is_array( $entry ) || ! isset( $entry['tmp_name'] ) || ! is_array( $entry['tmp_name'] ) ) {
			return array();
		}
		$files = array();
		foreach ( $entry['tmp_name'] as $key => $tmp ) {
			$error = isset( $entry['error'][ $key ] ) && is_scalar( $entry['error'][ $key ] ) ? (int) $entry['error'][ $key ] : UPLOAD_ERR_NO_FILE;
			if ( ! is_string( $key ) || UPLOAD_ERR_NO_FILE === $error || ! is_string( $tmp ) ) {
				continue;
			}
			$files[ $key ] = array(
				'name'     => isset( $entry['name'][ $key ] ) && is_string( $entry['name'][ $key ] ) ? $entry['name'][ $key ] : '',
				'tmp_name' => $tmp,
				'size'     => isset( $entry['size'][ $key ] ) ? (int) $entry['size'][ $key ] : 0,
				'error'    => $error,
			);
		}
		return $files;
	}

	/**
	 * How this form's file fields behave for the current visitor.
	 *
	 * `none` (no file fields), `upload` (server supports attachments and a customer
	 * with a trusted email is signed in), `signin` (supported, but nobody TackQuote
	 * may identify is signed in) or `unsupported` (an older server). The capability
	 * is asked only when the form has a file field.
	 *
	 * @since 1.10.0
	 *
	 * @param array $fields Field definitions.
	 * @return string
	 */
	public function file_mode( array $fields ) {
		$has_file = false;
		foreach ( $fields as $field ) {
			if ( is_array( $field ) && isset( $field['type'] ) && 'file' === $field['type'] ) {
				$has_file = true;
				break;
			}
		}
		if ( ! $has_file ) {
			return 'none';
		}
		if ( ! $this->client->supports_attachments() ) {
			return 'unsupported';
		}
		return ( '' !== self::trusted_account_email() && get_current_user_id() > 0 ) ? 'upload' : 'signin';
	}

	/**
	 * Validate, sanitise and send a wholesale application. Pure apart from the API call.
	 *
	 * @param array $post  The unslashed POST body.
	 * @param array $files 1.10.0: files_by_field() output, field key => posted file.
	 * @return array{kind:string,message:string,redirect:string,form:string,values?:array}
	 */
	public function process_wholesale_submission( array $post, array $files = array() ) {
		$redirect = $this->safe_redirect_target( isset( $post['tack_redirect'] ) ? (string) $post['tack_redirect'] : '' );
		$outcome  = array(
			'form'     => 'wholesale',
			'redirect' => $redirect,
		);

		if ( ! isset( $post['_tack_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( (string) $post['_tack_nonce'] ), self::ACTION_WHOLESALE ) ) {
			$this->attachments->discard( $files );
			return $outcome + $this->failure( __( 'Your session has expired. Please reload the page and try again.', 'tackquote' ) );
		}
		if ( self::rate_limited() ) {
			$this->attachments->discard( $files );
			return $outcome + $this->failure( self::rate_limit_message() );
		}

		$slug = isset( $post['tack_slug'] ) ? sanitize_key( (string) $post['tack_slug'] ) : '';
		if ( '' === $slug ) {
			$slug = self::default_slug();
		}

		$form = $this->client->get_wholesale_form( $slug );
		if ( is_wp_error( $form ) ) {
			$this->attachments->discard( $files );
			$this->log( 'wholesale-form read (on submit) failed: ' . $this->error_summary( $form ) );
			return $outcome + $this->failure( $this->friendly_error( $form ) );
		}
		$fields = isset( $form['fields'] ) && is_array( $form['fields'] ) ? $form['fields'] : array();

		$raw = isset( $post['tack_sf'] ) && is_array( $post['tack_sf'] )
			? map_deep( $post['tack_sf'], 'sanitize_textarea_field' )
			: array();

		$collected = $this->collect_answers( $fields, $raw );
		if ( null !== $collected['error'] ) {
			$this->attachments->discard( $files );
			return $outcome + $this->failure( $collected['error'], $collected['refill'] );
		}

		// 1.10.0: files. Every file is checked before anything is sent.
		$picked = $this->pick_files( $fields, $collected['values'], $files );
		if ( is_wp_error( $picked ) ) {
			$this->attachments->discard( $files );
			return $outcome + $this->failure( $picked->get_error_message(), $collected['refill'] );
		}

		self::count_application();
		if ( ! empty( $picked ) ) {
			$result = $this->submit_with_files( $slug, $collected['values'], $picked );
		} else {
			$customer_id = is_user_logged_in() ? (string) get_current_user_id() : '';
			$result      = $this->client->submit_wholesale_form( $slug, $collected['values'], $customer_id );
		}
		if ( is_wp_error( $result ) ) {
			$this->log( 'wholesale-form submit failed: ' . $this->error_summary( $result ) );
			return $outcome + $this->failure( $this->friendly_error( $result ), $collected['refill'] );
		}

		$status  = isset( $result['status'] ) ? (string) $result['status'] : '';
		$message = isset( $result['message'] ) && is_string( $result['message'] ) ? $this->plain( $result['message'], 500 ) : '';
		if ( 'approved' === $status ) {
			$outcome['kind']    = 'success';
			$outcome['message'] = '' !== $message ? $message : __( 'Your wholesale account has been approved.', 'tackquote' );
		} elseif ( 'pending' === $status ) {
			$outcome['kind']    = 'pending';
			$outcome['message'] = '' !== $message ? $message : __( 'Your application has been received and is awaiting review. We will email you when it has been decided.', 'tackquote' );
		} else {
			$outcome['kind']    = 'pending';
			$outcome['message'] = '' !== $message ? $message : __( 'Your application has been received.', 'tackquote' );
		}
		return $outcome;
	}

	/**
	 * The posted files for the form's SHOWN file fields, validated, or why not.
	 *
	 * One file per field (an application claims one per field). A required, shown
	 * file field without a file refuses the submission; a file for a field the form
	 * does not have, or one that is hidden, is ignored (and its temp file deleted).
	 * Files need a signed-in customer TackQuote may identify and a server that
	 * advertises attachments.
	 *
	 * @since 1.10.0
	 *
	 * @param array $fields Field definitions.
	 * @param array $values Answers collected so far (for showIf).
	 * @param array $files  files_by_field() output.
	 * @return array<string, array>|WP_Error Field key => validated file.
	 */
	public function pick_files( array $fields, array $values, array $files ) {
		$by_key = array();
		foreach ( $fields as $field ) {
			if ( is_array( $field ) && ! empty( $field['key'] ) ) {
				$by_key[ (string) $field['key'] ] = $field;
			}
		}

		$wanted = array();
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) || empty( $field['key'] ) || ! isset( $field['type'] ) || 'file' !== $field['type'] ) {
				continue;
			}
			if ( ! $this->is_shown( $field, $by_key, $values ) ) {
				continue;
			}
			$key   = (string) $field['key'];
			$label = isset( $field['label'] ) ? (string) $field['label'] : $key;
			if ( ! isset( $files[ $key ] ) ) {
				if ( ! empty( $field['required'] ) ) {
					/* translators: %s: the field's label. */
					return new WP_Error( 'tack_file_required', sprintf( __( '%s is required.', 'tackquote' ), $label ) );
				}
				continue;
			}
			$wanted[ $key ] = $field;
		}

		// Files posted for fields that are not (shown) file fields: dropped now.
		$this->attachments->discard( array_diff_key( $files, $wanted ) );
		if ( empty( $wanted ) ) {
			return array();
		}

		if ( 'upload' !== $this->file_mode( $fields ) ) {
			return new WP_Error( 'tack_file_signin', __( 'Sign in to your account to attach files.', 'tackquote' ) );
		}

		$picked = array();
		foreach ( $wanted as $key => $field ) {
			$accept = isset( $field['accept'] ) && is_array( $field['accept'] )
				? array_values( array_intersect( array_map( 'strval', $field['accept'] ), Tack_Attachments::MIME_TYPES ) )
				: array_values( array_unique( Tack_Attachments::MIME_TYPES ) );
			$max_mb = isset( $field['maxSizeMb'] ) && is_numeric( $field['maxSizeMb'] ) ? (int) $field['maxSizeMb'] : Tack_Attachments::MAX_MB;
			$file   = $this->attachments->validate_file( $files[ $key ], $accept, $max_mb );
			if ( is_wp_error( $file ) ) {
				return $file;
			}
			$picked[ $key ] = $file;
		}
		return $picked;
	}

	/**
	 * Upload each file to `/storefront/v1/wholesale-upload`, then submit through
	 * `/storefront/v1/wholesale-signup/<slug>` with `{uploadId}` as each file
	 * field's value. The legacy route cannot claim uploads, so there is no fallback.
	 *
	 * @since 1.10.0
	 *
	 * @param string $slug   Form slug.
	 * @param array  $values Answers.
	 * @param array  $picked pick_files() output.
	 * @return array|WP_Error
	 */
	private function submit_with_files( $slug, array $values, array $picked ) {
		$email  = self::trusted_account_email();
		$client = $this->client;
		$keys   = array_keys( $picked );
		$index  = 0;
		$sent   = $this->attachments->stream(
			array_values( $picked ),
			function ( $bytes, $file ) use ( $client, $slug, $email, $keys, &$index ) {
				$key = (string) $keys[ $index ];
				++$index;
				return $client->upload_wholesale_file( $bytes, $file['name'], $slug, $key, $email );
			}
		);
		if ( is_wp_error( $sent ) ) {
			$this->log( 'wholesale-form upload failed: ' . $this->error_summary( $sent ) );
			// Upload wording ("try again shortly", "sign in to attach files"), not the application's.
			return new WP_Error( 'tack_file_upload', Tack_Attachments::friendly_error( $sent ), array( 'status' => 0 ) );
		}
		foreach ( $sent as $i => $answer ) {
			$values[ $keys[ $i ] ] = array( 'uploadId' => $answer['uploadId'] );
		}
		$this->log( 'wholesale-form uploads accepted: ' . implode( ',', wp_list_pluck( $sent, 'uploadId' ) ) );
		return $this->client->submit_wholesale_signup( $slug, $values, $email );
	}

	/**
	 * Whether an answer must stay out of the refill that an error outcome stores.
	 *
	 * An error outcome is kept in a `tack_sf_*` transient (wp_options) under a token
	 * carried in the URL, so it holds only what is cheap to lose: phone numbers and tax,
	 * VAT or company registration numbers are left out and the shopper types them again
	 * Decided by the field's type (`tel`, `tax_id`), its role
	 * (`buyer_phone`) and, because a merchant may collect a VAT number in a plain text
	 * field, by the words in its key and label.
	 *
	 * @since 1.10.0
	 *
	 * @param array $field Field definition.
	 * @return bool
	 */
	public static function is_private_refill_field( array $field ) {
		$type = isset( $field['type'] ) ? (string) $field['type'] : '';
		$role = isset( $field['role'] ) ? (string) $field['role'] : '';
		if ( in_array( $type, array( 'tel', 'tax_id' ), true ) || 'buyer_phone' === $role ) {
			return true;
		}
		$text = ( isset( $field['key'] ) ? (string) $field['key'] : '' ) . ' ' . ( isset( $field['label'] ) ? (string) $field['label'] : '' );
		// Split camelCase and snake_case into lower-case words: "vatNumber" -> "vat number".
		$words = preg_split( '/[^a-z0-9]+/', strtolower( (string) preg_replace( '/([a-z0-9])([A-Z])/', '$1 $2', $text ) ) );
		foreach ( (array) $words as $word ) {
			if ( in_array( $word, array( 'tel', 'tin', 'ein', 'abn', 'cell' ), true )
				|| 1 === preg_match( '/^(tax|vat|gst|phone|telephone|mobile|registration)/', $word ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Turn sanitised POST values into the answer shapes the server validates.
	 *
	 * Mirrors the TackQuote server's form validation for what can
	 * be checked without the server: a required, shown field must be answered, and
	 * nothing may exceed the length caps. Hidden (`showIf`) fields are dropped the
	 * way the server drops them, so a required field behind an unticked box is not
	 * demanded.
	 *
	 * @param array $fields The form's field definitions.
	 * @param array $raw    Sanitised POST values keyed by field key.
	 * @return array{values:array,refill:array,error:string|null}
	 */
	public function collect_answers( array $fields, array $raw ) {
		$values = array();
		$refill = array();
		$by_key = array();
		foreach ( $fields as $field ) {
			if ( is_array( $field ) && ! empty( $field['key'] ) ) {
				$by_key[ (string) $field['key'] ] = $field;
			}
		}

		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) || empty( $field['key'] ) || empty( $field['type'] ) ) {
				continue;
			}
			$key   = (string) $field['key'];
			$type  = (string) $field['type'];
			$label = isset( $field['label'] ) ? (string) $field['label'] : $key;
			$input = array_key_exists( $key, $raw ) ? $raw[ $key ] : null;

			if ( 'file' === $type ) {
				// Files are posted separately ($_FILES) and checked by pick_files().
				continue;
			}
			if ( ! $this->is_shown( $field, $by_key, $values ) ) {
				continue;
			}

			$answer = $this->coerce_answer( $type, $field, $input );
			if ( null !== $answer && ! self::is_private_refill_field( $field ) ) {
				$refill[ $key ] = $answer;
			}

			$missing = null === $answer
				|| ( is_string( $answer ) && '' === trim( $answer ) )
				|| ( is_array( $answer ) && empty( $answer ) );
			if ( $missing ) {
				if ( ! empty( $field['required'] ) && 'checkbox' !== $type ) {
					return array(
						'values' => array(),
						'refill' => $refill,
						/* translators: %s: the field's label. */
						'error'  => sprintf( __( '%s is required.', 'tackquote' ), $label ),
					);
				}
				if ( 'checkbox' === $type ) {
					if ( ! empty( $field['required'] ) ) {
						return array(
							'values' => array(),
							'refill' => $refill,
							/* translators: %s: the field's label. */
							'error'  => sprintf( __( '%s must be ticked.', 'tackquote' ), $label ),
						);
					}
					$values[ $key ] = false;
				}
				continue;
			}

			if ( is_string( $answer ) ) {
				$max = 'textarea' === $type ? self::MAX_TEXTAREA : self::MAX_TEXT;
				if ( strlen( $answer ) > $max ) {
					return array(
						'values' => array(),
						'refill' => $refill,
						/* translators: %s: the field's label. */
						'error'  => sprintf( __( '%s is too long.', 'tackquote' ), $label ),
					);
				}
			}
			if ( 'address' === $type ) {
				foreach ( self::ADDRESS_REQUIRED as $part ) {
					if ( empty( $answer[ $part ] ) ) {
						return array(
							'values' => array(),
							'refill' => $refill,
							/* translators: 1: the address field's label, 2: the missing part of the address. */
							'error'  => sprintf( __( '%1$s: %2$s is required.', 'tackquote' ), $label, $this->address_part_label( $part ) ),
						);
					}
				}
			}
			$values[ $key ] = $answer;
		}

		return array(
			'values' => $values,
			'refill' => $refill,
			'error'  => null,
		);
	}

	/**
	 * One sanitised POST value in the shape the server expects for its type.
	 *
	 * @param string $type  Field type.
	 * @param array  $field Field definition.
	 * @param mixed  $input Sanitised POST value, or null when absent.
	 * @return string|bool|array|null null when nothing was answered.
	 */
	private function coerce_answer( $type, array $field, $input ) {
		switch ( $type ) {
			case 'checkbox':
				return null === $input ? null : true;

			case 'multiselect':
				if ( ! is_array( $input ) ) {
					return null;
				}
				$options = isset( $field['options'] ) && is_array( $field['options'] ) ? array_map( 'strval', $field['options'] ) : array();
				$picked  = array();
				foreach ( $input as $choice ) {
					$choice = trim( (string) $choice );
					if ( in_array( $choice, $options, true ) && ! in_array( $choice, $picked, true ) ) {
						$picked[] = $choice;
					}
				}
				return empty( $picked ) ? null : $picked;

			case 'address':
				if ( ! is_array( $input ) ) {
					return null;
				}
				$address = array();
				foreach ( self::ADDRESS_PARTS as $part ) {
					if ( isset( $input[ $part ] ) && '' !== trim( (string) $input[ $part ] ) ) {
						$address[ $part ] = substr( trim( (string) $input[ $part ] ), 0, 200 );
					}
				}
				return empty( $address ) ? null : $address;

			case 'textarea':
				return null === $input || is_array( $input ) ? null : trim( (string) $input );

			default:
				// text, email, tel, number, select, date, tax_id: a trimmed single line.
				return null === $input || is_array( $input ) ? null : trim( sanitize_text_field( (string) $input ) );
		}
	}

	/**
	 * Whether a conditional field is shown for the answers collected so far.
	 *
	 * Mirrors `isFieldShown()` on the server: the controller must itself be shown,
	 * and its current value must equal the condition (checkboxes compare booleans,
	 * everything else trimmed strings). Conditions only look backwards.
	 *
	 * @param array $field  Field definition.
	 * @param array $by_key Every field keyed by key.
	 * @param array $values Answers validated so far.
	 * @param int   $depth  Recursion guard.
	 * @return bool
	 */
	public function is_shown( array $field, array $by_key, array $values, $depth = 0 ) {
		if ( empty( $field['showIf'] ) || ! is_array( $field['showIf'] ) || empty( $field['showIf']['field'] ) ) {
			return true;
		}
		if ( $depth > 25 ) {
			return false;
		}
		$controller_key = (string) $field['showIf']['field'];
		if ( ! isset( $by_key[ $controller_key ] ) || ! $this->is_shown( $by_key[ $controller_key ], $by_key, $values, $depth + 1 ) ) {
			return false;
		}
		$current = array_key_exists( $controller_key, $values ) ? $values[ $controller_key ] : null;
		$equals  = array_key_exists( 'equals', $field['showIf'] ) ? $field['showIf']['equals'] : null;
		if ( is_bool( $equals ) ) {
			return $current === $equals;
		}
		return is_string( $current ) && trim( $current ) === (string) $equals;
	}

	/**
	 * Values a signed-in customer should not have to retype.
	 *
	 * Keyed by field key. A field's `role` (what its answer IS) decides first; a
	 * plain `email` field with no role is still prefilled with the account's email.
	 * A field with no role is also matched on the keys TackQuote's standard wholesale
	 * form uses (`firstName`, `lastName`, `companyName`, `phone`), which are the keys
	 * TackQuote reads when it creates the buyer. Names come from WooCommerce billing,
	 * then the WordPress profile, the same as the quote request form.
	 *
	 * @param array $fields Field definitions.
	 * @return array<string,string>
	 */
	private function wholesale_prefill( array $fields ) {
		if ( ! is_user_logged_in() ) {
			return array();
		}
		$user = wp_get_current_user();
		if ( ! $user || empty( $user->ID ) ) {
			return array();
		}
		$email   = isset( $user->user_email ) ? (string) $user->user_email : '';
		$first   = self::customer_name( 'first', $user );
		$last    = self::customer_name( 'last', $user );
		$name    = trim( $first . ' ' . $last );
		$name    = '' !== $name ? $name : ( isset( $user->display_name ) ? (string) $user->display_name : '' );
		$billing = $this->billing_details();
		$by_key  = array(
			'firstName'   => array( 'text', $first ),
			'lastName'    => array( 'text', $last ),
			'companyName' => array( 'text', $billing['company'] ),
			'phone'       => array( 'tel', $billing['phone'] ),
		);

		$out = array();
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) || empty( $field['key'] ) ) {
				continue;
			}
			$key  = (string) $field['key'];
			$role = isset( $field['role'] ) ? (string) $field['role'] : '';
			$type = isset( $field['type'] ) ? (string) $field['type'] : '';
			if ( 'buyer_email' === $role || ( '' === $role && 'email' === $type ) ) {
				$out[ $key ] = $email;
			} elseif ( 'buyer_name' === $role ) {
				$out[ $key ] = $name;
			} elseif ( 'buyer_company' === $role ) {
				$out[ $key ] = $billing['company'];
			} elseif ( 'buyer_phone' === $role ) {
				$out[ $key ] = $billing['phone'];
			} elseif ( '' === $role && isset( $by_key[ $key ] ) && $by_key[ $key ][0] === $type ) {
				$out[ $key ] = $by_key[ $key ][1];
			}
		}
		return array_filter( $out, 'strlen' );
	}

	/**
	 * The signed-in customer's first or last name for a prefill.
	 *
	 * @param string $part `first` or `last`.
	 * @param object $user The current user (fallback when the widget class is absent).
	 * @return string
	 */
	private static function customer_name( $part, $user ) {
		if ( class_exists( 'Tack_Widget' ) ) {
			return Tack_Widget::customer_name( $part );
		}
		$prop = $part . '_name';
		return isset( $user->$prop ) ? trim( (string) $user->$prop ) : '';
	}

	/**
	 * The label of the first REQUIRED file field, or null.
	 *
	 * @param array $fields Field definitions.
	 * @return string|null
	 */
	private function required_file_field( array $fields ) {
		foreach ( $fields as $field ) {
			if ( is_array( $field ) && isset( $field['type'] ) && 'file' === $field['type'] && ! empty( $field['required'] ) ) {
				return isset( $field['label'] ) ? (string) $field['label'] : (string) $field['key'];
			}
		}
		return null;
	}

	// ── Net-terms application ───────────────────────────────────────────────

	/**
	 * The net-terms application form, the outcome of a submission, the customer's
	 * net terms when they already have them, a pending notice when their application
	 * is under review, or — signed out — a login link and nothing else.
	 *
	 * @param string $return_url Where the handler sends the customer back to.
	 * @return string wp_kses()-filtered markup.
	 */
	public function render_net_terms_form( $return_url ) {
		if ( ! is_user_logged_in() ) {
			$html = $this->notice( 'info', __( 'Please log in to your account to apply for net terms.', 'tackquote' ) )
				. '<p class="tackquote-login-link"><a class="' . esc_attr( self::button_class( 'tackquote-login' ) ) . '" href="' . esc_url( wp_login_url( $return_url ) ) . '">' . esc_html__( 'Log in', 'tackquote' ) . '</a></p>';
			return $this->kses( '<div class="tackquote-storefront-form tackquote-net-terms-application">' . $html . '</div>' );
		}

		$outcome = $this->consume_outcome( 'credit' );
		$html    = '';
		if ( null !== $outcome ) {
			$html .= $this->notice( $outcome['kind'], $outcome['message'] );
			if ( 'error' !== $outcome['kind'] ) {
				return $this->kses( '<div class="tackquote-storefront-form tackquote-net-terms-application">' . $html . '</div>' );
			}
		} else {
			// Terms already granted, or an application already under review: no form.
			$standing = $this->net_terms_standing_html();
			if ( '' !== $standing ) {
				return $this->kses( '<div class="tackquote-storefront-form tackquote-net-terms-account">' . $standing . '</div>' );
			}
		}

		$user    = wp_get_current_user();
		$email   = $user && isset( $user->user_email ) ? (string) $user->user_email : '';
		$billing = $this->billing_details();
		$refill  = null !== $outcome && isset( $outcome['values'] ) && is_array( $outcome['values'] ) ? $outcome['values'] : array();
		$pick    = function ( $key, $fallback = '' ) use ( $refill ) {
			return isset( $refill[ $key ] ) && is_string( $refill[ $key ] ) ? $refill[ $key ] : $fallback;
		};

		$notices = $html;
		$html    = $this->input_row(
			'tack_ct[legalBusinessName]',
			__( 'Legal business name', 'tackquote' ),
			$pick( 'legalBusinessName', $billing['company'] ),
			'text',
			true,
			array(
				'maxlength'    => '200',
				'autocomplete' => 'organization',
			)
		);

		// The account's own address, shown but not editable: the server records the
		// applicant under this email, and a typed one would let anyone apply as anyone.
		$html .= '<p class="form-row form-row-wide"><label for="tack_ct_contactEmail">' . esc_html__( 'Contact email', 'tackquote' ) . '</label>'
			. '<input type="email" class="input-text" id="tack_ct_contactEmail" value="' . esc_attr( $email ) . '" disabled="disabled" readonly="readonly" />'
			. '<span class="description">' . esc_html__( 'Your account email. Change it under Account details.', 'tackquote' ) . '</span></p>';

		$html .= $this->input_row(
			'tack_ct[contactPhone]',
			__( 'Contact phone', 'tackquote' ),
			$pick( 'contactPhone', $billing['phone'] ),
			'tel',
			false,
			array(
				'maxlength'    => '40',
				'autocomplete' => 'tel',
			)
		);
		$html .= $this->input_row( 'tack_ct[taxId]', __( 'Tax / VAT ID', 'tackquote' ), $pick( 'taxId' ), 'text', false, array( 'maxlength' => '64' ) );

		$address_refill = isset( $refill['billingAddress'] ) && is_array( $refill['billingAddress'] ) ? $refill['billingAddress'] : array();
		$html          .= $this->address_fieldset(
			'tack_ct[billingAddress]',
			__( 'Billing address', 'tackquote' ),
			! empty( $address_refill ) ? $address_refill : $billing['address'],
			false
		);

		$html .= $this->input_row(
			'tack_ct[requestedLimit]',
			__( 'Requested credit limit', 'tackquote' ),
			$pick( 'requestedLimit' ),
			'number',
			false,
			array(
				'min'       => '0',
				'step'      => 'any',
				'inputmode' => 'decimal',
			)
		);

		$terms_value = (int) $pick( 'requestedTermsDays', '30' );
		$html       .= '<p class="form-row form-row-wide"><label for="tack_ct_requestedTermsDays">' . esc_html__( 'Requested payment terms', 'tackquote' ) . '</label>'
			. '<select name="tack_ct[requestedTermsDays]" id="tack_ct_requestedTermsDays" class="select">';
		foreach ( self::TERMS_DAYS as $days ) {
			$html .= '<option value="' . esc_attr( (string) $days ) . '"' . ( $days === $terms_value ? ' selected="selected"' : '' ) . '>'
				/* translators: %d: number of days. */
				. esc_html( sprintf( __( 'Net %d days', 'tackquote' ), $days ) ) . '</option>';
		}
		$html .= '</select></p>';

		$references = isset( $refill['tradeReferences'] ) && is_array( $refill['tradeReferences'] ) ? array_values( $refill['tradeReferences'] ) : array();
		$html      .= '<fieldset class="tackquote-trade-references"><legend>' . esc_html__( 'Trade references', 'tackquote' ) . '</legend>'
			. '<p class="description">' . esc_html( sprintf( /* translators: %d: how many references the form takes. */ __( 'Up to %d suppliers who can vouch for your payment history. Optional.', 'tackquote' ), self::MAX_REFERENCES ) ) . '</p>';
		for ( $i = 0; $i < self::MAX_REFERENCES; $i++ ) {
			$ref   = isset( $references[ $i ] ) && is_array( $references[ $i ] ) ? $references[ $i ] : array();
			$html .= '<div class="tackquote-trade-reference">'
				/* translators: %d: the reference's number. */
				. '<h3 class="tackquote-trade-reference-title">' . esc_html( sprintf( __( 'Reference %d', 'tackquote' ), $i + 1 ) ) . '</h3>'
				. $this->input_row( "tack_ct[tradeReferences][$i][companyName]", __( 'Company', 'tackquote' ), isset( $ref['companyName'] ) ? (string) $ref['companyName'] : '', 'text', false, array( 'maxlength' => '200' ) )
				. $this->input_row( "tack_ct[tradeReferences][$i][contactName]", __( 'Contact name', 'tackquote' ), isset( $ref['contactName'] ) ? (string) $ref['contactName'] : '', 'text', false, array( 'maxlength' => '200' ) )
				. $this->input_row( "tack_ct[tradeReferences][$i][email]", __( 'Contact email', 'tackquote' ), isset( $ref['email'] ) ? (string) $ref['email'] : '', 'email', false, array( 'maxlength' => '320' ) )
				. $this->input_row( "tack_ct[tradeReferences][$i][phone]", __( 'Contact phone', 'tackquote' ), isset( $ref['phone'] ) ? (string) $ref['phone'] : '', 'tel', false, array( 'maxlength' => '40' ) )
				. '</div>';
		}
		$html .= '</fieldset>';

		$html .= '<p class="form-row form-row-wide"><label for="tack_ct_notes">' . esc_html__( 'Notes', 'tackquote' ) . ' <span class="optional">' . esc_html__( '(optional)', 'tackquote' ) . '</span></label>'
			. '<textarea name="tack_ct[notes]" id="tack_ct_notes" class="input-text" rows="4" maxlength="2000">' . esc_textarea( $pick( 'notes' ) ) . '</textarea></p>';

		$html = $this->form_template(
			'myaccount/form-net-terms-application.php',
			array(
				'notices'      => $notices,
				'description'  => __( 'Apply to pay on account. The store reviews every application and will email you with its decision.', 'tackquote' ),
				'action_url'   => admin_url( 'admin-post.php' ),
				'fields'       => $html,
				'hidden'       => '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_CREDIT ) . '" />'
					. '<input type="hidden" name="tack_redirect" value="' . esc_url( $return_url ) . '" />'
					. wp_nonce_field( self::ACTION_CREDIT, '_tack_nonce', true, false ),
				'submit_label' => __( 'Apply for net terms', 'tackquote' ),
				'submit_class' => self::button_class( 'tackquote-submit' ),
			),
			'credit'
		);

		return $this->kses( '<div class="tackquote-storefront-form tackquote-net-terms-application">' . $html . '</div>' );
	}

	/**
	 * Classify a `GET /storefront/v1/net-terms` answer for the Net terms tab.
	 *
	 * `active`: a `standing` answer whose credit line is active with positive terms
	 * days and a currency (what the checkout gateway also requires). `pending`: an
	 * application under review (`state: pending`) and no active line. Anything else,
	 * including an unknown shape, is `none`, and the tab shows the form.
	 *
	 * @since 1.10.2
	 *
	 * @param mixed $answer Decoded answer.
	 * @return array{state:string,termsDays:int,creditLimit:string,available:string,currency:string,pending:bool}
	 */
	public static function net_terms_view( $answer ) {
		$view = array(
			'state'       => 'none',
			'termsDays'   => 0,
			'creditLimit' => '',
			'available'   => '',
			'currency'    => '',
			'pending'     => false,
		);
		if ( ! is_array( $answer ) || ! isset( $answer['status'] ) || 'standing' !== $answer['status'] ) {
			return $view;
		}
		$view['pending'] = isset( $answer['state'] ) && 'pending' === $answer['state'];
		$account         = isset( $answer['account'] ) && is_array( $answer['account'] ) ? $answer['account'] : null;
		$days            = null !== $account && isset( $account['termsDays'] ) && is_int( $account['termsDays'] ) ? $account['termsDays'] : 0;
		$currency        = null !== $account && isset( $account['currency'] ) && is_string( $account['currency'] ) ? strtoupper( trim( $account['currency'] ) ) : '';
		if ( null !== $account && isset( $account['status'] ) && 'active' === $account['status'] && $days > 0 && 1 === preg_match( '/^[A-Z]{3}$/', $currency ) ) {
			$view['state']     = 'active';
			$view['termsDays'] = $days;
			$view['currency']  = $currency;
			foreach ( array( 'creditLimit', 'available' ) as $field ) {
				if ( isset( $account[ $field ] ) && is_numeric( $account[ $field ] ) && is_finite( (float) $account[ $field ] ) ) {
					$view[ $field ] = (string) $account[ $field ];
				}
			}
			return $view;
		}
		if ( $view['pending'] ) {
			$view['state'] = 'pending';
		}
		return $view;
	}

	/**
	 * What the Net terms tab shows INSTEAD of the form, or '' to show the form.
	 *
	 * Reads the same cached standing as the checkout gateway (Tack_Net_Terms_Standing).
	 * Fails closed to the form: an untrusted email, an unreachable TackQuote or an
	 * unknown answer claims nothing about the customer's terms.
	 *
	 * @since 1.10.2
	 *
	 * @return string Escaped markup, or ''.
	 */
	private function net_terms_standing_html() {
		$email = self::trusted_account_email();
		if ( '' === $email ) {
			return '';
		}
		$answer = Tack_Net_Terms_Standing::read( $this->client, (int) get_current_user_id(), $email );
		if ( is_wp_error( $answer ) ) {
			$this->log( 'net-terms standing read failed; showing the application form: ' . $this->error_summary( $answer ) );
			return '';
		}
		$view = self::net_terms_view( $answer );
		if ( 'pending' === $view['state'] ) {
			return $this->notice( 'pending', __( 'Your net-terms application is being reviewed.', 'tackquote' ) );
		}
		if ( 'active' !== $view['state'] ) {
			return '';
		}

		$money = function ( $amount ) use ( $view ) {
			return wc_price( (float) $amount, array( 'currency' => $view['currency'] ) );
		};
		$lines = array(
			/* translators: %d: number of days. */
			'terms' => esc_html( sprintf( __( 'Net %d days', 'tackquote' ), $view['termsDays'] ) ),
		);
		if ( '' !== $view['creditLimit'] ) {
			/* translators: %s: the credit limit, formatted as a price. */
			$lines['credit_limit'] = sprintf( esc_html__( 'Credit limit: %s', 'tackquote' ), $money( $view['creditLimit'] ) );
		}
		if ( '' !== $view['available'] ) {
			/* translators: %s: the credit still available, formatted as a price. */
			$lines['available'] = sprintf( esc_html__( 'Available credit: %s', 'tackquote' ), $money( $view['available'] ) );
		}

		return Tack_Templates::html(
			'myaccount/net-terms-account.php',
			array(
				'notices'      => $view['pending'] ? $this->notice( 'pending', __( 'Your net-terms application is being reviewed.', 'tackquote' ) ) : '',
				'heading'      => __( 'Net terms', 'tackquote' ),
				'description'  => __( 'Your account can pay on net terms.', 'tackquote' ),
				'lines'        => $lines,
				'terms_days'   => $view['termsDays'],
				'credit_limit' => $view['creditLimit'],
				'available'    => $view['available'],
				'currency'     => $view['currency'],
			)
		);
	}

	/**
	 * The admin-post handler for the net-terms form. Redirects and exits.
	 */
	public function handle_credit_submit() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified inside process_credit_submission().
		$post    = wp_unslash( $_POST );
		$outcome = $this->process_credit_submission( is_array( $post ) ? $post : array() );
		$this->respond( $outcome );
	}

	/**
	 * Validate, sanitise and send a net-terms application. Pure apart from the API call.
	 *
	 * Refuses a signed-out caller outright: the application is attached to the
	 * account's own email, so there is nobody to attach it to.
	 *
	 * @param array $post The unslashed POST body.
	 * @return array{kind:string,message:string,redirect:string,form:string,values?:array}
	 */
	public function process_credit_submission( array $post ) {
		$redirect = $this->safe_redirect_target( isset( $post['tack_redirect'] ) ? (string) $post['tack_redirect'] : '' );
		$outcome  = array(
			'form'     => 'credit',
			'redirect' => $redirect,
		);

		if ( ! is_user_logged_in() ) {
			$outcome['redirect'] = wp_login_url( $redirect );
			return $outcome + $this->failure( __( 'Please log in to your account to apply for net terms.', 'tackquote' ) );
		}

		if ( ! isset( $post['_tack_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( (string) $post['_tack_nonce'] ), self::ACTION_CREDIT ) ) {
			return $outcome + $this->failure( __( 'Your session has expired. Please reload the page and try again.', 'tackquote' ) );
		}
		if ( self::rate_limited() ) {
			return $outcome + $this->failure( self::rate_limit_message() );
		}

		$user = wp_get_current_user();
		if ( $user && ! empty( $user->ID ) && '' === self::trusted_account_email() && isset( $user->user_email ) && '' !== (string) $user->user_email ) {
			return $outcome + $this->failure( __( 'Your account email was changed recently and must be confirmed by the store before you can apply for net terms. Please contact the store.', 'tackquote' ) );
		}
		$email = sanitize_email( self::trusted_account_email() );
		if ( '' === $email || ! is_email( $email ) ) {
			return $outcome + $this->failure( __( 'Your account has no valid email address. Please add one under Account details first.', 'tackquote' ) );
		}

		$raw       = isset( $post['tack_ct'] ) && is_array( $post['tack_ct'] ) ? map_deep( $post['tack_ct'], 'sanitize_textarea_field' ) : array();
		$validated = $this->validate_credit_payload( $raw, $email );
		if ( null !== $validated['error'] ) {
			return $outcome + $this->failure( $validated['error'], $validated['refill'] );
		}

		self::count_application();
		$result = $this->client->submit_credit_application( $validated['payload'] );
		if ( is_wp_error( $result ) ) {
			$this->log( 'credit-application submit failed: ' . $this->error_summary( $result ) );
			return $outcome + $this->failure( $this->friendly_error( $result ), $validated['refill'] );
		}

		$status = isset( $result['status'] ) ? (string) $result['status'] : '';
		if ( 'already_pending' === $status ) {
			$outcome['kind']    = 'pending';
			$outcome['message'] = __( 'You already have a net-terms application awaiting review. The store will email you with its decision.', 'tackquote' );
		} else {
			$outcome['kind']    = 'success';
			$outcome['message'] = __( 'Your net-terms application has been submitted. The store will email you with its decision.', 'tackquote' );
		}
		return $outcome;
	}

	/**
	 * Check a net-terms submission against the bounds TackQuote's credit application enforces.
	 *
	 * @param array  $raw   Sanitised POST values.
	 * @param string $email The account's email (never taken from the form).
	 * @return array{payload:array,refill:array,error:string|null}
	 */
	public function validate_credit_payload( array $raw, $email ) {
		$text = function ( $key, $max ) use ( $raw ) {
			$value = isset( $raw[ $key ] ) && ! is_array( $raw[ $key ] ) ? trim( sanitize_text_field( (string) $raw[ $key ] ) ) : '';
			return array( $value, strlen( $value ) > $max );
		};

		$refill  = array();
		$payload = array( 'contactEmail' => $email );

		list( $name, $too_long )     = $text( 'legalBusinessName', 200 );
		$refill['legalBusinessName'] = $name;
		if ( '' === $name ) {
			return $this->credit_invalid( __( 'Legal business name is required.', 'tackquote' ), $refill );
		}
		if ( $too_long ) {
			return $this->credit_invalid( __( 'Legal business name is too long.', 'tackquote' ), $refill );
		}
		$payload['legalBusinessName'] = $name;

		list( $phone, $too_long ) = $text( 'contactPhone', 40 );
		// Not refilled: a phone number is not kept in the outcome transient.
		if ( $too_long ) {
			return $this->credit_invalid( __( 'Contact phone is too long.', 'tackquote' ), $refill );
		}
		if ( '' !== $phone ) {
			$payload['contactPhone'] = $phone;
		}

		list( $tax_id, $too_long ) = $text( 'taxId', 64 );
		// Not refilled: a tax ID is not kept in the outcome transient.
		if ( $too_long ) {
			return $this->credit_invalid( __( 'Tax / VAT ID is too long.', 'tackquote' ), $refill );
		}
		if ( '' !== $tax_id ) {
			$payload['taxId'] = $tax_id;
		}

		$address = array();
		if ( isset( $raw['billingAddress'] ) && is_array( $raw['billingAddress'] ) ) {
			foreach ( self::ADDRESS_PARTS as $part ) {
				if ( isset( $raw['billingAddress'][ $part ] ) && '' !== trim( (string) $raw['billingAddress'][ $part ] ) ) {
					$address[ $part ] = substr( trim( sanitize_text_field( (string) $raw['billingAddress'][ $part ] ) ), 0, 200 );
				}
			}
		}
		$refill['billingAddress'] = $address;
		if ( ! empty( $address ) ) {
			foreach ( self::ADDRESS_REQUIRED as $part ) {
				if ( empty( $address[ $part ] ) ) {
					return $this->credit_invalid(
						sprintf(
							/* translators: %s: the missing part of the address. */
							__( 'Billing address: %s is required.', 'tackquote' ),
							$this->address_part_label( $part )
						),
						$refill
					);
				}
			}
			$payload['billingAddress'] = $address;
		}

		$limit_raw                = isset( $raw['requestedLimit'] ) && ! is_array( $raw['requestedLimit'] ) ? trim( (string) $raw['requestedLimit'] ) : '';
		$refill['requestedLimit'] = $limit_raw;
		if ( '' !== $limit_raw ) {
			if ( ! is_numeric( $limit_raw ) || (float) $limit_raw < 0 || (float) $limit_raw > self::MAX_REQUESTED_LIMIT ) {
				return $this->credit_invalid( __( 'Requested credit limit must be a number between 0 and 100,000,000.', 'tackquote' ), $refill );
			}
			$payload['requestedLimit'] = (float) $limit_raw;
		}

		$days_raw                     = isset( $raw['requestedTermsDays'] ) && ! is_array( $raw['requestedTermsDays'] ) ? trim( (string) $raw['requestedTermsDays'] ) : '';
		$refill['requestedTermsDays'] = $days_raw;
		if ( '' !== $days_raw ) {
			if ( ! ctype_digit( $days_raw ) || ! in_array( (int) $days_raw, self::TERMS_DAYS, true ) ) {
				return $this->credit_invalid( __( 'Please choose one of the offered payment terms.', 'tackquote' ), $refill );
			}
			$payload['requestedTermsDays'] = (int) $days_raw;
		}

		$references = array();
		if ( isset( $raw['tradeReferences'] ) && is_array( $raw['tradeReferences'] ) ) {
			$caps = array(
				'companyName' => 200,
				'contactName' => 200,
				'email'       => 320,
				'phone'       => 40,
			);
			foreach ( array_values( $raw['tradeReferences'] ) as $row ) {
				if ( count( $references ) >= self::MAX_REFERENCES ) {
					break;
				}
				if ( ! is_array( $row ) ) {
					continue;
				}
				$reference = array();
				foreach ( $caps as $part => $cap ) {
					$value = isset( $row[ $part ] ) && ! is_array( $row[ $part ] ) ? trim( sanitize_text_field( (string) $row[ $part ] ) ) : '';
					if ( '' === $value ) {
						continue;
					}
					if ( strlen( $value ) > $cap ) {
						return $this->credit_invalid( __( 'A trade reference entry is too long.', 'tackquote' ), $refill );
					}
					if ( 'email' === $part && ! is_email( $value ) ) {
						return $this->credit_invalid( __( 'A trade reference email address is not valid.', 'tackquote' ), $refill );
					}
					$reference[ $part ] = $value;
				}
				if ( ! empty( $reference ) ) {
					$references[] = $reference;
				}
			}
		}
		$refill['tradeReferences'] = array_map(
			static function ( $reference ) {
				unset( $reference['phone'] );
				return $reference;
			},
			$references
		);
		if ( ! empty( $references ) ) {
			$payload['tradeReferences'] = $references;
		}

		$notes           = isset( $raw['notes'] ) && ! is_array( $raw['notes'] ) ? trim( (string) $raw['notes'] ) : '';
		$refill['notes'] = $notes;
		if ( strlen( $notes ) > self::MAX_TEXTAREA ) {
			return $this->credit_invalid( __( 'Notes are too long (2,000 characters at most).', 'tackquote' ), $refill );
		}
		if ( '' !== $notes ) {
			$payload['notes'] = $notes;
		}

		return array(
			'payload' => $payload,
			'refill'  => $refill,
			'error'   => null,
		);
	}

	/**
	 * A validation failure for the net-terms form.
	 *
	 * @param string $message What is wrong.
	 * @param array  $refill  What the customer typed, to put back in the form.
	 * @return array{payload:array,refill:array,error:string}
	 */
	private function credit_invalid( $message, array $refill ) {
		return array(
			'payload' => array(),
			'refill'  => $refill,
			'error'   => $message,
		);
	}

	// ── Field rendering ─────────────────────────────────────────────────────

	/**
	 * One form field from its definition.
	 *
	 * @param array  $field     Field definition (`key`, `label`, `type`, `required`, `options`, `help`, `showIf`, `min`).
	 * @param string $name      The POST array the field belongs to (`tack_sf`).
	 * @param mixed  $value     Current value (prefill or refill).
	 * @param string $file_mode 1.10.0: file_mode() for this visitor (`upload`, `signin`, `unsupported`).
	 * @return string
	 */
	public function render_field( array $field, $name, $value, $file_mode = 'unsupported' ) {
		$key      = (string) $field['key'];
		$type     = (string) $field['type'];
		$label    = isset( $field['label'] ) ? (string) $field['label'] : $key;
		$required = ! empty( $field['required'] );
		$id       = 'tack_sf_' . sanitize_key( $key );
		$name_of  = $name . '[' . $key . ']';
		$help     = isset( $field['help'] ) && is_string( $field['help'] ) && '' !== $field['help'] ? $field['help'] : '';
		$cond     = '';
		if ( ! empty( $field['showIf'] ) && is_array( $field['showIf'] ) && ! empty( $field['showIf']['field'] ) ) {
			$equals = array_key_exists( 'equals', $field['showIf'] ) ? $field['showIf']['equals'] : '';
			$cond   = ' data-tack-show-if-field="' . esc_attr( (string) $field['showIf']['field'] ) . '"'
				. ' data-tack-show-if-equals="' . esc_attr( is_bool( $equals ) ? ( $equals ? 'true' : 'false' ) : (string) $equals ) . '"';
		}
		$label_html = esc_html( $label ) . ( $required
			? ' <span class="required" aria-hidden="true">*</span>'
			: ' <span class="optional">' . esc_html__( '(optional)', 'tackquote' ) . '</span>' );
		$help_html  = '' !== $help ? '<span class="description" id="' . esc_attr( $id . '_help' ) . '">' . esc_html( $help ) . '</span>' : '';
		$describe   = '' !== $help ? ' aria-describedby="' . esc_attr( $id . '_help' ) . '"' : '';
		$req_attr   = $required ? ' required="required"' : '';

		switch ( $type ) {
			case 'file':
				if ( 'upload' === $file_mode ) {
					$max_mb = isset( $field['maxSizeMb'] ) && is_numeric( $field['maxSizeMb'] ) ? max( 1, min( Tack_Attachments::MAX_MB, (int) $field['maxSizeMb'] ) ) : Tack_Attachments::MAX_MB;
					$accept = isset( $field['accept'] ) && is_array( $field['accept'] )
						? array_values( array_intersect( array_map( 'strval', $field['accept'] ), Tack_Attachments::MIME_TYPES ) )
						: array_values( array_unique( Tack_Attachments::MIME_TYPES ) );
					$tokens = array();
					foreach ( array_keys( array_intersect( Tack_Attachments::MIME_TYPES, $accept ) ) as $ext ) {
						$tokens[] = '.' . $ext;
					}
					$attr = implode( ',', array_merge( $tokens, $accept ) );
					$hint = sprintf(
						/* translators: %d: size limit in megabytes. */
						__( 'PDF, JPEG or PNG, up to %d MB.', 'tackquote' ),
						$max_mb
					);
					return '<p class="form-row form-row-wide tackquote-field tackquote-field-file" data-tack-field="' . esc_attr( $key ) . '"' . $cond . '>'
						. '<label for="' . esc_attr( $id ) . '">' . $label_html . '</label>'
						. '<input type="file" name="' . esc_attr( 'tack_sf_files[' . $key . ']' ) . '" id="' . esc_attr( $id ) . '" accept="' . esc_attr( $attr ) . '"' . $req_attr . ' aria-describedby="' . esc_attr( $id . '_file' ) . '" />'
						. '<span class="description" id="' . esc_attr( $id . '_file' ) . '">' . esc_html( '' !== $help ? $help . ' ' . $hint : $hint ) . '</span>'
						. '</p>';
				}
				$message = 'signin' === $file_mode
					? __( 'Sign in to your account to attach files.', 'tackquote' )
					: __( 'Files cannot be attached on this store yet. If the store needs a document from you, please email it to them.', 'tackquote' );
				return '<p class="form-row form-row-wide tackquote-field tackquote-field-file" data-tack-field="' . esc_attr( $key ) . '"' . $cond . '>'
					. '<label>' . $label_html . '</label>'
					. '<span class="description">' . esc_html( $message ) . '</span>'
					. '</p>';

			case 'textarea':
				return '<p class="form-row form-row-wide tackquote-field" data-tack-field="' . esc_attr( $key ) . '"' . $cond . '>'
					. '<label for="' . esc_attr( $id ) . '">' . $label_html . '</label>'
					. '<textarea name="' . esc_attr( $name_of ) . '" id="' . esc_attr( $id ) . '" class="input-text" rows="4" maxlength="' . esc_attr( (string) self::MAX_TEXTAREA ) . '"' . $req_attr . $describe . '>'
					. esc_textarea( is_string( $value ) ? $value : '' )
					. '</textarea>' . $help_html . '</p>';

			case 'select':
				$options = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();
				$labels  = isset( $field['optionLabels'] ) && is_array( $field['optionLabels'] ) ? $field['optionLabels'] : array();
				$html    = '<p class="form-row form-row-wide tackquote-field" data-tack-field="' . esc_attr( $key ) . '"' . $cond . '>'
					. '<label for="' . esc_attr( $id ) . '">' . $label_html . '</label>'
					. '<select name="' . esc_attr( $name_of ) . '" id="' . esc_attr( $id ) . '" class="select"' . $req_attr . $describe . '>'
					. '<option value="">' . esc_html__( 'Choose an option', 'tackquote' ) . '</option>';
				foreach ( array_values( $options ) as $i => $option ) {
					$option = (string) $option;
					$text   = isset( $labels[ $i ] ) && is_string( $labels[ $i ] ) && '' !== $labels[ $i ] ? $labels[ $i ] : $option;
					$html  .= '<option value="' . esc_attr( $option ) . '"' . ( is_string( $value ) && $value === $option ? ' selected="selected"' : '' ) . '>' . esc_html( $text ) . '</option>';
				}
				return $html . '</select>' . $help_html . '</p>';

			case 'multiselect':
				$options = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();
				$labels  = isset( $field['optionLabels'] ) && is_array( $field['optionLabels'] ) ? $field['optionLabels'] : array();
				$picked  = is_array( $value ) ? array_map( 'strval', $value ) : array();
				$html    = '<fieldset class="form-row form-row-wide tackquote-field tackquote-field-multiselect" data-tack-field="' . esc_attr( $key ) . '"' . $cond . '>'
					. '<legend>' . $label_html . '</legend>';
				foreach ( array_values( $options ) as $i => $option ) {
					$option = (string) $option;
					$text   = isset( $labels[ $i ] ) && is_string( $labels[ $i ] ) && '' !== $labels[ $i ] ? $labels[ $i ] : $option;
					$oid    = $id . '_' . $i;
					$html  .= '<label class="tackquote-choice" for="' . esc_attr( $oid ) . '">'
						. '<input type="checkbox" name="' . esc_attr( $name_of ) . '[]" id="' . esc_attr( $oid ) . '" value="' . esc_attr( $option ) . '"' . ( in_array( $option, $picked, true ) ? ' checked="checked"' : '' ) . ' /> '
						. esc_html( $text ) . '</label>';
				}
				return $html . $help_html . '</fieldset>';

			case 'checkbox':
				return '<p class="form-row form-row-wide tackquote-field tackquote-field-checkbox" data-tack-field="' . esc_attr( $key ) . '"' . $cond . '>'
					. '<label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox" for="' . esc_attr( $id ) . '">'
					. '<input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox" name="' . esc_attr( $name_of ) . '" id="' . esc_attr( $id ) . '" value="1"' . ( true === $value || '1' === $value ? ' checked="checked"' : '' ) . $req_attr . $describe . ' /> '
					. '<span>' . $label_html . '</span></label>' . $help_html . '</p>';

			case 'address':
				return $this->address_fieldset( $name_of, $label, is_array( $value ) ? $value : array(), $required, $cond, $help );

			default:
				$input_type = in_array( $type, array( 'email', 'tel', 'number', 'date' ), true ) ? $type : 'text';
				$extra      = array( 'maxlength' => (string) self::MAX_TEXT );
				if ( 'email' === $type ) {
					$extra['autocomplete'] = 'email';
				} elseif ( 'tel' === $type ) {
					$extra['autocomplete'] = 'tel';
				} elseif ( 'number' === $type ) {
					$extra['step']      = 'any';
					$extra['inputmode'] = 'decimal';
				} elseif ( 'date' === $type && isset( $field['min'] ) && 'today' === $field['min'] ) {
					$extra['min'] = gmdate( 'Y-m-d' );
				}
				return $this->input_row( $name_of, $label, is_string( $value ) ? $value : '', $input_type, $required, $extra, $id, $cond, $help );
		}
	}

	/**
	 * A labelled single-line input in WooCommerce's form-row markup.
	 *
	 * @param string $name     Input name.
	 * @param string $label    Label text.
	 * @param string $value    Current value.
	 * @param string $type     Input type.
	 * @param bool   $required Whether the input is required.
	 * @param array  $extra    Extra attributes (maxlength, min, step, autocomplete, inputmode).
	 * @param string $id       Element id, derived from the name when ''.
	 * @param string $cond     Pre-escaped showIf data attributes.
	 * @param string $help     Help text.
	 * @return string
	 */
	private function input_row( $name, $label, $value, $type = 'text', $required = false, array $extra = array(), $id = '', $cond = '', $help = '' ) {
		if ( '' === $id ) {
			$id = sanitize_key( str_replace( array( '[', ']' ), array( '_', '' ), $name ) );
		}
		$attrs = '';
		foreach ( $extra as $attr => $attr_value ) {
			if ( in_array( $attr, array( 'maxlength', 'min', 'max', 'step', 'autocomplete', 'inputmode', 'placeholder' ), true ) ) {
				$attrs .= ' ' . $attr . '="' . esc_attr( (string) $attr_value ) . '"';
			}
		}
		$help_html = '' !== $help ? '<span class="description" id="' . esc_attr( $id . '_help' ) . '">' . esc_html( $help ) . '</span>' : '';
		$describe  = '' !== $help ? ' aria-describedby="' . esc_attr( $id . '_help' ) . '"' : '';
		return '<p class="form-row form-row-wide tackquote-field"' . $cond . '>'
			. '<label for="' . esc_attr( $id ) . '">' . esc_html( $label )
			. ( $required ? ' <span class="required" aria-hidden="true">*</span>' : ' <span class="optional">' . esc_html__( '(optional)', 'tackquote' ) . '</span>' )
			. '</label>'
			. '<input type="' . esc_attr( $type ) . '" class="input-text" name="' . esc_attr( $name ) . '" id="' . esc_attr( $id ) . '" value="' . esc_attr( $value ) . '"' . $attrs . ( $required ? ' required="required"' : '' ) . $describe . ' />'
			. $help_html . '</p>';
	}

	/**
	 * The six-part address group, with a country selector when WooCommerce can list countries.
	 *
	 * @param string $name     Base input name (`tack_sf[key]`).
	 * @param string $label    Group label.
	 * @param array  $value    Current parts.
	 * @param bool   $required Whether the whole address is required.
	 * @param string $cond     Pre-escaped showIf data attributes.
	 * @param string $help     Help text.
	 * @return string
	 */
	private function address_fieldset( $name, $label, array $value, $required, $cond = '', $help = '' ) {
		$base  = sanitize_key( str_replace( array( '[', ']' ), array( '_', '' ), $name ) );
		$parts = array(
			'line1'      => array( __( 'Street address', 'tackquote' ), 'address-line1' ),
			'line2'      => array( __( 'Address line 2', 'tackquote' ), 'address-line2' ),
			'city'       => array( __( 'City', 'tackquote' ), 'address-level2' ),
			'region'     => array( __( 'State / Province', 'tackquote' ), 'address-level1' ),
			'postalCode' => array( __( 'Postal code', 'tackquote' ), 'postal-code' ),
		);
		$html  = '<fieldset class="tackquote-field tackquote-field-address"' . $cond . '><legend>' . esc_html( $label )
			. ( $required ? ' <span class="required" aria-hidden="true">*</span>' : ' <span class="optional">' . esc_html__( '(optional)', 'tackquote' ) . '</span>' )
			. '</legend>';
		foreach ( $parts as $part => $meta ) {
			$part_required = $required && in_array( $part, self::ADDRESS_REQUIRED, true );
			$html         .= $this->input_row(
				$name . '[' . $part . ']',
				$meta[0],
				isset( $value[ $part ] ) ? (string) $value[ $part ] : '',
				'text',
				$part_required,
				array(
					'maxlength'    => '200',
					'autocomplete' => $meta[1],
				),
				$base . '_' . sanitize_key( $part )
			);
		}

		$country_id = $base . '_country';
		$current    = isset( $value['country'] ) ? (string) $value['country'] : '';
		$countries  = $this->allowed_countries();
		$html      .= '<p class="form-row form-row-wide tackquote-field"><label for="' . esc_attr( $country_id ) . '">' . esc_html__( 'Country', 'tackquote' )
			. ( $required ? ' <span class="required" aria-hidden="true">*</span>' : '' ) . '</label>';
		if ( ! empty( $countries ) ) {
			$html .= '<select name="' . esc_attr( $name . '[country]' ) . '" id="' . esc_attr( $country_id ) . '" class="select" autocomplete="country"' . ( $required ? ' required="required"' : '' ) . '>'
				. '<option value="">' . esc_html__( 'Choose a country', 'tackquote' ) . '</option>';
			foreach ( $countries as $code => $country_name ) {
				$html .= '<option value="' . esc_attr( (string) $code ) . '"' . ( (string) $code === $current ? ' selected="selected"' : '' ) . '>' . esc_html( (string) $country_name ) . '</option>';
			}
			$html .= '</select>';
		} else {
			$html .= '<input type="text" class="input-text" name="' . esc_attr( $name . '[country]' ) . '" id="' . esc_attr( $country_id ) . '" value="' . esc_attr( $current ) . '" maxlength="200" autocomplete="country"' . ( $required ? ' required="required"' : '' ) . ' />';
		}
		$html .= '</p>';
		if ( '' !== $help ) {
			$html .= '<span class="description">' . esc_html( $help ) . '</span>';
		}
		return $html . '</fieldset>';
	}

	/**
	 * WooCommerce's allowed-countries list, or an empty array when unavailable.
	 *
	 * @return array<string,string>
	 */
	private function allowed_countries() {
		if ( ! function_exists( 'WC' ) ) {
			return array();
		}
		$wc = WC();
		if ( ! is_object( $wc ) || ! isset( $wc->countries ) || ! is_object( $wc->countries ) || ! method_exists( $wc->countries, 'get_allowed_countries' ) ) {
			return array();
		}
		$countries = $wc->countries->get_allowed_countries();
		return is_array( $countries ) ? $countries : array();
	}

	/**
	 * Merchant-readable name of an address part.
	 *
	 * @param string $part Address part key.
	 * @return string
	 */
	private function address_part_label( $part ) {
		$labels = array(
			'line1'      => __( 'street address', 'tackquote' ),
			'line2'      => __( 'address line 2', 'tackquote' ),
			'city'       => __( 'city', 'tackquote' ),
			'region'     => __( 'state / province', 'tackquote' ),
			'postalCode' => __( 'postal code', 'tackquote' ),
			'country'    => __( 'country', 'tackquote' ),
		);
		return isset( $labels[ $part ] ) ? $labels[ $part ] : $part;
	}

	/**
	 * The signed-in customer's billing details, for prefilling. Empty strings when unknown.
	 *
	 * @return array{company:string,phone:string,address:array<string,string>}
	 */
	private function billing_details() {
		$out = array(
			'company' => '',
			'phone'   => '',
			'address' => array(),
		);
		if ( ! function_exists( 'WC' ) ) {
			return $out;
		}
		$wc = WC();
		if ( ! is_object( $wc ) || ! isset( $wc->customer ) || ! is_object( $wc->customer ) ) {
			return $out;
		}
		$customer       = $wc->customer;
		$read           = function ( $method ) use ( $customer ) {
			return method_exists( $customer, $method ) ? trim( (string) $customer->$method() ) : '';
		};
		$out['company'] = $read( 'get_billing_company' );
		$out['phone']   = $read( 'get_billing_phone' );
		$address        = array(
			'line1'      => $read( 'get_billing_address_1' ),
			'line2'      => $read( 'get_billing_address_2' ),
			'city'       => $read( 'get_billing_city' ),
			'region'     => $read( 'get_billing_state' ),
			'postalCode' => $read( 'get_billing_postcode' ),
			'country'    => $read( 'get_billing_country' ),
		);
		$out['address'] = array_filter( $address, 'strlen' );
		return $out;
	}

	// ── Outcomes, redirects, errors ─────────────────────────────────────────

	/**
	 * The application allowance per visitor and window.
	 *
	 * Both forms are public handlers (`admin-post` with a `nopriv` twin) that send one
	 * TackQuote request per submission. Until 1.10.0 nothing limited them, so a script
	 * could fill the seller's workspace with applications. Charged just before the
	 * outbound call, so a form refused for a missing answer costs nothing.
	 *
	 * @since 1.10.0
	 *
	 * @return int Zero or less disables the limit.
	 */
	private static function rate_limit_max() {
		/**
		 * Filters how many wholesale and net-terms applications one visitor may send per ten minutes.
		 *
		 * @since 1.10.0
		 *
		 * @param int $max Maximum applications. Zero or less disables the limit.
		 */
		return (int) apply_filters( 'tack_quotes_form_rate_limit_max', self::RATE_LIMIT_MAX );
	}

	/**
	 * Is this visitor over the application allowance?
	 *
	 * @return bool
	 */
	public static function rate_limited() {
		return Tack_Rate_Limit::exceeded( 'tack_qf_', 'application', self::rate_limit_max() );
	}

	/**
	 * Charge one application to this visitor.
	 */
	private static function count_application() {
		Tack_Rate_Limit::hit( 'tack_qf_', 'application', self::rate_limit_max(), self::RATE_LIMIT_WINDOW );
	}

	/**
	 * What a visitor over the allowance is told.
	 *
	 * @return string
	 */
	private static function rate_limit_message() {
		return __( 'Too many applications were sent in a short time. Please wait a few minutes and try again.', 'tackquote' );
	}

	/**
	 * Store the outcome under a one-time token and send the browser back.
	 *
	 * @param array $outcome From process_*_submission().
	 */
	private function respond( array $outcome ) {
		$token = strtolower( wp_generate_password( 20, false, false ) );
		set_transient( self::RESULT_PREFIX . $token, $outcome, self::RESULT_TTL );
		wp_safe_redirect( add_query_arg( 'tack_form', $token, $outcome['redirect'] ) );
		exit;
	}

	/**
	 * Read and delete the outcome named by `?tack_form=`, if it belongs to this form.
	 *
	 * @param string $form `wholesale` or `credit`.
	 * @return array|null
	 */
	private function consume_outcome( $form ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a one-time, unguessable display token; it performs no action.
		$token = isset( $_GET['tack_form'] ) ? sanitize_key( wp_unslash( $_GET['tack_form'] ) ) : '';
		if ( '' === $token ) {
			return null;
		}
		$outcome = get_transient( self::RESULT_PREFIX . $token );
		if ( ! is_array( $outcome ) || ! isset( $outcome['form'], $outcome['kind'], $outcome['message'] ) || $outcome['form'] !== $form ) {
			return null;
		}
		delete_transient( self::RESULT_PREFIX . $token );
		return $outcome;
	}

	/**
	 * An error outcome.
	 *
	 * @param string $message Friendly, already-translated text.
	 * @param array  $refill  What the shopper typed, to put back in the form.
	 * @return array{kind:string,message:string,values:array}
	 */
	private function failure( $message, array $refill = array() ) {
		return array(
			'kind'    => 'error',
			'message' => $message,
			'values'  => $refill,
		);
	}

	/**
	 * Plain text for a failed API call. Never the raw body.
	 *
	 * A TackQuote validation answer (its own JSON 400, e.g. "Company name is
	 * required") is shown escaped, because it is written for the shopper. Every
	 * other status maps to a sentence that says what to do next.
	 *
	 * @param WP_Error $error From Tack_Api_Client.
	 * @return string
	 */
	public function friendly_error( $error ) {
		$data   = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
		$own    = is_array( $data ) && ! empty( $data['json'] ) && isset( $data['statusCode'] ) && (int) $data['statusCode'] === $status;

		if ( 0 === strpos( (string) $error->get_error_code(), 'tack_file_' ) ) {
			return (string) $error->get_error_message();
		}
		if ( 429 === $status ) {
			return __( 'Too many applications were sent in a short time. Please wait a few minutes and try again.', 'tackquote' );
		}
		if ( 400 === $status && $own ) {
			$message = $this->plain( (string) $error->get_error_message(), 200 );
			if ( '' !== $message ) {
				return $message;
			}
		}
		if ( 401 === $status || 403 === $status ) {
			return __( 'The store\'s TackQuote connection refused this request. Please contact the store.', 'tackquote' );
		}
		if ( 404 === $status ) {
			return __( 'This application form is not available.', 'tackquote' );
		}
		return __( 'TackQuote could not be reached. Please try again in a few minutes.', 'tackquote' );
	}

	/**
	 * Did TackQuote answer that no wholesale form has this slug?
	 *
	 * `GET /integrations/woocommerce/wholesale-form` answers 404 "Form not found" for
	 * a slug the workspace has no form under.
	 * The route itself exists on every server this plugin version supports, so any
	 * 404 there is read as "no such form".
	 *
	 * @since 1.10.0
	 *
	 * @param WP_Error $error From Tack_Api_Client.
	 * @return bool
	 */
	public static function is_missing_form( $error ) {
		$data = $error->get_error_data();
		return is_array( $data ) && isset( $data['status'] ) && 404 === (int) $data['status'];
	}

	/**
	 * For an administrator only: which slug matched no form, and where to fix it.
	 * Empty for everyone else.
	 *
	 * @since 1.10.0
	 *
	 * @param string $slug The form slug that was asked for.
	 * @return string Markup (escaped here; kses-filtered by the caller).
	 */
	public function missing_form_hint( $slug ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return '';
		}
		$settings = class_exists( 'Tack_Settings' ) ? Tack_Settings::tab_url( 'forms' ) : admin_url( 'admin.php' );
		return '<p class="tackquote-admin-hint">'
			. esc_html(
				sprintf(
					/* translators: %s: the wholesale form slug set in the plugin settings. */
					__( 'Only administrators see this: no TackQuote wholesale form has the slug "%s". Create one in TackQuote under Settings, Wholesale forms, or choose another slug on the Forms tab.', 'tackquote' ),
					$slug
				)
			)
			. ' <a href="' . esc_url( $settings ) . '">' . esc_html__( 'Open the Forms tab', 'tackquote' ) . '</a></p>';
	}

	/**
	 * Log a slug that matches no form at most once a day per slug, so a busy page does
	 * not fill the log with the same line.
	 *
	 * @since 1.10.0
	 *
	 * @param string   $slug  The form slug.
	 * @param WP_Error $error The 404.
	 */
	private function log_missing_form( $slug, $error ) {
		$logged = get_transient( self::MISSING_FORM_LOGGED );
		$logged = is_array( $logged ) ? $logged : array();
		if ( isset( $logged[ $slug ] ) ) {
			return;
		}
		$this->log( 'wholesale-form slug "' . $slug . '" matches no TackQuote wholesale form (' . $this->error_summary( $error ) . '); set the slug on the Forms tab' );
		$logged[ $slug ] = time();
		set_transient( self::MISSING_FORM_LOGGED, $logged, DAY_IN_SECONDS );
	}

	/**
	 * What to log about a failure: status and TackQuote's own machine fields only.
	 * Never the key, never a shopper's answers.
	 *
	 * @param WP_Error $error The failure.
	 * @return string
	 */
	private function error_summary( $error ) {
		$data   = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
		$code   = is_array( $data ) && ! empty( $data['code'] ) ? (string) $data['code'] : (string) $error->get_error_code();
		$scopes = is_array( $data ) && ! empty( $data['requiredScopes'] ) && is_array( $data['requiredScopes'] ) ? ' requires ' . implode( ',', $data['requiredScopes'] ) : '';
		return 'HTTP ' . $status . ' ' . $code . $scopes;
	}

	/**
	 * Strip markup and cap length, for server text shown to a shopper.
	 *
	 * @param string $text Raw text.
	 * @param int    $max  Longest allowed.
	 * @return string
	 */
	private function plain( $text, $max ) {
		$text = trim( wp_strip_all_tags( (string) $text ) );
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $text, 0, $max );
		}
		return substr( $text, 0, $max );
	}

	/**
	 * The posted return URL, if it is on this site; otherwise My Account or home.
	 *
	 * @param string $url Posted URL.
	 * @return string
	 */
	private function safe_redirect_target( $url ) {
		$fallback = function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'myaccount' ) : home_url( '/' );
		if ( '' === $fallback ) {
			$fallback = home_url( '/' );
		}
		$url = esc_url_raw( $url );
		if ( '' === $url ) {
			return $fallback;
		}
		return (string) wp_validate_redirect( $url, $fallback );
	}

	/**
	 * URL of a My Account endpoint.
	 *
	 * @param string $endpoint Endpoint slug.
	 * @return string
	 */
	private function account_url( $endpoint ) {
		if ( function_exists( 'wc_get_account_endpoint_url' ) ) {
			return (string) wc_get_account_endpoint_url( $endpoint );
		}
		return home_url( '/' );
	}

	/**
	 * The page a shortcode is rendering on.
	 *
	 * @return string
	 */
	private function current_url() {
		$permalink = get_permalink();
		if ( is_string( $permalink ) && '' !== $permalink ) {
			return $permalink;
		}
		return home_url( '/' );
	}

	/**
	 * Render a form template, after `tackquote_storefront_form_args`.
	 *
	 * @since 1.10.0
	 *
	 * @param string $template Template under `tackquote/`.
	 * @param array  $args     Template variables.
	 * @param string $form     `wholesale` or `credit`.
	 * @return string Template output (the caller passes it through kses()).
	 */
	private function form_template( $template, array $args, $form ) {
		/**
		 * Filters what a storefront form template receives.
		 *
		 * @since 1.10.0
		 *
		 * @param array  $args Template variables; see the template's header.
		 * @param string $form `wholesale` (wholesale application) or `credit` (net terms).
		 */
		$args = array_merge( $args, (array) apply_filters( 'tackquote_storefront_form_args', $args, $form ) );
		return Tack_Templates::html( $template, $args );
	}

	/**
	 * The theme's button classes (see Tack_Widget::button_class()).
	 *
	 * @since 1.10.0
	 *
	 * @param string $extra Plugin classes to append.
	 * @return string
	 */
	private static function button_class( $extra ) {
		return class_exists( 'Tack_Widget' ) ? Tack_Widget::button_class( $extra ) : trim( 'button wp-element-button ' . $extra );
	}

	/**
	 * A WooCommerce-styled notice.
	 *
	 * @param string $kind    success, pending, error or info.
	 * @param string $message Text.
	 * @return string
	 */
	private function notice( $kind, $message ) {
		$classes = array(
			'success' => 'woocommerce-message',
			'pending' => 'woocommerce-info',
			'info'    => 'woocommerce-info',
			'error'   => 'woocommerce-error',
		);
		$class   = isset( $classes[ $kind ] ) ? $classes[ $kind ] : 'woocommerce-info';
		return '<div class="' . esc_attr( $class ) . ' tackquote-notice tackquote-notice-' . esc_attr( $kind ) . '" role="alert">' . esc_html( $message ) . '</div>';
	}

	/**
	 * Allow exactly the markup the forms are built from.
	 *
	 * `wp_kses_post()` strips form controls, so the forms carry their own list.
	 *
	 * @return array
	 */
	public static function allowed_html() {
		$common = array(
			'class' => true,
			'id'    => true,
		);
		return array(
			'div'      => $common + array(
				'role'                     => true,
				'data-tack-field'          => true,
				'data-tack-show-if-field'  => true,
				'data-tack-show-if-equals' => true,
			),
			'form'     => $common + array(
				'method'         => true,
				'action'         => true,
				'enctype'        => true,
				'data-tack-form' => true,
			),
			'fieldset' => $common + array(
				'data-tack-field'          => true,
				'data-tack-show-if-field'  => true,
				'data-tack-show-if-equals' => true,
			),
			'legend'   => $common,
			'p'        => $common + array(
				'data-tack-field'          => true,
				'data-tack-show-if-field'  => true,
				'data-tack-show-if-equals' => true,
			),
			'label'    => $common + array( 'for' => true ),
			'input'    => $common + array(
				'type'             => true,
				'name'             => true,
				'value'            => true,
				'required'         => true,
				'checked'          => true,
				'disabled'         => true,
				'readonly'         => true,
				'maxlength'        => true,
				'min'              => true,
				'max'              => true,
				'step'             => true,
				'autocomplete'     => true,
				'inputmode'        => true,
				'placeholder'      => true,
				'accept'           => true,
				'aria-describedby' => true,
			),
			'select'   => $common + array(
				'name'             => true,
				'required'         => true,
				'disabled'         => true,
				'autocomplete'     => true,
				'aria-describedby' => true,
			),
			'option'   => array(
				'value'    => true,
				'selected' => true,
			),
			'textarea' => $common + array(
				'name'             => true,
				'rows'             => true,
				'required'         => true,
				'maxlength'        => true,
				'aria-describedby' => true,
			),
			'button'   => $common + array(
				'type'     => true,
				'name'     => true,
				'value'    => true,
				'disabled' => true,
			),
			'span'     => $common + array( 'aria-hidden' => true ),
			'a'        => $common + array( 'href' => true ),
			'h2'       => $common,
			'h3'       => $common,
			'strong'   => array(),
			'em'       => array(),
			'br'       => array(),
		);
	}

	/**
	 * Filter the built markup through the allowlist above.
	 *
	 * @param string $html Markup.
	 * @return string
	 */
	private function kses( $html ) {
		return wp_kses( $html, self::allowed_html() );
	}

	/**
	 * The conditional-field script, only on pages that render a form.
	 */
	private function enqueue_assets() {
		if ( ! function_exists( 'wp_enqueue_script' ) ) {
			return;
		}
		wp_enqueue_script( 'tackquote-storefront-forms', TACK_QUOTES_URL . 'assets/js/tack-storefront-forms.js', array(), TACK_QUOTES_VERSION, true );
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
