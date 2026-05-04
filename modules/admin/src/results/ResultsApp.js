/**
 * Top-level Analytics screen container.
 *
 * Section-based architecture (v1.1.0+):
 *
 *   <ResultsApp>
 *     <ResultsHeader>     chrome: title + form selector + refresh
 *     <AnalyticsSection>* one wrapper per descriptor returned by
 *                         buildSections() — assembled top-down so
 *                         "add a new section" = "add one entry to
 *                         buildSections.js".
 *
 * Today's descriptor list:
 *   1. Overview                 — KPI cards
 *   2. Submission Analytics     — baseline (when no poll/quiz)
 *   3. Poll Analytics           — when form has poll fields
 *   4. Quiz Analytics           — when form has quiz fields
 *      (bundles ResultsInsights + per-question + attempts table)
 *   5. Future Insights          — subtle placeholder for AI/demographics
 *
 * Future descriptors plug in here without touching this file:
 *   - Demographics (opt-in, see FutureInsightsSection.js for privacy)
 *   - Device / source analytics
 *   - Survey analytics
 *   - AI summaries (manual trigger; see endpoint TODO in
 *     FutureInsightsSection.js)
 *   - Conversion tracking
 *   - Spam / security trends
 *
 * Data model is single-form scoped: the form selector picks which
 * form's analytics we show. URL ?form_id=123 auto-selects on mount.
 *
 * Backend endpoints are UNCHANGED in this refactor — frontend only:
 * we still call /forms/{id}/polls and /forms/{id}/quiz and normalise
 * into a section-friendly shape inside buildSections.js. If a
 * future release ships a unified GET /forms/{id}/analytics, the
 * server payload can match that shape and the assembler stays put.
 */

