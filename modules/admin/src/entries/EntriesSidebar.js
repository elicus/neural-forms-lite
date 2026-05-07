/**
 * Sidebar for the Entries inbox.
 *
 * Renders, in order:
 *   - "All Forms" header
 *   - System views: Inbox, Unread, Needs Reply, Starred, Spam, Trash
 *   - Smart filters (High Priority, Spam Suspected)
 *   - Per-form folders (clicking scopes to one form_id)
 *
 * Per-form folders sit BELOW the system folders. Clicking one sets
 * formId to that form's id and clears any active system view scope
 * (the row title changes to that form's name).
 *
 * Selection model:
 *   `selection` is a tagged union:
 *     { kind: 'view',   id: 'inbox' | 'unread' | ... }
 *     { kind: 'filter', id: 'high_priority' | ... }
 *     { kind: 'form',   formId: 12 }
 *   The parent owns the state and passes it back down so the active
 *   highlight stays in sync with the actual list query.
 *
 * Phase 5 of refactor/remove-protected-admin-bundle drops the
 * Polls/Quizzes smart folders to match the rich UI's taxonomy. The
 * `smart` selection kind is no longer rendered here.
 */

import { __ } from '@wordpress/i18n';
import { SYSTEM_VIEWS, SMART_FILTERS } from './constants';

function badge( n ) {
	if ( ! n || n <= 0 ) return null;
	return <span className="neural-forms-entries-sidebar__badge">{ n }</span>;
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
	forms,
	formsLoading,
} ) {
	const c = counts || {};

	return (
		<aside className="neural-forms-entries-sidebar">
			<div className="neural-forms-entries-sidebar-card">
				<div className="neural-forms-entries-sidebar__section">
					<h3 className="neural-forms-entries-sidebar__section-label">{ __( 'All Forms', 'neural-forms-lite' ) }</h3>
					<ul className="neural-forms-entries-sidebar__list">
						{ SYSTEM_VIEWS.map( ( v ) => (
							<li key={ v.id }>
								<button
									type="button"
									className={
										'neural-forms-entries-sidebar__item'
										+ ( isActive( selection, 'view', v.id ) ? ' neural-forms-entries-sidebar__item--active' : '' )
									}
									onClick={ () => onSelect( { kind: 'view', id: v.id } ) }
								>
									<span className="neural-forms-entries-sidebar__item-label">{ __( v.label, 'neural-forms-lite' ) }</span>
									{ badge( c[ v.id ] ) }
								</button>
							</li>
						) ) }
					</ul>
				</div>

				<div className="neural-forms-entries-sidebar__section">
					<h3 className="neural-forms-entries-sidebar__section-label">{ __( 'Smart Filters', 'neural-forms-lite' ) }</h3>
					<ul className="neural-forms-entries-sidebar__list">
						{ SMART_FILTERS.map( ( v ) => (
							<li key={ v.id }>
								<button
									type="button"
									className={
										'neural-forms-entries-sidebar__item'
										+ ( isActive( selection, 'filter', v.id ) ? ' neural-forms-entries-sidebar__item--active' : '' )
									}
									onClick={ () => onSelect( { kind: 'filter', id: v.id } ) }
								>
									<span className="neural-forms-entries-sidebar__item-label">{ __( v.label, 'neural-forms-lite' ) }</span>
									{ badge( c[ v.id ] ) }
								</button>
							</li>
						) ) }
					</ul>
				</div>

				<div className="neural-forms-entries-sidebar__section">
					<h3 className="neural-forms-entries-sidebar__section-label">{ __( 'Forms', 'neural-forms-lite' ) }</h3>
					{ formsLoading && (
						<p className="neural-forms-entries-sidebar__hint">{ __( 'Loading forms...', 'neural-forms-lite' ) }</p>
					) }
					{ ! formsLoading && Array.isArray( forms ) && forms.length === 0 && (
						<p className="neural-forms-entries-sidebar__hint">
							{ __( 'No forms yet. Build one to start collecting entries.', 'neural-forms-lite' ) }
						</p>
					) }
					{ ! formsLoading && Array.isArray( forms ) && forms.length > 0 && (
						<ul className="neural-forms-entries-sidebar__list">
							{ forms.map( ( f ) => (
								<li key={ f.id }>
									<button
										type="button"
										className={
											'neural-forms-entries-sidebar__item'
											+ ( isActive( selection, 'form', null, f.id ) ? ' neural-forms-entries-sidebar__item--active' : '' )
										}
										onClick={ () => onSelect( { kind: 'form', formId: f.id } ) }
										title={ f.name || `#${ f.id }` }
									>
										<span className="neural-forms-entries-sidebar__item-label">{ f.name || `#${ f.id }` }</span>
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
