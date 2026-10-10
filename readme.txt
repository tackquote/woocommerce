=== TackQuote for WooCommerce ===
Contributors: tackquote
Tags: woocommerce, request a quote, b2b, wholesale, rfq
Requires at least: 6.4
Requires Plugins: woocommerce
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.10.0
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
and (since 1.10.0) an `X-TackQuote-Plugin-Version` header naming this plugin's version, so
TackQuote can tell which build your store runs. The API key needs the `buyers:write` scope for
the two application forms below; the read-only storefront lookups need no extra scope.

1. **Connection test** — `GET /integrations/woocommerce/ping`; on 404, `GET /health` (key unverified).
Sent when an administrator clicks "Test TackQuote connection", and at most daily from the
storefront for item 11. Sends your API key only: no store, order or customer data.

2. **Quote form field policy** — `GET /integrations/woocommerce/registration-config`.
Sent on storefront page views while quote buttons are on, at most every 15 minutes. Sends your API key only: no store, order or customer data.

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

9. **Net terms at checkout** — `GET /storefront/v1/net-terms`. Off by default; only while the
"Net terms (TackQuote)" payment method is enabled, for a signed-in customer at checkout. Sends
the account email and WordPress user ID. Fails closed.

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

* `GET /integrations/woocommerce/ping` (and `GET /health`) — connection test and server features. Sends no store or customer data.
* `GET /integrations/woocommerce/registration-config` — fetches which fields the quote form should ask for. Sends no store or customer data.
* `POST /integrations/woocommerce/quote-requests` — a shopper's quote request.
* `POST /integrations/woocommerce/order-sync` — order sync. **Only when the merchant has switched order sync on. It is off by default.**
* `POST /storefront-pricing/resolve`, `GET /storefront/v1/wholesale-price`, `GET /storefront/v1/quantity-breaks`, `GET /storefront/v1/order-limits`, `GET /storefront/v1/buyer-group` (and the older `GET /storefront-b2b/order-limits`, `GET /storefront-b2b/buyer-group`) — B2B prices, limits (also for guests, by SKU), the buyer group and its tax exemption. Only when the matching feature is switched on.
* `GET /integrations/woocommerce/wholesale-form`, `POST /integrations/woocommerce/wholesale-form/submit` — the wholesale application form.
* `POST /storefront/v1/credit-application` (or `POST /integrations/woocommerce/credit-application`) — a signed-in customer's net-terms application.
* `GET /storefront/v1/net-terms` — net terms at checkout (off by default).
* `GET /integrations/woocommerce/quote-checkout/<token>` — opens an accepted quote's checkout link. Sends only the token.
* `POST /storefront/v1/quote-upload`, `POST /storefront/v1/wholesale-upload`, `POST /storefront/v1/wholesale-signup/<slug>` — files a shopper attaches to a quote request (only when "Allow attachments on quote requests" is on; off by default) or to a wholesale application (signed-in customers only), and an application that carries files. Sends the file's bytes and name, and the signed-in customer's account email address and WordPress user ID; a guest's quote files carry only a single-use upload token. Unattached files are deleted by TackQuote after 24 hours (quote) or 7 days (application).
* `GET /storefront/v1/price-access` — whether a signed-in customer's wholesale application is approved. **Only when the merchant chose the "approved wholesale accounts" quote-only scope. It is off by default.** Sends the customer's account email address and WordPress user ID.

Every request carries an `X-TackQuote-Plugin-Version` header naming the plugin's version: the software, not a person.

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

