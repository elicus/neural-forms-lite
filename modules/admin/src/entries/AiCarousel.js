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
 * Carousel itself is Lite-owned: it just pages through saved cloud
 * responses and calls Lite's /finalize endpoint. Pro renders nothing
 * here — only the AI rerun buttons (slot above) and the Polish button
 * (slot in ReplyComposer) are Pro-rendered.
 *
 * Props:
 *   drafts        array of shaped draft rows from /entries/{id}/ai-responses
 *   selectedId    int | null — id of the currently finalized draft
 *   onFinalize    (draftId) => Promise<void>
 *   busy          number | null — id of draft currently being finalized
 */

import { useState, useEffect, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
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

export default function AiCarousel( {
	drafts,
	selectedId,
	onFinalize,
	onTranslate,
	siteLanguage,
	busy,
	generating,
	generatingLabel,
	freshKey,
} ) {
	const list = Array.isArray( drafts ) ? drafts : [];
	const [ index, setIndex ] = useState( 0 );

	// When a new draft lands, jump the carousel to it so the user
	// sees the latest output without having to click forward.
	useEffect( () => {
		setIndex( Math.max( 0, list.length - 1 ) );
	}, [ list.length ] );

	// 2.5s mint pulse whenever freshKey bumps (regenerate or polish).
	// First render is skipped so opening an entry doesn't fake-pulse.
	const [ isFresh, setIsFresh ] = useState( false );
	const firstRef = useRef( true );
	useEffect( () => {
		if ( firstRef.current ) { firstRef.current = false; return; }
		setIsFresh( true );
		const t = setTimeout( () => setIsFresh( false ), 2500 );
		return () => clearTimeout( t );
	}, [ freshKey ] );

	if ( list.length === 0 ) return null;

	const safeIndex = Math.min( index, list.length - 1 );
	const draft = list[ safeIndex ];
	if ( ! draft ) return null;

	const isSelected = selectedId === draft.id;

	const sectionClass = 'neural-forms-entry-detail__ai-carousel'
		+ ( generating ? ' is-loading' : '' )
		+ ( isFresh && ! generating ? ' is-just-generated' : '' );

	return (
		<section className={ sectionClass }>
			{ generating && (
				<div className="neural-forms-entry-detail__ai-loading-overlay neural-forms-entry-detail__ai-loading-overlay--mint" role="status" aria-live="polite">
					<span className="neural-forms-entry-detail__ai-loading-spinner" aria-hidden="true"></span>
					<span className="neural-forms-entry-detail__ai-loading-label">
						{ generatingLabel || __( 'Generating…', 'neural-forms-lite' ) }
					</span>
				</div>
			) }
			<header className="neural-forms-entry-detail__ai-carousel-header">
				<h4 className="neural-forms-entry-detail__ai-carousel-kind">{ __( 'Neural AI drafts', 'neural-forms-lite' ) }</h4>
				<div className="neural-forms-entry-detail__ai-carousel-nav">
					<button
						type="button"
						className="neural-forms-entries-btn"
						onClick={ () => setIndex( ( i ) => Math.max( 0, i - 1 ) ) }
						disabled={ safeIndex === 0 }
						aria-label={ __( 'Previous draft', 'neural-forms-lite' ) }
					>‹</button>
					<span className="neural-forms-entry-detail__ai-carousel-count">
						{ safeIndex + 1 } / { list.length }
					</span>
					<button
						type="button"
						className="neural-forms-entries-btn"
						onClick={ () => setIndex( ( i ) => Math.min( list.length - 1, i + 1 ) ) }
						disabled={ safeIndex === list.length - 1 }
						aria-label={ __( 'Next draft', 'neural-forms-lite' ) }
					>›</button>
				</div>
			</header>

			<div className="neural-forms-entry-detail__ai-carousel-meta">
				<span className="neural-forms-entries-pill neural-forms-entries-pill--neutral">
					{ __( KIND_LABEL[ draft.kind ] || 'Draft', 'neural-forms-lite' ) }
				</span>
				{ draft.tier_used && (
					<span className="neural-forms-entries-pill neural-forms-entries-pill--tier">{ draft.tier_used }</span>
				) }
				{ isSelected && (
					<span className="neural-forms-entries-pill neural-forms-entries-pill--read">{ __( 'In use', 'neural-forms-lite' ) }</span>
				) }
				<span className="neural-forms-entry-detail__ai-carousel-time">{ formatDate( draft.created_at ) }</span>
			</div>

			{ ( draft.summary || draft.category || draft.priority ) && (
				<dl className="neural-forms-entry-detail__ai-carousel-summary">
					{ draft.summary && (
						<div className="neural-forms-entry-detail__field-row neural-forms-entry-detail__field-row--full">
							<dt>{ __( 'Summary', 'neural-forms-lite' ) }</dt>
							<dd>{ draft.summary }</dd>
						</div>
					) }
					{ draft.category && (
						<div className="neural-forms-entry-detail__field-row">
							<dt>{ __( 'Category', 'neural-forms-lite' ) }</dt>
							<dd>{ draft.category }</dd>
						</div>
					) }
					{ draft.priority && (
						<div className="neural-forms-entry-detail__field-row">
							<dt>{ __( 'Priority', 'neural-forms-lite' ) }</dt>
							<dd>{ draft.priority }</dd>
						</div>
					) }
				</dl>
			) }

			{ Array.isArray( draft.meta?.missing_info ) && draft.meta.missing_info.length > 0 && (
				<div className="neural-forms-entry-detail__ai-needs-info" role="note">
					<strong>{ __( 'Needs business information:', 'neural-forms-lite' ) }</strong>
					{ ' ' }{ draft.meta.missing_info.join( ' · ' ) }
					{ ' ' }<span>{ __( 'Fill the placeholders before sending, or add these facts under Settings → Business context.', 'neural-forms-lite' ) }</span>
				</div>
			) }

			<pre className="neural-forms-entry-detail__ai-carousel-body">{ bodyForKind( draft ) }</pre>

			{ Array.isArray( draft.meta?.knowledge_refs ) && draft.meta.knowledge_refs.length > 0 && (
				<p className="neural-forms-entry-detail__ai-sources">
					<span>{ __( 'Sources used:', 'neural-forms-lite' ) }</span>
					{ draft.meta.knowledge_refs.map( ( ref ) => (
						<code key={ ref }>{ ref }</code>
					) ) }
					{ draft.meta.knowledge_revision ? <em>{ sprintf( __( 'knowledge rev. %d', 'neural-forms-lite' ), draft.meta.knowledge_revision ) }</em> : null }
				</p>
			) }

			<footer className="neural-forms-entry-detail__ai-carousel-footer">
				<button
					type="button"
					className="neural-forms-entries-btn neural-forms-entries-btn--primary"
					onClick={ () => onFinalize( draft.id ) }
					disabled={ busy === draft.id }
				>
					{ busy === draft.id
						? __( 'Applying...', 'neural-forms-lite' )
						: __( 'Use this draft', 'neural-forms-lite' )
					}
				</button>
				{ onTranslate && siteLanguage && draft.meta?.reply_language && draft.meta.reply_language.split( '-' )[ 0 ].toLowerCase() !== siteLanguage.split( '-' )[ 0 ].toLowerCase() && (
					<button
						type="button"
						className="neural-forms-entries-btn"
						onClick={ () => onTranslate( draft, siteLanguage ) }
						disabled={ busy === 'translate' }
						title={ __( 'Creates a translated copy as a new draft (uses one polish credit)', 'neural-forms-lite' ) }
					>
						{ busy === 'translate'
							? __( 'Translating…', 'neural-forms-lite' )
							: sprintf( __( 'Translate to %s', 'neural-forms-lite' ), siteLanguage ) }
					</button>
				) }
				{ draft.meta?.reply_language && (
					<span className="neural-forms-entries-pill neural-forms-entries-pill--neutral">{ draft.meta.reply_language }</span>
				) }
			</footer>
		</section>
	);
}
