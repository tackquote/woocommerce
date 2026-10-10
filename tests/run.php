<?php
/**
 * Regression tests for the plugin's admin entry point.
 *
 * Run: php tests/run.php   (no PHPUnit, no WordPress required)
 */

require __DIR__ . '/wp-stubs.php';
// The application-form and checkout-link limits (1.10.0 standards audit) are switched off
// for every suite that is not about them; standards-audit-test.php switches them back on.
tack_test_without_rate_limits();

define( 'ABSPATH', '/' );
define( 'TACK_QUOTES_FILE', dirname( __DIR__ ) . '/tackquote.php' );
define( 'TACK_QUOTES_DIR', dirname( __DIR__ ) . '/' );
define( 'TACK_QUOTES_URL', 'https://shop.example/wp-content/plugins/tackquote/' );
define( 'TACK_QUOTES_VERSION', 'test' );

require TACK_QUOTES_DIR . 'includes/class-tack-settings.php';
require TACK_QUOTES_DIR . 'includes/class-tack-quotes.php';

$failures = 0;
function check( $label, $condition, $detail = '' ) {
	global $failures;
	if ( $condition ) {
		echo "  PASS  $label\n";
		return;
	}
	$failures++;
	echo "  FAIL  $label" . ( $detail ? "\n        $detail" : '' ) . "\n";
}

// Register the menu exactly as WordPress would on `admin_menu`.
$settings = new Tack_Settings();
$settings->add_menu();
$registered = $GLOBALS['TACK_REGISTERED_PAGES'];

check( 'the plugin registers exactly one admin page', 1 === count( $registered ),
	'registered: ' . implode( ', ', array_keys( $registered ) ) );

// Build the Plugins-screen "Settings" link exactly as WordPress would.
$reflection = new ReflectionClass( 'Tack_Quotes' );
$plugin     = $reflection->newInstanceWithoutConstructor();
$links      = $plugin->action_links( array() );

check( 'action_links returns a link', ! empty( $links ) && is_string( $links[0] ) );

preg_match( '/[?&]page=([^"\'&]+)/', $links[0], $m );
$linked = isset( $m[1] ) ? urldecode( $m[1] ) : '(none)';

/*
 * THE REGRESSION. The Settings link once carried a hardcoded 'tack-quotes'
 * while the menu registered 'tackquote-for-woocommerce'. Clicking Settings
 * landed on an unregistered page, and WordPress reported
 * "Sorry, you are not allowed to access this page." — which reads as a
 * capability bug and is not one. Derive the slug from the constant instead.
 */
check(
	'the Settings link points at a REGISTERED admin page',
	array_key_exists( $linked, $registered ),
	"link slug '$linked' is not among registered: " . implode( ', ', array_keys( $registered ) )
);

check(
	'the Settings link slug equals Tack_Settings::PAGE_SLUG',
	$linked === Tack_Settings::PAGE_SLUG,
	"link '$linked' vs constant '" . Tack_Settings::PAGE_SLUG . "'"
);

// The page holds the API key that authenticates the whole store to TackQuote.
check(
	'the admin page requires manage_options',
	'manage_options' === ( $registered[ Tack_Settings::PAGE_SLUG ] ?? null )
);

echo "\n-- quote-only (B2B catalog) mode --\n";
require __DIR__ . '/catalog-mode-test.php';

echo "\n-- order-sync payload contract --\n";
require __DIR__ . '/order-payload-test.php';

// B2B pricing resolved by Tack. Requires the API client, which the pricing class
// takes by injection so no HTTP is ever attempted here.
require_once TACK_QUOTES_DIR . 'includes/class-tack-api-client.php';
require_once TACK_QUOTES_DIR . 'includes/class-tack-tax-basis.php';
require_once TACK_QUOTES_DIR . 'includes/class-tack-wholesale-pricing.php';
// Pricing reads the shared email-trust rule from the notices class.
require_once TACK_QUOTES_DIR . 'includes/class-tack-b2b-notices.php';
require __DIR__ . '/wholesale-pricing-test.php';

// Order limits and the buyer-group badge.
require_once TACK_QUOTES_DIR . 'includes/class-tack-b2b-notices.php';
require __DIR__ . '/b2b-notices-test.php';

