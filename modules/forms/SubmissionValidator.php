<?php
namespace NeuralForms\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Validates submitted form data against a stored form schema.
 *
 * Responsibility boundary
 *
 * This class checks whether submitted values are *acceptable* - it does not
 * sanitize, store, or make any network or database calls.
 *
 * Compare to FormValidator (schema validation):
 *   FormValidator::validate_schema()   - checks field definitions at save time.
 *   SubmissionValidator::validate()    - checks submitted values at request time.
 *
 * Error collection
 *
 * Unlike FormValidator (which returns on the first schema error), this class
 * collects errors for every field before returning. That lets the client
 * highlight all invalid fields in one round-trip rather than correcting
 * one error only to discover the next.
 *
 * Error shape
 *
 * Each field error in data['fields'] is an associative array:
 *   [ 'code' => 'required', 'message' => 'Name is required.' ]
 *
 * The 'code' is machine-readable for the React layer (to decide rendering).
 * The 'message' is human-readable for display. Both are always present.
 *
 * Extension hook
 *
 * apply_filters( 'neural_forms_validate_submission_field', null, $field, $value )
 *
 * Fires after all built-in checks pass for a field. Return a WP_Error to
 * add a field-level validation failure; return null (or the first argument
 * unchanged) to pass. The WP_Error code becomes the field error code.
 *
 * Example:
 *   add_filter( 'neural_forms_validate_submission_field', function( $result, $field, $value ) {
 *       if ( $field['type'] === 'phone' && ! preg_match( '/^\+?[\d\s\-]{7,15}$/', $value ) ) {
 *           return new WP_Error( 'invalid_phone', 'Please enter a valid phone number.' );
 *       }
 *       return $result;
 *   }, 10, 3 );
 */
class SubmissionValidator {

	// Checkbox value constants
	//
	// Defined here as the single source of truth. The endpoint sanitization
	// step and any future UI code must reference these rather than repeating
	// the accepted set independently.

	/**
	 * All raw values a checkbox field may legally submit.
	 *
	 * The endpoint must cast these to int (0 or 1) before storing, so the
	 * JSON blob is uniform for exports, filters, and AI context.
	 *
	 * Anything outside this set (e.g. "yes", "on", 2, []) is rejected.
	 *
	 * @var array
	 */
	public const ACCEPTED_CHECKBOX_VALUES = array( 0, 1, true, false, '0', '1', '', null );

	/**
	 * The subset of ACCEPTED_CHECKBOX_VALUES that mean "checked".
	 *
	 * Used by check_required() and can be referenced by the endpoint when
	 * deciding whether a checkbox value should sanitize to 1 vs. 0.
	 *
	 * @var array
	 */
	public const CHECKED_CHECKBOX_VALUES = array( 1, '1', true );

	// Types whose string values are trimmed before validation
	//
	// For these types, a value of "   " is treated as absent/empty during
	// validation - same as how sanitize_text_field() etc. behave at store time.
	// Trimming here is for validation only; the endpoint sanitizes independently.

	private const TRIMMABLE_TYPES = array( 'text', 'email', 'textarea', 'phone', 'tel', 'url', 'number', 'date', 'rating', 'poll', 'quiz_question' );

	// Public API

