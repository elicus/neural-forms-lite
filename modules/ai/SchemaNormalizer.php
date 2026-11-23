<?php
namespace NeuralForms\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Sanitises AI-generated form output into a Lite-validatable schema.
 *
 * The LLM output is not trusted: unknown types, broken option arrays,
 * malicious HTML, and missing labels are all real failure modes. This
 * normaliser walks the raw response and either returns a strict subset
 * Lite's FormValidator will accept, or a list of warnings.
 */
final class SchemaNormalizer {

	const MAX_FIELDS      = 40;
	const MAX_STEPS       = 8;
	const MAX_OPTIONS     = 10;
	const MAX_LABEL       = 200;
	const MAX_PLACEHOLDER = 200;

	/** Cross-checked against FieldRegistry at runtime — anything Lite can't validate is filtered out. */
	const ALLOWED_FIELD_TYPES = array(
		'text',
		'email',
		'textarea',
		'select',
		'checkbox',
		'checkboxes',
		'number',
		'phone',
		'url',
		'date',
		'radio',
		'hidden',
		'acceptance',
		'file',
		'rating',
		'poll',
		'quiz_question',
		'matrix',
		'repeater',
	);

	/** Mirrors Lite's FormValidator::REPEATER_ALLOWED_CHILD_TYPES — keep in sync. */
	const REPEATER_ALLOWED_CHILD_TYPES = array(
		'text',
		'email',
		'phone',
		'number',
		'url',
		'textarea',
		'select',
		'radio',
		'checkbox',
		'checkboxes',
		'date',
		'file',
	);

	const REPEATER_MAX_ITEMS    = 20;
	const REPEATER_MAX_CHILDREN = 8;

	/** Mirrors the cloud-side normaliser as defence-in-depth. Case-insensitive lookup. */
	const TYPE_ALIASES = array(
		'short_text'        => 'text',
		'single_line_text'  => 'text',
		'string'            => 'text',
		'name'              => 'text',
		'full_name'         => 'text',
		'first_name'        => 'text',
		'last_name'         => 'text',
		'company'           => 'text',
		'long_text'         => 'textarea',
		'paragraph'         => 'textarea',
		'message'           => 'textarea',
		'comments'          => 'textarea',
		'notes'             => 'textarea',
		'email_address'     => 'email',
		'mail'              => 'email',
		'tel'               => 'phone',
		'telephone'         => 'phone',
		'mobile'            => 'phone',
		'whatsapp'          => 'phone',
		'website'           => 'url',
		'link'              => 'url',
		'datepicker'        => 'date',
		'date_picker'       => 'date',
		'appointment_date'  => 'date',
		'integer'           => 'number',
		'numeric'           => 'number',
		'dropdown'          => 'select',
		'choice'            => 'radio',
		'single_choice'     => 'radio',
		'multiple_choice'   => 'checkboxes',
		'multi_select'      => 'checkboxes',
		'boolean'           => 'checkbox',
		'yes_no'            => 'checkbox',
		'consent'           => 'acceptance',
		'terms'             => 'acceptance',
		'privacy_consent'   => 'acceptance',
		'gdpr'              => 'acceptance',
		'upload'            => 'file',
		'file_upload'       => 'file',
		'attachment'        => 'file',
		'stars'             => 'rating',
		'star_rating'       => 'rating',
		'review_rating'     => 'rating',
		'vote'              => 'poll',
		'voting'            => 'poll',
		'mcq'               => 'quiz_question',
		'likert'            => 'matrix',
		'likert_scale'      => 'matrix',
		'rating_matrix'     => 'matrix',
		'survey_matrix'     => 'matrix',
		'grid'              => 'matrix',
		'scale_matrix'      => 'matrix',
		'repeatable'        => 'repeater',
		'repeatable_group'  => 'repeater',
		'repeat_group'      => 'repeater',
		'field_group'       => 'repeater',
		'group_list'        => 'repeater',
		'repeating_section' => 'repeater',
		'page'              => 'step',
		'wizard_step'       => 'step',
		'group'             => 'section',
	);

