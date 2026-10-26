<?php
/**
 * "Update available" on the TackQuote settings page (1.12.0).
 *
 * Two sources, the newer wins:
 *
 *   - WordPress's own plugin update check (the `update_plugins` site transient that
 *     WordPress fills, read-only here). For a WordPress.org install this is the
 *     WordPress.org release; for a store that installed the zip from the TackQuote
 *     website it is the GitHub release (see includes/class-tack-release-updates.php,
 *     present only in that build).
 *   - The newest release TackQuote names on its ping (`latestPluginVersion`, servers
 *     listing `plugin_release`), so the notice appears even before WordPress has checked.
 *
 * Nothing here changes how WordPress updates plugins; it only says an update exists and
 * links to where WordPress offers it.
 *
 * @package TackQuotes
 * @since   1.12.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The notice.
 */
class Tack_Update_Notice {

	/**
	 * Is `$candidate` a plain `X.Y.Z` newer than `$installed`?
	 *
	 * @param string $candidate Version offered.
	 * @param string $installed Version running.
	 * @return bool
	 */
	public static function is_newer( $candidate, $installed ) {
		return is_string( $candidate ) && 1 === preg_match( '/^\d{1,5}\.\d{1,5}\.\d{1,5}$/', $candidate )
			&& is_string( $installed ) && 1 === preg_match( '/^\d{1,5}\.\d{1,5}\.\d{1,5}/', $installed )
			&& version_compare( $candidate, $installed, '>' );
	}

	/**
	 * The update to announce, or null: `{version, wordpress_knows}`.
	 *
	 * @param Tack_Api_Client|null $client    Optional HTTP client (tests).
	 * @param string|null          $installed The running version (default TACK_QUOTES_VERSION).
	 * @return array{version:string,wordpress_knows:bool}|null
	 */
	public static function available( $client = null, $installed = null ) {
		$installed = null === $installed ? TACK_QUOTES_VERSION : (string) $installed;
		$best      = '';
		$known     = false;

		$updates = get_site_transient( 'update_plugins' );
		$file    = plugin_basename( TACK_QUOTES_FILE );
		if ( is_object( $updates ) && isset( $updates->response[ $file ] ) && is_object( $updates->response[ $file ] ) ) {
			$offered = isset( $updates->response[ $file ]->new_version ) ? (string) $updates->response[ $file ]->new_version : '';
			if ( self::is_newer( $offered, $installed ) ) {
				$best  = $offered;
				$known = true;
			}
		}

		if ( '' !== (string) get_option( 'tack_quotes_api_key', '' ) ) {
			$client = $client ? $client : new Tack_Api_Client();
			$named  = $client->latest_plugin_version();
			if ( self::is_newer( $named, '' !== $best ? $best : $installed ) ) {
				$best  = $named;
				$known = false;
			}
		}

		return '' === $best ? null : array(
			'version'         => $best,
			'wordpress_knows' => $known,
		);
	}

	/**
	 * Print the notice (the settings page calls this at the top).
	 *
	 * @param Tack_Api_Client|null $client Optional HTTP client (tests).
	 */
	public static function render( $client = null ) {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		$update = self::available( $client );
		if ( null === $update ) {
			return;
		}
		$text = sprintf(
			/* translators: 1: newest version, 2: installed version. */
			__( 'Update available: TackQuote: Request a Quote & B2B Wholesale for WooCommerce %1$s is out (this store runs %2$s).', 'tackquote' ),
			$update['version'],
			TACK_QUOTES_VERSION
		);
		if ( $update['wordpress_knows'] ) {
			printf(
				'<div class="notice notice-info tack-update-notice"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
				esc_html( $text ),
				esc_url( self_admin_url( 'update-core.php' ) ),
				esc_html__( 'Update now', 'tackquote' )
			);
			return;
		}
		printf(
			'<div class="notice notice-info tack-update-notice"><p>%1$s %2$s <a href="%3$s" target="_blank" rel="noopener noreferrer">%4$s</a></p></div>',
			esc_html( $text ),
			esc_html__( 'WordPress offers it under Dashboard → Updates once it checks for updates, or download it from TackQuote.', 'tackquote' ),
			esc_url( 'https://tackquote.com/integrations/woocommerce' ),
			esc_html__( 'Download', 'tackquote' )
		);
	}
}
