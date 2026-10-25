/**
 * 1.12.0 client scripts, loaded for real in a VM context:
 *
 *   - assets/js/tack-net-terms-block.js: canMakePayment prefers the Store API cart's
 *     own answer (`cart.extensions.tackquote.netTermsAvailable`) and falls back to the
 *     answer printed with the page.
 *   - assets/js/tack-cart-quote-block.js: fills ExperimentalOrderMeta in the
 *     `woocommerce-checkout` scope, renders only in the Cart block, carries the cart
 *     lines; without the slot it adds the fixed control instead.
 *   - assets/js/tack-quick-order.js: a pasted list is parsed one product per line.
 *
 * Run: node tests/js/blocks-1120.test.js   (Node 18+, no dependencies; exit 1 on any failure)
 */
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const read = (f) => fs.readFileSync(path.join(__dirname, '..', '..', 'assets', 'js', f), 'utf8');

let failures = 0;
function check(label, fn) {
  try {
    fn();
    console.log('  PASS  ' + label);
  } catch (e) {
    failures++;
    console.log('  FAIL  ' + label + '\n        ' + e.message);
  }
}

// ── Net terms ────────────────────────────────────────────────────────────────
function netTerms(canPay) {
  let registered = null;
  const window = {
    wc: {
      wcBlocksRegistry: { registerPaymentMethod: (cfg) => (registered = cfg) },
      wcSettings: { getSetting: () => ({ title: 'Pay on net terms', description: '', canPay }) },
    },
    wp: { element: { createElement: () => ({}) } },
  };
  vm.runInNewContext(read('tack-net-terms-block.js'), { window });
  return registered;
}

check('net terms: the Store API cart says no -> hidden, even though the page said yes', () => {
  const m = netTerms(true);
  assert.equal(m.canMakePayment({ cart: { extensions: { tackquote: { netTermsAvailable: false } } } }), false);
});
check('net terms: the Store API cart says yes -> offered, even though the page said no', () => {
  const m = netTerms(false);
  assert.equal(m.canMakePayment({ cart: { extensions: { tackquote: { netTermsAvailable: true } } } }), true);
});
check('net terms: no Store API answer -> the page\'s answer', () => {
  assert.equal(netTerms(true).canMakePayment({ cart: { extensions: {} } }), true);
  assert.equal(netTerms(false).canMakePayment(undefined), false);
});
check('net terms: a non-boolean Store API value is ignored (fail to the page answer)', () => {
  assert.equal(netTerms(false).canMakePayment({ cart: { extensions: { tackquote: { netTermsAvailable: 'yes' } } } }), false);
});

// ── Cart block control ───────────────────────────────────────────────────────
function cartBlock(withSlot) {
  const plugins = [];
  const appended = [];
  const el = (type, props, ...children) => ({ type, props: props || {}, children });
  const document = {
    readyState: 'complete',
    querySelector: () => null,
    createElement: (tag) => ({ tag, attrs: {}, children: [], setAttribute(k, v) { this.attrs[k] = v; }, appendChild(c) { this.children.push(c); } }),
    body: { appendChild: (n) => appended.push(n) },
    addEventListener() {},
  };
  const Slot = function OrderMeta() {};
  const window = {
    wc: {
      wcSettings: { getSetting: () => ({ label: 'Request a quote for your cart', buttonClass: 'button wp-element-button tack-quote-cart-btn' }) },
      blocksCheckout: withSlot ? { ExperimentalOrderMeta: Slot } : {},
    },
    wp: { element: { createElement: el }, plugins: { registerPlugin: (name, cfg) => plugins.push({ name, cfg }) } },
  };
  vm.runInNewContext(read('tack-cart-quote-block.js'), { window, document, JSON, Math, Number, String, Array, isFinite });
  return { plugins, appended, Slot };
}

check('cart block: registers one fill in the woocommerce-checkout scope', () => {
  const { plugins, Slot } = cartBlock(true);
  assert.equal(plugins.length, 1);
  assert.equal(plugins[0].cfg.scope, 'woocommerce-checkout');
  const tree = plugins[0].cfg.render();
  assert.equal(tree.type, Slot);
});
check('cart block: renders the classic control with the cart lines, only in the Cart block', () => {
  const { plugins } = cartBlock(true);
  const Control = plugins[0].cfg.render().children[0].type;
  const cart = { cartItems: [{ id: 203, name: 'Glove - Large', sku: 'GLOVE-L', quantity: 3, prices: { price: '600', currency_minor_unit: 2 } }] };
  const out = Control({ cart, context: 'woocommerce/cart' });
  const button = out.children[0];
  assert.match(button.props.className, /tack-quote-cart-btn/);
  assert.deepEqual(JSON.parse(button.props['data-lines']), [
    { product_id: 203, variation_id: 0, name: 'Glove - Large', sku: 'GLOVE-L', price: 6, quantity: 3 },
  ]);
  assert.equal(Control({ cart, context: 'woocommerce/checkout' }), null);
  assert.equal(Control({ cart: { cartItems: [] }, context: 'woocommerce/cart' }), null);
});
// No slot: the fixed control, only when the Store API cart has lines (async).
const asyncChecks = [];
function cartBlockFallback(items) {
  const appended = [];
  const document = {
    readyState: 'complete',
    querySelector: () => null,
    createElement: (tag) => ({ tag, attrs: {}, children: [], setAttribute(k, v) { this.attrs[k] = v; }, appendChild(c) { this.children.push(c); } }),
    body: { appendChild: (n) => appended.push(n) },
    addEventListener() {},
  };
  const window = {
    wc: { wcSettings: { getSetting: () => ({ label: 'Request a quote for your cart', buttonClass: 'button tack-quote-cart-btn' }) }, blocksCheckout: {} },
    wp: { element: { createElement: () => ({}) }, plugins: { registerPlugin() {} } },
    TackQuotes: { storeCartUrl: 'https://shop.example/wp-json/wc/store/v1/cart' },
    fetch: () => Promise.resolve({ ok: true, json: () => Promise.resolve({ items }) }),
  };
  vm.runInNewContext(read('tack-cart-quote-block.js'), { window, document, JSON, Math, Number, String, Array, isFinite });
  return new Promise((r) => setTimeout(() => r(appended), 20));
}
asyncChecks.push(
  cartBlockFallback([{ id: 1 }]).then((appended) =>
    check('cart block: no slot, cart has lines -> the fixed control is added', () => {
      assert.equal(appended.length, 1);
      assert.equal(appended[0].className, 'tack-quote-cart-fixed');
    })
  ),
  cartBlockFallback([]).then((appended) =>
    check('cart block: no slot, EMPTY cart -> no control', () => {
      assert.equal(appended.length, 0);
    })
  )
);

