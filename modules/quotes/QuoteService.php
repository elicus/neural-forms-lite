<?php
/**
 * Quote lifecycle (1.2.0 part B): draft → issued → expired | superseded.
 *
 *   create_draft()  snapshot from the entry's pricing + branding; no number yet
 *   update_draft()  edits (drafts only) → QuoteBuilder::apply_edits()
 *   issue()         assigns the number (first revision), validity date and
 *                   token seed in ONE guarded status transition; marks the
 *                   previous revision superseded
 *   revise()        new draft revision from an issued / expired quote
 *   send()          email with the public link (+ PDF when Pro provides one);
 *                   recorded as an outgoing message + activity. Nothing is
 *                   ever sent automatically.
 *   expire_due()    daily cron
 *
 * Every state change writes a `quote.*` activity row on the entry.
 * A quote never changes the lead stage or payment status by itself.
 */

namespace NeuralForms\Quotes;

use NeuralForms\Forms\FormRepository;
use NeuralForms\Notifications\NotificationService;
use NeuralForms\Storage\EntryActivity;
use NeuralForms\Storage\EntryMessageRepository;
use NeuralForms\Storage\EntryRepository;
use WP_Error;

final class QuoteService {

	/** @return array|WP_Error */
	public static function create_draft( int $entry_id, array $customer = array(), int $user_id = 0 ) {
		$entry = EntryRepository::get( $entry_id );
		if ( ! $entry ) {
			return new WP_Error( 'not_found', __( 'Entry not found.', 'neural-forms-lite' ), array( 'status' => 404 ) );
		}
		$form = FormRepository::find( (int) $entry['form_id'] );
		if ( ! $form ) {
			return new WP_Error( 'not_found', __( 'Form not found.', 'neural-forms-lite' ), array( 'status' => 404 ) );
		}
		$settings = QuoteSettings::get();
		$snapshot = QuoteBuilder::from_entry( $entry, $form, $settings, $customer );
		$snapshot['validity_days'] = (int) $settings['validity_days'];
		$id = QuoteRepository::create(
			array(
				'entry_id'           => $entry_id,
				'form_id'            => (int) $entry['form_id'],
				'status'             => 'draft',
				'revision'           => 1,
				'currency'           => $snapshot['currency'],
				'amount_total_minor' => (int) $snapshot['total'],
				'amount_due_minor'   => (int) $snapshot['due_now'],
				'snapshot'           => $snapshot,
				'created_by'         => $user_id,
			)
		);
		if ( ! $id ) {
			return new WP_Error( 'db_error', __( 'Could not create the quote.', 'neural-forms-lite' ), array( 'status' => 500 ) );
		}
		EntryActivity::record( $entry_id, 'quote.created', __( 'Quote draft created', 'neural-forms-lite' ), array( 'quote_id' => $id, 'by' => $user_id ) );
		return QuoteRepository::get( $id );
	}

	/** @return array|WP_Error */
	public static function update_draft( int $id, array $edits ) {
		$quote = QuoteRepository::get( $id );
		if ( ! $quote ) {
			return new WP_Error( 'not_found', __( 'Quote not found.', 'neural-forms-lite' ), array( 'status' => 404 ) );
		}
		if ( $quote['status'] !== 'draft' ) {
			return new WP_Error( 'quote_locked', __( 'Only draft quotes can be edited. Create a new revision instead.', 'neural-forms-lite' ), array( 'status' => 409 ) );
		}
		$snapshot = QuoteBuilder::apply_edits( $quote['snapshot'], $edits );
		if ( is_string( $snapshot ) ) {
			return new WP_Error( 'invalid_quote', $snapshot, array( 'status' => 422 ) );
		}
		if ( array_key_exists( 'validity_days', $edits ) && is_numeric( $edits['validity_days'] ) ) {
			$snapshot['validity_days'] = max( 1, min( 365, (int) $edits['validity_days'] ) );
		}
		QuoteRepository::update(
			$id,
			array(
				'snapshot'           => $snapshot,
				'currency'           => $snapshot['currency'],
				'amount_total_minor' => (int) $snapshot['total'],
				'amount_due_minor'   => (int) $snapshot['due_now'],
				'notes'              => (string) ( $snapshot['notes'] ?? '' ),
			)
		);
		return QuoteRepository::get( $id );
	}

