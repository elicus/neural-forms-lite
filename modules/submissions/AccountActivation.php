<?php
namespace NeuralForms\Submissions;

use NeuralForms\Core\Config;
use NeuralForms\Notifications\NotificationService;
use NeuralForms\Storage\EntryActivity;

defined( 'ABSPATH' ) || exit;

/**
 * Account activation for form registrations (1.3.0, `user_registration`).
 *
 * register_user.activation = none | email | admin
 *
 * - A held account is a normal user with NO role plus META_PENDING
 *   { mode, role, form_id, entry_id, auto_login, notify_user, redirect }.
 *   The email / username stay reserved while the person confirms.
 * - Email mode: one-time link ?nf_activate={user}&key={key}, 48 h. Only
 *   HMAC(key) is stored (META_KEY), so a database read can't forge a link.
 * - Admin mode: the admin is emailed a link to Users › Pending, where the
 *   Approve row action (nonce + promote_users) activates the account.
 * - While held: login is refused AFTER the password checks out (wrong
 *   passwords get WordPress's normal error, so the state never leaks) and a
 *   new link is sent, max RESEND_PER_HOUR; password reset is refused.
 * - Activation re-checks that the stored role is still a safe role.
 * - Nothing here deletes users: Users › "Pending confirmation" lists held
 *   accounts so the owner can use WordPress's own Delete screen.
 *
 * The hooks stay active even when Pro is gone, so held accounts stay held.
 */
final class AccountActivation {

