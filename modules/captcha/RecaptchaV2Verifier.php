<?php
namespace NeuralForms\Captcha;

defined( 'ABSPATH' ) || exit;

/**
 * RecaptchaV2Verifier
 *
 * Verifies Google reCAPTCHA v2 tokens by calling the siteverify API.
 *
 * Server-side verification flow:
 *   1. POST secret + response + remoteip to the siteverify URL.
 *   2. Decode the JSON response body.
 *   3. Treat success:true as pass, anything else as fail.
 *
 * @see https://developers.google.com/recaptcha/docs/verify
 */
class RecaptchaV2Verifier implements CaptchaVerifierInterface {

	private const VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

	/** @var string Google reCAPTCHA v2 secret key. */
	private string $secret_key;

	/**
	 * @param string $secret_key  Google reCAPTCHA v2 secret key.
	 */
	public function __construct( string $secret_key ) {
		$this->secret_key = $secret_key;
	}

	// CaptchaVerifierInterface

	/**
	 * @inheritDoc
	 *
	 * @return true|\WP_Error
	 */
	public function verify( string $token, string $ip ) {
		$body = array(
			'secret'   => $this->secret_key,
			'response' => $token,
		);

		if ( $ip !== '' ) {
			$body['remoteip'] = $ip;
		}

		$response = wp_remote_post(
			self::VERIFY_URL,
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
					'provider' => 'recaptcha_v2',
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
					'provider'    => 'recaptcha_v2',
					'error_codes' => $decoded['error-codes'] ?? array(),
				)
			);
		}

		return true;
	}
}
