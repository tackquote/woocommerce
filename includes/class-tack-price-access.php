<?php
/**
 * Has TackQuote approved the signed-in customer's wholesale application? Read once, shared.
 *
 * @package TackQuotes
 * @since   1.11.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Reads `GET /storefront/v1/price-access` for one customer and caches the answer.
 *
 * Two places ask the same question: the catalogue's "unapproved" price gate
 * (Tack_Catalog_Mode::buyer_is_approved_wholesale(), may this buyer use the cart?)
 * and the My Account Wholesale account tab (Tack_Storefront_Forms, has this
 * customer's application already been approved?). Both go through read(), so a
 * page that shows both makes one API call, and the two can never disagree about
 * the same customer while the answer is cached (CACHE_TTL).
 *
 * The server answers `PriceAccessResult`: `{status: anonymous}`,
 * `{status: unlinked, reason}` or `{status: linked, wholesaleApproved}`.
 * `wholesaleApproved` is true only for a wholesale application in status
 * `approved`; a buyer linked any other way (a net-terms approval, a checkout) is
 * `linked` with `wholesaleApproved: false`. The answer carries NO pending state.
 *
 * The transient key and value shape are the ones the price gate has used since
 * 1.10.0 (`tack_pa_<salted hash>`, `{approved: bool}`, or `'unavailable'` for a
 * failed call), so a cache written by an earlier 1.10.x request is still read.
 * Kept free of WooCommerce classes.
 */
class Tack_Price_Access {

	/** Prefix of the per-customer transient (suffix: a salted hash, never the address). */
	const CACHE_PREFIX = 'tack_pa_';

	/**
	 * How long an answer is remembered, in seconds. Approval is a seller action
	 * that happens once; five minutes is the longest an approved buyer waits.
	 */
	const CACHE_TTL = 300;

	/** How long a FAILED call is remembered, so an outage does not retry per page view. */
	const FAIL_TTL = 60;

	/**
	 * The transient key for one customer: a salted hash of the address, because a
	 * transient key is visible in the options table and a shared object cache.
	 *
	 * @param int    $user_id WordPress user id.
	 * @param string $email   Trusted account email.
	 * @return string
	 */
	public static function cache_key( $user_id, $email ) {
		return self::CACHE_PREFIX . substr( wp_hash( 'price-access|' . strtolower( (string) $email ) . '|' . (int) $user_id ), 0, 20 );
	}

	/**
	 * Is the customer's wholesale application approved?
	 *
	 * @param Tack_Api_Client $client  API client.
	 * @param int             $user_id WordPress user id.
	 * @param string          $email   Trusted account email ('' reads nothing).
	 * @return bool|WP_Error True or false as TackQuote answered; a WP_Error when it could not
	 *                       be asked or did not answer (each caller decides how to fail).
	 */
	public static function approved( $client, $user_id, $email ) {
		$email = (string) $email;
		if ( '' === $email ) {
			return new WP_Error( 'tack_price_access_no_email', 'No trusted customer email.' );
		}
		// The transient is the shared memo: within one request WordPress serves a
		// second get_transient() from its in-memory option/object cache.
		$key    = self::cache_key( $user_id, $email );
		$cached = get_transient( $key );
		if ( is_array( $cached ) && array_key_exists( 'approved', $cached ) ) {
			return (bool) $cached['approved'];
		}
		if ( 'unavailable' === $cached ) {
			return new WP_Error( 'tack_price_access_unavailable', 'price-access failed recently; not asked again yet.' );
		}

		// `buyerEmail` + `buyerExternalId` (the WordPress user id) are added by the
		// client's shared asserted-buyer query, key in `X-Api-Key` only.
		$response = $client->get_price_access( $email );
		if ( is_wp_error( $response ) ) {
			set_transient( $key, 'unavailable', self::FAIL_TTL );
			return $response;
		}
		if ( ! is_array( $response ) || ! isset( $response['status'] ) ) {
			set_transient( $key, 'unavailable', self::FAIL_TTL );
			return new WP_Error( 'tack_price_access_shape', 'Unexpected price-access answer.' );
		}

		$approved = 'linked' === $response['status'] && true === ( isset( $response['wholesaleApproved'] ) ? $response['wholesaleApproved'] : null );
		set_transient( $key, array( 'approved' => $approved ), self::CACHE_TTL );
		return $approved;
	}
}
