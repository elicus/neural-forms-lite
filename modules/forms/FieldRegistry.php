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
 * Nothing else needs to change - validation, the builder, and any
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
            'supports' => [ 'label', 'show_label', 'placeholder', 'required', 'default_value', 'min_length', 'max_length', 'width' ],
        ],
        'email'    => [
            'label'    => 'Email',
            'supports' => [ 'label', 'show_label', 'placeholder', 'required', 'default_value', 'width' ],
        ],
        'textarea' => [
            'label'    => 'Textarea',
            'supports' => [ 'label', 'show_label', 'placeholder', 'required', 'default_value', 'min_length', 'max_length', 'rows', 'width' ],
        ],
        'select'   => [
            'label'    => 'Select',
            'supports' => [ 'label', 'show_label', 'required', 'options', 'default_value', 'width' ],
        ],
        'checkbox' => [
            'label'    => 'Checkbox',
            'supports' => [ 'label', 'required', 'default_value', 'width' ],
        ],
        'number'   => [
            'label'    => 'Number',
            'supports' => [ 'label', 'show_label', 'placeholder', 'required', 'default_value', 'min', 'max', 'step', 'width' ],
        ],
        'phone'    => [
            'label'    => 'Phone',
            'supports' => [ 'label', 'show_label', 'placeholder', 'required', 'default_value', 'width' ],
        ],
        'tel'      => [
            'label'    => 'Telephone',
            'supports' => [ 'label', 'show_label', 'placeholder', 'required', 'default_value', 'width' ],
        ],
        'url'      => [
            'label'    => 'URL',
            'supports' => [ 'label', 'show_label', 'placeholder', 'required', 'default_value', 'width' ],
        ],
        'date'     => [
            'label'    => 'Date',
            'supports' => [ 'label', 'show_label', 'required', 'default_value', 'min', 'max', 'width' ],
        ],
        'acceptance' => [
            'label'    => 'Acceptance',
            'supports' => [ 'label', 'required', 'width' ],
        ],
        'radio'    => [
            'label'    => 'Radio',
            'supports' => [ 'label', 'show_label', 'required', 'options', 'default_value', 'width' ],
        ],
        'checkboxes' => [
            'label'    => 'Checkboxes',
            'supports' => [ 'label', 'show_label', 'required', 'options', 'default_value', 'width' ],
        ],
        // Hidden fields are not rendered, so width does not apply.
        'hidden'   => [
            'label'    => 'Hidden',
            'supports' => [ 'label', 'default_value' ],
        ],
        'range'    => [
            'label'    => 'Range',
            'supports' => [ 'label', 'show_label', 'required', 'default_value', 'min', 'max', 'step', 'width' ],
        ],
        'rating'   => [
            'label'    => 'Rating',
            'supports' => [ 'label', 'show_label', 'required', 'stars', 'width' ],
        ],
        // Poll: a real input field that submits like radio. Carries a
        // nested `settings` object (results_visibility, chart_type,
        // allow_multiple [reserved], randomize_options) — unique to
        // poll for now; SchemaHelper::normalize_poll_settings() owns
        // its shape.
        'poll'     => [
            'label'    => 'Poll',
            'supports' => [ 'label', 'show_label', 'required', 'options', 'settings', 'width' ],
        ],
        // Quiz Question: knowledge-quiz field (single-choice). Each
        // option carries an extra `is_correct` boolean. Nested
        // `settings` object controls scoring + after-submit feedback.
        // Score is computed server-side; the frontend NEVER sees the
        // correct answer until submission completes.
        'quiz_question' => [
            'label'    => 'Quiz Question',
            'supports' => [ 'label', 'show_label', 'required', 'options', 'settings', 'width' ],
        ],
        'file'     => [
            'label'    => 'File Upload',
            'supports' => [ 'label', 'show_label', 'required', 'allowed_types', 'max_file_size', 'width' ],
        ],
        // Matrix: survey-style row-by-column grid. Each row is a
        // question, each column is a possible answer. Nested `settings`
        // carries `selection_type` ('radio' = one column per row;
        // 'checkbox' = many columns per row) plus the row/column lists
        // (with `value` and optional `score` per column). Gated by
        // Capabilities::FIELD_MATRIX so a Lite-only install sees Matrix
        // as a locked Pro feature in the builder palette. The type
        // stays REGISTERED even when locked — existing forms with
        // Matrix open without schema-validation errors.
        'matrix'   => [
            'label'    => 'Matrix',
            'supports' => [ 'label', 'show_label', 'required', 'help_text', 'css_class', 'settings', 'width' ],
        ],
        // Repeater: repeatable group of child fields. Nested `settings`
        // carries min_items / max_items / columns / add_button_label /
        // remove_button_label; nested `fields` carries the child-field
        // TEMPLATE (each rendered iteration on the frontend stamps these
        // templates with a per-index id prefix). Gated by
        // Capabilities::FIELD_REPEATER so a Lite-only install sees
        // Repeater as a locked Pro feature in the builder palette. The
        // type stays REGISTERED even when locked — existing forms with
        // a Repeater open without schema-validation errors. Nested
        // repeater / matrix / row / step / section / poll / quiz_question
        // are NOT allowed as children in v1; that whitelist is
        // enforced in FormValidator + the cloud SchemaNormalizer.
        'repeater' => [
            'label'    => 'Repeater',
            'supports' => [ 'label', 'show_label', 'required', 'help_text', 'css_class', 'settings', 'width', 'fields' ],
        ],
    ];

    /**
     * Allowed values for the optional `width` property.
     *
     * 'full' - full-row field (the only width prior to 1.1.0).
     * 'half' - half-row field. Two consecutive half-width fields render
     *          side-by-side on the frontend; a single trailing half renders
     *          at half width with empty space to its right.
     *
     * Hidden + honeypot fields ignore this property entirely.
     */
    const WIDTH_FULL = 'full';
    const WIDTH_HALF = 'half';
    const VALID_WIDTHS = [ self::WIDTH_FULL, self::WIDTH_HALF ];

    /**
     * Coerce an arbitrary width value to one of VALID_WIDTHS.
     * Used by FormRenderer to decide whether a field pairs into a row.
     */
    public static function normalize_width( $value ): string {
        $value = is_string( $value ) ? strtolower( trim( $value ) ) : '';
        return in_array( $value, self::VALID_WIDTHS, true ) ? $value : self::WIDTH_FULL;
    }

    // Lookup

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
     * All type definitions - used by the REST API to expose the type list
     * to the form builder UI (Step 7).
     *
     * @return array  Keyed by type slug.
     */
    public static function all(): array {
        return self::$types;
    }
}
