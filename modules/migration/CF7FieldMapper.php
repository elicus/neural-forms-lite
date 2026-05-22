<?php
namespace NeuralForms\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Maps Contact Form 7 field tags to Neural Forms field definitions.
 *
 * Handles type conversion, option mapping, and unique ID generation.
 * All methods are static and pure - no side effects.
 */
class CF7FieldMapper {

	/**
	 * CF7 basetype => Neural Forms field type.
	 */
	const TYPE_MAP = array(
		'text'       => 'text',
		'email'      => 'email',
		'url'        => 'url',
		'tel'        => 'tel',
		'number'     => 'number',
		'range'      => 'range',
		'date'       => 'date',
		'textarea'   => 'textarea',
		'select'     => 'select',
		'checkbox'   => 'checkboxes',
		'acceptance' => 'acceptance',
		'radio'      => 'radio',
		'hidden'     => 'hidden',
		'file'       => 'file',
		// CF7 quiz → Neural Forms quiz_question. The mapping is one
		// CF7 quiz pair ("Question | Answer") to one Neural Forms
		// quiz_question field; multi-pair tags fan out to multiple
		// fields. See map_quiz_tag() for the fan-out and the
		// warning that asks the user to add distractor options.
		'quiz'       => 'quiz_question',
	);

	/**
	 * CF7 types that cannot be migrated - silently skipped with a warning.
	 */
	const UNSUPPORTED = array(
		'captchac',
		'captchar',
		'count',
		'reflection',
		'output',
		'response',
		'recaptcha',
		'stripe',
		'turnstile',
		'submit',
	);

	/**
	 * Map all tags from a CF7 form into Neural Forms field definitions.
	 *
	 * @param array[] $tags  Parsed tag descriptors from CF7Detector::parse_tags().
	 * @return array {
	 *   @type array[] $fields   Neural Forms field definitions, keyed by unique ID.
	 *   @type array[] $skipped  Tags that could not be mapped: { name, cf7_type, reason }.
	 *   @type string[] $warnings Human-readable notes about the migration.
	 * }
	 */
	public static function map_form( array $tags ): array {
		$fields   = array();
		$skipped  = array();
		$warnings = array();
		$used_ids = array();

		foreach ( $tags as $tag ) {
			$cf7_type = $tag['basetype'];

			// Skip explicitly unsupported types.
			if ( in_array( $cf7_type, self::UNSUPPORTED, true ) ) {
				$skipped[]  = array(
					'name'     => $tag['name'],
					'cf7_type' => $cf7_type,
					'reason'   => 'Unsupported field type.',
				);
				$warnings[] = sprintf(
					'Skipped "%s" (%s): unsupported field type.',
					$tag['name'],
					$cf7_type
				);
				continue;
			}

			// Skip unknown types not in our map.
			if ( ! isset( self::TYPE_MAP[ $cf7_type ] ) ) {
				$skipped[]  = array(
					'name'     => $tag['name'],
					'cf7_type' => $cf7_type,
					'reason'   => 'Unknown CF7 field type.',
				);
				$warnings[] = sprintf(
					'Skipped "%s" (%s): unknown field type.',
					$tag['name'],
					$cf7_type
				);
				continue;
			}

			// CF7 quiz tags are the only multi-output basetype: a single
			// `[quiz quiz-576 "Q1|A1" "Q2|A2"]` fans out to N Neural
			// Forms quiz_question fields. Everything else maps 1:1.
			if ( $cf7_type === 'quiz' ) {
				$quiz_fields = self::map_quiz_tag( $tag );
				if ( empty( $quiz_fields ) ) {
					$skipped[]  = array(
						'name'     => $tag['name'],
						'cf7_type' => $cf7_type,
						'reason'   => 'Quiz tag had no question/answer pairs.',
					);
					$warnings[] = sprintf(
						'Skipped "%s" (%s): no question/answer pairs found.',
						$tag['name'],
						$cf7_type
					);
					continue;
				}
				foreach ( $quiz_fields as $i => $quiz_field ) {
					// Suffix the original tag name with the pair index so
					// "quiz-576" with two Q/A pairs becomes "quiz_576_1"
					// + "quiz_576_2" — predictable, unique, debuggable.
					$suffix     = count( $quiz_fields ) === 1 ? '' : '_' . ( $i + 1 );
					$field_id   = self::unique_id( $tag['name'] . $suffix, $used_ids );
					$used_ids[] = $field_id;

					$fields[ $field_id ] = $quiz_field;
				}
				$warnings[] = sprintf(
					/* translators: %s: CF7 quiz tag name */
					'Imported "%s" as %d quiz question(s). Add distractor options before publishing — CF7 quiz only stores the correct answer.',
					$tag['name'],
					count( $quiz_fields )
				);
				continue;
			}

			$field = self::map_tag( $tag );
			if ( null === $field ) {
				$skipped[] = array(
					'name'     => $tag['name'],
					'cf7_type' => $cf7_type,
					'reason'   => 'Mapping produced no result.',
				);
				continue;
			}

			// Generate a unique field ID.
			$field_id   = self::unique_id( $tag['name'], $used_ids );
			$used_ids[] = $field_id;

			$fields[ $field_id ] = $field;
		}

		return array(
			'fields'   => $fields,
			'skipped'  => $skipped,
			'warnings' => $warnings,
		);
	}

