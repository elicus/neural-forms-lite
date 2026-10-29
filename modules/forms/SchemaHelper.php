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

	const ROW_TYPE            = 'row';
	const COLUMN_TYPE         = 'column';
	const STEP_TYPE           = 'step';
	const SECTION_TYPE        = 'section';
	const WELCOME_SCREEN_TYPE = 'welcome_screen';

	const MIN_COLUMNS = 1;
	const MAX_COLUMNS = 4;

	/** Allowed gap presets on a row. */
	const VALID_GAPS  = array( 'none', 'small', 'medium', 'large' );
	const DEFAULT_GAP = 'medium';

	/** Defaults for a step's settings object. */
	const DEFAULT_STEP_BUTTON_NEXT     = 'Next';
	const DEFAULT_STEP_BUTTON_PREVIOUS = 'Back';

	/** Allowed visual styles on a section. */
	const VALID_SECTION_STYLES  = array( 'default', 'minimal', 'boxed' );
	const DEFAULT_SECTION_STYLE = 'default';

	/** Poll field's nested `settings` object. Real-field, not a layout. */
	const POLL_VISIBILITIES       = array( 'after_vote', 'always', 'never' );
	const POLL_DEFAULT_VISIBILITY = 'after_vote';
	const POLL_CHART_TYPES        = array( 'bar', 'percentage' );
	const POLL_DEFAULT_CHART_TYPE = 'bar';

	/** Quiz Question field's nested `settings` object. */
	const QUIZ_SHOW_CORRECT         = array( 'after_submit', 'never' );
	const QUIZ_DEFAULT_SHOW_CORRECT = 'after_submit';
	const QUIZ_DEFAULT_POINTS       = 1;

	/**
	 * Quiz-form-level settings. Phase 3 adds advanced score-quiz config
	 * at form_schema.settings.quiz (NOT form.settings — that namespace
	 * is reserved for submission/security/email settings).
	 */
	const QUIZ_DEFAULT_PASSING_SCORE  = 70;
	const QUIZ_DEFAULT_SHOW_PASS_FAIL = false;

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
	 * True when a node is a layout step. Steps are top-level layout
	 * containers - they own a `fields[]` array of real fields and rows
	 * (rows can in turn nest columns). Steps NEVER appear inside other
	 * steps, columns, or rows; the validator rejects such nesting.
	 */
	public static function is_step( $node ): bool {
		if ( ! is_array( $node ) ) {
			return false;
		}
		return ( $node['type'] ?? '' ) === self::STEP_TYPE;
	}

	/**
	 * True when a node is a layout section (visual divider / heading +
	 * description block). Sections are leaf layout nodes - they do
	 * NOT contain fields in 1.1.0. They appear as siblings of real
	 * fields at root, inside step.fields, or inside column.fields.
	 */
	/**
	 * True when a node is a Welcome Screen — a special top-level
	 * display block that renders before the form fields and reveals
	 * them on Start. Welcome screens never collect data and are
	 * excluded from validation, submissions, entries, exports, AI
	 * analysis, and notifications.
	 */
	public static function is_welcome_screen( $node ): bool {
		if ( ! is_array( $node ) ) {
			return false;
		}
		return ( $node['type'] ?? '' ) === self::WELCOME_SCREEN_TYPE;
	}

	public static function is_section( $node ): bool {
		if ( ! is_array( $node ) ) {
			return false;
		}
		return ( $node['type'] ?? '' ) === self::SECTION_TYPE;
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
		if ( $type === self::COLUMN_TYPE ) {
			return true;
		}
		if ( $type !== '' ) {
			return false;
		}
		return is_array( $node['fields'] ?? null );
	}

	public static function is_layout_node( $node ): bool {
		return self::is_row( $node )
			|| self::is_column( $node )
			|| self::is_step( $node )
			|| self::is_section( $node )
			|| self::is_welcome_screen( $node );
	}

	/**
	 * True when a node represents a real input field. Rows, columns,
	 * steps, and sections are layout / content-only; anything else
	 * with a `type` is a real field.
	 */
	public static function is_real_field( $node ): bool {
		if ( ! is_array( $node ) ) {
			return false;
		}
		$type = $node['type'] ?? '';
		if ( $type === '' ) {
			return false;
		}
		return $type !== self::ROW_TYPE
			&& $type !== self::COLUMN_TYPE
			&& $type !== self::STEP_TYPE
			&& $type !== self::SECTION_TYPE
			&& $type !== self::WELCOME_SCREEN_TYPE;
	}

	/**
	 * Locate the welcome screen node inside a top-level fields array,
	 * or null when the form has none. Welcome screens MUST be top-level
	 * (never inside row/step/section), so we don't recurse.
	 *
	 * @param array $fields Top-level form_schema.fields
	 * @return array|null   The welcome_screen node or null.
	 */
	public static function find_welcome_screen( array $fields ): ?array {
		foreach ( $fields as $node ) {
			if ( self::is_welcome_screen( $node ) ) {
				return is_array( $node ) ? $node : null;
			}
		}
		return null;
	}

	/**
	 * Flatten a schema tree to its leaf field nodes, in source order.
	 *
	 * Steps, rows, and columns are skipped; only the actual form
	 * fields are returned, preserving their declaration order across
	 * the layout tree (step1.field, step1.row.col.field, step2.field).
	 *
	 * @param array $nodes  Top-level form_schema.fields array.
	 * @return array[]      Field arrays.
	 */
	public static function flatten_fields( array $nodes ): array {
		$out = array();
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			if ( self::is_step( $node ) ) {
				$step_fields = is_array( $node['fields'] ?? null ) ? $node['fields'] : array();
				foreach ( self::flatten_fields( $step_fields ) as $f ) {
					$out[] = $f;
				}
				continue;
			}
			if ( self::is_row( $node ) ) {
				$columns = is_array( $node['columns'] ?? null ) ? $node['columns'] : array();
				foreach ( $columns as $col ) {
					if ( ! is_array( $col ) ) {
						continue;
					}
					$col_fields = is_array( $col['fields'] ?? null ) ? $col['fields'] : array();
					foreach ( self::flatten_fields( $col_fields ) as $f ) {
						$out[] = $f;
					}
				}
				continue;
			}
			if ( self::is_real_field( $node ) ) {
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
				$columns = is_array( $node['columns'] ?? null ) ? $node['columns'] : array();
				foreach ( $columns as $col_idx => $col ) {
					if ( ! is_array( $col ) ) {
						continue;
					}
					$col_id     = (string) ( $col['id'] ?? '' );
					$col_fields = is_array( $col['fields'] ?? null ) ? $col['fields'] : array();
					foreach ( $col_fields as $f ) {
						if ( ! is_array( $f ) || empty( $f['type'] ) ) {
							continue;
						}
						$callback(
							$f,
							array(
								'row_id'    => $row_id,
								'col_index' => (int) $col_idx,
								'col_id'    => $col_id,
							)
						);
					}
				}
				continue;
			}
			if ( ! empty( $node['type'] ) ) {
				$callback(
					$node,
					array(
						'row_id'    => '',
						'col_index' => -1,
						'col_id'    => '',
					)
				);
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
		$cols  = is_array( $row['columns'] ?? null ) ? array_values( $row['columns'] ) : array();
		$count = count( $cols );

		if ( $count > $next ) {
			$kept   = array_slice( $cols, 0, $next );
			$excess = array_slice( $cols, $next );
			$last   = $next - 1;

			$kept[ $last ]['fields'] = is_array( $kept[ $last ]['fields'] ?? null )
				? $kept[ $last ]['fields']
				: array();
			foreach ( $excess as $col ) {
				$col_fields              = is_array( $col['fields'] ?? null ) ? $col['fields'] : array();
				$kept[ $last ]['fields'] = array_merge( $kept[ $last ]['fields'], $col_fields );
			}
			$cols = $kept;
		} elseif ( $count < $next ) {
			// $cols grows inside the loop — count() must be re-evaluated
			// each iteration so the loop terminates at the new target.
			// phpcs:ignore Squiz.PHP.DisallowSizeFunctionsInLoops.Found
			while ( count( $cols ) < $next ) {
				$cols[] = array(
					'id'     => self::generate_column_id(),
					'fields' => array(),
				);
			}
		}

		$row['columns']             = $cols;
		$row['settings']            = is_array( $row['settings'] ?? null ) ? $row['settings'] : array();
		$row['settings']['columns'] = $next;

		return $row;
	}

	/** Default settings for a freshly-created row. */
	public static function default_row_settings( int $col_count ): array {
		return array(
			'columns' => self::normalize_column_count( $col_count ),
			'gap'     => 'medium',
		);
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

	public static function generate_step_id(): string {
		return 'step_' . strtolower( wp_generate_password( 10, false, false ) );
	}

	public static function generate_section_id(): string {
		return 'section_' . strtolower( wp_generate_password( 10, false, false ) );
	}

	/** Default settings for a freshly-created section. */
	public static function default_section_settings(): array {
		return array( 'style' => self::DEFAULT_SECTION_STYLE );
	}

	/**
	 * Default `field.settings` for a freshly-created poll field.
	 *
	 * `allow_multiple` is persisted-but-ignored in v1. Keeping the key
	 * in the schema avoids a migration when v2 turns it on.
	 */
	public static function default_poll_settings(): array {
		return array(
			'results_visibility' => self::POLL_DEFAULT_VISIBILITY,
			'chart_type'         => self::POLL_DEFAULT_CHART_TYPE,
			'allow_multiple'     => false,
			'randomize_options'  => false,
		);
	}

	/**
	 * Default `field.settings` for a freshly-created quiz_question.
	 *
	 * `points` defaults to 1; the inspector clamps to int >= 0.
	 * `show_correct_answer` defaults to "after_submit" so the user
	 * sees feedback by default; "never" hides correct details on the
	 * frontend AND in the submit response payload.
	 */
	public static function default_quiz_settings(): array {
		return array(
			'points'              => self::QUIZ_DEFAULT_POINTS,
			'explanation'         => '',
			'show_correct_answer' => self::QUIZ_DEFAULT_SHOW_CORRECT,
			'randomize_options'   => false,
		);
	}

	/**
	 * Coerce a quiz_question field's nested `settings` object to
	 * canonical shape. Lenient: unknown keys dropped, unknown enum
	 * values fall back to defaults, points coerced to non-negative int.
	 */
	public static function normalize_quiz_settings( $settings ): array {
		$in = is_array( $settings ) ? $settings : array();

		$points = isset( $in['points'] ) ? $in['points'] : self::QUIZ_DEFAULT_POINTS;
		if ( ! is_numeric( $points ) || (int) $points < 0 ) {
			$points = 0;
		}

		$show = isset( $in['show_correct_answer'] ) && is_string( $in['show_correct_answer'] )
			? $in['show_correct_answer']
			: self::QUIZ_DEFAULT_SHOW_CORRECT;
		if ( ! in_array( $show, self::QUIZ_SHOW_CORRECT, true ) ) {
			$show = self::QUIZ_DEFAULT_SHOW_CORRECT;
		}

		return array(
			'points'              => (int) $points,
			'explanation'         => isset( $in['explanation'] ) && is_string( $in['explanation'] ) ? $in['explanation'] : '',
			'show_correct_answer' => $show,
			'randomize_options'   => ! empty( $in['randomize_options'] ),
		);
	}

	/**
	 * Allowed values for form_schema.settings.ai_processing.mode.
	 * Stored on the schema so the resolver can decide whether to
	 * enqueue an AI job when an entry is submitted.
	 *
	 *   inherit         → fall back to the global ai_auto_process setting
	 *   disabled        → never run AI for this form (auto OR manual)
	 *   every_entry     → analyze every submission automatically
	 *   manual          → no automatic AI; admins can analyze on demand
	 *   final_results   → no per-entry AI; aggregate insights only
	 */
	const AI_PROCESSING_MODES        = array(
		'inherit',
		'disabled',
		'every_entry',
		'manual',
		'final_results',
	);
	const AI_PROCESSING_DEFAULT_MODE = 'inherit';

	/**
	 * Default form_schema.settings.ai_processing — per-form AI mode.
	 *
	 * `inherit` is the only safe default: it preserves whatever
	 * existing forms were doing (global ai_auto_process drives
	 * behaviour) and lets users opt in to disabled/every_entry/
	 * manual/final_results explicitly.
	 *
	 * Per-form override gated by Capabilities::AI_PROCESS_ENTRIES.
	 * Lite stores the value but never runs entry analysis itself —
	 * the resolver short-circuits to "unavailable" when the cap is
	 * false. See AiProcessingResolver.
	 */
	public static function default_ai_processing_settings(): array {
		return array( 'mode' => self::AI_PROCESSING_DEFAULT_MODE );
	}

	/**
	 * Coerce form_schema.settings.ai_processing to canonical shape.
	 *
	 * - mode: must be one of AI_PROCESSING_MODES; unknown clamps to
	 *   `inherit` (the safest default — preserves existing behaviour
	 *   driven by the global toggle).
	 * - unknown keys dropped.
	 * - missing/non-array input → defaults.
	 */
	public static function normalize_ai_processing_settings( $settings ): array {
		$in   = is_array( $settings ) ? $settings : array();
		$mode = isset( $in['mode'] ) ? (string) $in['mode'] : self::AI_PROCESSING_DEFAULT_MODE;
		if ( ! in_array( $mode, self::AI_PROCESSING_MODES, true ) ) {
			$mode = self::AI_PROCESSING_DEFAULT_MODE;
		}
		return array( 'mode' => $mode );
	}

	/**
	 * Default form_schema.settings.quiz — advanced score-quiz config
	 * applied at the form level (pass/fail, result messages).
	 *
	 * Defaults are conservative: show_pass_fail=false leaves the
	 * existing Phase 1/2 submit response shape unchanged for forms
	 * that haven't opted in to advanced scoring.
	 */
	public static function default_quiz_form_settings(): array {
		return array(
			'show_pass_fail'  => self::QUIZ_DEFAULT_SHOW_PASS_FAIL,
			'passing_score'   => self::QUIZ_DEFAULT_PASSING_SCORE,
			'result_messages' => array(),
		);
	}

	/**
	 * Coerce form_schema.settings.quiz to canonical shape.
	 *
	 *   - show_pass_fail: strict bool cast
	 *   - passing_score:  numeric, clamped to 0..100, defaults to 70
	 *   - result_messages: array of {min, max, title, message}, each
	 *     with min/max clamped 0..100. Rows missing BOTH title and
	 *     message are dropped silently — they wouldn't render anyway.
	 *     A row with min > max is skipped (would never match).
	 *
	 * Lenient: unknown keys dropped, no fatals on malformed input.
	 */
	public static function normalize_quiz_form_settings( $settings ): array {
		$in = is_array( $settings ) ? $settings : array();

		$score = isset( $in['passing_score'] ) ? $in['passing_score'] : self::QUIZ_DEFAULT_PASSING_SCORE;
		if ( ! is_numeric( $score ) ) {
			$score = self::QUIZ_DEFAULT_PASSING_SCORE;
		}
		$score = (int) round( (float) $score );
		if ( $score < 0 ) {
			$score = 0;
		}
		if ( $score > 100 ) {
			$score = 100;
		}

		$msgs_in = is_array( $in['result_messages'] ?? null ) ? $in['result_messages'] : array();
		$msgs    = array();
		foreach ( $msgs_in as $m ) {
			if ( ! is_array( $m ) ) {
				continue;
			}

			$min = isset( $m['min'] ) && is_numeric( $m['min'] ) ? (int) round( (float) $m['min'] ) : 0;
			$max = isset( $m['max'] ) && is_numeric( $m['max'] ) ? (int) round( (float) $m['max'] ) : 100;
			if ( $min < 0 ) {
				$min = 0;
			}
			if ( $min > 100 ) {
				$min = 100;
			}
			if ( $max < 0 ) {
				$max = 0;
			}
			if ( $max > 100 ) {
				$max = 100;
			}
			if ( $min > $max ) {
				continue; // Unmatchable range — skip.
			}

			$title   = isset( $m['title'] ) && is_string( $m['title'] ) ? trim( $m['title'] ) : '';
			$message = isset( $m['message'] ) && is_string( $m['message'] ) ? trim( $m['message'] ) : '';
			if ( $title === '' && $message === '' ) {
				continue;
			}

			$msgs[] = array(
				'min'     => $min,
				'max'     => $max,
				'title'   => $title,
				'message' => $message,
			);
		}

		return array(
			'show_pass_fail'  => ! empty( $in['show_pass_fail'] ),
			'passing_score'   => $score,
			'result_messages' => $msgs,
		);
	}

	/**
	 * Coerce a poll field's nested `settings` object to canonical shape.
	 *
	 * Unknown keys are dropped; unknown enum values fall back to defaults.
	 * Booleans are cast strictly so a JSON-encoded "false" string from
	 * a misbehaving client doesn't read as truthy.
	 */
	public static function normalize_poll_settings( $settings ): array {
		$in = is_array( $settings ) ? $settings : array();

		$visibility = isset( $in['results_visibility'] ) && is_string( $in['results_visibility'] )
			? $in['results_visibility']
			: self::POLL_DEFAULT_VISIBILITY;
		if ( ! in_array( $visibility, self::POLL_VISIBILITIES, true ) ) {
			$visibility = self::POLL_DEFAULT_VISIBILITY;
		}

		$chart = isset( $in['chart_type'] ) && is_string( $in['chart_type'] )
			? $in['chart_type']
			: self::POLL_DEFAULT_CHART_TYPE;
		if ( ! in_array( $chart, self::POLL_CHART_TYPES, true ) ) {
			$chart = self::POLL_DEFAULT_CHART_TYPE;
		}

		return array(
			'results_visibility' => $visibility,
			'chart_type'         => $chart,
			'allow_multiple'     => ! empty( $in['allow_multiple'] ),
			'randomize_options'  => ! empty( $in['randomize_options'] ),
		);
	}

	/**
	 * Normalize a section to canonical shape.
	 *
	 * - id: filled if missing
	 * - title / description: defaulted to '' if missing
	 * - settings.style: clamped to VALID_SECTION_STYLES
	 * - extra fields[]/columns[] are dropped (sections are leaves)
	 */
	public static function normalize_section( $section ): array {
		if ( ! is_array( $section ) ) {
			return array(
				'id'          => self::generate_section_id(),
				'type'        => self::SECTION_TYPE,
				'title'       => '',
				'description' => '',
				'settings'    => self::default_section_settings(),
			);
		}

		$settings_in = is_array( $section['settings'] ?? null ) ? $section['settings'] : array();
		$style       = isset( $settings_in['style'] ) && is_string( $settings_in['style'] ) && $settings_in['style'] !== ''
			? $settings_in['style']
			: self::DEFAULT_SECTION_STYLE;
		if ( ! in_array( $style, self::VALID_SECTION_STYLES, true ) ) {
			$style = self::DEFAULT_SECTION_STYLE;
		}

		$out = array(
			'id'          => isset( $section['id'] ) && is_string( $section['id'] ) && $section['id'] !== ''
				? $section['id']
				: self::generate_section_id(),
			'type'        => self::SECTION_TYPE,
			'title'       => isset( $section['title'] ) && is_string( $section['title'] ) ? $section['title'] : '',
			'description' => isset( $section['description'] ) && is_string( $section['description'] ) ? $section['description'] : '',
			'settings'    => array( 'style' => $style ),
		);
		// 1.2.0: sections may carry conditional logic (hides the heading).
		if ( isset( $section['conditional_logic'] ) && is_array( $section['conditional_logic'] ) ) {
			$out['conditional_logic'] = $section['conditional_logic'];
		}
		return $out;
	}

	/** Default settings for a freshly-created step. */
	public static function default_step_settings(): array {
		return array(
			'button_next'     => self::DEFAULT_STEP_BUTTON_NEXT,
			'button_previous' => self::DEFAULT_STEP_BUTTON_PREVIOUS,
		);
	}

	/**
	 * Normalize a step to canonical shape.
	 *
	 * - id: filled if missing
	 * - title: defaulted to '' if missing
	 * - description: defaulted to '' if missing
	 * - settings.button_next / .button_previous: defaulted from constants
	 * - fields[]: defaulted to []
	 * - any nested step inside step.fields is dropped on lenient read
	 *   (validator rejects them on save); rows + real fields pass through
	 */
	public static function normalize_step( $step ): array {
		if ( ! is_array( $step ) ) {
			return array(
				'id'          => self::generate_step_id(),
				'type'        => self::STEP_TYPE,
				'title'       => '',
				'description' => '',
				'settings'    => self::default_step_settings(),
				'fields'      => array(),
			);
		}

		$settings_in = is_array( $step['settings'] ?? null ) ? $step['settings'] : array();
		$settings    = array(
			'button_next'     => isset( $settings_in['button_next'] ) && is_string( $settings_in['button_next'] ) && $settings_in['button_next'] !== ''
				? $settings_in['button_next']
				: self::DEFAULT_STEP_BUTTON_NEXT,
			'button_previous' => isset( $settings_in['button_previous'] ) && is_string( $settings_in['button_previous'] ) && $settings_in['button_previous'] !== ''
				? $settings_in['button_previous']
				: self::DEFAULT_STEP_BUTTON_PREVIOUS,
		);

		$children_in = is_array( $step['fields'] ?? null ) ? $step['fields'] : array();
		// Drop nested steps on lenient normalize. Rows pass through,
		// real fields pass through, columns at this level (orphan, no
		// parent row) are dropped because they don't make sense outside
		// a row.
		$children = array();
		foreach ( $children_in as $child ) {
			if ( ! is_array( $child ) ) {
				continue;
			}
			if ( self::is_step( $child ) ) {
				continue;
			}
			if ( self::is_column( $child ) && ! self::is_real_field( $child ) ) {
				continue;
			}
			if ( self::is_row( $child ) ) {
				$children[] = $child;
				continue;
			}
			if ( self::is_section( $child ) ) {
				$children[] = self::normalize_section( $child );
				continue;
			}
			if ( self::is_real_field( $child ) ) {
				$children[] = $child;
			}
		}

		$out = array(
			'id'          => isset( $step['id'] ) && is_string( $step['id'] ) && $step['id'] !== ''
				? $step['id']
				: self::generate_step_id(),
			'type'        => self::STEP_TYPE,
			'title'       => isset( $step['title'] ) && is_string( $step['title'] ) ? $step['title'] : '',
			'description' => isset( $step['description'] ) && is_string( $step['description'] ) ? $step['description'] : '',
			'settings'    => $settings,
			'fields'      => $children,
		);
		// 1.2.0: steps may carry conditional logic (step eligibility).
		if ( isset( $step['conditional_logic'] ) && is_array( $step['conditional_logic'] ) ) {
			$out['conditional_logic'] = $step['conditional_logic'];
		}
		return $out;
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
			return array(
				'id'     => self::generate_column_id(),
				'fields' => array(),
			);
		}
		$fields = is_array( $col['fields'] ?? null ) ? $col['fields'] : array();
		$fields = array_values(
			array_filter(
				$fields,
				static function ( $f ) {
					return is_array( $f ) && ! self::is_row( $f );
				}
			)
		);
		$out    = array(
			'id'     => isset( $col['id'] ) && is_string( $col['id'] ) && $col['id'] !== ''
				? $col['id']
				: self::generate_column_id(),
			'fields' => $fields,
		);
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
		$fields_in = is_array( $schema['fields'] ?? null ) ? $schema['fields'] : array();
		$out       = array();
		foreach ( $fields_in as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			if ( self::is_step( $node ) ) {
				// Steps live only at the top level. Their inner fields
				// (rows + sections + real fields) are normalized
				// recursively.
				$norm  = self::normalize_step( $node );
				$inner = array();
				foreach ( $norm['fields'] as $child ) {
					if ( self::is_row( $child ) ) {
						$inner[] = self::normalize_row_canonical( $child );
					} elseif ( self::is_section( $child ) ) {
						$inner[] = self::normalize_section( $child );
					} elseif ( self::is_real_field( $child ) ) {
						$inner[] = $child;
					}
				}
				$norm['fields'] = $inner;
				$out[]          = $norm;
				continue;
			}
			if ( self::is_row( $node ) ) {
				$out[] = self::normalize_row_canonical( $node );
				continue;
			}
			if ( self::is_section( $node ) ) {
				$out[] = self::normalize_section( $node );
				continue;
			}
			if ( self::is_welcome_screen( $node ) ) {
				// One per form: drop any duplicate occurrence so the
				// schema persistence layer can't accidentally save two.
				$has_welcome = false;
				foreach ( $out as $existing ) {
					if ( self::is_welcome_screen( $existing ) ) {
						$has_welcome = true;
						break;
					}
				}
				if ( ! $has_welcome ) {
					$out[] = self::normalize_welcome_screen( $node );
				}
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
	 * Normalise a Welcome Screen node to canonical shape — fills
	 * missing settings with defaults, coerces alignment to a known
	 * value, and clamps unknown setting keys to known ones so the
	 * persisted schema is predictable.
	 *
	 * The buttonText / button_text alias is preserved on output
	 * (buttonText is canonical) so both keys round-trip cleanly.
	 *
	 * @param array $node
	 * @return array
	 */
	public static function normalize_welcome_screen( array $node ): array {
		$settings_in = is_array( $node['settings'] ?? null ) ? $node['settings'] : array();
		$alignment   = (string) ( $settings_in['alignment'] ?? 'center' );
		if ( ! in_array( $alignment, array( 'left', 'center', 'right' ), true ) ) {
			$alignment = 'center';
		}
		return array(
			'id'          => (string) ( $node['id'] ?? '' ),
			'type'        => self::WELCOME_SCREEN_TYPE,
			'label'       => (string) ( $node['label'] ?? 'Welcome Screen' ),
			'title'       => (string) ( $node['title'] ?? '' ),
			'description' => (string) ( $node['description'] ?? '' ),
			// buttonText (canonical) wins when non-empty; fall back to
			// the snake_case alias for legacy / import payloads.
			'buttonText'  => (string) (
				( isset( $node['buttonText'] ) && $node['buttonText'] !== '' )
					? $node['buttonText']
					: ( $node['button_text'] ?? '' )
			),
			'settings'    => array(
				'alignment' => $alignment,
			),
		);
	}

	/**
	 * Internal: fold legacy row variants into canonical settings, then
	 * delegate to normalize_row() for the column-count resize step.
	 */
	private static function normalize_row_canonical( array $row ): array {
		$layout      = is_array( $row['layout'] ?? null ) ? $row['layout'] : array();
		$settings_in = is_array( $row['settings'] ?? null ) ? $row['settings'] : array();

		$desired_raw =
			$settings_in['columns']
			?? $layout['columns']
			?? ( is_int( $row['columns'] ?? null ) ? $row['columns'] : null )
			?? ( is_array( $row['columns'] ?? null ) ? count( $row['columns'] ) : 1 );

		$desired = self::normalize_column_count( $desired_raw );

		$gap_raw = $settings_in['gap'] ?? $layout['gap'] ?? $row['gap'] ?? self::DEFAULT_GAP;
		$gap     = in_array( $gap_raw, self::VALID_GAPS, true ) ? $gap_raw : self::DEFAULT_GAP;

		$settings  = array(
			'columns' => $desired,
			'gap'     => $gap,
		);
		$alignment = $settings_in['alignment'] ?? $layout['alignment'] ?? null;
		if ( null !== $alignment && $alignment !== '' ) {
			$settings['alignment'] = $alignment;
		}

		$cols_in = is_array( $row['columns'] ?? null ) ? $row['columns'] : array();
		// If row.columns was a number we normalize to an empty array;
		// resize_row will spawn the right number of empty columns.
		if ( ! is_array( $cols_in ) ) {
			$cols_in = array();
		}

		$cols = array_map( array( self::class, 'normalize_column' ), array_values( $cols_in ) );

		$next = array_merge(
			$row,
			array(
				'type'     => self::ROW_TYPE,
				'settings' => $settings,
				'columns'  => $cols,
			)
		);
		unset( $next['layout'], $next['gap'] );

		return self::normalize_row( $next, $desired );
	}

	/**
	 * Default per-field upload cap in MB when the builder leaves
	 * max_file_size unset.
	 */
	const DEFAULT_FILE_MAX_MB = 5;

	/**
	 * Effective upload size limit for a file field.
	 *
	 * The builder value (max_file_size, MB; default 5) is capped by the
	 * server's own limit — wp_max_upload_size() = min( upload_max_filesize,
	 * post_max_size ), filterable via `upload_size_limit`. PHP's default is
	 * 2 MB, so without this cap a 3 MB file passes the "Max 5 MB" hint and
	 * the client check, then dies in PHP with UPLOAD_ERR_INI_SIZE.
	 *
	 * The renderer (hint + data-max-size), the client pre-check, and the
	 * server-side size check all read this one value so they agree.
	 *
	 * @param array $field  File field schema (reads max_file_size).
	 * @return array{ bytes: int, label: string }  Limit in bytes + human label ("2 MB", "1.5 MB", "512 KB").
	 */
	public static function file_size_limit( array $field ): array {
		$mb    = isset( $field['max_file_size'] ) && (int) $field['max_file_size'] > 0
			? (int) $field['max_file_size']
			: self::DEFAULT_FILE_MAX_MB;
		$bytes = $mb * 1024 * 1024;

		if ( function_exists( 'wp_max_upload_size' ) ) {
			$server = (int) wp_max_upload_size();
			if ( $server > 0 && $server < $bytes ) {
				$bytes = $server;
			}
		}

		return array(
			'bytes' => $bytes,
			'label' => self::format_file_size_label( $bytes ),
		);
	}

	/**
	 * Human label for a byte count: whole or one-decimal MB, or KB below 1 MB.
	 *
	 * @param int $bytes
	 * @return string
	 */
	public static function format_file_size_label( int $bytes ): string {
		$mb = $bytes / ( 1024 * 1024 );
		if ( $mb >= 1 ) {
			$str = number_format( $mb, 1, '.', '' );
			$str = rtrim( rtrim( $str, '0' ), '.' );
			return $str . ' MB';
		}
		return (string) max( 1, (int) round( $bytes / 1024 ) ) . ' KB';
	}

	/**
	 * Top-level steps with their conditional logic and the ids of the
	 * fields they contain (1.2.0). Used by SubmitEndpoint to hide every
	 * field of an ineligible step and by FormValidator to check that a
	 * step only depends on fields from earlier steps.
	 *
	 * @param array $nodes Top-level schema nodes.
	 * @return array[] [ { id, index, logic|null, field_ids[] }, ... ] in order.
	 */
	public static function step_logic_map( array $nodes ): array {
		$out   = array();
		$index = 0;
		foreach ( $nodes as $node ) {
			if ( ! self::is_step( $node ) ) {
				continue;
			}
			$fields = is_array( $node['fields'] ?? null ) ? $node['fields'] : array();
			$ids    = array();
			foreach ( self::flatten_fields( $fields ) as $f ) {
				$fid = (string) ( $f['id'] ?? '' );
				if ( $fid !== '' ) {
					$ids[] = $fid;
				}
			}
			$logic = isset( $node['conditional_logic'] ) && is_array( $node['conditional_logic'] ) ? $node['conditional_logic'] : null;
			$out[] = array(
				'id'        => (string) ( $node['id'] ?? '' ),
				'index'     => $index,
				'logic'     => $logic,
				'field_ids' => $ids,
			);
			$index++;
		}
		return $out;
	}

	/**
	 * Every section node (top level or inside a step) with its logic.
	 *
	 * @return array[] [ { id, logic|null }, ... ]
	 */
	public static function section_logic_map( array $nodes ): array {
		$out = array();
		foreach ( $nodes as $node ) {
			if ( self::is_section( $node ) ) {
				$out[] = array(
					'id'    => (string) ( $node['id'] ?? '' ),
					'logic' => isset( $node['conditional_logic'] ) && is_array( $node['conditional_logic'] ) ? $node['conditional_logic'] : null,
				);
				continue;
			}
			if ( self::is_step( $node ) && is_array( $node['fields'] ?? null ) ) {
				foreach ( self::section_logic_map( $node['fields'] ) as $s ) {
					$out[] = $s;
				}
			}
		}
		return $out;
	}
}
