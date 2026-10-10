=== TackQuote for WooCommerce ===
Contributors: tackquote
Tags: woocommerce, request a quote, b2b, wholesale, rfq
Requires at least: 6.0
Requires Plugins: woocommerce
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.9.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Request a quote from WooCommerce for B2B wholesale quoting — sync orders to your TackQuote account with one API key.

== Description ==

**TackQuote for WooCommerce** adds request-a-quote buttons to your products, so B2B and wholesale shoppers can ask for a price instead of checking out — and optionally syncs orders one-way to your TackQuote account.

[Learn more about the WooCommerce integration](https://tackquote.com/integrations/woocommerce) &middot; [Create a free TackQuote account](https://app.tackquote.com/register)

= What you get =

* **Add to Quote** and **Request a Quote** buttons on product pages. Show either, both, or neither.
* A floating **quote list** with **Checkout as Quote** — several products, one request. It is separate from the WooCommerce cart, so stock and checkout are untouched.
* **Quote only (B2B catalogue) mode** — switch Add to Cart off across the whole store and take quotes instead. Your products, categories and search keep working; only checkout goes away. Apply it to everyone, to signed-out visitors only, or to chosen roles.
* **More places to start a quote** (all off by default, so an update changes nothing a shopper sees): "Add to Quote" on product cards in the shop, category and search lists; "Request a quote for your cart" on the cart page (classic cart and the Cart block); and a **quote page** of your own via the `[tackquote_quote_page]` shortcode, where shoppers change quantities, add an optional target price per line and a message.
* **Floating launcher settings** — side, offsets, label, icon only, item count, size, which pages, and hide on mobile. The defaults are the launcher the plugin always had.
* **Quote only, per product** — a "Quote only" checkbox in the product data panel hides Add to Cart for that product (its variations follow), and a fourth store-wide scope keeps the cart only for **approved wholesale accounts**.
* Optional one-way **order sync** to TackQuote — off by default, and queued through Action Scheduler so it never runs inside checkout.
* Works with WooCommerce **High-Performance Order Storage (HPOS)**.

Setup is one field: paste your TackQuote API key. You can [create an account here](https://app.tackquote.com/register).

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
and (since 1.9.0) an `X-TackQuote-Plugin-Version` header naming this plugin's version, so
TackQuote can tell which build your store runs. The API key needs the `buyers:write` scope for
the two application forms below; the read-only storefront lookups need no extra scope.

1. **Connection test** — `GET /integrations/woocommerce/ping`, falling back to `GET /health`.
Sent when an administrator clicks "Test TackQuote connection" on the plugin settings screen.
Sends your API key only: no store, order or customer data.

2. **Quote form field policy** — `GET /integrations/woocommerce/registration-config`.
Sent when a storefront page showing a quote button is viewed and the cached policy has
expired (cached for 15 minutes). Sends your API key only: no store, order or customer data.

3. **Quote request** — `POST /integrations/woocommerce/quote-requests`.
Sent when a shopper submits the quote form on your storefront. Sends what that shopper typed
into the form, plus the products being quoted: email address, first and last name, phone
number if given, company name and any company details the seller's registration policy
requires (legal name, tax/VAT ID, registration number, website, address, city, state, postal
code, country, company phone, industry, employee count), the free-text note if written, and
for each requested product its name, SKU, quantity, unit price excluding tax and WooCommerce
product ID, together with the store's currency code.

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
order limits, the buyer-group badge, group restrictions or tax-exempt buyers. Sent while a
signed-in customer views a product, the cart or checkout. Sends the product SKU and quantity,
the signed-in customer's email address and, since 1.9.0, their WordPress user ID (digits only;
never for a guest).

6. **Wholesale application** — `GET /integrations/woocommerce/wholesale-form` (the form's
fields, by form slug; sends no customer data) and `POST /integrations/woocommerce/wholesale-form/submit`.
Sent when a page with the `[tackquote_wholesale_application]` shortcode or the "Wholesale account"
My Account tab is viewed, and when a shopper submits that form. Sends the answers the shopper
typed into the seller's form (for example company name, email, phone, address, tax ID) and,
for a signed-in customer, their WordPress user ID as `wooCustomerId`. Attachments are not sent.

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

9. **Net terms at checkout** — `GET /storefront/v1/net-terms`. **Off by default.** Sent only
when the merchant has enabled the "Net terms (TackQuote)" payment method (WooCommerce →
Settings → Payments), only for a signed-in customer whose email is confirmed, when the checkout
lists payment methods (reused for at most one minute per customer) and again when that customer
places an order with this method. Sends the customer's account email address and WordPress user
ID (`buyerEmail`, `buyerExternalId`). TackQuote answers that buyer's own net-terms standing:
status, payment terms in days, credit limit and its currency. The limit is never shown in the
browser. If TackQuote cannot be reached or does not confirm the buyer, the method is hidden.
A purchase-order number the customer enters at checkout (an optional field, also off by default)
is saved on the order and sent only inside order sync (item 4).

The quote-list re-pricing for signed-in buyers uses the B2B pricing call the plugin already
makes for the cart (`POST /storefront-pricing/resolve`, SKU and quantity per line plus the
buyer's email), and only when the merchant has switched TackQuote pricing on.

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

* `GET /integrations/woocommerce/ping` (and `GET /health`) — connection test. Sends no store or customer data.
* `GET /integrations/woocommerce/registration-config` — fetches which fields the quote form should ask for. Sends no store or customer data.
* `POST /integrations/woocommerce/quote-requests` — a shopper's quote request.
* `POST /integrations/woocommerce/order-sync` — order sync. **Only when the merchant has switched order sync on. It is off by default.**
* `POST /storefront-pricing/resolve`, `GET /storefront/v1/wholesale-price`, `GET /storefront/v1/quantity-breaks`, `GET /storefront/v1/order-limits`, `GET /storefront/v1/buyer-group` (and the older `GET /storefront-b2b/order-limits`, `GET /storefront-b2b/buyer-group`) — B2B prices, limits, the buyer group and its tax exemption for a signed-in customer. Only when the matching feature is switched on.
* `GET /integrations/woocommerce/wholesale-form`, `POST /integrations/woocommerce/wholesale-form/submit` — the wholesale application form.
* `POST /storefront/v1/credit-application` (or `POST /integrations/woocommerce/credit-application`) — a signed-in customer's net-terms application.
* `GET /storefront/v1/net-terms` — a signed-in customer's own net-terms standing, read at checkout. **Only when the merchant has enabled the "Net terms (TackQuote)" payment method. It is off by default.** Sends the customer's account email address and WordPress user ID.
* `GET /storefront/v1/price-access` — whether a signed-in customer's wholesale application is approved. **Only when the merchant chose the "approved wholesale accounts" quote-only scope. It is off by default.** Sends the customer's account email address and WordPress user ID.

Every request carries an `X-TackQuote-Plugin-Version` header with the plugin's version number. It identifies the software, not a person.

= What the B2B lookups and application forms send =

* **B2B lookups**: the product SKU and quantity, the signed-in customer's email address, and their WordPress user ID (a number, sent only beside the email and only while they are signed in).
* **Wholesale application**: exactly the answers the shopper typed into the seller's form, plus the WordPress user ID (`wooCustomerId`) when they are signed in. File fields are not sent in this release.
* **Net-terms application**: the account's own email address (never a typed one), the WordPress user ID, and the legal business name, phone, tax/VAT ID, billing address, requested limit and terms, up to three trade references and notes the customer typed.

= What a quote request sends =

Sent when a shopper submits the quote form, using only what they typed into it:

* Email address
* First name, last name
* Phone number (if provided)
* Company name, and any company fields the seller's registration policy requires (for example legal name, tax/VAT ID, registration number, address, city, state, postal code, country, company phone, industry, employee count)
* The free-text note, if written (capped at 2,000 characters). From the quote page, any target prices the shopper typed are added to the note, one line per product
* The requested products: name, SKU, quantity, unit price excluding tax, and the WooCommerce product ID
* The store's currency code

= What order sync sends (off by default) =

Sent for each order when it is created and when its status changes, if the merchant
has enabled **Sync orders to TackQuote**. This is the whole order, because a quote
that becomes an order is only useful to the seller if it carries who is buying and
where it is going. Read this list before switching order sync on.

**The customer's identity and addresses**

* Billing address in full: first name, last name, company, street (both lines), city, state or county, postal code, country, email address and phone number
* Shipping address in full: the same fields, including shipping phone where WooCommerce holds one
* The WooCommerce customer ID, or `0` for a guest order
* The customer's order note, as they wrote it

**The order**

* WooCommerce order ID, order number and status
* Currency, item subtotal, discount total, shipping total, tax total and order total
* Coupon codes applied
* A purchase-order number: the one the customer typed into the optional checkout field (off by default; "Purchase order number" in the Net terms (TackQuote) payment settings), or whatever your store supplies through the `tack_quotes_order_po_number` filter. WooCommerce core has no purchase-order field, so nothing is sent unless one of the two is in use
* For an order placed on net terms, the payment method ID `tackquote_net_terms`, which tells TackQuote to invoice it on the buyer's terms
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

= If you are a merchant in the EU, UK or another jurisdiction with a transfer regime =

Order sync sends personal data about your customers to TackQuote, which makes
TackQuote a processor acting on your instructions. That is why it ships switched
**off** and why enabling it is a deliberate act rather than a default. Before you
enable it, satisfy yourself that you have a lawful basis and, where required, a data
processing agreement in place with TackQuote. Your own privacy policy should name
TackQuote as a recipient; the plugin adds suggested wording to
**Settings → Privacy** for you to review and adapt.

= What is stored on your own site =

* Plugin settings, as WordPress options: the TackQuote API key, API URL, button labels, and the feature toggles.
* `tack_quotes_registration_config` — a transient caching the quote-form field policy for 15 minutes.
* `tack_qr_*` — short-lived transients counting quote requests per visitor for rate limiting. They hold a salted hash of the visitor's IP address, never the address itself, and expire after 5 minutes.
* `tack_quotes_wholesale_form_cache` — a transient caching wholesale form definitions for 5 minutes (60 seconds after a failure).
* `tack_quotes_storefront_v1_missing` — a transient remembering for one hour that the TackQuote server has no `/storefront/v1` routes.
* `tack_sf_*` — five-minute transients carrying an application form's outcome (success or error text and what was typed, for refilling the form) back to the page after it is submitted. Read once and deleted.
* `tack_quotes_vat_exempt_applied` — a WooCommerce session value remembering that this plugin set the customer tax exempt, so the exemption can be withdrawn. Never saved to the customer record.
* `tack_nt_<user id>` — a one-minute transient per signed-in customer holding TackQuote's net-terms answer (status, terms, limit, currency) and a hash of the email it was read for, so the checkout does not ask TackQuote on every refresh. Only while the net-terms payment method is enabled.
* `_tackquote_net_terms` — order meta on an order placed on net terms: the terms in days and when TackQuote confirmed them.
* `_tackquote_po_number` — order meta holding the purchase-order number the customer entered at checkout (the Checkout block also keeps WooCommerce's own `_wc_other/tackquote/po-number` copy).
* `woocommerce_tackquote_net_terms_settings` — the net-terms payment method's settings (WooCommerce's own option for each payment method).
* `_tack_quotes_sync_key` — order meta recording which order state was last accepted by TackQuote, so the same state is not sent twice.

Deleting the plugin removes every option above and the `tack_quotes_registration_config` transient, on every site of a multisite network. The `tack_qr_*` rate-limit counters and the `tack_nt_*` net-terms answers are left to expire on their own (five minutes and one minute; they are keyed per visitor, so there is no fixed name to delete). The `_tackquote_net_terms` and `_tackquote_po_number` order meta stay with the orders, for the same reason as the sync key. The `_tack_quotes_sync_key` order meta is deliberately left in place: orders are financial records and an uninstall routine should not rewrite every one of them.

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

Yes, since 1.9.0, and both are off until you switch them on under **TackQuote → 8. Quote buttons and launcher**. "Product cards" adds "Add to Quote" to every simple product in the shop, category and search lists; variable, grouped and external products link to their page instead, because a card cannot say which variation is wanted. "Cart page" adds "Request a quote for your cart" under Proceed to checkout on the classic cart; on the Cart block it appears as a fixed button at the bottom of the cart page. It copies the cart into the quote list and leaves the WooCommerce cart as it was.

= How do I make a single product quote-only? =

Edit the product and tick **Quote only** in the General tab of the product data panel. Add to Cart disappears for that product (and for every variation of a variable product), a hand-made `?add-to-cart=` link and the Cart/Checkout blocks refuse it too, and a cart that already held it loses that line with a notice. Unlike the store-wide mode, this applies to shop managers as well, so your own test sees what customers see.

= Can I keep the cart for approved wholesale customers only? =

Yes. Turn on quote-only mode and choose "Everyone except approved wholesale accounts". The plugin asks TackQuote whether the signed-in customer's wholesale application is approved, and only then shows the cart. If TackQuote cannot be reached, the customer sees the quote-only catalogue (this one check fails closed, because it is a price gate). It needs the API key.

= Where do target prices on the quote page go? =

Into the request's note, one line per product ("Target prices: …"), after the shopper's own message. The quote itself keeps your store price for each line.

== Screenshots ==

1. Quote buttons sit beside Add to Cart on the product page, so a shopper can buy or ask for a price without leaving the page.
2. The quote list collects several products, then sends them to TackQuote as one request.
3. Store mode in the plugin settings: run a normal shop that also takes quotes, or switch the whole store to a B2B catalogue.
4. Quote-only mode on the storefront. Add to Cart is withdrawn and the quote buttons remain, so the catalogue still works and only checkout goes away.

== Changelog ==

= 1.9.0 =
* **Wholesale application form on your store.** New shortcode `[tackquote_wholesale_application slug="…"]` (slug defaults to the one under TackQuote → Storefront forms) renders the form you design in TackQuote under Settings → Wholesale forms, with every field kind (text, email, phone, number, select, multi-select, checkbox, textarea, date, address, tax ID; conditional fields shown and hidden as the server decides). File fields show a notice: attachments arrive in a later release. Submissions are nonce-protected and sent server to server; a signed-in customer's details are prefilled and their WordPress user ID travels as `wooCustomerId` so approval links the account. Shoppers see a friendly success, pending or error message, never a raw server answer.
* **My Account tabs "Wholesale account" and "Net terms"** (both off by default; TackQuote → Storefront forms). Registered with `add_rewrite_endpoint` through WooCommerce's `woocommerce_get_query_vars` filter and `woocommerce_account_menu_items`; rewrite rules are flushed on activation, deactivation and once after an update. The net-terms form (also `[tackquote_net_terms_application]`) is for signed-in customers only, uses the account's own email, and accepts up to three trade references.
* **Tax-exempt buyers** (off by default). When TackQuote marks a signed-in customer's buyer group tax exempt, `WC()->customer->set_is_vat_exempt( true )` is applied on `woocommerce_before_calculate_totals`, once per request and never saved to the customer record. Missing, false or unreachable means tax is charged.
* **Product-page prices, quantity breaks, order limits and the buyer group now read TackQuote's shared storefront API** (`/storefront/v1/*`), showing the currency each price is in and marking a price that is the customer's own ("Your account price"). Cart prices still use `/storefront-pricing/resolve`. On a TackQuote server without these routes the plugin falls back to the previous ones.
* **Fixed: order limits were never applied.** The plugin read `minQuantity`/`maxQuantity`, which TackQuote has never sent (it sends `min`/`max`), so no minimum or maximum was shown or enforced. Only quantity rules are now read as quantities; an order-total rule's money amount is no longer mistaken for one.
* Every request now carries an `X-TackQuote-Plugin-Version` header, and buyer lookups send the WordPress user ID beside the email (`buyerExternalId`) so TackQuote can link the account.
* A customer whose email was self-changed and not re-confirmed cannot apply for net terms or receive a tax exemption in another buyer's name (same rule as B2B pricing since 1.7.1).
* **Add to Quote on product cards** (`woocommerce_after_shop_loop_item`, priority 11), off by default. Simple products go straight to the quote list; variable, grouped and external products link to their page.
* **Request a quote for your cart** on the cart page, off by default: under Proceed to checkout on the classic cart (`woocommerce_proceed_to_checkout`), and as a fixed button on the Cart block (printed on `wp_footer` on the cart page when the cart has lines). It re-reads the live cart from WooCommerce's Store API before copying it into the quote list.
* **Floating launcher settings**, mirroring the Shopify launcher: position, side and bottom offsets, show on (all pages, product pages, cart page, nowhere), label, icon only, item count, size, hide on mobile. On phones narrower than 480 px it is always compact, 48 px tall, and lifted above the home indicator. Defaults reproduce the 1.8 launcher.
* **Quote page** shortcode `[tackquote_quote_page]` sharing the drawer's list: edit quantities, an optional target price per line (sent in the request note) and a message. New setting "Quote button opens: drawer or page", drawer by default.
* **Signed-in buyers see their price at the line quantity** in the quote list and on the quote page, re-priced (debounced) through TackQuote pricing when the merchant uses it.
* **Per-product Quote only** checkbox (`woocommerce_product_options_general_product_data`, saved on `woocommerce_admin_process_product_object`, meta `_tackquote_quote_only`; variations inherit), enforced in `woocommerce_is_purchasable` and in the Store API through `woocommerce_store_api_validate_add_to_cart`. Off by default.
* **Quote-only scope "approved wholesale accounts"** through `GET /storefront/v1/price-access` (sends the customer's email and WordPress user ID; fails closed). Off by default.
* **Order limits now also stop the Cart and Checkout blocks** through `woocommerce_store_api_cart_errors`, beside `woocommerce_check_cart_items`, with the same message and the error code `tackquote_order_limit`.
* **Net terms at checkout** (off by default). A new offline payment method "Net terms (TackQuote)", gateway id `tackquote_net_terms`, registered on `woocommerce_payment_gateways` and, for the Checkout block, as an `AbstractPaymentMethodType` on `woocommerce_blocks_payment_method_type_registration` with a small script using `wc.wcBlocksRegistry.registerPaymentMethod`. Settings live in WooCommerce → Settings → Payments. It is offered only to a signed-in customer with a confirmed email whose TackQuote credit line (`GET /storefront/v1/net-terms`) is active, in the checkout currency, with a limit that covers the order. It FAILS CLOSED: any error, timeout, 404 or unknown answer hides it. Placing the order reads the standing again and refuses if it no longer qualifies; otherwise the order goes on hold with the note "Awaiting payment on net terms (N days)" and order meta `_tackquote_net_terms`. It is never marked paid. The Checkout block receives only a yes/no, never the limit. Order sync sends `payment.method = tackquote_net_terms` so TackQuote can invoice it.
* **Optional purchase-order number at checkout** (off by default; a setting of the net-terms payment method). Checkout block: `woocommerce_register_additional_checkout_field` (`tackquote/po-number`, order section, at most 64 characters). Classic checkout: a field under the order notes. Saved as order meta `_tackquote_po_number` and sent as `poNumber` through the existing `tack_quotes_order_po_number` filter (a value your own filter supplies still wins).
* The plugin's buttons use WooCommerce's own button classes (`button`, plus `wp-element-button` on a block theme), so they match the theme.

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

= 1.5.1 =
* Shortened the description and added links to the WooCommerce integration page and to account signup, so it is clear where to get an API key.
* No code changes.

= 1.5.0 =
* Order sync now sends the whole order, not eleven fields of it. Previously the payload carried no address of any kind — a merchant testing it in production reported "no name, not address information, nothing", and they were right. It now carries both addresses in full, phone numbers, the WooCommerce customer ID and order note, the real item subtotal alongside discount/shipping/tax/total, coupon codes, payment method and gateway transaction reference, the created/modified/paid/completed timestamps, shipping and fee lines, and per-line product/variation IDs, line subtotal, tax and item meta (so "Large / Blue" survives the sync).
* Fixed: `subtotal` was never sent, so the receiving end recorded `subtotal = total` — meaning every order from a store that charges tax or shipping claimed its goods cost what the customer paid.
* New: `tack_quotes_order_po_number` filter. WooCommerce core has no purchase-order field, so nothing is sent unless your store wires one up; see the FAQ. Previously a purchase-order number could never be reported at all.
* Fixed: `modifiedAt` is sent but deliberately excluded from the idempotency hash. Under HPOS, recording a successful push writes order meta, and that stamps a new modified date — so hashing it would have invalidated the key the push just recorded and de-duplication would never have converged.
* The privacy disclosure on the settings screen, the wording offered to **Settings → Privacy**, and the **External services** and **Privacy** sections of this readme now describe the payload that is actually sent. An under-disclosure is worse than none: a merchant reads it and concludes the transfer is narrower than it is.
* Fixed: the settings screen named the WooCommerce log source as `tack-quotes`; it has been `tackquote` since the 1.3.3 slug rename.
* Added `tests/test-order-payload.php`, a WP-CLI contract test that names every required payload field against a real WooCommerce order, and offline payload coverage in `tests/run.php`.
* Tests: the quote-only mode suite now asserts that `woocommerce_is_purchasable` is actually hooked, not just that the callback decides correctly. It previously proved only the latter, so a wiring mistake would have hidden the buttons while the Store API kept taking orders. No behaviour change — the wiring was already correct.

= 1.4.0 =
* New: **Store mode**. A single setting turns the whole storefront into a B2B catalogue — "Add to cart" is withdrawn and customers request a quote instead. Choose whether it applies to every customer, to signed-out visitors only (so approved trade customers keep a normal cart), or to specific roles. Optionally replace prices with "Price on request".
* The switch is enforced server-side via `woocommerce_is_purchasable`, which WooCommerce checks before accepting any cart line — so a hand-crafted `?add-to-cart=` link, the Store API and cached pages are all refused, not just the button hidden.
* Carts filled *before* the store was switched to quote-only are emptied on the cart and checkout pages with an explanatory notice. WooCommerce's own cart validation only checks that a product still exists, not that it is purchasable, so without this a pre-existing cart could still be checked out and the store would not really be quote-only.
* Anyone who can manage WooCommerce keeps a working cart, so you can test your own store while it is closed to customers.
* Quote buttons now also mount outside the add-to-cart form, so they survive when the cart button is withdrawn.

= 1.3.4 =
* Fixed: the **Settings** link on the Plugins screen led to "Sorry, you are not allowed to access this page." even for an administrator. The link carried a hardcoded `page=tack-quotes`, which was the admin page slug up to 1.3.1; the 1.3.2 and 1.3.3 renames moved the slug to `tackquote` and left the link behind. Pointing at an unregistered page makes WordPress emit its permission-denied message, so the failure looked like a capability problem and was not one. The link is now derived from the same constant the menu is registered with, so the two cannot drift again.
* Added a regression test (`tests/run.php`, no PHPUnit or WordPress install required) asserting the Settings link resolves to a registered admin page that requires `manage_options`.

= 1.3.3 =
* The plugin slug, text domain, plugin folder and distributed ZIP are now all `tackquote`, matching the slug assigned on WordPress.org. WordPress requires the text domain to equal the slug, and a plugin folder that disagrees with either is its own defect. The admin page, the enqueued script/style handles, the Action Scheduler group and the WooCommerce log source move with it, so the log source is now `tackquote`.
* readme.txt now carries an **External services** section disclosing the TackQuote API: that the plugin cannot function without it, that nothing is sent until an API key is entered, and, endpoint by endpoint, what is sent and when — with links to the Terms of Service and Privacy Policy.
* Fixed the download links, which pointed at a repository that does not exist. The source now lives at https://github.com/tackquote/woocommerce.
* Note for anyone updating a manually installed 1.3.2: the folder changed from `tackquote-for-woocommerce/` to `tackquote/`, so WordPress treats the new ZIP as a separate plugin. Deactivate and delete the old copy after installing this one. Your API key and toggles are stored as WordPress options and survive both.

= 1.3.2 =
* Packaging: the distributed ZIP now unpacks to `tackquote-for-woocommerce/`, matching the plugin slug, and is rebuilt from the current source. The previously published download still contained pre-1.2.0 code, so stores installing it got the old buttons and none of the 1.3.1 security fixes.
* No functional changes to the plugin itself beyond the version bump.

= 1.3.1 =
* Security: company field names supplied by the API are now escaped and allowlisted before being rendered into the quote form, closing a cross-site scripting hole.
* Security: the TackQuote API key is no longer rendered into the settings page HTML. Leave the field blank to keep the saved key; a new "Remove saved API key" button clears it.
* Security: the settings page now requires the administrator capability, which is also the capability WordPress requires to save it — a shop manager previously saw the page but could not save it.
* Quote requests from the storefront are rate limited per visitor, the note field is length-capped, and the request timeout is 5s instead of 20s.
* Order sync is now queued and sent on a background request through Action Scheduler, so it no longer runs inside checkout, and each push carries an idempotency key so the same order state is never sent twice.
* Order sync now defaults to OFF on new installs, and readme.txt documents exactly which fields are sent where. Existing stores keep their current setting.
* An expired or cache-stale security token now says so and offers a reload, instead of showing a generic error that could never be resolved.
* Quantity and variation are read from the clicked product's own form, fixing wrong values on grouped products, product archives, related-product rows and sticky add-to-cart bars.
* Declares compatibility with the Cart and Checkout blocks, and adds the `WC tested up to` header that WooCommerce needs before it will surface any compatibility declaration.
* Uninstall now removes every option and transient the plugin creates.

= 1.3.0 =
* "Add to Quote" no longer adds the product to the WooCommerce cart. It now adds to a separate, browser-side "quote list" that never touches stock, cart totals, or checkout. A floating "Quote list" button (bottom-right, site-wide) appears once at least one product is added, showing what's in it and a "Checkout as Quote" button that submits the whole list as one TackQuote request. The WooCommerce cart page no longer has a quote button — quoting and purchasing are now fully separate paths.

= 1.2.0 =
* Restored "Request a Quote" as an independent, configurable product-page button alongside "Add to Quote" — Settings → TackQuote now has checkboxes to show "Add to Quote", "Request a Quote", both, or neither on product pages (the cart page's "Checkout as Quote" is unaffected). Both default to on for existing and new installs.

= 1.1.0 =
* Split the single "Request a Quote" button into two: "Add to Quote" on product pages (adds the product to the cart, same as Add to Cart) and "Checkout as Quote" on the cart page (submits everything in the cart as one quote request). Previously the product-page button submitted a quote for that one product immediately, with no way to accumulate multiple products into a single request without using the whole-cart button on every add.

= 1.0.1 =
* Replaced the browser `prompt()`/`alert()` quote-request flow with a real modal dialog (email + optional note fields, inline validation, loading/success/error states). Email is pre-filled for logged-in customers.

= 1.0.0 =
* Initial release: Request a Quote button, one-way order sync, TackQuote settings, HPOS declaration.
