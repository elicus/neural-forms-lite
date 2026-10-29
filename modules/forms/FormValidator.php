<?php
namespace NeuralForms\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Validates form schemas.
 *
 * Returns true on success, WP_Error on failure - so callers can pass
 * the result straight back through the REST response.
 *
 * Schema shape:
 *   form_schema.fields = [
 *     { id, type, ... },                             // flat field
 *     {                                              // layout row
 *       id, type: 'row',
 *       settings: { columns: 1..4, gap },
 *       columns: [ { id, fields: [ ... ] }, ... ],
 *     },
 *   ]
 *
 * Rules:
 *   1. 'fields' key exists and is a non-empty array.
 *   2. Every field id, row id, and column id matches FIELD_ID_PATTERN.
 *   3. Every field has a type registered in FieldRegistry.
 *   4. Every row has 1..4 columns and at least one column entry.
 *   5. Rows cannot contain other rows.
 *   6. Ids are unique across the whole tree (rows, columns, fields share
 *      one namespace) so callers can use a single id to look anything up.
 */
class FormValidator {

	/**
	 * Valid id pattern: lowercase letters, digits, and underscores only.
	 *
	 * Enforced so ids are safe to use as DB column aliases, JS object
	 * keys, merge tags, and CSS selectors.
	 */
	const FIELD_ID_PATTERN = '/^[a-z0-9_]+$/';

