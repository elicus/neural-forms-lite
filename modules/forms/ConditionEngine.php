<?php
namespace NeuralForms\Forms;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Shared condition engine (added 1.2.0).
 *
 * One versioned condition format used by field visibility today and,
 * from 1.2.0 on, by sections, steps, confirmation rules and workflows.
 *
 * Canonical (v2) shape:
 *
 *   {
 *     "enabled": true,
 *     "version": 2,
 *     "action":  "show" | "hide",          // what to do when the tree matches
 *     "match":   "all" | "any",            // across rules + groups
 *     "rules":   [ { "field", "operator", "value" }, ... ],
 *     "groups":  [ { "match", "rules": [...] }, ... ]   // Pro, one level deep
 *   }
 *
 * The 1.0.0 shape ({ enabled, field, operator, value }) is still read
 * everywhere and is normalised to a single-rule v2 tree. Stored schemas
 * are never rewritten in place; the builder writes v2 the next time the
 * logic of that field is saved.
 *
 * evaluate_rule() is the exact port of the pre-1.2 evaluator (previously
 * SubmitEndpoint::evaluate_conditional) so every stored rule behaves as
 * before. form-submit.js carries the same logic in JS; the fixtures in
 * tests/fixtures/conditions.json are run against both.
 */
final class ConditionEngine {

	const VERSION = 2;

	const OPERATORS = array( 'equals', 'not_equals', 'contains', 'empty', 'not_empty', 'gte', 'lte' );
	const ACTIONS   = array( 'show', 'hide' );
	const MATCHES   = array( 'all', 'any' );

	/** Hard caps so a schema cannot carry an unbounded tree. */
	const MAX_RULES  = 10;
	const MAX_GROUPS = 5;

	/**
	 * Normalise any stored conditional_logic value to the v2 shape.
	 *
	 * Never throws; unknown keys are dropped, rules without a field id
	 * are dropped, unknown operators are kept verbatim (they evaluate
	 * to "show" exactly as before; validate() rejects them on save).
	 *
	 * @param mixed $cl Stored value (array, null, anything).
	 * @return array
	 */
	public static function normalize( $cl ): array {
		$out = array(
			'enabled' => false,
			'version' => self::VERSION,
			'action'  => 'show',
			'match'   => 'all',
			'rules'   => array(),
			'groups'  => array(),
		);
		if ( ! is_array( $cl ) ) {
			return $out;
		}

		$out['enabled'] = ! empty( $cl['enabled'] );

		$action = isset( $cl['action'] ) ? (string) $cl['action'] : 'show';
		$out['action'] = in_array( $action, self::ACTIONS, true ) ? $action : 'show';

		$match = isset( $cl['match'] ) ? (string) $cl['match'] : 'all';
		$out['match'] = in_array( $match, self::MATCHES, true ) ? $match : 'all';

		if ( isset( $cl['rules'] ) && is_array( $cl['rules'] ) ) {
			foreach ( $cl['rules'] as $rule ) {
				$r = self::normalize_rule( $rule );
				if ( $r ) {
					$out['rules'][] = $r;
				}
			}
		} elseif ( isset( $cl['field'] ) ) {
			// Legacy 1.0.0 single rule.
			$r = self::normalize_rule( $cl );
			if ( $r ) {
				$out['rules'][] = $r;
			}
		}

		if ( isset( $cl['groups'] ) && is_array( $cl['groups'] ) ) {
			foreach ( $cl['groups'] as $group ) {
				if ( ! is_array( $group ) ) {
					continue;
				}
				$g_match = isset( $group['match'] ) ? (string) $group['match'] : 'all';
				$g       = array(
					'match' => in_array( $g_match, self::MATCHES, true ) ? $g_match : 'all',
					'rules' => array(),
				);
				if ( isset( $group['rules'] ) && is_array( $group['rules'] ) ) {
					foreach ( $group['rules'] as $rule ) {
						$r = self::normalize_rule( $rule );
						if ( $r ) {
							$g['rules'][] = $r;
						}
					}
				}
				if ( $g['rules'] ) {
					$out['groups'][] = $g;
				}
			}
		}

		return $out;
	}

	/**
	 * @param mixed $rule
	 * @return array|null  { field, operator, value } or null when unusable.
	 */
	private static function normalize_rule( $rule ) {
		if ( ! is_array( $rule ) ) {
			return null;
		}
		$field = isset( $rule['field'] ) ? (string) $rule['field'] : '';
		if ( $field === '' ) {
			return null;
		}
		return array(
			'field'    => $field,
			'operator' => isset( $rule['operator'] ) ? (string) $rule['operator'] : 'equals',
			'value'    => isset( $rule['value'] ) && is_scalar( $rule['value'] ) ? (string) $rule['value'] : '',
		);
	}

	/**
	 * True when the logic is enabled and has at least one usable rule.
	 *
	 * @param mixed $cl
	 */
	public static function is_active( $cl ): bool {
		$n = self::normalize( $cl );
		return $n['enabled'] && ( $n['rules'] || $n['groups'] );
	}

