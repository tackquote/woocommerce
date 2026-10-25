<?php
/**
 * 1.12.0 coding-standards pass: what that pass changed beyond formatting.
 *
 * - bin/i18n-scan.php decodes PHP string literals without eval();
 * - markup is escaped where it is echoed (wp_kses() with an allowlist), so every tag
 *   and attribute the builders emit must be on the list it is escaped with, or the
 *   echo would silently drop it (the quote form's file input once relied on three);
 * - the "Link my TackQuote account" handler checks capability and nonce itself.
 *
 * Runs last in tests/run.php and reuses fixtures the earlier suites left behind
 * ($simple, $variable, Tack_Test_Embed_Admin, Tack_Test_Embed_Client).
 *
 * @package TackQuotes
 */

// ── bin/i18n-scan.php: string literals without eval() ───────────────────────
require_once TACK_QUOTES_DIR . 'bin/i18n-scan.php';

$tack_std_literals = array(
	"'plain'"                 => 'plain',
	"'it\\'s'"                => "it's",
	"'a\\\\b'"                => 'a\\b',
	"'a\\nb'"                 => 'a\\nb',
	"'\\\\\\''"               => "\\'",
	'"tab\\there"'            => "tab\there",
	'"line\\nbreak\\r"'       => "line\nbreak\r",
	'"\\$price"'              => '$price',
	'"say \\"hi\\""'          => 'say "hi"',
	'"\\101\\x42\\u{43}"'     => 'ABC',
	'"\\u{e9}"'               => "\xC3\xA9",
	'"\\q stays"'             => '\\q stays',
	'"\\e\\v\\f"'             => "\x1B\x0B\x0C",
	"''"                      => '',
	'"\\\\n is not a newline"' => '\\n is not a newline',
);
$tack_std_bad = array();
foreach ( $tack_std_literals as $tack_std_token => $tack_std_want ) {
	if ( tack_i18n_unquote( $tack_std_token ) !== $tack_std_want ) {
		$tack_std_bad[] = $tack_std_token . ' => ' . var_export( tack_i18n_unquote( $tack_std_token ), true );
	}
}
check( 'i18n scan: PHP string literals decode as PHP decodes them (' . count( $tack_std_literals ) . ' cases)', array() === $tack_std_bad, implode( ' | ', $tack_std_bad ) );
$tack_std_evals = array_filter(
	token_get_all( (string) file_get_contents( TACK_QUOTES_DIR . 'bin/i18n-scan.php' ) ),
	static function ( $token ) {
		return is_array( $token ) && T_EVAL === $token[0];
	}
);
check( 'i18n scan: no eval in the scanner\'s code (tokens, so a comment cannot hide one)', array() === $tack_std_evals );

// ── Escaping where it is echoed ─────────────────────────────────────────────

/**
 * Tags and attributes in $html that $allowed (a wp_kses() list) would strip.
 *
 * @param string $html    Markup.
 * @param array  $allowed wp_kses() allowlist.
 * @return string[] `<tag>` for a tag, `tag@attribute` for an attribute.
 */
function tack_std_outside_allowlist( $html, array $allowed ) {
	$bad = array();
	preg_match_all( '/<([a-zA-Z][a-zA-Z0-9]*)\b([^>]*)>/', (string) $html, $tags, PREG_SET_ORDER );
	foreach ( $tags as $tag ) {
		$name = strtolower( $tag[1] );
		if ( ! isset( $allowed[ $name ] ) ) {
			$bad[] = '<' . $name . '>';
			continue;
		}
		preg_match_all( '/([a-zA-Z_:][-a-zA-Z0-9_:.]*)(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+))?/', $tag[2], $attrs );
		foreach ( $attrs[1] as $attr ) {
			if ( empty( $allowed[ $name ][ strtolower( $attr ) ] ) ) {
				$bad[] = $name . '@' . strtolower( $attr );
			}
		}
	}
	return array_values( array_unique( $bad ) );
}

check(
	'allowlist checker: positive control (an unlisted attribute and an unlisted tag are reported)',
	array( 'input@multiple', '<script>' ) === tack_std_outside_allowlist( '<input type="file" multiple="multiple" /><script>x</script>', array( 'input' => array( 'type' => true ) ) )
);

