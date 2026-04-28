<?php
namespace NeuralForms\Plan;

use NeuralForms\Core\Config;
use NeuralForms\Core\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Plan / capability source of truth.
 *
 * The plugin does not hardcode plan tiers or feature gates. Instead, it mirrors
 * the capability map returned by the Cloud API and checks features against it.
 *
 * ─── Cloud API contract (expected shape from GET /v1/auth/me) ─────────────────
 *
 *   {
 *     "plan":         { "id": "pro", "name": "Pro" },
 *     "capabilities": { "<feature>": bool, ... },
 *     "quota":        { "used": float, "limit": float, "reset_at": "ISO-8601" },
 *     "balance":      { "credits": float, "plan_credits": float, "topup_credits": float, "lifetime_topup_credits": float },
 *     "upgrade_url":  "https://neuralforms.ai/upgrade"
 *   }
 *
 * All usage-denominated fields are expressed in **credits** (1 credit =
 * 1000 tokens). The API stores tokens internally for exact metering and
 * converts to credits at the response boundary, so the plugin never sees
 * tokens.
 *
 * Known capability keys consumed by the plugin (Cloud defines the list):
 *   - ai_analyze           Full entry analysis.
 *   - ai_reply_regenerate  Regenerate reply draft.
 *   - ai_reply_polish      Refine (formerly "polish") a user-composed draft.
 *   - ai_auto_process      Queue analysis automatically on submission.
 *
 * ─── Policy ──────────────────────────────────────────────────────────────────
 *
 * Fail-open on unknown capabilities: if the cached plan has no entry for a
 * feature, can() returns true. This preserves existing behaviour for users on
 * Cloud versions that haven't yet published a capability map. If the Cloud
 * later returns feature_locked for a gated call, the cache is updated and the
 * UI reflects the lock on the next read.
 *
 * ─── Storage ─────────────────────────────────────────────────────────────────
 *
 * All state lives in a single key on the existing neural_forms_settings option:
 *
 *   Config::get( 'cloud_plan' ) = [
 *     'plan'        => [ 'id' => string, 'name' => string ],
 *     'capabilities' => [ <feature> => bool, ... ],
 *     'quota'       => [ 'used' => float, 'limit' => float, 'reset_at' => string ],
 *     'balance'     => [ 'credits' => float, 'plan_credits' => float, 'topup_credits' => float, 'lifetime_topup_credits' => float ],
 *     'subscription' => [
 *       'status'           => 'active'|'trialing'|'pending_cancel'|'expired'|'cancelled',
 *       'period_end'       => ISO string|null,
 *       'grace_expires_at' => unix timestamp|null,
 *     ],
 *     'upgrade_url' => string,
 *     'fetched_at'  => int (unix timestamp),
 *   ]
 */
class PlanManager {

	/** Default when no cached plan exists and no Cloud upgrade_url is known. */
	const DEFAULT_UPGRADE_URL = 'https://neuralforms.ai/pricing';

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * True if the current plan grants the given capability.
	 *
	 * Returns true when the capability is unknown (fail-open).
	 */
	public static function can( string $feature ): bool {
		$caps = self::get_plan()['capabilities'] ?? [];

		if ( ! array_key_exists( $feature, $caps ) ) {
			return true;
		}

		return (bool) $caps[ $feature ];
	}

	/**
	 * Return the full cached plan structure with safe defaults for any missing
	 * keys so callers can dereference fields without null checks.
	 */
	public static function get_plan(): array {
		$stored = Config::get( 'cloud_plan', [] );

		if ( ! is_array( $stored ) ) {
			$stored = [];
		}

		return [
			'plan'         => is_array( $stored['plan'] ?? null )         ? $stored['plan']         : [],
			'capabilities' => is_array( $stored['capabilities'] ?? null ) ? $stored['capabilities'] : [],
			'quota'        => is_array( $stored['quota'] ?? null )        ? $stored['quota']        : [],
			'balance'      => is_array( $stored['balance'] ?? null )      ? $stored['balance']      : [ 'credits' => 0, 'plan_credits' => 0, 'topup_credits' => 0, 'lifetime_topup_credits' => 0 ],
			'subscription' => is_array( $stored['subscription'] ?? null ) ? $stored['subscription'] : self::default_subscription(),
			'upgrade_url'  => (string) ( $stored['upgrade_url'] ?? '' ) !== ''
								? (string) $stored['upgrade_url']
								: self::DEFAULT_UPGRADE_URL,
			'fetched_at'   => (int) ( $stored['fetched_at'] ?? 0 ),
		];
	}

