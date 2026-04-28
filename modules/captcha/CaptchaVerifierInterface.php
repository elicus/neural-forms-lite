<?php
namespace NeuralForms\Captcha;

defined( 'ABSPATH' ) || exit;

/**
 * CaptchaVerifierInterface
 *
 * Contract for CAPTCHA provider implementations.
 *
 * Each verifier handles one provider's server-side API call.
 * The interface is intentionally minimal — the caller only needs
 * to know whether the token is valid, not how that was determined.
 *
 * WP_Error codes returned on failure:
 *   captcha_failed       — Provider responded with success: false.
 *   captcha_unreachable  — wp_remote_post() itself returned a WP_Error
 *                          (DNS failure, timeout, TLS error, etc.).
 */
interface CaptchaVerifierInterface {

	/**
	 * Verify the CAPTCHA token returned by the client-side widget.
	 *
	 * @param string $token  Token from the form submission
	 *                       (e.g. cf-turnstile-response or g-recaptcha-response).
	 * @param string $ip     Resolved client IP address.
	 *                       Passed to the provider when non-empty — improves
	 *                       detection accuracy for most providers.
	 * @return true|\WP_Error  true on success; WP_Error on any failure.
	 */
	public function verify( string $token, string $ip ): true|\WP_Error;
}
