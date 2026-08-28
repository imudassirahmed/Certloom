<?php
/** I18N-03C human-return candidate intake regression. */
$root = dirname( __DIR__ );
$checks = 0;
function lci_check( $condition, $message ) {
    global $checks;
    ++$checks;
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$message}\n" );
        exit( 1 );
    }
}
function lci_exec( $command ) {
    $out = array(); $rc = 0;
    exec( $command . ' 2>&1', $out, $rc );
    return array( $rc, implode( "\n", $out ) );
}
function lci_rm( $path ) {
    if ( file_exists( $path ) || is_link( $path ) ) {
        exec( 'rm -rf ' . escapeshellarg( $path ) );
    }
}
function lci_copy_with( $source, $target, $search, $replace ) {
    $data = file_get_contents( $source );
    file_put_contents( $target, str_replace( $search, $replace, $data ) );
}

$stage = $root . '/tests/i18n/stage_language_candidate.py';
$review = $root . '/tests/i18n/make_review_kit.py';
$validator = $root . '/tests/i18n/validate_language_packs.py';
$fixture = $root . '/tests/i18n/fixtures/certloom-es_ES-candidate.po';
$manifest = $root . '/languages/certloom-language-packs.json';
$pot = $root . '/languages/certloom.pot';

lci_check( is_file( $stage ), 'candidate intake tool exists' );
lci_check( is_file( $review ), 'review-kit generator exists' );
lci_check( is_file( $fixture ), 'partial human-return QA fixture exists' );
$source_stage = file_get_contents( $stage );
lci_check( false !== strpos( $source_stage, 'reviewed_status_granted' ), 'candidate intake explicitly records that reviewed status is not granted' );
lci_check( false !== strpos( $source_stage, 'source_key_set_sha256' ), 'candidate intake binds whole source-key-set identity' );
lci_check( false !== strpos( $source_stage, 'returned PO may not be a symbolic link' ), 'returned translation evidence rejects symlink ambiguity' );
lci_check( false !== strpos( $source_stage, 'MAX_RETURNED_PO' ), 'returned PO has a bounded intake size' );
lci_check( false !== strpos( $source_stage, 'candidate intake output must be outside the Certloom plugin tree' ), 'candidate evidence cannot be written into the plugin tree' );

$base = sys_get_temp_dir() . '/certloom-language-candidate-' . bin2hex( random_bytes( 6 ) );
mkdir( $base, 0700, true );
$kit_dir = $base . '/kit-es';
list( $kit_rc, $kit_out ) = lci_exec(
    'SOURCE_DATE_EPOCH=1787356800 python3 ' . escapeshellarg( $review ) . ' ' . escapeshellarg( $root ) . ' es_ES --output ' . escapeshellarg( $kit_dir )
);
lci_check( 0 === $kit_rc, 'exact current Spanish review kit is generated: ' . $kit_out );
$kit_zip = $base . '/certloom-es_ES-review-kit.zip';
lci_check( is_file( $kit_zip ), 'review-kit ZIP exists' );

$out1 = $base . '/intake-a/es_ES';
$cmd = 'SOURCE_DATE_EPOCH=1787356800 python3 ' . escapeshellarg( $stage ) . ' ' . escapeshellarg( $root ) . ' es_ES ' . escapeshellarg( $fixture )
    . ' --review-kit ' . escapeshellarg( $kit_zip ) . ' --output ' . escapeshellarg( $out1 )
    . ' --submitted-by ' . escapeshellarg( 'Certloom QA fixture' ) . ' --submitted-at 2026-08-28';
