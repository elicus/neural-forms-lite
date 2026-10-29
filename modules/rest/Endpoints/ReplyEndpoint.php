<?php
namespace NeuralForms\Rest\Endpoints;

use NeuralForms\Core\Config;
use NeuralForms\Core\Logger;
use NeuralForms\Forms\FormRepository;
use NeuralForms\Notifications\NotificationService;
use NeuralForms\Storage\EntryRepository;
use NeuralForms\Storage\EntryMessageRepository;
use WP_REST_Request;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * POST /entries/{id}/reply
 *
 * Sends a plain-text email reply using wp_mail().
 * Admin-only (manage_options capability).
 *
 * Request body:
 *   to      string  required  Recipient email address (validated with is_email()).
 *   subject string  optional  Defaults to the Email Settings "Default reply
 *                             subject" (reply_subject_pattern; tokens
 *                             {site_name} / {form_name}) when absent.
 *   body    string  required  Plain-text message body.
 *
 * Response:
 *   { sent: true, to: "recipient@example.com" }
 *
 * Both success and failure are written to neural_forms_logs so they are auditable
 * without tailing server error logs.
 *
 * Error codes:
 *   entry_not_found   404  Entry does not exist.
 *   invalid_recipient 400  `to` is not a valid email address.
 *   empty_body        400  `body` is empty after sanitization.
 *   reply_send_failed 500  wp_mail() returned false.
 */
class ReplyEndpoint {

