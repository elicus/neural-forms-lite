<?php
namespace NeuralForms\AI;

use NeuralForms\Core\Config;
use NeuralForms\AI\Contracts\AIAnalysisProviderInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Factory that resolves the active AI analysis provider.
 *
 * Reads configuration at call time — never at boot — so settings changes
 * take effect on the next queue run without a plugin reload.
 *
 * Neural Forms uses Neural Forms Cloud as its sole AI backend.
 * Requires cloud_ai_enabled = true and a non-empty cloud_api_key.
 */
class AiManager {

	/**
	 * Resolve and return the active provider.
	 *
	 * @return AIAnalysisProviderInterface
	 * @throws \RuntimeException If cloud_ai_enabled is false or cloud_api_key is empty.
	 */
	public static function resolve(): AIAnalysisProviderInterface {
		return self::resolve_cloud();
	}

	// -------------------------------------------------------------------------
	// Private resolver
	// -------------------------------------------------------------------------

	/**
	 * Return a NeuralFormsCloudProvider configured from plugin settings.
	 *
	 * @throws \RuntimeException If cloud_ai_enabled is false or cloud_api_key is empty.
	 */
	private static function resolve_cloud(): Providers\NeuralFormsCloudProvider {
		if ( ! (bool) Config::get( 'cloud_ai_enabled' ) ) {
			throw new \RuntimeException( 'Neural Forms Cloud AI is not enabled.' );
		}

		$key = (string) Config::get( 'cloud_api_key', '' );

		if ( $key === '' ) {
			throw new \RuntimeException( 'Neural Forms Cloud API key is not configured.' );
		}

		// Base URL resolution order:
		//   1. NEURAL_FORMS_CLOUD_API_URL PHP constant  (wp-config.php override)
		//   2. neural_forms_cloud_api_url filter         (staging/CI override)
		//   3. Stored config value                       (set via install defaults)
		$base_url = defined( 'NEURAL_FORMS_CLOUD_API_URL' )
			? NEURAL_FORMS_CLOUD_API_URL
			: (string) Config::get( 'cloud_api_base_url', 'https://api.neuralforms.ai' );

		$base_url = (string) apply_filters( 'neural_forms_cloud_api_url', $base_url );

		return new Providers\NeuralFormsCloudProvider( $key, $base_url );
	}
}