	/**
	 * Every field id the tree references (unique, in order of appearance).
	 *
	 * @param mixed $cl
	 * @return string[]
	 */
	public static function references( $cl ): array {
		$n   = self::normalize( $cl );
		$ids = array();
		foreach ( $n['rules'] as $r ) {
			$ids[ $r['field'] ] = true;
		}
		foreach ( $n['groups'] as $g ) {
			foreach ( $g['rules'] as $r ) {
				$ids[ $r['field'] ] = true;
			}
		}
		return array_keys( $ids );
	}

	/**
	 * Compact normalised payload for the frontend data-conditional
	 * attribute. Only emitted keys the JS needs.
	 *
	 * @param mixed $cl
	 */
	public static function for_attribute( $cl ): array {
		$n = self::normalize( $cl );
		$out = array(
			'v'      => self::VERSION,
			'action' => $n['action'],
			'match'  => $n['match'],
			'rules'  => $n['rules'],
		);
		if ( $n['groups'] ) {
			$out['groups'] = $n['groups'];
		}
		return $out;
	}

	/**
	 * Should the owner of this logic be VISIBLE / applicable?
	 *
	 * @param mixed    $cl           Stored logic (any shape).
	 * @param array    $data         Submitted data keyed by field id.
	 * @param array    $field_types  field id => type, for choice coercion.
	 * @param string[] $unavailable  Field ids whose value must not be
	 *                               trusted (hidden controllers). A rule
	 *                               referencing one of them fails.
	 * @return bool
	 */
	public static function evaluate( $cl, array $data, array $field_types = array(), array $unavailable = array() ): bool {
		// `enabled` is deliberately NOT checked here: callers decide whether
		// the logic applies (see is_active()); evaluate() judges the tree it
		// is given. This keeps the pre-1.2 evaluate_conditional() contract.
		$n = self::normalize( $cl );
		if ( ! $n['rules'] && ! $n['groups'] ) {
			return true;
		}
		$blocked = $unavailable ? array_flip( $unavailable ) : array();
		$matched = self::evaluate_group( $n['match'], $n['rules'], $n['groups'], $data, $field_types, $blocked );
		return $n['action'] === 'hide' ? ! $matched : $matched;
	}

	/**
	 * @param string $match
	 * @param array  $rules
	 * @param array  $groups
	 * @param array  $data
	 * @param array  $types
	 * @param array  $blocked  field id => true
	 */
	private static function evaluate_group( string $match, array $rules, array $groups, array $data, array $types, array $blocked ): bool {
		$results = array();
		foreach ( $rules as $rule ) {
			$results[] = isset( $blocked[ $rule['field'] ] )
				? false
				: self::evaluate_rule( $rule, $data, $types );
		}
		foreach ( $groups as $group ) {
			$results[] = self::evaluate_group( $group['match'], $group['rules'], array(), $data, $types, $blocked );
		}
		if ( ! $results ) {
			return true;
		}
		if ( $match === 'any' ) {
			return in_array( true, $results, true );
		}
		return ! in_array( false, $results, true );
	}

	/**
	 * Evaluate ONE rule. Exact port of the pre-1.2 evaluator; the JS
	 * evaluateCondition() in form-submit.js mirrors it. Both must stay
	 * identical so a field hidden in the browser is hidden on the server.
	 *
	 * Coercions:
	 *   - checkbox / acceptance: 1, '1', true  → '1'; anything else → ''
	 *   - choice controller absent from $data → ''  (unticked box is not
	 *     posted on the multipart path)
	 *   - non-choice controller absent        → rule passes (lenient:
	 *     the field cannot be judged, keep it visible)
	 *   - array value: file upload → 'file_uploaded', else scalars
	 *     joined by ','
	 *   - gte / lte with a non-numeric side → false
	 *   - unknown operator → true
	 *
	 * @param array $rule   { field, operator, value }
	 * @param array $data
	 * @param array $field_types
	 */
	public static function evaluate_rule( array $rule, array $data, array $field_types = array() ): bool {
		$control_id = (string) ( $rule['field'] ?? '' );
		$operator   = (string) ( $rule['operator'] ?? '' );
		$rule_value = (string) ( $rule['value'] ?? '' );

		if ( $control_id === '' || $operator === '' ) {
			return true;
		}

		$control_type = (string) ( $field_types[ $control_id ] ?? '' );
		$is_choice    = in_array( $control_type, array( 'checkbox', 'acceptance', 'radio', 'checkboxes' ), true );

		if ( ! array_key_exists( $control_id, $data ) ) {
			if ( ! $is_choice ) {
				return true;
			}
			$control_raw = '';
		} else {
			$control_raw = $data[ $control_id ];
		}

		if ( $control_type === 'checkbox' || $control_type === 'acceptance' ) {
			$control_raw = in_array( $control_raw, array( 1, '1', true ), true ) ? '1' : '';
		}

		if ( is_array( $control_raw ) ) {
			if ( isset( $control_raw['filename'] ) ) {
				$control_val = 'file_uploaded';
			} else {
				$control_val = implode( ',', array_map( 'strval', array_filter( $control_raw, 'is_scalar' ) ) );
			}
		} else {
			$control_val = trim( (string) $control_raw );
		}

		switch ( $operator ) {
			case 'equals':
				return $control_val === $rule_value;

			case 'not_equals':
				return $control_val !== $rule_value;

			case 'contains':
				return $rule_value !== '' && strpos( $control_val, $rule_value ) !== false;

			case 'empty':
				return $control_val === '';

			case 'not_empty':
				return $control_val !== '';

			case 'gte':
			case 'lte':
				if ( ! is_numeric( $control_val ) || ! is_numeric( $rule_value ) ) {
					return false;
				}
				$control_num = (float) $control_val;
				$rule_num    = (float) $rule_value;
				return $operator === 'gte'
					? $control_num >= $rule_num
					: $control_num <= $rule_num;

			default:
				return true;
		}
	}