	/**
	 * Validate submitted data against the form's schema fields.
	 *
	 * The submitted_data array is expected to have already been stripped of
	 * unknown keys by the endpoint - this validator iterates schema fields
	 * only, so any extra keys in submitted_data are naturally ignored.
	 *
	 * @param array $schema_fields  The 'fields' array from the stored form_schema.
	 * @param array $submitted_data Associative array of field_id => raw submitted value.
	 * @return true|\WP_Error
	 */
	public static function validate( array $schema_fields, array $submitted_data ) {
		$errors = array();

		foreach ( $schema_fields as $field ) {
			$field = (array) $field;

			$field_id = $field['id'] ?? '';

			// Skip malformed entries - FormValidator enforces this at save time,
			// but guard defensively in case the schema was written externally.
			if ( $field_id === '' ) {
				continue;
			}

			$type = $field['type'] ?? 'text';

			// Use array_key_exists rather than isset so an explicitly submitted
			// null is treated the same as a missing key - both mean the client
			// sent no usable value for this field.
			$value = array_key_exists( $field_id, $submitted_data )
				? $submitted_data[ $field_id ]
				: null;

			// For text-like types, trim whitespace before all validation checks.
			// "   " is not a useful value for any of these types and would
			// otherwise pass the required check or the is_absent() guard.
			// This is purely for validation - the endpoint sanitizes separately.
			$check_value = self::maybe_trim( $value, $type );

			// ' Required check '
			$required_error = self::check_required( $field, $check_value );
			if ( null !== $required_error ) {
				// Wrap in array: JS showFieldErrors expects { fieldId: [{code, message}, ...] }
				$errors[ $field_id ] = array( $required_error );
				// Do not run type checks - there is nothing meaningful to check.
				continue;
			}

			// ' Skip type checks for absent optional values '
			// If the field is not required and no value was submitted, it is
			// valid as-is. Type checks only make sense for present values.
			if ( self::is_absent( $check_value ) ) {
				continue;
			}

			// ' Type-specific format checks '
			$type_error = self::check_type( $field, $check_value );
			if ( null !== $type_error ) {
				// Wrap in array: JS showFieldErrors expects { fieldId: [{code, message}, ...] }
				$errors[ $field_id ] = array( $type_error );
				// Skip the hook for fields that already failed - providing a
				// malformed value to extensions would produce misleading results.
				continue;
			}

			// ' Extension hook '
			// Fires only when all built-in checks have passed. Extensions
			// receive the trimmed value - do not make security decisions based
			// on it; use it for format or business-rule checks only.
			$hook_result = apply_filters( 'neural_forms_validate_submission_field', null, $field, $check_value );

			if ( is_wp_error( $hook_result ) ) {
				// Wrap in array: JS showFieldErrors expects { fieldId: [{code, message}, ...] }
				$errors[ $field_id ] = array(
					array(
						'code'    => $hook_result->get_error_code(),
						'message' => $hook_result->get_error_message(),
					),
				);
			}
		}

		if ( empty( $errors ) ) {
			return true;
		}

		// Return a single WP_Error containing all field errors.
		// The top-level message is intentionally generic - callers should
		// read data['fields'] to get the per-field codes and messages.
		return new \WP_Error(
			'invalid_submission',
			__( 'The submission contains errors. Please check the fields below.', 'neural-forms-lite' ),
			array(
				'status' => 422,
				'fields' => $errors,
			)
		);
	}

	/**
	 * Validate a single field/value pair in isolation. Used by the
	 * Repeater branch to run per-iteration child validation without
	 * having to re-walk the whole schema. Returns null on success,
	 * or a single { code, message } error array on failure (matching
	 * the per-field error shape `validate()` produces).
	 *
	 * Internal contract: this is the same pair of checks `validate()`
	 * runs for every leaf field — required → absent? → type → hook —
	 * but exposed so the repeater walker can recurse without
	 * duplicating each per-type rule.
	 *
	 * @param array $field
	 * @param mixed $value
	 * @return array|null
	 */
	public static function validate_single_field( array $field, $value ): ?array {
		$type        = $field['type'] ?? 'text';
		$check_value = self::maybe_trim( $value, $type );

		$required_error = self::check_required( $field, $check_value );
		if ( null !== $required_error ) {
			return $required_error;
		}

		// Repeater values are arrays of iterations — `is_absent` is
		// only meaningful for scalars. Allow the type check to run
		// on the (possibly empty) array so its own count gate fires.
		if ( $type !== 'repeater' && self::is_absent( $check_value ) ) {
			return null;
		}

		$type_error = self::check_type( $field, $check_value );
		if ( null !== $type_error ) {
			return $type_error;
		}

		$hook_result = apply_filters( 'neural_forms_validate_submission_field', null, $field, $check_value );
		if ( is_wp_error( $hook_result ) ) {
			return array(
				'code'    => $hook_result->get_error_code(),
				'message' => $hook_result->get_error_message(),
			);
		}
		return null;
	}

	// Private helpers

