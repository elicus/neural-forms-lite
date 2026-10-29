<?php
namespace NeuralForms\Workflows\Actions;

use NeuralForms\Storage\EntryRepository;
use NeuralForms\Storage\LeadStages;

defined( 'ABSPATH' ) || exit;

final class SetStageAction extends AbstractAction {

	public function type(): string { return 'set_stage'; }
	public function label(): string { return __( 'Change lead stage', 'neural-forms-lite' ); }
	public function description(): string { return __( 'Moves the lead to a stage. Fires the stage-changed trigger for other workflows (loops are blocked).', 'neural-forms-lite' ); }

	public function settings_schema(): array {
		return array(
			array( 'key' => 'stage', 'label' => __( 'Stage', 'neural-forms-lite' ), 'type' => 'stage', 'required' => true ),
		);
	}

	public function validate_settings( array $settings ) {
		$r = parent::validate_settings( $settings );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		if ( ! LeadStages::is_valid( LeadStages::sanitize_id( (string) ( $settings['stage'] ?? '' ) ) ) ) {
			return $this->error( __( 'Unknown lead stage.', 'neural-forms-lite' ) );
		}
		return true;
	}

	public function describe( array $settings, array $ctx ): string {
		return sprintf( __( 'Move the lead to "%s"', 'neural-forms-lite' ), LeadStages::label( LeadStages::sanitize_id( (string) ( $settings['stage'] ?? '' ) ) ) );
	}

	public function execute( array $settings, array $ctx ): ActionResult {
		$stage = LeadStages::sanitize_id( (string) ( $settings['stage'] ?? '' ) );
		if ( ! LeadStages::is_valid( $stage ) ) {
			return ActionResult::failed( __( 'Unknown lead stage.', 'neural-forms-lite' ) );
		}
		$entry_id = (int) $ctx['entry']['id'];
		$from     = (string) ( $ctx['entry']['lead_stage'] ?? LeadStages::DEFAULT_STAGE );
		if ( $from === $stage ) {
			return ActionResult::done( __( 'Already in that stage.', 'neural-forms-lite' ) );
		}
		$ok = EntryRepository::update( $entry_id, array( 'lead_stage' => $stage, '_context' => $this->lead_context( $ctx ) ) );
		if ( ! $ok ) {
			return ActionResult::retry( __( 'Could not save the stage.', 'neural-forms-lite' ) );
		}
		$this->activity( $ctx, 'workflow.stage_changed', sprintf( __( 'Stage changed from %1$s to %2$s by workflow "%3$s"', 'neural-forms-lite' ), LeadStages::label( $from ), LeadStages::label( $stage ), (string) $ctx['workflow_name'] ), array( 'from' => $from, 'to' => $stage, 'source' => 'workflow' ) );
		return ActionResult::done( sprintf( __( 'Moved to %s', 'neural-forms-lite' ), LeadStages::label( $stage ) ) );
	}
}
