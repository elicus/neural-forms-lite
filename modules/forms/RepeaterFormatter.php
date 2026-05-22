<?php
namespace NeuralForms\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for rendering a stored Repeater submission
 * back into human-readable form (email, entry detail, CSV).
 *
 * Stored shape (set by SubmissionValidator + SubmitEndpoint::sanitize_repeater):
 *
 *   "team_members": [
 *     { "name": "Arif", "role": "developer",
 *       "resume": { "attachment_id": …, "url": …, "filename": …,
 *                   "mime_type": …, "size": … } },
 *     { "name": "Sara", "role": "designer" }
 *   ]
 *
 * For each child the formatter resolves the wire value back into a
 * display string using the schema's children template:
 *   - radio / select: option label by value.
 *   - checkboxes:     comma-joined option labels.
 *   - checkbox:       "Yes" / "No".
 *   - file:           filename (URL surfaced in entry-detail UI).
 *   - everything else: the value as a string.
 *
 * Two display surfaces (admin + email) and one export surface (CSV)
 * consume this class. The matching React mirror lives in
 * `admin/src/entries/helpers.js#formatRepeaterValueLines`.
 *
 * Unknown rows + unknown children never fatal — mirrors
 * MatrixFormatter's permissive display policy.
 */
final class RepeaterFormatter {

	/** Hard CSV ceiling — matches FormValidator::REPEATER_MAX_ITEMS. */
	const CSV_MAX_ITEMS_FALLBACK = 5;

	/**
	 * Normalised settings: min_items / max_items / columns / button
	 * labels. Defaults match newField('repeater') so a partially-
	 * configured field still renders meaningfully.
	 */
	public static function normalize_settings( array $field ): array {
		$s    = is_array( $field['settings'] ?? null ) ? $field['settings'] : array();
		$min  = isset( $s['min_items'] ) ? (int) $s['min_items'] : 1;
		$max  = isset( $s['max_items'] ) ? (int) $s['max_items'] : 5;
		$cols = isset( $s['columns'] ) ? (int) $s['columns'] : 1;
		if ( $min < 0 ) {
			$min = 0;
		}
		if ( $max < 1 ) {
			$max = 1;
		}
		if ( $cols < 1 ) {
			$cols = 1;
		}
		if ( $cols > 4 ) {
			$cols = 4;
		}
		return array(
			'min_items'           => $min,
			'max_items'           => $max,
			'columns'             => $cols,
			'add_button_label'    => (string) ( $s['add_button_label'] ?? 'Add item' ),
			'remove_button_label' => (string) ( $s['remove_button_label'] ?? 'Remove' ),
		);
	}

	/**
	 * Format a stored Repeater value as an array of items, each with
	 * `child_lines` ready for email / entry detail rendering.
	 *
	 *   [
	 *     [
	 *       'item_index' => 0,      // 0-based
	 *       'child_lines' => [
	 *         [ 'child_id' => 'name',   'child_label' => 'Name',
	 *           'child_type' => 'text', 'display' => 'Arif',
	 *           'raw' => 'Arif' ],
	 *         ...
	 *       ]
	 *     ],
	 *     ...
	 *   ]
	 *
	 * Walks the SCHEMA's children list so unknown wire keys naturally
	 * drop out. Children with no stored value render with display = ''
	 * so the caller can decide whether to show a dash, hide, etc.
	 *
	 * @param mixed $value Stored repeater value.
	 * @param array $field Repeater schema field.
	 * @return array
	 */
	public static function format_value_lines( $value, array $field ): array {
		$children_schema = is_array( $field['fields'] ?? null ) ? $field['fields'] : array();
		$items           = is_array( $value ) ? array_values( $value ) : array();

		$out = array();
		foreach ( $items as $idx => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$child_lines = array();
			foreach ( $children_schema as $child ) {
				if ( ! is_array( $child ) || empty( $child['id'] ) ) {
					continue;
				}
				$child_id      = (string) $child['id'];
				$child_type    = (string) ( $child['type'] ?? 'text' );
				$child_label   = (string) ( $child['label'] ?? $child_id );
				$raw           = array_key_exists( $child_id, $row ) ? $row[ $child_id ] : null;
				$child_lines[] = array(
					'child_id'    => $child_id,
					'child_label' => $child_label,
					'child_type'  => $child_type,
					'raw'         => $raw,
					'display'     => self::child_display_value( $child, $raw ),
				);
			}
			$out[] = array(
				'item_index'  => $idx,
				'child_lines' => $child_lines,
			);
		}
		return $out;
	}

