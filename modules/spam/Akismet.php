<?php
namespace NeuralForms\Spam;

use NeuralForms\Core\Logger;
use NeuralForms\Storage\EntryActivity;

defined( 'ABSPATH' ) || exit;

/**
 * Akismet
 *
 * Optional content check for form submissions through the site's own
 * Akismet plugin (active + connected). Unlike a CAPTCHA it never blocks:
 * a submission Akismet calls spam is SAVED with status "spam" so a false
 * positive can be recovered from the inbox, and the visitor sees the normal
 * confirmation. Spam entries skip notifications, AI, workflows and
 * integrations (SubmitEndpoint).
 *
 * Enabled per form (settings.akismet_enabled). Akismet being unreachable or
 * misconfigured never blocks a submission — the entry goes through as normal.
 *
 * When the owner moves an entry into or out of Spam in the inbox, the
 * stored request is reported back (submit-spam / submit-ham) so Akismet
 * learns from the correction.
 *
 * @see https://akismet.com/developers/
 */
class Akismet {

	/** Activity type that stores the request sent, for later feedback. */
	public const ACTIVITY_TYPE = 'entry.akismet';

	/** Verdicts returned by check(). */
	public const SPAM  = 'spam';
	public const HAM   = 'ham';
	public const ERROR = 'error';

	/**
	 * Akismet plugin active and connected with an API key.
	 */
	public static function available(): bool {
		if ( ! class_exists( '\Akismet' ) || ! is_callable( array( '\Akismet', 'http_post' ) ) || ! is_callable( array( '\Akismet', 'get_api_key' ) ) ) {
			return false;
		}
		return (string) \Akismet::get_api_key() !== '';
	}

	/**
	 * Form setting + availability.
	 *
	 * @param array $form Form row (settings decoded).
	 */
	public static function enabled_for( array $form ): bool {
		$settings = is_array( $form['settings'] ?? null ) ? $form['settings'] : array();
		return ! empty( $settings['akismet_enabled'] ) && self::available();
	}

	/**
	 * Build the comment-check request from a submission. Pure — no
	 * WordPress calls — so it is unit-testable.
	 *
	 * Name    = first text field whose id or label mentions "name".
	 * Email   = first email field.
	 * URL     = first url field.
	 * Content = every paragraph field, plus any other text field that is
	 *           not the name, joined by blank lines.
	 *
	 * @param array $fields Flattened schema fields (id, type, label).
	 * @param array $data   Sanitised submission values keyed by field id.
	 * @param array $env    blog, user_ip, user_agent, referrer, permalink, blog_lang, blog_charset.
	 * @return array<string,string>
	 */
	public static function build_request( array $fields, array $data, array $env ): array {
		$name = ''; $email = ''; $url = ''; $content = array(); $name_id = null;

		foreach ( $fields as $f ) {
			$id   = (string) ( $f['id'] ?? '' );
			$type = (string) ( $f['type'] ?? '' );
			$val  = $data[ $id ] ?? null;
			if ( $type === 'name' && $name === '' && is_array( $val ) && class_exists( '\\NeuralForms\\Forms\\ExtraFields' ) ) {
				$name    = \NeuralForms\Forms\ExtraFields::display( $f, $val );
				$name_id = $id;
				continue;
			}
			if ( $id === '' || ! is_scalar( $val ) || trim( (string) $val ) === '' ) {
				continue;
			}
			$val = trim( (string) $val );
			if ( $type === 'email' && $email === '' ) {
				$email = $val;
			} elseif ( $type === 'url' && $url === '' ) {
				$url = $val;
			} elseif ( $type === 'text' && $name === '' && preg_match( '/name/i', $id . ' ' . (string) ( $f['label'] ?? '' ) ) ) {
				$name    = $val;
				$name_id = $id;
			}
		}
		foreach ( $fields as $f ) {
			$id   = (string) ( $f['id'] ?? '' );
			$type = (string) ( $f['type'] ?? '' );
			$val  = $data[ $id ] ?? null;
			if ( $id === $name_id || ! in_array( $type, array( 'textarea', 'text' ), true ) || ! is_scalar( $val ) || trim( (string) $val ) === '' ) {
				continue;
			}
			$content[] = trim( (string) $val );
		}

		$req = array(
			'blog'                 => (string) ( $env['blog'] ?? '' ),
			'user_ip'              => (string) ( $env['user_ip'] ?? '' ),
			'user_agent'           => (string) ( $env['user_agent'] ?? '' ),
			'referrer'             => (string) ( $env['referrer'] ?? '' ),
			'permalink'            => (string) ( $env['permalink'] ?? '' ),
			'comment_type'         => 'contact-form',
			'comment_author'       => $name,
			'comment_author_email' => $email,
			'comment_author_url'   => $url,
			'comment_content'      => implode( "\n\n", $content ),
			'blog_lang'            => (string) ( $env['blog_lang'] ?? '' ),
			'blog_charset'         => (string) ( $env['blog_charset'] ?? '' ),
		);
		return array_filter( $req, static function ( $v ) { return $v !== ''; } );
	}

