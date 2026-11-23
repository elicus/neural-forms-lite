<?php
/**
 * Privacy mode + WordPress privacy tools.
 *
 * Run from the plugin root:
 *
 *   php tests/PrivacyModeTest.php
 *
 * Coverage:
 *   1. SubmitEndpoint reads privacy_mode and blanks ip / user_agent in the
 *      stored context + the CAPTCHA log (source-level guards)
 *   2. EntryRepository::data_contains_email(): nested, case-insensitive,
 *      exact (no substring) match
 *   3. PrivacyTools::export(): item shape, field labels, visitor metadata
 *      only when present, pagination 'done'
 *   4. PrivacyTools::erase(): cascade (messages + AI drafts) then entry,
 *      counts, 'done'
 *   5. registration filters add our callbacks
 *   6. Database::scrub_visitor_metadata() issues the blanking UPDATE
 *   7. DB version bumped to 1.1.3 so the upgrade scrub runs
 */

declare( strict_types = 1 );

namespace {
	if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', '/tmp/abspath' );
	defined( 'ABSPATH' ) || exit;
	// phpcs:disable WordPress.Security.EscapeOutput,WordPress.Security.ValidatedSanitizedInput,WordPress.Security.NonceVerification -- CLI-only test runner.
	if ( ! defined( 'ARRAY_A' ) ) define( 'ARRAY_A', 'ARRAY_A' );

	function __( $s, $d = null ) { return $s; }
	function esc_html__( $s, $d = null ) { return $s; }
	function wp_json_encode( $d, $f = 0, $depth = 512 ) { return json_encode( $d, $f, $depth ); }
	function wp_kses_post( $s ) { return $s; }
	$GLOBALS['__nf_hooks'] = [];
	function add_filter( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS['__nf_hooks'][ $h ][] = $cb; }
	function add_action( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS['__nf_hooks'][ $h ][] = $cb; }
	$GLOBALS['__nf_policy'] = null;
	function wp_add_privacy_policy_content( $name, $content ) { $GLOBALS['__nf_policy'] = [ $name, $content ]; }

	$plugin_root = dirname( __DIR__ );

