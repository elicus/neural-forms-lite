/**
 * Payment card (1.2.0 part B) — under the Pricing card in the entry
 * detail. Reads /neural-forms/v1/entries/{id}/payments: the aggregate
 * (paid / remaining, PAY2), every attempt with its gateway state, and a
 * "Check status now" button that reconciles against the gateway (PAY12).
 * Nothing here can mark a payment paid; only verified gateway state does.
 */

import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../api';
import { formatDate } from './helpers';

const TONE = { paid: 'ok', partially_refunded: 'ok', refunded: 'muted', processing: 'wait', pending: 'wait', failed: 'bad', expired: 'muted', replaced: 'muted' };

export default function PaymentCard( { entry, capabilities } ) {
	const entryId  = entry?.id;
	const unlocked = !! ( capabilities && capabilities.payments );
	const [ data,   setData ]   = useState( null );
	const [ busy,   setBusy ]   = useState( '' );
	const [ error,  setError ]  = useState( '' );
	const [ notice, setNotice ] = useState( '' );

	const load = () => entryId ? api( `/entries/${ entryId }/payments` ).then( setData ).catch( ( e ) => setError( e?.message || __( 'Could not load payments.', 'neural-forms-lite' ) ) ) : null;
	useEffect( () => { setData( null ); setError( '' ); setNotice( '' ); load(); }, [ entryId ] ); // eslint-disable-line react-hooks/exhaustive-deps

	if ( ! entryId || ! entry.pricing || ( entry.payment_status || 'none' ) === 'none' && ! ( data && data.attempts && data.attempts.length ) ) return null;

	const check = ( id ) => {
		setBusy( String( id ) ); setError( '' ); setNotice( '' );
		api( `/payments/${ id }/check`, { method: 'POST' } )
			.then( ( r ) => { if ( r?.summary ) setData( r.summary ); setNotice( r?.result === 'applied' ? __( 'Status updated from the gateway.', 'neural-forms-lite' ) : __( 'No change — the gateway reports the same state.', 'neural-forms-lite' ) ); } )
			.catch( ( e ) => setError( e?.message || __( 'Could not check the payment.', 'neural-forms-lite' ) ) )
			.finally( () => setBusy( '' ) );
	};
	const invoiceUrl = ( id ) => { const root = window.neuralFormsAdmin?.api?.root || '/wp-json/'; const nonce = window.neuralFormsAdmin?.api?.nonce || ''; return `${ root }neural-forms/v1/payments/${ id }/invoice?_wpnonce=${ encodeURIComponent( nonce ) }`; };
	const sendInvoice = ( a ) => {
		// eslint-disable-next-line no-alert
		const to = window.prompt( __( 'Email the invoice to:', 'neural-forms-lite' ), a.meta?.customer_email || '' );
		if ( ! to ) return;
		setBusy( 'inv' + a.id ); setError( '' ); setNotice( '' );
		api( `/payments/${ a.id }/invoice/send`, { method: 'POST', data: { to } } )
			.then( ( r ) => { setNotice( sprintf( __( 'Invoice %1$s sent to %2$s.', 'neural-forms-lite' ), r?.number || '', to ) ); return load(); } )
			.catch( ( e ) => setError( e?.message || __( 'Could not send the invoice.', 'neural-forms-lite' ) ) )
			.finally( () => setBusy( '' ) );
	};
	const refund = ( a ) => {
		const c = a.currency; const exp = { JPY: 0, KRW: 0, BHD: 3, KWD: 3 }[ c ] ?? 2; const max = a.refundable_minor || 0;
		const def = ( max / Math.pow( 10, exp ) ).toFixed( exp );
		// eslint-disable-next-line no-alert
		const raw = window.prompt( sprintf( __( 'Refund amount in %1$s (up to %2$s). This goes back through %3$s and cannot be undone.', 'neural-forms-lite' ), c, def, a.provider_label ), def );
		if ( raw === null ) return;
		const minor = Math.round( ( parseFloat( raw ) || 0 ) * Math.pow( 10, exp ) );
		if ( minor <= 0 || minor > max ) { setError( __( 'Enter a valid refund amount.', 'neural-forms-lite' ) ); return; }
		// eslint-disable-next-line no-alert
		const reason = window.prompt( __( 'Reason (optional):', 'neural-forms-lite' ), '' ) || '';
		setBusy( 'ref' + a.id ); setError( '' ); setNotice( '' );
		api( `/payments/${ a.id }/refund`, { method: 'POST', data: { amount_minor: minor, reason } } )
			.then( ( r ) => { if ( r?.summary ) setData( r.summary ); setNotice( __( 'Refund issued through the gateway.', 'neural-forms-lite' ) ); return load(); } )
			.catch( ( e ) => setError( e?.message || __( 'The refund failed.', 'neural-forms-lite' ) ) )
			.finally( () => setBusy( '' ) );
	};
	const copy = ( url ) => {
		if ( navigator.clipboard?.writeText ) navigator.clipboard.writeText( url ).then( () => setNotice( __( 'Checkout link copied.', 'neural-forms-lite' ) ) );
		else window.prompt( __( 'Copy this link:', 'neural-forms-lite' ), url ); // eslint-disable-line no-alert
	};

	const status = data?.status || entry.payment_status || 'pending';
	return (
		<section className="neural-forms-entry-detail__data-card neural-forms-entry-detail__payments" aria-label={ __( 'Payments', 'neural-forms-lite' ) }>
			<h4 className="neural-forms-entry-detail__data-card-title">
				{ __( 'Payment', 'neural-forms-lite' ) }
				<span className={ 'neural-forms-entries-pill neural-forms-entries-pill--pay neural-forms-entries-pill--pay-' + status }>{ data?.status_label || status }</span>
			</h4>
			{ error && <p className="neural-forms-entry-detail__lead-error" role="alert">{ error }</p> }
			{ notice && <p className="neural-forms-entry-detail__quote-notice" role="status">{ notice }</p> }
			{ data && (
				<dl className="neural-forms-entry-detail__pricing-totals">
					<dt>{ __( 'Amount paid', 'neural-forms-lite' ) }</dt><dd>{ data.formatted.paid }</dd>
					{ data.amounts.refunded > 0 && <><dt>{ __( 'Refunded', 'neural-forms-lite' ) }</dt><dd>−{ data.formatted.refunded }</dd></> }
					<dt className="is-total">{ __( 'Remaining balance', 'neural-forms-lite' ) }</dt><dd className="is-total">{ data.formatted.remaining }</dd>
				</dl>
			) }
			{ data?.needs_reconciliation && (
				<p className="neural-forms-entry-detail__pay-warning" role="alert">{ __( 'Needs a look: more than one payment succeeded, or an event did not match this record. Nothing was refunded automatically — review it in your gateway dashboard.', 'neural-forms-lite' ) }</p>
			) }
			{ data && data.attempts.length === 0 && <p className="neural-forms-entry-detail__quote-hint">{ __( 'No checkout has been started for this entry yet.', 'neural-forms-lite' ) }</p> }
			{ data && data.attempts.map( ( a ) => (
				<div key={ a.id } className={ 'neural-forms-entry-detail__pay-attempt is-' + ( TONE[ a.status ] || 'muted' ) }>
					<div className="neural-forms-entry-detail__pay-attempt-head">
						<span><strong>{ a.provider_label }</strong> · { a.kind === 'deposit' ? __( 'Deposit', 'neural-forms-lite' ) : __( 'Full amount', 'neural-forms-lite' ) } · { a.formatted.due }{ ! a.livemode && <em> · { __( 'test', 'neural-forms-lite' ) }</em> }</span>
						<span className={ 'neural-forms-entries-pill neural-forms-entries-pill--pay neural-forms-entries-pill--pay-' + a.status }>{ a.status_label }</span>
					</div>
					<div className="neural-forms-entry-detail__quote-meta">
						<span>{ sprintf( __( 'Attempt %d', 'neural-forms-lite' ), a.attempt ) }</span>
						<span>{ formatDate( a.created_at ) }</span>
						{ a.paid_at && <span>{ sprintf( __( 'Paid %s', 'neural-forms-lite' ), formatDate( a.paid_at ) ) }</span> }
						{ a.amount_refunded_minor > 0 && <span>{ sprintf( __( 'Refunded %s', 'neural-forms-lite' ), a.formatted.refunded ) }</span> }
						{ a.provider_payment_id && <span><code>{ a.provider_payment_id }</code></span> }
						{ a.invoice?.sent_at && <span>{ sprintf( __( 'Invoice sent to %s', 'neural-forms-lite' ), a.invoice.sent_to || '' ) }</span> }
					</div>
					{ unlocked && (
						<div className="neural-forms-entry-detail__quote-actions">
							{ ( a.status === 'pending' || a.status === 'processing' || a.status === 'paid' ) && a.session_id && (
								<button type="button" className="neural-forms-entry-detail__quote-btn" disabled={ busy !== '' } onClick={ () => check( a.id ) }>{ busy === String( a.id ) ? __( 'Checking…', 'neural-forms-lite' ) : __( 'Check status now', 'neural-forms-lite' ) }</button>
							) }
							{ a.status === 'pending' && a.checkout_url && <button type="button" className="neural-forms-entry-detail__quote-btn" onClick={ () => copy( a.checkout_url ) }>{ __( 'Copy checkout link', 'neural-forms-lite' ) }</button> }
							{ [ 'paid', 'partially_refunded', 'refunded' ].includes( a.status ) && <a className="neural-forms-entry-detail__quote-btn" href={ invoiceUrl( a.id ) } target="_blank" rel="noopener noreferrer">{ a.invoice?.number ? sprintf( __( 'Invoice %s', 'neural-forms-lite' ), a.invoice.number ) : __( 'Invoice', 'neural-forms-lite' ) }</a> }
							{ [ 'paid', 'partially_refunded', 'refunded' ].includes( a.status ) && <button type="button" className="neural-forms-entry-detail__quote-btn" disabled={ busy !== '' } onClick={ () => sendInvoice( a ) }>{ busy === 'inv' + a.id ? __( 'Sending…', 'neural-forms-lite' ) : __( 'Send invoice', 'neural-forms-lite' ) }</button> }
							{ a.refundable_minor > 0 && <button type="button" className="neural-forms-entry-detail__quote-btn is-danger" disabled={ busy !== '' } onClick={ () => refund( a ) }>{ busy === 'ref' + a.id ? __( 'Refunding…', 'neural-forms-lite' ) : __( 'Refund…', 'neural-forms-lite' ) }</button> }
							{ a.dashboard_url && <a className="neural-forms-entry-detail__quote-btn" href={ a.dashboard_url } target="_blank" rel="noopener noreferrer">{ sprintf( __( 'Open in %s', 'neural-forms-lite' ), a.provider_label ) }</a> }
						</div>
					) }
					{ a.events && a.events.length > 0 && (
						<details className="neural-forms-entry-detail__pay-events">
							<summary>{ sprintf( __( '%d gateway events', 'neural-forms-lite' ), a.events.length ) }</summary>
							<ul>{ a.events.map( ( ev ) => <li key={ ev.id }><code>{ ev.event_type }</code> → { ev.result || __( 'pending', 'neural-forms-lite' ) } <small>{ formatDate( ev.received_at ) }</small></li> ) }</ul>
						</details>
					) }
				</div>
			) ) }
			{ ! unlocked && <p className="neural-forms-entry-detail__lead-upsell">{ __( 'Take deposits and payments through Stripe or PayPal with Neural Forms Pro.', 'neural-forms-lite' ) }</p> }
		</section>
	);
}
