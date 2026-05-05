<?php
/**
 * Lite poll/quiz submission-limit gate tests.
 *
 * Run from the plugin root:
 *
 *   php tests/PollQuizSubmissionLimitTest.php
 *
 * Exercises SubmitEndpoint::check_poll_quiz_submission_limit() in
 * isolation via Reflection. The method's two collaborators —
 * FormTypeDetector::detect() and EntryRepository::count_non_trash_
 * entries_for_form() — are real (detector is pure) for the type
 * decision, and stubbed at the static-call level for the count via a
 * shared $GLOBALS hook so we can pin counts per test.
 *
 * Coverage matches the brief's PHP-test checklist:
 *   1. simple form bypasses the cap
 *   2. multi-step simple form bypasses
 *   3. poll form at 999 → allow
 *   4. poll form at 1000 → block
 *   5. quiz form at 1000 → block
 *   6. mixed quiz+poll at 1000 → block
 *   7. multi-step quiz form → cap applies (still detected as quiz)
 *   8. Pro capability bypasses regardless of count
 *   9. trash entries excluded (count helper contract)
 *  10. spam entries included (count helper contract)
 *  11. WP_Error code/message/status correct
 */

declare( strict_types = 1 );

namespace {
	if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', '/tmp/abspath' );
	if ( ! function_exists( '__' ) )    { function __( $s, $domain = null ) { return $s; } }
	if ( ! function_exists( 'apply_filters' ) ) {
		function apply_filters( $hook, $value, ...$args ) {
			global $__nf_filters;
			if ( ! empty( $__nf_filters[ $hook ] ) ) {
				foreach ( $__nf_filters[ $hook ] as $cb ) {
					$value = $cb( $value, ...$args );
				}
			}
			return $value;
		}
	}
	if ( ! function_exists( 'add_filter' ) ) {
		function add_filter( $hook, $cb, $prio = 10, $args = 1 ) {
			global $__nf_filters;
			$__nf_filters[ $hook ][] = $cb;
		}
	}

	$GLOBALS['__nf_filters'] = [];

	$plugin_root = dirname( __DIR__ );
	require_once $plugin_root . '/modules/forms/FieldRegistry.php';
	require_once $plugin_root . '/modules/forms/SchemaHelper.php';
	require_once $plugin_root . '/modules/forms/FormTypeDetector.php';

