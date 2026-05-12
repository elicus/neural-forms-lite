<?php
namespace NeuralForms\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Defensive sanitiser for the `form.settings` blob arriving from the
 * REST save path.
 *
 * Background — why this class exists:
 *
 *   `form.settings` is a free-form JSON blob. Lite's FormValidator
 *   strictly checks field-level nested settings (matrix/repeater
 *   specifics) but deliberately leaves the TOP-level settings blob
 *   opaque so each plugin feature can stash its own shape under its
 *   own key (on_success, on_error, captcha, ai_processing, etc.).
 *
 *   Per-form provider integrations (HubSpot in C3, more providers
 *   later) live under `settings.integrations.<provider>`. Without
 *   sanitisation, ANY client that can POST /forms can:
 *
 *     1. Inject a bogus `hubspot_form_id` containing path-escape
 *        characters (../ or query strings) — harmless at storage
 *        time but a problem the moment the delivery dispatcher
 *        composes a URL with it.
 *     2. Inject delivery telemetry (`last_sent_at`, `last_error`,
 *        `last_status_code`) — fake "this form delivered to HubSpot
 *        last night" entries that could mislead the admin.
 *     3. Stash a bogus `access_token` / `client_secret` field —
 *        not exploited today, but a future "show stored secrets"
 *        bug would surface user-supplied junk.
 *     4. Inject arbitrary keys → silently bloat form.settings rows.
 *
 *   The fix: a strict whitelist + per-value sanitiser for the
 *   integrations subtree, applied in FormsEndpoint before the
 *   payload reaches FormRepository.
 *
 * Scope guarantees:
 *
 *   - The class touches ONLY the `integrations` key. Every other
 *     top-level key in `form.settings` (process_with_ai, security,
 *     notifications, on_success, on_error, captcha, …) round-trips
 *     unchanged. Tests lock this down.
 *   - The `integrations` key only ever stores known providers
 *     (currently `hubspot`). Unknown providers are dropped.
 *   - A completely-empty hubspot config is collapsed away (the
 *     `integrations.hubspot` key is removed) so the row doesn't
 *     accumulate empty `{ enabled: false, ... }` artefacts.
 *
 * Telemetry decision (C4):
 *
 *   Client REST payloads are NOT trusted to set
 *   `last_sent_at` / `last_error` / `last_status_code`. Those keys
 *   are stripped on every save. The delivery dispatcher (C5) will
 *   write them via a dedicated server-side helper that bypasses
 *   this sanitiser.
 */
final class FormSettingsSanitizer {

	// HubSpot form IDs are GUID-shaped: alphanumeric + dashes (we also
	// allow underscores defensively in case HubSpot ever extends it).
	const HS_FORM_ID_PATTERN    = '/^[A-Za-z0-9_\-]+$/';
	// Google form IDs are URL-safe base64 — alphanumeric + dash +
	// underscore. Matches the pattern used elsewhere in the Pro
	// Google Forms layer (GoogleFormsClient::is_valid_form_id).
	const GF_FORM_ID_PATTERN    = '/^[A-Za-z0-9_\-]+$/';
	// Google form URL must be the docs.google.com host. We don't
	// require any specific path so both ../forms/d/{id}/edit and
	// ../forms/d/e/{published-id}/viewform are accepted.
	const GF_FORM_URL_HOST      = 'docs.google.com';
	// HubSpot internal property names use snake_case mostly. Allow
	// letters, digits, underscore, dot (some embedded-form properties),
	// and dash (defensive).
	const HS_FIELD_NAME_PATTERN = '/^[A-Za-z0-9_.\-]+$/';
	// Neural Forms field IDs: alphanumeric, underscore, dash. Matches
	// the sanitize_title-with-underscores convention the builder uses.
	const NF_FIELD_ID_PATTERN   = '/^[A-Za-z0-9_\-]+$/';

	const HS_FORM_NAME_MAX  = 200;
	const HS_FIELD_NAME_MAX = 200;
	const NF_FIELD_ID_MAX   = 100;
	const GF_FORM_TITLE_MAX = 200;
	const GF_IMPORTED_AT_MAX = 40; // "YYYY-MM-DD HH:MM:SS" fits in 19; cap is generous

