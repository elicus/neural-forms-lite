/**
 * Form settings › Pricing › Coupon codes (1.3.0, Pro via `calculations`).
 * Stored as settings.pricing.coupons[]; normalised by Pricing\Coupons.
 * Uses come from GET /forms/{id}/coupons/usage (entries that used the
 * code, spam and trash not counted).
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../api';

const MAX = 50;
const cleanCode = ( s ) => String( s || '' ).toUpperCase().replace( /[^A-Z0-9_-]/g, '' ).slice( 0, 40 );

export default function CouponsEditor( { coupons, onChange, formId } ) {
	const list = Array.isArray( coupons ) ? coupons : [];
	const [ uses, setUses ] = useState( {} );

	useEffect( () => {
		if ( ! formId || ! list.length ) return;
		let live = true;
		api( `/forms/${ formId }/coupons/usage` )
			.then( ( r ) => { if ( live ) setUses( r || {} ); } )
			.catch( () => {} );
		return () => { live = false; };
		// Only on open: counts come from saved entries, not from edits here.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ formId ] );

	const update = ( i, patch ) => onChange( list.map( ( c, k ) => ( k === i ? { ...c, ...patch } : c ) ) );
	const remove = ( i ) => onChange( list.filter( ( _, k ) => k !== i ) );
	const add = () => {
		if ( list.length >= MAX ) return;
		onChange( [ ...list, { id: 'coupon_' + Math.random().toString( 36 ).slice( 2, 8 ), code: '', label: '', type: 'percent', value: '10', max: '', min: '', expires: '', limit: 0, enabled: true } ] );
	};
	const codes = list.map( ( c ) => cleanCode( c.code ) );

	return (
		<>
			<h4 className="nf-form-settings__subtitle">{ __( 'Coupon codes', 'neural-forms-lite' ) }</h4>
			<p className="nf-form-settings__rules-note">
				{ __( 'Visitors type a code in a "Coupon code" box under the total. A coupon comes on top of the automatic discount above, on what is left. Codes are checked on the server and never appear in the page.', 'neural-forms-lite' ) }
			</p>
			<div className="nf-form-settings__rules">
				{ list.map( ( c, i ) => {
					const code = cleanCode( c.code );
					const dup = code !== '' && codes.indexOf( code ) !== i;
					const short = code.length > 0 && code.length < 3;
					const used = uses[ c.id ];
					return (
						<div className="nf-form-settings__rule" key={ c.id || i }>
							<div className="nf-form-settings__rule-head">
								<strong>{ code || sprintf( __( 'Coupon %d', 'neural-forms-lite' ), i + 1 ) }</strong>
								{ typeof used === 'number' && (
									<span className="nf-form-settings__hint">
										{ c.limit > 0
											? sprintf( __( 'Used %1$d of %2$d', 'neural-forms-lite' ), used, c.limit )
											: sprintf( __( 'Used %d times', 'neural-forms-lite' ), used ) }
									</span>
								) }
								<button type="button" onClick={ () => remove( i ) } aria-label={ __( 'Remove coupon', 'neural-forms-lite' ) }>{ __( 'Remove', 'neural-forms-lite' ) }</button>
							</div>
							<div className="nf-inspector__row">
								<label className="nf-inspector__field">
									<span className="nf-inspector__label">{ __( 'Code', 'neural-forms-lite' ) }</span>
									<input type="text" value={ c.code || '' } maxLength={ 40 } placeholder="SPRING10" onChange={ ( e ) => update( i, { code: cleanCode( e.target.value ) } ) } />
								</label>
								<label className="nf-inspector__field">
									<span className="nf-inspector__label">{ __( 'Label (optional)', 'neural-forms-lite' ) }</span>
									<input type="text" value={ c.label || '' } maxLength={ 120 } placeholder={ __( 'e.g. Spring offer', 'neural-forms-lite' ) } onChange={ ( e ) => update( i, { label: e.target.value } ) } />
								</label>
							</div>
							{ dup && <p className="nf-form-settings__error">{ __( 'Another coupon already uses this code; only the first one will work.', 'neural-forms-lite' ) }</p> }
							{ short && <p className="nf-form-settings__error">{ __( 'Codes need at least 3 characters.', 'neural-forms-lite' ) }</p> }
							<div className="nf-inspector__row">
								<label className="nf-inspector__field">
									<span className="nf-inspector__label">{ __( 'Type', 'neural-forms-lite' ) }</span>
									<select value={ c.type === 'fixed' ? 'fixed' : 'percent' } onChange={ ( e ) => update( i, { type: e.target.value } ) }>
										<option value="percent">{ __( 'Percent off', 'neural-forms-lite' ) }</option>
										<option value="fixed">{ __( 'Fixed amount off', 'neural-forms-lite' ) }</option>
									</select>
								</label>
								<label className="nf-inspector__field">
									<span className="nf-inspector__label">{ c.type === 'fixed' ? __( 'Amount', 'neural-forms-lite' ) : __( 'Percent', 'neural-forms-lite' ) }</span>
									<input type="text" inputMode="decimal" value={ c.value ?? '' } onChange={ ( e ) => update( i, { value: e.target.value } ) } />
								</label>
								<label className="nf-inspector__field">
									<span className="nf-inspector__label">{ __( 'Max amount (optional)', 'neural-forms-lite' ) }</span>
									<input type="text" inputMode="decimal" value={ c.max ?? '' } onChange={ ( e ) => update( i, { max: e.target.value } ) } />
								</label>
							</div>
							<div className="nf-inspector__row">
								<label className="nf-inspector__field">
									<span className="nf-inspector__label">{ __( 'Minimum order (optional)', 'neural-forms-lite' ) }</span>
									<input type="text" inputMode="decimal" value={ c.min ?? '' } onChange={ ( e ) => update( i, { min: e.target.value } ) } />
								</label>
								<label className="nf-inspector__field">
									<span className="nf-inspector__label">{ __( 'Valid until (optional)', 'neural-forms-lite' ) }</span>
									<input type="date" value={ c.expires || '' } onChange={ ( e ) => update( i, { expires: e.target.value } ) } />
								</label>
								<label className="nf-inspector__field">
									<span className="nf-inspector__label">{ __( 'Max uses (0 = no limit)', 'neural-forms-lite' ) }</span>
									<input type="number" min={ 0 } step={ 1 } value={ c.limit ?? 0 } onChange={ ( e ) => update( i, { limit: Math.max( 0, parseInt( e.target.value, 10 ) || 0 ) } ) } />
								</label>
							</div>
							<label className="nf-form-settings__field nf-payments-section__toggle">
								<input type="checkbox" checked={ c.enabled !== false } onChange={ ( e ) => update( i, { enabled: e.target.checked } ) } />
								<span><strong>{ __( 'Active', 'neural-forms-lite' ) }</strong><small>{ __( 'Switch off to stop the code working without deleting it.', 'neural-forms-lite' ) }</small></span>
							</label>
						</div>
					);
				} ) }
			</div>
			<button type="button" className="nf-form-settings__rules-add" onClick={ add } disabled={ list.length >= MAX }>
				{ __( '+ Add coupon code', 'neural-forms-lite' ) }
			</button>
		</>
	);
}
