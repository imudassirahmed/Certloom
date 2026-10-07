<?php
namespace Certloom\Application\Backup;

use Certloom\Infrastructure\Backup\WholeStateReferenceRepository;
use Certloom\Infrastructure\Backup\WholeStateAssetRepository;
use Certloom\Infrastructure\Files\PrivateJobFileStore;
use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Application\Rendering\SnapshotAssetResolver;
use Certloom\Infrastructure\Rendering\WordPressLocalAssetResolver;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Bounded source graph scan and exact-byte capture. Accounts and assets never create target authority. */
final class WholeStateReferenceBuilder {
	private $files; private $references; private $assets;
	public function __construct( ?PrivateJobFileStore $files = null ) { $this->files = $files ?: new PrivateJobFileStore(); $this->references = new WholeStateReferenceRepository(); $this->assets = new WholeStateAssetRepository(); }

	public function start( $job_key, $domain_path, array $fixed ) {
		if ( ! $this->files->owns( $domain_path ) || ! is_file( $domain_path ) || is_wp_error( ( new WholeStateConfigurationFormat() )->from_snapshot( $fixed ) ) ) { return $this->error( 'state' ); }
		$path = $this->files->create_path( $job_key, 'whole-references' ); if ( is_wp_error( $path ) ) { return $path; }
		$header = ( new WholeStateReferenceFormat() )->header( array_intersect_key( $fixed['options'], array_flip( WholeStateOptionInventory::keys( 'references' ) ) ) ); if ( is_wp_error( $header ) ) { return $header; }
		$h = @fopen( $path, 'xb' ); if ( ! is_resource( $h ) ) { return $this->error( 'file' ); }
		$written = fwrite( $h, $header ); $flushed = fflush( $h ); fclose( $h ); if ( strlen( $header ) !== $written || ! $flushed ) { $this->files->delete( $path ); return $this->error( 'file' ); } @chmod( $path, 0600 );
		$s = array( 'job_key' => $job_key, 'path' => $path, 'domain_path' => $domain_path, 'domain_offset' => 0, 'offset' => strlen( $header ), 'hash' => WholeStateReferenceFormat::next_hash( '', $header ), 'counts' => WholeStateReferenceFormat::empty_counts(), 'phase' => 'scan', 'cursor' => 0, 'objects' => array(), 'images' => array(), 'fonts' => array(), 'image_records' => array(), 'asset_files' => array(), 'verify_offset' => 0, 'complete' => false );
		$s += array( 'format_version' => WholeStateReferenceFormat::VERSION, 'files' => array(), 'media_urls' => array() );
		$decoder = new WholeStateOptionValue();
		foreach ( $fixed['options'] as $name => $record ) {
			$decoded = $decoder->decode( $record ); if ( is_wp_error( $decoded ) ) { return $decoded; }
			if ( ! $decoded['exists'] ) { continue; }
			$value = $decoded['value'];
			if ( 'certloom_custom_font_library_v1' === $name ) { $valid = $this->collect_fonts( $s, $value ); if ( is_wp_error( $valid ) ) { return $valid; } }
			elseif ( in_array( $name, array( 'certloom_email_template_catalog_v1', 'certloom_email_template_issued_v1' ), true ) ) { $nodes = 0; $valid = $this->walk( $s, $value, 0, $nodes, true ); if ( is_wp_error( $valid ) ) { return $valid; } }
			elseif ( in_array( $name, array( 'certloom_tutor_lms_mappings', 'certloom_tutor_pending_mappings_v1', 'certloom_woocommerce_mappings', 'certloom_woocommerce_pending_mappings_v1', 'certloom_learndash_mappings' ), true ) ) { $nodes = 0; $valid = $this->walk( $s, $value, 0, $nodes, false ); if ( is_wp_error( $valid ) ) { return $valid; } }
			elseif ( 'certverify_onboarding' === $name && is_array( $value ) ) {
				$page = $value['verification_page_id'] ?? 0; if ( $page ) { if ( ! is_int( $page ) || $page < 1 ) { return $this->error( 'object' ); } $s['objects'][$page] = true; }
				foreach ( array( 'organization_logo_id', 'certificate_image_id', 'setup_new_signer_signature_id' ) as $key ) { $id = $value['wizard_draft'][$key] ?? 0; if ( $id ) { if ( ! is_int( $id ) || $id < 1 ) { return $this->error( 'image' ); } $valid = $this->collect_image( $s, 'attachment:' . $id ); if ( is_wp_error( $valid ) ) { return $valid; } } }
			}
		}
		return $s;
	}

