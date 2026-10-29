<?php
/**
 * Quotes REST surface (1.2.0 part B). Namespace: neural-forms/v1
 *
 * Admin (manage_options; writes also need the `quotes` capability):
 *   GET    /quotes/settings                 branding + numbering + pdf_available
 *   PUT    /quotes/settings
 *   GET    /entries/{id}/quotes             newest first
 *   POST   /entries/{id}/quotes             create a draft from the entry's pricing
 *   GET    /quotes/{id}
 *   PUT    /quotes/{id}                     draft edits (lines, discount, deposit, texts, customer, validity_days)
 *   DELETE /quotes/{id}                     drafts only
 *   POST   /quotes/{id}/issue
 *   POST   /quotes/{id}/revise
 *   POST   /quotes/{id}/send                { to, message }
 *   GET    /quotes/{id}/preview             HTML (cookie auth + _wpnonce)
 *
 * Public (token in the query string, see QuoteTokens):
 *   GET    /quotes/{id}/view?token=…        printable HTML
 *   GET    /quotes/{id}/pdf?token=…         PDF when a renderer is registered, else the HTML view
 *
 * Reads of stored quotes work on a locked install (the data belongs to
 * the site); every write returns `pro_feature_locked` without the cap.
 */

namespace NeuralForms\Rest\Endpoints;

