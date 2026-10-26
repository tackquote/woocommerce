/**
 * TackQuote: the selected variation of WooCommerce's "Add to Cart with Options" block.
 *
 * In its blockified mode (WooCommerce 11.2.1, `AddToCartWithOptions::render()`) the
 * block renders its own form with an Interactivity API variation selector. There is
 * no `form.variations_form` and no jQuery `found_variation` / `show_variation` event.
 * What the block does publish in the page is a hidden `input[name="variation_id"]`
 * inside its form, bound to the selected variation's id (empty until a complete
 * set of attributes matches a variation), and its quantity input `input.qty`.
 * Stock and visibility are only in WooCommerce's private `woocommerce/products`
 * store, so the plugin prints them beside the buttons instead (`data-tack-variations`,
 * `Tack_Block_Product::variation_states()`): `{ "<id>": { "q": 1|0, "l": "label" } }`.
 *
 * Pure functions only (no DOM), so `tests/js/with-options.test.js` runs them in Node.
 * `tack-quotes.js` does the DOM reads and calls `resolve()`.
 *
 * @param {Object}   root    The global object (`window`), or null under Node.
 * @param {Function} factory Builds the API.
 */
( function ( root, factory ) {
	const api = factory();
	if ( typeof module === 'object' && module && module.exports ) {
		module.exports = api;
	}
	if ( root ) {
		root.TackWithOptions = api;
	}
} )( typeof window !== 'undefined' ? window : null, function () {
	'use strict';

	function positiveInt( value ) {
		const n = Math.floor( Number( value ) );
		return isFinite( n ) && n > 0 ? n : 0;
	}

	// `data-tack-variations` as printed (a JSON object), or null when the attribute is
	// absent, i.e. the product is not variable. Unreadable JSON is an empty map, so
	// nothing is quotable: fail closed rather than quote the parent product.
	function parseStates( raw ) {
		if ( raw === undefined || raw === null || raw === '' ) {
			return null;
		}
		try {
			const parsed = JSON.parse( String( raw ) );
			return parsed &&
				typeof parsed === 'object' &&
				! Array.isArray( parsed )
				? parsed
				: {};
		} catch ( e ) {
			return {};
		}
	}

	// What the quote buttons may send, given the block's hidden variation_id value and
	// its quantity input value. Same contract as the classic `show_variation` path in
	// tack-quotes.js: a variable product is quotable only with a complete variation
	// chosen that is visible and in stock; the id sent is that variation's.
	function resolve( states, variationValue, quantityValue ) {
		const quantity = positiveInt( quantityValue ) || 1;
		if ( states === null ) {
			return {
				quotable: true,
				variationId: 0,
				label: '',
				quantity,
			};
		}
		const id = positiveInt( variationValue );
		const key = String( id );
		const entry =
			id && states && Object.prototype.hasOwnProperty.call( states, key )
				? states[ key ]
				: null;
		const quotable = !! entry && Number( entry.q ) === 1;
		return {
			quotable,
			variationId: quotable ? id : 0,
			label: quotable && entry.l ? String( entry.l ) : '',
			quantity,
		};
	}

	// The quote-list row for an "Add to Quote" click.
	//
	// `base` is what the button carries: the PARENT's id, name, SKU and price.
	// `lines` is `data-tack-variation-lines` parsed (`{ "<id>": { "s": sku, "p": price } }`,
	// `Tack_Block_Product::variation_lines()`), the chosen variation's own SKU and unit
	// price excluding tax. With a variation chosen the row carries THAT variation's SKU and
	// price; a variation missing from the map shows no SKU and no price rather than the
	// parent's, which is the minimum of the range and would understate the price.
	// The server re-derives every value from the ids; this is only what the drawer shows.
	function listRow( base, variationId, label, lines, chosen, terms ) {
		const b = base || {};
		const id = positiveInt( variationId );
		const row = {
			productId: positiveInt( b.productId ),
			variationId: id,
			name: String( b.name || '' ) + ( label ? ' - ' + label : '' ),
			sku: String( b.sku || '' ),
			price: Number( b.price ) || 0,
		};
		if ( ! id ) {
			return row;
		}
		const key = String( id );
		const line =
			lines &&
			typeof lines === 'object' &&
			Object.prototype.hasOwnProperty.call( lines, key )
				? lines[ key ]
				: null;
		row.sku = line && typeof line.s === 'string' ? line.s : '';
		// WooCommerce's own wording of the variation ("Large, Blue"), when the page sent it:
		// the same name the quick order and the cart give the line.
		if ( line && typeof line.l === 'string' && line.l ) {
			row.name = String( b.name || '' ) + ' - ' + line.l;
		}
		// A variation with "Any …" attributes (1.12.0): the variation's own label lacks the
		// shopper's choice, so the row carries the choice (the server names the quote line
		// from it) and is named from it. Two choices of one variation are two rows.
		const attributes = anyAttributes( chosen, line && line.a );
		if ( Object.keys( attributes ).length ) {
			row.attributes = attributes;
			const named = attributesLabel( chosen, terms );
			if ( named ) {
				row.name = String( b.name || '' ) + ' - ' + named;
			}
		}
		const price = line ? Number( line.p ) : NaN;
		row.price =
			line &&
			line.p !== null &&
			line.p !== '' &&
			isFinite( price ) &&
			price >= 0
				? price
				: 0;
		return row;
	}

	// The chosen values (`{ attribute_pa_color: 'blue' }`) of the attributes the variation
	// leaves open (`keys`, `data-tack-variation-lines` `a`); empty values are dropped.
	function anyAttributes( chosen, keys ) {
		const out = {};
		if (
			! chosen ||
			typeof chosen !== 'object' ||
			! Array.isArray( keys )
		) {
			return out;
		}
		keys.forEach( function ( key ) {
			const value = Object.prototype.hasOwnProperty.call( chosen, key )
				? String( chosen[ key ] === null ? '' : chosen[ key ] )
				: '';
			if ( value !== '' ) {
				out[ String( key ) ] = value;
			}
		} );
		return out;
	}

	// "Blue, Large": each chosen value as WooCommerce names it (`data-tack-attribute-terms`,
	// `Tack_Block_Product::attribute_terms()`), in the product's attribute order, the way
	// wc_get_formatted_variation() words a cart line. '' without the names.
	function attributesLabel( chosen, terms ) {
		if ( ! chosen || ! terms || typeof terms !== 'object' ) {
			return '';
		}
		const parts = [];
		Object.keys( terms ).forEach( function ( key ) {
			const value = Object.prototype.hasOwnProperty.call( chosen, key )
				? String( chosen[ key ] === null ? '' : chosen[ key ] )
				: '';
			if ( value === '' ) {
				return;
			}
			const names = terms[ key ] || {};
			parts.push(
				Object.prototype.hasOwnProperty.call( names, value )
					? String( names[ value ] )
					: value
			);
		} );
		return parts.join( ', ' );
	}

	// What makes two quote-list rows the same line: product, variation and the "Any …"
	// choices. Anything else (Hoodie Blue vs Hoodie Red of one "Any colour" variation, or
	// two children of a group) is a line of its own.
	function attributesKey( attributes ) {
		if ( ! attributes || typeof attributes !== 'object' ) {
			return '';
		}
		return Object.keys( attributes )
			.filter( function ( k ) {
				return String( attributes[ k ] ) !== '';
			} )
			.sort()
			.map( function ( k ) {
				return k + '=' + String( attributes[ k ] );
			} )
			.join( '&' );
	}

	function sameLine( a, b ) {
		return (
			!! a &&
			!! b &&
			positiveInt( a.productId ) === positiveInt( b.productId ) &&
			positiveInt( a.variationId ) === positiveInt( b.variationId ) &&
			attributesKey( a.attributes ) === attributesKey( b.attributes )
		);
	}

	// A quantity within a row's ceiling (`max`: 1 for a product sold individually), at
	// least 1; no ceiling when there is none.
	function capQuantity( quantity, max ) {
		const q = Math.max( 1, Math.floor( Number( quantity ) || 1 ) );
		const m = positiveInt( max );
		return m ? Math.min( q, m ) : q;
	}

	/**
	 * The quote-list rows for a grouped product (1.12.0): one per child the shopper gave a
	 * quantity above zero, in the page's order, never the group itself. That is what
	 * WooCommerce adds to the cart from the same form
	 * (`WC_Form_Handler::add_to_cart_handler_grouped()`, 11.2.1).
	 *
	 * @param {Object} group      `data-tack-group` parsed: `{ "<child id>": { n, s, p } }`.
	 * @param {Array}  quantities `[ [ childId, quantity ], … ]` read from the form's
	 *                            `quantity[<id>]` (and quote-only `tack_quantity[<id>]`) inputs.
	 * @return {Array} Rows `{ productId, variationId: 0, name, sku, price, quantity }`.
	 */
	function groupRows( group, quantities ) {
		const rows = [];
		if (
			! group ||
			typeof group !== 'object' ||
			! Array.isArray( quantities )
		) {
			return rows;
		}
		quantities.forEach( function ( pair ) {
			const id = positiveInt( pair && pair[ 0 ] );
			const quantity = positiveInt( pair && pair[ 1 ] );
			const key = String( id );
			if (
				! id ||
				! quantity ||
				! Object.prototype.hasOwnProperty.call( group, key )
			) {
				return;
			}
			const child = group[ key ] || {};
			const max = positiveInt( child.m );
			const price = Number( child.p );
			const row = {
				productId: id,
				variationId: 0,
				name: String( child.n || '' ),
				sku: String( child.s || '' ),
				price:
					child.p !== null &&
					child.p !== '' &&
					isFinite( price ) &&
					price >= 0
						? price
						: 0,
				quantity: max ? Math.min( quantity, max ) : quantity,
			};
			if ( max ) {
				row.max = max;
			}
			// One input per child; a second entry for the same child adds to its row.
			for ( let i = 0; i < rows.length; i++ ) {
				if ( sameLine( rows[ i ], row ) ) {
					rows[ i ].quantity = capQuantity(
						rows[ i ].quantity + row.quantity,
						max
					);
					return;
				}
			}
			rows.push( row );
		} );
		return rows;
	}

	/**
	 * One Store API cart item (`/wc/store/v1/cart` items, or the Cart block's `cartItems`)
	 * as a quote-list line `{product_id, variation_id, name, sku, price, quantity}`.
	 *
	 * The Store API names a variation by its OWN id, with no parent id. The plugin adds
	 * `extensions.tackquote` to every cart item (`productId`, `variationId`, `name`, `sku`,
	 * `price`; Tack_Quotes::cart_item_data()), so the line carries the parent and the
	 * variation and merges with the same variation added from its product page. Without
	 * it (an older plugin build serving the page) the item's own id is sent, which the
	 * server resolves either way, and the name keeps the variation's attribute values.
	 *
	 * @param {Object} it One cart item from the Store API.
	 * @return {Object} The quote-list line.
	 */
	function cartLine( it ) {
		const item = it || {};
		const quantity = Math.max( 1, Number( item.quantity ) || 1 );
		const ext = item.extensions && item.extensions.tackquote;
		if ( ext && positiveInt( ext.productId ) ) {
			const line = {
				product_id: positiveInt( ext.productId ),
				variation_id: positiveInt( ext.variationId ),
				name: String( ext.name || item.name || '' ),
				sku: String( ext.sku || item.sku || '' ),
				price: Number( ext.price ) || 0,
				quantity,
			};
			// The line's "Any …" choices (1.12.0), kept on the quote line.
			if ( attributesKey( ext.attributes ) ) {
				line.attributes = ext.attributes;
			}
			return line;
		}
		const minor = item.prices
			? Number( item.prices.currency_minor_unit )
			: NaN;
		const raw = item.prices ? Number( item.prices.price ) : NaN;
		const values = Array.isArray( item.variation )
			? item.variation
					.map( function ( v ) {
						return v && v.value ? String( v.value ) : '';
					} )
					.filter( Boolean )
			: [];
		return {
			product_id: positiveInt( item.id ),
			variation_id: 0,
			name:
				String( item.name || '' ) +
				( values.length ? ' - ' + values.join( ', ' ) : '' ),
			sku: String( item.sku || '' ),
			price:
				isFinite( raw ) && isFinite( minor )
					? raw / Math.pow( 10, minor )
					: 0,
			quantity,
		};
	}

	/**
	 * The quote list without the rows the server will quote no line for (1.12.0
	 * follow-up): the re-price answer's `dropped` (`[{ product_id, variation_id, grouped }]`).
	 * A row is matched by product and variation; its "Any …" choices do not matter, as
	 * they cannot make a row quotable.
	 *
	 * @param {Array} list    The quote-list rows.
	 * @param {Array} dropped The server's `dropped`.
	 * @return {Object} `{ kept, removed, allGrouped }`; `allGrouped` is true when at least
	 *                  one row was removed and every removed row was a grouped product.
	 */
	function dropRows( list, dropped ) {
		const rows = Array.isArray( list ) ? list : [];
		const gone = Array.isArray( dropped ) ? dropped : [];
		const kept = [];
		const removed = [];
		let allGrouped = true;
		rows.forEach( function ( row ) {
			let match = null;
			for ( let i = 0; i < gone.length; i++ ) {
				if (
					gone[ i ] &&
					positiveInt( gone[ i ].product_id ) ===
						positiveInt( row && row.productId ) &&
					positiveInt( gone[ i ].variation_id ) ===
						positiveInt( row && row.variationId )
				) {
					match = gone[ i ];
					break;
				}
			}
			if ( ! match ) {
				kept.push( row );
				return;
			}
			removed.push( row );
			if ( match.grouped !== true ) {
				allGrouped = false;
			}
		} );
		return {
			kept,
			removed,
			allGrouped: removed.length > 0 && allGrouped,
		};
	}

	return {
		dropRows,
		parseStates,
		resolve,
		listRow,
		cartLine,
		anyAttributes,
		attributesLabel,
		attributesKey,
		sameLine,
		groupRows,
		capQuantity,
	};
} );
