<?php
namespace Certloom\Application\Backup;

use Certloom\Infrastructure\Backup\WholeStateOptionRepository;
use Certloom\Infrastructure\Backup\WholeStateTemplateRecommendationRepository;
use Certloom\Infrastructure\Backup\WholeStateDeliveryRepository;
use Certloom\Infrastructure\Backup\WholeStateProvenanceRepository;
use Certloom\Infrastructure\Backup\WholeStateCaptureCheckpoint;
use Certloom\Infrastructure\Files\PrivateJobFileStore;
use Certloom\Infrastructure\Data\DomainMaintenanceLock;
use Certloom\Infrastructure\Data\DomainGeneration;
use Certloom\Application\Rendering\CanonicalJson;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Common manual/scheduled whole-state capture. Incomplete or changed components never become a download. */
final class WholeStateBackupBuilder {
	// Operational bound is deliberately lower than the transport envelope's 1 GiB ceiling.
	const MAX_CAPTURE_BYTES = 134217728;
	private $files; private $checkpoints; private $domain; private $legacy; private $deliveries; private $references;
	public function __construct() {
		$this->files = new PrivateJobFileStore(); $this->checkpoints = new WholeStateCaptureCheckpoint( $this->files );
		$this->domain = new DomainBackupBuilder(); $this->legacy = new LegacyBackupBuilder( $this->files ); $this->deliveries = new WholeStateDeliveryBuilder( $this->files ); $this->references = new WholeStateReferenceBuilder( $this->files );
	}
	public function start( $job_key ) {
		if ( ! is_string( $job_key ) || 1 !== preg_match( '/^[a-f0-9]{32}$/D', $job_key ) || ! class_exists( '\ZipArchive' ) ) { return $this->error( 'unavailable' ); }
		$identity = ( new WholeStateProvenanceRepository() )->identity(); if ( is_wp_error( $identity ) ) { return $identity; }
		$domain = $this->domain->start( $job_key, CERTLOOM_VERSION ); if ( is_wp_error( $domain ) ) { return $domain; }
		$lock = new DomainMaintenanceLock(); $acquired = $lock->acquire( $job_key, 'whole_backup_snapshot', 120 ); if ( is_wp_error( $acquired ) ) { return $acquired; }
		try { $result = $this->snapshot( $job_key, $identity, $domain ); } catch ( \Throwable $e ) { $result = $this->error( 'snapshot' ); }
		$released = $lock->release( $job_key ); return is_wp_error( $released ) ? $released : $result;
	}
	private function snapshot( $key, array $identity, array $domain ) {
		$fixed = ( new WholeStateOptionRepository() )->snapshot(); if ( is_wp_error( $fixed ) ) { return $fixed; }
		$recommendations = ( new WholeStateTemplateRecommendationRepository() )->snapshot(); if ( is_wp_error( $recommendations ) ) { return $recommendations; }
		// Refuse executable/malformed option values before producing components.
		$decoder = new WholeStateOptionValue(); foreach ( array_merge( $fixed['options'], $recommendations['options'] ) as $record ) { $value = $decoder->decode( $record ); if ( is_wp_error( $value ) ) { return $value; } }
		$legacy = $this->legacy->start( $key ); if ( is_wp_error( $legacy ) ) { return $legacy; }
		$deliveries = $this->deliveries->start( $key, $fixed['options']['certloom_email_automation_runs_v1'] ); if ( is_wp_error( $deliveries ) ) { return $deliveries; }
		$configuration_format = new WholeStateConfigurationFormat(); $configuration = $configuration_format->from_snapshot( $fixed ); if ( is_wp_error( $configuration ) ) { return $configuration; }
		$configuration = $configuration_format->encode( $configuration ); if ( is_wp_error( $configuration ) ) { return $configuration; }
		$authoring_format = new WholeStateAuthoringFormat(); $authoring = $authoring_format->from_snapshots( $fixed, $recommendations ); if ( is_wp_error( $authoring ) ) { return $authoring; }
		$authoring = $authoring_format->encode( $authoring ); if ( is_wp_error( $authoring ) ) { return $authoring; }
		$context = $this->checkpoints->write( $key, array( 'fixed' => $fixed, 'recommendations' => $recommendations, 'identity' => $identity ), 'whole-context' ); if ( is_wp_error( $context ) ) { return $context; }
		$configuration_path = $this->write_component( $key, 'whole-configuration', $configuration ); if ( is_wp_error( $configuration_path ) ) { return $configuration_path; }
		$authoring_path = $this->write_component( $key, 'whole-authoring', $authoring ); if ( is_wp_error( $authoring_path ) ) { return $authoring_path; }
		$generation = ( new DomainGeneration() )->read_current(); if ( is_wp_error( $generation ) ) { return $generation; }
		if ( ! hash_equals( $domain['generation'], $generation ) || ! hash_equals( $generation, $deliveries['snapshot']['generation'] ) ) { return $this->error( 'changed' ); }
		return array( 'job_key' => $key, 'created_at' => gmdate( 'Y-m-d\TH:i:s\Z' ), 'context' => $context, 'phase' => 'domain', 'domain' => $domain, 'legacy' => $legacy, 'deliveries' => $deliveries, 'references' => null, 'member_paths' => array( 'data/configuration.json' => $configuration_path, 'data/authoring.jsonl' => $authoring_path ), 'members' => array(), 'member_cursor' => 0, 'total_bytes' => 0, 'archive_path' => null, 'complete' => false );
	}
	public function continue_build( array $s ) {
		if ( ! $this->valid( $s ) ) { return $this->error( 'state' ); }
		if ( $s['complete'] ) { return $s; }
		$ctx = $this->checkpoints->read( $s['context'], $s['job_key'], 'whole-context' ); if ( is_wp_error( $ctx ) ) { return $ctx; }
		$generation = ( new DomainGeneration() )->read_current(); if ( is_wp_error( $generation ) ) { return $generation; }
		if ( ! hash_equals( $s['domain']['generation'], $generation ) ) { return $this->error( 'changed' ); }
		if ( 'domain' === $s['phase'] ) { $state = $this->domain->continue_build( $s['domain'], 10 ); if ( is_wp_error( $state ) ) { return $state; } $s['domain'] = $state; if ( $state['complete'] ) { $s['member_paths']['data/domain.cvbackup'] = $state['path']; $s['phase'] = 'legacy'; } }
		elseif ( 'legacy' === $s['phase'] ) { $state = $this->legacy->continue_build( $s['legacy'], 100 ); if ( is_wp_error( $state ) ) { return $state; } $s['legacy'] = $state; if ( $state['complete'] ) { $s['member_paths']['data/legacy.cvbackup'] = $state['path']; $s['phase'] = 'deliveries'; } }
		elseif ( 'deliveries' === $s['phase'] ) { $state = $this->deliveries->continue_build( $s['deliveries'] ); if ( is_wp_error( $state ) ) { return $state; } $s['deliveries'] = $state; if ( $state['complete'] ) { $s['member_paths']['data/deliveries.jsonl'] = $state['path']; $s['phase'] = 'references'; } }
		elseif ( 'references' === $s['phase'] ) {
			if ( null === $s['references'] ) { $s['references'] = $this->references->start( $s['job_key'], $s['domain']['path'], $ctx['fixed'] ); if ( is_wp_error( $s['references'] ) ) { return $s['references']; } }
			$state = $this->references->continue_build( $s['references'] ); if ( is_wp_error( $state ) ) { return $state; } $s['references'] = $state;
			if ( $state['complete'] ) { $s['member_paths']['data/references.jsonl'] = $state['path']; foreach ( $state['asset_files'] as $sha => $file ) { $s['member_paths']['assets/' . $sha] = $file['path']; } ksort( $s['member_paths'], SORT_STRING ); $s['phase'] = 'members'; }
		}
		elseif ( 'members' === $s['phase'] ) {
			$names = array_keys( $s['member_paths'] );
			if ( $s['member_cursor'] >= count( $names ) ) { $s['phase'] = 'archive'; }
			else {
				$name = $names[$s['member_cursor']]; $path = $s['member_paths'][$name];
				if ( ! WholeStateBackupFormat::member_name( $name ) || ! $this->files->owns( $path ) || ! is_file( $path ) ) { return $this->error( 'member' ); }
				clearstatcache( true, $path ); $bytes = filesize( $path ); $hash = hash_file( 'sha256', $path );
				if ( ! is_int( $bytes ) || $bytes < 1 || $bytes > WholeStateBackupFormat::member_limit( $name ) || $bytes > self::MAX_CAPTURE_BYTES - $s['total_bytes'] || ! WholeStateReferenceRecord::sha( $hash ) || ( 0 === strpos( $name, 'assets/' ) && substr( $name, 7 ) !== $hash ) ) { return $this->error( 'size_or_member' ); }
				$s['members'][$name] = array( 'bytes' => $bytes, 'sha256' => $hash ); $s['total_bytes'] += $bytes; ++$s['member_cursor'];
			}
		}
		elseif ( 'archive' === $s['phase'] ) { $verified = $this->verify_source( $s, $ctx ); if ( is_wp_error( $verified ) ) { return $verified; } $path = $this->archive( $s, $ctx ); if ( is_wp_error( $path ) ) { return $path; } $s['archive_path'] = $path; $s['phase'] = 'verify'; }
		elseif ( 'verify' === $s['phase'] ) {
			$verified = $this->verify_source( $s, $ctx ); if ( is_wp_error( $verified ) ) { return $verified; }
			$read = ( new WholeStateBackupReader() )->analyze( $s['archive_path'] ); if ( is_wp_error( $read ) ) { return $read; }
			$origin = ( new WholeStateProvenanceRepository() )->verify( $read['manifest'] ); if ( is_wp_error( $origin ) ) { return $origin; }
			$native = ( new DomainBackupReader() )->analyze( $s['domain']['path'] ); if ( is_wp_error( $native ) ) { return $native; }
			foreach ( array( 'data/deliveries.jsonl' => new WholeStateDeliveryFormat(), 'data/references.jsonl' => new WholeStateReferenceFormat() ) as $name => $format ) { $h = fopen( $s['member_paths'][$name], 'rb' ); if ( ! is_resource( $h ) ) { return $this->error( 'component' ); } try { $valid = $format->analyze( $h, static function () { return true; } ); } finally { fclose( $h ); } if ( is_wp_error( $valid ) ) { return $valid; } }
			$verified = $this->verify_source( $s, $ctx ); if ( is_wp_error( $verified ) ) { return $verified; }
			$s['archive_sha256'] = $read['archive_sha256']; $s['archive_bytes'] = $read['archive_bytes']; $s['complete'] = true; $s['phase'] = 'complete';
		} else { return $this->error( 'state' ); }
		return $s;
	}
	public function save_checkpoint( array $state ) { return $this->valid( $state ) ? $this->checkpoints->write( $state['job_key'], $state ) : $this->error( 'state' ); }
	public function load_checkpoint( array $reference, $job_key ) { $s = $this->checkpoints->read( $reference, $job_key ); return is_wp_error( $s ) ? $s : ( $this->valid( $s ) ? $s : $this->error( 'state' ) ); }
	private function verify_source( array $s, array $ctx ) {
		foreach ( array( ( new WholeStateOptionRepository() )->verify( $ctx['fixed']['fingerprint'] ), ( new WholeStateTemplateRecommendationRepository() )->verify( $ctx['recommendations']['fingerprint'] ), ( new WholeStateDeliveryRepository() )->verify( $s['deliveries']['snapshot'] ) ) as $valid ) { if ( is_wp_error( $valid ) ) { return $valid; } }
		$legacy = \Ecapla_CertVerify_Backup::capture_state_marker(); $generation = ( new DomainGeneration() )->read_current(); $identity = ( new WholeStateProvenanceRepository() )->identity();
		if ( is_wp_error( $legacy ) || is_wp_error( $generation ) || is_wp_error( $identity ) || ! hash_equals( $s['domain']['generation'], $generation ) || ( new CanonicalJson() )->hash( $s['legacy']['marker'] ) !== ( new CanonicalJson() )->hash( $legacy ) || $identity != $ctx['identity'] ) { return $this->error( 'changed' ); }
		return true;
	}
	private function archive( array $s, array $ctx ) {
		$manifest = array( 'format' => WholeStateBackupFormat::FORMAT, 'format_version' => WholeStateBackupFormat::VERSION, 'created_at' => $s['created_at'], 'plugin_version' => CERTLOOM_VERSION, 'scope' => WholeStateBackupFormat::scope( $s['references']['format_version'] ?? 1 ), 'source' => array( 'installation_id' => $ctx['identity']['installation_id'], 'export_id' => $s['job_key'], 'blog_id' => $ctx['identity']['blog_id'], 'home_url' => untrailingslashit( home_url() ), 'generation' => $s['domain']['generation'] ), 'provenance' => array( 'algorithm' => 'site-hmac-sha256', 'tag' => str_repeat( '0', 64 ) ), 'members' => $s['members'] );
		$manifest = ( new WholeStateProvenanceRepository() )->sign( $manifest ); if ( is_wp_error( $manifest ) ) { return $manifest; }
		$bytes = ( new WholeStateBackupFormat() )->encode_manifest( $manifest ); if ( is_wp_error( $bytes ) ) { return $bytes; }
		$path = $this->files->create_path( $s['job_key'], 'whole-archive' ); if ( is_wp_error( $path ) ) { return $path; }
		$zip = new \ZipArchive(); if ( true !== $zip->open( $path, \ZipArchive::CREATE | \ZipArchive::EXCL ) ) { return $this->error( 'archive' ); }
		$ok = $zip->addFromString( 'manifest.json', $bytes ) && $zip->setCompressionName( 'manifest.json', \ZipArchive::CM_STORE ) && $zip->setExternalAttributesName( 'manifest.json', \ZipArchive::OPSYS_UNIX, 0100600 << 16 );
		foreach ( $s['members'] as $name => $member ) {
			$file = $s['member_paths'][$name]; clearstatcache( true, $file );
			if ( ! $this->files->owns( $file ) || ! is_file( $file ) || filesize( $file ) !== $member['bytes'] || ! hash_equals( $member['sha256'], (string) hash_file( 'sha256', $file ) ) || ! $zip->addFile( $file, $name ) || ! $zip->setCompressionName( $name, \ZipArchive::CM_STORE ) || ! $zip->setExternalAttributesName( $name, \ZipArchive::OPSYS_UNIX, 0100600 << 16 ) ) { $ok = false; break; }
		}
		$closed = $zip->close(); if ( ! $ok || ! $closed ) { $this->files->delete( $path ); return $this->error( 'archive' ); } @chmod( $path, 0600 );
		clearstatcache( true, $path ); return filesize( $path ) <= self::MAX_CAPTURE_BYTES + WholeStateBackupFormat::MAX_MANIFEST_BYTES + 1048576 ? $path : $this->error( 'size_or_member' );
	}
	private function write_component( $key, $purpose, $bytes ) {
		$path = $this->files->create_path( $key, $purpose ); if ( is_wp_error( $path ) ) { return $path; }
		$h = fopen( $path, 'xb' ); if ( ! is_resource( $h ) ) { return $this->error( 'write' ); } $written = fwrite( $h, $bytes ); $flushed = fflush( $h ); $closed = fclose( $h );
		if ( strlen( $bytes ) !== $written || ! $flushed || ! $closed ) { $this->files->delete( $path ); return $this->error( 'write' ); } @chmod( $path, 0600 ); return $path;
	}
	private function valid( array $s ) { return isset( $s['job_key'], $s['created_at'], $s['context'], $s['phase'], $s['domain'], $s['legacy'], $s['deliveries'], $s['member_paths'], $s['members'], $s['member_cursor'], $s['total_bytes'], $s['complete'] ) && array_key_exists( 'references', $s ) && array_key_exists( 'archive_path', $s ) && is_string( $s['job_key'] ) && 1 === preg_match( '/^[a-f0-9]{32}$/D', $s['job_key'] ) && is_string( $s['created_at'] ) && is_array( $s['context'] ) && is_string( $s['phase'] ) && is_array( $s['domain'] ) && is_array( $s['legacy'] ) && is_array( $s['deliveries'] ) && ( null === $s['references'] || is_array( $s['references'] ) ) && is_array( $s['member_paths'] ) && is_array( $s['members'] ) && is_int( $s['member_cursor'] ) && $s['member_cursor'] >= 0 && is_int( $s['total_bytes'] ) && $s['total_bytes'] >= 0 && $s['total_bytes'] <= self::MAX_CAPTURE_BYTES && is_bool( $s['complete'] ); }
	private function error( $code ) { return new \WP_Error( 'certloom_whole_capture_' . $code, __( 'Certloom could not produce a complete consistent recovery snapshot. Current capture supports up to 128 MB of component and asset data. No partial archive was published; review the capture error and retry after current edits finish.', 'certloom' ) ); }
}
