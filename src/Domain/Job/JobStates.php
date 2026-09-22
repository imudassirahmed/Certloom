<?php
namespace Certloom\Domain\Job;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** Stable machine states for all resumable Certloom background work. */
final class JobStates {
	const QUEUED              = 'queued';
	const RUNNING             = 'running';
	const PAUSED              = 'paused';
	const SUCCEEDED           = 'succeeded';
	const PARTIALLY_SUCCEEDED = 'partially_succeeded';
	const FAILED              = 'failed';
	const CANCELLED           = 'cancelled';

	/** @return array<int,string> */
	public static function values() {
		return array(
			self::QUEUED,
			self::RUNNING,
			self::PAUSED,
			self::SUCCEEDED,
			self::PARTIALLY_SUCCEEDED,
			self::FAILED,
			self::CANCELLED,
		);
	}

	/** @return bool */
	public static function is_terminal( $state ) {
		return in_array( (string) $state, array( self::SUCCEEDED, self::PARTIALLY_SUCCEEDED, self::FAILED, self::CANCELLED ), true );
	}
}
