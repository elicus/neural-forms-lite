<?php
namespace NeuralForms\AI;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * HTTP client for the Cloud's `/v1/ai/forms/generate` endpoint.
 *
 * Plugin never holds a provider key — cloud handles auth, routing, credit
 * deduction, and normalisation. Plugin re-runs SchemaNormalizer +
 * FormValidator as defence-in-depth. Lite-owned since 2026-10-30 (was Pro).
 */
final class CloudFormGeneratorClient {

	const ENDPOINT_PATH = '/v1/ai/forms/generate';

	/** Override via constant NEURAL_FORMS_CLOUD_API_URL or filter neural_forms_cloud_api_url. */
	const DEFAULT_BASE = 'https://api.neuralforms.ai';

	/**
	 * Defers to Lite's AiManager so this matches every other AI route.
	 * Does NOT consult the `cloud_api_base_url` option — a stale dev value
	 * would route prod requests off-host (use constant/filter for staging).
	 */
	public static function resolve_base_url(): string {
		if ( class_exists( '\\NeuralForms\\AI\\AiManager' )
			&& method_exists( '\\NeuralForms\\AI\\AiManager', 'resolve_base_url' )
		) {
			$base = \NeuralForms\AI\AiManager::resolve_base_url();
			if ( is_string( $base ) && $base !== '' ) {
				return rtrim( $base, '/' );
			}
		}
		// Bootstrap fallback when Lite's AI module isn't autoloaded yet.
		if ( defined( 'NEURAL_FORMS_CLOUD_API_URL' ) && is_string( NEURAL_FORMS_CLOUD_API_URL ) && NEURAL_FORMS_CLOUD_API_URL !== '' ) {
			return rtrim( (string) NEURAL_FORMS_CLOUD_API_URL, '/' );
		}
		$filtered = apply_filters( 'neural_forms_cloud_api_url', self::DEFAULT_BASE );
		return rtrim( is_string( $filtered ) && $filtered !== '' ? $filtered : self::DEFAULT_BASE, '/' );
	}

	/** Does not probe credits — that's a network round-trip. Use status() for that. */
	public static function is_connected(): bool {
		if ( ! class_exists( '\\NeuralForms\\Core\\Config' ) ) {
			return false;
		}
		$key = (string) \NeuralForms\Core\Config::get( 'cloud_api_key', '' );
		return $key !== '';
	}

	/** Trim is critical — cloud matches the SHA-256 of exact bytes; stray whitespace silently 401s. */
	private static function resolve_key(): string {
		if ( ! class_exists( '\\NeuralForms\\Core\\Config' ) ) {
			return '';
		}
		return trim( (string) \NeuralForms\Core\Config::get( 'cloud_api_key', '' ) );
	}

	/**
	 * Mirrors NeuralFormsCloudProvider::headers() — uses Lite's version
	 * constant (NOT Pro's). Filter merge order keeps core auth headers
	 * authoritative so a misbehaving filter can't drop them.
	 */
	public static function headers(): array {
		$headers  = array(
			'Authorization'         => 'Bearer ' . self::resolve_key(),
			'Content-Type'          => 'application/json',
			'X-NeuralForms-Version' => defined( 'NEURAL_FORMS_VERSION' )
				? (string) NEURAL_FORMS_VERSION
				: ( defined( 'NEURAL_FORMS_LITE_VERSION' ) ? (string) NEURAL_FORMS_LITE_VERSION : '' ),
			'X-NeuralForms-Site'    => function_exists( 'site_url' ) ? (string) site_url() : '',
		);
		$filtered = apply_filters( 'neural_forms_cloud_request_headers', $headers );
		return is_array( $filtered ) ? array_merge( $filtered, $headers ) : $headers;
	}

