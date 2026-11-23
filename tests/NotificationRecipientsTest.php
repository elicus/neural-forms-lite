<?php
/**
 * Per-form admin-notification recipient + enable tests.
 *
 * Run from the plugin root:
 *
 *   php tests/NotificationRecipientsTest.php
 *
 * Drives NotificationService::handle() end-to-end with wp_mail() stubbed
 * to capture recipients. The builder saves the per-form address and
 * toggle under form.settings; before 1.1.3 neither was read.
 *
 * Coverage:
 *   1. form.settings.admin_notification_email wins over global
 *   2. comma list on the form → every address
 *   3. no per-form address → global Notify address
 *   4. nothing configured → WordPress admin email
 *   5. legacy form_schema.settings key still honoured
 *   6. per-form toggle false silences the form despite global on
 *   7. per-form toggle true sends despite global off
 *   8. toggle unset → inherits global (on and off)
 */

declare( strict_types = 1 );

namespace {
	if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', '/tmp/abspath' );
	defined( 'ABSPATH' ) || exit;
	// phpcs:disable WordPress.Security.EscapeOutput,WordPress.Security.ValidatedSanitizedInput,WordPress.Security.NonceVerification -- CLI-only test runner.

	$GLOBALS['__nf_global'] = [];
	$GLOBALS['__nf_mail']   = [];