= 1.10.0 =
* **Security and standards audit.** A customer who changes their own email by any route (including the REST API, not only My Account) is no longer trusted with another buyer's prices, net terms or tax exemption until confirmed. Rate limits can no longer be dodged with a forged `X-Real-IP`, and now also cover the application forms and quote checkout links. Draft, private, password-protected and group-hidden products can no longer be quoted. The API key is never re-sent after an HTTP redirect. Uninstall removes every setting, the plugin's user and product meta and queued jobs. Personal-data export and erasure cover the plugin's user meta.
* **Attachments.** Quote requests can carry up to 3 files (PDF, JPEG or PNG, 5 MB each) through an optional "Attach files (optional)" control in the quote form, when you switch on "Allow attachments on quote requests" (Storefront tab, off by default) and your TackQuote server advertises attachments. Wholesale application file fields now accept a file from signed-in customers (guests see "Sign in to your account to attach files"). Files are checked on your store (count, size, extension and content), streamed server to server to TackQuote and never stored in WordPress; the seller sees them on the quote or application.
* **Fixed: "Test connection" no longer calls a rejected key connected.** A 401 or 403 from the ping now reads "Key rejected" (Overview and notice) and clears the cached server features, instead of falling back to the public `GET /health` and saying "Connected". `/health` is asked only when the ping route is missing (404), and then reads "Reachable, key not verified". Timeouts, 429 and 5xx read "Connection failed" with a try-again hint; a failed check is never cached as a capability list.
* **Settings page redesigned into tabs**: Overview, Connection, Storefront, B2B pricing, Buyer groups, Forms and Order sync. The Overview shows the connection (API host and the key's last four characters; "Connected" only after a passing test of the key saved now), the storefront mode, order sync (including a refusal TackQuote answered and the queue length), every B2B switch and a first-run checklist. Each tab is its own form with its own option group, so saving one tab never changes another. Short help under every field, with the full explanation kept under "Learn more"; switches, conditional fields, buyer-group grids with one column per group code, a sticky save bar, and a confirmation before removing the API key. Option names, defaults and storefront behaviour are unchanged. "Tax-exempt buyers" moved to the B2B pricing tab. The TackQuote mark replaces the generic dollar icon in the admin menu (a single-colour SVG that follows your admin colour scheme) and heads the settings page; nothing is added to your storefront.
* **Hide product categories per buyer group** (off by default; TackQuote → Buyer groups). A grid of your product categories against your group codes plus "Guests and customers in no group"; sub-categories follow their parent. Hidden products leave the shop, category, tag and search loops and product blocks (`pre_get_posts`, `tax_query` NOT IN, front end and Store API only), related products, up-sells and cross-sells (`woocommerce_product_is_visible`, `woocommerce_related_products`); their own URL answers 404 (`template_redirect`); they cannot be bought (`woocommerce_is_purchasable`, `woocommerce_variation_is_purchasable`); a line already in a cart is removed with a notice. Store managers, wp-admin and the `/wc/v3` REST API are not affected. If TackQuote cannot be reached the catalogue is shown, unless you tick "Also hide them when the buyer group is unknown".
* **Free or discounted shipping per buyer group** (off by default): free on chosen methods, a percentage off chosen methods, or only the methods that already cost nothing. Applied in the same `woocommerce_package_rates` callback as the group restrictions, after them; shipping tax is scaled with the cost. Only buyers TackQuote places in the group get it. These are plugin settings; shipping rules sent by TackQuote come later.
* The buyer group is now part of each shipping package, so WooCommerce recalculates cached rates when a buyer's group changes (this also applies to the 1.8 shipping restrictions).
* **Optional WordPress role per buyer group** (off by default): a signed-in buyer in group `TIER2` gets the extra role `tackquote_tier2` (capability `read` only), removed when the group changes. Roles the plugin did not add are never removed, an outage changes nothing, and roles never affect prices. Uninstall removes the roles the plugin created.

* **Wholesale application form on your store.** New shortcode `[tackquote_wholesale_application slug="…"]` (slug defaults to the one under TackQuote → Storefront forms) renders the form you design in TackQuote under Settings → Wholesale forms, with every field kind (text, email, phone, number, select, multi-select, checkbox, textarea, date, address, tax ID; conditional fields shown and hidden as the server decides). File fields show a notice: attachments arrive in a later release. Submissions are nonce-protected and sent server to server; a signed-in customer's details are prefilled and their WordPress user ID travels as `wooCustomerId` so approval links the account. Shoppers see a friendly success, pending or error message, never a raw server answer.
* **My Account tabs "Wholesale account" and "Net terms"** (both off by default; TackQuote → Storefront forms). Registered with `add_rewrite_endpoint` through WooCommerce's `woocommerce_get_query_vars` filter and `woocommerce_account_menu_items`; rewrite rules are flushed on activation, deactivation and once after an update. The net-terms form (also `[tackquote_net_terms_application]`) is for signed-in customers only, uses the account's own email, and accepts up to three trade references.
* **Tax-exempt buyers** (off by default). When TackQuote marks a signed-in customer's buyer group tax exempt, `WC()->customer->set_is_vat_exempt( true )` is applied on `woocommerce_before_calculate_totals`, once per request and never saved to the customer record. Missing, false or unreachable means tax is charged.
* **Product-page prices, quantity breaks, order limits and the buyer group now read TackQuote's shared storefront API** (`/storefront/v1/*`), showing the currency each price is in and marking a price that is the customer's own ("Your account price"). Cart prices still use `/storefront-pricing/resolve`. On a TackQuote server without these routes the plugin falls back to the previous ones.
* **Fixed: order limits were never applied.** The plugin read `minQuantity`/`maxQuantity`, which TackQuote has never sent (it sends `min`/`max`), so no minimum or maximum was shown or enforced. Only quantity rules are now read as quantities; an order-total rule's money amount is no longer mistaken for one.
* **Fixed: stores that enter prices including tax were undercharged tax on TackQuote prices.** TackQuote prices are net, but B2B prices were handed to WooCommerce as if they already included tax, so 10.00 net at 20 % was charged 10.00 in total instead of 12.00. B2B cart prices, product-page and listing prices, volume tables, the quote list and quote checkout now add tax at the rates WooCommerce takes back out (base rates, or the customer's when `woocommerce_adjust_non_base_location_prices` is off), so the cart's price excluding tax is exactly the TackQuote price; a VAT-exempt buyer pays exactly that, and non-taxable products are unchanged. Displayed TackQuote prices now follow your "Display prices in the shop" setting.
* Every request now carries an `X-TackQuote-Plugin-Version` header, and buyer lookups send the WordPress user ID beside the email (`buyerExternalId`) so TackQuote can link the account.
* **Fixed:** a customer whose email was self-changed and not re-confirmed is now priced as a guest in listings and the cart (B2B pricing read the email without that check), and cannot apply for net terms or receive a tax exemption in another buyer's name.
* **Add to Quote on product cards** (`woocommerce_after_shop_loop_item`, priority 11), off by default. Simple products go straight to the quote list; variable, grouped and external products link to their page.
* **Request a quote for your cart** on the cart page, off by default: under Proceed to checkout on the classic cart (`woocommerce_proceed_to_checkout`), and as a fixed button on the Cart block (printed on `wp_footer` on the cart page when the cart has lines). It re-reads the live cart from WooCommerce's Store API before copying it into the quote list.
* **Floating launcher settings**, mirroring the Shopify launcher: position, side and bottom offsets, show on (all pages, product pages, cart page, nowhere), label, icon only, item count, size, hide on mobile. Compact on phones. Defaults reproduce the 1.8 launcher.
* **Quote page** shortcode `[tackquote_quote_page]` sharing the drawer's list: edit quantities, an optional target price per line (sent as the line's target price, shown to the seller as "Buyer asked for"; in the request note on older TackQuote servers) and a message. New setting "Quote button opens: drawer or page", drawer by default.
* **Signed-in buyers see their price at the line quantity** in the quote list and on the quote page, re-priced (debounced) through TackQuote pricing when the merchant uses it.
* **Per-product Quote only** checkbox (`woocommerce_product_options_general_product_data`, saved on `woocommerce_admin_process_product_object`, meta `_tackquote_quote_only`; variations inherit), enforced in `woocommerce_is_purchasable` and in the Store API through `woocommerce_store_api_validate_add_to_cart`. Off by default.
* **Quote-only scope "approved wholesale accounts"** through `GET /storefront/v1/price-access` (sends the customer's email and WordPress user ID; fails closed). Off by default.
* **Order limits now also stop the Cart and Checkout blocks** through `woocommerce_store_api_cart_errors`, beside `woocommerce_check_cart_items`, with the same message and the error code `tackquote_order_limit`.
* **Net terms at checkout** (off by default). A new offline payment method "Net terms (TackQuote)", gateway id `tackquote_net_terms`, registered on `woocommerce_payment_gateways` and, for the Checkout block, as an `AbstractPaymentMethodType` on `woocommerce_blocks_payment_method_type_registration`. It is offered only to a signed-in customer with a confirmed email whose TackQuote credit line (`GET /storefront/v1/net-terms`) is active, in the checkout currency, whose remaining available credit covers the order (the limit when TackQuote omits it). It FAILS CLOSED: any error, timeout, 404 or unknown answer hides it. Placing the order reads the standing again and refuses if it no longer qualifies; otherwise the order goes on hold with the note "Awaiting payment on net terms (N days)" and order meta `_tackquote_net_terms`. It is never marked paid. The Checkout block never receives the limit. Order sync sends `payment.method = tackquote_net_terms` so TackQuote can invoice it.
* **Optional purchase-order number at checkout** (off by default; a setting of the net-terms payment method). Checkout block: `woocommerce_register_additional_checkout_field` (`tackquote/po-number`, order section, at most 64 characters). Classic checkout: a field under the order notes. Saved as order meta `_tackquote_po_number` and sent as `poNumber` through the existing `tack_quotes_order_po_number` filter (a value your own filter supplies still wins).
* **Translations bundled** for de_DE, es_ES, fr_FR, it_IT, ja, nl_NL and pt_BR (`languages/`, generated from TackQuote's shared storefront catalogue by `bin/build-translations.php`; Italian pending native review). Header `Domain Path: /languages`; the textdomain is registered on `init`. The quote form and quote list script now reads its text through `wp.i18n` (`wp-i18n` dependency, `wp_set_script_translations`) instead of a localised array. A translate.wordpress.org language pack still takes precedence. The "Add to Quote", "Request a Quote" and "Checkout as Quote" labels left at their default now follow the visitor's language: activation no longer stores them in English, and a stored English default or a blank field means the translated default.
* **Accepted quote to store checkout.** A buyer who accepts a quote in TackQuote's portal and chooses to check out in your store arrives at `?tackquote_checkout=<token>`. The plugin exchanges the single-use token server to server (`GET /integrations/woocommerce/quote-checkout/<token>`, once, never retried), refuses a quote in another currency, empties the cart and adds every quoted line at its quantity and quoted unit price (all or nothing: one unavailable product refuses the whole quote, by name), then redirects to checkout without the token. Quote lines keep the quoted price over B2B pricing, their quantities are locked (classic cart and Store API), other products cannot be added beside them, and products sold on quote only can be bought through their accepted quote. The order stores `_tackquote_quote_ref` and the quote's PO (unless the buyer typed one); with order sync on, the order is sent with `tackQuoteRef` so TackQuote links it to the quote. Active only while an API key is saved.
* The plugin's buttons use WooCommerce's own button classes (`button`, plus `wp-element-button` on a block theme). **Fixed on block themes:** the product-page buttons and order-limit notice render with the Add to Cart block (`render_block_{name}`), once per product, also for quote-only products. Quote-only variations no longer disable them. They follow the Add to Cart with Options form, quoting its chosen variation and quantity.
* **Blends with your theme, and you can restyle it without editing the plugin.** Storefront CSS no longer carries a palette of its own: the blue quote buttons and the dark launcher are gone, and every control (product, card and cart buttons, the launcher, drawer checkout, quote page and request form) is the theme's button; the final action is WooCommerce's `alt` button. The drawer, request form and fixed cart bar take the theme's colours (theme.json `base`/`contrast` presets, neutral fallback on classic themes) and fonts; the form fields wear WooCommerce's `form-row`/`input-text`, the quote page is wrapped in `woocommerce` like WooCommerce's own shortcodes and uses `shop_table shop_table_responsive`, and the volume table is a `shop_table`. Two stylesheets: `tackquote-layout` (always) and `tackquote` (appearance). New **Styling** settings on the Storefront tab: "Use the theme's styles only" and an optional accent colour (WordPress colour picker; `sanitize_hex_color`, inlined only when set). Documented `--tackquote-*` CSS variables, overridable templates in `templates/tackquote/` located through `wc_get_template()` (theme copies in `yourtheme/woocommerce/tackquote/`), and new filters `tackquote_button_label`, `tackquote_button_classes`, `tackquote_theme_styles_only`, `tackquote_quote_page_args`, `tackquote_quote_list_drawer_args`, `tackquote_quantity_breaks_args` and `tackquote_storefront_form_args`. Accessibility: the launcher toggles the drawer and reports `aria-expanded`, Escape closes the drawer, plugin-only controls show a focus ring, the close and remove icons use the text colour (the old light grey failed contrast), the opening animation respects `prefers-reduced-motion`, and the layout uses logical CSS properties so right-to-left stores are mirrored.
* The quote form's email is prefilled for signed-in customers only. A guest's checkout email is no longer printed into the page, where a full-page cache could have served it to other visitors.
* **Fixed: variable products on quote showed no size or colour choice on classic themes** in store-wide quote-only mode. The variation form now renders on quote for classic and block themes, with the quantity and quote buttons in place of the cart button; a quote-only variation still cannot be added to the cart.
* **Fixed on block themes:** the volume-pricing table renders after the Add to Cart block, once per product, instead of above the excerpt.
* The readme Description is now a short overview (wordpress.org trims a Description over 2,500 words, External services and Privacy included); the detailed field lists moved, unchanged, into the FAQ, and changelog entries for 1.5.1 and earlier into `changelog.txt`.
* Net terms, tax exemption and credit standing now go only to customers the seller has linked in TackQuote (a TackQuote server rule; prices still follow the account email). See the FAQ "Which features need a linked account?".
* Tools → Export Personal Data now includes, per order, the purchase-order number, the TackQuote quote reference and number, and the net terms (through WooCommerce's order exporter). Erase Personal Data deletes the purchase-order number when WooCommerce's "Remove personal data from orders on request" is on.
* When a wholesale or net-terms application is refused, the answers put back in the form are kept for 2 minutes (was 5) and no longer include phone numbers or tax, VAT or registration numbers; the customer types those again.
* **Requires WordPress 6.4 and WooCommerce 8.0 or later** (was 6.0 and 6.0). Older releases are untested and no longer receive security fixes. The plugin still checks that a newer WooCommerce feature exists before it uses it.
* **Fixed: the quote form's success message was invisible.** It sat inside the form that is hidden on success, so a guest saw an empty dialog and could send the request twice. It now shows below the hidden form, takes focus and is announced to screen readers.
* **Fixed: a variation in the quote list showed the parent's SKU and lowest price.** "Add to Quote" on size M listed the parent SKU at the cheapest variation's price; the drawer and quote page now show the chosen variation's own SKU and unit price (excluding tax, like every other line).
* **Fixed: target prices now reach the seller as "Buyer asked for"** on each quote line (`lineItems[].targetPrice`) instead of only inside the request note. Sent only to TackQuote servers that take the field (those that advertise attachments); older servers still get them in the note.
* The quote form now also prefills a signed-in customer's first and last name (billing name, else profile name), as it already did the email. Guests get nothing prefilled.
* More of the quote form, drawer and launcher is translated: Email address, Note, Company name, Send request, Sending…, Legal name, Address, State / Province, the drawer title and the launcher label. Strings with no identical entry in TackQuote's shared catalogue (for example First name, Last name, Cancel and the order-limit messages) stay English until translate.wordpress.org has them.

= 1.8.2 =
* **Repeated "slow down" answers back off further each time.** The first HTTP 429 from TackQuote holds order sync for the time TackQuote names (or one minute); if it happens again before any order got through, the wait doubles each time, with a random spread so held orders do not all reappear in the same second, up to one hour. The wait and the attempt count are stored as a site option, so every PHP worker and every scheduled run honours the same pause. A successful push resets it.
* **An order held by a 429 is sent again on its own.** It is put back into Action Scheduler for the moment the pause ends, instead of waiting for another order to change. Previously the last order of a quiet day could sit unsent until something else happened in the store.
* Together with the refusal handling below, this closes the audit finding that one installed copy of this plugin reached TackQuote about 2,800 times a day and was refused on 94% of them: a refused or throttled key now costs at most a handful of requests per hour.
* **Order sync stops when TackQuote refuses the API key, and tells you why.** If the key saved in TackQuote settings lacks the `orders:write` scope, has been revoked, or your TackQuote subscription is inactive, TackQuote refuses every order. The plugin used to try again on every order change, which on a busy store meant a refused request every few seconds, and the reason only appeared in WooCommerce > Status > Logs. It now stops sending, shows an error notice in wp-admin that names the missing scope or the billing step, and checks again at most once an hour. Saving a different key resumes at once.
* When order sync resumes, orders changed while it was refused are sent automatically (up to 200 of the oldest at a time; any beyond that are sent when they next change).
* When TackQuote asks the plugin to slow down (HTTP 429), the plugin waits for the time TackQuote names, up to one hour. A firewall or challenge page in front of TackQuote is treated as temporary and does not stop sync.

= 1.8.1 =
* **The "awaiting approval" message no longer says more than TackQuote knows.** TackQuote now answers "awaiting approval" for every quote request made on behalf of a company, so that a shopper typing a company name can no longer learn whether that company is already a customer of the store. The message used to read "Your company registration is awaiting approval by the seller", which is now false for a company that needs no approval. It reads "Request received. If your company account needs approval, we'll email you when it is ready."
* After a company request the buyer portal is offered as a link instead of not at all. The automatic redirect still happens only for an individual request; a company shopper may not be able to sign in yet, so they are never dropped onto a login they cannot pass.

= 1.8.0 =
* **The settings screen is grouped into a setup sequence.** Everything used to sit under a handful of headings in no particular order, with seven unrelated controls filed under "B2B pricing" — four of them about prices and three about hiding checkout methods. The page now reads top to bottom as the order you actually set the plugin up in: connect, choose how customers buy, put the buttons on the storefront, turn on order sync, price your trade customers, then restrict checkout methods. A section that cannot work yet says so where you are reading it, rather than saving happily and doing nothing.
* **Payment and shipping restrictions are no longer typed by hand.** Setting these up meant writing a small language into two text boxes — `cod: TIER2, TIER3`, one rule per line — which required knowing the WooCommerce GATEWAY ID (the Payments screen shows "Cash on delivery", not `cod`) and the TackQuote group code, from a different system. A wrong id did nothing; a wrong group code silently hid that payment method from every customer, permanently, with no error anywhere. You now get a row per real gateway and per real shipping method, showing the name you know it by with the id underneath, and you tick the buyer groups allowed to use it.
* **You type a group code once, not once per rule.** The new "Your buyer group codes" field is the single place codes are entered, and every rule then becomes a checkbox. Codes already used by rules you saved earlier are picked up automatically, so nothing needs retyping after this update — and no saved code can go missing from the list, which is what would have made it possible to lose one.
* **Nothing you already configured changes.** Rules are still stored in exactly the same format and read by exactly the same parser; the grid converts back to it on save. Rules for a gateway or shipping method your store does not currently offer — a payment plugin you deactivated, a full rate id you wrote by hand — are kept untouched and listed under the grid, instead of being quietly dropped because the screen could not draw a row for them. Your own `#` comments survive too.
* If the store reports no gateways or shipping methods at all, the field falls back to the text box it used to be, pre-filled with your rules. An empty grid would have posted an empty rule set and deleted the lot on the next save, so it deliberately never renders one.
* Fixed: uninstalling the plugin left thirteen options behind in the database — everything added in 1.6.0 and later, including the B2B pricing switches and the restriction rules. The readme said they were removed. They now are.

= 1.7.1 =
* **Security.** A customer could inherit another buyer's pricing group — and through it their payment terms — by changing their own email address. WooCommerce lets a customer change it on My Account with no verification: the current password is required only when the PASSWORD changes. Its one protection, `email_exists()`, refuses only an address already held by another WordPress user — so a TackQuote buyer approved for Net-30 who never registered on the store was takeable. A self-changed address is now untrusted until re-confirmed; the account still works normally, it is simply treated as anonymous by TackQuote. Stores that verify email another way can opt back in with `tackquote_trust_unverified_email`.
* **Security.** "TackQuote could not be reached" and "TackQuote says this buyer is in no group" were the same answer internally, and both fell through to the permissive default — so a buyer definitively placed in NO group was handed every group-restricted payment method. They are now distinguished: a real answer refuses a restricted method, and only a genuine outage leaves it visible.
* Group codes are matched case-insensitively.
* The payment/shipping rule fields now list the store's real gateway ids, since WooCommerce → Settings → Payments shows titles rather than the ids the field needs.

= 1.7.0 =
* New: **order limits**. TackQuote already held minimum/maximum order quantities and enforced them when a quote was converted, but a WooCommerce shopper never saw them — they filled a cart, reached checkout, and the order was refused. The limit is now shown on the product page AND enforced on the cart and at checkout.
* New: **buyer group badge**. Shows a signed-in customer which pricing group they are on. Without it a discounted price appears with no explanation, which reads as a pricing error rather than the negotiated rate it is.
* A TackQuote outage never blocks checkout. Nothing is refused unless TackQuote actually answered with a limit — a checkout that stops working because a supplier's API is slow costs the day's revenue, while an unenforced minimum costs a phone call.
* Quantities are summed across cart lines of the same product before checking, so 10 + 20 satisfies a minimum of 25.
* Both switches are off by default, under **WooCommerce → TackQuote → B2B pricing**.

= 1.6.0 =
* New: **B2B pricing**. A signed-in trade customer can now be priced from their TackQuote price book, buyer group and quantity breaks — the same pricing authority that prices a quote. Until now the plugin could send a quote request and receive Tack-resolved prices back on the quote, but a customer browsing the shop still saw the retail price, because nothing let the storefront ASK what Tack would charge before a quote existed.
* New: an optional **Volume pricing** table on product pages, showing only the tiers that actually change the price for that customer. It renders nothing when there is one tier, rather than showing an empty table under a heading.
* Quantity breaks apply to the CART, at the quantity actually ordered — not just to the table. The price is set on `woocommerce_before_calculate_totals`, which is the only hook that knows how many of each line the buyer wants; a filter on `woocommerce_product_get_price` has no quantity at all, so it could only ever resolve at one unit and would have advertised a volume discount the checkout did not honour.
* Both are **off by default** and must be switched on under **WooCommerce → TackQuote → B2B pricing**. B2B pricing changes the price used at CHECKOUT, not just the price displayed, so it is opted into rather than inherited from a plugin update.
* Correct on tax-inclusive stores. TackQuote returns a NET unit price, while `set_price()` means "the price in the basis this store is configured for". On a store set to enter prices inclusive of tax, the net figure is grossed up using the product's own tax class first — without that, WooCommerce would extract the tax back out of it and the seller would absorb the VAT on every wholesale line (a £100 net line at 20% would charge £100 instead of £120).
* Safety: if TackQuote cannot be reached, returns no price for a SKU, or your plan does not include B2B pricing, your store keeps its own prices. No product is ever left unpriced or silently zeroed — a resolved price of `0` is honoured as a real price, while "no answer" is not.
* Anonymous shoppers are never priced and no request is made for them, so a page cache holding a logged-out render can never contain one customer's negotiated price.
* One batched request per page rather than one per product: a category page resolves up to 50 SKUs in a single call.

Older releases (1.5.1 and earlier) are listed in `changelog.txt` inside the plugin and in the GitHub repository.
