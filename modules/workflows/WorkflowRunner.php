<?php
namespace NeuralForms\Workflows;

use NeuralForms\Core\Logger;
use NeuralForms\Forms\ConditionEngine;
use NeuralForms\Forms\FormRepository;
use NeuralForms\Storage\EntryActivity;
use NeuralForms\Storage\EntryRepository;
use NeuralForms\Workflows\Actions\ActionRegistry;
use NeuralForms\Workflows\Actions\ActionResult;

defined( 'ABSPATH' ) || exit;

/**
 * Executes one run: conditions → actions in order, with retries,
 * stop-on-failure semantics and a full explanation trail.
 */
final class WorkflowRunner {

	/**
	 * @return string 'done' | 'skipped' | 'waiting' | 'failed' | 'partial' | <reason>
	 */
	public static function process_run( int $run_id ): string {
		if ( ! WorkflowRunRepository::claim_run( $run_id ) ) {
			return 'not_claimable';
		}
		$run = WorkflowRunRepository::get_run( $run_id );
		if ( ! $run ) {
			return 'missing';
		}
		$def = is_array( $run['context']['definition'] ?? null ) ? $run['context']['definition'] : WorkflowRepository::find( (int) $run['workflow_id'] );
		if ( ! $def ) {
			WorkflowRunRepository::finish_run( $run_id, 'failed', 'Workflow definition missing.' );
			return 'failed';
		}
		$entry = EntryRepository::get( (int) $run['entry_id'] );
		if ( ! $entry ) {
			WorkflowRunRepository::finish_run( $run_id, 'failed', 'Entry no longer exists.' );
			return 'failed';
		}
		$form = FormRepository::find( (int) $entry['form_id'] );
		if ( ! $form ) {
			WorkflowRunRepository::finish_run( $run_id, 'failed', 'Form no longer exists.' );
			return 'failed';
		}

		$ctx = array(
			'run_id'        => $run_id,
			'workflow_id'   => (int) $def['id'],
			'workflow_name' => (string) $def['name'],
			'lineage'       => WorkflowDefinition::lineage_append( (string) $run['lineage'], (int) $def['id'], $run_id ),
			'entry'         => $entry,
			'form'          => $form,
			'trigger'       => (string) $run['trigger_type'],
			'dry_run'       => false,
		);

		// Conditions — evaluated once, on the first attempt of the run.
		$already_started = (bool) WorkflowRunRepository::actions_for_run( $run_id );
		if ( ! $already_started ) {
			$evaluation = self::evaluate( $def, $entry, $form );
			EntryActivity::record(
				(int) $entry['id'],
				$evaluation['matched'] ? 'workflow.started' : 'workflow.skipped',
				$evaluation['matched']
					? sprintf( __( 'Workflow "%s" started', 'neural-forms-lite' ), $def['name'] )
					: sprintf( __( 'Workflow "%s" skipped: conditions not met', 'neural-forms-lite' ), $def['name'] ),
				array( 'by' => 0, 'workflow_id' => (int) $def['id'], 'run_id' => $run_id, 'conditions' => $evaluation['rules'] )
			);
			if ( ! $evaluation['matched'] ) {
				WorkflowRunRepository::finish_run( $run_id, 'skipped', wp_json_encode( $evaluation['rules'] ) );
				return 'skipped';
			}
			WorkflowRunRepository::ensure_actions( $run_id, $def['actions'] );
		}

		$by_key = array();
		foreach ( $def['actions'] as $a ) {
			$by_key[ $a['key'] ] = $a;
		}

		$rows      = WorkflowRunRepository::actions_for_run( $run_id );
		$waiting   = false;
		$failed    = false;
		$uncertain = false;
		$stop      = false;
		$earliest_retry = null;

		foreach ( $rows as $row ) {
			$spec = $by_key[ $row['action_key'] ] ?? null;
			if ( ! $spec ) {
				WorkflowRunRepository::finish_action( (int) $row['id'], 'skipped', 'Action no longer in the definition.' );
				continue;
			}
			if ( $stop ) {
				if ( in_array( $row['status'], array( 'pending' ), true ) ) {
					WorkflowRunRepository::finish_action( (int) $row['id'], 'skipped', __( 'Skipped because an earlier action failed.', 'neural-forms-lite' ) );
				}
				continue;
			}
			if ( $row['status'] === 'done' || $row['status'] === 'skipped' ) {
				continue;
			}
			if ( $row['status'] === 'failed' ) {
				$failed = true;
				if ( empty( $spec['continue_on_failure'] ) ) {
					$stop = true;
				}
				continue;
			}
			if ( $row['status'] === 'uncertain' ) {
				$uncertain = true;
				if ( empty( $spec['continue_on_failure'] ) ) {
					$stop = true;
				}
				continue;
			}
			if ( $row['status'] === 'pending' && ! empty( $row['next_attempt_at'] ) && strtotime( $row['next_attempt_at'] . ' UTC' ) > time() ) {
				$waiting        = true;
				$earliest_retry = $earliest_retry ? min( $earliest_retry, strtotime( $row['next_attempt_at'] . ' UTC' ) ) : strtotime( $row['next_attempt_at'] . ' UTC' );
				$stop           = empty( $spec['continue_on_failure'] ); // dependents wait too
				continue;
			}
			if ( ! WorkflowRunRepository::claim_action( (int) $row['id'] ) ) {
				continue; // another worker has it
			}
			$attempts = $row['attempts'] + 1;
			$result   = self::execute( $spec, $ctx );

			switch ( $result->status ) {
				case ActionResult::DONE:
				case ActionResult::SKIPPED:
					WorkflowRunRepository::finish_action( (int) $row['id'], $result->status === ActionResult::DONE ? 'done' : 'skipped', $result->message, $result->external_ref );
					break;
				case ActionResult::RETRY:
					if ( $attempts >= WorkflowDefinition::MAX_ATTEMPTS ) {
						WorkflowRunRepository::finish_action( (int) $row['id'], 'failed', sprintf( __( 'Gave up after %1$d attempts: %2$s', 'neural-forms-lite' ), $attempts, $result->message ) );
						$failed = true;
						if ( empty( $spec['continue_on_failure'] ) ) {
							$stop = true;
						}
					} else {
						$at = time() + WorkflowDefinition::backoff_minutes( $attempts ) * 60;
						WorkflowRunRepository::finish_action( (int) $row['id'], 'pending', sprintf( __( 'Attempt %1$d failed: %2$s. Retrying.', 'neural-forms-lite' ), $attempts, $result->message ), null, gmdate( 'Y-m-d H:i:s', $at ) );
						// Undo the attempts bump? No — attempts counts tries; keep it.
						$waiting        = true;
						$earliest_retry = $earliest_retry ? min( $earliest_retry, $at ) : $at;
						if ( empty( $spec['continue_on_failure'] ) ) {
							$stop = true;
						}
					}
					break;
				case ActionResult::UNCERTAIN:
					WorkflowRunRepository::finish_action( (int) $row['id'], 'uncertain', $result->message, $result->external_ref );
					$uncertain = true;
					if ( empty( $spec['continue_on_failure'] ) ) {
						$stop = true;
					}
					break;
				case ActionResult::FAILED:
				default:
					WorkflowRunRepository::finish_action( (int) $row['id'], 'failed', $result->message );
					$failed = true;
					if ( empty( $spec['continue_on_failure'] ) ) {
						$stop = true;
					}
					break;
			}
			if ( ! $result->is_ok() ) {
				EntryActivity::record( (int) $entry['id'], 'workflow.action_' . $result->status, sprintf( __( 'Workflow "%1$s": %2$s — %3$s', 'neural-forms-lite' ), $def['name'], ActionRegistry::get( $spec['type'] ) ? ActionRegistry::get( $spec['type'] )->label() : $spec['type'], $result->message ), array( 'by' => 0, 'workflow_id' => (int) $def['id'], 'run_id' => $run_id, 'action' => $spec['key'] ), $result->status === ActionResult::RETRY ? 'warning' : 'error' );
			}
		}

		if ( $waiting ) {
			WorkflowRunRepository::finish_run( $run_id, 'waiting' );
			WorkflowEngine::enqueue_run( $run_id, $earliest_retry ?: time() + 60 );
			return 'waiting';
		}
		if ( $failed && ! $uncertain ) {
			$status = self::all_failed( WorkflowRunRepository::actions_for_run( $run_id ) ) ? 'failed' : 'partial';
		} elseif ( $uncertain ) {
			$status = 'partial';
		} else {
			$status = 'done';
		}
		WorkflowRunRepository::finish_run( $run_id, $status );
		EntryActivity::record( (int) $entry['id'], 'workflow.finished', sprintf( __( 'Workflow "%1$s" finished: %2$s', 'neural-forms-lite' ), $def['name'], $status ), array( 'by' => 0, 'workflow_id' => (int) $def['id'], 'run_id' => $run_id, 'status' => $status ), $status === 'done' ? 'info' : 'warning' );
		return $status;
	}

