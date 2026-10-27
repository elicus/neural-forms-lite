<?php
/**
 * Inbox reply: sender headers + default subject.
 *
 * Run from the plugin root:
 *
 *   php tests/ReplyEndpointTest.php
 *
 * Drives ReplyEndpoint::send_reply() with wp_mail() captured.
 *
 * Coverage:
 *   1. headers: Content-Type + From (configured sender) + Reply-To
 *   2. blank sender settings → From/Reply-To from site title + admin email
 *   3. no subject → reply_subject_pattern with {site_name} / {form_name}
 *   4. explicit subject wins
 *   5. blank pattern → "Re: Your submission"
 *   6. stored outgoing message records the real sender address
 *   7. default_subject() strips CR/LF
 */

declare( strict_types = 1 );

namespace {
	if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', '/tmp/abspath' );
	defined( 'ABSPATH' ) || exit;
	// phpcs:disable WordPress.Security.EscapeOutput,WordPress.Security.ValidatedSanitizedInput,WordPress.Security.NonceVerification -- CLI-only test runner.

	$GLOBALS['__nf_global']   = [];
	$GLOBALS['__nf_mail']     = [];
	$GLOBALS['__nf_messages'] = [];

	function __( $s, $d = null ) { return $s; }
	function is_email( $s ) { return is_string( $s ) && (bool) preg_match( '/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $s ); }
	function sanitize_email( $s ) { return is_string( $s ) ? trim( $s ) : ''; }
	function sanitize_text_field( $v ) { $v = is_string( $v ) ? $v : ''; return trim( preg_replace( '/\s+/', ' ', preg_replace( '/[\r\n\t]+/', ' ', $v ) ) ); }
	function sanitize_textarea_field( $v ) { return is_string( $v ) ? trim( $v ) : ''; }
	function wp_strip_all_tags( $s ) { return preg_replace( '/<[^>]*>/', '', (string) $s ); }
	function get_option( $k, $d = false ) { return $k === 'admin_email' ? 'admin@fixit.test' : $d; }
	function get_bloginfo( $k = '' ) { return $k === 'admin_email' ? 'admin@fixit.test' : 'Fixit &amp; Co'; }
	function wp_specialchars_decode( $s, $q = 0 ) { return html_entity_decode( (string) $s, ENT_QUOTES, 'UTF-8' ); }
	function rest_ensure_response( $r ) { return $r; }
	function is_wp_error( $t ) { return $t instanceof \WP_Error; }
	function wp_mail( $to, $subject, $message, $headers = [], $attachments = [] ) {
		$GLOBALS['__nf_mail'][] = compact( 'to', 'subject', 'message', 'headers' );
		return true;
	}
	class WP_Error {
		public $code, $message, $data;
		public function __construct( $c = '', $m = '', $d = [] ) { $this->code = $c; $this->message = $m; $this->data = $d; }
		public function get_error_code() { return $this->code; } public function get_error_message() { return $this->message; } public function get_error_data() { return $this->data; }
	}
	class WP_REST_Request implements \ArrayAccess {
		private $p;
		public function __construct( array $p ) { $this->p = $p; }
		public function get_param( $k ) { return $this->p[ $k ] ?? null; }
		public function offsetExists( $k ): bool { return isset( $this->p[ $k ] ); }
		#[\ReturnTypeWillChange]
		public function offsetGet( $k ) { return $this->p[ $k ] ?? null; } // attribute on its own line: a comment on 7.4, honoured on 8.1+
		public function offsetSet( $k, $v ): void { $this->p[ $k ] = $v; }
		public function offsetUnset( $k ): void { unset( $this->p[ $k ] ); }
	}

	eval( '
	namespace NeuralForms\\Core {
		class Config { public static function all(): array { return $GLOBALS["__nf_global"]; } public static function get( $k, $d = null ) { return $GLOBALS["__nf_global"][ $k ] ?? $d; } }
		class Logger { public static function log( ...$a ) {} public static function file( ...$a ) {} }
	}
	namespace NeuralForms\\Storage {
		class EntryRepository {
			public static function get( $id ) { return [ "id" => $id, "form_id" => 3, "data" => [ "email" => "omar@example.com" ] ]; }
			public static function update( $id, $f ) { return true; }
		}
		class EntryMessageRepository { public static function create( array $d ) { $GLOBALS["__nf_messages"][] = $d; return 99; } }
	}
	namespace NeuralForms\\Forms {
		class FormRepository { public static function find( int $id ): ?array { return [ "id" => $id, "name" => "Repair Request", "form_schema" => [ "fields" => [] ], "settings" => [] ]; } }
		class SchemaHelper { public static function flatten_fields( array $f ): array { return $f; } }
	}
	' );

	$plugin_root = dirname( __DIR__ );
	require_once $plugin_root . '/modules/notifications/NotificationService.php';
	require_once $plugin_root . '/modules/rest/Endpoints/ReplyEndpoint.php';

	use NeuralForms\Rest\Endpoints\ReplyEndpoint;

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
	$send = function ( array $params, array $settings ) {
		$GLOBALS['__nf_global']   = $settings;
		$GLOBALS['__nf_mail']     = [];
		$GLOBALS['__nf_messages'] = [];
		$ep  = new ReplyEndpoint();
		$res = $ep->send_reply( new WP_REST_Request( array_merge( [ 'id' => 7, 'to' => 'omar@example.com', 'body' => 'Hello Omar' ], $params ) ) );
		return [ 'res' => $res, 'mail' => $GLOBALS['__nf_mail'][0] ?? null, 'msg' => $GLOBALS['__nf_messages'][0] ?? null ];
	};

	// 1. configured sender
	$r = $send( [], [ 'sender_name' => 'Fixit Repairs', 'sender_email' => 'hello@fixit.test', 'reply_subject_pattern' => 'Re: Your submission' ] );
	check( '1a. sent',                                $r['res']['sent'] ?? null, true, $passed, $failed );
	check( '1b. headers: content-type + From + Reply-To',
		$r['mail']['headers'], [ 'Content-Type: text/plain; charset=UTF-8', 'From: Fixit Repairs <hello@fixit.test>', 'Reply-To: Fixit Repairs <hello@fixit.test>' ], $passed, $failed );

	// 2. blank sender → site title + admin email
	$r = $send( [], [ 'sender_name' => '', 'sender_email' => '' ] );
	check( '2. blank sender → From site title <admin email>',
		$r['mail']['headers'][1] ?? null, 'From: Fixit & Co <admin@fixit.test>', $passed, $failed );

	// 3. default subject from pattern with tokens
	$r = $send( [], [ 'reply_subject_pattern' => 'Re: your {form_name} message to {site_name}' ] );
	check( '3. subject from pattern with tokens',      $r['mail']['subject'], 'Re: your Repair Request message to Fixit & Co', $passed, $failed );

	// 4. explicit subject wins
	$r = $send( [ 'subject' => 'Parts have arrived' ], [ 'reply_subject_pattern' => 'Re: {form_name}' ] );
	check( '4. explicit subject wins',                 $r['mail']['subject'], 'Parts have arrived', $passed, $failed );

	// 5. blank pattern → fallback
	$r = $send( [], [ 'reply_subject_pattern' => '   ' ] );
	check( '5. blank pattern → Re: Your submission',   $r['mail']['subject'], 'Re: Your submission', $passed, $failed );
	$r = $send( [], [] );
	check( '5b. missing pattern → Re: Your submission', $r['mail']['subject'], 'Re: Your submission', $passed, $failed );

	// 6. stored message sender
	$r = $send( [], [ 'sender_email' => 'hello@fixit.test' ] );
	check( '6. outgoing message records sender email', [ $r['msg']['type'], $r['msg']['sender'], $r['msg']['recipient'] ], [ 'outgoing', 'hello@fixit.test', 'omar@example.com' ], $passed, $failed );
	$r = $send( [], [] );
	check( '6b. no sender → admin email recorded',     $r['msg']['sender'], 'admin@fixit.test', $passed, $failed );

	// 7. default_subject() header safety
	check( '7. default_subject strips line breaks',
		ReplyEndpoint::default_subject( [ 'reply_subject_pattern' => "Re:\r\nBcc: x@y.z" ], null ), 'Re: Bcc: x@y.z', $passed, $failed );

	echo "\n{$passed} passed, {$failed} failed\n";
	exit( $failed === 0 ? 0 : 1 );
}
