/**
 * Top-level Results screen container.
 *
 * Future-proof structure:
 *   <ResultsApp>
 *     <ResultsHeader>            chrome: title + form selector + refresh
 *     <ResultsOverviewCards>     overview KPIs (entries / votes / polls)
 *     <PollResultsSection>       the only section in v1
 *     <SurveyResultsSection>     <- future
 *     <QuizResultsSection>       <- future
 *     <DropoffSection>           <- future
 *
 * Adding a section later means adding one component and one render
 * call — no routing change, no fetch refactor.
 *
 * Data model is single-form scoped: the form selector picks which
 * form's analytics we show. URL ?form_id=123 auto-selects on mount.
 */

import { useEffect, useState, useCallback, useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../api';
import ResultsHeader from './ResultsHeader';
import EmptyState from './EmptyState';
import ResultsOverviewCards from './ResultsOverviewCards';
import PollResultsSection from './PollResultsSection';
import QuizResultsSection from './QuizResultsSection';

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

	// Forms that should appear in the dropdown: those with polls OR
	// quiz questions. Sorted by name for stable presentation.
	const resultsForms = useMemo(
		() => forms.filter( ( f ) =>
			Number( f.poll_count ) > 0 || !! f.has_polls
			|| Number( f.quiz_count ) > 0 || !! f.has_quiz
		).sort( ( a, b ) => String( a.name || '' ).localeCompare( String( b.name || '' ) ) ),
		[ forms ]
	);

	// Combined refresh: re-fetches both forms list AND (if a form is
	// selected) its poll + quiz results. Lets the user recover from a
	// bad state without reloading the page.
	const refreshAll = useCallback( () => {
		fetchForms().then( () => { if ( selectedId > 0 ) load(); } );
	}, [ fetchForms, load, selectedId ] );

	// Deep-link fallback: when ?form_id=N points at a form that's not
	// in the filtered resultsForms list (could be archived, or its
	// poll/quiz_count detection failed), still attempt to load
	// the endpoints directly. The server will return a 404 if the
	// form genuinely doesn't exist, which surfaces as the error
	// EmptyState below.
	const deepLinkOrphan = selectedId > 0 && ! formsLoading && ! resultsForms.find( ( f ) => Number( f.id ) === Number( selectedId ) );

	const hasPolls = !! ( data && Number( data.poll_count ) > 0 );
	const hasQuiz  = !! ( quizData && Number( quizData.quiz_count ) > 0 );

	return (
		<div className="nf-results">
			<ResultsHeader
				forms={ resultsForms }
				formsLoading={ formsLoading }
				formsError={ formsError }
				selectedId={ selectedId }
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

			{ /* No form selected, no error, dropdown empty. */ }
			{ ! formsError && selectedId === 0 && ! formsLoading && resultsForms.length === 0 && (
				<EmptyState
					title={ __( 'No results yet', 'neural-forms-lite' ) }
					subtitle={ __( 'Create a form with a Poll or Quiz Question field to start collecting results.', 'neural-forms-lite' ) }
				/>
			) }

			{ /* No form selected but there ARE poll/quiz forms. */ }
			{ ! formsError && selectedId === 0 && ! formsLoading && resultsForms.length > 0 && (
				<EmptyState
					title={ __( 'Select a form to view results.', 'neural-forms-lite' ) }
				/>
			) }

			{ selectedId > 0 && error && (
				<EmptyState
					title={ __( 'Could not load results.', 'neural-forms-lite' ) }
					subtitle={ error }
					tone="error"
				/>
			) }

			{ selectedId > 0 && ! error && loading && ! data && ! quizData && (
				<EmptyState title={ __( 'Loading results…', 'neural-forms-lite' ) } />
			) }

			{ selectedId > 0 && ! error && ( data || quizData ) && (
				<>
					<h2 className="nf-results__form-name">
						{ ( ( data && data.form_name ) || ( quizData && quizData.form_name ) || ( selectedForm && selectedForm.name ) || '' ) }
					</h2>

					<ResultsOverviewCards data={ data } quiz={ quizData } />

					{ hasPolls && (
						<PollResultsSection polls={ ( data && data.polls ) || [] } />
					) }

					{ hasQuiz && (
						<QuizResultsSection quiz={ quizData } />
					) }

					{ ! hasPolls && ! hasQuiz && (
						<EmptyState
							title={ __( 'This form has no poll or quiz fields yet.', 'neural-forms-lite' ) }
							subtitle={ __( 'Add a Poll or Quiz Question field in the form builder to see results here.', 'neural-forms-lite' ) }
						/>
					) }

					{ quizError && (
						<EmptyState
							title={ __( 'Quiz analytics could not be loaded.', 'neural-forms-lite' ) }
							subtitle={ quizError }
							tone="error"
						/>
					) }

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
