<?php
namespace NeuralForms\Rest\Endpoints;

use NeuralForms\Forms\FormRepository;
use NeuralForms\Forms\SchemaHelper;
use NeuralForms\Storage\EntryRepository;
use WP_REST_Request;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * GET /forms/{id}/polls/{field_id}
 *
 * Admin-only read of aggregated poll-vote counts for a single poll
 * field on a single saved form. Phase 2 of the poll feature: powers
 * the read-only "Poll Results" panel inside the builder inspector.
 *
 * Response shape:
 *
 *   {
 *     "form_id":     12,
 *     "field_id":    "poll_favorite_service",
 *     "label":       "Which service…?",
 *     "total_votes": 42,
 *     "options": [
 *       { "label": "Website Design", "value": "website_design", "votes": 20, "percentage": 47.62 },
 *       …
 *       // Synthetic Other bucket only when count > 0:
 *       { "label": "Other",          "value": "__other__",      "votes": 3,  "percentage": 7.14 }
 *     ]
 *   }
 *
 * Aggregation:
 *   - Vote counts come from EntryRepository::aggregate_poll_votes(),
 *     which excludes status='trash' rows and skips malformed JSON
 *     entries without fataling.
 *   - The configured options[] from the field schema are echoed back
 *     in schema order with their counts/percentages, so the UI can
 *     render bars even for options that have zero votes.
 *   - Values present in entries but missing from the current schema
 *     (e.g. an option was removed after votes were cast) collapse
 *     into a synthetic "__other__" bucket. The synthetic option is
 *     OMITTED when its count is zero.
 *   - Percentages are 2dp; total === 0 yields all zeros (no /0).
 */
class PollsEndpoint {

	public function register_routes(): void {
		$ns = \NeuralForms\Rest\RestAPI::NAMESPACE;

		register_rest_route( $ns, '/forms/(?P<id>\d+)/polls/(?P<field_id>[a-z0-9_]+)', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_poll_results' ],
			'permission_callback' => [ $this, 'check_permission' ],
			'args' => [
				'id' => [
					'validate_callback' => static function ( $v ) { return is_numeric( $v ) && (int) $v > 0; },
					'sanitize_callback' => 'absint',
				],
				'field_id' => [
					'validate_callback' => static function ( $v ) { return is_string( $v ) && (bool) preg_match( '/^[a-z0-9_]+$/', $v ); },
				],
			],
		] );