	/**
	 * Default subscription block — used when no plan has been cached yet or
	 * when the Cloud response omits the block (older API versions).
	 */
	private static function default_subscription(): array {
		return [
			'status'           => 'active',
			'period_end'       => null,
			'grace_expires_at' => null,
		];
	}

	/**
	 * True when the cached subscription has lapsed past any grace window.
	 *
	 * Scenarios:
	 *   - status 'active' or 'trialing' → false
	 *   - graceExpiresAt in the future   → false (still covered)
	 *   - 'pending_cancel' + period_end in the future → false (paid through term)
	 *   - else → true (expired / cancelled / grace-lapsed)
	 */
	public static function is_subscription_expired(): bool {
		$sub    = self::get_plan()['subscription'];
		$status = (string) ( $sub['status'] ?? 'active' );

		if ( $status === 'active' || $status === 'trialing' || $status === '' ) {
			return false;
		}

		$now   = time();
		$grace = isset( $sub['grace_expires_at'] ) ? (int) $sub['grace_expires_at'] : 0;
		if ( $grace > 0 && $grace > $now ) {
			return false;
		}

		if ( $status === 'pending_cancel' ) {
			$period_end = isset( $sub['period_end'] ) ? (string) $sub['period_end'] : '';
			if ( $period_end !== '' ) {
				$ts = strtotime( $period_end );
				if ( $ts && $ts > $now ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Shape emitted to the React app (neuralFormsAdmin.plan) and the GET /settings
	 * response. Identical on both surfaces so consumers can share types.
	 */
	public static function public_payload(): array {
		$plan = self::get_plan();

		return [
			'plan'         => $plan['plan'],
			'capabilities' => (object) $plan['capabilities'], // force JS object for stable iteration
			'quota'        => $plan['quota'],
			'balance'      => $plan['balance'],
			'subscription' => $plan['subscription'],
			'upgrade_url'  => $plan['upgrade_url'],
			'fetched_at'   => $plan['fetched_at'],
		];
	}

	/**
	 * Replace the cached plan with the normalized API response.
	 *
	 * @param array $cloud_response Raw decoded body from /v1/auth/me.
	 */
	public static function store_from_response( array $cloud_response ): void {
		$normalized = self::normalize_response( $cloud_response );
		Config::set( 'cloud_plan', $normalized );
	}

	/**
	 * Flip a single capability to false in the cache.
	 *
	 * Invoked when the Cloud returns feature_locked for a previously-granted
	 * capability so the UI locks the control immediately without waiting for
	 * the next /v1/auth/me refresh.
	 */
	public static function mark_feature_locked( string $feature ): void {
		if ( $feature === '' ) return;

		$plan = self::get_plan();
		$plan['capabilities'][ $feature ] = false;
		Config::set( 'cloud_plan', $plan );
	}

	/**
	 * Merge the quota block from a quota_exceeded error into the cache so the
	 * UI can show current usage without refetching.
	 */
	public static function mark_quota_exceeded( array $quota ): void {
		$plan = self::get_plan();
		$plan['quota'] = array_merge( $plan['quota'], self::normalize_quota( $quota ) );
		Config::set( 'cloud_plan', $plan );
	}

	/**
	 * Merge a subscription_expired error payload into the cached subscription
	 * block so the UI reflects the lapsed state without waiting for the next
	 * /v1/auth/me refresh.
	 *
	 * @param array $payload Error body with keys: status, period_end, grace_expires_at.
	 */
	public static function mark_subscription_expired( array $payload ): void {
		$plan = self::get_plan();
		$plan['subscription'] = self::normalize_subscription( array_merge(
			$plan['subscription'],
			[
				'status'           => $payload['status'] ?? 'expired',
				'period_end'       => $payload['period_end'] ?? $plan['subscription']['period_end'] ?? null,
				'grace_expires_at' => $payload['grace_expires_at'] ?? $plan['subscription']['grace_expires_at'] ?? null,
			]
		) );
		Config::set( 'cloud_plan', $plan );
	}

	/**
	 * Clear the cache. Called when cloud_api_key is removed or cloud_ai_enabled
	 * is toggled off so stale plan data never leaks into a disconnected UI.
	 */
	public static function clear(): void {
		Config::set( 'cloud_plan', [] );
	}

	// -------------------------------------------------------------------------
	// Normalization
	// -------------------------------------------------------------------------

	private static function normalize_response( array $body ): array {
		$plan_raw    = is_array( $body['plan'] ?? null ) ? $body['plan'] : [];
		$caps_raw    = is_array( $body['capabilities'] ?? null ) ? $body['capabilities'] : [];
		$quota_raw   = is_array( $body['quota'] ?? null ) ? $body['quota'] : [];
		$balance_raw = is_array( $body['balance'] ?? null ) ? $body['balance'] : [];
		$sub_raw     = is_array( $body['subscription'] ?? null ) ? $body['subscription'] : [];

		$caps = [];
		foreach ( $caps_raw as $key => $val ) {
			if ( ! is_string( $key ) ) continue;
			$caps[ sanitize_key( $key ) ] = (bool) $val;
		}

		return [
			'plan' => [
				'id'   => sanitize_key( (string) ( $plan_raw['id']   ?? '' ) ),
				'name' => sanitize_text_field( (string) ( $plan_raw['name'] ?? '' ) ),
			],
			'capabilities' => $caps,
			'quota'        => self::normalize_quota( $quota_raw ),
			'balance'      => self::normalize_balance( $balance_raw ),
			'subscription' => self::normalize_subscription( $sub_raw ),
			'upgrade_url'  => esc_url_raw( (string) ( $body['upgrade_url'] ?? '' ) ),
			'fetched_at'   => time(),
		];
	}

	private static function normalize_subscription( array $sub ): array {
		$status = sanitize_key( (string) ( $sub['status'] ?? 'active' ) );
		$allowed = [ 'active', 'trialing', 'pending_cancel', 'expired', 'cancelled' ];
		if ( ! in_array( $status, $allowed, true ) ) {
			$status = 'active';
		}
		$grace = $sub['grace_expires_at'] ?? null;
		return [
			'status'           => $status,
			'period_end'       => isset( $sub['period_end'] ) && is_string( $sub['period_end'] )
									? sanitize_text_field( $sub['period_end'] )
									: null,
			'grace_expires_at' => $grace === null ? null : (int) $grace,
		];
	}

	private static function normalize_balance( array $balance ): array {
		return [
			'credits'                => max( 0.0, (float) ( $balance['credits']                ?? 0 ) ),
			'plan_credits'           => max( 0.0, (float) ( $balance['plan_credits']           ?? 0 ) ),
			'topup_credits'          => max( 0.0, (float) ( $balance['topup_credits']          ?? 0 ) ),
			'lifetime_topup_credits' => max( 0.0, (float) ( $balance['lifetime_topup_credits'] ?? 0 ) ),
		];
	}

	private static function normalize_quota( array $quota ): array {
		return [
			'used'     => max( 0.0, (float) ( $quota['used'] ?? 0 ) ),
			'limit'    => max( 0.0, (float) ( $quota['limit'] ?? 0 ) ),
			'reset_at' => sanitize_text_field( (string) ( $quota['reset_at'] ?? '' ) ),
		];
	}
}
