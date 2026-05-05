<?php
namespace NeuralForms\AI\Exceptions;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by NeuralFormsCloudProvider when the cloud's SafetyGate
 * blocks an AI request, or when the moderation provider is
 * unavailable and the gate fails closed.
 *
 * Carries the structured `safety` and `usage` blocks from the cloud
 * so callers can:
 *   - mark the entry's ai_status = 'blocked_by_safety' (NOT 'failed');
 *   - render an inline message that respects credits_deducted=false;
 *   - skip retry logic — a blocked input will block again.
 *
 * Wire codes:
 *   blocked_by_safety       — content flagged unsafe (HTTP 403)
 *   ai_safety_unavailable   — moderation outage, fail-closed (HTTP 503)
 */
class CloudSafetyException extends \RuntimeException {

	private string $error_code;
	private ?string $category;
	private string $action;
	private array  $usage;
	private int    $http_status;

	public function __construct(
		string  $message,
		string  $error_code,
		int     $http_status,
		?string $category = null,
		string  $action   = 'block',
		array   $usage    = []
	) {
		parent::__construct( $message );
		$this->error_code  = $error_code;
		$this->category    = $category;
		$this->action      = $action;
		$this->usage       = $usage;
		$this->http_status = $http_status;
	}

	public function get_error_code(): string  { return $this->error_code; }
	public function get_category(): ?string   { return $this->category; }
	public function get_action(): string      { return $this->action; }
	public function get_usage(): array        { return $this->usage; }
	public function get_http_status(): int    { return $this->http_status; }

	public function is_unavailable(): bool {
		return $this->error_code === 'ai_safety_unavailable';
	}

	public function to_error_data(): array {
		return [
			'status'   => $this->http_status,
			'safety'   => [
				'category' => $this->category,
				'action'   => $this->action,
			],
			'usage'    => $this->usage,
		];
	}
}
