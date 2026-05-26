<?php
namespace NeuralForms\Frontend;

use NeuralForms\Core\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * BlockHandler
 *
 * Registers the neural-forms/form Gutenberg block and its PHP render callback.
 *
 * Architecture
 *
 * This is a fully block-native dynamic block. The editor shows a live
 * server-side-rendered preview of the actual form via <ServerSideRender>.
 * The PHP render callback produces all frontend HTML - there is no save()
 * output (save: () => null).
 *
 * The block does NOT wrap or depend on the shortcode. Both the block and the
 * shortcode call FormRenderer::render() independently as two entry points
 * into the same shared rendering engine.
 *
 * Rendering pipeline
 *
 *   block attributes '->-> BlockHandler::render()
 *     '->-> FormRenderer::render( formId )  '->-> form HTML
 *     '->-> build_scoped_css( attributes )  '->-> CSS string scoped to
 *                                            .neural-forms-block-<uid> so
 *                                            it can never leak into a
 *                                            sibling block.
 *     '->-> build_wrap_style( attributes )  '->-> inline layout styles on wrapper div
 *
 *   Routing of that scoped CSS is hybrid:
 *     - Frontend page load   '->-> wp_add_inline_style( 'neural-forms-form' )
 *                                  enqueue path (no raw <style> in document body).
 *     - Editor preview       '->-> inline <style> tag scoped to the block uid,
 *       (REST/SSR call)            because the ServerSideRender REST endpoint
 *                                  returns only the HTML and does not flush
 *                                  WordPress's enqueue queue.
 *
 *     '->-> final output (frontend):  <div class="wrap" style="...">...form...</div>
 *     '->-> final output (editor):    <style>...</style><div class="wrap" ...>...form...</div>
 *
 * Editor preview
 *
 * The block editor uses @wordpress/server-side-render to call this same render
 * callback via the REST API. When rendering in editor context (REST request
 * inside admin), the callback skips enqueuing form-submit.js to prevent the
 * submission handler from binding inside the editor iframe.
 *
 * Boot flow
 *
 *   plugins_loaded '->-> Neural Forms::boot_frontend() '->-> PublicForms::boot()
 *     '->-> BlockHandler::boot()
 *       '->-> add_action( 'init', BlockHandler::register )
 *         '->-> wp_register_script( 'neural-forms-block', ... )
 *         '->-> wp_register_style( 'neural-forms-block-editor', form.css )
 *         '->-> register_block_type( block.json, render_callback )
 */
class BlockHandler {

	// Boot

	/**
	 * Called by PublicForms::boot(). Registers the init hook.
	 *
	 * Must run unconditionally (not inside is_admin()) because:
	 *   * register_block_type() needs to fire on every request so the block
	 *     renders correctly on the frontend and is discoverable in the editor.
	 */
	public static function boot(): void {
		add_action( 'init', [ static::class, 'register' ] );
		add_filter( 'wp_theme_json_data_theme', [ static::class, 'ensure_default_spacing_sizes' ] );
		// (Removed) Earlier versions globally suppressed E_DEPRECATED on
		// rest_api_init to keep deprecated-warning output from third-party
		// theme functions.php files from corrupting the JSON body of
		// ServerSideRender's block-renderer REST endpoint. WordPress.org
		// Plugin Check flagged the global error_reporting() toggle as a
		// path-disclosure risk (it would also mask our OWN deprecated
		// warnings under WP_DEBUG_DISPLAY, hiding real bugs from devs).
		// If a third-party theme emits warnings during REST that break
		// the editor preview, that's a theme-side bug; we no longer
		// silence them on its behalf.
	}

	/**
	 * Ensure WordPress's built-in default spacing sizes are available in the
	 * block editor. Some themes set defaultSpacingSizes: false without providing
	 * their own spacingSizes, which leaves SpacingSizesControl with no scale and
	 * forces it to fall back to a raw px input. When that happens, re-enable the
	 * WP default scale so our Dimensions controls render as sliders.
	 *
	 * @internal  Public because it is used as a hook callback.
	 */
	public static function ensure_default_spacing_sizes( \WP_Theme_JSON_Data $theme_json ): \WP_Theme_JSON_Data {
		$data = $theme_json->get_data();

		$disabled = isset( $data['settings']['spacing']['defaultSpacingSizes'] )
			&& ! $data['settings']['spacing']['defaultSpacingSizes'];

		$has_custom = ! empty( $data['settings']['spacing']['spacingSizes'] );

		if ( $disabled && ! $has_custom ) {
			$theme_json->update_with( [
				'version'  => 3,
				'settings' => [
					'spacing' => [
						'defaultSpacingSizes' => true,
					],
				],
			] );
		}

		return $theme_json;
	}

	// Registration

