<?php
/**
 * Payments REST surface (1.2.0 part B). Namespace: neural-forms/v1
 *
 * Admin (manage_options):
 *   GET  /payments/providers                 registered providers (never secrets) + capability
 *   GET  /entries/{id}/payments              aggregate + attempts + events
 *   POST /payments/{id}/check                reconcile now (needs `payments` cap)
 *
 * Public:
 *   GET  /payments/{id}/return?token&result  buyer return page (raw HTML; reads stored state)
 *   POST /payments/webhook/{provider}        gateway webhooks; the provider verifies the signature
 */

namespace NeuralForms\Rest\Endpoints;

use NeuralForms\Core\Capabilities;
use NeuralForms\Payments\PaymentProviderRegistry;
use NeuralForms\Payments\PaymentRepository;
use NeuralForms\Payments\PaymentService;
use NeuralForms\Payments\PaymentTokens;
use NeuralForms\Payments\InvoiceRenderer;
use NeuralForms\Quotes\QuotePdf;
use NeuralForms\Quotes\QuotesBoot;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class PaymentsEndpoint {

	public function register_routes(): void {
		$ns = 'neural-forms/v1';
		register_rest_route( $ns, '/payments/providers', array( 'methods' => 'GET', 'callback' => array( $this, 'providers' ), 'permission_callback' => array( $this, 'check_admin' ) ) );
		register_rest_route( $ns, '/entries/(?P<id>[\d]+)/payments', array( 'methods' => 'GET', 'callback' => array( $this, 'for_entry' ), 'permission_callback' => array( $this, 'check_admin' ) ) );
		register_rest_route( $ns, '/payments/(?P<id>[\d]+)/check', array( 'methods' => 'POST', 'callback' => array( $this, 'check' ), 'permission_callback' => array( $this, 'check_write' ) ) );
		register_rest_route( $ns, '/payments/(?P<id>[\d]+)/return', array( 'methods' => 'GET', 'callback' => array( $this, 'return_page' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( $ns, '/payments', array( 'methods' => 'GET', 'callback' => array( $this, 'list_payments' ), 'permission_callback' => array( $this, 'check_admin' ) ) );
		register_rest_route( $ns, '/payments/(?P<id>[\d]+)/refund', array( 'methods' => 'POST', 'callback' => array( $this, 'refund' ), 'permission_callback' => array( $this, 'check_write' ) ) );
		register_rest_route( $ns, '/payments/(?P<id>[\d]+)/invoice', array( 'methods' => 'GET', 'callback' => array( $this, 'invoice' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( $ns, '/payments/(?P<id>[\d]+)/invoice/send', array( 'methods' => 'POST', 'callback' => array( $this, 'send_invoice' ), 'permission_callback' => array( $this, 'check_write' ) ) );
		register_rest_route( $ns, '/payments/webhook/(?P<provider>[a-z0-9_]+)', array( 'methods' => 'POST', 'callback' => array( $this, 'webhook' ), 'permission_callback' => '__return_true' ) );
	}

	public function check_admin() {
		return current_user_can( 'manage_options' ) ? true : new WP_Error( 'rest_forbidden', __( 'You do not have permission to do this.', 'neural-forms-lite' ), array( 'status' => 403 ) );
	}

	public function check_write() {
		$a = $this->check_admin();
		if ( true !== $a ) {
			return $a;
		}
		return Capabilities::can( Capabilities::PAYMENTS ) ? true : Capabilities::locked_error( Capabilities::PAYMENTS );
	}

	public function providers( WP_REST_Request $request ) {
		return rest_ensure_response(
			array(
				'unlocked'  => Capabilities::can( Capabilities::PAYMENTS ),
				'providers' => PaymentProviderRegistry::for_rest(),
				'webhooks'  => array_map( static function ( $id ) { return rest_url( 'neural-forms/v1/payments/webhook/' . $id ); }, array_combine( array_keys( PaymentProviderRegistry::all() ), array_keys( PaymentProviderRegistry::all() ) ) ?: array() ),
			)
		);
	}

	public function for_entry( WP_REST_Request $request ) {
		$summary = PaymentService::entry_summary( (int) $request['id'] );
		foreach ( $summary['attempts'] as &$a ) {
			$a['events'] = PaymentRepository::list_events_for_payment( (int) $a['id'], 20 );
		}
		unset( $a );
		return rest_ensure_response( $summary );
	}

	public function check( WP_REST_Request $request ) {
		$payment = PaymentRepository::get( (int) $request['id'] );
		if ( ! $payment ) {
			return new WP_Error( 'not_found', __( 'Payment not found.', 'neural-forms-lite' ), array( 'status' => 404 ) );
		}
		$r = PaymentService::reconcile( $payment );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		return rest_ensure_response( array_merge( $r, array( 'summary' => PaymentService::entry_summary( (int) $payment['entry_id'] ) ) ) );
	}

	/** Payments page: filters + paging + per-currency totals. */
	public function list_payments( WP_REST_Request $request ) {
		$args = array();
		foreach ( array( 'status', 'provider', 'form_id', 'kind', 'date_from', 'date_to', 'search', 'page', 'per_page' ) as $k ) {
			$v = $request->get_param( $k );
			if ( null !== $v && $v !== '' ) {
				$args[ $k ] = is_scalar( $v ) ? (string) $v : '';
			}
		}
		$r    = PaymentRepository::search( $args );
		$rows = array();
		foreach ( $r['rows'] as $p ) {
			$x                 = PaymentService::expose( $p );
			$x['entry_url']    = admin_url( 'admin.php?page=neural-forms-entries&form_id=' . (int) $p['form_id'] . '&view=inbox&entry_id=' . (int) $p['entry_id'] );
			$x['invoice_links'] = in_array( $p['status'], \NeuralForms\Payments\PaymentStatus::SETTLED, true ) ? PaymentService::invoice_links( $p ) : array();
			$rows[]            = $x;
		}
		$res = rest_ensure_response( array( 'payments' => $rows, 'total' => $r['total'], 'totals' => PaymentRepository::totals( $args ), 'providers' => \NeuralForms\Payments\PaymentProviderRegistry::for_rest(), 'unlocked' => Capabilities::can( Capabilities::PAYMENTS ), 'pdf_available' => QuotePdf::available() ) );
		$res->header( 'X-Total-Count', (string) $r['total'] );
		return $res;
	}

	public function refund( WP_REST_Request $request ) {
		$body   = $request->get_json_params();
		$amount = is_array( $body ) && isset( $body['amount_minor'] ) && is_numeric( $body['amount_minor'] ) ? (int) $body['amount_minor'] : 0;
		$reason = is_array( $body ) ? (string) ( $body['reason'] ?? '' ) : '';
		$r      = PaymentService::refund( (int) $request['id'], $amount, $reason, get_current_user_id() );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		return rest_ensure_response( $r + array( 'summary' => PaymentService::entry_summary( (int) $r['payment']['entry_id'] ) ) );
	}

	/**
	 * Invoice document. Public with the payment token (customer link) or
	 * for a logged-in admin (cookie auth + _wpnonce; preview from the UI).
	 */
	public function invoice( WP_REST_Request $request ) {
		$payment = PaymentRepository::get( (int) $request['id'] );
		$token   = (string) $request->get_param( 'token' );
		$allowed = $payment && ( ( $token !== '' && PaymentTokens::check( $payment, $token ) ) || current_user_can( 'manage_options' ) );
		if ( ! $allowed || ! in_array( $payment['status'], \NeuralForms\Payments\PaymentStatus::SETTLED, true ) ) {
			return QuotesBoot::raw_response( '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="robots" content="noindex"><title>Not found</title></head><body style="font-family:sans-serif;text-align:center;padding:60px"><h1>' . esc_html__( 'Invoice not found', 'neural-forms-lite' ) . '</h1></body></html>', 'text/html; charset=UTF-8', 404 );
		}
		$payment = PaymentService::ensure_invoice_number( $payment );
		if ( (string) $request->get_param( 'format' ) === 'pdf' ) {
			$pdf = PaymentService::invoice_pdf( $payment );
			if ( is_string( $pdf ) ) {
				return QuotesBoot::raw_response( $pdf, 'application/pdf', 200, array( 'Content-Disposition' => 'attachment; filename="' . InvoiceRenderer::filename( $payment ) . '"', 'Content-Length' => (string) strlen( $pdf ) ) );
			}
		}
		$links = PaymentService::invoice_links( $payment );
		return QuotesBoot::raw_response( PaymentService::invoice_html( $payment, array( 'mode' => 'html', 'toolbar' => true, 'pdf_url' => $links['pdf'] ?? '' ) ), 'text/html; charset=UTF-8' );
	}

	public function send_invoice( WP_REST_Request $request ) {
		$body = $request->get_json_params();
		$to   = is_array( $body ) ? (string) ( $body['to'] ?? '' ) : '';
		$msg  = is_array( $body ) ? (string) ( $body['message'] ?? '' ) : '';
		$r    = PaymentService::send_invoice( (int) $request['id'], $to, $msg, get_current_user_id() );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$r['payment'] = PaymentService::expose( $r['payment'] );
		return rest_ensure_response( $r );
	}

	public function return_page( WP_REST_Request $request ) {
		$payment = PaymentRepository::get( (int) $request['id'] );
		if ( ! $payment || ! PaymentTokens::check( $payment, (string) $request->get_param( 'token' ) ) ) {
			return QuotesBoot::raw_response( '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="robots" content="noindex"><title>Not found</title></head><body style="font-family:sans-serif;text-align:center;padding:60px"><h1>' . esc_html__( 'Payment not found', 'neural-forms-lite' ) . '</h1></body></html>', 'text/html; charset=UTF-8', 404 );
		}
		$result = (string) $request->get_param( 'result' ) === 'cancel' ? 'cancel' : 'success';
		$view   = PaymentService::return_view( $payment, $result );
		if ( ! empty( $view['redirect'] ) ) {
			return QuotesBoot::raw_response( '', 'text/html; charset=UTF-8', 302, array( 'Location' => $view['redirect'] ) );
		}
		return QuotesBoot::raw_response( $view['html'], 'text/html; charset=UTF-8' );
	}

	public function webhook( WP_REST_Request $request ) {
		$provider_id = (string) $request['provider'];
		$provider    = PaymentProviderRegistry::get( $provider_id );
		if ( ! $provider ) {
			return new WP_Error( 'unknown_provider', 'Unknown provider.', array( 'status' => 404 ) );
		}
		$event = $provider->verify_webhook( $request );
		if ( is_wp_error( $event ) ) {
			$data = $event->get_error_data();
			return new WP_Error( $event->get_error_code(), $event->get_error_message(), array( 'status' => is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400 ) );
		}
		$r = PaymentService::handle_event( $provider_id, $event );
		return new WP_REST_Response( array( 'received' => true ) + $r, 200 );
	}
}
