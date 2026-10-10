<?php
/**
 * Attachments: files a shopper adds to a quote request or a wholesale application.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * HOW A FILE TRAVELS (TackQuote upload-then-claim, tack docs/integrations/WOOCOMMERCE.md)
 * ─────────────────────────────────────────────────────────────────────────────
 * 1. The browser posts the files to this store (admin-ajax `tack_quote_upload`,
 *    nonce-checked, logged-in and guest variants).
 * 2. PHP validates every file BEFORE any outbound call: at most three, 5 MB each,
 *    a PDF, JPEG or PNG by extension (`wp_check_filetype_and_ext()`) AND by its
 *    leading bytes (finfo when available, plus the same magic-byte rule TackQuote
 *    applies), and nothing that is not a real HTTP upload.
 * 3. Each file's bytes are sent server to server to TackQuote
 *    (`POST /storefront/v1/quote-upload`, `X-Api-Key` only, raw
 *    `application/octet-stream`), and the PHP temp file is deleted. Nothing is
 *    written to wp-content/uploads or anywhere else in WordPress.
 * 4. TackQuote answers an opaque upload id (and, for a guest, an upload token);
 *    the browser sends those with the quote request, which claims the files.
 *
 * A signed-in customer is asserted by their (trusted) account email and WordPress
 * user id; a guest by the token TackQuote issues on the first upload. Fields are
 * only ever sent to a server whose ping advertises `attachments`, because an older
 * server refuses the whole quote request over one unknown field.
 *
 * Logged: opaque upload ids and HTTP statuses only. Never a file name, never the
 * bytes, never an email address.
 *
 * @package TackQuotes
 * @since   1.10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Validates shopper files and streams them to TackQuote.
 */
class Tack_Attachments {

	/** Option: "Allow attachments on quote requests" (Storefront tab, default off). */
	const OPTION_ENABLED = 'tack_quotes_enable_attachments';

	/** The admin-ajax action for quote-request uploads. */
	const AJAX_ACTION = 'tack_quote_upload';

	/** Files per quote request (TackQuote MAX_FILES_PER_QUOTE_REQUEST). */
	const MAX_FILES = 3;

	/** Megabytes per file (TackQuote MAX_QUOTE_REQUEST_FILE_MB / MAX_APPLICATION_FILE_MB). */
	const MAX_MB = 5;

	/** Upload requests allowed per visitor per window (this store's own flood guard). */
	const RATE_LIMIT_MAX = 20;

	/** The rate-limit window, in seconds. */
	const RATE_LIMIT_WINDOW = 600;

	/** Longest file name sent to TackQuote. */
	const MAX_NAME_LENGTH = 200;

	/**
	 * Accepted types: extension => MIME. TackQuote accepts exactly these three kinds.
	 */
	const MIME_TYPES = array(
		'pdf'  => 'application/pdf',
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
	);

	/**
	 * API client.
	 *
	 * @var Tack_Api_Client
	 */
	private $client;

	/**
	 * Constructor.
	 *
	 * @param Tack_Api_Client|null $client Injected in tests; built here otherwise.
	 */
	public function __construct( $client = null ) {
		$this->client = $client instanceof Tack_Api_Client ? $client : new Tack_Api_Client();
	}

