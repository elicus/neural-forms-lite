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
					</div>
					{ unlocked && (
						<div className="neural-forms-entry-detail__quote-actions">
							{ ( a.status === 'pending' || a.status === 'processing' || a.status === 'paid' ) && a.session_id && (
								<button type="button" className="neural-forms-entry-detail__quote-btn" disabled={ busy !== '' } onClick={ () => check( a.id ) }>{ busy === String( a.id ) ? __( 'Checking…', 'neural-forms-lite' ) : __( 'Check status now', 'neural-forms-lite' ) }</button>
							) }
							{ a.status === 'pending' && a.checkout_url && <button type="button" className="neural-forms-entry-detail__quote-btn" onClick={ () => copy( a.checkout_url ) }>{ __( 'Copy checkout link', 'neural-forms-lite' ) }</button> }
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
