<?php
/**
 * BizCity_Memory_Vault_Store — Guru memory as Markdown files in the Vault (memory-vault@1).
 *
 * [2026-11-14 11:04 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.151 M1 (owner 14/11: "trí nhớ Guru sẽ lưu vào vault .md … folder 05 Ghi chú (memory),
 * thay vì lưu vào các bảng memory"; Q151-7…11 chốt) — design: core/channel-gateway/docs/PHASE-0.151-NOTEBOOK-BRAIN-CORE/60-GURU-MEMORY-TO-VAULT-MD.md.
 *
 * Where: the `memory` namespace of BizCity_Brain_Vault_Store — the vault root (BIZCITY_VAULT_ROOT / option / uploads/bizcity-vault) with its
 * deny files, NOT the skills library in uploads/bizcity-skills (no deny file, 400-file cap shared with every note). Memory about customers is
 * personal data: it is reached through this class (server side) and, in the Vault UI, through its Journal rows filed in 05/tri-nho (owner only).
 *
 * Layout (one set per Guru × person, Q151-7):
 *   g<guru>/<subject>/profile.md              user memory  — replaced as a whole
 *   g<guru>/<subject>/rolling.md              rolling summary — replaced as a whole
 *   g<guru>/<subject>/episodic-YYYY-MM.md     episodic — append only, one file a month (…-YYYY-MM-2.md when a file passes MAX_BYTES)
 *   sessions/<session>.md                     session memory — replaced as a whole
 * <subject> = a stable identity key (identity_uuid / "wp-<id>"), never a phone number or a channel UID (R-CID-11).
 *
 * Each entry of an append file is a visible Markdown section plus one machine line `<!-- mem:{json} -->` carrying the exact record, so the
 * file stays readable / editable by the owner and the reader gets the record back unchanged.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Vault
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Memory_Vault_Store', false ) ) {
	return;
}

final class BizCity_Memory_Vault_Store {

	const CONTRACT    = 'memory-vault@1.0.0';
	const NS          = 'memory';
	const KINDS       = array( 'profile', 'rolling', 'episodic', 'session' );
	const MAX_BYTES   = 200000; // same ceiling as a Vault note (BizCity_Twin_Vault::MAX_MD)
	const MAX_ENTRIES = 2000;   // per read, newest kept
	const VAULT_FOLDER = '05/tri-nho'; // where the Journal rows of memory files are filed (owner only — BizCity_Twin_Vault::SUBFOLDER_ACCESS)
	const MAX_KEYED   = 500;    // entries per keyed file (= BizCity_User_Memory::MAX_PER_USER), oldest out — PHASE-0.151 M2
	const MAX_SCAN    = 5000;   // files a delete without a subject may open — PHASE-0.151 M2

	// ── pure ────────────────────────────────────────────────────────────────

	/** A safe path segment: lowercase [a-z0-9-], 1..64 chars; '' otherwise. Pure. */
	public static function key( string $raw ): string {
		$k = strtolower( trim( $raw ) );
		$k = (string) preg_replace( '/[^a-z0-9-]+/', '-', $k );
		$k = trim( $k, '-' );
		return ( '' !== $k && strlen( $k ) <= 64 ) ? $k : '';
	}

	/**
	 * Relative path of one memory file inside the namespace; '' for a bad argument. Pure.
	 * $kind episodic needs $month "YYYY-MM" (and $part ≥ 2 for an overflow file); session uses $subject as the session key and ignores $guru_id.
	 */
	public static function path_for( int $guru_id, string $subject, string $kind, string $month = '', int $part = 1 ): string {
		$s = self::key( $subject );
		if ( '' === $s || ! in_array( $kind, self::KINDS, true ) ) {
			return '';
		}
		if ( 'session' === $kind ) {
			return 'sessions/' . $s . '.md';
		}
		if ( $guru_id < 0 ) {
			return '';
		}
		// [2026-11-15 12:41 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.151 M2 (doc 60 §2.7) — guru 0 = "chung/": memory shared by every Guru, which is what the
		// identity-scoped memory classes write today (no guru on their records). A per-Guru set (g<id>/) stays available for a caller that has one.
		$base = ( 0 === $guru_id ? 'chung' : 'g' . $guru_id ) . '/' . $s . '/';
		if ( 'episodic' !== $kind ) {
			return $base . $kind . '.md';
		}
		if ( 1 !== preg_match( '/^\d{4}-\d{2}$/', $month ) ) {
			return '';
		}
		return $base . 'episodic-' . $month . ( $part > 1 ? '-' . $part : '' ) . '.md';
	}

	/** One append entry as Markdown: "## <when> · <title>" + text + the machine line. Pure. */
	public static function render_entry( array $record ): string {
		$at    = (string) ( $record['at'] ?? '' );
		$title = trim( preg_replace( '/\s+/', ' ', (string) ( $record['title'] ?? '' ) ) );
		$text  = trim( (string) preg_replace( "/\r\n?/", "\n", (string) ( $record['text'] ?? '' ) ) );
		$json  = function_exists( 'wp_json_encode' ) ? wp_json_encode( $record, JSON_UNESCAPED_UNICODE ) : json_encode( $record, JSON_UNESCAPED_UNICODE );
		$json  = str_replace( '--', '- -', (string) $json ); // an HTML comment may not contain "--"
		$head  = '## ' . ( '' !== $at ? $at : '—' ) . ( '' !== $title ? ' · ' . mb_substr( $title, 0, 120 ) : '' );
		return $head . "\n" . ( '' !== $text ? str_replace( '<!--', '<! --', $text ) . "\n" : '' ) . '<!-- mem:' . $json . " -->\n";
	}

	/** Every record carried by the machine lines of a body, in file order. Pure. */
	public static function parse_entries( string $body ): array {
		$out = array();
		if ( preg_match_all( '/<!-- mem:(\{.*?\}) -->/su', $body, $m ) ) {
			foreach ( $m[1] as $raw ) {
				$rec = json_decode( str_replace( '- -', '--', $raw ), true );
				if ( is_array( $rec ) ) {
					$out[] = $rec;
				}
			}
		}
		return $out;
	}

	/** The site-built frontmatter of a memory file (twin-vault@1 + memory keys). Pure. */
	public static function frontmatter( int $guru_id, string $subject, string $kind, array $extra = array() ): array {
		$fm = array(
			'schema'          => 'twin-vault@1',
			'type'            => 'note',
			'kind'            => 'memory_' . $kind,
			'memory_contract' => self::CONTRACT,
			'guru_id'         => $guru_id,
			'subject'         => self::key( $subject ),
			'folder'          => self::VAULT_FOLDER,
			'updated'         => gmdate( 'Y-m-d\TH:i:s\Z' ),
		);
		foreach ( array( 'title', 'channel_account', 'source_contract', 'display_name' ) as $k ) {
			if ( isset( $extra[ $k ] ) && '' !== trim( (string) $extra[ $k ] ) ) {
				$fm[ $k ] = mb_substr( trim( (string) $extra[ $k ] ), 0, 190 );
			}
		}
		return $fm;
	}

	// ── site ────────────────────────────────────────────────────────────────

	/**
	 * Absolute namespace dir of the acting Kho owner (created, with the root deny files), '' when the vault is not usable.
	 * [2026-11-15 09:59 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.153 V1 — memory lives in the Kho of the user id that owns the Zalo number
	 * (BizCity_Vault_Owner::current()): <root>/{blog}/u{id}/memory/, the legacy site owner keeps <root>/{blog}/memory/ (Q153-8).
	 */
	public static function dir(): string {
		if ( ! class_exists( 'BizCity_Brain_Vault_Store' ) ) {
			return '';
		}
		$owner = self::owner();
		return $owner > 0 ? BizCity_Brain_Vault_Store::ensure_user( self::NS, $owner ) : BizCity_Brain_Vault_Store::ensure( self::NS );
	}

	/** dir() for reading: never creates anything. */
	private static function read_dir(): string {
		if ( ! class_exists( 'BizCity_Brain_Vault_Store' ) ) {
			return '';
		}
		$owner = self::owner();
		return $owner > 0 ? BizCity_Brain_Vault_Store::user_dir( self::NS, $owner ) : BizCity_Brain_Vault_Store::namespace_dir( self::NS );
	}

	/**
	 * [2026-11-15 09:59 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.153 V1 — every user id that has a memory folder: the legacy site owner (pre-0.153 place)
	 * plus each <root>/{blog}/u{id}/memory/. A delete whose owner is not known (an admin removing a customer's memory) searches all of them.
	 *
	 * @return int[]
	 */
	public static function owners_with_memory(): array {
		$out = array();
		if ( class_exists( 'BizCity_Vault_Owner' ) && BizCity_Vault_Owner::legacy() > 0 ) {
			$out[] = BizCity_Vault_Owner::legacy();
		}
		$root = class_exists( 'BizCity_Brain_Vault_Store' ) ? BizCity_Brain_Vault_Store::root() : '';
		if ( '' !== $root ) {
			foreach ( (array) glob( $root . 'u*/' . self::NS, GLOB_ONLYDIR ) as $d ) {
				if ( preg_match( '#/u(\d+)/' . self::NS . '$#', str_replace( '\\', '/', (string) $d ), $m ) ) {
					$out[] = (int) $m[1];
				}
			}
		}
		return array_values( array_unique( array_filter( $out ) ) );
	}

	/** The user id whose Kho memory is read / written now; 0 = no owner known (pre-0.153 place). */
	public static function owner(): int {
		return class_exists( 'BizCity_Vault_Owner' ) ? BizCity_Vault_Owner::current() : 0;
	}

	/**
	 * Replace a whole memory file (profile / rolling / session). $body is Markdown; any frontmatter in it is dropped (the site builds it).
	 *
	 * @return array|WP_Error {path, bytes}
	 */
	public static function put( int $guru_id, string $subject, string $kind, string $body, array $extra = array() ) {
		if ( 'episodic' === $kind ) {
			return self::err( 'memory_use_append', 'Trí nhớ sự kiện chỉ ghi thêm.', 400 );
		}
		$rel = self::path_for( $guru_id, $subject, $kind );
		if ( '' === $rel ) {
			return self::err( 'memory_bad_key', 'Khoá trí nhớ không hợp lệ.', 400 );
		}
		$body = class_exists( 'BizCity_Brain_Vault_Store' ) ? BizCity_Brain_Vault_Store::split_frontmatter( $body )['body'] : $body;
		$md   = self::emit( self::frontmatter( $guru_id, $subject, $kind, $extra ), $body );
		if ( strlen( $md ) > self::MAX_BYTES ) {
			return self::err( 'memory_too_large', 'Trí nhớ quá lớn (> 200 KB).', 413 );
		}
		return self::locked( $rel, static function ( string $abs ) use ( $md ) {
			return self::atomic_write( $abs, $md );
		} );
	}

	/**
	 * Append one episodic record {at?, title?, text, …} to this month's file (a new part when the file passed MAX_BYTES).
	 *
	 * @return array|WP_Error {path, bytes, entries}
	 */
	public static function append( int $guru_id, string $subject, array $record, array $extra = array() ) {
		$record['at'] = (string) ( $record['at'] ?? gmdate( 'Y-m-d\TH:i:s\Z' ) );
		$month        = substr( $record['at'], 0, 7 );
		if ( '' === self::path_for( $guru_id, $subject, 'episodic', $month ) ) {
			return self::err( 'memory_bad_key', 'Khoá trí nhớ không hợp lệ.', 400 );
		}
		$entry = self::render_entry( $record );
		$dir   = self::dir();
		if ( '' === $dir ) {
			return self::unavailable();
		}
		$part = 1;
		while ( $part < 50 ) {
			$rel = self::path_for( $guru_id, $subject, 'episodic', $month, $part );
			$abs = $dir . $rel;
			if ( ! is_file( $abs ) || filesize( $abs ) + strlen( $entry ) <= self::MAX_BYTES ) {
				break;
			}
			$part++;
		}
		$fm = self::frontmatter( $guru_id, $subject, 'episodic', $extra + array( 'title' => 'Nhật ký ' . $month ) );
		return self::locked( $rel, static function ( string $abs ) use ( $fm, $entry ) {
			$cur  = is_file( $abs ) ? (string) file_get_contents( $abs ) : '';
			$body = '' !== $cur && class_exists( 'BizCity_Brain_Vault_Store' ) ? BizCity_Brain_Vault_Store::split_frontmatter( $cur )['body'] : '';
			$body = rtrim( $body ) . ( '' !== trim( $body ) ? "\n\n" : '' ) . $entry;
			$res = self::atomic_write( $abs, self::emit( $fm, $body ) );
			return is_array( $res ) ? $res + array( 'entries' => count( self::parse_entries( $body ) ) ) : $res;
		} );
	}

	/**
	 * Read one memory file. episodic + $month '' ⇒ every month of that person (all parts), entries oldest first, ≤ MAX_ENTRIES newest.
	 *
	 * @return array {exists, frontmatter, body, entries}
	 */
	public static function get( int $guru_id, string $subject, string $kind, string $month = '' ): array {
		$empty = array( 'exists' => false, 'frontmatter' => array(), 'body' => '', 'entries' => array() );
		$dir   = self::read_dir();
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return $empty;
		}
		if ( 'episodic' === $kind ) {
			$base = self::path_for( $guru_id, $subject, 'episodic', '' !== $month ? $month : '2000-01' );
			if ( '' === $base ) {
				return $empty;
			}
			$pattern = $dir . dirname( $base ) . '/episodic-' . ( '' !== $month ? $month : '[0-9][0-9][0-9][0-9]-[0-9][0-9]' ) . '*.md';
			$files   = (array) glob( $pattern );
			natsort( $files );
			$entries = array();
			foreach ( $files as $abs ) {
				if ( BizCity_Brain_Vault_Store::inside( $dir, $abs ) ) {
					$entries = array_merge( $entries, self::parse_entries( (string) file_get_contents( $abs ) ) );
				}
			}
			return array( 'exists' => (bool) $files, 'frontmatter' => array(), 'body' => '', 'entries' => array_slice( $entries, -self::MAX_ENTRIES ) );
		}
		$rel = self::path_for( $guru_id, $subject, $kind );
		$abs = $dir . $rel;
		if ( '' === $rel || ! BizCity_Brain_Vault_Store::inside( $dir, $abs ) || ! is_readable( $abs ) ) {
			return $empty;
		}
		$split = BizCity_Brain_Vault_Store::split_frontmatter( (string) file_get_contents( $abs ) );
		return array( 'exists' => true, 'frontmatter' => $split['frontmatter'], 'body' => $split['body'], 'entries' => self::parse_entries( $split['body'] ) );
	}

	/**
	 * [2026-11-15 12:41 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.151 M2 — insert or replace ONE keyed entry {id, at, title, text, rec} of a memory file
	 * (profile / rolling / session: one file; episodic: the part of the entry's month that already holds the id, else the first part with room).
	 * Oldest entries (by `at`) are dropped past MAX_KEYED or MAX_BYTES. An entry identical to the stored one is not rewritten.
	 *
	 * @return array|WP_Error {path, bytes?, created, changed, entries}
	 */
	public static function upsert_record( int $guru_id, string $subject, string $kind, array $entry, array $extra = array() ) {
		$entry['id'] = (string) ( $entry['id'] ?? '' );
		$entry['at'] = (string) ( $entry['at'] ?? gmdate( 'Y-m-d\TH:i:s\Z' ) );
		if ( '' === $entry['id'] ) {
			return self::err( 'memory_bad_entry', 'Mục trí nhớ thiếu khoá.', 400 );
		}
		$month = 'episodic' === $kind ? substr( $entry['at'], 0, 7 ) : '';
		if ( '' === self::path_for( $guru_id, $subject, $kind, $month ) ) {
			return self::err( 'memory_bad_key', 'Khoá trí nhớ không hợp lệ.', 400 );
		}
		$dir = self::dir();
		if ( '' === $dir ) {
			return self::unavailable();
		}
		$rel = self::path_for( $guru_id, $subject, $kind, $month );
		if ( 'episodic' === $kind ) {
			$size = strlen( self::render_entry( $entry ) );
			for ( $part = 1; $part < 50; $part++ ) {
				$rel = self::path_for( $guru_id, $subject, 'episodic', $month, $part );
				$abs = $dir . $rel;
				if ( ! is_file( $abs ) || false !== strpos( (string) file_get_contents( $abs ), self::id_needle( $entry['id'] ) ) || filesize( $abs ) + $size <= self::MAX_BYTES ) {
					break;
				}
			}
		}
		$fm = self::frontmatter( $guru_id, $subject, $kind, $extra + array( 'title' => self::title_of( $kind, $month ) ) );
		return self::locked( $rel, static function ( string $abs ) use ( $fm, $entry, $kind, $month ) {
			$created = ! is_file( $abs );
			$entries = $created ? array() : self::parse_entries( (string) file_get_contents( $abs ) );
			$found   = false;
			foreach ( $entries as $i => $e ) {
				if ( (string) ( $e['id'] ?? '' ) === $entry['id'] ) {
					if ( $e == $entry ) { // phpcs:ignore Universal.Operators.StrictComparisons — same record, any key order
						return array( 'created' => false, 'changed' => false, 'entries' => count( $entries ) );
					}
					$entries[ $i ] = $entry;
					$found         = true;
					break;
				}
			}
			if ( ! $found ) {
				$entries[] = $entry;
			}
			$md = self::render_file( $fm, $kind, $month, $entries );
			$res = self::atomic_write( $abs, $md );
			return is_array( $res ) ? $res + array( 'created' => $created, 'changed' => true, 'entries' => count( self::parse_entries( $md ) ) ) : $res;
		} );
	}

	/**
	 * Remove every entry whose id — or whose record's `record_id` — is $id. $subject '' ⇒ every memory file is searched (a delete that does not
	 * know whose memory it was; bounded by MAX_SCAN files). A file left with no entry is deleted.
	 *
	 * @return int entries removed
	 */
	public static function delete_record( string $id, string $subject = '', int $guru_id = 0 ): int {
		$dir = self::read_dir();
		if ( '' === trim( $id ) || '' === $dir || ! is_dir( $dir ) ) {
			return 0;
		}
		$s     = self::key( $subject );
		$files = '' !== $s
			? array_merge( (array) glob( $dir . ( 0 === $guru_id ? 'chung' : 'g' . $guru_id ) . '/' . $s . '/*.md' ), (array) glob( $dir . 'sessions/' . $s . '.md' ) )
			: array_merge( (array) glob( $dir . '*/*/*.md' ), (array) glob( $dir . 'sessions/*.md' ) );
		$removed = 0;
		foreach ( array_slice( array_values( array_filter( $files ) ), 0, self::MAX_SCAN ) as $abs ) {
			if ( ! BizCity_Brain_Vault_Store::inside( $dir, $abs ) || false === strpos( (string) file_get_contents( $abs ), $id ) ) {
				continue;
			}
			$rel = ltrim( str_replace( '\\', '/', substr( $abs, strlen( $dir ) ) ), '/' );
			$res = self::locked( $rel, static function ( string $file ) use ( $id ) {
				$raw     = (string) file_get_contents( $file );
				$split   = BizCity_Brain_Vault_Store::split_frontmatter( $raw );
				$entries = self::parse_entries( $split['body'] );
				$keep    = array_values( array_filter( $entries, static function ( $e ) use ( $id ) {
					return (string) ( $e['id'] ?? '' ) !== $id && (string) ( $e['rec']['record_id'] ?? '' ) !== $id;
				} ) );
				$gone = count( $entries ) - count( $keep );
				if ( 0 === $gone ) {
					return array( 'gone' => 0 );
				}
				if ( ! $keep ) {
					@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					return array( 'gone' => $gone );
				}
				$fm   = (array) $split['frontmatter'];
				$kind = substr( (string) ( $fm['kind'] ?? 'memory_profile' ), 7 );
				$fm['updated'] = gmdate( 'Y-m-d\TH:i:s\Z' );
				$res = self::atomic_write( $file, self::render_file( $fm, in_array( $kind, self::KINDS, true ) ? $kind : 'profile', '', $keep ) );
				return is_array( $res ) ? array( 'gone' => $gone ) : $res;
			} );
			$removed += is_array( $res ) ? (int) ( $res['gone'] ?? 0 ) : 0;
		}
		return $removed;
	}

	/**
	 * [2026-11-15 07:38 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.151 M3 (owner 15/11: "ko cần xuất trí nhớ cũ sang .md, đổi logic là ok") —
	 * the stored records (`rec` of each entry) of one kind, newest first: the read side of BizCity_Context_Bank_Memory_Adapter::query.
	 * $subject (identity uuid, or the session key for kind session) narrows to that person's files; '' scans every file of the kind
	 * (≤ MAX_SCAN files — an admin list by user_id). The caller applies its own field filters.
	 *
	 * @return array<int,array>
	 */
	public static function records( string $kind, string $subject = '', int $guru_id = 0 ): array {
		$dir = self::read_dir();
		if ( ! in_array( $kind, self::KINDS, true ) || '' === $dir || ! is_dir( $dir ) ) {
			return array();
		}
		$s    = self::key( $subject );
		$name = 'episodic' === $kind ? 'episodic-*.md' : $kind . '.md';
		$set  = 0 === $guru_id ? 'chung' : 'g' . max( 0, $guru_id );
		if ( 'session' === $kind ) {
			$files = (array) glob( $dir . 'sessions/' . ( '' !== $s ? $s : '*' ) . '.md' );
		} else {
			$files = (array) glob( $dir . $set . '/' . ( '' !== $s ? $s : '*' ) . '/' . $name );
		}
		$out = array();
		foreach ( array_slice( array_values( array_filter( $files ) ), 0, self::MAX_SCAN ) as $abs ) {
			if ( ! BizCity_Brain_Vault_Store::inside( $dir, $abs ) || ! is_readable( $abs ) ) {
				continue;
			}
			foreach ( self::parse_entries( (string) file_get_contents( $abs ) ) as $e ) {
				$rec = is_array( $e['rec'] ?? null ) ? $e['rec'] : array();
				if ( ! $rec ) {
					continue;
				}
				if ( '' === (string) ( $rec['record_id'] ?? '' ) && '' !== (string) ( $e['id'] ?? '' ) ) {
					$rec['record_id'] = (string) $e['id'];
				}
				$rec['_at'] = (string) ( $e['at'] ?? '' );
				$out[]      = $rec;
			}
		}
		usort( $out, static function ( $a, $b ) {
			return strcmp( (string) $b['_at'], (string) $a['_at'] );
		} );
		return array_map( static function ( $r ) {
			unset( $r['_at'] );
			return $r;
		}, $out );
	}

	/** Delete one memory file (forget). @return bool */
	public static function forget( int $guru_id, string $subject, string $kind, string $month = '' ): bool {
		$dir = self::read_dir();
		$rel = self::path_for( $guru_id, $subject, $kind, $month );
		if ( '' === $dir || '' === $rel || ! BizCity_Brain_Vault_Store::inside( $dir, $dir . $rel ) || ! is_file( $dir . $rel ) ) {
			return false;
		}
		return @unlink( $dir . $rel ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	// ── internals ───────────────────────────────────────────────────────────

	/** The `"id":"…"` text a stored entry carries (render_entry JSON). Pure. */
	private static function id_needle( string $id ): string {
		return '"id":' . str_replace( '--', '- -', (string) ( function_exists( 'wp_json_encode' ) ? wp_json_encode( $id, JSON_UNESCAPED_UNICODE ) : json_encode( $id, JSON_UNESCAPED_UNICODE ) ) );
	}

	/** Heading of a memory file. Pure. */
	private static function title_of( string $kind, string $month ): string {
		$t = array( 'profile' => 'Trí nhớ — hồ sơ', 'rolling' => 'Trí nhớ — tóm tắt hội thoại', 'episodic' => 'Trí nhớ — nhật ký', 'session' => 'Trí nhớ — phiên' );
		return ( $t[ $kind ] ?? 'Trí nhớ' ) . ( '' !== $month ? ' ' . $month : '' );
	}

	/** A keyed file: "# title" + entries oldest first, trimmed (oldest out) to MAX_KEYED and MAX_BYTES. Pure (but emit needs the vault class). */
	private static function render_file( array $fm, string $kind, string $month, array $entries ): string {
		usort( $entries, static function ( $a, $b ) {
			return strcmp( (string) ( $a['at'] ?? '' ), (string) ( $b['at'] ?? '' ) );
		} );
		$entries = array_slice( $entries, -self::MAX_KEYED );
		$fm['updated'] = gmdate( 'Y-m-d\TH:i:s\Z' );
		$head = '# ' . ( '' !== (string) ( $fm['title'] ?? '' ) ? (string) $fm['title'] : self::title_of( $kind, $month ) ) . "\n\n";
		do {
			$md = self::emit( $fm, $head . implode( "\n", array_map( array( __CLASS__, 'render_entry' ), $entries ) ) );
		} while ( strlen( $md ) > self::MAX_BYTES && count( $entries ) > 1 && array_shift( $entries ) );
		return $md;
	}

	private static function emit( array $fm, string $body ): string {
		$yaml = class_exists( 'BizCity_Brain_Vault_Store' ) ? BizCity_Brain_Vault_Store::emit_yaml( $fm ) : '';
		return "---\n" . $yaml . "---\n" . trim( (string) preg_replace( "/\r\n?/", "\n", $body ) ) . "\n";
	}

	/** Run $fn( $abs ) under an exclusive lock on "<file>.lock" (concurrent bot + TwinChat writes of one person). */
	private static function locked( string $rel, callable $fn ) {
		$dir = self::dir();
		if ( '' === $dir ) {
			return self::unavailable();
		}
		$abs = $dir . $rel;
		if ( ! BizCity_Brain_Vault_Store::inside( $dir, $abs ) ) {
			return self::err( 'memory_path_refused', 'Đường dẫn trí nhớ không hợp lệ.', 400 );
		}
		$folder = dirname( $abs );
		if ( ! is_dir( $folder ) && ! ( function_exists( 'wp_mkdir_p' ) ? wp_mkdir_p( $folder ) : @mkdir( $folder, 0755, true ) ) ) { // phpcs:ignore
			return self::err( 'memory_write_failed', 'Không ghi được trí nhớ.', 500 );
		}
		$lock = @fopen( $abs . '.lock', 'c' ); // phpcs:ignore
		if ( $lock ) {
			flock( $lock, LOCK_EX );
		}
		try {
			$res = $fn( $abs );
		} finally {
			if ( $lock ) {
				flock( $lock, LOCK_UN );
				fclose( $lock );
			}
		}
		return is_array( $res ) ? array( 'path' => $rel ) + $res : $res;
	}

	/** tmp + rename, so a reader never sees half a file. @return array|WP_Error {bytes} */
	private static function atomic_write( string $abs, string $md ) {
		$tmp = $abs . '.tmp' . getmypid() . mt_rand( 1000, 9999 );
		if ( false === @file_put_contents( $tmp, $md, LOCK_EX ) || ! @rename( $tmp, $abs ) ) { // phpcs:ignore
			@unlink( $tmp ); // phpcs:ignore
			return self::err( 'memory_write_failed', 'Không ghi được trí nhớ.', 500 );
		}
		return array( 'bytes' => strlen( $md ) );
	}

	private static function unavailable() {
		$msg = class_exists( 'BizCity_Brain_Vault_Store' ) ? BizCity_Brain_Vault_Store::unavailable_message( self::NS ) : 'Kho chưa sẵn sàng.';
		return self::err( 'memory_vault_unavailable', $msg, 503 );
	}

	private static function err( string $code, string $message, int $status ) {
		return class_exists( 'WP_Error' ) ? new WP_Error( $code, $message, array( 'status' => $status, 'help_code' => 'twin_vault' ) ) : array( 'error' => $code, 'message' => $message );
	}
}
