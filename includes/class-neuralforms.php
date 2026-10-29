<?php
namespace NeuralForms;

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin class.
 *
 * Responsible for:
 * - Holding the single plugin instance (singleton).
 * - Booting each module by registering its hooks.
 *
 * It does NOT contain business logic. Each module manages its own hooks
 * internally; this class simply calls their boot methods in the right order.
 */
final class NeuralForms {

	private static ?self $instance = null;

	private function __construct() {
		$this->boot();
	}

	/**
	 * Returns the single plugin instance, creating it on first call.
	 * Called via add_action( 'plugins_loaded', 'neural_forms' ).
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Boot sequence - each line here will grow as modules are added in
	 * later steps. For now it's intentionally empty: the plugin loads
	 * cleanly without throwing errors.
	 */
	private function boot(): void {
		// Translations: WordPress.org auto-loads them for this slug since
		// WP 4.6 (and 6.7+ handles "just-in-time" loading for any domain),
		// so an explicit load_plugin_textdomain() call would be redundant.
		Database::maybe_upgrade();
		$this->boot_rest();
		$this->boot_admin();
		$this->boot_frontend();
		$this->boot_ai();
		$this->boot_notifications();
		\NeuralForms\Privacy\PrivacyTools::register();

		/**
		 * Fires once Lite has finished booting all built-in modules.
		 *
		 * Add-on plugins (Neural Forms Pro) hook here to register their own
		 * modules - e.g. the Divi integration, which lives entirely in Pro.
		 * Running at the end of boot guarantees every extension point
		 * (filters, action hooks, REST routes, React slots) is already in
		 * place before add-ons attach to them.
		 *
		 * @param self $instance The Lite plugin singleton.
		 */
		do_action( 'neural_forms_booted', $this );
	}

	private function boot_rest(): void {
		\NeuralForms\Rest\RestAPI::boot();

		// Register cron handlers that need to run outside rest_api_init —
		// e.g. background plan-cache refresh, which fires from WP-Cron on
		// a non-REST request and therefore cannot rely on register_routes()
		// being called first.
		\NeuralForms\Rest\Endpoints\SettingsEndpoint::register_hooks();
	}

	private function boot_admin(): void {
		if ( is_admin() ) {
			\NeuralForms\Admin\AdminPanel::boot();
			// Pre-deactivation data-retention page (0.2.0). Hidden
			// submenu + plugin row action; never auto-redirects.
			\NeuralForms\Admin\DeactivationPage::boot();
		}
	}

	/**
	 * Boot all public-facing (non-admin) functionality.
	 *
	 * Not wrapped in is_admin() - intentionally runs in every context:
	 *   * Shortcode registration must run on the frontend.
	 *   * Block type registration (PublicForms Step 6) must run in both
	 *     frontend and admin contexts so the block editor can discover the block.
	 *   * wp_enqueue_scripts (where assets are registered) only fires on the
	 *     frontend, so admin pages are unaffected.
	 */
	private function boot_frontend(): void {
		\NeuralForms\Frontend\PublicForms::boot();
	}

	/**
	 * Boot the AI layer.
	 *
	 * Hooks are always registered regardless of ai_enabled - the individual
	 * handlers self-guard so toggling the setting in the UI takes effect
	 * immediately without requiring a plugin deactivate/reactivate cycle.
	 */
	private function boot_ai(): void {
		// Custom 5-minute (300s) recurring interval registered for the
		// AI queue processor — see AIQueueProcessor::add_cron_interval.
		// The interval is computed in the filter callback so phpcs can't
		// resolve it statically. 5 minutes is faster than WP's 10-minute
		// soft guideline but matches the latency users expect for AI
		// analysis to surface in the inbox.
		// phpcs:ignore WordPress.WP.CronInterval.ChangeDetected
		add_filter( 'cron_schedules', array( \NeuralForms\AI\AIQueueProcessor::class, 'add_cron_interval' ) );
		\NeuralForms\AI\AIQueueProcessor::boot();
		\NeuralForms\AI\QueueListener::boot();
		// 1.2.0 workflows: event dispatch + queue drain on the same cron hook.
		\NeuralForms\Workflows\WorkflowEngine::boot();
		// 1.2.0 part B: quotes (expiry cron + raw HTML / PDF serving).
		\NeuralForms\Quotes\QuotesBoot::boot();
		\NeuralForms\Payments\PaymentsBoot::boot();

		// Log cleanup: daily cron to prune old DB rows and log files.
		add_action( 'neural_forms_log_cleanup', array( \NeuralForms\Core\Logger::class, 'cleanup' ) );

		// Self-schedule so installs upgrading from a release without this cron
		// pick it up automatically. wp_schedule_event is a no-op when the
		// event is already queued.
		if ( ! wp_next_scheduled( 'neural_forms_log_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'neural_forms_log_cleanup' );
		}

		// Draft retention: daily cron purges non-finalized carousel drafts
		// older than DRAFT_TTL_DAYS. Finalized drafts (user picked "Use this
		// reply") are preserved - they're the canonical entry reply.
		add_action(
			'neural_forms_ai_draft_purge',
			static function () {
				\NeuralForms\Storage\AIResponseRepository::purge_older_than(
					\NeuralForms\Storage\AIResponseRepository::DRAFT_TTL_DAYS
				);
			}
		);

		// Self-schedule so existing installs pick up the new cron on upgrade
		// without requiring a deactivate/reactivate. wp_schedule_event is a
		// no-op when the event is already queued.
		if ( ! wp_next_scheduled( 'neural_forms_ai_draft_purge' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'neural_forms_ai_draft_purge' );
		}
	}

	private function boot_notifications(): void {
		\NeuralForms\Notifications\NotificationService::boot();
	}

	// Prevent cloning and unserialization of the singleton.
	public function __clone() {}
	public function __wakeup() {}
}
