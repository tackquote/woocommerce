<?php
/**
 * The seller-designed quote request form (1.12.0).
 *
 * A seller can design the "Request a quote" form in TackQuote (Settings > Quote
 * request form): the fields, their order, conditional fields, file fields and
 * "No dates in the past" dates on the seller's calendar. Every TackQuote
 * storefront renders it from one definition. This class reads that definition
 * with the store's key from `GET /storefront/v1/quote-form`, prints its fields
 * into a `<template>` the quote modal clones, and turns the posted answers back
 * into the shapes TackQuote validates.
 *
 * Used ONLY when all of these hold, else the modal keeps the form it always had
 * (name, email, phone, company details from the registration policy, note):
 *
 *   - the store holds an API key, and TackQuote's ping lists `quote-form` in its
 *     `capabilities` (an older server would refuse `answers` with a 400 over the
 *     whole request, `forbidNonWhitelisted`);
 *   - the definition has a `versionId`: null means the seller never designed a
 *     form and TackQuote answered its built-in default, which is today's form;
 *   - a REQUIRED file field can actually be answered here (the merchant allowed
 *     attachments and the server takes them). Otherwise the shopper could never
 *     send the form, so the fallback form is used and the reason is logged.
 *
 * The server stays the authority: it checks the answers against the CURRENT
 * version (a stale one is a 409, nothing written) with the one validator every
 * storefront shares. What is checked here only spares the shopper a round trip.
 *
 * @package TackQuotes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads, renders and answers the seller-designed quote request form.
 */
class Tack_Quote_Form {

	/** The ping capability that says `quote-requests` takes `answers`. */
	const CAPABILITY = 'quote-form';

	/** One transient for every locale: `{locale: {until, form?}}`. */
	const CACHE_TRANSIENT = 'tack_quotes_quote_form_cache';

	/** How long a definition is kept, in seconds. */
	const CACHE_TTL = 300;

	/** How long past CACHE_TTL a definition may still be served while one request refreshes it. */
	const STALE_TTL = 1800;

	/** Prefix of the short lock that elects the one refreshing request (+ md5 of the locale). */
	const REFRESH_LOCK = 'tack_quotes_qf_refresh_';

	/** Lifetime of that lock, in seconds. */
	const REFRESH_LOCK_TTL = 30;

	/** How long a failed read is remembered, in seconds. */
	const FAILURE_TTL = 60;

	/** The POST array the answers travel in. */
	const ANSWERS_FIELD = 'tq_answers';

	/** The POST array of each file field's upload ids. */
	const FILES_FIELD = 'tq_files';

	/** The POST key carrying the locale the form was shown in ). */
	const LOCALE_FIELD = 'tq_locale';

	/** The POST key carrying the version the shopper saw. */
	const VERSION_FIELD = 'tq_version';

	/** Element id prefix, distinct from the application forms' `tack_sf_`. */
	const ID_PREFIX = 'tack_qf_';

	/** Id of the `<template>` the modal clones. */
	const TEMPLATE_ID = 'tack-quote-seller-form';

	/** Set for a day once the required-file fallback was logged, so the log is not flooded. */
	const FILE_FALLBACK_LOGGED = 'tack_quotes_quote_form_file_fallback';

	/** The roles whose answers also travel as the request's buyer fields. */
	const BUYER_ROLES = array( 'buyer_name', 'buyer_email', 'buyer_company', 'buyer_phone', 'buyer_message' );

	/**
	 * API client.
	 *
	 * @var Tack_Api_Client
	 */
	private $client;

	/**
	 * Field renderer and answer collector (shared with the application forms).
	 *
	 * @var Tack_Storefront_Forms
	 */
	private $forms;

	/**
	 * Whether the shopper can attach files on this store (null: ask Tack_Attachments).
	 *
	 * @var bool|null
	 */
	private $uploads;

	/**
	 * The definition read this request, keyed by locale (false: none).
	 *
	 * @var array<string, array|false>
	 */
	private static $memo = array();

