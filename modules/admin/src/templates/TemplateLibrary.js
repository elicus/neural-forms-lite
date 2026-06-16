/**
 * Reusable Template Library UI.
 *
 * Same DOM/UX as the Templates admin page — builder tabs, category
 * pills, search, card grid, import/imported badges, lightbox preview.
 * Rendered in two contexts:
 *
 *   `<TemplateLibrary mode="page" />`   — admin Templates page (full hero)
 *   `<TemplateLibrary mode="modal" />`  — popup from Forms "+ New Form → Use Template"
 *
 * The component owns all fetch / filter / import state. In modal mode
 * the page-level hero (title + subtitle + Refresh button) is hidden —
 * the modal chrome supplies its own header. The host can drive a
 * refresh via the imperative handle:
 *
 *   const ref = useRef();
 *   <TemplateLibrary ref={ref} mode="modal" />
 *   ref.current.refresh();
 */

import { useEffect, useImperativeHandle, useMemo, useState, forwardRef } from '@wordpress/element';
import { __, sprintf, _n } from '@wordpress/i18n';
import { Button, Notice } from '@wordpress/components';
import api from '../api';
import '../templates.css';

// Block Editor is the only importable builder with templates today.
// The Divi tab is clickable when Neural Forms Pro is installed (so users
// can see Pro is wired up) but shows a "Coming soon" empty state — the
// Divi template pack isn't shipping yet. Without Pro, the tab stays
// locked with the PRO pill.
const PRO_INSTALLED = !! ( typeof window !== 'undefined'
	&& window.neuralFormsAdmin
	&& window.neuralFormsAdmin.pro
	&& window.neuralFormsAdmin.pro.version );

const BUILDERS = [
	{ slug: 'block-editor', label: __( 'Block Editor', 'neural-forms-lite' ), available: true,            pill: '' },
	{ slug: 'divi',         label: __( 'Divi',         'neural-forms-lite' ), available: PRO_INSTALLED,   pill: PRO_INSTALLED ? '' : __( 'PRO', 'neural-forms-lite' ) },
];

const SKELETON_COUNT = 6;

/**
 * Normalise the catalog's `category` field into an array of trimmed
 * non-empty strings. Accepts:
 *
 *   - undefined / null / ''   → []
 *   - "Contact Us"            → [ "Contact Us" ]
 *   - "Contact Us, Lead"      → [ "Contact Us", "Lead" ]   (legacy CSV)
 *   - [ "Contact Us", "Lead" ]→ [ "Contact Us", "Lead" ]
 *   - other                   → []
 *
 * The remote catalog ships `category` as an array since 2026-05; the
 * older single-string + CSV shapes are kept for back-compat with any
 * mirror or future tooling that still emits the legacy form. No
 * de-duplication beyond order-preserving — caller decides if it cares.
 */
function normaliseCategories( raw ) {
	if ( Array.isArray( raw ) ) {
		return raw
			.map( ( c ) => String( c ).trim() )
			.filter( ( c ) => c !== '' );
	}
	if ( typeof raw === 'string' ) {
		return raw
			.split( ',' )
			.map( ( c ) => c.trim() )
			.filter( ( c ) => c !== '' );
	}
	return [];
}

function formatImportedDate( raw ) {
	if ( ! raw ) return '';
	const d = new Date( String( raw ).replace( ' ', 'T' ) );
	if ( isNaN( d.getTime() ) ) return String( raw );
	return d.toLocaleDateString( undefined, { month: 'long', day: 'numeric', year: 'numeric' } );
}

