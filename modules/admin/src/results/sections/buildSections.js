/**
 * Assembles the ordered list of analytics-section descriptors for a
 * given form. Adding a new section means adding ONE entry here —
 * no routing change, no fetch refactor, no ResultsApp edit.
 *
 * Descriptor contract (see AnalyticsSection.js for the full doc):
 *   { id, title, description, isAvailable, isEmpty, data, render,
 *     emptyTitle, emptyMessage, chrome }
 *
 * Why this lives in its own file:
 *   - Keeps ResultsApp.js focused on data loading + page shell
 *   - Single place to add Demographics / Source / AI sections later
 *   - Easy to unit-test in isolation if we ever add JS test coverage
 *
 * Backend response shape is NOT changed in this refactor — the
 * existing /forms/{id}/polls and /forms/{id}/quiz endpoints are
 * still consumed verbatim. We normalise into a small in-memory
 * `analytics` object (form / overview / submissions / polls / quiz /
 * future) so the section assembler reads from one place. If a future
 * release adds a unified GET /forms/{id}/analytics endpoint, the
 * server-side payload can match this shape and the assembler stays
 * unchanged.
 */

import { __ } from '@wordpress/i18n';
import OverviewCards from '../ResultsOverviewCards';
import ResultsInsights from '../ResultsInsights';
import SubmissionAnalyticsSection from './SubmissionAnalyticsSection';
import PollAnalyticsSection from './PollAnalyticsSection';
import QuizAnalyticsSection from './QuizAnalyticsSection';
import PagePerformanceSection from './PagePerformanceSection';
import FutureInsightsSection from './FutureInsightsSection';

/**
 * Normalise raw endpoint payloads + the resolved form record into
 * the section-friendly shape used below. Intentionally additive:
 * unknown keys flow through untouched so individual sections can
 * read raw payload fields (e.g. polls[].options) without us listing
 * every field here.
 */
export function normaliseAnalytics( { selectedForm, selectedId, data, quizData } ) {
	const totalEntries = Number( ( data && data.total_entries ) ?? ( quizData && quizData.total_entries ) ?? 0 ) || 0;
	const spamCount    = Number( selectedForm && selectedForm.spam_count ) || 0;

	const hasPolls = !! ( data     && Number( data.poll_count )     > 0 );
	const hasQuiz  = !! ( quizData && Number( quizData.quiz_count ) > 0 );

	return {
		form: {
			id:            Number( selectedId ) || 0,
			name:          ( data && data.form_name ) || ( quizData && quizData.form_name ) || ( selectedForm && selectedForm.name ) || '',
			status:        selectedForm ? String( selectedForm.status || '' ) : '',
			detected_type: selectedForm ? String( selectedForm.detected_type || 'simple' ) : 'simple',
		},
		overview: {
			total_entries:    totalEntries,
			spam_count:       spamCount,
			poll_count:       Number( data && data.poll_count ) || 0,
			quiz_count:       Number( quizData && quizData.quiz_count ) || 0,
			total_poll_votes: Number( data && data.total_poll_votes ) || 0,
		},
		submissions: {
			total:  totalEntries,
			spam:   spamCount,
			status: selectedForm ? String( selectedForm.status || '' ) : '',
		},
		polls: {
			available: hasPolls,
			polls:     ( data && Array.isArray( data.polls ) ) ? data.polls : [],
		},
		quiz: {
			available: hasQuiz,
			payload:   quizData || null,
		},
		// Future sections (demographics, source, ai-insights, conversions)
		// will populate this slot once the corresponding endpoints land.
		// See FutureInsightsSection.js for the privacy / contract notes.
		future: {},
	};
}

/**
 * Build the ordered descriptor list. Order here IS the rendered order.
 *
 * @param {object} ctx { selectedId, selectedForm, data, quizData, quizError }
 * @return {Array<object>} descriptors
 */
