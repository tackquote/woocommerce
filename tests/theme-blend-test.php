<?php
/**
 * 1.10.0 theme blend and customisation: the storefront takes the theme's look by
 * default, and a merchant or developer can change it without editing the plugin.
 *
 * - Storefront CSS carries no colour of its own: every hex / rgb() / hsl() value
 *   is a var() fallback (scanned), and the --tackquote-* custom properties fall
 *   back to the theme's theme.json presets.
 * - "Use the theme's styles only" loads the layout sheet alone; the accent colour
 *   is sanitised and inlined only when set.
 * - Storefront markup comes from templates located by wc_get_template(), so a
 *   theme file at woocommerce/tackquote/<file> wins; every new filter is applied.
 *
 * Run: php tests/run.php   (no PHPUnit, no WordPress required)
 *
 * @package TackQuotes
 */

// ── CSS scan ────────────────────────────────────────────────────────────────

/**
 * Colour literals (hex, rgb[a](), hsl[a]()) found OUTSIDE a var() fallback.
 *
 * Walks the stylesheet character by character with a stack of open parentheses;
 * a literal is allowed only while some enclosing `var(` has passed its first
 * comma (i.e. sits in the fallback). Comments are removed first.
 *
 * @param string $css Stylesheet.
 * @return string[] Offending literals.
 */
function tack_blend_bare_colours( $css ) {
	$css   = preg_replace( '#/\*.*?\*/#s', '', $css );
	$bad   = array();
	$stack = array(); // Each: array( 'var' => bool, 'fallback' => bool ).
	$len   = strlen( $css );
	for ( $i = 0; $i < $len; $i++ ) {
		$c = $css[ $i ];
		if ( '(' === $c ) {
			$is_var  = (bool) preg_match( '/var\s*$/', substr( $css, max( 0, $i - 4 ), min( 4, $i ) ) );
			$stack[] = array(
				'var'      => $is_var,
				'fallback' => false,
			);
			continue;
		}
		if ( ')' === $c ) {
			array_pop( $stack );
			continue;
		}
		if ( ',' === $c && ! empty( $stack ) ) {
			$top = count( $stack ) - 1;
			if ( $stack[ $top ]['var'] ) {
				$stack[ $top ]['fallback'] = true;
			}
			continue;
		}
		$in_fallback = false;
		foreach ( $stack as $frame ) {
			if ( $frame['var'] && $frame['fallback'] ) {
				$in_fallback = true;
			}
		}
		if ( $in_fallback ) {
			continue;
		}
		if ( '#' === $c && preg_match( '/^#([0-9a-fA-F]{3,8})\b/', substr( $css, $i, 10 ), $m ) ) {
			// A selector like `#tack-quote-page` is not a colour: colours live in values,
			// i.e. after a ':' on the same declaration.
			$line_start = strrpos( substr( $css, 0, $i ), "\n" );
			$segment    = substr( $css, false === $line_start ? 0 : $line_start, $i - ( false === $line_start ? 0 : $line_start ) );
			if ( false !== strpos( $segment, ':' ) && ! preg_match( '/[{,]\s*$/', $segment ) ) {
				$bad[] = $m[0];
			}
			continue;
		}
		if ( preg_match( '/^(rgba?|hsla?)\(/i', substr( $css, $i, 5 ), $m ) && ( 0 === $i || ! preg_match( '/[a-z-]/i', $css[ $i - 1 ] ) ) ) {
			$bad[] = $m[1] . '(';
		}
	}
	return $bad;
}

$tack_css_files = array( 'assets/css/tack-quotes.css', 'assets/css/tack-quotes-layout.css' );
foreach ( $tack_css_files as $tack_css_file ) {
	$tack_css = (string) file_get_contents( TACK_QUOTES_DIR . $tack_css_file );
	$tack_bad = tack_blend_bare_colours( $tack_css );
	check( "$tack_css_file: no hard-coded colour outside a var() fallback", array() === $tack_bad, implode( ' ', $tack_bad ) );
}

// The scanner itself: a bare colour is caught, a fallback is not (positive and negative control).
check( 'scanner control: a bare hex value is reported', array( '#2563eb' ) === tack_blend_bare_colours( '.a { color: #2563eb; }' ) );
check( 'scanner control: a bare rgba() is reported', array( 'rgba(' ) === tack_blend_bare_colours( '.a { background: rgba(0,0,0,.5); }' ) );
check( 'scanner control: a var() fallback is allowed', array() === tack_blend_bare_colours( '.a { color: var(--x, var(--y, #fff)); box-shadow: var(--s, 0 1px 2px rgb(0 0 0 / .2)); }' ) );
check( 'scanner control: an id selector is not a colour', array() === tack_blend_bare_colours( "#tack-quote-page .a,\n#abc {\n  color: inherit;\n}" ) );

