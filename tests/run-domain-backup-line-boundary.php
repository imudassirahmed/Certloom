<?php
/** Exercise the streaming record limit independently of JSON/integrity validation. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error {
	private $code;
	public function __construct( $code, $message ) { $this->code = $code; }
	public function get_error_code() { return $this->code; }
}
function __( $message ) { return $message; }
function absint( $value ) { return abs( (int) $value ); }
require dirname( __DIR__ ) . '/src/Application/Backup/DomainBackupFormat.php';

use Certloom\Application\Backup\DomainBackupFormat;
$format = new DomainBackupFormat();
$checks = 0; $failures = 0;
function boundary_check( $condition, $message ) {
	global $checks, $failures;
	$checks++;
	if ( ! $condition ) { $failures++; }
	echo ( $condition ? 'PASS  ' : 'FAIL  ' ) . $message . "\n";
}
function boundary_stream( $bytes ) {
	$stream = fopen( 'php://temp/maxmemory:8388608', 'w+b' );
	if ( false === $stream || strlen( $bytes ) !== fwrite( $stream, $bytes ) || ! rewind( $stream ) ) { throw new RuntimeException( 'Cannot prepare bounded stream.' ); }
	return $stream;
}
$limit = DomainBackupFormat::MAX_LINE_BYTES;
foreach ( array( 0, 1 ) as $below ) {
	$record = str_repeat( 'a', $limit - 1 - $below ) . "\n";
	$stream = boundary_stream( $record . "{}\n" );
	boundary_check( $record === $format->read_line( $stream, 1 ), 'terminated record at/below limit remains intact' );
	boundary_check( "{}\n" === $format->read_line( $stream, 2 ), 'next line is not consumed with bounded record' );
	fclose( $stream );
}
foreach ( array( str_repeat( 'a', $limit ) . "\n", str_repeat( 'a', $limit ) . "{}\n" ) as $oversized ) {
	$stream = boundary_stream( $oversized );
	$result = $format->read_line( $stream, 7 );
	boundary_check( $result instanceof WP_Error && 'certloom_domain_backup_line_too_large' === $result->get_error_code(), 'unterminated limit-sized chunk is refused before a subsequent chunk can become another record' );
	fclose( $stream );
}
$stream = boundary_stream( '{}' );
boundary_check( '{}' === $format->read_line( $stream, 1 ), 'short final record without newline remains backward compatible' );
boundary_check( null === $format->read_line( $stream, 2 ), 'end of stream is reported separately' );
fclose( $stream );
echo "\n$checks checks, $failures failures\n";
exit( $failures ? 1 : 0 );