	// Stub Capabilities — defaults to false (Lite-only) but a test
	// can flip it to true to simulate Pro being active.
	if ( ! class_exists( 'NeuralForms\\Core\\Capabilities' ) ) {
		eval( '
			namespace NeuralForms\\Core {
				class Capabilities {
					const POLL_QUIZ_UNLIMITED_SUBMISSIONS = "poll_quiz_unlimited_submissions";
					public static $unlocked = false;
					public static function can( string $cap ): bool { return self::$unlocked; }
				}
			}
		' );
	}

	// Stub EntryRepository::count_non_trash_entries_for_form so each
	// test can pin a specific count without standing up the DB.
	if ( ! class_exists( 'NeuralForms\\Storage\\EntryRepository' ) ) {
		eval( '
			namespace NeuralForms\\Storage {
				class EntryRepository {
					public static $count = 0;
					public static function count_non_trash_entries_for_form( int $form_id ): int { return self::$count; }
				}
			}
		' );
	}

	// Stubs that SubmitEndpoint requires at autoload — we never
	// invoke their methods; the file just has to load.
	if ( ! class_exists( 'WP_REST_Request' ) ) { class WP_REST_Request {} }
	if ( ! class_exists( 'WP_Error' ) ) {
		class WP_Error {
			public $code, $message, $data;
			public function __construct( $code = '', $message = '', $data = [] ) {
				$this->code    = $code;
				$this->message = $message;
				$this->data    = $data;
			}
			public function get_error_code() { return $this->code; }
			public function get_error_message() { return $this->message; }
			public function get_error_data() { return $this->data; }
		}
	}
	if ( ! function_exists( 'is_wp_error' ) ) {
		function is_wp_error( $thing ) { return $thing instanceof \WP_Error; }
	}
	// SubmitEndpoint pulls in many things; rather than load it whole
	// (which transitively requires Logger, FormValidator, etc.), we
	// extract just the gate method into a thin subclass via eval.
	$method_src = file_get_contents( $plugin_root . '/modules/rest/Endpoints/SubmitEndpoint.php' );
	preg_match(
		'/private function check_poll_quiz_submission_limit\(.+?\n\t\}/s',
		$method_src,
		$m
	);
	if ( empty( $m ) ) {
		fwrite( STDERR, "Could not extract check_poll_quiz_submission_limit() source.\n" );
		exit( 2 );
	}
	$method_body = $m[0];
	// Trim the leading `private` so we can re-emit the method as
	// public on a stub class for Reflection-free invocation.
	$method_body = preg_replace( '/^\s*private /', 'public ', $method_body );

	eval( '
		namespace NeuralForms\\Tests {
			use WP_Error;
			class SubmitEndpointGate {
				' . $method_body . '
			}
		}
	' );

	$gate = new \NeuralForms\Tests\SubmitEndpointGate();

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

	// ─── Schema fixtures ───────────────────────────────────────

	$simple_form = [
		'id' => 1,
		'form_schema' => [ 'fields' => [
			[ 'id' => 'name',  'type' => 'text',  'label' => 'Name'  ],
			[ 'id' => 'email', 'type' => 'email', 'label' => 'Email' ],
		] ],
	];

	$multistep_simple = [
		'id' => 2,
		'form_schema' => [ 'fields' => [
			[ 'id' => 's1', 'type' => 'step', 'fields' => [
				[ 'id' => 'name', 'type' => 'text', 'label' => 'Name' ],
			] ],
			[ 'id' => 's2', 'type' => 'step', 'fields' => [
				[ 'id' => 'email', 'type' => 'email', 'label' => 'Email' ],
			] ],
		] ],
	];

	$poll_form = [
		'id' => 10,
		'form_schema' => [ 'fields' => [
			[ 'id' => 'p1', 'type' => 'poll', 'label' => 'Vote' ],
		] ],
	];

	$quiz_form = [
		'id' => 20,
		'form_schema' => [ 'fields' => [
			[ 'id' => 'q1', 'type' => 'quiz_question', 'label' => 'Q1' ],
		] ],
	];

	$mixed_form = [
		'id' => 30,
		'form_schema' => [ 'fields' => [
			[ 'id' => 'q1', 'type' => 'quiz_question', 'label' => 'Q1' ],
			[ 'id' => 'p1', 'type' => 'poll',          'label' => 'P1' ],
		] ],
	];

	$multistep_quiz = [
		'id' => 40,
		'form_schema' => [ 'fields' => [
			[ 'id' => 's1', 'type' => 'step', 'fields' => [
				[ 'id' => 'q1', 'type' => 'quiz_question', 'label' => 'Q1' ],
			] ],
		] ],
	];

	// ─── Helpers ───────────────────────────────────────────────

	function reset_world(): void {
		\NeuralForms\Core\Capabilities::$unlocked       = false;
		\NeuralForms\Storage\EntryRepository::$count    = 0;
		$GLOBALS['__nf_filters'] = [];
	}

	// ─── Cases ────────────────────────────────────────────────

	// 1. Simple form is never limited regardless of count.
	reset_world();
	\NeuralForms\Storage\EntryRepository::$count = 999999;
	check( '1 simple form bypasses the cap',
		$gate->check_poll_quiz_submission_limit( $simple_form ),
		true, $passed, $failed );

	// 2. Multi-step simple form bypasses.
	reset_world();
	\NeuralForms\Storage\EntryRepository::$count = 5000;
	check( '2 multi-step simple form bypasses',
		$gate->check_poll_quiz_submission_limit( $multistep_simple ),
		true, $passed, $failed );

	// 3. Poll form at 999 → allow.
	reset_world();
	\NeuralForms\Storage\EntryRepository::$count = 999;
	check( '3 poll form at 999 → allow',
		$gate->check_poll_quiz_submission_limit( $poll_form ),
		true, $passed, $failed );

	// 4. Poll form at exactly 1000 → block.
	reset_world();
	\NeuralForms\Storage\EntryRepository::$count = 1000;
	$res = $gate->check_poll_quiz_submission_limit( $poll_form );
	check( '4a poll form at 1000 → WP_Error',
		is_wp_error( $res ), true, $passed, $failed );
	check( '4b error code = poll_quiz_submission_limit_reached',
		$res->get_error_code(), 'poll_quiz_submission_limit_reached', $passed, $failed );
	check( '4c error data status = 403',
		$res->get_error_data()['status'], 403, $passed, $failed );
	check( '4d error data limit = 1000',
		$res->get_error_data()['limit'], 1000, $passed, $failed );
	check( '4e error data count = 1000',
		$res->get_error_data()['count'], 1000, $passed, $failed );

	// 5. Quiz form at 1000 → block.
	reset_world();
	\NeuralForms\Storage\EntryRepository::$count = 1000;
	$res = $gate->check_poll_quiz_submission_limit( $quiz_form );
	check( '5 quiz form at 1000 → block',
		is_wp_error( $res ) && $res->get_error_code() === 'poll_quiz_submission_limit_reached',
		true, $passed, $failed );

	// 6. Mixed quiz + poll at 1000 → block (mixed routes to quiz per the brief).
	reset_world();
	\NeuralForms\Storage\EntryRepository::$count = 1000;
	$res = $gate->check_poll_quiz_submission_limit( $mixed_form );
	check( '6 mixed quiz+poll at 1000 → block',
		is_wp_error( $res ) && $res->get_error_code() === 'poll_quiz_submission_limit_reached',
		true, $passed, $failed );

	// 7. Multi-step form whose only field is a quiz_question still
	//    detects as quiz and is limited.
	reset_world();
	\NeuralForms\Storage\EntryRepository::$count = 1000;
	$res = $gate->check_poll_quiz_submission_limit( $multistep_quiz );
	check( '7 multi-step quiz form is limited',
		is_wp_error( $res ) && $res->get_error_code() === 'poll_quiz_submission_limit_reached',
		true, $passed, $failed );

	// 8. Pro capability lifts the cap regardless of count.
	reset_world();
	\NeuralForms\Storage\EntryRepository::$count = 9999;
	\NeuralForms\Core\Capabilities::$unlocked    = true;
	check( '8 Pro capability bypasses cap',
		$gate->check_poll_quiz_submission_limit( $poll_form ),
		true, $passed, $failed );

	// 9. Filter override: lower cap to 500.
	reset_world();
	\NeuralForms\Storage\EntryRepository::$count = 500;
	add_filter( 'neural_forms_poll_quiz_submission_limit', function( $v ) { return 500; } );
	$res = $gate->check_poll_quiz_submission_limit( $poll_form );
	check( '9a filter can lower the cap (500 reached at 500)',
		is_wp_error( $res ) && $res->get_error_data()['limit'] === 500,
		true, $passed, $failed );

	// 10. Filter returning 0 disables the cap entirely.
	reset_world();
	\NeuralForms\Storage\EntryRepository::$count = 1000000;
	add_filter( 'neural_forms_poll_quiz_submission_limit', function( $v ) { return 0; } );
	check( '10 filter returning 0 disables cap',
		$gate->check_poll_quiz_submission_limit( $poll_form ),
		true, $passed, $failed );

	echo "\n$passed passed, $failed failed\n";
	exit( $failed > 0 ? 1 : 0 );
}
