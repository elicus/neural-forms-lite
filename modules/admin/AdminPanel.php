<?php
namespace NeuralForms\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Neural Forms admin menu and enqueues assets.
 *
 * Each page renders a single <div id="neural-forms-app"> mount point.
 * The React app (Step 7) handles all routing and UI from there.
 *
 * Assets are enqueued only on Neural Forms pages - never on unrelated
 * WP admin screens.
 */
class AdminPanel {

    /** Slug of the top-level menu item. Used to detect Neural Forms pages. */
    const MENU_SLUG = 'neural-forms';

    /**
     * Maps WP page slugs to clean screen identifiers passed to the React app.
     * The first entry is also the default - no magic string needed anywhere.
     */
    const SCREENS = [
        'neural-forms'           => 'forms',
        'neural-forms-entries'   => 'entries',
        'neural-forms-stats'     => 'stats',
        'neural-forms-settings'  => 'settings',
        'neural-forms-migration' => 'migration',
        'neural-forms-results'   => 'results',
    ];

    public static function boot(): void {
        add_action( 'admin_menu',            [ self::class, 'register_menus' ] );
        add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
        add_action( 'in_admin_header',       [ self::class, 'suppress_third_party_notices' ], 1000 );
    }

    /**
     * Strip third-party admin_notices on Neural Forms screens so they don't
     * crash into the React layout. Mirrors the pattern Yoast / Rank Math /
     * Wordfence use on their own admin pages. We keep WordPress core notices
     * (security updates, etc.) - only callbacks registered by *other* plugins
     * are removed.
     */
    public static function suppress_third_party_notices(): void {
        if ( ! function_exists( 'get_current_screen' ) ) {
            return;
        }
        $screen = get_current_screen();
        if ( ! $screen || strpos( (string) $screen->id, 'neural-forms' ) === false ) {
            return;
        }
        remove_all_actions( 'admin_notices' );
        remove_all_actions( 'all_admin_notices' );
    }

    // Menus

    public static function register_menus(): void {
        // Top-level menu - slug 'neural-forms' so clicking the parent item
        // opens the Forms screen directly. MENU_SLUG is used by
        // is_neural_forms_page() for hook detection.
        add_menu_page(
            __( 'Neural Forms', 'neural-forms-lite' ),           // page <title>
            __( 'Neural Forms', 'neural-forms-lite' ),           // menu label
            NEURAL_FORMS_CAP_MANAGE_FORMS,
            'neural-forms',                      // landing page = Forms
            [ self::class, 'render_page' ],
            'dashicons-feedback',
            30
        );

        // WordPress auto-creates a first submenu item with slug 'neural-forms';
        // rename it to "Forms" so the label matches the destination.
        add_submenu_page(
            'neural-forms',
            __( 'Forms - Neural Forms', 'neural-forms-lite' ),
            __( 'Forms', 'neural-forms-lite' ),
            NEURAL_FORMS_CAP_MANAGE_FORMS,
            'neural-forms',
            [ self::class, 'render_page' ]
        );

        // Match WP core's Plugins-update bubble: same markup, same classes,
        // so the existing admin CSS styles it without any new rules.
        $unread_count = self::unread_entries_count();
        $entries_label = __( 'Entries', 'neural-forms-lite' );
        if ( $unread_count > 0 ) {
            $entries_label .= sprintf(
                ' <span class="awaiting-mod count-%1$d"><span class="pending-count">%2$s</span></span>',
                (int) $unread_count,
                esc_html( number_format_i18n( $unread_count ) )
            );
        }

        add_submenu_page(
            'neural-forms',
            __( 'Entries - Neural Forms', 'neural-forms-lite' ),
            $entries_label,
            NEURAL_FORMS_CAP_MANAGE_FORMS,
            'neural-forms-entries',
            [ self::class, 'render_page' ]
        );

        add_submenu_page(
            'neural-forms',
            __( 'Stats - Neural Forms', 'neural-forms-lite' ),
            __( 'Stats', 'neural-forms-lite' ),
            NEURAL_FORMS_CAP_MANAGE_FORMS,
            'neural-forms-stats',
            [ self::class, 'render_page' ]
        );

        // Analytics screen (1.1.0). Independent split bundle — does NOT
        // share build/index.js with Forms/Entries/Settings/Stats. The
        // dedicated render_results_page() callback emits the
        // #neural-forms-results-app root that the analytics bundle
        // mounts into.
        //
        // The route slug stays `neural-forms-results` for backwards
        // compatibility with any deep links / bookmarks that already
        // exist in the wild — only the user-facing label moved from
        // "Results" to "Analytics".
        add_submenu_page(
            'neural-forms',
            __( 'Form Analytics - Neural Forms', 'neural-forms-lite' ),
            __( 'Analytics', 'neural-forms-lite' ),
            NEURAL_FORMS_CAP_MANAGE_FORMS,
            'neural-forms-results',
            [ self::class, 'render_results_page' ]
        );

        add_submenu_page(
            'neural-forms',
            __( 'Settings - Neural Forms', 'neural-forms-lite' ),
            __( 'Settings', 'neural-forms-lite' ),
            NEURAL_FORMS_CAP_MANAGE_FORMS,
            'neural-forms-settings',
            [ self::class, 'render_page' ]
        );

        // Import is only useful when there's something to import from. CF7
        // is the only supported source today, so hide the menu entry on
        // sites that don't have it active.
        if ( function_exists( 'is_plugin_active' ) && is_plugin_active( 'contact-form-7/wp-contact-form-7.php' ) ) {
            add_submenu_page(
                'neural-forms',
                __( 'Import - Neural Forms', 'neural-forms-lite' ),
                __( 'Import', 'neural-forms-lite' ),
                NEURAL_FORMS_CAP_MANAGE_FORMS,
                'neural-forms-migration',
                [ self::class, 'render_page' ]
            );
        }
    }

    /**
     * Unread (status='new') entry count across all forms, for the menu badge.
     *
     * Wrapped in try/catch so a DB hiccup during admin_menu (e.g. mid-upgrade
     * before dbDelta has run) never fatals the menu - we just skip the badge.
     */
    private static function unread_entries_count(): int {
        try {
            $counts = \NeuralForms\Storage\EntryRepository::count_by_view();
            return isset( $counts['unread'] ) ? (int) $counts['unread'] : 0;
        } catch ( \Throwable $e ) {
            return 0;
        }
    }

    // Page renderer

    /**
     * All Neural Forms pages share one render callback.
     * The React app reads window.neuralFormsAdmin.screen - set by PHP - to decide
     * which screen to show. It never parses the URL itself.
     */
    public static function render_page(): void {
        ?>
        <div class="wrap">
            <div id="neural-forms-app"></div>
        </div>
        <?php
    }

