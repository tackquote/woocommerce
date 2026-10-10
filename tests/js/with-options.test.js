/**
 * The Add to Cart with Options block's variation state (assets/js/tack-with-options.js).
 *
 * Run: node tests/js/with-options.test.js   (Node 18+, no dependencies; exit 1 on any failure)
 *
 * The values fed in are what tack-quotes.js reads from the page: the block's hidden
 * input[name="variation_id"] (empty until a complete variation is chosen, WooCommerce
 * 11.2.1), its input.qty, and the `data-tack-variations` JSON the plugin prints.
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

// Variation 21 in stock, 22 out of stock, 23 hidden: as Tack_Block_Product prints them.
const raw = JSON.stringify({ 21: { q: 1, l: 'Blue, Large' }, 22: { q: 0, l: 'Red, Large' }, 23: { q: 0, l: 'Red, Small' } });
const states = api.parseStates(raw);

check('the attribute parses to the per-variation map', () => {
  assert.deepEqual(Object.keys(states).sort(), ['21', '22', '23']);
});
check('no attribute (not a variable product) parses to null', () => {
  assert.equal(api.parseStates(undefined), null);
  assert.equal(api.parseStates(''), null);
});
check('unreadable JSON parses to an empty map (nothing quotable), never null', () => {
  assert.deepEqual(api.parseStates('{oops'), {});
  assert.deepEqual(api.parseStates('[1,2]'), {});
});

check('nothing chosen yet (empty variation_id): not quotable, no id sent', () => {
  assert.deepEqual(api.resolve(states, '', '1'), { quotable: false, variationId: 0, label: '', quantity: 1 });
});
check('a complete in-stock variation: quotable, sends THAT variation id and the quantity', () => {
  assert.deepEqual(api.resolve(states, '21', '3'), { quotable: true, variationId: 21, label: 'Blue, Large', quantity: 3 });
});
check('an out-of-stock variation: not quotable', () => {
  const r = api.resolve(states, '22', '1');
  assert.equal(r.quotable, false);
  assert.equal(r.variationId, 0);
});
check('a hidden variation: not quotable', () => {
  assert.equal(api.resolve(states, '23', '1').quotable, false);
});
check('an id that is not one of this product\'s variations: not quotable', () => {
  assert.equal(api.resolve(states, '99', '1').quotable, false);
  assert.equal(api.resolve(states, 'abc', '1').quotable, false);
  assert.equal(api.resolve(states, '-21', '1').quotable, false);
});
check('an inherited object key is not a variation', () => {
  assert.equal(api.resolve(api.parseStates('{}'), 'constructor', '1').quotable, false);
});
check('quantity: missing, zero or junk becomes 1; fractions are floored', () => {
  assert.equal(api.resolve(states, '21', '').quantity, 1);
  assert.equal(api.resolve(states, '21', '0').quantity, 1);
  assert.equal(api.resolve(states, '21', 'x').quantity, 1);
  assert.equal(api.resolve(states, '21', '4.9').quantity, 4);
});
check('not a variable product: quotable with the quantity, variation id 0', () => {
  assert.deepEqual(api.resolve(null, '', '5'), { quotable: true, variationId: 0, label: '', quantity: 5 });
});

console.log(failures ? '\n' + failures + ' failure(s)' : '\nAll JS checks passed');
process.exit(failures ? 1 : 0);
