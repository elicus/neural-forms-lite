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
	const TYPE_MAP = [
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
	];

	/**
	 * CF7 types that cannot be migrated - silently skipped with a warning.
	 */
	const UNSUPPORTED = [
		'quiz',
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
	];

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
		$fields   = [];
		$skipped  = [];
		$warnings = [];
		$used_ids = [];

		foreach ( $tags as $tag ) {
			$cf7_type = $tag['basetype'];

			// Skip explicitly unsupported types.
			if ( in_array( $cf7_type, self::UNSUPPORTED, true ) ) {
				$skipped[] = [
					'name'     => $tag['name'],
					'cf7_type' => $cf7_type,
					'reason'   => 'Unsupported field type.',
				];
				$warnings[] = sprintf(
					'Skipped "%s" (%s): unsupported field type.',
					$tag['name'],
					$cf7_type
				);
				continue;
			}

			// Skip unknown types not in our map.
			if ( ! isset( self::TYPE_MAP[ $cf7_type ] ) ) {
				$skipped[] = [
					'name'     => $tag['name'],
					'cf7_type' => $cf7_type,
					'reason'   => 'Unknown CF7 field type.',
				];
				$warnings[] = sprintf(
					'Skipped "%s" (%s): unknown field type.',
					$tag['name'],
					$cf7_type
				);
				continue;
			}

			$field = self::map_tag( $tag );
			if ( $field === null ) {
				$skipped[] = [
					'name'     => $tag['name'],
					'cf7_type' => $cf7_type,
					'reason'   => 'Mapping produced no result.',
				];
				continue;
			}

			// Generate a unique field ID.
			$field_id = self::unique_id( $tag['name'], $used_ids );
			$used_ids[] = $field_id;

			$fields[ $field_id ] = $field;
		}

		return [
			'fields'   => $fields,
			'skipped'  => $skipped,
			'warnings' => $warnings,
		];
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

		$field = [
			'type'     => $nf_type,
			'label'    => self::derive_label( $tag ),
			'required' => (bool) $tag['required'],
		];

		// Placeholder - for text-like fields.
		if ( in_array( $nf_type, [ 'text', 'email', 'url', 'tel', 'number', 'textarea' ], true ) ) {
			$field['placeholder'] = $tag['placeholder'] ?? '';
		}

		// Default value.
		if ( ( $tag['default_value'] ?? '' ) !== '' ) {
			$field['default_value'] = $tag['default_value'];
		}

		// Options - for select, radio, checkboxes.
		if ( in_array( $nf_type, [ 'select', 'radio', 'checkboxes' ], true ) ) {
			$field['options'] = self::map_options( $tag );
		}

		// Numeric constraints - number, range, date.
		if ( in_array( $nf_type, [ 'number', 'range', 'date' ], true ) ) {
			if ( ( $tag['min'] ?? '' ) !== '' ) {
				$field['min'] = $tag['min'];
			}
			if ( ( $tag['max'] ?? '' ) !== '' ) {
				$field['max'] = $tag['max'];
			}
		}

		// Step - number, range.
		if ( in_array( $nf_type, [ 'number', 'range' ], true ) ) {
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
		$label = str_replace( [ '-', '_' ], ' ', $name );
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
		$values = $tag['values'] ?? [];
		$labels = $tag['labels'] ?? [];
		$options = [];

		foreach ( $values as $i => $value ) {
			$options[] = [
				'label' => ! empty( $labels[ $i ] ) ? $labels[ $i ] : $value,
				'value' => $value,
			];
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
			$suffix++;
		}

		return $candidate;
	}
}
