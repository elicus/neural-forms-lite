/**
 * Tabular entries list with checkbox column for bulk selection.
 *
 * The list is dumb — it just paints rows and emits selection /
 * open / star events. Filtering, sorting, and the actual fetched
 * data live in EntriesScreen.
 *
 * Props:
 *   entries         array of entry rows (preview-shaped)
 *   selection       Set<number> of selected ids
 *   onToggleSelect  (id: number, checked: bool) => void
 *   onSelectAll     (checked: bool) => void
 *   onOpen          (id: number) => void
 *   onToggleStar    (entry) => void
 *   openId          number — currently expanded entry
 *   focusedId       number|null — row the keyboard shortcuts act on (j/k, r/u/s/#/!)
 */

import { __ } from '@wordpress/i18n';
import { formatDate, previewSummary, pickSubmitterEmail } from './helpers';

function StatusPill( { entry } ) {
	if ( entry.status === 'spam' )  return <span className="neural-forms-entries-pill neural-forms-entries-pill--spam">{ __( 'Spam', 'neural-forms-lite' ) }</span>;
	if ( entry.status === 'trash' ) return <span className="neural-forms-entries-pill neural-forms-entries-pill--trash">{ __( 'Trash', 'neural-forms-lite' ) }</span>;
	if ( entry.status === 'new'   ) return <span className="neural-forms-entries-pill neural-forms-entries-pill--new">{ __( 'Unread', 'neural-forms-lite' ) }</span>;
	// `has_reply` is populated by EntryRepository::list() — true when at
	// least one outgoing message has been sent for this entry. Once a
	// reply has gone out, the inbox row reads "Replied" instead of the
	// generic "Read" so users can scan their follow-up state at a glance.
	if ( entry.has_reply ) {
		return <span className="neural-forms-entries-pill neural-forms-entries-pill--replied">{ __( 'Replied', 'neural-forms-lite' ) }</span>;
	}
	return <span className="neural-forms-entries-pill neural-forms-entries-pill--read">{ __( 'Read', 'neural-forms-lite' ) }</span>;
}

/** 1.2.0 part B: aggregate payment status, only when the entry carries a price. */
const PAYMENT_PILL_LABELS = {
	pending: __( 'Awaiting payment', 'neural-forms-lite' ), processing: __( 'Processing', 'neural-forms-lite' ), failed: __( 'Payment failed', 'neural-forms-lite' ),
	cancelled: __( 'Checkout cancelled', 'neural-forms-lite' ), deposit_paid: __( 'Deposit paid', 'neural-forms-lite' ), paid: __( 'Paid', 'neural-forms-lite' ),
	partially_refunded: __( 'Partly refunded', 'neural-forms-lite' ), refunded: __( 'Refunded', 'neural-forms-lite' ),
};
function PaymentPill( { status } ) {
	if ( ! status || ! PAYMENT_PILL_LABELS[ status ] ) return null;
	return <span className={ 'neural-forms-entries-pill neural-forms-entries-pill--pay neural-forms-entries-pill--pay-' + status }>{ PAYMENT_PILL_LABELS[ status ] }</span>;
}

function PriorityPill( { p } ) {
	const v = String( p || '' ).toLowerCase();
	if ( v === 'high'   ) return <span className="neural-forms-entries-pill neural-forms-entries-pill--high">{ __( 'High',   'neural-forms-lite' ) }</span>;
	if ( v === 'medium' ) return <span className="neural-forms-entries-pill neural-forms-entries-pill--medium">{ __( 'Medium', 'neural-forms-lite' ) }</span>;
	if ( v === 'low'    ) return <span className="neural-forms-entries-pill neural-forms-entries-pill--low">{ __( 'Low',    'neural-forms-lite' ) }</span>;
	return null;
}

function StarButton( { starred, onClick } ) {
	return (
		<button
			type="button"
			className={ 'neural-forms-entries-star' + ( starred ? ' is-starred' : '' ) }
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
	focusedId = null,
} ) {
	if ( ! entries || entries.length === 0 ) return null;

	const allSelected = selection.size > 0 && entries.every( ( e ) => selection.has( e.id ) );
	const someSelected = selection.size > 0 && ! allSelected;

	return (
		<table className="neural-forms-entries-table">
			<thead>
				<tr>
					<th className="neural-forms-entries-col-check">
						<input
							type="checkbox"
							aria-label={ __( 'Select all', 'neural-forms-lite' ) }
							checked={ allSelected }
							ref={ ( el ) => { if ( el ) el.indeterminate = someSelected; } }
							onChange={ ( e ) => onSelectAll( e.target.checked ) }
						/>
					</th>
					<th className="neural-forms-entries-col-star" aria-label={ __( 'Starred', 'neural-forms-lite' ) }>★</th>
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
					const submitter   = pickSubmitterEmail( e.preview, null ) || '—';
					const previewCell = previewSummary( e.preview );
					return (
						<tr
							key={ e.id }
							data-entry-id={ e.id }
							className={
								'neural-forms-entries-row'
								+ ( e.status === 'new' ? ' is-unread' : '' )
								+ ( openId === e.id ? ' is-open' : '' )
								+ ( selection.has( e.id ) ? ' is-selected' : '' )
								+ ( focusedId === e.id ? ' is-focused' : '' )
							}
							aria-current={ focusedId === e.id ? 'true' : undefined }
							onClick={ () => onOpen( e.id ) }
						>
							<td className="neural-forms-entries-col-check" onClick={ ( ev ) => ev.stopPropagation() }>
								<input
									type="checkbox"
									checked={ selection.has( e.id ) }
									onChange={ ( ev ) => onToggleSelect( e.id, ev.target.checked ) }
									aria-label={ __( 'Select entry', 'neural-forms-lite' ) }
								/>
							</td>
							<td className="neural-forms-entries-col-star">
								<StarButton starred={ e.is_starred === 1 || e.is_starred === true } onClick={ () => onToggleStar( e ) } />
							</td>
							<td className="neural-forms-entries-row__form">{ e.form_name || `#${ e.form_id }` }</td>
							<td className="neural-forms-entries-row__submitter">{ submitter }</td>
							<td className="neural-forms-entries-row__preview">{ previewCell }</td>
							<td><StatusPill entry={ e } /><PaymentPill status={ e.payment_status } /></td>
							<td><PriorityPill p={ e.ai_priority } /></td>
							<td className="neural-forms-entries-row__date">{ formatDate( e.submitted_at || e.created_at ) }</td>
						</tr>
					);
				} ) }
			</tbody>
		</table>
	);
}
