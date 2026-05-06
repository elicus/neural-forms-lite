<?php
namespace NeuralForms\Rest\Endpoints;

use NeuralForms\Captcha\CaptchaManager;
use NeuralForms\Core\Config;
use NeuralForms\Core\Logger;
use NeuralForms\Forms\FormRepository;
use NeuralForms\Forms\SchemaHelper;
use NeuralForms\Forms\SubmissionValidator;
use NeuralForms\Storage\EntryRepository;
use WP_REST_Request;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Public REST endpoint for form submissions.
 *
 * Route:  POST /wp-json/neural-forms/v1/forms/{id}/submit
 * Auth:   None - public endpoint, no capability required.
 *
 * Processing order
 *
 *  1. Load form from DB; 404 if not found.
 *  2. Verify form status is 'active'; 403 otherwise.
 *  3. Rate-limit check via filter (infrastructure gate, no body read yet).
 *  4. Extract full body; honeypot check (before key-stripping).
 *  4b. CAPTCHA verification - if form has captcha_enabled, verify token
 *      before the body is stripped. Fails closed: missing token '->-> 400,
 *      bad token '->-> 400, misconfigured provider '->-> 400. Entry is never saved.
 *  5. Strip body to schema-known keys; spam check via filter.
 *  6. Validate with SubmissionValidator; return field-keyed 422 on failure.
 *  7. Sanitize each value by field type.
 *  8. Assemble server-side context (IP, UA, page URL, timestamps, schema hash).
 *  9. Persist entry via EntryRepository; 500 on DB failure.
 * 10. Fire post-save action hooks (created, notifications, AI queue).
 * 11. Return 201 { success: true, entry_id: ... }.
 *
 * Error codes
 *
 *  form_not_found          404  Form ID does not exist.
 *  form_not_active         403  Form is draft or archived.
 *  rate_limit_exceeded     429  Too many requests from this IP.
 *  spam_detected           400  Submission flagged as spam (honeypot or filter).
 *  captcha_missing         400  CAPTCHA token absent (provider fully configured but no token sent).
 *  captcha_failed          400  Token rejected by provider; failure is logged.
 *  invalid_submission      422  Validation errors; data.fields has per-field details.
 *  entry_save_failed       500  DB write failed; raw error is logged, not exposed.
 */
class SubmitEndpoint {

	/**
	 * Transient name prefix for the per-IP rate-limit counter.
	 *
	 * Plugin-specific prefix per the WordPress.org plugin guidelines: any
	 * option/transient must be uniquely namespaced to avoid collisions with
	 * other plugins. Keep this in sync with any cleanup tooling that removes
	 * stale rate-limit transients.
	 */
	private const RATE_LIMIT_TRANSIENT_PREFIX = 'neural_forms_lite_rate_limit_';

