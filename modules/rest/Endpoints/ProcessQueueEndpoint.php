<?php
namespace NeuralForms\Rest\Endpoints;

use NeuralForms\AI\AIQueueProcessor;
use NeuralForms\Core\Config;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * POST /neural-forms/v1/ai/process-queue
 *
 * Triggers an immediate synchronous run of the AI queue processor.
 * Intended for local/dev environments where WP-Cron does not fire
 * automatically. Admin-only (manage_options).
 *
 * Returns: { processed: true, ai_enabled: bool, has_key: bool }
 *
 * Error codes:
 *   ai_not_configured  400  AI is disabled or no API key is set.
 *   permission_denied  403  Caller lacks manage_options capability.
 */
class ProcessQueueEndpoint {

	public function register_routes(): void {
		register_rest_route( \NeuralForms\Rest\RestAPI::NAMESPACE, '/ai/process-queue', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle' ],
			'permission_callback' => [ $this, 'check_permission' ],
		] );
	}

	public function handle() {
		$ai_enabled  = (bool) Config::get( 'ai_enabled'       );
		$cloud_on    = (bool) Config::get( 'cloud_ai_enabled'  );
		$has_key     = Config::get( 'cloud_api_key' ) !== '';

		if ( ! $ai_enabled || ! $cloud_on || ! $has_key ) {
			return new WP_Error(
				'ai_not_configured',
				__( 'AI is not enabled or no API key is configured.', 'neural-forms' ),
				[ 'status' => 400, 'ai_enabled' => $ai_enabled, 'cloud_on' => $cloud_on, 'has_key' => $has_key ]
			);
		}

		AIQueueProcessor::run();

		return rest_ensure_response( [ 'processed' => true ] );
	}

	public function check_permission() {
		if ( current_user_can( NEURAL_FORMS_CAP_MANAGE_FORMS ) ) {
			return true;
		}

		return new WP_Error(
			'permission_denied',
			__( 'You do not have permission to trigger AI processing.', 'neural-forms' ),
			[ 'status' => 403 ]
		);
	}
}
