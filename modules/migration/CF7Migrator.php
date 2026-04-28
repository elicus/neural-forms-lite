<?php
namespace NeuralForms\Migration;

use NeuralForms\Forms\FormRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Orchestrates the migration of a CF7 form into Neural Forms.
 *
 * Two-step workflow:
 *   1. preview( $cf7_id ) — dry-run that shows what the import would produce.
 *   2. import( $cf7_id )  — creates the Neural Forms form row.
 *
 * All methods are static. The actual form persistence is delegated to
 * FormRepository::create().
 */
class CF7Migrator {

	/**
	 * Find Neural Forms forms that were previously imported from a given CF7 form.
	 *
	 * Searches the settings JSON column for matching import_meta.cf7_form_id.
	 *
	 * @param int $cf7_id  CF7 form post ID.
	 * @return array[] Each entry: { id, name, status, imported_at }
	 */
	public static function find_existing_imports( int $cf7_id ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'neural_forms_forms';

		// Use LIKE on the JSON settings column — simple, compatible with MySQL 5.7+.
		// The pattern matches "cf7_form_id": 123 (with or without spaces after colon).
		$pattern = '%"cf7_form_id":%' . $wpdb->esc_like( (string) $cf7_id ) . '%';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, name, status, settings FROM {$table} WHERE settings LIKE %s ORDER BY id DESC",
				$pattern
			),
			ARRAY_A
		);

		if ( ! $rows ) {
			return [];
		}

		$matches = [];

		// Verify the match is exact (LIKE could match partial numbers).
		foreach ( $rows as $row ) {
			$settings = json_decode( $row['settings'] ?? '{}', true );
			$stored_id = $settings['import_meta']['cf7_form_id'] ?? null;

			if ( (int) $stored_id === $cf7_id ) {
				$matches[] = [
					'id'          => (int) $row['id'],
					'name'        => $row['name'],
					'status'      => $row['status'],
					'imported_at' => $settings['import_meta']['imported_at'] ?? '',
				];
			}
		}

		return $matches;
	}

	/**
	 * Generate a migration preview without writing anything to the database.
	 *
	 * @param int $cf7_id  CF7 form post ID.
	 * @return array|null  Null if CF7 form not found.
	 */
	public static function preview( int $cf7_id ): ?array {
		if ( ! CF7Detector::is_cf7_active() ) {
			return null;
		}

		$cf7 = \WPCF7_ContactForm::get_instance( $cf7_id );
		if ( ! $cf7 ) {
			return null;
		}

		$tags   = CF7Detector::parse_tags( $cf7 );
		$result = CF7FieldMapper::map_form( $tags );

		$existing = self::find_existing_imports( $cf7_id );

		return [
			'cf7_id'           => $cf7_id,
			'cf7_title'        => $cf7->title(),
			'fields'           => $result['fields'],
			'field_count'      => count( $result['fields'] ),
			'skipped'          => $result['skipped'],
			'warnings'         => $result['warnings'],
			'can_import'       => count( $result['fields'] ) > 0,
			'already_imported' => ! empty( $existing ),
			'existing_imports' => $existing,
		];
	}

	/**
	 * Import a CF7 form into Neural Forms.
	 *
	 * Creates a new form row via FormRepository::create() with import
	 * metadata stored in the settings JSON column.
	 *
	 * @param int  $cf7_id  CF7 form post ID.
	 * @param bool $force   If true, proceed even if already imported.
	 * @return array|null  Null if CF7 form not found.
	 */
	public static function import( int $cf7_id, bool $force = false ): ?array {
		if ( ! CF7Detector::is_cf7_active() ) {
			return null;
		}

		$cf7 = \WPCF7_ContactForm::get_instance( $cf7_id );
		if ( ! $cf7 ) {
			return null;
		}

		// Block duplicate imports unless explicitly forced.
		$existing = self::find_existing_imports( $cf7_id );
		if ( ! empty( $existing ) && ! $force ) {
			return [
				'success'          => false,
				'form_id'          => null,
				'form_name'        => $cf7->title(),
				'field_count'      => 0,
				'skipped'          => [],
				'warnings'         => [],
				'duplicate'        => true,
				'existing_imports' => $existing,
			];
		}

		$tags   = CF7Detector::parse_tags( $cf7 );
		$result = CF7FieldMapper::map_form( $tags );

		if ( empty( $result['fields'] ) ) {
			return [
				'success'     => false,
				'form_id'     => null,
				'form_name'   => $cf7->title(),
				'field_count' => 0,
				'skipped'     => $result['skipped'],
				'warnings'    => array_merge(
					$result['warnings'],
					[ 'No mappable fields found — import aborted.' ]
				),
			];
		}

		$form_name = $cf7->title();

		// Build the form schema in the same shape the editor expects: { fields: [...] }.
		$fields = [];
		foreach ( $result['fields'] as $field_id => $field ) {
			$field['id'] = $field_id;
			$fields[] = $field;
		}
		$form_schema = [ 'fields' => $fields ];

		// Import metadata stored in settings.
		$settings = [
			'import_meta' => [
				'source'       => 'cf7',
				'cf7_form_id'  => $cf7_id,
				'cf7_title'    => $form_name,
				'imported_at'  => current_time( 'mysql' ),
				'imported_by'  => get_current_user_id(),
				'field_count'  => count( $fields ),
				'skipped'      => count( $result['skipped'] ),
			],
		];

		$new_id = FormRepository::create( [
			'name'        => $form_name,
			'form_schema' => $form_schema,
			'settings'    => $settings,
			'status'      => 'draft',
		] );

		return [
			'success'     => $new_id !== false,
			'form_id'     => $new_id ?: null,
			'form_name'   => $form_name,
			'field_count' => count( $fields ),
			'skipped'     => $result['skipped'],
			'warnings'    => $result['warnings'],
		];
	}
}
