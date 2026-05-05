<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
//
// This repository owns the wp_neural_forms_* tables and runs typed CRUD
// against them. Object caching is intentionally not used here:
// - REST handlers fetch fresh per request; entries / forms see frequent
//   writes, so a stale cache would surface in the admin UI.
// - Cache invalidation across the create/update/delete + bulk paths
//   would add more risk than the few percent of latency it would save.
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
 * - No business logic - only persistence.
 */
class FormRepository {

    // Write operations

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

        // ?? only triggers on null — an empty-string slug from the
        // builder (form.slug = '' for new forms) would otherwise pass
        // through and `generate_unique_slug('')` would default to the
        // generic prefix "form" instead of name-deriving. Truthy
        // check ensures the user-visible name drives the slug for
        // brand-new forms.
        $base_slug = ! empty( $data['slug'] )
            ? (string) $data['slug']
            : sanitize_title( (string) $data['name'] );
        $slug = self::unique_slug( $base_slug );

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
            // already archived - preserves the original pre-archive state
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

    // Read operations

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

        // Deterministic ordering safety net: if duplicates somehow
        // remain (legacy data not yet repaired, or a race that beat
        // the repair routine), prefer an active form first, then the
        // lowest id. The shortcode + REST routes always render the
        // SAME form for the same slug.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM %i
                 WHERE slug = %s
                 ORDER BY ( status = 'active' ) DESC, id ASC
                 LIMIT 1",
                self::table(),
                $slug
            ),
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
        $table    = $wpdb->prefix . 'neural_forms_forms';

        // Total count (no LIMIT). Table name flows through %i. $where is
        // composed from this method's own whitelist of placeholder fragments
        // ('status = %s' / 'status IN ( %s, %s )') so its placeholder count
        // is the count of values pushed onto $values above.
        $count_args = array_merge( [ $table ], $values );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $total = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE {$where}", ...$count_args )
        );

        // Paged rows. $orderby is whitelisted above against $allowed_columns
        // and $order is forced to ASC|DESC, so they are safe to interpolate.
        $rows_args = array_merge( [ $table ], $values, [ $per_page, $offset ] );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows = $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM %i WHERE {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d", ...$rows_args ),
            ARRAY_A
        );

        return [
            'items' => array_map( [ self::class, 'decode' ], $rows ?: [] ),
            'total' => $total,
        ];
    }

    /**
     * Resolve form ids whose detected type matches one of `$types`
     * (added 0.2.0 for the Entries inbox smart-folder filtering).
     *
     * Strategy: SELECT id + form_schema for non-trash forms, decode
     * each row's schema, run FormTypeDetector::detect, keep ids whose
     * type is in the requested set. The N-row decode is acceptable
     * because the forms table is in the dozens, not millions; if it
     * ever becomes a hot path we can cache the detected type as a
     * column.
     *
     * Type taxonomy mirrors FormTypeDetector exactly:
     *   simple, poll, quiz, mixed, survey
     *
     * Brief's mixed-routing rule: mixed forms (quiz_question + poll)
     * appear in QUIZ folder primarily; pass `[ 'quiz', 'mixed' ]` to
     * resolve the Quizzes-folder set, and `[ 'poll' ]` (without
     * 'mixed') to resolve the Polls-folder set.
     *
     * @param string[] $types Subset of FormTypeDetector::TYPE_* constants.
     * @param string $status_scope 'any' | 'active' (default 'any')
     * @return int[] Form IDs matching at least one of the requested types.
     */
    public static function get_form_ids_by_detected_type( array $types, string $status_scope = 'any' ): array {
        global $wpdb;
        if ( empty( $types ) ) return [];

        $table = $wpdb->prefix . 'neural_forms_forms';

        // Status filter: 'any' includes draft + active but excludes
        // archived rows from inbox-style folders. Callers that want
        // archived-too pass status_scope='all' (caller-side; not exposed
        // in the brief, default works).
        $where = '1=1';
        $args  = [ $table ];
        if ( $status_scope === 'active' ) {
            $where .= ' AND status = %s';
            $args[]  = 'active';
        }
        // Always exclude archived from smart folders.
        $where .= " AND status != 'archived'";

        $rows = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->prepare( "SELECT id, form_schema FROM %i WHERE {$where}", ...$args ),
            ARRAY_A
        );
        if ( ! is_array( $rows ) || empty( $rows ) ) return [];

        $detector = '\\NeuralForms\\Forms\\FormTypeDetector';
        $type_set = array_flip( $types );
        $out      = [];

        foreach ( $rows as $row ) {
            $schema = json_decode( (string) ( $row['form_schema'] ?? '{}' ), true );
            if ( ! is_array( $schema ) ) continue;
            $detected = $detector::detect( $schema );
            if ( isset( $type_set[ $detected ] ) ) {
                $out[] = (int) $row['id'];
            }
        }

        return $out;
    }

    // Helpers

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
     * Generate a slug that does not already exist in the forms table.
     * Appends -2, -3, ... until a free slug is found.
     *
     * Hardened (1.1.0+):
     *   - Empty / unsanitisable input falls back to "form".
     *   - Comparison is case-insensitive (LOWER(slug) == LOWER(candidate))
     *     so "Contact" and "contact" can't slip past each other on a
     *     case-insensitive collation. The MySQL default `utf8mb4_unicode_ci`
     *     is already case-insensitive, but explicit LOWER() avoids
     *     surprises on installs running case-sensitive collations.
     *   - $exclude_id is the form being updated — its OWN current slug
     *     is excluded from the conflict check so no-op saves keep the
     *     same slug instead of churning to "-2".
     *
     * @param string $base_slug
     * @param int    $exclude_id  Pass 0 (default) on create.
     * @return string
     */
    public static function generate_unique_slug( string $base_slug, int $exclude_id = 0 ): string {
        global $wpdb;

        $slug = sanitize_title( $base_slug );
        if ( $slug === '' ) {
            $slug = 'form';
        }

        $suffix  = 1;
        $attempt = $slug;
        $table   = self::table();

        // Hard cap so a misbehaving caller can't loop forever. With
        // unique slugs this never trips; if it ever did, fall back to
        // a slug + microtime so the insert still succeeds uniquely.
        $max_attempts = 10000;
        $iteration    = 0;

        while ( $iteration++ < $max_attempts ) {
            $sql  = 'SELECT id FROM %i WHERE LOWER(slug) = LOWER(%s)';
            $args = [ $table, $attempt ];
            if ( $exclude_id > 0 ) {
                $sql .= ' AND id != %d';
                $args[] = $exclude_id;
            }
            $sql .= ' LIMIT 1';

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $found = $wpdb->get_var( $wpdb->prepare( $sql, ...$args ) );

            if ( ! $found ) {
                return $attempt;
            }

            $suffix++;
            $attempt = "{$slug}-{$suffix}";
        }

        // Pathological fallback (essentially unreachable): time-based
        // suffix guarantees uniqueness when the loop somehow can't.
        return $slug . '-' . substr( (string) microtime( true ), -6 );
    }

    /**
     * Backwards-compatible private alias. Older callers used
     * `unique_slug()`; new callers should use the public
     * `generate_unique_slug()`. Kept private so external consumers
     * use the documented public method.
     */
    private static function unique_slug( string $base, ?int $exclude_id = null ): string {
        return self::generate_unique_slug( $base, (int) ( $exclude_id ?? 0 ) );
    }

    /**
     * Repair existing duplicate slugs in the forms table.
     *
     * Strategy: group by slug. For each slug appearing >1 time, the
     * lowest id KEEPS the original slug; every later duplicate is
     * renamed to `{slug}-2`, `{slug}-3`, ... using
     * generate_unique_slug() so the renames don't collide with
     * existing suffixed rows already in the table.
     *
     * Idempotent — running twice on a clean table is a no-op.
     * Returns the count of rows renamed.
     *
     * Called from Database::maybe_upgrade() so existing installs that
     * accumulated duplicates before the create/update guards landed
     * are repaired automatically on upgrade.
     */
    public static function repair_duplicate_slugs(): int {
        global $wpdb;

        $table = self::table();

        // Group slugs that appear more than once. ORDER BY id ASC
        // inside GROUP_CONCAT so the LOWEST id is the "winner" that
        // keeps the original slug.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT slug, GROUP_CONCAT(id ORDER BY id ASC) AS ids
                 FROM %i
                 GROUP BY slug
                 HAVING COUNT(*) > 1',
                $table
            ),
            ARRAY_A
        );

        if ( ! is_array( $rows ) || empty( $rows ) ) {
            return 0;
        }

        $renamed = 0;
        foreach ( $rows as $row ) {
            $base = (string) ( $row['slug'] ?? '' );
            $ids  = array_filter( array_map( 'intval', explode( ',', (string) ( $row['ids'] ?? '' ) ) ) );
            if ( count( $ids ) < 2 ) continue;

            // Keep the lowest id. Rename every subsequent duplicate.
            array_shift( $ids );
            foreach ( $ids as $dup_id ) {
                $new_slug = self::generate_unique_slug( $base !== '' ? $base : 'form', $dup_id );
                $wpdb->update(
                    $table,
                    [ 'slug' => $new_slug, 'updated_at' => current_time( 'mysql' ) ],
                    [ 'id'   => $dup_id ],
                    [ '%s', '%s' ],
                    [ '%d' ]
                );
                $renamed++;
            }
        }
        return $renamed;
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
     * Anything else '->-> null (treated as no filter rather than throwing).
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