	/**
	 * @param array $raw Raw decoded JSON from the AI provider.
	 * @return array { form: array|null, warnings: string[], error: string|null }
	 */
	public static function normalize( array $raw ): array {
		$warnings = array();

		$name = self::clean_text( $raw['name'] ?? '', self::MAX_LABEL );
		if ( $name === '' ) {
			$name = __( 'Generated form', 'neural-forms-lite' );
		}

		$description = self::clean_text( $raw['description'] ?? '', 500 );

		$raw_fields = is_array( $raw['fields'] ?? null ) ? $raw['fields'] : array();
		if ( empty( $raw_fields ) ) {
			return array(
				'form'     => null,
				'warnings' => $warnings,
				'error'    => __( 'Neural AI returned no fields.', 'neural-forms-lite' ),
			);
		}

		$ids_in_use        = array();
		$step_count        = 0;
		$field_count_total = 0;
		$normalized_fields = array();

		foreach ( $raw_fields as $node ) {
			if ( ! is_array( $node ) ) {
				$warnings[] = __( 'Skipped non-object node.', 'neural-forms-lite' );
				continue;
			}

			$type = (string) ( $node['type'] ?? '' );

			if ( $type === 'step' ) {
				if ( $step_count >= self::MAX_STEPS ) {
					$warnings[] = sprintf(
						/* translators: %d: step cap */
						__( 'Dropped step beyond the %d-step cap.', 'neural-forms-lite' ),
						self::MAX_STEPS
					);
					continue;
				}
				$step = self::normalize_step( $node, $ids_in_use, $field_count_total, $warnings );
				if ( null !== $step ) {
					$normalized_fields[] = $step;
					++$step_count;
				}
				continue;
			}

			if ( $type === 'row' ) {
				$row = self::normalize_row( $node, $ids_in_use, $field_count_total, $warnings );
				if ( null !== $row ) {
					$normalized_fields[] = $row;
				}
				continue;
			}

			if ( $type === 'section' || $type === 'column' ) {
				$inner = is_array( $node['fields'] ?? null ) ? $node['fields'] : array();
				foreach ( $inner as $sub ) {
					if ( ! is_array( $sub ) ) {
						continue;
					}
					if ( $field_count_total >= self::MAX_FIELDS ) {
						continue;
					}
					$sub_type = (string) ( $sub['type'] ?? '' );
					if ( $sub_type === 'row' ) {
						$row = self::normalize_row( $sub, $ids_in_use, $field_count_total, $warnings );
						if ( null !== $row ) {
							$normalized_fields[] = $row;
						}
						continue;
					}
					$f = self::normalize_field( $sub, $ids_in_use, $warnings );
					if ( null !== $f ) {
						$normalized_fields[] = $f;
						++$field_count_total;
					}
				}
				continue;
			}

			if ( $field_count_total >= self::MAX_FIELDS ) {
				$warnings[] = sprintf(
					/* translators: %d: field cap */
					__( 'Dropped field beyond the %d-field cap.', 'neural-forms-lite' ),
					self::MAX_FIELDS
				);
				continue;
			}

			$field = self::normalize_field( $node, $ids_in_use, $warnings );
			if ( null !== $field ) {
				$normalized_fields[] = $field;
				++$field_count_total;
			}
		}

		$normalized_fields = self::cleanup_empty_containers( $normalized_fields, $warnings );

		if ( empty( $normalized_fields ) ) {
			return array(
				'form'     => null,
				'warnings' => $warnings,
				'error'    => __( 'Neural AI output had no usable fields after normalisation.', 'neural-forms-lite' ),
			);
		}

		$form = array(
			'name'        => $name,
			'description' => $description,
			'form_schema' => array(
				'fields'   => $normalized_fields,
				'settings' => array(),
			),
		);

		$validation = self::run_lite_validation( $form['form_schema'] );
		if ( null !== $validation ) {
			return array(
				'form'     => null,
				'warnings' => $warnings,
				'error'    => $validation,
			);
		}

		return array(
			'form'     => $form,
			'warnings' => $warnings,
			'error'    => null,
		);
	}

	// ───────────────────────── Step / row containers ─────────────────────────

