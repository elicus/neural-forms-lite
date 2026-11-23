<?php
/**
 * Wires the quotes module into WordPress: the daily expiry cron and
 * the raw (non-JSON) serving of the public quote view / PDF routes.
 */

namespace NeuralForms\Quotes;

final class QuotesBoot {

	const EXPIRE_HOOK = 'neural_forms_quote_expire';

	public static function boot(): void {
		add_action( self::EXPIRE_HOOK, array( QuoteService::class, 'expire_due' ) );
		if ( ! wp_next_scheduled( self::EXPIRE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::EXPIRE_HOOK );
		}
		add_filter( 'rest_pre_serve_request', array( self::class, 'serve_raw' ), 10, 4 );
	}

	/**
	 * A REST callback that wants to answer with HTML / PDF returns a
	 * WP_REST_Response whose data is the body and which carries the
	 * `X-Neural-Forms-Raw` header; this filter streams it as-is.
	 */
	public static function serve_raw( $served, $result, $request, $server ) {
		if ( $served || ! $result instanceof \WP_REST_Response ) {
			return $served;
		}
		$headers = $result->get_headers();
		if ( empty( $headers['X-Neural-Forms-Raw'] ) ) {
			return $served;
		}
		$body = $result->get_data();
		if ( ! is_string( $body ) ) {
			return $served;
		}
		unset( $headers['X-Neural-Forms-Raw'] );
		if ( ! headers_sent() ) {
			status_header( $result->get_status() );
			foreach ( $headers as $name => $value ) {
				header( $name . ': ' . $value );
			}
			header( 'X-Robots-Tag: noindex, nofollow' );
		}
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered + escaped by QuoteRenderer / binary PDF.
		return true;
	}

	public static function raw_response( string $body, string $content_type, int $status = 200, array $extra = array() ): \WP_REST_Response {
		$headers = array_merge(
			array(
				'Content-Type'          => $content_type,
				'X-Neural-Forms-Raw'    => '1',
				'Cache-Control'         => 'private, no-store',
				'X-Content-Type-Options' => 'nosniff',
			),
			$extra
		);
		return new \WP_REST_Response( $body, $status, $headers );
	}
}