    /**
     * Dedicated render callback for the Results screen. Emits a
     * separate root so the results bundle does not collide with
     * the rich admin bundle's #neural-forms-app root.
     */
    public static function render_results_page(): void {
        $bundle_missing = ! file_exists( NEURAL_FORMS_PATH . 'modules/admin/build/results.js' );
        ?>
        <div class="wrap">
            <?php if ( $bundle_missing ) : ?>
                <div class="notice notice-warning"><p>
                    <strong><?php esc_html_e( 'Neural Forms: Results bundle not built.', 'neural-forms-lite' ); ?></strong>
                    <?php esc_html_e( 'Run', 'neural-forms-lite' ); ?>
                    <code>npm run build:results</code>
                    <?php esc_html_e( 'inside modules/admin/ to enable this screen.', 'neural-forms-lite' ); ?>
                </p></div>
            <?php endif; ?>
            <div id="neural-forms-results-app"></div>
        </div>
        <?php
    }

    // Assets

    /**
     * Enqueue the compiled admin bundle only on Neural Forms screens.
     *
     * $hook examples:
     *   toplevel_page_neural-forms
     *   neural-forms_page_neural-forms-entries
     *   neural-forms_page_neural-forms-stats
     *   neural-forms_page_neural-forms-settings
     */
    public static function enqueue_assets( string $hook ): void {
        if ( ! self::is_neural_forms_page( $hook ) ) {
            return;
        }

        $build_dir = NEURAL_FORMS_PATH . 'modules/admin/build/';
        $build_url = NEURAL_FORMS_URL  . 'modules/admin/build/';

        // Results screen split: independent bundle, never overlaps
        // with the rich admin bundle. Enqueue results.js on
        // ?page=neural-forms-results, and ALWAYS short-circuit out of
        // this function for that screen — even when the bundle is
        // missing — because the rich index.js mounts at a different
        // root (#neural-forms-app) and would render Forms-list chrome
        // on the Results page. The render callback shows a graceful
        // "missing bundle" notice instead.
        if ( self::is_results_screen() ) {
            if ( file_exists( $build_dir . 'results.js' ) ) {
                $asset_file = $build_dir . 'results.asset.php';
                $asset      = file_exists( $asset_file )
                    ? require $asset_file
                    : [ 'dependencies' => [], 'version' => NEURAL_FORMS_VERSION ];

                // Defensive: results.asset.php should be an array with
                // 'dependencies' and 'version' keys, but a malformed
                // file should never fatal the admin page.
                if ( ! is_array( $asset ) ) {
                    $asset = [ 'dependencies' => [], 'version' => NEURAL_FORMS_VERSION ];
                }
                $deps    = isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] ) ? $asset['dependencies'] : [];
                $version = isset( $asset['version'] ) && is_string( $asset['version'] ) ? $asset['version'] : NEURAL_FORMS_VERSION;

                wp_enqueue_script(
                    'neural-forms-results',
                    $build_url . 'results.js',
                    $deps,
                    $version,
                    true
                );

                wp_localize_script( 'neural-forms-results', 'neuralFormsAdmin', self::script_data() );

                if ( file_exists( $build_dir . 'results.css' ) ) {
                    wp_enqueue_style(
                        'neural-forms-results',
                        $build_url . 'results.css',
                        [ 'wp-components' ],
                        (string) filemtime( $build_dir . 'results.css' )
                    );
                }
            }
            return;
        }

        // Builder screen split: when the URL is the form editor
        // (?page=neural-forms&edit=N or &new=1) AND a builder.js bundle
        // exists alongside the rich admin bundle, enqueue the builder
        // bundle INSTEAD of index.js. This is the 1.1.0 split-bundle
        // path - the rich Forms / Entries / Settings / Stats screens
        // continue to ship from build/index.js, while the new 3-panel
        // builder ships independently as build/builder.js. The two
        // bundles are never both alive on the same page.

        $is_builder_screen = self::is_builder_screen();
        $use_builder_split = $is_builder_screen && file_exists( $build_dir . 'builder.js' );

        if ( $use_builder_split ) {
            $asset_file = $build_dir . 'builder.asset.php';
            $asset      = file_exists( $asset_file )
                ? require $asset_file
                : [ 'dependencies' => [], 'version' => NEURAL_FORMS_VERSION ];

            // Cache-bust: combine webpack's content hash with the file
            // mtime. The hash alone is supposed to change on every code
            // change, but a stale `builder.asset.php` (e.g. partial
            // rebuild, webpack quirk on a no-op recompile, or a
            // committed older version) can leave the URL unchanged and
            // make the browser serve the cached bundle. The mtime suffix
            // guarantees a fresh URL whenever the file actually changes
            // on disk — at the cost of one extra cache-miss per real
            // edit, which is what we want during development.
            $bust    = (string) filemtime( $build_dir . 'builder.js' );
            $version = ( $asset['version'] ?? NEURAL_FORMS_VERSION ) . '.' . $bust;

            wp_enqueue_script(
                'neural-forms-builder',
                $build_url . 'builder.js',
                $asset['dependencies'],
                $version,
                true
            );

            wp_localize_script( 'neural-forms-builder', 'neuralFormsAdmin', self::script_data() );

            if ( file_exists( $build_dir . 'builder.css' ) ) {
                wp_enqueue_style(
                    'neural-forms-builder',
                    $build_url . 'builder.css',
                    [ 'wp-components' ],
                    (string) filemtime( $build_dir . 'builder.css' )
                );
            }

            return;
        }

        // Default path: rich 1.0.0 admin bundle (Forms list, Entries
        // inbox, Settings, Stats). Untouched by the split-bundle work.
        //
        // JS - load deps and version from the asset file generated by webpack
        // so they stay in sync with the build automatically.
        if ( file_exists( $build_dir . 'index.js' ) ) {
            $asset_file = $build_dir . 'index.asset.php';
            $asset      = file_exists( $asset_file )
                ? require $asset_file
                : [ 'dependencies' => [], 'version' => NEURAL_FORMS_VERSION ];

            wp_enqueue_script(
                'neural-forms-admin',
                $build_url . 'index.js',
                $asset['dependencies'],
                $asset['version'],
                true
            );

            // Pass server data to the React app.
            wp_localize_script( 'neural-forms-admin', 'neuralFormsAdmin', self::script_data() );
        }

        // CSS - version pinned to filemtime so every rebuild busts the browser cache.
        if ( file_exists( $build_dir . 'index.css' ) ) {
            wp_enqueue_style(
                'neural-forms-admin',
                $build_url . 'index.css',
                [ 'wp-components' ],
                (string) filemtime( $build_dir . 'index.css' )
            );
        }

        // Forms-screen-only: inject a Shortcode column into the React-rendered
        // forms table. Lives outside the React bundle so it can ship without
        // rebuilding (the Node 18+ toolchain isn't available in every dev env
        // yet). Removed once the React Forms screen is rebuilt with the column
        // baked in. The injector reads the slug'->->name map localized below; no
        // extra REST round-trip on page load.
        if ( self::get_current_screen() === 'forms' ) {
            self::enqueue_shortcode_column_injector();
            self::enqueue_builder_navigation_injector();
            self::enqueue_forms_list_meta_injector();
        }

        if ( self::get_current_screen() === 'entries' ) {
            self::enqueue_entries_form_filter_injector();
        }
    }

    /**
     * Forms-list-only: redirect "+ New Form" and row "Edit" clicks to
     * the URL query that the 1.1.0 split-bundle enqueue swap recognises
     * (?new=1 / ?edit=N). Without this, those buttons in the rich 1.0.0
     * bundle flip an internal React state to render the old inline
     * editor without changing the URL - so builder.js never loads and
     * users keep seeing the legacy UI.
     *
     * Strategy: localize a slug->id map at PHP enqueue time, then run a
     * tiny capture-phase delegator on document.click that:
     *   - intercepts `.neural-forms-forms-list__new-btn` -> goes to ?new=1
     *   - intercepts `.neural-forms-forms-list__action-btn--edit` and
     *     `.neural-forms-forms-list__form-name` -> reads the row's
     *     .neural-forms-forms-list__form-slug, looks up id, goes to ?edit=N
     *
     * Capture-phase fires before React's bubble-phase onClick listeners,
     * so e.preventDefault() + e.stopPropagation() suppresses the rich
     * bundle's in-page state swap.
     *
     * Removed once the rich bundle is replaced with a split forms.js
     * that uses href URLs natively. Until then, this is the cheapest
     * non-rebuild fix.
     */
    private static function enqueue_builder_navigation_injector(): void {
        $result = \NeuralForms\Forms\FormRepository::list( [
            'status'   => 'any',
            'per_page' => 200,
            'page'     => 1,
            'orderby'  => 'name',
            'order'    => 'ASC',
        ] );

        $items = is_array( $result['items'] ?? null ) ? $result['items'] : [];
        $slug_to_id = [];
        foreach ( $items as $form ) {
            $slug = (string) ( $form['slug'] ?? '' );
            $id   = (int) ( $form['id'] ?? 0 );
            if ( $slug !== '' && $id > 0 ) {
                $slug_to_id[ $slug ] = $id;
            }
        }

        $base = admin_url( 'admin.php?page=neural-forms' );
        $boot = sprintf(
            'window.NeuralFormsBuilderNav = { slugToId: %s, base: %s };',
            wp_json_encode( $slug_to_id ),
            wp_json_encode( $base )
        );
        wp_add_inline_script( 'neural-forms-admin', $boot, 'before' );

        // Note: the delegator binds at script-eval time. The Forms list
        // is React-rendered async, but document-level capture-phase
        // listeners persist regardless of when the buttons appear.
        $delegator = <<<'JS'
( function () {
    var nav = window.NeuralFormsBuilderNav;
    if ( ! nav || ! nav.base ) return;

    function go( extra ) {
        // base already contains '?page=neural-forms', so '&new=1' / '&edit=N' is correct.
        window.location.href = nav.base + extra;
    }

    document.addEventListener( 'click', function ( e ) {
        if ( ! e.target || typeof e.target.closest !== 'function' ) return;

        // "+ New Form" button on the Forms list.
        if ( e.target.closest( '.neural-forms-forms-list__new-btn' ) ) {
            e.preventDefault();
            e.stopPropagation();
            go( '&new=1' );
            return;
        }

        // Row "Edit" button or clickable form-name cell.
        var editTrigger = e.target.closest(
            '.neural-forms-forms-list__action-btn--edit, .neural-forms-forms-list__form-name'
        );
        if ( editTrigger ) {
            var row = editTrigger.closest( '.neural-forms-forms-list__row' );
            if ( ! row ) return;
            var slugEl = row.querySelector( '.neural-forms-forms-list__form-slug' );
            if ( ! slugEl ) return;
            var slug = ( slugEl.textContent || '' ).trim();
            var id = ( nav.slugToId || {} )[ slug ];
            if ( ! id ) return;
            e.preventDefault();
            e.stopPropagation();
            go( '&edit=' + encodeURIComponent( id ) );
        }
    }, true ); // useCapture: true - fires before React's onClick.
} )();
JS;
        wp_add_inline_script( 'neural-forms-admin', $delegator, 'after' );
    }

    /**
     * Enqueue the inline JS that adds a Shortcode column to the Forms table.
     *
     * Runs after the admin bundle (depends on 'neural-forms-admin') so React
     * has had a tick to mount before the MutationObserver starts watching.
     * The map of shortcodes is localized as a JS global rather than fetched
     * via REST - saves a round-trip and avoids a flash of "no shortcode"
     * before the fetch resolves.
     */
    private static function enqueue_shortcode_column_injector(): void {
        $result = \NeuralForms\Forms\FormRepository::list( [
            'status'   => 'any',
            'per_page' => 200,
            'page'     => 1,
            'orderby'  => 'name',
            'order'    => 'ASC',
        ] );

        $items   = is_array( $result['items'] ?? null ) ? $result['items'] : [];
        $map     = [];
        $by_name = [];
        foreach ( $items as $form ) {
            $slug = (string) ( $form['slug'] ?? '' );
            if ( $slug === '' ) {
                continue;
            }
            $name = (string) ( $form['name'] ?? '' );
            $entry = [
                'id'        => (int) ( $form['id'] ?? 0 ),
                'slug'      => $slug,
                'name'      => $name,
                'shortcode' => '[neural-forms slug="' . $slug . '"]',
            ];
            $map[ $slug ] = $entry;
            if ( $name !== '' ) {
                $by_name[ strtolower( $name ) ] = $entry;
            }
        }

        // The injector script is registered against the same handle used as a
        // dependency, so ordering is deterministic. We use wp_add_inline_script
        // rather than a separate file so the markup remains a single source of
        // truth - no extra round-trip, no risk of a stale cached file.
        $payload = wp_json_encode( [
            'bySlug' => $map,
            'byName' => $by_name,
            'i18n'   => [
                'header' => __( 'Shortcode',          'neural-forms-lite' ),
                'copy'   => __( 'Copy',               'neural-forms-lite' ),
                'copied' => __( 'Copied',             'neural-forms-lite' ),
                'aria'   => __( 'Copy shortcode',     'neural-forms-lite' ),
            ],
        ] );

        $injector = self::shortcode_column_injector_js();

        wp_add_inline_script(
            'neural-forms-admin',
            'window.neuralFormsShortcodeData = ' . $payload . ';',
            'before'
        );
        wp_add_inline_script(
            'neural-forms-admin',
            $injector,
            'after'
        );

        wp_add_inline_style( 'neural-forms-admin', self::shortcode_column_injector_css() );
    }

    /** Vanilla-JS injector - no React, walks the DOM produced by the bundle. */
    private static function shortcode_column_injector_js(): string {
        return <<<'JS'
(function () {
    var data = window.neuralFormsShortcodeData;
    if ( ! data || ! data.bySlug ) { return; }

    var mount = document.getElementById( 'neural-forms-app' );
    if ( ! mount ) { return; }

    /**
     * For each <tr> in the forms table, find the form by:
     *   1. an exact slug match in the row's text content (the React UI shows
     *      the slug in small/code text under the name);
     *   2. an exact form-name match against any link/heading text in the row.
     * Returns the form record from the localized map, or null if no match.
     */
    function resolveFormForRow( row ) {
        var bySlug = data.bySlug || {};
        var byName = data.byName || {};

        // Match by slug - slugs are kebab-case alphanumerics, distinctive enough
        // that a substring match against an exact slug key is reliable.
        var text = row.textContent || '';
        for ( var slug in bySlug ) {
            if ( ! Object.prototype.hasOwnProperty.call( bySlug, slug ) ) { continue; }
            // Word-boundary-ish check: surrounded by non-alphanumeric or string edge
            // so 'contact-us' doesn't false-match against 'contact-us-extended'.
            var re = new RegExp( '(^|[^a-z0-9-])' + slug.replace( /[-/\\^$*+?.()|[\]{}]/g, '\\$&' ) + '($|[^a-z0-9-])' );
            if ( re.test( text ) ) {
                return bySlug[ slug ];
            }
        }

        // Fallback: match by full form name in any link/heading inside the row.
        var nameNodes = row.querySelectorAll( 'a, h1, h2, h3, strong, [class*="name"], [class*="title"]' );
        for ( var i = 0; i < nameNodes.length; i++ ) {
            var t = ( nameNodes[ i ].textContent || '' ).trim().toLowerCase();
            if ( t && byName[ t ] ) {
                return byName[ t ];
            }
        }
        return null;
    }

    function copy( text, btn ) {
        var done = function () {
            var original = btn.dataset.originalLabel || btn.textContent;
            btn.dataset.originalLabel = original;
            btn.classList.add( 'is-copied' );
            btn.textContent = ( data.i18n && data.i18n.copied ) || 'Copied';
            setTimeout( function () {
                btn.classList.remove( 'is-copied' );
                btn.textContent = original;
            }, 1500 );
        };
        var fallback = function () {
            var ta = document.createElement( 'textarea' );
            ta.value = text;
            ta.setAttribute( 'readonly', '' );
            ta.style.position = 'absolute';
            ta.style.left = '-9999px';
            document.body.appendChild( ta );
            ta.select();
            try { document.execCommand( 'copy' ); done(); }
            catch ( _ ) { /* swallow */ }
            document.body.removeChild( ta );
        };
        if ( navigator.clipboard && window.isSecureContext ) {
            navigator.clipboard.writeText( text ).then( done, fallback );
        } else {
            fallback();
        }
    }

    /**
     * Add the Shortcode column to a header <tr>. Idempotent - running twice
     * on the same row is a no-op so MutationObserver re-fires don't duplicate.
     */
    function augmentHeader( headerRow ) {
        if ( headerRow.querySelector( '[data-nf-shortcode-th]' ) ) { return; }
        var th = document.createElement( 'th' );
        th.setAttribute( 'data-nf-shortcode-th', '1' );
        th.className = 'nf-shortcode-col__th';
        th.textContent = ( data.i18n && data.i18n.header ) || 'Shortcode';
        headerRow.appendChild( th );
    }

    /**
     * Add a Shortcode <td> to a body <tr>. Idempotent.
     */
    function augmentRow( row ) {
        if ( row.querySelector( '[data-nf-shortcode-cell]' ) ) { return; }
        var form = resolveFormForRow( row );
        if ( ! form ) { return; }

        var td = document.createElement( 'td' );
        td.setAttribute( 'data-nf-shortcode-cell', '1' );
        td.className = 'nf-shortcode-col__td';

        var wrap = document.createElement( 'div' );
        wrap.className = 'nf-shortcode-col__wrap';

        var code = document.createElement( 'code' );
        code.className = 'nf-shortcode-col__code';
        code.textContent = form.shortcode;
        code.title = form.shortcode; // tooltip when ellipsis-truncated
        code.tabIndex = 0;
        code.addEventListener( 'click', function () {
            var range = document.createRange();
            range.selectNodeContents( code );
            var sel = window.getSelection();
            sel.removeAllRanges();
            sel.addRange( range );
        } );

        var btn = document.createElement( 'button' );
        btn.type = 'button';
        btn.className = 'button nf-shortcode-col__copy';
        btn.textContent = ( data.i18n && data.i18n.copy ) || 'Copy';
        btn.setAttribute( 'aria-label', ( data.i18n && data.i18n.aria ) || 'Copy shortcode' );
        btn.addEventListener( 'click', function () { copy( form.shortcode, btn ); } );

        wrap.appendChild( code );
        wrap.appendChild( btn );
        td.appendChild( wrap );
        row.appendChild( td );
    }

    /**
     * Scan the mount for any forms tables and inject the column into each.
     * Identifies a forms table heuristically - at least one body row whose
     * textContent contains a known slug.
     */
    function scan() {
        var tables = mount.querySelectorAll( 'table' );
        for ( var i = 0; i < tables.length; i++ ) {
            var table = tables[ i ];
            var bodyRows = table.querySelectorAll( 'tbody tr' );
            if ( bodyRows.length === 0 ) { continue; }

            // Quick heuristic: does at least one body row resolve to a known form?
            var matches = false;
            for ( var j = 0; j < bodyRows.length; j++ ) {
                if ( resolveFormForRow( bodyRows[ j ] ) ) { matches = true; break; }
            }
            if ( ! matches ) { continue; }

            var headerRow = table.querySelector( 'thead tr' );
            if ( headerRow ) { augmentHeader( headerRow ); }

            for ( var k = 0; k < bodyRows.length; k++ ) {
                augmentRow( bodyRows[ k ] );
            }
        }
    }

    // Run once now (in case the table mounted before this script ran), then
    // observe for future renders (tab switch, status filter, route change).
    scan();

    var observer = new MutationObserver( function () { scan(); } );
    observer.observe( mount, { childList: true, subtree: true } );
})();
JS;
    }

    /** Scoped CSS for the injected column. */
    private static function shortcode_column_injector_css(): string {
        return <<<'CSS'
/* Shortcode column header.
 * Compact, fixed width so the table doesn't visually split into two
 * sections. text-align matches the other forms-list headers.
 *
 * Padding mirrors the rich bundle's native TH (11px vertical, 16px
 * horizontal) but adds extra horizontal breathing room — without
 * this rule our injected TH inherits no padding from the bundle's
 * .neural-forms-forms-list__th selector (different classname) and
 * the "Shortcode" label sits flush against the table border. */
#neural-forms-app .nf-shortcode-col__th {
    text-align: left;
    white-space: nowrap;
    width: 460px;
    max-width: 460px;
    padding: 11px 28px 11px 16px;
    box-sizing: border-box;
}

