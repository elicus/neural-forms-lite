<?php
namespace NeuralForms\Captcha;

defined( 'ABSPATH' ) || exit;

/**
 * SiteverifyVerifier
 *
 * Shared server-side check for every provider that follows the
 * "siteverify" pattern (Turnstile, hCaptcha, reCAPTCHA v2 and v3):
 *
 *   1. POST secret + response (+ remoteip, + extra fields) to the URL.
 *   2. Decode the JSON body.
 *   3. success:true → pass (subclasses may add checks, e.g. the v3 score).
 *
 * Failure codes match CaptchaVerifierInterface: captcha_unreachable when
 * the HTTP call itself fails, captcha_failed for any rejected token.
 */
abstract class SiteverifyVerifier implements CaptchaVerifierInterface {

	/** @var string Provider secret key. */
	protected string $secret_key;

	/**
	 * @param string $secret_key  Provider secret key.
	 */
	public function __construct( string $secret_key ) {
		$this->secret_key = $secret_key;
	}

	/** Siteverify endpoint. */
	abstract protected function verify_url(): string;

	/** Provider slug written to the CAPTCHA log. */
	abstract protected function provider_id(): string;

	/**
	 * Extra POST fields beyond secret / response / remoteip.
	 *
	 * @return array<string,string>
	 */
	protected function extra_body(): array {
		return array();
	}

	/**
	 * Additional checks on a response that already has success:true.
	 * Return true to accept, or a WP_Error to reject.
	 *
	 * @param array $decoded  Decoded siteverify response.
	 * @return true|\WP_Error
	 */
	protected function check_success( array $decoded ) {
		return true;
	}

	/**
	 * @inheritDoc
	 *
	 * @return true|\WP_Error
	 */
	public function verify( string $token, string $ip ) {
		$body = array_merge(
			array(
				'secret'   => $this->secret_key,
				'response' => $token,
			),
			$this->extra_body()
		);

		if ( $ip !== '' ) {
			$body['remoteip'] = $ip;
		}

		$response = wp_remote_post(
			$this->verify_url(),
			array(
				'timeout' => 5,
				'body'    => $body,
			)
		);

		// HTTP request itself failed (network, DNS, TLS).
		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'captcha_unreachable',
				__( 'Could not reach CAPTCHA verification service.', 'neural-forms-lite' ),
				array(
					'provider' => $this->provider_id(),
					'wp_error' => $response->get_error_message(),
				)
			);
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $decoded ) || empty( $decoded['success'] ) ) {
			return new \WP_Error(
				'captcha_failed',
				__( 'CAPTCHA verification failed.', 'neural-forms-lite' ),
				array(
					'provider'    => $this->provider_id(),
					'error_codes' => is_array( $decoded ) ? ( $decoded['error-codes'] ?? array() ) : array(),
				)
			);
		}

		return $this->check_success( $decoded );
	}
}
