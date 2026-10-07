<?php
/** Historical hashes remain readable through the real artifact service without opening authority. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
require __DIR__ . '/bootstrap-autoload.php';
class WP_Error { private $code; public function __construct( $code, $message, $data = null ) { $this->code = $code; } public function get_error_code() { return $this->code; } }
function __( $value ) { return $value; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_-]/i', '', $value ) ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
use Certloom\Application\Contract\CredentialQueryRepositoryInterface;
use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Application\Rendering\CredentialArtifactService;
use Certloom\Application\Rendering\LayoutValidator;
use Certloom\Domain\Credential\PublicCredentialId;
final class HistoricalArtifactQuery implements CredentialQueryRepositoryInterface { public $row; public function find_current_by_public_id( $id ) { return $this->row; } }
function historical_digest( $value ) {
	if ( is_array( $value ) ) { if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) { ksort( $value, SORT_STRING ); } foreach ( $value as &$item ) { $item = historical_order( $item ); } unset( $item ); }
	$previous = ini_get( 'serialize_precision' ); ini_set( 'serialize_precision', '17' );
	try { return hash( 'sha256', json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION ) ); } finally { ini_set( 'serialize_precision', $previous ); }
}
function historical_order( $value ) { if ( ! is_array( $value ) ) { return $value; } if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) { ksort( $value, SORT_STRING ); } foreach ( $value as &$item ) { $item = historical_order( $item ); } return $value; }
$query = new HistoricalArtifactQuery(); $id = ( new PublicCredentialId() )->generate();
$layout = ( new LayoutValidator() )->normalize( json_decode( file_get_contents( __DIR__ . '/fixtures/rendering/english.json' ), true ) );
$layout['elements'][0]['x_mm'] = 0.1;
$merge = array( 'recipient.name' => 'Historical recipient', 'custom.score' => 0.1 ); $json = new CanonicalJson();
$snapshot = array( 'presentation' => array( 'mode' => 'design' ), 'template' => array( 'layout' => $layout, 'layout_sha256' => $json->hash( $layout ) ), 'merge' => array( 'values' => $merge, 'sha256' => $json->hash( $merge ) ), 'credential' => array( 'public_id' => $id, 'series_id' => 1, 'display_number' => 'HISTORICAL-1', 'verification_url' => 'https://example.test/credential/' . $id ) );
$query->row = array( 'version_id' => 1, 'series_id' => 1, 'public_id' => $id, 'recipient_id' => 1, 'display_number' => 'HISTORICAL-1', 'visibility' => 'public', 'issuance_state' => 'issued', 'administrative_state' => 'active', 'issued_at' => '2026-01-01', 'snapshot' => $snapshot );
$service = new CredentialArtifactService( $query, static function () { return array( 'id' => 1 ); } );
$checks = 0; $failures = 0;
function artifact_history_check( $ok, $label ) { global $checks, $failures; $checks++; if ( ! $ok ) { $failures++; } echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . "\n"; }
artifact_history_check( 'certloom_download_renderer_unavailable' === $service->availability( $id, 1, true )['reason'], 'canonical snapshot passes preparation and still respects the closed release gate' );
$query->row['snapshot']['template']['layout_sha256'] = historical_digest( $layout );
artifact_history_check( $json->hash( $layout ) !== historical_digest( $layout ), 'fixture exercises distinct legacy geometry bytes' );
artifact_history_check( 'certloom_download_renderer_unavailable' === $service->availability( $id, 1, true )['reason'], 'historical layout digest remains readable through artifact preparation' );
$query->row['snapshot']['merge']['sha256'] = historical_digest( $merge );
artifact_history_check( 'certloom_download_renderer_unavailable' === $service->availability( $id, 1, true )['reason'], 'historical merge digest remains readable without rewriting the issued snapshot' );
$query->row['snapshot']['template']['layout']['elements'][0]['x_mm'] = 0.2;
artifact_history_check( 'certloom_download_design_unavailable' === $service->availability( $id, 1, true )['reason'], 'changed historical geometry is refused' );
$query->row['snapshot']['template']['layout'] = $layout; $query->row['snapshot']['merge']['values']['custom.score'] = 0.2;
artifact_history_check( 'certloom_download_design_unavailable' === $service->availability( $id, 1, true )['reason'], 'changed historical merge values are refused' );
artifact_history_check( 'certloom_download_not_found' === $service->availability( $id, 0, true )['reason'], 'historical compatibility never bypasses authentication' );
echo "\n$checks checks, $failures failures\n"; exit( $failures ? 1 : 0 );
