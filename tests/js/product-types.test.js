/**
 * Product types (1.12.0, lane WOO-PRODUCT-TYPES): the pure quote-list helpers in
 * assets/js/tack-with-options.js that turn a product page into quote lines.
 *
 * The owner's report: a grouped product (Logo Collection) with Hoodie 4, T-Shirt 6,
 * Beanie 7 became ONE line "Logo Collection x 4". groupRows() makes one row per child
 * with a quantity, never the group.
 *
 * Run: node tests/js/product-types.test.js   (Node 18+, no dependencies; exit 1 on any failure)
 */
'use strict';

const assert = require('node:assert/strict');
const path = require('node:path');
const api = require(path.join(__dirname, '..', '..', 'assets', 'js', 'tack-with-options.js'));

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

// data-tack-group as Tack_Block_Product::grouped_children() prints it.
const group = api.parseStates(JSON.stringify({
  232: { n: 'Hoodie with Logo', s: 'woo-hoodie-with-logo', p: 45 },
  233: { n: 'T-Shirt', s: 'woo-tshirt', p: 18 },
  234: { n: 'Beanie', s: 'woo-beanie', p: 18 },
}));

check('grouped: Hoodie 4, T-Shirt 6, Beanie 7 are three rows, in the page order', () => {
  assert.deepEqual(api.groupRows(group, [[232, '4'], [233, '6'], [234, '7']]), [
    { productId: 232, variationId: 0, name: 'Hoodie with Logo', sku: 'woo-hoodie-with-logo', price: 45, quantity: 4 },
    { productId: 233, variationId: 0, name: 'T-Shirt', sku: 'woo-tshirt', price: 18, quantity: 6 },
    { productId: 234, variationId: 0, name: 'Beanie', sku: 'woo-beanie', price: 18, quantity: 7 },
  ]);
});

check('grouped: a child left at 0 (or empty) is skipped', () => {
  const rows = api.groupRows(group, [[232, ''], [233, '0'], [234, '2']]);
  assert.equal(rows.length, 1);
  assert.equal(rows[0].productId, 234);
  assert.equal(rows[0].quantity, 2);
});

check('grouped: all zero -> no rows (the page then says "choose the quantity")', () => {
  assert.deepEqual(api.groupRows(group, [[232, 0], [233, ''], [234, '0']]), []);
});

check('grouped: a quantity input for a child not in the map (a variable child, the group) is ignored', () => {
  assert.deepEqual(api.groupRows(group, [[252, 4], [235, 3]]), []);
});

check('grouped: negative or fractional quantities never become a line of their own', () => {
  const rows = api.groupRows(group, [[232, '-3'], [233, '2.7']]);
  assert.deepEqual(rows.map((r) => [r.productId, r.quantity]), [[233, 2]]);
});

check('grouped: an unpriced child shows no price rather than a wrong one', () => {
  const g = { 9: { n: 'X', s: '', p: null } };
  assert.equal(api.groupRows(g, [[9, 1]])[0].price, 0);
});

check('grouped: a child sold individually (m: 1) is at most one, and says so on the row', () => {
  const g = { 281: { n: 'Solo', s: 'S', p: 50, m: 1 } };
  const rows = api.groupRows(g, [[281, 3]]);
  assert.equal(rows[0].quantity, 1);
  assert.equal(rows[0].max, 1);
});

check('capQuantity: at least one, at most the ceiling, no ceiling without one', () => {
  assert.equal(api.capQuantity(5, 1), 1);
  assert.equal(api.capQuantity(0, 0), 1);
  assert.equal(api.capQuantity('7', undefined), 7);
});

// "Any" attributes.
const parent = { productId: 240, name: 'Polo', sku: 'POLO', price: 20 };
const lines = { 241: { s: 'POLO-L', p: 22, l: 'Large', a: ['attribute_pa_color'] }, 242: { s: 'POLO-RS', p: 21, l: 'Red, Small' } };
const terms = { attribute_pa_color: { blue: 'Blue', red: 'Red' }, attribute_size: { Large: 'Large', Small: 'Small' } };

check('any: the row keeps the shopper\'s choice and is named by it', () => {
  const row = api.listRow(parent, 241, 'Blue, Large', lines, { attribute_pa_color: 'blue', attribute_size: 'Large' }, terms);
  assert.equal(row.name, 'Polo - Blue, Large');
  assert.deepEqual(row.attributes, { attribute_pa_color: 'blue' });
  assert.equal(row.sku, 'POLO-L');
  assert.equal(row.price, 22);
});

