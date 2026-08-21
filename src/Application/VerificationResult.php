<?php
namespace Certloom\Application;

use Certloom\Domain\Credential\StatusResult;

/**
 * Structured, presentation-neutral result of a public credential-ID lookup.
 */
final class VerificationResult {
	/** @var bool */
	private $found;
	/** @var string */
	private $public_id;
	/** @var string */
	private $reason_code;
	/** @var array<string,mixed>|null */
	private $credential;
	/** @var StatusResult|null */
	private $status;

	/**
	 * @param bool                     $found       Whether a credential was resolved.
	 * @param string                   $public_id   Normalized public ID when available.
	 * @param string                   $reason_code Stable lookup result code.
	 * @param array<string,mixed>|null $credential Joined Series/current-Version read model.
	 * @param StatusResult|null        $status      Authoritative Core status result.
	 */
	public function __construct( $found, $public_id, $reason_code, array $credential = null, StatusResult $status = null ) {
		$this->found       = (bool) $found;
		$this->public_id   = (string) $public_id;
		$this->reason_code = (string) $reason_code;
		$this->credential  = $credential;
		$this->status      = $status;
	}

	public function found() { return $this->found; }
	public function public_id() { return $this->public_id; }
	public function reason_code() { return $this->reason_code; }
	public function credential() { return $this->credential; }
	public function status() { return $this->status; }

	/** @return array<string,mixed> */
	public function to_array() {
		return array(
			'found'       => $this->found,
			'public_id'   => $this->public_id,
			'reason_code' => $this->reason_code,
			'status'      => $this->status ? $this->status->to_array() : null,
		);
	}
}
