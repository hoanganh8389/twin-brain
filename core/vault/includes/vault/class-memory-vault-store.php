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
		if ( $guru_id <= 0 ) {
			return '';
		}
		$base = 'g' . $guru_id . '/' . $s . '/';
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

	/** Absolute namespace dir (created, with the root deny files), '' when the vault is not usable. */
	public static function dir(): string {
		return class_exists( 'BizCity_Brain_Vault_Store' ) ? BizCity_Brain_Vault_Store::ensure( self::NS ) : '';
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
		$dir   = class_exists( 'BizCity_Brain_Vault_Store' ) ? BizCity_Brain_Vault_Store::namespace_dir( self::NS ) : '';
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

	/** Delete one memory file (forget). @return bool */
	public static function forget( int $guru_id, string $subject, string $kind, string $month = '' ): bool {
		$dir = class_exists( 'BizCity_Brain_Vault_Store' ) ? BizCity_Brain_Vault_Store::namespace_dir( self::NS ) : '';
		$rel = self::path_for( $guru_id, $subject, $kind, $month );
		if ( '' === $dir || '' === $rel || ! BizCity_Brain_Vault_Store::inside( $dir, $dir . $rel ) || ! is_file( $dir . $rel ) ) {
			return false;
		}
		return @unlink( $dir . $rel ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	// ── internals ───────────────────────────────────────────────────────────

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
