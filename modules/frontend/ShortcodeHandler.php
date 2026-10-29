<?php
namespace NeuralForms\Frontend;

use NeuralForms\Forms\FormRepository;

defined( 'ABSPATH' ) || exit;

/**
 * ShortcodeHandler
 *
 * Registers the [neural-forms] shortcode and routes its attributes to FormRenderer.
 *
 * Supported syntax
 *
 *   [neural-forms id="42"]
 *   [neural-forms slug="contact-form"]
 *
 * When both attributes are supplied, `id` takes precedence.
 *
 * Error handling
 *
 * All failure paths return an empty string for non-admin visitors.
 * Admins (manage_options) receive an inline WP-styled notice that explains
 * exactly what is wrong (missing attributes, invalid ID, slug not found).
 * FormRenderer::render() handles the "form not active" case after the ID
 * is resolved - ShortcodeHandler does not duplicate that check.
 *
 * Double-lookup note (slug path)
 *
 * When a slug is provided, this class calls FormRepository::find_by_slug()
 * to resolve the ID, then passes that ID to FormRenderer::render() which
 * calls FormRepository::find() again. This is two DB queries for the slug
 * path. Acceptable at MVP scale. If this becomes a bottleneck, a future
 * render_from_object(array $form) method on FormRenderer would eliminate
 * the second query.
 */
class ShortcodeHandler {

	// Boot

	/**
	 * Register the [neural-forms] shortcode.
	 *
	 * Called by PublicForms::boot() on the `init` action.
	 * add_shortcode() must fire on or before `init` - never in a later hook.
	 */
	public static function boot(): void {
		add_shortcode( 'neural-forms', array( static::class, 'handle' ) );
		add_shortcode( 'neural-forms-payment-form', array( \NeuralForms\Payments\PaymentForm::class, 'shortcode' ) );
	}

	// Shortcode callback

	/**
	 * Process [neural-forms] shortcode attributes and return rendered HTML.
	 *
	 * Shortcode callbacks must be public - WordPress stores the callback
	 * reference and invokes it outside this class context.
	 *
	 * @param  array|string $atts  Shortcode attributes (empty string when none provided).
	 * @return string              Ready-to-output HTML, or empty string for non-admins on error.
	 */
	public static function handle( $atts ): string {
		$atts = shortcode_atts(
			array(
				'id'   => '',
				'slug' => '',
			),
			$atts,
			'neural-forms'
		);

		$raw_id = trim( (string) $atts['id'] );
		$slug   = trim( (string) $atts['slug'] );

		// ' id attribute '
		// id takes precedence when both attributes are present.
		if ( $raw_id !== '' ) {
			$form_id = (int) $raw_id;

			if ( $form_id < 1 ) {
				return static::admin_notice(
					sprintf(
						/* translators: %s: the supplied id value */
						__( 'Neural Forms: shortcode "id" must be a positive integer (got "%s").', 'neural-forms-lite' ),
						$raw_id
					)
				);
			}

			// FormRenderer::render() handles form-not-found and not-active cases.
			return FormRenderer::render( $form_id );
		}

		// ' slug attribute '
		if ( $slug !== '' ) {
			$form = FormRepository::find_by_slug( $slug );

			if ( ! $form ) {
				return static::admin_notice(
					sprintf(
						/* translators: %s: the supplied slug value */
						__( 'Neural Forms: no form found with slug "%s".', 'neural-forms-lite' ),
						$slug
					)
				);
			}

			return FormRenderer::render( (int) $form['id'] );
		}

		// ' Neither attribute provided '
		return static::admin_notice(
			__( 'Neural Forms: shortcode requires an id or slug attribute. Example: [neural-forms id="1"] or [neural-forms slug="contact-form"].', 'neural-forms-lite' )
		);
	}

	// Helpers

	/**
	 * Build the shortcode string for a form row.
	 *
	 * Single source of truth for admin-UI shortcode generation as of
	 * 0.2.0. ID-based shortcode is preferred — slugs are mutable
	 * (renames) and can collide across statuses, while ids are
	 * immutable. ShortcodeHandler::handle() still accepts the
	 * `slug=` attribute on the parsing side so existing pages that
	 * embedded slug-based shortcodes keep rendering unchanged.
	 *
	 * Behaviour:
	 *   - id present → `[neural-forms id="N"]` (absint sanitised)
	 *   - id missing, slug present → `[neural-forms slug="..."]`
	 *     (esc_attr sanitised). Saved forms always have an id, so
	 *     this branch is effectively a defence-in-depth fallback.
	 *   - neither → empty string
	 *
	 * @param array $form  Form row from FormRepository::list/find.
	 * @return string
	 */
	public static function for_form( array $form ): string {
		$id = isset( $form['id'] ) ? absint( $form['id'] ) : 0;
		if ( $id > 0 ) {
			return '[neural-forms id="' . $id . '"]';
		}
		$slug = isset( $form['slug'] ) ? (string) $form['slug'] : '';
		if ( $slug !== '' ) {
			return '[neural-forms slug="' . esc_attr( $slug ) . '"]';
		}
		return '';
	}

	/**
	 * Render a dismissible admin-only diagnostic notice.
	 *
	 * Returns an empty string for all non-admin visitors - safe to echo.
	 * Uses standard WordPress notice classes so it integrates with the
	 * block editor sidebar and the front-end admin bar context.
	 *
	 * @param string $message  Plain-text message (will be escaped).
	 * @return string
	 */
	private static function admin_notice( string $message ): string {
		if ( ! current_user_can( 'manage_options' ) ) {
			return '';
		}

		return '<div class="notice notice-warning inline"><p>' . esc_html( $message ) . '</p></div>';
	}
}
