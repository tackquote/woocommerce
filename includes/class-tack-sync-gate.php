<?php
/**
 * Decides whether an order push may be sent, from what TackQuote last said.
 *
 * WHY THIS EXISTS. One store's API key lacked the `orders:write` scope. Every order
 * trigger — creation, each status change, each admin save — queued a push, every push
 * was refused with HTTP 403, and nothing remembered the refusal, so the next trigger
 * sent the same doomed request: 444 refusals in 50 minutes, roughly one every 3 seconds.
 * The merchant saw nothing; the reason sat only in WooCommerce → Status → Logs.
 *
 * Nothing here loops by itself. Action Scheduler does not re-run a callback that returns
 * normally (it marks an action failed only on a fatal error or a timeout), so the volume
 * came from triggers, and the fix is to remember the refusal between them.
 *
 * Classification — the same temporary/terminal split the TackQuote API applies to the
 * vendors it calls:
 *
 *   TERMINAL   HTTP 401 or 403 with TackQuote's own JSON error body: a missing scope
 *              (`insufficient_scope`), a lapsed subscription (`SUBSCRIPTION_INACTIVE`), a
 *              revoked or unknown key. No retry changes these; the merchant has to act.
 *              Pushes stop, wp-admin says why, and one re-probe is allowed per
 *              TERMINAL_REPROBE so a renewed subscription heals without a key change.
 *              Saving a different key lifts the block at once.
 *   THROTTLED  HTTP 429. Wait for Retry-After, capped at MAX_WAIT.
 *   TEMPORARY  Everything else, including a 401/403 whose body is NOT JSON: that is a
 *              firewall or challenge page in front of the API and it goes away on its
 *              own. Calling it terminal would switch sync off for a five-minute blip.
 *
 * An order skipped while blocked is not marked as sent. When the block lifts (a push
 * succeeds, or a different key is saved) UNBLOCKED_ACTION fires and Tack_Order_Sync
 * re-queues the orders modified since the block began; any order that changes again is
 * pushed by its own trigger as before.
 *
 * Terminal means TackQuote's OWN error body (JSON with a matching `statusCode` and a
 * non-empty `code`); a 429 that carries a scope refusal stays terminal.
 *
 * @package TackQuotes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Order-sync circuit breaker.
 */
class Tack_Sync_Gate {

	/**
	 * Option holding the current block. Also listed in uninstall.php.
	 */
	const OPTION = 'tack_quotes_order_sync_block';

	/**
	 * Seconds between re-probes while a terminal refusal stands.
	 */
	const TERMINAL_REPROBE = 3600;

	/**
	 * Default wait for a 429 that names none.
	 */
	const DEFAULT_WAIT = 60;

	/**
	 * Longest wait any Retry-After may impose, and the ceiling of the back-off below.
	 */
	const MAX_WAIT = 3600;

	/**
	 * Share of the computed wait that random jitter may ADD. Never subtract: a Retry-After
	 * is the earliest moment the server allows, so jitter only ever lands later.
	 *
	 * Why jitter at all: every order held during a throttle is rescheduled to the same
	 * `until`, and without spreading them a store with fifty pending pushes would present
	 * all fifty in the same second the window reopens, which is the shape the server's
	 * limiter refuses again.
	 */
	const JITTER_FRACTION = 0.5;

	/**
	 * Fired when pushes are allowed again (a push succeeded, or a different key was saved),
	 * with the Unix time pushes started being held. Tack_Order_Sync re-queues the orders
	 * skipped in between, since nothing else would send an order that does not change again.
	 */
	const UNBLOCKED_ACTION = 'tack_quotes_order_sync_unblocked';

