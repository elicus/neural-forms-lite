<?php
namespace NeuralForms\Rest\Endpoints;

use NeuralForms\Core\Capabilities;
use NeuralForms\Core\Config;
use NeuralForms\Core\Logger;
use NeuralForms\Plan\PlanManager;
use WP_REST_Request;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * REST routes for plugin settings (admin-only).
 *
 * GET  /neural-forms/v1/settings  - Returns safe settings (API key never exposed raw).
 * PATCH /neural-forms/v1/settings - Partial update; unmentioned keys are preserved.
 *
 * Both routes require manage_options capability.
 *
 * API keys (cloud_api_key, captcha_secret_key) are write-only from the UI perspective:
 *   * GET returns *_set: bool (true if a key is stored), never the raw value.
 *   * PATCH accepts a new key value to overwrite; omitting a key leaves it unchanged.
 * This prevents keys from appearing in browser dev tools / network logs.
 */
class SettingsEndpoint {

	/**
	 * Background hook + TTL for the plan-cache refresh. See
	 * maybe_schedule_plan_refresh() for the why — the refresh used to
	 * block GET /settings on a synchronous /v1/auth/me round-trip
	 * (up to 5s on a slow cloud); it now runs out-of-band via
	 * wp_schedule_single_event so the Settings page returns
	 * immediately from cached data.
	 */
	const PLAN_REFRESH_HOOK = 'neural_forms_refresh_plan_cache';
	const PLAN_CACHE_TTL    = 300;

	/**
	 * Register cron handlers that need to be available on every
	 * request (REST + wp-cron + admin). Called once from
	 * NeuralForms::boot_rest(). Idempotent — re-registration is a
	 * no-op because add_action() de-duplicates identical callbacks.
	 */
	public static function register_hooks(): void {
		add_action( self::PLAN_REFRESH_HOOK, array( self::class, 'refresh_plan_cache_now' ) );
	}