/* Shortcode body cell. Same width as the TH for column alignment.
 * The 28px right padding is what lifts the pill off the table edge
 * so the copy button no longer hugs the border. Without it the pill
 * sits flush — our TD has no className overlap with the bundle's
 * own .neural-forms-forms-list__cell padding rules. */
#neural-forms-app .nf-shortcode-col__td {
    width: 460px;
    max-width: 460px;
    padding: 14px 28px 14px 16px;
    vertical-align: middle;
    box-sizing: border-box;
}

/* The pill wrapping <code> + Copy button. Was inline-flex without
 * width constraints — that's what caused the column to look broken
 * on long shortcodes. Now: full-width inside the cell, min-width:0
 * so flex truncation propagates to the <code> child, single-row
 * layout that aligns code-text left and copy button right. */
#neural-forms-app .nf-shortcode-col__wrap {
    display: flex;
    align-items: center;
    gap: 8px;
    width: 100%;
    max-width: 100%;
    padding: 4px 4px 4px 10px;
    background: #f5f6f8;
    border: 1px solid #e2e5ea;
    border-radius: 6px;
    box-sizing: border-box;
}

/* The shortcode text itself. flex:1 takes remaining space; min-width:0
 * lets it shrink below its intrinsic width (otherwise the long
 * shortcode forces the pill to overflow); ellipsis truncation when
 * the cell can't show the full text. The full shortcode is still
 * accessible via title= and via click-to-select-all. */
