<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
//
// This repository owns the wp_neural_forms_* tables and runs typed CRUD
// against them. Object caching is intentionally not used here:
// - REST handlers fetch fresh per request; entries / forms see frequent
//   writes, so a stale cache would surface in the admin UI.
// - Cache invalidation across the create/update/delete + bulk paths
//   would add more risk than the few percent of latency it would save.
namespace NeuralForms\Storage;

use NeuralForms\Core\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * All database reads and writes for the neural_forms_entries table.
 *
 * Rules:
 * - Every public method receives and returns plain arrays (never objects).
 * - JSON encoding/decoding for the `data` column is handled exclusively here.
 *   Callers always work with plain PHP arrays; they never see a JSON string.
 * - $context carries server-derived metadata (ip, user_agent, page_url,
 *   submitted_at, form_version). It is never mixed into the `data` column -
 *   `data` contains only sanitized field values from the submission.
 * - No business logic, no hooks - pure persistence.
 */
class EntryRepository {

	// Write operations

	/**
	 * Insert a new entry row.
	 *
	 * @param int   $form_id         The ID of the form this entry belongs to.
	 * @param array $sanitized_data  Associative array of field_id => sanitized value.
	 *                               All values must be of consistent types before
	 *                               reaching here - the repository stores them as-is.
	 *                               In particular: checkbox values must be int (0 or 1),
	 *                               not bool (true/false) or string ("true"/"false"),
	 *                               so the JSON blob is uniform for exports and filters.
	 * @param array $context {
	 *   @type string $ip            Client IP address (IPv4 or IPv6). Default ''.
	 *   @type string $user_agent    HTTP User-Agent header value.  Default ''.
	 *   @type string $page_url      HTTP Referer header value.     Default ''.
	 *   @type string $submitted_at  UTC datetime: Y-m-d H:i:s.    Default now.
	 *   @type string $form_version  MD5 hash of the form schema.   Default ''.
	 * }
	 * @return int|\WP_Error  New entry ID on success, WP_Error on failure.
	 */
	public static function create( int $form_id, array $sanitized_data, array $context = [] ) {
		global $wpdb;

		$now = current_time( 'mysql', true );  // UTC

		$context = array_merge(
			[
				'ip'           => '',
				'user_agent'   => '',
				'page_url'     => '',
				'submitted_at' => $now,
				'form_version' => '',
			],
			$context
		);

		// Defense-in-depth: SubmitEndpoint already flattens rows + columns
		// before validation, but if a future caller skips that step we still
		// must never store a layout container in entry data. Strip any value
		// whose key looks like a row/column id ('row_*' / 'col_*') OR whose
		// value is the special row marker.
		$sanitized_data = self::strip_layout_keys( $sanitized_data );

		// Encode the user-submitted field values to JSON.
		// wp_json_encode falls back to false on failure (malformed UTF-8, etc.).
		$json = wp_json_encode( $sanitized_data );

		if ( $json === false ) {
			Logger::file( 'storage', 'error', sprintf( 'wp_json_encode() returned false for form %d', $form_id ) );
			return new \WP_Error(
				'entry_encode_failed',
				__( 'Could not encode submission data.', 'neural-forms-lite' ),
				[ 'status' => 500 ]
			);
		}

		$inserted = $wpdb->insert(
			self::table(),
			[
				'form_id'      => $form_id,
				'form_version' => substr( $context['form_version'], 0, 32 ),
				'status'       => 'new',
				'data'         => $json,
				'ip_address'   => substr( $context['ip'],         0, 45 ),
				'user_agent'   => $context['user_agent'],
				'page_url'     => $context['page_url'],
				'submitted_at' => $context['submitted_at'],
				'created_at'   => $now,
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		if ( $inserted === false ) {
			$db_err = $wpdb->last_error ?: '(no wpdb error message)';
			Logger::file(
				'storage',
				'error',
				sprintf( 'EntryRepository::create() DB insert failed for form %d: %s', $form_id, $db_err )
			);

			return new \WP_Error(
				'entry_save_failed',
				__( 'Could not save the entry.', 'neural-forms-lite' ),
				[ 'status' => 500 ]
			);
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update one or more mutable fields on an entry.
	 *
	 * Accepted keys in $fields:
	 *   status      (string) - validated against VALID_STATUSES
	 *   is_starred  (int)    - 0 or 1
	 *   needs_reply (int)    - 0 or 1
	 *
	 * Unknown keys are silently ignored so callers cannot inject arbitrary columns.
	 *
	 * @param int   $id
	 * @param array $fields  Associative array of column => value pairs.
	 * @return bool
	 */
	public static function update( int $id, array $fields ): bool {
		global $wpdb;

		$data   = [];
		$format = [];

		if ( isset( $fields['status'] ) ) {
			$data['status'] = self::valid_status( $fields['status'] );
			$format[]       = '%s';
		}
		if ( array_key_exists( 'is_starred', $fields ) ) {
			$data['is_starred'] = (int) (bool) $fields['is_starred'];
			$format[]           = '%d';
		}
		if ( array_key_exists( 'needs_reply', $fields ) ) {
			$data['needs_reply'] = (int) (bool) $fields['needs_reply'];
			$format[]            = '%d';
		}
		if ( array_key_exists( 'ai_auto_spam_applied', $fields ) ) {
			$data['ai_auto_spam_applied'] = (int) (bool) $fields['ai_auto_spam_applied'];
			$format[]                     = '%d';
		}

		if ( empty( $data ) ) {
			return false;
		}

		$result = $wpdb->update(
			self::table(),
			$data,
			[ 'id' => $id ],
			$format,
			[ '%d' ]
		);

		return $result !== false;
	}

	/**
	 * Update the status of an entry (e.g. 'new' '->-> 'read', 'spam', 'trash').
	 *
	 * Thin wrapper around update() for backward compatibility.
	 *
	 * @param int    $id
	 * @param string $status
	 * @return bool
	 */
	public static function update_status( int $id, string $status ): bool {
		return self::update( $id, [ 'status' => $status ] );
	}

	/**
	 * Apply the same field updates to multiple entries in one query.
	 *
	 * Only updates entries that actually exist - returns the IDs that were
	 * successfully updated (may be a subset of $ids if some did not exist).
	 *
	 * Accepted keys in $fields: same as update().
	 *
	 * @param int[] $ids
	 * @param array $fields
	 * @return int[]  IDs that were updated.
	 */
	public static function bulk_update( array $ids, array $fields ): array {
		global $wpdb;

		$ids = array_filter( array_map( 'intval', $ids ) );

		if ( empty( $ids ) ) {
			return [];
		}

		// Build SET clause from the same allowlist as update().
		$set_parts  = [];
		$set_values = [];

		if ( isset( $fields['status'] ) ) {
			$set_parts[]  = 'status = %s';
			$set_values[] = self::valid_status( $fields['status'] );
		}
		if ( array_key_exists( 'is_starred', $fields ) ) {
			$set_parts[]  = 'is_starred = %d';
			$set_values[] = (int) (bool) $fields['is_starred'];
		}
		if ( array_key_exists( 'needs_reply', $fields ) ) {
			$set_parts[]  = 'needs_reply = %d';
			$set_values[] = (int) (bool) $fields['needs_reply'];
		}

		if ( empty( $set_parts ) ) {
			return [];
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$set_clause   = implode( ', ', $set_parts );
		$table        = self::table();

		// $set_clause uses field names from the method-internal whitelist
		// above; $placeholders is a string of '%d' tokens. All user values
		// flow through ...$set_values + ...$ids '->-> prepare().
		$query = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"UPDATE %i SET {$set_clause} WHERE id IN ({$placeholders})",
			...array_merge( [ $table ], $set_values, $ids )
		);
		$wpdb->query( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// Return the subset of requested IDs that actually exist in the table.
		$confirmed_placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$confirmed = $wpdb->get_col(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"SELECT id FROM %i WHERE id IN ({$confirmed_placeholders})",
				...array_merge( [ $table ], $ids )
			)
		);

		return array_map( 'intval', $confirmed ?: [] );
	}

	/**
	 * Return entry counts for each system view, optionally scoped to a form.
	 *
	 * Views:
	 *   inbox       - status IN ('new','read')   - active, non-archived entries
	 *   unread      - status = 'new'
	 *   starred     - is_starred = 1
	 *   needs_reply - needs_reply = 1
	 *   spam        - status = 'spam'
	 *   trash       - status = 'trash'
	 *
	 * @param int|null $form_id  If non-null, scope counts to a single form.
	 * @return array { inbox: int, unread: int, starred: int, needs_reply: int, spam: int, trash: int, high_priority: int, spam_suspected: int }
	 */
	public static function count_by_view( ?int $form_id = null ): array {
		global $wpdb;

		$table        = self::table();
		$scope_clause = $form_id ? $wpdb->prepare( ' AND form_id = %d', $form_id ) : '';

		$count = static function ( string $where ) use ( $wpdb, $table, $scope_clause ): int {
			// $where is a hardcoded literal from the array below; $scope_clause
			// is already prepared. Table flows through %i.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$value = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE {$where}{$scope_clause}", $table ) );
			return (int) $value;
		};

		return [
			'inbox'          => $count( "status IN ('new','read')" ),
			'unread'         => $count( "status = 'new'" ),
			'starred'        => $count( 'is_starred = 1' ),
			'needs_reply'    => $count( 'needs_reply = 1' ),
			'spam'           => $count( "status = 'spam'" ),
			'trash'          => $count( "status = 'trash'" ),
			'high_priority'  => $count( "ai_priority = 'high' AND status IN ('new','read')" ),
			'spam_suspected' => $count( "ai_is_spam = 1 AND ai_auto_spam_applied = 0 AND status NOT IN ('spam','trash')" ),
		];
	}

	/**
	 * Hard-delete a single entry.
	 *
	 * Intended for admin use and GDPR erasure requests.
	 * Callers are responsible for any pre-delete checks (ownership, capability).
	 *
	 * @param int $id
	 * @return bool
	 */
	public static function delete( int $id ): bool {
		global $wpdb;

		$result = $wpdb->delete(
			self::table(),
			[ 'id' => $id ],
			[ '%d' ]
		);

		return $result !== false;
	}

	/**
	 * Hard-delete multiple entries in one query.
	 *
	 * Only deletes entries that actually exist - returns the IDs that were
	 * successfully deleted (may be a subset of $ids if some did not exist).
	 *
	 * @param int[] $ids
	 * @return int[]  IDs that were deleted.
	 */
	public static function bulk_delete( array $ids ): array {
		global $wpdb;

		$ids = array_filter( array_map( 'intval', $ids ) );

		if ( empty( $ids ) ) {
			return [];
		}

		// Capture which IDs actually exist before deleting them.
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$table        = self::table();

		$existing = $wpdb->get_col(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"SELECT id FROM %i WHERE id IN ({$placeholders})",
				...array_merge( [ $table ], $ids )
			)
		);

		$existing = array_map( 'intval', $existing ?: [] );

		if ( empty( $existing ) ) {
			return [];
		}

		$del_placeholders = implode( ',', array_fill( 0, count( $existing ), '%d' ) );

		$wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"DELETE FROM %i WHERE id IN ({$del_placeholders})",
				...array_merge( [ $table ], $existing )
			)
		);

		return $existing;
	}

	// Read operations

	/**
	 * Fetch a single entry by primary key.
	 *
	 * @param int $id
	 * @return array|null  Decoded entry row, or null if not found.
	 */
	public static function get( int $id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ),
			ARRAY_A
		);

