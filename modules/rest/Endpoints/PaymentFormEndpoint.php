<?php
/**
 * POST /neural-forms/v1/forms/{id}/buy — quick purchase from a Buy button.
 *
 * Stores an entry (product choice + quantity, email, name) through the
 * same repository and hooks as a normal submission, then starts the hosted
 * checkout. Protected like the submit route: honeypot, per-IP rate limit,
 * server-side pricing. Other required fields of the form are NOT collected
 * by design (quick buy); the entry records `payment_form` as its source.
 *
 * GET /neural-forms/v1/payment-form/catalogue (edit_posts) lists forms + items for
 * the block inspector and the Divi module.
 */

namespace NeuralForms\Rest\Endpoints;

use NeuralForms\Core\Capabilities;
use NeuralForms\Forms\FormRepository;
use NeuralForms\Forms\SchemaHelper;
use NeuralForms\Payments\PaymentForm;
use NeuralForms\Payments\PaymentService;
use NeuralForms\Pricing\Money;
use NeuralForms\Pricing\PriceEngine;
use NeuralForms\Storage\EntryRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class PaymentFormEndpoint {

	public function register_routes(): void {
		register_rest_route( 'neural-forms/v1', '/forms/(?P<id>[\d]+)/pay', array( 'methods' => 'POST', 'callback' => array( $this, 'pay' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( 'neural-forms/v1', '/payment-form/catalogue', array( 'methods' => 'GET', 'callback' => array( $this, 'catalogue' ), 'permission_callback' => static function () { return current_user_can( 'edit_posts' ); } ) );
		register_rest_route( 'neural-forms/v1', '/payment-form/preview', array( 'methods' => 'GET', 'callback' => array( $this, 'preview' ), 'permission_callback' => static function () { return current_user_can( 'edit_posts' ); } ) );
	}

	public function catalogue( WP_REST_Request $request ) {
		return rest_ensure_response( array( 'unlocked' => Capabilities::can( Capabilities::PAYMENTS ), 'forms' => PaymentForm::catalogue() ) );
	}

	/** Editor preview for builders that cannot run a block render (Divi). */
	public function preview( WP_REST_Request $request ) {
		$attrs = array();
		foreach ( PaymentForm::defaults() as $k => $d ) {
			$v = $request->get_param( $k );
			if ( null === $v ) {
				continue;
			}
			$attrs[ $k ] = is_bool( $d ) ? ( $v === '1' || $v === 'on' || $v === true || $v === 'true' ) : ( is_int( $d ) ? (int) $v : sanitize_text_field( (string) $v ) );
		}
		return rest_ensure_response( array( 'html' => PaymentForm::render( $attrs, array( 'preview' => true ) ) ) );
	}

	public function pay( WP_REST_Request $request ) {
		$form_id = (int) $request['id'];
		$body    = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = (array) $request->get_body_params();
		}
		if ( trim( (string) ( $body['neural_forms_website'] ?? '' ) ) !== '' ) {
			return new WP_REST_Response( array( 'success' => true, 'entry_id' => 0 ), 201 ); // honeypot: pretend
		}
		$ip = ( new SubmitEndpoint() )->resolve_ip();
		// Same gates as a normal submission: site-wide rate-limit filter, then the form's CAPTCHA.
		if ( apply_filters( 'neural_forms_rate_limit', SubmitEndpoint::default_rate_limit( $ip, $form_id ), $request, $ip, $form_id ) ) {
			return new WP_Error( 'rate_limited', __( 'Too many attempts. Please wait a minute and try again.', 'neural-forms-lite' ), array( 'status' => 429 ) );
		}
		$form_row = \NeuralForms\Forms\FormRepository::find( $form_id );
		$fs       = is_array( $form_row['settings'] ?? null ) ? $form_row['settings'] : array();
		if ( ! empty( $fs['captcha_enabled'] ) && \NeuralForms\Captcha\CaptchaManager::is_configured() ) {
			$token = sanitize_text_field( (string) ( $body['captcha_token'] ?? $body['cf-turnstile-response'] ?? $body['g-recaptcha-response'] ?? '' ) );
			if ( $token === '' ) {
				return new WP_Error( 'captcha_missing', __( 'Please complete the CAPTCHA.', 'neural-forms-lite' ), array( 'status' => 400 ) );
			}
			if ( is_wp_error( \NeuralForms\Captcha\CaptchaManager::verify( $token, $ip ) ) ) {
				return new WP_Error( 'captcha_failed', __( 'CAPTCHA verification failed. Please try again.', 'neural-forms-lite' ), array( 'status' => 400 ) );
			}
		}
		$resolved = PaymentForm::resolve( array( 'formId' => $form_id, 'fieldId' => sanitize_text_field( (string) ( $body['field'] ?? '' ) ), 'choice' => sanitize_text_field( (string) ( $body['choice'] ?? '' ) ), 'quantity' => (int) ( $body['qty'] ?? 1 ), 'allowQuantity' => true ) );
		if ( empty( $resolved['ok'] ) ) {
			return new WP_Error( 'not_purchasable', __( 'This item is not available for purchase right now.', 'neural-forms-lite' ), array( 'status' => 409, 'reason' => $resolved['reason'] ) );
		}
		$email = sanitize_email( (string) ( $body['email'] ?? '' ) );
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'invalid_email', __( 'Please enter a valid email address.', 'neural-forms-lite' ), array( 'status' => 422, 'field' => 'email' ) );
		}
		$name = mb_substr( sanitize_text_field( (string) ( $body['full_name'] ?? '' ) ), 0, 190 );
		$qty  = max( $resolved['qty_min'], min( $resolved['qty_max'], (int) ( $body['qty'] ?? 1 ) ) );
		$form = $resolved['form'];

		// Map the collected values onto the form's own fields so the inbox,
		// notifications and invoices find them where they expect to.
		$fields     = SchemaHelper::flatten_fields( is_array( $form['form_schema']['fields'] ?? null ) ? $form['form_schema']['fields'] : array() );
		$email_key  = 'email';
		$name_key   = $name !== '' ? 'full_name' : '';
		foreach ( $fields as $f ) {
			if ( ( $f['type'] ?? '' ) === 'email' ) {
				$email_key = (string) $f['id'];
				break;
			}
		}
		if ( $name !== '' ) {
			foreach ( $fields as $f ) {
				$id = strtolower( (string) ( $f['id'] ?? '' ) );
				if ( in_array( $f['type'] ?? '', array( 'text', 'name' ), true ) && ( strpos( $id, 'name' ) !== false || stripos( (string) ( $f['label'] ?? '' ), 'name' ) !== false ) ) {
					$name_key = (string) $f['id'];
					break;
				}
			}
		}
		$product_id = (string) $resolved['field']['id'];
		$value      = array( 'choice' => (string) ( $resolved['option']['value'] ?? '' ), 'qty' => $qty );
		$data       = array( $product_id => $value, $email_key => $email );
		if ( $name_key !== '' ) {
			$data[ $name_key ] = $name;
		}
		$pricing = PriceEngine::evaluate( $form, $data, array() );
		$privacy = (bool) \NeuralForms\Core\Config::get( 'privacy_mode' );
		$context = array(
			'ip'           => $privacy ? '' : $ip,
			'user_agent'   => $privacy ? '' : sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ),
			'page_url'     => esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ?? '' ) ),
			'submitted_at' => current_time( 'mysql', true ),
			'form_version' => md5( (string) wp_json_encode( $form['form_schema'] ) ),
			'pricing'      => $pricing,
		);
		$entry_id = EntryRepository::create( $form_id, $data, $context );
		if ( ! $entry_id ) {
			return new WP_Error( 'storage_failed', __( 'Could not save your order. Please try again.', 'neural-forms-lite' ), array( 'status' => 500 ) );
		}
		$entry_id = (int) $entry_id;
		\NeuralForms\Storage\EntryActivity::record( $entry_id, 'entry.payment_form', __( 'Quick purchase from a Buy button', 'neural-forms-lite' ), array( 'by' => 0, 'field' => $product_id, 'qty' => $qty ) );
		do_action( 'neural_forms_entry_created', $entry_id, $form_id, $data );
		do_action( 'neural_forms_send_notifications', $entry_id, $form_id, $form );
		do_action( 'neural_forms_queue_ai_processing', $entry_id, $form_id );

		$out = array(
			'success'  => true,
			'entry_id' => $entry_id,
			'pricing'  => array( 'currency' => $pricing['currency'], 'total' => $pricing['total'], 'due_now' => $pricing['due_now'], 'formatted' => array( 'total' => Money::format( (int) $pricing['total'], $pricing['currency'] ), 'due_now' => Money::format( (int) $pricing['due_now'], $pricing['currency'] ) ) ),
		);
		if ( (int) $pricing['due_now'] > 0 ) {
			$entry    = EntryRepository::get( $entry_id );
			$checkout = $entry ? PaymentService::start_checkout( $entry, $form ) : null;
			if ( is_array( $checkout ) && ! empty( $checkout['url'] ) ) {
				$out['outcome'] = array( 'mode' => 'redirect', 'url' => (string) $checkout['url'] );
			} else {
				$out['payment_error'] = __( 'Your order was saved but the payment step could not be started. We will contact you.', 'neural-forms-lite' );
			}
		} else {
			$out['message'] = __( 'Thank you, your order has been received.', 'neural-forms-lite' );
		}
		return new WP_REST_Response( $out, 201 );
	}
}
