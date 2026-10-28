<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
//
// Direct $wpdb calls against wp_neural_forms_* tables only. Object caching
// is skipped intentionally - these reads back the admin UI which expects
// fresh data after every status / reply / AI mutation.
namespace NeuralForms\Rest\Endpoints;

use NeuralForms\Storage\EntryRepository;
use NeuralForms\Storage\AIResponseRepository;
use NeuralForms\Forms\FormRepository;
use NeuralForms\AI\AIRepository;
use NeuralForms\AI\AIQueueProcessor;
use NeuralForms\AI\Exceptions\CloudPlanException;
use NeuralForms\Core\Capabilities;
use NeuralForms\Core\Config;
use NeuralForms\Plan\PlanManager;
use WP_REST_Request;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * REST routes for reading form entries (admin-only).
 *
 * Base: /wp-json/neural-forms/v1/entries
 *
 * GET  /entries           '->-> paginated list for a form (data field omitted)
 * GET  /entries/{id}      '->-> full single entry including decoded data field
 *
 * Both routes require the neural_forms_manage_forms capability.
 *
 * The list route intentionally omits the `data` JSON column:
 *   - The data blob can be large (many fields, textarea content).
 *   - The list view only needs metadata to render rows.
 *   - The detail route fetches the full entry on demand.
 *
 * Error codes:
 *   missing_form_id  400  form_id param absent or not a positive integer.
 *   entry_not_found  404  Entry ID does not exist.
 *   permission_denied 403 Capability check failed.
 */
class EntriesEndpoint {

