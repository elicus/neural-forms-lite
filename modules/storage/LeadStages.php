<?php
namespace NeuralForms\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Lead stage registry (1.2.0).
 *
 * `neural_forms_entries.lead_stage` is independent of the inbox status
 * (new / read / spam / trash): an entry can be read AND "Quote sent".
 * Nothing moves a lead to `won` or `lost` except a human or an
 * explicit workflow action.
 *
 * Add-ons may replace or extend the set through the
 * `neural_forms_lead_stages` filter (id => label). Ids are stable
 * machine strings (lowercase, digits, underscore, max 32 chars).
 */
final class LeadStages {

	const DEFAULT_STAGE = 'new';

	/**
	 * @return array<string,string> id => label, in display order.
	 */
	public static function all(): array {
		$defaults = array(
			'new'        => __( 'New enquiry', 'neural-forms-lite' ),
			'contacted'  => __( 'Contacted', 'neural-forms-lite' ),
			'quote_sent' => __( 'Quote sent', 'neural-forms-lite' ),
			'won'        => __( 'Won', 'neural-forms-lite' ),
			'lost'       => __( 'Lost', 'neural-forms-lite' ),
		);

		/**
		 * Filter: neural_forms_lead_stages
		 *
		 * @param array<string,string> $stages id => label.
		 */
		$stages = apply_filters( 'neural_forms_lead_stages', $defaults );
		if ( ! is_array( $stages ) || ! $stages ) {
			$stages = $defaults;
		}

		$clean = array();
		foreach ( $stages as $id => $label ) {
			$id = self::sanitize_id( (string) $id );
			if ( $id === '' ) {
				continue;
			}
			$clean[ $id ] = is_string( $label ) && $label !== '' ? $label : $id;
		}
		if ( ! isset( $clean[ self::DEFAULT_STAGE ] ) ) {
			// The default stage must always exist: new entries are created with it.
			$clean = array( self::DEFAULT_STAGE => $defaults['new'] ) + $clean;
		}
		return $clean;
	}

	/** @return string[] stage ids in display order. */
	public static function ids(): array {
		return array_keys( self::all() );
	}

	public static function is_valid( string $id ): bool {
		return in_array( $id, self::ids(), true );
	}

	public static function label( string $id ): string {
		$all = self::all();
		return $all[ $id ] ?? $id;
	}

	public static function sanitize_id( string $id ): string {
		$id = strtolower( trim( $id ) );
		$id = preg_replace( '/[^a-z0-9_]/', '', $id );
		return substr( (string) $id, 0, 32 );
	}

	/**
	 * Localised list for the admin bundle.
	 *
	 * @return array[] [ { id, label }, ... ]
	 */
	public static function for_script(): array {
		$out = array();
		foreach ( self::all() as $id => $label ) {
			$out[] = array( 'id' => $id, 'label' => $label );
		}
		return $out;
	}
}