const TemplateLibrary = forwardRef( function TemplateLibrary( {
	mode = 'page',
	subtitle,
	onImported,
	onStateChange,
}, ref ) {
	const [ builder,         setBuilder         ] = useState( 'block-editor' );
	const [ catalog,         setCatalog         ] = useState( null );
	const [ loading,         setLoading         ] = useState( true );
	const [ error,           setError           ] = useState( '' );
	const [ activeCategory,  setActiveCategory  ] = useState( 'all' );
	const [ searchQuery,     setSearchQuery     ] = useState( '' );
	const [ refreshing,      setRefreshing      ] = useState( false );
	const [ importing,       setImporting       ] = useState( '' );
	const [ importSuccess,   setImportSuccess   ] = useState( null );
	const [ importError,     setImportError     ] = useState( '' );
	const [ lightbox,        setLightbox        ] = useState( null );

	function loadCatalog( forceRefresh = false, builderSlug = builder ) {
		// Divi pack hasn't shipped yet — short-circuit the API call and
		// render the "coming soon" empty state instead of hitting the
		// backend (which would 400 the unsupported builder slug).
		if ( builderSlug === 'divi' ) {
			setCatalog( {
				builder:    'divi',
				templates:  [],
				updated_at: '',
				coming_soon: true,
			} );
			setLoading( false );
			setRefreshing( false );
			setError( '' );
			return Promise.resolve();
		}

		const path = '/templates?builder=' + encodeURIComponent( builderSlug )
			+ ( forceRefresh ? '&refresh=1' : '' );
		if ( forceRefresh ) setRefreshing( true );
		else setLoading( true );
		setError( '' );

		// Hard-timeout race: api() races against a 15 s timer. Whichever
		// settles first wins. This guarantees the spinner can never stick
		// for more than ~15 s regardless of:
		//   - api-fetch never resolving (rare but possible — e.g.
		//     server-side wp_remote_get genuinely hanging past PHP's
		//     own timeout, browser-extension interference, ORB
		//     blocking the underlying transport)
		//   - a state-setter inside .then() throwing before .finally
		//   - any React render-layer oddity that would skip .finally
		// On timeout the catalog stays at whatever it was previously
		// (empty array if first load) and an inline error message is
		// shown via setError.
		const timeoutMs   = 15000;
		const timeoutErr  = new Error( __( 'Refresh timed out. Please try again.', 'neural-forms-lite' ) );
		const timeoutWin  = new Promise( ( _, reject ) => {
			setTimeout( () => reject( timeoutErr ), timeoutMs );
		} );

		return Promise.race( [ api( path ), timeoutWin ] )
			.then( ( res ) => {
				setCatalog( res );
			} )
			.catch( ( err ) => {
				setError( ( err && err.message ) || __( 'Failed to load templates.', 'neural-forms-lite' ) );
			} )
			.finally( () => {
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

	// Watchdog: belt-and-suspenders guarantee that no "Refreshing…" /
	// "Loading…" state can stay stuck longer than the timeout below.
	// Even if api()'s Promise never settles (browser extension, network
	// stall, dev-build invariant, anything weird), the spinner clears
	// itself so the Refresh button stays usable. The cleanup function
	// clears the timer the moment the state goes false through the
	// normal .finally() path, so the watchdog only ever fires on a
	// genuine hang.
	useEffect( () => {
		if ( ! refreshing ) return undefined;
		const t = setTimeout( () => setRefreshing( false ), 25000 );
		return () => clearTimeout( t );
	}, [ refreshing ] );
	useEffect( () => {
		if ( ! loading ) return undefined;
		const t = setTimeout( () => setLoading( false ), 25000 );
		return () => clearTimeout( t );
	}, [ loading ] );

	// Push header-relevant state to the modal whenever it changes. The
	// modal can't read this from the imperative ref reliably — it only
	// re-renders when its own state changes — so we hand the values out
	// proactively. The optional chaining means page-mode (no parent
	// listener) costs nothing.
	useEffect( () => {
		if ( typeof onStateChange === 'function' ) {
			onStateChange( {
				updatedAt: catalog?.updated_at || '',
				loading,
				refreshing,
			} );
		}
	}, [ catalog, loading, refreshing, onStateChange ] );

	useImperativeHandle( ref, () => ( {
		refresh:    () => loadCatalog( true, builder ),
		isLoading:  () => loading,
		isRefreshing: () => refreshing,
		getUpdatedAt: () => catalog?.updated_at || '',
	} ), [ builder, loading, refreshing, catalog ] );

	const categoryFilters = useMemo( () => {
		const rows = Array.isArray( catalog?.templates ) ? catalog.templates : [];
		const counts = new Map();
		// One template can belong to multiple categories. We count it
		// once per category so the filter pills surface each bucket's
		// real size, and the same card shows up in every matching
		// filter — same UX as a multi-tag library.
		rows.forEach( ( t ) => {
			normaliseCategories( t.category ).forEach( ( c ) => {
				counts.set( c, ( counts.get( c ) || 0 ) + 1 );
			} );
		} );
		const cats = Array.from( counts.entries() )
			.sort( ( a, b ) => a[ 0 ].localeCompare( b[ 0 ] ) )
			.map( ( [ label, count ] ) => ( { id: label.toLowerCase(), label, count } ) );
		return [
			{ id: 'all', label: __( 'All', 'neural-forms-lite' ), count: rows.length },
			...cats,
		];
	}, [ catalog ] );

	const filteredTemplates = useMemo( () => {
		const rows = Array.isArray( catalog?.templates ) ? catalog.templates : [];
		const q    = searchQuery.trim().toLowerCase();
		return rows.filter( ( t ) => {
			const cats = normaliseCategories( t.category );
			if ( activeCategory !== 'all' ) {
				const hit = cats.some( ( c ) => c.toLowerCase() === activeCategory );
				if ( ! hit ) return false;
			}
			if ( q !== '' ) {
				const haystack = [
					t.name || '',
					t.description || '',
					cats.join( ' ' ),
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
				const payload = {
					template:   tpl,
					pageId:     res?.page_id || 0,
					editUrl:    res?.edit_url || '',
					viewUrl:    res?.view_url || '',
					formIds:    Array.isArray( res?.imported_form_ids ) ? res.imported_form_ids : [],
					formIdMap:  res?.form_id_map && typeof res.form_id_map === 'object' ? res.form_id_map : {},
				};
				setImportSuccess( payload );
				loadCatalog( true, builder );
				if ( typeof onImported === 'function' ) {
					try { onImported( payload ); } catch ( e ) { /* host callback errors must not break the modal */ }
				}
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
		setActiveCategory( 'all' );
		setSearchQuery( '' );
		setBuilder( nextBuilder );
	}

	const updatedAt   = catalog?.updated_at || '';
	const showHero    = mode !== 'modal';
	const heroSubtitle = subtitle || __( 'Import professionally designed Neural Forms pages into your website in one click.', 'neural-forms-lite' );

	return (
		<div className={ 'neural-forms-templates' + ( mode === 'modal' ? ' neural-forms-templates--modal' : '' ) }>
			{ showHero && (
				<header className="neural-forms-templates__hero">
					<div className="neural-forms-templates__hero-text">
						<h1 className="neural-forms-templates__title">
							{ __( 'Template Library', 'neural-forms-lite' ) }
						</h1>
						<p className="neural-forms-templates__subtitle">{ heroSubtitle }</p>
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
			) }

			<nav className="neural-forms-templates__builders" role="tablist" aria-label={ __( 'Builder', 'neural-forms-lite' ) }>
				{ BUILDERS.map( ( b ) => {
					const isActive = b.slug === builder;
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

			<div className="neural-forms-templates__toolbar" hidden={ !! catalog?.coming_soon }>
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
				<div
					className={
						'neural-forms-templates__search' +
						( searchQuery ? ' has-query' : '' )
					}
				>
					<svg
						className="neural-forms-templates__search-icon"
						width="16"
						height="16"
						viewBox="0 0 24 24"
						fill="none"
						aria-hidden="true"
					>
						<circle cx="11" cy="11" r="7" stroke="currentColor" strokeWidth="2" />
						<line x1="21" y1="21" x2="16.65" y2="16.65" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
					</svg>
					<input
						type="search"
						placeholder={ __( 'Search templates…', 'neural-forms-lite' ) }
						value={ searchQuery }
						onChange={ ( e ) => setSearchQuery( e.target.value ) }
						aria-label={ __( 'Search templates', 'neural-forms-lite' ) }
					/>
					{ searchQuery && (
						<button
							type="button"
							className="neural-forms-templates__search-clear"
							onClick={ () => setSearchQuery( '' ) }
							aria-label={ __( 'Clear search', 'neural-forms-lite' ) }
						>
							<svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true">
								<line x1="6" y1="6" x2="18" y2="18" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
								<line x1="18" y1="6" x2="6" y2="18" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
							</svg>
						</button>
					) }
				</div>
			</div>

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

			{ loading && <SkeletonGrid /> }

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

			{ ! loading && ! error && catalog?.coming_soon && (
				<div className="neural-forms-templates__empty neural-forms-templates__empty--coming-soon">
					<p><strong>{ __( 'Divi templates coming soon.', 'neural-forms-lite' ) }</strong></p>
					<p className="neural-forms-templates__empty-hint">
						{ __( 'We\'re curating a Divi template pack. In the meantime, browse the Block Editor library.', 'neural-forms-lite' ) }
					</p>
				</div>
			) }

			{ ! loading && ! error && ! catalog?.coming_soon && ! catalog?.service_unavailable && ! catalog?.parse_error && filteredTemplates.length === 0 && (
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
} );

export default TemplateLibrary;

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
				{ /* Eyebrow surfaces the template's category list. The
				     remote catalog can ship either a single string or
				     a real array; normaliseCategories handles both
				     shapes and we join with " · " so multi-category
				     templates read as one tidy line. */ }
				{ (() => {
					const cats = normaliseCategories( tpl.category );
					if ( cats.length === 0 ) return null;
					return (
						<div className="neural-forms-templates__eyebrow">{ cats.join( ' · ' ) }</div>
					);
				} )() }
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
