<?php
namespace NeuralForms\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Detects Contact Form 7 forms and parses their field tags.
 *
 * Reads CF7 data via the WPCF7_ContactForm API - no direct DB queries.
 * All methods are static and read-only; CF7 data is never modified.
 */
class CF7Detector {

	/**
	 * True if Contact Form 7 is active and its classes are available.
	 */
	public static function is_cf7_active(): bool {
		return class_exists( 'WPCF7_ContactForm' );
	}

	/**
	 * Return a summary of every CF7 form in the database.
	 *
	 * @return array[] Each entry: { id, title, field_count, field_types, has_unsupported }
	 */
	public static function get_forms(): array {
		if ( ! self::is_cf7_active() ) {
			return array();
		}

		$posts = get_posts(
			array(
				'post_type'      => \WPCF7_ContactForm::post_type,
				'posts_per_page' => -1,
				'post_status'    => 'any',
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$forms = array();

		foreach ( $posts as $post ) {
			$cf7 = \WPCF7_ContactForm::get_instance( $post->ID );
			if ( ! $cf7 ) {
				continue;
			}

			$tags        = self::parse_tags( $cf7 );
			$field_types = array_values( array_unique( array_column( $tags, 'basetype' ) ) );

			$forms[] = array(
				'id'              => (int) $post->ID,
				'title'           => $cf7->title(),
				'field_count'     => count( $tags ),
				'field_types'     => $field_types,
				'has_unsupported' => self::has_unsupported( $field_types ),
			);
		}

		return $forms;
	}

	/**
	 * Parse all field tags from a CF7 form.
	 *
	 * Returns an array of normalized tag descriptors - one per data field.
	 * Non-data tags (submit, response, recaptcha, etc.) are excluded.
	 *
	 * @param int|\WPCF7_ContactForm $form  CF7 form ID or instance.
	 * @return array[]
	 */
	public static function parse_tags( $form ): array {
		if ( is_int( $form ) ) {
			if ( ! self::is_cf7_active() ) {
				return array();
			}
			$form = \WPCF7_ContactForm::get_instance( $form );
			if ( ! $form ) {
				return array();
			}
		}

		$raw_tags = $form->scan_form_tags();
		$result   = array();

		foreach ( $raw_tags as $tag ) {
			$tag = new \WPCF7_FormTag( $tag );

			// Skip tags that are not data fields.
			if ( $tag->name === '' ) {
				continue;
			}

			$basetype = $tag->basetype;

			$descriptor = array(
				'basetype'      => $basetype,
				'name'          => $tag->name,
				'required'      => $tag->is_required(),
				'options'       => $tag->options,
				'values'        => $tag->values,
				'labels'        => $tag->labels,
				'raw_values'    => $tag->raw_values,
				'content'       => $tag->content,
				'placeholder'   => self::extract_placeholder( $tag ),
				'default_value' => self::extract_default( $tag ),
				'min'           => $tag->get_option( 'min', 'signed_num', true ),
				'max'           => $tag->get_option( 'max', 'signed_num', true ),
				'step'          => $tag->get_option( 'step', 'num', true ),
				'filetypes'     => $tag->get_option( 'filetypes', '', true ),
				'limit'         => $tag->get_option( 'limit', 'int', true ),
			);

			$result[] = $descriptor;
		}

		return $result;
	}

	// Private helpers

	/**
	 * Extract placeholder text from a CF7 tag.
	 *
	 * CF7 uses two conventions:
	 *   1. The `placeholder` option: [text your-name placeholder "Enter name"]
	 *   2. The `watermark` option (legacy alias for placeholder).
	 */
	private static function extract_placeholder( \WPCF7_FormTag $tag ): string {
		$ph = $tag->get_option( 'placeholder', '', true );
		if ( false !== $ph && $ph !== '' ) {
			return (string) $ph;
		}

		$wm = $tag->get_option( 'watermark', '', true );
		if ( false !== $wm && $wm !== '' ) {
			return (string) $wm;
		}

		return '';
	}

	/**
	 * Extract the default value from a CF7 tag.
	 *
	 * CF7 supports explicit `default:value` options and also uses the first
	 * value in the values array as the default for some types.
	 */
	private static function extract_default( \WPCF7_FormTag $tag ): string {
		$default = $tag->get_option( 'default', '', true );
		if ( false !== $default && $default !== '' ) {
			return (string) $default;
		}

		// For text-like fields, the first value is the default.
		if ( in_array( $tag->basetype, array( 'text', 'email', 'url', 'tel', 'number', 'range', 'date', 'hidden' ), true ) ) {
			return (string) ( $tag->values[0] ?? '' );
		}

		return '';
	}

	/**
	 * Check if any field types in the list are unsupported by Neural Forms.
	 */
	private static function has_unsupported( array $field_types ): bool {
		foreach ( $field_types as $type ) {
			if ( in_array( $type, CF7FieldMapper::UNSUPPORTED, true ) ) {
				return true;
			}
			if ( ! isset( CF7FieldMapper::TYPE_MAP[ $type ] ) ) {
				return true;
			}
		}
		return false;
	}
}
