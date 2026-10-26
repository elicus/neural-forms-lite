<?php
namespace NeuralForms\Notifications;

use NeuralForms\Core\Config;
use NeuralForms\Core\Logger;
use NeuralForms\Forms\MatrixFormatter;
use NeuralForms\Forms\RepeaterFormatter;
use NeuralForms\Forms\SchemaHelper;
use NeuralForms\Storage\EntryRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Sends admin notifications and autoresponder emails after a form submission.
 *
 * Hooks into the `neural_forms_send_notifications` action fired by SubmitEndpoint
 * after the entry is committed to the database (step 10 of the submission flow).
 *
 * Design principles:
 *   * Non-blocking - the entry is already saved before this runs. A mail
 *     failure never aborts the submission response.
 *   * All outcomes (success and failure) are written to neural_forms_logs so they
 *     are visible in the admin without tailing server error logs.
 *   * Sender name/email from Email Settings are applied when set.
 *   * Autoresponder tokens: {site_name}, {form_name}, plus any {field_id}
 *     that exists in the submitted data.
 */
class NotificationService {

	// Boot

	public static function boot(): void {
		add_action( 'neural_forms_send_notifications', array( self::class, 'handle' ), 10, 3 );
	}

	// Dispatch

	/**
	 * Central dispatcher called by `neural_forms_send_notifications`.
	 *
	 * @param int   $entry_id
	 * @param int   $form_id
	 * @param array $form     Full form row including form_schema and name.
	 */
	public static function handle( int $entry_id, int $form_id, array $form ): void {
		$settings = Config::all();
		$entry    = EntryRepository::get( $entry_id );

		if ( ! $entry ) {
			return;
		}

		// Flatten rows + columns. Notifications only care about leaf fields:
		// the email summary and the autoresponder email lookup both walk the
		// resulting flat list.
		$schema_fields = SchemaHelper::flatten_fields(
			is_array( $form['form_schema']['fields'] ?? null ) ? $form['form_schema']['fields'] : array()
		);
		$data          = $entry['data'] ?? array();

		if ( ! empty( $settings['enable_admin_notification'] ) ) {
			self::send_admin_notification( $entry_id, $form, $schema_fields, $data, $settings );
		}

		if ( ! empty( $settings['enable_autoresponder'] ) ) {
			self::send_autoresponder( $entry_id, $form, $schema_fields, $data, $settings );
		}
	}

	// Admin notification

