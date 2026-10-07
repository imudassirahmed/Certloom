<?php
/** Shared Program editor and repository writes cannot grant personal/source authority. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
require __DIR__ . '/bootstrap-autoload.php';
class WP_Error { private $code; public function __construct( $code, $message = '' ) { $this->code = $code; } public function get_error_code() { return $this->code; } }
function __( $value ) { return $value; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_textarea_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function wp_json_encode( $value, $flags = 0, $depth = 512 ) { return json_encode( $value, $flags, $depth ); }
class Ecapla_CertVerify_Fields { public static function get_field_map() { return $GLOBALS['pfd_catalog']; } }
require dirname( __DIR__ ) . '/includes/class-ecapla-certverify-validator.php';
use Certloom\Application\Fields\FieldCollectionPolicy;
use Certloom\Application\Fields\ProgramFieldDefaults;
use Certloom\Infrastructure\Repository\ProgramRepository;
$policy = array( '_meta' => array( 'schema' => FieldCollectionPolicy::SCHEMA, 'schema_version' => 1, 'configured' => true ), 'lifetime' => 'program', 'writer' => 'staff', 'collect_when' => 'never', 'approval_required' => false, 'show_on_certificate' => false, 'help' => '' );
$catalog = array();
foreach ( array( 'training_hours' => 'number', 'program_note' => 'textarea', 'start_date' => 'date', 'personal_id' => 'text', 'score' => 'number', 'legacy_field' => 'text', 'invalid_policy' => 'text' ) as $key => $type ) {
	$catalog[ $key ] = array( 'key' => $key, 'label' => $key, 'type' => $type, 'sensitivity' => 'personal', 'public_exposure' => 'private', 'collection_policy' => $policy );
}
$catalog['personal_id']['collection_policy']['lifetime'] = 'recipient';
$catalog['personal_id']['collection_policy']['writer'] = 'recipient';
$catalog['personal_id']['collection_policy']['collect_when'] = 'profile';
$catalog['score']['collection_policy']['lifetime'] = 'achievement';
$catalog['score']['collection_policy']['writer'] = 'source';
unset( $catalog['legacy_field']['collection_policy'] );
$catalog['invalid_policy']['collection_policy']['_meta']['schema_version'] = 99;
$GLOBALS['pfd_catalog'] = $catalog;
$checks = 0;
function pfd_check( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } ++$GLOBALS['checks']; echo "PASS  $message\n"; }
$service = new ProgramFieldDefaults();
pfd_check( array( 'training_hours', 'program_note', 'start_date' ) === array_keys( $service->editable_fields( $catalog ) ), 'only explicit valid organization-owned Program fields appear in the editor' );
$form = array( 'program_form_version' => '1', 'program_form_complete' => '1', 'program_field_defaults_present' => '1', 'program_field_default' => array( 'training_hours' => '0', 'program_note' => "First line\nSecond line", 'start_date' => '2026-10-02' ) );
$metadata = array( 'integration_details' => array( 'nested' => 'keep' ), 'legacy_label' => 'unchanged' );
$written = $service->with_form_metadata( $metadata, $form, $catalog );
pfd_check( ! is_wp_error( $written ) && '0' === $written[ ProgramFieldDefaults::KEY ]['values']['training_hours'], 'a zero shared value survives the HTML form' );
pfd_check( "First line\nSecond line" === $written[ ProgramFieldDefaults::KEY ]['values']['program_note'], 'multiline shared values survive independently from the scalar metadata editor' );
$browser_lines = $form; $browser_lines['program_field_default']['program_note'] = "First line\r\nSecond line\rThird line";
pfd_check( "First line\nSecond line\nThird line" === $service->with_form_metadata( $metadata, $browser_lines, $catalog )[ ProgramFieldDefaults::KEY ]['values']['program_note'], 'native HTML form line endings become one stable multiline value' );
pfd_check( $written['integration_details'] === $metadata['integration_details'] && $written['legacy_label'] === $metadata['legacy_label'], 'unknown nested and legacy metadata survive a dedicated shared-value edit' );
pfd_check( $service->from_program( array( 'metadata' => $written ), $catalog ) === $written[ ProgramFieldDefaults::KEY ], 'the editor round trips through the authoritative provider envelope' );
pfd_check( $service->with_form_metadata( $written, array(), $catalog ) === $written, 'older or reduced forms preserve omitted shared settings' );
$clear = $form; $clear['program_field_default'] = array( 'training_hours' => '', 'program_note' => '', 'start_date' => '' );
$cleared = $service->with_form_metadata( $written, $clear, $catalog );
pfd_check( array() === $cleared[ ProgramFieldDefaults::KEY ]['values'] && $cleared['integration_details'] === $metadata['integration_details'], 'clearing values is explicit and preserves unrelated structured metadata' );
$empty = $form; unset( $empty['program_field_default'] );
pfd_check( array() === $service->with_form_metadata( $written, $empty, $catalog )[ ProgramFieldDefaults::KEY ]['values'], 'a complete empty editor safely clears retired shared values' );
foreach ( array( array( 'program_field_default' => array( 'training_hours' => '40' ) ), array( 'program_field_defaults_present' => '1' ), array( 'program_field_defaults_present' => array( '1' ), 'program_form_complete' => '1' ), array( 'program_form_version' => '1' ), array( 'program_form_version' => '2', 'program_form_complete' => '1' ) ) as $bad_form ) {
	pfd_check( is_wp_error( $service->with_form_metadata( $written, $bad_form, $catalog ) ), 'missing, truncated or unsupported form markers cannot clear saved values' );
}
foreach ( array( array( 'personal_id' => '123' ), array( 'score' => '100' ), array( 'legacy_field' => 'promoted' ), array( 'invalid_policy' => 'value' ), array( 'missing' => 'value' ), array( 'training_hours' => array( '40' ) ), array( 'training_hours' => 40 ), array( 'training_hours' => true ), array( 'training_hours' => 'bad number' ), array( 'training_hours' => '1e309' ), array( 'start_date' => 'not-a-date' ), array( 'start_date' => '2026-02-31' ), array( 'program_note' => str_repeat( 'x', 8193 ) ) ) as $bad_values ) {
	$bad_form = $form; $bad_form['program_field_default'] = $bad_values;
	pfd_check( is_wp_error( $service->with_form_metadata( $written, $bad_form, $catalog ) ), 'unauthorized or malformed shared values fail before persistence' );
}
$patch = new ReflectionMethod( ProgramRepository::class, 'normalize_patch' );
$repo = new ProgramRepository();
$row = $patch->invoke( $repo, array( 'metadata' => $written ), false );
pfd_check( ! is_wp_error( $row ) && json_decode( $row['metadata_json'], true ) === $written, 'repository writes independently validate and preserve the typed envelope' );
foreach ( array( null, 'forged', array( '_meta' => array( 'schema' => ProgramFieldDefaults::SCHEMA, 'schema_version' => 99 ), 'values' => array() ), array( '_meta' => array( 'schema' => ProgramFieldDefaults::SCHEMA, 'schema_version' => 1 ), 'values' => array( 'personal_id' => 'forged' ) ) ) as $bad_defaults ) {
	pfd_check( is_wp_error( $patch->invoke( $repo, array( 'metadata' => array( ProgramFieldDefaults::KEY => $bad_defaults ) ), false ) ), 'repository callers cannot bypass typed Program default validation' );
}
$legacy_row = $patch->invoke( $repo, array( 'metadata' => $metadata ), false );
pfd_check( ! is_wp_error( $legacy_row ) && json_decode( $legacy_row['metadata_json'], true ) === $metadata, 'metadata without a shared envelope retains legacy behavior' );
$before = $written; $changed_catalog = $catalog; $changed_catalog['training_hours']['collection_policy']['writer'] = 'source';
pfd_check( is_wp_error( $service->from_program( array( 'metadata' => $written ), $changed_catalog ) ) && $written === $before, 'a changed field supplier invalidates future defaults without mutating previous snapshots' );
echo "\n$checks checks, 0 failures\n";
