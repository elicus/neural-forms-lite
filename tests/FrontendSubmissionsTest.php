<?php
/**
 * Front-end submissions (1.3.0): post creation + user registration
 * settings, safety rules and escaping.
 *
 *   php tests/FrontendSubmissionsTest.php
 */
namespace NeuralForms\Notifications {
	class NotificationService {
		public static function render_tokens( string $t, array $form, array $data, int $entry_id = 0 ): string {
			return preg_replace_callback( '/\{([A-Za-z0-9_\-]+)\}/', function ( $m ) use ( $data ) {
				$v = $data[ $m[1] ] ?? null;
				if ( is_array( $v ) ) { return implode( ' ', array_filter( $v, 'is_scalar' ) ); }
				return $v === null ? $m[0] : (string) $v;
			}, $t );
		}
	}
}
namespace NeuralForms\Core {
	class Capabilities {
		const FRONTEND_POSTS = 'frontend_posts'; const USER_REGISTRATION = 'user_registration';
		public static $on = true;
		public static function can( $c ) { return self::$on; }
		public static function locked_error( $c ) { return new \WP_Error( 'pro_feature_locked', 'locked', array( 'status' => 403 ) ); }
	}
}
namespace NeuralForms\Storage { class EntryActivity { public static function record() {} } }
namespace NeuralForms\Forms {
	class SchemaHelper { public static function flatten_fields( array $f ) { return $f; } }
}
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'NEURAL_FORMS_CAP_MANAGE_FORMS', 'neural_forms_manage' );
	define( 'DAY_IN_SECONDS', 86400 );
	function esc_url_raw( $u ) { return preg_match( '#^https?://#i', $u ) ? $u : ''; }
	class WP_Error { public $c; public $d; function __construct( $c, $m = '', $d = array() ) { $this->c = $c; $this->d = $d; } function get_error_code() { return $this->c; } }
	function is_wp_error( $x ) { return $x instanceof WP_Error; }
	function __( $s ) { return $s; }
	function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
	function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
	function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
	function wp_kses_post( $s ) { return preg_replace( '#<script\b[^>]*>.*?</script>#is', '', (string) $s ); }
	function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
	function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
	function translate_user_role( $s ) { return $s; }
	function user_can( $u, $c ) { return $u === 5; }
	function is_user_logged_in() { return false; }
	function sanitize_email( $s ) { return trim( (string) $s ); }
	function is_email( $s ) { return (bool) filter_var( $s, FILTER_VALIDATE_EMAIL ); }
	function email_exists( $s ) { return $s === 'taken@example.com' ? 3 : false; }
	function username_exists( $s ) { return $s === 'taken' ? 3 : false; }
	function validate_username( $s ) { return (bool) preg_match( '/^[a-z0-9 _.\-@]+$/i', $s ); }
	function sanitize_user( $s ) { return preg_replace( '/[^a-z0-9 _.\-@]/i', '', (string) $s ); }
	function apply_filters( $t, $v ) { return $v; }
	function get_post_types() {
		return array(
			'post' => (object) array( 'labels' => (object) array( 'singular_name' => 'Post' ) ),
			'attachment' => (object) array( 'labels' => (object) array( 'singular_name' => 'Media' ) ),
		);
	}
	function get_object_taxonomies() { return array( 'category' => (object) array( 'name' => 'category', 'show_ui' => true, 'labels' => (object) array( 'singular_name' => 'Category' ) ) ); }
	class WP_Roles_Stub { public $roles = array(
		'administrator' => array( 'name' => 'Administrator', 'capabilities' => array( 'manage_options' => true, 'read' => true ) ),
		'editor'        => array( 'name' => 'Editor', 'capabilities' => array( 'edit_others_posts' => true, 'read' => true ) ),
		'author'        => array( 'name' => 'Author', 'capabilities' => array( 'publish_posts' => true, 'upload_files' => true, 'read' => true ) ),
		'shop_manager'  => array( 'name' => 'Shop manager', 'capabilities' => array( 'manage_woocommerce' => true ) ),
		'subscriber'    => array( 'name' => 'Subscriber', 'capabilities' => array( 'read' => true ) ),
		'sneaky'        => array( 'name' => 'Sneaky', 'capabilities' => array( 'read' => true, 'neural_forms_manage' => true ) ),
	); }
	function wp_roles() { return new WP_Roles_Stub(); }
	$GLOBALS['wpdb'] = (object) array( 'base_prefix' => 'wp_' );

	$root = dirname( __DIR__ );
	require $root . '/modules/submissions/Mapping.php';
	require $root . '/modules/submissions/PostSubmission.php';
	require $root . '/modules/submissions/UserRegistration.php';
	require $root . '/modules/submissions/AccountActivation.php';
	use NeuralForms\Submissions\Mapping;
	use NeuralForms\Submissions\PostSubmission as P;
	use NeuralForms\Submissions\UserRegistration as U;

	$passed = 0; $failed = 0;
	function check( $label, $cond ) { global $passed, $failed; if ( $cond ) { $passed++; echo "PASS  $label\n"; } else { $failed++; echo "FAIL  $label\n"; } }

	// Meta keys.
	foreach ( array( 'wp_capabilities', 'wp_user_level', '_edit_lock', 'session_tokens', 'first_name', 'my_capabilities', 'Has Space', '', str_repeat( 'a', 65 ) ) as $k ) {
		check( "meta key refused: '$k'", Mapping::safe_meta_key( $k ) === '' );
	}
	check( 'meta key allowed', Mapping::safe_meta_key( 'Company-Size' ) === 'company-size' );
	check( 'meta list cleaned + capped', count( Mapping::sanitize_meta_list( array_merge( array( array( 'key' => 'wp_capabilities', 'field' => 'a' ) ), array_fill( 0, 30, array( 'key' => 'k', 'field' => 'f' ) ) ) ) ) === 20 );

	// Roles.
	$roles = U::safe_roles();
	check( 'safe roles = author + subscriber', array_keys( $roles ) === array( 'author', 'subscriber' ) );
	check( 'administrator not safe', ! U::is_safe_role( 'administrator' ) );
	check( 'role with forms cap not safe', ! U::is_safe_role( 'sneaky' ) );

	// Settings sanitising.
	$u = U::sanitize_settings( array( 'enabled' => 1, 'role' => 'Subscriber', 'email' => 'em"ail', 'meta' => array( array( 'key' => 'wp_user_level', 'field' => 'x' ) ), 'logged_in' => 'weird' ) );
	check( 'user settings: role key, field id, meta dropped, logged_in default', $u['role'] === 'subscriber' && $u['email'] === 'email' && $u['meta'] === array() && $u['logged_in'] === 'hide' && $u['notify_user'] === true && $u['auto_login'] === false );
	check( 'empty user settings dropped', U::sanitize_settings( array( 'enabled' => false ) ) === array() );
	$p = P::sanitize_settings( array( 'enabled' => 1, 'status' => 'private', 'title' => '<b>{t}</b>', 'content' => 'Hi<script>x()</script>', 'terms' => array( array( 'taxonomy' => 'category', 'field' => 'c' ) ), 'author' => '-4' ) );
	check( 'post settings: status → pending, tags stripped from title, script removed', $p['status'] === 'pending' && $p['title'] === '{t}' && $p['content'] === 'Hi' && $p['author'] === 0 && count( $p['terms'] ) === 1 );

	// Activation settings.
	$a = U::sanitize_settings( array( 'enabled' => 1, 'email' => 'em', 'activation' => 'magic', 'activated_url' => 'javascript:alert(1)', 'activation_message' => "<b>Hi</b> {activation_link}" ) );
	check( 'activation: unknown mode → none, js URL dropped, tags stripped', $a['activation'] === 'none' && $a['activated_url'] === '' && $a['activation_message'] === 'Hi {activation_link}' );
	check( 'activation modes', U::sanitize_settings( array( 'enabled' => 1, 'email' => 'em', 'activation' => 'admin' ) )['activation'] === 'admin' );
	check( 'link lives 48 hours', \NeuralForms\Submissions\AccountActivation::TTL === 172800 );

	// Validation.
	$form = array( 'form_schema' => array( 'fields' => array(
		array( 'id' => 'em', 'type' => 'email' ), array( 'id' => 'pw', 'type' => 'password' ), array( 'id' => 'nm', 'type' => 'name' ),
		array( 'id' => 't', 'type' => 'text' ), array( 'id' => 'f', 'type' => 'file' ), array( 'id' => 'c', 'type' => 'select' ),
	) ) );
	$ok_user = array( 'enabled' => true, 'role' => 'subscriber', 'email' => 'em', 'password' => 'pw', 'first_name' => 'nm', 'meta' => array() );
	check( 'valid registration settings', U::validate_settings( array( 'register_user' => $ok_user ), $form ) === true );
	check( 'administrator role → 422', U::validate_settings( array( 'register_user' => array( 'role' => 'administrator' ) + $ok_user ), $form )->get_error_code() === 'invalid_form_data' );
	check( 'email mapped to a text field → 422', is_wp_error( U::validate_settings( array( 'register_user' => array( 'email' => 't' ) + $ok_user ), $form ) ) );
	check( 'password mapped to a text field → 422', is_wp_error( U::validate_settings( array( 'register_user' => array( 'password' => 't' ) + $ok_user ), $form ) ) );
	\NeuralForms\Core\Capabilities::$on = false;
	check( 'locked → 403', U::validate_settings( array( 'register_user' => $ok_user ), $form )->get_error_code() === 'pro_feature_locked' );
	check( 'locked post → 403', P::validate_settings( array( 'create_post' => array( 'enabled' => true ) ), $form )->get_error_code() === 'pro_feature_locked' );
	\NeuralForms\Core\Capabilities::$on = true;
	$ok_post = array( 'enabled' => true, 'post_type' => 'post', 'title' => '{t}', 'author' => 5, 'featured_image' => 'f', 'terms' => array( array( 'taxonomy' => 'category', 'field' => 'c' ) ), 'meta' => array() );
	check( 'valid post settings', P::validate_settings( array( 'create_post' => $ok_post ), $form ) === true );
	check( 'attachment post type refused', is_wp_error( P::validate_settings( array( 'create_post' => array( 'post_type' => 'attachment' ) + $ok_post ), $form ) ) );
	check( 'author without edit_posts refused', is_wp_error( P::validate_settings( array( 'create_post' => array( 'author' => 1 ) + $ok_post ), $form ) ) );
	check( 'featured image from a text field refused', is_wp_error( P::validate_settings( array( 'create_post' => array( 'featured_image' => 't' ) + $ok_post ), $form ) ) );
	check( 'unknown taxonomy refused', is_wp_error( P::validate_settings( array( 'create_post' => array( 'terms' => array( array( 'taxonomy' => 'post_tag', 'field' => 'c' ) ) ) + $ok_post ), $form ) ) );
	check( 'empty title refused', is_wp_error( P::validate_settings( array( 'create_post' => array( 'title' => ' ' ) + $ok_post ), $form ) ) );

	// Escaping: visitor text never becomes HTML.
	$html = P::render_html( '<p><strong>Says:</strong> {t}</p>{missing}', $form, array( 't' => '<img src=x onerror=alert(1)>[gallery]' ), 1 );
	check( 'visitor HTML + shortcodes escaped, template HTML kept, unknown token removed', $html === '<p><strong>Says:</strong> &lt;img src=x onerror=alert(1)&gt;&#91;gallery&#93;</p>' );
	check( 'plain title', P::render_text( '{t} — new', $form, array( 't' => "<b>Hi</b>\n there" ), 1 ) === 'Hi there — new' );

	// Precheck.
	$f2 = $form + array( 'settings' => array( 'register_user' => $ok_user + array( 'username' => 't' ) ) );
	$e  = U::precheck( $f2, array( 'em' => 'taken@example.com', 't' => 'taken' ) );
	check( 'taken email + username → 422 field errors', is_wp_error( $e ) && isset( $e->d['fields']['em'], $e->d['fields']['t'] ) && $e->d['status'] === 422 );
	check( 'bad email → field error', isset( U::precheck( $f2, array( 'em' => 'nope', 't' => 'fine' ) )->d['fields']['em'] ) );
	check( 'free email + username passes', U::precheck( $f2, array( 'em' => 'new@example.com', 't' => 'newbie' ) ) === true );

	echo "\nFrontendSubmissionsTest: $passed passed, $failed failed\n";
	exit( $failed ? 1 : 0 );
}