		return $row ? self::decode( $row ) : null;
	}

	/**
	 * System view identifiers and the WHERE clauses they expand to.
	 * Used by list() and count_by_view().
	 */
	public const VALID_VIEWS = [ 'inbox', 'unread', 'starred', 'needs_reply', 'spam', 'trash', 'high_priority', 'spam_suspected' ];

	/**
	 * Return a paginated list of entries.
	 *
	 * Can be scoped to a specific form ($form_id > 0) and/or a system view.
	 * When only a view is given (form_id = 0), the query runs across all forms.
	 *
	 * Includes form_name via a LEFT JOIN on neural_forms_forms. Null when the
	 * parent form has been deleted after the entry was recorded.
	 *
	 * @param int   $form_id  Form to scope to. Pass 0 for cross-form (view-only).
	 * @param array $args {
	 *   @type string $view     System view filter (see VALID_VIEWS). Default ''.
	 *   @type string $status   Filter by status ('any' or VALID_STATUSES value). Default 'any'.
	 *                          Ignored when $view is set - the view owns the WHERE logic.
	 *   @type int    $per_page Rows per page (1-100). Default 20.
	 *   @type int    $page     1-based page number. Default 1.
	 *   @type string $orderby  Column to sort by. Default 'created_at'.
	 *   @type string $order    'ASC' or 'DESC'. Default 'DESC'.
	 * }
	 * @return array { items: array[], total: int }
	 */
	public static function list( int $form_id, array $args = [] ): array {
		global $wpdb;

		$defaults = [
			'view'       => '',
			'status'     => 'any',
			'per_page'   => 20,
			'page'       => 1,
			'orderby'    => 'submitted_at',
			'order'      => 'DESC',
			'search'     => '',
			'sort_order' => '',
		];
		$args = array_merge( $defaults, $args );

		// sort_order is a convenience alias: when present it forces submitted_at
		// as the sort column and overrides the legacy orderby/order args.
		if ( $args['sort_order'] !== '' ) {
			$sort_order = strtolower( (string) $args['sort_order'] );
			$orderby    = 'submitted_at';
			$order      = in_array( $sort_order, [ 'asc', 'desc' ], true )
				? strtoupper( $sort_order )
				: 'DESC';
		} else {
			$allowed_columns = [ 'id', 'status', 'submitted_at', 'created_at' ];
			$orderby = in_array( $args['orderby'], $allowed_columns, true )
				? $args['orderby']
				: 'submitted_at';
			$order = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';
		}

		$per_page = min( 100, max( 1, (int) $args['per_page'] ) );
		$offset   = ( max( 1, (int) $args['page'] ) - 1 ) * $per_page;

		$entries_table = self::table();
		$forms_table   = $wpdb->prefix . 'neural_forms_forms';

		$clauses       = self::build_filter_clauses( $form_id, $args );
		$where_plain   = $clauses['plain'];
		$where_aliased = $clauses['aliased'];
		$values        = $clauses['values'];

		// COUNT query - table flows through %i; $where_plain is composed
		// from method-internal whitelists in build_filter_clauses().
		$count_args = array_merge( [ $entries_table ], $values );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total      = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM %i {$where_plain}", ...$count_args )
		);

		// Main SELECT - both table names flow through %i; $orderby is
		// whitelisted against $allowed_columns and $order is forced to
		// ASC|DESC, so they are safe to interpolate.
		$main_args = array_merge( [ $entries_table, $forms_table ], $values, [ $per_page, $offset ] );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.*, f.name AS form_name
				 FROM %i e
				 LEFT JOIN %i f ON e.form_id = f.id
				 {$where_aliased}
				 ORDER BY e.{$orderby} {$order}
				 LIMIT %d OFFSET %d",
				...$main_args
			),
			ARRAY_A
		);

		return [
			'items' => array_map( [ self::class, 'decode' ], $rows ?: [] ),
			'total' => $total,
		];
	}

	// Export

	/**
	 * Fetch all entries matching the given filters, without pagination.
	 *
	 * Designed for CSV export. Uses the same filter logic as list() via
	 * build_filter_clauses(), but omits LIMIT/OFFSET and caps at 5 000 rows.
	 *
	 * @param int   $form_id  Form to scope to. Pass 0 for cross-form.
	 * @param array $args {
	 *   @type string $view    System view filter (see VALID_VIEWS). Default ''.
	 *   @type string $search  LIKE search term. Default ''.
	 * }
	 * @return array  Flat array of decoded entry rows (form_name included).
	 */
	public static function list_for_export( int $form_id, array $args = [] ): array {
		global $wpdb;

		$entries_table = self::table();
		$forms_table   = $wpdb->prefix . 'neural_forms_forms';

		$sort_order_raw = strtolower( (string) ( $args['sort_order'] ?? 'desc' ) );
		$export_order   = in_array( $sort_order_raw, [ 'asc', 'desc' ], true )
			? strtoupper( $sort_order_raw )
			: 'DESC';

		$clauses = self::build_filter_clauses( $form_id, $args );
		$where   = $clauses['aliased'];
		$values  = $clauses['values'];

		// Hard cap at 5 000 rows - prevents runaway memory on large installs.
		$cap_args = array_merge( [ $entries_table, $forms_table ], $values, [ 5000 ] );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.*, f.name AS form_name
				 FROM %i e
				 LEFT JOIN %i f ON e.form_id = f.id
				 {$where}
				 ORDER BY e.submitted_at {$export_order}
				 LIMIT %d",
				...$cap_args
			),
			ARRAY_A
		);

		return array_map( [ self::class, 'decode' ], $rows ?: [] );
	}

	// Helpers

	/**
	 * Build the WHERE conditions shared by list() and list_for_export().
	 *
	 * Returns two WHERE string variants and a shared parameter array:
	 *   'plain'   - bare column names, used in COUNT queries (no JOIN).
	 *   'aliased' - 'e.' prefix on all columns, used in SELECT with LEFT JOIN.
	 *   'values'  - ordered parameters for $wpdb->prepare().
	 *
	 * The plain and aliased variants are structurally identical - they differ
	 * only in column-name prefixes.  The values array is shared between them
	 * because both variants add placeholders in the same order.
	 *
	 * @param int   $form_id
	 * @param array $args  Accepts: view, status, search.
	 * @return array{ plain: string, aliased: string, values: array }
	 */
	private static function build_filter_clauses( int $form_id, array $args ): array {
		global $wpdb;

		$forms_table = $wpdb->prefix . 'neural_forms_forms';

		$plain_parts   = [];
		$aliased_parts = [];
		$values        = [];

		// Optional form scope.
		if ( $form_id > 0 ) {
			$plain_parts[]   = 'form_id = %d';
			$aliased_parts[] = 'e.form_id = %d';
			$values[]        = $form_id;
		}

		// View filter takes precedence over the plain 'status' param.
		$view = in_array( $args['view'] ?? '', self::VALID_VIEWS, true ) ? $args['view'] : '';

		if ( $view ) {
			switch ( $view ) {
				case 'inbox':
					$plain_parts[]   = "status IN ('new','read')";
					$aliased_parts[] = "e.status IN ('new','read')";
					break;
				case 'unread':
					$plain_parts[]   = "status = 'new'";
					$aliased_parts[] = "e.status = 'new'";
					break;
				case 'starred':
					$plain_parts[]   = 'is_starred = 1';
					$aliased_parts[] = 'e.is_starred = 1';
					break;
				case 'needs_reply':
					$plain_parts[]   = 'needs_reply = 1';
					$aliased_parts[] = 'e.needs_reply = 1';
					break;
				case 'spam':
					$plain_parts[]   = "status = 'spam'";
					$aliased_parts[] = "e.status = 'spam'";
					break;
				case 'trash':
					$plain_parts[]   = "status = 'trash'";
					$aliased_parts[] = "e.status = 'trash'";
					break;
				case 'high_priority':
					$plain_parts[]   = "ai_priority = 'high' AND status IN ('new','read')";
					$aliased_parts[] = "e.ai_priority = 'high' AND e.status IN ('new','read')";
					break;
				case 'spam_suspected':
					$plain_parts[]   = "ai_is_spam = 1 AND ai_auto_spam_applied = 0 AND status NOT IN ('spam','trash')";
					$aliased_parts[] = "e.ai_is_spam = 1 AND e.ai_auto_spam_applied = 0 AND e.status NOT IN ('spam','trash')";
					break;
			}
		} elseif ( ( $args['status'] ?? 'any' ) !== 'any' ) {
			$plain_parts[]   = 'status = %s';
			$aliased_parts[] = 'e.status = %s';
			$values[]        = $args['status'];
		}

		// Search - LIKE on the JSON data blob and form name.
		// Plain variant uses a subquery for form name (no JOIN in COUNT query).
		// Aliased variant references the already-joined f.name column.
		// Both add exactly 2 placeholders so the shared $values array stays aligned.
		// $forms_table is `$wpdb->prefix . 'neural_forms_forms'`, never user-derived.
		$search_term = trim( $args['search'] ?? '' );
		if ( $search_term !== '' ) {
			$like = '%' . $wpdb->esc_like( $search_term ) . '%';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$plain_parts[]   = "(data LIKE %s OR form_id IN (SELECT id FROM {$forms_table} WHERE name LIKE %s))";
			$aliased_parts[] = '(e.data LIKE %s OR f.name LIKE %s)';
			$values[]        = $like;
			$values[]        = $like;
		}

		// Date range - applied to submitted_at.
		// Expected format: 'YYYY-MM-DD'. Time bounds are added here so a
		// date_to of '2026-04-16' includes entries submitted at any time on that day.
		// These args are only populated by list_for_export(); list() never passes
		// them, so the conditions are simply absent for normal inbox queries.
		$date_from = $args['date_from'] ?? '';
		$date_to   = $args['date_to']   ?? '';

		if ( $date_from !== '' ) {
			$plain_parts[]   = 'submitted_at >= %s';
			$aliased_parts[] = 'e.submitted_at >= %s';
			$values[]        = $date_from . ' 00:00:00';
		}
		if ( $date_to !== '' ) {
			$plain_parts[]   = 'submitted_at <= %s';
			$aliased_parts[] = 'e.submitted_at <= %s';
			$values[]        = $date_to . ' 23:59:59';
		}

		return [
			'plain'   => $plain_parts   ? 'WHERE ' . implode( ' AND ', $plain_parts )   : '',
			'aliased' => $aliased_parts ? 'WHERE ' . implode( ' AND ', $aliased_parts ) : '',
			'values'  => $values,
		];
	}

	/**
	 * Decode the `data` JSON column back to a PHP array for a single row.
	 * Also casts numeric IDs so callers never receive strings for those fields.
	 *
	 * @param array $row  Raw associative array from $wpdb.
	 * @return array
	 */
	private static function decode( array $row ): array {
		$row['id']          = (int) $row['id'];
		$row['form_id']     = (int) $row['form_id'];
		$row['is_starred']  = isset( $row['is_starred']  ) ? (int) $row['is_starred']  : 0;
		$row['needs_reply'] = isset( $row['needs_reply'] ) ? (int) $row['needs_reply'] : 0;
		$row['ai_score']    = isset( $row['ai_score'] ) && $row['ai_score'] !== null
			? (int) $row['ai_score']
			: null;

		// AI spam detection columns.
		$row['ai_is_spam']           = isset( $row['ai_is_spam'] )           ? (int) $row['ai_is_spam']           : 0;
		$row['ai_spam_confidence']   = isset( $row['ai_spam_confidence'] )   ? (int) $row['ai_spam_confidence']   : 0;
		$row['ai_spam_reason']       = isset( $row['ai_spam_reason'] )       ? (string) $row['ai_spam_reason']    : '';
		$row['ai_auto_spam_applied'] = isset( $row['ai_auto_spam_applied'] ) ? (int) $row['ai_auto_spam_applied'] : 0;

		// form_name is present when called from list() (LEFT JOIN).
		// Absent when called from get() (no JOIN) - normalise to null either way
		// so callers always get a consistent key regardless of which method ran.
		$row['form_name'] = isset( $row['form_name'] )
			? (string) $row['form_name']
			: null;

		$decoded = json_decode( $row['data'] ?? '{}', true );

		if ( $decoded === null && json_last_error() !== JSON_ERROR_NONE ) {
			// The row exists but the data column is corrupt. Fall back to an
			// empty array so callers can still render the row (id, status, etc.)
			// without crashing. Log the raw error so the issue is not silent.
			Logger::file(
				'storage',
				'error',
				sprintf( 'Corrupt JSON in entry %d: %s', $row['id'], json_last_error_msg() )
			);
			$decoded = [];
		}

		$row['data'] = $decoded ?: [];

		return $row;
	}

	/**
	 * All valid entry status values.
	 *
	 * Public so the REST endpoint and any future UI code can reference this
	 * single list instead of duplicating it.
	 *
	 *   new    - just received, not yet reviewed
	 *   read   - viewed in the admin entries list
	 *   spam   - flagged by the spam check hook
	 *   trash  - soft-deleted, pending permanent removal
	 */
	public const VALID_STATUSES = [ 'new', 'read', 'spam', 'trash' ];

	/**
	 * Returns the value if it is a known status, otherwise 'new'.
	 * Uses VALID_STATUSES as the single source of truth.
	 *
	 * @param string $status
	 * @return string
	 */
	public static function valid_status( string $status ): string {
		return in_array( $status, self::VALID_STATUSES, true )
			? $status
			: 'new';
	}

	/**
	 * Full table name including WP prefix.
	 *
	 * @return string
	 */
	/**
	 * Strip layout-container keys from the submission data array.
	 *
	 * Rows, columns, and steps use the prefixes 'row_', 'col_', and
	 * 'step_' (see SchemaHelper::generate_row_id /
	 * generate_column_id / generate_step_id). They are layout-only -
	 * they must never appear in entry data. This is a belt-and-
	 * suspenders sanitizer; SubmitEndpoint already flattens the
	 * schema before sanitization runs.
	 */
	private static function strip_layout_keys( array $data ): array {
		$out = [];
		foreach ( $data as $key => $value ) {
			$k = (string) $key;
			if ( strpos( $k, 'row_' ) === 0
				|| strpos( $k, 'col_' ) === 0
				|| strpos( $k, 'step_' ) === 0
				|| strpos( $k, 'section_' ) === 0 ) {
				continue;
			}
			if ( is_array( $value ) ) {
				$type = $value['type'] ?? '';
				if ( $type === 'row' || $type === 'step' || $type === 'column' || $type === 'section' ) {
					continue;
				}
			}
			$out[ $key ] = $value;
		}
		return $out;
	}

	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'neural_forms_entries';
	}

	/**
	 * Aggregate poll votes for a single field of a single form.
	 *
	 * Walks the entries table, decoding the JSON `data` column for
	 * every non-trashed entry and tallying values keyed by $field_id.
	 * No new index is required - `form_id` is already indexed and the
	 * per-row JSON decode dominates the cost.
	 *
	 * Spam entries are intentionally NOT excluded here: spam state is a
	 * Pro capability, and Lite installs have no spam classification, so
	 * silently dropping `status = 'spam'` rows would diverge between
	 * Lite and Pro for the same data. Phase 3 / future iterations may
	 * add an opt-in filter.
	 *
	 * Malformed entries (invalid JSON, non-array decoded shape) are
	 * skipped without fatal.
	 *
	 * Return shape:
	 *   [ 'total'  => int,
	 *     'counts' => [ option_value => count, ... ] ]
	 *
	 * Caller is responsible for splitting `counts` into known/unknown
	 * (Other) buckets using the field's configured options[].
	 */
	public static function aggregate_poll_votes( int $form_id, string $field_id ): array {
		global $wpdb;

		$out = [ 'total' => 0, 'counts' => [] ];

		if ( $form_id <= 0 || $field_id === '' ) {
			return $out;
		}

		$rows = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT data FROM %i WHERE form_id = %d AND status != %s',
				self::table(),
				$form_id,
				'trash'
			)
		);

		if ( empty( $rows ) || ! is_array( $rows ) ) {
			return $out;
		}

		foreach ( $rows as $json ) {
			if ( ! is_string( $json ) || $json === '' ) continue;
			$decoded = json_decode( $json, true );
			if ( ! is_array( $decoded ) ) continue;
			if ( ! array_key_exists( $field_id, $decoded ) ) continue;

			$value = $decoded[ $field_id ];
			// Single-choice poll v1: ignore array (multi) values
			// defensively. Phase 2 of allow_multiple will revisit.
			if ( is_array( $value ) ) continue;
			if ( $value === null || $value === '' ) continue;

			$key = (string) $value;
			$out['counts'][ $key ] = ( $out['counts'][ $key ] ?? 0 ) + 1;
			$out['total']++;
		}

		return $out;
	}
}