	/** Cross-checked against Lite's FieldRegistry at runtime. */
	public static function build_capabilities(): array {
		$candidates = array(
			'text',
			'email',
			'textarea',
			'select',
			'checkbox',
			'checkboxes',
			'number',
			'phone',
			'url',
			'date',
			'acceptance',
			'radio',
			'range',
			'file',
			'hidden',
			'rating',
			'poll',
			'quiz_question',
		);
		$supported  = array();
		if ( class_exists( '\\NeuralForms\\Forms\\FieldRegistry' ) ) {
			foreach ( $candidates as $t ) {
				if ( \NeuralForms\Forms\FieldRegistry::is_registered( $t ) ) {
					$supported[] = $t;
				}
			}
		} else {
			$supported = $candidates;
		}

		// Matrix is gated by Capabilities::FIELD_MATRIX — FieldRegistry lists it
		// even with Pro deactivated (so existing forms keep schema).
		$matrix_supported = false;
		if ( class_exists( '\\NeuralForms\\Core\\Capabilities' )
			&& \NeuralForms\Core\Capabilities::can( \NeuralForms\Core\Capabilities::FIELD_MATRIX )
			&& class_exists( '\\NeuralForms\\Forms\\FieldRegistry' )
			&& \NeuralForms\Forms\FieldRegistry::is_registered( 'matrix' )
		) {
			$matrix_supported = true;
			$supported[]      = 'matrix';
		}

		// Same capability-gated pattern for Repeater.
		$repeater_supported = false;
		if ( class_exists( '\\NeuralForms\\Core\\Capabilities' )
			&& \NeuralForms\Core\Capabilities::can( \NeuralForms\Core\Capabilities::FIELD_REPEATER )
			&& class_exists( '\\NeuralForms\\Forms\\FieldRegistry' )
			&& \NeuralForms\Forms\FieldRegistry::is_registered( 'repeater' )
		) {
			$repeater_supported = true;
			$supported[]        = 'repeater';
		}

		return array(
			'supported_fields'  => array_values( $supported ),
			'supports_steps'    => true,
			'supports_rows'     => true,
			'supports_sections' => true,
			'supports_poll'     => in_array( 'poll', $supported, true ),
			'supports_quiz'     => in_array( 'quiz_question', $supported, true ),
			'supports_rating'   => in_array( 'rating', $supported, true ),
			'supports_matrix'   => $matrix_supported,
			'supports_repeater' => $repeater_supported,
		);
	}