	private static function normalize_step( array $node, array &$ids_in_use, int &$field_count, array &$warnings ): ?array {
		$id    = self::generate_id( 'step', $ids_in_use );
		$title = self::clean_text( $node['title'] ?? __( 'Step', 'neural-forms-lite' ), self::MAX_LABEL );
		$next  = self::clean_text( $node['settings']['button_next'] ?? __( 'Next', 'neural-forms-lite' ), 60 );
		$prev  = self::clean_text( $node['settings']['button_previous'] ?? __( 'Previous', 'neural-forms-lite' ), 60 );

		$inner       = is_array( $node['fields'] ?? null ) ? $node['fields'] : array();
		$step_fields = array();
		foreach ( $inner as $sub ) {
			if ( ! is_array( $sub ) ) {
				continue;
			}
			$st = (string) ( $sub['type'] ?? '' );

			if ( $st === 'step' ) {
				$warnings[] = __( 'Dropped nested step (steps cannot contain steps).', 'neural-forms-lite' );
				continue;
			}
			if ( $st === 'row' ) {
				$row = self::normalize_row( $sub, $ids_in_use, $field_count, $warnings );
				if ( null !== $row ) {
					$step_fields[] = $row;
				}
				continue;
			}
			if ( $field_count >= self::MAX_FIELDS ) {
				continue;
			}
			$f = self::normalize_field( $sub, $ids_in_use, $warnings );
			if ( null !== $f ) {
				$step_fields[] = $f;
				++$field_count;
			}
		}

		if ( empty( $step_fields ) ) {
			$warnings[] = __( 'Dropped empty step.', 'neural-forms-lite' );
			return null;
		}

		return array(
			'id'       => $id,
			'type'     => 'step',
			'title'    => $title !== '' ? $title : __( 'Step', 'neural-forms-lite' ),
			'settings' => array(
				'button_next'     => $next !== '' ? $next : __( 'Next', 'neural-forms-lite' ),
				'button_previous' => $prev !== '' ? $prev : __( 'Previous', 'neural-forms-lite' ),
			),
			'fields'   => $step_fields,
		);
	}

	private static function normalize_row( array $node, array &$ids_in_use, int &$field_count, array &$warnings ): ?array {
		$id          = self::generate_id( 'row', $ids_in_use );
		$columns_in  = is_array( $node['columns'] ?? null ) ? $node['columns'] : array();
		$col_count   = max( 1, min( 4, count( $columns_in ) ) );
		$columns_out = array();
		for ( $i = 0; $i < $col_count; $i++ ) {
			$col_in     = is_array( $columns_in[ $i ] ?? null ) ? $columns_in[ $i ] : array();
			$col_id     = self::generate_id( 'col', $ids_in_use );
			$col_fields = array();
			$inner      = is_array( $col_in['fields'] ?? null ) ? $col_in['fields'] : array();
			foreach ( $inner as $sub ) {
				if ( ! is_array( $sub ) ) {
					continue;
				}
				$st = (string) ( $sub['type'] ?? '' );
				if ( $st === 'row' || $st === 'step' ) {
					$warnings[] = __( 'Dropped nested layout container inside column.', 'neural-forms-lite' );
					continue;
				}
				if ( $field_count >= self::MAX_FIELDS ) {
					continue;
				}
				$f = self::normalize_field( $sub, $ids_in_use, $warnings );
				if ( null !== $f ) {
					$col_fields[] = $f;
					++$field_count;
				}
			}
			$columns_out[] = array(
				'id'     => $col_id,
				'fields' => $col_fields,
			);
		}

		return array(
			'id'       => $id,
			'type'     => 'row',
			'settings' => array(
				'columns' => $col_count,
				'gap'     => 'medium',
			),
			'columns'  => $columns_out,
		);
	}

	// ───────────────────────── Leaf field normalisation ─────────────────────────

