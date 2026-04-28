<?php
namespace NeuralForms\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for all registered field types.
 *
 * Each entry defines:
 *   label          Human-readable name shown in the form builder UI.
 *   supports       Optional properties this field type accepts.
 *                  Used by the builder to show/hide config panels.
 *
 * Adding a new field type in the future = one new entry in $types.
 * Nothing else needs to change — validation, the builder, and any
 * future export/import all read from here.
 *
 * Usage:
 *   FieldRegistry::is_registered('email');   // true
 *   FieldRegistry::get_types();              // ['text','email',...]
 *   FieldRegistry::get('select');            // ['label'=>'Select', ...]
 */
class FieldRegistry {

    /**
     * Core field type definitions.
     *
     * 'supports' lists optional properties the field builder may send.
     * The validator uses this to reject unknown properties (Step 9).
     */
    private static array $types = [
        'text'     => [
            'label'    => 'Text',
            'supports' => [ 'label', 'show_label', 'placeholder', 'required', 'default_value', 'min_length', 'max_length' ],
        ],
        'email'    => [
            'label'    => 'Email',
            'supports' => [ 'label', 'show_label', 'placeholder', 'required', 'default_value' ],
        ],
        'textarea' => [
            'label'    => 'Textarea',
            'supports' => [ 'label', 'show_label', 'placeholder', 'required', 'default_value', 'min_length', 'max_length', 'rows' ],
        ],
        'select'   => [
            'label'    => 'Select',
            'supports' => [ 'label', 'show_label', 'required', 'options', 'default_value' ],
        ],
        'checkbox' => [
            'label'    => 'Checkbox',
            'supports' => [ 'label', 'required', 'default_value' ],
        ],
        'number'   => [
            'label'    => 'Number',
            'supports' => [ 'label', 'show_label', 'placeholder', 'required', 'default_value', 'min', 'max', 'step' ],
        ],
        'phone'    => [
            'label'    => 'Phone',
            'supports' => [ 'label', 'show_label', 'placeholder', 'required', 'default_value' ],
        ],
        'tel'      => [
            'label'    => 'Telephone',
            'supports' => [ 'label', 'show_label', 'placeholder', 'required', 'default_value' ],
        ],
        'url'      => [
            'label'    => 'URL',
            'supports' => [ 'label', 'show_label', 'placeholder', 'required', 'default_value' ],
        ],
        'date'     => [
            'label'    => 'Date',
            'supports' => [ 'label', 'show_label', 'required', 'default_value', 'min', 'max' ],
        ],
        'acceptance' => [
            'label'    => 'Acceptance',
            'supports' => [ 'label', 'required' ],
        ],
        'radio'    => [
            'label'    => 'Radio',
            'supports' => [ 'label', 'show_label', 'required', 'options', 'default_value' ],
        ],
        'checkboxes' => [
            'label'    => 'Checkboxes',
            'supports' => [ 'label', 'show_label', 'required', 'options', 'default_value' ],
        ],
        'hidden'   => [
            'label'    => 'Hidden',
            'supports' => [ 'label', 'default_value' ],
        ],
        'range'    => [
            'label'    => 'Range',
            'supports' => [ 'label', 'show_label', 'required', 'default_value', 'min', 'max', 'step' ],
        ],
        'file'     => [
            'label'    => 'File Upload',
            'supports' => [ 'label', 'show_label', 'required', 'allowed_types', 'max_file_size' ],
        ],
    ];

    // -------------------------------------------------------------------------
    // Lookup
    // -------------------------------------------------------------------------

    /**
     * True if the given type string is a registered field type.
     */
    public static function is_registered( string $type ): bool {
        return isset( self::$types[ $type ] );
    }

    /**
     * All registered type keys.
     * Used in validation error messages and the form builder type picker.
     *
     * @return string[]  e.g. ['text','email','textarea',...]
     */
    public static function get_types(): array {
        return array_keys( self::$types );
    }

    /**
     * Full definition for a single type, or null if not registered.
     *
     * @return array|null  e.g. ['label' => 'Email', 'supports' => [...]]
     */
    public static function get( string $type ): ?array {
        return self::$types[ $type ] ?? null;
    }

    /**
     * All type definitions — used by the REST API to expose the type list
     * to the form builder UI (Step 7).
     *
     * @return array  Keyed by type slug.
     */
    public static function all(): array {
        return self::$types;
    }
}
