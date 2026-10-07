<?php
namespace Certloom\Application\Contract;
if ( ! defined( 'ABSPATH' ) ) { exit; }
interface PendingRequestSelectionInterface {
	/** Indexed, ascending selection with a fixed time/identity ceiling. */
	public function due_ids( $cutoff, $after_id = 0, $max_id = PHP_INT_MAX, $limit = 11 );
}
