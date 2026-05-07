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

        // Entries screen split: source-controlled build/entries.js +
        // build/entries.css. Self-contained — never enqueues
        // build/index.css.
        if (
            self::is_entries_screen()
            && file_exists( $build_dir . 'entries.js' )
        ) {
            $asset_file = $build_dir . 'entries.asset.php';
            $asset      = file_exists( $asset_file )
                ? require $asset_file
                : [ 'dependencies' => [], 'version' => NEURAL_FORMS_VERSION ];

            if ( ! is_array( $asset ) ) {
                $asset = [ 'dependencies' => [], 'version' => NEURAL_FORMS_VERSION ];
            }
            $deps    = isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] ) ? $asset['dependencies'] : [];
            $bust    = (string) filemtime( $build_dir . 'entries.js' );
            $version = ( $asset['version'] ?? NEURAL_FORMS_VERSION ) . '.' . $bust;

            wp_enqueue_script(
                'neural-forms-entries',
                $build_url . 'entries.js',
                $deps,
                $version,
                true
            );

            wp_localize_script( 'neural-forms-entries', 'neuralFormsAdmin', self::script_data() );

            if ( file_exists( $build_dir . 'entries.css' ) ) {
                wp_enqueue_style(
                    'neural-forms-entries',
                    $build_url . 'entries.css',
                    [ 'wp-components' ],
                    (string) filemtime( $build_dir . 'entries.css' )
                );
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

        // Forms-list screen split (Phase 1 of refactor/remove-protected-
        // admin-bundle): when on ?page=neural-forms (the default Forms-
        // list view, NOT the Builder edit/new flow which is handled
        // above) AND build/forms.js exists, enqueue the per-screen
        // forms bundle INSTEAD of build/index.js. The forms bundle
        // ships its own forms.css so the protected build/index.css is
        // also skipped on this screen.
        //
        // The Forms-screen-only injectors (shortcode column, builder-
        // navigation, forms-list-meta, new-form-dropdown) below DO
        // still run — they target the same `.neural-forms-forms-list__*`
        // BEM hooks the per-screen bundle emits, so the PHP-side
        // enhancements work unchanged.
        //
        // Each admin screen routes to its own per-screen bundle below;
        // the rich `build/index.js` catch-all has been retired.
        if (
            self::get_current_screen() === 'forms'
            && ! self::is_builder_screen()
            && file_exists( $build_dir . 'forms.js' )
        ) {
            $asset_file = $build_dir . 'forms.asset.php';
            $asset      = file_exists( $asset_file )
                ? require $asset_file
                : [ 'dependencies' => [], 'version' => NEURAL_FORMS_VERSION ];

            if ( ! is_array( $asset ) ) {
                $asset = [ 'dependencies' => [], 'version' => NEURAL_FORMS_VERSION ];
            }
            $deps    = isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] ) ? $asset['dependencies'] : [];
            $bust    = (string) filemtime( $build_dir . 'forms.js' );
            $version = ( $asset['version'] ?? NEURAL_FORMS_VERSION ) . '.' . $bust;

            wp_enqueue_script(
                'neural-forms-admin',  // keep handle name so wp_add_inline_*
                                       // calls elsewhere still attach.
                $build_url . 'forms.js',
                $deps,
                $version,
                true
            );

            wp_localize_script( 'neural-forms-admin', 'neuralFormsAdmin', self::script_data() );

            if ( file_exists( $build_dir . 'forms.css' ) ) {
                wp_enqueue_style(
                    'neural-forms-admin',
                    $build_url . 'forms.css',
                    [ 'wp-components' ],
                    (string) filemtime( $build_dir . 'forms.css' )
                );
            }

        }

        // Stats screen split (Phase 2 of refactor/remove-protected-
        // admin-bundle): when on ?page=neural-forms-stats AND
        // build/stats.js exists, enqueue the per-screen stats bundle
        // INSTEAD of build/index.js. Same pattern as forms above —
        // handle name is reused so wp_add_inline_* attachments and
        // any future Stats-screen injectors keep working.
        if (
            self::get_current_screen() === 'stats'
            && file_exists( $build_dir . 'stats.js' )
        ) {
            $asset_file = $build_dir . 'stats.asset.php';
            $asset      = file_exists( $asset_file )
                ? require $asset_file
                : [ 'dependencies' => [], 'version' => NEURAL_FORMS_VERSION ];

            if ( ! is_array( $asset ) ) {
                $asset = [ 'dependencies' => [], 'version' => NEURAL_FORMS_VERSION ];
            }
            $deps    = isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] ) ? $asset['dependencies'] : [];
            $bust    = (string) filemtime( $build_dir . 'stats.js' );
            $version = ( $asset['version'] ?? NEURAL_FORMS_VERSION ) . '.' . $bust;

            wp_enqueue_script(
                'neural-forms-admin',  // shared handle so wp_add_inline_*
                                       // attaches; no clash with other screens
                                       // because each request enqueues only one
                                       // bundle.
                $build_url . 'stats.js',
                $deps,
                $version,
                true
            );

            wp_localize_script( 'neural-forms-admin', 'neuralFormsAdmin', self::script_data() );

            if ( file_exists( $build_dir . 'stats.css' ) ) {
                wp_enqueue_style(
                    'neural-forms-admin',
                    $build_url . 'stats.css',
                    [ 'wp-components' ],
                    (string) filemtime( $build_dir . 'stats.css' )
                );
            }

        }

        // Settings screen split (Phase 3 of refactor/remove-protected-
        // admin-bundle): when on ?page=neural-forms-settings AND
        // build/settings.js exists, enqueue the per-screen settings
        // bundle INSTEAD of build/index.js. Same pattern as forms +
        // stats above — handle name is reused so wp_add_inline_*
        // attachments and any future Settings-screen injectors keep
        // working.
        if (
            self::get_current_screen() === 'settings'
            && file_exists( $build_dir . 'settings.js' )
        ) {
            $asset_file = $build_dir . 'settings.asset.php';
            $asset      = file_exists( $asset_file )
                ? require $asset_file
                : [ 'dependencies' => [], 'version' => NEURAL_FORMS_VERSION ];

            if ( ! is_array( $asset ) ) {
                $asset = [ 'dependencies' => [], 'version' => NEURAL_FORMS_VERSION ];
            }
            $deps    = isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] ) ? $asset['dependencies'] : [];
            $bust    = (string) filemtime( $build_dir . 'settings.js' );
            $version = ( $asset['version'] ?? NEURAL_FORMS_VERSION ) . '.' . $bust;

            wp_enqueue_script(
                'neural-forms-admin',  // shared handle so wp_add_inline_*
                                       // attaches; no clash with other screens
                                       // because each request enqueues only one
                                       // bundle.
                $build_url . 'settings.js',
                $deps,
                $version,
                true
            );

            wp_localize_script( 'neural-forms-admin', 'neuralFormsAdmin', self::script_data() );

            if ( file_exists( $build_dir . 'settings.css' ) ) {
                wp_enqueue_style(
                    'neural-forms-admin',
                    $build_url . 'settings.css',
                    [ 'wp-components' ],
                    (string) filemtime( $build_dir . 'settings.css' )
                );
            }

        }

        // Import (CF7 migration) screen split (Phase 4 of refactor/
        // remove-protected-admin-bundle): when on
        // ?page=neural-forms-migration AND build/import.js exists,
        // enqueue the per-screen import bundle INSTEAD of build/index.js.
        // Same pattern as forms / stats / settings — handle name is
        // reused so wp_add_inline_* attachments keep working.
        if (
            self::get_current_screen() === 'migration'
            && file_exists( $build_dir . 'import.js' )
        ) {
            $asset_file = $build_dir . 'import.asset.php';
            $asset      = file_exists( $asset_file )
                ? require $asset_file
                : [ 'dependencies' => [], 'version' => NEURAL_FORMS_VERSION ];

            if ( ! is_array( $asset ) ) {
                $asset = [ 'dependencies' => [], 'version' => NEURAL_FORMS_VERSION ];
            }
            $deps    = isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] ) ? $asset['dependencies'] : [];
            $bust    = (string) filemtime( $build_dir . 'import.js' );
            $version = ( $asset['version'] ?? NEURAL_FORMS_VERSION ) . '.' . $bust;

            wp_enqueue_script(
                'neural-forms-admin',  // shared handle so wp_add_inline_*
                                       // attaches; no clash with other screens
                                       // because each request enqueues only one
                                       // bundle.
                $build_url . 'import.js',
                $deps,
                $version,
                true
            );

            wp_localize_script( 'neural-forms-admin', 'neuralFormsAdmin', self::script_data() );

            if ( file_exists( $build_dir . 'import.css' ) ) {
                wp_enqueue_style(
                    'neural-forms-admin',
                    $build_url . 'import.css',
                    [ 'wp-components' ],
                    (string) filemtime( $build_dir . 'import.css' )
                );
            }

        }

        // Page-header unifier — ships every per-screen split bundle the
        // same hero-card header that the Analytics screen already uses.
        // The CSS retargets each screen's existing `__header` / `__title`
        // BEM classes so the React tree stays untouched and only the
        // visual shell changes. Attached to the shared `neural-forms-admin`
        // handle that every split branch above enqueues under.
        wp_add_inline_style( 'neural-forms-admin', self::page_hero_unifier_css() );

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
            self::enqueue_new_form_dropdown_injector();
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
        $by_slug = [];
        $by_id   = [];
        $name_count = [];
        $by_name = [];
        // First pass: count names so we know which are unique. Forms
        // sharing a name (e.g. three "Contact Form" rows) cannot be
        // distinguished by name alone, so we MUST drop them from the
        // byName fallback — keeping them would let a row resolve to
        // the wrong duplicate and copy the wrong shortcode.
        foreach ( $items as $form ) {
            $name = strtolower( (string) ( $form['name'] ?? '' ) );
            if ( $name === '' ) continue;
            $name_count[ $name ] = ( $name_count[ $name ] ?? 0 ) + 1;
        }
        foreach ( $items as $form ) {
            $id   = (int) ( $form['id'] ?? 0 );
            $slug = (string) ( $form['slug'] ?? '' );
            $name = (string) ( $form['name'] ?? '' );
            $entry = [
                'id'        => $id,
                'slug'      => $slug,
                'name'      => $name,
                // 0.2.0: ID-based shortcode is the canonical embed format.
                // Slugs change (renames) and can collide; ids don't.
                // ShortcodeHandler::for_form() returns the id form when
                // possible and falls back to slug only when the row has
                // no id (shouldn't happen for saved forms — defensive).
                // ShortcodeHandler::handle() still accepts slug= for
                // back-compat with pages that embedded slug-shortcodes
                // before this change.
                'shortcode' => \NeuralForms\Frontend\ShortcodeHandler::for_form( $form ),
            ];
            // Primary key (1.1.0): the row's edit link carries the
            // form id in its href, so the injector can resolve every
            // row reliably even when the React UI doesn't render the
            // slug as plain text. byId is keyed by string so JS
            // lookup is allocation-free.
            if ( $id > 0 ) {
                $by_id[ (string) $id ] = $entry;
            }
            if ( $slug !== '' ) {
                $by_slug[ $slug ] = $entry;
            }
            // byName is intentionally LOSSY-SAFE: only populated when
            // exactly one form bears this name. The previous logic
            // wrote each duplicate over the last and silently bound
            // every duplicate row to whichever form was iterated last
            // — the source of the "every row shows id=33" bug.
            if ( $name !== '' && ( $name_count[ strtolower( $name ) ] ?? 0 ) === 1 ) {
                $by_name[ strtolower( $name ) ] = $entry;
            }
        }

        // The injector script is registered against the same handle used as a
        // dependency, so ordering is deterministic. We use wp_add_inline_script
        // rather than a separate file so the markup remains a single source of
        // truth - no extra round-trip, no risk of a stale cached file.
        $payload = wp_json_encode( [
            'byId'   => $by_id,
            'bySlug' => $by_slug,
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
     * Resolve the form for a row in priority order:
     *
     *   1. Edit-link id — every Forms list row has an
     *      <a href="...&edit=N"> link. N is the form's primary key,
     *      sourced directly from the React UI's own data, so it is
     *      always correct (even when multiple forms share a name).
     *      This is the canonical resolver.
     *   2. Slug regex against textContent — works when the React UI
     *      renders the slug as plain text. Word-boundary-ish.
     *   3. byName — ONLY for names that are unique across the list.
     *      Duplicate-named forms are deliberately omitted from byName
     *      on the PHP side because resolving by name alone would bind
     *      every duplicate row to whichever form happened to be last.
     *
     * Returns the form record from the localized map, or null.
     */
    function resolveFormForRow( row ) {
        var byId   = data.byId   || {};
        var bySlug = data.bySlug || {};
        var byName = data.byName || {};

        // 1. Edit-link id. Match an `edit=N` query in any anchor href
        //    within the row. Works when the React UI renders Edit as
        //    a real <a href="...&edit=N">.
        var anchors = row.querySelectorAll( 'a[href]' );
        for ( var a = 0; a < anchors.length; a++ ) {
            var href = anchors[ a ].getAttribute( 'href' ) || '';
            var m = href.match( /[?&]edit=(\d+)/ );
            if ( m && byId[ m[ 1 ] ] ) {
                return byId[ m[ 1 ] ];
            }
        }

        // 2. data-* attributes — many React UIs expose the row's
        //    primary key on the row or on action buttons.
        var dataIdNodes = row.querySelectorAll( '[data-form-id], [data-id], [data-form], [data-key]' );
        for ( var d = 0; d < dataIdNodes.length; d++ ) {
            var attrs = [ 'data-form-id', 'data-id', 'data-form', 'data-key' ];
            for ( var ai = 0; ai < attrs.length; ai++ ) {
                var v = dataIdNodes[ d ].getAttribute( attrs[ ai ] );
                if ( v && /^\d+$/.test( v ) && byId[ v ] ) {
                    return byId[ v ];
                }
            }
        }

        // 3. Slug match per descendant. The React Forms list renders
        //    the slug in its own small <code>/<span>, so that
        //    element's textContent equals the slug exactly. This is
        //    far more robust than a regex against the row's full
        //    textContent because React doesn't add whitespace
        //    between adjacent elements — slugs like "contact-form-2"
        //    end up concatenated to the form name (e.g.
        //    "Formcontact-form-2") and a word-boundary regex misses.
        var descendants = row.querySelectorAll( '*' );
        for ( var s = 0; s < descendants.length; s++ ) {
            // Skip nodes with element children — we want the
            // innermost text leaf so we don't false-match a parent
            // that wraps the slug along with other text.
            if ( descendants[ s ].children && descendants[ s ].children.length > 0 ) continue;
            var nodeText = ( descendants[ s ].textContent || '' ).trim();
            if ( nodeText && bySlug[ nodeText ] ) {
                return bySlug[ nodeText ];
            }
        }

        // 4. Unique-name fallback. Only triggers for names appearing
        //    exactly once in the list (PHP filtered duplicates out).
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
     * Add or REFRESH the Shortcode <td> on a body <tr>. Re-entrant —
     * called once per scan() pass plus whenever MutationObserver
     * fires.
     *
     * The previous implementation skipped any row that already had a
     * shortcode cell. That broke when React reused a <tr> across
     * status-tab switches / filter changes / pagination: the stale
     * <td> from the prior render persisted and the row showed the
     * WRONG form's shortcode (different name, same id) — exactly
     * the "two rows show id=28" bug from 0.2.0 QA.
     *
     * New behaviour:
     *   1. Resolve the form for the CURRENT row text.
     *   2. If the row already has a shortcode cell whose
     *      data-nf-form-id matches the resolved form id, no-op.
     *   3. Otherwise, remove the stale cell (if any) and append a
     *      fresh one stamped with data-nf-form-id.
     *
     * Resolved-form-id is the source of truth — slug or name text
     * inside the row drives resolveFormForRow, but it's the form's
     * primary key that goes onto the <td> as the cache identity.
     */
    function augmentRow( row ) {
        var form   = resolveFormForRow( row );
        var formId = form ? String( form.id || '' ) : '';

        var existing = row.querySelector( '[data-nf-shortcode-cell]' );
        if ( existing ) {
            // The cell already shows the right form — leave it alone.
            if ( form && existing.getAttribute( 'data-nf-form-id' ) === formId ) {
                return;
            }
            // Row was reused for a different form (or now resolves
            // differently) — drop the stale cell so we re-render.
            existing.parentNode.removeChild( existing );
        }

        // Always append a <td>, even when the form can't be
        // resolved. The header always has a "Shortcode" <th>, so
        // every <tr> needs a matching <td> to keep the table
        // columns aligned. An unresolved row gets an empty
        // placeholder cell that any later resolution pass can
        // replace once the row's DOM stabilises.
        var td = document.createElement( 'td' );
        td.setAttribute( 'data-nf-shortcode-cell', '1' );
        td.setAttribute( 'data-nf-form-id', formId );
        td.className = 'nf-shortcode-col__td';

        if ( ! form ) {
            // Empty placeholder — matches column widths so the
            // table doesn't collapse rows that we couldn't resolve.
            row.appendChild( td );
            return;
        }

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
        // Capture the shortcode by closure so a stale callback
        // never copies the wrong form's value (defensive — the
        // <td>/<button> are recreated on form-id change anyway).
        var shortcodeAtClickTime = form.shortcode;
        btn.addEventListener( 'click', function () { copy( shortcodeAtClickTime, btn ); } );

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

    /**
     * Unified page-header CSS shipped on every Neural Forms admin
     * screen. The Analytics screen's `nf-analytics-hero` is the visual
     * source of truth (white rounded card, blue title, soft border,
     * subtle shadow); this CSS applies the same look to the protected
     * `build/index.js` screens (Stats / Forms list / Settings / Import)
     * by retargeting their existing `__header` + `__title` markup.
     *
     * No DOM changes: the protected bundle keeps emitting the same
     * elements; we only restyle them. Subtitles + actions slots are
     * Analytics-only because the protected screens don't emit DOM for
     * them — adding those would require rebuilding `build/index.js`,
     * which is out of scope per the byte-protected-bundle policy in
     * CLAUDE.md.
     *
     * The shared `.nf-page-hero` / `.nf-page-hero__title` /
     * `.nf-page-hero__subtitle` / `.nf-page-hero__actions` classes are
     * also exposed so future React work (Lite split bundles, Pro admin
     * screens) can use the canonical class names directly.
     */
    private static function page_hero_unifier_css(): string {
        // Single source of truth — copied from nf-analytics-hero in
        // modules/admin/src/results.css. Keep these values in sync
        // when the Analytics hero is restyled.
        return <<<'CSS'
/* ── Shared hero card (canonical class names) ──────────────────── */
#neural-forms-app .nf-page-hero,
#neural-forms-results-app .nf-page-hero {
    box-sizing: border-box;     /* keep 28×32 padding INSIDE 100% width
                                   so the card aligns with siblings inside
                                   a constrained parent (e.g. forms-list's
                                   960px max-width). */
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 24px;
    padding: 28px 32px;
    margin-bottom: 24px;
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 18px;
    box-shadow: 0 8px 24px rgba(8, 15, 91, 0.06);
}

#neural-forms-app .nf-page-hero__copy,
#neural-forms-results-app .nf-page-hero__copy {
    min-width: 0;
    flex: 1 1 auto;
}

#neural-forms-app .nf-page-hero__title,
#neural-forms-results-app .nf-page-hero__title {
    margin: 0 0 6px;
    color: #080f5b;
    font-size: 22px;
    font-weight: 700;
    line-height: 1.2;
    letter-spacing: 0.01em;
}

#neural-forms-app .nf-page-hero__subtitle,
#neural-forms-results-app .nf-page-hero__subtitle {
    margin: 0;
    color: #475569;
    font-size: 13px;
    line-height: 1.5;
}

#neural-forms-app .nf-page-hero__actions,
#neural-forms-results-app .nf-page-hero__actions {
    display: flex;
    align-items: flex-end;
    gap: 12px;
    flex: 0 0 auto;
}

