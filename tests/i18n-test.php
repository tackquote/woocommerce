<?php
/**
 * Bundled translations: the generator, the files it wrote, and the wiring.
 *
 * Required by tests/run.php after the other suites; uses its check().
 *
 * @package TackQuotes
 */

require_once TACK_QUOTES_DIR . 'bin/build-translations.php';

/**
 * Minimal .mo reader, written from the GNU gettext format description rather than
 * reusing the writer: magic, revision, count, the two (length, offset) tables, and the
 * strings they point at. Returns null on anything malformed, so a writer that puts a
 * table or a string at the wrong offset cannot pass by accident.
 *
 * @param string $bytes File contents.
 * @return array<string,string>|null msgid => msgstr (header entry under '').
 */
function tack_i18n_test_read_mo( $bytes ) {
	$size = strlen( $bytes );
	if ( $size < 28 ) {
		return null;
	}
	$head = unpack( 'Vmagic/Vrevision/Vcount/Vorig/Vtrans/Vhsize/Vhoff', substr( $bytes, 0, 28 ) );
	if ( 0x950412de !== $head['magic'] || 0 !== $head['revision'] ) {
		return null;
	}
	$n = $head['count'];
	if ( $head['orig'] + 8 * $n > $size || $head['trans'] + 8 * $n > $size ) {
		return null;
	}
	$read = static function ( $table, $i ) use ( $bytes, $size ) {
		$pair = unpack( 'Vlen/Voff', substr( $bytes, $table + 8 * $i, 8 ) );
		// Each string is followed by a NUL the length does not count.
		if ( $pair['off'] + $pair['len'] >= $size || "\0" !== $bytes[ $pair['off'] + $pair['len'] ] ) {
			return null;
		}
		return substr( $bytes, $pair['off'], $pair['len'] );
	};
	$out  = array();
	$prev = null;
	for ( $i = 0; $i < $n; $i++ ) {
		$msgid  = $read( $head['orig'], $i );
		$msgstr = $read( $head['trans'], $i );
		if ( null === $msgid || null === $msgstr ) {
			return null;
		}
		// Binary search in gettext needs the originals sorted.
		if ( null !== $prev && strcmp( $prev, $msgid ) >= 0 ) {
			return null;
		}
		$prev           = $msgid;
		$out[ $msgid ] = $msgstr;
	}
	return $out;
}

$tack_lang = TACK_QUOTES_DIR . 'languages/';

// ── The .mo really carries German, for three known msgids ───────────────────
$tack_de = tack_i18n_test_read_mo( (string) file_get_contents( $tack_lang . 'tackquote-de_DE.mo' ) );
check( 'tackquote-de_DE.mo parses as a GNU .mo', is_array( $tack_de ), 'the reader rejected the file (magic, offsets, NULs or sort order)' );
$tack_de = is_array( $tack_de ) ? $tack_de : array();
check( 'de_DE: "Add to Quote" is "Zum Angebot hinzufügen"', 'Zum Angebot hinzufügen' === ( $tack_de['Add to Quote'] ?? null ), var_export( $tack_de['Add to Quote'] ?? null, true ) );
check( 'de_DE: "Price on request" is "Preis auf Anfrage"', 'Preis auf Anfrage' === ( $tack_de['Price on request'] ?? null ), var_export( $tack_de['Price on request'] ?? null, true ) );
check( 'de_DE: "Add %s to quote" keeps its placeholder: "%s zum Angebot hinzufügen"', '%s zum Angebot hinzufügen' === ( $tack_de['Add %s to quote'] ?? null ), var_export( $tack_de['Add %s to quote'] ?? null, true ) );
check(
	'de_DE: the header entry declares UTF-8, the locale and German plural forms',
	false !== strpos( $tack_de[''] ?? '', 'charset=UTF-8' ) && false !== strpos( $tack_de[''] ?? '', 'Language: de_DE' ) && false !== strpos( $tack_de[''] ?? '', 'Plural-Forms: nplurals=2; plural=n != 1;' )
);

