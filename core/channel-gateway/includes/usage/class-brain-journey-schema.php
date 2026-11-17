<?php
/**
 * Brain journey ledger — per-blog, append-only achievement log + its rebuildable rollup (PHASE-0.108 S108-L1,
 * docs PHASE-0.108-ZALO-BRAIN-ACHIEVEMENTS-ROI/00-MASTER-PLAN.md §3.4, D108-4).
 *
 * `{prefix}bizcity_brain_journey` = one row per achieved event (`born`, `first_reply`, `trained`, `milestone`,
 * `level_up`, `plan`, `payback`, `custom`). Never UPDATEd or DELETEd once inserted (R-BRAIN-JOURNEY luật 2):
 * a reversal is a new row, not an edit. `dedupe_key` UNIQUE makes the evaluator's INSERT IGNORE safe to run
 * any number of times. `prev_hash`/`row_hash` form a hash chain per subject (see class-brain-journey-evaluator.php)
 * so the ledger can be verified — the point of this table is to be *trusted* ("được công nhận", §3.4).
 *
 * [2026-11-17 11:20 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.154 W1 — the chain head is the subject's last journey row (writers serialised by
 * a GET_LOCK per blog + subject); `bizcity_brain_journey_heads` is quarantined. The metrics / period CACHES (D108-4: always rebuilt whole,
 * never incremented) are rows of the one ledger `bizcity_usage_ledger` (kind 'metric' / 'period'), read through derived tables with the old
 * columns; `bizcity_brain_metrics` and `bizcity_brain_metrics_period` are quarantined. Losing a cache row loses nothing but a warm-up.
 *
 * Per blog (`$wpdb->prefix`, never base_prefix), same as BizCity_Usage_Schema. Version option `bizcity_brain_journey_schema`.
 *
 * // [2026-10-13 Johnny Chu - Chu Hoàng Anh] PHASE-0.108 S108-L1 — new module, sibling of core/channel-gateway/includes/usage/.
 *
 * @package BizCity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

// Loaded with require_once only (no class_exists self-guard: an early-bound declaration makes that guard always true).
final class BizCity_Brain_Journey_Schema {

	// 1.1.0 (10-SPEC §9 G-2/G-3/G-4): + customer_replies_month, distinct_customers_month, ai_enabled.
	// 1.2.0 (S108-L11a): + bizcity_brain_metrics_period (7d / 30d / month windows + the window before, rebuildable cache).
	// 1.3.0 (PHASE-0.154 W1, D154-1/2): bizcity_brain_journey_heads, bizcity_brain_metrics, bizcity_brain_metrics_period are no longer
	// created — heads come from the last journey row, metrics / period are rows of the ONE ledger (bizcity_usage_ledger, kind 'metric' /
	// 'period'); the old tables are quarantined (never read, never written; DROP only with the owner's approval).
	// 1.4.0 (PHASE-0.154 W3): bizcity_brain_journey is ledger rows kind 'journey' — no brain table is created any more.
	const SCHEMA_VERSION = '1.4.0';
	const OPTION_VERSION = 'bizcity_brain_journey_schema';

	// [2026-11-17 11:20 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.154 S154-1.3/1.4 — account_seq bands of the cache rows in bizcity_usage_ledger
	// (far above any real cell sequence; 'day' rows use 4.0e18 + YYYYMMDD). One metric row per account; one period row per (account, range).
	const METRIC_SEQ  = 4300000000000000000;
	const PERIOD_SEQ  = 4400000000000000000;
	const PERIOD_CODE = array( '7d' => 1, '30d' => 2, 'month' => 3 );

	/** Columns of the old bizcity_brain_metrics kept in the payload: name => i (integer) | f (decimal) | p (plain / datetime, NULL when absent). */
	const METRIC_COLS = array(
		'customer_replies' => 'i', 'distinct_customers' => 'i', 'distinct_groups' => 'i', 'tasks_done' => 'i', 'trained_items' => 'i',
		'streak_days' => 'i', 'billed_tokens_month' => 'i', 'chi_usd_month' => 'f', 'thu_usd_month' => 'f', 'customer_replies_month' => 'i',
		'distinct_customers_month' => 'i', 'ai_enabled' => 'i', 'last_customer_reply_at' => 'p', 'born_at' => 'p', 'active' => 'i',
	);

	/** Columns of the old bizcity_brain_metrics_period kept in the payload. */
	const PERIOD_COLS = array(
		'range_key' => 'p', 'period_from' => 'p', 'period_to' => 'p', 'replies' => 'i', 'customers' => 'i', 'tasks' => 'i', 'billed_tokens' => 'i',
		'chi_usd' => 'f', 'thu_usd' => 'f', 'replies_prev' => 'i', 'customers_prev' => 'i', 'chi_usd_prev' => 'f', 'thu_usd_prev' => 'f',
	);

	/*
	 * [2026-11-17 02:20 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.154 W3 (D154-1/2) — the journey (append-only, hash-chained) is rows of the ONE ledger:
	 * kind 'journey', account_id = subject_id, account_seq = band of the subject kind + seq (brain 4.5e18 · owner 4.6e18 · site 4.7e18), so
	 * (account_id, account_seq) is the old UNIQUE(subject_kind, subject_id, seq). Every column of the old row is in the payload, unchanged:
	 * "Kiểm tra sổ" recomputes the same hashes. bizcity_brain_journey is quarantined: not created, read by the one-time copy and — until that copy
	 * is done — as the bounded fallback of the view, so the chain head is always visible and a new event never chains onto the wrong row.
	 */
	const JOURNEY_SEQ  = array( 'brain' => 4500000000000000000, 'owner' => 4600000000000000000, 'site' => 4700000000000000000 );
	const JOURNEY_COLS = array(
		'seq' => 'i', 'occurred_at' => 's:', 'subject_kind' => 's:brain', 'subject_id' => 's:', 'owner_user_id' => 'i', 'event' => 's:', 'code' => 's:',
		'tier' => 's:', 'value' => 'i', 'catalog_version' => 's:', 'source' => 's:', 'source_ref' => 's:', 'dedupe_key' => 's:', 'prev_hash' => 's:',
		'row_hash' => 's:', 'created_at' => 's:',
	);
	const JOURNEY_COPY_DONE   = 'bizcity_brain_journey_copied_v1';
	const JOURNEY_COPY_OFFSET = 'bizcity_brain_journey_copy_offset';
	const JOURNEY_COPY_HOOK   = 'bizcity_brain_journey_copy';

	public static function journey_table(): string {
		$case = "(CASE o.subject_kind WHEN 'brain' THEN " . self::JOURNEY_SEQ['brain'] . " WHEN 'owner' THEN " . self::JOURNEY_SEQ['owner'] . " WHEN 'site' THEN " . self::JOURNEY_SEQ['site'] . ' ELSE 0 END)';
		return BizCity_Usage_Schema::kind_view_with_legacy( 'journey', array( 'id' ), self::JOURNEY_COLS, 'bizcity_brain_journey_v', self::journey_physical_table(), self::JOURNEY_COPY_DONE,
			"l.kind = 'journey' AND l.account_id = o.subject_id AND l.account_seq = {$case} + o.seq" );
	}

	/** The quarantined physical table (copy / view fallback only; never written). */
	public static function journey_physical_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_brain_journey';
	}

	/** Ledger seq of one journey row; 0 = unknown subject kind (refused). */
	public static function journey_seq( string $subject_kind, int $seq ): int {
		return isset( self::JOURNEY_SEQ[ $subject_kind ] ) && $seq > 0 ? (int) self::JOURNEY_SEQ[ $subject_kind ] + $seq : 0;
	}

	/**
	 * Append one journey row: true only when THIS call wrote it (the (subject, seq) slot was free) — the old UNIQUE(subject_kind, subject_id, seq).
	 *
	 * @param array<string,mixed> $row the old table's row shape
	 */
	public static function put_journey_row( array $row ): bool {
		$seq = self::journey_seq( (string) ( $row['subject_kind'] ?? '' ), (int) ( $row['seq'] ?? 0 ) );
		$sid = (string) ( $row['subject_id'] ?? '' );
		if ( 0 === $seq || '' === $sid || ! class_exists( 'BizCity_Usage_Schema' ) ) {
			return false;
		}
		return BizCity_Usage_Schema::insert_kind_row_once( 'journey', $sid, $seq, '', (int) ( $row['owner_user_id'] ?? 0 ),
			BizCity_Usage_Schema::kind_payload( $row, self::JOURNEY_COLS ), (string) ( $row['occurred_at'] ?? '' ) );
	}

	/** admin_init: queue the one-time copy of bizcity_brain_journey while it is not done. */
	public static function schedule_journey_copy(): void {
		if ( ( defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI ) || ! function_exists( 'get_option' ) || '1' === (string) get_option( self::JOURNEY_COPY_DONE, '' ) ) {
			return;
		}
		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::JOURNEY_COPY_HOOK ) ) {
			wp_schedule_single_event( time() + 60, self::JOURNEY_COPY_HOOK );
		}
	}

	/**
	 * Cron: copy the old journey rows, 500 a page, INSERT IGNORE (seq, prev_hash, row_hash, occurred_at kept byte for byte). Done only when no old
	 * row is missing AND every copied subject's chain verifies on the copy (N6 / G4); otherwise it stays open and the view keeps the fallback.
	 */
	public static function copy_journey(): void {
		if ( ( defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI ) || ! function_exists( 'get_option' ) || '1' === (string) get_option( self::JOURNEY_COPY_DONE, '' ) ) {
			return;
		}
		global $wpdb;
		$old = self::journey_physical_table();
		if ( ! class_exists( 'BizCity_Table_Metadata' ) || ! BizCity_Table_Metadata::table_exists( $old ) ) {
			update_option( self::JOURNEY_COPY_DONE, '1', false );
			self::copy_note( 'done_no_table', 0, 0 );
			return;
		}
		$offset = max( 0, (int) get_option( self::JOURNEY_COPY_OFFSET, 0 ) );
		for ( $page = 0; $page < 20; $page++ ) {
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$old} ORDER BY id LIMIT %d OFFSET %d", 500, $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( $rows as $r ) {
				if ( 0 === self::journey_seq( (string) ( $r['subject_kind'] ?? '' ), (int) ( $r['seq'] ?? 0 ) ) ) {
					continue; // unknown subject kind: stays in the old table, the missing check keeps the copy open
				}
				self::put_journey_row( $r ); // false = already copied (INSERT IGNORE): fine
			}
			$offset += count( $rows );
			update_option( self::JOURNEY_COPY_OFFSET, $offset, false );
			if ( count( $rows ) < 500 ) {
				$led     = BizCity_Usage_Schema::ledger_physical_table();
				$case    = "(CASE o.subject_kind WHEN 'brain' THEN " . self::JOURNEY_SEQ['brain'] . " WHEN 'owner' THEN " . self::JOURNEY_SEQ['owner'] . " WHEN 'site' THEN " . self::JOURNEY_SEQ['site'] . ' ELSE 0 END)';
				$missing = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$old} o WHERE NOT EXISTS (SELECT 1 FROM {$led} l WHERE l.kind = 'journey' AND l.account_id = o.subject_id AND l.account_seq = {$case} + o.seq)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$broken  = 0;
				if ( 0 === $missing && class_exists( 'BizCity_Brain_Journey_Evaluator' ) ) {
					foreach ( (array) $wpdb->get_results( "SELECT DISTINCT subject_kind, subject_id FROM {$old}", ARRAY_A ) as $s ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$v = BizCity_Brain_Journey_Evaluator::verify_chain_detail( (string) $s['subject_kind'], (string) $s['subject_id'] );
						$broken += $v['ok'] ? 0 : 1;
					}
				}
				if ( 0 === $missing && 0 === $broken ) {
					update_option( self::JOURNEY_COPY_DONE, '1', false );
					self::copy_note( 'done', $offset, 0 );
				} else {
					update_option( self::JOURNEY_COPY_OFFSET, 0, false );
					self::copy_note( $missing ? 'missing_after_pass' : 'chain_broken_after_copy', $offset, $missing ?: $broken );
					wp_schedule_single_event( time() + 3600, self::JOURNEY_COPY_HOOK );
				}
				return;
			}
		}
		wp_schedule_single_event( time() + 60, self::JOURNEY_COPY_HOOK );
	}

	private static function copy_note( string $state, int $offset, int $n ): void {
		if ( class_exists( 'BizCity_Cron_Manager' ) && method_exists( 'BizCity_Cron_Manager', 'instance' ) ) {
			BizCity_Cron_Manager::instance()->note_event( 'brain_journey_copy', array( 'state' => $state, 'offset' => $offset, 'n' => $n ) );
		}
	}

	/** [2026-11-17 11:20 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.154 S154-1.2 — quarantined name (catalog / diagnostics only): the chain head is now the last journey row. */
	public static function heads_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_brain_journey_heads';
	}

	/**
	 * [2026-11-17 11:20 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.154 S154-1.3 — a DERIVED TABLE over the ledger rows kind='metric' with the exact
	 * columns of the old bizcity_brain_metrics, so every reader keeps its `FROM … WHERE …`. No row ⇒ the account simply has no figures yet
	 * (the next evaluator run writes them): never an error.
	 */
	public static function metrics_table(): string {
		$base = array( 'account_id', 'platform', 'bound_user_id AS owner_user_id', 'received_at AS computed_at' );
		return BizCity_Usage_Schema::kind_view( 'metric', $base, self::METRIC_COLS, 'bizcity_brain_metrics_v' );
	}

	/** [2026-11-17 11:20 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.154 S154-1.4 — derived table over kind='period' rows (columns of the old bizcity_brain_metrics_period). */
	public static function period_table(): string {
		$base = array( 'account_id', 'received_at AS computed_at' );
		return BizCity_Usage_Schema::kind_view( 'period', $base, self::PERIOD_COLS, 'bizcity_brain_metrics_period_v' );
	}

	/** Quarantined physical names (catalog / diagnostics only; never read or written). */
	public static function metrics_physical_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_brain_metrics';
	}

	public static function period_physical_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_brain_metrics_period';
	}

	/**
	 * Overwrite one cache row (metric / period) of the ledger. [2026-11-17 12:30 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.154 W2 — the SQL lives in the
	 * ledger owner (BizCity_Usage_Schema::put_kind_row, which also stamps key_id); platform / owner_user_id go to ledger columns.
	 *
	 * @param array<string,mixed>  $row  the old table's row
	 * @param array<string,string> $cols METRIC_COLS | PERIOD_COLS
	 */
	public static function put_cache_row( string $kind, string $account_id, int $seq, array $row, array $cols ): bool {
		if ( ! class_exists( 'BizCity_Usage_Schema' ) ) {
			return false;
		}
		return BizCity_Usage_Schema::put_kind_row( $kind, $account_id, $seq, (string) ( $row['platform'] ?? '' ), (int) ( $row['owner_user_id'] ?? 0 ), BizCity_Usage_Schema::kind_payload( $row, $cols ) );
	}

	public static function period_seq( string $range ): int {
		return self::PERIOD_SEQ + (int) ( self::PERIOD_CODE[ $range ] ?? 0 );
	}

	/**
	 * Ready to serve = the journey table exists in its current shape (1.2.0 or later).
	 * // [2026-11-17 12:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.154 W1 hotfix — was `option === SCHEMA_VERSION`: the 1.3.0 bump (no DDL on the
	 * journey table) made every Thành tích route answer ready:false after deploy until a fresh wp-admin page ran admin_init (REST never does).
	 * "Ready" and "needs the version bump" are now two questions.
	 */
	const READY_FROM = '1.2.0';

	public static function installed(): bool {
		if ( ! function_exists( 'get_option' ) ) {
			return false;
		}
		$v = (string) get_option( self::OPTION_VERSION, '' );
		return '' !== $v && version_compare( $v, self::READY_FROM, '>=' );
	}

	private static function current(): bool {
		return function_exists( 'get_option' ) && (string) get_option( self::OPTION_VERSION, '' ) === self::SCHEMA_VERSION;
	}

	/** Idempotent; cheap when the option already matches (called on admin_init and before every evaluator run). */
	public static function maybe_install(): void {
		if ( self::current() ) {
			return;
		}
		// [2026-11-17 02:20 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.154 W3 (D154-2) — no DDL left: every brain table is ledger rows now
		// (journey / metric / period); bizcity_brain_journey is no longer created. Only the version mark and the one-time copy remain.
		if ( function_exists( 'update_option' ) ) {
			update_option( self::OPTION_VERSION, self::SCHEMA_VERSION, false );
		}
		self::schedule_journey_copy();
	}
}
