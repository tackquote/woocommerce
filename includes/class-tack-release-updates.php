<?php
/**
 * WordPress updates for a store that installed the zip from the TackQuote website.
 *
 * THIS FILE SHIPS ONLY IN THE GITHUB RELEASE ZIP. bin/build.sh removes it from the
 * WordPress.org build, and adds the `Update URI` header to the GitHub build only:
 * WordPress.org updates its own installs, and a plugin hosted there may not carry an
 * updater or an `Update URI` pointing elsewhere (Plugin Check: `plugin_updater_detected`).
 *
 * How it works (WordPress core, wp-includes/update.php, since 5.8):
 *
 *   - The GitHub build's header reads `Update URI: https://github.com/tackquote/woocommerce`.
 *     A plugin whose Update URI is not its wordpress.org address gets no answer from the
 *     wordpress.org update API ("If the header has any other value, the API will not
 *     return any result", make.wordpress.org/core 2021-06-29), so the directory can never
 *     overwrite it.
 *   - WordPress then asks `update_plugins_{$hostname}` (here `update_plugins_github.com`)
 *     for that plugin, with its headers and file. This answers from the repository's
 *     GitHub "latest" release (GitHub's API excludes drafts and pre-releases): the tag
 *     `vX.Y.Z` is the version, and the release asset `tackquote.zip` (the same file the
 *     website's Download button serves) is the package. WordPress shows "Update
 *     available" and installs it like any other update.
 *   - `plugins_api` answers the "View details" box for this plugin from the same release.
 *
 * The filter answers only for THIS plugin file with THIS Update URI: other plugins that
 * also use a github.com Update URI pass through untouched. The release is read at most
 * every twelve hours (one hour after a failure) and never on a storefront request.
 *
 * @package TackQuotes
 * @since   1.12.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GitHub release updates for zip installs.
 */
class Tack_Release_Updates {

	const UPDATE_URI   = 'https://github.com/tackquote/woocommerce';
	const RELEASES_API = 'https://api.github.com/repos/tackquote/woocommerce/releases/latest';
	const ASSET        = 'tackquote.zip';
	const SLUG         = 'tackquote';
	const DETAILS_URL  = 'https://tackquote.com/integrations/woocommerce';

