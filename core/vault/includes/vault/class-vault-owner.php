<?php
/**
 * BizCity_Vault_Owner — whose Kho tri thức a request acts on (PHASE-0.153 V1, doc 00 §4.1b).
 *
 * Owner 15/11: "mỗi user id đều có 1 trợ lý zalo riêng, tương ứng core/scheduler và core/vault, journal index theo user id đó của riêng mình".
 * There is no site-wide owner of the data any more; each user id owns its own Kho.
 *
 *   current()   the owner of the Kho this request acts on:
 *               1. pushed by an entry point (an MCP / Zalo turn with account_id ⇒ the WP user that owns that Zalo number),
 *               2. the logged-in user,
 *               3. the legacy site owner (BizCity_Context_Producers::owner_user_id()) — behaviour of today for cron / backfill.
 *   is_legacy() the legacy site owner keeps the EXISTING paths as their Kho (Q153-8: nothing is moved, a single-owner site is unchanged);
 *               every other user id gets <root>/{blog}/u{id}/<ns>/ (BizCity_Brain_Vault_Store::user_dir).
 *
 * The number ⇒ owner lookup reads bizcity_zalo_accounts.owner_user_id (bridge id `rzh:<account_id>`), the column linking a number already writes.
 *
 * [2026-11-15 09:59 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.153 V1 — new file.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Vault
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Vault_Owner', false ) ) {
	return;
}

final class BizCity_Vault_Owner {

	/** Test seam: fn(string $account_id): int — replaces the bizcity_zalo_accounts lookup. */
	public static $account_owner = null;

	/** Test seam: fn(): int — replaces BizCity_Context_Producers::owner_user_id(). */
	public static $legacy = null;

	/** @var int[] owners pushed by entry points, innermost last */
	private static $stack = array();

	/** @var array<string,int> account_id ⇒ owner, this request */
	private static $memo = array();

	/** @var int an admin viewing another user's Kho on the web (Q153-6), 0 = none */
	private static $view_as = 0;

	/** Test seam: fn(int $user_id): bool — replaces "does this user own a Zalo number". */
	public static $owns_number = null;

	/**
	 * [2026-11-15 10:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.153 V1b — admin "Đang xem Kho của …": the REST routes of bizcity-vault/v1 act on that
	 * user's Kho for this request. Only a user with manage_options may set it; anyone else is ignored. 0 clears it.
	 */
	public static function view_as( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			self::$view_as = 0;
			return true;
		}
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		if ( function_exists( 'get_userdata' ) && ! get_userdata( $user_id ) ) {
			return false;
		}
		self::$view_as = $user_id;
		return true;
	}

	/**
	 * The Kho owner of a REST request of bizcity-vault/v1, decided by the SITE (never by a parameter, except an admin's `as_user`):
	 *   admin          ⇒ `as_user` when given (view_as), else their own Kho;
	 *   own assistant  ⇒ their own Kho (they own a Zalo number, or are the legacy owner, or already saved folders);
	 *   anyone else    ⇒ the legacy owner's Kho, read through the folder gate as before 0.153 (CRM staff: staff folders; others: public).
	 * @return int the owner set for this request
	 */
	public static function for_rest( int $as_user = 0 ): int {
		if ( $as_user > 0 && self::is_admin_user() && self::view_as( $as_user ) ) {
			return self::$view_as;
		}
		return self::current();
	}

	/** @var array<int,bool> has_own_kho() this request */
	private static $own = array();

	/** Does this user have a Kho of their own (an assistant)? The legacy owner, a user owning a Zalo number, or one that already saved folders. */
	public static function has_own_kho( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		if ( self::is_legacy( $user_id ) ) {
			return true;
		}
		if ( ! isset( self::$own[ $user_id ] ) ) {
			self::$own[ $user_id ] = self::owns_kho_now( $user_id );
		}
		return self::$own[ $user_id ];
	}

	private static function owns_kho_now( int $user_id ): bool {
		if ( is_callable( self::$owns_number ) ) {
			if ( call_user_func( self::$owns_number, $user_id ) ) {
				return true;
			}
		} elseif ( class_exists( 'BizCity_Zalo_Mapping_Repo' ) && method_exists( 'BizCity_Zalo_Mapping_Repo', 'list_personal_accounts_for_owner' ) ) {
			try {
				if ( array() !== (array) BizCity_Zalo_Mapping_Repo::list_personal_accounts_for_owner( $user_id ) ) {
					return true;
				}
			} catch ( \Throwable $e ) {
				// unknown ⇒ fall through
			}
		}
		return function_exists( 'get_user_meta' ) && is_array( get_user_meta( $user_id, 'bizcity_vault_folders', true ) );
	}

	/** Is the acting Kho the logged-in user's own (or an admin's view)? Then the folder gate reads it as its owner. */
	public static function acts_as_owner(): bool {
		if ( self::is_admin_user() ) {
			return true;
		}
		$me = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		return $me > 0 && self::has_own_kho( $me ) && self::current() === $me;
	}

	/** The user id whose Kho this request acts on; 0 when none can be found (no user, no legacy owner). */
	public static function current(): int {
		if ( self::$stack ) {
			return (int) end( self::$stack );
		}
		if ( self::$view_as > 0 ) {
			return self::$view_as;
		}
		$me = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		// PHASE-0.153 V1b — a logged-in user acts on their own Kho only when they have one (an assistant, or an admin); everyone else reads the
		// legacy Kho through its folder gate, exactly as before 0.153 (a staff account must not get a fresh default folder map with weaker access).
		if ( $me > 0 && ( self::is_admin_user() || self::has_own_kho( $me ) ) ) {
			return $me;
		}
		return self::legacy();
	}

	private static function is_admin_user(): bool {
		return function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
	}

	/** The site owner of before PHASE-0.153 (option bizcity_context_owner_user_id, else the first administrator). */
	public static function legacy(): int {
		if ( is_callable( self::$legacy ) ) {
			return (int) call_user_func( self::$legacy );
		}
		return class_exists( 'BizCity_Context_Producers' ) ? (int) BizCity_Context_Producers::owner_user_id() : 0;
	}

	/** Does this user id keep the pre-0.153 paths as their Kho (Q153-8)? */
	public static function is_legacy( int $user_id ): bool {
		return $user_id > 0 && $user_id === self::legacy();
	}

	/** The WP user that owns a Zalo number (hub account id, with or without `rzh:`); 0 when unknown. */
	public static function of_account( string $account_id ): int {
		$id = trim( $account_id );
		if ( 0 === strpos( $id, 'rzh:' ) ) {
			$id = substr( $id, 4 );
		}
		if ( '' === $id ) {
			return 0;
		}
		if ( isset( self::$memo[ $id ] ) ) {
			return self::$memo[ $id ];
		}
		$owner = 0;
		if ( is_callable( self::$account_owner ) ) {
			$owner = (int) call_user_func( self::$account_owner, $id );
		} elseif ( class_exists( 'BizCity_Zalo_Mapping_Repo' ) && method_exists( 'BizCity_Zalo_Mapping_Repo', 'find_account_by_bridge_id' ) ) {
			try {
				$row   = BizCity_Zalo_Mapping_Repo::find_account_by_bridge_id( 'personal', 'rzh:' . $id );
				$owner = is_array( $row ) ? (int) ( $row['owner_user_id'] ?? $row['user_id'] ?? 0 ) : 0;
			} catch ( \Throwable $e ) {
				$owner = 0;
			}
		}
		return self::$memo[ $id ] = max( 0, $owner );
	}

	/**
	 * Run $fn with $owner as the acting Kho owner (≤ 0 ⇒ $fn runs unchanged). Always restores, even on a throw.
	 *
	 * @return mixed whatever $fn returns
	 */
	public static function with( int $owner, callable $fn ) {
		if ( $owner <= 0 ) {
			return $fn();
		}
		self::$stack[] = $owner;
		try {
			return $fn();
		} finally {
			array_pop( self::$stack );
		}
	}

	/** with() for the owner of a Zalo number; an unknown number leaves the owner as it was. */
	public static function with_account( string $account_id, callable $fn ) {
		return self::with( self::of_account( $account_id ), $fn );
	}

	/** Test helper: forget pushed owners, the account memo and the seams. */
	public static function reset(): void {
		self::$stack         = array();
		self::$view_as       = 0;
		self::$memo          = array();
		self::$account_owner = null;
		self::$legacy        = null;
		self::$owns_number   = null;
		self::$own           = array();
	}
}