	/**
	 * Constructor.
	 *
	 * @param Tack_Api_Client|null       $client  Injected in tests.
	 * @param Tack_Storefront_Forms|null $forms   Injected in tests.
	 * @param bool|null                  $uploads Injected in tests: whether files can be attached.
	 */
	public function __construct( $client = null, $forms = null, $uploads = null ) {
		$this->client  = $client instanceof Tack_Api_Client ? $client : new Tack_Api_Client();
		$this->forms   = $forms instanceof Tack_Storefront_Forms ? $forms : new Tack_Storefront_Forms( $this->client );
		$this->uploads = null === $uploads ? null : (bool) $uploads;
	}

	/**
	 * Forget the per-request memo (tests).
	 */
	public static function reset_request_cache() {
		self::$memo = array();
	}

	/**
	 * Hooks: print the field template in the footer.
	 */
	public function init() {
		add_action( 'wp_footer', array( $this, 'render_template' ), 20 );
	}

	/**
	 * The visitor's locale as a BCP 47 tag (`de-DE`), the shape TackQuote's
	 * locale matching reads (`en`, `pt-BR`).
	 *
	 * @return string
	 */
	public static function locale_tag() {
		$locale = function_exists( 'determine_locale' ) ? (string) determine_locale() : ( function_exists( 'get_locale' ) ? (string) get_locale() : 'en_US' );
		return self::clean_locale( str_replace( '_', '-', $locale ) );
	}

	/**
	 * A BCP 47 tag as TackQuote accepts it (`en`, `pt-BR`), else `en`. Applied to the
	 * locale the browser posts back too (1.12.0), so it can only name a
	 * cache entry, never anything else.
	 *
	 * @param mixed $tag Candidate tag.
	 * @return string
	 */
	public static function clean_locale( $tag ) {
		$tag = is_string( $tag ) ? trim( $tag ) : '';
		return 1 === preg_match( '/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8}){0,2}$/', $tag ) ? $tag : 'en';
	}

	/**
	 * Can a shopper attach files here (merchant switch AND server capability)?
	 *
	 * @return bool
	 */
	private function uploads_available() {
		if ( null === $this->uploads ) {
			$this->uploads = class_exists( 'Tack_Attachments' ) && null !== ( new Tack_Attachments( $this->client ) )->script_config();
		}
		return $this->uploads;
	}

	/**
	 * The seller-designed form to render, or null for the built-in one.
	 *
	 * @param string|null $locale BCP 47 tag the form is wanted in; null for the visitor's.
	 * @return array|null `{versionId, fields, calendar?}`.
	 */
	public function definition( $locale = null ) {
		$locale = null === $locale ? self::locale_tag() : self::clean_locale( $locale );
		if ( array_key_exists( $locale, self::$memo ) ) {
			return false === self::$memo[ $locale ] ? null : self::$memo[ $locale ];
		}
		$form                  = $this->read( $locale );
		$usable                = $this->usable( $form );
		self::$memo[ $locale ] = $usable ? $form : false;
		return $usable ? $form : null;
	}

	/**
	 * Is this answer a seller-designed form the store can render and the shopper can send?
	 *
	 * @param mixed $form The quote-form answer.
	 * @return bool
	 */
	public function usable( $form ) {
		if ( ! is_array( $form ) || empty( $form['versionId'] ) || ! is_string( $form['versionId'] ) ) {
			return false;
		}
		if ( empty( $form['fields'] ) || ! is_array( $form['fields'] ) ) {
			return false;
		}
		$roles = self::roles( $form['fields'] );
		// TackQuote refuses a quote request form without both; a definition missing them is not one.
		if ( ! isset( $roles['buyer_email'], $roles['buyer_name'] ) ) {
			return false;
		}
		$required_files = 0;
		foreach ( $form['fields'] as $field ) {
			if ( is_array( $field ) && isset( $field['type'] ) && 'file' === $field['type'] && ! empty( $field['required'] ) ) {
				++$required_files;
			}
		}
		// Every required file field needs at least one file, and one request carries at
		// most Tack_Attachments::MAX_FILES: more required file fields than that can never
		// be sent.
		if ( $required_files > ( class_exists( 'Tack_Attachments' ) ? Tack_Attachments::MAX_FILES : 3 ) ) {
			self::log_once( 'The seller\'s quote request form requires more files than one quote request can carry: the built-in form is shown instead.' );
			return false;
		}
		foreach ( $form['fields'] as $field ) {
			if ( is_array( $field ) && isset( $field['type'] ) && 'file' === $field['type'] && ! empty( $field['required'] ) && ! $this->uploads_available() ) {
				self::log_once( 'The seller\'s quote request form has a required file field, but files cannot be attached on this store (Storefront > Attachments is off, or TackQuote does not take them): the built-in form is shown instead.' );
				return false;
			}
		}
		return true;
	}

