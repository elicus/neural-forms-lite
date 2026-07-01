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

import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { ProSlot } from '../slot-registry';
import { pickSubmitterEmail, liveCreditsExhausted } from './helpers';
import { UsageLine } from './EntryDetail';

const PRO_UPGRADE_URL = 'https://neuralforms.ai/pricing';

function LockIcon() {
	return (
		<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
			strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
			<rect x="4" y="11" width="16" height="10" rx="2" />
			<path d="M8 11V8a4 4 0 0 1 8 0v3" />
		</svg>
	);
}

function CheckIcon() {
	return (
		<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
			strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
			<circle cx="12" cy="12" r="9" />
			<path d="M9 12l2 2 4-4" />
		</svg>
	);
}

export default function ReplyComposer( {
	entry,
	capabilities,
	onSend,
	onPolish,
	busy,
	error,
	resetSignal,
	replyFreshKey,
	usage,
	onDismissUsage,
} ) {
	const initialTo = useMemo(
		() => pickSubmitterEmail( null, entry?.data ) || pickSubmitterEmail( entry?.preview, null ) || '',
		[ entry ]
	);

	// Recipient is displayed read-only — it's resolved from the entry's
	// submitted email field (form data → preview), and editing it would
	// confuse the conversation thread which is keyed by the original
	// submitter. Subject is omitted entirely; the backend defaults to
	// "Re: Your submission" when none is provided.
	const to = initialTo;
	const [ body,    setBody    ] = useState( entry?.ai_reply_draft || '' );
	const [ lastSentTo, setLastSentTo ] = useState( '' );

	// Re-seed the body whenever the entry's draft changes (e.g. after
	// regenerate) UNLESS the user has typed over the seed. Carousel
	// "Use this draft" picks bypass this protection — see resetSignal
	// below — because the user explicitly opted into the new draft.
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

	// Force-replace body when the user picks a draft from the carousel.
	// The parent bumps `resetSignal` after every successful /finalize so
	// "Use this draft" wins over local edits — without it, a user who
	// types into the textarea can never get the picked draft back.
	const firstResetRef = useRef( true );
	useEffect( () => {
		if ( firstResetRef.current ) {
			firstResetRef.current = false;
			return;
		}
		const incoming = entry?.ai_reply_draft || '';
		setBody( incoming );
		setLastSeed( incoming );
	}, [ resetSignal ] );

	// Drop the success block whenever the entry changes (user opened a
	// different entry) so the new entry doesn't show a stale confirmation.
	useEffect( () => { setLastSentTo( '' ); }, [ entry?.id ] );

	// 2.5s mint pulse on the textarea after Refine / Regenerate completes.
	// Keyed off `replyFreshKey` (parent bumps it on success). Skips the
	// initial render so opening an entry is calm.
	const [ isReplyFresh, setIsReplyFresh ] = useState( false );
	const firstFreshRef = useRef( true );
	useEffect( () => {
		if ( firstFreshRef.current ) { firstFreshRef.current = false; return; }
		setIsReplyFresh( true );
		const t = setTimeout( () => setIsReplyFresh( false ), 2500 );
		return () => clearTimeout( t );
	}, [ replyFreshKey ] );

	const canPolish = !! capabilities?.ai_refine_reply;
	const cloudApiKeySet = !! (
		typeof window !== 'undefined'
		&& window.neuralFormsAdmin
		&& window.neuralFormsAdmin.plugin
		&& window.neuralFormsAdmin.plugin.cloudApiKeySet
	);
	const proInstalled = !! (
		typeof window !== 'undefined'
		&& window.neuralFormsAdmin
		&& window.neuralFormsAdmin.pro
		&& window.neuralFormsAdmin.pro.version
	);
	const settingsUrl = ( typeof window !== 'undefined' && window.neuralFormsAdmin?.settingsUrl )
		? window.neuralFormsAdmin.settingsUrl
		: 'admin.php?page=neural-forms-settings';
	const pricingUrl = 'https://neuralforms.ai/pricing';

	const [ showUpsell, setShowUpsell ] = useState( false );

	const send = ( ev ) => {
		ev.preventDefault();
		const recipient = ( to || '' ).trim();
		if ( ! recipient || ! body.trim() ) return;
		Promise.resolve( onSend( { to: recipient, body: body.trim() } ) )
			.then( ( res ) => {
				if ( ! res ) return;
				setLastSentTo( ( res && res.to ) || recipient );
				setBody( '' );
			} )
			.catch( () => { /* error surfaced via parent's `error` prop */ } );
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
			<header className="neural-forms-entry-detail__reply-card-header">
				<h4 className="neural-forms-entry-detail__reply-header">{ __( 'Reply', 'neural-forms-lite' ) }</h4>
				{ !! lastSentTo && (
					<button
						type="button"
						className="neural-forms-entry-detail__reply-sent-close"
						onClick={ () => setLastSentTo( '' ) }
						aria-label={ __( 'Dismiss', 'neural-forms-lite' ) }
					>
						<span aria-hidden="true">×</span>
					</button>
				) }
			</header>
			{ !! lastSentTo && (
				<p className="neural-forms-entry-detail__reply-sent" role="status">
					<span className="neural-forms-entry-detail__reply-sent-icon" aria-hidden="true">
						<CheckIcon />
					</span>
					<span>
						{ __( 'Sent to', 'neural-forms-lite' ) }{ ' ' }
						<strong>{ lastSentTo }</strong>
					</span>
				</p>
			) }

			<div className="neural-forms-entry-detail__reply-to neural-forms-entry-detail__reply-to--readonly">
				<span className="neural-forms-entry-detail__reply-to-label">{ __( 'To', 'neural-forms-lite' ) }</span>
				<span className="neural-forms-entry-detail__reply-to-value">
					{ to || __( 'No email address on submission', 'neural-forms-lite' ) }
				</span>
			</div>

			<textarea
				className={
					'neural-forms-entry-detail__reply-textarea'
					+ ( busy === 'polish' || busy === 'regenerate' ? ' is-loading' : '' )
					+ ( isReplyFresh && busy !== 'polish' && busy !== 'regenerate' ? ' is-just-generated' : '' )
				}
				value={ body }
				onChange={ ( e ) => setBody( e.target.value ) }
				rows={ 8 }
				required
				data-nf-reply-textarea="1"
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

				{ ( () => {
					// Credits exhausted trumps everything else — same
					// message in Pro and Lite installs.
					const exhausted = liveCreditsExhausted();
					if ( exhausted ) {
						return (
							<a
								className="neural-forms-entry-detail__polish-trigger neural-forms-entry-detail__polish-trigger--exhausted"
								href={ exhausted.upgradeUrl || pricingUrl }
								target="_blank"
								rel="noreferrer"
								title={ __( 'Neural AI credits exhausted — click to buy more', 'neural-forms-lite' ) }
							>
								{ __( 'Buy more credits', 'neural-forms-lite' ) }
							</a>
						);
					}

					// Pro installed but no cloud API key on file — send
					// the user straight to the Settings page to paste it.
					if ( canPolish && ! cloudApiKeySet ) {
						return (
							<a
								className="neural-forms-entry-detail__polish-trigger"
								href={ settingsUrl }
								title={ __( 'Enter your Neural Forms Cloud API key to refine replies', 'neural-forms-lite' ) }
							>
								{ __( 'Enter API key to refine reply', 'neural-forms-lite' ) }
							</a>
						);
					}

					// Pro installed + key on file → the Pro bundle owns
					// the Refine action.
					if ( canPolish ) {
						return (
							<ProSlot
								name="EntryReplyActions"
								fillProps={ {
									body,
									isPolishing: busy === 'polish',
									sending:     busy === 'send',
									handlePolish,
								} }
							/>
						);
					}

					// Lite path (Refine is Pro-only regardless of key
					// state, so a locked Pro-upsell button is correct).
					return (
						<button
							type="button"
							className="neural-forms-entry-detail__polish-trigger"
							onClick={ () => setShowUpsell( true ) }
							aria-label={ __( 'Refine with Neural AI (Pro feature)', 'neural-forms-lite' ) }
						>
							<span className="neural-forms-entry-detail__polish-trigger-lock" aria-hidden="true">
								<LockIcon />
							</span>
							{ __( 'Refine with Neural AI', 'neural-forms-lite' ) }
						</button>
					);
				} )() }
			</div>

			{ /* Usage strip routed to the composer when the source action
			     was a Refine or Regenerate — sits right under the textarea
			     so the credit cost is next to the content it paid for. */ }
			{ usage && (
				<UsageLine usage={ usage } onDismiss={ onDismissUsage } />
			) }

			{ ! canPolish && showUpsell && (
				<aside className="neural-forms-entries-pro-notice" role="status">
					<span className="neural-forms-entries-pro-notice__icon" aria-hidden="true">
						<LockIcon />
					</span>
					<div className="neural-forms-entries-pro-notice__body">
						<p className="neural-forms-entries-pro-notice__title">
							{ __( 'Upgrade to Pro to refine with Neural AI', 'neural-forms-lite' ) }
						</p>
						<p className="neural-forms-entries-pro-notice__desc">
							{ __( 'Polish your reply tone and clarity with one click — Pro feature.', 'neural-forms-lite' ) }
						</p>
					</div>
					<a
						className="neural-forms-entries-pro-notice__cta"
						href={ PRO_UPGRADE_URL }
						target="_blank"
						rel="noreferrer"
					>
						{ __( 'View pricing', 'neural-forms-lite' ) }
					</a>
					<button
						type="button"
						className="neural-forms-entries-pro-notice__close"
						onClick={ () => setShowUpsell( false ) }
						aria-label={ __( 'Dismiss', 'neural-forms-lite' ) }
					>
						<span aria-hidden="true">×</span>
					</button>
				</aside>
			) }
		</form>
	);
}
