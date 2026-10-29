/**
 * Quotes page (1.2.0 part B) — ?page=neural-forms-quotes.
 *
 * Business identity, numbering and text blocks printed on every quote
 * (option `neural_forms_quote_settings`, REST /quotes/settings). Lives
 * on its own submenu page so the general Settings screen stays short;
 * the quotes themselves are created and sent from the entry inbox.
 * Reuses the Settings screen's CSS (settings.css is imported by the
 * entry point) so both pages look identical. Locked → Pro lock card.
 */

import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../api';

function Section( { title, description, children } ) {
	return (
		<section className="neural-forms-settings__section">
			<header className="neural-forms-settings__section-header">
				<h2 className="neural-forms-settings__section-title">{ title }</h2>
				{ description && <p className="neural-forms-settings__section-desc">{ description }</p> }
			</header>
			<div className="neural-forms-settings__section-body">{ children }</div>
		</section>
	);
}
function Field( { label, hint, children } ) {
	return (
		<div className="neural-forms-settings__field">
			<label className="neural-forms-settings__label">{ label }</label>
			<div className="neural-forms-settings__control">
				{ children }
				{ hint && <p className="neural-forms-settings__hint">{ hint }</p> }
			</div>
		</div>
	);
}
function TextInput( props ) { return <input className="neural-forms-settings__input" { ...props } />; }
function Textarea( props ) { return <textarea className="neural-forms-settings__textarea" { ...props } />; }
function ProLockCard( { title, description, upgradeUrl } ) {
	return (
		<div className="neural-forms-settings__pro-card">
			<span className="neural-forms-settings__pro-icon" aria-hidden="true">
				<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2" /><path d="M7 11V7a5 5 0 0 1 10 0v4" /></svg>
			</span>
			<div className="neural-forms-settings__pro-body">
				<h3 className="neural-forms-settings__pro-title">{ title }</h3>
				<p className="neural-forms-settings__pro-desc">{ description }</p>
				<a className="neural-forms-settings__pro-cta" href={ upgradeUrl || 'https://neuralforms.ai/pricing' } target="_blank" rel="noopener noreferrer">{ __( 'Upgrade to unlock', 'neural-forms-lite' ) }</a>
			</div>
		</div>
	);
}
/**
 * Quotes (1.2.0 part B): business identity, numbering and text blocks
 * printed on every quote (option neural_forms_quote_settings, REST
 * /quotes/settings). Locked → ProLockCard.
 */
