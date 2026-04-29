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
 * GET /stats
 *
 * Single endpoint that returns all data for the admin Stats screen.
 * Five payload sections:
 *
 *   needs_attention  - up to 8 entries requiring action (high-priority,
 *                      needs-reply, or spam-suspected-but-not-yet-moved).
 *   stats            - aggregate counts: entries last 7 days, unread,
 *                      needs-reply, spam detected.
 *   ai_insights      - top category, high-priority count, sales/support
 *                      count from completed AI analyses.
 *   recent_activity  - last 10 log events (reply, notification, AI).
 *   system_status    - AI on/off, pending queue depth, email failure count.
 *
 * All queries are simple aggregations or small-LIMIT fetches - no heavy
 * joins or full-table scans beyond what the existing indexes cover.
 */
class StatsEndpoint {

	public function register_routes(): void {
		$ns = \NeuralForms\Rest\RestAPI::NAMESPACE;

		register_rest_route( $ns, '/stats', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_stats' ],
			'permission_callback' => [ $this, 'check_permission' ],
		] );
	}

	public function check_permission(): bool {
		return current_user_can( NEURAL_FORMS_CAP_MANAGE_FORMS );
	}

	public function get_stats( WP_REST_Request $request ) {
		return rest_ensure_response( [
			'needs_attention' => $this->needs_attention(),
			'stats'           => $this->stats(),
			'ai_insights'     => $this->ai_insights(),
			'recent_activity' => $this->recent_activity(),
			'system_status'   => $this->system_status(),
		] );
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

		return array_map( function ( array $row ): array {
			$data    = json_decode( $row['data'] ?? '{}', true ) ?: [];
			$preview = array_slice( $data, 0, 3, true );

			return [
				'id'                  => (int) $row['id'],
				'form_id'             => (int) $row['form_id'],
				'form_name'           => isset( $row['form_name'] ) ? (string) $row['form_name'] : null,
				'status'              => $row['status'],
				'is_starred'          => (int) $row['is_starred'],
				'needs_reply'         => (int) $row['needs_reply'],
				'ai_priority'         => $row['ai_priority'],
				'ai_category'         => $row['ai_category'],
				'ai_is_spam'          => (int) $row['ai_is_spam'],
				'ai_auto_spam_applied' => (int) $row['ai_auto_spam_applied'],
				'submitted_at'        => $row['submitted_at'],
				'created_at'          => $row['created_at'],
				'preview'             => $preview,
			];
		}, $rows ?: [] );
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
			return [ 'entries_7d' => 0, 'unread' => 0, 'needs_reply' => 0, 'spam_detected' => 0 ];
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

		return [
			'top_category'        => ! empty( $categories ) ? $categories[0]['category'] : null,
			'categories'          => array_map( static function ( array $r ): array {
				return [ 'category' => $r['category'], 'count' => (int) $r['count'] ];
			}, $categories ?: [] ),
			'analyzed_count'      => $agg ? (int) $agg['analyzed_count']      : 0,
			'high_priority_count' => $agg ? (int) $agg['high_priority_count'] : 0,
			'sales_count'         => $agg ? (int) $agg['sales_count']         : 0,
			'support_count'       => $agg ? (int) $agg['support_count']       : 0,
			'spam_detected_count' => $agg ? (int) $agg['spam_detected_count'] : 0,
		];
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

		return array_map( function ( array $row ): array {
			return [
				'id'         => (int) $row['id'],
				'entry_id'   => $row['entry_id'] ? (int) $row['entry_id'] : null,
				'event_type' => $row['event_type'],
				'message'    => (string) $row['message'],
				'status'     => $row['status'],
				'created_at' => $row['created_at'],
			];
		}, $rows ?: [] );
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

		return [
			'ai_enabled'      => (bool) Config::get( 'ai_enabled' ),
			'ai_spam_enabled' => (bool) Config::get( 'ai_spam_enabled' ),
			'pending_jobs'    => $pending_jobs,
			'email_failures'  => $email_failures,
		];
	}
}
