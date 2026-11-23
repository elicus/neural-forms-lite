<?php
namespace NeuralForms\Forms;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/Countries.php';

/**
 * Name, Address, Time, Password, Content block and Signature fields (1.3.0).
 *
 * One place for everything type-specific about these six types: parts,
 * markup, submission checks, cleaning and display text. FormRenderer,
 * SubmissionValidator, SubmitEndpoint, the email / CSV / AI formatters
 * call in here, so each type's rules live once.
 *
 * Stored values:
 *   name       { first, middle?, last }       posted as {id}[first] …
 *   address    { line1, line2?, city, state, postal, country? }
 *   time       "HH:MM" (24 h)
 *   password   NEVER stored, emailed, exported or sent to AI. Checked, then
 *              handed to `neural_forms_password_submitted` and dropped.
 *   html       display-only, never posted (like Calculated)
 *   signature  posted as a PNG data URL, saved as a private attachment and
 *              stored like a file upload { attachment_id, url, filename, … }
 */
class ExtraFields {

	public const TYPES = array( 'name', 'address', 'time', 'password', 'html', 'signature' );

	/** Types posted as an array of parts. */
	public const ARRAY_TYPES = array( 'name', 'address', 'password' );

	/** Signature limits. */
	public const SIGNATURE_MAX_BYTES  = 300000;   // decoded PNG
	public const SIGNATURE_MAX_CHARS  = 420000;   // data URL
	public const SIGNATURE_MAX_WIDTH  = 2000;
	public const SIGNATURE_MAX_HEIGHT = 1000;

	public const PASSWORD_DEFAULT_MIN = 8;
	public const PASSWORD_MAX         = 200;

	public static function handles( string $type ): bool {
		return in_array( $type, self::TYPES, true );
	}

	// Parts

	/**
	 * Name parts in display order: key => [ label, autocomplete, required-when-field-required ].
	 */
	public static function name_parts( array $field ): array {
		$s     = is_array( $field['settings'] ?? null ) ? $field['settings'] : array();
		$parts = array( 'first' => array( __( 'First name', 'neural-forms-lite' ), 'given-name', true ) );
		if ( ( $s['format'] ?? '' ) === 'first_middle_last' ) {
			$parts['middle'] = array( __( 'Middle name', 'neural-forms-lite' ), 'additional-name', false );
		}
		$parts['last'] = array( __( 'Last name', 'neural-forms-lite' ), 'family-name', true );
		return self::with_custom_labels( $parts, $s );
	}

	public static function address_parts( array $field ): array {
		$s     = is_array( $field['settings'] ?? null ) ? $field['settings'] : array();
		$parts = array( 'line1' => array( __( 'Street address', 'neural-forms-lite' ), 'address-line1', true ) );
		if ( ! array_key_exists( 'show_line2', $s ) || ! empty( $s['show_line2'] ) ) {
			$parts['line2'] = array( __( 'Address line 2', 'neural-forms-lite' ), 'address-line2', false );
		}
		$parts['city']   = array( __( 'City', 'neural-forms-lite' ), 'address-level2', true );
		$parts['state']  = array( __( 'State / Province / Region', 'neural-forms-lite' ), 'address-level1', false );
		$parts['postal'] = array( __( 'ZIP / Postal code', 'neural-forms-lite' ), 'postal-code', false );
		if ( ! array_key_exists( 'show_country', $s ) || ! empty( $s['show_country'] ) ) {
			$parts['country'] = array( __( 'Country', 'neural-forms-lite' ), 'country-name', true );
		}
		return self::with_custom_labels( $parts, $s );
	}

	/** settings.sublabels { part: "Given name" } overrides a part's label. */
	private static function with_custom_labels( array $parts, array $settings ): array {
		$custom = is_array( $settings['sublabels'] ?? null ) ? $settings['sublabels'] : array();
		foreach ( $parts as $key => $p ) {
			$c = isset( $custom[ $key ] ) && is_string( $custom[ $key ] ) ? trim( $custom[ $key ] ) : '';
			if ( $c !== '' ) {
				$parts[ $key ][0] = $c;
			}
		}
		return $parts;
	}

