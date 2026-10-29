<?php
namespace NeuralForms\Frontend;

use NeuralForms\Core\Capabilities;
use NeuralForms\Core\Config;
use NeuralForms\Forms\ConditionEngine;
use NeuralForms\Forms\FieldRegistry;
use NeuralForms\Forms\FormRepository;
use NeuralForms\Forms\SchemaHelper;

defined( 'ABSPATH' ) || exit;

/**
 * FormRenderer
 *
 * Converts a stored form schema into ready-to-output frontend HTML.
 * Purely presentational - no state mutations, no REST calls, no hooks
 * registered. Every method is stateless.
 *
 * Usage
 *
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
 *
 *   wp_enqueue_style( 'neural-forms-form' ) and wp_enqueue_script( 'neural-forms-form-submit' )
 *   are called from render(). The handles must be registered first by
 *   PublicForms::boot() (Step 3). WordPress deduplicates enqueue calls, so
 *   rendering multiple forms on one page enqueues the assets exactly once.
 *
 * Escaping contract
 *
 *   All user-controlled data (field labels, placeholders, option labels,
 *   default values, success messages) passes through esc_attr() or esc_html()
 *   before being written into the HTML string. The caller may echo the return
 *   value directly - no additional escaping is required or should be applied.
 */
class FormRenderer {

	// Public API