	/**
	 * Check the required constraint for a single field.
	 *
	 * Returns a field-error array [ 'code' => ..., 'message' => ... ] on
	 * failure, or null when the check passes. The code is machine-readable;
	 * the message is human-readable for display.
	 *
	 * Checkbox is handled separately: required means "must be checked" (the
	 * checked subset of ACCEPTED_CHECKBOX_VALUES), not merely "must be present".
	 * An unchecked value (0, '0', false) is present but not sufficient.
	 *
	 * @param array $field
	 * @param mixed $value  Already trimmed by maybe_trim() where applicable.
	 * @return array|null
	 */
	private static function check_required( array $field, $value ): ?array {
		if ( empty( $field['required'] ) ) {
			return null;
		}

		$label = self::field_label( $field );
		$type  = $field['type'] ?? 'text';

		if ( $type === 'checkbox' || $type === 'acceptance' ) {
			if ( ! self::is_checkbox_checked( $value ) ) {
				return array(
					'code'    => 'required',
					/* translators: %s: field label */
					'message' => sprintf( __( '%s must be checked.', 'neural-forms-lite' ), $label ),
				);
			}
			return null;
		}

		if ( $type === 'checkboxes' ) {
			if ( ! is_array( $value ) || count( $value ) === 0 ) {
				return array(
					'code'    => 'required',
					/* translators: %s: field label */
					'message' => sprintf( __( 'Please select at least one option for %s.', 'neural-forms-lite' ), $label ),
				);
			}
			return null;
		}

		if ( $type === 'matrix' ) {
			// Required matrix: every configured row must carry a non-empty
			// selection. Radio rows need a non-empty scalar string; checkbox
			// rows need at least one non-empty array entry. Unknown row keys
			// in the submitted payload are not counted toward coverage —
			// they are silently ignored by check_type/sanitization.
			$settings     = is_array( $field['settings'] ?? null ) ? $field['settings'] : array();
			$selection    = ( ( $settings['selection_type'] ?? 'radio' ) === 'checkbox' ) ? 'checkbox' : 'radio';
			$allowed_rows = self::get_matrix_row_values( $field );

			if ( empty( $allowed_rows ) ) {
				// Matrix with zero configured rows can never accept input.
				// Treat as required-failure rather than silently passing so
				// authors notice the misconfiguration on first submission.
				return array(
					'code'    => 'required',
					/* translators: %s: field label */
					'message' => sprintf( __( 'Please complete all rows for %s.', 'neural-forms-lite' ), $label ),
				);
			}

			if ( ! is_array( $value ) ) {
				return array(
					'code'    => 'required',
					/* translators: %s: field label */
					'message' => sprintf( __( 'Please complete all rows for %s.', 'neural-forms-lite' ), $label ),
				);
			}

			// Required-check rule for matrix: a row is "missing" only if the
			// key is absent, null, an empty string, or an empty array.
			// Wrong-shape data (array under radio, scalar under checkbox)
			// is left alone here so check_type can emit the more specific
			// `invalid_matrix` error instead of a misleading `required`.
			foreach ( $allowed_rows as $row_key ) {
				if ( ! array_key_exists( $row_key, $value ) ) {
					return array(
						'code'    => 'required',
						/* translators: %s: field label */
						'message' => sprintf( __( 'Please complete all rows for %s.', 'neural-forms-lite' ), $label ),
					);
				}
				$row_val = $value[ $row_key ];

				if ( null === $row_val || $row_val === '' ) {
					return array(
						'code'    => 'required',
						/* translators: %s: field label */
						'message' => sprintf( __( 'Please complete all rows for %s.', 'neural-forms-lite' ), $label ),
					);
				}

				// Checkbox-specific empty: array with no non-empty entries.
				if ( 'checkbox' === $selection && is_array( $row_val ) ) {
					$has_any = false;
					foreach ( $row_val as $rv ) {
						if ( is_scalar( $rv ) && (string) $rv !== '' ) {
							$has_any = true;
							break;
						}
					}
					if ( ! $has_any ) {
						return array(
							'code'    => 'required',
							/* translators: %s: field label */
							'message' => sprintf( __( 'Please select at least one option for each row in %s.', 'neural-forms-lite' ), $label ),
						);
					}
				}
			}

			return null;
		}

		if ( $type === 'repeater' ) {
			// Required repeater: must carry at least min_items iterations
			// AND at least one — a min=0 repeater that's also "required"
			// still demands a single submitted iteration to satisfy the
			// required-ness contract. The per-iteration child required
			// checks run in check_type below.
			$settings  = is_array( $field['settings'] ?? null ) ? $field['settings'] : array();
			$min       = isset( $settings['min_items'] ) ? (int) $settings['min_items'] : 1;
			$rows      = is_array( $value ) ? array_values( $value ) : array();
			$threshold = max( 1, $min );
			if ( count( $rows ) < $threshold ) {
				return array(
					'code'    => 'required',
					'message' => sprintf(
						/* translators: 1: field label, 2: minimum iteration count */
						__( '%1$s needs at least %2$d item(s).', 'neural-forms-lite' ),
						$label,
						$threshold
					),
				);
			}
			return null;
		}

		if ( null === $value || $value === '' ) {
			return array(
				'code'    => 'required',
				/* translators: %s: field label */
				'message' => sprintf( __( '%s is required.', 'neural-forms-lite' ), $label ),
			);
		}

		return null;
	}

