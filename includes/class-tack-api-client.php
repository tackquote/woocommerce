<?php
/**
 * Thin HTTP client for the Tack API using the WordPress HTTP API.
 *
 * @package TackQuotes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles authenticated requests to the Tack API.
 */
class Tack_Api_Client {

	/**
	 * The configured API base URL.
	 *
	 * @return string API base URL without trailing slash.
	 */
	private function base_url() {
		return rtrim( (string) get_option( 'tack_quotes_api_url', 'https://api.tackquote.com/v1' ), '/' );
	}

	/**
	 * The stored API key.
	 *
	 * @return string The stored API key.
	 */
	private function api_key() {
		return (string) get_option( 'tack_quotes_api_key', '' );
	}

	/**
	 * Default timeout, in seconds, for calls that are not on a visitor's critical path.
	 */
	const DEFAULT_TIMEOUT = 20;

	/**
	 * Timeout, in seconds, for calls made inside a request a visitor is waiting on.
	 *
	 * A front-end request holds a PHP-FPM worker for as long as this call takes, so the
	 * ceiling here is a capacity decision, not a patience decision: at 20s a handful of
	 * concurrent quote submissions can occupy every worker on a small host.
	 */
	const INTERACTIVE_TIMEOUT = 5;

	/**
	 * Perform a request.
	 *
	 * A STRING body is sent as-is (1.10.0, the raw bytes of an attachment upload);
	 * the caller then names its own Content-Type. An array is JSON-encoded.
	 *
	 * @param string            $method  HTTP method.
	 * @param string            $path    Path beginning with '/'.
	 * @param array|string|null $body    Optional JSON body, or raw bytes.
	 * @param int|null          $timeout Optional timeout override, in seconds.
	 * @param array             $headers Optional extra request headers.
	 * @return array|WP_Error Decoded response array, or WP_Error.
	 */
	public function request( $method, $path, $body = null, $timeout = null, $headers = array() ) {
		$key = $this->api_key();
		if ( '' === $key ) {
			return new WP_Error( 'tack_no_key', __( 'No TackQuote API key configured.', 'tackquote' ) );
		}

		/*
		 * Never follow a redirect. WordPress' HTTP API (Requests::parse_response(), WP
		 * 7.1.3) re-sends the request headers, the key in `Authorization` and
		 * `X-Api-Key` included, to whatever `Location` a 3xx names: an `http://` URL in
		 * cleartext, another host, or an internal address. That would undo the
		 * https-only check on the API URL setting. The API answers JSON directly and
		 * never needs a redirect, so a 3xx is reported as an error with its status.
		 *
		 * The URL is set only by an administrator and may be a local development host,
		 * which is why this is `wp_remote_request()` and not `wp_safe_remote_request()`
		 * (the safe variant refuses loopback and private addresses, and every port
		 * except 80, 443 and 8080).
		 */
		$args = array(
			'method'      => $method,
			'timeout'     => null === $timeout ? self::DEFAULT_TIMEOUT : max( 1, (int) $timeout ),
			'redirection' => 0,
			'headers'     => array_merge(
				array(
					'Authorization'              => 'Bearer ' . $key,
					'X-Api-Key'                  => $key,
					'Content-Type'               => 'application/json',
					'Accept'                     => 'application/json',
					'User-Agent'                 => 'TackQuotes-WooCommerce/' . TACK_QUOTES_VERSION,
					// Sent on EVERY request so TackQuote can record which plugin build a store
					// runs and gate newer server features on it. A server that does not know
					// the header ignores it; the User-Agent above stays because proxies rewrite it.
					'X-TackQuote-Plugin-Version' => TACK_QUOTES_VERSION,
				),
				is_array( $headers ) ? $headers : array()
			),
		);

		/*
		 * A caller may REMOVE a default header by passing it as null. `/storefront/v1/*`
		 * needs this: it treats `Authorization` as a platform token (Wix) and refuses a
		 * request carrying two storefront credentials with 401 "Send exactly one
		 * storefront credential" (tack `storefront-identity.guard.ts`), so those reads
		 * send the key in `X-Api-Key` only. An empty string would still be a header.
		 */
		$args['headers'] = array_filter(
			$args['headers'],
			function ( $value ) {
				return null !== $value;
			}
		);
		if ( is_string( $body ) ) {
			$args['body'] = $body;
		} elseif ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $this->base_url() . $path, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$message = is_array( $data ) && isset( $data['message'] )
				? ( is_array( $data['message'] ) ? implode( ', ', $data['message'] ) : $data['message'] )
				: sprintf( /* translators: %d: HTTP status code */ __( 'TackQuote API returned HTTP %d.', 'tackquote' ), $code );

			/*
			 * What the API SAID, kept beside the sentence, because the caller has to decide
			 * whether trying again can ever help (see Tack_Sync_Gate). A JSON 403 naming a
			 * missing scope will be refused forever; a 403 HTML page from a firewall in front
			 * of the API will not. Only the status and the API's own machine fields are
			 * kept, never the request or the key.
			 */
			$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );
			return new WP_Error(
				'tack_http_' . $code,
				$message,
				array(
					'status'            => $code,
					'json'              => is_array( $data ),
					// TackQuote's own errors echo the status in the body; a proxy's JSON does not.
					'statusCode'        => is_array( $data ) && isset( $data['statusCode'] ) && is_numeric( $data['statusCode'] ) ? (int) $data['statusCode'] : 0,
					'code'              => is_array( $data ) && isset( $data['code'] ) && is_string( $data['code'] ) ? $data['code'] : '',
					'requiredScopes'    => is_array( $data ) && isset( $data['requiredScopes'] ) && is_array( $data['requiredScopes'] )
						? array_values( array_filter( $data['requiredScopes'], 'is_string' ) )
						: array(),
					'retryAfterSeconds' => is_array( $data ) && isset( $data['retryAfterSeconds'] ) && is_numeric( $data['retryAfterSeconds'] )
						? (int) $data['retryAfterSeconds']
						: 0,
					'retryAfterHeader'  => is_array( $retry_after ) ? (string) reset( $retry_after ) : (string) $retry_after,
				)
			);
		}

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Connection-test outcomes, carried in the `state` field of a test's WP_Error data.
	 *
	 * `rejected`   TackQuote answered 401/403: the key is wrong, revoked or not allowed.
	 * `unverified` the ping route is missing (404: an old or self-hosted server) and the
	 *              public `GET /health` answered: reachable, but the key was NOT checked.
	 * `failed`     transport error, 429, 5xx or any other answer.
	 *
	 * @since 1.10.0
	 */
	const STATE_REJECTED   = 'rejected';
	const STATE_UNVERIFIED = 'unverified';
	const STATE_FAILED     = 'failed';

	/**
	 * Check the saved key against TackQuote's authenticated ping.
	 *
	 * Only a 2xx ping passes. `GET /health` is PUBLIC (it never reads the key), so it is
	 * asked only when the ping route itself is missing (404), and even then the answer is
	 * "reachable, key not verified", never a pass. Falling back to it on ANY ping failure
	 * once reported "Connected" for a key TackQuote had just refused with 401.
	 *
	 * Capabilities are cached only from a passing ping. A 401/403 clears them, so a
	 * feature advertised earlier cannot outlive the key's refusal; any other failure
	 * leaves the cache as it was.
	 *
	 * @return true|WP_Error WP_Error data: `state` (STATE_*), `status`, `code`.
	 */
	public function test_connection() {
		$result = $this->request( 'GET', '/integrations/woocommerce/ping' );
		if ( ! is_wp_error( $result ) ) {
			$this->remember_capabilities( self::capabilities_of( $result ), DAY_IN_SECONDS );
			return true;
		}
		$status = self::status_of( $result );

		if ( 401 === $status || 403 === $status ) {
			$this->forget_capabilities();
			return self::connection_error( $result, self::STATE_REJECTED );
		}

		if ( 404 === $status ) {
			$health = $this->request( 'GET', '/health' );
			return self::connection_error( is_wp_error( $health ) ? $health : $result, is_wp_error( $health ) ? self::STATE_FAILED : self::STATE_UNVERIFIED );
		}

		return self::connection_error( $result, self::STATE_FAILED );
	}

	/**
	 * The HTTP status a request() error carries; 0 for a transport or no-key error.
	 *
	 * @param WP_Error $error Error.
	 * @return int
	 */
	private static function status_of( $error ) {
		$data = $error->get_error_data();
		return is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
	}

	/**
	 * The merchant-facing error for a failed connection test.
	 *
	 * Names the HTTP status and TackQuote's own error code when it sent one; never the
	 * key, and never the raw response body.
	 *
	 * @param WP_Error $error Error from request().
	 * @param string   $state One of STATE_*.
	 * @return WP_Error
	 */
	private static function connection_error( $error, $state ) {
		$status = self::status_of( $error );
		$data   = $error->get_error_data();
		$code   = is_array( $data ) && isset( $data['code'] ) ? sanitize_key( (string) $data['code'] ) : '';

		if ( 401 === $status ) {
			$message = __( 'TackQuote rejected this API key: check you pasted the whole key and that it is not revoked.', 'tackquote' );
		} elseif ( 403 === $status ) {
			$message = __( 'This key lacks permission to connect a store: check its scopes in TackQuote, or create a new key.', 'tackquote' );
		} elseif ( self::STATE_UNVERIFIED === $state ) {
			$message = __( 'Server reachable, key not verified: this TackQuote server has no connection check, so the key could not be tested.', 'tackquote' );
		} elseif ( 'tack_no_key' === $error->get_error_code() ) {
			$message = $error->get_error_message();
		} elseif ( 0 === $status ) {
			$message = __( 'Could not reach TackQuote. Check the API URL, then try again in a few minutes.', 'tackquote' );
		} elseif ( 429 === $status || $status >= 500 ) {
			$message = __( 'TackQuote is busy or unavailable right now. Try again in a few minutes.', 'tackquote' );
		} else {
			$message = __( 'TackQuote did not accept the connection test.', 'tackquote' );
		}

		if ( $status > 0 && self::STATE_UNVERIFIED !== $state ) {
			$message .= ' ' . ( '' !== $code
				/* translators: 1: HTTP status code, 2: TackQuote's error code. */
				? sprintf( __( '(HTTP %1$d, code %2$s)', 'tackquote' ), $status, $code )
				/* translators: %d: HTTP status code. */
				: sprintf( __( '(HTTP %d)', 'tackquote' ), $status ) );
		}

		return new WP_Error(
			'tack_connection_' . $state,
			$message,
			array(
				'state'  => $state,
				'status' => $status,
				'code'   => $code,
			)
		);
	}

	/**
	 * Transient caching what the TackQuote server said it supports.
	 *
	 * `{key: <16-char SHA-256 prefix of the API key>, caps: string[], until: int}`
	 * after a passing ping, re-read at most once a day. A FAILED ping writes
	 * `{key, until, failed: <STATE_*>}` for ten minutes instead: no `caps` at all, so a
	 * failure is never read as a capability answer (not even an empty one), and an
	 * outage or a refused key still costs at most one ping per ten minutes.
	 *
	 * @since 1.10.0
	 */
	const CAPABILITIES_TRANSIENT = 'tack_quotes_server_capabilities';

	/**
	 * Timeout, in seconds, for streaming one attachment (at most 5 MB) to TackQuote.
	 *
	 * Longer than INTERACTIVE_TIMEOUT because the request carries the file, still
	 * bounded because a shopper is waiting and the call holds a PHP worker.
	 *
	 * @since 1.10.0
	 */
	const UPLOAD_TIMEOUT = 15;

	/**
	 * The `capabilities` list from a ping answer: strings only.
	 *
	 * @param mixed $answer Decoded ping answer.
	 * @return string[]
	 */
	private static function capabilities_of( $answer ) {
		if ( ! is_array( $answer ) || ! isset( $answer['capabilities'] ) || ! is_array( $answer['capabilities'] ) ) {
			return array();
		}
		return array_values( array_filter( $answer['capabilities'], 'is_string' ) );
	}

	/**
	 * A short fingerprint of the saved key, so a cached answer never outlives a key change.
	 *
	 * @return string
	 */
	private function key_fingerprint() {
		return substr( hash( 'sha256', $this->api_key() ), 0, 16 );
	}

	/**
	 * Cache the server's capability list for the saved key.
	 *
	 * @param string[] $caps Capability names.
	 * @param int      $ttl  Seconds to keep it.
	 */
	private function remember_capabilities( array $caps, $ttl ) {
		set_transient(
			self::CAPABILITIES_TRANSIENT,
			array(
				'key'   => $this->key_fingerprint(),
				'caps'  => $caps,
				'until' => time() + (int) $ttl,
			),
			(int) $ttl
		);
	}

	/**
	 * Remember, for ten minutes, that the ping failed: replaces any capability list.
	 *
	 * @since 1.10.0
	 *
	 * @param string $state STATE_REJECTED or STATE_FAILED.
	 */
	private function remember_failure( $state ) {
		set_transient(
			self::CAPABILITIES_TRANSIENT,
			array(
				'key'    => $this->key_fingerprint(),
				'failed' => (string) $state,
				'until'  => time() + 10 * MINUTE_IN_SECONDS,
			),
			10 * MINUTE_IN_SECONDS
		);
	}

	/**
	 * What the TackQuote server says it supports (`GET /integrations/woocommerce/ping`
	 * answers `capabilities`, tack `WOOCOMMERCE_PLUGIN_CAPABILITIES`).
	 *
	 * An older server answers no list, which is "nothing new": the plugin then sends
	 * none of the fields a newer server added, because an older server's
	 * `forbidNonWhitelisted` refuses the WHOLE request over one unknown field.
	 *
	 * Cached for a day per API key. A failed ping answers "nothing" and is remembered
	 * for ten minutes as a failure (never as a capability list), so an outage does not
	 * turn every page view into a timeout. The ping
	 * uses the interactive timeout because it can run while a page renders.
	 *
	 * @since 1.10.0
	 *
	 * @param bool $refresh Ignore the cache.
	 * @return string[]
	 */
	public function server_capabilities( $refresh = false ) {
		if ( '' === $this->api_key() ) {
			return array();
		}
		$cached = get_transient( self::CAPABILITIES_TRANSIENT );
		if ( ! $refresh && is_array( $cached ) && isset( $cached['key'], $cached['until'] )
			&& $cached['key'] === $this->key_fingerprint() && (int) $cached['until'] > time() ) {
			if ( isset( $cached['caps'] ) && is_array( $cached['caps'] ) ) {
				return array_values( array_filter( $cached['caps'], 'is_string' ) );
			}
			if ( isset( $cached['failed'] ) ) {
				return array();
			}
		}
		$result = $this->request( 'GET', '/integrations/woocommerce/ping', null, self::INTERACTIVE_TIMEOUT );
		if ( is_wp_error( $result ) ) {
			$status = self::status_of( $result );
			$this->remember_failure( 401 === $status || 403 === $status ? self::STATE_REJECTED : self::STATE_FAILED );
			return array();
		}
		$caps = self::capabilities_of( $result );
		$this->remember_capabilities( $caps, DAY_IN_SECONDS );
		return $caps;
	}

	/**
	 * Does the server take quote-request attachments and wholesale-form files?
	 *
	 * @since 1.10.0
	 *
	 * @return bool
	 */
	public function supports_attachments() {
		return in_array( 'attachments', $this->server_capabilities(), true );
	}

	/**
	 * Does the server take `lineItems[].targetPrice` on the quote request?
	 *
	 * No ping capability names the field. tack added it (`StorefrontPluginLineItemDto`,
	 * commit b615540e1, 2026-10-10) BEFORE it added the `attachments` capability
	 * (a7d0a2593, which descends from b615540e1: checked with
	 * `git merge-base --is-ancestor`), so every server that lists `attachments` takes
	 * `targetPrice`. An older server's `forbidNonWhitelisted` refuses the whole request
	 * over the field, so it gets the target prices in the note instead.
	 *
	 * @since 1.10.0
	 *
	 * @return bool
	 */
	public function supports_target_price() {
		return in_array( 'attachments', $this->server_capabilities(), true );
	}

	/**
	 * Forget the cached capability list, so the next check pings again.
	 *
	 * Called when the server refused a field the cache said it supports (a 400
	 * naming `uploadIds`): the store was pointed at an older server, or one was
	 * rolled back.
	 *
	 * @since 1.10.0
	 */
	public function forget_capabilities() {
		delete_transient( self::CAPABILITIES_TRANSIENT );
	}

	/**
	 * Stream one quote-request file to TackQuote.
	 *
	 * `POST /storefront/v1/quote-upload?name=<file name>` with the raw bytes as
	 * `application/octet-stream` and the key in `X-Api-Key` ONLY (v1 refuses a second
	 * credential). A signed-in buyer is asserted with `buyerEmail` (+ the WordPress
	 * user id); a guest sends neither, and the first upload answers an `uploadToken`
	 * that later uploads of the same request send back as `upload_token`.
	 * Answers `{uploadId, filename, size, mimeType[, uploadToken]}`.
	 *
	 * @since 1.10.0
	 *
	 * @param string $bytes        File contents.
	 * @param string $name         Sanitised file name.
	 * @param string $buyer_email  Trusted account email, or '' for a guest.
	 * @param string $upload_token Guest token from an earlier upload of this request, or ''.
	 * @return array|WP_Error
	 */
	public function upload_quote_file( $bytes, $name, $buyer_email = '', $upload_token = '' ) {
		$query = array_merge( array( 'name' => (string) $name ), $this->buyer_query( $buyer_email ) );
		if ( '' === (string) $buyer_email && '' !== (string) $upload_token ) {
			$query['upload_token'] = (string) $upload_token;
		}
		return $this->upload( '/storefront/v1/quote-upload', $query, $bytes );
	}

	/**
	 * Stream one wholesale-application file to TackQuote.
	 *
	 * `POST /storefront/v1/wholesale-upload?form=<slug>&field=<key>&name=<file name>
	 * &buyerEmail=<email>&buyerExternalId=<WP user id>`: signed-in applicants only
	 * (the server answers 401 otherwise). Answers `{uploadId, filename, size, mimeType}`;
	 * the application then sends `{uploadId}` as that field's value.
	 *
	 * @since 1.10.0
	 *
	 * @param string $bytes       File contents.
	 * @param string $name        Sanitised file name.
	 * @param string $slug        Form slug.
	 * @param string $field_key   The file field's key.
	 * @param string $buyer_email Trusted account email.
	 * @return array|WP_Error
	 */
	public function upload_wholesale_file( $bytes, $name, $slug, $field_key, $buyer_email ) {
		$query = array_merge(
			array(
				'form'  => (string) $slug,
				'field' => (string) $field_key,
				'name'  => (string) $name,
			),
			$this->buyer_query( $buyer_email )
		);
		return $this->upload( '/storefront/v1/wholesale-upload', $query, $bytes );
	}

	/**
	 * Submit a wholesale application through the shared storefront route, which
	 * CLAIMS the files its values name.
	 *
	 * `POST /storefront/v1/wholesale-signup/<slug>?buyerEmail=&buyerExternalId=`
	 * with `{values}`; a file field's value is `{uploadId}`. Used only when the
	 * application carries files: the legacy route records the same applicant but
	 * cannot claim uploads.
	 *
	 * @since 1.10.0
	 *
	 * @param string $slug        Form slug.
	 * @param array  $values      Answers keyed by field key.
	 * @param string $buyer_email Trusted account email.
	 * @return array|WP_Error `{id, status, message}`.
	 */
	public function submit_wholesale_signup( $slug, array $values, $buyer_email ) {
		$query = $this->buyer_query( $buyer_email );
		return $this->request(
			'POST',
			'/storefront/v1/wholesale-signup/' . rawurlencode( (string) $slug ) . '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ),
			array( 'values' => (object) $values ),
			self::INTERACTIVE_TIMEOUT,
			array( 'Authorization' => null )
		);
	}

	/**
	 * POST raw bytes to an upload route.
	 *
	 * @param string $route Route path.
	 * @param array  $query Query parameters.
	 * @param string $bytes File contents.
	 * @return array|WP_Error
	 */
	private function upload( $route, array $query, $bytes ) {
		return $this->request(
			'POST',
			$route . '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ),
			(string) $bytes,
			self::UPLOAD_TIMEOUT,
			array(
				// The key in X-Api-Key ONLY: v1 refuses a second credential.
				'Authorization' => null,
				'Content-Type'  => 'application/octet-stream',
			)
		);
	}

	/**
	 * The seller's registration policy, which decides what the storefront form must render:
	 * whether companies or individuals are allowed, which company details are mandatory, and
	 * the seller's own custom questions.
	 *
	 * Cached, because this is fetched on every front-end page view that renders the button
	 * and the answer changes only when a seller edits their settings. Two different TTLs on
	 * purpose:
	 *
	 *   success  15 minutes — long enough to be cheap, short enough that a policy change
	 *                        shows up without anyone clearing caches.
	 *   failure  60 seconds — a short NEGATIVE cache. Without it, an API that is down turns
	 *                        every page view into a blocking HTTP call with the full timeout,
	 *                        and the storefront crawls. Caching the failure briefly keeps the
	 *                        page fast while still recovering quickly.
	 *
	 * Returns null on failure rather than throwing: the caller falls back to a minimal form
	 * so a quote can still be requested when Tack is unreachable.
	 *
	 * @param bool $force Bypass the cache (used by the settings screen's test button).
	 * @return array|null
	 */
	public function get_registration_config( $force = false ) {
		$key = 'tack_quotes_registration_config';

		if ( ! $force ) {
			$cached = get_transient( $key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
			// A cached failure is stored as the string 'unavailable' so it is
			// distinguishable from "nothing cached yet" — an empty array would not be.
			if ( 'unavailable' === $cached ) {
				return null;
			}
		}

		// Fetched while rendering a storefront page, so it uses the interactive timeout: a slow
		// TackQuote must not turn every uncached page view into a 20s wait. The negative cache
		// below is the other half of that.
		$result = $this->request( 'GET', '/integrations/woocommerce/registration-config', null, self::INTERACTIVE_TIMEOUT );

		if ( is_wp_error( $result ) || ! is_array( $result ) || empty( $result ) ) {
			set_transient( $key, 'unavailable', 60 );
			return null;
		}

		set_transient( $key, $result, 15 * MINUTE_IN_SECONDS );
		return $result;
	}

	/**
	 * Create a quote request from cart/product line items.
	 *
	 * Uses the interactive timeout: this runs inside an admin-ajax request the shopper is
	 * watching a spinner for, and every second of it is a PHP worker held open by an
	 * endpoint any visitor can call.
	 *
	 * @param array $payload {buyerEmail, note, lineItems:[{sku,name,quantity,unitPrice}]}.
	 * @return array|WP_Error Response — expected to include a portalUrl/quoteUrl.
	 */
	public function create_quote_request( $payload ) {
		return $this->request( 'POST', '/integrations/woocommerce/quote-requests', $payload, self::INTERACTIVE_TIMEOUT );
	}

	/**
	 * Push a WooCommerce order to Tack.
	 *
	 * Keeps the full timeout: this runs on a background Action Scheduler request, never
	 * inside a customer's checkout, so waiting is cheap and giving up early is not.
	 *
	 * @param array  $order_payload    Normalized order data.
	 * @param string $idempotency_key  Optional key echoed as an Idempotency-Key header.
	 * @return array|WP_Error
	 */
	public function sync_order( $order_payload, $idempotency_key = '' ) {
		$headers = '' !== (string) $idempotency_key
			? array( 'Idempotency-Key' => (string) $idempotency_key )
			: array();
		return $this->request( 'POST', '/integrations/woocommerce/order-sync', $order_payload, self::DEFAULT_TIMEOUT, $headers );
	}

	/**
	 * Exchange a single-use store checkout token for the accepted quote's cart.
	 *
	 * `GET /integrations/woocommerce/quote-checkout/<token>` (scope `quotes:write`):
	 * 200 `{quoteRef, quoteNumber, currency, buyerEmail, poNumber?, goodsTotal,
	 * lines: [{wooProductId, wooVariationId?, sku, name, quantity, unitPrice,
	 * lineTotal}]}`, 404 unknown token, 410 used, expired or no longer valid.
	 * Only the token (in the path) and the store's key are sent.
	 *
	 * Called once per token and NEVER retried: TackQuote spends the token on the
	 * first exchange that passes its checks, so a second attempt can only fail.
	 *
	 * @since 1.10.0
	 *
	 * @param string $token Token from the checkout link (shape already checked).
	 * @return array|WP_Error
	 */
	public function exchange_quote_checkout( $token ) {
		return $this->request( 'GET', '/integrations/woocommerce/quote-checkout/' . rawurlencode( (string) $token ), null, self::INTERACTIVE_TIMEOUT );
	}

	/**
	 * Transient holding the cached wholesale-form definitions, keyed by form slug.
	 *
	 * One transient for every slug (rather than one per slug) so uninstall.php can
	 * delete it by name without a LIKE query against wp_options.
	 */
	const FORM_CACHE_TRANSIENT = 'tack_quotes_wholesale_form_cache';

	/**
	 * Transient set when the server answered 404 for `/storefront/v1/*`.
	 *
	 * An older self-hosted TackQuote has no v1 storefront routes. Remembering the
	 * 404 for an hour keeps a page view at one request instead of two.
	 */
	const V1_MISSING_TRANSIENT = 'tack_quotes_storefront_v1_missing';

	/**
	 * The seller's wholesale application form: its fields, name and success copy.
	 *
	 * `GET /integrations/woocommerce/wholesale-form?slug=`. Fetched while rendering a
	 * storefront page, so it uses the interactive timeout and the same two-TTL cache
	 * as `get_registration_config()`: a success is kept for 5 minutes, a failure for
	 * 60 seconds so an outage does not turn every page view into a timeout.
	 *
	 * @param string $slug  Form slug (Settings -> Wholesale forms in TackQuote).
	 * @param bool   $force Bypass the cache.
	 * @return array|WP_Error The form definition (`fields`, `name`, `description`,
	 *                        `successMessage`), or a WP_Error naming why not.
	 */
	public function get_wholesale_form( $slug, $force = false ) {
		$slug = (string) $slug;
		if ( '' === $slug ) {
			return new WP_Error( 'tack_form_slug', __( 'No wholesale form slug is configured.', 'tackquote' ) );
		}

		$cache = get_transient( self::FORM_CACHE_TRANSIENT );
		$cache = is_array( $cache ) ? $cache : array();
		if ( ! $force && isset( $cache[ $slug ] ) && is_array( $cache[ $slug ] ) ) {
			$entry = $cache[ $slug ];
			if ( isset( $entry['until'] ) && (int) $entry['until'] > time() ) {
				if ( isset( $entry['form'] ) && is_array( $entry['form'] ) ) {
					return $entry['form'];
				}
				// A cached FAILURE: distinguishable from "nothing cached" by the entry
				// existing with no form in it. It keeps the HTTP status, so a cached 404
				// still reads as "no form has this slug" (E2E D10).
				return new WP_Error( 'tack_form_unavailable', __( 'The application form is not available right now.', 'tackquote' ), array( 'status' => isset( $entry['status'] ) ? (int) $entry['status'] : 0 ) );
			}
		}

		$result = $this->request(
			'GET',
			'/integrations/woocommerce/wholesale-form?slug=' . rawurlencode( $slug ),
			null,
			self::INTERACTIVE_TIMEOUT
		);

		if ( is_wp_error( $result ) || ! is_array( $result ) || empty( $result['fields'] ) || ! is_array( $result['fields'] ) ) {
			$cache[ $slug ] = array(
				'until'  => time() + 60,
				'status' => is_wp_error( $result ) ? self::status_of( $result ) : 0,
			);
			set_transient( self::FORM_CACHE_TRANSIENT, $cache, 15 * MINUTE_IN_SECONDS );
			return is_wp_error( $result )
				? $result
				: new WP_Error( 'tack_form_unavailable', __( 'The application form is not available right now.', 'tackquote' ), array( 'status' => 0 ) );
		}

		$cache[ $slug ] = array(
			'until' => time() + 5 * MINUTE_IN_SECONDS,
			'form'  => $result,
		);
		set_transient( self::FORM_CACHE_TRANSIENT, $cache, 15 * MINUTE_IN_SECONDS );
		return $result;
	}

	/**
	 * Submit a wholesale application.
	 *
	 * `POST /integrations/woocommerce/wholesale-form/submit?slug=`, scope `buyers:write`.
	 * Interactive timeout: a shopper is waiting on this request. Never queued and
	 * never retried here: a 429 is reported back so the shopper can try again, since
	 * re-sending an application on their behalf later is not what they asked for.
	 *
	 * @param string $slug            Form slug.
	 * @param array  $values          Answers keyed by field key, in the shapes
	 *                                `common/forms/form-schema.ts` validates.
	 * @param string $woo_customer_id WooCommerce customer id when signed in, else ''.
	 * @return array|WP_Error `{id, status, message}`.
	 */
	public function submit_wholesale_form( $slug, array $values, $woo_customer_id = '' ) {
		$body = array( 'values' => (object) $values );
		if ( '' !== (string) $woo_customer_id ) {
			$body['wooCustomerId'] = (string) $woo_customer_id;
		}
		return $this->request(
			'POST',
			'/integrations/woocommerce/wholesale-form/submit?slug=' . rawurlencode( (string) $slug ),
			$body,
			self::INTERACTIVE_TIMEOUT
		);
	}

	/**
	 * Submit a net-terms (credit) application for a signed-in customer.
	 *
	 * Scope `buyers:write` on both routes. The body mirrors
	 * `SubmitCreditApplicationDto`; the caller has already validated it against the
	 * same bounds. Two routes, tried in this order:
	 *
	 *   1. `POST /storefront/v1/credit-application?buyerEmail=&buyerExternalId=`.
	 *      The applicant is the ASSERTED identity in the query (the account email
	 *      and the WordPress user id), never a body field: the DTO validates with
	 *      `forbidNonWhitelisted`, so an extra body key is a 400. This route also
	 *      notifies the seller's reviewers.
	 *   2. The legacy `POST /integrations/woocommerce/credit-application`, only when
	 *      route 1 answers 404 (a server without v1 routes) or 501 (the workspace has
	 *      no active WooCommerce connection, which an asserted applicant needs). It
	 *      carries no user id: an older server has nowhere to put one.
	 *
	 * Never queued and never retried: a person is waiting on the answer.
	 *
	 * @param array $payload legalBusinessName, contactEmail, contactPhone, taxId,
	 *                       billingAddress, requestedLimit, requestedTermsDays,
	 *                       tradeReferences, notes.
	 * @return array|WP_Error `{status: received|already_pending, applicationId, linkedToBuyer}`.
	 */
	public function submit_credit_application( array $payload ) {
		$email = isset( $payload['contactEmail'] ) ? (string) $payload['contactEmail'] : '';
		if ( '' !== $email && ! get_transient( self::V1_MISSING_TRANSIENT ) ) {
			$result = $this->request(
				'POST',
				'/storefront/v1/credit-application?' . http_build_query( $this->buyer_query( $email ), '', '&', PHP_QUERY_RFC3986 ),
				$payload,
				self::INTERACTIVE_TIMEOUT,
				array( 'Authorization' => null )
			);
			$status = is_wp_error( $result ) ? self::error_status( $result ) : 0;
			if ( 404 === $status ) {
				set_transient( self::V1_MISSING_TRANSIENT, 1, HOUR_IN_SECONDS );
			}
			if ( 404 !== $status && 501 !== $status ) {
				return $result;
			}
		}
		return $this->request( 'POST', '/integrations/woocommerce/credit-application', $payload, self::INTERACTIVE_TIMEOUT );
	}

	/**
	 * The signed-in buyer's own wholesale unit price for one SKU, through the
	 * shared storefront contract.
	 *
	 * `GET /storefront/v1/wholesale-price?sku=&quantity=&buyerEmail=` answers
	 * `WholesalePriceResult`: `{status: priced, unitPrice, currency, quantity,
	 * accountSpecific}`, or a status carrying no price (`anonymous`, `unlinked`,
	 * `unpriced`, a currency mismatch). null when the server has no v1 routes, so
	 * the caller keeps the legacy `/storefront-pricing/resolve` answer.
	 *
	 * @param string $sku         Product SKU.
	 * @param int    $quantity    Quantity to price.
	 * @param string $buyer_email Signed-in buyer's email.
	 * @return array|WP_Error|null
	 */
	public function get_wholesale_price( $sku, $quantity, $buyer_email ) {
		return $this->storefront_v1_get(
			'wholesale-price',
			array(
				'sku'      => (string) $sku,
				'quantity' => (string) max( 1, (int) $quantity ),
			),
			$buyer_email
		);
	}

	/**
	 * Quantity-break ladder for one SKU through the shared storefront contract.
	 *
	 * `GET /storefront/v1/quantity-breaks?sku=&buyerEmail=` answers
	 * `QuantityBreakResult`: `{status: priced, currency, accountSpecific,
	 * rows: [{minQty, unitPrice, accountSpecific}]}`, or a status that carries no
	 * ladder (`anonymous`, `unlinked`, `unpriced`). Returns null when the server has
	 * no v1 routes (HTTP 404 — an older self-hosted TackQuote), so the caller can
	 * fall back to probing the legacy `/storefront-pricing/resolve` route.
	 *
	 * @param string $sku         Product SKU.
	 * @param string $buyer_email Signed-in buyer's email, or '' for anonymous.
	 * @return array|WP_Error|null The result, a WP_Error, or null when v1 is absent.
	 */
	public function get_quantity_breaks( $sku, $buyer_email = '' ) {
		return $this->storefront_v1_get( 'quantity-breaks', array( 'sku' => (string) $sku ), $buyer_email );
	}

	/**
	 * Order-limit notice for one SKU through the shared storefront contract.
	 *
	 * `GET /storefront/v1/order-limits?sku=&buyerEmail=` answers
	 * `OrderLimitsNoticeResult`: `{status: limited, accountSpecific, limits:
	 * [{limitType, sku, min, max, currency, message}]}` or `{status: none}`.
	 * Falls back to the legacy `/storefront-b2b/order-limits` route, which answers the
	 * same shape, when v1 is absent.
	 *
	 * @param string $sku         Product SKU.
	 * @param string $buyer_email Signed-in buyer's email, or ''.
	 * @return array|WP_Error
	 */
	public function get_order_limits( $sku, $buyer_email = '' ) {
		$query  = array( 'sku' => (string) $sku );
		$result = $this->storefront_v1_get( 'order-limits', $query, $buyer_email );
		if ( null !== $result ) {
			return $result;
		}
		if ( '' !== (string) $buyer_email ) {
			$query['buyerEmail'] = (string) $buyer_email;
		}
		return $this->request( 'GET', '/storefront-b2b/order-limits?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ), null, self::INTERACTIVE_TIMEOUT );
	}

	/**
	 * The buyer's group through the shared storefront contract.
	 *
	 * `GET /storefront/v1/buyer-group?buyerEmail=` answers `BuyerGroupResult`:
	 * `{status: grouped, name, code}`, `none`, `anonymous` or `unlinked`. Falls back
	 * to the legacy `/storefront-b2b/buyer-group` route (same shape) when v1 is absent.
	 *
	 * @param string $buyer_email Signed-in buyer's email.
	 * @return array|WP_Error
	 */
	public function get_buyer_group( $buyer_email ) {
		$result = $this->storefront_v1_get( 'buyer-group', array(), $buyer_email );
		if ( null !== $result ) {
			return $result;
		}
		return $this->request( 'GET', '/storefront-b2b/buyer-group?buyerEmail=' . rawurlencode( (string) $buyer_email ), null, self::INTERACTIVE_TIMEOUT );
	}

	/**
	 * One `/storefront/v1/{resource}` read, with the 404 memory the fallbacks rely on.
	 *
	 * The buyer is ASSERTED by this server with its secret key (`X-Api-Key` +
	 * `buyerEmail`), which is the trust model every plugin route has: the merchant's
	 * server vouches for who is signed in. No browser credential ever reaches this.
	 *
	 * @param string $route       `quantity-breaks`, `order-limits`, `buyer-group`.
	 * @param array  $query       Query parameters (sku, ...).
	 * @param string $buyer_email Signed-in buyer's email, or '' for anonymous.
	 * @return array|WP_Error|null null when the server has no v1 routes.
	 */
	private function storefront_v1_get( $route, array $query, $buyer_email ) {
		if ( get_transient( self::V1_MISSING_TRANSIENT ) ) {
			return null;
		}
		$query = array_merge( $query, $this->buyer_query( $buyer_email ) );
		$path  = '/storefront/v1/' . $route;
		if ( ! empty( $query ) ) {
			$path .= '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
		}
		// The key in X-Api-Key ONLY: v1 refuses a second credential (see request()).
		$result = $this->request( 'GET', $path, null, self::INTERACTIVE_TIMEOUT, array( 'Authorization' => null ) );
		if ( is_wp_error( $result ) && 404 === self::error_status( $result ) ) {
			set_transient( self::V1_MISSING_TRANSIENT, 1, HOUR_IN_SECONDS );
			return null;
		}
		return $result;
	}

	/**
	 * The asserted-buyer query parameters for a `/storefront/v1/*` call.
	 *
	 * `buyerEmail` when there is one. `buyerExternalId` — the WordPress user id,
	 * digits only — only beside an email AND only while a customer is signed in,
	 * so a guest never sends one and an id never travels without the email it
	 * belongs to (the server ignores a lone id; tack #726). A server older than
	 * that ignores the parameter.
	 *
	 * @param string $buyer_email Signed-in buyer's email, or ''.
	 * @return array<string,string>
	 */
	private function buyer_query( $buyer_email ) {
		$buyer_email = (string) $buyer_email;
		if ( '' === $buyer_email ) {
			return array();
		}
		$query   = array( 'buyerEmail' => $buyer_email );
		$user_id = is_user_logged_in() ? (int) get_current_user_id() : 0;
		if ( $user_id > 0 ) {
			$query['buyerExternalId'] = (string) $user_id;
		}
		return $query;
	}

	/**
	 * Has TackQuote approved the signed-in buyer's wholesale application?
	 *
	 * `GET /storefront/v1/price-access?buyerEmail=&buyerExternalId=` answers
	 * `PriceAccessResult`: `{status: anonymous}`, `{status: unlinked}` or
	 * `{status: linked, wholesaleApproved}` (tack `storefront-b2b.core.ts`). There is
	 * no legacy route to fall back to, so a server without v1 routes is an error here:
	 * the price gate that calls this fails CLOSED on any error.
	 *
	 * @since 1.10.0
	 *
	 * @param string $buyer_email Signed-in buyer's (trusted) email.
	 * @return array|WP_Error
	 */
	public function get_price_access( $buyer_email ) {
		$result = $this->storefront_v1_get( 'price-access', array(), $buyer_email );
		if ( null === $result ) {
			return new WP_Error( 'tack_v1_missing', __( 'This TackQuote server has no storefront price-access route.', 'tackquote' ), array( 'status' => 404 ) );
		}
		return $result;
	}

	/**
	 * The signed-in buyer's OWN net-terms standing.
	 *
	 * `GET /storefront/v1/net-terms?buyerEmail=&buyerExternalId=` answers
	 * `NetTermsResult` (tack `storefront-core/storefront-results.ts`):
	 * `{status: anonymous}`, `{status: unlinked, reason}` or `{status: standing,
	 * state, application, account: {status, termsDays, creditLimit, currency} | null}`
	 * (`storefront-net-terms.ts`). There is no legacy route, so a server without v1
	 * routes is an error: the net-terms gateway that calls this fails CLOSED on any
	 * error and is hidden.
	 *
	 * @since 1.10.0
	 *
	 * @param string $buyer_email Signed-in buyer's (trusted) email.
	 * @return array|WP_Error
	 */
	public function get_net_terms( $buyer_email ) {
		$result = $this->storefront_v1_get( 'net-terms', array(), $buyer_email );
		if ( null === $result ) {
			return new WP_Error( 'tack_v1_missing', __( 'This TackQuote server has no storefront net-terms route.', 'tackquote' ), array( 'status' => 404 ) );
		}
		return $result;
	}

	/**
	 * The HTTP status a WP_Error from request() carries, or 0 for a transport failure.
	 *
	 * @param WP_Error $error The failure.
	 * @return int
	 */
	private static function error_status( $error ) {
		$data = $error->get_error_data();
		return is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
	}
}
