<?php
namespace NeuralForms\Workflows\Actions;

use NeuralForms\Core\Capabilities;
use NeuralForms\Core\Config;
use NeuralForms\Plan\PlanManager;

defined( 'ABSPATH' ) || exit;

/**
 * Queues a "regenerate reply" job so a business-aware draft lands in the
 * entry's AI drafts carousel for a human to review. Nothing is sent.
 */
final class PrepareAiDraftAction extends AbstractAction {

	public function type(): string { return 'prepare_ai_draft'; }
	public function label(): string { return __( 'Prepare an AI reply draft for review', 'neural-forms-lite' ); }
	public function description(): string { return __( 'Asks Neural Forms Cloud for a reply draft and puts it in the AI drafts carousel. A person still reviews and sends it. Uses AI credits.', 'neural-forms-lite' ); }

	public function settings_schema(): array {
		return array();
	}

	public function describe( array $settings, array $ctx ): string {
		return __( 'Queue an AI reply draft for review (uses credits)', 'neural-forms-lite' );
	}

	public function execute( array $settings, array $ctx ): ActionResult {
		if ( ! Config::get( 'ai_enabled' ) || ! Config::get( 'cloud_ai_enabled' ) || Config::get( 'cloud_api_key' ) === '' ) {
			return ActionResult::failed( __( 'Neural Forms Cloud is not connected, so no draft can be prepared.', 'neural-forms-lite' ) );
		}
		if ( ! Capabilities::can( Capabilities::AI_REANALYZE ) ) {
			return ActionResult::failed( __( 'Reply generation requires Neural Forms Pro.', 'neural-forms-lite' ) );
		}
		if ( ! PlanManager::can( 'ai_reply_regenerate' ) ) {
			return ActionResult::failed( __( 'Your Cloud plan does not include reply generation.', 'neural-forms-lite' ) );
		}
		if ( PlanManager::is_credits_exhausted() ) {
			return ActionResult::retry( __( 'AI credit balance is empty; will retry.', 'neural-forms-lite' ) );
		}

		global $wpdb;
		$inserted = $wpdb->insert(
			$wpdb->prefix . 'neural_forms_queue',
			array(
				'job_type'     => 'regenerate_reply',
				'payload'      => wp_json_encode(
					array(
						'entry_id' => (int) $ctx['entry']['id'],
						'form_id'  => (int) $ctx['entry']['form_id'],
						'source'   => 'workflow',
						'run_id'   => (int) $ctx['run_id'],
					)
				),
				'status'       => 'pending',
				'attempts'     => 0,
				'scheduled_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%d', '%s' )
		);
		if ( ! $inserted ) {
			return ActionResult::retry( __( 'Could not queue the draft job.', 'neural-forms-lite' ) );
		}
		$this->activity( $ctx, 'workflow.draft_requested', sprintf( __( 'AI reply draft requested by workflow "%s"', 'neural-forms-lite' ), (string) $ctx['workflow_name'] ) );
		return ActionResult::done( __( 'Draft job queued; it appears in AI drafts when ready.', 'neural-forms-lite' ), 'queue:' . (int) $wpdb->insert_id );
	}
}
