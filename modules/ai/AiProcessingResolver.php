<?php
namespace NeuralForms\AI;

use NeuralForms\Core\Capabilities;
use NeuralForms\Core\Config;
use NeuralForms\Forms\FormRepository;
use NeuralForms\Forms\SchemaHelper;

defined( 'ABSPATH' ) || exit;

/**
 * Per-form "Process with AI" mode resolver (added 0.2.0).
 *
 * Single source of truth for "should AI run for this form?". Used by
 * QueueListener (auto-process gate on submission), and intended to be
 * called by future manual-analyze endpoints + Pro-side UI gates.
 *
 * Two-layer gate:
 *
 *   Layer 1 — capability + cloud connection
 *     The Lite plugin can STORE a per-form mode but never RUNS entry
 *     analysis. If the AI_PROCESS_ENTRIES capability is not unlocked
 *     (= Pro not installed/active), or the cloud isn't connected, the
 *     resolver returns 'unavailable' regardless of the form's saved
 *     mode. The settings UI surfaces this as an upsell.
 *
 *   Layer 2 — per-form mode
 *     When the capability/connection layer permits, the form's saved
 *     `form_schema.settings.ai_processing.mode` decides:
 *
 *       inherit       → defer to global Config::get('ai_auto_process')
 *       disabled      → never run AI for this form
 *       every_entry   → run on every submission
 *       manual        → no automatic processing; manual UI may run
 *       final_results → no per-entry AI; aggregate features only
 *
 * Returns one of:
 *   'unavailable'         — capability or cloud not ready; UI shows upsell/setup
 *   'allow_auto'          — auto-process this entry now
 *   'allow_manual_only'   — skip auto; manual surfaces may still run
 *   'skip'                — fully disabled for this form
 *
 * QueueListener treats only 'allow_auto' as "queue the job". Everything
 * else short-circuits cleanly with a structured log line.
 */
class AiProcessingResolver {

	const RESULT_UNAVAILABLE          = 'unavailable';
	const RESULT_ALLOW_AUTO           = 'allow_auto';
	const RESULT_ALLOW_MANUAL_ONLY    = 'allow_manual_only';
	const RESULT_SKIP                 = 'skip';

	/**
	 * Resolve the AI-processing decision for a given form.
	 *
	 * @param int $form_id  Form id; 0/invalid → 'unavailable'.
	 * @return string       One of the RESULT_* constants.
	 */
	public static function resolve( int $form_id ): string {
		// Layer 1a: capability gate. Lite-only installs return false here;
		// Pro flips the filter in its Unlocker. We deliberately do NOT
		// gate on PlanManager here — the resolver only answers the
		// "is this allowed at all?" question; cloud-plan checks happen
		// closer to the actual provider call.
		if ( ! Capabilities::can( Capabilities::AI_PROCESS_ENTRIES ) ) {
			return self::RESULT_UNAVAILABLE;
		}

		// Layer 1b: cloud connection. Without a configured cloud +
		// enabled flag, no AI of any kind can run. UI shows a "Connect
		// Neural Forms Cloud" CTA in this state.
		if ( ! Config::get( 'ai_enabled' ) )         return self::RESULT_UNAVAILABLE;
		if ( ! Config::get( 'cloud_ai_enabled' ) )    return self::RESULT_UNAVAILABLE;
		if ( ! Config::get( 'cloud_api_key' ) )       return self::RESULT_UNAVAILABLE;

		// Layer 2: per-form mode.
		$mode = self::form_mode( $form_id );

		switch ( $mode ) {
			case 'disabled':
				return self::RESULT_SKIP;

			case 'every_entry':
				return self::RESULT_ALLOW_AUTO;

			case 'manual':
			case 'final_results':
				return self::RESULT_ALLOW_MANUAL_ONLY;

			case 'inherit':
			default:
				// Defer to the global auto-process toggle. When global
				// auto-process is off but spam detection is on, the
				// existing QueueListener still queues for spam — this
				// resolver only answers the per-form question, so we
				// return ALLOW_AUTO when global says "auto" and let the
				// listener's existing gate decide the rest.
				$auto = (bool) Config::get( 'ai_auto_process' );
				return $auto
					? self::RESULT_ALLOW_AUTO
					: self::RESULT_ALLOW_MANUAL_ONLY;
		}
	}

	/**
	 * Read the per-form mode from form_schema.settings.ai_processing.
	 * Falls back to the canonical default for missing/malformed data.
	 *
	 * Public so the React Settings UI can fetch the same answer via
	 * REST without re-implementing the lookup.
	 */
	public static function form_mode( int $form_id ): string {
		if ( $form_id < 1 ) return SchemaHelper::AI_PROCESSING_DEFAULT_MODE;
		$form = FormRepository::find( $form_id );
		if ( ! is_array( $form ) ) return SchemaHelper::AI_PROCESSING_DEFAULT_MODE;

		$schema = is_array( $form['form_schema'] ?? null ) ? $form['form_schema'] : [];
		$schema_settings = is_array( $schema['settings'] ?? null ) ? $schema['settings'] : [];
		$ai_processing = is_array( $schema_settings['ai_processing'] ?? null )
			? $schema_settings['ai_processing']
			: [];

		$normalized = SchemaHelper::normalize_ai_processing_settings( $ai_processing );
		return $normalized['mode'];
	}
}