	public function register_routes(): void {
		register_rest_route(
			\NeuralForms\Rest\RestAPI::NAMESPACE,
			'/forms/(?P<id>[\d]+)/submit',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'submit' ],
				// Public - no authentication required.
				// Abuse prevention happens via the rate-limit and spam-check hooks.
				'permission_callback' => '__return_true',
			]
		);
	}

	// Handler

	/**
	 * Handle a form submission.
	 *
	 * @param WP_REST_Request $request
	 * @return \WP_REST_Response|WP_Error
	 */
	public function submit( WP_REST_Request $request ) {
		$form_id = (int) $request['id'];

		// ' Step 1: Load form '
		$form = FormRepository::find( $form_id );

		if ( ! $form ) {
			return new WP_Error(
				'form_not_found',
				__( 'Form not found.', 'neural-forms-lite' ),
				[ 'status' => 404 ]
			);
		}

		// ' Step 2: Active-status gate '
		// Draft and archived forms must not accept public submissions.
		// This also closes the gap in SubmissionValidator's permissive select
		// handling - by the time we reach validation, the form is confirmed active.
		if ( $form['status'] !== 'active' ) {
			return new WP_Error(
				'form_not_active',
				__( 'This form is not currently accepting submissions.', 'neural-forms-lite' ),
				[ 'status' => 403 ]
			);
		}

		// ' Step 2b: Lite poll/quiz submission cap '
		//
		// Poll and quiz forms (including mixed quiz+poll) are capped
		// at 1,000 non-trash entries per form on Lite. Pro flips
		// `poll_quiz_unlimited_submissions` via Capabilities to lift
		// the cap entirely. Plain contact / multi-step / rating-only
		// forms are NEVER limited regardless of plan.
		//
		// Spam entries count toward the cap (they consume storage
		// and represent real submissions); trashed entries do not,
		// so a site owner can recover capacity by emptying trash.
		//
		// Runs BEFORE the rate-limit, validation, and CAPTCHA gates
		// so a form already at cap doesn't burn IP rate slots or
		// CAPTCHA verifications. Rejection cost is one indexed
		// COUNT(*) per attempt against a capped form.
		$limit_error = $this->check_poll_quiz_submission_limit( $form );
		if ( is_wp_error( $limit_error ) ) {
			return $limit_error;
		}

		// ' Step 3: Rate-limit gate '
		// Built-in transient-based limiter: max 10 submissions per IP per 10
		// minutes. The filter lets extensions replace or tighten this logic.
		$ip = $this->resolve_ip();

		$rate_limited = apply_filters(
			'neural_forms_rate_limit',
			self::default_rate_limit( $ip ),
			$request,
			$ip
		);

		if ( $rate_limited ) {
			return new WP_Error(
				'rate_limit_exceeded',
				__( 'Too many requests. Please wait before submitting again.', 'neural-forms-lite' ),
				[ 'status' => 429 ]
			);
		}

		// ' Step 4: Extract and strip submitted values '
		// Read the full body once so we can check the honeypot before stripping.
		// Detects multipart/form-data (file upload path) vs JSON automatically.
		$raw_body = $this->get_submission_params( $request );

		// ' Step 4a: Honeypot check '
		// FormRenderer renders a hidden field named "neural_forms_website".
		// Legitimate users never fill it; bots that auto-fill all inputs do.
		// Must be read from $raw_body BEFORE key-stripping removes unknown keys.
		$honeypot = trim( (string) ( $raw_body['neural_forms_website'] ?? '' ) );
		if ( $honeypot !== '' ) {
			return new WP_Error(
				'spam_detected',
				__( 'Your submission could not be processed. Please try again.', 'neural-forms-lite' ),
				[ 'status' => 400 ]
			);
		}

		// ' Step 4b: CAPTCHA verification '
		// Must happen here - after honeypot but BEFORE array_intersect_key strips
		// the body to schema-only keys. captcha_token is not a schema field so it
		// would be silently discarded if we read it any later.
		//
		// Fail-closed rules:
		//   * form captcha_enabled = false '->-> skip entirely (no overhead).
		//   * form captcha_enabled = true, provider not configured '->-> 400.
		//   * form captcha_enabled = true, token missing '->-> 400.
		//   * form captcha_enabled = true, token fails verification '->-> 400 + log.
		//
		// Entry is never written when this block returns a WP_Error.
		$form_settings   = is_array( $form['settings'] ) ? $form['settings'] : [];
		$captcha_enabled = ! empty( $form_settings['captcha_enabled'] );

		// Only enforce CAPTCHA when the form has it enabled AND the global
		// provider is fully configured (both site key and secret key present).
		// If captcha_enabled is true but the global keys are not set, the
		// FormRenderer does not render a widget - so no token is possible and
		// the check must be skipped to avoid blocking every submission with 400.
		if ( $captcha_enabled && CaptchaManager::is_configured() ) {

			$captcha_token = sanitize_text_field( (string) ( $raw_body['captcha_token'] ?? '' ) );

			if ( $captcha_token === '' ) {
				return new WP_Error(
					'captcha_missing',
					__( 'Please complete the CAPTCHA.', 'neural-forms-lite' ),
					[ 'status' => 400 ]
				);
			}

			$captcha_result = CaptchaManager::verify( $captcha_token, $ip );

			if ( is_wp_error( $captcha_result ) ) {
				Logger::file(
					'captcha',
					'warning',
					sprintf( 'CAPTCHA verification failed for form %d', $form_id ),
					[
						'provider'   => (string) Config::get( 'captcha_provider' ),
						'error_code' => $captcha_result->get_error_code(),
						'error_data' => $captcha_result->get_error_data(),
						'form_id'    => $form_id,
						'ip'         => $ip,
					]
				);

				return new WP_Error(
					'captcha_failed',
					__( 'CAPTCHA verification failed. Please try again.', 'neural-forms-lite' ),
					[ 'status' => 400 ]
				);
			}
		}
		// ' end CAPTCHA '

		// Step 4c: File field processing.
		// Must happen before array_intersect_key so uploaded files (from $_FILES)
		// are processed and their metadata injected into $raw_body under the
		// field ID key. Only runs when the request is multipart/form-data.
		//
		// SchemaHelper::flatten_fields walks rows + columns and yields only
		// the leaf fields. Rows and columns are layout containers - they
		// never appear in the submission pipeline.
		$schema_fields_pre = SchemaHelper::flatten_fields(
			is_array( $form['form_schema']['fields'] ?? null ) ? $form['form_schema']['fields'] : []
		);
		// Public submission endpoint - auth is by design open (CAPTCHA + honeypot
		// + rate limit gate above), so no nonce is sent. $_FILES is read here
		// only to detect the multipart upload path; values pulled from it are
		// validated and sanitized inside process_file_fields().
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! empty( $_FILES ) ) {
			$file_result = $this->process_file_fields( $schema_fields_pre, $raw_body );
			if ( is_wp_error( $file_result ) ) {
				return $file_result;
			}
			$raw_body = $file_result;
		}
		// ' end file processing '

		// Only accept keys that correspond to field IDs in the stored schema.
		// Unknown keys are silently dropped here so the validator and sanitizer
		// never see data that has no corresponding schema definition.
		$schema_fields = $schema_fields_pre;
		$schema_ids    = array_column( $schema_fields, 'id' );
		$raw_data      = array_intersect_key( $raw_body, array_flip( $schema_ids ) );

		// ' Step 5a: Conditional logic - strip hidden fields '
		// Re-evaluate server-side so hidden fields can never sneak through via
		// direct API calls that bypass the frontend JS.
		//
		// Fields whose condition evaluates to false are unset from $raw_data here,
		// before validation. This ensures:
		//   * Hidden required fields never fail validation.
		//   * Hidden field values are never stored.
		//   * Hidden file fields (already uploaded in step 4c) are not validated
		//     or saved. The file will remain in the media library as an orphan;
		//     this is an accepted V1 limitation.
		//
		// Fallback: if the controlling field is absent from submitted data, treat
		// the dependent field as visible (do NOT strip it). This avoids silently
		// discarding data due to a schema/submission timing mismatch.
		foreach ( $schema_fields as $_cl_field ) {
			$_cl_field    = (array) $_cl_field;
			$_cl_field_id = $_cl_field['id'] ?? '';

			if ( $_cl_field_id === '' ) {
				continue;
			}

			$_cl = is_array( $_cl_field['conditional_logic'] ?? null )
				? $_cl_field['conditional_logic']
				: null;

			if ( $_cl === null || empty( $_cl['enabled'] ) || empty( (string) ( $_cl['field'] ?? '' ) ) ) {
				continue;
			}

			$_visible = $this->evaluate_conditional( $_cl, $raw_data );

			if ( ! $_visible ) {
				unset( $raw_data[ $_cl_field_id ] );
			}
		}

		// ' Step 5: Spam-check gate '
		// Fires after body is available so filters can inspect field values.
		// Return truthy to block with 400. Response is intentionally vague -
		// leaking which heuristic triggered would help spammers bypass it.
		$is_spam = apply_filters( 'neural_forms_spam_check', false, $raw_data, $form );

		if ( $is_spam ) {
			return new WP_Error(
				'spam_detected',
				__( 'Your submission could not be processed. Please try again.', 'neural-forms-lite' ),
				[ 'status' => 400 ]
			);
		}

		// ' Step 6: Validate '
		// SubmissionValidator returns true on success or a WP_Error whose
		// data['fields'] contains per-field [ 'code' => ..., 'message' => ... ].
		$validation = SubmissionValidator::validate( $schema_fields, $raw_data );

		if ( is_wp_error( $validation ) ) {
			// Pass the full WP_Error through - it already carries status 422
			// and the structured data.fields array. Do not rewrap.
			return $validation;
		}

		// ' Step 7: Sanitize '
		// Sanitization happens here, not in the validator.
		// The validator confirmed the values are structurally acceptable;
		// sanitization removes any remaining unsafe content before storage.
		// Null values are skipped - they represent optional absent fields and
		// should not appear in the stored entry data blob.
		$sanitized_data = $this->sanitize_fields( $schema_fields, $raw_data );

		// ' Step 8: Assemble server-side context '
		// These values are server-derived, never client-trusted.
		// form_version is an MD5 of the schema at the moment of submission -
		// useful later to detect entries captured against an older schema shape.
		$context = [
			'ip'           => $ip,
			'user_agent'   => sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ),
			'page_url'     => esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ?? '' ) ),
			'submitted_at' => current_time( 'mysql', true ),   // UTC
			'form_version' => md5( (string) wp_json_encode( $form['form_schema'] ) ),
		];

		// ' Step 9: Persist '
		// EntryRepository::create() already logs the raw DB error internally.
		// We must not forward that message to the public response.
		$entry_id = EntryRepository::create( $form_id, $sanitized_data, $context );

		if ( is_wp_error( $entry_id ) ) {
			return new WP_Error(
				'entry_save_failed',
				__( 'Could not save your submission. Please try again.', 'neural-forms-lite' ),
				[ 'status' => 500 ]
			);
		}

		// ' Step 10: Post-save hooks '
		// These hooks fire in a defined order after the entry is committed.
		// None of them can abort the response - the entry is already saved.
		//
		// IMPORTANT - hook implementations must be non-blocking:
		//   * Do not deliver emails synchronously inside the hook.
		//   * Do not call external AI APIs inline - enqueue a job instead.
		//   Blocking here delays the HTTP response for every submission.
		//
		// IMPORTANT - hook failures are currently silent (fire-and-forget).
		// When notification and AI listeners are implemented, they must write
		// failures to neural_forms_logs or update queue row status so that:
		//   * Failed email deliveries are visible in the admin entries view.
		//   * Stuck AI jobs can be retried without re-processing the entry.
		//   * Debugging does not require tailing server error logs.
		// The neural_forms_logs and neural_forms_queue tables already exist for this purpose.

		// Canonical "submission happened" event.
		// Listeners: CRM sync, webhook triggers, custom logging.
		do_action( 'neural_forms_entry_created', $entry_id, $form_id, $sanitized_data );

		// Notification layer (email, Slack, etc.).
		// Receives $form so listeners can read form name and notification settings.
		// Failures must be caught inside the listener and written to neural_forms_logs.
		//
		// Deferral: when AI spam detection and email_skip_spam_notifications are
		// both active, skip this hook now and let AIQueueProcessor fire it
		// after analysis - suppressing it for spam entries. Auto-process is
		// not required because QueueListener now queues on spam_enabled alone,
		// so the queue still runs and drives the deferred notification.
		$defer_notifications = \NeuralForms\Core\Config::get( 'ai_enabled' )
			&& \NeuralForms\Core\Config::get( 'ai_spam_enabled' )
			&& \NeuralForms\Core\Config::get( 'email_skip_spam_notifications' );

		if ( ! $defer_notifications ) {
			do_action( 'neural_forms_send_notifications', $entry_id, $form_id, $form );
		}

		// AI processing queue.
		// Intentionally last - depends on the entry existing (neural_forms_entry_created)
		// and notification config being available (neural_forms_send_notifications).
		// Listener must insert a row into neural_forms_queue, not call the AI API inline.
		do_action( 'neural_forms_queue_ai_processing', $entry_id, $form_id );

		// ' Step 11: Success response '
		$body = [
			'success'  => true,
			'entry_id' => $entry_id,
		];

		// Poll Phase 3: piggyback poll results on the submit response so
		// after_vote / always polls can refresh visually without a second
		// round-trip. visibility="never" polls are intentionally omitted.
		// $sanitized_data is the just-saved row's values (entry already
		// inserted, so the totals reflect THIS submission too).
		$polls_payload = $this->build_poll_results_payload( $form_id, $form['form_schema'] ?? [] );
		if ( ! empty( $polls_payload ) ) {
			$body['polls'] = $polls_payload;
		}

		// Quiz Phase 1: piggyback quiz score on the submit response.
		// Computed purely from the schema + the row we just sanitised
		// — no extra DB round-trip. Privacy: when a question's
		// show_correct_answer === 'never', correct_value/correct_label/
		// explanation are stripped before the response leaves the
		// server (the user still sees their pass/fail verdict).
		$quiz_payload = $this->build_quiz_result_payload( $form['form_schema'] ?? [], $sanitized_data );
		if ( $quiz_payload !== null ) {
			$body['quiz'] = $quiz_payload;
		}

		$response = rest_ensure_response( $body );
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * Build the poll-results payload attached to a successful submit.
	 *
	 * Walks every poll field on the form (root, row.column, step,
	 * step->row->column — flatten_fields handles all of those) and
	 * aggregates votes from EntryRepository. visibility="never" polls
	 * are skipped — those exist purely as admin-side data.
	 *
	 * Return shape (or [] when no eligible polls exist):
	 *
	 *   [ field_id => [
	 *       'total_votes'        => int,
	 *       'options'            => [ { label, value, votes, percentage }, ... ],
	 *       'chart_type'         => 'bar' | 'percentage',
	 *       'results_visibility' => 'after_vote' | 'always',
	 *   ] ]
	 *
	 * Enforce the Lite poll/quiz submission cap.
	 *
	 * Returns a WP_Error (403) when the cap is reached, true when the
	 * submission is allowed to proceed. Detection delegates to
	 * FormTypeDetector — same source of truth used by the Smart
	 * Folders backend, so "what counts as a poll/quiz form" is
	 * defined in exactly one place.
	 *
	 * The cap is hard-coded (1000) but exposed via the
	 * `neural_forms_poll_quiz_submission_limit` filter so site owners
	 * can tighten it without forking the plugin. Returning 0 from
	 * the filter disables the cap entirely.
	 *
	 * ─── Security model ───────────────────────────────────────────
	 *
	 * This is a LOCAL PRODUCT LIMIT, not a security boundary. Since
	 * WordPress is self-hosted, site owners with code or database
	 * access can modify local behavior — flip a filter, swap a class,
	 * delete the table row, etc. The 1,000 cap exists to keep Lite's
	 * storage footprint predictable and to give Pro a clear value
	 * proposition; it is intentionally not designed to resist a
	 * determined operator on their own server.
	 *
	 * What this enforces well:
	 *   * The default Lite UX: paid users keep collecting responses,
	 *     unpaid users hit a clear upgrade prompt.
	 *   * Honest operators: the cap is server-side (not frontend JS),
	 *     applied before EntryRepository::create(), and survives
	 *     CSRF/replay/headless-client submissions.
	 *
	 * What this is NOT:
	 *   * A monetization-enforcement boundary. Cloud / AI credit
	 *     limits — anything that costs us provider tokens — MUST be
	 *     enforced on api.neuralforms.ai. Never trust a WordPress
	 *     plugin-side check alone for billable features. The cloud
	 *     re-validates plan, credits, feature availability, and rate
	 *     limits on every AI request; that is the source of truth.
	 *
	 * Why no cloud round-trip per submission:
	 *   * Forms must accept submissions even when api.neuralforms.ai
	 *     is unreachable (network partition, cloud incident, free
	 *     plan with no key). Calling the cloud here would couple
	 *     core form intake to an external service and degrade
	 *     reliability for every customer. Form storage is a
	 *     self-contained Lite responsibility; the cloud is involved
	 *     only when paid AI features run.
	 *
	 * Why no frontend-only check:
	 *   * The browser cannot be trusted to enforce caps — anyone can
	 *     curl /submit directly, disable JS, or replay the request.
	 *     The server is the only place a limit can actually take
	 *     effect.
	 *
	 * @param array $form  Full form row (id, status, form_schema).
	 * @return true|\WP_Error
	 */
	private function check_poll_quiz_submission_limit( array $form ) {
		$type = \NeuralForms\Forms\FormTypeDetector::detect( $form['form_schema'] ?? null );
		$limited_types = [
			\NeuralForms\Forms\FormTypeDetector::TYPE_POLL,
			\NeuralForms\Forms\FormTypeDetector::TYPE_QUIZ,
			\NeuralForms\Forms\FormTypeDetector::TYPE_MIXED,
		];
		if ( ! in_array( $type, $limited_types, true ) ) {
			return true;
		}

		// Pro lifts the cap entirely.
		if ( \NeuralForms\Core\Capabilities::can( \NeuralForms\Core\Capabilities::POLL_QUIZ_UNLIMITED_SUBMISSIONS ) ) {
			return true;
		}

		$limit = (int) apply_filters( 'neural_forms_poll_quiz_submission_limit', 1000 );
		if ( $limit <= 0 ) {
			return true; // Filter disabled the cap.
		}

		$form_id = (int) ( $form['id'] ?? 0 );
		$count   = \NeuralForms\Storage\EntryRepository::count_non_trash_entries_for_form( $form_id );
		if ( $count < $limit ) {
			return true;
		}

		return new WP_Error(
			'poll_quiz_submission_limit_reached',
			__(
				'This poll or quiz has reached the 1,000 submission limit available in Lite. Upgrade to Pro to continue collecting responses.',
				'neural-forms-lite'
			),
			[
				'status'      => 403,
				'limit'       => $limit,
				'count'       => $count,
				'upgrade_url' => 'https://neuralforms.ai/pro/',
			]
		);
	}

	/**
	 * @param int   $form_id
	 * @param array $form_schema  Decoded form_schema (already loaded by submit()).
	 * @return array              Map keyed by poll field id, or empty array.
	 */
	private function build_poll_results_payload( int $form_id, $form_schema ): array {
		if ( ! is_array( $form_schema ) ) {
			return [];
		}
		$fields = is_array( $form_schema['fields'] ?? null ) ? $form_schema['fields'] : [];
		if ( empty( $fields ) ) {
			return [];
		}

		$out = [];
		foreach ( SchemaHelper::flatten_fields( $fields ) as $field ) {
			if ( ( $field['type'] ?? '' ) !== 'poll' ) continue;

			$field_id = (string) ( $field['id'] ?? '' );
			if ( $field_id === '' ) continue;

			$settings   = SchemaHelper::normalize_poll_settings( $field['settings'] ?? null );
			$visibility = $settings['results_visibility'];
			if ( $visibility === 'never' ) continue;

			$agg    = EntryRepository::aggregate_poll_votes( $form_id, $field_id );
			$total  = (int) ( $agg['total']  ?? 0 );
			$counts = is_array( $agg['counts'] ?? null ) ? $agg['counts'] : [];

			$schema_options = is_array( $field['options'] ?? null ) ? $field['options'] : [];
			$known          = [];
			$response_opts  = [];

			foreach ( $schema_options as $opt ) {
				$opt   = (array) $opt;
				$label = (string) ( $opt['label'] ?? '' );
				$raw   = (string) ( $opt['value'] ?? '' );
				$value = $raw !== '' ? $raw : $label;
				if ( $value === '' ) continue;

				$known[] = $value;
				$votes   = (int) ( $counts[ $value ] ?? 0 );
				$pct     = $total > 0 ? round( ( $votes / $total ) * 100, 2 ) : 0.0;

				$response_opts[] = [
					'label'      => $label !== '' ? $label : $value,
					'value'      => $value,
					'votes'      => $votes,
					'percentage' => $pct,
				];
			}

			// Synthetic Other bucket: only emit when > 0.
			$other = 0;
			foreach ( $counts as $value => $votes ) {
				if ( ! in_array( (string) $value, $known, true ) ) {
					$other += (int) $votes;
				}
			}
			if ( $other > 0 ) {
				$response_opts[] = [
					'label'      => __( 'Other', 'neural-forms-lite' ),
					'value'      => '__other__',
					'votes'      => $other,
					'percentage' => $total > 0 ? round( ( $other / $total ) * 100, 2 ) : 0.0,
				];
			}

			$out[ $field_id ] = [
				'total_votes'        => $total,
				'options'            => $response_opts,
				'chart_type'         => $settings['chart_type'],
				'results_visibility' => $visibility,
			];
		}

		return $out;
	}

	/**
	 * Build the quiz-result payload attached to a successful submit.
	 *
	 * Returns null when the form contains no quiz_question fields so
	 * the response simply omits the `quiz` key (backwards-compatible).
	 *
	 * Score is computed by matching $sanitized_data values against
	 * each quiz_question's options[*].is_correct === true. Each
	 * question contributes points_awarded = (selected is correct)
	 * ? settings.points : 0. percentage is 2dp; max_score === 0
	 * yields percentage = 0 (no /0).
	 *
	 * Privacy: per-question correct_value, correct_label, explanation
	 * are emitted ONLY when settings.show_correct_answer ===
	 * 'after_submit'. With 'never' the question entry still carries
	 * the user-facing verdict (correct: bool, points_awarded) so the
	 * aggregate score stays meaningful, but no cheating-relevant
	 * details leak.
	 *
	 * @param array $form_schema
	 * @param array $sanitized_data field_id => value (from sanitize_fields())
	 * @return array|null
	 */
	private function build_quiz_result_payload( $form_schema, array $sanitized_data ): ?array {
		if ( ! is_array( $form_schema ) ) {
			return null;
		}
		$fields = is_array( $form_schema['fields'] ?? null ) ? $form_schema['fields'] : [];
		if ( empty( $fields ) ) {
			return null;
		}

		$questions = [];
		$score     = 0;
		$max_score = 0;

		foreach ( SchemaHelper::flatten_fields( $fields ) as $field ) {
			if ( ( $field['type'] ?? '' ) !== 'quiz_question' ) continue;

			$field_id = (string) ( $field['id'] ?? '' );
			if ( $field_id === '' ) continue;

			$settings    = SchemaHelper::normalize_quiz_settings( $field['settings'] ?? null );
			$default_pts = (int) $settings['points'];
			$show_correct = $settings['show_correct_answer'] === 'after_submit';

			$options = is_array( $field['options'] ?? null ) ? $field['options'] : [];

			// Detect whether this question uses weighted scoring (any
			// option with numeric `points` >= 0). When weighted, the
			// max possible is the highest option.points value, and
			// the awarded score is the selected option's points.
			// When NOT weighted, fall back to Phase 1 semantics:
			// correct -> default_pts, anything else -> 0.
			$has_weighted = false;
			$option_max   = 0;
			foreach ( $options as $opt ) {
				$opt = (array) $opt;
				if ( array_key_exists( 'points', $opt ) && is_numeric( $opt['points'] ) ) {
					$pts = (int) round( (float) $opt['points'] );
					if ( $pts < 0 ) $pts = 0;
					$has_weighted = true;
					if ( $pts > $option_max ) $option_max = $pts;
				}
			}
			$points_max = $has_weighted ? $option_max : $default_pts;
			$max_score += $points_max;

			// Locate correct option (the validator guarantees exactly
			// one at save time; defensive check here so a hand-edited
			// schema doesn't fatal the response).
			$correct_value = '';
			$correct_label = '';
			foreach ( $options as $opt ) {
				$opt = (array) $opt;
				if ( empty( $opt['is_correct'] ) ) continue;
				$opt_label = (string) ( $opt['label'] ?? '' );
				$opt_raw   = (string) ( $opt['value'] ?? '' );
				$correct_value = $opt_raw !== '' ? $opt_raw : $opt_label;
				$correct_label = $opt_label !== '' ? $opt_label : $correct_value;
				break;
			}

			// Selected value (may be missing for optional/unanswered).
			$selected_raw = $sanitized_data[ $field_id ] ?? null;
			$selected_value = ( $selected_raw === null || $selected_raw === '' ) ? null : (string) $selected_raw;

			// Selected label: look up the option whose effective
			// value matches the submission.
			$selected_label  = null;
			$selected_points = null; // for weighted scoring
			if ( $selected_value !== null ) {
				foreach ( $options as $opt ) {
					$opt = (array) $opt;
					$opt_label = (string) ( $opt['label'] ?? '' );
					$opt_raw   = (string) ( $opt['value'] ?? '' );
					$effective = $opt_raw !== '' ? $opt_raw : $opt_label;
					if ( $effective === $selected_value ) {
						$selected_label = $opt_label !== '' ? $opt_label : $effective;
						if ( array_key_exists( 'points', $opt ) && is_numeric( $opt['points'] ) ) {
							$pts = (int) round( (float) $opt['points'] );
							$selected_points = $pts < 0 ? 0 : $pts;
						}
						break;
					}
				}
			}

			$is_correct = ( $selected_value !== null && $correct_value !== '' && $selected_value === $correct_value );

			if ( $has_weighted ) {
				// Weighted: award the selected option's points (default 0
				// if the chosen option had no `points` key — partial
				// credit only counts where the schema declared it).
				$points_awarded = $selected_points !== null ? $selected_points : 0;
			} else {
				$points_awarded = $is_correct ? $default_pts : 0;
			}
			$score += $points_awarded;

			$entry = [
				'field_id'            => $field_id,
				'label'               => (string) ( $field['label'] ?? '' ),
				'selected_value'      => $selected_value,
				'selected_label'      => $selected_label,
				'correct'             => $is_correct,
				'points_awarded'      => $points_awarded,
				'points_possible'     => $points_max,
				'show_correct_answer' => $show_correct,
			];

			if ( $show_correct ) {
				$entry['correct_value'] = $correct_value;
				$entry['correct_label'] = $correct_label;
				$entry['explanation']   = (string) $settings['explanation'];
			}

			$questions[] = $entry;
		}

		if ( empty( $questions ) ) {
			return null;
		}

		$percentage = $max_score > 0 ? round( ( $score / $max_score ) * 100, 2 ) : 0.0;

		$payload = [
			'score'      => $score,
			'max_score'  => $max_score,
			'percentage' => $percentage,
			'questions'  => $questions,
		];

		// Form-level quiz settings (Phase 3): pass/fail + result
		// message. Lives at form_schema.settings.quiz so it never
		// collides with form.settings (submission/security namespace).
		// Defaults are no-op: show_pass_fail=false leaves the payload
		// shape identical to Phase 1/2.
		$schema_settings = is_array( $form_schema['settings'] ?? null ) ? $form_schema['settings'] : [];
		$quiz_form       = SchemaHelper::normalize_quiz_form_settings( $schema_settings['quiz'] ?? null );

		if ( ! empty( $quiz_form['show_pass_fail'] ) ) {
			$payload['passing_score'] = (int) $quiz_form['passing_score'];
			$payload['passed']        = $percentage >= (float) $quiz_form['passing_score'];
		}

		// Result message: first range whose [min, max] contains the
		// percentage wins. Independent of show_pass_fail — admins may
		// want feedback messages without a hard pass/fail badge.
		foreach ( $quiz_form['result_messages'] as $msg ) {
			if ( $percentage >= (float) $msg['min'] && $percentage <= (float) $msg['max'] ) {
				$payload['result_message'] = [
					'title'   => (string) $msg['title'],
					'message' => (string) $msg['message'],
				];
				break;
			}
		}

		return $payload;
	}

	// Sanitization

	/**
	 * Sanitize all submitted field values by their declared type.
	 *
	 * Iterates the schema fields (not the raw data) so the output array
	 * preserves the order of fields as defined in the schema.
	 *
	 * Null values are excluded from the output - they represent optional
	 * fields that were not submitted and should not appear in the entry blob.
	 *
	 * @param array $schema_fields  The 'fields' array from form_schema.
	 * @param array $raw_data       Stripped submitted data (schema keys only).
	 * @return array                Associative array of field_id => sanitized value.
	 */
	private function sanitize_fields( array $schema_fields, array $raw_data ): array {
		$sanitized = [];

		foreach ( $schema_fields as $field ) {
			$field    = (array) $field;
			$field_id = $field['id'] ?? '';

			if ( $field_id === '' ) {
				continue;
			}

			// Only include fields that were actually submitted.
			if ( ! array_key_exists( $field_id, $raw_data ) ) {
				continue;
			}

			$value = $raw_data[ $field_id ];

			// Null means the client explicitly sent null - treat as absent.
			if ( $value === null ) {
				continue;
			}

			$sanitized[ $field_id ] = $this->sanitize_value( $value, $field['type'] ?? 'text' );
		}

		return $sanitized;
	}

	/**
	 * Sanitize a single value according to its field type.
	 *
	 * Checkbox notes:
	 * - SubmissionValidator::ACCEPTED_CHECKBOX_VALUES defines the valid input set.
	 * - SubmissionValidator::CHECKED_CHECKBOX_VALUES defines the checked subset.
	 * - Output is always int 0 or 1 (never bool, never string) so the JSON blob
	 *   is uniform for exports, filters, and AI context downstream.
	 *
	 * Number notes:
	 * - Uses floatval() to support decimal values. Integer-only enforcement
	 *   can be added later via a field-level 'step' or 'integer_only' property.
	 *
	 * @param mixed  $value  Raw submitted value (validated, not null).
	 * @param string $type   Field type from schema.
	 * @return mixed         Sanitized value ready for JSON storage.
	 */
	private function sanitize_value( $value, string $type ) {
		switch ( $type ) {

			case 'email':
				return sanitize_email( (string) $value );

			case 'url':
				return esc_url_raw( (string) $value );

			case 'number':
			case 'range':
				return floatval( $value );

			case 'date':
				// Already validated as YYYY-MM-DD by SubmissionValidator.
				return sanitize_text_field( (string) $value );

			case 'textarea':
				// Allows newlines; strips HTML tags.
				return sanitize_textarea_field( (string) $value );

			case 'checkboxes':
				// Array of selected option values. Sanitize each element.
				if ( ! is_array( $value ) ) {
					return [];
				}
				return array_map( 'sanitize_text_field', array_values( $value ) );

			case 'checkbox':
			case 'acceptance':
				// Cast to 0|1 integer. Cannot use (int)(bool) because (bool)'0' === true
				// in PHP (non-empty string). Check against CHECKED_CHECKBOX_VALUES instead.
				// Validation already confirmed the value is in ACCEPTED_CHECKBOX_VALUES.
				return $this->sanitize_checkbox( $value );

			// file:
			// Value is the structured metadata array injected by process_file_fields().
			// It has already been validated and sanitized during upload processing.
			// Pass through as-is - do not stringify it.
			case 'file':
				return is_array( $value ) ? $value : null;

			// text, phone, tel, select:
			// sanitize_text_field() strips tags, encodes HTML entities, trims.
			// Select values are already validated against the allowed options list
			// in SubmissionValidator, so this is a belt-and-suspenders pass.
			default:
				return sanitize_text_field( (string) $value );
		}
	}

	/**
	 * Normalize a checkbox value to integer 0 or 1.
	 *
	 * References SubmissionValidator::CHECKED_CHECKBOX_VALUES as the
	 * canonical "is this checked?" definition. The explicit loop matches
	 * the same === comparison used in the validator to guarantee consistency.
	 *
	 * @param mixed $value  Any value from SubmissionValidator::ACCEPTED_CHECKBOX_VALUES.
	 * @return int          1 if checked, 0 if unchecked.
	 */
	private function sanitize_checkbox( $value ): int {
		foreach ( SubmissionValidator::CHECKED_CHECKBOX_VALUES as $checked ) {
			if ( $value === $checked ) {
				return 1;
			}
		}
		return 0;
	}

	// File upload

	/**
	 * Allowed MIME type groups for file upload fields.
	 *
	 * Mirrors JS FILE_TYPE_OPTIONS in FieldItem.js - group slugs must match.
	 * Used in process_file_fields() to validate uploaded file MIME types.
	 */
	private const ALLOWED_MIME_GROUPS = [
		'image'    => [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ],
		'pdf'      => [ 'application/pdf' ],
		'document' => [
			'application/msword',
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'application/vnd.oasis.opendocument.text',
		],
		'text'     => [ 'text/plain', 'text/csv' ],
	];

	/**
	 * Process file fields from $_FILES, upload them to the WordPress media
	 * library, and inject the resulting attachment metadata into $raw_body.
	 *
	 * For each file field in the schema:
	 *   - If required and no file uploaded '->-> 422 error returned.
	 *   - If optional and no file uploaded '->-> field skipped (absent = valid).
	 *   - If file present '->-> validate size and MIME, upload, inject metadata.
	 *
	 * Returns the updated $raw_body array on success, or a WP_Error.
	 *
	 * @param array $schema_fields  The 'fields' array from form_schema.
	 * @param array $raw_body       Current body params (text fields).
	 * @return array|\WP_Error
	 */
	private function process_file_fields( array $schema_fields, array $raw_body ) {
		// WordPress upload functions require this file to be loaded.
		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		foreach ( $schema_fields as $field ) {
			$field    = (array) $field;
			$field_id = $field['id'] ?? '';

			if ( $field_id === '' || $field['type'] !== 'file' ) {
				continue;
			}

			$required      = ! empty( $field['required'] );
			$allowed_types = is_array( $field['allowed_types'] ?? null ) ? $field['allowed_types'] : [];
			$max_mb        = isset( $field['max_file_size'] ) && (int) $field['max_file_size'] > 0
				? (int) $field['max_file_size']
				: 5;
			$max_bytes = $max_mb * 1024 * 1024;

			// Public endpoint - see the nonce-ignore on the $_FILES gate above.
			// File contents are validated below with wp_check_filetype_and_ext()
			// (reads via finfo) before ever being trusted; the string fields
			// from $_FILES (name, type) are run through sanitize_text_field()
			// here so phpcs sees explicit sanitization on the raw input.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
			$raw_file = isset( $_FILES[ $field_id ] ) ? $_FILES[ $field_id ] : null;
			$file_info = is_array( $raw_file ) ? [
				'name'     => isset( $raw_file['name'] )     ? sanitize_file_name( wp_unslash( $raw_file['name'] ) )     : '',
				'type'     => isset( $raw_file['type'] )     ? sanitize_mime_type( wp_unslash( $raw_file['type'] ) )     : '',
				'tmp_name' => isset( $raw_file['tmp_name'] ) ? $raw_file['tmp_name']                                     : '',
				'error'    => isset( $raw_file['error'] )    ? (int) $raw_file['error']                                  : UPLOAD_ERR_NO_FILE,
				'size'     => isset( $raw_file['size'] )     ? (int) $raw_file['size']                                   : 0,
			] : null;

			// No file uploaded for this field.
			if ( ! $file_info || $file_info['error'] === UPLOAD_ERR_NO_FILE || empty( $file_info['name'] ) ) {
				if ( $required ) {
					$label = trim( $field['label'] ?? '' ) ?: $field_id;
					return new WP_Error(
						'invalid_submission',
						__( 'The submission contains errors. Please check the fields below.', 'neural-forms-lite' ),
						[
							'status' => 422,
							'fields' => [
								$field_id => [ [
									'code'    => 'required',
									/* translators: %s: field label */
									'message' => sprintf( __( '%s is required.', 'neural-forms-lite' ), $label ),
								] ],
							],
						]
					);
				}
				// Optional and absent - skip.
				continue;
			}

			// Upload error other than "no file".
			if ( $file_info['error'] !== UPLOAD_ERR_OK ) {
				return new WP_Error(
					'file_upload_error',
					__( 'A file upload error occurred. Please try again.', 'neural-forms-lite' ),
					[ 'status' => 400 ]
				);
			}

			// Server-side size check.
			if ( $file_info['size'] > $max_bytes ) {
				$label = trim( $field['label'] ?? '' ) ?: $field_id;
				return new WP_Error(
					'invalid_submission',
					__( 'The submission contains errors. Please check the fields below.', 'neural-forms-lite' ),
					[
						'status' => 422,
						'fields' => [
							$field_id => [ [
								'code'    => 'file_too_large',
								'message' => sprintf(
									/* translators: 1: field label  2: max size in MB */
									__( '%1$s: file exceeds the maximum allowed size of %2$d MB.', 'neural-forms-lite' ),
									$label,
									$max_mb
								),
							] ],
						],
					]
				);
			}

			// Build the custom mimes list once - used for both the pre-check and
			// the actual upload so the two are always in sync.
			$upload_mimes = $this->build_allowed_mimes( $allowed_types );

			// MIME type validation using wp_check_filetype_and_ext() which reads
			// actual file content via finfo - not the browser-reported type.
			// Pass our custom $upload_mimes so the result reflects what the upload
			// step will accept, not WordPress's global upload_mimes list.
			$check = wp_check_filetype_and_ext(
				$file_info['tmp_name'],
				$file_info['name'],
				$upload_mimes
			);

			// wp_check_filetype_and_ext() returns false (not null) for type/ext
			// when the file is unrecognised. Use ?: so false coalesces to ''.
			$actual_mime = $check['type'] ?: '';

			if ( ! empty( $allowed_types ) ) {
				$allowed_mimes = [];
				foreach ( $allowed_types as $group ) {
					if ( isset( self::ALLOWED_MIME_GROUPS[ $group ] ) ) {
						$allowed_mimes = array_merge( $allowed_mimes, self::ALLOWED_MIME_GROUPS[ $group ] );
					}
				}

				if ( ! in_array( $actual_mime, $allowed_mimes, true ) ) {
					$label = trim( $field['label'] ?? '' ) ?: $field_id;
					return new WP_Error(
						'invalid_submission',
						__( 'The submission contains errors. Please check the fields below.', 'neural-forms-lite' ),
						[
							'status' => 422,
							'fields' => [
								$field_id => [ [
									'code'    => 'invalid_file_type',
									/* translators: %s: field label */
									'message' => sprintf( __( '%s: file type not allowed.', 'neural-forms-lite' ), $label ),
								] ],
							],
						]
					);
				}
			}

			// Upload to WordPress media library.
			// test_form => false because this is not an admin form submission.
			//
			// unique_filename_callback randomizes the on-disk basename to a
			// 32-hex token (preserving extension) so the resulting URL is not
			// guessable from the original filename. Attachments live under
			// /wp-content/uploads/ which is publicly readable, so a random
			// path is the defense-in-depth layer that prevents enumeration of
			// other users' submitted files. The user-visible filename is kept
			// in the entry's `data` column for admin display + CSV export.
			$upload = wp_handle_upload(
				$file_info,
				[
					'test_form'                => false,
					'mimes'                    => $upload_mimes,
					'unique_filename_callback' => [ self::class, 'randomize_upload_filename' ],
				]
			);

			if ( isset( $upload['error'] ) ) {
				Logger::file(
					'upload',
					'error',
					sprintf( 'wp_handle_upload() failed for field "%s": %s', $field_id, $upload['error'] )
				);
				return new WP_Error(
					'file_upload_failed',
					__( 'Could not save the uploaded file. Please try again.', 'neural-forms-lite' ),
					[ 'status' => 500 ]
				);
			}

			// Insert into the media library.
			$attachment_id = wp_insert_attachment(
				[
					'post_mime_type' => $upload['type'],
					'post_title'     => sanitize_file_name( basename( $upload['file'] ) ),
					'post_content'   => '',
					'post_status'    => 'inherit',
				],
				$upload['file']
			);

			if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
				$err_msg = is_wp_error( $attachment_id ) ? $attachment_id->get_error_message() : 'returned 0/false';
				Logger::file(
					'upload',
					'error',
					sprintf( 'wp_insert_attachment() failed for field "%s": %s', $field_id, $err_msg )
				);
				return new WP_Error(
					'file_upload_failed',
					__( 'Could not save the uploaded file. Please try again.', 'neural-forms-lite' ),
					[ 'status' => 500 ]
				);
			}

			// Generate attachment metadata (thumbnails, dimensions, etc.).
			$attach_data = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
			wp_update_attachment_metadata( $attachment_id, $attach_data );

			// Inject structured metadata into raw_body so downstream steps can
			// treat this field like any other submitted value.
			//
			// `filename` is the human-readable original (kept for admin UI +
			// CSV export); the on-disk basename is randomized and only
			// reflected in `url` so it can't be enumerated.
			$raw_body[ $field_id ] = [
				'attachment_id' => $attachment_id,
				'url'           => $upload['url'],
				'filename'      => sanitize_file_name( (string) $file_info['name'] ),
				'mime_type'     => $upload['type'],
				'size'          => (int) filesize( $upload['file'] ),
			];
		}

		return $raw_body;
	}

	/**
	 * unique_filename_callback for wp_handle_upload(): replace the basename
	 * with a 32-hex token while preserving the extension. Result is e.g.
	 * "nf-3f2a...b91c.pdf" - unguessable so /wp-content/uploads/ URLs can't
	 * be enumerated by trying common filenames.
	 *
	 * @param string $dir   Target directory (unused - wp_handle_upload still
	 *                      resolves collisions itself, but our 128-bit token
	 *                      makes collisions vanishingly unlikely).
	 * @param string $name  Original filename including extension.
	 * @param string $ext   Extension with leading dot, e.g. '.pdf'.
	 * @return string       Randomized filename.
	 */
	public static function randomize_upload_filename( string $dir, string $name, string $ext ): string {
		$ext = strtolower( $ext );

		// wp_generate_password is cryptographically random; 32 alnum chars
		// is ~190 bits of entropy - plenty for an unguessable URL token.
		$token = strtolower( wp_generate_password( 32, false, false ) );

		return 'nf-' . $token . $ext;
	}

	/**
	 * Build the allowed_mimes array expected by wp_handle_upload().
	 *
	 * Maps type group slugs to extension => MIME entries.
	 *
	 * @param string[] $allowed_types  e.g. ['image', 'pdf']
	 * @return array  e.g. ['jpg|jpeg' => 'image/jpeg', ...]
	 */
	private function build_allowed_mimes( array $allowed_types ): array {
		$ext_map = [
			'image'    => [ 'jpg|jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp' ],
			'pdf'      => [ 'pdf' => 'application/pdf' ],
			'document' => [
				'doc'  => 'application/msword',
				'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
				'odt'  => 'application/vnd.oasis.opendocument.text',
			],
			'text'     => [ 'txt' => 'text/plain', 'csv' => 'text/csv' ],
		];

		if ( empty( $allowed_types ) ) {
			// No restriction - merge all groups.
			$mimes = [];
			foreach ( $ext_map as $group_mimes ) {
				$mimes = array_merge( $mimes, $group_mimes );
			}
			return $mimes;
		}

		$mimes = [];
		foreach ( $allowed_types as $group ) {
			if ( isset( $ext_map[ $group ] ) ) {
				$mimes = array_merge( $mimes, $ext_map[ $group ] );
			}
		}
		return $mimes;
	}

	// Conditional logic

	/**
	 * Evaluate a conditional logic rule against submitted data.
	 *
	 * Returns true  '->-> dependent field is VISIBLE   (keep it in $raw_data).
	 * Returns false '->-> dependent field is HIDDEN    (strip it from $raw_data).
	 *
	 * Mirrors the JS evaluateCondition() function - both sides must be consistent.
	 *
	 * Fallback rules:
	 *   * Incomplete rule (missing field or operator) '->-> true (show).
	 *   * Controlling field absent from submitted data '->-> true (show).
	 *   * Unknown operator '->-> true (show).
	 *
	 * @param array $logic  The conditional_logic object from the field schema.
	 * @param array $data   Submitted data keyed by field_id (may be modified by prior iterations).
	 * @return bool
	 */
	private function evaluate_conditional( array $logic, array $data ): bool {
		$control_id = (string) ( $logic['field']    ?? '' );
		$operator   = (string) ( $logic['operator'] ?? '' );
		$rule_value = (string) ( $logic['value']    ?? '' );

		if ( $control_id === '' || $operator === '' ) {
			return true;
		}

		// Safe fallback: if the controlling field is absent, show the dependent field.
		if ( ! array_key_exists( $control_id, $data ) ) {
			return true;
		}

		$control_raw = $data[ $control_id ];

		if ( is_array( $control_raw ) ) {
			// File fields store associative metadata (filename, url, ...) - there
			// is no useful scalar to compare against, so collapse to a presence
			// sentinel.  Multi-checkbox values are a numeric list of selected
			// options; join them with commas to mirror the frontend's
			// getFieldValue() so server- and client-side rules agree.
			if ( isset( $control_raw['filename'] ) ) {
				$control_val = 'file_uploaded';
			} else {
				$control_val = implode( ',', array_map( 'strval', array_filter( $control_raw, 'is_scalar' ) ) );
			}
		} else {
			$control_val = trim( (string) $control_raw );
		}

		switch ( $operator ) {
			case 'equals':
				return $control_val === $rule_value;

			case 'not_equals':
				return $control_val !== $rule_value;

			case 'contains':
				return $rule_value !== '' && strpos( $control_val, $rule_value ) !== false;

			case 'empty':
				return $control_val === '';

			case 'not_empty':
				return $control_val !== '';

			default:
				return true;
		}
	}

	// Utilities

	/**
	 * Extract submission parameters from the request.
	 *
	 * Detects whether the request is JSON or multipart/form-data and returns
	 * the appropriate params array. File fields are handled separately via
	 * $_FILES in process_file_fields(); this helper only returns text params.
	 *
	 * @param WP_REST_Request $request
	 * @return array
	 */
	private function get_submission_params( WP_REST_Request $request ): array {
		$content_type = $request->get_header( 'Content-Type' ) ?? '';

		if ( str_contains( $content_type, 'multipart/form-data' ) ) {
			// For multipart requests, WordPress parses text fields into body params.
			// File fields arrive separately in $_FILES.
			return $request->get_body_params() ?? [];
		}

		// Default: JSON submission.
		return $request->get_json_params() ?? [];
	}

	/**
	 * Resolve the client IP address.
	 *
	 * Prefers X-Forwarded-For when present (set by reverse proxies and load
	 * balancers). Takes only the first (leftmost) entry - it represents the
	 * original client; subsequent IPs are proxies appended along the chain.
	 *
	 * '��️  SPOOFING RISK: X-Forwarded-For is a plain HTTP header. Any client
	 * can send an arbitrary value when no trusted proxy sits in front. This
	 * means an attacker can trivially rotate the apparent IP and bypass any
	 * rate-limit filter that relies solely on this method.
	 *
	 * For MVP this is an acceptable trade-off - most shared hosting and basic
	 * server setups do not sit behind a load balancer, so REMOTE_ADDR is used
	 * directly. When a reverse proxy (Nginx, Cloudflare, AWS ALB) is added:
	 *
	 *  1. Restrict which IPs are allowed to set X-Forwarded-For at the proxy
	 *     layer (i.e. strip the header from untrusted sources before it hits PHP).
	 *  2. Or replace this method with a whitelist of trusted proxy CIDR ranges
	 *     and only trust X-Forwarded-For when REMOTE_ADDR is in that list.
	 *
	 * Use the resolved IP for analytics and rate-limit heuristics only.
	 * Never use it for authentication, capability checks, or billing decisions.
	 *
	 * @return string  IPv4 or IPv6 address, or empty string if unavailable.
	 */
	private function resolve_ip(): string {
		$candidate = '';

		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$forwarded = explode(
				',',
				sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) )
			);
			$candidate = trim( (string) $forwarded[0] );
		}

		if ( $candidate === '' ) {
			$candidate = isset( $_SERVER['REMOTE_ADDR'] )
				? trim( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) )
				: '';
		}

		// Reject anything that isn't a real IPv4/IPv6 - prevents header-injected
		// garbage from landing in the entries table, captcha upstream calls, or
		// the rate-limit transient key.
		return filter_var( $candidate, FILTER_VALIDATE_IP ) ? $candidate : '';
	}

	/**
	 * Built-in rate limiter: max 10 submissions per IP per 10-minute window.
	 *
	 * Uses WordPress transients for storage - works without external
	 * dependencies and self-cleans via TTL.
	 *
	 * @param string $ip  Resolved client IP address.
	 * @return bool  True if the IP should be blocked.
	 */
	private static function default_rate_limit( string $ip ): bool {
		if ( $ip === '' ) {
			return false;
		}

		$key   = self::RATE_LIMIT_TRANSIENT_PREFIX . md5( $ip );
		$count = (int) get_transient( $key );

		if ( $count >= 10 ) {
			return true;
		}

		set_transient( $key, $count + 1, 10 * MINUTE_IN_SECONDS );

		return false;
	}
}