	private static function normalize_field( array $node, array &$ids_in_use, array &$warnings ): ?array {
		$raw_type = (string) ( $node['type'] ?? '' );

		$matrix_selection_override = null;
		$normalised_raw            = strtolower( preg_replace( '/[\s-]+/', '_', $raw_type ) );
		if ( $normalised_raw === 'matrix_radio' ) {
			$matrix_selection_override = 'radio';
			$node['type']              = 'matrix';
			$raw_type                  = 'matrix';
		} elseif ( $normalised_raw === 'matrix_checkbox' ) {
			$matrix_selection_override = 'checkbox';
			$node['type']              = 'matrix';
			$raw_type                  = 'matrix';
		}

		$type = self::resolve_type_alias( $raw_type, $node );
		if ( $type !== $raw_type ) {
			$warnings[] = sprintf(
				/* translators: 1: raw, 2: canonical */
				__( 'Mapped Neural AI type "%1$s" → "%2$s".', 'neural-forms-lite' ),
				sanitize_text_field( $raw_type ),
				sanitize_text_field( $type )
			);
			$node['type'] = $type;
		}

		if ( $type === 'matrix'
			&& class_exists( '\\NeuralForms\\Core\\Capabilities' )
			&& ! \NeuralForms\Core\Capabilities::can( \NeuralForms\Core\Capabilities::FIELD_MATRIX )
		) {
			$warnings[] = __( 'Dropped matrix field (Matrix requires Neural Forms Pro).', 'neural-forms-lite' );
			return null;
		}

		if ( $type === 'repeater'
			&& class_exists( '\\NeuralForms\\Core\\Capabilities' )
			&& ! \NeuralForms\Core\Capabilities::can( \NeuralForms\Core\Capabilities::FIELD_REPEATER )
		) {
			$warnings[] = __( 'Dropped repeater field (Repeater requires Neural Forms Pro).', 'neural-forms-lite' );
			return null;
		}

		if ( ! in_array( $type, self::ALLOWED_FIELD_TYPES, true ) ) {
			$warnings[] = sprintf(
				/* translators: %s: rejected type */
				__( 'Dropped field of unsupported type "%s".', 'neural-forms-lite' ),
				$type !== '' ? sanitize_text_field( $type ) : '(empty)'
			);
			return null;
		}

		if ( class_exists( '\\NeuralForms\\Forms\\FieldRegistry' )
			&& ! \NeuralForms\Forms\FieldRegistry::is_registered( $type )
		) {
			$warnings[] = sprintf(
				/* translators: %s: rejected type */
				__( 'Dropped "%s" (not registered in this Lite version).', 'neural-forms-lite' ),
				sanitize_text_field( $type )
			);
			return null;
		}

		$id    = self::generate_id( 'field', $ids_in_use );
		$label = self::clean_text( $node['label'] ?? '', self::MAX_LABEL );
		// Treat a slug-shaped label (e.g. `text_mpv1gytfc`) as if the AI
		// omitted the field's label entirely — nobody wants raw slugs in
		// the entry inbox.
		if ( $label === '' || self::looks_like_auto_slug( $label ) ) {
			$label = self::humanize_field_type( $type );
		}

		$out = array(
			'id'    => $id,
			'type'  => $type,
			'label' => $label,
		);

		if ( ! empty( $node['placeholder'] ) ) {
			$out['placeholder'] = self::clean_text( $node['placeholder'], self::MAX_PLACEHOLDER );
		}

		if ( ! empty( $node['help_text'] ) ) {
			$out['help_text'] = self::clean_text( $node['help_text'], 500 );
		}

		if ( isset( $node['required'] ) ) {
			$out['required'] = (bool) $node['required'];
		}

		if ( $type === 'hidden' ) {
			$default = (string) ( $node['default_value'] ?? '' );
			$default = self::clean_text( $default, 500 );
			if ( self::looks_like_secret( $default ) ) {
				$warnings[] = __( 'Cleared a hidden-field default that looked like a secret.', 'neural-forms-lite' );
				$default    = '';
			}
			$out['default_value'] = $default;
		}

		if ( $type === 'file' && empty( $out['help_text'] ) ) {
			$out['help_text'] = __( 'Maximum size 10 MB. Supported formats per server config.', 'neural-forms-lite' );
		}

		if ( $type === 'acceptance' && empty( $out['label'] ) ) {
			$out['label'] = __( 'I agree to the terms', 'neural-forms-lite' );
		}

		$choice_types = array( 'select', 'checkboxes', 'radio', 'poll', 'quiz_question' );
		if ( in_array( $type, $choice_types, true ) ) {
			$opts = self::normalize_options( $node['options'] ?? array(), $type, $warnings );

			if ( $type === 'poll' && ( count( $opts ) < 2 || count( $opts ) > 6 ) ) {
				$warnings[] = __( 'Dropped poll: needs between 2 and 6 options.', 'neural-forms-lite' );
				return null;
			}
			if ( count( $opts ) < 2 ) {
				$warnings[] = sprintf(
					/* translators: %s: field type */
					__( 'Dropped %s field: needs at least 2 options.', 'neural-forms-lite' ),
					$type
				);
				return null;
			}

			$out['options'] = $opts;
		}

		if ( $type === 'quiz_question' ) {
			$correct = 0;
			foreach ( $out['options'] as $o ) {
				if ( ! empty( $o['is_correct'] ) ) {
					++$correct;
				}
			}
			if ( 0 === $correct ) {
				if ( isset( $out['options'][0] ) ) {
					$out['options'][0]['is_correct'] = true;
					$warnings[]                      = __( 'Quiz question had no correct option; marked the first option as correct.', 'neural-forms-lite' );
				}
			} elseif ( $correct > 1 ) {
				$kept = false;
				foreach ( $out['options'] as $i => $o ) {
					if ( ! empty( $o['is_correct'] ) ) {
						if ( ! $kept ) {
							$kept = true;
						} else {
							$out['options'][ $i ]['is_correct'] = false;
						}
					}
				}
				$warnings[] = __( 'Quiz question had multiple correct options; kept only the first.', 'neural-forms-lite' );
			}

			$points        = isset( $node['points'] ) ? (int) $node['points'] : 1;
			$out['points'] = max( 0, min( 100, $points ) );
		}

		if ( $type === 'rating' ) {
			$scale        = isset( $node['scale'] ) ? (int) $node['scale'] : 5;
			$out['scale'] = max( 3, min( 10, $scale ) );
		}

		if ( $type === 'matrix' ) {
			$out['settings'] = self::normalize_matrix( $node, $matrix_selection_override, $warnings );
		}

		if ( $type === 'repeater' ) {
			$normalized_repeater = self::normalize_repeater( $node, $ids_in_use, $warnings );
			$out['settings']     = $normalized_repeater['settings'];
			$out['fields']       = $normalized_repeater['fields'];
		}

		return $out;
	}