import { useEffect, useState, useCallback, useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../api';
import ResultsHeader from './ResultsHeader';
import EmptyState from './EmptyState';
import AnalyticsSection from './sections/AnalyticsSection';
import buildSections from './sections/buildSections';

function readFormIdFromUrl() {
	try {
		const u = new URL( window.location.href );
		const v = u.searchParams.get( 'form_id' );
		const n = Number( v );
		return Number.isFinite( n ) && n > 0 ? n : 0;
	} catch ( e ) {
		return 0;
	}
}

/**
 * Count fields of a given `type` in a form_schema, recursing into
 * rows / columns / steps / step.row.column. Mirrors
 * SchemaHelper::flatten_fields on the server. Used only as a
 * fallback when GET /forms doesn't carry the server-computed
 * poll_count / quiz_count (older Lite/Pro mix, malformed enrichment).
 */
function countTypeInSchema( schema, type ) {
	if ( ! schema || typeof schema !== 'object' ) return 0;
	const fields = Array.isArray( schema.fields ) ? schema.fields : [];
	let n = 0;
	const walk = ( nodes ) => {
		for ( let i = 0; i < nodes.length; i++ ) {
			const node = nodes[ i ];
			if ( ! node || typeof node !== 'object' ) continue;
			if ( node.type === type ) { n++; continue; }
			if ( node.type === 'step'   && Array.isArray( node.fields ) )  walk( node.fields );
			if ( node.type === 'row'    && Array.isArray( node.columns ) ) {
				for ( let c = 0; c < node.columns.length; c++ ) {
					const col = node.columns[ c ];
					if ( col && Array.isArray( col.fields ) ) walk( col.fields );
				}
			}
		}
	};
	walk( fields );
	return n;
}

export default function ResultsApp() {
	const [ forms,        setForms        ] = useState( [] );
	const [ formsLoading, setFormsLoading ] = useState( true );
	const [ formsError,   setFormsError   ] = useState( '' );

	const [ selectedId,  setSelectedId   ] = useState( readFormIdFromUrl() );

	const [ data,    setData    ] = useState( null );
	const [ loading, setLoading ] = useState( false );
	const [ error,   setError   ] = useState( '' );

	// Forms list — populates the selector and tells us which forms
	// have polls (poll_count + has_polls were added to GET /forms in
	// 1.1.0). Per-page is kept generous so the dropdown isn't paged.
	//
	// Response shape is { items, total } per FormRepository::list().
	// Older drafts used { forms, total }; we accept either to stay
	// resilient to future normalisation. We also fall back to
	// computing poll_count client-side from form_schema in case the
	// server-side enrichment is unavailable (older Pro/Lite mix).
	const fetchForms = useCallback( () => {
		setFormsLoading( true );
		setFormsError( '' );
		return api( '/forms?per_page=100&status=active,draft' )
			.then( ( res ) => {
				const raw = ( res && Array.isArray( res.items ) )
					? res.items
					: ( res && Array.isArray( res.forms ) )
						? res.forms
						: ( Array.isArray( res ) ? res : [] );
				const list = raw.map( ( f ) => {
					const havePoll = typeof f.poll_count === 'number';
					const haveQuiz = typeof f.quiz_count === 'number';
					const polls = havePoll ? f.poll_count : countTypeInSchema( f.form_schema, 'poll' );
					const quiz  = haveQuiz ? f.quiz_count : countTypeInSchema( f.form_schema, 'quiz_question' );
					return {
						...f,
						poll_count: polls,
						has_polls:  polls > 0 || !! f.has_polls,
						quiz_count: quiz,
						has_quiz:   quiz > 0 || !! f.has_quiz,
					};
				} );
				setForms( list );
			} )
			.catch( ( err ) => {
				setFormsError( ( err && err.message ) ? String( err.message ) : __( 'Failed to load forms.', 'neural-forms-lite' ) );
			} )
			.finally( () => { setFormsLoading( false ); } );
	}, [] );

	useEffect( () => { fetchForms(); }, [ fetchForms ] );

	// Per-selection state for quiz results — fetched alongside polls
	// when a form is picked. Either or both may be empty depending on
	// the form's schema.
	const [ quizData, setQuizData ] = useState( null );
	const [ quizError, setQuizError ] = useState( '' );

	// Whenever the user picks a form, fetch its results. Polls and
	// quiz are fetched in parallel; the UI renders whichever is
	// available without blocking the other.
	const load = useCallback( () => {
		if ( ! selectedId ) {
			setData( null );
			setQuizData( null );
			return;
		}
		setLoading( true );
		setError( '' );
		setQuizError( '' );

		const pollsP = api( `/forms/${ selectedId }/polls` )
			.then( ( res ) => { setData( res ); } )
			.catch( ( err ) => {
				setError( ( err && err.message ) ? String( err.message ) : __( 'Failed to load results.', 'neural-forms-lite' ) );
				setData( null );
			} );
		const quizP = api( `/forms/${ selectedId }/quiz` )
			.then( ( res ) => { setQuizData( res ); } )
			.catch( ( err ) => {
				setQuizError( ( err && err.message ) ? String( err.message ) : __( 'Failed to load quiz results.', 'neural-forms-lite' ) );
				setQuizData( null );
			} );

		Promise.all( [ pollsP, quizP ] ).finally( () => { setLoading( false ); } );
	}, [ selectedId ] );

	useEffect( () => { load(); }, [ load ] );

	// Keep the URL in sync with the selection so refresh / share-link
	// re-opens to the same view. We replace, not push, so the back
	// button doesn't accumulate selector changes.
	useEffect( () => {
		try {
			const u = new URL( window.location.href );
			if ( selectedId > 0 ) {
				u.searchParams.set( 'form_id', String( selectedId ) );
			} else {
				u.searchParams.delete( 'form_id' );
			}
			window.history.replaceState( null, '', u.toString() );
		} catch ( e ) { /* noop */ }
	}, [ selectedId ] );

	const selectedForm = useMemo(
		() => forms.find( ( f ) => Number( f.id ) === Number( selectedId ) ) || null,
		[ forms, selectedId ]
	);

	// Selector lists ACTIVE forms only — drafts/archived would just
	// confuse a user looking at analytics for live data. Simple/poll/
	// quiz/survey/mixed all show up here as long as their status is
	// 'active'. Sorted by name for stable presentation.
	const resultsForms = useMemo(
		() => forms
			.filter( ( f ) => String( f.status || '' ).toLowerCase() === 'active' )
			.sort( ( a, b ) => String( a.name || '' ).localeCompare( String( b.name || '' ) ) ),
		[ forms ]
	);

	// Deep-link inactive: ?form_id=N points at an existing form whose
	// status is draft/archived. We surface a clean message instead of
	// loading analytics for a form the user can't actually publish.
	const deepLinkInactive = !! (
		selectedId > 0
		&& selectedForm
		&& String( selectedForm.status || '' ).toLowerCase() !== 'active'
	);

	// Combined refresh: re-fetches both forms list AND (if a form is
	// selected) its poll + quiz results. Lets the user recover from a
	// bad state without reloading the page.
	const refreshAll = useCallback( () => {
		fetchForms().then( () => { if ( selectedId > 0 ) load(); } );
	}, [ fetchForms, load, selectedId ] );

	// Deep-link fallback: when ?form_id=N points at a form that's not
	// in the filtered resultsForms list AND is not flagged as
	// inactive (selectedForm is null because the id doesn't resolve
	// at all — could be a hard-deleted form, or the forms-list page
	// hasn't fetched it yet). Still attempt to load the endpoints
	// directly. The server returns 404 if the form genuinely doesn't
	// exist, which surfaces as the error EmptyState below.
	const deepLinkOrphan = selectedId > 0
		&& ! formsLoading
		&& ! deepLinkInactive
		&& ! resultsForms.find( ( f ) => Number( f.id ) === Number( selectedId ) );

	// Assemble the descriptor list once per data refresh. The render
	// loop below filters on isAvailable so unavailable sections cost
	// nothing structurally.
	const sections = useMemo(
		() => buildSections( {
			selectedId,
			selectedForm,
			data,
			quizData,
			quizError,
		} ),
		[ selectedId, selectedForm, data, quizData, quizError ]
	);

	return (
		<div className="nf-results">
			<ResultsHeader
				forms={ resultsForms }
				formsLoading={ formsLoading }
				formsError={ formsError }
				selectedId={ selectedId }
				selectedFormName={
					( data && data.form_name )
						|| ( quizData && quizData.form_name )
						|| ( selectedForm && selectedForm.name )
						|| ''
				}
				onSelect={ setSelectedId }
				onRefresh={ refreshAll }
				refreshing={ loading || formsLoading }
				canRefresh={ true }
			/>

			{ /* Forms-list error. */ }
			{ formsError && (
				<EmptyState
					title={ __( 'Could not load forms.', 'neural-forms-lite' ) }
					subtitle={ formsError }
					tone="error"
				/>
			) }

			{ /* Zero active forms — nothing to pick. */ }
			{ ! formsError && selectedId === 0 && ! formsLoading && resultsForms.length === 0 && (
				<EmptyState
					title={ __( 'No active forms found.', 'neural-forms-lite' ) }
					subtitle={ __( 'Activate a form to view analytics.', 'neural-forms-lite' ) }
				/>
			) }

			{ /* Active forms exist, none selected yet. */ }
			{ ! formsError && selectedId === 0 && ! formsLoading && resultsForms.length > 0 && (
				<EmptyState
					title={ __( 'Select a form to view analytics.', 'neural-forms-lite' ) }
				/>
			) }

			{ /* Deep-link points at a draft/archived form — clean message,
			     no analytics fetch. Takes precedence over the load/error
			     branches because we don't want to surface a stale spinner
			     for a form the user shouldn't be analysing yet. */ }
			{ ! formsError && deepLinkInactive && (
				<EmptyState
					title={ __( 'This form is not active.', 'neural-forms-lite' ) }
					subtitle={ __( 'Activate it to view analytics.', 'neural-forms-lite' ) }
				/>
			) }

			{ selectedId > 0 && ! deepLinkInactive && error && (
				<EmptyState
					title={ __( 'Could not load analytics.', 'neural-forms-lite' ) }
					subtitle={ error }
					tone="error"
				/>
			) }

			{ selectedId > 0 && ! deepLinkInactive && ! error && loading && ! data && ! quizData && (
				<EmptyState title={ __( 'Loading analytics…', 'neural-forms-lite' ) } />
			) }

			{ selectedId > 0 && ! deepLinkInactive && ! error && ( data || quizData ) && (
				<>
					{ sections.map( ( s ) => (
						<AnalyticsSection key={ s.id } { ...s } />
					) ) }

					{ deepLinkOrphan && (
						<p className="nf-results__deep-link-note">
							{ __( 'Loaded directly from the form_id URL.', 'neural-forms-lite' ) }
						</p>
					) }
				</>
			) }
		</div>
	);
}