$tack_std_forms  = new Tack_Storefront_Forms();
$tack_std_fields = array(
	array( 'key' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'help' => 'Your name.' ),
	array( 'key' => 'mail', 'label' => 'Email', 'type' => 'email', 'required' => true ),
	array( 'key' => 'tel', 'label' => 'Phone', 'type' => 'tel' ),
	array( 'key' => 'qty', 'label' => 'Quantity', 'type' => 'number', 'min' => 1 ),
	array( 'key' => 'when', 'label' => 'Needed by', 'type' => 'date', 'min' => 'today' ),
	array( 'key' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'showIf' => array( 'field' => 'kind', 'equals' => 'Other' ) ),
	array( 'key' => 'kind', 'label' => 'Kind', 'type' => 'select', 'options' => array( 'Retail', 'Other' ), 'required' => true ),
	array( 'key' => 'lines', 'label' => 'Lines', 'type' => 'multiselect', 'options' => array( 'A', 'B' ) ),
	array( 'key' => 'agree', 'label' => 'I agree', 'type' => 'checkbox', 'required' => true ),
	array( 'key' => 'ship', 'label' => 'Ship to', 'type' => 'address' ),
	array( 'key' => 'docs', 'label' => 'Documents', 'type' => 'file', 'maxFiles' => 3, 'maxSizeMb' => 5 ),
);
$tack_std_calendar = array(
	'timeZone' => 'America/Chicago',
	'today'    => '2026-10-25',
);
$tack_std_quote_html = '';
$tack_std_app_html   = '';
foreach ( $tack_std_fields as $tack_std_field ) {
	// The quote form (Tack_Quote_Form::fields_html()) and an application form.
	$tack_std_quote_html .= $tack_std_forms->render_field( $tack_std_field, Tack_Quote_Form::ANSWERS_FIELD, null, 'upload', $tack_std_calendar, Tack_Quote_Form::ID_PREFIX, Tack_Quote_Form::FILES_FIELD );
	$tack_std_app_html   .= $tack_std_forms->render_field( $tack_std_field, 'tack_sf', null, 'upload', $tack_std_calendar );
}
check(
	'quote form: the file input carries multiple, data-tack-max-files and data-tack-max-mb (what the storefront script reads)',
	false !== strpos( $tack_std_quote_html, 'multiple="multiple"' ) && false !== strpos( $tack_std_quote_html, 'data-tack-max-files="3"' ) && false !== strpos( $tack_std_quote_html, 'data-tack-max-mb="5"' )
);
$tack_std_out = tack_std_outside_allowlist( $tack_std_quote_html, Tack_Storefront_Forms::allowed_html() );
check( 'quote form: every tag and attribute of every field type is on allowed_html(), so render_template() escapes nothing away', array() === $tack_std_out, implode( ', ', $tack_std_out ) );
$tack_std_out = tack_std_outside_allowlist( $tack_std_app_html, Tack_Storefront_Forms::allowed_html() );
check( 'application forms: every tag and attribute of every field type is on allowed_html()', array() === $tack_std_out, implode( ', ', $tack_std_out ) );
$tack_std_out = tack_std_outside_allowlist( wp_nonce_field( Tack_Storefront_Forms::ACTION_WHOLESALE, '_tack_nonce', true, false ) . '<input type="hidden" name="tack_redirect" value="https://shop.example/apply/" />', Tack_Storefront_Forms::allowed_html() );
check( 'application forms: the hidden inputs and nonce are on allowed_html()', array() === $tack_std_out, implode( ', ', $tack_std_out ) );

// wc_price() as WooCommerce 11.2 prints it (the symbol span gained translate and dir).
$tack_std_price = '<span class="woocommerce-Price-amount amount"><bdi><span class="woocommerce-Price-currencySymbol" translate="no" dir="auto">&#36;</span>1,250.00</bdi></span>';
$tack_std_out   = tack_std_outside_allowlist( 'Credit limit: ' . $tack_std_price, Tack_Storefront_Forms::price_line_allowed_html() );
check( 'net-terms summary: wc_price() markup (span + bdi) is on price_line_allowed_html()', array() === $tack_std_out, implode( ', ', $tack_std_out ) );

