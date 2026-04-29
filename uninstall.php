<?php
/**
 * Runs when the plugin is deleted via WP Admin -> Plugins -> Delete.
 * NOT called on deactivation.
 *
 * Removes all plugin data: capabilities, options, and DB tables.
 *
 * The cleanup runs inside a function so its locals don't leak into the
 * global namespace - keeps the WordPress.org plugin-check happy about
 * variable prefixing without renaming every working name.
 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! function_exists( 'neural_forms_lite_uninstall' ) ) {
	function neural_forms_lite_uninstall(): void {
		global $wpdb;

		// Capabilities.
		$roles = [ 'administrator' ];
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
		// Site-profile cache is keyed per-site as
		// `neural_forms_site_profile_cache_{md5(home_url)}` - also clear the
		// legacy unkeyed name from older Pro versions.
		delete_option( 'neural_forms_site_profile_last_run' );
		delete_transient( 'neural_forms_site_profile_cache' );
		delete_transient( 'neural_forms_site_profile_cache_' . md5( (string) home_url( '/' ) ) );

		// Cron hooks. WordPress does NOT auto-clear hooks on uninstall, so
		// without this a reinstall picks up the old schedule and fires actions
		// whose handlers no longer exist.
		$hooks = [
			'neural_forms_log_cleanup',
			'neural_forms_ai_draft_purge',
			'neural_forms_process_ai_queue',
			'neural_forms_process_queue',
		];
		foreach ( $hooks as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}

		// Tables. Keep this list in sync with Database::create_tables() -
		// leftover AI tables collide with dbDelta on reinstall because their
		// columns may differ between versions.
		$tables = [
			$wpdb->prefix . 'neural_forms_forms',
			$wpdb->prefix . 'neural_forms_entries',
			$wpdb->prefix . 'neural_forms_logs',
			$wpdb->prefix . 'neural_forms_queue',
			$wpdb->prefix . 'neural_forms_entry_messages',
			$wpdb->prefix . 'neural_forms_entry_ai_responses',
		];
		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		}

		// Log directory. Logger writes daily files to
		// wp-content/uploads/neural-forms/logs/ along with a .htaccess + index
		// stub. Remove the whole directory so a reinstall starts clean.
		// phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.WP.AlternativeFunctions.file_system_operations_unlink, WordPress.PHP.NoSilencedErrors.Discouraged
		$log_dir = WP_CONTENT_DIR . '/uploads/neural-forms/logs/';
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

			$parent = WP_CONTENT_DIR . '/uploads/neural-forms/';
			if ( is_dir( $parent ) ) {
				@rmdir( $parent ); // only succeeds if empty
			}
		}
		// phpcs:enable WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.WP.AlternativeFunctions.file_system_operations_unlink, WordPress.PHP.NoSilencedErrors.Discouraged
	}
}

neural_forms_lite_uninstall();
