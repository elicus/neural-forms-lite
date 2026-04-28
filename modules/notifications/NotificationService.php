<?php
namespace NeuralForms\Notifications;

use NeuralForms\Core\Config;
use NeuralForms\Core\Logger;
use NeuralForms\Storage\EntryRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Sends admin notifications and autoresponder emails after a form submission.
 *
 * Hooks into the `neural_forms_send_notifications` action fired by SubmitEndpoint
 * after the entry is committed to the database (step 10 of the submission flow).
 *
 * Design principles:
 *   • Non-blocking — the entry is already saved before this runs. A mail
 *     failure never aborts the submission response.
 *   • All outcomes (success and failure) are written to neural_forms_logs so they
 *     are visible in the admin without tailing server error logs.
 *   • Sender name/email from Email Settings are applied when set.
 *   • Autoresponder tokens: {site_name}, {form_name}, plus any {field_id}
 *     that exists in the submitted data.
 */
class NotificationService {

	// -------------------------------------------------------------------------
	// Boot
	// -------------------------------------------------------------------------

	public static function boot(): void {
		add_action( 'neural_forms_send_notifications', [ self::class, 'handle' ], 10, 3 );
	}

	// -------------------------------------------------------------------------
	// Dispatch
	// -------------------------------------------------------------------------

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

		$schema_fields = $form['form_schema']['fields'] ?? [];
		$data          = $entry['data'] ?? [];

		if ( ! empty( $settings['enable_admin_notification'] ) ) {
			self::send_admin_notification( $entry_id, $form, $schema_fields, $data, $settings );
		}

