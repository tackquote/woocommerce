# Translations

Bundled translations of the storefront text for German (de_DE), Spanish (es_ES),
French (fr_FR), Italian (it_IT), Japanese (ja), Dutch (nl_NL) and Brazilian
Portuguese (pt_BR). English is the source.

- **Two catalogues.** Most storefront text comes from TackQuote's shared widget
  catalogue (the same text the BigCommerce, Shopify, Wix and Squarespace storefronts
  show), vendored in `source/widget/`; `source/strings.json` maps a plugin string to a
  catalogue entry only where the meaning is identical. Storefront text that catalogue
  has no identical entry for (WooCommerce notices, the My Account forms, the quote
  form's company fields, the quote page) comes from the plugin's own catalogue in
  `source/local/` (keys `woo.*`, added in 1.11.0), in the same format.
- **Nothing on the storefront stays English.** `source/strings.json` lists the files
  shoppers see (`storefront.sources`); `tests/i18n-test.php` fails when a msgid in them
  is not mapped and translated in all seven languages, unless `storefront.notStorefront`
  names it with the reason. What stays English is shop-manager text only: the settings
  pages, the product editor's "Quote only" switch, the payment-gateway settings, the
  connection test results and an administrators-only form hint.
- **Vocabulary.** The `source/local/` translations use WooCommerce core's own terms
  for each locale (translate.wordpress.org, WooCommerce stable: for example "Add to
  cart", "Checkout", "Billing address", "Street address", "First name"; German from the
  formal `de/formal` set) and the shared catalogue's terms for quote, net terms and
  wholesale.
- **Review state.** de, es, fr, ja, nl and pt-BR had a consistency and register review
  in the catalogue. **Italian is still machine-translated and pending native review**
  (`source/widget/machine-translated.json`; the `.po` marks those entries). **Every
  `source/local/` entry is machine-assisted and pending native review in all seven
  languages**; the `.po` marks those entries too.
- **Register.** Spanish uses the formal *usted*, Dutch the formal *u*, German *Sie* and
  Italian *Lei*, matching the other TackQuote storefronts.
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
