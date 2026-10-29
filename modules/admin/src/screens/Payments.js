/**
 * Payments page (1.2.0 part B) — ?page=neural-forms-payments.
 *
 * Every payment attempt across all forms (GET /payments), per-currency
 * totals, filters, and the money actions: open the entry, preview or
 * email the invoice, refund (full or partial) through the gateway.
 * Locked → Pro lock card. Shares settings.css for the hero + cards.
 */

import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../api';

const STATUS_OPTIONS = [
	[ '', __( 'All statuses', 'neural-forms-lite' ) ],
	[ 'paid,partially_refunded,refunded', __( 'Paid (incl. refunded)', 'neural-forms-lite' ) ],
	[ 'paid', __( 'Paid', 'neural-forms-lite' ) ],
	[ 'pending,processing', __( 'Open', 'neural-forms-lite' ) ],
	[ 'failed,expired', __( 'Failed / cancelled', 'neural-forms-lite' ) ],
	[ 'partially_refunded,refunded', __( 'Refunded', 'neural-forms-lite' ) ],
];

function exponentFor( c ) { return { BIF: 0, CLP: 0, DJF: 0, GNF: 0, JPY: 0, KMF: 0, KRW: 0, MGA: 0, PYG: 0, RWF: 0, UGX: 0, VND: 0, VUV: 0, XAF: 0, XOF: 0, XPF: 0, BHD: 3, IQD: 3, JOD: 3, KWD: 3, LYD: 3, OMR: 3, TND: 3 }[ c ] ?? 2; }
function fmt( minor, c ) {
	const exp = exponentFor( c ); const sym = { USD: '$', EUR: '€', GBP: '£', INR: '₹', AUD: 'A$', CAD: 'C$', NZD: 'NZ$', SGD: 'S$', JPY: '¥', ZAR: 'R', BRL: 'R$', MXN: 'MX$' }[ c ] ?? ( c + ' ' );
	const n = Number( minor ) || 0; const abs = Math.abs( n ); const whole = Math.floor( abs / Math.pow( 10, exp ) ); const frac = String( abs % Math.pow( 10, exp ) ).padStart( exp, '0' );
	return ( n < 0 ? '-' : '' ) + sym + whole.toString().replace( /\B(?=(\d{3})+(?!\d))/g, ',' ) + ( exp ? '.' + frac : '' );
}
function toMinor( dec, c ) { return Math.round( ( parseFloat( dec ) || 0 ) * Math.pow( 10, exponentFor( c ) ) ); }
function fmtDate( s ) { if ( ! s ) return ''; const d = new Date( s.replace( ' ', 'T' ) + 'Z' ); return isNaN( d ) ? s : d.toLocaleString(); }
function previewUrl( id ) { const root = window.neuralFormsAdmin?.api?.root || '/wp-json/'; const nonce = window.neuralFormsAdmin?.api?.nonce || ''; return `${ root }neural-forms/v1/payments/${ id }/invoice?_wpnonce=${ encodeURIComponent( nonce ) }`; }

function ProLockCard() {
	return (
		<div className="neural-forms-settings__pro-card">
			<span className="neural-forms-settings__pro-icon" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2" /><path d="M7 11V7a5 5 0 0 1 10 0v4" /></svg></span>
			<div className="neural-forms-settings__pro-body">
				<h3 className="neural-forms-settings__pro-title">{ __( 'Payments through Stripe and PayPal', 'neural-forms-lite' ) }</h3>
				<p className="neural-forms-settings__pro-desc">{ __( 'Take deposits or full payments when a priced form is submitted, send invoices, issue refunds and see every payment in one place.', 'neural-forms-lite' ) }</p>
				<a className="neural-forms-settings__pro-cta" href="https://neuralforms.ai/pricing" target="_blank" rel="noopener noreferrer">{ __( 'Upgrade to unlock', 'neural-forms-lite' ) }</a>
			</div>
		</div>
	);
}

