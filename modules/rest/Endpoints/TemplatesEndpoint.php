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

		register_rest_route(
			$ns,
			'/templates',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_templates' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					// Optional `refresh=1` busts the 6-hour catalog cache.
					// Not used by the MVP UI but exposed for future
					// "Refresh catalog" buttons / cron warmers.
					'refresh' => array(
						'type'              => 'boolean',
						'required'          => false,
						'default'           => false,
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
					// Builder slug. The React UI sends 'block-editor'
					// (matching the new remote catalog path). Older
					// clients can still pass 'gutenberg' — TemplateLibrary
					// rewrites that to 'block-editor' for the request.
					// Anything else is rejected upstream.
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
					// import_url points at one self-contained template
					// bundle JSON (page + forms inline). Older versions
					// of the spec used `manifest_url` for a multi-file
					// fetch chain; for MVP only the bundle path is
					// supported, but the parameter name keeps the door
					// open for a future Pro feature that lets sites
					// import from an arbitrary bundle URL.
					'import_url'  => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'esc_url_raw',
					),
					'template_id' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_key',
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
		$import_url = (string) $request->get_param( 'import_url' );
		if ( $import_url === '' ) {
			return new WP_Error(
				'template_missing_import_url',
				__( 'import_url is required.', 'neural-forms-lite' ),
				array( 'status' => 400 )
			);
		}

		$result = TemplateLibrary::import_template( $import_url );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Record the import keyed by template_id so the next
		// GET /templates can flip `imported: true` on the matching
		// row and the React card shows the "Imported" pill with a
		// link back to the page we just created. template_id is
		// optional in the request schema for backward compat — older
		// React bundles may not send it — but the catalog can't link
		// the import back to a row without it.
		$template_id = (string) $request->get_param( 'template_id' );
		$page_id     = isset( $result['page_id'] ) ? (int) $result['page_id'] : 0;
		$edit_url    = isset( $result['edit_url'] ) ? (string) $result['edit_url'] : '';
		if ( $template_id !== '' && $page_id > 0 ) {
			TemplateLibrary::record_import( $template_id, $page_id, $edit_url );
		}

		return $result;
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