	/**
	 * Server-side dispatcher writes to settings.integrations.google_forms,
	 * stripped from client payloads. Future telemetry (last_sync_at,
	 * last_checked_at, last_error) will follow the same pattern.
	 */
	const GF_TELEMETRY_KEYS = [
		'last_checked_at', 'last_sync_at', 'last_error',
	];

	/**
	 * Tokens / secrets are NEVER stored in form.settings. The Google
	 * Forms connection lives globally in
	 * neural_forms_pro_google_forms_settings + ..._connection, never
	 * per-form. If a tampered client payload includes any of these
	 * keys under integrations.google_forms, strip them.
	 */
	const GF_FORBIDDEN_KEYS = [
		'access_token', 'refresh_token', 'client_secret', 'token', 'secret',
	];

	const GF_VALID_MODES = [ 'imported_source', 'linked_source' ];

	/**
	 * Keys that MUST NEVER survive a client save. Belt-and-braces
	 * against a copy-paste mistake or an attacker probing.
	 */
	const HS_FORBIDDEN_KEYS = [
		'access_token', 'token', 'private_app_token',
		'client_secret', 'secret',
	];

	/**
	 * Delivery telemetry — server-only. The dispatcher updates these
	 * via a dedicated helper; client payloads cannot touch them.
	 */
	const HS_TELEMETRY_KEYS = [
		'last_sent_at', 'last_error', 'last_status_code',
	];

	/**
	 * Sanitize the entire form.settings blob.
	 *
	 * Pass-through for every key EXCEPT `integrations`. The
	 * integrations key gets the strict treatment defined below.
	 *
	 * @param mixed $settings Raw value from $request->get_param('settings').
	 * @return array<string,mixed>
	 */
	public static function sanitize( $settings ): array {
		if ( ! is_array( $settings ) ) return [];

		$out = $settings;
		if ( array_key_exists( 'integrations', $out ) ) {
			$clean = self::sanitize_integrations( $out['integrations'] );
			if ( empty( $clean ) ) {
				// Don't accumulate empty integrations blobs.
				unset( $out['integrations'] );
			} else {
				$out['integrations'] = $clean;
			}
		}
		return $out;
	}

	/**
	 * Sanitize the `settings.integrations` subtree. Only known
	 * providers are kept; unknown ones (provider name typos, an
	 * older client targeting a removed provider, an attacker
	 * probing) are silently dropped.
	 *
	 * @param mixed $integrations
	 * @return array<string,mixed>
	 */
	public static function sanitize_integrations( $integrations ): array {
		if ( ! is_array( $integrations ) ) return [];

		$out = [];

		if ( array_key_exists( 'hubspot', $integrations ) ) {
			$hubspot = self::sanitize_hubspot_settings( $integrations['hubspot'] );
			if ( ! empty( $hubspot ) ) {
				$out['hubspot'] = $hubspot;
			}
		}

		if ( array_key_exists( 'google_forms', $integrations ) ) {
			$google = self::sanitize_google_forms_settings( $integrations['google_forms'] );
			if ( ! empty( $google ) ) {
				$out['google_forms'] = $google;
			}
		}

		// Future providers (Zapier per-form delivery / Mailchimp /
		// Google Forms response sync) would slot in here.

		return $out;
	}

