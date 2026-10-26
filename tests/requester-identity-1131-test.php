<?php
/**
 * 1.13.1 (TackQuote Woo demo P3): a signed-in customer linked to their TackQuote
 * buyer was flagged "Requester not verified" on every quote request, because the
 * request carried only the typed email. To a server that lists
 * `requester_identity`, the request now carries `buyerExternalId`, the signed-in
 * WordPress user id; TackQuote counts it as verified only when that user is the
 * one the seller linked to the buyer the email resolves to.
 *
 * Mutation proof: send it to a server without the capability, send it for a guest,
 * or drop the call in handle_request() -> red.
 *
 * Runs from tests/run.php after store-settings-test.php.
 *
 * @package TackQuotes
 */

defined( 'ABSPATH' ) || exit;

$ri_base = array(
	'buyerEmail' => 'demo.buyer@example.test',
	'lineItems'  => array( array( 'name' => 'Tote', 'quantity' => 10 ) ),
);

$ri_sent = Tack_Widget::with_requester_identity( $ri_base, true, 2 );
check( 'requester identity: a signed-in user is named to a requester_identity server', isset( $ri_sent['buyerExternalId'] ) && '2' === $ri_sent['buyerExternalId'] );
check( 'requester identity: nothing else in the request changes', array_diff_key( $ri_sent, array( 'buyerExternalId' => 1 ) ) === $ri_base );
check( 'requester identity: an older server never gets the field (its whitelist would refuse the request)', Tack_Widget::with_requester_identity( $ri_base, false, 2 ) === $ri_base );
check( 'requester identity: a guest (user id 0) sends nothing', Tack_Widget::with_requester_identity( $ri_base, true, 0 ) === $ri_base );
check( 'requester identity: a negative or junk id sends nothing', Tack_Widget::with_requester_identity( $ri_base, true, -3 ) === $ri_base && Tack_Widget::with_requester_identity( $ri_base, true, 'abc' ) === $ri_base );

$ri_src = (string) file_get_contents( TACK_QUOTES_DIR . 'includes/class-tack-widget.php' );
check(
	'requester identity: handle_request() adds the signed-in user only for a requester_identity server',
	false !== strpos( $ri_src, "\$payload = self::with_requester_identity( \$payload, \$client->supports( 'requester_identity' ), get_current_user_id() );" )
);
