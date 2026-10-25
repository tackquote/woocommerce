<?php
/**
 * PHPStan bootstrap: the constants tackquote.php defines at load time.
 *
 * PHPStan reads code, it does not run the plugin, so define() calls in the main file
 * are not known while other files are analysed. Values are placeholders of the right
 * type. Loaded only by phpstan.neon.dist; never shipped.
 *
 * @package TackQuotes
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', '/' );
defined( 'TACK_QUOTES_VERSION' ) || define( 'TACK_QUOTES_VERSION', '0.0.0' );
defined( 'TACK_QUOTES_FILE' ) || define( 'TACK_QUOTES_FILE', dirname( __DIR__, 2 ) . '/tackquote.php' );
defined( 'TACK_QUOTES_DIR' ) || define( 'TACK_QUOTES_DIR', dirname( __DIR__, 2 ) . '/' );
defined( 'TACK_QUOTES_URL' ) || define( 'TACK_QUOTES_URL', 'https://example.test/wp-content/plugins/tackquote/' );