	private static function all_failed( array $rows ): bool {
		foreach ( $rows as $r ) {
			if ( $r['status'] === 'done' ) {
				return false;
			}
		}
		return true;
	}

	private static function execute( array $spec, array $ctx ): ActionResult {
		$action = ActionRegistry::get( $spec['type'] );
		if ( ! $action ) {
			return ActionResult::failed( sprintf( __( 'Unknown action type "%s" (is the add-on that provides it active?).', 'neural-forms-lite' ), $spec['type'] ) );
		}
		try {
			return $action->execute( is_array( $spec['settings'] ?? null ) ? $spec['settings'] : array(), $ctx );
		} catch ( \Throwable $e ) {
			Logger::file( 'workflows', 'error', sprintf( 'Action %s threw: %s', $spec['type'], $e->getMessage() ) );
			return ActionResult::retry( $e->getMessage() );
		}
	}

	/**
	 * Evaluate a workflow's conditions against an entry, with per-rule explanations.
	 *
	 * @return array { matched: bool, rules: [ { field, operator, value, actual, result } ] }
	 */
	public static function evaluate( array $def, array $entry, array $form ): array {
		if ( ! $def['conditions'] ) {
			return array( 'matched' => true, 'rules' => array() );
		}
		$ctx     = WorkflowDefinition::evaluation_context( $entry, $form );
		$matched = ConditionEngine::evaluate( $def['conditions'], $ctx['data'], $ctx['types'] );
		$rules   = array();
		$n       = ConditionEngine::normalize( $def['conditions'] );
		$all     = $n['rules'];
		foreach ( $n['groups'] as $g ) {
			$all = array_merge( $all, $g['rules'] );
		}
		foreach ( $all as $r ) {
			$actual  = $ctx['data'][ $r['field'] ] ?? null;
			$rules[] = array(
				'field'    => $r['field'],
				'operator' => $r['operator'],
				'value'    => $r['value'],
				'actual'   => is_array( $actual ) ? implode( ', ', array_map( 'strval', array_filter( $actual, 'is_scalar' ) ) ) : ( null === $actual ? null : (string) $actual ),
				'result'   => ConditionEngine::evaluate_rule( $r, $ctx['data'], $ctx['types'] ),
			);
		}
		return array( 'matched' => $matched, 'rules' => $rules );
	}