	/**
	 * Sends the "new submission" notification to the configured admin address.
	 */
	private static function send_admin_notification(
		int $entry_id,
		array $form,
		array $schema_fields,
		array $data,
		array $settings
	): void {
		$form_name = (string) ( $form['name'] ?? 'Unknown form' );

		// Recipient resolution order:
		// 1. Per-form admin_notification_email in form_schema.settings
		//    (the React Form Settings panel writes here)
		// 2. Per-form notification_email in form.settings (legacy
		//    shape — kept for back-compat with any old callers that
		//    set the flat key directly)
		// 3. Global admin_notification_email (plugin settings)
		// 4. WordPress admin email
		//
		// Each value may be a single email OR a comma-separated list.
		// parse_recipient_list() splits, sanitises, and drops invalid
		// entries. We stop at the first source that yields at least
		// one valid address.
		$candidates = array(
			(string) ( $form['form_schema']['settings']['admin_notification_email'] ?? '' ),
			(string) ( $form['settings']['notification_email'] ?? '' ),
			(string) ( $settings['admin_notification_email'] ?? '' ),
			(string) get_option( 'admin_email' ),
		);

		$recipients = array();
		foreach ( $candidates as $raw ) {
			$recipients = self::parse_recipient_list( $raw );
			if ( ! empty( $recipients ) ) {
				break;
			}
		}

		if ( empty( $recipients ) ) {
			Logger::log(
				'admin_notification_skipped',
				'warning',
				'Admin notification skipped: no valid recipient email address.',
				array(),
				$entry_id
			);
			return;
		}

		$to = $recipients;

		/* translators: %s: form name */
		$subject = sprintf( __( 'New submission: %s', 'neural-forms-lite' ), $form_name );

		/* translators: %s: form name */
		$body  = sprintf( __( 'You received a new submission from "%s".', 'neural-forms-lite' ), $form_name ) . "\n\n";
		$body .= self::build_fields_summary( $schema_fields, $data );
		$body .= "\n";
		$body .= sprintf(
			/* translators: %s: link to the Neural Forms entries screen */
			__( 'View entry: %s', 'neural-forms-lite' ),
			self::get_entry_url( $entry_id, (int) ( $form['id'] ?? 0 ) )
		) . "\n";

		$headers = self::build_from_headers( $settings );

		// Reply-To: when the submission carries a valid email field,
		// the admin should be able to hit "Reply" in their mail
		// client and reply directly to the user — not to themselves.
		// We never set From to the user (SPF/DKIM/DMARC would reject
		// most outbound mail); only Reply-To is rewritten.
		//
		// Resolution priority:
		// 1. Explicit per-form override   $form['settings']['reply_to_email']
		// 2. Explicit global override     $settings['admin_reply_to_email']
		// 3. Submitter email field        find_submitter_email()
		// 4. None                         (no Reply-To header)
		//
		// The neural_forms_admin_reply_to filter lets integrations
		// override or veto the auto Reply-To without changing settings.
		$reply_to_email = '';
		$reply_to_name  = '';

		$override = (string) ( $form['settings']['reply_to_email'] ?? '' );
		if ( $override === '' ) {
			$override = (string) ( $settings['admin_reply_to_email'] ?? '' );
		}

		if ( $override !== '' && is_email( $override ) ) {
			$reply_to_email = $override;
		} else {
			$reply_to_email = self::find_submitter_email( $schema_fields, $data );
			$reply_to_name  = self::find_submitter_name( $schema_fields, $data );
		}

		/**
		 * Filter the Reply-To pair before it's serialized into a header.
		 *
		 * @param array $reply_to  [ 'email' => string, 'name' => string ]
		 * @param array $form
		 * @param array $data
		 * @param int   $entry_id
		 */
		$filtered       = apply_filters(
			'neural_forms_admin_reply_to',
			array(
				'email' => $reply_to_email,
				'name'  => $reply_to_name,
			),
			$form,
			$data,
			$entry_id
		);
		$reply_to_email = is_array( $filtered ) ? (string) ( $filtered['email'] ?? '' ) : '';
		$reply_to_name  = is_array( $filtered ) ? (string) ( $filtered['name'] ?? '' ) : '';

		$reply_to_header = self::build_reply_to_header( $reply_to_email, $reply_to_name );
		if ( $reply_to_header !== '' ) {
			$headers[] = $reply_to_header;
		}

		// `$to` is an array since 1.1.0+ (comma-separated lists). The
		// human-readable join is used in log messages; the structured
		// `to` key keeps the array shape for any downstream consumer
		// that parses log payloads.
		$to_display = is_array( $to ) ? implode( ', ', $to ) : (string) $to;
		$sent       = self::send_with_retry( $to, $subject, $body, $headers );

		if ( $sent ) {
			Logger::log(
				'admin_notification_sent',
				'info',
				sprintf( 'Admin notification sent to %s.', $to_display ),
				array(
					'to'       => $to,
					'reply_to' => $reply_to_email,
				),
				$entry_id
			);
		} else {
			Logger::log(
				'admin_notification_failed',
				'error',
				sprintf( 'Admin notification failed for %s - wp_mail() returned false after retry.', $to_display ),
				array( 'to' => $to ),
				$entry_id
			);
		}
	}

	// Autoresponder

	/**
	 * Sends a confirmation email to the submitter, if a valid email field exists.
	 */
	private static function send_autoresponder(
		int $entry_id,
		array $form,
		array $schema_fields,
		array $data,
		array $settings
	): void {
		$to = self::find_submitter_email( $schema_fields, $data );

		if ( $to === '' ) {
			Logger::log(
				'autoresponder_skipped',
				'info',
				'Autoresponder skipped: no valid email field found in submission.',
				array(),
				$entry_id
			);
			return;
		}

		$form_name = (string) ( $form['name'] ?? '' );

		$subject = (string) ( $settings['autoresponder_subject'] ?? '' );
		if ( $subject === '' ) {
			$subject = __( 'Thank you for your submission', 'neural-forms-lite' );
		}

		$raw_body = (string) ( $settings['autoresponder_body'] ?? '' );
		if ( $raw_body === '' ) {
			$raw_body = __( "Hi,\n\nThank you for reaching out. We'll be in touch shortly.\n\n{site_name}", 'neural-forms-lite' );
		}

		$body = self::apply_tokens( $raw_body, $form_name, $data );

		$headers = self::build_from_headers( $settings );

		$sent = self::send_with_retry( $to, $subject, $body, $headers );

		if ( $sent ) {
			Logger::log(
				'autoresponder_sent',
				'info',
				sprintf( 'Autoresponder sent to %s.', $to ),
				array( 'to' => $to ),
				$entry_id
			);
		} else {
			Logger::log(
				'autoresponder_failed',
				'error',
				sprintf( 'Autoresponder failed for %s - wp_mail() returned false after retry.', $to ),
				array( 'to' => $to ),
				$entry_id
			);
		}
	}

