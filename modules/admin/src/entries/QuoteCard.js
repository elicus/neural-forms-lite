/**
 * Quote card (1.2.0 part B) — shown in the entry detail under the
 * Pricing card. Lists every quote revision for the entry and drives the
 * lifecycle through /neural-forms/v1/quotes/*:
 *
 *   draft     → Edit (lines, discount, deposit, texts, customer), Issue, Delete, Preview
 *   issued    → View, PDF, Send, Copy link, New revision
 *   expired   → View, New revision
 *   superseded→ View
 *
 * Locked (no `quotes` capability): existing quotes stay readable, the
 * actions are replaced by an upsell line. Nothing here changes the lead
 * stage or payment status — quotes are their own record.
 */

import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../api';
import { formatDate, formatMinor } from './helpers';

const STATUS_LABELS = {
	draft:      __( 'Draft', 'neural-forms-lite' ),
	issued:     __( 'Issued', 'neural-forms-lite' ),
	expired:    __( 'Expired', 'neural-forms-lite' ),
	superseded: __( 'Superseded', 'neural-forms-lite' ),
};

function exponentFor( currency ) {
	return { BIF: 0, CLP: 0, DJF: 0, GNF: 0, JPY: 0, KMF: 0, KRW: 0, MGA: 0, PYG: 0, RWF: 0, UGX: 0, VND: 0, VUV: 0, XAF: 0, XOF: 0, XPF: 0, BHD: 3, IQD: 3, JOD: 3, KWD: 3, LYD: 3, OMR: 3, TND: 3 }[ currency ] ?? 2;
}
function minorToDecimal( minor, currency ) {
	const exp = exponentFor( currency );
	const n = Number( minor ) || 0;
	return ( n / Math.pow( 10, exp ) ).toFixed( exp );
}

function previewUrl( id ) {
	const root  = window.neuralFormsAdmin?.api?.root || '/wp-json/';
	const nonce = window.neuralFormsAdmin?.api?.nonce || '';
	return `${ root }neural-forms/v1/quotes/${ id }/preview?_wpnonce=${ encodeURIComponent( nonce ) }`;
}

function errorMessage( err, fallback ) {
	return ( err && err.message ) || fallback;
}

