/**
 * Reply composer.
 *
 * Wraps the entry detail's bottom panel — to/subject/body inputs, a
 * Send button, and a slot for Pro-rendered AI helper buttons (Refine
 * with AI / Polish). Send is Lite-owned (Lite owns wp_mail). Polish
 * UI is Pro-rendered via the `EntryReplyActions` slot. Regenerate
 * lives in the AI analysis card slot above, not here.
 *
 * Phase 5 separation: Lite never renders the Polish or Regenerate
 * button itself. Lite mounts the slot with fillProps `{body,
 * isPolishing, sending, handlePolish}` matching the contract Pro's
 * `pro:refine-with-ai` fill expects. When `caps.ai_refine_reply` is
 * false, Lite shows a single upsell link instead.
 *
 * Props:
 *   entry         full entry row (used to seed To address from preview)
 *   capabilities  capability snapshot
 *   onSend        ({to, subject, body}) => Promise<void>
 *   onPolish      (text) => Promise<{polished_reply, usage}>
 *   busy          'send' | 'polish' | 'regenerate' | null
 *   error         string — inline error
 */

import { useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { ProSlot } from '../slot-registry';
import { pickSubmitterEmail } from './helpers';

export default function ReplyComposer( {
	entry,
	capabilities,
	onSend,
	onPolish,
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

	// Re-seed the body whenever the entry's draft changes (e.g. after
	// regenerate or carousel finalize) UNLESS the user has typed over
	// the seed.
	const [ lastSeed, setLastSeed ] = useState( entry?.ai_reply_draft || '' );
	useEffect( () => {
		const incoming = entry?.ai_reply_draft || '';
		if ( incoming !== lastSeed ) {
			if ( body === lastSeed || body === '' ) {
				setBody( incoming );
			}
			setLastSeed( incoming );
		}
	}, [ entry?.ai_reply_draft ] );

	const canPolish = !! capabilities?.ai_refine_reply;

	const send = ( ev ) => {
		ev.preventDefault();
		if ( ! to.trim() || ! body.trim() ) return;
		onSend( { to: to.trim(), subject: subject.trim(), body: body.trim() } );
	};

	// Pro's `EntryReplyActions` fill calls `handlePolish()` (no args).
	// Lite resolves the polish by calling `onPolish(body)` and updating
	// the textarea with the polished result. Pro never touches the body
	// state directly — it just triggers the action.
	const handlePolish = () => {
		if ( ! body.trim() ) return Promise.resolve();
		return onPolish( body ).then( ( res ) => {
			if ( res?.polished_reply ) setBody( res.polished_reply );
			return res;
		} ).catch( () => { /* surfaced via the parent's error prop */ } );
	};

	return (
		<form className="neural-forms-entry-detail__reply-card" onSubmit={ send }>
			<h4 className="neural-forms-entry-detail__reply-header">{ __( 'Reply', 'neural-forms-lite' ) }</h4>

			<label className="neural-forms-entry-detail__reply-to">
				<span className="neural-forms-entry-detail__reply-to-label">{ __( 'To', 'neural-forms-lite' ) }</span>
				<input
					type="email"
					className="neural-forms-entry-detail__reply-to-address"
					value={ to }
					onChange={ ( e ) => setTo( e.target.value ) }
					placeholder={ __( 'name@example.com', 'neural-forms-lite' ) }
					required
				/>
			</label>

			<label className="neural-forms-entry-detail__reply-to">
				<span className="neural-forms-entry-detail__reply-to-label">{ __( 'Subject', 'neural-forms-lite' ) }</span>
				<input
					type="text"
					className="neural-forms-entry-detail__reply-to-address"
					value={ subject }
					onChange={ ( e ) => setSubject( e.target.value ) }
					placeholder={ __( 'Re: Your submission', 'neural-forms-lite' ) }
				/>
			</label>

			<textarea
				className="neural-forms-entry-detail__reply-textarea"
				value={ body }
				onChange={ ( e ) => setBody( e.target.value ) }
				rows={ 8 }
				required
			/>

			{ !! error && <p className="neural-forms-entries-error">{ error }</p> }

			<div className="neural-forms-entry-detail__reply-footer">
				<button
					type="submit"
					className="neural-forms-entries-btn neural-forms-entries-btn--primary neural-forms-entry-detail__reply-send"
					disabled={ busy === 'send' || ! to.trim() || ! body.trim() }
				>
					{ busy === 'send' ? __( 'Sending...', 'neural-forms-lite' ) : __( 'Send', 'neural-forms-lite' ) }
				</button>

				{ canPolish ? (
					<ProSlot
						name="EntryReplyActions"
						fillProps={ {
							body,
							isPolishing: busy === 'polish',
							sending:     busy === 'send',
							handlePolish,
						} }
					/>
				) : (
					<a
						className="neural-forms-entries-upsell-link"
						href="https://neuralforms.ai/pro/"
						target="_blank"
						rel="noreferrer"
					>
						{ __( 'Refine with AI (Pro)', 'neural-forms-lite' ) }
					</a>
				) }
			</div>
		</form>
	);
}