	public function register_routes(): void {
		$ns = \NeuralForms\Rest\RestAPI::NAMESPACE;

		// List - optional form_id and/or view query param.
		register_rest_route(
			$ns,
			'/entries',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_entries' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => $this->list_args(),
				),
			)
		);

		// Counts - registered BEFORE /entries/(?P<id>...) so 'counts' is not
		// interpreted as a numeric ID by WordPress's route resolver.
		register_rest_route(
			$ns,
			'/entries/counts',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_counts' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'form_id' => array(
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// Bulk actions.
		register_rest_route(
			$ns,
			'/entries/bulk',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'bulk_action' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => $this->bulk_args(),
			)
		);

		// AI rerun actions - registered before /entries/{id} so the action
		// suffixes are not swallowed by the generic numeric-ID pattern.
		register_rest_route(
			$ns,
			'/entries/(?P<id>[\d]+)/reanalyze',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'reanalyze_entry' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			$ns,
			'/entries/(?P<id>[\d]+)/regenerate-reply',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'regenerate_reply' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			$ns,
			'/entries/(?P<id>[\d]+)/polish-reply',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'polish_reply' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'text' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_textarea_field',
						'validate_callback' => static function ( $value ): bool {
							return trim( (string) $value ) !== '';
						},
					),
				),
			)
		);

		// AI response draft carousel.
		register_rest_route(
			$ns,
			'/entries/(?P<id>[\d]+)/ai-responses',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_ai_responses' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			$ns,
			'/entries/(?P<id>[\d]+)/ai-responses/(?P<draft_id>[\d]+)/finalize',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'finalize_ai_response' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Detail - returns full entry including the data field.
		register_rest_route(
			$ns,
			'/entries/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_entry' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'update_entry' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => $this->patch_args(),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_entry' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);
	}

	// Handlers

	/**
	 * GET /entries
	 *
	 * Returns a paginated list of entries. Accepts either:
	 *   - form_id (scope to one form)
	 *   - view    (system folder: inbox | unread | starred | needs_reply | spam | trash)
	 *   - both    (view scoped to a form)
	 *
	 * At least one of form_id or view must be present.
	 * The `data` field is stripped; a `preview` of the first 3 fields is added.
	 *
	 * @param WP_REST_Request $request
	 * @return \WP_REST_Response|WP_Error
	 */
	public function get_entries( WP_REST_Request $request ) {
		$form_id = (int) $request->get_param( 'form_id' );
		$view    = (string) $request->get_param( 'view' );
		// 0.2.0 smart folders. `folder` is exclusive with `form_id`
		// (a single-form view always wins). Allowed values:
		// polls         → entries from forms detected as poll
		// quizzes       → entries from forms detected as quiz OR mixed
		// normal_inbox  → All Forms → Inbox view excluding poll/quiz/mixed
		$folder = (string) $request->get_param( 'folder' );

		// Require at least one scoping parameter so we never return the entire
		// unfiltered entries table. A non-empty search term counts as a filter.
		$search = trim( (string) $request->get_param( 'search' ) );
		if ( $form_id < 1 && $view === '' && $search === '' && $folder === '' ) {
			return new WP_Error(
				'missing_filter',
				__( 'A valid form_id, view, folder, or search term is required.', 'neural-forms-lite' ),
				array( 'status' => 400 )
			);
		}

		$list_args = array(
			'view'       => $view,
			'page'       => (int) $request->get_param( 'page' ),
			'per_page'   => (int) $request->get_param( 'per_page' ),
			'status'     => $request->get_param( 'status' ),
			'search'     => $search,
			'sort_order' => (string) $request->get_param( 'sort_order' ),
		);

		// Resolve folder → form_ids / exclude_form_ids using the
		// shared FormTypeDetector via FormRepository::get_form_ids_by_detected_type.
		// folder is ignored when form_id is set (single-form view wins).
		//
		// Auto-exclusion of poll/quiz/mixed at All Forms scope is
		// EXPLICIT, not implicit. The rich admin bundle has no
		// Polls/Quizzes top-level folders yet, so silently filtering
		// those entries out of the default Inbox would hide them
		// completely. Clients that want the filtered inbox must ask
		// for it via folder=normal_inbox; the new per-screen entries
		// bundle does this when enabled.
		if ( $form_id < 1 && $folder !== '' ) {
			switch ( $folder ) {
				case 'polls':
					// Polls folder: poll-only forms. Mixed routes to
					// quizzes per the brief's mixed→quiz rule.
					$list_args['form_ids'] = \NeuralForms\Forms\FormRepository::get_form_ids_by_detected_type( array( 'poll' ) );
					break;
				case 'quizzes':
					// Quizzes folder: quiz + mixed forms.
					$list_args['form_ids'] = \NeuralForms\Forms\FormRepository::get_form_ids_by_detected_type( array( 'quiz', 'mixed' ) );
					break;
				case 'normal_inbox':
					// All Forms → Inbox (split-bundle UI): exclude
					// poll/quiz/mixed so the normal contact/lead
					// workflow stays uncluttered.
					$list_args['exclude_form_ids'] = \NeuralForms\Forms\FormRepository::get_form_ids_by_detected_type( array( 'poll', 'quiz', 'mixed' ) );
					break;
			}
		}

		$result = EntryRepository::list( $form_id, $list_args );

		$items = array_map(
			static function ( array $entry ): array {
				$entry['preview'] = array_slice( $entry['data'] ?? array(), 0, 3, true );
				unset( $entry['data'] );
				// Suppress verbose spam reason in list payloads - available in single GET.
				unset( $entry['ai_spam_reason'] );
				return $entry;
			},
			$result['items']
		);

		$response = rest_ensure_response( $items );
		$response->header( 'X-Total-Count', $result['total'] );

		return $response;
	}

	/**
	 * GET /entries/counts
	 *
	 * Returns entry counts for each system view. Pass form_id to scope.
	 *
	 * @param WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function get_counts( WP_REST_Request $request ) {
		$form_id = (int) $request->get_param( 'form_id' );

		// Default per-view counts: NO auto-exclusion. The rich admin
		// bundle's All Forms → Inbox shows every entry regardless of
		// detected form type, so the badges next to Inbox / Unread /
		// Starred / etc. must match. Clients that want the filtered
		// counts (split-bundle UI) ask for them via folder=normal_inbox
		// against /entries directly.
		$counts = EntryRepository::count_by_view( $form_id > 0 ? $form_id : null );

		// Surface Polls / Quizzes smart-folder totals at All Forms
		// scope so the future split UI's sidebar can render its badges
		// without a second roundtrip. Harmless for the rich UI which
		// ignores these keys.
		if ( $form_id < 1 ) {
			$smart             = EntryRepository::count_smart_folders();
			$counts['polls']   = (int) ( $smart['polls'] ?? 0 );
			$counts['quizzes'] = (int) ( $smart['quizzes'] ?? 0 );
		}

		return rest_ensure_response( $counts );
	}

	/**
	 * POST /entries/bulk
	 *
	 * Apply a single action to multiple entries.
	 *
	 * @param WP_REST_Request $request
	 * @return \WP_REST_Response|WP_Error
	 */
	public function bulk_action( WP_REST_Request $request ) {
		// Scalars only: intval( array ) is 1, so a nested array would act on
		// entry #1. Deduplicate and cap the batch.
		$ids    = array_values( array_unique( array_filter( array_map( 'intval', array_filter( (array) $request->get_param( 'ids' ), 'is_scalar' ) ), static function ( $id ) { return $id > 0; } ) ) );
		$action = (string) $request->get_param( 'action' );
		if ( count( $ids ) > 500 ) {
			return new WP_Error( 'too_many_ids', __( 'Select at most 500 entries per bulk action.', 'neural-forms-lite' ), array( 'status' => 400 ) );
		}

		// Permanent delete is handled separately - it is a hard DELETE, not a
		// field update, so it bypasses the $field_map / bulk_update path.
		if ( $action === 'delete' ) {
			$deleted = EntryRepository::bulk_delete( $ids );
			return rest_ensure_response( array( 'deleted' => $deleted ) );
		}

		$field_map = array(
			'mark_read'   => array( 'status' => 'read' ),
			'mark_unread' => array( 'status' => 'new' ),
			'star'        => array( 'is_starred' => 1 ),
			'unstar'      => array( 'is_starred' => 0 ),
			'spam'        => array( 'status' => 'spam' ),
			'trash'       => array( 'status' => 'trash' ),
			'restore'     => array( 'status' => 'read' ),
		);

		if ( ! isset( $field_map[ $action ] ) ) {
			return new WP_Error(
				'invalid_bulk_action',
				__( 'Unknown bulk action.', 'neural-forms-lite' ),
				array( 'status' => 400 )
			);
		}

		$updated = EntryRepository::bulk_update( $ids, $field_map[ $action ] );

		return rest_ensure_response( array( 'updated' => $updated ) );
	}

	/**
	 * GET /entries/{id}
	 *
	 * Returns the full entry row including the decoded `data` field.
	 *
	 * form_name note:
	 *   EntryRepository::get() does not JOIN neural_forms_forms, so form_name is
	 *   always null here. This is intentional - the label "Deleted form" or any
	 *   other human fallback belongs in the React UI, not in storage or API logic.
	 *
	 *   If the detail view needs form_name, the preferred approaches are:
	 *     a) React already has it from the list row that opened the detail view
	 *        and can pass it down without an extra API call.
	 *     b) Add a FormRepository::find( $entry['form_id'] ) lookup here and
	 *        merge name into the response - acceptable since detail is one row,
	 *        not a paginated list.
	 *   Either way, nothing changes in the repository layer.
	 *
	 * @param WP_REST_Request $request
	 * @return \WP_REST_Response|WP_Error
	 */
	public function get_entry( WP_REST_Request $request ) {
		$id    = (int) $request['id'];
		$entry = EntryRepository::get( $id );

		if ( ! $entry ) {
			return new WP_Error(
				'entry_not_found',
				__( 'Entry not found.', 'neural-forms-lite' ),
				array( 'status' => 404 )
			);
		}

		// Embed the draft carousel inline so EntryDetail can render without
		// a follow-up request. For spam entries the carousel is hidden in
		// the UI, but we still ship the data so admins can recover history
		// if they flip an entry out of spam.
		$drafts                = AIResponseRepository::list_for_entry( $id );
		$entry['ai_responses'] = array_map( array( $this, 'shape_ai_response' ), $drafts );

		// Detail UI renders the form name as the card title. EntryRepository::get()
		// does not JOIN the forms table, so resolve the name here — one extra row
		// fetch per detail open is cheap and removes the React-side dependency on
		// the in-memory forms list (which may not be loaded on a deep-link visit).
		if ( empty( $entry['form_name'] ) && ! empty( $entry['form_id'] ) ) {
			$form = FormRepository::find( (int) $entry['form_id'] );
			if ( $form && ! empty( $form['name'] ) ) {
				$entry['form_name'] = (string) $form['name'];
			}
		}

		return rest_ensure_response( $entry );
	}

	/**
	 * POST /entries/{id}/reanalyze
	 *
	 * Queue a fresh full AI analysis for an existing entry.
	 *
	 * Guards:
	 *  * 503 if AI is not configured (feature off or API key absent).
	 *  * 404 if the entry does not exist.
	 *  * 409 if a job is already running (ai_status queued or processing).
	 *
	 * @param WP_REST_Request $request
	 * @return \WP_REST_Response|WP_Error
	 */
	public function reanalyze_entry( WP_REST_Request $request ) {
		$guard = $this->check_ai_configured();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$id    = (int) $request['id'];
		$entry = EntryRepository::get( $id );

		if ( ! $entry ) {
			return new WP_Error( 'entry_not_found', __( 'Entry not found.', 'neural-forms-lite' ), array( 'status' => 404 ) );
		}

		// Plugin-level gate: Pro is required only when the entry already
		// carries an analysis — the initial "Analyze with Neural AI" run
		// is a Lite feature, so a Pro-less install can trigger it once.
		// Cloud plan + credits guards below still apply either way.
		$has_prev_analysis = ! empty( $entry['ai_summary'] )
			|| ! empty( $entry['ai_category'] )
			|| ! empty( $entry['ai_priority'] );
		if ( $has_prev_analysis && ! Capabilities::can( Capabilities::AI_REANALYZE ) ) {
			return Capabilities::locked_error( Capabilities::AI_REANALYZE );
		}

		// Cloud plan check only applies to re-runs — the first analyze is
		// a Lite-plan feature and the cloud accepts it against a fresh key.
		if ( $has_prev_analysis ) {
			$plan_guard = $this->check_plan_feature( 'ai_reanalyze' );
			if ( is_wp_error( $plan_guard ) ) {
				return $plan_guard;
			}
		}

		$credits_guard = $this->check_ai_credits();
		if ( is_wp_error( $credits_guard ) ) {
			return $credits_guard;
		}

		$busy = $this->check_ai_not_busy( $entry );
		if ( is_wp_error( $busy ) ) {
			return $busy;
		}

		// Run inline - admin-initiated, so we block on the Cloud call and
		// return the fresh analysis in the same response. Removes the 5s
		// scheduled-event delay + WP-Cron pickup latency + UI poll cycle.
		try {
			$ai = AIQueueProcessor::run_inline_analyze( $id, (int) ( $entry['form_id'] ?? 0 ) );
			return rest_ensure_response(
				array(
					'entry_id' => $id,
					'ai'       => $ai,
					// Cloud's user-facing usage block — frontend renders
					// "Credits used: X · Remaining: Y" below the action.
					'usage'    => AIQueueProcessor::last_inline_usage(),
				)
			);
		} catch ( CloudPlanException $e ) {
			return new WP_Error(
				'feature_locked',
				$e->getMessage(),
				array(
					'status'  => 402,
					'feature' => $e->get_feature(),
					'quota'   => $e->get_quota(),
				)
			);
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'ai_analyze_failed',
				$e->getMessage() ?: __( 'Neural AI analysis failed. Please try again.', 'neural-forms-lite' ),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * POST /entries/{id}/regenerate-reply
	 *
	 * Queue a reply-only AI job for an existing entry.
	 * Does not overwrite summary, category, priority, or spam fields.
	 *
	 * Guards:
	 *  * 503 if AI is not configured.
	 *  * 404 if the entry does not exist.
	 *  * 409 if a job is already running.
	 *
	 * @param WP_REST_Request $request
	 * @return \WP_REST_Response|WP_Error
	 */
	public function regenerate_reply( WP_REST_Request $request ) {
		$guard = $this->check_ai_configured();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$id    = (int) $request['id'];
		$entry = EntryRepository::get( $id );

		if ( ! $entry ) {
			return new WP_Error( 'entry_not_found', __( 'Entry not found.', 'neural-forms-lite' ), array( 'status' => 404 ) );
		}

		// Pro is required only when a reply draft already exists — the
		// first "Generate with Neural AI" reply is a Lite feature. Cloud
		// plan + credits guards below still apply either way.
		$has_prev_reply = ! empty( $entry['ai_reply_draft'] );
		if ( $has_prev_reply && ! Capabilities::can( Capabilities::AI_REANALYZE ) ) {
			return Capabilities::locked_error( Capabilities::AI_REANALYZE );
		}

		// Cloud plan check only applies to reply re-runs — the first draft
		// is a Lite-plan feature.
		if ( $has_prev_reply ) {
			$plan_guard = $this->check_plan_feature( 'ai_reply_regenerate' );
			if ( is_wp_error( $plan_guard ) ) {
				return $plan_guard;
			}
		}

		$credits_guard = $this->check_ai_credits();
		if ( is_wp_error( $credits_guard ) ) {
			return $credits_guard;
		}

		$busy = $this->check_ai_not_busy( $entry );
		if ( is_wp_error( $busy ) ) {
			return $busy;
		}

		try {
			$reply = AIQueueProcessor::run_inline_regenerate_reply( $id, (int) ( $entry['form_id'] ?? 0 ) );
			return rest_ensure_response(
				array(
					'entry_id'    => $id,
					'reply_draft' => $reply,
					'usage'       => AIQueueProcessor::last_inline_usage(),
				)
			);
		} catch ( CloudPlanException $e ) {
			return new WP_Error(
				'feature_locked',
				$e->getMessage(),
				array(
					'status'  => 402,
					'feature' => $e->get_feature(),
					'quota'   => $e->get_quota(),
				)
			);
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'ai_regenerate_failed',
				$e->getMessage() ?: __( 'Reply regeneration failed. Please try again.', 'neural-forms-lite' ),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * POST /entries/{id}/polish-reply
	 *
	 * Synchronously polishes a user-provided reply draft with AI.
	 * The entry is loaded only to verify access; its data is NOT sent to the
	 * model - the polish prompt works entirely on the user's text.
	 *
	 * Guards:
	 *  * 503 if AI is not configured.
	 *  * 404 if the entry does not exist.
	 *  * 400 if text param is empty (enforced by arg validation above).
	 *
	 * Returns { polished_reply: string } synchronously.
	 * Nothing is written to the database.
	 *
	 * @param WP_REST_Request $request
	 * @return \WP_REST_Response|WP_Error
	 */
	public function polish_reply( WP_REST_Request $request ) {
		$guard = $this->check_ai_configured();
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		if ( ! Capabilities::can( Capabilities::AI_REFINE_REPLY ) ) {
			return Capabilities::locked_error( Capabilities::AI_REFINE_REPLY );
		}

		$plan_guard = $this->check_plan_feature( 'ai_reply_polish' );
		if ( is_wp_error( $plan_guard ) ) {
			return $plan_guard;
		}

		$credits_guard = $this->check_ai_credits();
		if ( is_wp_error( $credits_guard ) ) {
			return $credits_guard;
		}

		$id    = (int) $request['id'];
		$entry = EntryRepository::get( $id );

		if ( ! $entry ) {
			return new WP_Error( 'entry_not_found', __( 'Entry not found.', 'neural-forms-lite' ), array( 'status' => 404 ) );
		}

		$text    = trim( (string) $request->get_param( 'text' ) );
		$form_id = (int) ( $entry['form_id'] ?? 0 );
		$form    = $form_id > 0 ? ( \NeuralForms\Forms\FormRepository::find( $form_id ) ?? array() ) : array();

		try {
			$provider = \NeuralForms\AI\AiManager::resolve();
			$result   = $provider->polish_reply( $text, $entry, $form );
			// Mirror the cloud's authoritative `credits_remaining` into the
			// local cache so the Settings credits widget refreshes immediately.
			PlanManager::apply_usage( is_array( $result['usage'] ?? null ) ? $result['usage'] : array() );
		} catch ( CloudPlanException $e ) {
			return $this->plan_exception_to_error( $e, 'ai_reply_polish' );
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'ai_polish_failed',
				__( 'Neural AI could not refine the reply. Please try again.', 'neural-forms-lite' ),
				array( 'status' => 502 )
			);
		}

		// Persist the polished output as a carousel draft so the merchant
		// can flip back to it. Polish never auto-finalizes, never mutates
		// entries.ai_reply_draft, and leaves summary/category alone - the
		// draft is context-only until explicitly picked.
		AIResponseRepository::insert(
			$id,
			AIResponseRepository::KIND_POLISH,
			array(
				'tier_used'      => $result['tier_used'] ?? 'fast',
				'polished_reply' => $result['polished_reply'] ?? '',
				'reply_draft'    => $text,
				'ai_provider'    => $provider->provider_id(),
			)
		);

		return rest_ensure_response(
			array(
				'polished_reply' => $result['polished_reply'],
				'usage'          => is_array( $result['usage'] ?? null ) ? $result['usage'] : array(),
			)
		);
	}

	/**
	 * GET /entries/{id}/ai-responses
	 *
	 * Return every draft recorded for the carousel, oldest first. Includes
	 * the entry's current selected/finalized draft id so the frontend can
	 * highlight it without a second call. Empty array for entries with no
	 * drafts yet (pre-upgrade data).
	 */
	public function list_ai_responses( WP_REST_Request $request ) {
		$id    = (int) $request['id'];
		$entry = EntryRepository::get( $id );

		if ( ! $entry ) {
			return new WP_Error(
				'entry_not_found',
				__( 'Entry not found.', 'neural-forms-lite' ),
				array( 'status' => 404 )
			);
		}

		$drafts = AIResponseRepository::list_for_entry( $id );

		return rest_ensure_response(
			array(
				'drafts'      => array_map( array( $this, 'shape_ai_response' ), $drafts ),
				'selected_id' => isset( $entry['ai_selected_response_id'] )
					? (int) $entry['ai_selected_response_id']
					: null,
			)
		);
	}

	/**
	 * POST /entries/{id}/ai-responses/{draft_id}/finalize
	 *
	 * Pin a draft as the canonical reply for the entry - "Use this reply".
	 * Swaps entries.ai_selected_response_id and flips the draft's finalized
	 * flag so the retention cap/TTL skips it forever. Writes the draft's
	 * reply_draft (or polished_reply for polish kind) into ai_reply_draft so
	 * the reply composer picks it up. summary/category/priority are promoted
	 * back onto the entry for analyze/reanalyze kinds only - regenerate and
	 * polish carry no new classification.
	 */
	public function finalize_ai_response( WP_REST_Request $request ) {
		$id       = (int) $request['id'];
		$draft_id = (int) $request['draft_id'];
		$entry    = EntryRepository::get( $id );

		if ( ! $entry ) {
			return new WP_Error(
				'entry_not_found',
				__( 'Entry not found.', 'neural-forms-lite' ),
				array( 'status' => 404 )
			);
		}

		$draft = AIResponseRepository::get( $draft_id );
		if ( ! $draft || (int) $draft['entry_id'] !== $id ) {
			return new WP_Error(
				'draft_not_found',
				__( 'Draft not found for this entry.', 'neural-forms-lite' ),
				array( 'status' => 404 )
			);
		}

		$ok = AIResponseRepository::finalize( $id, $draft_id );
		if ( ! $ok ) {
			return new WP_Error(
				'finalize_failed',
				__( 'Could not finalize draft.', 'neural-forms-lite' ),
				array( 'status' => 500 )
			);
		}

		// Copy the draft's reply text onto the entry so the composer shows
		// the chosen reply. Polish kind has no reply_draft - use polished_reply.
		$kind       = (string) $draft['kind'];
		$reply_text = $kind === AIResponseRepository::KIND_POLISH
			? (string) ( $draft['polished_reply'] ?? '' )
			: (string) ( $draft['reply_draft'] ?? '' );

		$tier = isset( $draft['tier_used'] ) ? (string) $draft['tier_used'] : null;
		AIRepository::update_reply_draft( $id, $reply_text, $tier );

		// For analyze/reanalyze drafts the classification fields are part of
		// the draft - promote them back onto the entry so toggling between
		// drafts swaps the whole analysis card, not just the reply text.
		if ( in_array( $kind, array( AIResponseRepository::KIND_ANALYZE, AIResponseRepository::KIND_REANALYZE ), true ) ) {
			AIRepository::save_analysis(
				$id,
				array(
					'summary'         => $draft['summary'] ?? '',
					'category'        => $draft['category'] ?? 'general',
					'priority'        => $draft['priority'] ?? 'medium',
					'reply_draft'     => $reply_text,
					'score'           => isset( $draft['score'] ) ? (int) $draft['score'] : 50,
					'ai_provider'     => $draft['provider'] ?? '',
					'ai_model'        => $draft['model'] ?? '',
					'is_spam'         => (bool) ( $draft['is_spam'] ?? false ),
					'spam_confidence' => (int) ( $draft['spam_confidence'] ?? 0 ),
					'spam_reason'     => (string) ( $draft['spam_reason'] ?? '' ),
					'tier_used'       => $tier,
				)
			);
		}

		$drafts = AIResponseRepository::list_for_entry( $id );

		return rest_ensure_response(
			array(
				'entry_id'    => $id,
				'selected_id' => $draft_id,
				'drafts'      => array_map( array( $this, 'shape_ai_response' ), $drafts ),
			)
		);
	}

	/**
	 * Narrow an AIResponseRepository row to the fields the admin UI renders.
	 * Large/unused columns are dropped so payloads stay tight in a long
	 * carousel (up to 10 non-finalized + N finalized).
	 */
	private function shape_ai_response( array $row ): array {
		return array(
			'id'             => (int) $row['id'],
			'kind'           => (string) $row['kind'],
			'tier_used'      => (string) ( $row['tier_used'] ?? 'fast' ),
			'provider'       => (string) ( $row['provider'] ?? '' ),
			'model'          => (string) ( $row['model'] ?? '' ),
			'summary'        => (string) ( $row['summary'] ?? '' ),
			'category'       => (string) ( $row['category'] ?? '' ),
			'priority'       => (string) ( $row['priority'] ?? '' ),
			'reply_draft'    => (string) ( $row['reply_draft'] ?? '' ),
			'polished_reply' => (string) ( $row['polished_reply'] ?? '' ),
			'score'          => null !== $row['score'] ? (int) $row['score'] : null,
			'finalized'      => (int) ( $row['finalized'] ?? 0 ) === 1,
			'created_at'     => (string) ( $row['created_at'] ?? '' ),
		);
	}

	/**
	 * PATCH /entries/{id}
	 *
	 * Updates mutable fields on a single entry.
	 * Accepted fields: status, is_starred, needs_reply.
	 * At least one must be present (enforced in patch_args).
	 *
	 * Returns the full updated { id, status, is_starred, needs_reply } payload.
	 *
	 * @param WP_REST_Request $request
	 * @return \WP_REST_Response|WP_Error
	 */
	public function update_entry( WP_REST_Request $request ) {
		$id = (int) $request['id'];

		$existing = EntryRepository::get( $id );
		if ( ! $existing ) {
			return new WP_Error(
				'entry_not_found',
				__( 'Entry not found.', 'neural-forms-lite' ),
				array( 'status' => 404 )
			);
		}

		$fields = array();

		if ( null !== $request->get_param( 'status' ) ) {
			$fields['status'] = $request->get_param( 'status' );
		}
		if ( null !== $request->get_param( 'is_starred' ) ) {
			$fields['is_starred'] = $request->get_param( 'is_starred' );
		}
		if ( null !== $request->get_param( 'needs_reply' ) ) {
			$fields['needs_reply'] = $request->get_param( 'needs_reply' );
		}

		// When an entry is being restored out of spam, clear the auto-spam flag
		// so the "AI filtered" badge disappears and does not re-appear erroneously.
		if (
			isset( $fields['status'] ) &&
			in_array( $fields['status'], array( 'read', 'new' ), true ) &&
			( $existing['status'] ?? '' ) === 'spam'
		) {
			$fields['ai_auto_spam_applied'] = 0;
		}

		if ( empty( $fields ) ) {
			return new WP_Error(
				'no_fields',
				__( 'No updatable fields provided.', 'neural-forms-lite' ),
				array( 'status' => 400 )
			);
		}

		$ok = EntryRepository::update( $id, $fields );

		if ( ! $ok ) {
			return new WP_Error(
				'entry_update_failed',
				__( 'Could not update entry.', 'neural-forms-lite' ),
				array( 'status' => 500 )
			);
		}

		// Return fresh row so the client applies confirmed values.
		$entry = EntryRepository::get( $id );

		return rest_ensure_response(
			array(
				'id'          => $entry['id'],
				'status'      => $entry['status'],
				'is_starred'  => $entry['is_starred'],
				'needs_reply' => $entry['needs_reply'],
			)
		);
	}

	/**
	 * Kept for backward compatibility - delegates to update_entry.
	 */
	public function update_entry_status( WP_REST_Request $request ) {
		return $this->update_entry( $request );
	}

	/**
	 * DELETE /entries/{id}
	 *
	 * Permanently removes an entry from the database.
	 * Only available to admins; intended for use from the Trash view.
	 *
	 * Returns { deleted: true, id: <id> } on success.
	 *
	 * @param WP_REST_Request $request
	 * @return \WP_REST_Response|WP_Error
	 */
	public function delete_entry( WP_REST_Request $request ) {
		$id = (int) $request['id'];

		if ( ! EntryRepository::get( $id ) ) {
			return new WP_Error(
				'entry_not_found',
				__( 'Entry not found.', 'neural-forms-lite' ),
				array( 'status' => 404 )
			);
		}

		$ok = EntryRepository::delete( $id );

		if ( ! $ok ) {
			return new WP_Error(
				'entry_delete_failed',
				__( 'Could not delete the entry.', 'neural-forms-lite' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'deleted' => true,
				'id'      => $id,
			)
		);
	}

	// AI rerun helpers

	/**
	 * Return WP_Error 403 when the plan does not grant the given capability,
	 * true otherwise. PlanManager::can() returns true for unknown capabilities
	 * (fail-open), so this only rejects when the Cloud has explicitly locked
	 * the feature.
	 */
	private function check_plan_feature( string $feature ) {
		if ( PlanManager::can( $feature ) ) {
			return true;
		}

		$plan = PlanManager::get_plan();

		return new WP_Error(
			'feature_locked',
			__( 'This feature is not available on your current plan.', 'neural-forms-lite' ),
			array(
				'status'      => 403,
				'feature'     => $feature,
				'quota'       => $plan['quota'],
				'upgrade_url' => $plan['upgrade_url'],
			)
		);
	}

	/**
	 * Convert a CloudPlanException into a WP_Error carrying the same structured
	 * data the frontend uses to populate the upgrade modal. Also updates the
	 * local PlanManager cache so subsequent requests see the lock immediately.
	 */
	private function plan_exception_to_error( CloudPlanException $e, string $fallback_feature ) {
		$feature = $e->get_feature() !== '' ? $e->get_feature() : $fallback_feature;

		if ( $e->get_error_code() === 'feature_locked' && $feature !== '' ) {
			PlanManager::mark_feature_locked( $feature );
		}
		if ( $e->get_error_code() === 'quota_exceeded' ) {
			PlanManager::mark_quota_exceeded( $e->get_quota() );
		}

		$data            = $e->to_error_data();
		$data['feature'] = $feature;

		return new WP_Error( $e->get_error_code(), $e->getMessage(), $data );
	}

	/**
	 * Return WP_Error 402 when the cached cloud credit balance is at or
	 * below zero, true otherwise. Soft pre-flight — the cloud is still the
	 * source of truth and will hard-enforce via QuotaGuard, but checking
	 * locally first avoids burning a network round-trip on calls we
	 * already know will be rejected.
	 *
	 * Fails open if the plan cache has never been synced (`fetched_at = 0`)
	 * so a fresh install isn't false-positive blocked.
	 */
	private function check_ai_credits() {
		if ( ! PlanManager::is_credits_exhausted() ) {
			return true;
		}
		$plan = PlanManager::get_plan();
		return new WP_Error(
			'credits_exhausted',
			__( 'You have used all your Neural AI credits. Buy more to continue.', 'neural-forms-lite' ),
			array(
				'status'      => 402,
				'balance'     => $plan['balance'],
				'upgrade_url' => $plan['upgrade_url'] !== '' ? $plan['upgrade_url'] : PlanManager::DEFAULT_UPGRADE_URL,
			)
		);
	}

	/**
	 * Return WP_Error 503 when AI is not configured, true otherwise.
	 *
	 * Requires ai_enabled, cloud_ai_enabled, and a non-empty cloud_api_key.
	 */
	private function check_ai_configured() {
		if (
			! Config::get( 'ai_enabled' ) ||
			! Config::get( 'cloud_ai_enabled' ) ||
			Config::get( 'cloud_api_key' ) === ''
		) {
			return new WP_Error(
				'ai_not_configured',
				__( 'Neural AI is not configured. Enable Neural AI and add an API key in Settings.', 'neural-forms-lite' ),
				array( 'status' => 503 )
			);
		}

		return true;
	}

	/**
	 * Seconds a queue row may sit in 'processing' before it is treated as
	 * orphaned (PHP timeout / fatal mid-run). Mirrors the 2-minute window
	 * AIQueueProcessor::run() uses to re-claim stuck rows.
	 */
	const AI_JOB_ACTIVE_WINDOW = 120;

	/**
	 * Return WP_Error 409 only when a queue worker is genuinely mid-run on
	 * this entry (status 'processing', claimed within AI_JOB_ACTIVE_WINDOW).
	 *
	 * A job that is merely 'pending' means WP-Cron has not picked it up yet
	 * (low-traffic site, DISABLE_WP_CRON without a system cron, object-cache
	 * staleness…). The admin clicking "Analyze with AI" is the explicit
	 * request to run it now, so those rows — and 'processing' rows older
	 * than the window — are marked 'superseded' and the inline analysis
	 * proceeds. Before 1.1.4 this returned 409 "already running" and the
	 * entry stayed "Queued" forever on sites whose cron never fired.
	 *
	 * Safety valve: if the entry claims to be busy but no job row exists at
	 * all, the entry is orphaned — reset it so the user isn't locked out.
	 */
	private function check_ai_not_busy( array $entry ) {
		$busy = array( 'queued', 'processing' );
		if ( ! in_array( $entry['ai_status'] ?? '', $busy, true ) ) {
			return true;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'neural_forms_queue';
		// LIKE pattern interpolates (int) $entry['id'] - integer cast first, then
		// passed via the %s placeholder which adds quoting. Safe from injection.
		$jobs = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, status, scheduled_at FROM %i
				 WHERE payload LIKE %s
				   AND status IN ( \'pending\', \'processing\' )
				   AND attempts < %d',
				$table,
				'%"entry_id":' . (int) $entry['id'] . '%',
				\NeuralForms\AI\AIQueueProcessor::MAX_ATTEMPTS
			),
			ARRAY_A
		);
		$jobs = is_array( $jobs ) ? $jobs : array();

		$decision = self::classify_active_jobs( $jobs, time() );

		if ( $decision['running'] ) {
			return new WP_Error(
				'ai_already_processing',
				__( 'Neural AI is processing this entry right now. Please wait a moment and refresh.', 'neural-forms-lite' ),
				array( 'status' => 409 )
			);
		}

		foreach ( $decision['supersede'] as $job_id ) {
			$wpdb->update(
				$table,
				array(
					'status'       => 'superseded',
					'processed_at' => current_time( 'mysql', true ),
					'error'        => 'Superseded by a manual "Analyze with AI" run.',
				),
				array( 'id' => (int) $job_id ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);
		}

		if ( empty( $jobs ) ) {
			// No job row at all - entry is orphaned. Reset so the user can retry.
			\NeuralForms\AI\AIRepository::update_status( (int) $entry['id'], 'failed' );
		}

		return true;
	}

	/**
	 * Decide what to do with the queue rows found for an entry.
	 *
	 * @param array<int, array{id:int|string,status:string,scheduled_at:string}> $jobs
	 * @param int $now Unix timestamp.
	 * @return array{running: bool, supersede: int[]}
	 */
	private static function classify_active_jobs( array $jobs, int $now ): array {
		$running   = false;
		$supersede = array();
		foreach ( $jobs as $job ) {
			$status = (string) ( $job['status'] ?? '' );
			if ( 'processing' === $status ) {
				$claimed = strtotime( (string) ( $job['scheduled_at'] ?? '' ) . ' UTC' );
				if ( false !== $claimed && ( $now - $claimed ) < self::AI_JOB_ACTIVE_WINDOW ) {
					$running = true;
					continue;
				}
			}
			$supersede[] = (int) $job['id'];
		}
		return array(
			'running'   => $running,
			'supersede' => $running ? array() : $supersede,
		);
	}

	// Permission

	/**
	 * Both routes are admin-only.
	 * Returns a WP_Error (not false) so the response uses our error code
	 * instead of WordPress's default 'rest_forbidden'.
	 *
	 * @return true|WP_Error
	 */
	public function check_permission() {
		if ( current_user_can( NEURAL_FORMS_CAP_MANAGE_FORMS ) ) {
			return true;
		}

		return new WP_Error(
			'permission_denied',
			__( 'You do not have permission to view entries.', 'neural-forms-lite' ),
			array( 'status' => 403 )
		);
	}

	// Arg definitions

	/**
	 * Query parameter definitions for GET /entries.
	 *
	 * form_id is intentionally not marked required here - a missing or zero
	 * value is caught manually in the handler so we can return missing_form_id
	 * rather than WordPress's generic rest_missing_callback_param error.
	 *
	 * per_page is validated to [1, 100]. Values outside that range are rejected
	 * with a 400 before reaching the handler. The repository also enforces the
	 * cap internally as a defence-in-depth measure.
	 *
	 * status is validated against EntryRepository::VALID_STATUSES so adding a
	 * new status to the repository automatically allows it here - no duplicate
	 * list to maintain.
	 */
	private function list_args(): array {
		return array(
			'form_id'    => array(
				'default'           => 0,
				'sanitize_callback' => 'absint',
			),
			'view'       => array(
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => static function ( $value ): bool {
					return $value === ''
						|| in_array( $value, EntryRepository::VALID_VIEWS, true );
				},
			),
			'page'       => array(
				'default'           => 1,
				'sanitize_callback' => 'absint',
			),
			'per_page'   => array(
				'default'           => 20,
				'sanitize_callback' => 'absint',
				'validate_callback' => static function ( $value ): bool {
					$v = (int) $value;
					return $v >= 1 && $v <= 100;
				},
			),
			'status'     => array(
				'default'           => 'any',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => static function ( $value ): bool {
					return $value === 'any'
						|| in_array( $value, EntryRepository::VALID_STATUSES, true );
				},
			),
			'search'     => array(
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'sort_order' => array(
				'default'           => 'desc',
				'sanitize_callback' => static function ( $v ): string {
					$v = strtolower( trim( (string) $v ) );
					return in_array( $v, array( 'asc', 'desc' ), true ) ? $v : 'desc';
				},
			),
		);
	}

	private function patch_args(): array {
		return array(
			'status'      => array(
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => static function ( $value ): bool {
					return in_array( $value, EntryRepository::VALID_STATUSES, true );
				},
			),
			'is_starred'  => array(
				'required'          => false,
				'sanitize_callback' => 'absint',
				'validate_callback' => static function ( $value ): bool {
					return in_array( (int) $value, array( 0, 1 ), true );
				},
			),
			'needs_reply' => array(
				'required'          => false,
				'sanitize_callback' => 'absint',
				'validate_callback' => static function ( $value ): bool {
					return in_array( (int) $value, array( 0, 1 ), true );
				},
			),
		);
	}

	private function bulk_args(): array {
		$valid_actions = array( 'mark_read', 'mark_unread', 'star', 'unstar', 'spam', 'trash', 'restore', 'delete' );
		return array(
			'ids'    => array(
				'required'          => true,
				'validate_callback' => static function ( $value ): bool {
					return is_array( $value ) && ! empty( $value );
				},
			),
			'action' => array(
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => static function ( $value ) use ( $valid_actions ): bool {
					return in_array( $value, $valid_actions, true );
				},
			),
		);
	}
}
