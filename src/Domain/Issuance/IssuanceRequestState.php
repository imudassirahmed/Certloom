<?php
namespace Certloom\Domain\Issuance;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Pending intent is separate from an issued Credential's lifecycle. */
final class IssuanceRequestState {
	const RECEIVED = 'received';
	const WAITING = 'waiting';
	const READY = 'ready';
	const PROCESSING = 'processing';
	const ISSUED = 'issued';
	const BLOCKED = 'blocked';
	const FAILED = 'failed';
	const CANCELLED = 'cancelled';
	const NOT_ELIGIBLE = 'not_eligible';

	/** @return array<int,string> */
	public static function all() {
		return array( self::RECEIVED, self::WAITING, self::READY, self::PROCESSING, self::ISSUED, self::BLOCKED, self::FAILED, self::CANCELLED, self::NOT_ELIGIBLE );
	}

	/**
	 * Transition shape only. Services additionally verify permission, context,
	 * revisions, receipts and evidence under a transaction. A changed trusted
	 * evidence revision is required before reopening a not-eligible request.
	 */
	public static function can_transition( $from, $to, $new_evidence = false, $canonical_receipt = false ) {
		if ( true === $canonical_receipt && self::ISSUED === $to && in_array( $from, array( self::RECEIVED, self::WAITING, self::READY, self::PROCESSING, self::BLOCKED, self::FAILED, self::NOT_ELIGIBLE ), true ) ) { return true; }
		$reassess = array( self::WAITING, self::READY, self::BLOCKED, self::NOT_ELIGIBLE, self::CANCELLED );
		$allowed = array(
			self::RECEIVED => $reassess,
			self::WAITING => $reassess,
			self::BLOCKED => $reassess,
			self::READY => array_merge( $reassess, array( self::PROCESSING ) ),
			self::PROCESSING => array( self::ISSUED, self::WAITING, self::BLOCKED, self::NOT_ELIGIBLE, self::FAILED, self::CANCELLED ),
			self::FAILED => $reassess,
			self::ISSUED => array(),
			self::CANCELLED => array(),
			self::NOT_ELIGIBLE => true === $new_evidence ? array( self::RECEIVED, self::CANCELLED ) : array( self::CANCELLED ),
		);
		return is_string( $from ) && is_string( $to ) && isset( $allowed[ $from ] ) && in_array( $to, $allowed[ $from ], true );
	}
}