@media (max-width: 900px) {
    #neural-forms-app .nf-page-hero,
    #neural-forms-results-app .nf-page-hero {
        flex-direction: column;
        align-items: stretch;
    }
    #neural-forms-app .nf-page-hero__actions,
    #neural-forms-results-app .nf-page-hero__actions {
        width: 100%;
    }
    #neural-forms-app .nf-page-hero__actions > *,
    #neural-forms-results-app .nf-page-hero__actions > * {
        width: 100%;
    }
}

/* ── Protected-bundle screens: skin existing __header/__title ──
 *
 * The build/index.js bundle emits per-screen header containers
 * (`__header`) and title elements (`__title`) for Stats / Forms list
 * / Settings / Import. We retarget those existing classes with the
 * hero-card visuals — no DOM changes, no rebuild of the protected
 * bundle.
 *
 * Each rule is an `:is()` group so adding a new screen takes one line.
 *
 * Subtitle text isn't present in the protected DOM, so these screens
 * show TITLE-ONLY heroes today. When the rich source is restored,
 * each screen can emit a `__subtitle` paragraph and the styling
 * already exists.
 *
 * ── Why Entries is intentionally NOT in this list ──
 *
 * Entries (?page=neural-forms-entries) is INTENTIONALLY EXCLUDED
 * from the unifier and stays on the rich/protected bundle's existing
 * look. The inbox's 2-pane layout
 * (`.neural-forms-entries-layout`: 220px sidebar | 1fr content) has
 * no top-level `__header` element to retarget — there is no
 * `.neural-forms-entries__header` or `.neural-forms-entries__title`
 * class in the protected bundle. The "Inbox" label users see is a
 * sidebar folder item (`.neural-forms-entries-sidebar__folder-label`),
 * not a page banner.
 *
 * Migrating Entries to a unified hero would require either rebuilding
 * the byte-protected `build/index.js` (out of scope) or porting the
 * source UI in `src/entries/` to match the rich CSS + behaviour
 * exactly. The latter is tracked as a separate "Entries Source
 * Parity Migration" task — see the deferral note in CLAUDE.md
 * (`## Known Gotchas → Entries source parity is DEFERRED`).
 *
 * Do NOT add `.neural-forms-entries*` selectors here without
 * completing that migration. */