// ── Seller form: the registration policy's company fields (Fable R1) ─────────
function quotesApi(registration) {
  const chain = new Proxy(function () {}, { get: () => () => chain, apply: () => chain });
  const $ = new Proxy(function () { return chain; }, { get: (t, p) => (p in t ? t[p] : () => chain) });
  const sandbox = {
    jQuery: $,
    TackQuotes: { registration },
    setTimeout: (fn) => { fn(); return 0; },
    clearTimeout: () => {},
    localStorage: { getItem: () => null, setItem: () => {}, removeItem: () => {} },
    document: {},
    console,
    module: { exports: {} },
  };
  sandbox.window = sandbox;
  vm.createContext(sandbox);
  vm.runInContext(read('tack-quotes.js'), sandbox);
  return sandbox.module.exports;
}
check('seller form + company_only policy: company name and required details follow the form', () => {
  const api = quotesApi({ allowCompany: true, allowIndividual: false, requiredCompanyFields: ['companyName', 'taxId'] });
  const html = api.sellerPolicyFields({ roles: { buyer_email: 'email', buyer_name: 'name' } });
  assert.match(html, /name="companyName"/);
  assert.match(html, /name="company\[taxId\]"/);
  assert.doesNotMatch(html, /name="buyingAs"/);
});
check('seller form with a buyer_company field: the policy does not ask for the name twice', () => {
  const api = quotesApi({ allowCompany: true, allowIndividual: false, requiredCompanyFields: ['taxId'] });
  const html = api.sellerPolicyFields({ roles: { buyer_company: 'org' } });
  assert.doesNotMatch(html, /name="companyName"/);
  assert.match(html, /name="company\[taxId\]"/);
});
check('seller form + both allowed: the individual/company choice is offered', () => {
  const api = quotesApi({ allowCompany: true, allowIndividual: true, requiredCompanyFields: [] });
  assert.match(api.sellerPolicyFields({ roles: {} }), /name="buyingAs"/);
});
check('seller form + individuals only: nothing is added', () => {
  const api = quotesApi({ allowCompany: false, allowIndividual: true });
  assert.equal(api.sellerPolicyFields({ roles: {} }), '');
});
check('roleInput: a server key outside the field-key shape never reaches a selector (P11)', () => {
  const seen = [];
  const sandbox = quotesApi(null);
  const overlay = { find: (sel) => (seen.push(sel), 'found') };
  // roleInput reads TackQuotes.quoteForm from its own sandbox; set it through a fresh load.
  const chain = new Proxy(function () {}, { get: () => () => chain, apply: () => chain });
  const $ = new Proxy(function () { return arguments.length ? chain : 'empty'; }, { get: (t, p) => (p in t ? t[p] : () => chain) });
  const box = { jQuery: $, TackQuotes: { quoteForm: { roles: { buyer_email: 'a"]evil', buyer_name: 'name' } } }, setTimeout: (f) => f(), localStorage: { getItem: () => null, setItem() {} }, document: {}, console, module: { exports: {} } };
  box.window = box;
  vm.createContext(box);
  vm.runInContext(read('tack-quotes.js'), box);
  assert.equal(box.module.exports.roleInput(overlay, 'buyer_email'), 'empty');
  assert.equal(box.module.exports.roleInput(overlay, 'buyer_name'), 'found');
  assert.deepEqual(seen, ['[name="tq_answers[name]"]']);
  assert.ok(sandbox);
});

// ── Quick order: pasted lists ────────────────────────────────────────────────
const qoModule = { exports: {} };
const $stub = function () {
  return { on() {}, find() { return this; } };
};
$stub.Deferred = function () {};
vm.runInNewContext(read('tack-quick-order.js'), { module: qoModule, jQuery: $stub, window: {}, document: {} });
const parsePasted = (t) => JSON.parse(JSON.stringify(qoModule.exports.parsePasted(t)));
check('quick order: "SKU, qty", "SKU qty", tabs and a bare SKU (qty 1); blank lines skipped', () => {
  assert.deepEqual(parsePasted('ABC-1, 3\nDEF 10\n\nGHI\t7\nJKL\n'), [
    { sku: 'ABC-1', quantity: 3 },
    { sku: 'DEF', quantity: 10 },
    { sku: 'GHI', quantity: 7 },
    { sku: 'JKL', quantity: 1 },
  ]);
});
check('quick order: a SKU with spaces keeps them when the quantity comes last', () => {
  assert.deepEqual(parsePasted('WIDGET BLUE 5'), [{ sku: 'WIDGET BLUE', quantity: 5 }]);
});

Promise.all(asyncChecks).then(() => {
  if (failures) {
    console.log('\n' + failures + ' failure(s)');
    process.exit(1);
  }
  console.log('\nAll checks passed');
});