	// Helpers

	/**
	 * Scan schema fields for the first email-type field that has a valid value
	 * in the submitted data.
	 *
	 * Falls back to scanning all string values with is_email() so forms that
	 * store an email in a text field still trigger the autoresponder.
	 *
	 * @param array $schema_fields  Fields array from form_schema.
	 * @param array $data           Sanitized submitted field values.
	 * @return string  Valid email address or empty string if none found.
	 */
	/**
	 * Best-effort submitter display name for the Reply-To header.
	 *
	 * Resolution order:
	 *   A. Field id/name contains "name"  (e.g. id="name", id="full_name")
	 *   B. Field label contains "name"   (case-insensitive)
	 *   C. First text/textarea field with a non-empty value
	 *
	 * Returns an empty string when nothing usable is found — Reply-To
	 * will then be the bare email address with no display name.
	 *
	 * Output is sanitized: HTML stripped, control chars removed,
	 * angle brackets / commas / CR/LF stripped (so a hostile name
	 * cannot inject an extra header line), capped at 120 chars.
	 *
	 * @param array $schema_fields  Flattened schema fields.
	 * @param array $data           Sanitized submitted values.
	 * @return string
	 */
	private static function find_submitter_name( array $schema_fields, array $data ): string {
		$candidates = array();

		// Pass A: id/name contains "name".
		foreach ( $schema_fields as $field ) {
			$field    = (array) $field;
			$type     = $field['type'] ?? '';
			$field_id = (string) ( $field['id'] ?? '' );
			if ( $field_id === '' ) {
				continue;
			}
			if ( ! in_array( $type, array( 'text', 'textarea', 'hidden' ), true ) ) {
				continue;
			}
			if ( stripos( $field_id, 'name' ) === false ) {
				continue;
			}
			$value = (string) ( $data[ $field_id ] ?? '' );
			if ( $value !== '' ) {
				$candidates[] = $value;
				break; }
		}

		// Pass B: label contains "name".
		if ( empty( $candidates ) ) {
			foreach ( $schema_fields as $field ) {
				$field    = (array) $field;
				$type     = $field['type'] ?? '';
				$field_id = (string) ( $field['id'] ?? '' );
				$label    = (string) ( $field['label'] ?? '' );
				if ( $field_id === '' || $label === '' ) {
					continue;
				}
				if ( ! in_array( $type, array( 'text', 'textarea', 'hidden' ), true ) ) {
					continue;
				}
				if ( stripos( $label, 'name' ) === false ) {
					continue;
				}
				$value = (string) ( $data[ $field_id ] ?? '' );
				if ( $value !== '' ) {
					$candidates[] = $value;
					break; }
			}
		}

		// Pass C: first non-empty text/textarea value as a final fallback.
		if ( empty( $candidates ) ) {
			foreach ( $schema_fields as $field ) {
				$field    = (array) $field;
				$type     = $field['type'] ?? '';
				$field_id = (string) ( $field['id'] ?? '' );
				if ( $field_id === '' ) {
					continue;
				}
				if ( ! in_array( $type, array( 'text', 'textarea' ), true ) ) {
					continue;
				}
				$value = (string) ( $data[ $field_id ] ?? '' );
				if ( $value !== '' ) {
					$candidates[] = $value;
					break; }
			}
		}

		if ( empty( $candidates ) ) {
			return '';
		}

		$name = (string) $candidates[0];
		$name = wp_strip_all_tags( $name );
		$name = sanitize_text_field( $name );
		// Defense-in-depth header-injection guards: drop any CR / LF /
		// NULL plus the structural characters wp_mail header parsers
		// use (`,`, `<`, `>`, `"`).
		$name = str_replace( array( "\r", "\n", "\0", ',', '<', '>', '"' ), '', $name );
		$name = trim( $name );
		if ( strlen( $name ) > 120 ) {
			$name = substr( $name, 0, 120 );
		}
		return $name;
	}

