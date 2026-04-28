<?php
namespace NeuralForms\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * All database reads and writes for the neural_forms_forms table.
 *
 * Rules:
 * - Every public method receives and returns plain arrays (never objects).
 * - JSON encoding/decoding for form_schema and settings is handled here,
 *   so callers always work with plain PHP arrays.
 * - Slug uniqueness is enforced here, not in the caller.
 * - No business logic — only persistence.
 */
class FormRepository {

    // -------------------------------------------------------------------------
    // Write operations
    // -------------------------------------------------------------------------

    /**
     * Insert a new form row.
     *
     * @param array $data {
     *   @type string $name        Required. Human-readable form name.
     *   @type array  $form_schema Required. Field definitions array.
     *   @type string $slug        Optional. Auto-generated from name if omitted.
     *   @type array  $settings    Optional. Notification/redirect config.
     *   @type string $status      Optional. 'draft'|'active'|'archived'. Default 'active'.
     * }
     * @return int|false  New form ID on success, false on failure.
     */
    public static function create( array $data ) {
        global $wpdb;

        if ( empty( $data['name'] ) || empty( $data['form_schema'] ) ) {
            return false;
        }

        $slug = self::unique_slug(
            $data['slug'] ?? sanitize_title( $data['name'] )
        );

        $now = current_time( 'mysql' );

        $inserted = $wpdb->insert(
            self::table(),
            [
                'name'        => sanitize_text_field( $data['name'] ),
                'slug'        => $slug,
                'description' => isset( $data['description'] ) ? sanitize_textarea_field( $data['description'] ) : null,
                'form_schema' => wp_json_encode( $data['form_schema'] ),
                'settings'    => wp_json_encode( $data['settings'] ?? [] ),
                'status'      => self::valid_status( $data['status'] ?? 'active' ),
                'created_by'  => get_current_user_id(),
                'created_at'  => $now,
                'updated_at'  => $now,
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
        );

        return $inserted ? (int) $wpdb->insert_id : false;
    }

    /**
     * Update an existing form.
     *
     * Only the keys present in $data are changed; omitted keys are untouched.
     *
     * @param int   $id
     * @param array $data  Same keys as create(), all optional.
     * @return bool
     */
    public static function update( int $id, array $data ): bool {
        global $wpdb;

        $fields  = [ 'updated_at' => current_time( 'mysql' ) ];
        $formats = [ '%s' ];

        if ( isset( $data['name'] ) ) {
            $fields['name'] = sanitize_text_field( $data['name'] );
            $formats[]      = '%s';
        }

        if ( array_key_exists( 'description', $data ) ) {
            $fields['description'] = ! is_null( $data['description'] )
                ? sanitize_textarea_field( $data['description'] )
                : null;
            $formats[] = '%s';
        }

        if ( isset( $data['slug'] ) ) {
            $fields['slug'] = self::unique_slug( $data['slug'], $id );
            $formats[]      = '%s';
        }

        if ( isset( $data['form_schema'] ) ) {
            $fields['form_schema'] = wp_json_encode( $data['form_schema'] );
            $formats[]             = '%s';
        }

        if ( isset( $data['settings'] ) ) {
            $fields['settings'] = wp_json_encode( $data['settings'] );
            $formats[]          = '%s';
        }

        if ( isset( $data['status'] ) ) {
            $fields['status'] = self::valid_status( $data['status'] );
            $formats[]        = '%s';
        }

        $result = $wpdb->update(
            self::table(),
            $fields,
            [ 'id' => $id ],
            $formats,
            [ '%d' ]
        );

        return $result !== false;
    }

    /**
     * Delete a form. Soft-delete by default (status flipped to 'archived'
     * so the row + its entries are preserved); hard-delete only when the
     * caller explicitly opts in by passing $hard = true.
     *
     * On soft-delete we stash the *current* status into
     * settings._previous_status so restore() can put the form back where
     * it came from (active or draft) instead of guessing.
     *
     * @param int  $id
     * @param bool $hard  True = remove the row outright. False = archive.
     * @return bool
     */
    public static function delete( int $id, bool $hard = false ): bool {
        global $wpdb;

        if ( ! $hard ) {
            $current = self::find( $id );
            if ( ! $current ) {
                return false;
            }

            // Don't overwrite a stored previous status if the form is
            // already archived — preserves the original pre-archive state
            // through repeated archive/un-archive cycles.
            if ( $current['status'] !== 'archived' ) {
                $settings = is_array( $current['settings'] ) ? $current['settings'] : [];
                $settings['_previous_status'] = $current['status'];

                return self::update( $id, [
                    'status'   => 'archived',
                    'settings' => $settings,
                ] );
            }

            return self::update( $id, [ 'status' => 'archived' ] );
        }

        $result = $wpdb->delete(
            self::table(),
            [ 'id' => $id ],
            [ '%d' ]
        );

        return $result !== false;
    }

    /**
     * Restore an archived form to whatever status it had before being
     * archived. Falls back to 'draft' if the previous status is missing
     * or invalid (e.g. forms archived before this metadata was tracked).
     *
     * Returns the new status that was applied so the caller can surface
     * it in the response without an extra round-trip.
     *
     * @param int $id
     * @return string|false  New status on success, false on failure.
     */
    public static function restore( int $id ) {
        $form = self::find( $id );
        if ( ! $form ) {
            return false;
        }

        $settings = is_array( $form['settings'] ) ? $form['settings'] : [];
        $previous = $settings['_previous_status'] ?? null;

        $next = in_array( $previous, [ 'active', 'draft' ], true )
            ? $previous
            : 'draft';

        unset( $settings['_previous_status'] );

        $ok = self::update( $id, [
            'status'   => $next,
            'settings' => $settings,
        ] );

        return $ok ? $next : false;
    }

    // -------------------------------------------------------------------------
    // Read operations
    // -------------------------------------------------------------------------

    /**
     * Fetch a single form by primary key.
     *
     * @param int $id
     * @return array|null  Decoded form row, or null if not found.
     */
    public static function find( int $id ): ?array {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ),
            ARRAY_A
        );

