<?php
namespace NeuralForms\Rest\Endpoints;

use NeuralForms\Core\Capabilities;
use NeuralForms\Forms\FormRepository;
use NeuralForms\Storage\EntryRepository;
use NeuralForms\Storage\LeadStages;
use NeuralForms\Workflows\Actions\ActionRegistry;
use NeuralForms\Workflows\WorkflowDefinition;
use NeuralForms\Workflows\WorkflowEngine;
use NeuralForms\Workflows\WorkflowRepository;
use NeuralForms\Workflows\WorkflowRunner;
use NeuralForms\Workflows\WorkflowRunRepository;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Workflows REST surface (1.2.0). All routes need manage_options; every
 * route except /workflows/health also needs the `workflows` capability.
 *
 *   GET    /workflows?form_id=N          list (with run counts)
 *   POST   /workflows                     create
 *   GET    /workflows/meta                triggers, actions, stages, meta sources (editor data)
 *   GET    /workflows/health              queue + cron health (free)
 *   GET    /workflows/{id}                one
 *   PUT    /workflows/{id}                replace (revision + 1)
 *   DELETE /workflows/{id}
 *   POST   /workflows/{id}/test           dry run { entry_id? }
 *   GET    /workflows/{id}/runs           history
 *   POST   /workflows/{id}/cancel-pending
 *   POST   /workflow-actions/{id}/retry   manual retry of a failed/uncertain action
 */
final class WorkflowsEndpoint {

	public function register_routes(): void {
		$ns = 'neural-forms/v1';
		register_rest_route( $ns, '/workflows', array(
			array( 'methods' => 'GET', 'callback' => array( $this, 'list_workflows' ), 'permission_callback' => array( $this, 'check_permission' ) ),
			array( 'methods' => 'POST', 'callback' => array( $this, 'create_workflow' ), 'permission_callback' => array( $this, 'check_permission' ) ),
		) );
		register_rest_route( $ns, '/workflows/meta', array( 'methods' => 'GET', 'callback' => array( $this, 'meta' ), 'permission_callback' => array( $this, 'check_permission' ) ) );
		register_rest_route( $ns, '/workflows/health', array( 'methods' => 'GET', 'callback' => array( $this, 'health' ), 'permission_callback' => array( $this, 'check_admin' ) ) );
		register_rest_route( $ns, '/workflows/(?P<id>[\d]+)', array(
			array( 'methods' => 'GET', 'callback' => array( $this, 'get_workflow' ), 'permission_callback' => array( $this, 'check_permission' ) ),
			array( 'methods' => 'PUT', 'callback' => array( $this, 'update_workflow' ), 'permission_callback' => array( $this, 'check_permission' ) ),
			array( 'methods' => 'DELETE', 'callback' => array( $this, 'delete_workflow' ), 'permission_callback' => array( $this, 'check_permission' ) ),
		) );
		register_rest_route( $ns, '/workflows/(?P<id>[\d]+)/test', array( 'methods' => 'POST', 'callback' => array( $this, 'test_workflow' ), 'permission_callback' => array( $this, 'check_permission' ) ) );
		register_rest_route( $ns, '/workflows/(?P<id>[\d]+)/runs', array( 'methods' => 'GET', 'callback' => array( $this, 'list_runs' ), 'permission_callback' => array( $this, 'check_permission' ) ) );
		register_rest_route( $ns, '/workflows/(?P<id>[\d]+)/cancel-pending', array( 'methods' => 'POST', 'callback' => array( $this, 'cancel_pending' ), 'permission_callback' => array( $this, 'check_permission' ) ) );
		register_rest_route( $ns, '/workflow-actions/(?P<id>[\d]+)/retry', array( 'methods' => 'POST', 'callback' => array( $this, 'retry_action' ), 'permission_callback' => array( $this, 'check_permission' ) ) );
	}

	public function check_admin() {
		return current_user_can( 'manage_options' );
	}

