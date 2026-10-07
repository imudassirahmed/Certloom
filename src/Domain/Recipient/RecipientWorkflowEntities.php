<?php
namespace Certloom\Domain\Recipient;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Canonical dependency order for durable recipient workflow data. */
final class RecipientWorkflowEntities {
	public static function all() {
		return array( 'program_registrations', 'field_submissions', 'issuance_requests', 'issuance_request_events' );
	}
}
