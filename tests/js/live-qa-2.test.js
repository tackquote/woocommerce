/**
 * 1.12.0 live QA re-run (2026-10-26) fixes in the quote modal:
 *
 *   - P1: a seller-designed form's company field turned required for a company request
 *     while its label still said "(optional)". TackWithOptions.markRequired() keeps the
 *     label's marker and aria-required in step with `required`.
 *   - P2: the "Company details" heading stood over nothing when the seller form asks the
 *     company name itself and the policy requires no other company field.
 *
 * Run: node tests/js/live-qa-2.test.js   (Node 18+, no dependencies; exit 1 on any failure)
 */
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const read = (f) => fs.readFileSync(path.join(__dirname, '..', '..', 'assets', 'js', f), 'utf8');
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

// ── A small DOM: elements with classes, attributes, children and text ─────
function El(tag, cls) {
  this.tagName = tag;
  this.className = cls || '';
  this.attributes = {};
  this.childNodes = [];
  this.parentNode = null;
  this.textContent = '';
  this.required = false;
  this.ownerDocument = doc;
}
El.prototype.setAttribute = function (k, v) { this.attributes[k] = String(v); };
El.prototype.removeAttribute = function (k) { delete this.attributes[k]; };
El.prototype.getAttribute = function (k) { return k in this.attributes ? this.attributes[k] : null; };
El.prototype.appendChild = function (c) { c.parentNode = this; this.childNodes.push(c); return c; };
El.prototype.removeChild = function (c) { this.childNodes = this.childNodes.filter((x) => x !== c); c.parentNode = null; return c; };
El.prototype.querySelector = function (sel) {
  const classes = sel.split(',').map((s) => s.trim().replace(/^\./, ''));
  return this.childNodes.find((c) => c.className && classes.some((k) => c.className.split(' ').includes(k))) || null;
};
El.prototype.text = function () {
  return this.childNodes.map((c) => (c.nodeType === 3 ? c.data : c.textContent)).join('');
};
const doc = {
  createElement: (tag) => new El(tag),
  createTextNode: (data) => ({ nodeType: 3, data, parentNode: null }),
};

// The designed form's optional company field: "Company <span class="optional">(optional)</span>".
function optionalField() {
  const label = new El('label');
  label.appendChild(doc.createTextNode('Company'));
  const opt = new El('span', 'optional');
  opt.textContent = '(optional)';
  label.appendChild(doc.createTextNode(' '));
  label.appendChild(opt);
  const input = new El('input');
  return { label, input };
}

check('P1: required -> the "(optional)" marker goes, a "*" (aria-hidden) and aria-required come', () => {
  const { label, input } = optionalField();
  api.markRequired(input, label, true, '(optional)');
  assert.equal(input.required, true);
  assert.equal(input.getAttribute('aria-required'), 'true');
  assert.equal(label.querySelector('.optional'), null);
  const star = label.querySelector('.required');
  assert.ok(star);
  assert.equal(star.textContent, '*');
  assert.equal(star.getAttribute('aria-hidden'), 'true');
  assert.doesNotMatch(label.text(), /optional/);
});

check('P1: back to optional (the shopper chose "individual") -> "(optional)" again, no star, no aria-required', () => {
  const { label, input } = optionalField();
  api.markRequired(input, label, true, '(optional)');
  api.markRequired(input, label, false, '(optional)');
  assert.equal(input.required, false);
  assert.equal(input.getAttribute('aria-required'), null);
  assert.equal(label.querySelector('.required'), null);
  assert.ok(label.querySelector('.optional'));
  assert.match(label.text(), /\(optional\)/);
});

check('P1: repeated syncs never add a second marker', () => {
  const { label, input } = optionalField();
  api.markRequired(input, label, true, '(optional)');
  api.markRequired(input, label, true, '(optional)');
  assert.equal(label.childNodes.filter((c) => c.className === 'required').length, 1);
  api.markRequired(input, label, false, '(optional)');
  api.markRequired(input, label, false, '(optional)');
  assert.equal(label.childNodes.filter((c) => c.className && c.className.includes('optional')).length, 1);
});

check('P1: the built-in form\'s ".tack-quote-optional" marker is recognised too', () => {
  const label = new El('label');
  const opt = new El('span', 'tack-quote-optional');
  opt.textContent = '(optional)';
  label.appendChild(opt);
  api.markRequired(new El('input'), label, true, '(optional)');
  assert.equal(label.querySelector('.tack-quote-optional'), null);
});

check('P1: syncCompanySection drives the label through markRequired', () => {
  const src = read('tack-quotes.js').replace(/\s+/g, '');
  const sync = src.slice(src.indexOf('functionsyncCompanySection('), src.indexOf('functioncompanyAnswers('));
  assert.ok(sync.includes('window.TackWithOptions.markRequired('), 'markRequired is called');
  assert.ok(sync.includes("$overlay.find('label[for=\"'+this.id+'\"]')[0]"), 'with the field\'s own label');
});

// ── P2: no empty "Company details" heading ─────────────────────────────────
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

check('P2: seller form asks the company name, policy requires nothing else -> section, no heading', () => {
  const q = quotesApi({ allowCompany: true, allowIndividual: true, requiredCompanyFields: [] });
  const html = q.sellerPolicyFields({ roles: { buyer_company: 'org' } });
  assert.match(html, /class="tack-quote-company-section"/);
  assert.doesNotMatch(html, /tack-quote-company-heading/);
});

check('P2: with company fields to show, the heading stays above them', () => {
  const q = quotesApi({ allowCompany: true, allowIndividual: true, requiredCompanyFields: ['taxId'] });
  const html = q.sellerPolicyFields({ roles: { buyer_company: 'org' } });
  assert.match(html, /tack-quote-company-heading[\s\S]*name="company\[taxId\]"/);
});

check('P2: the built-in form (company name field) keeps its heading', () => {
  const q = quotesApi({ allowCompany: true, allowIndividual: true, requiredCompanyFields: [] });
  const html = q.sellerPolicyFields({ roles: {} });
  assert.match(html, /tack-quote-company-heading[\s\S]*name="companyName"/);
});

if (failures) {
  console.log('\n' + failures + ' failure(s)');
  process.exit(1);
}
console.log('\nAll checks passed');
