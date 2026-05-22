<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
//
// Direct $wpdb calls against wp_neural_forms_* tables only. Object caching
// is skipped intentionally - these reads back the admin UI which expects
// fresh data after every status / reply / AI mutation.
namespace NeuralForms\Rest\Endpoints;

use NeuralForms\Core\Config;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * GET /stats?range=today|7d|30d|90d
 *
 * Returns every block the admin Stats screen needs:
 *
 *   range              - echoed back ('7d' default)
 *   summary            - 6 counters (total_forms, entries, unread,
 *                        needs_reply, spam_detected, failed_deliveries)
 *   trend              - one { date, entries } row per day in range
 *   ai                 - analyzed, high_priority, needs_reply,
 *                        spam_detected, top_category, sentiment
 *   top_forms          - top 5 forms by entry count in range
 *   integration_health - filled by Pro via the
 *                        `neural_forms_integration_health` filter;
 *                        empty in a Lite-only install
 *   recent_entries     - last 8 entries with status pill + submitter
 *   recent_activity    - last 10 log rows (back-compat / System Activity)
 *   needs_attention    - up to 8 entries requiring action (back-compat)
 *   system             - ai_engine, queue, email_failures,
 *                        integration_failures, last_cron_run
 */
class StatsEndpoint {

	const RANGES = array(
		'today' => 1,
		'7d'    => 7,
		'30d'   => 30,
		'90d'   => 90,
	);

	const DEFAULT_RANGE = '7d';

