<?php
namespace Certloom\Application\Contract;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** Append-only event persistence required by lifecycle commands. */
interface EventRepositoryInterface {
	/** @param array<string,mixed> $event @return int|\WP_Error */
	public function append( array $event );
}
