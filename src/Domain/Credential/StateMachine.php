<?php
namespace Certloom\Domain\Credential;

use DomainException;

/**
 * Explicit lifecycle transition rules. Presentation/controller code must call a
 * command/service using these rules rather than mutating state strings itself.
 */
final class StateMachine {
	/** @return array<string,string> */
	public function issue( $issuance_state, $administrative_state ) {
		$this->assert_pair( $issuance_state, $administrative_state );
		if ( States::ISSUANCE_DRAFT !== $issuance_state || States::ADMIN_ACTIVE !== $administrative_state ) {
			throw new DomainException( 'Only an active draft credential version can be issued.' );
		}
		return array( 'issuance_state' => States::ISSUANCE_ISSUED, 'administrative_state' => States::ADMIN_ACTIVE );
	}

	/** @return array<string,string> */
	public function suspend( $issuance_state, $administrative_state ) {
		$this->assert_pair( $issuance_state, $administrative_state );
		if ( States::ISSUANCE_ISSUED !== $issuance_state || States::ADMIN_ACTIVE !== $administrative_state ) {
			throw new DomainException( 'Only an active issued credential can be suspended.' );
		}
		return array( 'issuance_state' => $issuance_state, 'administrative_state' => States::ADMIN_SUSPENDED );
	}

	/** @return array<string,string> */
	public function revoke( $issuance_state, $administrative_state ) {
		$this->assert_pair( $issuance_state, $administrative_state );
		if ( States::ISSUANCE_ISSUED !== $issuance_state || States::ADMIN_REVOKED === $administrative_state ) {
			throw new DomainException( 'Only a non-revoked issued credential can be revoked.' );
		}
		return array( 'issuance_state' => $issuance_state, 'administrative_state' => States::ADMIN_REVOKED );
	}

	/** @return array<string,string> */
	public function reinstate( $issuance_state, $administrative_state ) {
		$this->assert_pair( $issuance_state, $administrative_state );
		if ( States::ISSUANCE_ISSUED !== $issuance_state || ! in_array( $administrative_state, array( States::ADMIN_SUSPENDED, States::ADMIN_REVOKED ), true ) ) {
			throw new DomainException( 'Only a suspended or revoked issued credential can be reinstated.' );
		}
		return array( 'issuance_state' => $issuance_state, 'administrative_state' => States::ADMIN_ACTIVE );
	}

	/** @return array<string,string> */
	public function void( $issuance_state, $administrative_state ) {
		$this->assert_pair( $issuance_state, $administrative_state );
		if ( States::ISSUANCE_VOIDED === $issuance_state ) {
			throw new DomainException( 'Credential version is already voided.' );
		}
		return array( 'issuance_state' => States::ISSUANCE_VOIDED, 'administrative_state' => $administrative_state );
	}

	/** @return void */
	private function assert_pair( $issuance_state, $administrative_state ) {
		if ( ! in_array( $issuance_state, States::issuance_values(), true ) ) {
			throw new DomainException( 'Unknown issuance state.' );
		}
		if ( ! in_array( $administrative_state, States::administrative_values(), true ) ) {
			throw new DomainException( 'Unknown administrative state.' );
		}
	}
}