#neural-forms-app :is(
    .neural-forms-stats__header,
    .neural-forms-forms-list__header,
    .neural-forms-settings__header,
    .neural-forms-import__header
) {
    box-sizing: border-box;     /* protected bundle does NOT set border-box
                                   globally; without this, our 28×32 padding
                                   would visually push the card 64px wider
                                   than the table/list inside the same
                                   .neural-forms-{screen}-list parent
                                   (max-width: 960px on Forms list). */
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 24px;
    padding: 28px 32px;
    margin-bottom: 24px;
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 18px;
    box-shadow: 0 8px 24px rgba(8, 15, 91, 0.06);
}

#neural-forms-app :is(
    .neural-forms-stats__title,
    .neural-forms-forms-list__title,
    .neural-forms-settings__header-text,
    .neural-forms-import__title
) {
    margin: 0;
    color: #080f5b;
    font-size: 22px;
    font-weight: 700;
    line-height: 1.2;
    letter-spacing: 0.01em;
}

@media (max-width: 900px) {
    #neural-forms-app :is(
        .neural-forms-stats__header,
        .neural-forms-forms-list__header,
        .neural-forms-settings__header,
        .neural-forms-import__header
    ) {
        flex-direction: column;
        align-items: stretch;
    }
}
CSS;
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
     * Forms-list-only: turn the rich bundle's "+ New Form" button into
     * a split dropdown when Neural Forms Pro + AI form-generation are
     * available.
     *
     *   Lite-only / Pro inactive          → no change. The plain
     *                                       "+ New Form" button stays.
     *   Pro + AI configured + eligible    → dropdown with three items:
     *                                         Blank Form
     *                                         Use Template
     *                                         Create Form with AI ✨
     *   Pro installed but AI not ready    → AI item visible but
     *                                       disabled, with a "Connect
     *                                       Neural Forms Cloud..."
     *                                       hint pointing at Settings.
     *
     * Same vanilla-JS DOM-augmentation pattern as the shortcode column
     * injector. The rich bundle's React tree is not modified — we
     * locate the original button via its title/aria text, render a
     * sibling caret button, and emit a dropdown menu rooted in
     * #neural-forms-app.
     *
     * The "Create with AI" flow opens its own modal (rendered into a
     * portal under document.body so it isn't constrained by the rich
     * bundle's container CSS). The modal POSTs the prompt to Pro's
     * /neural-forms-pro/v1/ai/generate-form endpoint, then on Create
     * persists the returned schema via /neural-forms/v1/forms with
     * status=draft and redirects to ?page=neural-forms&edit=N so the
     * builder split-bundle picks up where the user left off.
     */
    private static function enqueue_new_form_dropdown_injector(): void {
        $cloud_ai_enabled = (bool) \NeuralForms\Core\Config::get( 'cloud_ai_enabled' );
        $cloud_key_set    = \NeuralForms\Core\Config::get( 'cloud_api_key' ) !== '';
        $can_generate     = \NeuralForms\Core\Capabilities::can( \NeuralForms\Core\Capabilities::AI_FORM_GENERATE );
        $pro_installed    = class_exists( '\\NeuralForms\\Pro\\NeuralFormsPro' );

        // Don't bother shipping any of this for Lite-only installs.
        // Pro flips ai_form_generate to true; without Pro the original
        // "+ New Form" button is the right UX (no dropdown clutter).
        if ( ! $pro_installed && ! $can_generate ) {
            return;
        }

        $payload = wp_json_encode( [
            'available'      => $can_generate && $cloud_ai_enabled && $cloud_key_set,
            'proInstalled'   => $pro_installed,
            'canGenerate'    => $can_generate,
            'cloudConnected' => $cloud_ai_enabled && $cloud_key_set,
            'cloudKeySet'    => $cloud_key_set,
            'cloudAiEnabled' => $cloud_ai_enabled,
            'settingsUrl'    => esc_url_raw( admin_url( 'admin.php?page=neural-forms-settings' ) ),
            'newFormUrl'     => esc_url_raw( admin_url( 'admin.php?page=neural-forms&new=1' ) ),
            'editFormUrl'    => esc_url_raw( admin_url( 'admin.php?page=neural-forms&edit=__ID__' ) ),
            'i18n' => [
                'caretLabel'   => __( 'New form options',           'neural-forms-lite' ),
                'blank'        => __( 'Blank Form',                  'neural-forms-lite' ),
                'template'     => __( 'Use Template',                'neural-forms-lite' ),
                'ai'           => __( 'Create Form with AI',         'neural-forms-lite' ),
                'aiBadge'      => __( 'AI',                          'neural-forms-lite' ),
                'aiSetup'      => __( 'Connect Neural Forms Cloud to use AI', 'neural-forms-lite' ),
                'modalTitle'   => __( 'Create form with AI',         'neural-forms-lite' ),
                'modalDesc'    => __( 'Describe the form you want. Neural Forms will draft the fields for you.', 'neural-forms-lite' ),
                'placeholder'  => __( 'e.g. Create a dental appointment form with name, email, phone, preferred date, and message.', 'neural-forms-lite' ),
                'cancel'       => __( 'Cancel',                      'neural-forms-lite' ),
                'generate'     => __( 'Generate form',               'neural-forms-lite' ),
                'generating'   => __( 'Generating...',               'neural-forms-lite' ),
                'creating'     => __( 'Creating draft...',           'neural-forms-lite' ),
                'createDraft'  => __( 'Create draft and edit',       'neural-forms-lite' ),
                'editPrompt'   => __( 'Edit prompt',                 'neural-forms-lite' ),
                'fieldsCount'  => __( '%d fields generated',         'neural-forms-lite' ),
                'genericError' => __( 'AI generation failed. Please try again.', 'neural-forms-lite' ),
                'createError'  => __( 'Could not create the form. Please try again.', 'neural-forms-lite' ),
                'safetyBlock'  => __( 'AI skipped this prompt because it appears unsafe.', 'neural-forms-lite' ),
                'safetyDown'   => __( 'AI safety check is temporarily unavailable. Please try again in a few minutes.', 'neural-forms-lite' ),
                'noCharge'     => __( 'No credits were deducted.',   'neural-forms-lite' ),
                'creditsUsed'  => __( '%s credits used',             'neural-forms-lite' ),
                'creditsLeft'  => __( '· %s remaining',              'neural-forms-lite' ),
            ],
        ] );

        wp_add_inline_script(
            'neural-forms-admin',
            'window.neuralFormsNewFormDropdown = ' . $payload . ';',
            'before'
        );
        wp_add_inline_script(
            'neural-forms-admin',
            self::new_form_dropdown_injector_js(),
            'after'
        );
        wp_add_inline_style(
            'neural-forms-admin',
            self::new_form_dropdown_injector_css()
        );
    }

    /**
     * Vanilla-JS DOM augmentation for the "+ New Form" button.
     */
    private static function new_form_dropdown_injector_js(): string {
        return <<<'JS'
(function () {
    var data = window.neuralFormsNewFormDropdown;
    if ( ! data ) { return; }

    var mount = document.getElementById( 'neural-forms-app' );
    if ( ! mount ) { return; }

    /**
     * Locate the rich bundle's "+ New Form" button. The button has no
     * stable class, so we match on visible text content (case-insensitive,
     * trimmed). The first match wins — in the Forms list UI there is only
     * ever one button labeled "New Form" / "+ New Form".
     */
    function findNewFormButton( root ) {
        var candidates = root.querySelectorAll( 'button, a' );
        for ( var i = 0; i < candidates.length; i++ ) {
            var t = ( candidates[ i ].textContent || '' ).trim().toLowerCase();
            if ( t === '+ new form' || t === 'new form' || t === '+new form' ) {
                return candidates[ i ];
            }
        }
        return null;
    }

    function safeEscape( s ) {
        var d = document.createElement( 'div' );
        d.textContent = s == null ? '' : String( s );
        return d.innerHTML;
    }

    function format( tmpl, args ) {
        var i = 0;
        return String( tmpl ).replace( /%[ds]/g, function () {
            return args[ i++ ] != null ? args[ i - 1 ] : '';
        } );
    }

    /**
     * Render the modal that hosts the AI generation flow. Lives outside
     * #neural-forms-app on document.body so React re-renders never wipe
     * it. State is held in a closure-private object passed between
     * step renderers.
     */
    function openAiModal() {
        var i18n = data.i18n || {};
        var state = { phase: 'compose', prompt: '', generated: null, warnings: [], usage: null, busy: null, error: '' };

        var wrap = document.createElement( 'div' );
        wrap.className = 'nf-aiform-modal';
        wrap.setAttribute( 'role', 'dialog' );
        wrap.setAttribute( 'aria-modal', 'true' );
        wrap.setAttribute( 'aria-label', i18n.modalTitle || 'Create form with AI' );
        document.body.appendChild( wrap );

        function close() {
            if ( wrap.parentNode ) wrap.parentNode.removeChild( wrap );
            document.removeEventListener( 'keydown', onKey );
        }

        function onKey( e ) {
            if ( e.key === 'Escape' && state.busy == null ) close();
        }
        document.addEventListener( 'keydown', onKey );

        function renderUsage( usage ) {
            if ( ! usage ) return '';
            if ( usage.credits_deducted === false ) {
                return '<p class="nf-aiform-modal__usage">' + safeEscape( i18n.noCharge ) + '</p>';
            }
            if ( usage.credits_deducted === true ) {
                var used = usage.credits_used != null ? Number( usage.credits_used ).toFixed( 2 ) : '';
                var left = usage.credits_remaining != null ? Number( usage.credits_remaining ).toFixed( 2 ) : '';
                var line = format( i18n.creditsUsed || '%s credits used', [ used ] );
                if ( left !== '' ) line += ' ' + format( i18n.creditsLeft || '· %s remaining', [ left ] );
                return '<p class="nf-aiform-modal__usage">' + safeEscape( line ) + '</p>';
            }
            return '';
        }

        function fieldCountOf( gen ) {
            if ( ! gen || ! gen.form_schema || ! Array.isArray( gen.form_schema.fields ) ) return 0;
            return gen.form_schema.fields.length;
        }

        function render() {
            // Markup mirrors UnsavedChangesModal: overlay → panel →
            // header → body → footer. Header/footer are stable shells;
            // only the body slot reflows between compose/preview phases.
            wrap.innerHTML =
                '<div class="nf-aiform-modal__backdrop"></div>' +
                '<div class="nf-aiform-modal__panel" role="dialog" aria-modal="true" aria-labelledby="nf-aiform-modal-title">' +
                    '<header class="nf-aiform-modal__head">' +
                        '<h2 id="nf-aiform-modal-title" class="nf-aiform-modal__title">' + safeEscape( state.phase === 'preview' && state.generated ? ( state.generated.name || i18n.modalTitle ) : i18n.modalTitle ) + '</h2>' +
                    '</header>' +
                    '<div class="nf-aiform-modal__body" data-nf-body></div>' +
                    '<footer class="nf-aiform-modal__foot" data-nf-foot></footer>' +
                '</div>';

            wrap.querySelector( '.nf-aiform-modal__backdrop' ).addEventListener( 'click', function () {
                if ( state.busy == null ) close();
            } );

            var body = wrap.querySelector( '[data-nf-body]' );
            var foot = wrap.querySelector( '[data-nf-foot]' );

            if ( state.phase === 'compose' ) {
                body.innerHTML =
                    '<p class="nf-aiform-modal__desc">' + safeEscape( i18n.modalDesc ) + '</p>' +
                    '<textarea class="nf-aiform-modal__textarea" rows="6" maxlength="1500" placeholder="' + safeEscape( i18n.placeholder ) + '"></textarea>' +
                    ( state.error ? '<p class="nf-aiform-modal__error">' + safeEscape( state.error ) + '</p>' : '' ) +
                    renderUsage( state.usage );

                var ta = body.querySelector( 'textarea' );
                ta.value = state.prompt;
                ta.addEventListener( 'input', function () { state.prompt = ta.value; updateGenerateBtn(); } );
                setTimeout( function () { ta.focus(); }, 0 );

                // Buttons use ONLY the local .nf-aiform-modal__btn
                // classes so they inherit the modal's own typography
                // tokens — NOT the WP-admin .button / .button-primary
                // styling, which would force a light-blue secondary
                // and a WP-native blue primary that don't match the
                // Unsaved modal's muted-grey + dark-navy pair.
                foot.innerHTML =
                    '<button type="button" class="nf-aiform-modal__btn nf-aiform-modal__btn--cancel" data-nf-cancel>' + safeEscape( i18n.cancel ) + '</button>' +
                    '<button type="button" class="nf-aiform-modal__btn nf-aiform-modal__btn--primary" data-nf-generate>' + safeEscape( state.busy === 'generate' ? i18n.generating : i18n.generate ) + '</button>';

                foot.querySelector( '[data-nf-cancel]' ).addEventListener( 'click', function () {
                    if ( state.busy == null ) close();
                } );
                var genBtn = foot.querySelector( '[data-nf-generate]' );
                genBtn.addEventListener( 'click', generate );
                updateGenerateBtn();

                function updateGenerateBtn() {
                    var ok = state.prompt.trim().length >= 5 && state.busy == null;
                    genBtn.disabled = ! ok;
                }
                return;
            }

            // preview phase
            var gen = state.generated;
            var count = fieldCountOf( gen );
            var fieldsList = '<ul class="nf-aiform-modal__fields">';
            if ( gen && gen.form_schema && Array.isArray( gen.form_schema.fields ) ) {
                gen.form_schema.fields.slice( 0, 12 ).forEach( function ( f ) {
                    fieldsList += '<li><span class="nf-aiform-modal__type">' + safeEscape( f.type || '?' ) + '</span> ' + safeEscape( f.label || f.id || '' ) + '</li>';
                } );
                if ( gen.form_schema.fields.length > 12 ) {
                    fieldsList += '<li class="nf-aiform-modal__more">…</li>';
                }
            }
            fieldsList += '</ul>';

            var warningsBlock = '';
            if ( state.warnings && state.warnings.length ) {
                warningsBlock = '<ul class="nf-aiform-modal__warnings">';
                state.warnings.forEach( function ( w ) { warningsBlock += '<li>' + safeEscape( w ) + '</li>'; } );
                warningsBlock += '</ul>';
            }

            body.innerHTML =
                '<p class="nf-aiform-modal__subtitle">' + safeEscape( format( i18n.fieldsCount || '%d fields generated', [ count ] ) ) + '</p>' +
                fieldsList +
                warningsBlock +
                ( state.error ? '<p class="nf-aiform-modal__error">' + safeEscape( state.error ) + '</p>' : '' ) +
                renderUsage( state.usage );

            foot.innerHTML =
                '<button type="button" class="nf-aiform-modal__btn nf-aiform-modal__btn--cancel" data-nf-edit-prompt>' + safeEscape( i18n.editPrompt ) + '</button>' +
                '<button type="button" class="nf-aiform-modal__btn nf-aiform-modal__btn--cancel" data-nf-cancel>' + safeEscape( i18n.cancel ) + '</button>' +
                '<button type="button" class="nf-aiform-modal__btn nf-aiform-modal__btn--primary" data-nf-create>' + safeEscape( state.busy === 'create' ? i18n.creating : i18n.createDraft ) + '</button>';

            foot.querySelector( '[data-nf-edit-prompt]' ).addEventListener( 'click', function () {
                if ( state.busy != null ) return;
                state.phase = 'compose';
                state.error = '';
                render();
            } );
            foot.querySelector( '[data-nf-cancel]' ).addEventListener( 'click', function () {
                if ( state.busy == null ) close();
            } );
            var createBtn = foot.querySelector( '[data-nf-create]' );
            createBtn.disabled = state.busy != null;
            createBtn.addEventListener( 'click', createDraft );
        }

        function generate() {
            var prompt = state.prompt.trim();
            if ( prompt.length < 5 ) return;
            state.busy = 'generate';
            state.error = '';
            render();

            var apiSettings = ( window.wpApiSettings || {} );
            var rootUrl = apiSettings.root || ( window.neuralFormsAdmin && window.neuralFormsAdmin.api && window.neuralFormsAdmin.api.root ) || '/wp-json/';
            var nonce   = apiSettings.nonce || ( window.neuralFormsAdmin && window.neuralFormsAdmin.api && window.neuralFormsAdmin.api.nonce ) || '';

            var url = ( rootUrl.replace( /\/$/, '' ) ) + '/neural-forms-pro/v1/ai/generate-form';

            fetch( url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
                body: JSON.stringify( { prompt: prompt } )
            } ).then( function ( resp ) {
                return resp.json().then( function ( body ) { return { ok: resp.ok, body: body }; } );
            } ).then( function ( res ) {
                state.busy = null;
                if ( ! res.ok || ! res.body || ! res.body.form ) {
                    var i18n2 = window.neuralFormsNewFormDropdown.i18n;
                    var code  = res.body && res.body.code;
                    var msg;
                    if ( code === 'blocked_by_safety' ) {
                        msg = i18n2.safetyBlock;
                        // SafetyGate guarantees credits_deducted=false on
                        // this code; surface the no-charge confirmation
                        // alongside the message so the user trusts the
                        // failed call didn't burn their balance.
                        if ( res.body.usage && res.body.usage.credits_deducted === false ) {
                            msg += ' ' + i18n2.noCharge;
                        }
                    } else if ( code === 'ai_safety_unavailable' ) {
                        msg = i18n2.safetyDown;
                    } else {
                        msg = ( res.body && ( res.body.message || res.body.code ) ) || i18n2.genericError;
                    }
                    state.error = msg;
                    state.usage = ( res.body && res.body.usage ) || null;
                    render();
                    return;
                }
                state.generated = res.body.form;
                state.warnings  = Array.isArray( res.body.warnings ) ? res.body.warnings : [];
                state.usage     = res.body.usage || null;
                state.phase     = 'preview';
                render();
            } ).catch( function () {
                state.busy = null;
                state.error = window.neuralFormsNewFormDropdown.i18n.genericError;
                render();
            } );
        }

        function createDraft() {
            if ( ! state.generated ) return;
            state.busy = 'create';
            state.error = '';
            render();

            var apiSettings = ( window.wpApiSettings || {} );
            var rootUrl = apiSettings.root || ( window.neuralFormsAdmin && window.neuralFormsAdmin.api && window.neuralFormsAdmin.api.root ) || '/wp-json/';
            var nonce   = apiSettings.nonce || ( window.neuralFormsAdmin && window.neuralFormsAdmin.api && window.neuralFormsAdmin.api.nonce ) || '';

            var url = ( rootUrl.replace( /\/$/, '' ) ) + '/neural-forms/v1/forms';

            // Generated payload may use either 'form_schema' or
            // already-flattened keys; FormsEndpoint expects name/slug
            // top-level + form_schema object.
            var gen = state.generated;
            var body = {
                name:        gen.name || 'AI form',
                slug:        gen.slug || '',
                description: gen.description || '',
                form_schema: gen.form_schema || gen.schema || { fields: [] },
                settings:    gen.settings || {},
                status:      'draft'
            };

            fetch( url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
                body: JSON.stringify( body )
            } ).then( function ( resp ) {
                return resp.json().then( function ( jsonBody ) { return { ok: resp.ok, body: jsonBody }; } );
            } ).then( function ( res ) {
                if ( ! res.ok || ! res.body || ! res.body.id ) {
                    state.busy = null;
                    state.error = ( res.body && res.body.message ) || window.neuralFormsNewFormDropdown.i18n.createError;
                    render();
                    return;
                }
                // Redirect to the builder for review/edit. The builder
                // split-bundle picks up ?edit=N.
                var target = window.neuralFormsNewFormDropdown.editFormUrl.replace( '__ID__', String( res.body.id ) );
                window.location.href = target;
            } ).catch( function () {
                state.busy = null;
                state.error = window.neuralFormsNewFormDropdown.i18n.createError;
                render();
            } );
        }

        render();
    }

    /**
     * Inject a sibling caret + dropdown menu next to the original
     * "+ New Form" button. Idempotent - we tag the wrapper with
     * data-nf-new-form-dropdown so a re-scan won't duplicate it.
     * If React unmounts the original button, the next MutationObserver
     * pass re-runs scan() and we re-augment whatever new button
     * appears.
     */
    function augment() {
        var btn = findNewFormButton( mount );
        if ( ! btn ) return;
        if ( btn.getAttribute( 'data-nf-new-form-augmented' ) === '1' ) return;
        btn.setAttribute( 'data-nf-new-form-augmented', '1' );

        var wrapper = document.createElement( 'span' );
        wrapper.className = 'nf-newform-split';
        wrapper.setAttribute( 'data-nf-new-form-dropdown', '1' );

        var parent = btn.parentNode;
        if ( ! parent ) return;
        parent.insertBefore( wrapper, btn );
        wrapper.appendChild( btn );

        var caret = document.createElement( 'button' );
        caret.type = 'button';
        caret.className = 'nf-newform-split__caret';
        caret.setAttribute( 'aria-label', data.i18n.caretLabel );
        caret.setAttribute( 'aria-haspopup', 'menu' );
        caret.setAttribute( 'aria-expanded', 'false' );
        caret.innerHTML = '<span aria-hidden="true">▾</span>';
        wrapper.appendChild( caret );

        var menu = document.createElement( 'ul' );
        menu.className = 'nf-newform-split__menu';
        menu.setAttribute( 'role', 'menu' );
        menu.hidden = true;
        wrapper.appendChild( menu );

        // Build menu items.
        var items = [
            { id: 'blank',    label: data.i18n.blank,    enabled: true,  action: function () { btn.click(); } },
            { id: 'template', label: data.i18n.template, enabled: true,  action: function () { btn.click(); } },
        ];

        var aiEnabled  = !! data.available;
        var aiHint     = aiEnabled ? '' : data.i18n.aiSetup;
        items.push( {
            id: 'ai',
            label: data.i18n.ai,
            enabled: aiEnabled,
            badge: data.i18n.aiBadge,
            hint: aiHint,
            action: aiEnabled
                ? openAiModal
                : function () { window.location.href = data.settingsUrl; }
        } );

        items.forEach( function ( it ) {
            var li = document.createElement( 'li' );
            li.className = 'nf-newform-split__item' + ( it.enabled ? '' : ' is-disabled' );
            li.setAttribute( 'role', 'none' );
            var b = document.createElement( 'button' );
            b.type = 'button';
            b.setAttribute( 'role', 'menuitem' );
            b.className = 'nf-newform-split__menuitem';
            var html = '<span class="nf-newform-split__label">' + safeEscape( it.label ) + '</span>';
            if ( it.badge ) html += '<span class="nf-newform-split__badge">' + safeEscape( it.badge ) + '</span>';
            if ( it.hint  ) html += '<span class="nf-newform-split__hint">'  + safeEscape( it.hint  ) + '</span>';
            b.innerHTML = html;
            b.addEventListener( 'click', function ( e ) {
                e.stopPropagation();
                hideMenu();
                it.action();
            } );
            li.appendChild( b );
            menu.appendChild( li );
        } );

        function showMenu() {
            menu.hidden = false;
            caret.setAttribute( 'aria-expanded', 'true' );
            setTimeout( function () { document.addEventListener( 'click', onDoc ); document.addEventListener( 'keydown', onKey ); }, 0 );
        }
        function hideMenu() {
            menu.hidden = true;
            caret.setAttribute( 'aria-expanded', 'false' );
            document.removeEventListener( 'click', onDoc );
            document.removeEventListener( 'keydown', onKey );
        }
        function onDoc( e ) {
            if ( ! wrapper.contains( e.target ) ) hideMenu();
        }
        function onKey( e ) {
            if ( e.key === 'Escape' ) hideMenu();
        }

        caret.addEventListener( 'click', function ( e ) {
            e.stopPropagation();
            if ( menu.hidden ) showMenu(); else hideMenu();
        } );
    }

    function scan() { augment(); }

    scan();

    var observer = new MutationObserver( function () { scan(); } );
    observer.observe( mount, { childList: true, subtree: true } );
})();
JS;
    }

    private static function new_form_dropdown_injector_css(): string {
        return <<<'CSS'
/* Split button for "+ New Form".
 *
 * The wrapper holds the rich bundle's original primary button on the
 * left and our injected caret on the right, joined by a shared border
 * so they read as a single control. The dropdown menu is rendered in
 * the same wrapper (right-aligned) and absolutely positioned so the
 * Forms list layout doesn't reflow when it opens.
 */
#neural-forms-app .nf-newform-split {
    position: relative;
    display: inline-flex;
    align-items: stretch;
}
#neural-forms-app .nf-newform-split > button:first-of-type,
#neural-forms-app .nf-newform-split > a:first-of-type {
    border-top-right-radius: 0;
    border-bottom-right-radius: 0;
}
#neural-forms-app .nf-newform-split__caret {
    margin: 0;
    padding: 0 8px;
    border: 1px solid #1d4ed8;
    border-left: 1px solid rgba( 255, 255, 255, 0.35 );
    background: #2563eb;
    color: #fff;
    cursor: pointer;
    border-top-left-radius: 0;
    border-bottom-left-radius: 0;
    border-top-right-radius: 4px;
    border-bottom-right-radius: 4px;
    font-size: 12px;
    line-height: 1;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 28px;
}
#neural-forms-app .nf-newform-split__caret:hover {
    background: #1d4ed8;
}
#neural-forms-app .nf-newform-split__caret:focus-visible {
    outline: 2px solid #1d4ed8;
    outline-offset: 2px;
}

