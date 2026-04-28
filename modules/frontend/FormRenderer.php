<?php
namespace NeuralForms\Frontend;

use NeuralForms\Core\Config;
use NeuralForms\Forms\FormRepository;

defined( 'ABSPATH' ) || exit;

/**
 * FormRenderer
 *
 * Converts a stored form schema into ready-to-output frontend HTML.
 * Purely presentational — no state mutations, no REST calls, no hooks
 * registered. Every method is stateless.
 *
 * Usage
 * ─────
 *   echo FormRenderer::render( 42 );
 *
 * The returned HTML is self-contained:
 *   - Fields are rendered in schema order (no client-side reordering needed).
 *   - Input IDs are scoped: neural-forms-{form_id}-{field_id}, so multiple forms
 *     on the same page never produce duplicate IDs.
 *   - Per-field error containers are pre-rendered empty and hidden; the JS
 *     submission handler populates them on 422 without altering DOM structure.
 *   - A honeypot field guards against naive bot submissions at zero cost.
 *   - novalidate on <form> suppresses browser native validation so the JS +
 *     server layers fully control the error UX.
 *
 * Asset enqueueing
 * ────────────────
 *   wp_enqueue_style( 'neural-forms-form' ) and wp_enqueue_script( 'neural-forms-form-submit' )
 *   are called from render(). The handles must be registered first by
 *   PublicForms::boot() (Step 3). WordPress deduplicates enqueue calls, so
 *   rendering multiple forms on one page enqueues the assets exactly once.
 *
 * Escaping contract
 * ─────────────────
 *   All user-controlled data (field labels, placeholders, option labels,
 *   default values, success messages) passes through esc_attr() or esc_html()
 *   before being written into the HTML string. The caller may echo the return
 *   value directly — no additional escaping is required or should be applied.
 */
class FormRenderer {

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Render a form by its database ID.
	 *
	 * Returns an empty string (not an exception) when the form is missing or
	 * inactive — safe to echo directly. Admins (manage_options) additionally
	 * see an inline notice so they can diagnose a wrong ID or status without
	 * the visitor ever knowing.
	 *
	 * Applies the 'neural_forms_render_form' filter before returning.
	 *
	 * @param int   $form_id
	 * @param array $options  Render options from the block (e.g. form_title).
	 * @return string  Ready-to-echo HTML (all content escaped internally).
	 */
	public static function render( int $form_id, array $options = [] ): string {
		$form = FormRepository::find( $form_id );

		if ( ! $form ) {
			return static::admin_notice(
				sprintf(
					/* translators: %d: form ID */
					__( 'Neural Forms: no form found with ID %d.', 'neural-forms' ),
					$form_id
				)
			);
		}

		if ( $form['status'] !== 'active' ) {
			return static::admin_notice(
				sprintf(
					/* translators: 1: form name  2: status slug */
					__( 'Neural Forms: form "%1$s" is not active (status: %2$s).', 'neural-forms' ),
					$form['name'],
					$form['status']
				)
			);
		}

		// Handles registered by PublicForms::boot() (Step 3).
		// wp_enqueue_* is safe inside shortcode callbacks — the script/style
		// output happens in wp_footer() / wp_head(), which run after the_content.
		// WordPress deduplicates: calling enqueue three times for three forms
		// on one page produces a single <script> tag.
		wp_enqueue_style( 'neural-forms-form' );
		wp_enqueue_script( 'neural-forms-form-submit' );

		$html = static::render_form( $form, $options );

		/**
		 * Filters the complete rendered form HTML.
		 *
		 * @param string $html Rendered form HTML (all content already escaped).
		 * @param array  $form Form row returned by FormRepository::find().
		 */
		return (string) apply_filters( 'neural_forms_render_form', $html, $form );
	}

	/**
	 * Render a form for block editor preview (ServerSideRender).
	 *
	 * Same output as render(), but skips enqueuing form-submit.js and
	 * form.css (the editor loads form.css via the block's editorStyle).
	 * This prevents the submission handler from binding inside the
	 * editor iframe and avoids double-loading stylesheets.
	 *
	 * Also skips the status check — drafts should be previewable in the editor.
	 *
	 * @param int   $form_id
	 * @param array $options  Render options from the block (e.g. form_title).
	 * @return string  Ready-to-echo HTML, or empty string if not found.
	 */
	public static function render_preview( int $form_id, array $options = [] ): string {
		$form = FormRepository::find( $form_id );

		if ( ! $form ) {
			return '';
		}

		// No wp_enqueue_style/script — editor context handles its own assets.
		return static::render_form( $form, $options );
	}

	// -------------------------------------------------------------------------
	// Form wrapper
	// -------------------------------------------------------------------------

