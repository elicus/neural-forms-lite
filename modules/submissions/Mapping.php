<?php
namespace NeuralForms\Submissions;

use NeuralForms\Forms\SchemaHelper;

defined( 'ABSPATH' ) || exit;

/**
 * Shared helpers for front-end post submissions and user registration
 * (1.3.0, Pro via the `frontend_posts` / `user_registration` caps):
 * field lookups, field → value reading and custom-field key rules.
 */
final class Mapping {

	public const MAX_META = 20;

	/** Meta keys that must never be written from a form, whatever the prefix. */
	private const BLOCKED_META = array(
		'session_tokens', 'default_password_nag', 'use_ssl', 'rich_editing', 'syntax_highlighting',
		'comment_shortcuts', 'admin_color', 'show_admin_bar_front', 'locale', 'dismissed_wp_pointers',
		'community-events-location', 'nickname', 'first_name', 'last_name', 'description',
		'wp_capabilities', 'wp_user_level', 'primary_blog', 'source_domain',
	);

	/** field id => field, for every field in the form (rows / steps flattened). */
	public static function fields_by_id( array $form ): array {
		$out = array();
		foreach ( SchemaHelper::flatten_fields( is_array( $form['form_schema']['fields'] ?? null ) ? $form['form_schema']['fields'] : array() ) as $f ) {
			$f = (array) $f;
			if ( ! empty( $f['id'] ) ) {
				$out[ (string) $f['id'] ] = $f;
			}
		}
		return $out;
	}

	public static function field_id( $raw ): string {
		return is_scalar( $raw ) ? substr( preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $raw ), 0, 64 ) : '';
	}

	/**
	 * Custom-field key a form may write: lowercase letters, digits, _ and -,
	 * never "_"-prefixed (protected), never a table-prefixed key (roles /
	 * levels) and never one of WordPress's own profile keys.
	 */
	public static function safe_meta_key( $raw ): string {
		$key = is_scalar( $raw ) ? strtolower( trim( (string) $raw ) ) : '';
		if ( $key === '' || strlen( $key ) > 64 || ! preg_match( '/^[a-z0-9][a-z0-9_\-]*$/', $key ) ) {
			return '';
		}
		global $wpdb;
		$prefixes = array( 'wp_' );
		if ( isset( $wpdb->base_prefix ) && $wpdb->base_prefix !== '' ) {
			$prefixes[] = strtolower( (string) $wpdb->base_prefix );
		}
		foreach ( $prefixes as $p ) {
			if ( strpos( $key, $p ) === 0 ) {
				return '';
			}
		}
		if ( in_array( $key, self::BLOCKED_META, true ) || strpos( $key, 'capabilities' ) !== false || strpos( $key, 'user_level' ) !== false ) {
			return '';
		}
		return $key;
	}

	/** [{ key, field }] list with safe keys and field ids, max MAX_META. */
	public static function sanitize_meta_list( $raw ): array {
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$key   = self::safe_meta_key( $row['key'] ?? '' );
			$field = self::field_id( $row['field'] ?? '' );
			if ( $key !== '' && $field !== '' ) {
				$out[] = array( 'key' => $key, 'field' => $field );
			}
			if ( count( $out ) >= self::MAX_META ) {
				break;
			}
		}
		return $out;
	}

	/** Plain-text value of a field for titles, names and custom fields. */
	public static function text( array $form, array $data, string $field_id ): string {
		if ( $field_id === '' || ! array_key_exists( $field_id, $data ) ) {
			return '';
		}
		$value = \NeuralForms\Notifications\NotificationService::render_tokens( '{' . $field_id . '}', $form, $data );
		return $value === '{' . $field_id . '}' ? '' : trim( $value );
	}

	/** Names behind a field value: choice labels and raw values (for term matching). */
	public static function choices( array $data, string $field_id ): array {
		$v = $data[ $field_id ] ?? null;
		if ( is_scalar( $v ) ) {
			$v = array( $v );
		}
		$out = array();
		foreach ( is_array( $v ) && ! isset( $v['filename'] ) ? $v : array() as $item ) {
			if ( is_scalar( $item ) && trim( (string) $item ) !== '' ) {
				$out[] = trim( (string) $item );
			}
		}
		return array_slice( array_unique( $out ), 0, 20 );
	}
}
