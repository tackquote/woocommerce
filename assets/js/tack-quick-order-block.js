/**
 * "TackQuote quick order" block (1.12.0), editor side.
 *
 * Plain JavaScript, no build step: `wp.blocks`, `wp.element`, `wp.blockEditor` and
 * `wp.serverSideRender` are WordPress's own globals. The block is dynamic: the
 * front end is rendered by Tack_Quick_Order::render() (the same markup as the
 * `[tackquote_quick_order]` shortcode), and the editor shows that render.
 */
( function () {
	'use strict';

	var wp = window.wp || {};
	if ( ! wp.blocks || ! wp.element ) {
		return;
	}
	var el = wp.element.createElement;
	var useBlockProps = wp.blockEditor && wp.blockEditor.useBlockProps ? wp.blockEditor.useBlockProps : null;
	var ServerSideRender = wp.serverSideRender || null;

	wp.blocks.registerBlockType( 'tackquote/quick-order', {
		// Title, description, icon, category and supports come from the server-side
		// registration (Tack_Quick_Order::register_block(), translated there), which
		// the editor bootstraps for every block registered in PHP.
		apiVersion: 3,
		edit: function () {
			var props = useBlockProps ? useBlockProps() : {};
			return el(
				'div',
				props,
				ServerSideRender
					? el( ServerSideRender, { block: 'tackquote/quick-order' } )
					: el( 'p', null, 'TackQuote' )
			);
		},
		save: function () {
			return null;
		},
	} );
}() );
