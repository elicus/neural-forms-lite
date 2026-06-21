<?php
namespace NeuralForms\Rest\Endpoints;

use NeuralForms\Storage\EntryRepository;
use NeuralForms\Storage\EntryMessageRepository;
use WP_REST_Request;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * GET /entries/{id}/messages
 *
 * Returns all stored messages for a given entry, oldest first.
 * Admin-only (manage_options capability).
 *
 * Response:
 *   Array of message objects:
 *   [
 *     {
 *       id         int
 *       entry_id   int
 *       type       "incoming"|"outgoing"|"note"
 *       sender     string|null
 *       recipient  string|null
 *       subject    string|null
 *       body       string
 *       meta       object
 *       created_at string  UTC datetime
 *     },
 *     ...
 *   ]
 *
 * Error codes:
 *   entry_not_found  404  Entry does not exist.
 */
class MessagesEndpoint {

	public function register_routes(): void {
		$ns = \NeuralForms\Rest\RestAPI::NAMESPACE;

		register_rest_route(
			$ns,
			'/entries/(?P<id>[\d]+)/messages',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_messages' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	// Handler

	public function get_messages( WP_REST_Request $request ) {
		$entry_id = (int) $request['id'];
		$entry    = EntryRepository::get( $entry_id );

		if ( ! $entry ) {
			return new WP_Error(
				'entry_not_found',
				__( 'Entry not found.', 'neural-forms-lite' ),
				array( 'status' => 404 )
			);
		}

		$messages = EntryMessageRepository::list_by_entry( $entry_id );

		return rest_ensure_response( $messages );
	}

	// Permission

	public function check_permission() {
		if ( current_user_can( NEURAL_FORMS_CAP_MANAGE_FORMS ) ) {
			return true;
		}

		return new WP_Error(
			'permission_denied',
			__( 'You do not have permission to view messages.', 'neural-forms-lite' ),
			array( 'status' => 403 )
		);
	}
}
