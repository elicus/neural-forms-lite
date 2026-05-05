/**
 * Sidebar for the Entries inbox.
 *
 * Renders, in order:
 *   - "All Forms" header
 *   - System views: Inbox, Unread, Needs Reply, Starred, Spam, Trash
 *   - Always-on smart folders: Polls, Quizzes
 *   - Smart filters (High Priority, Spam Suspected)
 *   - Per-form folders (clicking scopes to one form_id)
 *
 * Polls and Quizzes are first-class system folders even when empty.
 * Their count badge is gated on > 0 so the row stays visually quiet
 * when there's nothing to show, but the entry point is always there.
 *
 * Per-form folders sit BELOW the system folders. Clicking one sets
 * formId to that form's id and clears any active smart-folder /
 * system view scope (the row title changes to that form's name).
 *
 * Selection model:
 *   `selection` is a tagged union:
 *     { kind: 'view',   id: 'inbox' | 'unread' | ... }
 *     { kind: 'smart',  id: 'polls' | 'quizzes' }
 *     { kind: 'filter', id: 'high_priority' | ... }
 *     { kind: 'form',   formId: 12 }
 *   The parent owns the state and passes it back down so the active
 *   highlight stays in sync with the actual list query.
 */

import { __ } from '@wordpress/i18n';
import { SYSTEM_VIEWS, SMART_FILTERS } from './constants';

function badge( n ) {
	if ( ! n || n <= 0 ) return null;
	return <span className="nf-inbox__sidebar-count">{ n }</span>;
}

function isActive( selection, kind, id, formId ) {
	if ( ! selection ) return false;
	if ( selection.kind !== kind ) return false;
	if ( kind === 'form' ) return selection.formId === formId;
	return selection.id === id;
}

export default function EntriesSidebar( {
	selection,
	onSelect,
	counts,
	smartCounts,
	forms,
	formsLoading,
} ) {
	const c = counts || {};
	const s = smartCounts || { polls: 0, quizzes: 0 };

	return (
		<aside className="nf-inbox__sidebar">
			<div className="nf-inbox__sidebar-card">
				<div className="nf-inbox__sidebar-section">
					<h3 className="nf-inbox__sidebar-title">{ __( 'All Forms', 'neural-forms-lite' ) }</h3>
					<ul className="nf-inbox__sidebar-list">
						{ SYSTEM_VIEWS.map( ( v ) => (
							<li key={ v.id }>
								<button
									type="button"
									className={ 'nf-inbox__sidebar-item' + ( isActive( selection, 'view', v.id ) ? ' is-active' : '' ) }
									onClick={ () => onSelect( { kind: 'view', id: v.id } ) }
								>
									<span>{ __( v.label, 'neural-forms-lite' ) }</span>
									{ badge( c[ v.id ] ) }
								</button>
							</li>
						) ) }

						{ /* Always-on smart folders. Mixed forms route to Quizzes. */ }
						<li key="polls">
							<button
								type="button"
								className={ 'nf-inbox__sidebar-item' + ( isActive( selection, 'smart', 'polls' ) ? ' is-active' : '' ) }
								onClick={ () => onSelect( { kind: 'smart', id: 'polls' } ) }
							>
								<span>{ __( 'Polls', 'neural-forms-lite' ) }</span>
								{ badge( s.polls ) }
							</button>
						</li>
						<li key="quizzes">
							<button
								type="button"
								className={ 'nf-inbox__sidebar-item' + ( isActive( selection, 'smart', 'quizzes' ) ? ' is-active' : '' ) }
								onClick={ () => onSelect( { kind: 'smart', id: 'quizzes' } ) }
							>
								<span>{ __( 'Quizzes', 'neural-forms-lite' ) }</span>
								{ badge( s.quizzes ) }
							</button>
						</li>
					</ul>
				</div>

				<div className="nf-inbox__sidebar-section">
					<h3 className="nf-inbox__sidebar-title">{ __( 'Smart Filters', 'neural-forms-lite' ) }</h3>
					<ul className="nf-inbox__sidebar-list">
						{ SMART_FILTERS.map( ( v ) => (
							<li key={ v.id }>
								<button
									type="button"
									className={ 'nf-inbox__sidebar-item' + ( isActive( selection, 'filter', v.id ) ? ' is-active' : '' ) }
									onClick={ () => onSelect( { kind: 'filter', id: v.id } ) }
								>
									<span>{ __( v.label, 'neural-forms-lite' ) }</span>
									{ badge( c[ v.id ] ) }
								</button>
							</li>
						) ) }
					</ul>
				</div>

				<div className="nf-inbox__sidebar-section">
					<h3 className="nf-inbox__sidebar-title">{ __( 'Forms', 'neural-forms-lite' ) }</h3>
					{ formsLoading && (
						<p className="nf-inbox__sidebar-hint">{ __( 'Loading forms...', 'neural-forms-lite' ) }</p>
					) }
					{ ! formsLoading && Array.isArray( forms ) && forms.length === 0 && (
						<p className="nf-inbox__sidebar-hint">
							{ __( 'No forms yet. Build one to start collecting entries.', 'neural-forms-lite' ) }
						</p>
					) }
					{ ! formsLoading && Array.isArray( forms ) && forms.length > 0 && (
						<ul className="nf-inbox__sidebar-list">
							{ forms.map( ( f ) => (
								<li key={ f.id }>
									<button
										type="button"
										className={ 'nf-inbox__sidebar-item' + ( isActive( selection, 'form', null, f.id ) ? ' is-active' : '' ) }
										onClick={ () => onSelect( { kind: 'form', formId: f.id } ) }
										title={ f.name || `#${ f.id }` }
									>
										<span className="nf-inbox__sidebar-form-name">{ f.name || `#${ f.id }` }</span>
										{ badge( f.entry_count ) }
									</button>
								</li>
							) ) }
						</ul>
					) }
				</div>
			</div>
		</aside>
	);
}
