<?php
/**
 * Sends WooCommerce orders to the Tack API on creation and status change.
 *
 * Every push is DEFERRED. The three hooks below all fire inside a request the shopper or
 * the administrator is waiting on — two of them inside checkout itself — and the push is an
 * outbound HTTP call with a 20 second timeout. Doing that inline made "never blocks
 * checkout", which this plugin's readme and settings screen both claim, false: a slow or
 * unreachable TackQuote added up to 20 seconds to the customer's place-order request, and a
 * PHP timeout there can leave an order paid and unrecorded.
 *
 * @package TackQuotes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Order sync handler.
 */
class Tack_Order_Sync {

	/**
	 * Action fired to perform one deferred order push.
	 */
	const SYNC_HOOK = 'tack_quotes_sync_order';

	/**
	 * Action Scheduler group, so these jobs are identifiable in WooCommerce → Status →
	 * Scheduled Actions.
	 */
	const SYNC_GROUP = 'tackquote';

	/**
	 * Order meta holding the idempotency key of the last state successfully pushed.
	 */
	const SYNC_KEY_META = '_tack_quotes_sync_key';

	/**
	 * Action that re-queues the orders skipped while TackQuote refused order sync.
	 */
	const REQUEUE_HOOK = 'tack_quotes_requeue_unsynced';

	/**
	 * Most orders one re-queue will look at. Bounded so a long outage on a busy store
	 * cannot enqueue thousands of jobs at once; what is left over is logged, and each of
	 * those orders is still pushed the next time it changes.
	 */
	const REQUEUE_LIMIT = 200;

	/**
	 * How far back a re-queue reaches at most, whatever the block's start time says.
	 */
	const REQUEUE_MAX_AGE = 2592000; // 30 days.

