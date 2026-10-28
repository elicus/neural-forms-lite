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
				// CF7 acceptance boxes are required UNLESS they carry the
				// `optional` option — the `*` suffix convention does not
				// apply to them, so is_required() alone imported every
				// [acceptance] as optional.
				'required'      => $basetype === 'acceptance'
					? ! $tag->has_option( 'optional' )
					: $tag->is_required(),
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
				// Bytes, or '' when the tag has no limit option. CF7's
				// `limit:2mb` / `limit:500kb` / `limit:1048576` all parse
				// through get_limit_option(); the old get_option('limit',
				// 'int') only matched bare digits and dropped "2mb".
				'limit'         => self::extract_limit_bytes( $tag ),
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
		// Standard CF7 syntax: `placeholder` (or legacy `watermark`) is a
		// BARE flag and the placeholder text is the tag's first value:
		//   [text* your-name placeholder "Jane Cooper"]
		// get_option() only returns `name:value` forms, so the bare flag
		// read as '' and the text was imported as a default value instead
		// (pre-filling the live form).
		if ( $tag->has_option( 'placeholder' ) || $tag->has_option( 'watermark' ) ) {
			return (string) ( $tag->values[0] ?? '' );
		}

		// Tolerate the non-standard `placeholder:Text` form as well.
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
	 * File size limit in bytes, or '' when the tag has no `limit:` option.
	 */
	private static function extract_limit_bytes( \WPCF7_FormTag $tag ) {
		if ( ! $tag->has_option( 'limit' ) ) {
			return '';
		}
		if ( method_exists( $tag, 'get_limit_option' ) ) {
			return (int) $tag->get_limit_option();
		}
		// Older CF7: parse `limit:<n>[kb|mb]` ourselves.
		$raw = (string) $tag->get_option( 'limit', '', true );
		if ( preg_match( '/^([1-9][0-9]*)([kKmM]?[bB])?$/', $raw, $m ) ) {
			$size = (int) $m[1];
			$unit = strtolower( $m[2] ?? '' );
			if ( $unit === 'kb' ) {
				$size *= 1024;
			} elseif ( $unit === 'mb' ) {
				$size *= 1048576;
			}
			return $size;
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
		$basetype  = $tag->basetype;
		$is_choice = in_array( $basetype, array( 'select', 'radio', 'checkbox' ), true );

		$default = $tag->get_option( 'default', '', true );
		if ( false !== $default && $default !== '' ) {
			$default = (string) $default;

			// Choice tags use 1-based value indexes: `default:1`, or
			// `default:1_3` for multiple checkboxes. Resolve them to the
			// actual option values (what Neural Forms stores) — the raw
			// "1" never matched an option, so the default was lost.
			if ( $is_choice && preg_match( '/^[0-9_]+$/', $default ) ) {
				$picked = array();
				foreach ( explode( '_', $default ) as $num ) {
					$idx = (int) $num - 1;
					if ( $idx >= 0 && isset( $tag->values[ $idx ] ) ) {
						$picked[] = (string) $tag->values[ $idx ];
					}
				}
				if ( empty( $picked ) ) {
					return '';
				}
				return $basetype === 'checkbox' ? implode( ',', $picked ) : $picked[0];
			}

			// Dynamic defaults (user_email, get, post, shortcode_attr, …)
			// have no equivalent here; leave the field empty.
			if ( preg_match( '/^(user_|post_meta$|get$|post$|shortcode_attr$)/', $default ) ) {
				return '';
			}

			return $default;
		}

		// Text-like tags: the first value is the default — UNLESS the tag
		// carries the `placeholder` / `watermark` flag, in which case that
		// value is the placeholder (see extract_placeholder()).
		if ( in_array( $basetype, array( 'text', 'email', 'url', 'tel', 'number', 'range', 'date', 'hidden', 'textarea' ), true ) ) {
			if ( $tag->has_option( 'placeholder' ) || $tag->has_option( 'watermark' ) ) {
				return '';
			}
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