function RefundDialog( { payment, onClose, onDone } ) {
	const c = payment.currency;
	const max = payment.refundable_minor || 0;
	const [ amount, setAmount ] = useState( ( max / Math.pow( 10, exponentFor( c ) ) ).toFixed( exponentFor( c ) ) );
	const [ reason, setReason ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const minor = toMinor( amount, c );
	const submit = () => {
		if ( minor <= 0 || minor > max ) { setError( sprintf( __( 'Enter an amount between %1$s and %2$s.', 'neural-forms-lite' ), fmt( 1, c ), fmt( max, c ) ) ); return; }
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( sprintf( __( 'Refund %1$s via %2$s? This cannot be undone.', 'neural-forms-lite' ), fmt( minor, c ), payment.provider_label ) ) ) return;
		setBusy( true ); setError( '' );
		api( `/payments/${ payment.id }/refund`, { method: 'POST', data: { amount_minor: minor, reason } } )
			.then( ( r ) => { onDone( r ); onClose(); } )
			.catch( ( e ) => setError( e?.message || __( 'The refund failed.', 'neural-forms-lite' ) ) )
			.finally( () => setBusy( false ) );
	};
	return (
		<div className="nf-pay-dialog" role="dialog" aria-modal="true" aria-label={ __( 'Refund payment', 'neural-forms-lite' ) }>
			<div className="nf-pay-dialog__box">
				<h3>{ __( 'Refund payment', 'neural-forms-lite' ) }</h3>
				<p className="neural-forms-settings__hint">{ sprintf( __( 'Paid %1$s via %2$s. Up to %3$s can be refunded. The money goes back through the gateway; the entry\'s status updates when it confirms.', 'neural-forms-lite' ), fmt( payment.amount_paid_minor, c ), payment.provider_label, fmt( max, c ) ) }</p>
				<label className="nf-pay-dialog__field"><span>{ sprintf( __( 'Amount (%s)', 'neural-forms-lite' ), c ) }</span><input type="text" inputMode="decimal" value={ amount } onChange={ ( e ) => setAmount( e.target.value ) } /></label>
				<label className="nf-pay-dialog__field"><span>{ __( 'Reason (optional, shown to the customer where the gateway supports it)', 'neural-forms-lite' ) }</span><input type="text" maxLength={ 200 } value={ reason } onChange={ ( e ) => setReason( e.target.value ) } /></label>
				{ error && <p className="neural-forms-settings__msg is-error" role="alert">{ error }</p> }
				<div className="nf-pay-dialog__actions">
					<button type="button" className="neural-forms-settings__btn is-primary is-danger" disabled={ busy } onClick={ submit }>{ busy ? __( 'Refunding…', 'neural-forms-lite' ) : __( 'Refund', 'neural-forms-lite' ) }</button>
					<button type="button" className="neural-forms-settings__btn" disabled={ busy } onClick={ onClose }>{ __( 'Cancel', 'neural-forms-lite' ) }</button>
				</div>
			</div>
		</div>
	);
}

