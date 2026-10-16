/**
 * Conditional fields for the TackQuote storefront application forms.
 *
 * A field carrying data-tack-show-if-field / data-tack-show-if-equals is shown only
 * while the named controller field holds that value — the same rule the server
 * applies (a checkbox compares true/false, everything else its trimmed value), so
 * what the shopper sees is what the server will keep. Without this script every
 * field shows and the server simply drops the answers to hidden ones.
 *
 * A "No dates in the past" date field carries the SELLER's calendar
 * (data-tack-seller-zone / data-tack-seller-today), because the server refuses a
 * date before the seller's today. The earliest selectable day follows the seller's
 * zone live (a page left open past the seller's midnight moves with it), and the
 * hint under the field shows only when the shopper's own date differs.
 */
( function () {
	'use strict';

	function controllerValue( form, key ) {
		var holder = form.querySelector( '[data-tack-field="' + key + '"]' );
		if ( ! holder ) {
			return null;
		}
		var box = holder.querySelector( 'input[type="checkbox"]' );
		if ( box && ! holder.classList.contains( 'tackquote-field-multiselect' ) ) {
			return box.checked ? 'true' : 'false';
		}
		var input = holder.querySelector( 'input, select, textarea' );
		return input ? String( input.value ).trim() : null;
	}

	function hidden( form, holder, depth ) {
		var key = holder.getAttribute( 'data-tack-show-if-field' );
		if ( ! key || depth > 25 ) {
			return false;
		}
		var controller = form.querySelector( '[data-tack-field="' + key + '"]' );
		if ( ! controller || hidden( form, controller, depth + 1 ) ) {
			return true;
		}
		return controllerValue( form, key ) !== holder.getAttribute( 'data-tack-show-if-equals' );
	}

	function apply( form ) {
		var conditional = form.querySelectorAll( '[data-tack-show-if-field]' );
		Array.prototype.forEach.call( conditional, function ( holder ) {
			var off = hidden( form, holder, 0 );
			holder.hidden = off;
			Array.prototype.forEach.call( holder.querySelectorAll( 'input, select, textarea' ), function ( control ) {
				// A hidden required field must not block the browser's own validation.
				if ( off ) {
					if ( control.required ) {
						control.setAttribute( 'data-tack-was-required', '1' );
						control.required = false;
					}
				} else if ( control.getAttribute( 'data-tack-was-required' ) === '1' ) {
					control.required = true;
				}
			} );
		} );
	}

	var DAY = /^\d{4}-\d{2}-\d{2}$/;

	/** `YYYY-MM-DD` of `now` in `zone`, or null for a zone this browser does not know. */
	function dayIn( zone, now ) {
		try {
			var parts = new Intl.DateTimeFormat( 'en-US', {
				timeZone: zone,
				year: 'numeric',
				month: '2-digit',
				day: '2-digit',
			} ).formatToParts( now );
			var get = function ( type ) {
				for ( var i = 0; i < parts.length; i++ ) {
					if ( parts[ i ].type === type ) {
						return parts[ i ].value;
					}
				}
				return '';
			};
			var day = get( 'year' ) + '-' + get( 'month' ) + '-' + get( 'day' );
			return DAY.test( day ) ? day : null;
		} catch ( e ) {
			return null;
		}
	}

	/** The shopper's own local day. */
	function localDay( now ) {
		var pad = function ( n ) {
			return ( n < 10 ? '0' : '' ) + n;
		};
		return now.getFullYear() + '-' + pad( now.getMonth() + 1 ) + '-' + pad( now.getDate() );
	}

	/** "Oct 17, 2026" in the page's language; the ISO day when the browser cannot format. */
	function dayLabel( day ) {
		var p = day.split( '-' );
		try {
			return new Date( Date.UTC( +p[ 0 ], +p[ 1 ] - 1, +p[ 2 ] ) ).toLocaleDateString(
				document.documentElement.lang || undefined,
				{ month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC' }
			);
		} catch ( e ) {
			return day;
		}
	}

	function sellerCalendar( form, now ) {
		var inputs = form.querySelectorAll( 'input[data-tack-seller-today]' );
		Array.prototype.forEach.call( inputs, function ( input ) {
			var sent = input.getAttribute( 'data-tack-seller-today' );
			var today = dayIn( input.getAttribute( 'data-tack-seller-zone' ), now ) || ( DAY.test( sent ) ? sent : null );
			if ( ! today ) {
				return;
			}
			input.min = today;
			var hint = document.getElementById( input.id + '_calendar' );
			if ( ! hint ) {
				return;
			}
			var differs = today !== localDay( now );
			hint.hidden = ! differs;
			if ( differs ) {
				var label = hint.querySelector( '.tackquote-seller-day' );
				if ( label ) {
					label.textContent = dayLabel( today );
				}
				var described = ( input.getAttribute( 'aria-describedby' ) || '' ).split( ' ' ).filter( Boolean );
				if ( described.indexOf( hint.id ) === -1 ) {
					described.push( hint.id );
					input.setAttribute( 'aria-describedby', described.join( ' ' ) );
				}
			}
		} );
	}

	function init() {
		var forms = document.querySelectorAll( 'form[data-tack-form]' );
		Array.prototype.forEach.call( forms, function ( form ) {
			sellerCalendar( form, new Date() );
			form.addEventListener( 'focusin', function ( event ) {
				// Re-read on the way into a date field: the seller's day may have turned.
				if ( event.target && event.target.hasAttribute && event.target.hasAttribute( 'data-tack-seller-today' ) ) {
					sellerCalendar( form, new Date() );
				}
			} );
			apply( form );
			form.addEventListener( 'change', function () {
				apply( form );
			} );
			form.addEventListener( 'input', function () {
				apply( form );
			} );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
