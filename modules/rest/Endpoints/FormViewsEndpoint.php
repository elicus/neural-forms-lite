<?php
namespace NeuralForms\Rest\Endpoints;

use NeuralForms\Analytics\FormViews;
use NeuralForms\Forms\FormRepository;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Form views (1.3.0).
 *
 *   POST /forms/{id}/view                 public; form-submit.js counts one view
 *   GET  /forms/{id}/analytics/views      admin; views, submissions, conversion, trend
 *
 * The public route only ever adds 1 to today's counter of an ACTIVE form.
 * It stores nothing about the visitor. A loose per-IP cap (the IP is only
 * hashed into a 10-minute transient key) keeps a script from inflating
 * the numbers; the browser already counts once per session per form.
 */
class FormViewsEndpoint {

	/** Counted views per IP per form per window. */
	public const IP_CAP = 30;

	private const RL_PREFIX = 'neural_forms_view_rl_';

	public function register_routes(): void {
		$ns = \NeuralForms\Rest\RestAPI::NAMESPACE;

		register_rest_route(
			$ns,
			'/forms/(?P<id>\d+)/view',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'record_view' ),
				'permission_callback' => '__return_true',
				'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
			)
		);

		register_rest_route(
			$ns,
			'/forms/(?P<id>\d+)/analytics/views',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_views' ),
				'permission_callback' => static function (): bool {
					return current_user_can( NEURAL_FORMS_CAP_MANAGE_FORMS );
				},
				'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
			)
		);
	}

	/**
	 * Count one view. Always answers 204 for an active form, counted or
	 * not, so the response says nothing about the cap.
	 */
	public function record_view( WP_REST_Request $request ) {
		$form_id = (int) $request['id'];
		$form    = FormRepository::find( $form_id );
		if ( ! $form || ( $form['status'] ?? '' ) !== 'active' ) {
			return new WP_Error( 'form_not_found', __( 'Form not found.', 'neural-forms-lite' ), array( 'status' => 404 ) );
		}

		if ( ! self::over_cap( ( new SubmitEndpoint() )->resolve_ip(), $form_id ) ) {
			FormViews::record( $form_id );
		}

		$response = new WP_REST_Response( null, 204 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	public function get_views( WP_REST_Request $request ) {
		$form_id = (int) $request['id'];
		if ( ! FormRepository::find( $form_id ) ) {
			return new WP_Error( 'form_not_found', __( 'Form not found.', 'neural-forms-lite' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( FormViews::summary( $form_id ) );
	}

	/**
	 * True once an IP has had IP_CAP views counted for a form in the last
	 * 10 minutes. The IP never leaves this hash.
	 */
	public static function over_cap( string $ip, int $form_id ): bool {
		$key   = self::RL_PREFIX . md5( wp_salt( 'nonce' ) . '|' . $form_id . '|' . ( $ip === '' ? 'unknown' : $ip ) );
		$count = (int) get_transient( $key );
		if ( $count >= self::IP_CAP ) {
			return true;
		}
		set_transient( $key, $count + 1, 10 * MINUTE_IN_SECONDS );
		return false;
	}
}