	private static function normalize_repeater( array $node, array &$ids_in_use, array &$warnings ): array {
		$src = is_array( $node['settings'] ?? null ) ? $node['settings'] : array();

		$min  = isset( $src['min_items'] ) ? (int) $src['min_items'] : 1;
		$max  = isset( $src['max_items'] ) ? (int) $src['max_items'] : 5;
		$cols = isset( $src['columns'] ) ? (int) $src['columns'] : 1;
		if ( $min < 0 ) {
			$min = 0;
		}
		if ( $min > self::REPEATER_MAX_ITEMS ) {
			$min = self::REPEATER_MAX_ITEMS;
		}
		if ( $max < 1 ) {
			$max = 1;
		}
		if ( $max > self::REPEATER_MAX_ITEMS ) {
			$max = self::REPEATER_MAX_ITEMS;
		}
		if ( $max < $min ) {
			$max = $min;
		}
		if ( $cols < 1 ) {
			$cols = 1;
		}
		if ( $cols > 4 ) {
			$cols = 4;
		}

		$add = self::clean_text( $src['add_button_label'] ?? '', 60 );
		$rem = self::clean_text( $src['remove_button_label'] ?? '', 60 );
		if ( $add === '' ) {
			$add = __( 'Add item', 'neural-forms-lite' );
		}
		if ( $rem === '' ) {
			$rem = __( 'Remove', 'neural-forms-lite' );
		}

		$children_raw = is_array( $node['fields'] ?? null ) ? $node['fields'] : array();
		$child_ids    = array();
		$children  = array();
		foreach ( $children_raw as $child_in ) {
			if ( count( $children ) >= self::REPEATER_MAX_CHILDREN ) {
				$warnings[] = sprintf(
					/* translators: %d: child cap */
					__( 'Trimmed repeater children past the %d-child cap.', 'neural-forms-lite' ),
					self::REPEATER_MAX_CHILDREN
				);
				break;
			}
			if ( ! is_array( $child_in ) ) {
				continue;
			}

			$raw_child_type = (string) ( $child_in['type'] ?? '' );
			$child_resolved = self::resolve_type_alias( $raw_child_type, $child_in );
			if ( ! in_array( $child_resolved, self::REPEATER_ALLOWED_CHILD_TYPES, true ) ) {
				$warnings[] = sprintf(
					/* translators: %s: rejected child type */
					__( 'Dropped repeater child of unsupported type "%s".', 'neural-forms-lite' ),
					$child_resolved !== '' ? sanitize_text_field( $child_resolved ) : '(empty)'
				);
				continue;
			}

			$child_in['type'] = $child_resolved;
			$child_norm       = self::normalize_field( $child_in, $child_ids, $warnings );
			if ( null !== $child_norm ) {
				$children[] = $child_norm;
			}
		}

		return array(
			'settings' => array(
				'min_items'           => $min,
				'max_items'           => $max,
				'columns'             => $cols,
				'add_button_label'    => $add,
				'remove_button_label' => $rem,
			),
			'fields'   => $children,
		);
	}

