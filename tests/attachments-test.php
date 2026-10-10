<?php
/**
 * Tests for 1.10.0 attachments: quote-request files and wholesale-form file fields.
 *
 * What is pinned, and why each one matters:
 *
 *   · No capability, no control and no fields. An older TackQuote server refuses the
 *     WHOLE quote request over one unknown field (`forbidNonWhitelisted`), so the
 *     plugin must never send `uploadIds` to a server whose ping did not list
 *     `attachments`, and must not even render the control.
 *   · Every file is refused BEFORE any outbound call when it is over the count, over
 *     the size, or not really a PDF/JPEG/PNG (a renamed text file is caught by its bytes).
 *   · The bytes go to the right route, raw, with exactly ONE credential (`X-Api-Key`):
 *     `/storefront/v1` answers 401 to a request carrying two.
 *   · A guest's later uploads and the quote request carry the token the first upload
 *     answered; a signed-in customer is asserted by account email + WordPress user id.
 *   · The PHP temp file is deleted; nothing is written anywhere else.
 *   · A 429 is "try again shortly", never a silent drop.
 *   · Wholesale: files go through the v1 signup route; no files, the legacy route.
 *
 * Run: php tests/run.php
 *
 * @package TackQuotes
 */

require_once TACK_QUOTES_DIR . 'includes/class-tack-attachments.php';
require_once TACK_QUOTES_DIR . 'includes/class-tack-widget.php';

if ( ! defined( 'MB_IN_BYTES' ) ) {
	define( 'MB_IN_BYTES', 1048576 );
}
if ( ! function_exists( 'wp_check_filetype_and_ext' ) ) {
	/**
	 * Extension-based, like WordPress for a file it cannot inspect further.
	 *
	 * @param string $file     Path.
	 * @param string $filename Name.
	 * @param array  $mimes    ext => mime.
	 * @return array
	 */
	function wp_check_filetype_and_ext( $file, $filename, $mimes = null ) {
		$ext = strtolower( pathinfo( (string) $filename, PATHINFO_EXTENSION ) );
		$ok  = is_array( $mimes ) && isset( $mimes[ $ext ] );
		return array(
			'ext'             => $ok ? $ext : false,
			'type'            => $ok ? $mimes[ $ext ] : false,
			'proper_filename' => false,
		);
	}
}
if ( ! function_exists( 'sanitize_file_name' ) ) {
	/** @param string $n Name. @return string */
	function sanitize_file_name( $n ) {
		return preg_replace( '/[^A-Za-z0-9._-]+/', '-', (string) $n );
	}
}
if ( ! function_exists( 'wp_basename' ) ) {
	/** @param string $p Path. @return string */
	function wp_basename( $p ) {
		return basename( (string) $p );
	}
}
$GLOBALS['TACK_DELETED_FILES'] = array();
if ( ! function_exists( 'wp_delete_file' ) ) {
	/** @param string $p Path. */
	function wp_delete_file( $p ) {
		$GLOBALS['TACK_DELETED_FILES'][] = $p;
		@unlink( $p ); // phpcs:ignore
	}
}
if ( ! function_exists( 'wp_list_pluck' ) ) {
	/** @param array $l List. @param string $f Field. @return array */
	function wp_list_pluck( $l, $f ) {
		return array_map(
			function ( $r ) use ( $f ) {
				return is_array( $r ) ? $r[ $f ] : $r->$f;
			},
			$l
		);
	}
}
if ( ! function_exists( 'sanitize_mime_type' ) ) {
	/** @param string $m Mime. @return string */
	function sanitize_mime_type( $m ) {
		return preg_replace( '/[^-+*.a-zA-Z0-9\/]/', '', (string) $m );
	}
}
if ( ! function_exists( 'wp_hash' ) ) {
	/** @param string $d Data. @return string */
	function wp_hash( $d ) {
		return md5( 'salt' . $d );
	}
}

/**
 * Attachments with "is this a real upload" answered from a test list, since PHP's
 * is_uploaded_file() is only true inside a real multipart request.
 */