	/**
	 * Classify a failed push.
	 *
	 * @param mixed $error A WP_Error from Tack_Api_Client, or anything else.
	 * @param int   $now   Current Unix time.
	 * @return array|null A block (kind, status, code, scopes, message, at, until), or null
	 *                    when the failure is temporary and the next trigger may try again.
	 */
	public static function classify( $error, $now ) {
		if ( ! is_wp_error( $error ) ) {
			return null;
		}
		$data   = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
		$block  = array(
			'status'  => $status,
			'code'    => is_array( $data ) && isset( $data['code'] ) ? (string) $data['code'] : '',
			'scopes'  => is_array( $data ) && isset( $data['requiredScopes'] ) && is_array( $data['requiredScopes'] ) ? array_values( $data['requiredScopes'] ) : array(),
			'message' => (string) $error->get_error_message(),
			'at'      => (int) $now,
		);

		/*
		 * Only TackQuote's OWN error body may make a refusal terminal: JSON whose
		 * `statusCode` equals the HTTP status and which carries a non-empty `code` (the
		 * shape every TackQuote API error has). A proxy, WAF or load balancer that happens
		 * to answer 403 with some JSON of its own is not TackQuote saying "never", and
		 * goes away on its own.
		 */
		$from_tackquote = is_array( $data )
			&& ! empty( $data['json'] )
			&& isset( $data['statusCode'] ) && (int) $data['statusCode'] === $status
			&& '' !== $block['code'];

		if ( 429 === $status ) {
			$wait = self::wait_seconds( $data, $now );

			/*
			 * TackQuote answers a key that keeps hitting a missing scope with 429 instead of
			 * 403 after a few refusals. It is still the same terminal refusal, so it stays
			 * terminal — otherwise the wp-admin notice would flicker off for each throttle
			 * window — and it is held at least as long as any terminal block.
			 */
			if ( $from_tackquote && ( 'insufficient_scope' === $block['code'] || array() !== $block['scopes'] ) ) {
				$block['kind']  = 'terminal';
				$block['until'] = max( (int) $now + $wait, (int) $now + self::TERMINAL_REPROBE );
				return $block;
			}
			$block['kind'] = 'throttled';
			$block['wait'] = $wait;
			// The server's own answer. record_failure() lengthens it for a REPEATED
			// throttle (exponential back-off with jitter); classify() itself stays exact.
			$block['until'] = (int) $now + $wait;
			return $block;
		}

		if ( ( 401 === $status || 403 === $status ) && $from_tackquote ) {
			$block['kind']  = 'terminal';
			$block['until'] = (int) $now + self::TERMINAL_REPROBE;
			return $block;
		}

		return null;
	}

	/**
	 * Remember a failed push, if it is one that must hold back the next.
	 *
	 * @param mixed      $error   The failure.
	 * @param string     $api_key The key the push was made with. Only a hash is stored.
	 * @param int        $now     Current Unix time.
	 * @param float|null $random  Jitter source in [0, 1). Null draws one; tests pass a value.
	 * @return array|null The stored block.
	 */
	public static function record_failure( $error, $api_key, $now, $random = null ) {
		$block = self::classify( $error, $now );
		if ( null === $block ) {
			return null;
		}
		$block['key'] = self::key_fingerprint( $api_key );
		// When pushes STARTED being held, kept across re-records, so the re-queue on
		// unblock reaches back to the first skipped order rather than the last refusal.
		$previous       = get_option( self::OPTION, null );
		$same_key       = is_array( $previous ) && ( $previous['key'] ?? '' ) === $block['key'];
		$block['since'] = $same_key
			? (int) ( $previous['since'] ?? $previous['at'] ?? $now )
			: (int) $now;

		if ( 'throttled' === $block['kind'] ) {
			/*
			 * A SECOND 429 before any push succeeded means the first wait was not enough,
			 * so each consecutive throttle doubles the wait (from DEFAULT_WAIT) and adds
			 * jitter, never below what Retry-After asked and never above MAX_WAIT. The count
			 * lives in the option with the block, so every PHP worker and every cron run
			 * sees the same attempt number. A success clears the option and so resets it.
			 */
			$block['attempt'] = $same_key && 'throttled' === ( $previous['kind'] ?? '' )
				? (int) ( $previous['attempt'] ?? 1 ) + 1
				: 1;
			$block['until']   = (int) $now + self::backoff_seconds( (int) $block['wait'], $block['attempt'], $random );
		}

		update_option( self::OPTION, $block, false );
		return $block;
	}

