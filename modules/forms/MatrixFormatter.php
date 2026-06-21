<?php
namespace NeuralForms\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for resolving a stored Matrix submission
 * (row_value => column_value | column_value[]) back to human-readable
 * labels, plus the helpers the CSV exporter needs to expand a Matrix
 * field into one column per configured row.
 *
 * Stored shape (set by SubmissionValidator + SubmitEndpoint::sanitize_matrix):
 *   radio:    [ row_value => "column_value" ]
 *   checkbox: [ row_value => [ "col_a", "col_b", ... ] ]
 *
 * The formatter intentionally mirrors FormRenderer's effective-value
 * rule: when an option's `value` is empty, the `label` is used as the
 * effective value. Schemas built before that rule existed still
 * resolve correctly.
 *
 * Three display surfaces consume this class:
 *   - NotificationService::build_fields_summary (email body)
 *   - ExportEndpoint::build_columns + build_row (CSV)
 *   - The admin React entry-detail bundle (JS-side parallel, kept in
 *     sync by Matrix commit 5)
 *
 * Unknown rows + unknown columns never fatal — they are silently
 * dropped from display in keeping with the validator's
 * unknown-row-ignored behaviour.
 */
final class MatrixFormatter {

	/**
	 * Normalised matrix settings.
	 *
	 * @return array{
	 *   selection_type: string,
	 *   rows: array<int, array{value:string,label:string}>,
	 *   columns: array<int, array{value:string,label:string,score:int}>
	 * }
	 */
	public static function normalize_settings( array $field ): array {
		$settings  = is_array( $field['settings'] ?? null ) ? $field['settings'] : array();
		$selection = ( ( $settings['selection_type'] ?? 'radio' ) === 'checkbox' ) ? 'checkbox' : 'radio';

		$rows = array();
		foreach ( ( $settings['rows'] ?? array() ) as $row ) {
			$row   = (array) $row;
			$value = (string) ( $row['value'] ?? '' );
			$label = (string) ( $row['label'] ?? '' );
			$eff   = $value !== '' ? $value : $label;
			if ( $eff === '' ) {
				continue;
			}
			$rows[] = array(
				'value' => $eff,
				'label' => $label !== '' ? $label : $eff,
			);
		}

		$columns = array();
		foreach ( ( $settings['columns'] ?? array() ) as $col ) {
			$col   = (array) $col;
			$value = (string) ( $col['value'] ?? '' );
			$label = (string) ( $col['label'] ?? '' );
			$score = isset( $col['score'] ) && is_numeric( $col['score'] ) ? (int) $col['score'] : 0;
			$eff   = $value !== '' ? $value : $label;
			if ( $eff === '' ) {
				continue;
			}
			$columns[] = array(
				'value' => $eff,
				'label' => $label !== '' ? $label : $eff,
				'score' => $score,
			);
		}

		return array(
			'selection_type' => $selection,
			'rows'           => $rows,
			'columns'        => $columns,
		);
	}

	/**
	 * Map a stored Matrix value to a list of { row_label, value_label }
	 * pairs in schema-row order. Suitable for email + admin entry detail.
	 *
	 * Each entry's `display` is already comma-joined for checkbox mode.
	 * Rows with no stored data appear with `display === ''` so the
	 * caller can decide whether to render "—" / "(no answer)" / skip.
	 *
	 * @return array<int, array{row_value:string, row_label:string, display:string}>
	 */
	public static function format_value_lines( $value, array $field ): array {
		$norm  = self::normalize_settings( $field );
		$value = is_array( $value ) ? $value : array();

		$col_labels = array();
		foreach ( $norm['columns'] as $c ) {
			$col_labels[ $c['value'] ] = $c['label'];
		}

		$lines = array();
		foreach ( $norm['rows'] as $row ) {
			$row_val   = $row['value'];
			$row_label = $row['label'];
			$raw       = $value[ $row_val ] ?? null;
			$display   = '';

			if ( 'checkbox' === $norm['selection_type'] && is_array( $raw ) ) {
				$picked = array();
				foreach ( $raw as $rv ) {
					if ( ! is_scalar( $rv ) ) {
						continue;
					}
					$rv_s = (string) $rv;
					if ( $rv_s === '' ) {
						continue;
					}
					$picked[] = $col_labels[ $rv_s ] ?? $rv_s;
				}
				$display = implode( ', ', $picked );
			} elseif ( 'radio' === $norm['selection_type'] && is_scalar( $raw ) ) {
				$rv_s = (string) $raw;
				if ( $rv_s !== '' ) {
					$display = $col_labels[ $rv_s ] ?? $rv_s;
				}
			}

			$lines[] = array(
				'row_value' => $row_val,
				'row_label' => $row_label,
				'display'   => $display,
			);
		}

		return $lines;
	}

	/**
	 * CSV sub-column list for a Matrix field.
	 *
	 * Returns one entry per configured row:
	 *   [ 'header' => '<field_id>__<row_value>',
	 *     'row_value' => '<row_value>' ]
	 *
	 * Headers use the machine-readable `field_id__row_value` form so
	 * downstream tooling (spreadsheets, scripts) can map a CSV cell
	 * back to the row deterministically — Matrix row labels can contain
	 * spaces or punctuation that would collide with sibling columns.
	 *
	 * @return array<int, array{header:string,row_value:string,row_label:string}>
	 */
	public static function csv_columns( array $field ): array {
		$field_id = (string) ( $field['id'] ?? '' );
		$norm     = self::normalize_settings( $field );
		$cols     = array();
		foreach ( $norm['rows'] as $row ) {
			$cols[] = array(
				'header'    => $field_id . '__' . $row['value'],
				'row_value' => $row['value'],
				'row_label' => $row['label'],
			);
		}
		return $cols;
	}

	/**
	 * CSV cell value for a Matrix row.
	 *
	 * Mirrors format_value_lines() but takes one row_value and returns
	 * the joined string directly. For checkbox mode the values are
	 * joined with ", " (matches NotificationService and other multi-
	 * value field types).
	 */
	public static function csv_row_value( $value, array $field, string $row_value ): string {
		$norm = self::normalize_settings( $field );

		// Build column lookup ONCE per field.
		$col_labels = array();
		foreach ( $norm['columns'] as $c ) {
			$col_labels[ $c['value'] ] = $c['label'];
		}

		$value = is_array( $value ) ? $value : array();
		$raw   = $value[ $row_value ] ?? null;

		if ( 'checkbox' === $norm['selection_type'] && is_array( $raw ) ) {
			$picked = array();
			foreach ( $raw as $rv ) {
				if ( ! is_scalar( $rv ) ) {
					continue;
				}
				$rv_s = (string) $rv;
				if ( $rv_s === '' ) {
					continue;
				}
				$picked[] = $col_labels[ $rv_s ] ?? $rv_s;
			}
			return implode( ', ', $picked );
		}

		if ( 'radio' === $norm['selection_type'] && is_scalar( $raw ) ) {
			$rv_s = (string) $raw;
			if ( $rv_s === '' ) {
				return '';
			}
			return $col_labels[ $rv_s ] ?? $rv_s;
		}

		return '';
	}
}
