<?php
namespace NeuralForms\Captcha;

defined( 'ABSPATH' ) || exit;

/**
 * RecaptchaV2Verifier
 *
 * Verifies Google reCAPTCHA v2 ("I'm not a robot") tokens through the
 * siteverify API.
 *
 * @see https://developers.google.com/recaptcha/docs/verify
 */
class RecaptchaV2Verifier extends SiteverifyVerifier {

	protected function verify_url(): string {
		return 'https://www.google.com/recaptcha/api/siteverify';
	}

	protected function provider_id(): string {
		return 'recaptcha_v2';
	}
}
