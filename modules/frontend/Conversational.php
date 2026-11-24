<?php
namespace NeuralForms\Frontend;

use NeuralForms\Core\Capabilities;
use NeuralForms\Forms\ConditionEngine;
use NeuralForms\Forms\SchemaHelper;

defined( 'ABSPATH' ) || exit;

/**
 * Conversational forms (1.3.0, Pro via the `conversational_forms` cap):
 * one question per screen, built on the Step engine.
 *
 * Nothing is stored differently. At render time expand() turns the form's
 * field tree into one virtual step per question, so per-step validation,
 * step logic, progress, transitions and the entry preview all come from
 * the existing multi-step code. The server never sees the virtual steps:
 * SubmitEndpoint keeps reading the stored schema, so hidden-field and
 * step-logic rules are exactly the same as for the normal layout.
 *
 * Settings: form.settings.conversational { enabled, auto_advance,
 * show_numbers, progress (bar|none), review, next_label }.
 */
final class Conversational {

	public const PROGRESS = array( 'bar', 'none' );

	/** Virtual-step id prefix (never collides with builder ids). */
	public const STEP_PREFIX = 'nfcv_';

	public static function defaults(): array {
		return array(
			'enabled'      => false,
			'auto_advance' => true,
			'show_numbers' => true,
			'progress'     => 'bar',
			'review'       => false,
			'next_label'   => '',
		);
	}

	/** Clean form.settings.conversational. Empty array when it is all defaults and off. */
	public static function sanitize_settings( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$d   = self::defaults();
		$out = array(
			'enabled'      => ! empty( $raw['enabled'] ),
			'auto_advance' => array_key_exists( 'auto_advance', $raw ) ? ! empty( $raw['auto_advance'] ) : $d['auto_advance'],
			'show_numbers' => array_key_exists( 'show_numbers', $raw ) ? ! empty( $raw['show_numbers'] ) : $d['show_numbers'],
			'progress'     => in_array( $raw['progress'] ?? '', self::PROGRESS, true ) ? (string) $raw['progress'] : $d['progress'],
			'review'       => ! empty( $raw['review'] ),
			'next_label'   => mb_substr( sanitize_text_field( is_scalar( $raw['next_label'] ?? null ) ? (string) $raw['next_label'] : '' ), 0, 40 ),
		);
		return ( $out === $d ) ? array() : $out;
	}

	public static function settings( array $form ): array {
		$raw = $form['settings']['conversational'] ?? null;
		return array_merge( self::defaults(), is_array( $raw ) ? self::sanitize_settings( $raw ) : array() );
	}

	/** On for this form AND unlocked. A lapsed Pro install falls back to the normal layout. */
	public static function active( array $form ): bool {
		return ! empty( $form['settings']['conversational']['enabled'] )
			&& Capabilities::can( Capabilities::CONVERSATIONAL_FORMS );
	}

	/** Save-time check, called from FormValidator::validate_settings_logic(). */
	public static function validate_settings( array $settings ) {
		if ( ! empty( $settings['conversational']['enabled'] ) && ! Capabilities::can( Capabilities::CONVERSATIONAL_FORMS ) ) {
			return Capabilities::locked_error( Capabilities::CONVERSATIONAL_FORMS );
		}
		return true;
	}

	/**
	 * Top-level nodes (welcome screen already removed) → one step per screen.
	 *
	 * - every real field is its own screen; fields in a row are split up;
	 * - a section heading or content block is a "statement" screen;
	 * - hidden fields ride along on the next screen (or the last one);
	 * - the screens of a real step inherit that step's logic, and the
	 *   step's title / description go on its first screen;
	 * - with $review an empty final screen holds the entry preview.
	 *
	 * @return array[] Step nodes. `_cv_kind` = question | statement | review.
	 */
	public static function expand( array $nodes, bool $review = false ): array {
		$screens = array();
		$hidden  = array();

		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			if ( SchemaHelper::is_step( $node ) ) {
				$logic = $node['conditional_logic'] ?? null;
				$logic = ( is_array( $logic ) && ! empty( $logic['enabled'] ) && ConditionEngine::is_active( $logic ) ) ? $logic : null;
				$inner = self::expand_nodes( is_array( $node['fields'] ?? null ) ? $node['fields'] : array(), $hidden );
				$first = true;
				foreach ( $inner as $screen ) {
					if ( $logic !== null ) {
						$screen['conditional_logic'] = $logic;
					}
					if ( $first ) {
						$screen['title']       = (string) ( $node['title'] ?? '' );
						$screen['description'] = (string) ( $node['description'] ?? '' );
						$first                 = false;
					}
					$screens[] = $screen;
				}
				continue;
			}
			foreach ( self::expand_nodes( array( $node ), $hidden ) as $screen ) {
				$screens[] = $screen;
			}
		}

		// Hidden fields with no later screen go on the last one.
		if ( $hidden && $screens ) {
			$last                       = count( $screens ) - 1;
			$screens[ $last ]['fields'] = array_merge( $screens[ $last ]['fields'], $hidden );
		}

		if ( $review && $screens ) {
			$none      = array();
			$screens[] = self::screen( 'review', 'review', array(), $none );
		}
		return $screens;
	}

	/** @param array[] $hidden By-ref buffer of hidden fields waiting for a screen. */
	private static function expand_nodes( array $nodes, array &$hidden ): array {
		$out = array();
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			if ( SchemaHelper::is_row( $node ) ) {
				foreach ( is_array( $node['columns'] ?? null ) ? $node['columns'] : array() as $col ) {
					if ( is_array( $col ) && is_array( $col['fields'] ?? null ) ) {
						foreach ( self::expand_nodes( $col['fields'], $hidden ) as $s ) {
							$out[] = $s;
						}
					}
				}
				continue;
			}
			if ( SchemaHelper::is_step( $node ) || SchemaHelper::is_welcome_screen( $node ) ) {
				continue; // Steps are top level only; nested ones are invalid anyway.
			}
			$type = (string) ( $node['type'] ?? '' );
			$id   = (string) ( $node['id'] ?? '' );
			if ( $type === 'hidden' ) {
				$hidden[] = $node;
				continue;
			}
			if ( SchemaHelper::is_section( $node ) || $type === 'html' ) {
				$out[] = self::screen( $id !== '' ? $id : 's' . count( $out ), 'statement', array( $node ), $hidden );
				continue;
			}
			if ( $id === '' || $type === '' ) {
				continue;
			}
			$out[] = self::screen( $id, 'question', array( $node ), $hidden );
		}
		return $out;
	}

	private static function screen( string $id, string $kind, array $fields, array &$hidden ): array {
		if ( $hidden ) {
			$fields = array_merge( $hidden, $fields );
			$hidden = array();
		}
		return array(
			'type'     => SchemaHelper::STEP_TYPE,
			'id'       => self::STEP_PREFIX . preg_replace( '/[^A-Za-z0-9_\-]/', '', $id ),
			'title'    => '',
			'fields'   => $fields,
			'settings' => array(),
			'_cv_kind' => $kind,
		);
	}
}
