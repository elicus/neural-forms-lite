<?php
namespace NeuralForms\Workflows\Actions;

use NeuralForms\Core\Capabilities;
use NeuralForms\Notifications\NotificationService;
use NeuralForms\Quotes\QuoteRepository;
use NeuralForms\Quotes\QuoteService;

defined( 'ABSPATH' ) || exit;

/**
 * 1.2.0 part B (B4): turn a priced entry into a quote from a workflow.
 * Default = a DRAFT for review (nothing leaves the site). "Issue" assigns
 * the number; "Send" emails the issued quote to the visitor — an explicit,
 * deliberate setting, never the default. Skips when the entry has no
 * pricing or already has a live quote.
 */
final class CreateQuoteDraftAction extends AbstractAction {

	public function type(): string { return 'create_quote_draft'; }
	public function label(): string { return __( 'Create a quote', 'neural-forms-lite' ); }
	public function description(): string { return __( 'Builds a quote from the entry\'s pricing. By default it stays a draft for your review; you can also issue it and email it to the visitor.', 'neural-forms-lite' ); }

	public function settings_schema(): array {
		return array(
			array( 'key' => 'issue', 'label' => __( 'Issue immediately (assigns a quote number)', 'neural-forms-lite' ), 'type' => 'checkbox', 'default' => false ),
			array( 'key' => 'send', 'label' => __( 'Email the issued quote to the visitor', 'neural-forms-lite' ), 'type' => 'checkbox', 'default' => false, 'hint' => __( 'Only with "Issue immediately". The email uses the quote template, never AI text.', 'neural-forms-lite' ) ),
			array( 'key' => 'message', 'label' => __( 'Email note (optional)', 'neural-forms-lite' ), 'type' => 'textarea', 'required' => false, 'hint' => __( 'Shown above the quote summary in the email. Tokens: {form_name}, {field_id}.', 'neural-forms-lite' ) ),
		);
	}

	public function validate_settings( array $settings ) {
		$r = parent::validate_settings( $settings );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		if ( ! empty( $settings['send'] ) && empty( $settings['issue'] ) ) {
			return $this->error( __( 'Sending needs "Issue immediately" as well.', 'neural-forms-lite' ) );
		}
		return true;
	}

	public function describe( array $settings, array $ctx ): string {
		if ( ! empty( $settings['send'] ) ) {
			return __( 'Create, issue and email a quote to the visitor', 'neural-forms-lite' );
		}
		return ! empty( $settings['issue'] ) ? __( 'Create and issue a quote', 'neural-forms-lite' ) : __( 'Create a quote draft for review', 'neural-forms-lite' );
	}

	public function execute( array $settings, array $ctx ): ActionResult {
		if ( ! Capabilities::can( Capabilities::QUOTES ) ) {
			return ActionResult::skipped( __( 'Quotes are not available on this site.', 'neural-forms-lite' ) );
		}
		$entry = is_array( $ctx['entry'] ?? null ) ? $ctx['entry'] : array();
		if ( empty( $entry['pricing']['line_items'] ) ) {
			return ActionResult::skipped( __( 'The entry has no pricing to quote.', 'neural-forms-lite' ) );
		}
		foreach ( QuoteRepository::list_for_entry( (int) $entry['id'] ) as $q ) {
			if ( in_array( $q['status'], array( 'draft', 'issued' ), true ) ) {
				return ActionResult::skipped( sprintf( __( 'A %s quote already exists for this entry.', 'neural-forms-lite' ), $q['status'] ) );
			}
		}
		$quote = QuoteService::create_draft( (int) $entry['id'], array(), 0 );
		if ( is_wp_error( $quote ) ) {
			return ActionResult::failed( $quote->get_error_message() );
		}
		$this->activity( $ctx, 'workflow.quote_created', sprintf( __( 'Quote draft created by workflow "%s"', 'neural-forms-lite' ), (string) $ctx['workflow_name'] ), array( 'quote_id' => (int) $quote['id'] ) );
		if ( empty( $settings['issue'] ) ) {
			return ActionResult::done( __( 'Quote draft created for review.', 'neural-forms-lite' ), 'quote:' . (int) $quote['id'] );
		}
		$quote = QuoteService::issue( (int) $quote['id'], 0 );
		if ( is_wp_error( $quote ) ) {
			return ActionResult::failed( $quote->get_error_message() );
		}
		if ( empty( $settings['send'] ) ) {
			return ActionResult::done( sprintf( __( 'Quote %s issued.', 'neural-forms-lite' ), (string) $quote['number'] ), 'quote:' . (int) $quote['id'] );
		}
		$to = NotificationService::submitter_email( $ctx['form'], is_array( $entry['data'] ?? null ) ? $entry['data'] : array() );
		if ( $to === '' ) {
			return ActionResult::done( sprintf( __( 'Quote %s issued; no visitor email to send it to.', 'neural-forms-lite' ), (string) $quote['number'] ), 'quote:' . (int) $quote['id'] );
		}
		$note = NotificationService::render_tokens( (string) ( $settings['message'] ?? '' ), $ctx['form'], $this->token_data( $ctx ), (int) $entry['id'] );
		$sent = QuoteService::send( (int) $quote['id'], $to, $note, 0 );
		if ( is_wp_error( $sent ) ) {
			return ActionResult::retry( $sent->get_error_message() );
		}
		return ActionResult::done( sprintf( __( 'Quote %1$s issued and emailed to %2$s.', 'neural-forms-lite' ), (string) $quote['number'], $to ), 'quote:' . (int) $quote['id'] );
	}
}
