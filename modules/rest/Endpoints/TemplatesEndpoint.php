<?php
namespace NeuralForms\Rest\Endpoints;

use NeuralForms\Rest\RestAPI;
use NeuralForms\Templates\TemplateLibrary;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * REST routes for the local template library.
 *
 *   GET  /templates              List the catalog (per builder).
 *   POST /templates/import       Import one template by (builder, template_id).
 *
 * Both routes are capability-gated on `neural_forms_manage_forms` —
 * the same cap that controls the rest of the Forms / Entries admin —
 * so a contributor-level user can't reach them.
 *
 * The library now reads from the plugin's local `templates/` directory
 * (and any directories Pro / extension plugins register via the
 * `neural_forms_template_library_roots` filter). No remote HTTP is
 * involved at all — the `refresh` query arg is preserved for API
 * stability but is a no-op.
 */
class TemplatesEndpoint {

	public function register_routes(): void {
		$ns = RestAPI::NAMESPACE;

		register_rest_route(
			$ns,
			'/templates',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_templates' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					// Preserved for API stability — local file reads
					// are cheap so there's no cache to refresh, but
					// older clients may still send the param.
					'refresh' => array(
						'type'              => 'boolean',
						'required'          => false,
						'default'           => false,
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
					// Builder slug. Lite ships block-editor templates;
					// Pro / other extensions register additional
					// builders via the roots filter.
					'builder' => array(
						'type'              => 'string',
						'required'          => false,
						'default'           => 'block-editor',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/templates/import',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'import_template' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'builder'     => array(
						'type'              => 'string',
						'required'          => false,
						'default'           => 'block-editor',
						'sanitize_callback' => 'sanitize_key',
					),
					'template_id' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
					// Accepted for backward-shape compatibility with
					// the previous remote-URL API but ignored — the
					// server resolves the file path from template_id
					// via the local catalog, so a tampered client
					// payload can never be used for path traversal.
					'import_url'  => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * GET /templates
	 *
	 * @param WP_REST_Request $request
	 * @return array|WP_Error
	 */
	public function list_templates( WP_REST_Request $request ) {
		$bypass  = (bool) $request->get_param( 'refresh' );
		$builder = (string) $request->get_param( 'builder' );
		if ( '' === $builder ) {
			$builder = 'block-editor';
		}
		return TemplateLibrary::fetch_catalog( $bypass, $builder );
	}

	/**
	 * POST /templates/import
	 *
	 * @param WP_REST_Request $request
	 * @return array|WP_Error
	 */
	public function import_template( WP_REST_Request $request ) {
		$builder     = (string) $request->get_param( 'builder' );
		$template_id = (string) $request->get_param( 'template_id' );
		if ( '' === $builder ) {
			$builder = 'block-editor';
		}
		if ( '' === $template_id ) {
			return new WP_Error(
				'template_missing_id',
				__( 'template_id is required.', 'neural-forms-lite' ),
				array( 'status' => 400 )
			);
		}
		return TemplateLibrary::import_template( $builder, $template_id );
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
			array( 'status' => 403 )
		);
	}
}
