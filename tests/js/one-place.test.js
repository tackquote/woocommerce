/**
 * 1.13.0: the seller's own registration questions in the quote modal. TackQuote refuses
 * a request that leaves a REQUIRED question empty (validateRegistrationFields), and the
 * plugin never asked them, so every request to such a seller failed with a 400.
 *
 * Run: node tests/js/one-place.test.js   (Node 18+, no dependencies; exit 1 on any failure)
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

const policy = {
  allowCompany: true,
  allowIndividual: true,
  requiredCompanyFields: [],
  customFields: [
    { key: 'heardFrom', label: 'How did you hear about us?', type: 'text', required: true, appliesTo: 'both' },
    { key: 'vatNumber', label: 'VAT number', type: 'text', required: true, appliesTo: 'company' },
    { key: 'size', label: 'Team size', type: 'select', required: false, options: ['1-10', '11+'], appliesTo: 'buyer', helpText: 'Roughly' },
    { key: 'terms', label: 'I accept the terms', type: 'checkbox', required: true, appliesTo: 'both' },
    { key: 'notes', label: 'Notes', type: 'textarea', required: false, appliesTo: 'both' },
    { key: 'x" onfocus="alert(1)', label: 'Hostile key', type: 'text', required: true },
    { key: 'evil', label: '<img src=x onerror=alert(1)>', type: 'nope', required: false },
  ],
};

check('every question with a safe key is rendered, under customFields[key]', () => {
  const html = quotesApi(policy).buildQuestions();
  for (const key of ['heardFrom', 'vatNumber', 'size', 'terms', 'notes', 'evil']) {
    assert.match(html, new RegExp('name="customFields\\[' + key + '\\]"'), key);
  }
});

check('a key outside the server\'s own rule is never rendered', () => {
  const html = quotesApi(policy).buildQuestions();
  assert.doesNotMatch(html, /onfocus/);
  assert.doesNotMatch(html, /Hostile key/);
});

check('labels and options are escaped; an unknown type is a text input', () => {
  const html = quotesApi(policy).buildQuestions();
  assert.doesNotMatch(html, /<img/);
  assert.match(html, /&lt;img src=x onerror=alert\(1\)&gt;/);
  assert.match(html, /<input type="text" class="input-text" id="tack-quote-q-evil"/);
});

check('types: textarea, select with the seller\'s options, checkbox valued "true", help text', () => {
  const html = quotesApi(policy).buildQuestions();
  assert.match(html, /<textarea class="input-text" rows="3" id="tack-quote-q-notes"/);
  assert.match(html, /<select id="tack-quote-q-size"[^>]*><option value="">Choose an option<\/option><option value="1-10">1-10<\/option><option value="11\+">11\+<\/option><\/select>/);
  assert.match(html, /<input type="checkbox" value="true" id="tack-quote-q-terms"[^>]* required \/>/);
  assert.match(html, /<small class="tack-quote-question-help">Roughly<\/small>/);
});

check('required questions carry `required`; optional ones say so', () => {
  const html = quotesApi(policy).buildQuestions();
  assert.match(html, /id="tack-quote-q-heardFrom"[^>]* required/);
  assert.doesNotMatch(html, /id="tack-quote-q-notes"[^>]* required/);
  assert.match(html, /for="tack-quote-q-notes">Notes <span class="tack-quote-optional">/);
});

check('each question says whom it applies to', () => {
  const html = quotesApi(policy).buildQuestions();
  assert.match(html, /data-tack-applies="company"><label for="tack-quote-q-vatNumber"/);
  assert.match(html, /data-tack-applies="buyer"><label for="tack-quote-q-size"/);
  assert.match(html, /data-tack-applies="both"><label for="tack-quote-q-heardFrom"/);
});

check('questionApplies: both always; company only for a company request; buyer only for an individual', () => {
  const q = quotesApi(policy);
  assert.equal(q.questionApplies('both', true), true);
  assert.equal(q.questionApplies('both', false), true);
  assert.equal(q.questionApplies('company', true), true);
  assert.equal(q.questionApplies('company', false), false);
  assert.equal(q.questionApplies('buyer', false), true);
  assert.equal(q.questionApplies('buyer', true), false);
});

check('questionAnswers: values trimmed, a checkbox is "true" or "", the first empty required one is named', () => {
  const q = quotesApi(policy);
  const r = q.questionAnswers([
    { key: 'heardFrom', type: 'text', required: true, label: 'How did you hear about us?', value: '  ' },
    { key: 'terms', type: 'checkbox', required: true, label: 'I accept the terms', checked: false },
    { key: 'notes', type: 'textarea', required: false, label: 'Notes', value: ' hi ' },
  ]);
  assert.equal(r.missing, 'How did you hear about us?');
  assert.deepEqual(JSON.parse(JSON.stringify(r.values)), { heardFrom: '', terms: '', notes: 'hi' });
  const ok = q.questionAnswers([{ key: 'terms', type: 'checkbox', required: true, label: 'T', checked: true }]);
  assert.equal(ok.missing, '');
  assert.equal(ok.values.terms, 'true');
});

check('no questions in the policy: nothing rendered', () => {
  assert.equal(quotesApi({ allowCompany: true, allowIndividual: true }).buildQuestions(), '');
  assert.equal(quotesApi(null).buildQuestions(), '');
});

check('both forms render the questions: the built-in one and the seller-designed one', () => {
  const q = quotesApi(policy);
  assert.match(q.sellerPolicyFields({ roles: {} }), /class="tack-quote-questions"/);
  const src = read('tack-quotes.js').replace(/\s+/g, '');
  const builtIn = src.slice(src.indexOf('functionbuildBuiltInFields('), src.indexOf('functionopenModal('));
  assert.ok(builtIn.includes('buildCompanyFields()+buildQuestions()+'), 'built-in form');
});

check('both submits send the answers and stop on an empty required one', () => {
  const src = read('tack-quotes.js').replace(/\s+/g, '');
  const seller = src.slice(src.indexOf('functionsubmitSellerForm('), src.indexOf('functioncompanyChosen('));
  assert.ok(seller.includes("name:'customFields['+k+']'"), 'seller form posts customFields[k]');
  assert.ok(seller.includes('if(answers.missing){'), 'seller form stops');
  const builtIn = src.slice(src.indexOf('functionsubmitRequest('));
  assert.ok(builtIn.includes('payload.customFields=answers.values;'), 'built-in form posts customFields');
  assert.ok(builtIn.includes('if(answers.missing){'), 'built-in form stops');
});

check('the company choice also shows/hides (and disables) the questions', () => {
  const src = read('tack-quotes.js').replace(/\s+/g, '');
  const sync = src.slice(src.indexOf('functionsyncCompanySection('), src.indexOf('functioncompanyAnswers('));
  assert.ok(sync.includes("$overlay.find('.tack-quote-question').each("), 'walks the questions');
  assert.ok(sync.includes(".find('[data-tack-question]').prop('disabled',!show)"), 'disables the hidden ones');
});

console.log(failures ? '\n' + failures + ' failure(s)' : '\nAll checks passed');
process.exit(failures ? 1 : 0);
