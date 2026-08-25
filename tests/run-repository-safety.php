<?php
/** Structural regression for repository mutation ordering and fail-closed structured data. */
$root = dirname( __DIR__ );

function rs_source( $relative ) {
	$source = file_get_contents( dirname( __DIR__ ) . '/' . $relative );
	if ( ! is_string( $source ) || '' === $source ) { throw new RuntimeException( $relative . ' source unavailable.' ); }
	return $source;
}
function rs_before( $source, $needle_a, $needle_b, $message ) {
	$a = strpos( $source, $needle_a );
	$b = false === $a ? false : strpos( $source, $needle_b, $a );
	if ( false === $a || false === $b || $a >= $b ) { throw new RuntimeException( $message ); }
}

$credential = rs_source( 'src/Infrastructure/Repository/CredentialRepository.php' );
rs_before(
	$credential,
	'encode_array( $data[\'snapshot\']',
	'( new DomainGeneration() )->mark_before_mutation()',
	'Immutable snapshot serialization must complete before domain generation mutation.'
);
rs_before(
	$credential,
	'( new DomainGeneration() )->mark_before_mutation()',
	'$this->reserve_display_number( $series_id, $display_number )',
	'Certificate-number reservation must occur only after the generation mutation boundary is established.'
);
if ( substr_count( $credential, '$this->release_new_number_reservation( $reservation )' ) < 2 ) {
	throw new RuntimeException( 'Failed Version persistence/link paths must release new certificate-number reservations.' );
}
if ( false === strpos( $credential, 'certloom_version_create_cleanup_failed' ) || false === strpos( $credential, 'certloom_version_link_rollback_failed' ) || false === strpos( $credential, 'certloom_display_number_release_failed' ) ) {
	throw new RuntimeException( 'Version compensation failures must surface explicitly and retain number ownership rather than fail silently.' );
}

$direct_checks = array(
	'src/Infrastructure/Repository/IssuerRepository.php' => 'encode_array( $data[\'settings\']',
);
foreach ( $direct_checks as $file => $encode_needle ) {
	$source = rs_source( $file );
	rs_before( $source, $encode_needle, '( new DomainGeneration() )->mark_before_mutation()', $file . ' must serialize structured input before its generation marker.' );
	if ( false === strpos( $source, 'decode_array(' ) ) { throw new RuntimeException( $file . ' must fail closed when stored structured JSON is corrupt.' ); }
}

$normalized_checks = array(
	'src/Infrastructure/Repository/RecipientRepository.php' => "encode_array( \$data['custom_fields']",
	'src/Infrastructure/Repository/ProgramRepository.php'   => "encode_array( \$data['metadata']",
);
foreach ( $normalized_checks as $file => $encode_needle ) {
	$source = rs_source( $file );
	if ( false === strpos( $source, $encode_needle ) ) { throw new RuntimeException( $file . ' normalization must serialize structured input.' ); }
	rs_before( $source, '$this->normalize_patch( $data, true )', '( new DomainGeneration() )->mark_before_mutation()', $file . ' create path must finish normalization/serialization before its generation marker.' );
	rs_before( $source, '$this->normalize_patch( $data, false )', '( new DomainGeneration() )->mark_before_mutation()', $file . ' update path must finish normalization/serialization before its generation marker.' );
	if ( false === strpos( $source, 'decode_array(' ) ) { throw new RuntimeException( $file . ' must fail closed when stored structured JSON is corrupt.' ); }
}

$event = rs_source( 'src/Infrastructure/Repository/EventRepository.php' );
rs_before( $event, 'encode_array(', '( new DomainGeneration() )->mark_before_mutation()', 'Event state/meta serialization must finish before the event mutation generation marker.' );
if ( false === strpos( $event, 'decode_array(' ) ) { throw new RuntimeException( 'Event history must fail closed on corrupt stored JSON.' ); }

$job = rs_source( 'src/Infrastructure/Job/JobRepository.php' );
if ( false === strpos( $job, 'decode_array(' ) || false === strpos( $job, 'certloom_job_json_corrupt' ) ) {
	throw new RuntimeException( 'Durable job JSON must fail closed rather than silently becoming an empty array.' );
}

$restore = rs_source( 'src/Infrastructure/Restore/DomainRestoreMapRepository.php' );
if ( false === strpos( $restore, 'decode_array(' ) ) {
	throw new RuntimeException( 'Merge restore map metadata must fail closed when stored JSON is corrupt.' );
}

echo "PASS  immutable Version payload serialization is side-effect free before reservation\n";
echo "PASS  post-reservation Version failure paths verify cleanup and preserve ownership on compensation failure\n";
echo "PASS  domain entity/event structured payloads serialize before mutation markers\n";
echo "PASS  repository, job, and restore structured JSON reads fail closed on corruption\n";
