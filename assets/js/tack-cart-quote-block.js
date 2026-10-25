/**
 * "Request a quote for your cart" in the Cart block (1.12.0).
 *
 * Plain JavaScript, no build step. Fills WooCommerce's `ExperimentalOrderMeta` slot
 * (window.wc.blocksCheckout), which in the Cart block renders below the totals and
 * above the Proceed to Checkout button, through `wp.plugins.registerPlugin` in the
 * `woocommerce-checkout` scope (developer.woocommerce.com, "Available Slots").
 *
 * The control is the classic cart's `.tack-quote-cart-btn`: tack-quotes.js handles
 * the click, re-reading the live cart from the Store API. The lines carried here
 * come from the slot's `cart` prop and are only its fallback.
 *
 * Should the experimental slot be missing, a fixed control is added to the page
 * instead, the 1.10.0 behaviour, so the Cart block never loses the control.
 */
( function () {
	'use strict';

	var wc = window.wc || {};
	var wp = window.wp || {};
	var settings = wc.wcSettings && wc.wcSettings.getSetting ? wc.wcSettings.getSetting( 'tackquote-cart-quote_data', {} ) : {};
	var label = settings && settings.label ? String( settings.label ) : '';
	var classes = settings && settings.buttonClass ? String( settings.buttonClass ) : 'button tack-quote-cart-btn';
	if ( ! label ) {
		return;
	}

	// The slot's camelCase cart items as the lines tack-quotes.js reads (`data-lines`).
	function linesOf( cart ) {
		var items = cart && Array.isArray( cart.cartItems ) ? cart.cartItems : [];
		return items.map( function ( it ) {
			var minor = it.prices ? Number( it.prices.currency_minor_unit ) : NaN;
			var raw = it.prices ? Number( it.prices.price ) : NaN;
			return {
				product_id: Number( it.id ) || 0,
				variation_id: 0,
				name: String( it.name || '' ),
				sku: String( it.sku || '' ),
				price: isFinite( raw ) && isFinite( minor ) ? raw / Math.pow( 10, minor ) : 0,
				quantity: Number( it.quantity ) || 1,
			};
		} );
	}

	var Slot = wc.blocksCheckout && wc.blocksCheckout.ExperimentalOrderMeta;
	if ( Slot && wp.plugins && wp.plugins.registerPlugin && wp.element ) {
		var el = wp.element.createElement;
		var Control = function ( props ) {
			// The fill renders in the Checkout block too; the control belongs to the cart.
			if ( props && props.context && props.context !== 'woocommerce/cart' ) {
				return null;
			}
			var lines = linesOf( props && props.cart );
			if ( props && props.cart && ! lines.length ) {
				return null;
			}
			return el(
				'div',
				{ className: 'tack-quote-cart-block' },
				el(
					'button',
					{ type: 'button', className: classes, 'data-lines': JSON.stringify( lines ) },
					label
				)
			);
		};
		wp.plugins.registerPlugin( 'tackquote-cart-quote', {
			render: function () {
				return el( Slot, null, el( Control, null ) );
			},
			scope: 'woocommerce-checkout',
		} );
		return;
	}

	// No slot: the fixed control (1.10.0), once, and only while the cart has lines, as
	// read from WooCommerce's own Store API with the shopper's session.
	function addFixed() {
		if ( document.querySelector( '.tack-quote-cart-btn' ) ) {
			return;
		}
		var box = document.createElement( 'div' );
		box.className = 'tack-quote-cart-fixed';
		var button = document.createElement( 'button' );
		button.type = 'button';
		button.className = classes;
		button.setAttribute( 'data-lines', '[]' );
		button.textContent = label;
		box.appendChild( button );
		document.body.appendChild( box );
	}
	function fallback() {
		var url = window.TackQuotes && window.TackQuotes.storeCartUrl;
		if ( ! url || typeof window.fetch !== 'function' ) {
			return;
		}
		window
			.fetch( url, { credentials: 'same-origin', headers: { Accept: 'application/json' } } )
			.then( function ( r ) {
				return r.ok ? r.json() : null;
			} )
			.then( function ( cart ) {
				if ( cart && Array.isArray( cart.items ) && cart.items.length ) {
					addFixed();
				}
			} )
			.catch( function () {} );
	}
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', fallback );
	} else {
		fallback();
	}
}() );