	/**
	 * Sanitize a single Google Forms per-form config.
	 *
	 * Allowed keys (whitelist; anything else stripped):
	 *   enabled           bool, default false
	 *   mode              'imported_source' | 'linked_source'
	 *   google_form_id    [A-Za-z0-9_-]+ (URL-safe base64). Path-
	 *                      escape chars and control characters
	 *                      rejected to empty.
	 *   google_form_title sanitize_text_field + 200-char cap
	 *   google_form_url   esc_url_raw + must be https + host
	 *                      must be docs.google.com.
	 *   imported_at       sanitize_text_field + 40-char cap.
	 *   sync_responses    bool, default false (placeholder for the
	 *                      future response-sync milestone; never
	 *                      writable as true without server-side
	 *                      coordination).
	 *
	 * Stripped on every save:
	 *   - access_token / refresh_token / client_secret / token /
	 *     secret  (per-form settings NEVER hold credentials)
	 *   - last_checked_at / last_sync_at / last_error (server-only
	 *     telemetry — written by future sync dispatcher, never
	 *     by clients)
	 *   - any other key
	 *
	 * Returns [] when the resulting config has nothing useful so
	 * the parent drops the key (no empty `{enabled:false}` rows).
	 *
	 * @param mixed $google
	 * @return array<string,mixed>
	 */
	public static function sanitize_google_forms_settings( $google ): array {
		if ( ! is_array( $google ) ) return [];

		$enabled = array_key_exists( 'enabled', $google ) ? (bool) $google['enabled'] : false;

		$mode_raw = is_string( $google['mode'] ?? null ) ? trim( (string) $google['mode'] ) : '';
		$mode     = in_array( $mode_raw, self::GF_VALID_MODES, true ) ? $mode_raw : '';

		$form_id_raw = is_string( $google['google_form_id'] ?? null ) ? (string) $google['google_form_id'] : '';
		$form_id     = self::sanitize_google_form_id( $form_id_raw );

		$title_raw = is_string( $google['google_form_title'] ?? null ) ? (string) $google['google_form_title'] : '';
		$title     = self::sanitize_short_text( $title_raw, self::GF_FORM_TITLE_MAX );

		$url_raw = is_string( $google['google_form_url'] ?? null ) ? (string) $google['google_form_url'] : '';
		$url     = self::sanitize_google_form_url( $url_raw );

		$imported_raw = is_string( $google['imported_at'] ?? null ) ? (string) $google['imported_at'] : '';
		$imported_at  = self::sanitize_short_text( $imported_raw, self::GF_IMPORTED_AT_MAX );

		$sync_responses = ! empty( $google['sync_responses'] );

		// Collapse-when-empty (same pattern as HubSpot): a row that
		// carries no useful state drops out entirely.
		if (
			! $enabled
			&& $mode      === ''
			&& $form_id   === ''
			&& $title     === ''
			&& $url       === ''
			&& $imported_at === ''
			&& ! $sync_responses
		) {
			return [];
		}

		return [
			'enabled'           => $enabled,
			'mode'              => $mode,
			'google_form_id'    => $form_id,
			'google_form_title' => $title,
			'google_form_url'   => $url,
			'imported_at'       => $imported_at,
			'sync_responses'    => $sync_responses,
		];
	}

	/**
	 * Same rules as the Pro-side GoogleFormsClient::is_valid_form_id
	 * — alphanumeric + dash + underscore, no path escapes. Public
	 * so tests can hit it directly.
	 */
	public static function sanitize_google_form_id( string $raw ): string {
		$raw = trim( $raw );
		if ( $raw === '' ) return '';
		if ( preg_match( '/[\x00-\x1F\x7F]/', $raw ) ) return '';
		if ( ! preg_match( self::GF_FORM_ID_PATTERN, $raw ) ) return '';
		return $raw;
	}

	/**
	 * Accepts only https URLs hosted at docs.google.com. Returns ''
	 * for anything else — caller treats empty as "no link".
	 */
	public static function sanitize_google_form_url( string $raw ): string {
		$clean = function_exists( 'esc_url_raw' ) ? esc_url_raw( $raw ) : trim( $raw );
		if ( $clean === '' ) return '';
		$scheme = strtolower( (string) ( function_exists( 'wp_parse_url' ) ? wp_parse_url( $clean, PHP_URL_SCHEME ) : parse_url( $clean, PHP_URL_SCHEME ) ) );
		if ( $scheme !== 'https' ) return '';
		$host = strtolower( (string) ( function_exists( 'wp_parse_url' ) ? wp_parse_url( $clean, PHP_URL_HOST ) : parse_url( $clean, PHP_URL_HOST ) ) );
		if ( $host !== self::GF_FORM_URL_HOST ) return '';
		return $clean;
	}