        return $row ? self::decode( $row ) : null;
    }

    /**
     * Fetch a single form by its unique slug.
     *
     * @param string $slug
     * @return array|null
     */
    public static function find_by_slug( string $slug ): ?array {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM %i WHERE slug = %s', self::table(), $slug ),
            ARRAY_A
        );

        return $row ? self::decode( $row ) : null;
    }

    /**
     * Return a paginated list of forms.
     *
     * @param array $args {
     *   @type string|array $status  Filter by status. 'any' for no filter,
     *                                a single status string ('active'),
     *                                a CSV string ('active,draft'), or an
     *                                array of statuses. Default 'any'.
     *   @type int    $per_page  Rows per page. Default 20.
     *   @type int    $page      1-based page number. Default 1.
     *   @type string $orderby   Column to sort by. Default 'created_at'.
     *   @type string $order     'ASC' or 'DESC'. Default 'DESC'.
     * }
     * @return array { items: array[], total: int }
     */
    public static function list( array $args = [] ): array {
        global $wpdb;

        $defaults = [
            'status'   => 'any',
            'per_page' => 20,
            'page'     => 1,
            'orderby'  => 'created_at',
            'order'    => 'DESC',
        ];
        $args = array_merge( $defaults, $args );

        $allowed_columns = [ 'id', 'name', 'status', 'created_at', 'updated_at' ];
        $orderby = in_array( $args['orderby'], $allowed_columns, true )
            ? $args['orderby']
            : 'created_at';
        $order   = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

        $where  = '1=1';
        $values = [];

        // Normalize $status to either the sentinel 'any' or an array of
        // valid status strings. CSV ('active,draft') is split here so the
        // REST layer can pass query params through verbatim.
        $statuses = self::normalize_status_filter( $args['status'] );
        if ( $statuses !== null ) {
            if ( count( $statuses ) === 1 ) {
                $where   .= ' AND status = %s';
                $values[] = $statuses[0];
            } else {
                $placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
                $where       .= " AND status IN ( {$placeholders} )";
                $values       = array_merge( $values, $statuses );
            }
        }

        $per_page = max( 1, (int) $args['per_page'] );
        $offset   = ( max( 1, (int) $args['page'] ) - 1 ) * $per_page;

        // Total count (no LIMIT).
        $count_sql = "SELECT COUNT(*) FROM {$wpdb->prefix}neural_forms_forms WHERE {$where}";
        $total     = (int) ( $values
            ? $wpdb->get_var( $wpdb->prepare( $count_sql, ...$values ) )
            : $wpdb->get_var( $count_sql )
        );

        // Paged rows.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows_sql = "SELECT * FROM {$wpdb->prefix}neural_forms_forms WHERE {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
        $values[] = $per_page;
        $values[] = $offset;

        $rows = $wpdb->get_results(
            $wpdb->prepare( $rows_sql, ...$values ),
            ARRAY_A
        );

        return [
            'items' => array_map( [ self::class, 'decode' ], $rows ?: [] ),
            'total' => $total,
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Decode JSON columns back to PHP arrays for a single row.
     */
    private static function decode( array $row ): array {
        $row['form_schema'] = json_decode( $row['form_schema'] ?? '{}', true ) ?: [];
        $row['settings']    = json_decode( $row['settings']    ?? '{}', true ) ?: [];
        $row['id']          = (int) $row['id'];
        $row['created_by']  = (int) $row['created_by'];

        return $row;
    }

    /**
     * Generate a slug that does not already exist in the table.
     * Appends -2, -3 … until it finds a free one.
     *
     * @param string   $base  Starting slug candidate.
     * @param int|null $exclude_id  Ignore this row's current slug (for updates).
     */
    private static function unique_slug( string $base, ?int $exclude_id = null ): string {
        global $wpdb;

        $slug    = sanitize_title( $base );
        $suffix  = 1;
        $attempt = $slug;

        while ( true ) {
            $sql  = "SELECT id FROM {$wpdb->prefix}neural_forms_forms WHERE slug = %s";
            $args = [ $attempt ];

            if ( $exclude_id ) {
                $sql   .= ' AND id != %d';
                $args[] = $exclude_id;
            }

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $found = $wpdb->get_var( $wpdb->prepare( $sql, ...$args ) );

            if ( ! $found ) {
                return $attempt;
            }

            $suffix++;
            $attempt = "{$slug}-{$suffix}";
        }
    }

    /**
     * Returns the value if it is a valid status, otherwise 'draft'.
     */
    private static function valid_status( string $status ): string {
        return in_array( $status, [ 'draft', 'active', 'archived' ], true )
            ? $status
            : 'draft';
    }

    /**
     * Coerce a status filter into either null (no filter) or a deduped array
     * of valid statuses.
     *
     * Accepts: 'any' | 'active' | 'active,draft' | ['active','draft'].
     * Anything else → null (treated as no filter rather than throwing).
     */
    private static function normalize_status_filter( $status ): ?array {
        if ( $status === null || $status === '' || $status === 'any' ) {
            return null;
        }

        if ( is_string( $status ) ) {
            $status = array_map( 'trim', explode( ',', $status ) );
        }

        if ( ! is_array( $status ) ) {
            return null;
        }

        $valid = [];
        foreach ( $status as $s ) {
            if ( ! is_string( $s ) ) {
                continue;
            }
            if ( in_array( $s, [ 'draft', 'active', 'archived' ], true ) ) {
                $valid[ $s ] = true;
            }
        }

        $valid = array_keys( $valid );

        return empty( $valid ) ? null : $valid;
    }

    /**
     * Full table name including WP prefix.
     */
    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'neural_forms_forms';
    }
}