	/**
	 * Build the full form HTML from a decoded form array.
	 *
	 * Structure:
	 *   .neural-forms-form-wrap
	 *     form.neural-forms-form[data-neural-forms-id][data-neural-forms-version][novalidate]
	 *       .neural-forms-form__fields
	 *         … rendered fields …
	 *         .neural-forms-form__field--honeypot (hidden)
	 *       p.neural-forms-form__global-error[role=alert][hidden]
	 *       button.neural-forms-form__submit
	 *     .neural-forms-form__success[hidden]
	 *
	 * The success div is a sibling of <form>, not a child, so JS can hide
	 * the form and reveal the success message independently.
	 *
	 * @param array $form     Decoded form row from FormRepository::find().
	 * @param array $options  Render options (e.g. form_title, title_level).
	 * @return string
	 */
	private static function render_form( array $form, array $options = [] ): string {
		$form_id  = (int) $form['id'];
		$schema   = is_array( $form['form_schema'] ) ? $form['form_schema'] : [];
		$fields   = is_array( $schema['fields'] )    ? $schema['fields']    : [];
		$settings = is_array( $form['settings'] )    ? $form['settings']    : [];

		// Mirrors the form_version hash written by SubmitEndpoint at submission
		// time. Lets the JS layer (or future diffing logic) detect pages served
		// from a cache after the schema was updated.
		$version = md5( (string) wp_json_encode( $schema ) );

		// On-submit outcome settings. Both 'on_success' and 'on_error' carry:
		//   mode    'message' or 'redirect'
		//   message Used when mode === 'message'
		//   url     Used when mode === 'redirect'
		// Legacy 'success_message' (saved before the on_success object existed)
		// is honored as a fallback so older forms keep their custom text.
		$on_success         = is_array( $settings['on_success'] ?? null ) ? $settings['on_success'] : [];
		$on_error           = is_array( $settings['on_error']   ?? null ) ? $settings['on_error']   : [];
		$on_success_mode    = ( ( $on_success['mode'] ?? 'message' ) === 'redirect' ) ? 'redirect' : 'message';
		$on_success_message = (string) ( $on_success['message'] ?? '' );
		$on_success_url     = esc_url_raw( (string) ( $on_success['url'] ?? '' ) );
		$on_error_mode      = ( ( $on_error['mode'] ?? 'message' ) === 'redirect' ) ? 'redirect' : 'message';
		$on_error_message   = (string) ( $on_error['message'] ?? '' );
		$on_error_url       = esc_url_raw( (string) ( $on_error['url'] ?? '' ) );

		// Success message — uses the new on_success.message if set, then the
		// legacy success_message, then a translated default.
		$legacy_success_msg = (string) ( $settings['success_message'] ?? '' );
		if ( $on_success_message !== '' ) {
			$success_msg = $on_success_message;
		} elseif ( $legacy_success_msg !== '' ) {
			$success_msg = $legacy_success_msg;
		} else {
			$success_msg = __( 'Thank you! Your submission has been received.', 'neural-forms' );
		}

		// ── Fields ─────────────────────────────────────────────────────────
		$fields_html = '';
		$has_files   = false;

		foreach ( $fields as $field ) {
			$field = (array) $field;

			// Skip silently — malformed field definitions should never reach
			// here (FormValidator catches them at save time), but defensive
			// handling prevents a blank page from a corrupt schema.
			if ( empty( $field['id'] ) || empty( $field['type'] ) ) {
				continue;
			}

			if ( $field['type'] === 'file' ) {
				$has_files = true;
			}

			$fields_html .= static::render_field( $field, $form_id, $options );
		}

		// ── Honeypot ───────────────────────────────────────────────────────
		// Name "neural_forms_website" sounds like a real field to bots but is
		// intentionally generic — avoids "hp" or "honeypot" patterns that
		// sophisticated bots recognise and skip.
		//
		// inline style="display:none" rather than [hidden] attribute: some
		// DOM-scanning bots treat [hidden] as a skip signal; inline style is
		// less likely to be heuristically avoided.
		//
		// tabindex="-1" removes it from keyboard navigation for legitimate users.
		// autocomplete="off" prevents browsers from pre-filling it from saved form data.
		//
		// SubmitEndpoint checks this field at Step 4b: non-empty → spam_detected 400.
		$hp_input_id  = esc_attr( 'neural-forms-' . $form_id . '-website' );
		$fields_html .= '<div class="neural-forms-form__field neural-forms-form__field--honeypot"'
						. ' style="display:none" aria-hidden="true">'
						. '<label for="' . $hp_input_id . '">' . esc_html__( 'Website', 'neural-forms' ) . '</label>'
						. '<input type="text"'
						. ' id="' . $hp_input_id . '"'
						. ' name="neural_forms_website"'
						. ' tabindex="-1"'
						. ' autocomplete="off">'
						. '</div>';

		// ── Assemble ───────────────────────────────────────────────────────
		$html  = '<div class="neural-forms-form-wrap">';

		// Optional form title — block attribute formTitle. When non-empty,
		// emit it as a heading at the top of the form. The heading level is
		// configurable via the title_level option (h1–h6, default h2).
		$title = isset( $options['form_title'] ) ? (string) $options['form_title'] : '';
		if ( $title !== '' ) {
			$lvl = strtolower( (string) ( $options['title_level'] ?? 'h2' ) );
			if ( ! in_array( $lvl, [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ], true ) ) {
				$lvl = 'h2';
			}
			$html .= '<' . $lvl . ' class="neural-forms-form__title">'
				. esc_html( $title )
				. '</' . $lvl . '>';
		}

		// novalidate: disables browser native validation UI so JS + server
		// responses control all error feedback consistently.
		//
		// data-neural-forms-id: form ID read by form-submit.js to build the
		// endpoint URL: /wp-json/neural-forms/v1/forms/{id}/submit
		//
		// data-neural-forms-version: schema hash for future stale-page detection.
		$form_attrs  = ' class="neural-forms-form"';
		$form_attrs .= ' data-neural-forms-id="' . $form_id . '"';
		$form_attrs .= ' data-neural-forms-version="' . esc_attr( $version ) . '"';
		if ( $has_files ) {
			$form_attrs .= ' data-neural-forms-has-files="1"';
		}

		// On-submit outcome — read by form-submit.js to decide between the
		// inline success/error UI and a redirect. Empty URL implies the JS
		// falls back to the inline path even if mode === 'redirect'.
		$form_attrs .= ' data-on-success-mode="' . esc_attr( $on_success_mode ) . '"';
		if ( $on_success_url !== '' ) {
			$form_attrs .= ' data-on-success-url="' . esc_url( $on_success_url ) . '"';
		}
		$form_attrs .= ' data-on-error-mode="' . esc_attr( $on_error_mode ) . '"';
		if ( $on_error_url !== '' ) {
			$form_attrs .= ' data-on-error-url="' . esc_url( $on_error_url ) . '"';
		}
		if ( $on_error_message !== '' ) {
			$form_attrs .= ' data-on-error-message="' . esc_attr( $on_error_message ) . '"';
		}

		$form_attrs .= ' novalidate';

		$html .= '<form' . $form_attrs . '>';

		$html .= '<div class="neural-forms-form__fields">' . $fields_html . '</div>';

		// Global (non-field) error container — populated by JS for 429, 5xx,
		// and network failures. role="alert" implies aria-live="assertive" so
		// screen readers announce it immediately when JS sets its text.
		$html .= '<p class="neural-forms-form__global-error" role="alert" hidden></p>';

		// ── CAPTCHA widget ──────────────────────────────────────────────────
		// Rendered only when the form has captcha_enabled = true AND a provider
		// with a site key is globally configured. The provider JS SDK (Turnstile
		// or reCAPTCHA) auto-initialises widgets by CSS class name, so no custom
		// init code is required. The SDK also injects a hidden input that
		// form-submit.js reads to collect the token.
		//
		// data-neural-forms-captcha is a provider identifier read by form-submit.js
		// to know which input name to look for and which reset API to call.
		$captcha_provider = (string) Config::get( 'captcha_provider', 'none' );
		$captcha_site_key = (string) Config::get( 'captcha_site_key', '' );
		$captcha_enabled  = ! empty( $settings['captcha_enabled'] );

		if ( $captcha_enabled && $captcha_provider !== 'none' && $captcha_site_key !== '' ) {
			// Map provider slug to the auto-render CSS class expected by the SDK.
			$provider_class_map = [
				'turnstile'    => 'cf-turnstile',
				'recaptcha_v2' => 'g-recaptcha',
			];

			$provider_class = $provider_class_map[ $captcha_provider ] ?? '';

			if ( $provider_class !== '' ) {
				$html .= '<div'
					. ' class="neural-forms-form__captcha ' . esc_attr( $provider_class ) . '"'
					. ' data-sitekey="' . esc_attr( $captcha_site_key ) . '"'
					. ' data-neural-forms-captcha="' . esc_attr( $captcha_provider ) . '"'
					. '></div>';

				// Enqueue the provider script once per page (WP deduplicates).
				if ( $captcha_provider === 'turnstile' ) {
					wp_enqueue_script(
						'neural-forms-cf-turnstile',
						'https://challenges.cloudflare.com/turnstile/v0/api.js',
						[],
						null,
						true
					);
				} elseif ( $captcha_provider === 'recaptcha_v2' ) {
					wp_enqueue_script(
						'neural-forms-recaptcha-v2',
						'https://www.google.com/recaptcha/api.js',
						[],
						null,
						true
					);
				}
			}
		}
		// ── end CAPTCHA ─────────────────────────────────────────────────────

		$html .= '<div class="neural-forms-form__submit-wrap">'
				. '<button type="submit" class="neural-forms-form__submit">'
				. esc_html__( 'Submit', 'neural-forms' )
				. '</button>'
				. '</div>';

		$html .= '</form>';

		// Success state sits outside <form> as a sibling so JS can hide the form
		// (display:none) and show this div independently on a 201 response.
		$html .= '<div class="neural-forms-form__success" role="status" aria-live="polite" hidden>'
				. '<p>' . esc_html( $success_msg ) . '</p>'
				. '</div>';

		$html .= '</div>'; // .neural-forms-form-wrap

		return $html;
	}