$tack_layout = (string) file_get_contents( TACK_QUOTES_DIR . 'assets/css/tack-quotes-layout.css' );
$tack_main   = (string) file_get_contents( TACK_QUOTES_DIR . 'assets/css/tack-quotes.css' );
$tack_all    = $tack_layout . $tack_main;
check( 'panels take the theme surface: --tackquote-surface falls back to the base preset', false !== strpos( $tack_layout, 'var(--tackquote-surface, var(--wp--preset--color--base,' ) );
check( 'panels take the theme text: --tackquote-text falls back to the contrast preset', false !== strpos( $tack_layout, 'var(--tackquote-text, var(--wp--preset--color--contrast,' ) );
foreach ( array( '--tackquote-accent', '--tackquote-radius', '--tackquote-border', '--tackquote-shadow', '--tackquote-z-index', '--tackquote-drawer-width', '--tackquote-launcher-offset-x', '--tackquote-launcher-offset-y', '--tackquote-launcher-size', '--tackquote-gap', '--tackquote-space', '--tackquote-overlay' ) as $tack_prop ) {
	check( "custom property $tack_prop is read with a fallback", (bool) preg_match( '/var\(' . preg_quote( $tack_prop, '/' ) . ',/', $tack_all ), $tack_prop );
}
check( 'no font-family is imposed (the theme\'s type is inherited)', false === stripos( $tack_all, 'font-family' ) );
check( 'the drawer/modal animation only runs without prefers-reduced-motion', false !== strpos( $tack_main, '@media (prefers-reduced-motion: no-preference)' ) && 1 === substr_count( $tack_main, 'animation:' ) && strpos( $tack_main, '@media (prefers-reduced-motion: no-preference)' ) < strpos( $tack_main, 'animation:' ) );
check( 'RTL: the layout uses logical sides (no physical left/right offsets)', ! preg_match( '/^\s*(left|right|margin-left|margin-right|padding-left|padding-right)\s*:/m', $tack_all ) );
check( 'focus is visible on plugin-only controls', false !== strpos( $tack_main, ':focus-visible' ) && false !== strpos( $tack_main, 'outline: 2px solid var(--tackquote-focus' ) );

// ── Stylesheets and accent ──────────────────────────────────────────────────

/**
 * Reset the enqueue recorders.
 */
function tack_blend_reset_styles() {
	$GLOBALS['TACK_ENQUEUED_STYLES'] = array();
	$GLOBALS['TACK_INLINE_STYLES']   = array();
}

tack_parity_reset();
tack_blend_reset_styles();
Tack_Widget::enqueue_styles();
check( 'default: the layout and appearance sheets are both loaded', isset( $GLOBALS['TACK_ENQUEUED_STYLES']['tackquote-layout'], $GLOBALS['TACK_ENQUEUED_STYLES']['tackquote'] ) );
check( 'default: no inline CSS when no accent is set', array() === $GLOBALS['TACK_INLINE_STYLES'], wp_json_encode( $GLOBALS['TACK_INLINE_STYLES'] ) );

tack_blend_reset_styles();
$GLOBALS['TACK_OPTIONS'][ Tack_Widget::OPT_THEME_STYLES_ONLY ] = 'yes';
Tack_Widget::enqueue_styles();
check( '"theme styles only": only the layout sheet is loaded', isset( $GLOBALS['TACK_ENQUEUED_STYLES']['tackquote-layout'] ) && ! isset( $GLOBALS['TACK_ENQUEUED_STYLES']['tackquote'] ), wp_json_encode( array_keys( $GLOBALS['TACK_ENQUEUED_STYLES'] ) ) );

$GLOBALS['TACK_OPTIONS'][ Tack_Widget::OPT_ACCENT_COLOR ] = '#0a7c55';
tack_blend_reset_styles();
Tack_Widget::enqueue_styles();
$tack_inline = implode( '', $GLOBALS['TACK_INLINE_STYLES']['tackquote-layout'] ?? array() );
check( '"theme styles only" + accent: the accent is inlined on the layout handle', false !== strpos( $tack_inline, '--tackquote-accent:#0a7c55' ), $tack_inline );