class Tack_Test_Attachments extends Tack_Attachments {
	/** @var string[] */
	public static $uploaded = array();

	/**
	 * @param string $path Path.
	 * @return bool
	 */
	protected function is_uploaded( $path ) {
		return in_array( $path, self::$uploaded, true );
	}
}

/**
 * A temp file posing as a PHP upload.
 *
 * @param string $name  Client file name.
 * @param string $bytes Contents.
 * @return array A $_FILES-style single entry.
 */
function tack_test_upload_file( $name, $bytes ) {
	$path = tempnam( sys_get_temp_dir(), 'tackup' );
	file_put_contents( $path, $bytes ); // phpcs:ignore
	Tack_Test_Attachments::$uploaded[] = $path;
	return array(
		'name'     => $name,
		'tmp_name' => $path,
		'size'     => strlen( $bytes ),
		'error'    => UPLOAD_ERR_OK,
	);
}

$tack_pdf  = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
// A real 1x1 PNG, so finfo agrees with the magic bytes.
$tack_png  = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==' ); // phpcs:ignore
$tack_fake = "this is not an image at all, only text\n";

/**
 * Answer TackQuote calls by path, and record them.
 *
 * @param array $routes Path fragment => [code, body array, headers].
 */
function tack_test_attach_routes( array $routes ) {
	$GLOBALS['TACK_HTTP_REQUESTS'] = array();
	$GLOBALS['TACK_HTTP_RESPONDER'] = function ( $url, $args ) use ( $routes ) {
		foreach ( $routes as $fragment => $answer ) {
			if ( false !== strpos( $url, $fragment ) ) {
				$body = $answer[1];
				if ( is_callable( $body ) ) {
					$body = call_user_func( $body, $url, $args );
				}
				return array(
					'response' => array( 'code' => $answer[0] ),
					'body'     => wp_json_encode( $body ),
					'headers'  => isset( $answer[2] ) ? $answer[2] : array(),
				);
			}
		}
		return array(
			'response' => array( 'code' => 404 ),
			'body'     => '{"statusCode":404,"message":"Not Found"}',
			'headers'  => array(),
		);
	};
}

/**
 * Recorded requests whose URL contains a fragment.
 *
 * @param string $fragment Path fragment.
 * @return array
 */
function tack_test_attach_calls( $fragment ) {
	return array_values(
		array_filter(
			$GLOBALS['TACK_HTTP_REQUESTS'],
			function ( $r ) use ( $fragment ) {
				return false !== strpos( $r['url'], $fragment );
			}
		)
	);
}

$tack_ping_yes = array( 200, array( 'ok' => true, 'capabilities' => array( 'attachments' ) ) );
$tack_ping_no  = array( 200, array( 'ok' => true ) );
$tack_uuid1    = '0b9f2c55-7d1e-4a8b-9c3d-2e4f6a8b0c1d';
$tack_uuid2    = '1c0a3d66-8e2f-4b9c-8d4e-3f5a7b9c1d2e';
$tack_token    = 'guestTok_0123456789abcdefABCDEF';

tack_test_set_option( 'tack_quotes_api_key', 'tq_live_secret_key' );
tack_test_set_option( 'tack_quotes_api_url', 'https://api.tackquote.test/v1' );
$GLOBALS['TACK_NONCE_VALID'] = true;
$tack_nonce                  = wp_create_nonce( 'tack_request_quote' );

// ── 1. Capability absent: no control, no fields, no upload ─────────────────
tack_test_reset_transients();
tack_test_set_option( Tack_Attachments::OPTION_ENABLED, 'no' );
tack_test_attach_routes( array( '/ping' => $tack_ping_yes ) );
$att = new Tack_Test_Attachments();
check( 'setting off: no control', null === $att->script_config() );
check( 'setting off: no ping is made to find out', 0 === count( $GLOBALS['TACK_HTTP_REQUESTS'] ) );

