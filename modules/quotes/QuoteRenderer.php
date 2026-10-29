<?php
/**
 * Renders a quote snapshot as a standalone HTML document.
 *
 * One template serves three consumers: the admin preview, the public
 * token view (print stylesheet) and the PDF (Pro runs the same HTML
 * through dompdf). It therefore uses only table layout and inline CSS
 * that dompdf understands — no flexbox, no grid, no web fonts. Every
 * value is escaped here; the snapshot is data, never markup.
 */

namespace NeuralForms\Quotes;

final class QuoteRenderer {

	/**
	 * @param array $quote   Decoded quote row (snapshot included).
	 * @param array $options { mode: 'html'|'pdf', logo_src: string|null, pdf_url, print: bool }
	 */
	public static function html( array $quote, array $options = array() ): string {
		$s        = is_array( $quote['snapshot'] ?? null ) ? $quote['snapshot'] : array();
		$mode     = ( $options['mode'] ?? 'html' ) === 'pdf' ? 'pdf' : 'html';
		$biz      = is_array( $s['business'] ?? null ) ? $s['business'] : array();
		$cust     = is_array( $s['customer'] ?? null ) ? $s['customer'] : array();
		$fmt      = QuoteBuilder::formatted( $s );
		$accent   = preg_match( '/^#[0-9a-fA-F]{6}$/', (string) ( $biz['accent_color'] ?? '' ) ) ? (string) $biz['accent_color'] : '#1e1b4b';
		$number   = (string) ( $quote['number'] ?? '' );
		$status   = (string) ( $quote['status'] ?? 'draft' );
		$title    = $number !== '' ? sprintf( self::t( 'Quote %s' ), $number ) : self::t( 'Draft quote' );
		$logo_src = isset( $options['logo_src'] ) && is_string( $options['logo_src'] ) ? $options['logo_src'] : (string) ( $biz['logo_url'] ?? '' );
		$lines    = is_array( $s['line_items'] ?? null ) ? $s['line_items'] : array();
		$e        = array( self::class, 'esc' );

		$h  = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . $e( $title ) . '</title>';
		$h .= '<style>' . self::css( $accent, $mode ) . '</style></head><body class="nf-quote nf-quote--' . $e( $mode ) . '">';
		if ( $mode === 'html' && ! empty( $options['toolbar'] ) ) {
			$h .= '<div class="nf-quote__toolbar">';
			$h .= '<button type="button" onclick="window.print()">' . $e( self::t( 'Print' ) ) . '</button>';
			if ( ! empty( $options['pdf_url'] ) ) {
				$h .= ' <a class="nf-quote__toolbar-btn" href="' . $e( (string) $options['pdf_url'] ) . '">' . $e( self::t( 'Download PDF' ) ) . '</a>';
			}
			if ( ! empty( $options['pay_url'] ) ) {
				$h .= ' <a class="nf-quote__toolbar-btn nf-quote__toolbar-btn--pay" href="' . $e( (string) $options['pay_url'] ) . '">' . $e( sprintf( self::t( 'Pay %s now' ), $fmt['due_now'] ) ) . '</a>';
			}
			$h .= '</div>';
		}
		$h .= '<div class="nf-quote__page">';

		// Header: logo + business block left, title + meta right.
		$h .= '<table class="nf-quote__header" width="100%"><tr><td class="nf-quote__from" valign="top">';
		if ( $logo_src !== '' ) {
			$h .= '<img class="nf-quote__logo" src="' . $e( $logo_src ) . '" alt="' . $e( (string) ( $biz['name'] ?? '' ) ) . '">';
		}
		if ( (string) ( $biz['name'] ?? '' ) !== '' ) {
			$h .= '<div class="nf-quote__biz-name">' . $e( (string) $biz['name'] ) . '</div>';
		}
		foreach ( array( 'address', 'email', 'phone', 'website' ) as $k ) {
			if ( (string) ( $biz[ $k ] ?? '' ) !== '' ) {
				$h .= '<div class="nf-quote__biz-line">' . nl2br( $e( (string) $biz[ $k ] ) ) . '</div>';
			}
		}
		if ( (string) ( $biz['tax_id'] ?? '' ) !== '' ) {
			$h .= '<div class="nf-quote__biz-line">' . $e( sprintf( self::t( 'Tax ID: %s' ), (string) $biz['tax_id'] ) ) . '</div>';
		}
		$h .= '</td><td class="nf-quote__meta" valign="top" align="right">';
		$h .= '<div class="nf-quote__title">' . $e( self::t( 'Quote' ) ) . '</div>';
		$h .= '<table class="nf-quote__meta-table">';
		if ( $number !== '' ) {
			$h .= '<tr><th>' . $e( self::t( 'Number' ) ) . '</th><td>' . $e( $number ) . '</td></tr>';
		}
		if ( (int) ( $quote['revision'] ?? 1 ) > 1 ) {
			$h .= '<tr><th>' . $e( self::t( 'Revision' ) ) . '</th><td>' . (int) $quote['revision'] . '</td></tr>';
		}
		$issued = (string) ( $quote['issued_at'] ?? '' );
		$h     .= '<tr><th>' . $e( self::t( 'Date' ) ) . '</th><td>' . $e( self::date( $issued !== '' ? $issued : (string) ( $quote['created_at'] ?? '' ) ) ) . '</td></tr>';
		if ( (string) ( $quote['valid_until'] ?? '' ) !== '' ) {
			$h .= '<tr><th>' . $e( self::t( 'Valid until' ) ) . '</th><td>' . $e( self::date( (string) $quote['valid_until'] ) ) . '</td></tr>';
		}
		$h .= '<tr><th>' . $e( self::t( 'Status' ) ) . '</th><td><span class="nf-quote__status nf-quote__status--' . $e( $status ) . '">' . $e( self::status_label( $status ) ) . '</span></td></tr>';
		$h .= '</table></td></tr></table>';

		// Customer.
		if ( (string) ( $cust['name'] ?? '' ) !== '' || (string) ( $cust['email'] ?? '' ) !== '' ) {
			$h .= '<div class="nf-quote__to"><div class="nf-quote__label">' . $e( self::t( 'Prepared for' ) ) . '</div>';
			if ( (string) ( $cust['name'] ?? '' ) !== '' ) {
				$h .= '<div class="nf-quote__to-name">' . $e( (string) $cust['name'] ) . '</div>';
			}
			if ( (string) ( $cust['email'] ?? '' ) !== '' ) {
				$h .= '<div class="nf-quote__to-line">' . $e( (string) $cust['email'] ) . '</div>';
			}
			$h .= '</div>';
		}
		if ( (string) ( $s['intro'] ?? '' ) !== '' ) {
			$h .= '<div class="nf-quote__intro">' . nl2br( $e( (string) $s['intro'] ) ) . '</div>';
		}

		// Lines.
		$h .= '<table class="nf-quote__lines" width="100%" cellspacing="0"><thead><tr>';
		$h .= '<th class="is-desc">' . $e( self::t( 'Description' ) ) . '</th><th class="is-num">' . $e( self::t( 'Qty' ) ) . '</th><th class="is-num">' . $e( self::t( 'Unit price' ) ) . '</th><th class="is-num">' . $e( self::t( 'Amount' ) ) . '</th>';
		$h .= '</tr></thead><tbody>';
		foreach ( $lines as $i => $l ) {
			$h .= '<tr><td class="is-desc"><div class="nf-quote__line-label">' . $e( (string) ( $l['label'] ?? '' ) ) . '</div>';
			if ( (string) ( $l['description'] ?? '' ) !== '' ) {
				$h .= '<div class="nf-quote__line-desc">' . $e( (string) $l['description'] ) . '</div>';
			}
			$h .= '</td><td class="is-num">' . (int) ( $l['qty'] ?? 1 ) . '</td><td class="is-num">' . $e( $fmt['lines'][ $i ]['unit'] ?? '' ) . '</td><td class="is-num">' . $e( $fmt['lines'][ $i ]['total'] ?? '' ) . '</td></tr>';
		}
		if ( empty( $lines ) ) {
			$h .= '<tr><td class="is-desc nf-quote__empty" colspan="4">' . $e( self::t( 'No items.' ) ) . '</td></tr>';
		}
		$h .= '</tbody></table>';

		// Totals.
		$h .= '<table class="nf-quote__totals-wrap" width="100%"><tr><td></td><td class="nf-quote__totals-cell" align="right"><table class="nf-quote__totals">';
		$h .= '<tr><th>' . $e( self::t( 'Subtotal' ) ) . '</th><td>' . $e( $fmt['subtotal'] ) . '</td></tr>';
		if ( is_array( $s['discount'] ?? null ) && (int) ( $s['discount']['amount'] ?? 0 ) > 0 ) {
			$label = (string) ( $s['discount']['label'] ?? '' );
			if ( $label === '' ) {
				$label = self::t( 'Discount' );
			}
			if ( ( $s['discount']['type'] ?? '' ) === 'percent' ) {
				$label .= ' (' . $e( (string) $s['discount']['value'] ) . '%)';
			}
			$h .= '<tr><th>' . $e( $label ) . '</th><td>&minus;' . $e( $fmt['discount'] ) . '</td></tr>';
		}
		$h .= '<tr class="is-total"><th>' . $e( self::t( 'Total' ) ) . '</th><td>' . $e( $fmt['total'] ) . '</td></tr>';
		if ( is_array( $s['deposit'] ?? null ) && (int) ( $s['deposit']['amount'] ?? 0 ) > 0 ) {
			$dl = self::t( 'Due now (deposit)' );
			if ( ( $s['deposit']['type'] ?? '' ) === 'percent' ) {
				$dl = sprintf( self::t( 'Due now (%s%% deposit)' ), (string) $s['deposit']['value'] );
			}
			$h .= '<tr class="is-due"><th>' . $e( $dl ) . '</th><td>' . $e( $fmt['due_now'] ) . '</td></tr>';
			$h .= '<tr><th>' . $e( self::t( 'Remaining balance' ) ) . '</th><td>' . $e( $fmt['remaining'] ) . '</td></tr>';
		}
		$h .= '</table></td></tr></table>';
		if ( $mode === 'html' && ! empty( $options['pay_url'] ) ) {
			$h .= '<div class="nf-quote__pay"><a class="nf-quote__pay-btn" href="' . $e( (string) $options['pay_url'] ) . '">' . $e( sprintf( self::t( 'Pay %s securely now' ), $fmt['due_now'] ) ) . '</a><div class="nf-quote__pay-note">' . $e( self::t( 'You will be taken to a secure checkout page. No card details are entered on this site.' ) ) . '</div></div>';
		}

		if ( (string) ( $s['notes'] ?? '' ) !== '' ) {
			$h .= '<div class="nf-quote__section"><div class="nf-quote__label">' . $e( self::t( 'Notes' ) ) . '</div><div class="nf-quote__text">' . nl2br( $e( (string) $s['notes'] ) ) . '</div></div>';
		}
		if ( (string) ( $s['terms'] ?? '' ) !== '' ) {
			$h .= '<div class="nf-quote__section"><div class="nf-quote__label">' . $e( self::t( 'Terms' ) ) . '</div><div class="nf-quote__text">' . nl2br( $e( (string) $s['terms'] ) ) . '</div></div>';
		}
		$h .= '<div class="nf-quote__footer">';
		if ( (string) ( $s['footer'] ?? '' ) !== '' ) {
			$h .= '<div>' . nl2br( $e( (string) $s['footer'] ) ) . '</div>';
		}
		$h .= '<div class="nf-quote__disclaimer">' . $e( self::t( 'This quote is an estimate for review and is not a tax invoice. Prices are valid until the date shown.' ) ) . '</div>';
		$h .= '</div></div></body></html>';
		return $h;
	}

