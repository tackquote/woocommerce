/**
 * 1.13.0 release-candidate fixes from the Studio QA.
 *
 *   - P2: on Twenty Twenty-Five at 390px "Add to Quote" wrapped to two lines beside a
 *     one-line "Add to cart". At WooCommerce's small-screen breakpoint the card controls
 *     take 1em side padding (a viewport query: no containment, no layout change). Every
 *     layout is otherwise exactly 1.12.0's (a nowrap Row included).
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

check('no size containment anywhere in our stylesheets (no container:, no @container)', () => {
  for (const f of ['tack-quotes-layout.css', 'tack-quotes.css', 'tack-admin.css']) {
    const src = fs.readFileSync(path.join(__dirname, '..', '..', 'assets', 'css', f), 'utf8').replace(/\/\*[\s\S]*?\*\//g, '');
    assert.doesNotMatch(src, /@container|container(-type|-name)?\s*:/, f);
  }
});

check('P2: at WooCommerce\'s small-screen breakpoint (768px) the card controls only take 1em side padding', () => {
  const block = /@media only screen and \(max-width:\s*768px\)\s*\{([\s\S]*?)\n\}/.exec(css);
  assert.ok(block, 'the viewport query');
  assert.match(block[1], /\.wp-block-button\.tack-card-quote > :is\(\.tack-card-quote-btn, \.tack-card-quote-link\),\s*\.woocommerce ul\.products li\.product :is\(\.tack-card-quote-btn, \.tack-card-quote-link\)\s*\{\s*padding-inline:\s*1em;\s*\}/, 'block grid and classic loop, each with its own weight');
  assert.doesNotMatch(block[1], /^\s*:is\(\.tack-card-quote-btn/m, 'no bare low-specificity selector');
  assert.match(css, /woocommerce_style_smallscreen_breakpoint/, 'the comment names the filterable breakpoint');
  assert.doesNotMatch(block[1], /(?:^|[^-])width\s*:|white-space|font-size|padding-block|display/, 'nothing else changes');
});

check('no 1.13.0 shrink rules on the block-grid control: a nowrap Row behaves exactly as in 1.12.0', () => {
  const src = css.replace(/\/\*[\s\S]*?\*\//g, '');
  assert.doesNotMatch(src, /overflow-wrap:\s*anywhere/);
  const cardRules = src.match(/[^{}]*tack-card-quote[^{}]*\{[^}]*\}/g) || [];
  for (const rule of cardRules) {
    assert.doesNotMatch(rule, /min-width:\s*0/, rule);
  }
  assert.doesNotMatch(src, /\.wp-block-button\.tack-card-quote \{\s*min-width/);
});

check('1.12.0 block-grid rules unchanged (no align-self, no stretch added)', () => {
  const cardRules = css.replace(/\/\*[\s\S]*?\*\//g, '').match(/[^{}]*tack-card-quote[^{}]*\{[^}]*\}/g) || [];
  assert.ok(cardRules.length > 0, 'card rules found');
  for (const rule of cardRules) {
    assert.doesNotMatch(rule, /align-self/, rule);
  }
  assert.match(css, /\.wp-block-button\.tack-card-quote \{\s*display: flex;\s*flex-direction: column;\s*align-items: center;/);
});

check('the label still wraps rather than being cut', () => {
  const rule = /\.wp-block-button\.tack-card-quote > :is\(\.tack-card-quote-btn, \.tack-card-quote-link\) \{([^}]*display: inline-flex[^}]*)\}/.exec(css);
  assert.ok(rule);
  assert.match(rule[1], /white-space:\s*normal;/);
  assert.doesNotMatch(rule[1], /nowrap|text-overflow/);
});

console.log(failures ? '\n' + failures + ' failure(s)' : '\nAll checks passed');
process.exit(failures ? 1 : 0);
