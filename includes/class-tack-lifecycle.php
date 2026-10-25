<?php
/**
 * Tell TackQuote when the plugin is deactivated (1.12.0).
 *
 * TackQuote then pauses this store's connection: nothing syncs to or from a store whose
 * plugin is off, and the connection page in TackQuote says so instead of showing a
 * store that silently stopped answering. When the plugin is activated again, its first
 * request to TackQuote resumes the SAME connection, so links between WordPress customers
 * and TackQuote buyers are kept.
 *
 * Deleting the plugin sends `uninstalled` from uninstall.php (which runs without the
 * plugin's own files, so it carries its own copy of this request).
 *
 * Best effort and short: a deactivation must never hang on TackQuote. Sent only when the
 * server lists the `lifecycle` capability (an older server has no such route).
 *
 * @package TackQuotes
 * @since   1.12.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The deactivation notice.
 */
class Tack_Lifecycle {

	/**
	 * Seconds the notice may take.
	 */
	const TIMEOUT = 3;

	/**
	 * Tell TackQuote the plugin was deactivated.
	 *
	 * @param Tack_Api_Client|null $client Optional HTTP client (tests).
	 * @return bool Whether TackQuote accepted it.
	 */
	public static function notify_deactivated( $client = null ) {
		if ( '' === (string) get_option( 'tack_quotes_api_key', '' ) ) {
			return false;
		}
		$client = $client ? $client : new Tack_Api_Client();
		if ( ! $client->supports( 'lifecycle' ) ) {
			return false;
		}
		$result = $client->request( 'POST', '/integrations/woocommerce/plugin-lifecycle', array( 'event' => 'deactivated' ), self::TIMEOUT );
		return ! is_wp_error( $result );
	}
}
