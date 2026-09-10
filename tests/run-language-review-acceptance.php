<?php
/** I18N-03D reviewed-language acceptance boundary regression. */
$root = dirname( __DIR__ );
$checks = 0;
function lra_check( $condition, $message ) {
    global $checks;
    ++$checks;
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$message}\n" );
        exit( 1 );
    }
}
function lra_exec( $command ) {
    $out = array(); $rc = 0;
    exec( $command . ' 2>&1', $out, $rc );
    return array( $rc, implode( "\n", $out ) );
}
function lra_rm( $path ) {
    if ( file_exists( $path ) || is_link( $path ) ) {
        exec( 'rm -rf ' . escapeshellarg( $path ) );
    }
}

$accept = $root . '/tests/i18n/accept_language_review.py';
$review = $root . '/tests/i18n/make_review_kit.py';
$stage = $root . '/tests/i18n/stage_language_candidate.py';
$qa_return = $root . '/tests/i18n/make_complete_qa_return.py';
$validator = $root . '/tests/i18n/validate_language_packs.py';
$manifest = $root . '/languages/certloom-language-packs.json';
$pot = $root . '/languages/certloom.pot';

lra_check( is_file( $accept ), 'review acceptance tool exists' );
lra_check( is_file( $qa_return ), 'complete QA return generator exists only under tests' );
$accept_source = file_get_contents( $accept );
lra_check( false !== strpos( $accept_source, 'review acceptance output must be outside the Certloom plugin tree' ), 'reviewed acceptance cannot write directly into plugin source' );
lra_check( false !== strpos( $accept_source, 'candidate_intake_zip_sha256' ) && false !== strpos( $accept_source, 'candidate_po_sha256' ) && false !== strpos( $accept_source, 'source_key_set_sha256' ), 'human evidence is bound to exact candidate archive/translation/source identities' );
lra_check( false !== strpos( $accept_source, 'candidate report does not bind the exact submitted.po bytes' ), 'acceptance independently binds original submitted translation bytes' );
lra_check( false !== strpos( $accept_source, 'provenance' ) && false !== strpos( $accept_source, 'candidate-intake.zip' ), 'reviewed acceptance preserves exact candidate intake provenance' );
lra_check( false !== strpos( $accept_source, 'reviewed_at may not precede the candidate submission date' ), 'review chronology fails closed' );
lra_check( false !== strpos( $accept_source, 'rtl_mixed_direction_review_complete' ), 'RTL reviewed acceptance requires explicit mixed-direction evidence' );
lra_check( false !== strpos( $accept_source, '100% translated coverage' ), 'reviewed acceptance requires complete translated coverage' );
lra_check( false !== strpos( $accept_source, 'zero fuzzy entries' ), 'reviewed acceptance requires zero fuzzy entries' );

$base = sys_get_temp_dir() . '/certloom-language-review-acceptance-' . bin2hex( random_bytes( 6 ) );
mkdir( $base, 0700, true );
$manifest_before = hash_file( 'sha256', $manifest );

// Produce an exact current review kit and a test-only technically complete return.
$kit_dir = $base . '/kit-es';
list( $kit_rc, $kit_out ) = lra_exec(
    'SOURCE_DATE_EPOCH=1787356800 python3 ' . escapeshellarg( $review ) . ' ' . escapeshellarg( $root ) . ' es_ES --output ' . escapeshellarg( $kit_dir )
);
lra_check( 0 === $kit_rc, 'current Spanish review kit is generated: ' . $kit_out );
$kit_zip = $base . '/certloom-es_ES-review-kit.zip';
lra_check( is_file( $kit_zip ), 'exact review-kit ZIP exists' );

$full_po = $base . '/complete-qa-return.po';
list( $qa_rc, $qa_out ) = lra_exec( 'python3 ' . escapeshellarg( $qa_return ) . ' ' . escapeshellarg( $root ) . ' es_ES ' . escapeshellarg( $full_po ) );
lra_check( 0 === $qa_rc && is_file( $full_po ), 'test-only complete PO return is generated' );

