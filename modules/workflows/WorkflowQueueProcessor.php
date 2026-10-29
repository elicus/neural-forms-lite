<?php
namespace NeuralForms\Workflows;

use NeuralForms\AI\AIQueueProcessor;
use NeuralForms\Core\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Drains `workflow` jobs from neural_forms_queue. Hooked on the same
 * cron hook as the AI processor but as its own callback, so workflows
 * run even when AI is switched off (AIQueueProcessor::run() bails
 * early in that case and never touches job_type = 'workflow').
 */
final class WorkflowQueueProcessor {

	const BATCH_SIZE   = 10;
	const MAX_ATTEMPTS = 3;

	public static function boot(): void {
		add_action( AIQueueProcessor::CRON_HOOK, array( self::class, 'run' ), 20 );
	}

	public static function run(): void {
		update_option( 'neural_forms_queue_last_run', current_time( 'mysql', true ), false );

		global $wpdb;
		$table = $wpdb->prefix . 'neural_forms_queue';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$jobs = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i
				 WHERE job_type = %s
				   AND scheduled_at <= UTC_TIMESTAMP()
				   AND (
				       status = 'pending'
				       OR (status = 'processing' AND scheduled_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE))
				   )
				   AND attempts < %d
				 ORDER BY scheduled_at ASC
				 LIMIT %d",
				$table,
				WorkflowEngine::JOB_TYPE,
				self::MAX_ATTEMPTS,
				self::BATCH_SIZE
			),
			ARRAY_A
		);
		if ( ! $jobs ) {
			return;
		}
		foreach ( $jobs as $job ) {
			// Claim the job row first (same pattern as the AI processor).
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$claimed = $wpdb->query( $wpdb->prepare( "UPDATE %i SET status = 'processing', attempts = attempts + 1 WHERE id = %d AND status IN ('pending','processing')", $table, (int) $job['id'] ) );
			if ( 1 !== (int) $claimed ) {
				continue;
			}
			$payload = json_decode( (string) $job['payload'], true );
			$run_id  = (int) ( $payload['run_id'] ?? 0 );
			try {
				$outcome = $run_id > 0 ? WorkflowRunner::process_run( $run_id ) : 'invalid';
				$wpdb->update( $table, array( 'status' => 'completed', 'processed_at' => current_time( 'mysql', true ), 'error' => $outcome === 'done' ? null : $outcome ), array( 'id' => (int) $job['id'] ), array( '%s', '%s', '%s' ), array( '%d' ) );
			} catch ( \Throwable $e ) {
				Logger::file( 'workflows', 'error', sprintf( 'Run %d threw: %s', $run_id, $e->getMessage() ) );
				$wpdb->update( $table, array( 'status' => 'failed', 'processed_at' => current_time( 'mysql', true ), 'error' => mb_substr( $e->getMessage(), 0, 500 ) ), array( 'id' => (int) $job['id'] ), array( '%s', '%s', '%s' ), array( '%d' ) );
			}
		}
	}
}
