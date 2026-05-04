<?php
namespace NeuralForms\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Auto-detects a form's "type" from its schema.
 *
 * Display-only: nothing in the engine branches on the detected type —
 * it exists so the Forms list can show a quick at-a-glance badge
 * (Simple / Poll / Quiz / Survey / Mixed) without each row having to
 * walk the schema again. Actual feature availability is still driven
 * by the fields a form contains.
 *
 * Detection rules (first-match wins):
 *   1. quiz_question + poll        -> mixed
 *   2. quiz_question               -> quiz
 *   3. poll                        -> poll
 *   4. rating + feedback-text      -> survey
 *   5. otherwise                   -> simple
 *
 * "feedback-text" = a textarea, OR a text/textarea field whose id or
 * label hints at open-ended commentary (contains "feedback", "comment",
 * "review", "thoughts", "experience"). The heuristic is intentionally
 * forgiving: a survey is just "rating + somewhere to elaborate".
 */
class FormTypeDetector {

	public const TYPE_SIMPLE = 'simple';
	public const TYPE_POLL   = 'poll';
	public const TYPE_QUIZ   = 'quiz';
	public const TYPE_SURVEY = 'survey';
	public const TYPE_MIXED  = 'mixed';

	/**
	 * Pure detection: takes a (possibly already-decoded) form_schema array,
	 * returns one of the TYPE_* constants. Null / malformed input -> simple.
	 *
	 * @param array|null $schema
	 * @return string
	 */
	public static function detect( $schema ): string {
		if ( ! is_array( $schema ) ) {
			return self::TYPE_SIMPLE;
		}
		$fields = is_array( $schema['fields'] ?? null ) ? $schema['fields'] : [];
		if ( empty( $fields ) ) {
			return self::TYPE_SIMPLE;
		}

		$has_poll      = false;
		$has_quiz      = false;
		$has_rating    = false;
		$has_feedback  = false;

		foreach ( SchemaHelper::flatten_fields( $fields ) as $field ) {
			$type = (string) ( $field['type'] ?? '' );
			if ( $type === 'poll' )          { $has_poll = true;   continue; }
			if ( $type === 'quiz_question' ) { $has_quiz = true;   continue; }
			if ( $type === 'rating' )        { $has_rating = true; continue; }
			if ( self::is_feedback_field( $field ) ) {
				$has_feedback = true;
			}
		}

		if ( $has_quiz && $has_poll ) return self::TYPE_MIXED;
		if ( $has_quiz )              return self::TYPE_QUIZ;
		if ( $has_poll )              return self::TYPE_POLL;
		if ( $has_rating && $has_feedback ) return self::TYPE_SURVEY;
		return self::TYPE_SIMPLE;
	}

	/**
	 * Localized label for a detected type. Unknown/garbage falls back
	 * to "Simple" rather than rendering an empty pill.
	 */
	public static function label( string $type ): string {
		switch ( $type ) {
			case self::TYPE_POLL:   return __( 'Poll',   'neural-forms-lite' );
			case self::TYPE_QUIZ:   return __( 'Quiz',   'neural-forms-lite' );
			case self::TYPE_SURVEY: return __( 'Survey', 'neural-forms-lite' );
			case self::TYPE_MIXED:  return __( 'Mixed',  'neural-forms-lite' );
			case self::TYPE_SIMPLE:
			default:                return __( 'Simple', 'neural-forms-lite' );
		}
	}

	/**
	 * True when a field looks like an open-ended commentary input. Any
	 * textarea qualifies; for plain text fields we look for a hint in
	 * the id or label so a one-line "First name" doesn't accidentally
	 * make a contact form look like a survey.
	 */
	private static function is_feedback_field( array $field ): bool {
		$type = (string) ( $field['type'] ?? '' );
		if ( $type === 'textarea' ) {
			return true;
		}
		if ( $type !== 'text' ) {
			return false;
		}
		$haystack = strtolower(
			( (string) ( $field['id']    ?? '' ) ) . ' ' .
			( (string) ( $field['label'] ?? '' ) )
		);
		if ( $haystack === ' ' ) return false;
		foreach ( [ 'feedback', 'comment', 'review', 'thought', 'experience' ] as $needle ) {
			if ( strpos( $haystack, $needle ) !== false ) {
				return true;
			}
		}
		return false;
	}
}