	/**
	 * Test run: conditions + what each action WOULD do. No side effects,
	 * no email, no cloud call, no entry change (WF10).
	 */
	public static function dry_run( array $def, ?array $entry, array $form ): array {
		if ( null === $entry ) {
			$entry = self::sample_entry( $form );
		}
		$evaluation = self::evaluate( $def, $entry, $form );
		$ctx        = array(
			'run_id'        => 0,
			'workflow_id'   => (int) $def['id'],
			'workflow_name' => (string) $def['name'],
			'lineage'       => '',
			'entry'         => $entry,
			'form'          => $form,
			'trigger'       => $def['trigger'],
			'dry_run'       => true,
		);
		$planned = array();
		foreach ( $def['actions'] as $a ) {
			$action    = ActionRegistry::get( $a['type'] );
			$planned[] = array(
				'key'         => $a['key'],
				'type'        => $a['type'],
				'label'       => $action ? $action->label() : $a['type'],
				'description' => $action ? $action->describe( is_array( $a['settings'] ) ? $a['settings'] : array(), $ctx ) : __( 'Unknown action type', 'neural-forms-lite' ),
				'available'   => (bool) $action,
			);
		}
		$ctx_eval = WorkflowDefinition::evaluation_context( $entry, $form );
		return array(
			'entry_id'        => (int) ( $entry['id'] ?? 0 ),
			'sample'          => empty( $entry['id'] ),
			'values'          => $ctx_eval['data'],
			'matched'         => $evaluation['matched'],
			'conditions'      => $evaluation['rules'],
			'planned_actions' => $planned,
			'would_run'       => $evaluation['matched'] ? $planned : array(),
		);
	}

	/** A plausible entry for dry runs when no real entry is chosen. */
	private static function sample_entry( array $form ): array {
		$data = array();
		foreach ( \NeuralForms\Forms\SchemaHelper::flatten_fields( is_array( $form['form_schema']['fields'] ?? null ) ? $form['form_schema']['fields'] : array() ) as $f ) {
			$id   = (string) ( $f['id'] ?? '' );
			$type = (string) ( $f['type'] ?? 'text' );
			if ( $id === '' ) {
				continue;
			}
			switch ( $type ) {
				case 'email':
					$data[ $id ] = 'sample@example.com';
					break;
				case 'number':
				case 'range':
				case 'rating':
					$data[ $id ] = '5';
					break;
				case 'checkbox':
				case 'acceptance':
					$data[ $id ] = '1';
					break;
				case 'select':
				case 'radio':
					$opts        = is_array( $f['options'] ?? null ) ? $f['options'] : array();
					$data[ $id ] = isset( $opts[0] ) ? (string) ( $opts[0]['value'] ?? $opts[0]['label'] ?? '' ) : '';
					break;
				case 'checkboxes':
					$opts        = is_array( $f['options'] ?? null ) ? $f['options'] : array();
					$data[ $id ] = isset( $opts[0] ) ? array( (string) ( $opts[0]['value'] ?? $opts[0]['label'] ?? '' ) ) : array();
					break;
				default:
					$data[ $id ] = 'Sample ' . ( $f['label'] ?? $id );
			}
		}
		return array(
			'id'          => 0,
			'form_id'     => (int) ( $form['id'] ?? 0 ),
			'data'        => $data,
			'status'      => 'new',
			'lead_stage'  => 'new',
			'assigned_to' => null,
			'ai_category' => 'sales',
			'ai_priority' => 'high',
			'ai_score'    => 80,
			'ai_is_spam'  => 0,
		);
	}
}
