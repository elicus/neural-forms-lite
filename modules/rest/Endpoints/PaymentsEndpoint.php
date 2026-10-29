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
