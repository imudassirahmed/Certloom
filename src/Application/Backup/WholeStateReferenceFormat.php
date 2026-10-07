<?php
namespace Certloom\Application\Backup;

use Certloom\Application\Rendering\CanonicalJson;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Ordered, bounded references stream. Identity digests grant no account or object ownership. */
final class WholeStateReferenceFormat {
	const FORMAT = 'certloom-whole-state-references';
	const VERSION = 2;
	const MAX_LINE_BYTES = 8388608;
	const MAX_BYTES = 67108864;
	public function header( array $options, $version = self::VERSION ) {
		$valid = ( new WholeStateOptionRecord() )->validate_set( $options, WholeStateOptionInventory::keys( 'references' ) );
		return is_wp_error( $valid ) || ! in_array( $version, array( 1, 2 ), true ) ? $this->invalid() : $this->line( array( 'type' => 'manifest', 'format' => self::FORMAT, 'format_version' => $version, 'options' => $options ) );
	}
	public function record( array $row ) {
		$valid = ( new WholeStateReferenceRecord() )->validate( $row );
		return is_wp_error( $valid ) ? $valid : $this->line( array( 'type' => 'reference', 'data' => $row ) );
	}
	public function footer( array $counts, $hash ) {
		return $this->counts( $counts ) && WholeStateReferenceRecord::sha( $hash ) ? $this->line( array( 'type' => 'integrity', 'counts' => $counts, 'chain_sha256' => $hash ) ) : $this->invalid();
	}
	public static function next_hash( $hash, $line ) { return hash( 'sha256', $hash . $line ); }
	public static function empty_counts( $version = self::VERSION ) { $counts = array( 'account' => 0, 'object' => 0, 'image' => 0, 'font' => 0 ); return 1 === $version ? $counts : $counts + array( 'file' => 0, 'media_url' => 0 ); }
	public function analyze( $handle, callable $on_reference ) {
		if ( ! is_resource( $handle ) ) { return $this->invalid(); }
		$bytes = 0; $line = $this->read( $handle, $bytes );
		if ( ! is_string( $line ) ) { return $this->invalid(); }
		$head = $this->decode( $line );
		if ( is_wp_error( $head ) || ! $this->keys( $head, array( 'type', 'format', 'format_version', 'options' ) ) || 'manifest' !== $head['type'] || self::FORMAT !== $head['format'] || ! in_array( $head['format_version'], array( 1, 2 ), true ) || ! is_array( $head['options'] ) || is_wp_error( $this->header( $head['options'], $head['format_version'] ) ) ) { return $this->invalid(); }
		$hash = self::next_hash( '', $line ); $counts = self::empty_counts( $head['format_version'] ); $last = ''; $assets = array(); $codec = new WholeStateReferenceRecord(); $images = array(); $urls = array();
		while ( null !== ( $line = $this->read( $handle, $bytes ) ) ) {
			if ( is_wp_error( $line ) ) { return $line; }
			$row = $this->decode( $line ); if ( is_wp_error( $row ) ) { return $row; }
			if ( 'integrity' === ( $row['type'] ?? null ) ) {
				if ( ! $this->keys( $row, array( 'type', 'counts', 'chain_sha256' ) ) || ! is_array( $row['counts'] ) || ! $this->counts( $row['counts'] ) || $counts != $row['counts'] || ! WholeStateReferenceRecord::sha( $row['chain_sha256'] ) || ! hash_equals( $hash, $row['chain_sha256'] ) || null !== $this->read( $handle, $bytes ) ) { return $this->invalid(); }
				foreach ( $urls as $url ) { if ( ! isset( $images['attachment:' . $url['source_id']][$url['image_sha256']] ) ) { return $this->invalid(); } }
				return array( 'format_version' => $head['format_version'], 'counts' => $counts, 'options' => $head['options'], 'assets' => $assets, 'bytes' => $bytes );
			}
			if ( ! $this->keys( $row, array( 'type', 'data' ) ) || 'reference' !== $row['type'] || ! is_array( $row['data'] ) ) { return $this->invalid(); }
			$key = $codec->key( $row['data'] ); if ( is_wp_error( $key ) || strcmp( $key, $last ) <= 0 ) { return $this->invalid(); }
			$data = $row['data']; if ( ! array_key_exists( $data['kind'], $counts ) ) { return $this->invalid(); } ++$counts[ $data['kind'] ]; if ( ! $this->counts( $counts ) ) { return $this->invalid(); }
			if ( 'image' === $data['kind'] ) { $images[$data['reference']][$data['descriptor']['sha256']] = true; }
			if ( 'media_url' === $data['kind'] ) { $urls[] = $data; }
			if ( in_array( $data['kind'], array( 'image', 'font', 'file' ), true ) ) {
				$descriptor = 'font' === $data['kind'] ? array( 'sha256' => $data['sha256'], 'size' => $data['size'], 'format' => $data['format'] ) : $data['descriptor'];
				$sha = $descriptor['sha256'];
				if ( isset( $assets[ $sha ] ) && $assets[ $sha ] !== $descriptor ) {
					if ( $assets[$sha]['size'] !== $descriptor['size'] || ( ! isset( $descriptor['file_type'] ) && ! isset( $assets[$sha]['file_type'] ) ) ) { return $this->invalid(); }
					if ( isset( $descriptor['file_type'] ) ) { $descriptor = $assets[$sha]; }
				}
				$assets[ $sha ] = $descriptor; if ( count( $assets ) > WholeStateReferenceRecord::MAX_ASSETS ) { return $this->invalid(); }
			}
			$result = $on_reference( $data ); if ( is_wp_error( $result ) ) { return $result; }
			$last = $key; $hash = self::next_hash( $hash, $line );
		}
		return $this->invalid();
	}
	private function counts( array $c ) {
		if ( ! $this->keys( $c, array_keys( self::empty_counts( 1 ) ) ) && ! $this->keys( $c, array_keys( self::empty_counts( 2 ) ) ) ) { return false; }
		foreach ( $c as $v ) { if ( ! is_int( $v ) || $v < 0 ) { return false; } }
		return $c['account'] <= WholeStateReferenceRecord::MAX_ACCOUNTS && $c['object'] <= WholeStateReferenceRecord::MAX_OBJECTS && $c['image'] + $c['font'] + ( $c['file'] ?? 0 ) <= WholeStateReferenceRecord::MAX_ASSET_REFERENCES && ( $c['media_url'] ?? 0 ) <= WholeStateReferenceRecord::MAX_ASSET_REFERENCES;
	}
	private function read( $h, &$bytes ) { $line = fgets( $h, self::MAX_LINE_BYTES + 1 ); if ( false === $line ) { return feof( $h ) ? null : $this->invalid(); } if ( "\n" !== substr( $line, -1 ) || strlen( $line ) > self::MAX_LINE_BYTES || strlen( $line ) > self::MAX_BYTES - $bytes ) { return $this->invalid(); } $bytes += strlen( $line ); return $line; }
	private function line( array $a ) { try { $line = ( new CanonicalJson() )->encode( $a ) . "\n"; } catch ( \Throwable $e ) { return $this->invalid(); } return strlen( $line ) <= self::MAX_LINE_BYTES ? $line : $this->invalid(); }
	private function decode( $line ) { $row = json_decode( $line, true, 12 ); if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $row ) ) { return $this->invalid(); } $canonical = $this->line( $row ); return is_wp_error( $canonical ) || ! hash_equals( $canonical, $line ) ? $this->invalid() : $row; }
	private function keys( array $a, array $b ) { return count( $a ) === count( $b ) && ! array_diff( array_keys( $a ), $b ); }
	private function invalid() { return new \WP_Error( 'certloom_whole_reference_stream_invalid', __( 'This recovery snapshot has incomplete or changed installation references.', 'certloom' ) ); }
}
