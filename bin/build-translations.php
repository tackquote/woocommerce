<?php
/**
 * Generate the plugin's bundled translations from the TackQuote widget catalogue.
 *
 * One catalogue for every storefront: TackQuote's shared widget locales
 * (tack `packages/widget/locales/*.json`) are vendored into
 * `languages/source/widget/`, and `languages/source/strings.json` says which plugin
 * msgid means exactly the same as which catalogue key. Storefront text the shared
 * catalogue has no identical entry for (WooCommerce-specific notices, the My Account
 * forms, the quote modal's company fields) comes from the plugin's own catalogue in
 * `languages/source/local/` (keys `woo.*`, since 1.11.0), in the same format; its
 * `en.json` must read exactly as each msgid. Output, for de_DE, es_ES, fr_FR, it_IT,
 * ja, nl_NL and pt_BR:
 *
 *   languages/tackquote-<locale>.po    readable source of the .mo (and for reviewers)
 *   languages/tackquote-<locale>.mo    what load_plugin_textdomain() loads for PHP
 *   languages/tackquote-<locale>-<md5>.json
 *       JED 1.x for each storefront script, named the way load_script_textdomain()
 *       looks it up: md5 of the script path relative to the plugin root
 *       (`assets/js/tack-quotes.js`), holding only the msgids that script uses.
 *
 * Plain PHP, no Composer and no gettext tools (the php:8.3-cli image has neither):
 * the .mo is written byte by byte in the GNU format. Output is deterministic (no
 * dates, sorted entries), so `--check` can compare a fresh build with the
 * committed files.
 *
 * Usage:
 *   php bin/build-translations.php            write into languages/
 *   php bin/build-translations.php --check    exit 1 if languages/ is stale
 *   php bin/build-translations.php --out=DIR  write into DIR instead
 *
 * @package TackQuotes
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- a CLI build tool, not plugin runtime: WordPress is not loaded.
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI output and build errors for a terminal, not HTML.

require_once __DIR__ . '/i18n-scan.php';

/**
 * Catalogue code => WordPress locale, plural forms (as GlotPress uses for each).
 *
 * @return array<string, array{0:string, 1:string}>
 */
function tack_tr_locales() {
	return array(
		'de'    => array( 'de_DE', 'nplurals=2; plural=n != 1;' ),
		'es'    => array( 'es_ES', 'nplurals=2; plural=n != 1;' ),
		'fr'    => array( 'fr_FR', 'nplurals=2; plural=n > 1;' ),
		'it'    => array( 'it_IT', 'nplurals=2; plural=n != 1;' ),
		'ja'    => array( 'ja', 'nplurals=1; plural=0;' ),
		'nl'    => array( 'nl_NL', 'nplurals=2; plural=n != 1;' ),
		'pt-BR' => array( 'pt_BR', 'nplurals=2; plural=n > 1;' ),
	);
}

/**
 * The storefront scripts that get a JED file (relative to the plugin root).
 *
 * The forms and net-terms block scripts are not listed: neither has a string of its
 * own (the block's title and description are the merchant's gateway settings).
 *
 * @return string[]
 */
function tack_tr_scripts() {
	return array( 'assets/js/tack-quotes.js' );
}

/**
 * Read a JSON file or stop.
 *
 * @param string $file Path.
 * @throws RuntimeException When the input is unusable.
 * @return array
 */
