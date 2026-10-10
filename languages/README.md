# Translations

Bundled translations of the storefront text for German (de_DE), Spanish (es_ES),
French (fr_FR), Italian (it_IT), Japanese (ja), Dutch (nl_NL) and Brazilian
Portuguese (pt_BR). English is the source.

- **Machine-assisted, from one catalogue.** Every translation comes from TackQuote's
  shared widget catalogue (the same text the BigCommerce, Shopify, Wix and Squarespace
  storefronts show), vendored in `source/widget/`. `source/strings.json` maps a plugin
  string to a catalogue entry only where the meaning is identical; other strings stay
  English here.
- **Review state.** de, es, fr, ja, nl and pt-BR had a consistency and register review
  in the catalogue. **Italian is still machine-translated and pending native review**
  (`source/widget/machine-translated.json`; the `.po` marks those entries).
- **Register.** Spanish uses the formal *usted* and Dutch the formal *u*, matching the
  other TackQuote storefronts.
- **Generated, not edited.** `tackquote-<locale>.po/.mo` (PHP) and
  `tackquote-<locale>-<md5>.json` (JED for `assets/js/tack-quotes.js`, named by the md5
  of that path) are written by `php bin/build-translations.php`. `tests/run.php` fails
  when they are stale, and `bin/build.sh` re-checks when PHP is available.
- **Precedence.** WordPress loads a translate.wordpress.org language pack from
  `wp-content/languages/plugins/` FIRST and uses this folder only when no pack exists
  for the locale, for both PHP and JS (the script is registered with
  `wp_set_script_translations( 'tackquote', 'tackquote' )` and no path, so it follows the
  same textdomain registry). A community translation therefore replaces the bundled one
  file by file, not string by string.