	// ── Repository stubs ──
	$GLOBALS['__nf_entries'] = [];
	$GLOBALS['__nf_deleted'] = [ 'entries' => [], 'messages' => [], 'ai' => [] ];
	eval( '
	namespace NeuralForms\\Storage {
		class EntryRepository {
			public static function find_by_email( string $email, int $per_page = 50, int $page = 1 ): array {
				$all = array_values( array_filter( $GLOBALS["__nf_entries"], fn( $e ) => self::data_contains_email( $e["data"], $email ) ) );
				return array_slice( $all, ( $page - 1 ) * $per_page, $per_page );
			}
			public static function data_contains_email( array $data, string $email ): bool {
				foreach ( $data as $v ) { if ( is_string( $v ) && strtolower( trim( $v ) ) === strtolower( $email ) ) return true; }
				return false;
			}
			public static function delete( int $id ): bool {
				$GLOBALS["__nf_deleted"]["entries"][] = $id;
				$GLOBALS["__nf_entries"] = array_values( array_filter( $GLOBALS["__nf_entries"], fn( $e ) => (int) $e["id"] !== $id ) );
				return true;
			}
		}
		class EntryMessageRepository { public static function delete_by_entry( int $id ): int { $GLOBALS["__nf_deleted"]["messages"][] = $id; return 1; } }
		class AIResponseRepository   { public static function delete_by_entry( int $id ): int { $GLOBALS["__nf_deleted"]["ai"][] = $id; return 1; } }
	}
	namespace NeuralForms\\Forms {
		class FormRepository {
			public static function find( int $id ): ?array {
				return [ "id" => $id, "name" => "Contact #" . $id, "form_schema" => [ "fields" => [
					[ "id" => "name",  "type" => "text",  "label" => "Your name" ],
					[ "id" => "email", "type" => "email", "label" => "Email" ],
					[ "id" => "cv",    "type" => "file",  "label" => "" ],
				] ] ];
			}
		}
		class SchemaHelper { public static function flatten_fields( array $f ): array { return $f; } }
	}
	' );

	require_once $plugin_root . '/modules/privacy/PrivacyTools.php';
	use NeuralForms\Privacy\PrivacyTools;

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

	// ── 1. SubmitEndpoint source guards ──
	$submit = file_get_contents( $plugin_root . '/modules/rest/Endpoints/SubmitEndpoint.php' );
	check( '1a. submit() reads privacy_mode from Config',           strpos( $submit, "\$privacy_mode = (bool) Config::get( 'privacy_mode', false );" ) !== false, true, $passed, $failed );
	check( '1b. stored ip blanked under privacy mode',              strpos( $submit, "'ip'           => \$privacy_mode ? '' : \$ip," ) !== false, true, $passed, $failed );
	check( '1c. stored user_agent blanked under privacy mode',      (bool) preg_match( "/'user_agent'\\s+=> \\\$privacy_mode \\? '' : sanitize_text_field/", $submit ), true, $passed, $failed );
	check( '1d. CAPTCHA log ip redacted under privacy mode',        strpos( $submit, "'ip'         => \$privacy_mode ? '' : \$ip," ) !== false, true, $passed, $failed );
	check( '1e. privacy_mode read AFTER resolve_ip (ip still used in memory)', strpos( $submit, '$ip = $this->resolve_ip();' ) < strpos( $submit, "Config::get( 'privacy_mode'" ), true, $passed, $failed );

	// ── 2. data_contains_email() (real implementation, extracted) ──
	$repo_src = file_get_contents( $plugin_root . '/modules/storage/EntryRepository.php' );
	preg_match( '/public static function data_contains_email\(.+?\n\t\}/s', $repo_src, $m ) || exit( 2 );
	eval( 'namespace NeuralForms\\Tests { class Matcher { ' . $m[0] . ' } }' );
	$has = fn( array $d, string $e ) => \NeuralForms\Tests\Matcher::data_contains_email( $d, $e );
	check( '2a. exact match',                   $has( [ 'email' => 'Ann@X.co' ], 'ann@x.co' ), true,  $passed, $failed );
	check( '2b. surrounding whitespace ignored', $has( [ 'email' => '  ann@x.co ' ], 'ann@x.co' ), true, $passed, $failed );
	check( '2c. substring is NOT a match',      $has( [ 'email' => 'joann@x.com' ], 'ann@x.co' ), false, $passed, $failed );
	check( '2d. nested (repeater) values found', $has( [ 'rep' => [ [ 'contact' => 'ann@x.co' ] ] ], 'ann@x.co' ), true, $passed, $failed );
	check( '2e. empty needle never matches',    $has( [ 'email' => '' ], '' ), false, $passed, $failed );
	check( '2f. non-string scalars compared safely', $has( [ 'n' => 5, 'b' => true ], 'ann@x.co' ), false, $passed, $failed );

	// ── 3. export() ──
	$GLOBALS['__nf_entries'] = [
		[ 'id' => 10, 'form_id' => 1, 'submitted_at' => '2026-10-27 10:00:00', 'data' => [ 'name' => 'Ann', 'email' => 'ann@x.co', 'cv' => [ 'url' => 'https://s.test/cv.pdf', 'filename' => 'cv.pdf' ] ], 'ip_address' => '203.0.113.9', 'user_agent' => 'UA/1', 'page_url' => '' ],
		[ 'id' => 11, 'form_id' => 1, 'submitted_at' => '2026-10-27 11:00:00', 'data' => [ 'name' => 'Bob', 'email' => 'bob@x.co' ],                                                        'ip_address' => null,          'user_agent' => null, 'page_url' => null ],
		[ 'id' => 12, 'form_id' => 2, 'submitted_at' => '2026-10-27 12:00:00', 'data' => [ 'name' => 'Ann again', 'email' => 'ANN@x.co' ],                                                   'ip_address' => null,          'user_agent' => null, 'page_url' => null ],
	];
	$r = PrivacyTools::export( 'ann@x.co', 1 );
	check( '3a. two of three entries match',        count( $r['data'] ), 2, $passed, $failed );
	check( '3b. done on a short page',              $r['done'], true, $passed, $failed );
	check( '3c. group + item ids',                  [ $r['data'][0]['group_id'], $r['data'][0]['item_id'] ], [ 'neural-forms-entries', 'neural-forms-entry-10' ], $passed, $failed );
	$names = array_column( $r['data'][0]['data'], 'value', 'name' );
	check( '3d. form name + submitted at',          [ $names['Form'], $names['Submitted at'] ], [ 'Contact #1', '2026-10-27 10:00:00' ], $passed, $failed );
	check( '3e. field labels resolved from schema', [ $names['Your name'], $names['Email'] ], [ 'Ann', 'ann@x.co' ], $passed, $failed );
	check( '3f. unlabeled field falls back to id; file shows url', $names['cv'], 'https://s.test/cv.pdf', $passed, $failed );
	check( '3g. visitor metadata included when present', [ $names['IP address'], $names['Browser (user agent)'] ], [ '203.0.113.9', 'UA/1' ], $passed, $failed );
	check( '3h. empty page_url omitted',            isset( $names['Page URL'] ), false, $passed, $failed );
	$n2 = array_column( $r['data'][1]['data'], 'value', 'name' );
	check( '3i. entry without metadata has no IP/UA rows', isset( $n2['IP address'] ) || isset( $n2['Browser (user agent)'] ), false, $passed, $failed );
	check( '3j. no match → empty + done',           PrivacyTools::export( 'nobody@x.co', 1 ), [ 'data' => [], 'done' => true ], $passed, $failed );
	// pagination: 50 matching rows → page 1 not done, page 2 done
	$GLOBALS['__nf_entries'] = array_map( fn( $i ) => [ 'id' => $i, 'form_id' => 1, 'submitted_at' => '', 'data' => [ 'email' => 'many@x.co' ] ], range( 1, 50 ) );
	check( '3k. full page → done=false',            PrivacyTools::export( 'many@x.co', 1 )['done'], false, $passed, $failed );
	check( '3l. next (empty) page → done=true',     PrivacyTools::export( 'many@x.co', 2 )['done'], true,  $passed, $failed );

	// ── 4. erase() ──
	$GLOBALS['__nf_entries'] = [
		[ 'id' => 10, 'form_id' => 1, 'data' => [ 'email' => 'ann@x.co' ] ],
		[ 'id' => 11, 'form_id' => 1, 'data' => [ 'email' => 'bob@x.co' ] ],
		[ 'id' => 12, 'form_id' => 2, 'data' => [ 'email' => 'Ann@X.co' ] ],
	];
	$GLOBALS['__nf_deleted'] = [ 'entries' => [], 'messages' => [], 'ai' => [] ];
	$e = PrivacyTools::erase( 'ann@x.co', 1 );
	check( '4a. removed count',                     $e['items_removed'], 2, $passed, $failed );
	check( '4b. entries deleted',                   $GLOBALS['__nf_deleted']['entries'], [ 10, 12 ], $passed, $failed );
	check( '4c. messages cascade',                  $GLOBALS['__nf_deleted']['messages'], [ 10, 12 ], $passed, $failed );
	check( '4d. AI drafts cascade',                 $GLOBALS['__nf_deleted']['ai'], [ 10, 12 ], $passed, $failed );
	check( '4e. unrelated entry retained',          array_column( $GLOBALS['__nf_entries'], 'id' ), [ 11 ], $passed, $failed );
	check( '4f. done + nothing retained',           [ $e['done'], $e['items_retained'] ], [ true, 0 ], $passed, $failed );

	// ── 5. registration ──
	PrivacyTools::register();
	$ex = PrivacyTools::register_exporter( [] );
	$er = PrivacyTools::register_eraser( [] );
	check( '5a. exporter registered under plugin slug', isset( $ex['neural-forms-lite']['callback'] ), true, $passed, $failed );
	check( '5b. eraser registered under plugin slug',   isset( $er['neural-forms-lite']['callback'] ), true, $passed, $failed );
	check( '5c. hooks attached',                        [ isset( $GLOBALS['__nf_hooks']['wp_privacy_personal_data_exporters'] ), isset( $GLOBALS['__nf_hooks']['wp_privacy_personal_data_erasers'] ), isset( $GLOBALS['__nf_hooks']['admin_init'] ) ], [ true, true, true ], $passed, $failed );
	PrivacyTools::add_policy_content();
	check( '5d. policy text registered',                $GLOBALS['__nf_policy'][0] ?? null, 'Neural Forms Lite', $passed, $failed );
	check( '5e. policy text mentions Privacy mode',     strpos( (string) ( $GLOBALS['__nf_policy'][1] ?? '' ), 'Privacy mode' ) !== false, true, $passed, $failed );

	// ── 6. scrub query ──
	$db_src = file_get_contents( $plugin_root . '/includes/class-database.php' );
	preg_match( '/public static function scrub_visitor_metadata\(.+?\n\t\}/s', $db_src, $m ) || exit( 2 );
	class WPDB_Scrub { public $prefix = 'wp_'; public $sql = ''; public function prepare( $q, ...$a ) { return str_replace( '%i', $a[0], $q ); } public function query( $q ) { $this->sql = $q; return 7; } }
	$GLOBALS['wpdb'] = new WPDB_Scrub();
	eval( 'namespace NeuralForms\\Tests { class Scrub { ' . $m[0] . ' } }' );
	check( '6a. scrub returns affected rows',           \NeuralForms\Tests\Scrub::scrub_visitor_metadata(), 7, $passed, $failed );
	check( '6b. scrub blanks both columns on the entries table', $GLOBALS['wpdb']->sql, 'UPDATE wp_neural_forms_entries SET ip_address = NULL, user_agent = NULL WHERE ip_address IS NOT NULL OR user_agent IS NOT NULL', $passed, $failed );
	check( '6c. upgrade runs scrub only when privacy_mode is on', (bool) preg_match( "/privacy_mode'\\] \\) \\) \\{\\s*self::scrub_visitor_metadata\\(\\);/", $db_src ), true, $passed, $failed );

	// ── 7. DB version ──
	// The privacy scrub shipped with DB version 1.1.3; later releases keep
	// bumping the version (1.2.0 adds the lead/workflow schema), so assert
	// "at least 1.1.3" rather than the literal.
	preg_match( "/define\( 'NEURAL_FORMS_DB_VERSION', '([0-9.]+)' \);/", file_get_contents( $plugin_root . '/neural-forms-lite.php' ), $db_ver_m );
	check( '7. NEURAL_FORMS_DB_VERSION is at least 1.1.3', version_compare( $db_ver_m[1] ?? '0', '1.1.3', '>=' ), true, $passed, $failed );

	echo "\n{$passed} passed, {$failed} failed\n";
	exit( $failed === 0 ? 0 : 1 );
}