	/**
	 * Resolve one child value into its display string. Looks up
	 * option labels for radio/select/checkboxes, "Yes"/"No" for
	 * checkbox, the filename for file, and the raw string for
	 * everything else. Empty/missing → ''.
	 */
	public static function child_display_value( array $child, $raw ): string {
		$type = (string) ( $child['type'] ?? 'text' );

		if ( null === $raw ) {
			return '';
		}

		switch ( $type ) {
			case 'radio':
			case 'select':
				if ( ! is_scalar( $raw ) ) {
					return '';
				}
				$rv = (string) $raw;
				if ( $rv === '' ) {
					return '';
				}
				return self::option_label( $child, $rv );

			case 'checkboxes':
				$arr   = is_array( $raw ) ? $raw : array();
				$picks = array();
				foreach ( $arr as $v ) {
					if ( ! is_scalar( $v ) ) {
						continue;
					}
					$s = (string) $v;
					if ( $s === '' ) {
						continue;
					}
					$picks[] = self::option_label( $child, $s );
				}
				return implode( ', ', $picks );

			case 'checkbox':
			case 'acceptance':
				$truthy = ( 1 === $raw || $raw === '1' || true === $raw );
				return $truthy ? __( 'Yes', 'neural-forms-lite' ) : __( 'No', 'neural-forms-lite' );

			case 'file':
				if ( ! is_array( $raw ) ) {
					return '';
				}
				return (string) ( $raw['filename'] ?? '' );

			default:
				if ( is_array( $raw ) ) {
					// Defensive — unexpected shape (e.g. checkboxes
					// stored as array under a text field). Join scalars.
					$parts = array();
					foreach ( $raw as $v ) {
						if ( is_scalar( $v ) ) {
							$parts[] = (string) $v;
						}
					}
					return implode( ', ', $parts );
				}
				return (string) $raw;
		}
	}

	/**
	 * Resolve an option value to its label inside the child schema's
	 * options array. Falls back to the value itself when no match —
	 * mirrors the FormRenderer's "value || label" rule.
	 */
	private static function option_label( array $child, string $value ): string {
		$options = is_array( $child['options'] ?? null ) ? $child['options'] : array();
		foreach ( $options as $opt ) {
			if ( ! is_array( $opt ) ) {
				continue;
			}
			$ov  = (string) ( $opt['value'] ?? '' );
			$ol  = (string) ( $opt['label'] ?? '' );
			$eff = $ov !== '' ? $ov : $ol;
			if ( $eff === $value ) {
				return $ol !== '' ? $ol : $eff;
			}
		}
		return $value;
	}

	/**
	 * CSV sub-column descriptor list for a Repeater field. Returns
	 * one entry per (item_index, child) pair up to max_items, where
	 * file children produce TWO sub-columns (__filename + __url) to
	 * match the flat file-field CSV convention.
	 *
	 * Header shape:
	 *   <field_id>__<item_index_1>__<child_id>             // text-like
	 *   <field_id>__<item_index_1>__<child_id>_filename   // file
	 *   <field_id>__<item_index_1>__<child_id>_url        // file
	 *
	 * @return array<int, array{
	 *   header:string,
	 *   item_index:int,          0-based
	 *   child:array,             child schema
	 *   part:string              one of 'value' / 'filename' / 'url'
	 * }>
	 */
	public static function csv_columns( array $field ): array {
		$field_id  = (string) ( $field['id'] ?? '' );
		$settings  = self::normalize_settings( $field );
		$max_items = $settings['max_items'];
		if ( $max_items < 1 ) {
			$max_items = self::CSV_MAX_ITEMS_FALLBACK;
		}
		// Cap matches FormValidator::REPEATER_MAX_ITEMS.
		if ( $max_items > 20 ) {
			$max_items = 20;
		}

		$children_schema = is_array( $field['fields'] ?? null ) ? $field['fields'] : array();
		$out             = array();
		for ( $i = 0; $i < $max_items; $i++ ) {
			$human_idx = $i + 1;
			foreach ( $children_schema as $child ) {
				if ( ! is_array( $child ) || empty( $child['id'] ) ) {
					continue;
				}
				$cid   = (string) $child['id'];
				$ctype = (string) ( $child['type'] ?? 'text' );

				if ( $ctype === 'file' ) {
					$out[] = array(
						'header'     => $field_id . '__' . $human_idx . '__' . $cid . '_filename',
						'item_index' => $i,
						'child'      => $child,
						'part'       => 'filename',
					);
					$out[] = array(
						'header'     => $field_id . '__' . $human_idx . '__' . $cid . '_url',
						'item_index' => $i,
						'child'      => $child,
						'part'       => 'url',
					);
					continue;
				}

				$out[] = array(
					'header'     => $field_id . '__' . $human_idx . '__' . $cid,
					'item_index' => $i,
					'child'      => $child,
					'part'       => 'value',
				);
			}
		}
		return $out;
	}

	/**
	 * Resolve a single CSV cell value for a (repeater, item, child,
	 * part) tuple. Used by ExportEndpoint::build_row to populate one
	 * column at a time.
	 *
	 * @param mixed  $value      Stored repeater value (array of items).
	 * @param int    $item_index 0-based item index.
	 * @param array  $child      Child schema.
	 * @param string $part       'value' / 'filename' / 'url'.
	 */
	public static function csv_row_value( $value, int $item_index, array $child, string $part ): string {
		$items = is_array( $value ) ? array_values( $value ) : array();
		if ( ! isset( $items[ $item_index ] ) || ! is_array( $items[ $item_index ] ) ) {
			return '';
		}
		$child_id = (string) ( $child['id'] ?? '' );
		if ( $child_id === '' ) {
			return '';
		}
		$raw = $items[ $item_index ][ $child_id ] ?? null;

		if ( $part === 'filename' ) {
			return is_array( $raw ) ? (string) ( $raw['filename'] ?? '' ) : '';
		}
		if ( $part === 'url' ) {
			return is_array( $raw ) ? (string) ( $raw['url'] ?? '' ) : '';
		}

		// 'value' — resolve display string per child type.
		return self::child_display_value( $child, $raw );
	}
}
