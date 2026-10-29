<?php
namespace NeuralForms\Workflows\Actions;

defined( 'ABSPATH' ) || exit;

/**
 * One workflow action type.
 *
 * `$ctx` passed to execute() / describe():
 *   run_id, workflow_id, workflow_name, lineage (string),
 *   entry (decoded row), form (row), trigger, dry_run (bool)
 */
interface WorkflowAction {

	/** Stable machine type, e.g. 'assign_user'. */
	public function type(): string;

	/** Human label for the editor. */
	public function label(): string;

	/** Short description for the editor. */
	public function description(): string;

	/**
	 * Settings schema for the editor:
	 * [ { key, label, type: text|textarea|number|select|user|stage|checkbox, required?, options?, hint?, default? } ]
	 */
	public function settings_schema(): array;

	/** @return true|\WP_Error */
	public function validate_settings( array $settings );

	/** One sentence of what WOULD happen (dry run / history). */
	public function describe( array $settings, array $ctx ): string;

	/** Perform the action. Must be safe to call again after a retry/uncertain outcome where possible. */
	public function execute( array $settings, array $ctx ): ActionResult;
}