#neural-forms-app .nf-shortcode-col__code {
    flex: 1 1 auto;
    min-width: 0;
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: 12px;
    color: #353941;
    background: transparent;
    cursor: text;
    user-select: all;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
#neural-forms-app .nf-shortcode-col__code:focus {
    outline: 2px solid #1a2351;
    outline-offset: 1px;
}

/* Copy button never shrinks. Compact size so it doesn't dominate
 * the pill on short shortcodes. */
#neural-forms-app .nf-shortcode-col__copy {
    flex: 0 0 auto;
    min-height: 26px;
    padding: 2px 10px;
    font-size: 12px;
    line-height: 1;
    transition: background-color 120ms ease, color 120ms ease, border-color 120ms ease;
}
#neural-forms-app .nf-shortcode-col__copy.is-copied {
    background: #10b981 !important;
    border-color: #10b981 !important;
    color: #fff !important;
}

/* Wide screens: bump shortcode column to 500px so the pill keeps the
 * same effective width it had before we added 44px of horizontal
 * padding (16 left + 28 right) for breathing room from the table
 * border. */
@media ( min-width: 1281px ) {
    #neural-forms-app .nf-shortcode-col__th,
    #neural-forms-app .nf-shortcode-col__td {
        width: 500px;
        max-width: 500px;
    }
}

