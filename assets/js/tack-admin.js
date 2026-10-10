/**
 * TackQuote settings screen: conditional rows and the destructive-action confirm.
 *
 * Progressive enhancement only. Without this script every row is visible and every
 * form still works; hiding a row never disables its inputs, so what is saved never
 * depends on what is shown.
 */
( function () {
	'use strict';

	/**
	 * Current value of a named control inside a form.
	 *
	 * Checkboxes rendered by the settings page post a hidden "no" before the real
	 * box, so an unticked box reads "no" and a ticked one reads its own value.
	 *
	 * @param {HTMLFormElement} form Form.
	 * @param {string}          name Control name.
	 * @return {string|null} Value, or null when the form has no such control.
	 */
	function valueOf( form, name ) {
		var value = null;
		var i;
		var el;
		for ( i = 0; i < form.elements.length; i++ ) {
			el = form.elements[ i ];
			if ( el.name !== name ) {
				continue;
			}
			if ( 'radio' === el.type || 'checkbox' === el.type ) {
				if ( el.checked ) {
					value = el.value;
				} else if ( null === value && 'checkbox' === el.type ) {
					value = 'no';
				}
			} else if ( 'hidden' === el.type ) {
				if ( null === value ) {
					value = el.value;
				}
			} else {
				value = el.value;
			}
		}
		return value;
	}

	/**
	 * Show or hide every conditional element in a form.
	 *
	 * A row may carry several markers; it is shown only when all of them match.
	 *
	 * @param {HTMLFormElement} form Form.
	 */
	function apply( form ) {
		var markers = form.querySelectorAll( '[data-tack-when]' );
		var targets = [];
		var visible = [];
		var i;
		var marker;
		var target;
		var current;
		var wanted;
		var index;

		for ( i = 0; i < markers.length; i++ ) {
			marker = markers[ i ];
			current = valueOf( form, marker.getAttribute( 'data-tack-when' ) );
			if ( null === current ) {
				// The controller is on another tab: leave the element alone.
				continue;
			}
			wanted = ( marker.getAttribute( 'data-tack-when-value' ) || '' ).split( ' ' );
			target = marker.classList.contains( 'tack-when--row' ) ? marker.closest( 'tr' ) : marker;
			if ( ! target ) {
				continue;
			}
			index = targets.indexOf( target );
			if ( -1 === index ) {
				targets.push( target );
				visible.push( true );
				index = targets.length - 1;
			}
			if ( -1 === wanted.indexOf( current ) ) {
				visible[ index ] = false;
			}
		}

		for ( i = 0; i < targets.length; i++ ) {
			targets[ i ].hidden = ! visible[ i ];
		}
	}

	function init() {
		var forms = document.querySelectorAll( '.tack-admin form.tack-form' );
		var confirmForms = document.querySelectorAll( '.tack-admin form[data-tack-confirm]' );
		var i;

		for ( i = 0; i < forms.length; i++ ) {
			( function ( form ) {
				apply( form );
				form.addEventListener( 'change', function () {
					apply( form );
				} );
			} )( forms[ i ] );
		}

		for ( i = 0; i < confirmForms.length; i++ ) {
			confirmForms[ i ].addEventListener( 'submit', function ( event ) {
				// eslint-disable-next-line no-alert -- a native confirm is the accessible, dependency-free choice here.
				if ( ! window.confirm( this.getAttribute( 'data-tack-confirm' ) ) ) {
					event.preventDefault();
				}
			} );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
