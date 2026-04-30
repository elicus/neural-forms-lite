<?php
namespace NeuralForms\Rest\Endpoints;

use NeuralForms\Core\Logger;
use NeuralForms\Forms\FormRepository;
use NeuralForms\Forms\FormValidator;
use WP_REST_Request;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * REST routes for form CRUD.
 *
 * Base: /wp-json/neural-forms/v1/forms
 *
 * GET    /forms           '->-> list all forms (paginated)
 * GET    /forms/{id}      '->-> get single form
 * POST   /forms           '->-> create form
 * PUT    /forms/{id}      '->-> update form
 * DELETE /forms/{id}      '->-> delete form
 *
 * All routes require the neural_forms_manage_forms capability.
 *
 * Error codes used in this endpoint:
 *   form_not_found      - requested form ID does not exist
 *   invalid_form_data   - schema failed structural validation
 *   form_create_failed  - DB insert returned false
 *   permission_denied   - capability check failed
 */
class FormsEndpoint {

    public function register_routes(): void {
        $ns = \NeuralForms\Rest\RestAPI::NAMESPACE;

        register_rest_route( $ns, '/forms', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_forms' ],
                'permission_callback' => [ $this, 'check_permission' ],
                'args'                => [
                    'status'   => [
                        'default'           => 'any',
                        // Repository accepts 'any', a single status, a CSV
                        // ('active,draft'), or an array - pass through; it
                        // normalizes and silently drops invalid values.
                        'sanitize_callback' => function ( $value ) {
                            if ( is_array( $value ) ) {
                                return array_map( 'sanitize_text_field', $value );
                            }
                            return sanitize_text_field( (string) $value );
                        },
                    ],
                    'per_page' => [
                        'default'           => 20,
                        'sanitize_callback' => 'absint',
                    ],
                    'page'     => [
                        'default'           => 1,
                        'sanitize_callback' => 'absint',
                    ],
                    'orderby'  => [
                        'default'           => 'created_at',
                        'sanitize_callback' => 'sanitize_key',
                    ],
                    'order'    => [
                        'default'           => 'DESC',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'create_form' ],
                'permission_callback' => [ $this, 'check_permission' ],
                'args'                => $this->form_body_args( true ),
            ],
        ] );