	public static function parts_for( array $field ): array {
		$type = (string) ( $field['type'] ?? '' );
		if ( $type === 'name' ) {
			return self::name_parts( $field );
		}
		if ( $type === 'address' ) {
			return self::address_parts( $field );
		}
		return array();
	}

	// Rendering (called from FormRenderer::render_field)

	public static function render_input( array $field, string $input_id, string $error_id ): string {
		switch ( (string) ( $field['type'] ?? '' ) ) {
			case 'name':
			case 'address':
				return self::render_parts( $field, $input_id, $error_id );
			case 'time':
				return self::render_time( $field, $input_id, $error_id );
			case 'password':
				return self::render_password( $field, $input_id, $error_id );
			case 'signature':
				return self::render_signature( $field, $input_id, $error_id );
		}
		return '';
	}

	private static function legend( array $field ): string {
		$lbl = (string) ( $field['label'] ?? '' );
		if ( $lbl === '' ) {
			return '';
		}
		$show = ! empty( $field['show_label'] );
		$mark = ( $show && ! empty( $field['required'] ) ) ? ' <span class="neural-forms-form__required" aria-hidden="true">*</span>' : '';
		return '<legend class="' . ( $show ? 'neural-forms-form__label neural-forms-form__label--block' : 'screen-reader-text' ) . '">'
			. esc_html( $lbl ) . $mark . '</legend>';
	}

	private static function render_parts( array $field, string $input_id, string $error_id ): string {
		$type     = (string) $field['type'];
		$id       = (string) $field['id'];
		$required = ! empty( $field['required'] );
		$parts    = self::parts_for( $field );
		$s        = is_array( $field['settings'] ?? null ) ? $field['settings'] : array();
		$ph       = is_array( $s['placeholders'] ?? null ) ? $s['placeholders'] : array();
		$default_country = $type === 'address' ? Countries::resolve_default( $s['default_country'] ?? '' ) : '';

		$html = '<fieldset class="neural-forms-form__parts-group neural-forms-form__' . esc_attr( $type ) . '" aria-describedby="' . esc_attr( $error_id ) . '">'
			. self::legend( $field )
			. '<div class="neural-forms-form__parts neural-forms-form__parts--' . esc_attr( $type ) . '">';
		foreach ( $parts as $key => $p ) {
			$pid  = $input_id . '-' . $key;
			$req  = ( $required && $p[2] ) ? ' required aria-required="true"' : '';
			$hold = isset( $ph[ $key ] ) && is_string( $ph[ $key ] ) && $ph[ $key ] !== '' ? ' placeholder="' . esc_attr( $ph[ $key ] ) . '"' : '';
			$html .= '<div class="neural-forms-form__part neural-forms-form__part--' . esc_attr( $key ) . '">';
			if ( $type === 'address' && $key === 'country' ) {
				// Country is a dropdown of Countries::all(); the option value
				// is the name, so stored entries read naturally everywhere.
				$html .= '<select id="' . esc_attr( $pid ) . '" name="' . esc_attr( $id . '[country]' ) . '"'
					. ' class="neural-forms-form__select" autocomplete="country-name"' . $req
					. ' aria-describedby="' . esc_attr( $error_id ) . '">'
					. '<option value="">' . esc_html__( 'Select a country', 'neural-forms-lite' ) . '</option>';
				foreach ( Countries::all() as $code => $country ) {
					$html .= '<option value="' . esc_attr( $country ) . '" data-code="' . esc_attr( $code ) . '"'
						. ( $country === $default_country ? ' selected' : '' ) . '>' . esc_html( $country ) . '</option>';
				}
				$html .= '</select>';
			} else {
				$html .= '<input type="text" id="' . esc_attr( $pid ) . '" name="' . esc_attr( $id . '[' . $key . ']' ) . '"'
					. ' class="neural-forms-form__input" autocomplete="' . esc_attr( $p[1] ) . '"' . $hold . $req
					. ' aria-describedby="' . esc_attr( $error_id ) . '">';
			}
			$html .= ''
				. '<label class="neural-forms-form__sublabel" for="' . esc_attr( $pid ) . '">' . esc_html( $p[0] )
				. ( $req !== '' ? ' <span class="neural-forms-form__required" aria-hidden="true">*</span>' : '' )
				. '</label></div>';
		}
		return $html . '</div></fieldset>';
	}

