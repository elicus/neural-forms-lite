/**
 * AI response draft carousel.
 *
 * Pages through every saved draft for an entry — analyze, reanalyze,
 * regenerate, polish — so users can flip back to a prior draft via
 * "Use this reply". Each row in the underlying repository represents
 * a single AI call; the carousel renders one card per draft.
 *
 * Up to 10 non-finalized drafts retained per entry by the cloud
 * (rolling); finalized drafts are kept indefinitely. Empty entries
 * (pre-upgrade rows or rows that have never been analyzed) collapse
 * the carousel entirely so we don't show an empty card.
 *
 * Props:
 *   drafts        array of shaped draft rows from /entries/{id}/ai-responses
 *   selectedId    int | null — id of the currently finalized draft
 *   onFinalize    (draftId) => Promise<void>
 *   busy          number | null — id of draft currently being finalized
 */

import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { KIND_ANALYZE, KIND_REANALYZE, KIND_REGENERATE, KIND_POLISH } from './constants';
import { formatDate } from './helpers';

const KIND_LABEL = {
	[ KIND_ANALYZE   ]: 'Analysis',
	[ KIND_REANALYZE ]: 'Reanalysis',
	[ KIND_REGENERATE ]: 'Regenerated reply',
	[ KIND_POLISH    ]: 'Polished reply',
};

function bodyForKind( draft ) {
	if ( draft.kind === KIND_POLISH ) return draft.polished_reply || '';
	return draft.reply_draft || '';
}

export default function AiCarousel( { drafts, selectedId, onFinalize, busy } ) {
	const list = Array.isArray( drafts ) ? drafts : [];
	const [ index, setIndex ] = useState( 0 );

	// When a new draft lands, jump the carousel to it so the user
	// sees the latest output without having to click forward.
	useEffect( () => {
		setIndex( Math.max( 0, list.length - 1 ) );
	}, [ list.length ] );

	if ( list.length === 0 ) return null;

	const safeIndex = Math.min( index, list.length - 1 );
	const draft = list[ safeIndex ];
	if ( ! draft ) return null;

	const isSelected = selectedId === draft.id;

	return (
		<section className="nf-inbox__ai-carousel">
			<header className="nf-inbox__ai-carousel-header">
				<h4 className="nf-inbox__ai-carousel-title">{ __( 'AI drafts', 'neural-forms-lite' ) }</h4>
				<div className="nf-inbox__ai-carousel-nav">
					<button
						type="button"
						className="neural-forms-btn nf-inbox__ai-carousel-prev"
						onClick={ () => setIndex( ( i ) => Math.max( 0, i - 1 ) ) }
						disabled={ safeIndex === 0 }
						aria-label={ __( 'Previous draft', 'neural-forms-lite' ) }
					>‹</button>
					<span className="nf-inbox__ai-carousel-index">
						{ safeIndex + 1 } / { list.length }
					</span>
					<button
						type="button"
						className="neural-forms-btn nf-inbox__ai-carousel-next"
						onClick={ () => setIndex( ( i ) => Math.min( list.length - 1, i + 1 ) ) }
						disabled={ safeIndex === list.length - 1 }
						aria-label={ __( 'Next draft', 'neural-forms-lite' ) }
					>›</button>
				</div>
			</header>

			<div className="nf-inbox__ai-carousel-meta">
				<span className="nf-pill nf-pill--neutral">
					{ __( KIND_LABEL[ draft.kind ] || 'Draft', 'neural-forms-lite' ) }
				</span>
				{ draft.tier_used && (
					<span className="nf-pill nf-pill--tier">{ draft.tier_used }</span>
				) }
				{ isSelected && (
					<span className="nf-pill nf-pill--read">{ __( 'In use', 'neural-forms-lite' ) }</span>
				) }
				<span className="nf-inbox__ai-carousel-date">{ formatDate( draft.created_at ) }</span>
			</div>

			{ ( draft.summary || draft.category || draft.priority ) && (
				<dl className="nf-inbox__ai-carousel-classify">
					{ draft.summary && (
						<div className="nf-inbox__ai-carousel-row nf-inbox__ai-carousel-row--full">
							<dt>{ __( 'Summary', 'neural-forms-lite' ) }</dt>
							<dd>{ draft.summary }</dd>
						</div>
					) }
					{ draft.category && (
						<div className="nf-inbox__ai-carousel-row">
							<dt>{ __( 'Category', 'neural-forms-lite' ) }</dt>
							<dd>{ draft.category }</dd>
						</div>
					) }
					{ draft.priority && (
						<div className="nf-inbox__ai-carousel-row">
							<dt>{ __( 'Priority', 'neural-forms-lite' ) }</dt>
							<dd>{ draft.priority }</dd>
						</div>
					) }
				</dl>
			) }

			<pre className="nf-inbox__ai-carousel-body">{ bodyForKind( draft ) }</pre>

			<footer className="nf-inbox__ai-carousel-actions">
				<button
					type="button"
					className="neural-forms-btn neural-forms-btn--primary"
					onClick={ () => onFinalize( draft.id ) }
					disabled={ isSelected || busy === draft.id }
				>
					{ busy === draft.id
						? __( 'Applying...', 'neural-forms-lite' )
						: ( isSelected ? __( 'In use', 'neural-forms-lite' ) : __( 'Use this draft', 'neural-forms-lite' ) )
					}
				</button>
			</footer>
		</section>
	);
}
