/**
 * The quote modal's confirmation after a successful request (assets/js/tack-quotes.js
 * `showSubmitted`): the dialog stays on the message, the page never navigates by itself,
 * and the buyer portal is offered only as a link.
 *
 * Run: node tests/js/quote-success.test.js   (Node 18+, no dependencies; exit 1 on any failure)
 *
 * The real script is loaded in a VM context with a small jQuery stand-in. Any timer the
 * script starts runs at once, so a delayed redirect would be recorded like an immediate one.
 */
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const file = path.join(__dirname, '..', '..', 'assets', 'js', 'tack-quotes.js');
const source = fs.readFileSync(file, 'utf8');

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

/** A recorded element: what the script set on it. */
function FakeEl(html) {
  this.html = html || '';
  this.attrs = {};
  this.value = '';
  this.children = [];
  this.shown = false;
}
FakeEl.prototype.text = function (v) {
  if (arguments.length) {
    this.value = String(v);
    this.children = [];
    return this;
  }
  return this.value;
};
FakeEl.prototype.show = function () { this.shown = true; return this; };
FakeEl.prototype.hide = function () { this.shown = false; return this; };
FakeEl.prototype.attr = function (k, v) { this.attrs[k] = v; return this; };
FakeEl.prototype.append = function () {
  for (const c of arguments) this.children.push(c);
  return this;
};
FakeEl.prototype.trigger = function () { return this; };
FakeEl.prototype.links = function () {
  return this.children.filter((c) => c instanceof FakeEl && /^<a\b/.test(c.html));
};

// Anything else the script does at load ($(document).on(...), $(fn)) is absorbed.
const chain = new Proxy(function () {}, {
  get: () => () => chain,
  apply: () => chain,
});
const $ = new Proxy(
  function (arg) {
    if (typeof arg === 'string' && arg.trim().charAt(0) === '<') return new FakeEl(arg.trim());
    return chain;
  },
  { get: (target, prop) => (prop in target ? target[prop] : () => chain) }
);

const navigations = [];
const location = {
  get href() { return 'https://shop.example/product/widget/'; },
  set href(v) { navigations.push(String(v)); },
  assign: (v) => navigations.push(String(v)),
  replace: (v) => navigations.push(String(v)),
  reload: () => navigations.push('reload'),
};
const sandbox = {
  jQuery: $,
  TackQuotes: {},
  location,
  setTimeout: (fn) => { fn(); return 0; },
  clearTimeout: () => {},
  localStorage: { getItem: () => null, setItem: () => {}, removeItem: () => {} },
  document: {},
  console,
  module: { exports: {} },
};
sandbox.window = sandbox;
vm.createContext(sandbox);
vm.runInContext(source, sandbox, { filename: file });
const api = sandbox.module.exports;
const i18n = sandbox.TackQuotes.i18n;
const portal = 'https://portal.tackquote.com/request-quote?source=woocommerce&email=a%40example.com';

check('the script exports showSubmitted for this test', () => {
  assert.equal(typeof api.showSubmitted, 'function');
});

check('success with a portalUrl: the message stays, and the page does NOT navigate', () => {
  navigations.length = 0;
  const $success = new FakeEl();
  api.showSubmitted($success, { portalUrl: portal, awaitingApproval: false });
  assert.deepEqual(navigations, []);
  assert.equal($success.shown, true);
  assert.equal($success.value, i18n.success);
});

check('success with a portalUrl: the portal is offered as the tack-quote-portal-link link', () => {
  const $success = new FakeEl();
  api.showSubmitted($success, { portalUrl: portal });
  const links = $success.links();
  assert.equal(links.length, 1);
  assert.match(links[0].html, /class="tack-quote-portal-link"/);
  assert.equal(links[0].attrs.href, portal);
  assert.equal(links[0].value, i18n.portalLink);
});

check('success with no portalUrl: the message only, no link, no navigation', () => {
  navigations.length = 0;
  const $success = new FakeEl();
  api.showSubmitted($success, { quoteNumber: 'TK-2026-000001' });
  assert.equal($success.links().length, 0);
  assert.equal($success.value, i18n.success);
  assert.deepEqual(navigations, []);
});

check('no payload at all: the message only', () => {
  const $success = new FakeEl();
  api.showSubmitted($success, undefined);
  assert.equal($success.value, i18n.success);
  assert.equal($success.links().length, 0);
});

check('awaiting approval: the approval message, and the same link', () => {
  navigations.length = 0;
  const $success = new FakeEl();
  api.showSubmitted($success, { portalUrl: portal, awaitingApproval: true });
  assert.equal($success.value, i18n.awaitingApproval);
  assert.equal($success.links().length, 1);
  assert.deepEqual(navigations, []);
});

check('a portalUrl that is not http(s) is not turned into a link', () => {
  const $success = new FakeEl();
  api.showSubmitted($success, { portalUrl: 'javascript:alert(1)' });
  assert.equal($success.links().length, 0);
});

check('the success text no longer promises a redirect, and says the seller replies by email', () => {
  assert.doesNotMatch(i18n.success, /redirect/i);
  assert.match(i18n.success, /email/i);
});

check('the request handler itself starts no navigation or timer', () => {
  const start = source.indexOf('function send(body, allowRetry)');
  const end = source.indexOf('.fail(function (xhr)', start);
  assert.ok(start > 0 && end > start, 'send() anchors not found');
  const done = source.slice(start, end);
  assert.ok(done.includes('showSubmitted($success, res.data);'), 'send() does not call showSubmitted');
  assert.doesNotMatch(done, /location|setTimeout/);
});

console.log(failures ? '\n' + failures + ' JS failure(s)' : '\nAll JS checks passed');
process.exit(failures ? 1 : 0);
