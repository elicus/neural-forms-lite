<?php
namespace NeuralForms\Rest\Endpoints;

use NeuralForms\Core\Logger;
use NeuralForms\Storage\EntryRepository;
use NeuralForms\Storage\EntryMessageRepository;
use WP_REST_Request;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * POST /entries/{entry_id}/messages/{message_id}/retry
 *
 * Retries sending a previously failed outgoing message.
 * Admin-only (manage_options capability).
 *
 * The stored recipient, subject, and body are reused - no request body needed.
 *
 * On success:
 *   - Calls wp_mail() with the stored data.
 *   - Updates the message meta to { status: "sent" } in-place.
 *   - Returns { retried: true, message_id: int }.
 *
 * On failure:
 *   - Leaves the message meta unchanged (status stays "failed").
 *   - Returns WP_Error so the frontend keeps the "Not delivered" state.
 *
 * Error codes:
 *   entry_not_found    404  Entry does not exist.
 *   message_not_found  404  Message does not exist.
 *   message_mismatch   403  Message does not belong to this entry.
 *   not_retryable      400  Message is not an outgoing failed message.
 *   invalid_recipient  400  Stored recipient is not a valid email address.
 *   retry_send_failed  500  wp_mail() returned false.
 */
class RetryEndpoint {

	public function register_routes(): void {
		$ns = \NeuralForms\Rest\RestAPI::NAMESPACE;

		register_rest_route(
			$ns,
			'/entries/(?P<entry_id>[\d]+)/messages/(?P<message_id>[\d]+)/retry',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'retry_message' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	// Handler

	public function retry_message( WP_REST_Request $request ) {
		$entry_id   = (int) $request['entry_id'];
		$message_id = (int) $request['message_id'];

		$entry   = EntryRepository::get( $entry_id );
		$message = EntryMessageRepository::get( $message_id );

		if ( ! $entry ) {
			return new WP_Error(
				'entry_not_found',
				__( 'Entry not found.', 'neural-forms-lite' ),
				array( 'status' => 404 )
			);
		}

		if ( ! $message ) {
			return new WP_Error(
				'message_not_found',
				__( 'Message not found.', 'neural-forms-lite' ),
				array( 'status' => 404 )
			);
		}

		// Ownership check - prevents retrying messages from a different entry.
		if ( $message['entry_id'] !== $entry_id ) {
			return new WP_Error(
				'message_mismatch',
				__( 'Message does not belong to this entry.', 'neural-forms-lite' ),
				array( 'status' => 403 )
			);
		}

		// Only outgoing messages with status=failed can be retried.
		$meta   = $message['meta'] ?? array();
		$status = $meta['status'] ?? '';

		if ( $message['type'] !== 'outgoing' || $status !== 'failed' ) {
			return new WP_Error(
				'not_retryable',
				__( 'Only failed outgoing messages can be retried.', 'neural-forms-lite' ),
				array( 'status' => 400 )
			);
		}

		$to      = (string) ( $message['recipient'] ?? '' );
		$subject = (string) ( $message['subject'] ?? '' );
		$body    = (string) ( $message['body'] ?? '' );

		if ( ! is_email( $to ) ) {
			return new WP_Error(
				'invalid_recipient',
				__( 'The stored recipient address is not valid.', 'neural-forms-lite' ),
				array( 'status' => 400 )
			);
		}

		if ( $subject === '' ) {
			$subject = __( 'Re: Your submission', 'neural-forms-lite' );
		}

		// Same From / Reply-To as the original reply (before: no headers → "WordPress <wordpress@…>").
		$sent = wp_mail( $to, $subject, $body, \NeuralForms\Notifications\NotificationService::reply_headers() );

		if ( ! $sent ) {
			Logger::log(
				'retry_send_failed',
				'error',
				'Retry: wp_mail() returned false - check WordPress email configuration.',
				array(
					'message_id' => $message_id,
					'to'         => $to,
				),
				$entry_id
			);

			return new WP_Error(
				'retry_send_failed',
				__( 'Could not send the reply. Please check your WordPress email configuration.', 'neural-forms-lite' ),
				array( 'status' => 500 )
			);
		}

		// Success - flip meta.status to 'sent' in-place.
		$new_meta = array_merge( $meta, array( 'status' => 'sent' ) );
		EntryMessageRepository::update_meta( $message_id, $new_meta );

		// A reply was (re)sent - the entry no longer needs a reply.
		EntryRepository::update( $entry_id, array( 'needs_reply' => 0 ) );

		Logger::log(
			'retry_sent',
			'info',
			/* translators: %s: recipient email address */
			sprintf( __( 'Retry sent to %s.', 'neural-forms-lite' ), $to ),
			array(
				'message_id' => $message_id,
				'to'         => $to,
			),
			$entry_id
		);

		return rest_ensure_response(
			array(
				'retried'    => true,
				'message_id' => $message_id,
			)
		);
	}

	// Permission

	public function check_permission() {
		if ( current_user_can( NEURAL_FORMS_CAP_MANAGE_FORMS ) ) {
			return true;
		}

		return new WP_Error(
			'permission_denied',
			__( 'You do not have permission to retry messages.', 'neural-forms-lite' ),
			array( 'status' => 403 )
		);
	}
}