// ── Every mapping is sound ──────────────────────────────────────────────────
$tack_map  = json_decode( (string) file_get_contents( $tack_lang . 'source/strings.json' ), true );
$tack_en   = json_decode( (string) file_get_contents( $tack_lang . 'source/widget/en.json' ), true );
$tack_own  = json_decode( (string) file_get_contents( $tack_lang . 'source/local/en.json' ), true );
$tack_scan = tack_i18n_scan_plugin( rtrim( TACK_QUOTES_DIR, '/' ) );
$tack_miss = array();
$tack_nokey = array();
foreach ( $tack_map['strings'] as $tack_row ) {
	if ( ! isset( $tack_scan['php'][ $tack_row['msgid'] ] ) && ! isset( $tack_scan['js'][ $tack_row['msgid'] ] ) ) {
		$tack_miss[] = $tack_row['msgid'];
	}
	if ( ! isset( ( tack_tr_is_local_key( $tack_row['key'] ) ? $tack_own : $tack_en )[ $tack_row['key'] ] ) ) {
		$tack_nokey[] = $tack_row['key'];
	}
}
check( 'every msgid in strings.json is a real tackquote msgid in the PHP or JS source (' . count( $tack_map['strings'] ) . ' mapped)', array() === $tack_miss, 'not in source: ' . implode( ' | ', $tack_miss ) );
check( 'every mapped catalogue key exists in the vendored en.json snapshot or the plugin\'s local/en.json', array() === $tack_nokey, 'missing keys: ' . implode( ', ', $tack_nokey ) );
check( 'the scanner sees the storefront script\'s strings (positive control)', isset( $tack_scan['js']['Request a Quote'], $tack_scan['php']['Price on request'] ) );

// ── languages/ is what the generator writes today (no stale release) ────────
try {
	$tack_built = tack_tr_build( rtrim( TACK_QUOTES_DIR, '/' ) );
} catch ( RuntimeException $e ) {
	$tack_built = array();
	check( 'the generator builds without an error', false, $e->getMessage() );
}
$tack_stale = array();
foreach ( $tack_built as $tack_name => $tack_bytes ) {
	if ( ! is_file( $tack_lang . $tack_name ) || file_get_contents( $tack_lang . $tack_name ) !== $tack_bytes ) {
		$tack_stale[] = $tack_name;
	}
}
$tack_fresh_de = tack_i18n_test_read_mo( $tack_built['tackquote-de_DE.mo'] ?? '' );
check(
	'the writer\'s own de_DE.mo (fresh, in memory) parses and holds the German strings',
	// Every mapping is translated in German (+1: the header entry), so no German msgid is silently left English.
	is_array( $tack_fresh_de ) && 'Preis auf Anfrage' === ( $tack_fresh_de['Price on request'] ?? null ) && count( $tack_map['strings'] ) + 1 === count( $tack_fresh_de ),
	is_array( $tack_fresh_de ) ? count( $tack_fresh_de ) . ' entries' : 'the reader rejected the generated bytes'
);
check( 'languages/ matches a fresh build (21 files; run php bin/build-translations.php)', 21 === count( $tack_built ) && array() === $tack_stale, 'stale: ' . implode( ', ', $tack_stale ) );

