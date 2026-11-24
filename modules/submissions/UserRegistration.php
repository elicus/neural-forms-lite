<?php
namespace NeuralForms\Submissions;

use NeuralForms\Core\Capabilities;
use NeuralForms\Storage\EntryActivity;

defined( 'ABSPATH' ) || exit;

/**
 * Register a WordPress user from a form (1.3.0, `user_registration` cap).
 *
 * settings.register_user = { enabled, role, email, username, password,
 *   first_name, last_name, website, meta[{key, field}], auto_login,
 *   notify_user, notify_admin, logged_in hide|show, logged_in_message }
 *
 * Safety rules:
 * - Only roles WITHOUT any capability in DANGEROUS_CAPS can be given,
 *   checked when the form is saved AND again before every insert.
 * - The email / username are checked before the entry is saved, so a
 *   taken address is a normal field error, not a half-done submission.
 * - Visitors who are logged in are never registered (or re-registered).
 * - The password goes straight to wp_insert_user() (hashed by WordPress);
 *   Neural Forms never stores it. No password field → WordPress emails a
 *   "set your password" link.
 * - Custom fields go through Mapping::safe_meta_key() (no roles, levels,
 *   protected or core profile keys).
 */
final class UserRegistration {

	public const DANGEROUS_CAPS = array(
		'manage_options', 'edit_users', 'create_users', 'promote_users', 'delete_users', 'list_users', 'remove_users',
		'edit_others_posts', 'edit_others_pages', 'edit_pages', 'publish_pages', 'delete_others_posts', 'edit_private_posts',
		'edit_theme_options', 'switch_themes', 'edit_themes', 'install_themes', 'activate_plugins', 'install_plugins',
		'edit_plugins', 'update_plugins', 'delete_plugins', 'update_core', 'edit_files', 'unfiltered_html',
		'unfiltered_upload', 'import', 'export', 'moderate_comments', 'manage_categories', 'manage_links',
		'manage_woocommerce', 'view_woocommerce_reports', 'edit_shop_orders', 'manage_network', 'manage_sites',
		NEURAL_FORMS_CAP_MANAGE_FORMS,
	);

	/** Roles a form may give: role slug => label, none with a dangerous capability. */
	public static function safe_roles(): array {
		$out = array();
		if ( ! function_exists( 'wp_roles' ) ) {
			return $out;
		}
		foreach ( wp_roles()->roles as $slug => $role ) {
			$caps = array_keys( array_filter( (array) ( $role['capabilities'] ?? array() ) ) );
			if ( ! array_intersect( $caps, self::DANGEROUS_CAPS ) ) {
				$out[ (string) $slug ] = translate_user_role( (string) ( $role['name'] ?? $slug ) );
			}
		}
		return $out;
	}

	public static function is_safe_role( string $role ): bool {
		return $role !== '' && array_key_exists( $role, self::safe_roles() );
	}

	public static function sanitize_settings( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$role = isset( $raw['role'] ) && is_scalar( $raw['role'] ) ? sanitize_key( (string) $raw['role'] ) : 'subscriber';
		$out  = array(
			'enabled'           => ! empty( $raw['enabled'] ),
			'role'              => $role !== '' ? $role : 'subscriber',
			'email'             => Mapping::field_id( $raw['email'] ?? '' ),
			'username'          => Mapping::field_id( $raw['username'] ?? '' ),
			'password'          => Mapping::field_id( $raw['password'] ?? '' ),
			'first_name'        => Mapping::field_id( $raw['first_name'] ?? '' ),
			'last_name'         => Mapping::field_id( $raw['last_name'] ?? '' ),
			'website'           => Mapping::field_id( $raw['website'] ?? '' ),
			'meta'              => Mapping::sanitize_meta_list( $raw['meta'] ?? array() ),
			'auto_login'        => ! empty( $raw['auto_login'] ),
			'notify_user'       => array_key_exists( 'notify_user', $raw ) ? ! empty( $raw['notify_user'] ) : true,
			'notify_admin'      => ! empty( $raw['notify_admin'] ),
			'logged_in'         => ( $raw['logged_in'] ?? 'hide' ) === 'show' ? 'show' : 'hide',
			'logged_in_message' => isset( $raw['logged_in_message'] ) && is_scalar( $raw['logged_in_message'] ) ? mb_substr( sanitize_text_field( (string) $raw['logged_in_message'] ), 0, 300 ) : '',
			// Account activation (AccountActivation).
			'activation'         => isset( $raw['activation'] ) && in_array( (string) $raw['activation'], AccountActivation::MODES, true ) ? (string) $raw['activation'] : 'none',
			'activation_subject' => isset( $raw['activation_subject'] ) && is_scalar( $raw['activation_subject'] ) ? mb_substr( sanitize_text_field( (string) $raw['activation_subject'] ), 0, 200 ) : '',
			'activation_message' => isset( $raw['activation_message'] ) && is_scalar( $raw['activation_message'] ) ? mb_substr( sanitize_textarea_field( (string) $raw['activation_message'] ), 0, 5000 ) : '',
			'activated_url'      => isset( $raw['activated_url'] ) && is_scalar( $raw['activated_url'] ) && trim( (string) $raw['activated_url'] ) !== '' ? esc_url_raw( trim( (string) $raw['activated_url'] ) ) : '',
			'pending_message'    => isset( $raw['pending_message'] ) && is_scalar( $raw['pending_message'] ) ? mb_substr( sanitize_text_field( (string) $raw['pending_message'] ), 0, 300 ) : '',
		);
		return ( ! $out['enabled'] && $out['email'] === '' ) ? array() : $out;
	}