	public function check_permission() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		if ( ! Capabilities::can( Capabilities::WORKFLOWS ) ) {
			return Capabilities::locked_error( Capabilities::WORKFLOWS );
		}
		return true;
	}

	// ── handlers ─────────────────────────────────────────────────────

	public function meta( WP_REST_Request $request ) {
		return rest_ensure_response( array(
			'triggers' => array(
				array( 'id' => WorkflowDefinition::TRIGGER_SUBMISSION, 'label' => __( 'Submission received', 'neural-forms-lite' ), 'description' => __( 'A valid submission was stored. AI results are not available yet — use "AI analysis completed" for category or priority rules.', 'neural-forms-lite' ) ),
				array( 'id' => WorkflowDefinition::TRIGGER_ANALYSIS, 'label' => __( 'AI analysis completed', 'neural-forms-lite' ), 'description' => __( 'The first successful AI analysis of an entry finished (optionally also on re-analysis).', 'neural-forms-lite' ) ),
				array( 'id' => WorkflowDefinition::TRIGGER_STAGE, 'label' => __( 'Lead stage changed', 'neural-forms-lite' ), 'description' => __( 'The lead moved from one stage to another, by a person or a workflow.', 'neural-forms-lite' ) ),
				array( 'id' => WorkflowDefinition::TRIGGER_OVERDUE, 'label' => __( 'Follow-up overdue', 'neural-forms-lite' ), 'description' => __( 'An open follow-up passed its due time (checked hourly, fires once per due date).', 'neural-forms-lite' ) ),
			),
			'actions'      => ActionRegistry::for_script(),
			'stages'       => LeadStages::for_script(),
			'meta_sources' => array_map(
				static function ( $id, $m ) { return array_merge( array( 'id' => $id ), $m ); },
				array_keys( WorkflowDefinition::meta_sources() ),
				WorkflowDefinition::meta_sources()
			),
			'assignees'    => EntriesEndpoint::assignable_users(),
			'limits'       => array( 'max_actions' => WorkflowDefinition::MAX_ACTIONS, 'max_attempts' => WorkflowDefinition::MAX_ATTEMPTS, 'backoff_minutes' => WorkflowDefinition::BACKOFF_MINUTES ),
		) );
	}

	public function health( WP_REST_Request $request ) {
		return rest_ensure_response( WorkflowEngine::health() );
	}

	public function list_workflows( WP_REST_Request $request ) {
		$form_id = (int) $request->get_param( 'form_id' );
		if ( $form_id <= 0 ) {
			return new WP_Error( 'missing_form', __( 'form_id is required.', 'neural-forms-lite' ), array( 'status' => 400 ) );
		}
		$items = WorkflowRepository::list_for_form( $form_id );
		foreach ( $items as &$w ) {
			$w['runs'] = WorkflowRunRepository::count_runs( (int) $w['id'] );
		}
		unset( $w );
		return rest_ensure_response( $items );
	}

	public function get_workflow( WP_REST_Request $request ) {
		$w = WorkflowRepository::find( (int) $request['id'] );
		if ( ! $w ) {
			return $this->not_found();
		}
		$w['runs'] = WorkflowRunRepository::count_runs( (int) $w['id'] );
		return rest_ensure_response( $w );
	}

	public function create_workflow( WP_REST_Request $request ) {
		$def = WorkflowDefinition::normalize( $request->get_json_params() ?: $request->get_params() );
		$form = FormRepository::find( (int) $def['form_id'] );
		if ( ! $form ) {
			return new WP_Error( 'form_not_found', __( 'Form not found.', 'neural-forms-lite' ), array( 'status' => 404 ) );
		}
		$check = WorkflowDefinition::validate( $def, is_array( $form['form_schema'] ?? null ) ? $form['form_schema'] : array() );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$id = WorkflowRepository::create( $def );
		if ( $id <= 0 ) {
			return new WP_Error( 'workflow_create_failed', __( 'Could not save the workflow.', 'neural-forms-lite' ), array( 'status' => 500 ) );
		}
		$w         = WorkflowRepository::find( $id );
		$w['runs'] = WorkflowRunRepository::count_runs( $id );
		$response  = rest_ensure_response( $w );
		$response->set_status( 201 );
		return $response;
	}

	public function update_workflow( WP_REST_Request $request ) {
		$id       = (int) $request['id'];
		$existing = WorkflowRepository::find( $id );
		if ( ! $existing ) {
			return $this->not_found();
		}
		$body = $request->get_json_params() ?: $request->get_params();
		// Partial toggle: { enabled: bool } alone.
		if ( is_array( $body ) && count( array_intersect_key( $body, array_flip( array( 'name', 'trigger', 'actions', 'conditions', 'settings', 'description' ) ) ) ) === 0 && array_key_exists( 'enabled', $body ) ) {
			WorkflowRepository::set_enabled( $id, ! empty( $body['enabled'] ) );
			$w         = WorkflowRepository::find( $id );
			$w['runs'] = WorkflowRunRepository::count_runs( $id );
			return rest_ensure_response( $w );
		}
		$body['form_id'] = $existing['form_id']; // a workflow never moves between forms
		$body['id']      = $id;
		$def  = WorkflowDefinition::normalize( $body );
		$form = FormRepository::find( (int) $existing['form_id'] );
		$check = WorkflowDefinition::validate( $def, is_array( $form['form_schema'] ?? null ) ? $form['form_schema'] : array() );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		if ( ! WorkflowRepository::update( $id, $def ) ) {
			return new WP_Error( 'workflow_update_failed', __( 'Could not save the workflow.', 'neural-forms-lite' ), array( 'status' => 500 ) );
		}
		$w         = WorkflowRepository::find( $id );
		$w['runs'] = WorkflowRunRepository::count_runs( $id );
		return rest_ensure_response( $w );
	}

	public function delete_workflow( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( ! WorkflowRepository::find( $id ) ) {
			return $this->not_found();
		}
		WorkflowRunRepository::cancel_pending( $id );
		WorkflowRepository::delete( $id );
		return rest_ensure_response( array( 'deleted' => true, 'id' => $id ) );
	}

	public function test_workflow( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		$w  = WorkflowRepository::find( $id );
		if ( ! $w ) {
			return $this->not_found();
		}
		// Allow testing an UNSAVED edit: the body may carry a definition override.
		$body = $request->get_json_params() ?: array();
		if ( is_array( $body ) && isset( $body['definition'] ) && is_array( $body['definition'] ) ) {
			$override            = $body['definition'];
			$override['form_id'] = $w['form_id'];
			$override['id']      = $id;
			$w                   = WorkflowDefinition::normalize( $override );
		}
		$form = FormRepository::find( (int) $w['form_id'] );
		if ( ! $form ) {
			return new WP_Error( 'form_not_found', __( 'Form not found.', 'neural-forms-lite' ), array( 'status' => 404 ) );
		}
		$entry    = null;
		$entry_id = (int) ( $body['entry_id'] ?? $request->get_param( 'entry_id' ) );
		if ( $entry_id > 0 ) {
			$entry = EntryRepository::get( $entry_id );
			if ( ! $entry || (int) $entry['form_id'] !== (int) $w['form_id'] ) {
				return new WP_Error( 'entry_not_found', __( 'That entry does not belong to this form.', 'neural-forms-lite' ), array( 'status' => 404 ) );
			}
		}
		return rest_ensure_response( WorkflowRunner::dry_run( $w, $entry, $form ) );
	}

	public function list_runs( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( ! WorkflowRepository::find( $id ) ) {
			return $this->not_found();
		}
		return rest_ensure_response( WorkflowRunRepository::list_runs( $id, (int) ( $request->get_param( 'limit' ) ?: 30 ) ) );
	}

	public function cancel_pending( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( ! WorkflowRepository::find( $id ) ) {
			return $this->not_found();
		}
		return rest_ensure_response( array( 'cancelled' => WorkflowRunRepository::cancel_pending( $id ) ) );
	}

	public function retry_action( WP_REST_Request $request ) {
		$action_id = (int) $request['id'];
		$action    = WorkflowRunRepository::get_action( $action_id );
		if ( ! $action ) {
			return $this->not_found();
		}
		if ( ! WorkflowRunRepository::reopen_action( $action_id ) ) {
			return new WP_Error( 'not_retryable', __( 'Only failed, uncertain or skipped actions can be retried.', 'neural-forms-lite' ), array( 'status' => 409 ) );
		}
		WorkflowEngine::enqueue_run( (int) $action['run_id'] );
		WorkflowEngine::kick_queue();
		return rest_ensure_response( array( 'queued' => true, 'run_id' => (int) $action['run_id'] ) );
	}

	private function not_found(): WP_Error {
		return new WP_Error( 'workflow_not_found', __( 'Workflow not found.', 'neural-forms-lite' ), array( 'status' => 404 ) );
	}
}