list( $stage_rc, $stage_out ) = lci_exec( $cmd );
lci_check( 0 === $stage_rc, 'valid human-return candidate stages successfully: ' . $stage_out );
$result = json_decode( $stage_out, true );
lci_check( is_array( $result ) && 'candidate' === ( $result['status'] ?? '' ), 'staged return remains candidate status' );
lci_check( false === ( $result['reviewed_status_granted'] ?? true ), 'staging never grants reviewed status' );
lci_check( false === ( $result['human_review_complete'] ?? true ), 'staging never claims human review complete' );
lci_check( 3864 === (int) ( $result['source_strings'] ?? 0 ), 'candidate binds all 3,864 current source strings' );
lci_check( 6 === (int) ( $result['translated_entries_complete'] ?? 0 ), 'candidate reports exact complete translated-entry count' );
lci_check( 7 === (int) ( $result['translated_values_nonempty'] ?? 0 ), 'candidate reports exact translated value count including plurals' );
lci_check( abs( 0.16 - (float) ( $result['coverage_percent'] ?? 0 ) ) < 0.001, 'candidate coverage remains truthful and partial' );
lci_check( 0 === (int) ( $result['fuzzy_entries'] ?? -1 ), 'candidate reports fuzzy count' );
lci_check( hash_file( 'sha256', $fixture ) === ( $result['submitted_po_sha256'] ?? '' ), 'exact returned PO bytes are provenance-bound' );

$files = array_values( array_diff( scandir( $out1 ), array( '.', '..' ) ) );
sort( $files );
lci_check( array( 'INTAKE-CHECKLIST.md', 'SHA256SUMS.txt', 'candidate-manifest-entry.json', 'candidate-report.json', 'candidate.po', 'submitted.po' ) === $files, 'candidate intake directory contains only the six expected evidence files' );
lci_check( file_get_contents( $fixture ) === file_get_contents( $out1 . '/submitted.po' ), 'submitted.po preserves exact human-return bytes' );
$candidate = file_get_contents( $out1 . '/candidate.po' );
lci_check( false !== strpos( $candidate, '"X-Certloom-Review-Status: candidate\\n"' ), 'normalized candidate PO has canonical candidate header' );
lci_check( false !== strpos( $candidate, '"X-Certloom-POT-SHA256: ' . hash_file( 'sha256', $pot ) . '\\n"' ), 'normalized candidate PO binds exact current POT' );
lci_check( false === strpos( $candidate, 'reviewed_by' ) && false === strpos( $candidate, 'reviewed_at' ), 'candidate PO contains no fabricated reviewer sign-off' );
lci_check( false === file_exists( $out1 . '/certloom-es_ES.mo' ), 'candidate intake does not compile or imply an accepted MO artifact' );
lci_check( 0 === count( glob( $out1 . '/*.json.json' ) ?: array() ), 'candidate intake does not emit runtime translation catalogs' );
$entry = json_decode( file_get_contents( $out1 . '/candidate-manifest-entry.json' ), true );
lci_check( 'candidate' === ( $entry['status'] ?? '' ) && empty( $entry['reviewed_by'] ) && empty( $entry['reviewed_at'] ), 'manifest proposal is candidate-only and carries no review identity' );
$current = json_decode( file_get_contents( $manifest ), true );
$spanish = null; foreach ( $current['targets'] as $target ) { if ( 'es_ES' === $target['locale'] ) { $spanish = $target; break; } }
lci_check( is_array( $spanish ) && 'planned' === $spanish['status'], 'successful external intake does not mutate the shipped language manifest' );

list( $count_rc, $count_out ) = lci_exec(
    'python3 -c ' . escapeshellarg( 'import sys; sys.path.insert(0, sys.argv[1]); from po_tools import parse_po; print(len(parse_po(sys.argv[2]))-1)' )
    . ' ' . escapeshellarg( $root . '/tests/i18n' ) . ' ' . escapeshellarg( $out1 . '/candidate.po' )
);
lci_check( 0 === $count_rc && '3864' === trim( $count_out ), 'normalized candidate PO contains the complete current source-key skeleton' );

$out2 = $base . '/intake-b/es_ES';
$cmd2 = str_replace( escapeshellarg( $out1 ), escapeshellarg( $out2 ), $cmd );
list( $stage2_rc, $stage2_out ) = lci_exec( $cmd2 );
lci_check( 0 === $stage2_rc, 'second independent candidate intake succeeds: ' . $stage2_out );
$zip1 = $base . '/intake-a/certloom-es_ES-candidate-intake.zip';
$zip2 = $base . '/intake-b/certloom-es_ES-candidate-intake.zip';
lci_check( hash_file( 'sha256', $zip1 ) === hash_file( 'sha256', $zip2 ), 'same return + provenance produces byte-identical deterministic intake ZIPs' );
list( $zip_rc, $zip_out ) = lci_exec( 'unzip -Z1 ' . escapeshellarg( $zip1 ) );
$zip_names = array_filter( explode( "\n", trim( $zip_out ) ) ); sort( $zip_names );
lci_check( 0 === $zip_rc && $files === $zip_names, 'candidate ZIP contains only canonical flat evidence paths' );

