<?php
/** I18N-03A language-pack quality pipeline regression. */
$root = dirname( __DIR__ );
$checks = 0;
function lpq_check( $condition, $message ) {
    global $checks;
    ++$checks;
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$message}\n" );
        exit( 1 );
    }
}
function lpq_exec( $command ) {
    $out = array(); $rc = 0;
    exec( $command . ' 2>&1', $out, $rc );
    return array( $rc, implode( "\n", $out ) );
}
function lpq_rm( $path ) {
    if ( file_exists( $path ) || is_link( $path ) ) { exec( 'rm -rf ' . escapeshellarg( $path ) ); }
}
$validator = $root . '/tests/i18n/validate_language_packs.py';
$tools = $root . '/tests/i18n/po_tools.py';
$manifest = $root . '/languages/certloom-language-packs.json';
$pot = $root . '/languages/certloom.pot';

lpq_check( is_file( $validator ), 'validator exists' );
lpq_check( is_file( $tools ), 'dependency-free PO/MO tools exist' );
lpq_check( is_file( $manifest ), 'shipped language-pack manifest exists' );
$data = json_decode( file_get_contents( $manifest ), true );
lpq_check( is_array( $data ) && 1 === (int) ( $data['schema_version'] ?? 0 ), 'manifest schema v1' );
lpq_check( 'certloom' === ( $data['textdomain'] ?? '' ), 'manifest binds certloom textdomain' );
lpq_check( hash_file( 'sha256', $pot ) === ( $data['source_pot_sha256'] ?? '' ), 'manifest binds exact POT bytes' );
lpq_check( 20 === count( $data['targets'] ?? array() ), 'twenty major-language targets are explicit' );
$locales = array_column( $data['targets'], 'locale' );
lpq_check( count( $locales ) === count( array_unique( $locales ) ), 'target locales are unique' );
foreach ( array( 'ar', 'ur', 'fa_IR', 'he_IL' ) as $rtl ) {
    $row = null; foreach ( $data['targets'] as $target ) { if ( $rtl === $target['locale'] ) { $row = $target; break; } }
    lpq_check( is_array( $row ) && 'rtl' === $row['direction'], $rtl . ' is explicitly RTL' );
}
foreach ( $data['targets'] as $target ) {
    lpq_check( 'planned' === $target['status'], 'no language is falsely marketed as reviewed before translation review: ' . $target['locale'] );
}


$bootstrap = file_get_contents( $root . '/certloom.php' );
lpq_check( false !== strpos( $bootstrap, "function ecapla_certverify_load_textdomain()" ), 'reviewed bundled PHP packs have an explicit runtime loading boundary' );
lpq_check( false !== strpos( $bootstrap, "load_plugin_textdomain( 'certloom'" ), 'runtime loader uses the canonical textdomain' );
$init_pos = strpos( $bootstrap, "function ecapla_certverify_init()" );
$load_pos = strpos( $bootstrap, "ecapla_certverify_load_textdomain();", $init_pos );
$restore_pos = strpos( $bootstrap, "Ecapla_CertVerify_Backup::recover_interrupted_restore()", $init_pos );
lpq_check( false !== $load_pos && false !== $restore_pos && $load_pos < $restore_pos, 'textdomain is registered before init can emit translated provisioning errors' );
$build_script = file_get_contents( $root . '/tests/build-release.sh' );
lpq_check( false !== strpos( $build_script, 'validate_language_packs.py' ), 'deterministic release builder validates language-pack status/artifacts' );

list( $rc, $output ) = lpq_exec( 'python3 ' . escapeshellarg( $validator ) . ' ' . escapeshellarg( $root ) . ' --json' );
lpq_check( 0 === $rc, 'production manifest validates: ' . $output );
$report = json_decode( $output, true );
lpq_check( 4517 === (int) ( $report['source_strings'] ?? 0 ), 'quality pipeline sees all 4,517 source strings' );
lpq_check( empty( $report['errors'] ), 'production manifest has no quality errors' );

