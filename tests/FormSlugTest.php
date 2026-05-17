<?php
/**
 * Form-slug uniqueness tests.
 *
 * Targets:
 *   - FormRepository::generate_unique_slug() — case-insensitive
 *     conflict check, exclude_id semantics, empty-input fallback,
 *     suffix progression.
 *   - FormRepository::repair_duplicate_slugs() — keeps lowest id,
 *     renames later duplicates with -2/-3/... suffix that doesn't
 *     collide with existing slugs.
 *   - FormRepository::find_by_slug() — deterministic ordering when
 *     duplicates remain (active first, lowest id second).
 *
 * Uses a tiny in-memory $wpdb shim so the helper SQL exercises the
 * real code path without needing a live MySQL.
 *
 * Run from the plugin root:
 *
 *   php tests/FormSlugTest.php
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', '/tmp/abspath' );
defined( 'ABSPATH' ) || exit; // Direct-access guard (no-op once the line above defines ABSPATH).
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
if ( ! function_exists( 'is_wp_error' ) )    { function is_wp_error( $thing ): bool { return $thing instanceof WP_Error; } }
if ( ! function_exists( 'apply_filters' ) )  { function apply_filters( $tag, $value, ...$args ) { return $value; } }
if ( ! function_exists( '__' ) )             { function __( $s, $domain = null ) { return $s; } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $v, $opts = 0, $d = 512 ) { return json_encode( $v, $opts, $d ); } }
if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $title ) {
		$t = strtolower( trim( (string) $title ) );
		$t = preg_replace( '/[^a-z0-9-]+/', '-', $t );
		$t = preg_replace( '/-+/', '-', $t );
		return trim( $t, '-' );
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $s ) { return is_string( $s ) ? trim( $s ) : ''; } }
if ( ! function_exists( 'sanitize_textarea_field' ) ) { function sanitize_textarea_field( $s ) { return is_string( $s ) ? $s : ''; } }
if ( ! function_exists( 'current_time' ) ) { function current_time( $type, $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); } }
if ( ! function_exists( 'get_current_user_id' ) ) { function get_current_user_id() { return 1; } }
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
if ( ! defined( 'OBJECT'  ) ) { define( 'OBJECT',  'OBJECT'  ); }

if ( ! class_exists( 'NeuralForms\\Core\\Logger' ) ) {
	eval( 'namespace NeuralForms\\Core; class Logger { public static function file( ...$args ) {} }' );
}

/**
 * Tiny in-memory $wpdb shim. Tracks rows for the forms table only;
 * understands the exact SQL shapes our helpers issue:
 *
 *   SELECT id FROM <forms> WHERE LOWER(slug) = LOWER(%s) [AND id != %d] LIMIT 1
 *   SELECT * FROM <forms> WHERE slug = %s ORDER BY (status='active') DESC, id ASC LIMIT 1
 *   SELECT slug, GROUP_CONCAT(id ORDER BY id ASC) AS ids FROM <forms> GROUP BY slug HAVING COUNT(*) > 1
 *   $wpdb->update / $wpdb->insert against the forms table
 */
class NF_Slug_WPDB {
	public string $prefix = 'wp_';
	public array  $rows   = [];
	public int    $next   = 1;
	public string $last_error = '';
	public int    $insert_id = 0;

	public function prepare( $sql, ...$args ) {
		// Rendered to placeholders so it's still valid SQL-ish, but
		// we only inspect the structure in get_var/get_row/etc.
		return [ 'sql' => $sql, 'args' => $args ];
	}

	public function get_var( $query ) {
		$sql  = strtolower( $query['sql'] );
		$args = $query['args'];
		// Pattern: SELECT id FROM %i WHERE LOWER(slug) = LOWER(%s) [AND id != %d] LIMIT 1
		if ( strpos( $sql, 'select id from' ) !== false && strpos( $sql, 'lower(slug)' ) !== false ) {
			$candidate = strtolower( (string) $args[1] );
			$exclude   = isset( $args[2] ) ? (int) $args[2] : 0;
			foreach ( $this->rows as $r ) {
				if ( strtolower( $r['slug'] ) !== $candidate ) continue;
				if ( $exclude && (int) $r['id'] === $exclude ) continue;
				return (string) $r['id'];
			}
			return null;
		}
		return null;
	}