// Negative: unsafe plural expression must be rejected before it can reach WordPress/Jed.
$bad_plural = $base . '/bad-plural.po';
lci_copy_with( $fixture, $bad_plural, 'Plural-Forms: nplurals=2; plural=(n != 1);', 'Plural-Forms: nplurals=2; plural=(n != 1) || constructor;' );
list( $bad_plural_rc, $bad_plural_out ) = lci_exec(
    'SOURCE_DATE_EPOCH=1787356800 python3 ' . escapeshellarg( $stage ) . ' ' . escapeshellarg( $root ) . ' es_ES ' . escapeshellarg( $bad_plural )
    . ' --review-kit ' . escapeshellarg( $kit_zip ) . ' --output ' . escapeshellarg( $base . '/bad-plural-out' )
    . ' --submitted-by QA --submitted-at 2026-08-28'
);
lci_check( 0 !== $bad_plural_rc && false !== strpos( $bad_plural_out, 'unsupported syntax' ), 'translator-controlled plural expression rejects identifiers/call-capable syntax' );

$bad_placeholder = $base . '/bad-placeholder.po';
lci_copy_with( $fixture, $bad_placeholder, 'msgstr[1] "%d días"', 'msgstr[1] "días"' );
list( $bad_ph_rc, $bad_ph_out ) = lci_exec(
    'SOURCE_DATE_EPOCH=1787356800 python3 ' . escapeshellarg( $stage ) . ' ' . escapeshellarg( $root ) . ' es_ES ' . escapeshellarg( $bad_placeholder )
    . ' --review-kit ' . escapeshellarg( $kit_zip ) . ' --output ' . escapeshellarg( $base . '/bad-ph-out' )
    . ' --submitted-by QA --submitted-at 2026-08-28'
);
lci_check( 0 !== $bad_ph_rc && false !== strpos( $bad_ph_out, 'placeholder mismatch' ), 'candidate intake fails closed on printf placeholder loss' );

$bad_bidi = $base . '/bad-bidi.po';
$data = file_get_contents( $fixture );
$data = str_replace( 'msgstr "Guardar"', 'msgstr "Guardar' . "\u{202E}" . '"', $data );
file_put_contents( $bad_bidi, $data );
list( $bad_bidi_rc, $bad_bidi_out ) = lci_exec(
    'SOURCE_DATE_EPOCH=1787356800 python3 ' . escapeshellarg( $stage ) . ' ' . escapeshellarg( $root ) . ' es_ES ' . escapeshellarg( $bad_bidi )
    . ' --review-kit ' . escapeshellarg( $kit_zip ) . ' --output ' . escapeshellarg( $base . '/bad-bidi-out' )
    . ' --submitted-by QA --submitted-at 2026-08-28'
);
lci_check( 0 !== $bad_bidi_rc && false !== strpos( $bad_bidi_out, 'hidden bidi' ), 'candidate intake rejects hidden bidi controls' );

$stale = $base . '/stale.po';
file_put_contents( $stale, file_get_contents( $fixture ) . "\nmsgid \"Not a current Certloom source key\"\nmsgstr \"No\"\n" );
list( $stale_rc, $stale_out ) = lci_exec(
    'SOURCE_DATE_EPOCH=1787356800 python3 ' . escapeshellarg( $stage ) . ' ' . escapeshellarg( $root ) . ' es_ES ' . escapeshellarg( $stale )
    . ' --review-kit ' . escapeshellarg( $kit_zip ) . ' --output ' . escapeshellarg( $base . '/stale-out' )
    . ' --submitted-by QA --submitted-at 2026-08-28'
);
lci_check( 0 !== $stale_rc && false !== strpos( $stale_out, 'stale/non-source' ), 'candidate intake rejects stale/non-source translation keys' );

