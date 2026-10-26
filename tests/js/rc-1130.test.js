/**
 * 1.13.0 release-candidate fixes from the Studio QA.
 *
 *   - P2: on Twenty Twenty-Five at 390px "Add to Quote" wrapped to two lines (154x71)
 *     beside a one-line "Add to cart" (141x52): the theme's 2.25rem side padding left
 *     the longer label no room. In a narrow card the control keeps the theme's button
 *     and only gives the label the side padding back. No width is set.
 *
 * Run: node tests/js/rc-1130.test.js   (Node 18+, no dependencies; exit 1 on any failure)
 */
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const css = fs.readFileSync(path.join(__dirname, '..', '..', 'assets', 'css', 'tack-quotes-layout.css'), 'utf8');

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

check('P2: both wrappers (block grid and classic loop) are one named inline-size container that stretches across the card', () => {
  const rule = /:is\(\.wp-block-button\.tack-card-quote, \.tack-card-quote-classic\) \{([^}]*)\}/.exec(css);
  assert.ok(rule, 'the shared container rule');
  assert.match(rule[1], /container:\s*tack-card-quote\s*\/\s*inline-size;/);
  assert.match(rule[1], /align-self:\s*stretch;/, 'the classic wrapper stretches too (li.product as a flex column, Twenty Twenty-Three)');
});

check('P2: directly in a core flex layout (Row or Stack) neither wrapper is a container; it follows the layout', () => {
  const rule = /:where\(\.is-layout-flex\) > :is\(\.wp-block-button\.tack-card-quote, \.tack-card-quote-classic\) \{([^}]*)\}/.exec(css);
  assert.ok(rule, 'the flex opt-out rule');
  assert.match(rule[1], /container-type:\s*normal;/);
  assert.match(rule[1], /align-self:\s*auto;/);
  // It must come AFTER the container rule (same specificity: the later one wins).
  assert.ok(css.indexOf(rule[0]) > css.indexOf('container: tack-card-quote / inline-size;'), 'after the container rule');
});

check('P2: in a narrow card only the side padding changes (one line, theme button kept)', () => {
  const block = /@container tack-card-quote \(max-width:\s*14rem\)\s*\{([\s\S]*?)\n\}/.exec(css);
  assert.ok(block, 'the narrow-card container query');
  const inner = block[1];
  assert.match(inner, /:is\(\.wp-block-button\.tack-card-quote, \.tack-card-quote-classic\) > :is\(\.tack-card-quote-btn, \.tack-card-quote-link\)\s*\{[^}]*padding-inline:\s*1em;/);
  assert.doesNotMatch(inner, /(?:^|[^-])width\s*:/, 'no width is set on the control');
  assert.doesNotMatch(inner, /white-space|text-overflow|font-size|padding-block/, 'nothing else of the theme button changes');
});

check('P2: the label still wraps rather than being cut if a card is narrower still', () => {
  const rule = /\.wp-block-button\.tack-card-quote > :is\(\.tack-card-quote-btn, \.tack-card-quote-link\) \{([^}]*)\}/.exec(css);
  assert.ok(rule);
  assert.match(rule[1], /white-space:\s*normal;/);
  assert.doesNotMatch(rule[1], /nowrap|text-overflow/);
});

console.log(failures ? '\n' + failures + ' failure(s)' : '\nAll checks passed');
process.exit(failures ? 1 : 0);
