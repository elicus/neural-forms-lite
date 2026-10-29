<?php
namespace NeuralForms\Workflows\Actions;

use NeuralForms\Storage\EntryRepository;

defined( 'ABSPATH' ) || exit;

final class CreateFollowUpAction extends AbstractAction {

	public function type(): string { return 'create_follow_up'; }
	public function label(): string { return __( 'Set a follow-up reminder', 'neural-forms-lite' ); }
	public function description(): string { return __( 'Schedules a follow-up a number of hours from now. Overdue follow-ups show in the inbox and fire the overdue trigger.', 'neural-forms-lite' ); }

	public function settings_schema(): array {
		return array(
			array( 'key' => 'hours', 'label' => __( 'Due in (hours)', 'neural-forms-lite' ), 'type' => 'number', 'required' => true, 'default' => 24, 'hint' => __( 'Elapsed hours, not business hours. 24 = one day, 72 = three days.', 'neural-forms-lite' ) ),
			array( 'key' => 'assign_to', 'label' => __( 'Also assign to', 'neural-forms-lite' ), 'type' => 'user', 'hint' => __( 'Optional owner for the reminder.', 'neural-forms-lite' ) ),
		);
	}

	public function validate_settings( array $settings ) {
		$r = parent::validate_settings( $settings );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$h = (float) ( $settings['hours'] ?? 0 );
		if ( $h <= 0 || $h > 24 * 365 ) {
			return $this->error( __( 'Hours must be between 1 and 8760.', 'neural-forms-lite' ) );
		}
		$uid = (int) ( $settings['assign_to'] ?? 0 );
		if ( $uid > 0 && function_exists( 'user_can' ) && ! user_can( $uid, 'manage_options' ) ) {
			return $this->error( __( 'That user cannot manage entries and cannot be assigned.', 'neural-forms-lite' ) );
		}
		return true;
	}

	public function describe( array $settings, array $ctx ): string {
		return sprintf( __( 'Set a follow-up due in %s hours', 'neural-forms-lite' ), (string) (float) ( $settings['hours'] ?? 24 ) );
	}

	public function execute( array $settings, array $ctx ): ActionResult {
		$hours = (float) ( $settings['hours'] ?? 24 );
		if ( $hours <= 0 ) {
			return ActionResult::failed( __( 'Invalid number of hours.', 'neural-forms-lite' ) );
		}
		$due    = gmdate( 'Y-m-d H:i:s', time() + (int) round( $hours * 3600 ) );
		$fields = array( 'follow_up_due' => $due );
		$uid    = (int) ( $settings['assign_to'] ?? 0 );
		if ( $uid > 0 && user_can( $uid, 'manage_options' ) ) {
			$fields['assigned_to'] = $uid;
		}
		if ( ! EntryRepository::update( (int) $ctx['entry']['id'], $fields ) ) {
			return ActionResult::retry( __( 'Could not save the follow-up.', 'neural-forms-lite' ) );
		}
		$this->activity( $ctx, 'workflow.follow_up_set', sprintf( __( 'Follow-up due %1$s set by workflow "%2$s"', 'neural-forms-lite' ), $due, (string) $ctx['workflow_name'] ), array( 'due' => $due ) );
		return ActionResult::done( sprintf( __( 'Follow-up due %s', 'neural-forms-lite' ), $due ) );
	}
}
