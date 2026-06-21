<?php
namespace NeuralForms;

defined( 'ABSPATH' ) || exit;

/**
 * Runs once when the plugin is activated via the WP plugin screen.
 */
class Activator {

	public static function activate(): void {
		Database::create_tables();
		self::add_capabilities();
		\NeuralForms\AI\AIQueueProcessor::schedule();

		if ( ! wp_next_scheduled( 'neural_forms_log_cleanup' ) ) {
			wp_schedule_event( time(), 'daily', 'neural_forms_log_cleanup' );
		}

		if ( ! wp_next_scheduled( 'neural_forms_ai_draft_purge' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'neural_forms_ai_draft_purge' );
		}

		flush_rewrite_rules();
	}

	/**
	 * Grant Neural Forms capabilities to the roles that should have them.
	 *
	 * add_cap() writes to the database (wp_options -> wp_user_roles).
	 * It is safe to call multiple times - WordPress skips it if already set.
	 */
	public static function add_capabilities(): void {
		$roles = array( 'administrator' );

		foreach ( $roles as $role_name ) {
			$role = get_role( $role_name );
			if ( $role ) {
				$role->add_cap( NEURAL_FORMS_CAP_MANAGE_FORMS );
			}
		}
	}
}