	/**
	 * CF7 quiz tag → one or more Neural Forms `quiz_question` fields.
	 *
	 * CF7's quiz tag stores each `"Question | Answer"` string as a
	 * (labels[i], values[i]) pair where labels[] = questions and
	 * values[] = answers. At runtime CF7 picks ONE pair at random
	 * and shows the question; the user must type the answer that
	 * matches values[i] (case/whitespace-trimmed).
	 *
	 * Neural Forms' `quiz_question` is multiple-choice, so the
	 * mapping is asymmetric: we import every Q/A pair as a separate
	 * quiz_question with the answer marked correct, and emit a
	 * warning that asks the user to add distractor options before
	 * publishing (otherwise the question only has one option and
	 * isn't useful as a quiz).
	 *
	 * Returns an empty array when the tag has no labels OR no
	 * values — the caller treats that as a skipped tag.
	 *
	 * @param array $tag CF7Detector descriptor.
	 * @return array[]   Neural Forms quiz_question field definitions.
	 */
	private static function map_quiz_tag( array $tag ): array {
		$labels = is_array( $tag['labels'] ?? null ) ? $tag['labels'] : array();
		$values = is_array( $tag['values'] ?? null ) ? $tag['values'] : array();

		if ( empty( $labels ) || empty( $values ) ) {
			return array();
		}

		$out = array();
		foreach ( $labels as $i => $question ) {
			$question = trim( (string) $question );
			$answer   = isset( $values[ $i ] ) ? trim( (string) $values[ $i ] ) : '';
			if ( $question === '' || $answer === '' ) {
				continue;
			}

			$out[] = array(
				'type'     => 'quiz_question',
				'label'    => $question,
				'required' => (bool) $tag['required'],
				'options'  => array(
					array(
						'value'      => 'option_1',
						'label'      => $answer,
						'is_correct' => true,
					),
				),
			);
		}

		return $out;
	}

