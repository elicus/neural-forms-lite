<?php
namespace NeuralForms;

defined( 'ABSPATH' ) || exit;

/**
 * Runs once when the plugin is deactivated via the WP plugin screen.
 *
 * Deactivation ≠ uninstall. We do NOT drop tables or delete options here.
 * Data removal belongs in uninstall.php only.
 */
class Deactivator {

    public static function deactivate(): void {
        \NeuralForms\AI\AIQueueProcessor::unschedule();
        wp_clear_scheduled_hook( 'neural_forms_log_cleanup' );
        wp_clear_scheduled_hook( 'neural_forms_ai_draft_purge' );

        flush_rewrite_rules();
    }
}