	/**
	 * Read the definition through the cache.
	 *
	 * @param string $locale BCP 47 tag.
	 * @return array|null
	 */
	private function read( $locale ) {
		if ( '' === (string) get_option( 'tack_quotes_api_key', '' ) ) {
			return null;
		}
		$cache = get_transient( self::CACHE_TRANSIENT );
		$cache = is_array( $cache ) ? $cache : array();
		$entry = isset( $cache[ $locale ] ) && is_array( $cache[ $locale ] ) ? $cache[ $locale ] : array();
		$form  = isset( $entry['form'] ) && is_array( $entry['form'] ) ? $entry['form'] : null;
		if ( isset( $entry['until'] ) && (int) $entry['until'] > time() ) {
			return $form;
		}

		/*
		 * Soft TTL: past `until`, a definition still within STALE_TTL is served
		 * while ONE request refreshes it, the one that takes the short refresh lock;
		 * every other request in that moment keeps the stale copy instead of also
		 * calling TackQuote.
		 */
		if ( null !== $form && isset( $entry['stale'] ) && (int) $entry['stale'] > time() ) {
			$lock = self::REFRESH_LOCK . md5( $locale );
			if ( get_transient( $lock ) ) {
				return $form;
			}
			set_transient( $lock, 1, self::REFRESH_LOCK_TTL );
		}
		if ( ! in_array( self::CAPABILITY, $this->client->server_capabilities(), true ) ) {
			return null;
		}
		$result = $this->client->get_quote_form( $locale );
		if ( is_wp_error( $result ) || ! is_array( $result ) ) {
			// An outage keeps a stale copy rather than switching forms mid-session.
			$cache[ $locale ] = null !== $form && isset( $entry['stale'] ) && (int) $entry['stale'] > time()
				? array_merge( $entry, array( 'until' => time() + self::FAILURE_TTL ) )
				: array( 'until' => time() + self::FAILURE_TTL );
			set_transient( self::CACHE_TRANSIENT, $cache, HOUR_IN_SECONDS );
			return isset( $cache[ $locale ]['form'] ) ? $cache[ $locale ]['form'] : null;
		}
		$cache[ $locale ] = array(
			'until' => time() + self::CACHE_TTL,
			'stale' => time() + self::STALE_TTL,
			'form'  => $result,
		);
		set_transient( self::CACHE_TRANSIENT, $cache, HOUR_IN_SECONDS );
		return $result;
	}

	/**
	 * Drop every cached definition (TackQuote said the form changed).
	 */
	public static function forget() {
		delete_transient( self::CACHE_TRANSIENT );
		self::reset_request_cache();
	}

	/**
	 * Role => field key, for the fields that carry one.
	 *
	 * @param array $fields Field definitions.
	 * @return array<string, string>
	 */
	public static function roles( array $fields ) {
		$out = array();
		foreach ( $fields as $field ) {
			if ( is_array( $field ) && ! empty( $field['role'] ) && ! empty( $field['key'] ) && is_string( $field['role'] ) ) {
				$out[ $field['role'] ] = (string) $field['key'];
			}
		}
		return $out;
	}