	/**
	 * Sanitize a single HubSpot per-form config.
	 *
	 * @param mixed $hubspot
	 * @return array<string,mixed>  Empty array when the config has nothing useful;
	 *                              the caller drops the key in that case.
	 */
	public static function sanitize_hubspot_settings( $hubspot ): array {
		if ( ! is_array( $hubspot ) ) return [];

		// Build the output from scratch — any key NOT explicitly
		// pulled across is dropped (whitelist semantics). This is
		// what kills forbidden keys (access_token / secret) and
		// telemetry keys (last_sent_at etc.) in one stroke.

		$enabled         = array_key_exists( 'enabled', $hubspot ) ? (bool) $hubspot['enabled'] : false;

		$form_id_raw     = is_string( $hubspot['hubspot_form_id'] ?? null )   ? $hubspot['hubspot_form_id']   : '';
		$form_id         = self::sanitize_hubspot_form_id( $form_id_raw );

		$form_name_raw   = is_string( $hubspot['hubspot_form_name'] ?? null ) ? $hubspot['hubspot_form_name'] : '';
		$form_name       = self::sanitize_short_text( $form_name_raw, self::HS_FORM_NAME_MAX );

		$field_map       = self::sanitize_field_map( $hubspot['field_map'] ?? [] );

		// Collapse-when-empty: a config that's disabled AND has no
		// form / no mapping carries no information. Returning []
		// makes sanitize_integrations() drop the key so the row
		// stays clean. A disabled config WITH a stored mapping is
		// preserved — the admin may have toggled off temporarily.
		if (
			! $enabled
			&& $form_id   === ''
			&& $form_name === ''
			&& empty( $field_map )
		) {
			return [];
		}

		return [
			'enabled'           => $enabled,
			'hubspot_form_id'   => $form_id,
			'hubspot_form_name' => $form_name,
			'field_map'         => $field_map,
		];
	}

	/**
	 * Sanitize a HubSpot form id. Returns '' for anything that
	 * doesn't match the GUID character set. Public so the test
	 * suite can hit it directly + future callers can reuse.
	 */
	public static function sanitize_hubspot_form_id( string $raw ): string {
		$raw = trim( $raw );
		if ( $raw === '' ) return '';
		// Drop control chars defensively (they wouldn't survive the
		// regex below, but the regex doesn't strip — it accepts or
		// rejects whole. A control char anywhere → reject entirely).
		if ( preg_match( '/[\x00-\x1F\x7F]/', $raw ) ) return '';
		if ( ! preg_match( self::HS_FORM_ID_PATTERN, $raw ) ) return '';
		return $raw;
	}

	/**
	 * Sanitize the HubSpot → Neural Forms field map.
	 *
	 *   - Keys must be strings matching the HubSpot property-name
	 *     character set (letters/digits/underscore/dot/dash).
	 *   - Values must be strings matching the Neural Forms field-id
	 *     character set.
	 *   - Empty values are dropped (the UI's "Do not send" choice
	 *     stores ''; we don't persist those — same as the React
	 *     panel's behaviour).
	 *   - Both sides are length-capped to keep a runaway map from
	 *     ballooning the row.
	 *
	 * @param mixed $raw
	 * @return array<string,string>
	 */
	public static function sanitize_field_map( $raw ): array {
		if ( ! is_array( $raw ) ) return [];

		$out = [];
		foreach ( $raw as $hs_name => $nf_id ) {
			if ( ! is_string( $hs_name ) ) continue;
			$hs_name = trim( $hs_name );
			if ( $hs_name === '' ) continue;
			if ( mb_strlen( $hs_name ) > self::HS_FIELD_NAME_MAX ) continue;
			if ( ! preg_match( self::HS_FIELD_NAME_PATTERN, $hs_name ) ) continue;

			// Reject array / object values outright — no nested
			// structures allowed in a field map.
			if ( ! is_string( $nf_id ) ) continue;
			$nf_id = trim( $nf_id );
			if ( $nf_id === '' ) continue;
			if ( mb_strlen( $nf_id ) > self::NF_FIELD_ID_MAX ) continue;
			if ( ! preg_match( self::NF_FIELD_ID_PATTERN, $nf_id ) ) continue;

			$out[ $hs_name ] = $nf_id;
		}
		return $out;
	}

	/**
	 * sanitize_text_field + length cap. Pulled out so the form-name
	 * cap is consistent if we ever apply it elsewhere.
	 */
	private static function sanitize_short_text( string $raw, int $max ): string {
		if ( function_exists( 'sanitize_text_field' ) ) {
			$clean = sanitize_text_field( $raw );
		} else {
			$clean = trim( strip_tags( $raw ) );
		}
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $clean ) > $max ) {
			return mb_substr( $clean, 0, $max );
		}
		if ( strlen( $clean ) > $max ) {
			return substr( $clean, 0, $max );
		}
		return $clean;
	}
}