list( $fixture_rc, $fixture_output ) = lpq_exec( 'python3 ' . escapeshellarg( $validator ) . ' ' . escapeshellarg( $root ) . ' --manifest tests/i18n/fixtures/candidate-manifest.json --json' );
lpq_check( 0 === $fixture_rc, 'partial LTR/RTL candidate fixtures validate without being called reviewed: ' . $fixture_output );
$fixture_report = json_decode( $fixture_output, true );
lpq_check( 2 === count( $fixture_report['packs'] ?? array() ), 'candidate fixture exercises two scripts' );
lpq_check( ( $fixture_report['packs'][0]['coverage'] ?? 100 ) < 1, 'candidate coverage is truthfully partial' );

$base = sys_get_temp_dir() . '/certloom-language-pack-' . bin2hex( random_bytes( 6 ) );
mkdir( $base . '/languages', 0700, true );
mkdir( $base . '/tests/i18n', 0700, true );
copy( $validator, $base . '/tests/i18n/validate_language_packs.py' );
copy( $tools, $base . '/tests/i18n/po_tools.py' );
$mini_pot = <<<'POT'
msgid ""
msgstr ""
"Project-Id-Version: Certloom\n"
"MIME-Version: 1.0\n"
"Content-Type: text/plain; charset=UTF-8\n"
"Plural-Forms: nplurals=2; plural=(n != 1);\n"
"X-Domain: certloom\n"

msgid "Save"
msgstr ""

#. translators: %d: days.
msgid "%d day"
msgid_plural "%d days"
msgstr[0] ""
msgstr[1] ""

#: blocks/certloom/index.js:51
msgctxt "search action button"
msgid "Search"
msgstr ""
POT;
file_put_contents( $base . '/languages/certloom.pot', $mini_pot );
$mini_sha = hash_file( 'sha256', $base . '/languages/certloom.pot' );
$po = 'msgid ""' . "\n" . 'msgstr ""' . "\n" .
    '"Project-Id-Version: Certloom\\n"' . "\n" .
    '"Language: es_ES\\n"' . "\n" .
    '"MIME-Version: 1.0\\n"' . "\n" .
    '"Content-Type: text/plain; charset=UTF-8\\n"' . "\n" .
    '"Plural-Forms: nplurals=2; plural=(n != 1);\\n"' . "\n" .
    '"X-Domain: certloom\\n"' . "\n" .
    '"X-Certloom-Pack-Schema: 1\\n"' . "\n" .
    '"X-Certloom-POT-SHA256: ' . $mini_sha . '\\n"' . "\n" .
    '"X-Certloom-Review-Status: reviewed\\n"' . "\n" .
    '"X-Certloom-Direction: ltr\\n"' . "\n\n" .
    'msgid "Save"' . "\n" . 'msgstr "Guardar"' . "\n\n" .
    'msgid "%d day"' . "\n" . 'msgid_plural "%d days"' . "\n" . 'msgstr[0] "%d día"' . "\n" . 'msgstr[1] "%d días"' . "\n\n" .
    'msgctxt "search action button"' . "\n" . 'msgid "Search"' . "\n" . 'msgstr "Buscar"' . "\n";