	// -------------------------------------------------------------------------
	// Field dispatcher
	// -------------------------------------------------------------------------

	/**
	 * Render a single field: label + input + error container, in a wrapper div.
	 *
	 * The outer div carries data-neural-forms-field-id so form-submit.js can locate
	 * the error slot for any field by its schema ID without coupling itself to
	 * the internal markup layout.
	 *
	 * @param array $field    Decoded field definition from form_schema['fields'].
	 * @param int   $form_id  Parent form ID — used to scope element IDs.
	 * @param array $options  Reserved for future per-field render options.
	 * @return string
	 */
	private static function render_field( array $field, int $form_id, array $options = [] ): string {
		$type     = $field['type'];
		$field_id = $field['id'];
		$required = ! empty( $field['required'] );

		// Label / placeholder resolution:
		//   • show_labels (block-level)  → render every field's label visibly
		//     above its input. Falls back to per-field $field['show_label']
		//     when the option is absent (e.g. shortcode usage).
		//   • show_placeholders (block-level, default true) → render the
		//     placeholder. When false, no field gets a placeholder.
		//   • Placeholder source: user-defined $field['placeholder'] wins.
		//     If empty AND the label is hidden, fall back to label-as-
		//     placeholder so the user still sees a hint inside the input.
		$lbl              = (string) ( $field['label'] ?? '' );
		$user_placeholder = (string) ( $field['placeholder'] ?? '' );
		$show_label       = isset( $options['show_labels'] )
			? (bool) $options['show_labels']
			: ! empty( $field['show_label'] );
		$show_placeholders = ! isset( $options['show_placeholders'] ) || $options['show_placeholders'] !== false;

		if ( ! $show_placeholders ) {
			$field['placeholder'] = '';
		} elseif ( $user_placeholder !== '' ) {
			$field['placeholder'] = $user_placeholder;
		} elseif ( ! $show_label && $lbl !== '' ) {
			$field['placeholder'] = $lbl;
		} else {
			$field['placeholder'] = '';
		}

		// Persist the resolved show_label back onto the field so per-type
		// renderers (radio / checkboxes) that don't receive $options pick up
		// the same value.
		$field['show_label'] = $show_label;

		// Hidden fields render as a bare <input type="hidden"> with no wrapper,
		// label, or error slot — they are invisible to the user.
		if ( $type === 'hidden' ) {
			$input_id = 'neural-forms-' . $form_id . '-' . $field_id;
			return static::render_hidden( $field, $input_id );
		}

		// ── Conditional logic data attribute ───────────────────────────────────
		// When a field has conditional_logic.enabled = true and references another
		// field, emit data-conditional so form-submit.js can evaluate visibility
		// client-side without knowing the full schema.
		//
		// Only emit the keys the frontend actually needs: field, operator, value.
		// Ignore all other keys so the attribute stays small.
		$conditional_attr = '';
		$cl = $field['conditional_logic'] ?? null;
		if (
			is_array( $cl )
			&& ! empty( $cl['enabled'] )
			&& isset( $cl['field'] )
			&& (string) $cl['field'] !== ''
		) {
			$cl_data = [
				'field'    => (string) ( $cl['field']    ?? '' ),
				'operator' => (string) ( $cl['operator'] ?? 'equals' ),
				'value'    => (string) ( $cl['value']    ?? '' ),
			];
			$conditional_attr = ' data-conditional="' . esc_attr( (string) wp_json_encode( $cl_data ) ) . '"';
		}

		// Scoped IDs: neural-forms-{form_id}-{field_id}
		// Prevents ID collisions when two or more forms appear on the same page.
		$input_id = 'neural-forms-' . $form_id . '-' . $field_id;
		$error_id = $input_id . '-error';

		// Field-level label.
		// • Default: empty — the label is carried by the placeholder.
		// • show_label = true: emit a visible <label> above the input. Skipped
		//   for checkbox / acceptance (their inline label already serves as
		//   the question) and for radio / checkboxes (their renderers expose
		//   the label as a visible <legend> instead).
		$label_html = '';
		if (
			$show_label
			&& $lbl !== ''
			&& ! in_array( $type, [ 'checkbox', 'acceptance', 'radio', 'checkboxes' ], true )
		) {
			$required_mark = $required
				? ' <span class="neural-forms-form__required" aria-hidden="true">*</span>'
				: '';
			$label_html = '<label class="neural-forms-form__label neural-forms-form__label--block" for="' . esc_attr( $input_id ) . '">'
						. esc_html( $lbl )
						. $required_mark
						. '</label>';
		}

		// ── Input ──────────────────────────────────────────────────────────
		switch ( $type ) {
			case 'email':
				$input_html = static::render_email( $field, $input_id, $error_id );
				break;
			case 'phone':
			case 'tel':
				$input_html = static::render_phone( $field, $input_id, $error_id );
				break;
			case 'url':
				$input_html = static::render_url( $field, $input_id, $error_id );
				break;
			case 'number':
				$input_html = static::render_number( $field, $input_id, $error_id );
				break;
			case 'date':
				$input_html = static::render_date( $field, $input_id, $error_id );
				break;
			case 'textarea':
				$input_html = static::render_textarea( $field, $input_id, $error_id );
				break;
			case 'select':
				$input_html = static::render_select( $field, $input_id, $error_id );
				break;
			case 'radio':
				$input_html = static::render_radio( $field, $input_id, $error_id );
				break;
			case 'checkboxes':
				$input_html = static::render_checkboxes( $field, $input_id, $error_id );
				break;
			case 'checkbox':
				$input_html = static::render_checkbox( $field, $input_id, $error_id );
				break;
			case 'acceptance':
				$input_html = static::render_acceptance( $field, $input_id, $error_id );
				break;
			case 'range':
				$input_html = static::render_range( $field, $input_id, $error_id );
				break;
			case 'hidden':
				$input_html = static::render_hidden( $field, $input_id );
				break;
			case 'file':
				$input_html = static::render_file( $field, $input_id, $error_id );
				break;
			case 'text':
			default:
				$input_html = static::render_text( $field, $input_id, $error_id );
				break;
		}

		// ── Error slot ─────────────────────────────────────────────────────
		// Pre-rendered empty and hidden. On a 422 response, JS removes [hidden],
		// sets textContent, and adds .has-error to the wrapper div.
		//
		// role="alert": announces the error to screen readers when it appears.
		//
		// The id matches aria-describedby on the input, so when the field
		// receives focus after a failed submission the error is read aloud.
		$error_html = '<p'
					. ' class="neural-forms-form__field-error"'
					. ' id="' . esc_attr( $error_id ) . '"'
					. ' role="alert"'
					. ' hidden'
					. '></p>';

		// ── Wrapper ────────────────────────────────────────────────────────
		return '<div'
			. ' class="neural-forms-form__field neural-forms-form__field--' . esc_attr( $type ) . '"'
			. ' data-neural-forms-field-id="' . esc_attr( $field_id ) . '"'
			. $conditional_attr
			. '>'
			. $label_html
			. $input_html
			. $error_html
			. '</div>';
	}

