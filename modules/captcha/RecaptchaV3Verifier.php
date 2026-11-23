<?php
namespace NeuralForms\Captcha;

defined( 'ABSPATH' ) || exit;

/**
 * RecaptchaV3Verifier
 *
 * Verifies invisible Google reCAPTCHA v3 tokens. On top of success:true,
 * the token must carry the action the form requested and a score at or
 * above the site's threshold (0.0 = bot, 1.0 = human). A low score is a
 * normal captcha_failed so the visitor sees the usual retry message; the
 * score itself only goes to the CAPTCHA log.
 *
 * @see https://developers.google.com/recaptcha/docs/v3
 */
class RecaptchaV3Verifier extends SiteverifyVerifier {

	/** Action name the frontend passes to grecaptcha.execute(). */
	public const ACTION = 'neural_forms_submit';

	/** Default and allowed range for the score threshold. */
	public const DEFAULT_THRESHOLD = 0.5;
	public const MIN_THRESHOLD     = 0.1;
	public const MAX_THRESHOLD     = 0.9;

	/** @var float Minimum score accepted. */
	private float $threshold;

	/**
	 * @param string $secret_key  reCAPTCHA v3 secret key.
	 * @param float  $threshold   Minimum score accepted.
	 */
	public function __construct( string $secret_key, float $threshold = self::DEFAULT_THRESHOLD ) {
		parent::__construct( $secret_key );
		$this->threshold = self::clamp_threshold( $threshold );
	}

	/** Keep the threshold inside the range the settings screen offers. */
	public static function clamp_threshold( $value ): float {
		$v = is_numeric( $value ) ? (float) $value : self::DEFAULT_THRESHOLD;
		return round( min( self::MAX_THRESHOLD, max( self::MIN_THRESHOLD, $v ) ), 1 );
	}

	protected function verify_url(): string {
		return 'https://www.google.com/recaptcha/api/siteverify';
	}

	protected function provider_id(): string {
		return 'recaptcha_v3';
	}

	protected function check_success( array $decoded ) {
		$action = (string) ( $decoded['action'] ?? '' );
		if ( $action !== self::ACTION ) {
			return new \WP_Error(
				'captcha_failed',
				__( 'CAPTCHA verification failed.', 'neural-forms-lite' ),
				array(
					'provider' => $this->provider_id(),
					'reason'   => 'action_mismatch',
					'action'   => $action,
				)
			);
		}

		$score = isset( $decoded['score'] ) && is_numeric( $decoded['score'] ) ? (float) $decoded['score'] : 0.0;
		if ( $score < $this->threshold ) {
			return new \WP_Error(
				'captcha_failed',
				__( 'CAPTCHA verification failed.', 'neural-forms-lite' ),
				array(
					'provider'  => $this->provider_id(),
					'reason'    => 'low_score',
					'score'     => $score,
					'threshold' => $this->threshold,
				)
			);
		}

		return true;
	}
}
