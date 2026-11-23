<?php
/**
 * Builds and recalculates quote snapshots (pure, fixture-tested).
 *
 * A snapshot is a self-contained JSON document: business block,
 * customer block, line items in minor units, discount / deposit and
 * the resulting totals, text blocks and where it came from. Once a
 * quote is issued the snapshot is never touched again — changing the
 * form's prices afterwards cannot alter it (CQ8); a correction is a
 * new revision built with apply_edits() from the previous snapshot.
 */

namespace NeuralForms\Quotes;

use NeuralForms\Pricing\Money;

final class QuoteBuilder {

	const VERSION = 1;

	/**
	 * First snapshot for an entry, from its stored pricing snapshot.
	 *
	 * @param array $entry    Decoded entry (EntryRepository::get()).
	 * @param array $form     Decoded form (FormRepository::find()).
	 * @param array $settings QuoteSettings::get().
	 * @param array $customer Optional { name, email } override.
	 */
	public static function from_entry( array $entry, array $form, array $settings, array $customer = array() ): array {
		$pricing  = is_array( $entry['pricing'] ?? null ) ? $entry['pricing'] : array();
		$currency = Money::normalize_currency( (string) ( $pricing['currency'] ?? 'USD' ) );
		$lines    = array();
		foreach ( is_array( $pricing['line_items'] ?? null ) ? $pricing['line_items'] : array() as $l ) {
			if ( ! is_array( $l ) ) {
				continue;
			}
			$label  = (string) ( $l['choice_label'] ?? '' );
			$desc   = (string) ( $l['label'] ?? '' );
			if ( $label === '' ) {
				$label = $desc;
				$desc  = '';
			} elseif ( $desc === $label ) {
				$desc = '';
			}
			$lines[] = array(
				'label'       => $label,
				'description' => $desc,
				'qty'         => max( 1, (int) ( $l['qty'] ?? 1 ) ),
				'unit'        => (int) ( $l['unit'] ?? 0 ),
			);
		}
		$discount = null;
		if ( is_array( $pricing['discount'] ?? null ) && (int) ( $pricing['discount']['amount'] ?? 0 ) > 0 ) {
			$discount = array(
				'label' => (string) ( $pricing['discount']['label'] ?? '' ),
				'type'  => ( $pricing['discount']['type'] ?? 'fixed' ) === 'percent' ? 'percent' : 'fixed',
				'value' => (string) ( $pricing['discount']['value'] ?? '' ),
			);
			if ( $discount['type'] === 'fixed' ) {
				$discount['value'] = Money::to_decimal( (int) $pricing['discount']['amount'], $currency );
			}
		}
		$deposit = null;
		if ( is_array( $pricing['deposit'] ?? null ) && ( $pricing['deposit']['type'] ?? 'none' ) !== 'none' && (int) ( $pricing['deposit']['amount'] ?? 0 ) > 0 ) {
			$deposit = array(
				'type'  => ( $pricing['deposit']['type'] ?? 'fixed' ) === 'percent' ? 'percent' : 'fixed',
				'value' => (string) ( $pricing['deposit']['value'] ?? '' ),
			);
			if ( $deposit['type'] === 'fixed' ) {
				$deposit['value'] = Money::to_decimal( (int) $pricing['deposit']['amount'], $currency );
			}
		}
		$data = is_array( $entry['data'] ?? null ) ? $entry['data'] : array();
		$cust = array(
			'name'  => (string) ( $customer['name'] ?? '' ),
			'email' => (string) ( $customer['email'] ?? '' ),
		);
		if ( $cust['name'] === '' && class_exists( '\\NeuralForms\\Notifications\\NotificationService' ) && function_exists( 'sanitize_text_field' ) ) {
			$cust['name'] = \NeuralForms\Notifications\NotificationService::submitter_name( $form, $data );
		}
		if ( $cust['email'] === '' && class_exists( '\\NeuralForms\\Notifications\\NotificationService' ) && function_exists( 'is_email' ) ) {
			$cust['email'] = \NeuralForms\Notifications\NotificationService::submitter_email( $form, $data );
		}

		$snapshot = array(
			'version'    => self::VERSION,
			'currency'   => $currency,
			'exponent'   => Money::exponent( $currency ),
			'business'   => self::business_block( $settings ),
			'customer'   => $cust,
			'line_items' => $lines,
			'discount'   => $discount,
			'deposit'    => $deposit,
			'intro'      => (string) ( $settings['intro'] ?? '' ),
			'terms'      => (string) ( $settings['terms'] ?? '' ),
			'footer'     => (string) ( $settings['footer'] ?? '' ),
			'notes'      => '',
			'source'     => array(
				'entry_id'         => (int) ( $entry['id'] ?? 0 ),
				'form_id'          => (int) ( $form['id'] ?? $entry['form_id'] ?? 0 ),
				'form_name'        => (string) ( $form['name'] ?? '' ),
				'formula_revision' => (string) ( $pricing['formula_revision'] ?? '' ),
			),
		);
		return self::recalculate( $snapshot );
	}

