/**
 * AI analysis card displayed in the entry detail pane.
 *
 * Shows the cloud-side analysis: summary, category, priority, score,
 * spam classification, and a "Reanalyze" button (Pro-gated). Tier
 * badge shows which model wrote this analysis (fast/balanced/smart/
 * premium) so users can see at a glance how their plan ladder
 * resolved per-call.
 *
 * Also hosts the EntryAiActions SlotFill so Pro can inject extra
 * action buttons (e.g. "Regenerate analysis") next to Reanalyze
 * without Lite shipping the implementation.
 *
 * Props:
 *   entry          full entry row from /entries/{id}
 *   capabilities   { ai_reanalyze, ai_refine_reply, ... } snapshot
 *   onReanalyze    () => Promise<void> — owner runs the request
 *   reanalyzing    boolean — disables button while request is in flight
 *   error          string — shown inline below the card
 */

import { __ } from '@wordpress/i18n';
import { useMemo } from '@wordpress/element';

const TIER_LABEL = {
	fast:     'Fast',
	balanced: 'Balanced',
	smart:    'Smart',
	premium:  'Premium',
};

function tierLabel( t ) {
	const k = String( t || 'fast' ).toLowerCase();
	return TIER_LABEL[ k ] || k;
}

function getSlotFill() {
	// SlotFill mount points are exposed on a window registry by the
	// admin shell so Pro's code can register fills without a hard
	// import. Lite renders nothing if Pro hasn't registered.
	const reg = window.neuralForms?.slots;
	if ( reg && typeof reg.getSlot === 'function' ) {
		return reg.getSlot( 'NeuralForms.EntryAiActions' );
	}
	return null;
}

export default function AiAnalysisCard( { entry, capabilities, onReanalyze, reanalyzing, error } ) {
	const Slot = useMemo( getSlotFill, [] );

	if ( ! entry ) return null;

	const summary  = entry.ai_summary  || '';
	const category = entry.ai_category || '';
	const priority = entry.ai_priority || '';
	const score    = entry.ai_score;
	const tier     = entry.ai_tier;
	const isSpam   = entry.ai_is_spam === 1 || entry.ai_is_spam === true;
	const spamConf = entry.ai_spam_confidence || 0;
	const spamReason = entry.ai_spam_reason || '';
	const status   = entry.ai_status || '';

	const hasAnalysis = !! ( summary || category || priority || score != null );

	const canReanalyze = !! capabilities?.ai_reanalyze;

	return (
		<section className="nf-inbox__ai-card">
			<header className="nf-inbox__ai-card-header">
				<h4 className="nf-inbox__ai-card-title">{ __( 'AI Analysis', 'neural-forms-lite' ) }</h4>
				<div className="nf-inbox__ai-card-meta">
					{ tier && (
						<span className="nf-pill nf-pill--tier" title={ __( 'AI tier used', 'neural-forms-lite' ) }>
							{ tierLabel( tier ) }
						</span>
					) }
					{ status === 'queued'     && <span className="nf-pill nf-pill--neutral">{ __( 'Queued', 'neural-forms-lite' ) }</span> }
					{ status === 'processing' && <span className="nf-pill nf-pill--neutral">{ __( 'Processing...', 'neural-forms-lite' ) }</span> }
					{ status === 'failed'     && <span className="nf-pill nf-pill--spam">{ __( 'AI failed', 'neural-forms-lite' ) }</span> }
				</div>
			</header>

			{ ! hasAnalysis && status !== 'queued' && status !== 'processing' && (
				<p className="nf-inbox__ai-card-empty">
					{ __( 'No analysis yet for this entry.', 'neural-forms-lite' ) }
				</p>
			) }

			{ hasAnalysis && (
				<dl className="nf-inbox__ai-card-grid">
					{ summary && (
						<div className="nf-inbox__ai-card-row nf-inbox__ai-card-row--full">
							<dt>{ __( 'Summary', 'neural-forms-lite' ) }</dt>
							<dd>{ summary }</dd>
						</div>
					) }
					{ category && (
						<div className="nf-inbox__ai-card-row">
							<dt>{ __( 'Category', 'neural-forms-lite' ) }</dt>
							<dd>{ category }</dd>
						</div>
					) }
					{ priority && (
						<div className="nf-inbox__ai-card-row">
							<dt>{ __( 'Priority', 'neural-forms-lite' ) }</dt>
							<dd>{ priority }</dd>
						</div>
					) }
					{ score != null && (
						<div className="nf-inbox__ai-card-row">
							<dt>{ __( 'Score', 'neural-forms-lite' ) }</dt>
							<dd>{ score }/100</dd>
						</div>
					) }
				</dl>
			) }

			{ isSpam && (
				<div className="nf-inbox__ai-card-spam">
					<span className="nf-pill nf-pill--spam">{ __( 'Likely spam', 'neural-forms-lite' ) }</span>
					{ spamConf > 0 && (
						<span className="nf-inbox__ai-card-spam-conf">{ spamConf }%</span>
					) }
					{ spamReason && (
						<p className="nf-inbox__ai-card-spam-reason">{ spamReason }</p>
					) }
				</div>
			) }

			{ !! error && <p className="nf-inbox__inline-error">{ error }</p> }

			<footer className="nf-inbox__ai-card-actions">
				{ canReanalyze ? (
					<button
						type="button"
						className="neural-forms-btn"
						onClick={ onReanalyze }
						disabled={ reanalyzing }
					>
						{ reanalyzing
							? __( 'Reanalyzing...', 'neural-forms-lite' )
							: ( hasAnalysis ? __( 'Reanalyze', 'neural-forms-lite' ) : __( 'Analyze', 'neural-forms-lite' ) )
						}
					</button>
				) : (
					<a
						className="nf-inbox__upsell-link"
						href="https://neuralforms.ai/pro/"
						target="_blank"
						rel="noreferrer"
					>
						{ __( 'Reanalyze (Pro)', 'neural-forms-lite' ) }
					</a>
				) }

				{ /* Pro fills this slot to add Regenerate Analysis etc. */ }
				{ Slot ? <Slot.Slot fillProps={ { entry } } /> : null }
			</footer>
		</section>
	);
}
