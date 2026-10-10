<?php
/**
 * The signed-in customer's net-terms standing, read once and shared.
 *
 * @package TackQuotes
 * @since   1.10.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Reads `GET /storefront/v1/net-terms` for one customer and caches the answer.
 *
 * Two places ask the same question: the "Net terms (TackQuote)" payment gateway
 * (Tack_Gateway_Net_Terms, is the method offered at checkout?) and the My Account
 * Net terms tab (Tack_Storefront_Forms, does the customer already have terms?).
 * Both go through read(), so a page that shows both makes one API call, and the
 * two can never disagree about the same customer within a minute.
 *
 * Only a successful answer is cached, and only for the email it was read for. An
 * error is never cached, so the next page asks again as soon as TackQuote answers.
 * Kept free of WooCommerce classes, so it loads whether or not the gateway does.
 */
class Tack_Net_Terms_Standing {

	/** Prefix of the per-customer standing transient (suffix: WordPress user id). */
	const CACHE_PREFIX = 'tack_nt_';

	/** Seconds a standing answer is reused for the same customer. */
	const CACHE_TTL = 60;

	/**
	 * Standing answers already read in THIS request, keyed by user id.
	 *
	 * @var array<int,array{hash:string,answer:array}>
	 */
	private static $request_cache = array();

	/**
	 * The customer's standing answer, cached for the request and for CACHE_TTL seconds.
	 *
	 * @param Tack_Api_Client $client  API client.
	 * @param int             $user_id WordPress user id.
	 * @param string          $email   Trusted account email (Tack_Storefront_Forms::trusted_account_email()).
	 * @param bool            $fresh   Bypass both caches (an order being placed on terms).
	 * @return array|WP_Error The decoded `NetTermsResult`, or why it could not be read.
	 */
	public static function read( $client, $user_id, $email, $fresh = false ) {
		$user_id = (int) $user_id;
		$key     = self::CACHE_PREFIX . $user_id;
		$hash    = md5( strtolower( (string) $email ) );
		if ( ! $fresh ) {
			if ( isset( self::$request_cache[ $user_id ] ) && $hash === self::$request_cache[ $user_id ]['hash'] ) {
				return self::$request_cache[ $user_id ]['answer'];
			}
			$cached = get_transient( $key );
			if ( is_array( $cached ) && isset( $cached['hash'], $cached['answer'] ) && $hash === $cached['hash'] && is_array( $cached['answer'] ) ) {
				self::$request_cache[ $user_id ] = $cached;
				return $cached['answer'];
			}
		}
		$answer = $client->get_net_terms( $email );
		if ( is_wp_error( $answer ) ) {
			unset( self::$request_cache[ $user_id ] );
			return $answer;
		}
		if ( ! is_array( $answer ) ) {
			return new WP_Error( 'tack_net_terms_shape', 'Unexpected net-terms answer.' );
		}
		$entry                           = array(
			'hash'   => $hash,
			'answer' => $answer,
		);
		self::$request_cache[ $user_id ] = $entry;
		set_transient( $key, $entry, self::CACHE_TTL );
		return $answer;
	}

	/**
	 * Forget every standing read in this request (tests; a long-running worker).
	 */
	public static function reset_request_cache() {
		self::$request_cache = array();
	}
}