// ── 1.11.0 (D8): no storefront text stays English ───────────────────────────
//
// A completeness assertion, not a count: every msgid in a file shoppers see
// (strings.json `storefront.sources`) is mapped AND translated in all seven bundled
// languages, unless `storefront.notStorefront` names it with the reason (shop-manager
// screens, log-only text). Text with no word in it (a bare "%d+") needs no translation.
$tack_sources = isset( $tack_map['storefront']['sources'] ) ? (array) $tack_map['storefront']['sources'] : array();
$tack_exempt  = isset( $tack_map['storefront']['notStorefront'] ) ? (array) $tack_map['storefront']['notStorefront'] : array();
// The scanner reports PHP files by basename and scripts as assets/js/<file>.
$tack_source_names = array();
foreach ( $tack_sources as $tack_source ) {
	if ( '/' === substr( $tack_source, -1 ) ) {
		foreach ( array_merge( glob( TACK_QUOTES_DIR . $tack_source . '*.php' ), glob( TACK_QUOTES_DIR . $tack_source . '*/*.php' ) ) as $tack_file ) {
			$tack_source_names[ basename( $tack_file ) ] = true;
		}
	} elseif ( '.js' === substr( $tack_source, -3 ) ) {
		$tack_source_names[ $tack_source ] = true;
	} else {
		$tack_source_names[ basename( $tack_source ) ] = true;
	}
}
$tack_is_storefront = static function ( array $where ) use ( $tack_source_names ) {
	return array() !== array_intersect_key( array_flip( $where ), $tack_source_names );
};
$tack_mapped     = array_column( $tack_map['strings'], 'key', 'msgid' );
$tack_storefront = array();
foreach ( array( 'php', 'js' ) as $tack_kind ) {
	foreach ( $tack_scan[ $tack_kind ] as $tack_msgid => $tack_where ) {
		if ( $tack_is_storefront( $tack_where ) && ! isset( $tack_exempt[ $tack_msgid ] ) && preg_match( '/[A-Za-z]{2,}/', (string) preg_replace( '/%(\d\$)?[sd]/', '', $tack_msgid ) ) ) {
			$tack_storefront[ $tack_msgid ] = true;
		}
	}
}
$tack_unmapped = array_keys( array_diff_key( $tack_storefront, $tack_mapped ) );
check( 'the storefront scope is declared and reaches the quote modal, the forms and the templates (positive control)', count( $tack_sources ) > 10 && isset( $tack_storefront['First name'], $tack_storefront['Billing address'], $tack_storefront['Target price'], $tack_storefront['Sign in'] ) );
check( 'every storefront msgid is mapped to a catalogue entry (' . count( $tack_storefront ) . ' storefront msgids)', array() === $tack_unmapped, 'still English: ' . implode( ' | ', $tack_unmapped ) );
$tack_untranslated = array();
try {
	$tack_tables = tack_tr_tables( rtrim( TACK_QUOTES_DIR, '/' ) )['tables'];
} catch ( RuntimeException $e ) {
	$tack_tables       = array();
	$tack_untranslated = array( 'the generator failed: ' . $e->getMessage() );
}
foreach ( $tack_tables ? tack_tr_locales() : array() as $tack_code => $tack_meta ) {
	$tack_table = $tack_tables[ $tack_code ];
	foreach ( array_keys( $tack_storefront ) as $tack_msgid ) {
		if ( ! isset( $tack_table[ $tack_msgid ] ) ) {
			$tack_untranslated[] = $tack_meta[0] . ': ' . $tack_msgid;
		}
	}
}
check( 'every storefront msgid is translated in all seven bundled languages', array() === $tack_untranslated, implode( ' | ', array_slice( $tack_untranslated, 0, 20 ) ) );
$tack_stale_exempt = array_keys( array_diff_key( $tack_exempt, $tack_scan['php'] + $tack_scan['js'] ) );
check( 'every storefront.notStorefront entry is still a msgid in the source', array() === $tack_stale_exempt, implode( ' | ', $tack_stale_exempt ) );


// ── JED file name and contents follow load_script_textdomain() ──────────────
$tack_jed_name = 'tackquote-de_DE-' . md5( 'assets/js/tack-quotes.js' ) . '.json';
$tack_jed      = json_decode( is_file( $tack_lang . $tack_jed_name ) ? (string) file_get_contents( $tack_lang . $tack_jed_name ) : '', true );
check( 'the de_DE JED file is named tackquote-de_DE-<md5 of assets/js/tack-quotes.js>.json', is_array( $tack_jed ), $tack_jed_name );
check(
	'and carries the script\'s strings in JED 1.x shape',
	is_array( $tack_jed ) && array( 'Angebot anfragen' ) === ( $tack_jed['locale_data']['messages']['Request a Quote'] ?? null ) && 'de_DE' === ( $tack_jed['locale_data']['messages']['']['lang'] ?? null )
);
check(
	'but not PHP-only strings the script never shows',
	is_array( $tack_jed ) && ! isset( $tack_jed['locale_data']['messages']['Price on request'] )
);

// ── The generator refuses a translation that loses a placeholder ────────────
$tack_threw = false;
try {
	tack_tr_convert( 'Zum Angebot hinzufügen', array( 'name' => '%s' ), 'test' );
} catch ( RuntimeException $e ) {
	$tack_threw = true;
}
check( 'a catalogue text missing a declared {placeholder} stops the build', $tack_threw );

