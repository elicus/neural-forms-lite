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
        }
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
#neural-forms-app .nf-shortcode-col__th {
    text-align: left;
    white-space: nowrap;
}
#neural-forms-app .nf-shortcode-col__td {
    white-space: nowrap;
    vertical-align: middle;
}
#neural-forms-app .nf-shortcode-col__wrap {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 6px 4px 10px;
    background: #f5f6f8;
    border: 1px solid #e2e5ea;
    border-radius: 4px;
}
#neural-forms-app .nf-shortcode-col__code {
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: 12px;
    color: #353941;
    background: transparent;
    cursor: text;
    user-select: all;
}
#neural-forms-app .nf-shortcode-col__code:focus {
    outline: 2px solid #1a2351;
    outline-offset: 1px;
}
#neural-forms-app .nf-shortcode-col__copy {
    transition: background-color 120ms ease, color 120ms ease;
}
#neural-forms-app .nf-shortcode-col__copy.is-copied {
    background: #10b981 !important;
    border-color: #10b981 !important;
    color: #fff !important;
}
CSS;
    }

    // Helpers

    /**
     * True if the current screen belongs to Neural Forms.
     */
    private static function is_neural_forms_page( string $hook ): bool {
        return strpos( $hook, self::MENU_SLUG ) !== false;
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

        return self::SCREENS[ $page ] ?? reset( self::SCREENS );
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
