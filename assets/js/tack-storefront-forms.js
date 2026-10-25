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
		const holder = form.querySelector( '[data-tack-field="' + key + '"]' );
		if ( ! holder ) {
			return null;
		}
		const box = holder.querySelector( 'input[type="checkbox"]' );
		if (
			box &&
			! holder.classList.contains( 'tackquote-field-multiselect' )
		) {
			return box.checked ? 'true' : 'false';
		}
		const input = holder.querySelector( 'input, select, textarea' );
		return input ? String( input.value ).trim() : null;
	}

	function hidden( form, holder, depth ) {
		const key = holder.getAttribute( 'data-tack-show-if-field' );
		if ( ! key || depth > 25 ) {
			return false;
		}
		const controller = form.querySelector(
			'[data-tack-field="' + key + '"]'
		);
		if ( ! controller || hidden( form, controller, depth + 1 ) ) {
			return true;
		}
		return (
			controllerValue( form, key ) !==
			holder.getAttribute( 'data-tack-show-if-equals' )
		);
	}

	function apply( form ) {
		const conditional = form.querySelectorAll(
			'[data-tack-show-if-field]'
		);
		Array.prototype.forEach.call( conditional, function ( holder ) {
			const off = hidden( form, holder, 0 );
			holder.hidden = off;
			Array.prototype.forEach.call(
				holder.querySelectorAll( 'input, select, textarea' ),
				function ( control ) {
					// A hidden required field must not block the browser's own validation.
					if ( off ) {
						if ( control.required ) {
							control.setAttribute(
								'data-tack-was-required',
								'1'
							);
							control.required = false;
						}
					} else if (
						control.getAttribute( 'data-tack-was-required' ) === '1'
					) {
						control.required = true;
					}
				}
			);
		} );
	}

	const DAY = /^\d{4}-\d{2}-\d{2}$/;

	/**
	 * `YYYY-MM-DD` of `now` in `zone`, or null for a zone this browser does not know.
	 *
	 * @param {string} zone IANA time zone, e.g. `America/Chicago`.
	 * @param {Date}   now  The instant.
	 * @return {string|null} The day, or null.
	 */
	function dayIn( zone, now ) {
		try {
			const parts = new Intl.DateTimeFormat( 'en-US', {
				timeZone: zone,
				year: 'numeric',
				month: '2-digit',
				day: '2-digit',
			} ).formatToParts( now );
			const get = function ( type ) {
				for ( let i = 0; i < parts.length; i++ ) {
					if ( parts[ i ].type === type ) {
						return parts[ i ].value;
					}
				}
				return '';
			};
			const day =
				get( 'year' ) + '-' + get( 'month' ) + '-' + get( 'day' );
			return DAY.test( day ) ? day : null;
		} catch ( e ) {
			return null;
		}
	}

	/**
	 * The shopper's own local day.
	 *
	 * @param {Date} now The instant.
	 * @return {string} `YYYY-MM-DD`.
	 */
	function localDay( now ) {
		const pad = function ( n ) {
			return ( n < 10 ? '0' : '' ) + n;
		};
		return (
			now.getFullYear() +
			'-' +
			pad( now.getMonth() + 1 ) +
			'-' +
			pad( now.getDate() )
		);
	}

	/**
	 * "Oct 17, 2026" in the page's language; the ISO day when the browser cannot format.
	 *
	 * @param {string} day `YYYY-MM-DD`.
	 * @return {string} The label.
	 */
	function dayLabel( day ) {
		const p = day.split( '-' );
		try {
			return new Date(
				Date.UTC( +p[ 0 ], +p[ 1 ] - 1, +p[ 2 ] )
			).toLocaleDateString( document.documentElement.lang || undefined, {
				month: 'short',
				day: 'numeric',
				year: 'numeric',
				timeZone: 'UTC',
			} );
		} catch ( e ) {
			return day;
		}
	}

	/**
	 * The ISO day after `day`.
	 *
	 * @param {string} day `YYYY-MM-DD`.
	 * @return {string} `YYYY-MM-DD`.
	 */
	function nextDay( day ) {
		const p = day.split( '-' );
		const d = new Date( Date.UTC( +p[ 0 ], +p[ 1 ] - 1, +p[ 2 ] + 1 ) );
		const pad = function ( n ) {
			return ( n < 10 ? '0' : '' ) + n;
		};
		return (
			d.getUTCFullYear() +
			'-' +
			pad( d.getUTCMonth() + 1 ) +
			'-' +
			pad( d.getUTCDate() )
		);
	}

	/**
	 * The seller's today by this device's clock, kept between the day the server sent and
	 * the day after it. A device clock set wrong must not move the earliest day: earlier
	 * would let the shopper pick a day TackQuote refuses, later would refuse a valid one.
	 * The day after covers a page left open past the seller's midnight.
	 *
	 * @param {string} zone The seller's IANA time zone.
	 * @param {string} sent The seller's today as the server sent it (`YYYY-MM-DD`).
	 * @param {Date}   now  The instant.
	 * @return {string} `YYYY-MM-DD`.
	 */
	function sellerToday( zone, sent, now ) {
		const valid = DAY.test( sent ) ? sent : null;
		const live = dayIn( zone, now );
		if ( ! valid ) {
			return live;
		}
		if ( ! live || live < valid ) {
			return valid;
		}
		const next = nextDay( valid );
		return live > next ? next : live;
	}

	function sellerCalendar( form, now ) {
		const inputs = form.querySelectorAll( 'input[data-tack-seller-today]' );
		Array.prototype.forEach.call( inputs, function ( input ) {
			const sent = input.getAttribute( 'data-tack-seller-today' );
			const today = sellerToday(
				input.getAttribute( 'data-tack-seller-zone' ),
				sent,
				now
			);
			if ( ! today ) {
				return;
			}
			input.min = today;
			const hint = document.getElementById( input.id + '_calendar' );
			if ( ! hint ) {
				return;
			}
			const differs = today !== localDay( now );
			hint.hidden = ! differs;
			// A theme's `.description{display:block}` outranks `[hidden]`; the inline style does not lose.
			if ( hint.style ) {
				hint.style.display = differs ? '' : 'none';
			}
			if ( differs ) {
				const label = hint.querySelector( '.tackquote-seller-day' );
				if ( label ) {
					label.textContent = dayLabel( today );
				}
			}
			// A hidden node named by aria-describedby is still announced: link it only while shown.
			const described = ( input.getAttribute( 'aria-describedby' ) || '' )
				.split( ' ' )
				.filter( function ( id ) {
					return id && id !== hint.id;
				} );
			if ( differs ) {
				described.push( hint.id );
			}
			if ( described.length ) {
				input.setAttribute( 'aria-describedby', described.join( ' ' ) );
			} else {
				input.removeAttribute( 'aria-describedby' );
			}
		} );
	}

	/**
	 * Wire one form: the seller calendar and the conditional fields. Exposed as
	 * `window.TackQuoteForms.bind` (1.12.0) for the quote modal, which builds its
	 * seller-designed form after this script has run. Binding a form twice is a no-op.
	 *
	 * @param {HTMLFormElement} form The form.
	 */
	function bind( form ) {
		if ( ! form || form.getAttribute( 'data-tack-bound' ) === '1' ) {
			if ( form ) {
				apply( form );
			}
			return;
		}
		form.setAttribute( 'data-tack-bound', '1' );
		sellerCalendar( form, new Date() );
		form.addEventListener( 'focusin', function ( event ) {
			// Re-read on the way into a date field: the seller's day may have turned.
			if (
				event.target &&
				event.target.hasAttribute &&
				event.target.hasAttribute( 'data-tack-seller-today' )
			) {
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
	}

	if ( typeof window !== 'undefined' ) {
		window.TackQuoteForms = { bind, apply };
	}

	function init() {
		Array.prototype.forEach.call(
			document.querySelectorAll( 'form[data-tack-form]' ),
			bind
		);
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