	/**
	 * Request environment for the current submission.
	 *
	 * @param string $ip       Resolved client IP (used even in privacy mode:
	 *                         Akismet needs it and it is never stored here).
	 * @param string $page_url Page the form was submitted from.
	 */
	public static function environment( string $ip, string $page_url ): array {
		return array(
			'blog'         => home_url( '/' ),
			'user_ip'      => $ip,
			'user_agent'   => sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ),
			'referrer'     => esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ?? '' ) ),
			'permalink'    => $page_url,
			'blog_lang'    => get_locale(),
			'blog_charset' => (string) get_option( 'blog_charset' ),
		);
	}

	/**
	 * Map Akismet's raw reply to a verdict. "true" = spam, "false" = ham,
	 * anything else (invalid key, error) = error.
	 *
	 * @param mixed $body Response body.
	 */
	public static function parse_verdict( $body ): string {
		$b = trim( (string) $body );
		if ( $b === 'true' ) {
			return self::SPAM;
		}
		if ( $b === 'false' ) {
			return self::HAM;
		}
		return self::ERROR;
	}

	/**
	 * Ask Akismet about a submission.
	 *
	 * @param array $request From build_request().
	 * @return string self::SPAM | self::HAM | self::ERROR
	 */
	public static function check( array $request ): string {
		if ( ! self::available() ) {
			return self::ERROR;
		}
		try {
			$res = \Akismet::http_post( \Akismet::build_query( $request ), 'comment-check' );
		} catch ( \Throwable $e ) {
			$res = null;
		}
		$verdict = self::parse_verdict( is_array( $res ) ? ( $res[1] ?? '' ) : '' );
		if ( $verdict === self::ERROR ) {
			Logger::file( 'akismet', 'warning', 'Akismet comment-check failed; submission accepted without a spam check.' );
		}
		return $verdict;
	}

	/**
	 * Keep the request on the entry timeline so a later Spam / Not spam
	 * click can be reported back. Deleted with the entry.
	 */
	public static function remember( int $entry_id, array $request, string $verdict ): void {
		EntryActivity::record(
			$entry_id,
			self::ACTIVITY_TYPE,
			$verdict === self::SPAM
				? __( 'Akismet marked this submission as spam', 'neural-forms-lite' )
				: __( 'Akismet checked this submission', 'neural-forms-lite' ),
			array( 'verdict' => $verdict, 'request' => $request )
		);
	}

	/**
	 * Report an inbox correction back to Akismet. Only entries Akismet
	 * actually checked are reported, and only when the owner's choice
	 * disagrees with Akismet's verdict.
	 *
	 * @param int    $entry_id
	 * @param string $from     Previous status.
	 * @param string $to       New status.
	 */
	/**
	 * True only while the inbox applies the owner's own status change
	 * (EntriesEndpoint). Automated changes (AI spam detection, workflows)
	 * are never reported to Akismet.
	 *
	 * @var bool
	 */
	public static $owner_change = false;

	/** Reports sent in this request; a big bulk action stops here so it can't time out. */
	public const MAX_REPORTS_PER_REQUEST = 20;

	/** @var int */
	private static $reports_sent = 0;

	public static function on_status_changed( int $entry_id, string $from, string $to ): void {
		if ( ! self::$owner_change || $from === $to || ( $from !== 'spam' && $to !== 'spam' ) || ! self::available() ) {
			return;
		}
		if ( self::$reports_sent >= self::MAX_REPORTS_PER_REQUEST ) {
			return;
		}
		$stored = self::stored_request( $entry_id );
		if ( ! $stored ) {
			return;
		}
		$path = $to === 'spam' ? 'submit-spam' : 'submit-ham';
		if ( ( $path === 'submit-spam' && $stored['verdict'] === self::SPAM ) || ( $path === 'submit-ham' && $stored['verdict'] !== self::SPAM ) ) {
			return; // Akismet already agreed.
		}
		try {
			++self::$reports_sent;
			\Akismet::http_post( \Akismet::build_query( $stored['request'] ), $path );
			EntryActivity::record(
				$entry_id,
				self::ACTIVITY_TYPE,
				$to === 'spam'
					? __( 'Reported to Akismet as spam', 'neural-forms-lite' )
					: __( 'Reported to Akismet as not spam', 'neural-forms-lite' ),
				array( 'verdict' => $to === 'spam' ? self::SPAM : self::HAM, 'request' => $stored['request'], 'feedback' => true )
			);
		} catch ( \Throwable $e ) {
			Logger::file( 'akismet', 'warning', sprintf( 'Akismet %s failed for entry %d.', $path, $entry_id ) );
		}
	}

	/**
	 * Latest stored request + verdict for an entry, or null.
	 *
	 * @return array{verdict:string,request:array}|null
	 */
	private static function stored_request( int $entry_id ): ?array {
		foreach ( EntryActivity::list( $entry_id, 100 ) as $row ) {
			if ( ( $row['event_type'] ?? '' ) !== self::ACTIVITY_TYPE ) {
				continue;
			}
			$p = is_array( $row['payload'] ?? null ) ? $row['payload'] : array();
			if ( is_array( $p['request'] ?? null ) && $p['request'] ) {
				return array( 'verdict' => (string) ( $p['verdict'] ?? self::HAM ), 'request' => $p['request'] );
			}
		}
		return null;
	}
}
