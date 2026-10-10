/**
 * The quote-list row an "Add to Quote" click builds (assets/js/tack-with-options.js
 * `listRow`), E2E attempt 2 defect D4: a variation's row carried the PARENT's SKU and
 * the parent's (minimum) price.
 *
 * Run: node tests/js/list-row.test.js   (Node 18+, no dependencies; exit 1 on any failure)
 *
 * `lines` is `data-tack-variation-lines` as Tack_Block_Product::variation_lines() prints it.
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

// What the button carries for the variable parent: its id, name, SKU and minimum price.
const parent = { productId: '203', name: 'E2E TEST Bracket', sku: 'E2E-B', price: '10' };
const lines = api.parseStates(JSON.stringify({
  204: { s: 'E2E-B-S', p: 10 },
  205: { s: 'E2E-B-M', p: 12 },
  206: { s: 'E2E-B-L', p: null },
}));

check('size M: the row carries the VARIATION\'s SKU and price, and its label', () => {
  assert.deepEqual(api.listRow(parent, 205, 'M', lines), {
    productId: 203,
    variationId: 205,
    name: 'E2E TEST Bracket - M',
    sku: 'E2E-B-M',
    price: 12,
  });
});
check('a simple product (no variation) keeps the button\'s SKU and price', () => {
  assert.deepEqual(api.listRow({ productId: 11, name: 'Gloves', sku: 'SG-100', price: '4.5' }, 0, '', null), {
    productId: 11,
    variationId: 0,
    name: 'Gloves',
    sku: 'SG-100',
    price: 4.5,
  });
});
check('an unpriced variation shows no price, never the parent\'s minimum', () => {
  const row = api.listRow(parent, 206, 'L', lines);
  assert.equal(row.sku, 'E2E-B-L');
  assert.equal(row.price, 0);
});
check('a variation missing from the map shows no SKU and no price, never the parent\'s', () => {
  const row = api.listRow(parent, 299, 'XL', lines);
  assert.equal(row.sku, '');
  assert.equal(row.price, 0);
  assert.equal(row.variationId, 299);
});
check('no map at all (older cached page): still never the parent\'s SKU or price', () => {
  const row = api.listRow(parent, 205, 'M', api.parseStates(undefined));
  assert.equal(row.sku, '');
  assert.equal(row.price, 0);
});
check('a negative or non-numeric price in the map is not shown', () => {
  const bad = api.parseStates(JSON.stringify({ 205: { s: 'E2E-B-M', p: -1 }, 204: { s: 'E2E-B-S', p: 'x' } }));
  assert.equal(api.listRow(parent, 205, 'M', bad).price, 0);
  assert.equal(api.listRow(parent, 204, 'S', bad).price, 0);
});

console.log(failures ? '\n' + failures + ' JS failure(s)' : '\nAll JS checks passed');
process.exit(failures ? 1 : 0);