/* Medium screens: column width tightens, padding stays so the right
 * edge still has room. */
@media ( max-width: 1280px ) {
    #neural-forms-app .nf-shortcode-col__th,
    #neural-forms-app .nf-shortcode-col__td {
        width: 400px;
        max-width: 400px;
        padding-right: 24px;
    }
}

/* Narrow screens: drop the explicit width so the cell grows / shrinks
 * with the table; the pill itself stays full-width inside whatever
 * the cell ends up being. Right padding still gives ~16px lift off
 * the table edge so the copy button never hugs the border. */
@media ( max-width: 1024px ) {
    #neural-forms-app .nf-shortcode-col__th,
    #neural-forms-app .nf-shortcode-col__td {
        width: auto;
        max-width: 360px;
        padding-right: 16px;
    }
}
CSS;
    }

    /**
     * Forms-list-only: inject auto-detected type badge + submission /
     * spam counts under the form name, and an "Analytics" action link
     * into the row's actions cell. The rich 1.0.0 admin bundle does
     * not surface these natively and we cannot rebuild it (see
     * "Admin build warning" in CLAUDE.md), so the metadata is
     * delivered via a vanilla-JS injector + MutationObserver — same
     * pattern as enqueue_shortcode_column_injector().
     *
     * Data flows from FormsEndpoint::get_forms() (which already
     * computes detected_type / entry_count / spam_count for every
     * row) — but the rich bundle's existing forms-list fetch does
     * not redraw to consume those new keys. So we localise our own
     * slug -> meta map at PHP enqueue time, identical in spirit to
     * the shortcode payload, and bind row-level rendering against it.
     *
     * Removed once the rich bundle is replaced by a split forms.js
     * that renders these chips natively.
     */
    private static function enqueue_forms_list_meta_injector(): void {
        $result = \NeuralForms\Forms\FormRepository::list( [
            'status'   => 'any',
            'per_page' => 200,
            'page'     => 1,
            'orderby'  => 'name',
            'order'    => 'ASC',
        ] );

        $items = is_array( $result['items'] ?? null ) ? $result['items'] : [];
        $ids   = [];
        foreach ( $items as $form ) {
            $fid = (int) ( $form['id'] ?? 0 );
            if ( $fid > 0 ) $ids[] = $fid;
        }
        $totals = ! empty( $ids )
            ? \NeuralForms\Storage\EntryRepository::count_form_totals( $ids )
            : [];

        $by_slug = [];
        $by_name = [];
        foreach ( $items as $form ) {
            $slug = (string) ( $form['slug'] ?? '' );
            if ( $slug === '' ) continue;
            $fid  = (int) ( $form['id'] ?? 0 );
            $name = (string) ( $form['name'] ?? '' );
            $type = \NeuralForms\Forms\FormTypeDetector::detect( $form['form_schema'] ?? null );
            $row  = $totals[ $fid ] ?? [ 'entry_count' => 0, 'spam_count' => 0 ];
            $entry = [
                'id'           => $fid,
                'slug'         => $slug,
                'name'         => $name,
                'type'         => $type,
                'typeLabel'    => \NeuralForms\Forms\FormTypeDetector::label( $type ),
                'entryCount'   => (int) $row['entry_count'],
                'spamCount'    => (int) $row['spam_count'],
                'analyticsUrl' => admin_url( 'admin.php?page=neural-forms-results&form_id=' . $fid ),
            ];
            $by_slug[ $slug ] = $entry;
            if ( $name !== '' ) {
                $by_name[ strtolower( $name ) ] = $entry;
            }
        }

        $payload = wp_json_encode( [
            'bySlug' => $by_slug,
            'byName' => $by_name,
            'i18n'   => [
                'analytics'    => __( 'Analytics',   'neural-forms-lite' ),
                'submissions'  => __( 'submissions', 'neural-forms-lite' ),
                'submission'   => __( 'submission',  'neural-forms-lite' ),
                'spam'         => __( 'spam',        'neural-forms-lite' ),
                'analyticsAria'=> __( 'Open analytics for this form', 'neural-forms-lite' ),
            ],
        ] );

        wp_add_inline_script(
            'neural-forms-admin',
            'window.neuralFormsListMeta = ' . $payload . ';',
            'before'
        );
        wp_add_inline_script( 'neural-forms-admin', self::forms_list_meta_injector_js(), 'after' );
        wp_add_inline_style(  'neural-forms-admin', self::forms_list_meta_injector_css() );
    }

    /** Vanilla-JS injector that paints the badge + counts + Analytics action. */
    private static function forms_list_meta_injector_js(): string {
        return <<<'JS'
( function () {
    var data = window.neuralFormsListMeta;
    if ( ! data || ! data.bySlug ) return;
    var i18n = data.i18n || {};

    var mount = document.getElementById( 'neural-forms-app' );
    if ( ! mount ) return;

    function lookup( row ) {
        var slugEl = row.querySelector( '.neural-forms-forms-list__form-slug' );
        if ( slugEl ) {
            var slug = ( slugEl.textContent || '' ).trim();
            if ( slug && data.bySlug[ slug ] ) return data.bySlug[ slug ];
        }
        // Name fallback (parity with shortcode injector).
        var nameEl = row.querySelector( '.neural-forms-forms-list__form-name' );
        if ( nameEl ) {
            var name = ( nameEl.textContent || '' ).trim().toLowerCase();
            if ( name && data.byName[ name ] ) return data.byName[ name ];
        }
        return null;
    }

    function paintMeta( row, form ) {
        if ( row.querySelector( '[data-nf-form-meta]' ) ) return;
        var nameCell = row.querySelector( '.neural-forms-forms-list__cell--name' )
            || row.querySelector( '.neural-forms-forms-list__form-slug' );
        if ( ! nameCell ) return;

        var meta = document.createElement( 'div' );
        meta.setAttribute( 'data-nf-form-meta', '1' );
        meta.className = 'nf-list-meta';

        var badge = document.createElement( 'span' );
        badge.className = 'nf-list-meta__badge nf-list-meta__badge--' + ( form.type || 'simple' );
        badge.textContent = form.typeLabel || '';
        meta.appendChild( badge );

        var counts = document.createElement( 'span' );
        counts.className = 'nf-list-meta__counts';
        var n   = form.entryCount | 0;
        var lbl = ( n === 1 ? ( i18n.submission || 'submission' ) : ( i18n.submissions || 'submissions' ) );
        counts.appendChild( document.createTextNode( n + ' ' + lbl ) );
        if ( ( form.spamCount | 0 ) > 0 ) {
            var sep = document.createElement( 'span' );
            sep.className = 'nf-list-meta__sep';
            sep.textContent = ' · ';
            counts.appendChild( sep );
            var sp = document.createElement( 'span' );
            sp.className = 'nf-list-meta__spam';
            sp.textContent = ( form.spamCount | 0 ) + ' ' + ( i18n.spam || 'spam' );
            counts.appendChild( sp );
        }
        meta.appendChild( counts );

        // Append after slug if present, else into the name cell.
        var slugEl = nameCell.querySelector
            ? nameCell.querySelector( '.neural-forms-forms-list__form-slug' )
            : null;
        if ( slugEl && slugEl.parentNode ) {
            slugEl.parentNode.insertBefore( meta, slugEl.nextSibling );
        } else {
            nameCell.appendChild( meta );
        }
    }

    function paintAction( row, form ) {
        if ( row.querySelector( '[data-nf-analytics-btn]' ) ) return;
        var actions = row.querySelector( '.neural-forms-forms-list__cell--actions' );
        if ( ! actions ) return;

        var a = document.createElement( 'a' );
        a.setAttribute( 'data-nf-analytics-btn', '1' );
        a.className = 'neural-forms-forms-list__action-btn nf-list-meta__analytics-btn';
        a.href = form.analyticsUrl || '#';
        a.textContent = i18n.analytics || 'Analytics';
        a.setAttribute( 'aria-label', i18n.analyticsAria || 'Open analytics' );

        // Insert before the first existing action button so the row keeps
        // its visual rhythm — Edit | Analytics | Archive.
        var firstBtn = actions.querySelector( '.neural-forms-forms-list__action-btn' );
        if ( firstBtn ) {
            actions.insertBefore( a, firstBtn.nextSibling );
        } else {
            actions.appendChild( a );
        }
    }

    function scan() {
        var rows = mount.querySelectorAll( '.neural-forms-forms-list__row' );
        for ( var i = 0; i < rows.length; i++ ) {
            var form = lookup( rows[ i ] );
            if ( ! form ) continue;
            paintMeta( rows[ i ], form );
            paintAction( rows[ i ], form );
        }
    }

    scan();
    var observer = new MutationObserver( function () { scan(); } );
    observer.observe( mount, { childList: true, subtree: true } );
} )();
JS;
    }

    /** Scoped CSS for the badge / counts / Analytics action chips and the
     *  surrounding Forms-list layout polish (wider container, balanced
     *  columns, action button rhythm). All overrides are scoped under
     *  #neural-forms-app so they cannot bleed onto other admin screens. */
    private static function forms_list_meta_injector_css(): string {
        return <<<'CSS'
/* Container widening — the rich bundle caps .neural-forms-forms-list at
 * --f-content-width (960px), which makes the table stop ~900px wide
 * even on a 1600px screen. Override to 1440px so wide screens use the
 * available horizontal space. WP admin chrome already provides the
 * outer left/right padding; we keep it. */
#neural-forms-app .neural-forms-forms-list {
    max-width: 1440px;
}