	// -------------------------------------------------------------------------
	// Per-type renderers
	// -------------------------------------------------------------------------

	/**
	 * Single-line text input.
	 */
	private static function render_text( array $field, string $input_id, string $error_id ): string {
		return '<input type="text" ' . static::text_like_attrs( $field, $input_id, $error_id ) . '>';
	}

	/**
	 * Email input.
	 * type="email" triggers the email keyboard on mobile devices.
	 * novalidate on the parent <form> suppresses browser validation popups.
	 */
	private static function render_email( array $field, string $input_id, string $error_id ): string {
		return '<input type="email" ' . static::text_like_attrs( $field, $input_id, $error_id ) . '>';
	}

	/**
	 * Phone input.
	 * type="tel" triggers the phone/numeric keyboard on mobile devices.
	 */
	private static function render_phone( array $field, string $input_id, string $error_id ): string {
		return '<input type="tel" ' . static::text_like_attrs( $field, $input_id, $error_id ) . '>';
	}

	/**
	 * URL input.
	 * type="url" triggers the URL keyboard on mobile devices.
	 */
	private static function render_url( array $field, string $input_id, string $error_id ): string {
		return '<input type="url" ' . static::text_like_attrs( $field, $input_id, $error_id ) . '>';
	}

	/**
	 * Date input. Supports optional min and max date constraints.
	 */
	private static function render_date( array $field, string $input_id, string $error_id ): string {
		$attrs = 'id="' . esc_attr( $input_id ) . '"'
			. ' name="' . esc_attr( $field['id'] ) . '"'
			. ' class="neural-forms-form__input neural-forms-form__input--date"';

		$default = $field['default_value'] ?? '';
		if ( $default !== '' ) {
			$attrs .= ' value="' . esc_attr( (string) $default ) . '"';
		}

		foreach ( [ 'min', 'max' ] as $attr ) {
			if ( isset( $field[ $attr ] ) && $field[ $attr ] !== '' ) {
				$attrs .= ' ' . $attr . '="' . esc_attr( (string) $field[ $attr ] ) . '"';
			}
		}

		if ( ! empty( $field['required'] ) ) {
			$attrs .= ' required aria-required="true"';
		}

		$attrs .= ' aria-describedby="' . esc_attr( $error_id ) . '"';

		return '<input type="date" ' . $attrs . '>';
	}