	/**
	 * What the storefront script needs to know, or null when the built-in form is used.
	 *
	 * @return array{versionId:string,templateId:string,roles:array<string,string>}|null
	 */
	public function script_config() {
		$form = Tack_Widget::quote_controls_possible() ? $this->definition() : null;
		if ( null === $form ) {
			return null;
		}
		return array(
			'versionId'  => (string) $form['versionId'],
			// Posted back with the answers: the answers are collected against
			// the form in the language the shopper saw, whatever the AJAX request's own.
			'locale'     => self::locale_tag(),
			'templateId' => self::TEMPLATE_ID,
			'roles'      => (object) self::roles( $form['fields'] ),
			'maxFiles'   => class_exists( 'Tack_Attachments' ) ? Tack_Attachments::MAX_FILES : 3,
		);
	}

	/**
	 * The form's fields as HTML, in WooCommerce's form-row markup.
	 *
	 * @param array $form The definition.
	 * @return string
	 */
	public function fields_html( array $form ) {
		$calendar = Tack_Storefront_Forms::seller_calendar( $form );
		$mode     = $this->uploads_available() ? 'upload' : 'unsupported';
		$html     = '';
		foreach ( $form['fields'] as $field ) {
			if ( ! is_array( $field ) || empty( $field['key'] ) || empty( $field['type'] ) ) {
				continue;
			}
			$html .= $this->forms->render_field( $field, self::ANSWERS_FIELD, null, $mode, $calendar, self::ID_PREFIX, self::FILES_FIELD );
		}
		return $html;
	}