	/**
	 * Register the upload handler (logged-in and guest).
	 */
	public function init() {
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'handle_upload' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( $this, 'handle_upload' ) );
	}

	/**
	 * Is "Allow attachments on quote requests" switched on?
	 *
	 * @return bool
	 */
	public static function setting_on() {
		return 'yes' === get_option( self::OPTION_ENABLED, 'no' );
	}

	/**
	 * Are quote-request attachments offered: the merchant's switch AND the server's
	 * advertised `attachments` capability.
	 *
	 * The switch is read first so a store that has not opted in never pings for it.
	 *
	 * @return bool
	 */
	public function quote_attachments_enabled() {
		return self::setting_on() && $this->client->supports_attachments();
	}

	/**
	 * The storefront script's attachment settings, or null when the control must not render.
	 *
	 * @return array|null
	 */
	public function script_config() {
		if ( ! $this->quote_attachments_enabled() ) {
			return null;
		}
		return array(
			'action'   => self::AJAX_ACTION,
			'maxFiles' => self::MAX_FILES,
			'maxMb'    => self::MAX_MB,
			'accept'   => '.pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png',
		);
	}

	/**
	 * `$_FILES['x']` (single or `x[]` multiple) as a flat list of files.
	 *
	 * @param mixed $entry One `$_FILES` entry.
	 * @return array<int, array{name:string,tmp_name:string,size:int,error:int}>
	 */
	public static function normalize_files( $entry ) {
		if ( ! is_array( $entry ) || ! isset( $entry['tmp_name'] ) ) {
			return array();
		}
		$keys = array( 'name', 'tmp_name', 'size', 'error' );
		if ( ! is_array( $entry['tmp_name'] ) ) {
			$entry = array_map(
				function ( $value ) {
					return array( $value );
				},
				array_intersect_key( $entry, array_flip( $keys ) )
			);
		}
		$files = array();
		foreach ( (array) $entry['tmp_name'] as $i => $tmp ) {
			$error = isset( $entry['error'][ $i ] ) ? (int) $entry['error'][ $i ] : UPLOAD_ERR_NO_FILE;
			if ( UPLOAD_ERR_NO_FILE === $error ) {
				continue; // An empty file input, not a file.
			}
			$files[] = array(
				'name'     => isset( $entry['name'][ $i ] ) ? (string) $entry['name'][ $i ] : '',
				'tmp_name' => is_string( $tmp ) ? $tmp : '',
				'size'     => isset( $entry['size'][ $i ] ) ? (int) $entry['size'][ $i ] : 0,
				'error'    => $error,
			);
		}
		return $files;
	}

	/**
	 * Check one uploaded file. No outbound call happens before every file passed.
	 *
	 * @param array    $file          One entry from normalize_files().
	 * @param string[] $allowed_mimes MIME types this field accepts (a subset of MIME_TYPES).
	 * @param int      $max_mb        Size cap in megabytes.
	 * @return array{path:string,name:string,mime:string,size:int}|WP_Error
	 */
	public function validate_file( array $file, array $allowed_mimes, $max_mb ) {
		$name    = $this->safe_name( isset( $file['name'] ) ? (string) $file['name'] : '' );
		$path    = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
		$max     = max( 1, min( self::MAX_MB, (int) $max_mb ) );
		$display = '' !== $name ? $name : __( 'file', 'tackquote' );

		if ( ! isset( $file['error'] ) || UPLOAD_ERR_OK !== (int) $file['error'] ) {
			$too_big = isset( $file['error'] ) && in_array( (int) $file['error'], array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true );
			return $too_big
				/* translators: 1: file name, 2: size limit in megabytes. */
				? new WP_Error( 'tack_file_size', sprintf( __( '%1$s: files can be at most %2$d MB.', 'tackquote' ), $display, $max ) )
				: new WP_Error( 'tack_file_failed', __( 'That file could not be attached. Please try again.', 'tackquote' ) );
		}
		if ( '' === $path || ! $this->is_uploaded( $path ) ) {
			return new WP_Error( 'tack_file_failed', __( 'That file could not be attached. Please try again.', 'tackquote' ) );
		}

		// The size the browser declared AND the size on disk: either over the cap refuses.
		$size = (int) filesize( $path );
		if ( $size <= 0 || $size > $max * MB_IN_BYTES || ( isset( $file['size'] ) && (int) $file['size'] > $max * MB_IN_BYTES ) ) {
			return $size <= 0
				? new WP_Error( 'tack_file_failed', __( 'That file could not be attached. Please try again.', 'tackquote' ) )
				/* translators: 1: file name, 2: size limit in megabytes. */
				: new WP_Error( 'tack_file_size', sprintf( __( '%1$s: files can be at most %2$d MB.', 'tackquote' ), $display, $max ) );
		}

		/* translators: %s: file name. */
		$type_error = new WP_Error( 'tack_file_type', sprintf( __( '%s: attach a PDF, JPEG or PNG file.', 'tackquote' ), $display ) );

		// The extension, checked by WordPress against the bytes it can inspect.
		$checked = wp_check_filetype_and_ext( $path, $name, self::MIME_TYPES );
		$by_ext  = is_array( $checked ) && ! empty( $checked['type'] ) ? (string) $checked['type'] : '';
		if ( '' === $by_ext || ! in_array( $by_ext, self::MIME_TYPES, true ) ) {
			return $type_error;
		}

		// The leading bytes decide, and must agree with the extension.
		$sniffed = $this->sniff_mime( $path );
		if ( null === $sniffed || $sniffed !== $by_ext || ! in_array( $sniffed, $allowed_mimes, true ) ) {
			return $type_error;
		}

		return array(
			'path' => $path,
			'name' => $name,
			'mime' => $sniffed,
			'size' => $size,
		);
	}

	/**
	 * PDF, JPEG or PNG from the file's leading bytes, or null.
	 *
	 * The same rule TackQuote applies (`UploadsService.sniffApplicationMime`):
	 * `%PDF-`, `FF D8 FF`, or the 8-byte PNG signature, in a file of at least 8
	 * bytes. finfo, when PHP has it, must agree as well.
	 *
	 * @param string $path File path.
	 * @return string|null
	 */
	public function sniff_mime( $path ) {
		$head = $this->read_head( $path, 8 );
		if ( strlen( $head ) < 8 ) {
			return null;
		}
		if ( 0 === strpos( $head, '%PDF-' ) ) {
			$magic = 'application/pdf';
		} elseif ( "\xFF\xD8\xFF" === substr( $head, 0, 3 ) ) {
			$magic = 'image/jpeg';
		} elseif ( "\x89PNG\r\n\x1a\n" === $head ) {
			$magic = 'image/png';
		} else {
			return null;
		}
		if ( function_exists( 'finfo_open' ) ) {
			$finfo = finfo_open( FILEINFO_MIME_TYPE );
			if ( $finfo ) {
				$by_finfo = finfo_file( $finfo, $path );
				finfo_close( $finfo );
				if ( is_string( $by_finfo ) && '' !== $by_finfo && $by_finfo !== $magic ) {
					return null;
				}
			}
		}
		return $magic;
	}

	/**
	 * Is this a file PHP received in THIS request's upload?
	 *
	 * @param string $path File path.
	 * @return bool
	 */
	protected function is_uploaded( $path ) {
		return is_uploaded_file( $path );
	}

	/**
	 * The first bytes of a file.
	 *
	 * @param string $path  File path.
	 * @param int    $count How many bytes.
	 * @return string
	 */
	protected function read_head( $path, $count ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local PHP upload temp file, never a URL.
		$head = file_get_contents( $path, false, null, 0, (int) $count );
		return is_string( $head ) ? $head : '';
	}

	/**
	 * The whole file.
	 *
	 * @param string $path File path.
	 * @return string|false
	 */
	protected function read_bytes( $path ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local PHP upload temp file (at most 5 MB), never a URL.
		return file_get_contents( $path );
	}

	/**
	 * Delete PHP's temp copies of the posted files. Nothing else is ever on disk.
	 *
	 * @param array $files Entries from normalize_files().
	 */
	public function discard( array $files ) {
		foreach ( $files as $file ) {
			$path = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
			if ( '' !== $path && $this->is_uploaded( $path ) && file_exists( $path ) ) {
				wp_delete_file( $path );
			}
		}
	}

	/**
	 * A file name safe to send: WordPress' own sanitiser, capped.
	 *
	 * @param string $name As the browser sent it.
	 * @return string
	 */
	private function safe_name( $name ) {
		$name = sanitize_file_name( wp_basename( (string) $name ) );
		if ( strlen( $name ) > self::MAX_NAME_LENGTH ) {
			$ext  = pathinfo( $name, PATHINFO_EXTENSION );
			$name = substr( $name, 0, self::MAX_NAME_LENGTH - strlen( $ext ) - 1 ) . '.' . $ext;
		}
		return $name;
	}

	/**
	 * Send each validated file's bytes to TackQuote through `$send`, deleting the
	 * temp file after each one.
	 *
	 * @param array    $valid Validated files (validate_file() answers).
	 * @param callable $send  function( string $bytes, array $file ): array|WP_Error.
	 * @return array<int,array>|WP_Error TackQuote's answers, in order.
	 */
	public function stream( array $valid, $send ) {
		$answers = array();
		foreach ( $valid as $i => $file ) {
			$bytes = $this->read_bytes( $file['path'] );
			if ( false === $bytes || strlen( $bytes ) !== (int) $file['size'] ) {
				$this->discard_paths( array_slice( $valid, $i ) );
				return new WP_Error( 'tack_file_failed', __( 'That file could not be attached. Please try again.', 'tackquote' ) );
			}
			$answer = call_user_func( $send, $bytes, $file );
			unset( $bytes );
			$this->discard_paths( array( $file ) );
			if ( is_wp_error( $answer ) ) {
				$this->discard_paths( array_slice( $valid, $i + 1 ) );
				return $answer;
			}
			$id = is_array( $answer ) && isset( $answer['uploadId'] ) && is_string( $answer['uploadId'] ) ? strtolower( $answer['uploadId'] ) : '';
			if ( ! self::is_upload_id( $id ) ) {
				$this->discard_paths( array_slice( $valid, $i + 1 ) );
				return new WP_Error( 'tack_file_failed', __( 'That file could not be attached. Please try again.', 'tackquote' ), array( 'status' => 0 ) );
			}
			$answer['uploadId'] = $id;
			$answers[]          = $answer;
		}
		return $answers;
	}

	/**
	 * Delete validated files' temp copies.
	 *
	 * @param array $valid Validated files.
	 */
	private function discard_paths( array $valid ) {
		$this->discard(
			array_map(
				function ( $file ) {
					return array( 'tmp_name' => isset( $file['path'] ) ? $file['path'] : '' );
				},
				$valid
			)
		);
	}

	/**
	 * A TackQuote upload id: a v4 UUID, lower case.
	 *
	 * @param mixed $id Candidate.
	 * @return bool
	 */
	public static function is_upload_id( $id ) {
		return is_string( $id ) && 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id );
	}

	/**
	 * A guest upload token as TackQuote issues it (opaque, URL-safe, bounded).
	 *
	 * @param mixed $token Candidate.
	 * @return bool
	 */
	public static function is_upload_token( $token ) {
		return is_string( $token ) && 1 === preg_match( '/^[A-Za-z0-9_.~-]{16,256}$/', $token );
	}

	/**
	 * Shopper-facing text for a failed upload call. Never the raw body.
	 *
	 * @param WP_Error $error From Tack_Api_Client.
	 * @return string
	 */
	public static function friendly_error( $error ) {
		$data   = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
		$own    = is_array( $data ) && ! empty( $data['json'] ) && isset( $data['statusCode'] ) && (int) $data['statusCode'] === $status;
		if ( 0 === strpos( (string) $error->get_error_code(), 'tack_file_' ) ) {
			return (string) $error->get_error_message();
		}
		if ( 429 === $status ) {
			return __( 'Too many files were sent in a short time. Please try again shortly.', 'tackquote' );
		}
		if ( 413 === $status ) {
			/* translators: 1: file name, 2: size limit in megabytes. */
			return sprintf( __( '%1$s: files can be at most %2$d MB.', 'tackquote' ), __( 'file', 'tackquote' ), self::MAX_MB );
		}
		if ( 400 === $status && $own ) {
			$message = trim( wp_strip_all_tags( (string) $error->get_error_message() ) );
			if ( '' !== $message ) {
				return function_exists( 'mb_substr' ) ? mb_substr( $message, 0, 200 ) : substr( $message, 0, 200 );
			}
		}
		if ( 401 === $status ) {
			return __( 'Sign in to your account to attach files.', 'tackquote' );
		}
		if ( 403 === $status ) {
			return __( 'Files cannot be attached on this store.', 'tackquote' );
		}
		return __( 'That file could not be attached. Please try again.', 'tackquote' );
	}

	/**
	 * Seconds TackQuote asked us to wait, from a 429, or 0.
	 *
	 * @param WP_Error $error The failure.
	 * @return int
	 */
	public static function retry_after( $error ) {
		$data = $error->get_error_data();
		if ( ! is_array( $data ) ) {
			return 0;
		}
		if ( ! empty( $data['retryAfterSeconds'] ) ) {
			return max( 0, (int) $data['retryAfterSeconds'] );
		}
		return isset( $data['retryAfterHeader'] ) && is_numeric( $data['retryAfterHeader'] ) ? max( 0, (int) $data['retryAfterHeader'] ) : 0;
	}

	/**
	 * AJAX: validate the posted quote files, stream them to TackQuote, answer the ids.
	 */
	public function handle_upload() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified first thing in process_upload().
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified in process_upload(); every entry is validated there by path, size and content.
		$files  = isset( $_FILES['files'] ) ? self::normalize_files( $_FILES['files'] ) : array();
		$answer = $this->process_upload( $nonce, $files );
		if ( $answer['ok'] ) {
			wp_send_json_success( $answer['data'] );
		}
		wp_send_json_error( $answer['data'], $answer['status'] );
	}

	/**
	 * The upload, without the JSON response. Pure apart from the API calls.
	 *
	 * @param string $nonce The posted `tack_request_quote` nonce.
	 * @param array  $files Entries from normalize_files().
	 * @return array{ok:bool,status:int,data:array}
	 */
	public function process_upload( $nonce, array $files ) {
		$fail = function ( $status, $code, $message, array $extra = array() ) use ( $files ) {
			$this->discard( $files );
			return array(
				'ok'     => false,
				'status' => $status,
				'data'   => array(
					'code'    => $code,
					'message' => $message,
				) + $extra,
			);
		};

		// Same nonce as the quote request it belongs to, so the cached-page refresh
		// (`tack_quote_nonce`) covers both calls.
		if ( ! wp_verify_nonce( $nonce, 'tack_request_quote' ) ) {
			return $fail(
				403,
				'tack_nonce_expired',
				__( 'This page has been open too long, or was served from a cache. Reload it and request the quote again.', 'tackquote' ),
				array( 'reload' => true )
			);
		}
		if ( ! $this->quote_attachments_enabled() ) {
			return $fail( 403, 'tack_attachments_off', __( 'Files cannot be attached on this store.', 'tackquote' ) );
		}
		if ( empty( $files ) ) {
			return $fail( 400, 'tack_no_files', __( 'That file could not be attached. Please try again.', 'tackquote' ) );
		}
		if ( count( $files ) > self::MAX_FILES ) {
			/* translators: %d: the most files one request may carry. */
			return $fail( 400, 'tack_too_many_files', sprintf( __( 'Attach at most %d files.', 'tackquote' ), self::MAX_FILES ) );
		}

		$valid = array();
		foreach ( $files as $file ) {
			$checked = $this->validate_file( $file, array_values( array_unique( self::MIME_TYPES ) ), self::MAX_MB );
			if ( is_wp_error( $checked ) ) {
				return $fail( 400, $checked->get_error_code(), $checked->get_error_message() );
			}
			$valid[] = $checked;
		}

		if ( $this->rate_limited() ) {
			return $fail( 429, 'tack_rate_limited', __( 'Too many files were sent in a short time. Please try again shortly.', 'tackquote' ) );
		}
		$this->count_request();

		// Signed in with an email TackQuote may trust: asserted. Otherwise a guest,
		// identified by the token the first upload answers.
		$email  = class_exists( 'Tack_Storefront_Forms' ) ? Tack_Storefront_Forms::trusted_account_email() : '';
		$token  = '';
		$client = $this->client;
		$sent   = $this->stream(
			$valid,
			function ( $bytes, $file ) use ( $client, $email, &$token ) {
				$answer = $client->upload_quote_file( $bytes, $file['name'], $email, $token );
				if ( '' === $email && '' === $token && is_array( $answer ) && isset( $answer['uploadToken'] ) && self::is_upload_token( $answer['uploadToken'] ) ) {
					$token = $answer['uploadToken'];
				}
				return $answer;
			}
		);

		if ( is_wp_error( $sent ) ) {
			$this->log( 'quote upload failed: ' . self::error_summary( $sent ) );
			$status = self::error_status( $sent );
			$extra  = 429 === $status ? array( 'retryAfter' => self::retry_after( $sent ) ) : array();
			return $fail( 429 === $status ? 429 : 502, 429 === $status ? 'tack_rate_limited' : 'tack_upload_failed', self::friendly_error( $sent ), $extra );
		}
		if ( '' === $email && '' === $token ) {
			// A guest upload must come back with a token, or the request could never claim it.
			$this->log( 'quote upload answered no guest token' );
			return $fail( 502, 'tack_upload_failed', __( 'That file could not be attached. Please try again.', 'tackquote' ) );
		}

		$ids = wp_list_pluck( $sent, 'uploadId' );
		$this->log( 'quote upload accepted: ' . implode( ',', $ids ), 'info' );

		$data = array(
			'uploadIds' => array_values( $ids ),
			'files'     => array_map(
				function ( $answer ) {
					return array(
						'uploadId' => $answer['uploadId'],
						'size'     => isset( $answer['size'] ) ? (int) $answer['size'] : 0,
						'mimeType' => isset( $answer['mimeType'] ) && is_string( $answer['mimeType'] ) ? sanitize_mime_type( $answer['mimeType'] ) : '',
					);
				},
				$sent
			),
		);
		if ( '' !== $token ) {
			$data['uploadToken'] = $token;
		}
		return array(
			'ok'     => true,
			'status' => 200,
			'data'   => $data,
		);
	}

	/**
	 * The upload ids and guest token a quote request may forward, or a WP_Error
	 * saying why not. Nothing is sent to a server that did not advertise attachments.
	 *
	 * @param mixed  $raw_ids     Posted `upload_ids` (array or comma list).
	 * @param mixed  $raw_token   Posted `upload_token`.
	 * @param string $typed_email The email the request carries.
	 * @return array{uploadIds?:string[],uploadToken?:string}|WP_Error Empty when nothing was attached.
	 */
	public function quote_request_fields( $raw_ids, $raw_token, $typed_email ) {
		$ids = is_array( $raw_ids ) ? $raw_ids : ( is_string( $raw_ids ) && '' !== $raw_ids ? explode( ',', $raw_ids ) : array() );
		$ids = array_values( array_unique( array_map( 'strtolower', array_map( 'trim', array_filter( $ids, 'is_string' ) ) ) ) );
		$ids = array_values( array_filter( $ids, 'strlen' ) );
		if ( empty( $ids ) ) {
			return array();
		}
		if ( ! $this->quote_attachments_enabled() ) {
			return new WP_Error( 'tack_attachments_off', __( 'Files cannot be attached on this store.', 'tackquote' ) );
		}
		if ( count( $ids ) > self::MAX_FILES ) {
			/* translators: %d: the most files one request may carry. */
			return new WP_Error( 'tack_too_many_files', sprintf( __( 'Attach at most %d files.', 'tackquote' ), self::MAX_FILES ) );
		}
		foreach ( $ids as $id ) {
			if ( ! self::is_upload_id( $id ) ) {
				return new WP_Error( 'tack_file_failed', __( 'That file could not be attached. Please try again.', 'tackquote' ) );
			}
		}

		$token = is_string( $raw_token ) ? trim( $raw_token ) : '';
		if ( '' !== $token ) {
			if ( ! self::is_upload_token( $token ) ) {
				return new WP_Error( 'tack_file_failed', __( 'That file could not be attached. Please try again.', 'tackquote' ) );
			}
			return array(
				'uploadIds'   => $ids,
				'uploadToken' => $token,
			);
		}

		// No token: the files were asserted to the signed-in account's email, and
		// TackQuote matches them on the request's buyerEmail. A different typed
		// address could never claim them; say so before sending anything.
		$account = class_exists( 'Tack_Storefront_Forms' ) ? Tack_Storefront_Forms::trusted_account_email() : '';
		if ( '' === $account ) {
			return new WP_Error( 'tack_file_failed', __( 'That file could not be attached. Please try again.', 'tackquote' ) );
		}
		if ( strtolower( trim( $account ) ) !== strtolower( trim( (string) $typed_email ) ) ) {
			return new WP_Error(
				'tack_file_email',
				/* translators: %s: the signed-in customer's account email address. */
				sprintf( __( 'Files you attach are linked to your account email, %s. Use that email address for this request, or remove the files.', 'tackquote' ), $account )
			);
		}
		return array( 'uploadIds' => $ids );
	}

	/**
	 * Is this visitor over the upload allowance?
	 *
	 * @return bool
	 */
	private function rate_limited() {
		return Tack_Rate_Limit::exceeded( 'tack_qu_', 'quote-upload', $this->rate_limit_max() );
	}

	/**
	 * Count one upload request against this visitor's allowance.
	 */
	private function count_request() {
		Tack_Rate_Limit::hit( 'tack_qu_', 'quote-upload', $this->rate_limit_max(), self::RATE_LIMIT_WINDOW );
	}

	/**
	 * The configured allowance.
	 *
	 * @return int
	 */
	private function rate_limit_max() {
		/**
		 * Filter the number of attachment upload requests allowed per visitor per ten minutes.
		 *
		 * @since 1.10.0
		 *
		 * @param int $max Maximum upload requests. Zero or less disables the limit.
		 */
		return (int) apply_filters( 'tack_quotes_upload_rate_limit_max', self::RATE_LIMIT_MAX );
	}

	/**
	 * HTTP status carried by a WP_Error from the API client, or 0.
	 *
	 * @param WP_Error $error The failure.
	 * @return int
	 */
	public static function error_status( $error ) {
		$data = $error->get_error_data();
		return is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
	}

	/**
	 * What to log about a failure: the status and TackQuote's machine code. Never
	 * a file name, the bytes or an email address.
	 *
	 * @param WP_Error $error The failure.
	 * @return string
	 */
	public static function error_summary( $error ) {
		$data = $error->get_error_data();
		$code = is_array( $data ) && ! empty( $data['code'] ) ? (string) $data['code'] : (string) $error->get_error_code();
		return 'HTTP ' . self::error_status( $error ) . ' ' . $code;
	}

	/**
	 * Write to WooCommerce's log under the plugin's own source.
	 *
	 * @param string $message Message: ids and statuses only.
	 * @param string $level   Log level.
	 */
	private function log( $message, $level = 'warning' ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		$logger = wc_get_logger();
		if ( ! $logger ) {
			return;
		}
		if ( 'info' === $level ) {
			$logger->info( $message, array( 'source' => 'tackquote' ) );
		} else {
			$logger->warning( $message, array( 'source' => 'tackquote' ) );
		}
	}
}
