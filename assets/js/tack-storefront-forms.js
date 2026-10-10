/**
 * Conditional fields for the TackQuote storefront application forms.
 *
 * A field carrying data-tack-show-if-field / data-tack-show-if-equals is shown only
 * while the named controller field holds that value — the same rule the server
 * applies (a checkbox compares true/false, everything else its trimmed value), so
 * what the shopper sees is what the server will keep. Without this script every
 * field shows and the server simply drops the answers to hidden ones.
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

	function init() {
		var forms = document.querySelectorAll( 'form[data-tack-form]' );
		Array.prototype.forEach.call( forms, function ( form ) {
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
