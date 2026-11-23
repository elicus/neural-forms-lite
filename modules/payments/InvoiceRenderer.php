<?php
/**
 * Invoice / receipt document for a confirmed payment (1.2.0 part B).
 *
 * Same constraints as QuoteRenderer: one escaped, table-only, inline-CSS
 * HTML document that serves the admin preview, the customer's link and
 * the PDF. Numbers and amounts come from the payment row and the entry's
 * (or quote's) pricing snapshot — never recomputed from the live form.
 * It says "Invoice" with a PAID stamp; it never claims to be a tax invoice.
 */

namespace NeuralForms\Payments;

use NeuralForms\Pricing\Money;
use NeuralForms\Quotes\QuoteRenderer;

final class InvoiceRenderer {

	/**
	 * @param array      $payment  Decoded attempt (settled).
	 * @param array      $entry    Decoded entry (may be empty).
	 * @param array      $form     Decoded form (may be empty).
	 * @param array|null $quote    Quote row when the payment was for a quote.
	 * @param array      $settings QuoteSettings::get() (branding).
	 * @param array      $options  { mode: html|pdf, toolbar: bool, pdf_url, logo_src }
	 */
	public static function html( array $payment, array $entry, array $form, ?array $quote, array $settings, array $options = array() ): string {
		$e        = array( QuoteRenderer::class, 'esc' );
		$mode     = ( $options['mode'] ?? 'html' ) === 'pdf' ? 'pdf' : 'html';
		$c        = (string) $payment['currency'];
		$accent   = preg_match( '/^#[0-9a-fA-F]{6}$/', (string) ( $settings['accent_color'] ?? '' ) ) ? (string) $settings['accent_color'] : '#1e1b4b';
		$number   = (string) ( $payment['meta']['invoice_number'] ?? '' );
		$snapshot = $quote && is_array( $quote['snapshot'] ?? null ) ? $quote['snapshot'] : ( is_array( $entry['pricing'] ?? null ) ? $entry['pricing'] : array() );
		$lines    = self::lines( $snapshot, $quote !== null );
		$total    = (int) ( $snapshot['total'] ?? $payment['amount_due_minor'] );
		$paid     = (int) $payment['amount_paid_minor'];
		$refunded = (int) $payment['amount_refunded_minor'];
		$customer = array(
			'name'  => (string) ( $quote['snapshot']['customer']['name'] ?? $payment['meta']['customer_name'] ?? '' ),
			'email' => (string) ( $quote['snapshot']['customer']['email'] ?? $payment['meta']['customer_email'] ?? '' ),
		);
		$logo  = isset( $options['logo_src'] ) && is_string( $options['logo_src'] ) ? $options['logo_src'] : (string) ( $settings['logo_url'] ?? '' );
		$doc   = trim( (string) ( $settings['invoice_title'] ?? '' ) ) !== '' ? (string) $settings['invoice_title'] : self::t( 'Invoice' );
		$title = $number !== '' ? $doc . ' ' . $number : $doc;
		$stamp = $refunded >= $paid && $paid > 0 ? self::t( 'REFUNDED' ) : ( $refunded > 0 ? self::t( 'PARTLY REFUNDED' ) : self::t( 'PAID' ) );
		$stamp_class = $refunded > 0 ? 'is-refund' : 'is-paid';

		/**
		 * Filter the invoice stylesheet (inline CSS; dompdf understands tables,
		 * inline-block and plain CSS 2.1 — no flexbox / grid / web fonts).
		 */
		$css = (string) apply_filters( 'neural_forms_invoice_css', self::css( $accent, $mode ), $accent, $mode, $payment );
		$h   = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . $e( $title ) . '</title><style>' . $css . '</style></head><body class="nf-inv nf-inv--' . $e( $mode ) . '">';
		if ( $mode === 'html' && ! empty( $options['toolbar'] ) ) {
			$h .= '<div class="nf-inv__toolbar"><button type="button" onclick="window.print()">' . $e( self::t( 'Print' ) ) . '</button>';
			if ( ! empty( $options['pdf_url'] ) ) {
				$h .= ' <a class="nf-inv__toolbar-btn" href="' . $e( (string) $options['pdf_url'] ) . '">' . $e( self::t( 'Download PDF' ) ) . '</a>';
			}
			$h .= '</div>';
		}
		$h .= '<div class="nf-inv__page">';
		$h .= '<table class="nf-inv__header" width="100%"><tr><td valign="top">';
		if ( $logo !== '' ) {
			$h .= '<img class="nf-inv__logo" src="' . $e( $logo ) . '" alt="">';
		}
		if ( (string) ( $settings['business_name'] ?? '' ) !== '' ) {
			$h .= '<div class="nf-inv__biz-name">' . $e( (string) $settings['business_name'] ) . '</div>';
		}
		foreach ( array( 'address', 'email', 'phone', 'website' ) as $k ) {
			if ( (string) ( $settings[ $k ] ?? '' ) !== '' ) {
				$h .= '<div class="nf-inv__biz-line">' . nl2br( $e( (string) $settings[ $k ] ) ) . '</div>';
			}
		}
		if ( (string) ( $settings['tax_id'] ?? '' ) !== '' ) {
			$h .= '<div class="nf-inv__biz-line">' . $e( sprintf( self::t( 'Tax ID: %s' ), (string) $settings['tax_id'] ) ) . '</div>';
		}
		$h .= '</td><td valign="top" align="right"><div class="nf-inv__title">' . $e( $doc ) . '</div><div class="nf-inv__stamp ' . $stamp_class . '">' . $e( $stamp ) . '</div><table class="nf-inv__meta">';
		if ( $number !== '' ) {
			$h .= '<tr><th>' . $e( self::t( 'Number' ) ) . '</th><td>' . $e( $number ) . '</td></tr>';
		}
		$h .= '<tr><th>' . $e( self::t( 'Date' ) ) . '</th><td>' . $e( self::date( (string) ( $payment['paid_at'] ?? $payment['created_at'] ?? '' ) ) ) . '</td></tr>';
		if ( $quote && ! empty( $quote['number'] ) ) {
			$h .= '<tr><th>' . $e( self::t( 'Quote' ) ) . '</th><td>' . $e( (string) $quote['number'] ) . '</td></tr>';
		}
		$h .= '<tr><th>' . $e( self::t( 'Reference' ) ) . '</th><td>#' . (int) $payment['entry_id'] . '-' . (int) $payment['id'] . '</td></tr>';
		$h .= '</table></td></tr></table>';

		if ( $customer['name'] !== '' || $customer['email'] !== '' ) {
			$h .= '<div class="nf-inv__to"><div class="nf-inv__label">' . $e( self::t( 'Billed to' ) ) . '</div>';
			if ( $customer['name'] !== '' ) {
				$h .= '<div class="nf-inv__to-name">' . $e( $customer['name'] ) . '</div>';
			}
			if ( $customer['email'] !== '' ) {
				$h .= '<div class="nf-inv__to-line">' . $e( $customer['email'] ) . '</div>';
			}
			$h .= '</div>';
		}

		$h .= '<table class="nf-inv__lines" width="100%" cellspacing="0"><thead><tr><th class="is-desc">' . $e( self::t( 'Description' ) ) . '</th><th class="is-num">' . $e( self::t( 'Qty' ) ) . '</th><th class="is-num">' . $e( self::t( 'Unit price' ) ) . '</th><th class="is-num">' . $e( self::t( 'Amount' ) ) . '</th></tr></thead><tbody>';
		if ( $lines ) {
			foreach ( $lines as $l ) {
				$h .= '<tr><td class="is-desc">' . $e( $l['label'] ) . ( $l['description'] !== '' ? '<div class="nf-inv__line-desc">' . $e( $l['description'] ) . '</div>' : '' ) . '</td><td class="is-num">' . (int) $l['qty'] . '</td><td class="is-num">' . $e( Money::format( (int) $l['unit'], $c ) ) . '</td><td class="is-num">' . $e( Money::format( (int) $l['total'], $c ) ) . '</td></tr>';
			}
		} else {
			$h .= '<tr><td class="is-desc">' . $e( (string) ( $payment['meta']['description'] ?? $form['name'] ?? self::t( 'Payment' ) ) ) . '</td><td class="is-num">1</td><td class="is-num">' . $e( Money::format( (int) $payment['amount_due_minor'], $c ) ) . '</td><td class="is-num">' . $e( Money::format( (int) $payment['amount_due_minor'], $c ) ) . '</td></tr>';
		}
		$h .= '</tbody></table>';

		$h .= '<table class="nf-inv__totals-wrap" width="100%"><tr><td></td><td align="right"><table class="nf-inv__totals">';
		if ( $lines ) {
			$h .= '<tr><th>' . $e( self::t( 'Subtotal' ) ) . '</th><td>' . $e( Money::format( (int) ( $snapshot['subtotal'] ?? $total ), $c ) ) . '</td></tr>';
			if ( is_array( $snapshot['discount'] ?? null ) && (int) ( $snapshot['discount']['amount'] ?? 0 ) > 0 ) {
				$h .= '<tr><th>' . $e( (string) ( $snapshot['discount']['label'] ?: self::t( 'Discount' ) ) ) . '</th><td>&minus;' . $e( Money::format( (int) $snapshot['discount']['amount'], $c ) ) . '</td></tr>';
			}
			$h .= '<tr class="is-total"><th>' . $e( self::t( 'Total' ) ) . '</th><td>' . $e( Money::format( $total, $c ) ) . '</td></tr>';
		}
		$h .= '<tr class="is-paid"><th>' . $e( $payment['kind'] === 'deposit' ? self::t( 'Deposit paid' ) : self::t( 'Amount paid' ) ) . '</th><td>' . $e( Money::format( $paid, $c ) ) . '</td></tr>';
		if ( $refunded > 0 ) {
			$h .= '<tr><th>' . $e( self::t( 'Refunded' ) ) . '</th><td>&minus;' . $e( Money::format( $refunded, $c ) ) . '</td></tr>';
		}
		$remaining = max( 0, $total - max( 0, $paid - $refunded ) );
		if ( $lines && $remaining > 0 ) {
			$h .= '<tr><th>' . $e( self::t( 'Remaining balance' ) ) . '</th><td>' . $e( Money::format( $remaining, $c ) ) . '</td></tr>';
		}
		$h .= '</table></td></tr></table>';

		$provider = PaymentProviderRegistry::get( (string) $payment['provider'] );
		$method   = $provider ? $provider->label() : ucfirst( (string) $payment['provider'] );
		$h       .= '<div class="nf-inv__section"><div class="nf-inv__label">' . $e( self::t( 'Payment' ) ) . '</div><div class="nf-inv__text">' . $e( sprintf( self::t( '%1$s paid via %2$s on %3$s' ), Money::format( $paid, $c ), $method, self::date( (string) ( $payment['paid_at'] ?? '' ) ) ) );
		if ( ! empty( $payment['provider_payment_id'] ) ) {
			$h .= ' · ' . $e( (string) $payment['provider_payment_id'] );
		}
		$h .= '</div></div>';
		if ( (string) ( $settings['invoice_note'] ?? '' ) !== '' ) {
			$h .= '<div class="nf-inv__section"><div class="nf-inv__label">' . $e( self::t( 'Notes' ) ) . '</div><div class="nf-inv__text">' . nl2br( $e( (string) $settings['invoice_note'] ) ) . '</div></div>';
		}
		if ( (string) ( $settings['footer'] ?? '' ) !== '' ) {
			$h .= '<div class="nf-inv__footer">' . nl2br( $e( (string) $settings['footer'] ) ) . '</div>';
		}
		$h .= '<div class="nf-inv__footer nf-inv__disclaimer">' . $e( self::t( 'Thank you for your business.' ) ) . '</div>';
		$h .= '</div></body></html>';
		/**
		 * Filter the finished invoice document. Return your own complete HTML
		 * to replace the layout entirely; $payment / $entry / $quote are raw
		 * data and must be escaped by the filter.
		 */
		return (string) apply_filters( 'neural_forms_invoice_html', $h, $payment, $entry, $form, $quote, $settings, $options );
	}

