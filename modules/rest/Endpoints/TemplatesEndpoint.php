<?php
namespace NeuralForms\Rest\Endpoints;

use NeuralForms\Rest\RestAPI;
use NeuralForms\Templates\TemplateLibrary;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * REST routes for the remote template library.
 *
 *   GET  /templates              List the catalog (Gutenberg-only, cached).
 *   POST /templates/import       Import one template by manifest URL.
 *
 * Both routes are capability-gated on `neural_forms_manage_forms` —
 * the same cap that controls the rest of the Forms / Entries admin —
 * so a contributor-level user can't reach them.
 */
class TemplatesEndpoint {

	public function register_routes(): void {
		$ns = RestAPI::NAMESPACE;

		register_rest_route( $ns, '/templates', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'list_templates' ],
			'permission_callback' => [ $this, 'check_permission' ],
			'args'                => [
				// Optional `refresh=1` busts the 6-hour catalog cache.
				// Not used by the MVP UI but exposed for future
				// "Refresh catalog" buttons / cron warmers.
				'refresh' => [
					'type'              => 'boolean',
					'required'          => false,
					'default'           => false,
					'sanitize_callback' => 'rest_sanitize_boolean',
				],
			],
		] );

		register_rest_route( $ns, '/templates/import', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'import_template' ],
			'permission_callback' => [ $this, 'check_permission' ],
			'args'                => [
				'manifest_url' => [
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'esc_url_raw',
				],
				'template_id' => [
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_key',
				],
			],
		] );
	}

	/**
	 * GET /templates
	 *
	 * @param WP_REST_Request $request
	 * @return array|WP_Error
	 */
	public function list_templates( WP_REST_Request $request ) {
		$bypass = (bool) $request->get_param( 'refresh' );
		return TemplateLibrary::fetch_catalog( $bypass );
	}

	/**
	 * POST /templates/import
	 *
	 * @param WP_REST_Request $request
	 * @return array|WP_Error
	 */
	public function import_template( WP_REST_Request $request ) {
		$manifest_url = (string) $request->get_param( 'manifest_url' );
		if ( $manifest_url === '' ) {
			return new WP_Error(
				'template_missing_manifest',
				__( 'manifest_url is required.', 'neural-forms-lite' ),
				[ 'status' => 400 ]
			);
		}
		return TemplateLibrary::import_template( $manifest_url );
	}

	/**
	 * Capability gate. Mirrors FormsEndpoint::check_permission so the
	 * "permission_denied" error code is identical across the
	 * namespace.
	 *
	 * @return true|WP_Error
	 */
	public function check_permission() {
		if ( current_user_can( NEURAL_FORMS_CAP_MANAGE_FORMS ) ) {
			return true;
		}
		return new WP_Error(
			'permission_denied',
			'You do not have permission to import templates.',
			[ 'status' => 403 ]
		);
	}
}