	public function get_row( $query, $output = OBJECT ) {
		$sql  = strtolower( $query['sql'] );
		$args = $query['args'];
		// Pattern: SELECT * FROM %i WHERE slug = %s ORDER BY ... LIMIT 1
		if ( strpos( $sql, "select * from" ) !== false && strpos( $sql, 'where slug' ) !== false ) {
			$slug = (string) $args[1];
			$matches = array_values( array_filter( $this->rows, static fn( $r ) => $r['slug'] === $slug ) );
			if ( empty( $matches ) ) return null;
			usort( $matches, static function ( $a, $b ) {
				$aActive = ( $a['status'] === 'active' ) ? 1 : 0;
				$bActive = ( $b['status'] === 'active' ) ? 1 : 0;
				if ( $aActive !== $bActive ) return $bActive - $aActive;
				return $a['id'] - $b['id'];
			} );
			return $matches[ 0 ];
		}
		return null;
	}

	public function get_results( $query, $output = OBJECT ) {
		$sql = strtolower( $query['sql'] );
		// Pattern: GROUP BY slug HAVING COUNT(*) > 1
		if ( strpos( $sql, 'group by slug' ) !== false && strpos( $sql, 'having count' ) !== false ) {
			$bucket = [];
			foreach ( $this->rows as $r ) {
				$bucket[ $r['slug'] ][] = (int) $r['id'];
			}
			$out = [];
			foreach ( $bucket as $slug => $ids ) {
				if ( count( $ids ) <= 1 ) continue;
				sort( $ids );
				$out[] = [ 'slug' => $slug, 'ids' => implode( ',', $ids ) ];
			}
			return $out;
		}
		return [];
	}

	public function update( $table, $data, $where, $data_format = null, $where_format = null ) {
		$id = (int) ( $where['id'] ?? 0 );
		foreach ( $this->rows as &$r ) {
			if ( (int) $r['id'] === $id ) {
				foreach ( $data as $k => $v ) {
					$r[ $k ] = $v;
				}
				return 1;
			}
		}
		unset( $r );
		return 0;
	}

	public function insert( $table, $data, $formats = null ) {
		$id = $this->next++;
		$row = array_merge( [ 'id' => $id, 'status' => 'draft' ], $data );
		$this->rows[] = $row;
		$this->insert_id = $id;
		return 1;
	}

	public function query( $sql ) { return true; }
}
$GLOBALS['wpdb'] = new NF_Slug_WPDB();

$plugin_root = dirname( __DIR__ );
require_once $plugin_root . '/modules/forms/FormRepository.php';

use NeuralForms\Forms\FormRepository;

$cases = [];
$passed = 0; $failed = 0;
function check( string $label, $actual, $expected, array &$cases, int &$passed, int &$failed ): void {
	$ok = $actual === $expected;
	$cases[] = [ $ok, $label, $actual, $expected ];
	if ( $ok ) { $passed++; } else { $failed++; }
}

$wpdb = $GLOBALS['wpdb'];

