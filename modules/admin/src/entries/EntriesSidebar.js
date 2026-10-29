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
import { SYSTEM_VIEWS, visibleSmartFilters } from './constants';

/* Inline SVG icon set. Stroke uses currentColor so the icon picks up
 * the parent button's color for active / hover / muted states with a
 * single CSS rule instead of per-icon overrides. */
const ICON_PROPS = {
	width:           '16',
	height:          '16',
	viewBox:         '0 0 24 24',
	fill:            'none',
	stroke:          'currentColor',
	strokeWidth:     '1.6',
	strokeLinecap:   'round',
	strokeLinejoin:  'round',
	'aria-hidden':   true,
};

function MenuIcon()        { return ( <svg { ...ICON_PROPS }><line x1="4" y1="6" x2="20" y2="6" /><line x1="4" y1="12" x2="20" y2="12" /><line x1="4" y1="18" x2="20" y2="18" /></svg> ); }
function InboxIcon()       { return ( <svg { ...ICON_PROPS }><path d="M22 12h-6l-2 3h-4l-2-3H2" /><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z" /></svg> ); }
function UnreadIcon()      { return ( <svg { ...ICON_PROPS }><line x1="3" y1="6" x2="21" y2="6" /><line x1="3" y1="12" x2="15" y2="12" /><line x1="3" y1="18" x2="18" y2="18" /></svg> ); }
function ReplyIcon()       { return ( <svg { ...ICON_PROPS }><polyline points="9 17 4 12 9 7" /><path d="M20 18v-2a4 4 0 0 0-4-4H4" /></svg> ); }
function StarIcon()        { return ( <svg { ...ICON_PROPS }><polygon points="12 2 15 9 22 9.5 17 14.5 18.5 22 12 18 5.5 22 7 14.5 2 9.5 9 9 12 2" /></svg> ); }
function SpamIcon()        { return ( <svg { ...ICON_PROPS }><circle cx="12" cy="12" r="9" /><line x1="12" y1="8" x2="12" y2="13" /><line x1="12" y1="16" x2="12" y2="16.01" /></svg> ); }
function TrashIcon()       { return ( <svg { ...ICON_PROPS }><polyline points="3 6 5 6 21 6" /><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6" /><path d="M10 11v6" /><path d="M14 11v6" /><path d="M9 6V4a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2" /></svg> ); }
function ClockIcon()       { return ( <svg { ...ICON_PROPS }><circle cx="12" cy="12" r="9" /><polyline points="12 7 12 12 15 14" /></svg> ); }
function AlertTriangleIcon(){ return ( <svg { ...ICON_PROPS }><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" /><line x1="12" y1="9" x2="12" y2="13" /><line x1="12" y1="17" x2="12" y2="17.01" /></svg> ); }

const VIEW_ICONS = {
	inbox:          InboxIcon,
	unread:         UnreadIcon,
	needs_reply:    ReplyIcon,
	starred:        StarIcon,
	spam:           SpamIcon,
	trash:          TrashIcon,
	high_priority:  StarIcon,
	spam_suspected: AlertTriangleIcon,
	overdue:        ClockIcon,
};

function ViewIcon( { id } ) {
	const Icon = VIEW_ICONS[ id ];
	if ( ! Icon ) return null;
	return (
		<span className="neural-forms-entries-sidebar__item-icon">
			<Icon />
		</span>
	);
}

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
					<h3 className="neural-forms-entries-sidebar__section-label neural-forms-entries-sidebar__section-label--with-icon">
						<span className="neural-forms-entries-sidebar__section-icon">
							<MenuIcon />
						</span>
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
									<ViewIcon id={ v.id } />
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
											<span className="neural-forms-entries-sidebar__item-icon">
												<MenuIcon />
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
															<ViewIcon id={ sv.id } />
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
						{ visibleSmartFilters( window.neuralFormsAdmin?.plugin?.capabilities ).map( ( v ) => (
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
									<ViewIcon id={ v.id } />
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
