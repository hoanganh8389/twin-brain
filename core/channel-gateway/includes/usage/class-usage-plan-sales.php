<?php
/**
 * Revenue ledger of plan sales — one row per PAID plan order of THIS site (PHASE-0.108 S108-L18.2/L18.4/L20.2, 24-TECH-DESIGN-REVENUE.md §2.1, §4.2).
 *
 * Table `{prefix}bizcity_plan_sales` (own schema version, installed on admin_init — no edit of the shared usage schema). A row is written by the
 * action `bizcity_usage_plan_applied` that BizCity_Usage_Plan_Woo::apply() fires AFTER the plan was extended; `UNIQUE(order_id)` makes writing idempotent.
 * The double-extend guard itself stays in BizCity_Usage_Plan_Woo (option lock). This table is the CACHE that revenue screens read (SUM … GROUP BY) and is
 * rebuildable from the paid Woo orders (`rebuild_from_orders`, S108-L18.5). Only applied sales are here, so every SUM is revenue by definition.
 * A refunded order keeps its row with status `refunded` (the plan is NOT shortened — the owner decides) and is excluded from revenue.
 *
 * Money is whole VND. Times are UTC. The switch option `bizcity_plan_sales_enabled` (default yes) is the way back.
 *
 * Test seams ($readers): rows(sql, args): array · exec(sql, args): int|false · order_details(order_id): ?array · now(): int · enabled(): bool · installed(): bool
 *
 * // [2026-10-13 09:16 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.108 S108-L18.2 / L18.4 / L20.2.
 *
 * @package BizCity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Usage_Plan_Sales {

	const SCHEMA_VERSION = '1.0.0';
	const OPTION_VERSION = 'bizcity_plan_sales_schema';
	const OPT_ENABLED    = 'bizcity_plan_sales_enabled';

	const ST_PAID     = 'paid';
	const ST_REFUNDED = 'refunded';

	/** @var array<string,callable> */
	public static $readers = array();

	private static $initialized = false;

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;
		add_action( 'admin_init', array( __CLASS__, 'maybe_install' ) );
		add_action( self::COPY_HOOK, array( __CLASS__, 'copy_legacy' ) ); // PHASE-0.154 W2 — one-time paged copy of the old table
		add_action( 'bizcity_usage_plan_applied', array( __CLASS__, 'on_applied' ), 10, 3 );
		add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'on_refunded' ), 20, 1 );
	}

	/*
	 * [2026-11-17 12:30 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.154 W2 (D154-1/2, §9) — storage: one row of the ONE ledger per order (kind 'sale',
	 * account_id '_sale', account_seq 4.2e18 + order_id, the number in the payload, key_id = this site's key). bizcity_plan_sales is quarantined:
	 * not created on a new install, read once by copy_legacy(), never written. table() is a derived table with the old columns, so the revenue
	 * screens keep their SQL. Fixes on the way: G9 (a sale arriving before any admin visit was lost: the ledger always exists), G3 (an order
	 * re-recorded under another number stays ONE row).
	 */
	const SALE_COLS = array(
		'order_id' => 'i', 'account_id' => 'p', 'package_code' => 'p', 'months' => 'i', 'allowance' => 'i', 'amount_vnd' => 'i', 'floor_vnd' => 'i',
		'floor_version' => 'p', 'channel' => 'p', 'created_by' => 'i', 'payer_user_id' => 'i', 'status' => 'p', 'valid_until' => 'p', 'paid_at' => 'p',
		'applied_at' => 'p', 'refunded_at' => 'p', 'method' => 'p', 'bank_amount_vnd' => 'i', 'bank_ref' => 'p', 'created_at' => 'p',
	);
	/** Keys a re-record of the same order overwrites (the old ON DUPLICATE KEY UPDATE list); everything else keeps its first value. */
	const UPDATE_KEYS = array( 'account_id', 'package_code', 'months', 'allowance', 'amount_vnd', 'valid_until', 'applied_at' );
	const COPY_DONE   = 'bizcity_plan_sales_copied_v1';
	const COPY_OFFSET = 'bizcity_plan_sales_copy_offset';
	const COPY_HOOK   = 'bizcity_usage_sales_copy';
	const COPY_PAGE   = 500;

	public static function table(): string {
		// [2026-11-17 01:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.154 W2 — while the one-time copy is not done, the revenue screens also read the old
		// sales not yet in the ledger (shared BizCity_Usage_Schema::kind_view_with_legacy); after the copy: the ledger only.
		$seq0 = (int) BizCity_Usage_Schema::SALE_SEQ;
		return BizCity_Usage_Schema::kind_view_with_legacy( 'sale', array( 'id', 'platform' ), self::SALE_COLS, 'bizcity_plan_sales_v', self::physical_table(), self::COPY_DONE,
			"l.account_id = '" . BizCity_Usage_Schema::SALE_ACCOUNT . "' AND l.account_seq = {$seq0} + o.order_id" );
	}

	/** The quarantined physical table (read once by copy_legacy(); never written). */
	public static function physical_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_plan_sales';
	}

	public static function seq( int $order_id ): int {
		return BizCity_Usage_Schema::SALE_SEQ + max( 0, $order_id );
	}

	/** The ledger is the storage: ready as soon as the usage schema is (no table of our own to wait for). */
	public static function installed(): bool {
		if ( isset( self::$readers['installed'] ) ) {
			return (bool) call_user_func( self::$readers['installed'] );
		}
		return class_exists( 'BizCity_Usage_Schema' ) && BizCity_Usage_Schema::installed();
	}

	/** admin_init / revenue REST: no DDL any more (D154-2); queue the one-time copy of the old rows while it is not done. */
	public static function maybe_install(): void {
		if ( ( defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI ) || ! function_exists( 'get_option' ) || '1' === (string) get_option( self::COPY_DONE, '' ) ) {
			return;
		}
		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::COPY_HOOK ) ) {
			wp_schedule_single_event( time() + 60, self::COPY_HOOK );
		}
	}

	/**
	 * Cron: copy the old bizcity_plan_sales rows into the ledger, 500 a page (INSERT IGNORE: a sale already written to the ledger after the deploy
	 * keeps its newer state). Done only when no old order is missing from the ledger; the VND difference over the copied orders is noted as evidence.
	 */
	public static function copy_legacy(): void {
		if ( ( defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI ) || '1' === (string) get_option( self::COPY_DONE, '' ) ) {
			return;
		}
		$old = self::physical_table();
		if ( ! class_exists( 'BizCity_Table_Metadata' ) || ! BizCity_Table_Metadata::table_exists( $old ) ) {
			update_option( self::COPY_DONE, '1', false );
			self::copy_note( 'done_no_table', 0, 0 );
			return;
		}
		$offset = max( 0, (int) get_option( self::COPY_OFFSET, 0 ) );
		for ( $page = 0; $page < 20; $page++ ) {
			$rows = self::rows( "SELECT * FROM {$old} ORDER BY id LIMIT %d OFFSET %d", array( self::COPY_PAGE, $offset ) );
			foreach ( $rows as $r ) {
				$ok = BizCity_Usage_Schema::put_kind_row( 'sale', BizCity_Usage_Schema::SALE_ACCOUNT, self::seq( (int) $r['order_id'] ), (string) ( $r['platform'] ?? '' ),
					(int) ( $r['payer_user_id'] ?? 0 ), BizCity_Usage_Schema::kind_payload( $r, self::SALE_COLS ), 'ignore', (string) ( $r['paid_at'] ?? '' ) );
				if ( ! $ok ) {
					update_option( self::COPY_OFFSET, $offset, false );
					self::copy_note( 'insert_failed', $offset, 0 );
					wp_schedule_single_event( time() + 300, self::COPY_HOOK );
					return;
				}
			}
			$offset += count( $rows );
			update_option( self::COPY_OFFSET, $offset, false );
			if ( count( $rows ) < self::COPY_PAGE ) {
				$led     = BizCity_Usage_Schema::ledger_physical_table();
				$seq0    = BizCity_Usage_Schema::SALE_SEQ;
				$missing = self::rows( "SELECT COUNT(*) AS n FROM {$old} o LEFT JOIN {$led} l ON l.account_id = %s AND l.account_seq = %d + o.order_id WHERE l.id IS NULL", array( BizCity_Usage_Schema::SALE_ACCOUNT, $seq0 ) );
				$diff    = self::rows( "SELECT COALESCE(SUM(o.amount_vnd),0) - COALESCE(SUM(CAST(JSON_EXTRACT(l.payload, '$.amount_vnd') AS SIGNED)),0) AS d FROM {$old} o JOIN {$led} l ON l.account_id = %s AND l.account_seq = %d + o.order_id", array( BizCity_Usage_Schema::SALE_ACCOUNT, $seq0 ) );
				$n       = (int) ( $missing[0]['n'] ?? -1 );
				if ( 0 === $n ) {
					update_option( self::COPY_DONE, '1', false );
					self::copy_note( 'done', $offset, (int) ( $diff[0]['d'] ?? 0 ) ); // vnd_diff ≠ 0 only when an order was re-recorded after the deploy
				} else {
					update_option( self::COPY_OFFSET, 0, false );
					self::copy_note( 'missing_after_pass', $offset, $n );
					wp_schedule_single_event( time() + 300, self::COPY_HOOK );
				}
				return;
			}
		}
		wp_schedule_single_event( time() + 60, self::COPY_HOOK );
	}

	private static function copy_note( string $state, int $offset, int $n ): void {
		if ( class_exists( 'BizCity_Cron_Manager' ) && method_exists( 'BizCity_Cron_Manager', 'instance' ) ) {
			BizCity_Cron_Manager::instance()->note_event( 'usage_sales_copy', array( 'state' => $state, 'offset' => $offset, 'n' => $n ) );
		}
	}

	public static function enabled(): bool {
		if ( isset( self::$readers['enabled'] ) ) {
			return (bool) call_user_func( self::$readers['enabled'] );
		}
		return ! function_exists( 'get_option' ) || 'no' !== (string) get_option( self::OPT_ENABLED, 'yes' );
	}

	/* ================================================================
	 *  Writers
	 * ================================================================ */

	/** Hook: BizCity_Usage_Plan_Woo applied an order. Never throws, never undoes the extension. */
	public static function on_applied( $order_id, $order, $plan_row ): void {
		if ( ! self::enabled() || ! self::installed() ) {
			return;
		}
		try {
			$d = self::details( (int) $order_id );
			if ( ! $d ) {
				return;
			}
			$row = self::shape_row( (int) $order_id, (array) $order, is_array( $plan_row ) ? $plan_row : array(), $d, self::now() );
			self::record( $row );
		} catch ( \Throwable $e ) {
			if ( function_exists( 'error_log' ) ) {
				error_log( '[BizCity][PLAN-SALES] record failed for order ' . (int) $order_id . ': ' . $e->getMessage() );
			}
		}
	}

	/**
	 * Pure: the row to store from an applied order. `$o` is what Plan_Woo::order() returned (account_id, user_id, months, allowance),
	 * `$plan` the plan row after the extension, `$d` the Woo-side details (see details()).
	 *
	 * @return array<string,mixed>
	 */
	public static function shape_row( int $order_id, array $o, array $plan, array $d, int $now ): array {
		$months = max( 1, (int) ( $o['months'] ?? 0 ) ?: (int) ( $d['months'] ?? 0 ) );
		$package = (string) ( $d['package'] ?? '' );
		return array(
			'order_id'        => $order_id,
			'platform'        => (string) ( $plan['platform'] ?? 'zalo_personal' ),
			'account_id'      => (string) ( $o['account_id'] ?? '' ),
			'package_code'    => '' !== $package ? substr( $package, 0, 16 ) : 'legacy', // an order from before the package flow
			'months'          => $months,
			'allowance'       => (int) ( $plan['monthly_token_allowance'] ?? ( $o['allowance'] ?? 0 ) ),
			'amount_vnd'      => max( 0, (int) round( (float) ( $d['total'] ?? 0 ) ) ),
			'floor_vnd'       => max( 0, (int) ( $d['floor_vnd'] ?? 0 ) ),
			'floor_version'   => substr( (string) ( $d['floor_version'] ?? '' ), 0, 40 ),
			'channel'         => in_array( ( $d['channel'] ?? '' ), array( 'admin', 'self' ), true ) ? $d['channel'] : '',
			'created_by'      => (int) ( $d['created_by'] ?? 0 ),
			'payer_user_id'   => (int) ( $o['user_id'] ?? 0 ),
			'status'          => self::ST_PAID,
			'valid_until'     => isset( $plan['valid_until'] ) && '' !== (string) $plan['valid_until'] ? (string) $plan['valid_until'] : null,
			'paid_at'         => self::fmt( (int) ( $d['paid_ts'] ?? 0 ) ?: $now ),
			'applied_at'      => self::fmt( $now ),
			'method'          => substr( (string) ( $d['method'] ?? '' ), 0, 24 ),
			'bank_amount_vnd' => max( 0, (int) ( $d['bank_amount_vnd'] ?? 0 ) ),
			'bank_ref'        => substr( (string) ( $d['bank_ref'] ?? '' ), 0, 64 ),
			'created_at'      => self::fmt( $now ),
		);
	}

	/** Idempotent on order_id (re-running the same order updates the same row). */
	public static function record( array $row ): bool {
		// [2026-11-17 12:30 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.154 W2 — one ledger row per order. A re-record keeps the first values (paid_at,
		// method, payer…) and overwrites only UPDATE_KEYS, like the old ON DUPLICATE list; the SQL keeps a refund that landed meanwhile.
		$order = (int) ( $row['order_id'] ?? 0 );
		if ( $order <= 0 || ! class_exists( 'BizCity_Usage_Schema' ) ) {
			return false;
		}
		$led    = BizCity_Usage_Schema::ledger_physical_table();
		$seq    = self::seq( $order );
		$merged = $row;
		$prev   = self::rows( "SELECT payload FROM {$led} WHERE account_id = %s AND account_seq = %d", array( BizCity_Usage_Schema::SALE_ACCOUNT, $seq ) );
		$old    = isset( $prev[0]['payload'] ) ? json_decode( (string) $prev[0]['payload'], true ) : null;
		if ( is_array( $old ) ) {
			$merged = $old;
			foreach ( self::UPDATE_KEYS as $k ) {
				$merged[ $k ] = $row[ $k ] ?? null;
			}
		}
		$payload = BizCity_Usage_Schema::kind_payload( $merged, self::SALE_COLS );
		$json    = function_exists( 'wp_json_encode' ) ? wp_json_encode( $payload ) : json_encode( $payload );
		$now     = self::fmt( self::now() );
		$at      = (string) ( $merged['paid_at'] ?? $now );
		$cols    = 'account_id, account_seq, row_ref, kind, occurred_at, occurred_day, platform, bound_user_id, resolve_state, received_at, payload';
		$ph      = '%s,%d,%s,%s,%s,%s,%s,%d,%s,%s,%s';
		$args    = array( BizCity_Usage_Schema::SALE_ACCOUNT, $seq, 'sale', 'sale', $at, substr( $at, 0, 10 ), (string) ( $merged['platform'] ?? '' ), (int) ( $merged['payer_user_id'] ?? 0 ), 'ok', $now, (string) $json );
		if ( BizCity_Usage_Schema::has_key_column() ) {
			$cols  .= ', key_id';
			$ph    .= ',%d';
			$args[] = BizCity_Usage_Schema::site_key_id();
		}
		$keep_refund = "IF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.status')) = 'refunded', JSON_SET(VALUES(payload), '$.status', 'refunded', '$.refunded_at', COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.refunded_at')), '')), VALUES(payload))";
		$sql = "INSERT INTO {$led} ({$cols}) VALUES ({$ph}) ON DUPLICATE KEY UPDATE payload = {$keep_refund}, platform = VALUES(platform), bound_user_id = VALUES(bound_user_id), received_at = VALUES(received_at)";
		return false !== self::exec( $sql, $args );
	}

	/** The plan is NOT shortened (24 §4.2): only the flag, so the owner sees it and decides. */
	public static function on_refunded( $order_id ): void {
		if ( ! self::installed() ) {
			return;
		}
		self::mark_refunded( (int) $order_id, self::now() );
	}

	public static function mark_refunded( int $order_id, int $now ): bool {
		// [2026-11-17 12:30 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.154 W2 — the sale is a ledger row: only status / refunded_at change (constant values, so
		// JSON_SET behaves the same on MySQL and MariaDB); a paid order only, never twice.
		$led = BizCity_Usage_Schema::ledger_physical_table();
		return false !== self::exec(
			"UPDATE {$led} SET payload = JSON_SET(payload, '$.status', %s, '$.refunded_at', %s) WHERE account_id = %s AND account_seq = %d AND kind = 'sale' AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.status')) = %s",
			array( self::ST_REFUNDED, self::fmt( $now ), BizCity_Usage_Schema::SALE_ACCOUNT, self::seq( $order_id ), self::ST_PAID )
		);
	}

	/* ================================================================
	 *  Readers (revenue screens)
	 * ================================================================ */

	/**
	 * Pure: [from, to) of a range key in UTC strings plus the previous period of the same length.
	 *
	 * @return array{from:string,to:string,prev_from:string,prev_to:string,key:string}
	 */
	public static function period( string $range, int $now, DateTimeZone $tz ): array {
		$local = ( new DateTime( '@' . $now ) )->setTimezone( $tz );
		if ( 'month' === $range || 'prev_month' === $range ) {
			$start = ( clone $local )->modify( 'first day of this month' )->setTime( 0, 0, 0 );
			if ( 'prev_month' === $range ) {
				$start = ( clone $start )->modify( '-1 month' );
			}
			$end        = ( clone $start )->modify( '+1 month' );
			$prev_start = ( clone $start )->modify( '-1 month' );
			$key        = $range;
		} else {
			$days       = '7d' === $range ? 7 : 30;
			$key        = '7d' === $range ? '7d' : '30d';
			$end        = ( clone $local )->setTime( 0, 0, 0 )->modify( '+1 day' ); // today included
			$start      = ( clone $end )->modify( "-{$days} days" );
			$prev_start = ( clone $start )->modify( "-{$days} days" );
		}
		$utc = new DateTimeZone( 'UTC' );
		$f   = static function ( DateTime $d ) use ( $utc ) { return ( clone $d )->setTimezone( $utc )->format( 'Y-m-d H:i:s' ); };
		$day = static function ( DateTime $d ) use ( $tz ) { return ( clone $d )->setTimezone( $tz )->format( 'Y-m-d' ); }; // usage_daily.day / ledger.occurred_day are SITE-local dates
		return array(
			'from' => $f( $start ), 'to' => $f( $end ), 'prev_from' => $f( $prev_start ), 'prev_to' => $f( $start ), 'key' => $key,
			'from_day' => $day( $start ), 'to_day' => $day( $end ), 'prev_from_day' => $day( $prev_start ), 'prev_to_day' => $day( $start ),
		);
	}

	/** Pure: +18.0 for a rise, null when there is no previous revenue to compare with. */
	public static function delta_pct( int $current, int $previous ): ?float {
		if ( $previous <= 0 ) {
			return null;
		}
		return round( ( $current - $previous ) / $previous * 100, 1 );
	}

	/** @return array{paid_vnd:int,paid_orders:int} */
	public static function summary( string $from_utc, string $to_utc ): array {
		if ( ! self::installed() ) {
			return array( 'paid_vnd' => 0, 'paid_orders' => 0 );
		}
		$t = self::table();
		$r = self::rows( "SELECT COALESCE(SUM(amount_vnd),0) AS paid_vnd, COUNT(*) AS paid_orders FROM {$t} WHERE status <> %s AND paid_at >= %s AND paid_at < %s", array( self::ST_REFUNDED, $from_utc, $to_utc ) );
		return array( 'paid_vnd' => (int) ( $r[0]['paid_vnd'] ?? 0 ), 'paid_orders' => (int) ( $r[0]['paid_orders'] ?? 0 ) );
	}

	/**
	 * Per number: revenue in the range, revenue ever, and the latest payment.
	 *
	 * @return array<string,array{paid_vnd:int,paid_total_vnd:int,last_paid_at:?string,last_order_id:int,last_method:string,refunded:int}>
	 */
	public static function by_account( string $from_utc, string $to_utc ): array {
		if ( ! self::installed() ) {
			return array();
		}
		$t   = self::table();
		$out = array();
		$agg = self::rows(
			"SELECT account_id,
				SUM(CASE WHEN status <> %s AND paid_at >= %s AND paid_at < %s THEN amount_vnd ELSE 0 END) AS paid_vnd,
				SUM(CASE WHEN status <> %s THEN amount_vnd ELSE 0 END) AS paid_total_vnd,
				SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS refunded,
				MAX(paid_at) AS last_paid_at
			 FROM {$t} GROUP BY account_id",
			array( self::ST_REFUNDED, $from_utc, $to_utc, self::ST_REFUNDED, self::ST_REFUNDED )
		);
		foreach ( $agg as $r ) {
			$out[ (string) $r['account_id'] ] = array(
				'paid_vnd'       => (int) $r['paid_vnd'],
				'paid_total_vnd' => (int) $r['paid_total_vnd'],
				'last_paid_at'   => null === $r['last_paid_at'] ? null : (string) $r['last_paid_at'],
				'last_order_id'  => 0,
				'last_method'    => '',
				'refunded'       => (int) $r['refunded'],
			);
		}
		if ( $out ) {
			// [2026-11-17 12:30 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.154 W2 — {$t} is a derived table with its own alias: no `{$t} s JOIN … x` any more.
			$last = self::rows( "SELECT account_id, order_id, method FROM {$t} WHERE (account_id, paid_at) IN (SELECT account_id, MAX(paid_at) FROM {$t} GROUP BY account_id)", array() );
			foreach ( $last as $r ) {
				if ( isset( $out[ (string) $r['account_id'] ] ) ) {
					$out[ (string) $r['account_id'] ]['last_order_id'] = (int) $r['order_id'];
					$out[ (string) $r['account_id'] ]['last_method']   = (string) $r['method'];
				}
			}
		}
		return $out;
	}

	/**
	 * Paid orders, newest first.
	 *
	 * @param array{account_id?:string,status?:string,from?:string,to?:string} $f
	 * @return array{rows:array<int,array<string,mixed>>,total:int}
	 */
	public static function list_rows( array $f, int $page, int $per_page ): array {
		if ( ! self::installed() ) {
			return array( 'rows' => array(), 'total' => 0 );
		}
		$t     = self::table();
		$where = '1=1';
		$args  = array();
		if ( ! empty( $f['account_id'] ) ) {
			$where .= ' AND account_id = %s';
			$args[] = (string) $f['account_id'];
		}
		if ( ! empty( $f['status'] ) ) {
			$where .= ' AND status = %s';
			$args[] = (string) $f['status'];
		}
		if ( ! empty( $f['from'] ) ) {
			$where .= ' AND paid_at >= %s';
			$args[] = (string) $f['from'];
		}
		if ( ! empty( $f['to'] ) ) {
			$where .= ' AND paid_at < %s';
			$args[] = (string) $f['to'];
		}
		$per    = min( 50, max( 1, $per_page ) );
		$offset = max( 0, ( $page - 1 ) * $per );
		$total  = self::rows( "SELECT COUNT(*) AS n FROM {$t} WHERE {$where}", $args );
		$rows   = self::rows( "SELECT * FROM {$t} WHERE {$where} ORDER BY paid_at DESC, id DESC LIMIT %d OFFSET %d", array_merge( $args, array( $per, $offset ) ) );
		return array( 'rows' => $rows, 'total' => (int) ( $total[0]['n'] ?? 0 ) );
	}

	/* ================================================================
	 *  Rebuild (S108-L18.5)
	 * ================================================================ */

	/**
	 * Fill missing rows from paid Woo orders that were already applied (before this table existed). Safe to repeat. Returns [scanned, inserted].
	 *
	 * @return array{0:int,1:int}
	 */
	public static function rebuild_from_orders( int $limit = 200 ): array {
		if ( ! self::installed() || ! function_exists( 'wc_get_orders' ) || ! class_exists( 'BizCity_Usage_Plan_Woo' ) ) {
			return array( 0, 0 );
		}
		$ids = wc_get_orders( array(
			'limit'      => max( 1, min( 500, $limit ) ),
			'status'     => array( 'wc-processing', 'wc-completed' ),
			'return'     => 'ids',
			'meta_query' => array( array( 'key' => BizCity_Usage_Plan_Woo::META_APPLIED, 'compare' => 'EXISTS' ) ),
		) );
		$ins = 0;
		foreach ( (array) $ids as $oid ) {
			$d = self::details( (int) $oid );
			if ( ! $d ) {
				continue;
			}
			$o = array( 'account_id' => (string) ( $d['account_id'] ?? '' ), 'user_id' => (int) ( $d['payer_user_id'] ?? 0 ), 'months' => (int) ( $d['months'] ?? 0 ) );
			if ( '' === $o['account_id'] ) {
				continue;
			}
			$plan = class_exists( 'BizCity_Usage_Plans' ) ? (array) BizCity_Usage_Plans::get( BizCity_Usage_Plans::PLATFORM, $o['account_id'] ) : array();
			if ( self::record( self::shape_row( (int) $oid, $o, $plan, $d, self::now() ) ) ) {
				++$ins;
			}
		}
		return array( count( (array) $ids ), $ins );
	}

	/* ================================================================
	 *  Woo side (seam)
	 * ================================================================ */

	/**
	 * What the revenue row needs from the Woo order. Test seam: order_details(order_id).
	 *
	 * @return array{total:float,package:string,months:int,floor_vnd:int,floor_version:string,channel:string,created_by:int,paid_ts:int,method:string,bank_amount_vnd:int,bank_ref:string,account_id:string,payer_user_id:int}|null
	 */
	public static function details( int $order_id ): ?array {
		if ( isset( self::$readers['order_details'] ) ) {
			$d = call_user_func( self::$readers['order_details'], $order_id );
			return is_array( $d ) ? $d : null;
		}
		if ( ! function_exists( 'wc_get_order' ) ) {
			return null;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return null;
		}
		$paid = method_exists( $order, 'get_date_paid' ) ? $order->get_date_paid() : null;
		$ndck = (string) $order->get_meta( 'ttck_ndck' );
		return array(
			'total'           => (float) $order->get_total(),
			'package'         => (string) $order->get_meta( '_bizcity_plan_package' ),
			'months'          => (int) $order->get_meta( '_bizcity_plan_months' ),
			'floor_vnd'       => (int) $order->get_meta( '_bizcity_plan_floor_vnd' ),
			'floor_version'   => (string) $order->get_meta( '_bizcity_plan_floor_version' ),
			'channel'         => (string) $order->get_meta( '_bizcity_plan_channel' ),
			'created_by'      => (int) $order->get_meta( '_bizcity_plan_created_by' ),
			'paid_ts'         => $paid ? (int) $paid->getTimestamp() : 0,
			'method'          => (string) $order->get_payment_method(),
			'bank_amount_vnd' => (int) $order->get_meta( 'ttck_bank_amount' ),
			'bank_ref'        => $ndck,
			'account_id'      => (string) $order->get_meta( '_bizcity_plan_account_id' ),
			'payer_user_id'   => (int) $order->get_user_id(),
		);
	}

	/* ── plumbing ──────────────────────────────────────────────────── */

	private static function rows( string $sql, array $args ): array {
		if ( isset( self::$readers['rows'] ) ) {
			return (array) call_user_func( self::$readers['rows'], $sql, $args );
		}
		global $wpdb;
		$q = $args ? $wpdb->prepare( $sql, $args ) : $sql; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$r = $wpdb->get_results( $q, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return is_array( $r ) ? $r : array();
	}

	/** @return int|false */
	private static function exec( string $sql, array $args ) {
		if ( isset( self::$readers['exec'] ) ) {
			return call_user_func( self::$readers['exec'], $sql, $args );
		}
		global $wpdb;
		return $wpdb->query( $args ? $wpdb->prepare( $sql, $args ) : $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private static function now(): int {
		return isset( self::$readers['now'] ) ? (int) call_user_func( self::$readers['now'] ) : time();
	}

	private static function fmt( int $ts ): string {
		return gmdate( 'Y-m-d H:i:s', $ts );
	}
}
