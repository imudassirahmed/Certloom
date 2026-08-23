<?php
namespace Certloom\Application\Rendering;

use InvalidArgumentException;

/**
 * Re-proves local font identity from bytes before a renderer can parse them.
 *
 * This intentionally performs a small fail-closed SFNT envelope check rather
 * than attempting to become a complete OpenType parser. Full font import and
 * glyph-analysis policy remains owned by the later controlled font catalog.
 */
final class FontAssetInspector {
	const MAX_BYTES = 20971520; // 20 MiB per exact font face.
	const MAX_TABLES = 512;

	/** @return array{path:string,sha256:string,format:string,size:int} */
	public function inspect( $asset ) {
		if ( ! is_array( $asset ) || ! isset( $asset['path'], $asset['sha256'], $asset['format'], $asset['size'] ) ) {
			throw new InvalidArgumentException( 'Font resolver returned an incomplete local font asset.' );
		}

		$path = (string) $asset['path'];
		if ( '' === $path || false !== strpos( $path, "\0" ) || ! is_file( $path ) || ! is_readable( $path ) ) {
			throw new InvalidArgumentException( 'Resolved font is not a readable local file.' );
		}

		$size = filesize( $path );
		if ( false === $size || $size < 28 || $size > self::MAX_BYTES || (int) $size !== (int) $asset['size'] ) {
			throw new InvalidArgumentException( 'Resolved font byte size is invalid or outside the supported limit.' );
		}

		$sha256 = strtolower( trim( (string) $asset['sha256'] ) );
		$actual_sha256 = @hash_file( 'sha256', $path );
		if ( ! preg_match( '/^[0-9a-f]{64}$/', $sha256 ) || ! is_string( $actual_sha256 ) || ! hash_equals( $sha256, $actual_sha256 ) ) {
			throw new InvalidArgumentException( 'Resolved font content does not match its SHA-256 identity or could not be read safely.' );
		}

		$reported_format = strtolower( trim( (string) $asset['format'] ) );
		if ( ! in_array( $reported_format, array( 'ttf', 'otf' ), true ) ) {
			throw new InvalidArgumentException( 'Resolved font format must be ttf or otf.' );
		}

		$handle = @fopen( $path, 'rb' );
		if ( false === $handle ) {
			throw new InvalidArgumentException( 'Resolved font could not be opened for byte validation.' );
		}
		$header = (string) fread( $handle, 12 );
		fclose( $handle );
		if ( 12 !== strlen( $header ) ) {
			throw new InvalidArgumentException( 'Resolved font has a truncated SFNT header.' );
		}

		$signature = substr( $header, 0, 4 );
		if ( "\x00\x01\x00\x00" === $signature || 'true' === $signature ) {
			$detected_format = 'ttf';
		} elseif ( 'OTTO' === $signature ) {
			$detected_format = 'otf';
		} elseif ( in_array( $signature, array( 'ttcf', 'wOFF', 'wOF2' ), true ) ) {
			throw new InvalidArgumentException( 'Font collections and web-font containers are not accepted by the exact local font contract.' );
		} else {
			throw new InvalidArgumentException( 'Resolved font does not have a supported TrueType/OpenType SFNT signature.' );
		}
		if ( $reported_format !== $detected_format ) {
			throw new InvalidArgumentException( 'Resolved font format metadata does not match the font bytes.' );
		}

		$table_data = unpack( 'ncount', substr( $header, 4, 2 ) );
		$table_count = is_array( $table_data ) && isset( $table_data['count'] ) ? (int) $table_data['count'] : 0;
		$directory_bytes = 12 + ( 16 * $table_count );
		if ( $table_count < 1 || $table_count > self::MAX_TABLES || $size < $directory_bytes ) {
			throw new InvalidArgumentException( 'Resolved font has an invalid SFNT table directory.' );
		}
		$handle = @fopen( $path, 'rb' );
		if ( false === $handle ) {
			throw new InvalidArgumentException( 'Resolved font table directory could not be opened.' );
		}
		if ( 0 !== fseek( $handle, 12 ) ) {
			fclose( $handle );
			throw new InvalidArgumentException( 'Resolved font table directory could not be read.' );
		}
		$directory = (string) fread( $handle, 16 * $table_count );
		fclose( $handle );
		if ( strlen( $directory ) !== 16 * $table_count ) {
			throw new InvalidArgumentException( 'Resolved font table directory is truncated.' );
		}
		for ( $index = 0; $index < $table_count; $index++ ) {
			$record = substr( $directory, $index * 16, 16 );
			$range = unpack( 'Noffset/Nlength', substr( $record, 8, 8 ) );
			$offset = is_array( $range ) && isset( $range['offset'] ) ? (int) $range['offset'] : -1;
			$length = is_array( $range ) && isset( $range['length'] ) ? (int) $range['length'] : -1;
			if ( $offset < $directory_bytes || $length < 0 || $offset > $size || $length > $size - $offset ) {
				throw new InvalidArgumentException( 'Resolved font contains an out-of-bounds SFNT table record.' );
			}
		}

		return array(
			'path' => $path,
			'sha256' => $sha256,
			'format' => $detected_format,
			'size' => (int) $size,
		);
	}
}