function QuoteSettingsForm( { unlocked, form, set, pickLogo, loadError } ) {
	if ( ! unlocked ) {
		return (
			<Section title={ __( 'Branding', 'neural-forms-lite' ) } description={ __( 'Branding, numbering and terms for the quotes you send from the inbox.', 'neural-forms-lite' ) }>
				<ProLockCard title={ __( 'Branded quotes with PDF', 'neural-forms-lite' ) } description={ __( 'Turn priced entries into numbered quotes with your logo and terms, send them as a PDF with a secure link, and keep every revision on record.', 'neural-forms-lite' ) } />
			</Section>
		);
	}
	return (
		<Section title={ __( 'Branding', 'neural-forms-lite' ) } description={ __( 'Business identity and text blocks printed on every quote and invoice.', 'neural-forms-lite' ) }>
			{ ! form && ! loadError && <p className="neural-forms-settings__hint" style={ { padding: '16px 24px' } }>{ __( 'Loading…', 'neural-forms-lite' ) }</p> }
			{ ! form && loadError && <p className="neural-forms-settings__hint is-error" style={ { padding: '16px 24px' } }>{ loadError }</p> }
			{ form && <>
				<Field label={ __( 'Business name', 'neural-forms-lite' ) }><TextInput value={ form.business_name } onChange={ set( 'business_name' ) } /></Field>
				<Field label={ __( 'Address', 'neural-forms-lite' ) }><Textarea rows={ 3 } value={ form.address } onChange={ set( 'address' ) } /></Field>
				<Field label={ __( 'Email', 'neural-forms-lite' ) }><TextInput type="email" value={ form.email } onChange={ set( 'email' ) } /></Field>
				<Field label={ __( 'Phone', 'neural-forms-lite' ) }><TextInput value={ form.phone } onChange={ set( 'phone' ) } /></Field>
				<Field label={ __( 'Website', 'neural-forms-lite' ) }><TextInput value={ form.website } onChange={ set( 'website' ) } /></Field>
				<Field label={ __( 'Tax ID', 'neural-forms-lite' ) } hint={ __( 'Optional. Shown under your address.', 'neural-forms-lite' ) }><TextInput value={ form.tax_id } onChange={ set( 'tax_id' ) } /></Field>
				<Field label={ __( 'Logo', 'neural-forms-lite' ) } hint={ __( 'Image URL from your media library. PNG or JPG, shown at up to 200 × 60 px.', 'neural-forms-lite' ) }>
					<div className="neural-forms-settings__inline">
						<TextInput value={ form.logo_url } onChange={ set( 'logo_url' ) } placeholder="https://" />
						{ window.wp && window.wp.media && <button type="button" className="neural-forms-settings__btn" onClick={ pickLogo }>{ __( 'Choose', 'neural-forms-lite' ) }</button> }
					</div>
					{ form.logo_url && <img className="neural-forms-settings__logo-preview" src={ form.logo_url } alt="" /> }
				</Field>
				<Field label={ __( 'Accent colour', 'neural-forms-lite' ) }>
					<div className="neural-forms-settings__inline"><input type="color" value={ form.accent_color || '#1e1b4b' } onChange={ set( 'accent_color' ) } /><TextInput value={ form.accent_color } onChange={ set( 'accent_color' ) } /></div>
				</Field>
				<Field label={ __( 'Quote number prefix', 'neural-forms-lite' ) } hint={ sprintf( __( 'Next numbers look like %s.', 'neural-forms-lite' ), `${ ( form.prefix || 'Q' ).toUpperCase() }-${ new Date().getFullYear() }-0001` ) }><TextInput value={ form.prefix } onChange={ set( 'prefix' ) } /></Field>
				<Field label={ __( 'Valid for (days)', 'neural-forms-lite' ) } hint={ __( 'Default validity of a new quote; can be changed per quote before issuing.', 'neural-forms-lite' ) }><TextInput type="number" min="1" max="365" value={ form.validity_days } onChange={ set( 'validity_days' ) } /></Field>
				<Field label={ __( 'Introduction', 'neural-forms-lite' ) } hint={ __( 'Shown above the items on new quotes.', 'neural-forms-lite' ) }><Textarea rows={ 2 } value={ form.intro } onChange={ set( 'intro' ) } /></Field>
				<Field label={ __( 'Terms', 'neural-forms-lite' ) }><Textarea rows={ 4 } value={ form.terms } onChange={ set( 'terms' ) } /></Field>
				<Field label={ __( 'Footer', 'neural-forms-lite' ) }><Textarea rows={ 2 } value={ form.footer } onChange={ set( 'footer' ) } /></Field>
				<Field label={ __( 'Invoice title', 'neural-forms-lite' ) } hint={ __( 'Heading printed on payment invoices, for example "Invoice" or "Receipt". Default: Invoice.', 'neural-forms-lite' ) }><TextInput value={ form.invoice_title || '' } placeholder="Invoice" onChange={ set( 'invoice_title' ) } /></Field>
				<Field label={ __( 'Invoice note', 'neural-forms-lite' ) } hint={ __( 'Printed on every invoice under the payment details, for example bank details for the balance or a thank-you line.', 'neural-forms-lite' ) }><Textarea rows={ 3 } value={ form.invoice_note || '' } onChange={ set( 'invoice_note' ) } /></Field>
				<Field label={ __( 'PDF', 'neural-forms-lite' ) }>
					<p className="neural-forms-settings__hint" style={ { marginTop: 0 } }>{ form.pdf_available ? __( 'PDF generation is active. Quotes are emailed with a PDF attachment and a secure link.', 'neural-forms-lite' ) : __( 'PDF generation is not available on this server (it needs the PHP DOM and mbstring extensions and at least 96 MB of memory). Quotes are sent as a secure printable link instead.', 'neural-forms-lite' ) }</p>
				</Field>
			</> }
		</Section>
	);
}