	public function register_routes(): void {
		$ns = \NeuralForms\Rest\RestAPI::NAMESPACE;

		register_rest_route(
			$ns,
			'/stats',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_stats' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'range' => array(
						'required'          => false,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		// Page Performance (1.1.0+): per-form aggregation of entries
		// grouped by source page. Capability-gated for Pro; Lite
		// callers receive a feature_locked error so the analytics
		// bundle can render an upsell card.
		register_rest_route(
			$ns,
			'/forms/(?P<id>\d+)/analytics/page-performance',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_page_performance' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'id' => array(
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	public function check_permission(): bool {
		return current_user_can( NEURAL_FORMS_CAP_MANAGE_FORMS );
	}

	public function get_stats( WP_REST_Request $request ) {
		$range_key = (string) $request->get_param( 'range' );
		if ( ! isset( self::RANGES[ $range_key ] ) ) {
			$range_key = self::DEFAULT_RANGE;
		}
		$days = self::RANGES[ $range_key ];

		return rest_ensure_response(
			array(
				'range'              => $range_key,
				'summary'            => $this->summary( $days ),
				'trend'              => $this->trend( $days ),
				'ai'                 => $this->ai_summary( $days ),
				'top_forms'          => $this->top_forms( $days ),
				'integration_health' => $this->integration_health(),
				'recent_entries'     => $this->recent_entries(),
				'needs_attention'    => $this->needs_attention(),
				'recent_activity'    => $this->recent_activity(),
				'system'             => $this->system_status_v2(),
			)
		);
	}

	/**
	 * Six counters. total_forms is lifetime; unread + needs_reply
	 * are lifetime open-counts (the inbox-actionable numbers the
	 * user wants to see regardless of range); entries / spam /
	 * failed_deliveries are range-scoped.
	 */
	private function summary( int $days ): array {
		global $wpdb;
		$entries = $wpdb->prefix . 'neural_forms_entries';
		$forms   = $wpdb->prefix . 'neural_forms_forms';
		$logs    = $wpdb->prefix . 'neural_forms_logs';

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
				   SUM( created_at >= DATE_SUB( UTC_TIMESTAMP(), INTERVAL %d DAY ) )                          AS entries,
				   SUM( status = 'new' )                                                                       AS unread,
				   SUM( needs_reply = 1 AND status IN ('new','read') )                                         AS needs_reply,
				   SUM( ai_is_spam = 1 AND created_at >= DATE_SUB( UTC_TIMESTAMP(), INTERVAL %d DAY ) )        AS spam_detected
				 FROM %i",
				$days,
				$days,
				$entries
			),
			ARRAY_A
		);

		$total_forms = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i', $forms )
		);

		$failed_deliveries = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i
				 WHERE event_type IN (
				     'reply_send_failed',
				     'admin_notification_failed',
				     'autoresponder_failed'
				 )
				 AND created_at >= DATE_SUB( UTC_TIMESTAMP(), INTERVAL %d DAY )",
				$logs,
				$days
			)
		);

		return array(
			'total_forms'       => $total_forms,
			'entries'           => (int) ( $row['entries'] ?? 0 ),
			'unread'            => (int) ( $row['unread'] ?? 0 ),
			'needs_reply'       => (int) ( $row['needs_reply'] ?? 0 ),
			'spam_detected'     => (int) ( $row['spam_detected'] ?? 0 ),
			'failed_deliveries' => $failed_deliveries,
		);
	}

	/** One { date, entries } row per UTC calendar day in range; missing days filled with 0. */
	private function trend( int $days ): array {
		global $wpdb;
		$entries = $wpdb->prefix . 'neural_forms_entries';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(created_at) AS day, COUNT(*) AS entries
				 FROM %i
				 WHERE created_at >= DATE_SUB( UTC_TIMESTAMP(), INTERVAL %d DAY )
				 GROUP BY DATE(created_at)
				 ORDER BY day ASC",
				$entries,
				$days
			),
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : array();

		$by_date = array();
		foreach ( $rows as $r ) {
			$by_date[ (string) $r['day'] ] = (int) $r['entries'];
		}

		$out = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$date  = gmdate( 'Y-m-d', time() - ( $i * DAY_IN_SECONDS ) );
			$out[] = array(
				'date'    => $date,
				'entries' => $by_date[ $date ] ?? 0,
			);
		}
		return $out;
	}

	/** Range-scoped AI rollup. `sentiment` stays null until that column ships. */
	private function ai_summary( int $days ): array {
		global $wpdb;
		$entries = $wpdb->prefix . 'neural_forms_entries';

		$agg = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
				   COUNT(*)                                              AS analyzed,
				   SUM( ai_priority = 'high' )                           AS high_priority,
				   SUM( needs_reply = 1 AND status IN ('new','read') )   AS needs_reply,
				   SUM( ai_is_spam = 1 )                                 AS spam_detected
				 FROM %i
				 WHERE ai_status = 'complete'
				   AND created_at >= DATE_SUB( UTC_TIMESTAMP(), INTERVAL %d DAY )",
				$entries,
				$days
			),
			ARRAY_A
		);

		$top_category = (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ai_category FROM %i
				 WHERE ai_status = 'complete'
				   AND ai_category IS NOT NULL
				   AND ai_category != ''
				   AND ai_category != 'general'
				   AND created_at >= DATE_SUB( UTC_TIMESTAMP(), INTERVAL %d DAY )
				 GROUP BY ai_category
				 ORDER BY COUNT(*) DESC
				 LIMIT 1",
				$entries,
				$days
			)
		);

		return array(
			'analyzed'      => (int) ( $agg['analyzed']      ?? 0 ),
			'high_priority' => (int) ( $agg['high_priority'] ?? 0 ),
			'needs_reply'   => (int) ( $agg['needs_reply']   ?? 0 ),
			'spam_detected' => (int) ( $agg['spam_detected'] ?? 0 ),
			'top_category'  => '' !== $top_category ? $top_category : null,
			'sentiment'     => null,
		);
	}

	/** Top 5 forms in range by entry count. Unread + needs_reply are lifetime (inbox-actionable). */
	private function top_forms( int $days ): array {
		global $wpdb;
		$entries = $wpdb->prefix . 'neural_forms_entries';
		$forms   = $wpdb->prefix . 'neural_forms_forms';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.form_id,
				        f.name AS form_title,
				        COUNT(*) AS entries,
				        SUM( e.status = 'new' )                                   AS unread,
				        SUM( e.needs_reply = 1 AND e.status IN ('new','read') )   AS needs_reply
				 FROM %i e LEFT JOIN %i f ON e.form_id = f.id
				 WHERE e.created_at >= DATE_SUB( UTC_TIMESTAMP(), INTERVAL %d DAY )
				 GROUP BY e.form_id, f.name
				 ORDER BY entries DESC
				 LIMIT 5",
				$entries,
				$forms,
				$days
			),
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : array();

		return array_map(
			static function ( array $r ): array {
				return array(
					'form_id'     => (int) $r['form_id'],
					'title'       => (string) ( $r['form_title'] ?? '' ),
					'entries'     => (int) $r['entries'],
					'unread'      => (int) $r['unread'],
					'needs_reply' => (int) $r['needs_reply'],
				);
			},
			$rows
		);
	}

	/**
	 * Filled by Pro via `neural_forms_integration_health`; empty on
	 * Lite installs. React side hides the card when providers map is
	 * empty.
	 */
	private function integration_health(): array {
		$filtered = apply_filters( 'neural_forms_integration_health', array() );
		return is_array( $filtered ) ? $filtered : array();
	}

	/** Last 8 non-trash entries with coarse status pill + best-effort submitter email. */
	private function recent_entries(): array {
		global $wpdb;
		$entries = $wpdb->prefix . 'neural_forms_entries';
		$forms   = $wpdb->prefix . 'neural_forms_forms';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.id, e.form_id, e.status, e.needs_reply,
				        e.ai_priority, e.ai_is_spam, e.ai_auto_spam_applied,
				        e.submitted_at, e.created_at, e.data,
				        f.name AS form_name
				 FROM %i e LEFT JOIN %i f ON e.form_id = f.id
				 WHERE e.status != 'trash'
				 ORDER BY e.created_at DESC
				 LIMIT 8",
				$entries,
				$forms
			),
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : array();

		return array_map(
			static function ( array $r ): array {
				$status = 'normal';
				if ( 1 === (int) $r['ai_is_spam'] || 'spam' === $r['status'] ) {
					$status = 'spam';
				} elseif ( 'high' === (string) $r['ai_priority'] && in_array( $r['status'], array( 'new', 'read' ), true ) ) {
					$status = 'high_priority';
				} elseif ( 1 === (int) $r['needs_reply'] && in_array( $r['status'], array( 'new', 'read' ), true ) ) {
					$status = 'needs_reply';
				}

				$submitter = '';
				$data      = json_decode( (string) ( $r['data'] ?? '{}' ), true );
				if ( is_array( $data ) ) {
					foreach ( array( 'email', 'submitter_email', 'your_email', 'email_address' ) as $k ) {
						if ( ! empty( $data[ $k ] ) && is_string( $data[ $k ] ) && false !== strpos( $data[ $k ], '@' ) ) {
							$submitter = (string) $data[ $k ];
							break;
						}
					}
				}

				return array(
					'entry_id'   => (int) $r['id'],
					'form_id'    => (int) $r['form_id'],
					'form_title' => (string) ( $r['form_name'] ?? '' ),
					'submitter'  => $submitter,
					'status'     => $status,
					'created_at' => (string) ( $r['created_at'] ?? '' ),
				);
			},
			$rows
		);
	}

	/** v2 system block — adds integration_failures + last_cron_run alongside legacy fields. */
	private function system_status_v2(): array {
		global $wpdb;

		$pending_jobs = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i
				 WHERE job_type = 'analyze_entry'
				   AND status IN ('pending','failed')
				   AND attempts < %d",
				$wpdb->prefix . 'neural_forms_queue',
				\NeuralForms\AI\AIQueueProcessor::MAX_ATTEMPTS
			)
		);

		$email_failures = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i
				 WHERE event_type IN (
				     'reply_send_failed',
				     'admin_notification_failed',
				     'autoresponder_failed'
				 )
				 AND created_at >= DATE_SUB( UTC_TIMESTAMP(), INTERVAL 24 HOUR )",
				$wpdb->prefix . 'neural_forms_logs'
			)
		);

		$next_run     = wp_next_scheduled( 'neural_forms_process_queue' );
		$last_cron_ts = is_int( $next_run ) && $next_run > 0
			? (int) $next_run - 5 * MINUTE_IN_SECONDS
			: 0;

		return array(
			'ai_engine'            => (bool) Config::get( 'ai_enabled' ) ? 'on' : 'off',
			'queue'                => $pending_jobs,
			'email_failures'       => $email_failures,
			'integration_failures' => (int) apply_filters( 'neural_forms_integration_failure_count', 0 ),
			'last_cron_run'        => $last_cron_ts > 0 ? gmdate( 'Y-m-d H:i:s', $last_cron_ts ) : null,
		);
	}

	// Sections

	/**
	 * Entries that need a human decision:
	 *   - AI marked high-priority (inbox only)
	 *   - Flagged needs_reply (inbox only)
	 *   - AI suspected spam but not auto-moved (not already spam/trash)
	 */
	private function needs_attention(): array {
		global $wpdb;
		$entries = $wpdb->prefix . 'neural_forms_entries';
		$forms   = $wpdb->prefix . 'neural_forms_forms';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.id, e.form_id, e.status, e.is_starred, e.needs_reply,
				        e.ai_priority, e.ai_category, e.ai_is_spam, e.ai_auto_spam_applied,
				        e.submitted_at, e.created_at, e.data,
				        f.name AS form_name
				 FROM %i e
				 LEFT JOIN %i f ON e.form_id = f.id
				 WHERE (
				     ( e.ai_priority = 'high'  AND e.status IN ('new','read') )
				     OR ( e.needs_reply = 1      AND e.status IN ('new','read') )
				     OR ( e.ai_is_spam = 1 AND e.ai_auto_spam_applied = 0
				          AND e.status NOT IN ('spam','trash') )
				 )
				 ORDER BY e.created_at DESC
				 LIMIT 8",
				$entries,
				$forms
			),
			ARRAY_A
		);

		return array_map(
			function ( array $row ): array {
				$data    = json_decode( $row['data'] ?? '{}', true ) ?: array();
				$preview = array_slice( $data, 0, 3, true );

				return array(
					'id'                   => (int) $row['id'],
					'form_id'              => (int) $row['form_id'],
					'form_name'            => isset( $row['form_name'] ) ? (string) $row['form_name'] : null,
					'status'               => $row['status'],
					'is_starred'           => (int) $row['is_starred'],
					'needs_reply'          => (int) $row['needs_reply'],
					'ai_priority'          => $row['ai_priority'],
					'ai_category'          => $row['ai_category'],
					'ai_is_spam'           => (int) $row['ai_is_spam'],
					'ai_auto_spam_applied' => (int) $row['ai_auto_spam_applied'],
					'submitted_at'         => $row['submitted_at'],
					'created_at'           => $row['created_at'],
					'preview'              => $preview,
				);
			},
			$rows ?: array()
		);
	}

	/**
	 * Four aggregate counts in a single conditional-aggregation query.
	 */
	private function stats(): array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
				   SUM( created_at >= DATE_SUB( UTC_TIMESTAMP(), INTERVAL 7 DAY ) ) AS entries_7d,
				   SUM( status = 'new' )                                             AS unread,
				   SUM( needs_reply = 1 AND status IN ('new','read') )               AS needs_reply,
				   SUM( ai_is_spam = 1 )                                             AS spam_detected
				 FROM %i",
				$wpdb->prefix . 'neural_forms_entries'
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return array(
				'entries_7d'    => 0,
				'unread'        => 0,
				'needs_reply'   => 0,
				'spam_detected' => 0,
			);
		}

		return array_map( 'intval', $row );
	}

	/**
	 * Category distribution + priority/sales/support/spam counts from completed analyses.
	 *
	 * Returns:
	 *   analyzed_count      - total entries where ai_status = 'complete'
	 *   top_category        - highest-count non-generic category, or null
	 *   categories          - up to 4 category/count pairs (for future category list)
	 *   high_priority_count - entries where ai_priority = 'high'
	 *   sales_count         - entries where ai_category = 'sales'
	 *   support_count       - entries where ai_category = 'support'
	 *   spam_detected_count - entries where ai_is_spam = 1 (among completed)
	 */
	private function ai_insights(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'neural_forms_entries';

		// Top non-generic categories - drives the top_category field and
		// the categories array for any future breakdown UI.
		$categories = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ai_category AS category, COUNT(*) AS count
				 FROM %i
				 WHERE ai_status = 'complete'
				   AND ai_category IS NOT NULL
				   AND ai_category != ''
				   AND ai_category != 'general'
				 GROUP BY ai_category
				 ORDER BY count DESC
				 LIMIT 4",
				$table
			),
			ARRAY_A
		);

		// Single conditional-aggregation pass over completed entries.
		$agg = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
				   COUNT(*)                          AS analyzed_count,
				   SUM( ai_priority = 'high' )       AS high_priority_count,
				   SUM( ai_category = 'sales' )      AS sales_count,
				   SUM( ai_category = 'support' )    AS support_count,
				   SUM( ai_is_spam = 1 )             AS spam_detected_count
				 FROM %i
				 WHERE ai_status = 'complete'",
				$table
			),
			ARRAY_A
		);

		return array(
			'top_category'        => ! empty( $categories ) ? $categories[0]['category'] : null,
			'categories'          => array_map(
				static function ( array $r ): array {
					return array(
						'category' => $r['category'],
						'count'    => (int) $r['count'],
					);
				},
				$categories ?: array()
			),
			'analyzed_count'      => $agg ? (int) $agg['analyzed_count'] : 0,
			'high_priority_count' => $agg ? (int) $agg['high_priority_count'] : 0,
			'sales_count'         => $agg ? (int) $agg['sales_count'] : 0,
			'support_count'       => $agg ? (int) $agg['support_count'] : 0,
			'spam_detected_count' => $agg ? (int) $agg['spam_detected_count'] : 0,
		);
	}

	/**
	 * Last 10 actionable log events - reply, notification, and AI outcomes.
	 * Generic/noisy events (queued, status updates) are excluded.
	 */
	private function recent_activity(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, entry_id, event_type, message, status, created_at
				 FROM %i
				 WHERE event_type IN (
				     'reply_sent',           'reply_send_failed',
				     'ai_auto_spam',         'ai_processing_error',
				     'admin_notification_sent',  'admin_notification_failed',
				     'autoresponder_sent',       'autoresponder_failed'
				 )
				 ORDER BY created_at DESC
				 LIMIT 10",
				$wpdb->prefix . 'neural_forms_logs'
			),
			ARRAY_A
		);

		return array_map(
			function ( array $row ): array {
				return array(
					'id'         => (int) $row['id'],
					'entry_id'   => $row['entry_id'] ? (int) $row['entry_id'] : null,
					'event_type' => $row['event_type'],
					'message'    => (string) $row['message'],
					'status'     => $row['status'],
					'created_at' => $row['created_at'],
				);
			},
			$rows ?: array()
		);
	}

	/**
	 * AI on/off, pending queue depth, email failure count in last 24 hours.
	 */
	private function system_status(): array {
		global $wpdb;

		$pending_jobs = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i
				 WHERE job_type = 'analyze_entry'
				   AND status IN ('pending','failed')
				   AND attempts < %d",
				$wpdb->prefix . 'neural_forms_queue',
				\NeuralForms\AI\AIQueueProcessor::MAX_ATTEMPTS
			)
		);

		$email_failures = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i
				 WHERE event_type IN (
				     'reply_send_failed',
				     'admin_notification_failed',
				     'autoresponder_failed'
				 )
				 AND created_at >= DATE_SUB( UTC_TIMESTAMP(), INTERVAL 24 HOUR )",
				$wpdb->prefix . 'neural_forms_logs'
			)
		);

		return array(
			'ai_enabled'      => (bool) Config::get( 'ai_enabled' ),
			'ai_spam_enabled' => (bool) Config::get( 'ai_spam_enabled' ),
			'pending_jobs'    => $pending_jobs,
			'email_failures'  => $email_failures,
		);
	}

	// Page Performance

	/**
	 * GET /forms/{id}/analytics/page-performance
	 *
	 * Per-page aggregation of entries for a single form. Groups by a
	 * normalized origin+path slice of `page_url` (the column already
	 * recorded from HTTP_REFERER on every submission), so existing
	 * entries surface without a schema change.
	 *
	 * Pro-only: Lite callers receive a feature_locked error so the
	 * Analytics bundle can render an upsell card without a 403 toast.
	 *
	 * Returns: {
	 *   form_id, form_name, total_entries, untracked_entries,
	 *   pages: [ {
	 *     page_path, page_url, page_origin,
	 *     entries, spam, not_spam,
	 *     high_priority, medium_priority, low_priority, unknown_priority,
	 *     unread, needs_reply,
	 *     conversion_share, spam_rate, high_priority_rate,
	 *     last_entry_at
	 *   } ]
	 * }
	 *
	 * Sort: entries desc, then high_priority desc, then last_entry_at desc.
	 */
	public function get_page_performance( WP_REST_Request $request ) {
		// Pro capability gate. Distinct error so the Analytics bundle
		// renders an upsell rather than a generic permission denial.
		if ( ! \NeuralForms\Core\Capabilities::can( \NeuralForms\Core\Capabilities::PAGE_PERFORMANCE_ANALYTICS ) ) {
			return new \WP_Error(
				'pro_feature_locked',
				__( 'Page Performance Analytics is available in Neural Forms Pro.', 'neural-forms-lite' ),
				array(
					'status'  => 403,
					'feature' => \NeuralForms\Core\Capabilities::PAGE_PERFORMANCE_ANALYTICS,
				)
			);
		}

		$form_id = (int) $request['id'];
		if ( $form_id < 1 ) {
			return new \WP_Error( 'invalid_form_id', __( 'Invalid form id.', 'neural-forms-lite' ), array( 'status' => 400 ) );
		}

		global $wpdb;
		$entries = $wpdb->prefix . 'neural_forms_entries';
		$forms   = $wpdb->prefix . 'neural_forms_forms';

		// Form name lookup (cheap, used by the response envelope).
		$form_name = (string) $wpdb->get_var(
			$wpdb->prepare( 'SELECT name FROM %i WHERE id = %d', $forms, $form_id )
		);
		if ( $form_name === '' ) {
			return new \WP_Error( 'form_not_found', __( 'Form not found.', 'neural-forms-lite' ), array( 'status' => 404 ) );
		}

		// Pull every entry's page_url + status + ai_priority. We do
		// the bucketing in PHP rather than SQL because we need
		// origin+path normalization (strip query strings, lowercase
		// host) — easier to express deterministically in PHP than
		// SUBSTRING_INDEX gymnastics. Per-form scope keeps result
		// sizes bounded by typical form volumes (≤ tens of thousands
		// of rows per form even for high-traffic sites).
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, page_url, status, ai_priority, ai_is_spam, ai_auto_spam_applied,
				        is_starred, needs_reply, submitted_at, created_at
				 FROM %i WHERE form_id = %d',
				$entries,
				$form_id
			),
			ARRAY_A
		) ?: array();

		$buckets   = array(); // page_key => bucket
		$total     = 0;
		$untracked = 0;

		foreach ( $rows as $r ) {
			++$total;
			$norm = self::normalize_page_url( (string) ( $r['page_url'] ?? '' ) );
			if ( $norm['key'] === '' ) {
				++$untracked;
				$key  = '__unknown__';
				$norm = array(
					'key'    => $key,
					'origin' => '',
					'path'   => '',
					'url'    => '',
				);
			} else {
				$key = $norm['key'];
			}

			if ( ! isset( $buckets[ $key ] ) ) {
				$buckets[ $key ] = array(
					'page_key'         => $key,
					'page_origin'      => $norm['origin'],
					'page_path'        => $norm['path'],
					'page_url'         => $norm['url'],
					'entries'          => 0,
					'spam'             => 0,
					'not_spam'         => 0,
					'high_priority'    => 0,
					'medium_priority'  => 0,
					'low_priority'     => 0,
					'unknown_priority' => 0,
					'unread'           => 0,
					'needs_reply'      => 0,
					'last_entry_at'    => null,
				);
			}
			$b = &$buckets[ $key ];
			++$b['entries'];

			$status = (string) ( $r['status'] ?? '' );
			if ( $status === 'spam' ) {
				++$b['spam'];
			} else {
				++$b['not_spam'];
			}

			$priority = strtolower( (string) ( $r['ai_priority'] ?? '' ) );
			if ( $priority === 'high' ) {
				++$b['high_priority'];
			} elseif ( $priority === 'medium' ) {
				++$b['medium_priority'];
			} elseif ( $priority === 'low' ) {
				++$b['low_priority'];
			} else {
				++$b['unknown_priority'];
			}

			if ( $status === 'new' ) {
				++$b['unread'];
			}
			if ( (int) ( $r['needs_reply'] ?? 0 ) === 1 ) {
				++$b['needs_reply'];
			}

			$ts = (string) ( $r['submitted_at'] ?? $r['created_at'] ?? '' );
			if ( $ts !== '' && ( null === $b['last_entry_at'] || $ts > $b['last_entry_at'] ) ) {
				$b['last_entry_at'] = $ts;
			}
			unset( $b );
		}

		// Compute rates + share, then sort.
		$pages = array_values(
			array_map(
				static function ( array $b ) use ( $total ): array {
					$entries                 = (int) $b['entries'];
					$b['conversion_share']   = $total > 0 ? round( ( $entries / $total ) * 100, 2 ) : 0.0;
					$b['spam_rate']          = $entries > 0 ? round( ( (int) $b['spam'] / $entries ) * 100, 2 ) : 0.0;
					$b['high_priority_rate'] = $entries > 0 ? round( ( (int) $b['high_priority'] / $entries ) * 100, 2 ) : 0.0;
					return $b;
				},
				$buckets
			)
		);

		usort(
			$pages,
			static function ( $a, $b ) {
				if ( $a['entries'] !== $b['entries'] ) {
					return $b['entries'] <=> $a['entries'];
				}
				if ( $a['high_priority'] !== $b['high_priority'] ) {
					return $b['high_priority'] <=> $a['high_priority'];
				}
				return strcmp( (string) ( $b['last_entry_at'] ?? '' ), (string) ( $a['last_entry_at'] ?? '' ) );
			}
		);

		return rest_ensure_response(
			array(
				'form_id'           => $form_id,
				'form_name'         => $form_name,
				'total_entries'     => $total,
				'untracked_entries' => $untracked,
				'pages'             => $pages,
			// Future hook: AI insight will consume `pages` aggregate
			// (NOT raw entries) to summarize "page X is your best
			// converter, page Y has high spam rate". See brief Part F.
			)
		);
	}

	/**
	 * Normalize a stored page_url into { key, origin, path, url }.
	 *
	 *   key    = canonical group identifier (origin + path, lowercased)
	 *   origin = scheme://host[:port]
	 *   path   = the page's path (defaults to '/')
	 *   url    = clean origin + path (no query / no fragment)
	 *
	 * Query strings are stripped because the brief calls them out
	 * as a privacy hazard (utm_*, email=, name=, token=). The
	 * untracked / empty case returns key=''. parse_url is safe with
	 * untrusted input — we re-build the URL ourselves rather than
	 * trust whatever was in the column.
	 */
	private static function normalize_page_url( string $raw ): array {
		$raw = trim( $raw );
		if ( $raw === '' ) {
			return array(
				'key'    => '',
				'origin' => '',
				'path'   => '',
				'url'    => '',
			);
		}
		$parts = wp_parse_url( $raw );
		if ( ! is_array( $parts ) ) {
			return array(
				'key'    => '',
				'origin' => '',
				'path'   => '',
				'url'    => '',
			);
		}

		$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '';
		$host   = isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';
		$port   = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		$path   = isset( $parts['path'] ) ? (string) $parts['path'] : '/';
		if ( $path === '' ) {
			$path = '/';
		}

		// Without a host the URL is unusable for analytics grouping.
		if ( $host === '' ) {
			return array(
				'key'    => '',
				'origin' => '',
				'path'   => '',
				'url'    => '',
			);
		}

		$origin = $scheme !== '' ? "{$scheme}://{$host}{$port}" : "//{$host}{$port}";
		$url    = $origin . $path;
		return array(
			'key'    => strtolower( $origin . $path ),
			'origin' => $origin,
			'path'   => $path,
			'url'    => $url,
		);
	}
}
