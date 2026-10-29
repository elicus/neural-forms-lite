<?php
namespace NeuralForms\Workflows\Actions;

use NeuralForms\Storage\EntryActivity;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Shared helpers for the built-in actions.
 */
abstract class AbstractAction implements WorkflowAction {

	public function validate_settings( array $settings ) {
		foreach ( $this->settings_schema() as $field ) {
			if ( ! empty( $field['required'] ) ) {
				$v = $settings[ $field['key'] ] ?? null;
				if ( null === $v || $v === '' || ( is_array( $v ) && ! $v ) ) {
					return new WP_Error( 'invalid_workflow', sprintf( __( '%s is required.', 'neural-forms-lite' ), $field['label'] ) );
				}
			}
		}
		return true;
	}

	protected function error( string $message ): WP_Error {
		return new WP_Error( 'invalid_workflow', $message );
	}

	/** Timeline row attributed to the workflow (by = 0 → system). */
	protected function activity( array $ctx, string $event_type, string $message, array $payload = array(), string $status = 'info' ): void {
		$entry_id = (int) ( $ctx['entry']['id'] ?? 0 );
		if ( $entry_id <= 0 || ! empty( $ctx['dry_run'] ) ) {
			return;
		}
		$payload['by']          = 0;
		$payload['workflow_id'] = (int) ( $ctx['workflow_id'] ?? 0 );
		$payload['run_id']      = (int) ( $ctx['run_id'] ?? 0 );
		$payload['workflow']    = (string) ( $ctx['workflow_name'] ?? '' );
		EntryActivity::record( $entry_id, $event_type, $message, $payload, $status );
	}

	protected function lead_context( array $ctx ): array {
		return array(
			'source'   => 'workflow',
			'lineage'  => (string) ( $ctx['lineage'] ?? '' ),
			'run_id'   => (int) ( $ctx['run_id'] ?? 0 ),
			'workflow' => (int) ( $ctx['workflow_id'] ?? 0 ),
		);
	}
}
