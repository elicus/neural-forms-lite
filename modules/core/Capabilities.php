<?php
namespace NeuralForms\Core;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Central registry for premium feature capability checks.
 *
 * Lite defines one filter per premium capability and defaults each to
 * `false`. Pro (or any add-on) may flip a capability to `true` by hooking
 * the corresponding filter:
 *
 *   neural_forms_can_ai_spam_detect   - spam detection & related settings
 *   neural_forms_can_ai_reanalyze     - re-run analysis / regenerate reply
 *   neural_forms_can_ai_refine_reply  - polish user-drafted replies
 *
 * This is strictly the plugin-level (UX) unlock. It decides whether the
 * feature is exposed at all in this install - not whether the user's Cloud
 * plan permits it. Cloud-side enforcement (PlanManager + CloudPlanException)
 * remains the authoritative gate for anything that actually calls the API.
 *
 * Callers should therefore layer the two checks:
 *   1. Capabilities::can()        - plugin gate (Pro installed?)
 *   2. PlanManager::can()         - Cloud gate  (plan permits?)
 *   3. Execute feature / provider call
 *
 * Keeping the two gates separate means the error responses can route to
 * the correct upgrade flow - a locked plugin cap means "install Pro",
 * a locked Cloud cap means "upgrade your Cloud plan".
 */
final class Capabilities {

    /** Capability keys - stable public strings; renaming any of these is
     *  a breaking change for Pro and any third-party integration. */
    const AI_SPAM_DETECT  = 'ai_spam_detect';
    const AI_REANALYZE      = 'ai_reanalyze';
    const AI_REFINE_REPLY   = 'ai_refine_reply';
    const AI_FORM_GENERATE  = 'ai_form_generate';
    /**
     * AI_PROCESS_ENTRIES (added 0.2.0): unlocks per-form "Process with AI"
     * processing, both auto and manual. Lite stores the per-form mode but
     * NEVER actually runs entry analysis on its own — the cloud-call paths
     * are gated on this cap. Pro flips it via the
     * `neural_forms_can_ai_process_entries` filter as part of its Unlocker.
     * Default false → Lite-only installs see the settings UI as an upsell
     * and never enqueue entry-analysis jobs.
     */
    const AI_PROCESS_ENTRIES = 'ai_process_entries';

    /**
     * PAGE_PERFORMANCE_ANALYTICS (added 1.1.0): unlocks the Page
     * Performance section in the Analytics screen — entries grouped
     * by source page (server-recorded HTTP_REFERER / page_url
     * column) with spam / priority breakdowns. Lite installs see a
     * locked upsell card; Pro flips this to true via the
     * `neural_forms_can_page_performance_analytics` filter.
     *
     * Source-page CAPTURE happens unconditionally in Lite (column
     * was already populated from HTTP_REFERER) — only the analytics
     * UI + aggregation endpoint are gated.
     */
    const PAGE_PERFORMANCE_ANALYTICS = 'page_performance_analytics';

    /**
     * POLL_QUIZ_UNLIMITED_SUBMISSIONS (added 1.1.0): Lite caps poll
     * and quiz forms at 1,000 non-trash submissions per form. Pro
     * flips this to true to lift the cap. Plain contact / multi-step
     * forms are NOT limited regardless of plan.
     *
     * Spam entries count toward the limit (they consume storage and
     * are real submissions); trashed entries do not.
     *
     * NOTE: this is a LOCAL PRODUCT LIMIT, not a security boundary.
     * Since WordPress is self-hosted, site owners with code/database
     * access can modify local behavior. Cloud/AI credit limits must
     * be enforced on api.neuralforms.ai — see the full rationale on
     * SubmitEndpoint::check_poll_quiz_submission_limit().
     */
    const POLL_QUIZ_UNLIMITED_SUBMISSIONS = 'poll_quiz_unlimited_submissions';

