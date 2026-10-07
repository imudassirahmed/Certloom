<?php
namespace Certloom\Application\Restore;

use Certloom\Application\Backup\WholeStateBackupReader;
use Certloom\Application\Backup\WholeStateBackupBuilder;
use Certloom\Application\Backup\WholeStateReferenceFormat;
use Certloom\Application\Backup\WholeStateReferenceRecord;
use Certloom\Infrastructure\Backup\WholeStateProvenanceRepository;
use Certloom\Infrastructure\Backup\WholeStateReferenceRepository;
use Certloom\Infrastructure\Data\DomainGeneration;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Explicit current target object review. This cannot rewrite historical snapshot identities. */
final class WholeStateObjectMappingProof {
	private $archive_path;
	private $archive_sha256;
	private $actor;
	private $actor_sha256;
	private $generation;
	private $bindings;
	private $provenance;
	private function __construct() {}
	private function __clone() {}
	public function review_choices() { $choices = array(); foreach ( $this->bindings as $id => $binding ) { $choices[$id] = $binding['target_id']; } return $choices; }
	public function fingerprint() { return ( new \Certloom\Application\Rendering\CanonicalJson() )->hash( array( $this->archive_sha256, $this->actor, $this->actor_sha256, $this->generation, $this->bindings, $this->provenance ) ); }
	public function __serialize() { throw new \LogicException( 'Recovery object authority cannot be serialized.' ); }
	public function __unserialize( array $data ) { throw new \LogicException( 'Recovery object authority requires current explicit review.' ); }

	/** Every source object needs an explicit existing target ID or null (disconnect). */
	public static function review( $path, $sha, $actor, array $mappings, $mode ) {
		if ( ! is_int( $actor ) || $actor < 1 || $actor !== (int) get_current_user_id() || ! current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_DATA_TOOLS ) || ! WholeStateReferenceRecord::sha( $sha ) || ! in_array( $mode, array( 'same_installation', 'reviewed_target' ), true ) || count( $mappings ) > WholeStateReferenceRecord::MAX_OBJECTS ) { return self::invalid(); }
		$archive = ( new WholeStateBackupReader() )->analyze( $path );
		if ( is_wp_error( $archive ) || $archive['archive_sha256'] !== $sha || $archive['uncompressed_bytes'] > WholeStateBackupBuilder::MAX_CAPTURE_BYTES ) { return self::invalid(); }
		$origin = ( new WholeStateProvenanceRepository() )->verify( $archive['manifest'] );
		if ( is_wp_error( $origin ) ) {
			if ( 'same_installation' === $mode ) { return self::invalid(); }
			$local = ( new WholeStateProvenanceRepository() )->identity();
			if ( ! is_wp_error( $local ) && $local['installation_id'] === $archive['manifest']['source']['installation_id'] && $local['blog_id'] === $archive['manifest']['source']['blog_id'] ) { return self::invalid(); }
		}
		$repository = new WholeStateReferenceRepository(); $actor_sha = $repository->account_identity( $actor ); $generation = ( new DomainGeneration() )->read_current();
		if ( ! is_string( $actor_sha ) || is_wp_error( $generation ) ) { return self::invalid(); }
		$zip = new \ZipArchive(); if ( true !== $zip->open( $path, \ZipArchive::RDONLY ) ) { return self::invalid(); }
		try {
			$objects = array(); $stream = $zip->getStream( 'data/references.jsonl' ); if ( ! is_resource( $stream ) ) { return self::invalid(); }
			try { $read = ( new WholeStateReferenceFormat() )->analyze( $stream, static function ( $record ) use ( &$objects ) { if ( 'object' === $record['kind'] ) { $objects[$record['source_id']] = $record; } return true; } ); } finally { fclose( $stream ); }
			if ( is_wp_error( $read ) || $read['format_version'] !== $archive['manifest']['scope']['references'] || count( $objects ) !== count( $mappings ) || array_diff_key( $objects, $mappings ) || array_diff_key( $mappings, $objects ) ) { return self::invalid(); }
			$bindings = array(); $targets = array();
			foreach ( $objects as $source_id => $source ) {
				$target = $mappings[$source_id]; if ( null !== $target && ( ! WholeStateReferenceRecord::id( $target ) || isset( $targets[$target] ) ) ) { return self::invalid(); }
				$current = null;
				if ( null !== $target ) {
					$current = $repository->object( $target );
					if ( is_wp_error( $current ) || ! is_string( $current['identity_sha256'] ) || ! is_string( $source['post_type'] ) || $source['post_type'] !== $current['post_type'] ) { return self::invalid(); }
					if ( 'same_installation' === $mode && ( $target !== $source_id || $source['identity_sha256'] !== $current['identity_sha256'] ) ) { return self::invalid(); }
					$targets[$target] = true;
				}
				$bindings[$source_id] = array( 'source_type' => $source['post_type'], 'target_id' => $target, 'target_sha256' => null === $current ? null : $current['identity_sha256'] );
			}
			$proof = new self(); $proof->archive_path = $path; $proof->archive_sha256 = $sha; $proof->actor = $actor; $proof->actor_sha256 = $actor_sha; $proof->generation = $generation; $proof->bindings = $bindings; $proof->provenance = ! is_wp_error( $origin );
			return is_wp_error( $proof->assert_current() ) ? self::invalid() : $proof;
		} catch ( \Throwable $error ) { return self::invalid(); }
		finally { if ( ! $zip->close() ) { return self::invalid(); } }
	}
	/** Only editable configuration/design references may use this explicit mapping. */
	public function target_for( $source_id, $expected_type ) {
		if ( ! WholeStateReferenceRecord::id( $source_id ) || ! is_string( $expected_type ) || ! array_key_exists( $source_id, $this->bindings ) ) { return self::invalid(); }
		$binding = $this->bindings[$source_id];
		if ( null !== $binding['source_type'] && $binding['source_type'] !== $expected_type ) { return self::invalid(); }
		return $binding['target_id'];
	}
	public function assert_current() {
		$repository = new WholeStateReferenceRepository();
		if ( $this->actor !== (int) get_current_user_id() || ! current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_DATA_TOOLS ) || $this->generation !== ( new DomainGeneration() )->read_current() || $this->actor_sha256 !== $repository->account_identity( $this->actor ) || is_link( $this->archive_path ) || ! is_file( $this->archive_path ) || hash_file( 'sha256', $this->archive_path ) !== $this->archive_sha256 ) { return self::invalid(); }
		foreach ( $this->bindings as $binding ) { if ( null === $binding['target_id'] ) { continue; } $object = $repository->object( $binding['target_id'] ); if ( is_wp_error( $object ) || $object['post_type'] !== $binding['source_type'] || $object['identity_sha256'] !== $binding['target_sha256'] ) { return self::invalid(); } }
		return true;
	}
	public function receipt() { return array( 'archive_sha256' => $this->archive_sha256, 'actor_user_id' => $this->actor, 'provenance_verified' => $this->provenance, 'mapped' => count( array_filter( $this->bindings, static function ( $row ) { return null !== $row['target_id']; } ) ), 'disconnected' => count( array_filter( $this->bindings, static function ( $row ) { return null === $row['target_id']; } ) ) ); }
	private static function invalid() { return new \WP_Error( 'certloom_whole_object_review_invalid', __( 'Review every restored course, product and media connection against the current site. Changed objects, mismatched types or incomplete mappings cannot replace those references.', 'certloom' ) ); }
}
