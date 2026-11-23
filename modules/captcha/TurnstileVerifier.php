<?php
namespace NeuralForms\Captcha;

defined( 'ABSPATH' ) || exit;

/**
 * TurnstileVerifier
 *
 * Verifies Cloudflare Turnstile tokens through the siteverify API.
 *
 * @see https://developers.cloudflare.com/turnstile/get-started/server-side-validation/
 */
class TurnstileVerifier extends SiteverifyVerifier {

	protected function verify_url(): string {
		return 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
	}

	protected function provider_id(): string {
		return 'turnstile';
	}
}