	/**
	 * How long to hold pushes after the Nth consecutive 429.
	 *
	 * Pure, so it can be proven without WordPress:
	 *
	 *   base   = max( retry_after, DEFAULT_WAIT * 2^(attempt-1) )
	 *   jitter = floor( base * JITTER_FRACTION * random ),  random in [0, 1)
	 *   wait   = min( MAX_WAIT, base + jitter )
	 *
	 * Retry-After is a floor, not a suggestion: the result is never earlier than the
	 * server asked (except that nothing exceeds MAX_WAIT, which also bounds a Retry-After
	 * on its own, see wait_seconds()). Jitter is additive for the same reason.
	 *
	 * @param int        $retry_after Seconds the server asked for (0 when it named none).
	 * @param int        $attempt     1 for the first throttle since the last success.
	 * @param float|null $random      In [0, 1). Null draws one.
	 * @return int Seconds.
	 */
	public static function backoff_seconds( $retry_after, $attempt, $random = null ) {
		$attempt = max( 1, (int) $attempt );
		// Doubling stops once it has passed MAX_WAIT; min() below caps the result and
		// this keeps 2^(attempt-1) from overflowing on a long outage.
		$exponent = min( $attempt - 1, 10 );
		$base     = max( (int) $retry_after, self::DEFAULT_WAIT * ( 2 ** $exponent ) );
		if ( null === $random ) {
			// Same draw as before (an integer in [0, mt_getrandmax()) over mt_getrandmax(),
			// so the result stays in [0, 1)), from WordPress's CSPRNG-backed wp_rand().
			$random = wp_rand( 0, mt_getrandmax() - 1 ) / mt_getrandmax();
		}
		$random = min( max( (float) $random, 0.0 ), 0.999999 );
		$jitter = (int) floor( $base * self::JITTER_FRACTION * $random );
		return (int) min( self::MAX_WAIT, $base + $jitter );
	}

	/**
	 * A push succeeded: whatever was blocking has been fixed. Pushes skipped while it
	 * stood are re-queued.
	 */
	public static function record_success() {
		$block = get_option( self::OPTION, null );
		if ( is_array( $block ) ) {
			self::lift( $block );
		}
	}

	/**
	 * `update_option_tack_quotes_api_key` handler: a different key was saved, so a block
	 * recorded against the old one no longer applies. Lifted now, not on the next push,
	 * so the skipped orders are re-queued immediately.
	 *
	 * @param mixed $old_value Previous key.
	 * @param mixed $new_value New key.
	 */
	public static function on_api_key_changed( $old_value, $new_value ) {
		if ( (string) $old_value === (string) $new_value ) {
			return;
		}
		$block = get_option( self::OPTION, null );
		if ( is_array( $block ) ) {
			self::lift( $block );
		}
	}