	public const META_PENDING    = '_neural_forms_pending';
	public const META_KEY        = '_neural_forms_activation';
	public const TTL             = 2 * DAY_IN_SECONDS;
	public const RESEND_PER_HOUR = 3;
	public const MODES           = array( 'none', 'email', 'admin' );

	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'handle_link' ), 5 );
		add_filter( 'wp_authenticate_user', array( __CLASS__, 'block_login' ), 30, 2 );
		add_filter( 'allow_password_reset', array( __CLASS__, 'block_reset' ), 10, 2 );
		add_filter( 'login_message', array( __CLASS__, 'login_message' ) );
		add_filter( 'views_users', array( __CLASS__, 'users_view' ) );
		add_action( 'pre_get_users', array( __CLASS__, 'users_query' ) );
		add_filter( 'user_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_action( 'admin_init', array( __CLASS__, 'handle_admin_action' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
	}

	public static function default_subject(): string {
		return __( 'Confirm your account at {site_name}', 'neural-forms-lite' );
	}

	public static function default_message(): string {
		return __( "Hi {first_name},\n\nThanks for signing up at {site_name}. Please confirm your email address to activate your account:\n\n{activation_link}\n\nThe link works for 48 hours. If you did not sign up, you can ignore this email.", 'neural-forms-lite' );
	}

	public static function pending( int $user_id ): ?array {
		$p = get_user_meta( $user_id, self::META_PENDING, true );
		return is_array( $p ) && in_array( $p['mode'] ?? '', array( 'email', 'admin' ), true ) ? $p : null;
	}

	public static function hash_key( string $key ): string {
		return hash_hmac( 'sha256', $key, wp_salt( 'auth' ) );
	}

	/** Put a just-created account on hold and send the right email. */
	public static function hold( int $user_id, array $s, array $form, int $entry_id, string $role ): void {
		$mode = (string) $s['activation'];
		$user = get_userdata( $user_id );
		if ( $user ) {
			$user->set_role( '' ); // no capabilities at all until confirmed
		}
		update_user_meta(
			$user_id,
			self::META_PENDING,
			array(
				'mode'        => $mode,
				'role'        => $role,
				'form_id'     => (int) ( $form['id'] ?? 0 ),
				'entry_id'    => $entry_id,
				'auto_login'  => ! empty( $s['auto_login'] ),
				'notify_user' => ! empty( $s['notify_user'] ),
				'redirect'    => (string) ( $s['activated_url'] ?? '' ),
				'created'     => time(),
			)
		);
		if ( $mode === 'email' ) {
			self::send_link( $user_id, $s );
			EntryActivity::record( $entry_id, 'entry.user_pending', __( 'Account waiting for email confirmation.', 'neural-forms-lite' ), array( 'user_id' => $user_id ) );
		} else {
			self::notify_admin( $user_id, $form );
			EntryActivity::record( $entry_id, 'entry.user_pending', __( 'Account waiting for admin approval.', 'neural-forms-lite' ), array( 'user_id' => $user_id ) );
		}
	}

	/** New one-time key (replaces any older one) and the confirmation email. */
	public static function send_link( int $user_id, ?array $s = null ): bool {
		$user = get_userdata( $user_id );
		if ( ! $user || ! self::pending( $user_id ) ) {
			return false;
		}
		if ( null === $s ) {
			$p    = self::pending( $user_id );
			$form = \NeuralForms\Forms\FormRepository::find( (int) $p['form_id'] );
			$s    = is_array( $form['settings']['register_user'] ?? null ) ? $form['settings']['register_user'] : array();
		}
		$key = wp_generate_password( 32, false, false );
		update_user_meta( $user_id, self::META_KEY, array( 'hash' => self::hash_key( $key ), 'expires' => time() + self::TTL ) );
		$link    = add_query_arg( array( 'nf_activate' => $user_id, 'key' => $key ), home_url( '/' ) );
		$replace = array(
			'{activation_link}' => $link,
			'{first_name}'      => $user->first_name !== '' ? $user->first_name : $user->display_name,
			'{username}'        => $user->user_login,
			'{site_name}'       => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
		);
		$subject = strtr( (string) ( $s['activation_subject'] ?? '' ) !== '' ? (string) $s['activation_subject'] : self::default_subject(), $replace );
		$body    = (string) ( $s['activation_message'] ?? '' ) !== '' ? (string) $s['activation_message'] : self::default_message();
		if ( strpos( $body, '{activation_link}' ) === false ) {
			$body .= "\n\n{activation_link}";
		}
		return NotificationService::send_mail( $user->user_email, $subject, strtr( $body, $replace ) );
	}

	private static function notify_admin( int $user_id, array $form ): void {
		$user = get_userdata( $user_id );
		$to   = (string) Config::get( 'admin_notification_email' );
		$to   = $to !== '' ? $to : (string) get_option( 'admin_email' );
		if ( ! $user || $to === '' ) {
			return;
		}
		NotificationService::send_mail(
			$to,
			/* translators: %s: site name */
			sprintf( __( '[%s] New account waiting for approval', 'neural-forms-lite' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
			sprintf(
				/* translators: 1: username, 2: email, 3: form name, 4: link */
				__( "A new account was created with the form \"%3\$s\" and is waiting for your approval.\n\nUsername: %1\$s\nEmail: %2\$s\n\nReview it here: %4\$s", 'neural-forms-lite' ),
				$user->user_login,
				$user->user_email,
				(string) ( $form['name'] ?? '' ),
				admin_url( 'users.php?nf_pending=1' )
			)
		);
	}

	/**
	 * Give the stored role and lift the hold. Returns the user id or a
	 * WP_Error. $source: 'link' | 'admin'.
	 *
	 * @return int|\WP_Error
	 */
	public static function activate( int $user_id, string $source ) {
		$p    = self::pending( $user_id );
		$user = get_userdata( $user_id );
		if ( ! $p || ! $user ) {
			return new \WP_Error( 'nf_not_pending', __( 'This account is already active.', 'neural-forms-lite' ) );
		}
		$role = (string) ( $p['role'] ?? '' );
		if ( ! UserRegistration::is_safe_role( $role ) ) {
			return new \WP_Error( 'nf_role_unsafe', __( 'This account cannot be activated: its role is no longer allowed for forms. Please contact the site owner.', 'neural-forms-lite' ) );
		}
		$user->set_role( $role );
		delete_user_meta( $user_id, self::META_PENDING );
		delete_user_meta( $user_id, self::META_KEY );
		if ( ! empty( $p['entry_id'] ) ) {
			EntryActivity::record(
				(int) $p['entry_id'],
				'entry.user_activated',
				$source === 'admin' ? __( 'Account approved by an admin.', 'neural-forms-lite' ) : __( 'Account confirmed by email.', 'neural-forms-lite' ),
				array( 'user_id' => $user_id )
			);
		}
		// Login details (or the set-password link) only once the account works.
		if ( ! empty( $p['notify_user'] ) || $source === 'admin' ) {
			wp_new_user_notification( $user_id, null, 'user' );
		}
		/**
		 * Fires when a held form registration becomes active.
		 *
		 * @param int    $user_id
		 * @param string $source 'link' | 'admin'
		 */
		do_action( 'neural_forms_user_activated', $user_id, $source );
		return $user_id;
	}

	/**
	 * Check an activation link. Does not redirect (handle_link() does).
	 *
	 * @return int|\WP_Error
	 */
	public static function verify_link( int $user_id, string $key ) {
		$p      = self::pending( $user_id );
		$stored = get_user_meta( $user_id, self::META_KEY, true );
		if ( ! $p || $p['mode'] !== 'email' || ! is_array( $stored ) || $key === '' ) {
			return new \WP_Error( 'nf_invalid_link', __( 'This activation link is not valid.', 'neural-forms-lite' ) );
		}
		if ( ! hash_equals( (string) ( $stored['hash'] ?? '' ), self::hash_key( $key ) ) ) {
			return new \WP_Error( 'nf_invalid_link', __( 'This activation link is not valid.', 'neural-forms-lite' ) );
		}
		if ( (int) ( $stored['expires'] ?? 0 ) < time() ) {
			return new \WP_Error( 'nf_expired_link', __( 'This activation link has expired.', 'neural-forms-lite' ) );
		}
		return self::activate( $user_id, 'link' );
	}

	public static function handle_link(): void {
		if ( ! isset( $_GET['nf_activate'], $_GET['key'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the key is the proof
			return;
		}
		$user_id = absint( wp_unslash( $_GET['nf_activate'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$key     = preg_replace( '/[^A-Za-z0-9]/', '', (string) wp_unslash( $_GET['key'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$p       = self::pending( $user_id );
		$result  = self::verify_link( $user_id, (string) $key );
		if ( is_wp_error( $result ) ) {
			$code = $result->get_error_code() === 'nf_expired_link' ? 'expired' : ( $result->get_error_code() === 'nf_role_unsafe' ? 'role' : 'invalid' );
			wp_safe_redirect( add_query_arg( 'nf_activation', $code, wp_login_url() ) );
			exit;
		}
		$target = $p && ! empty( $p['redirect'] ) ? wp_validate_redirect( (string) $p['redirect'], '' ) : '';
		if ( $p && ! empty( $p['auto_login'] ) && ! is_user_logged_in() ) {
			wp_set_current_user( $user_id );
			wp_set_auth_cookie( $user_id, false, is_ssl() );
			wp_safe_redirect( $target !== '' ? $target : home_url( '/' ) );
			exit;
		}
		wp_safe_redirect( $target !== '' ? $target : add_query_arg( 'nf_activation', 'done', wp_login_url() ) );
		exit;
	}

	/** Held accounts can't log in. Only answered once the password is right. */
	public static function block_login( $user, $password ) {
		if ( ! $user instanceof \WP_User || ! self::pending( (int) $user->ID ) ) {
			return $user;
		}
		if ( ! wp_check_password( (string) $password, $user->data->user_pass, $user->ID ) ) {
			return $user; // WordPress reports the wrong password as usual
		}
		$p = self::pending( (int) $user->ID );
		if ( $p['mode'] === 'admin' ) {
			return new \WP_Error( 'nf_pending_approval', __( 'Your account is waiting for approval. You will get an email once it is active.', 'neural-forms-lite' ) );
		}
		$rl   = 'nf_act_resend_' . (int) $user->ID;
		$sent = (int) get_transient( $rl );
		if ( $sent < self::RESEND_PER_HOUR && self::send_link( (int) $user->ID ) ) {
			set_transient( $rl, $sent + 1, HOUR_IN_SECONDS );
			return new \WP_Error( 'nf_pending_email', __( 'Please confirm your email address first. We have just sent you a new activation link.', 'neural-forms-lite' ) );
		}
		return new \WP_Error( 'nf_pending_email', __( 'Please confirm your email address first, using the link we emailed you.', 'neural-forms-lite' ) );
	}

	public static function block_reset( $allow, $user_id ) {
		return self::pending( (int) $user_id ) ? false : $allow;
	}

	public static function login_message( $message ) {
		$state = isset( $_GET['nf_activation'] ) ? sanitize_key( wp_unslash( $_GET['nf_activation'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$texts = array(
			'done'    => array( 'message', __( 'Your account is active. You can log in now.', 'neural-forms-lite' ) ),
			'expired' => array( 'error', __( 'That activation link has expired. Log in with your username and password and we will email you a new one.', 'neural-forms-lite' ) ),
			'invalid' => array( 'error', __( 'That activation link is not valid, or the account is already active.', 'neural-forms-lite' ) ),
			'role'    => array( 'error', __( 'This account cannot be activated. Please contact the site owner.', 'neural-forms-lite' ) ),
		);
		if ( ! isset( $texts[ $state ] ) ) {
			return $message;
		}
		return $message . '<p class="' . esc_attr( $texts[ $state ][0] ) . '">' . esc_html( $texts[ $state ][1] ) . '</p>';
	}

	// ── Users screen ─────────────────────────────────────────────────────

	private static function pending_count(): int {
		$q = new \WP_User_Query( array( 'meta_key' => self::META_PENDING, 'meta_compare' => 'EXISTS', 'fields' => 'ID', 'number' => 1, 'count_total' => true ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		return (int) $q->get_total();
	}

	public static function users_view( $views ) {
		$n = self::pending_count();
		if ( $n > 0 ) {
			$current                 = ! empty( $_GET['nf_pending'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$views['nf_pending'] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url( admin_url( 'users.php?nf_pending=1' ) ),
				$current ? ' class="current" aria-current="page"' : '',
				esc_html__( 'Pending confirmation', 'neural-forms-lite' ),
				$n
			);
		}
		return $views;
	}

	public static function users_query( $query ): void {
		if ( is_admin() && ! empty( $_GET['nf_pending'] ) && function_exists( 'get_current_screen' ) && get_current_screen() && get_current_screen()->id === 'users' ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$query->set( 'meta_key', self::META_PENDING );
			$query->set( 'meta_compare', 'EXISTS' );
		}
	}

	public static function row_actions( $actions, $user ) {
		if ( ! $user instanceof \WP_User || ! current_user_can( 'promote_users' ) ) {
			return $actions;
		}
		$p = self::pending( (int) $user->ID );
		if ( ! $p ) {
			return $actions;
		}
		$url = static function ( $do ) use ( $user ) {
			return wp_nonce_url( admin_url( 'users.php?nf_account=' . $do . '&user_id=' . (int) $user->ID ), 'nf_account_' . $do . '_' . (int) $user->ID );
		};
		$actions['nf_approve'] = '<a href="' . esc_url( $url( 'approve' ) ) . '">' . esc_html__( 'Approve', 'neural-forms-lite' ) . '</a>';
		if ( $p['mode'] === 'email' ) {
			$actions['nf_resend'] = '<a href="' . esc_url( $url( 'resend' ) ) . '">' . esc_html__( 'Resend confirmation', 'neural-forms-lite' ) . '</a>';
		}
		$actions['nf_pending_label'] = '<span style="color:#996800">' . esc_html( $p['mode'] === 'admin' ? __( 'Waiting for approval', 'neural-forms-lite' ) : __( 'Waiting for email confirmation', 'neural-forms-lite' ) ) . '</span>';
		return $actions;
	}

	public static function handle_admin_action(): void {
		if ( empty( $_GET['nf_account'] ) || empty( $_GET['user_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$do      = sanitize_key( wp_unslash( $_GET['nf_account'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$user_id = absint( $_GET['user_id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $do, array( 'approve', 'resend' ), true ) ) {
			return;
		}
		check_admin_referer( 'nf_account_' . $do . '_' . $user_id );
		if ( ! current_user_can( 'promote_users' ) || ! current_user_can( 'edit_user', $user_id ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'neural-forms-lite' ), 403 );
		}
		$result = $do === 'approve' ? self::activate( $user_id, 'admin' ) : ( self::send_link( $user_id ) ? $user_id : new \WP_Error( 'nf_send', '' ) );
		wp_safe_redirect( add_query_arg( array( 'nf_pending' => self::pending_count() > 0 ? 1 : false, 'nf_done' => is_wp_error( $result ) ? 'failed' : $do ), admin_url( 'users.php' ) ) );
		exit;
	}

	public static function admin_notice(): void {
		$done = isset( $_GET['nf_done'] ) ? sanitize_key( wp_unslash( $_GET['nf_done'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$map  = array(
			'approve' => array( 'success', __( 'Account approved. The user has been emailed.', 'neural-forms-lite' ) ),
			'resend'  => array( 'success', __( 'Confirmation email sent again.', 'neural-forms-lite' ) ),
			'failed'  => array( 'error', __( 'That did not work. The account may already be active, or its role is no longer allowed for forms.', 'neural-forms-lite' ) ),
		);
		if ( isset( $map[ $done ] ) ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $map[ $done ][0] ), esc_html( $map[ $done ][1] ) );
		}
	}
}
