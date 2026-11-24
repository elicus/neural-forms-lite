<?php
namespace NeuralForms\Pricing;

defined( 'ABSPATH' ) || exit;

/**
 * Coupon codes (1.3.0, Pro via the `calculations` cap).
 *
 * Stored in form settings.pricing.coupons[]:
 *   { id, code, label, type percent|fixed, value, max, min, expires (Y-m-d,
 *     valid through that day, site time), limit (0 = unlimited), enabled }
 *
 * Codes never reach the page: the browser asks POST /forms/{id}/coupon
 * (rate limited) and SubmitEndpoint checks the code again. A coupon is
 * applied AFTER the automatic discount rule, on what is left, and is folded
 * into snapshot.discount (with discount.parts) so quotes, invoices,
 * payments and the inbox keep one discount figure. snapshot.coupon
 * { id, code, amount } is what usage counting reads: uses = entries of the
 * form (not spam / trash) whose snapshot carries that coupon id.
 */
final class Coupons {

	public const MAX       = 50;
	public const MIN_LEN   = 3;
	public const MAX_LEN   = 40;
	public const TRY_CAP   = 10;   // code checks per IP per form per 10 minutes
	private const RL_PREFIX = 'neural_forms_coupon_rl_';

	public static function clean_code( $raw ): string {
		$code = is_scalar( $raw ) ? strtoupper( preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $raw ) ) : '';
		return substr( $code, 0, self::MAX_LEN );
	}

	/** Normalise settings.pricing.coupons. Codes are unique (first one wins). */
	public static function normalize( $raw ): array {
		$out  = array();
		$seen = array();
		foreach ( is_array( $raw ) ? array_values( $raw ) : array() as $i => $c ) {
			if ( ! is_array( $c ) ) {
				continue;
			}
			$code = self::clean_code( $c['code'] ?? '' );
			if ( strlen( $code ) < self::MIN_LEN || isset( $seen[ $code ] ) ) {
				continue;
			}
			$seen[ $code ] = true;
			$id      = isset( $c['id'] ) && is_scalar( $c['id'] ) ? preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $c['id'] ) ) : '';
			$expires = isset( $c['expires'] ) && is_string( $c['expires'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $c['expires'] ) ? $c['expires'] : '';
			$out[]   = array(
				'id'      => $id !== '' ? substr( $id, 0, 40 ) : 'coupon_' . ( $i + 1 ),
				'code'    => $code,
				'label'   => isset( $c['label'] ) && is_string( $c['label'] ) ? mb_substr( trim( wp_strip_all_tags( $c['label'] ) ), 0, 120 ) : '',
				'type'    => ( $c['type'] ?? 'percent' ) === 'fixed' ? 'fixed' : 'percent',
				'value'   => isset( $c['value'] ) && is_scalar( $c['value'] ) ? trim( (string) $c['value'] ) : '0',
				'max'     => isset( $c['max'] ) && is_scalar( $c['max'] ) ? trim( (string) $c['max'] ) : '',
				'min'     => isset( $c['min'] ) && is_scalar( $c['min'] ) ? trim( (string) $c['min'] ) : '',
				'expires' => $expires,
				'limit'   => max( 0, (int) ( $c['limit'] ?? 0 ) ),
				'enabled' => ! array_key_exists( 'enabled', $c ) || ! empty( $c['enabled'] ),
			);
			if ( count( $out ) >= self::MAX ) {
				break;
			}
		}
		return $out;
	}

	/** True when the form has at least one switched-on coupon. */
	public static function form_has_coupons( array $form ): bool {
		foreach ( PriceEngine::settings( $form )['coupons'] as $c ) {
			if ( $c['enabled'] ) {
				return true;
			}
		}
		return false;
	}

	/** The switched-on coupon with this code (case-insensitive), or null. */
	public static function find( array $form, string $code ): ?array {
		$code = self::clean_code( $code );
		if ( $code === '' ) {
			return null;
		}
		foreach ( PriceEngine::settings( $form )['coupons'] as $c ) {
			if ( $c['enabled'] && hash_equals( $c['code'], $code ) ) {
				return $c;
			}
		}
		return null;
	}

	/** Expired = the end of the `expires` day (site time) has passed. */
	public static function is_expired( array $coupon, ?string $today = null ): bool {
		if ( $coupon['expires'] === '' ) {
			return false;
		}
		$today = $today ?? ( function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' ) );
		return strcmp( $today, $coupon['expires'] ) > 0;
	}

	/** Entries (not spam / trash) of this form that used the coupon. */
	public static function uses( int $form_id, string $coupon_id ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'neural_forms_entries';
		$like  = '%' . $wpdb->esc_like( '"coupon":{"id":"' . $coupon_id . '"' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE form_id = %d AND status NOT IN ('spam','trash') AND pricing_snapshot LIKE %s", $form_id, $like ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * The coupon for a typed code, or a WP_Error the visitor can read.
	 * Unknown and switched-off codes get the same answer.
	 *
	 * @return array|\WP_Error
	 */
	public static function check( array $form, string $code, ?callable $uses = null ) {
		$coupon = self::find( $form, $code );
		if ( ! $coupon ) {
			return new \WP_Error( 'coupon_invalid', __( 'That code isn’t valid.', 'neural-forms-lite' ), array( 'status' => 422 ) );
		}
		if ( self::is_expired( $coupon ) ) {
			return new \WP_Error( 'coupon_expired', __( 'That code has expired.', 'neural-forms-lite' ), array( 'status' => 422 ) );
		}
		if ( $coupon['limit'] > 0 ) {
			$used = $uses ? (int) $uses( $coupon ) : self::uses( (int) ( $form['id'] ?? 0 ), $coupon['id'] );
			if ( $used >= $coupon['limit'] ) {
				return new \WP_Error( 'coupon_used_up', __( 'That code has been used up.', 'neural-forms-lite' ), array( 'status' => 422 ) );
			}
		}
		return $coupon;
	}

	/** What the browser needs to mirror the coupon (never the other codes). */
	public static function client_shape( array $coupon, string $currency ): array {
		return array(
			'id'     => $coupon['id'],
			'code'   => $coupon['code'],
			'label'  => self::label( $coupon ),
			'type'   => $coupon['type'],
			'bp'     => $coupon['type'] === 'percent' ? Money::percent_to_basis_points( $coupon['value'] ) : 0,
			'amount' => $coupon['type'] === 'fixed' ? (int) ( Money::to_minor( $coupon['value'], $currency ) ?? 0 ) : 0,
			'max'    => $coupon['max'] !== '' ? Money::to_minor( $coupon['max'], $currency ) : null,
			'min'    => $coupon['min'] !== '' ? Money::to_minor( $coupon['min'], $currency ) : null,
		);
	}

	public static function label( array $coupon ): string {
		/* translators: %s: coupon code */
		return $coupon['label'] !== '' ? $coupon['label'] : sprintf( __( 'Coupon %s', 'neural-forms-lite' ), $coupon['code'] );
	}

	/** Per-IP, per-form guess limit. True when over the cap (the try is counted). */
	public static function over_try_cap( string $ip, int $form_id ): bool {
		$key   = self::RL_PREFIX . md5( wp_salt( 'nonce' ) . '|' . $form_id . '|' . ( $ip === '' ? 'unknown' : $ip ) );
		$count = (int) get_transient( $key );
		if ( $count >= self::TRY_CAP ) {
			return true;
		}
		set_transient( $key, $count + 1, 10 * MINUTE_IN_SECONDS );
		return false;
	}
}
