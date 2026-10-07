<?php
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
require_once __DIR__ . '/support/packaging-tools.php';

// Small process/data fixtures only. This does not build, copy or extract Certloom.
$checks = 0;
function pp_check( $condition, $message ) { global $checks; ++$checks; if ( ! $condition ) { throw new RuntimeException( $message ); } }
function pp_reject( callable $operation, $message ) { $rejected = false; try { $operation(); } catch ( RuntimeException $error ) { $rejected = true; } pp_check( $rejected, $message ); }
$base = dirname( __DIR__ ) . '/output/packaging-portability-' . bin2hex( random_bytes( 6 ) );
mkdir( $base, 0700, true );
$parent_value = getenv( 'CERTLOOM_PACKAGING_TEST_VALUE' );
try {
	$child = $base . '/argument echo & sample.php';
	file_put_contents( $child, '<?php echo json_encode(array("argument"=>$argv[1],"environment"=>getenv("CERTLOOM_PACKAGING_TEST_VALUE"),"path"=>false!==getenv("PATH")),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); fwrite(STDERR,"\nchild-stderr"); exit((int)$argv[2]);' );
	putenv( 'CERTLOOM_PACKAGING_TEST_VALUE=parent-fixture' );
	foreach ( array( 'plain', 'space value', 'quote" and apostrophe\'', '%PATH% !value! & | < >', '$(echo unsafe); `echo unsafe`', 'C:\\fixture path\\trailing\\', 'العربية café', '' ) as $argument ) {
		[$rc, $output] = certloom_test_process( array( PHP_BINARY, $child, $argument, '0' ), array( 'CERTLOOM_PACKAGING_TEST_VALUE' => 'child-fixture % ! & "' ) );
		$expected = json_encode( array( 'argument'=>$argument, 'environment'=>'child-fixture % ! & "', 'path'=>true ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\nchild-stderr";
		pp_check( 0 === $rc && $expected === $output, 'Child process must preserve literal arguments, environment and merged stderr.' );
	}
	pp_check( 'parent-fixture' === getenv( 'CERTLOOM_PACKAGING_TEST_VALUE' ), 'Child overrides must not change the parent environment.' );
	[$rc, $output] = certloom_test_process( array( PHP_BINARY, $child, 'nonzero', '23' ), array( 'CERTLOOM_PACKAGING_TEST_VALUE'=>null ) );
	pp_check( 23 === $rc && false === json_decode( explode( "\n", $output )[0], true )['environment'], 'Exit status and explicitly removed environment keys must survive.' );
	[$rc, $output] = certloom_test_packaging_bash( array( '-c', 'printf "%s" "$CERTLOOM_PACKAGING_TEST_VALUE"' ), array( 'CERTLOOM_PACKAGING_TEST_VALUE'=>'bash-fixture space & literal' ) );
	pp_check( 0 === $rc && 'bash-fixture space & literal' === $output, 'Git Bash must receive environment values without shell-built assignment prefixes: ' . json_encode( array( $rc, $output ) ) );
	[$rc, $output] = certloom_test_packaging_bash( array( '-c', "php -r 'echo PHP_VERSION;'" ) );
	pp_check( 0 === $rc && PHP_VERSION === $output, 'Builder subprocesses must resolve the same PHP executable.' );

	$source = $base . '/authority-fixture'; mkdir( $source ); file_put_contents( $source . '/note.json', "{\"fixture\":true}\n" );
	certloom_test_copy_authority_fixture( $source, $base . '/copy' );
	pp_check( file_get_contents( $source . '/note.json' ) === file_get_contents( $base . '/copy/note.json' ), 'Native fixture copy must preserve bytes.' );
	pp_reject( static function () use ( $source, $base ) { certloom_test_copy_authority_fixture( $source, $base . '/copy' ); }, 'Fixture copy must refuse an existing destination.' );
	mkdir( $source . '/nested' );
	pp_reject( static function () use ( $source, $base ) { certloom_test_copy_authority_fixture( $source, $base . '/bad-copy' ); }, 'Fixture copy must reject unexpected directories before copying.' );
	pp_check( ! file_exists( $base . '/bad-copy' ), 'Rejected copy must leave the destination absent.' );

	$zip = new ZipArchive(); pp_check( true === $zip->open( $base . '/good.zip', ZipArchive::CREATE ), 'Fixture ZIP must open.' );
	$binary = "fixture\0bytes\n"; $zip->addFromString( 'fixture/readme.txt', "readme\n" ); $zip->addFromString( 'fixture/data.bin', $binary ); $zip->close();
	pp_check( array( 'fixture/readme.txt', 'fixture/data.bin' ) === certloom_test_zip_entries( $base . '/good.zip' ), 'Native ZIP listing must retain entry names.' );
	pp_check( $binary === certloom_test_zip_read( $base . '/good.zip', 'fixture/data.bin' ), 'Native ZIP reads must retain binary bytes.' );
	certloom_test_zip_extract( $base . '/good.zip', $base . '/extracted' );
	pp_check( $binary === file_get_contents( $base . '/extracted/fixture/data.bin' ), 'Native ZIP extraction must retain binary bytes.' );
	pp_reject( static function () use ( $base ) { certloom_test_zip_extract( $base . '/good.zip', $base . '/extracted' ); }, 'Extraction must refuse a nonempty destination.' );
	foreach ( array( '../escape.txt', '/absolute.txt', 'C:/drive.txt', 'fixture/../../escape.txt', '..\\escape.txt', 'fixture/name:stream' ) as $i => $name ) {
		$zip = new ZipArchive(); $zip->open( $base . '/bad-' . $i . '.zip', ZipArchive::CREATE ); $zip->addFromString( $name, 'unsafe fixture' ); $zip->close();
		pp_reject( static function () use ( $base, $i ) { certloom_test_zip_extract( $base . '/bad-' . $i . '.zip', $base . '/bad-extracted-' . $i ); }, 'Unsafe archive paths must be rejected before extraction.' );
		pp_check( ! file_exists( $base . '/bad-extracted-' . $i ), 'Rejected archive must leave its destination absent.' );
	}
	$zip = new ZipArchive(); $zip->open( $base . '/link.zip', ZipArchive::CREATE ); $zip->addFromString( 'fixture/link', '../outside' ); $zip->setExternalAttributesName( 'fixture/link', 3, 0120777 << 16 ); $zip->close();
	pp_reject( static function () use ( $base ) { certloom_test_zip_extract( $base . '/link.zip', $base . '/link-extracted' ); }, 'ZIP symbolic links must be rejected.' );
	file_put_contents( $base . '/outside.txt', 'preserve outside boundary' );
	pp_reject( static function () use ( $base ) { certloom_test_remove_fixture_tree( $base . '/outside.txt', $base . '/extracted' ); }, 'Fixture cleanup must reject a resolved target outside its boundary.' );
	pp_check( 'preserve outside boundary' === file_get_contents( $base . '/outside.txt' ), 'Rejected cleanup must preserve outside data.' );
	certloom_test_remove_fixture_tree( $base . '/extracted', $base );
	pp_check( ! file_exists( $base . '/extracted' ) && is_file( $base . '/outside.txt' ), 'Recursive cleanup must remove only the allowed fixture subtree.' );
	echo "PASS  Packaging process/ZIP/fixture portability (no Certloom package build)\n\n{$checks} checks, 0 failures\n";
} finally {
	putenv( false === $parent_value ? 'CERTLOOM_PACKAGING_TEST_VALUE' : 'CERTLOOM_PACKAGING_TEST_VALUE=' . $parent_value );
	certloom_test_remove_fixture_tree( $base, $base );
}