	/** Business block copied from settings at build time (branding is part of the record, CQ7). */
	public static function business_block( array $settings ): array {
		return array(
			'name'         => (string) ( $settings['business_name'] ?? '' ),
			'address'      => (string) ( $settings['address'] ?? '' ),
			'email'        => (string) ( $settings['email'] ?? '' ),
			'phone'        => (string) ( $settings['phone'] ?? '' ),
			'website'      => (string) ( $settings['website'] ?? '' ),
			'tax_id'       => (string) ( $settings['tax_id'] ?? '' ),
			'logo_url'     => (string) ( $settings['logo_url'] ?? '' ),
			'accent_color' => (string) ( $settings['accent_color'] ?? '#1e1b4b' ),
		);
	}

	/**
	 * Apply admin edits to a DRAFT snapshot and recalculate. Unknown keys
	 * are ignored; amounts come in as decimal strings in the quote's
	 * currency and are converted to minor units here. Returns the new
	 * snapshot, or a string error message.
	 *
	 * @return array|string
	 */
	public static function apply_edits( array $snapshot, array $edits ) {
		$currency = (string) $snapshot['currency'];
		if ( array_key_exists( 'line_items', $edits ) ) {
			if ( ! is_array( $edits['line_items'] ) ) {
				return 'line_items must be a list.';
			}
			$lines = array();
			foreach ( array_values( $edits['line_items'] ) as $i => $l ) {
				if ( ! is_array( $l ) ) {
					return 'Line ' . ( $i + 1 ) . ' is not an object.';
				}
				$label = self::clean_text( (string) ( $l['label'] ?? '' ), 190 );
				if ( $label === '' ) {
					return 'Line ' . ( $i + 1 ) . ' needs a label.';
				}
				$qty = isset( $l['qty'] ) && is_numeric( $l['qty'] ) ? (int) $l['qty'] : 1;
				if ( $qty < 1 || $qty > 100000 ) {
					return 'Line ' . ( $i + 1 ) . ': quantity must be between 1 and 100000.';
				}
				if ( isset( $l['unit_minor'] ) && is_numeric( $l['unit_minor'] ) ) {
					$unit = (int) $l['unit_minor'];
				} else {
					$unit = Money::to_minor( $l['unit'] ?? '0', $currency );
					if ( null === $unit ) {
						return 'Line ' . ( $i + 1 ) . ': unit price is not a number.';
					}
				}
				if ( $unit < 0 || $unit > 100000000000 ) {
					return 'Line ' . ( $i + 1 ) . ': unit price is out of range.';
				}
				$lines[] = array(
					'label'       => $label,
					'description' => self::clean_text( (string) ( $l['description'] ?? '' ), 500 ),
					'qty'         => $qty,
					'unit'        => $unit,
				);
			}
			if ( count( $lines ) > 100 ) {
				return 'A quote can have at most 100 lines.';
			}
			$snapshot['line_items'] = $lines;
		}
		if ( array_key_exists( 'discount', $edits ) ) {
			$d = $edits['discount'];
			if ( null === $d || ( is_array( $d ) && ( $d['type'] ?? 'none' ) === 'none' ) ) {
				$snapshot['discount'] = null;
			} elseif ( is_array( $d ) ) {
				$type = ( $d['type'] ?? 'fixed' ) === 'percent' ? 'percent' : 'fixed';
				$val  = trim( (string) ( $d['value'] ?? '' ) );
				if ( ! is_numeric( $val ) || (float) $val < 0 || ( $type === 'percent' && (float) $val > 100 ) ) {
					return 'Discount value is invalid.';
				}
				$snapshot['discount'] = array( 'label' => self::clean_text( (string) ( $d['label'] ?? '' ), 120 ), 'type' => $type, 'value' => $val );
			} else {
				return 'Discount must be an object or null.';
			}
		}
		if ( array_key_exists( 'deposit', $edits ) ) {
			$d = $edits['deposit'];
			if ( null === $d || ( is_array( $d ) && ( $d['type'] ?? 'none' ) === 'none' ) ) {
				$snapshot['deposit'] = null;
			} elseif ( is_array( $d ) ) {
				$type = ( $d['type'] ?? 'fixed' ) === 'percent' ? 'percent' : 'fixed';
				$val  = trim( (string) ( $d['value'] ?? '' ) );
				if ( ! is_numeric( $val ) || (float) $val <= 0 || ( $type === 'percent' && (float) $val > 100 ) ) {
					return 'Deposit value is invalid.';
				}
				$snapshot['deposit'] = array( 'type' => $type, 'value' => $val );
			} else {
				return 'Deposit must be an object or null.';
			}
		}
		foreach ( array( 'intro', 'terms', 'footer', 'notes' ) as $k ) {
			if ( array_key_exists( $k, $edits ) && is_scalar( $edits[ $k ] ) ) {
				$snapshot[ $k ] = self::clean_multiline( (string) $edits[ $k ], 4000 );
			}
		}
		if ( isset( $edits['customer'] ) && is_array( $edits['customer'] ) ) {
			$c = $edits['customer'];
			if ( array_key_exists( 'name', $c ) && is_scalar( $c['name'] ) ) {
				$snapshot['customer']['name'] = self::clean_text( (string) $c['name'], 190 );
			}
			if ( array_key_exists( 'email', $c ) && is_scalar( $c['email'] ) ) {
				$email = trim( (string) $c['email'] );
				if ( $email !== '' && function_exists( 'is_email' ) && ! is_email( $email ) ) {
					return 'Customer email is invalid.';
				}
				$snapshot['customer']['email'] = $email;
			}
		}
		return self::recalculate( $snapshot );
	}

