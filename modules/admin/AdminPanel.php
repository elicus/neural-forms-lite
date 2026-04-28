<?php
namespace NeuralForms\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Neural Forms admin menu and enqueues assets.
 *
 * Each page renders a single <div id="neural-forms-app"> mount point.
 * The React app (Step 7) handles all routing and UI from there.
 *
 * Assets are enqueued only on Neural Forms pages — never on unrelated
 * WP admin screens.
 */
class AdminPanel {

    /** Slug of the top-level menu item. Used to detect Neural Forms pages. */
    const MENU_SLUG = 'neural-forms';

    /**
     * Maps WP page slugs to clean screen identifiers passed to the React app.
     * The first entry is also the default — no magic string needed anywhere.
     */
    const SCREENS = [
        'neural-forms'    => 'forms',
        'neural-forms-entries'  => 'entries',
        'neural-forms-stats'    => 'stats',
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
     * (security updates, etc.) — only callbacks registered by *other* plugins
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

    // -------------------------------------------------------------------------
    // Menus
    // -------------------------------------------------------------------------

    public static function register_menus(): void {
        // Top-level menu — slug 'neural-forms' so clicking the parent item
        // opens the Forms screen directly.  MENU_SLUG ('neural-forms') is used
        // by is_neural_forms_page() for hook detection.
        add_menu_page(
            __( 'Neural Forms', 'neural-forms' ),           // page <title>
            __( 'Neural Forms', 'neural-forms' ),           // menu label
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
            __( 'Forms — Neural Forms', 'neural-forms' ),
            __( 'Forms', 'neural-forms' ),
            NEURAL_FORMS_CAP_MANAGE_FORMS,
            'neural-forms',
            [ self::class, 'render_page' ]
        );

        // Match WP core's Plugins-update bubble: same markup, same classes,
        // so the existing admin CSS styles it without any new rules.
        $unread_count = self::unread_entries_count();
        $entries_label = __( 'Entries', 'neural-forms' );
        if ( $unread_count > 0 ) {
            $entries_label .= sprintf(
                ' <span class="awaiting-mod count-%1$d"><span class="pending-count">%2$s</span></span>',
                (int) $unread_count,
                esc_html( number_format_i18n( $unread_count ) )
            );
        }

        add_submenu_page(
            'neural-forms',
            __( 'Entries — Neural Forms', 'neural-forms' ),
            $entries_label,
            NEURAL_FORMS_CAP_MANAGE_FORMS,
            'neural-forms-entries',
            [ self::class, 'render_page' ]
        );

        add_submenu_page(
            'neural-forms',
            __( 'Stats — Neural Forms', 'neural-forms' ),
            __( 'Stats', 'neural-forms' ),
            NEURAL_FORMS_CAP_MANAGE_FORMS,
            'neural-forms-stats',
            [ self::class, 'render_page' ]
        );

        add_submenu_page(
            'neural-forms',
            __( 'Settings — Neural Forms', 'neural-forms' ),
            __( 'Settings', 'neural-forms' ),
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
                __( 'Import — Neural Forms', 'neural-forms' ),
                __( 'Import', 'neural-forms' ),
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
     * before dbDelta has run) never fatals the menu — we just skip the badge.
     */
    private static function unread_entries_count(): int {
        try {
            $counts = \NeuralForms\Storage\EntryRepository::count_by_view();
            return isset( $counts['unread'] ) ? (int) $counts['unread'] : 0;
        } catch ( \Throwable $e ) {
            return 0;
        }
    }

    // -------------------------------------------------------------------------
    // Page renderer
    // -------------------------------------------------------------------------

    /**
     * All Neural Forms pages share one render callback.
     * The React app reads window.neuralFormsAdmin.screen — set by PHP — to decide
     * which screen to show. It never parses the URL itself.
     */
    public static function render_page(): void {
        ?>
        <div class="wrap">
            <div id="neural-forms-app"></div>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Assets
    // -------------------------------------------------------------------------

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

        // JS — load deps and version from the asset file generated by webpack
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

        // CSS — version pinned to filemtime so every rebuild busts the browser cache.
        if ( file_exists( $build_dir . 'index.css' ) ) {
            wp_enqueue_style(
                'neural-forms-admin',
                $build_url . 'index.css',
                [ 'wp-components' ],
                (string) filemtime( $build_dir . 'index.css' )
            );
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * True if the current screen belongs to Neural Forms.
     */
    private static function is_neural_forms_page( string $hook ): bool {
        return strpos( $hook, self::MENU_SLUG ) !== false;
    }

    /**
     * Resolve the current screen identifier from the ?page= query parameter.
     *
     * Falls back to the first entry in SCREENS — no magic string.
     * If new screens are added to SCREENS, the fallback updates automatically.
     */
    private static function get_current_screen(): string {
        $page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';

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
         * data here — e.g. $data['pro'] = [ 'license' => ..., 'slots' => [...] ] —
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
