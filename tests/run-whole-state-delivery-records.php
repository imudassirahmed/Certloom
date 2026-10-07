<?php
/** Lossless, bounded recovery records. No delivery execution or SQL writes. */
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
define( 'ABSPATH', __DIR__ . '/fake-wp/' );
class WP_Error { private $code; public function __construct( $code, $message = '', $data = null ) { $this->code = $code; } public function get_error_code() { return $this->code; } }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function __( $value, $domain = null ) { return $value; }
require __DIR__ . '/bootstrap-autoload.php';

use Certloom\Application\Backup\WholeStateDeliveryRecord;
use Certloom\Application\Rendering\CanonicalJson;

class DeliveryWakeupProbe { public static $woken = 0; public function __wakeup() { ++self::$woken; } }
$codec = new WholeStateDeliveryRecord(); $checks = 0;
$check = static function ( $ok, $label ) use ( &$checks ) { if ( ! $ok ) { throw new RuntimeException( $label ); } ++$checks; echo 'PASS ' . $label . "\n"; };
foreach ( array( 'email_deliveries', 'destination_deliveries' ) as $type ) {
	$row = array_fill_keys( $codec::columns( $type ), null );
	$row['id'] = '123'; $row['version_id'] = '00123'; $row['series_id'] = '0'; $row['status'] = 'unreviewed_future_state'; $row['failure_code'] = '';
	$row['evidence_json'] = "\0\xffUnicode \xc3\xa9\r\n" . serialize( new DeliveryWakeupProbe() );
	$record = $codec->from_row( $type, $row );
	$check( ! is_wp_error( $record ) && $codec->to_row( $record ) === $row, $type . ': exact opaque bytes, leading zero, SQL null and empty string round trip' );
	$check( ( new CanonicalJson() )->encode( $record ) === ( new CanonicalJson() )->encode( $codec->from_row( $type, array_reverse( $row, true ) ) ), $type . ': column input order does not affect canonical record bytes' );
	$check( 0 === DeliveryWakeupProbe::$woken, $type . ': serialized evidence never executes' );
	$bad = $row; $bad['extra'] = 'unexpected'; $check( is_wp_error( $codec->from_row( $type, $bad ) ), $type . ': unknown columns refused' );
	$bad = $row; unset( $bad['job_key'] ); $check( is_wp_error( $codec->from_row( $type, $bad ) ), $type . ': omitted SQL null column refused' );
	foreach ( array( true, 123, 1.5, array(), new stdClass() ) as $value ) { $bad = $row; $bad['evidence_json'] = $value; $check( is_wp_error( $codec->from_row( $type, $bad ) ), $type . ': ambiguous storage type refused' ); }
	foreach ( array( '0', '001', '1e2', '9223372036854775808', 1, -1, null ) as $id ) { $bad = $row; $bad['id'] = $id; $check( is_wp_error( $codec->from_row( $type, $bad ) ), $type . ': noncanonical or unrepresentable primary identity refused' ); }
	$minimal = array_fill_keys( $codec::columns( $type ), null ); $minimal['id'] = '1';
	$minimal['evidence_json'] = str_repeat( 'x', $codec::MAX_ROW_BYTES - 1 ); $max = $codec->from_row( $type, $minimal );
	$check( ! is_wp_error( $max ) && $codec::MAX_ROW_BYTES === $max['bytes'] && $codec->to_row( $max ) === $minimal, $type . ': exact aggregate row ceiling survives round trip' );
	$minimal['evidence_json'] .= 'x'; $check( is_wp_error( $codec->from_row( $type, $minimal ) ), $type . ': oversized source row refused' );
	foreach ( array( 'version', 'identity', 'bytes', 'hash', 'encoded', 'scope', 'omitted', 'extra', 'record_extra' ) as $mode ) {
		$bad = $record;
		if ( 'version' === $mode ) { $bad['record_version'] = 2; }
		if ( 'identity' === $mode ) { $bad['id'] = '124'; }
		if ( 'bytes' === $mode ) { ++$bad['bytes']; }
		if ( 'hash' === $mode ) { $bad['sha256'] = str_repeat( 'a', 64 ); }
		if ( 'encoded' === $mode ) { $bad['columns']['evidence_json'] .= "\n"; }
		if ( 'scope' === $mode ) { $bad['entity'] = 'jobs'; }
		if ( 'omitted' === $mode ) { unset( $bad['columns']['job_key'] ); }
		if ( 'extra' === $mode ) { $bad['columns']['extra'] = ''; }
		if ( 'record_extra' === $mode ) { $bad['restore_ready'] = true; }
		$check( is_wp_error( $codec->to_row( $bad ) ), $type . ': malformed ' . $mode . ' record refused' );
	}
}
$check( is_wp_error( $codec->from_row( 'jobs', array( 'id' => '1' ) ) ) && is_wp_error( $codec->to_row( 'not a record' ) ), 'the closed inventory cannot grant execution-job or scalar import authority' );
$check( $codec::id( (string) PHP_INT_MAX ) && $codec::id( '0', true ) && ! $codec::id( '0' ), 'platform-representable identity bound and explicit zero cursor are stable' );
echo "\n{$checks} checks, 0 failures\n";
