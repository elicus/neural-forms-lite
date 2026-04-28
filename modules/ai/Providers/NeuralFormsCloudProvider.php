<?php
namespace NeuralForms\AI\Providers;

use NeuralForms\AI\Contracts\AIAnalysisProviderInterface;
use NeuralForms\AI\AIRepository;
use NeuralForms\AI\Exceptions\CloudPlanException;

defined( 'ABSPATH' ) || exit;

/**
 * Calls the Neural Forms Cloud AI service instead of a local model provider.
 *
 * The cloud handles prompt building, provider selection, and spam classification.
 * The plugin sends structured entry/form data and receives a normalized result —
 * no prompt logic lives here.
 *
 * Endpoints:
 *   POST {base_url}/v1/ai/analyze
 *   POST {base_url}/v1/ai/reply/regenerate
 *   POST {base_url}/v1/ai/reply/polish
 *
 * Auth headers sent on every request:
 *   Authorization: Bearer {api_key}
 *   X-NeuralForms-Version: {NEURAL_FORMS_VERSION}
 *   X-NeuralForms-Site: {site_url()}
 */
class NeuralFormsCloudProvider implements AIAnalysisProviderInterface {

	private string $api_key;
	private string $base_url;

	/** Timeout in seconds for cloud API calls. */
	const TIMEOUT = 45;

	public function __construct( string $api_key, string $base_url = 'https://api.neuralforms.ai' ) {
		$this->api_key  = $api_key;
		$this->base_url = rtrim( $base_url, '/' );
	}

	public function provider_id(): string {
		return 'neural-forms-cloud';
	}

	// -------------------------------------------------------------------------
	// AIAnalysisProviderInterface
	// -------------------------------------------------------------------------

	/**
	 * POST /v1/ai/analyze
	 *
	 * Request body:
	 * {
	 *   "site_url":  string,
	 *   "entry_id":  int,
	 *   "entry":     { "id": int, "fields": object, "submitted_at": string },
	 *   "form":      { "id": int, "name": string }
	 * }
	 *
	 * Response body (200):
	 * {
	 *   "summary":         string,
	 *   "category":        string,
	 *   "priority":        string,
	 *   "reply_draft":     string,
	 *   "score":           int,
	 *   "is_spam":         bool,
	 *   "spam_confidence": int,
	 *   "spam_reason":     string
	 * }
	 *
	 * @throws \RuntimeException
	 */
	public function analyze( array $entry, array $form ): array {
		$payload = [
			'site_url' => site_url(),
			'entry_id' => (int) ( $entry['id'] ?? 0 ),
			'entry'    => $this->format_entry( $entry ),
			'form'     => $this->format_form( $form ),
		];

		// Reanalyze promotion context — the API uses these to step up the tier
		// ladder (plan-clamped server-side). First analyze sends neither; the
		// API treats that as "first pass" and runs the cheap fast tier.
		$prev_tier     = isset( $entry['ai_tier'] )     ? (string) $entry['ai_tier']     : '';
		$prev_priority = isset( $entry['ai_priority'] ) ? (string) $entry['ai_priority'] : '';
		if ( $prev_tier !== '' ) {
			$payload['previous_tier'] = $prev_tier;
		}
		if ( $prev_priority !== '' ) {
			$payload['previous_priority'] = $prev_priority;
		}

		$body = $this->post( '/v1/ai/analyze', $payload );

		$result = $this->normalize_analysis( $body );

		$result['ai_provider'] = $this->provider_id();
		$result['ai_model']    = 'cloud';
		$result['tier_used']   = $this->normalize_tier( $body['tier_used'] ?? null );

		return $result;
	}

	/**
	 * POST /v1/ai/reply/regenerate
	 *
	 * Request body:
	 * {
	 *   "site_url":  string          (optional),
	 *   "entry":     { "fields": object }  (required),
	 *   "form":      { "id": int, "name": string }  (optional),
	 *   "previous_reply": string     (optional),
	 *   "tone":      string          (optional)
	 * }
	 *
	 * Response body (200):
	 * {
	 *   "reply_draft": string
	 * }
	 *
	 * @throws \RuntimeException
	 */
	public function regenerate_reply( array $entry, array $form ): array {
		$payload = [
			'site_url' => site_url(),
			// Required by the API's per-entry click budget (5 clicks / 2h).
			// Without it the API collapses unrelated entries into one bucket.
			'entry_id' => (int) ( $entry['id'] ?? 0 ),
			'entry'    => $this->format_entry( $entry ),
			'form'     => $this->format_form( $form ),
		];

		$cur_tier = isset( $entry['ai_tier'] ) ? (string) $entry['ai_tier'] : '';
		if ( $cur_tier !== '' ) {
			$payload['current_tier'] = $cur_tier;
		}

		$body = $this->post( '/v1/ai/reply/regenerate', $payload );

		return [
			'reply_draft' => sanitize_textarea_field( (string) ( $body['reply_draft'] ?? '' ) ),
			'tier_used'   => $this->normalize_tier( $body['tier_used'] ?? null ),
		];
	}

