<?php
namespace NeuralForms\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Validates form schemas.
 *
 * Returns true on success, WP_Error on failure - so callers can pass
 * the result straight back through the REST response.
 *
 * Schema shape:
 *   form_schema.fields = [
 *     { id, type, ... },                             // flat field
 *     {                                              // layout row
 *       id, type: 'row',
 *       settings: { columns: 1..4, gap },
 *       columns: [ { id, fields: [ ... ] }, ... ],
 *     },
 *   ]
 *
 * Rules:
 *   1. 'fields' key exists and is a non-empty array.
 *   2. Every field id, row id, and column id matches FIELD_ID_PATTERN.
 *   3. Every field has a type registered in FieldRegistry.
 *   4. Every row has 1..4 columns and at least one column entry.
 *   5. Rows cannot contain other rows.
 *   6. Ids are unique across the whole tree (rows, columns, fields share
 *      one namespace) so callers can use a single id to look anything up.
 */
class FormValidator {

    /**
     * Valid id pattern: lowercase letters, digits, and underscores only.
     *
     * Enforced so ids are safe to use as DB column aliases, JS object
     * keys, merge tags, and CSS selectors.
     */
    const FIELD_ID_PATTERN = '/^[a-z0-9_]+$/';

    /**
     * @param array $schema  The decoded form_schema array.
     * @return true|\WP_Error
     */
    public static function validate_schema( array $schema ) {
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

        return self::validate_nodes( $schema['fields'], $seen_ids, 'fields' );
    }

    /**
     * Recursive walker. $seen_ids is shared across the whole tree so a
     * field id used inside one column cannot collide with a field id used
     * inside another, or with a row id at the top level.
     *
     * @param array  $nodes
     * @param array  $seen_ids  Shared id set, by reference.
     * @param string $path      Human-readable path used in error messages.
     * @return true|\WP_Error
     */
    private static function validate_nodes( array $nodes, array &$seen_ids, string $path, bool $is_top_level = true ) {
        foreach ( $nodes as $index => $node ) {
            $node     = (array) $node;
            $node_path = $path . '[' . $index . ']';

            if ( SchemaHelper::is_step( $node ) ) {
                if ( ! $is_top_level ) {
                    return new \WP_Error(
                        'invalid_form_data',
                        sprintf( 'Step at %s cannot be nested. Steps live only at the top level of the form.', $node_path ),
                        [ 'status' => 422 ]
                    );
                }
                $step_error = self::validate_step( $node, $seen_ids, $node_path );
                if ( is_wp_error( $step_error ) ) {
                    return $step_error;
                }
                continue;
            }

            if ( SchemaHelper::is_row( $node ) ) {
                $row_error = self::validate_row( $node, $seen_ids, $node_path );
                if ( is_wp_error( $row_error ) ) {
                    return $row_error;
                }
                continue;
            }

            $field_error = self::validate_field( $node, $seen_ids, $node_path );
            if ( is_wp_error( $field_error ) ) {
                return $field_error;
            }
        }
        return true;
    }

    /**
     * Validate a layout step. Steps are top-level only and own a
     * fields[] array of real fields and rows. Nested steps and orphan
     * columns inside step.fields are rejected.
     */
    private static function validate_step( array $step, array &$seen_ids, string $path ) {
        if ( empty( $step['id'] ) ) {
            return new \WP_Error(
                'invalid_form_data',
                sprintf( 'Step at %s is missing an id.', $path ),
                [ 'status' => 422 ]
            );
        }

        $id = (string) $step['id'];

        if ( ! preg_match( self::FIELD_ID_PATTERN, $id ) ) {
            return new \WP_Error(
                'invalid_form_data',
                sprintf(
                    'Step id "%s" is invalid. Only lowercase letters (a-z), digits (0-9), and underscores (_) are allowed.',
                    $id
                ),
                [ 'status' => 422 ]
            );
        }

        if ( in_array( $id, $seen_ids, true ) ) {
            return new \WP_Error(
                'invalid_form_data',
                sprintf( 'Duplicate id "%s". Step, row, column, and field ids share one namespace.', $id ),
                [ 'status' => 422 ]
            );
        }
        $seen_ids[] = $id;

        $children = is_array( $step['fields'] ?? null ) ? $step['fields'] : [];

        // Recurse with $is_top_level = false so nested steps are
        // rejected. Rows + real fields are allowed; orphan columns are
        // not (they only appear inside rows).
        return self::validate_nodes( $children, $seen_ids, $path . '.fields', false );
    }