tack_test_set_option( Tack_Attachments::OPTION_ENABLED, 'yes' );
tack_test_reset_transients();
tack_test_attach_routes( array( '/ping' => $tack_ping_no ) );
$att = new Tack_Test_Attachments();
check( 'capability absent: no control', null === $att->script_config() );
$fields = $att->quote_request_fields( array( $tack_uuid1 ), $tack_token, 'guest@example.test' );
check( 'capability absent: ids are refused, never forwarded', is_wp_error( $fields ) );
$widget  = ( new ReflectionClass( 'Tack_Widget' ) )->newInstanceWithoutConstructor();
$payload = $widget->attach_uploads( array( 'buyerEmail' => 'guest@example.test' ), array( $tack_uuid1 ), $tack_token, $att );
check( 'capability absent: the quote payload gets no uploadIds', is_wp_error( $payload ) );
$no_files = $widget->attach_uploads( array( 'buyerEmail' => 'a@example.test' ), array(), '', $att );
check( 'no files: the payload is unchanged (no attachment keys at all)', array( 'buyerEmail' => 'a@example.test' ) === $no_files );
$up = $att->process_upload( $tack_nonce, array( tack_test_upload_file( 'a.pdf', $tack_pdf ) ) );
check( 'capability absent: the upload handler refuses (403)', ! $up['ok'] && 403 === $up['status'] );
check( 'capability absent: nothing is uploaded', 0 === count( tack_test_attach_calls( 'quote-upload' ) ) );

// The capability is cached once per day: a second check makes no second ping.
tack_test_reset_transients();
tack_test_attach_routes( array( '/ping' => $tack_ping_yes ) );
$att = new Tack_Test_Attachments();
check( 'capability present + setting on: the control is offered', is_array( $att->script_config() ) && 3 === $att->script_config()['maxFiles'] );
check( 'the capability answer is cached (one ping for many checks)', 1 === count( tack_test_attach_calls( '/ping' ) ) );
tack_test_set_option( 'tack_quotes_api_key', 'tq_live_other_key' );
$att->script_config();
check( 'a different API key re-checks the capability', 2 === count( tack_test_attach_calls( '/ping' ) ) );
tack_test_set_option( 'tack_quotes_api_key', 'tq_live_secret_key' );

// ── 2. Refused before any HTTP ─────────────────────────────────────────────
tack_test_set_logged_in( false, '' );
tack_test_reset_transients();
tack_test_attach_routes( array( '/ping' => $tack_ping_yes, 'quote-upload' => array( 201, array( 'uploadId' => $tack_uuid1 ) ) ) );
$att = new Tack_Test_Attachments();
$att->script_config(); // Warm the capability cache so only uploads are counted below.

$four = array();
for ( $i = 0; $i < 4; $i++ ) {
	$four[] = tack_test_upload_file( "f$i.pdf", $tack_pdf );
}
$r = $att->process_upload( $tack_nonce, $four );
check( 'more than 3 files: refused 400', ! $r['ok'] && 400 === $r['status'] && 'tack_too_many_files' === $r['data']['code'] );
check( 'more than 3 files: no upload call', 0 === count( tack_test_attach_calls( 'quote-upload' ) ) );
check( 'more than 3 files: every temp file deleted', ! file_exists( $four[0]['tmp_name'] ) && ! file_exists( $four[3]['tmp_name'] ) );

$big = tack_test_upload_file( 'big.pdf', $tack_pdf . str_repeat( 'x', 5 * 1048576 ) );
$r   = $att->process_upload( $tack_nonce, array( $big ) );
check( 'over 5 MB: refused before any HTTP', ! $r['ok'] && 'tack_file_size' === $r['data']['code'] && 0 === count( tack_test_attach_calls( 'quote-upload' ) ) );

$fake = tack_test_upload_file( 'photo.png', $tack_fake );
$r    = $att->process_upload( $tack_nonce, array( $fake ) );
check( 'a text file named .png (wrong magic bytes): refused', ! $r['ok'] && 'tack_file_type' === $r['data']['code'] );
check( 'wrong magic bytes: no upload call', 0 === count( tack_test_attach_calls( 'quote-upload' ) ) );

