<?php
namespace NeuralForms\Pricing;

use NeuralForms\Forms\ConditionEngine;
use NeuralForms\Forms\SchemaHelper;

defined( 'ABSPATH' ) || exit;

/**
 * Authoritative price engine (1.2.0, part B). Pure: form + submitted data
 * in, snapshot out. The browser mirrors it for live totals; the server
 * result is the only one ever stored or charged.
 *
 * Field schemas it understands:
 *
 *   product   pricing: { mode: fixed | options | input,
 *                        price: "1000.00",                     (fixed)
 *                        display: select | radio | checkboxes, (options; checkboxes = add-ons)
 *                        quantity: { enabled, min, max, default },
 *                        min_amount, max_amount }               (input = visitor types the amount)
 *             options: [ { value, label, price } ]
 *             posted value: { choice: string | string[], qty: "2", amount: "25.00" }
 *   calculated expression: "{a} * {b}", format: number | currency, decimals
 *             never posted; computed here into snapshot.calculated
 *
 * Form settings.pricing:
 *   { currency, discounts: [ { id, label, conditions (ConditionEngine v2), type: percent | fixed, value, max } ],
 *     deposit: { type: none | percent | fixed, value } }
 *
 * Money is integer minor units throughout (Money::to_minor at the
 * edges). Half-up rounding per line and per percentage step.
 */
final class PriceEngine {

	const MAX_QTY   = 999;
	const MAX_LINES = 50;

	/** Default pricing settings for a form. */
	public static function default_settings(): array {
		return array(
			'currency'  => 'USD',
			'discounts' => array(),
			'deposit'   => array( 'type' => 'none', 'value' => '' ),
		);
	}

	public static function settings( array $form ): array {
		$raw = is_array( $form['settings']['pricing'] ?? null ) ? $form['settings']['pricing'] : array();
		return self::normalize_settings( $raw );
	}

