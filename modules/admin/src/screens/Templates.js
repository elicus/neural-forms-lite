/**
 * Templates screen — remote template library.
 *
 * Fetches the templates.neuralforms.ai catalog on mount via
 * GET /templates, renders a filterable card grid, and imports a chosen
 * template through POST /templates/import. Import responses include the
 * new draft page ID + edit / view URLs and the IDs of every form that
 * was imported alongside the page, which the success notice surfaces.
 *
 * BEM root: `.neural-forms-templates`. All styling lives in
 * src/templates.css; no inline styles, no raw <style> blocks.
 */

import { useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Notice, Spinner } from '@wordpress/components';
import api from '../api';

// Category filter tabs — keep this list explicit so the order is
// stable regardless of which categories the remote catalog happens
// to ship today. "All" is the default; the others filter by
// case-insensitive match on the template's `category` or `tags`.
const FILTERS = [
	{ id: 'all',     label: 'All',              match: null            },
	{ id: 'contact', label: 'Contact',          match: 'contact'       },
	{ id: 'lead',    label: 'Lead Generation',  match: 'lead'          },
	{ id: 'news',    label: 'Newsletter',       match: 'newsletter'    },
	{ id: 'support', label: 'Support',          match: 'support'       },
];

function matchesFilter( tpl, filter ) {
	if ( ! filter || ! filter.match ) return true;
	const needle   = filter.match.toLowerCase();
	const category = String( tpl.category || '' ).toLowerCase();
	if ( category.indexOf( needle ) !== -1 ) return true;
	const tags = Array.isArray( tpl.tags ) ? tpl.tags : [];
	for ( let i = 0; i < tags.length; i++ ) {
		if ( String( tags[ i ] ).toLowerCase().indexOf( needle ) !== -1 ) return true;
	}
	return false;
}

export default function Templates() {
	const [ catalog,  setCatalog  ] = useState( null );
	const [ loading,  setLoading  ] = useState( true );
	const [ error,    setError    ] = useState( '' );
	const [ filter,   setFilter   ] = useState( 'all' );
	const [ importing,    setImporting    ] = useState( '' ); // template id mid-import
	const [ importSuccess, setImportSuccess ] = useState( null );
	const [ importError,   setImportError   ] = useState( '' );

	useEffect( () => {
		let cancelled = false;
		setLoading( true );
		setError( '' );
		api( '/templates' )
			.then( ( res ) => {
				if ( cancelled ) return;
				setCatalog( res );
				setLoading( false );
			} )
			.catch( ( err ) => {
				if ( cancelled ) return;
				setError( ( err && err.message ) || __( 'Failed to load templates.', 'neural-forms-lite' ) );
				setLoading( false );
			} );
		return () => { cancelled = true; };
	}, [] );

	const templates = useMemo( () => {
		const rows = Array.isArray( catalog?.templates ) ? catalog.templates : [];
		const active = FILTERS.find( ( f ) => f.id === filter ) || FILTERS[ 0 ];
		return rows.filter( ( t ) => matchesFilter( t, active ) );
	}, [ catalog, filter ] );

	function onImport( tpl ) {
		if ( ! tpl || ! tpl.manifest ) return;
		setImporting( tpl.id );
		setImportError( '' );
		setImportSuccess( null );
		api( '/templates/import', {
			method: 'POST',
			data: {
				template_id:  tpl.id,
				manifest_url: tpl.manifest,
			},
		} )
			.then( ( res ) => {
				setImporting( '' );
				setImportSuccess( {
					template: tpl,
					pageId:   res?.page_id || 0,
					editUrl:  res?.edit_url || '',
					viewUrl:  res?.view_url || '',
					formIds:  Array.isArray( res?.imported_form_ids ) ? res.imported_form_ids : [],
				} );
			} )
			.catch( ( err ) => {
				setImporting( '' );
				setImportError( ( err && err.message ) || __( 'Import failed.', 'neural-forms-lite' ) );
			} );
	}

	return (
		<div className="neural-forms-templates">
			<header className="neural-forms-templates__header">
				<h1 className="neural-forms-templates__title">
					{ __( 'Template Library', 'neural-forms-lite' ) }
				</h1>
				<p className="neural-forms-templates__subtitle">
					{ __( 'Import ready-made Neural Forms pages into your website.', 'neural-forms-lite' ) }
				</p>
			</header>

			{ !! importSuccess && (
				<Notice
					status="success"
					isDismissible
					onRemove={ () => setImportSuccess( null ) }
					className="neural-forms-templates__notice"
				>
					{ __( 'Template imported successfully.', 'neural-forms-lite' ) }
					{ ' ' }
					{ !! importSuccess.editUrl && (
						<a href={ importSuccess.editUrl } target="_blank" rel="noreferrer">
							{ __( 'Edit Page', 'neural-forms-lite' ) }
						</a>
					) }
					{ !! importSuccess.editUrl && !! importSuccess.viewUrl && ' · ' }
					{ !! importSuccess.viewUrl && (
						<a href={ importSuccess.viewUrl } target="_blank" rel="noreferrer">
							{ __( 'View Page', 'neural-forms-lite' ) }
						</a>
					) }
				</Notice>
			) }

			{ !! importError && (
				<Notice
					status="error"
					isDismissible
					onRemove={ () => setImportError( '' ) }
					className="neural-forms-templates__notice"
				>
					{ importError }
				</Notice>
			) }

			<nav className="neural-forms-templates__filters" role="tablist" aria-label={ __( 'Filter templates', 'neural-forms-lite' ) }>
				{ FILTERS.map( ( f ) => (
					<button
						key={ f.id }
						type="button"
						role="tab"
						aria-selected={ filter === f.id }
						className={
							'neural-forms-templates__filter' +
							( filter === f.id ? ' is-active' : '' )
						}
						onClick={ () => setFilter( f.id ) }
					>
						{ f.label }
					</button>
				) ) }
			</nav>

			{ loading && (
				<div className="neural-forms-templates__loading">
					<Spinner />
					<span>{ __( 'Loading templates…', 'neural-forms-lite' ) }</span>
				</div>
			) }

			{ ! loading && error && (
				<Notice status="error" isDismissible={ false } className="neural-forms-templates__notice">
					{ error }
				</Notice>
			) }

			{ ! loading && ! error && catalog?.service_unavailable && (
				<div className="neural-forms-templates__empty">
					{ __( 'The Neural Forms template library is being prepared. Please check back soon.', 'neural-forms-lite' ) }
				</div>
			) }

			{ ! loading && ! error && ! catalog?.service_unavailable && templates.length === 0 && (
				<div className="neural-forms-templates__empty">
					{ __( 'No templates match this filter yet. Try a different category.', 'neural-forms-lite' ) }
				</div>
			) }

			{ ! loading && ! error && templates.length > 0 && (
				<div className="neural-forms-templates__grid" role="list">
					{ templates.map( ( tpl ) => (
						<TemplateCard
							key={ tpl.id }
							tpl={ tpl }
							importing={ importing === tpl.id }
							onImport={ onImport }
						/>
					) ) }
				</div>
			) }
		</div>
	);
}

