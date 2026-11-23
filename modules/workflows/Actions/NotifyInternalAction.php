<?php
namespace NeuralForms\Workflows\Actions;

use NeuralForms\Notifications\NotificationService;

defined( 'ABSPATH' ) || exit;

final class NotifyInternalAction extends AbstractAction {

	public function type(): string { return 'notify_internal'; }
	public function label(): string { return __( 'Notify your team', 'neural-forms-lite' ); }
	public function description(): string { return __( 'Emails fixed addresses. Tokens: {form_name}, {site_name}, {entry_url}, {field_id}, {payment_status}, {payment_amount}, {payment_remaining}, {payment_kind}.', 'neural-forms-lite' ); }

	public function settings_schema(): array {
		return array(
			array( 'key' => 'recipients', 'label' => __( 'Recipients', 'neural-forms-lite' ), 'type' => 'text', 'required' => true, 'hint' => __( 'Comma-separated email addresses. Visitor input is never used as a recipient.', 'neural-forms-lite' ) ),
			array( 'key' => 'subject', 'label' => __( 'Subject', 'neural-forms-lite' ), 'type' => 'text', 'required' => true, 'default' => __( 'New qualified lead: {form_name}', 'neural-forms-lite' ) ),
			array( 'key' => 'body', 'label' => __( 'Message', 'neural-forms-lite' ), 'type' => 'textarea', 'required' => true, 'default' => __( "A new enquiry needs attention.\n\n{entry_url}", 'neural-forms-lite' ) ),
		);
	}

	public function validate_settings( array $settings ) {
		$r = parent::validate_settings( $settings );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		if ( ! NotificationService::parse_recipient_list( (string) ( $settings['recipients'] ?? '' ) ) ) {
			return $this->error( __( 'Enter at least one valid email address.', 'neural-forms-lite' ) );
		}
		if ( strpos( (string) ( $settings['recipients'] ?? '' ), '{' ) !== false ) {
			return $this->error( __( 'Recipients must be fixed addresses, not tokens.', 'neural-forms-lite' ) );
		}
		return true;
	}

	public function describe( array $settings, array $ctx ): string {
		return sprintf( __( 'Email your team at %s', 'neural-forms-lite' ), (string) ( $settings['recipients'] ?? '' ) );
	}

	public function execute( array $settings, array $ctx ): ActionResult {
		$to = NotificationService::parse_recipient_list( (string) ( $settings['recipients'] ?? '' ) );
		if ( ! $to ) {
			return ActionResult::failed( __( 'No valid recipients.', 'neural-forms-lite' ) );
		}
		$form    = $ctx['form'];
		$data    = $this->token_data( $ctx );
		$subject = NotificationService::render_tokens( (string) ( $settings['subject'] ?? '' ), $form, $data, (int) $ctx['entry']['id'] );
		$body    = NotificationService::render_tokens( (string) ( $settings['body'] ?? '' ), $form, $data, (int) $ctx['entry']['id'] );
		$sent    = NotificationService::send_mail( $to, $subject, $body );
		if ( ! $sent ) {
			return ActionResult::retry( __( 'The mail server did not accept the message.', 'neural-forms-lite' ) );
		}
		$this->activity( $ctx, 'workflow.notified', sprintf( __( 'Team notified at %1$s by workflow "%2$s"', 'neural-forms-lite' ), implode( ', ', $to ), (string) $ctx['workflow_name'] ), array( 'to' => $to ) );
		// wp_mail() returning true means the mailer accepted it, not that it was delivered.
		return ActionResult::done( sprintf( __( 'Accepted by the mail server for %s', 'neural-forms-lite' ), implode( ', ', $to ) ) );
	}
}