	/**
	 * @param string|null $selection_override 'radio'|'checkbox'|null. Wins over the field's own selection_type.
	 */
	private static function normalize_matrix( array $node, ?string $selection_override, array &$warnings ): array {
		$src = is_array( $node['settings'] ?? null ) ? $node['settings'] : array();

		$selection = $selection_override;
		if ( null === $selection ) {
			$candidate = (string) ( $src['selection_type'] ?? $node['selection_type'] ?? '' );
			if ( $candidate === '' ) {
				$candidate = (string) ( $node['input_type'] ?? '' );
			}
			if ( $candidate === '' ) {
				$raw_sel = (string) ( $node['selection'] ?? $src['selection'] ?? '' );
				if ( $raw_sel === 'single' ) {
					$candidate = 'radio';
				}
				if ( $raw_sel === 'multiple' ) {
					$candidate = 'checkbox';
				}
			}
			$selection = ( $candidate === 'checkbox' ) ? 'checkbox' : 'radio';
		}

		$rows_src = is_array( $src['rows'] ?? null ) ? $src['rows'] : ( is_array( $node['rows'] ?? null ) ? $node['rows'] : array() );
		$cols_src = is_array( $src['columns'] ?? null ) ? $src['columns'] : ( is_array( $node['columns'] ?? null ) ? $node['columns'] : array() );

		$rows = self::normalize_matrix_rows( $rows_src, $warnings );
		$cols = self::normalize_matrix_columns( $cols_src, $warnings );

		if ( empty( $rows ) ) {
			$warnings[] = __( 'Matrix had no usable rows; inserted three default rows.', 'neural-forms-lite' );
			$rows       = array(
				array(
					'label' => 'Row 1',
					'value' => 'row_1',
				),
				array(
					'label' => 'Row 2',
					'value' => 'row_2',
				),
				array(
					'label' => 'Row 3',
					'value' => 'row_3',
				),
			);
		}
		if ( empty( $cols ) ) {
			$warnings[] = __( 'Matrix had no usable columns; inserted three default columns.', 'neural-forms-lite' );
			$cols       = array(
				array(
					'label' => 'Option 1',
					'value' => 'option_1',
					'score' => 1,
				),
				array(
					'label' => 'Option 2',
					'value' => 'option_2',
					'score' => 2,
				),
				array(
					'label' => 'Option 3',
					'value' => 'option_3',
					'score' => 3,
				),
			);
		}

		return array(
			'selection_type' => $selection,
			'rows'           => $rows,
			'columns'        => $cols,
		);
	}

	private static function normalize_matrix_rows( array $raw, array &$warnings ): array {
		$out  = array();
		$used = array();
		foreach ( $raw as $i => $entry ) {
			if ( count( $out ) >= self::MAX_OPTIONS ) {
				$warnings[] = sprintf(
					/* translators: %d: row cap */
					__( 'Trimmed matrix rows past the %d-row cap.', 'neural-forms-lite' ),
					self::MAX_OPTIONS
				);
				break;
			}
			$label = '';
			$value = '';
			if ( is_string( $entry ) ) {
				$label = $entry;
			} elseif ( is_array( $entry ) ) {
				$label = (string) ( $entry['label'] ?? '' );
				$value = (string) ( $entry['value'] ?? '' );
			} else {
				continue;
			}
			$label = self::clean_text( $label, 120 );
			if ( $label === '' && $value === '' ) {
				continue;
			}
			$seed  = $value !== '' ? $value : $label;
			$value = sanitize_title( $seed );
			if ( $value === '' ) {
				$value = 'row_' . ( $i + 1 );
			}
			$base = $value;
			$n    = 2;
			while ( isset( $used[ $value ] ) ) {
				$value = $base . '-' . ( $n++ );
			}
			$used[ $value ] = true;
			$out[]          = array(
				'label' => $label !== '' ? $label : $value,
				'value' => $value,
			);
		}
		return $out;
	}

	private static function normalize_matrix_columns( array $raw, array &$warnings ): array {
		$out  = array();
		$used = array();
		foreach ( $raw as $i => $entry ) {
			if ( count( $out ) >= self::MAX_OPTIONS ) {
				$warnings[] = sprintf(
					/* translators: %d: column cap */
					__( 'Trimmed matrix columns past the %d-column cap.', 'neural-forms-lite' ),
					self::MAX_OPTIONS
				);
				break;
			}
			$label = '';
			$value = '';
			$score = null;
			if ( is_string( $entry ) ) {
				$label = $entry;
			} elseif ( is_array( $entry ) ) {
				$label = (string) ( $entry['label'] ?? '' );
				$value = (string) ( $entry['value'] ?? '' );
				if ( isset( $entry['score'] ) && is_numeric( $entry['score'] ) ) {
					$score = (int) $entry['score'];
				}
			} else {
				continue;
			}
			$label = self::clean_text( $label, 120 );
			if ( $label === '' && $value === '' ) {
				continue;
			}
			$seed  = $value !== '' ? $value : $label;
			$value = sanitize_title( $seed );
			if ( $value === '' ) {
				$value = 'option_' . ( $i + 1 );
			}
			$base = $value;
			$n    = 2;
			while ( isset( $used[ $value ] ) ) {
				$value = $base . '-' . ( $n++ );
			}
			$used[ $value ] = true;
			if ( null === $score ) {
				$score = count( $out ) + 1;
			}
			$out[] = array(
				'label' => $label !== '' ? $label : $value,
				'value' => $value,
				'score' => $score,
			);
		}
		return $out;
	}

