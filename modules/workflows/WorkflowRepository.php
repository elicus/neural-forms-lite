<?php
namespace NeuralForms\Workflows;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD for `neural_forms_workflows`.
 */
final class WorkflowRepository {

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'neural_forms_workflows';
	}

	/**
	 * @param array $def Validated WorkflowDefinition::normalize() output.
	 * @return int New id (0 on failure).
	 */
	public static function create( array $def ): int {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$ok  = $wpdb->insert(
			self::table(),
			array(
				'form_id'      => (int) $def['form_id'],
				'name'         => $def['name'],
				'description'  => $def['description'],
				'trigger_type' => $def['trigger'],
				'conditions'   => $def['conditions'] ? wp_json_encode( $def['conditions'] ) : null,
				'actions'      => wp_json_encode( $def['actions'] ),
				'settings'     => wp_json_encode( $def['settings'] ),
				'enabled'      => $def['enabled'] ? 1 : 0,
				'revision'     => 1,
				'created_by'   => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
				'created_at'   => $now,
				'updated_at'   => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s' )
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Replace the definition; bumps `revision` so queued runs keep their snapshot.
	 */
	public static function update( int $id, array $def ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET name = %s, description = %s, trigger_type = %s, conditions = %s, actions = %s, settings = %s, enabled = %d, revision = revision + 1, updated_at = %s WHERE id = %d',
				self::table(),
				$def['name'],
				$def['description'],
				$def['trigger'],
				$def['conditions'] ? wp_json_encode( $def['conditions'] ) : null,
				wp_json_encode( $def['actions'] ),
				wp_json_encode( $def['settings'] ),
				$def['enabled'] ? 1 : 0,
				current_time( 'mysql', true ),
				$id
			)
		);
		return false !== $result;
	}

	public static function set_enabled( int $id, bool $enabled ): bool {
		global $wpdb;
		$r = $wpdb->update( self::table(), array( 'enabled' => $enabled ? 1 : 0, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $id ), array( '%d', '%s' ), array( '%d' ) );
		return false !== $r;
	}

	public static function delete( int $id ): bool {
		global $wpdb;
		return false !== $wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );
	}

	public static function find( int $id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ), ARRAY_A );
		return $row ? self::decode( $row ) : null;
	}

	/** @return array[] All workflows of a form, newest first. */
	public static function list_for_form( int $form_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE form_id = %d ORDER BY id DESC', self::table(), $form_id ), ARRAY_A );
		return array_map( array( self::class, 'decode' ), (array) $rows );
	}

	/** @return array[] Enabled workflows of a form for one trigger, oldest first (stable execution order). */
	public static function list_enabled( int $form_id, string $trigger ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE form_id = %d AND trigger_type = %s AND enabled = 1 ORDER BY id ASC', self::table(), $form_id, $trigger ), ARRAY_A );
		return array_map( array( self::class, 'decode' ), (array) $rows );
	}

	/** True when at least one enabled workflow exists anywhere (cheap guard for hooks). */
	public static function any_enabled( string $trigger = '' ): bool {
		global $wpdb;
		if ( $trigger !== '' ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE enabled = 1 AND trigger_type = %s', self::table(), $trigger ) ) > 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE enabled = 1', self::table() ) ) > 0;
	}

	/**
	 * Row → definition array (WorkflowDefinition shape + id/timestamps).
	 */
	public static function decode( array $row ): array {
		$def = WorkflowDefinition::normalize(
			array(
				'id'          => $row['id'],
				'form_id'     => $row['form_id'],
				'name'        => $row['name'],
				'description' => $row['description'] ?? '',
				'enabled'     => $row['enabled'],
				'revision'    => $row['revision'],
				'trigger'     => $row['trigger_type'],
				'conditions'  => json_decode( (string) ( $row['conditions'] ?? '' ), true ),
				'actions'     => json_decode( (string) ( $row['actions'] ?? '[]' ), true ),
				'settings'    => json_decode( (string) ( $row['settings'] ?? '{}' ), true ),
			)
		);
		$def['created_at'] = (string) ( $row['created_at'] ?? '' );
		$def['updated_at'] = (string) ( $row['updated_at'] ?? '' );
		return $def;
	}
}