unset( $GLOBALS['TACK_OPTIONS'][ Tack_Widget::OPT_THEME_STYLES_ONLY ] );
tack_blend_reset_styles();
Tack_Widget::enqueue_styles();
$tack_inline = implode( '', $GLOBALS['TACK_INLINE_STYLES']['tackquote'] ?? array() );
check( 'accent set: inlined once, on the appearance handle', 1 === count( $GLOBALS['TACK_INLINE_STYLES'] ) && false !== strpos( $tack_inline, ':root{--tackquote-accent:#0a7c55;--tackquote-accent-text:#fff}' ), wp_json_encode( $GLOBALS['TACK_INLINE_STYLES'] ) );
check( 'accent set: the plugin\'s primary buttons are repainted with it', false !== strpos( $tack_inline, 'html:root body .button.tack-quote-btn.tack-quote-btn{' ) || false !== strpos( $tack_inline, 'html:root body .button.tack-quote-btn.tack-quote-btn,' ) );

foreach ( array( 'red;}body{display:none}', '#12345g', 'javascript:alert(1)', '#0a7c55;}x{', 'rgb(1,2,3)' ) as $tack_evil ) {
	$GLOBALS['TACK_OPTIONS'][ Tack_Widget::OPT_ACCENT_COLOR ] = $tack_evil;
	tack_blend_reset_styles();
	Tack_Widget::enqueue_styles();
	check( 'a stored accent that is not a hex colour is never printed: ' . $tack_evil, array() === $GLOBALS['TACK_INLINE_STYLES'] && '' === Tack_Widget::accent_color(), wp_json_encode( $GLOBALS['TACK_INLINE_STYLES'] ) );
}
check( 'accent_css() refuses a non-hex value even if called directly', '' === Tack_Widget::accent_css( 'red;}body{x' ) );
unset( $GLOBALS['TACK_OPTIONS'][ Tack_Widget::OPT_ACCENT_COLOR ] );

check( 'readable text: white on black', '#fff' === Tack_Widget::readable_text_on( '#000' ) );
check( 'readable text: black on white', '#000' === Tack_Widget::readable_text_on( '#ffffff' ) );
check( 'readable text: black on yellow (#ff0)', '#000' === Tack_Widget::readable_text_on( '#ff0' ) );
check( 'readable text: white on WordPress blue (#2271b1)', '#fff' === Tack_Widget::readable_text_on( '#2271b1' ) );

tack_test_add_filter_return( 'tackquote_theme_styles_only', true );
tack_blend_reset_styles();
Tack_Widget::enqueue_styles();
check( 'filter tackquote_theme_styles_only lets a theme force layout-only', ! isset( $GLOBALS['TACK_ENQUEUED_STYLES']['tackquote'] ) );
tack_test_clear_filter_returns();

// The setting's sanitiser.
$tack_settings                   = new Tack_Settings();
$GLOBALS['TACK_SETTINGS_ERRORS'] = array();
check( 'sanitize_accent_color keeps a hex colour', '#0a7c55' === $tack_settings->sanitize_accent_color( ' #0a7c55 ' ) );
check( 'sanitize_accent_color: empty means "the theme\'s"', '' === $tack_settings->sanitize_accent_color( '' ) );
$GLOBALS['TACK_OPTIONS'][ Tack_Widget::OPT_ACCENT_COLOR ] = '#111111';
check( 'sanitize_accent_color refuses CSS injection and keeps the saved value', '#111111' === $tack_settings->sanitize_accent_color( 'red;}body{display:none}' ) && 1 === count( $GLOBALS['TACK_SETTINGS_ERRORS'] ) );
check( 'sanitize_accent_color refuses a non-string', '' === $tack_settings->sanitize_accent_color( array( '#fff' ) ) );
unset( $GLOBALS['TACK_OPTIONS'][ Tack_Widget::OPT_ACCENT_COLOR ] );

// ── Templates ───────────────────────────────────────────────────────────────

