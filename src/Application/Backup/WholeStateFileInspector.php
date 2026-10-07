<?php
namespace Certloom\Application\Backup;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Opaque email attachments remain private data, never executable or public media. */
final class WholeStateFileInspector {
	public function inspect( array $descriptor ) {
		$path = $descriptor['path'] ?? null;
		$expected = $descriptor; unset( $expected['path'] );
		if ( is_wp_error( ( new WholeStateReferenceRecord() )->validate( array( 'kind' => 'file', 'reference' => 'attachment:1', 'descriptor' => $expected ) ) ) || ! is_string( $path ) || false !== strpos( $path, "\0" ) || preg_match( '/^[a-z][a-z0-9+.-]*:\/\//i', $path ) || is_link( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) { throw new \RuntimeException( 'Unproven recovery attachment.' ); }
		clearstatcache( true, $path );
		if ( filesize( $path ) !== $expected['size'] || ! hash_equals( $expected['sha256'], (string) hash_file( 'sha256', $path ) ) ) { throw new \RuntimeException( 'Changed recovery attachment.' ); }
		return array( 'path' => $path ) + $expected;
	}
}
