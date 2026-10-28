<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
//
// This repository owns the wp_neural_forms_* tables and runs typed CRUD
// against them. Object caching is intentionally not used here:
// - REST handlers fetch fresh per request; entries / forms see frequent
// writes, so a stale cache would surface in the admin UI.
// - Cache invalidation across the create/update/delete + bulk paths
// would add more risk than the few percent of latency it would save.
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
	public static function create( int $form_id, array $sanitized_data, array $context = array() ) {
		global $wpdb;

		$now = current_time( 'mysql', true );  // UTC

		$context = array_merge(
			array(
				'ip'           => '',
				'user_agent'   => '',
				'page_url'     => '',
				'submitted_at' => $now,
				'form_version' => '',
			),
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
		//
		// UNESCAPED flags matter for search: the inbox search is a LIKE on
		// this blob, so "José" must be stored as José, not "Jos\u00e9", and
		// "https://x" as-is rather than "https:\/\/x". Rows written before
		// 1.1.3 still carry the escaped form; build_filter_clauses() matches
		// both, so no migration is needed.
		$json = wp_json_encode( $sanitized_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		if ( false === $json ) {
			Logger::file( 'storage', 'error', sprintf( 'wp_json_encode() returned false for form %d', $form_id ) );
			return new \WP_Error(
				'entry_encode_failed',
				__( 'Could not encode submission data.', 'neural-forms-lite' ),
				array( 'status' => 500 )
			);
		}

		$inserted = $wpdb->insert(
			self::table(),
			array(
				'form_id'      => $form_id,
				'form_version' => substr( $context['form_version'], 0, 32 ),
				'status'       => 'new',
				'data'         => $json,
				'ip_address'   => substr( $context['ip'], 0, 45 ),
				'user_agent'   => $context['user_agent'],
				'page_url'     => $context['page_url'],
				'submitted_at' => $context['submitted_at'],
				'created_at'   => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			$db_err = $wpdb->last_error ?: '(no wpdb error message)';
			Logger::file(
				'storage',
				'error',
				sprintf( 'EntryRepository::create() DB insert failed for form %d: %s', $form_id, $db_err )
			);

			return new \WP_Error(
				'entry_save_failed',
				__( 'Could not save the entry.', 'neural-forms-lite' ),
				array( 'status' => 500 )
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

		$data   = array();
		$format = array();

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
			array( 'id' => $id ),
			$format,
			array( '%d' )
		);

		return false !== $result;
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
		return self::update( $id, array( 'status' => $status ) );
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
			return array();
		}

		// Build SET clause from the same allowlist as update().
		$set_parts  = array();
		$set_values = array();

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
			return array();
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
			...array_merge( array( $table ), $set_values, $ids )
		);
		$wpdb->query( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// Return the subset of requested IDs that actually exist in the table.
		$confirmed_placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$confirmed              = $wpdb->get_col(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"SELECT id FROM %i WHERE id IN ({$confirmed_placeholders})",
				...array_merge( array( $table ), $ids )
			)
		);

		return array_map( 'intval', $confirmed ?: array() );
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
	public static function count_by_view( ?int $form_id = null, array $args = array() ): array {
		global $wpdb;

		$table        = self::table();
		$scope_clause = '';
		if ( $form_id ) {
			$scope_clause = $wpdb->prepare( ' AND form_id = %d', $form_id );
		}

		// Smart-folder scoping (0.2.0). When the caller passes a
		// `form_ids` array (Polls/Quizzes folders) or `exclude_form_ids`
		// (All Forms → Inbox excluding poll/quiz/mixed), we layer those
		// onto the AND-chain of every per-view count.
		if ( isset( $args['form_ids'] ) && is_array( $args['form_ids'] ) ) {
			$ids = array_values(
				array_unique(
					array_filter(
						array_map( 'intval', $args['form_ids'] ),
						static fn ( $id ) => $id > 0
					)
				)
			);
			if ( empty( $ids ) ) {
				// No matching forms → all counts zero. Short-circuit
				// with a literal false predicate.
				$scope_clause .= ' AND 0 = 1';
			} else {
				// `$placeholders` is a method-internal string of `%d`
				// tokens; the values flow through prepare's variadic
				// args. PCP's "UnfinishedPrepare" sniff doesn't model
				// fragment-style clause builders.
				$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				$scope_clause .= $wpdb->prepare( " AND form_id IN ({$placeholders})", ...$ids );
			}
		}
		if ( isset( $args['exclude_form_ids'] ) && is_array( $args['exclude_form_ids'] ) ) {
			$exclude = array_values(
				array_unique(
					array_filter(
						array_map( 'intval', $args['exclude_form_ids'] ),
						static fn ( $id ) => $id > 0
					)
				)
			);
			if ( ! empty( $exclude ) ) {
				$placeholders = implode( ', ', array_fill( 0, count( $exclude ), '%d' ) );
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				$scope_clause .= $wpdb->prepare( " AND form_id NOT IN ({$placeholders})", ...$exclude );
			}
		}

		$count = static function ( string $where ) use ( $wpdb, $table, $scope_clause ): int {
			// $where is a hardcoded literal from the array below; $scope_clause
			// is already prepared. Table flows through %i.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$value = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE {$where}{$scope_clause}", $table ) );
			return (int) $value;
		};

		return array(
			'inbox'          => $count( "status IN ('new','read')" ),
			'unread'         => $count( "status = 'new'" ),
			'starred'        => $count( 'is_starred = 1' ),
			'needs_reply'    => $count( 'needs_reply = 1' ),
			'spam'           => $count( "status = 'spam'" ),
			'trash'          => $count( "status = 'trash'" ),
			'high_priority'  => $count( "ai_priority = 'high' AND status IN ('new','read')" ),
			'spam_suspected' => $count( "ai_is_spam = 1 AND ai_auto_spam_applied = 0 AND status NOT IN ('spam','trash')" ),
		);
	}

	/**
	 * Smart-folder counts for the Entries sidebar (0.2.0).
	 *
	 * Returns the totals shown next to "Polls" and "Quizzes" nav items.
	 * Counts include status='new'+'read' only — same scope as the
	 * regular Inbox view (excludes spam/trash). Mixed forms count
	 * toward Quizzes per the brief's mixed→quiz routing rule.
	 *
	 * Returns 0 for any folder whose form-id resolution yields an
	 * empty list — the sidebar uses these counts to decide whether
	 * to render the folder at all.
	 *
	 * @return array{ polls:int, quizzes:int }
	 */
	public static function count_smart_folders(): array {
		$poll_ids = \NeuralForms\Forms\FormRepository::get_form_ids_by_detected_type( array( 'poll' ) );
		$quiz_ids = \NeuralForms\Forms\FormRepository::get_form_ids_by_detected_type( array( 'quiz', 'mixed' ) );

		$tally = static function ( array $ids ) {
			if ( empty( $ids ) ) {
				return 0;
			}
			global $wpdb;
			$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
			$table        = self::table();
			$args         = array_merge( array( $table ), $ids );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM %i WHERE status IN ('new','read') AND form_id IN ({$placeholders})",
					...$args
				)
			);
		};

		return array(
			'polls'   => $tally( $poll_ids ),
			'quizzes' => $tally( $quiz_ids ),
		);
	}

	/**
	 * Count non-trashed entries for a single form.
	 *
	 * Used by the Lite poll/quiz submission limit (200 per form).
	 * Spam IS counted because spam entries still consume storage and
	 * are real submissions; only `status = 'trash'` is excluded so a
	 * site owner can recover capacity by emptying trash.
	 *
	 * @param int $form_id
	 * @return int
	 */
	public static function count_non_trash_entries_for_form( int $form_id ): int {
		if ( $form_id < 1 ) {
			return 0;
		}
		global $wpdb;
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE form_id = %d AND status != 'trash'",
				self::table(),
				$form_id
			)
		);
		return (int) $count;
	}

	/**
	 * Aggregate per-form totals for the Forms list.
	 *
	 * Returns one row per requested id with non-trash entry_count and
	 * spam-status spam_count. A single grouped query handles every form
	 * in the page so the Forms list does not fan out into N COUNT(*)
	 * queries — the typical admin Forms list shows 20 forms per page.
	 *
	 * Forms with zero entries are still represented in the result so
	 * callers can blindly look up by id without checking isset().
	 *
	 * @param int[] $form_ids
	 * @return array<int, array{entry_count:int, spam_count:int}>
	 */
	public static function count_form_totals( array $form_ids ): array {
		global $wpdb;

		$ids = array_values(
			array_unique(
				array_filter(
					array_map( 'intval', $form_ids ),
					static fn ( $id ) => $id > 0
				)
			)
		);
		if ( empty( $ids ) ) {
			return array();
		}

		$out = array();
		foreach ( $ids as $id ) {
			$out[ $id ] = array(
				'entry_count' => 0,
				'spam_count'  => 0,
			);
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$table        = self::table();

		// One grouped query: counts both buckets per form_id. CASE WHEN
		// inside SUM() avoids a second round-trip for spam.
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"SELECT form_id,
				        SUM( CASE WHEN status != 'trash' THEN 1 ELSE 0 END ) AS entry_count,
				        SUM( CASE WHEN status =  'spam'  THEN 1 ELSE 0 END ) AS spam_count
				 FROM %i
				 WHERE form_id IN ({$placeholders})
				 GROUP BY form_id",
				...array_merge( array( $table ), $ids )
			),
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$fid = (int) ( $row['form_id'] ?? 0 );
			if ( ! isset( $out[ $fid ] ) ) {
				continue;
			}
			$out[ $fid ] = array(
				'entry_count' => (int) ( $row['entry_count'] ?? 0 ),
				'spam_count'  => (int) ( $row['spam_count'] ?? 0 ),
			);
		}

		return $out;
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
			array( 'id' => $id ),
			array( '%d' )
		);

		return false !== $result;
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
			return array();
		}

		// Capture which IDs actually exist before deleting them.
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$table        = self::table();

		$existing = $wpdb->get_col(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"SELECT id FROM %i WHERE id IN ({$placeholders})",
				...array_merge( array( $table ), $ids )
			)
		);

		$existing = array_map( 'intval', $existing ?: array() );

		if ( empty( $existing ) ) {
			return array();
		}

		$del_placeholders = implode( ',', array_fill( 0, count( $existing ), '%d' ) );

		$wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"DELETE FROM %i WHERE id IN ({$del_placeholders})",
				...array_merge( array( $table ), $existing )
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
	 * Entries whose submitted data contains the given email address.
	 *
	 * Backs the WordPress "Export / Erase Personal Data" tools. A LIKE
	 * pre-filter on the JSON blob narrows the scan; the decoded data is
	 * then checked with data_contains_email() for an exact (case-
	 * insensitive) match so "ann@x.co" never matches "joann@x.com".
	 *
	 * @param string $email
	 * @param int    $per_page  1-200.
	 * @param int    $page      1-based.
	 * @return array  Decoded entry rows (same shape as get()).
	 */
	public static function find_by_email( string $email, int $per_page = 50, int $page = 1 ): array {
		global $wpdb;

		$email = strtolower( trim( $email ) );
		if ( $email === '' ) {
			return array();
		}
		$per_page = max( 1, min( 200, $per_page ) );
		$offset   = max( 0, ( $page - 1 ) * $per_page );

		// Over-fetch: the LIKE is only a pre-filter, so a page may lose
		// rows to the exact-match pass. Fetch a generous window and trim.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE data LIKE %s ORDER BY id ASC LIMIT %d OFFSET %d',
				self::table(),
				'%' . $wpdb->esc_like( $email ) . '%',
				$per_page,
				$offset
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$row = self::decode( $row );
			if ( self::data_contains_email( is_array( $row['data'] ?? null ) ? $row['data'] : array(), $email ) ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/**
	 * True when any (nested) scalar in the submitted data equals $email,
	 * case-insensitively and ignoring surrounding whitespace.
	 *
	 * @param array  $data
	 * @param string $email
	 */
	public static function data_contains_email( array $data, string $email ): bool {
		$needle = strtolower( trim( $email ) );
		if ( $needle === '' ) {
			return false;
		}
		$stack = array( $data );
		while ( $stack ) {
			$node = array_pop( $stack );
			foreach ( $node as $v ) {
				if ( is_array( $v ) ) {
					$stack[] = $v;
				} elseif ( is_scalar( $v ) && strtolower( trim( (string) $v ) ) === $needle ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * System view identifiers and the WHERE clauses they expand to.
	 * Used by list() and count_by_view().
	 */
	public const VALID_VIEWS = array( 'inbox', 'unread', 'starred', 'needs_reply', 'spam', 'trash', 'high_priority', 'spam_suspected' );

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
	public static function list( int $form_id, array $args = array() ): array {
		global $wpdb;

		$defaults = array(
			'view'       => '',
			'status'     => 'any',
			'per_page'   => 20,
			'page'       => 1,
			'orderby'    => 'submitted_at',
			'order'      => 'DESC',
			'search'     => '',
			'sort_order' => '',
		);
		$args     = array_merge( $defaults, $args );

		// sort_order is a convenience alias: when present it forces submitted_at
		// as the sort column and overrides the legacy orderby/order args.
		if ( $args['sort_order'] !== '' ) {
			$sort_order = strtolower( (string) $args['sort_order'] );
			$orderby    = 'submitted_at';
			$order      = in_array( $sort_order, array( 'asc', 'desc' ), true )
				? strtoupper( $sort_order )
				: 'DESC';
		} else {
			$allowed_columns = array( 'id', 'status', 'submitted_at', 'created_at' );
			$orderby         = in_array( $args['orderby'], $allowed_columns, true )
				? $args['orderby']
				: 'submitted_at';
			$order           = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';
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
		$count_args = array_merge( array( $entries_table ), $values );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM %i {$where_plain}", ...$count_args )
		);

		// Main SELECT - both table names flow through %i; $orderby is
		// whitelisted against $allowed_columns and $order is forced to
		// ASC|DESC, so they are safe to interpolate.
		$main_args = array_merge( array( $entries_table, $forms_table ), $values, array( $per_page, $offset ) );
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

		// Enrich each row with `has_reply` (1 when at least one outgoing
		// message has been sent for this entry). A single small lookup
		// against the messages table — kept out of the main SELECT so the
		// row-level join cardinality stays clean and the prepare argument
		// order doesn't get tangled. Used by the list UI to flip the
		// status pill from "Read" to "Replied".
		if ( $rows ) {
			$entry_ids = array_map( static fn( $r ) => (int) $r['id'], $rows );
			$replied   = self::entry_ids_with_outgoing_message( $entry_ids );
			foreach ( $rows as &$r ) {
				$r['has_reply'] = isset( $replied[ (int) $r['id'] ] ) ? 1 : 0;
			}
			unset( $r );
		}

		return array(
			'items' => array_map( array( self::class, 'decode' ), $rows ?: array() ),
			'total' => $total,
		);
	}

	/**
	 * Return a set (id => true) of entry IDs that have at least one
	 * outgoing message in the messages table. Cheaper than EXISTS()
	 * subqueries in the main SELECT — single round-trip, no join
	 * cardinality concerns.
	 *
	 * @param int[] $entry_ids
	 * @return array<int,bool>
	 */
	private static function entry_ids_with_outgoing_message( array $entry_ids ): array {
		global $wpdb;

		$entry_ids = array_filter( array_map( 'intval', $entry_ids ), static fn( $id ) => $id > 0 );
		if ( ! $entry_ids ) {
			return array();
		}

		$messages_table = $wpdb->prefix . 'neural_forms_entry_messages';
		$placeholders   = implode( ',', array_fill( 0, count( $entry_ids ), '%d' ) );
		$args           = array_merge( array( $messages_table ), $entry_ids );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT entry_id FROM %i WHERE type = 'outgoing' AND entry_id IN ({$placeholders})",
				...$args
			)
		);

		$set = array();
		foreach ( (array) $ids as $id ) {
			$set[ (int) $id ] = true;
		}
		return $set;
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
	public static function list_for_export( int $form_id, array $args = array() ): array {
		global $wpdb;

		$entries_table = self::table();
		$forms_table   = $wpdb->prefix . 'neural_forms_forms';

		$sort_order_raw = strtolower( (string) ( $args['sort_order'] ?? 'desc' ) );
		$export_order   = in_array( $sort_order_raw, array( 'asc', 'desc' ), true )
			? strtoupper( $sort_order_raw )
			: 'DESC';

		$clauses = self::build_filter_clauses( $form_id, $args );
		$where   = $clauses['aliased'];
		$values  = $clauses['values'];

		// Hard cap at 5 000 rows - prevents runaway memory on large installs.
		$cap_args = array_merge( array( $entries_table, $forms_table ), $values, array( 5000 ) );
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

		return array_map( array( self::class, 'decode' ), $rows ?: array() );
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

		$plain_parts   = array();
		$aliased_parts = array();
		$values        = array();

		// Optional form scope.
		if ( $form_id > 0 ) {
			$plain_parts[]   = 'form_id = %d';
			$aliased_parts[] = 'e.form_id = %d';
			$values[]        = $form_id;
		}

		// Smart-folder scoping (added 0.2.0). Polls / Quizzes folders
		// resolve to a list of form ids via FormRepository::get_form_ids_by_detected_type
		// and pass them through here as `form_ids`. The IN-clause is
		// composed from %d placeholders so it stays prepared.
		//
		// Empty array on `form_ids` means "no forms match this folder";
		// short-circuit by inserting an always-false predicate so the
		// query returns zero rows + zero count without dropping the
		// rest of the WHERE chain.
		if ( isset( $args['form_ids'] ) && is_array( $args['form_ids'] ) ) {
			$ids = array_values(
				array_unique(
					array_filter(
						array_map( 'intval', $args['form_ids'] ),
						static fn ( $id ) => $id > 0
					)
				)
			);
			if ( empty( $ids ) ) {
				$plain_parts[]   = '0 = 1';
				$aliased_parts[] = '0 = 1';
			} else {
				$placeholders    = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
				$plain_parts[]   = "form_id IN ({$placeholders})";
				$aliased_parts[] = "e.form_id IN ({$placeholders})";
				// Same placeholders, same values, twice (once for the
				// COUNT/plain query, once for the SELECT/aliased query —
				// both run with `array_merge( ..., $values )`). $values
				// is shared between the two, so we list the ids once
				// and the prepared-statement substitution wraps both
				// fragments with the same value list. WAIT — the two
				// fragments use the SAME values array on each query
				// invocation; the ids must appear once in $values.
				$values = array_merge( $values, $ids );
			}
		}

		// Exclude-list scoping. Used by the All Forms → Inbox folder
		// to hide poll/quiz/mixed entries (the smart folders own them).
		if ( isset( $args['exclude_form_ids'] ) && is_array( $args['exclude_form_ids'] ) ) {
			$exclude = array_values(
				array_unique(
					array_filter(
						array_map( 'intval', $args['exclude_form_ids'] ),
						static fn ( $id ) => $id > 0
					)
				)
			);
			if ( ! empty( $exclude ) ) {
				$placeholders    = implode( ', ', array_fill( 0, count( $exclude ), '%d' ) );
				$plain_parts[]   = "form_id NOT IN ({$placeholders})";
				$aliased_parts[] = "e.form_id NOT IN ({$placeholders})";
				$values          = array_merge( $values, $exclude );
			}
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
		// Both variants add the same placeholders so the shared $values array
		// stays aligned. $forms_table is `$wpdb->prefix . 'neural_forms_forms'`,
		// never user-derived.
		//
		// Entries saved before 1.1.3 were encoded with default wp_json_encode
		// flags, so non-ASCII is stored as \uXXXX and '/' as '\/'. Entries
		// saved since are stored unescaped. When the term's JSON-escaped form
		// differs from the raw term, match both so old and new rows are found.
		$search_term = trim( $args['search'] ?? '' );
		if ( $search_term !== '' ) {
			$like      = '%' . $wpdb->esc_like( $search_term ) . '%';
			$data_like = array( $like );

			$escaped = wp_json_encode( $search_term );
			$escaped = is_string( $escaped ) ? substr( $escaped, 1, -1 ) : $search_term;
			if ( $escaped !== $search_term ) {
				$data_like[] = '%' . $wpdb->esc_like( $escaped ) . '%';
			}

			$data_sql = implode( ' OR ', array_fill( 0, count( $data_like ), 'data LIKE %s' ) );
			$data_sql_aliased = implode( ' OR ', array_fill( 0, count( $data_like ), 'e.data LIKE %s' ) );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$plain_parts[]   = "({$data_sql} OR form_id IN (SELECT id FROM {$forms_table} WHERE name LIKE %s))";
			$aliased_parts[] = "({$data_sql_aliased} OR f.name LIKE %s)";
			foreach ( $data_like as $dl ) {
				$values[] = $dl;
			}
			$values[] = $like;
		}

		// Date range - applied to submitted_at.
		// Expected format: 'YYYY-MM-DD'. Time bounds are added here so a
		// date_to of '2026-04-16' includes entries submitted at any time on that day.
		// These args are only populated by list_for_export(); list() never passes
		// them, so the conditions are simply absent for normal inbox queries.
		$date_from = $args['date_from'] ?? '';
		$date_to   = $args['date_to'] ?? '';

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

		return array(
			'plain'   => $plain_parts ? 'WHERE ' . implode( ' AND ', $plain_parts ) : '',
			'aliased' => $aliased_parts ? 'WHERE ' . implode( ' AND ', $aliased_parts ) : '',
			'values'  => $values,
		);
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
		$row['is_starred']  = isset( $row['is_starred'] ) ? (int) $row['is_starred'] : 0;
		$row['needs_reply'] = isset( $row['needs_reply'] ) ? (int) $row['needs_reply'] : 0;
		$row['ai_score']    = isset( $row['ai_score'] ) && null !== $row['ai_score']
			? (int) $row['ai_score']
			: null;

		// AI spam detection columns.
		$row['ai_is_spam']           = isset( $row['ai_is_spam'] ) ? (int) $row['ai_is_spam'] : 0;
		$row['ai_spam_confidence']   = isset( $row['ai_spam_confidence'] ) ? (int) $row['ai_spam_confidence'] : 0;
		$row['ai_spam_reason']       = isset( $row['ai_spam_reason'] ) ? (string) $row['ai_spam_reason'] : '';
		$row['ai_auto_spam_applied'] = isset( $row['ai_auto_spam_applied'] ) ? (int) $row['ai_auto_spam_applied'] : 0;

		// has_reply is computed by list() via an EXISTS() subquery against
		// the messages table. MySQL returns "1"/"0" strings; normalise to int
		// so the JS can use a single truthy check. Absent on get() — there's
		// a separate path that loads the full message thread for detail view.
		$row['has_reply'] = isset( $row['has_reply'] ) ? (int) $row['has_reply'] : 0;

		// form_name is present when called from list() (LEFT JOIN).
		// Absent when called from get() (no JOIN) - normalise to null either way
		// so callers always get a consistent key regardless of which method ran.
		$row['form_name'] = isset( $row['form_name'] )
			? (string) $row['form_name']
			: null;

		$decoded = json_decode( $row['data'] ?? '{}', true );

		if ( null === $decoded && json_last_error() !== JSON_ERROR_NONE ) {
			// The row exists but the data column is corrupt. Fall back to an
			// empty array so callers can still render the row (id, status, etc.)
			// without crashing. Log the raw error so the issue is not silent.
			Logger::file(
				'storage',
				'error',
				sprintf( 'Corrupt JSON in entry %d: %s', $row['id'], json_last_error_msg() )
			);
			$decoded = array();
		}

		$row['data'] = $decoded ?: array();

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
	public const VALID_STATUSES = array( 'new', 'read', 'spam', 'trash' );

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
		$out = array();
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

		$out = array(
			'total'  => 0,
			'counts' => array(),
		);

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
			if ( ! is_string( $json ) || $json === '' ) {
				continue;
			}
			$decoded = json_decode( $json, true );
			if ( ! is_array( $decoded ) ) {
				continue;
			}
			if ( ! array_key_exists( $field_id, $decoded ) ) {
				continue;
			}

			$value = $decoded[ $field_id ];
			// Single-choice poll v1: ignore array (multi) values
			// defensively. Phase 2 of allow_multiple will revisit.
			if ( is_array( $value ) ) {
				continue;
			}
			if ( null === $value || $value === '' ) {
				continue;
			}

			$key                   = (string) $value;
			$out['counts'][ $key ] = ( $out['counts'][ $key ] ?? 0 ) + 1;
			++$out['total'];
		}

		return $out;
	}

	/**
	 * List recent non-trash entries for a form with their decoded
	 * `data` blob, id, and submitted_at timestamp. Ordered by id DESC
	 * (newest first). Powers the Results screen's Individual Attempts
	 * table.
	 *
	 * Spam state is intentionally NOT excluded — same rationale as
	 * aggregate_poll_votes / aggregate_quiz_answers (spam is a Pro
	 * capability; Lite installs have no classification).
	 *
	 * @param int $form_id
	 * @param int $limit   Max rows to return. Caller should cap (≤200).
	 * @param int $offset
	 * @return array  [ ['id'=>int, 'submitted_at'=>string, 'data'=>array], ... ]
	 */
	public static function list_form_entries_with_data( int $form_id, int $limit = 50, int $offset = 0 ): array {
		global $wpdb;
		if ( $form_id <= 0 || $limit <= 0 ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, submitted_at, data FROM %i
				 WHERE form_id = %d AND status != %s
				 ORDER BY id DESC
				 LIMIT %d OFFSET %d',
				self::table(),
				$form_id,
				'trash',
				$limit,
				max( 0, $offset )
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $r ) {
			$decoded = is_string( $r['data'] ?? '' ) ? json_decode( $r['data'], true ) : null;
			$out[]   = array(
				'id'           => (int) ( $r['id'] ?? 0 ),
				'submitted_at' => (string) ( $r['submitted_at'] ?? '' ),
				'data'         => is_array( $decoded ) ? $decoded : array(),
			);
		}
		return $out;
	}

	/**
	 * Aggregate quiz answers for one or more quiz_question fields on a form.
	 *
	 * Walks the entries table once per call. Returns:
	 *
	 *   [
	 *     'rows' => array[]   // each non-trash entry's decoded data
	 *                            (used by callers that need to compute
	 *                            per-entry score for averages)
	 *     'counts' => [
	 *        field_id => [ option_value => answer_count, ... ],
	 *        ...
	 *     ],
	 *     'totals' => [
	 *        field_id => total_answers_for_that_field,
	 *        ...
	 *     ],
	 *     'entry_count' => int    // non-trash entries
	 *   ]
	 *
	 * Spam state is intentionally NOT excluded — same rationale as
	 * aggregate_poll_votes(): spam classification is a Pro capability.
	 *
	 * Malformed JSON / non-array shapes are skipped without fatal.
	 *
	 * @param int      $form_id
	 * @param string[] $field_ids  Quiz field ids to aggregate.
	 * @return array
	 */
	public static function aggregate_quiz_answers( int $form_id, array $field_ids ): array {
		global $wpdb;

		$out = array(
			'rows'        => array(),
			'counts'      => array(),
			'totals'      => array(),
			'entry_count' => 0,
		);

		if ( $form_id <= 0 || empty( $field_ids ) ) {
			return $out;
		}

		// Initialise zero counts so the caller doesn't have to default.
		foreach ( $field_ids as $fid ) {
			$out['counts'][ $fid ] = array();
			$out['totals'][ $fid ] = 0;
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
			if ( ! is_string( $json ) || $json === '' ) {
				continue;
			}
			$decoded = json_decode( $json, true );
			if ( ! is_array( $decoded ) ) {
				continue;
			}

			$out['rows'][] = $decoded;
			++$out['entry_count'];

			foreach ( $field_ids as $fid ) {
				if ( ! array_key_exists( $fid, $decoded ) ) {
					continue;
				}
				$value = $decoded[ $fid ];
				// Single-choice quiz v1: arrays defensively ignored.
				if ( is_array( $value ) ) {
					continue;
				}
				if ( null === $value || $value === '' ) {
					continue;
				}

				$key                           = (string) $value;
				$out['counts'][ $fid ][ $key ] = ( $out['counts'][ $fid ][ $key ] ?? 0 ) + 1;
				++$out['totals'][ $fid ];
			}
		}

		return $out;
	}
}
