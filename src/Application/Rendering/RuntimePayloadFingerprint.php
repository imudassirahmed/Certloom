<?php
namespace Certloom\Application\Rendering;

use InvalidArgumentException;
use RuntimeException;

/**
 * Deterministic identity for the exact Certloom production payload that may be
 * authorized by a renderer release manifest.
 *
 * Development-only docs/tests and generated VCS/interpreter residue are
 * excluded exactly as the deterministic release builder excludes them. The
 * authority manifest itself is excluded to avoid a self-referential hash.
 */
final class RuntimePayloadFingerprint {
    const SCHEMA = 'certloom-runtime-payload';
    const VERSION = 1;
    const MAX_FILES = 4096;
    const MAX_FILE_BYTES = 67108864; // 64 MiB.
    const MAX_TOTAL_BYTES = 268435456; // 256 MiB.

    /** @return array<string,mixed> */
    public function fingerprint( $root ) {
        $real_root = realpath( (string) $root );
        if ( false === $real_root || ! is_dir( $real_root ) || ! is_readable( $real_root ) ) {
            throw new InvalidArgumentException( 'Certloom runtime payload root is invalid or unreadable.' );
        }
        $files = array();
        $total = 0;
        $this->walk( $real_root, '', $files, $total );
        usort( $files, function ( array $a, array $b ) { return strcmp( $a['path'], $b['path'] ); } );
        if ( 0 === count( $files ) ) {
            throw new RuntimeException( 'Certloom runtime payload contains no production files.' );
        }
        $identity = array(
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'files' => $files,
        );
        return $this->normalize( array(
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'sha256' => ( new CanonicalJson() )->hash( $identity ),
            'file_count' => count( $files ),
            'total_bytes' => $total,
        ) );
    }

    /** @return array<string,mixed> */
    public function normalize( array $value ) {
        $keys = array( 'schema', 'version', 'sha256', 'file_count', 'total_bytes' );
        if ( array_diff( array_keys( $value ), $keys ) || array_diff( $keys, array_keys( $value ) ) ) {
            throw new InvalidArgumentException( 'Certloom runtime payload fingerprint has an invalid shape.' );
        }
        if ( self::SCHEMA !== (string) $value['schema'] || ! is_int( $value['version'] ) || self::VERSION !== $value['version'] ) {
            throw new InvalidArgumentException( 'Certloom runtime payload fingerprint schema/version is unsupported.' );
        }
        $sha = strtolower( trim( (string) $value['sha256'] ) );
        if ( 1 !== preg_match( '/^[0-9a-f]{64}$/', $sha ) ) {
            throw new InvalidArgumentException( 'Certloom runtime payload fingerprint SHA-256 is invalid.' );
        }
        if ( ! is_int( $value['file_count'] ) || $value['file_count'] < 1 || $value['file_count'] > self::MAX_FILES ) {
            throw new InvalidArgumentException( 'Certloom runtime payload file count is invalid.' );
        }
        if ( ! is_int( $value['total_bytes'] ) || $value['total_bytes'] < 1 || $value['total_bytes'] > self::MAX_TOTAL_BYTES ) {
            throw new InvalidArgumentException( 'Certloom runtime payload byte count is invalid.' );
        }
        return array(
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'sha256' => $sha,
            'file_count' => $value['file_count'],
            'total_bytes' => $value['total_bytes'],
        );
    }

    /** @param array<int,array<string,mixed>> $files */
    private function walk( $root, $relative, array &$files, &$total ) {
        $dir = '' === $relative ? $root : $root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative );
        $entries = scandir( $dir );
        if ( false === $entries ) {
            throw new RuntimeException( 'Certloom runtime payload directory could not be enumerated.' );
        }
        foreach ( $entries as $name ) {
            if ( '.' === $name || '..' === $name ) { continue; }
            $rel = '' === $relative ? $name : $relative . '/' . $name;
            if ( $this->excluded( $rel, $name ) ) { continue; }
            $full = $root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $rel );
            if ( is_link( $full ) ) {
                throw new RuntimeException( 'Certloom runtime payload may not contain symbolic links: ' . $rel );
            }
            if ( is_dir( $full ) ) {
                $this->walk( $root, $rel, $files, $total );
                continue;
            }
            if ( ! is_file( $full ) || ! is_readable( $full ) ) {
                throw new RuntimeException( 'Certloom runtime payload contains a non-regular or unreadable entry: ' . $rel );
            }
            $size = filesize( $full );
            if ( false === $size || $size < 0 || $size > self::MAX_FILE_BYTES ) {
                throw new RuntimeException( 'Certloom runtime payload file size is outside the allowed range: ' . $rel );
            }
            $total += (int) $size;
            if ( $total > self::MAX_TOTAL_BYTES ) {
                throw new RuntimeException( 'Certloom runtime payload exceeds the total byte ceiling.' );
            }
            if ( count( $files ) >= self::MAX_FILES ) {
                throw new RuntimeException( 'Certloom runtime payload exceeds the file-count ceiling.' );
            }
            $hash = hash_file( 'sha256', $full );
            if ( ! is_string( $hash ) || 64 !== strlen( $hash ) ) {
                throw new RuntimeException( 'Certloom runtime payload file could not be hashed: ' . $rel );
            }
            $files[] = array( 'path' => str_replace( '\\', '/', $rel ), 'bytes' => (int) $size, 'sha256' => strtolower( $hash ) );
        }
    }

    private function excluded( $relative, $name ) {
        $relative = str_replace( '\\', '/', (string) $relative );
        if ( 'resources/renderer/accepted.json' === $relative ) { return true; }
        $parts = explode( '/', $relative );
        if ( isset( $parts[0] ) && ( 'docs' === $parts[0] || 'tests' === $parts[0] ) ) { return true; }
        foreach ( $parts as $part ) {
            if ( '.git' === $part || '.svn' === $part || '__pycache__' === $part ) { return true; }
        }
        if ( '.DS_Store' === $name || preg_match( '/\.(?:pyc|pyo)$/i', (string) $name ) ) { return true; }
        return false;
    }
}