	/**
	 * Clear a block and announce it, so the skipped orders can be re-queued.
	 *
	 * @param array $block The block being lifted.
	 */
	private static function lift( array $block ) {
		self::clear();

		/**
		 * Fires when a block on order sync is lifted (a push succeeded, or a different
		 * API key was saved). Tack_Order_Sync listens and re-queues the orders that
		 * changed while pushes were held.
		 *
		 * The hook name is the UNBLOCKED_ACTION constant (`tack_quotes_order_sync_unblocked`)
		 * so the listener in Tack_Order_Sync and this emitter cannot drift apart.
		 *
		 * @since 1.8.2
		 *
		 * @param int $since Unix time pushes started being held; 0 when unknown.
		 */
		do_action( self::UNBLOCKED_ACTION, (int) ( $block['since'] ?? $block['at'] ?? 0 ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the constant holds the prefixed literal 'tack_quotes_order_sync_unblocked'; it is shared with the listener on purpose.
	}

	/**
	 * Forget any block.
	 */
	public static function clear() {
		delete_option( self::OPTION );
	}

	/**
	 * The block that must hold back a push right now, if any.
	 *
	 * @param string $api_key The key the next push would use.
	 * @param int    $now     Current Unix time.
	 * @return array|null
	 */
	public static function active_block( $api_key, $now ) {
		$block = self::stored_for_key( $api_key );
		if ( null === $block ) {
			return null;
		}
		return (int) $now < (int) ( $block['until'] ?? 0 ) ? $block : null;
	}

	/**
	 * Print the wp-admin notice while order sync is refused.
	 *
	 * Shown to anyone who can manage WooCommerce, because they are the people whose
	 * orders are not arriving. It stays until a push succeeds or a different key is saved;
	 * a throttle on its own is not shown, since it clears itself.
	 */
	public static function render_admin_notice() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$block = self::stored_for_key( (string) get_option( 'tack_quotes_api_key', '' ) );
		if ( null === $block || 'terminal' !== ( $block['kind'] ?? '' ) ) {
			return;
		}

		// Tack_Settings is always loaded by the plugin bootstrap (class-tack-quotes.php).
		$settings = admin_url( 'admin.php?page=' . Tack_Settings::PAGE_SLUG );
		echo '<div class="notice notice-error"><p><strong>'
			. esc_html__( 'TackQuote: orders are not being sent.', 'tackquote' )
			. '</strong> '
			. esc_html( self::notice_text( $block ) )
			. ' <a href="' . esc_url( $settings ) . '">'
			. esc_html__( 'Open TackQuote settings', 'tackquote' )
			. '</a></p></div>';
	}

	/**
	 * The merchant-facing explanation for a terminal block.
	 *
	 * @param array $block Stored block.
	 * @return string
	 */
	public static function notice_text( array $block ) {
		$scopes = isset( $block['scopes'] ) && is_array( $block['scopes'] ) ? array_filter( $block['scopes'], 'is_string' ) : array();
		if ( 'insufficient_scope' === ( $block['code'] ?? '' ) || array() !== $scopes ) {
			$list = array() !== $scopes ? implode( ', ', $scopes ) : 'orders:write';
			return sprintf(
				/* translators: %1$s: comma-separated API key scopes, e.g. orders:write */
				__( 'TackQuote refused order sync because the API key saved here is missing the %1$s scope. In TackQuote, open Settings > API keys, create a key with the %1$s scope, paste it into TackQuote settings on this site, then revoke the old key. Orders placed until then are sent when they next change.', 'tackquote' ),
				$list
			);
		}
		if ( 'SUBSCRIPTION_INACTIVE' === ( $block['code'] ?? '' ) ) {
			return __( 'Your TackQuote subscription is not active, so TackQuote refuses new orders. Choose a plan in TackQuote under Profile > Billing & Plan; sync resumes within the hour.', 'tackquote' );
		}
		if ( 401 === (int) ( $block['status'] ?? 0 ) ) {
			return __( 'TackQuote does not accept the API key saved here (it may have been revoked). Create a new key in TackQuote under Settings > API keys with the orders:write scope and save it in TackQuote settings on this site.', 'tackquote' );
		}
		return sprintf(
			/* translators: %s: the refusal message TackQuote returned */
			__( 'TackQuote refused order sync: %s', 'tackquote' ),
			(string) ( $block['message'] ?? '' )
		);
	}

	/**
	 * The stored block, if it was recorded for this key.
	 *
	 * A block recorded for a different key is stale — the merchant has saved a new one —
	 * and is dropped rather than trusted.
	 *
	 * @param string $api_key Current key.
	 * @return array|null
	 */
	private static function stored_for_key( $api_key ) {
		$block = get_option( self::OPTION, null );
		if ( ! is_array( $block ) ) {
			return null;
		}
		if ( ( $block['key'] ?? '' ) !== self::key_fingerprint( $api_key ) ) {
			self::lift( $block );
			return null;
		}
		return $block;
	}

	/**
	 * A short one-way fingerprint, so the option can tell keys apart without holding one.
	 *
	 * @param string $api_key Key.
	 * @return string
	 */
	private static function key_fingerprint( $api_key ) {
		return substr( hash( 'sha256', (string) $api_key ), 0, 16 );
	}

	/**
	 * Seconds to wait after a 429: Retry-After (delta-seconds or an HTTP-date, RFC 9110
	 * section 10.2.3), else the body's retryAfterSeconds, else DEFAULT_WAIT; at most MAX_WAIT.
	 *
	 * @param mixed $data Error data.
	 * @param int   $now  Current Unix time.
	 * @return int
	 */
	private static function wait_seconds( $data, $now ) {
		$wait   = 0;
		$header = is_array( $data ) && isset( $data['retryAfterHeader'] ) ? trim( (string) $data['retryAfterHeader'] ) : '';
		if ( '' !== $header && preg_match( '/^\d+$/', $header ) ) {
			$wait = (int) $header;
		} elseif ( '' !== $header ) {
			$when = strtotime( $header );
			$wait = false === $when ? 0 : $when - (int) $now;
		}
		if ( $wait <= 0 && is_array( $data ) && ! empty( $data['retryAfterSeconds'] ) ) {
			$wait = (int) $data['retryAfterSeconds'];
		}
		if ( $wait <= 0 ) {
			$wait = self::DEFAULT_WAIT;
		}
		return min( $wait, self::MAX_WAIT );
	}
}