	/**
	 * Convert a single CF7 tag descriptor into a Neural Forms field definition.
	 *
	 * @param array $tag  Tag descriptor from CF7Detector::parse_tags().
	 * @return array|null  Neural Forms field definition, or null on failure.
	 */
	public static function map_tag( array $tag ): ?array {
		$cf7_type = $tag['basetype'];
		$nf_type  = self::TYPE_MAP[ $cf7_type ] ?? null;

		if ( ! $nf_type ) {
			return null;
		}

		$field = array(
			'type'     => $nf_type,
			'label'    => self::derive_label( $tag ),
			'required' => (bool) $tag['required'],
		);

		// Placeholder - for text-like fields.
		if ( in_array( $nf_type, array( 'text', 'email', 'url', 'tel', 'number', 'textarea' ), true ) ) {
			$field['placeholder'] = $tag['placeholder'] ?? '';
		}

		// Default value.
		if ( ( $tag['default_value'] ?? '' ) !== '' ) {
			$field['default_value'] = $tag['default_value'];
		}

		// Options - for select, radio, checkboxes.
		if ( in_array( $nf_type, array( 'select', 'radio', 'checkboxes' ), true ) ) {
			$field['options'] = self::map_options( $tag );
		}

		// Numeric constraints - number, range, date.
		if ( in_array( $nf_type, array( 'number', 'range', 'date' ), true ) ) {
			if ( ( $tag['min'] ?? '' ) !== '' ) {
				$field['min'] = $tag['min'];
			}
			if ( ( $tag['max'] ?? '' ) !== '' ) {
				$field['max'] = $tag['max'];
			}
		}

		// Step - number, range.
		if ( in_array( $nf_type, array( 'number', 'range' ), true ) ) {
			if ( ( $tag['step'] ?? '' ) !== '' ) {
				$field['step'] = $tag['step'];
			}
		}

		// File upload constraints.
		if ( $nf_type === 'file' ) {
			if ( ( $tag['filetypes'] ?? '' ) !== '' ) {
				$field['allowed_types'] = $tag['filetypes'];
			}
			if ( ( $tag['limit'] ?? '' ) !== '' ) {
				$field['max_file_size'] = (int) $tag['limit'];
			}
		}

		// Acceptance - no extra properties needed beyond required.
		// Hidden - default_value is already handled above.

		return $field;
	}

	// Private helpers

	/**
	 * Derive a human-readable label from the CF7 tag name.
	 *
	 * Converts kebab/snake-case names into Title Case labels.
	 * e.g. "your-name" => "Your Name", "email_address" => "Email Address"
	 */
	private static function derive_label( array $tag ): string {
		$name = $tag['name'];

		// Replace hyphens and underscores with spaces, then title-case.
		$label = str_replace( array( '-', '_' ), ' ', $name );
		$label = ucwords( $label );

		return $label;
	}

	/**
	 * Map CF7 values/labels into Neural Forms options array.
	 *
	 * CF7 stores option labels in `labels` (if pipe syntax is used)
	 * and raw values in `values`. Pipe syntax: "Label | value".
	 *
	 * @return array[] Each entry: { label, value }
	 */
	private static function map_options( array $tag ): array {
		$values  = $tag['values'] ?? array();
		$labels  = $tag['labels'] ?? array();
		$options = array();

		foreach ( $values as $i => $value ) {
			$options[] = array(
				'label' => ! empty( $labels[ $i ] ) ? $labels[ $i ] : $value,
				'value' => $value,
			);
		}

		return $options;
	}

	/**
	 * Generate a unique field ID from the CF7 tag name.
	 *
	 * Sanitizes the name, then appends a numeric suffix if it collides
	 * with an already-used ID.
	 *
	 * @param string   $name     CF7 tag name.
	 * @param string[] $used_ids IDs already assigned in this form.
	 * @return string
	 */
	private static function unique_id( string $name, array $used_ids ): string {
		// Sanitize: lowercase, replace non-alphanumeric with underscore.
		$base = preg_replace( '/[^a-z0-9_]/', '_', strtolower( $name ) );
		$base = trim( $base, '_' );

		if ( $base === '' ) {
			$base = 'field';
		}

		$candidate = $base;
		$suffix    = 2;

		while ( in_array( $candidate, $used_ids, true ) ) {
			$candidate = $base . '_' . $suffix;
			++$suffix;
		}

		return $candidate;
	}
}
