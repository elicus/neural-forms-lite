<?php
namespace NeuralForms\Rest\Endpoints;

use NeuralForms\AI\FormGeneratorService;
use WP_REST_Request;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * POST /wp-json/neural-forms/v1/ai/generate-form (Lite-owned since 2026-10-30)
 *
 * Generates a Neural Forms schema from a natural-language prompt. Rate
 * limiting is owned by the cloud (per-site click budget + plan credit
 * caps) — the plugin trusts the cloud's 429 / `rate_limited` response.
 */
class FormGeneratorEndpoint {

	public function register_routes(): void {
		$ns = \NeuralForms\Rest\RestAPI::NAMESPACE;

		register_rest_route(
			$ns,
			'/ai/generate-form',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'handle' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'prompt' => array(
							'required' => true,
							'type'     => 'string',
						),
						'mode'   => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/ai/generate-form/status',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'status' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);
	}

	public function check_permission() {
		if ( ! current_user_can( NEURAL_FORMS_CAP_MANAGE_FORMS ) ) {
			return new WP_Error(
				'permission_denied',
				__( 'You do not have permission to manage forms.', 'neural-forms-lite' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	public function status( WP_REST_Request $request ) {
		$snap = FormGeneratorService::status();

		// Behind ?debug=1 AND manage_options.
		$debug = $request->get_param( 'debug' );
		if ( $debug && current_user_can( 'manage_options' ) ) {
			$snap['debug'] = FormGeneratorService::debug_status_snapshot();

			if ( (string) $debug === '2' || (string) $debug === 'live' ) {
				$snap['debug']['live_probe'] = self::probe_cloud_auth_me();
			}
		}

		return rest_ensure_response( $snap );
	}

	/** Admin-debug-only probe of cloud `/v1/auth/me`. */
	private static function probe_cloud_auth_me(): array {
		if ( ! class_exists( '\\NeuralForms\\AI\\CloudFormGeneratorClient' ) ) {
			return array(
				'reachable' => false,
				'note'      => 'cloud client not loaded',
			);
		}
		$base = \NeuralForms\AI\CloudFormGeneratorClient::resolve_base_url();
		$key = class_exists( '\\NeuralForms\\Core\\Config' )
			? trim( (string) \NeuralForms\Core\Config::get( 'cloud_api_key', '' ) )
			: '';
		$res = wp_remote_get(
			$base . '/v1/auth/me',
			array(
				'timeout' => 15,
				'headers' => \NeuralForms\AI\CloudFormGeneratorClient::headers(),
			)
		);
		if ( is_wp_error( $res ) ) {
			return array(
				'reachable' => false,
				'note'      => $res->get_error_message(),
				'base_url'  => $base,
				'key_len'   => strlen( $key ),
			);
		}
		$code    = (int) wp_remote_retrieve_response_code( $res );
		$body    = (string) wp_remote_retrieve_body( $res );
		$payload = json_decode( $body, true );
		$err     = is_array( $payload ) && is_array( $payload['error'] ?? null ) ? $payload['error'] : null;
		return array(
			'reachable'     => true,
			'http_status'   => $code,
			'base_url'      => $base,
			'key_len'       => strlen( $key ),
			'key_prefix'    => $key !== '' ? substr( $key, 0, 4 ) . '…' : '',
			'cloud_code'    => $err ? (string) ( $err['code'] ?? '' ) : '',
			'cloud_message' => $err ? (string) ( $err['message'] ?? '' ) : '',
			'plan_id'       => is_array( $payload ) && is_array( $payload['plan'] ?? null )
				? (string) ( $payload['plan']['id'] ?? '' )
				: '',
		);
	}

	public function handle( WP_REST_Request $request ) {
		$debug_on = self::debug_enabled( $request );
		self::debug_log(
			$debug_on,
			'endpoint reached',
			array(
				'user_id'    => get_current_user_id(),
				'has_prompt' => $request->get_param( 'prompt' ) !== null,
			)
		);

		$prompt = (string) $request->get_param( 'prompt' );
		$prompt = wp_strip_all_tags( $prompt, true );
		$prompt = preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $prompt );
		$prompt = trim( (string) $prompt );

		// Prompt body never logged — only its length.
		$snap = FormGeneratorService::debug_status_snapshot();
		self::debug_log(
			$debug_on,
			'pre-call snapshot',
			array(
				'eligible'          => $snap['eligible'],
				'cloud_connected'   => $snap['cloud_connected'],
				'credits_available' => $snap['credits_available'],
				'prompt_length'     => strlen( $prompt ),
				'eligible_reasons'  => $snap['eligible_reasons'],
			)
		);

		$result = FormGeneratorService::generate( $prompt );
		if ( is_wp_error( $result ) ) {
			self::debug_log(
				$debug_on,
				'generate WP_Error',
				array(
					'code'   => $result->get_error_code(),
					'status' => is_array( $result->get_error_data() ) ? ( $result->get_error_data()['status'] ?? null ) : null,
				)
			);
			if ( $debug_on && current_user_can( 'manage_options' ) ) {
				$data = $result->get_error_data();
				if ( ! is_array( $data ) ) {
					$data = array();
				}
				$data['debug'] = self::build_response_debug( $snap, false, null );
				$result->add_data( $data );
			}
			return $result;
		}

		self::debug_log(
			$debug_on,
			'generate ok',
			array(
				'request_id' => $result['request_id'] ?? '',
				'fields'     => isset( $result['form']['form_schema']['fields'] )
					? count( $result['form']['form_schema']['fields'] ) : 0,
				'warnings'   => isset( $result['warnings'] ) ? count( $result['warnings'] ) : 0,
			)
		);

		$response = array(
			'success'    => true,
			'form'       => $result['form'],
			'warnings'   => $result['warnings'],
			'request_id' => $result['request_id'] ?? '',
			'usage'      => $result['usage'] ?? array(),
		);

		if ( $debug_on && current_user_can( 'manage_options' ) ) {
			$response['debug'] = self::build_response_debug( $snap, true, $result['request_id'] ?? null );
		}

		return rest_ensure_response( $response );
	}

	/** WP_DEBUG + opt-in via ?debug_ai=1 or X-Neural-Forms-Debug header. */
	private static function debug_enabled( WP_REST_Request $request ): bool {
		if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			return false;
		}
		$qs  = $request->get_param( 'debug_ai' );
		$hdr = $request->get_header( 'x_neural_forms_debug' );
		return ( $qs && (string) $qs === '1' ) || ( $hdr && (string) $hdr === '1' );
	}

	/** Routes through Lite's Logger when available. Never logs credentials. */
	private static function debug_log( bool $on, string $event, array $fields = array() ): void {
		if ( ! $on ) {
			return;
		}
		$line = '[NF AI] ' . $event . ' ' . wp_json_encode( $fields );
		if ( class_exists( '\\NeuralForms\\Core\\Logger' ) ) {
			try {
				\NeuralForms\Core\Logger::file( 'ai-form-generator', 'debug', $line );
				return;
			} catch ( \Throwable $e ) {
				// fall through to error_log
			}
		}
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( $line );
	}

	/** Omits anything that resembles a credential. */
	private static function build_response_debug( array $snap, bool $cloud_called, $request_id ): array {
		return array(
			'endpoint_reached'  => true,
			'eligible'          => (bool) $snap['eligible'],
			'cloud_connected'   => (bool) $snap['cloud_connected'],
			'credits_available' => (bool) $snap['credits_available'],
			'eligible_reasons'  => $snap['eligible_reasons'],
			'cloud_called'      => $cloud_called,
			'cloud_status'      => $cloud_called ? 200 : null,
			'request_id'        => $cloud_called ? ( $request_id ?: null ) : null,
		);
	}
}