	private static function render_time( array $field, string $input_id, string $error_id ): string {
		$attrs = 'id="' . esc_attr( $input_id ) . '" name="' . esc_attr( (string) $field['id'] ) . '" class="neural-forms-form__input neural-forms-form__input--time"';
		foreach ( array( 'min', 'max', 'default_value' ) as $k ) {
			$v = self::clean_time( $field[ $k ] ?? '' );
			if ( $v !== '' ) {
				$attrs .= ' ' . ( $k === 'default_value' ? 'value' : $k ) . '="' . esc_attr( $v ) . '"';
			}
		}
		$step = (int) ( $field['step'] ?? 0 );
		if ( $step > 0 ) {
			$attrs .= ' step="' . esc_attr( (string) ( $step * 60 ) ) . '"';
		}
		if ( ! empty( $field['required'] ) ) {
			$attrs .= ' required aria-required="true"';
		}
		return '<input type="time" ' . $attrs . ' aria-describedby="' . esc_attr( $error_id ) . '">';
	}

	private static function render_password( array $field, string $input_id, string $error_id ): string {
		$id       = (string) $field['id'];
		$req      = ! empty( $field['required'] ) ? ' required aria-required="true"' : '';
		$min      = self::password_min( $field );
		$ph       = (string) ( $field['placeholder'] ?? '' );
		$html     = '<input type="password" id="' . esc_attr( $input_id ) . '" name="' . esc_attr( $id . '[password]' ) . '"'
			. ' class="neural-forms-form__input" autocomplete="new-password" minlength="' . esc_attr( (string) $min ) . '"'
			. ' maxlength="' . self::PASSWORD_MAX . '"'
			. ( $ph !== '' ? ' placeholder="' . esc_attr( $ph ) . '"' : '' ) . $req
			. ' aria-describedby="' . esc_attr( $error_id ) . '">';
		if ( ! empty( $field['settings']['confirm'] ) ) {
			$cid   = $input_id . '-confirm';
			$html .= '<div class="neural-forms-form__part neural-forms-form__part--confirm">'
				. '<input type="password" id="' . esc_attr( $cid ) . '" name="' . esc_attr( $id . '[confirm]' ) . '"'
				. ' class="neural-forms-form__input" autocomplete="new-password" maxlength="' . self::PASSWORD_MAX . '"' . $req
				. ' data-neural-forms-confirm-of="' . esc_attr( $input_id ) . '" aria-describedby="' . esc_attr( $error_id ) . '">'
				. '<label class="neural-forms-form__sublabel" for="' . esc_attr( $cid ) . '">' . esc_html__( 'Confirm password', 'neural-forms-lite' ) . '</label></div>';
		}
		/* translators: %d: minimum number of characters */
		$html .= '<p class="neural-forms-form__sublabel">' . esc_html( sprintf( __( 'At least %d characters.', 'neural-forms-lite' ), $min ) ) . '</p>';
		return $html;
	}

