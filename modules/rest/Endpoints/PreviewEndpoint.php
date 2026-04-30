<?php
namespace NeuralForms\Rest\Endpoints;

use NeuralForms\Forms\FormValidator;
use NeuralForms\Frontend\FormRenderer;
use WP_REST_Request;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * POST /neural-forms/v1/forms/preview
 *
 * Renders an in-flight form schema (the unsaved state held by the React
 * builder) to the same HTML that FormRenderer produces on the frontend.
 *
 * Lets the builder's Preview action show a faithful preview without
 * requiring the admin to save first. Admin-only.
 *
 * Request body:
 *   {
 *     "name":          "Optional preview title",
 *     "form_schema":   { "fields": [ ... ] },
 *     "settings":      { "on_success": { ... }, ... } (optional),
 *     "options":       { "show_labels": true, ... } (optional render options)
 *   }
 *
 * Response:
 *   { "html": "<div class=\"neural-forms-form-wrap\">..." }
 */
class PreviewEndpoint {

	public function register_routes(): void {
		register_rest_route(
			\NeuralForms\Rest\RestAPI::NAMESPACE,
			'/forms/preview',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'preview' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => [
					'form_schema' => [
						'required'          => true,
						'validate_callback' => static fn( $v ) => is_array( $v ),
					],
					'settings' => [
						'required'          => false,
						'validate_callback' => static fn( $v ) => is_array( $v ),
					],
					'options' => [
						'required'          => false,
						'validate_callback' => static fn( $v ) => is_array( $v ),
					],
				],
			]
		);
	}

	public function preview( WP_REST_Request $request ) {
		$schema   = $request->get_param( 'form_schema' );
		$settings = $request->get_param( 'settings' ) ?: [];
		$options  = $request->get_param( 'options' )  ?: [];

		// Validate the schema first so a malformed builder state surfaces
		// the same field-level error the user sees on save.
		$validation = FormValidator::validate_schema( is_array( $schema ) ? $schema : [] );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		// Build a synthetic form row that FormRenderer can consume. No DB
		// write happens - this row is constructed in memory and discarded
		// after the response is sent.
		$synthetic = [
			'id'          => 0,
			'name'        => (string) ( $request->get_param( 'name' ) ?: __( 'Preview', 'neural-forms-lite' ) ),
			'status'      => 'active',
			'form_schema' => $schema,
			'settings'    => $settings,
		];

		// FormRenderer's render_preview() bypasses asset enqueuing and the
		// active-status check, exactly what we want for an editor preview.
		$html = FormRenderer::render_preview_form( $synthetic, $options );

		return rest_ensure_response( [ 'html' => $html ] );
	}

	public function check_permission() {
		if ( current_user_can( NEURAL_FORMS_CAP_MANAGE_FORMS ) ) {
			return true;
		}
		return new WP_Error(
			'permission_denied',
			__( 'You do not have permission to preview forms.', 'neural-forms-lite' ),
			[ 'status' => 403 ]
		);
	}
}
