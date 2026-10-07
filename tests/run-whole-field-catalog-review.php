<?php
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
require __DIR__ . '/bootstrap-autoload.php';
class WP_Error { public function __construct( $code, $message = '', $data = null ) {} }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function __( $value ) { return $value; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $value ) ); }
$checks = 0;
$check = static function ( $ok, $label ) use ( &$checks ) { if ( ! $ok ) { throw new RuntimeException( $label ); } ++$checks; };
$review = new \Certloom\Application\Restore\WholeStateFieldCatalogReview();
$catalog = array( 'id_number' => array( 'key' => 'id_number', 'active' => true ), 'old_score' => array( 'key' => 'old_score', 'active' => false ) );
foreach ( array( 'issuers' => array( 'settings_json', 'issuer' ), 'programs' => array( 'metadata_json', 'program' ), 'batches' => array( 'metadata_json', 'batch' ) ) as $entity => $settings ) {
	list( $column, $scope ) = $settings; $contract = array( '_meta' => array( 'schema' => 'certloom-scoped-fields', 'schema_version' => 1, 'scope' => $scope, 'configured' => true ), 'fields' => array( 'id_number' => 'required', 'old_score' => 'optional' ) );
	$row = array( $column => json_encode( array( \Certloom\Application\Fields\ScopedFieldContract::STORAGE_KEY => $contract ) ) ); $before = $row;
	$check( true === $review->validate( $entity, $row, $catalog ) && $row === $before, $entity . ': active and retired source requirements preserve their exact stored modes' );
	$check( is_wp_error( $review->validate( $entity, $row, array() ) ), $entity . ': target fields cannot substitute for missing source definitions' );
	$bad = $contract; $bad['fields']['unknown'] = 'required'; $row[$column] = json_encode( array( \Certloom\Application\Fields\ScopedFieldContract::STORAGE_KEY => $bad ) );
	$check( is_wp_error( $review->validate( $entity, $row, $catalog ) ), $entity . ': a missing source field fails before recovery' );
	$bad = $contract; $bad['_meta']['scope'] = 'global'; $row[$column] = json_encode( array( \Certloom\Application\Fields\ScopedFieldContract::STORAGE_KEY => $bad ) );
	$check( is_wp_error( $review->validate( $entity, $row, $catalog ) ), $entity . ': a foreign scope cannot acquire this entity authority' );
	$check( true === $review->validate( $entity, array( $column => null ), $catalog ), $entity . ': absent optional field configuration stays absent' );
}
$check( true === $review->validate( 'credential_versions', array( 'snapshot_json' => '{"original":"unchanged"}' ), array() ), 'immutable issued field snapshots remain independent of mutable field definitions' );
echo $checks . " field-catalog review checks, 0 failures\n";