/* Dropdown menu. Right-aligned so it doesn't trigger horizontal
 * overflow when the parent column is narrow. */
#neural-forms-app .nf-newform-split__menu {
    position: absolute;
    top: calc( 100% + 4px );
    right: 0;
    min-width: 240px;
    margin: 0;
    padding: 4px;
    list-style: none;
    background: #fff;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    box-shadow: 0 8px 24px rgba( 15, 23, 42, 0.12 );
    z-index: 10000;
}
#neural-forms-app .nf-newform-split__item {
    margin: 0;
    padding: 0;
}
#neural-forms-app .nf-newform-split__menuitem {
    width: 100%;
    text-align: left;
    background: transparent;
    border: 0;
    border-radius: 4px;
    padding: 8px 10px;
    cursor: pointer;
    font-size: 13px;
    line-height: 1.4;
    color: #0f172a;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 2px;
}
#neural-forms-app .nf-newform-split__menuitem:hover,
#neural-forms-app .nf-newform-split__menuitem:focus-visible {
    background: #eff6ff;
    color: #1d4ed8;
    outline: none;
}
#neural-forms-app .nf-newform-split__item.is-disabled .nf-newform-split__menuitem {
    color: #94a3b8;
    cursor: not-allowed;
}
#neural-forms-app .nf-newform-split__label {
    font-weight: 500;
}
#neural-forms-app .nf-newform-split__badge {
    display: inline-block;
    margin-left: 6px;
    padding: 1px 6px;
    border-radius: 999px;
    background: linear-gradient(90deg, #6366f1, #d946ef);
    color: #fff;
    font-size: 10px;
    font-weight: 600;
    letter-spacing: 0.04em;
}
#neural-forms-app .nf-newform-split__hint {
    font-size: 11px;
    color: #94a3b8;
}

