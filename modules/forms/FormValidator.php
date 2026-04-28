<?php
namespace NeuralForms\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Validates form schemas.
 *
 * Responsibility: confirm that a schema array is structurally sound before
 * it is stored. This is NOT submission validation (that comes in Step 9).
 *
 * Returns true on success, WP_Error on failure — so callers can pass
 * the result straight back through the REST response.
 *
 * Rules enforced:
 *  1. 'fields' key exists and is a non-empty array.
 *  2. Each field has a non-empty 'id'.
 *  3. Each field has a 'type' that is registered in FieldRegistry.
 *  4. Field IDs are unique within the schema.
 */
class FormValidator {

    /**
     * Valid field ID pattern: lowercase letters, digits, and underscores only.
     *
     * Enforced so IDs are safe to use as:
     *   - DB column aliases
     *   - JavaScript object keys
     *   - Merge tags  e.g. {{first_name}}
     *   - CSS selectors  e.g. #field_first_name
     */
    const FIELD_ID_PATTERN = '/^[a-z0-9_]+$/';

    /**
     * @param array $schema  The decoded form_schema array.
     * @return true|\WP_Error
     */
    public static function validate_schema( array $schema ) {
        // Rule 1 — fields key must exist and be a non-empty array.
        if ( ! isset( $schema['fields'] ) || ! is_array( $schema['fields'] ) ) {
            return new \WP_Error(
                'invalid_form_data',
                'Schema must contain a fields array.',
                [ 'status' => 422 ]
            );
        }

        if ( empty( $schema['fields'] ) ) {
            return new \WP_Error(
                'invalid_form_data',
                'Schema must contain at least one field.',
                [ 'status' => 422 ]
            );
        }

        $seen_ids = [];

        foreach ( $schema['fields'] as $index => $field ) {
            $field = (array) $field;

            // Rule 2 — each field must have a non-empty id.
            if ( empty( $field['id'] ) ) {
                return new \WP_Error(
                    'invalid_form_data',
                    sprintf( 'Field at index %d is missing an id.', $index ),
                    [ 'status' => 422 ]
                );
            }

            $id = (string) $field['id'];

            // Rule 3 — id must match the allowed format: a-z, 0-9, _ only.
            if ( ! preg_match( self::FIELD_ID_PATTERN, $id ) ) {
                return new \WP_Error(
                    'invalid_form_data',
                    sprintf(
                        'Field id "%s" is invalid. Only lowercase letters (a-z), digits (0-9), and underscores (_) are allowed.',
                        $id
                    ),
                    [ 'status' => 422 ]
                );
            }

            // Rule 4 — type must be registered in FieldRegistry.
            if ( empty( $field['type'] ) ) {
                return new \WP_Error(
                    'invalid_form_data',
                    sprintf( 'Field "%s" is missing a type.', $id ),
                    [ 'status' => 422 ]
                );
            }

            if ( ! FieldRegistry::is_registered( $field['type'] ) ) {
                return new \WP_Error(
                    'invalid_form_data',
                    sprintf(
                        'Field "%s" has unregistered type "%s". Registered types: %s.',
                        $id,
                        $field['type'],
                        implode( ', ', FieldRegistry::get_types() )
                    ),
                    [ 'status' => 422 ]
                );
            }

            // Rule 5 — IDs must be unique within the schema.
            if ( in_array( $id, $seen_ids, true ) ) {
                return new \WP_Error(
                    'invalid_form_data',
                    sprintf( 'Duplicate field id "%s". Each field must have a unique id.', $id ),
                    [ 'status' => 422 ]
                );
            }

            $seen_ids[] = $id;

            // Rule 6 — Extension hook.
            // Fires after all core rules pass for this field.
            // Extensions return null to pass, or a WP_Error to fail.
            //
            // Usage:
            //   add_filter( 'neural_forms_validate_field', function( $error, $field ) {
            //       if ( $field['type'] === 'select' && empty( $field['options'] ) ) {
            //           return new WP_Error( 'invalid_form_data', 'Select fields must have options.', ['status' => 422] );
            //       }
            //       return $error; // pass through
            //   }, 10, 2 );
            $extra = apply_filters( 'neural_forms_validate_field', null, $field );

            if ( is_wp_error( $extra ) ) {
                return $extra;
            }
        }

        return true;
    }
}
