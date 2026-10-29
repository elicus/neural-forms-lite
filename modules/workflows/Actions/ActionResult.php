<?php
namespace NeuralForms\Workflows\Actions;

defined( 'ABSPATH' ) || exit;

/**
 * Outcome of one workflow action attempt.
 *
 *   done       effect applied (or provider accepted the request)
 *   retry      transient failure — try again with backoff
 *   failed     configuration / permission / data problem — never retried
 *   uncertain  the effect MAY have happened (timeout after send) — shown
 *              with a manual Retry, never retried automatically
 *   skipped    nothing to do (e.g. no submitter email) — not an error
 */
final class ActionResult {

	const DONE      = 'done';
	const RETRY     = 'retry';
	const FAILED    = 'failed';
	const UNCERTAIN = 'uncertain';
	const SKIPPED   = 'skipped';

	/** @var string */
	public $status;
	/** @var string */
	public $message;
	/** @var string|null */
	public $external_ref;

	private function __construct( string $status, string $message, ?string $external_ref = null ) {
		$this->status       = $status;
		$this->message      = $message;
		$this->external_ref = $external_ref;
	}

	public static function done( string $message = '', ?string $external_ref = null ): self {
		return new self( self::DONE, $message, $external_ref );
	}

	public static function retry( string $message ): self {
		return new self( self::RETRY, $message );
	}

	public static function failed( string $message ): self {
		return new self( self::FAILED, $message );
	}

	public static function uncertain( string $message, ?string $external_ref = null ): self {
		return new self( self::UNCERTAIN, $message, $external_ref );
	}

	public static function skipped( string $message ): self {
		return new self( self::SKIPPED, $message );
	}

	public function is_ok(): bool {
		return $this->status === self::DONE || $this->status === self::SKIPPED;
	}
}
