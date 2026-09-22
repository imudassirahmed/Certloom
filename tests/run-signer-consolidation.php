<?php
/** Audit regression: Signer relationship authority is signer_assignments; legacy Batch fields are projections only. */
define( 'ABSPATH', __DIR__ . '/' );
$fail = 0; $checks = 0;
function sc_check( $ok, $label ) { global $fail,$checks; $checks++; echo ($ok?'PASS  ':'FAIL  ') . $label . PHP_EOL; if(!$ok){$fail++;} }
$signer = file_get_contents( dirname(__DIR__) . '/src/Infrastructure/Repository/SignerRepository.php' );
$batch = file_get_contents( dirname(__DIR__) . '/src/Infrastructure/Repository/BatchRepository.php' );
$admin = file_get_contents( dirname(__DIR__) . '/src/Infrastructure/Admin/DomainStructureAdmin.php' );
sc_check( false !== strpos($signer, 'function ensure_assignment'), 'Signer repository exposes idempotent canonical assignment creation' );
sc_check( false !== strpos($signer, 'function find_or_create_lightweight_instructor'), 'legacy free-text instructor can converge onto one reusable Signer' );
sc_check( false !== strpos( $signer, 'function sync_primary_assignment' ) && false !== strpos( $batch, "sync_primary_assignment( \$signer_id, 'batch', \$batch_id, 'instructor' )" ), 'Batch compatibility projection synchronizes one canonical primary instructor assignment' );
sc_check( false !== strpos($batch, 'find_or_create_lightweight_instructor'), 'Batch writes migrate a legacy instructor label instead of perpetuating a third identity model' );
sc_check( false !== strpos($admin, 'Saving this Batch will reuse an exact-name Signer'), 'Batch UI explains legacy convergence instead of promising permanent free-text preservation' );
sc_check( false === strpos($admin, "text_field( 'instructor_name'"), 'new Batch forms do not expose a free-text instructor identity field' );
echo "\n{$checks} signer consolidation checks, {$fail} failures\n"; exit($fail?1:0);