$candidate_out = $base . '/candidate/es_ES';
list( $stage_rc, $stage_out ) = lra_exec(
    'SOURCE_DATE_EPOCH=1787356800 python3 ' . escapeshellarg( $stage ) . ' ' . escapeshellarg( $root ) . ' es_ES ' . escapeshellarg( $full_po )
    . ' --review-kit ' . escapeshellarg( $kit_zip ) . ' --output ' . escapeshellarg( $candidate_out )
    . ' --submitted-by ' . escapeshellarg( 'Certloom acceptance QA fixture' ) . ' --submitted-at 2026-08-28'
);
lra_check( 0 === $stage_rc, 'technically complete return stages through the frozen candidate intake: ' . $stage_out );
$candidate_zip = $base . '/candidate/certloom-es_ES-candidate-intake.zip';
lra_check( is_file( $candidate_zip ), 'complete candidate intake ZIP exists' );
$candidate_report = json_decode( file_get_contents( $candidate_out . '/candidate-report.json' ), true );
lra_check( 100.0 === (float) ( $candidate_report['coverage_percent'] ?? -1 ), 'complete QA candidate reports 100% technical coverage' );
lra_check( 0 === (int) ( $candidate_report['fuzzy_entries'] ?? -1 ), 'complete QA candidate has zero fuzzy entries' );