    /**
     * FIELD_MATRIX (added 1.1.0): unlocks the survey-style Matrix field
     * type — admins define rows + columns and pick a selection type
     * (radio = one column per row; checkbox = many columns per row).
     * Lite ships the entire Matrix engine (registry awareness, builder
     * UI, frontend renderer, validator, entry display, AI generation
     * support). Pro flips this filter to true and the field becomes
     * fully usable; Lite-only installs see Matrix in the palette as a
     * locked Pro upsell and the type cannot be added.
     *
     * Existing forms that already contain Matrix fields (Pro
     * deactivated after creation) keep their schema intact — Lite
     * never strips unknown fields. The field is rendered as an
     * admin-visible "requires Pro" placeholder in the builder; on the
     * frontend the renderer skips it gracefully so the rest of the
     * form still works.
     */
    const FIELD_MATRIX = 'field_matrix';

    /**
     * FIELD_REPEATER (added 1.1.0): unlocks the Repeater field type — a
     * repeatable group of child fields used for "team members", "guests",
     * "work experience", "documents", and similar list-shaped data.
     * Whitelisted v1 child types are text / email / phone / number / url /
     * textarea / select / radio / checkbox / checkboxes / date / file.
     * Nested repeater / matrix / row / step / section / poll /
     * quiz_question are explicitly NOT allowed inside a repeater in v1.
     *
     * Lite ships the entire Repeater engine (registry awareness, builder
     * UI, frontend renderer, validator + nested-file uploader, entry
     * display, AI generation support). Pro flips this filter to true and
     * the field becomes fully usable; Lite-only installs see Repeater
     * in the palette as a locked Pro upsell and the type cannot be
     * added.
     *
     * Existing forms that already contain Repeater fields (Pro
     * deactivated after creation) keep their schema intact — Lite
     * never strips unknown fields. The field is rendered as an
     * admin-visible "requires Pro" placeholder in the builder; on the
     * frontend the renderer skips it gracefully so the rest of the
     * form still works.
     */
    const FIELD_REPEATER = 'field_repeater';

    /**
     * True when the given premium capability is unlocked in this install.
     *
     * @param string $cap One of the AI_* constants on this class.
     */
    public static function can( string $cap ): bool {
        /**
         * Filter: neural_forms_can_{$cap}
         *
         * Return true to unlock the capability. Default is false. Registered
         * by Lite once per known cap below; additional caps registered by
         * third parties are not dispatched through here.
         *
         * @param bool $allowed Default false.
         */
        return (bool) apply_filters( "neural_forms_can_{$cap}", false );
    }

    /**
     * Snapshot of every known capability. Suitable for localizing to the
     * admin React bundle or returning from a REST endpoint.
     */
    public static function all(): array {
        return [
            self::AI_SPAM_DETECT             => self::can( self::AI_SPAM_DETECT ),
            self::AI_REANALYZE               => self::can( self::AI_REANALYZE ),
            self::AI_REFINE_REPLY            => self::can( self::AI_REFINE_REPLY ),
            self::AI_FORM_GENERATE           => self::can( self::AI_FORM_GENERATE ),
            self::AI_PROCESS_ENTRIES         => self::can( self::AI_PROCESS_ENTRIES ),
            self::PAGE_PERFORMANCE_ANALYTICS      => self::can( self::PAGE_PERFORMANCE_ANALYTICS ),
            self::POLL_QUIZ_UNLIMITED_SUBMISSIONS => self::can( self::POLL_QUIZ_UNLIMITED_SUBMISSIONS ),
            self::FIELD_MATRIX                    => self::can( self::FIELD_MATRIX ),
            self::FIELD_REPEATER                  => self::can( self::FIELD_REPEATER ),
        ];
    }

    /**
     * Canonical WP_Error returned when a request hits a locked capability.
     *
     * The `pro_feature_locked` code is intentionally distinct from the
     * Cloud-plan `feature_locked` code so the frontend can route to the
     * right upgrade flow.
     *
     * @param string $cap The capability that was locked.
     */
    public static function locked_error( string $cap ): WP_Error {
        return new WP_Error(
            'pro_feature_locked',
            __( 'This feature requires Neural Forms Pro.', 'neural-forms-lite' ),
            [
                'status'       => 403,
                'feature'      => $cap,
                'requires_pro' => true,
            ]
        );
    }
}
