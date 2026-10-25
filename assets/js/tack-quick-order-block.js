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
	var __ = wp.i18n && wp.i18n.__ ? wp.i18n.__ : function ( text ) {
		return text;
	};
	var useBlockProps = wp.blockEditor && wp.blockEditor.useBlockProps ? wp.blockEditor.useBlockProps : null;
	var ServerSideRender = wp.serverSideRender || null;

	wp.blocks.registerBlockType( 'tackquote/quick-order', {
		apiVersion: 3,
		title: __( 'TackQuote quick order', 'tackquote' ),
		description: __( 'Buyers enter SKUs and quantities, then add them to the cart or to the quote list.', 'tackquote' ),
		category: 'woocommerce',
		icon: 'list-view',
		supports: { html: false, align: [ 'wide', 'full' ] },
		edit: function () {
			var props = useBlockProps ? useBlockProps() : {};
			return el(
				'div',
				props,
				ServerSideRender
					? el( ServerSideRender, { block: 'tackquote/quick-order' } )
					: el( 'p', null, __( 'TackQuote quick order', 'tackquote' ) )
			);
		},
		save: function () {
			return null;
		},
	} );
}() );