	private static function normalize_options( $raw, string $type, array &$warnings ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out         = array();
		$used_values = array();
		foreach ( $raw as $opt ) {
			if ( count( $out ) >= self::MAX_OPTIONS ) {
				$warnings[] = sprintf(
					/* translators: %d: option cap */
					__( 'Trimmed options past the %d-option cap.', 'neural-forms-lite' ),
					self::MAX_OPTIONS
				);
				break;
			}
			if ( ! is_array( $opt ) ) {
				continue;
			}

			$label = self::clean_text( $opt['label'] ?? '', 120 );
			if ( $label === '' ) {
				continue;
			}

			$value_seed = (string) ( $opt['value'] ?? $label );
			$value      = sanitize_title( $value_seed );
			if ( $value === '' || $value === '__other__' ) {
				$value = sanitize_title( $label . '-' . ( count( $out ) + 1 ) );
			}
			$base = $value;
			$n    = 2;
			while ( isset( $used_values[ $value ] ) ) {
				$value = $base . '-' . ( $n++ );
			}
			$used_values[ $value ] = true;

			$row = array(
				'label' => $label,
				'value' => $value,
			);


			if ( $type === 'quiz_question' ) {
				$row['is_correct'] = ! empty( $opt['is_correct'] );
				if ( isset( $opt['points'] ) ) {
					$row['points'] = max( 0, min( 100, (int) $opt['points'] ) );
				}
			}

			$out[] = $row;
		}
		return $out;
	}

	private static function clean_text( $value, int $max_len ): string {
		if ( ! is_string( $value ) ) {
			$value = is_scalar( $value ) ? (string) $value : '';
		}
		$value = wp_strip_all_tags( $value, true );
		$value = sanitize_text_field( $value );
		if ( strlen( $value ) > $max_len ) {
			$value = substr( $value, 0, $max_len );
		}
		return trim( $value );
	}

	private static function generate_id( string $kind, array &$ids_in_use ): string {
		$prefix = ( $kind === 'row' ) ? 'row_'
			: ( ( $kind === 'col' ) ? 'col_'
			: ( ( $kind === 'step' ) ? 'step_'
			: 'field_' ) );
		do {
			$id = $prefix . substr( md5( uniqid( '', true ) ), 0, 10 );
		} while ( isset( $ids_in_use[ $id ] ) );
		$ids_in_use[ $id ] = true;
		return $id;
	}

	private static function resolve_type_alias( string $raw, array $node ): string {
		if ( $raw === '' ) {
			return $raw;
		}
		$key = strtolower( preg_replace( '/[\s-]+/', '_', $raw ) );
		if ( isset( self::TYPE_ALIASES[ $key ] ) ) {
			return self::TYPE_ALIASES[ $key ];
		}
		if ( $key === 'question' ) {
			$opts        = is_array( $node['options'] ?? null ) ? $node['options']
				: ( is_array( $node['choices'] ?? null ) ? $node['choices']
				: ( is_array( $node['answers'] ?? null ) ? $node['answers'] : array() ) );
			$has_correct = false;
			foreach ( $opts as $o ) {
				if ( ! is_array( $o ) ) {
					continue;
				}
				if ( ! empty( $o['is_correct'] ) || ! empty( $o['isCorrect'] ) || ! empty( $o['correct'] ) ) {
					$has_correct = true;
					break;
				}
			}
			if ( ! $has_correct && isset( $node['correct_answer'] ) && is_string( $node['correct_answer'] ) ) {
				$has_correct = true;
			}
			return $has_correct ? 'quiz_question' : 'radio';
		}
		return $raw;
	}