	/**
	 * Whether outbound order sync is switched on.
	 *
	 * Single source of truth for the default, because it is consulted both when deciding
	 * whether to register the hooks at all and again inside the deferred worker.
	 *
	 * Defaults to OFF. This feature sends personal data — the buyer's email address, name and
	 * company, plus order totals and line items — to a third-party service, and a plugin must
	 * not start doing that on activation without the merchant choosing it. Stores that already
	 * have the option stored keep whatever they chose; `add_option()` never overwrites, so
	 * this changes nothing for an existing install.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return 'yes' === get_option( 'tack_quotes_enable_order_sync', 'no' );
	}

	/**
	 * Hook registration.
	 */
	public function init() {
		/*
		 * Both creation hooks are registered, and they are not redundant with each other:
		 * `woocommerce_checkout_order_processed` does not fire for Store API requests, which
		 * is exactly why WooCommerce added `woocommerce_store_api_checkout_order_processed`
		 * as its block-checkout equivalent. A store running the block checkout fires only the
		 * second; a store running the classic shortcode checkout fires only the first.
		 *
		 * They are also not redundant with the status hook, which is a claim worth being
		 * precise about because it looks true and is not. `WC_Order::set_status()` only
		 * records a transition when `true === $this->object_read && ! empty( $result['from'] )`
		 * (woocommerce/includes/class-wc-order.php), so a brand-new order created straight
		 * into `pending` fires no status change at all. Dropping the creation hooks would
		 * therefore silently stop syncing every order that a gateway leaves at `pending` —
		 * off-site redirect gateways that the customer abandons, most obviously — and those
		 * are precisely the leads a quoting platform wants.
		 *
		 * What made the pair a defect was not the coverage, it was the duplication: on one
		 * classic checkout both fired, and the payload carried nothing that let TackQuote
		 * recognise the second push as the same event. That is fixed by deferral plus the
		 * idempotency key below, not by deleting a hook.
		 */
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'on_new_order' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'on_new_order' ), 20, 1 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 20, 4 );
	}

	/**
	 * Register the deferred worker. Runs on a background request, where a 20s call is free.
	 *
	 * Deliberately separate from init() and registered even when order sync is switched OFF.
	 * A job queued while it was on has to find a callback when it is claimed, or Action
	 * Scheduler records it as a permanently failed action with no handler — noise in the
	 * merchant's Scheduled Actions list describing a decision they already made. The worker
	 * re-checks the toggle itself and returns without sending anything.
	 */
	public function register_worker() {
		// Two accepted args: a job queued by a trigger carries ( $order_id ); one that
		// schedule_retry() put back after a 429 carries ( $order_id, RETRY_MARKER ). Jobs
		// queued by 1.8.1 and earlier carry one and still match (PHP fills the default).
		add_action( self::SYNC_HOOK, array( $this, 'run_sync' ), 10, 2 );
		// Registered with the worker, not init(): a merchant who switches sync off while it
		// is refused still deserves to know why orders stopped arriving.
		add_action( 'admin_notices', array( 'Tack_Sync_Gate', 'render_admin_notice' ) );

		// A refusal lifted (a push succeeded, or a different key was saved): re-send what
		// was skipped meanwhile, off the request that lifted it.
		add_action( Tack_Sync_Gate::UNBLOCKED_ACTION, array( $this, 'schedule_requeue' ), 10, 1 );
		add_action( self::REQUEUE_HOOK, array( $this, 'requeue_unsynced' ), 10, 1 );
		add_action( 'update_option_tack_quotes_api_key', array( 'Tack_Sync_Gate', 'on_api_key_changed' ), 10, 2 );
	}

	/**
	 * Second argument of a job that schedule_retry() put back after a 429.
	 *
	 * Part of the job's args on purpose. Action Scheduler's `$unique` compares hook, group
	 * AND args against every PENDING and RUNNING action (ActionScheduler_DBStore::
	 * build_where_clause_for_insert, read from the vendor source), and this job is
	 * scheduled from INSIDE the running job for the same order: with identical args the
	 * insert would be refused as a duplicate of the very action that is scheduling it, and
	 * nothing would be put back. Distinct args make the retry unique among retries only,
	 * which is the dedupe wanted: at most one pending retry per order.
	 */
	const RETRY_MARKER = 'retry';

	/**
	 * Most seconds of spread added to a retry's time, so the orders held by one throttle
	 * do not all reappear in the same second it ends.
	 */
	const RETRY_SPREAD = 30;

	/**
	 * Put one order back for after a throttle ends.
	 *
	 * Never earlier than `$until`: that is when the server said it would accept a request
	 * again. The spread only ever lands later.
	 *
	 * @param int $order_id Order id.
	 * @param int $until    Unix time the block lifts.
	 */
	public function schedule_retry( $order_id, $until ) {
		$when = max( (int) $until, time() + 1 ) + wp_rand( 0, self::RETRY_SPREAD );
		$args = array( (int) $order_id, self::RETRY_MARKER );
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( $when, self::SYNC_HOOK, $args, self::SYNC_GROUP, true );
			return;
		}
		if ( ! wp_next_scheduled( self::SYNC_HOOK, $args ) ) {
			wp_schedule_single_event( $when, self::SYNC_HOOK, $args );
		}
	}

	/**
	 * Queue one re-queue job for the orders skipped since `$since`.
	 *
	 * @param int $since Unix time pushes started being held.
	 */
	public function schedule_requeue( $since ) {
		$args = array( (int) $since );
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::REQUEUE_HOOK, $args, self::SYNC_GROUP, true );
			return;
		}
		if ( ! wp_next_scheduled( self::REQUEUE_HOOK, $args ) ) {
			wp_schedule_single_event( time() + 1, self::REQUEUE_HOOK, $args );
		}
	}

	/**
	 * Re-queue the orders modified since `$since`. Each goes through the normal worker,
	 * which skips an order whose current state was already accepted (its sync key), so
	 * re-queuing an order that did get through costs one meta read and no request.
	 *
	 * `date_modified => '>' . timestamp` is the documented wc_get_orders() form
	 * (woocommerce/docs/features/orders/wc-get-orders.md).
	 *
	 * @param int $since Unix time pushes started being held.
	 */
	public function requeue_unsynced( $since ) {
		if ( ! self::is_enabled() || ! function_exists( 'wc_get_orders' ) ) {
			return;
		}
		$floor = max( (int) $since - 60, time() - self::REQUEUE_MAX_AGE );
		$ids   = wc_get_orders(
			array(
				'type'          => 'shop_order',
				'date_modified' => '>' . $floor,
				'orderby'       => 'modified',
				'order'         => 'ASC',
				'limit'         => self::REQUEUE_LIMIT + 1,
				'return'        => 'ids',
			)
		);
		$ids   = is_array( $ids ) ? $ids : array();
		if ( count( $ids ) > self::REQUEUE_LIMIT && function_exists( 'wc_get_logger' ) && wc_get_logger() ) {
			wc_get_logger()->warning(
				sprintf(
					'TackQuote order sync resumed: re-sending the %d oldest orders changed since it was refused; later ones are sent when they next change.',
					self::REQUEUE_LIMIT
				),
				array( 'source' => 'tackquote' )
			);
		}
		foreach ( array_slice( $ids, 0, self::REQUEUE_LIMIT ) as $order_id ) {
			$this->enqueue( (int) $order_id );
		}
	}

	/**
	 * New order created.
	 *
	 * @param int|WC_Order $order Order id or object.
	 */
	public function on_new_order( $order ) {
		$this->enqueue( $order );
	}

	/**
	 * Order status changed.
	 *
	 * @param int      $order_id Order id.
	 * @param string   $from     Old status.
	 * @param string   $to       New status.
	 * @param WC_Order $order    Order object.
	 */
	public function on_status_changed( $order_id, $from, $to, $order ) {
		$this->enqueue( $order );
	}

	/**
	 * Queue a deferred push for one order.
	 *
	 * Nothing about the order is captured here beyond its id. The worker re-reads the order
	 * when it runs, and that is what collapses a classic checkout's two triggers into one
	 * push: by the time either job runs the order is in its settled status, both jobs compute
	 * the same payload and the same key, and the second one finds the key already recorded
	 * and does nothing.
	 *
	 * @param int|WC_Order $order Order id or object.
	 */
	private function enqueue( $order ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$order_id = (int) $order->get_id();
		$args     = array( $order_id );

		/*
		 * Action Scheduler ships inside WooCommerce, and every hook that reaches this method
		 * fires long after `init`, which is the point Action Scheduler asks callers to wait
		 * for. It is still probed rather than assumed: a host that has interfered with it, or
		 * a WooCommerce old enough not to bundle it, must degrade instead of fatalling. The
		 * fallback is WP-Cron, which still gets the call off the checkout request — later than
		 * Action Scheduler would, but not inside the customer's page load.
		 *
		 * $unique = true drops a second PENDING job for the same order, which is the common
		 * case here (creation hook, then the gateway's status change, milliseconds apart).
		 */
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::SYNC_HOOK, $args, self::SYNC_GROUP, true );
			return;
		}

		if ( ! wp_next_scheduled( self::SYNC_HOOK, $args ) ) {
			wp_schedule_single_event( time() + 1, self::SYNC_HOOK, $args );
		}
	}

	/**
	 * Perform one deferred push. Failures are logged, never fatal.
	 *
	 * @param int    $order_id Order id.
	 * @param string $reason   RETRY_MARKER when this job was put back by schedule_retry();
	 *                         '' for a job queued by an order trigger. Informational: both
	 *                         paths do exactly the same work.
	 */
	public function run_sync( $order_id, $reason = '' ) {
		unset( $reason );
		// Re-checked at run time: a merchant may have switched sync off between the order
		// being placed and this job being claimed, and the answer they expect from that switch
		// is "stop sending", including for work already queued.
		if ( ! self::is_enabled() ) {
			return;
		}

		$order = wc_get_order( absint( $order_id ) );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$payload = $this->build_payload( $order );
		$key     = $this->idempotency_key( $order, $payload );

		// Same state already accepted by TackQuote — nothing to say. This is what stops the
		// duplicate pushes, and also stops every later admin save that does not actually
		// change the order from generating traffic.
		if ( $key === (string) $order->get_meta( self::SYNC_KEY_META ) ) {
			return;
		}

		/*
		 * TackQuote already refused this key in a way no retry can change (a missing
		 * `orders:write` scope, a revoked key, a lapsed subscription), or asked us to slow
		 * down. Do not send: the order is left unmarked, so its next trigger pushes it once
		 * the merchant has acted. See Tack_Sync_Gate for the classification and why a
		 * refusal used to turn into a request every few seconds.
		 */
		$api_key = (string) get_option( 'tack_quotes_api_key', '' );
		$now     = time();
		$held    = Tack_Sync_Gate::active_block( $api_key, $now );
		if ( null !== $held ) {
			/*
			 * A THROTTLE ends on its own, so this order is put back for when it does;
			 * otherwise an order held by the last throttle of the day would wait for the
			 * next order's trigger, or for a successful push to fire the re-queue, and a
			 * quiet store has neither. A TERMINAL block is not rescheduled per order: the
			 * merchant has to act, the next trigger re-probes once an hour, and the
			 * re-queue on unblock reaches back to `since` for everything skipped.
			 */
			if ( 'throttled' === ( $held['kind'] ?? '' ) ) {
				$this->schedule_retry( (int) $order->get_id(), (int) ( $held['until'] ?? $now ) );
			}
			return;
		}

		$payload['idempotencyKey'] = $key;

		$result = ( new Tack_Api_Client() )->sync_order( $payload, $key );
		if ( is_wp_error( $result ) ) {
			$block = Tack_Sync_Gate::record_failure( $result, $api_key, $now );
			if ( is_array( $block ) && 'throttled' === ( $block['kind'] ?? '' ) ) {
				// Honour what the server asked (Retry-After, lengthened by the gate's
				// back-off on a repeat) by sending THIS order again after it, not by
				// hoping for another trigger.
				$this->schedule_retry( (int) $order->get_id(), (int) $block['until'] );
			}
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error(
					sprintf(
						/* translators: 1: order id, 2: error message */
						'TackQuote order sync failed for order %1$d: %2$s',
						(int) $order->get_id(),
						$result->get_error_message()
					),
					array( 'source' => 'tackquote' )
				);
			}
			return;
		}

		// Recorded only on success, so a failed push is retried by the next trigger rather
		// than being suppressed as a duplicate. Written through the order CRUD, not
		// update_post_meta(), because with HPOS enabled orders do not live in wp_postmeta.
		$order->update_meta_data( self::SYNC_KEY_META, $key );
		$order->save_meta_data();
		Tack_Sync_Gate::record_success();
	}

	/**
	 * Normalize an order for the Tack API.
	 *
	 * This used to send eleven fields — id, number, status, currency, total, the buyer's
	 * email/name/company, and per-line sku/name/quantity/total. A merchant testing the
	 * integration in production described the result as "only getting order id and the
	 * orders related information, no name, not address information, nothing", and they were
	 * right about the part that matters: there was no address of any kind in the payload, no
	 * phone, no shipping destination, no payment method, and no tax/shipping/discount
	 * breakdown. An order that cannot be shipped, invoiced or reconciled from what was synced
	 * is not a synced order.
	 *
	 * Everything below is read through WooCommerce's own getters rather than assembled from
	 * meta or arithmetic. That is not stylistic: `get_subtotal()`, `get_discount_total()`,
	 * `get_shipping_total()` and `get_total_tax()` each apply the store's rounding and
	 * `wc_get_price_decimals()` (see abstract-wc-order.php), and they read from the order's
	 * own props under HPOS exactly as they do under post storage, so nothing here needs to
	 * know which order table the store uses.
	 *
	 * The five original `buyer*` keys are kept alongside the new `billing` object even though
	 * they duplicate it. A store can update this plugin before the receiving API is updated,
	 * or the other way round; keeping the flat keys means neither ordering loses the buyer.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	private function build_payload( $order ) {
		$billing  = $this->address_payload( $order, 'billing' );
		$shipping = $this->address_payload( $order, 'shipping' );

		$payload = array(
			'externalOrderId' => (string) $order->get_id(),
			'orderNumber'     => $order->get_order_number(),
			'status'          => $order->get_status(),
			'currency'        => $order->get_currency(),

			/*
			 * The real subtotal, which is what the receiving end had been missing most
			 * expensively: it recorded `subtotal = total`, so the moment a store charged tax
			 * or shipping every synced order claimed its goods cost what the customer paid.
			 * get_subtotal() is line items excluding tax, shipping, fees and coupons — the
			 * figure the other four combine with to reach the total.
			 */
			'subtotal'        => (float) $order->get_subtotal(),
			'discountTotal'   => (float) $order->get_discount_total(),
			'shippingTotal'   => (float) $order->get_shipping_total(),
			'taxTotal'        => (float) $order->get_total_tax(),
			'total'           => (float) $order->get_total(),

			// Retained flat buyer identity — see the docblock.
			'buyerEmail'      => $order->get_billing_email(),
			'buyerName'       => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'buyerCompany'    => $order->get_billing_company(),
			'buyerPhone'      => $order->get_billing_phone(),

			'billing'         => $billing,
			'shipping'        => $shipping,

			'payment'         => $this->payment_payload( $order ),

			/*
			 * 0 for a guest checkout, which WooCommerce treats as a real value rather than an
			 * absence, so it is sent as-is instead of being nulled: "this order was placed by
			 * someone with no account" is information the receiving end can act on.
			 */
			'customerId'      => (int) $order->get_customer_id(),
			'customerNote'    => $order->get_customer_note(),
			'couponCodes'     => array_values( $order->get_coupon_codes() ),
			'poNumber'        => $this->po_number( $order ),

			'lineItems'       => $this->line_items_payload( $order ),
			'shippingLines'   => $this->shipping_lines_payload( $order ),
			'feeLines'        => $this->fee_lines_payload( $order ),

			'createdAt'       => self::date_atom( $order->get_date_created() ),
			'modifiedAt'      => self::date_atom( $order->get_date_modified() ),
			'paidAt'          => self::date_atom( $order->get_date_paid() ),
			'completedAt'     => self::date_atom( $order->get_date_completed() ),

			'source'          => 'woocommerce',
		);

		return $payload;
	}

	/**
	 * The buyer's purchase-order number, if this store collects one.
	 *
	 * WooCommerce core has NO purchase-order field — there is nothing to read, and TackQuote's
	 * `b2b_orders.po_number` column would otherwise stay empty forever. Rather than guess at
	 * the meta key some B2B extension might use (a guess that looks correct and silently
	 * returns nothing is worse than an empty field), this is a documented filter.
	 *
	 * A store whose checkout collects a PO wires it up once:
	 *
	 *     add_filter(
	 *         'tack_quotes_order_po_number',
	 *         function ( $po, $order ) {
	 *             return $order->get_meta( '_my_checkout_po_field' );
	 *         },
	 *         10,
	 *         2
	 *     );
	 *
	 * @param WC_Order $order Order.
	 * @return string Empty when the store collects no PO number.
	 */
	private function po_number( $order ) {
		/**
		 * Filters the purchase-order number reported for an order.
		 *
		 * @since 1.5.0
		 *
		 * @param string   $po_number Purchase-order number. Empty by default.
		 * @param WC_Order $order     The order being synced.
		 */
		$po_number = apply_filters( 'tack_quotes_order_po_number', '', $order );

		return is_scalar( $po_number ) ? trim( (string) $po_number ) : '';
	}

	/**
	 * One address, as WooCommerce holds it.
	 *
	 * Read field by field through the typed getters rather than via `get_address()`, which
	 * returns WooCommerce's own snake_case keys and would make the wire contract change shape
	 * if WooCommerce ever changed its internal address array.
	 *
	 * Empty fields are dropped rather than sent as ''. An address is a set of optional lines —
	 * most orders have no `address_2`, most non-US orders have no `state` — and sending the
	 * blanks would mean the receiving end cannot tell "the shopper left this out" from "this
	 * plugin does not know about that field", which is precisely the ambiguity that made the
	 * original payload hard to diagnose.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $type  'billing' or 'shipping'.
	 * @return array
	 */
	private function address_payload( $order, $type ) {
		$is_billing = 'billing' === $type;

		$fields = array(
			'firstName' => $is_billing ? $order->get_billing_first_name() : $order->get_shipping_first_name(),
			'lastName'  => $is_billing ? $order->get_billing_last_name() : $order->get_shipping_last_name(),
			'company'   => $is_billing ? $order->get_billing_company() : $order->get_shipping_company(),
			'address1'  => $is_billing ? $order->get_billing_address_1() : $order->get_shipping_address_1(),
			'address2'  => $is_billing ? $order->get_billing_address_2() : $order->get_shipping_address_2(),
			'city'      => $is_billing ? $order->get_billing_city() : $order->get_shipping_city(),
			'state'     => $is_billing ? $order->get_billing_state() : $order->get_shipping_state(),
			'postcode'  => $is_billing ? $order->get_billing_postcode() : $order->get_shipping_postcode(),
			'country'   => $is_billing ? $order->get_billing_country() : $order->get_shipping_country(),
		);

		if ( $is_billing ) {
			// Only billing carries an email in WooCommerce's data model.
			$fields['email'] = $order->get_billing_email();
			$fields['phone'] = $order->get_billing_phone();
		} else {
			/*
			 * `WC_Order::get_shipping_phone()` is `@since 5.6.0` (includes/class-wc-order.php).
			 * This plugin declares `WC requires at least: 6.0`, so it is always present on a
			 * store that meets the floor — but WordPress does not ENFORCE that header, it only
			 * warns, so a merchant on WooCommerce 5.x can still activate this plugin. A fatal
			 * error inside a background sync job would be invisible to them until orders
			 * stopped arriving, so the call is probed rather than trusted.
			 */
			$fields['phone'] = method_exists( $order, 'get_shipping_phone' ) ? $order->get_shipping_phone() : '';
		}

		return self::drop_blanks( $fields );
	}

	/**
	 * Payment facts for one order.
	 *
	 * `needsPayment` is WooCommerce's own answer to "is this order still awaiting money",
	 * which is a status-and-total question rather than a gateway one (`WC_Order::needs_payment()`
	 * checks the total is above zero and the status is in `woocommerce_valid_order_statuses_for_payment`).
	 * Sending it means the receiving end does not have to re-derive that rule from a status
	 * string, and cannot get it wrong for a store with custom statuses.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	private function payment_payload( $order ) {
		return array(
			'method'        => $order->get_payment_method(),
			'methodTitle'   => $order->get_payment_method_title(),
			'transactionId' => $order->get_transaction_id(),
			'paidAt'        => self::date_atom( $order->get_date_paid() ),
			'needsPayment'  => (bool) $order->needs_payment(),
		);
	}

	/**
	 * Line items, including what makes a variation identifiable.
	 *
	 * A payload that says `name: "T-Shirt", quantity: 2` for an order of two Large/Blue
	 * shirts has lost the order. The variation id and the item meta are what carry the
	 * chosen options, so both are sent.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	private function line_items_payload( $order ) {
		$items = array();

		foreach ( $order->get_items() as $item ) {
			// $item is a WC_Order_Item_Product; get_product() returns false for a deleted
			// product, which is why the SKU below is guarded rather than read directly.
			$product      = $item->get_product();
			$variation_id = (int) $item->get_variation_id();

			$items[] = array(
				'externalProductId' => (string) $item->get_product_id(),
				// 0 means "not a variation" in WooCommerce; sent as null so the receiving
				// end does not store a product reference that points at nothing.
				'externalVariantId' => $variation_id > 0 ? (string) $variation_id : null,
				'sku'               => $product instanceof WC_Product ? $product->get_sku() : '',
				'name'              => $item->get_name(),
				'quantity'          => (int) $item->get_quantity(),

				/*
				 * subtotal is the line BEFORE coupons, total is after — WooCommerce keeps
				 * both, and the difference is the per-line discount. Sending only `total`
				 * (which is what this did) makes a discounted order indistinguishable from
				 * one that was simply cheaper.
				 */
				'subtotal'          => (float) $item->get_subtotal(),
				'total'             => (float) $item->get_total(),
				'tax'               => (float) $item->get_total_tax(),
				'meta'              => $this->item_meta_payload( $item ),
			);
		}

		return $items;
	}

	/**
	 * The chosen variation attributes and any other item meta, as label/value pairs.
	 *
	 * `get_formatted_meta_data()` is used rather than `get_meta_data()` because it is what
	 * resolves a taxonomy attribute from its stored slug to the term name a human recognises
	 * — `pa_color: blue` becomes `Color: Blue` (class-wc-order-item.php looks the term up
	 * with `get_term_by()`).
	 *
	 * Two deliberate departures from how WooCommerce uses it for display:
	 *
	 * - `$include_all = true`. With the default `false` it SKIPS any attribute whose value is
	 *   already inside the product name, because showing "T-Shirt - Blue" next to "Color:
	 *   Blue" is redundant on screen. For a data sync it is the opposite: a value buried in a
	 *   display string is not a field, and the receiving end has no reliable way to parse it
	 *   back out.
	 *
	 * - `display_value` is stripped of markup. WooCommerce runs it through `wpautop()` and
	 *   `make_clickable()` for output, so the raw value arrives wrapped in `<p>` tags.
	 *
	 * @param WC_Order_Item $item Order item.
	 * @return array
	 */
	private function item_meta_payload( $item ) {
		if ( ! method_exists( $item, 'get_formatted_meta_data' ) ) {
			return array();
		}

		$meta = array();
		foreach ( $item->get_formatted_meta_data( '_', true ) as $entry ) {
			$key   = trim( wp_strip_all_tags( (string) $entry->display_key ) );
			$value = trim( wp_strip_all_tags( (string) $entry->display_value ) );
			if ( '' === $key && '' === $value ) {
				continue;
			}
			$meta[] = array(
				'key'   => $key,
				'value' => $value,
			);
		}

		return $meta;
	}

	/**
	 * Shipping lines — the method the customer chose and what it cost.
	 *
	 * `$order->get_shipping_total()` is the money; this is the method, which is what a
	 * fulfilment team actually needs. Read from the shipping line items rather than
	 * `get_shipping_method()`, which flattens several methods into one comma-joined string.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	private function shipping_lines_payload( $order ) {
		$lines = array();
		foreach ( $order->get_items( 'shipping' ) as $line ) {
			$lines[] = array(
				'methodId'    => $line->get_method_id(),
				'methodTitle' => $line->get_method_title(),
				'total'       => (float) $line->get_total(),
				'tax'         => (float) $line->get_total_tax(),
			);
		}
		return $lines;
	}

	/**
	 * Fee lines, if the store or another extension added any.
	 *
	 * Usually empty. Included because a fee moves the total without appearing in any line
	 * item, so an order carrying one cannot be reconciled from line items plus shipping plus
	 * tax alone.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	private function fee_lines_payload( $order ) {
		$lines = array();
		foreach ( $order->get_fees() as $fee ) {
			$lines[] = array(
				'name'  => $fee->get_name(),
				'total' => (float) $fee->get_total(),
				'tax'   => (float) $fee->get_total_tax(),
			);
		}
		return $lines;
	}

	/**
	 * Drop blank strings from a flat array, preserving keys.
	 *
	 * Stated once here rather than re-derived per field, and deliberately only blank STRINGS:
	 * `0` and `false` are values.
	 *
	 * @param array $fields Field map.
	 * @return array
	 */
	private static function drop_blanks( $fields ) {
		$out = array();
		foreach ( $fields as $key => $value ) {
			if ( is_string( $value ) && '' === trim( $value ) ) {
				continue;
			}
			$out[ $key ] = $value;
		}
		return $out;
	}

	/**
	 * A WooCommerce date as an ISO-8601 string, or null when unset.
	 *
	 * @param WC_DateTime|null $date Date.
	 * @return string|null
	 */
	private static function date_atom( $date ) {
		return $date instanceof WC_DateTime ? $date->date( DATE_ATOM ) : null;
	}

	/**
	 * Payload keys excluded from the idempotency hash because they move without the order
	 * meaningfully changing.
	 *
	 * `modifiedAt` is the whole list, and excluding it is what makes it safe to SEND at all.
	 * The reason is specific to HPOS and was measured rather than assumed:
	 *
	 * `OrdersTableDataStore::after_meta_change()` calls `should_save_after_meta_change()`,
	 * which is true when the order has no other queued changes, and then does
	 * `$order->set_date_modified( current_time( 'mysql' ) ); $order->save();`. Saving a single
	 * meta value therefore stamps a new modified date — and `run_sync()` below finishes every
	 * successful push by doing exactly that, writing SYNC_KEY_META and nothing else.
	 *
	 * So with `modifiedAt` inside the hash, recording a push would itself invalidate the key
	 * that push recorded: the next trigger would compute a different key, push again, stamp
	 * again, and de-duplication would never converge for as long as the store had HPOS on.
	 * Verified on WooCommerce 11.0.1 with HPOS enabled — writing SYNC_KEY_META moved
	 * date_modified, the shipped rule kept the key stable, and hashing the payload as-is
	 * produced a fresh key every time.
	 *
	 * Post storage does not have this problem (`abstract-wc-order-data-store-cpt.php` only
	 * rewrites `post_modified` when one of date_created/date_modified/status/parent_id/
	 * post_excerpt changed, and a meta write is none of those), which is exactly why this
	 * would have looked fine on a site that had not migrated yet.
	 */
	const VOLATILE_PAYLOAD_KEYS = array( 'modifiedAt' );

	/**
	 * Build the idempotency key for one order state.
	 *
	 * Scoped by site so two stores pushing the same order id cannot collide, and derived from
	 * the payload so a genuine change — a new status, an edited total, an added line — is a
	 * NEW event rather than a suppressed duplicate. It is a de-duplication token, not a
	 * signature, which is why an ordinary hash is appropriate here.
	 *
	 * Expanding the payload necessarily changes this key, so every order that was already
	 * synced pushes once more the next time it is triggered. That is correct rather than
	 * unfortunate — the previous push genuinely did not carry the address.
	 *
	 * @param WC_Order $order   Order.
	 * @param array    $payload Payload about to be sent.
	 * @return string
	 */
	private function idempotency_key( $order, $payload ) {
		foreach ( self::VOLATILE_PAYLOAD_KEYS as $volatile ) {
			unset( $payload[ $volatile ] );
		}

		return sprintf(
			'woocommerce-%s-%d-%s',
			substr( md5( home_url( '/' ) ), 0, 12 ),
			(int) $order->get_id(),
			substr( md5( (string) wp_json_encode( $payload ) ), 0, 16 )
		);
	}
}
