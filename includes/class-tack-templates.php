<?php
/**
 * Storefront templates a theme can override.
 *
 * Every storefront template ships in this plugin's `templates/tackquote/` folder
 * and is loaded through WooCommerce's own template loader, `wc_get_template()`,
 * with this plugin's folder as the default path. `wc_locate_template()` looks in
 * the active theme first, in this order (woocommerce/includes/wc-core-functions.php,
 * WooCommerce 11.2.1):
 *
 *   yourtheme/woocommerce/tackquote/<file>   (WC()->template_path() is `woocommerce/`)
 *   yourtheme/tackquote/<file>
 *   wp-content/plugins/tackquote/templates/tackquote/<file>
 *
 * so a theme (or child theme) copies a file into `woocommerce/tackquote/` and
 * edits it, the same way it overrides WooCommerce's own templates. The
 * `woocommerce_locate_template` and `wc_get_template` filters apply too.
 *
 * Each template carries an `@version`; it changes when the template's markup
 * contract changes, so a merchant can tell an outdated override.
 *
 * @package TackQuote
 * @since   1.10.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Loads the plugin's overridable storefront templates.
 *
 * @since 1.10.0
 */
class Tack_Templates {

	/**
	 * Sub-folder of both the theme's WooCommerce folder and the plugin's templates folder.
	 */
	const FOLDER = 'tackquote/';

	/**
	 * The plugin's own templates folder (the last place WooCommerce looks).
	 *
	 * @return string Absolute path with a trailing slash.
	 */
	public static function default_path() {
		return TACK_QUOTES_DIR . 'templates/';
	}

	/**
	 * Print a template.
	 *
	 * @param string $name Template file under `tackquote/`, e.g. `quote-page.php`.
	 * @param array  $args Variables the template receives.
	 * @return void
	 */
	public static function render( $name, array $args = array() ) {
		if ( ! function_exists( 'wc_get_template' ) ) {
			return;
		}
		wc_get_template( self::FOLDER . $name, $args, '', self::default_path() );
	}

	/**
	 * Return a template's output.
	 *
	 * @param string $name Template file under `tackquote/`.
	 * @param array  $args Variables the template receives.
	 * @return string
	 */
	public static function html( $name, array $args = array() ) {
		if ( ! function_exists( 'wc_get_template_html' ) ) {
			return '';
		}
		return (string) wc_get_template_html( self::FOLDER . $name, $args, '', self::default_path() );
	}
}