		// All-polls aggregator. Powers the Results screen.
		register_rest_route( $ns, '/forms/(?P<id>\d+)/polls', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_all_poll_results' ],
			'permission_callback' => [ $this, 'check_permission' ],
			'args' => [
				'id' => [
					'validate_callback' => static function ( $v ) { return is_numeric( $v ) && (int) $v > 0; },
					'sanitize_callback' => 'absint',
				],
			],
		] );
	}

	public function check_permission(): bool {
		// Admin-only: same gate the rest of the form-management surface
		// uses. Public consumption (Phase 3) goes through the submit
		// response, not this endpoint.
		return current_user_can( NEURAL_FORMS_CAP_MANAGE_FORMS );
	}

	public function get_poll_results( WP_REST_Request $request ) {
		$form_id  = (int) $request['id'];
		$field_id = (string) $request['field_id'];

		$form = FormRepository::find( $form_id );
		if ( ! $form ) {
			return new WP_Error(
				'form_not_found',
				__( 'Form not found.', 'neural-forms-lite' ),
				[ 'status' => 404 ]
			);
		}

		$schema = is_array( $form['form_schema'] ?? null ) ? $form['form_schema'] : [];
		$fields = is_array( $schema['fields'] ?? null )    ? $schema['fields']    : [];

		$poll_field = null;
		foreach ( SchemaHelper::flatten_fields( $fields ) as $f ) {
			if ( ( $f['type'] ?? '' ) === 'poll' && (string) ( $f['id'] ?? '' ) === $field_id ) {
				$poll_field = $f;
				break;
			}
		}

		if ( ! $poll_field ) {
			return new WP_Error(
				'poll_not_found',
				__( 'Poll field not found on this form.', 'neural-forms-lite' ),
				[ 'status' => 404 ]
			);
		}

		$result = self::compute_poll_result( $form_id, $poll_field );
		$result['form_id'] = $form_id;

		return rest_ensure_response( $result );
	}

	/**
	 * GET /forms/{id}/polls
	 *
	 * Aggregates every poll field on the form into one response. Powers
	 * the Results screen. Response shape:
	 *
	 *   {
	 *     form_id, form_name,
	 *     total_entries:    int,
	 *     poll_count:       int,
	 *     total_poll_votes: int,
	 *     polls: [
	 *       { field_id, label, total_votes, options: [...] },
	 *       ...
	 *     ]
	 *   }
	 *
	 * - Empty `polls` is returned (200) when the form has no poll
	 *   fields. The response shape is identical, so the UI renders the
	 *   "no poll fields" empty state without a 404 round-trip.
	 * - total_entries excludes status='trash' entries (matches
	 *   aggregate_poll_votes()).
	 */
	public function get_all_poll_results( WP_REST_Request $request ) {
		$form_id = (int) $request['id'];

		$form = FormRepository::find( $form_id );
		if ( ! $form ) {
			return new WP_Error(
				'form_not_found',
				__( 'Form not found.', 'neural-forms-lite' ),
				[ 'status' => 404 ]
			);
		}

		$schema = is_array( $form['form_schema'] ?? null ) ? $form['form_schema'] : [];
		$fields = is_array( $schema['fields'] ?? null )    ? $schema['fields']    : [];

		$polls = [];
		$total_poll_votes = 0;
		foreach ( SchemaHelper::flatten_fields( $fields ) as $f ) {
			if ( ( $f['type'] ?? '' ) !== 'poll' ) continue;
			$result = self::compute_poll_result( $form_id, $f );
			$total_poll_votes += (int) $result['total_votes'];
			$polls[] = $result;
		}

		$total_entries = self::count_form_entries( $form_id );

		return rest_ensure_response( [
			'form_id'          => $form_id,
			'form_name'        => (string) ( $form['name'] ?? '' ),
			'total_entries'    => $total_entries,
			'poll_count'       => count( $polls ),
			'total_poll_votes' => $total_poll_votes,
			'polls'            => $polls,
		] );
	}

	/**
	 * Compute the response shape for a single poll field.
	 * Shared by the per-field and all-polls callbacks.
	 */
	private static function compute_poll_result( int $form_id, array $poll_field ): array {
		$field_id = (string) ( $poll_field['id'] ?? '' );

		$agg = EntryRepository::aggregate_poll_votes( $form_id, $field_id );
		$total  = (int) ( $agg['total']  ?? 0 );
		$counts = is_array( $agg['counts'] ?? null ) ? $agg['counts'] : [];

		$options_in   = is_array( $poll_field['options'] ?? null ) ? $poll_field['options'] : [];
		$known_values = [];
		$response_options = [];

		foreach ( $options_in as $opt ) {
			$opt   = (array) $opt;
			$label = (string) ( $opt['label'] ?? '' );
			$raw   = (string) ( $opt['value'] ?? '' );
			$value = $raw !== '' ? $raw : $label;
			if ( $value === '' ) continue;

			$known_values[] = $value;

			$votes = (int) ( $counts[ $value ] ?? 0 );
			$pct   = $total > 0 ? round( ( $votes / $total ) * 100, 2 ) : 0.0;

			$response_options[] = [
				'label'      => $label !== '' ? $label : $value,
				'value'      => $value,
				'votes'      => $votes,
				'percentage' => $pct,
			];
		}

		$other_votes = 0;
		foreach ( $counts as $value => $votes ) {
			if ( ! in_array( (string) $value, $known_values, true ) ) {
				$other_votes += (int) $votes;
			}
		}
		if ( $other_votes > 0 ) {
			$response_options[] = [
				'label'      => __( 'Other', 'neural-forms-lite' ),
				'value'      => '__other__',
				'votes'      => $other_votes,
				'percentage' => $total > 0 ? round( ( $other_votes / $total ) * 100, 2 ) : 0.0,
			];
		}

		return [
			'field_id'    => $field_id,
			'label'       => (string) ( $poll_field['label'] ?? '' ),
			'total_votes' => $total,
			'options'     => $response_options,
		];
	}

	/**
	 * Count of non-trashed entries for a form. Used by the Results
	 * overview cards. Direct $wpdb call avoids loading the heavier
	 * EntryRepository::count_by_view aggregate.
	 */
	private static function count_form_entries( int $form_id ): int {
		global $wpdb;
		if ( $form_id <= 0 ) return 0;
		// phpcs:ignore WordPress.DB
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE form_id = %d AND status != %s',
				$wpdb->prefix . 'neural_forms_entries',
				$form_id,
				'trash'
			)
		);
		return (int) $count;
	}
}
