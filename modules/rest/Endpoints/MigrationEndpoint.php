<?php
namespace NeuralForms\Rest\Endpoints;

use NeuralForms\Migration\CF7Detector;
use NeuralForms\Migration\CF7Migrator;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * REST endpoints for CF7 → Neural Forms migration.
 *
 *   GET  /migration/cf7/forms               — list all CF7 forms with compatibility info
 *   GET  /migration/cf7/preview/(?P<id>\d+) — preview what the import would produce
 *   POST /migration/cf7/import/(?P<id>\d+)  — execute the import
 */
class MigrationEndpoint {

	public function register_routes(): void {
		$ns = \NeuralForms\Rest\RestAPI::NAMESPACE;

		register_rest_route( $ns, '/migration/cf7/forms', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_forms' ],
			'permission_callback' => [ $this, 'check_permission' ],
		] );

		register_rest_route( $ns, '/migration/cf7/preview/(?P<id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'preview' ],
			'permission_callback' => [ $this, 'check_permission' ],
			'args'                => [
				'id' => [
					'required'          => true,
					'validate_callback' => function ( $val ) {
						return is_numeric( $val ) && (int) $val > 0;
					},
					'sanitize_callback' => 'absint',
				],
			],
		] );

		register_rest_route( $ns, '/migration/cf7/import/(?P<id>\d+)', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'import' ],
			'permission_callback' => [ $this, 'check_permission' ],
			'args'                => [
				'id' => [
					'required'          => true,
					'validate_callback' => function ( $val ) {
						return is_numeric( $val ) && (int) $val > 0;
					},
					'sanitize_callback' => 'absint',
				],
				'force_import' => [
					'type'              => 'boolean',
					'default'           => false,
					'sanitize_callback' => 'rest_sanitize_boolean',
				],
			],
		] );
	}

	public function check_permission(): bool {
		return current_user_can( NEURAL_FORMS_CAP_MANAGE_FORMS );
	}

	/**
	 * GET /migration/cf7/forms
	 */
	public function get_forms( WP_REST_Request $request ) {
		if ( ! CF7Detector::is_cf7_active() ) {
			return rest_ensure_response( [
				'available' => false,
				'forms'     => [],
				'message'   => 'Contact Form 7 is not installed or not active.',
			] );
		}

		return rest_ensure_response( [
			'available' => true,
			'forms'     => CF7Detector::get_forms(),
		] );
	}

	/**
	 * GET /migration/cf7/preview/{id}
	 */
	public function preview( WP_REST_Request $request ) {
		$cf7_id = (int) $request->get_param( 'id' );

		$preview = CF7Migrator::preview( $cf7_id );

		if ( $preview === null ) {
			return new \WP_Error(
				'cf7_form_not_found',
				'Contact Form 7 form not found.',
				[ 'status' => 404 ]
			);
		}

		return rest_ensure_response( $preview );
	}

	/**
	 * POST /migration/cf7/import/{id}
	 */
	public function import( WP_REST_Request $request ) {
		$cf7_id = (int) $request->get_param( 'id' );
		$force  = (bool) $request->get_param( 'force_import' );

		$result = CF7Migrator::import( $cf7_id, $force );

		if ( $result === null ) {
			return new \WP_Error(
				'cf7_form_not_found',
				'Contact Form 7 form not found.',
				[ 'status' => 404 ]
			);
		}

		// Duplicate blocked — return 409 with existing import details.
		if ( ! empty( $result['duplicate'] ) ) {
			return new \WP_Error(
				'duplicate_import',
				'This Contact Form 7 form has already been imported.',
				[
					'status'           => 409,
					'existing_imports' => $result['existing_imports'],
					'form_name'        => $result['form_name'],
				]
			);
		}

		if ( ! $result['success'] ) {
			return new \WP_Error(
				'import_failed',
				'Could not import the form. ' . implode( ' ', $result['warnings'] ),
				[ 'status' => 422, 'data' => $result ]
			);
		}

		return rest_ensure_response( $result );
	}
}