	/**
	 * Cached release: `{version, package, url, notes, until}` or `{failed, until}`.
	 */
	const CACHE = 'tack_quotes_github_release';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'filter_update' ), 10, 3 );
		add_filter( 'plugins_api', array( __CLASS__, 'filter_details' ), 10, 3 );
	}

	/**
	 * Is this the plugin, carrying this build's Update URI?
	 *
	 * @param array  $plugin_data Headers.
	 * @param string $plugin_file Plugin file.
	 * @return bool
	 */
	private static function is_ours( $plugin_data, $plugin_file ) {
		return plugin_basename( TACK_QUOTES_FILE ) === $plugin_file
			&& is_array( $plugin_data )
			&& self::UPDATE_URI === untrailingslashit( (string) ( $plugin_data['UpdateURI'] ?? '' ) );
	}

	/**
	 * `update_plugins_github.com`.
	 *
	 * @param array|false $update      Update data from another filter, or false.
	 * @param array       $plugin_data Plugin headers.
	 * @param string      $plugin_file Plugin file.
	 * @return array|false
	 */
	public static function filter_update( $update, $plugin_data, $plugin_file ) {
		if ( ! self::is_ours( $plugin_data, $plugin_file ) ) {
			return $update;
		}
		$release = self::release();
		if ( null === $release ) {
			return $update;
		}
		return array(
			'id'      => self::UPDATE_URI,
			'slug'    => self::SLUG,
			'version' => $release['version'],
			'url'     => self::DETAILS_URL,
			'package' => $release['package'],
		);
	}

	/**
	 * `plugins_api`: the "View details" box for this plugin.
	 *
	 * @param false|object|array $result Result so far.
	 * @param string             $action API action.
	 * @param object             $args   Arguments.
	 * @return false|object|array
	 */
	public static function filter_details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || self::SLUG !== ( $args->slug ?? '' ) ) {
			return $result;
		}
		$release = self::release();
		if ( null === $release ) {
			return $result;
		}
		return (object) array(
			'name'          => 'TackQuote: Request a Quote & B2B Wholesale for WooCommerce',
			'slug'          => self::SLUG,
			'version'       => $release['version'],
			'author'        => 'TackQuote',
			'homepage'      => self::DETAILS_URL,
			'download_link' => $release['package'],
			'sections'      => array(
				'changelog' => '<p><a href="' . esc_url( $release['url'] ) . '">' . esc_html__( 'Release notes on GitHub', 'tackquote' ) . '</a></p>' . wp_kses_post( wpautop( $release['notes'] ) ),
			),
		);
	}

	/**
	 * The latest GitHub release, cached; null when unknown.
	 *
	 * @return array{version:string,package:string,url:string,notes:string}|null
	 */
	public static function release() {
		$cached = get_site_transient( self::CACHE );
		if ( is_array( $cached ) && (int) ( $cached['until'] ?? 0 ) > time() ) {
			return isset( $cached['version'] ) ? $cached : null;
		}
		$release = self::fetch();
		$failed  = array(
			'failed' => true,
			'until'  => time() + HOUR_IN_SECONDS,
		);
		set_site_transient(
			self::CACHE,
			null === $release ? $failed : $release + array( 'until' => time() + 12 * HOUR_IN_SECONDS ),
			12 * HOUR_IN_SECONDS
		);
		return $release;
	}

	/**
	 * Read and check the release.
	 *
	 * @return array{version:string,package:string,url:string,notes:string}|null
	 */
	private static function fetch() {
		$response = wp_safe_remote_get(
			self::RELEASES_API,
			array(
				'timeout' => 5,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'TackQuotes-WooCommerce/' . TACK_QUOTES_VERSION,
				),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		return self::parse( json_decode( wp_remote_retrieve_body( $response ), true ) );
	}

	/**
	 * The update a release answer describes, or null. The tag must be `vX.Y.Z`, and the
	 * package must be this repository's own `tackquote.zip` asset for that tag.
	 *
	 * @param mixed $data Decoded GitHub release.
	 * @return array{version:string,package:string,url:string,notes:string}|null
	 */
	public static function parse( $data ) {
		if ( ! is_array( $data ) || ! empty( $data['draft'] ) || ! empty( $data['prerelease'] ) ) {
			return null;
		}
		$tag = isset( $data['tag_name'] ) && is_string( $data['tag_name'] ) ? $data['tag_name'] : '';
		if ( 1 !== preg_match( '/^v(\d{1,5}\.\d{1,5}\.\d{1,5})$/', $tag, $m ) ) {
			return null;
		}
		$want    = 'https://github.com/tackquote/woocommerce/releases/download/' . $tag . '/' . self::ASSET;
		$package = '';
		foreach ( isset( $data['assets'] ) && is_array( $data['assets'] ) ? $data['assets'] : array() as $asset ) {
			if ( is_array( $asset ) && self::ASSET === ( $asset['name'] ?? '' ) && ( $asset['browser_download_url'] ?? '' ) === $want ) {
				$package = $want;
			}
		}
		if ( '' === $package ) {
			return null;
		}
		$url = isset( $data['html_url'] ) && is_string( $data['html_url'] ) && 0 === strpos( $data['html_url'], 'https://github.com/tackquote/woocommerce/' ) ? $data['html_url'] : self::UPDATE_URI . '/releases';
		return array(
			'version' => $m[1],
			'package' => $package,
			'url'     => $url,
			'notes'   => isset( $data['body'] ) && is_string( $data['body'] ) ? substr( $data['body'], 0, 20000 ) : '',
		);
	}
}
