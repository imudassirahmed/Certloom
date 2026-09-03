<?php
/** Architecture regression for Core-owned persisted compliance truth. */
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code;
		private $message;
		public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
	}
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function __( $text ) { return $text; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_key( $key ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $key ) ); }
require_once __DIR__ . '/bootstrap-autoload.php';

use Certloom\Application\Restore\DomainRestoreRowValidator;
use Certloom\Domain\Credential\PublicCredentialId;
use Certloom\Infrastructure\Database\SchemaManager;

function cp_assert( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}

$columns = SchemaManager::column_names( 'credential_series' );
cp_assert( in_array( 'compliance_state', $columns, true ), 'Credential Series schema must persist compliance_state in Core.' );

$row = array_fill_keys( $columns, null );
$row['id']                   = '1';
$row['public_id']            = ( new PublicCredentialId() )->generate();
$row['recipient_id']         = '1';
$row['administrative_state'] = 'active';
$row['compliance_state']     = 'noncompliant';
$row['visibility']           = 'verification_summary';
$row['created_at']           = '2026-08-22 00:00:00';
$row['updated_at']           = '2026-08-22 00:00:00';
$validator = new DomainRestoreRowValidator();
cp_assert( true === $validator->validate( 'credential_series', $row ), 'A supported persisted compliance state must survive backup/restore validation.' );
$row['compliance_state'] = 'made_up_state';
$result = $validator->validate( 'credential_series', $row );
cp_assert( is_wp_error( $result ), 'Unknown compliance states must fail closed during restore.' );

echo "PASS  Credential Series persists Core-readable compliance state and restore rejects unknown compliance values\n\n1 test, 0 failures\n";