use NeuralForms\Core\Capabilities;
use NeuralForms\Quotes\QuotePdf;
use NeuralForms\Quotes\QuoteRenderer;
use NeuralForms\Quotes\QuoteRepository;
use NeuralForms\Quotes\QuoteService;
use NeuralForms\Quotes\QuoteSettings;
use NeuralForms\Quotes\QuotesBoot;
use NeuralForms\Quotes\QuoteTokens;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class QuotesEndpoint {

	public function register_routes(): void {
		$ns = 'neural-forms/v1';
		register_rest_route( $ns, '/quotes/settings', array(
			array( 'methods' => 'GET', 'callback' => array( $this, 'get_settings' ), 'permission_callback' => array( $this, 'check_admin' ) ),
			array( 'methods' => 'PUT', 'callback' => array( $this, 'update_settings' ), 'permission_callback' => array( $this, 'check_write' ) ),
		) );
		register_rest_route( $ns, '/entries/(?P<id>[\d]+)/quotes', array(
			array( 'methods' => 'GET', 'callback' => array( $this, 'list_for_entry' ), 'permission_callback' => array( $this, 'check_admin' ) ),
			array( 'methods' => 'POST', 'callback' => array( $this, 'create' ), 'permission_callback' => array( $this, 'check_write' ) ),
		) );
		register_rest_route( $ns, '/quotes/(?P<id>[\d]+)', array(
			array( 'methods' => 'GET', 'callback' => array( $this, 'get_quote' ), 'permission_callback' => array( $this, 'check_admin' ) ),
			array( 'methods' => 'PUT', 'callback' => array( $this, 'update' ), 'permission_callback' => array( $this, 'check_write' ) ),
			array( 'methods' => 'DELETE', 'callback' => array( $this, 'delete' ), 'permission_callback' => array( $this, 'check_write' ) ),
		) );
		register_rest_route( $ns, '/quotes/(?P<id>[\d]+)/issue', array( 'methods' => 'POST', 'callback' => array( $this, 'issue' ), 'permission_callback' => array( $this, 'check_write' ) ) );
		register_rest_route( $ns, '/quotes/(?P<id>[\d]+)/revise', array( 'methods' => 'POST', 'callback' => array( $this, 'revise' ), 'permission_callback' => array( $this, 'check_write' ) ) );
		register_rest_route( $ns, '/quotes/(?P<id>[\d]+)/send', array( 'methods' => 'POST', 'callback' => array( $this, 'send' ), 'permission_callback' => array( $this, 'check_write' ) ) );
		register_rest_route( $ns, '/quotes/(?P<id>[\d]+)/preview', array( 'methods' => 'GET', 'callback' => array( $this, 'preview' ), 'permission_callback' => array( $this, 'check_admin' ) ) );
		register_rest_route( $ns, '/quotes/(?P<id>[\d]+)/view', array( 'methods' => 'GET', 'callback' => array( $this, 'public_view' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( $ns, '/quotes/(?P<id>[\d]+)/pdf', array( 'methods' => 'GET', 'callback' => array( $this, 'public_pdf' ), 'permission_callback' => '__return_true' ) );
	}

	public function check_admin() {
		return current_user_can( 'manage_options' ) ? true : new WP_Error( 'rest_forbidden', __( 'You do not have permission to do this.', 'neural-forms-lite' ), array( 'status' => 403 ) );
	}

	public function check_write() {
		$admin = $this->check_admin();
		if ( true !== $admin ) {
			return $admin;
		}
		return Capabilities::can( Capabilities::QUOTES ) ? true : Capabilities::locked_error( Capabilities::QUOTES );
	}

	// ── settings ─────────────────────────────────────────────────────

	public function get_settings( WP_REST_Request $request ) {
		return rest_ensure_response( $this->settings_payload() );
	}

	public function update_settings( WP_REST_Request $request ) {
		$raw = $request->get_json_params();
		if ( ! is_array( $raw ) ) {
			$raw = (array) $request->get_params();
		}
		QuoteSettings::update( $raw );
		return rest_ensure_response( $this->settings_payload() );
	}

	private function settings_payload(): array {
		$s                  = QuoteSettings::for_rest();
		$s['pdf_available'] = QuotePdf::available();
		$s['unlocked']      = Capabilities::can( Capabilities::QUOTES );
		return $s;
	}

	// ── per entry ────────────────────────────────────────────────────

	public function list_for_entry( WP_REST_Request $request ) {
		$entry_id = (int) $request['id'];
		return rest_ensure_response( array_map( array( QuoteService::class, 'expose' ), QuoteRepository::list_for_entry( $entry_id ) ) );
	}

	public function create( WP_REST_Request $request ) {
		$entry_id = (int) $request['id'];
		$body     = $request->get_json_params();
		$customer = is_array( $body['customer'] ?? null ) ? $body['customer'] : array();
		$quote    = QuoteService::create_draft( $entry_id, $customer, get_current_user_id() );
		if ( is_wp_error( $quote ) ) {
			return $quote;
		}
		return new WP_REST_Response( QuoteService::expose( $quote ), 201 );
	}

	// ── single quote ─────────────────────────────────────────────────

	public function get_quote( WP_REST_Request $request ) {
		$quote = QuoteRepository::get( (int) $request['id'] );
		return $quote ? rest_ensure_response( QuoteService::expose( $quote ) ) : $this->not_found();
	}

	public function update( WP_REST_Request $request ) {
		$edits = $request->get_json_params();
		if ( ! is_array( $edits ) ) {
			return new WP_Error( 'invalid_body', __( 'Send a JSON body.', 'neural-forms-lite' ), array( 'status' => 400 ) );
		}
		$quote = QuoteService::update_draft( (int) $request['id'], $edits );
		return is_wp_error( $quote ) ? $quote : rest_ensure_response( QuoteService::expose( $quote ) );
	}

	public function delete( WP_REST_Request $request ) {
		$r = QuoteService::delete_draft( (int) $request['id'] );
		return is_wp_error( $r ) ? $r : rest_ensure_response( array( 'deleted' => true ) );
	}

	public function issue( WP_REST_Request $request ) {
		$quote = QuoteService::issue( (int) $request['id'], get_current_user_id() );
		return is_wp_error( $quote ) ? $quote : rest_ensure_response( QuoteService::expose( $quote ) );
	}

	public function revise( WP_REST_Request $request ) {
		$quote = QuoteService::revise( (int) $request['id'], get_current_user_id() );
		return is_wp_error( $quote ) ? $quote : new WP_REST_Response( QuoteService::expose( $quote ), 201 );
	}

	public function send( WP_REST_Request $request ) {
		$body = $request->get_json_params();
		$to   = is_array( $body ) ? (string) ( $body['to'] ?? '' ) : '';
		$msg  = is_array( $body ) ? (string) ( $body['message'] ?? '' ) : '';
		$r    = QuoteService::send( (int) $request['id'], $to, $msg, get_current_user_id() );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$r['quote'] = QuoteService::expose( $r['quote'] );
		return rest_ensure_response( $r );
	}

	public function preview( WP_REST_Request $request ) {
		$quote = QuoteRepository::get( (int) $request['id'] );
		if ( ! $quote ) {
			return $this->not_found();
		}
		$links = QuoteService::links( $quote );
		$html  = QuoteRenderer::html( $quote, array( 'mode' => 'html', 'toolbar' => true, 'pdf_url' => $links['pdf'] ?? '' ) );
		return QuotesBoot::raw_response( $html, 'text/html; charset=UTF-8' );
	}

	// ── public ───────────────────────────────────────────────────────

	public function public_view( WP_REST_Request $request ) {
		$quote  = QuoteRepository::get( (int) $request['id'] );
		$reason = $this->public_check( $quote, $request );
		if ( $reason !== '' ) {
			return $this->public_error( $reason );
		}
		$links = QuoteService::links( $quote );
		$html  = QuoteRenderer::html( $quote, array( 'mode' => 'html', 'toolbar' => true, 'pdf_url' => $links['pdf'] ?? '' ) );
		return QuotesBoot::raw_response( $html, 'text/html; charset=UTF-8' );
	}

	public function public_pdf( WP_REST_Request $request ) {
		$quote  = QuoteRepository::get( (int) $request['id'] );
		$reason = $this->public_check( $quote, $request );
		if ( $reason !== '' ) {
			return $this->public_error( $reason );
		}
		$pdf = QuotePdf::render( $quote );
		if ( null === $pdf ) {
			// D5 fallback: the printable HTML view.
			return $this->public_view( $request );
		}
		return QuotesBoot::raw_response(
			$pdf,
			'application/pdf',
			200,
			array( 'Content-Disposition' => 'attachment; filename="' . QuoteRenderer::filename( $quote ) . '"', 'Content-Length' => (string) strlen( $pdf ) )
		);
	}

	/** '' when the request may see the quote, else a reason code. */
	private function public_check( ?array $quote, WP_REST_Request $request ): string {
		if ( ! $quote ) {
			return 'invalid';
		}
		$token = (string) $request->get_param( 'token' );
		return QuoteTokens::check( $quote, $token );
	}

	private function public_error( string $reason ) {
		$status = 404;
		switch ( $reason ) {
			case 'expired':
				$status = 410;
				$title  = __( 'This quote has expired', 'neural-forms-lite' );
				$text   = __( 'Please contact us for an updated quote.', 'neural-forms-lite' );
				break;
			case 'superseded':
				$status = 410;
				$title  = __( 'This quote has been replaced', 'neural-forms-lite' );
				$text   = __( 'A newer version of this quote was issued. Please use the latest link you received.', 'neural-forms-lite' );
				break;
			case 'not_issued':
				$title = __( 'This quote is not available yet', 'neural-forms-lite' );
				$text  = __( 'It has not been issued. Please check back later.', 'neural-forms-lite' );
				break;
			default:
				$title = __( 'Quote not found', 'neural-forms-lite' );
				$text  = __( 'The link is not valid. Please check the address you received.', 'neural-forms-lite' );
		}
		$e    = array( QuoteRenderer::class, 'esc' );
		$html = '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="robots" content="noindex"><title>' . $e( $title ) . '</title>'
			. '<style>body{margin:0;background:#f3f4f6;font-family:Helvetica,Arial,sans-serif;color:#111827}.box{max-width:480px;margin:80px auto;background:#fff;padding:36px;border-radius:10px;text-align:center}h1{font-size:20px;margin:0 0 10px}p{color:#4b5563;margin:0}</style></head>'
			. '<body><div class="box"><h1>' . $e( $title ) . '</h1><p>' . $e( $text ) . '</p></div></body></html>';
		return QuotesBoot::raw_response( $html, 'text/html; charset=UTF-8', $status );
	}

	private function not_found() {
		return new WP_Error( 'not_found', __( 'Quote not found.', 'neural-forms-lite' ), array( 'status' => 404 ) );
	}
}
