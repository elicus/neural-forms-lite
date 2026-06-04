<?php
/**
 * Local template library tests.
 *
 * Run from the plugin root:
 *
 *   php tests/TemplateLibraryLocalTest.php
 *
 * Coverage:
 *   - get_template_roots() returns the bundled block-editor root.
 *   - fetch_catalog('block-editor') reads templates.json from disk,
 *     resolves thumbnail / preview_url to absolute plugin URLs,
 *     keeps import_url as a relative string.
 *   - fetch_catalog returns an empty (well-formed) catalog for a
 *     builder with no registered roots — no WP_Error.
 *   - fetch_catalog rejects an unsupported builder slug with a
 *     WP_Error.
 *   - resolve_local_path (via Reflection) rejects every path-traversal
 *     vector: empty, "..", absolute Unix path, http:// scheme,
 *     non-.json extension.
 *   - resolve_local_path accepts a legitimate relative path inside
 *     the registered root.
 *
 * The roots filter is exercised by registering a synthetic root in
 * /tmp and confirming a templates.json placed there flows through
 * fetch_catalog. Pro will register its `divi` root the same way.
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', '/tmp/abspath' );
defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.Security.EscapeOutput,WordPress.Security.ValidatedSanitizedInput,WordPress.Security.NonceVerification -- CLI-only test runner.

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code; private $message; private $data;
		public function __construct( string $code = '', string $message = '', $data = null ) {
			$this->code = $code; $this->message = $message; $this->data = $data;
		}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data() { return $this->data; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $thing ): bool { return $thing instanceof WP_Error; } }
if ( ! function_exists( '__' ) )           { function __( $s, $domain = null ) { return $s; } }
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		$key = strtolower( (string) $key );
		return preg_replace( '/[^a-z0-9_\-]/', '', $key );
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return trim( preg_replace( '/[\r\n\t]+/', ' ', strip_tags( (string) $str ) ) );
	}
}
if ( ! function_exists( 'esc_url_raw' ) )  { function esc_url_raw( $url ) { return (string) $url; } }
if ( ! function_exists( 'esc_url' ) )      { function esc_url( $url ) { return (string) $url; } }
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value, ...$args ) {
		global $__nf_filters;
		if ( ! empty( $__nf_filters[ $hook ] ) ) {
			foreach ( $__nf_filters[ $hook ] as $cb ) {
				$value = $cb( $value, ...$args );
			}
		}
		return $value;
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $cb, $prio = 10, $args = 1 ) {
		global $__nf_filters;
		$__nf_filters[ $hook ][] = $cb;
	}
}
$GLOBALS['__nf_filters'] = [];

$plugin_root = dirname( __DIR__ );
if ( ! defined( 'NEURAL_FORMS_PATH' ) ) define( 'NEURAL_FORMS_PATH', $plugin_root . '/' );
if ( ! defined( 'NEURAL_FORMS_URL' ) )  define( 'NEURAL_FORMS_URL',  'https://example.test/wp-content/plugins/neural-forms-lite/' );

// FormRepository is namespaced but not required for fetch / resolve paths.
// We don't import a template in these tests; just exercise the lookup path.
if ( ! class_exists( 'NeuralForms\\Forms\\FormRepository' ) ) {
	// stub — never invoked by the tests below, but required so the
	// TemplateLibrary file loads without fataling on the use statement.
	eval( 'namespace NeuralForms\\Forms { class FormRepository { public static function create( $f ) { return 0; } } }' );
}

require_once $plugin_root . '/modules/templates/TemplateLibrary.php';

use NeuralForms\Templates\TemplateLibrary;

$passed = 0;
$failed = 0;
function check( string $label, $actual, $expected, &$passed, &$failed ): void {
	$ok = $actual === $expected;
	if ( $ok ) {
		echo "PASS  $label\n";
		$passed += 1;
	} else {
		echo "FAIL  $label\n";
		echo "       expected: " . var_export( $expected, true ) . "\n";
		echo "       actual:   " . var_export( $actual,   true ) . "\n";
		$failed += 1;
	}
}

// ─── get_template_roots ────────────────────────────────────────────

$roots = TemplateLibrary::get_template_roots();
check( '1a roots include block-editor',
	isset( $roots['block-editor'] ) && is_array( $roots['block-editor'] ),
	true, $passed, $failed );
check( '1b block-editor root has correct shape',
	is_array( $roots['block-editor'][0] ?? null )
		&& isset( $roots['block-editor'][0]['dir'], $roots['block-editor'][0]['url'] ),
	true, $passed, $failed );

// ─── fetch_catalog: bundled block-editor ───────────────────────────

$catalog = TemplateLibrary::fetch_catalog( false, 'block-editor' );
check( '2a fetch_catalog returns array (not WP_Error)',
	is_array( $catalog ), true, $passed, $failed );
check( '2b builder echoes back',
	$catalog['builder'] ?? '', 'block-editor', $passed, $failed );
check( '2c templates is an array',
	is_array( $catalog['templates'] ?? null ), true, $passed, $failed );
check( '2d at least one template loaded',
	count( $catalog['templates'] ) >= 1, true, $passed, $failed );

$contact = $catalog['templates'][0] ?? array();
check( '2e first template id matches',
	$contact['id'] ?? '', 'contact-us-1', $passed, $failed );
check( '2f thumbnail resolved to absolute URL',
	is_string( $contact['thumbnail'] ?? null )
		&& strpos( $contact['thumbnail'], 'https://example.test/' ) === 0,
	true, $passed, $failed );
check( '2g import_url kept as relative string',
	$contact['import_url'] ?? '', 'contact-us/contact-us-1.json', $passed, $failed );

// ─── fetch_catalog: no roots for builder → empty catalog ───────────

$divi = TemplateLibrary::fetch_catalog( false, 'divi' );
check( '3a divi builder (no roots) returns array',
	is_array( $divi ), true, $passed, $failed );
check( '3b divi catalog is empty',
	count( $divi['templates'] ?? [] ), 0, $passed, $failed );

// ─── fetch_catalog: unsupported builder → WP_Error ─────────────────

$bad = TemplateLibrary::fetch_catalog( false, 'wordpress-classic' );
check( '4 unsupported builder returns WP_Error',
	is_wp_error( $bad ), true, $passed, $failed );

// ─── resolve_local_path via Reflection ─────────────────────────────

$method = new ReflectionMethod( TemplateLibrary::class, 'resolve_local_path' );
$method->setAccessible( true );
$root_dir = $roots['block-editor'][0]['dir'];

function r( $method, $root_dir, $rel ) {
	return $method->invoke( null, $root_dir, $rel );
}

check( '5a empty path → WP_Error',
	is_wp_error( r( $method, $root_dir, '' ) ), true, $passed, $failed );
check( '5b ".." segment → WP_Error',
	is_wp_error( r( $method, $root_dir, '../../etc/passwd' ) ), true, $passed, $failed );
check( '5c ".." nested → WP_Error',
	is_wp_error( r( $method, $root_dir, 'contact-us/../../../etc/passwd' ) ), true, $passed, $failed );
check( '5d http scheme → WP_Error',
	is_wp_error( r( $method, $root_dir, 'http://evil.test/x.json' ) ), true, $passed, $failed );
check( '5e absolute Unix path → WP_Error',
	is_wp_error( r( $method, $root_dir, '/etc/passwd.json' ) ), true, $passed, $failed );
check( '5f non-.json extension → WP_Error',
	is_wp_error( r( $method, $root_dir, 'contact-us/x.txt' ) ), true, $passed, $failed );
check( '5g missing file → WP_Error',
	is_wp_error( r( $method, $root_dir, 'contact-us/nonexistent.json' ) ), true, $passed, $failed );

$ok = r( $method, $root_dir, 'contact-us/contact-us-1.json' );
check( '5h legitimate path resolves to absolute file path',
	is_string( $ok ) && strpos( $ok, $root_dir ) === 0 && substr( $ok, -5 ) === '.json',
	true, $passed, $failed );

// ─── Roots filter: Pro-style registration of a synthetic builder ───

$tmpdir = sys_get_temp_dir() . '/nf_tpl_test_' . uniqid();
mkdir( $tmpdir . '/synth-builder', 0777, true );
file_put_contents(
	$tmpdir . '/synth-builder/templates.json',
	json_encode( array(
		'version'    => '0.0.1',
		'builder'    => 'synth-builder',
		'updated_at' => '2026-06-04',
		'templates'  => array(
			array(
				'id'            => 'synth-1',
				'name'          => 'Synth One',
				'description'   => 'From the test root.',
				'category'      => 'Misc',
				'thumbnail'     => 'screenshots/synth.jpg',
				'preview_url'   => 'screenshots/synth.jpg',
				'import_url'    => 'synth-1.json',
				'compatibility' => array( 'synth-builder' ),
			),
		),
	) )
);
add_filter( 'neural_forms_template_library_roots', function ( $roots ) use ( $tmpdir ) {
	$roots['synth-builder'][] = array(
		'dir' => $tmpdir . '/synth-builder/',
		'url' => 'https://example.test/synth/',
	);
	return $roots;
} );

// synth-builder isn't in SUPPORTED_BUILDERS, so it's rejected. Adjust
// expectation: this confirms the supported-builder gate fires before
// the roots lookup — a Pro plugin registering 'divi' uses one of
// the supported slugs.
$synth = TemplateLibrary::fetch_catalog( false, 'synth-builder' );
check( '6a unsupported-but-registered builder still 400s',
	is_wp_error( $synth ), true, $passed, $failed );

// Same root but registered under the supported 'divi' slug — should load.
add_filter( 'neural_forms_template_library_roots', function ( $roots ) use ( $tmpdir ) {
	$roots['divi'][] = array(
		'dir' => $tmpdir . '/synth-builder/',
		'url' => 'https://example.test/synth/',
	);
	return $roots;
} );
// Roots are now: block-editor (default) + divi (test). The synth-builder
// row inside templates.json declares compatibility=['synth-builder'],
// which doesn't include 'divi', so the compatibility filter drops it.
// Patch the catalog row to include 'divi' for this test case.
file_put_contents(
	$tmpdir . '/synth-builder/templates.json',
	json_encode( array(
		'version'    => '0.0.1',
		'builder'    => 'divi',
		'updated_at' => '2026-06-04',
		'templates'  => array(
			array(
				'id'            => 'synth-1',
				'name'          => 'Synth One',
				'description'   => 'From the test root.',
				'thumbnail'     => 'screenshots/synth.jpg',
				'preview_url'   => 'screenshots/synth.jpg',
				'import_url'    => 'synth-1.json',
				'compatibility' => array( 'divi' ),
			),
		),
	) )
);
$divi2 = TemplateLibrary::fetch_catalog( false, 'divi' );
check( '6b filter-registered divi root loads via supported slug',
	is_array( $divi2 ) && count( $divi2['templates'] ) === 1,
	true, $passed, $failed );
check( '6c synthetic template id flows through',
	$divi2['templates'][0]['id'] ?? '', 'synth-1', $passed, $failed );
check( '6d thumbnail resolves against the registered root URL',
	strpos( $divi2['templates'][0]['thumbnail'] ?? '', 'https://example.test/synth/' ) === 0,
	true, $passed, $failed );

// Cleanup
@unlink( $tmpdir . '/synth-builder/templates.json' );
@rmdir( $tmpdir . '/synth-builder' );
@rmdir( $tmpdir );

echo "\n$passed passed, $failed failed\n";
exit( $failed > 0 ? 1 : 0 );