export default function buildSections( ctx ) {
	const a = normaliseAnalytics( ctx );

	const descriptors = [];

	// 1. Overview — unified KPI grid. Owns Total entries, Spam (when
	//    > 0), Status (simple forms), poll/quiz facts. The single
	//    place where overview cards live so the page never duplicates
	//    a Total entries / Total submissions card.
	descriptors.push( {
		id:          'overview',
		title:       null,
		isAvailable: true,
		isEmpty:     false,
		chrome:      false,
		data:        { data: ctx.data, quiz: ctx.quizData, selectedForm: ctx.selectedForm },
		render:      ( d ) => <OverviewCards data={ d.data } quiz={ d.quiz } selectedForm={ d.selectedForm } />,
	} );

	// 2. Submission Analytics — for simple forms only, this is the
	//    "View entries" CTA card. The KPI cards moved up to Overview.
	descriptors.push( {
		id:          'submission',
		title:       __( 'Submission analytics', 'neural-forms-lite' ),
		description: __( 'Open the inbox to act on these submissions.', 'neural-forms-lite' ),
		isAvailable: ! a.polls.available && ! a.quiz.available,
		isEmpty:     false,
		chrome:      true,
		data: {
			totalEntries: a.submissions.total,
			formId:       a.form.id,
		},
		render: ( d ) => <SubmissionAnalyticsSection { ...d } />,
	} );

	// 3. Poll Analytics — only when the form has poll fields.
	descriptors.push( {
		id:          'polls',
		isAvailable: a.polls.available,
		isEmpty:     a.polls.polls.length === 0,
		chrome:      false, // PollResultsSection brings its own chrome
		data:        { polls: a.polls.polls },
		render:      ( d ) => <PollAnalyticsSection polls={ d.polls } />,
	} );

	// 4. Quiz Insights — extracted from the quiz payload so the page
	//    can render Page Performance between the Insights summary and
	//    the heavier quiz body (per-question results + attempts).
	const quizInsights = ( a.quiz.payload && Array.isArray( a.quiz.payload.insights ) ) ? a.quiz.payload.insights : [];
	descriptors.push( {
		id:          'quiz-insights',
		isAvailable: a.quiz.available && quizInsights.length > 0,
		isEmpty:     false,
		chrome:      false, // ResultsInsights renders its own card chrome.
		data:        { insights: quizInsights },
		render:      ( d ) => <ResultsInsights insights={ d.insights } />,
	} );

	// 5. Page Performance — Pro analytics. Slots between Insights and
	//    the quiz body so the operator sees "where these submissions
	//    came from" before diving into per-question scoring. Always
	//    available so the Lite locked card can render an upsell; the
	//    section component decides between locked / empty / data
	//    states internally based on capability + fetched payload.
	descriptors.push( {
		id:          'page-performance',
		isAvailable: a.form.id > 0,
		isEmpty:     false,
		chrome:      false, // Component renders its own card chrome.
		data:        { formId: a.form.id },
		render:      ( d ) => <PagePerformanceSection formId={ d.formId } />,
	} );

	// 6. Quiz Analytics — per-question results + attempts table.
	//    Insights moved out (see #4) so Page Performance can sit
	//    between them.
	descriptors.push( {
		id:          'quiz',
		isAvailable: a.quiz.available,
		isEmpty:     ! a.quiz.payload,
		chrome:      false, // QuizAnalyticsSection brings its own chrome
		data:        { quiz: a.quiz.payload, formId: a.form.id },
		render:      ( d ) => <QuizAnalyticsSection quiz={ d.quiz } formId={ d.formId } />,
	} );

	// 7. Quiz error banner — only when the quiz endpoint failed but
	//    polls succeeded. Surfaces inline so the user knows half the
	//    page is missing without losing the half that loaded.
	if ( ctx.quizError ) {
		descriptors.push( {
			id:          'quiz-error',
			title:       __( 'Quiz analytics could not be loaded.', 'neural-forms-lite' ),
			description: ctx.quizError,
			isAvailable: true,
			isEmpty:     true, // renders as EmptyAnalyticsState
			chrome:      true,
			emptyTitle:  __( 'Quiz analytics could not be loaded.', 'neural-forms-lite' ),
			emptyMessage: ctx.quizError,
		} );
	}

	// 7. AI Insights / Future Insights — Stats-style insight-rows
	//    card. Renders only when there is real data above it so it
	//    reads as forward-looking rather than unfinished.
	descriptors.push( {
		id:          'future',
		title:       __( 'Neural AI Insights', 'neural-forms-lite' ),
		description: __( 'Coming in a future release.', 'neural-forms-lite' ),
		isAvailable: a.overview.total_entries > 0,
		isEmpty:     false,
		chrome:      true,
		data:        {},
		render:      () => <FutureInsightsSection />,
	} );

	return descriptors;
}
