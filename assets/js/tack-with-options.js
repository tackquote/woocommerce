/**
 * TackQuote: the selected variation of WooCommerce's "Add to Cart with Options" block.
 *
 * In its blockified mode (WooCommerce 11.2.1, `AddToCartWithOptions::render()`) the
 * block renders its own form with an Interactivity API variation selector. There is
 * no `form.variations_form` and no jQuery `found_variation` / `show_variation` event.
 * What the block does publish in the page is a hidden `input[name="variation_id"]`
 * inside its form, bound to the selected variation's id (empty until a complete
 * set of attributes matches a variation), and its quantity input `input.qty`.
 * Stock and visibility are only in WooCommerce's private `woocommerce/products`
 * store, so the plugin prints them beside the buttons instead (`data-tack-variations`,
 * `Tack_Block_Product::variation_states()`): `{ "<id>": { "q": 1|0, "l": "label" } }`.
 *
 * Pure functions only (no DOM), so `tests/js/with-options.test.js` runs them in Node.
 * `tack-quotes.js` does the DOM reads and calls `resolve()`.
 */
(function (root, factory) {
  var api = factory();
  if (typeof module === 'object' && module && module.exports) {
    module.exports = api;
  }
  if (root) {
    root.TackWithOptions = api;
  }
})(typeof window !== 'undefined' ? window : null, function () {
  'use strict';

  function positiveInt(value) {
    var n = Math.floor(Number(value));
    return isFinite(n) && n > 0 ? n : 0;
  }

  // `data-tack-variations` as printed (a JSON object), or null when the attribute is
  // absent, i.e. the product is not variable. Unreadable JSON is an empty map, so
  // nothing is quotable: fail closed rather than quote the parent product.
  function parseStates(raw) {
    if (raw === undefined || raw === null || raw === '') {
      return null;
    }
    try {
      var parsed = JSON.parse(String(raw));
      return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
    } catch (e) {
      return {};
    }
  }

  // What the quote buttons may send, given the block's hidden variation_id value and
  // its quantity input value. Same contract as the classic `show_variation` path in
  // tack-quotes.js: a variable product is quotable only with a complete variation
  // chosen that is visible and in stock; the id sent is that variation's.
  function resolve(states, variationValue, quantityValue) {
    var quantity = positiveInt(quantityValue) || 1;
    if (states === null) {
      return { quotable: true, variationId: 0, label: '', quantity: quantity };
    }
    var id = positiveInt(variationValue);
    var key = String(id);
    var entry = id && states && Object.prototype.hasOwnProperty.call(states, key) ? states[key] : null;
    var quotable = !!entry && Number(entry.q) === 1;
    return {
      quotable: quotable,
      variationId: quotable ? id : 0,
      label: quotable && entry.l ? String(entry.l) : '',
      quantity: quantity,
    };
  }

  return { parseStates: parseStates, resolve: resolve };
});