/* Header: title left, "+ New Form" right. The bundle already does this
 * at desktop widths but uses a column layout on narrow viewports —
 * that part is unchanged. We just widen the header to track the
 * widened table so the "+ New Form" button sits flush with the
 * right edge of the table instead of floating mid-page. */
#neural-forms-app .neural-forms-forms-list__header {
    width: 100%;
}

/* Column widths.
 *
 * The rich bundle's table is `width:100%` with no per-column widths,
 * so on a wide page each cell stretches to its content. We pin Status
 * and Actions to compact widths and let Name flex into the remainder;
 * the Shortcode column has its own widths from the shortcode injector. */
#neural-forms-app .neural-forms-forms-list__th--name,
#neural-forms-app .neural-forms-forms-list__cell--name {
    min-width: 260px;
}
#neural-forms-app .neural-forms-forms-list__th--status,
#neural-forms-app .neural-forms-forms-list__cell--status {
    width: 120px;
    min-width: 120px;
}
#neural-forms-app .neural-forms-forms-list__th--actions,
#neural-forms-app .neural-forms-forms-list__cell--actions {
    /* "width:1%" + white-space:nowrap is the classic shrink-to-fit
     * trick: the column claims only as much width as its longest
     * action-row needs, and Name absorbs the rest. */
    width: 1%;
    white-space: nowrap;
}

/* Cell padding nudge for breathing room in wide rows. */
#neural-forms-app .neural-forms-forms-list__cell {
    padding: 16px 18px;
}

/* Keep the slug visible on desktop — bundle hides it at the narrow
 * breakpoint via its own media query, which we don't touch. This rule
 * just confirms the desktop default. */
@media ( min-width: 769px ) {
    #neural-forms-app .neural-forms-forms-list__form-slug {
        display: block;
    }
}

/* Actions cell — replace ad-hoc margin-right between buttons with a
 * consistent rhythm. The bundle gives every action button
 * margin-right:6px individually; that's fine, we just align vertical. */
#neural-forms-app .neural-forms-forms-list__cell--actions .neural-forms-forms-list__action-btn {
    vertical-align: middle;
}

/* Analytics action button — brand mint accent on navy text so it reads
 * as a meaningful action without overpowering "+ New Form" or "Edit". */
#neural-forms-app .nf-list-meta__analytics-btn {
    background: rgba( 21, 219, 149, 0.12 );
    color: #080f5b;
    border: 1px solid rgba( 21, 219, 149, 0.45 );
    border-radius: 4px;
    text-decoration: none;
    margin-right: 6px;
    margin-left: 0;
    padding: 6px 14px;
    font-weight: 500;
    line-height: 1;
    transition: background 120ms ease, border-color 120ms ease;
}
#neural-forms-app .nf-list-meta__analytics-btn:hover,
#neural-forms-app .nf-list-meta__analytics-btn:focus {
    background: rgba( 21, 219, 149, 0.22 );
    border-color: rgba( 21, 219, 149, 0.65 );
    color: #080f5b;
    text-decoration: none;
    box-shadow: none;
}