foreach ( array( 'quote-list-drawer.php', 'quote-page.php', 'single-product/quantity-breaks.php', 'myaccount/form-wholesale-application.php', 'myaccount/form-net-terms-application.php', 'myaccount/net-terms-account.php', 'myaccount/wholesale-account.php' ) as $tack_tpl ) {
	$tack_src = (string) file_get_contents( TACK_QUOTES_DIR . 'templates/tackquote/' . $tack_tpl );
	check( "template $tack_tpl: direct access is refused and it carries an @version", false !== strpos( $tack_src, "defined( 'ABSPATH' ) || exit;" ) && (bool) preg_match( '/@version\s+\d+\.\d+\.\d+/', $tack_src ) && false !== strpos( $tack_src, 'yourtheme/woocommerce/tackquote/' . $tack_tpl ) );
}

tack_parity_reset();
$GLOBALS['TACK_TEMPLATES_LOADED'] = array();
$tack_widget                      = new Tack_Widget();
$tack_out                         = tack_parity_capture( array( $tack_widget, 'render_quote_list_drawer' ) );
check( 'the drawer is rendered from the plugin template through wc_get_template()', array( TACK_QUOTES_DIR . 'templates/tackquote/quote-list-drawer.php' ) === $GLOBALS['TACK_TEMPLATES_LOADED'], wp_json_encode( $GLOBALS['TACK_TEMPLATES_LOADED'] ) );
check( 'the launcher is a theme button and says whether the drawer is open', false !== strpos( $tack_out, 'class="button tack-quote-list-toggle"' ) && false !== strpos( $tack_out, 'aria-expanded="false"' ), $tack_out );
check( 'the drawer checkout is the theme\'s primary (alt) button', false !== strpos( $tack_out, 'class="button alt tack-quote-btn tack-quote-list-checkout"' ), $tack_out );

$tack_html = $tack_widget->render_quote_page( array() );
check( 'the quote page is wrapped like a WooCommerce shortcode and uses shop_table', 0 === strpos( $tack_html, '<div class="woocommerce tackquote">' ) && false !== strpos( $tack_html, 'class="shop_table shop_table_responsive tack-quote-page-table"' ), $tack_html );
check( 'the quote page message is a WooCommerce form row', false !== strpos( $tack_html, 'class="form-row form-row-wide tack-quote-field tack-quote-page-message"' ) && false !== strpos( $tack_html, 'class="input-text"' ) );

