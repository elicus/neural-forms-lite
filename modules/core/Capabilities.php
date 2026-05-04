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
            self::AI_SPAM_DETECT     => self::can( self::AI_SPAM_DETECT ),
            self::AI_REANALYZE       => self::can( self::AI_REANALYZE ),
            self::AI_REFINE_REPLY    => self::can( self::AI_REFINE_REPLY ),
            self::AI_FORM_GENERATE   => self::can( self::AI_FORM_GENERATE ),
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