check('any: a fixed variation carries no attributes and keeps WooCommerce\'s label', () => {
  const row = api.listRow(parent, 242, 'Red, Small', lines, { attribute_pa_color: 'red', attribute_size: 'Small' }, terms);
  assert.equal(row.name, 'Polo - Red, Small');
  assert.equal(row.attributes, undefined);
});

check('any: Blue and Red of one Any variation are two lines; same choice is one', () => {
  const blue = { productId: 240, variationId: 241, attributes: { attribute_pa_color: 'blue' } };
  const red = { productId: 240, variationId: 241, attributes: { attribute_pa_color: 'red' } };
  assert.equal(api.sameLine(blue, red), false);
  assert.equal(api.sameLine(blue, { productId: '240', variationId: '241', attributes: { attribute_pa_color: 'blue' } }), true);
});

check('same line: two children of a group, or two variations, are different lines', () => {
  assert.equal(api.sameLine({ productId: 232, variationId: 0 }, { productId: 233, variationId: 0 }), false);
  assert.equal(api.sameLine({ productId: 240, variationId: 241 }, { productId: 240, variationId: 242 }), false);
  assert.equal(api.sameLine({ productId: 232 }, { productId: 232, variationId: 0, attributes: {} }), true);
});

check('attributesKey: order-independent, empty values ignored', () => {
  assert.equal(api.attributesKey({ b: '2', a: '1', c: '' }), api.attributesKey({ a: '1', b: '2' }));
  assert.equal(api.attributesKey(null), '');
});

check('attributesLabel: an unknown value is shown as posted', () => {
  assert.equal(api.attributesLabel({ attribute_pa_color: 'teal' }, terms), 'teal');
});

check('cartLine: a cart item\'s Any choice rides on the line', () => {
  const line = api.cartLine({
    id: 241,
    quantity: 2,
    extensions: { tackquote: { productId: 240, variationId: 241, name: 'Polo - Blue, Large', sku: 'POLO-L', price: 22, attributes: { attribute_pa_color: 'blue' } } },
  });
  assert.deepEqual(line.attributes, { attribute_pa_color: 'blue' });
  const plain = api.cartLine({ id: 232, quantity: 1, extensions: { tackquote: { productId: 232, variationId: 0, attributes: {} } } });
  assert.equal(plain.attributes, undefined);
});

// Follow-up: rows the server will quote no line for (re-price `dropped`).
check('dropRows: a grouped parent left in the list is removed; all removed were grouped', () => {
  const list = [
    { productId: 252, variationId: 0, quantity: 4 },
    { productId: 233, variationId: 0, quantity: 6 },
  ];
  const r = api.dropRows(list, [{ product_id: 252, variation_id: 0, grouped: true }]);
  assert.deepEqual(r.kept.map((x) => x.productId), [233]);
  assert.equal(r.removed.length, 1);
  assert.equal(r.allGrouped, true);
});

check('dropRows: a deleted product removed alongside -> not all grouped', () => {
  const list = [{ productId: 252 }, { productId: 9 }];
  const r = api.dropRows(list, [
    { product_id: 252, variation_id: 0, grouped: true },
    { product_id: 9, variation_id: 0, grouped: false },
  ]);
  assert.equal(r.kept.length, 0);
  assert.equal(r.allGrouped, false);
});

check('dropRows: nothing dropped -> list unchanged, no message', () => {
  const list = [{ productId: 233 }];
  const r = api.dropRows(list, []);
  assert.deepEqual(r.kept, list);
  assert.equal(r.allGrouped, false);
  assert.equal(api.dropRows(list, undefined).kept.length, 1);
});

check('dropRows: matched on product AND variation', () => {
  const list = [{ productId: 240, variationId: 241 }, { productId: 240, variationId: 0 }];
  const r = api.dropRows(list, [{ product_id: 240, variation_id: 0, grouped: false }]);
  assert.deepEqual(r.kept.map((x) => x.variationId), [241]);
});

if (failures) {
  console.log('\n' + failures + ' failure(s)');
  process.exit(1);
}
console.log('\nAll checks passed');