$evidence = array(
    'schema_version' => 1,
    'locale' => 'es_ES',
    'candidate_intake_zip_sha256' => hash_file( 'sha256', $candidate_zip ),
    'candidate_po_sha256' => $candidate_report['candidate_po_sha256'],
    'source_pot_sha256' => $candidate_report['source_pot_sha256'],
    'source_key_set_sha256' => $candidate_report['source_key_set_sha256'],
    'reviewed_by' => 'Certloom QA fixture — not production review',
    'reviewed_at' => '2026-08-28',
    'linguistic_review_complete' => true,
    'meaning_and_claims_review_complete' => true,
    'browser_review_complete' => true,
    'no_bulk_machine_translation_claimed' => true,
    'notes' => 'Automated acceptance-contract fixture only',
);
$evidence_file = $base . '/review-evidence.json';
file_put_contents( $evidence_file, json_encode( $evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . "\n" );

$accepted1 = $base . '/accepted-a/es_ES';
$command = 'SOURCE_DATE_EPOCH=1787356800 python3 ' . escapeshellarg( $accept ) . ' ' . escapeshellarg( $root ) . ' es_ES'
    . ' --candidate-intake ' . escapeshellarg( $candidate_zip ) . ' --review-evidence ' . escapeshellarg( $evidence_file )
    . ' --output ' . escapeshellarg( $accepted1 );
list( $accept_rc, $accept_out ) = lra_exec( $command );
lra_check( 0 === $accept_rc, 'complete candidate + exact human evidence reaches external reviewed acceptance: ' . $accept_out );
$result = json_decode( $accept_out, true );
lra_check( is_array( $result ) && 'reviewed-acceptance' === ( $result['status'] ?? '' ), 'acceptance result is explicitly reviewed-acceptance evidence' );
lra_check( 100.0 === (float) ( $result['coverage_percent'] ?? -1 ) && 0 === (int) ( $result['fuzzy_entries'] ?? -1 ), 'acceptance report freezes complete/non-fuzzy technical state' );
lra_check( false === ( $result['source_tree_mutated'] ?? true ) && true === ( $result['integration_required'] ?? false ), 'acceptance remains external and requires deliberate integration' );
lra_check( hash_file( 'sha256', $manifest ) === $manifest_before, 'successful external acceptance does not mutate shipped manifest' );

$reviewed_po = $accepted1 . '/integration/languages/certloom-es_ES.po';
$reviewed_mo = $accepted1 . '/integration/languages/certloom-es_ES.mo';
lra_check( is_file( $reviewed_po ) && is_file( $reviewed_mo ), 'reviewed integration PO/MO artifacts are emitted externally' );
$po_text = file_get_contents( $reviewed_po );
lra_check( false !== strpos( $po_text, '"X-Certloom-Review-Status: reviewed\\n"' ), 'reviewed PO has canonical reviewed header' );
lra_check( false === strpos( $po_text, '#, fuzzy' ), 'reviewed PO contains no fuzzy markers' );
$json_files = glob( $accepted1 . '/integration/languages/certloom-es_ES-*.json' ) ?: array();
lra_check( count( $json_files ) > 0, 'reviewed acceptance emits deterministic WordPress/Jed JSON catalogs for native block strings' );
$manifest_entry = json_decode( file_get_contents( $accepted1 . '/reviewed-manifest-entry.json' ), true );
lra_check( 'reviewed' === ( $manifest_entry['status'] ?? '' ) && '2026-08-28' === ( $manifest_entry['reviewed_at'] ?? '' ), 'reviewed manifest proposal carries genuine-review evidence fields' );
lra_check( hash_file( 'sha256', $reviewed_po ) === ( $manifest_entry['po_sha256'] ?? '' ), 'reviewed manifest proposal binds exact PO bytes' );
lra_check( hash_file( 'sha256', $reviewed_mo ) === ( $manifest_entry['mo_sha256'] ?? '' ), 'reviewed manifest proposal binds exact deterministic MO bytes' );
lra_check( file_get_contents( $evidence_file ) === file_get_contents( $accepted1 . '/submitted-review-evidence.json' ), 'exact review evidence bytes are preserved' );
lra_check( file_get_contents( $candidate_zip ) === file_get_contents( $accepted1 . '/provenance/candidate-intake.zip' ), 'exact candidate intake ZIP bytes are preserved inside reviewed acceptance provenance' );
$acceptance_report = json_decode( file_get_contents( $accepted1 . '/acceptance-report.json' ), true );
lra_check( hash_file( 'sha256', $candidate_zip ) === ( $acceptance_report['candidate_intake_zip_sha256'] ?? '' ), 'acceptance report binds exact preserved candidate ZIP' );
lra_check( 'Certloom acceptance QA fixture' === ( $acceptance_report['submitted_by'] ?? '' ) && '2026-08-28' === ( $acceptance_report['submitted_at'] ?? '' ), 'acceptance report preserves candidate submission provenance' );

// Integrate into a temporary validation root and prove the existing frozen validator accepts exact artifacts.
$validate_root = $base . '/validation-root';
mkdir( $validate_root . '/languages', 0700, true );
copy( $pot, $validate_root . '/languages/certloom.pot' );
$current_manifest = json_decode( file_get_contents( $manifest ), true );
foreach ( $current_manifest['targets'] as &$target ) {
    if ( 'es_ES' === $target['locale'] ) {
        $target = $manifest_entry;
        break;
    }
}
unset( $target );
file_put_contents( $validate_root . '/languages/certloom-language-packs.json', json_encode( $current_manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" );
copy( $reviewed_po, $validate_root . '/languages/certloom-es_ES.po' );
copy( $reviewed_mo, $validate_root . '/languages/certloom-es_ES.mo' );
foreach ( $json_files as $file ) { copy( $file, $validate_root . '/languages/' . basename( $file ) ); }
list( $validate_rc, $validate_out ) = lra_exec( 'python3 ' . escapeshellarg( $validator ) . ' ' . escapeshellarg( $validate_root ) . ' --manifest languages/certloom-language-packs.json' );
lra_check( 0 === $validate_rc, 'reviewed acceptance artifacts pass the frozen pack validator without recompilation: ' . $validate_out );

// Determinism for the same candidate + exact evidence.
$accepted2 = $base . '/accepted-b/es_ES';
$command2 = str_replace( escapeshellarg( $accepted1 ), escapeshellarg( $accepted2 ), $command );
list( $accept2_rc, $accept2_out ) = lra_exec( $command2 );
lra_check( 0 === $accept2_rc, 'second independent reviewed acceptance succeeds: ' . $accept2_out );
$zip1 = $base . '/accepted-a/certloom-es_ES-reviewed-acceptance.zip';
$zip2 = $base . '/accepted-b/certloom-es_ES-reviewed-acceptance.zip';
lra_check( hash_file( 'sha256', $zip1 ) === hash_file( 'sha256', $zip2 ), 'same candidate + review evidence produces byte-identical acceptance ZIPs' );

// Negative: review evidence cannot be reused for different candidate bytes.
$bad_evidence = $evidence; $bad_evidence['candidate_po_sha256'] = str_repeat( '0', 64 );
$bad_evidence_file = $base . '/bad-evidence.json';
file_put_contents( $bad_evidence_file, json_encode( $bad_evidence ) . "\n" );
list( $bad_bind_rc, $bad_bind_out ) = lra_exec(
    'SOURCE_DATE_EPOCH=1787356800 python3 ' . escapeshellarg( $accept ) . ' ' . escapeshellarg( $root ) . ' es_ES'
    . ' --candidate-intake ' . escapeshellarg( $candidate_zip ) . ' --review-evidence ' . escapeshellarg( $bad_evidence_file )
    . ' --output ' . escapeshellarg( $base . '/bad-bind' )
);
lra_check( 0 !== $bad_bind_rc && false !== strpos( $bad_bind_out, 'does not bind the exact candidate/source' ), 'review evidence must bind exact candidate bytes' );

$bad_zip_evidence = $evidence; $bad_zip_evidence['candidate_intake_zip_sha256'] = str_repeat( 'f', 64 );
$bad_zip_file = $base . '/bad-zip-evidence.json';
file_put_contents( $bad_zip_file, json_encode( $bad_zip_evidence ) . "\n" );
list( $bad_zip_rc, $bad_zip_out ) = lra_exec(
    'SOURCE_DATE_EPOCH=1787356800 python3 ' . escapeshellarg( $accept ) . ' ' . escapeshellarg( $root ) . ' es_ES'
    . ' --candidate-intake ' . escapeshellarg( $candidate_zip ) . ' --review-evidence ' . escapeshellarg( $bad_zip_file )
    . ' --output ' . escapeshellarg( $base . '/bad-zip-bind' )
);
lra_check( 0 !== $bad_zip_rc && false !== strpos( $bad_zip_out, 'candidate_intake_zip_sha256' ), 'review evidence cannot be reused for a different candidate intake archive' );

// Negative: technical candidate completeness is not optional.
$partial_fixture = $root . '/tests/i18n/fixtures/certloom-es_ES-candidate.po';
$partial_out = $base . '/partial/es_ES';
list( $partial_stage_rc, $partial_stage_out ) = lra_exec(
    'SOURCE_DATE_EPOCH=1787356800 python3 ' . escapeshellarg( $stage ) . ' ' . escapeshellarg( $root ) . ' es_ES ' . escapeshellarg( $partial_fixture )
    . ' --review-kit ' . escapeshellarg( $kit_zip ) . ' --output ' . escapeshellarg( $partial_out )
    . ' --submitted-by QA --submitted-at 2026-08-28'
);
lra_check( 0 === $partial_stage_rc, 'partial candidate fixture stages for negative acceptance test' );
$partial_report = json_decode( file_get_contents( $partial_out . '/candidate-report.json' ), true );
$partial_evidence = $evidence;
$partial_evidence['candidate_intake_zip_sha256'] = hash_file( 'sha256', $base . '/partial/certloom-es_ES-candidate-intake.zip' );
$partial_evidence['candidate_po_sha256'] = $partial_report['candidate_po_sha256'];
$partial_evidence['source_pot_sha256'] = $partial_report['source_pot_sha256'];
$partial_evidence['source_key_set_sha256'] = $partial_report['source_key_set_sha256'];
$partial_evidence_file = $base . '/partial-evidence.json';
file_put_contents( $partial_evidence_file, json_encode( $partial_evidence ) . "\n" );
$partial_zip = $base . '/partial/certloom-es_ES-candidate-intake.zip';
list( $partial_rc, $partial_out_text ) = lra_exec(
    'SOURCE_DATE_EPOCH=1787356800 python3 ' . escapeshellarg( $accept ) . ' ' . escapeshellarg( $root ) . ' es_ES'
    . ' --candidate-intake ' . escapeshellarg( $partial_zip ) . ' --review-evidence ' . escapeshellarg( $partial_evidence_file )
    . ' --output ' . escapeshellarg( $base . '/partial-accept' )
);
lra_check( 0 !== $partial_rc && false !== strpos( $partial_out_text, '100% translated coverage' ), 'partial candidate cannot be promoted by attestation alone' );

// Negative: review date cannot predate candidate submission.
$old_evidence = $evidence; $old_evidence['reviewed_at'] = '2026-08-27';
$old_file = $base . '/old-review.json'; file_put_contents( $old_file, json_encode( $old_evidence ) . "\n" );
list( $old_rc, $old_out ) = lra_exec(
    'SOURCE_DATE_EPOCH=1787356800 python3 ' . escapeshellarg( $accept ) . ' ' . escapeshellarg( $root ) . ' es_ES'
    . ' --candidate-intake ' . escapeshellarg( $candidate_zip ) . ' --review-evidence ' . escapeshellarg( $old_file )
    . ' --output ' . escapeshellarg( $base . '/old-review-out' )
);
lra_check( 0 !== $old_rc && false !== strpos( $old_out, 'may not precede' ), 'review chronology rejects a review date before submission' );

// Negative: duplicate JSON keys are ambiguous and rejected.
$duplicate_file = $base . '/duplicate-review.json';
$duplicate_json = str_replace( '"locale": "es_ES",', "\"locale\": \"es_ES\",\n  \"locale\": \"es_ES\",", file_get_contents( $evidence_file ) );
file_put_contents( $duplicate_file, $duplicate_json );
list( $dup_rc, $dup_out ) = lra_exec(
    'SOURCE_DATE_EPOCH=1787356800 python3 ' . escapeshellarg( $accept ) . ' ' . escapeshellarg( $root ) . ' es_ES'
    . ' --candidate-intake ' . escapeshellarg( $candidate_zip ) . ' --review-evidence ' . escapeshellarg( $duplicate_file )
    . ' --output ' . escapeshellarg( $base . '/dup-out' )
);
lra_check( 0 !== $dup_rc && false !== strpos( $dup_out, 'duplicate key' ), 'duplicate review-evidence JSON keys fail closed' );

// Negative: required human-review attestations cannot be omitted.
$missing = $evidence; unset( $missing['browser_review_complete'] );
$missing_file = $base . '/missing-review.json'; file_put_contents( $missing_file, json_encode( $missing ) . "\n" );
list( $missing_rc, $missing_out ) = lra_exec(
    'SOURCE_DATE_EPOCH=1787356800 python3 ' . escapeshellarg( $accept ) . ' ' . escapeshellarg( $root ) . ' es_ES'
    . ' --candidate-intake ' . escapeshellarg( $candidate_zip ) . ' --review-evidence ' . escapeshellarg( $missing_file )
    . ' --output ' . escapeshellarg( $base . '/missing-out' )
);
lra_check( 0 !== $missing_rc && false !== strpos( $missing_out, 'browser_review_complete=true' ), 'browser-review attestation is mandatory' );

// Negative: RTL acceptance requires mixed-direction review evidence even when other review fields are true.
$rtl_py = <<<'PY'
import json, sys
sys.path.insert(0, sys.argv[1])
from accept_language_review import validate_evidence
payload={
 'schema_version':1,'locale':'ar','candidate_intake_zip_sha256':'d'*64,'candidate_po_sha256':'a'*64,'source_pot_sha256':'b'*64,'source_key_set_sha256':'c'*64,
 'reviewed_by':'QA','reviewed_at':'2026-08-28','linguistic_review_complete':True,'meaning_and_claims_review_complete':True,
 'browser_review_complete':True,'no_bulk_machine_translation_claimed':True
}
try:
 validate_evidence((json.dumps(payload)+'\n').encode(), 'ar', 'rtl', 'd'*64, 'a'*64, 'b'*64, 'c'*64)
except ValueError as exc:
 print(exc); raise SystemExit(0)
raise SystemExit(1)
PY;
list( $rtl_rc, $rtl_out ) = lra_exec( 'python3 -c ' . escapeshellarg( $rtl_py ) . ' ' . escapeshellarg( $root . '/tests/i18n' ) );
lra_check( 0 === $rtl_rc && false !== strpos( $rtl_out, 'rtl_mixed_direction_review_complete=true' ), 'RTL reviewed acceptance explicitly requires mixed-direction evidence' );

// Negative: no in-tree acceptance artifact and no overwrite of prior evidence.
$inside = $root . '/tests/i18n/.review-acceptance-negative'; lra_rm( $inside );
list( $inside_rc, $inside_out ) = lra_exec(
    'SOURCE_DATE_EPOCH=1787356800 python3 ' . escapeshellarg( $accept ) . ' ' . escapeshellarg( $root ) . ' es_ES'
    . ' --candidate-intake ' . escapeshellarg( $candidate_zip ) . ' --review-evidence ' . escapeshellarg( $evidence_file )
    . ' --output ' . escapeshellarg( $inside )
);
lra_check( 0 !== $inside_rc && ! file_exists( $inside ), 'plugin-tree reviewed acceptance output is rejected without leaving artifacts' );
$existing_parent = $base . '/existing'; mkdir( $existing_parent );
file_put_contents( $existing_parent . '/certloom-es_ES-reviewed-acceptance.zip', 'preserve me' );
list( $existing_rc, $existing_out ) = lra_exec(
    'SOURCE_DATE_EPOCH=1787356800 python3 ' . escapeshellarg( $accept ) . ' ' . escapeshellarg( $root ) . ' es_ES'
    . ' --candidate-intake ' . escapeshellarg( $candidate_zip ) . ' --review-evidence ' . escapeshellarg( $evidence_file )
    . ' --output ' . escapeshellarg( $existing_parent . '/es_ES' )
);
lra_check( 0 !== $existing_rc && 'preserve me' === file_get_contents( $existing_parent . '/certloom-es_ES-reviewed-acceptance.zip' ), 'existing review-acceptance ZIP is never overwritten' );

$empty_output = $base . '/precreated-empty';
mkdir( $empty_output );
list( $empty_rc, $empty_out ) = lra_exec(
    'SOURCE_DATE_EPOCH=1787356800 python3 ' . escapeshellarg( $accept ) . ' ' . escapeshellarg( $root ) . ' es_ES'
    . ' --candidate-intake ' . escapeshellarg( $candidate_zip ) . ' --review-evidence ' . escapeshellarg( $evidence_file )
    . ' --output ' . escapeshellarg( $empty_output )
);
lra_check( 0 !== $empty_rc && is_dir( $empty_output ) && array() === array_values( array_diff( scandir( $empty_output ), array( '.', '..' ) ) ), 'pre-created output directory is rejected without deleting or populating operator state' );

$candidate_link = $base . '/candidate-link.zip';
symlink( $candidate_zip, $candidate_link );
list( $link_rc, $link_out ) = lra_exec(
    'SOURCE_DATE_EPOCH=1787356800 python3 ' . escapeshellarg( $accept ) . ' ' . escapeshellarg( $root ) . ' es_ES'
    . ' --candidate-intake ' . escapeshellarg( $candidate_link ) . ' --review-evidence ' . escapeshellarg( $evidence_file )
    . ' --output ' . escapeshellarg( $base . '/link-out' )
);
lra_check( 0 !== $link_rc && false !== strpos( $link_out, 'symbolic link' ), 'candidate intake symlink ambiguity fails closed before path resolution' );

list( $epoch_rc, $epoch_out ) = lra_exec(
    'env -u SOURCE_DATE_EPOCH python3 ' . escapeshellarg( $accept ) . ' ' . escapeshellarg( $root ) . ' es_ES'
    . ' --candidate-intake ' . escapeshellarg( $candidate_zip ) . ' --review-evidence ' . escapeshellarg( $evidence_file )
    . ' --output ' . escapeshellarg( $base . '/no-epoch' )
);
lra_check( 0 !== $epoch_rc && false !== strpos( $epoch_out, 'SOURCE_DATE_EPOCH' ), 'review-acceptance ZIP requires explicit deterministic epoch' );

lra_rm( $base );
printf( "Language review acceptance: %d/%d checks passed.\n", $checks, $checks );
