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

	/** Upper bound on conditional confirmation rules per form (1.2.0). */
	const MAX_CONFIRMATION_RULES = 10;

	// HubSpot form IDs are GUID-shaped: alphanumeric + dashes (we also
	// allow underscores defensively in case HubSpot ever extends it).
	const HS_FORM_ID_PATTERN = '/^[A-Za-z0-9_\-]+$/';
	// Google form IDs are URL-safe base64 — alphanumeric + dash +
	// underscore. Matches the pattern used elsewhere in the Pro
	// Google Forms layer (GoogleFormsClient::is_valid_form_id).
	const GF_FORM_ID_PATTERN = '/^[A-Za-z0-9_\-]+$/';
	// Google form URL must be the docs.google.com host. We don't
	// require any specific path so both ../forms/d/{id}/edit and
	// ../forms/d/e/{published-id}/viewform are accepted.
	const GF_FORM_URL_HOST = 'docs.google.com';
	// HubSpot internal property names use snake_case mostly. Allow
	// letters, digits, underscore, dot (some embedded-form properties),
	// and dash (defensive).
	const HS_FIELD_NAME_PATTERN = '/^[A-Za-z0-9_.\-]+$/';
	// Neural Forms field IDs: alphanumeric, underscore, dash. Matches
	// the sanitize_title-with-underscores convention the builder uses.
	const NF_FIELD_ID_PATTERN = '/^[A-Za-z0-9_\-]+$/';

	const HS_FORM_NAME_MAX   = 200;
	const HS_FIELD_NAME_MAX  = 200;
	const NF_FIELD_ID_MAX    = 100;
	const GF_FORM_TITLE_MAX  = 200;
	const GF_IMPORTED_AT_MAX = 40; // "YYYY-MM-DD HH:MM:SS" fits in 19; cap is generous

	/**
	 * Server-side dispatcher writes to settings.integrations.google_forms,
	 * stripped from client payloads. Future telemetry (last_sync_at,
	 * last_checked_at, last_error) will follow the same pattern.
	 */
	const GF_TELEMETRY_KEYS = array(
		'last_checked_at',
		'last_sync_at',
		'last_error',
	);

	/**
	 * Tokens / secrets are NEVER stored in form.settings. The Google
	 * Forms connection lives globally in
	 * neural_forms_pro_google_forms_settings + ..._connection, never
	 * per-form. If a tampered client payload includes any of these
	 * keys under integrations.google_forms, strip them.
	 */
	const GF_FORBIDDEN_KEYS = array(
		'access_token',
		'refresh_token',
		'client_secret',
		'token',
		'secret',
	);

	const GF_VALID_MODES = array( 'imported_source', 'linked_source' );

	// ── WhatsApp per-form constants ────────────────────────────────

	const WA_TEMPLATE_NAME_MAX    = 100;
	const WA_RECIPIENT_MIN_DIGITS = 7;
	const WA_RECIPIENT_MAX_DIGITS = 15;
	const WA_RECIPIENTS_MAX       = 10;

	const WA_TEMPLATE_NAME_PATTERN = '/^[A-Za-z0-9_\-]+$/';
	const WA_TEMPLATE_LANG_PATTERN = '/^[a-z]{2,3}(?:_[A-Z]{2,3})?$/';

	const WA_VALID_TRIGGERS = array(
		'entry_created',
	);

	/**
	 * `send_when` accepted values. For v1 only `all_entries` actually
	 * fires deliveries — the others are accepted for forward
	 * compatibility (the future AI-analyzed lifecycle hook will
	 * route those) but the UI surfaces them disabled with a
	 * "Coming soon" hint. We accept stored values so an admin who
	 * sets one early doesn't have it silently flipped to
	 * all_entries on every save.
	 */
	const WA_VALID_SEND_WHEN = array(
		'all_entries',
		'high_priority',
		'needs_reply',
		'spam_detected',
		'not_spam',
	);

	/**
	 * Global-connection / secret keys that MUST NEVER appear in
	 * per-form WhatsApp settings. Belt-and-braces against client
	 * payload tampering.
	 */
	const WA_FORBIDDEN_KEYS = array(
		'access_token',
		'token',
		'secret',
		'client_secret',
		'phone_number_id',
		'waba_id',
		'graph_version',
	);

	/**
	 * Server-side telemetry — written by the future dispatcher
	 * helper, never by clients.
	 */
	const WA_TELEMETRY_KEYS = array(
		'last_sent_at',
		'last_error',
		'last_status_code',
	);

	// ── Zapier per-form constants ──────────────────────────────────

	const ZAP_WEBHOOK_IDS_MAX = 10;

	/**
	 * `send_when` values accepted for Zapier form-level settings. We
	 * intentionally accept the full WhatsApp-style enum so admins
	 * who configure forward-looking values aren't silently downgraded
	 * to all_entries on every save. v1 delivery only acts on
	 * all_entries; the AI-conditional triggers will activate once
	 * the existing `neural_forms_entry_analysis_completed` hook is
	 * wired into the Zapier dispatcher.
	 */
	const ZAP_VALID_SEND_WHEN = array(
		'all_entries',
		'high_priority',
		'needs_reply',
		'spam_detected',
		'not_spam',
	);

	/**
	 * Global-connection / secret keys that MUST NEVER appear in
	 * per-form Zapier settings. The webhook URL itself is the bearer
	 * token for an unauthenticated Catch Hook target — duplicating
	 * it into form.settings would multiply the leak surface for no
	 * gain. The form references hooks by id only.
	 */
	const ZAP_FORBIDDEN_KEYS = array(
		'target_url',
		'webhook_url',
		'url',
		'secret',
		'token',
		'client_secret',
	);

	/**
	 * Server-side telemetry — written by WebhookRepository::record_delivery
	 * against the global webhook row, never by clients on the form
	 * settings blob.
	 */
	const ZAP_TELEMETRY_KEYS = array(
		'last_sent_at',
		'last_error',
		'last_status_code',
	);

	// ── Mailchimp per-form constants ───────────────────────────────

	const MC_AUDIENCE_ID_PATTERN = '/^[A-Za-z0-9]{1,32}$/';
	const MC_FIELD_ID_PATTERN    = '/^[A-Za-z0-9_-]{1,128}$/';
	const MC_MERGE_TAG_PATTERN   = '/^[A-Z0-9_]{1,10}$/';
	const MC_AUDIENCE_NAME_MAX   = 200;
	const MC_TAG_MAX_LENGTH      = 100;
	const MC_TAGS_MAX_COUNT      = 20;

	const MC_VALID_STATUS = array( 'subscribed', 'pending' );

	/**
	 * Global-connection / secret keys that MUST NEVER appear in
	 * per-form Mailchimp settings. The API key + server prefix live
	 * globally in neural_forms_pro_mailchimp_settings; tampered client
	 * payloads that try to bleed any of these into a form row are
	 * stripped.
	 */
	const MC_FORBIDDEN_KEYS = array(
		'api_key',
		'key',
		'token',
		'secret',
		'client_secret',
		'server_prefix',
	);

	/**
	 * Server-side telemetry — written by C4's dispatcher helper,
	 * never by clients.
	 */
	const MC_TELEMETRY_KEYS = array(
		'last_sent_at',
		'last_error',
		'last_status_code',
	);

	/**
	 * Keys that MUST NEVER survive a client save. Belt-and-braces
	 * against a copy-paste mistake or an attacker probing.
	 */
	const HS_FORBIDDEN_KEYS = array(
		'access_token',
		'token',
		'private_app_token',
		'client_secret',
		'secret',
	);

	/**
	 * Delivery telemetry — server-only. The dispatcher updates these
	 * via a dedicated helper; client payloads cannot touch them.
	 */
	const HS_TELEMETRY_KEYS = array(
		'last_sent_at',
		'last_error',
		'last_status_code',
	);

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
		if ( ! is_array( $settings ) ) {
			return array();
		}

		$out = $settings;
		if ( isset( $out['on_success'] ) && is_array( $out['on_success'] ) ) {
			$out['on_success'] = self::sanitize_on_success( $out['on_success'] );
		}
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
	 * Sanitize `settings.on_success` (1.2.0). The default outcome
	 * (mode / message / url) round-trips as before; the optional
	 * `rules[]` list (conditional confirmations, Pro) is normalised:
	 *
	 *   rules[] = { id, conditions: <ConditionEngine v2, enabled>,
	 *               mode: message|redirect, message, url }
	 *
	 * Unknown keys are dropped, URLs go through esc_url_raw, messages
	 * through wp_kses_post, at most MAX_CONFIRMATION_RULES rules are
	 * kept, and a rule without any usable condition is dropped. The
	 * schema-aware checks (field references exist, Pro unlocked) run in
	 * FormValidator::validate_settings_logic() from FormsEndpoint.
	 *
	 * @param array $on_success
	 * @return array
	 */
	public static function sanitize_on_success( array $on_success ): array {
		if ( ! array_key_exists( 'rules', $on_success ) ) {
			return $on_success;
		}
		$rules_in = is_array( $on_success['rules'] ) ? $on_success['rules'] : array();
		$rules    = array();
		foreach ( $rules_in as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			$conditions = is_array( $rule['conditions'] ?? null ) ? $rule['conditions'] : array();
			$norm       = ConditionEngine::normalize( $conditions );
			$norm['enabled'] = true;
			if ( ! $norm['rules'] && ! $norm['groups'] ) {
				continue;
			}
			$id = isset( $rule['id'] ) && is_scalar( $rule['id'] ) ? sanitize_key( (string) $rule['id'] ) : '';
			if ( $id === '' ) {
				$id = 'rule_' . substr( md5( (string) wp_json_encode( $norm ) . count( $rules ) ), 0, 8 );
			}
			$mode = ( $rule['mode'] ?? 'message' ) === 'redirect' ? 'redirect' : 'message';
			$rules[] = array(
				'id'         => $id,
				'conditions' => $norm,
				'mode'       => $mode,
				'message'    => isset( $rule['message'] ) && is_string( $rule['message'] ) ? wp_kses_post( $rule['message'] ) : '',
				'url'        => isset( $rule['url'] ) && is_string( $rule['url'] ) ? esc_url_raw( $rule['url'] ) : '',
			);
			if ( count( $rules ) >= self::MAX_CONFIRMATION_RULES ) {
				break;
			}
		}
		if ( $rules ) {
			$on_success['rules'] = $rules;
		} else {
			unset( $on_success['rules'] );
		}
		return $on_success;
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
		if ( ! is_array( $integrations ) ) {
			return array();
		}

		$out = array();

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

		if ( array_key_exists( 'whatsapp', $integrations ) ) {
			$whatsapp = self::sanitize_whatsapp_settings( $integrations['whatsapp'] );
			if ( ! empty( $whatsapp ) ) {
				$out['whatsapp'] = $whatsapp;
			}
		}

		if ( array_key_exists( 'mailchimp', $integrations ) ) {
			$mailchimp = self::sanitize_mailchimp_settings( $integrations['mailchimp'] );
			if ( ! empty( $mailchimp ) ) {
				$out['mailchimp'] = $mailchimp;
			}
		}

		if ( array_key_exists( 'zapier', $integrations ) ) {
			$zapier = self::sanitize_zapier_settings( $integrations['zapier'] );
			if ( ! empty( $zapier ) ) {
				$out['zapier'] = $zapier;
			}
		}

		// Future providers (Google Forms response sync …) would slot
		// in here.

		return $out;
	}

	/**
	 * Sanitize a single WhatsApp per-form config.
	 *
	 * Allowed keys (whitelist; anything else stripped):
	 *   enabled            bool, default false
	 *   recipients         array<string,digits-only>, max 10,
	 *                       deduped, each entry 7..15 digits
	 *   trigger            enum, currently only 'entry_created'
	 *   template_name      safe string (alphanumeric + _ -), max 100
	 *   template_language  BCP47-ish, default en_US
	 *   send_when          enum (all_entries active in v1;
	 *                       high_priority/needs_reply/spam_detected/
	 *                       not_spam accepted but inactive until
	 *                       AI-lifecycle hook lands)
	 *   include_summary    bool, default true
	 *
	 * Stripped on every save:
	 *   - global / secret keys (access_token / token / secret /
	 *     client_secret / phone_number_id / waba_id / graph_version)
	 *   - server-side telemetry (last_sent_at / last_error /
	 *     last_status_code)
	 *   - any other unknown key
	 *
	 * Returns [] when the resulting config has nothing useful so
	 * the parent drops the key (no empty `{enabled:false}` rows).
	 *
	 * recipients input shapes accepted (per spec):
	 *   - array of strings
	 *   - single string with linebreaks / commas (parsed)
	 *
	 * @param mixed $whatsapp
	 * @return array<string,mixed>
	 */
	public static function sanitize_whatsapp_settings( $whatsapp ): array {
		if ( ! is_array( $whatsapp ) ) {
			return array();
		}

		$enabled    = array_key_exists( 'enabled', $whatsapp ) ? (bool) $whatsapp['enabled'] : false;
		$recipients = self::sanitize_whatsapp_recipients( $whatsapp['recipients'] ?? array() );

		$trigger_raw = is_string( $whatsapp['trigger'] ?? null ) ? trim( (string) $whatsapp['trigger'] ) : '';
		$trigger     = in_array( $trigger_raw, self::WA_VALID_TRIGGERS, true ) ? $trigger_raw : 'entry_created';

		$template_name = self::sanitize_whatsapp_template_name( $whatsapp['template_name'] ?? '' );

		$template_language = self::sanitize_whatsapp_template_language( $whatsapp['template_language'] ?? '' );

		$send_when_raw = is_string( $whatsapp['send_when'] ?? null ) ? trim( (string) $whatsapp['send_when'] ) : '';
		$send_when     = in_array( $send_when_raw, self::WA_VALID_SEND_WHEN, true ) ? $send_when_raw : 'all_entries';

		// include_summary defaults to true (most admins want the
		// templated summary). Only flips to false on explicit
		// false-y input.
		$include_summary = array_key_exists( 'include_summary', $whatsapp )
			? (bool) $whatsapp['include_summary']
			: true;

		// Collapse-when-empty: disabled AND no recipients AND no
		// template_name carries no information.
		if (
			! $enabled
			&& empty( $recipients )
			&& $template_name === ''
		) {
			return array();
		}

		return array(
			'enabled'           => $enabled,
			'recipients'        => $recipients,
			'trigger'           => $trigger,
			'template_name'     => $template_name,
			'template_language' => $template_language,
			'send_when'         => $send_when,
			'include_summary'   => $include_summary,
		);
	}

	/**
	 * Normalize the recipients input into a digits-only array.
	 *
	 * Accepts:
	 *   - array<string>  (canonical UI shape)
	 *   - string         (textarea with linebreak / comma separated
	 *                     numbers — defensive in case a tampered
	 *                     payload sends the textarea raw)
	 *
	 * Per number:
	 *   - strip non-digits
	 *   - require 7..15 digits (E.164 length bounds)
	 *   - drop everything else
	 *
	 * The final list is deduped and capped at WA_RECIPIENTS_MAX so
	 * a tampered payload can't ship a million-number array.
	 *
	 * @param mixed $raw
	 * @return array<int,string>
	 */
	public static function sanitize_whatsapp_recipients( $raw ): array {
		$rows = array();
		if ( is_array( $raw ) ) {
			foreach ( $raw as $r ) {
				if ( is_scalar( $r ) ) {
					$rows[] = (string) $r;
				}
			}
		} elseif ( is_string( $raw ) ) {
			$rows = preg_split( '/[\r\n,]+/', $raw );
			$rows = is_array( $rows ) ? $rows : array();
		}

		$out  = array();
		$seen = array();
		foreach ( $rows as $r ) {
			if ( ! is_string( $r ) ) {
				continue;
			}
			$digits = preg_replace( '/[^0-9]/', '', $r );
			if ( ! is_string( $digits ) || $digits === '' ) {
				continue;
			}
			$len = strlen( $digits );
			if ( $len < self::WA_RECIPIENT_MIN_DIGITS || $len > self::WA_RECIPIENT_MAX_DIGITS ) {
				continue;
			}
			if ( isset( $seen[ $digits ] ) ) {
				continue;
			}
			$seen[ $digits ] = true;
			$out[]           = $digits;
			if ( count( $out ) >= self::WA_RECIPIENTS_MAX ) {
				break;
			}
		}
		return $out;
	}

	public static function sanitize_whatsapp_template_name( $raw ): string {
		if ( ! is_string( $raw ) ) {
			return '';
		}
		$raw = trim( $raw );
		if ( $raw === '' ) {
			return '';
		}
		if ( strlen( $raw ) > self::WA_TEMPLATE_NAME_MAX ) {
			return '';
		}
		if ( ! preg_match( self::WA_TEMPLATE_NAME_PATTERN, $raw ) ) {
			return '';
		}
		return $raw;
	}

	public static function sanitize_whatsapp_template_language( $raw ): string {
		$default = 'en_US';
		if ( ! is_string( $raw ) ) {
			return $default;
		}
		$raw = trim( $raw );
		if ( $raw === '' ) {
			return $default;
		}
		if ( ! preg_match( self::WA_TEMPLATE_LANG_PATTERN, $raw ) ) {
			return $default;
		}
		return $raw;
	}

	/**
	 * Sanitize a single Mailchimp per-form config.
	 *
	 * Allowed keys (whitelist; anything else stripped):
	 *   enabled         bool, default false
	 *   audience_id     [A-Za-z0-9]{1,32} — Mailchimp list id slug
	 *   audience_name   sanitized text, ≤ 200 chars (display label
	 *                    cached client-side from the audiences list)
	 *   email_field     [A-Za-z0-9_-]{1,128} — Neural Forms field id
	 *                    of the email field that drives the subscriber
	 *                    address
	 *   status          'subscribed' | 'pending' (per-form override
	 *                    of the global default)
	 *   update_existing bool, default true (PUT semantics with
	 *                    status_if_new — see C4 dispatcher)
	 *   tags            array<string>, ≤ 20 entries, dedupe + control-
	 *                    char strip + 100-char cap per tag
	 *   merge_map       object<MAILCHIMP_MERGE_TAG, NF_FIELD_ID>
	 *                    keys match [A-Z0-9_]{1,10}; values match the
	 *                    NF field id pattern. EMAIL is sanitized OUT
	 *                    of merge_map (the dedicated email_field key
	 *                    drives the email_address) — see C4 docs.
	 *
	 * Stripped on every save:
	 *   - global / secret keys (api_key / key / token / secret /
	 *     client_secret / server_prefix)
	 *   - server-side telemetry (last_sent_at / last_error /
	 *     last_status_code)
	 *   - any other unknown key
	 *
	 * Returns [] when the resulting config carries no useful
	 * information so the parent drops the key (no empty
	 * `{enabled:false}` rows). A disabled-but-with-mapping config IS
	 * preserved so admins can pre-configure mapping then flip the
	 * switch on later.
	 *
	 * @param mixed $mailchimp
	 * @return array<string,mixed>
	 */
	public static function sanitize_mailchimp_settings( $mailchimp ): array {
		if ( ! is_array( $mailchimp ) ) {
			return array();
		}

		$enabled       = array_key_exists( 'enabled', $mailchimp ) ? (bool) $mailchimp['enabled'] : false;
		$audience_id   = self::sanitize_mailchimp_audience_id( $mailchimp['audience_id'] ?? '' );
		$audience_name = self::sanitize_mailchimp_audience_name( $mailchimp['audience_name'] ?? '' );
		$email_field   = self::sanitize_mailchimp_field_id( $mailchimp['email_field'] ?? '' );

		$status_raw = is_string( $mailchimp['status'] ?? null ) ? strtolower( trim( (string) $mailchimp['status'] ) ) : '';
		$status     = in_array( $status_raw, self::MC_VALID_STATUS, true ) ? $status_raw : 'subscribed';

		// update_existing defaults to true (most admins want
		// "add-or-update" semantics; the false case is a niche
		// "create new subscribers only" workflow).
		$update_existing = array_key_exists( 'update_existing', $mailchimp )
			? (bool) $mailchimp['update_existing']
			: true;

		$tags      = self::sanitize_mailchimp_tags( $mailchimp['tags'] ?? array() );
		$merge_map = self::sanitize_mailchimp_merge_map( $mailchimp['merge_map'] ?? array() );

		// Collapse-when-empty: disabled AND no audience_id AND no
		// email_field AND no mapping carries no information.
		if (
			! $enabled
			&& $audience_id === ''
			&& $email_field === ''
			&& empty( $tags )
			&& empty( $merge_map )
		) {
			return array();
		}

		return array(
			'enabled'         => $enabled,
			'audience_id'     => $audience_id,
			'audience_name'   => $audience_name,
			'email_field'     => $email_field,
			'status'          => $status,
			'update_existing' => $update_existing,
			'tags'            => $tags,
			'merge_map'       => $merge_map,
		);
	}

	public static function sanitize_mailchimp_audience_id( $raw ): string {
		if ( ! is_string( $raw ) ) {
			return '';
		}
		$raw = trim( $raw );
		if ( $raw === '' ) {
			return '';
		}
		if ( ! preg_match( self::MC_AUDIENCE_ID_PATTERN, $raw ) ) {
			return '';
		}
		return $raw;
	}

	public static function sanitize_mailchimp_audience_name( $raw ): string {
		if ( ! is_string( $raw ) ) {
			return '';
		}
		// Strip control chars; rely on sanitize_text_field for the rest.
		$raw = preg_replace( '/[\x00-\x1F\x7F]/u', '', $raw );
		$raw = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $raw ) : trim( (string) $raw );
		if ( $raw === '' ) {
			return '';
		}
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $raw ) > self::MC_AUDIENCE_NAME_MAX ) {
			$raw = function_exists( 'mb_substr' ) ? mb_substr( $raw, 0, self::MC_AUDIENCE_NAME_MAX ) : substr( $raw, 0, self::MC_AUDIENCE_NAME_MAX );
		} elseif ( strlen( $raw ) > self::MC_AUDIENCE_NAME_MAX ) {
			$raw = substr( $raw, 0, self::MC_AUDIENCE_NAME_MAX );
		}
		return $raw;
	}

	/**
	 * Generic Neural Forms field-id sanitizer used by Mailchimp's
	 * email_field + merge_map values. Field ids in the canonical
	 * schema look like `email_abc123`, `tx_q1`, etc. — alphanumeric
	 * + underscores + hyphens, length-capped. Public so any future
	 * provider can reuse the same shape.
	 */
	public static function sanitize_mailchimp_field_id( $raw ): string {
		if ( ! is_string( $raw ) ) {
			return '';
		}
		$raw = trim( $raw );
		if ( $raw === '' ) {
			return '';
		}
		if ( ! preg_match( self::MC_FIELD_ID_PATTERN, $raw ) ) {
			return '';
		}
		return $raw;
	}

	/**
	 * Mailchimp tags: array of short strings. Trim, strip control
	 * chars, length-cap each, dedupe case-insensitively (Mailchimp
	 * itself dedupes that way), cap the array at MC_TAGS_MAX_COUNT.
	 *
	 * @param mixed $raw
	 * @return array<int,string>
	 */
	public static function sanitize_mailchimp_tags( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out  = array();
		$seen = array();
		foreach ( $raw as $tag ) {
			if ( ! is_string( $tag ) ) {
				continue;
			}
			$tag = trim( preg_replace( '/[\x00-\x1F\x7F]/u', '', $tag ) ?? '' );
			if ( $tag === '' ) {
				continue;
			}
			if ( strlen( $tag ) > self::MC_TAG_MAX_LENGTH ) {
				$tag = substr( $tag, 0, self::MC_TAG_MAX_LENGTH );
			}
			$key = strtolower( $tag );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = $tag;
			if ( count( $out ) >= self::MC_TAGS_MAX_COUNT ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Mailchimp merge_map: object of MERGE_TAG → field_id.
	 *
	 * Per Mailchimp's API conventions, merge tags are uppercase
	 * alphanumeric + underscore (FNAME, LNAME, PHONE, etc.), capped
	 * at 10 chars. We reject anything else as the key. Values are
	 * Neural Forms field ids and must match MC_FIELD_ID_PATTERN.
	 *
	 * The reserved `EMAIL` tag is dropped — the email address is
	 * driven by the dedicated `email_field` key, not by merge_map.
	 * Including EMAIL in merge_map would let an admin point two
	 * fields at the same surface and produce confusing semantics.
	 *
	 * @param mixed $raw
	 * @return array<string,string>
	 */
	public static function sanitize_mailchimp_merge_map( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $k => $v ) {
			if ( ! is_string( $k ) ) {
				continue;
			}
			$key = strtoupper( trim( $k ) );
			if ( $key === '' || $key === 'EMAIL' ) {
				continue;
			}
			if ( ! preg_match( self::MC_MERGE_TAG_PATTERN, $key ) ) {
				continue;
			}
			$field_id = self::sanitize_mailchimp_field_id( is_scalar( $v ) ? (string) $v : '' );
			if ( $field_id === '' ) {
				continue;
			}
			$out[ $key ] = $field_id;
		}
		return $out;
	}

	/**
	 * Sanitize a single Zapier per-form config.
	 *
	 * Per-form Zapier settings are an OVERRIDE on top of the global
	 * Zapier webhooks (which live in Pro's
	 * `neural_forms_pro_integrations_webhooks` option, keyed by
	 * provider + form_id + event). The presence of a Zapier sub-blob
	 * on a form means "ignore the find_active() default selection
	 * for this form — use my explicit webhook_ids instead". Absence
	 * means "deliver using the existing find_active() behavior
	 * (all-forms hooks + form-scoped hooks)".
	 *
	 * Allowed keys (whitelist; anything else stripped):
	 *   enabled                  bool, default false
	 *   webhook_ids              array<int>, ≤ 10 entries, deduped,
	 *                             every id ≥ 1
	 *   send_when                enum (only all_entries fires in v1;
	 *                             other values accepted for forward
	 *                             compatibility with the AI-analysis
	 *                             hook)
	 *   include_fields_readable  bool, default true
	 *   include_raw_fields       bool, default true
	 *   include_source           bool, default true
	 *
	 * Stripped on every save:
	 *   - target_url / webhook_url / url (webhook URLs live globally
	 *     only; per-form settings reference hooks by id)
	 *   - secret / token / client_secret
	 *   - last_sent_at / last_status_code / last_error (telemetry
	 *     lives on the global webhook row, not the form)
	 *   - any other unknown key
	 *
	 * Returns [] when the resulting config carries no useful
	 * information so the parent drops the key (no empty
	 * `{enabled:false}` rows). A disabled-with-mapping config IS
	 * preserved so admins can pre-pick hooks then flip the switch
	 * on later — matches the Mailchimp / WhatsApp behaviour.
	 *
	 * @param mixed $zapier
	 * @return array<string,mixed>
	 */
	public static function sanitize_zapier_settings( $zapier ): array {
		if ( ! is_array( $zapier ) ) {
			return array();
		}

		$enabled = array_key_exists( 'enabled', $zapier ) ? (bool) $zapier['enabled'] : false;

		$webhook_ids = self::sanitize_zapier_webhook_ids( $zapier['webhook_ids'] ?? array() );

		$send_when_raw = is_string( $zapier['send_when'] ?? null ) ? trim( (string) $zapier['send_when'] ) : '';
		$send_when     = in_array( $send_when_raw, self::ZAP_VALID_SEND_WHEN, true ) ? $send_when_raw : 'all_entries';

		// Payload toggles default to true (admins generally want the
		// full payload when they hook Zapier in). Only an explicit
		// false flips them off.
		$incl_readable = array_key_exists( 'include_fields_readable', $zapier ) ? (bool) $zapier['include_fields_readable'] : true;
		$incl_raw      = array_key_exists( 'include_raw_fields', $zapier ) ? (bool) $zapier['include_raw_fields'] : true;
		$incl_source   = array_key_exists( 'include_source', $zapier ) ? (bool) $zapier['include_source'] : true;

		// Collapse-when-empty: disabled AND no webhooks selected AND
		// default payload toggles carries no information.
		if (
			! $enabled
			&& empty( $webhook_ids )
			&& $incl_readable && $incl_raw && $incl_source
			&& $send_when === 'all_entries'
		) {
			return array();
		}

		return array(
			'enabled'                 => $enabled,
			'webhook_ids'             => $webhook_ids,
			'send_when'               => $send_when,
			'include_fields_readable' => $incl_readable,
			'include_raw_fields'      => $incl_raw,
			'include_source'          => $incl_source,
		);
	}

	/**
	 * Normalise the webhook_ids input into a deduped int array.
	 *
	 * Accepts:
	 *   - array<int|numeric-string> (canonical UI shape)
	 *
	 * Per id:
	 *   - cast to int, require ≥ 1
	 *
	 * The final list is deduped (a hook id can only be selected
	 * once per form) and capped at ZAP_WEBHOOK_IDS_MAX so a tampered
	 * payload can't ship a million-entry array.
	 *
	 * @param mixed $raw
	 * @return array<int,int>
	 */
	public static function sanitize_zapier_webhook_ids( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out  = array();
		$seen = array();
		foreach ( $raw as $v ) {
			if ( ! is_scalar( $v ) ) {
				continue;
			}
			$id = (int) $v;
			if ( $id <= 0 ) {
				continue;
			}
			if ( isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$out[]       = $id;
			if ( count( $out ) >= self::ZAP_WEBHOOK_IDS_MAX ) {
				break;
			}
		}
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
		if ( ! is_array( $google ) ) {
			return array();
		}

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
			&& $mode === ''
			&& $form_id === ''
			&& $title === ''
			&& $url === ''
			&& $imported_at === ''
			&& ! $sync_responses
		) {
			return array();
		}

		return array(
			'enabled'           => $enabled,
			'mode'              => $mode,
			'google_form_id'    => $form_id,
			'google_form_title' => $title,
			'google_form_url'   => $url,
			'imported_at'       => $imported_at,
			'sync_responses'    => $sync_responses,
		);
	}

	/**
	 * Same rules as the Pro-side GoogleFormsClient::is_valid_form_id
	 * — alphanumeric + dash + underscore, no path escapes. Public
	 * so tests can hit it directly.
	 */
	public static function sanitize_google_form_id( string $raw ): string {
		$raw = trim( $raw );
		if ( $raw === '' ) {
			return '';
		}
		if ( preg_match( '/[\x00-\x1F\x7F]/', $raw ) ) {
			return '';
		}
		if ( ! preg_match( self::GF_FORM_ID_PATTERN, $raw ) ) {
			return '';
		}
		return $raw;
	}

	/**
	 * Accepts only https URLs hosted at docs.google.com. Returns ''
	 * for anything else — caller treats empty as "no link".
	 */
	public static function sanitize_google_form_url( string $raw ): string {
		$clean = function_exists( 'esc_url_raw' ) ? esc_url_raw( $raw ) : trim( $raw );
		if ( $clean === '' ) {
			return '';
		}
		// wp_parse_url() is the WP-compatible alternative to PHP's parse_url();
		// it's been available since WP 4.4 so the function_exists guard is
		// unnecessary, and the previous fallback to PHP's parse_url() was
		// what tripped WordPress.WP.AlternativeFunctions.parse_url_parse_url
		// in Plugin Check.
		$scheme = strtolower( (string) wp_parse_url( $clean, PHP_URL_SCHEME ) );
		if ( $scheme !== 'https' ) {
			return '';
		}
		$host = strtolower( (string) wp_parse_url( $clean, PHP_URL_HOST ) );
		if ( $host !== self::GF_FORM_URL_HOST ) {
			return '';
		}
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
		if ( ! is_array( $hubspot ) ) {
			return array();
		}

		// Build the output from scratch — any key NOT explicitly
		// pulled across is dropped (whitelist semantics). This is
		// what kills forbidden keys (access_token / secret) and
		// telemetry keys (last_sent_at etc.) in one stroke.

		$enabled = array_key_exists( 'enabled', $hubspot ) ? (bool) $hubspot['enabled'] : false;

		$form_id_raw = is_string( $hubspot['hubspot_form_id'] ?? null ) ? $hubspot['hubspot_form_id'] : '';
		$form_id     = self::sanitize_hubspot_form_id( $form_id_raw );

		$form_name_raw = is_string( $hubspot['hubspot_form_name'] ?? null ) ? $hubspot['hubspot_form_name'] : '';
		$form_name     = self::sanitize_short_text( $form_name_raw, self::HS_FORM_NAME_MAX );

		$field_map = self::sanitize_field_map( $hubspot['field_map'] ?? array() );

		// Collapse-when-empty: a config that's disabled AND has no
		// form / no mapping carries no information. Returning []
		// makes sanitize_integrations() drop the key so the row
		// stays clean. A disabled config WITH a stored mapping is
		// preserved — the admin may have toggled off temporarily.
		if (
			! $enabled
			&& $form_id === ''
			&& $form_name === ''
			&& empty( $field_map )
		) {
			return array();
		}

		return array(
			'enabled'           => $enabled,
			'hubspot_form_id'   => $form_id,
			'hubspot_form_name' => $form_name,
			'field_map'         => $field_map,
		);
	}

	/**
	 * Sanitize a HubSpot form id. Returns '' for anything that
	 * doesn't match the GUID character set. Public so the test
	 * suite can hit it directly + future callers can reuse.
	 */
	public static function sanitize_hubspot_form_id( string $raw ): string {
		$raw = trim( $raw );
		if ( $raw === '' ) {
			return '';
		}
		// Drop control chars defensively (they wouldn't survive the
		// regex below, but the regex doesn't strip — it accepts or
		// rejects whole. A control char anywhere → reject entirely).
		if ( preg_match( '/[\x00-\x1F\x7F]/', $raw ) ) {
			return '';
		}
		if ( ! preg_match( self::HS_FORM_ID_PATTERN, $raw ) ) {
			return '';
		}
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
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$out = array();
		foreach ( $raw as $hs_name => $nf_id ) {
			if ( ! is_string( $hs_name ) ) {
				continue;
			}
			$hs_name = trim( $hs_name );
			if ( $hs_name === '' ) {
				continue;
			}
			if ( mb_strlen( $hs_name ) > self::HS_FIELD_NAME_MAX ) {
				continue;
			}
			if ( ! preg_match( self::HS_FIELD_NAME_PATTERN, $hs_name ) ) {
				continue;
			}

			// Reject array / object values outright — no nested
			// structures allowed in a field map.
			if ( ! is_string( $nf_id ) ) {
				continue;
			}
			$nf_id = trim( $nf_id );
			if ( $nf_id === '' ) {
				continue;
			}
			if ( mb_strlen( $nf_id ) > self::NF_FIELD_ID_MAX ) {
				continue;
			}
			if ( ! preg_match( self::NF_FIELD_ID_PATTERN, $nf_id ) ) {
				continue;
			}

			$out[ $hs_name ] = $nf_id;
		}
		return $out;
	}

	/**
	 * sanitize_text_field + length cap. Pulled out so the form-name
	 * cap is consistent if we ever apply it elsewhere.
	 */
	private static function sanitize_short_text( string $raw, int $max ): string {
		// sanitize_text_field() has been in WP core since 2.9, so the
		// fallback branch was dead. Removing it also drops the bare
		// strip_tags() call that tripped Plugin Check's strip_tags
		// alternative-functions warning.
		$clean = sanitize_text_field( $raw );
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $clean ) > $max ) {
			return mb_substr( $clean, 0, $max );
		}
		if ( strlen( $clean ) > $max ) {
			return substr( $clean, 0, $max );
		}
		return $clean;
	}
}
