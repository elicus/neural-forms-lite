<?php
namespace NeuralForms\Admin;

use NeuralForms\Core\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Pre-deactivation data-retention page (added in 0.2.0).
 *
 * WordPress provides no reliable way to render a custom UI when the
 * user clicks Plugins → Deactivate (the request returns directly to
 * plugins.php and the plugin code is no longer loaded on the next
 * request). The redirect-after-deactivation pattern is also racy:
 * by the time admin_init fires, the Plugins screen has already
 * rendered the "deactivated" notice, and the user has already
 * decided what to do next.
 *
 * Approach: a plugin-row action link routes the user to a hidden
 * admin page WHILE THE PLUGIN IS STILL ACTIVE. The page asks them
 * to decide between keep / delete-on-uninstall / cancel. After the
 * choice, we save the option (or not) and call deactivate_plugins()
 * programmatically. This is reliable because everything happens
 * before deactivation, with our code fully loaded.
 *
 * Standard "Deactivate" link continues to work and is data-safe — uninstall.php
 * gates destructive cleanup behind delete_data_on_uninstall (default
 * FALSE), so a user who never visits this page and later deletes the
 * plugin still keeps their data.
 *
 * Routes:
 *   admin.php?page=neural-forms-deactivation        — render the choice page (hidden submenu)
 *   admin-post.php?action=neural_forms_deactivate    — handle form post
 *
 * Security:
 *   - capability: activate_plugins (same gate WP requires for
 *     Deactivate / Delete in the plugin row)
 *   - nonce: wp_nonce_field( 'neural_forms_deactivate' )
 *   - destructive option requires an explicit confirmation checkbox
 */
class DeactivationPage {

	const PAGE_SLUG       = 'neural-forms-deactivation';
	const ADMIN_POST_NAME = 'neural_forms_deactivate';
	const NONCE_ACTION    = 'neural_forms_deactivate';
	const NONCE_NAME      = 'neural_forms_deactivate_nonce';

	public static function boot(): void {
		add_action( 'admin_menu', [ self::class, 'register_page' ] );
		add_action( 'admin_post_' . self::ADMIN_POST_NAME, [ self::class, 'handle_post' ] );
		add_action( 'admin_notices', [ self::class, 'maybe_render_notice' ] );

		// Plugin row action link — only Lite's own row.
		if ( defined( 'NEURAL_FORMS_BASENAME' ) ) {
			add_filter(
				'plugin_action_links_' . NEURAL_FORMS_BASENAME,
				[ self::class, 'filter_action_links' ]
			);
		}
	}

	/**
	 * Register a hidden submenu page. Passing null as the parent slug
	 * keeps it out of the menu but still routable via
	 * admin.php?page={slug}. activate_plugins gate matches the cap
	 * required to deactivate a plugin in the first place.
	 */
	public static function register_page(): void {
		add_submenu_page(
			null, // hidden — not in menu
			__( 'Before you go', 'neural-forms-lite' ),
			__( 'Neural Forms — deactivation', 'neural-forms-lite' ),
			'activate_plugins',
			self::PAGE_SLUG,
			[ self::class, 'render_page' ]
		);
	}

