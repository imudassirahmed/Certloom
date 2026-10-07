<?php
namespace Certloom\Application\Restore;

use Certloom\Application\Backup\DomainBackupReader;
use Certloom\Application\Backup\DomainBackupFormat;
use Certloom\Application\Backup\WholeStateBackupReader;
use Certloom\Application\Backup\WholeStateReferenceFormat;
use Certloom\Application\Backup\WholeStateReferenceRecord;
use Certloom\Application\Recipient\RecipientAccountLink;
use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Infrastructure\Backup\WholeStateProvenanceRepository;
use Certloom\Infrastructure\Backup\WholeStateReferenceRepository;
use Certloom\Infrastructure\Data\DomainGeneration;
use Certloom\Infrastructure\Files\PrivateJobFileStore;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Actual archive and explicit target review, never an archive-supplied ownership flag. */
final class WholeStateAccountMappingProof {
	const MAX_REVIEW_ACCOUNTS = 5000;
	private $archive_path;
	private $archive_sha256;
	private $actor_id;
	private $actor_sha256;
	private $generation;
	private $bindings;
	private $provenance;
	private function __construct() {}
	private function __clone() {}
	public function review_choices() { $choices = array(); foreach ( $this->bindings as $id => $binding ) { $choices[$id] = $binding['target_user_id']; } return $choices; }
	public function fingerprint() { return ( new CanonicalJson() )->hash( array( $this->archive_sha256, $this->actor_id, $this->actor_sha256, $this->generation, $this->bindings, $this->provenance ) ); }
	public function __serialize() { throw new \LogicException( 'Recovery authority must be rebuilt from the actual archive and current review.' ); }
	public function __unserialize( array $data ) { throw new \LogicException( 'Serialized recovery authority is not accepted.' ); }