	/**
	 * Number input. Supports min, max, step schema properties.
	 * These are rendered as HTML attributes; SubmissionValidator enforces the
	 * limits server-side regardless of whether the browser respects them.
	 */
	private static function render_number( array $field, string $input_id, string $error_id ): string {
		$attrs = 'id="' . esc_attr( $input_id ) . '"'
			. ' name="' . esc_attr( $field['id'] ) . '"'
			. ' class="neural-forms-form__input neural-forms-form__input--number"';

		$placeholder = $field['placeholder'] ?? '';
		if ( $placeholder !== '' ) {
			$attrs .= ' placeholder="' . esc_attr( $placeholder ) . '"';
		}

		$default = $field['default_value'] ?? '';
		if ( $default !== '' ) {
			$attrs .= ' value="' . esc_attr( (string) $default ) . '"';
		}

		// min, max, step are optional numeric constraints.
		// Cast to string before esc_attr() to handle integer/float values safely.
		foreach ( [ 'min', 'max', 'step' ] as $attr ) {
			if ( isset( $field[ $attr ] ) && $field[ $attr ] !== '' ) {
				$attrs .= ' ' . $attr . '="' . esc_attr( (string) $field[ $attr ] ) . '"';
			}
		}

		if ( ! empty( $field['required'] ) ) {
			$attrs .= ' required aria-required="true"';
		}

		$attrs .= ' aria-describedby="' . esc_attr( $error_id ) . '"';

		return '<input type="number" ' . $attrs . '>';
	}

	/**
	 * Textarea. Defaults to 3 rows when the 'rows' property is absent or zero.
	 */
	private static function render_textarea( array $field, string $input_id, string $error_id ): string {
		$rows        = ( isset( $field['rows'] ) && (int) $field['rows'] > 0 ) ? (int) $field['rows'] : 3;
		$placeholder = $field['placeholder'] ?? '';
		$default     = $field['default_value'] ?? '';

		$attrs = 'id="' . esc_attr( $input_id ) . '"'
			. ' name="' . esc_attr( $field['id'] ) . '"'
			. ' class="neural-forms-form__textarea"'
			. ' rows="' . $rows . '"';

		if ( $placeholder !== '' ) {
			$attrs .= ' placeholder="' . esc_attr( $placeholder ) . '"';
		}

		if ( ! empty( $field['required'] ) ) {
			$attrs .= ' required aria-required="true"';
		}

		$attrs .= ' aria-describedby="' . esc_attr( $error_id ) . '"';

		// esc_textarea() encodes HTML entities and preserves line breaks —
		// correct for <textarea> content (different from esc_attr/esc_html).
		return '<textarea ' . $attrs . '>' . esc_textarea( $default ) . '</textarea>';
	}

	/**
	 * Select dropdown. Always includes a blank placeholder option (value="")
	 * so a required select starts with no selection and SubmissionValidator
	 * correctly treats the empty value as "nothing selected".
	 */
	private static function render_select( array $field, string $input_id, string $error_id ): string {
		$default = (string) ( $field['default_value'] ?? '' );
		$options = is_array( $field['options'] ) ? $field['options'] : [];

		$attrs = 'id="' . esc_attr( $input_id ) . '"'
			. ' name="' . esc_attr( $field['id'] ) . '"'
			. ' class="neural-forms-form__select"';

		if ( ! empty( $field['required'] ) ) {
			$attrs .= ' required aria-required="true"';
		}

		$attrs .= ' aria-describedby="' . esc_attr( $error_id ) . '"';

		$options_html = '<option value="">' . esc_html__( 'Select…', 'neural-forms' ) . '</option>';

		foreach ( $options as $option ) {
			$option = (array) $option;
			$label  = (string) ( $option['label'] ?? '' );

			// If the builder left the value field empty, use the label as the
			// effective value. This keeps the stored submission value meaningful
			// ("Blue") rather than an empty string that displays as "—" in Entry Detail.
			$raw_val = (string) ( $option['value'] ?? '' );
			$val     = $raw_val !== '' ? $raw_val : $label;

			// Skip options that have neither a value nor a label — nothing useful to render.
			if ( $val === '' ) {
				continue;
			}

			$selected = ( $val === $default ) ? ' selected' : '';

			$options_html .= '<option value="' . esc_attr( $val ) . '"' . $selected . '>'
							. esc_html( $label )
							. '</option>';
		}

		return '<select ' . $attrs . '>' . $options_html . '</select>';
	}