	/** Non-PII metadata only. Cloud uses these for telemetry + version gating. */
	public static function build_site_envelope(): array {
		return array(
			'url'            => home_url( '/' ),
			'wp_version'     => function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'version' ) : '',
			'plugin_version' => defined( 'NEURAL_FORMS_LITE_VERSION' ) ? (string) NEURAL_FORMS_LITE_VERSION
				: ( defined( 'NEURAL_FORMS_VERSION' ) ? (string) NEURAL_FORMS_VERSION : '' ),
			'pro_version'    => defined( 'NEURAL_FORMS_PRO_VERSION' ) ? (string) NEURAL_FORMS_PRO_VERSION : '',
		);
	}

	/**
	 * @param string $prompt Sanitised user prompt.
	 * @param array  $opts   Extra envelope keys (limits, locale).
	 * @return array|WP_Error On success: { form, warnings, request_id, usage }
	 */
	public static function generate( string $prompt, array $opts = array() ) {
		if ( ! self::is_connected() ) {
			return new WP_Error(
				'cloud_not_connected',
				__( 'Connect Neural Forms Cloud to use Neural AI generation.', 'neural-forms-lite' ),
				array( 'status' => 409 )
			);
		}

		$base = self::resolve_base_url();
		$url  = $base . self::ENDPOINT_PATH;

		$body = array(
			'prompt'       => $prompt,
			'site'         => self::build_site_envelope(),
			'capabilities' => self::build_capabilities(),
			'limits'       => array(
				'max_fields'  => 40,
				'max_steps'   => 8,
				'max_options' => 10,
			),
			'locale'       => function_exists( 'get_locale' ) ? (string) get_locale() : 'en_US',
		);
		if ( isset( $opts['limits'] ) && is_array( $opts['limits'] ) ) {
			$body['limits'] = array_merge( $body['limits'], $opts['limits'] );
		}

		$res = wp_remote_post(
			$url,
			array(
				'timeout' => 45,
				'headers' => self::headers(),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $res ) ) {
			return new WP_Error(
				'ai_provider_error',
				$res->get_error_message(),
				array( 'status' => 424 )
			);
		}

		$code    = (int) wp_remote_retrieve_response_code( $res );
		$raw     = (string) wp_remote_retrieve_body( $res );
		$payload = json_decode( $raw, true );

		// Reads both wrapped (HttpExceptionFilter) and bare error shapes.
		if ( $code < 200 || $code >= 300 ) {
			$err_envelope     = is_array( $payload ) && is_array( $payload['error'] ?? null )
				? $payload['error']
				: ( is_array( $payload ) ? $payload : array() );
			$cloud_code       = isset( $err_envelope['code'] ) ? (string) $err_envelope['code'] : '';
			$cloud_msg        = isset( $err_envelope['message'] ) ? (string) $err_envelope['message'] : '';
			$cloud_request_id = isset( $err_envelope['request_id'] )
				? (string) $err_envelope['request_id']
				: ( isset( $payload['request_id'] ) ? (string) $payload['request_id'] : '' );
			if ( $cloud_msg === '' ) {
				$cloud_msg = sprintf(
					/* translators: %d: HTTP status */
					__( 'Cloud returned HTTP %d.', 'neural-forms-lite' ),
					$code
				);
			}
			$err_data = array(
				'status'       => $code >= 500 ? 424 : $code, // never 502/504 (CDN swallows the body)
				'cloud_code'   => $cloud_code,
				'cloud_status' => $code,
				'request_id'   => $cloud_request_id,
			);
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log(
					sprintf(
						'[NF AI] cloud error code=%s status=%d request_id=%s',
						$cloud_code !== '' ? $cloud_code : '(empty)',
						$code,
						$cloud_request_id !== '' ? $cloud_request_id : '(none)'
					)
				);
			}
			return new WP_Error(
				self::map_cloud_error( $cloud_code, $code ),
				$cloud_msg,
				$err_data
			);
		}

		if ( ! is_array( $payload ) || empty( $payload['form'] ) ) {
			return new WP_Error(
				'ai_invalid_response',
				__( 'Cloud returned an unexpected payload.', 'neural-forms-lite' ),
				array( 'status' => 424 )
			);
		}

		return array(
			'form'       => $payload['form'],
			'warnings'   => is_array( $payload['warnings'] ?? null ) ? $payload['warnings'] : array(),
			'request_id' => isset( $payload['request_id'] ) ? (string) $payload['request_id'] : '',
			'usage'      => is_array( $payload['usage'] ?? null ) ? $payload['usage'] : array(),
		);
	}

	private static function map_cloud_error( string $cloud_code, int $http_status ): string {
		switch ( $cloud_code ) {
			case 'invalid_prompt':
				return 'invalid_prompt';
			case 'unauthorized':
			case 'invalid_api_key':
			case 'missing_authorization':
			case 'invalid_authorization':
				return 'cloud_unauthorized';
			case 'plan_not_allowed':
				return 'plan_not_allowed';
			case 'feature_locked':
				return 'feature_not_allowed';
			case 'subscription_expired':
				return 'cloud_unauthorized';
			case 'quota_exceeded':
			case 'insufficient_credits':
				return 'insufficient_credits';
			case 'rate_limited':
				return 'ai_rate_limited';
			case 'malformed_ai_response':
				return 'ai_invalid_response';
			case 'invalid_ai_response':
				return 'ai_invalid_response';
			case 'schema_normalization_failed':
				return 'ai_invalid_response';
			case 'no_supported_fields':
				return 'ai_invalid_response';
			case 'provider_timeout':
				return 'ai_provider_timeout';
			case 'provider_unavailable':
				return 'ai_provider_error';
			case 'ai_provider_unavailable':
				return 'ai_provider_error';
			case 'domain_already_claimed':
				return 'plan_not_allowed';
		}
		if ( 401 === $http_status || 403 === $http_status ) {
			return 'cloud_unauthorized';
		}
		if ( 402 === $http_status ) {
			return 'insufficient_credits';
		}
		if ( 429 === $http_status ) {
			return 'ai_rate_limited';
		}
		return 'ai_provider_error';
	}
}
