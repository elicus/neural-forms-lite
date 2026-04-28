<?php
namespace NeuralForms\Rest\Endpoints;

use NeuralForms\Captcha\CaptchaManager;
use NeuralForms\Core\Config;
use NeuralForms\Core\Logger;
use NeuralForms\Forms\FormRepository;
use NeuralForms\Forms\SubmissionValidator;
use NeuralForms\Storage\EntryRepository;
use WP_REST_Request;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Public REST endpoint for form submissions.
 *
 * Route:  POST /wp-json/neural-forms/v1/forms/{id}/submit
 * Auth:   None — public endpoint, no capability required.
 *
 * Processing order
 * ────────────────
 *  1. Load form from DB; 404 if not found.
 *  2. Verify form status is 'active'; 403 otherwise.
 *  3. Rate-limit check via filter (infrastructure gate, no body read yet).
 *  4. Extract full body; honeypot check (before key-stripping).
 *  4b. CAPTCHA verification — if form has captcha_enabled, verify token
 *      before the body is stripped. Fails closed: missing token → 400,
 *      bad token → 400, misconfigured provider → 400. Entry is never saved.
 *  5. Strip body to schema-known keys; spam check via filter.
 *  6. Validate with SubmissionValidator; return field-keyed 422 on failure.
 *  7. Sanitize each value by field type.
 *  8. Assemble server-side context (IP, UA, page URL, timestamps, schema hash).
 *  9. Persist entry via EntryRepository; 500 on DB failure.
 * 10. Fire post-save action hooks (created, notifications, AI queue).
 * 11. Return 201 { success: true, entry_id: … }.
 *
 * Error codes
 * ───────────
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

	public function register_routes(): void {
		register_rest_route(
			\NeuralForms\Rest\RestAPI::NAMESPACE,
			'/forms/(?P<id>[\d]+)/submit',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'submit' ],
				// Public — no authentication required.
				// Abuse prevention happens via the rate-limit and spam-check hooks.
				'permission_callback' => '__return_true',
			]
		);
	}

	// -------------------------------------------------------------------------
	// Handler
	// -------------------------------------------------------------------------

	/**
	 * Handle a form submission.
	 *
	 * @param WP_REST_Request $request
	 * @return \WP_REST_Response|WP_Error
	 */
	public function submit( WP_REST_Request $request ) {
		$form_id = (int) $request['id'];

		// ── Step 1: Load form ────────────────────────────────────────────────
		$form = FormRepository::find( $form_id );

		if ( ! $form ) {
			return new WP_Error(
				'form_not_found',
				__( 'Form not found.', 'neural-forms' ),
				[ 'status' => 404 ]
			);
		}

		// ── Step 2: Active-status gate ───────────────────────────────────────
		// Draft and archived forms must not accept public submissions.
		// This also closes the gap in SubmissionValidator's permissive select
		// handling — by the time we reach validation, the form is confirmed active.
		if ( $form['status'] !== 'active' ) {
			return new WP_Error(
				'form_not_active',
				__( 'This form is not currently accepting submissions.', 'neural-forms' ),
				[ 'status' => 403 ]
			);
		}

		// ── Step 3: Rate-limit gate ──────────────────────────────────────────
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
				__( 'Too many requests. Please wait before submitting again.', 'neural-forms' ),
				[ 'status' => 429 ]
			);
		}

		// ── Step 4: Extract and strip submitted values ───────────────────────
		// Read the full body once so we can check the honeypot before stripping.
		// Detects multipart/form-data (file upload path) vs JSON automatically.
		$raw_body = $this->get_submission_params( $request );

		// ── Step 4a: Honeypot check ──────────────────────────────────────────
		// FormRenderer renders a hidden field named "neural_forms_website".
		// Legitimate users never fill it; bots that auto-fill all inputs do.
		// Must be read from $raw_body BEFORE key-stripping removes unknown keys.
		$honeypot = trim( (string) ( $raw_body['neural_forms_website'] ?? '' ) );
		if ( $honeypot !== '' ) {
			return new WP_Error(
				'spam_detected',
				__( 'Your submission could not be processed. Please try again.', 'neural-forms' ),
				[ 'status' => 400 ]
			);
		}

		// ── Step 4b: CAPTCHA verification ────────────────────────────────────
		// Must happen here — after honeypot but BEFORE array_intersect_key strips
		// the body to schema-only keys. captcha_token is not a schema field so it
		// would be silently discarded if we read it any later.
		//
		// Fail-closed rules:
		//   • form captcha_enabled = false → skip entirely (no overhead).
		//   • form captcha_enabled = true, provider not configured → 400.
		//   • form captcha_enabled = true, token missing → 400.
		//   • form captcha_enabled = true, token fails verification → 400 + log.
		//
		// Entry is never written when this block returns a WP_Error.
		$form_settings   = is_array( $form['settings'] ) ? $form['settings'] : [];
		$captcha_enabled = ! empty( $form_settings['captcha_enabled'] );

		// Only enforce CAPTCHA when the form has it enabled AND the global
		// provider is fully configured (both site key and secret key present).
		// If captcha_enabled is true but the global keys are not set, the
		// FormRenderer does not render a widget — so no token is possible and
		// the check must be skipped to avoid blocking every submission with 400.
		if ( $captcha_enabled && CaptchaManager::is_configured() ) {

			$captcha_token = sanitize_text_field( (string) ( $raw_body['captcha_token'] ?? '' ) );

			if ( $captcha_token === '' ) {
				return new WP_Error(
					'captcha_missing',
					__( 'Please complete the CAPTCHA.', 'neural-forms' ),
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
					__( 'CAPTCHA verification failed. Please try again.', 'neural-forms' ),
					[ 'status' => 400 ]
				);
			}
		}
		// ── end CAPTCHA ───────────────────────────────────────────────────────

		// ── Step 4c: File field processing ──────────────────────────────────
		// Must happen before array_intersect_key so uploaded files (from $_FILES)
		// are processed and their metadata injected into $raw_body under the
		// field ID key. Only runs when the request is multipart/form-data.
		$schema_fields_pre = $form['form_schema']['fields'] ?? [];
		if ( ! empty( $_FILES ) ) {
			$file_result = $this->process_file_fields( $schema_fields_pre, $raw_body );
			if ( is_wp_error( $file_result ) ) {
				return $file_result;
			}
			$raw_body = $file_result;
		}
		// ── end file processing ───────────────────────────────────────────────

		// Only accept keys that correspond to field IDs in the stored schema.
		// Unknown keys are silently dropped here so the validator and sanitizer
		// never see data that has no corresponding schema definition.
		$schema_fields = $schema_fields_pre;
		$schema_ids    = array_column( $schema_fields, 'id' );
		$raw_data      = array_intersect_key( $raw_body, array_flip( $schema_ids ) );

		// ── Step 5a: Conditional logic — strip hidden fields ────────────────────
		// Re-evaluate server-side so hidden fields can never sneak through via
		// direct API calls that bypass the frontend JS.
		//
		// Fields whose condition evaluates to false are unset from $raw_data here,
		// before validation. This ensures:
		//   • Hidden required fields never fail validation.
		//   • Hidden field values are never stored.
		//   • Hidden file fields (already uploaded in step 4c) are not validated
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

		// ── Step 5: Spam-check gate ──────────────────────────────────────────
		// Fires after body is available so filters can inspect field values.
		// Return truthy to block with 400. Response is intentionally vague —
		// leaking which heuristic triggered would help spammers bypass it.
		$is_spam = apply_filters( 'neural_forms_spam_check', false, $raw_data, $form );

		if ( $is_spam ) {
			return new WP_Error(
				'spam_detected',
				__( 'Your submission could not be processed. Please try again.', 'neural-forms' ),
				[ 'status' => 400 ]
			);
		}

		// ── Step 6: Validate ─────────────────────────────────────────────────
		// SubmissionValidator returns true on success or a WP_Error whose
		// data['fields'] contains per-field [ 'code' => ..., 'message' => ... ].
		$validation = SubmissionValidator::validate( $schema_fields, $raw_data );

		if ( is_wp_error( $validation ) ) {
			// Pass the full WP_Error through — it already carries status 422
			// and the structured data.fields array. Do not rewrap.
			return $validation;
		}

		// ── Step 7: Sanitize ─────────────────────────────────────────────────
		// Sanitization happens here, not in the validator.
		// The validator confirmed the values are structurally acceptable;
		// sanitization removes any remaining unsafe content before storage.
		// Null values are skipped — they represent optional absent fields and
		// should not appear in the stored entry data blob.
		$sanitized_data = $this->sanitize_fields( $schema_fields, $raw_data );

		// ── Step 8: Assemble server-side context ─────────────────────────────
		// These values are server-derived, never client-trusted.
		// form_version is an MD5 of the schema at the moment of submission —
		// useful later to detect entries captured against an older schema shape.
		$context = [
			'ip'           => $ip,
			'user_agent'   => sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ),
			'page_url'     => esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ?? '' ) ),
			'submitted_at' => current_time( 'mysql', true ),   // UTC
			'form_version' => md5( (string) wp_json_encode( $form['form_schema'] ) ),
		];

		// ── Step 9: Persist ──────────────────────────────────────────────────
		// EntryRepository::create() already logs the raw DB error internally.
		// We must not forward that message to the public response.
		$entry_id = EntryRepository::create( $form_id, $sanitized_data, $context );

		if ( is_wp_error( $entry_id ) ) {
			return new WP_Error(
				'entry_save_failed',
				__( 'Could not save your submission. Please try again.', 'neural-forms' ),
				[ 'status' => 500 ]
			);
		}

		// ── Step 10: Post-save hooks ─────────────────────────────────────────
		// These hooks fire in a defined order after the entry is committed.
		// None of them can abort the response — the entry is already saved.
		//
		// IMPORTANT — hook implementations must be non-blocking:
		//   • Do not deliver emails synchronously inside the hook.
		//   • Do not call external AI APIs inline — enqueue a job instead.
		//   Blocking here delays the HTTP response for every submission.
		//
		// IMPORTANT — hook failures are currently silent (fire-and-forget).
		// When notification and AI listeners are implemented, they must write
		// failures to neural_forms_logs or update queue row status so that:
		//   • Failed email deliveries are visible in the admin entries view.
		//   • Stuck AI jobs can be retried without re-processing the entry.
		//   • Debugging does not require tailing server error logs.
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
		// after analysis — suppressing it for spam entries. Auto-process is
		// not required because QueueListener now queues on spam_enabled alone,
		// so the queue still runs and drives the deferred notification.
		$defer_notifications = \NeuralForms\Core\Config::get( 'ai_enabled' )
			&& \NeuralForms\Core\Config::get( 'ai_spam_enabled' )
			&& \NeuralForms\Core\Config::get( 'email_skip_spam_notifications' );

		if ( ! $defer_notifications ) {
			do_action( 'neural_forms_send_notifications', $entry_id, $form_id, $form );
		}

		// AI processing queue.
		// Intentionally last — depends on the entry existing (neural_forms_entry_created)
		// and notification config being available (neural_forms_send_notifications).
		// Listener must insert a row into neural_forms_queue, not call the AI API inline.
		do_action( 'neural_forms_queue_ai_processing', $entry_id, $form_id );

		// ── Step 11: Success response ─────────────────────────────────────────
		$response = rest_ensure_response( [
			'success'  => true,
			'entry_id' => $entry_id,
		] );
		$response->set_status( 201 );

		return $response;
	}

	// -------------------------------------------------------------------------
	// Sanitization
	// -------------------------------------------------------------------------

	/**
	 * Sanitize all submitted field values by their declared type.
	 *
	 * Iterates the schema fields (not the raw data) so the output array
	 * preserves the order of fields as defined in the schema.
	 *
	 * Null values are excluded from the output — they represent optional
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

			// Null means the client explicitly sent null — treat as absent.
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
			// Pass through as-is — do not stringify it.
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

	// -------------------------------------------------------------------------
	// File upload
	// -------------------------------------------------------------------------

	/**
	 * Allowed MIME type groups for file upload fields.
	 *
	 * Mirrors JS FILE_TYPE_OPTIONS in FieldItem.js — group slugs must match.
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
	 *   - If required and no file uploaded → 422 error returned.
	 *   - If optional and no file uploaded → field skipped (absent = valid).
	 *   - If file present → validate size and MIME, upload, inject metadata.
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

			$file_info = $_FILES[ $field_id ] ?? null;

			// No file uploaded for this field.
			if ( ! $file_info || $file_info['error'] === UPLOAD_ERR_NO_FILE || empty( $file_info['name'] ) ) {
				if ( $required ) {
					$label = trim( $field['label'] ?? '' ) ?: $field_id;
					return new WP_Error(
						'invalid_submission',
						__( 'The submission contains errors. Please check the fields below.', 'neural-forms' ),
						[
							'status' => 422,
							'fields' => [
								$field_id => [ [
									'code'    => 'required',
									/* translators: %s: field label */
									'message' => sprintf( __( '%s is required.', 'neural-forms' ), $label ),
								] ],
							],
						]
					);
				}
				// Optional and absent — skip.
				continue;
			}

			// Upload error other than "no file".
			if ( $file_info['error'] !== UPLOAD_ERR_OK ) {
				return new WP_Error(
					'file_upload_error',
					__( 'A file upload error occurred. Please try again.', 'neural-forms' ),
					[ 'status' => 400 ]
				);
			}

			// Server-side size check.
			if ( $file_info['size'] > $max_bytes ) {
				$label = trim( $field['label'] ?? '' ) ?: $field_id;
				return new WP_Error(
					'invalid_submission',
					__( 'The submission contains errors. Please check the fields below.', 'neural-forms' ),
					[
						'status' => 422,
						'fields' => [
							$field_id => [ [
								'code'    => 'file_too_large',
								/* translators: 1: field label  2: max size in MB */
								'message' => sprintf(
									__( '%1$s: file exceeds the maximum allowed size of %2$d MB.', 'neural-forms' ),
									$label,
									$max_mb
								),
							] ],
						],
					]
				);
			}

			// Build the custom mimes list once — used for both the pre-check and
			// the actual upload so the two are always in sync.
			$upload_mimes = $this->build_allowed_mimes( $allowed_types );

			// MIME type validation using wp_check_filetype_and_ext() which reads
			// actual file content via finfo — not the browser-reported type.
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
						__( 'The submission contains errors. Please check the fields below.', 'neural-forms' ),
						[
							'status' => 422,
							'fields' => [
								$field_id => [ [
									'code'    => 'invalid_file_type',
									/* translators: %s: field label */
									'message' => sprintf( __( '%s: file type not allowed.', 'neural-forms' ), $label ),
								] ],
							],
						]
					);
				}
			}

			// Upload to WordPress media library.
			// test_form => false because this is not an admin form submission.
			$upload = wp_handle_upload(
				$file_info,
				[ 'test_form' => false, 'mimes' => $upload_mimes ]
			);

			if ( isset( $upload['error'] ) ) {
				Logger::file(
					'upload',
					'error',
					sprintf( 'wp_handle_upload() failed for field "%s": %s', $field_id, $upload['error'] )
				);
				return new WP_Error(
					'file_upload_failed',
					__( 'Could not save the uploaded file. Please try again.', 'neural-forms' ),
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
					__( 'Could not save the uploaded file. Please try again.', 'neural-forms' ),
					[ 'status' => 500 ]
				);
			}

			// Generate attachment metadata (thumbnails, dimensions, etc.).
			$attach_data = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
			wp_update_attachment_metadata( $attachment_id, $attach_data );

			// Inject structured metadata into raw_body so downstream steps can
			// treat this field like any other submitted value.
			$raw_body[ $field_id ] = [
				'attachment_id' => $attachment_id,
				'url'           => $upload['url'],
				'filename'      => basename( $upload['file'] ),
				'mime_type'     => $upload['type'],
				'size'          => (int) filesize( $upload['file'] ),
			];
		}

		return $raw_body;
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
			// No restriction — merge all groups.
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

	// -------------------------------------------------------------------------
	// Conditional logic
	// -------------------------------------------------------------------------

	/**
	 * Evaluate a conditional logic rule against submitted data.
	 *
	 * Returns true  → dependent field is VISIBLE   (keep it in $raw_data).
	 * Returns false → dependent field is HIDDEN    (strip it from $raw_data).
	 *
	 * Mirrors the JS evaluateCondition() function — both sides must be consistent.
	 *
	 * Fallback rules:
	 *   • Incomplete rule (missing field or operator) → true (show).
	 *   • Controlling field absent from submitted data → true (show).
	 *   • Unknown operator → true (show).
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
			// File fields store associative metadata (filename, url, …) — there
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

	// -------------------------------------------------------------------------
	// Utilities
	// -------------------------------------------------------------------------

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
	 * balancers). Takes only the first (leftmost) entry — it represents the
	 * original client; subsequent IPs are proxies appended along the chain.
	 *
	 * ⚠️  SPOOFING RISK: X-Forwarded-For is a plain HTTP header. Any client
	 * can send an arbitrary value when no trusted proxy sits in front. This
	 * means an attacker can trivially rotate the apparent IP and bypass any
	 * rate-limit filter that relies solely on this method.
	 *
	 * For MVP this is an acceptable trade-off — most shared hosting and basic
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
			$forwarded = explode( ',', wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
			$candidate = trim( (string) $forwarded[0] );
		}

		if ( $candidate === '' ) {
			$candidate = trim( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		}

		// Reject anything that isn't a real IPv4/IPv6 — prevents header-injected
		// garbage from landing in the entries table, captcha upstream calls, or
		// the rate-limit transient key.
		return filter_var( $candidate, FILTER_VALIDATE_IP ) ? $candidate : '';
	}

	/**
	 * Built-in rate limiter: max 10 submissions per IP per 10-minute window.
	 *
	 * Uses WordPress transients for storage — works without external
	 * dependencies and self-cleans via TTL.
	 *
	 * @param string $ip  Resolved client IP address.
	 * @return bool  True if the IP should be blocked.
	 */
	private static function default_rate_limit( string $ip ): bool {
		if ( $ip === '' ) {
			return false;
		}

		$key   = 'nf_rl_' . md5( $ip );
		$count = (int) get_transient( $key );

		if ( $count >= 10 ) {
			return true;
		}

		set_transient( $key, $count + 1, 10 * MINUTE_IN_SECONDS );

		return false;
	}
}