// A theme override wins, at WooCommerce's documented path.
$tack_theme = sys_get_temp_dir() . '/tack-blend-theme-' . getmypid();
@mkdir( $tack_theme . '/woocommerce/tackquote/single-product', 0777, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
file_put_contents( $tack_theme . '/woocommerce/tackquote/quote-page.php', '<?php echo "THEME-QUOTE-PAGE:" . esc_html( $submit_label );' );
file_put_contents( $tack_theme . '/woocommerce/tackquote/single-product/quantity-breaks.php', '<?php echo "THEME-BREAKS:" . count( $rows );' );
$GLOBALS['TACK_THEME_DIR'] = $tack_theme;
check( 'a theme copy at woocommerce/tackquote/quote-page.php replaces the plugin template', 'THEME-QUOTE-PAGE:Checkout as Quote' === $tack_widget->render_quote_page( array() ), $tack_widget->render_quote_page( array() ) );
$GLOBALS['TACK_THEME_DIR'] = '';
check( 'without the theme copy the plugin template is used again', 0 === strpos( $tack_widget->render_quote_page( array() ), '<div class="woocommerce tackquote">' ) );

// Filters.
tack_test_add_filter_return( 'tackquote_button_label', 'Ask for a price' );
check( 'filter tackquote_button_label changes a storefront label', 'Ask for a price' === Tack_Widget::button_label( 'tack_quotes_button_label' ) );
tack_test_clear_filter_returns();
tack_test_add_filter_return( 'tackquote_button_label', '' );
check( 'tackquote_button_label returning blank keeps the label', 'Add to Quote' === Tack_Widget::button_label( 'tack_quotes_button_label' ) );
tack_test_clear_filter_returns();

tack_test_add_filter_return( 'tackquote_button_classes', 'btn btn-primary x' );
check( 'filter tackquote_button_classes replaces the button classes', 'btn btn-primary x' === Tack_Widget::button_class( 'x' ) );
tack_test_clear_filter_returns();

$GLOBALS['TACK_FILTERS']['tackquote_quote_page_args'][] = function ( $args ) {
	$args['submit_label'] = 'Send my list';
	return $args;
};
check( 'filter tackquote_quote_page_args reaches the template', false !== strpos( $tack_widget->render_quote_page( array() ), 'Send my list' ) );
tack_test_clear_filter_returns();

$GLOBALS['TACK_FILTERS']['tackquote_quote_list_drawer_args'][] = function ( $args ) {
	$args['checkout_label'] = 'Send list';
	return $args;
};
check( 'filter tackquote_quote_list_drawer_args reaches the template', false !== strpos( tack_parity_capture( array( $tack_widget, 'render_quote_list_drawer' ) ), 'Send list' ) );
tack_test_clear_filter_returns();

// Volume table: template, shop_table, filter, theme override.
tack_test_reset_transients();
tack_test_set_option( 'tack_quotes_api_key', 'tk_test_key' );
tack_test_set_option( Tack_Wholesale_Pricing::OPTION_ENABLED, 'yes' );
tack_test_set_logged_in( true, 'buyer@trade-customer.test' );
$tack_pricing = new Tack_Wholesale_Pricing( tack_fu_breaks_client() );
$GLOBALS['TACK_FILTERS']['tackquote_quantity_breaks_args'][] = function ( $args ) {
	$args['caption'] = 'Bulk prices';
	return $args;
};
$tack_out = $tack_pricing->append_quantity_breaks_to_block( '', array(), tack_block_instance( 32 ) );
check( 'the volume table is a WooCommerce shop_table from the template', false !== strpos( $tack_out, '<table class="tackquote-quantity-breaks shop_table">' ) && 2 === substr_count( $tack_out, '<tr><td>' ), $tack_out );
check( 'filter tackquote_quantity_breaks_args reaches the template', false !== strpos( $tack_out, '<caption>Bulk prices</caption>' ), $tack_out );
tack_test_clear_filter_returns();
$GLOBALS['TACK_THEME_DIR'] = $tack_theme;
$tack_out                  = ( new Tack_Wholesale_Pricing( tack_fu_breaks_client() ) )->append_quantity_breaks_to_block( '', array(), tack_block_instance( 32 ) );
check( 'a theme copy of single-product/quantity-breaks.php replaces the table', false !== strpos( $tack_out, 'THEME-BREAKS:2' ), $tack_out );
$GLOBALS['TACK_THEME_DIR'] = '';

// Storefront forms: template + filter.
tack_test_set_logged_in( false, '' );
$tack_forms_client = new Tack_Test_Forms_Client(
	array(
		'wholesale-form?slug=default' => array(
			'name'   => 'Trade account',
			'fields' => array(
				array(
					'key'      => 'company',
					'type'     => 'text',
					'label'    => 'Company',
					'required' => true,
				),
			),
		),
	)
);
$GLOBALS['TACK_FILTERS']['tackquote_storefront_form_args'][] = function ( $args, $form ) {
	$args['submit_label'] = 'Apply (' . $form . ')';
	return $args;
};
$tack_html = ( new Tack_Storefront_Forms( $tack_forms_client ) )->render_wholesale_form( 'default', 'https://shop.example/apply/' );
check( 'filter tackquote_storefront_form_args reaches the wholesale template', false !== strpos( $tack_html, 'Apply (wholesale)' ), $tack_html );
check( 'the wholesale submit wears the theme\'s button classes', false !== strpos( $tack_html, 'class="button tackquote-submit"' ), $tack_html );
tack_test_clear_filter_returns();

@unlink( $tack_theme . '/woocommerce/tackquote/quote-page.php' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
@unlink( $tack_theme . '/woocommerce/tackquote/single-product/quantity-breaks.php' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

// ── Audit L-4 (assigned to W6): no guest email in the page's inline script ──
$tack_email_of = new ReflectionMethod( 'Tack_Widget', 'current_customer_email' );
$tack_email_of->setAccessible( true );
$GLOBALS['TACK_WC_CUSTOMER'] = new class() {
	/** @return string */
	public function get_billing_email() {
		return 'guest-session@buyer.test';
	}
};
tack_test_set_logged_in( false, '' );
check( 'L-4: a guest\'s WooCommerce-session billing email is NOT printed into the page', '' === $tack_email_of->invoke( new Tack_Widget() ) );
tack_test_set_logged_in( true, 'account@buyer.test' );
check( 'L-4: a signed-in buyer still gets their billing email prefilled', 'guest-session@buyer.test' === $tack_email_of->invoke( new Tack_Widget() ) );
$GLOBALS['TACK_WC_CUSTOMER'] = null;
check( 'L-4: a signed-in buyer without a billing email gets their account email', 'account@buyer.test' === $tack_email_of->invoke( new Tack_Widget() ) );
tack_test_set_logged_in( false, '' );
