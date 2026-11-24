<?php
namespace NeuralForms\Rest\Endpoints;

use NeuralForms\Analytics\FormFunnel;
use NeuralForms\Core\Capabilities;
use NeuralForms\Forms\FormRepository;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Form drop-off (1.3.0).
 *
 *   POST /forms/{id}/funnel               public; form-submit.js records started / step / quit
 *   GET  /forms/{id}/analytics/dropoff    admin + `form_dropoff_analytics` cap; the report
 *
 * Recording is free (like page-source capture) so an upgrade shows the
 * history at once; only the report is Pro. The public route answers 204
 * for every active form, stores only a counter, and drops ids that are not
 * in the form's current schema. A per-IP cap (IP hashed into a 10-minute
 * transient key) keeps a script from flooding the counters.
 *
 * Body (form-encoded, from sendBeacon): kind = started | step | quit | unquit,
 * node (step), field (quit / unquit).
 */
class FormFunnelEndpoint {

	/** Recorded events per IP per form per window. */
	public const IP_CAP = 60;

	private const RL_PREFIX = 'neural_forms_funnel_rl_';

	public function register_routes(): void {
		$ns = \NeuralForms\Rest\RestAPI::NAMESPACE;

		register_rest_route(
			$ns,
			'/forms/(?P<id>\d+)/funnel',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'record' ),
				'permission_callback' => '__return_true',
				'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
			)
		);

		register_rest_route(
			$ns,
			'/forms/(?P<id>\d+)/analytics/dropoff',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_report' ),
				'permission_callback' => static function (): bool {
					return current_user_can( NEURAL_FORMS_CAP_MANAGE_FORMS );
				},
				'args'                => array(
					'id'   => array( 'sanitize_callback' => 'absint' ),
					'days' => array( 'sanitize_callback' => 'absint' ),
				),
			)
		);
	}

	public function record( WP_REST_Request $request ) {
		$form_id = (int) $request['id'];
		$form    = FormRepository::find( $form_id );
		if ( ! $form || ( $form['status'] ?? '' ) !== 'active' ) {
			return new WP_Error( 'form_not_found', __( 'Form not found.', 'neural-forms-lite' ), array( 'status' => 404 ) );
		}

		$event = self::parse( $form, (string) $request->get_param( 'kind' ), (string) $request->get_param( 'node' ), (string) $request->get_param( 'field' ) );
		if ( $event && ! FormViewsEndpoint::over_cap( ( new SubmitEndpoint() )->resolve_ip(), $form_id, self::RL_PREFIX, self::IP_CAP ) ) {
			FormFunnel::bump( $form_id, $event['kind'], $event['node'], $event['delta'] );
		}

		$response = new WP_REST_Response( null, 204 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Turn a beacon into one counter change, or null to ignore it.
	 *
	 * @return array{ kind: string, node: string, delta: int }|null
	 */
	public static function parse( array $form, string $kind, string $node, string $field ): ?array {
		switch ( $kind ) {
			case 'started':
				return array( 'kind' => 'started', 'node' => '', 'delta' => 1 );
			case 'step':
				return FormFunnel::is_known( $form, $node ) ? array( 'kind' => 'step', 'node' => $node, 'delta' => 1 ) : null;
			case 'quit':
			case 'unquit':
				return FormFunnel::is_known( $form, $field )
					? array( 'kind' => 'quit_field', 'node' => $field, 'delta' => $kind === 'quit' ? 1 : -1 )
					: null;
		}
		return null;
	}

	public function get_report( WP_REST_Request $request ) {
		if ( ! Capabilities::can( Capabilities::FORM_DROPOFF_ANALYTICS ) ) {
			return Capabilities::locked_error( Capabilities::FORM_DROPOFF_ANALYTICS );
		}
		$form = FormRepository::find( (int) $request['id'] );
		if ( ! $form ) {
			return new WP_Error( 'form_not_found', __( 'Form not found.', 'neural-forms-lite' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( FormFunnel::report( $form, (int) $request->get_param( 'days' ) ) );
	}
}
