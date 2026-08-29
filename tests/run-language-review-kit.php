<?php
/** I18N-03B deterministic human-review intake kit regression. */
$root = dirname( __DIR__ );
$checks = 0;
function lrk_check( $condition, $message ) {
    global $checks;
    ++$checks;
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$message}\n" );
        exit( 1 );
    }
}
function lrk_exec( $command ) {
    $out = array(); $rc = 0;
    exec( $command . ' 2>&1', $out, $rc );
    return array( $rc, implode( "\n", $out ) );
}
function lrk_rm( $path ) {
    if ( file_exists( $path ) || is_link( $path ) ) { exec( 'rm -rf ' . escapeshellarg( $path ) ); }
}
$tool = $root . '/tests/i18n/make_review_kit.py';
$manifest_path = $root . '/languages/certloom-language-packs.json';
$pot_path = $root . '/languages/certloom.pot';

lrk_check( is_file( $tool ), 'review-kit generator exists' );
$tool_source = file_get_contents( $tool );
lrk_check( false !== strpos( $tool_source, 'review kits must be generated outside the Certloom plugin tree' ), 'tool refuses in-tree review-kit output' );
lrk_check( false !== strpos( $tool_source, 'translation_content_included' ), 'review manifest explicitly records absence of translation content' );
lrk_check( false !== strpos( $tool_source, 'printf_placeholders' ) && false !== strpos( $tool_source, 'html_tags' ), 'review index exposes placeholder and HTML risk metadata' );
lrk_check( false !== strpos( $tool_source, 'plural_printf_placeholders' ) && false !== strpos( $tool_source, 'plural_html_tags' ), 'review index exposes plural-source placeholder and HTML risk metadata' );
lrk_check( false !== strpos( $tool_source, 'spreadsheet_safe' ), 'review TSV explicitly hardens formula-like cells for spreadsheet use' );
lrk_check( false !== strpos( $tool_source, 'block-js' ), 'review index exposes Gutenberg JavaScript ownership risk' );

$base = sys_get_temp_dir() . '/certloom-review-kit-' . bin2hex( random_bytes( 6 ) );
mkdir( $base, 0700, true );
$epoch = '1787356800';
mkdir( $base . '/run-a', 0700 );
mkdir( $base . '/run-b', 0700 );
$cmd_a = 'SOURCE_DATE_EPOCH=' . $epoch . ' python3 ' . escapeshellarg( $tool ) . ' ' . escapeshellarg( $root ) . ' es_ES --output ' . escapeshellarg( $base . '/run-a/es' );
$cmd_b = 'SOURCE_DATE_EPOCH=' . $epoch . ' python3 ' . escapeshellarg( $tool ) . ' ' . escapeshellarg( $root ) . ' es_ES --output ' . escapeshellarg( $base . '/run-b/es' );
list( $rc_a, $out_a ) = lrk_exec( $cmd_a );
list( $rc_b, $out_b ) = lrk_exec( $cmd_b );
lrk_check( 0 === $rc_a && 0 === $rc_b, 'Spanish review kit builds twice: ' . $out_a . ' ' . $out_b );
$report_a = json_decode( $out_a, true );
$report_b = json_decode( $out_b, true );
lrk_check( is_array( $report_a ) && 'es_ES' === ( $report_a['locale'] ?? '' ), 'Spanish review kit reports exact locale' );
lrk_check( 4262 === (int) ( $report_a['source_strings'] ?? 0 ), 'review kit binds all 4,262 source strings' );
lrk_check( hash_file( 'sha256', $pot_path ) === ( $report_a['pot_sha256'] ?? '' ), 'review kit binds exact Certloom POT SHA-256' );
lrk_check( ( $report_a['zip_sha256'] ?? '' ) === ( $report_b['zip_sha256'] ?? '' ), 'same locale review kit ZIP is deterministic across independent outputs' );
lrk_check( hash_file( 'sha256', $base . '/run-b/certloom-es_ES-review-kit.zip' ) === ( $report_b['zip_sha256'] ?? '' ), 'reported review-kit ZIP SHA-256 matches delivered bytes' );

