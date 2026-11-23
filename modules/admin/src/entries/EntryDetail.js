/**
 * Right-pane detail view for a single entry.
 *
 * Composes the AI analysis card, the response carousel, the raw
 * field grid, and the reply composer. The screen owner threads
 * REST handlers and capability state through props so this file
 * stays presentational.
 *
 * Layout (top → bottom):
 *   header     icon + form name + Entry #N · date · IP, source URL, status pill
 *   banner     "This entry likely needs a reply." (AI suggestion)
 *   data       raw submitted field key/value table
 *   analysis   AI card (Pro slot for Reanalyze + Regenerate buttons)
 *   carousel   AI drafts (only shown when there's at least one)
 *   reply      reply composer (Pro slot for Polish/Refine button)
 *
 * Props:
 *   entry             full entry from /entries/{id}
 *   loading           boolean — initial fetch in flight
 *   error             string — initial fetch error
 *   capabilities      capability snapshot from neuralFormsAdmin.plugin.capabilities
 *   formNameFallback  string — used if entry.form_name is missing (deep-link)
 *   fieldLabels       { field_id: label } — built from the parent form's schema
 *   onReanalyze       () => Promise<void>
 *   reanalyzing       bool
 *   reanalyzeError    string
 *   onPolish          (text) => Promise<{polished_reply, ...}>
 *   onRegenerate      () => Promise<{reply_draft, ...}>
 *   onSendReply       ({to, subject, body}) => Promise<void>
 *   onFinalize        (draftId) => Promise<void>
 *   composerBusy      'send' | 'polish' | 'regenerate' | null
 *   composerError     string
 *   carouselBusy      draftId | null
 *   onToggleStar      () => void
 *   onMarkNeedsReply  () => Promise<void>  — flips needs_reply = 1
 */