export default function QuoteCard( { entry, capabilities } ) {
	const entryId  = entry?.id;
	const unlocked = !! ( capabilities && capabilities.quotes );
	const [ quotes,   setQuotes   ] = useState( null );
	const [ settings, setSettings ] = useState( null );
	const [ busy,     setBusy     ] = useState( '' );
	const [ error,    setError    ] = useState( '' );
	const [ notice,   setNotice   ] = useState( '' );
	const [ editing,  setEditing  ] = useState( null );   // { id, form }
	const [ sending,  setSending  ] = useState( null );   // { id, to, message }

	const load = () => {
		if ( ! entryId ) return;
		return api( `/entries/${ entryId }/quotes` ).then( ( list ) => setQuotes( Array.isArray( list ) ? list : [] ) ).catch( ( e ) => setError( errorMessage( e, __( 'Could not load quotes.', 'neural-forms-lite' ) ) ) );
	};

	useEffect( () => {
		setQuotes( null ); setError( '' ); setNotice( '' ); setEditing( null ); setSending( null );
		load();
		if ( unlocked ) {
			api( '/quotes/settings' ).then( setSettings ).catch( () => setSettings( null ) );
		}
	}, [ entryId ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const hasPricing = entry && entry.pricing && typeof entry.pricing === 'object';
	if ( ! entryId || ( ! hasPricing && ( ! quotes || quotes.length === 0 ) ) ) return null;

	const run = ( key, promise, successNotice ) => {
		setBusy( key ); setError( '' ); setNotice( '' );
		return promise
			.then( ( res ) => { if ( successNotice ) setNotice( successNotice ); return res; } )
			.catch( ( e ) => { setError( errorMessage( e, __( 'Something went wrong.', 'neural-forms-lite' ) ) ); throw e; } )
			.finally( () => setBusy( '' ) );
	};

	const createDraft = () => run( 'create', api( `/entries/${ entryId }/quotes`, { method: 'POST', data: {} } ).then( load ), __( 'Draft quote created. Review it, then issue it.', 'neural-forms-lite' ) ).catch( () => {} );
	const issue = ( id ) => {
		if ( ! window.confirm( __( 'Issue this quote? It gets a number and becomes read-only; corrections will need a new revision.', 'neural-forms-lite' ) ) ) return;
		run( 'issue' + id, api( `/quotes/${ id }/issue`, { method: 'POST' } ).then( load ), __( 'Quote issued.', 'neural-forms-lite' ) ).catch( () => {} );
	};
	const remove = ( id ) => {
		if ( ! window.confirm( __( 'Delete this draft?', 'neural-forms-lite' ) ) ) return;
		run( 'delete' + id, api( `/quotes/${ id }`, { method: 'DELETE' } ).then( load ) ).catch( () => {} );
	};
	const revise = ( id ) => run( 'revise' + id, api( `/quotes/${ id }/revise`, { method: 'POST' } ).then( load ), __( 'New draft revision created.', 'neural-forms-lite' ) ).catch( () => {} );
	const copyLink = ( q ) => {
		const url = q.links && q.links.view;
		if ( ! url ) return;
		const done = () => setNotice( __( 'Link copied.', 'neural-forms-lite' ) );
		if ( navigator.clipboard && navigator.clipboard.writeText ) navigator.clipboard.writeText( url ).then( done, () => window.prompt( __( 'Copy this link:', 'neural-forms-lite' ), url ) );
		else window.prompt( __( 'Copy this link:', 'neural-forms-lite' ), url );
	};
	const startEdit = ( q ) => {
		const s = q.snapshot || {};
		setEditing( {
			id: q.id,
			currency: s.currency || 'USD',
			form: {
				line_items: ( s.line_items || [] ).map( ( l ) => ( { label: l.label || '', description: l.description || '', qty: l.qty || 1, unit: minorToDecimal( l.unit, s.currency ) } ) ),
				discount: s.discount ? { type: s.discount.type, value: s.discount.value, label: s.discount.label || '' } : { type: 'none', value: '', label: '' },
				deposit: s.deposit ? { type: s.deposit.type, value: s.deposit.value } : { type: 'none', value: '' },
				validity_days: q.validity_days || 30,
				customer: { name: s.customer?.name || '', email: s.customer?.email || '' },
				intro: s.intro || '', notes: s.notes || '', terms: s.terms || '',
			},
		} );
	};
	const saveEdit = () => {
		const payload = { ...editing.form };
		if ( payload.discount.type === 'none' ) payload.discount = null;
		if ( payload.deposit.type === 'none' ) payload.deposit = null;
		run( 'save' + editing.id, api( `/quotes/${ editing.id }`, { method: 'PUT', data: payload } ).then( () => { setEditing( null ); return load(); } ), __( 'Draft saved.', 'neural-forms-lite' ) ).catch( () => {} );
	};
	const send = () => {
		run( 'send' + sending.id, api( `/quotes/${ sending.id }/send`, { method: 'POST', data: { to: sending.to, message: sending.message } } ).then( () => { setSending( null ); return load(); } ), __( 'Quote emailed.', 'neural-forms-lite' ) ).catch( () => {} );
	};

	const needsBranding = unlocked && settings && ! settings.is_complete;

	return (
		<section className={ 'neural-forms-entry-detail__data-card neural-forms-entry-detail__quotes' + ( unlocked ? '' : ' is-locked' ) } aria-label={ __( 'Quotes', 'neural-forms-lite' ) }>
			<h4 className="neural-forms-entry-detail__data-card-title">
				{ __( 'Quotes', 'neural-forms-lite' ) }
				{ unlocked && hasPricing && (
					<button type="button" className="neural-forms-entry-detail__quote-btn is-primary" disabled={ busy !== '' } onClick={ createDraft }>
						{ busy === 'create' ? __( 'Creating…', 'neural-forms-lite' ) : __( 'Create quote', 'neural-forms-lite' ) }
					</button>
				) }
			</h4>
			{ ! unlocked && (
				<p className="neural-forms-entry-detail__lead-upsell">{ __( 'Turn this entry into a numbered, branded quote with a PDF and a secure link your customer can open, with Neural Forms Pro.', 'neural-forms-lite' ) }</p>
			) }
			{ needsBranding && (
				<p className="neural-forms-entry-detail__quote-hint">{ __( 'Add your business name and details on the Neural Forms › Quotes page so they appear on the quote.', 'neural-forms-lite' ) }</p>
			) }
			{ error && <p className="neural-forms-entry-detail__lead-error" role="alert">{ error }</p> }
			{ notice && <p className="neural-forms-entry-detail__quote-notice" role="status">{ notice }</p> }
			{ quotes === null && ! error && <p className="neural-forms-entry-detail__quote-hint">{ __( 'Loading…', 'neural-forms-lite' ) }</p> }
			{ quotes && quotes.length === 0 && unlocked && (
				<p className="neural-forms-entry-detail__quote-hint">{ __( 'No quote yet. Create one from the pricing above, review the lines, then issue and send it.', 'neural-forms-lite' ) }</p>
			) }
			{ quotes && quotes.map( ( q ) => (
				<div key={ q.id } className={ 'neural-forms-entry-detail__quote neural-forms-entry-detail__quote--' + q.status }>
					<div className="neural-forms-entry-detail__quote-head">
						<div>
							<strong>{ q.number || __( 'Draft', 'neural-forms-lite' ) }</strong>
							{ q.revision > 1 && <span className="neural-forms-entry-detail__quote-rev">{ sprintf( __( 'rev. %d', 'neural-forms-lite' ), q.revision ) }</span> }
							<span className={ 'neural-forms-entries-pill neural-forms-entries-pill--quote neural-forms-entries-pill--quote-' + q.status }>{ STATUS_LABELS[ q.status ] || q.status }</span>
						</div>
						<div className="neural-forms-entry-detail__quote-amount">{ q.formatted?.total }</div>
					</div>
					<div className="neural-forms-entry-detail__quote-meta">
						{ q.snapshot?.deposit && q.snapshot.deposit.amount > 0 && <span>{ sprintf( __( 'Due now %s', 'neural-forms-lite' ), q.formatted.due_now ) }</span> }
						{ q.valid_until && <span>{ sprintf( __( 'Valid until %s', 'neural-forms-lite' ), formatDate( q.valid_until ) ) }</span> }
						{ q.sent_at && <span>{ sprintf( __( 'Sent %s', 'neural-forms-lite' ), formatDate( q.sent_at ) ) }</span> }
						{ q.status === 'draft' && <span>{ sprintf( __( '%d lines', 'neural-forms-lite' ), ( q.snapshot?.line_items || [] ).length ) }</span> }
					</div>
					{ unlocked && editing?.id !== q.id && sending?.id !== q.id && (
						<div className="neural-forms-entry-detail__quote-actions">
							{ q.status === 'draft' && <>
								<button type="button" className="neural-forms-entry-detail__quote-btn" onClick={ () => startEdit( q ) }>{ __( 'Edit', 'neural-forms-lite' ) }</button>
								<a className="neural-forms-entry-detail__quote-btn" href={ previewUrl( q.id ) } target="_blank" rel="noopener noreferrer">{ __( 'Preview', 'neural-forms-lite' ) }</a>
								<button type="button" className="neural-forms-entry-detail__quote-btn is-primary" disabled={ busy !== '' } onClick={ () => issue( q.id ) }>{ __( 'Issue', 'neural-forms-lite' ) }</button>
								<button type="button" className="neural-forms-entry-detail__quote-btn is-danger" disabled={ busy !== '' } onClick={ () => remove( q.id ) }>{ __( 'Delete', 'neural-forms-lite' ) }</button>
							</> }
							{ q.status === 'issued' && <>
								<a className="neural-forms-entry-detail__quote-btn" href={ q.links?.view || previewUrl( q.id ) } target="_blank" rel="noopener noreferrer">{ __( 'View', 'neural-forms-lite' ) }</a>
								{ q.links?.pdf && <a className="neural-forms-entry-detail__quote-btn" href={ q.links.pdf }>{ __( 'PDF', 'neural-forms-lite' ) }</a> }
								<button type="button" className="neural-forms-entry-detail__quote-btn is-primary" disabled={ busy !== '' } onClick={ () => setSending( { id: q.id, to: q.snapshot?.customer?.email || '', message: '' } ) }>{ __( 'Send', 'neural-forms-lite' ) }</button>
								<button type="button" className="neural-forms-entry-detail__quote-btn" onClick={ () => copyLink( q ) }>{ __( 'Copy link', 'neural-forms-lite' ) }</button>
								<button type="button" className="neural-forms-entry-detail__quote-btn" disabled={ busy !== '' } onClick={ () => revise( q.id ) }>{ __( 'New revision', 'neural-forms-lite' ) }</button>
							</> }
							{ q.status === 'expired' && <>
								<a className="neural-forms-entry-detail__quote-btn" href={ previewUrl( q.id ) } target="_blank" rel="noopener noreferrer">{ __( 'View', 'neural-forms-lite' ) }</a>
								<button type="button" className="neural-forms-entry-detail__quote-btn is-primary" disabled={ busy !== '' } onClick={ () => revise( q.id ) }>{ __( 'New revision', 'neural-forms-lite' ) }</button>
							</> }
							{ q.status === 'superseded' && <a className="neural-forms-entry-detail__quote-btn" href={ previewUrl( q.id ) } target="_blank" rel="noopener noreferrer">{ __( 'View', 'neural-forms-lite' ) }</a> }
						</div>
					) }
					{ editing?.id === q.id && <QuoteEditor state={ editing } setState={ setEditing } busy={ busy === 'save' + q.id } onSave={ saveEdit } onCancel={ () => setEditing( null ) } /> }
					{ sending?.id === q.id && (
						<div className="neural-forms-entry-detail__quote-editor">
							<label className="neural-forms-entry-detail__quote-field"><span>{ __( 'To', 'neural-forms-lite' ) }</span><input type="email" value={ sending.to } onChange={ ( e ) => setSending( { ...sending, to: e.target.value } ) } /></label>
							<label className="neural-forms-entry-detail__quote-field"><span>{ __( 'Message', 'neural-forms-lite' ) }</span><textarea rows={ 3 } value={ sending.message } placeholder={ __( 'Optional note shown above the quote summary.', 'neural-forms-lite' ) } onChange={ ( e ) => setSending( { ...sending, message: e.target.value } ) } /></label>
							<p className="neural-forms-entry-detail__quote-hint">{ settings?.pdf_available ? __( 'The email includes the secure link and the PDF.', 'neural-forms-lite' ) : __( 'The email includes the secure link to the printable quote.', 'neural-forms-lite' ) }</p>
							<div className="neural-forms-entry-detail__quote-actions">
								<button type="button" className="neural-forms-entry-detail__quote-btn is-primary" disabled={ busy !== '' || ! sending.to } onClick={ send }>{ busy === 'send' + q.id ? __( 'Sending…', 'neural-forms-lite' ) : __( 'Send email', 'neural-forms-lite' ) }</button>
								<button type="button" className="neural-forms-entry-detail__quote-btn" onClick={ () => setSending( null ) }>{ __( 'Cancel', 'neural-forms-lite' ) }</button>
							</div>
						</div>
					) }
				</div>
			) ) }
		</section>
	);
}

function QuoteEditor( { state, setState, busy, onSave, onCancel } ) {
	const f = state.form;
	const set = ( patch ) => setState( { ...state, form: { ...f, ...patch } } );
	const setLine = ( i, patch ) => set( { line_items: f.line_items.map( ( l, j ) => j === i ? { ...l, ...patch } : l ) } );
	const removeLine = ( i ) => set( { line_items: f.line_items.filter( ( _, j ) => j !== i ) } );
	const addLine = () => set( { line_items: [ ...f.line_items, { label: '', description: '', qty: 1, unit: '0.00' } ] } );
	const exp = exponentFor( state.currency );
	const toMinor = ( dec ) => Math.round( ( parseFloat( dec ) || 0 ) * Math.pow( 10, exp ) );
	const subtotal = f.line_items.reduce( ( s, l ) => s + toMinor( l.unit ) * ( parseInt( l.qty, 10 ) || 1 ), 0 );
	let discount = 0;
	if ( f.discount.type === 'percent' ) discount = Math.round( subtotal * ( parseFloat( f.discount.value ) || 0 ) / 100 );
	else if ( f.discount.type === 'fixed' ) discount = toMinor( f.discount.value );
	discount = Math.max( 0, Math.min( subtotal, discount ) );
	const total = subtotal - discount;
	let due = total;
	if ( f.deposit.type === 'percent' ) due = Math.min( total, Math.round( total * ( parseFloat( f.deposit.value ) || 0 ) / 100 ) ) || total;
	else if ( f.deposit.type === 'fixed' ) due = Math.min( total, toMinor( f.deposit.value ) ) || total;
	const fm = ( m ) => formatMinor( m, state.currency );

	return (
		<div className="neural-forms-entry-detail__quote-editor">
			<table className="neural-forms-entry-detail__quote-lines">
				<thead><tr><th>{ __( 'Description', 'neural-forms-lite' ) }</th><th className="is-num">{ __( 'Qty', 'neural-forms-lite' ) }</th><th className="is-num">{ __( 'Unit price', 'neural-forms-lite' ) }</th><th className="is-num">{ __( 'Amount', 'neural-forms-lite' ) }</th><th /></tr></thead>
				<tbody>
					{ f.line_items.map( ( l, i ) => (
						<tr key={ i }>
							<td>
								<input type="text" value={ l.label } placeholder={ __( 'Item', 'neural-forms-lite' ) } onChange={ ( e ) => setLine( i, { label: e.target.value } ) } />
								<input type="text" className="is-desc" value={ l.description } placeholder={ __( 'Description (optional)', 'neural-forms-lite' ) } onChange={ ( e ) => setLine( i, { description: e.target.value } ) } />
							</td>
							<td className="is-num"><input type="number" min="1" step="1" value={ l.qty } onChange={ ( e ) => setLine( i, { qty: e.target.value } ) } /></td>
							<td className="is-num"><input type="text" inputMode="decimal" value={ l.unit } onChange={ ( e ) => setLine( i, { unit: e.target.value } ) } /></td>
							<td className="is-num">{ fm( toMinor( l.unit ) * ( parseInt( l.qty, 10 ) || 1 ) ) }</td>
							<td><button type="button" className="neural-forms-entry-detail__quote-x" aria-label={ __( 'Remove line', 'neural-forms-lite' ) } onClick={ () => removeLine( i ) }>×</button></td>
						</tr>
					) ) }
				</tbody>
			</table>
			<button type="button" className="neural-forms-entry-detail__quote-btn" onClick={ addLine }>{ __( '+ Add line', 'neural-forms-lite' ) }</button>
			<div className="neural-forms-entry-detail__quote-grid">
				<label className="neural-forms-entry-detail__quote-field"><span>{ __( 'Discount', 'neural-forms-lite' ) }</span>
					<span className="neural-forms-entry-detail__quote-inline">
						<select value={ f.discount.type } onChange={ ( e ) => set( { discount: { ...f.discount, type: e.target.value } } ) }>
							<option value="none">{ __( 'None', 'neural-forms-lite' ) }</option><option value="percent">{ __( 'Percent', 'neural-forms-lite' ) }</option><option value="fixed">{ __( 'Fixed amount', 'neural-forms-lite' ) }</option>
						</select>
						{ f.discount.type !== 'none' && <input type="text" inputMode="decimal" value={ f.discount.value } onChange={ ( e ) => set( { discount: { ...f.discount, value: e.target.value } } ) } /> }
						{ f.discount.type !== 'none' && <input type="text" placeholder={ __( 'Label', 'neural-forms-lite' ) } value={ f.discount.label } onChange={ ( e ) => set( { discount: { ...f.discount, label: e.target.value } } ) } /> }
					</span>
				</label>
				<label className="neural-forms-entry-detail__quote-field"><span>{ __( 'Deposit (due now)', 'neural-forms-lite' ) }</span>
					<span className="neural-forms-entry-detail__quote-inline">
						<select value={ f.deposit.type } onChange={ ( e ) => set( { deposit: { ...f.deposit, type: e.target.value } } ) }>
							<option value="none">{ __( 'Full amount', 'neural-forms-lite' ) }</option><option value="percent">{ __( 'Percent', 'neural-forms-lite' ) }</option><option value="fixed">{ __( 'Fixed amount', 'neural-forms-lite' ) }</option>
						</select>
						{ f.deposit.type !== 'none' && <input type="text" inputMode="decimal" value={ f.deposit.value } onChange={ ( e ) => set( { deposit: { ...f.deposit, value: e.target.value } } ) } /> }
					</span>
				</label>
				<label className="neural-forms-entry-detail__quote-field"><span>{ __( 'Valid for (days)', 'neural-forms-lite' ) }</span><input type="number" min="1" max="365" value={ f.validity_days } onChange={ ( e ) => set( { validity_days: e.target.value } ) } /></label>
				<label className="neural-forms-entry-detail__quote-field"><span>{ __( 'Customer name', 'neural-forms-lite' ) }</span><input type="text" value={ f.customer.name } onChange={ ( e ) => set( { customer: { ...f.customer, name: e.target.value } } ) } /></label>
				<label className="neural-forms-entry-detail__quote-field"><span>{ __( 'Customer email', 'neural-forms-lite' ) }</span><input type="email" value={ f.customer.email } onChange={ ( e ) => set( { customer: { ...f.customer, email: e.target.value } } ) } /></label>
			</div>
			<label className="neural-forms-entry-detail__quote-field"><span>{ __( 'Introduction', 'neural-forms-lite' ) }</span><textarea rows={ 2 } value={ f.intro } onChange={ ( e ) => set( { intro: e.target.value } ) } /></label>
			<label className="neural-forms-entry-detail__quote-field"><span>{ __( 'Notes', 'neural-forms-lite' ) }</span><textarea rows={ 2 } value={ f.notes } onChange={ ( e ) => set( { notes: e.target.value } ) } /></label>
			<label className="neural-forms-entry-detail__quote-field"><span>{ __( 'Terms', 'neural-forms-lite' ) }</span><textarea rows={ 2 } value={ f.terms } onChange={ ( e ) => set( { terms: e.target.value } ) } /></label>
			<dl className="neural-forms-entry-detail__pricing-totals">
				<dt>{ __( 'Subtotal', 'neural-forms-lite' ) }</dt><dd>{ fm( subtotal ) }</dd>
				{ discount > 0 && <><dt>{ __( 'Discount', 'neural-forms-lite' ) }</dt><dd>−{ fm( discount ) }</dd></> }
				<dt className="is-total">{ __( 'Total', 'neural-forms-lite' ) }</dt><dd className="is-total">{ fm( total ) }</dd>
				{ due !== total && <><dt>{ __( 'Due now', 'neural-forms-lite' ) }</dt><dd>{ fm( due ) }</dd></> }
			</dl>
			<p className="neural-forms-entry-detail__quote-hint">{ __( 'Amounts are recalculated on the server when you save.', 'neural-forms-lite' ) }</p>
			<div className="neural-forms-entry-detail__quote-actions">
				<button type="button" className="neural-forms-entry-detail__quote-btn is-primary" disabled={ busy } onClick={ onSave }>{ busy ? __( 'Saving…', 'neural-forms-lite' ) : __( 'Save draft', 'neural-forms-lite' ) }</button>
				<button type="button" className="neural-forms-entry-detail__quote-btn" onClick={ onCancel }>{ __( 'Cancel', 'neural-forms-lite' ) }</button>
			</div>
		</div>
	);
}
