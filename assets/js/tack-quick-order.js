/**
 * Quick order (1.12.0): SKU rows and a pasted list, checked against the store's
 * catalogue (`?wc-ajax=tack_quick_order_lookup`), then added to the cart
 * (`?wc-ajax=tack_quick_order_add`, through WooCommerce's own add-to-cart rules)
 * or to the quote list (`window.TackQuoteList`, owned by tack-quotes.js).
 *
 * The server re-checks every id on the way in; nothing here decides a price.
 */
/* global TackQuickOrder, TackQuotes, jQuery */
/* eslint-disable @wordpress/valid-sprintf -- every sprintf() format here is a translated string from the i18n map below, so the rule, which accepts only a literal or a direct __() call, cannot see it; each of those strings carries a translators comment. */
( function ( $ ) {
	'use strict';

	const wpI18n = window.wp && window.wp.i18n ? window.wp.i18n : null;
	const __ = wpI18n
		? wpI18n.__
		: function ( text ) {
				return text;
		  };
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

	const i18n = {
		checking: __( 'Checking…', 'tackquote' ),
		check: __( 'Check items', 'tackquote' ),
		empty: __( 'Enter at least one SKU.', 'tackquote' ),
		// translators: %d: the most products one check accepts.
		tooMany: __( 'Enter at most %d products at a time.', 'tackquote' ),
		notFound: __( 'Not found', 'tackquote' ),
		chooseOptions: __( 'Choose options', 'tackquote' ),
		notPurchasable: __( 'Available on quote', 'tackquote' ),
		error: __(
			'We could not check these items right now. Please try again.',
			'tackquote'
		),
		addFailed: __( 'Something went wrong. Please try again.', 'tackquote' ),
		adding: __( 'Adding…', 'tackquote' ),
		// translators: %d: number of products added to the cart.
		addedToCart: __( 'Items added to your cart: %d', 'tackquote' ),
		viewCart: __( 'View cart', 'tackquote' ),
		// translators: %d: number of products added to the quote list.
		addedToQuote: __( 'Items added to your quote list: %d', 'tackquote' ),
	};

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

	// "ABC-1, 3" / "ABC-1 3" / "ABC-1\t3" / "ABC-1": one line, one product.
	function parsePasted( text ) {
		const out = [];
		String( text || '' )
			.split( /\r?\n/ )
			.forEach( function ( line ) {
				const trimmed = line.trim();
				if ( ! trimmed ) {
					return;
				}
				const m = trimmed.match( /^(.+?)[\s,;]+(\d+)$/ );
				out.push(
					m
						? {
								sku: m[ 1 ].trim(),
								quantity: Number( m[ 2 ] ) || 1,
						  }
						: { sku: trimmed, quantity: 1 }
				);
			} );
		return out;
	}

	// Rows and the pasted list, merged by SKU (quantities add up), order kept.
	function collect( $root ) {
		let rows = [];
		$root.find( '.tack-quick-order-row' ).each( function () {
			const sku = String(
				$( this ).find( '.tack-quick-order-sku' ).val() || ''
			).trim();
			if ( sku ) {
				rows.push( {
					sku,
					quantity: Math.max(
						1,
						Number(
							$( this ).find( '.tack-quick-order-qty' ).val()
						) || 1
					),
				} );
			}
		} );
		rows = rows.concat(
			parsePasted( $root.find( '.tack-quick-order-paste-input' ).val() )
		);
		const merged = [];
		const at = {};
		rows.forEach( function ( r ) {
			const key = r.sku.toLowerCase();
			if ( Object.prototype.hasOwnProperty.call( at, key ) ) {
				merged[ at[ key ] ].quantity += r.quantity;
			} else {
				at[ key ] = merged.length;
				merged.push( { sku: r.sku, quantity: r.quantity } );
			}
		} );
		return merged;
	}

	function nonce() {
		return window.TackQuotes && TackQuotes.nonce ? TackQuotes.nonce : '';
	}

	// POST with the storefront nonce; refresh it ONCE when a cached page's nonce went stale.
	function post( url, data, allowRetry ) {
		const deferred = $.Deferred();
		$.post( url, $.extend( { nonce: nonce() }, data ) )
			.done( function ( res ) {
				if ( res && res.success ) {
					deferred.resolve( res.data );
				} else {
					deferred.reject( ( res && res.data ) || null );
				}
			} )
			.fail( function ( xhr ) {
				const body =
					( xhr && xhr.responseJSON && xhr.responseJSON.data ) ||
					null;
				if (
					allowRetry &&
					body &&
					body.code === 'tack_nonce_expired' &&
					window.TackQuotes &&
					TackQuotes.nonceUrl
				) {
					$.get( TackQuotes.nonceUrl )
						.done( function ( fresh ) {
							const refreshed =
								fresh && fresh.data && fresh.data.nonce;
							if ( ! refreshed ) {
								deferred.reject( body );
								return;
							}
							TackQuotes.nonce = refreshed;
							post( url, data, false ).then(
								deferred.resolve,
								deferred.reject
							);
						} )
						.fail( function () {
							deferred.reject( body );
						} );
					return;
				}
				deferred.reject( body );
			} );
		return deferred.promise();
	}

	function say( $root, text, isError ) {
		$root
			.find( '.tack-quick-order-message' )
			.text( text )
			.toggleClass( 'tack-quick-order-error', !! isError )
			.prop( 'hidden', ! text );
	}

	function renderResults( $root, lines ) {
		const $body = $root.find( '.tack-quick-order-result-rows' ).empty();
		lines.forEach( function ( line ) {
			let product;
			let price = '';
			if ( line.status === 'found' ) {
				product =
					'<a href="' +
					escapeHtml( line.url ) +
					'">' +
					escapeHtml( line.name ) +
					'</a>';
				// priceHtml is WooCommerce's own price markup, already through wp_kses_post().
				price = line.priceHtml || '';
				if ( ! line.purchasable ) {
					product +=
						' <span class="tack-quick-order-note">' +
						escapeHtml( i18n.notPurchasable ) +
						'</span>';
				}
			} else if ( line.status === 'needs_options' ) {
				product =
					escapeHtml( line.name ) +
					' <a class="tack-quick-order-note" href="' +
					escapeHtml( line.url ) +
					'">' +
					escapeHtml( i18n.chooseOptions ) +
					'</a>';
			} else {
				product =
					'<span class="tack-quick-order-note">' +
					escapeHtml( i18n.notFound ) +
					'</span>';
			}
			$body.append(
				'<tr class="tack-quick-order-result tack-quick-order-' +
					escapeHtml( line.status ) +
					'">' +
					'<td data-title="' +
					escapeHtml( __( 'SKU', 'tackquote' ) ) +
					'">' +
					escapeHtml( line.sku ) +
					'</td>' +
					'<td data-title="' +
					escapeHtml( __( 'Product', 'tackquote' ) ) +
					'">' +
					product +
					'</td>' +
					'<td data-title="' +
					escapeHtml( __( 'Price', 'tackquote' ) ) +
					'">' +
					price +
					'</td>' +
					'<td data-title="' +
					escapeHtml( __( 'Quantity', 'tackquote' ) ) +
					'">' +
					escapeHtml( line.quantity ) +
					'</td>' +
					'</tr>'
			);
		} );
		const found = lines.filter( function ( l ) {
			return l.status === 'found';
		} );
		$root.data( 'tackFound', found );
		$root.find( '.tack-quick-order-cart' ).prop(
			'disabled',
			! found.some( function ( l ) {
				return l.purchasable;
			} )
		);
		$root
			.find( '.tack-quick-order-quote' )
			.prop( 'disabled', ! found.length || ! window.TackQuoteList );
		$root.find( '.tack-quick-order-results' ).prop( 'hidden', false );
	}

	function failure( $root, data ) {
		say( $root, ( data && data.message ) || i18n.error, true );
	}

	$( document ).on(
		'submit',
		'[data-tack-quick-order] .tack-quick-order-form',
		function ( e ) {
			e.preventDefault();
			const $root = $( this ).closest( '[data-tack-quick-order]' );
			const lines = collect( $root );
			const max =
				Number( $root.attr( 'data-max-lines' ) ) ||
				TackQuickOrder.maxLines ||
				50;
			if ( ! lines.length ) {
				say( $root, i18n.empty, true );
				return;
			}
			if ( lines.length > max ) {
				say( $root, sprintf( i18n.tooMany, max ), true );
				return;
			}
			const $btn = $( this )
				.find( '.tack-quick-order-lookup' )
				.prop( 'disabled', true )
				.text( i18n.checking );
			say( $root, '' );
			post(
				TackQuickOrder.lookupUrl,
				{ lines: JSON.stringify( lines ) },
				true
			)
				.done( function ( data ) {
					renderResults( $root, ( data && data.lines ) || [] );
				} )
				.fail( function ( data ) {
					failure( $root, data );
				} )
				.always( function () {
					$btn.prop( 'disabled', false ).text( i18n.check );
				} );
		}
	);

	$( document ).on(
		'click',
		'[data-tack-quick-order] .tack-quick-order-add-row',
		function () {
			const $rows = $( this )
				.closest( '[data-tack-quick-order]' )
				.find( '.tack-quick-order-rows' );
			const $last = $rows.find( '.tack-quick-order-row' ).last();
			const n = $rows.find( '.tack-quick-order-row' ).length + 1;
			const $row = $last.clone();
			$row.find( 'input' ).each( function () {
				const id = String( this.id || '' ).replace( /-\d+$/, '-' + n );
				$( this ).closest( 'td' ).find( 'label' ).attr( 'for', id );
				this.id = id;
				this.value = $( this ).hasClass( 'tack-quick-order-qty' )
					? '1'
					: '';
			} );
			$row.find( 'label' ).each( function () {
				// "SKU, row 5": the number is the last digits of the label.
				$( this ).text(
					$( this )
						.text()
						.replace( /\d+\s*$/, String( n ) )
				);
			} );
			$rows.append( $row );
			$row.find( '.tack-quick-order-sku' ).trigger( 'focus' );
		}
	);

	$( document ).on(
		'click',
		'[data-tack-quick-order] .tack-quick-order-cart',
		function () {
			const $root = $( this ).closest( '[data-tack-quick-order]' );
			const found = ( $root.data( 'tackFound' ) || [] ).filter(
				function ( l ) {
					return l.purchasable;
				}
			);
			if ( ! found.length ) {
				return;
			}
			const $btn = $( this ).prop( 'disabled', true );
			const label = $btn.text();
			$btn.text( i18n.adding );
			post(
				TackQuickOrder.addUrl,
				{
					lines: JSON.stringify(
						found.map( function ( l ) {
							return {
								product_id: l.productId,
								variation_id: l.variationId || 0,
								quantity: l.quantity,
							};
						} )
					),
				},
				true
			)
				.done( function ( data ) {
					const messages = [];
					if ( data && data.added ) {
						messages.push(
							sprintf( i18n.addedToCart, data.added )
						);
					}
					( ( data && data.lines ) || [] ).forEach(
						function ( line ) {
							if ( ! line.added && line.message ) {
								messages.push( line.message );
							}
						}
					);
					( ( data && data.warnings ) || [] ).forEach(
						function ( w ) {
							messages.push( w );
						}
					);
					say(
						$root,
						messages.join( ' ' ),
						! ( data && data.added )
					);
					if ( data && data.added && data.cartUrl ) {
						$root
							.find( '.tack-quick-order-message' )
							.append(
								' ',
								$(
									'<a class="tack-quick-order-cart-link"></a>'
								)
									.attr( 'href', data.cartUrl )
									.text( i18n.viewCart )
							);
					}
					if ( data && data.added ) {
						// Classic themes refresh their mini cart from the fragments; the Mini-Cart
						// and Cart blocks listen for this event and re-read the Store API cart.
						$( document.body ).trigger( 'wc_fragment_refresh' );
						if ( typeof window.CustomEvent === 'function' ) {
							document.body.dispatchEvent(
								new window.CustomEvent(
									'wc-blocks_added_to_cart',
									{
										bubbles: true,
										cancelable: true,
										detail: { preserveCartData: false },
									}
								)
							);
						}
					}
				} )
				.fail( function ( data ) {
					say(
						$root,
						( data && data.message ) || i18n.addFailed,
						true
					);
				} )
				.always( function () {
					$btn.prop( 'disabled', false ).text( label );
				} );
		}
	);

	$( document ).on(
		'click',
		'[data-tack-quick-order] .tack-quick-order-quote',
		function () {
			const $root = $( this ).closest( '[data-tack-quick-order]' );
			const found = $root.data( 'tackFound' ) || [];
			if ( ! found.length || ! window.TackQuoteList ) {
				return;
			}
			found.forEach( function ( l ) {
				window.TackQuoteList.add( {
					productId: l.productId,
					variationId: l.variationId || 0,
					name: String( l.name || '' ),
					sku: String( l.productSku || l.sku || '' ),
					price: typeof l.price === 'number' ? l.price : 0,
					quantity: Math.max( 1, Number( l.quantity ) || 1 ),
				} );
			} );
			say( $root, sprintf( i18n.addedToQuote, found.length ) );
			window.TackQuoteList.open();
		}
	);

	// Node tests load this file with stand-ins; browsers have no `module`.
	if ( typeof module === 'object' && module && module.exports ) {
		module.exports = { parsePasted, collect };
	}
} )( jQuery );
