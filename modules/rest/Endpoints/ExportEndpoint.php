<?php
namespace NeuralForms\Rest\Endpoints;

use NeuralForms\Forms\FormRepository;
use NeuralForms\Forms\MatrixFormatter;
use NeuralForms\Forms\SchemaHelper;
use NeuralForms\Storage\EntryRepository;
use WP_REST_Request;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * CSV export endpoint for Neural Forms entries (admin-only).
 *
 * GET /neural-forms/v1/entries/export
 *
 * Query params:
 *   form_id  (int, optional)    - scope to a single form; 0 = all forms.
 *   view     (string, optional) - system view filter (inbox, spam, ...).
 *   search   (string, optional) - LIKE search on data + form name.
 *
 * At least one of form_id > 0, view, or search must be present.
 *
 * Behaviour:
 *   - Fetches up to 5 000 matching entries via EntryRepository::list_for_export().
 *   - For a single-form export the dynamic field columns follow the schema field
 *     order stored in FormRepository.
 *   - For an all-forms / search-only export the dynamic columns are the union of
 *     field ids found across all returned entries (first-seen ordering).
 *   - File fields (detected by type in schema or by value shape) are expanded
 *     into two sub-columns: {field_id}_filename and {field_id}_url.
 *   - A UTF-8 BOM is prepended for Excel compatibility.
 *   - The response streams directly with Content-Disposition: attachment so
 *     the browser downloads it without leaving the admin page.
 *   - If the result was truncated at 5 000 rows the header
 *     X-Neural Forms-Export-Truncated: true is included.
 *
 * Fixed columns (always first, in this order):
 *   entry_id, form_id, form_name, submitted_at, status,
 *   ai_category, ai_priority, ai_is_spam, ai_spam_confidence,
 *   needs_reply, is_starred
 */
class ExportEndpoint {

	/**
	 * Fixed CSV columns: header label => entry array key.
	 * Order here defines output order.
	 */
	private const FIXED_COLS = [
		'entry_id'           => 'id',
		'form_id'            => 'form_id',
		'form_name'          => 'form_name',
		'submitted_at'       => 'submitted_at',
		'status'             => 'status',
		'ai_category'        => 'ai_category',
		'ai_priority'        => 'ai_priority',
		'ai_is_spam'         => 'ai_is_spam',
		'ai_spam_confidence' => 'ai_spam_confidence',
		'needs_reply'        => 'needs_reply',
		'is_starred'         => 'is_starred',
	];

	// Route registration

