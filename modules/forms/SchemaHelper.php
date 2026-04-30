<?php
namespace NeuralForms\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Helpers for walking a form schema that may contain row + column layout
 * containers as well as flat fields.
 *
 * Schema shape:
 *
 *   form_schema.fields = [
 *     // Flat field (legacy + still supported).
 *     { id, type: 'text', label, ... },
 *
 *     // Layout row.
 *     {
 *       id: 'row_xxx',
 *       type: 'row',
 *       settings: { columns: 2, gap: 'medium' },
 *       columns: [
 *         { id: 'col_1', width: '50', fields: [ ...fields ] },
 *         { id: 'col_2', width: '50', fields: [ ...fields ] },
 *       ],
 *     },
 *   ]
 *
 * Rows and columns are layout-only - they never appear in submitted entry
 * data, never get validated as fields, never carry a value. Only their
 * nested fields participate in the submission pipeline.
 */
class SchemaHelper {

	const ROW_TYPE = 'row';

	const MIN_COLUMNS = 1;
	const MAX_COLUMNS = 4;

	/** Allowed gap presets on a row. */
	const VALID_GAPS = [ 'none', 'small', 'medium', 'large' ];

	/**
	 * True when a node is a layout row (not a renderable form field).
	 *
	 * @param array $node
	 * @return bool
	 */
	public static function is_row( $node ): bool {
		if ( ! is_array( $node ) ) {
			return false;
		}
		return ( $node['type'] ?? '' ) === self::ROW_TYPE;
	}

	/**
	 * Flatten a schema tree to its leaf field nodes, in source order.
	 *
	 * Rows and columns are skipped; only the actual form fields are
	 * returned, preserving their declaration order across rows.
	 *
	 * @param array $nodes  Top-level form_schema.fields array.
	 * @return array[]      Field arrays.
	 */
	public static function flatten_fields( array $nodes ): array {
		$out = [];
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			if ( self::is_row( $node ) ) {
				$columns = is_array( $node['columns'] ?? null ) ? $node['columns'] : [];
				foreach ( $columns as $col ) {
					if ( ! is_array( $col ) ) {
						continue;
					}
					$col_fields = is_array( $col['fields'] ?? null ) ? $col['fields'] : [];
					foreach ( self::flatten_fields( $col_fields ) as $f ) {
						$out[] = $f;
					}
				}
				continue;
			}
			if ( ! empty( $node['type'] ) ) {
				$out[] = $node;
			}
		}
		return $out;
	}

	/**
	 * Walk every leaf field, calling $callback once per field with its
	 * surrounding context (row id, column index, column id) so callers
	 * who need positional info don't have to re-traverse the tree.
	 *
	 * @param array    $nodes
	 * @param callable $callback  fn( array $field, array $context )
	 */
	public static function walk( array $nodes, callable $callback ): void {
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			if ( self::is_row( $node ) ) {
				$row_id  = (string) ( $node['id'] ?? '' );
				$columns = is_array( $node['columns'] ?? null ) ? $node['columns'] : [];
				foreach ( $columns as $col_idx => $col ) {
					if ( ! is_array( $col ) ) {
						continue;
					}
					$col_id     = (string) ( $col['id'] ?? '' );
					$col_fields = is_array( $col['fields'] ?? null ) ? $col['fields'] : [];
					foreach ( $col_fields as $f ) {
						if ( ! is_array( $f ) || empty( $f['type'] ) ) {
							continue;
						}
						$callback( $f, [
							'row_id'    => $row_id,
							'col_index' => (int) $col_idx,
							'col_id'    => $col_id,
						] );
					}
				}
				continue;
			}
			if ( ! empty( $node['type'] ) ) {
				$callback( $node, [
					'row_id'    => '',
					'col_index' => -1,
					'col_id'    => '',
				] );
			}
		}
	}

	/**
	 * Coerce an arbitrary column count to one of MIN_COLUMNS..MAX_COLUMNS.
	 */
	public static function normalize_column_count( $value ): int {
		$value = (int) $value;
		return max( self::MIN_COLUMNS, min( self::MAX_COLUMNS, $value ) );
	}

	/**
	 * Coerce a row's columns array to the requested count without losing
	 * any user fields.
	 *
	 * Rules:
	 * - When growing, append empty columns with fresh ids.
	 * - When shrinking, every field from the dropped columns is appended
	 *   to the last kept column in order. No field is ever discarded.
	 *
	 * Used by the builder when the admin changes the column count for an
	 * existing row.
	 *
	 * @param array $row             Row node (must be a row).
	 * @param int   $next_col_count  Target column count, will be clamped.
	 * @return array                 Row node with updated columns + settings.
	 */
	public static function normalize_row( array $row, int $next_col_count ): array {
		if ( ! self::is_row( $row ) ) {
			return $row;
		}

		$next  = self::normalize_column_count( $next_col_count );
		$cols  = is_array( $row['columns'] ?? null ) ? array_values( $row['columns'] ) : [];
		$count = count( $cols );

		if ( $count > $next ) {
			$kept   = array_slice( $cols, 0, $next );
			$excess = array_slice( $cols, $next );
			$last   = $next - 1;

			$kept[ $last ]['fields'] = is_array( $kept[ $last ]['fields'] ?? null )
				? $kept[ $last ]['fields']
				: [];
			foreach ( $excess as $col ) {
				$col_fields = is_array( $col['fields'] ?? null ) ? $col['fields'] : [];
				$kept[ $last ]['fields'] = array_merge( $kept[ $last ]['fields'], $col_fields );
			}
			$cols = $kept;
		} elseif ( $count < $next ) {
			while ( count( $cols ) < $next ) {
				$cols[] = [
					'id'     => self::generate_column_id(),
					'fields' => [],
				];
			}
		}

		$row['columns']               = $cols;
		$row['settings']              = is_array( $row['settings'] ?? null ) ? $row['settings'] : [];
		$row['settings']['columns']   = $next;

		return $row;
	}

	/** Default settings for a freshly-created row. */
	public static function default_row_settings( int $col_count ): array {
		return [
			'columns' => self::normalize_column_count( $col_count ),
			'gap'     => 'medium',
		];
	}

	/**
	 * Generate a fresh row id. Pattern matches the field id rules in
	 * FormValidator (lowercase + digits + underscores) so row ids pass the
	 * same validation gate as fields.
	 */
	public static function generate_row_id(): string {
		return 'row_' . strtolower( wp_generate_password( 10, false, false ) );
	}

	public static function generate_column_id(): string {
		return 'col_' . strtolower( wp_generate_password( 10, false, false ) );
	}
}
