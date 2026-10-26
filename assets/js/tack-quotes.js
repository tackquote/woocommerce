/* global TackQuotes, jQuery */
/* eslint-disable @wordpress/valid-sprintf -- every sprintf() format here is a translated string from the i18n map below (a public object a site may override), so the rule, which accepts only a literal or a direct __() call, cannot see it; each of those strings carries a translators comment. */
( function ( $ ) {
	'use strict';

	// Storefront text, translated through wp.i18n (the script is enqueued with the
	// `wp-i18n` dependency and wp_set_script_translations( 'tackquote', 'tackquote' )).
	// The literals stay here, in __() calls, so make-pot and translate.wordpress.org
	// can extract them; the JED files in languages/ carry the bundled translations.
	// Falls back to English if wp.i18n is somehow absent, never to blank labels.
	const wpI18n = window.wp && window.wp.i18n ? window.wp.i18n : null;
	const __ = wpI18n
		? wpI18n.__
		: function ( text ) {
				return text;
		  };
	// Positional printf (%s, %d, %1$s): wp.i18n's own when present.
	const sprintf =
		wpI18n && wpI18n.sprintf
			? wpI18n.sprintf
			: function ( format ) {
					const args = Array.prototype.slice.call( arguments, 1 );
					let next = 0;
					return String( format ).replace(
						/%(?:(\d+)\$)?[sd]/g,
						function ( m, pos ) {
							const v = pos
								? args[ Number( pos ) - 1 ]
								: args[ next++ ];
							return v === null || v === undefined
								? ''
								: String( v );
						}
					);
			  };
	TackQuotes.i18n = {
		modalTitle: __( 'Request a Quote', 'tackquote' ),
		firstNameLabel: __( 'First name', 'tackquote' ),
		lastNameLabel: __( 'Last name', 'tackquote' ),
		emailLabel: __( 'Email address', 'tackquote' ),
		phoneLabel: __( 'Phone', 'tackquote' ),
		companyHeading: __( 'Company details', 'tackquote' ),
		companyNameLabel: __( 'Company name', 'tackquote' ),
		buyingAsLabel: __( 'I am buying as', 'tackquote' ),
		buyingAsIndividual: __( 'An individual', 'tackquote' ),
		buyingAsCompany: __( 'A company', 'tackquote' ),
		optional: __( '(optional)', 'tackquote' ),
		firstNameRequired: __( 'Please enter your first name.', 'tackquote' ),
		// translators: %s: the seller's question, e.g. "How did you hear about us?".
		questionRequired: __( 'Please answer: %s', 'tackquote' ),
		questionChoose: __( 'Choose an option', 'tackquote' ),
		companyRequired: __(
			'Please complete the required company details.',
			'tackquote'
		),
		// Neutral on purpose (1.8.1): TackQuote answers awaitingApproval for EVERY company
		// request, so this must not say whether a company name matched an existing account.
		awaitingApproval: __(
			"Request received. If your company account needs approval, we'll email you when it is ready.",
			'tackquote'
		),
		portalLink: __( 'Open your buyer portal', 'tackquote' ),
		emailPlaceholder: __( 'you@example.com', 'tackquote' ),
		// Just "Note": the form builder appends the optional marker itself.
		noteLabel: __( 'Note', 'tackquote' ),
		notePlaceholder: __(
			'Anything the seller should know about this request…',
			'tackquote'
		),
		submit: __( 'Send request', 'tackquote' ),
		sending: __( 'Sending…', 'tackquote' ),
		cancel: __( 'Cancel', 'tackquote' ),
		close: __( 'Close', 'tackquote' ),
		error: __(
			'Could not create the quote. Please try again.',
			'tackquote'
		),
		reload: __( 'Reload page', 'tackquote' ),
		emailRequired: __( 'Please enter a valid email address.', 'tackquote' ),
		success: __(
			'Your quote request was received. The seller will reply to you by email.',
			'tackquote'
		),
		added: __( 'Added ✓', 'tackquote' ),
		remove: __( 'Remove', 'tackquote' ),
		cartEmpty: __( 'Your cart is empty.', 'tackquote' ),
		quantity: __( 'Quantity', 'tackquote' ),
		// A grouped product with no child given a quantity (1.12.0).
		chooseQuantities: __(
			'Please choose the quantity of each product you want quoted.',
			'tackquote'
		),
		targetPrice: __( 'Target price', 'tackquote' ),
		targetPricePlaceholder: __( 'Optional', 'tackquote' ),
		yourPrice: __( 'Your price', 'tackquote' ),
		// 1.10.0 attachments. The catalogue's wording, so the bundled languages match
		// the other TackQuote storefronts.
		filesLabel: __( 'Attach files (optional)', 'tackquote' ),
		// translators: 1: most files per request, 2: size limit in megabytes.
		filesHelp: __(
			'Up to %1$d PDF, JPEG or PNG files, %2$d MB each. Only the seller can open them.',
			'tackquote'
		),
		// translators: %d: most files per request.
		filesMax: __( 'Attach at most %d files.', 'tackquote' ),
		// translators: %s: file name.
		fileType: __( '%s: attach a PDF, JPEG or PNG file.', 'tackquote' ),
		// translators: 1: file name, 2: size limit in megabytes.
		fileSize: __( '%1$s: files can be at most %2$d MB.', 'tackquote' ),
		fileFailed: __(
			'That file could not be attached. Please try again.',
			'tackquote'
		),
		// translators: %s: the names of the files being uploaded, comma-separated.
		uploading: __( 'Uploading %s…', 'tackquote' ),
		// translators: 1: field label, 2: most files for that field.
		fieldFilesMax: __( '%1$s: attach at most %2$d files.', 'tackquote' ),
		// Company field labels keyed by the names requiredCompanyFields returns; an
		// unlisted key falls back to a humanised version of itself.
		companyFields: {
			legalName: __( 'Legal name', 'tackquote' ),
			taxId: __( 'Tax / VAT ID', 'tackquote' ),
			registrationNumber: __( 'Registration number', 'tackquote' ),
			website: __( 'Website', 'tackquote' ),
			addressLine1: __( 'Address', 'tackquote' ),
			addressLine2: __( 'Address line 2', 'tackquote' ),
			city: __( 'City', 'tackquote' ),
			state: __( 'State / Province', 'tackquote' ),
			postalCode: __( 'Postal code', 'tackquote' ),
			country: __( 'Country', 'tackquote' ),
			phone: __( 'Company phone', 'tackquote' ),
			industry: __( 'Industry', 'tackquote' ),
			employeeCount: __( 'Number of employees', 'tackquote' ),
		},
	};

	let modal = null;
	const STORAGE_KEY = 'tack_quote_list';
	// A note at the top of the quote list (1.12.0 follow-up), until the shopper changes it.
	let listNotice = '';

	// ─── Quote list (browser-side, separate from the WooCommerce cart) ───────

	function getList() {
		try {
			const raw = localStorage.getItem( STORAGE_KEY );
			return raw ? JSON.parse( raw ) : [];
		} catch ( e ) {
			return [];
		}
	}

	function saveList( list ) {
		try {
			localStorage.setItem( STORAGE_KEY, JSON.stringify( list ) );
		} catch ( e ) {
			// Ignore — worst case the list doesn't persist across reloads.
		}
		renderList( list );
	}

	function addToList( item ) {
		listNotice = '';
		const list = getList();
		let existing = null;
		for ( let i = 0; i < list.length; i++ ) {
			// Match on product AND variation (and, 1.12.0, the variation's "Any …" choices).
			// Keying on productId alone merged two different variations of one parent into a
			// single line — adding Medium then Large produced one row of quantity 2, and the
			// server then quoted whichever variation was stored on it.
			if ( sameRow( list[ i ], item ) ) {
				existing = list[ i ];
				break;
			}
		}
		if ( existing ) {
			// A product sold individually stays at one (`max`), as its quote line does.
			existing.quantity = window.TackWithOptions.capQuantity(
				existing.quantity + item.quantity,
				existing.max || item.max
			);
		} else {
			if ( item.max ) {
				item.quantity = window.TackWithOptions.capQuantity(
					item.quantity,
					item.max
				);
			}
			list.push( item );
		}
		saveList( list );
	}

	// Variation-aware for the same reason addToList's match is: two rows can share a
	// productId and differ only by variation.
	function removeFromList( line ) {
		listNotice = '';
		const list = getList().filter( function ( row ) {
			return ! sameRow( row, line );
		} );
		saveList( list );
	}

	function clearList() {
		listNotice = '';
		saveList( [] );
	}

	// ─── 1.10.0: quantities, target prices, re-pricing, where "open the quote" goes ───

	// The same quote line: product, variation and "Any …" choices (TackWithOptions.sameLine).
	function sameRow( row, line ) {
		return window.TackWithOptions.sameLine( row, line );
	}

	// The same product and variation, whatever the choices: what a re-price answers for
	// (the price does not depend on an "Any …" choice).
	function sameIds( row, productId, variationId ) {
		return (
			Number( row.productId ) === Number( productId ) &&
			( Number( row.variationId ) || 0 ) ===
				( Number( variationId ) || 0 )
		);
	}

	function setQuantity( line, quantity ) {
		const list = getList();
		for ( let i = 0; i < list.length; i++ ) {
			if ( sameRow( list[ i ], line ) ) {
				list[ i ].quantity = window.TackWithOptions.capQuantity(
					quantity,
					list[ i ].max
				);
				// A price resolved for the OLD quantity is not this line's price any more.
				delete list[ i ].yourPrice;
				delete list[ i ].yourPriceQty;
			}
		}
		saveList( list );
		scheduleReprice();
	}

	// Optional, numeric, >= 0. Stored on the row; the server writes it into the request
	// note (the plugin's request contract has no per-line field for it yet).
	function setTargetPrice( line, value ) {
		const list = getList();
		const n = String(
			value === null || value === undefined ? '' : value
		).trim();
		for ( let i = 0; i < list.length; i++ ) {
			if ( sameRow( list[ i ], line ) ) {
				if ( n === '' || ! /^\d+(\.\d+)?$/.test( n ) ) {
					delete list[ i ].targetPrice;
				} else {
					list[ i ].targetPrice = Number( n );
				}
			}
		}
		try {
			localStorage.setItem( STORAGE_KEY, JSON.stringify( list ) );
		} catch ( e ) {
			// Ignore — see saveList.
		}
	}

	// The store's own price format (symbol, decimals, separators, position), as
	// WooCommerce reports it. Display only: every quoted amount is re-derived server-side.
	function formatPrice( amount ) {
		const p = TackQuotes.price || {};
		const n = Number( amount );
		if ( ! isFinite( n ) ) {
			return '';
		}
		const decimals = typeof p.decimals === 'number' ? p.decimals : 2;
		const fixed = n.toFixed( decimals );
		const parts = fixed.split( '.' );
		const whole = parts[ 0 ].replace(
			/\B(?=(\d{3})+(?!\d))/g,
			p.thousandSep === null || p.thousandSep === undefined
				? ','
				: p.thousandSep
		);
		const body =
			whole +
			( parts[ 1 ]
				? ( p.decimalSep === null || p.decimalSep === undefined
						? '.'
						: p.decimalSep ) + parts[ 1 ]
				: '' );
		const symbol = p.symbol || '';
		switch ( p.position ) {
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
		return document.getElementById( 'tack-quote-page' ) !== null;
	}

	// Where "open the quote" goes: the drawer, or the merchant's quote page when the
	// setting says so and we are not already on it.
	function openQuote() {
		if (
			TackQuotes.opens === 'page' &&
			TackQuotes.pageUrl &&
			! onQuotePage()
		) {
			window.location.href = TackQuotes.pageUrl;
			return;
		}
		if ( onQuotePage() ) {
			return;
		}
		setDrawerOpen( true );
	}

	// 1.10.0: the theme's own button classes (`button`, plus `wp-element-button` on a
	// block theme), printed by Tack_Widget::button_class(), so the modal's buttons
	// look like every other button on the store. `extra` is appended.
	function buttonClass( extra ) {
		const base =
			typeof TackQuotes.buttonClass === 'string'
				? TackQuotes.buttonClass
				: 'button';
		return escapeHtml( base + ( extra ? ' ' + extra : '' ) );
	}

	// Re-price the list at the line quantities for a signed-in buyer: one batched call,
	// debounced, through the same TackQuote pricing read that prices the cart. Guests
	// (TackQuotes.repriceEnabled false) are never asked for. A line TackQuote does not
	// price answers null and keeps the store price it was added at.
	let repriceTimer = null;
	function scheduleReprice() {
		if ( ! TackQuotes.repriceEnabled ) {
			return;
		}
		window.clearTimeout( repriceTimer );
		repriceTimer = window.setTimeout( reprice, 400 );
	}

	function reprice() {
		const list = getList();
		if ( ! list.length ) {
			return;
		}
		$.post( TackQuotes.ajaxUrl, {
			action: 'tack_quote_reprice',
			nonce: TackQuotes.nonce,
			items: JSON.stringify(
				list.map( function ( row ) {
					return {
						product_id: row.productId,
						variation_id: row.variationId || 0,
						quantity: row.quantity,
					};
				} )
			),
		} ).done( function ( res ) {
			const items = res && res.success && res.data && res.data.items;
			if ( ! Array.isArray( items ) ) {
				return;
			}
			// Rows the server will quote no line for (1.12.0 follow-up): a grouped product
			// saved before grouped products were quoted as their children, a deleted or
			// hidden product. Dropped, so the request does not silently leave them out.
			const dropped = window.TackWithOptions.dropRows(
				getList(),
				res.data.dropped
			);
			if ( dropped.allGrouped ) {
				listNotice = TackQuotes.i18n.chooseQuantities;
			}
			const fresh = dropped.kept;
			items.forEach( function ( it ) {
				for ( let i = 0; i < fresh.length; i++ ) {
					if (
						sameIds( fresh[ i ], it.product_id, it.variation_id ) &&
						fresh[ i ].quantity === it.quantity &&
						it.unitPrice !== null &&
						it.unitPrice !== undefined
					) {
						fresh[ i ].yourPrice =
							it.formatted || formatPrice( it.unitPrice );
						fresh[ i ].yourPriceQty = it.quantity;
					}
				}
			} );
			saveList( fresh );
		} );
	}

	// ─── Floating quote-list widget (button + drawer) ────────────────────────

	function renderList( list ) {
		const $widget = $( '#tack-quote-list-widget' );
		if ( ! $widget.length ) {
			return;
		}

		// Shown even with an empty list, so the shopper learns why a row went away.
		$widget.prop( 'hidden', list.length === 0 && ! listNotice );
		let $notice = $( '#tack-quote-list-notice' );
		if ( listNotice && ! $notice.length ) {
			$notice = $(
				'<p id="tack-quote-list-notice" class="tack-quote-list-notice" role="status"></p>'
			).insertBefore( '#tack-quote-list-items' );
		}
		if ( listNotice ) {
			$notice.text( listNotice );
		} else {
			$notice.remove();
		}
		$( '#tack-quote-list-count' ).text( list.length );

		const $items = $( '#tack-quote-list-items' ).empty();
		list.forEach( function ( row ) {
			const $li = $( '<li class="tack-quote-list-item"></li>' );
			$li.append(
				$( '<span class="tack-quote-list-item-name"></span>' ).text(
					row.name
				)
			);
			// Editable quantity (1.10.0). A change re-prices the line for a signed-in buyer.
			const $qty = $( '<span class="tack-quote-list-item-qty"></span>' );
			$qty.append( document.createTextNode( '×' ) );
			const $input = $(
				'<input type="number" min="1" step="1" class="input-text qty tack-quote-list-item-qty-input" />'
			)
				.attr( 'aria-label', TackQuotes.i18n.quantity )
				.attr( 'max', row.max || null )
				.val( row.quantity );
			$input.on( 'change', function () {
				setQuantity( row, $input.val() );
			} );
			$qty.append( $input );
			$li.append( $qty );
			if ( row.yourPrice && row.yourPriceQty === row.quantity ) {
				$li.append(
					$( '<span class="tack-quote-list-item-price"></span>' )
						.attr( 'title', TackQuotes.i18n.yourPrice || '' )
						.text( row.yourPrice )
				);
			}
			const $remove = $(
				'<button type="button" class="tack-quote-list-item-remove" aria-label="' +
					escapeHtml( TackQuotes.i18n.remove ) +
					'">&times;</button>'
			);
			$remove.on( 'click', function () {
				removeFromList( row );
			} );
			$li.append( $remove );
			$items.append( $li );
		} );

		$( '#tack-quote-list-checkout' ).prop( 'disabled', list.length === 0 );
		renderQuotePage( list );
	}

	// ─── 1.10.0: the quote page ([tackquote_quote_page]) ─────────────────────────
	//
	// Same list as the drawer, on a page of the merchant's. Quantities, an optional
	// target price per line, and a message that becomes the request note.
	function renderQuotePage( list ) {
		const $page = $( '#tack-quote-page' );
		if ( ! $page.length ) {
			return;
		}
		const withTarget = $page.data( 'target-price' ) !== 'no';
		const $table = $page.find( '.tack-quote-page-table' );
		const $empty = $page.find( '.tack-quote-page-empty' );
		const $body = $( '#tack-quote-page-items' ).empty();

		$empty.prop( 'hidden', list.length > 0 );
		$table.prop( 'hidden', list.length === 0 );
		// The same note as the drawer's (a grouped product removed, 1.12.0 follow-up).
		let $note = $page.find( '.tack-quote-list-notice' );
		if ( listNotice && ! $note.length ) {
			$note = $(
				'<p class="tack-quote-list-notice" role="status"></p>'
			).prependTo( $page );
		}
		if ( listNotice ) {
			$note.text( listNotice );
		} else {
			$note.remove();
		}
		$( '#tack-quote-page-submit' ).prop( 'disabled', list.length === 0 );

		// `shop_table_responsive` (1.10.0): on a narrow screen WooCommerce's stylesheet
		// stacks each cell under its `data-title`, so every cell carries its column
		// heading, read from the table's own (translated, overridable) header.
		const titles = {};
		$table.find( 'thead th' ).each( function () {
			const col = ( this.className.match(
				/tack-quote-page-col-[a-z]+/
			) || [ '' ] )[ 0 ];
			// The remove column's heading is screen-reader text; WooCommerce's own cart
			// gives that cell no title either.
			if ( col && col !== 'tack-quote-page-col-remove' ) {
				titles[ col ] = $.trim( $( this ).text() );
			}
		} );
		// WooCommerce's own cart-table cell classes beside ours, so the theme's cart
		// styling applies (and the remove cell gets no stacked heading on phones).
		const wcCell = {
			'tack-quote-page-col-product': 'product-name',
			'tack-quote-page-col-qty': 'product-quantity',
			'tack-quote-page-col-price': 'product-price',
			'tack-quote-page-col-remove': 'product-remove',
		};
		function cell( col ) {
			const $td = $(
				'<td class="' +
					( wcCell[ col ] ? wcCell[ col ] + ' ' : '' ) +
					col +
					'"></td>'
			);
			if ( titles[ col ] ) {
				$td.attr( 'data-title', titles[ col ] );
			}
			return $td;
		}

		list.forEach( function ( row ) {
			const $tr = $( '<tr class="tack-quote-page-item"></tr>' );
			const $name = cell( 'tack-quote-page-col-product' ).text(
				row.name
			);
			if ( row.sku ) {
				$name.append(
					$(
						'<small class="tack-quote-page-item-sku"></small>'
					).text( row.sku )
				);
			}
			$tr.append( $name );

			const $qty = $(
				'<input type="number" min="1" step="1" class="input-text qty tack-quote-page-qty" />'
			)
				.attr( 'aria-label', TackQuotes.i18n.quantity )
				.attr( 'max', row.max || null )
				.val( row.quantity );
			$qty.on( 'change', function () {
				setQuantity( row, $qty.val() );
			} );
			$tr.append( cell( 'tack-quote-page-col-qty' ).append( $qty ) );

			let priceText = '';
			if ( row.yourPrice && row.yourPriceQty === row.quantity ) {
				priceText = row.yourPrice;
			} else if ( row.price ) {
				priceText = formatPrice( row.price );
			}
			$tr.append( cell( 'tack-quote-page-col-price' ).text( priceText ) );

			if ( withTarget ) {
				const $target = $(
					'<input type="number" min="0" step="any" class="input-text tack-quote-page-target" />'
				)
					.attr( 'aria-label', TackQuotes.i18n.targetPrice )
					.attr(
						'placeholder',
						TackQuotes.i18n.targetPricePlaceholder || ''
					)
					.val(
						row.targetPrice === null ||
							row.targetPrice === undefined
							? ''
							: row.targetPrice
					);
				$target.on( 'change', function () {
					setTargetPrice( row, $target.val() );
				} );
				$tr.append(
					cell( 'tack-quote-page-col-target' ).append( $target )
				);
			}

			const $remove = $(
				'<button type="button" class="tack-quote-page-remove">&times;</button>'
			).attr( 'aria-label', TackQuotes.i18n.remove );
			$remove.on( 'click', function () {
				removeFromList( row );
			} );
			$tr.append(
				cell( 'tack-quote-page-col-remove' ).append( $remove )
			);
			$body.append( $tr );
		} );
	}

	function escapeHtml( str ) {
		return String( str === null || str === undefined ? '' : str ).replace(
			/[&<>"']/g,
			function ( c ) {
				return {
					'&': '&amp;',
					'<': '&lt;',
					'>': '&gt;',
					'"': '&quot;',
					"'": '&#39;',
				}[ c ];
			}
		);
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
	const SAFE_FIELD_KEY = /^[A-Za-z0-9_]{1,40}$/;

	function isSafeFieldKey( key ) {
		return typeof key === 'string' && SAFE_FIELD_KEY.test( key );
	}

	// `id` and `name` are escaped for the same reason `label` and `placeholder` always were.
	// They used to be interpolated raw, which let a policy field named
	// `x" autofocus onfocus="…` close the attribute and execute — in every shopper's browser,
	// and in any administrator's browser on a page that renders this form.
	function field( id, name, label, opts ) {
		const o = opts || {};
		return (
			'<div class="form-row tack-quote-field' +
			( o.half ? ' tack-quote-field-half' : '' ) +
			'">' +
			'<label for="' +
			escapeHtml( id ) +
			'">' +
			escapeHtml( label ) +
			( o.required
				? ''
				: ' <span class="tack-quote-optional">' +
				  escapeHtml( TackQuotes.i18n.optional ) +
				  '</span>' ) +
			'</label>' +
			'<input type="' +
			escapeHtml( o.type || 'text' ) +
			'" class="input-text" id="' +
			escapeHtml( id ) +
			'" name="' +
			escapeHtml( name ) +
			'"' +
			( o.placeholder
				? ' placeholder="' + escapeHtml( o.placeholder ) + '"'
				: '' ) +
			( o.required ? ' required' : '' ) +
			' />' +
			'</div>'
		);
	}

	function buildIdentityFields() {
		const i18n = TackQuotes.i18n;
		const html =
			'<div class="tack-quote-field-row">' +
			field( 'tack-quote-first-name', 'firstName', i18n.firstNameLabel, {
				required: true,
				half: true,
			} ) +
			field( 'tack-quote-last-name', 'lastName', i18n.lastNameLabel, {
				half: true,
			} ) +
			'</div>' +
			field( 'tack-quote-email', 'email', i18n.emailLabel, {
				type: 'email',
				required: true,
				placeholder: i18n.emailPlaceholder,
			} ) +
			field( 'tack-quote-phone', 'phone', i18n.phoneLabel, {
				type: 'tel',
			} );

		return html + buildBuyingAs();
	}

	// Only offer the individual/company choice when the seller actually allows both.
	// A company_only or buyer_only policy has nothing to choose, and rendering a
	// single-option radio group is noise that implies a choice the seller does not offer.
	function buildBuyingAs() {
		const i18n = TackQuotes.i18n;
		let html = '';
		const r = reg();
		if ( r && r.allowCompany && r.allowIndividual ) {
			html +=
				'<fieldset class="tack-quote-field tack-quote-buying-as">' +
				'<legend>' +
				escapeHtml( i18n.buyingAsLabel ) +
				'</legend>' +
				'<label><input type="radio" name="buyingAs" value="individual" checked /> ' +
				escapeHtml( i18n.buyingAsIndividual ) +
				'</label>' +
				'<label><input type="radio" name="buyingAs" value="company" /> ' +
				escapeHtml( i18n.buyingAsCompany ) +
				'</label>' +
				'</fieldset>';
		}
		return html;
	}

	function companyFieldLabel( key ) {
		const known = TackQuotes.i18n.companyFields || {};
		if ( known[ key ] ) {
			return known[ key ];
		}
		// Humanise an unknown key rather than printing it raw: the seller can add policy fields
		// we have no translation for, and "taxId" is friendlier than nothing but "Tax Id" is
		// friendlier still.
		return key
			.replace( /([A-Z])/g, ' $1' )
			.replace( /[_-]+/g, ' ' )
			.replace( /^./, function ( c ) {
				return c.toUpperCase();
			} )
			.trim();
	}

	// `skipName` (1.12.0): the seller-designed form already asks for the company name
	// (its buyer_company field), so the policy section must not ask twice.
	function buildCompanyFields( skipName ) {
		const r = reg();
		if ( ! r || ! r.allowCompany ) {
			return '';
		}
		const i18n = TackQuotes.i18n;
		// company_only means every shopper is a company, so the section is always shown and
		// always required. When both modes are allowed it starts hidden and the radio reveals it.
		const alwaysCompany = ! r.allowIndividual;
		const required = Array.isArray( r.requiredCompanyFields )
			? r.requiredCompanyFields
			: [];

		let fields = skipName
			? ''
			: field(
					'tack-quote-company-name',
					'companyName',
					i18n.companyNameLabel,
					{ required: true }
			  );

		for ( let i = 0; i < required.length; i++ ) {
			const key = required[ i ];
			// companyName is rendered above; a policy that also lists it must not duplicate it.
			if ( key === 'companyName' || key === 'name' ) {
				continue;
			}
			// Second half of the defence in depth: escaping makes a hostile key inert, the
			// allowlist means it is never rendered at all. A key outside the charset could not have
			// survived the PHP handler's own filter on submit anyway, so dropping it here loses
			// nothing a shopper could have used.
			if ( ! isSafeFieldKey( key ) ) {
				continue;
			}
			fields += field(
				'tack-quote-company-' + key,
				'company[' + key + ']',
				companyFieldLabel( key ),
				{
					required: true,
				}
			);
		}

		// The section always exists (it says whether the request is a company one), but
		// its "Company details" heading only above fields: a seller form that asks the
		// company name itself, under a policy with no required company fields, left a
		// heading over nothing (live QA 2026-10-26).
		return (
			'<div class="tack-quote-company-section"' +
			( alwaysCompany ? '' : ' hidden' ) +
			'>' +
			( fields
				? '<h3 class="tack-quote-company-heading">' +
				  escapeHtml( i18n.companyHeading ) +
				  '</h3>' +
				  fields
				: '' ) +
			'</div>'
		);
	}

	// ─── 1.13.0: the seller's own registration questions ──────────────────────
	//
	// `TackQuotes.registration.customFields` is the policy's list of questions
	// ({key, label, type, required, options, helpText, appliesTo}). TackQuote refuses a
	// request that leaves a REQUIRED one empty (validateRegistrationFields), and before
	// 1.13.0 the plugin never asked them: every quote request to such a seller failed.
	// Each question applies to company requests, individual ones, or both; the ones that
	// do not apply right now are hidden AND disabled (see syncCompanySection), so neither
	// the browser's validation nor the submit reads them.
	//
	// Keys are allowlisted with the server's own rule (registration-policy.ts
	// parseCustomFields: /^[a-zA-Z][a-zA-Z0-9_]{0,48}$/), the PHP handler applies the
	// same, and every value rendered into the markup is escaped.
	const SAFE_QUESTION_KEY = /^[A-Za-z][A-Za-z0-9_]{0,48}$/;
	const QUESTION_TYPES = [
		'text',
		'textarea',
		'number',
		'select',
		'checkbox',
		'date',
	];

	function questions() {
		const r = reg();
		const list = r && Array.isArray( r.customFields ) ? r.customFields : [];
		return list.filter( function ( q ) {
			return (
				q &&
				typeof q.key === 'string' &&
				SAFE_QUESTION_KEY.test( q.key ) &&
				typeof q.label === 'string'
			);
		} );
	}

	// Does a question apply to this request? `both`, or its own kind.
	function questionApplies( appliesTo, isCompany ) {
		if ( appliesTo === 'company' ) {
			return !! isCompany;
		}
		if ( appliesTo === 'buyer' ) {
			return ! isCompany;
		}
		return true;
	}

	function questionField( q ) {
		const i18n = TackQuotes.i18n;
		const type = QUESTION_TYPES.indexOf( q.type ) >= 0 ? q.type : 'text';
		const required = q.required === true;
		const applies =
			q.appliesTo === 'company' || q.appliesTo === 'buyer'
				? q.appliesTo
				: 'both';
		const id = 'tack-quote-q-' + q.key;
		const attrs =
			' id="' +
			escapeHtml( id ) +
			'" name="customFields[' +
			escapeHtml( q.key ) +
			']" data-tack-question="' +
			escapeHtml( q.key ) +
			'" data-tack-question-type="' +
			escapeHtml( type ) +
			'"' +
			( required ? ' required' : '' );
		const optional = required
			? ''
			: ' <span class="tack-quote-optional">' +
			  escapeHtml( i18n.optional ) +
			  '</span>';
		const label =
			'<label for="' +
			escapeHtml( id ) +
			'">' +
			escapeHtml( q.label ) +
			optional +
			'</label>';
		let control;
		if ( type === 'checkbox' ) {
			control =
				'<label class="tack-quote-question-check"><input type="checkbox" value="true"' +
				attrs +
				' /> ' +
				escapeHtml( q.label ) +
				optional +
				'</label>';
		} else if ( type === 'textarea' ) {
			control =
				label +
				'<textarea class="input-text" rows="3"' +
				attrs +
				'></textarea>';
		} else if ( type === 'select' ) {
			const options = Array.isArray( q.options ) ? q.options : [];
			control =
				label +
				'<select' +
				attrs +
				'><option value="">' +
				escapeHtml( i18n.questionChoose ) +
				'</option>' +
				options
					.map( function ( o ) {
						return (
							'<option value="' +
							escapeHtml( String( o ) ) +
							'">' +
							escapeHtml( String( o ) ) +
							'</option>'
						);
					} )
					.join( '' ) +
				'</select>';
		} else {
			control =
				label +
				'<input type="' +
				escapeHtml( type ) +
				'" class="input-text"' +
				attrs +
				' />';
		}
		const help =
			typeof q.helpText === 'string' && q.helpText
				? '<small class="tack-quote-question-help">' +
				  escapeHtml( q.helpText ) +
				  '</small>'
				: '';
		return (
			'<div class="form-row tack-quote-field tack-quote-question" data-tack-applies="' +
			escapeHtml( applies ) +
			'">' +
			control +
			help +
			'</div>'
		);
	}

	function buildQuestions() {
		const list = questions();
		if ( ! list.length ) {
			return '';
		}
		return (
			'<div class="tack-quote-questions">' +
			list.map( questionField ).join( '' ) +
			'</div>'
		);
	}

	// The answers to send, from [{key, type, required, label, value, checked}] (enabled
	// questions only). A checkbox is 'true' or ''. `missing` names the first required
	// question left empty, for the inline message. Pure, so Node can test it.
	function questionAnswers( entries ) {
		const values = {};
		let missing = '';
		entries.forEach( function ( e ) {
			let v;
			if ( e.type === 'checkbox' ) {
				v = e.checked ? 'true' : '';
			} else {
				v = String(
					e.value === null || e.value === undefined ? '' : e.value
				).trim();
			}
			if ( e.required && ! v && ! missing ) {
				missing = e.label;
			}
			values[ e.key ] = v;
		} );
		return { values, missing };
	}

	function questionEntries( $overlay ) {
		const entries = [];
		$overlay.find( '[data-tack-question]:enabled' ).each( function () {
			const $field = $( this ).closest( '.tack-quote-question' );
			entries.push( {
				key: this.getAttribute( 'data-tack-question' ),
				type: this.getAttribute( 'data-tack-question-type' ),
				required: !! this.required,
				label: ( $field.find( 'label' ).first().text() || '' ).trim(),
				value: this.value,
				checked: !! this.checked,
			} );
		} );
		return questionAnswers( entries );
	}

	// ─── 1.10.0: attachments ───────────────────────────────────────────────────
	//
	// Rendered only when the server-side config says so (the merchant's switch AND
	// the TackQuote server's `attachments` capability). The files go to this store's
	// own `tack_quote_upload` handler, which validates them again and streams them to
	// TackQuote; only the opaque upload ids (and a guest's token) come back here and
	// travel with the quote request.
	function filesConfig() {
		const cfg = TackQuotes.attachments;
		return cfg && cfg.action ? cfg : null;
	}

	function buildFilesField() {
		const cfg = filesConfig();
		if ( ! cfg ) {
			return '';
		}
		const i18n = TackQuotes.i18n;
		return (
			'<div class="form-row tack-quote-field tack-quote-files">' +
			'<label for="tack-quote-files">' +
			escapeHtml( i18n.filesLabel ) +
			'</label>' +
			'<input type="file" id="tack-quote-files" name="files[]" multiple accept="' +
			escapeHtml( cfg.accept || '' ) +
			'" aria-describedby="tack-quote-files-help" />' +
			'<small id="tack-quote-files-help" class="tack-quote-files-help">' +
			escapeHtml( sprintf( i18n.filesHelp, cfg.maxFiles, cfg.maxMb ) ) +
			'</small>' +
			'</div>'
		);
	}

	const ACCEPTED_FILE = /\.(pdf|jpe?g|png)$/i;

	// The same limits the server enforces, checked first so the shopper gets an
	// inline message instead of a round trip. The server stays the authority.
	function checkFiles( files, cfg ) {
		const i18n = TackQuotes.i18n;
		if ( files.length > cfg.maxFiles ) {
			return sprintf( i18n.filesMax, cfg.maxFiles );
		}
		for ( let i = 0; i < files.length; i++ ) {
			if ( ! ACCEPTED_FILE.test( files[ i ].name || '' ) ) {
				return sprintf( i18n.fileType, files[ i ].name );
			}
			if ( files[ i ].size > cfg.maxMb * 1024 * 1024 ) {
				return sprintf( i18n.fileSize, files[ i ].name, cfg.maxMb );
			}
		}
		return '';
	}

	// POST the chosen files; resolves with {uploadIds, uploadToken?}. Retries ONCE
	// with a fresh nonce when the page's nonce has gone stale (cached HTML).
	function uploadFiles( files, cfg ) {
		const deferred = $.Deferred();
		function attempt( allowRetry ) {
			const body = new window.FormData();
			body.append( 'action', cfg.action );
			body.append( 'nonce', TackQuotes.nonce );
			for ( let i = 0; i < files.length; i++ ) {
				body.append( 'files[]', files[ i ] );
			}
			$.ajax( {
				url: TackQuotes.ajaxUrl,
				type: 'POST',
				data: body,
				processData: false,
				contentType: false,
			} )
				.done( function ( res ) {
					if (
						res &&
						res.success &&
						res.data &&
						Array.isArray( res.data.uploadIds )
					) {
						deferred.resolve( res.data );
					} else {
						deferred.reject( ( res && res.data ) || null );
					}
				} )
				.fail( function ( xhr ) {
					const data =
						( xhr && xhr.responseJSON && xhr.responseJSON.data ) ||
						null;
					if (
						allowRetry &&
						data &&
						data.code === 'tack_nonce_expired' &&
						TackQuotes.nonceUrl
					) {
						$.get( TackQuotes.nonceUrl )
							.done( function ( fresh ) {
								const refreshed =
									fresh && fresh.data && fresh.data.nonce;
								if ( ! refreshed ) {
									deferred.reject( data );
									return;
								}
								TackQuotes.nonce = refreshed;
								attempt( false );
							} )
							.fail( function () {
								deferred.reject( data );
							} );
						return;
					}
					deferred.reject( data );
				} );
		}
		attempt( true );
		return deferred.promise();
	}

	// ─── 1.12.0: the seller-designed quote request form ─────────────────────────
	//
	// When TackQuote serves a form the seller designed (`TackQuotes.quoteForm`, set by the
	// PHP only when the server lists `quote-form`), its fields replace the built-in ones.
	// Null config, or no template on the page, keeps the built-in form.
	function sellerForm() {
		const cfg = TackQuotes.quoteForm;
		if ( ! cfg || ! cfg.templateId || ! cfg.versionId ) {
			return null;
		}
		const tpl = document.getElementById( cfg.templateId );
		return tpl && tpl.content ? { cfg, tpl } : null;
	}

	// The answer input of a role (`buyer_email` …), or an empty set.
	function roleInput( $overlay, role ) {
		const cfg = TackQuotes.quoteForm || {};
		const key = cfg.roles && cfg.roles[ role ];
		// The key comes from the TackQuote server: only TackQuote's own field-key shape is
		// put into a selector.
		if (
			typeof key !== 'string' ||
			! /^[A-Za-z][A-Za-z0-9_-]{0,63}$/.test( key )
		) {
			return $();
		}
		return $overlay.find( '[name="tq_answers[' + key + ']"]' );
	}

	function buildModal() {
		const i18n = TackQuotes.i18n;
		const seller = sellerForm();

		const overlay = document.createElement( 'div' );
		overlay.className = 'tack-quote-modal-overlay';
		overlay.setAttribute( 'hidden', 'hidden' );

		overlay.innerHTML =
			'<div class="tack-quote-modal woocommerce" role="dialog" aria-modal="true" aria-labelledby="tack-quote-modal-title">' +
			'<button type="button" class="tack-quote-modal-close" aria-label="' +
			escapeHtml( i18n.close ) +
			'">&times;</button>' +
			'<h2 id="tack-quote-modal-title">' +
			escapeHtml( i18n.modalTitle ) +
			'</h2>' +
			'<form class="tack-quote-modal-form" novalidate>' +
			( seller
				? '<div class="tack-quote-seller-fields"></div>' +
				  sellerPolicyFields( seller.cfg )
				: buildBuiltInFields() ) +
			'<p class="tack-quote-modal-error" hidden></p>' +
			'<div class="tack-quote-modal-actions">' +
			'<button type="button" class="' +
			buttonClass( 'tack-quote-modal-cancel' ) +
			'">' +
			escapeHtml( i18n.cancel ) +
			'</button>' +
			'<button type="submit" class="' +
			buttonClass( 'alt tack-quote-modal-submit' ) +
			'">' +
			escapeHtml( i18n.submit ) +
			'</button>' +
			'</div>' +
			'</form>' +
			// OUTSIDE the form, on purpose: the success branch hides the
			// form, and a message inside it was hidden with it, so a guest saw an empty dialog
			// and sent the request again. Focusable (tabindex -1) so it can take focus for
			// screen readers when it appears; role="status" announces it as well.
			'<p class="tack-quote-modal-success" role="status" tabindex="-1" hidden></p>' +
			'</div>';

		document.body.appendChild( overlay );
		if ( seller ) {
			// The seller-designed form: the fields the PHP rendered into a <template>, so they
			// carry the same markup, escaping, conditions and calendar as the application forms.
			const holder = overlay.querySelector( '.tack-quote-seller-fields' );
			holder.appendChild(
				document.importNode( seller.tpl.content, true )
			);
			const formEl = overlay.querySelector( 'form' );
			formEl.setAttribute(
				'data-tack-seller-form',
				seller.cfg.versionId
			);
			if ( window.TackQuoteForms && window.TackQuoteForms.bind ) {
				window.TackQuoteForms.bind( formEl );
			}
		}
		return overlay;
	}

	// The seller's registration policy still decides whether a company is needed
	// (company_only, or the company details it requires), and TackQuote refuses a
	// request without them, so they follow the designed fields. A form with
	// a buyer_company field already asks for the company name: not twice.
	function sellerPolicyFields( cfg ) {
		return (
			buildBuyingAs() +
			buildCompanyFields(
				!! ( cfg && cfg.roles && cfg.roles.buyer_company )
			) +
			buildQuestions()
		);
	}

	// The built-in form: name, email, phone, the seller's company policy, a note, files.
	function buildBuiltInFields() {
		const i18n = TackQuotes.i18n;
		return (
			buildIdentityFields() +
			buildCompanyFields() +
			buildQuestions() +
			'<div class="form-row tack-quote-field">' +
			'<label for="tack-quote-note">' +
			escapeHtml( i18n.noteLabel ) +
			' <span class="tack-quote-optional">' +
			escapeHtml( i18n.optional ) +
			'</span></label>' +
			'<textarea id="tack-quote-note" class="input-text" name="note" rows="3" placeholder="' +
			escapeHtml( i18n.notePlaceholder ) +
			'"></textarea>' +
			'</div>' +
			buildFilesField()
		);
	}

	function openModal( context ) {
		if ( ! modal ) {
			modal = buildModal();
		}

		const $overlay = $( modal );
		const $form = $overlay.find( 'form' );
		const $email = $overlay.find( '#tack-quote-email' );
		const $note = $overlay.find( '#tack-quote-note' );
		const $error = $overlay.find( '.tack-quote-modal-error' );
		const $success = $overlay.find( '.tack-quote-modal-success' );
		const $submit = $overlay.find( '.tack-quote-modal-submit' );

		$form[ 0 ].reset();
		// Files uploaded for an earlier request were claimed by it (or will expire).
		const filesInput = $overlay.find( '#tack-quote-files' )[ 0 ];
		if ( filesInput ) {
			filesInput.tackUploaded = null;
		}
		$email.val( TackQuotes.customerEmail || '' );
		// A signed-in customer's name; '' for guests.
		const $firstName = $overlay.find( '#tack-quote-first-name' );
		$firstName.val( TackQuotes.customerFirstName || '' );
		$overlay
			.find( '#tack-quote-last-name' )
			.val( TackQuotes.customerLastName || '' );
		// The quote page's message, when the request comes from there (1.10.0).
		$note.val( context.message || '' );
		$error.hide().text( '' );
		$success.hide().text( '' );
		$submit
			.off( 'click.tackReload' )
			.prop( 'disabled', false )
			.text( TackQuotes.i18n.submit );
		$form.show();

		const seller = !! $form.attr( 'data-tack-seller-form' );
		if ( seller ) {
			// Files uploaded for an earlier request were claimed by it (or will expire).
			$form.find( 'input[type="file"]' ).each( function () {
				this.tackUploaded = null;
			} );
			// A signed-in customer's email and name; '' for guests. The quote page's message.
			roleInput( $overlay, 'buyer_email' ).val(
				TackQuotes.customerEmail || ''
			);
			roleInput( $overlay, 'buyer_name' ).val(
				[
					TackQuotes.customerFirstName || '',
					TackQuotes.customerLastName || '',
				]
					.join( ' ' )
					.trim()
			);
			if ( context.message ) {
				roleInput( $overlay, 'buyer_message' ).val( context.message );
			}
			// Conditions follow the reset values.
			if ( window.TackQuoteForms && window.TackQuoteForms.apply ) {
				window.TackQuoteForms.apply( $form[ 0 ] );
			}
		}

		// Before the focus pick below: the company section must already be in the state
		// the reset radio says.
		syncCompanySection( $overlay );

		modal.removeAttribute( 'hidden' );
		document.body.classList.add( 'tack-quote-modal-open' );
		// Focus the first required field still empty, else the submit button.
		let $emptyRequired = $();
		if ( seller ) {
			$emptyRequired = $form
				.find( 'input, select, textarea' )
				.filter( function () {
					return (
						this.required &&
						! this.disabled &&
						! this.value &&
						! $( this ).closest( '[hidden]' ).length
					);
				} )
				.first();
		} else if ( ! $firstName.val() ) {
			$emptyRequired = $firstName;
		} else if ( ! $email.val() ) {
			$emptyRequired = $email;
		}
		( $emptyRequired.length
			? $emptyRequired
			: $overlay.find( '.tack-quote-modal-submit' )
		).trigger( 'focus' );

		// Company section follows the individual/company choice. Only present when the seller
		// allows both; a company_only policy renders it always-visible with no radio to drive it.
		$overlay
			.off( 'change.tackBuyingAs input.tackBuyingAs' )
			.on( 'change.tackBuyingAs', 'input[name="buyingAs"]', function () {
				syncCompanySection( $overlay );
			} );
		// A seller-designed form's company answer also makes the request a company one.
		const $companyRole = roleInput( $overlay, 'buyer_company' );
		if ( $companyRole.length ) {
			$overlay.on(
				'change.tackBuyingAs input.tackBuyingAs',
				'[name="' + $companyRole.attr( 'name' ) + '"]',
				function () {
					syncCompanySection( $overlay );
				}
			);
		}
		// The reset above put the radio back to its default: the section follows it NOW,
		// not only on the next change (a hidden, still-enabled required input
		// blocked an individual shopper's first submit).
		syncCompanySection( $overlay );

		$form.off( 'submit' ).on( 'submit', function ( e ) {
			e.preventDefault();
			submitRequest( context, $overlay );
		} );
	}

	function closeModal() {
		if ( ! modal ) {
			return;
		}
		modal.setAttribute( 'hidden', 'hidden' );
		document.body.classList.remove( 'tack-quote-modal-open' );
	}

	/**
	 * The modal's confirmation after a successful request. The dialog stays open on
	 * this message: the page never navigates on its own. TackQuote's `portalUrl` can
	 * lead to a sign-in page the shopper has no account for yet, so the buyer portal
	 * is offered only as a link they may choose to follow.
	 *
	 * @param {jQuery} $success The status paragraph (`.tack-quote-modal-success`).
	 * @param {Object} data     The `wp_send_json_success()` payload.
	 */
	function showSubmitted( $success, data ) {
		const d = data || {};
		// When a company account may still need approval, say so instead of implying
		// the portal is ready to use.
		$success
			.text(
				d.awaitingApproval
					? TackQuotes.i18n.awaitingApproval
					: TackQuotes.i18n.success
			)
			.show();
		const portalUrl = typeof d.portalUrl === 'string' ? d.portalUrl : '';
		if ( /^https?:\/\//i.test( portalUrl ) ) {
			$success.append(
				' ',
				$( '<a class="tack-quote-portal-link"></a>' )
					.attr( 'href', portalUrl )
					.text( TackQuotes.i18n.portalLink || portalUrl )
			);
		}
	}

	// What the request is for: the quote list (items) or one product (with its scope's
	// quantity and chosen variation). Shared by the built-in and the seller-designed form.
	function contextFields( context ) {
		const out = {};
		if ( context.items ) {
			// "Checkout as Quote" from the quote-list drawer — the server
			// re-derives name/SKU/price from each product_id; quantity is the
			// only other value trusted from the client.
			out.items = JSON.stringify(
				context.items.map( function ( row ) {
					const line = {
						product_id: row.productId,
						variation_id: row.variationId || 0,
						quantity: row.quantity,
					};
					// The variation's "Any …" choices (1.12.0); the server keeps only the
					// ones the variation leaves open and its parent offers.
					if (
						window.TackWithOptions.attributesKey( row.attributes )
					) {
						line.attributes = row.attributes;
					}
					if (
						typeof row.targetPrice === 'number' &&
						isFinite( row.targetPrice ) &&
						row.targetPrice >= 0
					) {
						line.target_price = row.targetPrice;
					}
					return line;
				} )
			);
			return out;
		}
		// "Request a Quote" — a single product, submitted immediately.
		const $scope =
			context.$scope && context.$scope.length ? context.$scope : $();
		out.product_id = context.productId || 0;
		// `context.quantity` / `context.variationId` are set only for the Add to Cart with
		// Options block (resolved by tack-with-options.js); every other form is read here.
		out.quantity =
			context.quantity || $scope.find( 'input.qty' ).val() || 1;
		// On a variable product the button can only carry the PARENT id, so the shopper's
		// chosen variation has to be read from WooCommerce's own variation form, which keeps
		// the selected id in a hidden input[name="variation_id"] (0 when nothing is chosen
		// yet). Without this a quote for "X-Large" was recorded against the parent — wrong
		// SKU, and the parent's cheapest price. The server re-validates that this variation
		// really belongs to product_id before using it.
		const variationId =
			context.variationId !== undefined
				? context.variationId
				: Number( $scope.find( 'input[name="variation_id"]' ).val() ) ||
				  0;
		if ( variationId ) {
			out.variation_id = variationId;
			const attributes = context.attributes || scopeAttributes( $scope );
			if ( window.TackWithOptions.attributesKey( attributes ) ) {
				out.attributes = JSON.stringify( attributes );
			}
		}
		return out;
	}

	// POSTs a quote request (an object, or name/value pairs) and reports the outcome in
	// the modal; refreshes the nonce and retries ONCE when the page's nonce went stale.
	function makeSender( context, $overlay ) {
		const $error = $overlay.find( '.tack-quote-modal-error' );
		const $success = $overlay.find( '.tack-quote-modal-success' );
		const $submit = $overlay.find( '.tack-quote-modal-submit' );
		const $form = $overlay.find( 'form' );

		function setNonce( body, nonce ) {
			if ( Array.isArray( body ) ) {
				body.forEach( function ( pair ) {
					if ( pair.name === 'nonce' ) {
						pair.value = nonce;
					}
				} );
			} else {
				body.nonce = nonce;
			}
		}

		function showFailure( data ) {
			$error
				.text( ( data && data.message ) || TackQuotes.i18n.error )
				.show();
			if ( data && data.reload ) {
				$submit
					.prop( 'disabled', false )
					.text( TackQuotes.i18n.reload )
					.off( 'click.tackReload' )
					.on( 'click.tackReload', function ( ev ) {
						ev.preventDefault();
						window.location.reload();
					} );
				return;
			}
			$submit.prop( 'disabled', false ).text( TackQuotes.i18n.submit );
		}

		function send( body, allowRetry ) {
			$.post(
				TackQuotes.ajaxUrl,
				Array.isArray( body ) ? $.param( body ) : body
			)
				.done( function ( res ) {
					if ( res && res.success ) {
						$form.hide();
						showSubmitted( $success, res.data );
						// Only a request FOR the quote list empties it; a grouped product's
						// "Request a Quote" also sends rows, and leaves the list alone.
						if ( context.fromList ) {
							clearList();
						}
						$success.trigger( 'focus' );
					} else {
						showFailure( ( res && res.data ) || null );
					}
				} )
				.fail( function ( xhr ) {
					const data =
						( xhr && xhr.responseJSON && xhr.responseJSON.data ) ||
						null;
					if (
						allowRetry &&
						data &&
						'tack_nonce_expired' === data.code &&
						TackQuotes.nonceUrl
					) {
						$.get( TackQuotes.nonceUrl )
							.done( function ( fresh ) {
								const refreshed =
									fresh && fresh.data && fresh.data.nonce;
								if ( ! refreshed ) {
									showFailure( data );
									return;
								}
								TackQuotes.nonce = refreshed;
								setNonce( body, refreshed );
								send( body, false );
							} )
							.fail( function () {
								showFailure( data );
							} );
						return;
					}
					showFailure( data );
				} );
		}

		return { send, showFailure };
	}

	// The seller-designed form: the browser checks what it can (required, types, the
	// seller's earliest date), the files go up first, then the answers travel as
	// tq_answers[...] with the version the shopper saw. The server checks them again.
	function submitSellerForm( context, $overlay ) {
		const $error = $overlay.find( '.tack-quote-modal-error' );
		const $form = $overlay.find( 'form' );
		const form = $form[ 0 ];
		$error.hide().text( '' );

		if (
			typeof form.checkValidity === 'function' &&
			! form.checkValidity()
		) {
			if ( typeof form.reportValidity === 'function' ) {
				form.reportValidity();
			}
			return;
		}

		const companyState = companyAnswers( $overlay );
		if ( companyState.missing ) {
			$error.text( TackQuotes.i18n.companyRequired ).show();
			return;
		}
		const answers = questionEntries( $overlay );
		if ( answers.missing ) {
			$error
				.text(
					sprintf( TackQuotes.i18n.questionRequired, answers.missing )
				)
				.show();
			return;
		}

		const pairs = [
			{ name: 'action', value: 'tack_request_quote' },
			{ name: 'nonce', value: TackQuotes.nonce },
			{ name: 'tq_version', value: TackQuotes.quoteForm.versionId },
			{ name: 'tq_locale', value: TackQuotes.quoteForm.locale || '' },
		];
		if ( companyState.isCompany ) {
			if ( companyState.name ) {
				pairs.push( {
					name: 'company_name',
					value: companyState.name,
				} );
			}
			Object.keys( companyState.company ).forEach( function ( k ) {
				pairs.push( {
					name: 'company[' + k + ']',
					value: companyState.company[ k ],
				} );
			} );
		}
		Object.keys( answers.values ).forEach( function ( k ) {
			pairs.push( {
				name: 'customFields[' + k + ']',
				value: answers.values[ k ],
			} );
		} );
		$form.serializeArray().forEach( function ( pair ) {
			if ( pair.name.indexOf( 'tq_answers[' ) === 0 ) {
				pairs.push( pair );
			}
		} );
		const extra = contextFields( context );
		Object.keys( extra ).forEach( function ( k ) {
			pairs.push( { name: k, value: extra[ k ] } );
		} );

		// Files of every shown file field, in field order; one upload call (one guest token).
		const slots = [];
		let files = [];
		$form.find( 'input[type="file"]' ).each( function () {
			if (
				$( this ).closest( '[hidden]' ).length ||
				! this.files ||
				! this.files.length
			) {
				return;
			}
			const m = String( this.name || '' ).match( /^tq_files\[(.+)\]$/ );
			if ( ! m ) {
				return;
			}
			const mine = Array.prototype.slice.call( this.files );
			const label =
				$( this )
					.closest( '[data-tack-field]' )
					.find( 'label' )
					.first()
					.text()
					.replace( /\s*\*\s*$/, '' )
					.trim() || m[ 1 ];
			slots.push( {
				key: m[ 1 ],
				first: files.length,
				count: mine.length,
				max: Number( this.getAttribute( 'data-tack-max-files' ) ) || 1,
				maxMb: Number( this.getAttribute( 'data-tack-max-mb' ) ) || 0,
				label,
			} );
			files = files.concat( mine );
		} );

		// Each field's own cap first, naming the field, then the request's total:
		// a refusal after the upload would leave orphaned files and a confusing message.
		for ( let si = 0; si < slots.length; si++ ) {
			const slot = slots[ si ];
			if ( slot.count > slot.max ) {
				$error
					.text(
						sprintf(
							TackQuotes.i18n.fieldFilesMax,
							slot.label,
							slot.max
						)
					)
					.show();
				return;
			}
			for ( let fi = slot.first; fi < slot.first + slot.count; fi++ ) {
				if (
					slot.maxMb &&
					files[ fi ].size > slot.maxMb * 1024 * 1024
				) {
					$error
						.text(
							sprintf(
								TackQuotes.i18n.fileSize,
								files[ fi ].name,
								slot.maxMb
							)
						)
						.show();
					return;
				}
			}
		}

		const $submit = $overlay.find( '.tack-quote-modal-submit' );
		const sender = makeSender( context, $overlay );
		$submit.prop( 'disabled', true ).text( TackQuotes.i18n.sending );
		if ( ! files.length ) {
			sender.send( pairs, true );
			return;
		}
		const cfg = filesConfig();
		if ( ! cfg ) {
			sender.showFailure( { message: TackQuotes.i18n.fileFailed } );
			return;
		}
		const problem = checkFiles( files, cfg );
		if ( problem ) {
			$error.text( problem ).show();
			$submit.prop( 'disabled', false ).text( TackQuotes.i18n.submit );
			return;
		}
		const withUploads = function ( uploaded ) {
			$submit.text( TackQuotes.i18n.sending );
			const ids = uploaded.uploadIds || [];
			// Each id goes to the field its file came from, by the position the upload
			// handler echoes (`files[].index`), never by counting.
			const owner = {};
			slots.forEach( function ( slot ) {
				for ( let i = slot.first; i < slot.first + slot.count; i++ ) {
					owner[ i ] = slot.key;
				}
			} );
			( uploaded.files || [] ).forEach( function ( f ) {
				if (
					f &&
					typeof f.uploadId === 'string' &&
					Object.prototype.hasOwnProperty.call( owner, f.index )
				) {
					pairs.push( {
						name: 'tq_files[' + owner[ f.index ] + '][]',
						value: f.uploadId,
					} );
				}
			} );
			ids.forEach( function ( id ) {
				pairs.push( { name: 'upload_ids[]', value: id } );
			} );
			if ( uploaded.uploadToken ) {
				pairs.push( {
					name: 'upload_token',
					value: uploaded.uploadToken,
				} );
			}
			sender.send( pairs, true );
		};
		// A retry after a failed request reuses the files already uploaded.
		if ( form.tackUploaded ) {
			withUploads( form.tackUploaded );
			return;
		}
		$submit.text(
			sprintf(
				TackQuotes.i18n.uploading,
				files
					.map( function ( f ) {
						return f.name;
					} )
					.join( ', ' )
			)
		);
		uploadFiles( files, cfg )
			.done( function ( uploaded ) {
				form.tackUploaded = uploaded;
				$form.find( 'input[type="file"]' ).one( 'change', function () {
					form.tackUploaded = null;
				} );
				withUploads( uploaded );
			} )
			.fail( function ( data ) {
				sender.showFailure(
					data && data.message
						? data
						: {
								message: TackQuotes.i18n.fileFailed,
								reload: data && data.reload,
						  }
				);
			} );
	}

	// The registration policy's company section: whether the shopper is buying as a
	// company, the values, and whether a required one is empty.
	// Is the request a company one? Pure, so it can be tested without a DOM.
	//   no company section        -> no (the policy takes individuals only)
	//   section, no radio         -> yes (company_only)
	//   radio                     -> the radio, OR a non-empty company answer on a
	//                                seller-designed form (the shopper named a company)
	function companyChosen( state ) {
		if ( ! state.hasSection ) {
			return false;
		}
		if ( ! state.hasRadio ) {
			return true;
		}
		return (
			state.radio === 'company' ||
			!! ( state.formCompany && String( state.formCompany ).trim() )
		);
	}

	function companyStateOf( $overlay ) {
		return {
			hasSection:
				$overlay.find( '.tack-quote-company-section' ).length > 0,
			hasRadio: $overlay.find( 'input[name="buyingAs"]' ).length > 0,
			radio: $overlay.find( 'input[name="buyingAs"]:checked' ).val(),
			formCompany: roleInput( $overlay, 'buyer_company' ).val(),
		};
	}

	// The company section follows companyChosen(): hidden AND disabled when not chosen,
	// so neither the browser's validation nor the submit reads its inputs. On a seller
	// form, its optional company field is required while the request is a company one
	// (company_only, or the radio says company), since the policy asks for a name.
	function syncCompanySection( $overlay ) {
		const isCompany = companyChosen( companyStateOf( $overlay ) );
		const $section = $overlay.find( '.tack-quote-company-section' );
		$section.prop( 'hidden', ! isCompany );
		$section.find( 'input' ).prop( 'disabled', ! isCompany );
		// 1.13.0: the seller's questions follow the same choice.
		$overlay.find( '.tack-quote-question' ).each( function () {
			const show = questionApplies(
				this.getAttribute( 'data-tack-applies' ),
				isCompany
			);
			$( this ).prop( 'hidden', ! show );
			$( this ).find( '[data-tack-question]' ).prop( 'disabled', ! show );
		} );
		const $role = roleInput( $overlay, 'buyer_company' );
		if ( $role.length ) {
			if ( $role.attr( 'data-tack-required' ) === undefined ) {
				$role.attr(
					'data-tack-required',
					$role.prop( 'required' ) ? '1' : '0'
				);
			}
			const required =
				$role.attr( 'data-tack-required' ) === '1' || isCompany;
			// The label's "(optional)" or "*" and aria-required follow (live QA 2026-10-26).
			$role.each( function () {
				window.TackWithOptions.markRequired(
					this,
					this.id
						? $overlay.find( 'label[for="' + this.id + '"]' )[ 0 ]
						: null,
					required,
					TackQuotes.i18n.optional
				);
			} );
		}
		return isCompany;
	}

	function companyAnswers( $overlay ) {
		const isCompany = companyChosen( companyStateOf( $overlay ) );
		const company = {};
		let missing = false;
		if ( isCompany ) {
			$overlay
				.find( '.tack-quote-company-section input:enabled' )
				.each( function () {
					const v = ( this.value || '' ).trim();
					if ( this.required && ! v ) {
						missing = true;
					}
					const m = this.name.match( /^company\[(.+)\]$/ );
					if ( m ) {
						company[ m[ 1 ] ] = v;
					}
				} );
		}
		return {
			isCompany,
			company,
			missing,
			name: (
				$overlay.find( '#tack-quote-company-name' ).val() || ''
			).trim(),
		};
	}

	function submitRequest( context, $overlay ) {
		if ( $overlay.find( 'form' ).attr( 'data-tack-seller-form' ) ) {
			submitSellerForm( context, $overlay );
			return;
		}
		const $error = $overlay.find( '.tack-quote-modal-error' );
		const val = function ( sel ) {
			return ( $overlay.find( sel ).val() || '' ).trim();
		};

		const email = val( '#tack-quote-email' );

		$error.hide().text( '' );

		const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
		if ( ! email || ! emailPattern.test( email ) ) {
			$error.text( TackQuotes.i18n.emailRequired ).show();
			return;
		}
		// Checked client-side purely so the shopper gets an inline message instead of a round
		// trip; the server is still the authority and rejects it independently.
		const firstName = val( '#tack-quote-first-name' );
		if ( ! firstName ) {
			$error.text( TackQuotes.i18n.firstNameRequired ).show();
			return;
		}

		const isCompany =
			$overlay.find( 'input[name="buyingAs"]:checked' ).val() ===
				'company' ||
			( $overlay.find( '.tack-quote-company-section' ).length > 0 &&
				! $overlay.find( 'input[name="buyingAs"]' ).length );

		const company = {};
		let companyMissing = false;
		if ( isCompany ) {
			$overlay
				.find( '.tack-quote-company-section input' )
				.each( function () {
					const name = this.name;
					const v = ( this.value || '' ).trim();
					if ( this.required && ! v ) {
						companyMissing = true;
					}
					const m = name.match( /^company\[(.+)\]$/ );
					if ( m ) {
						company[ m[ 1 ] ] = v;
					}
				} );
			if ( companyMissing ) {
				$error.text( TackQuotes.i18n.companyRequired ).show();
				return;
			}
		}

		const answers = questionEntries( $overlay );
		if ( answers.missing ) {
			$error
				.text(
					sprintf( TackQuotes.i18n.questionRequired, answers.missing )
				)
				.show();
			return;
		}

		const note = val( '#tack-quote-note' );
		const payload = {
			action: 'tack_request_quote',
			nonce: TackQuotes.nonce,
			email,
			note,
			first_name: firstName,
			last_name: val( '#tack-quote-last-name' ),
			phone: val( '#tack-quote-phone' ),
		};

		if ( isCompany ) {
			payload.company_name = val( '#tack-quote-company-name' );
			// Sent as a nested object under `company[...]`; jQuery serialises this into
			// company[taxId]=... which PHP parses back into an array, matching the API's
			// CompanyDetailsInput shape without any manual encoding.
			payload.company = company;
		}

		if ( Object.keys( answers.values ).length ) {
			// Serialised by jQuery as customFields[key]=value, an array in PHP.
			payload.customFields = answers.values;
		}

		$.extend( payload, contextFields( context ) );

		const $submit = $overlay.find( '.tack-quote-modal-submit' );
		$submit.prop( 'disabled', true ).text( TackQuotes.i18n.sending );

		// Read by the hoisted send() and showFailure() below, which run before any return.
		// eslint-disable-next-line @wordpress/no-unused-vars-before-return -- used through those closures; moving it below the first return would read it before initialisation.
		const sender = makeSender( context, $overlay );
		const cfg = filesConfig();
		const input = cfg ? $overlay.find( '#tack-quote-files' )[ 0 ] : null;
		const files =
			input && input.files
				? Array.prototype.slice.call( input.files )
				: [];
		if ( ! files.length ) {
			send( payload, true );
			return;
		}
		const problem = checkFiles( files, cfg );
		if ( problem ) {
			$error.text( problem ).show();
			$submit.prop( 'disabled', false ).text( TackQuotes.i18n.submit );
			return;
		}
		// A retry after a failed request reuses the files already uploaded, rather
		// than sending the same bytes again.
		if ( input.tackUploaded ) {
			withUploads( input.tackUploaded );
			return;
		}
		$submit.text(
			sprintf(
				TackQuotes.i18n.uploading,
				files
					.map( function ( f ) {
						return f.name;
					} )
					.join( ', ' )
			)
		);
		uploadFiles( files, cfg )
			.done( function ( uploaded ) {
				input.tackUploaded = uploaded;
				$( input ).one( 'change', function () {
					input.tackUploaded = null;
				} );
				withUploads( uploaded );
			} )
			.fail( function ( data ) {
				showFailure(
					data && data.message
						? data
						: {
								message: TackQuotes.i18n.fileFailed,
								reload: data && data.reload,
						  }
				);
			} );

		function withUploads( uploaded ) {
			$submit.text( TackQuotes.i18n.sending );
			payload.upload_ids = uploaded.uploadIds;
			if ( uploaded.uploadToken ) {
				payload.upload_token = uploaded.uploadToken;
			}
			send( payload, true );
		}

		// The nonce is printed into the page, so on a full-page-cached store it is baked
		// into cached HTML and stops verifying once it ages past the nonce lifetime: the
		// sender fetches a fresh one and resubmits ONCE (see makeSender()).
		function send( body, allowRetry ) {
			sender.send( body, allowRetry );
		}

		function showFailure( data ) {
			sender.showFailure( data );
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
	function scopeFor( $btn ) {
		const $scoped = $btn.closest( 'form.cart, form.variations_form' );
		if ( $scoped.length ) {
			return $scoped;
		}
		const $form = $btn.closest( 'form' );
		const $box = $btn.closest(
			'.tack-quote-buttons[data-tack-scope="add-to-cart-with-options"]'
		);
		return ! $form.length && $box.length
			? withOptionsFormFor( $box )
			: $form;
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
	const WITH_OPTIONS_FORM = 'form.wc-block-add-to-cart-with-options';

	function withOptionsFormFor( $box ) {
		const id = String(
			$box
				.find( '[data-product-id]' )
				.first()
				.attr( 'data-product-id' ) || ''
		);
		if ( ! id ) {
			return $();
		}
		return $( WITH_OPTIONS_FORM )
			.filter( function () {
				return (
					$( this )
						.find(
							'input[name="add-to-cart"], input[name="product_id"]'
						)
						.filter( function () {
							return String( this.value ) === id;
						} ).length > 0
				);
			} )
			.first();
	}

	// The quantity is the block's own input; a product the block treats as not
	// purchasable (quote only) has none, and the plugin prints one beside the buttons.
	function withOptionsState( $box ) {
		const api = window.TackWithOptions;
		if ( ! api ) {
			return { quotable: false, variationId: 0, label: '', quantity: 1 };
		}
		const $form = withOptionsFormFor( $box );
		let $qty = $form.find( 'input.qty' );
		if ( ! $qty.length ) {
			$qty = $box.find( 'input.qty' );
		}
		return api.resolve(
			api.parseStates( $box.attr( 'data-tack-variations' ) ),
			$form.find( 'input[name="variation_id"]' ).val(),
			$qty.val()
		);
	}

	function withOptionsBox( $btn ) {
		return $btn.closest(
			'.tack-quote-buttons[data-tack-scope="add-to-cart-with-options"]'
		);
	}

	// Enable the buttons of a variable product only while a quotable variation is chosen.
	function syncWithOptions() {
		$( '.tack-quote-buttons[data-tack-variations]' ).each( function () {
			const $box = $( this );
			const quotable = withOptionsState( $box ).quotable;
			$box.find( '.tack-quote-btn, .tack-add-to-quote-btn' )
				.prop( 'disabled', ! quotable )
				.toggleClass( 'disabled', ! quotable );
		} );
	}

	// The store re-renders asynchronously after a choice, and it fires no event, so the
	// buttons re-read the form after any interaction with it and once the page settles
	// (attributes chosen by default or auto-selected).
	function scheduleWithOptionsSync() {
		window.setTimeout( syncWithOptions, 0 );
		window.setTimeout( syncWithOptions, 150 );
	}
	$( document ).on(
		'click change input keyup',
		WITH_OPTIONS_FORM,
		scheduleWithOptionsSync
	);
	$( function () {
		syncWithOptions();
	} );
	$( window ).on( 'load', function () {
		syncWithOptions();
		window.setTimeout( syncWithOptions, 500 );
	} );

	// ─── Variable products: mirror WooCommerce's own button gating ──────────────
	//
	// The server already refuses a variable product with no variation chosen (it would
	// otherwise quote the parent SKU at the cheapest variation's price). This is the UX half:
	// WooCommerce keeps its own add-to-cart button in `disabled wc-variation-selection-needed`
	// until a purchasable variation is found, so a quote button sitting in the same form that
	// stays clickable is inconsistent and invites the error the server then rejects.
	//
	// `show_variation` / `hide_variation` are the events core itself listens to in
	// assets/js/frontend/add-to-cart-variation.js. These names are not part of any
	// documented public API, so they are taken from that source rather than assumed.
	//
	// Core's `purchasable` argument is NOT used as-is: it is false whenever the variation is
	// not purchasable, and a quote-only product (store-wide or per product) is made
	// non-purchasable on purpose, so mirroring it left the quote buttons disabled on every
	// quote-only variable product. A variation is quotable when one is chosen, it is visible
	// and in stock — core's other two conditions (add-to-cart-variation.js, WooCommerce 11.2.1).
	$( document ).on(
		'show_variation',
		'form.variations_form',
		function ( event, variation ) {
			const quotable =
				!! ( variation && variation.variation_id ) &&
				variation.variation_is_visible !== false &&
				variation.is_in_stock !== false;
			$( this )
				.find( '.tack-quote-btn, .tack-add-to-quote-btn' )
				.prop( 'disabled', ! quotable )
				.toggleClass( 'disabled', ! quotable );
		}
	);

	$( document ).on(
		'hide_variation reset_data',
		'form.variations_form',
		function () {
			$( this )
				.find( '.tack-quote-btn, .tack-add-to-quote-btn' )
				.prop( 'disabled', true )
				.addClass( 'disabled' );
		}
	);

	// Initial state: a variable product loads with nothing selected, so the buttons must
	// start disabled. Non-variable products have no variations_form and are untouched.
	$( function () {
		$( 'form.variations_form' )
			.find( '.tack-quote-btn, .tack-add-to-quote-btn' )
			.prop( 'disabled', true )
			.addClass( 'disabled' );
	} );

	// ─── 1.12.0: what a product-page button quotes, for every product type ─────

	// The quantity input the button's quantity comes from: WooCommerce's own in the
	// button's form, else the one the plugin prints beside the buttons where WooCommerce
	// shows none (a quote-only, unpriced, out-of-stock or external product).
	function quantityInputFor( $btn, $form ) {
		const $own = $form.find( 'input.qty' ).first();
		return $own.length
			? $own
			: $btn.closest( '.tack-quote-buttons' ).find( 'input.qty' ).first();
	}

	// WooCommerce's quantity inputs carry the product's own minimum and step (and quantity
	// rules plugins add theirs there). Its Add to cart is a form submit, so the browser
	// holds it on such a value; a quote button is not, so it asks the same way. The
	// MAXIMUM is not held: it is what WooCommerce would sell now (the stock, without
	// backorders), and a quote may ask for more, as one for an out-of-stock product does.
	// The server quotes a product sold individually as one.
	function quantityValid( $inputs ) {
		let valid = true;
		$inputs.each( function () {
			const v = this.validity;
			if (
				valid &&
				v &&
				( v.badInput || v.rangeUnderflow || v.stepMismatch )
			) {
				if ( typeof this.reportValidity === 'function' ) {
					this.reportValidity();
				}
				valid = false;
			}
		} );
		return valid;
	}

	// The shopper's attribute choices in the form: the classic variation form's selects, or
	// the hidden `attribute_*` inputs the Add to Cart with Options block keeps beside its
	// selector (`VariationSelectorAttribute::render()`, 11.2.1).
	function scopeAttributes( $scope ) {
		const out = {};
		$scope
			.find( 'select[name^="attribute_"], input[name^="attribute_"]' )
			.each( function () {
				const value = String( $( this ).val() || '' );
				if ( value !== '' ) {
					out[ String( this.name ) ] = value;
				}
			} );
		return out;
	}

	// A grouped product's form: the one around the button, else (the buttons rendered after
	// it: a quote-only group, or the Add to Cart with Options block) the grouped form of
	// the same product, found by WooCommerce's hidden `add-to-cart` input.
	function groupFormFor( $btn, $form ) {
		if ( $form.length ) {
			return $form;
		}
		const id = String( $btn.attr( 'data-product-id' ) || '' );
		return $( 'form' )
			.filter( function () {
				return (
					$( this )
						.find( 'input[name="add-to-cart"]' )
						.filter( function () {
							return String( this.value ) === id;
						} ).length > 0
				);
			} )
			.first();
	}

	// `[ [ childId, quantity ], … ]` from a grouped form: WooCommerce's `quantity[<id>]`
	// inputs (a checkbox for a child sold individually), and the plugin's
	// `tack_quantity[<id>]` for a child on quote.
	function groupQuantities( $form ) {
		const out = [];
		$form
			.find( 'input[name^="quantity["], input[name^="tack_quantity["]' )
			.each( function () {
				const m = /^(?:tack_)?quantity\[(\d+)\]$/.exec(
					String( this.name )
				);
				if ( ! m ) {
					return;
				}
				let quantity = Number( $( this ).val() ) || 0;
				if ( this.type === 'checkbox' ) {
					quantity = this.checked ? 1 : 0;
				}
				out.push( [ Number( m[ 1 ] ), quantity ] );
			} );
		return out;
	}

	// A short message beside the buttons, read out by screen readers; cleared by the next
	// click. WooCommerce's own wording for the same mistake on its Add to cart.
	function buttonsNotice( $btn, text ) {
		const $box = $btn.closest( '.tack-quote-buttons' );
		let $notice = $box.find( '.tack-quote-buttons-notice' );
		if ( ! text ) {
			$notice.remove();
			return;
		}
		if ( ! $notice.length ) {
			$notice = $(
				'<p class="tack-quote-buttons-notice" role="alert"></p>'
			).appendTo( $box );
		}
		$notice.text( text );
	}

	// A grouped product's rows, or null when this button is not a grouped product's.
	// `[]` means no child has a quantity: the shopper is told, and nothing is quoted.
	function groupedRows( $btn ) {
		const raw = $btn
			.closest( '.tack-quote-buttons' )
			.attr( 'data-tack-group' );
		if ( raw === undefined ) {
			return null;
		}
		const $form = groupFormFor( $btn, scopeFor( $btn ) );
		const $inputs = $form.find(
			'input[name^="quantity["], input[name^="tack_quantity["]'
		);
		if ( ! quantityValid( $inputs ) ) {
			return [];
		}
		const api = window.TackWithOptions;
		const rows = api.groupRows(
			api.parseStates( raw ) || {},
			groupQuantities( $form )
		);
		buttonsNotice(
			$btn,
			rows.length ? '' : TackQuotes.i18n.chooseQuantities
		);
		return rows;
	}

	// "Request a Quote" (product page) — single product, immediate.
	$( document ).on( 'click', '.tack-quote-btn', function ( e ) {
		e.preventDefault();
		const $btn = $( this );
		buttonsNotice( $btn, '' );

		// A grouped product: its children with a quantity, one line each.
		const rows = groupedRows( $btn );
		if ( rows !== null ) {
			if ( rows.length ) {
				openModal( { items: rows } );
			}
			return;
		}

		const $box = withOptionsBox( $btn );
		const $scope = scopeFor( $btn );
		if ( ! $box.length ) {
			const $qty = quantityInputFor( $btn, $scope );
			if ( ! quantityValid( $qty ) ) {
				return;
			}
			openModal( {
				productId: $btn.data( 'product-id' ) || 0,
				$scope,
				quantity: Number( $qty.val() ) || 1,
			} );
			return;
		}
		const chosen = withOptionsState( $box );
		if ( ! chosen.quotable ) {
			syncWithOptions();
			return;
		}
		if ( ! quantityValid( quantityInputFor( $btn, $scope ) ) ) {
			return;
		}
		openModal( {
			productId: $btn.data( 'product-id' ) || 0,
			$scope,
			quantity: chosen.quantity,
			variationId: chosen.variationId,
			attributes: scopeAttributes( $scope ),
		} );
	} );

	// "Add to Quote" (product page) — adds to the browser-side quote list.
	// Never touches the WooCommerce cart, so it can't affect stock, cart
	// totals, or normal checkout.
	$( document ).on( 'click', '.tack-add-to-quote-btn', function ( e ) {
		e.preventDefault();
		const $btn = $( this );
		buttonsNotice( $btn, '' );
		const api = window.TackWithOptions;

		// A grouped product: one row per child with a quantity, never the group.
		const rows = groupedRows( $btn );
		if ( rows !== null ) {
			if ( rows.length ) {
				rows.forEach( addToList );
				scheduleReprice();
				flashAdded( $btn );
			}
			return;
		}

		const $box = withOptionsBox( $btn );
		const chosen = $box.length ? withOptionsState( $box ) : null;
		if ( chosen && ! chosen.quotable ) {
			syncWithOptions();
			return;
		}
		const $form = scopeFor( $btn );
		const $qty = quantityInputFor( $btn, $form );
		if ( ! quantityValid( $qty ) ) {
			return;
		}
		let quantity = Number( $qty.val() ) || 1;

		// On a variable product the button carries the PARENT's id/name/sku/price, so the
		// chosen variation has to come from WooCommerce's own hidden input. The server
		// re-derives every value from these ids; the rest is only what the drawer displays.
		let variationId =
			Number( $form.find( 'input[name="variation_id"]' ).val() ) || 0;
		let variationLabel = $form
			.find( 'select[name^="attribute_"]' )
			.map( function () {
				return (
					$( this ).find( 'option:selected' ).text() ||
					$( this ).val()
				);
			} )
			.get()
			.filter( Boolean )
			.join( ', ' );
		if ( chosen ) {
			// The block has no attribute selects; its variation, label and quantity come
			// from the resolved state.
			variationId = chosen.variationId;
			variationLabel = chosen.label;
			quantity = chosen.quantity;
		}

		// A variation's own SKU and price come from the map printed beside the buttons:
		// the button's are the parent's, i.e. the cheapest variation. Its "Any …" choices
		// (1.12.0) come from the form, named with the map of attribute values.
		const $buttons = $btn.closest( '.tack-quote-buttons' );
		const row = api.listRow(
			{
				productId: $btn.data( 'product-id' ),
				name: $btn.attr( 'data-product-name' ),
				sku: $btn.attr( 'data-product-sku' ),
				price: $btn.attr( 'data-product-price' ),
			},
			variationId,
			variationLabel,
			api.parseStates( $buttons.attr( 'data-tack-variation-lines' ) ),
			scopeAttributes( $form ),
			api.parseStates( $buttons.attr( 'data-tack-attribute-terms' ) )
		);
		row.quantity = quantity;
		if ( Number( $btn.attr( 'data-product-max' ) ) > 0 ) {
			row.max = Number( $btn.attr( 'data-product-max' ) );
		}
		addToList( row );
		scheduleReprice();
		flashAdded( $btn );
	} );

	// "Added ✓" for a moment. The label is remembered once: a second click inside the
	// moment used to remember "Added ✓" as the label and leave the button saying it.
	function flashAdded( $btn ) {
		if ( $btn.data( 'tackLabel' ) === undefined ) {
			$btn.data( 'tackLabel', $btn.text() );
		}
		window.clearTimeout( $btn.data( 'tackFlash' ) );
		$btn.text( TackQuotes.i18n.added );
		$btn.data(
			'tackFlash',
			window.setTimeout( function () {
				$btn.text( $btn.data( 'tackLabel' ) );
			}, 1200 )
		);
	}

	// Floating quote-list launcher: the drawer, or the merchant's quote page (1.10.0).
	$( document ).on( 'click', '#tack-quote-list-toggle', function () {
		const href = $( this ).data( 'href' );
		if ( href && ! onQuotePage() ) {
			window.location.href = href;
			return;
		}
		// 1.10.0: the launcher toggles the drawer and says so (aria-expanded).
		setDrawerOpen( $( '#tack-quote-list-drawer' ).prop( 'hidden' ) );
	} );

	function setDrawerOpen( open ) {
		$( '#tack-quote-list-drawer' ).prop( 'hidden', ! open );
		$( '#tack-quote-list-toggle' ).attr(
			'aria-expanded',
			open ? 'true' : 'false'
		);
	}

	// Escape closes an open drawer and gives focus back to the launcher.
	$( document ).on( 'keydown', function ( e ) {
		if (
			'Escape' !== e.key ||
			$( '#tack-quote-list-drawer' ).prop( 'hidden' ) ||
			$( 'body' ).hasClass( 'tack-quote-modal-open' )
		) {
			return;
		}
		setDrawerOpen( false );
		$( '#tack-quote-list-toggle' ).trigger( 'focus' );
	} );

	// "Add to Quote" on a product card (1.10.0): simple products only, quantity 1. The
	// server re-derives name/SKU/price from the id; the attributes are what the drawer shows.
	$( document ).on( 'click', '.tack-card-quote-btn', function ( e ) {
		e.preventDefault();
		e.stopPropagation();
		const $btn = $( this );
		addToList( {
			productId: $btn.data( 'product-id' ) || 0,
			variationId: 0,
			name: String( $btn.data( 'product-name' ) || '' ),
			sku: String( $btn.data( 'product-sku' ) || '' ),
			price: Number( $btn.data( 'product-price' ) ) || 0,
			quantity: 1,
			max: Number( $btn.attr( 'data-product-max' ) ) || undefined,
		} );
		scheduleReprice();
		flashAdded( $btn );
		openQuote();
	} );

	// "Request a quote for your cart" (1.10.0). The lines were read from the cart when the
	// page rendered; on the Cart block the cart changes without a page load, so the live
	// cart is re-read from WooCommerce's own Store API first (same site, the shopper's own
	// session) and the snapshot is the fallback. Quantities and membership come from the
	// live read; name, SKU and the tax-exclusive price from the matching snapshot line.
	$( document ).on( 'click', '.tack-quote-cart-btn', function ( e ) {
		e.preventDefault();
		const $btn = $( this );
		let snapshot = [];
		try {
			snapshot = JSON.parse( $btn.attr( 'data-lines' ) || '[]' );
		} catch ( err ) {
			snapshot = [];
		}
		if ( ! Array.isArray( snapshot ) ) {
			snapshot = [];
		}
		$btn.prop( 'disabled', true );

		function finish( lines ) {
			$btn.prop( 'disabled', false );
			if ( ! lines.length ) {
				// eslint-disable-next-line no-alert -- the "Quote my cart" button's only feedback when the cart is empty; kept as it shipped (a design change is out of scope for a standards pass).
				window.alert( TackQuotes.i18n.cartEmpty );
				return;
			}
			lines.forEach( function ( line ) {
				const row = {
					productId: Number( line.product_id ) || 0,
					variationId: Number( line.variation_id ) || 0,
					name: String( line.name || '' ),
					sku: String( line.sku || '' ),
					price: Number( line.price ) || 0,
					quantity: Math.max( 1, Number( line.quantity ) || 1 ),
				};
				// The cart line's "Any …" choices (1.12.0): Blue and Red of one "Any
				// colour" variation stay two lines.
				if ( window.TackWithOptions.attributesKey( line.attributes ) ) {
					row.attributes = line.attributes;
				}
				addToList( row );
			} );
			scheduleReprice();
			openQuote();
		}

		if ( ! TackQuotes.storeCartUrl || typeof window.fetch !== 'function' ) {
			finish( snapshot );
			return;
		}
		window
			.fetch( TackQuotes.storeCartUrl, {
				credentials: 'same-origin',
				headers: { Accept: 'application/json' },
			} )
			.then( function ( r ) {
				return r.ok ? r.json() : null;
			} )
			.then( function ( cart ) {
				const items =
					cart && Array.isArray( cart.items ) ? cart.items : null;
				if ( ! items ) {
					finish( snapshot );
					return;
				}
				const lines = [];
				items.forEach( function ( it ) {
					const id = Number( it.id ) || 0;
					// The plugin's own data on the item names the line exactly
					// (parent, variation, "Any …" choices): two cart lines of one
					// variation share `id`, so matching the snapshot by id cannot.
					if (
						it.extensions &&
						it.extensions.tackquote &&
						Number( it.extensions.tackquote.productId )
					) {
						lines.push( window.TackWithOptions.cartLine( it ) );
						return;
					}
					let match = null;
					for ( let i = 0; i < snapshot.length; i++ ) {
						if (
							( Number( snapshot[ i ].variation_id ) ||
								Number( snapshot[ i ].product_id ) ) === id
						) {
							match = snapshot[ i ];
							break;
						}
					}
					if ( match ) {
						lines.push( {
							product_id: match.product_id,
							variation_id: match.variation_id,
							name: match.name,
							sku: match.sku,
							price: match.price,
							quantity: it.quantity,
						} );
					} else if ( id ) {
						// Added since the page rendered: the parent and variation come from the
						// plugin's own Store API data on the item (TackWithOptions.cartLine).
						lines.push( window.TackWithOptions.cartLine( it ) );
					}
				} );
				finish( lines );
			} )
			.catch( function () {
				finish( snapshot );
			} );
	} );

	// The quote page's submit: the whole list plus the page's message (1.10.0).
	$( document ).on( 'click', '#tack-quote-page-submit', function () {
		const list = getList();
		if ( ! list.length ) {
			return;
		}
		openModal( {
			items: list,
			fromList: true,
			message: $( '#tack-quote-page-message' ).val() || '',
		} );
	} );

	$( document ).on( 'click', '#tack-quote-list-close', function () {
		setDrawerOpen( false );
		$( '#tack-quote-list-toggle' ).trigger( 'focus' );
	} );

	// "Checkout as Quote" — submits the whole quote list.
	$( document ).on( 'click', '#tack-quote-list-checkout', function () {
		const list = getList();
		if ( ! list.length ) {
			return;
		}
		openModal( { items: list, fromList: true } );
	} );

	$( document ).on( 'click', '.tack-quote-modal-overlay', function ( e ) {
		if ( e.target === this ) {
			closeModal();
		}
	} );

	$( document ).on(
		'click',
		'.tack-quote-modal-close, .tack-quote-modal-cancel',
		function ( e ) {
			e.preventDefault();
			closeModal();
		}
	);

	$( document ).on( 'keydown', function ( e ) {
		if ( e.key === 'Escape' && modal && ! modal.hasAttribute( 'hidden' ) ) {
			closeModal();
		}
	} );

	// Initial render on page load (in case the list was populated earlier).
	$( function () {
		renderList( getList() );
		// A signed-in buyer's list may hold lines added as a guest, or priced for a
		// quantity since changed: ask once on load. No-op for guests.
		const needs = getList().some( function ( row ) {
			return ! row.yourPrice || row.yourPriceQty !== row.quantity;
		} );
		if ( needs ) {
			scheduleReprice();
		}
	} );

	// 1.12.0: the quote list for other storefront scripts (quick order). Adding goes
	// through the same row merge and re-pricing as every button on the page.
	window.TackQuoteList = {
		add( row ) {
			addToList( row );
			scheduleReprice();
		},
		open: openQuote,
	};

	// Node tests (tests/js/) load this file with a stand-in jQuery; browsers have no `module`.
	if ( typeof module === 'object' && module && module.exports ) {
		module.exports = {
			showSubmitted,
			sellerPolicyFields,
			roleInput,
			companyChosen,
			buildQuestions,
			questionApplies,
			questionAnswers,
		};
	}
} )( jQuery );
