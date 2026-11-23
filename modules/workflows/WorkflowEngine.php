<?php
namespace NeuralForms\Workflows;

use NeuralForms\AI\AIQueueProcessor;
use NeuralForms\Core\Capabilities;
use NeuralForms\Core\Logger;
use NeuralForms\Storage\EntryActivity;
use NeuralForms\Storage\EntryRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Turns plugin events into workflow runs (1.2.0).
 *
 *   neural_forms_entry_created              → submission_received
 *   neural_forms_entry_analysis_completed   → analysis_completed
 *   neural_forms_lead_stage_changed         → stage_changed
 *   neural_forms_follow_up_sweep (hourly)   → follow_up_overdue
 *
 * Each (workflow, event) becomes ONE run row (unique key) plus one
 * `workflow` job in neural_forms_queue; WorkflowQueueProcessor drains
 * the jobs on the existing AI-queue cron hook, so there is one queue
 * and one health story. Everything here runs with AI disabled and with
 * zero credits — only the prepare_ai_draft action needs the cloud.
 *
 * The engine is active only when the `workflows` capability is
 * unlocked (Pro). Lite installs keep the tables but never dispatch.
 */
final class WorkflowEngine {

	const SWEEP_HOOK = 'neural_forms_follow_up_sweep';
	const JOB_TYPE   = 'workflow';

	public static function boot(): void {
		add_action( 'neural_forms_entry_created', array( self::class, 'on_entry_created' ), 50, 3 );
		add_action( 'neural_forms_entry_analysis_completed', array( self::class, 'on_analysis_completed' ), 50, 3 );
		add_action( 'neural_forms_lead_stage_changed', array( self::class, 'on_stage_changed' ), 10, 4 );
		add_action( self::SWEEP_HOOK, array( self::class, 'sweep_overdue_follow_ups' ) );
		// 1.2.0 part B: fired by PaymentService once per verified, recorded payment.
		add_action( 'neural_forms_payment_confirmed', array( self::class, 'on_payment_confirmed' ), 50, 3 );

		WorkflowQueueProcessor::boot();

		if ( ! wp_next_scheduled( self::SWEEP_HOOK ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::SWEEP_HOOK );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::SWEEP_HOOK );
	}

	public static function enabled(): bool {
		return Capabilities::can( Capabilities::WORKFLOWS );
	}

	// ── event handlers ───────────────────────────────────────────────

	public static function on_entry_created( $entry_id, $form_id, $data ): void {
		$entry_id = (int) $entry_id;
		$form_id  = (int) $form_id;
		if ( $entry_id <= 0 || $form_id <= 0 || ! self::enabled() ) {
			return;
		}
		self::dispatch( WorkflowDefinition::TRIGGER_SUBMISSION, $form_id, $entry_id, array() );
	}

	/**
	 * `payment_confirmed` — only PaymentService fires the action, only after
	 * a signature-verified webhook or a server-to-server fetch said "paid".
	 * The event id is unique per payment attempt, so a redelivered event
	 * can never start a second run.
	 */
	public static function on_payment_confirmed( $entry_id, $payment, $aggregate ): void {
		$entry_id = (int) $entry_id;
		if ( $entry_id <= 0 || ! is_array( $payment ) || ! self::enabled() ) {
			return;
		}
		$form_id = (int) ( $payment['form_id'] ?? 0 );
		if ( $form_id <= 0 ) {
			$entry   = EntryRepository::get( $entry_id );
			$form_id = $entry ? (int) $entry['form_id'] : 0;
		}
		if ( $form_id <= 0 ) {
			return;
		}
		$aggregate = is_array( $aggregate ) ? $aggregate : array();
		self::dispatch(
			WorkflowDefinition::TRIGGER_PAYMENT,
			$form_id,
			$entry_id,
			array(
				'payment_id' => (int) ( $payment['id'] ?? 0 ),
				'payment'    => array(
					'id'                => (int) ( $payment['id'] ?? 0 ),
					'provider'          => (string) ( $payment['provider'] ?? '' ),
					'kind'              => (string) ( $payment['kind'] ?? 'full' ),
					'currency'          => (string) ( $payment['currency'] ?? 'USD' ),
					'amount_paid_minor' => (int) ( $payment['amount_paid_minor'] ?? 0 ),
					'remaining_minor'   => (int) ( $aggregate['remaining_minor'] ?? 0 ),
					'status'            => (string) ( $aggregate['status'] ?? '' ),
					'paid_at'           => (string) ( $payment['paid_at'] ?? '' ),
					'livemode'          => ! empty( $payment['livemode'] ),
				),
			)
		);
	}