$exe = tack_test_upload_file( 'tool.exe', $tack_pdf );
$r   = $att->process_upload( $tack_nonce, array( $exe ) );
check( 'a PDF named .exe (wrong extension): refused', ! $r['ok'] && 'tack_file_type' === $r['data']['code'] );

$swap = tack_test_upload_file( 'scan.pdf', $tack_png );
$r    = $att->process_upload( $tack_nonce, array( $swap ) );
check( 'PNG bytes named .pdf (extension and content disagree): refused', ! $r['ok'] && 'tack_file_type' === $r['data']['code'] );

$stray = $tack_pdf;
$r     = $att->process_upload(
	$tack_nonce,
	array(
		array(
			'name'     => 'passwd.pdf',
			'tmp_name' => '/etc/passwd',
			'size'     => 10,
			'error'    => UPLOAD_ERR_OK,
		),
	)
);
check( 'a path that is not a PHP upload is refused', ! $r['ok'] && 0 === count( tack_test_attach_calls( 'quote-upload' ) ) );

$GLOBALS['TACK_NONCE_VALID'] = false;
$r                           = $att->process_upload( $tack_nonce, array( tack_test_upload_file( 'a.pdf', $tack_pdf ) ) );
check( 'bad nonce: 403 tack_nonce_expired with reload', ! $r['ok'] && 403 === $r['status'] && 'tack_nonce_expired' === $r['data']['code'] && ! empty( $r['data']['reload'] ) );
$GLOBALS['TACK_NONCE_VALID'] = true;
check( 'nothing was uploaded by any refused case', 0 === count( tack_test_attach_calls( 'quote-upload' ) ) );

// ── 3. Guest: route, one credential, raw bytes, token chaining ─────────────
tack_test_set_logged_in( false, '' );
tack_test_reset_transients();
$tack_upload_n = 0;
tack_test_attach_routes(
	array(
		'/ping'        => $tack_ping_yes,
		'quote-upload' => array(
			201,
			function ( $url ) use ( $tack_uuid1, $tack_uuid2, $tack_token, &$tack_upload_n ) {
				++$tack_upload_n;
				$answer = array(
					'uploadId' => 1 === $tack_upload_n ? strtoupper( $tack_uuid1 ) : $tack_uuid2,
					'size'     => 10,
					'mimeType' => 'application/pdf',
				);
				if ( 1 === $tack_upload_n ) {
					$answer['uploadToken'] = $tack_token;
				}
				return $answer;
			},
		),
	)
);
$att = new Tack_Test_Attachments();
$f1  = tack_test_upload_file( 'Drawing A.pdf', $tack_pdf );
$f2  = tack_test_upload_file( 'logo.png', $tack_png );
$r   = $att->process_upload( $tack_nonce, array( $f1, $f2 ) );
$up  = tack_test_attach_calls( 'quote-upload' );
check( 'guest: both files uploaded', $r['ok'] && 2 === count( $up ), wp_json_encode( $r ) );
check( 'guest: upload ids answered (lower-cased)', $r['ok'] && array( $tack_uuid1, $tack_uuid2 ) === $r['data']['uploadIds'] );
check( 'guest: the token is handed back for the quote request', $r['ok'] && $tack_token === $r['data']['uploadToken'] );
$first = $up[0];
$parts = wp_parse_url( $first['url'] );
parse_str( isset( $parts['query'] ) ? $parts['query'] : '', $q1 );
check( 'upload goes to /storefront/v1/quote-upload', 'https://api.tackquote.test/v1/storefront/v1/quote-upload' === $parts['scheme'] . '://' . $parts['host'] . $parts['path'] );
check( 'upload names the file (sanitised)', isset( $q1['name'] ) && 'Drawing-A.pdf' === $q1['name'] );
check( 'guest upload sends no buyerEmail and no user id', ! isset( $q1['buyerEmail'] ) && ! isset( $q1['buyerExternalId'] ) );
check( 'first guest upload has no token yet', ! isset( $q1['upload_token'] ) );
parse_str( (string) wp_parse_url( $up[1]['url'], PHP_URL_QUERY ), $q2 );
check( 'second guest upload sends the token the first answered', isset( $q2['upload_token'] ) && $tack_token === $q2['upload_token'] );
$h = $first['args']['headers'];
check( 'upload carries X-Api-Key', isset( $h['X-Api-Key'] ) && 'tq_live_secret_key' === $h['X-Api-Key'] );
check( 'upload carries exactly ONE credential (no Authorization)', ! isset( $h['Authorization'] ) );
check( 'upload is raw application/octet-stream', isset( $h['Content-Type'] ) && 'application/octet-stream' === $h['Content-Type'] );
check( 'upload carries the plugin version header', isset( $h['X-TackQuote-Plugin-Version'] ) );
check( 'upload body is exactly the file bytes', $tack_pdf === $first['args']['body'] );
check( 'upload is a POST', 'POST' === $first['args']['method'] );
check( 'temp files deleted after upload', ! file_exists( $f1['tmp_name'] ) && ! file_exists( $f2['tmp_name'] ) );
$logged = wp_json_encode( $GLOBALS['TACK_LOGGED'] );
check( 'logs never carry the file name', false === strpos( $logged, 'Drawing' ) && false === strpos( $logged, 'logo.png' ) );

