<?php
/**
 * `wp bizcity usage-audit` — read-only audit of the ONE ledger along the vertical axis "one API key ⇒ one ledger" (PHASE-0.154 W4, doc 00 §9).
 *
 * Checks (each answers PASS / FAIL / INFO / SKIP, never a silent pass):
 *   1. continuity  — per account, the token rows (kinds of BizCity_Usage_Ingest::KINDS) have no hole in account_seq (count = max − min + 1);
 *   2. key         — how many token rows carry a key_id, how many carry ANOTHER key than --key (one site = one key);
 *   3. copies      — the one-time copies of the old tables (activity_day, plan_sales, account_plans, brain_journey) are done;
 *   4. journey     — every journey subject's hash chain verifies (BizCity_Brain_Journey_Evaluator::verify_chain_detail);
 *   5. days        — rows / billed tokens / turns per day for the last --days days (INFO: to set beside the Hub wallet screen).
 * It never writes, never calls the Hub, prints no contact, token or payload. An automatic site ⇄ Hub comparison per (key, day) needs a Hub
 * route that does not exist yet (GAP W4-H1, doc 10 / 40).
 *
 *   wp bizcity usage-audit [--key=<id>] [--days=30] [--format=table|json]
 *
 * Test seam: $readers['rows'](sql, args): array · $readers['option'](name): string · $readers['verify'](kind, id): array{ok:bool}.
 *
 * // [2026-11-17 02:50 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.154 W4 — new file.
 *
 * @package BizCity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Usage_Audit {

	/** @var array<string,callable> */
	public static $readers = array();

	const COPIES = array(
		'activity_day ⇒ kind day'      => 'bizcity_activity_day_merged_v2',
		'plan_sales ⇒ kind sale'       => 'bizcity_plan_sales_copied_v1',
		'account_plans ⇒ kind plan'    => 'bizcity_account_plans_copied_v1',
		'brain_journey ⇒ kind journey' => 'bizcity_brain_journey_copied_v1',
	);

	public static function init(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
			\WP_CLI::add_command( 'bizcity usage-audit', array( __CLASS__, 'cli' ) );
		}
	}

	/**
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc
	 */
	public static function cli( $args, $assoc ): void {
		$key    = max( 0, (int) ( $assoc['key'] ?? 0 ) );
		$days   = min( 366, max( 1, (int) ( $assoc['days'] ?? 30 ) ) );
		$report = self::run( $key, $days );
		if ( 'json' === ( $assoc['format'] ?? 'table' ) ) {
			\WP_CLI::line( (string) wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
		} else {
			\WP_CLI\Utils\format_items( 'table', $report['checks'], array( 'check', 'status', 'detail' ) );
			if ( $report['days'] ) {
				\WP_CLI\Utils\format_items( 'table', $report['days'], array( 'day', 'rows', 'billed_tokens', 'turns' ) );
			}
		}
		$failed = count( array_filter( $report['checks'], static function ( $c ) { return 'FAIL' === $c['status']; } ) );
		\WP_CLI::line( sprintf( '[usage-audit] key=%d days=%d failed=%d', $key, $days, $failed ) );
		if ( $failed > 0 ) {
			\WP_CLI::halt( 1 );
		}
	}

	/**
	 * @return array{key:int,checks:array<int,array{check:string,status:string,detail:string}>,days:array<int,array<string,mixed>>}
	 */
	public static function run( int $key, int $days ): array {
		$checks = array();
		$led    = BizCity_Usage_Schema::ledger_physical_table();
		$kinds  = BizCity_Usage_Schema::token_kinds_sql();
		$kw     = $key > 0 && BizCity_Usage_Schema::has_key_column() ? ' AND key_id = ' . (int) $key : '';

		// 1. continuity
		$acc  = self::rows( "SELECT account_id, MIN(account_seq) AS mn, MAX(account_seq) AS mx, COUNT(*) AS n FROM {$led} WHERE kind IN ({$kinds}){$kw} GROUP BY account_id", array() );
		$gaps = array();
		foreach ( $acc as $a ) {
			$missing = ( (int) $a['mx'] - (int) $a['mn'] + 1 ) - (int) $a['n'];
			if ( $missing > 0 ) {
				$gaps[] = self::mask( (string) $a['account_id'] ) . ' −' . $missing;
			}
		}
		$checks[] = array(
			'check'  => 'continuity (account_seq without holes)',
			'status' => $acc ? ( $gaps ? 'FAIL' : 'PASS' ) : 'SKIP',
			'detail' => $acc ? ( $gaps ? count( $gaps ) . ' account(s) with holes: ' . implode( ', ', array_slice( $gaps, 0, 5 ) ) : count( $acc ) . ' account(s), no hole' ) : 'no token row' . ( $kw ? ' for this key' : '' ),
		);

		// 2. key traceability
		if ( ! BizCity_Usage_Schema::has_key_column() ) {
			$checks[] = array( 'check' => 'key_id on every row', 'status' => 'SKIP', 'detail' => 'ledger schema below 1.8.0: open one wp-admin page, then rerun' );
		} else {
			$k = self::rows( "SELECT SUM(key_id > 0) AS with_key, SUM(key_id = 0) AS without_key, COUNT(DISTINCT NULLIF(key_id,0)) AS keys_seen FROM {$led} WHERE kind IN ({$kinds})", array() );
			$k = $k[0] ?? array();
			$with = (int) ( $k['with_key'] ?? 0 );
			$seen = (int) ( $k['keys_seen'] ?? 0 );
			$checks[] = array(
				'check'  => 'key_id on token rows',
				'status' => $seen > 1 ? 'FAIL' : 'INFO',
				'detail' => $with . ' with key, ' . (int) ( $k['without_key'] ?? 0 ) . ' without (rows ingested before 1.8.0), ' . $seen . ' distinct key(s)' . ( $seen > 1 ? ' — one site must have ONE key' : '' ),
			);
			if ( $key > 0 ) {
				$o = self::rows( "SELECT COUNT(*) AS n FROM {$led} WHERE key_id > 0 AND key_id <> %d", array( $key ) );
				$n = (int) ( $o[0]['n'] ?? 0 );
				$checks[] = array( 'check' => 'rows of another key', 'status' => $n ? 'FAIL' : 'PASS', 'detail' => $n . ' row(s) carry a key other than ' . $key );
			}
		}

		// 3. one-time copies
		foreach ( self::COPIES as $label => $opt ) {
			$done     = '1' === self::option( $opt );
			$checks[] = array( 'check' => 'copy ' . $label, 'status' => $done ? 'PASS' : 'INFO', 'detail' => $done ? 'done' : 'not done yet (cron; the views still read the old table meanwhile)' );
		}

		// 4. journey chains
		$subjects = self::rows( "SELECT DISTINCT JSON_UNQUOTE(JSON_EXTRACT(payload, '$.subject_kind')) AS k, account_id AS id FROM {$led} WHERE kind = 'journey'", array() );
		$broken   = 0;
		foreach ( $subjects as $s ) {
			$v       = self::verify( (string) $s['k'], (string) $s['id'] );
			$broken += empty( $v['ok'] ) ? 1 : 0;
		}
		$checks[] = array(
			'check'  => 'journey hash chains',
			'status' => $subjects ? ( $broken ? 'FAIL' : 'PASS' ) : 'SKIP',
			'detail' => $subjects ? count( $subjects ) . ' subject(s), ' . $broken . ' broken' : 'no journey row in the ledger yet',
		);

		// 5. per day (INFO)
		$from = gmdate( 'Y-m-d', time() - ( $days - 1 ) * 86400 );
		$rows = self::rows( "SELECT occurred_day AS day, COUNT(*) AS rows, COALESCE(SUM(billed_tokens),0) AS billed_tokens, COUNT(DISTINCT NULLIF(turn_ref,'')) AS turns FROM {$led} WHERE kind IN ({$kinds}){$kw} AND occurred_day >= %s GROUP BY occurred_day ORDER BY occurred_day DESC", array( $from ) );
		$checks[] = array( 'check' => 'site ⇄ Hub per day', 'status' => 'SKIP', 'detail' => 'no Hub route for per-key daily totals yet (GAP W4-H1): compare the day table below with the Hub wallet screen by hand' );

		return array( 'key' => $key, 'checks' => $checks, 'days' => $rows );
	}

	/** 100123456 ⇒ 1001•••456 (account ids never printed whole). */
	public static function mask( string $id ): string {
		return strlen( $id ) > 7 ? substr( $id, 0, 4 ) . '•••' . substr( $id, -3 ) : $id;
	}

	private static function rows( string $sql, array $args ): array {
		if ( isset( self::$readers['rows'] ) ) {
			return (array) call_user_func( self::$readers['rows'], $sql, $args );
		}
		global $wpdb;
		$q = $args ? $wpdb->prepare( $sql, $args ) : $sql; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (array) $wpdb->get_results( $q, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private static function option( string $name ): string {
		if ( isset( self::$readers['option'] ) ) {
			return (string) call_user_func( self::$readers['option'], $name );
		}
		return function_exists( 'get_option' ) ? (string) get_option( $name, '' ) : '';
	}

	private static function verify( string $kind, string $id ): array {
		if ( isset( self::$readers['verify'] ) ) {
			return (array) call_user_func( self::$readers['verify'], $kind, $id );
		}
		return class_exists( 'BizCity_Brain_Journey_Evaluator' ) ? BizCity_Brain_Journey_Evaluator::verify_chain_detail( $kind, $id ) : array( 'ok' => false );
	}
}