	/** Form save check: 403 when locked, 422 for unusable mappings. */
	public static function validate_settings( array $settings, array $form ) {
		$s = is_array( $settings['register_user'] ?? null ) ? $settings['register_user'] : array();
		if ( empty( $s['enabled'] ) ) {
			return true;
		}
		if ( ! Capabilities::can( Capabilities::USER_REGISTRATION ) ) {
			return Capabilities::locked_error( Capabilities::USER_REGISTRATION );
		}
		$fields = Mapping::fields_by_id( $form );
		$bad    = static function ( $msg ) {
			return new \WP_Error( 'invalid_form_data', $msg, array( 'status' => 422 ) );
		};
		if ( ( $fields[ $s['email'] ?? '' ]['type'] ?? '' ) !== 'email' ) {
			return $bad( __( 'User registration needs an Email field for the account email.', 'neural-forms-lite' ) );
		}
		if ( ! self::is_safe_role( (string) ( $s['role'] ?? '' ) ) ) {
			return $bad( __( 'That role cannot be given from a form. Choose a role without admin or editing rights, such as Subscriber.', 'neural-forms-lite' ) );
		}
		$types = array(
			'username'   => array( 'text' ),
			'password'   => array( 'password' ),
			'first_name' => array( 'text', 'name' ),
			'last_name'  => array( 'text' ),
			'website'    => array( 'url', 'text' ),
		);
		foreach ( $types as $key => $allowed ) {
			$id = (string) ( $s[ $key ] ?? '' );
			if ( $id !== '' && ! in_array( (string) ( $fields[ $id ]['type'] ?? '' ), $allowed, true ) ) {
				/* translators: %s: setting name, e.g. password */
				return $bad( sprintf( __( 'The field chosen for the account %s is missing or the wrong type.', 'neural-forms-lite' ), str_replace( '_', ' ', $key ) ) );
			}
		}
		foreach ( (array) ( $s['meta'] ?? array() ) as $m ) {
			if ( ! isset( $fields[ $m['field'] ?? '' ] ) ) {
				return $bad( __( 'A user custom field points at a field that is no longer on the form.', 'neural-forms-lite' ) );
			}
		}
		if ( ! empty( $s['activated_url'] ) && ! preg_match( '#^https?://#i', (string) $s['activated_url'] ) ) {
			return $bad( __( 'The page to open after activation must be a full http(s) address.', 'neural-forms-lite' ) );
		}
		return true;
	}

	/** Settings when registration should run for this request, else null. */
	public static function active_settings( array $form ): ?array {
		$s = $form['settings']['register_user'] ?? null;
		if ( ! is_array( $s ) || empty( $s['enabled'] ) || ! Capabilities::can( Capabilities::USER_REGISTRATION ) ) {
			return null;
		}
		return is_user_logged_in() ? null : $s;
	}