	/**
	 * @param array $schema  The decoded form_schema array.
	 * @return true|\WP_Error
	 */
	public static function validate_schema( array $schema ) {
		if ( ! isset( $schema['fields'] ) || ! is_array( $schema['fields'] ) ) {
			return new \WP_Error(
				'invalid_form_data',
				'Schema must contain a fields array.',
				array( 'status' => 422 )
			);
		}

		if ( empty( $schema['fields'] ) ) {
			return new \WP_Error(
				'invalid_form_data',
				'Schema must contain at least one field.',
				array( 'status' => 422 )
			);
		}

		$seen_ids = array();

		$result = self::validate_nodes( $schema['fields'], $seen_ids, 'fields' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result = self::validate_conditional_logic( $schema['fields'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return self::validate_calculations( $schema['fields'] );
	}

	/**
	 * Second pass (1.2.0): validate every field's conditional_logic
	 * against the full id set, so a rule cannot reference a field that
	 * is not in the form, itself, or use an unknown operator. Nested
	 * groups require the advanced-logic capability (Pro).
	 *
	 * Runs only when at least one field has enabled logic, so schemas
	 * without conditions never load the engine.
	 *
	 * @param array $nodes Top-level schema nodes.
	 * @return true|\WP_Error
	 */
	private static function validate_conditional_logic( array $nodes ) {
		$fields     = SchemaHelper::flatten_fields( $nodes );
		$ids        = array();
		$with_logic = array();
		foreach ( $fields as $f ) {
			$f  = (array) $f;
			$id = (string) ( $f['id'] ?? '' );
			if ( $id === '' ) {
				continue;
			}
			$ids[] = $id;
			$cl    = $f['conditional_logic'] ?? null;
			if ( is_array( $cl ) && ! empty( $cl['enabled'] ) ) {
				$with_logic[] = $f;
			}
		}

		$steps    = SchemaHelper::step_logic_map( $nodes );
		$sections = SchemaHelper::section_logic_map( $nodes );
		$has_step_logic = false;
		foreach ( $steps as $st ) {
			if ( is_array( $st['logic'] ) && ! empty( $st['logic']['enabled'] ) ) {
				$has_step_logic = true;
			}
		}
		$has_section_logic = false;
		foreach ( $sections as $sec ) {
			if ( is_array( $sec['logic'] ) && ! empty( $sec['logic']['enabled'] ) ) {
				$has_section_logic = true;
			}
		}

		if ( ! $with_logic && ! $has_step_logic && ! $has_section_logic ) {
			return true;
		}

		$advanced = (bool) apply_filters( 'neural_forms_can_conditional_logic_advanced', false );

		foreach ( $with_logic as $f ) {
			$label  = isset( $f['label'] ) && is_string( $f['label'] ) && $f['label'] !== '' ? $f['label'] : (string) $f['id'];
			$result = ConditionEngine::validate( $f['conditional_logic'], $ids, $advanced, (string) $f['id'], $label );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		// Step eligibility (Pro): a step may only depend on fields from
		// EARLIER steps — never its own or a later one — so the visitor
		// can never be on a step whose eligibility flips while typing.
		$earlier_ids = array();
		foreach ( $steps as $st ) {
			$logic = $st['logic'];
			if ( is_array( $logic ) && ! empty( $logic['enabled'] ) ) {
				$label = 'Step ' . ( $st['index'] + 1 );
				if ( ! $advanced ) {
					return self::locked( $label . ' uses conditional step logic, which requires Neural Forms Pro.' );
				}
				if ( $st['index'] === 0 && ConditionEngine::is_active( $logic ) ) {
					return new \WP_Error( 'invalid_form_data', 'The first step cannot have conditional logic; there is no earlier answer to decide it.', array( 'status' => 422 ) );
				}
				$result = ConditionEngine::validate( $logic, $earlier_ids, true, $st['id'], $label );
				if ( is_wp_error( $result ) ) {
					// Re-word the "not in this form" message for the step case.
					$msg = $result->get_error_message();
					if ( strpos( $msg, 'is not in this form' ) !== false ) {
						foreach ( ConditionEngine::references( $logic ) as $ref ) {
							if ( ! in_array( $ref, $earlier_ids, true ) ) {
								return new \WP_Error( 'invalid_form_data', sprintf( '%s can only depend on fields from earlier steps; "%s" is not one of them.', $label, $ref ), array( 'status' => 422 ) );
							}
						}
					}
					return $result;
				}
			}
			foreach ( $st['field_ids'] as $fid ) {
				$earlier_ids[] = $fid;
			}
		}

		// Sections (Pro): heading blocks, may depend on any field.
		foreach ( $sections as $sec ) {
			$logic = $sec['logic'];
			if ( is_array( $logic ) && ! empty( $logic['enabled'] ) ) {
				if ( ! $advanced ) {
					return self::locked( 'Conditional sections require Neural Forms Pro.' );
				}
				$result = ConditionEngine::validate( $logic, $ids, true, $sec['id'], 'Section ' . $sec['id'] );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}
		}

		return true;
	}

	/**
	 * Conditional confirmations / redirects (1.2.0, Pro): validate
	 * `settings.on_success.rules[]` against the schema before saving.
	 * Each rule needs enabled conditions that reference real fields;
	 * the default outcome (mode/message/url) is untouched here.
	 *
	 * @param array $settings Sanitised form settings.
	 * @param array $schema   Form schema ({ fields: [...] }).
	 * @return true|\WP_Error
	 */
	public static function validate_settings_logic( array $settings, array $schema ) {
		$pricing = self::validate_settings_pricing( $settings, $schema );
		if ( is_wp_error( $pricing ) ) {
			return $pricing;
		}
		$rules = $settings['on_success']['rules'] ?? null;
		if ( ! is_array( $rules ) || ! $rules ) {
			return true;
		}
		$advanced = (bool) apply_filters( 'neural_forms_can_conditional_logic_advanced', false );
		if ( ! $advanced ) {
			return self::locked( 'Conditional confirmations require Neural Forms Pro.' );
		}
		$ids = array();
		foreach ( SchemaHelper::flatten_fields( is_array( $schema['fields'] ?? null ) ? $schema['fields'] : array() ) as $f ) {
			$id = (string) ( $f['id'] ?? '' );
			if ( $id !== '' ) {
				$ids[] = $id;
			}
		}
		$n = 0;
		foreach ( $rules as $rule ) {
			$n++;
			$label = 'Confirmation rule ' . $n;
			if ( ! is_array( $rule ) || ! is_array( $rule['conditions'] ?? null ) || ! ConditionEngine::is_active( $rule['conditions'] ) ) {
				return new \WP_Error( 'invalid_form_data', $label . ' has no conditions. Add at least one condition or remove the rule.', array( 'status' => 422 ) );
			}
			$result = ConditionEngine::validate( $rule['conditions'], $ids, true, '', $label );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( ( $rule['mode'] ?? 'message' ) === 'redirect' && (string) ( $rule['url'] ?? '' ) === '' ) {
				return new \WP_Error( 'invalid_form_data', $label . ' redirects but has no URL.', array( 'status' => 422 ) );
			}
		}
		return true;
	}

	/**
	 * Product field (1.2.0, part B): prices must parse, option values must
	 * be unique, quantity bounds sane, input bounds ordered.
	 *
	 * @return true|\WP_Error
	 */
	private static function validate_product( array $field, string $id ) {
		$p   = \NeuralForms\Pricing\PriceEngine::product_pricing( $field );
		$err = static function ( string $msg ) use ( $id ) {
			return new \WP_Error( 'invalid_form_data', sprintf( 'Product "%s": %s', $id, $msg ), array( 'status' => 422 ) );
		};
		$is_price = static function ( $v ): bool {
			return is_scalar( $v ) && preg_match( '/^\s*-?[\d,]+(\.\d+)?\s*$/', (string) $v ) && strpos( (string) $v, '-' ) === false;
		};
		if ( $p['mode'] === 'fixed' && ! $is_price( $p['price'] ) ) {
			return $err( 'the price must be a number like 1250 or 99.50.' );
		}
		if ( $p['mode'] === 'options' ) {
			$options = is_array( $field['options'] ?? null ) ? $field['options'] : array();
			if ( ! $options ) {
				return $err( 'add at least one priced option.' );
			}
			$seen = array();
			foreach ( $options as $o ) {
				$o = (array) $o;
				$v = (string) ( $o['value'] ?? '' );
				if ( $v === '' ) {
					$v = (string) ( $o['label'] ?? '' );
				}
				if ( $v === '' ) {
					continue;
				}
				if ( isset( $seen[ $v ] ) ) {
					return $err( sprintf( 'option value "%s" is used twice.', $v ) );
				}
				$seen[ $v ] = true;
				if ( ! $is_price( $o['price'] ?? '0' ) ) {
					return $err( sprintf( 'option "%s" needs a numeric price.', $v ) );
				}
			}
		}
		if ( $p['mode'] === 'input' ) {
			if ( $p['min_amount'] !== '' && ! $is_price( $p['min_amount'] ) ) {
				return $err( 'the minimum amount must be a number.' );
			}
			if ( $p['max_amount'] !== '' && ! $is_price( $p['max_amount'] ) ) {
				return $err( 'the maximum amount must be a number.' );
			}
			if ( $p['min_amount'] !== '' && $p['max_amount'] !== '' && (float) str_replace( ',', '', $p['min_amount'] ) > (float) str_replace( ',', '', $p['max_amount'] ) ) {
				return $err( 'the minimum amount is above the maximum.' );
			}
		}
		if ( $p['quantity']['enabled'] && $p['quantity']['max'] < $p['quantity']['min'] ) {
			return $err( 'the maximum quantity is below the minimum.' );
		}
		return true;
	}

	/**
	 * Second pass (1.2.0, part B): calculated-field references and cycles.
	 * Runs from validate_schema() after the conditional-logic pass.
	 *
	 * @return true|\WP_Error
	 */
	private static function validate_calculations( array $nodes ) {
		$fields = SchemaHelper::flatten_fields( $nodes );
		$numeric = array();
		$has_calc = false;
		foreach ( $fields as $f ) {
			$id = (string) ( $f['id'] ?? '' );
			$t  = (string) ( $f['type'] ?? '' );
			if ( $id === '' ) {
				continue;
			}
			if ( in_array( $t, array( 'number', 'range', 'rating', 'product', 'calculated', 'hidden' ), true ) ) {
				$numeric[ $id ] = $t;
			}
			if ( $t === 'calculated' ) {
				$has_calc = true;
			}
		}
		if ( ! $has_calc ) {
			return true;
		}
		foreach ( $fields as $f ) {
			if ( ( $f['type'] ?? '' ) !== 'calculated' ) {
				continue;
			}
			$id = (string) $f['id'];
			try {
				$refs = \NeuralForms\Pricing\Expression::parse( (string) ( $f['expression'] ?? '' ) )['refs'];
			} catch ( \InvalidArgumentException $e ) {
				return new \WP_Error( 'invalid_form_data', sprintf( 'Calculated field "%s": %s', $id, $e->getMessage() ), array( 'status' => 422 ) );
			}
			foreach ( $refs as $r ) {
				if ( $r === $id ) {
					return new \WP_Error( 'invalid_form_data', sprintf( 'Calculated field "%s" refers to itself.', $id ), array( 'status' => 422 ) );
				}
				if ( ! isset( $numeric[ $r ] ) ) {
					return new \WP_Error( 'invalid_form_data', sprintf( 'Calculated field "%s" refers to "%s", which is not a number, product or calculated field in this form.', $id, $r ), array( 'status' => 422 ) );
				}
			}
		}
		$cycle = \NeuralForms\Pricing\PriceEngine::find_cycle( $fields );
		if ( $cycle ) {
			return new \WP_Error( 'invalid_form_data', sprintf( 'Calculated fields form a loop: %s.', implode( ' → ', $cycle ) ), array( 'status' => 422 ) );
		}
		return true;
	}

	/**
	 * settings.pricing (1.2.0, part B): currency, discount rules (conditions
	 * reference real fields, values numeric and bounded), deposit.
	 *
	 * @return true|\WP_Error
	 */
	private static function validate_settings_pricing( array $settings, array $schema ) {
		if ( ! isset( $settings['pricing'] ) || ! is_array( $settings['pricing'] ) ) {
			return true;
		}
		$p   = \NeuralForms\Pricing\PriceEngine::normalize_settings( $settings['pricing'] );
		$err = static function ( string $msg ) {
			return new \WP_Error( 'invalid_form_data', 'Pricing: ' . $msg, array( 'status' => 422 ) );
		};
		$ids = array();
		foreach ( SchemaHelper::flatten_fields( is_array( $schema['fields'] ?? null ) ? $schema['fields'] : array() ) as $f ) {
			$id = (string) ( $f['id'] ?? '' );
			if ( $id !== '' ) {
				$ids[] = $id;
			}
		}
		$n = 0;
		foreach ( $p['discounts'] as $d ) {
			$n++;
			if ( $d['type'] === 'percent' ) {
				$bp = \NeuralForms\Pricing\Money::percent_to_basis_points( $d['value'] );
				if ( $bp <= 0 || $bp > 10000 ) {
					return $err( sprintf( 'discount %d must be a percentage between 0.01 and 100.', $n ) );
				}
			} elseif ( null === \NeuralForms\Pricing\Money::to_minor( $d['value'], $p['currency'] ) || \NeuralForms\Pricing\Money::to_minor( $d['value'], $p['currency'] ) <= 0 ) {
				return $err( sprintf( 'discount %d must be a positive amount.', $n ) );
			}
			if ( $d['max'] !== '' && null === \NeuralForms\Pricing\Money::to_minor( $d['max'], $p['currency'] ) ) {
				return $err( sprintf( 'discount %d has a maximum that is not a number.', $n ) );
			}
			if ( $d['conditions'] ) {
				$r = ConditionEngine::validate( $d['conditions'], $ids, true, '', sprintf( 'Discount %d', $n ) );
				if ( is_wp_error( $r ) ) {
					return $r;
				}
			}
		}
		if ( $p['deposit']['type'] === 'percent' ) {
			$bp = \NeuralForms\Pricing\Money::percent_to_basis_points( $p['deposit']['value'] );
			if ( $bp <= 0 || $bp >= 10000 ) {
				return $err( 'the deposit must be a percentage between 0.01 and 99.99.' );
			}
		} elseif ( $p['deposit']['type'] === 'fixed' ) {
			$m = \NeuralForms\Pricing\Money::to_minor( $p['deposit']['value'], $p['currency'] );
			if ( null === $m || $m <= 0 ) {
				return $err( 'the deposit must be a positive amount.' );
			}
		}
		return true;
	}

	private static function locked( string $message ): \WP_Error {
		return new \WP_Error(
			'pro_feature_locked',
			$message,
			array(
				'status'       => 403,
				'feature'      => 'conditional_logic_advanced',
				'requires_pro' => true,
			)
		);
	}

	/**
	 * Recursive walker. $seen_ids is shared across the whole tree so a
	 * field id used inside one column cannot collide with a field id used
	 * inside another, or with a row id at the top level.
	 *
	 * @param array  $nodes
	 * @param array  $seen_ids  Shared id set, by reference.
	 * @param string $path      Human-readable path used in error messages.
	 * @return true|\WP_Error
	 */
	private static function validate_nodes( array $nodes, array &$seen_ids, string $path, bool $is_top_level = true ) {
		foreach ( $nodes as $index => $node ) {
			$node      = (array) $node;
			$node_path = $path . '[' . $index . ']';

			if ( SchemaHelper::is_step( $node ) ) {
				if ( ! $is_top_level ) {
					return new \WP_Error(
						'invalid_form_data',
						sprintf( 'Step at %s cannot be nested. Steps live only at the top level of the form.', $node_path ),
						array( 'status' => 422 )
					);
				}
				$step_error = self::validate_step( $node, $seen_ids, $node_path );
				if ( is_wp_error( $step_error ) ) {
					return $step_error;
				}
				continue;
			}

			if ( SchemaHelper::is_row( $node ) ) {
				$row_error = self::validate_row( $node, $seen_ids, $node_path );
				if ( is_wp_error( $row_error ) ) {
					return $row_error;
				}
				continue;
			}

			if ( SchemaHelper::is_section( $node ) ) {
				$section_error = self::validate_section( $node, $seen_ids, $node_path );
				if ( is_wp_error( $section_error ) ) {
					return $section_error;
				}
				continue;
			}

			if ( SchemaHelper::is_welcome_screen( $node ) ) {
				if ( ! $is_top_level ) {
					return new \WP_Error(
						'invalid_form_data',
						sprintf( 'Welcome Screen at %s cannot be nested. It must live at the top level of the form.', $node_path ),
						array( 'status' => 422 )
					);
				}
				$ws_error = self::validate_welcome_screen( $node, $seen_ids, $node_path );
				if ( is_wp_error( $ws_error ) ) {
					return $ws_error;
				}
				continue;
			}

			$field_error = self::validate_field( $node, $seen_ids, $node_path );
			if ( is_wp_error( $field_error ) ) {
				return $field_error;
			}
		}
		return true;
	}

	/**
	 * Validate a layout section. Sections are leaf content nodes -
	 * id pattern + dup-id namespace check, that's it. They never
	 * contain fields in 1.1.0; any extras (fields[]/columns[]) on
	 * a section node are silently ignored by the renderer.
	 */
	private static function validate_section( array $section, array &$seen_ids, string $path ) {
		if ( empty( $section['id'] ) ) {
			return new \WP_Error(
				'invalid_form_data',
				sprintf( 'Section at %s is missing an id.', $path ),
				array( 'status' => 422 )
			);
		}

		$id = (string) $section['id'];

		if ( ! preg_match( self::FIELD_ID_PATTERN, $id ) ) {
			return new \WP_Error(
				'invalid_form_data',
				sprintf(
					'Section id "%s" is invalid. Only lowercase letters (a-z), digits (0-9), and underscores (_) are allowed.',
					$id
				),
				array( 'status' => 422 )
			);
		}

		if ( in_array( $id, $seen_ids, true ) ) {
			return new \WP_Error(
				'invalid_form_data',
				sprintf( 'Duplicate id "%s". Step, row, column, section, and field ids share one namespace.', $id ),
				array( 'status' => 422 )
			);
		}
		$seen_ids[] = $id;

		return true;
	}

	/**
	 * Validate a Welcome Screen layout block.
	 *
	 * Welcome Screens are top-level display-only blocks that render
	 * before the form fields. Rules:
	 *   - id required and matches FIELD_ID_PATTERN
	 *   - id unique in the shared layout/field namespace
	 *   - one per form (the second occurrence is rejected here)
	 *
	 * Title / description / button_text are sanitised in
	 * FormSettingsSanitizer, not gate-validated, so a form with an
	 * empty title still saves (the builder may save mid-edit).
	 */
	private static function validate_welcome_screen( array $node, array &$seen_ids, string $path ) {
		if ( empty( $node['id'] ) ) {
			return new \WP_Error(
				'invalid_form_data',
				sprintf( 'Welcome Screen at %s is missing an id.', $path ),
				array( 'status' => 422 )
			);
		}

		$id = (string) $node['id'];

		if ( ! preg_match( self::FIELD_ID_PATTERN, $id ) ) {
			return new \WP_Error(
				'invalid_form_data',
				sprintf(
					'Welcome Screen id "%s" is invalid. Only lowercase letters (a-z), digits (0-9), and underscores (_) are allowed.',
					$id
				),
				array( 'status' => 422 )
			);
		}

		// One welcome screen per form. Track with a sentinel inside
		// $seen_ids so the dup check is co-located with the regular
		// id-uniqueness pass.
		$sentinel = '__welcome_screen_present__';
		if ( in_array( $sentinel, $seen_ids, true ) ) {
			return new \WP_Error(
				'invalid_form_data',
				'A form can only have one Welcome Screen.',
				array( 'status' => 422 )
			);
		}
		$seen_ids[] = $sentinel;

		if ( in_array( $id, $seen_ids, true ) ) {
			return new \WP_Error(
				'invalid_form_data',
				sprintf( 'Duplicate id "%s". Step, row, column, section, welcome-screen, and field ids share one namespace.', $id ),
				array( 'status' => 422 )
			);
		}
		$seen_ids[] = $id;

		return true;
	}

	/**
	 * Validate a layout step. Steps are top-level only and own a
	 * fields[] array of real fields and rows. Nested steps and orphan
	 * columns inside step.fields are rejected.
	 */
	private static function validate_step( array $step, array &$seen_ids, string $path ) {
		if ( empty( $step['id'] ) ) {
			return new \WP_Error(
				'invalid_form_data',
				sprintf( 'Step at %s is missing an id.', $path ),
				array( 'status' => 422 )
			);
		}

		$id = (string) $step['id'];

		if ( ! preg_match( self::FIELD_ID_PATTERN, $id ) ) {
			return new \WP_Error(
				'invalid_form_data',
				sprintf(
					'Step id "%s" is invalid. Only lowercase letters (a-z), digits (0-9), and underscores (_) are allowed.',
					$id
				),
				array( 'status' => 422 )
			);
		}

		if ( in_array( $id, $seen_ids, true ) ) {
			return new \WP_Error(
				'invalid_form_data',
				sprintf( 'Duplicate id "%s". Step, row, column, and field ids share one namespace.', $id ),
				array( 'status' => 422 )
			);
		}
		$seen_ids[] = $id;

		$children = is_array( $step['fields'] ?? null ) ? $step['fields'] : array();

		// Recurse with $is_top_level = false so nested steps are
		// rejected. Rows + real fields are allowed; orphan columns are
		// not (they only appear inside rows).
		return self::validate_nodes( $children, $seen_ids, $path . '.fields', false );
	}

	/**
	 * Validate a layout row: id, settings.columns (1..4), and recurse
	 * into every column's nested fields.
	 */
	private static function validate_row( array $row, array &$seen_ids, string $path ) {
		if ( empty( $row['id'] ) ) {
			return new \WP_Error(
				'invalid_form_data',
				sprintf( 'Row at %s is missing an id.', $path ),
				array( 'status' => 422 )
			);
		}

		$id = (string) $row['id'];

		if ( ! preg_match( self::FIELD_ID_PATTERN, $id ) ) {
			return new \WP_Error(
				'invalid_form_data',
				sprintf(
					'Row id "%s" is invalid. Only lowercase letters (a-z), digits (0-9), and underscores (_) are allowed.',
					$id
				),
				array( 'status' => 422 )
			);
		}

		if ( in_array( $id, $seen_ids, true ) ) {
			return new \WP_Error(
				'invalid_form_data',
				sprintf( 'Duplicate id "%s". Row, column, and field ids share one namespace.', $id ),
				array( 'status' => 422 )
			);
		}
		$seen_ids[] = $id;

		$columns = is_array( $row['columns'] ?? null ) ? $row['columns'] : array();
		if ( empty( $columns ) ) {
			return new \WP_Error(
				'invalid_form_data',
				sprintf( 'Row "%s" has no columns.', $id ),
				array( 'status' => 422 )
			);
		}

		$declared = isset( $row['settings']['columns'] )
			? (int) $row['settings']['columns']
			: count( $columns );
		if ( $declared < SchemaHelper::MIN_COLUMNS || $declared > SchemaHelper::MAX_COLUMNS ) {
			return new \WP_Error(
				'invalid_form_data',
				sprintf(
					'Row "%s" declares %d columns. Allowed range is %d to %d.',
					$id,
					$declared,
					SchemaHelper::MIN_COLUMNS,
					SchemaHelper::MAX_COLUMNS
				),
				array( 'status' => 422 )
			);
		}

		foreach ( $columns as $col_idx => $col ) {
			$col      = (array) $col;
			$col_path = $path . '.columns[' . $col_idx . ']';

			if ( empty( $col['id'] ) ) {
				return new \WP_Error(
					'invalid_form_data',
					sprintf( 'Column at %s is missing an id.', $col_path ),
					array( 'status' => 422 )
				);
			}

			$col_id = (string) $col['id'];
			if ( ! preg_match( self::FIELD_ID_PATTERN, $col_id ) ) {
				return new \WP_Error(
					'invalid_form_data',
					sprintf(
						'Column id "%s" is invalid. Only lowercase letters, digits, and underscores are allowed.',
						$col_id
					),
					array( 'status' => 422 )
				);
			}

			if ( in_array( $col_id, $seen_ids, true ) ) {
				return new \WP_Error(
					'invalid_form_data',
					sprintf( 'Duplicate id "%s". Row, column, and field ids share one namespace.', $col_id ),
					array( 'status' => 422 )
				);
			}
			$seen_ids[] = $col_id;

			// Empty columns are allowed - the user may have just spawned
			// the row and not dragged anything in yet. Validate any
			// fields that are there. Nested rows are NOT supported -
			// reject so this surface stays simple.
			$col_fields = is_array( $col['fields'] ?? null ) ? $col['fields'] : array();
			foreach ( $col_fields as $f_idx => $nested ) {
				$nested = (array) $nested;
				if ( SchemaHelper::is_row( $nested ) ) {
					return new \WP_Error(
						'invalid_form_data',
						sprintf( 'Row at %s.fields[%d] is nested - rows cannot contain other rows.', $col_path, $f_idx ),
						array( 'status' => 422 )
					);
				}
				if ( SchemaHelper::is_step( $nested ) ) {
					return new \WP_Error(
						'invalid_form_data',
						sprintf( 'Step at %s.fields[%d] is nested - steps live only at the top level of the form.', $col_path, $f_idx ),
						array( 'status' => 422 )
					);
				}
				if ( SchemaHelper::is_section( $nested ) ) {
					$section_error = self::validate_section( $nested, $seen_ids, $col_path . '.fields[' . $f_idx . ']' );
					if ( is_wp_error( $section_error ) ) {
						return $section_error;
					}
					continue;
				}
				$field_error = self::validate_field( $nested, $seen_ids, $col_path . '.fields[' . $f_idx . ']' );
				if ( is_wp_error( $field_error ) ) {
					return $field_error;
				}
			}
		}

		return true;
	}

	/**
	 * Validate a single leaf field. Same rules as the original
	 * top-level loop, just factored out for the recursive walker.
	 */
	private static function validate_field( array $field, array &$seen_ids, string $path ) {
		if ( empty( $field['id'] ) ) {
			return new \WP_Error(
				'invalid_form_data',
				sprintf( 'Field at %s is missing an id.', $path ),
				array( 'status' => 422 )
			);
		}

		$id = (string) $field['id'];

		if ( ! preg_match( self::FIELD_ID_PATTERN, $id ) ) {
			return new \WP_Error(
				'invalid_form_data',
				sprintf(
					'Field id "%s" is invalid. Only lowercase letters (a-z), digits (0-9), and underscores (_) are allowed.',
					$id
				),
				array( 'status' => 422 )
			);
		}

		if ( empty( $field['type'] ) ) {
			return new \WP_Error(
				'invalid_form_data',
				sprintf( 'Field "%s" is missing a type.', $id ),
				array( 'status' => 422 )
			);
		}

		if ( ! FieldRegistry::is_registered( $field['type'] ) ) {
			return new \WP_Error(
				'invalid_form_data',
				sprintf(
					'Field "%s" has unregistered type "%s". Registered types: %s.',
					$id,
					$field['type'],
					implode( ', ', FieldRegistry::get_types() )
				),
				array( 'status' => 422 )
			);
		}

		if ( in_array( $id, $seen_ids, true ) ) {
			return new \WP_Error(
				'invalid_form_data',
				sprintf( 'Duplicate field id "%s". Each id must be unique across the form.', $id ),
				array( 'status' => 422 )
			);
		}
		$seen_ids[] = $id;

		// Quiz-specific: at least 2 usable options, exactly one must
		// be marked is_correct === true. Points + show_correct_answer
		// are normalized leniently elsewhere; this gate only rejects
		// quiz schemas that would render an unscorable form.
		if ( $field['type'] === 'quiz_question' ) {
			$options = is_array( $field['options'] ?? null ) ? $field['options'] : array();
			$usable  = array_filter(
				$options,
				static function ( $opt ) {
					if ( ! is_array( $opt ) ) {
						return false;
					}
					$label = (string) ( $opt['label'] ?? '' );
					$value = (string) ( $opt['value'] ?? '' );
					return $label !== '' || $value !== '';
				}
			);
			if ( count( $usable ) < 2 ) {
				return new \WP_Error(
					'invalid_form_data',
					sprintf( 'Quiz question "%s" must have at least two options.', $id ),
					array( 'status' => 422 )
				);
			}
			$correct_count = 0;
			foreach ( $usable as $opt ) {
				if ( ! empty( $opt['is_correct'] ) ) {
					++$correct_count;
				}
			}
			if ( 1 !== $correct_count ) {
				return new \WP_Error(
					'invalid_form_data',
					sprintf( 'Quiz question "%s" must have exactly one correct option (found %d).', $id, $correct_count ),
					array( 'status' => 422 )
				);
			}
		}

		// Poll-specific: must have at least one configured option, and
		// every option must have a label or a value (the renderer
		// promotes label-as-value when value is empty, mirroring radio
		// / select behaviour).
		if ( $field['type'] === 'poll' ) {
			$options       = is_array( $field['options'] ?? null ) ? $field['options'] : array();
			$valid_options = array_filter(
				$options,
				static function ( $opt ) {
					if ( ! is_array( $opt ) ) {
						return false;
					}
					$label = (string) ( $opt['label'] ?? '' );
					$value = (string) ( $opt['value'] ?? '' );
					return $label !== '' || $value !== '';
				}
			);
			if ( empty( $valid_options ) ) {
				return new \WP_Error(
					'invalid_form_data',
					sprintf( 'Poll field "%s" must have at least one option.', $id ),
					array( 'status' => 422 )
				);
			}
		}

		// Repeater-specific: children template validation.
		//
		// The repeater's `fields` array carries TEMPLATE field schemas —
		// not the actual rendered fields on the form. Each rendered
		// iteration on the frontend stamps these templates with a
		// per-index id prefix. We enforce three rules at save time:
		//
		// 1. Settings clamp: min_items >= 0, max_items >= min_items
		// and capped at REPEATER_MAX_ITEMS, columns in [1..4].
		// 2. Child whitelist: only leaf field types from the safe
		// list (text/email/phone/number/url/textarea/select/radio/
		// checkbox/checkboxes/date/file). Nested repeater / matrix
		// row / step / section / poll / quiz_question are
		// EXPLICITLY rejected — see REPEATER_BLOCKED_CHILD_TYPES.
		// 3. Each child runs through validate_field with its own
		// id-uniqueness scope (child ids only need to be unique
		// WITHIN the repeater; they're separate from the top-level
		// id namespace because the rendered iteration prefixes
		// them).
		if ( $field['type'] === 'repeater' ) {
			$settings = is_array( $field['settings'] ?? null ) ? $field['settings'] : array();
			$min      = isset( $settings['min_items'] ) ? (int) $settings['min_items'] : 0;
			$max      = isset( $settings['max_items'] ) ? (int) $settings['max_items'] : 5;
			if ( $min < 0 ) {
				return new \WP_Error(
					'invalid_form_data',
					sprintf( 'Repeater "%s" min_items cannot be negative.', $id ),
					array( 'status' => 422 )
				);
			}
			if ( $max > self::REPEATER_MAX_ITEMS ) {
				return new \WP_Error(
					'invalid_form_data',
					sprintf( 'Repeater "%s" max_items cannot exceed %d.', $id, self::REPEATER_MAX_ITEMS ),
					array( 'status' => 422 )
				);
			}
			if ( $max > 0 && $max < $min ) {
				return new \WP_Error(
					'invalid_form_data',
					sprintf( 'Repeater "%s" max_items (%d) must be greater than or equal to min_items (%d).', $id, $max, $min ),
					array( 'status' => 422 )
				);
			}

			$children  = is_array( $field['fields'] ?? null ) ? $field['fields'] : array();
			$child_ids = array();
			foreach ( $children as $child_index => $child ) {
				if ( ! is_array( $child ) ) {
					return new \WP_Error(
						'invalid_form_data',
						sprintf( 'Repeater "%s" child at index %d is malformed.', $id, $child_index ),
						array( 'status' => 422 )
					);
				}
				$child_type = (string) ( $child['type'] ?? '' );

				if ( in_array( $child_type, self::REPEATER_BLOCKED_CHILD_TYPES, true ) ) {
					return new \WP_Error(
						'invalid_form_data',
						sprintf(
							'Repeater "%s" cannot contain "%s" (nested repeater / matrix / layout / poll / quiz fields are not allowed).',
							$id,
							$child_type
						),
						array( 'status' => 422 )
					);
				}

				if ( ! in_array( $child_type, self::REPEATER_ALLOWED_CHILD_TYPES, true ) ) {
					return new \WP_Error(
						'invalid_form_data',
						sprintf(
							'Repeater "%s" cannot contain "%s". Allowed child types: %s.',
							$id,
							$child_type !== '' ? $child_type : '(empty)',
							implode( ', ', self::REPEATER_ALLOWED_CHILD_TYPES )
						),
						array( 'status' => 422 )
					);
				}

				// Run the same per-field validation against an isolated
				// id namespace — child ids only need to be unique within
				// the repeater (rendered iterations prefix them).
				$child_err = self::validate_field( $child, $child_ids, $path . '.fields[' . $child_index . ']' );
				if ( is_wp_error( $child_err ) ) {
					return $child_err;
				}
			}
		}

		// 1.2.0 part B: Product + Calculated.
		if ( $field['type'] === 'product' ) {
			$err = self::validate_product( $field, $id );
			if ( is_wp_error( $err ) ) {
				return $err;
			}
		}
		if ( $field['type'] === 'calculated' ) {
			try {
				\NeuralForms\Pricing\Expression::parse( (string) ( $field['expression'] ?? '' ) );
			} catch ( \InvalidArgumentException $e ) {
				return new \WP_Error( 'invalid_form_data', sprintf( 'Calculated field "%s": %s', $id, $e->getMessage() ), array( 'status' => 422 ) );
			}
		}

		// Extension hook. Fires after all core rules pass for this field.
		// Return null to pass, or a WP_Error to fail.
		$extra = apply_filters( 'neural_forms_validate_field', null, $field );
		if ( is_wp_error( $extra ) ) {
			return $extra;
		}

		return true;
	}

	/**
	 * Child-type whitelist for the Repeater field. Kept in sync with
	 * the cloud SchemaNormalizer's matching list (commit 6 of the
	 * Repeater plan). v1 deliberately excludes hidden, range,
	 * acceptance, and rating; add them later only after their
	 * per-iteration rendering and validation have been thought
	 * through.
	 */
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

	/**
	 * Child types explicitly REJECTED inside a Repeater. The negative
	 * list is checked BEFORE the positive allowlist so the error
	 * message can call out the specific reason — "no nested repeater"
	 * rather than the generic "type not in allowlist".
	 */
	const REPEATER_BLOCKED_CHILD_TYPES = array(
		'repeater',      // no nested repeaters
		'matrix',        // matrix-in-repeater is a v1 explicit exclusion
		'row',           // layout containers don't make sense inside a leaf-shaped repeater iteration
		'step',
		'section',
		'column',
		'welcome_screen', // welcome screen is a top-level form intro; never a repeater child
		'poll',          // public voting fields don't make sense in a repeated personal context
		'quiz_question',
	);

	/**
	 * Hard ceiling on repeater max_items. Matches the cloud
	 * SchemaNormalizer cap so AI-generated repeaters obey the same
	 * limit. Bumping this requires coordinated changes in the cloud
	 * + the CSV exporter's stable-column model.
	 */
	const REPEATER_MAX_ITEMS = 20;
}
