<?php
namespace NeuralForms\AI\Providers;

use NeuralForms\AI\Contracts\AIAnalysisProviderInterface;
use NeuralForms\AI\AIRepository;
use NeuralForms\AI\Exceptions\CloudPlanException;
use NeuralForms\AI\Exceptions\CloudSafetyException;

defined( 'ABSPATH' ) || exit;

/**
 * Calls the Neural Forms Cloud AI service instead of a local model provider.
 *
 * The cloud handles prompt building, provider selection, and spam classification.
 * The plugin sends structured entry/form data and receives a normalized result -
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

	// AIAnalysisProviderInterface

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
			'entry'    => $this->format_entry( $entry, $form ),
			'form'     => $this->format_form( $form ),
		];

		// Reanalyze promotion context - the API uses these to step up the tier
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
		// Cloud's user-facing usage block — used by PlanManager::apply_usage
		// to keep the local credit balance in sync with the authoritative
		// cloud counter without an extra /v1/auth/me round-trip.
		$result['usage']       = is_array( $body['usage'] ?? null ) ? $body['usage'] : [];

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
			// Sent for cloud-side request logging; not used to gate anything.
			'entry_id' => (int) ( $entry['id'] ?? 0 ),
			'entry'    => $this->format_entry( $entry, $form ),
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
			'usage'       => is_array( $body['usage'] ?? null ) ? $body['usage'] : [],
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
			// Sent for cloud-side request logging; not used to gate anything.
			'entry_id' => (int) ( $entry['id'] ?? 0 ),
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
			'usage'          => is_array( $body['usage'] ?? null ) ? $body['usage'] : [],
		];
	}

	/**
	 * GET /v1/auth/me
	 *
	 * Fetches plan, capabilities, and quota for the authenticated workspace.
	 * Used by PlanManager to populate the local capability cache after a
	 * successful test-connection. See PlanManager for the expected response
	 * shape - the raw body is returned here and normalized there.
	 *
	 * @throws \RuntimeException  On transport failure or non-2xx response.
	 */
	public function fetch_account(): array {
		return $this->get( '/v1/auth/me' );
	}

	// HTTP transport

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
				'Neural Forms Cloud request failed: ' . esc_html( $response->get_error_message() )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 ) {
			// Plan-related errors carry structured data so the plugin can react
			// without parsing messages. Surface them as a typed exception.
			$body_code = is_array( $data ) ? (string) ( $data['code'] ?? '' ) : '';

			// SafetyGate decisions: distinct exception so AIQueueProcessor
			// can mark the entry blocked_by_safety instead of failed (no
			// retry, no credit deduction). Both block and unavailable
			// codes flow through this branch with the cloud's flat payload.
			if ( in_array( $body_code, [ 'blocked_by_safety', 'ai_safety_unavailable' ], true ) ) {
				$safety   = is_array( $data['safety'] ?? null ) ? $data['safety'] : [];
				$usage    = is_array( $data['usage']  ?? null ) ? $data['usage']  : [];
				$category = isset( $safety['category'] ) && is_string( $safety['category'] )
					? $safety['category'] : null;
				$action   = isset( $safety['action'] )   && is_string( $safety['action'] )
					? $safety['action']   : 'block';
				throw new CloudSafetyException(
					esc_html( (string) ( $data['message'] ?? $body_code ) ),
					esc_html( $body_code ),
					(int) $code,
					$category !== null ? esc_html( $category ) : null,
					esc_html( $action ),
					// $usage is a structured data array (credits_used,
					// credits_remaining, credits_deducted) stored as a
					// property for callers to read programmatically — not
					// for direct output. The exception class never echoes
					// it. phpcs can't see across the constructor boundary.
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
					$usage
				);
			}

			if ( in_array( $body_code, [ 'feature_locked', 'quota_exceeded' ], true ) ) {
				$quota = is_array( $data['quota'] ?? null ) ? $data['quota'] : [];
				throw new CloudPlanException(
					esc_html( (string) ( $data['message'] ?? $body_code ) ),
					esc_html( $body_code ),
					(int) $code,
					esc_html( (string) ( $data['feature']     ?? '' ) ),
					// self::esc_quota() recursively esc_html()s every scalar
					// inside the quota array; phpcs can't see across method
					// boundaries, so suppress the false positive here.
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
					self::esc_quota( $quota ),
					esc_html( (string) ( $data['upgrade_url'] ?? '' ) )
				);
			}

			$msg = ( is_array( $data ) && isset( $data['message'] ) )
				? (string) $data['message']
				: 'HTTP ' . (int) $code;

			$code_slug = $body_code !== '' ? ' [' . $body_code . ']' : '';

			// Include truncated response body for debugging 4xx errors.
			$body_hint = ( $code >= 400 && $code < 500 )
				? ' | body: ' . substr( $raw, 0, 500 )
				: '';

			throw new \RuntimeException(
				esc_html( "Neural Forms Cloud API error{$code_slug}: {$msg}{$body_hint}" )
			);
		}

		if ( ! is_array( $data ) ) {
			throw new \RuntimeException(
				'Neural Forms Cloud returned non-JSON response: ' . esc_html( substr( $raw, 0, 200 ) )
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
				'Neural Forms Cloud request failed: ' . esc_html( $response->get_error_message() )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 ) {
			$body_code = is_array( $data ) ? (string) ( $data['code'] ?? '' ) : '';
			if ( in_array( $body_code, [ 'feature_locked', 'quota_exceeded' ], true ) ) {
				$quota = is_array( $data['quota'] ?? null ) ? $data['quota'] : [];
				throw new CloudPlanException(
					esc_html( (string) ( $data['message'] ?? $body_code ) ),
					esc_html( $body_code ),
					(int) $code,
					esc_html( (string) ( $data['feature']     ?? '' ) ),
					// self::esc_quota() recursively esc_html()s every scalar
					// inside the quota array; phpcs can't see across method
					// boundaries, so suppress the false positive here.
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
					self::esc_quota( $quota ),
					esc_html( (string) ( $data['upgrade_url'] ?? '' ) )
				);
			}

			$msg       = is_array( $data ) && isset( $data['message'] ) ? (string) $data['message'] : 'HTTP ' . (int) $code;
			$code_slug = $body_code !== '' ? ' [' . $body_code . ']' : '';

			throw new \RuntimeException( esc_html( "Neural Forms Cloud API error{$code_slug}: {$msg}" ) );
		}

		if ( ! is_array( $data ) ) {
			throw new \RuntimeException(
				'Neural Forms Cloud returned non-JSON response: ' . esc_html( substr( $raw, 0, 200 ) )
			);
		}

		return $data;
	}

	/**
	 * Build the auth + version headers sent on every request.
	 *
	 * Passes the final array through the neural_forms_cloud_request_headers
	 * filter so add-on plugins (e.g. Neural Forms Pro) can inject their own
	 * headers - typically a license key - without this class needing to know
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

	// Payload helpers

	/**
	 * Flatten an entry row to the shape the cloud API expects.
	 *
	 * For Matrix-typed fields we replace the raw stored value with a
	 * structured envelope that carries the field label + row/column
	 * schema + scores alongside the stored value. The cloud's
	 * matrix-signals module detects the envelope (`_matrix: true`)
	 * and uses it to:
	 *   - render the field as readable text for the model instead of
	 *     emitting an opaque JSON blob, and
	 *   - extract a deterministic sentiment/priority hint to sanity-
	 *     upgrade the model's classification when needed.
	 *
	 * Non-matrix fields pass through untouched so the wire shape for
	 * every other field type stays the same.
	 */
	private function format_entry( array $entry, array $form = [] ): array {
		$fields = is_array( $entry['data'] ?? null ) ? $entry['data'] : [];
		if ( ! empty( $fields ) && ! empty( $form ) ) {
			$fields = $this->enrich_matrix_fields( $fields, $form );
		}
		return [
			'id'           => (int) ( $entry['id'] ?? 0 ),
			'fields'       => $fields,
			'submitted_at' => (string) ( $entry['created_at'] ?? '' ),
		];
	}

	/**
	 * Walk the form schema and, for each field whose stored value is a
	 * Matrix submission, wrap the value in a structured envelope so
	 * the cloud can interpret it.
	 *
	 * The envelope shape (kept in sync with the cloud's
	 * matrix-signals.ts MatrixFieldEnvelope interface) is:
	 *
	 *   {
	 *     _matrix:        true,
	 *     label:          string,
	 *     selection_type: "radio" | "checkbox",
	 *     rows:           [{ label, value }, ...],
	 *     columns:        [{ label, value, score? }, ...],
	 *     value:          <stored matrix value>,
	 *   }
	 *
	 * Form schemas can carry fields nested under `step.fields` and
	 * `row.columns[].fields`, so we recurse to find every leaf matrix
	 * field. Fields with no entry value, or whose stored value is not
	 * an array, are skipped (no envelope written).
	 */
	private function enrich_matrix_fields( array $fields, array $form ): array {
		$schema = is_array( $form['form_schema'] ?? null ) ? $form['form_schema'] : [];
		$nodes  = is_array( $schema['fields'] ?? null )    ? $schema['fields']    : [];
		$out    = $fields;
		$this->enrich_matrix_walk( $nodes, $out );
		return $out;
	}

	/**
	 * Recurse into a list of schema nodes (mix of leaf fields, rows,
	 * sections, steps) and enrich any matrix field that has a stored
	 * value on the entry.
	 *
	 * Mutates `$values` in place — this is the entry data map we're
	 * about to send to the cloud, keyed on field id.
	 */
	private function enrich_matrix_walk( array $nodes, array &$values ): void {
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$type = (string) ( $node['type'] ?? '' );

			// Layout containers — recurse into children.
			if ( 'step' === $type || 'section' === $type ) {
				$children = is_array( $node['fields'] ?? null ) ? $node['fields'] : [];
				$this->enrich_matrix_walk( $children, $values );
				continue;
			}
			if ( 'row' === $type ) {
				$columns = is_array( $node['columns'] ?? null ) ? $node['columns'] : [];
				foreach ( $columns as $col ) {
					if ( ! is_array( $col ) ) continue;
					$col_fields = is_array( $col['fields'] ?? null ) ? $col['fields'] : [];
					$this->enrich_matrix_walk( $col_fields, $values );
				}
				continue;
			}

			if ( 'matrix' !== $type ) {
				continue;
			}

			$field_id = (string) ( $node['id'] ?? '' );
			if ( $field_id === '' || ! array_key_exists( $field_id, $values ) ) {
				continue;
			}
			$stored = $values[ $field_id ];
			if ( ! is_array( $stored ) ) {
				// A scalar / null where an array was expected — leave alone
				// so the cloud receives whatever the storage layer wrote.
				continue;
			}

			$settings  = is_array( $node['settings'] ?? null ) ? $node['settings'] : [];
			$selection = ( ( $settings['selection_type'] ?? 'radio' ) === 'checkbox' ) ? 'checkbox' : 'radio';

			$rows = [];
			foreach ( ( is_array( $settings['rows'] ?? null ) ? $settings['rows'] : [] ) as $row ) {
				$row = (array) $row;
				$rv  = (string) ( $row['value'] ?? '' );
				$rl  = (string) ( $row['label'] ?? '' );
				if ( $rv === '' && $rl === '' ) continue;
				$rows[] = [
					'label' => $rl !== '' ? $rl : $rv,
					'value' => $rv !== '' ? $rv : $rl,
				];
			}
			$columns = [];
			foreach ( ( is_array( $settings['columns'] ?? null ) ? $settings['columns'] : [] ) as $col ) {
				$col = (array) $col;
				$cv  = (string) ( $col['value'] ?? '' );
				$cl  = (string) ( $col['label'] ?? '' );
				if ( $cv === '' && $cl === '' ) continue;
				$entry = [
					'label' => $cl !== '' ? $cl : $cv,
					'value' => $cv !== '' ? $cv : $cl,
				];
				if ( isset( $col['score'] ) && is_numeric( $col['score'] ) ) {
					$entry['score'] = (int) $col['score'];
				}
				$columns[] = $entry;
			}

			$values[ $field_id ] = [
				'_matrix'        => true,
				'label'          => (string) ( $node['label'] ?? '' ),
				'selection_type' => $selection,
				'rows'           => $rows,
				'columns'        => $columns,
				'value'          => $stored,
			];
		}
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

	// Response normalization

	/**
	 * Recursively run esc_html() on the scalar values of a quota array so
	 * the result is safe to hand to a thrown exception. Non-scalar values
	 * (which the cloud API never returns under `quota`) are dropped.
	 */
	private static function esc_quota( array $quota ): array {
		$out = [];
		foreach ( $quota as $key => $value ) {
			$safe_key = is_string( $key ) ? esc_html( $key ) : $key;
			if ( is_array( $value ) ) {
				$out[ $safe_key ] = self::esc_quota( $value );
			} elseif ( is_scalar( $value ) ) {
				$out[ $safe_key ] = esc_html( (string) $value );
			}
		}
		return $out;
	}

	/** Allowed tier slugs mirrored from the API's model-registry. */
	const VALID_TIERS = [ 'fast', 'balanced', 'smart', 'premium' ];

	/**
	 * Clamp the API's tier_used field to a known slug. Null/invalid '->-> 'fast'.
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