// A guest quote request MUST carry the token; with it, both fields are forwarded.
$fields = $att->quote_request_fields( array( $tack_uuid1, $tack_uuid2 ), $tack_token, 'typed@example.test' );
check( 'guest request: uploadIds + uploadToken forwarded', is_array( $fields ) && array( $tack_uuid1, $tack_uuid2 ) === $fields['uploadIds'] && $tack_token === $fields['uploadToken'] );
$fields = $att->quote_request_fields( array( $tack_uuid1 ), '', 'typed@example.test' );
check( 'guest request without a token: refused (a typed email is never an upload identity)', is_wp_error( $fields ) );
$fields = $att->quote_request_fields( array( $tack_uuid1, $tack_uuid2, 'a5b6c7d8-1111-4222-8333-444455556666', '2c0a3d66-8e2f-4b9c-8d4e-3f5a7b9c1d2e' ), $tack_token, 'x@example.test' );
check( 'more than 3 ids on the request: refused', is_wp_error( $fields ) );
$fields = $att->quote_request_fields( array( '../../etc' ), $tack_token, 'x@example.test' );
check( 'a malformed id: refused', is_wp_error( $fields ) );
$fields = $att->quote_request_fields( array( $tack_uuid1 ), 'bad token!', 'x@example.test' );
check( 'a malformed token: refused', is_wp_error( $fields ) );

// ── 4. Signed in: asserted email + WordPress user id ───────────────────────
tack_test_set_logged_in( true, 'buyer@example.test' );
tack_test_reset_transients();
tack_test_attach_routes(
	array(
		'/ping'        => $tack_ping_yes,
		'quote-upload' => array( 201, array( 'uploadId' => $tack_uuid1 ) ),
	)
);
$att = new Tack_Test_Attachments();
$r   = $att->process_upload( $tack_nonce, array( tack_test_upload_file( 'po.pdf', $tack_pdf ) ) );
$up  = tack_test_attach_calls( 'quote-upload' );
parse_str( (string) wp_parse_url( $up[0]['url'], PHP_URL_QUERY ), $q );
check( 'signed in: buyerEmail is the account email', isset( $q['buyerEmail'] ) && 'buyer@example.test' === $q['buyerEmail'] );
check( 'signed in: buyerExternalId is the WordPress user id', isset( $q['buyerExternalId'] ) && '1' === $q['buyerExternalId'] );
check( 'signed in: no guest token involved', $r['ok'] && ! isset( $r['data']['uploadToken'] ) && ! isset( $q['upload_token'] ) );
$fields = $att->quote_request_fields( array( $tack_uuid1 ), '', 'Buyer@Example.test' );
check( 'signed in, same email: ids forwarded without a token', is_array( $fields ) && array( 'uploadIds' => array( $tack_uuid1 ) ) === $fields );
$fields = $att->quote_request_fields( array( $tack_uuid1 ), '', 'someone.else@example.test' );
check( 'signed in, a different typed email: refused before sending', is_wp_error( $fields ) && 'tack_file_email' === $fields->get_error_code() );

