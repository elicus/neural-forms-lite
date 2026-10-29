<?php
namespace NeuralForms\Workflows;

use NeuralForms\Forms\ConditionEngine;
use NeuralForms\Forms\SchemaHelper;
use NeuralForms\Storage\LeadStages;
use NeuralForms\Workflows\Actions\ActionRegistry;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Workflow definition: shape, normalisation, validation and the pure
 * helpers the engine needs (event ids, lineage, backoff) — no DB here,
 * so every rule is unit-testable (tests/WorkflowDefinitionTest.php).
 *
 * Stored shape (neural_forms_workflows row, JSON columns decoded):
 *
 *   {
 *     id, form_id, name, description, enabled, revision,
 *     trigger:    submission_received | analysis_completed | stage_changed | follow_up_overdue,
 *     conditions: <ConditionEngine v2 object> | null,
 *     actions:    [ { key: 'a1', type, settings: {}, continue_on_failure: bool } ],
 *     settings:   { on_reanalysis: bool, stage_from: '', stage_to: '' }
 *   }
 *
 * Conditions may reference form fields AND the entry meta sources in
 * META_SOURCES (ai_category, ai_priority, …). The engine always passes
 * every meta key (empty string when unknown) so an "equals" rule on a
 * missing AI result fails instead of hitting the lenient absent-field
 * fallback of the field evaluator.
 */
final class WorkflowDefinition {

	const TRIGGER_SUBMISSION = 'submission_received';
	const TRIGGER_ANALYSIS   = 'analysis_completed';
	const TRIGGER_STAGE      = 'stage_changed';
	const TRIGGER_OVERDUE    = 'follow_up_overdue';
	const TRIGGER_PAYMENT    = 'payment_confirmed'; // 1.2.0 part B (B4)

	const TRIGGERS = array( self::TRIGGER_SUBMISSION, self::TRIGGER_ANALYSIS, self::TRIGGER_STAGE, self::TRIGGER_OVERDUE, self::TRIGGER_PAYMENT );

	const MAX_ACTIONS = 10;

	/** Retry schedule for retryable action failures, in minutes (attempt 1, 2, 3). */
	const BACKOFF_MINUTES = array( 1, 5, 30 );
	const MAX_ATTEMPTS    = 3;

	/** Loop guard: a stage-change event with this many ancestor runs stops cascading. */
	const MAX_LINEAGE_DEPTH = 3;

	/**
	 * Entry meta exposed to workflow conditions as pseudo-fields.
	 *
	 * @return array<string,array> id => { label, type, options? }
	 */
	public static function meta_sources(): array {
		$stage_options = array();
		foreach ( LeadStages::all() as $id => $label ) {
			$stage_options[] = array( 'value' => $id, 'label' => $label );
		}
		return array(
			'ai_category' => array(
				'label'   => __( 'AI category', 'neural-forms-lite' ),
				'type'    => 'select',
				'options' => array_map(
					static function ( $c ) { return array( 'value' => $c, 'label' => ucwords( str_replace( '_', ' ', $c ) ) ); },
					array( 'sales', 'support', 'partnership', 'job_application', 'feedback', 'complaint', 'spam', 'other' )
				),
			),
			'ai_priority' => array(
				'label'   => __( 'AI priority', 'neural-forms-lite' ),
				'type'    => 'select',
				'options' => array(
					array( 'value' => 'high', 'label' => __( 'High', 'neural-forms-lite' ) ),
					array( 'value' => 'normal', 'label' => __( 'Normal', 'neural-forms-lite' ) ),
					array( 'value' => 'low', 'label' => __( 'Low', 'neural-forms-lite' ) ),
				),
			),
			'ai_score'    => array( 'label' => __( 'AI lead score (0-100)', 'neural-forms-lite' ), 'type' => 'number' ),
			'ai_is_spam'  => array( 'label' => __( 'AI flagged as spam', 'neural-forms-lite' ), 'type' => 'checkbox' ),
			'lead_stage'  => array( 'label' => __( 'Lead stage', 'neural-forms-lite' ), 'type' => 'select', 'options' => $stage_options ),
			'assigned_to' => array( 'label' => __( 'Assigned user id (0 = unassigned)', 'neural-forms-lite' ), 'type' => 'number' ),
			// 1.2.0 part B: payment aggregate (entry) + the confirmed payment's kind (payment_confirmed trigger only).
			'payment_status' => array(
				'label'   => __( 'Payment status', 'neural-forms-lite' ),
				'type'    => 'select',
				'options' => array_map(
					static function ( $s ) { return array( 'value' => $s, 'label' => class_exists( '\\NeuralForms\\Payments\\PaymentStatus' ) ? \NeuralForms\Payments\PaymentStatus::label( $s ) : $s ); },
					array( 'none', 'pending', 'processing', 'failed', 'cancelled', 'deposit_paid', 'paid', 'partially_refunded', 'refunded' )
				),
			),
			'payment_kind'   => array(
				'label'   => __( 'Confirmed payment was a', 'neural-forms-lite' ),
				'type'    => 'select',
				'options' => array( array( 'value' => 'deposit', 'label' => __( 'Deposit', 'neural-forms-lite' ) ), array( 'value' => 'full', 'label' => __( 'Full payment', 'neural-forms-lite' ) ) ),
			),
			'status'      => array(
				'label'   => __( 'Inbox status', 'neural-forms-lite' ),
				'type'    => 'select',
				'options' => array(
					array( 'value' => 'new', 'label' => __( 'New', 'neural-forms-lite' ) ),
					array( 'value' => 'read', 'label' => __( 'Read', 'neural-forms-lite' ) ),
					array( 'value' => 'spam', 'label' => __( 'Spam', 'neural-forms-lite' ) ),
					array( 'value' => 'trash', 'label' => __( 'Trash', 'neural-forms-lite' ) ),
				),
			),
		);
	}

