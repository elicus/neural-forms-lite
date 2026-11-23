<?php
namespace NeuralForms\Workflows\Actions;

defined( 'ABSPATH' ) || exit;

/**
 * Registry of workflow action types.
 *
 * Lite registers its six built-ins; add-ons add theirs through the
 * `neural_forms_workflow_actions` filter (array of WorkflowAction
 * instances keyed by type). Pro uses it for its integration actions.
 */
final class ActionRegistry {

	/** @var array<string,WorkflowAction>|null */
	private static $cache = null;

	/**
	 * @return array<string,WorkflowAction> type => action
	 */
	public static function all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$built_in = array(
			new AssignUserAction(),
			new SetStageAction(),
			new CreateFollowUpAction(),
			new NotifyInternalAction(),
			new SendAcknowledgementAction(),
			new PrepareAiDraftAction(),
			new CreateQuoteDraftAction(),
		);
		$map = array();
		foreach ( $built_in as $a ) {
			$map[ $a->type() ] = $a;
		}

		/**
		 * Filter: neural_forms_workflow_actions
		 *
		 * @param array<string,WorkflowAction> $map
		 */
		$filtered = apply_filters( 'neural_forms_workflow_actions', $map );
		$clean    = array();
		foreach ( is_array( $filtered ) ? $filtered : $map as $type => $a ) {
			if ( $a instanceof WorkflowAction ) {
				$clean[ $a->type() ] = $a;
			}
		}
		self::$cache = $clean;
		return $clean;
	}

	public static function get( string $type ): ?WorkflowAction {
		$all = self::all();
		return $all[ $type ] ?? null;
	}

	/** Editor payload. */
	public static function for_script(): array {
		$out = array();
		foreach ( self::all() as $a ) {
			$out[] = array(
				'type'        => $a->type(),
				'label'       => $a->label(),
				'description' => $a->description(),
				'settings'    => $a->settings_schema(),
			);
		}
		return $out;
	}

	public static function reset_for_tests(): void {
		self::$cache = null;
	}
}
