<?php
/**
 * Regression tests for the 1.10.0 final polish (lane W9).
 *
 * Findings: tack-notes WOO_PLUGIN_AUDIT_2026-10-10.md.
 *   L-5  the version floors are WordPress 6.4 and WooCommerce 8.0, and every place that
 *        states them agrees.
 *
 * Run: php tests/run.php
 *
 * @package TackQuotes
 */

// ── L-5: one floor, stated the same way everywhere ─────────────────────────

$tack_w9_header = (string) file_get_contents( TACK_QUOTES_DIR . 'tackquote.php' );
$tack_w9_readme = (string) file_get_contents( TACK_QUOTES_DIR . 'readme.txt' );
$tack_w9_md     = (string) file_get_contents( TACK_QUOTES_DIR . 'README.md' );

check( 'L-5: the plugin header requires WordPress 6.4', 1 === preg_match( '/^ \* Requires at least: 6\.4$/m', $tack_w9_header ) );
check( 'L-5: the plugin header requires WooCommerce 8.0', 1 === preg_match( '/^ \* WC requires at least: 8\.0$/m', $tack_w9_header ) );
check( 'L-5: readme.txt states the same WordPress floor', 1 === preg_match( '/^Requires at least: 6\.4$/m', $tack_w9_readme ) );
check( 'L-5: README.md states both floors', false !== strpos( $tack_w9_md, "- WordPress 6.4+\n- WooCommerce 8.0+\n" ) );
check( 'L-5: no file still claims the old 6.0 floor', 0 === preg_match( '/(Requires at least|WC requires at least): 6\.0|(WordPress|WooCommerce) 6\.0\+/', $tack_w9_header . $tack_w9_readme . $tack_w9_md ) );
