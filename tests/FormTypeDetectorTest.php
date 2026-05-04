<?php
/**
 * FormTypeDetector unit tests.
 *
 * Exercises the auto-detection rules:
 *   simple | poll | quiz | survey | mixed
 * plus the input-validation edge cases (null, empty, malformed).
 *
 * Run from the plugin root:
 *
 *   php tests/FormTypeDetectorTest.php
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', '/tmp/abspath' );
if ( ! function_exists( '__' ) ) { function __( $s, $domain = null ) { return $s; } }

$plugin_root = dirname( __DIR__ );
require_once $plugin_root . '/modules/forms/FieldRegistry.php';
require_once $plugin_root . '/modules/forms/SchemaHelper.php';
require_once $plugin_root . '/modules/forms/FormTypeDetector.php';

use NeuralForms\Forms\FormTypeDetector;

$cases = [];
$passed = 0; $failed = 0;

function check( string $label, $actual, $expected, array &$cases, int &$passed, int &$failed ): void {
	$ok = $actual === $expected;
	$cases[] = [ $ok, $label, $actual, $expected ];
	if ( $ok ) { $passed++; } else { $failed++; }
}

// Fixtures
$f_text     = [ 'id' => 'name',     'type' => 'text',          'label' => 'Name' ];
$f_email    = [ 'id' => 'email',    'type' => 'email',         'label' => 'Email' ];
$f_textarea = [ 'id' => 'message',  'type' => 'textarea',      'label' => 'Message' ];
$f_rating   = [ 'id' => 'rating1',  'type' => 'rating',        'label' => 'Rate us' ];
$f_poll     = [ 'id' => 'p1',       'type' => 'poll',          'label' => 'Pick',
                'options' => [ [ 'label' => 'A', 'value' => 'a' ] ] ];
$f_quiz     = [ 'id' => 'q1',       'type' => 'quiz_question', 'label' => 'Capital?' ];
$f_fb_text  = [ 'id' => 'comments', 'type' => 'text',          'label' => 'Your comments' ];
$f_fb_label = [ 'id' => 'feedback_box', 'type' => 'text',      'label' => 'Open feedback' ];

// ---------------------------------------------------------------------------
// 1. Edge inputs.
// ---------------------------------------------------------------------------
check( '1a null -> simple',          FormTypeDetector::detect( null ),                         'simple', $cases, $passed, $failed );
check( '1b empty array -> simple',   FormTypeDetector::detect( [] ),                           'simple', $cases, $passed, $failed );
check( '1c missing fields -> simple',FormTypeDetector::detect( [ 'name' => 'X' ] ),            'simple', $cases, $passed, $failed );
check( '1d empty fields -> simple',  FormTypeDetector::detect( [ 'fields' => [] ] ),           'simple', $cases, $passed, $failed );
check( '1e non-array schema -> simple', FormTypeDetector::detect( 'garbage' ),                 'simple', $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 2. Simple.
// ---------------------------------------------------------------------------
check( '2a contact form (text+email) -> simple',
	FormTypeDetector::detect( [ 'fields' => [ $f_text, $f_email ] ] ),
	'simple', $cases, $passed, $failed );
check( '2b text+email+textarea (no rating) -> simple',
	FormTypeDetector::detect( [ 'fields' => [ $f_text, $f_email, $f_textarea ] ] ),
	'simple', $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 3. Poll-only.
// ---------------------------------------------------------------------------
check( '3a single poll -> poll',
	FormTypeDetector::detect( [ 'fields' => [ $f_poll ] ] ),
	'poll', $cases, $passed, $failed );
check( '3b poll + plain fields -> poll',
	FormTypeDetector::detect( [ 'fields' => [ $f_text, $f_poll, $f_email ] ] ),
	'poll', $cases, $passed, $failed );
check( '3c poll + rating (no feedback text) -> poll',
	FormTypeDetector::detect( [ 'fields' => [ $f_poll, $f_rating ] ] ),
	'poll', $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 4. Quiz-only.
// ---------------------------------------------------------------------------
check( '4a single quiz_question -> quiz',
	FormTypeDetector::detect( [ 'fields' => [ $f_quiz ] ] ),
	'quiz', $cases, $passed, $failed );
check( '4b quiz + plain fields -> quiz',
	FormTypeDetector::detect( [ 'fields' => [ $f_text, $f_quiz ] ] ),
	'quiz', $cases, $passed, $failed );
check( '4c quiz + rating (no poll) -> quiz',
	FormTypeDetector::detect( [ 'fields' => [ $f_quiz, $f_rating ] ] ),
	'quiz', $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 5. Mixed (poll + quiz).
// ---------------------------------------------------------------------------
check( '5a poll + quiz -> mixed',
	FormTypeDetector::detect( [ 'fields' => [ $f_poll, $f_quiz ] ] ),
	'mixed', $cases, $passed, $failed );
check( '5b quiz + poll + rating + feedback -> mixed (quiz+poll wins)',
	FormTypeDetector::detect( [ 'fields' => [ $f_rating, $f_quiz, $f_textarea, $f_poll ] ] ),
	'mixed', $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 6. Survey (rating + feedback-text).
// ---------------------------------------------------------------------------
check( '6a rating + textarea -> survey',
	FormTypeDetector::detect( [ 'fields' => [ $f_rating, $f_textarea ] ] ),
	'survey', $cases, $passed, $failed );
check( '6b rating + text labelled "Your comments" -> survey',
	FormTypeDetector::detect( [ 'fields' => [ $f_rating, $f_fb_text ] ] ),
	'survey', $cases, $passed, $failed );
check( '6c rating + id "feedback_box" -> survey',
	FormTypeDetector::detect( [ 'fields' => [ $f_rating, $f_fb_label ] ] ),
	'survey', $cases, $passed, $failed );
check( '6d rating only (no commentary) -> simple',
	FormTypeDetector::detect( [ 'fields' => [ $f_rating ] ] ),
	'simple', $cases, $passed, $failed );
check( '6e rating + plain text "Name" -> simple',
	FormTypeDetector::detect( [ 'fields' => [ $f_rating, $f_text ] ] ),
	'simple', $cases, $passed, $failed );
check( '6f textarea only (no rating) -> simple',
	FormTypeDetector::detect( [ 'fields' => [ $f_textarea ] ] ),
	'simple', $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 7. Recursion: poll inside row + quiz inside step.
// ---------------------------------------------------------------------------
$row_with_poll = [
	'id' => 'row_1', 'type' => 'row',
	'settings' => [ 'columns' => 1, 'gap' => 'medium' ],
	'columns' => [ [ 'id' => 'col_1', 'fields' => [ $f_poll ] ] ],
];
$step_with_quiz = [
	'id' => 'step_1', 'type' => 'step', 'title' => 'X',
	'settings' => [ 'button_next' => 'N', 'button_previous' => 'B' ],
	'fields' => [ $f_quiz ],
];
check( '7a poll inside row -> poll',
	FormTypeDetector::detect( [ 'fields' => [ $row_with_poll ] ] ),
	'poll', $cases, $passed, $failed );
check( '7b quiz inside step -> quiz',
	FormTypeDetector::detect( [ 'fields' => [ $step_with_quiz ] ] ),
	'quiz', $cases, $passed, $failed );
check( '7c row.poll + step.quiz -> mixed',
	FormTypeDetector::detect( [ 'fields' => [ $row_with_poll, $step_with_quiz ] ] ),
	'mixed', $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 8. Labels.
// ---------------------------------------------------------------------------
check( '8a label simple',  FormTypeDetector::label( 'simple' ),  'Simple', $cases, $passed, $failed );
check( '8b label poll',    FormTypeDetector::label( 'poll' ),    'Poll',   $cases, $passed, $failed );
check( '8c label quiz',    FormTypeDetector::label( 'quiz' ),    'Quiz',   $cases, $passed, $failed );
check( '8d label survey',  FormTypeDetector::label( 'survey' ),  'Survey', $cases, $passed, $failed );
check( '8e label mixed',   FormTypeDetector::label( 'mixed' ),   'Mixed',  $cases, $passed, $failed );
check( '8f label unknown', FormTypeDetector::label( 'gibberish' ), 'Simple', $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// Output
// ---------------------------------------------------------------------------
foreach ( $cases as $c ) {
	if ( $c[0] ) {
		echo "\033[32mPASS\033[0m  " . $c[1] . "\n";
	} else {
		echo "\033[31mFAIL\033[0m  " . $c[1] . "\n";
		echo "    expected: " . var_export( $c[3], true ) . "\n";
		echo "    actual:   " . var_export( $c[2], true ) . "\n";
	}
}
echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed === 0 ? 0 : 1 );