	/**
	 * POST /v1/ai/reply/polish
	 *
	 * Request body:
	 * {
	 *   "text": string
	 * }
	 *
	 * Response body (200):
	 * {
	 *   "polished_reply": string
	 * }
	 *
	 * @throws \RuntimeException
	 */
	public function polish_reply( string $draft, array $entry, array $form ): array {
		$payload = [
			'text'     => $draft,
			// Required by the API's per-entry click budget (5 clicks / 2h).
			'entry_id' => (int) ( $entry['id'] ?? 0 ),
			// Per-installation discriminator — folded into the click-budget
			// scope on the cloud so two installs sharing one API key with
			// overlapping entry IDs don't share a budget.
			'site_url' => site_url(),
		];

		$cur_tier = isset( $entry['ai_tier'] ) ? (string) $entry['ai_tier'] : '';
		if ( $cur_tier !== '' ) {
			$payload['current_tier'] = $cur_tier;
		}

		$body = $this->post( '/v1/ai/reply/polish', $payload );

		return [
			'polished_reply' => sanitize_textarea_field( (string) ( $body['polished_reply'] ?? '' ) ),
			'tier_used'      => $this->normalize_tier( $body['tier_used'] ?? null ),
		];
	}

	/**
	 * GET /v1/auth/me
	 *
	 * Fetches plan, capabilities, and quota for the authenticated workspace.
	 * Used by PlanManager to populate the local capability cache after a
	 * successful test-connection. See PlanManager for the expected response
	 * shape — the raw body is returned here and normalized there.
	 *
	 * @throws \RuntimeException  On transport failure or non-2xx response.
	 */
	public function fetch_account(): array {
		return $this->get( '/v1/auth/me' );
	}

	// -------------------------------------------------------------------------
	// HTTP transport
	// -------------------------------------------------------------------------

	/**
	 * POST to a cloud endpoint and return the decoded response body.
	 *
	 * @param string $path     Path relative to base_url, e.g. '/v1/ai/analyze'.
	 * @param array  $payload  Data to JSON-encode as the request body.
	 * @return array           Decoded response body.
	 * @throws \RuntimeException On WP_Error, non-2xx response, or non-JSON body.
	 */
	private function post( string $path, array $payload ): array {
		$url = $this->base_url . $path;

		$response = wp_remote_post( $url, [
			'timeout' => self::TIMEOUT,
			'headers' => $this->headers(),
			'body'    => wp_json_encode( $payload ),
		] );

		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException(
				'Neural Forms Cloud request failed: ' . $response->get_error_message()
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 ) {
			// Plan-related errors carry structured data so the plugin can react
			// without parsing messages. Surface them as a typed exception.
			$body_code = is_array( $data ) ? (string) ( $data['code'] ?? '' ) : '';
			if ( in_array( $body_code, [ 'feature_locked', 'quota_exceeded' ], true ) ) {
				throw new CloudPlanException(
					(string) ( $data['message'] ?? $body_code ),
					$body_code,
					$code,
					(string) ( $data['feature']     ?? '' ),
					is_array( $data['quota'] ?? null ) ? $data['quota'] : [],
					(string) ( $data['upgrade_url'] ?? '' )
				);
			}

			$msg = ( is_array( $data ) && isset( $data['message'] ) )
				? $data['message']
				: "HTTP {$code}";

			$code_slug = $body_code !== '' ? ' [' . $body_code . ']' : '';

			// Include truncated response body for debugging 4xx errors.
			$body_hint = ( $code >= 400 && $code < 500 )
				? ' | body: ' . substr( $raw, 0, 500 )
				: '';

			throw new \RuntimeException(
				"Neural Forms Cloud API error{$code_slug}: {$msg}{$body_hint}"
			);
		}

		if ( ! is_array( $data ) ) {
			throw new \RuntimeException(
				'Neural Forms Cloud returned non-JSON response: ' . substr( $raw, 0, 200 )
			);
		}

		return $data;
	}