	/** Every linked source Recipient needs exactly one explicit target ID or null (unlink). */
	public static function review( $archive_path, $archive_sha256, $actor_id, array $mappings, $mode ) {
		if ( ! is_int( $actor_id ) || $actor_id < 1 || $actor_id !== (int) get_current_user_id() || ! current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_DATA_TOOLS ) || ! in_array( $mode, array( 'same_installation', 'reviewed_target' ), true ) || ! WholeStateReferenceRecord::sha( $archive_sha256 ) || count( $mappings ) > self::MAX_REVIEW_ACCOUNTS ) { return self::invalid(); }
		$archive = ( new WholeStateBackupReader() )->analyze( $archive_path );
		if ( is_wp_error( $archive ) || $archive['archive_sha256'] !== $archive_sha256 || $archive['uncompressed_bytes'] > \Certloom\Application\Backup\WholeStateBackupBuilder::MAX_CAPTURE_BYTES ) { return self::invalid(); }
		$origin = ( new WholeStateProvenanceRepository() )->verify( $archive['manifest'] );
		if ( 'same_installation' === $mode && is_wp_error( $origin ) ) { return self::invalid(); }
		// A matching installation claim with a broken signature is not cross-site review.
		if ( is_wp_error( $origin ) ) {
			$local = ( new WholeStateProvenanceRepository() )->identity();
			if ( ! is_wp_error( $local ) && $local['installation_id'] === $archive['manifest']['source']['installation_id'] && $local['blog_id'] === $archive['manifest']['source']['blog_id'] ) { return self::invalid(); }
		}
		$repo = new WholeStateReferenceRepository(); $actor_sha = $repo->account_identity( $actor_id ); $generation = ( new DomainGeneration() )->read_current();
		if ( ! is_string( $actor_sha ) || is_wp_error( $generation ) ) { return self::invalid(); }
		$zip = new \ZipArchive(); if ( true !== $zip->open( $archive_path, \ZipArchive::RDONLY ) ) { return self::invalid(); }
		$files = new PrivateJobFileStore(); $temporary = null; $temporary_created = false;
		try {
			$refs = array(); $h = $zip->getStream( 'data/references.jsonl' );
			if ( ! is_resource( $h ) ) { return self::invalid(); }
			try { $reference_result = ( new WholeStateReferenceFormat() )->analyze( $h, static function ( $row ) use ( &$refs ) { if ( 'account' !== $row['kind'] ) { return true; } if ( count( $refs ) >= self::MAX_REVIEW_ACCOUNTS ) { return self::invalid(); } $refs[$row['recipient_id']] = $row; return true; } ); }
			finally { fclose( $h ); }
			if ( is_wp_error( $reference_result ) || $reference_result['format_version'] !== $archive['manifest']['scope']['references'] || count( $refs ) !== count( $mappings ) ) { return self::invalid(); }
			foreach ( $mappings as $id => $target ) { if ( ! self::id( $id ) || ! isset( $refs[(int) $id] ) || ( null !== $target && ! WholeStateReferenceRecord::id( $target ) ) ) { return self::invalid(); } }
			$key = bin2hex( random_bytes( 16 ) ); $temporary = $files->create_path( $key, 'whole-account-review' ); if ( is_wp_error( $temporary ) ) { $temporary = null; return self::invalid(); }
			$in = $zip->getStream( 'data/domain.cvbackup' ); $out = fopen( $temporary, 'xb' );
			$temporary_created = is_resource( $out );
			if ( ! is_resource( $in ) || ! is_resource( $out ) ) { if ( is_resource( $in ) ) { fclose( $in ); } if ( is_resource( $out ) ) { fclose( $out ); } return self::invalid(); }
			$member = $archive['manifest']['members']['data/domain.cvbackup'];
			$copied = stream_copy_to_stream( $in, $out, $member['bytes'] + 1 ); $flushed = fflush( $out ); fclose( $in ); fclose( $out ); @chmod( $temporary, 0600 );
			if ( $copied !== $member['bytes'] || ! $flushed || hash_file( 'sha256', $temporary ) !== $member['sha256'] || is_wp_error( ( new DomainBackupReader() )->analyze( $temporary ) ) ) { return self::invalid(); }
			$bindings = array(); $targets = array(); $format = new DomainBackupFormat(); $h = fopen( $temporary, 'rb' );
			try {
				for ( $line_no = 1; null !== ( $line = $format->read_line( $h, $line_no ) ); ++$line_no ) {
					if ( is_wp_error( $line ) ) { return self::invalid(); } $item = json_decode( $line, true );
					if ( ! is_array( $item ) || JSON_ERROR_NONE !== json_last_error() || wp_json_encode( $item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n" !== $line || ( 1 === $line_no && DomainBackupFormat::VERSION !== ( $item['format_version'] ?? null ) ) ) { return self::invalid(); }
					if ( 'entity' !== ( $item['type'] ?? null ) || 'recipients' !== ( $item['entity'] ?? null ) ) { continue; }
					$row = $item['data']; if ( empty( $row['wp_user_id'] ) ) { continue; }
					if ( ! self::id( $row['id'] ?? null ) || ! self::id( $row['wp_user_id'] ) || isset( $bindings[(int) $row['id']] ) || ! isset( $refs[(int) $row['id']] ) ) { return self::invalid(); }
					$id = (int) $row['id']; $source = $refs[$id]; $target = $mappings[$id];
					if ( $source['source_user_id'] !== (int) $row['wp_user_id'] ) { return self::invalid(); }
					$digest = null;
					if ( null !== $target ) {
						if ( isset( $targets[$target] ) ) { return self::invalid(); } $targets[$target] = true;
						$digest = $repo->account_identity( $target ); if ( ! is_string( $digest ) || ! self::member( $target ) ) { return self::invalid(); }
						if ( 'same_installation' === $mode && ( $source['source_user_id'] !== $target || ! is_string( $source['identity_sha256'] ) || ! hash_equals( $source['identity_sha256'], $digest ) ) ) { return self::invalid(); }
					}
					$projected = $row; $projected['wp_user_id'] = null === $target ? null : (string) $target;
					$custom = json_decode( $row['custom_fields'] ?? '', true ); if ( null !== $row['custom_fields'] && '' !== $row['custom_fields'] && ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $custom ) ) ) { return self::invalid(); } $custom = is_array( $custom ) ? $custom : array();
					$metadata = array( 'schema_version' => 1, 'generation' => substr( hash( 'sha256', $archive_sha256 . '|' . $id . '|' . ( $target ?? 'unlink' ) . '|' . ( $digest ?? '' ) ), 0, 32 ), 'name_authority' => 'organization', 'history' => array() );
					if ( null !== $target ) { $creation = $repo->account_creation_identity( $target ); if ( ! is_string( $creation ) ) { return self::invalid(); } $metadata['restored_account_identity_sha256'] = $creation; }
					if ( isset( $custom[RecipientAccountLink::KEY] ) ) { $old = ( new RecipientAccountLink() )->normalize( $custom[RecipientAccountLink::KEY] ); if ( is_wp_error( $old ) ) { return self::invalid(); } $metadata['history'] = $old['history']; }
					$custom[RecipientAccountLink::KEY] = $metadata; $projected['custom_fields'] = wp_json_encode( $custom, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); if ( false === $projected['custom_fields'] ) { return self::invalid(); }
					$bindings[$id] = array( 'source_sha256' => ( new CanonicalJson() )->hash( $row ), 'target_user_id' => $target, 'target_sha256' => $digest, 'custom_fields' => $projected['custom_fields'], 'projected_sha256' => ( new CanonicalJson() )->hash( $projected ) );
				}
			} finally { fclose( $h ); }
			if ( count( $bindings ) !== count( $refs ) || hash_file( 'sha256', $archive_path ) !== $archive_sha256 || $generation !== ( new DomainGeneration() )->read_current() || $actor_sha !== $repo->account_identity( $actor_id ) ) { return self::invalid(); }
			$proof = new self(); $proof->archive_path = $archive_path; $proof->archive_sha256 = $archive_sha256; $proof->actor_id = $actor_id; $proof->actor_sha256 = $actor_sha; $proof->generation = $generation; $proof->bindings = $bindings; $proof->provenance = ! is_wp_error( $origin );
			return $proof;
		} catch ( \Throwable $error ) { return self::invalid(); }
		finally { $closed = $zip->close(); $deleted = true; if ( $temporary_created && is_string( $temporary ) && $files->owns( $temporary ) ) { $deleted = $files->delete( $temporary ); } if ( ! $closed || true !== $deleted ) { return self::invalid(); } }
	}

	public function project_recipient( array $row ) {
		if ( empty( $row['wp_user_id'] ) && ! isset( $this->bindings[(int) ( $row['id'] ?? 0 )] ) ) { return $row; }
		$binding = $this->bindings[(int) ( $row['id'] ?? 0 )] ?? null;
		if ( ! $binding || $binding['source_sha256'] !== ( new CanonicalJson() )->hash( $row ) ) { return self::invalid(); }
		$row['wp_user_id'] = null === $binding['target_user_id'] ? null : (string) $binding['target_user_id']; $row['custom_fields'] = $binding['custom_fields']; return $row;
	}
	public function permits_source( array $row ) { $b = $this->bindings[(int) ( $row['id'] ?? 0 )] ?? null; return $b && $b['source_sha256'] === ( new CanonicalJson() )->hash( $row ); }
	public function permits_projected( array $row ) { $b = $this->bindings[(int) ( $row['id'] ?? 0 )] ?? null; return $b && $b['projected_sha256'] === ( new CanonicalJson() )->hash( $row ); }
	/** Recheck before staging/publication; no account or role is created or changed. */
	public function assert_current( $lock_accounts = false ) {
		$repo = new WholeStateReferenceRepository();
		if ( $this->actor_id !== (int) get_current_user_id() || ! current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_DATA_TOOLS ) ) { return self::invalid(); }
		if ( $this->generation !== ( new DomainGeneration() )->read_current() || $this->actor_sha256 !== $repo->account_identity( $this->actor_id, $lock_accounts ) || is_link( $this->archive_path ) || ! is_file( $this->archive_path ) || hash_file( 'sha256', $this->archive_path ) !== $this->archive_sha256 ) { return self::invalid(); }
		foreach ( $this->bindings as $b ) { if ( null !== $b['target_user_id'] && ( $b['target_sha256'] !== $repo->account_identity( $b['target_user_id'], $lock_accounts ) || ! self::member( $b['target_user_id'], $lock_accounts ) ) ) { return self::invalid(); } }
		return true;
	}
	public function receipt() { return array( 'archive_sha256' => $this->archive_sha256, 'actor_user_id' => $this->actor_id, 'provenance_verified' => $this->provenance, 'linked' => count( array_filter( $this->bindings, static function ( $b ) { return null !== $b['target_user_id']; } ) ), 'unlinked' => count( array_filter( $this->bindings, static function ( $b ) { return null === $b['target_user_id']; } ) ) ); }
	private static function member( $id, $lock = false ) { global $wpdb; $wpdb->last_error = ''; $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT umeta_id FROM %i WHERE user_id=%d AND meta_key=%s ORDER BY umeta_id LIMIT 2' . ( $lock ? ' FOR UPDATE' : '' ), $wpdb->usermeta, $id, $wpdb->prefix . 'capabilities' ), ARRAY_A ); return '' === (string) $wpdb->last_error && is_array( $rows ) && count( $rows ) === 1; }
	private static function id( $id ) { return WholeStateReferenceRecord::id( $id ) || ( is_string( $id ) && preg_match( '/^[1-9][0-9]*$/D', $id ) && (string) (int) $id === $id && (int) $id > 0 ); }
	private static function invalid() { return new \WP_Error( 'certloom_whole_account_review_invalid', __( 'Review every restored account connection against the current site. Changed identities, incomplete mappings or unverified source ownership cannot grant recipient access.', 'certloom' ) ); }
}
