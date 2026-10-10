=== TackQuote for WooCommerce ===
Contributors: tackquote
Tags: woocommerce, request a quote, b2b, wholesale, rfq
Requires at least: 6.4
Requires Plugins: woocommerce
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.10.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Request a quote from WooCommerce for B2B wholesale quoting — sync orders to your TackQuote account with one API key.

== Description ==

**TackQuote for WooCommerce** lets B2B and wholesale shoppers ask for a price instead of checking out, and can sync orders one way to your TackQuote account.

It is for stores that sell to trade customers (wholesalers, distributors, manufacturers) and quote prices per account, per quantity or per order. Setup is one field: paste your TackQuote API key.

[Learn more about the WooCommerce integration](https://tackquote.com/integrations/woocommerce) &middot; [Create a free TackQuote account](https://app.tackquote.com/register)

= Features =

* **Add to Quote** and **Request a Quote** buttons on product pages, in your theme's button style.
* A floating **quote list** with **Checkout as Quote**: several products in one request, separate from the cart.
* **Quote-only mode** for the whole store, signed-out visitors, chosen roles, or all but approved wholesale accounts.
* **Quote only per product**, with variations following their product and still selectable for a quote.
* Optional quote buttons on **product cards** and the **cart page**, and a **quote page** with target prices.
* A configurable **floating launcher**: side, offsets, label, icon, count, size, pages, mobile.
* **B2B pricing** from TackQuote: account prices and volume tables, net of tax on any tax setting.
* **Order limits**, a **buyer-group badge** and **tax-exempt buyers**.
* Per buyer group: hidden **product categories**, free or discounted **shipping**, an optional **WordPress role**.
* **Wholesale** and **net-terms application forms**, and a **Net terms** payment method with an optional PO number.
* **Accepted quote checkout**: the buyer pays an accepted quote at your normal checkout, at the quoted prices.
* Optional one-way **order sync** to TackQuote, off by default and queued so it never runs inside checkout.
* Works with **HPOS**, the Cart and Checkout blocks, and block and classic themes.
* Storefront text in English and seven more languages.

The FAQ below explains each feature, and lists exactly what the plugin sends and stores.

= What it does not do =

* It does not replace WooCommerce checkout or act as a full CPQ workspace.
* Order sync is **outbound only** — it does not import quotes as orders, sync your catalogue, or touch inventory.
* Failed syncs are logged (WooCommerce → Status → Logs, source `tackquote`) and never block checkout.

Source code is developed in the open at [github.com/tackquote/woocommerce](https://github.com/tackquote/woocommerce).

== External services ==

This plugin connects to the TackQuote API, a third-party B2B quoting service operated by
TackQuote, to send quote requests that shoppers submit on your store and — only if you
switch it on — to send data about orders placed in your store.

The service is required for the plugin to work. Without a TackQuote account and API key the
plugin cannot create quotes, and its storefront buttons do nothing. Nothing is sent anywhere
until you enter an API key.

All requests go to the API base URL set under **TackQuote → TackQuote API URL**, which is
`https://api.tackquote.com/v1` unless your TackQuote support contact gave you a different
one. Every request carries your TackQuote API key so the service can identify your account,
and (since 1.10.0) headers naming this plugin's version (`X-TackQuote-Plugin-Version`) and the
store's address (`X-TackQuote-Site-Url`), so TackQuote can tell which build and store sent it. The API key needs the `buyers:write` scope for
the two application forms below; the read-only storefront lookups need no extra scope.

1. **Connection test** — `GET /integrations/woocommerce/ping`; on 404, `GET /health` (key unverified).
Sent when an administrator clicks "Test TackQuote connection", and at most daily from the
storefront for item 11. Sends your API key and the store's address only: no order or customer data.

2. **Quote form field policy** — `GET /integrations/woocommerce/registration-config`.
Sent on storefront page views while quote buttons are on, at most every 15 minutes. Same data as item 1.

3. **Quote request** — `POST /integrations/woocommerce/quote-requests`.
Sent when a shopper submits the quote form. Sends what that shopper typed
into the form, plus the products being quoted: email address, first and last name, phone
number if given, company name and any company details the seller's registration policy
requires (legal name, tax/VAT ID, registration number, website, address, city, state, postal
code, country, company phone, industry, employee count), the free-text note if written, and
for each requested product its name, SKU, quantity, unit price excluding tax, WooCommerce
product ID and any target price, together with the store's currency code.

4. **Order sync** — `POST /integrations/woocommerce/order-sync`. **Off by default.**
Sent when an order is created and each time its status changes, but only if the merchant has
switched on "Sync orders to TackQuote". Sends the whole order: the customer's billing and
shipping addresses, email address and phone numbers, WooCommerce customer ID, their order
note, the order ID, number and status, currency and totals, coupon codes, timestamps, the
payment method ID and title, the payment gateway's transaction ID, and every line item with
its name, SKU, product and variation IDs, quantities, totals, taxes and item meta. No card
numbers, no card details and no gateway credentials are ever sent. The **Privacy** section
below lists every field individually.

5. **B2B pricing, order limits and buyer group** — `POST /storefront-pricing/resolve` (cart
prices), `GET /storefront/v1/wholesale-price`, `GET /storefront/v1/quantity-breaks`,
`GET /storefront/v1/order-limits` and `GET /storefront/v1/buyer-group`, falling back to the
older `GET /storefront-b2b/order-limits` and `GET /storefront-b2b/buyer-group` on a TackQuote
server without the `/storefront/v1` routes. Only when the merchant has switched on B2B pricing,
order limits, the buyer-group badge, any buyer-group rule or tax-exempt buyers. Sent while
customers browse (order limits also for guests). Sends the product SKU and quantity and, for
a signed-in customer, their email address and WordPress user ID (digits only).

6. **Wholesale application** — `GET /integrations/woocommerce/wholesale-form` (the form's
fields, by form slug; sends no customer data) and `POST /integrations/woocommerce/wholesale-form/submit`.
Sent when a page with the `[tackquote_wholesale_application]` shortcode or the "Wholesale account"
My Account tab is viewed, and when a shopper submits that form. Sends the answers the shopper
typed into the seller's form (for example company name, email, phone, address, tax ID) and,
for a signed-in customer, their WordPress user ID as `wooCustomerId`. An application that carries
files is sent instead to `POST /storefront/v1/wholesale-signup/<slug>` with the same
answers, the signed-in customer's account email address and WordPress user ID
(`buyerEmail`, `buyerExternalId`), and the upload IDs of item 11.

7. **Net-terms application** — `POST /storefront/v1/credit-application`, falling back to
`POST /integrations/woocommerce/credit-application` on an older TackQuote server. Signed-in
customers only, when they submit the "Net terms" form. Sends the account's email address, the
WordPress user ID (in the query, v1 route only), and what the customer typed: legal business
name, contact phone, tax/VAT ID, billing address, requested credit limit, requested payment
terms, up to three trade references (company, contact name, email, phone) and notes.

8. **Wholesale price gate** — `GET /storefront/v1/price-access`. **Off by default.**
Sent only when the merchant chose the quote-only scope "Everyone except approved wholesale
accounts", and only for a signed-in customer, at most once every five minutes per customer
(one minute after a failure). Sends that customer's account email address and their
WordPress user ID (`buyerEmail`, `buyerExternalId`). TackQuote answers whether that buyer's
wholesale application is approved. Nothing is sent for a signed-out visitor.

9. **Net terms** — `GET /storefront/v1/net-terms`. Sent for a signed-in customer at checkout
while the "Net terms (TackQuote)" payment method is enabled (off by default), and when a
signed-in customer views the "Net terms" My Account tab or the `[tackquote_net_terms_application]`
shortcode (the tab is off by default), to show their terms or a pending application instead of
the form. Sends the account email and WordPress user ID. One answer per customer is reused for
up to a minute. Fails closed: without an answer, checkout hides the method and the tab shows
the application form.

10. **Accepted quote checkout** — `GET /integrations/woocommerce/quote-checkout/<token>`, when a
buyer opens a TackQuote checkout link (`?tackquote_checkout=`) on your store. Sends only the
link's single-use token and your API key.

11. **Attachments** — `POST /storefront/v1/quote-upload` and
`POST /storefront/v1/wholesale-upload`. Quote-request files only when the merchant switched on
"Allow attachments on quote requests" (off by default); wholesale-application files only for a
signed-in customer; both only when the TackQuote server's connection check (`ping`) lists
`attachments`. Sent when a shopper attaches a file. Sends the file's bytes (a PDF, JPEG or PNG of
at most 5 MB, checked on your store first) and its file name, plus, for a signed-in customer, the
account email address and WordPress user ID (`buyerEmail`, `buyerExternalId`); for a wholesale
form also the form slug and field key. A guest's quote files carry no identity, only the
single-use upload token TackQuote issues. Files are streamed from your server and never stored
on your site. The quote request then sends the upload IDs (`uploadIds`) and, for a guest, the
token (`uploadToken`). TackQuote deletes a file that was never attached to a request after 24
hours (quote files) or 7 days (application files); attached files are kept with the quote or
application for the seller.

This plugin sends data to no other external service.

The TackQuote service is provided by TackQuote. By using this plugin you agree to their
terms. Please review them before entering an API key:

* Terms of Service: https://tackquote.com/terms
* Privacy Policy: https://tackquote.com/privacy

== Installation ==

WooCommerce must be installed and active first, and you need a TackQuote account —
[create one here](https://app.tackquote.com/register) if you do not have one yet.

= Install =

1. In WP Admin go to **Plugins → Add New**, search for **TackQuote for WooCommerce**, and click **Install Now**.
2. Activate **TackQuote for WooCommerce**.
3. Open **TackQuote** in the admin menu.
4. Paste your **TackQuote API Key** (TackQuote → Settings → Developer → API Keys). Leave the API URL as the default unless support gives you another base URL.
5. Work down the numbered sections in order — they are arranged as a setup sequence, and each one only depends on the ones above it. Then click **Save TackQuote settings**.
6. Click **Test TackQuote connection** to verify.

Before you enter an API key, read the **External services** section above: the plugin cannot
create quotes without sending data to the TackQuote API.

= Manual install =

1. Download `tackquote.zip` from [the releases page](https://github.com/tackquote/woocommerce/releases), or build it from source with `bash bin/build.sh`.
2. In WP Admin go to **Plugins → Add New → Upload Plugin**, upload the ZIP, and choose **Replace current with uploaded** if an older copy is already installed.
3. Activate, then follow steps 3–6 above.
4. Your API key and toggles are stored as WordPress options and are preserved across updates.

== Privacy ==

This plugin sends data to TackQuote, a third-party service, over HTTPS. It sends nothing anywhere else, and it never sends payment card data.

= Where data is sent =

To the TackQuote API base URL configured under **TackQuote → TackQuote API URL** — `https://api.tackquote.com/v1` unless your TackQuote support contact gave you another one. Endpoints used:

* `GET /integrations/woocommerce/ping` (and `GET /health`) — connection test and server features. Sends only the store's address.
* `GET /integrations/woocommerce/registration-config` — fetches which fields the quote form should ask for. Sends only the store's address.
* `POST /integrations/woocommerce/quote-requests` — a shopper's quote request.
* `POST /integrations/woocommerce/order-sync` — order sync. **Only when the merchant has switched order sync on. It is off by default.**
* `POST /storefront-pricing/resolve`, `GET /storefront/v1/wholesale-price`, `GET /storefront/v1/quantity-breaks`, `GET /storefront/v1/order-limits`, `GET /storefront/v1/buyer-group` (and the older `GET /storefront-b2b/order-limits`, `GET /storefront-b2b/buyer-group`) — B2B prices, limits (also for guests, by SKU), the buyer group and its tax exemption. Only when the matching feature is switched on.
* `GET /integrations/woocommerce/wholesale-form`, `POST /integrations/woocommerce/wholesale-form/submit` — the wholesale application form.
* `POST /storefront/v1/credit-application` (or `POST /integrations/woocommerce/credit-application`) — a signed-in customer's net-terms application.
* `GET /storefront/v1/net-terms` — a signed-in customer's net terms, at checkout and on the "Net terms" My Account tab (both off by default).
* `GET /integrations/woocommerce/quote-checkout/<token>` — opens an accepted quote's checkout link. Sends only the token.
* `POST /storefront/v1/quote-upload`, `POST /storefront/v1/wholesale-upload`, `POST /storefront/v1/wholesale-signup/<slug>` — files a shopper attaches to a quote request (only when "Allow attachments on quote requests" is on; off by default) or to a wholesale application (signed-in customers only), and an application that carries files. Sends the file's bytes and name, and the signed-in customer's account email address and WordPress user ID; a guest's quote files carry only a single-use upload token. Unattached files are deleted by TackQuote after 24 hours (quote) or 7 days (application).
* `GET /storefront/v1/price-access` — whether a signed-in customer's wholesale application is approved. **Only when the merchant chose the "approved wholesale accounts" quote-only scope. It is off by default.** Sends the customer's account email address and WordPress user ID.

Every request carries `X-TackQuote-Plugin-Version` (the plugin's version) and `X-TackQuote-Site-Url` (your store's address, `home_url()`).

= The full lists =

Every field the plugin sends, and everything it stores on your site, is listed in the FAQ below:

* **What does a quote request send?**
* **What do the B2B lookups and application forms send?**
* **What does order sync send?** (off by default)
* **What does the plugin store on my site, and what does deleting it remove?**

= If you are a merchant in the EU, UK or another jurisdiction with a transfer regime =

Order sync sends personal data about your customers to TackQuote, which makes
TackQuote a processor acting on your instructions. That is why it ships switched
**off**. Before you
enable it, satisfy yourself that you have a lawful basis and, where required, a data
processing agreement in place with TackQuote. Your own privacy policy should name
TackQuote as a recipient; the plugin adds suggested wording to
**Settings → Privacy** for you to review and adapt.

= Suggested privacy policy text =

The plugin adds suggested wording to **Settings → Privacy → Policy guide** in WordPress, listing the same fields. Edit it to match how your store actually uses the plugin.

== Frequently Asked Questions ==

= Where do I get an API key? =

In TackQuote, go to **Settings → Developer → API Keys**.

= Does it work with High-Performance Order Storage (HPOS)? =

Yes. The plugin declares HPOS (`custom_order_tables`) compatibility via WooCommerce `FeaturesUtil` and uses the WooCommerce order CRUD APIs (`wc_get_order`, order status hooks) rather than direct `wp_posts` order queries. You can run with HPOS enabled.

= Is order sync bidirectional? =

No. Sync is one-way: WooCommerce → TackQuote on order create and status change. Turning the toggle off stops new pushes; it does not delete data already in TackQuote.

= My store collects a purchase-order number at checkout. Can it be synced? =

Yes, with one line of code. WooCommerce core has no purchase-order field, and there is no
meta key this plugin could guess that would be right for every B2B extension — a guess that
looks correct and silently returns nothing is worse than an empty field. So the value is
read through a filter your theme or a small site plugin fills in:

`add_filter( 'tack_quotes_order_po_number', function ( $po, $order ) { return $order->get_meta( '_my_checkout_po_field' ); }, 10, 2 );`

Replace `_my_checkout_po_field` with the meta key your checkout writes. Without this filter
nothing is sent and TackQuote records no purchase-order number.

= Do the quote buttons replace checkout, or touch the WooCommerce cart? =

No, and no. "Add to Quote" adds the product to a separate, browser-side quote list — it never touches the WooCommerce cart, stock, or totals. "Checkout as Quote" creates a quote request in TackQuote from that list's contents. Customers can still shop and check out through WooCommerce completely normally, at the same time, with no interaction between the two.

= Why doesn't "Add to Quote" send a quote request immediately? =

So shoppers can add multiple products before requesting one combined quote. Use the floating "Quote list" button (bottom-right) once you've added everything you want quoted, then click "Checkout as Quote".

= Can shoppers start a quote from the shop page or the cart? =

Yes, since 1.10.0, and both are off until you switch them on under **TackQuote → Storefront**. "Product cards" adds "Add to Quote" to every simple product in the shop, category and search lists; variable, grouped and external products link to their page instead, because a card cannot say which variation is wanted. "Cart page" adds "Request a quote for your cart" under Proceed to checkout on the classic cart; on the Cart block it appears as a fixed button at the bottom of the cart page. It copies the cart into the quote list and leaves the WooCommerce cart as it was.

= How do I make a single product quote-only? =

Edit the product and tick **Quote only** in the General tab of the product data panel. Add to Cart disappears for that product (and for every variation of a variable product), a hand-made `?add-to-cart=` link and the Cart/Checkout blocks refuse it too, and a cart that already held it loses that line with a notice. Unlike the store-wide mode, this applies to shop managers as well, so your own test sees what customers see.

= Can I keep the cart for approved wholesale customers only? =

Yes. Turn on quote-only mode and choose "Everyone except approved wholesale accounts". The plugin asks TackQuote whether the signed-in customer's wholesale application is approved, and only then shows the cart. If TackQuote cannot be reached, the customer sees the quote-only catalogue (this one check fails closed, because it is a price gate). It needs the API key.

= Which features need a linked account? =

Prices, quantity breaks, order limits, the buyer-group badge and the price gate follow the customer's account email. Net terms, tax exemption and credit standing go only to customers the seller has linked to a TackQuote buyer: approving the customer's wholesale application links them, or the seller links the WordPress user under **Buyers → buyer → WooCommerce customer** in TackQuote. Approving a net-terms application does not link the account. A matching email alone never links one, because WooCommerce does not verify the email at registration. If the email belongs to a buyer already linked to a different WordPress user, that customer gets none of these B2B features.

= Where do target prices on the quote page go? =

To the quote line, where the seller sees "Buyer asked for …" beside your store price, which the quote keeps. A TackQuote server older than the attachments feature cannot take the field, so there they go into the request's note instead, one line per product ("Target prices: …"), after the shopper's own message.

= Can I change how it looks? =

It takes your theme's look by default: every button is your theme's button (so Site Editor > Styles > Blocks/Elements > Button restyles them), fields and tables use WooCommerce's classes, and the drawer and quote form use your theme's colours and fonts. Under **TackQuote → Storefront → Styling** you can pick an accent colour for the quote buttons and launcher, or tick "Use the theme's styles only" to load layout rules alone. For finer changes, set CSS variables in Appearance > Customize > Additional CSS or the Site Editor's custom CSS, for example `:root { --tackquote-radius: 0; --tackquote-drawer-width: 26rem; }` (also `--tackquote-accent`, `--tackquote-surface`, `--tackquote-text`, `--tackquote-border`, `--tackquote-shadow`, `--tackquote-z-index`, `--tackquote-launcher-offset-x` / `-y`). Developers can copy any file from the plugin's `templates/tackquote/` folder (quote page, quote list drawer, volume table, wholesale and net-terms forms) into `yourtheme/woocommerce/tackquote/` and edit it there, as with WooCommerce's own templates, and use the filters `tackquote_button_label`, `tackquote_button_classes`, `tackquote_theme_styles_only`, `tackquote_quote_page_args`, `tackquote_quote_list_drawer_args`, `tackquote_quantity_breaks_args` and `tackquote_storefront_form_args`. The full list is in `docs/CUSTOMISATION.md` in the plugin's GitHub repository.

= Which languages does it ship in? =

English, plus German, Spanish, French, Italian, Japanese, Dutch and Brazilian Portuguese for the storefront text, in `languages/` (machine-assisted from the TackQuote storefront catalogue; Italian awaits native review). A translate.wordpress.org language pack, when one exists, replaces the bundled file.

= What does a quote request send? =

Sent when a shopper submits the quote form, using only what they typed into it:

* Email address
* First name, last name
* Phone number (if provided)
* Company name, and any company fields the seller's registration policy requires (for example legal name, tax/VAT ID, registration number, address, city, state, postal code, country, company phone, industry, employee count)
* The free-text note, if written (capped at 2,000 characters). On a TackQuote server older than the attachments feature, any target prices the shopper typed on the quote page are added to the note, one line per product
* The requested products: name, SKU, quantity, unit price excluding tax, the WooCommerce product ID and, from the quote page, the target price if the shopper typed one
* The store's currency code
* Attachments (only when "Allow attachments on quote requests" is on): each attached file's bytes and file name, sent before the request to `/storefront/v1/quote-upload`, with the signed-in customer's account email address and WordPress user ID, or for a guest a single-use upload token; the request then carries the upload IDs and the guest token. Files are never stored on your site

= What do the B2B lookups and application forms send? =

* **B2B lookups**: the product SKU and quantity, the signed-in customer's email address, and their WordPress user ID (a number, sent only beside the email and only while they are signed in).
* **Wholesale application**: exactly the answers the shopper typed into the seller's form, plus the WordPress user ID (`wooCustomerId`) when they are signed in. Files (signed-in customers only, when the TackQuote server supports attachments): each file's bytes and name, the form slug and field key, the account email address and WordPress user ID; the application then goes to `/storefront/v1/wholesale-signup/<slug>` with the same answers and the upload IDs.
* **Net-terms application**: the account's own email address (never a typed one), the WordPress user ID, and the legal business name, phone, tax/VAT ID, billing address, requested limit and terms, up to three trade references and notes the customer typed.

= What does order sync send? =

Sent for each order when it is created and when its status changes, if the merchant
has enabled **Sync orders to TackQuote**. This is the whole order. Read this list
before switching order sync on.

**The customer's identity and addresses**

* Billing address in full: first name, last name, company, street (both lines), city, state or county, postal code, country, email address and phone number
* Shipping address in full: the same fields, including shipping phone where WooCommerce holds one
* The WooCommerce customer ID, or `0` for a guest order
* The customer's order note, as they wrote it

**The order**

* WooCommerce order ID, order number and status
* Currency, item subtotal, discount total, shipping total, tax total and order total
* Coupon codes applied
* A purchase-order number, from the optional checkout field (off by default) or the `tack_quotes_order_po_number` filter
* The quote reference, for an order placed through a quote checkout link
* Created, last-modified, paid and completed timestamps
* An idempotency key, so a repeated delivery of the same order state can be discarded

**Payment**

* Payment method ID and its display title (for example `stripe` / "Credit Card")
* The gateway transaction ID, where the gateway recorded one
* Whether the order still needs payment, and when it was paid

**No card numbers, no card details, and no gateway credentials are ever sent.** The
transaction ID is a reference the gateway issued, not an instrument.

**Line items**

* Product name, SKU, WooCommerce product ID and variation ID
* Quantity, line subtotal, line total and line tax
* Item meta — the variation attributes and any custom item fields your store records on a line (for example "Size: Large", "Colour: Blue"). If your checkout writes customer-supplied text onto a line item, it is included here
* Shipping lines: method title and cost. Fee lines: name and amount

= What does the plugin store on my site, and what does deleting it remove? =

* Plugin settings, as WordPress options: the TackQuote API key, API URL, button labels, and the feature toggles.
* `tack_quotes_registration_config` — a transient caching the quote-form field policy for 15 minutes.
* `tack_qr_*` — short-lived transients counting quote requests per visitor for rate limiting. They hold a salted hash of the visitor's IP address, never the address itself, and expire after 5 minutes.
* `tack_quotes_wholesale_form_cache` — a transient caching wholesale form definitions for 5 minutes (60 seconds after a failure).
* `tack_quotes_storefront_v1_missing` — a transient remembering for one hour that the TackQuote server has no `/storefront/v1` routes.
* `tack_quotes_wholesale_form_missing` — a transient listing, for one day, the wholesale form slugs already logged as matching no form, so the log gets one line a day per slug.
* `tack_quotes_server_capabilities` — a transient caching, for one day (ten minutes after a failed check), which optional features the TackQuote server advertises (for example `attachments`) and a 16-character SHA-256 prefix of the key it was read with (never the key).
* `tack_qu_*`, `tack_qf_*`, `tack_qc_*` — ten-minute transients counting attachment uploads, applications and checkout links per visitor for rate limiting, keyed on a salted hash of the IP address, never the address. Each counter also has a `…s` twin for the connecting address, which a client cannot forge. Attached files themselves are never written to your site: PHP's temporary copy is deleted as soon as the file has been sent.
* `tack_quotes_connection_check` — a transient remembering for one day whether the settings page's last "Test connection" passed, when, the message shown, and a 16-character SHA-256 prefix of the key that was tested (never the key itself). It lets the Overview say "Connected" only for the key saved now.
* `tack_sf_*` — two-minute transients carrying an application form's outcome (success or error text and what was typed, except phone numbers and tax, VAT or registration numbers, for refilling the form) back to the page after it is submitted. Read once and deleted.
* `tack_quotes_vat_exempt_applied` — a WooCommerce session value remembering that this plugin set the customer tax exempt, so the exemption can be withdrawn. Never saved to the customer record.
* Net terms: `tack_nt_<user id>` (TackQuote's answer, one minute), order meta `_tackquote_net_terms` and `_tackquote_po_number`, option `woocommerce_tackquote_net_terms_settings`.
* Quote checkout: session value `tackquote_quote_checkout`, order meta `_tackquote_quote_ref` and `_tackquote_quote_number`.
* `_tack_quotes_sync_key` — order meta recording which order state was last accepted by TackQuote, so the same state is not sent twice.

* User meta `_tack_known_email` (a copy of the account email), `_tack_email_unverified`, `_tack_mirrored_roles`, `_tack_role_mirror_checked`; product meta `_tackquote_quote_only`. Tools → Export/Erase Personal Data covers the user meta, and WooCommerce's order export covers the order meta above. Erasure deletes only the purchase-order number, and only when WooCommerce's "Remove personal data from orders on request" is on; the quote reference, quote number and net terms are business records and stay.

Deleting the plugin removes every option above, the fixed-name transients, the user meta, the product meta and queued order-sync jobs, on every site of a multisite network. Kept on purpose: `_tack_email_unverified` (no personal data; deleting it would trust a self-changed email again), the rate-limit counters (they expire within ten minutes and have no fixed name), and order meta (orders are financial records).

== Screenshots ==

1. Add to Quote and Request a Quote sit beside Add to cart on the product page (Twenty Twenty-Five).
2. Product cards in the shop grid can carry an Add to Quote button.
3. The quote drawer lists the chosen products and quantities, with Checkout as Quote.
4. The quote page from the `[tackquote_quote_page]` shortcode takes target prices and a message, and the request form can carry attachments.
5. Quote-only mode shows "Price on request" in place of prices, and single products can be marked "Available on quote".
6. A signed-in buyer sees their account price, their buyer group badge and a volume pricing table.
7. Order limits show on the product page and are enforced in the cart and the Checkout block.
8. Buyers TackQuote has approved can pay on net terms at checkout and add a purchase order number.
9. The wholesale application form in My Account is the form you design in TackQuote, file fields included.
10. A checkout link from an accepted quote fills the cart at the quoted prices with the quantities locked.
11. Categories hidden per buyer group: a guest (left) does not see the trade range that a Tier 2 buyer (right) sees.
12. Storefront buttons in German from the bundled translations.
13. The Overview tab shows the connection, the storefront mode, order sync and every B2B switch.
14. The Styling section of the Storefront tab: use the theme's styles only, or pick an accent colour.
15. The Buyer groups tab keeps payment and shipping methods for the groups you tick.
16. With an accent colour set, the quote buttons blend into Twenty Twenty-Four.

== Changelog ==

= 1.10.2 =
* **Fixed: after a quote request the shopper is no longer sent to a sign-in page.** The quote form used to show its confirmation for under a second and then load the buyer portal, which asks a shopper without a portal account to sign in. The form now stays open on "Your quote request was received. The seller will reply to you by email." and offers "Open your buyer portal" as a link when TackQuote sends one. Same for "Checkout as Quote".
* **My Account › Net terms shows the customer's terms.** A customer whose net terms are active sees their payment terms, credit limit and, when TackQuote reports it, the credit still available, instead of the application form. A customer whose application is under review sees "Your net-terms application is being reviewed." Anyone else, or anyone when TackQuote cannot be reached, gets the form as before. The answer is the one the "Net terms (TackQuote)" checkout method reads, shared for up to a minute. New template `tackquote/myaccount/net-terms-account.php`.
* **My Account › Wholesale account prefills the customer's name.** First name, last name, company name and phone are filled in from the customer's WooCommerce billing details, then their WordPress profile, like the quote form.

= 1.10.1 =
* No functional change: source comments and packaging cleaned for release; release audit added to the build.

= 1.10.0 =
* **Security and standards audit.** A customer who changes their own email is not trusted with another buyer's prices, net terms or tax exemption until confirmed; rate limits can no longer be dodged with a forged `X-Real-IP`; draft, private, password-protected and group-hidden products can no longer be quoted; uninstall removes everything the plugin stores.
* **Settings redesigned into tabs**, with an Overview; "Test connection" no longer calls a rejected key connected.
* **New, each off by default:** attachments on quote requests; per buyer group category visibility, shipping discounts and an optional role; wholesale and net-terms application forms and My Account tabs; tax-exempt buyers; net terms and a purchase-order number at checkout; a quote page, quote buttons on product cards and the cart; per-product quote only; accepted quote to store checkout.
* **Fixed:** order limits were never applied; stores that enter prices including tax were undercharged tax on TackQuote prices; the quote form's success message was invisible.
* Translations bundled for seven languages, and styling that follows your theme. Requires WordPress 6.4 and WooCommerce 8.0 or later.
* The full 1.10.0 notes are in changelog.txt.

Older releases are listed in changelog.txt, shipped with the plugin.