	public function continue_build( array $s ) {
		if ( ! $this->valid( $s ) ) { return $this->error( 'state' ); }
		if ( isset( $s['format_version'] ) && ( 2 !== $s['format_version'] || ! is_array( $s['files'] ?? null ) || ! is_array( $s['media_urls'] ?? null ) ) ) { return $this->error( 'state' ); }
		if ( $s['complete'] ) { return $s; }
		$h = @fopen( $s['path'], 'r+b' ); if ( ! is_resource( $h ) ) { return $this->error( 'file' ); }
		try {
			$stat = fstat( $h ); if ( ! is_array( $stat ) || $stat['size'] < $s['offset'] || ! ftruncate( $h, $s['offset'] ) || 0 !== fseek( $h, $s['offset'] ) ) { return $this->error( 'resume' ); }
			if ( 'scan' === $s['phase'] ) { $result = $this->scan( $s, $h ); }
			elseif ( 'objects' === $s['phase'] ) { $result = $this->objects( $s, $h ); }
			elseif ( 'images' === $s['phase'] ) { $result = $this->images( $s ); }
			elseif ( 'write_images' === $s['phase'] ) { $result = $this->write_images( $s, $h ); }
			elseif ( 'fonts' === $s['phase'] ) { $result = $this->fonts( $s, $h ); }
			elseif ( 'files' === $s['phase'] ) { $result = $this->email_files( $s, $h ); }
			elseif ( 'media_urls' === $s['phase'] ) { $result = $this->media_urls( $s, $h ); }
			elseif ( 'verify_references' === $s['phase'] ) { $result = $this->verify_references( $s ); }
			elseif ( 'verify_assets' === $s['phase'] ) { $result = $this->verify_assets( $s ); }
			elseif ( 'finish' === $s['phase'] ) {
				$line = ( new WholeStateReferenceFormat() )->footer( $s['counts'], $s['hash'] );
				$result = $this->append( $s, $h, $line, false );
				if ( ! is_wp_error( $result ) ) { $s['complete'] = true; }
			} else { return $this->error( 'state' ); }
			if ( is_wp_error( $result ) ) { return $result; }
			if ( ! fflush( $h ) ) { return $this->error( 'flush' ); }
			return $s;
		} finally { fclose( $h ); }
	}

