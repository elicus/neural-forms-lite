<?php
namespace NeuralForms\Workflows\Actions;

use NeuralForms\Storage\EntryRepository;
use NeuralForms\Workflows\LeadRouting;

defined( 'ABSPATH' ) || exit;

/**
 * Share leads between team members (1.3.0): round robin, or whoever has
 * the fewest open leads. Pair with conditions on the AI score / category
 * or on answers to route each kind of lead to the right group.
 */
final class RouteLeadAction extends AbstractAction {

	public function type(): string { return 'route_lead'; }
	public function label(): string { return __( 'Share between team members', 'neural-forms-lite' ); }
	public function description(): string { return __( 'Assigns the entry to one of several team members: in turn, or to whoever has the fewest open leads. Only users who can manage entries can be chosen.', 'neural-forms-lite' ); }

	public function settings_schema(): array {
		return array(
			array( 'key' => 'user_ids', 'label' => __( 'Team members', 'neural-forms-lite' ), 'type' => 'users', 'required' => true ),
			array(
				'key'     => 'mode',
				'label'   => __( 'How to choose', 'neural-forms-lite' ),
				'type'    => 'select',
				'default' => 'round_robin',
				'options' => array(
					array( 'value' => 'round_robin', 'label' => __( 'In turn (round robin)', 'neural-forms-lite' ) ),
					array( 'value' => 'fewest_open', 'label' => __( 'Whoever has the fewest open leads', 'neural-forms-lite' ) ),
				),
			),
			array( 'key' => 'skip_if_assigned', 'label' => __( 'Keep the current owner if the entry already has one', 'neural-forms-lite' ), 'type' => 'checkbox', 'default' => true ),
		);
	}

	public function validate_settings( array $settings ) {
		$ids = LeadRouting::clean_user_ids( $settings['user_ids'] ?? array() );
		if ( ! $ids ) {
			return $this->error( __( 'Choose at least one team member.', 'neural-forms-lite' ) );
		}
		foreach ( $ids as $uid ) {
			if ( function_exists( 'user_can' ) && ! user_can( $uid, 'manage_options' ) ) {
				return $this->error( __( 'One of the chosen users cannot manage entries.', 'neural-forms-lite' ) );
			}
		}
		if ( ! in_array( (string) ( $settings['mode'] ?? 'round_robin' ), array( 'round_robin', 'fewest_open' ), true ) ) {
			return $this->error( __( 'Unknown way of choosing a team member.', 'neural-forms-lite' ) );
		}
		return true;
	}

	public function describe( array $settings, array $ctx ): string {
		$names = array();
		foreach ( LeadRouting::clean_user_ids( $settings['user_ids'] ?? array() ) as $uid ) {
			$u       = function_exists( 'get_userdata' ) ? get_userdata( $uid ) : null;
			$names[] = $u && isset( $u->display_name ) ? (string) $u->display_name : '#' . $uid;
		}
		return ( $settings['mode'] ?? '' ) === 'fewest_open'
			/* translators: %s: comma-separated names */
			? sprintf( __( 'Assign to whoever has the fewest open leads: %s', 'neural-forms-lite' ), implode( ', ', $names ) )
			/* translators: %s: comma-separated names */
			: sprintf( __( 'Assign in turn to: %s', 'neural-forms-lite' ), implode( ', ', $names ) );
	}

	public function execute( array $settings, array $ctx ): ActionResult {
		$entry_id = (int) $ctx['entry']['id'];
		$current  = (int) ( $ctx['entry']['assigned_to'] ?? 0 );
		$keep     = ! array_key_exists( 'skip_if_assigned', $settings ) || ! empty( $settings['skip_if_assigned'] );
		if ( $keep && $current > 0 ) {
			return ActionResult::skipped( __( 'The entry already has an owner.', 'neural-forms-lite' ) );
		}

		// Re-checked at run time: someone may have been demoted since saving.
		$team = array_values( array_filter( LeadRouting::clean_user_ids( $settings['user_ids'] ?? array() ), static function ( $uid ) {
			return user_can( $uid, 'manage_options' );
		} ) );
		if ( ! $team ) {
			return ActionResult::failed( __( 'None of the chosen team members can manage entries any more.', 'neural-forms-lite' ) );
		}

		$mode = ( $settings['mode'] ?? '' ) === 'fewest_open' ? 'fewest_open' : 'round_robin';
		$uid  = $mode === 'fewest_open'
			? LeadRouting::pick_fewest_open( $team )
			: LeadRouting::pick_round_robin( 'w' . (int) ( $ctx['workflow_id'] ?? 0 ), $team );

		if ( $uid === $current ) {
			return ActionResult::done( __( 'Already assigned to that user.', 'neural-forms-lite' ) );
		}
		if ( ! EntryRepository::update( $entry_id, array( 'assigned_to' => $uid ) ) ) {
			return ActionResult::retry( __( 'Could not save the assignment.', 'neural-forms-lite' ) );
		}
		$user = get_userdata( $uid );
		$name = $user ? (string) $user->display_name : ( '#' . $uid );
		$this->activity(
			$ctx,
			'workflow.assigned',
			/* translators: 1: user name, 2: workflow name */
			sprintf( __( 'Assigned to %1$s by workflow "%2$s"', 'neural-forms-lite' ), $name, (string) $ctx['workflow_name'] ),
			array( 'to' => $uid, 'to_name' => $name, 'mode' => $mode )
		);
		/* translators: %s: user name */
		return ActionResult::done( sprintf( __( 'Assigned to %s', 'neural-forms-lite' ), $name ) );
	}
}
