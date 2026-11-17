<?php
/**
 * BizCity_Memory_Vault_Mirror — every Guru memory write / delete also lands in the Vault as .md (memory-vault@1), one Journal row per file.
 *
 * [2026-11-15 12:41 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.151 M2 (owner 15/11: "nối trí nhớ vault và journal"; doc 60 §2.7).
 *
 * Seam: the two actions every memory owner already fires after its JSONL write —
 *   bizcity_memory_mirror_write ( $class, $payload, $op )   user · rolling · episodic · session (core/guru/memory, notebook memory/)
 *   bizcity_memory_mirror_delete( $class, $id, $ctx )       ctx.record_id (+ identity_uuid when the caller knows it)
 * so none of the ~14 callers of BizCity_User_Memory / Rolling / Episodic / Session changes.
 *
 * M3 (owner 15/11 07:38 PM: "ko cần xuất trí nhớ cũ sang .md, đổi logic là ok"): READS now come from these files
 * (BizCity_Context_Bank_Memory_Adapter::query → BizCity_Memory_Vault_Store::records). Memory older than the M2 deploy is not exported —
 * the owner's call; it stays in JSONL, unread. JSONL writes continue until M4. `note` (TwinChat pins) is not memory of a person and is not mirrored.
 *
 * Fail-soft: a vault error is logged and never reaches the memory writer (the JSONL write already succeeded).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Vault
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Memory_Vault_Mirror', false ) ) {
	return;
}

final class BizCity_Memory_Vault_Mirror {

	/** memory class (Context Bank adapter key) ⇒ memory-vault kind */
	const KIND_OF = array( 'user' => 'profile', 'rolling' => 'rolling', 'episodic' => 'episodic', 'session' => 'session' );

	/** Labels of a file's Journal row (Vault 05/tri-nho). */
	const LABEL = array( 'profile' => 'hồ sơ', 'rolling' => 'tóm tắt hội thoại', 'episodic' => 'nhật ký', 'session' => 'phiên' );

	/** Test seam: journal(array $entry): mixed — replaces BizCity_Journal_Index::add. */
	public static $journal = null;

	private static $registered = false;

	public static function register(): void {
		if ( self::$registered || ! self::enabled() || ! function_exists( 'add_action' ) ) {
			return;
		}
		self::$registered = true;
		add_action( 'bizcity_memory_mirror_write', array( __CLASS__, 'on_write' ), 30, 3 );
		add_action( 'bizcity_memory_mirror_delete', array( __CLASS__, 'on_delete' ), 30, 3 );
	}

	/** On unless BIZCITY_MEMORY_VAULT_MIRROR is false or the filter says no. */
	public static function enabled(): bool {
		$on = ! defined( 'BIZCITY_MEMORY_VAULT_MIRROR' ) || (bool) BIZCITY_MEMORY_VAULT_MIRROR;
		return function_exists( 'apply_filters' ) ? (bool) apply_filters( 'bizcity_memory_vault_mirror_enabled', $on ) : $on;
	}

	// ── pure ────────────────────────────────────────────────────────────────

	/**
	 * Map one mirror payload to {kind, subject, entry} or null (not mirrored / no stable owner). Pure.
	 * entry = {id, at, title, text, rec}; rec = the payload without the filestore receipt, plus record_id.
	 */
	public static function entry_from( string $class, array $payload ): ?array {
		$kind = self::KIND_OF[ $class ] ?? '';
		if ( '' === $kind ) {
			return null;
		}
		$receipt   = is_array( $payload['filestore_receipt'] ?? null ) ? $payload['filestore_receipt'] : ( is_array( $payload['receipt'] ?? null ) ? $payload['receipt'] : array() );
		$record_id = (string) ( $receipt['record_id'] ?? $payload['record_id'] ?? '' );
		$rec       = $payload;
		unset( $rec['filestore_receipt'], $rec['receipt'] );
		if ( '' !== $record_id ) {
			$rec['record_id'] = $record_id;
		}
		$subject = 'session' === $kind ? (string) ( $payload['session_id'] ?? '' ) : strtolower( trim( (string) ( $payload['identity_uuid'] ?? '' ) ) );
		if ( '' === BizCity_Memory_Vault_Store::key( $subject ) ) {
			return null; // no durable owner (R-CH-IDMEM writes always carry one; an anonymous row is not memory of a person)
		}
		$at = self::iso( (string) ( $payload['updated_at'] ?? $payload['last_seen'] ?? $payload['created_at'] ?? '' ) );
		switch ( $kind ) {
			case 'profile':
				$key   = (string) ( $payload['memory_key'] ?? '' );
				$id    = '' !== $record_id ? $record_id : ( '' !== $key ? 'k:' . md5( $key ) : '' );
				$title = self::type_label( (string) ( $payload['memory_type'] ?? '' ) );
				$text  = (string) ( $payload['memory_text'] ?? '' );
				break;
			case 'rolling': // one entry per conversation. The owner fires up to three payloads a turn; the last one (no receipt) is the old
				// unified-table mirror of the same row — skipped, so the entry keeps the record_id an admin delete names.
				if ( '' === $record_id ) {
					return null;
				}
				$conv  = (string) ( $payload['conversation_id'] ?? '' );
				$id    = '' !== $conv ? 'conv:' . $conv : $record_id;
				$title = trim( (string) ( $payload['goal_label'] ?? '' ) ) ?: ( trim( (string) ( $payload['goal'] ?? '' ) ) ?: 'Hội thoại' );
				$text  = trim( trim( (string) ( $payload['window_summary'] ?? '' ) ) . "\n" . trim( (string) ( $payload['completion_summary'] ?? '' ) ) );
				break;
			case 'episodic':
				$key   = (string) ( $payload['event_key'] ?? '' );
				$id    = '' !== $record_id ? $record_id : ( '' !== $key ? 'ev:' . md5( $key ) : '' );
				$title = (string) ( $payload['event_type'] ?? 'sự kiện' );
				$text  = (string) ( $payload['event_text'] ?? '' );
				break;
			default: // session
				$key   = (string) ( $payload['memory_key'] ?? '' );
				$id    = '' !== $key ? 'k:' . md5( $key ) : $record_id;
				$title = self::type_label( (string) ( $payload['memory_type'] ?? '' ) );
				$text  = (string) ( $payload['memory_text'] ?? '' );
		}
		if ( '' === $id || ( '' === trim( $text ) && 'rolling' !== $kind ) ) {
			return null;
		}
		return array( 'kind' => $kind, 'subject' => $subject, 'entry' => array( 'id' => $id, 'at' => $at, 'title' => $title, 'text' => $text, 'rec' => $rec ) );
	}

	/** "Y-m-d H:i:s" (site) / ISO ⇒ ISO-8601 UTC-ish stamp; '' ⇒ now. Pure. */
	public static function iso( string $t ): string {
		$ts = '' !== trim( $t ) ? strtotime( $t ) : false;
		return gmdate( 'Y-m-d\TH:i:s\Z', false !== $ts ? $ts : time() );
	}

	private static function type_label( string $type ): string {
		$map = array( 'identity' => 'Thông tin', 'preference' => 'Sở thích', 'goal' => 'Mục tiêu', 'pain' => 'Khó khăn', 'constraint' => 'Lưu ý', 'habit' => 'Thói quen', 'relationship' => 'Quan hệ', 'fact' => 'Sự thật', 'request' => 'Yêu cầu ghi nhớ' );
		return $map[ $type ] ?? ( '' !== $type ? $type : 'Ghi nhớ' );
	}

	// ── site ────────────────────────────────────────────────────────────────

	/**
	 * [2026-11-15 09:59 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.153 V1 — the Zalo number a memory was learned on (`account_id` on the payload, else in
	 * its `metadata`, as memory.save stores it); '' when the writer did not say. Pure.
	 */
	public static function account_of( array $payload ): string {
		$a = trim( (string) ( $payload['account_id'] ?? '' ) );
		if ( '' === $a ) {
			$meta = $payload['metadata'] ?? null;
			$meta = is_string( $meta ) ? json_decode( $meta, true ) : $meta;
			$a    = is_array( $meta ) ? trim( (string) ( $meta['account_id'] ?? '' ) ) : '';
		}
		return $a;
	}

	/** @param mixed $payload */
	public static function on_write( $class, $payload = array(), $op = '' ): void {
		// PHASE-0.153 V1 — the memory goes to the Kho of the user id that owns the number it was learned on (an unknown number keeps the acting owner)
		$account = is_array( $payload ) ? self::account_of( $payload ) : '';
		if ( '' !== $account && class_exists( 'BizCity_Vault_Owner' ) ) {
			BizCity_Vault_Owner::with_account( $account, static function () use ( $class, $payload, $op ) {
				self::write_now( $class, $payload, $op );
			} );
			return;
		}
		self::write_now( $class, $payload, $op );
	}

	/** @param mixed $payload */
	private static function write_now( $class, $payload, $op ): void {
		try {
			// [2026-11-15 07:38 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.151 M3 — reads now come from these files, so the entry keeps the record
			// AS STORED (merged times_seen / score / created_at / legacy_id), followed through the lock-captured receipt; the payload is only a fallback.
			$payload = is_array( $payload ) ? self::stored_record( (string) $class, $payload ) : $payload;
			$m       = is_array( $payload ) ? self::entry_from( (string) $class, $payload ) : null;
			if ( null === $m || ! class_exists( 'BizCity_Memory_Vault_Store' ) ) {
				return;
			}
			$res = BizCity_Memory_Vault_Store::upsert_record( 0, $m['subject'], $m['kind'], $m['entry'], array( 'source_contract' => self::contract( (string) $class ) ) );
			if ( is_array( $res ) && ! empty( $res['path'] ) && ! empty( $res['created'] ) ) {
				self::file_row( (string) $res['path'], $m['kind'], $m['subject'] ); // the row is opened with the file; later writes change the file only
			} elseif ( is_object( $res ) && method_exists( $res, 'get_error_code' ) && 'memory_vault_unavailable' !== $res->get_error_code() ) {
				error_log( '[BizCity_Memory_Vault_Mirror] write ' . $class . ': ' . $res->get_error_code() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			}
		} catch ( \Throwable $e ) {
			error_log( '[BizCity_Memory_Vault_Mirror] write failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
	}

	/** @param mixed $ctx */
	public static function on_delete( $class, $id = 0, $ctx = array() ): void {
		try {
			$ctx = is_array( $ctx ) ? $ctx : array();
			$rid = trim( (string) ( $ctx['record_id'] ?? '' ) );
			if ( '' === $rid || ! isset( self::KIND_OF[ (string) $class ] ) || ! class_exists( 'BizCity_Memory_Vault_Store' ) ) {
				return;
			}
			$subject = strtolower( trim( (string) ( $ctx['identity_uuid'] ?? '' ) ) );
			$gone    = BizCity_Memory_Vault_Store::delete_record( $rid, $subject );
			if ( 0 === $gone && '' !== $subject ) {
				$gone = BizCity_Memory_Vault_Store::delete_record( $rid ); // the caller's identity was not the file's: search every file, never keep a deleted memory
			}
			// [2026-11-15 09:59 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.153 V1 — not in the acting owner's Kho (an admin deleting another owner's customer
			// memory): look in every owner's Kho, so a deleted memory never comes back anywhere
			if ( 0 === $gone && class_exists( 'BizCity_Vault_Owner' ) ) {
				$acting = BizCity_Memory_Vault_Store::owner();
				foreach ( BizCity_Memory_Vault_Store::owners_with_memory() as $owner ) {
					if ( $owner === $acting ) {
						continue;
					}
					$gone += (int) BizCity_Vault_Owner::with( $owner, static function () use ( $rid ) {
						return BizCity_Memory_Vault_Store::delete_record( $rid );
					} );
					if ( $gone > 0 ) {
						break;
					}
				}
			}
		} catch ( \Throwable $e ) {
			error_log( '[BizCity_Memory_Vault_Mirror] delete failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
	}

	/** The payload merged over the JSONL record its receipt points at (the receipt is kept); the payload unchanged when it cannot be followed. */
	private static function stored_record( string $class, array $payload ): array {
		$receipt  = is_array( $payload['filestore_receipt'] ?? null ) ? $payload['filestore_receipt'] : ( is_array( $payload['receipt'] ?? null ) ? $payload['receipt'] : array() );
		$contract = self::contract( $class );
		if ( ! $receipt || '' === $contract || ! class_exists( 'BizCity_Business_JSONL_File_Store' ) ) {
			return $payload;
		}
		$followed = BizCity_Business_JSONL_File_Store::read_receipt( $contract, $receipt );
		if ( ! is_array( $followed ) || empty( $followed['ok'] ) || ! is_array( $followed['record'] ?? null ) ) {
			return $payload;
		}
		return array_merge( $payload, $followed['record'] );
	}

	private static function contract( string $class ): string {
		return class_exists( 'BizCity_Context_Bank_Memory_Adapter' ) ? (string) BizCity_Context_Bank_Memory_Adapter::contract_for_class( $class ) : '';
	}

	/**
	 * Exactly one Journal row `common.note` per memory file (Q151-11), opened when the file is created, filed in 05/tri-nho (owner only).
	 * Idempotent on the file path: a file deleted and created again gets its old row back.
	 */
	private static function file_row( string $rel, string $kind, string $subject ): void {
		// [2026-11-15 09:59 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.153 V1 — the row belongs to the Kho owner the file was written for, not to a site owner
		$owner = BizCity_Memory_Vault_Store::owner();
		if ( $owner <= 0 && class_exists( 'BizCity_Context_Producers' ) ) {
			$owner = BizCity_Context_Producers::owner_user_id();
		}
		if ( $owner <= 0 && null === self::$journal ) {
			return;
		}
		$blog  = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$title = 'Trí nhớ · ' . ( self::LABEL[ $kind ] ?? $kind ) . ' · ' . substr( $subject, 0, 8 ); // never a name / phone in a title (R-CID-11)
		$entry = array(
			'owner_user_id'   => $owner,
			'kind'            => 'common.note',
			'title'           => $title,
			'summary'         => 'Trí nhớ Guru (' . ( self::LABEL[ $kind ] ?? $kind ) . ') — tệp ' . $rel,
			'doc_date'        => gmdate( 'Y-m-d' ),
			'source_type'     => 'vault',
			'source_ref'      => substr( 'mem:' . $rel, 0, 120 ),
			'idempotency_key' => 'memory:' . $blog . ':' . substr( md5( $rel ), 0, 24 ),
			'origin'          => array( 'surface' => 'vault' ),
			'card'            => array( 'vault_path' => 'mem:' . $rel ),
			'meta'            => array( 'folder' => BizCity_Memory_Vault_Store::VAULT_FOLDER, 'title' => $title ),
		);
		if ( null !== self::$journal ) {
			call_user_func( self::$journal, $entry );
			return;
		}
		if ( class_exists( 'BizCity_Journal_Index' ) ) {
			$row = BizCity_Journal_Index::add( $entry );
			if ( is_object( $row ) && method_exists( $row, 'get_error_code' ) ) {
				error_log( '[BizCity_Memory_Vault_Mirror] journal row: ' . $row->get_error_code() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			}
		}
	}
}
