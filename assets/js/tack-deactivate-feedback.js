/**
 * TackQuote: the optional "Why are you deactivating?" dialog on Plugins (1.16.0).
 *
 * Loaded on plugins.php only, and only when the server takes the answer
 * (Tack_Lifecycle::should_ask()). "Deactivate" on this plugin opens the dialog;
 * "Skip and deactivate" (focused first) follows WordPress's own deactivation link
 * unchanged; Cancel or Esc closes it and deactivates nothing; "Send and deactivate"
 * posts the form to admin-post.php. Without `<dialog>` support this script does
 * nothing and the link works as it always did.
 */
( function ( root ) {
	'use strict';

	function setup( doc, win ) {
		var link = doc.querySelector( 'a[data-tackquote-deactivate]' );
		var dialog = doc.getElementById( 'tackquote-deactivate-feedback' );
		if ( ! link || ! dialog || typeof dialog.showModal !== 'function' ) {
			return false;
		}
		var href = link.getAttribute( 'href' );
		var skip = dialog.querySelector( '[data-tackquote-skip]' );
		var cancel = dialog.querySelector( '[data-tackquote-cancel]' );
		var form = dialog.querySelector( 'form' );
		if ( ! href || ! skip || ! cancel || ! form ) {
			return false;
		}

		link.addEventListener( 'click', function ( event ) {
			// A modified click (new tab, new window) keeps the browser's own behaviour.
			if ( event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ) {
				return;
			}
			event.preventDefault();
			dialog.showModal();
			skip.focus();
		} );
		skip.addEventListener( 'click', function () {
			win.location.href = href;
		} );
		cancel.addEventListener( 'click', function () {
			dialog.close();
		} );
		form.addEventListener( 'submit', function () {
			// One answer per click: a second press would post it twice.
			var buttons = form.querySelectorAll( 'button' );
			for ( var i = 0; i < buttons.length; i++ ) {
				buttons[ i ].disabled = true;
			}
		} );
		return true;
	}

	if ( typeof module === 'object' && module.exports ) {
		module.exports = { setup: setup };
	} else if ( root && root.document ) {
		if ( root.document.readyState === 'loading' ) {
			root.document.addEventListener( 'DOMContentLoaded', function () {
				setup( root.document, root );
			} );
		} else {
			setup( root.document, root );
		}
	}
} )( typeof window !== 'undefined' ? window : null );
