<?php
namespace Certloom\Infrastructure\Backup;

use Certloom\Application\Backup\WholeStateReferenceRecord;
use Certloom\Application\Rendering\AssetInspector;
use Certloom\Application\Rendering\FontAssetInspector;
use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Infrastructure\Files\PrivateJobFileStore;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Exact local bytes only. An issued descriptor may use recovered bytes, never a replacement attachment. */
final class WholeStateAssetRepository {
	/** URL authority includes uncached metadata even when a new path has identical bytes. */
	public function attachment_url_evidence( $id ) {
		if ( ! WholeStateReferenceRecord::id( $id ) ) { return $this->failed(); }
		global $wpdb; $wpdb->last_error = '';
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT CASE WHEN OCTET_LENGTH(meta_value)<=2048 THEN meta_value ELSE NULL END AS value FROM %i WHERE post_id=%d AND meta_key='_wp_attached_file' ORDER BY meta_id LIMIT 2", $wpdb->postmeta, $id ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) || 1 !== count( $rows ) || ! is_string( $rows[0]['value'] ?? null ) ) { return $this->failed(); }
		wp_cache_delete( $id, 'post_meta' ); wp_cache_delete( $id, 'posts' );
		$url = wp_get_attachment_url( $id ); if ( ! WholeStateReferenceRecord::media_url( $url ) ) { return $this->failed(); }
		return array( 'url' => $url, 'metadata_sha256' => hash( 'sha256', $rows[0]['value'] ) );
	}
	/** Static email files are compared by exact bytes; names and IDs grant no trust. */
	public function file( $reference ) {
		if ( ! is_string( $reference ) || 1 !== preg_match( '/^attachment:([1-9][0-9]{0,18})$/D', $reference, $match ) || (string) (int) $match[1] !== $match[1] ) { return $this->failed(); }
		$path = $this->attachment_path( (int) $match[1] );
		if ( is_wp_error( $path ) || ! \Certloom\Application\Email\EmailTemplateService::safe_attachment_extension( $path ) ) { return $this->failed(); }
		clearstatcache( true, $path ); $size = filesize( $path ); $sha = hash_file( 'sha256', $path );
		$descriptor = array( 'path' => $path, 'sha256' => $sha, 'size' => $size, 'file_type' => 'email_attachment' );
		try { return ( new \Certloom\Application\Backup\WholeStateFileInspector() )->inspect( $descriptor ); } catch ( \Throwable $e ) { return $this->failed(); }
	}
	public function image( $reference, $expected = null ) {
		if ( ! is_string( $reference ) || 1 !== preg_match( '/^attachment:([1-9][0-9]{0,18})$/D', $reference, $match ) || (string) (int) $match[1] !== $match[1] ) { return $this->failed(); }
		if ( null !== $expected ) { $v = ( new WholeStateReferenceRecord() )->validate( array( 'kind' => 'image', 'reference' => $reference, 'descriptor' => $expected ) ); if ( is_wp_error( $v ) ) { return $v; } }
		$current = $this->attachment( (int) $match[1] );
		if ( ! is_wp_error( $current ) && ( null === $expected || $this->matches( $current, $expected ) ) ) { return $current; }
		if ( null === $expected ) { return $current; }
		$path = $this->recovered_path( $expected['sha256'] );
		if ( is_wp_error( $path ) ) { return $this->failed(); }
		try { $recovered = ( new AssetInspector() )->inspect( array( 'path' => $path ) + $expected ); } catch ( \Throwable $e ) { return $this->failed(); }
		return $this->matches( $recovered, $expected ) ? $recovered : $this->failed();
	}
	public function font( $sha, $format ) {
		if ( ! WholeStateReferenceRecord::sha( $sha ) || ! in_array( $format, array( 'ttf', 'otf' ), true ) ) { return $this->failed(); }
		$uploads = wp_upload_dir( null, false );
		if ( empty( $uploads['error'] ) && is_string( $uploads['basedir'] ?? null ) ) {
			$root = $uploads['basedir'] . '/certloom-fonts'; $path = $root . '/' . $sha . '.' . $format;
			if ( ! is_link( $root ) && ! is_link( $path ) && is_file( $path ) && dirname( (string) realpath( $path ) ) === realpath( $root ) ) { $current = $this->inspect_font( $path, $sha, $format ); if ( ! is_wp_error( $current ) ) { return $current; } }
		}
		$path = $this->recovered_path( $sha ); if ( is_wp_error( $path ) ) { return $path; }
		return $this->inspect_font( $path, $sha, $format );
	}
	private function inspect_font( $path, $sha, $format ) {
		clearstatcache( true, $path ); $size = filesize( $path );
		if ( ! is_int( $size ) || $size > \Certloom\Application\Rendering\CustomFontLibrary::MAX_BYTES ) { return $this->failed(); }
		try { return ( new FontAssetInspector() )->inspect( array( 'path' => $path, 'sha256' => $sha, 'format' => $format, 'size' => $size ) ); } catch ( \Throwable $e ) { return $this->failed(); }
	}
	public function recovered_path( $sha ) {
		if ( ! WholeStateReferenceRecord::sha( $sha ) ) { return $this->failed(); }
		$private = ( new PrivateJobFileStore() )->directory(); if ( is_wp_error( $private ) ) { return $private; }
		$root = $private . '/recovery-assets'; $path = $root . '/' . $sha;
		if ( is_link( $root ) || is_link( $path ) || ! is_file( $path ) || dirname( (string) realpath( $root ) ) !== realpath( $private ) || dirname( (string) realpath( $path ) ) !== realpath( $root ) ) { return $this->failed(); }
		clearstatcache( true, $path ); $bytes = filesize( $path );
		return is_int( $bytes ) && $bytes > 0 && $bytes <= 67108864 && hash_equals( $sha, (string) hash_file( 'sha256', $path ) ) ? $path : $this->failed();
	}
	private function attachment( $id ) {
		global $wpdb; $wpdb->last_error = '';
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT post_mime_type FROM %i WHERE ID=%d AND post_type='attachment'", $wpdb->posts, $id ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $row ) || ! in_array( $row['post_mime_type'], array( 'image/png', 'image/jpeg' ), true ) ) { return $this->failed(); }
		$path = $this->attachment_path( $id ); if ( is_wp_error( $path ) ) { return $path; }
		clearstatcache( true, $path ); $size = filesize( $path );
		if ( ! is_int( $size ) || $size < 1 || $size > AssetInspector::MAX_BYTES ) { return $this->failed(); }
		try { return ( new AssetInspector() )->inspect( array( 'path' => $path, 'sha256' => hash_file( 'sha256', $path ), 'mime' => $row['post_mime_type'], 'size' => $size ) ); } catch ( \Throwable $e ) { return $this->failed(); }
	}
	private function attachment_path( $id ) {
		global $wpdb; $wpdb->last_error = '';
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM %i WHERE ID=%d AND post_type='attachment'", $wpdb->posts, $id ) );
		if ( '' !== (string) $wpdb->last_error || (string) $exists !== (string) $id ) { return $this->failed(); }
		$wpdb->last_error = '';
		$paths = $wpdb->get_results( $wpdb->prepare( "SELECT CASE WHEN OCTET_LENGTH(meta_value)<=2048 THEN meta_value ELSE NULL END AS path FROM %i WHERE post_id=%d AND meta_key='_wp_attached_file' ORDER BY meta_id LIMIT 2", $wpdb->postmeta, $id ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $paths ) || 1 !== count( $paths ) || ! is_string( $paths[0]['path'] ?? null ) ) { return $this->failed(); }
		$path = $paths[0]['path'];
		if ( '' === $path || false !== strpos( $path, "\0" ) || preg_match( '/^[a-z][a-z0-9+.-]*:\/\//i', $path ) ) { return $this->failed(); }
		if ( ! path_is_absolute( $path ) ) { $uploads = wp_upload_dir( null, false ); if ( ! empty( $uploads['error'] ) || ! is_string( $uploads['basedir'] ?? null ) ) { return $this->failed(); } $path = $uploads['basedir'] . '/' . $path; }
		if ( is_link( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) { return $this->failed(); }
		$real = realpath( $path ); if ( ! is_string( $real ) ) { return $this->failed(); }
		return $real;
	}
	private function matches( array $actual, array $expected ) { unset( $actual['path'] ); return ( new CanonicalJson() )->hash( $actual ) === ( new CanonicalJson() )->hash( $expected ); }
	private function failed() { return new \WP_Error( 'certloom_whole_asset_unavailable', __( 'Exact certificate media, font or email attachment bytes are unavailable or changed. Replace or repair the affected reference before creating a complete recovery snapshot.', 'certloom' ) ); }
}
