<?php
namespace NeuralForms\AI;

use NeuralForms\Core\Config;
use NeuralForms\AI\Contracts\AIAnalysisProviderInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Factory that resolves the active AI analysis provider.
 *
 * Reads configuration at call time - never at boot - so settings changes
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

	// Private resolver

	/**
	 * Return a NeuralFormsCloudProvider configured from plugin settings.
	 *
	 * @throws \RuntimeException If cloud_ai_enabled is false or cloud_api_key is empty.
	 */
	private static function resolve_cloud(): Providers\NeuralFormsCloudProvider {
		if ( ! (bool) Config::get( 'cloud_ai_enabled' ) ) {
			throw new \RuntimeException( 'Neural Forms Cloud AI is not enabled.' );
		}

		$key = trim( (string) Config::get( 'cloud_api_key', '' ) ); // cloud hashes exact bytes; a stray space 401s

		if ( $key === '' ) {
			throw new \RuntimeException( 'Neural Forms Cloud API key is not configured.' );
		}

		return new Providers\NeuralFormsCloudProvider( $key, self::resolve_base_url() );
	}

	/**
	 * Resolve the cloud base URL.
	 *
	 * Priority:
	 *   1. NEURAL_FORMS_CLOUD_API_URL PHP constant (wp-config.php override)
	 *   2. neural_forms_cloud_api_url filter        (staging / CI override)
	 *   3. https://api.neuralforms.ai (production default)
	 *
	 * The `cloud_api_base_url` option is intentionally NOT consulted - a
	 * stale local value saved during dev would otherwise route every
	 * request away from production. Use the constant or the filter for
	 * legitimate dev overrides; both are loaded fresh per request.
	 */
	public static function resolve_base_url(): string {
		$base_url = defined( 'NEURAL_FORMS_CLOUD_API_URL' )
			? NEURAL_FORMS_CLOUD_API_URL
			: 'https://api.neuralforms.ai';

		$base_url = (string) apply_filters( 'neural_forms_cloud_api_url', $base_url );

		// Final guard: any value that does not start with http(s) reverts to
		// the production default, so a typo in the constant or a buggy
		// filter never produces a mis-routed request.
		if ( ! preg_match( '#^https?://#', $base_url ) ) {
			$base_url = 'https://api.neuralforms.ai';
		}

		return rtrim( $base_url, '/' );
	}
}
