<?php
namespace NeuralForms\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Logger
 *
 * Hybrid logging: lightweight product-activity events go to the database
 * (visible in admin); verbose debug/error traces go to daily log files.
 *
 * Public API
 *
 *   Logger::log( ... )   '->-> database only  (admin-visible activity trail)
 *   Logger::file( ... )  '->-> file only      (debug/diagnostic traces)
 *   Logger::both( ... )  '->-> database + file (actionable errors)
 *
 * File logs are stored under the site's uploads directory at
 * <uploads>/neural-forms/logs/ (resolved via wp_upload_dir(), so the path
 * follows multisite/UPLOADS overrides) with daily rotation and automatic
 * .htaccess protection.
 *
 * All methods are fire-and-forget - failures are silently swallowed so logging
 * can never break a request or submission.
 */
class Logger {

	/** @var bool Whether the log directory has been verified this request. */
	private static bool $dir_ready = false;

	// Public API

	/**
	 * Write a log entry to the wp_neural_forms_logs table.
	 *
	 * Use for short, product-relevant events the admin may see in a dashboard
	 * (reply_sent, ai_auto_spam, test_email, etc.).
	 *
	 * @param string   $event_type  Machine-readable event slug (max 100 chars).
	 * @param string   $status      Severity: 'info', 'warning', or 'error'.
	 * @param string   $message     Human-readable description of the event.
	 * @param array    $payload     Contextual data - will be JSON-encoded.
	 * @param int|null $entry_id    Related entry ID when applicable.
	 */
	public static function log(
		string $event_type,
		string $status,
		string $message,
		array $payload = array(),
		?int $entry_id = null
	): void {
		self::write_db( $event_type, $status, $message, $payload, $entry_id );
	}

	/**
	 * Write a log line to the daily log file.
	 *
	 * Use for verbose debug/diagnostic data that should not bloat the database
	 * (queue heartbeats, API payloads, stack traces, etc.).
	 *
	 * @param string $channel  Logical channel, e.g. 'ai_queue', 'captcha', 'upload'.
	 * @param string $level    Severity: 'debug', 'info', 'warning', 'error'.
	 * @param string $message  Human-readable log line.
	 * @param array  $context  Extra data - JSON-encoded inline.
	 */
	public static function file(
		string $channel,
		string $level,
		string $message,
		array $context = array()
	): void {
		self::write_file( $channel, $level, $message, $context );
	}

	/**
	 * Write to both the database and the log file.
	 *
	 * Use for actionable errors that the admin needs to see AND that contain
	 * detail useful for debugging (ai_processing_error, etc.).
	 *
	 * @param string   $event_type  DB event_type slug.
	 * @param string   $status      DB status ('info', 'warning', 'error').
	 * @param string   $message     Shared message for both destinations.
	 * @param array    $payload     Contextual data (written to both DB and file).
	 * @param int|null $entry_id    Related entry ID.
	 * @param string   $channel     File channel (defaults to event_type).
	 */
	public static function both(
		string $event_type,
		string $status,
		string $message,
		array $payload = array(),
		?int $entry_id = null,
		string $channel = ''
	): void {
		self::write_db( $event_type, $status, $message, $payload, $entry_id );
		self::write_file( $channel ?: $event_type, $status, $message, $payload );
	}

	// Database writer

	private static function write_db(
		string $event_type,
		string $status,
		string $message,
		array $payload,
		?int $entry_id
	): void {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return;
		}

		$data    = array();
		$formats = array();

		if ( null !== $entry_id ) {
			$data['entry_id'] = $entry_id;
			$formats[]        = '%d';
		}

		$data['event_type'] = substr( $event_type, 0, 100 );
		$data['payload']    = ! empty( $payload ) ? (string) wp_json_encode( $payload ) : null;
		$data['status']     = $status;
		$data['message']    = $message;
		$data['created_at'] = current_time( 'mysql', true );

		$formats = array_merge( $formats, array( '%s', '%s', '%s', '%s', '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert( $wpdb->prefix . 'neural_forms_logs', $data, $formats );
	}

	// File writer

	private static function write_file(
		string $channel,
		string $level,
		string $message,
		array $context
	): void {
		try {
			$dir = self::log_dir();
			if ( ! $dir ) {
				return;
			}

			$file = $dir . 'neural-forms-' . gmdate( 'Y-m-d' ) . '.log';
			$ts   = gmdate( 'Y-m-d H:i:s' );
			$ctx  = ! empty( $context ) ? ' ' . wp_json_encode( $context, JSON_UNESCAPED_SLASHES ) : '';
			$line = sprintf( "[%s] %s.%s: %s%s\n", $ts, $channel, strtoupper( $level ), $message, $ctx );

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $file, $line, FILE_APPEND | LOCK_EX );
		} catch ( \Throwable $e ) {
			// Swallow - logging must never break anything.
			unset( $e );
		}
	}

	// Directory bootstrap

	/**
	 * Resolve the log directory path under the active site's uploads dir.
	 *
	 * Uses wp_upload_dir() so the path is correct on multisite, on installs
	 * with a relocated UPLOADS constant, and on hosts that mount uploads
	 * outside wp-content (WordPress.org plugin guideline).
	 *
	 * Returns null when wp_upload_dir() reports an error or no basedir, so
	 * callers can bail out without touching the filesystem.
	 *
	 * @return string|null  Trailing-slash directory path, or null on failure.
	 */
	private static function logs_path(): ?string {
		$uploads = wp_upload_dir( null, false );
		if ( ! is_array( $uploads ) || ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return null;
		}
		return trailingslashit( $uploads['basedir'] ) . 'neural-forms/logs/';
	}

	/**
	 * Ensure the log directory exists with web-access protection.
	 *
	 * @return string|null  Trailing-slash directory path, or null on failure.
	 */
	private static function log_dir(): ?string {
		$dir = self::logs_path();
		if ( null === $dir ) {
			return null;
		}

		if ( self::$dir_ready ) {
			return $dir;
		}

		if ( ! is_dir( $dir ) ) {
			if ( ! wp_mkdir_p( $dir ) ) {
				return null;
			}
		}

		// Protect against direct web access.
		$htaccess = $dir . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $htaccess, "Deny from all\n" );
		}

		$index = $dir . 'index.php';
		if ( ! file_exists( $index ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $index, "<?php\n// Silence.\n" );
		}

		self::$dir_ready = true;

		return $dir;
	}

	// Cleanup (called by daily cron)

	/**
	 * Delete DB log rows older than 60 days and log files older than 14 days.
	 */
	public static function cleanup(): void {
		global $wpdb;

		// DB: remove rows older than 60 days.
		if ( $wpdb instanceof \wpdb ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query(
				"DELETE FROM {$wpdb->prefix}neural_forms_logs
				 WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 60 DAY)"
			);
		}

		// Files: remove log files older than 14 days.
		$dir = self::logs_path();
		if ( null === $dir || ! is_dir( $dir ) ) {
			return;
		}

		$cutoff = time() - ( 14 * DAY_IN_SECONDS );
		$files  = glob( $dir . 'neural-forms-*.log' );

		if ( ! is_array( $files ) ) {
			return;
		}

		foreach ( $files as $file ) {
			// Extract date from filename: neural-forms-YYYY-MM-DD.log
			if ( preg_match( '/neural-forms-(\d{4}-\d{2}-\d{2})\.log$/', $file, $m ) ) {
				$file_ts = strtotime( $m[1] . ' 00:00:00 UTC' );
				if ( $file_ts && $file_ts < $cutoff ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
					unlink( $file );
				}
			}
		}
	}
}
