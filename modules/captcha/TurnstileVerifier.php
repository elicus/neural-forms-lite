<?php
namespace NeuralForms\Captcha;

defined( 'ABSPATH' ) || exit;

/**
 * TurnstileVerifier
 *
 * Verifies Cloudflare Turnstile tokens by calling the siteverify API.
 *
 * Server-side verification flow:
 *   1. POST secret + response + remoteip to the siteverify URL.
 *   2. Decode the JSON response body.
 *   3. Treat success:true as pass, anything else as fail.
 *
 * @see https://developers.cloudflare.com/turnstile/get-started/server-side-validation/
 */
class TurnstileVerifier implements CaptchaVerifierInterface {

	private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

	/** @var string Cloudflare Turnstile secret key. */
	private string $secret_key;

	/**
	 * @param string $secret_key  Cloudflare Turnstile secret key.
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
		$body = [
			'secret'   => $this->secret_key,
			'response' => $token,
		];

		if ( $ip !== '' ) {
			$body['remoteip'] = $ip;
		}

		$response = wp_remote_post( self::VERIFY_URL, [
			'timeout' => 5,
			'body'    => $body,
		] );

		// HTTP request itself failed (network, DNS, TLS).
		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'captcha_unreachable',
				__( 'Could not reach CAPTCHA verification service.', 'neural-forms-lite' ),
				[
					'provider' => 'turnstile',
					'wp_error' => $response->get_error_message(),
				]
			);
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $decoded ) || empty( $decoded['success'] ) ) {
			return new \WP_Error(
				'captcha_failed',
				__( 'CAPTCHA verification failed.', 'neural-forms-lite' ),
				[
					'provider'    => 'turnstile',
					'error_codes' => $decoded['error-codes'] ?? [],
				]
			);
		}

		return true;
	}
}