	public static function normalize_settings( $raw ): array {
		$raw = is_array( $raw ) ? $raw : array();
		$out = self::default_settings();
		$out['currency'] = Money::normalize_currency( $raw['currency'] ?? 'USD' );
		if ( isset( $raw['discounts'] ) && is_array( $raw['discounts'] ) ) {
			foreach ( array_values( $raw['discounts'] ) as $i => $d ) {
				if ( ! is_array( $d ) ) {
					continue;
				}
				$type = ( $d['type'] ?? 'percent' ) === 'fixed' ? 'fixed' : 'percent';
				$id   = isset( $d['id'] ) && is_scalar( $d['id'] ) ? preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $d['id'] ) ) : '';
				$cond = isset( $d['conditions'] ) && is_array( $d['conditions'] ) ? ConditionEngine::normalize( $d['conditions'] ) : null;
				if ( $cond && ! $cond['rules'] && ! $cond['groups'] ) {
					$cond = null;
				}
				if ( $cond ) {
					$cond['enabled'] = true;
				}
				$out['discounts'][] = array(
					'id'         => $id !== '' ? $id : 'discount_' . ( $i + 1 ),
					'label'      => isset( $d['label'] ) && is_string( $d['label'] ) ? trim( $d['label'] ) : '',
					'conditions' => $cond,
					'type'       => $type,
					'value'      => isset( $d['value'] ) && is_scalar( $d['value'] ) ? trim( (string) $d['value'] ) : '0',
					'max'        => isset( $d['max'] ) && is_scalar( $d['max'] ) && trim( (string) $d['max'] ) !== '' ? trim( (string) $d['max'] ) : '',
				);
				if ( count( $out['discounts'] ) >= 20 ) {
					break;
				}
			}
		}
		$dep = isset( $raw['deposit'] ) && is_array( $raw['deposit'] ) ? $raw['deposit'] : array();
		$dt  = isset( $dep['type'] ) && in_array( $dep['type'], array( 'none', 'percent', 'fixed' ), true ) ? $dep['type'] : 'none';
		$out['deposit'] = array( 'type' => $dt, 'value' => isset( $dep['value'] ) && is_scalar( $dep['value'] ) ? trim( (string) $dep['value'] ) : '' );
		if ( $out['deposit']['value'] === '' ) {
			$out['deposit']['type'] = 'none';
		}
		return $out;
	}

	/** Product field pricing block with defaults. */
	public static function product_pricing( array $field ): array {
		$p    = is_array( $field['pricing'] ?? null ) ? $field['pricing'] : array();
		$mode = isset( $p['mode'] ) && in_array( $p['mode'], array( 'fixed', 'options', 'input' ), true ) ? $p['mode'] : 'fixed';
		$q    = is_array( $p['quantity'] ?? null ) ? $p['quantity'] : array();
		$min  = max( 1, (int) ( $q['min'] ?? 1 ) );
		$max  = max( $min, min( self::MAX_QTY, (int) ( $q['max'] ?? 10 ) ) );
		return array(
			'mode'       => $mode,
			'price'      => isset( $p['price'] ) && is_scalar( $p['price'] ) ? trim( (string) $p['price'] ) : '0',
			'display'    => isset( $p['display'] ) && in_array( $p['display'], array( 'select', 'radio', 'checkboxes' ), true ) ? $p['display'] : 'select',
			'quantity'   => array(
				'enabled' => ! empty( $q['enabled'] ),
				'min'     => $min,
				'max'     => $max,
				'default' => max( $min, min( $max, (int) ( $q['default'] ?? $min ) ) ),
			),
			'min_amount' => isset( $p['min_amount'] ) && is_scalar( $p['min_amount'] ) ? trim( (string) $p['min_amount'] ) : '',
			'max_amount' => isset( $p['max_amount'] ) && is_scalar( $p['max_amount'] ) ? trim( (string) $p['max_amount'] ) : '',
		);
	}

	/** True when the form has at least one product field. */
	public static function form_has_pricing( array $form ): bool {
		foreach ( SchemaHelper::flatten_fields( is_array( $form['form_schema']['fields'] ?? null ) ? $form['form_schema']['fields'] : array() ) as $f ) {
			if ( ( $f['type'] ?? '' ) === 'product' ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Compute the snapshot.
	 *
	 * @param array    $form       Form row (form_schema + settings).
	 * @param array    $data       Submitted data (post-visibility; product values as posted).
	 * @param string[] $hidden_ids Field ids hidden by logic (excluded from totals).
	 * @return array {
	 *   currency, exponent, line_items[], subtotal, discount{id,label,amount}|null, total,
	 *   deposit{type,value,amount}|null, due_now, remaining, calculated{id:{value,display}}, errors[], formula_revision
	 * }
	 */
	public static function evaluate( array $form, array $data, array $hidden_ids = array() ): array {
		$settings = self::settings( $form );
		$currency = $settings['currency'];
		$fields   = SchemaHelper::flatten_fields( is_array( $form['form_schema']['fields'] ?? null ) ? $form['form_schema']['fields'] : array() );
		$hidden   = array_flip( $hidden_ids );
		$types    = array();
		$errors   = array();
		$lines    = array();
		$by_id    = array();

		foreach ( $fields as $f ) {
			$id = (string) ( $f['id'] ?? '' );
			if ( $id === '' ) {
				continue;
			}
			$types[ $id ] = (string) ( $f['type'] ?? 'text' );
			$by_id[ $id ] = $f;
			if ( $types[ $id ] !== 'product' || isset( $hidden[ $id ] ) ) {
				continue;
			}
			foreach ( self::line_items_for( $f, $data[ $id ] ?? null, $currency, $errors ) as $line ) {
				$lines[] = $line;
				if ( count( $lines ) >= self::MAX_LINES ) {
					break 2;
				}
			}
		}

		$subtotal = 0;
		foreach ( $lines as $l ) {
			$subtotal += $l['total'];
		}

		// Discount: first rule whose conditions match (no conditions = always).
		$discount = null;
		$cond_data = $data;
		// Conditions see a product as its CHOICE (option value(s), the typed
		// amount, or '1' for a fixed product); expressions see its TOTAL.
		$per_field = array();
		foreach ( $lines as $l ) {
			$per_field[ $l['field'] ] = ( $per_field[ $l['field'] ] ?? 0 ) + $l['total'];
		}
		foreach ( $types as $fid => $t ) {
			if ( $t !== 'product' ) {
				continue;
			}
			$p = self::product_pricing( $by_id[ $fid ] );
			$v = is_array( $data[ $fid ] ?? null ) ? $data[ $fid ] : array();
			if ( $p['mode'] === 'options' ) {
				$choices = isset( $v['choice'] ) ? ( is_array( $v['choice'] ) ? array_map( 'strval', $v['choice'] ) : array( (string) $v['choice'] ) ) : array();
				$cond_data[ $fid ] = $p['display'] === 'checkboxes' ? $choices : ( $choices[0] ?? '' );
			} elseif ( $p['mode'] === 'input' ) {
				$cond_data[ $fid ] = isset( $per_field[ $fid ] ) ? Money::to_decimal( $per_field[ $fid ], $currency ) : '';
			} else {
				$cond_data[ $fid ] = isset( $per_field[ $fid ] ) ? '1' : '';
			}
		}
		$expr_totals = array();
		foreach ( $per_field as $fid => $sum ) {
			$expr_totals[ $fid ] = (float) Money::to_decimal( $sum, $currency );
		}
		foreach ( $settings['discounts'] as $rule ) {
			if ( $rule['conditions'] && ! ConditionEngine::evaluate( $rule['conditions'], $cond_data, $types, $hidden_ids ) ) {
				continue;
			}
			$amount = $rule['type'] === 'percent'
				? Money::percent_of( $subtotal, $rule['value'] )
				: (int) ( Money::to_minor( $rule['value'], $currency ) ?? 0 );
			$max = $rule['max'] !== '' ? Money::to_minor( $rule['max'], $currency ) : null;
			if ( null !== $max && $max >= 0 ) {
				$amount = min( $amount, $max );
			}
			$amount   = max( 0, min( $amount, $subtotal ) );
			$discount = array( 'id' => $rule['id'], 'label' => $rule['label'], 'type' => $rule['type'], 'value' => $rule['value'], 'amount' => $amount );
			break;
		}

		$total = max( 0, $subtotal - ( $discount['amount'] ?? 0 ) );

		$deposit = null;
		$due_now = $total;
		if ( $settings['deposit']['type'] !== 'none' && $total > 0 ) {
			$amount = $settings['deposit']['type'] === 'percent'
				? Money::percent_of( $total, $settings['deposit']['value'] )
				: (int) ( Money::to_minor( $settings['deposit']['value'], $currency ) ?? 0 );
			$amount  = max( 0, min( $amount, $total ) );
			$deposit = array( 'type' => $settings['deposit']['type'], 'value' => $settings['deposit']['value'], 'amount' => $amount );
			$due_now = $amount > 0 ? $amount : $total;
		}
		$remaining = $total - $due_now;

		// Calculated fields: numeric fields + product line totals as decimals.
		$calculated = array();
		$resolver   = static function ( string $ref ) use ( $data, $expr_totals, $types, $hidden, &$calculated ) {
			if ( isset( $calculated[ $ref ] ) ) {
				return $calculated[ $ref ]['value'];
			}
			if ( isset( $hidden[ $ref ] ) ) {
				return 0.0;
			}
			if ( ( $types[ $ref ] ?? '' ) === 'product' ) {
				return $expr_totals[ $ref ] ?? 0.0;
			}
			$v = $data[ $ref ] ?? null;
			if ( is_array( $v ) ) {
				return 0.0;
			}
			return is_numeric( $v ) ? (float) $v : 0.0;
		};
		foreach ( self::calculated_in_order( $fields ) as $f ) {
			$id = (string) $f['id'];
			if ( isset( $hidden[ $id ] ) ) {
				continue;
			}
			$expr = (string) ( $f['expression'] ?? '' );
			try {
				$value = Expression::compute( $expr, $resolver );
			} catch ( \InvalidArgumentException $e ) {
				$errors[] = array( 'field' => $id, 'message' => $e->getMessage() );
				$value    = null;
			}
			$decimals = max( 0, min( 6, (int) ( $f['decimals'] ?? 2 ) ) );
			$format   = ( $f['format'] ?? 'number' ) === 'currency' ? 'currency' : 'number';
			if ( null === $value ) {
				$errors[]          = array( 'field' => $id, 'message' => 'Could not compute (division by zero?).' );
				$calculated[ $id ] = array( 'value' => 0.0, 'display' => '—', 'format' => $format );
				continue;
			}
			$rounded = round( $value, $format === 'currency' ? Money::exponent( $currency ) : $decimals );
			$display = $format === 'currency'
				? Money::format( (int) Money::to_minor( number_format( $rounded, Money::exponent( $currency ), '.', '' ), $currency ), $currency )
				: number_format( $rounded, $decimals, '.', ',' );
			$calculated[ $id ] = array( 'value' => $rounded, 'display' => $display, 'format' => $format );
		}

		return array(
			'currency'         => $currency,
			'exponent'         => Money::exponent( $currency ),
			'line_items'       => $lines,
			'subtotal'         => $subtotal,
			'discount'         => $discount,
			'total'            => $total,
			'deposit'          => $deposit,
			'due_now'          => $due_now,
			'remaining'        => $remaining,
			'calculated'       => $calculated,
			'errors'           => $errors,
			'formula_revision' => self::formula_revision( $form ),
		);
	}

	/**
	 * Line items for one product field from its posted value.
	 *
	 * @return array[] [ { field, label, choice, choice_label, unit, qty, total } ]
	 */
	public static function line_items_for( array $field, $value, string $currency, array &$errors ): array {
		$id      = (string) $field['id'];
		$label   = (string) ( $field['label'] ?? $id );
		$p       = self::product_pricing( $field );
		$value   = is_array( $value ) ? $value : array();
		$qty     = 1;
		if ( $p['quantity']['enabled'] ) {
			$q   = isset( $value['qty'] ) && is_numeric( $value['qty'] ) ? (int) $value['qty'] : $p['quantity']['default'];
			$qty = max( $p['quantity']['min'], min( $p['quantity']['max'], $q ) );
		}
		$lines = array();

		if ( $p['mode'] === 'fixed' ) {
			$unit = Money::to_minor( $p['price'], $currency );
			if ( null === $unit ) {
				$errors[] = array( 'field' => $id, 'message' => 'Price is not a number.' );
				return array();
			}
			$lines[] = self::line( $id, $label, '', '', $unit, $qty );
			return $lines;
		}

		if ( $p['mode'] === 'input' ) {
			$amount = isset( $value['amount'] ) ? Money::to_minor( $value['amount'], $currency ) : null;
			if ( null === $amount ) {
				return array(); // nothing entered → no line
			}
			$min = $p['min_amount'] !== '' ? Money::to_minor( $p['min_amount'], $currency ) : null;
			$max = $p['max_amount'] !== '' ? Money::to_minor( $p['max_amount'], $currency ) : null;
			if ( null !== $min ) {
				$amount = max( $min, $amount );
			}
			if ( null !== $max ) {
				$amount = min( $max, $amount );
			}
			$amount = max( 0, $amount );
			if ( $amount === 0 ) {
				return array();
			}
			$lines[] = self::line( $id, $label, '', '', $amount, 1 );
			return $lines;
		}

		// options mode
		$prices = array();
		$labels = array();
		foreach ( (array) ( $field['options'] ?? array() ) as $opt ) {
			$opt = (array) $opt;
			$v   = (string) ( $opt['value'] ?? '' );
			if ( $v === '' ) {
				$v = (string) ( $opt['label'] ?? '' );
			}
			if ( $v === '' ) {
				continue;
			}
			$prices[ $v ] = Money::to_minor( $opt['price'] ?? '0', $currency );
			$labels[ $v ] = (string) ( $opt['label'] ?? $v );
		}
		$choices = $value['choice'] ?? array();
		$choices = is_array( $choices ) ? $choices : array( $choices );
		if ( $p['display'] !== 'checkboxes' ) {
			$choices = array_slice( $choices, 0, 1 );
		}
		foreach ( $choices as $c ) {
			$c = (string) $c;
			if ( $c === '' || ! array_key_exists( $c, $prices ) ) {
				continue; // unknown option → ignored, never priced
			}
			$unit = $prices[ $c ];
			if ( null === $unit ) {
				$errors[] = array( 'field' => $id, 'message' => sprintf( 'Option "%s" has a price that is not a number.', $c ) );
				continue;
			}
			$lines[] = self::line( $id, $label, $c, $labels[ $c ], $unit, $qty );
		}
		return $lines;
	}

	private static function line( string $field, string $label, string $choice, string $choice_label, int $unit, int $qty ): array {
		return array(
			'field'        => $field,
			'label'        => $label,
			'choice'       => $choice,
			'choice_label' => $choice_label,
			'unit'         => $unit,
			'qty'          => $qty,
			'total'        => $unit * $qty,
		);
	}

	/**
	 * Calculated fields in dependency order (a field that references
	 * another calculated field comes after it). Cycles are rejected by
	 * FormValidator; here a cycle just falls back to schema order.
	 */
	public static function calculated_in_order( array $fields ): array {
		$calc = array();
		foreach ( $fields as $f ) {
			if ( ( $f['type'] ?? '' ) === 'calculated' && ! empty( $f['id'] ) ) {
				$calc[ (string) $f['id'] ] = $f;
			}
		}
		$deps = array();
		foreach ( $calc as $id => $f ) {
			try {
				$refs = Expression::parse( (string) ( $f['expression'] ?? '' ) )['refs'];
			} catch ( \InvalidArgumentException $e ) {
				$refs = array();
			}
			$deps[ $id ] = array_values( array_filter( $refs, static function ( $r ) use ( $calc ) { return isset( $calc[ $r ] ); } ) );
		}
		$ordered = array();
		$state   = array();
		$visit   = static function ( $id ) use ( &$visit, &$ordered, &$state, $deps, $calc ) {
			if ( isset( $state[ $id ] ) ) {
				return; // visited or in progress (cycle) — skip
			}
			$state[ $id ] = 1;
			foreach ( $deps[ $id ] as $d ) {
				$visit( $d );
			}
			$ordered[] = $calc[ $id ];
		};
		foreach ( array_keys( $calc ) as $id ) {
			$visit( $id );
		}
		return $ordered;
	}

	/**
	 * Detect cycles among calculated fields. Returns the first cycle path or [].
	 */
	public static function find_cycle( array $fields ): array {
		$calc = array();
		foreach ( $fields as $f ) {
			if ( ( $f['type'] ?? '' ) === 'calculated' && ! empty( $f['id'] ) ) {
				try {
					$refs = Expression::parse( (string) ( $f['expression'] ?? '' ) )['refs'];
				} catch ( \InvalidArgumentException $e ) {
					$refs = array();
				}
				$calc[ (string) $f['id'] ] = $refs;
			}
		}
		$colour = array();
		$path   = array();
		$found  = array();
		$dfs    = static function ( $id ) use ( &$dfs, &$colour, &$path, &$found, $calc ) {
			if ( $found ) {
				return;
			}
			$colour[ $id ] = 1;
			$path[]        = $id;
			foreach ( $calc[ $id ] as $r ) {
				if ( ! isset( $calc[ $r ] ) ) {
					continue;
				}
				if ( ( $colour[ $r ] ?? 0 ) === 1 ) {
					$found = array_merge( array_slice( $path, array_search( $r, $path, true ) ), array( $r ) );
					return;
				}
				if ( ! isset( $colour[ $r ] ) ) {
					$dfs( $r );
				}
			}
			array_pop( $path );
			$colour[ $id ] = 2;
		};
		foreach ( array_keys( $calc ) as $id ) {
			if ( ! isset( $colour[ $id ] ) ) {
				$dfs( $id );
			}
		}
		return $found;
	}

	/** Hash of everything that influences money: changes it when prices or rules change. */
	public static function formula_revision( array $form ): string {
		$parts = array( 'settings' => self::settings( $form ), 'products' => array() );
		foreach ( SchemaHelper::flatten_fields( is_array( $form['form_schema']['fields'] ?? null ) ? $form['form_schema']['fields'] : array() ) as $f ) {
			$t = (string) ( $f['type'] ?? '' );
			if ( $t === 'product' ) {
				$parts['products'][ (string) $f['id'] ] = array( 'pricing' => self::product_pricing( $f ), 'options' => $f['options'] ?? array() );
			} elseif ( $t === 'calculated' ) {
				$parts['products'][ (string) $f['id'] ] = array( 'expression' => (string) ( $f['expression'] ?? '' ) );
			}
		}
		return substr( md5( (string) wp_json_encode( $parts ) ), 0, 12 );
	}

	/**
	 * Compact config for the frontend mirror (data-neural-forms-pricing).
	 */
	public static function client_config( array $form ): array {
		$settings = self::settings( $form );
		$currency = $settings['currency'];
		$products = array();
		$calc     = array();
		foreach ( SchemaHelper::flatten_fields( is_array( $form['form_schema']['fields'] ?? null ) ? $form['form_schema']['fields'] : array() ) as $f ) {
			$t = (string) ( $f['type'] ?? '' );
			if ( $t === 'product' ) {
				$p    = self::product_pricing( $f );
				$opts = array();
				foreach ( (array) ( $f['options'] ?? array() ) as $o ) {
					$o = (array) $o;
					$v = (string) ( $o['value'] ?? '' );
					if ( $v === '' ) {
						$v = (string) ( $o['label'] ?? '' );
					}
					if ( $v !== '' ) {
						$opts[ $v ] = (int) ( Money::to_minor( $o['price'] ?? '0', $currency ) ?? 0 );
					}
				}
				$products[] = array(
					'id'       => (string) $f['id'],
					'label'    => (string) ( $f['label'] ?? $f['id'] ),
					'mode'     => $p['mode'],
					'display'  => $p['display'],
					'price'    => (int) ( Money::to_minor( $p['price'], $currency ) ?? 0 ),
					'quantity' => $p['quantity'],
					'min'      => $p['min_amount'] !== '' ? Money::to_minor( $p['min_amount'], $currency ) : null,
					'max'      => $p['max_amount'] !== '' ? Money::to_minor( $p['max_amount'], $currency ) : null,
					'options'  => $opts,
				);
			} elseif ( $t === 'calculated' ) {
				$calc[] = array(
					'id'         => (string) $f['id'],
					'expression' => (string) ( $f['expression'] ?? '' ),
					'format'     => ( $f['format'] ?? 'number' ) === 'currency' ? 'currency' : 'number',
					'decimals'   => max( 0, min( 6, (int) ( $f['decimals'] ?? 2 ) ) ),
				);
			}
		}
		$discounts = array();
		foreach ( $settings['discounts'] as $d ) {
			$discounts[] = array(
				'id'         => $d['id'],
				'label'      => $d['label'],
				'conditions' => $d['conditions'] ? ConditionEngine::for_attribute( $d['conditions'] ) : null,
				'type'       => $d['type'],
				'bp'         => $d['type'] === 'percent' ? Money::percent_to_basis_points( $d['value'] ) : 0,
				'amount'     => $d['type'] === 'fixed' ? (int) ( Money::to_minor( $d['value'], $currency ) ?? 0 ) : 0,
				'max'        => $d['max'] !== '' ? Money::to_minor( $d['max'], $currency ) : null,
			);
		}
		$deposit = $settings['deposit']['type'] === 'none' ? null : array(
			'type'   => $settings['deposit']['type'],
			'bp'     => $settings['deposit']['type'] === 'percent' ? Money::percent_to_basis_points( $settings['deposit']['value'] ) : 0,
			'amount' => $settings['deposit']['type'] === 'fixed' ? (int) ( Money::to_minor( $settings['deposit']['value'], $currency ) ?? 0 ) : 0,
		);
		return array(
			'currency'   => $currency,
			'exponent'   => Money::exponent( $currency ),
			'symbol'     => Money::SYMBOLS[ $currency ] ?? ( $currency . ' ' ),
			'products'   => $products,
			'calculated' => $calc,
			'discounts'  => $discounts,
			'deposit'    => $deposit,
		);
	}
}