	/**
	 * Run type-specific format checks on a value that is known to be present.
	 *
	 * Returns a field-error array [ 'code' => ..., 'message' => ... ] on
	 * failure, or null when the value is acceptable.
	 *
	 * Called only after is_absent() returns false, so $value is never null
	 * or empty string here (checkbox is the only exception - it has its own
	 * handling via is_valid_checkbox_value()).
	 *
	 * @param array $field
	 * @param mixed $value  Already trimmed by maybe_trim() where applicable.
	 * @return array|null
	 */
	private static function check_type( array $field, $value ): ?array {
		$type  = $field['type'] ?? 'text';
		$label = self::field_label( $field );

		switch ( $type ) {

			case 'email':
				if ( ! is_email( $value ) ) {
					return array(
						'code'    => 'invalid_email',
						/* translators: %s: field label */
						'message' => sprintf( __( '%s must be a valid email address.', 'neural-forms-lite' ), $label ),
					);
				}
				break;

			case 'phone':
			case 'tel':
				// Allowed punctuation: + (leading only), digits, spaces,
				// dashes, parens, dots. 7-15 digits — same envelope used
				// by client-side validation in form-submit.js. Rejects
				// "0", "1", and other single-digit / alphabetic inputs
				// that previously slipped through because there was no
				// type rule for phone here.
				$digits = preg_replace( '/\D/', '', (string) $value );
				if (
					! preg_match( '/^\+?[\d\s().\-]+$/', (string) $value )
					|| strlen( $digits ) < 7
					|| strlen( $digits ) > 15
				) {
					return array(
						'code'    => 'invalid_phone',
						/* translators: %s: field label */
						'message' => sprintf( __( '%s must be a valid phone number.', 'neural-forms-lite' ), $label ),
					);
				}
				break;

			case 'number':
				if ( ! is_numeric( $value ) ) {
					return array(
						'code'    => 'invalid_number',
						/* translators: %s: field label */
						'message' => sprintf( __( '%s must be a number.', 'neural-forms-lite' ), $label ),
					);
				}
				break;

			case 'select':
			case 'radio':
			case 'poll':
			case 'quiz_question':
				$allowed = self::get_select_option_values( $field );

				// Permissive when no options are defined: an active form reaching
				// this point with an empty options array is a broken configuration,
				// not an expected state. The endpoint pre-validates form status
				// (must be 'active') before calling the validator, so draft forms
				// cannot reach here. If select options are required at schema-save
				// time, enforce that via the 'neural_forms_validate_field' hook in
				// FormValidator rather than silently rejecting every submission.
				if ( ! empty( $allowed ) && ! in_array( (string) $value, $allowed, true ) ) {
					return array(
						'code'    => 'invalid_option',
						/* translators: %s: field label */
						'message' => sprintf( __( '%s contains an invalid selection.', 'neural-forms-lite' ), $label ),
					);
				}
				break;

			case 'url':
				if ( filter_var( $value, FILTER_VALIDATE_URL ) === false ) {
					return array(
						'code'    => 'invalid_url',
						/* translators: %s: field label */
						'message' => sprintf( __( '%s must be a valid URL.', 'neural-forms-lite' ), $label ),
					);
				}
				break;

			case 'date':
				// Accept YYYY-MM-DD format (HTML date input standard).
				if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $value ) || ! strtotime( $value ) ) {
					return array(
						'code'    => 'invalid_date',
						/* translators: %s: field label */
						'message' => sprintf( __( '%s must be a valid date.', 'neural-forms-lite' ), $label ),
					);
				}
				break;

