<?php
/**
 * Entries search-clause tests (inbox search + CSV export search).
 *
 * Run from the plugin root:
 *
 *   php tests/EntrySearchClauseTest.php
 *
 * Exercises EntryRepository::build_filter_clauses() in isolation via
 * source extraction (same pattern as PollQuizSubmissionLimitTest).
 * Entries saved before 1.1.3 hold JSON-escaped data (\uXXXX, \/);
 * entries saved since are unescaped. The clause must match both.
 *
 * Coverage:
 *   1. ASCII term → one data LIKE + one name LIKE, values aligned
 *   2. non-ASCII term → raw + \uXXXX alternatives
 *   3. slash term (URL) → raw + \/ alternative
 *   4. plain and aliased variants carry the same placeholder count
 *   5. esc_like is applied to the escaped alternative (backslashes doubled)
 *   6. empty search adds nothing
 */

declare( strict_types = 1 );

namespace {
	if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', '/tmp/abspath' );
	defined( 'ABSPATH' ) || exit;
	// phpcs:disable WordPress.Security.EscapeOutput,WordPress.Security.ValidatedSanitizedInput,WordPress.Security.NonceVerification -- CLI-only test runner.

	if ( ! function_exists( 'wp_json_encode' ) ) {
		function wp_json_encode( $data, $flags = 0, $depth = 512 ) { return json_encode( $data, $flags, $depth ); }
	}

	class WPDB_Stub {
		public $prefix = 'wp_';
		// Mirrors wpdb::esc_like(): escape \ % _ for use inside LIKE.
		public function esc_like( $text ) { return addcslashes( (string) $text, '_%\\' ); }
	}
	$GLOBALS['wpdb'] = new WPDB_Stub();

	$plugin_root = dirname( __DIR__ );
	$src = file_get_contents( $plugin_root . '/modules/storage/EntryRepository.php' );
	if ( ! preg_match( '/private static function build_filter_clauses\(.+?\n\t\}/s', $src, $m ) ) {
		fwrite( STDERR, "Could not extract build_filter_clauses() source.\n" );
		exit( 2 );
	}
	if ( ! preg_match( '/const VALID_VIEWS\s*=\s*array\(.*?\);/s', $src, $v ) ) {
		fwrite( STDERR, "Could not extract VALID_VIEWS.\n" );
		exit( 2 );
	}
	$body = preg_replace( '/^\s*private /', 'public ', $m[0] );

	eval( '
		namespace NeuralForms\\Tests {
			class EntryRepositoryClauses {
				' . $v[0] . '
				' . $body . '
			}
		}
	' );

	$passed = 0;
	$failed = 0;

	function check( string $label, $actual, $expected, int &$passed, int &$failed ): void {
		$ok = $actual === $expected;
		if ( $ok ) $passed++; else $failed++;
		$indicator = $ok ? "\033[32mPASS\033[0m" : "\033[31mFAIL\033[0m";
		echo $indicator . '  ' . $label . "\n";
		if ( ! $ok ) {
			echo "      expected: " . var_export( $expected, true ) . "\n";
			echo "      actual:   " . var_export( $actual, true ) . "\n";
		}
	}

	$build = fn( string $term ) => \NeuralForms\Tests\EntryRepositoryClauses::build_filter_clauses( 0, [ 'search' => $term, 'view' => 'inbox' ] );

	// Strip the view-related parts so we only look at what search added.
	$search_values = function ( array $r ): array {
		// view=inbox adds no %s values; status defaults to 'any'. Values are search-only.
		return $r['values'];
	};

	// ─── 1. ASCII ───────────────────────────────────────────────
	$r = $build( 'hello' );
	check( '1. ASCII term → values [data, name]', $search_values( $r ), [ '%hello%', '%hello%' ], $passed, $failed );
	check( '1b. plain SQL has single data LIKE',   substr_count( $r['plain'], 'data LIKE %s' ), 1, $passed, $failed );
	check( '1c. aliased SQL has single e.data LIKE', substr_count( $r['aliased'], 'e.data LIKE %s' ), 1, $passed, $failed );

	// ─── 2. non-ASCII ───────────────────────────────────────────
	$r = $build( 'José' );
	check( '2. non-ASCII → raw + \\uXXXX alternative + name',
		$search_values( $r ),
		[ '%José%', '%Jos\\\\u00e9%', '%José%' ],
		$passed, $failed );
	check( '2b. plain SQL has two data LIKEs',      substr_count( $r['plain'], 'data LIKE %s' ), 2, $passed, $failed );
	check( '2c. aliased SQL has two e.data LIKEs',  substr_count( $r['aliased'], 'e.data LIKE %s' ), 2, $passed, $failed );
	check( '2d. form-name LIKE still present once (plain)', substr_count( $r['plain'], 'name LIKE %s' ), 1, $passed, $failed );
	check( '2e. form-name LIKE still present once (aliased)', substr_count( $r['aliased'], 'f.name LIKE %s' ), 1, $passed, $failed );

	// ─── 3. URL / slash ─────────────────────────────────────────
	$r = $build( 'https://example.com/x' );
	check( '3. URL → raw + \\/ alternative + name',
		$search_values( $r ),
		[ '%https://example.com/x%', '%https:\\\\/\\\\/example.com\\\\/x%', '%https://example.com/x%' ],
		$passed, $failed );

	// ─── 4. placeholder alignment ───────────────────────────────
	foreach ( [ 'plain', 'José', 'a/b', 'ñ/ü' ] as $t ) {
		$r = $build( $t );
		check( "4. placeholders aligned for '{$t}'",
			[ substr_count( $r['plain'], '%s' ), substr_count( $r['aliased'], '%s' ) ],
			[ count( $r['values'] ), count( $r['values'] ) ],
			$passed, $failed );
	}

	// ─── 5. esc_like on the escaped alternative ─────────────────
	$r = $build( 'é' );
	check( '5. escaped alternative has doubled backslash for LIKE', $r['values'][1], '%\\\\u00e9%', $passed, $failed );

	// ─── 6. empty search ────────────────────────────────────────
	$r = $build( '' );
	check( '6. empty search adds no values', $r['values'], [], $passed, $failed );
	check( '6b. empty search adds no LIKE', strpos( $r['plain'], 'LIKE' ), false, $passed, $failed );

	echo "\n{$passed} passed, {$failed} failed\n";
	exit( $failed === 0 ? 0 : 1 );
}