$wrong_language = $base . '/wrong-language.po';
lci_copy_with( $fixture, $wrong_language, 'Language: es_ES', 'Language: fr_FR' );
list( $wrong_rc, $wrong_out ) = lci_exec(
    'SOURCE_DATE_EPOCH=1787356800 python3 ' . escapeshellarg( $stage ) . ' ' . escapeshellarg( $root ) . ' es_ES ' . escapeshellarg( $wrong_language )
    . ' --review-kit ' . escapeshellarg( $kit_zip ) . ' --output ' . escapeshellarg( $base . '/wrong-out' )
    . ' --submitted-by QA --submitted-at 2026-08-28'
);
lci_check( 0 !== $wrong_rc && false !== strpos( $wrong_out, 'Language header' ), 'candidate intake rejects locale-mismatched returned PO' );

$empty = $base . '/empty.po';
$empty_data = file_get_contents( $fixture );
$empty_data = preg_replace( '/^msgstr "(?!")(.+)"$/m', 'msgstr ""', $empty_data );
$empty_data = preg_replace( '/^msgstr\[[0-9]+\] ".*"$/m', 'msgstr[0] ""', $empty_data );
// Ensure plural slots remain syntactically unique after the coarse replacement.
$empty_data = str_replace( "msgstr[0] \"\"\nmsgstr[0] \"\"", "msgstr[0] \"\"\nmsgstr[1] \"\"", $empty_data );
file_put_contents( $empty, $empty_data );
list( $empty_rc, $empty_out ) = lci_exec(
    'SOURCE_DATE_EPOCH=1787356800 python3 ' . escapeshellarg( $stage ) . ' ' . escapeshellarg( $root ) . ' es_ES ' . escapeshellarg( $empty )
    . ' --review-kit ' . escapeshellarg( $kit_zip ) . ' --output ' . escapeshellarg( $base . '/empty-out' )
    . ' --submitted-by QA --submitted-at 2026-08-28'
);
lci_check( 0 !== $empty_rc && false !== strpos( $empty_out, 'no translated values' ), 'empty returned catalog cannot be staged as candidate work' );

// Tamper with the exact review kit while leaving its original checksum file unchanged.
$tampered = $base . '/tampered-kit.zip';
$py = <<<'PY'
import sys, zipfile
src, dst = sys.argv[1], sys.argv[2]
with zipfile.ZipFile(src) as zin, zipfile.ZipFile(dst, 'w', zipfile.ZIP_DEFLATED) as zout:
    for info in zin.infolist():
        data = zin.read(info.filename)
        if info.filename == 'review-manifest.json': data = data.replace(b'"locale": "es_ES"', b'"locale": "fr_FR"')
        zout.writestr(info.filename, data)
PY;
list( $tamper_make_rc, $tamper_make_out ) = lci_exec( 'python3 -c ' . escapeshellarg( $py ) . ' ' . escapeshellarg( $kit_zip ) . ' ' . escapeshellarg( $tampered ) );
lci_check( 0 === $tamper_make_rc, 'tampered review-kit fixture can be constructed' );
list( $tamper_rc, $tamper_out ) = lci_exec(
    'SOURCE_DATE_EPOCH=1787356800 python3 ' . escapeshellarg( $stage ) . ' ' . escapeshellarg( $root ) . ' es_ES ' . escapeshellarg( $fixture )
    . ' --review-kit ' . escapeshellarg( $tampered ) . ' --output ' . escapeshellarg( $base . '/tamper-out' )
    . ' --submitted-by QA --submitted-at 2026-08-28'
);
lci_check( 0 !== $tamper_rc && false !== strpos( $tamper_out, 'checksum mismatch' ), 'tampered review-kit evidence is rejected' );

$inside = $root . '/tests/i18n/.candidate-intake-negative';
lci_rm( $inside );
list( $inside_rc, $inside_out ) = lci_exec(
    'SOURCE_DATE_EPOCH=1787356800 python3 ' . escapeshellarg( $stage ) . ' ' . escapeshellarg( $root ) . ' es_ES ' . escapeshellarg( $fixture )
    . ' --review-kit ' . escapeshellarg( $kit_zip ) . ' --output ' . escapeshellarg( $inside )
    . ' --submitted-by QA --submitted-at 2026-08-28'
);
lci_check( 0 !== $inside_rc && ! file_exists( $inside ), 'plugin-tree candidate output is rejected without leaving artifacts' );