/* AI generation modal — visual baseline matches the builder bundle's
 * .nf-unsaved-modal__* spec exactly so users perceive both modals as
 * the same product surface. The unified tokens are:
 *   overlay     rgba(17,24,39,0.45) + 24px outer padding + flex center
 *   card        14px radius, 0 12px 40px shadow, white, max-h 86vh
 *   head        18px 22px 10px + bottom rule #f1f5f9
 *   title       17px / 600 / #111827
 *   body        16px 22px 4px
 *   foot        16px 22px 18px + top rule #f1f5f9 + 16px margin-top
 *   button      .neural-forms-btn baseline + 108px min-width
 *   error       8px 10px / 6px radius / #fef2f2 + #fecaca / #b91c1c
 *
 * AI modal rendered into document.body (vanilla JS injector path,
 * not React) so we re-emit the exact same numbers here rather than
 * sharing classes with the builder bundle. Unsaved modal stays the
 * authoritative spec — change there first, then mirror here.
 *
 * Card width is 760px max (vs Unsaved's 480px) because the AI prompt
 * textarea benefits from a wider canvas; everything else matches. */
.nf-aiform-modal {
    position: fixed;
    inset: 0;
    z-index: 100001;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
}
.nf-aiform-modal__backdrop {
    position: absolute;
    inset: 0;
    background: rgba( 17, 24, 39, 0.45 );
}
.nf-aiform-modal__panel {
    position: relative;
    background: #ffffff;
    border-radius: 14px;
    box-shadow: 0 12px 40px rgba( 0, 0, 0, 0.18 );
    width: min( 760px, calc( 100vw - 40px ) );
    max-height: 86vh;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
.nf-aiform-modal__head {
    padding: 18px 22px 10px;
    border-bottom: 1px solid #f1f5f9;
}
.nf-aiform-modal__title {
    margin: 0;
    font-size: 17px;
    font-weight: 600;
    color: #111827;
}
.nf-aiform-modal__body {
    padding: 16px 22px 4px;
    overflow-y: auto;
}
.nf-aiform-modal__desc,
.nf-aiform-modal__subtitle {
    margin: 0 0 12px;
    font-size: 14px;
    color: #1f2937;
    line-height: 1.5;
}
.nf-aiform-modal__textarea {
    width: 100%;
    min-height: 180px;
    padding: 10px 12px;
    font-size: 14px;
    line-height: 1.5;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    resize: vertical;
    box-sizing: border-box;
    color: #111827;
    background: #ffffff;
    font-family: inherit;
    transition: border-color 120ms ease, box-shadow 120ms ease;
}
.nf-aiform-modal__textarea:focus {
    outline: none;
    border-color: #080f5b;
    box-shadow: 0 0 0 3px rgba( 8, 15, 91, 0.14 );
}
.nf-aiform-modal__error {
    margin: 12px 0 0;
    padding: 8px 10px;
    background: #fef2f2;
    border: 1px solid #fecaca;
    border-radius: 6px;
    color: #b91c1c;
    font-size: 12px;
}
.nf-aiform-modal__usage {
    margin: 12px 0 0;
    color: #475569;
    font-size: 12px;
    line-height: 1.4;
}
.nf-aiform-modal__foot {
    display: flex;
    gap: 8px;
    justify-content: flex-end;
    flex-wrap: wrap;
    padding: 16px 22px 18px;
    border-top: 1px solid #f1f5f9;
    margin-top: 16px;
}
/* Self-contained button rules — the AI modal renders into
 * document.body so it CAN'T inherit the rich bundle's
 * .neural-forms-btn primary token; specifying everything inline
 * here keeps the look stable wherever the modal is mounted, and
 * avoids cascading conflicts with WP's .button / .button-primary
 * (those classes are intentionally NOT applied to the markup so
 * their high-specificity rules don't win the cascade either). */
.nf-aiform-modal__btn {
    min-width: 108px;
    height: 36px;
    padding: 0 16px;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 600;
    line-height: 1;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    box-sizing: border-box;
    border: 1px solid transparent;
    transition: background 120ms ease, color 120ms ease, border-color 120ms ease;
    font-family: inherit;
}
.nf-aiform-modal__btn:focus-visible {
    outline: 2px solid #2563eb;
    outline-offset: 2px;
}
.nf-aiform-modal__btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

/* Cancel / Edit-prompt — secondary muted (matches Unsaved modal's
 * .nf-unsaved-modal__btn--stay: bg #f3f4f6, text #1f2937,
 * border #e5e7eb). */
.nf-aiform-modal__btn--cancel {
    background: #f3f4f6;
    color: #1f2937;
    border-color: #e5e7eb;
}
.nf-aiform-modal__btn--cancel:hover:not(:disabled) {
    background: #e5e7eb;
}

/* Primary — dark navy, matches Unsaved modal's "Save as Draft"
 * .neural-forms-btn--primary (the rich bundle uses #080f5b for
 * --f-blue-600). Inline values so this works regardless of mount. */
.nf-aiform-modal__btn--primary {
    background: #080f5b;
    color: #ffffff;
    border-color: #080f5b;
}
.nf-aiform-modal__btn--primary:hover:not(:disabled) {
    background: #050942;
    border-color: #050942;
}
.nf-aiform-modal__fields {
    margin: 0;
    padding: 0;
    list-style: none;
    display: grid;
    grid-template-columns: repeat( auto-fill, minmax( 220px, 1fr ) );
    gap: 6px 12px;
    font-size: 12px;
    color: #334155;
}
.nf-aiform-modal__fields li {
    display: flex;
    align-items: center;
    gap: 6px;
}
.nf-aiform-modal__type {
    display: inline-block;
    padding: 1px 6px;
    border-radius: 4px;
    background: #e2e8f0;
    color: #1e293b;
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.02em;
    text-transform: lowercase;
}
.nf-aiform-modal__more {
    color: #64748b;
}
.nf-aiform-modal__warnings {
    margin: 12px 0 0;
    padding: 10px 12px;
    background: #fffbeb;
    border: 1px solid #fde68a;
    border-radius: 4px;
    list-style: disc;
    padding-left: 28px;
    color: #92400e;
    font-size: 12px;
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
     * True when ?page=neural-forms-entries. The entries screen ships
     * as its own split bundle in 1.1.0+ so we can iterate on the inbox
     * UI (Polls/Quizzes folders, AI carousel, bulk toolbar) without
     * rebuilding the byte-protected rich build/index.js.
     */
    private static function is_entries_screen(): bool {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        return $page === 'neural-forms-entries';
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