	/** Line items in a uniform shape from either a quote or an entry pricing snapshot. */
	public static function lines( array $snapshot, bool $is_quote ): array {
		$out = array();
		foreach ( is_array( $snapshot['line_items'] ?? null ) ? $snapshot['line_items'] : array() as $l ) {
			if ( ! is_array( $l ) ) {
				continue;
			}
			if ( $is_quote ) {
				$label = (string) ( $l['label'] ?? '' );
				$desc  = (string) ( $l['description'] ?? '' );
			} else {
				$label = (string) ( $l['choice_label'] ?: ( $l['label'] ?? '' ) );
				$desc  = (string) ( $l['choice_label'] && $l['label'] !== $l['choice_label'] ? $l['label'] : '' );
			}
			$out[] = array( 'label' => $label, 'description' => $desc, 'qty' => max( 1, (int) ( $l['qty'] ?? 1 ) ), 'unit' => (int) ( $l['unit'] ?? 0 ), 'total' => (int) ( $l['total'] ?? ( (int) ( $l['unit'] ?? 0 ) * max( 1, (int) ( $l['qty'] ?? 1 ) ) ) ) );
		}
		return $out;
	}

	public static function filename( array $payment ): string {
		$n = (string) ( $payment['meta']['invoice_number'] ?? ( 'invoice-' . (int) $payment['id'] ) );
		return strtolower( trim( preg_replace( '/[^A-Za-z0-9\-]+/', '-', $n ), '-' ) ) . '.pdf';
	}