function TemplateCard( { tpl, importing, onImport } ) {
	const compat = Array.isArray( tpl.compatibility ) ? tpl.compatibility : [];
	const showsBlockEditor = compat.indexOf( 'gutenberg' ) !== -1;

	return (
		<article className="neural-forms-templates__card" role="listitem">
			<div className="neural-forms-templates__thumb">
				{ tpl.thumbnail ? (
					<img
						src={ tpl.thumbnail }
						alt={ tpl.title || tpl.id }
						loading="lazy"
					/>
				) : (
					<div className="neural-forms-templates__thumb-placeholder" aria-hidden="true" />
				) }
			</div>

			<div className="neural-forms-templates__body">
				<h2 className="neural-forms-templates__card-title">
					{ tpl.title || tpl.id }
				</h2>

				{ !! tpl.description && (
					<p className="neural-forms-templates__card-desc">
						{ tpl.description }
					</p>
				) }

				{ showsBlockEditor && (
					<span className="neural-forms-templates__badge">
						{ __( 'Block Editor', 'neural-forms-lite' ) }
					</span>
				) }
			</div>

			<footer className="neural-forms-templates__actions">
				<Button
					variant="secondary"
					href={ tpl.preview || tpl.thumbnail || '#' }
					target="_blank"
					rel="noreferrer"
					disabled={ ! tpl.preview && ! tpl.thumbnail }
				>
					{ __( 'Preview', 'neural-forms-lite' ) }
				</Button>
				<Button
					variant="primary"
					onClick={ () => onImport( tpl ) }
					isBusy={ importing }
					disabled={ importing || ! tpl.manifest }
				>
					{ importing ? __( 'Importing…', 'neural-forms-lite' ) : __( 'Import', 'neural-forms-lite' ) }
				</Button>
			</footer>
		</article>
	);
}