	function __( $s, $d = null ) { return $s; }
	function esc_html__( $s, $d = null ) { return $s; }
	function wp_strip_all_tags( $s ) { return preg_replace( '/<[^>]*>/', '', (string) $s ); }
	function sanitize_text_field( $v ) { $v = is_string( $v ) ? $v : ''; return trim( preg_replace( '/\s+/', ' ', preg_replace( '/[\r\n\t]+/', ' ', $v ) ) ); }
	function sanitize_email( $s ) { return is_string( $s ) ? trim( $s ) : ''; }
	function is_email( $s ) { return is_string( $s ) && (bool) preg_match( '/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $s ); }
	function get_option( $k, $d = false ) { return $k === 'admin_email' ? 'admin@site.test' : $d; }
	function get_bloginfo( $k = '' ) { return 'Site'; }
	function wp_specialchars_decode( $s, $q = 0 ) { return $s; }
	function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
	function admin_url( $p = '' ) { return 'https://site.test/wp-admin/' . $p; }
	function apply_filters( $hook, $value, ...$args ) { return $value; }
	function do_action( ...$a ) {}
	function wp_kses_post( $s ) { return $s; }
	function esc_html( $s ) { return $s; }
	function esc_url( $s ) { return $s; }
	function current_time( $t, $gmt = 0 ) { return '2026-10-27 00:00:00'; }
	function wp_date( $f, $t = null ) { return '2026-10-27'; }
	function date_i18n( $f, $t = null ) { return '2026-10-27'; }
	function get_date_from_gmt( $s, $f = 'Y-m-d H:i:s' ) { return $s; }
	function wp_mail( $to, $subject, $message, $headers = [], $attachments = [] ) {
		$GLOBALS['__nf_mail'][] = [ 'to' => $to, 'subject' => $subject, 'headers' => $headers ];
		return true;
	}

	eval( 'namespace NeuralForms\\Core { class Config { public static function all(): array { return $GLOBALS["__nf_global"]; } public static function get( $k, $d = null ) { return $GLOBALS["__nf_global"][ $k ] ?? $d; } } class Logger { public static function log( ...$a ) {} public static function file( ...$a ) {} } }' );
	eval( 'namespace NeuralForms\\Storage { class EntryRepository { public static function get( $id ) { return [ "id" => $id, "form_id" => 1, "data" => [ "email" => "visitor@example.com", "name" => "Vis Itor" ], "submitted_at" => "2026-10-27 00:00:00" ]; } } }' );
	eval( 'namespace NeuralForms\\Forms { class SchemaHelper { public static function flatten_fields( array $fields ): array { return $fields; } } }' );

	$plugin_root = dirname( __DIR__ );
	require_once $plugin_root . '/modules/notifications/NotificationService.php';

	use NeuralForms\Notifications\NotificationService;

	$passed = 0;
	$failed = 0;
	function check( string $label, $actual, $expected, int &$passed, int &$failed ): void {
		$ok = $actual === $expected;
		if ( $ok ) $passed++; else $failed++;
		$indicator = $ok ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m";
		echo $indicator . '  ' . $label . "\n";
		if ( ! $ok ) {
			echo "      expected: " . var_export( $expected, true ) . "\n";
			echo "      actual:   " . var_export( $actual, true ) . "\n";
		}
	}

	$fields = [ [ 'id' => 'name', 'type' => 'text', 'label' => 'Name' ], [ 'id' => 'email', 'type' => 'email', 'label' => 'Email' ] ];
	$form = function ( array $settings = [], array $schema_settings = [] ) use ( $fields ): array {
		$schema = [ 'fields' => $fields ];
		if ( $schema_settings ) $schema['settings'] = $schema_settings;
		return [ 'id' => 1, 'name' => 'Contact', 'form_schema' => $schema, 'settings' => $settings ];
	};
	// Only the admin-notification subject is interesting; autoresponder stays off.
	$run = function ( array $form_row, array $global ) {
		$GLOBALS['__nf_global'] = array_merge( [ 'enable_admin_notification' => true, 'enable_autoresponder' => false, 'admin_notification_email' => '' ], $global );
		$GLOBALS['__nf_mail']   = [];
		NotificationService::handle( 7, 1, $form_row );
		$admin = array_values( array_filter( $GLOBALS['__nf_mail'], fn( $m ) => strpos( (string) $m['subject'], 'New submission' ) === 0 ) );
		return $admin ? $admin[0]['to'] : null;
	};

	check( '1. per-form address wins over global',
		$run( $form( [ 'admin_notification_email' => 'sales@form.test' ] ), [ 'admin_notification_email' => 'global@site.test' ] ),
		[ 'sales@form.test' ], $passed, $failed );

	check( '2. per-form comma list → every address',
		$run( $form( [ 'admin_notification_email' => 'a@form.test, b@form.test' ] ), [] ),
		[ 'a@form.test', 'b@form.test' ], $passed, $failed );

	check( '3. no per-form address → global Notify address',
		$run( $form( [ 'admin_notification_email' => '' ] ), [ 'admin_notification_email' => 'global@site.test' ] ),
		[ 'global@site.test' ], $passed, $failed );

	check( '4. nothing configured → WordPress admin email',
		$run( $form(), [] ),
		[ 'admin@site.test' ], $passed, $failed );

	check( '5. legacy form_schema.settings key still honoured',
		$run( $form( [], [ 'admin_notification_email' => 'legacy@form.test' ] ), [ 'admin_notification_email' => 'global@site.test' ] ),
		[ 'legacy@form.test' ], $passed, $failed );

	check( '5b. invalid per-form address falls through to global',
		$run( $form( [ 'admin_notification_email' => 'nope' ] ), [ 'admin_notification_email' => 'global@site.test' ] ),
		[ 'global@site.test' ], $passed, $failed );

	check( '6. per-form toggle false silences despite global on',
		$run( $form( [ 'enable_admin_notification' => false, 'admin_notification_email' => 'sales@form.test' ] ), [ 'enable_admin_notification' => true ] ),
		null, $passed, $failed );

	check( '7. per-form toggle true sends despite global off',
		$run( $form( [ 'enable_admin_notification' => true ] ), [ 'enable_admin_notification' => false, 'admin_notification_email' => 'global@site.test' ] ),
		[ 'global@site.test' ], $passed, $failed );

	check( '8a. toggle unset inherits global on',
		$run( $form(), [ 'enable_admin_notification' => true ] ),
		[ 'admin@site.test' ], $passed, $failed );
	check( '8b. toggle unset inherits global off',
		$run( $form(), [ 'enable_admin_notification' => false ] ),
		null, $passed, $failed );

	check( '9. admin_notification_enabled(): explicit false beats global true',
		NotificationService::admin_notification_enabled( [ 'settings' => [ 'enable_admin_notification' => false ] ], [ 'enable_admin_notification' => true ] ), false, $passed, $failed );
	check( '9b. admin_notification_enabled(): non-array form settings → global',
		NotificationService::admin_notification_enabled( [ 'settings' => 'junk' ], [ 'enable_admin_notification' => true ] ), true, $passed, $failed );

	echo "\n{$passed} passed, {$failed} failed\n";
	exit( $failed === 0 ? 0 : 1 );
}