			case 'range':
				if ( ! is_numeric( $value ) ) {
					return array(
						'code'    => 'invalid_range',
						/* translators: %s: field label */
						'message' => sprintf( __( '%s must be a number.', 'neural-forms-lite' ), $label ),
					);
				}
				$num = (float) $value;
				$min = isset( $field['min'] ) && $field['min'] !== '' ? (float) $field['min'] : null;
				$max = isset( $field['max'] ) && $field['max'] !== '' ? (float) $field['max'] : null;
				if ( null !== $min && $num < $min ) {
					return array(
						'code'    => 'range_below_min',
						/* translators: 1: field label, 2: minimum value */
						'message' => sprintf( __( '%1$s must be at least %2$s.', 'neural-forms-lite' ), $label, $min ),
					);
				}
				if ( null !== $max && $num > $max ) {
					return array(
						'code'    => 'range_above_max',
						/* translators: 1: field label, 2: maximum value */
						'message' => sprintf( __( '%1$s must be at most %2$s.', 'neural-forms-lite' ), $label, $max ),
					);
				}
				break;

			case 'rating':
				if ( ! is_numeric( $value ) ) {
					return array(
						'code'    => 'invalid_rating',
						/* translators: %s: field label */
						'message' => sprintf( __( '%s must be a number.', 'neural-forms-lite' ), $label ),
					);
				}
				$num   = (int) $value;
				$stars = isset( $field['stars'] ) ? (int) $field['stars'] : 5;
				if ( $stars < 1 ) {
					$stars = 1; }
				if ( $stars > 10 ) {
					$stars = 10; }
				if ( $num < 1 || $num > $stars ) {
					return array(
						'code'    => 'invalid_rating',
						/* translators: 1: field label, 2: max stars */
						'message' => sprintf( __( '%1$s must be between 1 and %2$d.', 'neural-forms-lite' ), $label, $stars ),
					);
				}
				break;

			case 'checkboxes':
				if ( ! is_array( $value ) ) {
					return array(
						'code'    => 'invalid_checkboxes',
						/* translators: %s: field label */
						'message' => sprintf( __( '%s must be an array of selections.', 'neural-forms-lite' ), $label ),
					);
				}
				$allowed = self::get_select_option_values( $field );
				if ( ! empty( $allowed ) ) {
					foreach ( $value as $v ) {
						if ( ! in_array( (string) $v, $allowed, true ) ) {
							return array(
								'code'    => 'invalid_option',
								/* translators: %s: field label */
								'message' => sprintf( __( '%s contains an invalid selection.', 'neural-forms-lite' ), $label ),
							);
						}
					}
				}
				break;

			case 'checkbox':
			case 'acceptance':
				// Value is present (not absent) but may still be outside the
				// accepted set - e.g. a client sent "yes" or an arbitrary string.
				if ( ! self::is_valid_checkbox_value( $value ) ) {
					return array(
						'code'    => 'invalid_checkbox',
						/* translators: %s: field label */
						'message' => sprintf( __( '%s contains an invalid value.', 'neural-forms-lite' ), $label ),
					);
				}
				break;

			case 'matrix':
				// Shape:
				// radio:    row_value => "column_value"
				// checkbox: row_value => ["col_a","col_b",...]
				// The validator only rejects:
				// - non-array root value (e.g. someone POSTed a string)
				// - wrong inner shape for the configured selection mode
				// (array under radio, scalar under checkbox)
				// - column value not in the allowed columns allowlist
				// Unknown row keys are silently ignored (per spec) — the
				// sanitizer drops them before storage. Empty rows are
				// already gated by check_required for required matrices.
				if ( ! is_array( $value ) ) {
					return array(
						'code'    => 'invalid_matrix',
						/* translators: %s: field label */
						'message' => sprintf( __( '%s must be a set of row selections.', 'neural-forms-lite' ), $label ),
					);
				}