	/**
	 * Build the data + types maps a workflow condition is evaluated against.
	 *
	 * @param array $entry Decoded entry row (EntryRepository::get).
	 * @param array $form  Form row (for field types).
	 * @return array { data: array, types: array }
	 */
	public static function evaluation_context( array $entry, array $form, array $trigger_context = array() ): array {
		$data  = is_array( $entry['data'] ?? null ) ? $entry['data'] : array();
		$types = array();
		$fields = SchemaHelper::flatten_fields( is_array( $form['form_schema']['fields'] ?? null ) ? $form['form_schema']['fields'] : array() );
		foreach ( $fields as $f ) {
			$id = (string) ( $f['id'] ?? '' );
			if ( $id !== '' ) {
				$types[ $id ] = (string) ( $f['type'] ?? 'text' );
			}
		}
		foreach ( self::meta_sources() as $id => $meta ) {
			$types[ $id ] = $meta['type'];
		}
		$data['ai_category'] = (string) ( $entry['ai_category'] ?? '' );
		$data['ai_priority'] = (string) ( $entry['ai_priority'] ?? '' );
		$data['ai_score']    = isset( $entry['ai_score'] ) && null !== $entry['ai_score'] ? (string) (int) $entry['ai_score'] : '';
		$data['ai_is_spam']  = ! empty( $entry['ai_is_spam'] ) ? '1' : '';
		$data['lead_stage']  = (string) ( $entry['lead_stage'] ?? LeadStages::DEFAULT_STAGE );
		$data['assigned_to'] = (string) (int) ( $entry['assigned_to'] ?? 0 );
		$data['status']      = (string) ( $entry['status'] ?? '' );
		$data['payment_status'] = (string) ( $entry['payment_status'] ?? 'none' );
		$data['payment_kind']   = (string) ( $trigger_context['payment']['kind'] ?? '' );
		return array( 'data' => $data, 'types' => $types );
	}