	/**
	 * Recompute every derived amount from line_items + discount + deposit.
	 * Same arithmetic as PriceEngine: integer minor units, half-up
	 * percentages, a discount can never push the total below zero, a
	 * deposit never exceeds the total.
	 */
	public static function recalculate( array $s ): array {
		$currency = (string) $s['currency'];
		$subtotal = 0;
		$lines    = array();
		foreach ( is_array( $s['line_items'] ?? null ) ? $s['line_items'] : array() as $l ) {
			$qty        = max( 1, (int) ( $l['qty'] ?? 1 ) );
			$unit       = (int) ( $l['unit'] ?? 0 );
			$l['qty']   = $qty;
			$l['unit']  = $unit;
			$l['total'] = $unit * $qty;
			$subtotal  += $l['total'];
			$lines[]    = $l;
		}
		$s['line_items'] = $lines;
		$s['subtotal']   = $subtotal;

		$discount_amount = 0;
		if ( is_array( $s['discount'] ?? null ) ) {
			$d               = $s['discount'];
			$discount_amount = $d['type'] === 'percent' ? Money::percent_of( $subtotal, $d['value'] ) : (int) ( Money::to_minor( $d['value'], $currency ) ?? 0 );
			$discount_amount = max( 0, min( $subtotal, $discount_amount ) );
			$s['discount']['amount'] = $discount_amount;
		}
		$total      = $subtotal - $discount_amount;
		$s['total'] = $total;

		$due = $total;
		if ( is_array( $s['deposit'] ?? null ) ) {
			$d              = $s['deposit'];
			$deposit_amount = $d['type'] === 'percent' ? Money::percent_of( $total, $d['value'] ) : (int) ( Money::to_minor( $d['value'], $currency ) ?? 0 );
			$deposit_amount = max( 0, min( $total, $deposit_amount ) );
			$s['deposit']['amount'] = $deposit_amount;
			$due                    = $deposit_amount > 0 ? $deposit_amount : $total;
		}
		$s['due_now']   = $due;
		$s['remaining'] = $total - $due;
		return $s;
	}

	/** Human-readable amounts for templates / the inbox. */
	public static function formatted( array $s ): array {
		$c   = (string) $s['currency'];
		$out = array(
			'subtotal'  => Money::format( (int) ( $s['subtotal'] ?? 0 ), $c ),
			'total'     => Money::format( (int) ( $s['total'] ?? 0 ), $c ),
			'due_now'   => Money::format( (int) ( $s['due_now'] ?? 0 ), $c ),
			'remaining' => Money::format( (int) ( $s['remaining'] ?? 0 ), $c ),
			'discount'  => is_array( $s['discount'] ?? null ) ? Money::format( (int) ( $s['discount']['amount'] ?? 0 ), $c ) : '',
			'deposit'   => is_array( $s['deposit'] ?? null ) ? Money::format( (int) ( $s['deposit']['amount'] ?? 0 ), $c ) : '',
			'lines'     => array(),
		);
		foreach ( is_array( $s['line_items'] ?? null ) ? $s['line_items'] : array() as $l ) {
			$out['lines'][] = array( 'unit' => Money::format( (int) $l['unit'], $c ), 'total' => Money::format( (int) $l['total'], $c ) );
		}
		return $out;
	}

	private static function clean_text( string $s, int $max ): string {
		$s = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $s ) : trim( strip_tags( $s ) );
		return mb_substr( $s, 0, $max );
	}

	private static function clean_multiline( string $s, int $max ): string {
		$s = function_exists( 'sanitize_textarea_field' ) ? sanitize_textarea_field( $s ) : trim( strip_tags( $s ) );
		return mb_substr( $s, 0, $max );
	}
}