				$settings_m   = is_array( $field['settings'] ?? null ) ? $field['settings'] : array();
				$selection_m  = ( ( $settings_m['selection_type'] ?? 'radio' ) === 'checkbox' ) ? 'checkbox' : 'radio';
				$allowed_rows = self::get_matrix_row_values( $field );
				$allowed_cols = self::get_matrix_column_values( $field );

				foreach ( $value as $row_key => $row_val ) {
					$row_key_str = (string) $row_key;

					// Unknown row → ignore (no error, no storage). Matches
					// how other field allowlists silently drop unknowns.
					if ( ! in_array( $row_key_str, $allowed_rows, true ) ) {
						continue;
					}

					if ( 'checkbox' === $selection_m ) {
						// Scalar under checkbox is an invalid shape. We do
						// NOT silently promote it to a one-item array —
						// that would mask client tampering.
						if ( ! is_array( $row_val ) ) {
							return array(
								'code'    => 'invalid_matrix',
								/* translators: %s: field label */
								'message' => sprintf( __( 'Please select a valid option for %s.', 'neural-forms-lite' ), $label ),
							);
						}
						foreach ( $row_val as $rv ) {
							if ( ! is_scalar( $rv ) ) {
								return array(
									'code'    => 'invalid_matrix',
									/* translators: %s: field label */
									'message' => sprintf( __( 'Please select a valid option for %s.', 'neural-forms-lite' ), $label ),
								);
							}
							$rv_s = (string) $rv;
							if ( $rv_s === '' ) {
								continue;
							}
							if ( ! empty( $allowed_cols ) && ! in_array( $rv_s, $allowed_cols, true ) ) {
								return array(
									'code'    => 'invalid_option',
									/* translators: %s: field label */
									'message' => sprintf( __( 'Please select a valid option for %s.', 'neural-forms-lite' ), $label ),
								);
							}
						}
					} else {
						// radio: array under radio is invalid.
						if ( is_array( $row_val ) ) {
							return array(
								'code'    => 'invalid_matrix',
								/* translators: %s: field label */
								'message' => sprintf( __( 'Please select a valid option for %s.', 'neural-forms-lite' ), $label ),
							);
						}
						if ( null === $row_val ) {
							// Optional matrix may carry null rows — ignore.
							continue;
						}
						if ( ! is_scalar( $row_val ) ) {
							return array(
								'code'    => 'invalid_matrix',
								/* translators: %s: field label */
								'message' => sprintf( __( 'Please select a valid option for %s.', 'neural-forms-lite' ), $label ),
							);
						}
						$row_val_str = (string) $row_val;
						if ( $row_val_str === '' ) {
							continue;
						}
						if ( ! empty( $allowed_cols ) && ! in_array( $row_val_str, $allowed_cols, true ) ) {
							return array(
								'code'    => 'invalid_option',
								/* translators: %s: field label */
								'message' => sprintf( __( 'Please select a valid option for %s.', 'neural-forms-lite' ), $label ),
							);
						}
					}
				}
				break;

			// file:
			// Processed before this validator runs (process_file_fields in
			// SubmitEndpoint). By the time we reach here, the value is either
			// absent (optional, no file uploaded) or a structured metadata array
			// (uploaded successfully). No further format check needed.
			case 'file':
				break;