        register_rest_route( $ns, '/forms/(?P<id>\d+)/restore', [
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'restore_form' ],
                'permission_callback' => [ $this, 'check_permission' ],
            ],
        ] );

        register_rest_route( $ns, '/forms/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_form' ],
                'permission_callback' => [ $this, 'check_permission' ],
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [ $this, 'update_form' ],
                'permission_callback' => [ $this, 'check_permission' ],
                'args'                => $this->form_body_args( false ),
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ $this, 'delete_form' ],
                'permission_callback' => [ $this, 'check_permission' ],
                'args'                => [
                    // ?force=true '->-> hard delete (drops the row + cascades).
                    // Default soft-delete flips status to 'archived' so the
                    // form and its entries stay recoverable.
                    'force' => [
                        'default'           => false,
                        'sanitize_callback' => 'rest_sanitize_boolean',
                    ],
                ],
            ],
        ] );
    }

    // Handlers

    public function get_forms( WP_REST_Request $request ) {
        $result = FormRepository::list( [
            'status'   => $request->get_param( 'status' ),
            'per_page' => $request->get_param( 'per_page' ),
            'page'     => $request->get_param( 'page' ),
            'orderby'  => $request->get_param( 'orderby' ),
            'order'    => $request->get_param( 'order' ),
        ] );

        $response = rest_ensure_response( $result );
        $response->header( 'X-Total-Count', $result['total'] );

        return $response;
    }

    public function get_form( WP_REST_Request $request ) {
        $form = FormRepository::find( (int) $request['id'] );

        if ( ! $form ) {
            return new WP_Error(
                'form_not_found',
                'Form not found.',
                [ 'status' => 404 ]
            );
        }

        return rest_ensure_response( $form );
    }

    public function create_form( WP_REST_Request $request ) {
        $name = trim( (string) $request->get_param( 'name' ) );
        if ( $name === '' ) {
            return new WP_Error(
                'form_name_required',
                __( 'Form name is required.', 'neural-forms-lite' ),
                [ 'status' => 400, 'field' => 'name' ]
            );
        }

        // Treat an empty-string slug the same as a missing slug: derive from
        // the name. The new builder sends `slug: ""` for fresh forms because
        // the user hasn't picked one yet; without this, every "Save" on a
        // brand-new form would 400 with "slug required".
        $slug_param = $request->get_param( 'slug' );
        $slug_seed  = ( is_string( $slug_param ) && $slug_param !== '' ) ? $slug_param : $name;
        $slug       = sanitize_title( (string) $slug_seed );
        if ( $slug === '' ) {
            return new WP_Error(
                'form_slug_required',
                __( 'Form slug is required. Use letters, numbers, or hyphens.', 'neural-forms-lite' ),
                [ 'status' => 400, 'field' => 'slug' ]
            );
        }

        $schema = $request->get_param( 'form_schema' );

        $validation = FormValidator::validate_schema( $schema );
        if ( is_wp_error( $validation ) ) {
            return $validation;
        }

        $id = FormRepository::create( [
            'name'        => $name,
            'slug'        => $slug,
            'description' => $request->get_param( 'description' ),
            'form_schema' => $schema,
            'settings'    => $request->get_param( 'settings' ) ?? [],
            'status'      => $request->get_param( 'status' ) ?? 'active',
        ] );

        if ( ! $id ) {
            global $wpdb;
            // Log the raw DB error for diagnosis but do not return it in the
            // response - exposes table prefix + schema details even to admins.
            Logger::file(
                'storage',
                'error',
                sprintf( 'FormsEndpoint::create_form() insert failed: %s', $wpdb->last_error ?: '(no wpdb error)' )
            );

            return new WP_Error(
                'form_create_failed',
                __( 'Could not create form. Please try again.', 'neural-forms-lite' ),
                [ 'status' => 500 ]
            );
        }

        $response = rest_ensure_response( FormRepository::find( $id ) );
        $response->set_status( 201 );

        return $response;
    }

    public function update_form( WP_REST_Request $request ) {
        $id = (int) $request['id'];

        if ( ! FormRepository::find( $id ) ) {
            return new WP_Error(
                'form_not_found',
                'Form not found.',
                [ 'status' => 404 ]
            );
        }

        $raw = $request->get_params();

        // Reject explicit empty name/slug on update - an empty string here
        // would silently produce a broken record. Unsent keys are fine.
        if ( array_key_exists( 'name', $raw ) && trim( (string) $raw['name'] ) === '' ) {
            return new WP_Error(
                'form_name_required',
                __( 'Form name is required.', 'neural-forms-lite' ),
                [ 'status' => 400, 'field' => 'name' ]
            );
        }

        if ( array_key_exists( 'slug', $raw ) && sanitize_title( (string) $raw['slug'] ) === '' ) {
            return new WP_Error(
                'form_slug_required',
                __( 'Form slug is required. Use letters, numbers, or hyphens.', 'neural-forms-lite' ),
                [ 'status' => 400, 'field' => 'slug' ]
            );
        }

        // Validate schema only when it is explicitly sent in this request.
        $schema = $request->get_param( 'form_schema' );
        if ( $schema !== null ) {
            $validation = FormValidator::validate_schema( $schema );
            if ( is_wp_error( $validation ) ) {
                return $validation;
            }
        }

        // Use array_key_exists so an explicit null description clears the field.
        $data = array_filter(
            [
                'name'        => $request->get_param( 'name' ),
                'slug'        => $request->get_param( 'slug' ),
                'form_schema' => $schema,
                'settings'    => $request->get_param( 'settings' ),
                'status'      => $request->get_param( 'status' ),
            ],
            fn( $v ) => $v !== null
        );

        if ( array_key_exists( 'description', $raw ) ) {
            $data['description'] = $request->get_param( 'description' );
        }

        FormRepository::update( $id, $data );

        return rest_ensure_response( FormRepository::find( $id ) );
    }

    public function restore_form( WP_REST_Request $request ) {
        $id = (int) $request['id'];

        $form = FormRepository::find( $id );
        if ( ! $form ) {
            return new WP_Error(
                'form_not_found',
                'Form not found.',
                [ 'status' => 404 ]
            );
        }

        if ( $form['status'] !== 'archived' ) {
            return new WP_Error(
                'form_not_archived',
                __( 'Only archived forms can be restored.', 'neural-forms-lite' ),
                [ 'status' => 409 ]
            );
        }

        $next = FormRepository::restore( $id );
        if ( $next === false ) {
            return new WP_Error(
                'form_restore_failed',
                __( 'Could not restore the form.', 'neural-forms-lite' ),
                [ 'status' => 500 ]
            );
        }

        return rest_ensure_response( FormRepository::find( $id ) );
    }

    public function delete_form( WP_REST_Request $request ) {
        $id = (int) $request['id'];

        $form = FormRepository::find( $id );
        if ( ! $form ) {
            return new WP_Error(
                'form_not_found',
                'Form not found.',
                [ 'status' => 404 ]
            );
        }

        $force = (bool) $request->get_param( 'force' );

        FormRepository::delete( $id, $force );

        // Soft-delete returns the now-archived form so the client can update
        // its row in place; hard-delete returns 204 since the row is gone.
        if ( $force ) {
            $response = rest_ensure_response( null );
            $response->set_status( 204 );
            return $response;
        }

        return rest_ensure_response( FormRepository::find( $id ) );
    }

    // Permission

    /**
     * Returns true if the current user has the required capability,
     * or a WP_Error so the response uses our own error code instead of
     * WordPress's default 'rest_forbidden'.
     *
     * @return true|\WP_Error
     */
    public function check_permission() {
        if ( current_user_can( NEURAL_FORMS_CAP_MANAGE_FORMS ) ) {
            return true;
        }

        return new WP_Error(
            'permission_denied',
            'You do not have permission to manage forms.',
            [ 'status' => 403 ]
        );
    }

    // Arg definitions

    /**
     * Shared body args for POST and PUT.
     * form_schema shape validation is handled in the handler via FormValidator,
     * not here - args only check that the value is an array.
     *
     * @param bool $required True for POST (create), false for PUT (update).
     */
    private function form_body_args( bool $required ): array {
        return [
            'name'        => [
                'required'          => $required,
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'slug'        => [
                'required'          => false,
                // sanitize_title is a 3-arg function (title, fallback, context).
                // Used as a bare sanitize_callback, WordPress invokes it as
                // sanitize_title($value, $request, $param) — the WP_REST_Request
                // leaks into $fallback_title, and an empty $value causes
                // sanitize_title to RETURN the request object. Downstream
                // callers that do `(string) $slug` then fatal with "Object of
                // class WP_REST_Request could not be converted to string".
                // Wrap it so only the value is forwarded.
                'sanitize_callback' => static fn( $v ) => sanitize_title( (string) $v ),
            ],
            'description' => [
                'required'          => false,
                'sanitize_callback' => 'sanitize_textarea_field',
            ],
            'form_schema' => [
                'required'          => $required,
                'validate_callback' => fn( $v ) => is_array( $v ),
            ],
            'settings'    => [
                'required'          => false,
                'validate_callback' => fn( $v ) => is_array( $v ),
            ],
            'status'      => [
                'required'          => false,
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => fn( $v ) => in_array( $v, [ 'draft', 'active', 'archived' ], true ),
            ],
        ];
    }
}
