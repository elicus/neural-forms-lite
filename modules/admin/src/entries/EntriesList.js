/**
 * Tabular entries list with checkbox column for bulk selection.
 *
 * The list is dumb — it just paints rows and emits selection /
 * open / star events. Filtering, sorting, and the actual fetched
 * data live in EntriesScreen.
 *
 * Quiz/Poll smart-folder rows substitute the submitter cell with a
 * stable "Quiz attempt #ID" / "Poll response #ID" label. Quiz rows
 * also blank the preview cell because raw chosen-option ids without
 * question context are noise; poll rows keep the preview.
 *
 * Props:
 *   entries         array of entry rows (preview-shaped)
 *   selection       Set<number> of selected ids
 *   onToggleSelect  (id: number, checked: bool) => void
 *   onSelectAll     (checked: bool) => void
 *   onOpen          (id: number) => void
 *   onToggleStar    (entry) => void
 *   openId          number — currently expanded entry
 *   sidebarKind     'view' | 'smart' | 'filter' | 'form'
 *   sidebarId       string — id of the active selection (e.g. 'quizzes')
 */

import { __ } from '@wordpress/i18n';
import { formatDate, previewSummary, pickSubmitterEmail } from './helpers';

function StatusPill( { entry } ) {
	if ( entry.status === 'spam' )  return <span className="nf-pill nf-pill--spam">{ __( 'Spam', 'neural-forms-lite' ) }</span>;
	if ( entry.status === 'trash' ) return <span className="nf-pill nf-pill--trash">{ __( 'Trash', 'neural-forms-lite' ) }</span>;
	if ( entry.status === 'new'   ) return <span className="nf-pill nf-pill--new">{ __( 'Unread', 'neural-forms-lite' ) }</span>;
	return <span className="nf-pill nf-pill--read">{ __( 'Read', 'neural-forms-lite' ) }</span>;
}

function PriorityPill( { p } ) {
	if ( p === 'high' )   return <span className="nf-pill nf-pill--high">{ __( 'High', 'neural-forms-lite' ) }</span>;
	if ( p === 'low'  )   return <span className="nf-pill nf-pill--low">{ __( 'Low', 'neural-forms-lite' ) }</span>;
	return null;
}

function StarButton( { starred, onClick } ) {
	return (
		<button
			type="button"
			className={ 'nf-inbox__star' + ( starred ? ' is-starred' : '' ) }
			onClick={ ( e ) => { e.stopPropagation(); onClick(); } }
			aria-label={ starred ? __( 'Unstar', 'neural-forms-lite' ) : __( 'Star', 'neural-forms-lite' ) }
			title={ starred ? __( 'Unstar', 'neural-forms-lite' ) : __( 'Star', 'neural-forms-lite' ) }
		>
			{ starred ? '★' : '☆' }
		</button>
	);
}

export default function EntriesList( {
	entries,
	selection,
	onToggleSelect,
	onSelectAll,
	onOpen,
	onToggleStar,
	openId,
	sidebarKind,
	sidebarId,
} ) {
	if ( ! entries || entries.length === 0 ) return null;

	const allSelected = selection.size > 0 && entries.every( ( e ) => selection.has( e.id ) );
	const someSelected = selection.size > 0 && ! allSelected;

	const isQuizView = sidebarKind === 'smart' && sidebarId === 'quizzes';
	const isPollView = sidebarKind === 'smart' && sidebarId === 'polls';

	return (
		<table className="nf-inbox__table">
			<thead>
				<tr>
					<th className="nf-inbox__col-check">
						<input
							type="checkbox"
							aria-label={ __( 'Select all', 'neural-forms-lite' ) }
							checked={ allSelected }
							ref={ ( el ) => { if ( el ) el.indeterminate = someSelected; } }
							onChange={ ( e ) => onSelectAll( e.target.checked ) }
						/>
					</th>
					<th className="nf-inbox__col-star" aria-label={ __( 'Starred', 'neural-forms-lite' ) }>★</th>
					<th>{ __( 'Form', 'neural-forms-lite' ) }</th>
					<th>{ __( 'Submitter', 'neural-forms-lite' ) }</th>
					<th>{ __( 'Preview', 'neural-forms-lite' ) }</th>
					<th>{ __( 'Status', 'neural-forms-lite' ) }</th>
					<th>{ __( 'Priority', 'neural-forms-lite' ) }</th>
					<th>{ __( 'Date', 'neural-forms-lite' ) }</th>
				</tr>
			</thead>
			<tbody>
				{ entries.map( ( e ) => {
					let submitter, previewCell;
					if ( isQuizView ) {
						submitter   = `${ __( 'Quiz attempt', 'neural-forms-lite' ) } #${ e.id }`;
						previewCell = '—';
					} else if ( isPollView ) {
						submitter   = `${ __( 'Poll response', 'neural-forms-lite' ) } #${ e.id }`;
						previewCell = previewSummary( e.preview );
					} else {
						submitter   = pickSubmitterEmail( e.preview, null ) || '-';
						previewCell = previewSummary( e.preview );
					}
					return (
						<tr
							key={ e.id }
							className={
								'nf-inbox__row'
								+ ( e.status === 'new' ? ' is-unread' : '' )
								+ ( openId === e.id ? ' is-open' : '' )
								+ ( selection.has( e.id ) ? ' is-selected' : '' )
							}
							onClick={ () => onOpen( e.id ) }
						>
							<td className="nf-inbox__col-check" onClick={ ( ev ) => ev.stopPropagation() }>
								<input
									type="checkbox"
									checked={ selection.has( e.id ) }
									onChange={ ( ev ) => onToggleSelect( e.id, ev.target.checked ) }
									aria-label={ __( 'Select entry', 'neural-forms-lite' ) }
								/>
							</td>
							<td className="nf-inbox__col-star">
								<StarButton starred={ e.is_starred === 1 || e.is_starred === true } onClick={ () => onToggleStar( e ) } />
							</td>
							<td className="nf-inbox__row-form">{ e.form_name || `#${ e.form_id }` }</td>
							<td className="nf-inbox__row-submitter">{ submitter }</td>
							<td className="nf-inbox__row-preview">{ previewCell }</td>
							<td><StatusPill entry={ e } /></td>
							<td><PriorityPill p={ e.ai_priority } /></td>
							<td className="nf-inbox__row-date">{ formatDate( e.submitted_at || e.created_at ) }</td>
						</tr>
					);
				} ) }
			</tbody>
		</table>
	);
}