// ---------------------------------------------------------------------------
// 1. Empty input → "form".
// ---------------------------------------------------------------------------
$wpdb->rows = [];
check( '1a empty base → "form"',     FormRepository::generate_unique_slug( '' ),     'form', $cases, $passed, $failed );
check( '1b whitespace base → "form"', FormRepository::generate_unique_slug( '   ' ),  'form', $cases, $passed, $failed );
check( '1c special-only base → "form"', FormRepository::generate_unique_slug( '!!!' ), 'form', $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 2. First slug is taken as-is.
// ---------------------------------------------------------------------------
check( '2 first "Contact Us" → contact-us', FormRepository::generate_unique_slug( 'Contact Us' ), 'contact-us', $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 3. Suffix progression on conflict.
// ---------------------------------------------------------------------------
$wpdb->rows = [
	[ 'id' => 1, 'slug' => 'contact-us', 'status' => 'active' ],
];
check( '3a second "Contact Us" → contact-us-2', FormRepository::generate_unique_slug( 'Contact Us' ), 'contact-us-2', $cases, $passed, $failed );

$wpdb->rows[] = [ 'id' => 2, 'slug' => 'contact-us-2', 'status' => 'draft' ];
check( '3b third "Contact Us" → contact-us-3',  FormRepository::generate_unique_slug( 'Contact Us' ), 'contact-us-3', $cases, $passed, $failed );

$wpdb->rows[] = [ 'id' => 3, 'slug' => 'contact-us-3', 'status' => 'draft' ];
check( '3c fourth "Contact Us" → contact-us-4', FormRepository::generate_unique_slug( 'Contact Us' ), 'contact-us-4', $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 4. exclude_id keeps the form's own slug stable on update.
// ---------------------------------------------------------------------------
$wpdb->rows = [
	[ 'id' => 5, 'slug' => 'feedback', 'status' => 'active' ],
];
// Updating form id 5 with the same slug → returns 'feedback' (no churn).
check( '4a same slug + own id → unchanged', FormRepository::generate_unique_slug( 'feedback', 5 ), 'feedback', $cases, $passed, $failed );

// Form id 5 wants slug 'support', and form id 6 already has 'support'.
$wpdb->rows[] = [ 'id' => 6, 'slug' => 'support', 'status' => 'active' ];
check( '4b conflict with another form → -2', FormRepository::generate_unique_slug( 'support', 5 ), 'support-2', $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 5. Case-insensitive comparison: "Contact" can't sneak past "contact".
// ---------------------------------------------------------------------------
$wpdb->rows = [
	[ 'id' => 1, 'slug' => 'contact', 'status' => 'active' ],
];
// Caller submits "Contact" — sanitize_title lowercases to "contact"; should auto-suffix.
check( '5 case-insensitive conflict → contact-2', FormRepository::generate_unique_slug( 'Contact' ), 'contact-2', $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 6. Repair existing duplicates: lowest id keeps slug; later renamed.
// ---------------------------------------------------------------------------
$wpdb->rows = [
	[ 'id' => 1, 'slug' => 'contact-us', 'status' => 'active' ],
	[ 'id' => 2, 'slug' => 'contact-us', 'status' => 'draft'  ], // duplicate
	[ 'id' => 3, 'slug' => 'contact-us', 'status' => 'draft'  ], // duplicate
	[ 'id' => 4, 'slug' => 'feedback',   'status' => 'active' ],
	[ 'id' => 5, 'slug' => 'feedback',   'status' => 'active' ], // duplicate
];
$renamed = FormRepository::repair_duplicate_slugs();
check( '6a renamed count = 3', $renamed, 3, $cases, $passed, $failed );

// Re-collect slugs by id.
$by_id = [];
foreach ( $wpdb->rows as $r ) { $by_id[ $r['id'] ] = $r['slug']; }
check( '6b id 1 keeps contact-us',       $by_id[ 1 ], 'contact-us',   $cases, $passed, $failed );
check( '6c id 2 → contact-us-2',         $by_id[ 2 ], 'contact-us-2', $cases, $passed, $failed );
check( '6d id 3 → contact-us-3',         $by_id[ 3 ], 'contact-us-3', $cases, $passed, $failed );
check( '6e id 4 keeps feedback',         $by_id[ 4 ], 'feedback',     $cases, $passed, $failed );
check( '6f id 5 → feedback-2',           $by_id[ 5 ], 'feedback-2',   $cases, $passed, $failed );

// 6g. Re-running on the now-clean table is a no-op.
check( '6g idempotent rerun returns 0', FormRepository::repair_duplicate_slugs(), 0, $cases, $passed, $failed );

// 6h. Repair handles existing -2 collisions: starts from -3.
$wpdb->rows = [
	[ 'id' => 1, 'slug' => 'about',   'status' => 'active' ],
	[ 'id' => 2, 'slug' => 'about',   'status' => 'draft'  ], // duplicate
	[ 'id' => 3, 'slug' => 'about-2', 'status' => 'draft'  ], // already taken
];
FormRepository::repair_duplicate_slugs();
$by_id = [];
foreach ( $wpdb->rows as $r ) { $by_id[ $r['id'] ] = $r['slug']; }
check( '6h dup avoids existing about-2 → about-3', $by_id[ 2 ], 'about-3', $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 7. find_by_slug deterministic ordering when duplicates remain.
// ---------------------------------------------------------------------------
$wpdb->rows = [
	[ 'id' => 7, 'slug' => 'orphan', 'status' => 'draft',  'name' => 'B', 'description' => null, 'form_schema' => '{}', 'settings' => '{}' ],
	[ 'id' => 9, 'slug' => 'orphan', 'status' => 'active', 'name' => 'C', 'description' => null, 'form_schema' => '{}', 'settings' => '{}' ],
	[ 'id' => 5, 'slug' => 'orphan', 'status' => 'draft',  'name' => 'A', 'description' => null, 'form_schema' => '{}', 'settings' => '{}' ],
];
$found = FormRepository::find_by_slug( 'orphan' );
check( '7a active form wins over drafts',  $found['id'] ?? null, 9, $cases, $passed, $failed );

// All same status → lowest id wins.
$wpdb->rows = [
	[ 'id' => 7, 'slug' => 'orphan', 'status' => 'draft', 'name' => 'B', 'description' => null, 'form_schema' => '{}', 'settings' => '{}' ],
	[ 'id' => 5, 'slug' => 'orphan', 'status' => 'draft', 'name' => 'A', 'description' => null, 'form_schema' => '{}', 'settings' => '{}' ],
];
$found = FormRepository::find_by_slug( 'orphan' );
check( '7b lowest id wins among drafts',   $found['id'] ?? null, 5, $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 8. create() inserts a unique slug end-to-end.
// ---------------------------------------------------------------------------
$wpdb->rows = [];
$wpdb->next = 1;
FormRepository::create( [ 'name' => 'Test Form', 'form_schema' => [ 'fields' => [ [ 'id' => 'a', 'type' => 'text' ] ] ] ] );
FormRepository::create( [ 'name' => 'Test Form', 'form_schema' => [ 'fields' => [ [ 'id' => 'a', 'type' => 'text' ] ] ] ] );
FormRepository::create( [ 'name' => 'Test Form', 'form_schema' => [ 'fields' => [ [ 'id' => 'a', 'type' => 'text' ] ] ] ] );
$slugs = array_column( $wpdb->rows, 'slug' );
check( '8 three "Test Form" creates → unique slugs',
	$slugs, [ 'test-form', 'test-form-2', 'test-form-3' ], $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// 9. update() preserves own slug; conflicts auto-suffix.
// ---------------------------------------------------------------------------
$wpdb->rows = [
	[ 'id' => 1, 'slug' => 'a', 'status' => 'active', 'name' => 'A', 'description' => null, 'form_schema' => '{}', 'settings' => '{}' ],
	[ 'id' => 2, 'slug' => 'b', 'status' => 'active', 'name' => 'B', 'description' => null, 'form_schema' => '{}', 'settings' => '{}' ],
];
FormRepository::update( 2, [ 'slug' => 'b' ] );
check( '9a self-update keeps slug',  $wpdb->rows[1]['slug'], 'b',   $cases, $passed, $failed );

FormRepository::update( 2, [ 'slug' => 'a' ] );
check( '9b conflict auto-suffixed',  $wpdb->rows[1]['slug'], 'a-2', $cases, $passed, $failed );

// ---------------------------------------------------------------------------
// Summary.
// ---------------------------------------------------------------------------
foreach ( $cases as $row ) {
	[ $ok, $label, $actual, $expected ] = $row;
	if ( $ok ) {
		echo "\033[32mPASS\033[0m  $label\n";
	} else {
		echo "\033[31mFAIL\033[0m  $label\n";
		echo "      expected: " . var_export( $expected, true ) . "\n";
		echo "      actual:   " . var_export( $actual, true ) . "\n";
	}
}
echo "\n$passed passed, $failed failed\n";
exit( $failed === 0 ? 0 : 1 );
