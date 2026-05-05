/**
 * Right-pane detail view for a single entry.
 *
 * Composes the AI analysis card, the response carousel, the raw
 * field grid, and the reply composer. The screen owner threads
 * REST handlers and capability state through props so this file
 * stays presentational.
 *
 * Layout:
 *   header     form name / submitter / date / status / star
 *   analysis   AI card (Pro-gated reanalyze)
 *   carousel   AI drafts (only shown when there's at least one)
 *   data       raw field key/value table
 *   reply      reply composer (Pro-gated polish/regenerate)
 *
 * Props:
 *   entry          full entry from /entries/{id}
 *   loading        boolean — initial fetch in flight
 *   error          string — initial fetch error
 *   capabilities   capability snapshot from neuralFormsAdmin.plugin.capabilities
 *   onReanalyze    () => Promise<void>
 *   reanalyzing    bool
 *   reanalyzeError string
 *   onPolish       (text) => Promise<{polished_reply, ...}>
 *   onRegenerate   () => Promise<{reply_draft, ...}>
 *   onSendReply    ({to, subject, body}) => Promise<void>
 *   onFinalize     (draftId) => Promise<void>
 *   composerBusy   'send' | 'polish' | 'regenerate' | null
 *   composerError  string
 *   carouselBusy   draftId | null
 *   onToggleStar   () => void
 */

import { __ } from '@wordpress/i18n';
import AiAnalysisCard from './AiAnalysisCard';
import AiCarousel     from './AiCarousel';
import ReplyComposer  from './ReplyComposer';
import { formatDate, pickSubmitterEmail } from './helpers';

function StatusPill( { entry } ) {
	if ( entry.status === 'spam' )  return <span className="nf-pill nf-pill--spam">{ __( 'Spam', 'neural-forms-lite' ) }</span>;
	if ( entry.status === 'trash' ) return <span className="nf-pill nf-pill--trash">{ __( 'Trash', 'neural-forms-lite' ) }</span>;
	if ( entry.status === 'new'   ) return <span className="nf-pill nf-pill--new">{ __( 'Unread', 'neural-forms-lite' ) }</span>;
	return <span className="nf-pill nf-pill--read">{ __( 'Read', 'neural-forms-lite' ) }</span>;
}

export default function EntryDetail( {
	entry,
	loading,
	error,
	capabilities,
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
	onToggleStar,
} ) {
	if ( loading ) return <div className="nf-inbox__detail nf-inbox__detail--status">{ __( 'Loading entry...', 'neural-forms-lite' ) }</div>;
	if ( error )   return <div className="nf-inbox__detail nf-inbox__detail--status nf-inbox__detail--error">{ error }</div>;
	if ( ! entry ) return null;

	const data = entry.data && typeof entry.data === 'object' ? entry.data : {};
	const rows = Object.entries( data );
	const email = pickSubmitterEmail( null, data );
	const starred = entry.is_starred === 1 || entry.is_starred === true;
	const drafts  = Array.isArray( entry.ai_responses ) ? entry.ai_responses : [];
	const selectedDraftId = entry.ai_selected_response_id != null
		? Number( entry.ai_selected_response_id )
		: null;

	return (
		<div className="nf-inbox__detail">
			<header className="nf-inbox__detail-header">
				<div>
					<h3 className="nf-inbox__detail-title">{ entry.form_name || `#${ entry.form_id }` }</h3>
					<p className="nf-inbox__detail-meta">
						{ email && <span>{ email }</span> }
						{ email && ' · ' }
						<span>{ formatDate( entry.submitted_at || entry.created_at ) }</span>
						<span> · </span>
						<StatusPill entry={ entry } />
					</p>
				</div>
				<button
					type="button"
					className={ 'nf-inbox__star' + ( starred ? ' is-starred' : '' ) }
					onClick={ onToggleStar }
					aria-label={ starred ? __( 'Unstar', 'neural-forms-lite' ) : __( 'Star', 'neural-forms-lite' ) }
				>{ starred ? '★' : '☆' }</button>
			</header>

			<AiAnalysisCard
				entry={ entry }
				capabilities={ capabilities }
				onReanalyze={ onReanalyze }
				reanalyzing={ reanalyzing }
				error={ reanalyzeError }
			/>

			<AiCarousel
				drafts={ drafts }
				selectedId={ selectedDraftId }
				onFinalize={ onFinalize }
				busy={ carouselBusy }
			/>

			<section className="nf-inbox__detail-data">
				<h4 className="nf-inbox__detail-section-title">{ __( 'Submitted fields', 'neural-forms-lite' ) }</h4>
				{ rows.length === 0 ? (
					<p className="nf-inbox__detail-empty">{ __( 'No data captured for this entry.', 'neural-forms-lite' ) }</p>
				) : (
					<dl className="nf-inbox__detail-grid">
						{ rows.map( ( [ k, v ] ) => (
							<div className="nf-inbox__detail-row" key={ k }>
								<dt>{ k }</dt>
								<dd>{ typeof v === 'string' ? v : ( v == null ? '' : JSON.stringify( v ) ) }</dd>
							</div>
						) ) }
					</dl>
				) }
			</section>

			{ entry.status !== 'spam' && entry.status !== 'trash' && (
				<ReplyComposer
					entry={ entry }
					capabilities={ capabilities }
					onSend={ onSendReply }
					onPolish={ onPolish }
					onRegenerate={ onRegenerate }
					busy={ composerBusy }
					error={ composerError }
				/>
			) }
		</div>
	);
}
