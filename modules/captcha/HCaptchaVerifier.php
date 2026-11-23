<?php
namespace NeuralForms\Captcha;

defined( 'ABSPATH' ) || exit;

/**
 * HCaptchaVerifier
 *
 * Verifies hCaptcha tokens through the siteverify API. The site key is
 * sent too, so a token issued for another site key is rejected.
 *
 * @see https://docs.hcaptcha.com/#verify-the-user-response-server-side
 */
class HCaptchaVerifier extends SiteverifyVerifier {

	/** @var string hCaptcha site key (public). */
	private string $site_key;

	/**
	 * @param string $secret_key  hCaptcha secret key.
	 * @param string $site_key    hCaptcha site key.
	 */
	public function __construct( string $secret_key, string $site_key = '' ) {
		parent::__construct( $secret_key );
		$this->site_key = $site_key;
	}

	protected function verify_url(): string {
		return 'https://api.hcaptcha.com/siteverify';
	}

	protected function provider_id(): string {
		return 'hcaptcha';
	}

	protected function extra_body(): array {
		return $this->site_key !== '' ? array( 'sitekey' => $this->site_key ) : array();
	}
}