	/**
	 * Radio button group. Renders a set of <input type="radio"> elements,
	 * one per option. Uses the same options format as select fields.
	 */
	private static function render_radio( array $field, string $input_id, string $error_id ): string {
		$default  = (string) ( $field['default_value'] ?? '' );
		$options  = is_array( $field['options'] ?? null ) ? $field['options'] : [];
		$required = ! empty( $field['required'] );

		$html = '<fieldset class="neural-forms-form__radio-group" aria-describedby="' . esc_attr( $error_id ) . '">';

		// Legend doubles as the field's question. Hidden by default so radio
		// groups stay flush with their options; promoted to a visible block
		// label when the builder turned show_label on.
		$lbl        = (string) ( $field['label'] ?? '' );
		$show_label = ! empty( $field['show_label'] );
		if ( $lbl !== '' ) {
			$legend_class  = $show_label
				? 'neural-forms-form__label neural-forms-form__label--block'
				: 'screen-reader-text';
			$required_mark = ( $show_label && $required )
				? ' <span class="neural-forms-form__required" aria-hidden="true">*</span>'
				: '';
			$html .= '<legend class="' . esc_attr( $legend_class ) . '">'
				. esc_html( $lbl )
				. $required_mark
				. '</legend>';
		}

		foreach ( $options as $i => $option ) {
			$option = (array) $option;
			$label  = (string) ( $option['label'] ?? '' );
			$raw_val = (string) ( $option['value'] ?? '' );
			$val     = $raw_val !== '' ? $raw_val : $label;

			if ( $val === '' ) {
				continue;
			}

			$opt_id  = $input_id . '-' . $i;
			$checked = ( $val === $default ) ? ' checked' : '';

			$req_attr = ( $required && $i === 0 ) ? ' required aria-required="true"' : '';

			$html .= '<div class="neural-forms-form__radio-option">'
				. '<input type="radio"'
				. ' id="' . esc_attr( $opt_id ) . '"'
				. ' name="' . esc_attr( $field['id'] ) . '"'
				. ' value="' . esc_attr( $val ) . '"'
				. ' class="neural-forms-form__radio"'
				. $checked
				. $req_attr
				. '>'
				. '<label class="neural-forms-form__label neural-forms-form__label--radio" for="' . esc_attr( $opt_id ) . '">'
				. esc_html( $label )
				. '</label>'
				. '</div>';
		}

		$html .= '</fieldset>';

		return $html;
	}

	/**
	 * Checkbox group. Multiple checkboxes sharing one field name.
	 *
	 * Uses name="fieldId[]" so the browser (and form-submit.js) submits
	 * an array of selected values. Structure mirrors render_radio() — a
	 * <fieldset> wrapping individual checkbox+label pairs.
	 */
	private static function render_checkboxes( array $field, string $input_id, string $error_id ): string {
		$options  = is_array( $field['options'] ?? null ) ? $field['options'] : [];
		$required = ! empty( $field['required'] );

		// default_value may be a JSON-encoded array or a comma-separated string.
		$defaults = $field['default_value'] ?? [];
		if ( is_string( $defaults ) ) {
			$defaults = array_map( 'trim', explode( ',', $defaults ) );
		}
		if ( ! is_array( $defaults ) ) {
			$defaults = [];
		}

		$name = esc_attr( $field['id'] ) . '[]';

		$html = '<fieldset class="neural-forms-form__checkbox-group" aria-describedby="' . esc_attr( $error_id ) . '">';

		// Legend doubles as the field's question. Hidden by default; promoted
		// to a visible block label when the builder turned show_label on.
		$lbl        = (string) ( $field['label'] ?? '' );
		$show_label = ! empty( $field['show_label'] );
		if ( $lbl !== '' ) {
			$legend_class  = $show_label
				? 'neural-forms-form__label neural-forms-form__label--block'
				: 'screen-reader-text';
			$required_mark = ( $show_label && $required )
				? ' <span class="neural-forms-form__required" aria-hidden="true">*</span>'
				: '';
			$html .= '<legend class="' . esc_attr( $legend_class ) . '">'
				. esc_html( $lbl )
				. $required_mark
				. '</legend>';
		}

		foreach ( $options as $i => $option ) {
			$option  = (array) $option;
			$label   = (string) ( $option['label'] ?? '' );
			$raw_val = (string) ( $option['value'] ?? '' );
			$val     = $raw_val !== '' ? $raw_val : $label;

			if ( $val === '' ) {
				continue;
			}

			$opt_id  = $input_id . '-' . $i;
			$checked = in_array( $val, $defaults, true ) ? ' checked' : '';

			$html .= '<div class="neural-forms-form__checkbox-option">'
				. '<input type="checkbox"'
				. ' id="' . esc_attr( $opt_id ) . '"'
				. ' name="' . $name . '"'
				. ' value="' . esc_attr( $val ) . '"'
				. ' class="neural-forms-form__checkbox"'
				. $checked
				. '>'
				. '<label class="neural-forms-form__label neural-forms-form__label--checkbox" for="' . esc_attr( $opt_id ) . '">'
				. esc_html( $label )
				. '</label>'
				. '</div>';
		}

		$html .= '</fieldset>';

		return $html;
	}