	/** Suggested download filename. */
	public static function filename( array $quote ): string {
		$base = (string) ( $quote['number'] ?? '' );
		if ( $base === '' ) {
			$base = 'quote-draft-' . (int) ( $quote['id'] ?? 0 );
		}
		$base = preg_replace( '/[^A-Za-z0-9\-_]+/', '-', $base );
		return strtolower( trim( $base, '-' ) ) . '.pdf';
	}

	public static function status_label( string $status ): string {
		switch ( $status ) {
			case 'issued':
				return self::t( 'Issued' );
			case 'expired':
				return self::t( 'Expired' );
			case 'superseded':
				return self::t( 'Superseded' );
			default:
				return self::t( 'Draft' );
		}
	}

	private static function css( string $accent, string $mode ): string {
		$font = 'DejaVu Sans, Helvetica, Arial, sans-serif';
		$css  = "body{margin:0;padding:0;background:#f3f4f6;color:#111827;font-family:{$font};font-size:12px;line-height:1.45}";
		$css .= '.nf-quote__page{background:#fff;max-width:760px;margin:24px auto;padding:40px 44px;box-sizing:border-box}';
		$css .= '.nf-quote__toolbar{max-width:760px;margin:16px auto 0;text-align:right}.nf-quote__toolbar button,.nf-quote__toolbar-btn{display:inline-block;font:inherit;font-size:12px;padding:7px 12px;border:1px solid #d1d5db;background:#fff;border-radius:6px;color:#111827;text-decoration:none;cursor:pointer}';
		$css .= '.nf-quote__header{margin-bottom:22px}.nf-quote__logo{max-height:60px;max-width:200px;display:block;margin-bottom:8px}';
		$css .= ".nf-quote__biz-name{font-size:15px;font-weight:bold;color:{$accent}}.nf-quote__biz-line{color:#4b5563}";
		$css .= ".nf-quote__title{font-size:26px;font-weight:bold;color:{$accent};letter-spacing:1px;text-transform:uppercase;margin-bottom:6px}";
		$css .= '.nf-quote__meta-table{margin-left:auto;border-collapse:collapse}.nf-quote__meta-table th{text-align:left;font-weight:normal;color:#6b7280;padding:1px 10px 1px 0}.nf-quote__meta-table td{text-align:right;padding:1px 0}';
		$css .= '.nf-quote__status{display:inline-block;padding:1px 7px;border-radius:9px;font-size:10px;font-weight:bold;text-transform:uppercase;background:#e5e7eb;color:#374151}.nf-quote__status--issued{background:#d1fae5;color:#065f46}.nf-quote__status--expired,.nf-quote__status--superseded{background:#fee2e2;color:#991b1b}';
		$css .= '.nf-quote__label{font-size:10px;font-weight:bold;text-transform:uppercase;letter-spacing:.6px;color:#6b7280;margin-bottom:3px}';
		$css .= '.nf-quote__to{margin-bottom:18px}.nf-quote__to-name{font-weight:bold;font-size:13px}.nf-quote__to-line{color:#4b5563}';
		$css .= '.nf-quote__intro{margin-bottom:18px;color:#374151}';
		$css .= ".nf-quote__lines{border-collapse:collapse;margin-bottom:14px}.nf-quote__lines th{background:{$accent};color:#fff;text-align:left;padding:7px 8px;font-size:11px;text-transform:uppercase;letter-spacing:.4px}.nf-quote__lines th.is-num,.nf-quote__lines td.is-num{text-align:right;white-space:nowrap}.nf-quote__lines td{padding:7px 8px;border-bottom:1px solid #e5e7eb;vertical-align:top}.nf-quote__line-desc{color:#6b7280;font-size:11px}.nf-quote__empty{color:#9ca3af;text-align:center}";
		$css .= '.nf-quote__totals{border-collapse:collapse;min-width:260px}.nf-quote__totals th{text-align:left;font-weight:normal;color:#4b5563;padding:3px 16px 3px 0}.nf-quote__totals td{text-align:right;padding:3px 0;white-space:nowrap}';
		$css .= ".nf-quote__totals tr.is-total th,.nf-quote__totals tr.is-total td{font-weight:bold;font-size:14px;color:{$accent};border-top:2px solid {$accent};padding-top:6px}.nf-quote__totals tr.is-due th,.nf-quote__totals tr.is-due td{font-weight:bold}";
		$css .= '.nf-quote__pay{text-align:right;margin:6px 0 4px}.nf-quote__pay-btn{display:inline-block;padding:11px 20px;border-radius:8px;background:' . $accent . ';color:#fff;font-weight:bold;text-decoration:none}.nf-quote__pay-note{font-size:11px;color:#6b7280;margin-top:6px}.nf-quote__toolbar-btn--pay{background:' . $accent . ';color:#fff;border-color:' . $accent . '}';
		$css .= '.nf-quote__section{margin-top:18px}.nf-quote__text{color:#374151;white-space:normal}';
		$css .= '.nf-quote__footer{margin-top:28px;padding-top:12px;border-top:1px solid #e5e7eb;color:#6b7280;font-size:10.5px}.nf-quote__disclaimer{margin-top:6px;color:#9ca3af}';
		if ( $mode === 'pdf' ) {
			$css .= 'body{background:#fff}.nf-quote__page{margin:0;padding:0;max-width:none}';
		} else {
			$css .= '@media print{body{background:#fff}.nf-quote__toolbar{display:none}.nf-quote__page{margin:0;max-width:none;padding:0}}';
		}
		return $css;
	}

	private static function date( string $mysql_utc ): string {
		$ts = $mysql_utc !== '' ? strtotime( $mysql_utc . ' UTC' ) : false;
		if ( false === $ts ) {
			return '';
		}
		if ( function_exists( 'wp_date' ) && function_exists( 'get_option' ) ) {
			return wp_date( (string) get_option( 'date_format', 'F j, Y' ), $ts );
		}
		return gmdate( 'F j, Y', $ts );
	}

	public static function esc( string $s ): string {
		return htmlspecialchars( $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}

	private static function t( string $s ): string {
		return function_exists( '__' ) ? __( $s, 'neural-forms-lite' ) : $s;
	}
}
