<?php
namespace NeuralForms\Frontend;

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
 *     '->-> build_scoped_css( attributes )  '->-> <style> tag with CSS custom properties
 *     '->-> build_wrap_style( attributes )  '->-> inline layout styles on wrapper div
 *     '->-> final output: <style>...</style><div class="wrap">...form...</div>
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
		$is_editor = defined( 'REST_REQUEST' ) && REST_REQUEST && is_admin();

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
			'form_title'        => (string) ( $attributes['formTitle'] ?? '' ),
			'title_level'       => (string) ( $attributes['titleLevel'] ?? 'h2' ),
			'show_labels'       => ! empty( $attributes['showLabels'] ),
			'show_placeholders' => ! isset( $attributes['showPlaceholders'] )
				|| $attributes['showPlaceholders'] !== false,
		];

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

		return '<style>' . $scoped_css . $editor_css . '</style>'
			. '<div class="' . $wrap_class . '"' . $style_attr . '>'
			. $form_html
			. '</div>';
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
			'bgColor', 'bgGradient', 'bgImage', 'bgImageSize', 'bgImagePosition', 'bgImageRepeat',
			'borderColor', 'borderWidth', 'borderStyle', 'borderRadius',
			'borderRadiusTL', 'borderRadiusTR', 'borderRadiusBR', 'borderRadiusBL',
			'btnTextColor', 'btnBgColor',
			'btnAlign', 'btnRadius',
			'btnRadiusTL', 'btnRadiusTR', 'btnRadiusBR', 'btnRadiusBL',
			'btnBorderColor', 'btnBorderWidth', 'btnBorderStyle',
			'btnFontSize', 'btnFontFamily',
			'btnFontWeight', 'btnLetterSpacing', 'btnTextDecoration', 'btnTextTransform', 'btnLineHeight',
			'btnPaddingTop', 'btnPaddingRight', 'btnPaddingBottom', 'btnPaddingLeft',
			'btnHoverBg', 'btnHoverColor', 'btnHoverBorderColor',
			'btnTransitionDuration', 'btnTransitionEasing',
			'shadowH', 'shadowV', 'shadowBlur', 'shadowSpread', 'shadowColor', 'shadowPosition',
			'fieldBg', 'fieldFocusBg',
			'fieldHoverBg', 'fieldHoverBorderColor',
			'fieldBorderColor', 'fieldBorderWidth', 'fieldBorderStyle', 'fieldBorderRadius',
			'fieldBorderRadiusTL', 'fieldBorderRadiusTR', 'fieldBorderRadiusBR', 'fieldBorderRadiusBL',
			'fieldTextColor', 'fieldFocusTextColor',
			'fieldFocusBorderColor',
			'fieldFontSize', 'fieldFontFamily', 'fieldFontWeight', 'fieldLetterSpacing',
			'fieldTextDecoration', 'fieldTextTransform', 'fieldLineHeight',
			'fieldTextAlign',
			'fieldPaddingTop', 'fieldPaddingRight', 'fieldPaddingBottom', 'fieldPaddingLeft',
			'fieldGap',
			'fieldErrorBg', 'fieldErrorTextColor', 'fieldErrorBorderColor',
			'fieldErrorMsgColor', 'fieldErrorMsgFontSize',
			'successMsgBg', 'successMsgColor', 'successMsgBorderColor',
			'successMsgPaddingTop', 'successMsgPaddingRight', 'successMsgPaddingBottom', 'successMsgPaddingLeft',
			'errorMsgBg', 'errorMsgColor', 'errorMsgBorderColor',
			'errorMsgPaddingTop', 'errorMsgPaddingRight', 'errorMsgPaddingBottom', 'errorMsgPaddingLeft',
			'checkboxSize', 'checkboxCheckedColor', 'selectArrowColor',
			'fileBg', 'fileColor', 'fileBorderColor',
			'titleAlign', 'titleColor', 'titleFontFamily',
			'titleFontSize', 'titleFontWeight', 'titleLetterSpacing',
			'titleTextDecoration', 'titleTextTransform', 'titleLineHeight',
			'titlePaddingTop', 'titlePaddingRight', 'titlePaddingBottom', 'titlePaddingLeft',
			'titleMarginTop', 'titleMarginRight', 'titleMarginBottom', 'titleMarginLeft',
			'formTitle',
			'labelAlign', 'labelColor', 'labelFontFamily',
			'labelFontSize', 'labelFontWeight', 'labelLetterSpacing',
			'labelTextDecoration', 'labelTextTransform', 'labelLineHeight',
			'labelPaddingTop', 'labelPaddingRight', 'labelPaddingBottom', 'labelPaddingLeft',
			'labelMarginTop', 'labelMarginRight', 'labelMarginBottom', 'labelMarginLeft',
		];

		foreach ( $style_keys as $key ) {
			if ( isset( $attributes[ $key ] ) && $attributes[ $key ] !== '' ) {
				return true;
			}
		}

		// Boolean flags
		if (
			! empty( $attributes['btnFullWidth'] ) ||
			( isset( $attributes['successMsgEnabled'] ) && $attributes['successMsgEnabled'] === false ) ||
			( isset( $attributes['errorMsgEnabled'] ) && $attributes['errorMsgEnabled'] === false ) ||
			! empty( $attributes['showLabels'] ) ||
			( isset( $attributes['showPlaceholders'] ) && $attributes['showPlaceholders'] === false )
		) {
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

		$margin_top = (string) ( $attributes['marginTop'] ?? '' );
		if ( $margin_top !== '' ) {
			$parts[] = 'margin-top:' . sanitize_text_field( $margin_top );
		}

		$margin_right = (string) ( $attributes['marginRight'] ?? '' );
		if ( $margin_right !== '' ) {
			$parts[] = 'margin-right:' . sanitize_text_field( $margin_right );
		}

		$margin_bottom = (string) ( $attributes['marginBottom'] ?? '' );
		if ( $margin_bottom !== '' ) {
			$parts[] = 'margin-bottom:' . sanitize_text_field( $margin_bottom );
		}

		$margin_left = (string) ( $attributes['marginLeft'] ?? '' );
		if ( $margin_left !== '' ) {
			$parts[] = 'margin-left:' . sanitize_text_field( $margin_left );
		}

		// formAlign overrides side margins when a max-width is set.
		$form_align = (string) ( $attributes['formAlign'] ?? '' );
		if ( $form_align === 'center' && $max_width !== '' ) {
			$parts[] = 'margin-left:auto';
			$parts[] = 'margin-right:auto';
		} elseif ( $form_align === 'right' && $max_width !== '' ) {
			$parts[] = 'margin-left:auto';
			$parts[] = 'margin-right:0';
		} elseif ( $form_align === 'stretch' ) {
			$parts[] = 'width:100%';
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
		$wrap_sel = '.neural-forms-block-' . $uid;
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

		$wrap_rules = [];
		$var_rules  = [];

		// ' Container box '
		$bg_color = (string) ( $attributes['bgColor'] ?? '' );
		if ( $bg_color !== '' ) {
			$wrap_rules[] = 'background-color:' . sanitize_hex_color( $bg_color );
			$var_rules[]  = 'background-color:' . sanitize_hex_color( $bg_color );
		}

		// Background image / gradient. Image wins when both are set, but a
		// gradient can be layered on top of a solid color so they coexist.
		//
		// Apply bg-image / bg-gradient to BOTH the outer wrap AND the inner
		// .neural-forms-form element. Reason: bgColor is also mirrored to
		// both (see above), so the inner form paints its own background on
		// top of the wrap. Without mirroring image/gradient too, the form's
		// solid color hides the wrap's image - the user sees the image only
		// in the strip above the form (where the title lives), not under the
		// fields. Same rule for both, applied to both selectors.
		$bg_image    = (string) ( $attributes['bgImage']    ?? '' );
		$bg_gradient = (string) ( $attributes['bgGradient'] ?? '' );
		if ( $bg_image !== '' ) {
			$bg_image_rule = 'background-image:url("' . esc_url( $bg_image ) . '")';
			$bg_size       = (string) ( $attributes['bgImageSize'] ?? '' );
			$bg_size_rule  = 'background-size:' . sanitize_text_field( $bg_size !== '' ? $bg_size : 'cover' );
			$bg_pos        = (string) ( $attributes['bgImagePosition'] ?? '' );
			$bg_pos_rule   = 'background-position:' . sanitize_text_field( $bg_pos !== '' ? $bg_pos : 'center center' );
			$bg_repeat     = (string) ( $attributes['bgImageRepeat'] ?? '' );
			$bg_repeat_rule = 'background-repeat:' . sanitize_text_field( $bg_repeat !== '' ? $bg_repeat : 'no-repeat' );

			$wrap_rules[] = $bg_image_rule;
			$wrap_rules[] = $bg_size_rule;
			$wrap_rules[] = $bg_pos_rule;
			$wrap_rules[] = $bg_repeat_rule;

			$var_rules[]  = $bg_image_rule;
			$var_rules[]  = $bg_size_rule;
			$var_rules[]  = $bg_pos_rule;
			$var_rules[]  = $bg_repeat_rule;
		} elseif ( $bg_gradient !== '' ) {
			$gradient_rule = 'background-image:' . sanitize_text_field( $bg_gradient );
			$wrap_rules[]  = $gradient_rule;
			$var_rules[]   = $gradient_rule;
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

		if ( $has_corner_radius ) {
			if ( $radius_tl !== '' ) $wrap_rules[] = 'border-top-left-radius:'     . sanitize_text_field( $radius_tl );
			if ( $radius_tr !== '' ) $wrap_rules[] = 'border-top-right-radius:'    . sanitize_text_field( $radius_tr );
			if ( $radius_br !== '' ) $wrap_rules[] = 'border-bottom-right-radius:' . sanitize_text_field( $radius_br );
			if ( $radius_bl !== '' ) $wrap_rules[] = 'border-bottom-left-radius:'  . sanitize_text_field( $radius_bl );
		} else {
			$container_radius = (string) ( $attributes['borderRadius'] ?? '' );
			if ( $container_radius !== '' ) {
				$wrap_rules[] = 'border-radius:' . sanitize_text_field( $container_radius );
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

		$padding_top = (string) ( $attributes['paddingTop'] ?? '' );
		if ( $padding_top !== '' ) {
			$wrap_rules[] = 'padding-top:' . sanitize_text_field( $padding_top );
		}

		$padding_right = (string) ( $attributes['paddingRight'] ?? '' );
		if ( $padding_right !== '' ) {
			$wrap_rules[] = 'padding-right:' . sanitize_text_field( $padding_right );
		}

		$padding_bottom = (string) ( $attributes['paddingBottom'] ?? '' );
		if ( $padding_bottom !== '' ) {
			$wrap_rules[] = 'padding-bottom:' . sanitize_text_field( $padding_bottom );
		}

		$padding_left = (string) ( $attributes['paddingLeft'] ?? '' );
		if ( $padding_left !== '' ) {
			$wrap_rules[] = 'padding-left:' . sanitize_text_field( $padding_left );
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

		$btn_radius = (string) ( $attributes['btnRadius'] ?? '' );
		if ( $btn_radius !== '' ) {
			$var_rules[] = '--neural-forms-btn-radius:' . sanitize_text_field( $btn_radius );
		}

		$btn_align = (string) ( $attributes['btnAlign'] ?? '' );
		if ( $btn_align !== '' && in_array( $btn_align, [ 'left', 'center', 'right' ], true ) ) {
			$var_rules[] = '--neural-forms-btn-align:' . $btn_align;
		}

		if ( ! empty( $attributes['btnFullWidth'] ) ) {
			$var_rules[] = '--neural-forms-btn-width:100%';
		}

		// ' Field styling '
		$field_bg = (string) ( $attributes['fieldBg'] ?? '' );
		if ( $field_bg !== '' ) {
			$var_rules[] = '--neural-forms-field-bg:' . sanitize_hex_color( $field_bg );
		}

		$field_focus_bg = (string) ( $attributes['fieldFocusBg'] ?? '' );
		if ( $field_focus_bg !== '' ) {
			$var_rules[] = '--neural-forms-field-focus-bg:' . sanitize_hex_color( $field_focus_bg );
		}

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

		$field_border_radius = (string) ( $attributes['fieldBorderRadius'] ?? '' );
		if ( $field_border_radius !== '' ) {
			$var_rules[] = '--neural-forms-radius:' . sanitize_text_field( $field_border_radius );
		}

		$field_text_color = (string) ( $attributes['fieldTextColor'] ?? '' );
		if ( $field_text_color !== '' ) {
			$var_rules[] = '--neural-forms-text:' . sanitize_hex_color( $field_text_color );
		}

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
		$field_letter_spacing = (string) ( $attributes['fieldLetterSpacing'] ?? '' );
		if ( $field_letter_spacing !== '' ) {
			$ls = $field_letter_spacing;
			if ( is_numeric( $ls ) ) {
				$ls .= 'px';
			}
			$field_typo_rules[] = 'letter-spacing:' . sanitize_text_field( $ls );
		}
		$field_text_decoration = (string) ( $attributes['fieldTextDecoration'] ?? '' );
		if ( $field_text_decoration !== '' && in_array( $field_text_decoration, [ 'underline', 'line-through' ], true ) ) {
			$field_typo_rules[] = 'text-decoration:' . $field_text_decoration;
		}
		$field_text_transform = (string) ( $attributes['fieldTextTransform'] ?? '' );
		if ( $field_text_transform !== '' && in_array( $field_text_transform, [ 'uppercase', 'lowercase', 'capitalize' ], true ) ) {
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
			if ( $btn_radius_tl !== '' ) $btn_rules[] = 'border-top-left-radius:'     . sanitize_text_field( $btn_radius_tl );
			if ( $btn_radius_tr !== '' ) $btn_rules[] = 'border-top-right-radius:'    . sanitize_text_field( $btn_radius_tr );
			if ( $btn_radius_br !== '' ) $btn_rules[] = 'border-bottom-right-radius:' . sanitize_text_field( $btn_radius_br );
			if ( $btn_radius_bl !== '' ) $btn_rules[] = 'border-bottom-left-radius:'  . sanitize_text_field( $btn_radius_bl );
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
		$btn_letter_spacing = (string) ( $attributes['btnLetterSpacing'] ?? '' );
		if ( $btn_letter_spacing !== '' ) {
			$ls = $btn_letter_spacing;
			if ( is_numeric( $ls ) ) {
				$ls .= 'px';
			}
			$btn_rules[] = 'letter-spacing:' . sanitize_text_field( $ls );
		}
		$btn_text_decoration = (string) ( $attributes['btnTextDecoration'] ?? '' );
		if ( $btn_text_decoration !== '' && in_array( $btn_text_decoration, [ 'underline', 'line-through' ], true ) ) {
			$btn_rules[] = 'text-decoration:' . $btn_text_decoration;
		}
		$btn_text_transform = (string) ( $attributes['btnTextTransform'] ?? '' );
		if ( $btn_text_transform !== '' && in_array( $btn_text_transform, [ 'uppercase', 'lowercase', 'capitalize' ], true ) ) {
			$btn_rules[] = 'text-transform:' . $btn_text_transform;
		}
		$btn_line_height = (string) ( $attributes['btnLineHeight'] ?? '' );
		if ( $btn_line_height !== '' ) {
			$btn_rules[] = 'line-height:' . sanitize_text_field( $btn_line_height );
		}

		foreach ( [ 'Top' => 'padding-top', 'Right' => 'padding-right', 'Bottom' => 'padding-bottom', 'Left' => 'padding-left' ] as $key => $css_prop ) {
			$val = (string) ( $attributes[ 'btnPadding' . $key ] ?? '' );
			if ( $val !== '' ) {
				$btn_rules[] = $css_prop . ':' . sanitize_text_field( $val );
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

		if ( $var_rules ) {
			$css .= $form_sel . '{' . implode( ';', $var_rules ) . '}';
		}

		if ( $btn_rules ) {
			$css .= $btn_sel . '{' . implode( ';', $btn_rules ) . '}';
		}

		if ( $btn_hover_rules ) {
			$css .= $btn_hover_sel . '{' . implode( ';', $btn_hover_rules ) . '}';
		}

		if ( $field_typo_rules ) {
			$field_sel = $wrap_sel . ' .neural-forms-form__input,'
				. $wrap_sel . ' .neural-forms-form__textarea,'
				. $wrap_sel . ' .neural-forms-form__select';
			$css .= $field_sel . '{' . implode( ';', $field_typo_rules ) . '}';
		}

		// ' Field padding (applies to inputs, textareas, selects) '
		$field_pad_rules = [];
		foreach ( [ 'Top' => 'padding-top', 'Right' => 'padding-right', 'Bottom' => 'padding-bottom', 'Left' => 'padding-left' ] as $key => $css_prop ) {
			$val = (string) ( $attributes[ 'fieldPadding' . $key ] ?? '' );
			if ( $val !== '' ) {
				$field_pad_rules[] = $css_prop . ':' . sanitize_text_field( $val );
			}
		}
		if ( $field_pad_rules ) {
			$field_pad_sel = $wrap_sel . ' .neural-forms-form__input,'
				. $wrap_sel . ' .neural-forms-form__textarea,'
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
		if ( $title_align !== '' && in_array( $title_align, [ 'left', 'center', 'right' ], true ) ) {
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
		$title_ls = (string) ( $attributes['titleLetterSpacing'] ?? '' );
		if ( $title_ls !== '' ) {
			$title_rules[] = 'letter-spacing:' . sanitize_text_field( $title_ls );
		}
		$title_td = (string) ( $attributes['titleTextDecoration'] ?? '' );
		if ( $title_td !== '' && in_array( $title_td, [ 'underline', 'line-through' ], true ) ) {
			$title_rules[] = 'text-decoration:' . $title_td;
		}
		$title_tt = (string) ( $attributes['titleTextTransform'] ?? '' );
		if ( $title_tt !== '' && in_array( $title_tt, [ 'uppercase', 'lowercase', 'capitalize' ], true ) ) {
			$title_rules[] = 'text-transform:' . $title_tt;
		}
		$title_lh = (string) ( $attributes['titleLineHeight'] ?? '' );
		if ( $title_lh !== '' ) {
			$title_rules[] = 'line-height:' . sanitize_text_field( $title_lh );
		}
		foreach ( [ 'Top' => 'padding-top', 'Right' => 'padding-right', 'Bottom' => 'padding-bottom', 'Left' => 'padding-left' ] as $key => $css_prop ) {
			$val = (string) ( $attributes[ 'titlePadding' . $key ] ?? '' );
			if ( $val !== '' ) {
				$title_rules[] = $css_prop . ':' . sanitize_text_field( $val );
			}
		}
		foreach ( [ 'Top' => 'margin-top', 'Right' => 'margin-right', 'Bottom' => 'margin-bottom', 'Left' => 'margin-left' ] as $key => $css_prop ) {
			$val = (string) ( $attributes[ 'titleMargin' . $key ] ?? '' );
			if ( $val !== '' ) {
				$title_rules[] = $css_prop . ':' . sanitize_text_field( $val );
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
		if ( $label_align !== '' && in_array( $label_align, [ 'left', 'center', 'right' ], true ) ) {
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
		$label_ls = (string) ( $attributes['labelLetterSpacing'] ?? '' );
		if ( $label_ls !== '' ) {
			$ls = $label_ls;
			if ( is_numeric( $ls ) ) {
				$ls .= 'px';
			}
			$label_rules[] = 'letter-spacing:' . sanitize_text_field( $ls );
		}
		$label_td = (string) ( $attributes['labelTextDecoration'] ?? '' );
		if ( $label_td !== '' && in_array( $label_td, [ 'underline', 'line-through' ], true ) ) {
			$label_rules[] = 'text-decoration:' . $label_td;
		}
		$label_tt = (string) ( $attributes['labelTextTransform'] ?? '' );
		if ( $label_tt !== '' && in_array( $label_tt, [ 'uppercase', 'lowercase', 'capitalize' ], true ) ) {
			$label_rules[] = 'text-transform:' . $label_tt;
		}
		$label_lh = (string) ( $attributes['labelLineHeight'] ?? '' );
		if ( $label_lh !== '' ) {
			$label_rules[] = 'line-height:' . sanitize_text_field( $label_lh );
		}
		foreach ( [ 'Top' => 'padding-top', 'Right' => 'padding-right', 'Bottom' => 'padding-bottom', 'Left' => 'padding-left' ] as $key => $css_prop ) {
			$val = (string) ( $attributes[ 'labelPadding' . $key ] ?? '' );
			if ( $val !== '' ) {
				$label_rules[] = $css_prop . ':' . sanitize_text_field( $val );
			}
		}
		foreach ( [ 'Top' => 'margin-top', 'Right' => 'margin-right', 'Bottom' => 'margin-bottom', 'Left' => 'margin-left' ] as $key => $css_prop ) {
			$val = (string) ( $attributes[ 'labelMargin' . $key ] ?? '' );
			if ( $val !== '' ) {
				$label_rules[] = $css_prop . ':' . sanitize_text_field( $val );
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
				$field_corner_rules[] = $css_prop . ':' . sanitize_text_field( $val );
			}
		}
		if ( $field_corner_rules ) {
			$field_corner_sel = $wrap_sel . ' .neural-forms-form__input,'
				. $wrap_sel . ' .neural-forms-form__textarea,'
				. $wrap_sel . ' .neural-forms-form__select';
			$css .= $field_corner_sel . '{' . implode( ';', $field_corner_rules ) . '}';
		}

		// ' Field hover state (bg + border color) '
		$field_hover_rules = [];
		$field_hover_bg = (string) ( $attributes['fieldHoverBg'] ?? '' );
		if ( $field_hover_bg !== '' ) {
			$field_hover_rules[] = 'background-color:' . sanitize_hex_color( $field_hover_bg );
		}
		$field_hover_border = (string) ( $attributes['fieldHoverBorderColor'] ?? '' );
		if ( $field_hover_border !== '' ) {
			$field_hover_rules[] = 'border-color:' . sanitize_hex_color( $field_hover_border );
		}
		if ( $field_hover_rules ) {
			$field_hover_sel = $wrap_sel . ' .neural-forms-form__input:hover:not(:focus),'
				. $wrap_sel . ' .neural-forms-form__textarea:hover:not(:focus),'
				. $wrap_sel . ' .neural-forms-form__select:hover:not(:focus)';
			$css .= $field_hover_sel . '{' . implode( ';', $field_hover_rules ) . '}';
		}

		// ' Field error state (invalid input + per-field error message) '
		$field_error_rules = [];
		$field_err_bg = (string) ( $attributes['fieldErrorBg'] ?? '' );
		if ( $field_err_bg !== '' ) {
			$field_error_rules[] = 'background-color:' . sanitize_hex_color( $field_err_bg );
		}
		$field_err_text = (string) ( $attributes['fieldErrorTextColor'] ?? '' );
		if ( $field_err_text !== '' ) {
			$field_error_rules[] = 'color:' . sanitize_hex_color( $field_err_text );
		}
		$field_err_border = (string) ( $attributes['fieldErrorBorderColor'] ?? '' );
		if ( $field_err_border !== '' ) {
			$field_error_rules[] = 'border-color:' . sanitize_hex_color( $field_err_border );
		}
		if ( $field_error_rules ) {
			$field_err_sel = $wrap_sel . ' .neural-forms-form__field--has-error .neural-forms-form__input,'
				. $wrap_sel . ' .neural-forms-form__field--has-error .neural-forms-form__textarea,'
				. $wrap_sel . ' .neural-forms-form__field--has-error .neural-forms-form__select';
			$css .= $field_err_sel . '{' . implode( ';', $field_error_rules ) . '}';
		}

		$field_err_msg_rules = [];
		$field_err_msg_color = (string) ( $attributes['fieldErrorMsgColor'] ?? '' );
		if ( $field_err_msg_color !== '' ) {
			$field_err_msg_rules[] = 'color:' . sanitize_hex_color( $field_err_msg_color );
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
			$success_rules = [];
			$success_bg = (string) ( $attributes['successMsgBg'] ?? '' );
			if ( $success_bg !== '' ) {
				$success_rules[] = 'background-color:' . sanitize_hex_color( $success_bg );
			}
			$success_color = (string) ( $attributes['successMsgColor'] ?? '' );
			if ( $success_color !== '' ) {
				$success_rules[] = 'color:' . sanitize_hex_color( $success_color );
			}
			$success_border = (string) ( $attributes['successMsgBorderColor'] ?? '' );
			if ( $success_border !== '' ) {
				$success_rules[] = 'border-color:' . sanitize_hex_color( $success_border );
			}
			foreach ( [ 'Top' => 'padding-top', 'Right' => 'padding-right', 'Bottom' => 'padding-bottom', 'Left' => 'padding-left' ] as $key => $css_prop ) {
				$val = (string) ( $attributes[ 'successMsgPadding' . $key ] ?? '' );
				if ( $val !== '' ) {
					$success_rules[] = $css_prop . ':' . sanitize_text_field( $val );
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
			$err_rules = [];
			$err_bg = (string) ( $attributes['errorMsgBg'] ?? '' );
			if ( $err_bg !== '' ) {
				$err_rules[] = 'background-color:' . sanitize_hex_color( $err_bg );
			}
			$err_color = (string) ( $attributes['errorMsgColor'] ?? '' );
			if ( $err_color !== '' ) {
				$err_rules[] = 'color:' . sanitize_hex_color( $err_color );
			}
			$err_border = (string) ( $attributes['errorMsgBorderColor'] ?? '' );
			if ( $err_border !== '' ) {
				$err_rules[] = 'border-color:' . sanitize_hex_color( $err_border );
			}
			foreach ( [ 'Top' => 'padding-top', 'Right' => 'padding-right', 'Bottom' => 'padding-bottom', 'Left' => 'padding-left' ] as $key => $css_prop ) {
				$val = (string) ( $attributes[ 'errorMsgPadding' . $key ] ?? '' );
				if ( $val !== '' ) {
					$err_rules[] = $css_prop . ':' . sanitize_text_field( $val );
				}
			}
			if ( $err_rules ) {
				$css .= $wrap_sel . ' .neural-forms-form__global-error{' . implode( ';', $err_rules ) . '}';
			}
		}

		// ' Per-field-type controls (checkbox / radio / select / file) '
		$cb_size = (string) ( $attributes['checkboxSize'] ?? '' );
		if ( $cb_size !== '' ) {
			$cb = sanitize_text_field( $cb_size );
			$cb_sel = $wrap_sel . ' .neural-forms-form__checkbox,' . $wrap_sel . ' .neural-forms-form__radio';
			$css .= $cb_sel . '{width:' . $cb . ';height:' . $cb . '}';
		}
		$cb_color = (string) ( $attributes['checkboxCheckedColor'] ?? '' );
		if ( $cb_color !== '' ) {
			$cb_sel = $wrap_sel . ' .neural-forms-form__checkbox,' . $wrap_sel . ' .neural-forms-form__radio';
			$css .= $cb_sel . '{accent-color:' . sanitize_hex_color( $cb_color ) . '}';
		}

		$arrow_color = (string) ( $attributes['selectArrowColor'] ?? '' );
		if ( $arrow_color !== '' ) {
			$hex = sanitize_hex_color( $arrow_color );
			if ( $hex ) {
				// Encode the hex without the leading '#' for the inline SVG URL.
				$encoded = '%23' . substr( $hex, 1 );
				$svg = "url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath fill='" . $encoded . "' d='M1 1l5 5 5-5'/%3E%3C/svg%3E\")";
				$css .= $wrap_sel . ' .neural-forms-form__select{background-image:' . $svg . '}';
			}
		}

		$file_rules = [];
		$file_bg = (string) ( $attributes['fileBg'] ?? '' );
		if ( $file_bg !== '' ) {
			$file_rules[] = 'background-color:' . sanitize_hex_color( $file_bg );
		}
		$file_color = (string) ( $attributes['fileColor'] ?? '' );
		if ( $file_color !== '' ) {
			$file_rules[] = 'color:' . sanitize_hex_color( $file_color );
		}
		$file_border = (string) ( $attributes['fileBorderColor'] ?? '' );
		if ( $file_border !== '' ) {
			$file_rules[] = 'border-color:' . sanitize_hex_color( $file_border );
		}
		if ( $file_rules ) {
			$css .= $wrap_sel . ' .neural-forms-form__file-input{' . implode( ';', $file_rules ) . '}';
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
