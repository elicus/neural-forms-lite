<?php
namespace NeuralForms;

defined( 'ABSPATH' ) || exit;

/**
 * Runs once when the plugin is deactivated via the WP plugin screen.
 *
 * Deactivation '�� uninstall. We do NOT drop tables or delete options here.
 * Data removal belongs in uninstall.php only.
 */
class Deactivator {

	public static function deactivate(): void {
		\NeuralForms\AI\AIQueueProcessor::unschedule();
		wp_clear_scheduled_hook( 'neural_forms_log_cleanup' );
		wp_clear_scheduled_hook( 'neural_forms_ai_draft_purge' );
		wp_clear_scheduled_hook( 'neural_forms_follow_up_sweep' );
		wp_clear_scheduled_hook( 'neural_forms_quote_expire' );
		wp_clear_scheduled_hook( \NeuralForms\Rest\Endpoints\SettingsEndpoint::PLAN_REFRESH_HOOK );

		flush_rewrite_rules();
	}
}
