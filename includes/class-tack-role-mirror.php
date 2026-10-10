<?php
/**
 * Optional WordPress role that mirrors a buyer's TackQuote group (1.10.0).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHAT IT IS, AND WHAT IT IS NOT
 * ─────────────────────────────────────────────────────────────────────────────
 * Themes and other plugins often key on a WordPress role ("show this banner to
 * wholesale"). TackQuote buyer groups are the source of truth; this is only a
 * local, read-only MIRROR of them, off by default:
 *
 *   - a signed-in customer TackQuote places in group `TIER2` gets the extra
 *     role `tackquote_tier2` (created on first use, capability `read` only);
 *   - when the group changes, or TackQuote says the customer is in no group,
 *     the role the plugin added is taken away again;
 *   - a role the plugin did not add is NEVER removed, including a
 *     `tackquote_*` role a merchant assigned by hand. What the plugin added is
 *     recorded in user meta, and only that list is ever removed;
 *   - an outage (TackQuote unreachable) changes nothing;
 *   - it never drives pricing, restrictions or visibility. Those read the group
 *     from TackQuote at request time, as before.
 *
 * The group is read through the same shared `Tack_B2B_Notices` instance as the
 * other group features, so it costs no extra lookup on a request that already
 * asked. To keep it from adding a lookup to every page view on its own, it
 * re-checks a customer at most once every five minutes, and immediately after
 * they sign in.
 *
 * @package TackQuotes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/**
 * Adds and removes the `tackquote_<code>` role for the signed-in customer.
 */
class Tack_Role_Mirror {

	/** Master switch (default off). */
	const OPTION_ENABLED = 'tack_quotes_enable_role_mirror';

	/** Every role this plugin has created, so uninstall can remove them. */
	const OPTION_CREATED_ROLES = 'tack_quotes_mirror_roles_created';

	/** User meta: the roles this plugin added to this user. */
	const META_ADDED = '_tack_mirrored_roles';

	/** User meta: when this user was last checked (Unix time). */
	const META_CHECKED = '_tack_role_mirror_checked';

	/** Role name prefix. */
	const PREFIX = 'tackquote_';

	/** Seconds between checks for the same customer. */
	const INTERVAL = 300;

	/**
	 * Supplies the buyer group.
	 *
	 * @var Tack_B2B_Notices
	 */
	private $notices;

	/**
	 * Constructor.
	 *
	 * @param Tack_B2B_Notices|null $notices Injected in tests; built here otherwise.
	 */
	public function __construct( $notices = null ) {
		$this->notices = $notices instanceof Tack_B2B_Notices ? $notices : new Tack_B2B_Notices();
	}

	/**
	 * Is the feature switched on?
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return 'yes' === get_option( self::OPTION_ENABLED, 'no' );
	}

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'template_redirect', array( $this, 'maybe_sync_current_user' ), 30 );
		add_action( 'wp_login', array( __CLASS__, 'reset_check' ), 10, 2 );
	}

	/**
	 * Force a fresh check on the next page after signing in.
	 *
	 * @param string       $login Login name (unused).
	 * @param WP_User|null $user  The user.
	 */
	public static function reset_check( $login = '', $user = null ) {
		unset( $login );
		if ( is_object( $user ) && ! empty( $user->ID ) ) {
			delete_user_meta( (int) $user->ID, self::META_CHECKED );
		}
	}

	/**
	 * Role name for a group code: `tackquote_` + the code as a role key.
	 *
	 * @param string $code Group code.
	 * @return string '' when the code has nothing usable.
	 */
	public static function role_for( $code ) {
		$key = sanitize_key( str_replace( '.', '_', (string) $code ) );
		return '' === $key ? '' : self::PREFIX . $key;
	}

	/**
	 * `template_redirect`: sync the signed-in customer, throttled.
	 *
	 * @return bool Whether a sync ran.
	 */
	public function maybe_sync_current_user() {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		$user = wp_get_current_user();
		if ( ! is_object( $user ) || empty( $user->ID ) ) {
			return false;
		}
		$last = (int) get_user_meta( (int) $user->ID, self::META_CHECKED, true );
		if ( $last > 0 && ( time() - $last ) < self::INTERVAL ) {
			return false;
		}
		$this->sync_user( $user );
		return true;
	}

	/**
	 * Bring one user's mirrored role in line with their TackQuote group.
	 *
	 * @param WP_User $user The user (must support add_role/remove_role).
	 * @return string What happened: added|kept|removed|cleared|outage.
	 */
	public function sync_user( $user ) {
		$status = $this->notices->buyer_group_status();
		if ( 'unavailable' === $status ) {
			// Could not ask: leave every role exactly as it is.
			return 'outage';
		}
		update_user_meta( (int) $user->ID, self::META_CHECKED, time() );

		$desired = '';
		if ( 'grouped' === $status ) {
			$group = $this->notices->buyer_group();
			if ( is_array( $group ) && ! empty( $group['code'] ) ) {
				$desired = self::role_for( (string) $group['code'] );
			}
		}

		$added   = $this->added_roles( $user );
		$roles   = isset( $user->roles ) ? (array) $user->roles : array();
		$changed = false;

		// Remove what WE added and no longer applies. Nothing else.
		foreach ( $added as $i => $role ) {
			if ( $role === $desired ) {
				continue;
			}
			if ( in_array( $role, $roles, true ) ) {
				$user->remove_role( $role );
			}
			unset( $added[ $i ] );
			$changed = true;
		}

		$result = '' === $desired ? ( $changed ? 'removed' : 'cleared' ) : 'kept';
		if ( '' !== $desired && ! in_array( $desired, $roles, true ) ) {
			$this->ensure_role_exists( $desired, (string) $group['code'] );
			$user->add_role( $desired );
			$added[] = $desired;
			$result  = 'added';
		}
		// A desired role the user ALREADY had (assigned by hand) is not recorded
		// as ours, so it is never removed later.

		update_user_meta( (int) $user->ID, self::META_ADDED, array_values( array_unique( $added ) ) );
		return $result;
	}

	/**
	 * The roles this plugin added to this user.
	 *
	 * @param WP_User $user The user.
	 * @return string[]
	 */
	private function added_roles( $user ) {
		$stored = get_user_meta( (int) $user->ID, self::META_ADDED, true );
		$out    = array();
		foreach ( (array) $stored as $role ) {
			$role = (string) $role;
			// Only ever our own prefix, whatever the meta says.
			if ( 0 === strpos( $role, self::PREFIX ) ) {
				$out[] = $role;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Create the role on first use, with the `read` capability only.
	 *
	 * @param string $role Role name.
	 * @param string $code Group code, for the display name.
	 */
	private function ensure_role_exists( $role, $code ) {
		if ( get_role( $role ) ) {
			return;
		}
		add_role(
			$role,
			/* translators: %s: TackQuote buyer group code. */
			sprintf( __( 'TackQuote group %s', 'tackquote' ), $code ),
			array( 'read' => true )
		);
		$created = (array) get_option( self::OPTION_CREATED_ROLES, array() );
		if ( ! in_array( $role, $created, true ) ) {
			$created[] = $role;
			update_option( self::OPTION_CREATED_ROLES, array_values( $created ), false );
		}
	}
}