import { Fragment, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../api';
import AiAnalysisCard from './AiAnalysisCard';
import AiCarousel     from './AiCarousel';
import ReplyComposer  from './ReplyComposer';
import { formatDate, formatChoiceValue, formatMatrixValueLines, formatRepeaterValueLines, formatProductValue, formatMinor } from './helpers';
import LeadPanel, { ActivityTimeline } from './LeadPanel';
import QuoteCard from './QuoteCard';
import PaymentCard from './PaymentCard';

function StatusPill( { entry } ) {
	if ( entry.status === 'spam' )  return <span className="neural-forms-entries-pill neural-forms-entries-pill--spam">{ __( 'Spam', 'neural-forms-lite' ) }</span>;
	if ( entry.status === 'trash' ) return <span className="neural-forms-entries-pill neural-forms-entries-pill--trash">{ __( 'Trash', 'neural-forms-lite' ) }</span>;
	if ( entry.status === 'new'   ) return <span className="neural-forms-entries-pill neural-forms-entries-pill--new">{ __( 'Unread', 'neural-forms-lite' ) }</span>;
	return <span className="neural-forms-entries-pill neural-forms-entries-pill--read">{ __( 'Read', 'neural-forms-lite' ) }</span>;
}

function FormIcon() {
	return (
		<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor"
			strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
			<line x1="4"  y1="6"  x2="14" y2="6" />
			<line x1="4"  y1="12" x2="20" y2="12" />
			<line x1="4"  y1="18" x2="11" y2="18" />
		</svg>
	);
}

function ExternalLinkIcon() {
	return (
		<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
			strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
			<path d="M14 3h7v7" />
			<path d="M10 14L21 3" />
			<path d="M21 14v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5" />
		</svg>
	);
}

function NeedsReplyIcon() {
	return (
		<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
			strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
			<circle cx="12" cy="12" r="3" />
			<line x1="12" y1="3"  x2="12" y2="6" />
			<line x1="12" y1="18" x2="12" y2="21" />
			<line x1="3"  y1="12" x2="6"  y2="12" />
			<line x1="18" y1="12" x2="21" y2="12" />
			<line x1="5.6"  y1="5.6"  x2="7.7"  y2="7.7" />
			<line x1="16.3" y1="16.3" x2="18.4" y2="18.4" />
			<line x1="5.6"  y1="18.4" x2="7.7"  y2="16.3" />
			<line x1="16.3" y1="7.7"  x2="18.4" y2="5.6" />
		</svg>
	);
}

/**
 * Inline credit-meter strip rendered under each AI action area after a
 * successful call. Shape matches the cloud's `usage` block:
 *   { credits_used, credits_remaining, credits_deducted }
 *
 * Rendered with light-mint accent so it reads as a confirmation, not a
 * warning. Auto-clears when the user dismisses or opens a different entry.
 */
export function UsageLine( { usage, onDismiss } ) {
	if ( ! usage ) return null;
	if ( usage.credits_deducted === false ) {
		return (
			<p className="neural-forms-entry-detail__ai-usage" role="status">
				<span>{ __( 'No credits were deducted.', 'neural-forms-lite' ) }</span>
				{ onDismiss && (
					<button
						type="button"
						className="neural-forms-entry-detail__ai-usage-close"
						onClick={ onDismiss }
						aria-label={ __( 'Dismiss', 'neural-forms-lite' ) }
					>
						<span aria-hidden="true">×</span>
					</button>
				) }
			</p>
		);
	}
	const used = usage.credits_used != null ? Number( usage.credits_used ).toFixed( 2 ) : null;
	const left = usage.credits_remaining != null ? Number( usage.credits_remaining ).toFixed( 2 ) : null;
	return (
		<p className="neural-forms-entry-detail__ai-usage" role="status">
			<span>
				{ used !== null && (
					<>
						{ __( 'Credits used:', 'neural-forms-lite' ) } <strong>{ used }</strong>
					</>
				) }
				{ used !== null && left !== null && ' · ' }
				{ left !== null && (
					<>
						{ __( 'Remaining:', 'neural-forms-lite' ) } <strong>{ left }</strong>
					</>
				) }
			</span>
			{ onDismiss && (
				<button
					type="button"
					className="neural-forms-entry-detail__ai-usage-close"
					onClick={ onDismiss }
					aria-label={ __( 'Dismiss', 'neural-forms-lite' ) }
				>
					<span aria-hidden="true">×</span>
				</button>
			) }
		</p>
	);
}

function FlagIcon() {
	return (
		<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
			strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
			<path d="M4 21V4h12l-2 4 2 4H4" />
		</svg>
	);
}

function formatFileSize( bytes ) {
	const n = Number( bytes );
	if ( ! isFinite( n ) || n <= 0 ) return '';
	if ( n < 1024 )            return n + ' B';
	if ( n < 1024 * 1024 )     return ( n / 1024 ).toFixed( 1 ) + ' KB';
	if ( n < 1024 * 1024 * 1024 ) return ( n / ( 1024 * 1024 ) ).toFixed( 1 ) + ' MB';
	return ( n / ( 1024 * 1024 * 1024 ) ).toFixed( 1 ) + ' GB';
}

function getEntryFormName( entry, fallback ) {
	if ( entry?.form_name && String( entry.form_name ).trim() !== '' ) return String( entry.form_name );
	if ( fallback && String( fallback ).trim() !== '' ) return String( fallback );
	return '';
}

function getEntryIp( entry ) {
	return entry?.ip_address || entry?.ip || '';
}

function getEntrySourceUrl( entry ) {
	return entry?.page_url || entry?.source_url || entry?.referer || '';
}

const REPLY_CATEGORIES = new Set( [ 'sales', 'support', 'lead', 'inquiry', 'question' ] );

function shouldSuggestReply( entry ) {
	if ( ! entry ) return false;
	if ( entry.needs_reply === 1 || entry.needs_reply === true ) return false;
	if ( entry.status === 'spam' || entry.status === 'trash' ) return false;
	if ( entry.ai_is_spam === 1 || entry.ai_is_spam === true ) return false;
	const cat  = String( entry.ai_category || '' ).trim().toLowerCase();
	const prio = String( entry.ai_priority || '' ).trim().toLowerCase();
	if ( ! cat && ! prio ) return false;
	if ( REPLY_CATEGORIES.has( cat ) ) return true;
	if ( prio === 'high' || prio === 'medium' ) return true;
	if ( entry.ai_reply_draft && String( entry.ai_reply_draft ).trim() !== '' ) return true;
	return false;
}

/**
 * Conversation thread — paints the entry's submission as the leading
 * "incoming" bubble and every saved EntryMessage as a follow-up bubble
 * (incoming = received, outgoing = admin reply). Refetches whenever
 * `refreshSignal` bumps so a freshly-sent reply lands without a page
 * reload.
 */
/**
 * Render a single (field_id, value) pair into the readable text used
 * in the conversation's submission bubble. Repeater + Matrix get
 * structured multi-line output so admins never see raw JSON in the
 * conversation; everything else falls through to scalar / joined-
 * array text.
 *
 * Pure — no React, no side effects. Module-local so the Conversation
 * component and its tests can share it.
 */
function formatEntryFieldForConversation( fieldId, value, fieldLabels, fieldSchemas ) {
	const label = ( fieldLabels && fieldLabels[ fieldId ] ) || fieldId;
	const field = ( fieldSchemas && fieldSchemas[ fieldId ] ) || null;

	if ( field && field.type === 'repeater' ) {
		const items = formatRepeaterValueLines( value, field );
		const blocks = items.map( ( it ) => {
			const visible = it.childLines.filter( ( cl ) => cl.display !== '' );
			if ( visible.length === 0 ) return '';
			const lines = visible.map( ( cl ) => `  - ${ cl.childLabel }: ${ cl.display }` );
			return `Item ${ it.itemIndex + 1 }\n${ lines.join( '\n' ) }`;
		} ).filter( Boolean );
		if ( blocks.length === 0 ) return `${ label }: —`;
		return `${ label }:\n${ blocks.join( '\n\n' ) }`;
	}

	if ( field && field.type === 'matrix' ) {
		const matrixLines = formatMatrixValueLines( value, field );
		const visible = matrixLines.filter( ( ml ) => ml.display !== '' );
		if ( visible.length === 0 ) return `${ label }: —`;
		const lines = visible.map( ( ml ) => `  - ${ ml.rowLabel }: ${ ml.display }` );
		return `${ label }:\n${ lines.join( '\n' ) }`;
	}

	// Select / radio / checkboxes: show option labels, not stored values.
	const choice = formatChoiceValue( value, field );
	if ( choice !== null ) {
		return `${ label }: ${ choice }`;
	}

	if ( typeof value === 'string' ) {
		return `${ label }: ${ value }`;
	}
	if ( value == null || value === '' ) {
		return `${ label }: `;
	}
	if ( Array.isArray( value ) ) {
		const flat = value.filter( ( v ) => v != null && typeof v !== 'object' ).map( String ).join( ', ' );
		return `${ label }: ${ flat }`;
	}
	if ( value && typeof value === 'object' && typeof value.filename === 'string' ) {
		// File metadata object — show the filename.
		return `${ label }: ${ value.filename }`;
	}
	// Genuinely unknown shape — fall back to a flat key=value join so
	// the bubble never contains a literal `{` / `[` character.
	const parts = [];
	for ( const [ k, v ] of Object.entries( value ) ) {
		if ( v == null || typeof v === 'object' ) continue;
		parts.push( `${ k }: ${ v }` );
	}
	return `${ label }: ${ parts.join( ', ' ) }`;
}

function Conversation( { entry, fieldLabels, fieldSchemas, refreshSignal } ) {
	const [ messages, setMessages ] = useState( [] );
	const [ loading,  setLoading  ] = useState( false );
	const [ error,    setError    ] = useState( '' );

	const entryId = entry?.id;

	useEffect( () => {
		if ( ! entryId ) return undefined;
		let cancelled = false;
		setLoading( true );
		setError( '' );
		api( `/entries/${ entryId }/messages` ).then( ( res ) => {
			if ( cancelled ) return;
			setMessages( Array.isArray( res ) ? res : [] );
			setLoading( false );
		} ).catch( ( err ) => {
			if ( cancelled ) return;
			setError( err?.message || '' );
			setLoading( false );
		} );
		return () => { cancelled = true; };
	}, [ entryId, refreshSignal ] );

	// Synthesize a leading "incoming" bubble from the entry's submitted
	// fields so the conversation starts with the original submission, not
	// the admin reply. The repository doesn't store this as a message row.
	const submissionBubble = ( () => {
		const data = entry?.data && typeof entry.data === 'object' ? entry.data : {};
		const lines = Object.entries( data ).map( ( [ k, v ] ) => {
			// Structured field types (repeater, matrix) go through
			// dedicated formatters so the bubble never contains raw
			// JSON. Everything else falls back to the scalar / joined
			// shape that worked before commit 5.
			return formatEntryFieldForConversation( k, v, fieldLabels, fieldSchemas );
		} );
		const sender = ( () => {
			for ( const v of Object.values( data ) ) {
				if ( typeof v === 'string' && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( v ) ) return v;
			}
			return '';
		} )();
		return {
			id:         'submission',
			type:       'incoming',
			sender,
			recipient:  '',
			body:       lines.join( '\n' ),
			created_at: entry?.submitted_at || entry?.created_at || '',
		};
	} )();

	const items = [ submissionBubble, ...messages ];

	return (
		<section className="neural-forms-entry-detail__conversation">
			<h4 className="neural-forms-entry-detail__conversation-title">
				{ __( 'Conversation', 'neural-forms-lite' ) }
			</h4>
			{ loading && (
				<p className="neural-forms-entries-status">{ __( 'Loading conversation…', 'neural-forms-lite' ) }</p>
			) }
			{ !! error && (
				<p className="neural-forms-entries-status neural-forms-entries-status--error">{ error }</p>
			) }
			<ol className="neural-forms-entry-detail__conversation-list">
				{ items.map( ( m ) => (
					<li
						key={ m.id }
						className={
							'neural-forms-entry-detail__conversation-item' +
							( m.type === 'outgoing'
								? ' neural-forms-entry-detail__conversation-item--outgoing'
								: ' neural-forms-entry-detail__conversation-item--incoming' )
						}
					>
						<header className="neural-forms-entry-detail__conversation-meta">
							<span className="neural-forms-entry-detail__conversation-direction">
								{ m.type === 'outgoing'
									? __( 'To', 'neural-forms-lite' )
									: __( 'From', 'neural-forms-lite' ) }
							</span>
							<span className="neural-forms-entry-detail__conversation-party">
								{ m.type === 'outgoing' ? m.recipient : m.sender }
							</span>
							{ m.created_at && (
								<span className="neural-forms-entry-detail__conversation-time">
									{ formatDate( m.created_at ) }
								</span>
							) }
						</header>
						{ m.subject && (
							<p className="neural-forms-entry-detail__conversation-subject">
								<strong>{ m.subject }</strong>
							</p>
						) }
						<pre className="neural-forms-entry-detail__conversation-body">{ m.body || '' }</pre>
					</li>
				) ) }
			</ol>
		</section>
	);
}

/**
 * Pricing snapshot card (1.2.0, part B): line items, discount, total,
 * deposit and the payment status pill.
 */
const PAYMENT_LABELS = {
	none: '', pending: __( 'Payment pending', 'neural-forms-lite' ), processing: __( 'Payment processing', 'neural-forms-lite' ),
	failed: __( 'Payment failed', 'neural-forms-lite' ), cancelled: __( 'Checkout cancelled', 'neural-forms-lite' ), deposit_paid: __( 'Deposit paid', 'neural-forms-lite' ),
	paid: __( 'Paid in full', 'neural-forms-lite' ), partially_refunded: __( 'Partially refunded', 'neural-forms-lite' ), refunded: __( 'Refunded', 'neural-forms-lite' ),
};

function PricingCard( { pricing, paymentStatus } ) {
	const cur = pricing.currency || 'USD';
	const f = ( m ) => formatMinor( m, cur );
	const lines = Array.isArray( pricing.line_items ) ? pricing.line_items : [];
	const status = paymentStatus && PAYMENT_LABELS[ paymentStatus ] ? paymentStatus : 'none';
	return (
		<section className="neural-forms-entry-detail__data-card neural-forms-entry-detail__pricing">
			<h4 className="neural-forms-entry-detail__data-card-title">
				{ __( 'Pricing', 'neural-forms-lite' ) }
				{ status !== 'none' && <span className={ 'neural-forms-entries-pill neural-forms-entries-pill--pay neural-forms-entries-pill--pay-' + status }>{ PAYMENT_LABELS[ status ] }</span> }
			</h4>
			{ lines.length > 0 && (
				<table className="neural-forms-entry-detail__pricing-lines">
					<tbody>
						{ lines.map( ( l, i ) => (
							<tr key={ i }>
								<td>{ l.choice_label || l.label }{ l.choice_label && l.label !== l.choice_label ? <small> · { l.label }</small> : null }</td>
								<td className="is-num">{ l.qty > 1 ? `${ l.qty } × ${ f( l.unit ) }` : '' }</td>
								<td className="is-num">{ f( l.total ) }</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }
			<dl className="neural-forms-entry-detail__pricing-totals">
				<dt>{ __( 'Subtotal', 'neural-forms-lite' ) }</dt><dd>{ f( pricing.subtotal ) }</dd>
				{ pricing.discount && pricing.discount.amount > 0 && <><dt>{ pricing.discount.label || __( 'Discount', 'neural-forms-lite' ) }</dt><dd>−{ f( pricing.discount.amount ) }</dd></> }
				<dt className="is-total">{ __( 'Total', 'neural-forms-lite' ) }</dt><dd className="is-total">{ f( pricing.total ) }</dd>
				{ pricing.deposit && pricing.deposit.amount > 0 && <><dt>{ __( 'Due now (deposit)', 'neural-forms-lite' ) }</dt><dd>{ f( pricing.due_now ) }</dd><dt>{ __( 'Remaining balance', 'neural-forms-lite' ) }</dt><dd>{ f( pricing.remaining ) }</dd></> }
			</dl>
			{ pricing.calculated && Object.keys( pricing.calculated ).length > 0 && (
				<dl className="neural-forms-entry-detail__pricing-calc">
					{ Object.entries( pricing.calculated ).map( ( [ id, c ] ) => <Fragment key={ id }><dt>{ id }</dt><dd>{ c.display }</dd></Fragment> ) }
				</dl>
			) }
			{ Array.isArray( pricing.errors ) && pricing.errors.length > 0 && (
				<p className="neural-forms-entry-detail__lead-error">{ pricing.errors.map( ( e ) => e.field + ': ' + e.message ).join( ' · ' ) }</p>
			) }
		</section>
	);
}

function NeedsReplyBanner( { entry, onMarkNeedsReply, onDismiss } ) {
	const category = String( entry.ai_category || '' ).trim();
	const [ busy, setBusy ] = useState( false );

	const mark = () => {
		setBusy( true );
		Promise.resolve( onMarkNeedsReply && onMarkNeedsReply() )
			.catch( () => {} )
			.finally( () => setBusy( false ) );
	};

	return (
		<section className="neural-forms-entry-detail__needs-reply" role="status">
			<div className="neural-forms-entry-detail__needs-reply-icon" aria-hidden="true">
				<NeedsReplyIcon />
			</div>
			<div className="neural-forms-entry-detail__needs-reply-body">
				<p className="neural-forms-entry-detail__needs-reply-title">
					{ __( 'This entry likely needs a reply.', 'neural-forms-lite' ) }
				</p>
				{ category && (
					<span className="neural-forms-entries-pill neural-forms-entry-detail__needs-reply-pill">
						{ category.charAt( 0 ).toUpperCase() + category.slice( 1 ) }
					</span>
				) }
				<div className="neural-forms-entry-detail__needs-reply-actions">
					<button
						type="button"
						className="neural-forms-entries-btn neural-forms-entries-btn--primary neural-forms-entry-detail__needs-reply-cta"
						onClick={ mark }
						disabled={ busy }
					>
						<span className="neural-forms-entry-detail__needs-reply-cta-icon" aria-hidden="true">
							<FlagIcon />
						</span>
						{ busy
							? __( 'Marking…', 'neural-forms-lite' )
							: __( 'Mark as Needs Reply', 'neural-forms-lite' ) }
					</button>
					<button
						type="button"
						className="neural-forms-entry-detail__needs-reply-dismiss"
						onClick={ onDismiss }
					>
						{ __( 'Not now', 'neural-forms-lite' ) }
					</button>
				</div>
			</div>
			<button
				type="button"
				className="neural-forms-entry-detail__needs-reply-close"
				onClick={ onDismiss }
				aria-label={ __( 'Dismiss', 'neural-forms-lite' ) }
			>
				<span aria-hidden="true">×</span>
			</button>
		</section>
	);
}

export default function EntryDetail( {
	entry,
	loading,
	error,
	capabilities,
	formNameFallback,
	fieldLabels,
	fieldSchemas,
	onReanalyze,
	reanalyzing,
	reanalyzeError,
	onPolish,
	onRegenerate,
	onSendReply,
	onFinalize,
	composerBusy,
	composerError,
	carouselBusy,
	replyResetSignal,
	messagesRefreshSignal,
	creditsExhausted,
	onDismissCreditsExhausted,
	lastAiUsage,
	lastAiUsageSource,
	onDismissLastAiUsage,
	analysisFreshKey,
	draftFreshKey,
	onToggleStar,
	onMarkNeedsReply,
	leadStages,
	assignees,
	onUpdateLead,
	activityRefreshSignal,
	onTranslate,
	siteLanguage,
} ) {
	const [ needsReplyDismissed, setNeedsReplyDismissed ] = useState( false );

	if ( loading ) return <div className="neural-forms-entry-detail neural-forms-entry-detail--status">{ __( 'Loading entry...', 'neural-forms-lite' ) }</div>;
	if ( error )   return <div className="neural-forms-entry-detail neural-forms-entry-detail--status neural-forms-entry-detail--error">{ error }</div>;
	if ( ! entry ) return null;

	const data = entry.data && typeof entry.data === 'object' ? entry.data : {};
	const rows = Object.entries( data );
	const drafts  = Array.isArray( entry.ai_responses ) ? entry.ai_responses : [];
	const selectedDraftId = entry.ai_selected_response_id != null
		? Number( entry.ai_selected_response_id )
		: null;

	const formName  = getEntryFormName( entry, formNameFallback );
	const ip        = getEntryIp( entry );
	const sourceUrl = getEntrySourceUrl( entry );
	const submittedAt = entry.submitted_at || entry.created_at;
	const showNeedsReply = ! needsReplyDismissed && shouldSuggestReply( entry );

	return (
		<div className="neural-forms-entry-detail">
			<header className="neural-forms-entry-detail__header neural-forms-entry-detail__header--card">
				<div className="neural-forms-entry-detail__header-icon" aria-hidden="true">
					<FormIcon />
				</div>
				<div className="neural-forms-entry-detail__header-body">
					<h3 className="neural-forms-entry-detail__title">
						{ formName || __( 'Entry', 'neural-forms-lite' ) + ' #' + entry.id }
					</h3>
					<p className="neural-forms-entry-detail__meta">
						<span className="neural-forms-entry-detail__meta-id">
							{ __( 'Entry', 'neural-forms-lite' ) } <strong>#{ entry.id }</strong>
						</span>
						{ submittedAt && (
							<>
								<span className="neural-forms-entry-detail__meta-sep" aria-hidden="true">·</span>
								<span>{ formatDate( submittedAt ) }</span>
							</>
						) }
						{ ip && (
							<>
								<span className="neural-forms-entry-detail__meta-sep" aria-hidden="true">·</span>
								<span className="neural-forms-entry-detail__meta-ip">{ ip }</span>
							</>
						) }
					</p>
					{ sourceUrl && (
						<p className="neural-forms-entry-detail__source">
							<span className="neural-forms-entry-detail__source-icon" aria-hidden="true">
								<ExternalLinkIcon />
							</span>
							<a
								className="neural-forms-entry-detail__source-link"
								href={ sourceUrl }
								target="_blank"
								rel="noreferrer"
							>
								{ sourceUrl }
							</a>
						</p>
					) }
				</div>
				<StatusPill entry={ entry } />
			</header>

			{ showNeedsReply && (
				<NeedsReplyBanner
					entry={ entry }
					onMarkNeedsReply={ onMarkNeedsReply }
					onDismiss={ () => setNeedsReplyDismissed( true ) }
				/>
			) }

			<LeadPanel
				entry={ entry }
				capabilities={ capabilities }
				leadStages={ leadStages }
				assignees={ assignees }
				onUpdateLead={ onUpdateLead }
			/>

			{ creditsExhausted && (
				<aside className="neural-forms-entries-pro-notice neural-forms-entries-pro-notice--credits" role="status">
					<span className="neural-forms-entries-pro-notice__icon" aria-hidden="true">
						<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
							strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round">
							<circle cx="12" cy="12" r="9" />
							<path d="M12 7v6" />
							<path d="M12 16v.01" />
						</svg>
					</span>
					<div className="neural-forms-entries-pro-notice__body">
						<p className="neural-forms-entries-pro-notice__title">
							{ __( 'Out of Neural AI credits', 'neural-forms-lite' ) }
						</p>
						<p className="neural-forms-entries-pro-notice__desc">
							{ creditsExhausted.message
								|| __( 'You have used all your Neural AI credits. Buy more to continue analyzing entries and generating replies.', 'neural-forms-lite' ) }
						</p>
					</div>
					<a
						className="neural-forms-entries-pro-notice__cta"
						href={ creditsExhausted.upgradeUrl || 'https://neuralforms.ai/pricing' }
						target="_blank"
						rel="noreferrer"
					>
						{ __( 'Buy credits', 'neural-forms-lite' ) }
					</a>
					<button
						type="button"
						className="neural-forms-entries-pro-notice__close"
						onClick={ onDismissCreditsExhausted }
						aria-label={ __( 'Dismiss', 'neural-forms-lite' ) }
					>
						<span aria-hidden="true">×</span>
					</button>
				</aside>
			) }

			{ entry.pricing && typeof entry.pricing === 'object' && (
				<PricingCard pricing={ entry.pricing } paymentStatus={ entry.payment_status } />
			) }
			<PaymentCard entry={ entry } capabilities={ capabilities } />
			<QuoteCard entry={ entry } capabilities={ capabilities } />

			<section className="neural-forms-entry-detail__data-card">
				<h4 className="neural-forms-entry-detail__data-card-title">{ __( 'Submitted fields', 'neural-forms-lite' ) }</h4>
				{ rows.length === 0 ? (
					<p className="neural-forms-entry-detail__data-empty">{ __( 'No data captured for this entry.', 'neural-forms-lite' ) }</p>
				) : (
					<dl className="neural-forms-entry-detail__fields">
						{ rows.map( ( [ k, v ] ) => {
							const label = ( fieldLabels && fieldLabels[ k ] ) || k;
							const field = ( fieldSchemas && fieldSchemas[ k ] ) || null;

							// Product (1.2.0 part B): "SEO, Copywriting × 2".
							if ( field && field.type === 'product' ) {
								return (
									<div key={ k } className="neural-forms-entry-detail__field-row">
										<dt>{ label }</dt>
										<dd>{ formatProductValue( v, field ) || __( '(nothing chosen)', 'neural-forms-lite' ) }</dd>
									</div>
								);
							}

							// Repeater: render per-iteration block of child label/value lines.
							if ( field && field.type === 'repeater' ) {
								const items = formatRepeaterValueLines( v, field );
								const hasAny = items.length > 0 && items.some(
									( it ) => it.childLines.some( ( cl ) => cl.display !== '' )
								);
								return (
									<div
										className="neural-forms-entry-detail__field-row neural-forms-entry-detail__field-row--repeater"
										key={ k }
									>
										<dt className="neural-forms-entry-detail__field-label">{ label }</dt>
										<dd className="neural-forms-entry-detail__field-value">
											{ ! hasAny ? (
												<span className="neural-forms-entry-detail__field-empty">
													{ __( 'No items', 'neural-forms-lite' ) }
												</span>
											) : (
												<ol className="neural-forms-entry-detail__repeater-list">
													{ items.map( ( it ) => {
														const visible = it.childLines.filter( ( cl ) => cl.display !== '' );
														if ( visible.length === 0 ) return null;
														return (
															<li
																className="neural-forms-entry-detail__repeater-item"
																key={ it.itemIndex }
															>
																<div className="neural-forms-entry-detail__repeater-item-num">
																	{ __( 'Item', 'neural-forms-lite' ) } { it.itemIndex + 1 }
																</div>
																<ul className="neural-forms-entry-detail__repeater-children">
																	{ visible.map( ( cl ) => (
																		<li
																			className="neural-forms-entry-detail__repeater-child"
																			key={ cl.childId }
																		>
																			<span className="neural-forms-entry-detail__repeater-child-label">
																				{ cl.childLabel }:
																			</span>{ ' ' }
																			{ cl.childType === 'file' && cl.raw && cl.raw.url ? (
																				<a
																					className="neural-forms-entry-detail__repeater-child-value"
																					href={ cl.raw.url }
																					target="_blank"
																					rel="noreferrer"
																				>
																					{ cl.display }
																				</a>
																			) : (
																				<span className="neural-forms-entry-detail__repeater-child-value">
																					{ cl.display }
																				</span>
																			) }
																		</li>
																	) ) }
																</ul>
															</li>
														);
													} ) }
												</ol>
											) }
										</dd>
									</div>
								);
							}

							// Matrix: render row-by-row labelled selections.
							if ( field && field.type === 'matrix' ) {
								const matrixLines = formatMatrixValueLines( v, field );
								const hasAny = matrixLines.some( ( ml ) => ml.display !== '' );
								return (
									<div
										className="neural-forms-entry-detail__field-row neural-forms-entry-detail__field-row--matrix"
										key={ k }
									>
										<dt className="neural-forms-entry-detail__field-label">{ label }</dt>
										<dd className="neural-forms-entry-detail__field-value">
											{ ! hasAny ? (
												<span className="neural-forms-entry-detail__field-empty">
													{ __( 'No answer', 'neural-forms-lite' ) }
												</span>
											) : (
												<ul className="neural-forms-entry-detail__matrix-list">
													{ matrixLines.map( ( ml ) => (
														<li
															className="neural-forms-entry-detail__matrix-line"
															key={ ml.rowValue }
														>
															<span className="neural-forms-entry-detail__matrix-row-label">
																{ ml.rowLabel }:
															</span>
															<span className="neural-forms-entry-detail__matrix-row-value">
																{ ml.display || '—' }
															</span>
														</li>
													) ) }
												</ul>
											) }
										</dd>
									</div>
								);
							}

							// Select / radio / checkboxes: option LABELS ("Laptop"),
							// not stored values ("laptop"); checkbox groups join
							// their labels instead of rendering a JSON array.
							const choiceText = formatChoiceValue( v, field );
							if ( choiceText !== null ) {
								return (
									<div className="neural-forms-entry-detail__field-row" key={ k }>
										<dt className="neural-forms-entry-detail__field-label">{ label }</dt>
										<dd className="neural-forms-entry-detail__field-value">
											{ choiceText !== '' ? choiceText : (
												<span className="neural-forms-entry-detail__field-empty">{ __( 'No answer', 'neural-forms-lite' ) }</span>
											) }
										</dd>
									</div>
								);
							}

							// File upload — render filename as a download link
							// instead of dumping the raw {attachment_id, url, ...}
							// JSON envelope into the value cell. Matches the
							// repeater child-row treatment above. Handles both
							// the canonical object shape and the rare array
							// shape (multi-file fields ship as [ {…}, {…} ]).
							const isFileType  = !! ( field && field.type === 'file' );
							const looksLikeFile = ! isFileType && v && typeof v === 'object' && ! Array.isArray( v )
								&& typeof v.url === 'string' && v.url !== '';
							if ( isFileType || looksLikeFile ) {
								const files = Array.isArray( v ) ? v : [ v ];
								const validFiles = files.filter( ( f ) => f && typeof f === 'object' && typeof f.url === 'string' && f.url !== '' );
								return (
									<div className="neural-forms-entry-detail__field-row" key={ k }>
										<dt className="neural-forms-entry-detail__field-label">{ label }</dt>
										<dd className="neural-forms-entry-detail__field-value">
											{ validFiles.length === 0 ? (
												<span className="neural-forms-entry-detail__field-empty">
													{ __( 'No file uploaded', 'neural-forms-lite' ) }
												</span>
											) : (
												<ul className="neural-forms-entry-detail__file-list">
													{ validFiles.map( ( f, i ) => (
														<li key={ ( f.attachment_id || i ) + '' } className="neural-forms-entry-detail__file-item">
															<a
																className="neural-forms-entry-detail__file-link"
																href={ f.url }
																target="_blank"
																rel="noreferrer"
															>
																{ f.filename || f.url }
															</a>
															{ typeof f.size === 'number' && f.size > 0 && (
																<span className="neural-forms-entry-detail__file-meta">
																	{ formatFileSize( f.size ) }
																</span>
															) }
														</li>
													) ) }
												</ul>
											) }
										</dd>
									</div>
								);
							}

							// Phone fields: insert a separator between the
							// dial code and the local number so values
							// stored without a space (legacy submissions
							// from before the picker added one
							// automatically) read as "+1 9785880685"
							// instead of "+19785880685". Only touches
							// values that match the +DD-then-digits
							// pattern; everything else passes through
							// unchanged.
							const isPhoneType = !! ( field && ( field.type === 'phone' || field.type === 'tel' ) );
							let displayV = v;
							if ( isPhoneType && typeof v === 'string' ) {
								const m = v.match( /^(\+\d{1,4})(\d{4,})$/ );
								if ( m ) displayV = m[ 1 ] + ' ' + m[ 2 ];
							}

							return (
								<div className="neural-forms-entry-detail__field-row" key={ k }>
									<dt className="neural-forms-entry-detail__field-label">{ label }</dt>
									<dd className="neural-forms-entry-detail__field-value">
										{ typeof displayV === 'string' ? displayV : ( displayV == null ? '' : JSON.stringify( displayV ) ) }
									</dd>
								</div>
							);
						} ) }
					</dl>
				) }
			</section>

			<AiAnalysisCard
				entry={ entry }
				capabilities={ capabilities }
				onReanalyze={ onReanalyze }
				onRegenerate={ onRegenerate }
				reanalyzing={ reanalyzing }
				regenerating={ composerBusy === 'regenerate' }
				freshKey={ analysisFreshKey }
				error={ reanalyzeError }
			/>

			{ /* Reanalyze + Generate reply both live as buttons inside the
			     AI Analysis card above, so their credit metering renders
			     here — directly under the buttons, before the AI Drafts
			     card — which is where the click happened. Refine with AI
			     keeps its line in the reply composer below. */ }
			{ lastAiUsage && lastAiUsageSource === 'analyze' && (
				<UsageLine usage={ lastAiUsage } onDismiss={ onDismissLastAiUsage } />
			) }

			<AiCarousel
				drafts={ drafts }
				selectedId={ selectedDraftId }
				onFinalize={ onFinalize }
				busy={ carouselBusy }
				generating={ composerBusy === 'regenerate' || composerBusy === 'polish' }
				generatingLabel={
					composerBusy === 'polish'
						? __( 'Refining reply…', 'neural-forms-lite' )
						: __( 'Generating reply…', 'neural-forms-lite' )
				}
				freshKey={ draftFreshKey }
				onTranslate={ onTranslate }
				siteLanguage={ siteLanguage }
			/>

			<ActivityTimeline
				entry={ entry }
				refreshSignal={ activityRefreshSignal }
			/>

			<Conversation
				entry={ entry }
				fieldLabels={ fieldLabels }
				fieldSchemas={ fieldSchemas }
				refreshSignal={ messagesRefreshSignal }
			/>

			{ entry.status !== 'spam' && entry.status !== 'trash' && (
				<ReplyComposer
					entry={ entry }
					capabilities={ capabilities }
					onSend={ onSendReply }
					onPolish={ onPolish }
					busy={ composerBusy }
					error={ composerError }
					resetSignal={ replyResetSignal }
					replyFreshKey={ draftFreshKey }
					usage={ lastAiUsageSource === 'reply' ? lastAiUsage : null }
					onDismissUsage={ onDismissLastAiUsage }
				/>
			) }
		</div>
	);
}
