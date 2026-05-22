<?php
/**
 * Runs when the plugin is deleted via WP Admin -> Plugins -> Delete.
 * NOT called on deactivation.
 *
 * Data-retention contract (added in 0.2.0):
 *
 *   The destructive cleanup (drop tables, delete options, remove the
 *   admin capability, wipe the log directory) is GATED behind a
 *   user-controlled toggle stored on `neural_forms_settings.delete_data_on_uninstall`
 *   — default FALSE. Users who delete the plugin without enabling
 *   that toggle KEEP all their forms, entries, AI context, and
 *   settings. This matches the deactivation-options page UX: data
 *   removal is opt-in, never automatic.
 *
 *   The harmless cleanup (clearing scheduled cron hooks + a couple
 *   of transients) runs unconditionally so reinstalls don't pick up
 *   stale handlers and so we don't leave behind cache rows that
 *   would also be re-populated on reinstall.
 *
 * The cleanup runs inside a function so its locals don't leak into the
 * global namespace - keeps the WordPress.org plugin-check happy about
 * variable prefixing without renaming every working name.
 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! function_exists( 'neural_forms_lite_uninstall' ) ) {
	function neural_forms_lite_uninstall(): void {
		global $wpdb;

		// ── Always-run, harmless cleanup ─────────────────────────────
		// Cron hooks. WordPress does NOT auto-clear hooks on uninstall, so
		// without this a reinstall picks up the old schedule and fires actions
		// whose handlers no longer exist. Safe to run regardless of the
		// data-retention preference — these are runtime artefacts, not user data.
		$hooks = array(
			'neural_forms_log_cleanup',
			'neural_forms_ai_draft_purge',
			'neural_forms_process_ai_queue',
			'neural_forms_process_queue',
		);
		foreach ( $hooks as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}

		// Pro-owned cache transients. Cleared even when keeping data because
		// they're caches keyed off home_url — no value is lost; reinstall
		// repopulates them on first use.
		delete_transient( 'neural_forms_site_profile_cache' );
		delete_transient( 'neural_forms_site_profile_cache_' . md5( (string) home_url( '/' ) ) );

		// ── Data-retention gate ──────────────────────────────────────
		// Read the raw option value here. uninstall.php runs in isolation
		// without our plugin code loaded, so we cannot use Config::get().
		// Default is FALSE — existing installs upgrading to 0.2.0 keep
		// data on delete unless they explicitly opt in via the
		// deactivation-options page or the 'delete plugin data on
		// uninstall' setting.
		$settings = get_option( 'neural_forms_settings', array() );
		$delete   = is_array( $settings ) && ! empty( $settings['delete_data_on_uninstall'] );

		if ( ! $delete ) {
			// User chose to keep data (or never opted in). Stop here.
			// Forms, entries, settings, AI context, log directory — all preserved.
			return;
		}

		// ── Destructive cleanup (only when user opted in) ────────────

		// Capabilities.
		$roles = array( 'administrator' );
		foreach ( $roles as $role_name ) {
			$role = get_role( $role_name );
			if ( $role ) {
				$role->remove_cap( 'neural_forms_manage_forms' );
			}
		}

		// Plugin options.
		delete_option( 'neural_forms_settings' );
		delete_option( 'neural_forms_db_version' );
		delete_option( 'neural_forms_ai_revoke_snapshot' );

		// Pro-owned options. Lite doesn't create these, but cleans them up on
		// uninstall so a user removing both plugins doesn't leave orphan data.
		delete_option( 'neural_forms_site_profile_last_run' );

		// Tables. Keep this list in sync with Database::create_tables() -
		// leftover AI tables collide with dbDelta on reinstall because their
		// columns may differ between versions.
		$tables = array(
			$wpdb->prefix . 'neural_forms_forms',
			$wpdb->prefix . 'neural_forms_entries',
			$wpdb->prefix . 'neural_forms_logs',
			$wpdb->prefix . 'neural_forms_queue',
			$wpdb->prefix . 'neural_forms_entry_messages',
			$wpdb->prefix . 'neural_forms_entry_ai_responses',
		);
		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		}

		// Log directory. Logger writes daily files under the active site's
		// uploads dir at <uploads>/neural-forms/logs/ along with a .htaccess +
		// index stub. Resolve the path via wp_upload_dir() (not WP_CONTENT_DIR)
		// so it works on multisite, with a relocated UPLOADS constant, and on
		// hosts that mount uploads outside wp-content. Bail out silently if
		// the uploads dir is unavailable - leftover files are harmless.
		// phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.WP.AlternativeFunctions.file_system_operations_unlink, WordPress.PHP.NoSilencedErrors.Discouraged
		$uploads = wp_upload_dir( null, false );
		if ( is_array( $uploads ) && empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) ) {
			$parent  = trailingslashit( $uploads['basedir'] ) . 'neural-forms/';
			$log_dir = $parent . 'logs/';
			if ( is_dir( $log_dir ) ) {
				$files = glob( $log_dir . '*' );
				if ( is_array( $files ) ) {
					foreach ( $files as $file ) {
						if ( is_file( $file ) ) {
							@unlink( $file );
						}
					}
				}
				if ( file_exists( $log_dir . '.htaccess' ) ) {
					@unlink( $log_dir . '.htaccess' );
				}
				@rmdir( $log_dir );

				if ( is_dir( $parent ) ) {
					@rmdir( $parent ); // only succeeds if empty
				}
			}
		}
		// phpcs:enable WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.WP.AlternativeFunctions.file_system_operations_unlink, WordPress.PHP.NoSilencedErrors.Discouraged
	}
}

neural_forms_lite_uninstall();