	/**
	 * Render a form by its database ID.
	 *
	 * Returns an empty string (not an exception) when the form is missing or
	 * inactive - safe to echo directly. Admins (manage_options) additionally
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
					__( 'Neural Forms: no form found with ID %d.', 'neural-forms-lite' ),
					$form_id
				)
			);
		}

		if ( $form['status'] !== 'active' ) {
			return static::admin_notice(
				sprintf(
					/* translators: 1: form name  2: status slug */
					__( 'Neural Forms: form "%1$s" is not active (status: %2$s).', 'neural-forms-lite' ),
					$form['name'],
					$form['status']
				)
			);
		}

		// Handles registered by PublicForms::boot() (Step 3).
		// wp_enqueue_* is safe inside shortcode callbacks - the script/style
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
	 * Also skips the status check - drafts should be previewable in the editor.
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

		// No wp_enqueue_style/script - editor context handles its own assets.
		return static::render_form( $form, $options );
	}

	/**
	 * Render a synthetic, in-memory form (no DB lookup) to HTML.
	 *
	 * Used by the builder's Preview action: the React app POSTs the
	 * unsaved schema + settings to /forms/preview, which constructs a
	 * synthetic row and hands it to this method. Identical output to
	 * render() / render_preview(), without enqueueing assets and without
	 * an active-status check.
	 *
	 * The caller is expected to have validated the schema first
	 * (PreviewEndpoint runs FormValidator::validate_schema()).
	 *
	 * @param array $form     Synthetic form row: id, name, status, form_schema, settings.
	 * @param array $options  Render options (e.g. show_labels, show_placeholders).
	 * @return string
	 */
	public static function render_preview_form( array $form, array $options = [] ): string {
		// Defensive defaults so a caller passing a partial array doesn't
		// reach render_form() with missing keys.
		$form += [
			'id'          => 0,
			'name'        => '',
			'status'      => 'active',
			'form_schema' => [],
			'settings'    => [],
		];

		return static::render_form( $form, $options );
	}

	// Form wrapper

	/**
	 * Build the full form HTML from a decoded form array.
	 *
	 * Structure:
	 *   .neural-forms-form-wrap
	 *     form.neural-forms-form[data-neural-forms-id][data-neural-forms-version][novalidate]
	 *       .neural-forms-form__fields
	 *         ... rendered fields ...
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
		$fields   = is_array( $schema['fields'] ?? null )    ? $schema['fields']    : [];
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

		// Success message - uses the new on_success.message if set, then the
		// legacy success_message, then a translated default.
		$legacy_success_msg = (string) ( $settings['success_message'] ?? '' );
		if ( $on_success_message !== '' ) {
			$success_msg = $on_success_message;
		} elseif ( $legacy_success_msg !== '' ) {
			$success_msg = $legacy_success_msg;
		} else {
			$success_msg = __( 'Thank you! Your submission has been received.', 'neural-forms-lite' );
		}

		// Detect and split out the Welcome Screen before iterating the
		// field tree. Welcome Screens are top-level display-only blocks
		// rendered ABOVE the form body; they never recurse into rows /
		// steps / sections. We strip them out of $fields so they don't
		// double-render or get treated as a real field by render_nodes.
		$welcome_screen = SchemaHelper::find_welcome_screen( $fields );
		if ( $welcome_screen !== null ) {
			$fields = array_values( array_filter(
				$fields,
				static function ( $node ) {
					return ! ( is_array( $node ) && ( $node['type'] ?? '' ) === SchemaHelper::WELCOME_SCREEN_TYPE );
				}
			) );

			// Block-level "Show Welcome Screen" toggle (default true).
			// When off, the welcome screen node is still stripped from
			// $fields above (it's never a real field), but its markup
			// and the data-neural-forms-has-welcome wrap flag are
			// skipped so the form body shows immediately.
			if ( ! ( $options['show_welcome_screen'] ?? true ) ) {
				$welcome_screen = null;
			}
		}

		// Render the field tree. A node is either:
		//   - a layout row (type=row) with explicit columns; or
		//   - a flat field, optionally with width=half for pair-rendering.
		// has_files is collected via a single recursive scan so file uploads
		// inside a column still trigger the multipart enctype.
		$has_files   = false;
		$fields_html = static::render_nodes( $fields, $form_id, $options, $has_files );

		// Detect top-level step containers. Used both to flag the form
		// (data-neural-forms-multistep="1") so form-submit.js can opt in
		// cheaply, and to emit the steps-nav buttons just above the
		// submit button further down.
		$top_steps = array_values( array_filter( $fields, static function ( $n ) {
			return is_array( $n ) && ( $n['type'] ?? '' ) === SchemaHelper::STEP_TYPE;
		} ) );
		$has_steps = count( $top_steps ) >= 2;

		// Form-level multi-step settings live on form_schema.settings.
		// (form.settings is reserved for submission/security/email
		// settings; schema-level rendering options belong here so the
		// shape of the form is self-contained.)
		//
		//   form_schema.settings.multistep.progress_indicator
		//     "numbers" | "bar" | "none"   default: "numbers"
		$schema_settings    = is_array( $schema['settings'] ?? null ) ? $schema['settings'] : [];
		$multistep_settings = is_array( $schema_settings['multistep'] ?? null ) ? $schema_settings['multistep'] : [];
		$progress_indicator = (string) ( $multistep_settings['progress_indicator'] ?? 'numbers' );
		if ( ! in_array( $progress_indicator, [ 'numbers', 'bar', 'none' ], true ) ) {
			$progress_indicator = 'numbers';
		}

		// Block-level override. The Neural Multi Step block exposes a
		// SelectControl that lets the page author override the form's
		// stored progress-indicator style PER PLACEMENT — without
		// touching form_schema.settings.multistep.progress_indicator,
		// which other placements / shortcode users still depend on.
		// Only honoured for the well-defined values; an empty string
		// (the "Use form setting" choice) leaves the schema value alone.
		$progress_override = isset( $options['progress_indicator_override'] )
			? (string) $options['progress_indicator_override']
			: '';
		if ( in_array( $progress_override, [ 'numbers', 'bar', 'none' ], true ) ) {
			$progress_indicator = $progress_override;
		}

		$show_progress = $has_steps && $progress_indicator !== 'none';

		// ' Honeypot '
		// Name "neural_forms_website" sounds like a real field to bots but is
		// intentionally generic - avoids "hp" or "honeypot" patterns that
		// sophisticated bots recognise and skip.
		//
		// inline style="display:none" rather than [hidden] attribute: some
		// DOM-scanning bots treat [hidden] as a skip signal; inline style is
		// less likely to be heuristically avoided.
		//
		// tabindex="-1" removes it from keyboard navigation for legitimate users.
		// autocomplete="off" prevents browsers from pre-filling it from saved form data.
		//
		// SubmitEndpoint checks this field at Step 4b: non-empty '->-> spam_detected 400.
		$hp_input_id  = esc_attr( 'neural-forms-' . $form_id . '-website' );
		$fields_html .= '<div class="neural-forms-form__field neural-forms-form__field--honeypot"'
						. ' style="display:none" aria-hidden="true">'
						. '<label for="' . $hp_input_id . '">' . esc_html__( 'Website', 'neural-forms-lite' ) . '</label>'
						. '<input type="text"'
						. ' id="' . $hp_input_id . '"'
						. ' name="neural_forms_website"'
						. ' tabindex="-1"'
						. ' autocomplete="off">'
						. '</div>';

		// ' Assemble '
		// When a welcome screen is present, the wrap gets a data flag so
		// CSS hides the form body until JS removes it (or sets the
		// is-started class). Keeps the no-JS fallback graceful — without
		// JS the welcome screen renders but the form below is also
		// visible, so visitors can still submit.
		$wrap_attrs = ' class="neural-forms-form-wrap"';
		if ( $welcome_screen !== null ) {
			$wrap_attrs .= ' data-neural-forms-has-welcome="1"';
		}
		$html  = '<div' . $wrap_attrs . '>';

		// Welcome Screen — top-level display block rendered before the
		// form body. Hidden by CSS only when JS is available (the wrap's
		// data-has-welcome attribute + a small JS toggle take care of
		// the reveal). No JS = both visible = no broken first-paint.
		if ( $welcome_screen !== null ) {
			$html .= static::render_welcome_screen( $welcome_screen, $form_id, $options );
		}

		// Optional form title - block attribute formTitle. When non-empty,
		// emit it as a heading at the top of the form. The heading level is
		// configurable via the title_level option (h1-h6, default h2).
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
		if ( $has_steps ) {
			$form_attrs .= ' data-neural-forms-multistep="1"';
			$form_attrs .= ' data-neural-forms-step-count="' . count( $top_steps ) . '"';
			$step_transition = isset( $options['step_transition'] ) ? (string) $options['step_transition'] : '';
			if ( $step_transition !== '' ) {
				$form_attrs .= ' data-step-transition="' . esc_attr( $step_transition ) . '"';
				$form_attrs .= ' data-step-transition-dir="' . esc_attr( (string) ( $options['step_transition_direction'] ?? 'auto' ) ) . '"';
				$form_attrs .= ' data-step-transition-duration="' . esc_attr( (string) ( $options['step_transition_duration'] ?? 350 ) ) . '"';
				$form_attrs .= ' data-step-transition-easing="' . esc_attr( (string) ( $options['step_transition_easing'] ?? 'ease' ) ) . '"';
			}
		}

		// On-submit outcome - read by form-submit.js to decide between the
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

		// data-show-poll-results: read by form-submit.js to suppress poll
		// results cards in populateDynamicResults() / renderPollResults()
		// when the block's "Show Poll Results" toggle is off. Uses the
		// <form> element (not the poll div) because render_poll() does not
		// receive $options. Omitted when true (default) to keep the DOM
		// clean for non-poll forms.
		$show_poll_results = ! isset( $options['show_poll_results'] ) || $options['show_poll_results'] !== false;
		if ( ! $show_poll_results ) {
			$form_attrs .= ' data-show-poll-results="0"';
		}

		$html .= '<form' . $form_attrs . '>';

		// Multi-step progress indicator. Rendered ABOVE the fields
		// container so it sits at the top of the form. The active /
		// completed state is purely JS-driven (form-submit.js mirrors
		// `current` into the indicator); without JS the markup shows
		// step 1 active by default and is otherwise inert.
		$progress_html = '';
		if ( $show_progress && $progress_indicator === 'numbers' ) {
			$progress_html .= '<ol class="neural-forms-form__progress neural-forms-form__progress--numbers"'
				. ' data-progress-mode="numbers"'
				. ' aria-label="' . esc_attr__( 'Form progress', 'neural-forms-lite' ) . '">';
			foreach ( $top_steps as $idx => $step ) {
				$step_title = (string) ( $step['title'] ?? '' );
				$item_class = 'neural-forms-form__progress-item' . ( $idx === 0 ? ' is-active' : '' );
				$progress_html .= '<li class="' . esc_attr( $item_class ) . '"'
					. ' data-step-index="' . (int) $idx . '">'
					. '<span class="neural-forms-form__progress-num">' . ( (int) $idx + 1 ) . '</span>';
				if ( $step_title !== '' ) {
					$progress_html .= '<span class="neural-forms-form__progress-label">'
						. esc_html( $step_title ) . '</span>';
				}
				$progress_html .= '</li>';
			}
			$progress_html .= '</ol>';
		} elseif ( $show_progress && $progress_indicator === 'bar' ) {
			$total = count( $top_steps );
			$pct   = $total > 0 ? (int) floor( ( 1 / $total ) * 100 ) : 0;
			$progress_html .= '<div class="neural-forms-form__progress neural-forms-form__progress--bar"'
				. ' data-progress-mode="bar"'
				. ' aria-label="' . esc_attr__( 'Form progress', 'neural-forms-lite' ) . '">'
				. '<div class="neural-forms-form__progress-bar-track" role="progressbar"'
				. ' aria-valuemin="0" aria-valuemax="' . (int) $total . '" aria-valuenow="1">'
				. '<div class="neural-forms-form__progress-bar-fill" style="width:' . (int) $pct . '%"></div>'
				. '</div>'
				. '<p class="neural-forms-form__progress-text">'
				. sprintf(
					/* translators: 1: current step number  2: total steps */
					esc_html__( 'Step %1$d of %2$d', 'neural-forms-lite' ),
					1,
					(int) $total
				)
				. '</p>'
				. '</div>';
		}

		// When the form has steps the progress indicator and step divs are
		// wrapped in an extra __steps-body container so JS step-transition
		// animations can use it as a self-contained clipping/positioning
		// context — nav buttons and submit sit outside it and are unaffected.
		if ( $has_steps ) {
			$html .= '<div class="neural-forms-form__fields">'
				. '<div class="neural-forms-form__steps-body">'
				. $progress_html . $fields_html
				. '</div>'
				. '</div>';
		} else {
			$html .= '<div class="neural-forms-form__fields">' . $progress_html . $fields_html . '</div>';
		}

		// Global (non-field) error container - populated by JS for 429, 5xx,
		// and network failures. role="alert" implies aria-live="assertive" so
		// screen readers announce it immediately when JS sets its text.
		$html .= '<p class="neural-forms-form__global-error" role="alert" hidden></p>';

		// ' CAPTCHA widget '
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
				//
				// These two URLs MUST be loaded from the upstream service -
				// CAPTCHA verification depends on the same origin server-side
				// (challenges.cloudflare.com / www.google.com) issuing the
				// challenge token that the plugin then verifies via siteverify.
				// Self-hosting the SDK is not supported by either provider.
				// Both services are disclosed under "External services" in
				// readme.txt as required for WordPress.org submission.
				//
				// phpcs:disable PluginCheck.CodeAnalysis.EnqueuedResourceOffloading.OffloadedContent
				if ( $captcha_provider === 'turnstile' ) {
					wp_enqueue_script(
						'neural-forms-cf-turnstile',
						'https://challenges.cloudflare.com/turnstile/v0/api.js',
						[],
						NEURAL_FORMS_VERSION,
						true
					);
				} elseif ( $captcha_provider === 'recaptcha_v2' ) {
					wp_enqueue_script(
						'neural-forms-recaptcha-v2',
						'https://www.google.com/recaptcha/api.js',
						[],
						NEURAL_FORMS_VERSION,
						true
					);
				}
				// phpcs:enable PluginCheck.CodeAnalysis.EnqueuedResourceOffloading.OffloadedContent
			}
		}
		// ' end CAPTCHA '

		// Multi-step navigation: emitted only when 2+ top-level step
		// nodes exist (computed near the top of render_form). Without
		// JS, all steps render stacked and the nav buttons sit just
		// above the submit doing nothing (type="button" so they
		// cannot accidentally submit). form-submit.js wires their
		// click handlers + per-step visibility.
		$nav_html = '';
		if ( $has_steps ) {
			// Block-level overrides (set by BlockHandler when called
			// from the Multi Step block) win over per-step settings.
			// An explicit empty string is honoured — the button still
			// renders as an empty <button> so admins can style it as
			// icon-only via CSS without text leaking through.
			$first_prev = array_key_exists( 'prev_button_text', $options )
				? (string) $options['prev_button_text']
				: (string) ( $top_steps[0]['settings']['button_previous'] ?? __( 'Back', 'neural-forms-lite' ) );
			$first_next = array_key_exists( 'next_button_text', $options )
				? (string) $options['next_button_text']
				: (string) ( $top_steps[0]['settings']['button_next']     ?? __( 'Next', 'neural-forms-lite' ) );

			// `wp-element-button` is the WordPress core class that block
			// themes (and many classic themes) apply Button-element
			// styles to via `:where(.wp-element-button)` in theme.json.
			// Adding it here means a Neural Forms block on a page picks
			// up the theme's button color / radius / typography by
			// default — no per-form configuration needed. Per-block
			// btnBgColor / btnTextColor / etc. still win because
			// BlockHandler emits them as direct rules at higher
			// specificity than `:where()`.
			// data-nav-default-next/prev carry the resolved label (block
			// override wins over per-step form settings). data-nav-override-*
			// flags are present when the block path explicitly set the text,
			// so JS knows to ignore per-step labels and always use these.
			$nav_open = '<div class="neural-forms-form__steps-nav" data-neural-forms-steps-nav'
				. ' data-nav-default-next="' . esc_attr( $first_next ) . '"'
				. ' data-nav-default-prev="' . esc_attr( $first_prev ) . '"';
			if ( array_key_exists( 'next_button_text', $options ) ) {
				$nav_open .= ' data-nav-override-next';
			}
			if ( array_key_exists( 'prev_button_text', $options ) ) {
				$nav_open .= ' data-nav-override-prev';
			}
			$nav_open .= '>';
			// ── Nav button icon injection ──────────────────────────────────
			// Build prev button HTML.
			$prev_class = 'neural-forms-form__step-prev wp-element-button';
			$prev_svg   = '';
			if ( ! empty( $options['nav_btn_prev_icon'] ) ) {
				$prev_svg = ButtonIcons::get( (string) $options['nav_btn_prev_icon'] );
			}
			if ( $prev_svg !== '' ) {
				$prev_pos    = (string) ( $options['nav_btn_prev_icon_position'] ?? 'before' );
				$prev_icls   = 'nf-btn-icon nf-btn-icon--' . esc_attr( $prev_pos );
				$prev_class .= ' nf-btn--has-icon';
				if ( ! empty( $options['nav_btn_prev_icon_hover'] ) ) {
					$prev_class  .= ' nf-btn--hover-icon';
					$prev_icls   .= ' nf-btn-icon--hover';
				}
				$prev_icon_html = '<span class="' . $prev_icls . '" aria-hidden="true">' . $prev_svg . '</span>';
				$prev_label_html = '<span class="nf-btn-label">' . esc_html( $first_prev ) . '</span>';
				$prev_content    = $prev_pos === 'before'
					? $prev_icon_html . $prev_label_html
					: $prev_label_html . $prev_icon_html;
			} else {
				$prev_content = esc_html( $first_prev );
			}

			// Build next button HTML.
			$next_class = 'neural-forms-form__step-next wp-element-button';
			$next_svg   = '';
			if ( ! empty( $options['nav_btn_next_icon'] ) ) {
				$next_svg = ButtonIcons::get( (string) $options['nav_btn_next_icon'] );
			}
			if ( $next_svg !== '' ) {
				$next_pos    = (string) ( $options['nav_btn_next_icon_position'] ?? 'after' );
				$next_icls   = 'nf-btn-icon nf-btn-icon--' . esc_attr( $next_pos );
				$next_class .= ' nf-btn--has-icon';
				if ( ! empty( $options['nav_btn_next_icon_hover'] ) ) {
					$next_class  .= ' nf-btn--hover-icon';
					$next_icls   .= ' nf-btn-icon--hover';
				}
				$next_icon_html = '<span class="' . $next_icls . '" aria-hidden="true">' . $next_svg . '</span>';
				$next_label_html = '<span class="nf-btn-label">' . esc_html( $first_next ) . '</span>';
				$next_content    = $next_pos === 'before'
					? $next_icon_html . $next_label_html
					: $next_label_html . $next_icon_html;
			} else {
				$next_content = esc_html( $first_next );
			}

			// Held back and emitted together with the submit wrapper inside
			// .neural-forms-form__steps-footer (see below) so that on the
			// last step Previous and Submit share one row, exactly like
			// Previous / Next do on earlier steps. Before 1.1.3 the submit
			// wrapper was a separate block under the nav row, so the last
			// step stacked Previous above Submit.
			$nav_html = $nav_open
					. '<button type="button" class="' . esc_attr( $prev_class ) . '"'
					. ' data-step-action="prev">'
					. $prev_content
					. '</button>'
					. '<button type="button" class="' . esc_attr( $next_class ) . '"'
					. ' data-step-action="next">'
					. $next_content
					. '</button>'
					. '</div>';
		}

		// `wp-element-button` is the WordPress core class block themes
		// hook Button-element styles to. Adding it here makes the
		// submit button inherit the active theme's button look (color,
		// border-radius, typography) by default. Per-block customisations
		// via btnBgColor / btnTextColor still win because BlockHandler
		// emits them as direct rules at higher specificity than the
		// theme's `:where(.wp-element-button)` rule.
		$submit_class = 'neural-forms-form__submit wp-element-button';
		if ( $has_steps ) {
			// JS hides the submit until the last step is reached.
			// Without JS the submit stays visible (graceful fallback).
			$submit_class .= ' neural-forms-form__submit--multistep';
		}

		// ── Submit button icon injection ────────────────────────────────────
		// Icon SVGs come from the hardcoded ButtonIcons registry — no user
		// content is rendered here. The icon id is validated by ButtonIcons::get()
		// (returns '' for unknown ids).
		$btn_icon_svg = '';
		if ( ! empty( $options['btn_icon'] ) ) {
			$btn_icon_svg = ButtonIcons::get( (string) $options['btn_icon'] );
		}

		// Quiz summary placeholder: one per form, emitted only when the
		// form contains at least one quiz_question. Populated post-
		// submit by form-submit.js with the score / max / percentage.
		// Empty + hidden at SSR so a no-JS render stays clean.
		$has_quiz = false;
		foreach ( SchemaHelper::flatten_fields( $fields ) as $f ) {
			if ( ( $f['type'] ?? '' ) === 'quiz_question' ) { $has_quiz = true; break; }
		}
		if ( $has_quiz ) {
			$show_quiz_results = ! isset( $options['show_quiz_results'] ) || $options['show_quiz_results'] !== false;
			$html .= '<div class="neural-forms-form__quiz-summary"'
				. ' data-quiz-summary'
				. ' data-show-results="' . ( $show_quiz_results ? '1' : '0' ) . '"'
				. ' role="status" aria-live="polite"'
				. ' hidden></div>';
		}

		// Block-level submit-text override. Explicit empty string emits
		// an empty button (icon-only support via theme CSS).
		$submit_text = array_key_exists( 'submit_button_text', $options )
			? (string) $options['submit_button_text']
			: __( 'Submit', 'neural-forms-lite' );

		if ( $btn_icon_svg !== '' ) {
			$btn_icon_pos  = (string) ( $options['btn_icon_position'] ?? 'after' );
			$btn_icon_cls  = 'nf-btn-icon nf-btn-icon--' . esc_attr( $btn_icon_pos );
			$submit_class .= ' nf-btn--has-icon';
			if ( ! empty( $options['btn_icon_hover'] ) ) {
				$submit_class .= ' nf-btn--hover-icon';
				$btn_icon_cls .= ' nf-btn-icon--hover';
			}
			$btn_icon_html   = '<span class="' . $btn_icon_cls . '" aria-hidden="true">' . $btn_icon_svg . '</span>';
			$btn_label_html  = '<span class="nf-btn-label">' . esc_html( $submit_text ) . '</span>';
			$btn_content     = $btn_icon_pos === 'before'
				? $btn_icon_html . $btn_label_html
				: $btn_label_html . $btn_icon_html;
		} else {
			$btn_content = esc_html( $submit_text );
		}

		$submit_wrap_html = '<div class="neural-forms-form__submit-wrap">'
				. '<button type="submit" class="' . esc_attr( $submit_class ) . '">'
				. $btn_content
				. '</button>'
				. '</div>';

		if ( $has_steps ) {
			// Nav row + submit wrapper in one flex footer. The nav keeps
			// its own markup/classes (Divi's scoped CSS targets them), the
			// footer only lays the two out side by side.
			$html .= '<div class="neural-forms-form__steps-footer">'
				. $nav_html
				. $submit_wrap_html
				. '</div>';
		} else {
			$html .= $submit_wrap_html;
		}

		$html .= '</form>';

		// Success state sits outside <form> as a sibling so JS can hide the form
		// (display:none) and show this div independently on a 201 response.
		//
		// The .neural-forms-form__dynamic-results child is populated by
		// form-submit.js with poll bars / quiz score on a successful
		// submission. It lives INSIDE the success block (not inside the
		// form) so it stays visible after the form is hidden — the
		// in-form per-field placeholders are still emitted but become
		// invisible the moment showSuccess() flips form.hidden = true.
		$html .= '<div class="neural-forms-form__success" role="status" aria-live="polite" hidden>'
				. '<p class="neural-forms-form__success-message">' . esc_html( $success_msg ) . '</p>'
				. '<div class="neural-forms-form__dynamic-results" data-neural-forms-dynamic-results hidden></div>'
				. '</div>';

		$html .= '</div>'; // .neural-forms-form-wrap

		return $html;
	}

	// Field dispatcher

	/**
	 * Render a single field: label + input + error container, in a wrapper div.
	 *
	 * The outer div carries data-neural-forms-field-id so form-submit.js can locate
	 * the error slot for any field by its schema ID without coupling itself to
	 * the internal markup layout.
	 *
	 * @param array $field    Decoded field definition from form_schema['fields'].
	 * @param int   $form_id  Parent form ID - used to scope element IDs.
	 * @param array $options  Reserved for future per-field render options.
	 * @return string
	 */
	/**
	 * Walk a node array (flat fields, layout rows, or a mix), emit HTML.
	 *
	 * has_files is set to true (via reference) the first time a file
	 * field is encountered anywhere in the tree, so render_form() can
	 * decide whether to add the data-neural-forms-has-files attribute.
	 *
	 * @param array $nodes
	 * @param int   $form_id
	 * @param array $options
	 * @param bool  $has_files  By-ref out flag.
	 * @return string
	 */
	private static function render_nodes( array $nodes, int $form_id, array $options, bool &$has_files, bool $is_top_level = true ): string {
		$html       = '';
		$count      = count( $nodes );
		$step_index = 0; // Running counter of top-level step nodes.

		// Pair-only half-width back-compat: when two consecutive flat
		// fields both have width=half (no row wrapper around them), wrap
		// them in a 2-up grid the same way 1.0.0 did. This only triggers
		// for legacy schemas - new schemas use explicit row nodes.
		for ( $i = 0; $i < $count; $i++ ) {
			$node = (array) $nodes[ $i ];

			if ( $is_top_level && SchemaHelper::is_step( $node ) ) {
				$html .= static::render_step( $node, $step_index, $form_id, $options, $has_files );
				$step_index++;
				continue;
			}

			if ( SchemaHelper::is_row( $node ) ) {
				$html .= static::render_row( $node, $form_id, $options, $has_files );
				continue;
			}

			if ( SchemaHelper::is_section( $node ) ) {
				$html .= static::render_section( $node );
				continue;
			}

			if ( empty( $node['id'] ) || empty( $node['type'] ) ) {
				continue;
			}

			if ( $node['type'] === 'file' ) {
				$has_files = true;
			}

			// Legacy half-width pairing.
			$next = isset( $nodes[ $i + 1 ] ) ? (array) $nodes[ $i + 1 ] : null;
			$pair = $node['type'] !== 'hidden'
				&& FieldRegistry::normalize_width( $node['width'] ?? '' ) === FieldRegistry::WIDTH_HALF
				&& $next !== null
				&& ! SchemaHelper::is_row( $next )
				&& ( $next['type'] ?? '' ) !== 'hidden'
				&& FieldRegistry::normalize_width( $next['width'] ?? '' ) === FieldRegistry::WIDTH_HALF;

			if ( $pair ) {
				if ( ( $next['type'] ?? '' ) === 'file' ) {
					$has_files = true;
				}
				$html .= '<div class="neural-forms-form__row neural-forms-form__row--cols-2">';
				$html .= '<div class="neural-forms-form__row__column">' . static::render_field( $node, $form_id, $options ) . '</div>';
				$html .= '<div class="neural-forms-form__row__column">' . static::render_field( $next, $form_id, $options ) . '</div>';
				$html .= '</div>';
				$i++;
				continue;
			}

			// Isolated half / full / hidden: render directly.
			$html .= static::render_field( $node, $form_id, $options );
		}

		return $html;
	}

	/**
	 * Render a layout step: title + description + body of fields/rows.
	 *
	 * Steps are top-level only. Their inner fields[] is recursed via
	 * render_nodes() with $is_top_level=false so the recursive call
	 * never re-enters the step branch.
	 *
	 * data-step-index is consumed by form-submit.js multi-step nav to
	 * show one step at a time. Without JS, every step renders stacked
	 * (the .is-active CSS toggle is JS-only) - the form remains
	 * fully usable in graceful-degradation mode.
	 */
	/**
	 * Render a layout section: a visual divider / heading + optional
	 * description block. Sections are leaves - they never contain
	 * fields. The output is purely presentational; no input ever
	 * appears here, so section ids never become entry data.
	 *
	 * Title-less sections still emit the wrapper so styling that
	 * relies on the section being a sibling of a field (e.g. a thin
	 * divider line via ::before) still works. An empty section is a
	 * deliberate visual break.
	 */
	/**
	 * Render the Welcome Screen — a top-level display block that
	 * shows a title, description, and Start button before the actual
	 * form fields. Clicking Start reveals the form body via JS.
	 *
	 * Settings (all optional, with sane fallbacks):
	 *   - title              (string)  default "Let's get started"
	 *   - description        (string)  default ""
	 *   - button_text        (string)  default "Start"
	 *   - settings.alignment        "left" | "center" | "right" — default "center"
	 *   - settings.background_color  CSS colour, default unset
	 *   - settings.text_color        CSS colour, default unset
	 *   - settings.button_background CSS colour, default unset
	 *   - settings.button_text_color CSS colour, default unset
	 *   - settings.full_height       boolean — when true, the welcome
	 *                                screen takes full viewport height
	 *
	 * The Welcome Screen never appears in submission data, entries,
	 * exports, AI analysis, or notifications — it's a display element,
	 * not a field.
	 */
	private static function render_welcome_screen( array $node, int $form_id, array $options = [] ): string {
		$node_id     = (string) ( $node['id'] ?? '' );
		$title       = (string) ( $node['title'] ?? '' );
		$description = (string) ( $node['description'] ?? '' );
		$button_text = (string) ( $node['buttonText'] ?? $node['button_text'] ?? '' );
		if ( $title === '' ) {
			$title = __( "Let's get started", 'neural-forms-lite' );
		}
		if ( $button_text === '' ) {
			$button_text = __( 'Start', 'neural-forms-lite' );
		}

		$settings   = is_array( $node['settings'] ?? null ) ? $node['settings'] : [];
		$alignment  = (string) ( $settings['alignment'] ?? 'center' );
		if ( ! in_array( $alignment, [ 'left', 'center', 'right' ], true ) ) {
			$alignment = 'center';
		}

		$classes = 'neural-forms-form__welcome neural-forms-form__welcome--align-' . $alignment;

		$html  = '<div class="' . esc_attr( $classes ) . '"';
		if ( $node_id !== '' ) {
			$html .= ' data-neural-forms-welcome-id="' . esc_attr( $node_id ) . '"';
		}
		$html .= ' data-neural-forms-welcome-for="' . (int) $form_id . '">';
		$welcome_title_lvl = strtolower( (string) ( $options['welcome_title_level'] ?? 'h2' ) );
		if ( ! in_array( $welcome_title_lvl, [ 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ], true ) ) {
			$welcome_title_lvl = 'h2';
		}

		$html .= '<div class="neural-forms-form__welcome-inner">';
		$html .= '<' . $welcome_title_lvl . ' class="neural-forms-form__welcome-title">' . esc_html( $title ) . '</' . $welcome_title_lvl . '>';
		if ( $description !== '' ) {
			$html .= '<p class="neural-forms-form__welcome-desc">' . esc_html( $description ) . '</p>';
		}
		$welcome_btn_icon_svg = '';
		if ( ! empty( $options['welcome_btn_icon'] ) ) {
			$welcome_btn_icon_svg = ButtonIcons::get( (string) $options['welcome_btn_icon'] );
		}

		$welcome_btn_class = 'neural-forms-form__welcome-btn';
		if ( $welcome_btn_icon_svg !== '' ) {
			$welcome_btn_icon_pos = (string) ( $options['welcome_btn_icon_position'] ?? 'after' );
			$welcome_btn_icon_cls = 'nf-btn-icon nf-btn-icon--' . esc_attr( $welcome_btn_icon_pos );
			$welcome_btn_class   .= ' nf-btn--has-icon';
			if ( ! empty( $options['welcome_btn_icon_hover'] ) ) {
				$welcome_btn_class .= ' nf-btn--hover-icon';
				$welcome_btn_icon_cls .= ' nf-btn-icon--hover';
			}
			$welcome_btn_icon_html  = '<span class="' . $welcome_btn_icon_cls . '" aria-hidden="true">' . $welcome_btn_icon_svg . '</span>';
			$welcome_btn_label_html = '<span class="nf-btn-label">' . esc_html( $button_text ) . '</span>';
			$welcome_btn_content    = $welcome_btn_icon_pos === 'before'
				? $welcome_btn_icon_html . $welcome_btn_label_html
				: $welcome_btn_label_html . $welcome_btn_icon_html;
		} else {
			$welcome_btn_content = esc_html( $button_text );
		}

		$html .= '<button type="button"'
			. ' class="' . esc_attr( $welcome_btn_class ) . '"'
			. ' data-neural-forms-welcome-start="1">'
			. $welcome_btn_content
			. '</button>';
		$html .= '</div>';
		$html .= '</div>';
		return $html;
	}

	private static function render_section( array $section ): string {
		$style = (string) ( $section['settings']['style'] ?? SchemaHelper::DEFAULT_SECTION_STYLE );
		if ( ! in_array( $style, SchemaHelper::VALID_SECTION_STYLES, true ) ) {
			$style = SchemaHelper::DEFAULT_SECTION_STYLE;
		}

		$section_id  = (string) ( $section['id'] ?? '' );
		$title       = (string) ( $section['title'] ?? '' );
		$description = (string) ( $section['description'] ?? '' );

		$class = 'neural-forms-form__section neural-forms-form__section--' . $style;

		$html  = '<div class="' . esc_attr( $class ) . '"';
		if ( $section_id !== '' ) {
			$html .= ' data-neural-forms-section-id="' . esc_attr( $section_id ) . '"';
		}
		// 1.2.0: a section (heading block) can be shown/hidden by logic.
		$cl = $section['conditional_logic'] ?? null;
		if ( is_array( $cl ) && ! empty( $cl['enabled'] ) && ConditionEngine::is_active( $cl ) ) {
			$html .= ' data-conditional="' . esc_attr( (string) wp_json_encode( ConditionEngine::for_attribute( $cl ) ) ) . '"';
		}
		$html .= '>';
		if ( $title !== '' ) {
			$html .= '<h3 class="neural-forms-form__section-title">' . esc_html( $title ) . '</h3>';
		}
		if ( $description !== '' ) {
			$html .= '<p class="neural-forms-form__section-description">' . esc_html( $description ) . '</p>';
		}
		$html .= '</div>';
		return $html;
	}

	private static function render_step( array $step, int $step_index, int $form_id, array $options, bool &$has_files ): string {
		$step_id = (string) ( $step['id'] ?? '' );
		$title   = (string) ( $step['title'] ?? '' );
		$desc    = (string) ( $step['description'] ?? '' );
		$btn_next = (string) ( $step['settings']['button_next']     ?? '' );
		$btn_prev = (string) ( $step['settings']['button_previous'] ?? '' );

		$html  = '<div class="neural-forms-form__step"';
		$html .= ' data-step-index="' . (int) $step_index . '"';
		if ( $step_id !== '' ) {
			$html .= ' data-neural-forms-step-id="' . esc_attr( $step_id ) . '"';
		}
		if ( $btn_next !== '' ) {
			$html .= ' data-step-button-next="' . esc_attr( $btn_next ) . '"';
		}
		if ( $btn_prev !== '' ) {
			$html .= ' data-step-button-previous="' . esc_attr( $btn_prev ) . '"';
		}
		// 1.2.0: step eligibility. Deliberately NOT data-conditional —
		// inactive steps are display:none too, and the serializer treats
		// a display:none [data-conditional] ancestor as "hidden by logic".
		// form-submit.js reads data-conditional-step and marks skipped
		// steps with .neural-forms-form__step--skipped instead.
		$cl = $step['conditional_logic'] ?? null;
		if ( is_array( $cl ) && ! empty( $cl['enabled'] ) && ConditionEngine::is_active( $cl ) ) {
			$html .= ' data-conditional-step="' . esc_attr( (string) wp_json_encode( ConditionEngine::for_attribute( $cl ) ) ) . '"';
		}
		$html .= '>';

		if ( $title !== '' && ( $options['show_step_labels'] ?? true ) ) {
			$html .= '<h3 class="neural-forms-form__step-title">' . esc_html( $title ) . '</h3>';
		}
		if ( $desc !== '' && ( $options['show_step_descriptions'] ?? true ) ) {
			$html .= '<p class="neural-forms-form__step-description">' . esc_html( $desc ) . '</p>';
		}

		$html .= '<div class="neural-forms-form__step-body">';
		$inner_nodes = is_array( $step['fields'] ?? null ) ? $step['fields'] : [];
		// is_top_level=false so a malformed nested step is rendered as
		// a no-op (the validator already rejects them on save).
		$html .= static::render_nodes( $inner_nodes, $form_id, $options, $has_files, false );
		$html .= '</div>';

		$html .= '</div>';
		return $html;
	}

	/**
	 * Render a layout row with N columns. Empty columns are still emitted
	 * so the grid layout is preserved when an admin spawns a row but
	 * hasn't dropped fields into every cell yet.
	 */
	private static function render_row( array $row, int $form_id, array $options, bool &$has_files ): string {
		$col_count = SchemaHelper::normalize_column_count(
			$row['settings']['columns'] ?? count( is_array( $row['columns'] ?? null ) ? $row['columns'] : [] )
		);

		$gap = (string) ( $row['settings']['gap'] ?? 'medium' );
		if ( ! in_array( $gap, SchemaHelper::VALID_GAPS, true ) ) {
			$gap = 'medium';
		}

		$row_id = (string) ( $row['id'] ?? '' );

		$html  = '<div class="neural-forms-form__row'
			. ' neural-forms-form__row--cols-' . $col_count
			. ' neural-forms-form__row--gap-' . esc_attr( $gap ) . '"';
		if ( $row_id !== '' ) {
			$html .= ' data-neural-forms-row-id="' . esc_attr( $row_id ) . '"';
		}
		$html .= '>';

		$columns = is_array( $row['columns'] ?? null ) ? array_values( $row['columns'] ) : [];

		// Render only the declared count - excess columns in the schema
		// (left over from a column-count-shrink that hasn't been saved
		// yet) are folded into the last visible column so no field is
		// ever silently dropped.
		$visible = array_slice( $columns, 0, $col_count );
		$tail    = array_slice( $columns, $col_count );

		if ( ! empty( $tail ) && ! empty( $visible ) ) {
			$last_idx = count( $visible ) - 1;
			$visible[ $last_idx ]['fields'] = is_array( $visible[ $last_idx ]['fields'] ?? null )
				? $visible[ $last_idx ]['fields']
				: [];
			foreach ( $tail as $tail_col ) {
				$tail_fields = is_array( $tail_col['fields'] ?? null ) ? $tail_col['fields'] : [];
				$visible[ $last_idx ]['fields'] = array_merge( $visible[ $last_idx ]['fields'], $tail_fields );
			}
		}

		// Pad with empty columns when settings.columns > count(columns).
		// $visible grows inside the loop — count() must be re-evaluated
		// each iteration so the padding stops at the new target.
		// phpcs:ignore Squiz.PHP.DisallowSizeFunctionsInLoops.Found
		while ( count( $visible ) < $col_count ) {
			$visible[] = [ 'id' => '', 'fields' => [] ];
		}

		foreach ( $visible as $col ) {
			$col      = (array) $col;
			$col_id   = (string) ( $col['id'] ?? '' );
			$col_html = '<div class="neural-forms-form__row__column"';
			if ( $col_id !== '' ) {
				$col_html .= ' data-neural-forms-col-id="' . esc_attr( $col_id ) . '"';
			}
			$col_html .= '>';

			$col_fields = is_array( $col['fields'] ?? null ) ? $col['fields'] : [];
			$col_html  .= static::render_nodes( $col_fields, $form_id, $options, $has_files );
			$col_html  .= '</div>';

			$html .= $col_html;
		}

		$html .= '</div>';
		return $html;
	}

	private static function render_field( array $field, int $form_id, array $options = [] ): string {
		$type     = $field['type'];
		$field_id = $field['id'];
		$required = ! empty( $field['required'] );

		// Label / placeholder resolution:
		//   * show_labels (block-level)  '->-> render every field's label visibly
		//     above its input. Falls back to per-field $field['show_label']
		//     when the option is absent (e.g. shortcode usage).
		//   * show_placeholders (block-level, default true) '->-> render the
		//     placeholder. When false, no field gets a placeholder.
		//   * Placeholder source: user-defined $field['placeholder'] wins.
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

		// Field types whose label can NOT be carried by a placeholder: a
		// radio / checkbox / rating / matrix / repeater group has no input
		// to put a placeholder in, and a file input ignores placeholders.
		// Hiding their label hid the QUESTION ("Ticket type" above three
		// radios), so these always render it visibly regardless of the
		// block-level toggle — the same rule poll / quiz_question already
		// follow. Text-like fields keep the label-as-placeholder behaviour.
		$label_always_visible = in_array( $type, [ 'radio', 'checkboxes', 'rating', 'matrix', 'repeater', 'file' ], true );

		// Persist the resolved show_label back onto the field so per-type
		// renderers (radio / checkboxes) that don't receive $options pick up
		// the same value.
		$field['show_label'] = $show_label || $label_always_visible;

		// Hidden fields render as a bare <input type="hidden"> with no wrapper,
		// label, or error slot - they are invisible to the user.
		if ( $type === 'hidden' ) {
			$input_id = 'neural-forms-' . $form_id . '-' . $field_id;
			return static::render_hidden( $field, $input_id );
		}

		// ' Conditional logic data attribute '
		// When a field has active conditional logic, emit the normalised
		// v2 tree (ConditionEngine::for_attribute) so form-submit.js can
		// evaluate visibility client-side without knowing the full schema.
		// Legacy single-rule objects are normalised here, so the frontend
		// only ever sees the v2 shape.
		$conditional_attr = '';
		$cl = $field['conditional_logic'] ?? null;
		if ( is_array( $cl ) && ! empty( $cl['enabled'] ) && ConditionEngine::is_active( $cl ) ) {
			$conditional_attr = ' data-conditional="' . esc_attr( (string) wp_json_encode( ConditionEngine::for_attribute( $cl ) ) ) . '"';
		}

		// Scoped IDs: neural-forms-{form_id}-{field_id}
		// Prevents ID collisions when two or more forms appear on the same page.
		$input_id = 'neural-forms-' . $form_id . '-' . $field_id;
		$error_id = $input_id . '-error';

		// Field-level label.
		// * Default: empty - the label is carried by the placeholder.
		// * show_label = true: emit a visible <label> above the input. Skipped
		//   for checkbox / acceptance (their inline label already serves as
		//   the question) and for radio / checkboxes (their renderers expose
		//   the label as a visible <legend> instead).
		// * show_label = false: emit a visually hidden <label> instead, so
		//   (a) assistive tech gets the real accessible name — a placeholder
		//   is not one — and (b) form-submit.js fieldLabelText() can build
		//   "Full name is required." instead of naming the placeholder
		//   ("Jane Cooper is required.").
		$label_html   = '';
		$owns_legend  = in_array( $type, [ 'checkbox', 'acceptance', 'radio', 'checkboxes', 'rating', 'poll', 'quiz_question', 'matrix', 'repeater' ], true );
		if ( $lbl !== '' && ! $owns_legend ) {
			if ( $show_label || $label_always_visible ) {
				$required_mark = $required
					? ' <span class="neural-forms-form__required" aria-hidden="true">*</span>'
					: '';
				$label_html = '<label class="neural-forms-form__label neural-forms-form__label--block" for="' . esc_attr( $input_id ) . '">'
							. esc_html( $lbl )
							. $required_mark
							. '</label>';
			} else {
				$label_html = '<label class="screen-reader-text" for="' . esc_attr( $input_id ) . '">'
							. esc_html( $lbl )
							. '</label>';
			}
		}

		// ' Input '
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
			case 'rating':
				$input_html = static::render_rating( $field, $input_id, $error_id );
				break;
			case 'poll':
				$input_html = static::render_poll( $field, $input_id, $error_id, $form_id );
				break;
			case 'quiz_question':
				$input_html = static::render_quiz_question( $field, $input_id, $error_id );
				break;
			case 'matrix':
				$input_html = static::render_matrix( $field, $input_id, $error_id, $form_id );
				break;
			case 'repeater':
				$input_html = static::render_repeater( $field, $input_id, $error_id, $form_id, $options );
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

		// ' Error slot '
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

		// ' Help text '
		// The builder's "Help text" box (field.help_text) was saved and
		// previewed but never rendered before 1.1.3. Emitted under the
		// input and linked via aria-describedby (prepended to the error
		// id every per-type renderer already sets) so assistive tech
		// reads it on focus.
		$help_html = '';
		$help_text = trim( (string) ( $field['help_text'] ?? '' ) );
		if ( $help_text !== '' ) {
			$help_id    = $input_id . '-help';
			$help_html  = '<p class="neural-forms-form__field-help" id="' . esc_attr( $help_id ) . '">'
				. esc_html( $help_text )
				. '</p>';
			$input_html = (string) preg_replace(
				'/(aria-describedby="[^"]*)' . preg_quote( $error_id, '/' ) . '"/',
				'$1' . esc_attr( $help_id ) . ' ' . $error_id . '"',
				$input_html,
				1
			);
		}

		// ' Wrapper '
		return '<div'
			. ' class="neural-forms-form__field neural-forms-form__field--' . esc_attr( $type ) . '"'
			. ' data-neural-forms-field-id="' . esc_attr( $field_id ) . '"'
			. $conditional_attr
			. '>'
			. $label_html
			. $input_html
			. $help_html
			. $error_html
			. '</div>';
	}

	// Per-type renderers

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
	 *
	 * The input is always wrapped in a country picker (flag button +
	 * searchable dropdown + tel input). The picker is built
	 * progressively by form-submit.js on init — server-side we just
	 * emit the wrapper, the default country's ISO code, and an empty
	 * placeholder flag span; JS inflates the dropdown list and wires
	 * the open/select handlers.
	 *
	 * Without JS, the wrapper still degrades to a usable tel input
	 * (the flag button is purely decorative and the dropdown is
	 * hidden), so submissions still work.
	 */
	private static function render_phone( array $field, string $input_id, string $error_id ): string {
		$settings = is_array( $field['settings'] ?? null ) ? $field['settings'] : array();

		$default_country = strtoupper( (string) ( $settings['default_country'] ?? 'US' ) );
		// 2-letter ISO only (regional-indicator emoji generation needs
		// exactly two A-Z letters). Fall back to US for malformed data.
		if ( ! preg_match( '/^[A-Z]{2}$/', $default_country ) ) {
			$default_country = 'US';
		}

		// We render the tel input with the standard text_like_attrs so
		// every other behaviour (required, placeholder, value, conditional
		// logic) keeps working. JS adds the dial-code prefix on init.
		$tel_attrs = static::text_like_attrs( $field, $input_id, $error_id );

		$html  = '<div class="neural-forms-form__phone"'
			. ' data-neural-forms-phone="1"'
			. ' data-default-country="' . esc_attr( $default_country ) . '">';
		$html .= '<button type="button"'
			. ' class="neural-forms-form__phone-flag"'
			. ' aria-haspopup="listbox"'
			. ' aria-expanded="false"'
			. ' aria-label="' . esc_attr__( 'Select country', 'neural-forms-lite' ) . '"'
			. ' tabindex="0">';
		$html .= '<span class="neural-forms-form__phone-flag-emoji" aria-hidden="true"></span>';
		$html .= '<span class="neural-forms-form__phone-flag-chevron" aria-hidden="true">▾</span>';
		$html .= '</button>';
		$html .= '<input type="tel" ' . $tel_attrs . '>';
		// Dropdown is rendered empty at SSR — form-submit.js populates
		// it on first open from the in-JS country list so a no-JS
		// visitor doesn't pay the markup cost.
		$html .= '<div class="neural-forms-form__phone-dropdown" hidden role="dialog">';
		$html .= '<input type="text"'
			. ' class="neural-forms-form__phone-search"'
			. ' placeholder="' . esc_attr__( 'Search country', 'neural-forms-lite' ) . '"'
			. ' aria-label="' . esc_attr__( 'Search country', 'neural-forms-lite' ) . '"'
			. ' autocomplete="off">';
		$html .= '<ul class="neural-forms-form__phone-list" role="listbox"></ul>';
		$html .= '</div>';
		$html .= '</div>';

		return $html;
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

		// esc_textarea() encodes HTML entities and preserves line breaks -
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
		$options = is_array( $field['options'] ?? null ) ? $field['options'] : [];

		$attrs = 'id="' . esc_attr( $input_id ) . '"'
			. ' name="' . esc_attr( $field['id'] ) . '"'
			. ' class="neural-forms-form__select"';

		if ( ! empty( $field['required'] ) ) {
			$attrs .= ' required aria-required="true"';
		}

		$attrs .= ' aria-describedby="' . esc_attr( $error_id ) . '"';

		$options_html = '<option value="">' . esc_html__( 'Select...', 'neural-forms-lite' ) . '</option>';

		foreach ( $options as $option ) {
			$option = (array) $option;
			$label  = (string) ( $option['label'] ?? '' );

			// If the builder left the value field empty, use the label as the
			// effective value. This keeps the stored submission value meaningful
			// ("Blue") rather than an empty string that displays as "-" in Entry Detail.
			$raw_val = (string) ( $option['value'] ?? '' );
			$val     = $raw_val !== '' ? $raw_val : $label;

			// Skip options that have neither a value nor a label - nothing useful to render.
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

		// Legend doubles as the field's question. render_field() forces
		// show_label on for radio groups (a hidden legend hid the question
		// entirely — there's no placeholder to carry it), so this is
		// visible unless the field has no label at all.
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
	 * an array of selected values. Structure mirrors render_radio() - a
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
	 * Checkbox. Input renders before its label - standard UX convention.
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
	 * Always renders unchecked (no default_value support) - the user must
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
			. ' oninput="this.setAttribute(\'aria-valuenow\',this.value);document.getElementById(\'' . esc_js( $output_id ) . '\').textContent=this.value">'
			. '<output id="' . esc_attr( $output_id ) . '" for="' . esc_attr( $input_id ) . '" class="neural-forms-form__range-output">' . esc_html( $default ) . '</output>'
			. '</div>';
	}

	/**
	 * Star rating input. Renders a fieldset of N radio inputs (1..stars)
	 * with star-glyph labels. Same field name on every radio so the
	 * submitted value is the chosen star count as a numeric string.
	 *
	 * Stars count is clamped to 1..10. Default 5 when missing/invalid.
	 */
	private static function render_rating( array $field, string $input_id, string $error_id ): string {
		$stars = isset( $field['stars'] ) ? (int) $field['stars'] : 5;
		if ( $stars < 1 ) { $stars = 1; }
		if ( $stars > 10 ) { $stars = 10; }

		$required = ! empty( $field['required'] );
		$default  = isset( $field['default_value'] ) && $field['default_value'] !== '' ? (int) $field['default_value'] : 0;

		$html = '<fieldset class="neural-forms-form__rating-group"'
			. ' data-stars="' . (int) $stars . '"'
			. ' aria-describedby="' . esc_attr( $error_id ) . '">';

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

		$html .= '<div class="neural-forms-form__rating">';
		for ( $i = 1; $i <= $stars; $i++ ) {
			$opt_id  = $input_id . '-' . $i;
			$checked = ( $default === $i ) ? ' checked' : '';
			$req_attr = ( $required && $i === 1 ) ? ' required aria-required="true"' : '';

			$html .= '<label class="neural-forms-form__rating-star" for="' . esc_attr( $opt_id ) . '">'
				. '<input type="radio"'
				. ' id="' . esc_attr( $opt_id ) . '"'
				. ' name="' . esc_attr( $field['id'] ) . '"'
				. ' value="' . (int) $i . '"'
				. ' class="neural-forms-form__rating-input"'
				. $checked
				. $req_attr
				. '>'
				. '<span class="neural-forms-form__rating-glyph" aria-hidden="true">&#9733;</span>'
				. '<span class="screen-reader-text">'
				. sprintf(
					/* translators: %d: star number */
					esc_html__( '%d stars', 'neural-forms-lite' ),
					(int) $i
				)
				. '</span>'
				. '</label>';
		}
		$html .= '</div>';
		$html .= '</fieldset>';

		return $html;
	}

	/**
	 * Poll field. Renders accessible radio inputs for each configured
	 * option (single-choice in v1) plus an empty results placeholder
	 * div when results_visibility !== 'never'. The placeholder is
	 * populated by JS in Phase 3; Phase 1 emits it empty so the
	 * markup contract is fixed.
	 */
	private static function render_poll( array $field, string $input_id, string $error_id, int $form_id = 0 ): string {
		$default  = (string) ( $field['default_value'] ?? '' );
		$options  = is_array( $field['options'] ?? null ) ? $field['options'] : [];
		$required = ! empty( $field['required'] );
		$settings = SchemaHelper::normalize_poll_settings( $field['settings'] ?? null );

		// Randomize: same contract as render_quiz_question — server-side
		// shuffle for no-JS, data attribute for the per-visitor JS shuffle.
		$randomize_attr = '';
		if ( $settings['randomize_options'] ) {
			shuffle( $options );
			$randomize_attr = ' data-poll-randomize="1"';
		}

		$html = '<fieldset class="neural-forms-form__poll"'
			. $randomize_attr
			. ' aria-describedby="' . esc_attr( $error_id ) . '">';

		// Poll question label is the field's content (the question
		// itself). Same reasoning as render_quiz_question: always
		// render visibly when set; the block-level `show_labels`
		// toggle controls labels on text-like fields, but a poll
		// question has no placeholder fallback — hide it and the
		// visitor sees orphan radio options.
		$lbl = (string) ( $field['label'] ?? '' );
		if ( $lbl !== '' ) {
			$required_mark = $required
				? ' <span class="neural-forms-form__required" aria-hidden="true">*</span>'
				: '';
			$html .= '<legend class="neural-forms-form__label neural-forms-form__label--block">'
				. esc_html( $lbl )
				. $required_mark
				. '</legend>';
		}

		$html .= '<div class="neural-forms-form__poll-options">';

		foreach ( $options as $i => $option ) {
			$option = (array) $option;
			$label   = (string) ( $option['label'] ?? '' );
			$raw_val = (string) ( $option['value'] ?? '' );
			$val     = $raw_val !== '' ? $raw_val : $label;

			if ( $val === '' ) {
				continue;
			}

			$opt_id  = $input_id . '-' . $i;
			$checked = ( $val === $default ) ? ' checked' : '';
			$req_attr = ( $required && $i === 0 ) ? ' required aria-required="true"' : '';

			// Whole option row is a <label> wrapping the radio + the
			// option text so clicking anywhere inside the padded /
			// bordered area selects the radio (same rationale as
			// render_quiz_question).
			$html .= '<label class="neural-forms-form__poll-option" for="' . esc_attr( $opt_id ) . '">'
				. '<input type="radio"'
				. ' id="' . esc_attr( $opt_id ) . '"'
				. ' name="' . esc_attr( $field['id'] ) . '"'
				. ' value="' . esc_attr( $val ) . '"'
				. ' class="neural-forms-form__poll-input"'
				. $checked
				. $req_attr
				. '>'
				. '<span class="neural-forms-form__label neural-forms-form__label--poll">'
				. esc_html( $label )
				. '</span>'
				. '</label>';
		}

		$html .= '</div>';

		// Results placeholder.
		//   never      — omitted entirely.
		//   after_vote — emitted empty + [hidden]; populated by JS on
		//                a successful submit response.
		//   always     — server-rendered with the current totals so the
		//                form is functional without JS.
		if ( $settings['results_visibility'] !== 'never' ) {
			$attrs  = ' data-poll-results="' . esc_attr( $field['id'] ) . '"';
			$attrs .= ' data-poll-visibility="' . esc_attr( $settings['results_visibility'] ) . '"';
			$attrs .= ' data-poll-chart="' . esc_attr( $settings['chart_type'] ) . '"';

			$inner = '';
			$is_visible = false;

			if ( $settings['results_visibility'] === 'always' && $form_id > 0 ) {
				$inner = static::render_poll_results_html( $form_id, $field, $settings );
				$is_visible = true;
			}

			$html .= '<div class="neural-forms-form__poll-results"'
				. $attrs
				. ( $is_visible ? '' : ' hidden' )
				. '>'
				. $inner
				. '</div>';
		}

		$html .= '</fieldset>';

		return $html;
	}

	/**
	 * Server-render the inner HTML for a poll-results panel. Used for
	 * results_visibility='always' at SSR time so the form is useful
	 * without JS. The same markup contract is honoured by the JS path
	 * in form-submit.js when populating after_vote results.
	 *
	 * Output shape:
	 *
	 *   <p class="neural-forms-form__poll-total">Total votes: N</p>
	 *   <ul class="neural-forms-form__poll-bars">
	 *     <li class="neural-forms-form__poll-bar">
	 *       <div class="neural-forms-form__poll-bar-head">
	 *         <span class="...__poll-bar-label">…</span>
	 *         <span class="...__poll-bar-count">12 (60%)</span>
	 *       </div>
	 *       <div class="neural-forms-form__poll-bar-track">
	 *         <div class="neural-forms-form__poll-bar-fill" style="width:60%"></div>
	 *       </div>
	 *     </li>
	 *     …
	 *   </ul>
	 *
	 * For chart_type='percentage', the bar track is omitted and only
	 * the head row (label + count/percent) is rendered.
	 */
	private static function render_poll_results_html( int $form_id, array $field, array $settings ): string {
		$field_id = (string) ( $field['id'] ?? '' );
		if ( $form_id <= 0 || $field_id === '' ) {
			return '';
		}

		$agg    = \NeuralForms\Storage\EntryRepository::aggregate_poll_votes( $form_id, $field_id );
		$total  = (int) ( $agg['total']  ?? 0 );
		$counts = is_array( $agg['counts'] ?? null ) ? $agg['counts'] : [];

		$options = is_array( $field['options'] ?? null ) ? $field['options'] : [];
		$known   = [];
		$rows    = [];

		foreach ( $options as $opt ) {
			$opt   = (array) $opt;
			$label = (string) ( $opt['label'] ?? '' );
			$raw   = (string) ( $opt['value'] ?? '' );
			$value = $raw !== '' ? $raw : $label;
			if ( $value === '' ) continue;

			$known[] = $value;
			$votes   = (int) ( $counts[ $value ] ?? 0 );
			$pct     = $total > 0 ? round( ( $votes / $total ) * 100, 2 ) : 0.0;

			$rows[] = [
				'label' => $label !== '' ? $label : $value,
				'votes' => $votes,
				'pct'   => $pct,
			];
		}

		$other_votes = 0;
		foreach ( $counts as $value => $votes ) {
			if ( ! in_array( (string) $value, $known, true ) ) {
				$other_votes += (int) $votes;
			}
		}
		if ( $other_votes > 0 ) {
			$rows[] = [
				'label' => __( 'Other', 'neural-forms-lite' ),
				'votes' => $other_votes,
				'pct'   => $total > 0 ? round( ( $other_votes / $total ) * 100, 2 ) : 0.0,
			];
		}

		$chart = $settings['chart_type'] ?? 'bar';

		$html  = '<p class="neural-forms-form__poll-total">'
			. sprintf(
				/* translators: %d: total vote count */
				esc_html__( 'Total votes: %d', 'neural-forms-lite' ),
				$total
			)
			. '</p>';

		$html .= '<ul class="neural-forms-form__poll-bars" data-poll-chart="' . esc_attr( $chart ) . '">';
		foreach ( $rows as $r ) {
			$pct_attr = max( 0, min( 100, (float) $r['pct'] ) );
			$html .= '<li class="neural-forms-form__poll-bar">'
				. '<div class="neural-forms-form__poll-bar-head">'
				. '<span class="neural-forms-form__poll-bar-label">' . esc_html( $r['label'] ) . '</span>'
				. '<span class="neural-forms-form__poll-bar-count">'
				. sprintf(
					/* translators: 1: vote count, 2: percentage */
					esc_html__( '%1$d (%2$s%%)', 'neural-forms-lite' ),
					(int) $r['votes'],
					(string) $r['pct']
				)
				. '</span>'
				. '</div>';
			if ( $chart !== 'percentage' ) {
				$html .= '<div class="neural-forms-form__poll-bar-track" aria-hidden="true">'
					. '<div class="neural-forms-form__poll-bar-fill" style="width:' . $pct_attr . '%"></div>'
					. '</div>';
			}
			$html .= '</li>';
		}
		$html .= '</ul>';

		return $html;
	}

	/**
	 * Quiz Question. Renders accessible radio inputs (single-choice)
	 * + an empty hidden feedback placeholder populated by
	 * form-submit.js after a successful submit.
	 *
	 * Privacy contract: this method MUST NEVER emit `is_correct` or
	 * any value that would let a client-side inspector identify the
	 * correct option before submitting. Score computation is server-
	 * side only; the response carries the verdict back to the JS,
	 * never the schema.
	 */
	private static function render_quiz_question( array $field, string $input_id, string $error_id ): string {
		$default  = (string) ( $field['default_value'] ?? '' );
		$options  = is_array( $field['options'] ?? null ) ? $field['options'] : [];
		$required = ! empty( $field['required'] );
		$settings = SchemaHelper::normalize_quiz_settings( $field['settings'] ?? null );

		// Randomize: shuffle server-side so the setting works without JS,
		// AND flag the fieldset so form-submit.js re-shuffles per visitor —
		// a page cache would otherwise freeze one server-side order.
		$randomize_attr = '';
		if ( $settings['randomize_options'] ) {
			shuffle( $options );
			$randomize_attr = ' data-quiz-randomize="1"';
		}

		$html = '<fieldset class="neural-forms-form__quiz"'
			. $randomize_attr
			. ' aria-describedby="' . esc_attr( $error_id ) . '">';

		// Quiz question label is the field's content (the question
		// itself). Always render visibly when set — the block-level
		// `show_labels` toggle controls labels on text-like fields
		// (where the placeholder can carry the hint instead), but
		// for a quiz/poll question there is no alternative carrier:
		// hide it and the visitor sees just orphan radio options.
		// Admins who want to suppress the question text can set
		// label to an empty string.
		$lbl = (string) ( $field['label'] ?? '' );
		if ( $lbl !== '' ) {
			$required_mark = $required
				? ' <span class="neural-forms-form__required" aria-hidden="true">*</span>'
				: '';
			$html .= '<legend class="neural-forms-form__label neural-forms-form__label--block">'
				. esc_html( $lbl )
				. $required_mark
				. '</legend>';
		}

		$html .= '<div class="neural-forms-form__quiz-options">';

		foreach ( $options as $i => $option ) {
			$option = (array) $option;
			$label   = (string) ( $option['label'] ?? '' );
			$raw_val = (string) ( $option['value'] ?? '' );
			$val     = $raw_val !== '' ? $raw_val : $label;

			if ( $val === '' ) {
				continue;
			}

			$opt_id  = $input_id . '-' . $i;
			$checked = ( $val === $default ) ? ' checked' : '';
			$req_attr = ( $required && $i === 0 ) ? ' required aria-required="true"' : '';

			// is_correct INTENTIONALLY NOT EMITTED: it would leak the
			// answer to anyone with browser devtools. Score is computed
			// server-side; the submit response carries the verdict.
			//
			// The whole option row is a <label> wrapping the radio +
			// option text so clicking ANYWHERE inside the padded /
			// bordered option box selects the radio (admins added
			// large padding to options via the design panel; clicking
			// only the small radio circle felt fiddly).
			$html .= '<label class="neural-forms-form__quiz-option" for="' . esc_attr( $opt_id ) . '">'
				. '<input type="radio"'
				. ' id="' . esc_attr( $opt_id ) . '"'
				. ' name="' . esc_attr( $field['id'] ) . '"'
				. ' value="' . esc_attr( $val ) . '"'
				. ' class="neural-forms-form__quiz-input"'
				. $checked
				. $req_attr
				. '>'
				. '<span class="neural-forms-form__label neural-forms-form__label--quiz">'
				. esc_html( $label )
				. '</span>'
				. '</label>';
		}

		$html .= '</div>';

		// Feedback placeholder. Populated post-submit; empty + hidden
		// at SSR so a JS-disabled visitor sees a clean form.
		$html .= '<div class="neural-forms-form__quiz-feedback"'
			. ' data-quiz-feedback="' . esc_attr( $field['id'] ) . '"'
			. ' hidden></div>';

		$html .= '</fieldset>';

		return $html;
	}

	/**
	 * Matrix field — survey-style row-by-column grid.
	 *
	 * Two selection modes:
	 *   - radio:    one column per row.  Posted as $_POST[field_id][row_value] = "col_value".
	 *   - checkbox: many columns per row. Posted as $_POST[field_id][row_value][] = ["a","b",...].
	 *
	 * The wire format intentionally nests under the field id so the existing
	 * SubmitEndpoint::array_intersect_key( $raw_body, schema_ids ) gate keeps
	 * working with no pipeline changes — Lite already treats per-field values
	 * as either scalar or array (checkboxes do exactly this) and the matrix
	 * just adds a second array level keyed by row.
	 *
	 * Pro-deactivated fallback:
	 *   - If the FIELD_MATRIX capability is locked, no inputs are rendered. An
	 *     admin viewer (manage_options) sees a one-line notice; public
	 *     visitors see nothing at all. The rest of the form continues to
	 *     render normally — the matrix field is silently skipped.
	 *
	 * Server-side validation lands in Matrix commit 4; for now `required` on
	 * radio rows uses the first cell of each row group to ask the browser to
	 * enforce one selection per row. Checkbox required is intentionally not
	 * browser-enforced (the browser cannot express "at least one per row"
	 * across a name array) and will be enforced server-side in commit 4.
	 */
	// $form_id kept on the signature for parity with render_repeater so
	// the dispatch table in render() can pass it uniformly to every
	// composite-field renderer; matrix doesn't currently need it for
	// the simple-radio / checkbox cases, but reserving the slot avoids
	// a churny refactor when matrix gains form-scoped behaviour later.
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	private static function render_matrix( array $field, string $input_id, string $error_id, int $form_id ): string {
		// Pro-deactivated fallback. Stay silent for visitors so the rest of
		// the form keeps working; show a one-line, admin-only notice so
		// the site owner notices the missing field.
		if ( ! Capabilities::can( Capabilities::FIELD_MATRIX ) ) {
			if ( current_user_can( 'manage_options' ) ) {
				return '<p class="neural-forms-matrix__notice" role="status">'
					. esc_html__( 'Matrix field hidden — requires Neural Forms Pro. (Visible to administrators only.)', 'neural-forms-lite' )
					. '</p>';
			}
			return '';
		}

		$settings = is_array( $field['settings'] ?? null ) ? $field['settings'] : [];
		$sel_type = ( ( $settings['selection_type'] ?? 'radio' ) === 'checkbox' ) ? 'checkbox' : 'radio';
		$rows_raw = is_array( $settings['rows'] ?? null )    ? $settings['rows']    : [];
		$cols_raw = is_array( $settings['columns'] ?? null ) ? $settings['columns'] : [];
		$required = ! empty( $field['required'] );
		$field_id = (string) $field['id'];

		// Drop empty rows/columns. The builder allows partial state mid-edit;
		// the renderer must never emit an input with no value / no name.
		$rows = array_values( array_filter( $rows_raw, static function ( $r ) {
			if ( ! is_array( $r ) ) {
				return false;
			}
			$v = (string) ( $r['value'] ?? '' );
			$l = (string) ( $r['label'] ?? '' );
			return $v !== '' || $l !== '';
		} ) );
		$cols = array_values( array_filter( $cols_raw, static function ( $c ) {
			if ( ! is_array( $c ) ) {
				return false;
			}
			$v = (string) ( $c['value'] ?? '' );
			$l = (string) ( $c['label'] ?? '' );
			return $v !== '' || $l !== '';
		} ) );

		if ( empty( $rows ) || empty( $cols ) ) {
			// Misconfigured matrix: render nothing rather than fatal. The
			// inspector already nudges authors to add rows/cols.
			return '';
		}

		$lbl        = (string) ( $field['label'] ?? '' );
		$show_label = ! empty( $field['show_label'] );

		$html = '<fieldset class="neural-forms-matrix" aria-describedby="' . esc_attr( $error_id ) . '">';

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

		$html .= '<div class="neural-forms-matrix__scroll">';
		$html .= '<table class="neural-forms-matrix__table">';

		// Column header row.
		$html .= '<thead><tr>';
		$html .= '<th class="neural-forms-matrix__corner" scope="col"></th>';
		foreach ( $cols as $col ) {
			$col_label = (string) ( $col['label'] ?? '' );
			if ( $col_label === '' ) {
				$col_label = (string) ( $col['value'] ?? '' );
			}
			$html .= '<th class="neural-forms-matrix__col" scope="col">'
				. esc_html( $col_label )
				. '</th>';
		}
		$html .= '</tr></thead>';

		// Body rows.
		$html .= '<tbody>';
		foreach ( $rows as $ri => $row ) {
			$row_value = (string) ( $row['value'] ?? '' );
			$row_label = (string) ( $row['label'] ?? '' );
			if ( $row_value === '' ) {
				$row_value = $row_label;
			}
			if ( $row_label === '' ) {
				$row_label = $row_value;
			}

			$name = ( 'checkbox' === $sel_type )
				? esc_attr( $field_id ) . '[' . esc_attr( $row_value ) . '][]'
				: esc_attr( $field_id ) . '[' . esc_attr( $row_value ) . ']';

			$html .= '<tr class="neural-forms-matrix__row">';
			$html .= '<th class="neural-forms-matrix__row-label" scope="row">'
				. esc_html( $row_label )
				. '</th>';

			foreach ( $cols as $ci => $col ) {
				$col_value = (string) ( $col['value'] ?? '' );
				$col_label = (string) ( $col['label'] ?? '' );
				if ( $col_value === '' ) {
					$col_value = $col_label;
				}
				if ( $col_label === '' ) {
					$col_label = $col_value;
				}

				// Cell id: neural-forms-{form_id}-{field_id}-{row_value}-{col_value}.
				// sanitize_html_class keeps the DOM id valid even when the
				// row/column slug carries hyphens or mixed case.
				$cell_id  = $input_id . '-' . sanitize_html_class( $row_value ) . '-' . sanitize_html_class( $col_value );
				$aria_lbl = trim( $lbl . ' — ' . $row_label . ' — ' . $col_label );

				// Required attribute strategy:
				//   - radio:    mark first column in each row required so
				//               the browser enforces "one per row" client-
				//               side. The HTML radio group is row-scoped,
				//               so marking one input requires the group.
				//   - checkbox: not browser-enforceable across rows; left
				//               for commit 4 server validation.
				$req_attr = '';
				if ( $required && 'radio' === $sel_type && 0 === $ci ) {
					$req_attr = ' required aria-required="true"';
				}

				$html .= '<td class="neural-forms-matrix__cell">'
					. '<label class="neural-forms-matrix__cell-label" for="' . esc_attr( $cell_id ) . '">'
					. '<span class="screen-reader-text">' . esc_html( $aria_lbl ) . '</span>'
					. '<input'
					. ' type="' . esc_attr( $sel_type ) . '"'
					. ' id="' . esc_attr( $cell_id ) . '"'
					. ' name="' . $name . '"'
					. ' value="' . esc_attr( $col_value ) . '"'
					. ' class="neural-forms-matrix__input"'
					. $req_attr
					. '>'
					. '</label>'
					. '</td>';
			}
			$html .= '</tr>';
		}
		$html .= '</tbody>';
		$html .= '</table>';
		$html .= '</div>';

		$html .= '</fieldset>';

		return $html;
	}

	/**
	 * Repeater field — repeatable group of child fields.
	 *
	 * Wire format
	 * - Each rendered iteration scopes its child inputs under
	 *     <repeater_id>[<index>][<child_id>]
	 *   and checkbox-array children under
	 *     <repeater_id>[<index>][<child_id>][]
	 *   so the existing SubmitEndpoint pipeline (array_intersect_key on
	 *   the top-level repeater id; nested-array survives) accepts it
	 *   without endpoint changes for the leaf-children path.
	 * - File children render the same <input type="file"> markup as
	 *   a regular file field. Server-side nested file processing (the
	 *   $_FILES parallel-array shape PHP emits for bracketed file
	 *   names) lands in Commit 4 — until that ships, repeater file
	 *   children submit but get dropped on the server because
	 *   process_file_fields() only indexes flat top-level ids.
	 *
	 * Initial item count
	 *   max( 1, min_items ) iterations rendered server-side. The
	 *   browser then lets the visitor add up to max_items more.
	 *
	 * Add / remove
	 *   form-submit.js binds delegated click handlers on
	 *   data-action="repeater-add" / data-action="repeater-remove".
	 *   On add, the <template> below is cloned and __INDEX__ is
	 *   substituted with the new iteration index. On remove, every
	 *   remaining input is reindexed via its data-name-template
	 *   and data-id-template so name + id stay contiguous and per-
	 *   row radio groups stay independent.
	 *
	 * Pro-deactivated fallback
	 *   When FIELD_REPEATER is locked, no inputs render. Admin
	 *   viewers see a one-line "requires Neural Forms Pro" notice;
	 *   public visitors see nothing. The rest of the form keeps
	 *   working — the repeater field is silently skipped.
	 */
	private static function render_repeater( array $field, string $input_id, string $error_id, int $form_id, array $options = [] ): string {
		// Pro-deactivated fallback. Stay silent for visitors.
		if ( ! Capabilities::can( Capabilities::FIELD_REPEATER ) ) {
			if ( current_user_can( 'manage_options' ) ) {
				return '<p class="neural-forms-repeater__notice" role="status">'
					. esc_html__( 'Repeater field hidden — requires Neural Forms Pro. (Visible to administrators only.)', 'neural-forms-lite' )
					. '</p>';
			}
			return '';
		}

		$settings = is_array( $field['settings'] ?? null ) ? $field['settings'] : [];
		$min      = isset( $settings['min_items'] ) ? (int) $settings['min_items'] : 1;
		$max      = isset( $settings['max_items'] ) ? (int) $settings['max_items'] : 5;
		$cols_in  = isset( $settings['columns'] )   ? (int) $settings['columns']   : 1;

		if ( $min < 0 ) $min = 0;
		if ( $max < 1 ) $max = 1;
		if ( $max > 20 ) $max = 20;
		if ( $max < $min ) $max = $min;
		$columns  = ( $cols_in >= 1 && $cols_in <= 4 ) ? $cols_in : 1;

		$add_label    = (string) ( $settings['add_button_label']    ?? '' );
		$remove_label = (string) ( $settings['remove_button_label'] ?? '' );
		if ( $add_label === '' )    $add_label    = __( 'Add item', 'neural-forms-lite' );
		if ( $remove_label === '' ) $remove_label = __( 'Remove', 'neural-forms-lite' );
		// Block-level placement override wins over per-field label.
		if ( array_key_exists( 'repeater_add_btn_text', $options ) && (string) $options['repeater_add_btn_text'] !== '' ) {
			$add_label = (string) $options['repeater_add_btn_text'];
		}

		$children = is_array( $field['fields'] ?? null ) ? $field['fields'] : [];
		// Drop malformed / blocked children defensively — the schema
		// validator already enforces this on save, but a manually-
		// edited DB row could carry stale entries.
		$children = array_values( array_filter( $children, static function ( $c ) {
			return is_array( $c ) && ! empty( $c['id'] ) && ! empty( $c['type'] );
		} ) );

		if ( empty( $children ) ) {
			// Repeater with no children template — render nothing useful.
			if ( current_user_can( 'manage_options' ) ) {
				return '<p class="neural-forms-repeater__notice" role="status">'
					. esc_html__( 'Repeater has no child fields configured. (Visible to administrators only.)', 'neural-forms-lite' )
					. '</p>';
			}
			return '';
		}

		$field_id      = (string) $field['id'];
		$repeater_attr = esc_attr( $field_id );
		$initial_count = max( 1, $min );
		// Initial items capped at max_items so a misconfigured
		// min_items > max_items can't render more than max.
		if ( $initial_count > $max ) $initial_count = $max;

		$lbl        = (string) ( $field['label'] ?? '' );
		$show_label = ! empty( $field['show_label'] );
		$required   = ! empty( $field['required'] );

		$html  = '<fieldset class="neural-forms-repeater"'
			. ' data-neural-forms-repeater="' . $repeater_attr . '"'
			. ' data-min="' . esc_attr( (string) $min ) . '"'
			. ' data-max="' . esc_attr( (string) $max ) . '"'
			. ' data-columns="' . esc_attr( (string) $columns ) . '"'
			// Visible numbering pattern. The literal "__INDEX_1__"
			// gets substituted by form-submit.js#stampRepeaterIndex
			// with the 1-based human index on every add / remove.
			// Server-rendered items get the substitution done in
			// render_repeater_item below.
			. ' data-item-num-template="' . esc_attr( '__INDEX_1__.' ) . '"'
			. ' aria-describedby="' . esc_attr( $error_id ) . '"'
			. '>';

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

		$html .= '<ol class="neural-forms-repeater__list">';
		for ( $i = 0; $i < $initial_count; $i++ ) {
			$html .= static::render_repeater_item( $field_id, $children, $i, $form_id, $columns, $remove_label, false );
		}
		$html .= '</ol>';

		// The <template> carries the __INDEX__ placeholder. form-submit.js
		// clones .content on Add and replaces __INDEX__ / __INDEX_1__
		// (0-based numeric / 1-based human) with the new iteration index
		// across every name= / id= / for= / data-* attribute.
		$html .= '<template class="neural-forms-repeater__template"'
			. ' data-template-for="' . $repeater_attr . '">';
		$html .= static::render_repeater_item( $field_id, $children, '__INDEX__', $form_id, $columns, $remove_label, true );
		$html .= '</template>';

		// Add button — visibility toggled by JS against data-max vs.
		// current list length.
		$html .= '<button type="button"'
			. ' class="neural-forms-repeater__add"'
			. ' data-action="repeater-add"'
			. ' data-target="' . $repeater_attr . '"'
			. '>+ ' . esc_html( $add_label ) . '</button>';

		$html .= '</fieldset>';

		return $html;
	}

	/**
	 * Render a single Repeater iteration <li>. Re-used for both the
	 * server-rendered initial items and the hidden <template>: when
	 * `$is_template === true` the index is the literal "__INDEX__"
	 * placeholder string that form-submit.js substitutes on add.
	 *
	 * @param string     $repeater_id  Parent repeater field id.
	 * @param array      $children     Children template list.
	 * @param int|string $index        0-based index, OR "__INDEX__".
	 * @param int        $form_id      Form id (used for scoped input id).
	 * @param int        $columns      1..4 grid columns.
	 * @param string     $remove_label Pre-i18n'd remove button label.
	 * @param bool       $is_template  True when rendering the hidden template.
	 */
	private static function render_repeater_item(
		string $repeater_id,
		array $children,
		$index,
		int $form_id,
		int $columns,
		string $remove_label,
		bool $is_template
	): string {
		// "__INDEX__" in the literal template; otherwise the 0-based
		// numeric index. Used directly in name / id strings — both
		// callers pre-sanitise via esc_attr().
		$idx_str = (string) $index;
		// 1-based human label for the "Item N" header. The template
		// stores __INDEX_1__ so the JS substitutes (idx + 1) at
		// runtime; for server-rendered items we compute it here.
		$idx_human = $is_template ? '__INDEX_1__' : (string) ( ( (int) $index ) + 1 );

		$html  = '<li class="neural-forms-repeater__item"'
			. ' data-repeater-item';
		if ( ! $is_template ) {
			$html .= ' data-index="' . esc_attr( $idx_str ) . '"';
		}
		$html .= '>';

		$html .= '<header class="neural-forms-repeater__item-head">';
		// Visible numbering — just "1.", "2.", etc. The
		// data-item-num attribute is the JS reindexer's hook; the
		// template literal "__INDEX_1__" is left in place inside
		// the hidden <template> below and substituted at clone time.
		$html .= '<span class="neural-forms-repeater__item-num" data-item-num>'
			. esc_html( $idx_human . '.' )
			. '</span>';
		$html .= '<button type="button"'
			. ' class="neural-forms-repeater__remove"'
			. ' data-action="repeater-remove"'
			. ' data-target="' . esc_attr( $repeater_id ) . '"'
			. '>' . esc_html( $remove_label ) . '</button>';
		$html .= '</header>';

		$html .= '<div class="neural-forms-repeater__grid neural-forms-repeater__grid--cols-'
			. esc_attr( (string) $columns )
			. '">';
		foreach ( $children as $child ) {
			$html .= static::render_repeater_child( $child, $repeater_id, $idx_str, $form_id );
		}
		$html .= '</div>';

		$html .= '</li>';
		return $html;
	}

	/**
	 * Render one child field inside a Repeater iteration.
	 *
	 * The child's id is rewritten to the scoped bracketed name
	 * (`<repeater_id>[<index>][<child_id>]`) and its scoped input id
	 * is built as `neural-forms-{form_id}-{repeater_id}-{index}-{child_id}`.
	 * Each input emitted by the per-type renderer carries an extra
	 * data-name-template / data-id-template attribute (also injected
	 * here) so the JS reindexer can rewrite them when the user adds
	 * or removes an iteration. The data-* templates use the literal
	 * "__INDEX__" placeholder so the JS string-replace is unambiguous.
	 *
	 * Children template-types that aren't in the v1 leaf whitelist
	 * are silently skipped — FormValidator already enforces the
	 * allowlist at save time, but this is defence-in-depth.
	 */
	private static function render_repeater_child( array $child, string $repeater_id, string $index, int $form_id ): string {
		$child_id   = (string) ( $child['id']   ?? '' );
		$child_type = (string) ( $child['type'] ?? '' );
		if ( $child_id === '' || $child_type === '' ) return '';

		// v1 leaf whitelist. Mirrors FormValidator::REPEATER_ALLOWED_CHILD_TYPES.
		$allowed = [ 'text','email','phone','tel','number','url','textarea','select','radio','checkbox','checkboxes','date','file' ];
		if ( ! in_array( $child_type, $allowed, true ) ) return '';

		// Scoped wire names + ids.
		$is_template_index = ( $index === '__INDEX__' );
		$scoped_name       = $repeater_id . '[' . $index . '][' . $child_id . ']';
		$scoped_input_id   = 'neural-forms-' . $form_id . '-' . $repeater_id . '-' . $index . '-' . $child_id;
		$scoped_error_id   = $scoped_input_id . '-error';
		// Template attributes for the JS reindexer — both use the
		// literal __INDEX__ placeholder regardless of current index.
		$name_tpl     = $repeater_id . '[__INDEX__][' . $child_id . ']';
		$id_tpl       = 'neural-forms-' . $form_id . '-' . $repeater_id . '-__INDEX__-' . $child_id;
		$error_id_tpl = $id_tpl . '-error';

		// Build the inner input via the existing per-type renderer.
		// Pass a CLONE of the child field with its id set to the
		// scoped bracketed name; the renderers use $field['id'] for
		// the input's `name` attribute and the supplied $input_id for
		// the input's `id` attribute.
		$scoped = $child;
		$scoped['id']            = $scoped_name;
		$scoped['placeholder']   = (string) ( $child['placeholder'] ?? '' );
		// Per-iteration `name` for radio + checkboxes must include the
		// row index so each iteration has its own radio group / array
		// bucket. render_radio / render_checkboxes already build their
		// `name` from $field['id'], so the scoped id above is enough.

		switch ( $child_type ) {
			case 'email':                 $inner = static::render_email(     $scoped, $scoped_input_id, $scoped_error_id ); break;
			case 'phone': case 'tel':     $inner = static::render_phone(     $scoped, $scoped_input_id, $scoped_error_id ); break;
			case 'url':                   $inner = static::render_url(       $scoped, $scoped_input_id, $scoped_error_id ); break;
			case 'number':                $inner = static::render_number(    $scoped, $scoped_input_id, $scoped_error_id ); break;
			case 'date':                  $inner = static::render_date(      $scoped, $scoped_input_id, $scoped_error_id ); break;
			case 'textarea':              $inner = static::render_textarea(  $scoped, $scoped_input_id, $scoped_error_id ); break;
			case 'select':                $inner = static::render_select(    $scoped, $scoped_input_id, $scoped_error_id ); break;
			case 'radio':                 $inner = static::render_radio(     $scoped, $scoped_input_id, $scoped_error_id ); break;
			case 'checkboxes':            $inner = static::render_checkboxes( $scoped, $scoped_input_id, $scoped_error_id ); break;
			case 'checkbox':              $inner = static::render_checkbox(  $scoped, $scoped_input_id, $scoped_error_id ); break;
			case 'file':                  $inner = static::render_file(      $scoped, $scoped_input_id, $scoped_error_id ); break;
			case 'text': default:         $inner = static::render_text(      $scoped, $scoped_input_id, $scoped_error_id ); break;
		}

		// Inject data-name-template / data-id-template on every named
		// element in the rendered fragment so the JS reindexer can
		// rewrite name + id deterministically. This is a post-process
		// pass to avoid teaching every per-type renderer about
		// repeater context.
		$inner = static::inject_repeater_templates( $inner, $name_tpl, $id_tpl, $error_id_tpl );

		// Label sits OUTSIDE the input so the visual hierarchy reads
		// label-then-input, mirroring the standard render_field()
		// shape. We can't reuse render_field directly because it adds
		// a wrapper with the field id as data-attr — we want the
		// scoped id instead.
		$label = (string) ( $child['label'] ?? '' );
		$req   = ! empty( $child['required'] );
		$label_html = '';
		if ( $label !== '' ) {
			$req_mark = $req ? ' <span class="neural-forms-form__required" aria-hidden="true">*</span>' : '';
			// Match the parent form's label style.
			$label_html = '<label class="neural-forms-form__label neural-forms-form__label--block" for="'
				. esc_attr( $scoped_input_id ) . '">'
				. esc_html( $label )
				. $req_mark
				. '</label>';
		}

		$wrap_class = 'neural-forms-form__field neural-forms-form__field--' . esc_attr( $child_type )
			. ' neural-forms-repeater__child';

		return '<div class="' . $wrap_class . '"'
			. ' data-neural-forms-field-id="' . esc_attr( $child_id ) . '"'
			. '>'
			. $label_html
			. $inner
			. '<p class="neural-forms-form__field-error" id="' . esc_attr( $scoped_error_id ) . '"'
			. ' data-error-id-template="' . esc_attr( $error_id_tpl ) . '"'
			. ' role="alert" hidden></p>'
			. '</div>';
	}

	/**
	 * Stamp every named input + every for= attribute + every id=
	 * attribute in the rendered child fragment with the matching
	 * data-name-template / data-id-template / data-for-template so
	 * the JS reindexer can rewrite them after add / remove without
	 * having to parse the existing bracketed value.
	 *
	 * Simple string-replace pass — never touches HTML structure;
	 * cheaper than running a DOM walker for what's typically 10–30
	 * matches per repeater item.
	 */
	private static function inject_repeater_templates( string $html, string $name_tpl, string $id_tpl, string $error_id_tpl ): string {
		// Inject the templates on every element that carries a `name`
		// or `id`. We use a regex over the already-rendered HTML to
		// avoid teaching every per-type renderer about repeater
		// context. The regex is intentionally narrow (anchored on
		// ` name="…"` / ` id="…"` / ` for="…"` patterns) so it can't
		// accidentally hit content text.
		$html = preg_replace_callback(
			'/(\s)name="([^"]*)"/',
			static function ( $m ) use ( $name_tpl ) {
				return $m[1] . 'name="' . $m[2] . '" data-name-template="' . esc_attr( $name_tpl ) . '"';
			},
			$html
		);
		$html = preg_replace_callback(
			'/(\s)id="([^"]*)"/',
			static function ( $m ) use ( $id_tpl, $error_id_tpl ) {
				// Discriminate between the input id and the error
				// container id by inspecting the suffix.
				$tpl = ( substr( $m[2], -6 ) === '-error' ) ? $error_id_tpl : $id_tpl;
				return $m[1] . 'id="' . $m[2] . '" data-id-template="' . esc_attr( $tpl ) . '"';
			},
			$html
		);
		$html = preg_replace_callback(
			'/(\s)for="([^"]*)"/',
			static function ( $m ) use ( $id_tpl ) {
				return $m[1] . 'for="' . $m[2] . '" data-for-template="' . esc_attr( $id_tpl ) . '"';
			},
			$html
		);
		// aria-describedby points at the error container id, which
		// includes the iteration index — give it a template too so the
		// reindexer keeps aria wiring in sync.
		$html = preg_replace_callback(
			'/(\s)aria-describedby="([^"]*)"/',
			static function ( $m ) use ( $error_id_tpl ) {
				return $m[1] . 'aria-describedby="' . $m[2] . '" data-aria-describedby-template="' . esc_attr( $error_id_tpl ) . '"';
			},
			$html
		);
		return $html;
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
		// Effective limit = min( field max_file_size, wp_max_upload_size() ),
		// so the hint, the client pre-check and the server check all agree.
		$limit = \NeuralForms\Forms\SchemaHelper::file_size_limit( $field );

		$attrs = 'type="file"'
			. ' id="' . esc_attr( $input_id ) . '"'
			. ' name="' . esc_attr( $field['id'] ) . '"'
			. ' class="neural-forms-form__file-input"'
			. ' data-max-size="' . (int) $limit['bytes'] . '"'            // bytes
			. ' data-max-size-label="' . esc_attr( $limit['label'] ) . '"';

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
		$hint_parts[] = 'Max ' . $limit['label'];
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
	 * that group. Returns a comma-separated string ready for <input accept="...">,
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

	// Shared helpers

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
		// after a failed submission - without the user having to navigate away.
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
			$public_text = __( 'This form is currently unavailable. Please try again later.', 'neural-forms-lite' );
		}

		return '<div class="neural-forms-form-wrap"><p class="neural-forms-form-unavailable">'
			. esc_html( $public_text ) . '</p></div>';
	}
}
