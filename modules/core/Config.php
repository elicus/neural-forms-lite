<?php
namespace NeuralForms\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes plugin-wide settings stored in wp_options.
 *
 * All settings live under a single option key ('neural_forms_settings') as an
 * associative array. This keeps wp_options clean and makes export/import
 * trivial in the future.
 *
 * Usage:
 *   Config::get('cloud_ai_enabled');      // false
 *   Config::set('ai_enabled', true);
 *   Config::update(['cloud_ai_enabled' => true, 'cloud_api_key' => 'nf_live_...']);
 */
class Config {

    const OPTION_KEY = 'neural_forms_settings';

    /**
     * Sensible defaults. These are merged with whatever is stored in the DB,
     * so a missing key always returns a predictable value.
     */
    private static array $defaults = [
        'ai_enabled'            => false,
        'ai_auto_process'       => true,       // automatically queue AI analysis on submission
        'privacy_mode'          => false,      // when true: skip IP + user-agent storage
        'sender_name'           => '',         // From: name for wp_mail
        'sender_email'          => '',         // From: address for wp_mail
        'reply_subject_pattern' => 'Re: Your submission', // default reply subject

        // Notifications
        'enable_admin_notification' => true,   // email admin on every new submission
        'admin_notification_email'  => '',     // defaults to get_option('admin_email')
        'enable_autoresponder'      => false,  // send confirmation to the submitter
        'autoresponder_subject'     => 'Thank you for your submission',
        'autoresponder_body'        => "Hi,\n\nThank you for reaching out. We'll be in touch shortly.\n\n{site_name}",

        // AI spam detection
        'ai_spam_enabled'              => false,   // master switch - off by default
        'ai_spam_mode'                 => 'suggest', // 'suggest' | 'auto'
        'ai_spam_threshold'            => 85,      // 60-100; auto-move only above this confidence
        'email_skip_spam_notifications' => false,  // skip email notifications for spam entries

        // CAPTCHA
        'captcha_provider'   => 'none',       // 'none' | 'turnstile' | 'recaptcha_v2'
        'captcha_site_key'   => '',           // public key, rendered in HTML
        'captcha_secret_key' => '',           // private key, used only server-side

        // Neural Forms Cloud AI. The cloud URL itself is no longer stored as
        // an option - it is resolved by AiManager::resolve_base_url() from
        // the NEURAL_FORMS_CLOUD_API_URL constant or the production default.
        'cloud_ai_enabled'      => false,       // use Cloud API instead of direct provider calls
        'cloud_api_key'         => '',          // Bearer token for api.neuralforms.ai
        'cloud_signup_complete' => false,      // sticky once a key has been saved; suppresses first-run signup overlay even after revoke
    ];

    /** In-memory cache so we only hit the DB once per request. */
    private static ?array $cache = null;

    // Public API

    /**
     * Get a single setting value.
     *
     * @param string $key     One of the keys defined in $defaults.
     * @param mixed  $default Override the built-in default for this call only.
     * @return mixed
     */
    public static function get( string $key, $default = null ) {
        $settings = self::all();
        $fallback  = $default ?? ( self::$defaults[ $key ] ?? null );

        return $settings[ $key ] ?? $fallback;
    }

    /**
     * Persist a single setting.
     */
    public static function set( string $key, $value ): void {
        self::update( [ $key => $value ] );
    }

    /**
     * Persist multiple settings at once (partial update - unmentioned keys
     * keep their existing values).
     *
     * @param array $new_values Associative array of key => value pairs.
     */
    public static function update( array $new_values ): void {
        $current = self::all();
        $merged  = array_merge( $current, $new_values );

        update_option( self::OPTION_KEY, $merged, false ); // autoload = false

        self::$cache = $merged;
    }

    /**
     * Return all settings, merged with defaults so every key is always present.
     *
     * @return array
     */
    public static function all(): array {
        if ( self::$cache === null ) {
            $stored      = get_option( self::OPTION_KEY, [] );
            self::$cache = array_merge( self::$defaults, is_array( $stored ) ? $stored : [] );
        }

        return self::$cache;
    }

    /**
     * Wipe the in-memory cache. Useful in tests or after an external option
     * update (e.g., a REST endpoint wrote directly via update_option).
     */
    public static function flush_cache(): void {
        self::$cache = null;
    }

    /**
     * Returns the current feature-flag state.
     *
     * Flags that map to a stored setting are driven by that value - flipping
     * the setting in the DB automatically toggles the flag everywhere,
     * including the JS boot data exposed to the React app.
     *
     * Flags for unimplemented features default to false until their step
     * is reached. Add new flags here as features are introduced.
     *
     * @return array<string, bool>
     */
    public static function features(): array {
        return [
            'ai'      => (bool) self::get( 'ai_enabled' ),  // driven by stored setting
            'webhook' => false,                              // Step 21+
        ];
    }
}