// The net-terms standing view (render_net_terms_form() and the tab that prints it) is
// filtered through allowed_html(), so wc_price()'s markup must be on that list too.
$tack_std_out = tack_std_outside_allowlist( $tack_std_price, Tack_Storefront_Forms::allowed_html() );
check( 'net-terms standing: wc_price() markup (span translate/dir + bdi) is on allowed_html() as well', array() === $tack_std_out, implode( ', ', $tack_std_out ) );
if ( function_exists( 'tack_tabs_render_net_terms' ) && function_exists( 'tack_tabs_standing' ) ) {
	// The account-tabs suite's own preconditions: a key, a signed-in customer whose email
	// is confirmed (an unconfirmed one gets the form, not the standing).
	tack_test_reset_user_meta();
	tack_test_set_option( 'tack_quotes_api_key', 'tk_test_key' );
	tack_test_set_logged_in( true, 'buyer@trade-customer.test' );
	$tack_std_standing         = new ReflectionMethod( 'Tack_Storefront_Forms', 'net_terms_standing_html' );
	$tack_std_standing->setAccessible( true );
	Tack_Net_Terms_Standing::reset_request_cache();
	tack_test_reset_transients();
	$tack_std_raw = $tack_std_standing->invoke( new Tack_Storefront_Forms( new Tack_Test_Forms_Client( array( 'storefront/v1/net-terms' => tack_tabs_standing() ) ) ) );
	$tack_std_out = tack_std_outside_allowlist( $tack_std_raw, Tack_Storefront_Forms::allowed_html() );
	check( 'net-terms standing: the summary as built (before any filtering) is entirely on allowed_html()', false !== strpos( $tack_std_raw, 'tackquote-net-terms-credit-limit' ) && array() === $tack_std_out, implode( ', ', $tack_std_out ) . ' :: ' . $tack_std_raw );
	list( $tack_std_tab ) = tack_tabs_render_net_terms( tack_tabs_standing() );
	check( 'net-terms standing: the rendered tab keeps every amount', 2 <= substr_count( $tack_std_tab, 'class="amount"' ), $tack_std_tab );
} else {
	check( 'net-terms standing: fixtures from account-tabs-test.php are present', false );
}

if ( isset( $simple, $variable ) ) {
	$tack_std_widget = new Tack_Widget();
	$tack_std_card   = new ReflectionMethod( 'Tack_Widget', 'card_button_html' );
	$tack_std_allow  = new ReflectionMethod( 'Tack_Widget', 'card_button_allowed_html' );
	$tack_std_card->setAccessible( true );
	$tack_std_allow->setAccessible( true );
	$tack_std_html = $tack_std_card->invoke( $tack_std_widget, $simple ) . $tack_std_card->invoke( $tack_std_widget, $variable );
	$tack_std_out  = tack_std_outside_allowlist( $tack_std_html, $tack_std_allow->invoke( null ) );
	check( 'card control: the button and the choose-options link are within card_button_allowed_html()', false !== strpos( $tack_std_html, '<button' ) && false !== strpos( $tack_std_html, '<a ' ) && array() === $tack_std_out, implode( ', ', $tack_std_out ) );
} else {
	check( 'card control: fixtures from storefront-1120-test.php are present', false );
}

// ── "Link my TackQuote account": capability and nonce in the handler itself ─
if ( class_exists( 'Tack_Test_Embed_Admin' ) ) {
	$tack_std_claim = function ( $caps, $nonce ) {
		// A signed-in shop manager, set here rather than inherited from an earlier suite.
		$was                        = array( $GLOBALS['TACK_LOGGED_IN'] ?? false, $GLOBALS['TACK_USER_LOGIN'] ?? null );
		$GLOBALS['TACK_LOGGED_IN']  = true;
		$GLOBALS['TACK_USER_LOGIN'] = 'shopmanager';
		$GLOBALS['TACK_CAPS']       = $caps;
		$client                = new Tack_Test_Embed_Client();
		$client->answers['/embed/claim'] = array( 'status' => 'linked' );
		$admin                 = new Tack_Test_Embed_Admin( $client );
		$_POST['_tqnonce']     = $nonce;
		$_POST['tq_link_code'] = 'ABCD-2345';
		$died                  = '';
		try {
			$admin->handle_claim();
		} catch ( Tack_Test_Wp_Die $e ) {
			$died = $e->getMessage();
		}
		unset( $_POST['_tqnonce'], $_POST['tq_link_code'] );
		$GLOBALS['TACK_CAPS']       = array( 'manage_woocommerce' );
		$GLOBALS['TACK_LOGGED_IN']  = $was[0];
		$GLOBALS['TACK_USER_LOGIN'] = $was[1];
		return array( $died, count( $client->calls ) );
	};
	list( $tack_std_died, $tack_std_calls ) = $tack_std_claim( array(), wp_create_nonce( Tack_Embed_Admin::CLAIM_ACTION ) );
	check( 'claim: a user without manage_woocommerce is refused before any API call', false !== strpos( $tack_std_died, 'permission' ) && 0 === $tack_std_calls );
	list( $tack_std_died, $tack_std_calls ) = $tack_std_claim( array( 'manage_woocommerce' ), wp_create_nonce( Tack_Embed_Admin::TAB_ACTION ) );
	check( 'claim: another action\'s nonce is refused before any API call', false !== strpos( $tack_std_died, 'expired' ) && 0 === $tack_std_calls );
	list( $tack_std_died, $tack_std_calls ) = $tack_std_claim( array( 'manage_woocommerce' ), '' );
	check( 'claim: a missing nonce is refused before any API call', false !== strpos( $tack_std_died, 'expired' ) && 0 === $tack_std_calls );
	list( $tack_std_died, $tack_std_calls ) = $tack_std_claim( array( 'manage_woocommerce' ), wp_create_nonce( Tack_Embed_Admin::CLAIM_ACTION ) );
	check( 'claim: with the capability and its own nonce the code is sent (positive control)', '' === $tack_std_died && 1 === $tack_std_calls, 'died: ' . $tack_std_died . ' calls: ' . $tack_std_calls );
} else {
	check( 'claim: fixtures from embed-admin-test.php are present', false );
}