			case 'repeater':
				// Shape: numeric-keyed array of per-iteration children
				// objects. The wire format (from form-submit.js's
				// bracket-aware serializer) is
				// { repeater_id: { '0': { child_id: ... }, '1': {...} } }
				// which PHP's json_decode delivers as a plain array.
				//
				// Rules:
				// - root must be an array.
				// - count <= max_items (hard cap re-enforced here in
				// case a tampered submission tries to overflow).
				// - per-iteration: each schema child template runs
				// through validate_single_field with the
				// iteration's value for that child id.
				if ( ! is_array( $value ) ) {
					return array(
						'code'    => 'invalid_repeater',
						/* translators: %s: field label */
						'message' => sprintf( __( '%s must be a list of items.', 'neural-forms-lite' ), $label ),
					);
				}
				$settings_r = is_array( $field['settings'] ?? null ) ? $field['settings'] : array();
				$max_items  = isset( $settings_r['max_items'] ) ? (int) $settings_r['max_items'] : 5;
				if ( $max_items < 1 ) {
					$max_items = 1;
				}
				if ( $max_items > 20 ) {
					$max_items = 20;
				}
				$rows      = array_values( $value );
				$row_count = count( $rows );
				if ( $row_count > $max_items ) {
					return array(
						'code'    => 'invalid_repeater',
						'message' => sprintf(
							/* translators: 1: field label, 2: max iteration count */
							__( '%1$s allows at most %2$d item(s).', 'neural-forms-lite' ),
							$label,
							$max_items
						),
					);
				}
				$children_schema = is_array( $field['fields'] ?? null ) ? $field['fields'] : array();
				foreach ( $rows as $row_idx => $row ) {
					if ( ! is_array( $row ) ) {
						// Tampered scalar inside an iteration slot.
						return array(
							'code'    => 'invalid_repeater',
							'message' => sprintf(
								/* translators: 1: field label, 2: row index */
								__( '%1$s item #%2$d is malformed.', 'neural-forms-lite' ),
								$label,
								(int) $row_idx + 1
							),
						);
					}
					foreach ( $children_schema as $child ) {
						if ( ! is_array( $child ) || empty( $child['id'] ) ) {
							continue;
						}
						$child_id    = (string) $child['id'];
						$child_value = array_key_exists( $child_id, $row ) ? $row[ $child_id ] : null;
						$child_err   = self::validate_single_field( $child, $child_value );
						if ( null !== $child_err ) {
							// Prefix the message with "Item N: " so the
							// admin / visitor can locate which row failed.
							return array(
								'code'    => $child_err['code'],
								'message' => sprintf(
									/* translators: 1: row index, 2: field label, 3: child error message */
									__( '%1$s item #%2$d — %3$s', 'neural-forms-lite' ),
									$label,
									(int) $row_idx + 1,
									$child_err['message']
								),
							);
						}
					}
				}
				break;

