<?php
/**
 * Runs when the plugin is deleted via WP Admin → Plugins → Delete.
 * NOT called on deactivation.
 *
 * Removes all plugin data: capabilities, options, and DB tables.
 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// --- Capabilities -----------------------------------------------------------

$roles_with_cap = [ 'administrator' ];

foreach ( $roles_with_cap as $role_name ) {
    $role = get_role( $role_name );
    if ( $role ) {
        $role->remove_cap( 'neural_forms_manage_forms' );
    }
}

// --- Options ----------------------------------------------------------------

delete_option( 'neural_forms_settings' );
delete_option( 'neural_forms_db_version' );
delete_option( 'neural_forms_ai_revoke_snapshot' );

// Pro-owned options. Lite doesn't create these, but cleans them up on
// uninstall so a user removing both plugins doesn't leave orphan data.
// (Site profile is a Pro-owned feature — see Pro CLAUDE.md for the
// documented exception to the "Lite owns engine" rule.) Site-profile
// cache is keyed per-site as `neural_forms_site_profile_cache_{md5(home_url)}`
// — also clear the legacy unkeyed name from older Pro versions.
delete_option( 'neural_forms_site_profile_last_run' );
delete_transient( 'neural_forms_site_profile_cache' );
delete_transient( 'neural_forms_site_profile_cache_' . md5( (string) home_url( '/' ) ) );

// --- WP-Cron events ---------------------------------------------------------
//
// Clear every cron hook this plugin schedules. WordPress does NOT auto-clear
// hooks on uninstall, so without this a reinstall picks up the old schedule
// and fires actions whose handlers no longer exist (silent no-op now, fatal
// later if a handler is added back with a different signature).

$cron_hooks = [
    'neural_forms_log_cleanup',
    'neural_forms_ai_draft_purge',
    'neural_forms_process_ai_queue',
    'neural_forms_process_queue',
];

foreach ( $cron_hooks as $hook ) {
    wp_clear_scheduled_hook( $hook );
}

// --- Tables -----------------------------------------------------------------
//
// Drop every table this plugin creates in includes/class-database.php. Keep
// this list in sync with Database::create_tables() — leftover AI tables
// (entry_messages, entry_ai_responses) collide with dbDelta on reinstall
// because their columns may differ between versions.

global $wpdb;

$tables = [
    $wpdb->prefix . 'neural_forms_forms',
    $wpdb->prefix . 'neural_forms_entries',
    $wpdb->prefix . 'neural_forms_logs',
    $wpdb->prefix . 'neural_forms_queue',
    $wpdb->prefix . 'neural_forms_entry_messages',
    $wpdb->prefix . 'neural_forms_entry_ai_responses',
];

foreach ( $tables as $table ) {
    $wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore
}

// --- Log directory ----------------------------------------------------------
//
// Logger writes daily files to wp-content/uploads/neural-forms/logs/ along
// with a .htaccess and index.php silence stub. Remove the whole directory
// so a reinstall starts clean and doesn't carry over old log files from a
// previous install.

$log_dir = WP_CONTENT_DIR . '/uploads/neural-forms/logs/';
if ( is_dir( $log_dir ) ) {
    $files = glob( $log_dir . '*' );
    if ( is_array( $files ) ) {
        foreach ( $files as $file ) {
            if ( is_file( $file ) ) {
                @unlink( $file ); // phpcs:ignore
            }
        }
    }
    // Also remove dotfiles (.htaccess) that glob('*') won't pick up.
    if ( file_exists( $log_dir . '.htaccess' ) ) {
        @unlink( $log_dir . '.htaccess' ); // phpcs:ignore
    }
    @rmdir( $log_dir ); // phpcs:ignore

    $parent = WP_CONTENT_DIR . '/uploads/neural-forms/';
    if ( is_dir( $parent ) ) {
        @rmdir( $parent ); // phpcs:ignore  (only succeeds if empty)
    }
}
