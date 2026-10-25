/**
 * TackQuote net terms: the Checkout block payment method.
 *
 * Plain JavaScript, no build step: WooCommerce exposes the registry as
 * window.wc.wcBlocksRegistry and the settings as window.wc.wcSettings
 * (WooCommerce docs, "Payment method integration"). The server sends title,
 * description and a boolean canPay only; the credit line never reaches here.
 * Since 1.12.0 canMakePayment also reads the Store API cart's own yes/no
 * (`extensions.tackquote.netTermsAvailable`), so a cart change re-checks it.
 */
( function () {
	'use strict';

	var wc = window.wc || {};
	var wp = window.wp || {};
	if ( ! wc.wcBlocksRegistry || ! wc.wcSettings || ! wp.element ) {
		return;
	}

	var el = wp.element.createElement;
	var decode = wp.htmlEntities && wp.htmlEntities.decodeEntities
		? wp.htmlEntities.decodeEntities
		: function ( text ) {
			return text;
		};
	var data = wc.wcSettings.getSetting( 'tackquote_net_terms_data', {} ) || {};
	var title = decode( data.title || '' );
	var description = decode( data.description || '' );

	var Content = function () {
		return el( 'div', null, description );
	};

	var Label = function ( props ) {
		var components = props && props.components ? props.components : {};
		if ( components.PaymentMethodLabel ) {
			return el( components.PaymentMethodLabel, { text: title } );
		}
		return el( 'span', null, title );
	};

	wc.wcBlocksRegistry.registerPaymentMethod( {
		name: 'tackquote_net_terms',
		label: el( Label, null ),
		content: el( Content, null ),
		edit: el( Content, null ),
		ariaLabel: title,
		// 1.12.0: WooCommerce re-runs this whenever the cart changes, with the Store API
		// cart (`cart.extensions`). The server's answer for THAT cart wins; the answer
		// printed with the page is the fallback (an older server, or no Store API data).
		canMakePayment: function ( arg ) {
			var ext = arg && arg.cart && arg.cart.extensions && arg.cart.extensions.tackquote;
			if ( ext && typeof ext.netTermsAvailable === 'boolean' ) {
				return ext.netTermsAvailable;
			}
			return true === data.canPay;
		},
		supports: {
			features: Array.isArray( data.supports ) ? data.supports : [ 'products' ],
		},
	} );
} )();