		if ( ! empty( $settings['enable_autoresponder'] ) ) {
			self::send_autoresponder( $entry_id, $form, $schema_fields, $data, $settings );
		}
	}

	// -------------------------------------------------------------------------
	// Admin notification
	// -------------------------------------------------------------------------

	/**
	 * Sends the "new submission" notification to the configured admin address.
	 */
	private static function send_admin_notification(
		int    $entry_id,
		array  $form,
		array  $schema_fields,
		array  $data,
		array  $settings
	): void {
		$form_name = (string) ( $form['name'] ?? 'Unknown form' );

		// Recipient resolution order:
		//   1. Per-form notification_email (form settings)
		//   2. Global admin_notification_email (plugin settings)
		//   3. WordPress admin email
		$to = (string) ( $form['settings']['notification_email'] ?? '' );
		if ( $to === '' || ! is_email( $to ) ) {
			$to = (string) ( $settings['admin_notification_email'] ?? '' );
		}
		if ( $to === '' || ! is_email( $to ) ) {
			$to = (string) get_option( 'admin_email' );
		}

		if ( ! is_email( $to ) ) {
			Logger::log( 'admin_notification_skipped', 'warning',
				'Admin notification skipped: no valid recipient email address.', [], $entry_id );
			return;
		}

		/* translators: %s: form name */
		$subject = sprintf( __( 'New submission: %s', 'neural-forms' ), $form_name );

		$body  = sprintf( __( 'You received a new submission from "%s".', 'neural-forms' ), $form_name ) . "\n\n";
		$body .= self::build_fields_summary( $schema_fields, $data );
		$body .= "\n";
		$body .= sprintf(
			/* translators: %s: link to the Neural Forms entries screen */
			__( 'View entry: %s', 'neural-forms' ),
			self::get_entry_url( $entry_id )
		) . "\n";

		$headers = self::build_from_headers( $settings );

		$sent = self::send_with_retry( $to, $subject, $body, $headers );

		if ( $sent ) {
			Logger::log( 'admin_notification_sent', 'info',
				sprintf( 'Admin notification sent to %s.', $to ),
				[ 'to' => $to ], $entry_id );
		} else {
			Logger::log( 'admin_notification_failed', 'error',
				sprintf( 'Admin notification failed for %s — wp_mail() returned false after retry.', $to ),
				[ 'to' => $to ], $entry_id );
		}
	}

	// -------------------------------------------------------------------------
	// Autoresponder
	// -------------------------------------------------------------------------

	/**
	 * Sends a confirmation email to the submitter, if a valid email field exists.
	 */
	private static function send_autoresponder(
		int    $entry_id,
		array  $form,
		array  $schema_fields,
		array  $data,
		array  $settings
	): void {
		$to = self::find_submitter_email( $schema_fields, $data );

		if ( $to === '' ) {
			Logger::log( 'autoresponder_skipped', 'info',
				'Autoresponder skipped: no valid email field found in submission.', [], $entry_id );
			return;
		}

		$form_name = (string) ( $form['name'] ?? '' );

		$subject = (string) ( $settings['autoresponder_subject'] ?? '' );
		if ( $subject === '' ) {
			$subject = __( 'Thank you for your submission', 'neural-forms' );
		}

		$raw_body = (string) ( $settings['autoresponder_body'] ?? '' );
		if ( $raw_body === '' ) {
			$raw_body = __( "Hi,\n\nThank you for reaching out. We'll be in touch shortly.\n\n{site_name}", 'neural-forms' );
		}

		$body = self::apply_tokens( $raw_body, $form_name, $data );

		$headers = self::build_from_headers( $settings );

		$sent = self::send_with_retry( $to, $subject, $body, $headers );

		if ( $sent ) {
			Logger::log( 'autoresponder_sent', 'info',
				sprintf( 'Autoresponder sent to %s.', $to ),
				[ 'to' => $to ], $entry_id );
		} else {
			Logger::log( 'autoresponder_failed', 'error',
				sprintf( 'Autoresponder failed for %s — wp_mail() returned false after retry.', $to ),
				[ 'to' => $to ], $entry_id );
		}
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

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
	private static function find_submitter_email( array $schema_fields, array $data ): string {
		// Pass 1: typed email fields — most reliable.
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
		// result is predictable and repeatable — the first text/textarea/url
		// field whose submitted value passes is_email() wins.
		foreach ( $schema_fields as $field ) {
			$field    = (array) $field;
			$type     = $field['type'] ?? '';
			$field_id = $field['id']   ?? '';

			// Only consider fields that could plausibly hold an email address.
			if ( ! in_array( $type, [ 'text', 'textarea', 'url', 'hidden' ], true ) ) {
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
		$lines = [];

		foreach ( $schema_fields as $field ) {
			$field    = (array) $field;
			$field_id = $field['id'] ?? '';

			if ( $field_id === '' || ! array_key_exists( $field_id, $data ) ) {
				continue;
			}

			$label = (string) ( $field['label'] ?? $field_id );
			$raw   = $data[ $field_id ];

			if ( $field['type'] === 'checkbox' ) {
				// Multi-option checkboxes submit an array of selected values;
				// single-option checkboxes submit a 0/1 scalar.
				if ( is_array( $raw ) ) {
					$value = implode( ', ', array_map( 'strval', $raw ) );
				} else {
					$value = $raw ? __( 'Yes', 'neural-forms' ) : __( 'No', 'neural-forms' );
				}
			} elseif ( $field['type'] === 'file' ) {
				// File fields store a metadata array — display the filename if available.
				$value = is_array( $raw ) ? (string) ( $raw['filename'] ?? 'file' ) : '';
			} elseif ( is_array( $raw ) ) {
				// Generic fallback for any other multi-value field type
				// (select-multi, etc.) — join scalar leaves with commas.
				$value = implode( ', ', array_map( 'strval', array_filter( $raw, 'is_scalar' ) ) );
			} else {
				$value = (string) $raw;
			}

			$lines[] = $label . ': ' . $value;
		}

		return $lines ? implode( "\n", $lines ) . "\n" : '';
	}

	/**
	 * Replace supported tokens in an autoresponder body template.
	 *
	 * Supported tokens:
	 *   {site_name}  — WordPress site/blog name
	 *   {form_name}  — the form's human-readable name
	 *   {field_id}   — any key from the submitted data (case-sensitive)
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
		$replacements = [
			'{site_name}' => get_bloginfo( 'name' ),
			'{form_name}' => $form_name,
		];

		foreach ( $data as $field_id => $value ) {
			if ( is_scalar( $value ) ) {
				$replacements[ '{' . $field_id . '}' ] = (string) $value;
			}
		}

		return str_replace( array_keys( $replacements ), array_values( $replacements ), $template );
	}

	/**
	 * Build the From: header array from Email Settings.
	 *
	 * Returns an empty array when neither sender_name nor sender_email is
	 * configured — wp_mail() uses the WP default in that case.
	 *
	 * @param array $settings
	 * @return string[]
	 */
	private static function build_from_headers( array $settings ): array {
		$sender_name  = (string) ( $settings['sender_name']  ?? '' );
		$sender_email = (string) ( $settings['sender_email'] ?? '' );

		if ( $sender_email === '' || ! is_email( $sender_email ) ) {
			// No custom sender configured — let wp_mail() use its default.
			// If only a name is set, we still need an address to form a valid header.
			return [];
		}

		$from = $sender_name !== ''
			? sprintf( '%s <%s>', $sender_name, $sender_email )
			: $sender_email;

		return [ 'From: ' . $from ];
	}

	/**
	 * Try wp_mail() once, and if it fails, retry once after a 1-second pause.
	 *
	 * Transient SMTP connection failures (server busy, greylisting, etc.)
	 * often succeed on immediate retry. A single retry keeps things simple
	 * without needing a full email queue.
	 *
	 * @param string          $to
	 * @param string          $subject
	 * @param string          $body
	 * @param string[]        $headers
	 * @return bool
	 */
	private static function send_with_retry( string $to, string $subject, string $body, array $headers ): bool {
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
	private static function get_entry_url( int $entry_id ): string {
		return add_query_arg(
			[ 'page' => 'neural-forms-entries', 'entry' => $entry_id ],
			admin_url( 'admin.php' )
		);
	}

}
