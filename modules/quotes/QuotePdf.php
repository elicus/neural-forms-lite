<?php
/**
 * PDF bridge. Lite has no PDF library; Pro registers one through
 * `neural_forms_quote_pdf` (returns the PDF bytes for an HTML document)
 * and `neural_forms_quote_pdf_available`. When nothing is registered the
 * public link falls back to the printable HTML view (decision D5).
 */

namespace NeuralForms\Quotes;

final class QuotePdf {

	public static function available(): bool {
		return (bool) apply_filters( 'neural_forms_quote_pdf_available', false );
	}

	/**
	 * @return string|null  PDF bytes, or null when no renderer is available / rendering failed.
	 */
	public static function render( array $quote ): ?string {
		if ( ! self::available() ) {
			return null;
		}
		$html = QuoteRenderer::html( $quote, array( 'mode' => 'pdf', 'logo_src' => self::logo_data_uri( $quote ) ) );
		try {
			$pdf = apply_filters( 'neural_forms_quote_pdf', null, $html, $quote );
		} catch ( \Throwable $e ) {
			if ( class_exists( '\\NeuralForms\\Core\\Logger' ) ) {
				\NeuralForms\Core\Logger::log( 'quote_pdf_failed', 'error', $e->getMessage(), array( 'quote_id' => (int) ( $quote['id'] ?? 0 ) ) );
			}
			return null;
		}
		return is_string( $pdf ) && strncmp( $pdf, '%PDF', 4 ) === 0 ? $pdf : null;
	}

	/**
	 * Generic: any escaped HTML document (invoices, receipts) → PDF bytes
	 * through the same Pro renderer, or null when unavailable.
	 */
	public static function from_html( string $html, array $context = array() ): ?string {
		if ( ! self::available() ) {
			return null;
		}
		try {
			$pdf = apply_filters( 'neural_forms_quote_pdf', null, $html, $context );
		} catch ( \Throwable $e ) {
			return null;
		}
		return is_string( $pdf ) && strncmp( $pdf, '%PDF', 4 ) === 0 ? $pdf : null;
	}

	/**
	 * The logo as a data URI so the PDF renderer never needs network or
	 * filesystem access. Only images inside this site's uploads folder
	 * (or any http(s) image up to 2 MB via the HTTP API) are embedded.
	 */
	public static function logo_data_uri( array $quote ): ?string {
		$url = (string) ( $quote['snapshot']['business']['logo_url'] ?? '' );
		if ( $url === '' ) {
			return '';
		}
		$bytes = null;
		$type  = '';
		if ( function_exists( 'wp_get_upload_dir' ) ) {
			$up = wp_get_upload_dir();
			if ( ! empty( $up['baseurl'] ) && strpos( $url, (string) $up['baseurl'] ) === 0 ) {
				$path = (string) $up['basedir'] . substr( $url, strlen( (string) $up['baseurl'] ) );
				$path = strtok( $path, '?' );
				$real = realpath( $path );
				if ( $real && strpos( $real, (string) realpath( (string) $up['basedir'] ) ) === 0 && is_readable( $real ) && filesize( $real ) <= 2097152 ) {
					$bytes = file_get_contents( $real ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				}
			}
		}
		if ( null === $bytes && function_exists( 'wp_remote_get' ) ) {
			$res = wp_remote_get( $url, array( 'timeout' => 8, 'limit_response_size' => 2097152 ) );
			if ( ! is_wp_error( $res ) && (int) wp_remote_retrieve_response_code( $res ) === 200 ) {
				$bytes = (string) wp_remote_retrieve_body( $res );
				$type  = (string) wp_remote_retrieve_header( $res, 'content-type' );
			}
		}
		if ( ! is_string( $bytes ) || $bytes === '' ) {
			return '';
		}
		if ( $type === '' && function_exists( 'finfo_open' ) ) {
			$fi   = finfo_open( FILEINFO_MIME_TYPE );
			$type = $fi ? (string) finfo_buffer( $fi, $bytes ) : '';
		}
		$type = strtok( $type, ';' );
		if ( ! in_array( $type, array( 'image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/svg+xml' ), true ) ) {
			return '';
		}
		return 'data:' . $type . ';base64,' . base64_encode( $bytes );
	}
}