	private function scan( array &$s, $output ) {
		$h = @fopen( $s['domain_path'], 'rb' ); if ( ! is_resource( $h ) ) { return $this->error( 'domain' ); }
		try {
			if ( 0 !== fseek( $h, $s['domain_offset'] ) ) { return $this->error( 'domain' ); }
			for ( $i = 0; $i < 10; ++$i ) {
				$line = ( new DomainBackupFormat() )->read_line( $h, 0 ); if ( is_wp_error( $line ) ) { return $line; }
				if ( null === $line ) { $s['phase'] = 'objects'; $s['cursor'] = 0; ksort( $s['objects'], SORT_NUMERIC ); ksort( $s['images'], SORT_STRING ); ksort( $s['fonts'], SORT_STRING ); return true; }
				$item = json_decode( $line, true, 64 ); if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $item ) ) { return $this->error( 'domain' ); }
				if ( 'entity' === ( $item['type'] ?? null ) ) {
					$type = $item['entity']; $row = $item['data']; if ( ! is_array( $row ) ) { return $this->error( 'domain' ); }
					if ( 'recipients' === $type && ! empty( $row['wp_user_id'] ) ) { $r = $this->references->account( (int) $row['id'], (int) $row['wp_user_id'] ); if ( is_wp_error( $r ) ) { return $r; } $result = $this->emit( $s, $output, $r ); if ( is_wp_error( $result ) ) { return $result; } }
					elseif ( 'issuers' === $type || 'signers' === $type ) { foreach ( array( 'logo_attachment_id', 'seal_attachment_id', 'signature_attachment_id' ) as $column ) { if ( ! empty( $row[$column] ) ) { $result = $this->collect_image( $s, 'attachment:' . $row[$column] ); if ( is_wp_error( $result ) ) { return $result; } } } }
					elseif ( 'template_revisions' === $type ) { $layout = json_decode( $row['layout_json'], true, 64 ); if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $layout ) ) { return $this->error( 'layout' ); } $nodes = 0; $result = $this->walk( $s, $layout, 0, $nodes, true ); if ( is_wp_error( $result ) ) { return $result; } }
					elseif ( 'credential_versions' === $type && null !== $row['snapshot_json'] ) {
						$snapshot = json_decode( $row['snapshot_json'], true, 64 ); if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $snapshot ) ) { return $this->error( 'snapshot' ); }
						$media = $snapshot['media'] ?? null;
						try { new SnapshotAssetResolver( new WordPressLocalAssetResolver(), $media ); } catch ( \Throwable $e ) { return $this->error( 'historical_media' ); }
						foreach ( $media['assets'] ?? array() as $ref => $expected ) { $result = $this->collect_image( $s, $ref, $expected ); if ( is_wp_error( $result ) ) { return $result; } }
						// A media-bearing historical layout needs its own proven bytes.
						$nodes = 0; $required = array(); $this->attachment_refs( $snapshot['template']['layout'] ?? array(), $required, 0, $nodes );
						if ( 'image' === ( $snapshot['presentation']['mode'] ?? null ) ) { $required['attachment:' . ( $snapshot['presentation']['certificate_image_id'] ?? 0 )] = true; }
						foreach ( $required as $ref => $_ ) { if ( ! isset( $media['assets'][$ref] ) ) { return $this->error( 'historical_media' ); } }
					}
				}
				$offset = ftell( $h ); if ( ! is_int( $offset ) ) { return $this->error( 'domain' ); } $s['domain_offset'] = $offset;
			}
			return true;
		} catch ( \Throwable $e ) { return $this->error( 'scan' ); } finally { fclose( $h ); }
	}
	private function objects( array &$s, $h ) {
		$ids = array_keys( $s['objects'] );
		for ( $i = 0; $i < 25 && $s['cursor'] < count( $ids ); ++$i, ++$s['cursor'] ) { $r = $this->references->object( (int) $ids[$s['cursor']] ); if ( is_wp_error( $r ) ) { return $r; } $result = $this->emit( $s, $h, $r ); if ( is_wp_error( $result ) ) { return $result; } }
		if ( $s['cursor'] >= count( $ids ) ) { $s['phase'] = 'images'; $s['cursor'] = 0; }
		return true;
	}
	private function images( array &$s ) {
		$keys = array_keys( $s['images'] );
		if ( $s['cursor'] >= count( $keys ) ) { ksort( $s['image_records'], SORT_STRING ); $s['phase'] = 'write_images'; $s['cursor'] = 0; return true; }
		$item = $s['images'][$keys[$s['cursor']]]; $asset = $this->assets->image( $item['reference'], $item['expected'] ); if ( is_wp_error( $asset ) ) { return $asset; }
		$result = $this->copy_asset( $s, $asset ); if ( is_wp_error( $result ) ) { return $result; }
		$descriptor = $asset; unset( $descriptor['path'] ); $record = array( 'kind' => 'image', 'reference' => $item['reference'], 'descriptor' => $descriptor );
		$key = ( new WholeStateReferenceRecord() )->key( $record ); if ( is_wp_error( $key ) ) { return $key; } $s['image_records'][$key] = $record;
		$s['images'][$keys[$s['cursor']]]['captured'] = $descriptor; ++$s['cursor']; return true;
	}
	private function write_images( array &$s, $h ) {
		$rows = array_values( $s['image_records'] );
		for ( $i = 0; $i < 25 && $s['cursor'] < count( $rows ); ++$i, ++$s['cursor'] ) { $result = $this->emit( $s, $h, $rows[$s['cursor']] ); if ( is_wp_error( $result ) ) { return $result; } }
		if ( $s['cursor'] >= count( $rows ) ) { $s['phase'] = 'fonts'; $s['cursor'] = 0; } return true;
	}
	private function fonts( array &$s, $h ) {
		$rows = array_values( $s['fonts'] );
		if ( $s['cursor'] >= count( $rows ) ) { $s['phase'] = 2 === ( $s['format_version'] ?? 1 ) ? 'files' : 'verify_references'; $s['cursor'] = 0; return true; }
		$row = $rows[$s['cursor']]; $asset = $this->assets->font( $row['sha256'], $row['format'] ); if ( is_wp_error( $asset ) ) { return $asset; }
		$result = $this->copy_asset( $s, $asset ); if ( is_wp_error( $result ) ) { return $result; }
		$result = $this->emit( $s, $h, array( 'kind' => 'font', 'sha256' => $asset['sha256'], 'format' => $asset['format'], 'size' => $asset['size'] ) ); if ( is_wp_error( $result ) ) { return $result; } ++$s['cursor']; return true;
	}
	private function email_files( array &$s, $h ) {
		ksort( $s['files'], SORT_STRING ); $references = array_keys( $s['files'] );
		if ( $s['cursor'] >= count( $references ) ) { $s['phase'] = 'media_urls'; $s['cursor'] = 0; return true; }
		$reference = $references[$s['cursor']]; $asset = $this->assets->file( $reference ); if ( is_wp_error( $asset ) ) { return $asset; }
		$result = $this->copy_asset( $s, $asset ); if ( is_wp_error( $result ) ) { return $result; }
		unset( $asset['path'] ); $row = array( 'kind' => 'file', 'reference' => $reference, 'descriptor' => $asset );
		$result = $this->emit( $s, $h, $row ); if ( is_wp_error( $result ) ) { return $result; }
		$s['files'][$reference] = $asset; ++$s['cursor']; return true;
	}
	private function media_urls( array &$s, $h ) {
		ksort( $s['media_urls'], SORT_STRING ); $rows = array_values( $s['media_urls'] );
		for ( $i = 0; $i < 25 && $s['cursor'] < count( $rows ); ++$i, ++$s['cursor'] ) {
			$row = $rows[$s['cursor']]; $descriptor = $s['images']['attachment:' . $row['source_id'] . ':current']['captured'] ?? null;
			if ( ! is_array( $descriptor ) ) { return $this->error( 'image' ); }
			$row['image_sha256'] = $descriptor['sha256']; $result = $this->emit( $s, $h, $row ); if ( is_wp_error( $result ) ) { return $result; }
		}
		if ( $s['cursor'] >= count( $rows ) ) { $s['phase'] = 'verify_references'; $s['cursor'] = 0; } return true;
	}
	private function verify_references( array &$s ) {
		$h = fopen( $s['path'], 'rb' ); if ( ! is_resource( $h ) ) { return $this->error( 'file' ); }
		try {
			if ( 0 !== fseek( $h, $s['verify_offset'] ) ) { return $this->error( 'file' ); }
			for ( $i = 0; $i < 50; ++$i ) {
				$line = fgets( $h, WholeStateReferenceFormat::MAX_LINE_BYTES + 1 );
				if ( false === $line ) { if ( ! feof( $h ) ) { return $this->error( 'file' ); } $s['phase'] = 'verify_assets'; $s['cursor'] = 0; return true; }
				if ( "\n" !== substr( $line, -1 ) ) { return $this->error( 'file' ); } $r = json_decode( $line, true, 16 ); if ( ! is_array( $r ) ) { return $this->error( 'file' ); }
				if ( 'reference' === ( $r['type'] ?? null ) ) { $r = $r['data']; $valid = ( new WholeStateReferenceRecord() )->validate( $r ); if ( is_wp_error( $valid ) ) { return $valid; }
					if ( 'account' === $r['kind'] ) { $current = $this->references->account( $r['recipient_id'], $r['source_user_id'] ); }
					elseif ( 'object' === $r['kind'] ) { $current = $this->references->object( $r['source_id'] ); } else { $current = $r; }
					if ( 'media_url' === $r['kind'] && $this->local_url_attachment( $r['source_url'] ) !== $r['source_id'] ) { return $this->error( 'changed' ); }
					if ( is_wp_error( $current ) || ( new CanonicalJson() )->hash( $current ) !== ( new CanonicalJson() )->hash( $r ) ) { return $this->error( 'changed' ); }
				} $s['verify_offset'] = ftell( $h );
			} return true;
		} finally { fclose( $h ); }
	}
	private function verify_assets( array &$s ) {
		$rows = array_values( $s['images'] );
		if ( $s['cursor'] < count( $rows ) ) { $row = $rows[$s['cursor']]; $current = $this->assets->image( $row['reference'], $row['expected'] ); if ( is_wp_error( $current ) ) { return $current; } unset( $current['path'] ); if ( ( new CanonicalJson() )->hash( $current ) !== ( new CanonicalJson() )->hash( $row['captured'] ) ) { return $this->error( 'changed' ); } ++$s['cursor']; return true; }
		$source_files = $s['files'] ?? array(); $index = $s['cursor'] - count( $rows );
		if ( $index < count( $source_files ) ) {
			$reference = array_keys( $source_files )[$index]; $current = $this->assets->file( $reference ); if ( is_wp_error( $current ) ) { return $current; } unset( $current['path'] );
			if ( ( new CanonicalJson() )->hash( $current ) !== ( new CanonicalJson() )->hash( $source_files[$reference] ) ) { return $this->error( 'changed' ); } ++$s['cursor']; return true;
		}
		$files = array_values( $s['asset_files'] ); $index -= count( $source_files );
		if ( $index >= count( $files ) ) { $s['phase'] = 'finish'; return true; }
		$file = $files[$index]; clearstatcache( true, $file['path'] );
		if ( ! $this->files->owns( $file['path'] ) || ! is_file( $file['path'] ) || filesize( $file['path'] ) !== $file['size'] || ! hash_equals( $file['sha256'], (string) hash_file( 'sha256', $file['path'] ) ) ) { return $this->error( 'changed' ); }
		++$s['cursor']; return true;
	}
	private function collect_image( array &$s, $reference, $expected = null ) {
		if ( ! is_string( $reference ) || 1 !== preg_match( '/^attachment:([1-9][0-9]{0,18})$/D', $reference, $m ) || (string) (int) $m[1] !== $m[1] ) { return $this->error( 'image' ); }
		if ( null !== $expected && is_wp_error( ( new WholeStateReferenceRecord() )->validate( array( 'kind' => 'image', 'reference' => $reference, 'descriptor' => $expected ) ) ) ) { return $this->error( 'image' ); }
		$key = $reference . ':' . ( $expected['sha256'] ?? 'current' ); $s['images'][$key] = array( 'reference' => $reference, 'expected' => $expected ); $s['objects'][(int) $m[1]] = true;
		return count( $s['images'] ) + count( $s['fonts'] ) + count( $s['files'] ?? array() ) <= WholeStateReferenceRecord::MAX_ASSET_REFERENCES && count( $s['objects'] ) <= WholeStateReferenceRecord::MAX_OBJECTS ? true : $this->error( 'limit' );
	}
	private function walk( array &$s, $value, $depth, &$nodes, $images ) {
		if ( ++$nodes > 50000 || $depth > 32 ) { return $this->error( 'limit' ); }
		if ( is_array( $value ) ) { foreach ( $value as $key => $item ) {
			if ( $images && 'attachments' === $key && 2 === ( $s['format_version'] ?? 1 ) ) {
				if ( ! is_array( $item ) || count( $item ) > \Certloom\Application\Email\EmailTemplateService::MAX_STATIC_ATTACHMENTS ) { return $this->error( 'file' ); }
				foreach ( $item as $id ) { if ( ! WholeStateReferenceRecord::id( $id ) ) { return $this->error( 'file' ); } $s['files']['attachment:' . $id] = null; $s['objects'][$id] = true; }
				if ( count( $s['files'] ) + count( $s['images'] ) + count( $s['fonts'] ) > WholeStateReferenceRecord::MAX_ASSET_REFERENCES || count( $s['objects'] ) > WholeStateReferenceRecord::MAX_OBJECTS ) { return $this->error( 'limit' ); }
			}
			if ( ! $images && in_array( $key, array( 'course_id', 'product_id', 'variation_id' ), true ) && ( is_int( $item ) || is_string( $item ) ) ) { if ( 1 !== preg_match( '/^[1-9][0-9]{0,18}$/D', (string) $item ) || (string) (int) $item !== (string) $item ) { return $this->error( 'object' ); } $s['objects'][(int) $item] = true; if ( count( $s['objects'] ) > WholeStateReferenceRecord::MAX_OBJECTS ) { return $this->error( 'limit' ); } }
			$r = $this->walk( $s, $item, $depth + 1, $nodes, $images ); if ( is_wp_error( $r ) ) { return $r; }
		} }
		elseif ( $images && is_string( $value ) ) {
			if ( 0 === strpos( $value, 'attachment:' ) ) { return $this->collect_image( $s, $value ); }
			$urls = WholeStateEmailMedia::urls( $value ); if ( is_wp_error( $urls ) ) { return $urls; }
			foreach ( $urls as $url ) {
				$id = $this->local_url_attachment( $url ); if ( is_wp_error( $id ) ) { return $id; } if ( ! $id ) { continue; }
				$result = $this->collect_image( $s, 'attachment:' . $id ); if ( is_wp_error( $result ) ) { return $result; }
				if ( 2 === ( $s['format_version'] ?? 1 ) ) {
					$s['media_urls'][hash( 'sha256', $url )] = array( 'kind' => 'media_url', 'source_url' => $url, 'source_id' => $id );
					if ( count( $s['media_urls'] ) > WholeStateReferenceRecord::MAX_ASSET_REFERENCES ) { return $this->error( 'limit' ); }
				}
			}
		} return true;
	}
	private function local_url_attachment( $url ) {
		if ( ! WholeStateReferenceRecord::media_url( $url ) || ! function_exists( 'attachment_url_to_postid' ) || ! function_exists( 'wp_get_attachment_url' ) ) { return 0; }
		$id = attachment_url_to_postid( $url );
		if ( ! is_int( $id ) || $id < 1 ) {
			$uploads = wp_upload_dir( null, false ); $base = $uploads['baseurl'] ?? null;
			if ( ! empty( $uploads['error'] ) || ! WholeStateReferenceRecord::media_url( $base ) ) { return $this->error( 'image' ); }
			$source = parse_url( $url ); $local = parse_url( $base ); $path_prefix = rtrim( $local['path'] ?? '', '/' ) . '/';
			return strtolower( $source['host'] ) === strtolower( $local['host'] ) && ( $source['port'] ?? null ) === ( $local['port'] ?? null ) && 0 === strpos( $source['path'] ?? '', $path_prefix ) ? $this->error( 'missing_email_image' ) : 0;
		}
		$evidence = $this->assets->attachment_url_evidence( $id ); if ( is_wp_error( $evidence ) ) { return $evidence; } $current = $evidence['url'];
		$source = parse_url( $url ); $target = parse_url( $current );
		return strtolower( $source['host'] ) === strtolower( $target['host'] ) && ( $source['port'] ?? null ) === ( $target['port'] ?? null ) && ( $source['path'] ?? '' ) === ( $target['path'] ?? '' ) ? $id : 0;
	}
	private function attachment_refs( $value, array &$refs, $depth, &$nodes ) { if ( ++$nodes > 50000 || $depth > 32 ) { throw new \RuntimeException( 'Reference graph exceeds bounds.' ); } if ( is_array( $value ) ) { foreach ( $value as $item ) { $this->attachment_refs( $item, $refs, $depth + 1, $nodes ); } } elseif ( is_string( $value ) && 0 === strpos( $value, 'attachment:' ) ) { $refs[$value] = true; } }
	private function collect_fonts( array &$s, $families ) {
		if ( ! is_array( $families ) ) { return $this->error( 'fonts' ); }
		foreach ( $families as $key => $family ) {
			if ( ! is_string( $key ) || 1 !== preg_match( '/^custom-[a-f0-9]{16}$/D', $key ) || ! is_array( $family ) || count( $family ) !== 2 || ! is_string( $family['label'] ?? null ) || '' === trim( $family['label'] ) || strlen( $family['label'] ) > 96 || ! is_array( $family['faces'] ?? null ) || ! $family['faces'] ) { return $this->error( 'fonts' ); }
			foreach ( $family['faces'] as $variant => $face ) {
				if ( ! is_string( $variant ) || 1 !== preg_match( '/^[1-9]00-(?:normal|italic)$/D', $variant ) || ! is_array( $face ) || count( $face ) !== 3 || ! WholeStateReferenceRecord::sha( $face['sha256'] ?? null ) || ! in_array( $face['format'] ?? null, array( 'ttf', 'otf' ), true ) || ( $face['file'] ?? null ) !== $face['sha256'] . '.' . $face['format'] ) { return $this->error( 'fonts' ); }
				$s['fonts'][$face['sha256'] . ':' . $face['format']] = $face;
				if ( count( $s['images'] ) + count( $s['fonts'] ) + count( $s['files'] ?? array() ) > WholeStateReferenceRecord::MAX_ASSET_REFERENCES ) { return $this->error( 'limit' ); }
			}
		} return true;
	}
	private function copy_asset( array &$s, array $asset ) {
		$sha = $asset['sha256'];
		if ( isset( $s['asset_files'][$sha] ) ) { return $s['asset_files'][$sha]['size'] === $asset['size'] ? true : $this->error( 'asset' ); }
		if ( count( $s['asset_files'] ) >= WholeStateReferenceRecord::MAX_ASSETS ) { return $this->error( 'limit' ); }
		$total = 0; foreach ( $s['asset_files'] as $file ) { $total += $file['size']; }
		if ( $asset['size'] > WholeStateBackupBuilder::MAX_CAPTURE_BYTES - $total ) { return $this->error( 'limit' ); }
		$path = $this->files->store_copy( $s['job_key'], $asset['path'], 'whole-asset' ); if ( is_wp_error( $path ) ) { return $path; }
		clearstatcache( true, $path );
		if ( filesize( $path ) !== $asset['size'] || ! hash_equals( $sha, (string) hash_file( 'sha256', $path ) ) ) { $this->files->delete( $path ); return $this->error( 'changed' ); }
		$s['asset_files'][$sha] = array( 'path' => $path, 'size' => $asset['size'], 'sha256' => $sha ); return true;
	}
	private function emit( array &$s, $h, array $row ) { $r = $this->append( $s, $h, ( new WholeStateReferenceFormat() )->record( $row ) ); if ( is_wp_error( $r ) ) { return $r; } ++$s['counts'][$row['kind']]; return $s['counts']['account'] <= WholeStateReferenceRecord::MAX_ACCOUNTS ? true : $this->error( 'limit' ); }
	private function append( array &$s, $h, $line, $chain = true ) { if ( is_wp_error( $line ) ) { return $line; } if ( strlen( $line ) > WholeStateReferenceFormat::MAX_BYTES - $s['offset'] || strlen( $line ) !== fwrite( $h, $line ) ) { return $this->error( 'write' ); } $s['offset'] += strlen( $line ); if ( $chain ) { $s['hash'] = WholeStateReferenceFormat::next_hash( $s['hash'], $line ); } return true; }
	private function valid( array $s ) { return isset( $s['job_key'], $s['path'], $s['domain_path'], $s['domain_offset'], $s['offset'], $s['hash'], $s['counts'], $s['phase'], $s['cursor'], $s['objects'], $s['images'], $s['fonts'], $s['image_records'], $s['asset_files'], $s['verify_offset'], $s['complete'] ) && is_string( $s['job_key'] ) && 1 === preg_match( '/^[a-f0-9]{32}$/D', $s['job_key'] ) && $this->files->owns( $s['path'] ) && $this->files->owns( $s['domain_path'] ) && is_file( $s['path'] ) && is_file( $s['domain_path'] ) && is_int( $s['offset'] ) && $s['offset'] > 0 && $s['offset'] <= WholeStateReferenceFormat::MAX_BYTES && is_int( $s['domain_offset'] ) && $s['domain_offset'] >= 0 && WholeStateReferenceRecord::sha( $s['hash'] ) && is_array( $s['counts'] ) && is_string( $s['phase'] ) && is_int( $s['cursor'] ) && $s['cursor'] >= 0 && is_array( $s['objects'] ) && is_array( $s['images'] ) && is_array( $s['fonts'] ) && is_array( $s['image_records'] ) && is_array( $s['asset_files'] ) && is_int( $s['verify_offset'] ) && $s['verify_offset'] >= 0 && is_bool( $s['complete'] ); }
	private function error( $code ) { return new \WP_Error( 'certloom_whole_references_' . $code, __( 'Certloom could not finish complete installation reference and asset capture. No recovery snapshot was published.', 'certloom' ) ); }
}
