<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
//
// Direct $wpdb calls against wp_neural_forms_* tables only. Object caching
// is skipped intentionally - these reads back the admin UI which expects
// fresh data after every status / reply / AI mutation.
namespace NeuralForms\AI;

use NeuralForms\Core\Capabilities;
use NeuralForms\Core\Config;
use NeuralForms\Core\Logger;
use NeuralForms\Plan\PlanManager;
use NeuralForms\Storage\EntryRepository;
use NeuralForms\Storage\AIResponseRepository;
use NeuralForms\Forms\FormRepository;
use NeuralForms\AI\Contracts\AIAnalysisProviderInterface;
use NeuralForms\AI\Exceptions\CloudPlanException;

defined( 'ABSPATH' ) || exit;

/**
 * WP-Cron worker that dequeues and processes AI analysis jobs.
 *
 * Scheduling model:
 *   * QueueListener schedules a single-event run 10 seconds after each
 *     submission - provides near-real-time analysis.
 *   * A recurring 5-minute event runs as the guaranteed fallback for any
 *     jobs the immediate run missed (server restart, failed cron trigger, etc.).
 *
 * Safety:
 *   * Processes at most BATCH_SIZE jobs per run to avoid PHP timeouts.
 *   * Retries failed jobs up to MAX_ATTEMPTS times.
 *   * All errors are written to neural_forms_logs so they are visible in the admin.
 */
class AIQueueProcessor {

	const CRON_HOOK    = 'neural_forms_process_ai_queue';
	const MAX_ATTEMPTS = 3;
	const BATCH_SIZE   = 5;

	// Boot / schedule

	public static function boot(): void {
		add_action( self::CRON_HOOK, [ self::class, 'run' ] );
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'five_minutes', self::CRON_HOOK );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Filter callback for 'cron_schedules'.
	 * Adds the 'five_minutes' interval if it is not already registered.
	 */
	public static function add_cron_interval( array $schedules ): array {
		if ( ! isset( $schedules['five_minutes'] ) ) {
			// Plain string - `cron_schedules` fires on `wp_schedule_event` /
			// `wp_get_schedules`, which can run before `init` (e.g. during
			// activation, scheduled-event reads). Calling `__()` against the
			// `neural-forms` domain pre-init triggers WP 6.7's
			// `_load_textdomain_just_in_time` notice. The label is only seen on
			// Tools '->-> Cron Events, so translating it is not worth the early-load.
			$schedules['five_minutes'] = [
				'interval' => 300,
				'display'  => 'Every 5 minutes',
			];
		}
		return $schedules;
	}

	// Cron worker

