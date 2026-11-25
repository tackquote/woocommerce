/**
 * 1.16.0: the optional "Why are you deactivating?" dialog on Plugins
 * (assets/js/tack-deactivate-feedback.js). A minimal DOM, no dependencies.
 *
 * Run: node tests/js/deactivate-feedback.test.js   (exit 1 on any failure)
 */
'use strict';

const { setup } = require('../../assets/js/tack-deactivate-feedback.js');

let failures = 0;
function check(label, ok) {
  if (ok) {
    console.log('  PASS  ' + label);
    return;
  }
  failures++;
  console.log('  FAIL  ' + label);
}

function el(attrs) {
  const listeners = {};
  return Object.assign(
    {
      listeners,
      disabled: false,
      focused: false,
      addEventListener(type, fn) {
        (listeners[type] = listeners[type] || []).push(fn);
      },
      fire(type, event) {
        (listeners[type] || []).forEach((fn) => fn(event));
      },
      focus() {
        this.focused = true;
      },
      getAttribute(name) {
        return attrs && name in attrs ? attrs[name] : null;
      },
    },
    attrs && attrs.props ? attrs.props : {}
  );
}

function page({ withDialogSupport = true, withLink = true } = {}) {
  const link = el({ href: 'plugins.php?action=deactivate&plugin=tackquote%2Ftackquote.php&_wpnonce=abc' });
  const skip = el();
  const cancel = el();
  const send = el();
  const form = el();
  form.querySelectorAll = () => [skip, send, cancel];
  const dialog = el();
  dialog.open = false;
  if (withDialogSupport) {
    dialog.showModal = () => { dialog.open = true; };
    dialog.close = () => { dialog.open = false; };
  }
  dialog.querySelector = (sel) => ({ '[data-tackquote-skip]': skip, '[data-tackquote-cancel]': cancel, form }[sel] || null);
  const doc = {
    querySelector: (sel) => (sel === 'a[data-tackquote-deactivate]' && withLink ? link : null),
    getElementById: (id) => (id === 'tackquote-deactivate-feedback' ? dialog : null),
  };
  const win = { location: { href: 'plugins.php' } };
  return { doc, win, link, skip, cancel, send, form, dialog };
}

function click(extra) {
  const e = Object.assign({ button: 0, metaKey: false, ctrlKey: false, shiftKey: false, altKey: false, prevented: false }, extra);
  e.preventDefault = () => { e.prevented = true; };
  return e;
}

// No <dialog> support: nothing is intercepted, the link deactivates as before.
{
  const p = page({ withDialogSupport: false });
  check('no <dialog> support: the script does nothing', setup(p.doc, p.win) === false && !p.link.listeners.click);
}
// No marked link (the dialog is not offered): nothing happens.
{
  const p = page({ withLink: false });
  check('no marked link: the script does nothing', setup(p.doc, p.win) === false);
}
// Deactivate opens the dialog with Skip focused; nothing navigates yet.
{
  const p = page();
  setup(p.doc, p.win);
  const e = click();
  p.link.fire('click', e);
  check('Deactivate: the dialog opens instead of deactivating', e.prevented && p.dialog.open && p.win.location.href === 'plugins.php');
  check('Deactivate: "Skip and deactivate" has the focus', p.skip.focused);
  p.skip.fire('click', click());
  check('Skip: follows WordPress\'s own deactivation link, unchanged', p.win.location.href === 'plugins.php?action=deactivate&plugin=tackquote%2Ftackquote.php&_wpnonce=abc');
}
// Cancel closes and deactivates nothing.
{
  const p = page();
  setup(p.doc, p.win);
  p.link.fire('click', click());
  p.cancel.fire('click', click());
  check('Cancel: closes the dialog, nothing is deactivated', !p.dialog.open && p.win.location.href === 'plugins.php');
}
// A modified click keeps the browser's behaviour.
{
  const p = page();
  setup(p.doc, p.win);
  const e = click({ ctrlKey: true });
  p.link.fire('click', e);
  check('Ctrl+click: not intercepted', !e.prevented && !p.dialog.open);
}
// Submitting disables the buttons (one answer per click).
{
  const p = page();
  setup(p.doc, p.win);
  p.form.fire('submit', {});
  check('Send: every button is disabled while the form posts', p.skip.disabled && p.send.disabled && p.cancel.disabled);
}

if (failures) {
  console.log('\n' + failures + ' failure(s)');
  process.exit(1);
}
console.log('\nAll checks passed');
