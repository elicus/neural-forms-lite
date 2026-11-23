<?php
/**
 * hCaptcha, reCAPTCHA v3 and Akismet (1.3.0).
 *
 * Run from the plugin root:
 *
 *   php tests/SpamProtectionTest.php
 *
 * Coverage:
 *   1. Siteverify verifiers: request body per provider (hCaptcha sends the
 *      site key), unreachable / failed / passed outcomes
 *   2. reCAPTCHA v3: action must match, score threshold, threshold clamp
 *   3. CaptchaManager::PROVIDERS and the settings schema use it
 *   4. Settings: switching provider without new keys is refused
 *   5. Renderer: hCaptcha widget + script, v3 invisible marker + render= script
 *   6. Akismet::build_request() field mapping, parse_verdict(), enabled_for()
 *   7. Akismet feedback: submit-spam / submit-ham only on disagreement
 *   8. SubmitEndpoint: Akismet spam skips notifications / AI / created hook;
 *      payment forms skip the check; privacy mode strips IP from storage
 */

declare( strict_types = 1 );

namespace {
	if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', '/tmp/abspath' );
	// phpcs:disable -- CLI-only test runner.

	class WP_Error {
		public $code; public $message; public $data;
		public function __construct( $c = '', $m = '', $d = null ) { $this->code = $c; $this->message = $m; $this->data = $d; }
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
		public function get_error_data() { return $this->data; }
	}
	function __( $s, $d = null ) { return $s; }
	function is_wp_error( $x ) { return $x instanceof WP_Error; }
	function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? ( $r['body'] ?? '' ) : ''; }

	$GLOBALS['__http'] = [ 'calls' => [], 'reply' => null ];
	function wp_remote_post( $url, $args ) {
		$GLOBALS['__http']['calls'][] = [ $url, $args['body'] ?? [] ];
		return $GLOBALS['__http']['reply'];
	}

	$GLOBALS['__activity'] = [];
	$GLOBALS['__akismet']  = [ 'key' => 'k', 'calls' => [], 'reply' => 'false' ];
	class Akismet {
		public static function get_api_key() { return $GLOBALS['__akismet']['key']; }
		public static function build_query( $a ) { return http_build_query( $a ); }
		public static function http_post( $q, $path ) { $GLOBALS['__akismet']['calls'][] = [ $path, $q ]; return [ [], $GLOBALS['__akismet']['reply'] ]; }
	}

	eval( '
	namespace NeuralForms\\Core {
		class Logger { public static $lines = []; public static function file( $a, $b, $c ) { self::$lines[] = $c; } public static function log( ...$a ) {} }
	}
	namespace NeuralForms\\Storage {
		class EntryActivity {
			public static function record( int $id, string $t, string $m, array $p = [], string $s = "info" ): void { array_unshift( $GLOBALS["__activity"], [ "entry_id" => $id, "event_type" => $t, "message" => $m, "payload" => $p ] ); }
			public static function list( int $id, int $limit = 100 ): array { return array_values( array_filter( $GLOBALS["__activity"], fn( $r ) => $r["entry_id"] === $id ) ); }
		}
	}' );

	$root = dirname( __DIR__ );
	require $root . '/modules/captcha/CaptchaVerifierInterface.php';
	require $root . '/modules/captcha/SiteverifyVerifier.php';
	require $root . '/modules/captcha/TurnstileVerifier.php';
	require $root . '/modules/captcha/HCaptchaVerifier.php';
	require $root . '/modules/captcha/RecaptchaV2Verifier.php';
	require $root . '/modules/captcha/RecaptchaV3Verifier.php';
	require $root . '/modules/spam/Akismet.php';

	use NeuralForms\Captcha\HCaptchaVerifier;
	use NeuralForms\Captcha\RecaptchaV2Verifier;
	use NeuralForms\Captcha\RecaptchaV3Verifier;
	use NeuralForms\Captcha\TurnstileVerifier;
	use NeuralForms\Spam\Akismet as NFAkismet;

	$pass = 0; $fail = 0;
	$ok = function ( $cond, string $label ) use ( &$pass, &$fail ) {
		if ( $cond ) { $pass++; } else { $fail++; echo "FAIL: $label\n"; }
	};
	$reply = function ( $json ) { $GLOBALS['__http']['reply'] = [ 'body' => is_string( $json ) ? $json : json_encode( $json ) ]; $GLOBALS['__http']['calls'] = []; };

	// ── 1. Siteverify verifiers ──
	$reply( [ 'success' => true ] );
	$ok( ( new TurnstileVerifier( 's' ) )->verify( 't', '1.2.3.4' ) === true, 'turnstile passes' );
	$ok( $GLOBALS['__http']['calls'][0][0] === 'https://challenges.cloudflare.com/turnstile/v0/siteverify', 'turnstile url' );
	$ok( $GLOBALS['__http']['calls'][0][1] === [ 'secret' => 's', 'response' => 't', 'remoteip' => '1.2.3.4' ], 'turnstile body' );

	$reply( [ 'success' => true ] );
	$ok( ( new HCaptchaVerifier( 's', 'site' ) )->verify( 't', '' ) === true, 'hcaptcha passes' );
	$ok( $GLOBALS['__http']['calls'][0][0] === 'https://api.hcaptcha.com/siteverify', 'hcaptcha url' );
	$ok( $GLOBALS['__http']['calls'][0][1] === [ 'secret' => 's', 'response' => 't', 'sitekey' => 'site' ], 'hcaptcha sends sitekey, no empty remoteip' );

	$reply( [ 'success' => false, 'error-codes' => [ 'invalid-input-response' ] ] );
	$r = ( new HCaptchaVerifier( 's', 'site' ) )->verify( 't', '' );
	$ok( is_wp_error( $r ) && $r->get_error_code() === 'captcha_failed' && $r->get_error_data()['error_codes'] === [ 'invalid-input-response' ], 'hcaptcha failure codes' );

	$GLOBALS['__http']['reply'] = new WP_Error( 'http', 'down' );
	$r = ( new RecaptchaV2Verifier( 's' ) )->verify( 't', '' );
	$ok( is_wp_error( $r ) && $r->get_error_code() === 'captcha_unreachable' && $r->get_error_data()['provider'] === 'recaptcha_v2', 'unreachable' );

	$reply( 'not json' );
	$r = ( new RecaptchaV2Verifier( 's' ) )->verify( 't', '' );
	$ok( is_wp_error( $r ) && $r->get_error_code() === 'captcha_failed', 'garbage body fails' );

	// ── 2. reCAPTCHA v3 ──
	$v3 = new RecaptchaV3Verifier( 's', 0.5 );
	$reply( [ 'success' => true, 'action' => 'neural_forms_submit', 'score' => 0.9 ] );
	$ok( $v3->verify( 't', '' ) === true, 'v3 high score passes' );
	$reply( [ 'success' => true, 'action' => 'neural_forms_submit', 'score' => 0.5 ] );
	$ok( $v3->verify( 't', '' ) === true, 'v3 score equal to threshold passes' );
	$reply( [ 'success' => true, 'action' => 'neural_forms_submit', 'score' => 0.3 ] );
	$r = $v3->verify( 't', '' );
	$ok( is_wp_error( $r ) && $r->get_error_data()['reason'] === 'low_score' && $r->get_error_data()['score'] === 0.3, 'v3 low score fails with score logged' );
	$reply( [ 'success' => true, 'action' => 'login', 'score' => 0.9 ] );
	$r = $v3->verify( 't', '' );
	$ok( is_wp_error( $r ) && $r->get_error_data()['reason'] === 'action_mismatch', 'v3 wrong action fails' );
	$reply( [ 'success' => true, 'action' => 'neural_forms_submit' ] );
	$ok( is_wp_error( $v3->verify( 't', '' ) ), 'v3 missing score fails' );
	$reply( [ 'success' => false ] );
	$ok( is_wp_error( $v3->verify( 't', '' ) ), 'v3 success:false fails' );
	$ok( RecaptchaV3Verifier::clamp_threshold( 0 ) === 0.1 && RecaptchaV3Verifier::clamp_threshold( 5 ) === 0.9, 'clamp range' );
	$ok( RecaptchaV3Verifier::clamp_threshold( 'abc' ) === 0.5 && RecaptchaV3Verifier::clamp_threshold( '0.74' ) === 0.7, 'clamp junk + rounding' );

	// ── 3 / 4. Provider list + settings rule (source-level) ──
	$mgr = file_get_contents( $root . '/modules/captcha/CaptchaManager.php' );
	$ok( strpos( $mgr, "array( 'none', 'turnstile', 'hcaptcha', 'recaptcha_v2', 'recaptcha_v3' )" ) !== false, 'PROVIDERS list' );
	$ok( strpos( $mgr, "case 'hcaptcha':" ) !== false && strpos( $mgr, "case 'recaptcha_v3':" ) !== false, 'manager cases' );
	$set = file_get_contents( $root . '/modules/rest/Endpoints/SettingsEndpoint.php' );
	$ok( strpos( $set, 'CaptchaManager::PROVIDERS, true' ) !== false, 'schema uses PROVIDERS' );
	$ok( strpos( $set, '$provider_changed && ! isset' ) !== false, 'key rule only when the provider changes' );
	$ok( strpos( $set, "'captcha_new_keys_required'" ) !== false && strpos( $set, "self::stored_keys_provider() !== \$captcha_provider" ) !== false, 'provider switch needs new keys' );
	$ok( strpos( $set, "\$updates['captcha_keys_provider'] = \$captcha_provider;" ) !== false, 'keys provider recorded with new secret' );

	// ── 5. Renderer (source-level) ──
	$ren = file_get_contents( $root . '/modules/frontend/FormRenderer.php' );
	$ok( strpos( $ren, "'hcaptcha'     => 'h-captcha'" ) !== false && strpos( $ren, 'https://js.hcaptcha.com/1/api.js' ) !== false, 'hcaptcha widget + script' );
	$ok( strpos( $ren, "add_query_arg( 'render', rawurlencode( \$captcha_site_key ), 'https://www.google.com/recaptcha/api.js' )" ) !== false, 'v3 render= script' );
	$ok( strpos( $ren, 'RecaptchaV3Verifier::ACTION' ) !== false, 'v3 data-action from the verifier constant' );
	$js = file_get_contents( $root . '/modules/frontend/assets/js/form-submit.js' );
	$ok( strpos( $js, "'h-captcha-response'" ) !== false && strpos( $js, 'executeRecaptchaV3' ) !== false, 'frontend token handling' );

	// ── 6. Akismet request ──
	$fields = [
		[ 'id' => 'full_name', 'type' => 'text', 'label' => 'Your name' ],
		[ 'id' => 'mail', 'type' => 'email', 'label' => 'Email' ],
		[ 'id' => 'site', 'type' => 'url', 'label' => 'Website' ],
		[ 'id' => 'subject', 'type' => 'text', 'label' => 'Subject' ],
		[ 'id' => 'msg', 'type' => 'textarea', 'label' => 'Message' ],
		[ 'id' => 'tags', 'type' => 'checkbox', 'label' => 'Tags' ],
	];
	$data = [ 'full_name' => ' Jo ', 'mail' => 'jo@example.com', 'site' => 'https://x.test', 'subject' => 'Hi', 'msg' => 'Buy now', 'tags' => [ 'a' ] ];
	$req  = NFAkismet::build_request( $fields, $data, [ 'blog' => 'https://b.test/', 'user_ip' => '1.1.1.1', 'user_agent' => 'UA', 'permalink' => 'https://b.test/contact' ] );
	$ok( $req['comment_author'] === 'Jo', 'author from name field' );
	$ok( $req['comment_author_email'] === 'jo@example.com' && $req['comment_author_url'] === 'https://x.test', 'email + url' );
	$ok( $req['comment_content'] === "Hi\n\nBuy now", 'content = other text + textarea' );
	$ok( $req['comment_type'] === 'contact-form' && $req['user_ip'] === '1.1.1.1' && ! isset( $req['referrer'] ), 'type, ip, empty keys dropped' );
	$req2 = NFAkismet::build_request( [ [ 'id' => 'a', 'type' => 'text', 'label' => 'Company' ] ], [ 'a' => 'Acme' ], [] );
	$ok( ! isset( $req2['comment_author'] ) && $req2['comment_content'] === 'Acme', 'no name field: text goes to content' );
	$ok( NFAkismet::parse_verdict( 'true' ) === 'spam' && NFAkismet::parse_verdict( "false\n" ) === 'ham' && NFAkismet::parse_verdict( 'invalid' ) === 'error', 'verdicts' );
	$ok( NFAkismet::enabled_for( [ 'settings' => [ 'akismet_enabled' => true ] ] ) === true, 'enabled with key' );
	$ok( NFAkismet::enabled_for( [ 'settings' => [] ] ) === false, 'off by default' );
	$GLOBALS['__akismet']['key'] = '';
	$ok( NFAkismet::enabled_for( [ 'settings' => [ 'akismet_enabled' => true ] ] ) === false && NFAkismet::check( [] ) === 'error', 'no key = unavailable' );
	$GLOBALS['__akismet']['key'] = 'k';
	$GLOBALS['__akismet']['reply'] = 'true';
	$ok( NFAkismet::check( $req ) === 'spam' && $GLOBALS['__akismet']['calls'][0][0] === 'comment-check', 'check calls comment-check' );
	$GLOBALS['__akismet']['reply'] = '';
	$ok( NFAkismet::check( $req ) === 'error', 'empty reply = error' );

	// ── 7. Feedback ──
	$GLOBALS['__akismet']['calls'] = [];
	NFAkismet::remember( 7, $req, 'spam' );
	NFAkismet::on_status_changed( 7, 'spam', 'read' );
	$ok( $GLOBALS['__akismet']['calls'] === [], 'automated change (AI, workflow) never reported' );
	NFAkismet::$owner_change = true;
	NFAkismet::on_status_changed( 7, 'new', 'spam' );
	$ok( $GLOBALS['__akismet']['calls'] === [], 'no report when Akismet already said spam' );
	NFAkismet::on_status_changed( 7, 'spam', 'read' );
	$ok( ( $GLOBALS['__akismet']['calls'][0][0] ?? '' ) === 'submit-ham', 'not spam reported as ham' );
	NFAkismet::remember( 8, $req, 'ham' );
	$GLOBALS['__akismet']['calls'] = [];
	NFAkismet::on_status_changed( 8, 'read', 'trash' );
	$ok( $GLOBALS['__akismet']['calls'] === [], 'unrelated status change ignored' );
	NFAkismet::on_status_changed( 8, 'new', 'spam' );
	$ok( ( $GLOBALS['__akismet']['calls'][0][0] ?? '' ) === 'submit-spam', 'missed spam reported' );
	$GLOBALS['__akismet']['calls'] = [];
	NFAkismet::on_status_changed( 99, 'new', 'spam' );
	$ok( $GLOBALS['__akismet']['calls'] === [], 'unchecked entry never reported' );
	$ep = file_get_contents( $root . '/modules/rest/Endpoints/EntriesEndpoint.php' );
	$ok( substr_count( $ep, 'Akismet::$owner_change = true;' ) === 2 && substr_count( $ep, 'Akismet::$owner_change = false;' ) === 2, 'only inbox actions flag an owner change' );
	NFAkismet::remember( 9, $req, 'ham' );
	$GLOBALS['__akismet']['calls'] = [];
	for ( $i = 0; $i < 30; $i++ ) { NFAkismet::on_status_changed( 9, 'new', 'spam' ); }
	$ok( count( $GLOBALS['__akismet']['calls'] ) <= NFAkismet::MAX_REPORTS_PER_REQUEST, 'bulk reports capped per request' );
	NFAkismet::$owner_change = false;
	$sub0 = file_get_contents( $root . '/modules/rest/Endpoints/SubmitEndpoint.php' );
	$ok( strpos( $sub0, "apply_filters( 'neural_forms_spam_check', false, \$_spam_view, \$form )" ) !== false, 'spam filter never sees passwords' );

	// ── 8. SubmitEndpoint wiring (source-level) ──
	$sub = file_get_contents( $root . '/modules/rest/Endpoints/SubmitEndpoint.php' );
	$ok( strpos( $sub, "if ( ! \$_is_akismet_spam ) {\n\t\t\tdo_action( 'neural_forms_entry_created'" ) !== false, 'created hook skipped for spam' );
	$ok( strpos( $sub, "if ( ! \$defer_notifications && ! \$_is_akismet_spam ) {" ) !== false, 'notifications skipped for spam' );
	$ok( strpos( $sub, "if ( ! \$_is_akismet_spam ) {\n\t\t\tdo_action( 'neural_forms_queue_ai_processing'" ) !== false, 'AI skipped for spam' );
	$ok( strpos( $sub, "( ! \$_pricing || (int) ( \$_pricing['due_now'] ?? 0 ) <= 0 ) && \\NeuralForms\\Spam\\Akismet::enabled_for( \$form )" ) !== false, 'payment forms not checked' );
	$ok( strpos( $sub, "unset( \$_stored_request['user_ip'], \$_stored_request['user_agent'] );" ) !== false, 'privacy mode keeps IP out of storage' );
	$repo = file_get_contents( $root . '/modules/storage/EntryRepository.php' );
	$ok( strpos( $repo, "( \$context['status'] ?? '' ) === 'spam' ? 'spam' : 'new'" ) !== false, 'create() accepts spam status only' );
	$ok( substr_count( $repo, "do_action( 'neural_forms_entry_status_changed'" ) === 2, 'status hook in update + bulk_update' );

	echo "SpamProtectionTest: $pass passed, $fail failed\n";
	exit( $fail ? 1 : 0 );
}