	/** @return array|WP_Error */
	public static function issue( int $id, int $user_id = 0 ) {
		$quote = QuoteRepository::get( $id );
		if ( ! $quote ) {
			return new WP_Error( 'not_found', __( 'Quote not found.', 'neural-forms-lite' ), array( 'status' => 404 ) );
		}
		if ( $quote['status'] !== 'draft' ) {
			return new WP_Error( 'quote_locked', __( 'This quote has already been issued.', 'neural-forms-lite' ), array( 'status' => 409 ) );
		}
		if ( empty( $quote['snapshot']['line_items'] ) ) {
			return new WP_Error( 'invalid_quote', __( 'Add at least one line before issuing the quote.', 'neural-forms-lite' ), array( 'status' => 422 ) );
		}
		$settings = QuoteSettings::get();
		$days     = (int) ( $quote['snapshot']['validity_days'] ?? $settings['validity_days'] );
		$days     = max( 1, min( 365, $days ) );
		$now      = time();
		$number   = $quote['number'] ?? QuoteNumbering::next( (string) $settings['prefix'] );
		$ok       = QuoteRepository::transition(
			$id,
			'draft',
			'issued',
			array(
				'number'      => $number,
				'valid_until' => gmdate( 'Y-m-d H:i:s', $now + $days * DAY_IN_SECONDS ),
				'token_seed'  => QuoteTokens::new_seed(),
				'issued_by'   => $user_id,
				'issued_at'   => gmdate( 'Y-m-d H:i:s', $now ),
			)
		);
		if ( ! $ok ) {
			return new WP_Error( 'quote_locked', __( 'The quote was issued by someone else a moment ago.', 'neural-forms-lite' ), array( 'status' => 409 ) );
		}
		if ( $quote['supersedes_id'] ) {
			$prev = QuoteRepository::get( (int) $quote['supersedes_id'] );
			if ( $prev && in_array( $prev['status'], array( 'issued', 'expired' ), true ) ) {
				QuoteRepository::update( (int) $prev['id'], array( 'status' => 'superseded' ) );
				if ( class_exists( '\\NeuralForms\\Payments\\PaymentService' ) ) {
					\NeuralForms\Payments\PaymentService::expire_open_for_quote( $prev ); // old-price checkouts die with the revision
				}
			}
		}
		EntryActivity::record(
			(int) $quote['entry_id'],
			'quote.issued',
			sprintf( __( 'Quote %s issued', 'neural-forms-lite' ), $number ),
			array( 'quote_id' => $id, 'number' => $number, 'revision' => (int) $quote['revision'], 'by' => $user_id )
		);
		return QuoteRepository::get( $id );
	}

	/** New draft revision from an issued / expired quote. @return array|WP_Error */
	public static function revise( int $id, int $user_id = 0 ) {
		$quote = QuoteRepository::get( $id );
		if ( ! $quote ) {
			return new WP_Error( 'not_found', __( 'Quote not found.', 'neural-forms-lite' ), array( 'status' => 404 ) );
		}
		if ( ! in_array( $quote['status'], array( 'issued', 'expired' ), true ) ) {
			return new WP_Error( 'quote_state', __( 'Only issued or expired quotes can be revised.', 'neural-forms-lite' ), array( 'status' => 409 ) );
		}
		foreach ( QuoteRepository::list_for_entry( (int) $quote['entry_id'] ) as $other ) {
			if ( $other['status'] === 'draft' && (int) $other['supersedes_id'] === $id ) {
				return new WP_Error( 'quote_state', __( 'A draft revision of this quote already exists.', 'neural-forms-lite' ), array( 'status' => 409 ) );
			}
		}
		$snapshot             = $quote['snapshot'];
		$snapshot['business'] = QuoteBuilder::business_block( QuoteSettings::get() );
		$new_id               = QuoteRepository::create(
			array(
				'entry_id'           => (int) $quote['entry_id'],
				'form_id'            => (int) $quote['form_id'],
				'number'             => $quote['number'],
				'revision'           => (int) $quote['revision'] + 1,
				'supersedes_id'      => $id,
				'status'             => 'draft',
				'currency'           => $quote['currency'],
				'amount_total_minor' => (int) $quote['amount_total_minor'],
				'amount_due_minor'   => (int) $quote['amount_due_minor'],
				'snapshot'           => $snapshot,
				'notes'              => $quote['notes'],
				'created_by'         => $user_id,
			)
		);
		if ( ! $new_id ) {
			return new WP_Error( 'db_error', __( 'Could not create the revision.', 'neural-forms-lite' ), array( 'status' => 500 ) );
		}
		EntryActivity::record( (int) $quote['entry_id'], 'quote.revised', sprintf( __( 'Revision %d of quote %s started', 'neural-forms-lite' ), (int) $quote['revision'] + 1, (string) $quote['number'] ), array( 'quote_id' => $new_id, 'supersedes_id' => $id, 'by' => $user_id ) );
		return QuoteRepository::get( $new_id );
	}