// ── 5. 429 → friendly retry ────────────────────────────────────────────────
tack_test_reset_transients();
tack_test_attach_routes(
	array(
		'/ping'        => $tack_ping_yes,
		'quote-upload' => array(
			429,
			array(
				'statusCode' => 429,
				'message'    => 'ThrottlerException: Too Many Requests',
			),
			array( 'retry-after' => '42' ),
		),
	)
);
$att  = new Tack_Test_Attachments();
$f429 = tack_test_upload_file( 'a.pdf', $tack_pdf );
$r    = $att->process_upload( $tack_nonce, array( $f429 ) );
check( '429: answered 429, not a silent drop', ! $r['ok'] && 429 === $r['status'] && 'tack_rate_limited' === $r['data']['code'] );
check( '429: "try again shortly", never the raw body', false !== strpos( $r['data']['message'], 'try again shortly' ) && false === strpos( $r['data']['message'], 'Throttler' ) );
check( '429: Retry-After forwarded', 42 === $r['data']['retryAfter'] );
check( '429: temp file still deleted', ! file_exists( $f429['tmp_name'] ) );

// ── 6. A server that refuses the fields is re-checked next time ────────────
$refusal = new WP_Error( 'tack_http_400', 'property uploadIds should not exist', array( 'status' => 400 ) );
check( 'a 400 naming uploadIds is recognised', Tack_Widget::refused_attachment_fields( $refusal ) );
check( 'an unrelated 400 is not', ! Tack_Widget::refused_attachment_fields( new WP_Error( 'tack_http_400', 'email must be an email', array( 'status' => 400 ) ) ) );
( new Tack_Api_Client() )->server_capabilities();
( new Tack_Api_Client() )->forget_capabilities();
check( 'forget_capabilities() clears the cache', false === get_transient( Tack_Api_Client::CAPABILITIES_TRANSIENT ) );

// The connection test records what ping advertised.
tack_test_reset_transients();
tack_test_attach_routes( array( '/ping' => $tack_ping_yes ) );
( new Tack_Api_Client() )->test_connection();
$cached = get_transient( Tack_Api_Client::CAPABILITIES_TRANSIENT );
check( 'the connection test caches the capability list', is_array( $cached ) && array( 'attachments' ) === $cached['caps'] );
check( 'the cache holds a key fingerprint, never the key', is_array( $cached ) && false === strpos( wp_json_encode( $cached ), 'tq_live_secret_key' ) );

// ── 7. Wholesale form file fields ──────────────────────────────────────────
$tack_form = array(
	'name'   => 'Trade account',
	'fields' => array(
		array(
			'key'      => 'company',
			'label'    => 'Company',
			'type'     => 'text',
			'required' => true,
		),
		array(
			'key'       => 'resale_cert',
			'label'     => 'Resale certificate',
			'type'      => 'file',
			'required'  => true,
			'accept'    => array( 'application/pdf' ),
			'maxSizeMb' => 2,
		),
	),
);
$tack_optional_form                          = $tack_form;
$tack_optional_form['fields'][1]['required'] = false;

$wholesale_client = function ( $ping, $form ) use ( $tack_uuid1 ) {
	return new Tack_Test_Forms_Client(
		array(
			'/ping'                     => $ping,
			'wholesale-form/submit'     => array(
				'id'     => 'app-legacy',
				'status' => 'pending',
			),
			'wholesale-form?slug'       => $form,
			'wholesale-upload'          => array( 'uploadId' => $tack_uuid1 ),
			'wholesale-signup/'         => array(
				'id'     => 'app-v1',
				'status' => 'pending',
			),
		)
	);
};
$tack_post = function ( array $extra = array() ) {
	return array_merge(
		array(
			'_tack_nonce'   => wp_create_nonce( Tack_Storefront_Forms::ACTION_WHOLESALE ),
			'tack_slug'     => 'trade',
			'tack_redirect' => 'https://shop.example/apply/',
			'tack_sf'       => array( 'company' => 'Acme Ltd' ),
		),
		$extra
	);
};

