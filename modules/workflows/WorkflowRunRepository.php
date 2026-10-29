<?php
namespace NeuralForms\Workflows;

defined( 'ABSPATH' ) || exit;

/**
 * `neural_forms_workflow_runs` + `neural_forms_workflow_actions`.
 *
 * Run status:    pending → running → done | failed | partial | skipped | cancelled
 *                (waiting = a retryable action is scheduled for later)
 * Action status: pending → running → done | failed | uncertain | skipped
 *
 * Idempotency: (workflow_id, event_id) and (run_id, action_key) are
 * UNIQUE, so duplicate events and duplicate action rows are no-ops at
 * the database. Claims are single UPDATE statements guarded by the
 * current status, so two workers can never both own a run or action.
 */
final class WorkflowRunRepository {

	public static function runs_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'neural_forms_workflow_runs';
	}

	public static function actions_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'neural_forms_workflow_actions';
	}

	/**
	 * Create a run for (workflow, event). Returns the new run id, or 0
	 * when the same event was already recorded (duplicate).
	 */
	public static function create_run( array $def, int $entry_id, string $event_id, string $lineage, array $context ): int {
		global $wpdb;
		$context['definition'] = $def; // snapshot: a later edit never changes a queued run
		$ok = $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (workflow_id, revision, entry_id, trigger_type, event_id, status, lineage, context, created_at) VALUES (%d, %d, %d, %s, %s, %s, %s, %s, %s)',
				self::runs_table(),
				(int) $def['id'],
				(int) $def['revision'],
				$entry_id,
				$def['trigger'],
				substr( $event_id, 0, 80 ),
				'pending',
				$lineage !== '' ? $lineage : null,
				wp_json_encode( $context ),
				current_time( 'mysql', true )
			)
		);
		if ( ! $ok ) {
			return 0; // duplicate (INSERT IGNORE affected 0 rows) or error
		}
		return (int) $wpdb->insert_id;
	}

	public static function has_run( int $workflow_id, int $entry_id, string $trigger ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE workflow_id = %d AND entry_id = %d AND trigger_type = %s', self::runs_table(), $workflow_id, $entry_id, $trigger ) ) > 0;
	}

	/** Atomically take ownership of a pending/waiting run. */
	public static function claim_run( int $run_id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$affected = $wpdb->query( $wpdb->prepare( "UPDATE %i SET status = 'running', started_at = COALESCE(started_at, %s) WHERE id = %d AND status IN ('pending','waiting')", self::runs_table(), current_time( 'mysql', true ), $run_id ) );
		return 1 === (int) $affected;
	}

	public static function finish_run( int $run_id, string $status, string $result = '' ): void {
		global $wpdb;
		$data = array( 'status' => $status, 'result' => $result !== '' ? $result : null );
		$fmt  = array( '%s', '%s' );
		if ( in_array( $status, array( 'done', 'failed', 'partial', 'skipped', 'cancelled' ), true ) ) {
			$data['finished_at'] = current_time( 'mysql', true );
			$fmt[]               = '%s';
		}
		$wpdb->update( self::runs_table(), $data, array( 'id' => $run_id ), $fmt, array( '%d' ) );
	}

	public static function get_run( int $run_id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::runs_table(), $run_id ), ARRAY_A );
		return $row ? self::decode_run( $row ) : null;
	}

	/** @return array[] newest first, each with its actions. */
	public static function list_runs( int $workflow_id, int $limit = 30 ): array {
		global $wpdb;
		$limit = max( 1, min( 200, $limit ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE workflow_id = %d ORDER BY id DESC LIMIT %d', self::runs_table(), $workflow_id, $limit ), ARRAY_A );
		$runs = array_map( array( self::class, 'decode_run' ), (array) $rows );
		if ( $runs ) {
			$ids          = array_map( static function ( $r ) { return (int) $r['id']; }, $runs );
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$acts = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE run_id IN ({$placeholders}) ORDER BY run_id DESC, position ASC", self::actions_table(), ...$ids ), ARRAY_A );
			$by   = array();
			foreach ( (array) $acts as $a ) {
				$by[ (int) $a['run_id'] ][] = self::decode_action( $a );
			}
			foreach ( $runs as &$r ) {
				$r['actions'] = $by[ (int) $r['id'] ] ?? array();
				unset( $r['context'] ); // the definition snapshot is large and not needed by the UI
			}
			unset( $r );
		}
		return $runs;
	}

	public static function count_runs( int $workflow_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS n, MAX(created_at) AS last FROM %i WHERE workflow_id = %d GROUP BY status', self::runs_table(), $workflow_id ), ARRAY_A );
		$out  = array( 'total' => 0, 'by_status' => array(), 'last_run_at' => null );
		foreach ( (array) $rows as $r ) {
			$out['by_status'][ (string) $r['status'] ] = (int) $r['n'];
			$out['total']                             += (int) $r['n'];
			if ( null === $out['last_run_at'] || $r['last'] > $out['last_run_at'] ) {
				$out['last_run_at'] = (string) $r['last'];
			}
		}
		return $out;
	}

	public static function cancel_pending( int $workflow_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$n = $wpdb->query( $wpdb->prepare( "UPDATE %i SET status = 'cancelled', finished_at = %s WHERE workflow_id = %d AND status IN ('pending','waiting')", self::runs_table(), current_time( 'mysql', true ), $workflow_id ) );
		return (int) $n;
	}

	// ── actions ──────────────────────────────────────────────────────

	/** Insert one pending row per action of the definition (no-op for rows that already exist). */
	public static function ensure_actions( int $run_id, array $actions ): void {
		global $wpdb;
		$now = current_time( 'mysql', true );
		foreach ( array_values( $actions ) as $i => $a ) {
			$wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO %i (run_id, action_key, action_type, position, status, attempts, created_at) VALUES (%d, %s, %s, %d, 'pending', 0, %s)",
					self::actions_table(),
					$run_id,
					(string) $a['key'],
					(string) $a['type'],
					$i,
					$now
				)
			);
		}
	}

	/** @return array[] actions of a run in position order. */
	public static function actions_for_run( int $run_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE run_id = %d ORDER BY position ASC', self::actions_table(), $run_id ), ARRAY_A );
		return array_map( array( self::class, 'decode_action' ), (array) $rows );
	}

	public static function get_action( int $action_id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::actions_table(), $action_id ), ARRAY_A );
		return $row ? self::decode_action( $row ) : null;
	}

	/** Atomically take ownership of a pending (or due-for-retry) action. */
	public static function claim_action( int $action_id ): bool {
		global $wpdb;
		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$affected = $wpdb->query( $wpdb->prepare( "UPDATE %i SET status = 'running', attempts = attempts + 1, updated_at = %s WHERE id = %d AND status = 'pending' AND (next_attempt_at IS NULL OR next_attempt_at <= %s)", self::actions_table(), $now, $action_id, $now ) );
		return 1 === (int) $affected;
	}

	public static function finish_action( int $action_id, string $status, string $result, ?string $external_ref = null, ?string $next_attempt_at = null ): void {
		global $wpdb;
		$wpdb->update(
			self::actions_table(),
			array(
				'status'          => $status,
				'result'          => $result !== '' ? mb_substr( $result, 0, 2000 ) : null,
				'external_ref'    => $external_ref,
				'next_attempt_at' => $next_attempt_at,
				'updated_at'      => current_time( 'mysql', true ),
			),
			array( 'id' => $action_id ),
			array( '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/** Manual retry: put a failed/uncertain action back to pending (attempts reset) and reopen the run. */
	public static function reopen_action( int $action_id ): bool {
		global $wpdb;
		$action = self::get_action( $action_id );
		if ( ! $action ) {
			return false;
		}
		$retryable = in_array( $action['status'], array( 'failed', 'uncertain', 'skipped' ), true )
			|| ( $action['status'] === 'pending' && ! empty( $action['next_attempt_at'] ) ); // "retry now" on a waiting action
		if ( ! $retryable ) {
			return false;
		}
		$wpdb->update( self::actions_table(), array( 'status' => 'pending', 'attempts' => 0, 'next_attempt_at' => null, 'result' => null, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $action_id ), array( '%s', '%d', '%s', '%s', '%s' ), array( '%d' ) );
		$wpdb->update( self::runs_table(), array( 'status' => 'waiting', 'finished_at' => null ), array( 'id' => (int) $action['run_id'] ), array( '%s', '%s' ), array( '%d' ) );
		return true;
	}

	// ── decoders ─────────────────────────────────────────────────────

	public static function decode_run( array $row ): array {
		$row['id']          = (int) $row['id'];
		$row['workflow_id'] = (int) $row['workflow_id'];
		$row['revision']    = (int) $row['revision'];
		$row['entry_id']    = isset( $row['entry_id'] ) ? (int) $row['entry_id'] : 0;
		$row['lineage']     = (string) ( $row['lineage'] ?? '' );
		$ctx                = json_decode( (string) ( $row['context'] ?? '' ), true );
		$row['context']     = is_array( $ctx ) ? $ctx : array();
		return $row;
	}

	public static function decode_action( array $row ): array {
		$row['id']       = (int) $row['id'];
		$row['run_id']   = (int) $row['run_id'];
		$row['position'] = (int) $row['position'];
		$row['attempts'] = (int) $row['attempts'];
		return $row;
	}
}