	/**
	 * Hidden input. Renders a bare <input type="hidden"> with no visible UI.
	 * The render_field() method short-circuits before building the wrapper/label/error
	 * for this type, so this method returns only the input element.
	 */
	private static function render_hidden( array $field, string $input_id ): string {
		$value = (string) ( $field['default_value'] ?? '' );

		return '<input type="hidden"'
			. ' id="' . esc_attr( $input_id ) . '"'
			. ' name="' . esc_attr( $field['id'] ) . '"'
			. ' value="' . esc_attr( $value ) . '"'
			. '>';
	}

	/**
	 * Checkbox. Input renders before its label — standard UX convention.
	 *
	 * value="1": SubmissionValidator::CHECKED_CHECKBOX_VALUES includes '1' and
	 * int 1 as the "checked" representation. Unchecked checkboxes are not
	 * submitted by the browser in a plain form POST, but form-submit.js
	 * explicitly sends 0 for unchecked boxes in the JSON body.
	 *
	 * The label is rendered here (not in render_field) because it must appear
	 * after the input element for the [checkbox] [label] layout.
	 */
	private static function render_checkbox( array $field, string $input_id, string $error_id ): string {
		$required   = ! empty( $field['required'] );
		$checked    = ! empty( $field['default_value'] );
		$label_text = (string) ( $field['label'] ?? '' );

		$attrs = 'type="checkbox"'
			. ' id="' . esc_attr( $input_id ) . '"'
			. ' name="' . esc_attr( $field['id'] ) . '"'
			. ' value="1"'
			. ' class="neural-forms-form__checkbox"';

		if ( $checked ) {
			$attrs .= ' checked';
		}

		if ( $required ) {
			$attrs .= ' required aria-required="true"';
		}

		$attrs .= ' aria-describedby="' . esc_attr( $error_id ) . '"';

		$required_mark = $required
			? ' <span class="neural-forms-form__required" aria-hidden="true">*</span>'
			: '';

		return '<div class="neural-forms-form__checkbox-wrapper">'
			. '<input ' . $attrs . '>'
			. '<label class="neural-forms-form__label neural-forms-form__label--checkbox" for="' . esc_attr( $input_id ) . '">'
			. esc_html( $label_text )
			. $required_mark
			. '</label>'
			. '</div>';
	}

	/**
	 * Acceptance checkbox. Functionally identical to checkbox but semantically
	 * distinct for CF7 migration compatibility and form builder UX.
	 * Always renders unchecked (no default_value support) — the user must
	 * actively consent.
	 */
	private static function render_acceptance( array $field, string $input_id, string $error_id ): string {
		$required   = ! empty( $field['required'] );
		$label_text = (string) ( $field['label'] ?? '' );

		$attrs = 'type="checkbox"'
			. ' id="' . esc_attr( $input_id ) . '"'
			. ' name="' . esc_attr( $field['id'] ) . '"'
			. ' value="1"'
			. ' class="neural-forms-form__checkbox"';

		if ( $required ) {
			$attrs .= ' required aria-required="true"';
		}

		$attrs .= ' aria-describedby="' . esc_attr( $error_id ) . '"';

		$required_mark = $required
			? ' <span class="neural-forms-form__required" aria-hidden="true">*</span>'
			: '';

		return '<div class="neural-forms-form__checkbox-wrapper">'
			. '<input ' . $attrs . '>'
			. '<label class="neural-forms-form__label neural-forms-form__label--checkbox" for="' . esc_attr( $input_id ) . '">'
			. esc_html( $label_text )
			. $required_mark
			. '</label>'
			. '</div>';
	}

	/**
	 * Range slider input. Supports min, max, step, and default_value.
	 * Displays the current numeric value beside the slider.
	 */
	private static function render_range( array $field, string $input_id, string $error_id ): string {
		$min     = isset( $field['min'] ) && $field['min'] !== '' ? (string) $field['min'] : '0';
		$max     = isset( $field['max'] ) && $field['max'] !== '' ? (string) $field['max'] : '100';
		$step    = isset( $field['step'] ) && $field['step'] !== '' ? (string) $field['step'] : '1';
		$default = isset( $field['default_value'] ) && $field['default_value'] !== '' ? (string) $field['default_value'] : $min;

		$attrs = 'id="' . esc_attr( $input_id ) . '"'
			. ' name="' . esc_attr( $field['id'] ) . '"'
			. ' class="neural-forms-form__input neural-forms-form__input--range"'
			. ' min="' . esc_attr( $min ) . '"'
			. ' max="' . esc_attr( $max ) . '"'
			. ' step="' . esc_attr( $step ) . '"'
			. ' value="' . esc_attr( $default ) . '"';

		if ( ! empty( $field['required'] ) ) {
			$attrs .= ' required aria-required="true"';
		}

		$attrs .= ' aria-describedby="' . esc_attr( $error_id ) . '"';

		$output_id = $input_id . '-output';

		return '<div class="neural-forms-form__range-wrapper">'
			. '<input type="range" ' . $attrs
			. ' aria-valuemin="' . esc_attr( $min ) . '"'
			. ' aria-valuemax="' . esc_attr( $max ) . '"'
			. ' aria-valuenow="' . esc_attr( $default ) . '"'
			. ' oninput="this.setAttribute(\'aria-valuenow\',this.value);document.getElementById(\'' . esc_attr( $output_id ) . '\').textContent=this.value">'
			. '<output id="' . esc_attr( $output_id ) . '" for="' . esc_attr( $input_id ) . '" class="neural-forms-form__range-output">' . esc_html( $default ) . '</output>'
			. '</div>';
	}