tack_test_reset_transients();
tack_test_set_logged_in( true, 'buyer@example.test' );
$client = $wholesale_client( array( 'capabilities' => array( 'attachments' ) ), $tack_form );
$forms  = new Tack_Storefront_Forms( $client, new Tack_Test_Attachments( $client ) );
$html   = $forms->render_wholesale_form( 'trade', 'https://shop.example/apply/' );
check( 'wholesale, signed in + capable: a file input renders', false !== strpos( $html, 'type="file"' ) && false !== strpos( $html, 'tack_sf_files[resale_cert]' ) );
check( 'wholesale: the form is multipart', false !== strpos( $html, 'enctype="multipart/form-data"' ) );
check( 'wholesale: the field\'s accept list is honoured', false !== strpos( $html, 'accept=".pdf,application/pdf"' ) );
check( 'wholesale: a required file field no longer blocks the submit button', false !== strpos( $html, 'tackquote-submit' ) );

$wf     = tack_test_upload_file( 'cert.pdf', $tack_pdf );
$out    = $forms->process_wholesale_submission( $tack_post(), array( 'resale_cert' => $wf ) );
$paths  = $client->paths();
$upload = null;
$signup = null;
foreach ( $client->sent as $sent ) {
	if ( false !== strpos( $sent['path'], 'wholesale-upload' ) ) {
		$upload = $sent;
	}
	if ( false !== strpos( $sent['path'], 'wholesale-signup/' ) ) {
		$signup = $sent;
	}
}
check( 'wholesale with a file: submitted (pending)', 'pending' === $out['kind'], wp_json_encode( $out ) );
check( 'wholesale with a file: uploaded to /storefront/v1/wholesale-upload', null !== $upload );
parse_str( (string) wp_parse_url( 'https://x' . ( $upload ? $upload['path'] : '' ), PHP_URL_QUERY ), $wq );
check( 'wholesale upload names form, field, buyer and user id', isset( $wq['form'], $wq['field'], $wq['buyerEmail'], $wq['buyerExternalId'] ) && 'trade' === $wq['form'] && 'resale_cert' === $wq['field'] && '1' === $wq['buyerExternalId'] );
check( 'wholesale upload: raw bytes, X-Api-Key only', $upload && $tack_pdf === $upload['body'] && array_key_exists( 'Authorization', $upload['headers'] ) && null === $upload['headers']['Authorization'] && 'application/octet-stream' === $upload['headers']['Content-Type'] );
check( 'wholesale with a file: submitted through /storefront/v1/wholesale-signup/<slug>', null !== $signup && 0 === strpos( $signup['path'], '/storefront/v1/wholesale-signup/trade?' ) );
check( 'wholesale with a file: the file field\'s value is {uploadId}', $signup && isset( $signup['body']['values'] ) && array( 'uploadId' => $tack_uuid1 ) === ( (array) $signup['body']['values'] )['resale_cert'] );
check( 'wholesale with a file: the legacy route is NOT used', ! in_array( true, array_map( function ( $p ) { return false !== strpos( $p, 'wholesale-form/submit' ); }, $paths ), true ) ); // phpcs:ignore
check( 'wholesale: temp file deleted', ! file_exists( $wf['tmp_name'] ) );

// Without files (optional field left empty): the legacy route, exactly as before.
tack_test_reset_transients();
$client = $wholesale_client( array( 'capabilities' => array( 'attachments' ) ), $tack_optional_form );
$forms  = new Tack_Storefront_Forms( $client, new Tack_Test_Attachments( $client ) );
$out    = $forms->process_wholesale_submission( $tack_post(), array() );
$legacy = array_filter( $client->paths(), function ( $p ) { return false !== strpos( $p, 'wholesale-form/submit' ); } ); // phpcs:ignore
check( 'wholesale without files: the legacy route', 'pending' === $out['kind'] && 1 === count( $legacy ) );
check( 'wholesale without files: no v1 signup, no upload', 0 === count( array_filter( $client->paths(), function ( $p ) { return false !== strpos( $p, 'wholesale-signup' ) || false !== strpos( $p, 'wholesale-upload' ); } ) ) ); // phpcs:ignore

