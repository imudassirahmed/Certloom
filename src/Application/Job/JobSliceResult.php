<?php
namespace Certloom\Application\Job;

/** Structured result from one bounded background-job handler slice. */
final class JobSliceResult {
	const PROGRESS  = 'progress';
	const SUCCEEDED = 'succeeded';
	const PARTIAL   = 'partially_succeeded';
	const FAILED    = 'failed';

	private $state;
	private $cursor;
	private $phase;
	private $result;
	private $error;

	private function __construct( $state, $cursor, $phase, array $result, array $error ) {
		$this->state  = $state;
		$this->cursor = absint( $cursor );
		$this->phase  = null === $phase ? null : sanitize_key( (string) $phase );
		$this->result = $result;
		$this->error  = $error;
	}

	public static function progress( $cursor, $phase = null, array $result = array() ) {
		return new self( self::PROGRESS, $cursor, $phase, $result, array() );
	}

	public static function succeeded( array $result = array(), $cursor = 0 ) {
		return new self( self::SUCCEEDED, $cursor, null, $result, array() );
	}

	public static function partially_succeeded( array $result = array(), $cursor = 0 ) {
		return new self( self::PARTIAL, $cursor, null, $result, array() );
	}

	public static function failed( array $error, array $result = array(), $cursor = 0 ) {
		return new self( self::FAILED, $cursor, null, $result, $error );
	}

	public function state() { return $this->state; }
	public function cursor() { return $this->cursor; }
	public function phase() { return $this->phase; }
	public function result() { return $this->result; }
	public function error() { return $this->error; }
}