	private static function css( string $accent, string $mode ): string {
		$css  = 'body{margin:0;padding:0;background:#f3f4f6;color:#111827;font-family:DejaVu Sans,Helvetica,Arial,sans-serif;font-size:12px;line-height:1.45}.nf-inv__page{background:#fff;max-width:760px;margin:24px auto;padding:40px 44px;box-sizing:border-box}';
		$css .= '.nf-inv__toolbar{max-width:760px;margin:16px auto 0;text-align:right}.nf-inv__toolbar button,.nf-inv__toolbar-btn{display:inline-block;font:inherit;font-size:12px;padding:7px 12px;border:1px solid #d1d5db;background:#fff;border-radius:6px;color:#111827;text-decoration:none;cursor:pointer}';
		$css .= ".nf-inv__header{margin-bottom:22px}.nf-inv__logo{max-height:60px;max-width:200px;display:block;margin-bottom:8px}.nf-inv__biz-name{font-size:15px;font-weight:bold;color:{$accent}}.nf-inv__biz-line{color:#4b5563}";
		$css .= ".nf-inv__title{font-size:26px;font-weight:bold;color:{$accent};letter-spacing:1px;text-transform:uppercase}.nf-inv__stamp{display:inline-block;margin:4px 0 8px;padding:3px 10px;border:2px solid #10b981;color:#065f46;border-radius:6px;font-weight:bold;letter-spacing:1px;font-size:11px}.nf-inv__stamp.is-refund{border-color:#f59e0b;color:#92400e}";
		$css .= '.nf-inv__meta{margin-left:auto;border-collapse:collapse}.nf-inv__meta th{text-align:left;font-weight:normal;color:#6b7280;padding:1px 10px 1px 0}.nf-inv__meta td{text-align:right;padding:1px 0}';
		$css .= '.nf-inv__label{font-size:10px;font-weight:bold;text-transform:uppercase;letter-spacing:.6px;color:#6b7280;margin-bottom:3px}.nf-inv__to{margin-bottom:18px}.nf-inv__to-name{font-weight:bold;font-size:13px}.nf-inv__to-line{color:#4b5563}';
		$css .= ".nf-inv__lines{border-collapse:collapse;margin-bottom:14px}.nf-inv__lines th{background:{$accent};color:#fff;text-align:left;padding:7px 8px;font-size:11px;text-transform:uppercase}.nf-inv__lines th.is-num,.nf-inv__lines td.is-num{text-align:right;white-space:nowrap}.nf-inv__lines td{padding:7px 8px;border-bottom:1px solid #e5e7eb;vertical-align:top}.nf-inv__line-desc{color:#6b7280;font-size:11px}";
		$css .= ".nf-inv__totals{border-collapse:collapse;min-width:260px}.nf-inv__totals th{text-align:left;font-weight:normal;color:#4b5563;padding:3px 16px 3px 0}.nf-inv__totals td{text-align:right;padding:3px 0;white-space:nowrap}.nf-inv__totals tr.is-total th,.nf-inv__totals tr.is-total td{font-weight:bold;font-size:14px;color:{$accent};border-top:2px solid {$accent};padding-top:6px}.nf-inv__totals tr.is-paid th,.nf-inv__totals tr.is-paid td{font-weight:bold;color:#065f46}";
		$css .= '.nf-inv__section{margin-top:18px}.nf-inv__text{color:#374151}.nf-inv__footer{margin-top:24px;padding-top:12px;border-top:1px solid #e5e7eb;color:#6b7280;font-size:10.5px}.nf-inv__disclaimer{border-top:0;padding-top:0;margin-top:8px;color:#9ca3af}';
		$css .= $mode === 'pdf' ? 'body{background:#fff}.nf-inv__page{margin:0;padding:0;max-width:none}' : '@media print{body{background:#fff}.nf-inv__toolbar{display:none}.nf-inv__page{margin:0;max-width:none;padding:0}}';
		return $css;
	}

	private static function date( string $mysql_utc ): string {
		$ts = $mysql_utc !== '' ? strtotime( $mysql_utc . ' UTC' ) : false;
		if ( false === $ts ) {
			return '';
		}
		return function_exists( 'wp_date' ) && function_exists( 'get_option' ) ? wp_date( (string) get_option( 'date_format', 'F j, Y' ), $ts ) : gmdate( 'F j, Y', $ts );
	}

	private static function t( string $s ): string {
		return function_exists( '__' ) ? __( $s, 'neural-forms-lite' ) : $s;
	}
}