// A required file field without a file: refused, nothing sent.
tack_test_reset_transients();
$client = $wholesale_client( array( 'capabilities' => array( 'attachments' ) ), $tack_form );
$forms  = new Tack_Storefront_Forms( $client, new Tack_Test_Attachments( $client ) );
$out    = $forms->process_wholesale_submission( $tack_post(), array() );
check( 'required file missing: refused with the field name', 'error' === $out['kind'] && false !== strpos( $out['message'], 'Resale certificate' ) );

// The field's own accept list and size cap apply: a PNG to a PDF-only field is refused.
$png_for_pdf = tack_test_upload_file( 'cert.png', $tack_png );
$out         = $forms->process_wholesale_submission( $tack_post(), array( 'resale_cert' => $png_for_pdf ) );
check( 'a type the field does not accept: refused before any upload', 'error' === $out['kind'] && 0 === count( array_filter( $client->paths(), function ( $p ) { return false !== strpos( $p, 'wholesale-upload' ); } ) ) ); // phpcs:ignore
check( 'refused file: temp file deleted', ! file_exists( $png_for_pdf['tmp_name'] ) );
$three_mb = tack_test_upload_file( 'cert.pdf', $tack_pdf . str_repeat( 'x', 3 * 1048576 ) );
$out      = $forms->process_wholesale_submission( $tack_post(), array( 'resale_cert' => $three_mb ) );
check( 'over the field\'s maxSizeMb (2 MB): refused', 'error' === $out['kind'] && false !== strpos( $out['message'], '2 MB' ) );

// Guest: "sign in to attach files"; a required file field blocks the form.
tack_test_reset_transients();
tack_test_set_logged_in( false, '' );
$client = $wholesale_client( array( 'capabilities' => array( 'attachments' ) ), $tack_form );
$forms  = new Tack_Storefront_Forms( $client, new Tack_Test_Attachments( $client ) );
$html   = $forms->render_wholesale_form( 'trade', 'https://shop.example/apply/' );
check( 'wholesale guest: no file input', false === strpos( $html, 'type="file"' ) );
check( 'wholesale guest: told to sign in', false !== strpos( $html, 'signed in to apply' ) );
check( 'wholesale guest + required file: no submit button', false === strpos( $html, 'tackquote-submit' ) );
$gf  = tack_test_upload_file( 'cert.pdf', $tack_pdf );
$out = $forms->process_wholesale_submission( $tack_post(), array( 'resale_cert' => $gf ) );
check( 'wholesale guest posting a file anyway: "sign in to attach files", nothing uploaded', 'error' === $out['kind'] && false !== strpos( $out['message'], 'Sign in' ) && 0 === count( array_filter( $client->paths(), function ( $p ) { return false !== strpos( $p, 'wholesale-upload' ); } ) ) ); // phpcs:ignore
check( 'wholesale guest: temp file deleted', ! file_exists( $gf['tmp_name'] ) );

// Server without the capability: the notice, and a required file field still blocks.
tack_test_reset_transients();
tack_test_set_logged_in( true, 'buyer@example.test' );
$client = $wholesale_client( array( 'ok' => true ), $tack_form );
$forms  = new Tack_Storefront_Forms( $client, new Tack_Test_Attachments( $client ) );
$html   = $forms->render_wholesale_form( 'trade', 'https://shop.example/apply/' );
check( 'wholesale, server without attachments: no file input', false === strpos( $html, 'type="file"' ) );
check( 'wholesale, server without attachments: required file blocks submit', false === strpos( $html, 'tackquote-submit' ) );

tack_test_set_logged_in( false, '' );
unset( $GLOBALS['TACK_HTTP_RESPONDER'] );
tack_test_reset_transients();