export default function Quotes() {
	const caps     = ( typeof window !== 'undefined' && window.neuralFormsAdmin?.plugin?.capabilities ) || {};
	const unlocked = !! caps.quotes;
	const [ form,      setForm      ] = useState( null );
	const [ initial,   setInitial   ] = useState( null );
	const [ saving,    setSaving    ] = useState( false );
	const [ saved,     setSaved     ] = useState( false );
	const [ error,     setError     ] = useState( '' );
	const [ loadError, setLoadError ] = useState( '' );

	useEffect( () => {
		if ( ! unlocked ) return;
		let cancelled = false;
		api( '/quotes/settings' )
			.then( ( res ) => { if ( ! cancelled ) { setForm( res ); setInitial( res ); } } )
			.catch( ( e ) => { if ( ! cancelled ) setLoadError( e?.message || __( 'Could not load quote settings.', 'neural-forms-lite' ) ); } );
		return () => { cancelled = true; };
	}, [ unlocked ] );

	const isDirty = !! ( form && initial && JSON.stringify( form ) !== JSON.stringify( initial ) );
	const set = ( key ) => ( e ) => { const v = e && e.target ? e.target.value : e; setForm( ( f ) => ( { ...f, [ key ]: v } ) ); setSaved( false ); setError( '' ); };
	const save = () => {
		if ( ! form || saving ) return;
		setSaving( true ); setError( '' );
		api( '/quotes/settings', { method: 'PUT', data: form } )
			.then( ( res ) => { setForm( res ); setInitial( res ); setSaved( true ); window.setTimeout( () => setSaved( false ), 4000 ); } )
			.catch( ( e ) => setError( e?.message || __( 'Could not save.', 'neural-forms-lite' ) ) )
			.finally( () => setSaving( false ) );
	};
	const pickLogo = () => {
		const media = window.wp && window.wp.media;
		if ( ! media ) return;
		const frame = media( { title: __( 'Choose a logo', 'neural-forms-lite' ), multiple: false, library: { type: 'image' } } );
		frame.on( 'select', () => { const att = frame.state().get( 'selection' ).first().toJSON(); set( 'logo_url' )( att.url || '' ); } );
		frame.open();
	};

	// Same three-state save bar as the Settings screen (top + bottom).
	const renderSaveBar = ( variant ) => {
		if ( ! unlocked || ! form ) return null;
		const cls = 'neural-forms-settings__save-bar' + ( variant === 'top' ? ' neural-forms-settings__save-bar--top' : '' );
		return (
			<div className={ cls }>
				{ saved && <span className="neural-forms-settings__save-msg neural-forms-settings__save-msg--ok">{ __( 'Saved! Your changes are live.', 'neural-forms-lite' ) }</span> }
				{ isDirty && ! saving && ! saved && <span className="neural-forms-settings__save-msg neural-forms-settings__save-msg--dirty">{ __( 'Unsaved changes', 'neural-forms-lite' ) }</span> }
				{ error && <span className="neural-forms-settings__save-msg neural-forms-settings__save-msg--error">{ error }</span> }
				{ ( isDirty || saving ) ? (
					<button type="button" className="neural-forms-settings__save-btn" onClick={ save } disabled={ saving }>
						{ saving ? __( 'Saving…', 'neural-forms-lite' ) : __( 'Save settings', 'neural-forms-lite' ) }
					</button>
				) : (
					<span className="neural-forms-settings__save-btn neural-forms-settings__save-btn--saved" aria-live="polite">{ __( 'Saved', 'neural-forms-lite' ) }</span>
				) }
			</div>
		);
	};

	return (
		<div className="neural-forms-settings neural-forms-quotes">
			<header className="neural-forms-settings__header">
				<div className="neural-forms-settings__header-text">
					<h1 className="neural-forms-settings__page-title">{ __( 'Quotes', 'neural-forms-lite' ) }</h1>
					<p className="neural-forms-settings__page-desc">{ __( 'Set up the business details, numbering and terms used on the quotes you create from the entries inbox. Numbers follow the pattern PREFIX-YEAR-0001 and are assigned when a quote is issued.', 'neural-forms-lite' ) }</p>
				</div>
				{ renderSaveBar( 'top' ) }
			</header>
			<QuoteSettingsForm unlocked={ unlocked } form={ form } set={ set } pickLogo={ pickLogo } loadError={ loadError } />
			{ renderSaveBar( 'bottom' ) }
		</div>
	);
}
