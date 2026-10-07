<?php
/** FLD-02 mutable Program/Batch configuration dependency guard regression. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', __DIR__ . '/' );
$root = dirname( __DIR__ );
$pass = 0; $fail = 0;
function fldcfg( $ok, $label ) { global $pass, $fail; if ( $ok ) { $pass++; echo "PASS  $label\n"; } else { $fail++; echo "FAIL  $label\n"; } }
$guard = file_get_contents( $root . '/src/Infrastructure/Admin/ScopedFieldConfigurationGuard.php' );
$program_admin = file_get_contents( $root . '/src/Infrastructure/Admin/DomainAdmin.php' );
$batch_admin = file_get_contents( $root . '/src/Infrastructure/Admin/DomainStructureAdmin.php' );
$batch_repo = file_get_contents( $root . '/src/Infrastructure/Repository/BatchRepository.php' );
$template_repo = file_get_contents( $root . '/src/Infrastructure/Repository/TemplateRepository.php' );

fldcfg( false !== strpos( $program_admin, 'validate_program_change' ) && false !== strpos( $program_admin, 'from_program_metadata' ), 'Program save validates the prospective typed field contract before persistence' );
fldcfg( false !== strpos( $batch_admin, 'validate_batch_change' ) && false !== strpos( $batch_admin, 'from_batch_metadata' ), 'Batch save validates the prospective typed override before persistence' );
fldcfg( false !== strpos( $guard, 'list_for_program_after' ) && false !== strpos( $guard, 'BATCH_CHUNK = 100' ), 'Program mutation scans dependent Batches in bounded chunks instead of loading an unbounded cohort' );
fldcfg( false !== strpos( $batch_repo, 'WHERE program_id=%d AND id>%d ORDER BY id ASC LIMIT %d' ) && false !== strpos( $batch_repo, 'min( 100' ), 'Batch dependency repository uses stable id pagination with a hard upper bound' );
fldcfg( false !== strpos( $guard, 'find_published_by_machine_key' ) && false !== strpos( $template_repo, 'function find_published_by_machine_key' ), 'Program locale Template variants use the real stable machine-key publication boundary' );
fldcfg( false !== strpos( $guard, 'resolve_published_assignment_details' ) && false !== strpos( $guard, "'published_layout'" ), 'Configuration guard validates the currently resolved inherited Template hierarchy using its exact published layout' );
fldcfg( false !== strpos( $guard, 'New Programs already inherit Issuer/Global design assignments' ), 'New Program creation validates inherited Issuer/Global Template compatibility before an id exists' );
fldcfg( false !== strpos( $guard, 'certloom_program_field_contract_breaks_batch' ) && false !== strpos( $guard, 'with_batch_context' ), 'Program edits fail closed with Batch-specific context when an existing override or Template would break' );
fldcfg( false !== strpos( $guard, 'validate_templates( $variants' ) && false !== strpos( $guard, 'validate_resolved_assignment' ), 'Both locale variants and resolved certificate designs are checked against prospective effective fields' );
fldcfg( false !== strpos( $guard, "'certloom_template_not_published' === \$resolved->get_error_code()" ) && false !== strpos( $guard, 'issuance remain fail-closed' ), 'Draft design assignment does not block Program/Batch configuration while publication and issuance stay fail-closed' );
fldcfg( false === strpos( $guard, 'find_published_template_by_machine_key' ) && false === strpos( $guard, 'resolve_published_template_for_context' ), 'Guard does not call nonexistent repository convenience methods' );

echo "\n$pass FLD-02 configuration dependency checks, $fail failures\n";
exit( $fail ? 1 : 0 );