	private static function render_signature( array $field, string $input_id, string $error_id ): string {
		$req = ! empty( $field['required'] ) ? ' required aria-required="true"' : '';
		return '<div class="neural-forms-form__signature" data-neural-forms-signature>'
			. '<canvas class="neural-forms-form__signature-pad" width="600" height="180" role="img"'
			. ' aria-label="' . esc_attr__( 'Signature pad: draw with your mouse, finger or stylus', 'neural-forms-lite' ) . '"></canvas>'
			. '<input type="hidden" id="' . esc_attr( $input_id ) . '" name="' . esc_attr( (string) $field['id'] ) . '" value=""' . $req
			. ' aria-describedby="' . esc_attr( $error_id ) . '">'
			. '<div class="neural-forms-form__signature-bar">'
			. '<span class="neural-forms-form__sublabel">' . esc_html__( 'Sign above', 'neural-forms-lite' ) . '</span>'
			. '<button type="button" class="neural-forms-form__signature-clear" data-neural-forms-signature-clear>' . esc_html__( 'Clear', 'neural-forms-lite' ) . '</button>'
			. '</div>'
			. '<noscript><p class="neural-forms-form__sublabel">' . esc_html__( 'Signing needs JavaScript.', 'neural-forms-lite' ) . '</p></noscript>'
			. '</div>';
	}

	/**
	 * Content block markup (not a field: no label, no input, no error slot).
	 * HTML goes through wp_kses_post(), so a forms manager without
	 * unfiltered_html can't add scripts.
	 */
	public static function render_content_block( array $field, string $conditional_attr ): string {
		$content = (string) ( $field['content'] ?? '' );
		if ( trim( $content ) === '' ) {
			return '';
		}
		return '<div class="neural-forms-form__content-block" data-neural-forms-field-id="' . esc_attr( (string) $field['id'] ) . '"' . $conditional_attr . '>'
			. wpautop( wp_kses_post( $content ) )
			. '</div>';
	}

	// Submission checks

