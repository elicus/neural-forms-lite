<?php
namespace NeuralForms\Workflows\Actions;

use NeuralForms\Notifications\NotificationService;
use NeuralForms\Storage\EntryMessageRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Sends a FIXED, administrator-written acknowledgement to the
 * submitter. Deliberately cannot send AI text: the body is the saved
 * template with tokens only, so this action never bypasses human
 * review of AI drafts (see PrepareAiDraftAction).
 */
final class SendAcknowledgementAction extends AbstractAction {

	public function type(): string { return 'send_acknowledgement'; }
	public function label(): string { return __( 'Send a fixed acknowledgement', 'neural-forms-lite' ); }
	public function description(): string { return __( 'Emails the submitter your pre-written text. Tokens: {form_name}, {site_name}, {field_id}. AI text is never sent by this action.', 'neural-forms-lite' ); }

	public function settings_schema(): array {
		return array(
			array( 'key' => 'subject', 'label' => __( 'Subject', 'neural-forms-lite' ), 'type' => 'text', 'required' => true, 'default' => __( 'Thanks for getting in touch', 'neural-forms-lite' ) ),
			array( 'key' => 'body', 'label' => __( 'Message', 'neural-forms-lite' ), 'type' => 'textarea', 'required' => true, 'default' => __( "Hi,\n\nThanks for your enquiry. We will get back to you within one working day.\n\n{site_name}", 'neural-forms-lite' ) ),
		);
	}

	public function describe( array $settings, array $ctx ): string {
		$email = NotificationService::submitter_email( $ctx['form'] ?? array(), is_array( $ctx['entry']['data'] ?? null ) ? $ctx['entry']['data'] : array() );
		return $email !== ''
			? sprintf( __( 'Send the fixed acknowledgement to %s', 'neural-forms-lite' ), $email )
			: __( 'Send the fixed acknowledgement (skipped: this entry has no email address)', 'neural-forms-lite' );
	}

	public function execute( array $settings, array $ctx ): ActionResult {
		$form  = $ctx['form'];
		$data  = is_array( $ctx['entry']['data'] ?? null ) ? $ctx['entry']['data'] : array();
		$email = NotificationService::submitter_email( $form, $data );
		if ( $email === '' ) {
			return ActionResult::skipped( __( 'The entry has no email address.', 'neural-forms-lite' ) );
		}
		$entry_id = (int) $ctx['entry']['id'];
		$subject  = NotificationService::render_tokens( (string) ( $settings['subject'] ?? '' ), $form, $data, $entry_id );
		$body     = NotificationService::render_tokens( (string) ( $settings['body'] ?? '' ), $form, $data, $entry_id );
		$sent     = NotificationService::send_mail( $email, $subject, $body );
		if ( ! $sent ) {
			return ActionResult::retry( __( 'The mail server did not accept the message.', 'neural-forms-lite' ) );
		}
		$sender = NotificationService::resolve_sender( \NeuralForms\Core\Config::all() );
		EntryMessageRepository::create(
			array(
				'entry_id'  => $entry_id,
				'type'      => 'outgoing',
				'sender'    => (string) ( $sender['email'] ?? '' ),
				'recipient' => $email,
				'subject'   => $subject,
				'body'      => $body,
				'meta'      => array( 'status' => 'sent', 'source' => 'workflow', 'workflow_id' => (int) $ctx['workflow_id'], 'run_id' => (int) $ctx['run_id'], 'fixed_template' => true ),
			)
		);
		$this->activity( $ctx, 'workflow.acknowledged', sprintf( __( 'Acknowledgement sent to %1$s by workflow "%2$s"', 'neural-forms-lite' ), $email, (string) $ctx['workflow_name'] ), array( 'to' => $email ) );
		return ActionResult::done( sprintf( __( 'Acknowledgement accepted by the mail server for %s', 'neural-forms-lite' ), $email ) );
	}
}
