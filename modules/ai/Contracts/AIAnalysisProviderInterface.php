<?php
namespace NeuralForms\AI\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Higher-level provider contract for structured AI analysis.
 *
 * Unlike AIProviderInterface (which is a raw prompt-in / text-out contract),
 * this interface works in terms of entry and form data. The implementation
 * decides how to turn that data into a provider call - AIQueueProcessor never
 * needs to know whether it is talking to the cloud or to a local model.
 *
 * All three methods must return a normalized array that passes directly into
 * AIRepository without any further parsing by the caller.
 */
interface AIAnalysisProviderInterface {

	/**
	 * Run a full analysis of a form submission.
	 *
	 * @param array $entry  Decoded entry row from EntryRepository::get().
	 * @param array $form   Decoded form row from FormRepository::find().
	 * @return array {
	 *   summary         string,
	 *   category        string  (one of AIRepository::VALID_CATEGORIES),
	 *   priority        string  (one of AIRepository::VALID_PRIORITIES),
	 *   reply_draft     string,
	 *   score           int     0-100,
	 *   is_spam         bool,
	 *   spam_confidence int     0-100,
	 *   spam_reason     string,
	 *   ai_provider     string,
	 *   ai_model        string,
	 * }
	 * @throws \RuntimeException On any transport or API failure.
	 */
	public function analyze( array $entry, array $form ): array;

	/**
	 * Regenerate only the reply draft for an existing entry.
	 *
	 * Must never overwrite summary, category, priority, or spam fields.
	 *
	 * @param array $entry  Decoded entry row from EntryRepository::get().
	 * @param array $form   Decoded form row from FormRepository::find().
	 * @return array {
	 *   reply_draft string,
	 * }
	 * @throws \RuntimeException On any transport or API failure.
	 */
	public function regenerate_reply( array $entry, array $form ): array;

	/**
	 * Polish a reply draft supplied by the user.
	 *
	 * Must preserve the original intent and length. Returns an improved version
	 * of the text without adding new information.
	 *
	 * @param string $draft       The user's current reply draft.
	 * @param array  $entry       Decoded entry row (context only - not re-analysed).
	 * @param array  $form        Decoded form row (context only).
	 * @return array {
	 *   polished_reply string,
	 * }
	 * @throws \RuntimeException On any transport or API failure.
	 */
	public function polish_reply( string $draft, array $entry, array $form, array $options = array() ): array;

	/**
	 * Short identifier for this provider implementation.
	 * Stored in ai_provider column for auditability.
	 * Examples: 'neural-forms-cloud', 'openai'
	 */
	public function provider_id(): string;
}