// Payment/shipping methods gated by buyer group.
require_once TACK_QUOTES_DIR . 'includes/class-tack-group-restrictions.php';
require __DIR__ . '/group-restrictions-test.php';

// 1.10.0: catalogue visibility, shipping discounts per group, role mirror.
require __DIR__ . '/group-catalog-test.php';

// Wholesale + net-terms application forms, the storefront v1 reads and the
// plugin-version header every request carries.
echo "\n-- storefront forms: wholesale application, net terms, v1 reads, version header --\n";
require_once TACK_QUOTES_DIR . 'includes/class-tack-storefront-forms.php';
require_once TACK_QUOTES_DIR . 'includes/class-tack-tax-exempt.php';
require __DIR__ . '/storefront-forms-test.php';

// The admin surface: how the settings page is grouped, and what a save does to
// rules the merchant already had.
echo "\n-- settings page structure + rule editing --\n";
require __DIR__ . '/settings-page-test.php';

// 1.10.0: one form and one option group per tab; a save never resets another tab.
echo "\n-- settings tabs: per-tab option groups, overview --\n";
require __DIR__ . '/settings-tabs-test.php';

// Order sync stops on a TERMINAL refusal (401/403 from TackQuote) and backs off on 429.
echo "\n-- order-sync gate: terminal 401/403, 429 back-off, admin notice --\n";
require __DIR__ . '/sync-gate-test.php';

// 1.10.0 storefront parity: card/cart buttons, launcher settings, quote page,
// per-product quote-only + Store API hooks, approved-wholesale price gate.
echo "\n-- 1.10.0 storefront parity --\n";
require __DIR__ . '/storefront-parity-test.php';

// Block themes: product-page buttons and order-limit notice on the Add to Cart blocks.
echo "\n-- block-theme product page (render_block_woocommerce/add-to-cart-*) --\n";
require __DIR__ . '/block-theme-buttons-test.php';

// 1.10.0 net-terms payment gateway, its Checkout block integration, the PO field.
echo "\n-- net-terms gateway + Blocks + PO number --\n";
require __DIR__ . '/net-terms-gateway-test.php';

// Bundled translations: generated .mo/.po/JED, the mapping, and the wp.i18n wiring.
echo "\n-- translations (languages/) --\n";
require __DIR__ . '/i18n-test.php';

// 1.10.0 accepted quote -> store checkout link (parity row 10).
echo "\n-- quote checkout link --\n";
require __DIR__ . '/quote-checkout-test.php';

// Tack_Tax_Basis: net TackQuote prices on inclusive / exclusive stores.
require __DIR__ . '/tax-basis-test.php';

// 1.10.0 follow-ups: quote-only variable products keep their variation form; volume table on block themes.
echo "\n-- storefront follow-ups (variation form on quote, volume table on blocks) --\n";
require __DIR__ . '/storefront-followups-test.php';

// 1.10.0 attachments: quote-request files and wholesale-form file fields.
echo "\n-- attachments (quote uploads, wholesale files) --\n";
require __DIR__ . '/attachments-test.php';

// 1.10.0 Add to Cart with Options block, blockified mode: buttons after its form.
echo "\n-- add-to-cart-with-options (blockified) --\n";
require __DIR__ . '/with-options-block-test.php';

// 1.10.0 connection test: only an authenticated ping is "Connected" (defect D1).
echo "\n-- connection test (401/403 rejected, 404 unverified, ping only) --\n";
require __DIR__ . '/connection-test-test.php';

// 1.10.0 standards audit: email trust on every path, rate limits, quotable products,
// no redirects with the key, uninstall completeness, privacy exporter/eraser.
echo "\n-- standards audit (W7) --\n";
require __DIR__ . '/standards-audit-test.php';

// 1.10.0 theme blend: theme-derived CSS, styling settings, overridable templates, filters.
echo "\n-- theme blend and customisation --\n";
require __DIR__ . '/theme-blend-test.php';

// 1.10.0 final polish: version floors, application refill minimisation, order privacy export.
echo "\n-- final polish (W9) --\n";
require __DIR__ . '/final-polish-test.php';

echo $failures ? "\n$failures failure(s)\n" : "\nAll checks passed\n";
exit( $failures ? 1 : 0 );
