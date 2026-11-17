<?php
/**
 * BizCity_CRM_Contact_Space — PHASE-0.153 V4b (Q153-2): a customer's "lưu giúp" in a 1-1 Zalo chat lands in the customer's space
 * (`contact:<identity_uuid>`) in the Kho of the number's owner.
 *
 * Hook `bizcity_crm_message_persisted` (same as the group auto-save), code only — no model (R-PF-2):
 *   - a customer = the sender of a 1-1 chat who is neither the owner UID nor a staff UID of the number (owner / staff use owner-capture);
 *   - matrix (BizCity_Vault_Policy, column customer_1_1, the owner's / the number's / this customer's layer):
 *       "Tệp"      auto  ⇒ every file / image the customer sends is kept;
 *       "Lưu giúp" ask|auto ⇒ kept when the customer asks ("lưu giúp", "lưu lại", "giữ giúp" …): the files of the last 15 minutes when the ask
 *                  comes after them, or a file that comes within 15 minutes after the ask; an ask with no file but with text ⇒ a note in
 *                  the space (`ghi-chu/`);
 *       off ⇒ nothing.
 *   - the space quota (10 tệp / 20 MB) is checked per file in the capture job; the note path is small text.
 *
 * Test seams: $readers {conversation, message, recent(conv_id, since), policy(bridge), owner_user(bridge), identity(bridge, uid, create),
 * capture(bridge, files, contact), note(space, args, owner), policy_data(owner), now}.
 *
 * Biz Central Brain — Johnny Chu (Chu Hoàng Anh).
 *
 * [2026-11-17 10:04 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.153 V4b — new file.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\CRM
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_CRM_Contact_Space {

	const WINDOW_MIN = 15;
	const RECENT_MAX = 50;
	const NOTE_MIN   = 12;
	const NOTE_MAX   = 2000;

	/** "lưu giúp", "lưu lại giúp", "lưu hộ", "lưu giùm", "giữ giúp", "cất giúp", "lưu lại cho em" … (not "nhớ giúp": that is memory.save) */
	const ASK = '/(?<!\p{L})(?:(?:lưu|giữ|cất)\s+(?:lại\s+)?(?:giúp|giùm|dùm|hộ)|lưu\s+lại(?:\s+cho\s+(?:anh|chị|em|tôi|mình))?|lưu\s+(?:file|tệp|ảnh|hình|tài\s+liệu)\s+(?:này\s+)?(?:giúp|giùm|dùm|hộ|nhé|nha|với))(?!\p{L})/iu';

	public static $readers = array();

	private static $hooked = false;

	public static function init(): void {
		if ( self::$hooked || ! function_exists( 'add_action' ) ) {
			return;
		}
		self::$hooked = true;
		add_action( 'bizcity_crm_message_persisted', array( __CLASS__, 'on_message_persisted' ), 21 );
	}

	/** Pure: does this text ask the assistant to keep something? */
	public static function asked_to_save( string $text ): bool {
		return 1 === preg_match( self::ASK, ( function_exists( 'normalizer_normalize' ) ? (string) normalizer_normalize( $text ) : $text ) );
	}

	/** Pure: the text of a note once the ask words are taken out ('' when nothing worth keeping is left). */
	public static function note_text( string $text ): string {
		$t = trim( (string) preg_replace( self::ASK, ' ', $text ) );
		$t = trim( (string) preg_replace( '/\s+/u', ' ', $t ), " \t\n\r\0\x0B:,.-–—!?" );
		$t = trim( (string) preg_replace( '/^(?:em|anh|chị|bạn|shop|ơi|nhé|nha|với|cho|giúp|này|cái)(?:\s+(?:em|anh|chị|bạn|shop|ơi|nhé|nha|với|cho|giúp|này|cái))*\s*/iu', '', $t ) );
		return mb_strlen( $t ) >= self::NOTE_MIN ? mb_substr( $t, 0, self::NOTE_MAX ) : '';
	}

	public static function on_message_persisted( $p ): void {
		if ( ! is_array( $p ) || 'zalo_personal' !== (string) ( $p['adapter_code'] ?? '' ) ) {
			return;
		}
		$conv_id = (int) ( $p['conversation_id'] ?? 0 );
		$msg_id  = (int) ( $p['message_id'] ?? 0 );
		$row     = $conv_id > 0 && $msg_id > 0 ? self::read( 'conversation', array( $conv_id ), null ) : null;
		if ( ! is_array( $row ) || 0 === strpos( (string) ( $row['source_id'] ?? '' ), 'group:' ) ) {
			return; // groups: BizCity_CRM_Group_Secretary
		}
		$msg = self::read( 'message', array( $conv_id, $msg_id ), null );
		if ( ! is_array( $msg ) || 'incoming' !== (string) ( $msg['message_type'] ?? '' ) ) {
			return;
		}
		$bridge = '' !== (string) ( $row['account_id'] ?? '' ) ? (string) $row['account_id'] : (string) ( $row['inbox_ref_id'] ?? '' );
		$uid    = self::sender_uid( $msg, (string) ( $row['source_id'] ?? '' ) );
		if ( '' === $bridge || '' === $uid || ! self::is_customer( $bridge, $uid ) ) {
			return;
		}
		$owner = (int) self::read( 'owner_user', array( $bridge ), 0 );
		if ( $owner <= 0 ) {
			return;
		}
		$text      = (string) ( $msg['content'] ?? '' );
		$has_files = ! empty( $msg['attachments'] );
		$ask_now   = '' !== trim( $text ) && self::asked_to_save( $text );
		if ( ! $has_files && ! $ask_now ) {
			return; // most messages stop here, before any identity / policy read
		}
		if ( ! class_exists( 'BizCity_Vault_Spaces' ) ) {
			return;
		}
		$since = gmdate( 'Y-m-d H:i:s', self::now() - self::WINDOW_MIN * 60 );
		$asked = $ask_now || ( $has_files && self::asked_before( $conv_id, $since, $uid, $msg_id ) );
		// The customer is looked up without binding a new identity; one is bound only for a save that will happen (an ask), never for
		// every customer who sends a picture. Without an identity, only the owner / number layers of the matrix apply.
		$uuid = strtolower( (string) self::read( 'identity', array( $bridge, $uid, false ), '' ) );
		if ( '' === $uuid && $asked ) {
			$uuid = strtolower( (string) self::read( 'identity', array( $bridge, $uid, true ), '' ) );
		}
		$space    = '' !== BizCity_Vault_Spaces::id( 'contact:' . $uuid ) ? 'contact:' . $uuid : '';
		$files    = self::mode( $owner, $bridge, 'files', $space );
		$remember = self::mode( $owner, $bridge, 'remember', $space );
		$on_ask   = 'ask' === $remember || 'auto' === $remember;
		$label    = (string) ( $row['contact_name'] ?? '' );
		if ( 'auto' === $files && '' === $space ) { // "Tệp: auto" for customers: this file is kept, so the customer gets an identity now
			$uuid  = strtolower( (string) self::read( 'identity', array( $bridge, $uid, true ), '' ) );
			$space = '' !== BizCity_Vault_Spaces::id( 'contact:' . $uuid ) ? 'contact:' . $uuid : '';
		}
		if ( '' === $space ) {
			return;
		}

		if ( $has_files ) {
			if ( 'auto' === $files || ( $on_ask && $asked ) ) {
				self::capture( $bridge, self::files_of( array( $msg ), $conv_id ), $uuid, $label );
			}
			return;
		}
		// a text ask with no file in it
		if ( ! $on_ask ) {
			return;
		}
		$recent = array_filter( (array) self::read( 'recent', array( $conv_id, $since ), array() ), static function ( $r ) use ( $uid, $msg_id ) {
			return is_array( $r ) && (int) ( $r['id'] ?? 0 ) < $msg_id && 'incoming' === (string) ( $r['message_type'] ?? '' ) && ! empty( $r['attachments'] ) && self::sender_uid( $r, '' ) === $uid;
		} );
		if ( $recent && 'auto' !== $files ) { // files already kept by "Tệp: auto" are not sent twice
			self::capture( $bridge, self::files_of( array_values( $recent ), $conv_id ), $uuid, $label );
			return;
		}
		$note = self::note_text( $text );
		if ( '' !== $note ) {
			$args = array( 'title' => mb_substr( $note, 0, 60 ), 'label' => $label, 'markdown' => $note . "\n" );
			$res  = isset( self::$readers['note'] ) ? call_user_func( self::$readers['note'], $space, $args, $owner ) : BizCity_Vault_Spaces::write_note( $space, 'ghi-chu', $args, $owner );
			if ( is_wp_error( $res ) && function_exists( 'error_log' ) ) {
				error_log( '[BIZCITY_CONTACT_SPACE] ' . wp_json_encode( array( 'event' => 'note_failed', 'code' => $res->get_error_code() ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}
	}

	/** Matrix mode of one row for a customer of this number; no matrix on the site ⇒ the defaults of Q153-2 (files off, "lưu giúp" ask). */
	public static function mode( int $owner, string $bridge, string $row, string $space ): string {
		if ( ! class_exists( 'BizCity_Vault_Policy' ) ) {
			return 'files' === $row ? 'off' : 'ask';
		}
		$data    = isset( self::$readers['policy_data'] ) ? (array) call_user_func( self::$readers['policy_data'], $owner ) : BizCity_Vault_Policy::data( $owner );
		$account = 0 === strpos( $bridge, 'rzh:' ) ? substr( $bridge, 4 ) : $bridge;
		return BizCity_Vault_Policy::mode( $data, $row, 'customer_1_1', $account, '' !== $space && class_exists( 'BizCity_Vault_Spaces' ) ? BizCity_Vault_Spaces::id( $space ) : '' );
	}

	/** Owner UID / staff UID of the number ⇒ not a customer. No owner UID configured ⇒ nobody is treated as a customer (fail closed). */
	public static function is_customer( string $bridge, string $uid ): bool {
		$policy = (array) self::read( 'policy', array( $bridge ), array() );
		$owner  = preg_replace( '/[^0-9]/', '', (string) ( $policy['owner_uid'] ?? '' ) );
		if ( '' === $owner || hash_equals( $owner, $uid ) ) {
			return false;
		}
		$staff = class_exists( 'BizCity_Zalo_Agent_Principals' ) ? BizCity_Zalo_Agent_Principals::from_policy( $policy ) : (array) ( $policy['staff'] ?? array() );
		foreach ( $staff as $s ) {
			if ( is_array( $s ) && hash_equals( preg_replace( '/[^0-9]/', '', (string) ( $s['zalo_uid'] ?? '' ) ), $uid ) ) {
				return false;
			}
		}
		return true;
	}

	private static function asked_before( int $conv_id, string $since, string $uid, int $msg_id ): bool {
		foreach ( (array) self::read( 'recent', array( $conv_id, $since ), array() ) as $r ) {
			if ( is_array( $r ) && (int) ( $r['id'] ?? 0 ) < $msg_id && 'incoming' === (string) ( $r['message_type'] ?? '' )
				&& self::sender_uid( $r, '' ) === $uid && self::asked_to_save( (string) ( $r['content'] ?? '' ) ) ) {
				return true;
			}
		}
		return false;
	}

	private static function files_of( array $rows, int $conv_id ): array {
		return class_exists( 'BizCity_CRM_Group_Secretary' ) ? BizCity_CRM_Group_Secretary::files_from_rows( $rows, $conv_id, '', array(), true ) : array();
	}

	private static function capture( string $bridge, array $files, string $uuid, string $label ): void {
		if ( ! $files ) {
			return;
		}
		$contact = array( 'uuid' => $uuid, 'label' => $label );
		if ( isset( self::$readers['capture'] ) ) {
			call_user_func( self::$readers['capture'], $bridge, $files, $contact );
			return;
		}
		if ( class_exists( 'BizCity_Zalo_Personal_Knowledge_Capture' ) && method_exists( 'BizCity_Zalo_Personal_Knowledge_Capture', 'from_contact_files' ) ) {
			BizCity_Zalo_Personal_Knowledge_Capture::from_contact_files( $bridge, $files, $contact );
		}
	}

	private static function sender_uid( array $msg, string $fallback ): string {
		$meta = json_decode( (string) ( $msg['ai_metadata_json'] ?? '' ), true );
		$uid  = is_array( $meta ) ? (string) ( $meta['sender_user_id'] ?? '' ) : '';
		$uid  = '' !== $uid ? $uid : $fallback;
		return preg_match( '/^[0-9]{3,30}$/', $uid ) ? $uid : '';
	}

	private static function now(): int {
		if ( isset( self::$readers['now'] ) ) {
			return (int) call_user_func( self::$readers['now'] );
		}
		return function_exists( 'current_time' ) ? (int) current_time( 'timestamp' ) : time(); // CRM created_at is site time
	}

	/** Seam first, else the real reader `db_<name>`. */
	private static function read( string $name, array $args, $default ) {
		if ( isset( self::$readers[ $name ] ) ) {
			return call_user_func_array( self::$readers[ $name ], $args );
		}
		$m = 'db_' . $name;
		return method_exists( __CLASS__, $m ) ? call_user_func_array( array( __CLASS__, $m ), $args ) : $default;
	}

	/* ── real readers ─────────────────────────────────────────────────── */

	private static function db_conversation( int $conv_id ): ?array {
		if ( ! class_exists( 'BizCity_CRM_DB_Installer_V2' ) ) {
			return null;
		}
		global $wpdb;
		$c  = BizCity_CRM_DB_Installer_V2::tbl_conversations();
		$ct = BizCity_CRM_DB_Installer_V2::tbl_contacts();
		$ci = BizCity_CRM_DB_Installer_V2::tbl_contact_inboxes();
		$ib = BizCity_CRM_DB_Installer_V2::tbl_inboxes();
		$r  = $wpdb->get_row( $wpdb->prepare(
			"SELECT c.id, c.account_id, ib.channel_ref_id AS inbox_ref_id, ci.source_id, ct.name AS contact_name FROM {$c} c LEFT JOIN {$ci} ci ON ci.id = c.contact_inbox_id LEFT JOIN {$ct} ct ON ct.id = ci.contact_id LEFT JOIN {$ib} ib ON ib.id = c.inbox_id WHERE c.id = %d",
			$conv_id
		), ARRAY_A );
		return is_array( $r ) ? $r : null;
	}

	private static function db_message( int $conv_id, int $msg_id ): ?array {
		if ( ! class_exists( 'BizCity_CRM_Repository' ) ) {
			return null;
		}
		$rows = BizCity_CRM_Repository::get_messages_by_ids( $conv_id, array( $msg_id ) );
		return is_array( $rows ) && isset( $rows[0] ) && is_array( $rows[0] ) ? $rows[0] : null;
	}

	private static function db_recent( int $conv_id, string $since ): array {
		if ( ! class_exists( 'BizCity_CRM_DB_Installer_V2' ) || ! class_exists( 'BizCity_CRM_Repository' ) ) {
			return array();
		}
		global $wpdb;
		$tbl  = BizCity_CRM_DB_Installer_V2::tbl_messages();
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$tbl} WHERE conversation_id = %d AND created_at >= %s AND message_type = 'incoming' ORDER BY id ASC LIMIT %d",
			$conv_id, $since, self::RECENT_MAX
		), ARRAY_A );
		return BizCity_CRM_Repository::hydrate_messages( is_array( $rows ) ? $rows : array() );
	}

	private static function db_policy( string $bridge ): array {
		return class_exists( 'BizCity_Zalo_Agent_Principals' ) ? BizCity_Zalo_Agent_Principals::policy( $bridge ) : array();
	}

	private static function db_owner_user( string $bridge ): int {
		return class_exists( 'BizCity_Zalo_Agent_Principals' ) ? BizCity_Zalo_Agent_Principals::owner_user_id( $bridge ) : 0;
	}

	/** Identity Hub uuid of this customer of this number (bound on first ask — same path as memory.save of a customer). */
	private static function db_identity( string $bridge, string $uid, bool $create ): string {
		return class_exists( 'BizCity_Bot_Memory' ) && method_exists( 'BizCity_Bot_Memory', 'identity_for_claim' )
			? (string) BizCity_Bot_Memory::identity_for_claim( array( 'account_id' => $bridge, 'sender_uid' => $uid, 'chat_kind' => 'user' ), $create )
			: '';
	}
}