file_put_contents( $base . '/languages/certloom-es_ES.po', $po );
$mini_manifest = array(
    'schema_version' => 1,
    'textdomain' => 'certloom',
    'source_pot_sha256' => $mini_sha,
    'targets' => array( array(
        'locale' => 'es_ES', 'language_name' => 'Spanish reviewed fixture', 'direction' => 'ltr', 'status' => 'reviewed',
        'po' => 'languages/certloom-es_ES.po', 'mo' => 'languages/certloom-es_ES.mo',
        'reviewed_by' => 'Certloom QA fixture', 'reviewed_at' => '2026-08-28', 'coverage_percent' => 100,
    ) ),
);
file_put_contents( $base . '/languages/certloom-language-packs.json', json_encode( $mini_manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
list( $reviewed_rc, $reviewed_output ) = lpq_exec( 'python3 ' . escapeshellarg( $base . '/tests/i18n/validate_language_packs.py' ) . ' ' . escapeshellarg( $base ) . ' --compile --json' );
lpq_check( 0 === $reviewed_rc, 'complete reviewed fixture validates and compiles: ' . $reviewed_output );
lpq_check( is_file( $base . '/languages/certloom-es_ES.mo' ) && filesize( $base . '/languages/certloom-es_ES.mo' ) > 100, 'deterministic MO artifact is emitted' );
$json_files = glob( $base . '/languages/certloom-es_ES-*.json' );
lpq_check( 1 === count( $json_files ), 'WordPress block-editor JSON catalog is emitted' );
$json_data = json_decode( file_get_contents( $json_files[0] ), true );
lpq_check( 'Buscar' === ( $json_data['locale_data']['messages']["search action button\x04Search"][0] ?? '' ), 'contextual JS translation survives deterministic JSON compilation' );
$mo_hash = hash_file( 'sha256', $base . '/languages/certloom-es_ES.mo' );
list( $reviewed_again_rc, $reviewed_again_output ) = lpq_exec( 'python3 ' . escapeshellarg( $base . '/tests/i18n/validate_language_packs.py' ) . ' ' . escapeshellarg( $base ) . ' --compile --json' );
lpq_check( 0 === $reviewed_again_rc && $mo_hash === hash_file( 'sha256', $base . '/languages/certloom-es_ES.mo' ), 'MO compilation is deterministic: ' . $reviewed_again_output );

$bad_placeholder = str_replace( 'msgstr[1] "%d días"', 'msgstr[1] "días"', $po );
file_put_contents( $base . '/languages/certloom-es_ES.po', $bad_placeholder );
list( $bad_rc, $bad_out ) = lpq_exec( 'python3 ' . escapeshellarg( $base . '/tests/i18n/validate_language_packs.py' ) . ' ' . escapeshellarg( $base ) . ' --json' );
lpq_check( 0 !== $bad_rc && false !== strpos( $bad_out, 'placeholder mismatch' ), 'placeholder loss fails closed' );

$unsafe_plural = str_replace( 'Plural-Forms: nplurals=2; plural=(n != 1);', 'Plural-Forms: nplurals=2; plural=(n != 1) || constructor;', $po );
file_put_contents( $base . '/languages/certloom-es_ES.po', $unsafe_plural );
list( $plural_rc, $plural_out ) = lpq_exec( 'python3 ' . escapeshellarg( $base . '/tests/i18n/validate_language_packs.py' ) . ' ' . escapeshellarg( $base ) . ' --json' );
lpq_check( 0 !== $plural_rc && false !== strpos( $plural_out, 'unsupported syntax' ), 'translator-controlled Plural-Forms rejects identifiers/call-capable syntax before MO/Jed compilation' );

$bad_bidi = str_replace( 'msgstr "Guardar"', "msgstr \"Guardar\\u202E\"", $po );
file_put_contents( $base . '/languages/certloom-es_ES.po', $bad_bidi );
list( $bidi_rc, $bidi_out ) = lpq_exec( 'python3 ' . escapeshellarg( $base . '/tests/i18n/validate_language_packs.py' ) . ' ' . escapeshellarg( $base ) . ' --json' );
lpq_check( 0 !== $bidi_rc, 'hidden bidi-control injection fails closed' );

$fuzzy_po = str_replace( 'msgid "Save"', "#, fuzzy\nmsgid \"Save\"", $po );
file_put_contents( $base . '/languages/certloom-es_ES.po', $fuzzy_po );
list( $fuzzy_rc, $fuzzy_out ) = lpq_exec( 'python3 ' . escapeshellarg( $base . '/tests/i18n/validate_language_packs.py' ) . ' ' . escapeshellarg( $base ) . ' --json' );
lpq_check( 0 !== $fuzzy_rc && false !== strpos( $fuzzy_out, 'zero fuzzy' ), 'reviewed packs reject fuzzy entries' );

$partial_po = preg_replace( '/\nmsgctxt "search action button".*$/s', "\n", $po );
file_put_contents( $base . '/languages/certloom-es_ES.po', $partial_po );
list( $partial_rc, $partial_out ) = lpq_exec( 'python3 ' . escapeshellarg( $base . '/tests/i18n/validate_language_packs.py' ) . ' ' . escapeshellarg( $base ) . ' --json' );
lpq_check( 0 !== $partial_rc && false !== strpos( $partial_out, 'must contain every POT key' ), 'reviewed packs reject incomplete coverage' );

lpq_rm( $base );
printf( "Language pack quality: %d/%d checks passed.\n", $checks, $checks );