	/**
	 * `wp_footer`: the template the quote modal clones. Nothing for the built-in form.
	 */
	public function render_template() {
		if ( is_admin() ) {
			return;
		}
		$form = Tack_Widget::quote_controls_possible() ? $this->definition() : null;
		if ( null === $form ) {
			return;
		}
		echo '<template id="' . esc_attr( self::TEMPLATE_ID ) . '" data-tack-version="' . esc_attr( (string) $form['versionId'] ) . '">';
		echo $this->fields_html( $form ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped per attribute by Tack_Storefront_Forms::render_field().
		echo '</template>';
	}

	/**
	 * Turn the posted answers into what the quote request sends.
	 *
	 * @param array  $raw_answers   Posted `tq_answers`, unslashed and sanitised per leaf.
	 * @param array  $raw_files     Posted `tq_files`: field key => upload ids.
	 * @param string $version       Posted `tq_version`.
	 * @param array  $uploaded      Upload ids this request carries (`upload_ids`); a file answer must be one of them.
	 * @param string $locale        Posted `tq_locale`: the language the form was shown in (null: the request's).
	 * @return array|WP_Error `{answers, formVersionId, buyer: {email, firstName, lastName, company, phone, message}}`.
	 */
	public function collect( array $raw_answers, array $raw_files, $version, array $uploaded, $locale = null ) {
		$form = $this->definition( $locale );
		if ( null === $form ) {
			return new WP_Error( 'tack_form_gone', __( 'This form has just been updated. Please check your answers and send it again.', 'tackquote' ), array( 'reload' => true ) );
		}
		if ( (string) $version !== (string) $form['versionId'] ) {
			self::forget();
			return new WP_Error( 'tack_form_changed', __( 'This form has just been updated. Please check your answers and send it again.', 'tackquote' ), array( 'reload' => true ) );
		}

		$fields    = $form['fields'];
		$collected = $this->forms->collect_answers( $fields, $raw_answers );
		if ( null !== $collected['error'] ) {
			return new WP_Error( 'tack_form_invalid', $collected['error'] );
		}
		$answers = $collected['values'];

		$by_key = array();
		foreach ( $fields as $field ) {
			if ( is_array( $field ) && ! empty( $field['key'] ) ) {
				$by_key[ (string) $field['key'] ] = $field;
			}
		}
		$uploaded = array_values( array_filter( array_map( 'strval', $uploaded ), array( 'Tack_Attachments', 'is_upload_id' ) ) );
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) || ! isset( $field['type'], $field['key'] ) || 'file' !== $field['type'] ) {
				continue;
			}
			$key = (string) $field['key'];
			if ( ! $this->forms->is_shown( $field, $by_key, $answers ) ) {
				continue;
			}
			$max = isset( $field['maxFiles'] ) && is_numeric( $field['maxFiles'] ) ? max( 1, min( Tack_Attachments::MAX_FILES, (int) $field['maxFiles'] ) ) : 1;
			$ids = array();
			foreach ( isset( $raw_files[ $key ] ) ? (array) $raw_files[ $key ] : array() as $id ) {
				$id = strtolower( trim( (string) $id ) );
				// Only an id this request uploaded: never one typed into the form.
				if ( in_array( $id, $uploaded, true ) && ! in_array( $id, $ids, true ) ) {
					$ids[] = $id;
				}
			}
			$label = isset( $field['label'] ) ? (string) $field['label'] : $key;
			if ( count( $ids ) > $max ) {
				/* translators: %d: how many files the field takes. */
				return new WP_Error( 'tack_form_invalid', sprintf( __( 'Attach at most %d files.', 'tackquote' ), $max ) );
			}
			if ( empty( $ids ) ) {
				if ( ! empty( $field['required'] ) ) {
					/* translators: %s: the field's label. */
					return new WP_Error( 'tack_form_invalid', sprintf( __( '%s is required.', 'tackquote' ), $label ) );
				}
				continue;
			}
			// A one-file field keeps TackQuote's `{uploadId}` shape; a multi-file one an array.
			$answers[ $key ] = $max > 1
				? array_map(
					function ( $id ) {
						return array( 'uploadId' => $id );
					},
					$ids
				)
				: array( 'uploadId' => $ids[0] );
		}

		$roles = self::roles( $fields );
		$text  = function ( $role ) use ( $roles, $answers ) {
			if ( ! isset( $roles[ $role ] ) || ! isset( $answers[ $roles[ $role ] ] ) || ! is_string( $answers[ $roles[ $role ] ] ) ) {
				return '';
			}
			return trim( $answers[ $roles[ $role ] ] );
		};
		$name  = $text( 'buyer_name' );
		$parts = '' === $name ? array() : preg_split( '/\s+/', $name );
		$first = isset( $parts[0] ) ? (string) $parts[0] : '';
		$last  = count( $parts ) > 1 ? implode( ' ', array_slice( $parts, 1 ) ) : '';

		return array(
			'answers'       => $answers,
			'formVersionId' => (string) $form['versionId'],
			'buyer'         => array(
				'email'     => $text( 'buyer_email' ),
				'firstName' => $first,
				'lastName'  => $last,
				'company'   => $text( 'buyer_company' ),
				'phone'     => $text( 'buyer_phone' ),
				'message'   => $text( 'buyer_message' ),
			),
		);
	}

	/**
	 * Did TackQuote refuse the request because the form changed (409 FORM_CHANGED)?
	 *
	 * @param WP_Error $error From Tack_Api_Client.
	 * @return bool
	 */
	public static function refused_as_changed( $error ) {
		$data = $error->get_error_data();
		// The quote-requests route answers 409 for exactly one reason: FORM_CHANGED.
		return is_array( $data ) && isset( $data['status'] ) && 409 === (int) $data['status'];
	}

	/**
	 * Log a fallback reason at most once a day.
	 *
	 * @param string $message Message.
	 */
	private static function log_once( $message ) {
		if ( get_transient( self::FILE_FALLBACK_LOGGED ) ) {
			return;
		}
		set_transient( self::FILE_FALLBACK_LOGGED, 1, DAY_IN_SECONDS );
		self::log( $message );
	}

	/**
	 * Write to WooCommerce's log under the plugin's own source.
	 *
	 * @param string $message Message.
	 */
	private static function log( $message ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		$logger = wc_get_logger();
		if ( $logger ) {
			$logger->warning( $message, array( 'source' => 'tackquote' ) );
		}
	}
}
