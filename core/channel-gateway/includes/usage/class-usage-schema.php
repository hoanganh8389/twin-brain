<?php
/**
 * Usage ledger — per-blog tables of the site's COPY of its own client ledger (PHASE-0.107 S107-L9, docs 62 §5.3 + 64 L12).
 *
 * `{prefix}bizcity_usage_ledger` = one row per cell ledger row of this client (contract usage-ledger@1), keyed by
 * (account_id, account_seq). Billed columns only: no cost, no real tokens, no markup (R-USAGE-ONE-LEDGER §5).
 * `{prefix}bizcity_usage_daily`  = aggregate per (day, platform, account_id, bound_user_id, actor_role, actor_user_id, kind),
 * rebuilt from the ledger for every (day, account) an ingest touched.
 *
 * Per blog (`$wpdb->prefix`, never base_prefix). Version option `bizcity_usage_ledger_schema`.
 *
 * // [2026-10-13 01:08 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.107 S107-L9 — new module core/channel-gateway/includes/usage/.
 *
 * @package BizCity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

// Loaded with require_once only (no class_exists self-guard: an early-bound declaration makes that guard always true).
final class BizCity_Usage_Schema {

	// [2026-10-13 02:28 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.107 S107-L14 — 1.1.0 adds {prefix}bizcity_account_plans (client plan per account id).
	// [2026-10-13 03:30 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.107 round 5 — 1.2.0: the SITE enforces plans (no push to the cell): ai_off_by_plan / ai_off_at / enforce_error / last_notice_*.
	// [2026-10-13 06:14 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.107 S107-L42 — 1.3.0: surface VARCHAR(24) to VARCHAR(96) (cell writes gateway:<caller>).
	// [2026-10-15 05:50 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.112 S112-L1 — 1.4.0 adds {prefix}bizcity_activity_day (the cell's daily ACTIVITY COUNTS per number, activity-day@1; no money).
	// [2026-10-15 10:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.112 S112-W9 — 1.5.0: activity_day += points / jobs_done / jobs_failed / jobs_by_kind / efficiency_score / points_rev (activity-day@1.1, no tokens).
	// [2026-10-25 09:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.139 R126-TB (owner "gộp 3 bảng làm 1 sổ cái") — 1.6.0: bizcity_activity_day is no longer a table — its rows live in bizcity_usage_ledger as kind='day' (payload JSON); activity_day_table() is a derived table and ledger_table() hides the day rows.
	// [2026-10-25 09:47 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.140 S140-L5 — 1.7.0: bizcity_usage_ledger += subject_ref VARCHAR(64) NULL (the file a row served: TL-<id> / file:<msgId>, usage-ledger@1 optional key) + KEY idx_bound_subject (bound_user_id, subject_ref).
	// [2026-11-17 12:30 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.154 W2 (§9 "một khoá ⇒ một sổ") — 1.8.0: bizcity_usage_ledger += key_id BIGINT UNSIGNED
	// (the API key that owns the row: the Hub's key_id on ingested rows, this site's own key on site rows) + KEY idx_key_time (key_id, occurred_at).
	const SCHEMA_VERSION = '1.8.0';
	const OPTION_VERSION = 'bizcity_usage_ledger_schema';
	const READY_FROM     = '1.6.0'; // ledger + day rows exist: readers may serve (a version bump never blanks the screens, PHASE-0.154 W1 lesson)
	const KEY_FROM       = '1.8.0'; // the key_id column exists: writers may fill it
	const OPT_SITE_KEY   = 'bizcity_usage_site_key_id';

	// Site rows of the one ledger (account_seq bands far above any cell sequence; 'day' 4.0e18, 'metric' 4.3e18, 'period' 4.4e18).
	const SALE_ACCOUNT = '_sale'; // one fixed account for sale rows: an order that changes number never becomes a second row (G3)
	const SALE_SEQ     = 4200000000000000000;

	/** The physical ledger table: DDL, ingest inserts and the activity-day rows only. Token readers use ledger_table(). */
	public static function ledger_physical_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_usage_ledger';
	}

	/**
	 * // [2026-10-25 09:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.139 R126-TB (owner "gộp 3 bảng làm 1 sổ cái") — the table every TOKEN reader queries: a derived table over the physical ledger WITHOUT the kind='day' activity rows,
	 * so counts, MAX(account_seq) continuity, MAX(occurred_at) and SUMs keep meaning "tokens / tool calls" exactly as before the merge.
	 */
	public static function ledger_table(): string {
		// [2026-11-17 11:20 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.154 S154-1.1 (G1) — an ALLOW list (was `kind <> 'day'`): the one ledger now also
		// holds cache rows ('metric', 'period', later 'plan' / 'sale' / 'journey'), which must never reach a token SUM / COUNT / MAX(account_seq).
		// The list is exactly the kinds the cell may send (usage-ledger@1 ingest), 'activity' included — daily turns / threads count those rows.
		return '(SELECT * FROM ' . self::ledger_physical_table() . ' WHERE kind IN (' . self::token_kinds_sql() . ')) AS bizcity_usage_ledger_v';
	}

	/** Kinds a token reader may see = BizCity_Usage_Ingest::KINDS (one source; the fallback mirrors it when the ingest class is not loaded). */
	public static function token_kinds_sql(): string {
		$kinds = class_exists( 'BizCity_Usage_Ingest' ) ? BizCity_Usage_Ingest::KINDS : array( 'llm', 'tool', 'adjust', 'reversal', 'activity' );
		return "'" . implode( "','", array_map( static function ( $k ) { return preg_replace( '/[^a-z_]/', '', (string) $k ); }, $kinds ) ) . "'";
	}

	/**
	 * [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-TB (owner: "usage_daily gộp vào ledger cho chuẩn unify token ledger từ cell => hub => client") —
	 * the daily roll-up is no longer a table: this returns a DERIVED TABLE over bizcity_usage_ledger with the exact columns of the old
	 * bizcity_usage_daily (same GROUP BY as the old refresh_daily()), so every reader keeps its `FROM … WHERE …` unchanged and the ledger
	 * is the one source of billed tokens. The physical table is quarantined (rows stay; never written again) — see daily_physical_table().
	 */
	public static function daily_table(): string {
		$l = self::ledger_table();
		return "(SELECT occurred_day AS day, platform, account_id, bound_user_id, actor_role, actor_user_id, kind,
			SUM(billed_tokens) AS billed_tokens, COALESCE(SUM(billed_usd),0) AS billed_usd,
			COUNT(DISTINCT NULLIF(turn_ref,'')) AS turns, COUNT(DISTINCT NULLIF(thread_ref,'')) AS threads,
			SUM(CASE WHEN kind = 'tool' THEN 1 ELSE 0 END) AS tool_calls, MAX(received_at) AS updated_at
			FROM {$l} GROUP BY occurred_day, platform, account_id, bound_user_id, actor_role, actor_user_id, kind) AS bizcity_usage_daily_v";
	}

	/** [2026-10-25] PHASE-0.126 R126-TB — the quarantined physical table name (catalog / diagnostics only; never written). */
	public static function daily_physical_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_usage_daily';
	}

	/*
	 * [2026-11-17 01:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.154 W2c-1 (D154-1/2) — the plan of a number is one row of the one ledger:
	 * kind 'plan', account_id = the number, account_seq = 4.1e18 + platform code (UNIQUE ⇒ one plan per (platform, number), REPLACE = save).
	 * bizcity_account_plans is quarantined: not created, read only by the one-time copy and, until that copy is done, as the bounded fallback
	 * of the view (so the AI switch never loses a plan between deploy and copy). Behaviour of plans is unchanged in this step.
	 */
	const PLAN_SEQ      = 4100000000000000000;
	const PLATFORM_CODE = array( 'zalo_personal' => 1, 'zalo_oa' => 2, 'facebook' => 3, 'web' => 4 ); // unknown platform ⇒ refused, never guessed
	const PLAN_COLS     = array(
		'account_id' => 's:', 'plan_code' => 's:', 'state' => 's:none', 'plan_mode' => 's:off', 'plan_version' => 'i', 'started_at' => 'p',
		'valid_until' => 'p', 'monthly_token_allowance' => 'i', 'allowance_period' => 's:month', 'notice_text' => 's:', 'ai_off_by_plan' => 's:',
		'ai_off_at' => 'p', 'enforce_error' => 's:', 'last_notice_at' => 'p', 'last_notice_reason' => 's:', 'last_order_id' => 'i',
		'last_sold_by' => 'i', 'updated_by' => 'i', 'created_at' => 'p', 'updated_at' => 'p',
	);
	const PLANS_COPY_DONE   = 'bizcity_account_plans_copied_v1';
	const PLANS_COPY_OFFSET = 'bizcity_account_plans_copy_offset';
	const PLANS_COPY_HOOK   = 'bizcity_usage_plans_copy';

	public static function plans_table(): string {
		return self::plans_view_as( 'bizcity_account_plans_v' );
	}

	/** The plans view under a caller-chosen alias (for `… LEFT JOIN {plans} p ON …`: a derived table takes exactly one alias). */
	public static function plans_view_as( string $alias ): string {
		$alias = preg_replace( '/[^A-Za-z0-9_]/', '', $alias );
		return self::kind_view_with_legacy( 'plan', array( 'id', 'platform' ), self::PLAN_COLS, $alias, self::plans_physical_table(), self::PLANS_COPY_DONE,
			"l.kind = 'plan' AND l.account_id = o.account_id AND l.platform = o.platform" );
	}

	/** The quarantined physical table (catalog / copy / fallback only; never written). */
	public static function plans_physical_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_account_plans';
	}

	public static function plan_seq( string $platform ): int {
		return isset( self::PLATFORM_CODE[ $platform ] ) ? self::PLAN_SEQ + (int) self::PLATFORM_CODE[ $platform ] : 0;
	}

	/**
	 * Save one plan row (the old INSERT … ON DUPLICATE KEY UPDATE of every column). created_at keeps the first value the caller passes.
	 *
	 * @param array<string,mixed> $row full plan row (platform, account_id, …)
	 */
	public static function put_plan_row( array $row, string $mode = 'replace' ): bool {
		$platform = (string) ( $row['platform'] ?? '' );
		$seq      = self::plan_seq( $platform );
		$account  = (string) ( $row['account_id'] ?? '' );
		if ( 0 === $seq || '' === $account ) {
			return false;
		}
		return self::put_kind_row( 'plan', $account, $seq, $platform, 0, self::kind_payload( $row, self::PLAN_COLS ), $mode );
	}

	/** admin_init: queue the one-time copy of bizcity_account_plans while it is not done. */
	public static function schedule_plans_copy(): void {
		if ( ( defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI ) || ! function_exists( 'get_option' ) || '1' === (string) get_option( self::PLANS_COPY_DONE, '' ) ) {
			return;
		}
		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::PLANS_COPY_HOOK ) ) {
			wp_schedule_single_event( time() + 60, self::PLANS_COPY_HOOK );
		}
	}

	/** Cron: copy the old plan rows, 500 a page, INSERT IGNORE (a plan saved to the ledger after the deploy keeps its newer state). Done when none is missing. */
	public static function copy_plans(): void {
		if ( ( defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI ) || ! function_exists( 'get_option' ) || '1' === (string) get_option( self::PLANS_COPY_DONE, '' ) ) {
			return;
		}
		global $wpdb;
		$old = self::plans_physical_table();
		if ( ! class_exists( 'BizCity_Table_Metadata' ) || ! BizCity_Table_Metadata::table_exists( $old ) ) {
			update_option( self::PLANS_COPY_DONE, '1', false );
			self::copy_note( 'usage_plans_copy', 'done_no_table', 0, 0 );
			return;
		}
		$offset  = max( 0, (int) get_option( self::PLANS_COPY_OFFSET, 0 ) );
		$skipped = 0;
		for ( $page = 0; $page < 20; $page++ ) {
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$old} ORDER BY id LIMIT %d OFFSET %d", 500, $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( $rows as $r ) {
				if ( 0 === self::plan_seq( (string) ( $r['platform'] ?? '' ) ) ) {
					++$skipped; // unknown platform: never guessed; counted, and the "missing" check below keeps the copy open
					continue;
				}
				if ( ! self::put_plan_row( $r, 'ignore' ) ) {
					update_option( self::PLANS_COPY_OFFSET, $offset, false );
					self::copy_note( 'usage_plans_copy', 'insert_failed', $offset, 0 );
					wp_schedule_single_event( time() + 300, self::PLANS_COPY_HOOK );
					return;
				}
			}
			$offset += count( $rows );
			update_option( self::PLANS_COPY_OFFSET, $offset, false );
			if ( count( $rows ) < 500 ) {
				$led     = self::ledger_physical_table();
				$missing = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$old} o WHERE NOT EXISTS (SELECT 1 FROM {$led} l WHERE l.kind = 'plan' AND l.account_id = o.account_id AND l.platform = o.platform)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( 0 === $missing ) {
					update_option( self::PLANS_COPY_DONE, '1', false );
					self::copy_note( 'usage_plans_copy', 'done', $offset, 0 );
				} elseif ( $missing <= $skipped ) {
					self::copy_note( 'usage_plans_copy', 'unknown_platform_left', $offset, $missing ); // stays open, the view keeps serving those rows from the old table
				} else {
					update_option( self::PLANS_COPY_OFFSET, 0, false );
					self::copy_note( 'usage_plans_copy', 'missing_after_pass', $offset, $missing );
					wp_schedule_single_event( time() + 300, self::PLANS_COPY_HOOK );
				}
				return;
			}
		}
		wp_schedule_single_event( time() + 60, self::PLANS_COPY_HOOK );
	}

	private static function copy_note( string $event, string $state, int $offset, int $n ): void {
		if ( class_exists( 'BizCity_Cron_Manager' ) && method_exists( 'BizCity_Cron_Manager', 'instance' ) ) {
			BizCity_Cron_Manager::instance()->note_event( $event, array( 'state' => $state, 'offset' => $offset, 'n' => $n ) );
		}
	}

	/** The quarantined physical table (rows are copied into the ledger once by merge_legacy_activity(); never written again). */
	public static function activity_day_physical_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_activity_day';
	}

	/** Activity-day columns: name => kind (i int, t JSON text, f decimal, p plain text). */
	const ACTIVITY_COLS = array(
		'measured' => 'i', 'customers' => 'i', 'replies' => 'i', 'customers_new' => 'i', 'replies_failed' => 'i', 'groups_active' => 'i', 'tasks' => 'i',
		'tasks_by_label' => 't', 'by_channel' => 't', 'groups_joined' => 'i', 'connections_active' => 'i', 'streak_days' => 'i', 'ledger_to_seq' => 'i',
		'points' => 'i', 'jobs_done' => 'i', 'jobs_failed' => 'i', 'jobs_by_kind' => 't', 'efficiency_score' => 'f', 'points_rev' => 'p',
	);

	/**
	 * // [2026-10-25 09:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.139 R126-TB (owner "gộp 3 bảng làm 1 sổ cái") — PHASE-0.112 S112-L1 — one row per (account, day): the counts the CELL measured (activity-day@1), now a DERIVED TABLE over the
	 * ledger rows kind='day' with the exact columns of the old bizcity_activity_day (JSON payload => columns), so every reader keeps its FROM ... WHERE.
	 * A row the cell overwrites whole; never a token ledger.
	 */
	public static function activity_day_table(): string {
		$sel = array( 'account_id', 'occurred_day AS day' );
		foreach ( self::ACTIVITY_COLS as $col => $k ) {
			$j = "JSON_EXTRACT(payload, '$.{$col}')";
			if ( 'i' === $k ) {
				$sel[] = "COALESCE(CAST({$j} AS UNSIGNED),0) AS {$col}";
			} elseif ( 'f' === $k ) {
				$sel[] = "CAST({$j} AS DECIMAL(4,1)) AS {$col}";
			} elseif ( 'p' === $k ) {
				$sel[] = "COALESCE(JSON_UNQUOTE({$j}),'') AS {$col}";
			} else {
				$sel[] = "{$j} AS {$col}";
			}
		}
		$sel[] = 'received_at';
		return '(SELECT ' . implode( ', ', $sel ) . ' FROM ' . self::ledger_physical_table() . " WHERE kind = 'day') AS bizcity_activity_day_v";
	}

	/** account_seq of a day row: far above any real cell sequence, one per calendar day, so UNIQUE(account_id, account_seq) makes REPLACE an upsert. */
	public static function day_seq( string $day ): int {
		return 4000000000000000000 + (int) preg_replace( '/\D/', '', $day );
	}

	/** One activity-day row (old table shape) => one physical ledger row (kind='day'). */
	public static function activity_to_ledger_row( array $row ): array {
		$day     = (string) $row['day'];
		$payload = array();
		foreach ( self::ACTIVITY_COLS as $col => $k ) {
			if ( ! array_key_exists( $col, $row ) || null === $row[ $col ] ) {
				continue; // a missing key reads back as SQL NULL (efficiency_score stays NULL)
			}
			$v = $row[ $col ];
			if ( 't' === $k ) {
				$v = is_string( $v ) ? ( json_decode( $v, true ) ?? array() ) : $v;
			} elseif ( 'i' === $k ) {
				$v = (int) $v;
			} elseif ( 'f' === $k ) {
				$v = (float) $v;
			} else {
				$v = (string) $v;
			}
			$payload[ $col ] = $v;
		}
		return array(
			'account_id'    => (string) $row['account_id'],
			'account_seq'   => self::day_seq( $day ),
			'row_ref'       => 'day',
			'kind'          => 'day',
			'occurred_at'   => $day . ' 00:00:00',
			'occurred_day'  => $day,
			'resolve_state' => 'ok',
			'received_at'   => (string) ( $row['received_at'] ?? gmdate( 'Y-m-d H:i:s' ) ),
			'payload'       => function_exists( 'wp_json_encode' ) ? wp_json_encode( $payload ) : json_encode( $payload ),
		);
	}

	// [2026-11-17 11:45 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.154 S154-0.6 (N7) — the first copy (PHASE-0.139) read `LIMIT 20000` once, inside a web
	// request, then set `bizcity_activity_day_merged` even when more rows existed: a site with > 20 000 activity_day rows lost the rest while the
	// flag said done. Now: a WP-Cron job copies in pages of 500 with a saved offset (the old table is frozen, so the order is stable), INSERT IGNORE
	// makes a re-copy harmless (it also repairs sites flagged by the old code), and `_v2` is set only when every old (account, day) is in the ledger.
	const MERGE_DONE   = 'bizcity_activity_day_merged_v2';
	const MERGE_OFFSET = 'bizcity_activity_day_merge_offset';
	const MERGE_HOOK   = 'bizcity_usage_activity_merge';
	const MERGE_PAGE   = 500;
	const MERGE_PAGES  = 20; // per cron run: ≤ 10 000 rows

	/** admin_init: queue the copy job while it is not done (cheap when done). */
	public static function schedule_merge(): void {
		if ( ( defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI ) || ! function_exists( 'get_option' ) || '1' === (string) get_option( self::MERGE_DONE, '' ) ) {
			return;
		}
		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::MERGE_HOOK ) ) {
			wp_schedule_single_event( time() + 60, self::MERGE_HOOK );
		}
	}

	/** Cron callback: copy a bounded number of pages of the old bizcity_activity_day rows into the ledger (kind='day'). */
	public static function merge_legacy_activity(): void {
		if ( ( defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI ) || ! function_exists( 'get_option' ) || '1' === (string) get_option( self::MERGE_DONE, '' ) ) {
			return;
		}
		global $wpdb;
		$old = self::activity_day_physical_table();
		if ( ! class_exists( 'BizCity_Table_Metadata' ) || ! BizCity_Table_Metadata::table_exists( $old ) ) {
			update_option( self::MERGE_DONE, '1', false ); // new install / table already dropped: nothing to copy
			self::merge_note( 'done_no_table', 0, 0 );
			return;
		}
		$offset = max( 0, (int) get_option( self::MERGE_OFFSET, 0 ) );
		$copied = 0;
		for ( $page = 0; $page < self::MERGE_PAGES; $page++ ) {
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$old} ORDER BY account_id, day LIMIT %d OFFSET %d", self::MERGE_PAGE, $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( $rows as $r ) {
				$row = self::activity_to_ledger_row( $r );
				$ok  = $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					'INSERT IGNORE INTO ' . self::ledger_physical_table() . ' (account_id, account_seq, row_ref, kind, occurred_at, occurred_day, resolve_state, received_at, payload) VALUES (%s,%d,%s,%s,%s,%s,%s,%s,%s)',
					$row['account_id'], $row['account_seq'], $row['row_ref'], $row['kind'], $row['occurred_at'], $row['occurred_day'], $row['resolve_state'], $row['received_at'], $row['payload']
				) );
				if ( false === $ok ) {
					update_option( self::MERGE_OFFSET, $offset, false ); // resume from this page
					self::merge_note( 'insert_failed', $copied, $offset );
					wp_schedule_single_event( time() + 300, self::MERGE_HOOK );
					return;
				}
			}
			$offset += count( $rows );
			$copied += count( $rows );
			update_option( self::MERGE_OFFSET, $offset, false );
			if ( count( $rows ) < self::MERGE_PAGE ) {
				$missing = self::merge_missing();
				if ( 0 === $missing ) {
					update_option( self::MERGE_DONE, '1', false );
					self::merge_note( 'done', $copied, $offset );
				} else {
					update_option( self::MERGE_OFFSET, 0, false ); // a full pass left gaps: one more full pass (INSERT IGNORE skips what is there)
					self::merge_note( 'missing_after_pass', $copied, $missing );
					wp_schedule_single_event( time() + 300, self::MERGE_HOOK );
				}
				return;
			}
		}
		self::merge_note( 'more', $copied, $offset );
		wp_schedule_single_event( time() + 60, self::MERGE_HOOK );
	}

	/** Old (account_id, day) rows with no ledger day row yet (UNIQUE account_id, account_seq; seq = 4e18 + YYYYMMDD). */
	private static function merge_missing(): int {
		global $wpdb;
		$old = self::activity_day_physical_table();
		$led = self::ledger_physical_table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$old} o LEFT JOIN {$led} l ON l.account_id = o.account_id AND l.account_seq = 4000000000000000000 + CAST(DATE_FORMAT(o.day, '%Y%m%d') AS UNSIGNED) WHERE l.id IS NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** R-CRON-META evidence (counts only). */
	private static function merge_note( string $state, int $copied, int $n ): void {
		if ( class_exists( 'BizCity_Cron_Manager' ) && method_exists( 'BizCity_Cron_Manager', 'instance' ) ) {
			BizCity_Cron_Manager::instance()->note_event( 'usage_activity_merge', array( 'state' => $state, 'copied' => $copied, 'n' => $n ) );
		}
	}

	/** Ready to serve readers (ledger with day rows, 1.6.0 or later). [2026-11-17 12:30 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.154 W2 — was `=== SCHEMA_VERSION`. */
	public static function installed(): bool {
		return self::at_least( self::READY_FROM );
	}

	/** The key_id column exists (1.8.0 or later). Writers add key_id only then, so a not-yet-upgraded site never sends an unknown column. */
	public static function has_key_column(): bool {
		return self::at_least( self::KEY_FROM );
	}

	private static function at_least( string $version ): bool {
		if ( ! function_exists( 'get_option' ) ) {
			return false;
		}
		$v = (string) get_option( self::OPTION_VERSION, '' );
		return '' !== $v && version_compare( $v, $version, '>=' );
	}

	/** This site's own API key id, as the Hub stamps it on every ledger-ingest batch (no Hub call). 0 = not learnt yet. */
	public static function site_key_id(): int {
		return function_exists( 'get_option' ) ? max( 0, (int) get_option( self::OPT_SITE_KEY, 0 ) ) : 0;
	}

	/** Remember the key id the Hub stamped on an authenticated batch (only when it changes: no write per request). */
	public static function remember_site_key( int $key_id ): void {
		if ( $key_id > 0 && function_exists( 'update_option' ) && self::site_key_id() !== $key_id ) {
			update_option( self::OPT_SITE_KEY, $key_id, false );
		}
	}

	/**
	 * [2026-11-17 12:30 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.154 W2 — a DERIVED TABLE over the ledger rows of one site kind, with the exact columns of
	 * the table it replaces: `$base` = ledger columns (with AS), `$cols` = payload keys name => i (integer) | f (decimal) | p (plain / datetime, NULL
	 * when absent). Shared by the metric / period / sale (and later plan) views so every reader keeps its `FROM … WHERE …`.
	 *
	 * @param string[]             $base
	 * @param array<string,string> $cols
	 */
	public static function kind_view( string $kind, array $base, array $cols, string $alias ): string {
		return '(' . self::kind_select( $kind, $base, $cols ) . ') AS ' . $alias;
	}

	/**
	 * The SELECT of kind_view() without parentheses / alias. Column types: i integer · f decimal · p plain (NULL when absent) ·
	 * s:<default> plain NOT NULL (absent ⇒ the old column default, so PHP comparisons such as `'' !== $row['x']` keep their meaning).
	 *
	 * @param string[]             $base
	 * @param array<string,string> $cols
	 */
	public static function kind_select( string $kind, array $base, array $cols ): string {
		$sel = $base;
		foreach ( $cols as $col => $type ) {
			$j = "JSON_EXTRACT(payload, '$.{$col}')";
			if ( 'i' === $type ) {
				$sel[] = "COALESCE(CAST({$j} AS SIGNED),0) AS {$col}";
			} elseif ( 'f' === $type ) {
				$sel[] = "COALESCE(CAST({$j} AS DECIMAL(18,8)),0) AS {$col}";
			} elseif ( 0 === strpos( $type, 's:' ) ) {
				$sel[] = "COALESCE(JSON_UNQUOTE({$j}),'" . preg_replace( '/[^a-z0-9_]/', '', substr( $type, 2 ) ) . "') AS {$col}";
			} else {
				$sel[] = "JSON_UNQUOTE({$j}) AS {$col}"; // absent key ⇒ SQL NULL
			}
		}
		$kind = preg_replace( '/[^a-z_]/', '', $kind );
		return 'SELECT ' . implode( ', ', $sel ) . ' FROM ' . self::ledger_physical_table() . " WHERE kind = '{$kind}'";
	}

	/**
	 * [2026-11-17 01:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.154 W2 — kind_view() that, until the one-time copy of the old table is done
	 * ($done_opt = '1'), also returns the old rows not yet in the ledger (bounded read fallback of a frozen table, R-TABLE-FREEZE). Readers never
	 * see a row disappear between deploy and the end of the copy — critical for plans (the AI switch reads them) and sales (money).
	 * The old table must have columns named like $base + $cols, in that order. $match_sql: the condition, on ledger alias `l` and old alias `o`,
	 * that says "this old row is already in the ledger".
	 */
	public static function kind_view_with_legacy( string $kind, array $base, array $cols, string $alias, string $old, string $done_opt, string $match_sql ): string {
		$select = self::kind_select( $kind, $base, $cols );
		$done   = ! function_exists( 'get_option' ) || '1' === (string) get_option( $done_opt, '' );
		if ( $done || ! class_exists( 'BizCity_Table_Metadata' ) || ! BizCity_Table_Metadata::table_exists( $old ) ) {
			return '(' . $select . ') AS ' . $alias;
		}
		$names = array();
		foreach ( $base as $b ) {
			$names[] = 'o.' . trim( (string) preg_replace( '/^.*\s+AS\s+/i', '', $b ) ); // `received_at AS created_at` ⇒ o.created_at
		}
		foreach ( array_keys( $cols ) as $c ) {
			$names[] = 'o.' . $c;
		}
		$led = self::ledger_physical_table();
		return "({$select} UNION ALL SELECT " . implode( ', ', $names ) . " FROM {$old} o WHERE NOT EXISTS (SELECT 1 FROM {$led} l WHERE {$match_sql})) AS {$alias}";
	}

	/**
	 * Payload of one site row: only the declared keys, typed, nulls left out (they read back as SQL NULL).
	 *
	 * @param array<string,mixed>  $row
	 * @param array<string,string> $cols
	 * @return array<string,mixed>
	 */
	public static function kind_payload( array $row, array $cols ): array {
		$payload = array();
		foreach ( $cols as $col => $type ) {
			if ( ! array_key_exists( $col, $row ) || null === $row[ $col ] ) {
				continue;
			}
			$v = $row[ $col ];
			$payload[ $col ] = 'i' === $type ? (int) $v : ( 'f' === $type ? (float) $v : (string) $v );
		}
		return $payload;
	}

	/**
	 * Write one site row of the ledger. $mode 'replace' overwrites (caches), 'ignore' keeps an existing row (one-time copies of old tables).
	 * key_id = this site's key when the column exists.
	 *
	 * @param array<string,mixed> $payload already shaped (kind_payload)
	 */
	public static function put_kind_row( string $kind, string $account_id, int $seq, string $platform, int $bound_user_id, array $payload, string $mode = 'replace', string $occurred_at = '' ): bool {
		global $wpdb;
		if ( '' === $account_id || ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return false;
		}
		$now  = gmdate( 'Y-m-d H:i:s' );
		$at   = '' !== $occurred_at ? $occurred_at : $now;
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $payload ) : json_encode( $payload );
		$cols = 'account_id, account_seq, row_ref, kind, occurred_at, occurred_day, platform, bound_user_id, resolve_state, received_at, payload';
		$ph   = '%s,%d,%s,%s,%s,%s,%s,%d,%s,%s,%s';
		$args = array( $account_id, $seq, substr( $kind, 0, 16 ), $kind, $at, substr( $at, 0, 10 ), $platform, $bound_user_id, 'ok', $now, (string) $json );
		if ( self::has_key_column() ) {
			$cols  .= ', key_id';
			$ph    .= ',%d';
			$args[] = self::site_key_id();
		}
		$verb = 'ignore' === $mode ? 'INSERT IGNORE INTO' : 'REPLACE INTO';
		$ok   = $wpdb->query( $wpdb->prepare( "{$verb} " . self::ledger_physical_table() . " ({$cols}) VALUES ({$ph})", $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		self::$last_affected = false === $ok ? -1 : (int) $ok;
		return false !== $ok;
	}

	/** Rows the last put_kind_row() really wrote (-1 = error, 0 = INSERT IGNORE kept an existing row). */
	private static $last_affected = 0;

	/**
	 * [2026-11-17 02:20 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.154 W3 — insert one site row only if (account_id, account_seq) is free, and say whether
	 * THIS call wrote it. Replaces a UNIQUE key of an old table (e.g. brain_journey's UNIQUE(subject, seq)): false = taken or error.
	 *
	 * @param array<string,mixed> $payload already shaped (kind_payload)
	 */
	public static function insert_kind_row_once( string $kind, string $account_id, int $seq, string $platform, int $bound_user_id, array $payload, string $occurred_at = '' ): bool {
		return self::put_kind_row( $kind, $account_id, $seq, $platform, $bound_user_id, $payload, 'ignore', $occurred_at ) && 1 === self::$last_affected;
	}

	/** Idempotent; cheap when the option already matches (called on admin_init and before every ingest). */
	public static function maybe_install(): void {
		if ( self::at_least( self::SCHEMA_VERSION ) ) {
			return;
		}
		if ( ! defined( 'ABSPATH' ) || ! is_file( ABSPATH . 'wp-admin/includes/upgrade.php' ) ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		global $wpdb;
		$charset = function_exists( 'bizcity_get_charset_collate' ) ? bizcity_get_charset_collate() : $wpdb->get_charset_collate();
		$ledger  = self::ledger_physical_table();

		// occurred_at / received_at are UTC; occurred_day is the SITE-local date (what the daily table and charts use).
		// contact_key = first 12 hex of the cell contact_ref (never the 64-hex), billed_usd NULL = tool not priced by the Hub table.
		dbDelta( "CREATE TABLE {$ledger} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			account_id VARCHAR(64) NOT NULL DEFAULT '',
			account_seq BIGINT UNSIGNED NOT NULL DEFAULT 0,
			row_ref VARCHAR(16) NOT NULL DEFAULT '',
			kind VARCHAR(12) NOT NULL DEFAULT 'llm',
			occurred_at DATETIME NOT NULL,
			occurred_day DATE NOT NULL,
			platform VARCHAR(24) NOT NULL DEFAULT '',
			channel_ref VARCHAR(96) NOT NULL DEFAULT '',
			bound_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			actor_role VARCHAR(12) NOT NULL DEFAULT 'customer',
			actor_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			contact_key VARCHAR(16) NOT NULL DEFAULT '',
			thread_ref VARCHAR(96) NOT NULL DEFAULT '',
			turn_ref VARCHAR(96) NOT NULL DEFAULT '',
			surface VARCHAR(96) NOT NULL DEFAULT '',
			model VARCHAR(120) NOT NULL DEFAULT '',
			tool VARCHAR(80) NOT NULL DEFAULT '',
			tool_kind VARCHAR(12) NOT NULL DEFAULT '',
			units DECIMAL(14,4) NOT NULL DEFAULT 0,
			billed_tokens BIGINT NOT NULL DEFAULT 0,
			billed_usd DECIMAL(18,8) NULL,
			price_source VARCHAR(24) NOT NULL DEFAULT '',
			price_version VARCHAR(40) NOT NULL DEFAULT '',
			is_error TINYINT(1) NOT NULL DEFAULT 0,
			resolve_state VARCHAR(16) NOT NULL DEFAULT 'ok',
			received_at DATETIME NOT NULL,
			payload LONGTEXT NULL,
			subject_ref VARCHAR(64) NULL,
			key_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_account_seq (account_id, account_seq),
			KEY idx_account_time (account_id, occurred_at),
			KEY idx_bound_time (bound_user_id, occurred_at),
			KEY idx_actor_time (actor_user_id, occurred_at),
			KEY idx_thread (thread_ref),
			KEY idx_day_account (occurred_day, account_id),
			KEY idx_kind_account_day (kind, account_id, occurred_day),
			KEY idx_bound_subject (bound_user_id, subject_ref),
			KEY idx_key_time (key_id, occurred_at)
		) {$charset};" ); // [2026-10-25 09:47 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.140 S140-L5 — + subject_ref / idx_bound_subject (1.7.0)

		// [2026-10-25 Johnny Chu - Chu Hoàng Anh] PHASE-0.126 R126-TB — bizcity_usage_daily is no longer created: daily_table() derives it from the ledger (the table is quarantined).

		// [2026-11-17 01:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.154 W2c-1 (D154-2) — bizcity_account_plans is no longer created: a plan is a ledger row kind='plan' (see plans_table()).

		// [2026-10-25 09:40 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.139 R126-TB (owner "gộp 3 bảng làm 1 sổ cái") — bizcity_activity_day is no longer created: its rows are ledger rows kind='day' (see activity_day_table()).

		update_option( self::OPTION_VERSION, self::SCHEMA_VERSION, false );
		self::schedule_merge(); // [2026-11-17 11:45 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.154 S154-0.6 — the copy runs on WP-Cron, never inside this request
		self::schedule_plans_copy(); // [2026-11-17 01:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.154 W2c-1 — one-time copy of bizcity_account_plans, on WP-Cron
	}
}