	public static function on_analysis_completed( $entry_id, $form_id, $result ): void {
		$entry_id = (int) $entry_id;
		$form_id  = (int) $form_id;
		if ( $entry_id <= 0 || $form_id <= 0 || ! self::enabled() ) {
			return;
		}
		self::dispatch( WorkflowDefinition::TRIGGER_ANALYSIS, $form_id, $entry_id, array( 'analysis' => true ) );
	}

	public static function on_stage_changed( $entry_id, $from, $to, $context ): void {
		$entry_id = (int) $entry_id;
		if ( $entry_id <= 0 || ! self::enabled() ) {
			return;
		}
		$entry = EntryRepository::get( $entry_id );
		if ( ! $entry ) {
			return;
		}
		$context = is_array( $context ) ? $context : array();
		self::dispatch(
			WorkflowDefinition::TRIGGER_STAGE,
			(int) $entry['form_id'],
			$entry_id,
			array(
				'from'    => (string) $from,
				'to'      => (string) $to,
				'at'      => time(),
				'lineage' => (string) ( $context['lineage'] ?? '' ),
				'source'  => (string) ( $context['source'] ?? 'user' ),
			)
		);
	}

	/**
	 * Hourly: every open follow-up that is now overdue fires the overdue
	 * trigger ONCE (follow_up_overdue_sent). Rescheduling the follow-up
	 * resets the flag (EntryRepository::update), so a new due time can
	 * alert again; a closed/deleted reminder never alerts (WF7).
	 */
	public static function sweep_overdue_follow_ups(): void {
		if ( ! self::enabled() || ! WorkflowRepository::any_enabled( WorkflowDefinition::TRIGGER_OVERDUE ) ) {
			return;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, form_id, follow_up_due FROM %i WHERE ' . EntryRepository::OVERDUE_WHERE . ' AND follow_up_overdue_sent = 0 ORDER BY follow_up_due ASC LIMIT 200',
				$wpdb->prefix . 'neural_forms_entries'
			),
			ARRAY_A
		);
		foreach ( (array) $rows as $row ) {
			$entry_id = (int) $row['id'];
			// Mark first so a crash mid-loop cannot alert twice.
			EntryRepository::update( $entry_id, array( 'follow_up_overdue_sent' => 1 ) );
			self::dispatch( WorkflowDefinition::TRIGGER_OVERDUE, (int) $row['form_id'], $entry_id, array( 'due' => (string) $row['follow_up_due'] ) );
		}
	}

	// ── dispatch ─────────────────────────────────────────────────────

	/**
	 * Create a run for every enabled workflow of the form with this
	 * trigger whose filters match, then make sure the queue drains soon.
	 *
	 * @return int[] Created run ids (duplicates and filtered workflows are omitted).
	 */
	public static function dispatch( string $trigger, int $form_id, int $entry_id, array $context ): array {
		$workflows = WorkflowRepository::list_enabled( $form_id, $trigger );
		if ( ! $workflows ) {
			return array();
		}
		$lineage = (string) ( $context['lineage'] ?? '' );
		$created = array();
		foreach ( $workflows as $def ) {
			if ( ! WorkflowDefinition::may_start( $lineage, (int) $def['id'] ) ) {
				Logger::file( 'workflows', 'info', sprintf( 'Workflow %d not started for entry %d: lineage %s would loop or is too deep.', $def['id'], $entry_id, $lineage ) );
				EntryActivity::record( $entry_id, 'workflow.loop_blocked', sprintf( __( 'Workflow "%s" was not started because it would loop.', 'neural-forms-lite' ), $def['name'] ), array( 'by' => 0, 'workflow_id' => (int) $def['id'], 'lineage' => $lineage ), 'warning' );
				continue;
			}
			if ( $trigger === WorkflowDefinition::TRIGGER_STAGE && ! WorkflowDefinition::stage_matches( $def, (string) ( $context['from'] ?? '' ), (string) ( $context['to'] ?? '' ) ) ) {
				continue;
			}
			$event_context = $context;
			if ( $trigger === WorkflowDefinition::TRIGGER_ANALYSIS ) {
				$first = ! WorkflowRunRepository::has_run( (int) $def['id'], $entry_id, $trigger );
				if ( ! $first && empty( $def['settings']['on_reanalysis'] ) ) {
					continue; // default: react to the first successful analysis only
				}
				$event_context['occurrence'] = $first ? 1 : time();
			}
			$event_id = WorkflowDefinition::event_id( $trigger, $entry_id, $event_context );
			$run_id   = WorkflowRunRepository::create_run( $def, $entry_id, $event_id, $lineage, array( 'trigger_context' => $event_context ) );
			if ( $run_id <= 0 ) {
				continue; // duplicate event
			}
			$created[] = $run_id;
			self::enqueue_run( $run_id );
		}
		if ( $created ) {
			self::kick_queue();
		}
		return $created;
	}

	/** Queue one job for a run (now, or at $when for retries). */
	public static function enqueue_run( int $run_id, ?int $when = null ): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'neural_forms_queue',
			array(
				'job_type'     => self::JOB_TYPE,
				'payload'      => wp_json_encode( array( 'run_id' => $run_id ) ),
				'status'       => 'pending',
				'attempts'     => 0,
				'scheduled_at' => gmdate( 'Y-m-d H:i:s', $when ?? time() ),
			),
			array( '%s', '%s', '%s', '%d', '%s' )
		);
	}

	/** Ask cron to run the queue shortly instead of waiting for the 5-minute tick. */
	public static function kick_queue( int $delay = 5 ): void {
		$hook = AIQueueProcessor::CRON_HOOK;
		$next = wp_next_scheduled( $hook );
		if ( $next && $next - time() <= 60 ) {
			return;
		}
		wp_schedule_single_event( time() + $delay, $hook );
	}

	// ── health ───────────────────────────────────────────────────────

	public static function health(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'neural_forms_queue';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT job_type, status, COUNT(*) AS n FROM %i WHERE status IN ('pending','processing','failed') GROUP BY job_type, status", $table ), ARRAY_A );
		$pending = array();
		foreach ( (array) $rows as $r ) {
			$pending[ (string) $r['job_type'] ][ (string) $r['status'] ] = (int) $r['n'];
		}
		$oldest = $wpdb->get_var( $wpdb->prepare( "SELECT MIN(scheduled_at) FROM %i WHERE status = 'pending'", $table ) );
		$next   = wp_next_scheduled( AIQueueProcessor::CRON_HOOK );
		return array(
			'queue'          => $pending,
			'oldest_pending' => $oldest ? (string) $oldest : null,
			'last_run_at'    => get_option( 'neural_forms_queue_last_run', null ),
			'next_run_at'    => $next ? gmdate( 'Y-m-d H:i:s', $next ) : null,
			'wp_cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'alternate_cron'   => defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON,
			'sweep_next_at'    => ( $s = wp_next_scheduled( self::SWEEP_HOOK ) ) ? gmdate( 'Y-m-d H:i:s', $s ) : null,
			'workflows_enabled' => self::enabled(),
			'system_cron_hint'  => '*/5 * * * * curl -s ' . site_url( 'wp-cron.php?doing_wp_cron' ) . ' > /dev/null',
		);
	}
}
