/**
 * Templates screen — remote template library.
 *
 * Fetches the templates.neuralforms.ai catalog for the active builder
 * via GET /templates?builder=<slug>, renders a polished card grid with
 * a hero header, builder tabs, category pills, search input, and a
 * lightbox preview. Importing a template POSTs to /templates/import
 * which persists the import in the `neural_forms_imported_templates`
 * option — subsequent listings flag those rows so the card shows
 * an "Imported" badge and offers Import Again + Edit imported page.
 *
 * BEM root: `.neural-forms-templates`. All styling lives in
 * src/templates.css; no inline styles, no raw <style> blocks.
 */

import { useEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf, _n } from '@wordpress/i18n';
import { Button, Notice, Spinner } from '@wordpress/components';
import api from '../api';

// ─── builder tabs ──────────────────────────────────────────────────
// Block Editor is the only importable builder for MVP. Divi sits in
// the tab row as a disabled "PRO" entry so users can see it's part
// of the roadmap (and behind the Pro plugin). Elementor is dropped
// from the UI entirely for now — when its importer ships, add an
// entry here with `pillVariant: 'pro'` or `'soon'` as appropriate.
const BUILDERS = [
	{ slug: 'block-editor', label: __( 'Block Editor', 'neural-forms-lite' ), available: true,  pill: '' },
	{ slug: 'divi',         label: __( 'Divi',         'neural-forms-lite' ), available: false, pill: __( 'PRO', 'neural-forms-lite' ) },
];

// Skeleton cards count — 6 fills two rows on desktop, one row on
// tablet, six rows on mobile. Pure CSS animation in templates.css.
const SKELETON_COUNT = 6;

/**
 * Format an imported_at MySQL-style timestamp ("2026-05-18 18:00:00")
 * as a friendly long date ("May 18, 2026") using the browser's
 * locale. Falls back to the raw value when parsing fails so a
 * broken option payload still surfaces something readable.
 *
 * @param {string} raw
 * @returns {string}
 */
function formatImportedDate( raw ) {
	if ( ! raw ) return '';
	// Replace the space with 'T' so Safari's parser accepts it as
	// ISO-style — Chromium / Firefox handle either, Safari is strict.
	const d = new Date( String( raw ).replace( ' ', 'T' ) );
	if ( isNaN( d.getTime() ) ) return String( raw );
	return d.toLocaleDateString( undefined, {
		month: 'long',
		day:   'numeric',
		year:  'numeric',
	} );
}

