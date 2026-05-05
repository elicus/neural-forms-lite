<?php
/**
 * Reply-To header tests for admin notifications.
 *
 * Run from the plugin root:
 *
 *   php tests/AdminReplyToHeaderTest.php
 *
 * Tests two private helpers in NotificationService via Reflection so
 * we don't have to stand up the full wp_mail / EntryRepository /
 * Logger pipeline:
 *
 *   find_submitter_name( $schema_fields, $data )
 *   build_reply_to_header( $email, $name )
 *
 * The dispatch logic (which header gets appended in which scenario)
 * is mirrored by the test fixtures below — change the resolution
 * order in NotificationService and the corresponding test must move.
 */

declare( strict_types = 1 );

namespace {
	if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', '/tmp/abspath' );

	// Minimal WP stubs the helpers transitively need.
	if ( ! function_exists( '__' ) )                  { function __( $s, $domain = null ) { return $s; } }
	if ( ! function_exists( 'wp_strip_all_tags' ) )   { function wp_strip_all_tags( $s ) { return preg_replace( '/<[^>]*>/', '', (string) $s ); } }
	if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $v ) { $v = is_string( $v ) ? $v : ''; $v = preg_replace( '/[\r\n\t]+/', ' ', $v ); return trim( preg_replace( '/\s+/', ' ', $v ) ); } }
	if ( ! function_exists( 'sanitize_email' ) )      { function sanitize_email( $s ) { return is_string( $s ) ? trim( $s ) : ''; } }
	if ( ! function_exists( 'is_email' ) )            { function is_email( $s ) { return is_string( $s ) && (bool) preg_match( '/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $s ); } }

	// Logger / Config / EntryRepository / SchemaHelper aren't reached by
	// the helpers under test, but NotificationService.php declares
	// `use` statements — autoload them lazily by stubbing the namespaces
	// before requiring the file.

	$plugin_root = dirname( __DIR__ );

	// NotificationService imports several classes via use statements.
	// Stub them so the require_once succeeds without their concrete
	// implementations needing WP runtime to work.
	if ( ! class_exists( 'NeuralForms\\Core\\Config' ) ) {
		eval( 'namespace NeuralForms\\Core { class Config { public static function all(): array { return []; } public static function get( $k, $d = null ) { return $d; } } class Logger { public static function log( ...$a ) {} public static function file( ...$a ) {} } }' );
	}
	if ( ! class_exists( 'NeuralForms\\Storage\\EntryRepository' ) ) {
		eval( 'namespace NeuralForms\\Storage { class EntryRepository { public static function get( $id ) { return null; } } }' );
	}
	if ( ! class_exists( 'NeuralForms\\Forms\\SchemaHelper' ) ) {
		eval( 'namespace NeuralForms\\Forms { class SchemaHelper { public static function flatten_fields( array $fields ): array { return $fields; } } }' );
	}

	require_once $plugin_root . '/modules/notifications/NotificationService.php';

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

	function call_private( string $method, array $args ) {
		$ref = new \ReflectionMethod( 'NeuralForms\\Notifications\\NotificationService', $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( null, $args );
	}

	function f( string $id, string $type, string $label = '' ): array {
		return [ 'id' => $id, 'type' => $type, 'label' => $label ];
	}

	// ─── find_submitter_name ──────────────────────────────────────────

	check( '1a name from id="name"',
		call_private( 'find_submitter_name', [
			[ f( 'name', 'text', 'Your Name' ), f( 'email', 'email', 'Email' ) ],
			[ 'name' => 'John Doe', 'email' => 'john@example.com' ],
		] ),
		'John Doe',
		$passed, $failed );

	check( '1b name from id="full_name"',
		call_private( 'find_submitter_name', [
			[ f( 'full_name', 'text', '' ), f( 'email', 'email', '' ) ],
			[ 'full_name' => 'Ada Lovelace', 'email' => 'ada@example.com' ],
		] ),
		'Ada Lovelace',
		$passed, $failed );

	check( '1c name from label "Your Name" when id is opaque',
		call_private( 'find_submitter_name', [
			[ f( 'q1', 'text', 'Your Name' ), f( 'q2', 'email', 'Email' ) ],
			[ 'q1' => 'Grace Hopper', 'q2' => 'g@example.com' ],
		] ),
		'Grace Hopper',
		$passed, $failed );

	check( '1d empty name when no candidates',
		call_private( 'find_submitter_name', [
			[ f( 'subject', 'text', 'Subject' ), f( 'email', 'email', 'Email' ) ],
			[ 'email' => 'x@example.com' ],
		] ),
		'',
		$passed, $failed );

	check( '1e CRLF in name is stripped',
		call_private( 'find_submitter_name', [
			[ f( 'name', 'text', 'Name' ) ],
			[ 'name' => "Evil\r\nBcc: attacker@example.com\r\nName" ],
		] ),
		'Evil Bcc: attacker@example.com Name',
		$passed, $failed );

	// wp_strip_all_tags treats <…> as a tag, so the address inside
	// the angle brackets is removed BEFORE our manual `,<>"` strip
	// runs. The remaining structural characters are still removed
	// by our defense-in-depth pass — net effect: hostile names
	// can't carry an alternate address through to the header.
	check( '1f angle brackets and quotes in name are stripped',
		call_private( 'find_submitter_name', [
			[ f( 'name', 'text', 'Name' ) ],
			[ 'name' => 'Evil <evil@example.com>, "fake"' ],
		] ),
		'Evil  fake',
		$passed, $failed );

	check( '1g HTML in name is stripped',
		call_private( 'find_submitter_name', [
			[ f( 'name', 'text', 'Name' ) ],
			[ 'name' => '<b>Bob</b> Smith' ],
		] ),
		'Bob Smith',
		$passed, $failed );

	check( '1h name truncated at 120 chars',
		strlen( call_private( 'find_submitter_name', [
			[ f( 'name', 'text', 'Name' ) ],
			[ 'name' => str_repeat( 'a', 200 ) ],
		] ) ),
		120,
		$passed, $failed );

	// ─── build_reply_to_header ───────────────────────────────────────

	check( '2a Reply-To with name+email',
		call_private( 'build_reply_to_header', [ 'john@example.com', 'John Doe' ] ),
		'Reply-To: John Doe <john@example.com>',
		$passed, $failed );

	check( '2b Reply-To with bare email when name empty',
		call_private( 'build_reply_to_header', [ 'john@example.com', '' ] ),
		'Reply-To: john@example.com',
		$passed, $failed );

	check( '2c invalid email returns empty (no Reply-To)',
		call_private( 'build_reply_to_header', [ 'not-an-email', 'John' ] ),
		'',
		$passed, $failed );

	check( '2d empty email returns empty',
		call_private( 'build_reply_to_header', [ '', 'John' ] ),
		'',
		$passed, $failed );

	// CRLF injection in email: helper rejects the whole header
	// (returns '') because once \r\n is stripped, the residue still
	// fails is_email() — the collapsed value contains 'Bcc:' and
	// spaces. The defensive contract is "no header rather than a
	// dangerous one".
	check( '2e CRLF injection in email yields no header',
		call_private( 'build_reply_to_header', [
			"john@example.com\r\nBcc: attacker@example.com",
			'John Doe',
		] ),
		'',
		$passed, $failed );

	check( '2f CRLF injection in name is stripped to single line',
		call_private( 'build_reply_to_header', [
			'john@example.com',
			"John\r\nBcc: attacker@example.com",
		] ),
		'Reply-To: JohnBcc: attacker@example.com <john@example.com>',
		$passed, $failed );
	// (After CRLF strip the residual text is glued together — there
	// is no newline so the resulting header is one line, which is
	// what wp_mail's header parser requires.)

	echo "\n$passed passed, $failed failed\n";
	exit( $failed > 0 ? 1 : 0 );
}