// ── readme.txt: the wordpress.org listing must not cut the disclosures off ──
// Plugin Check's readme parser (includes/Lib/Readme/Parser.php, Plugin Check 2.1.0)
// appends every section it does not know (External services, Privacy) to Description,
// each behind an <h3> title, and trims the result once its whitespace-split pieces
// reach 2 x 2500. Replicated here; it agreed with `wp plugin check` on both sides of
// the line (trimmed at bfd9d72, clean at 1c3eeac).

/**
 * Whitespace-split pieces of the Description as the readme parser assembles it.
 *
 * @param string $readme readme.txt contents.
 * @return int
 */
function tack_std_readme_description_pieces( $readme ) {
	$lines    = explode( "\n", str_replace( "\r", '', $readme ) );
	$expected = array( 'description', 'installation', 'faq', 'screenshots', 'changelog', 'upgrade_notice', 'other_notes' );
	$alias    = array(
		'frequently_asked_questions' => 'faq',
		'change_log'                 => 'changelog',
		'screenshot'                 => 'screenshots',
	);
	while ( $lines && ( 0 !== strpos( trim( $lines[0] ), '== ' ) || 0 === strpos( trim( $lines[0] ), '=== ' ) ) ) {
		array_shift( $lines );
	}
	$sections = array_fill_keys( $expected, '' );
	$current  = '';
	$name     = '';
	foreach ( $lines as $line ) {
		$t = trim( $line );
		if ( '' === $t ) {
			$current .= "\n";
			continue;
		}
		if ( '=' === $t[0] && isset( $t[1] ) && '=' === $t[1] ) {
			if ( '' !== $name ) {
				$sections[ $name ] .= trim( $current );
			}
			$current = '';
			$title   = trim( $line, "#= \t" );
			$name    = strtolower( str_replace( ' ', '_', $title ) );
			$name    = $alias[ $name ] ?? $name;
			if ( ! in_array( $name, $expected, true ) ) {
				$current .= '<h3>' . $title . '</h3>';
				$name     = 'other_notes';
			}
			continue;
		}
		$current .= $line . "\n";
	}
	if ( '' !== $name ) {
		$sections[ $name ] .= trim( $current );
	}
	$description = $sections['description'] . ( '' !== $sections['other_notes'] ? "\n" . $sections['other_notes'] : '' );
	return count( preg_split( '/(\s+)/u', $description, -1, PREG_SPLIT_DELIM_CAPTURE ) );
}

$tack_std_pieces = tack_std_readme_description_pieces( (string) file_get_contents( TACK_QUOTES_DIR . 'readme.txt' ) );
check( 'readme: Description + External services + Privacy fit the listing (' . $tack_std_pieces . ' of 5000 pieces), so wordpress.org shows every disclosure', $tack_std_pieces < 5000 );
check( 'readme: the budget check counts something real (positive control: 2,600 more words go over)', tack_std_readme_description_pieces( (string) file_get_contents( TACK_QUOTES_DIR . 'readme.txt' ) . "\n== Extra ==\n" . str_repeat( 'word ', 2600 ) ) >= 5000 );
