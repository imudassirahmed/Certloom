<?php
namespace Certloom\Application\Contract;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Private, bounded selections contain scheduling facts, never customer values. */
interface PendingConfigurationSelectionInterface {
	public function configuration_rows( $program_id, $after_id, $max_id, $limit = 11 );
	public function acknowledge( $generation );
}