$kit = $base . '/run-a/es';
foreach ( array( 'REVIEW-CHECKLIST.md', 'SHA256SUMS.txt', 'certloom.pot', 'review-manifest.json', 'strings.tsv' ) as $name ) {
    lrk_check( is_file( $kit . '/' . $name ), 'review kit contains ' . $name );
}
lrk_check( hash_file( 'sha256', $pot_path ) === hash_file( 'sha256', $kit . '/certloom.pot' ), 'review kit copies the canonical POT byte-for-byte' );
$kit_manifest = json_decode( file_get_contents( $kit . '/review-manifest.json' ), true );
lrk_check( false === ( $kit_manifest['translation_content_included'] ?? true ), 'review manifest does not pretend translation content exists' );
lrk_check( true === ( $kit_manifest['review_required'] ?? false ), 'review manifest explicitly requires human review' );
lrk_check( true === ( $kit_manifest['strings_tsv_is_spreadsheet_safe'] ?? false ), 'review manifest records spreadsheet-safe TSV treatment' );
lrk_check( 'ltr' === ( $kit_manifest['direction'] ?? '' ), 'Spanish review kit keeps LTR policy' );
lrk_check( preg_match( '/^[a-f0-9]{64}$/', $kit_manifest['source_key_set_sha256'] ?? '' ) === 1, 'review manifest binds the canonical source-key set' );
lrk_check( ( $kit_manifest['printf_strings'] ?? 0 ) > 0 && ( $kit_manifest['html_strings'] ?? 0 ) > 0 && ( $kit_manifest['block_javascript_strings'] ?? 0 ) > 0, 'review manifest counts high-risk printf/HTML/block-JS source strings' );
$tsv = file_get_contents( $kit . '/strings.tsv' );
lrk_check( false !== strpos( $tsv, "source_key_sha256\tcontext\tsource\tsource_plural" ) && false !== strpos( $tsv, "printf_placeholders\tplural_printf_placeholders\thtml_tags\tplural_html_tags\trisk_labels" ), 'TSV has deterministic singular/plural risk columns' );
lrk_check( preg_match( '/\tplural\b.*\t[^\t]*%[^\t]*\t/s', $tsv ) || false !== strpos( $tsv, 'plural_printf_placeholders' ), 'TSV carries plural-risk review metadata without dropping plural source structure' );
lrk_check( false !== strpos( $tsv, 'block-js' ), 'TSV identifies block-editor JavaScript strings' );
lrk_check( false !== strpos( file_get_contents( $kit . '/REVIEW-CHECKLIST.md' ), 'must not imply accreditation, compliance, renderer authority or disclosure' ), 'review checklist protects Certloom authority semantics' );

mkdir( $base . '/ar-run', 0700 );
list( $ar_rc, $ar_out ) = lrk_exec( 'SOURCE_DATE_EPOCH=' . $epoch . ' python3 ' . escapeshellarg( $tool ) . ' ' . escapeshellarg( $root ) . ' ar --output ' . escapeshellarg( $base . '/ar-run/ar-kit' ) );
lrk_check( 0 === $ar_rc, 'Arabic review kit builds: ' . $ar_out );
$ar_manifest = json_decode( file_get_contents( $base . '/ar-run/ar-kit/review-manifest.json' ), true );
lrk_check( 'rtl' === ( $ar_manifest['direction'] ?? '' ), 'Arabic review kit preserves RTL policy' );
lrk_check( false !== strpos( file_get_contents( $base . '/ar-run/ar-kit/REVIEW-CHECKLIST.md' ), 'hidden bidi embedding/override/isolate controls' ), 'RTL review checklist forbids hidden bidi forcing' );