    /**
     * Validate a layout row: id, settings.columns (1..4), and recurse
     * into every column's nested fields.
     */
    private static function validate_row( array $row, array &$seen_ids, string $path ) {
        if ( empty( $row['id'] ) ) {
            return new \WP_Error(
                'invalid_form_data',
                sprintf( 'Row at %s is missing an id.', $path ),
                [ 'status' => 422 ]
            );
        }

        $id = (string) $row['id'];

        if ( ! preg_match( self::FIELD_ID_PATTERN, $id ) ) {
            return new \WP_Error(
                'invalid_form_data',
                sprintf(
                    'Row id "%s" is invalid. Only lowercase letters (a-z), digits (0-9), and underscores (_) are allowed.',
                    $id
                ),
                [ 'status' => 422 ]
            );
        }

        if ( in_array( $id, $seen_ids, true ) ) {
            return new \WP_Error(
                'invalid_form_data',
                sprintf( 'Duplicate id "%s". Row, column, and field ids share one namespace.', $id ),
                [ 'status' => 422 ]
            );
        }
        $seen_ids[] = $id;

        $columns = is_array( $row['columns'] ?? null ) ? $row['columns'] : [];
        if ( empty( $columns ) ) {
            return new \WP_Error(
                'invalid_form_data',
                sprintf( 'Row "%s" has no columns.', $id ),
                [ 'status' => 422 ]
            );
        }

        $declared = isset( $row['settings']['columns'] )
            ? (int) $row['settings']['columns']
            : count( $columns );
        if ( $declared < SchemaHelper::MIN_COLUMNS || $declared > SchemaHelper::MAX_COLUMNS ) {
            return new \WP_Error(
                'invalid_form_data',
                sprintf(
                    'Row "%s" declares %d columns. Allowed range is %d to %d.',
                    $id,
                    $declared,
                    SchemaHelper::MIN_COLUMNS,
                    SchemaHelper::MAX_COLUMNS
                ),
                [ 'status' => 422 ]
            );
        }

        foreach ( $columns as $col_idx => $col ) {
            $col      = (array) $col;
            $col_path = $path . '.columns[' . $col_idx . ']';

            if ( empty( $col['id'] ) ) {
                return new \WP_Error(
                    'invalid_form_data',
                    sprintf( 'Column at %s is missing an id.', $col_path ),
                    [ 'status' => 422 ]
                );
            }

            $col_id = (string) $col['id'];
            if ( ! preg_match( self::FIELD_ID_PATTERN, $col_id ) ) {
                return new \WP_Error(
                    'invalid_form_data',
                    sprintf(
                        'Column id "%s" is invalid. Only lowercase letters, digits, and underscores are allowed.',
                        $col_id
                    ),
                    [ 'status' => 422 ]
                );
            }

            if ( in_array( $col_id, $seen_ids, true ) ) {
                return new \WP_Error(
                    'invalid_form_data',
                    sprintf( 'Duplicate id "%s". Row, column, and field ids share one namespace.', $col_id ),
                    [ 'status' => 422 ]
                );
            }
            $seen_ids[] = $col_id;

            // Empty columns are allowed - the user may have just spawned
            // the row and not dragged anything in yet. Validate any
            // fields that are there. Nested rows are NOT supported -
            // reject so this surface stays simple.
            $col_fields = is_array( $col['fields'] ?? null ) ? $col['fields'] : [];
            foreach ( $col_fields as $f_idx => $nested ) {
                $nested = (array) $nested;
                if ( SchemaHelper::is_row( $nested ) ) {
                    return new \WP_Error(
                        'invalid_form_data',
                        sprintf( 'Row at %s.fields[%d] is nested - rows cannot contain other rows.', $col_path, $f_idx ),
                        [ 'status' => 422 ]
                    );
                }
                if ( SchemaHelper::is_step( $nested ) ) {
                    return new \WP_Error(
                        'invalid_form_data',
                        sprintf( 'Step at %s.fields[%d] is nested - steps live only at the top level of the form.', $col_path, $f_idx ),
                        [ 'status' => 422 ]
                    );
                }
                $field_error = self::validate_field( $nested, $seen_ids, $col_path . '.fields[' . $f_idx . ']' );
                if ( is_wp_error( $field_error ) ) {
                    return $field_error;
                }
            }
        }

        return true;
    }

    /**
     * Validate a single leaf field. Same rules as the original
     * top-level loop, just factored out for the recursive walker.
     */
    private static function validate_field( array $field, array &$seen_ids, string $path ) {
        if ( empty( $field['id'] ) ) {
            return new \WP_Error(
                'invalid_form_data',
                sprintf( 'Field at %s is missing an id.', $path ),
                [ 'status' => 422 ]
            );
        }

        $id = (string) $field['id'];

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

        if ( in_array( $id, $seen_ids, true ) ) {
            return new \WP_Error(
                'invalid_form_data',
                sprintf( 'Duplicate field id "%s". Each id must be unique across the form.', $id ),
                [ 'status' => 422 ]
            );
        }
        $seen_ids[] = $id;

        // Extension hook. Fires after all core rules pass for this field.
        // Return null to pass, or a WP_Error to fail.
        $extra = apply_filters( 'neural_forms_validate_field', null, $field );
        if ( is_wp_error( $extra ) ) {
            return $extra;
        }

        return true;
    }
}