	public static function cleanup_empty_containers( array $nodes, array &$warnings ): array {
		$out = array();
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$type = (string) ( $node['type'] ?? '' );

			if ( $type === 'row' ) {
				$cols      = is_array( $node['columns'] ?? null ) ? $node['columns'] : array();
				$kept_cols = array();
				foreach ( $cols as $col ) {
					if ( ! is_array( $col ) ) {
						continue;
					}
					$col_fields = self::cleanup_empty_containers(
						is_array( $col['fields'] ?? null ) ? $col['fields'] : array(),
						$warnings
					);
					if ( ! empty( $col_fields ) ) {
						$col['fields'] = $col_fields;
						$kept_cols[]   = $col;
					}
				}
				if ( empty( $kept_cols ) ) {
					$warnings[] = __( 'Removed empty row.', 'neural-forms-lite' );
					continue;
				}
				if ( count( $kept_cols ) === 1 ) {
					$warnings[] = __( 'Unwrapped single-column row to bare fields.', 'neural-forms-lite' );
					foreach ( $kept_cols[0]['fields'] as $f ) {
						$out[] = $f;
					}
					continue;
				}
				$node['columns'] = $kept_cols;
				$out[]           = $node;
				continue;
			}

			if ( $type === 'step' ) {
				$step_fields = self::cleanup_empty_containers(
					is_array( $node['fields'] ?? null ) ? $node['fields'] : array(),
					$warnings
				);
				if ( empty( $step_fields ) ) {
					$warnings[] = __( 'Removed empty step.', 'neural-forms-lite' );
					continue;
				}
				$node['fields'] = $step_fields;
				$out[]          = $node;
				continue;
			}

			if ( $type === 'section' || $type === 'column' ) {
				$inner = self::cleanup_empty_containers(
					is_array( $node['fields'] ?? null ) ? $node['fields'] : array(),
					$warnings
				);
				if ( empty( $inner ) ) {
					$warnings[] = sprintf(
						/* translators: %s: container type */
						__( 'Removed empty %s.', 'neural-forms-lite' ),
						$type
					);
					continue;
				}
				$warnings[] = sprintf(
					/* translators: %s: container type */
					__( 'Unwrapped children from unsupported %s container.', 'neural-forms-lite' ),
					$type
				);
				foreach ( $inner as $f ) {
					$out[] = $f;
				}
				continue;
			}

			$out[] = $node;
		}
		return $out;
	}

	/** Detect labels shaped like the Lite builder's auto-slug (e.g. `text_mpv1gytfc`). */
	private static function looks_like_auto_slug( string $s ): bool {
		return (bool) preg_match( '/^[a-z]+(?:_[a-z0-9]+){1,}$/', $s );
	}

	/**
	 * Human-readable fallback name for a field type. Kept in sync manually
	 * with Lite's JS FIELD_TYPE_LABELS + PHP field_type_labels() — the
	 * three tables must stay identical.
	 */
	private static function humanize_field_type( string $type ): string {
		static $map = null;
		if ( null === $map ) {
			$map = array(
				'text'          => __( 'Text field',    'neural-forms-lite' ),
				'textarea'      => __( 'Message',       'neural-forms-lite' ),
				'email'         => __( 'Email address', 'neural-forms-lite' ),
				'phone'         => __( 'Phone number',  'neural-forms-lite' ),
				'number'        => __( 'Number',        'neural-forms-lite' ),
				'url'           => __( 'URL',           'neural-forms-lite' ),
				'select'        => __( 'Selection',     'neural-forms-lite' ),
				'radio'         => __( 'Choice',        'neural-forms-lite' ),
				'checkbox'      => __( 'Checkbox',      'neural-forms-lite' ),
				'checkboxes'    => __( 'Choices',       'neural-forms-lite' ),
				'date'          => __( 'Date',          'neural-forms-lite' ),
				'file'          => __( 'File upload',   'neural-forms-lite' ),
				'rating'        => __( 'Rating',        'neural-forms-lite' ),
				'acceptance'    => __( 'Consent',       'neural-forms-lite' ),
				'poll'          => __( 'Poll',          'neural-forms-lite' ),
				'quiz_question' => __( 'Quiz question', 'neural-forms-lite' ),
				'matrix'        => __( 'Matrix',        'neural-forms-lite' ),
				'repeater'      => __( 'Group',         'neural-forms-lite' ),
				'hidden'        => __( 'Hidden field',  'neural-forms-lite' ),
			);
		}
		return $map[ $type ] ?? __( 'Field', 'neural-forms-lite' );
	}

	private static function looks_like_secret( string $value ): bool {
		if ( $value === '' ) {
			return false;
		}
		if ( preg_match( '/^(sk|pk|rk)[-_][A-Za-z0-9_-]{16,}$/', $value ) ) {
			return true;
		}
		if ( strlen( $value ) >= 32 && preg_match( '/^[A-Za-z0-9+\/=_-]{32,}$/', $value ) ) {
			return true;
		}
		return false;
	}

	private static function run_lite_validation( array $schema ): ?string {
		if ( ! class_exists( '\\NeuralForms\\Forms\\FormValidator' ) ) {
			return null;
		}
		$result = \NeuralForms\Forms\FormValidator::validate_schema( $schema );
		if ( is_wp_error( $result ) ) {
			$msg = $result->get_error_message();
			return is_string( $msg ) && $msg !== ''
				? $msg
				: __( 'Generated schema failed validation.', 'neural-forms-lite' );
		}
		return null;
	}
}
