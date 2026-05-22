<?php
namespace NeuralForms\AI\Exceptions;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by NeuralFormsCloudProvider when the Cloud API returns a plan-related
 * error (feature_locked or quota_exceeded).
 *
 * Carries the structured data the error contract defines so callers can react
 * without parsing exception messages:
 *
 *   code         string  One of: 'feature_locked' | 'quota_exceeded'.
 *   feature      string  For feature_locked: the capability key (e.g. 'ai_reply_polish').
 *   quota        array   For quota_exceeded: { used, limit, reset_at }.
 *   upgrade_url  string  Cloud-provided upgrade URL; may be empty.
 *   http_status  int     Original HTTP status (402/403/429).
 */
class CloudPlanException extends \RuntimeException {

	private string $error_code;
	private string $feature;
	private array $quota;
	private string $upgrade_url;
	private int $http_status;

	public function __construct(
		string $message,
		string $error_code,
		int $http_status,
		string $feature = '',
		array $quota = array(),
		string $upgrade_url = ''
	) {
		parent::__construct( $message );
		$this->error_code  = $error_code;
		$this->feature     = $feature;
		$this->quota       = $quota;
		$this->upgrade_url = $upgrade_url;
		$this->http_status = $http_status;
	}

	public function get_error_code(): string {
		return $this->error_code; }
	public function get_feature(): string {
		return $this->feature; }
	public function get_quota(): array {
		return $this->quota; }
	public function get_upgrade_url(): string {
		return $this->upgrade_url; }
	public function get_http_status(): int {
		return $this->http_status; }

	/**
	 * Structured payload for WP_Error `data` so the frontend receives the full
	 * context of the block (which feature, quota state, upgrade URL).
	 */
	public function to_error_data(): array {
		return array(
			'status'      => $this->http_status,
			'feature'     => $this->feature,
			'quota'       => $this->quota,
			'upgrade_url' => $this->upgrade_url,
		);
	}
}
