<?php
namespace Certloom\Domain\Job;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** Explicit transitions prevent job state from becoming another overloaded status field. */
final class JobStateMachine {
	/** @return bool */
	public function can_transition( $from, $to ) {
		$from = (string) $from;
		$to   = (string) $to;
		$allowed = array(
			JobStates::QUEUED => array( JobStates::RUNNING, JobStates::PAUSED, JobStates::CANCELLED ),
			JobStates::RUNNING => array( JobStates::QUEUED, JobStates::PAUSED, JobStates::SUCCEEDED, JobStates::PARTIALLY_SUCCEEDED, JobStates::FAILED, JobStates::CANCELLED ),
			JobStates::PAUSED => array( JobStates::QUEUED, JobStates::CANCELLED ),
			JobStates::FAILED => array( JobStates::QUEUED, JobStates::CANCELLED ),
			JobStates::SUCCEEDED => array(),
			JobStates::PARTIALLY_SUCCEEDED => array(),
			JobStates::CANCELLED => array(),
		);
		return isset( $allowed[ $from ] ) && in_array( $to, $allowed[ $from ], true );
	}

	/** @return true|\WP_Error */
	public function assert_transition( $from, $to ) {
		if ( ! in_array( (string) $from, JobStates::values(), true ) || ! in_array( (string) $to, JobStates::values(), true ) ) {
			return new \WP_Error( 'certloom_job_state_invalid', __( 'The Certloom job state is invalid.', 'certloom' ) );
		}
		if ( ! $this->can_transition( $from, $to ) ) {
			return new \WP_Error( 'certloom_job_transition_invalid', __( 'That Certloom job state transition is not allowed.', 'certloom' ) );
		}
		return true;
	}
}