	/**
	 * Validate a stored logic object before a form is saved.
	 *
	 * @param mixed    $cl         Stored value.
	 * @param string[] $known_ids  Every field id in the form.
	 * @param bool     $advanced   Whether nested groups are allowed
	 *                             (Capabilities::CONDITIONAL_LOGIC_ADVANCED).
	 * @param string   $owner_id   Id of the field/section/step that owns
	 *                             the logic (self-reference check).
	 * @param string   $owner_label Human label for error messages.
	 * @return true|WP_Error
	 */
	public static function validate( $cl, array $known_ids, bool $advanced, string $owner_id = '', string $owner_label = '' ) {
		if ( $cl === null || $cl === array() ) {
			return true;
		}
		if ( ! is_array( $cl ) ) {
			return self::error( sprintf( 'Conditional logic on "%s" must be an object.', $owner_label ) );
		}
		if ( empty( $cl['enabled'] ) ) {
			// Disabled logic is kept verbatim so re-enabling restores it.
			return true;
		}

		$n = self::normalize( $cl );

		if ( isset( $cl['action'] ) && ! in_array( (string) $cl['action'], self::ACTIONS, true ) ) {
			return self::error( sprintf( 'Conditional logic on "%s" has an unknown action "%s".', $owner_label, (string) $cl['action'] ) );
		}
		if ( isset( $cl['match'] ) && ! in_array( (string) $cl['match'], self::MATCHES, true ) ) {
			return self::error( sprintf( 'Conditional logic on "%s" has an unknown match mode "%s".', $owner_label, (string) $cl['match'] ) );
		}
		if ( count( $n['rules'] ) > self::MAX_RULES ) {
			return self::error( sprintf( 'Conditional logic on "%s" has more than %d conditions.', $owner_label, self::MAX_RULES ) );
		}
		if ( $n['groups'] ) {
			if ( ! $advanced ) {
				return new WP_Error(
					'pro_feature_locked',
					sprintf( 'Condition groups on "%s" require Neural Forms Pro.', $owner_label ),
					array(
						'status'       => 403,
						'feature'      => 'conditional_logic_advanced',
						'requires_pro' => true,
					)
				);
			}
			if ( count( $n['groups'] ) > self::MAX_GROUPS ) {
				return self::error( sprintf( 'Conditional logic on "%s" has more than %d condition groups.', $owner_label, self::MAX_GROUPS ) );
			}
			foreach ( $cl['groups'] as $raw_group ) {
				if ( is_array( $raw_group ) && isset( $raw_group['groups'] ) ) {
					return self::error( sprintf( 'Conditional logic on "%s" nests groups too deeply.', $owner_label ) );
				}
				if ( is_array( $raw_group ) && isset( $raw_group['rules'] ) && is_array( $raw_group['rules'] ) && count( $raw_group['rules'] ) > self::MAX_RULES ) {
					return self::error( sprintf( 'A condition group on "%s" has more than %d conditions.', $owner_label, self::MAX_RULES ) );
				}
			}
		}

		$all_rules = $n['rules'];
		foreach ( $n['groups'] as $g ) {
			$all_rules = array_merge( $all_rules, $g['rules'] );
		}

		foreach ( $all_rules as $rule ) {
			if ( ! in_array( $rule['operator'], self::OPERATORS, true ) ) {
				return self::error( sprintf( 'Conditional logic on "%s" uses an unknown operator "%s".', $owner_label, $rule['operator'] ) );
			}
			if ( $owner_id !== '' && $rule['field'] === $owner_id ) {
				return self::error( sprintf( 'Conditional logic on "%s" cannot depend on the field itself.', $owner_label ) );
			}
			if ( ! in_array( $rule['field'], $known_ids, true ) ) {
				return self::error( sprintf( 'Conditional logic on "%s" refers to a field "%s" that is not in this form.', $owner_label, $rule['field'] ) );
			}
		}

		return true;
	}

	private static function error( string $message ): WP_Error {
		return new WP_Error( 'invalid_form_data', $message, array( 'status' => 422 ) );
	}
}
