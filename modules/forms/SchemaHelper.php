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

	const ROW_TYPE    = 'row';
	const COLUMN_TYPE = 'column';

	const MIN_COLUMNS = 1;
	const MAX_COLUMNS = 4;

	/** Allowed gap presets on a row. */
	const VALID_GAPS  = [ 'none', 'small', 'medium', 'large' ];
	const DEFAULT_GAP = 'medium';

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
	 * True when a node looks like a layout column. Columns historically
	 * had no `type`, so we accept "shape matches column" too: an array
	 * with a `fields` array and either no `type` or `type === "column"`.
	 */
	public static function is_column( $node ): bool {
		if ( ! is_array( $node ) ) {
			return false;
		}
		$type = $node['type'] ?? '';
		if ( $type === self::COLUMN_TYPE ) return true;
		if ( $type !== '' ) return false;
		return is_array( $node['fields'] ?? null );
	}

	public static function is_layout_node( $node ): bool {
		return self::is_row( $node ) || self::is_column( $node );
	}

	/**
	 * True when a node represents a real input field. Rows and columns
	 * are layout-only; anything else with a `type` is a real field.
	 */
	public static function is_real_field( $node ): bool {
		if ( ! is_array( $node ) ) return false;
		$type = $node['type'] ?? '';
		if ( $type === '' ) return false;
		return $type !== self::ROW_TYPE && $type !== self::COLUMN_TYPE;
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

	/**
	 * Normalize a column to canonical shape.
	 *
	 *   - Generates a missing id.
	 *   - Defaults `fields` to [].
	 *   - Drops nested rows inside column.fields (validator also rejects
	 *     them; this is the lenient read path so a partially malformed
	 *     row never fatals the renderer).
	 *   - Preserves `width` as a passthrough (not rendered today).
	 *   - Strips `type` since columns are layout-only.
	 */
	public static function normalize_column( $col ): array {
		if ( ! is_array( $col ) ) {
			return [ 'id' => self::generate_column_id(), 'fields' => [] ];
		}
		$fields = is_array( $col['fields'] ?? null ) ? $col['fields'] : [];
		$fields = array_values( array_filter( $fields, static function ( $f ) {
			return is_array( $f ) && ! self::is_row( $f );
		} ) );
		$out = [
			'id'     => isset( $col['id'] ) && is_string( $col['id'] ) && $col['id'] !== ''
				? $col['id']
				: self::generate_column_id(),
			'fields' => $fields,
		];
		if ( array_key_exists( 'width', $col ) ) {
			$out['width'] = $col['width'];
		}
		return $out;
	}

	/**
	 * Normalize the whole schema. Accepts the canonical shape AND
	 * legacy / alt input variants (row.layout.*, row.gap, row.columns
	 * as number, missing column ids/fields). Output always conforms
	 * to canonical:
	 *
	 *   row.settings.{columns, gap [, alignment]}
	 *   row.columns[] each normalized via normalize_column()
	 *
	 * Pure - returns a new array; the input is not mutated.
	 */
	public static function normalize_schema( array $schema ): array {
		$fields_in = is_array( $schema['fields'] ?? null ) ? $schema['fields'] : [];
		$out       = [];
		foreach ( $fields_in as $node ) {
			if ( ! is_array( $node ) ) continue;
			if ( self::is_row( $node ) ) {
				$out[] = self::normalize_row_canonical( $node );
				continue;
			}
			if ( self::is_real_field( $node ) ) {
				$out[] = $node;
				continue;
			}
			// Anything else (orphan column, junk) is dropped.
		}
		$schema['fields'] = $out;
		return $schema;
	}

	/**
	 * Internal: fold legacy row variants into canonical settings, then
	 * delegate to normalize_row() for the column-count resize step.
	 */
	private static function normalize_row_canonical( array $row ): array {
		$layout      = is_array( $row['layout']   ?? null ) ? $row['layout']   : [];
		$settings_in = is_array( $row['settings'] ?? null ) ? $row['settings'] : [];

		$desired_raw =
			$settings_in['columns']
			?? $layout['columns']
			?? ( is_int( $row['columns'] ?? null ) ? $row['columns'] : null )
			?? ( is_array( $row['columns'] ?? null ) ? count( $row['columns'] ) : 1 );

		$desired = self::normalize_column_count( $desired_raw );

		$gap_raw = $settings_in['gap'] ?? $layout['gap'] ?? $row['gap'] ?? self::DEFAULT_GAP;
		$gap     = in_array( $gap_raw, self::VALID_GAPS, true ) ? $gap_raw : self::DEFAULT_GAP;

		$settings = [ 'columns' => $desired, 'gap' => $gap ];
		$alignment = $settings_in['alignment'] ?? $layout['alignment'] ?? null;
		if ( $alignment !== null && $alignment !== '' ) {
			$settings['alignment'] = $alignment;
		}

		$cols_in = is_array( $row['columns'] ?? null ) ? $row['columns'] : [];
		// If row.columns was a number we normalize to an empty array;
		// resize_row will spawn the right number of empty columns.
		if ( ! is_array( $cols_in ) ) $cols_in = [];

		$cols = array_map( [ self::class, 'normalize_column' ], array_values( $cols_in ) );

		$next = array_merge( $row, [
			'type'     => self::ROW_TYPE,
			'settings' => $settings,
			'columns'  => $cols,
		] );
		unset( $next['layout'], $next['gap'] );

		return self::normalize_row( $next, $desired );
	}
}
