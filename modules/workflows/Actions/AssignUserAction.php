<?php
namespace NeuralForms\Workflows\Actions;

use NeuralForms\Storage\EntryRepository;

defined( 'ABSPATH' ) || exit;

final class AssignUserAction extends AbstractAction {

	public function type(): string { return 'assign_user'; }
	public function label(): string { return __( 'Assign to a team member', 'neural-forms-lite' ); }
	public function description(): string { return __( 'Sets the entry owner. Only users who can manage entries can be chosen.', 'neural-forms-lite' ); }

	public function settings_schema(): array {
		return array(
			array( 'key' => 'user_id', 'label' => __( 'Team member', 'neural-forms-lite' ), 'type' => 'user', 'required' => true ),
		);
	}

	public function validate_settings( array $settings ) {
		$r = parent::validate_settings( $settings );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$uid = (int) ( $settings['user_id'] ?? 0 );
		if ( $uid <= 0 || ( function_exists( 'user_can' ) && ! user_can( $uid, 'manage_options' ) ) ) {
			return $this->error( __( 'That user cannot manage entries and cannot be assigned.', 'neural-forms-lite' ) );
		}
		return true;
	}

	public function describe( array $settings, array $ctx ): string {
		$uid  = (int) ( $settings['user_id'] ?? 0 );
		$user = $uid > 0 && function_exists( 'get_userdata' ) ? get_userdata( $uid ) : null;
		$name = $user && isset( $user->display_name ) ? (string) $user->display_name : ( '#' . $uid );
		return sprintf( __( 'Assign the entry to %s', 'neural-forms-lite' ), $name );
	}

	public function execute( array $settings, array $ctx ): ActionResult {
		$uid = (int) ( $settings['user_id'] ?? 0 );
		if ( $uid <= 0 || ! user_can( $uid, 'manage_options' ) ) {
			// Re-checked at run time: the user may have been demoted since the workflow was saved.
			return ActionResult::failed( __( 'The chosen user can no longer manage entries.', 'neural-forms-lite' ) );
		}
		$entry_id = (int) $ctx['entry']['id'];
		if ( (int) ( $ctx['entry']['assigned_to'] ?? 0 ) === $uid ) {
			return ActionResult::done( __( 'Already assigned to that user.', 'neural-forms-lite' ) );
		}
		if ( ! EntryRepository::update( $entry_id, array( 'assigned_to' => $uid ) ) ) {
			return ActionResult::retry( __( 'Could not save the assignment.', 'neural-forms-lite' ) );
		}
		$user = get_userdata( $uid );
		$name = $user ? (string) $user->display_name : ( '#' . $uid );
		$this->activity( $ctx, 'workflow.assigned', sprintf( __( 'Assigned to %1$s by workflow "%2$s"', 'neural-forms-lite' ), $name, (string) $ctx['workflow_name'] ), array( 'to' => $uid, 'to_name' => $name ) );
		return ActionResult::done( sprintf( __( 'Assigned to %s', 'neural-forms-lite' ), $name ) );
	}
}