	/**
	 * Required + format check. Returns { code, message } or null.
	 * Values come straight from the request (not yet cleaned).
	 */
	public static function validate( array $field, $value ): ?array {
		$type     = (string) ( $field['type'] ?? '' );
		$label    = trim( (string) ( $field['label'] ?? '' ) ) !== '' ? trim( (string) $field['label'] ) : (string) ( $field['id'] ?? '' );
		$required = ! empty( $field['required'] );

		if ( $type === 'html' ) {
			return null;
		}

		$bad = array(
			'code'    => 'invalid_value',
			/* translators: %s: field label */
			'message' => sprintf( __( '%s has an invalid value.', 'neural-forms-lite' ), $label ),
		);

		if ( in_array( $type, self::ARRAY_TYPES, true ) ) {
			if ( $value !== null && $value !== '' && ! is_array( $value ) ) {
				return $bad;
			}
			$value = is_array( $value ) ? $value : array();
			foreach ( $value as $part ) {
				if ( ! is_scalar( $part ) && $part !== null ) {
					return $bad;
				}
				if ( is_string( $part ) && strlen( $part ) > 500 ) {
					return array(
						'code'    => 'too_long',
						/* translators: %s: field label */
						'message' => sprintf( __( '%s is too long.', 'neural-forms-lite' ), $label ),
					);
				}
			}
		} elseif ( $value !== null && ! is_scalar( $value ) ) {
			return $bad;
		}

		switch ( $type ) {
			case 'name':
			case 'address':
				// Country must be one of the dropdown's countries.
				$parts = self::parts_for( $field );
				if ( $type === 'address' && isset( $parts['country'] ) && is_scalar( $value['country'] ?? null )
					&& trim( (string) $value['country'] ) !== '' && ! Countries::is_known_name( (string) $value['country'] ) ) {
					return array(
						'code'    => 'invalid_country',
						/* translators: %s: field label */
						'message' => sprintf( __( '%s: please choose a country from the list.', 'neural-forms-lite' ), $label ),
					);
				}
				if ( $required ) {
					foreach ( $parts as $key => $p ) {
						if ( $p[2] && trim( (string) ( $value[ $key ] ?? '' ) ) === '' ) {
							return array(
								'code'    => 'required',
								/* translators: 1: field label, 2: part, e.g. "City" */
								'message' => sprintf( __( '%1$s: %2$s is required.', 'neural-forms-lite' ), $label, $p[0] ),
							);
						}
					}
				}
				return null;

			case 'time':
				$t = trim( (string) $value );
				if ( $t === '' ) {
					/* translators: %s: field label */
					return $required ? array( 'code' => 'required', 'message' => sprintf( __( '%s is required.', 'neural-forms-lite' ), $label ) ) : null;
				}
				$clean = self::clean_time( $t );
				if ( $clean === '' ) {
					/* translators: %s: field label */
					return array( 'code' => 'invalid_time', 'message' => sprintf( __( '%s must be a valid time.', 'neural-forms-lite' ), $label ) );
				}
				$min = self::clean_time( $field['min'] ?? '' );
				$max = self::clean_time( $field['max'] ?? '' );
				if ( ( $min !== '' && $clean < $min ) || ( $max !== '' && $clean > $max ) ) {
					return array(
						'code'    => 'out_of_range',
						/* translators: 1: field label, 2: earliest time, 3: latest time */
						'message' => sprintf( __( '%1$s must be between %2$s and %3$s.', 'neural-forms-lite' ), $label, $min !== '' ? $min : '00:00', $max !== '' ? $max : '23:59' ),
					);
				}
				return null;

			case 'password':
				$pw = (string) ( $value['password'] ?? '' );
				if ( $pw === '' ) {
					/* translators: %s: field label */
					return $required ? array( 'code' => 'required', 'message' => sprintf( __( '%s is required.', 'neural-forms-lite' ), $label ) ) : null;
				}
				$min = self::password_min( $field );
				if ( function_exists( 'mb_strlen' ) ? mb_strlen( $pw ) < $min : strlen( $pw ) < $min ) {
					/* translators: 1: field label, 2: minimum characters */
					return array( 'code' => 'too_short', 'message' => sprintf( __( '%1$s must be at least %2$d characters.', 'neural-forms-lite' ), $label, $min ) );
				}
				if ( strlen( $pw ) > self::PASSWORD_MAX ) {
					/* translators: %s: field label */
					return array( 'code' => 'too_long', 'message' => sprintf( __( '%s is too long.', 'neural-forms-lite' ), $label ) );
				}
				if ( ! empty( $field['settings']['confirm'] ) && ! hash_equals( $pw, (string) ( $value['confirm'] ?? '' ) ) ) {
					return array( 'code' => 'mismatch', 'message' => __( 'The passwords do not match.', 'neural-forms-lite' ) );
				}
				return null;

			case 'signature':
				$v = (string) $value;
				if ( $v === '' ) {
					/* translators: %s: field label */
					return $required ? array( 'code' => 'required', 'message' => sprintf( __( '%s is required.', 'neural-forms-lite' ), $label ) ) : null;
				}
				if ( null === self::decode_signature( $v ) ) {
					/* translators: %s: field label */
					return array( 'code' => 'invalid_signature', 'message' => sprintf( __( '%s could not be read. Please clear it and sign again.', 'neural-forms-lite' ), $label ) );
				}
				return null;
		}
		return null;
	}

	// Cleaning

	/**
	 * Cleaned value for storage, or null when nothing should be stored
	 * (empty parts, password, content block). Signature is NOT handled
	 * here: SubmitEndpoint turns the data URL into an attachment.
	 */
	public static function sanitize( array $field, $value ) {
		$type = (string) ( $field['type'] ?? '' );
		if ( $type === 'name' || $type === 'address' ) {
			$out = array();
			$v   = is_array( $value ) ? $value : array();
			foreach ( self::parts_for( $field ) as $key => $p ) {
				$clean = sanitize_text_field( (string) ( is_scalar( $v[ $key ] ?? null ) ? $v[ $key ] : '' ) );
				if ( $type === 'address' && $key === 'country' ) {
					$clean = Countries::canonical_name( $clean );
				}
				if ( $clean !== '' ) {
					$out[ $key ] = $clean;
				}
			}
			return $out ? $out : null;
		}
		if ( $type === 'time' ) {
			$t = self::clean_time( is_scalar( $value ) ? (string) $value : '' );
			return $t !== '' ? $t : null;
		}
		return null;
	}

