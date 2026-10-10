/* global TackQuotes, jQuery */
(function ($) {
  'use strict';

  // Storefront text, translated through wp.i18n (the script is enqueued with the
  // `wp-i18n` dependency and wp_set_script_translations( 'tackquote', 'tackquote' )).
  // The literals stay here, in __() calls, so make-pot and translate.wordpress.org
  // can extract them; the JED files in languages/ carry the bundled translations.
  // Falls back to English if wp.i18n is somehow absent, never to blank labels.
  var wpI18n = window.wp && window.wp.i18n ? window.wp.i18n : null;
  var __ = wpI18n ? wpI18n.__ : function (text) {
    return text;
  };
  // Positional printf (%s, %d, %1$s): wp.i18n's own when present.
  var sprintf = wpI18n && wpI18n.sprintf ? wpI18n.sprintf : function (format) {
    var args = Array.prototype.slice.call(arguments, 1);
    var next = 0;
    return String(format).replace(/%(?:(\d+)\$)?[sd]/g, function (m, pos) {
      var v = pos ? args[Number(pos) - 1] : args[next++];
      return v == null ? '' : String(v);
    });
  };
  TackQuotes.i18n = {
    modalTitle: __('Request a Quote', 'tackquote'),
    firstNameLabel: __('First name', 'tackquote'),
    lastNameLabel: __('Last name', 'tackquote'),
    emailLabel: __('Email address', 'tackquote'),
    phoneLabel: __('Phone', 'tackquote'),
    companyHeading: __('Company details', 'tackquote'),
    companyNameLabel: __('Company name', 'tackquote'),
    buyingAsLabel: __('I am buying as', 'tackquote'),
    buyingAsIndividual: __('An individual', 'tackquote'),
    buyingAsCompany: __('A company', 'tackquote'),
    optional: __('(optional)', 'tackquote'),
    firstNameRequired: __('Please enter your first name.', 'tackquote'),
    companyRequired: __('Please complete the required company details.', 'tackquote'),
    // Neutral on purpose (1.8.1): TackQuote answers awaitingApproval for EVERY company
    // request, so this must not say whether a company name matched an existing account.
    awaitingApproval: __("Request received. If your company account needs approval, we'll email you when it is ready.", 'tackquote'),
    portalLink: __('Go to your buyer portal', 'tackquote'),
    emailPlaceholder: __('you@example.com', 'tackquote'),
    // Just "Note": the form builder appends the optional marker itself.
    noteLabel: __('Note', 'tackquote'),
    notePlaceholder: __('Anything the seller should know about this request…', 'tackquote'),
    submit: __('Send request', 'tackquote'),
    sending: __('Sending…', 'tackquote'),
    cancel: __('Cancel', 'tackquote'),
    close: __('Close', 'tackquote'),
    error: __('Could not create the quote. Please try again.', 'tackquote'),
    reload: __('Reload page', 'tackquote'),
    emailRequired: __('Please enter a valid email address.', 'tackquote'),
    success: __('Quote requested! Redirecting you to it now…', 'tackquote'),
    added: __('Added ✓', 'tackquote'),
    remove: __('Remove', 'tackquote'),
    cartEmpty: __('Your cart is empty.', 'tackquote'),
    quantity: __('Quantity', 'tackquote'),
    targetPrice: __('Target price', 'tackquote'),
    targetPricePlaceholder: __('Optional', 'tackquote'),
    yourPrice: __('Your price', 'tackquote'),
    // 1.10.0 attachments. The catalogue's wording, so the bundled languages match
    // the other TackQuote storefronts.
    filesLabel: __('Attach files (optional)', 'tackquote'),
    filesHelp: __('Up to %1$d PDF, JPEG or PNG files, %2$d MB each. Only the seller can open them.', 'tackquote'),
    filesMax: __('Attach at most %d files.', 'tackquote'),
    fileType: __('%s: attach a PDF, JPEG or PNG file.', 'tackquote'),
    fileSize: __('%1$s: files can be at most %2$d MB.', 'tackquote'),
    fileFailed: __('That file could not be attached. Please try again.', 'tackquote'),
    uploading: __('Uploading %s…', 'tackquote'),
    // Company field labels keyed by the names requiredCompanyFields returns; an
    // unlisted key falls back to a humanised version of itself.
    companyFields: {
      legalName: __('Legal name', 'tackquote'),
      taxId: __('Tax / VAT ID', 'tackquote'),
      registrationNumber: __('Registration number', 'tackquote'),
      website: __('Website', 'tackquote'),
      addressLine1: __('Address', 'tackquote'),
      addressLine2: __('Address line 2', 'tackquote'),
      city: __('City', 'tackquote'),
      state: __('State / Province', 'tackquote'),
      postalCode: __('Postal code', 'tackquote'),
      country: __('Country', 'tackquote'),
      phone: __('Company phone', 'tackquote'),
      industry: __('Industry', 'tackquote'),
      employeeCount: __('Number of employees', 'tackquote'),
    },
  };

  var modal = null;
  var STORAGE_KEY = 'tack_quote_list';

  // ─── Quote list (browser-side, separate from the WooCommerce cart) ───────

  function getList() {
    try {
      var raw = localStorage.getItem(STORAGE_KEY);
      return raw ? JSON.parse(raw) : [];
    } catch (e) {
      return [];
    }
  }

  function saveList(list) {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(list));
    } catch (e) {
      // Ignore — worst case the list doesn't persist across reloads.
    }
    renderList(list);
  }

  function addToList(item) {
    var list = getList();
    var existing = null;
    for (var i = 0; i < list.length; i++) {
      // Match on product AND variation. Keying on productId alone merged two different
      // variations of one parent into a single line — adding Medium then Large produced one
      // row of quantity 2, and the server then quoted whichever variation was stored on it.
      if (
        list[i].productId === item.productId &&
        (list[i].variationId || 0) === (item.variationId || 0)
      ) {
        existing = list[i];
        break;
      }
    }
    if (existing) {
      existing.quantity += item.quantity;
    } else {
      list.push(item);
    }
    saveList(list);
  }

  // Variation-aware for the same reason addToList's match is: two rows can share a
  // productId and differ only by variation.
  function removeFromList(productId, variationId) {
    var list = getList().filter(function (row) {
      return !(
        row.productId === productId && (row.variationId || 0) === (variationId || 0)
      );
    });
    saveList(list);
  }

  function clearList() {
    saveList([]);
  }

  // ─── 1.10.0: quantities, target prices, re-pricing, where "open the quote" goes ───

  function sameRow(row, productId, variationId) {
    return row.productId === productId && (row.variationId || 0) === (variationId || 0);
  }

  function setQuantity(productId, variationId, quantity) {
    var list = getList();
    for (var i = 0; i < list.length; i++) {
      if (sameRow(list[i], productId, variationId)) {
        list[i].quantity = Math.max(1, Math.floor(Number(quantity) || 1));
        // A price resolved for the OLD quantity is not this line's price any more.
        delete list[i].yourPrice;
        delete list[i].yourPriceQty;
      }
    }
    saveList(list);
    scheduleReprice();
  }

  // Optional, numeric, >= 0. Stored on the row; the server writes it into the request
  // note (the plugin's request contract has no per-line field for it yet).
  function setTargetPrice(productId, variationId, value) {
    var list = getList();
    var n = String(value == null ? '' : value).trim();
    for (var i = 0; i < list.length; i++) {
      if (sameRow(list[i], productId, variationId)) {
        if (n === '' || !/^\d+(\.\d+)?$/.test(n)) {
          delete list[i].targetPrice;
        } else {
          list[i].targetPrice = Number(n);
        }
      }
    }
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(list));
    } catch (e) {
      // Ignore — see saveList.
    }
  }

  // The store's own price format (symbol, decimals, separators, position), as
  // WooCommerce reports it. Display only: every quoted amount is re-derived server-side.
  function formatPrice(amount) {
    var p = TackQuotes.price || {};
    var n = Number(amount);
    if (!isFinite(n)) {
      return '';
    }
    var decimals = typeof p.decimals === 'number' ? p.decimals : 2;
    var fixed = n.toFixed(decimals);
    var parts = fixed.split('.');
    var whole = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, p.thousandSep == null ? ',' : p.thousandSep);
    var body = whole + (parts[1] ? (p.decimalSep == null ? '.' : p.decimalSep) + parts[1] : '');
    var symbol = p.symbol || '';
    switch (p.position) {
      case 'right':
        return body + symbol;
      case 'left_space':
        return symbol + ' ' + body;
      case 'right_space':
        return body + ' ' + symbol;
      default:
        return symbol + body;
    }
  }

  function onQuotePage() {
    return document.getElementById('tack-quote-page') !== null;
  }

  // Where "open the quote" goes: the drawer, or the merchant's quote page when the
  // setting says so and we are not already on it.
  function openQuote() {
    if (TackQuotes.opens === 'page' && TackQuotes.pageUrl && !onQuotePage()) {
      window.location.href = TackQuotes.pageUrl;
      return;
    }
    if (onQuotePage()) {
      return;
    }
    setDrawerOpen(true);
  }

  // 1.10.0: the theme's own button classes (`button`, plus `wp-element-button` on a
  // block theme), printed by Tack_Widget::button_class(), so the modal's buttons
  // look like every other button on the store. `extra` is appended.
  function buttonClass(extra) {
    var base = typeof TackQuotes.buttonClass === 'string' ? TackQuotes.buttonClass : 'button';
    return escapeHtml(base + (extra ? ' ' + extra : ''));
  }

  // Re-price the list at the line quantities for a signed-in buyer: one batched call,
  // debounced, through the same TackQuote pricing read that prices the cart. Guests
  // (TackQuotes.repriceEnabled false) are never asked for. A line TackQuote does not
  // price answers null and keeps the store price it was added at.
  var repriceTimer = null;
  function scheduleReprice() {
    if (!TackQuotes.repriceEnabled) {
      return;
    }
    window.clearTimeout(repriceTimer);
    repriceTimer = window.setTimeout(reprice, 400);
  }

  function reprice() {
    var list = getList();
    if (!list.length) {
      return;
    }
    $.post(TackQuotes.ajaxUrl, {
      action: 'tack_quote_reprice',
      nonce: TackQuotes.nonce,
      items: JSON.stringify(
        list.map(function (row) {
          return { product_id: row.productId, variation_id: row.variationId || 0, quantity: row.quantity };
        }),
      ),
    }).done(function (res) {
      var items = res && res.success && res.data && res.data.items;
      if (!Array.isArray(items)) {
        return;
      }
      var fresh = getList();
      items.forEach(function (it) {
        for (var i = 0; i < fresh.length; i++) {
          if (
            sameRow(fresh[i], it.product_id, it.variation_id) &&
            fresh[i].quantity === it.quantity &&
            it.unitPrice !== null &&
            it.unitPrice !== undefined
          ) {
            fresh[i].yourPrice = it.formatted || formatPrice(it.unitPrice);
            fresh[i].yourPriceQty = it.quantity;
          }
        }
      });
      saveList(fresh);
    });
  }

  // ─── Floating quote-list widget (button + drawer) ────────────────────────

  function renderList(list) {
    var $widget = $('#tack-quote-list-widget');
    if (!$widget.length) {
      return;
    }

    $widget.prop('hidden', list.length === 0);
    $('#tack-quote-list-count').text(list.length);

    var $items = $('#tack-quote-list-items').empty();
    list.forEach(function (row) {
      var $li = $('<li class="tack-quote-list-item"></li>');
      $li.append($('<span class="tack-quote-list-item-name"></span>').text(row.name));
      // Editable quantity (1.10.0). A change re-prices the line for a signed-in buyer.
      var $qty = $('<span class="tack-quote-list-item-qty"></span>');
      $qty.append(document.createTextNode('×'));
      var $input = $('<input type="number" min="1" step="1" class="input-text qty tack-quote-list-item-qty-input" />')
        .attr('aria-label', TackQuotes.i18n.quantity)
        .val(row.quantity);
      $input.on('change', function () {
        setQuantity(row.productId, row.variationId, $input.val());
      });
      $qty.append($input);
      $li.append($qty);
      if (row.yourPrice && row.yourPriceQty === row.quantity) {
        $li.append(
          $('<span class="tack-quote-list-item-price"></span>')
            .attr('title', TackQuotes.i18n.yourPrice || '')
            .text(row.yourPrice),
        );
      }
      var $remove = $(
        '<button type="button" class="tack-quote-list-item-remove" aria-label="' +
          escapeHtml(TackQuotes.i18n.remove) +
          '">&times;</button>',
      );
      $remove.on('click', function () {
        removeFromList(row.productId, row.variationId);
      });
      $li.append($remove);
      $items.append($li);
    });

    $('#tack-quote-list-checkout').prop('disabled', list.length === 0);
    renderQuotePage(list);
  }

  // ─── 1.10.0: the quote page ([tackquote_quote_page]) ─────────────────────────
  //
  // Same list as the drawer, on a page of the merchant's. Quantities, an optional
  // target price per line, and a message that becomes the request note.
  function renderQuotePage(list) {
    var $page = $('#tack-quote-page');
    if (!$page.length) {
      return;
    }
    var withTarget = $page.data('target-price') !== 'no';
    var $table = $page.find('.tack-quote-page-table');
    var $empty = $page.find('.tack-quote-page-empty');
    var $body = $('#tack-quote-page-items').empty();

    $empty.prop('hidden', list.length > 0);
    $table.prop('hidden', list.length === 0);
    $('#tack-quote-page-submit').prop('disabled', list.length === 0);

    // `shop_table_responsive` (1.10.0): on a narrow screen WooCommerce's stylesheet
    // stacks each cell under its `data-title`, so every cell carries its column
    // heading, read from the table's own (translated, overridable) header.
    var titles = {};
    $table.find('thead th').each(function () {
      var col = (this.className.match(/tack-quote-page-col-[a-z]+/) || [''])[0];
      // The remove column's heading is screen-reader text; WooCommerce's own cart
      // gives that cell no title either.
      if (col && col !== 'tack-quote-page-col-remove') {
        titles[col] = $.trim($(this).text());
      }
    });
    // WooCommerce's own cart-table cell classes beside ours, so the theme's cart
    // styling applies (and the remove cell gets no stacked heading on phones).
    var wcCell = {
      'tack-quote-page-col-product': 'product-name',
      'tack-quote-page-col-qty': 'product-quantity',
      'tack-quote-page-col-price': 'product-price',
      'tack-quote-page-col-remove': 'product-remove',
    };
    function cell(col) {
      var $td = $('<td class="' + (wcCell[col] ? wcCell[col] + ' ' : '') + col + '"></td>');
      if (titles[col]) {
        $td.attr('data-title', titles[col]);
      }
      return $td;
    }

    list.forEach(function (row) {
      var $tr = $('<tr class="tack-quote-page-item"></tr>');
      var $name = cell('tack-quote-page-col-product').text(row.name);
      if (row.sku) {
        $name.append($('<small class="tack-quote-page-item-sku"></small>').text(row.sku));
      }
      $tr.append($name);

      var $qty = $('<input type="number" min="1" step="1" class="input-text qty tack-quote-page-qty" />')
        .attr('aria-label', TackQuotes.i18n.quantity)
        .val(row.quantity);
      $qty.on('change', function () {
        setQuantity(row.productId, row.variationId, $qty.val());
      });
      $tr.append(cell('tack-quote-page-col-qty').append($qty));

      var priceText = row.yourPrice && row.yourPriceQty === row.quantity ? row.yourPrice : row.price ? formatPrice(row.price) : '';
      $tr.append(cell('tack-quote-page-col-price').text(priceText));

      if (withTarget) {
        var $target = $('<input type="number" min="0" step="any" class="input-text tack-quote-page-target" />')
          .attr('aria-label', TackQuotes.i18n.targetPrice)
          .attr('placeholder', TackQuotes.i18n.targetPricePlaceholder || '')
          .val(row.targetPrice == null ? '' : row.targetPrice);
        $target.on('change', function () {
          setTargetPrice(row.productId, row.variationId, $target.val());
        });
        $tr.append(cell('tack-quote-page-col-target').append($target));
      }

      var $remove = $('<button type="button" class="tack-quote-page-remove">&times;</button>').attr(
        'aria-label',
        TackQuotes.i18n.remove,
      );
      $remove.on('click', function () {
        removeFromList(row.productId, row.variationId);
      });
      $tr.append(cell('tack-quote-page-col-remove').append($remove));
      $body.append($tr);
    });
  }

  function escapeHtml(str) {
    return String(str == null ? '' : str).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  // ─── Request-a-quote modal (single product or the whole quote list) ──────

  // ─── Form fields, driven by the seller's registration policy ────────────────
  //
  // The form used to be a single email box. That is why the API had to invent a buyer name
  // from the email local part (`email.split('@')[0]`), so sellers saw contacts called
  // "woo-buyer" with no way to tell the name was fabricated — and why no company could ever
  // be registered from WooCommerce even when the seller's policy required one.
  //
  // Which fields appear is NOT hardcoded here: it comes from
  // GET /integrations/woocommerce/registration-config (the seller's own policy — company vs
  // individual, which company details are mandatory). `TackQuotes.registration` is null when
  // that call failed, and the fallback is a name + email form rather than nothing, so a
  // shopper can still ask for a quote when our API is unreachable.

  function reg() {
    return TackQuotes.registration || null;
  }

  // Company field keys come off the wire, so they are allowlisted here with the SAME charset
  // the PHP handler applies to them on the way in
  // (class-tack-widget.php: /^[A-Za-z0-9_]{1,40}$/ over $_POST['company']).
  //
  // Note the asymmetry that existed before this: PHP allowlisted on the way IN, the JS did
  // not allowlist on the way OUT. `TackQuotes.registration.requiredCompanyFields` is the JSON
  // body of GET /integrations/woocommerce/registration-config, and the API base URL is a
  // plugin setting, so these keys are server-controlled, not ours.
  var SAFE_FIELD_KEY = /^[A-Za-z0-9_]{1,40}$/;

  function isSafeFieldKey(key) {
    return typeof key === 'string' && SAFE_FIELD_KEY.test(key);
  }

  // `id` and `name` are escaped for the same reason `label` and `placeholder` always were.
  // They used to be interpolated raw, which let a policy field named
  // `x" autofocus onfocus="…` close the attribute and execute — in every shopper's browser,
  // and in any administrator's browser on a page that renders this form.
  function field(id, name, label, opts) {
    var o = opts || {};
    return (
      '<div class="form-row tack-quote-field' + (o.half ? ' tack-quote-field-half' : '') + '">' +
      '<label for="' + escapeHtml(id) + '">' +
      escapeHtml(label) +
      (o.required ? '' : ' <span class="tack-quote-optional">' + escapeHtml(TackQuotes.i18n.optional) + '</span>') +
      '</label>' +
      '<input type="' + escapeHtml(o.type || 'text') + '" class="input-text" id="' + escapeHtml(id) + '" name="' + escapeHtml(name) + '"' +
      (o.placeholder ? ' placeholder="' + escapeHtml(o.placeholder) + '"' : '') +
      (o.required ? ' required' : '') +
      ' />' +
      '</div>'
    );
  }

  function buildIdentityFields() {
    var i18n = TackQuotes.i18n;
    var html =
      '<div class="tack-quote-field-row">' +
      field('tack-quote-first-name', 'firstName', i18n.firstNameLabel, { required: true, half: true }) +
      field('tack-quote-last-name', 'lastName', i18n.lastNameLabel, { half: true }) +
      '</div>' +
      field('tack-quote-email', 'email', i18n.emailLabel, {
        type: 'email',
        required: true,
        placeholder: i18n.emailPlaceholder,
      }) +
      field('tack-quote-phone', 'phone', i18n.phoneLabel, { type: 'tel' });

    // Only offer the individual/company choice when the seller actually allows both.
    // A company_only or buyer_only policy has nothing to choose, and rendering a
    // single-option radio group is noise that implies a choice the seller does not offer.
    var r = reg();
    if (r && r.allowCompany && r.allowIndividual) {
      html +=
        '<fieldset class="tack-quote-field tack-quote-buying-as">' +
        '<legend>' + escapeHtml(i18n.buyingAsLabel) + '</legend>' +
        '<label><input type="radio" name="buyingAs" value="individual" checked /> ' +
        escapeHtml(i18n.buyingAsIndividual) + '</label>' +
        '<label><input type="radio" name="buyingAs" value="company" /> ' +
        escapeHtml(i18n.buyingAsCompany) + '</label>' +
        '</fieldset>';
    }
    return html;
  }

  function companyFieldLabel(key) {
    var known = TackQuotes.i18n.companyFields || {};
    if (known[key]) {
      return known[key];
    }
    // Humanise an unknown key rather than printing it raw: the seller can add policy fields
    // we have no translation for, and "taxId" is friendlier than nothing but "Tax Id" is
    // friendlier still.
    return key
      .replace(/([A-Z])/g, ' $1')
      .replace(/[_-]+/g, ' ')
      .replace(/^./, function (c) {
        return c.toUpperCase();
      })
      .trim();
  }

  function buildCompanyFields() {
    var r = reg();
    if (!r || !r.allowCompany) {
      return '';
    }
    var i18n = TackQuotes.i18n;
    // company_only means every shopper is a company, so the section is always shown and
    // always required. When both modes are allowed it starts hidden and the radio reveals it.
    var alwaysCompany = !r.allowIndividual;
    var required = Array.isArray(r.requiredCompanyFields) ? r.requiredCompanyFields : [];

    var html =
      '<div class="tack-quote-company-section"' + (alwaysCompany ? '' : ' hidden') + '>' +
      '<h3 class="tack-quote-company-heading">' + escapeHtml(i18n.companyHeading) + '</h3>' +
      field('tack-quote-company-name', 'companyName', i18n.companyNameLabel, { required: true });

    for (var i = 0; i < required.length; i++) {
      var key = required[i];
      // companyName is rendered above; a policy that also lists it must not duplicate it.
      if (key === 'companyName' || key === 'name') {
        continue;
      }
      // Second half of the defence in depth: escaping makes a hostile key inert, the
      // allowlist means it is never rendered at all. A key outside the charset could not have
      // survived the PHP handler's own filter on submit anyway, so dropping it here loses
      // nothing a shopper could have used.
      if (!isSafeFieldKey(key)) {
        continue;
      }
      html += field('tack-quote-company-' + key, 'company[' + key + ']', companyFieldLabel(key), {
        required: true,
      });
    }

    html += '</div>';
    return html;
  }

  // ─── 1.10.0: attachments ───────────────────────────────────────────────────
  //
  // Rendered only when the server-side config says so (the merchant's switch AND
  // the TackQuote server's `attachments` capability). The files go to this store's
  // own `tack_quote_upload` handler, which validates them again and streams them to
  // TackQuote; only the opaque upload ids (and a guest's token) come back here and
  // travel with the quote request.
  function filesConfig() {
    var cfg = TackQuotes.attachments;
    return cfg && cfg.action ? cfg : null;
  }

  function buildFilesField() {
    var cfg = filesConfig();
    if (!cfg) {
      return '';
    }
    var i18n = TackQuotes.i18n;
    return (
      '<div class="form-row tack-quote-field tack-quote-files">' +
      '<label for="tack-quote-files">' + escapeHtml(i18n.filesLabel) + '</label>' +
      '<input type="file" id="tack-quote-files" name="files[]" multiple accept="' +
      escapeHtml(cfg.accept || '') + '" aria-describedby="tack-quote-files-help" />' +
      '<small id="tack-quote-files-help" class="tack-quote-files-help">' +
      escapeHtml(sprintf(i18n.filesHelp, cfg.maxFiles, cfg.maxMb)) +
      '</small>' +
      '</div>'
    );
  }

  var ACCEPTED_FILE = /\.(pdf|jpe?g|png)$/i;

  // The same limits the server enforces, checked first so the shopper gets an
  // inline message instead of a round trip. The server stays the authority.
  function checkFiles(files, cfg) {
    var i18n = TackQuotes.i18n;
    if (files.length > cfg.maxFiles) {
      return sprintf(i18n.filesMax, cfg.maxFiles);
    }
    for (var i = 0; i < files.length; i++) {
      if (!ACCEPTED_FILE.test(files[i].name || '')) {
        return sprintf(i18n.fileType, files[i].name);
      }
      if (files[i].size > cfg.maxMb * 1024 * 1024) {
        return sprintf(i18n.fileSize, files[i].name, cfg.maxMb);
      }
    }
    return '';
  }

  // POST the chosen files; resolves with {uploadIds, uploadToken?}. Retries ONCE
  // with a fresh nonce when the page's nonce has gone stale (cached HTML).
  function uploadFiles(files, cfg) {
    var deferred = $.Deferred();
    function attempt(allowRetry) {
      var body = new window.FormData();
      body.append('action', cfg.action);
      body.append('nonce', TackQuotes.nonce);
      for (var i = 0; i < files.length; i++) {
        body.append('files[]', files[i]);
      }
      $.ajax({
        url: TackQuotes.ajaxUrl,
        type: 'POST',
        data: body,
        processData: false,
        contentType: false,
      })
        .done(function (res) {
          if (res && res.success && res.data && Array.isArray(res.data.uploadIds)) {
            deferred.resolve(res.data);
          } else {
            deferred.reject((res && res.data) || null);
          }
        })
        .fail(function (xhr) {
          var data = (xhr && xhr.responseJSON && xhr.responseJSON.data) || null;
          if (allowRetry && data && data.code === 'tack_nonce_expired' && TackQuotes.nonceUrl) {
            $.get(TackQuotes.nonceUrl)
              .done(function (fresh) {
                var refreshed = fresh && fresh.data && fresh.data.nonce;
                if (!refreshed) {
                  deferred.reject(data);
                  return;
                }
                TackQuotes.nonce = refreshed;
                attempt(false);
              })
              .fail(function () {
                deferred.reject(data);
              });
            return;
          }
          deferred.reject(data);
        });
    }
    attempt(true);
    return deferred.promise();
  }

  function buildModal() {
    var i18n = TackQuotes.i18n;

    var overlay = document.createElement('div');
    overlay.className = 'tack-quote-modal-overlay';
    overlay.setAttribute('hidden', 'hidden');

    overlay.innerHTML =
      '<div class="tack-quote-modal woocommerce" role="dialog" aria-modal="true" aria-labelledby="tack-quote-modal-title">' +
      '<button type="button" class="tack-quote-modal-close" aria-label="' +
      escapeHtml(i18n.close) +
      '">&times;</button>' +
      '<h2 id="tack-quote-modal-title">' +
      escapeHtml(i18n.modalTitle) +
      '</h2>' +
      '<form class="tack-quote-modal-form" novalidate>' +
      buildIdentityFields() +
      buildCompanyFields() +
      '<div class="form-row tack-quote-field">' +
      '<label for="tack-quote-note">' +
      escapeHtml(i18n.noteLabel) +
      ' <span class="tack-quote-optional">' +
      escapeHtml(i18n.optional) +
      '</span></label>' +
      '<textarea id="tack-quote-note" class="input-text" name="note" rows="3" placeholder="' +
      escapeHtml(i18n.notePlaceholder) +
      '"></textarea>' +
      '</div>' +
      buildFilesField() +
      '<p class="tack-quote-modal-error" hidden></p>' +
      '<p class="tack-quote-modal-success" hidden></p>' +
      '<div class="tack-quote-modal-actions">' +
      '<button type="button" class="' + buttonClass('tack-quote-modal-cancel') + '">' +
      escapeHtml(i18n.cancel) +
      '</button>' +
      '<button type="submit" class="' + buttonClass('alt tack-quote-modal-submit') + '">' +
      escapeHtml(i18n.submit) +
      '</button>' +
      '</div>' +
      '</form>' +
      '</div>';

    document.body.appendChild(overlay);
    return overlay;
  }

  function openModal(context) {
    if (!modal) {
      modal = buildModal();
    }

    var $overlay = $(modal);
    var $form = $overlay.find('form');
    var $email = $overlay.find('#tack-quote-email');
    var $note = $overlay.find('#tack-quote-note');
    var $error = $overlay.find('.tack-quote-modal-error');
    var $success = $overlay.find('.tack-quote-modal-success');
    var $submit = $overlay.find('.tack-quote-modal-submit');

    $form[0].reset();
    // Files uploaded for an earlier request were claimed by it (or will expire).
    var filesInput = $overlay.find('#tack-quote-files')[0];
    if (filesInput) {
      filesInput.tackUploaded = null;
    }
    $email.val(TackQuotes.customerEmail || '');
    // The quote page's message, when the request comes from there (1.10.0).
    $note.val(context.message || '');
    $error.hide().text('');
    $success.hide().text('');
    $submit.off('click.tackReload').prop('disabled', false).text(TackQuotes.i18n.submit);
    $form.show();

    modal.removeAttribute('hidden');
    document.body.classList.add('tack-quote-modal-open');
    ($email.val() ? $overlay.find('.tack-quote-modal-submit') : $email).trigger('focus');

    // Company section follows the individual/company choice. Only present when the seller
    // allows both; a company_only policy renders it always-visible with no radio to drive it.
    $overlay
      .off('change.tackBuyingAs')
      .on('change.tackBuyingAs', 'input[name="buyingAs"]', function () {
        var isCompany = $overlay.find('input[name="buyingAs"]:checked').val() === 'company';
        var $section = $overlay.find('.tack-quote-company-section');
        $section.prop('hidden', !isCompany);
        // Required-ness has to follow visibility, or the browser blocks submission on a
        // field the shopper cannot see. novalidate is set on the form, but the server
        // validates too and a hidden required field would fail there instead.
        $section.find('input').prop('disabled', !isCompany);
      });

    $form.off('submit').on('submit', function (e) {
      e.preventDefault();
      submitRequest(context, $overlay);
    });
  }

  function closeModal() {
    if (!modal) {
      return;
    }
    modal.setAttribute('hidden', 'hidden');
    document.body.classList.remove('tack-quote-modal-open');
  }

  function submitRequest(context, $overlay) {
    var $error = $overlay.find('.tack-quote-modal-error');
    var $success = $overlay.find('.tack-quote-modal-success');
    var $submit = $overlay.find('.tack-quote-modal-submit');
    var $form = $overlay.find('form');
    var val = function (sel) {
      return ($overlay.find(sel).val() || '').trim();
    };

    var email = val('#tack-quote-email');
    var note = val('#tack-quote-note');
    var firstName = val('#tack-quote-first-name');

    $error.hide().text('');

    var emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!email || !emailPattern.test(email)) {
      $error.text(TackQuotes.i18n.emailRequired).show();
      return;
    }
    // Checked client-side purely so the shopper gets an inline message instead of a round
    // trip; the server is still the authority and rejects it independently.
    if (!firstName) {
      $error.text(TackQuotes.i18n.firstNameRequired).show();
      return;
    }

    var isCompany =
      $overlay.find('input[name="buyingAs"]:checked').val() === 'company' ||
      ($overlay.find('.tack-quote-company-section').length > 0 &&
        !$overlay.find('input[name="buyingAs"]').length);

    var company = {};
    var companyMissing = false;
    if (isCompany) {
      $overlay.find('.tack-quote-company-section input').each(function () {
        var name = this.name;
        var v = (this.value || '').trim();
        if (this.required && !v) {
          companyMissing = true;
        }
        var m = name.match(/^company\[(.+)\]$/);
        if (m) {
          company[m[1]] = v;
        }
      });
      if (companyMissing) {
        $error.text(TackQuotes.i18n.companyRequired).show();
        return;
      }
    }

    var payload = {
      action: 'tack_request_quote',
      nonce: TackQuotes.nonce,
      email: email,
      note: note,
      first_name: firstName,
      last_name: val('#tack-quote-last-name'),
      phone: val('#tack-quote-phone'),
    };

    if (isCompany) {
      payload.company_name = val('#tack-quote-company-name');
      // Sent as a nested object under `company[...]`; jQuery serialises this into
      // company[taxId]=... which PHP parses back into an array, matching the API's
      // CompanyDetailsInput shape without any manual encoding.
      payload.company = company;
    }

    if (context.items) {
      // "Checkout as Quote" from the quote-list drawer — the server
      // re-derives name/SKU/price from each product_id; quantity is the
      // only other value trusted from the client.
      payload.items = JSON.stringify(
        context.items.map(function (row) {
          var out = {
            product_id: row.productId,
            variation_id: row.variationId || 0,
            quantity: row.quantity,
          };
          if (typeof row.targetPrice === 'number' && isFinite(row.targetPrice) && row.targetPrice >= 0) {
            out.target_price = row.targetPrice;
          }
          return out;
        }),
      );
    } else {
      // "Request a Quote" — a single product, submitted immediately.
      var $scope = context.$scope && context.$scope.length ? context.$scope : $();
      payload.product_id = context.productId || 0;
      // `context.quantity` / `context.variationId` are set only for the Add to Cart with
      // Options block (resolved by tack-with-options.js); every other form is read here.
      payload.quantity = context.quantity || $scope.find('input.qty').val() || 1;
      // On a variable product the button can only carry the PARENT id, so the shopper's
      // chosen variation has to be read from WooCommerce's own variation form, which keeps
      // the selected id in a hidden input[name="variation_id"] (0 when nothing is chosen
      // yet). Without this a quote for "X-Large" was recorded against the parent — wrong
      // SKU, and the parent's cheapest price. The server re-validates that this variation
      // really belongs to product_id before using it.
      var variationId =
        context.variationId !== undefined
          ? context.variationId
          : Number($scope.find('input[name="variation_id"]').val()) || 0;
      if (variationId) {
        payload.variation_id = variationId;
      }
    }

    $submit.prop('disabled', true).text(TackQuotes.i18n.sending);

    var cfg = filesConfig();
    var input = cfg ? $overlay.find('#tack-quote-files')[0] : null;
    var files = input && input.files ? Array.prototype.slice.call(input.files) : [];
    if (!files.length) {
      send(payload, true);
      return;
    }
    var problem = checkFiles(files, cfg);
    if (problem) {
      $error.text(problem).show();
      $submit.prop('disabled', false).text(TackQuotes.i18n.submit);
      return;
    }
    // A retry after a failed request reuses the files already uploaded, rather
    // than sending the same bytes again.
    if (input.tackUploaded) {
      withUploads(input.tackUploaded);
      return;
    }
    $submit.text(sprintf(TackQuotes.i18n.uploading, files.map(function (f) { return f.name; }).join(', ')));
    uploadFiles(files, cfg)
      .done(function (uploaded) {
        input.tackUploaded = uploaded;
        $(input).one('change', function () {
          input.tackUploaded = null;
        });
        withUploads(uploaded);
      })
      .fail(function (data) {
        showFailure(data && data.message ? data : { message: TackQuotes.i18n.fileFailed, reload: data && data.reload });
      });

    function withUploads(uploaded) {
      $submit.text(TackQuotes.i18n.sending);
      payload.upload_ids = uploaded.uploadIds;
      if (uploaded.uploadToken) {
        payload.upload_token = uploaded.uploadToken;
      }
      send(payload, true);
    }

    /**
     * POST the quote request, refreshing the nonce and retrying ONCE if the nonce is
     * rejected.
     *
     * The nonce is printed into the page, so on a full-page-cached store it is baked into
     * cached HTML and stops verifying once it ages past the nonce lifetime. Previously the
     * shopper was shown a "reload" button — honest, but it put the fix on the person least
     * equipped to understand why a form that looks fine cannot be submitted.
     *
     * `allowRetry` guarantees exactly one extra attempt, so a genuinely invalid nonce can
     * never become a loop.
     */
    function send(body, allowRetry) {
      $.post(TackQuotes.ajaxUrl, body)
      .done(function (res) {
        if (res && res.success) {
          $form.hide();
          // When the seller's policy requires company approval, the quote IS created but the
          // account is not usable yet. Saying "redirecting you to it now" and then dropping
          // the shopper on a login they cannot pass is worse than telling them the truth.
          var awaiting = res.data && res.data.awaitingApproval;
          $success
            .text(awaiting ? TackQuotes.i18n.awaitingApproval : TackQuotes.i18n.success)
            .show();
          if (context.items) {
            clearList();
          }
          var portalUrl = res.data && res.data.portalUrl;
          if (portalUrl && !awaiting) {
            window.setTimeout(function () {
              window.location.href = portalUrl;
            }, 900);
          } else if (portalUrl && awaiting) {
            // A company request may or may not need approval (TackQuote no longer
            // says which), so the portal is offered as a LINK, never an automatic
            // redirect onto a login the shopper may not be able to pass yet.
            $success.append(
              ' ',
              $('<a class="tack-quote-portal-link"></a>')
                .attr('href', portalUrl)
                .text(TackQuotes.i18n.portalLink || portalUrl)
            );
          }
        } else {
          $error.text((res && res.data && res.data.message) || TackQuotes.i18n.error).show();
          $submit.prop('disabled', false).text(TackQuotes.i18n.submit);
        }
      })
      .fail(function (xhr) {
        var data = (xhr && xhr.responseJSON && xhr.responseJSON.data) || null;

        // Cache-stale nonce: fetch a fresh one from the no-cache endpoint and resubmit,
        // once. The shopper never sees this happen, which is the point — the previous
        // behaviour put the fix (reload the page) on the person least able to know why a
        // form that looks fine will not submit.
        if (allowRetry && data && 'tack_nonce_expired' === data.code && TackQuotes.nonceUrl) {
          $.get(TackQuotes.nonceUrl)
            .done(function (fresh) {
              var refreshed = fresh && fresh.data && fresh.data.nonce;
              if (!refreshed) {
                showFailure(data);
                return;
              }
              TackQuotes.nonce = refreshed;
              body.nonce = refreshed;
              send(body, false);
            })
            .fail(function () {
              showFailure(data);
            });
          return;
        }

        showFailure(data);
      });
    }

    /**
     * Surface a failure the retry could not resolve.
     *
     * The reload affordance is kept for the case where even a freshly minted nonce is
     * rejected — that is no longer "the cache staled it" but something about the session
     * itself, and reloading is still the only move available to the shopper.
     */
    function showFailure(data) {
      $error.text((data && data.message) || TackQuotes.i18n.error).show();

      if (data && data.reload) {
        $submit
          .prop('disabled', false)
          .text(TackQuotes.i18n.reload)
          .off('click.tackReload')
          .on('click.tackReload', function (ev) {
            ev.preventDefault();
            window.location.reload();
          });
        return;
      }

      $submit.prop('disabled', false).text(TackQuotes.i18n.submit);
    }
  }

  // ─── Event wiring ──────────────────────────────────────────────────────────

  // The form the clicked button actually belongs to.
  //
  // Quantity and variation used to be read with page-global selectors — $('input.qty') and
  // $('input[name="variation_id"]') — which take the FIRST match in the document. On a
  // grouped product, an archive with quick-add, a related-products row, or a theme's sticky
  // add-to-cart bar, that is routinely a different product's input. The failure was worse
  // than wrong-quantity: the server correctly rejects a variation that does not belong to
  // the product being quoted, so the shopper was told to "choose the product options" they
  // had already chosen, with no way to make it pass.
  //
  // `form.cart` and `form.variations_form` are WooCommerce's own add-to-cart form classes,
  // and the buttons are printed by woocommerce_after_add_to_cart_button, i.e. inside that
  // form. Falling back to any enclosing form, then to nothing, keeps a theme that has moved
  // the button working rather than silently picking up a stranger's values.
  //
  // WooCommerce's Add to Cart with Options block (1.10.0): in its blockified mode the
  // buttons are rendered AFTER the block, outside its form (a button inside switches
  // WooCommerce to a plain posted form), in a container marked `data-tack-scope`. Their
  // scope is then that block's form for the same product.
  function scopeFor($btn) {
    var $scoped = $btn.closest('form.cart, form.variations_form');
    if ($scoped.length) {
      return $scoped;
    }
    var $form = $btn.closest('form');
    var $box = $btn.closest('.tack-quote-buttons[data-tack-scope="add-to-cart-with-options"]');
    return !$form.length && $box.length ? withOptionsFormFor($box) : $form;
  }

  // ─── Add to Cart with Options block: the variation comes from its form ─────
  //
  // No `form.variations_form` and no `show_variation` there: the block's variation
  // selector is an Interactivity API store. Its form carries a hidden
  // input[name="variation_id"] bound to the selected variation (WooCommerce 11.2.1,
  // AddToCartWithOptions::render(): "used by extensions ... to gather information of
  // the form state") and the product id in input[name="add-to-cart"] /
  // input[name="product_id"]. Stock and visibility come from `data-tack-variations`;
  // tack-with-options.js turns the two into the classic contract.
  var WITH_OPTIONS_FORM = 'form.wc-block-add-to-cart-with-options';

  function withOptionsFormFor($box) {
    var id = String($box.find('[data-product-id]').first().attr('data-product-id') || '');
    if (!id) {
      return $();
    }
    return $(WITH_OPTIONS_FORM)
      .filter(function () {
        return (
          $(this)
            .find('input[name="add-to-cart"], input[name="product_id"]')
            .filter(function () {
              return String(this.value) === id;
            }).length > 0
        );
      })
      .first();
  }

  // The quantity is the block's own input; a product the block treats as not
  // purchasable (quote only) has none, and the plugin prints one beside the buttons.
  function withOptionsState($box) {
    var api = window.TackWithOptions;
    if (!api) {
      return { quotable: false, variationId: 0, label: '', quantity: 1 };
    }
    var $form = withOptionsFormFor($box);
    var $qty = $form.find('input.qty');
    if (!$qty.length) {
      $qty = $box.find('input.qty');
    }
    return api.resolve(
      api.parseStates($box.attr('data-tack-variations')),
      $form.find('input[name="variation_id"]').val(),
      $qty.val()
    );
  }

  function withOptionsBox($btn) {
    return $btn.closest('.tack-quote-buttons[data-tack-scope="add-to-cart-with-options"]');
  }

  // Enable the buttons of a variable product only while a quotable variation is chosen.
  function syncWithOptions() {
    $('.tack-quote-buttons[data-tack-variations]').each(function () {
      var $box = $(this);
      var quotable = withOptionsState($box).quotable;
      $box
        .find('.tack-quote-btn, .tack-add-to-quote-btn')
        .prop('disabled', !quotable)
        .toggleClass('disabled', !quotable);
    });
  }

  // The store re-renders asynchronously after a choice, and it fires no event, so the
  // buttons re-read the form after any interaction with it and once the page settles
  // (attributes chosen by default or auto-selected).
  function scheduleWithOptionsSync() {
    window.setTimeout(syncWithOptions, 0);
    window.setTimeout(syncWithOptions, 150);
  }
  $(document).on('click change input keyup', WITH_OPTIONS_FORM, scheduleWithOptionsSync);
  $(function () {
    syncWithOptions();
  });
  $(window).on('load', function () {
    syncWithOptions();
    window.setTimeout(syncWithOptions, 500);
  });

  // ─── Variable products: mirror WooCommerce's own button gating ──────────────
  //
  // The server already refuses a variable product with no variation chosen (it would
  // otherwise quote the parent SKU at the cheapest variation's price). This is the UX half:
  // WooCommerce keeps its own add-to-cart button in `disabled wc-variation-selection-needed`
  // until a purchasable variation is found, so a quote button sitting in the same form that
  // stays clickable is inconsistent and invites the error the server then rejects.
  //
  // `show_variation` / `hide_variation` are the events core itself listens to in
  // assets/js/frontend/add-to-cart-variation.js — verified against the installed source
  // rather than assumed, since these names are not part of any documented public API.
  //
  // Core's `purchasable` argument is NOT used as-is: it is false whenever the variation is
  // not purchasable, and a quote-only product (store-wide or per product) is made
  // non-purchasable on purpose, so mirroring it left the quote buttons disabled on every
  // quote-only variable product. A variation is quotable when one is chosen, it is visible
  // and in stock — core's other two conditions (add-to-cart-variation.js, WooCommerce 11.2.1).
  $(document).on('show_variation', 'form.variations_form', function (event, variation) {
    var quotable =
      !!(variation && variation.variation_id) &&
      variation.variation_is_visible !== false &&
      variation.is_in_stock !== false;
    $(this)
      .find('.tack-quote-btn, .tack-add-to-quote-btn')
      .prop('disabled', !quotable)
      .toggleClass('disabled', !quotable);
  });

  $(document).on('hide_variation reset_data', 'form.variations_form', function () {
    $(this)
      .find('.tack-quote-btn, .tack-add-to-quote-btn')
      .prop('disabled', true)
      .addClass('disabled');
  });

  // Initial state: a variable product loads with nothing selected, so the buttons must
  // start disabled. Non-variable products have no variations_form and are untouched.
  $(function () {
    $('form.variations_form')
      .find('.tack-quote-btn, .tack-add-to-quote-btn')
      .prop('disabled', true)
      .addClass('disabled');
  });

  // "Request a Quote" (product page) — single product, immediate.
  $(document).on('click', '.tack-quote-btn', function (e) {
    e.preventDefault();
    var $btn = $(this);
    var $box = withOptionsBox($btn);
    if (!$box.length) {
      openModal({ productId: $btn.data('product-id') || 0, $scope: scopeFor($btn) });
      return;
    }
    var chosen = withOptionsState($box);
    if (!chosen.quotable) {
      syncWithOptions();
      return;
    }
    openModal({
      productId: $btn.data('product-id') || 0,
      $scope: scopeFor($btn),
      quantity: chosen.quantity,
      variationId: chosen.variationId,
    });
  });

  // "Add to Quote" (product page) — adds to the browser-side quote list.
  // Never touches the WooCommerce cart, so it can't affect stock, cart
  // totals, or normal checkout.
  $(document).on('click', '.tack-add-to-quote-btn', function (e) {
    e.preventDefault();
    var $btn = $(this);
    var $form = scopeFor($btn);
    var $box = withOptionsBox($btn);
    var chosen = $box.length ? withOptionsState($box) : null;
    if (chosen && !chosen.quotable) {
      syncWithOptions();
      return;
    }
    var quantity = Number($form.find('input.qty').val()) || 1;

    // On a variable product the button carries the PARENT's id/name/sku/price, so the
    // chosen variation has to come from WooCommerce's own hidden input. The server
    // re-derives every value from these ids; the rest is only what the drawer displays.
    var variationId = Number($form.find('input[name="variation_id"]').val()) || 0;
    var variationLabel = $form
      .find('select[name^="attribute_"]')
      .map(function () {
        return $(this).val();
      })
      .get()
      .filter(Boolean)
      .join(' / ');
    if (chosen) {
      // The block has no attribute selects; its variation, label and quantity come
      // from the resolved state.
      variationId = chosen.variationId;
      variationLabel = chosen.label;
      quantity = chosen.quantity;
    }

    addToList({
      productId: $btn.data('product-id') || 0,
      variationId: variationId,
      name:
        ($btn.data('product-name') || '') +
        (variationLabel ? ' - ' + variationLabel : ''),
      sku: $btn.data('product-sku') || '',
      price: Number($btn.data('product-price')) || 0,
      quantity: quantity,
    });
    scheduleReprice();

    var original = $btn.text();
    $btn.text(TackQuotes.i18n.added);
    window.setTimeout(function () {
      $btn.text(original);
    }, 1200);
  });

  // Floating quote-list launcher: the drawer, or the merchant's quote page (1.10.0).
  $(document).on('click', '#tack-quote-list-toggle', function () {
    var href = $(this).data('href');
    if (href && !onQuotePage()) {
      window.location.href = href;
      return;
    }
    // 1.10.0: the launcher toggles the drawer and says so (aria-expanded).
    setDrawerOpen($('#tack-quote-list-drawer').prop('hidden'));
  });

  function setDrawerOpen(open) {
    $('#tack-quote-list-drawer').prop('hidden', !open);
    $('#tack-quote-list-toggle').attr('aria-expanded', open ? 'true' : 'false');
  }

  // Escape closes an open drawer and gives focus back to the launcher.
  $(document).on('keydown', function (e) {
    if ('Escape' !== e.key || $('#tack-quote-list-drawer').prop('hidden') || $('body').hasClass('tack-quote-modal-open')) {
      return;
    }
    setDrawerOpen(false);
    $('#tack-quote-list-toggle').trigger('focus');
  });

  // "Add to Quote" on a product card (1.10.0): simple products only, quantity 1. The
  // server re-derives name/SKU/price from the id; the attributes are what the drawer shows.
  $(document).on('click', '.tack-card-quote-btn', function (e) {
    e.preventDefault();
    e.stopPropagation();
    var $btn = $(this);
    addToList({
      productId: $btn.data('product-id') || 0,
      variationId: 0,
      name: String($btn.data('product-name') || ''),
      sku: String($btn.data('product-sku') || ''),
      price: Number($btn.data('product-price')) || 0,
      quantity: 1,
    });
    scheduleReprice();
    var original = $btn.text();
    $btn.text(TackQuotes.i18n.added);
    window.setTimeout(function () {
      $btn.text(original);
    }, 1200);
    openQuote();
  });

  // "Request a quote for your cart" (1.10.0). The lines were read from the cart when the
  // page rendered; on the Cart block the cart changes without a page load, so the live
  // cart is re-read from WooCommerce's own Store API first (same site, the shopper's own
  // session) and the snapshot is the fallback. Quantities and membership come from the
  // live read; name, SKU and the tax-exclusive price from the matching snapshot line.
  $(document).on('click', '.tack-quote-cart-btn', function (e) {
    e.preventDefault();
    var $btn = $(this);
    var snapshot = [];
    try {
      snapshot = JSON.parse($btn.attr('data-lines') || '[]');
    } catch (err) {
      snapshot = [];
    }
    if (!Array.isArray(snapshot)) {
      snapshot = [];
    }
    $btn.prop('disabled', true);

    function finish(lines) {
      $btn.prop('disabled', false);
      if (!lines.length) {
        window.alert(TackQuotes.i18n.cartEmpty);
        return;
      }
      lines.forEach(function (line) {
        addToList({
          productId: Number(line.product_id) || 0,
          variationId: Number(line.variation_id) || 0,
          name: String(line.name || ''),
          sku: String(line.sku || ''),
          price: Number(line.price) || 0,
          quantity: Math.max(1, Number(line.quantity) || 1),
        });
      });
      scheduleReprice();
      openQuote();
    }

    if (!TackQuotes.storeCartUrl || typeof window.fetch !== 'function') {
      finish(snapshot);
      return;
    }
    window
      .fetch(TackQuotes.storeCartUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) {
        return r.ok ? r.json() : null;
      })
      .then(function (cart) {
        var items = cart && Array.isArray(cart.items) ? cart.items : null;
        if (!items) {
          finish(snapshot);
          return;
        }
        var lines = [];
        items.forEach(function (it) {
          var id = Number(it.id) || 0;
          var match = null;
          for (var i = 0; i < snapshot.length; i++) {
            if ((Number(snapshot[i].variation_id) || Number(snapshot[i].product_id)) === id) {
              match = snapshot[i];
              break;
            }
          }
          if (match) {
            lines.push({
              product_id: match.product_id,
              variation_id: match.variation_id,
              name: match.name,
              sku: match.sku,
              price: match.price,
              quantity: it.quantity,
            });
          } else if (id) {
            // Added since the page rendered: the id is a product or variation id, which
            // the server resolves either way; the display price is the Store API's, in
            // the store's cart tax display mode.
            var minor = it.prices && Number(it.prices.currency_minor_unit);
            var raw = it.prices && Number(it.prices.price);
            lines.push({
              product_id: id,
              variation_id: 0,
              name: String(it.name || ''),
              sku: String(it.sku || ''),
              price: isFinite(raw) && isFinite(minor) ? raw / Math.pow(10, minor) : 0,
              quantity: it.quantity,
            });
          }
        });
        finish(lines);
      })
      .catch(function () {
        finish(snapshot);
      });
  });

  // The quote page's submit: the whole list plus the page's message (1.10.0).
  $(document).on('click', '#tack-quote-page-submit', function () {
    var list = getList();
    if (!list.length) {
      return;
    }
    openModal({ items: list, message: $('#tack-quote-page-message').val() || '' });
  });

  $(document).on('click', '#tack-quote-list-close', function () {
    setDrawerOpen(false);
    $('#tack-quote-list-toggle').trigger('focus');
  });

  // "Checkout as Quote" — submits the whole quote list.
  $(document).on('click', '#tack-quote-list-checkout', function () {
    var list = getList();
    if (!list.length) {
      return;
    }
    openModal({ items: list });
  });

  $(document).on('click', '.tack-quote-modal-overlay', function (e) {
    if (e.target === this) {
      closeModal();
    }
  });

  $(document).on('click', '.tack-quote-modal-close, .tack-quote-modal-cancel', function (e) {
    e.preventDefault();
    closeModal();
  });

  $(document).on('keydown', function (e) {
    if (e.key === 'Escape' && modal && !modal.hasAttribute('hidden')) {
      closeModal();
    }
  });

  // Initial render on page load (in case the list was populated earlier).
  $(function () {
    renderList(getList());
    // A signed-in buyer's list may hold lines added as a guest, or priced for a
    // quantity since changed: ask once on load. No-op for guests.
    var needs = getList().some(function (row) {
      return !row.yourPrice || row.yourPriceQty !== row.quantity;
    });
    if (needs) {
      scheduleReprice();
    }
  });
})(jQuery);
