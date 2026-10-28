<?php
/**
 * Emails: option labels for choice fields + tokens in the autoresponder subject.
 *
 * Run from the plugin root:
 *
 *   php tests/NotificationLabelsTokensTest.php
 *
 * Drives NotificationService::handle() with wp_mail() captured.
 *
 * Coverage:
 *   1. admin notification shows select / radio LABELS, not values
 *   2. checkboxes show joined labels
 *   3. unknown value (option removed) falls back to the raw value
 *   4. autoresponder subject gets {site_name} / {form_name} / {field} tokens
 *   5. choice-field token in body resolves to the label
 *   6. subject line breaks stripped
 */

declare( strict_types = 1 );

namespace {
	if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', '/tmp/abspath' );
	defined( 'ABSPATH' ) || exit;
	// phpcs:disable WordPress.Security.EscapeOutput,WordPress.Security.ValidatedSanitizedInput,WordPress.Security.NonceVerification -- CLI-only test runner.

	$GLOBALS['__nf_global'] = [];
	$GLOBALS['__nf_mail']   = [];
	$GLOBALS['__nf_entry']  = [];

	function __( $s, $d = null ) { return $s; }
	function esc_html__( $s, $d = null ) { return $s; }
	function wp_strip_all_tags( $s ) { return preg_replace( '/<[^>]*>/', '', (string) $s ); }
	function sanitize_text_field( $v ) { $v = is_string( $v ) ? $v : ''; return trim( preg_replace( '/\s+/', ' ', preg_replace( '/[\r\n\t]+/', ' ', $v ) ) ); }
	function sanitize_email( $s ) { return is_string( $s ) ? trim( $s ) : ''; }
	function is_email( $s ) { return is_string( $s ) && (bool) preg_match( '/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $s ); }
	function get_option( $k, $d = false ) { return $k === 'admin_email' ? 'admin@site.test' : $d; }
	function get_bloginfo( $k = '' ) { return 'Fixit'; }
	function wp_specialchars_decode( $s, $q = 0 ) { return $s; }
	function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
	function admin_url( $p = '' ) { return 'https://site.test/wp-admin/' . $p; }
	function apply_filters( $hook, $value, ...$args ) { return $value; }
	function do_action( ...$a ) {}
	function wp_mail( $to, $subject, $message, $headers = [], $attachments = [] ) {
		$GLOBALS['__nf_mail'][] = [ 'to' => $to, 'subject' => $subject, 'body' => $message ];
		return true;
	}

	eval( 'namespace NeuralForms\\Core { class Config { public static function all(): array { return $GLOBALS["__nf_global"]; } public static function get( $k, $d = null ) { return $GLOBALS["__nf_global"][ $k ] ?? $d; } } class Logger { public static function log( ...$a ) {} public static function file( ...$a ) {} } }' );
	eval( 'namespace NeuralForms\\Storage { class EntryRepository { public static function get( $id ) { return $GLOBALS["__nf_entry"]; } } }' );
	eval( 'namespace NeuralForms\\Forms { class SchemaHelper { public static function flatten_fields( array $fields ): array { return $fields; } } }' );

	$plugin_root = dirname( __DIR__ );
	require_once $plugin_root . '/modules/notifications/NotificationService.php';
	use NeuralForms\Notifications\NotificationService;

	$passed = 0; $failed = 0;
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

	$fields = [
		[ 'id' => 'name',   'type' => 'text',       'label' => 'Name' ],
		[ 'id' => 'email',  'type' => 'email',      'label' => 'Email' ],
		[ 'id' => 'device', 'type' => 'select',     'label' => 'Device', 'options' => [ [ 'label' => 'Laptop', 'value' => 'laptop' ], [ 'label' => 'Phone', 'value' => 'phone' ] ] ],
		[ 'id' => 'urgency','type' => 'radio',      'label' => 'Urgency', 'options' => [ [ 'label' => 'Same day', 'value' => 'same_day' ], [ 'label' => 'This week', 'value' => 'week' ] ] ],
		[ 'id' => 'extras', 'type' => 'checkboxes', 'label' => 'Extras', 'options' => [ [ 'label' => 'Pick-up', 'value' => 'pickup' ], [ 'label' => 'Loaner', 'value' => 'loaner' ], [ 'label' => 'Backup', 'value' => 'backup' ] ] ],
	];
	$form = [ 'id' => 1, 'name' => 'Repair Request', 'form_schema' => [ 'fields' => $fields ], 'settings' => [] ];
	$run = function ( array $data, array $global ) use ( $form ) {
		$GLOBALS['__nf_global'] = array_merge( [ 'enable_admin_notification' => true, 'enable_autoresponder' => false ], $global );
		$GLOBALS['__nf_entry']  = [ 'id' => 7, 'form_id' => 1, 'data' => $data ];
		$GLOBALS['__nf_mail']   = [];
		NotificationService::handle( 7, 1, $form );
		$admin = array_values( array_filter( $GLOBALS['__nf_mail'], fn( $m ) => strpos( (string) $m['subject'], 'New submission' ) === 0 ) );
		$auto  = array_values( array_filter( $GLOBALS['__nf_mail'], fn( $m ) => strpos( (string) $m['subject'], 'New submission' ) !== 0 ) );
		return [ 'admin' => $admin[0] ?? null, 'auto' => $auto[0] ?? null ];
	};
	$data = [ 'name' => 'Omar', 'email' => 'omar@example.com', 'device' => 'laptop', 'urgency' => 'same_day', 'extras' => [ 'pickup', 'backup' ] ];

	// 1-2. admin notification labels
	$r = $run( $data, [] );
	check( '1a. select shows label',                   strpos( $r['admin']['body'], 'Device: Laptop' ) !== false, true, $passed, $failed );
	check( '1b. raw select value absent',              strpos( $r['admin']['body'], 'laptop' ) === false, true, $passed, $failed );
	check( '1c. radio shows label',                    strpos( $r['admin']['body'], 'Urgency: Same day' ) !== false, true, $passed, $failed );
	check( '2. checkboxes show joined labels',         strpos( $r['admin']['body'], 'Extras: Pick-up, Backup' ) !== false, true, $passed, $failed );

	// 3. unknown value falls back
	$r = $run( array_merge( $data, [ 'device' => 'tablet' ] ), [] );
	check( '3. removed option → raw value shown',       strpos( $r['admin']['body'], 'Device: tablet' ) !== false, true, $passed, $failed );

	// 4-5. autoresponder subject + body tokens
	$r = $run( $data, [ 'enable_autoresponder' => true, 'autoresponder_subject' => 'Thanks {name} — your {device} request at {site_name}', 'autoresponder_body' => "Hi {name}, we got your {form_name}. Device: {device}. Extras: {extras}.\n{site_name}" ] );
	check( '4a. autoresponder sent to submitter',       $r['auto']['to'] ?? null, 'omar@example.com', $passed, $failed );
	check( '4b. subject tokens resolved (label for select)', $r['auto']['subject'] ?? null, 'Thanks Omar — your Laptop request at Fixit', $passed, $failed );
	check( '5a. body select token → label',            strpos( $r['auto']['body'], 'Device: Laptop.' ) !== false, true, $passed, $failed );
	check( '5b. body checkboxes token → joined labels', strpos( $r['auto']['body'], 'Extras: Pick-up, Backup.' ) !== false, true, $passed, $failed );
	check( '5c. body form_name token',                 strpos( $r['auto']['body'], 'we got your Repair Request.' ) !== false, true, $passed, $failed );

	// 6. subject header safety
	$r = $run( array_merge( $data, [ 'name' => "Omar\r\nBcc: x@y.z" ] ), [ 'enable_autoresponder' => true, 'autoresponder_subject' => 'Thanks {name}' ] );
	check( '6. subject has no line breaks',            strpos( (string) $r['auto']['subject'], "\n" ) === false && strpos( (string) $r['auto']['subject'], "\r" ) === false, true, $passed, $failed );

	echo "\n{$passed} passed, {$failed} failed\n";
	exit( $failed === 0 ? 0 : 1 );
}