	public static function run(): void {
		// Cron fires unconditionally - these guards stop us from rotating
		// queue rows into "running" state (and burning a provider call slot)
		// when the user has either turned AI off or hasn't connected Cloud
		// yet. Logging at info so operators can see WHY a backlog isn't
		// draining without flipping on debug logs.
		if ( ! Config::get( 'ai_enabled'      ) ) {
			Logger::file( 'ai_queue', 'info', 'Queue skipped: ai_enabled is false.' );
			return;
		}
		if ( ! Config::get( 'cloud_ai_enabled' ) ) {
			Logger::file( 'ai_queue', 'info', 'Queue skipped: cloud_ai_enabled is false.' );
			return;
		}
		// Empty key means the user disconnected Cloud or never finished
		// setup. We deliberately don't fail jobs here - leaving them queued
		// lets a later key entry pick up the backlog with no manual retry.
		if ( Config::get( 'cloud_api_key' ) === '' ) {
			Logger::file( 'ai_queue', 'info', 'Queue skipped: cloud_api_key is empty.' );
			return;
		}

		try {
			$provider = AiManager::resolve();
		} catch ( \RuntimeException $e ) {
			Logger::file( 'ai_queue', 'error', 'Queue skipped: provider resolve failed - ' . $e->getMessage() );
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'neural_forms_queue';

		// Also recover jobs stuck in 'processing' for over 2 minutes (orphaned
		// by a prior PHP timeout or uncaught error before the \Throwable fix).
		$jobs = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i
				 WHERE job_type IN ('analyze_entry', 'regenerate_reply')
				   AND (
				       status IN ('pending', 'failed')
				       OR (status = 'processing' AND scheduled_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 MINUTE))
				   )
				   AND attempts < %d
				 ORDER BY scheduled_at ASC
				 LIMIT %d",
				$table,
				self::MAX_ATTEMPTS,
				self::BATCH_SIZE
			),
			ARRAY_A
		);

		if ( empty( $jobs ) ) {
			Logger::file( 'ai_queue', 'info', 'Queue run: no pending jobs.' );
			return;
		}

		Logger::file( 'ai_queue', 'info', sprintf( 'Queue run: processing %d job(s).', count( $jobs ) ) );

		foreach ( $jobs as $job ) {
			self::process_job( $job, $provider );
		}
	}

	// Job processing

	private static function process_job( array $job, AIAnalysisProviderInterface $provider ): void {
		// Dispatch to the appropriate handler based on job type.
		if ( $job['job_type'] === 'regenerate_reply' ) {
			self::process_regenerate_reply( $job, $provider );
			return;
		}

		// Default: full analysis (analyze_entry).
		global $wpdb;

		$table    = $wpdb->prefix . 'neural_forms_queue';
		$payload  = json_decode( $job['payload'], true );
		$entry_id = isset( $payload['entry_id'] ) ? (int) $payload['entry_id'] : 0;

		if ( $entry_id === 0 ) {
			self::finish_job( (int) $job['id'], 'failed', 'Missing entry_id in payload.' );
			return;
		}

		// Claim the job: mark in-flight so parallel cron runs skip it.
		$wpdb->update(
			$table,
			[
				'status'   => 'processing',
				'attempts' => (int) $job['attempts'] + 1,
			],
			[ 'id' => $job['id'] ],
			[ '%s', '%d' ],
			[ '%d' ]
		);
		AIRepository::update_status( $entry_id, 'processing' );

		try {
			$entry = EntryRepository::get( $entry_id );

			if ( ! $entry ) {
				throw new \RuntimeException( "Entry {$entry_id} not found." );
			}

			$form_id = (int) ( $payload['form_id'] ?? $entry['form_id'] ?? 0 );
			$form    = $form_id > 0 ? ( FormRepository::find( $form_id ) ?? [] ) : [];

			// Provider builds prompts (or calls the cloud) and returns a
			// normalized array ready to pass directly into AIRepository.
			Logger::file( 'ai_queue', 'info', "Job {$job['id']}: calling provider->analyze() for entry {$entry_id}." );
			$result = $provider->analyze( $entry, $form );
			Logger::file( 'ai_queue', 'info', "Job {$job['id']}: analyze returned, saving.", [ 'category' => $result['category'] ?? '', 'priority' => $result['priority'] ?? '' ] );

			// Queued jobs only originate from new-submission auto-processing
			// (admin-initiated re-analyze now runs inline via REST, not the
			// queue). When ai_auto_process is off the only reason this job
			// ran is spam detection, so we persist spam fields only and
			// leave the analysis card blank.
			if ( ! Config::get( 'ai_auto_process' ) ) {
				AIRepository::save_spam_only( $entry_id, $result );
			} else {
				AIRepository::save_analysis( $entry_id, $result );
				// Auto-process path is always the first analyze for a given
				// entry - record the draft and pin it as the selected response
				// so the carousel opens with this one visible by default.
				self::record_ai_draft( $entry_id, $entry, $result, 'analyze' );
			}
			self::maybe_apply_spam( $entry_id, $result );
			self::maybe_send_deferred_notifications( $entry_id, $result, $form_id );
			self::finish_job( (int) $job['id'], 'complete' );
			Logger::file( 'ai_queue', 'info', "Job {$job['id']}: complete for entry {$entry_id}." );

		} catch ( CloudPlanException $e ) {
			// Plan error: no point retrying - the account can't run this job.
			self::handle_plan_exception( $e, 'ai_analyze' );
			self::finish_job( (int) $job['id'], 'failed', $e->getMessage() );
			AIRepository::update_status( $entry_id, 'failed', $e->getMessage() );
			self::maybe_send_deferred_notifications_on_failure( $entry_id, $payload );
			self::log_error( $entry_id, (int) $job['id'], (int) $job['attempts'] + 1, $e->getMessage() );
		} catch ( \Throwable $e ) {
			$attempts = (int) $job['attempts'] + 1;
			$is_final = $attempts >= self::MAX_ATTEMPTS;

			$next_status = $is_final ? 'failed' : 'pending';
			self::finish_job( (int) $job['id'], $next_status, $e->getMessage() );

			// If retries remain, keep the entry as 'queued' so the UI still
			// shows "Analyzing...". Mark 'failed' only on the final attempt,
			// persisting the error reason so the admin can see why.
			if ( $is_final ) {
				AIRepository::update_status( $entry_id, 'failed', $e->getMessage() );

				// If notifications were deferred waiting for spam check,
				// send them now - the admin should still know about this entry
				// even though AI failed.
				self::maybe_send_deferred_notifications_on_failure( $entry_id, $payload );
			} else {
				AIRepository::update_status( $entry_id, 'queued' );
			}

			self::log_error( $entry_id, (int) $job['id'], $attempts, $e->getMessage() );
		}
	}

	/**
	 * Process a regenerate_reply job.
	 *
	 * Only updates ai_reply_draft - never touches summary/category/priority/spam.
	 * On failure (including final failure after all retries) the entry ai_status
	 * is restored to 'complete' so a prior good analysis is never degraded to
	 * the "AI analysis failed" card state.
	 */
	private static function process_regenerate_reply( array $job, AIAnalysisProviderInterface $provider ): void {
		global $wpdb;

		$table    = $wpdb->prefix . 'neural_forms_queue';
		$payload  = json_decode( $job['payload'], true );
		$entry_id = isset( $payload['entry_id'] ) ? (int) $payload['entry_id'] : 0;

		if ( $entry_id === 0 ) {
			self::finish_job( (int) $job['id'], 'failed', 'Missing entry_id in payload.' );
			return;
		}

		// Claim the job.
		$wpdb->update(
			$table,
			[
				'status'   => 'processing',
				'attempts' => (int) $job['attempts'] + 1,
			],
			[ 'id' => $job['id'] ],
			[ '%s', '%d' ],
			[ '%d' ]
		);
		AIRepository::update_status( $entry_id, 'processing' );

		try {
			$entry = EntryRepository::get( $entry_id );

			if ( ! $entry ) {
				throw new \RuntimeException( "Entry {$entry_id} not found." );
			}

			$form_id = (int) ( $payload['form_id'] ?? $entry['form_id'] ?? 0 );
			$form    = $form_id > 0 ? ( FormRepository::find( $form_id ) ?? [] ) : [];

			// Reply-only - never overwrites summary/category/priority/spam.
			$result = $provider->regenerate_reply( $entry, $form );

			$tier_used = isset( $result['tier_used'] ) ? (string) $result['tier_used'] : null;
			AIRepository::update_reply_draft( $entry_id, (string) $result['reply_draft'], $tier_used );
			self::record_ai_draft( $entry_id, $entry, $result, AIResponseRepository::KIND_REGENERATE );
			self::finish_job( (int) $job['id'], 'complete' );
			Logger::file( 'ai_queue', 'info', "Job {$job['id']}: regenerate_reply complete for entry {$entry_id}." );

		} catch ( CloudPlanException $e ) {
			self::handle_plan_exception( $e, 'ai_reply_regenerate' );
			self::finish_job( (int) $job['id'], 'failed', $e->getMessage() );
			// Never degrade a prior full analysis into the "failed" card state
			// just because a reply regeneration was plan-blocked.
			AIRepository::update_status( $entry_id, 'complete' );
			self::log_error( $entry_id, (int) $job['id'], (int) $job['attempts'] + 1, $e->getMessage() );
		} catch ( \Throwable $e ) {
			$attempts = (int) $job['attempts'] + 1;
			$is_final = $attempts >= self::MAX_ATTEMPTS;

			$next_job_status = $is_final ? 'failed' : 'pending';
			self::finish_job( (int) $job['id'], $next_job_status, $e->getMessage() );

			// Always restore to 'complete' - never degrade a prior full analysis
			// into the "AI analysis failed" state due to a reply-only failure.
			AIRepository::update_status( $entry_id, 'complete' );

			self::log_error( $entry_id, (int) $job['id'], $attempts, $e->getMessage() );
		}
	}

	/**
	 * Update the local plan cache when the Cloud reports a plan error from
	 * inside a queue job. The admin UI picks up the new lock state on the
	 * next GET /settings / entry open.
	 *
	 * On quota_exceeded we also purge every other pending/failed AI job
	 * because the whole account is gated - retrying them just burns cron
	 * runs against a 403. Entries revert to 'failed' with a clear reason.
	 */
	private static function handle_plan_exception( CloudPlanException $e, string $fallback_feature ): void {
		$feature = $e->get_feature() !== '' ? $e->get_feature() : $fallback_feature;

		if ( $e->get_error_code() === 'feature_locked' && $feature !== '' ) {
			PlanManager::mark_feature_locked( $feature );
		}
		if ( $e->get_error_code() === 'quota_exceeded' ) {
			PlanManager::mark_quota_exceeded( $e->get_quota() );
			self::purge_pending_ai_jobs( $e->getMessage() );
		}
	}

	/**
	 * Mark every remaining analyze_entry / regenerate_reply job as failed
	 * and flip their entries to the 'failed' status so the admin sees a
	 * consistent "quota exceeded" state across the inbox instead of jobs
	 * being retried one by one.
	 */
	private static function purge_pending_ai_jobs( string $reason ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'neural_forms_queue';

		$jobs = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, payload FROM %i
				 WHERE job_type IN ('analyze_entry', 'regenerate_reply')
				   AND status IN ('pending', 'failed', 'processing')",
				$table
			),
			ARRAY_A
		);

		if ( empty( $jobs ) ) return;

		$error_msg = 'Cloud quota exceeded - remaining jobs cleared. ' . $reason;

		foreach ( $jobs as $job ) {
			$wpdb->update(
				$table,
				[
					'status'       => 'failed',
					'processed_at' => current_time( 'mysql', true ),
					'error'        => $error_msg,
				],
				[ 'id' => (int) $job['id'] ],
				[ '%s', '%s', '%s' ],
				[ '%d' ]
			);

			$payload  = json_decode( (string) $job['payload'], true );
			$entry_id = is_array( $payload ) ? (int) ( $payload['entry_id'] ?? 0 ) : 0;
			if ( $entry_id > 0 ) {
				AIRepository::update_status( $entry_id, 'failed', $error_msg );
			}
		}

		Logger::file(
			'ai_queue',
			'warn',
			sprintf( 'Quota exceeded: cleared %d pending AI job(s).', count( $jobs ) )
		);
	}

	// Helpers

	/**
	 * When a job fails permanently and notifications were deferred, send them
	 * anyway so the admin doesn't miss the submission entirely.
	 */
	private static function maybe_send_deferred_notifications_on_failure( int $entry_id, array $payload ): void {
		if ( ! Config::get( 'ai_enabled' )                   ) return;
		if ( ! Config::get( 'ai_spam_enabled' )               ) return;
		if ( ! Config::get( 'email_skip_spam_notifications' ) ) return;

		$form_id = (int) ( $payload['form_id'] ?? 0 );
		if ( $form_id < 1 ) return;

		$form = FormRepository::find( $form_id );
		if ( ! $form ) return;

		do_action( 'neural_forms_send_notifications', $entry_id, $form_id, $form );
	}

	/**
	 * Fire the neural_forms_send_notifications hook when it was deferred at submission
	 * time because email_skip_spam_notifications is enabled.
	 *
	 * Only runs when all four deferral flags are active (same condition that
	 * suppressed the hook in SubmitEndpoint). Skips silently for spam entries;
	 * fires normally for everything else.
	 *
	 * Must only be called from the analyze_entry path - not from
	 * process_regenerate_reply - to avoid double-sending.
	 */
	private static function maybe_send_deferred_notifications(
		int   $entry_id,
		array $result,
		int   $form_id
	): void {
		if ( ! Config::get( 'ai_enabled' )                      ) return;
		if ( ! Config::get( 'ai_spam_enabled' )                  ) return;
		if ( ! Config::get( 'email_skip_spam_notifications' )    ) return;

		// The whole point: suppress notifications for spam entries.
		if ( $result['is_spam'] ?? false ) {
			return;
		}

		// Not spam - fire the notification hook now.
		if ( $form_id < 1 ) return;
		$form = FormRepository::find( $form_id );
		if ( ! $form ) return;

		do_action( 'neural_forms_send_notifications', $entry_id, $form_id, $form );
	}

	/**
	 * Optionally auto-move an entry to Spam based on the AI spam result.
	 *
	 * Two-layer guard:
	 *   1. Plugin capability (Pro-installed?) - Lite defaults locked.
	 *   2. User opt-in setting (ai_spam_enabled).
	 *
	 * The capability check runs first so an install without Pro never
	 * acts on spam classifications even if ai_spam_enabled was toggled
	 * on under a previous Pro session.
	 *
	 * In 'suggest' mode: spam fields are stored but status is not changed.
	 * In 'auto' mode:    high-confidence spam is moved to the Spam folder
	 *                    and ai_auto_spam_applied is set to 1.
	 */
	private static function maybe_apply_spam( int $entry_id, array $result ): void {
		if ( ! Capabilities::can( Capabilities::AI_SPAM_DETECT ) ) {
			return;
		}
		if ( ! Config::get( 'ai_spam_enabled' ) ) {
			return;
		}

		$is_spam    = (bool) ( $result['is_spam']         ?? false );
		$confidence = (int)  ( $result['spam_confidence'] ?? 0 );
		$mode       = (string) Config::get( 'ai_spam_mode' );
		$threshold  = (int) Config::get( 'ai_spam_threshold' );

		// Default policy: Lite auto-moves only in 'auto' mode above threshold.
		// Pro's SpamPolicy module replaces this via the filter below to add
		// richer rules (learning, per-form overrides, etc.) without forking
		// the processor.
		$default_decision = [
			'action'     => ( $mode === 'auto' && $is_spam && $confidence >= $threshold )
								? 'mark_spam'
								: 'skip',
			'reason'     => (string) ( $result['spam_reason'] ?? '' ),
			'confidence' => $confidence,
		];

		/**
		 * Filter the spam decision before it is applied.
		 *
		 * @param array $decision  [ 'action' => 'skip'|'mark_spam', 'reason' => string, 'confidence' => int ]
		 * @param array $result    The full analysis response from the AI provider.
		 * @param int   $entry_id  The entry being evaluated.
		 * @param array $context   [ 'mode' => string, 'threshold' => int ] - current settings.
		 */
		$decision = apply_filters(
			'neural_forms_spam_decision',
			$default_decision,
			$result,
			$entry_id,
			[ 'mode' => $mode, 'threshold' => $threshold ]
		);

		if ( ! is_array( $decision ) || ( $decision['action'] ?? 'skip' ) !== 'mark_spam' ) {
			return;
		}

		EntryRepository::update( $entry_id, [
			'status'              => 'spam',
			'ai_auto_spam_applied' => true,
		] );

		Logger::log(
			'ai_auto_spam',
			'info',
			"AI auto-spam applied: entry {$entry_id}, confidence {$confidence}%",
			[
				'confidence' => $confidence,
				'reason'     => (string) ( $decision['reason'] ?? $result['spam_reason'] ?? '' ),
				'threshold'  => $threshold,
			],
			$entry_id
		);
	}

	/**
	 * Persist an AI result as a draft in the carousel history.
	 *
	 * Rules:
	 *   - The very first analyze (kind=analyze) auto-finalizes. It's the
	 *     entry's canonical reply until the merchant explicitly picks
	 *     another draft from the carousel.
	 *   - reanalyze / regenerate / polish never auto-finalize - merchant
	 *     chooses via "Use this reply".
	 *   - Skipped silently when the result is flagged spam. Spam entries
	 *     don't show the carousel UI, so there's no reason to grow history.
	 *
	 * $entry is the pre-write row (used to check prior selected-draft state);
	 * callers pass it through so we don't re-query AIRepository on the hot path.
	 */
	private static function record_ai_draft( int $entry_id, array $entry, array $result, string $kind ): void {
		if ( ! empty( $result['is_spam'] ) ) {
			return;
		}

		$data = $result;

		// Auto-finalize on the very first analyze only. "First" = the entry
		// had no selected draft before this call. Everything else remains a
		// non-finalized draft in the carousel until picked explicitly.
		$auto_finalize = ( $kind === AIResponseRepository::KIND_ANALYZE )
			&& empty( $entry['ai_selected_response_id'] );
		if ( $auto_finalize ) {
			$data['finalized'] = true;
		}

		$id = AIResponseRepository::insert( $entry_id, $kind, $data );
		if ( $id > 0 && $auto_finalize ) {
			AIRepository::set_selected_response( $entry_id, $id );
		}
	}

	private static function finish_job( int $job_id, string $status, string $error = '' ): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'neural_forms_queue',
			[
				'status'       => $status,
				'processed_at' => current_time( 'mysql', true ),
				'error'        => $error !== '' ? $error : '',
			],
			[ 'id' => $job_id ],
			[ '%s', '%s', '%s' ],
			[ '%d' ]
		);
	}

	private static function log_error( int $entry_id, int $job_id, int $attempt, string $message ): void {
		Logger::both(
			'ai_processing_error',
			'error',
			$message,
			[ 'job_id' => $job_id, 'attempt' => $attempt ],
			$entry_id,
			'ai_queue'
		);
	}

	// Inline execution (used by admin-initiated REST calls)

	/**
	 * Run a full analysis synchronously and return the refreshed AI row.
	 *
	 * Used by the Re-analyze REST route so the admin gets the result in the
	 * same HTTP response - no queue, no cron, no polling. Always writes the
	 * full analysis regardless of ai_auto_process because this path is only
	 * reached through an explicit admin click.
	 *
	 * On CloudPlanException the plan cache is updated before rethrowing so
	 * the UI reconciles lock state the same way it does from the queue path.
	 *
	 * @throws CloudPlanException  Plan-level error from Cloud (caller maps to 402/403).
	 * @throws \Throwable          Any other failure (caller maps to 500/503).
	 */
	public static function run_inline_analyze( int $entry_id, int $form_id ): array {
		AIRepository::update_status( $entry_id, 'processing' );

		try {
			$provider = AiManager::resolve();
			$entry    = EntryRepository::get( $entry_id );
			if ( ! $entry ) {
				throw new \RuntimeException( "Entry {$entry_id} not found." );
			}
			$form = $form_id > 0 ? ( FormRepository::find( $form_id ) ?? [] ) : [];

			Logger::file( 'ai_queue', 'info', "Inline analyze: entry {$entry_id}." );
			$result = $provider->analyze( $entry, $form );

			AIRepository::save_analysis( $entry_id, $result );

			// Inline analyze covers both the very first analysis (admin clicks
			// "Analyze" on an unanalyzed entry) and all reanalyze clicks. The
			// prior selected-draft pointer tells the two apart: null = first.
			$kind = empty( $entry['ai_selected_response_id'] )
				? AIResponseRepository::KIND_ANALYZE
				: AIResponseRepository::KIND_REANALYZE;
			self::record_ai_draft( $entry_id, $entry, $result, $kind );

			self::maybe_apply_spam( $entry_id, $result );
			self::maybe_send_deferred_notifications( $entry_id, $result, $form_id );

			Logger::file( 'ai_queue', 'info', "Inline analyze: complete for entry {$entry_id}." );

			return AIRepository::get_analysis( $entry_id ) ?? [];
		} catch ( CloudPlanException $e ) {
			self::handle_plan_exception( $e, 'ai_analyze' );
			AIRepository::update_status( $entry_id, 'failed', $e->getMessage() );
			self::maybe_send_deferred_notifications_on_failure( $entry_id, [ 'form_id' => $form_id ] );
			throw $e;
		} catch ( \Throwable $e ) {
			AIRepository::update_status( $entry_id, 'failed', $e->getMessage() );
			self::maybe_send_deferred_notifications_on_failure( $entry_id, [ 'form_id' => $form_id ] );
			Logger::both( 'ai_inline_analyze_failed', 'error', $e->getMessage(), [], $entry_id, 'ai_queue' );
			throw $e;
		}
	}

	/**
	 * Run a reply-only regeneration synchronously and return the new draft.
	 * Mirrors run_inline_analyze but never touches summary/category/priority/spam.
	 * On failure the prior complete state is restored so a reply retry never
	 * degrades an existing good analysis into the "AI failed" card.
	 *
	 * @throws CloudPlanException
	 * @throws \Throwable
	 */
	public static function run_inline_regenerate_reply( int $entry_id, int $form_id ): string {
		AIRepository::update_status( $entry_id, 'processing' );

		try {
			$provider = AiManager::resolve();
			$entry    = EntryRepository::get( $entry_id );
			if ( ! $entry ) {
				throw new \RuntimeException( "Entry {$entry_id} not found." );
			}
			$form = $form_id > 0 ? ( FormRepository::find( $form_id ) ?? [] ) : [];

			Logger::file( 'ai_queue', 'info', "Inline regenerate_reply: entry {$entry_id}." );
			$result = $provider->regenerate_reply( $entry, $form );
			$reply  = (string) ( $result['reply_draft'] ?? '' );
			$tier_used = isset( $result['tier_used'] ) ? (string) $result['tier_used'] : null;

			AIRepository::update_reply_draft( $entry_id, $reply, $tier_used );
			self::record_ai_draft( $entry_id, $entry, $result, AIResponseRepository::KIND_REGENERATE );
			Logger::file( 'ai_queue', 'info', "Inline regenerate_reply: complete for entry {$entry_id}." );

			return $reply;
		} catch ( CloudPlanException $e ) {
			self::handle_plan_exception( $e, 'ai_reply_regenerate' );
			AIRepository::update_status( $entry_id, 'complete' );
			throw $e;
		} catch ( \Throwable $e ) {
			AIRepository::update_status( $entry_id, 'complete' );
			Logger::both( 'ai_inline_regenerate_failed', 'error', $e->getMessage(), [], $entry_id, 'ai_queue' );
			throw $e;
		}
	}

}