list( $unknown_rc, $unknown_out ) = lrk_exec( 'python3 ' . escapeshellarg( $tool ) . ' ' . escapeshellarg( $root ) . ' xx_XX --output ' . escapeshellarg( $base . '/unknown' ) );
lrk_check( 0 !== $unknown_rc && false !== strpos( $unknown_out, 'not an approved Certloom language-pack target' ), 'unknown locale fails closed' );
list( $inside_rc, $inside_out ) = lrk_exec( 'python3 ' . escapeshellarg( $tool ) . ' ' . escapeshellarg( $root ) . ' es_ES --output ' . escapeshellarg( $root . '/tests/i18n/.bad-review-kit' ) );
lrk_check( 0 !== $inside_rc && false !== strpos( $inside_out, 'outside the Certloom plugin tree' ), 'in-tree review kit fails closed' );
lrk_check( ! file_exists( $root . '/tests/i18n/.bad-review-kit' ), 'failed in-tree generation leaves source clean' );

$isolated = $base . '/isolated';
mkdir( $isolated . '/languages', 0700, true );
mkdir( $isolated . '/tests/i18n', 0700, true );
foreach ( array( 'make_review_kit.py', 'po_tools.py', 'validate_language_packs.py' ) as $name ) { copy( $root . '/tests/i18n/' . $name, $isolated . '/tests/i18n/' . $name ); }
copy( $pot_path, $isolated . '/languages/certloom.pot' );
$manifest = json_decode( file_get_contents( $manifest_path ), true );
$manifest['source_pot_sha256'] = str_repeat( '0', 64 );
file_put_contents( $isolated . '/languages/certloom-language-packs.json', json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
list( $stale_rc, $stale_out ) = lrk_exec( 'python3 ' . escapeshellarg( $isolated . '/tests/i18n/make_review_kit.py' ) . ' ' . escapeshellarg( $isolated ) . ' es_ES --output ' . escapeshellarg( $base . '/stale' ) );
lrk_check( 0 !== $stale_rc && false !== strpos( $stale_out, 'source_pot_sha256 does not match languages/certloom.pot' ), 'stale manifest/POT identity fails closed' );


mkdir( $base . '/existing-zip-parent', 0700 );
file_put_contents( $base . '/existing-zip-parent/certloom-es_ES-review-kit.zip', 'do-not-overwrite' );
list( $zip_exists_rc, $zip_exists_out ) = lrk_exec( 'python3 ' . escapeshellarg( $tool ) . ' ' . escapeshellarg( $root ) . ' es_ES --output ' . escapeshellarg( $base . '/existing-zip-parent/es' ) );
lrk_check( 0 !== $zip_exists_rc && false !== strpos( $zip_exists_out, 'ZIP target already exists' ), 'existing review ZIP target fails closed before generation' );
lrk_check( ! file_exists( $base . '/existing-zip-parent/es' ), 'existing review ZIP failure leaves no partial review directory' );

list( $epoch_rc, $epoch_out ) = lrk_exec( 'SOURCE_DATE_EPOCH=not-an-integer python3 ' . escapeshellarg( $tool ) . ' ' . escapeshellarg( $root ) . ' es_ES --output ' . escapeshellarg( $base . '/bad-epoch' ) );
lrk_check( 0 !== $epoch_rc && false !== strpos( $epoch_out, 'SOURCE_DATE_EPOCH must be an integer' ), 'invalid deterministic epoch fails closed' );
lrk_check( ! file_exists( $base . '/bad-epoch' ), 'invalid epoch fails before writing a partial review directory' );

mkdir( $base . '/nonempty', 0700 );
file_put_contents( $base . '/nonempty/keep.txt', 'keep' );
list( $nonempty_rc, $nonempty_out ) = lrk_exec( 'python3 ' . escapeshellarg( $tool ) . ' ' . escapeshellarg( $root ) . ' es_ES --output ' . escapeshellarg( $base . '/nonempty' ) );
lrk_check( 0 !== $nonempty_rc && false !== strpos( $nonempty_out, 'must be empty' ), 'non-empty output directory fails closed instead of deleting operator files' );

lrk_rm( $base );
printf( "Language review kit: %d/%d checks passed.\n", $checks, $checks );
