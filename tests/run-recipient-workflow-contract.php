<?php
/** Durable request transitions and workflow table/index contracts. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', __DIR__ . '/' ); define( 'DAY_IN_SECONDS', 86400 ); define( 'MINUTE_IN_SECONDS', 60 );
$wpdb = (object) array( 'prefix' => 'wp_' );
require_once __DIR__ . '/bootstrap-autoload.php';
use Certloom\Domain\Issuance\IssuanceRequestState;
use Certloom\Infrastructure\Database\RecipientWorkflowSchema;
use Certloom\Infrastructure\Database\SchemaManager;
$passed = 0; $failed = 0;
function rwc_check( $ok, $label ) { global $passed, $failed; if ( $ok ) { ++$passed; echo "PASS  $label\n"; } else { ++$failed; echo "FAIL  $label\n"; } }
foreach ( IssuanceRequestState::all() as $target ) {
	rwc_check( ! IssuanceRequestState::can_transition( IssuanceRequestState::ISSUED, $target ), 'issued request cannot be changed to ' . $target );
	rwc_check( ! IssuanceRequestState::can_transition( IssuanceRequestState::CANCELLED, $target ), 'cancelled request cannot be changed to ' . $target );
}
rwc_check( ! IssuanceRequestState::can_transition( 'waiting', 'issued' ), 'missing information cannot bypass readiness and processing' );
rwc_check( IssuanceRequestState::can_transition( 'waiting', 'issued', false, true ), 'a separately verified committed receipt reconciles a stale waiting request' );
rwc_check( ! IssuanceRequestState::can_transition( 'waiting', 'issued', false, 'true' ), 'untyped receipt flags never grant reconciliation authority' );
rwc_check( ! IssuanceRequestState::can_transition( 'cancelled', 'issued', false, true ), 'receipt reconciliation cannot reopen a cancelled request' );
rwc_check( ! IssuanceRequestState::can_transition( 'failed', 'processing' ), 'retry requires reassessment rather than an expired review' );
rwc_check( IssuanceRequestState::can_transition( 'ready', 'processing' ) && IssuanceRequestState::can_transition( 'processing', 'issued' ), 'reviewed ready request can follow the canonical issue path' );
rwc_check( IssuanceRequestState::can_transition( 'processing', 'waiting' ), 'a changed final check returns the request to waiting' );
rwc_check( ! IssuanceRequestState::can_transition( 'not_eligible', 'received' ), 'old failed qualification cannot be reopened by a profile save' );
rwc_check( IssuanceRequestState::can_transition( 'not_eligible', 'received', true ), 'a new trusted evidence revision may request reassessment' );
rwc_check( ! IssuanceRequestState::can_transition( 'not_eligible', 'received', 'true' ), 'untyped browser flags cannot grant evidence revision authority' );
rwc_check( ! IssuanceRequestState::can_transition( 'unknown', 'issued' ), 'unknown request states fail closed' );
$contracts = RecipientWorkflowSchema::contracts(); $sql = RecipientWorkflowSchema::sql( 'DEFAULT CHARACTER SET utf8mb4' );
rwc_check( 4 === count( $contracts ) && array_keys( $contracts ) === array_keys( $sql ), 'DDL and structural verification cover the same four stores' );
foreach ( $contracts as $name => $contract ) {
	rwc_check( array_keys( $contract['columns'] ) === SchemaManager::column_names( $name ), $name . ' uses the canonical restore column whitelist' );
	rwc_check( false !== strpos( $sql[ $name ], 'ENGINE=InnoDB' ), $name . ' participates in real atomic transactions' );
	rwc_check( true === $contract['indexes']['PRIMARY']['unique'] && array( 'id' ) === $contract['indexes']['PRIMARY']['columns'], $name . ' has stable durable row identity' );
}
rwc_check( array( 'recipient_id', 'program_id', 'instance_key' ) === $contracts['program_registrations']['indexes']['participation']['columns'], 'registration identity supports repeat participation and Program separation' );
rwc_check( true === $contracts['field_submissions']['indexes']['open_submission']['unique'] && 'YES' === $contracts['field_submissions']['columns']['open_slot']['null'], 'one open submission per field scope coexists with historical decisions' );
rwc_check( true === $contracts['issuance_requests']['indexes']['intake_key']['unique'], 'source replay has a database-enforced unique intake identity' );
rwc_check( array( 'status', 'next_attempt_at', 'id' ) === $contracts['issuance_requests']['indexes']['work_due']['columns'], 'reassessment can use bounded indexed work rather than scanning all recipients' );
rwc_check( array( 'request_id', 'id' ) === $contracts['issuance_request_events']['indexes']['request_history']['columns'], 'audit history has an indexed request boundary' );
echo "\n$passed workflow contract checks, $failed failures\n";
exit( $failed ? 1 : 0 );