function SendInvoiceDialog( { payment, onClose, onDone } ) {
	const [ to, setTo ] = useState( payment.meta?.customer_email || '' );
	const [ message, setMessage ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const submit = () => {
		setBusy( true ); setError( '' );
		api( `/payments/${ payment.id }/invoice/send`, { method: 'POST', data: { to, message } } )
			.then( ( r ) => { onDone( r ); onClose(); } )
			.catch( ( e ) => setError( e?.message || __( 'Could not send the invoice.', 'neural-forms-lite' ) ) )
			.finally( () => setBusy( false ) );
	};
	return (
		<div className="nf-pay-dialog" role="dialog" aria-modal="true" aria-label={ __( 'Send invoice', 'neural-forms-lite' ) }>
			<div className="nf-pay-dialog__box">
				<h3>{ __( 'Send invoice', 'neural-forms-lite' ) }</h3>
				<label className="nf-pay-dialog__field"><span>{ __( 'To', 'neural-forms-lite' ) }</span><input type="email" value={ to } onChange={ ( e ) => setTo( e.target.value ) } /></label>
				<label className="nf-pay-dialog__field"><span>{ __( 'Note (optional)', 'neural-forms-lite' ) }</span><textarea rows={ 3 } value={ message } onChange={ ( e ) => setMessage( e.target.value ) } /></label>
				{ error && <p className="neural-forms-settings__msg is-error" role="alert">{ error }</p> }
				<div className="nf-pay-dialog__actions">
					<button type="button" className="neural-forms-settings__btn is-primary" disabled={ busy || ! to } onClick={ submit }>{ busy ? __( 'Sending…', 'neural-forms-lite' ) : __( 'Send email', 'neural-forms-lite' ) }</button>
					<button type="button" className="neural-forms-settings__btn" disabled={ busy } onClick={ onClose }>{ __( 'Cancel', 'neural-forms-lite' ) }</button>
				</div>
			</div>
		</div>
	);
}

export default function Payments() {
	const caps     = ( typeof window !== 'undefined' && window.neuralFormsAdmin?.plugin?.capabilities ) || {};
	const unlocked = !! caps.payments;
	const [ filters, setFilters ] = useState( { status: '', provider: '', kind: '', date_from: '', date_to: '', search: '' } );
	const [ data, setData ] = useState( null );
	const [ page, setPage ] = useState( 1 );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ notice, setNotice ] = useState( '' );
	const [ dialog, setDialog ] = useState( null ); // { kind: 'refund'|'send', payment }

	const load = useCallback( ( p = 1, append = false ) => {
		setLoading( true ); setError( '' );
		const params = new URLSearchParams();
		Object.entries( filters ).forEach( ( [ k, v ] ) => { if ( v ) params.set( k, v ); } );
		params.set( 'page', String( p ) ); params.set( 'per_page', '50' );
		api( '/payments?' + params.toString() )
			.then( ( res ) => { setData( ( prev ) => append && prev ? { ...res, payments: [ ...prev.payments, ...res.payments ] } : res ); setPage( p ); } )
			.catch( ( e ) => setError( e?.message || __( 'Could not load payments.', 'neural-forms-lite' ) ) )
			.finally( () => setLoading( false ) );
	}, [ filters ] );
	useEffect( () => { if ( unlocked ) load( 1 ); }, [ load, unlocked ] );

	const replaceRow = ( row ) => setData( ( prev ) => prev ? { ...prev, payments: prev.payments.map( ( p ) => p.id === row.id ? { ...p, ...row } : p ) } : prev );
	const setF = ( k ) => ( e ) => setFilters( { ...filters, [ k ]: e.target.value } );

	return (
		<div className="neural-forms-settings neural-forms-payments">
			<header className="neural-forms-settings__header">
				<div className="neural-forms-settings__header-text">
					<h1 className="neural-forms-settings__page-title">{ __( 'Payments', 'neural-forms-lite' ) }</h1>
					<p className="neural-forms-settings__page-desc">{ __( 'Every payment taken through your forms: deposits, full payments, refunds and open checkouts. Send invoices and issue refunds from here; the gateway confirms every change.', 'neural-forms-lite' ) }</p>
				</div>
			</header>
			{ ! unlocked && <ProLockCard /> }
			{ unlocked && (
				<>
					{ data && data.totals && data.totals.length > 0 && (
						<div className="nf-pay-totals">
							{ data.totals.map( ( t, i ) => (
								<div key={ i } className={ 'nf-pay-totals__card' + ( t.livemode ? '' : ' is-test' ) }>
									<div className="nf-pay-totals__head">{ t.currency }{ ! t.livemode && <span className="nf-pay-test-badge">{ __( 'test', 'neural-forms-lite' ) }</span> }</div>
									<div className="nf-pay-totals__row"><span>{ __( 'Received', 'neural-forms-lite' ) }</span><strong>{ fmt( t.paid, t.currency ) }</strong></div>
									<div className="nf-pay-totals__row"><span>{ __( 'Refunded', 'neural-forms-lite' ) }</span><strong>−{ fmt( t.refunded, t.currency ) }</strong></div>
									<div className="nf-pay-totals__row is-net"><span>{ __( 'Net', 'neural-forms-lite' ) }</span><strong>{ fmt( t.net, t.currency ) }</strong></div>
									<div className="nf-pay-totals__meta">{ sprintf( __( '%1$d paid · %2$d open (%3$s) · %4$d failed', 'neural-forms-lite' ), t.paid_count, t.open_count, fmt( t.open, t.currency ), t.failed_count ) }</div>
								</div>
							) ) }
						</div>
					) }
					{ data && data.providers && data.providers.filter( ( p ) => p.configured ).length === 0 && (
						<p className="neural-forms-settings__hint nf-pay-hint">{ __( 'No payment provider is connected yet. Connect Stripe or PayPal under Integrations, then switch payments on per form under Form settings › Payments.', 'neural-forms-lite' ) }</p>
					) }
					<section className="neural-forms-settings__section">
						<div className="nf-pay-filters">
							<select value={ filters.status } onChange={ setF( 'status' ) } aria-label={ __( 'Status', 'neural-forms-lite' ) }>{ STATUS_OPTIONS.map( ( [ v, l ] ) => <option key={ v } value={ v }>{ l }</option> ) }</select>
							<select value={ filters.provider } onChange={ setF( 'provider' ) } aria-label={ __( 'Provider', 'neural-forms-lite' ) }><option value="">{ __( 'All providers', 'neural-forms-lite' ) }</option>{ ( data?.providers || [] ).map( ( p ) => <option key={ p.id } value={ p.id }>{ p.label }</option> ) }</select>
							<select value={ filters.kind } onChange={ setF( 'kind' ) } aria-label={ __( 'Kind', 'neural-forms-lite' ) }><option value="">{ __( 'Deposits and full', 'neural-forms-lite' ) }</option><option value="deposit">{ __( 'Deposits', 'neural-forms-lite' ) }</option><option value="full">{ __( 'Full payments', 'neural-forms-lite' ) }</option></select>
							<input type="date" value={ filters.date_from } onChange={ setF( 'date_from' ) } aria-label={ __( 'From', 'neural-forms-lite' ) } />
							<input type="date" value={ filters.date_to } onChange={ setF( 'date_to' ) } aria-label={ __( 'To', 'neural-forms-lite' ) } />
							<input type="search" value={ filters.search } placeholder={ __( 'Entry #, email, reference, invoice…', 'neural-forms-lite' ) } onChange={ setF( 'search' ) } aria-label={ __( 'Search', 'neural-forms-lite' ) } />
						</div>
						{ error && <p className="neural-forms-settings__msg is-error nf-pay-msg" role="alert">{ error }</p> }
						{ notice && <p className="neural-forms-settings__msg nf-pay-msg" role="status">{ notice }</p> }
						<table className="nf-pay-table">
							<thead><tr><th>{ __( 'Date', 'neural-forms-lite' ) }</th><th>{ __( 'Customer', 'neural-forms-lite' ) }</th><th>{ __( 'Form', 'neural-forms-lite' ) }</th><th>{ __( 'Provider', 'neural-forms-lite' ) }</th><th className="is-num">{ __( 'Amount', 'neural-forms-lite' ) }</th><th>{ __( 'Status', 'neural-forms-lite' ) }</th><th>{ __( 'Invoice', 'neural-forms-lite' ) }</th><th /></tr></thead>
							<tbody>
								{ data && data.payments.length === 0 && <tr><td colSpan={ 8 } className="nf-pay-empty">{ loading ? __( 'Loading…', 'neural-forms-lite' ) : __( 'No payments match these filters.', 'neural-forms-lite' ) }</td></tr> }
								{ data && data.payments.map( ( p ) => {
									const settled = [ 'paid', 'partially_refunded', 'refunded' ].includes( p.status );
									return (
										<tr key={ p.id } className={ 'is-' + p.status }>
											<td>{ fmtDate( p.paid_at || p.created_at ) }{ ! p.livemode && <span className="nf-pay-test-badge">{ __( 'test', 'neural-forms-lite' ) }</span> }</td>
											<td><a href={ p.entry_url }>{ p.meta?.customer_name || p.meta?.customer_email || sprintf( __( 'Entry #%d', 'neural-forms-lite' ), p.entry_id ) }</a>{ p.meta?.customer_name && p.meta?.customer_email && <small>{ p.meta.customer_email }</small> }</td>
											<td>{ p.meta?.form_name || `#${ p.form_id }` }<small>{ p.kind === 'deposit' ? __( 'Deposit', 'neural-forms-lite' ) : __( 'Full', 'neural-forms-lite' ) }{ p.quote_id ? ' · ' + __( 'quote', 'neural-forms-lite' ) : '' }</small></td>
											<td>{ p.provider_label }{ p.provider_payment_id && <small><code>{ p.provider_payment_id }</code></small> }</td>
											<td className="is-num">{ settled ? p.formatted.paid : p.formatted.due }{ p.amount_refunded_minor > 0 && <small>−{ p.formatted.refunded }</small> }</td>
											<td><span className={ 'neural-forms-entries-pill neural-forms-entries-pill--pay neural-forms-entries-pill--pay-' + p.status }>{ p.status_label }</span></td>
											<td>{ settled ? ( p.invoice?.number || __( 'not issued', 'neural-forms-lite' ) ) : '—' }{ p.invoice?.sent_at && <small>{ sprintf( __( 'sent %s', 'neural-forms-lite' ), fmtDate( p.invoice.sent_at ) ) }</small> }</td>
											<td className="nf-pay-actions">
												<a className="neural-forms-settings__btn" href={ p.entry_url }>{ __( 'Entry', 'neural-forms-lite' ) }</a>
												{ settled && <a className="neural-forms-settings__btn" href={ previewUrl( p.id ) } target="_blank" rel="noopener noreferrer">{ __( 'Invoice', 'neural-forms-lite' ) }</a> }
												{ settled && <button type="button" className="neural-forms-settings__btn" onClick={ () => setDialog( { kind: 'send', payment: p } ) }>{ __( 'Send', 'neural-forms-lite' ) }</button> }
												{ settled && p.refundable_minor > 0 && <button type="button" className="neural-forms-settings__btn is-danger" onClick={ () => setDialog( { kind: 'refund', payment: p } ) }>{ __( 'Refund', 'neural-forms-lite' ) }</button> }
												{ p.dashboard_url && <a className="neural-forms-settings__btn" href={ p.dashboard_url } target="_blank" rel="noopener noreferrer">{ p.provider_label }</a> }
											</td>
										</tr>
									);
								} ) }
							</tbody>
						</table>
						{ data && data.payments.length < data.total && (
							<div className="nf-pay-more"><button type="button" className="neural-forms-settings__btn" disabled={ loading } onClick={ () => load( page + 1, true ) }>{ loading ? __( 'Loading…', 'neural-forms-lite' ) : sprintf( __( 'Load more (%1$d of %2$d)', 'neural-forms-lite' ), data.payments.length, data.total ) }</button></div>
						) }
					</section>
				</>
			) }
			{ dialog && dialog.kind === 'refund' && <RefundDialog payment={ dialog.payment } onClose={ () => setDialog( null ) } onDone={ ( r ) => { if ( r?.payment ) replaceRow( r.payment ); setNotice( __( 'Refund issued.', 'neural-forms-lite' ) ); load( 1 ); } } /> }
			{ dialog && dialog.kind === 'send' && <SendInvoiceDialog payment={ dialog.payment } onClose={ () => setDialog( null ) } onDone={ ( r ) => { if ( r?.payment ) replaceRow( r.payment ); setNotice( sprintf( __( 'Invoice %s sent.', 'neural-forms-lite' ), r?.number || '' ) ); } } /> }
		</div>
	);
}