	/** @return true|WP_Error */
	public static function delete_draft( int $id ) {
		$quote = QuoteRepository::get( $id );
		if ( ! $quote ) {
			return new WP_Error( 'not_found', __( 'Quote not found.', 'neural-forms-lite' ), array( 'status' => 404 ) );
		}
		if ( $quote['status'] !== 'draft' ) {
			return new WP_Error( 'quote_locked', __( 'Issued quotes are kept as a record and cannot be deleted.', 'neural-forms-lite' ), array( 'status' => 409 ) );
		}
		QuoteRepository::delete( $id );
		EntryActivity::record( (int) $quote['entry_id'], 'quote.deleted', __( 'Quote draft deleted', 'neural-forms-lite' ), array( 'quote_id' => $id ) );
		return true;
	}

	/**
	 * Email the quote to the customer. @return array|WP_Error  { sent, message_id, quote }
	 */
	public static function send( int $id, string $to, string $message = '', int $user_id = 0 ) {
		$quote = QuoteRepository::get( $id );
		if ( ! $quote ) {
			return new WP_Error( 'not_found', __( 'Quote not found.', 'neural-forms-lite' ), array( 'status' => 404 ) );
		}
		if ( $quote['status'] !== 'issued' ) {
			return new WP_Error( 'quote_state', __( 'Issue the quote before sending it.', 'neural-forms-lite' ), array( 'status' => 409 ) );
		}
		$to = sanitize_email( $to );
		if ( ! is_email( $to ) ) {
			return new WP_Error( 'invalid_email', __( 'Enter a valid recipient email address.', 'neural-forms-lite' ), array( 'status' => 422 ) );
		}
		$links = self::links( $quote );
		if ( empty( $links['view'] ) ) {
			return new WP_Error( 'quote_state', __( 'This quote has no active link (it may have expired).', 'neural-forms-lite' ), array( 'status' => 409 ) );
		}
		$settings = QuoteSettings::get();
		$biz      = (string) ( $quote['snapshot']['business']['name'] ?? '' );
		if ( $biz === '' ) {
			$biz = (string) get_bloginfo( 'name' );
		}
		$fmt     = QuoteBuilder::formatted( $quote['snapshot'] );
		$subject = sprintf( __( 'Quote %1$s from %2$s', 'neural-forms-lite' ), (string) $quote['number'], $biz );
		$name    = (string) ( $quote['snapshot']['customer']['name'] ?? '' );
		$lines   = array();
		$lines[] = $name !== '' ? sprintf( __( 'Hi %s,', 'neural-forms-lite' ), $name ) : __( 'Hello,', 'neural-forms-lite' );
		$lines[] = '';
		if ( trim( $message ) !== '' ) {
			$lines[] = sanitize_textarea_field( $message );
			$lines[] = '';
		}
		$lines[] = sprintf( __( 'Please find quote %1$s attached. Total: %2$s.', 'neural-forms-lite' ), (string) $quote['number'], $fmt['total'] );
		if ( (int) ( $quote['snapshot']['remaining'] ?? 0 ) > 0 ) {
			$lines[] = sprintf( __( 'Due now: %1$s. Remaining balance: %2$s.', 'neural-forms-lite' ), $fmt['due_now'], $fmt['remaining'] );
		}
		if ( $quote['valid_until'] ) {
			$lines[] = sprintf( __( 'This quote is valid until %s.', 'neural-forms-lite' ), wp_date( (string) get_option( 'date_format', 'F j, Y' ), strtotime( $quote['valid_until'] . ' UTC' ) ) );
		}
		$lines[] = '';
		$lines[] = __( 'View the quote online:', 'neural-forms-lite' );
		$lines[] = $links['view'];
		if ( ! empty( $links['pay'] ) ) {
			$lines[] = '';
			$lines[] = sprintf( __( 'Pay %s securely online:', 'neural-forms-lite' ), $fmt['due_now'] );
			$lines[] = $links['pay'];
		}
		$lines[] = '';
		$lines[] = $biz;
		if ( (string) ( $settings['email'] ?? '' ) !== '' ) {
			$lines[] = (string) $settings['email'];
		}
		$body = implode( "\n", $lines );

		$attachments = array();
		$tmp         = null;
		$pdf         = QuotePdf::render( $quote );
		if ( is_string( $pdf ) ) {
			$tmp = wp_tempnam( QuoteRenderer::filename( $quote ) );
			if ( $tmp ) {
				$named = dirname( $tmp ) . '/' . QuoteRenderer::filename( $quote );
				// Use the real filename when the temp dir allows it so the attachment is named after the quote.
				if ( ! file_exists( $named ) && @rename( $tmp, $named ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					$tmp = $named;
				}
				file_put_contents( $tmp, $pdf ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				$attachments[] = $tmp;
			}
		}
		$sender  = NotificationService::resolve_sender( \NeuralForms\Core\Config::all() );
		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
		if ( $sender['email'] !== '' ) {
			$headers[] = 'Reply-To: ' . ( $sender['name'] !== '' ? $sender['name'] . ' <' . $sender['email'] . '>' : $sender['email'] );
		}
		$sent = NotificationService::send_mail( $to, $subject, $body, $headers, $attachments );
		if ( $tmp && file_exists( $tmp ) ) {
			wp_delete_file( $tmp );
		}
		$message_id = EntryMessageRepository::create(
			array(
				'entry_id'  => (int) $quote['entry_id'],
				'type'      => 'outgoing',
				'sender'    => $sender['email'] !== '' ? $sender['email'] : (string) get_bloginfo( 'admin_email' ),
				'recipient' => $to,
				'subject'   => $subject,
				'body'      => $body,
				'meta'      => array( 'status' => $sent ? 'sent' : 'failed', 'quote_id' => $id, 'quote_number' => (string) $quote['number'], 'reviewed_by' => $user_id, 'pdf_attached' => ! empty( $attachments ) ),
			)
		);
		if ( ! $sent ) {
			EntryActivity::record( (int) $quote['entry_id'], 'quote.send_failed', sprintf( __( 'Quote %s could not be emailed', 'neural-forms-lite' ), (string) $quote['number'] ), array( 'quote_id' => $id, 'to' => $to, 'by' => $user_id ), 'error' );
			return new WP_Error( 'send_failed', __( 'The email could not be sent. Check the site email configuration.', 'neural-forms-lite' ), array( 'status' => 424 ) );
		}
		QuoteRepository::update( $id, array( 'sent_at' => gmdate( 'Y-m-d H:i:s' ) ) );
		EntryActivity::record( (int) $quote['entry_id'], 'quote.sent', sprintf( __( 'Quote %1$s emailed to %2$s', 'neural-forms-lite' ), (string) $quote['number'], $to ), array( 'quote_id' => $id, 'to' => $to, 'by' => $user_id ) );
		return array( 'sent' => true, 'message_id' => is_wp_error( $message_id ) ? null : $message_id, 'quote' => QuoteRepository::get( $id ) );
	}

	/** Public links for an issued quote; empty array when none can be derived. */
	public static function links( array $quote ): array {
		if ( ( $quote['status'] ?? '' ) !== 'issued' || empty( $quote['token_seed'] ) ) {
			return array();
		}
		$token = QuoteTokens::derive( (int) $quote['id'], (int) $quote['revision'], (string) $quote['token_seed'] );
		if ( $token === '' ) {
			return array();
		}
		$base = rest_url( 'neural-forms/v1/quotes/' . (int) $quote['id'] );
		$out  = array( 'view' => add_query_arg( 'token', $token, $base . '/view' ) );
		if ( QuotePdf::available() ) {
			$out['pdf'] = add_query_arg( 'token', $token, $base . '/pdf' );
		}
		if ( self::is_payable( $quote ) ) {
			$out['pay'] = add_query_arg( 'token', $token, $base . '/pay' );
		}
		return $out;
	}

	/** Daily cron. @return int quotes expired */
	public static function expire_due(): int {
		$n = 0;
		foreach ( QuoteRepository::issued_past_validity( gmdate( 'Y-m-d H:i:s' ) ) as $id ) {
			if ( QuoteRepository::transition( $id, 'issued', 'expired' ) ) {
				$q = QuoteRepository::get( $id );
				if ( $q && class_exists( '\\NeuralForms\\Payments\\PaymentService' ) ) {
					\NeuralForms\Payments\PaymentService::expire_open_for_quote( $q );
				}
				if ( $q ) {
					EntryActivity::record( (int) $q['entry_id'], 'quote.expired', sprintf( __( 'Quote %s expired', 'neural-forms-lite' ), (string) $q['number'] ), array( 'quote_id' => $id, 'by' => 0 ) );
				}
				$n++;
			}
		}
		return $n;
	}

	/**
	 * B4: the quote can be paid online right now — issued + valid (CQ9),
	 * something due, the form takes payments, a provider is connected and
	 * no settled payment exists for this quote yet.
	 */
	public static function is_payable( array $quote ): bool {
		if ( ! self::can_start_payment( $quote ) || (int) ( $quote['snapshot']['due_now'] ?? 0 ) <= 0 ) {
			return false;
		}
		if ( ! class_exists( '\\NeuralForms\\Payments\\PaymentService' ) ) {
			return false;
		}
		$form = FormRepository::find( (int) $quote['form_id'] );
		if ( ! $form || ! \NeuralForms\Payments\PaymentService::enabled_for( $form ) ) {
			return false;
		}
		foreach ( \NeuralForms\Payments\PaymentRepository::list_for_entry( (int) $quote['entry_id'] ) as $p ) {
			if ( (int) ( $p['quote_id'] ?? 0 ) === (int) $quote['id'] && in_array( $p['status'], \NeuralForms\Payments\PaymentStatus::SETTLED, true ) ) {
				return false;
			}
		}
		return true;
	}

	/** CQ9: only an issued, unexpired quote may start a payment. */
	public static function can_start_payment( array $quote, ?int $now = null ): bool {
		if ( ( $quote['status'] ?? '' ) !== 'issued' ) {
			return false;
		}
		$now   = $now ?? time();
		$until = ! empty( $quote['valid_until'] ) ? strtotime( $quote['valid_until'] . ' UTC' ) : false;
		return ! ( $until !== false && $until < $now );
	}

	/** REST shape: never the seed; adds formatted amounts, links and labels. */
	public static function expose( array $quote ): array {
		$links = self::links( $quote );
		unset( $quote['token_seed'] );
		$quote['formatted']     = QuoteBuilder::formatted( $quote['snapshot'] );
		$quote['links']         = $links;
		$quote['status_label']  = QuoteRenderer::status_label( (string) $quote['status'] );
		$quote['pdf_available'] = QuotePdf::available();
		$quote['validity_days'] = (int) ( $quote['snapshot']['validity_days'] ?? QuoteSettings::get()['validity_days'] );
		$quote['is_expired']    = $quote['status'] === 'issued' && ! self::can_start_payment( $quote );
		return $quote;
	}
}