// ── Wiring ──────────────────────────────────────────────────────────────────
$tack_main = (string) file_get_contents( TACK_QUOTES_DIR . 'tackquote.php' );
check( 'the plugin header declares Domain Path: /languages', 1 === preg_match( '/^ \* Domain Path:\s+\/languages$/m', $tack_main ) );
check(
	'the textdomain is registered on init (not plugins_loaded) with the languages/ folder',
	false !== strpos( $tack_main, "add_action( 'init', 'tack_quotes_load_textdomain', 0 );" )
	&& false !== strpos( $tack_main, "load_plugin_textdomain( 'tackquote', false, dirname( plugin_basename( TACK_QUOTES_FILE ) ) . '/languages' );" )
);
$tack_widget = (string) file_get_contents( TACK_QUOTES_DIR . 'includes/class-tack-widget.php' );
check(
	'the storefront script depends on wp-i18n and gets wp_set_script_translations with no path',
	false !== strpos( $tack_widget, "array( 'jquery', 'wp-i18n', 'tackquote-with-options' )" ) && false !== strpos( $tack_widget, "wp_set_script_translations( 'tackquote', 'tackquote' );" )
);
check( 'no i18n array is localised any more (one source: wp.i18n)', false === strpos( $tack_widget, "'i18n'" ) );

// Every TackQuotes.i18n.<key> the script reads is defined in its wp.i18n block, so
// moving the strings out of PHP left no label undefined.
$tack_js = (string) file_get_contents( TACK_QUOTES_DIR . 'assets/js/tack-quotes.js' );
preg_match( '/TackQuotes\.i18n = \{(.*?)\n  \};/s', $tack_js, $tack_block );
preg_match_all( '/^\s{4}(\w+):/m', $tack_block[1] ?? '', $tack_defined );
preg_match_all( '/\bi18n\.(\w+)/', $tack_js, $tack_used );
$tack_undefined = array_diff( array_unique( $tack_used[1] ), $tack_defined[1] );
check( 'every TackQuotes.i18n key the script reads is defined through wp.i18n', count( $tack_defined[1] ) > 30 && array() === array_values( $tack_undefined ), 'undefined: ' . implode( ', ', $tack_undefined ) );

// ── Button labels follow the visitor's language unless the merchant changed them ──
// Until 1.10.0 activation stored __( 'Add to Quote' ) in the database, so the English
// text was frozen into every store and no .mo could reach the product button.
$GLOBALS['TACK_TRANSLATIONS'] = array(
	'Add to Quote'      => 'Zum Angebot hinzufügen',
	'Request a Quote'   => 'Angebot anfragen',
	'Checkout as Quote' => 'Als Angebot absenden',
);
tack_test_set_option( 'tack_quotes_button_label', 'Add to Quote' );
check( 'a label stored as the English default renders translated', 'Zum Angebot hinzufügen' === Tack_Widget::button_label( 'tack_quotes_button_label' ), Tack_Widget::button_label( 'tack_quotes_button_label' ) );
tack_test_set_option( 'tack_quotes_request_button_label', '' );
check( 'a blank label renders the translated default', 'Angebot anfragen' === Tack_Widget::button_label( 'tack_quotes_request_button_label' ) );
tack_test_set_option( 'tack_quotes_checkout_button_label', 'Send my RFQ' );
check( 'a label the merchant changed is shown verbatim', 'Send my RFQ' === Tack_Widget::button_label( 'tack_quotes_checkout_button_label' ) );
$tack_settings = new Tack_Settings();
check(
	'saving the default text (English or as translated) stores blank, so it keeps following the language',
	'' === $tack_settings->sanitize_button_label( 'Add to Quote' ) && '' === $tack_settings->sanitize_button_label( 'Angebot anfragen' ) && 'Send my RFQ' === $tack_settings->sanitize_button_label( ' Send my RFQ ' )
);
$GLOBALS['TACK_TRANSLATIONS'] = array();
tack_test_set_option( 'tack_quotes_button_label', '' );
tack_test_set_option( 'tack_quotes_request_button_label', '' );
tack_test_set_option( 'tack_quotes_checkout_button_label', '' );
$tack_quotes_src = (string) file_get_contents( TACK_QUOTES_DIR . 'includes/class-tack-quotes.php' );
check( 'activation no longer stores a label (which froze the activation-time language)', 0 === preg_match( "/add_option\\( 'tack_quotes_(request_|checkout_)?button_label'/", $tack_quotes_src ) );
