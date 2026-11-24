<?php
namespace NeuralForms\Rest\Endpoints;

use NeuralForms\Core\Capabilities;
use NeuralForms\Forms\FormRepository;
use NeuralForms\Pricing\Coupons;
use NeuralForms\Pricing\PriceEngine;
use WP_REST_Request;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Coupon codes (1.3.0, Pro via `calculations`).
 *
 *   POST /forms/{id}/coupon          public; { code } → { coupon } or 422
 *   GET  /forms/{id}/coupons/usage   manage forms; { coupon_id: uses }
 *
 * The public route answers for one typed code at a time and only for an
 * active form that prices something, Coupons::TRY_CAP tries per IP per
 * form per 10 minutes (429 after that), so codes can't be guessed in bulk.
 * Applying a code here changes nothing server-side: SubmitEndpoint checks
 * it again and the entry's pricing snapshot is what counts as a use.
 */
class CouponsEndpoint {

	public function register_routes(): void {
		$ns = \NeuralForms\Rest\RestAPI::NAMESPACE;

		register_rest_route(
			$ns,
			'/forms/(?P<id>\d+)/coupon',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'check' ),
				'permission_callback' => '__return_true',
				'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
			)
		);

		register_rest_route(
			$ns,
			'/forms/(?P<id>\d+)/coupons/usage',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'usage' ),
				'permission_callback' => static function (): bool {
					return current_user_can( NEURAL_FORMS_CAP_MANAGE_FORMS );
				},
				'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
			)
		);
	}

	public function check( WP_REST_Request $request ) {
		$form_id = (int) $request['id'];
		$form    = FormRepository::find( $form_id );
		if ( ! $form || ( $form['status'] ?? '' ) !== 'active' || ! Capabilities::can( Capabilities::CALCULATIONS ) || ! PriceEngine::form_has_pricing( $form ) || ! Coupons::form_has_coupons( $form ) ) {
			return new WP_Error( 'form_not_found', __( 'Form not found.', 'neural-forms-lite' ), array( 'status' => 404 ) );
		}
		if ( Coupons::over_try_cap( ( new SubmitEndpoint() )->resolve_ip(), $form_id ) ) {
			return new WP_Error( 'coupon_rate_limited', __( 'Too many tries. Please wait a few minutes and try again.', 'neural-forms-lite' ), array( 'status' => 429 ) );
		}
		$result = Coupons::check( $form, (string) $request->get_param( 'code' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$response = rest_ensure_response( array( 'coupon' => Coupons::client_shape( $result, PriceEngine::settings( $form )['currency'] ) ) );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	public function usage( WP_REST_Request $request ) {
		$form_id = (int) $request['id'];
		$form    = FormRepository::find( $form_id );
		if ( ! $form ) {
			return new WP_Error( 'form_not_found', __( 'Form not found.', 'neural-forms-lite' ), array( 'status' => 404 ) );
		}
		$out = array();
		foreach ( PriceEngine::settings( $form )['coupons'] as $c ) {
			$out[ $c['id'] ] = Coupons::uses( $form_id, $c['id'] );
		}
		return rest_ensure_response( (object) $out );
	}
}