/* Metadata row under the form name — the badge + counts chips. */
#neural-forms-app .nf-list-meta {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-top: 6px;
    flex-wrap: wrap;
    font-size: 11px;
    line-height: 1.4;
}
#neural-forms-app .nf-list-meta__badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 999px;
    font-size: 10px;
    font-weight: 600;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    background: #eef0f4;
    color: #4b5563;
    border: 1px solid #e2e5ea;
}
#neural-forms-app .nf-list-meta__badge--poll {
    background: rgba( 21, 219, 149, 0.12 );
    color: #0f766e;
    border-color: rgba( 21, 219, 149, 0.32 );
}
#neural-forms-app .nf-list-meta__badge--quiz {
    background: rgba( 8, 15, 91, 0.08 );
    color: #080f5b;
    border-color: rgba( 8, 15, 91, 0.22 );
}
#neural-forms-app .nf-list-meta__badge--survey {
    background: rgba( 217, 119, 6, 0.10 );
    color: #b45309;
    border-color: rgba( 217, 119, 6, 0.28 );
}
#neural-forms-app .nf-list-meta__badge--mixed {
    background: #080f5b;
    color: #ffffff;
    border-color: #080f5b;
}
#neural-forms-app .nf-list-meta__counts {
    color: #6b7280;
    font-size: 11px;
    font-variant-numeric: tabular-nums;
}
#neural-forms-app .nf-list-meta__sep {
    color: #9ca3af;
}
#neural-forms-app .nf-list-meta__spam {
    color: #b45309;
    font-weight: 600;
}

/* Tablet — give the shortcode column a bit more room than the bundle's
 * default and keep the name column readable. */
@media ( max-width: 1280px ) {
    #neural-forms-app .neural-forms-forms-list {
        max-width: 100%;
    }
    #neural-forms-app .neural-forms-forms-list__cell {
        padding: 14px 14px;
    }
}

/* Narrow / mobile — preserve readability without forcing horizontal
 * scroll. Status and Actions widths relax so the row doesn't squeeze. */
