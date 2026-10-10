# Customising the storefront look

Since 1.10.0 every storefront element the plugin renders takes the active theme's look by
default, and can be changed without editing plugin files. There are four layers; use the
lightest one that does the job.

1. **Theme styles** (no code). Every button is the theme's button, so the theme's own button
   settings restyle them (block themes: Site Editor > Styles > Blocks or Elements > Button;
   classic themes: the theme's `.button` rules).
2. **Settings**: TackQuote > Storefront > Styling.
3. **CSS variables**, in Appearance > Customize > Additional CSS (classic themes), the Site
   Editor's custom CSS (block themes), or a child theme stylesheet.
4. **Templates and filters**, for developers.

This file is documentation in the plugin's repository; it is not shipped in `tackquote.zip`
(`bin/build.sh` excludes `*.md`). The readme FAQ "Can I change how it looks?" summarises it.

## What the plugin does by default

| Element | Markup | Looks like |
|---|---|---|
| "Add to Quote", "Request a Quote" (product page) | `button` (+ `wp-element-button` on block themes) | the theme's button; "Request a Quote" is `alt` (primary) when the product is quote-only or unpriced |
| Product card control | as above, plus `wp-block-button__link` on block themes | the Product Collection's own card button |
| "Request a quote for your cart" | `button` | the theme's button |
| Floating launcher | `button` + `tack-quote-list-toggle` | the theme's button, pill-shaped and floating |
| Quote list drawer | `tack-quote-list-drawer` | theme surface/text colours, theme font |
| "Checkout as Quote" (drawer, quote page), "Send request" (form) | `button alt` | the theme's primary button |
| Request form (modal) | `woocommerce` scope, `form-row`, `input-text` | WooCommerce's form styling in the theme |
| Quote page | `<div class="woocommerce tackquote">`, `shop_table shop_table_responsive`, WooCommerce cart cell classes | WooCommerce's cart table in the theme; stacked on phones |
| Volume pricing table | `tackquote-quantity-breaks shop_table` | WooCommerce's table styling |
| Wholesale / net-terms forms | `woocommerce-form`, `form-row`, `input-text`, `select`, WooCommerce notices | My Account form styling |

Colours, fonts and spacing come from the theme. On block themes the panels read the
theme.json presets WordPress prints as `--wp--preset--color--base` / `--contrast`,
`--wp--preset--spacing--*`, `--wp--preset--shadow--natural`; classic themes without presets
get a neutral white surface with near-black text (contrast 16:1). The plugin's stylesheets
contain no colour outside a `var()` fallback (enforced by `tests/theme-blend-test.php`).

## Settings (TackQuote > Storefront > Styling)

| Setting | Option | Effect |
|---|---|---|
| Use the theme's styles only | `tack_quotes_theme_styles_only` (`yes`/`no`, default `no`) | Loads only `tackquote-layout` (positioning, `hidden`, an opaque surface for the floating panels). `tackquote` (appearance) is not loaded. |
| Accent colour | `tack_quotes_accent_color` (hex, default empty) | Sets `--tackquote-accent` and repaints the plugin's buttons and launcher with it (text colour chosen for contrast). Saved through `sanitize_hex_color()`; nothing is printed when empty. |

The launcher's position, offsets, label, size, pages and mobile behaviour are under
"Quote list & launcher" on the same tab.

## CSS variables

Set them on `:root` (or any ancestor, e.g. `body`). Every one is read with a fallback, so
unset means "the default in the right column".

| Variable | Used for | Default |
|---|---|---|
| `--tackquote-accent` | focus rings, order-limit edge, buyer-group badge; buttons only when the Accent colour setting is used | `currentColor` |
| `--tackquote-surface` | background of the drawer, request form and fixed cart bar | `var(--wp--preset--color--base, #fff)` |
| `--tackquote-text` | text colour of those panels | `var(--wp--preset--color--contrast, #1e1e1e)` |
| `--tackquote-border` | hairlines in the drawer, form and tables | `color-mix(in srgb, currentColor 15%, transparent)` |
| `--tackquote-radius` | panel and icon-button corners; the launcher's too unless `--tackquote-launcher-radius` is set | panels 8px, icons 4px, launcher pill |
| `--tackquote-launcher-radius` | launcher corners | `999px` |
| `--tackquote-shadow` | drawer, form, launcher and cart bar shadow | `var(--wp--preset--shadow--natural, …)` |
| `--tackquote-overlay` | backdrop behind the request form | `rgb(0 0 0 / 0.5)` |
| `--tackquote-space` | padding inside the drawer and form | `var(--wp--preset--spacing--30, 1rem)` |
| `--tackquote-gap` | gap between buttons | `var(--wp--preset--spacing--20, 0.625rem)` |
| `--tackquote-font-size` | drawer text size | `0.9375rem` |
| `--tackquote-z-index` | launcher (form is +1, cart bar −1) | `99999` |
| `--tackquote-drawer-width` | drawer width | `22rem` (never wider than the screen) |
| `--tackquote-modal-width` | request form width | `30rem` |
| `--tackquote-launcher-offset-x` / `-y` | launcher distance from the side / bottom (overrides the setting) | the setting, 20px |
| `--tackquote-launcher-size` | launcher minimum height (and width on phones) | auto; 48px on phones |
| `--tackquote-focus` | focus ring colour | `--tackquote-accent` |
| `--tackquote-error` / `--tackquote-success` | request form message edge | WooCommerce's `--wc-red` / `--wc-green` |

Example (Additional CSS):

```css
:root {
  --tackquote-accent: #0a7c55;
  --tackquote-radius: 0;
  --tackquote-drawer-width: 26rem;
}
```

The launcher and drawer use logical properties (`inset-inline-end`, …), so on a right-to-left
site "bottom right" is mirrored to the left, as WordPress mirrors the rest of the page. The
opening animation only runs when the visitor has not asked for reduced motion.

## Stable classes

Container and hook classes, safe to target from theme CSS: `tack-quote-buttons`,
`tack-add-to-quote-btn`, `tack-quote-btn`, `tack-card-quote-btn`, `tack-card-quote-link`,
`tack-quote-cart-btn`, `tack-quote-cart-fixed`, `tack-quote-list-widget` (+ `tack-fab-left`,
`tack-fab-compact`, `tack-fab-icon-only`, `tack-fab-no-count`, `tack-fab-hide-mobile`),
`tack-quote-list-toggle`, `tack-quote-list-drawer`, `tack-quote-list-item`,
`tack-quote-list-checkout`, `tack-quote-modal-overlay`, `tack-quote-modal`,
`tack-quote-modal-submit`, `tack-quote-modal-cancel`, `tack-quote-page`,
`tack-quote-page-table`, `tack-quote-page-submit`, `tack-quote-page-continue`,
`tackquote-quantity-breaks`, `tackquote-order-limit`, `tackquote-buyer-group`,
`tack-quote-only-cta`, `tackquote-storefront-form`, `tackquote-form`, `tackquote-submit`.
Stylesheet handles: `tackquote-layout`, `tackquote`.

## Templates

Loaded with WooCommerce's `wc_get_template()`, so the lookup order is WooCommerce's:
`yourtheme/woocommerce/tackquote/<file>`, then `yourtheme/tackquote/<file>`, then the plugin.
Copy the file into your (child) theme and edit the copy. Keep every `id`, `data-*` attribute,
`hidden` attribute and form field name: the storefront script and the form handlers rely on
them. Each template has an `@version`; compare it after updating the plugin.

| Template | Renders | Variables |
|---|---|---|
| `tackquote/quote-list-drawer.php` | launcher and drawer (footer) | `$classes`, `$fab`, `$opens`, `$page_url`, `$style`, `$toggle_class`, `$checkout_class`, `$checkout_label` |
| `tackquote/quote-page.php` | `[tackquote_quote_page]` | `$target_price`, `$message`, `$message_max`, `$shop_url`, `$continue_class`, `$submit_class`, `$submit_label` |
| `tackquote/single-product/quantity-breaks.php` | volume pricing table | `$classes`, `$caption`, `$rows` (`qty`, `qty_label`, `price_html`), `$product` |
| `tackquote/myaccount/form-wholesale-application.php` | wholesale application form | `$notices`, `$title`, `$description`, `$action_url`, `$multipart`, `$fields`, `$blocked`, `$hidden`, `$submit_label`, `$submit_class` |
| `tackquote/myaccount/form-net-terms-application.php` | net-terms application form | `$notices`, `$description`, `$action_url`, `$fields`, `$hidden`, `$submit_label`, `$submit_class` |
| `tackquote/myaccount/net-terms-account.php` | the customer's active net terms, shown instead of the application form | `$notices`, `$heading`, `$description`, `$lines` (`terms`, `credit_limit`, `available`), `$terms_days`, `$credit_limit`, `$available`, `$currency` |

The two form templates' output, and the net terms summary's, is still passed through the plugin's allowed-HTML list
(`Tack_Storefront_Forms::allowed_html()`), so tags and attributes outside it are removed.
The request form (modal) and the drawer's rows are built by `assets/js/tack-quotes.js`, not
by a template; restyle them with CSS.

## Filters (all since 1.10.0)

| Filter | Arguments | Use |
|---|---|---|
| `tackquote_button_label` | `$label`, `$option` | change "Add to Quote" / "Request a Quote" / "Checkout as Quote" (`$option` names which); a blank return keeps the label |
| `tackquote_button_classes` | `$classes`, `$extra` | replace the button classes (keep the `tack-*` classes in `$extra`) |
| `tackquote_theme_styles_only` | `$only` | force layout-only CSS from a theme |
| `tackquote_quote_page_args` | `$args`, `$atts` | template variables of the quote page |
| `tackquote_quote_list_drawer_args` | `$args` | template variables of the launcher/drawer |
| `tackquote_quantity_breaks_args` | `$args`, `$product` | template variables of the volume table |
| `tackquote_storefront_form_args` | `$args`, `$form` (`wholesale` or `credit`) | template variables of the account forms |

Older filters still apply: `tack_quotes_card_options_label`, `tackquote_quantity_break_steps`.

Example (theme `functions.php`):

```php
add_filter( 'tackquote_button_label', function ( $label, $option ) {
	return 'tack_quotes_request_button_label' === $option ? __( 'Ask for a price', 'my-theme' ) : $label;
}, 10, 2 );
```
