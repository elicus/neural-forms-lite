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

		register_rest_route(
			$ns,
			'/forms/(?P<id>\d+)/polls/(?P<field_id>[a-z0-9_]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_poll_results' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'id'       => array(
						'validate_callback' => static function ( $v ) {
							return is_numeric( $v ) && (int) $v > 0; },
						'sanitize_callback' => 'absint',
					),
					'field_id' => array(
						'validate_callback' => static function ( $v ) {
							return is_string( $v ) && (bool) preg_match( '/^[a-z0-9_]+$/', $v ); },
					),
				),
			)
		);

		// All-polls aggregator. Powers the Results screen.
		register_rest_route(
			$ns,
			'/forms/(?P<id>\d+)/polls',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_all_poll_results' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'id' => array(
						'validate_callback' => static function ( $v ) {
							return is_numeric( $v ) && (int) $v > 0; },
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// All-quiz aggregator. Powers the Results screen Quiz section.
		// Lives on this endpoint class because the data flow is
		// parallel to polls: same form lookup, same trash exclusion,
		// same admin-only gate. No registry split needed in v1.
		register_rest_route(
			$ns,
			'/forms/(?P<id>\d+)/quiz',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_all_quiz_results' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'id' => array(
						'validate_callback' => static function ( $v ) {
							return is_numeric( $v ) && (int) $v > 0; },
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
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
				array( 'status' => 404 )
			);
		}

		$schema = is_array( $form['form_schema'] ?? null ) ? $form['form_schema'] : array();
		$fields = is_array( $schema['fields'] ?? null ) ? $schema['fields'] : array();

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
				array( 'status' => 404 )
			);
		}

		$result            = self::compute_poll_result( $form_id, $poll_field );
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
				array( 'status' => 404 )
			);
		}

		$schema = is_array( $form['form_schema'] ?? null ) ? $form['form_schema'] : array();
		$fields = is_array( $schema['fields'] ?? null ) ? $schema['fields'] : array();

		$polls            = array();
		$total_poll_votes = 0;
		foreach ( SchemaHelper::flatten_fields( $fields ) as $f ) {
			if ( ( $f['type'] ?? '' ) !== 'poll' ) {
				continue;
			}
			$result            = self::compute_poll_result( $form_id, $f );
			$total_poll_votes += (int) $result['total_votes'];
			$polls[]           = $result;
		}

		$total_entries = self::count_form_entries( $form_id );

		return rest_ensure_response(
			array(
				'form_id'          => $form_id,
				'form_name'        => (string) ( $form['name'] ?? '' ),
				'total_entries'    => $total_entries,
				'poll_count'       => count( $polls ),
				'total_poll_votes' => $total_poll_votes,
				'polls'            => $polls,
			)
		);
	}

	/**
	 * GET /forms/{id}/quiz
	 *
	 * Aggregates every quiz_question on the form into one analytics
	 * payload. Powers the Quiz Results section of the Results screen.
	 *
	 * Response shape (verified by QuizResultsEndpointTest):
	 *
	 *   {
	 *     form_id, form_name,
	 *     total_entries:      int,
	 *     quiz_count:         int,
	 *     total_attempts:     int,    // non-trash entries with >=1 quiz answer
	 *     max_score:          int,
	 *     average_score:      float,  // mean across non-trash entries
	 *     average_percentage: float,  // 2dp; 0 when max_score === 0
	 *     questions: [
	 *       {
	 *         field_id, label, points_possible,
	 *         total_answers, correct_answers, incorrect_answers,
	 *         correct_percentage,
	 *         options: [
	 *           { label, value, is_correct, answers, percentage },
	 *           ...
	 *           // synthetic Other only when count > 0:
	 *           { label: "Other", value: "__other__", is_correct: false, answers, percentage }
	 *         ]
	 *       },
	 *       ...
	 *     ]
	 *   }
	 *
	 * Empty `questions` is returned (200) when the form has no quiz
	 * fields, mirroring the all-polls contract.
	 */
	public function get_all_quiz_results( WP_REST_Request $request ) {
		$form_id = (int) $request['id'];

		$form = FormRepository::find( $form_id );
		if ( ! $form ) {
			return new WP_Error(
				'form_not_found',
				__( 'Form not found.', 'neural-forms-lite' ),
				array( 'status' => 404 )
			);
		}

		$schema = is_array( $form['form_schema'] ?? null ) ? $form['form_schema'] : array();
		$fields = is_array( $schema['fields'] ?? null ) ? $schema['fields'] : array();

		// Collect quiz fields in schema order.
		$quiz_fields = array();
		foreach ( SchemaHelper::flatten_fields( $fields ) as $f ) {
			if ( ( $f['type'] ?? '' ) === 'quiz_question' ) {
				$quiz_fields[] = $f;
			}
		}

		$total_entries = self::count_form_entries( $form_id );

		if ( empty( $quiz_fields ) ) {
			return rest_ensure_response(
				array(
					'form_id'            => $form_id,
					'form_name'          => (string) ( $form['name'] ?? '' ),
					'total_entries'      => $total_entries,
					'quiz_count'         => 0,
					'total_attempts'     => 0,
					'max_score'          => 0,
					'average_score'      => 0.0,
					'average_percentage' => 0.0,
					'questions'          => array(),
				)
			);
		}

		$field_ids = array_map(
			static function ( $f ) {
				return (string) ( $f['id'] ?? '' );
			},
			$quiz_fields
		);
		$field_ids = array_filter(
			$field_ids,
			static function ( $id ) {
				return $id !== '';
			}
		);

		$agg = \NeuralForms\Storage\EntryRepository::aggregate_quiz_answers( $form_id, array_values( $field_ids ) );

		// Build per-question payload.
		$max_score = 0;
		$questions = array();

		foreach ( $quiz_fields as $field ) {
			$field_id = (string) ( $field['id'] ?? '' );
			if ( $field_id === '' ) {
				continue;
			}

			$settings   = SchemaHelper::normalize_quiz_settings( $field['settings'] ?? null );
			$points     = (int) $settings['points'];
			$max_score += $points;

			$schema_options = is_array( $field['options'] ?? null ) ? $field['options'] : array();
			$counts         = is_array( $agg['counts'][ $field_id ] ?? null ) ? $agg['counts'][ $field_id ] : array();
			$total_answers  = (int) ( $agg['totals'][ $field_id ] ?? 0 );

			$correct_value = '';
			$known_values  = array();
			$response_opts = array();

			foreach ( $schema_options as $opt ) {
				$opt   = (array) $opt;
				$label = (string) ( $opt['label'] ?? '' );
				$raw   = (string) ( $opt['value'] ?? '' );
				$value = $raw !== '' ? $raw : $label;
				if ( $value === '' ) {
					continue;
				}

				$known_values[] = $value;
				$is_correct     = ! empty( $opt['is_correct'] );
				if ( $is_correct ) {
					$correct_value = $value;
				}

				$answers = (int) ( $counts[ $value ] ?? 0 );
				$pct     = $total_answers > 0 ? round( ( $answers / $total_answers ) * 100, 2 ) : 0.0;

				$response_opts[] = array(
					'label'      => $label !== '' ? $label : $value,
					'value'      => $value,
					'is_correct' => $is_correct,
					'answers'    => $answers,
					'percentage' => $pct,
				);
			}

			// Other bucket for unknown vote values.
			$other = 0;
			foreach ( $counts as $value => $n ) {
				if ( ! in_array( (string) $value, $known_values, true ) ) {
					$other += (int) $n;
				}
			}
			if ( $other > 0 ) {
				$response_opts[] = array(
					'label'      => __( 'Other', 'neural-forms-lite' ),
					'value'      => '__other__',
					'is_correct' => false,
					'answers'    => $other,
					'percentage' => $total_answers > 0 ? round( ( $other / $total_answers ) * 100, 2 ) : 0.0,
				);
			}

			$correct_answers   = (int) ( $correct_value !== '' && isset( $counts[ $correct_value ] ) ? $counts[ $correct_value ] : 0 );
			$incorrect_answers = max( 0, $total_answers - $correct_answers );
			$correct_pct       = $total_answers > 0 ? round( ( $correct_answers / $total_answers ) * 100, 2 ) : 0.0;

			$questions[] = array(
				'field_id'           => $field_id,
				'label'              => (string) ( $field['label'] ?? '' ),
				'points_possible'    => $points,
				'total_answers'      => $total_answers,
				'correct_answers'    => $correct_answers,
				'incorrect_answers'  => $incorrect_answers,
				'correct_percentage' => $correct_pct,
				'options'            => $response_opts,
			);
		}

		// Compute per-entry score, mean across non-trash entries that
		// contained at least one quiz answer. An entry that reached
		// the form but skipped every quiz question is NOT an attempt;
		// excluding it keeps the average meaningful (otherwise an
		// unrelated submit would dilute the score for everyone).
		$attempts  = 0;
		$score_sum = 0;
		foreach ( ( $agg['rows'] ?? array() ) as $row ) {
			$has_answer  = false;
			$entry_score = 0;
			foreach ( $quiz_fields as $field ) {
				$fid = (string) ( $field['id'] ?? '' );
				if ( $fid === '' ) {
					continue;
				}
				if ( ! array_key_exists( $fid, $row ) ) {
					continue;
				}
				$val = $row[ $fid ];
				if ( null === $val || $val === '' || is_array( $val ) ) {
					continue;
				}
				$has_answer = true;

				// Match against this question's correct option.
				$question_options = is_array( $field['options'] ?? null ) ? $field['options'] : array();
				$pts              = (int) SchemaHelper::normalize_quiz_settings( $field['settings'] ?? null )['points'];
				foreach ( $question_options as $opt ) {
					$opt = (array) $opt;
					if ( empty( $opt['is_correct'] ) ) {
						continue;
					}
					$opt_label = (string) ( $opt['label'] ?? '' );
					$opt_raw   = (string) ( $opt['value'] ?? '' );
					$correct   = $opt_raw !== '' ? $opt_raw : $opt_label;
					if ( (string) $val === $correct ) {
						$entry_score += $pts;
					}
					break;
				}
			}
			if ( $has_answer ) {
				++$attempts;
				$score_sum += $entry_score;
			}
		}

		$average_score      = $attempts > 0 ? round( $score_sum / $attempts, 2 ) : 0.0;
		$average_percentage = ( $max_score > 0 && $attempts > 0 )
			? round( ( $score_sum / ( $max_score * $attempts ) ) * 100, 2 )
			: 0.0;

		// ── Individual Attempts (latest 50) ────────────────────────
		// Identity is best-effort: prefer the first email field's
		// value, fall back to a name-like text field, fall back to
		// "Entry #ID". Heuristic computed once per request.
		$identity_field_id = self::guess_identity_field( $fields );
		$attempts_limit    = 50;
		$entries           = \NeuralForms\Storage\EntryRepository::list_form_entries_with_data( $form_id, $attempts_limit );

		// Form-level quiz settings for pass/fail detection.
		$schema_settings    = is_array( $schema['settings'] ?? null ) ? $schema['settings'] : array();
		$quiz_form_settings = SchemaHelper::normalize_quiz_form_settings( $schema_settings['quiz'] ?? null );
		$show_pass_fail     = ! empty( $quiz_form_settings['show_pass_fail'] );
		$passing_score      = (int) $quiz_form_settings['passing_score'];

		$attempts_payload = array();
		$pass_count       = 0;
		$counted_for_pass = 0;
		$highest_score    = null;
		$lowest_score     = null;

		foreach ( $entries as $entry ) {
			$row             = $entry['data'];
			$attempt_score   = 0;
			$attempt_correct = 0;
			$attempt_wrong   = 0;
			$has_quiz_answer = false;
			$answers_payload = array();

			foreach ( $quiz_fields as $field ) {
				$fid = (string) ( $field['id'] ?? '' );
				if ( $fid === '' ) {
					continue;
				}

				$qsettings   = SchemaHelper::normalize_quiz_settings( $field['settings'] ?? null );
				$default_pts = (int) $qsettings['points'];
				$options     = is_array( $field['options'] ?? null ) ? $field['options'] : array();

				// Detect weighted scoring (any option.points key).
				$has_weighted = false;
				$option_max   = 0;
				foreach ( $options as $opt ) {
					$opt = (array) $opt;
					if ( array_key_exists( 'points', $opt ) && is_numeric( $opt['points'] ) ) {
						$pts = (int) round( (float) $opt['points'] );
						if ( $pts < 0 ) {
							$pts = 0;
						}
						$has_weighted = true;
						if ( $pts > $option_max ) {
							$option_max = $pts;
						}
					}
				}
				$points_possible = $has_weighted ? $option_max : $default_pts;

				// Correct option lookup.
				$correct_value = '';
				$correct_label = '';
				foreach ( $options as $opt ) {
					$opt = (array) $opt;
					if ( empty( $opt['is_correct'] ) ) {
						continue;
					}
					$opt_label     = (string) ( $opt['label'] ?? '' );
					$opt_raw       = (string) ( $opt['value'] ?? '' );
					$correct_value = $opt_raw !== '' ? $opt_raw : $opt_label;
					$correct_label = $opt_label !== '' ? $opt_label : $correct_value;
					break;
				}

				// Selected value + label.
				$raw_sel        = $row[ $fid ] ?? null;
				$selected_value = ( null === $raw_sel || $raw_sel === '' || is_array( $raw_sel ) ) ? null : (string) $raw_sel;
				$selected_label = null;
				$selected_pts   = null;
				if ( null !== $selected_value ) {
					$has_quiz_answer = true;
					foreach ( $options as $opt ) {
						$opt       = (array) $opt;
						$opt_label = (string) ( $opt['label'] ?? '' );
						$opt_raw   = (string) ( $opt['value'] ?? '' );
						$effective = $opt_raw !== '' ? $opt_raw : $opt_label;
						if ( $effective === $selected_value ) {
							$selected_label = $opt_label !== '' ? $opt_label : $effective;
							if ( array_key_exists( 'points', $opt ) && is_numeric( $opt['points'] ) ) {
								$pts          = (int) round( (float) $opt['points'] );
								$selected_pts = $pts < 0 ? 0 : $pts;
							}
							break;
						}
					}
				}

				$is_correct     = ( null !== $selected_value && $correct_value !== '' && $selected_value === $correct_value );
				$points_awarded = $has_weighted
					? ( null !== $selected_pts ? $selected_pts : 0 )
					: ( $is_correct ? $default_pts : 0 );
				$attempt_score += $points_awarded;
				if ( null !== $selected_value ) {
					if ( $is_correct ) {
						++$attempt_correct;
					} else {
						++$attempt_wrong;
					}
				}

				$answers_payload[] = array(
					'field_id'        => $fid,
					'label'           => (string) ( $field['label'] ?? '' ),
					'selected_value'  => $selected_value,
					'selected_label'  => $selected_label,
					'correct'         => $is_correct,
					'correct_label'   => $correct_label,
					'points_awarded'  => $points_awarded,
					'points_possible' => $points_possible,
				);
			}

			if ( ! $has_quiz_answer ) {
				continue; // Skip non-attempts.
			}

			$attempt_pct    = $max_score > 0 ? round( ( $attempt_score / $max_score ) * 100, 2 ) : 0.0;
			$attempt_passed = $show_pass_fail ? ( $attempt_pct >= (float) $passing_score ) : null;
			if ( $show_pass_fail ) {
				++$counted_for_pass;
				if ( $attempt_passed ) {
					++$pass_count;
				}
			}
			if ( null === $highest_score || $attempt_score > $highest_score ) {
				$highest_score = $attempt_score;
			}
			if ( null === $lowest_score || $attempt_score < $lowest_score ) {
				$lowest_score = $attempt_score;
			}

			// Identity: email > name > Entry #ID.
			$identity = '';
			if ( $identity_field_id !== '' && isset( $row[ $identity_field_id ] ) ) {
				$candidate = $row[ $identity_field_id ];
				if ( is_string( $candidate ) && trim( $candidate ) !== '' ) {
					$identity = trim( $candidate );
				}
			}
			if ( $identity === '' ) {
				$identity = sprintf( /* translators: %d: entry id */ __( 'Entry #%d', 'neural-forms-lite' ), $entry['id'] );
			}

			$attempt_entry = array(
				'entry_id'        => $entry['id'],
				'submitted_at'    => $entry['submitted_at'],
				'identity'        => $identity,
				'score'           => $attempt_score,
				'max_score'       => $max_score,
				'percentage'      => $attempt_pct,
				'correct_count'   => $attempt_correct,
				'incorrect_count' => $attempt_wrong,
				'answers'         => $answers_payload,
			);
			if ( $show_pass_fail ) {
				$attempt_entry['passed'] = $attempt_passed;
			}
			$attempts_payload[] = $attempt_entry;
		}

		$pass_rate = ( $show_pass_fail && $counted_for_pass > 0 )
			? round( ( $pass_count / $counted_for_pass ) * 100, 2 )
			: null;

		// ── Insights (rule-based, no AI) ──────────────────────────
		$insights = self::build_quiz_insights( $questions, $attempts, $average_percentage, $show_pass_fail, $pass_rate, $passing_score );

		$response = array(
			'form_id'            => $form_id,
			'form_name'          => (string) ( $form['name'] ?? '' ),
			'total_entries'      => $total_entries,
			'quiz_count'         => count( $quiz_fields ),
			'total_attempts'     => $attempts,
			'max_score'          => $max_score,
			'average_score'      => $average_score,
			'average_percentage' => $average_percentage,
			'highest_score'      => null === $highest_score ? 0 : (int) $highest_score,
			'lowest_score'       => null === $lowest_score ? 0 : (int) $lowest_score,
			'questions'          => $questions,
			'attempts'           => $attempts_payload,
			'attempts_limit'     => $attempts_limit,
			'attempts_total'     => $attempts,
			'insights'           => $insights,
		);
		if ( $show_pass_fail ) {
			$response['pass_rate']     = $pass_rate;
			$response['passing_score'] = $passing_score;
		}
		return rest_ensure_response( $response );
	}

	/**
	 * Heuristic: pick a field id whose value best identifies the
	 * submitter for the Attempts table.
	 *   1. First field with type='email'.
	 *   2. First text/text-like field whose id or label contains "name".
	 *   3. Empty string → caller falls back to "Entry #ID".
	 */
	private static function guess_identity_field( array $fields ): string {
		// Pass 1: email type.
		foreach ( SchemaHelper::flatten_fields( $fields ) as $f ) {
			if ( ( $f['type'] ?? '' ) === 'email' && ! empty( $f['id'] ) ) {
				return (string) $f['id'];
			}
		}
		// Pass 2: name-ish text/text-like fields.
		foreach ( SchemaHelper::flatten_fields( $fields ) as $f ) {
			$type = $f['type'] ?? '';
			if ( ! in_array( $type, array( 'text', 'textarea' ), true ) ) {
				continue;
			}
			$id  = strtolower( (string) ( $f['id'] ?? '' ) );
			$lbl = strtolower( (string) ( $f['label'] ?? '' ) );
			if ( strpos( $id, 'name' ) !== false || strpos( $lbl, 'name' ) !== false ) {
				return (string) $f['id'];
			}
		}
		return '';
	}

	/**
	 * Rule-based insights for the quiz Results screen.
	 *
	 *   type: 'success' | 'warning' | 'info'
	 *
	 * Generated from the question breakdown + averages — no entry-level
	 * data needed, so this is cheap and stable.
	 */
	private static function build_quiz_insights(
		array $questions,
		int $attempts,
		float $average_percentage,
		bool $show_pass_fail,
		$pass_rate,
		int $passing_score
	): array {
		$out = array();

		if ( 0 === $attempts ) {
			$out[] = array(
				'type'    => 'info',
				'title'   => __( 'No responses yet', 'neural-forms-lite' ),
				'message' => __( 'Once people submit this quiz, insights will appear here.', 'neural-forms-lite' ),
			);
			return $out;
		}

		// Overall performance.
		if ( $average_percentage >= 80 ) {
			$out[] = array(
				'type'    => 'success',
				'title'   => __( 'Strong performance', 'neural-forms-lite' ),
				'message' => sprintf(
					/* translators: %s: percentage */
					__( 'Average score is %s%% — most users are answering correctly.', 'neural-forms-lite' ),
					(string) $average_percentage
				),
			);
		} elseif ( $average_percentage < 50 ) {
			$out[] = array(
				'type'    => 'warning',
				'title'   => __( 'Average score is low', 'neural-forms-lite' ),
				'message' => sprintf(
					/* translators: %s: percentage */
					__( 'Average score is %s%%. Consider reviewing the quiz wording or content.', 'neural-forms-lite' ),
					(string) $average_percentage
				),
			);
		}

		// Pass/fail signal.
		if ( $show_pass_fail && is_numeric( $pass_rate ) ) {
			if ( $pass_rate >= 80 ) {
				$out[] = array(
					'type'    => 'success',
					'title'   => __( 'High pass rate', 'neural-forms-lite' ),
					'message' => sprintf(
						/* translators: 1: pass rate, 2: passing score */
						__( '%1$s%% of attempts passed (≥ %2$d%%).', 'neural-forms-lite' ),
						(string) $pass_rate,
						$passing_score
					),
				);
			} elseif ( $pass_rate < 50 ) {
				$out[] = array(
					'type'    => 'warning',
					'title'   => __( 'Low pass rate', 'neural-forms-lite' ),
					'message' => sprintf(
						/* translators: 1: pass rate, 2: passing score */
						__( 'Only %1$s%% of attempts passed (≥ %2$d%%).', 'neural-forms-lite' ),
						(string) $pass_rate,
						$passing_score
					),
				);
			}
		}

		// Per-question insights.
		foreach ( $questions as $q ) {
			$total   = (int) ( $q['total_answers'] ?? 0 );
			$correct = (int) ( $q['correct_answers'] ?? 0 );
			$pct     = (float) ( $q['correct_percentage'] ?? 0 );
			if ( 0 === $total ) {
				continue;
			}

			$label = (string) ( $q['label'] ?? $q['field_id'] ?? '' );
			if ( $pct < 50 ) {
				$out[] = array(
					'type'    => 'warning',
					'title'   => __( 'Question needs review', 'neural-forms-lite' ),
					'message' => sprintf(
						/* translators: 1: question label, 2: percentage */
						__( '“%1$s” — only %2$s%% answered correctly.', 'neural-forms-lite' ),
						$label,
						(string) $pct
					),
				);
			}

			// Most-selected wrong answer (if any wrong dominates).
			$worst_label = '';
			$worst_count = 0;
			foreach ( ( $q['options'] ?? array() ) as $opt ) {
				if ( ! empty( $opt['is_correct'] ) ) {
					continue;
				}
				if ( ( $opt['value'] ?? '' ) === '__other__' ) {
					continue;
				}
				$cnt = (int) ( $opt['answers'] ?? 0 );
				if ( $cnt > $worst_count ) {
					$worst_count = $cnt;
					$worst_label = (string) ( $opt['label'] ?? '' );
				}
			}
			if ( $worst_count > 0 && $worst_count >= $correct && $worst_label !== '' ) {
				$out[] = array(
					'type'    => 'warning',
					'title'   => __( 'Most-picked wrong answer', 'neural-forms-lite' ),
					'message' => sprintf(
						/* translators: 1: question label, 2: option label */
						__( 'On “%1$s”, the most common wrong answer was “%2$s”.', 'neural-forms-lite' ),
						$label,
						$worst_label
					),
				);
			}
		}

		return $out;
	}

	/**
	 * Compute the response shape for a single poll field.
	 * Shared by the per-field and all-polls callbacks.
	 */
	private static function compute_poll_result( int $form_id, array $poll_field ): array {
		$field_id = (string) ( $poll_field['id'] ?? '' );

		$agg    = EntryRepository::aggregate_poll_votes( $form_id, $field_id );
		$total  = (int) ( $agg['total'] ?? 0 );
		$counts = is_array( $agg['counts'] ?? null ) ? $agg['counts'] : array();

		$options_in       = is_array( $poll_field['options'] ?? null ) ? $poll_field['options'] : array();
		$known_values     = array();
		$response_options = array();

		foreach ( $options_in as $opt ) {
			$opt   = (array) $opt;
			$label = (string) ( $opt['label'] ?? '' );
			$raw   = (string) ( $opt['value'] ?? '' );
			$value = $raw !== '' ? $raw : $label;
			if ( $value === '' ) {
				continue;
			}

			$known_values[] = $value;

			$votes = (int) ( $counts[ $value ] ?? 0 );
			$pct   = $total > 0 ? round( ( $votes / $total ) * 100, 2 ) : 0.0;

			$response_options[] = array(
				'label'      => $label !== '' ? $label : $value,
				'value'      => $value,
				'votes'      => $votes,
				'percentage' => $pct,
			);
		}

		$other_votes = 0;
		foreach ( $counts as $value => $votes ) {
			if ( ! in_array( (string) $value, $known_values, true ) ) {
				$other_votes += (int) $votes;
			}
		}
		if ( $other_votes > 0 ) {
			$response_options[] = array(
				'label'      => __( 'Other', 'neural-forms-lite' ),
				'value'      => '__other__',
				'votes'      => $other_votes,
				'percentage' => $total > 0 ? round( ( $other_votes / $total ) * 100, 2 ) : 0.0,
			);
		}

		return array(
			'field_id'    => $field_id,
			'label'       => (string) ( $poll_field['label'] ?? '' ),
			'total_votes' => $total,
			'options'     => $response_options,
		);
	}

	/**
	 * Count of non-trashed entries for a form. Used by the Results
	 * overview cards. Direct $wpdb call avoids loading the heavier
	 * EntryRepository::count_by_view aggregate.
	 */
	private static function count_form_entries( int $form_id ): int {
		global $wpdb;
		if ( $form_id <= 0 ) {
			return 0;
		}
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