	/**
	 * Register the editor script handle and block type.
	 *
	 * Script is registered before register_block_type() because block.json's
	 * editorScript key references the handle by name - it must already exist
	 * in the WP script registry when register_block_type() reads the JSON.
	 *
	 * @internal  Public because it is used as a hook callback.
	 */
	public static function register(): void {
		$asset_file = NEURAL_FORMS_PATH . 'modules/admin/build/block.asset.php';
		$asset      = file_exists( $asset_file )
			? require $asset_file
			: [ 'dependencies' => [], 'version' => NEURAL_FORMS_VERSION ];

		wp_register_script(
			'neural-forms-block',
			NEURAL_FORMS_URL . 'modules/admin/build/block.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		// Register form.css as an editor style so the ServerSideRender preview
		// inside the block editor displays the form with correct styling.
		// On the frontend, PublicForms::register_assets() handles this handle.
		wp_register_style(
			'neural-forms-block-editor',
			NEURAL_FORMS_URL . 'modules/frontend/assets/css/form.css',
			[],
			NEURAL_FORMS_VERSION
		);

		register_block_type(
			__DIR__ . '/block/block.json',
			[
				'render_callback' => [ static::class, 'render' ],
				'editor_style'   => 'neural-forms-block-editor',
			]
		);

		// 0.2.0: register the 3 extra blocks (quiz / multi-step / poll).
		static::register_extra_blocks();
	}

	/**
	 * Register the 3 extra blocks added in 0.2.0:
	 *
	 *   - neural-forms/quiz
	 *   - neural-forms/multi-step
	 *   - neural-forms/poll
	 *
	 * They share the form block's full attribute schema (so this same
	 * render() method renders any of them) and a single editor-script
	 * bundle (build/blocks-extra.js, compiled from
	 * src/blocks-extra-entry.js via `npm run build:blocks-extra`).
	 *
	 * Why they're registered programmatically rather than each having
	 * its own block.json: their attribute schema is an exact clone of
	 * the form block's, and three near-duplicate 200-line block.json
	 * files would drift the moment a new attribute is added. The
	 * single source of truth stays in modules/frontend/block/block.json.
	 */
	private static function register_extra_blocks(): void {
		$asset_file = NEURAL_FORMS_PATH . 'modules/admin/build/blocks-extra.asset.php';
		$asset      = file_exists( $asset_file )
			? require $asset_file
			: [ 'dependencies' => [], 'version' => NEURAL_FORMS_VERSION ];

		// Ensure neural-forms-block (protected block.js, registers neural-forms/form)
		// loads before this bundle so unregisterBlockType('neural-forms/form') works.
		$deps = $asset['dependencies'];
		if ( ! in_array( 'neural-forms-block', $deps, true ) ) {
			$deps[] = 'neural-forms-block';
		}
		if ( ! in_array( 'wp-dom-ready', $deps, true ) ) {
			$deps[] = 'wp-dom-ready';
		}

		wp_register_script(
			'neural-forms-blocks-extra',
			NEURAL_FORMS_URL . 'modules/admin/build/blocks-extra.js',
			$deps,
			$asset['version'],
			true
		);

		// Capability snapshot for the block editor — only the two field-type
		// caps that gate design panels. Resolved server-side so Pro active
		// = true, Pro absent = false, without the block bundle needing to
		// know about the admin-page JS global (neuralFormsAdmin).
		wp_localize_script(
			'neural-forms-blocks-extra',
			'neuralFormsBlockCaps',
			[
				'fieldRepeater' => Capabilities::can( Capabilities::FIELD_REPEATER ),
				'fieldMatrix'   => Capabilities::can( Capabilities::FIELD_MATRIX ),
			]
		);

		// Read the form block's metadata once so attribute + supports
		// schemas stay in sync across all 4 blocks. If block.json is
		// missing or unreadable for any reason, bail rather than
		// registering blocks with no attributes (which would corrupt
		// every saved instance on the next editor load).
		$base_json = @file_get_contents( __DIR__ . '/block/block.json' );
		if ( $base_json === false ) {
			return;
		}
		$base = json_decode( $base_json, true );
		if ( ! is_array( $base ) ) {
			return;
		}
		$attributes = isset( $base['attributes'] ) && is_array( $base['attributes'] ) ? $base['attributes'] : [];
		$supports   = isset( $base['supports'] )   && is_array( $base['supports'] )   ? $base['supports']   : [];

		$blocks = [
			[
				'name'        => 'neural-forms/quiz',
				'title'       => __( 'Neural Quiz', 'neural-forms-lite' ),
				'description' => __( 'Embed a Neural Forms quiz on any post or page. Picks up forms with quiz_question fields.', 'neural-forms-lite' ),
				'keywords'    => [ 'quiz', 'questions', 'score' ],
			],
			[
				'name'        => 'neural-forms/multi-step',
				'title'       => __( 'Neural Multi Step', 'neural-forms-lite' ),
				'description' => __( 'Embed a multi-step Neural Forms form. Picks up forms with two or more Step layout blocks.', 'neural-forms-lite' ),
				'keywords'    => [ 'multi-step', 'wizard', 'steps' ],
			],
			[
				'name'        => 'neural-forms/poll',
				'title'       => __( 'Neural Poll', 'neural-forms-lite' ),
				'description' => __( 'Embed a Neural Forms poll. Picks up forms that contain a Poll field.', 'neural-forms-lite' ),
				'keywords'    => [ 'poll', 'survey', 'vote' ],
			],
		];

		foreach ( $blocks as $b ) {
			register_block_type(
				$b['name'],
				[
					'api_version'     => 3,
					'title'           => $b['title'],
					'description'     => $b['description'],
					'category'        => 'embed',
					'keywords'        => $b['keywords'],
					'textdomain'      => 'neural-forms-lite',
					'attributes'      => $attributes,
					'supports'        => $supports,
					'editor_script'   => 'neural-forms-blocks-extra',
					'editor_style'    => 'neural-forms-block-editor',
					'render_callback' => [ static::class, 'render' ],
				]
			);
		}
	}

	// Render callback

	/**
	 * PHP render callback for the neural-forms/form block.
	 *
	 * When the block carries formatting attributes, wraps the form HTML in a
	 * scoped container so the styles affect only this block instance.
	 *
	 * @param  array $attributes  Block attributes as saved by the editor.
	 * @return string             Rendered HTML.
	 */
	public static function render( array $attributes ): string {
		$form_id   = (int) ( $attributes['formId'] ?? 0 );
		// Detect ServerSideRender editor preview. The block editor's
		// REST call hits /wp-json/wp/v2/block-renderer/<block> from
		// the admin page, but the PHP handling of that REST endpoint
		// does NOT define WP_ADMIN — so `is_admin()` returns FALSE
		// here even though the browser is on an admin URL. The
		// reliable signal is REST_REQUEST alone. (An over-narrow
		// `REST_REQUEST && is_admin()` guard was tried and broke the
		// editor preview entirely: the hybrid CSS routing below would
		// have taken the wp_add_inline_style path, which never
		// reaches the REST response body.)
		$is_editor = defined( 'REST_REQUEST' ) && REST_REQUEST;

		if ( $form_id < 1 ) {
			if ( $is_editor ) {
				// Return nothing in editor - the JS empty state handles this.
				return '';
			}
			return static::admin_notice(
				__( 'Neural Forms: no form selected. Edit the block and choose a form.', 'neural-forms-lite' )
			);
		}

		// Build render options from block attributes that affect HTML output
		// (not just CSS). show_labels / show_placeholders override the per-
		// field schema settings so the block author controls label/placeholder
		// behaviour for every field in one place.
		$render_options = [
			'form_title'         => (string) ( $attributes['formTitle'] ?? '' ),
			'title_level'        => (string) ( $attributes['titleLevel'] ?? 'h2' ),
			'show_labels'        => ! empty( $attributes['showLabels'] ),
			'show_placeholders'  => ! isset( $attributes['showPlaceholders'] )
				|| $attributes['showPlaceholders'] !== false,
			'show_quiz_results'  => ! isset( $attributes['showQuizResults'] )
				|| $attributes['showQuizResults'] !== false,
			'show_poll_results'  => ! isset( $attributes['showPollResults'] )
				|| $attributes['showPollResults'] !== false,
		];

		// Button-text overrides (1.1.0). Block attributes always carry a
		// default ("Submit" / "Previous" / "Next"), so the option key is
		// always set when rendering through the block path. We forward
		// the value as-is — including an EXPLICIT empty string, which
		// tells the renderer to emit a button with no text inside (so
		// admins who add an icon via theme CSS get an icon-only button).
		//
		// We only forward the key when it exists on $attributes so the
		// shortcode / standalone FormRenderer::render() path is
		// unaffected (its option key stays absent → renderer falls
		// back to its hardcoded defaults).
		if ( array_key_exists( 'submitButtonText', $attributes ) ) {
			$render_options['submit_button_text'] = (string) $attributes['submitButtonText'];
		}
		if ( array_key_exists( 'prevButtonText', $attributes ) ) {
			$render_options['prev_button_text'] = (string) $attributes['prevButtonText'];
		}
		if ( array_key_exists( 'nextButtonText', $attributes ) ) {
			$render_options['next_button_text'] = (string) $attributes['nextButtonText'];
		}
		if ( array_key_exists( 'repeaterAddBtnText', $attributes ) ) {
			$render_options['repeater_add_btn_text'] = (string) $attributes['repeaterAddBtnText'];
		}

		// Button icons — forwarded to FormRenderer so it can inject SVG into
		// the button HTML. The icon id is validated against ButtonIcons::ids()
		// inside FormRenderer; BlockHandler just forwards the raw values.
		if ( ! empty( $attributes['btnIconEnabled'] ) && ! empty( $attributes['btnIcon'] ) ) {
			$render_options['btn_icon']          = (string) $attributes['btnIcon'];
			$render_options['btn_icon_position'] = in_array(
				(string) ( $attributes['btnIconPosition'] ?? 'after' ),
				[ 'before', 'after' ], true
			) ? (string) $attributes['btnIconPosition'] : 'after';
			$render_options['btn_icon_hover'] = ! empty( $attributes['btnIconShowOnHover'] );
		}

		if ( ! empty( $attributes['navBtnPrevIconEnabled'] ) && ! empty( $attributes['navBtnPrevIcon'] ) ) {
			$render_options['nav_btn_prev_icon']          = (string) $attributes['navBtnPrevIcon'];
			$render_options['nav_btn_prev_icon_position'] = in_array(
				(string) ( $attributes['navBtnPrevIconPosition'] ?? 'before' ),
				[ 'before', 'after' ], true
			) ? (string) $attributes['navBtnPrevIconPosition'] : 'before';
			$render_options['nav_btn_prev_icon_hover'] = ! empty( $attributes['navBtnPrevIconShowOnHover'] );
		}

		if ( ! empty( $attributes['navBtnNextIconEnabled'] ) && ! empty( $attributes['navBtnNextIcon'] ) ) {
			$render_options['nav_btn_next_icon']          = (string) $attributes['navBtnNextIcon'];
			$render_options['nav_btn_next_icon_position'] = in_array(
				(string) ( $attributes['navBtnNextIconPosition'] ?? 'after' ),
				[ 'before', 'after' ], true
			) ? (string) $attributes['navBtnNextIconPosition'] : 'after';
			$render_options['nav_btn_next_icon_hover'] = ! empty( $attributes['navBtnNextIconShowOnHover'] );
		}

		// Multi-Step block (and the form block, harmlessly): per-placement
		// progress-indicator override. Empty string = "Use form setting"
		// → no override. Any other value is validated by FormRenderer.
		$progress_override = (string) ( $attributes['progressIndicatorOverride'] ?? '' );
		if ( $progress_override !== '' ) {
			$render_options['progress_indicator_override'] = $progress_override;
		}

		// In editor context, render the form without enqueuing the submission
		// handler JS - the preview should be visual only, not interactive.
		$form_html = $is_editor
			? FormRenderer::render_preview( $form_id, $render_options )
			: FormRenderer::render( $form_id, $render_options );

		if ( $form_html === '' ) {
			return $form_html;
		}

		// Frontend fast path: no formatting attributes - return the raw form
		// HTML unchanged so there is zero overhead for unstyled blocks.
		// In the editor we always wrap (need pointer-events:none at minimum).
		if ( ! $is_editor && ! static::has_style_attrs( $attributes ) ) {
			return $form_html;
		}

		// A short deterministic uid scopes the injected CSS to this block instance,
		// preventing one block's overrides from leaking into a sibling block on the
		// same page (e.g. two different forms with different button colours).
		$uid        = substr( md5( (string) wp_json_encode( $attributes ) ), 0, 8 );
		$scoped_css = static::build_scoped_css( $uid, $attributes );
		$wrap_style = static::build_wrap_style( $attributes );
		$wrap_class = 'neural-forms-block-wrap neural-forms-block-' . esc_attr( $uid );
		$extra_class = trim( (string) ( $attributes['extraClass'] ?? '' ) );
		if ( $extra_class !== '' ) {
			$wrap_class .= ' ' . esc_attr( $extra_class );
		}

		$style_attr = $wrap_style ? ' style="' . esc_attr( $wrap_style ) . '"' : '';

		// In editor context, disable pointer events so the user can click
		// through to select/move the block instead of interacting with inputs.
		$editor_css = '';
		if ( $is_editor ) {
			$editor_css = '.neural-forms-block-' . esc_attr( $uid )
				. ' .neural-forms-form{pointer-events:none;}';
		}

		// Per-instance scoped CSS — inline <style> tag, both surfaces.
		//
		// Previously the frontend path used wp_add_inline_style() to
		// attach the scoped CSS to the 'neural-forms-form' handle and
		// relied on WP's late-print pipeline to flush it alongside the
		// stylesheet. That works in the happy path, but breaks the
		// moment a theme / plugin / template forces an early style
		// print, swaps the enqueue queue, or strips the handle before
		// the inline content is flushed — and when it breaks, the
		// inline CSS is silently dropped and the form falls back to
		// the form.css defaults regardless of what the author
		// configured (reported after a theme switch on 0.2.0).
		//
		// Emit an inline <style> tag in the rendered block content
		// instead. It's scoped via `.neural-forms-block-<uid>` so it
		// cannot leak into sibling blocks, and it's the pattern every
		// established dynamic-block plugin uses for the same reason
		// (Cover / Gallery / Kadence / GenerateBlocks / Stackable).
		// The static dedupe guard below prevents re-emitting the same
		// rule block when the same form is embedded more than once on
		// a single page (two embeds of the same form with identical
		// attributes share one uid and one <style> tag).
		$inline_css = $scoped_css . $editor_css;

		$wrap_html = '<div class="' . $wrap_class . '"' . $style_attr . '>'
			. $form_html
			. '</div>';

		if ( $inline_css === '' ) {
			return $wrap_html;
		}

		static $emitted_inline_css = [];
		$css_key = md5( $inline_css );
		if ( isset( $emitted_inline_css[ $css_key ] ) ) {
			return $wrap_html;
		}
		$emitted_inline_css[ $css_key ] = true;

		return '<style>' . $inline_css . '</style>' . $wrap_html;
	}

	// Style helpers

	/**
	 * Returns true when at least one formatting attribute has a non-default value,
	 * meaning a scoped wrapper needs to be injected.
	 */
	private static function has_style_attrs( array $attributes ): bool {
		$style_keys = [
			'formAlign', 'formWidth', 'maxWidth',
			'minHeight', 'height', 'maxHeight',
			'marginTop', 'marginRight', 'marginBottom', 'marginLeft',
			'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft',
			'bgColor', 'bgGradient', 'bgImage', 'bgImageSize', 'bgImagePosition', 'bgImageRepeat', 'bgImageBlend',
			'borderColor', 'borderWidth', 'borderStyle', 'borderRadius',
			'borderRadiusTL', 'borderRadiusTR', 'borderRadiusBR', 'borderRadiusBL',
			'btnTextColor', 'btnBgColor', 'btnBgGradient',
			'btnAlign', 'btnRadius',
			'btnRadiusTL', 'btnRadiusTR', 'btnRadiusBR', 'btnRadiusBL',
			'btnBorderColor', 'btnBorderWidth', 'btnBorderStyle',
			'btnFontSize', 'btnFontFamily',
			'btnFontWeight', 'btnFontStyle', 'btnLetterSpacing', 'btnTextDecoration', 'btnTextTransform', 'btnLineHeight',
			'btnPaddingTop', 'btnPaddingRight', 'btnPaddingBottom', 'btnPaddingLeft',
			'btnHoverBg', 'btnHoverBgGradient', 'btnHoverColor', 'btnHoverBorderColor',
			'btnTransitionDuration', 'btnTransitionEasing',
			'shadowH', 'shadowV', 'shadowBlur', 'shadowSpread', 'shadowColor', 'shadowPosition',
			'fieldBg', 'fieldBgGradient', 'fieldFocusBg', 'fieldFocusBgGradient',
			'fieldHoverBg', 'fieldHoverBgGradient', 'fieldHoverBorderColor',
			'fieldBorderColor', 'fieldBorderWidth', 'fieldBorderStyle', 'fieldBorderRadius',
			'fieldBorderRadiusTL', 'fieldBorderRadiusTR', 'fieldBorderRadiusBR', 'fieldBorderRadiusBL',
			'fieldTextColor', 'fieldFocusTextColor', 'fieldPlaceholderColor',
			'fieldFocusBorderColor',
			'fieldFontSize', 'fieldFontFamily', 'fieldFontWeight', 'fieldFontStyle', 'fieldLetterSpacing',
			'fieldTextDecoration', 'fieldTextTransform', 'fieldLineHeight',
			'fieldTextAlign',
			'fieldPaddingTop', 'fieldPaddingRight', 'fieldPaddingBottom', 'fieldPaddingLeft',
			'fieldGap',
			'fieldErrorMsgColor', 'fieldErrorMsgFontSize',
			'successMsgBg', 'successMsgBgGradient', 'successMsgColor',
			'successMsgPaddingTop', 'successMsgPaddingRight', 'successMsgPaddingBottom', 'successMsgPaddingLeft',
			'errorMsgBg', 'errorMsgBgGradient', 'errorMsgColor',
			'errorMsgPaddingTop', 'errorMsgPaddingRight', 'errorMsgPaddingBottom', 'errorMsgPaddingLeft',
			// Radio field
			'radioOptionsDirection', 'radioOptionsGap',
			'radioOptionSize',
			'radioOptionBg', 'radioOptionHoverBg', 'radioOptionCheckedBg',
			'radioOptionBorderColor', 'radioOptionBorderWidth', 'radioOptionBorderStyle', 'radioOptionBorderRadius',
			'radioOptionPaddingTop', 'radioOptionPaddingRight', 'radioOptionPaddingBottom', 'radioOptionPaddingLeft',
			'radioAccentColor', 'radioLabelGap',
			'radioOptionColor', 'radioOptionFontSize', 'radioOptionFontWeight',
			// Checkbox group field
			'cbGroupDirection',
			'cbGroupSize', 'cbGroupBg', 'cbGroupBgGradient', 'cbGroupCheckedBg',
			'cbGroupBorderColor', 'cbGroupBorderWidth', 'cbGroupBorderRadius',
			'cbGroupLabelColor', 'cbGroupLabelFontSize', 'cbGroupLabelFontWeight',
			'cbGroupGap', 'cbGroupItemGap',
			// Rating field
			'ratingStarSize', 'ratingStarGap', 'ratingEmptyColor', 'ratingFilledColor', 'ratingHoverColor',
			// File input field
			'fileInputBg', 'fileInputBgGradient', 'fileInputBorderColor', 'fileInputBorderWidth', 'fileInputBorderStyle', 'fileInputBorderRadius',
			'fileInputColor', 'fileInputFontSize',
			'fileInputPaddingTop', 'fileInputPaddingRight', 'fileInputPaddingBottom', 'fileInputPaddingLeft',
			// File browse button
			'fileBtnBg', 'fileBtnBgGradient', 'fileBtnHoverBg', 'fileBtnHoverBgGradient', 'fileBtnColor', 'fileBtnFontSize', 'fileBtnFontWeight',
			'fileBtnPaddingTop', 'fileBtnPaddingRight', 'fileBtnPaddingBottom', 'fileBtnPaddingLeft',
			'fileBtnBorderColor', 'fileBtnBorderWidth', 'fileBtnBorderStyle', 'fileBtnBorderRadius',
			'titleAlign', 'titleColor', 'titleFontFamily',
			'titleFontSize', 'titleFontWeight', 'titleFontStyle', 'titleLetterSpacing',
			'titleTextDecoration', 'titleTextTransform', 'titleLineHeight',
			'titlePaddingTop', 'titlePaddingRight', 'titlePaddingBottom', 'titlePaddingLeft',
			'titleMarginTop', 'titleMarginRight', 'titleMarginBottom', 'titleMarginLeft',
			'formTitle',
			'labelAlign', 'labelColor', 'labelFontFamily',
			'labelFontSize', 'labelFontWeight', 'labelFontStyle', 'labelLetterSpacing',
			'labelTextDecoration', 'labelTextTransform', 'labelLineHeight',
			'labelPaddingTop', 'labelPaddingRight', 'labelPaddingBottom', 'labelPaddingLeft',
			'labelMarginTop', 'labelMarginRight', 'labelMarginBottom', 'labelMarginLeft',
			// Textarea field
			'textareaBg', 'textareaBgGradient', 'textareaHoverBg', 'textareaHoverBgGradient', 'textareaFocusBg', 'textareaFocusBgGradient',
			'textareaTextColor', 'textareaFocusTextColor', 'textareaPlaceholderColor',
			'textareaBorderColor', 'textareaBorderWidth', 'textareaBorderStyle',
			'textareaHoverBorderColor', 'textareaFocusBorderColor',
			'textareaBorderRadiusTL', 'textareaBorderRadiusTR', 'textareaBorderRadiusBR', 'textareaBorderRadiusBL',
			'textareaFontSize', 'textareaFontWeight', 'textareaFontStyle', 'textareaFontFamily',
			'textareaLetterSpacing', 'textareaLineHeight', 'textareaTextTransform', 'textareaTextDecoration', 'textareaTextAlign',
			'textareaPaddingTop', 'textareaPaddingRight', 'textareaPaddingBottom', 'textareaPaddingLeft',
			'textareaMinHeight',
			// Acceptance field
			'acceptanceBg', 'acceptanceCheckedBg', 'acceptanceBorderColor', 'acceptanceBorderWidth',
			'acceptanceBorderRadius', 'acceptanceSize',
			'acceptanceLabelColor', 'acceptanceLabelFontSize', 'acceptanceLabelFontWeight',
			'acceptanceGap',
			// Poll-specific
			'pollOptionsGap',
			'pollOptionPaddingTop', 'pollOptionPaddingRight', 'pollOptionPaddingBottom', 'pollOptionPaddingLeft',
			'pollOptionBg', 'pollOptionBgGradient', 'pollOptionHoverBg', 'pollOptionHoverBgGradient', 'pollOptionCheckedBg', 'pollOptionCheckedBgGradient',
			'pollOptionBorderColor', 'pollOptionBorderWidth', 'pollOptionBorderStyle', 'pollOptionBorderRadius',
			'pollAccentColor', 'pollOptionColor', 'pollOptionFontSize', 'pollOptionFontWeight',
			'pollBarColor', 'pollBarColor2', 'pollBarTrackColor',
			'pollBarHeight', 'pollBarRadius',
			'pollResultsLabelColor', 'pollResultsCountColor', 'pollResultsFontSize',
			'pollTotalColor', 'pollTotalFontSize', 'pollResultsMarginTop',
			// Quiz-specific
			'quizOptionsGap',
			'quizOptionPaddingTop', 'quizOptionPaddingRight', 'quizOptionPaddingBottom', 'quizOptionPaddingLeft',
			'quizOptionBg', 'quizOptionBgGradient', 'quizOptionHoverBg', 'quizOptionHoverBgGradient', 'quizOptionCheckedBg', 'quizOptionCheckedBgGradient',
			'quizOptionBorderColor', 'quizOptionBorderWidth', 'quizOptionBorderStyle', 'quizOptionBorderRadius',
			'quizAccentColor', 'quizOptionColor', 'quizOptionFontSize', 'quizOptionFontWeight',
			'quizSummaryBg', 'quizSummaryGradient', 'quizSummaryBgImage',
			'quizSummaryBgSize', 'quizSummaryBgPosition', 'quizSummaryBgRepeat', 'quizSummaryBgBlend',
			'quizSummaryColor', 'quizSummaryBorderColor', 'quizSummaryBorderWidth',
			'quizSummaryBorderRadiusTL', 'quizSummaryBorderRadiusTR', 'quizSummaryBorderRadiusBR', 'quizSummaryBorderRadiusBL',
			'quizSummaryPaddingTop', 'quizSummaryPaddingRight', 'quizSummaryPaddingBottom', 'quizSummaryPaddingLeft',
			'quizScoreFontSize',
			// Multi-step: prev & next buttons (shared)
			'navBtnBgColor', 'navBtnBgGradient', 'navBtnTextColor',
			'navBtnHoverBg', 'navBtnHoverBgGradient', 'navBtnHoverColor', 'navBtnHoverBorderColor',
			'navBtnBorderColor', 'navBtnBorderWidth', 'navBtnBorderStyle',
			'navBtnRadiusTL', 'navBtnRadiusTR', 'navBtnRadiusBR', 'navBtnRadiusBL',
			'navBtnFontSize', 'navBtnFontFamily',
			'navBtnFontWeight', 'navBtnFontStyle', 'navBtnLetterSpacing', 'navBtnTextDecoration', 'navBtnTextTransform', 'navBtnLineHeight',
			'navBtnPaddingTop', 'navBtnPaddingRight', 'navBtnPaddingBottom', 'navBtnPaddingLeft',
			'navBtnTransitionDuration', 'navBtnTransitionEasing',
			// Button icons — submit
			'btnIconEnabled', 'btnIcon', 'btnIconPosition', 'btnIconShowOnHover',
			// Button icons — nav (prev / next, MultiStep only)
			'navBtnPrevIconEnabled', 'navBtnPrevIcon', 'navBtnPrevIconPosition', 'navBtnPrevIconShowOnHover',
			'navBtnNextIconEnabled', 'navBtnNextIcon', 'navBtnNextIconPosition', 'navBtnNextIconShowOnHover',
			// Multi-step: step indicator
			'stepTitleColor', 'stepTitleFontSize', 'stepTitleFontWeight',
			'stepDescColor', 'stepDescFontSize', 'stepDescFontWeight',
			'stepSepColor', 'stepSepWidth', 'stepSepStyle',
			'stepBarFillColor', 'stepBarTrackColor', 'stepBarHeight', 'stepBarRadius',
			'stepBarTextColor', 'stepBarTextFontSize', 'stepBarTextFontWeight',
			'stepActiveNumBg', 'stepActiveNumColor',
			'stepCompleteNumBg', 'stepCompleteNumColor',
			'stepInactiveNumBg', 'stepInactiveNumColor',
			'stepNumSize', 'stepNumFontSize', 'stepConnectorColor',
			'stepLabelColor', 'stepActiveLabelColor', 'stepCompleteLabelColor',
			'stepLabelFontSize', 'stepLabelFontWeight',
			// Repeater field
			'repeaterContainerBg', 'repeaterContainerBgGradient', 'repeaterContainerBorderColor', 'repeaterContainerBorderWidth', 'repeaterContainerBorderStyle',
			'repeaterContainerBorderRadius', 'repeaterContainerPaddingTop', 'repeaterContainerPaddingRight', 'repeaterContainerPaddingBottom', 'repeaterContainerPaddingLeft',
			'repeaterListAddGap',
			'repeaterRowBg', 'repeaterRowBgGradient', 'repeaterRowHoverBg', 'repeaterRowHoverBgGradient', 'repeaterRowBorderColor', 'repeaterRowBorderWidth', 'repeaterRowBorderStyle',
			'repeaterRowBorderRadius', 'repeaterRowPaddingTop', 'repeaterRowPaddingRight', 'repeaterRowPaddingBottom', 'repeaterRowPaddingLeft',
			'repeaterRowGap',
			'repeaterAddBtnBg', 'repeaterAddBtnBgGradient', 'repeaterAddBtnHoverBg', 'repeaterAddBtnHoverBgGradient', 'repeaterAddBtnColor', 'repeaterAddBtnHoverColor',
			'repeaterAddBtnFontSize', 'repeaterAddBtnFontWeight',
			'repeaterAddBtnBorderColor', 'repeaterAddBtnBorderWidth', 'repeaterAddBtnBorderStyle', 'repeaterAddBtnBorderRadius',
			'repeaterAddBtnPaddingTop', 'repeaterAddBtnPaddingRight', 'repeaterAddBtnPaddingBottom', 'repeaterAddBtnPaddingLeft',
			'repeaterRemoveBtnColor', 'repeaterRemoveBtnHoverColor', 'repeaterRemoveBtnSize',
			'repeaterItemNumColor', 'repeaterItemNumFontSize',
			// Matrix field
			'matrixBorderColor', 'matrixBorderWidth',
			'matrixColHeaderBg', 'matrixColHeaderBgGradient', 'matrixColHeaderColor', 'matrixColHeaderFontSize', 'matrixColHeaderFontWeight',
			'matrixColHeaderPaddingTop', 'matrixColHeaderPaddingRight', 'matrixColHeaderPaddingBottom', 'matrixColHeaderPaddingLeft',
			'matrixRowHeaderBg', 'matrixRowHeaderBgGradient', 'matrixRowHeaderColor', 'matrixRowHeaderFontSize', 'matrixRowHeaderFontWeight',
			'matrixCellBg', 'matrixCellBgGradient', 'matrixCellHoverBg', 'matrixCellHoverBgGradient', 'matrixCellCheckedBg', 'matrixCellCheckedBgGradient', 'matrixCellBorderColor', 'matrixCellBorderWidth',
			'matrixCellPaddingTop', 'matrixCellPaddingRight', 'matrixCellPaddingBottom', 'matrixCellPaddingLeft',
			'matrixAccentColor', 'matrixRowBg', 'matrixRowBgGradient', 'matrixAltRowBg', 'matrixAltRowBgGradient',
		];

		foreach ( $style_keys as $key ) {
			if ( isset( $attributes[ $key ] ) && $attributes[ $key ] !== '' ) {
				return true;
			}
		}

		// Boolean flags
		if (
			! empty( $attributes['btnFullWidth'] ) ||
			! empty( $attributes['navBtnFullWidth'] ) ||
			( isset( $attributes['successMsgEnabled'] ) && $attributes['successMsgEnabled'] === false ) ||
			( isset( $attributes['errorMsgEnabled'] ) && $attributes['errorMsgEnabled'] === false ) ||
			! empty( $attributes['showLabels'] ) ||
			( isset( $attributes['showPlaceholders'] ) && $attributes['showPlaceholders'] === false )
		) {
			return true;
		}

		// Direction toggles (vertical/horizontal) trigger wrapper even when no
		// other style attrs are set — we only need to wrap when explicitly 'horizontal'.
		if ( isset( $attributes['radioOptionsDirection'] ) && $attributes['radioOptionsDirection'] === 'horizontal' ) {
			return true;
		}

		if ( isset( $attributes['cbGroupDirection'] ) && $attributes['cbGroupDirection'] === 'horizontal' ) {
			return true;
		}

		if ( isset( $attributes['pollOptionsDirection'] ) && $attributes['pollOptionsDirection'] === 'horizontal' ) {
			return true;
		}

		// Same for quiz option direction.
		if ( isset( $attributes['quizOptionsDirection'] ) && $attributes['quizOptionsDirection'] === 'horizontal' ) {
			return true;
		}

		// Extra CSS class(es) - wrapper must render so the class lands on the DOM.
		if ( ! empty( $attributes['extraClass'] ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Build the inline style string for the outer wrapper div.
	 *
	 * Layout concerns (max-width, margins, alignment) live here because they
	 * affect how the block sits in the page flow, not how the form looks
	 * internally. Inline styles on the wrapper let the block editor preview
	 * them correctly via useBlockProps({ style: wrapStyle }) in index.js.
	 */
	private static function build_wrap_style( array $attributes ): string {
		$parts = [];

		$form_width = (string) ( $attributes['formWidth'] ?? '' );
		if ( $form_width !== '' ) {
			$parts[] = 'width:' . sanitize_text_field( $form_width );
		}

		$max_width = (string) ( $attributes['maxWidth'] ?? '' );
		if ( $max_width !== '' ) {
			$parts[] = 'max-width:' . sanitize_text_field( $max_width );
		}

		$min_height = (string) ( $attributes['minHeight'] ?? '' );
		if ( $min_height !== '' ) {
			$parts[] = 'min-height:' . sanitize_text_field( $min_height );
		}

		$height = (string) ( $attributes['height'] ?? '' );
		if ( $height !== '' ) {
			$parts[] = 'height:' . sanitize_text_field( $height );
		}

		$max_height = (string) ( $attributes['maxHeight'] ?? '' );
		if ( $max_height !== '' ) {
			$parts[] = 'max-height:' . sanitize_text_field( $max_height );
		}

		// Stretch overrides width inline (safe; not affected by WP layout constraints).
		// Center/right margins are handled in build_scoped_css() with !important to
		// override WordPress constrained-layout CSS that forces margin:auto on children.
		$form_align = (string) ( $attributes['formAlign'] ?? '' );
		if ( $form_align === 'stretch' ) {
			$parts[] = 'width:100%';
		}

		// Explicit user margins. Handles SpacingSizesControl preset vars and bare numbers.
		$normalize = static function ( string $v ): string {
			$v = sanitize_text_field( $v );
			if ( $v === '' ) return '';
			if ( strpos( $v, 'var:preset|spacing|' ) === 0 ) {
				$slug = str_replace( 'var:preset|spacing|', '', $v );
				return 'var(--wp--preset--spacing--' . sanitize_html_class( $slug ) . ')';
			}
			return is_numeric( $v ) ? $v . 'px' : $v;
		};

		$margin_top = $normalize( (string) ( $attributes['marginTop'] ?? '' ) );
		if ( $margin_top !== '' ) {
			$parts[] = 'margin-top:' . $margin_top;
		}

		$margin_right = $normalize( (string) ( $attributes['marginRight'] ?? '' ) );
		if ( $margin_right !== '' ) {
			$parts[] = 'margin-right:' . $margin_right;
		}

		$margin_bottom = $normalize( (string) ( $attributes['marginBottom'] ?? '' ) );
		if ( $margin_bottom !== '' ) {
			$parts[] = 'margin-bottom:' . $margin_bottom;
		}

		$margin_left = $normalize( (string) ( $attributes['marginLeft'] ?? '' ) );
		if ( $margin_left !== '' ) {
			$parts[] = 'margin-left:' . $margin_left;
		}

		return implode( ';', $parts );
	}

	/**
	 * Build the scoped CSS block that overrides Neural Forms's CSS custom properties
	 * and container styles for this block instance only.
	 *
	 * Two selectors are used:
	 *   .neural-forms-block-{uid}            '->-> container box (bg, border, shadow, padding)
	 *   .neural-forms-block-{uid} .neural-forms-form '->-> CSS custom properties that cascade
	 *                                        down to all child elements
	 *
	 * CSS values come from block attributes - all values are sanitized before
	 * being written into the style tag (text_field for lengths, hex/rgb for colors).
	 *
	 * @param string $uid         8-char hex uid for this attribute combination.
	 * @param array  $attributes  Block attributes.
	 * @return string             Raw CSS string (safe to echo inside <style>).
	 */
	private static function build_scoped_css( string $uid, array $attributes ): string {
		// Compound the two classes that BlockHandler::render() always
		// puts on the same wrapper div (`neural-forms-block-wrap` +
		// `neural-forms-block-<uid>`). The selector still targets a
		// single element — the wrap — but its specificity is (0,2,0)
		// instead of (0,1,0). Every derived selector below inherits
		// that bump, so the actual rules land at (0,3,0+) and survive
		// theme stylesheets that ship aggressive `.entry-content
		// input.something` style selectors at (0,2,1). Without this,
		// switching themes can wipe out the block's painted bg / text
		// / border colors on the frontend (the editor canvas got away
		// with (0,2,0) only because Gutenberg's `.editor-styles-
		// wrapper` already added a class). Keeping both classes on
		// the wrap div in render() is therefore load-bearing — DO NOT
		// drop either class.
		$wrap_sel = '.neural-forms-block-' . $uid . '.neural-forms-block-wrap';
		$form_sel = $wrap_sel . ' .neural-forms-form';

		// Normalize a length value: if a bare number is supplied (e.g. FontSizePicker
		// returning 20 instead of '20px'), append 'px'. Sanitize otherwise.
		$len = static function ( $v ): string {
			$v = sanitize_text_field( (string) $v );
			if ( $v === '' ) {
				return '';
			}
			if ( is_numeric( $v ) ) {
				$v .= 'px';
			}
			return $v;
		};

		// Like $len but also resolves WP spacing presets (SpacingSizesControl stores
		// values as 'var:preset|spacing|50' which must become 'var(--wp--preset--spacing--50)').
		$spacing = static function ( $v ) use ( $len ): string {
			$v = sanitize_text_field( (string) $v );
			if ( $v === '' ) return '';
			if ( strpos( $v, 'var:preset|spacing|' ) === 0 ) {
				$slug = str_replace( 'var:preset|spacing|', '', $v );
				return 'var(--wp--preset--spacing--' . sanitize_html_class( $slug ) . ')';
			}
			return $len( $v );
		};

		$wrap_rules          = [];
		$var_rules           = [];
		$form_wrap_pad_rules = [];

		// Alignment margins use !important to override WordPress's constrained-layout
		// CSS (.is-layout-constrained > :where(...) { margin-left:auto!important; ... })
		// which would otherwise neutralise the right-aligned / centered inline styles.
		$form_align_css        = (string) ( $attributes['formAlign'] ?? '' );
		$form_width_css        = (string) ( $attributes['formWidth'] ?? '' );
		$max_width_css         = (string) ( $attributes['maxWidth']  ?? '' );
		$has_constrained_width = $form_width_css !== '' || $max_width_css !== '';
		if ( $form_align_css === 'center' && $has_constrained_width ) {
			$wrap_rules[] = 'margin-left:auto!important';
			$wrap_rules[] = 'margin-right:auto!important';
		} elseif ( $form_align_css === 'right' && $has_constrained_width ) {
			$wrap_rules[] = 'margin-left:auto!important';
			$wrap_rules[] = 'margin-right:0!important';
		} elseif ( $form_align_css === 'left' && $has_constrained_width ) {
			$wrap_rules[] = 'margin-left:0!important';
			$wrap_rules[] = 'margin-right:auto!important';
		}

		// ' Container box '
		// Background (color / image / gradient) goes to .neural-forms-form-wrap so
		// that the padding gap between the container edges and the form content shows
		// the container background color. The outer wrapper stays transparent and
		// only carries border, shadow, and border-radius (with overflow:hidden to
		// clip the form-wrap background to the rounded shape).
		$bg_color = (string) ( $attributes['bgColor'] ?? '' );
		if ( $bg_color !== '' ) {
			$hex = sanitize_hex_color( $bg_color );
			if ( $hex !== null && $hex !== '' ) {
				$form_wrap_pad_rules[] = 'background-color:' . $hex;
			}
		}

		// Priority: image > gradient > color. Higher priority wins exclusively.
		$bg_image    = (string) ( $attributes['bgImage']    ?? '' );
		$bg_gradient = (string) ( $attributes['bgGradient'] ?? '' );
		if ( $bg_image !== '' ) {
			// Image is highest priority — show image only, no gradient overlay.
			$bg_size   = (string) ( $attributes['bgImageSize']     ?? '' );
			$bg_pos    = (string) ( $attributes['bgImagePosition'] ?? '' );
			$bg_repeat = (string) ( $attributes['bgImageRepeat']   ?? '' );
			$bg_blend  = (string) ( $attributes['bgImageBlend']    ?? '' );
			$form_wrap_pad_rules[] = 'background-image:url(' . esc_url( $bg_image ) . ')';
			if ( in_array( $bg_size, [ 'cover', 'contain', 'initial' ], true ) ) {
				$form_wrap_pad_rules[] = 'background-size:' . $bg_size;
			} elseif ( $bg_size === 'stretch' ) {
				$form_wrap_pad_rules[] = 'background-size:100% 100%';
			} else {
				$form_wrap_pad_rules[] = 'background-size:cover';
			}
			if ( in_array( $bg_pos, [ 'top_left', 'top_center', 'top_right', 'center_left', 'center', 'center_right', 'bottom_left', 'bottom_center', 'bottom_right' ], true ) ) {
				$form_wrap_pad_rules[] = 'background-position:' . str_replace( '_', ' ', $bg_pos );
			} else {
				$form_wrap_pad_rules[] = 'background-position:center';
			}
			if ( in_array( $bg_repeat, [ 'repeat', 'repeat-x', 'repeat-y', 'space', 'round', 'no-repeat' ], true ) ) {
				$form_wrap_pad_rules[] = 'background-repeat:' . $bg_repeat;
			} else {
				$form_wrap_pad_rules[] = 'background-repeat:no-repeat';
			}
			if ( in_array( $bg_blend, [ 'normal', 'multiply', 'screen', 'overlay', 'darken', 'lighten' ], true ) ) {
				$form_wrap_pad_rules[] = 'background-blend-mode:' . $bg_blend;
			}
		} elseif ( $bg_gradient !== '' ) {
			// Gradient is second priority — show gradient only.
			$form_wrap_pad_rules[] = 'background-image:' . sanitize_text_field( $bg_gradient );
		} elseif ( $bg_color !== '' ) {
			// Color only — clear any default background-image that may exist.
			$form_wrap_pad_rules[] = 'background-image:none';
		}

		$border_color = (string) ( $attributes['borderColor'] ?? '' );
		$border_width = (string) ( $attributes['borderWidth'] ?? '' );
		$border_style = (string) ( $attributes['borderStyle'] ?? '' );
		if ( $border_color !== '' || $border_width !== '' || $border_style !== '' ) {
			$bw = $border_width !== '' ? sanitize_text_field( $border_width ) : '1px';
			$bc = $border_color !== '' ? sanitize_hex_color( $border_color ) : '#d1d5db';
			$bs = in_array( $border_style, [ 'solid', 'dashed', 'dotted' ], true ) ? $border_style : 'solid';
			$wrap_rules[] = 'border:' . $bw . ' ' . $bs . ' ' . $bc;
		}

		// Per-corner radius wins over the unified radius when any corner is set.
		$radius_tl = (string) ( $attributes['borderRadiusTL'] ?? '' );
		$radius_tr = (string) ( $attributes['borderRadiusTR'] ?? '' );
		$radius_br = (string) ( $attributes['borderRadiusBR'] ?? '' );
		$radius_bl = (string) ( $attributes['borderRadiusBL'] ?? '' );
		$has_corner_radius = ( $radius_tl !== '' || $radius_tr !== '' || $radius_br !== '' || $radius_bl !== '' );

		// Border-radius rationale:
		//
		//   Outer wrap carries the border + border-radius. The
		//   inner .neural-forms-form-wrap (which holds the
		//   background / gradient / padding) is clipped to the
		//   outer's rounded contour by `overflow:hidden`.
		//
		//   `overflow:hidden` clips at the PADDING-box contour,
		//   whose effective radius equals `border-radius - border-width`
		//   (the browser computes this automatically). So when the
		//   admin sets a border AND a border-radius, the inner
		//   background follows the smaller padding-box curve, which
		//   matches the inner edge of the border exactly.
		//
		//   We intentionally do NOT mirror border-radius onto the
		//   inner wrap. Doing so would put a `border-radius`-sized
		//   curve at the inner wrap's OWN corners (which sit at the
		//   padding-box edge), creating a tighter inset shape than
		//   the padding-box clipping and leaving visible triangular
		//   gaps in each corner whenever the outer has a border.
		if ( $has_corner_radius ) {
			if ( $radius_tl !== '' ) $wrap_rules[] = 'border-top-left-radius:'     . $len( $radius_tl );
			if ( $radius_tr !== '' ) $wrap_rules[] = 'border-top-right-radius:'    . $len( $radius_tr );
			if ( $radius_br !== '' ) $wrap_rules[] = 'border-bottom-right-radius:' . $len( $radius_br );
			if ( $radius_bl !== '' ) $wrap_rules[] = 'border-bottom-left-radius:'  . $len( $radius_bl );
			$wrap_rules[] = 'overflow:hidden';
		} else {
			$container_radius = (string) ( $attributes['borderRadius'] ?? '' );
			if ( $container_radius !== '' ) {
				$wrap_rules[] = 'border-radius:' . $len( $container_radius );
				$wrap_rules[] = 'overflow:hidden';
			}
		}

		// Custom box shadow.
		$shadow_h      = (string) ( $attributes['shadowH']        ?? '' );
		$shadow_v      = (string) ( $attributes['shadowV']        ?? '' );
		$shadow_blur   = (string) ( $attributes['shadowBlur']     ?? '' );
		$shadow_spread = (string) ( $attributes['shadowSpread']   ?? '' );
		$shadow_color  = (string) ( $attributes['shadowColor']    ?? '' );
		$shadow_pos    = (string) ( $attributes['shadowPosition'] ?? '' );
		$has_custom_shadow = ( $shadow_h !== '' || $shadow_v !== '' || $shadow_blur !== '' || $shadow_spread !== '' || $shadow_color !== '' );

		if ( $has_custom_shadow ) {
			$h = $shadow_h !== '' ? sanitize_text_field( $shadow_h ) : '0';
			$v = $shadow_v !== '' ? sanitize_text_field( $shadow_v ) : '0';
			$b = $shadow_blur !== '' ? sanitize_text_field( $shadow_blur ) : '0';
			$s = $shadow_spread !== '' ? sanitize_text_field( $shadow_spread ) : '0';
			$c = $shadow_color !== '' ? sanitize_hex_color( $shadow_color ) : 'rgba(0,0,0,0.3)';
			$inset = $shadow_pos === 'inner' ? 'inset ' : '';
			$wrap_rules[] = 'box-shadow:' . $inset . $h . ' ' . $v . ' ' . $b . ' ' . $s . ' ' . $c;
		}

		// Container padding: insets form content from the form-wrap edges (which
		// carry the background, so the gap shows the container colour).
		$padding_top = (string) ( $attributes['paddingTop'] ?? '' );
		if ( $padding_top !== '' ) {
			$form_wrap_pad_rules[] = 'padding-top:' . $spacing( $padding_top );
		}

		$padding_right = (string) ( $attributes['paddingRight'] ?? '' );
		if ( $padding_right !== '' ) {
			$form_wrap_pad_rules[] = 'padding-right:' . $spacing( $padding_right );
		}

		$padding_bottom = (string) ( $attributes['paddingBottom'] ?? '' );
		if ( $padding_bottom !== '' ) {
			$form_wrap_pad_rules[] = 'padding-bottom:' . $spacing( $padding_bottom );
		}

		$padding_left = (string) ( $attributes['paddingLeft'] ?? '' );
		if ( $padding_left !== '' ) {
			$form_wrap_pad_rules[] = 'padding-left:' . $spacing( $padding_left );
		}

		// ' CSS custom properties (cascade to all form children) '
		$btn_text_color = (string) ( $attributes['btnTextColor'] ?? '' );
		if ( $btn_text_color !== '' ) {
			$var_rules[] = '--neural-forms-btn-color:' . sanitize_hex_color( $btn_text_color );
		}

		$btn_bg_color = (string) ( $attributes['btnBgColor'] ?? '' );
		if ( $btn_bg_color !== '' ) {
			$var_rules[] = '--neural-forms-btn-bg:' . sanitize_hex_color( $btn_bg_color );
		}

		$btn_radius = $len( (string) ( $attributes['btnRadius'] ?? '' ) );
		if ( $btn_radius !== '' ) {
			$var_rules[] = '--neural-forms-btn-radius:' . $btn_radius;
		}

		$btn_align = (string) ( $attributes['btnAlign'] ?? '' );
		if ( $btn_align !== '' && in_array( $btn_align, [ 'left', 'center', 'right' ], true ) ) {
			$var_rules[] = '--neural-forms-btn-align:' . $btn_align;
		}

		if ( ! empty( $attributes['btnFullWidth'] ) ) {
			$var_rules[] = '--neural-forms-btn-width:100%';
		}

		// ' Field styling '
		$field_bg          = (string) ( $attributes['fieldBg']         ?? '' );
		$field_bg_gradient = (string) ( $attributes['fieldBgGradient'] ?? '' );
		if ( $field_bg_gradient !== '' ) {
			$var_rules[] = '--neural-forms-field-bg-image:' . sanitize_text_field( $field_bg_gradient );
		} elseif ( $field_bg !== '' ) {
			$var_rules[] = '--neural-forms-field-bg:' . sanitize_hex_color( $field_bg );
		}

		$field_focus_bg          = (string) ( $attributes['fieldFocusBg']         ?? '' );
		$field_focus_bg_gradient = (string) ( $attributes['fieldFocusBgGradient'] ?? '' );

		$field_focus_text = (string) ( $attributes['fieldFocusTextColor'] ?? '' );
		if ( $field_focus_text !== '' ) {
			$var_rules[] = '--neural-forms-field-focus-text:' . sanitize_hex_color( $field_focus_text );
		}

		$field_border_color = (string) ( $attributes['fieldBorderColor'] ?? '' );
		if ( $field_border_color !== '' ) {
			$var_rules[] = '--neural-forms-border:' . sanitize_hex_color( $field_border_color );
		}

		$field_border_width = (string) ( $attributes['fieldBorderWidth'] ?? '' );
		if ( $field_border_width !== '' ) {
			$var_rules[] = '--neural-forms-border-width:' . sanitize_text_field( $field_border_width );
		}

		$field_border_style = (string) ( $attributes['fieldBorderStyle'] ?? '' );
		if ( $field_border_style !== '' && in_array( $field_border_style, [ 'solid', 'dashed', 'dotted' ], true ) ) {
			$var_rules[] = '--neural-forms-border-style:' . $field_border_style;
		}

		$field_border_radius = (string) ( $attributes['fieldBorderRadius'] ?? '' );
		if ( $field_border_radius !== '' ) {
			$var_rules[] = '--neural-forms-radius:' . sanitize_text_field( $field_border_radius );
		}

		$field_text_color = (string) ( $attributes['fieldTextColor'] ?? '' );
		if ( $field_text_color !== '' ) {
			$var_rules[] = '--neural-forms-text:' . sanitize_hex_color( $field_text_color );
		}

		// Stored for later emission in the assemble block (after $css is initialised).
		// Direct ::placeholder rules keep input and textarea colours independent —
		// the shared --neural-forms-placeholder variable would bleed the input
		// colour into the textarea, so we target concrete pseudo-element selectors.
		$field_placeholder_color = sanitize_hex_color( (string) ( $attributes['fieldPlaceholderColor'] ?? '' ) );

		$field_focus_border_color = (string) ( $attributes['fieldFocusBorderColor'] ?? '' );
		if ( $field_focus_border_color !== '' ) {
			$var_rules[] = '--neural-forms-border-focus:' . sanitize_hex_color( $field_focus_border_color );
		}

		// Typography rules - applied directly to the field selectors so they
		// affect all input types (text/email/textarea/select) without needing
		// per-property CSS variables in form.css.
		$field_typo_rules = [];
		// Font-size emitted directly (not via CSS var) so it overrides regardless
		// of FontSizePicker storage form (string, number, or slug).
		$field_font_size_raw = (string) ( $attributes['fieldFontSize'] ?? '' );
		$slug_to_px = [ 'small' => '13px', 'medium' => '16px', 'large' => '20px', 'x-large' => '24px' ];
		if ( isset( $slug_to_px[ $field_font_size_raw ] ) ) {
			$field_font_size_raw = $slug_to_px[ $field_font_size_raw ];
		}
		$field_font_size = $len( $field_font_size_raw );
		if ( $field_font_size !== '' ) {
			$field_typo_rules[] = 'font-size:' . $field_font_size;
		}
		$field_font_family = (string) ( $attributes['fieldFontFamily'] ?? '' );
		if ( $field_font_family !== '' ) {
			$field_typo_rules[] = 'font-family:' . sanitize_text_field( $field_font_family );
		}
		$field_font_weight = (string) ( $attributes['fieldFontWeight'] ?? '' );
		if ( $field_font_weight !== '' ) {
			$field_typo_rules[] = 'font-weight:' . sanitize_text_field( $field_font_weight );
		}
		$field_font_style = (string) ( $attributes['fieldFontStyle'] ?? '' );
		if ( $field_font_style === 'italic' ) {
			$field_typo_rules[] = 'font-style:italic';
		}
		$field_letter_spacing = (string) ( $attributes['fieldLetterSpacing'] ?? '' );
		if ( $field_letter_spacing !== '' ) {
			$ls = $field_letter_spacing;
			if ( is_numeric( $ls ) ) {
				$ls .= 'px';
			}
			$field_typo_rules[] = 'letter-spacing:' . sanitize_text_field( $ls );
		}
		$field_text_decoration = (string) ( $attributes['fieldTextDecoration'] ?? '' );
		if ( $field_text_decoration !== '' && in_array( $field_text_decoration, [ 'none', 'underline', 'overline', 'line-through' ], true ) ) {
			$field_typo_rules[] = 'text-decoration:' . $field_text_decoration;
		}
		$field_text_transform = (string) ( $attributes['fieldTextTransform'] ?? '' );
		if ( $field_text_transform !== '' && in_array( $field_text_transform, [ 'none', 'uppercase', 'lowercase', 'capitalize' ], true ) ) {
			$field_typo_rules[] = 'text-transform:' . $field_text_transform;
		}
		$field_line_height = (string) ( $attributes['fieldLineHeight'] ?? '' );
		if ( $field_line_height !== '' ) {
			$field_typo_rules[] = 'line-height:' . sanitize_text_field( $field_line_height );
		}
		$field_text_align = (string) ( $attributes['fieldTextAlign'] ?? '' );
		if ( $field_text_align !== '' && in_array( $field_text_align, [ 'left', 'center', 'right', 'justify' ], true ) ) {
			$field_typo_rules[] = 'text-align:' . $field_text_align;
		}

		$field_gap = (string) ( $attributes['fieldGap'] ?? '' );
		if ( $field_gap !== '' ) {
			$var_rules[] = '--neural-forms-field-gap:' . sanitize_text_field( $field_gap );
		}

		// ' Submit button: normal + hover state rules '
		// CSS custom properties cover the basic bg/color/radius/align/width
		// case (kept above for back-compat). The rules below cover the
		// extended controls (border, font-size, padding) and the hover state
		// - since custom properties can't conditionally swap on :hover,
		// hover styling has to be a real selector.
		$btn_sel       = $wrap_sel . ' .neural-forms-form__submit';
		$btn_hover_sel = $btn_sel . ':hover:not(:disabled)';
		$btn_rules     = [];
		$btn_hover_rules = [];

		// Emit color + background-color directly on the block-uid-scoped
		// `.neural-forms-block-<uid> .neural-forms-form__submit` selector
		// (specificity 0,2,0) so themes that target buttons with their
		// own class-level rules (e.g. Divi's `.et_pb_button` / WooCommerce's
		// `.button`) don't beat the variable expansion that form.css does
		// at lower specificity. The CSS variables remain set on the form
		// element above so any theme-side variable consumer still works.
		if ( $btn_text_color !== '' ) {
			$btn_rules[] = 'color:' . sanitize_hex_color( $btn_text_color );
		}
		if ( $btn_bg_color !== '' ) {
			$btn_rules[] = 'background-color:' . sanitize_hex_color( $btn_bg_color );
		}

		$btn_border_color = (string) ( $attributes['btnBorderColor'] ?? '' );
		$btn_border_width = (string) ( $attributes['btnBorderWidth'] ?? '' );
		$btn_border_style = (string) ( $attributes['btnBorderStyle'] ?? '' );
		if ( $btn_border_color !== '' || $btn_border_width !== '' || $btn_border_style !== '' ) {
			$bw = $btn_border_width !== '' ? sanitize_text_field( $btn_border_width ) : '1px';
			$bc = $btn_border_color !== '' ? sanitize_hex_color( $btn_border_color ) : 'transparent';
			$bs = in_array( $btn_border_style, [ 'solid', 'dashed', 'dotted' ], true ) ? $btn_border_style : 'solid';
			$btn_rules[] = 'border:' . $bw . ' ' . $bs . ' ' . $bc;
		}

		// Per-corner radius wins over the unified btnRadius when any corner is set.
		$btn_radius_tl = (string) ( $attributes['btnRadiusTL'] ?? '' );
		$btn_radius_tr = (string) ( $attributes['btnRadiusTR'] ?? '' );
		$btn_radius_br = (string) ( $attributes['btnRadiusBR'] ?? '' );
		$btn_radius_bl = (string) ( $attributes['btnRadiusBL'] ?? '' );
		if ( $btn_radius_tl !== '' || $btn_radius_tr !== '' || $btn_radius_br !== '' || $btn_radius_bl !== '' ) {
			if ( $btn_radius_tl !== '' ) $btn_rules[] = 'border-top-left-radius:'     . $len( $btn_radius_tl );
			if ( $btn_radius_tr !== '' ) $btn_rules[] = 'border-top-right-radius:'    . $len( $btn_radius_tr );
			if ( $btn_radius_br !== '' ) $btn_rules[] = 'border-bottom-right-radius:' . $len( $btn_radius_br );
			if ( $btn_radius_bl !== '' ) $btn_rules[] = 'border-bottom-left-radius:'  . $len( $btn_radius_bl );
		}

		$btn_font_family = (string) ( $attributes['btnFontFamily'] ?? '' );
		if ( $btn_font_family !== '' ) {
			$btn_rules[] = 'font-family:' . sanitize_text_field( $btn_font_family );
		}

		$btn_font_size_raw = (string) ( $attributes['btnFontSize'] ?? '' );
		$btn_slug_to_px = [ 'small' => '14px', 'medium' => '16px', 'large' => '18px', 'x-large' => '22px' ];
		if ( isset( $btn_slug_to_px[ $btn_font_size_raw ] ) ) {
			$btn_font_size_raw = $btn_slug_to_px[ $btn_font_size_raw ];
		}
		$btn_font_size = $len( $btn_font_size_raw );
		if ( $btn_font_size !== '' ) {
			$btn_rules[] = 'font-size:' . $btn_font_size;
		}

		// Hover transition. Override the form.css default (0.15s ease) when
		// either duration or easing is set so the choice takes effect.
		$btn_trans_dur = (string) ( $attributes['btnTransitionDuration'] ?? '' );
		$btn_trans_ease = (string) ( $attributes['btnTransitionEasing'] ?? '' );
		if ( $btn_trans_dur !== '' || $btn_trans_ease !== '' ) {
			$dur  = $btn_trans_dur !== '' ? sanitize_text_field( $btn_trans_dur ) : '0.15s';
			$ease = $btn_trans_ease !== '' ? sanitize_text_field( $btn_trans_ease ) : 'ease';
			$btn_rules[] = 'transition:background-color ' . $dur . ' ' . $ease . ',color ' . $dur . ' ' . $ease . ',border-color ' . $dur . ' ' . $ease . ',filter ' . $dur . ' ' . $ease;
		}

		// Full-width button - apply width:100% on the button itself, not via the
		// CSS variable, so it works even when --neural-forms-btn-width is unset.
		if ( ! empty( $attributes['btnFullWidth'] ) ) {
			$btn_rules[] = 'width:100%';
		}

		$btn_font_weight = (string) ( $attributes['btnFontWeight'] ?? '' );
		if ( $btn_font_weight !== '' ) {
			$btn_rules[] = 'font-weight:' . sanitize_text_field( $btn_font_weight );
		}
		$btn_font_style = (string) ( $attributes['btnFontStyle'] ?? '' );
		if ( $btn_font_style === 'italic' ) {
			$btn_rules[] = 'font-style:italic';
		}
		$btn_letter_spacing = (string) ( $attributes['btnLetterSpacing'] ?? '' );
		if ( $btn_letter_spacing !== '' ) {
			$ls = $btn_letter_spacing;
			if ( is_numeric( $ls ) ) {
				$ls .= 'px';
			}
			$btn_rules[] = 'letter-spacing:' . sanitize_text_field( $ls );
		}
		$btn_text_decoration = (string) ( $attributes['btnTextDecoration'] ?? '' );
		if ( $btn_text_decoration !== '' && in_array( $btn_text_decoration, [ 'none', 'underline', 'overline', 'line-through' ], true ) ) {
			$btn_rules[] = 'text-decoration:' . $btn_text_decoration;
		}
		$btn_text_transform = (string) ( $attributes['btnTextTransform'] ?? '' );
		if ( $btn_text_transform !== '' && in_array( $btn_text_transform, [ 'none', 'uppercase', 'lowercase', 'capitalize' ], true ) ) {
			$btn_rules[] = 'text-transform:' . $btn_text_transform;
		}
		$btn_line_height = (string) ( $attributes['btnLineHeight'] ?? '' );
		if ( $btn_line_height !== '' ) {
			$btn_rules[] = 'line-height:' . sanitize_text_field( $btn_line_height );
		}

		foreach ( [ 'Top' => 'padding-top', 'Right' => 'padding-right', 'Bottom' => 'padding-bottom', 'Left' => 'padding-left' ] as $key => $css_prop ) {
			$val = (string) ( $attributes[ 'btnPadding' . $key ] ?? '' );
			if ( $val !== '' ) {
				$btn_rules[] = $css_prop . ':' . $spacing( $val );
			}
		}

		$btn_hover_bg     = (string) ( $attributes['btnHoverBg']          ?? '' );
		$btn_hover_color  = (string) ( $attributes['btnHoverColor']       ?? '' );
		$btn_hover_border = (string) ( $attributes['btnHoverBorderColor'] ?? '' );
		if ( $btn_hover_bg !== '' ) {
			$btn_hover_rules[] = 'background-color:' . sanitize_hex_color( $btn_hover_bg );
		}
		if ( $btn_hover_color !== '' ) {
			$btn_hover_rules[] = 'color:' . sanitize_hex_color( $btn_hover_color );
		}
		if ( $btn_hover_border !== '' ) {
			$btn_hover_rules[] = 'border-color:' . sanitize_hex_color( $btn_hover_border );
		}
		// When a hover bg/color is set, override the default brightness filter
		// so the user's chosen colours render exactly as picked.
		if ( $btn_hover_rules ) {
			$btn_hover_rules[] = 'filter:none';
		}

		// ' Assemble '
		$css = '';

		if ( $wrap_rules ) {
			$css .= $wrap_sel . '{' . implode( ';', $wrap_rules ) . '}';
		}

		if ( $form_wrap_pad_rules ) {
			$css .= $wrap_sel . ' .neural-forms-form-wrap{' . implode( ';', $form_wrap_pad_rules ) . '}';
		}

		if ( $var_rules ) {
			$css .= $form_sel . '{' . implode( ';', $var_rules ) . '}';
		}

		if ( $field_placeholder_color !== null && $field_placeholder_color !== '' ) {
			$ph_sel = $wrap_sel . ' .neural-forms-form__input::placeholder,'
				. $wrap_sel . ' .neural-forms-form__select::placeholder';
			$css .= $ph_sel . '{color:' . $field_placeholder_color . '}';
		}

		if ( $btn_rules ) {
			$css .= $btn_sel . '{' . implode( ';', $btn_rules ) . '}';
		}

		if ( $btn_hover_rules ) {
			$css .= $btn_hover_sel . '{' . implode( ';', $btn_hover_rules ) . '}';
		}

		if ( $field_typo_rules ) {
			$field_sel = $wrap_sel . ' .neural-forms-form__input,'
				. $wrap_sel . ' .neural-forms-form__select';
			$css .= $field_sel . '{' . implode( ';', $field_typo_rules ) . '}';
		}

		// ' Field bg / color / border — direct rules on the scoped input
		//   selector. We ALSO set CSS variables above (for back-compat and
		//   so non-block contexts like the shortcode pick them up), but in
		//   the block editor and in front-end themes that ship aggressive
		//   `input[type="text"]` rules (Gutenberg core, Divi, etc.), the
		//   consumer rule `.neural-forms-form__input { background-color:
		//   var(--…); }` from form.css can lose on specificity even though
		//   the variable resolves correctly. Emit the colors / border
		//   directly on the scoped uid selector (`.neural-forms-block-
		//   <uid> .neural-forms-form__input`) so the resulting selector
		//   beats `.editor-styles-wrapper input[type="text"]` in the
		//   canvas and survives most theme overrides on the frontend. '
		$field_idle_rules = [];
		if ( $field_bg_gradient !== '' ) {
			$field_idle_rules[] = 'background-image:' . sanitize_text_field( $field_bg_gradient );
		} elseif ( $field_bg !== '' ) {
			$field_idle_rules[] = 'background-color:' . sanitize_hex_color( $field_bg );
		}
		if ( $field_text_color !== '' ) {
			$field_idle_rules[] = 'color:' . sanitize_hex_color( $field_text_color );
		}
		// Border: only emit the shorthand when at least one of color /
		// width / style is set. Fill the others from the form.css default
		// so partial selections (e.g. just color) still produce a valid
		// declaration. Themes that override the full border shorthand
		// would otherwise wipe out a partial-property fight.
		if ( $field_border_color !== '' || $field_border_width !== '' || $field_border_style !== '' ) {
			$bw = $field_border_width !== '' ? $len( $field_border_width ) : '1px';
			$bc = $field_border_color !== '' ? sanitize_hex_color( $field_border_color ) : '#d1d5db';
			$bs = in_array( $field_border_style, [ 'solid', 'dashed', 'dotted' ], true ) ? $field_border_style : 'solid';
			$field_idle_rules[] = 'border:' . $bw . ' ' . $bs . ' ' . $bc;
		}
		if ( $field_border_radius !== '' ) {
			$field_idle_rules[] = 'border-radius:' . $len( $field_border_radius );
		}
		if ( $field_idle_rules ) {
			// Selector covers inputs + selects only. Textarea has its
			// own dedicated panel (Textarea Field → bg/border/etc.) so
			// it would be wrong for the Input Fields panel to bleed
			// into textareas. Keeping textarea out of this selector
			// list means an empty Textarea panel renders a transparent
			// textarea even when the Input Fields panel paints inputs.
			$field_idle_sel = $wrap_sel . ' .neural-forms-form__input,'
				. $wrap_sel . ' .neural-forms-form__select';
			$css .= $field_idle_sel . '{' . implode( ';', $field_idle_rules ) . '}';
		}

		// Focus-state direct rules — same reasoning as above. Form.css's
		// `:focus` rule for inputs uses CSS variables; we mirror it as
		// direct color rules on the scoped selector so theme/editor
		// `:focus` overrides don't win.
		$field_focus_rules = [];
		if ( $field_focus_bg_gradient !== '' ) {
			$field_focus_rules[] = 'background-image:' . sanitize_text_field( $field_focus_bg_gradient );
		} elseif ( $field_focus_bg !== '' ) {
			$field_focus_rules[] = 'background-color:' . sanitize_hex_color( $field_focus_bg );
		}
		if ( $field_focus_text !== '' ) {
			$field_focus_rules[] = 'color:' . sanitize_hex_color( $field_focus_text );
		}
		if ( $field_focus_border_color !== '' ) {
			$field_focus_rules[] = 'border-color:' . sanitize_hex_color( $field_focus_border_color );
		}
		if ( $field_focus_rules ) {
			// Inputs + selects only — textarea has its own focus
			// styling under the Textarea panel.
			$field_focus_sel = $wrap_sel . ' .neural-forms-form__input:focus,'
				. $wrap_sel . ' .neural-forms-form__select:focus';
			$css .= $field_focus_sel . '{' . implode( ';', $field_focus_rules ) . '}';
		}

		// ' Field padding (applies to inputs, selects — textarea has its own panel) '
		$field_pad_rules = [];
		foreach ( [ 'Top' => 'padding-top', 'Right' => 'padding-right', 'Bottom' => 'padding-bottom', 'Left' => 'padding-left' ] as $key => $css_prop ) {
			$val = (string) ( $attributes[ 'fieldPadding' . $key ] ?? '' );
			if ( $val !== '' ) {
				$field_pad_rules[] = $css_prop . ':' . $spacing( $val );
			}
		}
		if ( $field_pad_rules ) {
			$field_pad_sel = $wrap_sel . ' .neural-forms-form__input,'
				. $wrap_sel . ' .neural-forms-form__select';
			$css .= $field_pad_sel . '{' . implode( ';', $field_pad_rules ) . '}';
		}

		// ' Title typography / color / alignment '
		$title_rules = [];
		$title_color = (string) ( $attributes['titleColor'] ?? '' );
		if ( $title_color !== '' ) {
			$title_rules[] = 'color:' . sanitize_hex_color( $title_color );
		}
		$title_align = (string) ( $attributes['titleAlign'] ?? '' );
		if ( $title_align !== '' && in_array( $title_align, [ 'left', 'center', 'right', 'justify' ], true ) ) {
			$title_rules[] = 'text-align:' . $title_align;
		}
		$title_ff = (string) ( $attributes['titleFontFamily'] ?? '' );
		if ( $title_ff !== '' ) {
			$title_rules[] = 'font-family:' . sanitize_text_field( $title_ff );
		}
		$title_fs_raw = (string) ( $attributes['titleFontSize'] ?? '' );
		$title_slug_to_px = [ 'small' => '13px', 'medium' => '18px', 'large' => '24px', 'x-large' => '32px' ];
		if ( isset( $title_slug_to_px[ $title_fs_raw ] ) ) {
			$title_fs_raw = $title_slug_to_px[ $title_fs_raw ];
		}
		$title_fs = $len( $title_fs_raw );
		if ( $title_fs !== '' ) {
			$title_rules[] = 'font-size:' . $title_fs;
		}
		$title_fw = (string) ( $attributes['titleFontWeight'] ?? '' );
		if ( $title_fw !== '' ) {
			$title_rules[] = 'font-weight:' . sanitize_text_field( $title_fw );
		}
		$title_fstyle = (string) ( $attributes['titleFontStyle'] ?? '' );
		if ( $title_fstyle === 'italic' ) {
			$title_rules[] = 'font-style:italic';
		}
		$title_ls = (string) ( $attributes['titleLetterSpacing'] ?? '' );
		if ( $title_ls !== '' ) {
			$title_rules[] = 'letter-spacing:' . sanitize_text_field( $title_ls );
		}
		$title_td = (string) ( $attributes['titleTextDecoration'] ?? '' );
		if ( $title_td !== '' && in_array( $title_td, [ 'none', 'underline', 'overline', 'line-through' ], true ) ) {
			$title_rules[] = 'text-decoration:' . $title_td;
		}
		$title_tt = (string) ( $attributes['titleTextTransform'] ?? '' );
		if ( $title_tt !== '' && in_array( $title_tt, [ 'none', 'uppercase', 'lowercase', 'capitalize' ], true ) ) {
			$title_rules[] = 'text-transform:' . $title_tt;
		}
		$title_lh = (string) ( $attributes['titleLineHeight'] ?? '' );
		if ( $title_lh !== '' ) {
			$title_rules[] = 'line-height:' . sanitize_text_field( $title_lh );
		}
		foreach ( [ 'Top' => 'padding-top', 'Right' => 'padding-right', 'Bottom' => 'padding-bottom', 'Left' => 'padding-left' ] as $key => $css_prop ) {
			$val = (string) ( $attributes[ 'titlePadding' . $key ] ?? '' );
			if ( $val !== '' ) {
				$title_rules[] = $css_prop . ':' . $spacing( $val );
			}
		}
		foreach ( [ 'Top' => 'margin-top', 'Right' => 'margin-right', 'Bottom' => 'margin-bottom', 'Left' => 'margin-left' ] as $key => $css_prop ) {
			$val = (string) ( $attributes[ 'titleMargin' . $key ] ?? '' );
			if ( $val !== '' ) {
				$title_rules[] = $css_prop . ':' . $spacing( $val );
			}
		}
		if ( $title_rules ) {
			$css .= $wrap_sel . ' .neural-forms-form__title{' . implode( ';', $title_rules ) . '}';
		}

		// ' Label typography / color / alignment '
		// Targets visible <label> tags emitted when showLabels is true, plus
		// the <legend> used by radio / checkboxes group fields.
		$label_rules = [];
		$label_color = (string) ( $attributes['labelColor'] ?? '' );
		if ( $label_color !== '' ) {
			$label_rules[] = 'color:' . sanitize_hex_color( $label_color );
		}
		$label_align = (string) ( $attributes['labelAlign'] ?? '' );
		if ( $label_align !== '' && in_array( $label_align, [ 'left', 'center', 'right', 'justify' ], true ) ) {
			$label_rules[] = 'text-align:' . $label_align;
			$label_rules[] = 'display:block';
		}
		$label_ff = (string) ( $attributes['labelFontFamily'] ?? '' );
		if ( $label_ff !== '' ) {
			$label_rules[] = 'font-family:' . sanitize_text_field( $label_ff );
		}
		$label_fs_raw = (string) ( $attributes['labelFontSize'] ?? '' );
		$label_slug_to_px = [ 'small' => '12px', 'medium' => '14px', 'large' => '16px', 'x-large' => '20px' ];
		if ( isset( $label_slug_to_px[ $label_fs_raw ] ) ) {
			$label_fs_raw = $label_slug_to_px[ $label_fs_raw ];
		}
		$label_fs = $len( $label_fs_raw );
		if ( $label_fs !== '' ) {
			$label_rules[] = 'font-size:' . $label_fs;
		}
		$label_fw = (string) ( $attributes['labelFontWeight'] ?? '' );
		if ( $label_fw !== '' ) {
			$label_rules[] = 'font-weight:' . sanitize_text_field( $label_fw );
		}
		$label_fstyle = (string) ( $attributes['labelFontStyle'] ?? '' );
		if ( $label_fstyle === 'italic' ) {
			$label_rules[] = 'font-style:italic';
		}
		$label_ls = (string) ( $attributes['labelLetterSpacing'] ?? '' );
		if ( $label_ls !== '' ) {
			$ls = $label_ls;
			if ( is_numeric( $ls ) ) {
				$ls .= 'px';
			}
			$label_rules[] = 'letter-spacing:' . sanitize_text_field( $ls );
		}
		$label_td = (string) ( $attributes['labelTextDecoration'] ?? '' );
		if ( $label_td !== '' && in_array( $label_td, [ 'none', 'underline', 'overline', 'line-through' ], true ) ) {
			$label_rules[] = 'text-decoration:' . $label_td;
		}
		$label_tt = (string) ( $attributes['labelTextTransform'] ?? '' );
		if ( $label_tt !== '' && in_array( $label_tt, [ 'none', 'uppercase', 'lowercase', 'capitalize' ], true ) ) {
			$label_rules[] = 'text-transform:' . $label_tt;
		}
		$label_lh = (string) ( $attributes['labelLineHeight'] ?? '' );
		if ( $label_lh !== '' ) {
			$label_rules[] = 'line-height:' . sanitize_text_field( $label_lh );
		}
		foreach ( [ 'Top' => 'padding-top', 'Right' => 'padding-right', 'Bottom' => 'padding-bottom', 'Left' => 'padding-left' ] as $key => $css_prop ) {
			$val = (string) ( $attributes[ 'labelPadding' . $key ] ?? '' );
			if ( $val !== '' ) {
				$label_rules[] = $css_prop . ':' . $spacing( $val );
			}
		}
		foreach ( [ 'Top' => 'margin-top', 'Right' => 'margin-right', 'Bottom' => 'margin-bottom', 'Left' => 'margin-left' ] as $key => $css_prop ) {
			$val = (string) ( $attributes[ 'labelMargin' . $key ] ?? '' );
			if ( $val !== '' ) {
				$label_rules[] = $css_prop . ':' . $spacing( $val );
			}
		}
		if ( $label_rules ) {
			// .neural-forms-form__label covers both block-level <label> tags
			// (text/email/textarea/select fields) and the <legend> emitted by
			// radio / checkboxes group fields when show_labels is on.
			$label_sel = $wrap_sel . ' .neural-forms-form__label';
			$css .= $label_sel . '{' . implode( ';', $label_rules ) . '}';
		}

		// ' Per-corner field radius '
		$field_corner_rules = [];
		foreach ( [ 'TL' => 'border-top-left-radius', 'TR' => 'border-top-right-radius', 'BR' => 'border-bottom-right-radius', 'BL' => 'border-bottom-left-radius' ] as $key => $css_prop ) {
			$val = (string) ( $attributes[ 'fieldBorderRadius' . $key ] ?? '' );
			if ( $val !== '' ) {
				$field_corner_rules[] = $css_prop . ':' . $spacing( $val );
			}
		}
		if ( $field_corner_rules ) {
			$field_corner_sel = $wrap_sel . ' .neural-forms-form__input,'
				. $wrap_sel . ' .neural-forms-form__select';
			$css .= $field_corner_sel . '{' . implode( ';', $field_corner_rules ) . '}';
		}

		// ' Field hover state (bg + border color — textarea has its own panel) '
		$field_hover_rules    = [];
		$field_hover_bg          = (string) ( $attributes['fieldHoverBg']         ?? '' );
		$field_hover_bg_gradient = (string) ( $attributes['fieldHoverBgGradient'] ?? '' );
		if ( $field_hover_bg_gradient !== '' ) {
			$field_hover_rules[] = 'background-image:' . sanitize_text_field( $field_hover_bg_gradient );
		} elseif ( $field_hover_bg !== '' ) {
			$field_hover_rules[] = 'background-color:' . sanitize_hex_color( $field_hover_bg );
		}
		$field_hover_border = (string) ( $attributes['fieldHoverBorderColor'] ?? '' );
		if ( $field_hover_border !== '' ) {
			$field_hover_rules[] = 'border-color:' . sanitize_hex_color( $field_hover_border );
		}
		if ( $field_hover_rules ) {
			$field_hover_sel = $wrap_sel . ' .neural-forms-form__input:hover:not(:focus),'
				. $wrap_sel . ' .neural-forms-form__select:hover:not(:focus)';
			$css .= $field_hover_sel . '{' . implode( ';', $field_hover_rules ) . '}';
		}

		// ' Textarea field — type-specific (.neural-forms-form__textarea) '
		$ta_sel   = $wrap_sel . ' .neural-forms-form__textarea';
		$ta_rules = [];
		$ta_bg          = (string) ( $attributes['textareaBg']         ?? '' );
		$ta_bg_gradient = (string) ( $attributes['textareaBgGradient'] ?? '' );
		if ( $ta_bg_gradient !== '' ) {
			$ta_rules[] = 'background-image:' . sanitize_text_field( $ta_bg_gradient );
		} elseif ( $ta_bg !== '' ) {
			$ta_rules[] = 'background-color:' . sanitize_hex_color( $ta_bg );
		}
		$ta_text = (string) ( $attributes['textareaTextColor'] ?? '' );
		if ( $ta_text !== '' ) $ta_rules[] = 'color:' . sanitize_hex_color( $ta_text );
		$ta_bc = (string) ( $attributes['textareaBorderColor'] ?? '' );
		$ta_bw = (string) ( $attributes['textareaBorderWidth'] ?? '' );
		$ta_bs = (string) ( $attributes['textareaBorderStyle'] ?? '' );
		if ( $ta_bc !== '' || $ta_bw !== '' || $ta_bs !== '' ) {
			$bw = $ta_bw !== '' ? sanitize_text_field( $ta_bw ) : '1px';
			$bc = $ta_bc !== '' ? sanitize_hex_color( $ta_bc ) : 'transparent';
			$bs = in_array( $ta_bs, [ 'solid', 'dashed', 'dotted' ], true ) ? $ta_bs : 'solid';
			$ta_rules[] = 'border:' . $bw . ' ' . $bs . ' ' . $bc;
		}
		foreach ( [ 'TL' => 'border-top-left-radius', 'TR' => 'border-top-right-radius', 'BR' => 'border-bottom-right-radius', 'BL' => 'border-bottom-left-radius' ] as $k => $prop ) {
			$v = $len( (string) ( $attributes[ 'textareaBorderRadius' . $k ] ?? '' ) );
			if ( $v !== '' ) $ta_rules[] = $prop . ':' . $v;
		}
		$ta_fs_raw = (string) ( $attributes['textareaFontSize'] ?? '' );
		if ( isset( $slug_to_px[ $ta_fs_raw ] ) ) $ta_fs_raw = $slug_to_px[ $ta_fs_raw ];
		$ta_fs = $len( $ta_fs_raw );
		if ( $ta_fs !== '' ) $ta_rules[] = 'font-size:' . $ta_fs;
		$ta_fw = (string) ( $attributes['textareaFontWeight'] ?? '' );
		if ( $ta_fw !== '' ) $ta_rules[] = 'font-weight:' . sanitize_text_field( $ta_fw );
		$ta_fstyle = (string) ( $attributes['textareaFontStyle'] ?? '' );
		if ( $ta_fstyle === 'italic' ) $ta_rules[] = 'font-style:italic';
		$ta_ff = (string) ( $attributes['textareaFontFamily'] ?? '' );
		if ( $ta_ff !== '' ) $ta_rules[] = 'font-family:' . sanitize_text_field( $ta_ff );
		$ta_ls = (string) ( $attributes['textareaLetterSpacing'] ?? '' );
		if ( $ta_ls !== '' ) { if ( is_numeric( $ta_ls ) ) $ta_ls .= 'px'; $ta_rules[] = 'letter-spacing:' . sanitize_text_field( $ta_ls ); }
		$ta_lh = (string) ( $attributes['textareaLineHeight'] ?? '' );
		if ( $ta_lh !== '' ) $ta_rules[] = 'line-height:' . sanitize_text_field( $ta_lh );
		$ta_tt = (string) ( $attributes['textareaTextTransform'] ?? '' );
		if ( $ta_tt !== '' && in_array( $ta_tt, [ 'none', 'uppercase', 'lowercase', 'capitalize' ], true ) ) $ta_rules[] = 'text-transform:' . $ta_tt;
		$ta_td = (string) ( $attributes['textareaTextDecoration'] ?? '' );
		if ( $ta_td !== '' && in_array( $ta_td, [ 'none', 'underline', 'overline', 'line-through' ], true ) ) $ta_rules[] = 'text-decoration:' . $ta_td;
		$ta_ta = (string) ( $attributes['textareaTextAlign'] ?? '' );
		if ( $ta_ta !== '' && in_array( $ta_ta, [ 'left', 'center', 'right', 'justify' ], true ) ) $ta_rules[] = 'text-align:' . $ta_ta;
		foreach ( [ 'Top' => 'padding-top', 'Right' => 'padding-right', 'Bottom' => 'padding-bottom', 'Left' => 'padding-left' ] as $k => $prop ) {
			$v = $spacing( (string) ( $attributes[ 'textareaPadding' . $k ] ?? '' ) );
			if ( $v !== '' ) $ta_rules[] = $prop . ':' . $v;
		}
		$ta_mh = $len( (string) ( $attributes['textareaMinHeight'] ?? '' ) );
		if ( $ta_mh !== '' ) $ta_rules[] = 'min-height:' . $ta_mh;
		if ( $ta_rules ) $css .= $ta_sel . '{' . implode( ';', $ta_rules ) . '}';

		$ta_focus_rules          = [];
		$ta_focus_bg             = (string) ( $attributes['textareaFocusBg']         ?? '' );
		$ta_focus_bg_gradient    = (string) ( $attributes['textareaFocusBgGradient'] ?? '' );
		if ( $ta_focus_bg_gradient !== '' ) {
			$ta_focus_rules[] = 'background-image:' . sanitize_text_field( $ta_focus_bg_gradient );
		} elseif ( $ta_focus_bg !== '' ) {
			$ta_focus_rules[] = 'background-color:' . sanitize_hex_color( $ta_focus_bg );
		}
		$ta_focus_text = (string) ( $attributes['textareaFocusTextColor'] ?? '' );
		if ( $ta_focus_text !== '' ) $ta_focus_rules[] = 'color:' . sanitize_hex_color( $ta_focus_text );
		$ta_focus_border = (string) ( $attributes['textareaFocusBorderColor'] ?? '' );
		if ( $ta_focus_border !== '' ) $ta_focus_rules[] = 'border-color:' . sanitize_hex_color( $ta_focus_border );
		if ( $ta_focus_rules ) $css .= $ta_sel . ':focus{' . implode( ';', $ta_focus_rules ) . '}';

		$ta_hover_rules          = [];
		$ta_hover_bg             = (string) ( $attributes['textareaHoverBg']         ?? '' );
		$ta_hover_bg_gradient    = (string) ( $attributes['textareaHoverBgGradient'] ?? '' );
		if ( $ta_hover_bg_gradient !== '' ) {
			$ta_hover_rules[] = 'background-image:' . sanitize_text_field( $ta_hover_bg_gradient );
		} elseif ( $ta_hover_bg !== '' ) {
			$ta_hover_rules[] = 'background-color:' . sanitize_hex_color( $ta_hover_bg );
		}
		$ta_hover_border = (string) ( $attributes['textareaHoverBorderColor'] ?? '' );
		if ( $ta_hover_border !== '' ) $ta_hover_rules[] = 'border-color:' . sanitize_hex_color( $ta_hover_border );
		if ( $ta_hover_rules ) $css .= $ta_sel . ':hover:not(:focus){' . implode( ';', $ta_hover_rules ) . '}';

		$ta_placeholder_color = sanitize_hex_color( (string) ( $attributes['textareaPlaceholderColor'] ?? '' ) );
		if ( $ta_placeholder_color !== null && $ta_placeholder_color !== '' ) {
			$css .= $ta_sel . '::placeholder{color:' . $ta_placeholder_color . '}';
		}

		// ' Acceptance field — checkbox + label (.neural-forms-form__field--acceptance) '
		$acc_cb_sel    = $wrap_sel . ' .neural-forms-form__field--acceptance .neural-forms-form__checkbox';
		$acc_label_sel = $wrap_sel . ' .neural-forms-form__field--acceptance .neural-forms-form__label--checkbox';
		$acc_wrap_sel  = $wrap_sel . ' .neural-forms-form__field--acceptance .neural-forms-form__checkbox-wrapper';
		$acc_cb_rules      = [];
		$acc_checked_bg    = sanitize_hex_color( (string) ( $attributes['acceptanceCheckedBg'] ?? '' ) );
		$acc_border_color  = sanitize_hex_color( (string) ( $attributes['acceptanceBorderColor'] ?? '' ) );
		$acc_border_width  = $len( (string) ( $attributes['acceptanceBorderWidth'] ?? '' ) );
		$acc_radius        = $len( (string) ( $attributes['acceptanceBorderRadius'] ?? '' ) );
		$acc_size_raw      = $len( (string) ( $attributes['acceptanceSize'] ?? '' ) );

		if ( $acc_size_raw !== '' ) { $acc_cb_rules[] = 'width:' . $acc_size_raw; $acc_cb_rules[] = 'height:' . $acc_size_raw; }
		if ( $acc_border_color !== null && $acc_border_color !== '' ) {
			$acc_cb_rules[] = 'border-color:' . $acc_border_color;
			$acc_cb_rules[] = 'border-style:solid';
			if ( $acc_border_width !== '' ) $acc_cb_rules[] = 'border-width:' . $acc_border_width;
		} elseif ( $acc_border_width !== '' ) {
			$acc_cb_rules[] = 'border-style:solid';
			$acc_cb_rules[] = 'border-width:' . $acc_border_width;
		}
		if ( $acc_radius !== '' ) $acc_cb_rules[] = 'border-radius:' . $acc_radius;

		if ( $acc_cb_rules ) {
			// appearance:none required so browser stops using native rendering and honours our CSS.
			// We prepend base reset rules so they come first in the declaration.
			$base = [ 'appearance:none', '-webkit-appearance:none', 'cursor:pointer', 'background-color:#ffffff', 'box-sizing:border-box' ];
			$css .= $acc_cb_sel . '{' . implode( ';', array_merge( $base, $acc_cb_rules ) ) . '}';
			// Checked state: fill with accent colour + inline SVG checkmark (accent-color doesn't work with appearance:none).
			$fill    = ( $acc_checked_bg !== null && $acc_checked_bg !== '' ) ? $acc_checked_bg : '#2271b1';
			$chk_svg = "url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3E%3Cpath fill='none' stroke='%23fff' stroke-width='2' d='M3 8l3.5 3.5L13 4'/%3E%3C/svg%3E\")";
			$css .= $acc_cb_sel . ':checked{background-color:' . $fill . ';background-image:' . $chk_svg . ';background-size:70%;background-repeat:no-repeat;background-position:center}';
		} elseif ( $acc_checked_bg !== null && $acc_checked_bg !== '' ) {
			// Only the accent colour is set — keep native appearance so accent-color still works.
			$css .= $acc_cb_sel . '{accent-color:' . $acc_checked_bg . '}';
		}

		$acc_label_rules = [];
		$acc_label_color = (string) ( $attributes['acceptanceLabelColor'] ?? '' );
		if ( $acc_label_color !== '' ) $acc_label_rules[] = 'color:' . sanitize_hex_color( $acc_label_color );
		$acc_label_fs_raw = (string) ( $attributes['acceptanceLabelFontSize'] ?? '' );
		$label_slug_map = [ 'small' => '12px', 'medium' => '14px', 'large' => '16px', 'x-large' => '20px' ];
		if ( isset( $label_slug_map[ $acc_label_fs_raw ] ) ) $acc_label_fs_raw = $label_slug_map[ $acc_label_fs_raw ];
		$acc_label_fs = $len( $acc_label_fs_raw );
		if ( $acc_label_fs !== '' ) $acc_label_rules[] = 'font-size:' . $acc_label_fs;
		$acc_label_fw = (string) ( $attributes['acceptanceLabelFontWeight'] ?? '' );
		if ( $acc_label_fw !== '' ) $acc_label_rules[] = 'font-weight:' . sanitize_text_field( $acc_label_fw );
		if ( $acc_label_rules ) $css .= $acc_label_sel . '{' . implode( ';', $acc_label_rules ) . '}';

		$acc_gap = (string) ( $attributes['acceptanceGap'] ?? '' );
		if ( $acc_gap !== '' ) $css .= $acc_wrap_sel . '{gap:' . sanitize_text_field( $acc_gap ) . '}';

		// ' Field error state (invalid input + per-field error message) '
		$field_err_msg_rules = [];
		$field_err_msg_color = (string) ( $attributes['fieldErrorMsgColor'] ?? '' );
		if ( $field_err_msg_color !== '' ) {
			$hex = sanitize_hex_color( $field_err_msg_color );
			if ( $hex !== null && $hex !== '' ) {
				$field_err_msg_rules[] = 'color:' . $hex;
			}
		}
		$field_err_msg_fs = $len( $attributes['fieldErrorMsgFontSize'] ?? '' );
		if ( $field_err_msg_fs !== '' ) {
			$field_err_msg_rules[] = 'font-size:' . $field_err_msg_fs;
		}
		if ( $field_err_msg_rules ) {
			$css .= $wrap_sel . ' .neural-forms-form__field-error{' . implode( ';', $field_err_msg_rules ) . '}';
		}

		// ' Success message '
		if ( isset( $attributes['successMsgEnabled'] ) && $attributes['successMsgEnabled'] === false ) {
			$css .= $wrap_sel . ' .neural-forms-form__success{display:none !important}';
		} else {
			$success_rules          = [];
			$success_bg             = (string) ( $attributes['successMsgBg']         ?? '' );
			$success_bg_gradient    = (string) ( $attributes['successMsgBgGradient'] ?? '' );
			if ( $success_bg_gradient !== '' ) {
				$success_rules[] = 'background-image:' . sanitize_text_field( $success_bg_gradient );
			} elseif ( $success_bg !== '' ) {
				$success_rules[] = 'background-color:' . sanitize_hex_color( $success_bg );
			}
			$success_color = (string) ( $attributes['successMsgColor'] ?? '' );
			if ( $success_color !== '' ) {
				$success_rules[] = 'color:' . sanitize_hex_color( $success_color );
			}

			foreach ( [ 'Top' => 'padding-top', 'Right' => 'padding-right', 'Bottom' => 'padding-bottom', 'Left' => 'padding-left' ] as $key => $css_prop ) {
				$val = (string) ( $attributes[ 'successMsgPadding' . $key ] ?? '' );
				if ( $val !== '' ) {
					$success_rules[] = $css_prop . ':' . $spacing( $val );
				}
			}
			if ( $success_rules ) {
				$css .= $wrap_sel . ' .neural-forms-form__success{' . implode( ';', $success_rules ) . '}';
			}
		}

		// ' Global error message '
		if ( isset( $attributes['errorMsgEnabled'] ) && $attributes['errorMsgEnabled'] === false ) {
			$css .= $wrap_sel . ' .neural-forms-form__global-error{display:none !important}';
		} else {
			$err_rules          = [];
			$err_bg             = (string) ( $attributes['errorMsgBg']         ?? '' );
			$err_bg_gradient    = (string) ( $attributes['errorMsgBgGradient'] ?? '' );
			if ( $err_bg_gradient !== '' ) {
				$err_rules[] = 'background-image:' . sanitize_text_field( $err_bg_gradient );
			} elseif ( $err_bg !== '' ) {
				$hex = sanitize_hex_color( $err_bg );
				if ( $hex !== null && $hex !== '' ) {
					$err_rules[] = 'background-color:' . $hex;
				}
			}
			$err_color = (string) ( $attributes['errorMsgColor'] ?? '' );
			if ( $err_color !== '' ) {
				$hex = sanitize_hex_color( $err_color );
				if ( $hex !== null && $hex !== '' ) {
					$err_rules[] = 'color:' . $hex;
				}
			}
			foreach ( [ 'Top' => 'padding-top', 'Right' => 'padding-right', 'Bottom' => 'padding-bottom', 'Left' => 'padding-left' ] as $key => $css_prop ) {
				$val = (string) ( $attributes[ 'errorMsgPadding' . $key ] ?? '' );
				if ( $val !== '' ) {
					$err_rules[] = $css_prop . ':' . $spacing( $val );
				}
			}
			if ( $err_rules ) {
				$css .= $wrap_sel . ' .neural-forms-form__global-error{' . implode( ';', $err_rules ) . '}';
			}
		}

		// ——— Radio field ———

		$radio_opt_sel       = $wrap_sel . ' .neural-forms-form__radio-option';
		$radio_opt_hover_sel = $radio_opt_sel . ':hover';
		$radio_opt_chk_sel   = $wrap_sel . ' .neural-forms-form__radio-option:has(.neural-forms-form__radio:checked)';
		$radio_opts_sel      = $wrap_sel . ' .neural-forms-form__radio-group';
		$radio_input_sel     = $wrap_sel . ' .neural-forms-form__radio';
		$radio_label_sel     = $wrap_sel . ' .neural-forms-form__label--radio';

		$radio_direction = (string) ( $attributes['radioOptionsDirection'] ?? 'vertical' );
		if ( $radio_direction === 'horizontal' ) {
			$css .= $radio_opts_sel . '{flex-direction:row;flex-wrap:wrap}';
		}

		$radio_gap = (string) ( $attributes['radioOptionsGap'] ?? '' );
		if ( $radio_gap !== '' ) {
			$css .= $radio_opts_sel . '{gap:' . sanitize_text_field( $radio_gap ) . '}';
		}

		$radio_size = (string) ( $attributes['radioOptionSize'] ?? '' );
		if ( $radio_size !== '' ) {
			$s = $len( $radio_size );
			$css .= $radio_input_sel . '{width:' . $s . ';height:' . $s . ';min-width:' . $s . '}';
		}

		$radio_label_gap = (string) ( $attributes['radioLabelGap'] ?? '' );
		if ( $radio_label_gap !== '' ) {
			$css .= $radio_input_sel . '{margin-right:' . $len( $radio_label_gap ) . '}';
		}

		$radio_opt_rules = [];
		$radio_opt_bg = (string) ( $attributes['radioOptionBg'] ?? '' );
		if ( $radio_opt_bg !== '' ) {
			$radio_opt_rules[] = 'background-color:' . sanitize_hex_color( $radio_opt_bg );
		}
		$radio_opt_border_color = (string) ( $attributes['radioOptionBorderColor'] ?? '' );
		$radio_opt_border_width = (string) ( $attributes['radioOptionBorderWidth'] ?? '' );
		$radio_opt_border_style = (string) ( $attributes['radioOptionBorderStyle'] ?? '' );
		if ( $radio_opt_border_color !== '' || $radio_opt_border_width !== '' || $radio_opt_border_style !== '' ) {
			$bw = $radio_opt_border_width !== '' ? sanitize_text_field( $radio_opt_border_width ) : '1px';
			$bc = $radio_opt_border_color !== '' ? ( sanitize_hex_color( $radio_opt_border_color ) ?? 'transparent' ) : 'transparent';
			$bs = in_array( $radio_opt_border_style, [ 'solid', 'dashed', 'dotted' ], true ) ? $radio_opt_border_style : 'solid';
			$radio_opt_rules[] = 'border:' . $bw . ' ' . $bs . ' ' . $bc;
		}
		$radio_opt_radius = (string) ( $attributes['radioOptionBorderRadius'] ?? '' );
		if ( $radio_opt_radius !== '' ) {
			$radio_opt_rules[] = 'border-radius:' . sanitize_text_field( $radio_opt_radius );
		}
		foreach ( [ 'Top' => 'padding-top', 'Right' => 'padding-right', 'Bottom' => 'padding-bottom', 'Left' => 'padding-left' ] as $side => $css_prop ) {
			$val = (string) ( $attributes[ 'radioOptionPadding' . $side ] ?? '' );
			if ( $val !== '' ) {
				$radio_opt_rules[] = $css_prop . ':' . $spacing( $val );
			}
		}
		if ( $radio_opt_rules ) {
			$css .= $radio_input_sel . '{' . implode( ';', $radio_opt_rules ) . '}';
		}

		$radio_hover_bg = (string) ( $attributes['radioOptionHoverBg'] ?? '' );
		if ( $radio_hover_bg !== '' ) {
			$css .= $radio_opt_hover_sel . '{background-color:' . sanitize_hex_color( $radio_hover_bg ) . '}';
		}

		$radio_checked_bg = (string) ( $attributes['radioOptionCheckedBg'] ?? '' );
		if ( $radio_checked_bg !== '' ) {
			$css .= $radio_opt_chk_sel . '{background-color:' . sanitize_hex_color( $radio_checked_bg ) . '}';
		}

		$radio_accent = (string) ( $attributes['radioAccentColor'] ?? '' );
		if ( $radio_accent !== '' ) {
			$hex = sanitize_hex_color( $radio_accent );
			if ( $hex !== null && $hex !== '' ) {
				$css .= $radio_input_sel . ':checked{background-image:radial-gradient(circle,' . $hex . ' 40%,transparent 40%);border-color:' . $hex . '}';
			}
		}

		$radio_label_rules = [];
		$radio_label_color = (string) ( $attributes['radioOptionColor'] ?? '' );
		if ( $radio_label_color !== '' ) {
			$hex = sanitize_hex_color( $radio_label_color );
			if ( $hex !== null && $hex !== '' ) {
				$radio_label_rules[] = 'color:' . $hex;
			}
		}
		$radio_label_fs = (string) ( $attributes['radioOptionFontSize'] ?? '' );
		if ( $radio_label_fs !== '' ) {
			$radio_label_rules[] = 'font-size:' . $len( $radio_label_fs );
		}
		$radio_label_fw = (string) ( $attributes['radioOptionFontWeight'] ?? '' );
		if ( $radio_label_fw !== '' ) {
			$radio_label_rules[] = 'font-weight:' . sanitize_text_field( $radio_label_fw );
		}
		if ( $radio_label_rules ) {
			$css .= $radio_label_sel . '{' . implode( ';', $radio_label_rules ) . '}';
		}

		// ——— Checkbox group field ———

		$cbg_cb_grp_sel = $wrap_sel . ' .neural-forms-form__checkbox-group .neural-forms-form__checkbox';
		$cbg_cb_sng_sel = $wrap_sel . ' .neural-forms-form__field--checkbox .neural-forms-form__checkbox';
		$cbg_cb_sel     = $cbg_cb_grp_sel . ',' . $cbg_cb_sng_sel;
		$cbg_cb_chk_sel = $cbg_cb_grp_sel . ':checked,' . $cbg_cb_sng_sel . ':checked';
		$cbg_cb_fcs_sel = $cbg_cb_grp_sel . ':focus,' . $cbg_cb_sng_sel . ':focus';
		$cbg_label_sel  = $wrap_sel . ' .neural-forms-form__checkbox-group .neural-forms-form__label--checkbox,'
		                . $wrap_sel . ' .neural-forms-form__field--checkbox .neural-forms-form__label--checkbox';
		$cbg_opt_sel    = $wrap_sel . ' .neural-forms-form__checkbox-group .neural-forms-form__checkbox-option';
		$cbg_grp_sel    = $wrap_sel . ' .neural-forms-form__checkbox-group';

		$cbg_direction = (string) ( $attributes['cbGroupDirection'] ?? 'vertical' );
		if ( $cbg_direction === 'horizontal' ) {
			$css .= $cbg_grp_sel . '{flex-direction:row;flex-wrap:wrap}';
		}

		$cbg_size          = (string) ( $attributes['cbGroupSize']        ?? '' );
		$cbg_bg            = (string) ( $attributes['cbGroupBg']          ?? '' );
		$cbg_bg_gradient   = (string) ( $attributes['cbGroupBgGradient']  ?? '' );
		$cbg_chk  = (string) ( $attributes['cbGroupCheckedBg'] ?? '' );
		$cbg_bc   = (string) ( $attributes['cbGroupBorderColor'] ?? '' );
		$cbg_bw   = (string) ( $attributes['cbGroupBorderWidth'] ?? '' );
		$cbg_br   = (string) ( $attributes['cbGroupBorderRadius'] ?? '' );

		$has_cbg_custom = ( $cbg_bg !== '' || $cbg_bg_gradient !== '' || $cbg_bc !== '' || $cbg_bw !== '' || $cbg_br !== '' || $cbg_size !== '' );

		$cbg_cb_rules = [];
		if ( $cbg_size !== '' ) {
			$s = $len( $cbg_size );
			$cbg_cb_rules[] = 'width:' . $s;
			$cbg_cb_rules[] = 'height:' . $s;
			$cbg_cb_rules[] = 'min-width:' . $s;
		}
		if ( $has_cbg_custom ) {
			// appearance:none so border/bg CSS takes effect on native checkboxes
			$cbg_cb_rules[] = 'appearance:none';
			$cbg_cb_rules[] = '-webkit-appearance:none';
			$cbg_cb_rules[] = 'cursor:pointer';
			$cbg_cb_rules[] = 'box-sizing:border-box';
			if ( $cbg_bg_gradient !== '' ) {
				$cbg_cb_rules[] = 'background-image:' . sanitize_text_field( $cbg_bg_gradient );
			} else {
				$cbg_bg_hex = $cbg_bg !== '' ? ( sanitize_hex_color( $cbg_bg ) ?? '#ffffff' ) : '#ffffff';
				$cbg_cb_rules[] = 'background-color:' . $cbg_bg_hex;
			}
		}
		if ( $cbg_bc !== '' || $cbg_bw !== '' ) {
			$bw = $cbg_bw !== '' ? sanitize_text_field( $cbg_bw ) : '1px';
			$bc = $cbg_bc !== '' ? ( sanitize_hex_color( $cbg_bc ) ?? '#cccccc' ) : '#cccccc';
			$cbg_cb_rules[] = 'border:' . $bw . ' solid ' . $bc;
		}
		if ( $cbg_br !== '' ) {
			$cbg_cb_rules[] = 'border-radius:' . $len( $cbg_br );
		}
		if ( $cbg_cb_rules ) {
			$css .= $cbg_cb_sel . '{' . implode( ';', $cbg_cb_rules ) . '}';
		}

		if ( $has_cbg_custom ) {
			// Checked state: accent bg + white SVG tick
			$fill_hex   = $cbg_chk !== '' ? ( sanitize_hex_color( $cbg_chk ) ?? '#2271b1' ) : '#2271b1';
			$chk_svg    = "url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3E%3Cpath fill='none' stroke='%23fff' stroke-width='2' d='M3 8l3.5 3.5L13 4'/%3E%3C/svg%3E\")";
			$css .= $cbg_cb_chk_sel . '{background-color:' . $fill_hex . ';background-image:' . $chk_svg . ';background-size:70%;background-repeat:no-repeat;background-position:center;border-color:' . $fill_hex . '}';
			$css .= $cbg_cb_fcs_sel . '{outline:none}';
		} elseif ( $cbg_chk !== '' ) {
			// Only checked bg set — keep native appearance and use accent-color
			$hex = sanitize_hex_color( $cbg_chk );
			if ( $hex !== null && $hex !== '' ) {
				$css .= $cbg_cb_sel . '{accent-color:' . $hex . '}';
			}
		}

		$cbg_gap = (string) ( $attributes['cbGroupGap'] ?? '' );
		if ( $cbg_gap !== '' ) {
			$css .= $cbg_cb_sel . '{margin-right:' . $len( $cbg_gap ) . '}';
		}

		$cbg_item_gap = (string) ( $attributes['cbGroupItemGap'] ?? '' );
		if ( $cbg_item_gap !== '' ) {
			$css .= $cbg_grp_sel . '{gap:' . $len( $cbg_item_gap ) . '}';
		}

		$cbg_label_rules = [];
		$cbg_lc = (string) ( $attributes['cbGroupLabelColor'] ?? '' );
		if ( $cbg_lc !== '' ) {
			$hex = sanitize_hex_color( $cbg_lc );
			if ( $hex !== null && $hex !== '' ) {
				$cbg_label_rules[] = 'color:' . $hex;
			}
		}
		$cbg_lfs = (string) ( $attributes['cbGroupLabelFontSize'] ?? '' );
		if ( $cbg_lfs !== '' ) {
			$cbg_label_rules[] = 'font-size:' . $len( $cbg_lfs );
		}
		$cbg_lfw = (string) ( $attributes['cbGroupLabelFontWeight'] ?? '' );
		if ( $cbg_lfw !== '' ) {
			$cbg_label_rules[] = 'font-weight:' . sanitize_text_field( $cbg_lfw );
		}
		if ( $cbg_label_rules ) {
			$css .= $cbg_label_sel . '{' . implode( ';', $cbg_label_rules ) . '}';
		}

		// ——— Rating field ———

		$rating_glyph_sel = $wrap_sel . ' .neural-forms-form__rating-glyph';
		$rating_star_sel  = $wrap_sel . ' .neural-forms-form__rating-star';
		$rating_row_sel   = $wrap_sel . ' .neural-forms-form__rating';

		$rating_size = (string) ( $attributes['ratingStarSize'] ?? '' );
		if ( $rating_size !== '' ) {
			$css .= $rating_glyph_sel . '{font-size:' . $len( $rating_size ) . '}';
		}

		$rating_gap = (string) ( $attributes['ratingStarGap'] ?? '' );
		if ( $rating_gap !== '' ) {
			$css .= $rating_row_sel . '{gap:' . $len( $rating_gap ) . '}';
		}

		$rating_empty = (string) ( $attributes['ratingEmptyColor'] ?? '' );
		if ( $rating_empty !== '' ) {
			$hex = sanitize_hex_color( $rating_empty );
			if ( $hex !== null && $hex !== '' ) {
				// Default state: every glyph (all are visually "empty" until starred)
				$css .= $rating_glyph_sel . '{color:' . $hex . '}';
			}
		}

		$rating_filled = (string) ( $attributes['ratingFilledColor'] ?? '' );
		if ( $rating_filled !== '' ) {
			$hex = sanitize_hex_color( $rating_filled );
			if ( $hex !== null && $hex !== '' ) {
				// Filled: checked input's glyph + all preceding siblings (row-reverse trick)
				$css .= $wrap_sel . ' .neural-forms-form__rating-input:checked ~ .neural-forms-form__rating-glyph,'
					. $wrap_sel . ' .neural-forms-form__rating-star:has(.neural-forms-form__rating-input:checked) .neural-forms-form__rating-glyph,'
					. $wrap_sel . ' .neural-forms-form__rating-star:has(.neural-forms-form__rating-input:checked) ~ .neural-forms-form__rating-star .neural-forms-form__rating-glyph'
					. '{color:' . $hex . '}';
			}
		}

		$rating_hover = (string) ( $attributes['ratingHoverColor'] ?? '' );
		if ( $rating_hover !== '' ) {
			$hex = sanitize_hex_color( $rating_hover );
			if ( $hex !== null && $hex !== '' ) {
				$css .= $rating_star_sel . ':hover,'
					. $rating_star_sel . ':hover ~ ' . $rating_star_sel . ','
					. $rating_star_sel . ':focus-within,'
					. $rating_star_sel . ':focus-within ~ ' . $rating_star_sel
					. '{color:' . $hex . '}';
			}
		}

		// ——— File input field (filename display area) ———

		$fi_sel   = $wrap_sel . ' .neural-forms-form__file-input';
		$fi_rules = [];

		$fi_bg          = (string) ( $attributes['fileInputBg']         ?? '' );
		$fi_bg_gradient = (string) ( $attributes['fileInputBgGradient'] ?? '' );
		if ( $fi_bg_gradient !== '' ) {
			$fi_rules[] = 'background-image:' . sanitize_text_field( $fi_bg_gradient );
		} elseif ( $fi_bg !== '' ) {
			$hex = sanitize_hex_color( $fi_bg );
			if ( $hex !== null && $hex !== '' ) {
				$fi_rules[] = 'background-color:' . $hex;
			}
		}

		$fi_color = (string) ( $attributes['fileInputColor'] ?? '' );
		if ( $fi_color !== '' ) {
			$hex = sanitize_hex_color( $fi_color );
			if ( $hex !== null && $hex !== '' ) {
				$fi_rules[] = 'color:' . $hex;
			}
		}

		$fi_fs = (string) ( $attributes['fileInputFontSize'] ?? '' );
		if ( $fi_fs !== '' ) {
			$fi_rules[] = 'font-size:' . $len( $fi_fs );
		}

		$fi_bc = (string) ( $attributes['fileInputBorderColor'] ?? '' );
		$fi_bw = (string) ( $attributes['fileInputBorderWidth'] ?? '' );
		$fi_bs = (string) ( $attributes['fileInputBorderStyle'] ?? '' );
		if ( $fi_bc !== '' || $fi_bw !== '' || $fi_bs !== '' ) {
			$bw = $fi_bw !== '' ? sanitize_text_field( $fi_bw ) : '1px';
			$bc = $fi_bc !== '' ? ( sanitize_hex_color( $fi_bc ) ?? 'transparent' ) : 'transparent';
			$bs = in_array( $fi_bs, [ 'solid', 'dashed', 'dotted' ], true ) ? $fi_bs : 'solid';
			$fi_rules[] = 'border:' . $bw . ' ' . $bs . ' ' . $bc;
		}

		$fi_br = (string) ( $attributes['fileInputBorderRadius'] ?? '' );
		if ( $fi_br !== '' ) {
			$fi_rules[] = 'border-radius:' . $len( $fi_br );
		}

		foreach ( [ 'Top' => 'padding-top', 'Right' => 'padding-right', 'Bottom' => 'padding-bottom', 'Left' => 'padding-left' ] as $side => $css_prop ) {
			$val = (string) ( $attributes[ 'fileInputPadding' . $side ] ?? '' );
			if ( $val !== '' ) {
				$fi_rules[] = $css_prop . ':' . $spacing( $val );
			}
		}

		if ( $fi_rules ) {
			$css .= $fi_sel . '{' . implode( ';', $fi_rules ) . '}';
		}

		// ——— File browse button (::file-selector-button pseudo-element) ———

		$fbtn_sel       = $wrap_sel . ' .neural-forms-form__file-input::file-selector-button';
		$fbtn_hover_sel = $wrap_sel . ' .neural-forms-form__file-input:hover::file-selector-button';
		$fbtn_rules     = [];

		$fbtn_bg          = (string) ( $attributes['fileBtnBg']         ?? '' );
		$fbtn_bg_gradient = (string) ( $attributes['fileBtnBgGradient'] ?? '' );
		if ( $fbtn_bg_gradient !== '' ) {
			$fbtn_rules[] = 'background-image:' . sanitize_text_field( $fbtn_bg_gradient );
		} elseif ( $fbtn_bg !== '' ) {
			$hex = sanitize_hex_color( $fbtn_bg );
			if ( $hex !== null && $hex !== '' ) {
				$fbtn_rules[] = 'background-color:' . $hex;
			}
		}

		$fbtn_color = (string) ( $attributes['fileBtnColor'] ?? '' );
		if ( $fbtn_color !== '' ) {
			$hex = sanitize_hex_color( $fbtn_color );
			if ( $hex !== null && $hex !== '' ) {
				$fbtn_rules[] = 'color:' . $hex;
			}
		}

		$fbtn_fs = (string) ( $attributes['fileBtnFontSize'] ?? '' );
		if ( $fbtn_fs !== '' ) {
			$fbtn_rules[] = 'font-size:' . $len( $fbtn_fs );
		}

		$fbtn_fw = (string) ( $attributes['fileBtnFontWeight'] ?? '' );
		if ( $fbtn_fw !== '' ) {
			$fbtn_rules[] = 'font-weight:' . sanitize_text_field( $fbtn_fw );
		}

		$fbtn_bc = (string) ( $attributes['fileBtnBorderColor'] ?? '' );
		$fbtn_bw = (string) ( $attributes['fileBtnBorderWidth'] ?? '' );
		$fbtn_bs = (string) ( $attributes['fileBtnBorderStyle'] ?? '' );
		if ( $fbtn_bc !== '' || $fbtn_bw !== '' || $fbtn_bs !== '' ) {
			$bw = $fbtn_bw !== '' ? sanitize_text_field( $fbtn_bw ) : '1px';
			$bc = $fbtn_bc !== '' ? ( sanitize_hex_color( $fbtn_bc ) ?? 'transparent' ) : 'transparent';
			$bs = in_array( $fbtn_bs, [ 'solid', 'dashed', 'dotted' ], true ) ? $fbtn_bs : 'solid';
			$fbtn_rules[] = 'border:' . $bw . ' ' . $bs . ' ' . $bc;
		}

		$fbtn_br = (string) ( $attributes['fileBtnBorderRadius'] ?? '' );
		if ( $fbtn_br !== '' ) {
			$fbtn_rules[] = 'border-radius:' . $len( $fbtn_br );
		}

		foreach ( [ 'Top' => 'padding-top', 'Right' => 'padding-right', 'Bottom' => 'padding-bottom', 'Left' => 'padding-left' ] as $side => $css_prop ) {
			$val = (string) ( $attributes[ 'fileBtnPadding' . $side ] ?? '' );
			if ( $val !== '' ) {
				$fbtn_rules[] = $css_prop . ':' . $spacing( $val );
			}
		}

		if ( $fbtn_rules ) {
			$css .= $fbtn_sel . '{' . implode( ';', $fbtn_rules ) . '}';
		}

		$fbtn_hover_bg          = (string) ( $attributes['fileBtnHoverBg']         ?? '' );
		$fbtn_hover_bg_gradient = (string) ( $attributes['fileBtnHoverBgGradient'] ?? '' );
		if ( $fbtn_hover_bg_gradient !== '' ) {
			$css .= $fbtn_hover_sel . '{background-image:' . sanitize_text_field( $fbtn_hover_bg_gradient ) . '}';
		} elseif ( $fbtn_hover_bg !== '' ) {
			$hex = sanitize_hex_color( $fbtn_hover_bg );
			if ( $hex !== null && $hex !== '' ) {
				$css .= $fbtn_hover_sel . '{background-color:' . $hex . '}';
			}
		}

		// ——— Poll options ———

		$poll_opt_sel        = $wrap_sel . ' .neural-forms-form__poll-option';
		$poll_opt_hover_sel  = $poll_opt_sel . ':hover';
		$poll_opt_check_sel  = $wrap_sel . ' .neural-forms-form__poll-option:has(.neural-forms-form__poll-input:checked)';
		$poll_opts_sel       = $wrap_sel . ' .neural-forms-form__poll-options';
		$poll_input_sel      = $wrap_sel . ' .neural-forms-form__poll-input';
		$poll_label_sel      = $wrap_sel . ' .neural-forms-form__label--poll';

		// Layout direction.
		$poll_direction = (string) ( $attributes['pollOptionsDirection'] ?? 'vertical' );
		if ( $poll_direction === 'horizontal' ) {
			$css .= $poll_opts_sel . '{flex-direction:row;flex-wrap:wrap}';
		}

		// Gap between option rows.
		$poll_gap = (string) ( $attributes['pollOptionsGap'] ?? '' );
		if ( $poll_gap !== '' ) {
			$css .= $poll_opts_sel . '{gap:' . sanitize_text_field( $poll_gap ) . '}';
		}

		// Option item rules (bg, border, radius, padding).
		$poll_opt_rules          = [];
		$poll_opt_bg             = (string) ( $attributes['pollOptionBg']         ?? '' );
		$poll_opt_bg_gradient    = (string) ( $attributes['pollOptionBgGradient'] ?? '' );
		if ( $poll_opt_bg_gradient !== '' ) {
			$poll_opt_rules[] = 'background-image:' . sanitize_text_field( $poll_opt_bg_gradient );
		} elseif ( $poll_opt_bg !== '' ) {
			$poll_opt_rules[] = 'background-color:' . sanitize_hex_color( $poll_opt_bg );
		}
		$poll_opt_border_color = (string) ( $attributes['pollOptionBorderColor'] ?? '' );
		$poll_opt_border_width = (string) ( $attributes['pollOptionBorderWidth'] ?? '' );
		$poll_opt_border_style = (string) ( $attributes['pollOptionBorderStyle'] ?? '' );
		if ( $poll_opt_border_color !== '' || $poll_opt_border_width !== '' || $poll_opt_border_style !== '' ) {
			$bw = $poll_opt_border_width !== '' ? sanitize_text_field( $poll_opt_border_width ) : '1px';
			$bc = $poll_opt_border_color !== '' ? sanitize_hex_color( $poll_opt_border_color ) : 'transparent';
			$bs = in_array( $poll_opt_border_style, [ 'solid', 'dashed', 'dotted' ], true ) ? $poll_opt_border_style : 'solid';
			$poll_opt_rules[] = 'border:' . $bw . ' ' . $bs . ' ' . $bc;
		}
		$poll_opt_radius = (string) ( $attributes['pollOptionBorderRadius'] ?? '' );
		if ( $poll_opt_radius !== '' ) {
			$poll_opt_rules[] = 'border-radius:' . sanitize_text_field( $poll_opt_radius );
		}
		foreach ( [ 'Top' => 'padding-top', 'Right' => 'padding-right', 'Bottom' => 'padding-bottom', 'Left' => 'padding-left' ] as $key => $css_prop ) {
			$val = (string) ( $attributes[ 'pollOptionPadding' . $key ] ?? '' );
			if ( $val !== '' ) {
				$poll_opt_rules[] = $css_prop . ':' . $spacing( $val );
			}
		}
		if ( $poll_opt_rules ) {
			$css .= $poll_opt_sel . '{' . implode( ';', $poll_opt_rules ) . '}';
		}

		// Option hover bg.
		$poll_opt_hover_bg          = (string) ( $attributes['pollOptionHoverBg']         ?? '' );
		$poll_opt_hover_bg_gradient = (string) ( $attributes['pollOptionHoverBgGradient'] ?? '' );
		if ( $poll_opt_hover_bg_gradient !== '' ) {
			$css .= $poll_opt_hover_sel . '{background-image:' . sanitize_text_field( $poll_opt_hover_bg_gradient ) . '}';
		} elseif ( $poll_opt_hover_bg !== '' ) {
			$css .= $poll_opt_hover_sel . '{background-color:' . sanitize_hex_color( $poll_opt_hover_bg ) . '}';
		}

		// Selected (checked) option bg.
		$poll_opt_checked_bg          = (string) ( $attributes['pollOptionCheckedBg']         ?? '' );
		$poll_opt_checked_bg_gradient = (string) ( $attributes['pollOptionCheckedBgGradient'] ?? '' );
		if ( $poll_opt_checked_bg_gradient !== '' ) {
			$css .= $poll_opt_check_sel . '{background-image:' . sanitize_text_field( $poll_opt_checked_bg_gradient ) . '}';
		} elseif ( $poll_opt_checked_bg !== '' ) {
			$css .= $poll_opt_check_sel . '{background-color:' . sanitize_hex_color( $poll_opt_checked_bg ) . '}';
		}

		// Radio accent color.
		$poll_accent = (string) ( $attributes['pollAccentColor'] ?? '' );
		if ( $poll_accent !== '' ) {
			$css .= $poll_input_sel . '{accent-color:' . sanitize_hex_color( $poll_accent ) . '}';
		}

		// Option label color + typography.
		$poll_label_rules = [];
		$poll_opt_color = (string) ( $attributes['pollOptionColor'] ?? '' );
		if ( $poll_opt_color !== '' ) {
			$poll_label_rules[] = 'color:' . sanitize_hex_color( $poll_opt_color );
		}
		$poll_opt_fs_raw = (string) ( $attributes['pollOptionFontSize'] ?? '' );
		$poll_slug_to_px = [ 'small' => '13px', 'medium' => '15px', 'large' => '18px', 'x-large' => '22px' ];
		if ( isset( $poll_slug_to_px[ $poll_opt_fs_raw ] ) ) {
			$poll_opt_fs_raw = $poll_slug_to_px[ $poll_opt_fs_raw ];
		}
		$poll_opt_fs = $len( $poll_opt_fs_raw );
		if ( $poll_opt_fs !== '' ) {
			$poll_label_rules[] = 'font-size:' . $poll_opt_fs;
		}
		$poll_opt_fw = (string) ( $attributes['pollOptionFontWeight'] ?? '' );
		if ( $poll_opt_fw !== '' ) {
			$poll_label_rules[] = 'font-weight:' . sanitize_text_field( $poll_opt_fw );
		}
		if ( $poll_label_rules ) {
			$css .= $poll_label_sel . '{' . implode( ';', $poll_label_rules ) . '}';
		}

		// ——— Results bar ———

		$poll_bar_fill_sel  = $wrap_sel . ' .neural-forms-form__poll-bar-fill';
		$poll_bar_track_sel = $wrap_sel . ' .neural-forms-form__poll-bar-track';
		$poll_bar_label_sel = $wrap_sel . ' .neural-forms-form__poll-bar-label';
		$poll_bar_count_sel = $wrap_sel . ' .neural-forms-form__poll-bar-count';
		$poll_total_sel     = $wrap_sel . ' .neural-forms-form__poll-total';
		$poll_results_sel   = $wrap_sel . ' .neural-forms-form__poll-results';

		// Bar fill: solid or two-stop gradient.
		$poll_bar_c1 = (string) ( $attributes['pollBarColor']  ?? '' );
		$poll_bar_c2 = (string) ( $attributes['pollBarColor2'] ?? '' );
		if ( $poll_bar_c1 !== '' ) {
			$c1 = sanitize_hex_color( $poll_bar_c1 );
			$c2 = $poll_bar_c2 !== '' ? sanitize_hex_color( $poll_bar_c2 ) : $c1;
			$fill_bg = ( $c2 && $c2 !== $c1 )
				? 'linear-gradient(90deg,' . $c1 . ' 0%,' . $c2 . ' 100%)'
				: $c1;
			$css .= $poll_bar_fill_sel . '{background:' . $fill_bg . '}';
		}

		// Bar track color.
		$poll_bar_track = (string) ( $attributes['pollBarTrackColor'] ?? '' );
		if ( $poll_bar_track !== '' ) {
			$css .= $poll_bar_track_sel . '{background-color:' . sanitize_hex_color( $poll_bar_track ) . '}';
		}

		// Bar height + border-radius (applied to both track and fill).
		$poll_bar_height = $len( $attributes['pollBarHeight'] ?? '' );
		$poll_bar_radius = $len( $attributes['pollBarRadius'] ?? '' );
		$poll_bar_dim_rules = [];
		if ( $poll_bar_height !== '' ) {
			$poll_bar_dim_rules[] = 'height:' . $poll_bar_height;
		}
		if ( $poll_bar_radius !== '' ) {
			$poll_bar_dim_rules[] = 'border-radius:' . $poll_bar_radius;
		}
		if ( $poll_bar_dim_rules ) {
			$dim_css = implode( ';', $poll_bar_dim_rules );
			$css .= $poll_bar_track_sel . '{' . $dim_css . '}';
			$css .= $poll_bar_fill_sel  . '{' . $dim_css . '}';
		}

		// Results area margin-top.
		// Targets the in-form results container (visible when results_visibility="always")
		// AND the dynamic results card in the success message (visible after voting for
		// all visibility modes — it is a sibling of <form>, outside form.hidden=true).
		$poll_results_mt = $len( $attributes['pollResultsMarginTop'] ?? '' );
		if ( $poll_results_mt !== '' ) {
			$css .= $poll_results_sel . '{margin-top:' . $poll_results_mt . '}';
			$css .= $wrap_sel . ' .neural-forms-form__dynamic-results{margin-top:' . $poll_results_mt . '}';
		}

		// Results bar label + count typography.
		$poll_res_fs_raw = (string) ( $attributes['pollResultsFontSize'] ?? '' );
		if ( isset( $poll_slug_to_px[ $poll_res_fs_raw ] ) ) {
			$poll_res_fs_raw = $poll_slug_to_px[ $poll_res_fs_raw ];
		}
		$poll_res_fs = $len( $poll_res_fs_raw );
		if ( $poll_res_fs !== '' ) {
			$css .= $poll_bar_label_sel . ',' . $poll_bar_count_sel . '{font-size:' . $poll_res_fs . '}';
		}

		$poll_res_label_color = (string) ( $attributes['pollResultsLabelColor'] ?? '' );
		if ( $poll_res_label_color !== '' ) {
			$css .= $poll_bar_label_sel . '{color:' . sanitize_hex_color( $poll_res_label_color ) . '}';
		}
		$poll_res_count_color = (string) ( $attributes['pollResultsCountColor'] ?? '' );
		if ( $poll_res_count_color !== '' ) {
			$css .= $poll_bar_count_sel . '{color:' . sanitize_hex_color( $poll_res_count_color ) . ';opacity:1}';
		}

		// Total votes text.
		$poll_total_rules = [];
		$poll_total_color = (string) ( $attributes['pollTotalColor'] ?? '' );
		if ( $poll_total_color !== '' ) {
			$poll_total_rules[] = 'color:' . sanitize_hex_color( $poll_total_color );
		}
		$poll_total_fs_raw = (string) ( $attributes['pollTotalFontSize'] ?? '' );
		if ( isset( $poll_slug_to_px[ $poll_total_fs_raw ] ) ) {
			$poll_total_fs_raw = $poll_slug_to_px[ $poll_total_fs_raw ];
		}
		$poll_total_fs = $len( $poll_total_fs_raw );
		if ( $poll_total_fs !== '' ) {
			$poll_total_rules[] = 'font-size:' . $poll_total_fs;
		}
		if ( $poll_total_rules ) {
			$css .= $poll_total_sel . '{' . implode( ';', $poll_total_rules ) . '}';
		}

		// ——— Quiz options ———

		$quiz_opt_sel       = $wrap_sel . ' .neural-forms-form__quiz-option';
		$quiz_opt_hover_sel = $quiz_opt_sel . ':hover';
		$quiz_opt_check_sel = $wrap_sel . ' .neural-forms-form__quiz-option:has(.neural-forms-form__quiz-input:checked)';
		$quiz_opts_sel      = $wrap_sel . ' .neural-forms-form__quiz-options';
		$quiz_input_sel     = $wrap_sel . ' .neural-forms-form__quiz-input';
		$quiz_label_sel     = $wrap_sel . ' .neural-forms-form__label--quiz';

		$quiz_direction = (string) ( $attributes['quizOptionsDirection'] ?? '' );
		if ( $quiz_direction === 'horizontal' ) {
			$css .= $quiz_opts_sel . '{flex-direction:row;flex-wrap:wrap}';
		}

		$quiz_gap = (string) ( $attributes['quizOptionsGap'] ?? '' );
		if ( $quiz_gap !== '' ) {
			$css .= $quiz_opts_sel . '{gap:' . sanitize_text_field( $quiz_gap ) . '}';
		}

		$quiz_opt_rules        = [];
		$quiz_opt_bg             = (string) ( $attributes['quizOptionBg']         ?? '' );
		$quiz_opt_bg_gradient    = (string) ( $attributes['quizOptionBgGradient'] ?? '' );
		$quiz_opt_border_color = (string) ( $attributes['quizOptionBorderColor'] ?? '' );
		$quiz_opt_border_width = (string) ( $attributes['quizOptionBorderWidth'] ?? '' );
		$quiz_opt_border_style = (string) ( $attributes['quizOptionBorderStyle'] ?? '' );

		if ( $quiz_opt_bg_gradient !== '' ) {
			$quiz_opt_rules[] = 'background-image:' . sanitize_text_field( $quiz_opt_bg_gradient );
		} elseif ( $quiz_opt_bg !== '' ) {
			$quiz_opt_rules[] = 'background-color:' . sanitize_hex_color( $quiz_opt_bg );
		}
		if ( $quiz_opt_border_color !== '' || $quiz_opt_border_width !== '' || $quiz_opt_border_style !== '' ) {
			$bw = $quiz_opt_border_width !== '' ? sanitize_text_field( $quiz_opt_border_width ) : '1px';
			$bc = $quiz_opt_border_color !== '' ? sanitize_hex_color( $quiz_opt_border_color ) : 'transparent';
			$bs = in_array( $quiz_opt_border_style, [ 'solid', 'dashed', 'dotted' ], true ) ? $quiz_opt_border_style : 'solid';
			$quiz_opt_rules[] = 'border:' . $bw . ' ' . $bs . ' ' . $bc;
		}
		$quiz_opt_radius = (string) ( $attributes['quizOptionBorderRadius'] ?? '' );
		if ( $quiz_opt_radius !== '' ) {
			$quiz_opt_rules[] = 'border-radius:' . sanitize_text_field( $quiz_opt_radius );
		}
		foreach ( [ 'Top' => 'padding-top', 'Right' => 'padding-right', 'Bottom' => 'padding-bottom', 'Left' => 'padding-left' ] as $key => $css_prop ) {
			$val = (string) ( $attributes[ 'quizOptionPadding' . $key ] ?? '' );
			if ( $val !== '' ) {
				$quiz_opt_rules[] = $css_prop . ':' . $spacing( $val );
			}
		}
		if ( $quiz_opt_rules ) {
			$css .= $quiz_opt_sel . '{' . implode( ';', $quiz_opt_rules ) . '}';
		}

		$quiz_opt_hover_bg          = (string) ( $attributes['quizOptionHoverBg']         ?? '' );
		$quiz_opt_hover_bg_gradient = (string) ( $attributes['quizOptionHoverBgGradient'] ?? '' );
		if ( $quiz_opt_hover_bg_gradient !== '' ) {
			$css .= $quiz_opt_hover_sel . '{background-image:' . sanitize_text_field( $quiz_opt_hover_bg_gradient ) . '}';
		} elseif ( $quiz_opt_hover_bg !== '' ) {
			$css .= $quiz_opt_hover_sel . '{background-color:' . sanitize_hex_color( $quiz_opt_hover_bg ) . '}';
		}

		$quiz_opt_checked_bg          = (string) ( $attributes['quizOptionCheckedBg']         ?? '' );
		$quiz_opt_checked_bg_gradient = (string) ( $attributes['quizOptionCheckedBgGradient'] ?? '' );
		if ( $quiz_opt_checked_bg_gradient !== '' ) {
			$css .= $quiz_opt_check_sel . '{background-image:' . sanitize_text_field( $quiz_opt_checked_bg_gradient ) . '}';
		} elseif ( $quiz_opt_checked_bg !== '' ) {
			$css .= $quiz_opt_check_sel . '{background-color:' . sanitize_hex_color( $quiz_opt_checked_bg ) . '}';
		}

		$quiz_accent = (string) ( $attributes['quizAccentColor'] ?? '' );
		if ( $quiz_accent !== '' ) {
			$css .= $quiz_input_sel . '{accent-color:' . sanitize_hex_color( $quiz_accent ) . '}';
		}

		$quiz_label_rules = [];
		$quiz_opt_color   = (string) ( $attributes['quizOptionColor'] ?? '' );
		if ( $quiz_opt_color !== '' ) {
			$quiz_label_rules[] = 'color:' . sanitize_hex_color( $quiz_opt_color );
		}
		$quiz_opt_fs_raw   = (string) ( $attributes['quizOptionFontSize'] ?? '' );
		$quiz_slug_to_px   = [ 'small' => '13px', 'medium' => '15px', 'large' => '18px', 'x-large' => '22px' ];
		if ( isset( $quiz_slug_to_px[ $quiz_opt_fs_raw ] ) ) {
			$quiz_opt_fs_raw = $quiz_slug_to_px[ $quiz_opt_fs_raw ];
		}
		$quiz_opt_fs = $len( $quiz_opt_fs_raw );
		if ( $quiz_opt_fs !== '' ) {
			$quiz_label_rules[] = 'font-size:' . $quiz_opt_fs;
		}
		$quiz_opt_fw = (string) ( $attributes['quizOptionFontWeight'] ?? '' );
		if ( $quiz_opt_fw !== '' ) {
			$quiz_label_rules[] = 'font-weight:' . sanitize_text_field( $quiz_opt_fw );
		}
		if ( $quiz_label_rules ) {
			$css .= $quiz_label_sel . '{' . implode( ';', $quiz_label_rules ) . '}';
		}

		// ——— Quiz score summary ———

		$quiz_summary_sel       = $wrap_sel . ' .neural-forms-form__quiz-summary';
		$quiz_summary_score_sel = $wrap_sel . ' .neural-forms-form__quiz-summary-line strong';
		$quiz_summary_rules     = [];

		$quiz_summary_bg = (string) ( $attributes['quizSummaryBg'] ?? '' );
		if ( $quiz_summary_bg !== '' ) {
			$hex = sanitize_hex_color( $quiz_summary_bg );
			if ( $hex !== null && $hex !== '' ) {
				$quiz_summary_rules[] = 'background-color:' . $hex;
			}
		}

		// Priority: image > gradient > color. Higher priority wins exclusively.
		$quiz_summary_gradient = (string) ( $attributes['quizSummaryGradient'] ?? '' );
		$quiz_summary_bg_image = (string) ( $attributes['quizSummaryBgImage'] ?? '' );

		if ( $quiz_summary_bg_image !== '' ) {
			// Image is highest priority — show image only, no gradient overlay.
			$quiz_summary_rules[] = 'background-image:url(' . esc_url( $quiz_summary_bg_image ) . ')';
		} elseif ( $quiz_summary_gradient !== '' ) {
			// Gradient is second priority — show gradient only.
			$quiz_summary_rules[] = 'background-image:' . sanitize_text_field( $quiz_summary_gradient );
		} elseif ( $quiz_summary_bg !== '' ) {
			// Color only — clear the default gradient from form.css.
			$quiz_summary_rules[] = 'background-image:none';
		}

		if ( $quiz_summary_bg_image !== '' ) {
			$bg_size = (string) ( $attributes['quizSummaryBgSize'] ?? '' );
			if ( in_array( $bg_size, [ 'cover', 'contain', 'initial' ], true ) ) {
				$quiz_summary_rules[] = 'background-size:' . $bg_size;
			} elseif ( $bg_size === 'stretch' ) {
				$quiz_summary_rules[] = 'background-size:100% 100%';
			}
			$bg_pos = (string) ( $attributes['quizSummaryBgPosition'] ?? '' );
			if ( in_array( $bg_pos, [ 'top_left', 'top_center', 'top_right', 'center_left', 'center', 'center_right', 'bottom_left', 'bottom_center', 'bottom_right' ], true ) ) {
				$quiz_summary_rules[] = 'background-position:' . str_replace( '_', ' ', $bg_pos );
			}
			$bg_repeat = (string) ( $attributes['quizSummaryBgRepeat'] ?? '' );
			if ( in_array( $bg_repeat, [ 'repeat', 'repeat-x', 'repeat-y', 'space', 'round', 'no-repeat' ], true ) ) {
				$quiz_summary_rules[] = 'background-repeat:' . $bg_repeat;
			}
			$bg_blend = (string) ( $attributes['quizSummaryBgBlend'] ?? '' );
			if ( in_array( $bg_blend, [ 'normal', 'multiply', 'screen', 'overlay', 'darken', 'lighten' ], true ) ) {
				$quiz_summary_rules[] = 'background-blend-mode:' . $bg_blend;
			}
		}

		$quiz_summary_color = (string) ( $attributes['quizSummaryColor'] ?? '' );
		if ( $quiz_summary_color !== '' ) {
			$hex = sanitize_hex_color( $quiz_summary_color );
			if ( $hex !== null && $hex !== '' ) {
				$quiz_summary_rules[] = 'color:' . $hex;
			}
		}

		$quiz_summary_bc = (string) ( $attributes['quizSummaryBorderColor'] ?? '' );
		$quiz_summary_bw = (string) ( $attributes['quizSummaryBorderWidth'] ?? '' );
		if ( $quiz_summary_bc !== '' || $quiz_summary_bw !== '' ) {
			$bw = $quiz_summary_bw !== '' ? sanitize_text_field( $quiz_summary_bw ) : '1px';
			$bc = $quiz_summary_bc !== '' ? sanitize_hex_color( $quiz_summary_bc ) : 'currentColor';
			$quiz_summary_rules[] = 'border:' . $bw . ' solid ' . $bc;
		}

		foreach ( [ 'TL' => 'border-top-left-radius', 'TR' => 'border-top-right-radius', 'BR' => 'border-bottom-right-radius', 'BL' => 'border-bottom-left-radius' ] as $corner => $css_prop ) {
			$val = (string) ( $attributes[ 'quizSummaryBorderRadius' . $corner ] ?? '' );
			if ( $val !== '' ) {
				$quiz_summary_rules[] = $css_prop . ':' . $spacing( $val );
			}
		}

		foreach ( [ 'Top' => 'padding-top', 'Right' => 'padding-right', 'Bottom' => 'padding-bottom', 'Left' => 'padding-left' ] as $key => $css_prop ) {
			$val = (string) ( $attributes[ 'quizSummaryPadding' . $key ] ?? '' );
			if ( $val !== '' ) {
				$quiz_summary_rules[] = $css_prop . ':' . $spacing( $val );
			}
		}
		if ( $quiz_summary_rules ) {
			$css .= $quiz_summary_sel . '{' . implode( ';', $quiz_summary_rules ) . '}';
		}

		$quiz_score_fs_raw = (string) ( $attributes['quizScoreFontSize'] ?? '' );
		if ( isset( $quiz_slug_to_px[ $quiz_score_fs_raw ] ) ) {
			$quiz_score_fs_raw = $quiz_slug_to_px[ $quiz_score_fs_raw ];
		}
		$quiz_score_fs = $len( $quiz_score_fs_raw );
		if ( $quiz_score_fs !== '' ) {
			$css .= $quiz_summary_score_sel . '{font-size:' . $quiz_score_fs . '}';
		}

		// ——— Multi-step: prev & next buttons (shared navBtn* attributes) ———

		$nav_btn_sel       = $wrap_sel . ' .neural-forms-form__step-prev,' . $wrap_sel . ' .neural-forms-form__step-next';
		$nav_btn_hover_sel = $wrap_sel . ' .neural-forms-form__step-prev:hover:not(:disabled),' . $wrap_sel . ' .neural-forms-form__step-next:hover:not(:disabled)';
		$nav_btn_rules     = [];
		$nav_btn_hover_rules = [];

		$nav_border_color = (string) ( $attributes['navBtnBorderColor'] ?? '' );
		$nav_border_width = (string) ( $attributes['navBtnBorderWidth'] ?? '' );
		$nav_border_style = (string) ( $attributes['navBtnBorderStyle'] ?? '' );
		if ( $nav_border_color !== '' || $nav_border_width !== '' || $nav_border_style !== '' ) {
			$bw = $nav_border_width !== '' ? sanitize_text_field( $nav_border_width ) : '1px';
			$bc = $nav_border_color !== '' ? sanitize_hex_color( $nav_border_color ) : 'transparent';
			$bs = in_array( $nav_border_style, [ 'solid', 'dashed', 'dotted' ], true ) ? $nav_border_style : 'solid';
			$nav_btn_rules[] = 'border:' . $bw . ' ' . $bs . ' ' . $bc;
		}

		foreach ( [ 'TL' => 'border-top-left-radius', 'TR' => 'border-top-right-radius', 'BR' => 'border-bottom-right-radius', 'BL' => 'border-bottom-left-radius' ] as $corner => $css_prop ) {
			$val = (string) ( $attributes[ 'navBtnRadius' . $corner ] ?? '' );
			if ( $val !== '' ) {
				$nav_btn_rules[] = $css_prop . ':' . $spacing( $val );
			}
		}

		$nav_font_family = (string) ( $attributes['navBtnFontFamily'] ?? '' );
		if ( $nav_font_family !== '' ) {
			$nav_btn_rules[] = 'font-family:' . sanitize_text_field( $nav_font_family );
		}

		$nav_fs = $len( (string) ( $attributes['navBtnFontSize'] ?? '' ) );
		if ( $nav_fs !== '' ) {
			$nav_btn_rules[] = 'font-size:' . $nav_fs;
		}

		$nav_trans_dur  = (string) ( $attributes['navBtnTransitionDuration'] ?? '' );
		$nav_trans_ease = (string) ( $attributes['navBtnTransitionEasing'] ?? '' );
		if ( $nav_trans_dur !== '' || $nav_trans_ease !== '' ) {
			$dur  = $nav_trans_dur !== '' ? sanitize_text_field( $nav_trans_dur ) : '0.15s';
			$ease = $nav_trans_ease !== '' ? sanitize_text_field( $nav_trans_ease ) : 'ease';
			$nav_btn_rules[] = 'transition:background-color ' . $dur . ' ' . $ease . ',color ' . $dur . ' ' . $ease . ',border-color ' . $dur . ' ' . $ease . ',filter ' . $dur . ' ' . $ease;
		}

		if ( ! empty( $attributes['navBtnFullWidth'] ) ) {
			$nav_btn_rules[] = 'width:100%';
		}

		$nav_fw = (string) ( $attributes['navBtnFontWeight'] ?? '' );
		if ( $nav_fw !== '' ) {
			$nav_btn_rules[] = 'font-weight:' . sanitize_text_field( $nav_fw );
		}
		$nav_fstyle = (string) ( $attributes['navBtnFontStyle'] ?? '' );
		if ( $nav_fstyle === 'italic' ) {
			$nav_btn_rules[] = 'font-style:italic';
		}
		$nav_ls = (string) ( $attributes['navBtnLetterSpacing'] ?? '' );
		if ( $nav_ls !== '' ) {
			if ( is_numeric( $nav_ls ) ) { $nav_ls .= 'px'; }
			$nav_btn_rules[] = 'letter-spacing:' . sanitize_text_field( $nav_ls );
		}
		$nav_td = (string) ( $attributes['navBtnTextDecoration'] ?? '' );
		if ( $nav_td !== '' && in_array( $nav_td, [ 'none', 'underline', 'overline', 'line-through' ], true ) ) {
			$nav_btn_rules[] = 'text-decoration:' . $nav_td;
		}
		$nav_tt = (string) ( $attributes['navBtnTextTransform'] ?? '' );
		if ( $nav_tt !== '' && in_array( $nav_tt, [ 'none', 'uppercase', 'lowercase', 'capitalize' ], true ) ) {
			$nav_btn_rules[] = 'text-transform:' . $nav_tt;
		}
		$nav_lh = (string) ( $attributes['navBtnLineHeight'] ?? '' );
		if ( $nav_lh !== '' ) {
			$nav_btn_rules[] = 'line-height:' . sanitize_text_field( $nav_lh );
		}

		foreach ( [ 'Top' => 'padding-top', 'Right' => 'padding-right', 'Bottom' => 'padding-bottom', 'Left' => 'padding-left' ] as $key => $css_prop ) {
			$val = (string) ( $attributes[ 'navBtnPadding' . $key ] ?? '' );
			if ( $val !== '' ) {
				$nav_btn_rules[] = $css_prop . ':' . $spacing( $val );
			}
		}

		$nav_bg_color   = (string) ( $attributes['navBtnBgColor']   ?? '' );
		$nav_text_color = (string) ( $attributes['navBtnTextColor']  ?? '' );
		if ( $nav_bg_color !== '' ) {
			$hex = sanitize_hex_color( $nav_bg_color );
			if ( $hex !== null && $hex !== '' ) { $nav_btn_rules[] = 'background-color:' . $hex; }
		}
		if ( $nav_text_color !== '' ) {
			$hex = sanitize_hex_color( $nav_text_color );
			if ( $hex !== null && $hex !== '' ) { $nav_btn_rules[] = 'color:' . $hex; }
		}

		$nav_hover_bg     = (string) ( $attributes['navBtnHoverBg']          ?? '' );
		$nav_hover_color  = (string) ( $attributes['navBtnHoverColor']       ?? '' );
		$nav_hover_border = (string) ( $attributes['navBtnHoverBorderColor'] ?? '' );
		if ( $nav_hover_bg !== '' ) {
			$hex = sanitize_hex_color( $nav_hover_bg );
			if ( $hex !== null && $hex !== '' ) { $nav_btn_hover_rules[] = 'background-color:' . $hex; }
		}
		if ( $nav_hover_color !== '' ) {
			$hex = sanitize_hex_color( $nav_hover_color );
			if ( $hex !== null && $hex !== '' ) { $nav_btn_hover_rules[] = 'color:' . $hex; }
		}
		if ( $nav_hover_border !== '' ) {
			$hex = sanitize_hex_color( $nav_hover_border );
			if ( $hex !== null && $hex !== '' ) { $nav_btn_hover_rules[] = 'border-color:' . $hex; }
		}
		if ( $nav_btn_hover_rules ) {
			$nav_btn_hover_rules[] = 'filter:none';
		}

		if ( $nav_btn_rules ) {
			$css .= $nav_btn_sel . '{' . implode( ';', $nav_btn_rules ) . '}';
		}
		if ( $nav_btn_hover_rules ) {
			$css .= $nav_btn_hover_sel . '{' . implode( ';', $nav_btn_hover_rules ) . '}';
		}

		// ——— Multi-step: step title + separator ———

		$step_title_sel = $wrap_sel . ' .neural-forms-form__step-title';
		$step_sep_sel   = $wrap_sel . ' .neural-forms-form__step:not(:first-of-type)';

		$step_title_color  = sanitize_hex_color( (string) ( $attributes['stepTitleColor']      ?? '' ) );
		$step_title_fs     = $len( (string) ( $attributes['stepTitleFontSize']    ?? '' ) );
		$step_title_fw_raw = (string) ( $attributes['stepTitleFontWeight'] ?? '' );
		$allowed_fw_st     = [ '100','200','300','400','500','600','700','800','900','bold','normal' ];
		$step_title_fw     = in_array( $step_title_fw_raw, $allowed_fw_st, true ) ? $step_title_fw_raw : '';

		$step_title_rules = [];
		if ( $step_title_color !== null && $step_title_color !== '' ) {
			$step_title_rules[] = 'color:' . $step_title_color;
		}
		if ( $step_title_fs !== '' ) {
			$step_title_rules[] = 'font-size:' . $step_title_fs;
		}
		if ( $step_title_fw !== '' ) {
			$step_title_rules[] = 'font-weight:' . $step_title_fw;
		}
		if ( $step_title_rules ) {
			$css .= $step_title_sel . '{' . implode( ';', $step_title_rules ) . '}';
		}

		$step_desc_sel    = $wrap_sel . ' .neural-forms-form__step-description';
		$step_desc_color  = sanitize_hex_color( (string) ( $attributes['stepDescColor']     ?? '' ) );
		$step_desc_fs     = $len( (string) ( $attributes['stepDescFontSize']   ?? '' ) );
		$step_desc_fw_raw = (string) ( $attributes['stepDescFontWeight'] ?? '' );
		$step_desc_fw     = in_array( $step_desc_fw_raw, $allowed_fw_st, true ) ? $step_desc_fw_raw : '';

		$step_desc_rules = [];
		if ( $step_desc_color !== null && $step_desc_color !== '' ) {
			$step_desc_rules[] = 'color:' . $step_desc_color;
		}
		if ( $step_desc_fs !== '' ) {
			$step_desc_rules[] = 'font-size:' . $step_desc_fs;
		}
		if ( $step_desc_fw !== '' ) {
			$step_desc_rules[] = 'font-weight:' . $step_desc_fw;
		}
		if ( $step_desc_rules ) {
			$css .= $step_desc_sel . '{' . implode( ';', $step_desc_rules ) . '}';
		}

		$step_sep_color = sanitize_hex_color( (string) ( $attributes['stepSepColor'] ?? '' ) );
		$step_sep_width = $len( (string) ( $attributes['stepSepWidth'] ?? '' ) );
		$step_sep_style_raw = (string) ( $attributes['stepSepStyle'] ?? '' );
		$allowed_bs     = [ 'solid', 'dashed', 'dotted', 'double' ];
		$step_sep_style = in_array( $step_sep_style_raw, $allowed_bs, true ) ? $step_sep_style_raw : '';

		$step_sep_rules = [];
		if ( $step_sep_color !== null && $step_sep_color !== '' ) {
			$step_sep_rules[] = 'border-top-color:' . $step_sep_color;
		}
		if ( $step_sep_width !== '' ) {
			$step_sep_rules[] = 'border-top-width:' . $step_sep_width;
		}
		if ( $step_sep_style !== '' ) {
			$step_sep_rules[] = 'border-top-style:' . $step_sep_style;
		}
		if ( $step_sep_rules ) {
			$css .= $step_sep_sel . '{' . implode( ';', $step_sep_rules ) . '}';
		}

		// ——— Multi-step: step indicator — progress bar ———

		$bar_track_sel = $wrap_sel . ' .neural-forms-form__progress-bar-track';
		$bar_fill_sel  = $wrap_sel . ' .neural-forms-form__progress-bar-fill';

		$step_bar_fill  = sanitize_hex_color( (string) ( $attributes['stepBarFillColor']  ?? '' ) );
		$step_bar_track = sanitize_hex_color( (string) ( $attributes['stepBarTrackColor'] ?? '' ) );
		$step_bar_h     = $len( (string) ( $attributes['stepBarHeight'] ?? '' ) );
		$step_bar_r     = $len( (string) ( $attributes['stepBarRadius'] ?? '' ) );

		if ( $step_bar_fill !== null && $step_bar_fill !== '' ) {
			$css .= $bar_fill_sel . '{background:' . $step_bar_fill . '}';
		}
		if ( $step_bar_track !== null && $step_bar_track !== '' ) {
			$css .= $bar_track_sel . '{background:' . $step_bar_track . '}';
		}
		if ( $step_bar_h !== '' ) {
			$css .= $bar_track_sel . '{height:' . $step_bar_h . '}';
			$css .= $bar_fill_sel  . '{height:' . $step_bar_h . '}';
		}
		if ( $step_bar_r !== '' ) {
			$css .= $bar_track_sel . '{border-radius:' . $step_bar_r . '}';
			$css .= $bar_fill_sel  . '{border-radius:' . $step_bar_r . '}';
		}

		// Progress text ("Step X of Y").
		$bar_text_sel   = $wrap_sel . ' .neural-forms-form__progress-text';
		$bar_text_color = sanitize_hex_color( (string) ( $attributes['stepBarTextColor']      ?? '' ) );
		$bar_text_fs    = $len( (string) ( $attributes['stepBarTextFontSize']   ?? '' ) );
		$bar_text_fw_r  = (string) ( $attributes['stepBarTextFontWeight'] ?? '' );
		$allowed_fw_bar = [ '100','200','300','400','500','600','700','800','900','bold','normal' ];
		$bar_text_fw    = in_array( $bar_text_fw_r, $allowed_fw_bar, true ) ? $bar_text_fw_r : '';

		$bar_text_rules = [];
		if ( $bar_text_color !== null && $bar_text_color !== '' ) {
			$bar_text_rules[] = 'color:' . $bar_text_color;
		}
		if ( $bar_text_fs !== '' ) {
			$bar_text_rules[] = 'font-size:' . $bar_text_fs;
		}
		if ( $bar_text_fw !== '' ) {
			$bar_text_rules[] = 'font-weight:' . $bar_text_fw;
		}
		if ( $bar_text_rules ) {
			$css .= $bar_text_sel . '{' . implode( ';', $bar_text_rules ) . '}';
		}

		// ——— Multi-step: step indicator — step numbers ———

		$step_num_sel            = $wrap_sel . ' .neural-forms-form__progress-num';
		$step_active_item_sel    = $wrap_sel . ' .neural-forms-form__progress-item.is-active .neural-forms-form__progress-num';
		$step_complete_item_sel  = $wrap_sel . ' .neural-forms-form__progress-item.is-complete .neural-forms-form__progress-num';

		$step_inactive_bg    = sanitize_hex_color( (string) ( $attributes['stepInactiveNumBg']    ?? '' ) );
		$step_inactive_color = sanitize_hex_color( (string) ( $attributes['stepInactiveNumColor'] ?? '' ) );
		$step_active_bg      = sanitize_hex_color( (string) ( $attributes['stepActiveNumBg']      ?? '' ) );
		$step_active_color   = sanitize_hex_color( (string) ( $attributes['stepActiveNumColor']   ?? '' ) );
		$step_complete_bg    = sanitize_hex_color( (string) ( $attributes['stepCompleteNumBg']    ?? '' ) );
		$step_complete_color = sanitize_hex_color( (string) ( $attributes['stepCompleteNumColor'] ?? '' ) );
		$step_num_size       = $len( (string) ( $attributes['stepNumSize']     ?? '' ) );
		$step_num_fs         = $len( (string) ( $attributes['stepNumFontSize'] ?? '' ) );

		$step_num_base_rules = [];
		if ( $step_inactive_bg !== null && $step_inactive_bg !== '' ) {
			$step_num_base_rules[] = 'background:' . $step_inactive_bg;
		}
		if ( $step_inactive_color !== null && $step_inactive_color !== '' ) {
			$step_num_base_rules[] = 'color:' . $step_inactive_color;
		}
		if ( $step_num_size !== '' ) {
			$step_num_base_rules[] = 'min-width:' . $step_num_size;
			$step_num_base_rules[] = 'height:' . $step_num_size;
		}
		if ( $step_num_fs !== '' ) {
			$step_num_base_rules[] = 'font-size:' . $step_num_fs;
		}
		if ( $step_num_base_rules ) {
			$css .= $step_num_sel . '{' . implode( ';', $step_num_base_rules ) . '}';
		}

		$step_active_rules = [];
		if ( $step_active_bg !== null && $step_active_bg !== '' ) {
			$step_active_rules[] = 'background:' . $step_active_bg;
		}
		if ( $step_active_color !== null && $step_active_color !== '' ) {
			$step_active_rules[] = 'color:' . $step_active_color;
		}
		if ( $step_active_rules ) {
			$css .= $step_active_item_sel . '{' . implode( ';', $step_active_rules ) . '}';
		}

		$step_complete_rules = [];
		if ( $step_complete_bg !== null && $step_complete_bg !== '' ) {
			$step_complete_rules[] = 'background:' . $step_complete_bg;
		}
		if ( $step_complete_color !== null && $step_complete_color !== '' ) {
			$step_complete_rules[] = 'color:' . $step_complete_color;
		}
		if ( $step_complete_rules ) {
			$css .= $step_complete_item_sel . '{' . implode( ';', $step_complete_rules ) . '}';
		}

		$step_connector_color = sanitize_hex_color( (string) ( $attributes['stepConnectorColor'] ?? '' ) );
		if ( $step_connector_color !== null && $step_connector_color !== '' ) {
			$connector_sel = $wrap_sel . ' .neural-forms-form__progress--numbers .neural-forms-form__progress-item:not(:last-child)::after';
			$css .= $connector_sel . '{content:\'\';flex-shrink:0;display:inline-block;width:16px;height:2px;background:' . $step_connector_color . ';align-self:center;margin-left:6px}';
		}

		// Step label (.progress-label) — color per state + shared typography.
		$step_label_sel          = $wrap_sel . ' .neural-forms-form__progress-label';
		$step_active_label_sel   = $wrap_sel . ' .neural-forms-form__progress-item.is-active .neural-forms-form__progress-label';
		$step_complete_label_sel = $wrap_sel . ' .neural-forms-form__progress-item.is-complete .neural-forms-form__progress-label';

		$step_label_color    = sanitize_hex_color( (string) ( $attributes['stepLabelColor']         ?? '' ) );
		$step_active_lc      = sanitize_hex_color( (string) ( $attributes['stepActiveLabelColor']   ?? '' ) );
		$step_complete_lc    = sanitize_hex_color( (string) ( $attributes['stepCompleteLabelColor'] ?? '' ) );
		$step_label_fs       = $len( (string) ( $attributes['stepLabelFontSize']   ?? '' ) );
		$step_label_fw_raw   = (string) ( $attributes['stepLabelFontWeight'] ?? '' );
		$allowed_fw          = [ '100','200','300','400','500','600','700','800','900','bold','normal' ];
		$step_label_fw       = in_array( $step_label_fw_raw, $allowed_fw, true ) ? $step_label_fw_raw : '';

		$step_label_base = [];
		if ( $step_label_color !== null && $step_label_color !== '' ) {
			$step_label_base[] = 'color:' . $step_label_color;
		}
		if ( $step_label_fs !== '' ) {
			$step_label_base[] = 'font-size:' . $step_label_fs;
		}
		if ( $step_label_fw !== '' ) {
			$step_label_base[] = 'font-weight:' . $step_label_fw;
		}
		if ( $step_label_base ) {
			$css .= $step_label_sel . '{' . implode( ';', $step_label_base ) . '}';
		}
		if ( $step_active_lc !== null && $step_active_lc !== '' ) {
			$css .= $step_active_label_sel . '{color:' . $step_active_lc . '}';
		}
		if ( $step_complete_lc !== null && $step_complete_lc !== '' ) {
			$css .= $step_complete_label_sel . '{color:' . $step_complete_lc . '}';
		}

		// ── Repeater field ────────────────────────────────────────────────────
		// Container (.neural-forms-repeater fieldset)
		$rep_cont_sel    = $wrap_sel . ' .neural-forms-repeater';
		$rep_cont_rules  = [];
		$rep_cont_bg_raw  = (string) ( $attributes['repeaterContainerBg']          ?? '' );
		$rep_cont_bg_grad = (string) ( $attributes['repeaterContainerBgGradient']  ?? '' );
		$rep_cont_bg      = sanitize_hex_color( $rep_cont_bg_raw );
		$rep_cont_bc     = sanitize_hex_color( (string) ( $attributes['repeaterContainerBorderColor'] ?? '' ) );
		$rep_cont_bw     = (string) ( $attributes['repeaterContainerBorderWidth']  ?? '' );
		$rep_cont_bs     = (string) ( $attributes['repeaterContainerBorderStyle']  ?? '' );
		$rep_cont_radius = (string) ( $attributes['repeaterContainerBorderRadius'] ?? '' );
		$rep_cont_pt     = (string) ( $attributes['repeaterContainerPaddingTop']    ?? '' );
		$rep_cont_pr     = (string) ( $attributes['repeaterContainerPaddingRight']  ?? '' );
		$rep_cont_pb     = (string) ( $attributes['repeaterContainerPaddingBottom'] ?? '' );
		$rep_cont_pl     = (string) ( $attributes['repeaterContainerPaddingLeft']   ?? '' );
		if ( $rep_cont_bg_grad !== '' ) {
			$rep_cont_rules[] = 'background-image:' . sanitize_text_field( $rep_cont_bg_grad );
		} elseif ( $rep_cont_bg ) {
			$rep_cont_rules[] = 'background:' . $rep_cont_bg;
		}
		if ( $rep_cont_bc && $rep_cont_bw && $rep_cont_bs ) {
			$rep_cont_rules[] = 'border:' . $len( $rep_cont_bw ) . ' ' . esc_attr( $rep_cont_bs ) . ' ' . $rep_cont_bc;
		} elseif ( $rep_cont_bc ) {
			$rep_cont_rules[] = 'border-color:' . $rep_cont_bc;
		}
		if ( $rep_cont_radius ) $rep_cont_rules[] = 'border-radius:' . $len( $rep_cont_radius );
		if ( $rep_cont_pt || $rep_cont_pr || $rep_cont_pb || $rep_cont_pl ) {
			$rep_cont_rules[] = 'padding:' . $spacing( $rep_cont_pt ?: '0' ) . ' ' . $spacing( $rep_cont_pr ?: '0' ) . ' ' . $spacing( $rep_cont_pb ?: '0' ) . ' ' . $spacing( $rep_cont_pl ?: '0' );
		}
		if ( $rep_cont_rules ) $css .= $rep_cont_sel . '{' . implode( ';', $rep_cont_rules ) . '}';

		$rep_list_add_gap = (string) ( $attributes['repeaterListAddGap'] ?? '' );
		if ( $rep_list_add_gap !== '' ) {
			$css .= $wrap_sel . ' .neural-forms-repeater__add{margin-top:' . $len( $rep_list_add_gap ) . '}';
		}

		$rep_row_sel       = $wrap_sel . ' .neural-forms-repeater__item';
		$rep_row_rules     = [];
		$rep_row_bg_raw    = (string) ( $attributes['repeaterRowBg']          ?? '' );
		$rep_row_bg_grad   = (string) ( $attributes['repeaterRowBgGradient']  ?? '' );
		$rep_row_bg        = sanitize_hex_color( $rep_row_bg_raw );
		$rep_row_border_c  = sanitize_hex_color( (string) ( $attributes['repeaterRowBorderColor'] ?? '' ) );
		$rep_row_border_w  = (string) ( $attributes['repeaterRowBorderWidth']  ?? '' );
		$rep_row_border_s  = (string) ( $attributes['repeaterRowBorderStyle']  ?? '' );
		$rep_row_radius    = (string) ( $attributes['repeaterRowBorderRadius'] ?? '' );
		$rep_row_pt        = (string) ( $attributes['repeaterRowPaddingTop']    ?? '' );
		$rep_row_pr        = (string) ( $attributes['repeaterRowPaddingRight']  ?? '' );
		$rep_row_pb        = (string) ( $attributes['repeaterRowPaddingBottom'] ?? '' );
		$rep_row_pl        = (string) ( $attributes['repeaterRowPaddingLeft']   ?? '' );
		if ( $rep_row_bg_grad !== '' ) {
			$rep_row_rules[] = 'background-image:' . sanitize_text_field( $rep_row_bg_grad );
		} elseif ( $rep_row_bg ) {
			$rep_row_rules[] = 'background:' . $rep_row_bg;
		}
		if ( $rep_row_border_c && $rep_row_border_w && $rep_row_border_s ) {
			$rep_row_rules[] = 'border:' . $len( $rep_row_border_w ) . ' ' . esc_attr( $rep_row_border_s ) . ' ' . $rep_row_border_c;
		} elseif ( $rep_row_border_c ) {
			$rep_row_rules[] = 'border-color:' . $rep_row_border_c;
		}
		if ( $rep_row_radius )   $rep_row_rules[] = 'border-radius:' . $len( $rep_row_radius );
		if ( $rep_row_pt || $rep_row_pr || $rep_row_pb || $rep_row_pl ) {
			$rep_row_rules[] = 'padding:' . $spacing( $rep_row_pt ?: '0' ) . ' ' . $spacing( $rep_row_pr ?: '0' ) . ' ' . $spacing( $rep_row_pb ?: '0' ) . ' ' . $spacing( $rep_row_pl ?: '0' );
		}
		if ( $rep_row_rules ) $css .= $rep_row_sel . '{' . implode( ';', $rep_row_rules ) . '}';

		$rep_row_hover_bg_raw  = (string) ( $attributes['repeaterRowHoverBg']         ?? '' );
		$rep_row_hover_bg_grad = (string) ( $attributes['repeaterRowHoverBgGradient'] ?? '' );
		$rep_row_hover_bg      = sanitize_hex_color( $rep_row_hover_bg_raw );
		if ( $rep_row_hover_bg_grad !== '' ) {
			$css .= $rep_row_sel . ':hover{background-image:' . sanitize_text_field( $rep_row_hover_bg_grad ) . '}';
		} elseif ( $rep_row_hover_bg ) {
			$css .= $rep_row_sel . ':hover{background:' . $rep_row_hover_bg . '}';
		}

		$rep_row_gap = (string) ( $attributes['repeaterRowGap'] ?? '' );
		if ( $rep_row_gap !== '' ) {
			$css .= $wrap_sel . ' .neural-forms-repeater__list{gap:' . $len( $rep_row_gap ) . '}';
		}

		// Add Row button
		$rep_add_sel      = $wrap_sel . ' .neural-forms-repeater__add';
		$rep_add_rules    = [];
		$rep_add_bg_raw   = (string) ( $attributes['repeaterAddBtnBg']           ?? '' );
		$rep_add_bg_grad  = (string) ( $attributes['repeaterAddBtnBgGradient']  ?? '' );
		$rep_add_bg       = sanitize_hex_color( $rep_add_bg_raw );
		$rep_add_color    = sanitize_hex_color( (string) ( $attributes['repeaterAddBtnColor']        ?? '' ) );
		$rep_add_fs       = (string) ( $attributes['repeaterAddBtnFontSize']    ?? '' );
		$rep_add_fw       = (string) ( $attributes['repeaterAddBtnFontWeight']  ?? '' );
		$rep_add_bc       = sanitize_hex_color( (string) ( $attributes['repeaterAddBtnBorderColor']  ?? '' ) );
		$rep_add_bw       = (string) ( $attributes['repeaterAddBtnBorderWidth'] ?? '' );
		$rep_add_bs       = (string) ( $attributes['repeaterAddBtnBorderStyle'] ?? '' );
		$rep_add_radius   = (string) ( $attributes['repeaterAddBtnBorderRadius'] ?? '' );
		$rep_add_pt       = (string) ( $attributes['repeaterAddBtnPaddingTop']    ?? '' );
		$rep_add_pr       = (string) ( $attributes['repeaterAddBtnPaddingRight']  ?? '' );
		$rep_add_pb       = (string) ( $attributes['repeaterAddBtnPaddingBottom'] ?? '' );
		$rep_add_pl       = (string) ( $attributes['repeaterAddBtnPaddingLeft']   ?? '' );
		if ( $rep_add_bg_grad !== '' ) {
			$rep_add_rules[] = 'background-image:' . sanitize_text_field( $rep_add_bg_grad );
		} elseif ( $rep_add_bg ) {
			$rep_add_rules[] = 'background:' . $rep_add_bg;
		}
		if ( $rep_add_color )  $rep_add_rules[] = 'color:' . $rep_add_color;
		if ( $rep_add_fs )     $rep_add_rules[] = 'font-size:' . $len( $rep_add_fs );
		if ( $rep_add_fw )     $rep_add_rules[] = 'font-weight:' . esc_attr( $rep_add_fw );
		if ( $rep_add_bc && $rep_add_bw && $rep_add_bs ) {
			$rep_add_rules[] = 'border:' . $len( $rep_add_bw ) . ' ' . esc_attr( $rep_add_bs ) . ' ' . $rep_add_bc;
		} elseif ( $rep_add_bc ) {
			$rep_add_rules[] = 'border-color:' . $rep_add_bc;
		}
		if ( $rep_add_radius ) $rep_add_rules[] = 'border-radius:' . $len( $rep_add_radius );
		if ( $rep_add_pt || $rep_add_pr || $rep_add_pb || $rep_add_pl ) {
			$rep_add_rules[] = 'padding:' . $spacing( $rep_add_pt ?: '0' ) . ' ' . $spacing( $rep_add_pr ?: '0' ) . ' ' . $spacing( $rep_add_pb ?: '0' ) . ' ' . $spacing( $rep_add_pl ?: '0' );
		}
		if ( $rep_add_rules ) $css .= $rep_add_sel . '{' . implode( ';', $rep_add_rules ) . '}';

		$rep_add_hover_bg_raw  = (string) ( $attributes['repeaterAddBtnHoverBg']         ?? '' );
		$rep_add_hover_bg_grad = (string) ( $attributes['repeaterAddBtnHoverBgGradient'] ?? '' );
		$rep_add_hover_bg      = sanitize_hex_color( $rep_add_hover_bg_raw );
		$rep_add_hover_color = sanitize_hex_color( (string) ( $attributes['repeaterAddBtnHoverColor'] ?? '' ) );
		$rep_add_hover_rules = [];
		if ( $rep_add_hover_bg_grad !== '' ) {
			$rep_add_hover_rules[] = 'background-image:' . sanitize_text_field( $rep_add_hover_bg_grad );
		} elseif ( $rep_add_hover_bg ) {
			$rep_add_hover_rules[] = 'background:' . $rep_add_hover_bg;
		}
		if ( $rep_add_hover_color ) $rep_add_hover_rules[] = 'color:' . $rep_add_hover_color;
		if ( $rep_add_hover_rules ) $css .= $rep_add_sel . ':hover{' . implode( ';', $rep_add_hover_rules ) . '}';

		// Remove button
		$rep_rm_sel        = $wrap_sel . ' .neural-forms-repeater__remove';
		$rep_rm_rules      = [];
		$rep_rm_color      = sanitize_hex_color( (string) ( $attributes['repeaterRemoveBtnColor']  ?? '' ) );
		$rep_rm_size       = (string) ( $attributes['repeaterRemoveBtnSize'] ?? '' );
		if ( $rep_rm_color ) $rep_rm_rules[] = 'color:' . $rep_rm_color;
		if ( $rep_rm_size )  $rep_rm_rules[] = 'font-size:' . $len( $rep_rm_size );
		if ( $rep_rm_rules ) $css .= $rep_rm_sel . '{' . implode( ';', $rep_rm_rules ) . '}';

		$rep_rm_hover_color = sanitize_hex_color( (string) ( $attributes['repeaterRemoveBtnHoverColor'] ?? '' ) );
		if ( $rep_rm_hover_color ) $css .= $rep_rm_sel . ':hover{color:' . $rep_rm_hover_color . '}';

		$rep_num_sel   = $wrap_sel . ' .neural-forms-repeater__item-num';
		$rep_num_color = sanitize_hex_color( (string) ( $attributes['repeaterItemNumColor']    ?? '' ) );
		$rep_num_fs    = (string) ( $attributes['repeaterItemNumFontSize'] ?? '' );
		$rep_num_rules = [];
		if ( $rep_num_color ) $rep_num_rules[] = 'color:' . $rep_num_color;
		if ( $rep_num_fs )    $rep_num_rules[] = 'font-size:' . $len( $rep_num_fs );
		if ( $rep_num_rules ) $css .= $rep_num_sel . '{' . implode( ';', $rep_num_rules ) . '}';

		// ── Matrix field ──────────────────────────────────────────────────────
		$mat_table_sel   = $wrap_sel . ' .neural-forms-matrix__table';
		$mat_table_rules = [];
		$mat_border_c    = sanitize_hex_color( (string) ( $attributes['matrixBorderColor']  ?? '' ) );
		$mat_border_w    = (string) ( $attributes['matrixBorderWidth']  ?? '' );
		if ( $mat_border_c && $mat_border_w ) {
			$mat_table_rules[] = 'border:' . $len( $mat_border_w ) . ' solid ' . $mat_border_c;
		} elseif ( $mat_border_c ) {
			$mat_table_rules[] = 'border-color:' . $mat_border_c;
		}
		if ( $mat_table_rules ) $css .= $mat_table_sel . '{' . implode( ';', $mat_table_rules ) . '}';

		// Column headers
		$mat_col_sel   = $wrap_sel . ' .neural-forms-matrix__col';
		$mat_col_rules = [];
		$mat_col_bg_raw  = (string) ( $attributes['matrixColHeaderBg']          ?? '' );
		$mat_col_bg_grad = (string) ( $attributes['matrixColHeaderBgGradient']  ?? '' );
		$mat_col_bg      = sanitize_hex_color( $mat_col_bg_raw );
		$mat_col_color = sanitize_hex_color( (string) ( $attributes['matrixColHeaderColor']      ?? '' ) );
		$mat_col_fs    = (string) ( $attributes['matrixColHeaderFontSize']   ?? '' );
		$mat_col_fw    = (string) ( $attributes['matrixColHeaderFontWeight'] ?? '' );
		$mat_col_pt    = (string) ( $attributes['matrixColHeaderPaddingTop']    ?? '' );
		$mat_col_pr    = (string) ( $attributes['matrixColHeaderPaddingRight']  ?? '' );
		$mat_col_pb    = (string) ( $attributes['matrixColHeaderPaddingBottom'] ?? '' );
		$mat_col_pl    = (string) ( $attributes['matrixColHeaderPaddingLeft']   ?? '' );
		if ( $mat_col_bg_grad !== '' ) {
			$mat_col_rules[] = 'background-image:' . sanitize_text_field( $mat_col_bg_grad );
		} elseif ( $mat_col_bg ) {
			$mat_col_rules[] = 'background:' . $mat_col_bg;
		}
		if ( $mat_col_color ) $mat_col_rules[] = 'color:' . $mat_col_color;
		if ( $mat_col_fs )    $mat_col_rules[] = 'font-size:' . $len( $mat_col_fs );
		if ( $mat_col_fw )    $mat_col_rules[] = 'font-weight:' . esc_attr( $mat_col_fw );
		if ( $mat_col_pt || $mat_col_pr || $mat_col_pb || $mat_col_pl ) {
			$mat_col_rules[] = 'padding:' . $spacing( $mat_col_pt ?: '0' ) . ' ' . $spacing( $mat_col_pr ?: '0' ) . ' ' . $spacing( $mat_col_pb ?: '0' ) . ' ' . $spacing( $mat_col_pl ?: '0' );
		}
		if ( $mat_col_rules ) $css .= $mat_col_sel . '{' . implode( ';', $mat_col_rules ) . '}';

		// Row headers
		$mat_rh_sel   = $wrap_sel . ' .neural-forms-matrix__row-label';
		$mat_rh_rules = [];
		$mat_rh_bg_raw  = (string) ( $attributes['matrixRowHeaderBg']          ?? '' );
		$mat_rh_bg_grad = (string) ( $attributes['matrixRowHeaderBgGradient']  ?? '' );
		$mat_rh_bg      = sanitize_hex_color( $mat_rh_bg_raw );
		$mat_rh_color = sanitize_hex_color( (string) ( $attributes['matrixRowHeaderColor']      ?? '' ) );
		$mat_rh_fs    = (string) ( $attributes['matrixRowHeaderFontSize']   ?? '' );
		$mat_rh_fw    = (string) ( $attributes['matrixRowHeaderFontWeight'] ?? '' );
		if ( $mat_rh_bg_grad !== '' ) {
			$mat_rh_rules[] = 'background-image:' . sanitize_text_field( $mat_rh_bg_grad );
		} elseif ( $mat_rh_bg ) {
			$mat_rh_rules[] = 'background:' . $mat_rh_bg;
		}
		if ( $mat_rh_color ) $mat_rh_rules[] = 'color:' . $mat_rh_color;
		if ( $mat_rh_fs )    $mat_rh_rules[] = 'font-size:' . $len( $mat_rh_fs );
		if ( $mat_rh_fw )    $mat_rh_rules[] = 'font-weight:' . esc_attr( $mat_rh_fw );
		if ( $mat_rh_rules ) $css .= $mat_rh_sel . '{' . implode( ';', $mat_rh_rules ) . '}';

		// Body cells
		$mat_cell_sel   = $wrap_sel . ' .neural-forms-matrix__cell';
		$mat_cell_rules = [];
		$mat_cell_bg_raw  = (string) ( $attributes['matrixCellBg']          ?? '' );
		$mat_cell_bg_grad = (string) ( $attributes['matrixCellBgGradient']  ?? '' );
		$mat_cell_bg      = sanitize_hex_color( $mat_cell_bg_raw );
		$mat_cell_bc    = sanitize_hex_color( (string) ( $attributes['matrixCellBorderColor'] ?? '' ) );
		$mat_cell_bw    = (string) ( $attributes['matrixCellBorderWidth']  ?? '' );
		$mat_cell_pt    = (string) ( $attributes['matrixCellPaddingTop']    ?? '' );
		$mat_cell_pr    = (string) ( $attributes['matrixCellPaddingRight']  ?? '' );
		$mat_cell_pb    = (string) ( $attributes['matrixCellPaddingBottom'] ?? '' );
		$mat_cell_pl    = (string) ( $attributes['matrixCellPaddingLeft']   ?? '' );
		if ( $mat_cell_bg ) $mat_cell_rules[] = 'background:' . $mat_cell_bg;
		if ( $mat_cell_bc && $mat_cell_bw ) {
			$mat_cell_rules[] = 'border:' . $len( $mat_cell_bw ) . ' solid ' . $mat_cell_bc;
		} elseif ( $mat_cell_bc ) {
			$mat_cell_rules[] = 'border-color:' . $mat_cell_bc;
		}
		if ( $mat_cell_pt || $mat_cell_pr || $mat_cell_pb || $mat_cell_pl ) {
			$mat_cell_rules[] = 'padding:' . $spacing( $mat_cell_pt ?: '0' ) . ' ' . $spacing( $mat_cell_pr ?: '0' ) . ' ' . $spacing( $mat_cell_pb ?: '0' ) . ' ' . $spacing( $mat_cell_pl ?: '0' );
		}
		if ( $mat_cell_rules ) $css .= $mat_cell_sel . '{' . implode( ';', $mat_cell_rules ) . '}';

		$mat_cell_hover_bg = sanitize_hex_color( (string) ( $attributes['matrixCellHoverBg'] ?? '' ) );
		if ( $mat_cell_hover_bg ) {
			$css .= $mat_cell_sel . ':hover{background:' . $mat_cell_hover_bg . '}';
		}

		// :has() is well-supported (Chrome 105+, Firefox 121+, Safari 15.4+).
		$mat_cell_checked_bg          = (string) ( $attributes['matrixCellCheckedBg']         ?? '' );
		$mat_cell_checked_bg_gradient = (string) ( $attributes['matrixCellCheckedBgGradient'] ?? '' );
		if ( $mat_cell_checked_bg_gradient !== '' ) {
			$css .= $mat_cell_sel . ':has(input:checked){background-image:' . sanitize_text_field( $mat_cell_checked_bg_gradient ) . '}';
		} elseif ( $mat_cell_checked_bg !== '' ) {
			$hex = sanitize_hex_color( $mat_cell_checked_bg );
			if ( $hex ) {
				$css .= $mat_cell_sel . ':has(input:checked){background:' . $hex . '}';
			}
		}

		// Radio accent color inside cells
		$mat_accent = sanitize_hex_color( (string) ( $attributes['matrixAccentColor'] ?? '' ) );
		if ( $mat_accent ) {
			$css .= $wrap_sel . ' .neural-forms-matrix__input{accent-color:' . $mat_accent . '}';
		}

		// Alternating row background
		$mat_row_bg = sanitize_hex_color( (string) ( $attributes['matrixRowBg'] ?? '' ) );
		if ( $mat_row_bg ) {
			$css .= $wrap_sel . ' .neural-forms-matrix__row .neural-forms-matrix__cell{background:' . $mat_row_bg . '}';
		}

		$mat_alt_bg = sanitize_hex_color( (string) ( $attributes['matrixAltRowBg'] ?? '' ) );
		if ( $mat_alt_bg ) {
			$css .= $wrap_sel . ' .neural-forms-matrix__row:nth-child(even) .neural-forms-matrix__cell{background:' . $mat_alt_bg . '}';
		}

		return $css;
	}

	// Helpers

	/**
	 * Inline admin-only diagnostic notice.
	 * Returns empty string for non-admins - safe to echo.
	 *
	 * @param string $message  Plain text (will be escaped).
	 * @return string
	 */
	private static function admin_notice( string $message ): string {
		if ( ! current_user_can( 'manage_options' ) ) {
			return '';
		}

		return '<div class="notice notice-warning inline"><p>' . esc_html( $message ) . '</p></div>';
	}
}