$nonempty = $base . '/nonempty'; mkdir( $nonempty ); file_put_contents( $nonempty . '/keep.txt', 'operator evidence' );
list( $nonempty_rc, $nonempty_out ) = lci_exec(
    'SOURCE_DATE_EPOCH=1787356800 python3 ' . escapeshellarg( $stage ) . ' ' . escapeshellarg( $root ) . ' es_ES ' . escapeshellarg( $fixture )
    . ' --review-kit ' . escapeshellarg( $kit_zip ) . ' --output ' . escapeshellarg( $nonempty )
    . ' --submitted-by QA --submitted-at 2026-08-28'
);
lci_check( 0 !== $nonempty_rc && 'operator evidence' === file_get_contents( $nonempty . '/keep.txt' ), 'non-empty operator output is rejected without deleting prior evidence' );

$existing_parent = $base . '/existing-zip'; mkdir( $existing_parent );
$existing_out = $existing_parent . '/es_ES';
file_put_contents( $existing_parent . '/certloom-es_ES-candidate-intake.zip', 'do not overwrite' );
list( $existing_rc, $existing_out_text ) = lci_exec(
    'SOURCE_DATE_EPOCH=1787356800 python3 ' . escapeshellarg( $stage ) . ' ' . escapeshellarg( $root ) . ' es_ES ' . escapeshellarg( $fixture )
    . ' --review-kit ' . escapeshellarg( $kit_zip ) . ' --output ' . escapeshellarg( $existing_out )
    . ' --submitted-by QA --submitted-at 2026-08-28'
);
lci_check( 0 !== $existing_rc && 'do not overwrite' === file_get_contents( $existing_parent . '/certloom-es_ES-candidate-intake.zip' ), 'existing candidate ZIP is never overwritten' );

list( $epoch_rc, $epoch_out ) = lci_exec(
    'env -u SOURCE_DATE_EPOCH python3 ' . escapeshellarg( $stage ) . ' ' . escapeshellarg( $root ) . ' es_ES ' . escapeshellarg( $fixture )
    . ' --review-kit ' . escapeshellarg( $kit_zip ) . ' --output ' . escapeshellarg( $base . '/no-epoch' )
    . ' --submitted-by QA --submitted-at 2026-08-28'
);
lci_check( 0 !== $epoch_rc && false !== strpos( $epoch_out, 'SOURCE_DATE_EPOCH' ), 'candidate ZIP generation requires an explicit deterministic epoch' );

list( $date_rc, $date_out ) = lci_exec(
    'SOURCE_DATE_EPOCH=1787356800 python3 ' . escapeshellarg( $stage ) . ' ' . escapeshellarg( $root ) . ' es_ES ' . escapeshellarg( $fixture )
    . ' --review-kit ' . escapeshellarg( $kit_zip ) . ' --output ' . escapeshellarg( $base . '/bad-date' )
    . ' --submitted-by QA --submitted-at 2026-08-28T12:00:00'
);
lci_check( 0 !== $date_rc && false !== strpos( $date_out, 'YYYY-MM-DD' ), 'submission date must be normalized and auditable' );

list( $actor_rc, $actor_out ) = lci_exec(
    'SOURCE_DATE_EPOCH=1787356800 python3 ' . escapeshellarg( $stage ) . ' ' . escapeshellarg( $root ) . ' es_ES ' . escapeshellarg( $fixture )
    . ' --review-kit ' . escapeshellarg( $kit_zip ) . ' --output ' . escapeshellarg( $base . '/bad-actor' )
    . ' --submitted-by ' . escapeshellarg( "QA\nInjected" ) . ' --submitted-at 2026-08-28'
);
lci_check( 0 !== $actor_rc && false !== strpos( $actor_out, 'submitted_by' ), 'submission actor metadata rejects control-character injection' );

lci_rm( $base );
printf( "Language candidate intake: %d/%d checks passed.\n", $checks, $checks );