	/** "9:05", "09:05", "09:05:30" → "09:05"; anything else → ''. */
	public static function clean_time( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		if ( ! preg_match( '/^\s*(\d{1,2}):(\d{2})(?::\d{2})?\s*$/', $value, $m ) ) {
			return '';
		}
		$h = (int) $m[1];
		$i = (int) $m[2];
		if ( $h > 23 || $i > 59 ) {
			return '';
		}
		return sprintf( '%02d:%02d', $h, $i );
	}

	public static function password_min( array $field ): int {
		$min = (int) ( $field['settings']['min_length'] ?? self::PASSWORD_DEFAULT_MIN );
		return max( 1, min( 64, $min > 0 ? $min : self::PASSWORD_DEFAULT_MIN ) );
	}

	/**
	 * PNG bytes from a signature data URL, or null when it is not a small,
	 * real PNG image (prefix, base64, PNG magic number, decodable size).
	 */
	public static function decode_signature( string $data_url ): ?string {
		if ( strlen( $data_url ) > self::SIGNATURE_MAX_CHARS || strpos( $data_url, 'data:image/png;base64,' ) !== 0 ) {
			return null;
		}
		$bin = base64_decode( substr( $data_url, 22 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( ! is_string( $bin ) || $bin === '' || strlen( $bin ) > self::SIGNATURE_MAX_BYTES || strpos( $bin, "\x89PNG\r\n\x1a\n" ) !== 0 ) {
			return null;
		}
		$size = function_exists( 'getimagesizefromstring' ) ? @getimagesizefromstring( $bin ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! is_array( $size ) || ( $size[2] ?? 0 ) !== IMAGETYPE_PNG
			|| $size[0] < 1 || $size[1] < 1 || $size[0] > self::SIGNATURE_MAX_WIDTH || $size[1] > self::SIGNATURE_MAX_HEIGHT ) {
			return null;
		}
		return $bin;
	}

	// Display (email, CSV, AI, workflow tokens)

	/**
	 * One-line text for a stored value; '' when nothing to show.
	 * Address joins with ", ". Signature reads "Signed" (+ link in CSV).
	 */
	public static function display( array $field, $raw ): string {
		$type = (string) ( $field['type'] ?? '' );
		if ( $type === 'name' || $type === 'address' ) {
			if ( ! is_array( $raw ) ) {
				return is_scalar( $raw ) ? (string) $raw : '';
			}
			$out = array();
			foreach ( array_keys( self::parts_for( $field ) ) as $key ) {
				$v = isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) ? trim( (string) $raw[ $key ] ) : '';
				if ( $v !== '' ) {
					$out[] = $v;
				}
			}
			return implode( $type === 'name' ? ' ' : ', ', $out );
		}
		if ( $type === 'signature' ) {
			return is_array( $raw ) && ! empty( $raw['url'] ) ? __( 'Signed', 'neural-forms-lite' ) : '';
		}
		if ( $type === 'password' || $type === 'html' ) {
			return '';
		}
		return is_scalar( $raw ) ? (string) $raw : '';
	}

	/**
	 * Replace name / address / signature values in a field_id => value map
	 * with their display text (AI payloads). Passwords and content blocks
	 * are never in entry data.
	 */
	public static function readable( array $values, array $flat_fields ): array {
		foreach ( $flat_fields as $f ) {
			$id   = (string) ( $f['id'] ?? '' );
			$type = (string) ( $f['type'] ?? '' );
			if ( $id !== '' && in_array( $type, array( 'name', 'address', 'signature' ), true ) && array_key_exists( $id, $values ) ) {
				$values[ $id ] = self::display( $f, $values[ $id ] );
			}
		}
		return $values;
	}
}