export default function Templates() {
	const [ builder,       setBuilder       ] = useState( 'block-editor' );
	const [ catalog,       setCatalog       ] = useState( null );
	const [ loading,       setLoading       ] = useState( true );
	const [ error,         setError         ] = useState( '' );
	const [ activeCategory, setActiveCategory ] = useState( 'all' );
	const [ searchQuery,   setSearchQuery   ] = useState( '' );
	const [ refreshing,    setRefreshing    ] = useState( false );
	const [ importing,     setImporting     ] = useState( '' ); // template id mid-import
	const [ importSuccess, setImportSuccess ] = useState( null );
	const [ importError,   setImportError   ] = useState( '' );
	const [ lightbox,      setLightbox      ] = useState( null );  // { src, alt } | null

	function loadCatalog( forceRefresh = false, builderSlug = builder ) {
		const path = '/templates?builder=' + encodeURIComponent( builderSlug )
			+ ( forceRefresh ? '&refresh=1' : '' );
		if ( forceRefresh ) setRefreshing( true );
		else setLoading( true );
		setError( '' );
		return api( path )
			.then( ( res ) => {
				setCatalog( res );
				setLoading( false );
				setRefreshing( false );
			} )
			.catch( ( err ) => {
				setError( ( err && err.message ) || __( 'Failed to load templates.', 'neural-forms-lite' ) );
				setLoading( false );
				setRefreshing( false );
			} );
	}

	useEffect( () => {
		let cancelled = false;
		( async function () {
			if ( cancelled ) return;
			await loadCatalog( false );
		} )();
		return () => { cancelled = true; };
	// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ builder ] );

	// Build the category filter list dynamically from the loaded
	// catalog. Always lead with "All"; subsequent entries are
	// derived from the unique `category` values present in the data
	// so the filter row never shows an empty bucket.
	const categoryFilters = useMemo( () => {
		const rows = Array.isArray( catalog?.templates ) ? catalog.templates : [];
		const counts = new Map();
		rows.forEach( ( t ) => {
			const c = String( t.category || '' ).trim();
			if ( c === '' ) return;
			counts.set( c, ( counts.get( c ) || 0 ) + 1 );
		} );
		const cats = Array.from( counts.entries() )
			.sort( ( a, b ) => a[ 0 ].localeCompare( b[ 0 ] ) )
			.map( ( [ label, count ] ) => ( { id: label.toLowerCase(), label, count } ) );
		return [
			{ id: 'all', label: __( 'All', 'neural-forms-lite' ), count: rows.length },
			...cats,
		];
	}, [ catalog ] );

	// Filtering pipeline — category match (case-insensitive on the
	// raw catalog `category` value) AND a search match over name /
	// description / category / tags.
	const filteredTemplates = useMemo( () => {
		const rows = Array.isArray( catalog?.templates ) ? catalog.templates : [];
		const q    = searchQuery.trim().toLowerCase();
		return rows.filter( ( t ) => {
			if ( activeCategory !== 'all' ) {
				const cat = String( t.category || '' ).toLowerCase();
				if ( cat !== activeCategory ) return false;
			}
			if ( q !== '' ) {
				const haystack = [
					t.name || '',
					t.description || '',
					t.category || '',
					...( Array.isArray( t.tags ) ? t.tags : [] ),
				].join( ' ' ).toLowerCase();
				if ( haystack.indexOf( q ) === -1 ) return false;
			}
			return true;
		} );
	}, [ catalog, activeCategory, searchQuery ] );

	function onImport( tpl ) {
		if ( ! tpl || ! tpl.import_url ) return;
		setImporting( tpl.id );
		setImportError( '' );
		setImportSuccess( null );
		api( '/templates/import', {
			method: 'POST',
			data: {
				builder:     builder,
				template_id: tpl.id,
				import_url:  tpl.import_url,
			},
		} )
			.then( ( res ) => {
				setImporting( '' );
				setImportSuccess( {
					template:   tpl,
					pageId:     res?.page_id || 0,
					editUrl:    res?.edit_url || '',
					viewUrl:    res?.view_url || '',
					formIds:    Array.isArray( res?.imported_form_ids ) ? res.imported_form_ids : [],
					formIdMap:  res?.form_id_map && typeof res.form_id_map === 'object' ? res.form_id_map : {},
				} );
				// Refresh the catalog so the just-imported row flips
				// to its "Imported" state without the user having to
				// click Refresh. Force-bypasses the transient on the
				// server so the imported decoration is recomputed.
				loadCatalog( true, builder );
			} )
			.catch( ( err ) => {
				setImporting( '' );
				setImportError( ( err && err.message ) || __( 'Import failed.', 'neural-forms-lite' ) );
			} );
	}

	function onBuilderChange( nextBuilder ) {
		if ( nextBuilder === builder ) return;
		const def = BUILDERS.find( ( b ) => b.slug === nextBuilder );
		if ( ! def || ! def.available ) return;
		// Reset filter / search when crossing builders so the user
		// doesn't carry a stale "Contact" pill into a Divi catalog
		// that doesn't have a Contact category.
		setActiveCategory( 'all' );
		setSearchQuery( '' );
		setBuilder( nextBuilder );
	}

	const updatedAt = catalog?.updated_at || '';

	return (
		<div className="neural-forms-templates">
			<header className="neural-forms-templates__hero">
				<div className="neural-forms-templates__hero-text">
					<h1 className="neural-forms-templates__title">
						{ __( 'Template Library', 'neural-forms-lite' ) }
					</h1>
					<p className="neural-forms-templates__subtitle">
						{ __( 'Import professionally designed Neural Forms pages into your website in one click.', 'neural-forms-lite' ) }
					</p>
				</div>
				<div className="neural-forms-templates__hero-actions">
					{ !! updatedAt && (
						<span className="neural-forms-templates__updated">
							{ sprintf(
								/* translators: %s: catalog updated_at timestamp from the remote catalog file. */
								__( 'Catalog updated %s', 'neural-forms-lite' ),
								updatedAt
							) }
						</span>
					) }
					<Button
						variant="secondary"
						onClick={ () => loadCatalog( true, builder ) }
						isBusy={ refreshing }
						disabled={ refreshing || loading }
					>
						{ refreshing
							? __( 'Refreshing…', 'neural-forms-lite' )
							: __( 'Refresh Catalog', 'neural-forms-lite' ) }
					</Button>
				</div>
			</header>

			{ /* Builder tabs — Block Editor active, Divi disabled with
			     a navy PRO pill. Elementor is intentionally absent
			     from the row. Render as a WAI-ARIA tablist so screen
			     readers announce them properly. */ }
			<nav className="neural-forms-templates__builders" role="tablist" aria-label={ __( 'Builder', 'neural-forms-lite' ) }>
				{ BUILDERS.map( ( b ) => {
					const isActive = b.slug === builder;
					// The pill text comes from the builder definition
					// (`b.pill` — "PRO" for Divi today). When present
					// we add a `--pro` modifier so the navy pill style
					// kicks in; the old grey "Coming Soon" pill style
					// is gone.
					const showsPill = ! b.available && b.pill;
					return (
						<button
							key={ b.slug }
							type="button"
							role="tab"
							aria-selected={ isActive }
							aria-disabled={ ! b.available }
							className={
								'neural-forms-templates__builder' +
								( isActive ? ' is-active' : '' ) +
								( ! b.available ? ' is-disabled' : '' )
							}
							onClick={ () => onBuilderChange( b.slug ) }
							disabled={ ! b.available }
						>
							<span className="neural-forms-templates__builder-label">{ b.label }</span>
							{ showsPill && (
								<span className="neural-forms-templates__builder-pill neural-forms-templates__builder-pill--pro">
									{ b.pill }
								</span>
							) }
						</button>
					);
				} ) }
			</nav>

			{ !! importSuccess && (
				<Notice
					status="success"
					isDismissible
					onRemove={ () => setImportSuccess( null ) }
					className="neural-forms-templates__notice"
				>
					{ __( 'Template imported successfully.', 'neural-forms-lite' ) }
					{ importSuccess.formIds.length > 0 && (
						<>
							{ ' ' }
							<span className="neural-forms-templates__notice-meta">
								{ sprintf(
									/* translators: %d: number of forms imported alongside the page. */
									_n( '%d form imported.', '%d forms imported.', importSuccess.formIds.length, 'neural-forms-lite' ),
									importSuccess.formIds.length
								) }
							</span>
						</>
					) }
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

			{ /* Filter bar + search live on the same row at desktop
			     widths; the search input wraps to a new line on
			     narrow viewports via flex-wrap. */ }
			<div className="neural-forms-templates__toolbar">
				<nav className="neural-forms-templates__filters" role="tablist" aria-label={ __( 'Filter templates', 'neural-forms-lite' ) }>
					{ categoryFilters.map( ( f ) => (
						<button
							key={ f.id }
							type="button"
							role="tab"
							aria-selected={ activeCategory === f.id }
							className={
								'neural-forms-templates__filter' +
								( activeCategory === f.id ? ' is-active' : '' )
							}
							onClick={ () => setActiveCategory( f.id ) }
						>
							<span>{ f.label }</span>
							{ f.count > 0 && (
								<span className="neural-forms-templates__filter-count">{ f.count }</span>
							) }
						</button>
					) ) }
				</nav>
				<div className="neural-forms-templates__search">
					<input
						type="search"
						placeholder={ __( 'Search templates…', 'neural-forms-lite' ) }
						value={ searchQuery }
						onChange={ ( e ) => setSearchQuery( e.target.value ) }
						aria-label={ __( 'Search templates', 'neural-forms-lite' ) }
					/>
				</div>
			</div>

			{ /*
			 * Count strip — only renders when the catalog is loaded
			 * with at least one template so empty / error states
			 * don't carry a confusing "Showing 0 of 0".
			 */ }
			{ ! loading && ! error && ! catalog?.service_unavailable && ! catalog?.parse_error && Array.isArray( catalog?.templates ) && catalog.templates.length > 0 && (
				<div className="neural-forms-templates__count" aria-live="polite">
					{ sprintf(
						/* translators: 1: count of templates matching the active filter / search. 2: total count in the catalog. */
						__( 'Showing %1$d of %2$d templates', 'neural-forms-lite' ),
						filteredTemplates.length,
						catalog.templates.length
					) }
				</div>
			) }

			{ /* Body states — skeleton while initial load, distinct
			     notices for the two server-error flavors, friendly
			     empty for "filter matched nothing", actual grid
			     otherwise. */ }
			{ loading && (
				<SkeletonGrid />
			) }

			{ ! loading && error && (
				<Notice status="error" isDismissible={ false } className="neural-forms-templates__notice">
					{ error }
				</Notice>
			) }

			{ ! loading && ! error && catalog?.parse_error && (
				<Notice status="error" isDismissible={ false } className="neural-forms-templates__notice">
					<p>
						<strong>{ __( 'The template catalog file is not valid JSON.', 'neural-forms-lite' ) }</strong>
					</p>
					{ !! catalog.parse_error_message && (
						<p>
							{ __( 'Parser detail:', 'neural-forms-lite' ) }{ ' ' }
							<code>{ catalog.parse_error_message }</code>
						</p>
					) }
					{ !! catalog.catalog_url && (
						<p className="neural-forms-templates__empty-hint">
							{ __( 'Source:', 'neural-forms-lite' ) }{ ' ' }
							<a href={ catalog.catalog_url } target="_blank" rel="noreferrer">
								<code>{ catalog.catalog_url }</code>
							</a>
							{ ' — ' }
							{ __( 'fix the JSON on the server, then click Refresh Catalog above.', 'neural-forms-lite' ) }
						</p>
					) }
				</Notice>
			) }

			{ ! loading && ! error && catalog?.service_unavailable && (
				<div className="neural-forms-templates__empty">
					<p>
						<strong>{ __( 'Unable to load templates.', 'neural-forms-lite' ) }</strong>
					</p>
					<p className="neural-forms-templates__empty-hint">
						{ __( 'Please check your connection or click Refresh Catalog above.', 'neural-forms-lite' ) }
					</p>
				</div>
			) }

			{ ! loading && ! error && ! catalog?.service_unavailable && ! catalog?.parse_error && filteredTemplates.length === 0 && (
				<div className="neural-forms-templates__empty">
					<p><strong>{ __( 'No templates found.', 'neural-forms-lite' ) }</strong></p>
					<p className="neural-forms-templates__empty-hint">
						{ __( 'Try changing the category or search keyword.', 'neural-forms-lite' ) }
					</p>
				</div>
			) }

			{ ! loading && ! error && filteredTemplates.length > 0 && (
				<div className="neural-forms-templates__grid" role="list">
					{ filteredTemplates.map( ( tpl ) => (
						<TemplateCard
							key={ tpl.id }
							tpl={ tpl }
							importing={ importing === tpl.id }
							onImport={ onImport }
							onOpenLightbox={ ( src, alt ) => setLightbox( { src, alt } ) }
						/>
					) ) }
				</div>
			) }

			{ !! lightbox && (
				<Lightbox
					src={ lightbox.src }
					alt={ lightbox.alt }
					onClose={ () => setLightbox( null ) }
				/>
			) }
		</div>
	);
}

/**
 * Six skeleton cards that mirror the real card geometry — same grid
 * cell size, same internal sections — so the layout doesn't shift
 * when the real data lands. Animation is CSS-only (shimmer keyframes
 * in templates.css).
 */
function SkeletonGrid() {
	const items = new Array( SKELETON_COUNT ).fill( 0 );
	return (
		<div className="neural-forms-templates__grid neural-forms-templates__grid--skeleton" aria-hidden="true">
			{ items.map( ( _, i ) => (
				<div key={ i } className="neural-forms-templates__card neural-forms-templates__card--skeleton">
					<div className="neural-forms-templates__thumb neural-forms-templates__thumb--skeleton" />
					<div className="neural-forms-templates__body">
						<div className="neural-forms-templates__skel-line neural-forms-templates__skel-line--short" />
						<div className="neural-forms-templates__skel-line" />
						<div className="neural-forms-templates__skel-line neural-forms-templates__skel-line--med" />
					</div>
				</div>
			) ) }
		</div>
	);
}

function TemplateCard( { tpl, importing, onImport, onOpenLightbox } ) {
	const cardLabel  = tpl.name || tpl.id;
	const isImported = !! tpl.imported;
	const importedAt = tpl.imported_at || '';
	const editUrl    = tpl.imported_edit_url || '';

	const compat = Array.isArray( tpl.compatibility ) ? tpl.compatibility : [];
	const showsBlockEditor =
		compat.indexOf( 'block-editor' ) !== -1 ||
		compat.indexOf( 'gutenberg' ) !== -1;

	return (
		<article
			className={
				'neural-forms-templates__card' +
				( isImported ? ' is-imported' : '' )
			}
			role="listitem"
		>
			<div className="neural-forms-templates__thumb-wrap">
				{ tpl.thumbnail ? (
					<button
						type="button"
						className="neural-forms-templates__thumb neural-forms-templates__thumb--clickable"
						onClick={ () => onOpenLightbox( tpl.thumbnail, cardLabel ) }
						aria-label={ /* translators: %s: template name */
							sprintf( __( 'Preview %s thumbnail in lightbox', 'neural-forms-lite' ), cardLabel )
						}
					>
						<img src={ tpl.thumbnail } alt={ cardLabel } loading="lazy" />
					</button>
				) : (
					<div className="neural-forms-templates__thumb">
						<div className="neural-forms-templates__thumb-placeholder" aria-hidden="true" />
					</div>
				) }

				{ /* Compatibility chip pinned bottom-left over the
				     thumbnail. Imported badge pins top-right and uses
				     the brand-green/navy palette per the spec. */ }
				{ showsBlockEditor && (
					<span className="neural-forms-templates__compat-chip">
						{ __( 'Block Editor', 'neural-forms-lite' ) }
					</span>
				) }

				{ isImported && (
					<span className="neural-forms-templates__imported-badge">
						<span aria-hidden="true" className="neural-forms-templates__imported-tick">✓</span>
						<span>{ __( 'Imported', 'neural-forms-lite' ) }</span>
					</span>
				) }
			</div>

			<div className="neural-forms-templates__body">
				{ !! tpl.category && (
					<div className="neural-forms-templates__eyebrow">{ tpl.category }</div>
				) }
				<h2 className="neural-forms-templates__card-title">{ cardLabel }</h2>

				{ !! tpl.description && (
					<p className="neural-forms-templates__card-desc">{ tpl.description }</p>
				) }

				{ Array.isArray( tpl.tags ) && tpl.tags.length > 0 && (
					<div className="neural-forms-templates__tags">
						{ tpl.tags.slice( 0, 3 ).map( ( tag ) => (
							<span key={ tag } className="neural-forms-templates__tag">{ tag }</span>
						) ) }
					</div>
				) }
			</div>

			<footer className="neural-forms-templates__actions">
				<div className="neural-forms-templates__actions-row">
					<Button
						variant="secondary"
						href={ tpl.preview_url || undefined }
						target="_blank"
						rel="noopener noreferrer"
						disabled={ ! tpl.preview_url }
					>
						{ __( 'Preview', 'neural-forms-lite' ) }
					</Button>
					<Button
						variant="primary"
						onClick={ () => onImport( tpl ) }
						isBusy={ importing }
						disabled={ importing || ! tpl.import_url }
					>
						{ importing
							? __( 'Importing…', 'neural-forms-lite' )
							: isImported
								? __( 'Import Again', 'neural-forms-lite' )
								: __( 'Import', 'neural-forms-lite' ) }
					</Button>
				</div>

				{ isImported && ( !! editUrl || !! importedAt ) && (
					<div className="neural-forms-templates__imported-meta">
						{ !! importedAt && (
							<span className="neural-forms-templates__imported-when">
								{ sprintf(
									/* translators: %s: friendly long date the template was imported, e.g. "May 18, 2026". */
									__( 'Imported on %s', 'neural-forms-lite' ),
									formatImportedDate( importedAt )
								) }
							</span>
						) }
						{ !! editUrl && !! importedAt && (
							<span className="neural-forms-templates__imported-sep" aria-hidden="true"> · </span>
						) }
						{ !! editUrl && (
							<a href={ editUrl } target="_blank" rel="noreferrer" className="neural-forms-templates__edit-link">
								{ __( 'Edit page', 'neural-forms-lite' ) }
							</a>
						) }
					</div>
				) }
			</footer>
		</article>
	);
}

/**
 * Self-contained lightbox overlay. No third-party deps; ESC, backdrop
 * click, and the × button all close. Body scroll is locked while open.
 */
function Lightbox( { src, alt, onClose } ) {
	useEffect( () => {
		function onKey( e ) {
			if ( e.key === 'Escape' ) onClose();
		}
		document.addEventListener( 'keydown', onKey );
		const previousOverflow = document.body.style.overflow;
		document.body.style.overflow = 'hidden';
		return () => {
			document.removeEventListener( 'keydown', onKey );
			document.body.style.overflow = previousOverflow;
		};
	}, [ onClose ] );

	return (
		<div
			className="neural-forms-templates__lightbox"
			role="dialog"
			aria-modal="true"
			aria-label={ __( 'Template preview', 'neural-forms-lite' ) }
			onClick={ onClose }
		>
			<button
				type="button"
				className="neural-forms-templates__lightbox-close"
				onClick={ onClose }
				aria-label={ __( 'Close preview', 'neural-forms-lite' ) }
			>×</button>
			<img
				src={ src }
				alt={ alt }
				className="neural-forms-templates__lightbox-image"
				onClick={ ( e ) => e.stopPropagation() }
			/>
		</div>
	);
}
