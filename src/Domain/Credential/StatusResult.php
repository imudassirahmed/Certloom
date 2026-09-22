<?php
namespace Certloom\Domain\Credential;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Immutable structured output of the Core Status Service.
 */
final class StatusResult {
	/** @var bool */
	private $valid;
	/** @var string */
	private $code;
	/** @var string */
	private $reason_code;
	/** @var string */
	private $issuance_state;
	/** @var string */
	private $administrative_state;
	/** @var string */
	private $temporal_state;
	/** @var string */
	private $compliance_state;
	/** @var string[] */
	private $contributors;

	/**
	 * @param bool     $valid                Whether the credential is publicly valid now.
	 * @param string   $code                 Stable effective-status machine code.
	 * @param string   $reason_code          Stable primary reason code.
	 * @param string   $issuance_state       Stored issuance state.
	 * @param string   $administrative_state Stored administrative state.
	 * @param string   $temporal_state       Derived temporal state.
	 * @param string   $compliance_state     Derived/current compliance state.
	 * @param string[] $contributors         Additional invalidating/current factors.
	 */
	public function __construct( $valid, $code, $reason_code, $issuance_state, $administrative_state, $temporal_state, $compliance_state, array $contributors = array() ) {
		$this->valid                = (bool) $valid;
		$this->code                 = (string) $code;
		$this->reason_code          = (string) $reason_code;
		$this->issuance_state       = (string) $issuance_state;
		$this->administrative_state = (string) $administrative_state;
		$this->temporal_state       = (string) $temporal_state;
		$this->compliance_state     = (string) $compliance_state;
		$this->contributors         = array_values( array_unique( array_map( 'strval', $contributors ) ) );
	}

	public function is_valid() { return $this->valid; }
	public function code() { return $this->code; }
	public function reason_code() { return $this->reason_code; }
	public function issuance_state() { return $this->issuance_state; }
	public function administrative_state() { return $this->administrative_state; }
	public function temporal_state() { return $this->temporal_state; }
	public function compliance_state() { return $this->compliance_state; }
	public function contributors() { return $this->contributors; }

	/**
	 * Stable transport shape for REST/presentation adapters.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array() {
		return array(
			'valid'                => $this->valid,
			'code'                 => $this->code,
			'reason_code'          => $this->reason_code,
			'issuance_state'       => $this->issuance_state,
			'administrative_state' => $this->administrative_state,
			'temporal_state'       => $this->temporal_state,
			'compliance_state'     => $this->compliance_state,
			'contributors'         => $this->contributors,
		);
	}
}