	/**
	 * Build a Reply-To header line, or empty string if the email
	 * isn't usable. Output is safe to append to wp_mail()'s $headers
	 * array. CRLF / NULL / structural chars are stripped from both
	 * the email and the optional display name to prevent header
	 * injection.
	 */
	private static function build_reply_to_header( string $email, string $name ): string {
		$email = trim( str_replace( array( "\r", "\n", "\0" ), '', sanitize_email( $email ) ) );
		if ( $email === '' || ! is_email( $email ) ) {
			return '';
		}
		$name = str_replace( array( "\r", "\n", "\0", ',', '<', '>', '"' ), '', $name );
		$name = trim( $name );
		if ( $name === '' ) {
			return 'Reply-To: ' . $email;
		}
		return sprintf( 'Reply-To: %s <%s>', $name, $email );
	}

	private static function find_submitter_email( array $schema_fields, array $data ): string {
		// Pass 1: typed email fields - most reliable.
		// Scans the schema for fields declared as type='email' and returns the
		// first one that contains a valid email in the submitted data.
		foreach ( $schema_fields as $field ) {
			$field = (array) $field;
			if ( ( $field['type'] ?? '' ) !== 'email' ) {
				continue;
			}
			$field_id = $field['id'] ?? '';
			$value    = (string) ( $data[ $field_id ] ?? '' );
			if ( $value !== '' && is_email( $value ) ) {
				return $value;
			}
		}

		// Pass 2: schema-ordered scan of all text-like fields.
		// Walk fields in schema order (not arbitrary $data key order) so the
		// result is predictable and repeatable - the first text/textarea/url
		// field whose submitted value passes is_email() wins.
		foreach ( $schema_fields as $field ) {
			$field    = (array) $field;
			$type     = $field['type'] ?? '';
			$field_id = $field['id'] ?? '';

			// Only consider fields that could plausibly hold an email address.
			if ( ! in_array( $type, array( 'text', 'textarea', 'url', 'hidden' ), true ) ) {
				continue;
			}

			$value = $data[ $field_id ] ?? null;
			if ( is_string( $value ) && is_email( $value ) ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Build a plain-text summary of submitted field values.
	 *
	 * Uses schema field labels as keys. Falls back to the field ID when a
	 * label is absent. Checkbox 0/1 integers are rendered as "No" / "Yes".
	 *
	 * @param array $schema_fields
	 * @param array $data
	 * @return string  Multi-line text block, newline-terminated.
	 */
	private static function build_fields_summary( array $schema_fields, array $data ): string {
		$lines = array();

		// Precompute humanized fallback labels for any field whose schema
		// label is empty or looks like the AI generator's auto-slug
		// (e.g. `text_mpv1gytfc`), so the email never ships raw slugs.
		$fallbacks = self::build_field_label_fallbacks( $schema_fields );

		foreach ( $schema_fields as $field ) {
			$field    = (array) $field;
			$field_id = $field['id'] ?? '';

			if ( $field_id === '' || ! array_key_exists( $field_id, $data ) ) {
				continue;
			}

			$raw_label = isset( $field['label'] ) ? trim( (string) $field['label'] ) : '';
			$label     = self::field_label_is_meaningful( $raw_label, (string) $field_id )
				? $raw_label
				: ( $fallbacks[ (string) $field_id ] ?? (string) $field_id );
			$raw       = $data[ $field_id ];

			if ( $field['type'] === 'checkbox' ) {
				// Multi-option checkboxes submit an array of selected values;
				// single-option checkboxes submit a 0/1 scalar.
				if ( is_array( $raw ) ) {
					$value = implode( ', ', array_map( 'strval', $raw ) );
				} else {
					$value = $raw ? __( 'Yes', 'neural-forms-lite' ) : __( 'No', 'neural-forms-lite' );
				}
			} elseif ( $field['type'] === 'file' ) {
				// File fields store a metadata array - display the filename if available.
				$value = is_array( $raw ) ? (string) ( $raw['filename'] ?? 'file' ) : '';
			} elseif ( $field['type'] === 'repeater' ) {
				// Repeater renders as a labelled block with one nested
				// list per submitted iteration:
				//
				// Team Members:
				// Item 1:
				// - Name: Arif
				// - Role: Developer
				// Item 2:
				// - Name: Sara
				// - Role: Designer
				$items    = RepeaterFormatter::format_value_lines( $raw, $field );
				$rendered = array();
				foreach ( $items as $item ) {
					$child_lines = array();
					foreach ( $item['child_lines'] as $cl ) {
						if ( $cl['display'] === '' ) {
							continue;
						}
						$child_lines[] = '    - ' . $cl['child_label'] . ': ' . $cl['display'];
					}
					if ( empty( $child_lines ) ) {
						continue;
					}
					$rendered[] = '  ' . sprintf(
						/* translators: %d: 1-based iteration number */
						__( 'Item %d:', 'neural-forms-lite' ),
						$item['item_index'] + 1
					) . "\n" . implode( "\n", $child_lines );
				}
				if ( empty( $rendered ) ) {
					$lines[] = $label . ':';
				} else {
					$lines[] = $label . ":\n" . implode( "\n", $rendered );
				}
				continue;
			} elseif ( $field['type'] === 'matrix' ) {
				// Matrix renders one line per configured row, indented under
				// the field label so the visual hierarchy mirrors how a
				// respondent answered the survey:
				//
				// Satisfaction:
				// - Purchase: Very Satisfied
				// - Service:  Satisfied
				$matrix_lines = MatrixFormatter::format_value_lines( $raw, $field );
				$rendered     = array();
				foreach ( $matrix_lines as $ml ) {
					if ( $ml['display'] === '' ) {
						continue;
					}
					$rendered[] = '  - ' . $ml['row_label'] . ': ' . $ml['display'];
				}
				if ( empty( $rendered ) ) {
					$lines[] = $label . ':';
				} else {
					$lines[] = $label . ":\n" . implode( "\n", $rendered );
				}
				continue;
			} elseif ( is_array( $raw ) ) {
				// Generic fallback for any other multi-value field type
				// (select-multi, etc.) - join scalar leaves with commas.
				$value = implode( ', ', array_map( 'strval', array_filter( $raw, 'is_scalar' ) ) );
			} else {
				$value = (string) $raw;
			}

			$lines[] = $label . ': ' . $value;
		}

		return $lines ? implode( "\n", $lines ) . "\n" : '';
	}

	/**
	 * Human-readable fallback names per field type — mirrors the JS
	 * FIELD_TYPE_LABELS in modules/admin/src/entries/helpers.js. Kept
	 * in sync manually; the two lists must stay identical.
	 *
	 * @return array<string,string>
	 */
	private static function field_type_labels(): array {
		return array(
			'text'          => __( 'Text field',    'neural-forms-lite' ),
			'textarea'      => __( 'Message',       'neural-forms-lite' ),
			'email'         => __( 'Email address', 'neural-forms-lite' ),
			'phone'         => __( 'Phone number',  'neural-forms-lite' ),
			'number'        => __( 'Number',        'neural-forms-lite' ),
			'url'           => __( 'URL',           'neural-forms-lite' ),
			'select'        => __( 'Selection',     'neural-forms-lite' ),
			'radio'         => __( 'Choice',        'neural-forms-lite' ),
			'checkbox'      => __( 'Checkbox',      'neural-forms-lite' ),
			'checkboxes'    => __( 'Choices',       'neural-forms-lite' ),
			'date'          => __( 'Date',          'neural-forms-lite' ),
			'file'          => __( 'File upload',   'neural-forms-lite' ),
			'rating'        => __( 'Rating',        'neural-forms-lite' ),
			'acceptance'    => __( 'Consent',       'neural-forms-lite' ),
			'poll'          => __( 'Poll',          'neural-forms-lite' ),
			'quiz_question' => __( 'Quiz question', 'neural-forms-lite' ),
			'matrix'        => __( 'Matrix',        'neural-forms-lite' ),
			'repeater'      => __( 'Group',         'neural-forms-lite' ),
			'hidden'        => __( 'Hidden field',  'neural-forms-lite' ),
		);
	}

	/** Detect labels shaped like the AI generator's auto-slug (e.g. `text_mpv1gytfc`). */
	private static function looks_like_auto_slug( string $s ): bool {
		return (bool) preg_match( '/^[a-z]+(?:_[a-z0-9]+){1,}$/', $s );
	}

	/**
	 * True when a schema `label` conveys something beyond the field id or
	 * an auto-generated slug (i.e. worth showing verbatim in an email).
	 */
	private static function field_label_is_meaningful( string $label, string $field_id ): bool {
		if ( $label === '' ) {
			return false;
		}
		if ( $label === $field_id ) {
			return false;
		}
		if ( self::looks_like_auto_slug( $label ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Build a `{ field_id => human fallback label }` map for schemas whose
	 * `label` is empty or slug-shaped. Fields with meaningful labels are
	 * absent from the map. When a type has 2+ fallbacks a "1", "2", …
	 * counter is appended so the reader can tell them apart.
	 *
	 * @param array $schema_fields
	 * @return array<string,string>
	 */
	private static function build_field_label_fallbacks( array $schema_fields ): array {
		$type_labels  = self::field_type_labels();
		$type_totals  = array();
		$needs        = array();

		foreach ( $schema_fields as $field ) {
			$field    = (array) $field;
			$field_id = isset( $field['id'] ) ? (string) $field['id'] : '';
			if ( $field_id === '' ) {
				continue;
			}
			$raw_label = isset( $field['label'] ) ? trim( (string) $field['label'] ) : '';
			if ( self::field_label_is_meaningful( $raw_label, $field_id ) ) {
				continue;
			}
			$type              = isset( $field['type'] ) ? (string) $field['type'] : 'field';
			$needs[]           = array( $field_id, $type );
			$type_totals[ $type ] = ( $type_totals[ $type ] ?? 0 ) + 1;
		}

		$fallbacks     = array();
		$type_counters = array();
		foreach ( $needs as list( $field_id, $type ) ) {
			$human = $type_labels[ $type ] ?? __( 'Field', 'neural-forms-lite' );
			if ( ( $type_totals[ $type ] ?? 0 ) > 1 ) {
				$type_counters[ $type ]   = ( $type_counters[ $type ] ?? 0 ) + 1;
				$fallbacks[ $field_id ] = $human . ' ' . $type_counters[ $type ];
			} else {
				$fallbacks[ $field_id ] = $human;
			}
		}
		return $fallbacks;
	}

	/**
	 * Replace supported tokens in an autoresponder body template.
	 *
	 * Supported tokens:
	 *   {site_name}  - WordPress site/blog name
	 *   {form_name}  - the form's human-readable name
	 *   {field_id}   - any key from the submitted data (case-sensitive)
	 *
	 * Unknown tokens are left in place so the admin can see a mis-typed
	 * token rather than having it silently disappear.
	 *
	 * @param string $template
	 * @param string $form_name
	 * @param array  $data       Sanitized submitted field values.
	 * @return string
	 */
	private static function apply_tokens( string $template, string $form_name, array $data ): string {
		$replacements = array(
			'{site_name}' => get_bloginfo( 'name' ),
			'{form_name}' => $form_name,
		);

		foreach ( $data as $field_id => $value ) {
			if ( is_scalar( $value ) ) {
				$replacements[ '{' . $field_id . '}' ] = (string) $value;
			}
		}

		return str_replace( array_keys( $replacements ), array_values( $replacements ), $template );
	}

	/**
	 * Resolve the sender identity from Email Settings, applying the
	 * defaults the Settings screen promises:
	 *
	 *   sender_name  blank → site title (get_bloginfo 'name')
	 *   sender_email blank / invalid → WordPress admin email
	 *
	 * Used by every outbound mail (admin notification, autoresponder,
	 * reply, test email) so they all carry the same From identity.
	 *
	 * Deliverability note: the admin email may live on another domain
	 * (e.g. a Gmail address), which some receivers treat less kindly than
	 * WordPress's wordpress@<site-domain> default. Site owners who hit
	 * that should set an on-domain Sender email explicitly.
	 *
	 * @param array $settings  Plugin settings (sender_name / sender_email).
	 * @return array{ name: string, email: string }  email is '' only when
	 *         neither the setting nor admin_email is a valid address.
	 */
	public static function resolve_sender( array $settings ): array {
		$name  = trim( (string) ( $settings['sender_name'] ?? '' ) );
		$email = trim( (string) ( $settings['sender_email'] ?? '' ) );

		if ( $name === '' ) {
			$name = (string) get_bloginfo( 'name' );
			if ( function_exists( 'wp_specialchars_decode' ) ) {
				$name = wp_specialchars_decode( $name, ENT_QUOTES );
			}
		}
		// Same header-injection guard as the Reply-To builders below: drop
		// CR / LF / NULL and the structural characters wp_mail's header
		// parser uses (`,`, `<`, `>`, `"`).
		$name = trim( str_replace( array( "\r", "\n", "\0", ',', '<', '>', '"' ), '', $name ) );

		if ( $email === '' || ! is_email( $email ) ) {
			$email = (string) get_option( 'admin_email', '' );
			if ( $email === '' || ! is_email( $email ) ) {
				$email = '';
			}
		}

		return array( 'name' => $name, 'email' => $email );
	}

	/**
	 * Build the From: header array from Email Settings (with the defaults
	 * described in resolve_sender()).
	 *
	 * Returns an empty array only when no valid address can be resolved
	 * at all - wp_mail() then uses its own wordpress@<domain> default.
	 *
	 * @param array $settings
	 * @return string[]
	 */
	public static function build_from_headers( array $settings ): array {
		$sender = self::resolve_sender( $settings );

		if ( $sender['email'] === '' ) {
			return array();
		}

		$from = $sender['name'] !== ''
			? sprintf( '%s <%s>', $sender['name'], $sender['email'] )
			: $sender['email'];

		return array( 'From: ' . $from );
	}

	/**
	 * Try wp_mail() once, and if it fails, retry once after a 1-second pause.
	 *
	 * Transient SMTP connection failures (server busy, greylisting, etc.)
	 * often succeed on immediate retry. A single retry keeps things simple
	 * without needing a full email queue.
	 *
	 * @param string|array $to       Single recipient OR list of recipients.
	 *                               wp_mail() accepts either form natively.
	 * @param string       $subject
	 * @param string       $body
	 * @param string[]     $headers
	 * @return bool
	 */
	private static function send_with_retry( $to, string $subject, string $body, array $headers ): bool {
		if ( wp_mail( $to, $subject, $body, $headers ) ) {
			return true;
		}

		// One retry after a brief pause.
		sleep( 1 );
		return wp_mail( $to, $subject, $body, $headers );
	}

	/**
	 * Build a deep-link URL to the Neural Forms entries screen, anchored to the entry.
	 *
	 * The React app currently uses PHP-set screen state (not hash routing), so
	 * we link to the entries page and pass the entry ID as a query parameter for
	 * future deep-link support. The URL is safe to include in emails as-is.
	 *
	 * @param int $entry_id
	 * @return string
	 */
	/**
	 * Deep link to one entry in the inbox.
	 *
	 * URL contract of the split entries bundle (EntriesScreen.js):
	 *   entry_id=N          → opens the detail pane
	 *   form_id=N&view=inbox → scopes the list behind it to that form
	 * The key is `entry_id`, NOT `entry` — the latter opened the bare list.
	 */
	private static function get_entry_url( int $entry_id, int $form_id = 0 ): string {
		$args = array( 'page' => 'neural-forms-entries' );
		if ( $form_id > 0 ) {
			$args['form_id'] = $form_id;
			$args['view']    = 'inbox';
		}
		$args['entry_id'] = $entry_id;

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Split a single string into a list of valid recipient addresses.
	 *
	 * Accepts either a bare email (`alice@example.com`) or a
	 * comma-separated list (`alice@example.com, bob@example.com`).
	 * Each candidate is trimmed, passed through `sanitize_email()`,
	 * and validated with `is_email()`. Invalid entries are silently
	 * dropped — we never bubble a "your second address is broken"
	 * error to the form submitter, who didn't enter the value. The
	 * admin who set up the form sees the resulting recipient list
	 * (or its absence) reflected in the entry's neural_forms_logs
	 * row.
	 *
	 * Returns an empty array when nothing in $raw is a valid address.
	 * Callers should treat the empty case as "no recipient" and fall
	 * back to the next source in their resolution chain.
	 *
	 * Reused by SettingsEndpoint to validate the global `Notify
	 * address` field on save, so the same comma-list semantics apply
	 * everywhere a notification recipient is configured.
	 *
	 * @param string $raw
	 * @return array<int, string>  Valid emails, de-duplicated, in input order.
	 */
	public static function parse_recipient_list( string $raw ): array {
		$raw = trim( $raw );
		if ( $raw === '' ) {
			return array();
		}

		$out  = array();
		$seen = array();
		foreach ( explode( ',', $raw ) as $candidate ) {
			$candidate = sanitize_email( trim( $candidate ) );
			if ( $candidate === '' || ! is_email( $candidate ) ) {
				continue;
			}
			$key = strtolower( $candidate );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = $candidate;
		}
		return $out;
	}
}
