/**
 * Reply composer.
 *
 * Wraps the entry detail's bottom panel — to/subject/body inputs, a
 * Send button, and AI helper buttons (Polish, Regenerate). Polish
 * works on the user's text without re-sending the entry to the model;
 * Regenerate hits the cloud against the entry and replaces the body.
 *
 * Pro/cloud capability gates:
 *   ai_reanalyze     — Regenerate
 *   ai_refine_reply  — Polish
 *
 * SlotFill mount:
 *   NeuralForms.EntryReplyActions — Pro injects extra buttons (e.g.
 *     a "Send via custom transport" override) inside the action row.
 *
 * Props:
 *   entry         full entry row (used to seed To address from preview)
 *   capabilities  capability snapshot
 *   onSend        ({to, subject, body}) => Promise<void>
 *   onPolish      (text) => Promise<{polished_reply, usage}>
 *   onRegenerate  () => Promise<{reply_draft, usage}>
 *   busy          'send' | 'polish' | 'regenerate' | null
 *   error         string — inline error
 */

import { useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { pickSubmitterEmail } from './helpers';

function getReplySlot() {
	const reg = window.neuralForms?.slots;
	if ( reg && typeof reg.getSlot === 'function' ) {
		return reg.getSlot( 'NeuralForms.EntryReplyActions' );
	}
	return null;
}

export default function ReplyComposer( {
	entry,
	capabilities,
	onSend,
	onPolish,
	onRegenerate,
	busy,
	error,
} ) {
	const initialTo = useMemo(
		() => pickSubmitterEmail( null, entry?.data ) || pickSubmitterEmail( entry?.preview, null ) || '',
		[ entry ]
	);

	const [ to,      setTo      ] = useState( initialTo );
	const [ subject, setSubject ] = useState( '' );
	const [ body,    setBody    ] = useState( entry?.ai_reply_draft || '' );

	const Slot = useMemo( getReplySlot, [] );

	// Re-seed the body whenever the entry's draft changes (e.g. after
	// regenerate or carousel finalize) UNLESS the user has typed over
	// the seed.
	const [ lastSeed, setLastSeed ] = useState( entry?.ai_reply_draft || '' );
	useEffect( () => {
		const incoming = entry?.ai_reply_draft || '';
		if ( incoming !== lastSeed ) {
			// Seed changed under us — fold it in unless body has been hand-edited away.
			if ( body === lastSeed || body === '' ) {
				setBody( incoming );
			}
			setLastSeed( incoming );
		}
	}, [ entry?.ai_reply_draft ] );

	const canPolish     = !! capabilities?.ai_refine_reply;
	const canRegenerate = !! capabilities?.ai_reanalyze;

	const send = ( ev ) => {
		ev.preventDefault();
		if ( ! to.trim() || ! body.trim() ) return;
		onSend( { to: to.trim(), subject: subject.trim(), body: body.trim() } );
	};

	return (
		<form className="nf-inbox__reply" onSubmit={ send }>
			<h4 className="nf-inbox__reply-title">{ __( 'Reply', 'neural-forms-lite' ) }</h4>

			<label className="nf-inbox__reply-field">
				<span>{ __( 'To', 'neural-forms-lite' ) }</span>
				<input
					type="email"
					value={ to }
					onChange={ ( e ) => setTo( e.target.value ) }
					placeholder={ __( 'name@example.com', 'neural-forms-lite' ) }
					required
				/>
			</label>

			<label className="nf-inbox__reply-field">
				<span>{ __( 'Subject', 'neural-forms-lite' ) }</span>
				<input
					type="text"
					value={ subject }
					onChange={ ( e ) => setSubject( e.target.value ) }
					placeholder={ __( 'Re: Your submission', 'neural-forms-lite' ) }
				/>
			</label>

			<label className="nf-inbox__reply-field nf-inbox__reply-field--body">
				<span>{ __( 'Body', 'neural-forms-lite' ) }</span>
				<textarea
					value={ body }
					onChange={ ( e ) => setBody( e.target.value ) }
					rows={ 8 }
					required
				/>
			</label>

			{ !! error && <p className="nf-inbox__inline-error">{ error }</p> }

			<div className="nf-inbox__reply-actions">
				<button
					type="submit"
					className="neural-forms-btn neural-forms-btn--primary"
					disabled={ busy === 'send' || ! to.trim() || ! body.trim() }
				>
					{ busy === 'send' ? __( 'Sending...', 'neural-forms-lite' ) : __( 'Send', 'neural-forms-lite' ) }
				</button>

				{ canPolish ? (
					<button
						type="button"
						className="neural-forms-btn"
						disabled={ busy != null || ! body.trim() }
						onClick={ () => onPolish( body ).then( ( res ) => {
							if ( res?.polished_reply ) setBody( res.polished_reply );
						} ).catch( () => { /* parent surfaces error */ } ) }
					>
						{ busy === 'polish' ? __( 'Polishing...', 'neural-forms-lite' ) : __( 'Polish', 'neural-forms-lite' ) }
					</button>
				) : (
					<a
						className="nf-inbox__upsell-link"
						href="https://neuralforms.ai/pro/"
						target="_blank"
						rel="noreferrer"
					>
						{ __( 'Polish (Pro)', 'neural-forms-lite' ) }
					</a>
				) }

				{ canRegenerate ? (
					<button
						type="button"
						className="neural-forms-btn"
						disabled={ busy != null }
						onClick={ () => onRegenerate().then( ( res ) => {
							if ( res?.reply_draft ) setBody( res.reply_draft );
						} ).catch( () => { /* parent surfaces error */ } ) }
					>
						{ busy === 'regenerate' ? __( 'Regenerating...', 'neural-forms-lite' ) : __( 'Regenerate', 'neural-forms-lite' ) }
					</button>
				) : (
					<a
						className="nf-inbox__upsell-link"
						href="https://neuralforms.ai/pro/"
						target="_blank"
						rel="noreferrer"
					>
						{ __( 'Regenerate (Pro)', 'neural-forms-lite' ) }
					</a>
				) }

				{ Slot ? <Slot.Slot fillProps={ { entry } } /> : null }
			</div>
		</form>
	);
}