	public function register_routes(): void {
		$ns = \NeuralForms\Rest\RestAPI::NAMESPACE;

		// Must be registered before /entries/(?P<id>[\d]+) in EntriesEndpoint,
		// though in practice "export" never matches the numeric ID pattern.
		register_rest_route( $ns, '/entries/export', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'export' ],
			'permission_callback' => [ $this, 'check_permission' ],
			'args'                => [
				'form_id' => [
					'default'           => 0,
					'sanitize_callback' => 'absint',
				],
				'view'    => [
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => static function ( $value ): bool {
						return $value === ''
							|| in_array( $value, EntryRepository::VALID_VIEWS, true );
					},
				],
				'search'    => [
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
				],
				'date_from' => [
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => static function ( $value ): bool {
						return $value === ''
							|| (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value );
					},
				],
				'date_to'    => [
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => static function ( $value ): bool {
						return $value === ''
							|| (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value );
					},
				],
				'sort_order' => [
					'default'           => 'desc',
					'sanitize_callback' => static function ( $v ): string {
						$v = strtolower( trim( (string) $v ) );
						return in_array( $v, [ 'asc', 'desc' ], true ) ? $v : 'desc';
					},
				],
			],
		] );
	}

	// Handler

	/**
	 * Stream a CSV file containing matching entries.
	 *
	 * Outputs headers and body directly, then exits.  This bypasses WordPress's
	 * JSON serialisation pipeline, which is intentional for file downloads.
	 * The permission_callback has already run before this method is reached.
	 *
	 * @param WP_REST_Request $request
	 * @return void|WP_Error  Returns a WP_Error only for validation failures
	 *                        that occur before output starts.
	 */
	public function export( WP_REST_Request $request ) {
		$form_id    = (int)    $request->get_param( 'form_id' );
		$view       = (string) $request->get_param( 'view' );
		$search     = (string) $request->get_param( 'search' );
		$date_from  = (string) $request->get_param( 'date_from' );
		$date_to    = (string) $request->get_param( 'date_to' );
		$sort_order = (string) $request->get_param( 'sort_order' );

		// At least one filter is required - date range alone is not enough.
		if ( $form_id < 1 && $view === '' && $search === '' ) {
			return new WP_Error(
				'missing_filter',
				__( 'A valid form_id, view, or search term is required.', 'neural-forms-lite' ),
				[ 'status' => 400 ]
			);
		}

		// Fetch entries - capped at 5 000 by list_for_export().
		$entries   = EntryRepository::list_for_export( $form_id, [
			'view'       => $view,
			'search'     => $search,
			'date_from'  => $date_from,
			'date_to'    => $date_to,
			'sort_order' => $sort_order,
		] );
		$truncated = count( $entries ) === 5000;

		// Discover dynamic columns and build the header row.
		[ $dynamic_cols, $header_row ] = $this->build_columns( $form_id, $entries );

		// Filename: prefer form name when available.
		$form_name_for_file = '';
		if ( $form_id > 0 && ! empty( $entries ) ) {
			$form_name_for_file = (string) ( $entries[0]['form_name'] ?? '' );
		}
		$filename = $this->build_filename( $form_id, $view, $search, $form_name_for_file, $date_from, $date_to );

		// ' Stream the CSV '
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'X-Content-Type-Options: nosniff' );

		if ( $truncated ) {
			header( 'X-Neural Forms-Export-Truncated: true' );
		}

		// UTF-8 BOM - makes Excel open the file with correct encoding without
		// requiring the user to go through the import wizard.
		echo "\xEF\xBB\xBF";

		// CSV body output. Values are encoded by csv_row() per RFC 4180
		// (quotes / commas / newlines escaped); HTML escaping would corrupt
		// the file (encoding `&` as `&amp;` etc.). Content-Type is text/csv.
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::csv_row( $header_row );
		foreach ( $entries as $entry ) {
			echo self::csv_row( $this->build_row( $entry, $dynamic_cols ) );
		}
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped

		exit;
	}

	/**
	 * Encode an array of values as one CSV record (RFC 4180), including the
	 * trailing CRLF. We hand-roll this instead of using fputcsv() because
	 * fputcsv() requires a stream handle and the canonical pattern of
	 * fopen( 'php://output' ) trips WordPress.WP.AlternativeFunctions
	 * sniffs that have no real bearing on a streaming download.
	 *
	 * Quoting rule: a field is quoted when it contains a comma, double-quote,
	 * carriage return, or line feed. Embedded double-quotes are doubled.
	 *
	 * @param string[] $row
	 * @return string  Encoded CSV record terminated by "\r\n".
	 */
	private static function csv_row( array $row ): string {
		$cells = [];
		foreach ( $row as $value ) {
			$value = (string) $value;
			if ( preg_match( '/[",\r\n]/', $value ) ) {
				$cells[] = '"' . str_replace( '"', '""', $value ) . '"';
			} else {
				$cells[] = $value;
			}
		}
		return implode( ',', $cells ) . "\r\n";
	}

	// Column building

	/**
	 * Build the dynamic column list and the complete CSV header row.
	 *
	 * For a single-form export the dynamic columns follow the schema field
	 * order from FormRepository so the CSV mirrors the form layout.
	 *
	 * For an all-forms / search-only export the dynamic columns are the union
	 * of field ids discovered across all returned entries (first-seen order).
	 *
	 * File fields are expanded into two sub-columns:
	 *   {field_id}_filename  and  {field_id}_url
	 *
	 * @param int   $form_id
	 * @param array $entries  Decoded entry rows from list_for_export().
	 * @return array{ 0: array, 1: string[] }
	 *   [0] - $dynamic_cols: array of { header, source_id, part }.
	 *          part is null (scalar), 'filename', or 'url'.
	 *   [1] - $header_row: flat string array for fputcsv().
	 */
	private function build_columns( int $form_id, array $entries ): array {
		$dynamic_cols = [];

		if ( $form_id > 0 ) {
			// Single-form export: use schema order. Flatten the tree so
			// rows + columns don't pollute the CSV header with layout ids -
			// only real fields become columns.
			$form          = FormRepository::find( $form_id );
			$schema_fields = $form
				? SchemaHelper::flatten_fields(
					is_array( $form['form_schema']['fields'] ?? null ) ? $form['form_schema']['fields'] : []
				  )
				: [];

			foreach ( $schema_fields as $field ) {
				$field    = (array) $field;
				$field_id = $field['id'] ?? '';

				if ( $field_id === '' ) {
					continue;
				}

				$type = (string) ( $field['type'] ?? '' );

				if ( $type === 'file' ) {
					$dynamic_cols[] = [ 'header' => $field_id . '_filename', 'source_id' => $field_id, 'part' => 'filename' ];
					$dynamic_cols[] = [ 'header' => $field_id . '_url',      'source_id' => $field_id, 'part' => 'url'      ];
				} elseif ( $type === 'matrix' ) {
					// Matrix expands to one CSV column per configured row.
					// `field` is captured so build_row can map stored
					// column values back to labels without re-fetching
					// the schema.
					foreach ( MatrixFormatter::csv_columns( $field ) as $mc ) {
						$dynamic_cols[] = [
							'header'    => $mc['header'],
							'source_id' => $field_id,
							'part'      => 'matrix_row',
							'row_value' => $mc['row_value'],
							'field'     => $field,
						];
					}
				} else {
					$dynamic_cols[] = [ 'header' => $field_id, 'source_id' => $field_id, 'part' => null ];
				}
			}
		} else {
			// ' All-forms / search-only: union of field ids from entries '
			// First pass: collect all field ids in first-seen order; detect file
			// fields by value shape (array with both 'filename' and 'url' keys).
			$seen        = [];  // field_id => true (ordered by first appearance)
			$file_fields = [];  // field_id => true for confirmed file fields

			foreach ( $entries as $entry ) {
				foreach ( $entry['data'] as $field_id => $value ) {
					if ( ! isset( $seen[ $field_id ] ) ) {
						$seen[ $field_id ] = true;

						if ( is_array( $value )
							&& array_key_exists( 'filename', $value )
							&& array_key_exists( 'url', $value )
						) {
							$file_fields[ $field_id ] = true;
						}
					}
				}
			}

			foreach ( array_keys( $seen ) as $field_id ) {
				if ( isset( $file_fields[ $field_id ] ) ) {
					$dynamic_cols[] = [ 'header' => $field_id . '_filename', 'source_id' => $field_id, 'part' => 'filename' ];
					$dynamic_cols[] = [ 'header' => $field_id . '_url',      'source_id' => $field_id, 'part' => 'url'      ];
				} else {
					$dynamic_cols[] = [ 'header' => $field_id, 'source_id' => $field_id, 'part' => null ];
				}
			}
		}

		// Build the complete header row: fixed columns first, then dynamic.
		$header_row = array_keys( self::FIXED_COLS );
		foreach ( $dynamic_cols as $col ) {
			$header_row[] = $col['header'];
		}

		return [ $dynamic_cols, $header_row ];
	}

	// Row building

	/**
	 * Assemble a single CSV data row for an entry.
	 *
	 * Fixed columns come first in FIXED_COLS order; dynamic columns follow.
	 * File field sub-columns extract 'filename' or 'url' from the metadata array.
	 * All other values go through normalize_value().
	 *
	 * @param array $entry        Decoded entry row (data already an array).
	 * @param array $dynamic_cols Column metadata from build_columns().
	 * @return string[]
	 */
	private function build_row( array $entry, array $dynamic_cols ): array {
		// Fixed columns.
		$row = [];
		foreach ( self::FIXED_COLS as $entry_key ) {
			$row[] = $this->normalize_value( $entry[ $entry_key ] ?? null );
		}

		// Dynamic columns.
		$data = $entry['data'] ?? [];
		foreach ( $dynamic_cols as $col ) {
			$raw = $data[ $col['source_id'] ] ?? null;

			if ( $col['part'] === 'filename' ) {
				$row[] = self::escape_csv_formula( is_array( $raw ) ? (string) ( $raw['filename'] ?? '' ) : '' );
			} elseif ( $col['part'] === 'url' ) {
				$row[] = self::escape_csv_formula( is_array( $raw ) ? (string) ( $raw['url'] ?? '' ) : '' );
			} elseif ( $col['part'] === 'matrix_row' ) {
				$field    = is_array( $col['field'] ?? null ) ? $col['field'] : [];
				$row_val  = (string) ( $col['row_value'] ?? '' );
				$rendered = MatrixFormatter::csv_row_value( $raw, $field, $row_val );
				$row[]    = self::escape_csv_formula( $rendered );
			} else {
				$row[] = $this->normalize_value( $raw );
			}
		}

		return $row;
	}

	// Value normalisation

	/**
	 * Convert a single entry field value to a human-readable CSV string.
	 *
	 * Rules:
	 *   null / ''           '->-> ''
	 *   bool                '->-> '1' / '0'
	 *   int / float         '->-> cast to string
	 *   file metadata array '->-> '' (handled upstream by _filename/_url columns)
	 *   plain array         '->-> comma-separated string values
	 *   string              '->-> as-is
	 *
	 * @param mixed $value
	 * @return string
	 */
	private function normalize_value( $value ): string {
		if ( $value === null || $value === '' ) {
			return '';
		}

		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}

		if ( is_array( $value ) ) {
			// File metadata objects are split into sub-columns upstream.
			// If one reaches here unexpectedly, emit an empty string rather than
			// a raw serialisation that would produce "[object Object]" in some editors.
			if ( array_key_exists( 'filename', $value ) && array_key_exists( 'url', $value ) ) {
				return '';
			}

			// Nested arrays (e.g. matrix on the all-forms export path
			// where the per-row sub-columns can't be built without
			// schema). Flatten one level so the CSV stays string-only:
			// scalars stringify directly; arrays-of-scalars join with
			// commas. This is best-effort — per-form CSV expands matrix
			// into proper sub-columns via the matrix_row case above.
			$parts = [];
			foreach ( $value as $v ) {
				if ( is_scalar( $v ) ) {
					$parts[] = (string) $v;
				} elseif ( is_array( $v ) ) {
					$parts[] = implode( ', ', array_map( 'strval', array_filter( $v, 'is_scalar' ) ) );
				}
			}
			return implode( ', ', $parts );
		}

		return self::escape_csv_formula( (string) $value );
	}

	/**
	 * Prevent CSV formula injection.
	 *
	 * Spreadsheet applications (Excel, Sheets, Calc) treat cells starting with
	 * certain characters as formulas. Prefixing with a single-quote forces the
	 * cell to be treated as a text literal.
	 */
	private static function escape_csv_formula( string $value ): string {
		if ( $value !== '' && in_array( $value[0], [ '=', '+', '-', '@', "\t", "\r" ], true ) ) {
			return "'" . $value;
		}

		return $value;
	}

	// Filename generation

	/**
	 * Generate a readable download filename.
	 *
	 * Pattern (no range):   neural-forms-{scope}-{view}-{today}.csv
	 * Pattern (with range): neural-forms-{scope}-{view}-{date_from}-to-{date_to}.csv
	 *
	 * Examples:
	 *   neural-forms-contact-form-inbox-2026-04-16.csv
	 *   neural-forms-all-forms-inbox-2026-03-17-to-2026-04-16.csv
	 *   neural-forms-all-forms-needs-reply-2026-04-01-to-2026-04-30.csv
	 *
	 * @param int    $form_id
	 * @param string $view
	 * @param string $search
	 * @param string $form_name  Human-readable form name (used when form_id > 0).
	 * @param string $date_from  YYYY-MM-DD or ''.
	 * @param string $date_to    YYYY-MM-DD or ''.
	 * @return string
	 */
	private function build_filename(
		int    $form_id,
		string $view,
		string $search,
		string $form_name,
		string $date_from = '',
		string $date_to   = ''
	): string {
		if ( $form_id > 0 && $form_name !== '' ) {
			$scope = sanitize_title( $form_name );
		} elseif ( $search !== '' ) {
			$scope = 'search';
		} else {
			$scope = 'all-forms';
		}

		$view_slug = $view !== '' ? $view : 'all';

		// Date suffix: include the range when provided, otherwise today's date.
		if ( $date_from !== '' && $date_to !== '' ) {
			$date_suffix = $date_from . '-to-' . $date_to;
		} elseif ( $date_from !== '' ) {
			$date_suffix = 'from-' . $date_from;
		} elseif ( $date_to !== '' ) {
			$date_suffix = 'to-' . $date_to;
		} else {
			$date_suffix = gmdate( 'Y-m-d' );
		}

		return 'neural-forms-' . $scope . '-' . $view_slug . '-' . $date_suffix . '.csv';
	}

	// Permission

	/**
	 * Admin-only - requires the same capability used by EntriesEndpoint.
	 *
	 * @return true|WP_Error
	 */
	public function check_permission() {
		if ( current_user_can( NEURAL_FORMS_CAP_MANAGE_FORMS ) ) {
			return true;
		}

		return new WP_Error(
			'permission_denied',
			__( 'You do not have permission to export entries.', 'neural-forms-lite' ),
			[ 'status' => 403 ]
		);
	}
}