	/**
	 * Inject "Deactivate options" before WP's stock "Deactivate" link.
	 * Original Deactivate stays in place — clicking it follows the
	 * normal WP flow (data-safe by default per uninstall.php gate).
	 */
	public static function filter_action_links( array $links ): array {
		if ( ! current_user_can( 'activate_plugins' ) ) return $links;

		$url = add_query_arg(
			[ 'page' => self::PAGE_SLUG ],
			admin_url( 'admin.php' )
		);

		$cleanup_link = '<a href="' . esc_url( $url ) . '" aria-label="'
			. esc_attr__( 'Open Neural Forms deactivation options', 'neural-forms-lite' )
			. '">' . esc_html__( 'Deactivate options', 'neural-forms-lite' ) . '</a>';

		// Insert as the first action so it sits before "Deactivate".
		// Preserve all other links untouched (Pro may add its own).
		return array_merge( [ 'neural_forms_deactivate_options' => $cleanup_link ], $links );
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'neural-forms-lite' ) );
		}

		$current = (bool) Config::get( 'delete_data_on_uninstall', false );
		$post_url = admin_url( 'admin-post.php' );
		$plugins_url = admin_url( 'plugins.php' );

		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Before you go', 'neural-forms-lite' ); ?></h1>

			<p style="font-size:14px;max-width:680px">
				<?php echo esc_html__( 'Do you want to keep your Neural Forms data when you deactivate (or later delete) this plugin?', 'neural-forms-lite' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( $post_url ); ?>" style="max-width:720px;margin-top:24px">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ADMIN_POST_NAME ); ?>" />
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>

				<?php /*
				 * Three radio choices. Default selection mirrors the
				 * user's current preference (so re-opening the page
				 * doesn't silently flip their setting). For first-time
				 * visits the default is "keep" — the safe choice.
				 */ ?>

				<div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:16px;margin-bottom:12px">
					<label style="display:block;font-weight:600;font-size:14px;margin-bottom:6px">
						<input type="radio" name="choice" value="keep" <?php checked( $current === false ); ?> />
						<?php echo esc_html__( 'Keep data and deactivate', 'neural-forms-lite' ); ?>
						<span style="margin-left:6px;background:#ecfdf5;color:#047857;border:1px solid #a7f3d0;border-radius:999px;padding:1px 8px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.04em">
							<?php echo esc_html__( 'Recommended', 'neural-forms-lite' ); ?>
						</span>
					</label>
					<p style="margin:0;color:#6b7280;font-size:13px">
						<?php echo esc_html__( 'Forms, entries, settings, analytics, and AI context stay in your database. You can reactivate at any time and pick up where you left off.', 'neural-forms-lite' ); ?>
					</p>
				</div>

				<div style="background:#fff;border:1px solid #fecaca;border-radius:8px;padding:16px;margin-bottom:12px">
					<label style="display:block;font-weight:600;font-size:14px;margin-bottom:6px">
						<input type="radio" name="choice" value="delete_on_uninstall" <?php checked( $current === true ); ?> />
						<?php echo esc_html__( 'Delete all data when the plugin is uninstalled', 'neural-forms-lite' ); ?>
					</label>
					<p style="margin:0 0 8px;color:#6b7280;font-size:13px">
						<?php echo esc_html__( 'The plugin deactivates now, but data stays until you actually delete the plugin in WP Admin → Plugins. At that moment, all forms, entries, settings, AI context, and the plugin tables are removed.', 'neural-forms-lite' ); ?>
					</p>
					<label style="display:flex;align-items:flex-start;gap:8px;color:#b91c1c;font-size:13px">
						<input type="checkbox" name="confirm_delete" value="1" />
						<span><?php echo esc_html__( 'I understand this will permanently delete all Neural Forms data when the plugin is deleted.', 'neural-forms-lite' ); ?></span>
					</label>
				</div>

				<div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:16px;margin-bottom:20px">
					<label style="display:block;font-weight:600;font-size:14px;margin-bottom:6px">
						<input type="radio" name="choice" value="cancel" />
						<?php echo esc_html__( 'Cancel', 'neural-forms-lite' ); ?>
					</label>
					<p style="margin:0;color:#6b7280;font-size:13px">
						<?php echo esc_html__( 'Close this page without changing anything. The plugin stays active.', 'neural-forms-lite' ); ?>
					</p>
				</div>

				<p>
					<button type="submit" class="button button-primary">
						<?php echo esc_html__( 'Continue', 'neural-forms-lite' ); ?>
					</button>
					<a href="<?php echo esc_url( $plugins_url ); ?>" class="button" style="margin-left:8px">
						<?php echo esc_html__( 'Back to Plugins', 'neural-forms-lite' ); ?>
					</a>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle the form post. Always validates capability + nonce
	 * before doing anything; on success, saves the option (or not)
	 * and either calls deactivate_plugins() programmatically OR (for
	 * cancel) just redirects back to plugins.php.
	 */
	public static function handle_post(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'neural-forms-lite' ) );
		}

		$nonce = isset( $_POST[ self::NONCE_NAME ] )
			? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) )
			: '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Security check failed. Please try again.', 'neural-forms-lite' ) );
		}

		$choice = isset( $_POST['choice'] )
			? sanitize_key( wp_unslash( $_POST['choice'] ) )
			: '';

		$plugins_url = admin_url( 'plugins.php' );

		switch ( $choice ) {
			case 'keep':
				// Persist the no-delete preference. Even users who
				// previously enabled delete-on-uninstall can change
				// their mind here.
				Config::set( 'delete_data_on_uninstall', false );
				self::deactivate_self();
				wp_safe_redirect( add_query_arg( 'neural_forms_deactivated', 'kept', $plugins_url ) );
				exit;

			case 'delete_on_uninstall':
				// Require explicit confirmation. Without it, fall
				// through to a no-op redirect (user must check the box).
				$confirmed = ! empty( $_POST['confirm_delete'] );
				if ( ! $confirmed ) {
					wp_safe_redirect(
						add_query_arg(
							[
								'page'                            => self::PAGE_SLUG,
								'neural_forms_needs_confirmation' => '1',
							],
							admin_url( 'admin.php' )
						)
					);
					exit;
				}
				Config::set( 'delete_data_on_uninstall', true );
				self::deactivate_self();
				wp_safe_redirect( add_query_arg( 'neural_forms_deactivated', 'delete_on_uninstall', $plugins_url ) );
				exit;

			case 'cancel':
			default:
				// No state change, no deactivation. Just go back.
				wp_safe_redirect( $plugins_url );
				exit;
		}
	}

	/**
	 * Programmatically deactivate Lite. deactivate_plugins() is
	 * available because admin-post.php loaded wp-admin/includes;
	 * if for any reason the include is missing, pull it in.
	 */
	private static function deactivate_self(): void {
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( defined( 'NEURAL_FORMS_BASENAME' ) ) {
			deactivate_plugins( NEURAL_FORMS_BASENAME );
		}
	}

	/**
	 * Render success notice on plugins.php after a deactivation choice.
	 * Reads ?neural_forms_deactivated=... from the URL — set by
	 * handle_post() before the redirect.
	 *
	 * Also surfaces a "please tick the confirmation checkbox" hint
	 * when the user picked delete-on-uninstall without confirming.
	 */
	public static function maybe_render_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( $screen && $screen->id === 'plugins' && isset( $_GET['neural_forms_deactivated'] ) ) {
			$state = sanitize_key( wp_unslash( $_GET['neural_forms_deactivated'] ) );
			if ( $state === 'kept' ) {
				echo '<div class="notice notice-success is-dismissible"><p>'
					. esc_html__( 'Neural Forms was deactivated. Your forms and entries were kept.', 'neural-forms-lite' )
					. '</p></div>';
			} elseif ( $state === 'delete_on_uninstall' ) {
				echo '<div class="notice notice-warning is-dismissible"><p>'
					. esc_html__( 'Neural Forms was deactivated. Your data will be deleted if you uninstall the plugin from this Plugins screen.', 'neural-forms-lite' )
					. '</p></div>';
			}
		}

		if ( $screen && $screen->id === 'admin_page_' . self::PAGE_SLUG && ! empty( $_GET['neural_forms_needs_confirmation'] ) ) {
			echo '<div class="notice notice-error"><p>'
				. esc_html__( 'Please tick the confirmation checkbox before choosing to delete data on uninstall.', 'neural-forms-lite' )
				. '</p></div>';
		}
	}
}