	public function register_routes(): void {
		$ns = \NeuralForms\Rest\RestAPI::NAMESPACE;

		register_rest_route(
			$ns,
			'/entries/(?P<id>[\d]+)/reply',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'send_reply' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => $this->post_args(),
			)
		);
	}

	// Handler

	public function send_reply( WP_REST_Request $request ) {
		$entry_id = (int) $request['id'];
		$entry    = EntryRepository::get( $entry_id );

		if ( ! $entry ) {
			return new WP_Error(
				'entry_not_found',
				__( 'Entry not found.', 'neural-forms-lite' ),
				array( 'status' => 404 )
			);
		}

		$to      = sanitize_email( (string) $request->get_param( 'to' ) );
		$subject = sanitize_text_field( (string) ( $request->get_param( 'subject' ) ?? '' ) );
		$body    = sanitize_textarea_field( (string) $request->get_param( 'body' ) );

		// Guard: validate address again server-side in case args validation was bypassed.
		if ( ! is_email( $to ) ) {
			return new WP_Error(
				'invalid_recipient',
				__( 'The recipient email address is not valid.', 'neural-forms-lite' ),
				array( 'status' => 400 )
			);
		}

		if ( $body === '' ) {
			return new WP_Error(
				'empty_body',
				__( 'Reply body cannot be empty.', 'neural-forms-lite' ),
				array( 'status' => 400 )
			);
		}

		$settings = Config::all();
		$form     = FormRepository::find( (int) ( $entry['form_id'] ?? 0 ) );

		if ( $subject === '' ) {
			$subject = self::default_subject( $settings, is_array( $form ) ? $form : null );
		}

		// Same From identity as notifications / autoresponder / test email
		// (Sender name + email with the site-title / admin-email defaults).
		// Before 1.1.3 no headers were sent at all, so replies went out as
		// "WordPress <wordpress@domain>". Reply-To is set to the same
		// sender so that a mail plugin that forces a fixed From address
		// still routes the customer's answer back to the business.
		$sender  = NotificationService::resolve_sender( $settings );
		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
		$headers = array_merge( $headers, NotificationService::build_from_headers( $settings ) );
		if ( $sender['email'] !== '' ) {
			$headers[] = 'Reply-To: ' . ( $sender['name'] !== '' ? $sender['name'] . ' <' . $sender['email'] . '>' : $sender['email'] );
		}
		$sender_email = $sender['email'] !== '' ? $sender['email'] : (string) get_bloginfo( 'admin_email' );

		$sent = wp_mail( $to, $subject, $body, $headers );

		if ( ! $sent ) {
			Logger::log(
				'reply_send_failed',
				'error',
				'wp_mail() returned false - check WordPress email configuration.',
				array(
					'to'      => $to,
					'subject' => $subject,
				),
				$entry_id
			);

			// Persist the failed attempt so it survives a page reload and can
			// be retried. The saved ID is returned in the error data so the
			// frontend can wire the optimistic bubble to the real DB row.
			$failed_result = EntryMessageRepository::create(
				array(
					'entry_id'  => $entry_id,
					'type'      => 'outgoing',
					'sender'    => $sender_email,
					'recipient' => $to,
					'subject'   => $subject,
					'body'      => $body,
					'meta'      => array( 'status' => 'failed' ),
				)
			);

			$failed_message_id = is_wp_error( $failed_result ) ? null : $failed_result;

			return new WP_Error(
				'reply_send_failed',
				__( 'Could not send the reply. Please check your WordPress email configuration.', 'neural-forms-lite' ),
				array(
					'status'     => 500,
					'message_id' => $failed_message_id,
				)
			);
		}

		Logger::log(
			'reply_sent',
			'info',
			/* translators: %s: recipient email address */
			sprintf( __( 'Reply sent to %s.', 'neural-forms-lite' ), $to ),
			array(
				'to'      => $to,
				'subject' => $subject,
			),
			$entry_id
		);

		// A reply was sent - the entry no longer needs a reply.
		EntryRepository::update( $entry_id, array( 'needs_reply' => 0 ) );

		// Persist the outgoing message so it appears in the thread view.
		// 1.2.0: who reviewed/sent it and which AI draft (if any) it came from.
		$source_response_id = (int) $request->get_param( 'source_response_id' );
		$message_result     = EntryMessageRepository::create(
			array(
				'entry_id'  => $entry_id,
				'type'      => 'outgoing',
				'sender'    => $sender_email,
				'recipient' => $to,
				'subject'   => $subject,
				'body'      => $body,
				'meta'      => array_filter(
					array(
						'status'             => 'sent',
						'reviewed_by'        => function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0,
						'source_response_id' => $source_response_id > 0 ? $source_response_id : null,
					),
					static function ( $v ) { return null !== $v; }
				),
			)
		);
		if ( class_exists( '\\NeuralForms\\Storage\\EntryActivity' ) ) {
			\NeuralForms\Storage\EntryActivity::record( $entry_id, 'entry.reply_sent', sprintf( __( 'Reply sent to %s', 'neural-forms-lite' ), $to ), array( 'message_id' => is_wp_error( $message_result ) ? null : $message_result, 'source_response_id' => $source_response_id > 0 ? $source_response_id : null ) );
		}

		$message_id = is_wp_error( $message_result ) ? null : $message_result;

		return rest_ensure_response(
			array(
				'sent'       => true,
				'to'         => $to,
				'message_id' => $message_id,
			)
		);
	}

	/**
	 * Default reply subject from Email Settings.
	 *
	 * `reply_subject_pattern` (Settings > Email > "Default reply subject")
	 * with {site_name} / {form_name} tokens resolved. Falls back to
	 * "Re: Your submission" when the setting is blank. Before 1.1.3 the
	 * setting was saved but never read.
	 *
	 * @param array      $settings  Plugin settings (Config::all()).
	 * @param array|null $form      Form row, for {form_name}.
	 */
	public static function default_subject( array $settings, ?array $form ): string {
		$pattern = trim( (string) ( $settings['reply_subject_pattern'] ?? '' ) );
		if ( $pattern === '' ) {
			return __( 'Re: Your submission', 'neural-forms-lite' );
		}
		$site_name = (string) get_bloginfo( 'name' );
		if ( function_exists( 'wp_specialchars_decode' ) ) {
			$site_name = wp_specialchars_decode( $site_name, ENT_QUOTES );
		}
		$subject = str_replace(
			array( '{site_name}', '{form_name}' ),
			array( $site_name, (string) ( $form['name'] ?? '' ) ),
			$pattern
		);
		// Header-safe: a subject must be a single line.
		return trim( (string) preg_replace( '/[\r\n]+/', ' ', $subject ) );
	}

	// Permission

	public function check_permission() {
		if ( current_user_can( NEURAL_FORMS_CAP_MANAGE_FORMS ) ) {
			return true;
		}

		return new WP_Error(
			'permission_denied',
			__( 'You do not have permission to send replies.', 'neural-forms-lite' ),
			array( 'status' => 403 )
		);
	}

	// Arg definitions

	private function post_args(): array {
		return array(
			'to'      => array(
				'required'          => true,
				'sanitize_callback' => 'sanitize_email',
				'validate_callback' => static function ( $value ): bool {
					return is_email( sanitize_email( (string) $value ) );
				},
			),
			'subject' => array(
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'body'    => array(
				'required'          => true,
				'sanitize_callback' => 'sanitize_textarea_field',
				'validate_callback' => static function ( $value ): bool {
					return trim( (string) $value ) !== '';
				},
			),
		);
	}
}
