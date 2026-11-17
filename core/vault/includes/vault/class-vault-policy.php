<?php
/**
 * BizCity_Vault_Policy — the matrix "lưu gì, vào đâu" of one assistant owner (PHASE-0.153 V3, doc 00 §4.4, contract vault-policy@1).
 *
 * Rows = what may be kept, columns = which conversation, cell = a mode:
 *   auto  lưu không cần hỏi · ask  chỉ khi người trong cuộc nói rõ "lưu giúp / nhớ giúp" · off  tắt · na  không áp dụng (locked)
 *
 * Three layers, the later wins (doc 00 §4.4):
 *   1. DEFAULTS (this file: the owner's directive — khách không lưu tài liệu, nhóm có chủ tự động lưu)
 *   2. the owner's matrix, optionally per Zalo number (Q153-10: one Kho, a matrix per number)
 *   3. a per-space override (one group / one customer: "nhóm này không lưu tệp", "khách này được lưu")
 * Stored in user meta `bizcity_vault_policy` of the assistant owner (no option, no table). An `na` cell never changes.
 *
 * [2026-11-15 11:05 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.153 V3 — new file.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Vault
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Vault_Policy', false ) ) {
	return;
}

final class BizCity_Vault_Policy {

	const CONTRACT = 'vault-policy@1.0.0';
	const META     = 'bizcity_vault_policy';
	const MODES    = array( 'auto', 'ask', 'off' );

	/** rows (what) ⇒ label */
	const ROWS = array(
		'files'     => 'Lưu tệp gửi vào (tệp gốc + bản biên dịch)',
		'remember'  => '"Lưu giúp / nhớ giúp …"',
		'tasks'     => 'Việc giao, hẹn, việc cần theo dõi',
		'minutes'   => 'Biên bản nhóm theo ngày',
		'memory'    => 'Trí nhớ về người (sở thích, xưng hô)',
		'guru_read' => 'Guru đọc ngăn này khi trả lời',
	);

	/** columns (which conversation) ⇒ label */
	const COLS = array(
		'owner_1_1'      => 'Chủ / nhân sự · chat 1-1',
		'customer_1_1'   => 'Khách · chat 1-1',
		'group_owner'    => 'Nhóm có chủ',
		'group_no_owner' => 'Nhóm không có chủ',
	);

	/** Layer 1 — the directive of 15/11 (doc 00 §4.4); `na` = locked. */
	const DEFAULTS = array(
		'files'     => array( 'owner_1_1' => 'auto', 'customer_1_1' => 'off', 'group_owner' => 'auto', 'group_no_owner' => 'off' ),
		'remember'  => array( 'owner_1_1' => 'auto', 'customer_1_1' => 'ask', 'group_owner' => 'auto', 'group_no_owner' => 'ask' ),
		'tasks'     => array( 'owner_1_1' => 'auto', 'customer_1_1' => 'na', 'group_owner' => 'auto', 'group_no_owner' => 'off' ),
		'minutes'   => array( 'owner_1_1' => 'na', 'customer_1_1' => 'na', 'group_owner' => 'auto', 'group_no_owner' => 'off' ),
		'memory'    => array( 'owner_1_1' => 'auto', 'customer_1_1' => 'auto', 'group_owner' => 'auto', 'group_no_owner' => 'auto' ),
		'guru_read' => array( 'owner_1_1' => 'auto', 'customer_1_1' => 'auto', 'group_owner' => 'auto', 'group_no_owner' => 'off' ),
	);

	/** Test seam: fn(int $owner): array — replaces the user meta read; fn(int $owner, array $data) for the write. */
	public static $read  = null;
	public static $write = null;

	// ── pure ────────────────────────────────────────────────────────────────

	/** Keep only real (row, col) cells with a real mode; never an `na` cell. Pure. */
	public static function clean_matrix( $in ): array {
		$out = array();
		foreach ( (array) $in as $row => $cols ) {
			if ( ! isset( self::DEFAULTS[ $row ] ) || ! is_array( $cols ) ) {
				continue;
			}
			foreach ( $cols as $col => $mode ) {
				if ( isset( self::DEFAULTS[ $row ][ $col ] ) && 'na' !== self::DEFAULTS[ $row ][ $col ] && in_array( (string) $mode, self::MODES, true ) ) {
					$out[ $row ][ $col ] = (string) $mode;
				}
			}
		}
		return $out;
	}

	/** A space override: row ⇒ mode (rows only; the column is the space's own kind). Pure. */
	public static function clean_space( $in ): array {
		$out = array();
		foreach ( (array) $in as $row => $mode ) {
			if ( isset( self::DEFAULTS[ $row ] ) && in_array( (string) $mode, self::MODES, true ) ) {
				$out[ $row ] = (string) $mode;
			}
		}
		return $out;
	}

	/**
	 * The resolved matrix for one number: defaults ⇐ owner matrix ⇐ that number's matrix. Pure over $data {matrix, accounts{acc: matrix}}.
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function resolve( array $data, string $account = '' ): array {
		$m = self::DEFAULTS;
		foreach ( array( self::clean_matrix( $data['matrix'] ?? array() ), '' !== $account ? self::clean_matrix( $data['accounts'][ $account ] ?? array() ) : array() ) as $layer ) {
			foreach ( $layer as $row => $cols ) {
				foreach ( $cols as $col => $mode ) {
					$m[ $row ][ $col ] = $mode;
				}
			}
		}
		return $m;
	}

	/**
	 * The mode of one cell, with the space override on top (layer 3). An `na` cell stays `na` whatever a space says. Pure.
	 */
	public static function mode( array $data, string $row, string $col, string $account = '', string $space = '' ): string {
		$m    = self::resolve( $data, $account );
		$mode = (string) ( $m[ $row ][ $col ] ?? 'off' );
		if ( 'na' === $mode || '' === $space ) {
			return $mode;
		}
		$ov = self::clean_space( $data['spaces'][ $space ] ?? array() );
		return $ov[ $row ] ?? $mode;
	}

	/** How many spaces override each cell's row, by space kind ⇒ {row: {group: n, contact: n}}. Pure. */
	public static function override_counts( array $data ): array {
		$out = array();
		foreach ( (array) ( $data['spaces'] ?? array() ) as $space => $ov ) {
			$kind = 0 === strpos( (string) $space, 'contact:' ) ? 'contact' : 'group';
			foreach ( array_keys( self::clean_space( $ov ) ) as $row ) {
				$out[ $row ][ $kind ] = ( $out[ $row ][ $kind ] ?? 0 ) + 1;
			}
		}
		return $out;
	}

	// ── site ────────────────────────────────────────────────────────────────

	/** The stored policy of an owner: {matrix, accounts, spaces}. */
	public static function data( int $owner ): array {
		$raw = is_callable( self::$read ) ? call_user_func( self::$read, $owner ) : ( $owner > 0 && function_exists( 'get_user_meta' ) ? get_user_meta( $owner, self::META, true ) : array() );
		$raw = is_array( $raw ) ? $raw : array();
		return array(
			'matrix'   => self::clean_matrix( $raw['matrix'] ?? array() ),
			'accounts' => array_map( array( __CLASS__, 'clean_matrix' ), is_array( $raw['accounts'] ?? null ) ? $raw['accounts'] : array() ),
			'spaces'   => array_filter( array_map( array( __CLASS__, 'clean_space' ), is_array( $raw['spaces'] ?? null ) ? $raw['spaces'] : array() ) ),
		);
	}

	private static function store( int $owner, array $data ): bool {
		if ( $owner <= 0 ) {
			return false;
		}
		if ( is_callable( self::$write ) ) {
			call_user_func( self::$write, $owner, $data );
			return true;
		}
		return function_exists( 'update_user_meta' ) && false !== update_user_meta( $owner, self::META, $data );
	}

	/**
	 * Change cells of the owner's matrix (or of one number's matrix). $patch {row: {col: mode|null}} — null puts the cell back on the layer below.
	 *
	 * @return array|WP_Error the resolved matrix
	 */
	public static function save_matrix( int $owner, array $patch, string $account = '' ) {
		$data = self::data( $owner );
		$cur  = '' === $account ? $data['matrix'] : ( $data['accounts'][ $account ] ?? array() );
		foreach ( $patch as $row => $cols ) {
			foreach ( (array) $cols as $col => $mode ) {
				if ( ! isset( self::DEFAULTS[ $row ][ $col ] ) ) {
					return new WP_Error( 'policy_cell_unknown', 'Ô không có trong ma trận: ' . $row . ' × ' . $col, array( 'status' => 400, 'help_code' => 'twin_vault' ) );
				}
				if ( 'na' === self::DEFAULTS[ $row ][ $col ] ) {
					return new WP_Error( 'policy_cell_locked', 'Ô này không áp dụng nên không đổi được.', array( 'status' => 400, 'help_code' => 'twin_vault' ) );
				}
				if ( null === $mode ) {
					unset( $cur[ $row ][ $col ] );
					continue;
				}
				if ( ! in_array( (string) $mode, self::MODES, true ) ) {
					return new WP_Error( 'policy_mode_invalid', 'Chế độ chỉ là auto, ask hoặc off.', array( 'status' => 400, 'help_code' => 'twin_vault' ) );
				}
				$cur[ $row ][ $col ] = (string) $mode;
			}
		}
		$cur = array_filter( self::clean_matrix( $cur ) );
		if ( '' === $account ) {
			$data['matrix'] = $cur;
		} else {
			$data['accounts'][ $account ] = $cur;
			$data['accounts']             = array_filter( $data['accounts'] );
		}
		self::store( $owner, $data );
		return self::resolve( $data, $account );
	}

	/**
	 * Override one space (a group / a customer). $patch {row: mode|null}. @return array|WP_Error the space's overrides
	 */
	public static function save_space( int $owner, string $space, array $patch ) {
		$id = class_exists( 'BizCity_Vault_Spaces' ) ? BizCity_Vault_Spaces::id( $space ) : '';
		if ( '' === $id ) {
			return new WP_Error( 'space_invalid', 'Không gian không hợp lệ.', array( 'status' => 400, 'help_code' => 'twin_vault' ) );
		}
		$data = self::data( $owner );
		$cur  = $data['spaces'][ $id ] ?? array();
		foreach ( $patch as $row => $mode ) {
			if ( ! isset( self::DEFAULTS[ $row ] ) ) {
				return new WP_Error( 'policy_cell_unknown', 'Không có việc này trong ma trận: ' . $row, array( 'status' => 400, 'help_code' => 'twin_vault' ) );
			}
			if ( null === $mode ) {
				unset( $cur[ $row ] );
			} elseif ( in_array( (string) $mode, self::MODES, true ) ) {
				$cur[ $row ] = (string) $mode;
			} else {
				return new WP_Error( 'policy_mode_invalid', 'Chế độ chỉ là auto, ask hoặc off.', array( 'status' => 400, 'help_code' => 'twin_vault' ) );
			}
		}
		$data['spaces'][ $id ] = self::clean_space( $cur );
		$data['spaces']        = array_filter( $data['spaces'] );
		self::store( $owner, $data );
		return $data['spaces'][ $id ] ?? array();
	}

	/** What the cell / the screen reads: {contract, owner, account, rows, cols, defaults, matrix, spaces, overrides}. */
	public static function snapshot( int $owner, string $account = '' ): array {
		$data = self::data( $owner );
		return array(
			'contract'  => self::CONTRACT,
			'owner'     => $owner,
			'account'   => $account,
			'rows'      => self::ROWS,
			'cols'      => self::COLS,
			'defaults'  => self::DEFAULTS,
			'matrix'    => self::resolve( $data, $account ),
			'spaces'    => $data['spaces'],
			'overrides' => self::override_counts( $data ),
		);
	}
}
