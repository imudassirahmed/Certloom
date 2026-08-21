<?php
namespace Certloom\Application\Contract;

/** Append-only event persistence required by lifecycle commands. */
interface EventRepositoryInterface {
	/** @param array<string,mixed> $event @return int|\WP_Error */
	public function append( array $event );
}