@media ( max-width: 768px ) {
    #neural-forms-app .neural-forms-forms-list__th--status,
    #neural-forms-app .neural-forms-forms-list__cell--status {
        width: auto;
        min-width: 0;
    }
    #neural-forms-app .nf-list-meta {
        gap: 6px;
    }
    #neural-forms-app .nf-list-meta__analytics-btn {
        padding: 5px 10px;
        font-size: 12px;
    }
}
CSS;
    }

    /**
     * Entries-page-only: when ?form_id=N is present in the URL,
     * programmatically click the matching sidebar form item so the
     * inbox is filtered. Also mirrors subsequent user-driven form
     * switches into the URL via history.replaceState so refreshing
     * or sharing the link preserves the filter.
     *
     * Why an injector? The rich admin bundle's Entries screen reads
     * `?entry=` from the URL on mount but does NOT read `?form_id=` —
     * form selection is internal React state only. We cannot rebuild
     * the rich bundle (CLAUDE.md byte-protection guard), so we drive
     * the filter via DOM events, mirroring the established pattern
     * used by enqueue_shortcode_column_injector() and
     * enqueue_forms_list_meta_injector().
     *
     * Sidebar form items render as
     *   <button class="neural-forms-entries-sidebar__item" title="{name}">
     * (verified in build/index.js); we match by `title` attribute,
     * which the rich bundle sets to the full form name. Active state
     * is the `--active` modifier.
     *
     * Removed once the rich bundle is replaced by a split entries.js
     * that reads ?form_id= natively.
     */
    private static function enqueue_entries_form_filter_injector(): void {
        $result = \NeuralForms\Forms\FormRepository::list( [
            'status'   => 'active',
            'per_page' => 200,
            'page'     => 1,
            'orderby'  => 'name',
            'order'    => 'ASC',
        ] );

        $items = is_array( $result['items'] ?? null ) ? $result['items'] : [];
        $by_id = [];
        foreach ( $items as $form ) {
            $fid  = (int) ( $form['id']   ?? 0 );
            $name = (string) ( $form['name'] ?? '' );
            if ( $fid > 0 && $name !== '' ) {
                $by_id[ (string) $fid ] = $name;
            }
        }

        $payload = wp_json_encode( [
            'byId' => $by_id,
        ] );

        wp_add_inline_script(
            'neural-forms-admin',
            'window.neuralFormsEntriesFilter = ' . $payload . ';',
            'before'
        );
        wp_add_inline_script( 'neural-forms-admin', self::entries_form_filter_injector_js(), 'after' );
    }

    /** Vanilla-JS injector — auto-selects form on mount + URL mirror. */
    private static function entries_form_filter_injector_js(): string {
        return <<<'JS'
( function () {
    var data = window.neuralFormsEntriesFilter;
    if ( ! data || ! data.byId ) return;
    var byId = data.byId;

    var mount = document.getElementById( 'neural-forms-app' );
    if ( ! mount ) return;

    function readUrlFormId() {
        try {
            var p = new URLSearchParams( window.location.search );
            var n = parseInt( p.get( 'form_id' ) || '', 10 );
            return isFinite( n ) && n > 0 ? n : 0;
        } catch ( e ) { return 0; }
    }

    function nameForId( id ) {
        return byId[ String( id ) ] || '';
    }

    function idForName( name ) {
        name = ( name || '' ).trim();
        if ( ! name ) return 0;
        for ( var k in byId ) {
            if ( Object.prototype.hasOwnProperty.call( byId, k ) && byId[ k ] === name ) {
                return parseInt( k, 10 ) || 0;
            }
        }
        return 0;
    }

    function setUrlFormId( id ) {
        try {
            var u = new URL( window.location.href );
            if ( id > 0 ) {
                u.searchParams.set( 'form_id', String( id ) );
            } else {
                u.searchParams.delete( 'form_id' );
            }
            window.history.replaceState( null, '', u.toString() );
        } catch ( e ) { /* noop */ }
    }

    function labelOf( btn ) {
        var t = btn.getAttribute( 'title' ) || '';
        if ( t ) return t.trim();
        var lbl = btn.querySelector( '.neural-forms-entries-sidebar__item-label' );
        return lbl ? ( lbl.textContent || '' ).trim() : '';
    }

    // Auto-select the form referenced by ?form_id=N, ONCE, on mount.
    // The bundle's onClick handler is wired synchronously when the
    // button enters the DOM, so a programmatic .click() filters the
    // inbox the same as a user click would.
    var didAutoSelect = false;
    function tryAutoSelect() {
        if ( didAutoSelect ) return;
        var targetId = readUrlFormId();
        if ( targetId <= 0 ) { didAutoSelect = true; return; }
        var name = nameForId( targetId );
        if ( ! name ) {
            // form_id points at a form that is not active or not visible
            // to the current user. Bundle's default view is fine — leave
            // the URL alone so the user sees what they asked for the
            // moment they navigate to the form via the sidebar.
            didAutoSelect = true;
            return;
        }
        var items = mount.querySelectorAll( 'button.neural-forms-entries-sidebar__item' );
        for ( var i = 0; i < items.length; i++ ) {
            var btn = items[ i ];
            if ( labelOf( btn ) === name ) {
                if ( ! btn.classList.contains( 'neural-forms-entries-sidebar__item--active' ) ) {
                    btn.click();
                }
                didAutoSelect = true;
                return;
            }
        }
    }

    // Mirror user-driven sidebar interactions to the URL.
    document.addEventListener( 'click', function ( e ) {
        if ( ! e.target || typeof e.target.closest !== 'function' ) return;
        var formBtn = e.target.closest( 'button.neural-forms-entries-sidebar__item' );
        if ( formBtn ) {
            var id = idForName( labelOf( formBtn ) );
            // setTimeout: defer one tick so the bundle's React state
            // settles before we mirror to the URL (otherwise a fast
            // back-button could pick up a half-set state).
            setTimeout( function () { setUrlFormId( id ); }, 0 );
            return;
        }
        // System views (Inbox / Starred / Spam / Trash etc.) clear the
        // form filter so refreshing doesn't re-apply a stale form id.
        var folderBtn = e.target.closest( 'button.neural-forms-entries-sidebar__folder' );
        if ( folderBtn ) {
            setTimeout( function () { setUrlFormId( 0 ); }, 0 );
        }
    }, true );

    tryAutoSelect();
    var observer = new MutationObserver( function () { tryAutoSelect(); } );
    observer.observe( mount, { childList: true, subtree: true } );
} )();
JS;
    }

    // Helpers

    /**
     * True if the current screen belongs to Neural Forms.
     */
    private static function is_neural_forms_page( string $hook ): bool {
        return strpos( $hook, self::MENU_SLUG ) !== false;
    }

    /**
     * True when the URL identifies the form-builder editor screen
     * (?page=neural-forms&edit=N or &new=1). Used by enqueue_assets()
     * to swap to the split-bundle builder instead of the rich admin
     * bundle. Capability is enforced upstream by add_menu_page().
     */
    private static function is_builder_screen(): bool {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $page   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        $edit   = isset( $_GET['edit'] ) ? (int) $_GET['edit']                         : 0;
        $is_new = isset( $_GET['new']  ) && $_GET['new'] === '1';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        return $page === self::MENU_SLUG && ( $edit > 0 || $is_new );
    }

    /**
     * True when ?page=neural-forms-results. The results screen ships
     * as its own split bundle and must NEVER share enqueue with the
     * rich admin bundle — different mount root, different routes.
     */
    private static function is_results_screen(): bool {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        return $page === 'neural-forms-results';
    }

    /**
     * Resolve the current screen identifier from the ?page= query parameter.
     *
     * Falls back to the first entry in SCREENS - no magic string.
     * If new screens are added to SCREENS, the fallback updates automatically.
     */
    private static function get_current_screen(): string {
        // The ?page= param is WordPress's own admin-router input, not a
        // submitted form field. Capability is enforced by add_menu_page()
        // before this runs, and the value is whitelisted against SCREENS.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

        // PHP 8.x: reset() requires a variable (pass-by-reference). A class
        // constant cannot be referenced directly, so copy SCREENS into a
        // local first. array_key_first() would also work but reset() keeps
        // the "first declared entry is the default" invariant explicit.
        $screens = self::SCREENS;
        return $screens[ $page ] ?? reset( $screens );
    }

    /**
     * Data passed to window.neuralFormsAdmin in the browser.
     * Grouped by concern so each consumer (middleware, router, UI) reads
     * only its own namespace without touching unrelated values.
     */
    private static function script_data(): array {
        $data = [
            'screen' => self::get_current_screen(),

            'api' => [
                // Lite-namespace base for default short-form paths like '/forms'.
                'url'   => esc_url_raw( rest_url( \NeuralForms\Rest\RestAPI::NAMESPACE ) ),
                // REST root, used by the middleware when a caller passes a path
                // that already includes its own namespace (e.g. Pro's
                // '/neural-forms-pro/v1/...'). Without this, those paths get
                // double-prefixed against `url` and 404.
                'root'  => esc_url_raw( rest_url() ),
                'nonce' => wp_create_nonce( 'wp_rest' ),
            ],

            'admin' => [
                'ajaxUrl'    => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
                'adminEmail' => (string) get_option( 'admin_email' ),
                'siteName'   => (string) get_bloginfo( 'name' ),
            ],

            'user' => [
                'id'   => get_current_user_id(),
                'caps' => [
                    'manageForms' => current_user_can( NEURAL_FORMS_CAP_MANAGE_FORMS ),
                ],
            ],

            'plugin' => [
                'version'  => NEURAL_FORMS_VERSION,
                'features' => \NeuralForms\Core\Config::features(),
                'cloudAiEnabled' => (bool) \NeuralForms\Core\Config::get( 'cloud_ai_enabled' ),
                'cloudApiKeySet' => \NeuralForms\Core\Config::get( 'cloud_api_key' ) !== '',
                'aiSpam'   => [
                    'enabled'   => (bool)   \NeuralForms\Core\Config::get( 'ai_spam_enabled' ),
                    'mode'      => (string) \NeuralForms\Core\Config::get( 'ai_spam_mode' ),
                    'threshold' => (int)    \NeuralForms\Core\Config::get( 'ai_spam_threshold' ),
                ],
                // Plugin-level capability snapshot ("is Pro installed?"). The
                // React bundle consults this to pick the right upsell vs.
                // unlocked UI. Cloud-plan gating remains on the `plan` key.
                'capabilities' => \NeuralForms\Core\Capabilities::all(),
                // True when Pro is active. When the user's cloud plan already
                // includes Pro features but the Pro plugin isn't installed,
                // the upsell modal swaps "Upgrade plan" for "Download Pro".
                'proInstalled' => class_exists( '\\NeuralForms\\Pro\\NeuralFormsPro' ),
                'proDownloadUrl' => 'https://neuralforms.ai/my-account/downloads/',
            ],

            // Plan + capabilities, identical shape to GET /settings.cloud_plan.
            // The JS PlanStore seeds from this on boot.
            'plan' => \NeuralForms\Plan\PlanManager::public_payload(),

            'captcha' => [
                'configured'  => \NeuralForms\Captcha\CaptchaManager::is_configured(),
                'settingsUrl' => esc_url_raw( admin_url( 'admin.php?page=neural-forms-settings' ) ),
            ],

            'migration' => [
                'cf7Active' => is_plugin_active( 'contact-form-7/wp-contact-form-7.php' ),
            ],
        ];

        /**
         * Filter the boot data localized into window.neuralFormsAdmin.
         *
         * Add-on plugins (e.g. Neural Forms Pro) can attach their own namespaced
         * data here - e.g. $data['pro'] = [ 'license' => ..., 'slots' => [...] ] -
         * so they can share the same localized payload rather than enqueuing an
         * additional script just to carry a few values.
         *
         * Keep added keys namespaced to avoid colliding with Lite's existing
         * shape. Never mutate or remove Lite's own keys.
         *
         * @param array $data The full localized payload.
         */
        return apply_filters( 'neural_forms_admin_script_data', $data );
    }
}