	/**
	 * GET against a cloud endpoint. Mirrors post() for response handling so
	 * feature_locked / quota_exceeded bodies are translated to a typed
	 * exception no matter which verb is used.
	 *
	 * @throws \RuntimeException | CloudPlanException
	 */
	private function get( string $path ): array {
		$response = wp_remote_get( $this->base_url . $path, [
			'timeout' => self::TIMEOUT,
			'headers' => $this->headers(),
		] );

		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException(
				'Neural Forms Cloud request failed: ' . $response->get_error_message()
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 ) {
			$body_code = is_array( $data ) ? (string) ( $data['code'] ?? '' ) : '';
			if ( in_array( $body_code, [ 'feature_locked', 'quota_exceeded' ], true ) ) {
				throw new CloudPlanException(
					(string) ( $data['message'] ?? $body_code ),
					$body_code,
					$code,
					(string) ( $data['feature']     ?? '' ),
					is_array( $data['quota'] ?? null ) ? $data['quota'] : [],
					(string) ( $data['upgrade_url'] ?? '' )
				);
			}

			$msg       = is_array( $data ) && isset( $data['message'] ) ? $data['message'] : "HTTP {$code}";
			$code_slug = $body_code !== '' ? ' [' . $body_code . ']' : '';

			throw new \RuntimeException( "Neural Forms Cloud API error{$code_slug}: {$msg}" );
		}

		if ( ! is_array( $data ) ) {
			throw new \RuntimeException(
				'Neural Forms Cloud returned non-JSON response: ' . substr( $raw, 0, 200 )
			);
		}

		return $data;
	}

	/**
	 * Build the auth + version headers sent on every request.
	 *
	 * Passes the final array through the neural_forms_cloud_request_headers
	 * filter so add-on plugins (e.g. Neural Forms Pro) can inject their own
	 * headers — typically a license key — without this class needing to know
	 * they exist. The core auth headers are always set first so a badly
	 * behaved filter can never silently remove them.
	 */
	private function headers(): array {
		$headers = [
			'Authorization'         => 'Bearer ' . $this->api_key,
			'Content-Type'          => 'application/json',
			'X-NeuralForms-Version' => defined( 'NEURAL_FORMS_VERSION' ) ? NEURAL_FORMS_VERSION : '',
			'X-NeuralForms-Site'    => site_url(),
		];

		$filtered = apply_filters( 'neural_forms_cloud_request_headers', $headers );

		return is_array( $filtered ) ? array_merge( $filtered, $headers ) : $headers;
	}

	// -------------------------------------------------------------------------
	// Payload helpers
	// -------------------------------------------------------------------------

	/**
	 * Flatten an entry row to the shape the cloud API expects.
	 */
	private function format_entry( array $entry ): array {
		return [
			'id'           => (int) ( $entry['id'] ?? 0 ),
			'fields'       => $entry['data'] ?? [],
			'submitted_at' => (string) ( $entry['created_at'] ?? '' ),
		];
	}

	/**
	 * Flatten a form row to the shape the cloud API expects.
	 */
	private function format_form( array $form ): array {
		return [
			'id'   => (int) ( $form['id'] ?? 0 ),
			'name' => (string) ( $form['title'] ?? '' ),
		];
	}

	// -------------------------------------------------------------------------
	// Response normalization
	// -------------------------------------------------------------------------

	/** Allowed tier slugs mirrored from the API's model-registry. */
	const VALID_TIERS = [ 'fast', 'balanced', 'smart', 'premium' ];

	/**
	 * Clamp the API's tier_used field to a known slug. Null/invalid → 'fast'.
	 * Ensures we never persist garbage in the entries.ai_tier column even if
	 * the cloud returns an unexpected value (e.g. future tier we haven't added).
	 */
	private function normalize_tier( $value ): string {
		$tier = is_string( $value ) ? strtolower( trim( $value ) ) : '';
		return in_array( $tier, self::VALID_TIERS, true ) ? $tier : 'fast';
	}

	/**
	 * Normalize and sanitize the analyze endpoint response.
	 *
	 * Applies the same validation rules as AIResultParser::parse() so the
	 * output is identical regardless of which provider produced it.
	 */
	private function normalize_analysis( array $data ): array {
		return [
			'summary'         => sanitize_textarea_field( (string) ( $data['summary']     ?? '' ) ),
			'category'        => in_array( $data['category'] ?? '', AIRepository::VALID_CATEGORIES, true )
									 ? $data['category']
									 : 'general',
			'priority'        => in_array( $data['priority'] ?? '', AIRepository::VALID_PRIORITIES, true )
									 ? $data['priority']
									 : 'medium',
			'reply_draft'     => sanitize_textarea_field( (string) ( $data['reply_draft'] ?? '' ) ),
			'score'           => min( 100, max( 0, (int) ( $data['score'] ?? 50 ) ) ),
			'is_spam'         => (bool) ( $data['is_spam']         ?? false ),
			'spam_confidence' => min( 100, max( 0, (int) ( $data['spam_confidence'] ?? 0 ) ) ),
			'spam_reason'     => sanitize_text_field( (string) ( $data['spam_reason'] ?? '' ) ),
		];
	}
}
