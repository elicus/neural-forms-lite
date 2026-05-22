<?php
namespace NeuralForms\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * PublicForms
 *
 * Bootstraps all public-facing (non-admin) functionality for the plugin.
 * Responsibilities are strictly limited to wiring hooks and registering assets.
 * No rendering logic lives here.
 *
 * Separation of concerns:
 *
 *   PublicForms       '->-> hook registration, asset registration
 *   ShortcodeHandler  '->-> [neural-forms] shortcode
 *   BlockHandler      '->-> Gutenberg block type (Step 6)
 *   FormRenderer      '->-> HTML output
 * *
 * Asset strategy: register-then-enqueue-on-demand
 *
 * Handles are registered here (on wp_enqueue_scripts) but NOT globally
 * enqueued. FormRenderer::render() calls wp_enqueue_style/script() at render
 * time, so the assets only appear on pages that actually contain a form.
 * WordPress deduplicates enqueue calls - rendering three forms on one page
 * produces a single <link> and <script> tag.
 *
 * The inline config object (window.neuralFormsPublic) is attached to the script
 * handle via wp_add_inline_script() at registration time. It is output only
 * when the handle is enqueued, so it never appears on pages without a form.
 * */
class PublicForms {

	// Boot

	/**
	 * Register all public hooks.
	 *
	 * Called by Neural Forms::boot_frontend() unconditionally (not inside is_admin())
	 * because:
	 *   * Shortcode registration must run on the frontend.
	 *   * Block type registration (Step 6) must run in both frontend and admin
	 *     contexts so the block editor can discover the block.
	 *   * wp_enqueue_scripts only fires on the frontend, so admin pages are
	 *     unaffected by the asset registration hook below.
	 */
	public static function boot(): void {
		// Shortcode - must be registered on or before `init`.
		add_action( 'init', array( ShortcodeHandler::class, 'boot' ) );

		// Block type - runs in both frontend and admin contexts so the block editor
		// can discover the block, and so it renders correctly on the frontend.
		BlockHandler::boot();

		// Asset registration - wp_enqueue_scripts fires on the frontend only.
		// Handles are registered here; enqueue happens inside FormRenderer::render().
		add_action( 'wp_enqueue_scripts', array( static::class, 'register_assets' ) );
	}

	// Asset registration

	/**
	 * Register frontend style and script handles, and attach the JS config object.
	 *
	 * Called on the wp_enqueue_scripts action (frontend-only).
	 *
	 * Why register here, enqueue in render?
	 *   wp_add_inline_script() requires a registered handle to attach to. We
	 *   register both handles here so the inline config is always present when
	 *   the script is enqueued, regardless of which hook triggered the enqueue.
	 *
	 * @internal  Public only because it is used as a hook callback.
	 */
	public static function register_assets(): void {
		// Cache-buster strategy for the public-facing form assets:
		//
		// `NEURAL_FORMS_VERSION` is the canonical plugin version Pro
		// reads for its dep check; it only changes at tagged releases.
		// Between releases we still ship bug fixes to form.css and
		// form-submit.js (e.g. the bracket-aware serializer that
		// unblocks Matrix submissions). With a static version stamp
		// browsers happily serve the cached old copy for weeks.
		//
		// Falling back to filemtime() of the actual file solves this:
		// any on-disk change automatically produces a new ?ver= URL,
		// so browsers see a fresh asset without forcing a hard reload.
		// filemtime() returns false on read errors — in that case we
		// drop back to NEURAL_FORMS_VERSION so the asset still loads
		// (no version is worse than a static version for caches).
		$css_path = NEURAL_FORMS_PATH . 'modules/frontend/assets/css/form.css';
		$js_path  = NEURAL_FORMS_PATH . 'modules/frontend/assets/js/form-submit.js';
		$css_ver  = file_exists( $css_path ) ? (string) filemtime( $css_path ) : NEURAL_FORMS_VERSION;
		$js_ver   = file_exists( $js_path ) ? (string) filemtime( $js_path ) : NEURAL_FORMS_VERSION;

		// ' Style '
		wp_register_style(
			'neural-forms-form',
			NEURAL_FORMS_URL . 'modules/frontend/assets/css/form.css',
			array(),
			$css_ver
		);

		// ' Script '
		// No external dependencies - form-submit.js is vanilla JS.
		// Loaded in the footer (true) so it does not block page rendering.
		wp_register_script(
			'neural-forms-form-submit',
			NEURAL_FORMS_URL . 'modules/frontend/assets/js/form-submit.js',
			array(),
			$js_ver,
			true
		);

		// ' Inline config '
		// Output as a <script> block immediately before form-submit.js so
		// window.neuralFormsPublic is defined when the script executes.
		//
		// rest_url() returns the full absolute URL (scheme + host + path) which
		// works correctly in subdirectory installs and behind reverse proxies.
		// RestAPI::NAMESPACE ('neural-forms/v1') is used as the path so the JS can
		// build endpoint URLs without knowing the WP install root.
		//
		// Example output:
		// window.neuralFormsPublic = {"api":{"url":"https://example.com/wp-json/neural-forms/v1/"}};
		wp_add_inline_script(
			'neural-forms-form-submit',
			'window.neuralFormsPublic = ' . wp_json_encode(
				array(
					'api' => array(
						'url' => rest_url( \NeuralForms\Rest\RestAPI::NAMESPACE ),
					),
				)
			) . ';',
			'before'
		);
	}
}