function tack_tr_json( $file ) {
	$data = is_file( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
	if ( ! is_array( $data ) ) {
		throw new RuntimeException( "unreadable JSON: $file" );
	}
	return $data;
}

/**
 * Turn a catalogue text into the plugin's printf form.
 *
 * Every {token} in the text must be declared in $placeholders, and every declared
 * token must appear: a translation that dropped or renamed a placeholder would
 * otherwise ship as a sprintf() argument-count bug.
 *
 * @param string $text         Catalogue text.
 * @param array  $placeholders Token => printf placeholder.
 * @param string $where        For the error message.
 * @throws RuntimeException When the input is unusable.
 * @return string
 */
function tack_tr_convert( $text, array $placeholders, $where ) {
	preg_match_all( '/\{(\w+)\}/', $text, $m );
	$found = array_values( array_unique( $m[1] ) );
	$want  = array_keys( $placeholders );
	sort( $found );
	sort( $want );
	if ( $found !== $want ) {
		throw new RuntimeException( "$where: placeholders {" . implode( '},{', $found ) . '} do not match the declared {' . implode( '},{', $want ) . '}' );
	}
	if ( false !== strpos( $text, '%' ) ) {
		throw new RuntimeException( "$where: a literal % would break sprintf()" );
	}
	foreach ( $placeholders as $token => $printf ) {
		$text = str_replace( '{' . $token . '}', $printf, $text );
	}
	return $text;
}

/**
 * Build the msgid => msgstr table for every locale.
 *
 * @param string $root Plugin root.
 * @throws RuntimeException When the input is unusable.
 * @return array{tables: array<string, array<string,string>>, keys: array<string,string>, machine: array<string, string[]>, sha: string}
 */
function tack_tr_tables( $root ) {
	$src     = $root . '/languages/source';
	$map     = tack_tr_json( $src . '/strings.json' );
	$en      = tack_tr_json( $src . '/widget/en.json' );
	$machine = tack_tr_json( $src . '/widget/machine-translated.json' );
	$own_en  = tack_tr_json( $src . '/local/en.json' );
	if ( ! isset( $map['strings'] ) || ! is_array( $map['strings'] ) ) {
		throw new RuntimeException( 'strings.json has no "strings" list' );
	}
	$tables = array();
	$keys   = array();
	foreach ( tack_tr_locales() as $code => $meta ) {
		$shared = tack_tr_json( $src . '/widget/' . $code . '.json' );
		$own    = tack_tr_json( $src . '/local/' . $code . '.json' );
		$table  = array();
		foreach ( $map['strings'] as $row ) {
			$msgid     = (string) $row['msgid'];
			$key       = (string) $row['key'];
			$ph        = isset( $row['placeholders'] ) && is_array( $row['placeholders'] ) ? $row['placeholders'] : array();
			$is_own    = tack_tr_is_local_key( $key );
			$english   = $is_own ? $own_en : $en;
			$catalogue = $is_own ? $own : $shared;
			if ( ! isset( $english[ $key ] ) ) {
				throw new RuntimeException( "strings.json: key $key is not in the " . ( $is_own ? 'local/en.json' : 'en.json snapshot' ) );
			}
			// The English catalogue text must convert cleanly too: proves the declared
			// placeholders are the catalogue's, not invented.
			$english_text = tack_tr_convert( (string) $english[ $key ], $ph, "en $key" );
			// The plugin's own catalogue is written for exactly one msgid each.
			if ( $is_own && $english_text !== $msgid ) {
				throw new RuntimeException( "local/en.json: $key reads \"$english_text\", not its msgid \"$msgid\"" );
			}
			$keys[ $msgid ] = $key;
			if ( ! isset( $catalogue[ $key ] ) || '' === trim( (string) $catalogue[ $key ] ) ) {
				continue; // Not translated in this language: WordPress shows the English msgid.
			}
			$table[ $msgid ] = tack_tr_convert( (string) $catalogue[ $key ], $ph, "$code $key" );
		}
		ksort( $table, SORT_STRING );
		$tables[ $code ] = $table;
	}
	$readme = is_file( $src . '/widget/README.md' ) ? (string) file_get_contents( $src . '/widget/README.md' ) : '';
	$sha    = preg_match( '/\b([0-9a-f]{40})\b/', $readme, $m ) ? $m[1] : 'unknown';
	return array(
		'tables'  => $tables,
		'keys'    => $keys,
		'machine' => isset( $machine['machineTranslated'] ) && is_array( $machine['machineTranslated'] ) ? $machine['machineTranslated'] : array(),
		'sha'     => $sha,
	);
}

/**
 * Is this a key of the plugin's own catalogue (`languages/source/local/`)?
 *
 * @param string $key Catalogue key.
 * @return bool
 */
function tack_tr_is_local_key( $key ) {
	return 0 === strpos( (string) $key, 'woo.' );
}

/**
 * Header entry shared by the .po and the .mo.
 *
 * @param string $locale WordPress locale.
 * @param string $plural Plural-Forms.
 * @param string $sha    Catalogue commit.
 * @return string
 */
function tack_tr_header( $locale, $plural, $sha ) {
	return "Project-Id-Version: TackQuote for WooCommerce\n"
		. "Report-Msgid-Bugs-To: https://github.com/tackquote/woocommerce/issues\n"
		. "Language: $locale\n"
		. "MIME-Version: 1.0\n"
		. "Content-Type: text/plain; charset=UTF-8\n"
		. "Content-Transfer-Encoding: 8bit\n"
		. "Plural-Forms: $plural\n"
		. "X-Generator: bin/build-translations.php\n"
		. "X-Domain: tackquote\n"
		. "X-TackQuote-Catalogue: $sha\n";
}

/**
 * Quote a string for a .po file.
 *
 * @param string $s Text.
 * @return string
 */
function tack_tr_po_quote( $s ) {
	return '"' . addcslashes( $s, "\\\"\n\t" ) . '"';
}

/**
 * The .po text.
 *
 * @param string $locale  WordPress locale.
 * @param string $plural  Plural-Forms.
 * @param array  $table   msgid => msgstr.
 * @param array  $keys    msgid => catalogue key.
 * @param array  $machine Catalogue keys still machine-translated in this language.
 * @param string $sha     Catalogue commit.
 * @return string
 */
function tack_tr_po( $locale, $plural, array $table, array $keys, array $machine, $sha ) {
	$out  = "# Generated by bin/build-translations.php from the TackQuote storefront catalogue. Do not edit:\n";
	$out .= "# change languages/source/ in the plugin repository and rebuild.\n";
	$out .= "msgid \"\"\nmsgstr \"\"\n";
	foreach ( explode( "\n", rtrim( tack_tr_header( $locale, $plural, $sha ), "\n" ) ) as $line ) {
		$out .= tack_tr_po_quote( $line . "\n" ) . "\n";
	}
	foreach ( $table as $msgid => $msgstr ) {
		$out .= "\n#. catalogue: " . $keys[ $msgid ] . "\n";
		if ( in_array( $keys[ $msgid ], $machine, true ) ) {
			$out .= "#. machine-translated, pending native review\n";
		} elseif ( tack_tr_is_local_key( $keys[ $msgid ] ) ) {
			$out .= "#. plugin catalogue (languages/source/local), pending native review\n";
		}
		if ( preg_match( '/%[sd]/', $msgid ) ) {
			$out .= "#, php-format\n";
		}
		$out .= 'msgid ' . tack_tr_po_quote( $msgid ) . "\n";
		$out .= 'msgstr ' . tack_tr_po_quote( $msgstr ) . "\n";
	}
	return $out;
}

/**
 * The binary .mo (GNU gettext format, little-endian, no hash table).
 *
 * Layout: 28-byte header (magic, revision 0, N, offset of the original-strings
 * table, offset of the translations table, hash size 0, hash offset), the two
 * tables of (length, offset) pairs, then the NUL-terminated strings. Entries are
 * sorted by msgid bytes, the header entry (msgid "") first, as the format requires
 * for binary search.
 *
 * @param string $header Header entry text.
 * @param array  $table  msgid => msgstr.
 * @return string
 */
function tack_tr_mo( $header, array $table ) {
	$entries = array( '' => $header ) + $table;
	ksort( $entries, SORT_STRING );
	$n          = count( $entries );
	$orig_off   = 28;
	$trans_off  = $orig_off + 8 * $n;
	$data_off   = $trans_off + 8 * $n;
	$orig_tab   = '';
	$trans_tab  = '';
	$orig_data  = '';
	$trans_data = '';
	foreach ( $entries as $msgid => $msgstr ) {
		$msgid      = (string) $msgid;
		$orig_tab  .= pack( 'VV', strlen( $msgid ), $data_off + strlen( $orig_data ) );
		$orig_data .= $msgid . "\0";
	}
	$trans_start = $data_off + strlen( $orig_data );
	foreach ( $entries as $msgstr ) {
		$trans_tab  .= pack( 'VV', strlen( $msgstr ), $trans_start + strlen( $trans_data ) );
		$trans_data .= $msgstr . "\0";
	}
	return pack( 'VVVVVVV', 0x950412de, 0, $n, $orig_off, $trans_off, 0, $data_off )
		. $orig_tab . $trans_tab . $orig_data . $trans_data;
}

/**
 * The JED 1.x file wp.i18n reads (load_script_translations() -> setLocaleData()).
 *
 * @param string $locale WordPress locale.
 * @param string $plural Plural-Forms.
 * @param array  $table  msgid => msgstr, already narrowed to the script's msgids.
 * @param string $script Script path relative to the plugin root.
 * @return string
 */
function tack_tr_jed( $locale, $plural, array $table, $script ) {
	$messages = array(
		'' => array(
			'domain'       => 'messages',
			'lang'         => $locale,
			'plural-forms' => $plural,
		),
	);
	foreach ( $table as $msgid => $msgstr ) {
		$messages[ $msgid ] = array( $msgstr );
	}
	$jed = array(
		'generator'   => 'bin/build-translations.php',
		'domain'      => 'messages',
		'source'      => $script,
		'locale_data' => array( 'messages' => $messages ),
	);
	return json_encode( $jed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) . "\n";
}

/**
 * Every generated file: basename => contents.
 *
 * @param string $root Plugin root.
 * @return array<string, string>
 */
function tack_tr_build( $root ) {
	$built = tack_tr_tables( $root );
	$files = array();
	foreach ( tack_tr_locales() as $code => $meta ) {
		list( $locale, $plural ) = $meta;
		$table                   = $built['tables'][ $code ];
		$machine                 = isset( $built['machine'][ $code ] ) ? (array) $built['machine'][ $code ] : array();

		$files[ "tackquote-$locale.po" ] = tack_tr_po( $locale, $plural, $table, $built['keys'], $machine, $built['sha'] );
		$files[ "tackquote-$locale.mo" ] = tack_tr_mo( tack_tr_header( $locale, $plural, $built['sha'] ), $table );

		foreach ( tack_tr_scripts() as $script ) {
			$used = array();
			foreach ( tack_i18n_scan_js( $root . '/' . $script ) as $hit ) {
				$used[ $hit['msgid'] ] = true;
			}
			$files[ "tackquote-$locale-" . md5( $script ) . '.json' ] = tack_tr_jed( $locale, $plural, array_intersect_key( $table, $used ), $script );
		}
	}
	ksort( $files, SORT_STRING );
	return $files;
}

/**
 * CLI entry point.
 *
 * @param string[] $argv Arguments.
 * @return int Exit code.
 */
function tack_tr_main( array $argv ) {
	$root  = dirname( __DIR__ );
	$check = in_array( '--check', $argv, true );
	$out   = $root . '/languages';
	foreach ( $argv as $arg ) {
		if ( 0 === strpos( $arg, '--out=' ) ) {
			$out = rtrim( substr( $arg, 6 ), '/' );
		}
	}
	try {
		$files = tack_tr_build( $root );
	} catch ( RuntimeException $e ) {
		fwrite( STDERR, 'build-translations: ' . $e->getMessage() . "\n" );
		return 2;
	}

	$present = array();
	foreach ( (array) glob( $out . '/tackquote-*' ) as $path ) {
		$present[ basename( $path ) ] = $path;
	}

	if ( $check ) {
		$stale = array();
		foreach ( $files as $name => $contents ) {
			if ( ! isset( $present[ $name ] ) || file_get_contents( $present[ $name ] ) !== $contents ) {
				$stale[] = $name;
			}
		}
		foreach ( array_diff_key( $present, $files ) as $name => $path ) {
			$stale[] = "$name (no longer generated)";
		}
		if ( $stale ) {
			fwrite( STDERR, "languages/ is stale; run php bin/build-translations.php and commit:\n  " . implode( "\n  ", $stale ) . "\n" );
			return 1;
		}
		echo 'languages/ is fresh (' . count( $files ) . " files)\n";
		return 0;
	}

	if ( ! is_dir( $out ) && ! mkdir( $out, 0755, true ) ) {
		fwrite( STDERR, "build-translations: cannot create $out\n" );
		return 2;
	}
	foreach ( array_diff_key( $present, $files ) as $path ) {
		unlink( $path );
	}
	foreach ( $files as $name => $contents ) {
		file_put_contents( $out . '/' . $name, $contents );
	}
	echo 'wrote ' . count( $files ) . " files to $out\n";
	return 0;
}

if ( PHP_SAPI === 'cli' && isset( $argv ) && realpath( $argv[0] ) === __FILE__ ) {
	exit( tack_tr_main( $argv ) );
}
