<?php
/**
 * Lead scoring + routing (1.3.0): LeadRouting helpers, the route_lead
 * action's validation and the wiring (hot view, tokens, templates).
 *
 *   php tests/LeadRoutingTest.php
 */
define( 'ABSPATH', __DIR__ . '/' );
$root = dirname( __DIR__ );

$GLOBALS['__opts'] = array();
$GLOBALS['__filters'] = array();
$GLOBALS['__admins'] = array( 1, 2, 3 );
function __( $s ) { return $s; }
function apply_filters( $tag, $v ) { return isset( $GLOBALS['__filters'][ $tag ] ) ? $GLOBALS['__filters'][ $tag ]( $v ) : $v; }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['__opts'] ) ? $GLOBALS['__opts'][ $k ] : $d; }
function update_option( $k, $v ) { $GLOBALS['__opts'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['__opts'][ $k ] ); return true; }
function user_can( $uid, $cap ) { return in_array( (int) $uid, $GLOBALS['__admins'], true ); }
function get_userdata( $uid ) { return (object) array( 'display_name' => 'User ' . $uid ); }
class WP_Error { public $m; function __construct( $c, $m ) { $this->m = $m; } function get_error_message() { return $this->m; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }

require $root . '/modules/workflows/LeadRouting.php';
require $root . '/modules/workflows/Actions/WorkflowAction.php';
require $root . '/modules/workflows/Actions/ActionResult.php';
require $root . '/modules/workflows/Actions/AbstractAction.php';
require $root . '/modules/workflows/Actions/RouteLeadAction.php';

use NeuralForms\Workflows\LeadRouting;
use NeuralForms\Workflows\Actions\RouteLeadAction;

$passed = 0; $failed = 0;
function check( $label, $cond ) {
	global $passed, $failed;
	if ( $cond ) { $passed++; echo "PASS  $label\n"; } else { $failed++; echo "FAIL  $label\n"; }
}

// Hot score.
check( 'hot score default 80', LeadRouting::hot_score() === 80 );
$GLOBALS['__filters']['neural_forms_hot_lead_score'] = function () { return 250; };
check( 'hot score clamped to 100', LeadRouting::hot_score() === 100 );
$GLOBALS['__filters']['neural_forms_hot_lead_score'] = function () { return '70abc'; };
check( 'hot score cast to int', LeadRouting::hot_score() === 70 );
unset( $GLOBALS['__filters']['neural_forms_hot_lead_score'] );
check( 'hot where', LeadRouting::hot_where() === "ai_score >= 80 AND status IN ('new','read')" );
check( 'hot where aliased', LeadRouting::hot_where( 'e.' ) === "e.ai_score >= 80 AND e.status IN ('new','read')" );
check( 'hot where refuses odd prefix', strpos( LeadRouting::hot_where( "x'; DROP" ), 'DROP' ) === false );
check( 'is_hot at threshold', LeadRouting::is_hot( array( 'ai_score' => 80, 'status' => 'read' ) ) );
check( 'not hot below', ! LeadRouting::is_hot( array( 'ai_score' => 79, 'status' => 'new' ) ) );
check( 'not hot in spam', ! LeadRouting::is_hot( array( 'ai_score' => 99, 'status' => 'spam' ) ) );
check( 'not hot without score', ! LeadRouting::is_hot( array( 'ai_score' => null, 'status' => 'new' ) ) );

// Team list cleaning.
check( 'user ids cleaned', LeadRouting::clean_user_ids( array( '3', 0, -1, 3, 'x', array( 9 ), 2 ) ) === array( 3, 2 ) );
check( 'user ids capped', count( LeadRouting::clean_user_ids( range( 1, 50 ) ) ) === LeadRouting::MAX_TEAM );
check( 'non-array → empty', LeadRouting::clean_user_ids( '1,2' ) === array() );

// Round robin + forget.
$seq = array(); for ( $i = 0; $i < 4; $i++ ) { $seq[] = LeadRouting::pick_round_robin( 'w7', array( 1, 2, 3 ) ); }
check( 'round robin in turn', $seq === array( 1, 2, 3, 1 ) );
check( 'separate rotation per workflow', LeadRouting::pick_round_robin( 'w8', array( 1, 2, 3 ) ) === 1 );
LeadRouting::forget( 7 );
check( 'forget drops only that workflow', ! isset( $GLOBALS['__opts'][ LeadRouting::POINTER_OPTION ]['w7'] ) && isset( $GLOBALS['__opts'][ LeadRouting::POINTER_OPTION ]['w8'] ) );
LeadRouting::forget( 8 );
check( 'last forget deletes the option', ! isset( $GLOBALS['__opts'][ LeadRouting::POINTER_OPTION ] ) );
check( 'empty team picks nobody', LeadRouting::pick_round_robin( 'w9', array() ) === 0 && LeadRouting::pick_fewest_open( array() ) === 0 );

// Action validation.
$a = new RouteLeadAction();
check( 'type', $a->type() === 'route_lead' );
check( 'valid team', $a->validate_settings( array( 'user_ids' => array( 1, 2 ), 'mode' => 'fewest_open' ) ) === true );
check( 'empty team refused', is_wp_error( $a->validate_settings( array( 'user_ids' => array() ) ) ) );
check( 'non-admin refused', is_wp_error( $a->validate_settings( array( 'user_ids' => array( 1, 99 ) ) ) ) );
check( 'unknown mode refused', is_wp_error( $a->validate_settings( array( 'user_ids' => array( 1 ), 'mode' => 'random' ) ) ) );
check( 'describe names the team', $a->describe( array( 'user_ids' => array( 1, 2 ) ), array() ) === 'Assign in turn to: User 1, User 2' );
$r = $a->execute( array( 'user_ids' => array( 1 ) ), array( 'entry' => array( 'id' => 5, 'assigned_to' => 2 ), 'workflow_id' => 1, 'workflow_name' => 'W' ) );
check( 'keeps an existing owner by default', $r->status === 'skipped' );
$GLOBALS['__admins'] = array( 1 );
$r = $a->execute( array( 'user_ids' => array( 2, 3 ), 'skip_if_assigned' => false ), array( 'entry' => array( 'id' => 5, 'assigned_to' => 0 ), 'workflow_id' => 1, 'workflow_name' => 'W' ) );
check( 'demoted team fails at run time', $r->status === 'failed' );

// Wiring (source-level).
$repo = file_get_contents( $root . '/modules/storage/EntryRepository.php' );
check( 'hot is a valid view', strpos( $repo, "'paid', 'hot' );" ) !== false );
check( 'hot count + filter use hot_where', substr_count( $repo, 'LeadRouting::hot_where(' ) === 3 );
check( 'action registered', strpos( file_get_contents( $root . '/modules/workflows/Actions/ActionRegistry.php' ), 'new RouteLeadAction()' ) !== false );
$abs = file_get_contents( $root . '/modules/workflows/Actions/AbstractAction.php' );
foreach ( array( 'ai_score', 'ai_priority', 'ai_category', 'ai_summary', 'assigned_to' ) as $t ) {
	check( "token {$t}", strpos( $abs, "\$data['{$t}']" ) !== false );
}
check( 'smart filter gated by workflows', strpos( file_get_contents( $root . '/modules/admin/src/entries/constants.js' ), "{ id: 'hot',            label: 'Hot leads',          cap: 'workflows' }" ) !== false );
$ws = file_get_contents( $root . '/modules/admin/src/forms/WorkflowsSection.js' );
check( 'templates present', strpos( $ws, "id: 'hot_alert'" ) !== false && strpos( $ws, "id: 'share_sales'" ) !== false && strpos( $ws, "id: 'route_answer'" ) !== false );
check( 'uninstall removes rotation', strpos( file_get_contents( $root . '/uninstall.php' ), "delete_option( 'neural_forms_route_pointer' )" ) !== false );
check( 'deleting a workflow forgets its rotation', strpos( file_get_contents( $root . '/modules/workflows/WorkflowRepository.php' ), 'LeadRouting::forget( $id );' ) !== false );

echo "\nLeadRoutingTest: $passed passed, $failed failed\n";
exit( $failed ? 1 : 0 );