	public function register_routes(): void {
		$ns = \NeuralForms\Rest\RestAPI::NAMESPACE;

		register_rest_route(
			$ns,
			'/settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => $this->patch_args(),
				),
			)
		);

		register_rest_route(
			$ns,
			'/settings/test-connection',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'test_connection' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'api_key' => array(
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/settings/revoke-connection',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'revoke_connection' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			$ns,
			'/settings/test-email',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'send_test_email' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'email' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_email',
						'validate_callback' => static function ( $value ): bool {
							return is_email( sanitize_email( (string) $value ) );
						},
					),
				),
			)
		);
	}

	// Handlers

	// $request is required by the route callback signature even though
	// GET /settings reads no body / query — REST handlers must accept
	// the request object so WP can dispatch the callback uniformly.
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
	public function get_settings( WP_REST_Request $request ) {
		$all = Config::all();

		// Schedule a background refresh of the plan + balance snapshot from
		// /v1/auth/me when the cached copy is older than PLAN_CACHE_TTL.
		// This used to fire the cloud round-trip inline, which could hold
		// the Settings page on its loading spinner for up to 5s every time
		// the cache aged out — now the refresh runs via WP-Cron on the next
		// request boundary so GET /settings returns immediately from cache.
		// Each AI call already mirrors usage.credits_remaining into the
		// local cache via PlanManager::apply_usage(), so the periodic
		// round-trip is only needed to catch:
		// - usage block missing from a pre-fix cloud response
		// - the user topped up credits in another tab
		// - the plan changed (cancelled / renewed) outside this admin
		// Worst case: the user sees a one-navigation-stale balance after a
		// long gap, instead of a blocked page load.
		$this->maybe_schedule_plan_refresh();

		// Derived signup-complete: sticky once the user has saved a key OR has
		// previously revoked one. The revoke snapshot acts as a backfill marker
		// for installs that connected before `cloud_signup_complete` existed -
		// without it, those installs would re-see the first-run overlay on
		// upgrade. Setting cloud_api_key implies signup happened on this site.
		$signup_complete = ! empty( $all['cloud_signup_complete'] )
			|| ! empty( $all['cloud_api_key'] )
			|| (bool) get_option( self::REVOKE_SNAPSHOT_OPTION, false );

		return rest_ensure_response(
			array(
				'ai_auto_process'               => (bool) ( $all['ai_auto_process'] ?? true ),
				'privacy_mode'                  => (bool) ( $all['privacy_mode'] ?? false ),
				'sender_name'                   => (string) ( $all['sender_name'] ?? '' ),
				'sender_email'                  => (string) ( $all['sender_email'] ?? '' ),
				'reply_subject_pattern'         => (string) ( $all['reply_subject_pattern'] ?? 'Re: Your submission' ),

				// Notifications
				'enable_admin_notification'     => (bool) ( $all['enable_admin_notification'] ?? true ),
				'admin_notification_email'      => (string) ( $all['admin_notification_email'] ?? '' ),
				'enable_autoresponder'          => (bool) ( $all['enable_autoresponder'] ?? false ),
				'autoresponder_subject'         => (string) ( $all['autoresponder_subject'] ?? 'Thank you for your submission' ),
				'autoresponder_body'            => (string) ( $all['autoresponder_body'] ?? '' ),

				// AI spam detection
				'ai_spam_enabled'               => (bool) ( $all['ai_spam_enabled'] ?? false ),
				'ai_spam_mode'                  => (string) ( $all['ai_spam_mode'] ?? 'suggest' ),
				'ai_spam_threshold'             => (int) ( $all['ai_spam_threshold'] ?? 85 ),
				'email_skip_spam_notifications' => (bool) ( $all['email_skip_spam_notifications'] ?? false ),

				// CAPTCHA - secret key is write-only; only a boolean "is it set?" is returned.
				'captcha_provider'              => (string) ( $all['captcha_provider'] ?? 'none' ),
				'captcha_site_key'              => (string) ( $all['captcha_site_key'] ?? '' ),
				'captcha_secret_key_set'        => ! empty( $all['captcha_secret_key'] ),

				// Neural Forms Cloud AI - cloud key is write-only.
				'cloud_ai_enabled'              => (bool) ( $all['cloud_ai_enabled'] ?? false ),
				'cloud_api_key_set'             => ! empty( $all['cloud_api_key'] ),
				'cloud_signup_complete'         => $signup_complete,

				// Plan + capabilities. Sourced entirely from the Cloud API; the plugin
				// never hardcodes tier logic. See PlanManager for the shape.
				'cloud_plan'                    => PlanManager::public_payload(),
			)
		);
	}

	/**
	 * Schedules a one-shot background refresh of the plan + balance cache
	 * via wp_schedule_single_event when:
	 *   - cloud is configured (api key + cloud_ai_enabled),
	 *   - the cached plan is older than PLAN_CACHE_TTL,
	 *   - no refresh is already queued.
	 *
	 * Always returns immediately — the actual /v1/auth/me round-trip runs
	 * out-of-band on the next request boundary (handled by
	 * refresh_plan_cache_now() via the PLAN_REFRESH_HOOK action). This
	 * removes the synchronous cloud call that previously blocked
	 * GET /settings whenever the cache aged out.
	 */
	private function maybe_schedule_plan_refresh(): void {
		if ( ! Config::get( 'cloud_ai_enabled' ) ) {
			return;
		}
		if ( Config::get( 'cloud_api_key' ) === '' ) {
			return;
		}

		$plan       = PlanManager::get_plan();
		$fetched_at = (int) ( $plan['fetched_at'] ?? 0 );
		if ( $fetched_at > 0 && ( time() - $fetched_at ) < self::PLAN_CACHE_TTL ) {
			return;
		}

		// Single-event de-dup: if a refresh is already queued (or running),
		// don't pile up more. WP-Cron will fire the existing one on the
		// next request boundary.
		if ( wp_next_scheduled( self::PLAN_REFRESH_HOOK ) ) {
			return;
		}

		wp_schedule_single_event( time() + 1, self::PLAN_REFRESH_HOOK );
	}

	/**
	 * Background refresh handler — runs out-of-band via WP-Cron after
	 * maybe_schedule_plan_refresh() enqueues PLAN_REFRESH_HOOK. Performs
	 * the actual /v1/auth/me round-trip and mirrors the response into
	 * PlanManager's local cache.
	 *
	 * Soft-fail by design: any exception (transport / auth / parse) is
	 * logged and swallowed so a transient cloud outage never breaks the
	 * cron pass.
	 */
	public static function refresh_plan_cache_now(): void {
		if ( ! Config::get( 'cloud_ai_enabled' ) ) {
			return;
		}
		if ( Config::get( 'cloud_api_key' ) === '' ) {
			return;
		}

		try {
			$provider = \NeuralForms\AI\AiManager::resolve();
			$body     = $provider->fetch_account();
			if ( is_array( $body ) ) {
				PlanManager::store_from_response( $body );
			}
		} catch ( \Throwable $e ) {
			Logger::file(
				'plan_refresh',
				'warn',
				'Background /v1/auth/me refresh failed - ' . $e->getMessage()
			);
		}
	}

	public function update_settings( WP_REST_Request $request ) {
		$updates = array();

		if ( null !== $request->get_param( 'ai_enabled' ) ) {
			$updates['ai_enabled'] = (bool) $request->get_param( 'ai_enabled' );
		}
		if ( null !== $request->get_param( 'ai_auto_process' ) ) {
			$updates['ai_auto_process'] = (bool) $request->get_param( 'ai_auto_process' );
		}
		if ( null !== $request->get_param( 'privacy_mode' ) ) {
			$updates['privacy_mode'] = (bool) $request->get_param( 'privacy_mode' );
		}
		if ( null !== $request->get_param( 'sender_name' ) ) {
			$updates['sender_name'] = sanitize_text_field( (string) $request->get_param( 'sender_name' ) );
		}
		if ( null !== $request->get_param( 'sender_email' ) ) {
			$updates['sender_email'] = sanitize_email( (string) $request->get_param( 'sender_email' ) );
		}
		if ( null !== $request->get_param( 'reply_subject_pattern' ) ) {
			$updates['reply_subject_pattern'] = sanitize_text_field( (string) $request->get_param( 'reply_subject_pattern' ) );
		}
		if ( null !== $request->get_param( 'enable_admin_notification' ) ) {
			$updates['enable_admin_notification'] = (bool) $request->get_param( 'enable_admin_notification' );
		}
		if ( null !== $request->get_param( 'admin_notification_email' ) ) {
			// Comma-separated list support (1.1.0+). Parse to a list of
			// valid addresses, drop invalid entries, then re-join so
			// the option stays a flat string the React UI can edit.
			$valid_list                          = \NeuralForms\Notifications\NotificationService::parse_recipient_list(
				(string) $request->get_param( 'admin_notification_email' )
			);
			$updates['admin_notification_email'] = implode( ', ', $valid_list );
		}
		if ( null !== $request->get_param( 'enable_autoresponder' ) ) {
			$updates['enable_autoresponder'] = (bool) $request->get_param( 'enable_autoresponder' );
		}
		if ( null !== $request->get_param( 'autoresponder_subject' ) ) {
			$updates['autoresponder_subject'] = sanitize_text_field( (string) $request->get_param( 'autoresponder_subject' ) );
		}
		if ( null !== $request->get_param( 'autoresponder_body' ) ) {
			$updates['autoresponder_body'] = sanitize_textarea_field( (string) $request->get_param( 'autoresponder_body' ) );
		}
		if ( null !== $request->get_param( 'ai_spam_enabled' ) ) {
			$updates['ai_spam_enabled'] = (bool) $request->get_param( 'ai_spam_enabled' );
		}
		if ( null !== $request->get_param( 'ai_spam_mode' ) ) {
			$updates['ai_spam_mode'] = sanitize_text_field( (string) $request->get_param( 'ai_spam_mode' ) );
		}
		if ( null !== $request->get_param( 'ai_spam_threshold' ) ) {
			$updates['ai_spam_threshold'] = min( 100, max( 60, (int) $request->get_param( 'ai_spam_threshold' ) ) );
		}
		if ( null !== $request->get_param( 'email_skip_spam_notifications' ) ) {
			$updates['email_skip_spam_notifications'] = (bool) $request->get_param( 'email_skip_spam_notifications' );
		}

		// Neural Forms Cloud AI settings '
		if ( null !== $request->get_param( 'cloud_ai_enabled' ) ) {
			$updates['cloud_ai_enabled'] = (bool) $request->get_param( 'cloud_ai_enabled' );
		}
		// Cloud key is write-only: only persist when the client sends a non-empty value.
		$cloud_key = $request->get_param( 'cloud_api_key' );
		if ( null !== $cloud_key && trim( (string) $cloud_key ) !== '' ) {
			$updates['cloud_api_key'] = sanitize_text_field( (string) $cloud_key );
		}
		// CAPTCHA settings '
		if ( null !== $request->get_param( 'captcha_provider' ) ) {
			$updates['captcha_provider'] = sanitize_text_field( (string) $request->get_param( 'captcha_provider' ) );
		}
		if ( null !== $request->get_param( 'captcha_site_key' ) ) {
			$updates['captcha_site_key'] = sanitize_text_field( (string) $request->get_param( 'captcha_site_key' ) );
		}
		// Secret key is write-only: only persist when the client sends a non-empty
		// value, so an empty payload field means "keep the existing key".
		$captcha_secret = $request->get_param( 'captcha_secret_key' );
		if ( null !== $captcha_secret && trim( (string) $captcha_secret ) !== '' ) {
			$updates['captcha_secret_key'] = sanitize_text_field( (string) $captcha_secret );
		}

		if ( empty( $updates ) ) {
			return new WP_Error(
				'no_settings',
				__( 'No settings provided.', 'neural-forms-lite' ),
				array( 'status' => 400 )
			);
		}

		// ' Config validation: prevent enabling features without prerequisites '

		// CAPTCHA: reject enabling a provider when keys are missing.
		$captcha_provider = $updates['captcha_provider'] ?? (string) Config::get( 'captcha_provider' );
		if ( $captcha_provider !== 'none' ) {
			$site_key   = $updates['captcha_site_key'] ?? (string) Config::get( 'captcha_site_key' );
			$secret_set = isset( $updates['captcha_secret_key'] )
				? ( trim( (string) $updates['captcha_secret_key'] ) !== '' )
				: ! empty( Config::get( 'captcha_secret_key' ) );

			if ( trim( (string) $site_key ) === '' || ! $secret_set ) {
				return new WP_Error(
					'captcha_keys_required',
					__( 'CAPTCHA cannot be enabled without both a Site Key and a Secret Key.', 'neural-forms-lite' ),
					array( 'status' => 422 )
				);
			}
		}

		// Cloud AI: reject enabling without an API key, and validate the key.
		$cloud_ai_enabled = $updates['cloud_ai_enabled'] ?? (bool) Config::get( 'cloud_ai_enabled' );
		if ( $cloud_ai_enabled ) {
			$cloud_key_value = isset( $updates['cloud_api_key'] )
				? trim( (string) $updates['cloud_api_key'] )
				: trim( (string) Config::get( 'cloud_api_key', '' ) );

			if ( $cloud_key_value === '' ) {
				return new WP_Error(
					'cloud_api_key_required',
					__( 'Neural AI Cloud cannot be enabled without an API key. Add a key first.', 'neural-forms-lite' ),
					array( 'status' => 422 )
				);
			}

			// When the key is new or Cloud AI is being toggled on, validate it.
			$is_new_key     = isset( $updates['cloud_api_key'] );
			$is_toggling_on = isset( $updates['cloud_ai_enabled'] ) && $updates['cloud_ai_enabled'];
			if ( $is_new_key || $is_toggling_on ) {
				$test_request = new WP_REST_Request( 'POST' );
				$test_request->set_param( 'api_key', $cloud_key_value );
				$test_result = $this->test_connection( $test_request );

				if ( is_wp_error( $test_result ) ) {
					return new WP_Error(
						'cloud_api_key_invalid',
						sprintf(
							/* translators: %s: error detail from the Cloud API */
							__( 'Neural AI Cloud key validation failed: %s', 'neural-forms-lite' ),
							$test_result->get_error_message()
						),
						array( 'status' => 422 )
					);
				}

				// Reconnect: if a revoke snapshot exists, restore any AI toggles
				// the current payload didn't set explicitly. Payload wins, so an
				// intentional off-toggle in this save isn't clobbered.
				if ( $is_new_key ) {
					$updates['cloud_signup_complete'] = true;

					$snapshot = get_option( self::REVOKE_SNAPSHOT_OPTION );
					if ( is_array( $snapshot ) && ! empty( $snapshot ) ) {
						foreach ( self::revoke_snapshot_keys() as $key ) {
							if ( ! array_key_exists( $key, $updates ) && array_key_exists( $key, $snapshot ) ) {
								$updates[ $key ] = (bool) $snapshot[ $key ];
							}
						}
						delete_option( self::REVOKE_SNAPSHOT_OPTION );
					}
				}
			}
		}

		// Mirror cloud_ai_enabled → ai_enabled. The two flags exist for
		// historical reasons (cloud-connection state vs AI master switch)
		// but they always move together in current product behaviour.
		// The React Settings save handler sends a diff PATCH — it only
		// includes the toggle the user touched — so toggling Cloud AI on
		// would otherwise leave ai_enabled at its default false and the
		// analyze/reply gate (check_ai_configured in EntriesEndpoint)
		// would reject every call as "not configured". Keep the pair
		// coherent server-side so a single save fixes both flags.
		if ( isset( $updates['cloud_ai_enabled'] ) && ! isset( $updates['ai_enabled'] ) ) {
			$updates['ai_enabled'] = (bool) $updates['cloud_ai_enabled'];
		}
		// Corrective sync for installs that hit the bug before this fix
		// shipped: if cloud_ai_enabled is already true in Config but
		// ai_enabled is still the default false, any save (even one that
		// doesn't touch cloud_ai_enabled) will repair the pair.
		if (
			! isset( $updates['ai_enabled'] )
			&& (bool) Config::get( 'cloud_ai_enabled' )
			&& ! (bool) Config::get( 'ai_enabled' )
		) {
			$updates['ai_enabled'] = true;
		}

		// Guard: auto-process requires AI to be enabled.
		$ai_enabled = $updates['ai_enabled'] ?? (bool) Config::get( 'ai_enabled' );
		if ( ! $ai_enabled ) {
			$updates['ai_auto_process'] = false;
		}

		// Guard: spam detection requires the plugin-level capability.
		// Reject with 422 if the caller tries to turn it on without Pro -
		// the UI should prevent this via the SpamPanel upsell, so hitting
		// this branch usually means a direct REST call.
		if (
			isset( $updates['ai_spam_enabled'] )
			&& $updates['ai_spam_enabled']
			&& ! Capabilities::can( Capabilities::AI_SPAM_DETECT )
		) {
			return new WP_Error(
				'pro_feature_locked',
				__( 'Spam detection requires Neural Forms Pro.', 'neural-forms-lite' ),
				array(
					'status'       => 422,
					'feature'      => Capabilities::AI_SPAM_DETECT,
					'requires_pro' => true,
				)
			);
		}

		// Defensive coercion: if Pro was uninstalled while ai_spam_enabled
		// was on, flip it off on the next save so downstream code never
		// sees a "dead" toggle referencing a locked capability.
		if (
			! isset( $updates['ai_spam_enabled'] )
			&& (bool) Config::get( 'ai_spam_enabled' )
			&& ! Capabilities::can( Capabilities::AI_SPAM_DETECT )
		) {
			$updates['ai_spam_enabled'] = false;
		}

		// Guard: skip-spam-notifications requires spam detection to be on.
		$spam_enabled = $updates['ai_spam_enabled'] ?? (bool) Config::get( 'ai_spam_enabled' );
		if ( ! $spam_enabled ) {
			$updates['email_skip_spam_notifications'] = false;
		}

		Config::update( $updates );
		Config::flush_cache();

		// Clear cached plan when Cloud AI is turned off so stale capabilities
		// don't bleed into a disconnected UI. The next test-connection repopulates.
		if ( isset( $updates['cloud_ai_enabled'] ) && ! $updates['cloud_ai_enabled'] ) {
			PlanManager::clear();
		}

		return $this->get_settings( $request );
	}

	/**
	 * POST /settings/test-connection
	 *
	 * Proxies a GET request to the Neural Forms Cloud /v1/auth/me endpoint
	 * using the provided API key. This avoids CORS issues that occur when the
	 * browser calls the Cloud API directly from a different origin.
	 *
	 * @param WP_REST_Request $request
	 * @return \WP_REST_Response|WP_Error
	 */
	public function test_connection( WP_REST_Request $request ) {
		$api_key = trim( (string) ( $request->get_param( 'api_key' ) ?? '' ) );

		// Fall back to the stored key when no key is provided.
		// This allows auto-verification on page load without re-entering the key.
		if ( $api_key === '' ) {
			$api_key = (string) Config::get( 'cloud_api_key', '' );
		}

		if ( $api_key === '' ) {
			return new WP_Error(
				'missing_key',
				__( 'No API key provided and no saved key found.', 'neural-forms-lite' ),
				array( 'status' => 400 )
			);
		}

		// Single source of truth for the cloud URL - same resolution path
		// AiManager uses on every request.
		$base_url = \NeuralForms\AI\AiManager::resolve_base_url();

		$response = wp_remote_get(
			$base_url . '/v1/auth/me',
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'connection_failed',
				$response->get_error_message(),
				array( 'status' => 502 )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 200 && $code < 300 ) {
			// Cache plan + capabilities for later gating calls. PlanManager
			// normalizes missing/invalid fields so a partial response never
			// breaks the cache.
			if ( is_array( $body ) ) {
				PlanManager::store_from_response( $body );
			}

			return rest_ensure_response(
				array(
					'success'        => true,
					'workspace_name' => $body['site']['name'] ?? '',
					'plan'           => $body['plan']['name'] ?? '',
					'cloud_plan'     => PlanManager::public_payload(),
				)
			);
		}

		return new WP_Error(
			'api_error',
			$body['message'] ?? "API returned HTTP {$code}",
			array( 'status' => $code >= 400 && $code < 600 ? $code : 502 )
		);
	}

	/**
	 * Option key holding the pre-revoke snapshot of AI toggles.
	 * Restored on the next successful Cloud API key save so admins don't
	 * have to reconfigure auto-process / spam / notification flags.
	 */
	const REVOKE_SNAPSHOT_OPTION = 'neural_forms_ai_revoke_snapshot';

	/** Keys captured on revoke and restored on reconnect. */
	private static function revoke_snapshot_keys(): array {
		return array(
			'cloud_ai_enabled',
			'ai_enabled',
			'ai_auto_process',
			'ai_spam_enabled',
			'email_skip_spam_notifications',
		);
	}

	/**
	 * POST /settings/revoke-connection
	 *
	 * Clears the stored Cloud API key and disables every AI feature that
	 * depends on it. Also flushes the cached plan so stale capabilities
	 * can't leak into the disconnected UI.
	 *
	 * Before wiping, snapshot the AI toggles to a separate option so they
	 * can be auto-restored the next time the admin pastes a valid key -
	 * otherwise reconnecting forces them to reconfigure every flag.
	 *
	 * @param WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function revoke_connection( WP_REST_Request $request ) {
		$snapshot = array();
		foreach ( self::revoke_snapshot_keys() as $key ) {
			$snapshot[ $key ] = (bool) Config::get( $key );
		}
		update_option( self::REVOKE_SNAPSHOT_OPTION, $snapshot, false );

		Config::update(
			array(
				'cloud_api_key'                 => '',
				'cloud_ai_enabled'              => false,
				'ai_enabled'                    => false,
				'ai_auto_process'               => false,
				'ai_spam_enabled'               => false,
				'email_skip_spam_notifications' => false,
				'cloud_signup_complete'         => true,
			)
		);
		Config::flush_cache();
		PlanManager::clear();

		Logger::log( 'cloud_connection', 'info', 'Cloud AI connection revoked by admin.' );

		return $this->get_settings( $request );
	}

	/**
	 * POST /settings/test-email
	 *
	 * Sends a test email to the given address using wp_mail(). Applies the
	 * configured sender_name and sender_email as the From header when set,
	 * falling back to WordPress defaults when not.
	 *
	 * Logs the result to neural_forms_logs regardless of success or failure so
	 * admins can diagnose delivery issues from the server log.
	 *
	 * @param WP_REST_Request $request
	 * @return \WP_REST_Response|WP_Error
	 */
	public function send_test_email( WP_REST_Request $request ) {
		$to = sanitize_email( (string) $request->get_param( 'email' ) );

		$subject = __( 'Neural Forms Test Email', 'neural-forms-lite' );
		$message = __( 'This is a test email from Neural Forms. If you received this, email is working.', 'neural-forms-lite' );
		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );

		// Apply configured sender identity when available.
		$sender_name  = (string) Config::get( 'sender_name', '' );
		$sender_email = (string) Config::get( 'sender_email', '' );

		if ( $sender_email !== '' && is_email( $sender_email ) ) {
			$from      = $sender_name !== '' ? $sender_name . ' <' . $sender_email . '>' : $sender_email;
			$headers[] = 'From: ' . $from;
		} elseif ( $sender_name !== '' ) {
			// Name set but no email - use the WordPress admin email with the custom name.
			$headers[] = 'From: ' . $sender_name . ' <' . get_option( 'admin_email' ) . '>';
		}

		$sent = wp_mail( $to, $subject, $message, $headers );

		if ( $sent ) {
			Logger::log( 'test_email', 'info', 'Test email sent to ' . $to );

			return rest_ensure_response(
				array(
					'success' => true,
					'message' => sprintf(
					/* translators: %s: email address */
						__( 'Test email sent to %s.', 'neural-forms-lite' ),
						$to
					),
				)
			);
		}

		Logger::log( 'test_email', 'error', 'Test email failed to send to ' . $to );

		return new WP_Error(
			'test_email_failed',
			sprintf(
				/* translators: %s: email address */
				__( 'wp_mail() returned false for %s. Check your server\'s mail configuration.', 'neural-forms-lite' ),
				$to
			),
			array( 'status' => 500 )
		);
	}

	// Permission

	public function check_permission() {
		if ( current_user_can( NEURAL_FORMS_CAP_MANAGE_FORMS ) ) {
			return true;
		}

		return new WP_Error(
			'permission_denied',
			__( 'You do not have permission to manage Neural Forms settings.', 'neural-forms-lite' ),
			array( 'status' => 403 )
		);
	}

	// Arg definitions

	private function patch_args(): array {
		return array(
			'ai_enabled'                    => array(
				'required' => false,
				'type'     => 'boolean',
			),
			'ai_auto_process'               => array(
				'required' => false,
				'type'     => 'boolean',
			),
			'privacy_mode'                  => array(
				'required' => false,
				'type'     => 'boolean',
			),
			'sender_name'                   => array(
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'sender_email'                  => array(
				'required'          => false,
				'sanitize_callback' => 'sanitize_email',
				'validate_callback' => static function ( $value ): bool {
					return $value === '' || is_email( sanitize_email( (string) $value ) );
				},
			),
			'reply_subject_pattern'         => array(
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'enable_admin_notification'     => array(
				'required' => false,
				'type'     => 'boolean',
			),
			'admin_notification_email'      => array(
				'required'          => false,
				// Sanitisation happens in the handler via
				// NotificationService::parse_recipient_list — we
				// only strip surrounding whitespace here so the
				// raw comma-separated string survives the args
				// pipeline. sanitize_email() would strip the
				// commas before the handler ever saw them.
				'sanitize_callback' => static function ( $value ): string {
					return is_string( $value ) ? trim( $value ) : '';
				},
				// Accept blank OR any string that contains at least
				// one valid address. The full per-entry validation
				// lives in the handler; this gate just rejects
				// pure-garbage input early.
				'validate_callback' => static function ( $value ): bool {
					if ( ! is_string( $value ) || trim( $value ) === '' ) {
						return true;
					}
					$valid = \NeuralForms\Notifications\NotificationService::parse_recipient_list( $value );
					return ! empty( $valid );
				},
			),
			'enable_autoresponder'          => array(
				'required' => false,
				'type'     => 'boolean',
			),
			'autoresponder_subject'         => array(
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'autoresponder_body'            => array(
				'required'          => false,
				'sanitize_callback' => 'sanitize_textarea_field',
			),
			'ai_spam_enabled'               => array(
				'required' => false,
				'type'     => 'boolean',
			),
			'email_skip_spam_notifications' => array(
				'required' => false,
				'type'     => 'boolean',
			),
			'ai_spam_mode'                  => array(
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => static function ( $value ): bool {
					return in_array( $value, array( 'suggest', 'auto' ), true );
				},
			),
			'ai_spam_threshold'             => array(
				'required'          => false,
				'sanitize_callback' => 'absint',
				'validate_callback' => static function ( $value ): bool {
					$v = (int) $value;
					return $v >= 60 && $v <= 100;
				},
			),

			// Neural Forms Cloud AI
			'cloud_ai_enabled'              => array(
				'required' => false,
				'type'     => 'boolean',
			),
			'cloud_api_key'                 => array(
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),

			// CAPTCHA
			'captcha_provider'              => array(
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => static function ( $value ): bool {
					return in_array( $value, array( 'none', 'turnstile', 'recaptcha_v2' ), true );
				},
			),
			'captcha_site_key'              => array(
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'captcha_secret_key'            => array(
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}
}
