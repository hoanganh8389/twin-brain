<?php
/**
 * BizCity_Vault_Spaces — "Không gian" of a Kho tri thức: one ngăn per Zalo group, one per customer (PHASE-0.153 V2, doc 00 §4.2–§4.3).
 *
 * Owner 15/11: "cho nhóm, cho khách có thể thiết kế dạng folder và chứa được tài liệu biên dịch tương ứng của group, khách yêu cầu lưu".
 *
 * A space lives INSIDE the Kho of the user id that owns the Zalo number (BizCity_Vault_Owner) — nothing here is site-wide:
 *   space id   `group:<thread_id>` · `contact:<identity_uuid>`
 *   notes      ghi chú / việc giao / biên bản = ordinary library notes of that Kho under /khong-gian/<nhom|khach>-<hash>/<ghi-chu|bien-ban>/…md
 *              (written through BizCity_Twin_Vault::write: one Journal row each, search, graph and the note view come with it)
 *   files      the existing Zalo capture (original in the private store ⇒ Journal row ⇒ the cell's .md reading = the "bản biên dịch");
 *              their row carries the space TAG `sp-g-<hash>` / `sp-c-<hash>` (Journal meta keeps no unknown key; tags are filterable)
 *   quota      group 500 files / 2 GB · contact 10 files / 20 MB (Q153-2); over it ⇒ `space_quota_full`, nothing stored
 *   access     can_read(): Kho owner / admin all · staff a group they are a member of (Q153-4) · a Guru answering a customer only that
 *              customer's space (Q153-5) · a Guru answering in a group only that group's space · anyone else nothing
 *
 * Paths and tags carry a hash, never a phone number, a name or a raw UID (R-CID-11). No model call, no new table.
 *
 * [2026-11-15 10:50 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.153 V2 — new file.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Vault
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Vault_Spaces', false ) ) {
	return;
}

final class BizCity_Vault_Spaces {

	const CONTRACT = 'vault-space@1.0.0';
	const ROOT     = '/khong-gian/';
	const SECTIONS = array( 'ghi-chu', 'bien-ban' );
	/** type ⇒ [max files, max bytes] (Q153-2: a customer 10 files / 20 MB; a group: doc 00 §7) */
	const QUOTA    = array(
		'group'   => array( 500, 2147483648 ),
		'contact' => array( 10, 20971520 ),
	);

	// ── pure ────────────────────────────────────────────────────────────────

	/** `group:<thread>` / `contact:<uuid>` ⇒ {type, key}; null for anything else. Pure. */
	public static function parse( string $space ): ?array {
		if ( 1 !== preg_match( '/^(group|contact):(.+)$/', trim( $space ), $m ) ) {
			return null;
		}
		$key = strtolower( trim( $m[2] ) );
		if ( 1 !== preg_match( '/^[a-z0-9][a-z0-9._:-]{0,99}$/', $key ) ) {
			return null;
		}
		return array( 'type' => $m[1], 'key' => $key );
	}

	/** The normalised id of a space, '' when invalid. Pure. */
	public static function id( string $space ): string {
		$p = self::parse( $space );
		return null === $p ? '' : $p['type'] . ':' . $p['key'];
	}

	/** 12 hex of the space id: what paths and tags show instead of the id itself. Pure. */
	public static function hash( string $space ): string {
		$id = self::id( $space );
		return '' === $id ? '' : substr( md5( $id ), 0, 12 );
	}

	/** The Journal tag of a space's rows: sp-g-<hash> / sp-c-<hash>; '' when invalid. Pure. */
	public static function tag( string $space ): string {
		$p = self::parse( $space );
		return null === $p ? '' : 'sp-' . ( 'group' === $p['type'] ? 'g' : 'c' ) . '-' . self::hash( $space );
	}

	/** The library folder of a space: /khong-gian/nhom-<hash>/ or /khong-gian/khach-<hash>/; '' when invalid. Pure. */
	public static function base_path( string $space ): string {
		$p = self::parse( $space );
		return null === $p ? '' : self::ROOT . ( 'group' === $p['type'] ? 'nhom' : 'khach' ) . '-' . self::hash( $space ) . '/';
	}

	/** Is this library path inside a space? Pure. */
	public static function is_space_path( string $path ): bool {
		return 0 === strpos( '/' . ltrim( $path, '/' ), self::ROOT );
	}

	/**
	 * May this viewer read this space? Pure (doc 00 §4.3).
	 *
	 * @param array $viewer {role: owner|admin|staff|guru_customer|guru_group, member?: bool (staff is a member of the group),
	 *                      identity?: string (the customer a Guru answers), thread?: string (the group a Guru answers in)}
	 */
	public static function can_read( string $space, array $viewer ): bool {
		$p = self::parse( $space );
		if ( null === $p ) {
			return false;
		}
		switch ( (string) ( $viewer['role'] ?? '' ) ) {
			case 'owner':
			case 'admin':
				return true;
			case 'staff':
				return 'group' === $p['type'] && true === ( $viewer['member'] ?? false );
			case 'guru_customer':
				return 'contact' === $p['type'] && strtolower( trim( (string) ( $viewer['identity'] ?? '' ) ) ) === $p['key'];
			case 'guru_group':
				return 'group' === $p['type'] && strtolower( trim( (string) ( $viewer['thread'] ?? '' ) ) ) === $p['key'];
		}
		return false;
	}

	/** Test seam: fn(int $user_id, int $owner): string[] group thread ids the staff user is assigned to. */
	public static $staff_groups = null;

	/** @var array<string,array<string,bool>> per-request memo */
	private static $staff_memo = array();

	/**
	 * [2026-11-17 11:36 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.153 (Q153-4) — the group spaces a staff user may read: the groups of a number owned by
	 * `$owner` where the user's Zalo UID (staff row of that number) is an assignee ("nhân sự được giao nhóm = thành viên"). Tag ⇒ true.
	 *
	 * @return array<string,bool>
	 */
	public static function staff_space_tags( int $user_id, int $owner ): array {
		if ( $user_id <= 0 || $owner <= 0 ) {
			return array();
		}
		$key = $user_id . ':' . $owner;
		if ( isset( self::$staff_memo[ $key ] ) ) {
			return self::$staff_memo[ $key ];
		}
		$gids = is_callable( self::$staff_groups ) ? (array) call_user_func( self::$staff_groups, $user_id, $owner ) : self::db_staff_groups( $user_id, $owner );
		$out  = array();
		foreach ( $gids as $gid ) {
			$tag = self::tag( 'group:' . (string) $gid );
			if ( '' !== $tag ) {
				$out[ $tag ] = true;
			}
		}
		return self::$staff_memo[ $key ] = $out;
	}

	public static function reset_staff_memo(): void {
		self::$staff_memo = array();
	}

	private static function db_staff_groups( int $user_id, int $owner ): array {
		if ( ! class_exists( 'BizCity_Zalo_Mapping_Repo' ) || ! method_exists( 'BizCity_Zalo_Mapping_Repo', 'list_personal_accounts_for_owner' )
			|| ! class_exists( 'BizCity_Zalo_Agent_Principals' ) || ! class_exists( 'BizCity_CRM_Group_Secretary' ) ) {
			return array();
		}
		$gids = array();
		foreach ( (array) BizCity_Zalo_Mapping_Repo::list_personal_accounts_for_owner( $owner ) as $row ) {
			$b = (string) ( ( (array) $row )['bridge_account_id'] ?? '' );
			if ( '' === $b ) {
				continue;
			}
			foreach ( array_unique( array( $b, 0 === strpos( $b, 'rzh:' ) ? substr( $b, 4 ) : $b ) ) as $bridge ) {
				$uid = '';
				foreach ( BizCity_Zalo_Agent_Principals::from_policy( BizCity_Zalo_Agent_Principals::policy( $bridge ) ) as $s ) {
					if ( (int) ( $s['user_id'] ?? 0 ) === $user_id && ! empty( $s['enabled'] ) ) {
						$uid = (string) ( $s['zalo_uid'] ?? '' );
						break;
					}
				}
				if ( '' === $uid ) {
					continue;
				}
				foreach ( BizCity_CRM_Group_Secretary::all_settings( $bridge ) as $gid => $set ) {
					foreach ( (array) ( $set['assignees'] ?? array() ) as $a ) {
						if ( (string) ( $a['uid'] ?? '' ) === $uid ) {
							$gids[ (string) $gid ] = true;
						}
					}
				}
			}
		}
		return array_keys( $gids );
	}

	/**
	 * Pure: may a tree item tagged with `$space_tag` be shown to `$role`? Owner: every space. Staff: only a group space in `$staff_tags`.
	 * Anyone else (public / a customer view): no space. A customer space is the owner's only (§4.3).
	 */
	public static function tag_visible( string $space_tag, string $role, array $staff_tags ): bool {
		if ( 'owner' === $role ) {
			return true;
		}
		return 'staff' === $role && 0 === strpos( $space_tag, 'sp-g-' ) && ! empty( $staff_tags[ $space_tag ] );
	}

	/** Over quota after adding ($files, $bytes)? Pure. @param array $usage {files, bytes} */
	public static function over_quota( string $space, array $usage, int $add_files = 1, int $add_bytes = 0 ): bool {
		$p = self::parse( $space );
		if ( null === $p ) {
			return true;
		}
		list( $max_files, $max_bytes ) = self::QUOTA[ $p['type'] ];
		return (int) ( $usage['files'] ?? 0 ) + $add_files > $max_files || (int) ( $usage['bytes'] ?? 0 ) + $add_bytes > $max_bytes;
	}

	// ── site ────────────────────────────────────────────────────────────────

	/**
	 * Files kept in a space of the acting Kho (Journal rows with its tag, notes not counted): {files, bytes, max_files, max_bytes}.
	 */
	public static function usage( string $space, int $owner = 0 ): array {
		$p     = self::parse( $space );
		$quota = null === $p ? array( 0, 0 ) : self::QUOTA[ $p['type'] ];
		$out   = array( 'files' => 0, 'bytes' => 0, 'max_files' => $quota[0], 'max_bytes' => $quota[1] );
		$owner = $owner > 0 ? $owner : self::owner();
		if ( null === $p || $owner <= 0 || ! class_exists( 'BizCity_Journal_Index' ) ) {
			return $out;
		}
		for ( $off = 0; $off < 1000; $off += 50 ) {
			$res = BizCity_Journal_Index::find( array( 'owner_user_id' => $owner, 'tags' => array( self::tag( $space ) ), 'limit' => 50, 'offset' => $off ) );
			foreach ( (array) ( $res['items'] ?? array() ) as $e ) {
				if ( 'common.note' === (string) ( $e['kind'] ?? '' ) ) {
					continue;
				}
				++$out['files'];
				$out['bytes'] += (int) ( $e['original']['size'] ?? 0 );
			}
			if ( count( (array) ( $res['items'] ?? array() ) ) < 50 ) {
				break;
			}
		}
		return $out;
	}

	/** WP_Error `space_quota_full` when one more file of $bytes would pass the space's limit; true otherwise. */
	public static function check_quota( string $space, int $bytes = 0, int $owner = 0 ) {
		$u = self::usage( $space, $owner );
		if ( ! self::over_quota( $space, $u, 1, $bytes ) ) {
			return true;
		}
		$p   = self::parse( $space );
		$who = null !== $p && 'contact' === $p['type'] ? 'khách này' : 'nhóm này';
		return new WP_Error( 'space_quota_full', 'Ngăn của ' . $who . ' đã đầy (' . $u['max_files'] . ' tệp / ' . self::mb( $u['max_bytes'] ) . ').', array( 'status' => 413, 'hint' => 'Xoá bớt tệp cũ trong ngăn hoặc nhờ chủ trợ lý mở rộng.', 'help_code' => 'twin_vault', 'usage' => $u ) );
	}

	/**
	 * Write one note (ghi chú, việc giao) or one day's minutes (biên bản) into a space of the acting Kho.
	 * $args {title, markdown, label? (shown name of the group / customer — never a phone), date? (Y-m-d, minutes: one file per day)}
	 *
	 * @return array|WP_Error BizCity_Twin_Vault::write() result + {space, tag}
	 */
	public static function write_note( string $space, string $section, array $args, int $owner = 0 ) {
		$id = self::id( $space );
		if ( '' === $id || ! in_array( $section, self::SECTIONS, true ) ) {
			return new WP_Error( 'space_invalid', 'Không gian không hợp lệ.', array( 'status' => 400, 'help_code' => 'twin_vault' ) );
		}
		if ( ! class_exists( 'BizCity_Twin_Vault' ) ) {
			return new WP_Error( 'vault_unavailable', 'Kho tri thức chưa sẵn sàng trên site này.', array( 'status' => 503, 'help_code' => 'twin_vault' ) );
		}
		$owner = $owner > 0 ? $owner : self::owner();
		if ( $owner <= 0 ) {
			return new WP_Error( 'space_no_owner', 'Không xác định được chủ Kho của ngăn này.', array( 'status' => 400, 'help_code' => 'twin_vault' ) );
		}
		$title = trim( (string) ( $args['title'] ?? '' ) );
		$date  = 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $args['date'] ?? '' ) ) ? (string) $args['date'] : gmdate( 'Y-m-d' );
		$name  = 'bien-ban' === $section ? $date . '.md' : BizCity_Twin_Vault::slug( '' !== $title ? $title : 'ghi-chu' );
		$path  = self::base_path( $id ) . $section . '/' . $name;
		$label = mb_substr( trim( (string) ( $args['label'] ?? '' ) ), 0, 80 );
		$tag   = self::tag( $id );
		$res   = class_exists( 'BizCity_Vault_Owner' )
			? BizCity_Vault_Owner::with( $owner, static function () use ( $args, $title, $path, $id, $section, $label, $owner, $date ) {
				return self::write_now( $args, $title, $path, $id, $section, $label, $owner, $date );
			} )
			: self::write_now( $args, $title, $path, $id, $section, $label, $owner, $date );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( (int) ( $res['entry_id'] ?? 0 ) > 0 && class_exists( 'BizCity_Journal_Index' ) ) {
			BizCity_Journal_Index::file( (int) $res['entry_id'], $owner, array( 'tags' => array( $tag ) ) ); // the space tag (links kept by file())
		}
		return $res + array( 'space' => $id, 'tag' => $tag );
	}

	private static function write_now( array $args, string $title, string $path, string $id, string $section, string $label, int $owner, string $date ) {
		return BizCity_Twin_Vault::write( array(
			'title'       => '' !== $title ? $title : ( 'bien-ban' === $section ? 'Biên bản ' . $date : 'Ghi chú' ),
			'markdown'    => (string) ( $args['markdown'] ?? '' ),
			'path'        => $path,
			'frontmatter' => array_filter( array( 'space' => $id, 'space_label' => $label, 'section' => $section ) ),
		), array( 'owner_user_id' => $owner, 'role' => 'owner', 'vrole' => 'owner' ) );
	}

	/**
	 * The spaces of the acting Kho that have notes: [{space, type, label, hash, notes}] (a space with files only shows once a note exists, V5 adds the rest).
	 */
	public static function spaces(): array {
		if ( ! class_exists( 'BizCity_Twin_Vault' ) ) {
			return array();
		}
		$out = array();
		foreach ( BizCity_Twin_Vault::lib_files() as $f ) {
			if ( ! self::is_space_path( (string) $f['path'] ) ) {
				continue;
			}
			$id = self::id( (string) ( $f['fm']['space'] ?? '' ) );
			if ( '' === $id ) {
				continue;
			}
			if ( ! isset( $out[ $id ] ) ) {
				$p          = self::parse( $id );
				$out[ $id ] = array( 'space' => $id, 'type' => $p['type'], 'label' => '', 'hash' => self::hash( $id ), 'notes' => 0 );
			}
			++$out[ $id ]['notes'];
			if ( '' === $out[ $id ]['label'] && '' !== (string) ( $f['fm']['space_label'] ?? '' ) ) {
				$out[ $id ]['label'] = (string) $f['fm']['space_label'];
			}
		}
		return array_values( $out );
	}

	/** The notes of one space in the acting Kho: [{path, title, section, updated}], newest first. */
	public static function notes( string $space ): array {
		$base = self::base_path( $space );
		if ( '' === $base || ! class_exists( 'BizCity_Twin_Vault' ) ) {
			return array();
		}
		$out = array();
		foreach ( BizCity_Twin_Vault::lib_files() as $f ) {
			if ( 0 !== strpos( (string) $f['path'], $base ) ) {
				continue;
			}
			$out[] = array( 'path' => (string) $f['path'], 'title' => (string) ( $f['fm']['title'] ?? basename( (string) $f['path'], '.md' ) ), 'section' => (string) ( $f['fm']['section'] ?? '' ), 'updated' => (string) ( $f['fm']['updated'] ?? '' ) );
		}
		usort( $out, static function ( $a, $b ) { return strcmp( $b['updated'], $a['updated'] ); } );
		return $out;
	}

	private static function owner(): int {
		return class_exists( 'BizCity_Vault_Owner' ) ? BizCity_Vault_Owner::current() : 0;
	}

	private static function mb( int $bytes ): string {
		return $bytes >= 1073741824 ? round( $bytes / 1073741824, 1 ) . ' GB' : round( $bytes / 1048576 ) . ' MB';
	}
}
