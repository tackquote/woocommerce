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
	function listRow( base, variationId, label, lines ) {
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
			return {
				product_id: positiveInt( ext.productId ),
				variation_id: positiveInt( ext.variationId ),
				name: String( ext.name || item.name || '' ),
				sku: String( ext.sku || item.sku || '' ),
				price: Number( ext.price ) || 0,
				quantity,
			};
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

	return {
		parseStates,
		resolve,
		listRow,
		cartLine,
	};
} );
