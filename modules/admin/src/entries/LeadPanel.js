/**
 * Lead management panel + activity timeline (1.2.0).
 *
 * LeadPanel — stage / owner / follow-up controls shown under the entry
 * hero. The columns always exist in Lite; the CONTROLS need the
 * `workflows` capability (Pro). Locked installs see the current values
 * read-only plus an upsell line.
 *
 * ActivityTimeline — GET /entries/{id}/activity (assignments, stage
 * changes, follow-ups, workflow actions), newest first. Reloads when
 * `refreshSignal` changes.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../api';
import { formatDate } from './helpers';

function toLocalInputValue( mysqlUtc ) {
	if ( ! mysqlUtc ) return '';
	// "YYYY-MM-DD HH:MM:SS" (UTC) → local "YYYY-MM-DDTHH:MM" for <input type=datetime-local>.
	const d = new Date( mysqlUtc.replace( ' ', 'T' ) + 'Z' );
	if ( isNaN( d.getTime() ) ) return '';
	const pad = ( n ) => String( n ).padStart( 2, '0' );
	return `${ d.getFullYear() }-${ pad( d.getMonth() + 1 ) }-${ pad( d.getDate() ) }T${ pad( d.getHours() ) }:${ pad( d.getMinutes() ) }`;
}

function fromLocalInputValue( local ) {
	if ( ! local ) return null;
	const d = new Date( local );
	return isNaN( d.getTime() ) ? null : d.toISOString();
}

export default function LeadPanel( { entry, capabilities, leadStages, assignees, onUpdateLead } ) {
	const [ busy, setBusy ]   = useState( '' );
	const [ error, setError ] = useState( '' );
	const unlocked = !! ( capabilities && capabilities.workflows );
	const stages   = Array.isArray( leadStages ) && leadStages.length ? leadStages : [ { id: 'new', label: __( 'New enquiry', 'neural-forms-lite' ) } ];
	const users    = Array.isArray( assignees ) ? assignees : [];

	if ( ! entry ) return null;

	const stage      = entry.lead_stage || 'new';
	const stageLabel = ( stages.find( ( s ) => s.id === stage ) || {} ).label || stage;
	const owner      = entry.assigned_to ? users.find( ( u ) => u.id === Number( entry.assigned_to ) ) : null;
	const due        = entry.follow_up_due || null;
	const done       = !! Number( entry.follow_up_done );
	const overdue    = !! Number( entry.follow_up_overdue );

	const save = ( key, fields ) => {
		if ( ! onUpdateLead ) return;
		setBusy( key );
		setError( '' );
		Promise.resolve( onUpdateLead( entry, fields ) )
			.catch( ( err ) => setError( ( err && err.message ) || __( 'Could not save.', 'neural-forms-lite' ) ) )
			.finally( () => setBusy( '' ) );
	};

	if ( ! unlocked ) {
		const hasValues = stage !== 'new' || entry.assigned_to || due;
		return (
			<section className="neural-forms-entry-detail__lead neural-forms-entry-detail__lead--locked" aria-label={ __( 'Lead management', 'neural-forms-lite' ) }>
				{ hasValues && (
					<p className="neural-forms-entry-detail__lead-values">
						<span>{ sprintf( __( 'Stage: %s', 'neural-forms-lite' ), stageLabel ) }</span>
						{ owner && <span>{ sprintf( __( 'Owner: %s', 'neural-forms-lite' ), owner.name ) }</span> }
						{ due && <span>{ sprintf( __( 'Follow-up: %s', 'neural-forms-lite' ), formatDate( due ) ) }</span> }
					</p>
				) }
				<p className="neural-forms-entry-detail__lead-upsell">
					{ __( 'Track each lead through stages, assign an owner and set follow-up reminders with Neural Forms Pro.', 'neural-forms-lite' ) }
				</p>
			</section>
		);
	}

	return (
		<section className="neural-forms-entry-detail__lead" aria-label={ __( 'Lead management', 'neural-forms-lite' ) }>
			<label className="neural-forms-entry-detail__lead-field">
				<span className="neural-forms-entry-detail__lead-label">{ __( 'Stage', 'neural-forms-lite' ) }</span>
				<select
					value={ stage }
					disabled={ busy !== '' }
					onChange={ ( e ) => save( 'stage', { lead_stage: e.target.value } ) }
					className={ 'neural-forms-entry-detail__lead-stage neural-forms-entry-detail__lead-stage--' + stage }
				>
					{ stages.map( ( s ) => <option key={ s.id } value={ s.id }>{ s.label }</option> ) }
				</select>
			</label>

			<label className="neural-forms-entry-detail__lead-field">
				<span className="neural-forms-entry-detail__lead-label">{ __( 'Owner', 'neural-forms-lite' ) }</span>
				<select
					value={ entry.assigned_to ? String( entry.assigned_to ) : '' }
					disabled={ busy !== '' }
					onChange={ ( e ) => save( 'owner', { assigned_to: e.target.value ? Number( e.target.value ) : 0 } ) }
				>
					<option value="">{ __( 'Unassigned', 'neural-forms-lite' ) }</option>
					{ users.map( ( u ) => <option key={ u.id } value={ String( u.id ) }>{ u.name }</option> ) }
					{ entry.assigned_to && ! owner && (
						<option value={ String( entry.assigned_to ) }>{ sprintf( __( 'User #%d', 'neural-forms-lite' ), Number( entry.assigned_to ) ) }</option>
					) }
				</select>
			</label>

			<label className={ 'neural-forms-entry-detail__lead-field' + ( overdue ? ' neural-forms-entry-detail__lead-field--overdue' : '' ) }>
				<span className="neural-forms-entry-detail__lead-label">
					{ __( 'Follow-up', 'neural-forms-lite' ) }
					{ overdue && <span className="neural-forms-entry-detail__lead-overdue">{ __( 'Overdue', 'neural-forms-lite' ) }</span> }
				</span>
				<span className="neural-forms-entry-detail__lead-followup">
					<input
						type="datetime-local"
						value={ toLocalInputValue( due ) }
						disabled={ busy !== '' }
						onChange={ ( e ) => save( 'due', { follow_up_due: fromLocalInputValue( e.target.value ) } ) }
					/>
					{ due && (
						<label className="neural-forms-entry-detail__lead-done">
							<input
								type="checkbox"
								checked={ done }
								disabled={ busy !== '' }
								onChange={ ( e ) => save( 'done', { follow_up_done: e.target.checked ? 1 : 0 } ) }
							/>
							<span>{ __( 'Done', 'neural-forms-lite' ) }</span>
						</label>
					) }
					{ due && (
						<button
							type="button"
							className="neural-forms-entry-detail__lead-clear"
							disabled={ busy !== '' }
							onClick={ () => save( 'due', { follow_up_due: null } ) }
							aria-label={ __( 'Clear follow-up', 'neural-forms-lite' ) }
							title={ __( 'Clear follow-up', 'neural-forms-lite' ) }
						>
							×
						</button>
					) }
				</span>
			</label>

			{ error && <p className="neural-forms-entry-detail__lead-error" role="alert">{ error }</p> }
		</section>
	);
}

const EVENT_LABELS = {
	'entry.assigned':          __( 'Assignment', 'neural-forms-lite' ),
	'entry.stage_changed':     __( 'Stage', 'neural-forms-lite' ),
	'entry.follow_up_set':     __( 'Follow-up', 'neural-forms-lite' ),
	'entry.follow_up_done':    __( 'Follow-up', 'neural-forms-lite' ),
	'entry.follow_up_cleared': __( 'Follow-up', 'neural-forms-lite' ),
	'entry.reply_sent':        __( 'Reply', 'neural-forms-lite' ),
};

function eventLabel( type ) {
	if ( EVENT_LABELS[ type ] ) return EVENT_LABELS[ type ];
	if ( type && type.indexOf( 'workflow.' ) === 0 ) return __( 'Workflow', 'neural-forms-lite' );
	return __( 'Activity', 'neural-forms-lite' );
}

export function ActivityTimeline( { entry, refreshSignal } ) {
	const [ items, setItems ]     = useState( null );
	const [ error, setError ]     = useState( '' );
	const [ open, setOpen ]       = useState( true );
	const entryId = entry ? entry.id : 0;

	useEffect( () => {
		if ( ! entryId ) return undefined;
		let cancelled = false;
		api( `/entries/${ entryId }/activity` ).then( ( rows ) => {
			if ( ! cancelled ) setItems( Array.isArray( rows ) ? rows : [] );
		} ).catch( ( err ) => {
			if ( ! cancelled ) setError( ( err && err.message ) || __( 'Could not load activity.', 'neural-forms-lite' ) );
		} );
		return () => { cancelled = true; };
	}, [ entryId, refreshSignal ] );

	if ( ! entryId ) return null;
	if ( ! error && items && items.length === 0 ) return null;

	return (
		<section className="neural-forms-entry-detail__activity" aria-label={ __( 'Activity', 'neural-forms-lite' ) }>
			<button
				type="button"
				className="neural-forms-entry-detail__activity-toggle"
				onClick={ () => setOpen( ( o ) => ! o ) }
				aria-expanded={ open }
			>
				<span className="neural-forms-entry-detail__activity-title">{ __( 'Activity', 'neural-forms-lite' ) }</span>
				{ items && <span className="neural-forms-entry-detail__activity-count">{ items.length }</span> }
				<span className="neural-forms-entry-detail__activity-chevron" aria-hidden="true">{ open ? '▾' : '▸' }</span>
			</button>
			{ open && error && <p className="neural-forms-entry-detail__lead-error">{ error }</p> }
			{ open && ! error && ! items && <p className="neural-forms-entry-detail__activity-empty">{ __( 'Loading…', 'neural-forms-lite' ) }</p> }
			{ open && items && items.length > 0 && (
				<ol className="neural-forms-entry-detail__activity-list">
					{ items.map( ( row ) => (
						<li key={ row.id } className={ 'neural-forms-entry-detail__activity-item neural-forms-entry-detail__activity-item--' + ( row.status || 'info' ) }>
							<span className="neural-forms-entry-detail__activity-kind">{ eventLabel( row.event_type ) }</span>
							<span className="neural-forms-entry-detail__activity-message">{ row.message }</span>
							<span className="neural-forms-entry-detail__activity-meta">
								{ row.by_name ? row.by_name + ' · ' : '' }{ formatDate( row.created_at ) }
							</span>
						</li>
					) ) }
				</ol>
			) }
		</section>
	);
}
