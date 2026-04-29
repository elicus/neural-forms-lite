<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
//
// Direct $wpdb call against a wp_neural_forms_* table; admin-only path,
// fresh-per-request semantics, no shared cache key worth invalidating.
namespace NeuralForms\AI;

use NeuralForms\Core\Config;
use NeuralForms\Core\Logger;
use NeuralForms\Plan\PlanManager;

defined( 'ABSPATH' ) || exit;

/**
 * Listens for the neural_forms_queue_ai_processing action fired by SubmitEndpoint
 * after every successful entry save.
 *
 * Responsibilities:
 *  1. Guard: respect ai_enabled, cloud_ai_enabled, cloud_api_key, ai_auto_process settings.
 *  2. Insert a job row into neural_forms_queue.
 *  3. Mark the entry ai_status as 'queued'.
 *  4. Schedule an immediate single-event cron run (+10 s) so the analysis
 *     arrives quickly without waiting for the 5-minute recurring fallback.
 *
 * Every failure path logs and updates entry status - nothing is silent.
 */
class QueueListener {

	public static function boot(): void {
		add_action( 'neural_forms_queue_ai_processing', [ self::class, 'handle' ], 10, 2 );
	}

	/**
	 * @param int $entry_id  ID of the newly created entry.
	 * @param int $form_id   ID of the parent form.
	 */
	public static function handle( int $entry_id, int $form_id ): void {
		// Master gates - without a configured Cloud connection nothing can run.
		if ( ! Config::get( 'ai_enabled'       ) ) return;
		if ( ! Config::get( 'cloud_ai_enabled'  ) ) return;
		if ( ! Config::get( 'cloud_api_key'     ) ) return;

		// Either trigger is enough to queue: auto-processing for the full
		// summary/reply/category output, OR spam detection on its own. Spam
		// classification is part of the same analyze() response, so it rides
		// along with the auto-process pipeline.
		$auto_process = (bool) Config::get( 'ai_auto_process' );
		$spam_enabled = (bool) Config::get( 'ai_spam_enabled' );
		if ( ! $auto_process && ! $spam_enabled ) return;

		// Plan gate: every analyze_entry job calls the Cloud analyze route.
		if ( ! PlanManager::can( 'ai_analyze' ) ) return;

		// When only auto-process is the trigger, require its specific cap.
		// Spam-only triggers need the spam classification cap instead.
		if ( $auto_process && ! PlanManager::can( 'ai_auto_process' ) ) return;
		if ( ! $auto_process && $spam_enabled && ! PlanManager::can( 'ai_spam_classify' ) ) return;

		global $wpdb;

		$inserted = $wpdb->insert(
			$wpdb->prefix . 'neural_forms_queue',
			[
				'job_type'     => 'analyze_entry',
				'payload'      => wp_json_encode( [
					'entry_id' => $entry_id,
					'form_id'  => $form_id,
				] ),
				'status'       => 'pending',
				'attempts'     => 0,
				'scheduled_at' => current_time( 'mysql', true ),
			],
			[ '%s', '%s', '%s', '%d', '%s' ]
		);

		if ( ! $inserted ) {
			$db_error = $wpdb->last_error ?: 'Unknown database error';

			Logger::both(
				'ai_queue_insert_failed',
				'error',
				sprintf( 'Failed to insert AI job for entry %d: %s', $entry_id, $db_error ),
				[ 'form_id' => $form_id, 'db_error' => $db_error ],
				$entry_id,
				'ai_queue'
			);

			AIRepository::update_status( $entry_id, 'failed', 'AI job could not be queued: ' . $db_error );
			return;
		}

		// Update entry status so the UI can show "Analyzing..." immediately.
		AIRepository::update_status( $entry_id, 'queued' );

		// Fire an immediate single-event run so the user doesn't wait for
		// the 5-minute recurring cron. The recurring cron is the safety net.
		wp_schedule_single_event( time() + 10, AIQueueProcessor::CRON_HOOK );
	}
}
