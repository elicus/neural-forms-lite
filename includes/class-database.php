<?php
namespace NeuralForms;

defined( 'ABSPATH' ) || exit;

/**
 * Manages database table creation and upgrades.
 *
 * Rules for dbDelta() compatibility (it is strict about formatting):
 *  - Each column definition must be on its own line.
 *  - PRIMARY KEY must have two spaces before it.
 *  - Use KEY, not INDEX.
 *  - No trailing comma after the last key definition.
 *
 * We avoid ENUM columns because dbDelta cannot alter them reliably.
 * VARCHAR with a documented set of values is safer for upgrades.
 */
class Database {

	/**
	 * Run from Activator::activate() and on upgrade checks.
	 * Safe to call multiple times - dbDelta only applies changes.
	 */
	public static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		$tables = array(
			self::sql_forms( $charset ),
			self::sql_entries( $charset ),
			self::sql_logs( $charset ),
			self::sql_queue( $charset ),
			self::sql_entry_messages( $charset ),
			self::sql_ai_responses( $charset ),
		);

		foreach ( $tables as $sql ) {
			dbDelta( $sql );
		}

		update_option( 'neural_forms_db_version', NEURAL_FORMS_DB_VERSION );
	}

	/**
	 * Returns true if the stored DB version is behind the current one.
	 */
	public static function needs_upgrade(): bool {
		return version_compare(
			get_option( 'neural_forms_db_version', '0' ),
			NEURAL_FORMS_DB_VERSION,
			'<'
		);
	}

	/**
	 * Runs create_tables() only when the DB version is behind.
	 * Called on every plugins_loaded so column additions are applied
	 * automatically on existing installs without a deactivate/reactivate.
	 *
	 * dbDelta() is safe to call repeatedly - it only applies changes.
	 */
	public static function maybe_upgrade(): void {
		if ( self::needs_upgrade() ) {
			self::create_tables();

			// 1.1.0+ slug uniqueness repair. Existing installs may
			// have accumulated duplicate form slugs before the
			// hardened generate_unique_slug() guards landed in
			// create/update. Repair is idempotent: a clean table is a
			// no-op. Wrapped in a class_exists guard so this method
			// never fatals during a partial-load upgrade where the
			// forms autoloader hasn't run yet.
			if ( class_exists( '\\NeuralForms\\Forms\\FormRepository' ) ) {
				try {
					\NeuralForms\Forms\FormRepository::repair_duplicate_slugs();
				} catch ( \Throwable $e ) {
					// Don't break the upgrade if repair throws — the
					// schema work has already succeeded.
					unset( $e );
				}
			}

			// 1.1.3: Privacy mode was a no-op before this version —
			// entries were stored WITH ip_address + user_agent even when
			// the toggle was on. If the site has it on now, that data
			// was stored against what the site told its visitors, so
			// blank it once. Sites with the toggle off are untouched.
			// Reads the option directly so this never depends on the
			// Config class being loaded mid-upgrade.
			try {
				$opts = get_option( 'neural_forms_settings', array() );
				if ( is_array( $opts ) && ! empty( $opts['privacy_mode'] ) ) {
					self::scrub_visitor_metadata();
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}
	}

	/**
	 * Blank ip_address + user_agent on every stored entry.
	 *
	 * Used by the 1.1.3 upgrade when Privacy mode is on. Idempotent.
	 *
	 * @return int  Rows updated (0 when nothing had metadata).
	 */
	public static function scrub_visitor_metadata(): int {
		global $wpdb;
		$table = $wpdb->prefix . 'neural_forms_entries';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET ip_address = NULL, user_agent = NULL WHERE ip_address IS NOT NULL OR user_agent IS NOT NULL',
				$table
			)
		);
		return is_int( $updated ) ? $updated : 0;
	}

	// Table definitions

	private static function sql_forms( string $charset ): string {
		global $wpdb;
		$table = $wpdb->prefix . 'neural_forms_forms';

		return "CREATE TABLE {$table} (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  name varchar(255) NOT NULL,
  slug varchar(255) NOT NULL,
  description text DEFAULT NULL,
  form_schema longtext NOT NULL,
  settings text DEFAULT NULL,
  status varchar(20) NOT NULL DEFAULT 'draft',
  created_by bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY slug (slug),
  KEY status (status)
) {$charset};";
	}

	private static function sql_entries( string $charset ): string {
		global $wpdb;
		$table = $wpdb->prefix . 'neural_forms_entries';

		return "CREATE TABLE {$table} (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  form_id bigint(20) UNSIGNED NOT NULL,
  form_version varchar(32) DEFAULT NULL,
  data longtext NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'new',
  ip_address varchar(45) DEFAULT NULL,
  user_agent varchar(500) DEFAULT NULL,
  page_url text DEFAULT NULL,
  is_starred tinyint(1) NOT NULL DEFAULT 0,
  needs_reply tinyint(1) NOT NULL DEFAULT 0,
  ai_status varchar(20) NOT NULL DEFAULT 'none',
  ai_score tinyint(3) UNSIGNED DEFAULT NULL,
  ai_summary text DEFAULT NULL,
  ai_category varchar(100) DEFAULT NULL,
  ai_priority varchar(10) DEFAULT NULL,
  ai_reply_draft text DEFAULT NULL,
  ai_provider varchar(50) DEFAULT NULL,
  ai_model varchar(100) DEFAULT NULL,
  ai_is_spam tinyint(1) NOT NULL DEFAULT 0,
  ai_spam_confidence int(3) NOT NULL DEFAULT 0,
  ai_spam_reason text DEFAULT NULL,
  ai_auto_spam_applied tinyint(1) NOT NULL DEFAULT 0,
  ai_error_reason text DEFAULT NULL,
  ai_tier varchar(16) NOT NULL DEFAULT 'fast',
  ai_selected_response_id bigint(20) UNSIGNED DEFAULT NULL,
  submitted_at datetime NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY form_id (form_id),
  KEY status (status),
  KEY is_starred (is_starred),
  KEY ai_status (ai_status)
) {$charset};";
	}

	/**
	 * Draft history for AI responses on an entry - fuels the carousel UI
	 * (prev/next) so merchants can flip between reanalyze/regenerate/polish
	 * outputs before picking one to use as the canonical reply.
	 *
	 * Retention rules (enforced by AIResponseRepository + WP-Cron):
	 *   - per-entry cap: at most 10 non-finalized drafts. Oldest evicted.
	 *   - age cap: drafts older than 30 days are purged nightly.
	 *   - the draft pointed at by entries.ai_selected_response_id is
	 *     exempt from both caps (preserved forever).
	 *
	 * `kind` narrows the source endpoint: analyze | reanalyze | regenerate
	 * | polish. `tier_used` is what the API routed to (post plan clamp).
	 * `tokens_used` is the sum of input+output tokens per provider reply.
	 */
	private static function sql_ai_responses( string $charset ): string {
		global $wpdb;
		$table = $wpdb->prefix . 'neural_forms_entry_ai_responses';

		return "CREATE TABLE {$table} (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  entry_id bigint(20) UNSIGNED NOT NULL,
  kind varchar(20) NOT NULL,
  tier_used varchar(16) NOT NULL DEFAULT 'fast',
  provider varchar(40) DEFAULT NULL,
  model varchar(120) DEFAULT NULL,
  summary text DEFAULT NULL,
  category varchar(100) DEFAULT NULL,
  priority varchar(10) DEFAULT NULL,
  reply_draft text DEFAULT NULL,
  polished_reply text DEFAULT NULL,
  is_spam tinyint(1) NOT NULL DEFAULT 0,
  spam_confidence int(3) NOT NULL DEFAULT 0,
  spam_reason text DEFAULT NULL,
  score tinyint(3) UNSIGNED DEFAULT NULL,
  tokens_used int(11) NOT NULL DEFAULT 0,
  finalized tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY entry_id (entry_id),
  KEY finalized (finalized),
  KEY created_at (created_at)
) {$charset};";
	}

	private static function sql_logs( string $charset ): string {
		global $wpdb;
		$table = $wpdb->prefix . 'neural_forms_logs';

		return "CREATE TABLE {$table} (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  entry_id bigint(20) UNSIGNED DEFAULT NULL,
  event_type varchar(100) NOT NULL,
  payload longtext DEFAULT NULL,
  status varchar(20) NOT NULL DEFAULT 'info',
  message text DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY entry_id (entry_id),
  KEY event_type (event_type)
) {$charset};";
	}

	private static function sql_queue( string $charset ): string {
		global $wpdb;
		$table = $wpdb->prefix . 'neural_forms_queue';

		return "CREATE TABLE {$table} (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  job_type varchar(100) NOT NULL,
  payload text NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'pending',
  attempts tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  scheduled_at datetime NOT NULL,
  processed_at datetime DEFAULT NULL,
  error text DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY status (status),
  KEY scheduled_at (scheduled_at)
) {$charset};";
	}

	private static function sql_entry_messages( string $charset ): string {
		global $wpdb;
		$table = $wpdb->prefix . 'neural_forms_entry_messages';

		return "CREATE TABLE {$table} (
  id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  entry_id bigint(20) UNSIGNED NOT NULL,
  type varchar(20) NOT NULL DEFAULT 'outgoing',
  sender varchar(255) DEFAULT NULL,
  recipient varchar(255) DEFAULT NULL,
  subject varchar(500) DEFAULT NULL,
  body text NOT NULL,
  meta longtext DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY entry_id (entry_id),
  KEY type (type)
) {$charset};";
	}
}