	/** Should the renderer replace the form with the "already logged in" message? */
	public static function hide_for_current_user( array $form ): ?string {
		$s = $form['settings']['register_user'] ?? null;
		if ( ! is_array( $s ) || empty( $s['enabled'] ) || ( $s['logged_in'] ?? 'hide' ) !== 'hide' || ! is_user_logged_in() || ! Capabilities::can( Capabilities::USER_REGISTRATION ) ) {
			return null;
		}
		// Builder / block-editor previews always show the form.
		if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( function_exists( 'is_preview' ) && is_preview() ) ) {
			return null;
		}
		return (string) ( $s['logged_in_message'] ?? '' ) !== '' ? (string) $s['logged_in_message'] : __( 'You are already registered and logged in.', 'neural-forms-lite' );
	}

	/** Username from the mapped field, else built from the email. */
	private static function username( array $s, array $form, array $data, string $email ): array {
		if ( ! empty( $s['username'] ) ) {
			return array( sanitize_user( Mapping::text( $form, $data, (string) $s['username'] ), true ), true );
		}
		$base = sanitize_user( strtolower( (string) strstr( $email, '@', true ) ), true );
		$base = $base !== '' ? substr( $base, 0, 50 ) : 'user';
		$name = $base;
		for ( $i = 2; username_exists( $name ) && $i < 100; $i++ ) {
			$name = $base . $i;
		}
		return array( $name, false );
	}

	/**
	 * Before the entry is saved: refuse a taken / invalid email or username
	 * as field errors (422, same shape as SubmissionValidator).
	 *
	 * @return true|\WP_Error
	 */
	public static function precheck( array $form, array $data ) {
		$s = self::active_settings( $form );
		if ( ! $s ) {
			return true;
		}
		$errors = array();
		$email  = sanitize_email( Mapping::text( $form, $data, (string) $s['email'] ) );
		if ( $email === '' || ! is_email( $email ) ) {
			$errors[ (string) $s['email'] ] = array( array( 'code' => 'invalid_email', 'message' => __( 'Please enter a valid email address.', 'neural-forms-lite' ) ) );
		} elseif ( email_exists( $email ) ) {
			$errors[ (string) $s['email'] ] = array( array( 'code' => 'email_exists', 'message' => __( 'An account with this email already exists. Please log in instead.', 'neural-forms-lite' ) ) );
		}
		if ( ! empty( $s['username'] ) ) {
			list( $login ) = self::username( $s, $form, $data, $email );
			if ( $login === '' || ! validate_username( $login ) || mb_strlen( $login ) > 60 ) {
				$errors[ (string) $s['username'] ] = array( array( 'code' => 'invalid_username', 'message' => __( 'Usernames may only use letters, numbers, spaces and . - _ @', 'neural-forms-lite' ) ) );
			} elseif ( username_exists( $login ) || in_array( strtolower( $login ), array_map( 'strtolower', (array) apply_filters( 'illegal_user_logins', array() ) ), true ) ) {
				$errors[ (string) $s['username'] ] = array( array( 'code' => 'username_exists', 'message' => __( 'This username is taken. Please choose another.', 'neural-forms-lite' ) ) );
			}
		}
		if ( ! $errors ) {
			return true;
		}
		return new \WP_Error(
			'invalid_submission',
			__( 'The submission contains errors. Please check the fields below.', 'neural-forms-lite' ),
			array( 'status' => 422, 'fields' => $errors )
		);
	}

	/**
	 * After the entry is saved: create the account. Never throws; failures
	 * are logged on the entry timeline.
	 *
	 * @param array<string,string> $passwords field id => password (never stored)
	 * @return int New user id, 0 when nothing was created.
	 */
	public static function register( int $entry_id, array $form, array $data, array $passwords ): int {
		$s = self::active_settings( $form );
		if ( ! $s ) {
			return 0;
		}
		$role = (string) ( $s['role'] ?? 'subscriber' );
		if ( ! self::is_safe_role( $role ) ) { // role may have gained capabilities since the form was saved
			EntryActivity::record( $entry_id, 'entry.user_failed', __( 'Account not created: the chosen role is no longer allowed for forms.', 'neural-forms-lite' ), array(), 'error' );
			return 0;
		}
		$email               = sanitize_email( Mapping::text( $form, $data, (string) $s['email'] ) );
		list( $login )       = self::username( $s, $form, $data, $email );
		$password            = ! empty( $s['password'] ) ? (string) ( $passwords[ (string) $s['password'] ] ?? '' ) : '';
		$generated           = $password === '';
		$first               = '';
		$last                = '';
		$fields              = Mapping::fields_by_id( $form );
		$first_id            = (string) ( $s['first_name'] ?? '' );
		if ( $first_id !== '' && ( $fields[ $first_id ]['type'] ?? '' ) === 'name' && is_array( $data[ $first_id ] ?? null ) ) {
			$first = sanitize_text_field( (string) ( $data[ $first_id ]['first'] ?? '' ) );
			$last  = sanitize_text_field( (string) ( $data[ $first_id ]['last'] ?? '' ) );
		} else {
			$first = sanitize_text_field( Mapping::text( $form, $data, $first_id ) );
			$last  = sanitize_text_field( Mapping::text( $form, $data, (string) ( $s['last_name'] ?? '' ) ) );
		}
		$website = esc_url_raw( Mapping::text( $form, $data, (string) ( $s['website'] ?? '' ) ) );

		$user_id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_email'   => $email,
				'user_pass'    => $generated ? wp_generate_password( 24, true, true ) : $password,
				'first_name'   => mb_substr( $first, 0, 100 ),
				'last_name'    => mb_substr( $last, 0, 100 ),
				'display_name' => trim( $first . ' ' . $last ) !== '' ? mb_substr( trim( $first . ' ' . $last ), 0, 200 ) : $login,
				'user_url'     => $website,
				'role'         => $role,
			)
		);
		unset( $password );
		if ( is_wp_error( $user_id ) || ! is_int( $user_id ) || $user_id <= 0 ) {
			EntryActivity::record( $entry_id, 'entry.user_failed', __( 'Account not created: ', 'neural-forms-lite' ) . ( is_wp_error( $user_id ) ? $user_id->get_error_message() : __( 'unknown error', 'neural-forms-lite' ) ), array(), 'error' );
			return 0;
		}

		foreach ( (array) ( $s['meta'] ?? array() ) as $m ) {
			$key = Mapping::safe_meta_key( $m['key'] ?? '' );
			if ( $key !== '' ) {
				update_user_meta( $user_id, $key, mb_substr( sanitize_textarea_field( Mapping::text( $form, $data, (string) $m['field'] ) ), 0, 5000 ) );
			}
		}
		update_user_meta( $user_id, '_neural_forms_entry_id', $entry_id );

		// Held account: no role, no login, no login-details email until it
		// is confirmed (email link) or approved (admin).
		$mode = in_array( (string) ( $s['activation'] ?? 'none' ), array( 'email', 'admin' ), true ) ? (string) $s['activation'] : 'none';
		if ( $mode !== 'none' ) {
			AccountActivation::hold( $user_id, array( 'notify_user' => ! empty( $s['notify_user'] ) || $generated ) + $s, $form, $entry_id, $role );
			if ( ! empty( $s['notify_admin'] ) && $mode === 'email' ) {
				wp_new_user_notification( $user_id, null, 'admin' );
			}
			do_action( 'neural_forms_user_registered', $user_id, $entry_id, $form );
			return $user_id;
		}

		$notify = ( ! empty( $s['notify_admin'] ) ? 'admin' : '' ) . ( ( ! empty( $s['notify_user'] ) || $generated ) ? 'user' : '' );
		if ( $notify !== '' ) {
			wp_new_user_notification( $user_id, null, $notify === 'adminuser' ? 'both' : $notify );
		}

		EntryActivity::record(
			$entry_id,
			'entry.user_registered',
			/* translators: %s: username */
			sprintf( __( 'Account created: %s', 'neural-forms-lite' ), $login ),
			array( 'user_id' => $user_id, 'role' => $role, 'edit_url' => admin_url( 'user-edit.php?user_id=' . $user_id ) )
		);

		/**
		 * Fires after a form created a user account.
		 *
		 * @param int   $user_id
		 * @param int   $entry_id
		 * @param array $form
		 */
		do_action( 'neural_forms_user_registered', $user_id, $entry_id, $form );

		if ( ! empty( $s['auto_login'] ) && ! is_user_logged_in() ) {
			wp_set_current_user( $user_id );
			wp_set_auth_cookie( $user_id, false, is_ssl() );
		}
		return $user_id;
	}
}