	/**
	 * Normalise a raw definition (REST body or DB row). Never throws.
	 *
	 * @param mixed $raw
	 */
	public static function normalize( $raw ): array {
		$raw = is_array( $raw ) ? $raw : array();
		$trigger = isset( $raw['trigger'] ) ? (string) $raw['trigger'] : self::TRIGGER_SUBMISSION;
		if ( ! in_array( $trigger, self::TRIGGERS, true ) ) {
			$trigger = self::TRIGGER_SUBMISSION;
		}

		$conditions = null;
		if ( isset( $raw['conditions'] ) && is_array( $raw['conditions'] ) ) {
			$n = ConditionEngine::normalize( $raw['conditions'] );
			if ( $n['rules'] || $n['groups'] ) {
				$n['enabled'] = true;
				$conditions   = $n;
			}
		}

		$actions = array();
		$list    = isset( $raw['actions'] ) && is_array( $raw['actions'] ) ? array_values( $raw['actions'] ) : array();
		foreach ( $list as $i => $a ) {
			if ( ! is_array( $a ) ) {
				continue;
			}
			$type = isset( $a['type'] ) ? preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $a['type'] ) ) : '';
			if ( $type === '' ) {
				continue;
			}
			$actions[] = array(
				'key'                 => 'a' . ( count( $actions ) + 1 ),
				'type'                => $type,
				'settings'            => isset( $a['settings'] ) && is_array( $a['settings'] ) ? $a['settings'] : array(),
				'continue_on_failure' => ! empty( $a['continue_on_failure'] ),
			);
			if ( count( $actions ) >= self::MAX_ACTIONS ) {
				break;
			}
		}

		$s = isset( $raw['settings'] ) && is_array( $raw['settings'] ) ? $raw['settings'] : array();
		$settings = array(
			'on_reanalysis' => ! empty( $s['on_reanalysis'] ),
			'stage_from'    => isset( $s['stage_from'] ) ? LeadStages::sanitize_id( (string) $s['stage_from'] ) : '',
			'stage_to'      => isset( $s['stage_to'] ) ? LeadStages::sanitize_id( (string) $s['stage_to'] ) : '',
		);

		return array(
			'id'          => isset( $raw['id'] ) ? (int) $raw['id'] : 0,
			'form_id'     => isset( $raw['form_id'] ) ? (int) $raw['form_id'] : 0,
			'name'        => isset( $raw['name'] ) && is_string( $raw['name'] ) ? trim( $raw['name'] ) : '',
			'description' => isset( $raw['description'] ) && is_string( $raw['description'] ) ? trim( $raw['description'] ) : '',
			'enabled'     => ! isset( $raw['enabled'] ) || ! empty( $raw['enabled'] ),
			'revision'    => isset( $raw['revision'] ) ? max( 1, (int) $raw['revision'] ) : 1,
			'trigger'     => $trigger,
			'conditions'  => $conditions,
			'actions'     => $actions,
			'settings'    => $settings,
		);
	}

	/**
	 * Validate a normalised definition against a form schema and the
	 * action registry.
	 *
	 * @param array      $def         normalize() output.
	 * @param array      $form_schema { fields: [...] }
	 * @param array|null $registry    ActionRegistry::all() (null → load).
	 * @return true|WP_Error
	 */
	public static function validate( array $def, array $form_schema, ?array $registry = null ) {
		if ( $def['name'] === '' ) {
			return self::error( __( 'Give the workflow a name.', 'neural-forms-lite' ) );
		}
		if ( mb_strlen( $def['name'] ) > 190 ) {
			return self::error( __( 'The workflow name is too long (190 characters max).', 'neural-forms-lite' ) );
		}
		if ( $def['form_id'] <= 0 ) {
			return self::error( __( 'A workflow belongs to a form.', 'neural-forms-lite' ) );
		}
		if ( ! $def['actions'] ) {
			return self::error( __( 'Add at least one action.', 'neural-forms-lite' ) );
		}

		$ids = array();
		foreach ( SchemaHelper::flatten_fields( is_array( $form_schema['fields'] ?? null ) ? $form_schema['fields'] : array() ) as $f ) {
			$id = (string) ( $f['id'] ?? '' );
			if ( $id !== '' ) {
				$ids[] = $id;
			}
		}
		$ids = array_merge( $ids, array_keys( self::meta_sources() ) );

		if ( $def['conditions'] ) {
			$r = ConditionEngine::validate( $def['conditions'], $ids, true, '', __( 'Workflow conditions', 'neural-forms-lite' ) );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
		}

		if ( $def['trigger'] === self::TRIGGER_STAGE ) {
			foreach ( array( 'stage_from', 'stage_to' ) as $k ) {
				$v = $def['settings'][ $k ];
				if ( $v !== '' && ! LeadStages::is_valid( $v ) ) {
					return self::error( sprintf( __( 'Unknown lead stage "%s".', 'neural-forms-lite' ), $v ) );
				}
			}
			if ( $def['settings']['stage_from'] !== '' && $def['settings']['stage_from'] === $def['settings']['stage_to'] ) {
				return self::error( __( 'The stage trigger needs two different stages (or leave one blank for "any").', 'neural-forms-lite' ) );
			}
		}

		$registry = null === $registry ? ActionRegistry::all() : $registry;
		$n = 0;
		foreach ( $def['actions'] as $a ) {
			$n++;
			if ( ! isset( $registry[ $a['type'] ] ) ) {
				return self::error( sprintf( __( 'Action %1$d uses an unknown type "%2$s".', 'neural-forms-lite' ), $n, $a['type'] ) );
			}
			$action = $registry[ $a['type'] ];
			$r = $action->validate_settings( $a['settings'] );
			if ( is_wp_error( $r ) ) {
				return new WP_Error( 'invalid_workflow', sprintf( __( 'Action %1$d (%2$s): %3$s', 'neural-forms-lite' ), $n, $action->label(), $r->get_error_message() ), array( 'status' => 422 ) );
			}
		}
		return true;
	}

	// ── pure engine helpers ──────────────────────────────────────────

	/**
	 * Stable event id per trigger occurrence. Duplicate dispatches of the
	 * same event collapse on the (workflow_id, event_id) unique key.
	 */
	public static function event_id( string $trigger, int $entry_id, array $context = array() ): string {
		switch ( $trigger ) {
			case self::TRIGGER_SUBMISSION:
				return 'created:' . $entry_id;
			case self::TRIGGER_ANALYSIS:
				$n = isset( $context['occurrence'] ) ? (int) $context['occurrence'] : 1;
				return 'analysis:' . $entry_id . ':' . $n;
			case self::TRIGGER_STAGE:
				return 'stage:' . $entry_id . ':' . (string) ( $context['from'] ?? '' ) . '>' . (string) ( $context['to'] ?? '' ) . ':' . (string) ( $context['at'] ?? time() );
			case self::TRIGGER_OVERDUE:
				return 'overdue:' . $entry_id . ':' . (string) ( $context['due'] ?? '' );
			case self::TRIGGER_PAYMENT:
				return 'payment:' . $entry_id . ':' . (int) ( $context['payment_id'] ?? 0 );
		}
		return $trigger . ':' . $entry_id;
	}

	/**
	 * Lineage is a comma list of "W{workflow}:R{run}" tokens, oldest first.
	 */
	public static function lineage_append( string $lineage, int $workflow_id, int $run_id ): string {
		$token = 'W' . $workflow_id . ':R' . $run_id;
		return $lineage === '' ? $token : $lineage . ',' . $token;
	}

	public static function lineage_depth( string $lineage ): int {
		return $lineage === '' ? 0 : count( explode( ',', $lineage ) );
	}

	/** True when the workflow already appears in the lineage (would loop). */
	public static function lineage_contains( string $lineage, int $workflow_id ): bool {
		if ( $lineage === '' ) {
			return false;
		}
		foreach ( explode( ',', $lineage ) as $token ) {
			if ( strpos( $token, 'W' . $workflow_id . ':' ) === 0 ) {
				return true;
			}
		}
		return false;
	}

	/** May this workflow start from an event with this lineage? */
	public static function may_start( string $lineage, int $workflow_id ): bool {
		return ! self::lineage_contains( $lineage, $workflow_id ) && self::lineage_depth( $lineage ) < self::MAX_LINEAGE_DEPTH;
	}

	/** Minutes to wait before attempt N+1 (after N failed attempts). */
	public static function backoff_minutes( int $attempts_so_far ): int {
		$i = max( 0, min( $attempts_so_far - 1, count( self::BACKOFF_MINUTES ) - 1 ) );
		return self::BACKOFF_MINUTES[ $i ];
	}

	/** Does a stage event match the workflow's from/to filters? */
	public static function stage_matches( array $def, string $from, string $to ): bool {
		$f = $def['settings']['stage_from'] ?? '';
		$t = $def['settings']['stage_to'] ?? '';
		if ( $f !== '' && $f !== $from ) {
			return false;
		}
		if ( $t !== '' && $t !== $to ) {
			return false;
		}
		return true;
	}

	private static function error( string $message ): WP_Error {
		return new WP_Error( 'invalid_workflow', $message, array( 'status' => 422 ) );
	}
}