	/**
	 * File upload input.
	 *
	 * Renders a single-file input with an optional accept attribute (built
	 * from the field's allowed_types setting) and a data-max-size attribute
	 * used by form-submit.js for client-side size pre-checking.
	 */
	private static function render_file( array $field, string $input_id, string $error_id ): string {
		$allowed_types = is_array( $field['allowed_types'] ?? null ) ? $field['allowed_types'] : [];
		$max_mb        = isset( $field['max_file_size'] ) && (int) $field['max_file_size'] > 0
			? (int) $field['max_file_size']
			: 5;

		$attrs = 'type="file"'
			. ' id="' . esc_attr( $input_id ) . '"'
			. ' name="' . esc_attr( $field['id'] ) . '"'
			. ' class="neural-forms-form__file-input"'
			. ' data-max-size="' . ( $max_mb * 1024 * 1024 ) . '"';   // bytes

		$accept = static::build_accept_attr( $allowed_types );
		if ( $accept !== '' ) {
			$attrs .= ' accept="' . esc_attr( $accept ) . '"';
		}

		if ( ! empty( $field['required'] ) ) {
			$attrs .= ' required aria-required="true"';
		}

		// Build hint text: accepted types + max size.
		$hint_parts = [];
		if ( ! empty( $allowed_types ) ) {
			$type_labels = [
				'image'    => 'JPG, PNG, GIF, WebP',
				'pdf'      => 'PDF',
				'document' => 'DOC, DOCX, ODT',
				'text'     => 'TXT, CSV',
			];
			$labels = array_filter( array_map(
				static fn( $t ) => $type_labels[ $t ] ?? null,
				$allowed_types
			) );
			if ( ! empty( $labels ) ) {
				$hint_parts[] = 'Accepted: ' . implode( ', ', $labels );
			}
		}
		$hint_parts[] = 'Max ' . $max_mb . ' MB';
		$hint_text    = implode( ' · ', $hint_parts );

		// Link both the error container and the hint text to the input via
		// aria-describedby so screen readers announce file constraints on focus.
		$hint_id = $input_id . '-hint';
		$attrs  .= ' aria-describedby="' . esc_attr( $hint_id ) . ' ' . esc_attr( $error_id ) . '"';

		return '<input ' . $attrs . '>'
			. '<p class="neural-forms-form__file-hint" id="' . esc_attr( $hint_id ) . '">' . esc_html( $hint_text ) . '</p>';
	}

	/**
	 * Build the accept attribute string from an array of allowed type group slugs.
	 *
	 * Maps each group slug to the MIME types that WordPress considers valid for
	 * that group. Returns a comma-separated string ready for <input accept="…">,
	 * or an empty string when the array is empty (no restriction).
	 *
	 * @param string[] $allowed_types  e.g. ['image', 'pdf']
	 * @return string
	 */
	private static function build_accept_attr( array $allowed_types ): string {
		$mime_map = [
			'image'    => [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ],
			'pdf'      => [ 'application/pdf' ],
			'document' => [ 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.oasis.opendocument.text' ],
			'text'     => [ 'text/plain', 'text/csv' ],
		];

		if ( empty( $allowed_types ) ) {
			return '';
		}

		$mimes = [];
		foreach ( $allowed_types as $group ) {
			if ( isset( $mime_map[ $group ] ) ) {
				$mimes = array_merge( $mimes, $mime_map[ $group ] );
			}
		}

		return implode( ',', array_unique( $mimes ) );
	}

	// -------------------------------------------------------------------------
	// Shared helpers
	// -------------------------------------------------------------------------

	/**
	 * Build the shared attribute string for single-line text-like inputs:
	 * text, email, and phone.
	 *
	 * Number and textarea carry additional attributes (min/max/step, rows) and
	 * are handled in their own methods. This helper avoids repeating the same
	 * five attributes for three nearly-identical input types.
	 *
	 * @param array  $field
	 * @param string $input_id  Scoped element ID (neural-forms-{form_id}-{field_id}).
	 * @param string $error_id  Matching error paragraph ID for aria-describedby.
	 * @return string           Attribute string ready to inline into <input {attrs}>.
	 */
	private static function text_like_attrs( array $field, string $input_id, string $error_id ): string {
		$attrs = 'id="' . esc_attr( $input_id ) . '"'
			. ' name="' . esc_attr( $field['id'] ) . '"'
			. ' class="neural-forms-form__input"';

		$placeholder = $field['placeholder'] ?? '';
		if ( $placeholder !== '' ) {
			$attrs .= ' placeholder="' . esc_attr( $placeholder ) . '"';
		}

		$default = $field['default_value'] ?? '';
		if ( $default !== '' ) {
			$attrs .= ' value="' . esc_attr( (string) $default ) . '"';
		}

		if ( ! empty( $field['required'] ) ) {
			$attrs .= ' required aria-required="true"';
		}

		// aria-describedby links the input to its error container so screen
		// readers announce the error message when the input receives focus
		// after a failed submission — without the user having to navigate away.
		$attrs .= ' aria-describedby="' . esc_attr( $error_id ) . '"';

		return $attrs;
	}

	/**
	 * Render a diagnostic notice.
	 *
	 * Admins see the full technical message. Non-admin visitors see a brief
	 * user-friendly fallback so they don't encounter a blank gap on the page.
	 *
	 * @param string $message       Admin-facing detail (will be escaped).
	 * @param string $public_text   Visitor-facing fallback (optional; defaults to generic).
	 * @return string
	 */
	private static function admin_notice( string $message, string $public_text = '' ): string {
		if ( current_user_can( 'manage_options' ) ) {
			return '<div class="notice notice-warning inline"><p>' . esc_html( $message ) . '</p></div>';
		}

		if ( $public_text === '' ) {
			$public_text = __( 'This form is currently unavailable. Please try again later.', 'neural-forms' );
		}

		return '<div class="neural-forms-form-wrap"><p class="neural-forms-form-unavailable">'
			. esc_html( $public_text ) . '</p></div>';
	}
}
