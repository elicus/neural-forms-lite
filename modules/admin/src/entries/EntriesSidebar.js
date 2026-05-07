/**
 * Sidebar for the Entries inbox.
 *
 * Section order (matches the rich UI's Gmail-like layout):
 *   1. All Forms      — system views: Inbox / Unread / Needs Reply /
 *                       Starred / Spam / Trash
 *   2. Forms          — each form is a parent row; clicking expands it
 *                       and selects its Inbox. Child rows mirror the
 *                       same six system views, scoped to that form via
 *                       form_id=N&view=<id>. One form open at a time
 *                       (driven by selection state, no separate
 *                       expansion state).
 *   3. Smart Filters  — High Priority / Spam Suspected
 *
 * Selection model (new in Phase 5):
 *   { type: 'all'   , formId: null, view: '<system view>', smartFilter: null }
 *   { type: 'form'  , formId: N   , view: '<system view>', smartFilter: null }
 *   { type: 'smart' , formId: null, view: 'inbox',         smartFilter: '<id>' }
 *
 * Phase 5 of refactor/remove-protected-admin-bundle. Polls/Quizzes
 * smart folders intentionally absent.
 */

import { __ } from '@wordpress/i18n';
import { SYSTEM_VIEWS, SMART_FILTERS } from './constants';

function badge( n ) {
	if ( ! n || n <= 0 ) return null;
	return <span className="neural-forms-entries-sidebar__badge">{ n }</span>;
}

function isAllActive( selection, viewId ) {
	return selection?.type === 'all' && selection.view === viewId;
}

function isFormExpanded( selection, formId ) {
	return selection?.type === 'form' && selection.formId === formId;
}

function isFormChildActive( selection, formId, viewId ) {
	return selection?.type === 'form'
		&& selection.formId === formId
		&& selection.view   === viewId;
}

function isSmartActive( selection, filterId ) {
	return selection?.type === 'smart' && selection.smartFilter === filterId;
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

				{ /* ── All Forms: system views ────────────────────── */ }
				<div className="neural-forms-entries-sidebar__section">
					<h3 className="neural-forms-entries-sidebar__section-label">
						{ __( 'All Forms', 'neural-forms-lite' ) }
					</h3>
					<ul className="neural-forms-entries-sidebar__list">
						{ SYSTEM_VIEWS.map( ( v ) => (
							<li key={ v.id }>
								<button
									type="button"
									className={
										'neural-forms-entries-sidebar__item'
										+ ( isAllActive( selection, v.id ) ? ' neural-forms-entries-sidebar__item--active' : '' )
									}
									onClick={ () => onSelect( {
										type:        'all',
										formId:      null,
										view:        v.id,
										smartFilter: null,
									} ) }
								>
									<span className="neural-forms-entries-sidebar__item-label">{ __( v.label, 'neural-forms-lite' ) }</span>
									{ badge( c[ v.id ] ) }
								</button>
							</li>
						) ) }
					</ul>
				</div>

				{ /* ── Forms: each expandable with system-view children ── */ }
				<div className="neural-forms-entries-sidebar__section">
					<h3 className="neural-forms-entries-sidebar__section-label">
						{ __( 'Forms', 'neural-forms-lite' ) }
					</h3>

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
							{ forms.map( ( f ) => {
								const expanded = isFormExpanded( selection, f.id );
								return (
									<li key={ f.id }>
										<button
											type="button"
											className={
												'neural-forms-entries-sidebar__form'
												+ ( expanded ? ' neural-forms-entries-sidebar__form--active' : '' )
											}
											aria-expanded={ expanded }
											onClick={ () => onSelect( {
												type:        'form',
												formId:      f.id,
												view:        'inbox',
												smartFilter: null,
											} ) }
											title={ f.name || `#${ f.id }` }
										>
											<span className="neural-forms-entries-sidebar__chevron" aria-hidden="true">
												{ expanded ? '▾' : '▸' }
											</span>
											<span className="neural-forms-entries-sidebar__item-label">{ f.name || `#${ f.id }` }</span>
											{ badge( f.entry_count ) }
										</button>

										{ expanded && (
											<ul className="neural-forms-entries-sidebar__sub-list">
												{ SYSTEM_VIEWS.map( ( sv ) => (
													<li key={ sv.id }>
														<button
															type="button"
															className={
																'neural-forms-entries-sidebar__sub-item'
																+ ( isFormChildActive( selection, f.id, sv.id ) ? ' neural-forms-entries-sidebar__sub-item--active' : '' )
															}
															onClick={ () => onSelect( {
																type:        'form',
																formId:      f.id,
																view:        sv.id,
																smartFilter: null,
															} ) }
														>
															<span className="neural-forms-entries-sidebar__item-label">{ __( sv.label, 'neural-forms-lite' ) }</span>
															{ /* Counts are form-scoped only when this form is the
															     active selection (the screen refetches counts with
															     form_id then). For other forms we'd be showing the
															     wrong number, so omit the badge until expanded. */ }
															{ expanded && badge( c[ sv.id ] ) }
														</button>
													</li>
												) ) }
											</ul>
										) }
									</li>
								);
							} ) }
						</ul>
					) }
				</div>

				{ /* ── Smart Filters ──────────────────────────────── */ }
				<div className="neural-forms-entries-sidebar__section">
					<h3 className="neural-forms-entries-sidebar__section-label">
						{ __( 'Smart Filters', 'neural-forms-lite' ) }
					</h3>
					<ul className="neural-forms-entries-sidebar__list">
						{ SMART_FILTERS.map( ( v ) => (
							<li key={ v.id }>
								<button
									type="button"
									className={
										'neural-forms-entries-sidebar__item'
										+ ( isSmartActive( selection, v.id ) ? ' neural-forms-entries-sidebar__item--active' : '' )
									}
									onClick={ () => onSelect( {
										type:        'smart',
										formId:      null,
										view:        'inbox',
										smartFilter: v.id,
									} ) }
								>
									<span className="neural-forms-entries-sidebar__item-label">{ __( v.label, 'neural-forms-lite' ) }</span>
									{ badge( c[ v.id ] ) }
								</button>
							</li>
						) ) }
					</ul>
				</div>
			</div>
		</aside>
	);
}
