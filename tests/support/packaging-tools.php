<?php
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }

/** Run argument vectors directly, preserving literal arguments and the parent environment. */
function certloom_test_process( array $arguments, array $overrides = array() ) {
	if ( ! $arguments ) { throw new InvalidArgumentException( 'A test executable is required.' ); }
	$environment = getenv();
	foreach ( $overrides as $key => $value ) {
		if ( ! preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/D', $key ) ) { throw new InvalidArgumentException( 'Invalid test environment key.' ); }
		if ( null === $value ) { unset( $environment[ $key ] ); } else { $environment[ $key ] = (string) $value; }
	}
	$process = proc_open( $arguments, array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'redirect', 1 ) ), $pipes, null, $environment, array( 'bypass_shell' => true ) );
	if ( ! is_resource( $process ) ) { throw new RuntimeException( 'Could not start the packaging test process.' ); }
	fclose( $pipes[0] );
	$output = stream_get_contents( $pipes[1] );
	fclose( $pipes[1] );
	return array( proc_close( $process ), $output );
}

/** The builder is a Bash program; on Windows prefer Git Bash over a WSL launcher. */
function certloom_test_packaging_bash( array $arguments, array $environment = array() ) {
	$bash = getenv( 'CERTLOOM_TEST_BASH' );
	if ( ! $bash && 'Windows' === PHP_OS_FAMILY ) {
		$candidate = ( getenv( 'ProgramFiles' ) ?: 'C:/Program Files' ) . '/Git/bin/bash.exe';
		if ( is_file( $candidate ) ) { $bash = $candidate; }
	}
	$environment['PATH'] = dirname( PHP_BINARY ) . PATH_SEPARATOR . ( $environment['PATH'] ?? getenv( 'PATH' ) );
	return certloom_test_process( array_merge( array( $bash ?: 'bash' ), $arguments ), $environment );
}

/** Authority fixtures contain regular files only; never follow links or overwrite another directory. */
function certloom_test_copy_authority_fixture( $source, $destination ) {
	if ( ! is_dir( $source ) || is_link( $source ) || file_exists( $destination ) || is_link( $destination ) ) { throw new RuntimeException( 'Authority fixture source/destination is unsafe.' ); }
	$entries = scandir( $source );
	foreach ( $entries as $name ) {
		if ( '.' === $name || '..' === $name ) { continue; }
		$path = $source . '/' . $name;
		if ( ! is_file( $path ) || is_link( $path ) ) { throw new RuntimeException( 'Authority fixtures must contain regular files only.' ); }
	}
	if ( ! mkdir( $destination, 0700, true ) ) { throw new RuntimeException( 'Could not create the authority fixture destination.' ); }
	foreach ( $entries as $name ) {
		if ( '.' !== $name && '..' !== $name && ! copy( $source . '/' . $name, $destination . '/' . $name ) ) { throw new RuntimeException( 'Could not copy an authority fixture.' ); }
	}
}

/** Delete only within an explicitly resolved fixture boundary, checking each directory before traversal. */
function certloom_test_remove_fixture_tree( $path, $boundary ) {
	if ( ! file_exists( $path ) && ! is_link( $path ) ) { return; }
	$root = realpath( $boundary );
	$parent = realpath( dirname( $path ) );
	if ( false === $root || false === $parent ) { throw new RuntimeException( 'Fixture cleanup boundary is unavailable.' ); }
	$normalize = static function ( $value ) { $value = str_replace( '\\', '/', $value ); return 'Windows' === PHP_OS_FAMILY ? strtolower( $value ) : $value; };
	$within = static function ( $value ) use ( $root, $normalize ) { $root = rtrim( $normalize( $root ), '/' ); $value = $normalize( $value ); return $value === $root || 0 === strpos( $value, $root . '/' ); };
	if ( ! $within( $parent . '/' . basename( $path ) ) ) { throw new RuntimeException( 'Refusing cleanup outside the fixture boundary.' ); }
	if ( is_link( $path ) ) {
		if ( ! unlink( $path ) && ! rmdir( $path ) ) { throw new RuntimeException( 'Could not remove a fixture link.' ); }
		return;
	}
	if ( ! $within( realpath( $path ) ) ) { throw new RuntimeException( 'Refusing traversal outside the fixture boundary.' ); }
	if ( is_dir( $path ) ) {
		if ( 'Windows' === PHP_OS_FAMILY && ! chmod( $path, 0777 ) ) { throw new RuntimeException( 'Could not make an owned fixture directory removable.' ); }
		foreach ( scandir( $path ) as $name ) { if ( '.' !== $name && '..' !== $name ) { certloom_test_remove_fixture_tree( $path . '/' . $name, $root ); } }
		if ( ! rmdir( $path ) ) { throw new RuntimeException( 'Could not remove a fixture directory.' ); }
	} else {
		// Frozen candidate fixtures are read-only. Windows requires clearing the
		// read-only attribute even though the validated owning directory is writable.
		if ( 'Windows' === PHP_OS_FAMILY && ! chmod( $path, 0666 ) ) { throw new RuntimeException( 'Could not make an owned fixture removable.' ); }
		if ( ! unlink( $path ) ) { throw new RuntimeException( 'Could not remove a fixture file.' ); }
	}
}

function certloom_test_zip_open( $path ) {
	if ( ! class_exists( 'ZipArchive' ) ) { throw new RuntimeException( 'Packaging tests require the PHP zip extension.' ); }
	$zip = new ZipArchive();
	if ( true !== $zip->open( $path ) ) { throw new RuntimeException( 'Could not open the packaging test ZIP.' ); }
	return $zip;
}

function certloom_test_zip_entries( $path ) {
	$zip = certloom_test_zip_open( $path );
	try { $entries = array(); for ( $i = 0; $i < $zip->numFiles; $i++ ) { $entries[] = $zip->getNameIndex( $i ); } return $entries; } finally { $zip->close(); }
}

function certloom_test_zip_read( $path, $entry ) {
	$zip = certloom_test_zip_open( $path );
	try { $bytes = $zip->getFromName( $entry ); if ( false === $bytes ) { throw new RuntimeException( 'Required packaging test ZIP entry is absent.' ); } return $bytes; } finally { $zip->close(); }
}

/** Native extraction requires a fresh/empty destination and rejects traversal and symbolic links. */
function certloom_test_zip_extract( $path, $destination ) {
	if ( is_link( $destination ) || ( file_exists( $destination ) && ( ! is_dir( $destination ) || count( scandir( $destination ) ) > 2 ) ) ) { throw new RuntimeException( 'Packaging extraction requires an empty destination.' ); }
	$zip = certloom_test_zip_open( $path );
	try {
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = str_replace( '\\', '/', $zip->getNameIndex( $i ) );
			if ( '' === $name || '/' === $name[0] || false !== strpos( $name, ':' ) || in_array( '..', explode( '/', $name ), true ) ) { throw new RuntimeException( 'Unsafe packaging ZIP entry.' ); }
			$zip->getExternalAttributesIndex( $i, $system, $attributes );
			if ( 3 === $system && 0120000 === ( ( $attributes >> 16 ) & 0170000 ) ) { throw new RuntimeException( 'Packaging test ZIP links are forbidden.' ); }
		}
		if ( ! $zip->extractTo( $destination ) ) { throw new RuntimeException( 'Could not extract the packaging test ZIP.' ); }
	} finally { $zip->close(); }
}