			// text, textarea, phone, tel:
			// No format constraint beyond presence. Presence is handled by
			// check_required(). Sanitization is the endpoint's responsibility.
		}

		return null;
	}

	/**
	 * Trim whitespace from a value for text-like field types.
	 *
	 * Applies only to string values and only to types in TRIMMABLE_TYPES.
	 * Non-string values (null, int, bool) and non-trimmable types (checkbox,
	 * select) are returned unchanged.
	 *
	 * @param mixed  $value
	 * @param string $type
	 * @return mixed
	 */
	private static function maybe_trim( $value, string $type ) {
		if ( is_string( $value ) && in_array( $type, self::TRIMMABLE_TYPES, true ) ) {
			$value = trim( $value );
			if ( $type === 'phone' || $type === 'tel' ) {
				$value = self::normalize_phone( $value );
			}
			return $value;
		}
		return $value;
	}

	/**
	 * Treat a phone value that is ONLY a country dial code as empty.
	 *
	 * The frontend country picker pre-fills "+1 " (etc.) into every Phone
	 * input, so a visitor who leaves the field alone submits "+1", not "".
	 * Before 1.1.3 that failed the 7-digit type rule and blocked optional
	 * fields (and gave required ones the wrong message). Shared by the
	 * validator (required / type checks) and SubmitEndpoint's sanitizer
	 * (so "" is stored, not "+1"); form-submit.js mirrors the same rule.
	 *
	 * @param string $value  Trimmed phone value.
	 * @return string  '' when the value is just "+<1-4 digits>", else unchanged.
	 */
	public static function normalize_phone( string $value ): string {
		$value = trim( $value );
		return preg_match( '/^\+\d{1,4}$/', $value ) ? '' : $value;
	}

	/**
	 * True when a value is absent - null or empty string.
	 *
	 * Used to skip type checks for optional fields that were not submitted.
	 * A checkbox with value 0 or '0' is NOT absent - the client explicitly
	 * submitted "unchecked". Only null (key missing) and '' (empty payload
	 * value) represent "nothing was sent".
	 *
	 * @param mixed $value  Value after maybe_trim().
	 * @return bool
	 */
	private static function is_absent( $value ): bool {
		return null === $value || $value === '';
	}

	/**
	 * True when a checkbox value represents "checked".
	 *
	 * References CHECKED_CHECKBOX_VALUES as the single source of truth.
	 * Only the values in that constant - integer 1, string '1', boolean true -
	 * are considered checked. All other ACCEPTED_CHECKBOX_VALUES mean unchecked.
	 *
	 * @param mixed $value
	 * @return bool
	 */
	private static function is_checkbox_checked( $value ): bool {
		foreach ( self::CHECKED_CHECKBOX_VALUES as $checked ) {
			if ( $value === $checked ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * True when a checkbox value is within ACCEPTED_CHECKBOX_VALUES.
	 *
	 * Uses an explicit loop with === rather than in_array( $value, [...], true )
	 * to make each accepted case readable without relying on knowledge of PHP's
	 * strict-comparison edge cases for null and bool.
	 *
	 * The endpoint must cast accepted values to int (0|1) before storing.
	 *
	 * @param mixed $value
	 * @return bool
	 */
	private static function is_valid_checkbox_value( $value ): bool {
		foreach ( self::ACCEPTED_CHECKBOX_VALUES as $accepted ) {
			if ( $value === $accepted ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Extract the allowed option values from a select field's options array.
	 *
	 * Expected options format (mirrors JS fieldTypes.js defaults):
	 *   [ ['value' => 'red', 'label' => 'Red'], ... ]
	 *
	 * Returns an array of strings (values only) for in_array() comparison.
	 * Entries without a 'value' key are silently skipped.
	 *
	 * @param array $field
	 * @return string[]
	 */
	private static function get_select_option_values( array $field ): array {
		$options = $field['options'] ?? array();

		if ( ! is_array( $options ) || empty( $options ) ) {
			return array();
		}

		$values = array();
		foreach ( $options as $opt ) {
			$opt     = (array) $opt;
			$raw_val = (string) ( $opt['value'] ?? '' );
			$label   = (string) ( $opt['label'] ?? '' );
			// Mirror FormRenderer: if value is empty, use label as the effective
			// value. This ensures the allowed-values list matches what the renderer
			// emits in the <option value="..."> attribute.
			$effective = $raw_val !== '' ? $raw_val : $label;
			if ( $effective !== '' ) {
				$values[] = $effective;
			}
		}

		return $values;
	}

	/**
	 * Allowed row values for a matrix field (read from settings.rows).
	 *
	 * Mirrors FormRenderer normalization: when a row's `value` is empty
	 * the `label` is used as the effective value. Empty/empty entries
	 * are skipped.
	 *
	 * @param array $field
	 * @return string[]
	 */
	private static function get_matrix_row_values( array $field ): array {
		$settings = is_array( $field['settings'] ?? null ) ? $field['settings'] : array();
		$rows     = is_array( $settings['rows'] ?? null ) ? $settings['rows'] : array();
		$out      = array();
		foreach ( $rows as $row ) {
			$row = (array) $row;
			$v   = (string) ( $row['value'] ?? '' );
			$l   = (string) ( $row['label'] ?? '' );
			$eff = $v !== '' ? $v : $l;
			if ( $eff !== '' ) {
				$out[] = $eff;
			}
		}
		return $out;
	}

	/**
	 * Allowed column values for a matrix field (read from settings.columns).
	 *
	 * Mirrors FormRenderer normalization: when a column's `value` is empty
	 * the `label` is used as the effective value. Empty/empty entries
	 * are skipped.
	 *
	 * @param array $field
	 * @return string[]
	 */
	private static function get_matrix_column_values( array $field ): array {
		$settings = is_array( $field['settings'] ?? null ) ? $field['settings'] : array();
		$cols     = is_array( $settings['columns'] ?? null ) ? $settings['columns'] : array();
		$out      = array();
		foreach ( $cols as $col ) {
			$col = (array) $col;
			$v   = (string) ( $col['value'] ?? '' );
			$l   = (string) ( $col['label'] ?? '' );
			$eff = $v !== '' ? $v : $l;
			if ( $eff !== '' ) {
				$out[] = $eff;
			}
		}
		return $out;
	}

	/**
	 * Returns a display-safe label for use in error messages.
	 * Falls back to the field ID if the label is absent or whitespace-only.
	 *
	 * @param array $field
	 * @return string
	 */
	private static function field_label( array $field ): string {
		$label = trim( $field['label'] ?? '' );
		return $label !== '' ? $label : ( (string) ( $field['id'] ?? 'Field' ) );
	}
}
