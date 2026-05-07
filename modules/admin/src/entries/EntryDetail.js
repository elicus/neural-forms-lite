/**
 * Right-pane detail view for a single entry.
 *
 * Composes the AI analysis card, the response carousel, the raw
 * field grid, and the reply composer. The screen owner threads
 * REST handlers and capability state through props so this file
 * stays presentational.
 *
 * Layout (top → bottom):
 *   header     form name / submitter / date / status / star
 *   data       raw submitted field key/value table — rendered FIRST
 *              so users see what was submitted before the AI gloss
 *   analysis   AI card (Pro slot for Reanalyze + Regenerate buttons)
 *   carousel   AI drafts (only shown when there's at least one)
 *   reply      reply composer (Pro slot for Polish/Refine button)
 *
 * Props:
 *   entry          full entry from /entries/{id}
 *   loading        boolean — initial fetch in flight
 *   error          string — initial fetch error
 *   capabilities   capability snapshot from neuralFormsAdmin.plugin.capabilities
 *   fieldLabels    { field_id: label } — built from the parent form's
 *                  schema; falls back to the raw key when missing
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
	if ( entry.status === 'spam' )  return <span className="neural-forms-entries-pill neural-forms-entries-pill--spam">{ __( 'Spam', 'neural-forms-lite' ) }</span>;
	if ( entry.status === 'trash' ) return <span className="neural-forms-entries-pill neural-forms-entries-pill--trash">{ __( 'Trash', 'neural-forms-lite' ) }</span>;
	if ( entry.status === 'new'   ) return <span className="neural-forms-entries-pill neural-forms-entries-pill--new">{ __( 'Unread', 'neural-forms-lite' ) }</span>;
	return <span className="neural-forms-entries-pill neural-forms-entries-pill--read">{ __( 'Read', 'neural-forms-lite' ) }</span>;
}

export default function EntryDetail( {
	entry,
	loading,
	error,
	capabilities,
	fieldLabels,
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
	if ( loading ) return <div className="neural-forms-entry-detail neural-forms-entry-detail--status">{ __( 'Loading entry...', 'neural-forms-lite' ) }</div>;
	if ( error )   return <div className="neural-forms-entry-detail neural-forms-entry-detail--status neural-forms-entry-detail--error">{ error }</div>;
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
		<div className="neural-forms-entry-detail">
			<header className="neural-forms-entry-detail__header">
				<div>
					<h3 className="neural-forms-entry-detail__title">{ entry.form_name || `#${ entry.form_id }` }</h3>
					<p className="neural-forms-entry-detail__meta">
						{ email && <span>{ email }</span> }
						{ email && ' · ' }
						<span>{ formatDate( entry.submitted_at || entry.created_at ) }</span>
						<span> · </span>
						<StatusPill entry={ entry } />
					</p>
				</div>
				<button
					type="button"
					className={ 'neural-forms-entries-star' + ( starred ? ' is-starred' : '' ) }
					onClick={ onToggleStar }
					aria-label={ starred ? __( 'Unstar', 'neural-forms-lite' ) : __( 'Star', 'neural-forms-lite' ) }
				>{ starred ? '★' : '☆' }</button>
			</header>

			<section className="neural-forms-entry-detail__data-card">
				<h4 className="neural-forms-entry-detail__data-card-title">{ __( 'Submitted fields', 'neural-forms-lite' ) }</h4>
				{ rows.length === 0 ? (
					<p className="neural-forms-entry-detail__data-empty">{ __( 'No data captured for this entry.', 'neural-forms-lite' ) }</p>
				) : (
					<dl className="neural-forms-entry-detail__fields">
						{ rows.map( ( [ k, v ] ) => {
							const label = ( fieldLabels && fieldLabels[ k ] ) || k;
							return (
								<div className="neural-forms-entry-detail__field-row" key={ k }>
									<dt className="neural-forms-entry-detail__field-label">{ label }</dt>
									<dd className="neural-forms-entry-detail__field-value">
										{ typeof v === 'string' ? v : ( v == null ? '' : JSON.stringify( v ) ) }
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
				error={ reanalyzeError }
			/>

			<AiCarousel
				drafts={ drafts }
				selectedId={ selectedDraftId }
				onFinalize={ onFinalize }
				busy={ carouselBusy }
			/>

			{ entry.status !== 'spam' && entry.status !== 'trash' && (
				<ReplyComposer
					entry={ entry }
					capabilities={ capabilities }
					onSend={ onSendReply }
					onPolish={ onPolish }
					busy={ composerBusy }
					error={ composerError }
				/>
			) }
		</div>
	);
}
