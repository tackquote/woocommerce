<?php
/**
 * Lists the translatable strings in the plugin's PHP and JS source.
 *
 * Shared by bin/build-translations.php (which msgids each script carries) and
 * tests/i18n-test.php (every mapped msgid exists in the source). A small extractor of
 * its own, because the build image has no WP-CLI make-pot: PHP through token_get_all(),
 * so a msgid is a real first argument of a gettext call with the `tackquote` domain and
 * never a comment or a log line; JS through a pattern over `__( '…', 'tackquote' )`, the
 * only gettext call the storefront script uses. Not shipped (bin/ is excluded from the zip).
 *
 * @package TackQuotes
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- a build/test helper reading local source files; WordPress is not loaded.

/**
 * Gettext calls and the argument positions of singular, plural and domain.
 *
 * @return array<string, array{0:int,1:int|null,2:int}>
 */
function tack_i18n_scan_functions() {
	return array(
		'__'         => array( 0, null, 1 ),
		'_e'         => array( 0, null, 1 ),
		'esc_html__' => array( 0, null, 1 ),
		'esc_html_e' => array( 0, null, 1 ),
		'esc_attr__' => array( 0, null, 1 ),
		'esc_attr_e' => array( 0, null, 1 ),
		'_x'         => array( 0, null, 2 ),
		'esc_html_x' => array( 0, null, 2 ),
		'esc_attr_x' => array( 0, null, 2 ),
		'_n'         => array( 0, 1, 3 ),
	);
}

/**
 * Translatable strings in one PHP file.
 *
 * @param string $file Path.
 * @return array<int, array{msgid:string, plural:string|null}>
 */
function tack_i18n_scan_php( $file ) {
	$tokens = token_get_all( (string) file_get_contents( $file ) );
	$fns    = tack_i18n_scan_functions();
	$found  = array();
	$count  = count( $tokens );
	for ( $i = 0; $i < $count; $i++ ) {
		if ( ! is_array( $tokens[ $i ] ) || T_STRING !== $tokens[ $i ][0] || ! isset( $fns[ $tokens[ $i ][1] ] ) ) {
			continue;
		}
		$prev = $i - 1;
		while ( $prev >= 0 && is_array( $tokens[ $prev ] ) && T_WHITESPACE === $tokens[ $prev ][0] ) {
			--$prev;
		}
		if ( $prev >= 0 && is_array( $tokens[ $prev ] ) && in_array( $tokens[ $prev ][0], array( T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON ), true ) ) {
			continue;
		}
		$j = $i + 1;
		while ( $j < $count && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
			++$j;
		}
		if ( $j >= $count || '(' !== $tokens[ $j ] ) {
			continue;
		}
		// Collect top-level arguments; an argument counts only when it is ONE string literal.
		$args  = array();
		$cur   = array();
		$depth = 0;
		for ( ++$j; $j < $count; $j++ ) {
			$t = $tokens[ $j ];
			if ( '(' === $t || '[' === $t ) {
				++$depth;
			} elseif ( ')' === $t || ']' === $t ) {
				if ( 0 === $depth ) {
					$args[] = $cur;
					break;
				}
				--$depth;
			} elseif ( ',' === $t && 0 === $depth ) {
				$args[] = $cur;
				$cur    = array();
				continue;
			}
			if ( is_array( $t ) && in_array( $t[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			$cur[] = $t;
		}
		$literal           = static function ( $arg ) {
			if ( ! is_array( $arg ) || 1 !== count( $arg ) || ! is_array( $arg[0] ) || T_CONSTANT_ENCAPSED_STRING !== $arg[0][0] ) {
				return null;
			}
			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- a single PHP string-literal token, unquoted exactly as PHP would.
			return eval( 'return ' . $arg[0][1] . ';' );
		};
		list( $s, $p, $d ) = $fns[ $tokens[ $i ][1] ];
		if ( ! isset( $args[ $d ] ) || 'tackquote' !== $literal( $args[ $d ] ) ) {
			continue;
		}
		$msgid = isset( $args[ $s ] ) ? $literal( $args[ $s ] ) : null;
		if ( null === $msgid ) {
			continue;
		}
		$found[] = array(
			'msgid'  => $msgid,
			'plural' => null === $p ? null : $literal( $args[ $p ] ),
		);
	}
	return $found;
}

/**
 * Translatable strings in one JS file: `__( '…', 'tackquote' )` with a quoted literal.
 *
 * @param string $file Path.
 * @return array<int, array{msgid:string, plural:null}>
 */
function tack_i18n_scan_js( $file ) {
	$src = (string) file_get_contents( $file );
	preg_match_all( '/\b__\(\s*(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")\s*,\s*[\'"]tackquote[\'"]\s*\)/', $src, $m );
	$found = array();
	foreach ( $m[1] as $quoted ) {
		$found[] = array(
			'msgid'  => stripcslashes( substr( $quoted, 1, -1 ) ),
			'plural' => null,
		);
	}
	return $found;
}

/**
 * Every msgid in the shipped source, keyed by msgid, with where it occurs.
 *
 * @param string $root Plugin root.
 * @return array{php: array<string,string[]>, js: array<string,string[]>, plural: array<string,string>}
 */
function tack_i18n_scan_plugin( $root ) {
	$out   = array(
		'php'    => array(),
		'js'     => array(),
		'plural' => array(),
	);
	$files = array_merge(
		array( $root . '/tackquote.php', $root . '/uninstall.php' ),
		glob( $root . '/includes/*.php' ),
		glob( $root . '/includes/*/*.php' )
	);
	foreach ( $files as $file ) {
		foreach ( tack_i18n_scan_php( $file ) as $hit ) {
			$out['php'][ $hit['msgid'] ][] = basename( $file );
			if ( null !== $hit['plural'] ) {
				$out['plural'][ $hit['msgid'] ] = $hit['plural'];
			}
		}
	}
	foreach ( glob( $root . '/assets/js/*.js' ) as $file ) {
		foreach ( tack_i18n_scan_js( $file ) as $hit ) {
			$out['js'][ $hit['msgid'] ][] = 'assets/js/' . basename( $file );
		}
	}
	return $out;
}
