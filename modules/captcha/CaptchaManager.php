<?php
namespace NeuralForms\Captcha;

use NeuralForms\Core\Config;

defined( 'ABSPATH' ) || exit;

/**
 * CaptchaManager
 *
 * Factory and dispatcher for CAPTCHA token verification.
 *
 * Reads global plugin settings to determine the active provider and
 * credentials, then instantiates and delegates to the correct verifier.
 *
 * All methods are static - no instantiation needed.
 *
 * Usage
 *
 *   if ( CaptchaManager::is_configured() ) {
 *       $result = CaptchaManager::verify( $token, $ip );
 *       if ( is_wp_error( $result ) ) { ... }
 *   }
 */
class CaptchaManager {

	// Public API

	/**
	 * Build and return the verifier for the currently configured provider.
	 *
	 * Returns null when:
	 *   - captcha_provider is 'none'
	 *   - captcha_secret_key is empty (keys not yet configured)
	 *
	 * @return CaptchaVerifierInterface|null
	 */
	public static function get_verifier(): ?CaptchaVerifierInterface {
		$provider   = (string) Config::get( 'captcha_provider', 'none' );
		$secret_key = (string) Config::get( 'captcha_secret_key', '' );

		if ( $provider === 'none' || $secret_key === '' ) {
			return null;
		}

		switch ( $provider ) {
			case 'turnstile':
				return new TurnstileVerifier( $secret_key );
			case 'recaptcha_v2':
				return new RecaptchaV2Verifier( $secret_key );
			default:
				return null;
		}
	}

	/**
	 * Returns true when a provider is active and BOTH keys are present.
	 *
	 * Requires captcha_site_key in addition to captcha_secret_key so this
	 * method's answer matches FormRenderer's widget-render condition exactly:
	 *   $captcha_provider !== 'none' && $captcha_site_key !== '' && $captcha_secret_key !== ''
	 *
	 * When only one key is configured the widget is never rendered, so the
	 * backend must also skip enforcement - otherwise every submission gets a
	 * 400 for a token the user was never asked to provide.
	 *
	 * Use this before calling verify() to distinguish "not configured"
	 * from "configured but verification failed".
	 *
	 * @return bool
	 */
	public static function is_configured(): bool {
		$site_key = (string) Config::get( 'captcha_site_key', '' );
		return $site_key !== '' && static::get_verifier() !== null;
	}

	/**
	 * Verify a CAPTCHA token against the configured provider.
	 *
	 * Returns WP_Error with code 'captcha_not_configured' when no provider
	 * is active - callers should check is_configured() before calling this
	 * if they need to distinguish misconfiguration from a failed token.
	 *
	 * @param string $token  Token from the form submission.
	 * @param string $ip     Resolved client IP address.
	 * @return true|\WP_Error
	 */
	public static function verify( string $token, string $ip ) {
		$verifier = static::get_verifier();

		if ( null === $verifier ) {
			return new \WP_Error(
				'captcha_not_configured',
				__( 'CAPTCHA is not configured.', 'neural-forms-lite' )
			);
		}

		return $verifier->verify( $token, $ip );
	}
}
