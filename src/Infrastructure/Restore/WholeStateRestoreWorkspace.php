<?php
namespace Certloom\Infrastructure\Restore;

use Certloom\Application\Restore\WholeStateRestoreReview;
use Certloom\Application\Restore\WholeStateLegacyFormat;
use Certloom\Application\Restore\WholeStateDeliveryRowValidator;
use Certloom\Application\Restore\WholeStateOptionRows;
use Certloom\Application\Backup\WholeStateBackupReader;
use Certloom\Application\Backup\WholeStateDeliveryRecord;
use Certloom\Application\Backup\WholeStateOptionRecord;
use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Infrastructure\Backup\DomainBackupRepository;
use Certloom\Infrastructure\Backup\WholeStateProvenanceRepository;
use Certloom\Infrastructure\Backup\SecretVault;
use Certloom\Infrastructure\Data\DatabaseOptions;
use Certloom\Infrastructure\Data\DomainGeneration;
use Certloom\Infrastructure\Data\DomainMaintenanceLock;
use Certloom\Infrastructure\Data\MaintenanceMutex;
use Certloom\Infrastructure\Database\SchemaManager;
use Certloom\Infrastructure\Database\Transaction;
use Certloom\Infrastructure\Files\PrivateJobFileStore;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** One complete promotion boundary for 21 tables, reviewed settings and exact private assets. */
final class WholeStateRestoreWorkspace {
	const RECEIPTS = 'certloom_whole_restore_receipts';
	const MEMBER_NAMES = array( 'data/domain.cvbackup', 'data/legacy.cvbackup', 'data/deliveries.jsonl' );
	private $journal;
	private $maintenance;
	public function __construct() { $this->journal = new WholeStateRestoreJournal(); $this->maintenance = new DomainMaintenanceLock(); }
	public static function entities() { return array_merge( DomainBackupRepository::entity_types(), array( 'legacy_registry', 'email_deliveries', 'destination_deliveries' ) ); }
	public static function target_matches( $target, array $identity ) { return is_array( $target ) && WholeStateOptionRows::same( $target, $identity ); }

	public static function valid_member( $member, $owner, $index ) {
		return is_int( $index ) && isset( self::MEMBER_NAMES[$index] ) && is_array( $member ) && count( $member ) === 4 && self::MEMBER_NAMES[$index] === ( $member['name'] ?? null ) && is_string( $member['path'] ?? null ) && ( new PrivateJobFileStore() )->owns( $member['path'] ) && preg_match( '/^' . $owner . '-whole-restore-member-[a-f0-9]{48}\.data$/D', basename( $member['path'] ) ) && is_string( $member['sha256'] ?? null ) && preg_match( '/^[a-f0-9]{64}$/D', $member['sha256'] ) && is_int( $member['bytes'] ?? null ) && $member['bytes'] > 0 && $member['bytes'] <= \Certloom\Application\Backup\WholeStateBackupBuilder::MAX_CAPTURE_BYTES;
	}
	public static function valid_generations( $snapshot ) {
		$keys = array( DomainGeneration::OPTION, \Ecapla_CertVerify_Backup::GENERATION_OPTION );
		if ( ! is_array( $snapshot ) || count( $snapshot ) !== 2 || array_diff( array_keys( $snapshot ), $keys ) ) { return false; }
		foreach ( $snapshot as $entry ) {
			if ( ! is_array( $entry ) || count( $entry ) !== 3 || array_diff( array( 'exists', 'raw', 'autoload' ), array_keys( $entry ) ) || ! is_bool( $entry['exists'] ) || ( $entry['exists'] ? ( ! is_string( $entry['raw'] ) || strlen( $entry['raw'] ) > 4096 || ! in_array( $entry['autoload'], array( 'yes', 'no', 'on', 'off', 'auto', 'auto-on', 'auto-off' ), true ) ) : ( null !== $entry['raw'] || null !== $entry['autoload'] ) ) ) { return false; }
		}
		return true;
	}
	/** Receipts describe a completed local commit; imported metadata cannot replace them. */
	public static function valid_receipt( $receipt ) {
		$keys = array( 'owner_token', 'source_sha256', 'counts', 'records', 'committed_at', 'generation' );
		if ( ! is_array( $receipt ) || count( $receipt ) !== count( $keys ) || array_diff( array_keys( $receipt ), $keys )
			|| ! is_string( $receipt['owner_token'] ) || ! preg_match( '/^[a-f0-9]{32}$/D', $receipt['owner_token'] )
			|| ! is_string( $receipt['source_sha256'] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $receipt['source_sha256'] )
			|| ! is_array( $receipt['counts'] ) || count( $receipt['counts'] ) !== count( self::entities() ) || array_diff( array_keys( $receipt['counts'] ), self::entities() )
			|| ! is_int( $receipt['records'] ) || ! is_int( $receipt['committed_at'] ) || $receipt['committed_at'] < 1
			|| ! is_string( $receipt['generation'] ) || ! preg_match( '/^[0-9]+\.[0-9]{6}\|[a-f0-9]{24}$/D', $receipt['generation'] ) ) { return false; }
		foreach ( $receipt['counts'] as $count ) { if ( ! is_int( $count ) || $count < 0 || $count > 500000 ) { return false; } }
		return $receipt['records'] === array_sum( $receipt['counts'] );
	}

	public function start( WholeStateRestoreReview $review, $owner ) {
		return MaintenanceMutex::run( function () use ( $review, $owner ) {
			if ( ! $this->owner( $owner ) ) { return $this->invalid(); }
			$receipt = $this->receipt( $owner ); if ( is_wp_error( $receipt ) ) { return $receipt; } if ( $receipt ) { return $receipt['source_sha256'] === $review->sha256() ? array( 'complete' => true, 'receipt' => $receipt ) : $this->invalid(); }
			if ( is_wp_error( $review->assert_current() ) ) { return $this->invalid(); }
			$current = $this->journal->read(); if ( is_wp_error( $current ) ) { return $current; }
			if ( $current ) { return $current['owner_token'] === $owner && $current['review_packet']['fingerprint'] === $review->fingerprint() ? $current : $this->invalid(); }
			$busy = $this->jobs_quiet( $owner ); if ( is_wp_error( $busy ) ) { return $busy; }
			$identity = ( new WholeStateProvenanceRepository() )->ensure( $review->packet()['actor'] ); if ( is_wp_error( $identity ) ) { return $identity; }
			$lock = $this->maintenance->acquire( $owner, 'whole_restore', 3600 ); if ( is_wp_error( $lock ) ) { return $lock; }
			if ( is_wp_error( $review->assert_current() ) ) { $this->maintenance->release( $owner ); return $this->invalid(); }
			foreach ( self::entities() as $entity ) { foreach ( array( 'stage', 'rollback' ) as $kind ) { $exists = $this->exists( $this->table( $owner, $kind, $entity ) ); if ( is_wp_error( $exists ) || $exists ) { $this->maintenance->release( $owner ); return $this->invalid(); } } }
			$generations = $this->generation_snapshot(); if ( is_wp_error( $generations ) ) { $this->maintenance->release( $owner ); return $generations; }
			$archive = ( new WholeStateBackupReader() )->analyze( $review->path() ); if ( is_wp_error( $archive ) ) { $this->maintenance->release( $owner ); return $archive; }
			$members = array(); $files = new PrivateJobFileStore();
			foreach ( self::MEMBER_NAMES as $name ) {
				$path = $files->create_path( $owner, 'whole-restore-member' ); if ( is_wp_error( $path ) ) { $this->maintenance->release( $owner ); return $path; }
				$members[] = array( 'name' => $name, 'path' => $path, 'sha256' => $archive['manifest']['members'][$name]['sha256'], 'bytes' => $archive['manifest']['members'][$name]['bytes'] );
			}
			$state = array( 'version' => 1, 'purpose' => WholeStateRestoreJournal::PURPOSE, 'target' => $identity, 'owner_token' => $owner, 'source_path' => $review->path(), 'source_sha256' => $review->sha256(), 'review_packet' => $review->packet(), 'settings_before' => $review->baseline(), 'settings_after' => $review->proposal(), 'generations_before' => $generations, 'members' => $members, 'cursor' => 0, 'offset' => 0, 'design_cursor' => 0, 'designs_complete' => false, 'cleanup_cursor' => 0, 'counts' => array_fill_keys( self::entities(), 0 ), 'expected_counts' => $review->counts(), 'phase' => 'building', 'revision' => 0, 'created_at' => time(), 'receipt' => array() );
			$saved = $this->journal->write( $state ); if ( is_wp_error( $saved ) ) { $this->maintenance->release( $owner ); return $saved; }
			return $saved;
		} );
	}

	/** The caller retains typed authority; each slice loads at most 200 SQL records or one asset. */
	public function continue_stage( $owner, WholeStateRestoreReview $review, $limit = 200 ) {
		return MaintenanceMutex::run( function () use ( $owner, $review, $limit ) {
			$j = $this->owned_journal( $owner ); if ( is_wp_error( $j ) ) { return $j; }
			if ( $j['review_packet']['fingerprint'] !== $review->fingerprint() || is_wp_error( $review->assert_current() ) ) { return $this->invalid(); }
			if ( 'ready' === $j['phase'] ) { return $j; } if ( 'building' !== $j['phase'] ) { return $this->invalid(); }
			$prepared = $this->prepare_files_and_tables( $j ); if ( is_wp_error( $prepared ) ) { return $prepared; }
			if ( $j['cursor'] < 3 ) {
				$member = $j['members'][$j['cursor']]; $handle = fopen( $member['path'], 'rb' ); if ( ! is_resource( $handle ) ) { return $this->invalid(); }
				try {
					if ( 0 !== fseek( $handle, $j['offset'] ) ) { return $this->invalid(); }
					for ( $i = 0; $i < max( 1, min( 200, (int) $limit ) ); ++$i ) {
						$line = fgets( $handle, 8388609 );
						if ( false === $line ) { if ( ! feof( $handle ) ) { return $this->invalid(); } ++$j['cursor']; $j['offset'] = 0; break; }
						$item = json_decode( $line, true, 64 ); if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $item ) || strlen( $line ) > 8388608 ) { return $this->invalid(); }
						$type = $item['type'] ?? null;
						if ( in_array( $type, array( 'manifest', 'integrity' ), true ) ) { $j['offset'] = ftell( $handle ); continue; }
						if ( 0 === $j['cursor'] && 'entity' === $type ) { $entity = $item['entity']; $row = $review->project_domain( $entity, $item['data'] ); }
						elseif ( 1 === $j['cursor'] && 'record' === $type ) { $entity = 'legacy_registry'; $row = ( new WholeStateLegacyFormat() )->to_row( $item['data'], $review->field_catalog(), $j['counts'][$entity] + 1 ); }
						elseif ( 2 === $j['cursor'] && 'delivery' === $type ) { $entity = $item['data']['entity']; $row = ( new WholeStateDeliveryRecord() )->to_row( $item['data'] ); if ( ! is_wp_error( $row ) ) { $row = ( new WholeStateDeliveryRowValidator() )->project( $entity, $row, $j['source_sha256'] ); } }
						else { return $this->invalid(); }
						if ( is_wp_error( $row ) ) { return $row; }
						$inserted = $this->insert( $owner, $entity, $row ); if ( is_wp_error( $inserted ) ) { return $inserted; }
						if ( ++$j['counts'][$entity] > $j['expected_counts'][$entity] ) { return $this->invalid(); }
						$j['offset'] = ftell( $handle );
					}
				} finally { fclose( $handle ); }
				return $this->journal->write( $j );
			}
			if ( 0 === $j['design_cursor'] && ! $j['designs_complete'] && ! WholeStateOptionRows::same( $j['counts'], $j['expected_counts'] ) ) { return $this->invalid(); }
			if ( ! $j['designs_complete'] ) {
				return DomainMaintenanceLock::run_internal( function () use ( $j, $review ) { return ( new Transaction() )->run( function () use ( $j, $review ) {
					$changed = $this->prepare_designs( $j, $review ); return is_wp_error( $changed ) ? $changed : $this->journal->write( $changed );
				} ); } );
			}
			$relationships = $this->relationships( $j, $review ); if ( is_wp_error( $relationships ) ) { return $relationships; }
			$assets = new WholeStateAssetPublication();
			$publication = $assets->start( $j['source_path'], $j['source_sha256'], $owner ); if ( is_wp_error( $publication ) ) { return $publication; }
			$publication = $assets->continue_publication( $owner ); if ( is_wp_error( $publication ) ) { return $publication; }
			if ( 'published' === $publication['phase'] ) { $j['phase'] = 'ready'; }
			return $this->journal->write( $j );
		} );
	}

	public function commit( $owner, WholeStateRestoreReview $review ) {
		return MaintenanceMutex::run( function () use ( $owner, $review ) {
			$receipt = $this->receipt( $owner );
			if ( is_wp_error( $receipt ) ) { return $receipt; }
			$pending = $this->journal->read();
			if ( is_wp_error( $pending ) ) { return $pending; }
			if ( ! $pending && $receipt ) { return $receipt['source_sha256'] === $review->sha256() ? array( 'complete' => true, 'receipt' => $receipt ) : $this->invalid(); }
			$j = $this->owned_journal( $owner ); if ( is_wp_error( $j ) ) { return $j; }
			if ( 'committed' === $j['phase'] ) { return $this->finish_committed( $j ); }
			if ( 'ready' !== $j['phase'] || $j['review_packet']['fingerprint'] !== $review->fingerprint() || is_wp_error( $review->assert_current() ) || is_wp_error( $this->jobs_quiet( $owner ) ) ) { return $this->invalid(); }
			$prepared = DomainMaintenanceLock::run_internal( function () use ( $j, $review ) {
				return ( new Transaction() )->run( function () use ( $j, $review ) {
					$relationships = $this->relationships( $j, $review ); if ( is_wp_error( $relationships ) ) { return $relationships; }
					$j['phase'] = 'prepared'; return $this->journal->write( $j );
				} );
			} );
			if ( is_wp_error( $prepared ) ) { return $prepared; } $j = $prepared;
			if ( is_wp_error( $review->assert_current() ) ) { return $this->rollback_error( $j ); }
			$swapped = $this->swap( $j, false ); if ( is_wp_error( $swapped ) ) { return $this->rollback_error( $j ); }
			$j['phase'] = 'swapped'; $saved = $this->journal->write( $j ); if ( is_wp_error( $saved ) ) { return $this->rollback_error( $j ); } $j = $saved;
			$committed = DomainMaintenanceLock::run_internal( function () use ( $j ) {
				return ( new Transaction() )->run( function () use ( $j ) {
					$published = ( new WholeStateOptionPublication() )->apply( $j['settings_after'], $j['owner_token'], $j['settings_before'] ); if ( is_wp_error( $published ) ) { return $published; }
					$markers = array(); foreach ( array_keys( $j['generations_before'] ) as $key ) { $markers[$key] = array( 'exists' => true, 'raw' => sprintf( '%.6F|%s', microtime( true ), bin2hex( random_bytes( 12 ) ) ), 'autoload' => 'off' ); }
					$advanced = $this->publish_generations( $markers ); if ( is_wp_error( $advanced ) ) { return $advanced; }
					$j['receipt'] = array( 'owner_token' => $j['owner_token'], 'source_sha256' => $j['source_sha256'], 'counts' => $j['counts'], 'records' => array_sum( $j['counts'] ), 'committed_at' => time(), 'generation' => $markers[DomainGeneration::OPTION]['raw'] );
					$j['phase'] = 'committed'; return $this->journal->write( $j );
				} );
			} );
			if ( is_wp_error( $committed ) ) { return $this->rollback_error( $j ); }
			return $this->finish_committed( $committed );
		} );
	}

	/** Boot recovery never imports approval: it only rolls back or finishes a durable local commit. */
	public function recover() {
		return MaintenanceMutex::run( function () {
			$j = $this->journal->read(); if ( is_wp_error( $j ) || ! $j ) { return is_wp_error( $j ) ? $j : true; }
			$allowed = $this->maintenance->recovery_allowed( $j['owner_token'] );
			if ( is_wp_error( $allowed ) ) { return $allowed; }
			// Terminal phases cannot promote or roll back again. Their bounded,
			// mutex-protected cleanup must continue even after recovery renews its
			// own lease; active staging remains exclusively owned by its worker.
			if ( ! $allowed && ! in_array( $j['phase'], array( 'committed', 'rolled_back' ), true ) ) { return true; }
			$owned = $this->maintenance->acquire( $j['owner_token'], 'whole_restore_recovery', 3600 ); if ( is_wp_error( $owned ) ) { return $owned; }
			return 'committed' === $j['phase'] ? $this->finish_committed( $j ) : $this->rollback( $j['owner_token'] );
		} );
	}
	public function rollback( $owner ) {
		return MaintenanceMutex::run( function () use ( $owner ) {
			$j = $this->owned_journal( $owner ); if ( is_wp_error( $j ) ) { return $j; }
			if ( 'committed' === $j['phase'] ) { return $this->invalid(); }
			// Once the old state is durably restored, some rollback aliases may
			// already have been dropped. Resume cleanup without another rename.
			$restored = $j;
			if ( 'rolled_back' !== $j['phase'] ) {
				$swapped = $this->swap( $j, true ); if ( is_wp_error( $swapped ) ) { return $swapped; }
				$restored = DomainMaintenanceLock::run_internal( function () use ( $j ) {
					return ( new Transaction() )->run( function () use ( $j ) {
						$result = ( new WholeStateOptionPublication() )->apply( $j['settings_before'], $j['owner_token'] ); if ( is_wp_error( $result ) ) { return $result; }
						$result = $this->publish_generations( $j['generations_before'] ); if ( is_wp_error( $result ) ) { return $result; }
						$j['phase'] = 'rolled_back'; return $this->journal->write( $j );
					} );
				} );
				if ( is_wp_error( $restored ) ) { return $restored; }
			}
			$assets = new WholeStateAssetPublication(); $pending = $assets->pending_owner(); if ( is_wp_error( $pending ) ) { return $pending; }
			if ( $pending ) { $cleaned = $assets->rollback( $owner ); if ( is_wp_error( $cleaned ) || is_array( $cleaned ) ) { return is_wp_error( $cleaned ) ? $cleaned : array( 'complete' => false ); } }
			return $this->cleanup( $restored );
		} );
	}

	private function prepare_files_and_tables( array $j ) {
		global $wpdb;
		if ( ! is_file( $j['source_path'] ) || is_link( $j['source_path'] ) || hash_file( 'sha256', $j['source_path'] ) !== $j['source_sha256'] ) { return $this->invalid(); }
		$zip = new \ZipArchive(); if ( true !== $zip->open( $j['source_path'], \ZipArchive::RDONLY ) ) { return $this->invalid(); }
		try {
			foreach ( $j['members'] as $member ) {
				if ( ! file_exists( $member['path'] ) ) {
					$in = $zip->getStream( $member['name'] ); $out = fopen( $member['path'], 'xb' );
					if ( ! is_resource( $in ) || ! is_resource( $out ) ) { if ( is_resource( $in ) ) { fclose( $in ); } if ( is_resource( $out ) ) { fclose( $out ); } return $this->invalid(); }
					try { $bytes = stream_copy_to_stream( $in, $out, $member['bytes'] + 1 ); $flushed = fflush( $out ); } finally { fclose( $in ); fclose( $out ); }
					@chmod( $member['path'], 0600 ); if ( $bytes !== $member['bytes'] || ! $flushed ) { return $this->invalid(); }
				}
				if ( is_link( $member['path'] ) || filesize( $member['path'] ) !== $member['bytes'] || hash_file( 'sha256', $member['path'] ) !== $member['sha256'] ) { return $this->invalid(); }
			}
		} finally { $zip->close(); }
		foreach ( self::entities() as $entity ) {
			$stage = $this->table( $j['owner_token'], 'stage', $entity ); $exists = $this->exists( $stage ); if ( is_wp_error( $exists ) ) { return $exists; }
			if ( ! $exists ) { $wpdb->last_error = ''; $created = $wpdb->query( $wpdb->prepare( 'CREATE TABLE %i LIKE %i', $stage, $this->live( $entity ) ) ); if ( false === $created || '' !== (string) $wpdb->last_error || true !== $this->exists( $stage ) ) { return $this->invalid(); } }
		}
		return true;
	}
	private function insert( $owner, $entity, array $row ) {
		global $wpdb; if ( ! in_array( $entity, self::entities(), true ) ) { return $this->invalid(); }
		$columns = 'legacy_registry' === $entity ? WholeStateLegacyFormat::columns() : SchemaManager::column_names( $entity );
		if ( count( $columns ) !== count( $row ) || array_diff( array_keys( $row ), $columns ) ) { return $this->invalid(); }
		$table = $this->table( $owner, 'stage', $entity ); $wpdb->last_error = '';
		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', $table, $row['id'] ), ARRAY_A ); if ( '' !== (string) $wpdb->last_error ) { return $this->invalid(); }
		if ( $existing ) { foreach ( $row as $key => $value ) { if ( $existing[$key] !== ( null === $value ? null : (string) $value ) ) { return $this->invalid(); } } return true; }
		$wpdb->last_error = ''; $written = $wpdb->insert( $table, $row ); return 1 === $written && '' === (string) $wpdb->last_error ? true : $this->invalid();
	}
	private function relationships( array $j, WholeStateRestoreReview $review ) {
		global $wpdb;
		foreach ( self::entities() as $entity ) {
			$wpdb->last_error = '';
			$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $this->table( $j['owner_token'], 'stage', $entity ) ) );
			if ( '' !== (string) $wpdb->last_error || null === $count || (int) $count !== $j['counts'][$entity] ) { return $this->invalid(); }
		}
		$workspace = array( 'owner_token' => $j['owner_token'], 'suffix' => substr( $j['owner_token'], 0, 12 ) );
		$domain = ( new DomainRestoreWorkspace( null, null, null, $review->account_proof() ) )->validate_relationships( $workspace ); if ( is_wp_error( $domain ) ) { return $domain; }
		foreach ( array( 'email_deliveries', 'destination_deliveries' ) as $entity ) {
			$wpdb->last_error = '';
			$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i d LEFT JOIN %i v ON v.id=d.version_id AND v.series_id=d.series_id WHERE v.id IS NULL', $this->table( $j['owner_token'], 'stage', $entity ), $this->table( $j['owner_token'], 'stage', 'credential_versions' ) ) );
			if ( '' !== (string) $wpdb->last_error || null === $count || (int) $count ) { return $this->invalid(); }
		}
		$decoded = ( new \Certloom\Application\Restore\WholeStateOptionSemantics() )->validate_set( $j['settings_after']['fixed'] ); if ( is_wp_error( $decoded ) ) { return $decoded; }
		$graph = \Certloom\Application\Restore\WholeStateConfigurationGraph::build( $decoded, $j['settings_after']['recommendations'], $review->field_catalog() ); if ( is_wp_error( $graph ) ) { return $graph; }
		$connected = $graph->validate_batches( function ( $entity, array $ids ) use ( $j ) {
			global $wpdb; $wpdb->last_error = '';
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( array( $this->table( $j['owner_token'], 'stage', $entity ) ), $ids ) ), ARRAY_A );
			return '' === (string) $wpdb->last_error && is_array( $rows ) ? $rows : $this->invalid();
		} );
		if ( is_wp_error( $connected ) ) { return $connected; }
		$variants = $this->template_variants( $j ); if ( is_wp_error( $variants ) ) { return $variants; }
		return true;
	}
	/** Check editable locale choices in bounded SQL batches; issued revisions are untouched. */
	private function template_variants( array $j ) {
		global $wpdb; $cursor = 0;
		do {
			$wpdb->last_error = '';
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id,metadata_json FROM %i WHERE id>%d AND metadata_json IS NOT NULL ORDER BY id LIMIT 200', $this->table( $j['owner_token'], 'stage', 'programs' ), $cursor ), ARRAY_A );
			if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) { return $this->invalid(); }
			$keys = array();
			foreach ( $rows as $row ) {
				$cursor = (int) $row['id']; $metadata = json_decode( $row['metadata_json'], true, 64 );
				foreach ( $metadata[\Certloom\Application\Program\ProgramProfile::STORAGE_KEY]['template_variants'] ?? array() as $key ) { $keys[$key] = true; }
			}
			if ( ! $keys ) { continue; }
			$wpdb->last_error = '';
			$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE machine_key IN (' . implode( ',', array_fill( 0, count( $keys ), '%s' ) ) . ')', array_merge( array( $this->table( $j['owner_token'], 'stage', 'templates' ) ), array_keys( $keys ) ) ) );
			if ( '' !== (string) $wpdb->last_error || null === $count || (int) $count !== count( $keys ) ) { return new \WP_Error( 'certloom_whole_template_variant_missing', __( 'A recovered Program locale refers to a certificate design missing from this snapshot. Repair the source Program design choices before recovery.', 'certloom' ) ); }
		} while ( count( $rows ) === 200 );
		return true;
	}
	private function prepare_designs( array $j, WholeStateRestoreReview $review ) {
		global $wpdb;
		$templates = $this->table( $j['owner_token'], 'stage', 'templates' ); $revisions = $this->table( $j['owner_token'], 'stage', 'template_revisions' );
		$wpdb->last_error = ''; $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE id>%d ORDER BY id LIMIT 20', $templates, $j['design_cursor'] ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) { return $this->invalid(); }
		foreach ( $rows as $template ) {
			$aliases = array(); $updates = array();
			foreach ( array( 'current_revision_id', 'published_revision_id' ) as $column ) {
				$id = (int) $template[$column]; if ( ! $id ) { continue; }
				if ( isset( $aliases[$id] ) ) { if ( $aliases[$id] !== $id ) { $updates[$column] = $aliases[$id]; } continue; }
				$wpdb->last_error = ''; $source = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', $revisions, $id ), ARRAY_A );
				if ( '' !== (string) $wpdb->last_error || ! is_array( $source ) ) { return $this->invalid(); }
				$layout = $review->map_layout( json_decode( $source['layout_json'], true, 64 ) ); if ( is_wp_error( $layout ) ) { return $layout; }
				$canonical = new CanonicalJson(); if ( $canonical->matches_hash( $layout, $source['layout_sha256'] ) ) { $aliases[$id] = $id; continue; }
				$wpdb->last_error = ''; $number = $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(revision_number) FROM %i WHERE template_id=%d', $revisions, $template['id'] ) ); if ( '' !== (string) $wpdb->last_error || null === $number || (int) $number >= 4294967295 ) { return $this->invalid(); }
				$clone = $source; unset( $clone['id'] ); $clone['revision_number'] = (int) $number + 1; $clone['parent_revision_id'] = $id; $clone['layout_json'] = $canonical->encode( $layout ); $clone['layout_sha256'] = $canonical->hash( $layout ); $clone['source_type'] = 'import'; $clone['created_by_user_id'] = $j['review_packet']['actor']; $clone['created_at'] = gmdate( 'Y-m-d H:i:s' );
				$wpdb->last_error = ''; $written = $wpdb->insert( $revisions, $clone ); if ( 1 !== $written || '' !== (string) $wpdb->last_error || $wpdb->insert_id < 1 ) { return $this->invalid(); }
				$aliases[$id] = (int) $wpdb->insert_id; $updates[$column] = $aliases[$id]; ++$j['counts']['template_revisions'];
			}
			if ( $updates ) { $wpdb->last_error = ''; if ( 1 !== $wpdb->update( $templates, $updates, array( 'id' => $template['id'] ) ) || '' !== (string) $wpdb->last_error ) { return $this->invalid(); } }
			$j['design_cursor'] = (int) $template['id'];
		}
		$j['designs_complete'] = count( $rows ) < 20;
		return $j;
	}

	private function swap( array $j, $rollback ) {
		global $wpdb; $owner = $j['owner_token']; $present = 0;
		foreach ( self::entities() as $entity ) { $exists = $this->exists( $this->table( $owner, 'rollback', $entity ) ); if ( is_wp_error( $exists ) ) { return $exists; } $present += (int) $exists; }
		if ( $rollback && 0 === $present ) { return true; } if ( $rollback ? 21 !== $present : 0 !== $present ) { return $this->invalid(); }
		$parts = array(); $args = array();
		foreach ( self::entities() as $entity ) {
			$stage = $this->table( $owner, 'stage', $entity ); $old = $this->table( $owner, 'rollback', $entity ); $live = $this->live( $entity );
			if ( true !== $this->exists( $live ) || ( $rollback ? false !== $this->exists( $stage ) : true !== $this->exists( $stage ) ) ) { return $this->invalid(); }
			$parts[] = '%i TO %i, %i TO %i'; array_push( $args, $rollback ? $live : $live, $rollback ? $stage : $old, $rollback ? $old : $stage, $live );
		}
		$wpdb->last_error = ''; $result = $wpdb->query( $wpdb->prepare( 'RENAME TABLE ' . implode( ', ', $parts ), $args ) );
		if ( false === $result || '' !== (string) $wpdb->last_error ) { return $this->invalid(); }
		foreach ( self::entities() as $entity ) { if ( ( $rollback ? false : true ) !== $this->exists( $this->table( $owner, 'rollback', $entity ) ) || ( $rollback ? true : false ) !== $this->exists( $this->table( $owner, 'stage', $entity ) ) ) { return $this->invalid(); } }
		return true;
	}
	private function generation_snapshot() {
		global $wpdb; $out = array();
		foreach ( array( DomainGeneration::OPTION, \Ecapla_CertVerify_Backup::GENERATION_OPTION ) as $key ) {
			$wpdb->last_error = ''; $row = $wpdb->get_row( $wpdb->prepare( 'SELECT option_value,autoload FROM %i WHERE option_name=%s', $wpdb->options, $key ), ARRAY_A ); if ( '' !== (string) $wpdb->last_error ) { return $this->invalid(); }
			$out[$key] = array( 'exists' => null !== $row, 'raw' => $row ? $row['option_value'] : null, 'autoload' => $row ? $row['autoload'] : null );
		}
		return self::valid_generations( $out ) ? $out : $this->invalid();
	}
	private function publish_generations( array $snapshot ) {
		global $wpdb; if ( ! self::valid_generations( $snapshot ) || ! Transaction::owns_current() ) { return $this->invalid(); }
		foreach ( $snapshot as $key => $entry ) {
			$wpdb->last_error = ''; $old = $wpdb->get_row( $wpdb->prepare( 'SELECT option_value,autoload FROM %i WHERE option_name=%s FOR UPDATE', $wpdb->options, $key ), ARRAY_A ); if ( '' !== (string) $wpdb->last_error ) { return $this->invalid(); }
			if ( ! $entry['exists'] ) { $written = $old ? $wpdb->delete( $wpdb->options, array( 'option_name' => $key ) ) : 1; }
			else { $data = array( 'option_value' => $entry['raw'], 'autoload' => $entry['autoload'] ); $written = $old && $old['option_value'] === $entry['raw'] && $old['autoload'] === $entry['autoload'] ? 1 : ( $old ? $wpdb->update( $wpdb->options, $data, array( 'option_name' => $key ) ) : $wpdb->insert( $wpdb->options, array( 'option_name' => $key ) + $data ) ); }
			if ( 1 !== $written || '' !== (string) $wpdb->last_error ) { return $this->invalid(); }
		}
		DatabaseOptions::invalidate( array_keys( $snapshot ) ); return true;
	}
	private function finish_committed( array $j ) {
		$assets = new WholeStateAssetPublication(); $pending = $assets->pending_owner(); if ( is_wp_error( $pending ) ) { return $pending; }
		if ( $pending ) { $committed = $assets->commit_after_whole_receipt( $j['owner_token'], $j['source_sha256'] ); if ( is_wp_error( $committed ) ) { return $committed; } }
		$saved = $this->save_receipt( $j ); if ( is_wp_error( $saved ) ) { return $saved; }
		$cleaned = $this->cleanup( $j ); return is_wp_error( $cleaned ) || is_array( $cleaned ) ? $cleaned : array( 'complete' => true, 'receipt' => $j['receipt'] );
	}
	private function cleanup( array $j ) {
		global $wpdb;
		$entities = self::entities();
		for ( $i = 0; $i < 10 && $j['cleanup_cursor'] < 45; ++$i, ++$j['cleanup_cursor'] ) {
			$cursor = $j['cleanup_cursor'];
			if ( $cursor < 42 ) { $wpdb->last_error = ''; $n = $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $this->table( $j['owner_token'], $cursor % 2 ? 'rollback' : 'stage', $entities[(int) floor( $cursor / 2 )] ) ) ); if ( false === $n || '' !== (string) $wpdb->last_error ) { return $this->invalid(); } }
			else { $deleted = ( new PrivateJobFileStore() )->delete( $j['members'][$cursor - 42]['path'] ); if ( is_wp_error( $deleted ) || true !== $deleted ) { return $this->invalid(); } }
		}
		if ( $j['cleanup_cursor'] < 45 ) { $saved = $this->journal->write( $j ); return is_wp_error( $saved ) ? $saved : array( 'complete' => false ); }
		$saved = $this->journal->write( $j ); if ( is_wp_error( $saved ) ) { return $saved; }
		// Retain the sealed terminal journal if releasing its lease fails. Both
		// option rows share the checked transaction; no DDL or file work joins it.
		return DomainMaintenanceLock::run_internal( function () use ( $saved, $j ) {
			return ( new Transaction() )->run( function () use ( $saved, $j ) {
				$cleared = $this->journal->clear( $saved );
				return is_wp_error( $cleared ) ? $cleared : $this->maintenance->release( $j['owner_token'] );
			} );
		} );
	}
	private function rollback_error( array $j ) {
		$recovered = $this->rollback( $j['owner_token'] );
		if ( is_wp_error( $recovered ) ) { return $recovered; }
		return is_array( $recovered )
			? new \WP_Error( 'certloom_whole_restore_cleanup_pending', __( 'The restore could not commit. The previous data is restored; private recovery cleanup must finish before certificate changes resume.', 'certloom' ) )
			: new \WP_Error( 'certloom_whole_restore_rolled_back', __( 'The complete restore could not commit. The previous registry, credentials, settings and files were recovered.', 'certloom' ) );
	}
	private function owned_journal( $owner ) {
		$j = $this->journal->read(); if ( is_wp_error( $j ) || ! $j || $j['owner_token'] !== $owner || ! $this->maintenance->owned_active( $owner ) ) { return $this->invalid(); }
		$touched = $this->maintenance->touch( $owner, 'whole_restore', 3600 ); return is_wp_error( $touched ) ? $touched : $j;
	}
	private function live( $entity ) { return 'legacy_registry' === $entity ? \Ecapla_CertVerify_Database::table_name() : SchemaManager::table( $entity ); }
	private function table( $owner, $kind, $entity ) {
		global $wpdb;
		$extra = array( 'legacy_registry' => 'lr', 'email_deliveries' => 'ed', 'destination_deliveries' => 'dd' );
		if ( isset( $extra[$entity] ) ) { return $wpdb->prefix . ( 'stage' === $kind ? 'certloom_rs_' : 'certloom_rb_' ) . substr( $owner, 0, 12 ) . '_' . $extra[$entity]; }
		return ( new DomainRestoreWorkspace() )->workspace_table( array( 'owner_token' => $owner, 'suffix' => substr( $owner, 0, 12 ) ), $kind, $entity );
	}
	private function exists( $table ) { global $wpdb; $wpdb->last_error = ''; $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); return '' !== (string) $wpdb->last_error ? $this->invalid() : $found === $table; }
	private function owner( $owner ) { return is_string( $owner ) && preg_match( '/^[a-f0-9]{32}$/D', $owner ); }
	private function jobs_quiet( $owner ) { global $wpdb; $wpdb->last_error = ''; $count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE status IN ('queued','running','paused','failed') AND job_key<>%s", SchemaManager::table( 'jobs' ), $owner ) ); return '' !== (string) $wpdb->last_error || null === $count || (int) $count ? new \WP_Error( 'certloom_whole_restore_jobs_pending', __( 'Finish or cancel pending Certloom jobs before replacing the complete workspace.', 'certloom' ) ) : true; }

	public function receipt( $owner ) {
		$entries = $this->receipts(); if ( is_wp_error( $entries ) ) { return $entries; } return $entries[$owner] ?? null;
	}
	private function receipts() {
		$raw = DatabaseOptions::read( array( self::RECEIPTS ) ); if ( is_wp_error( $raw ) ) { return $raw; } if ( ! $raw[self::RECEIPTS]['exists'] ) { return array(); }
		$sealed = $raw[self::RECEIPTS]['value']; if ( ! is_string( $sealed ) || strlen( $sealed ) > 262144 ) { return $this->invalid(); }
		$payload = SecretVault::open( $sealed ); $identity = ( new WholeStateProvenanceRepository() )->identity();
		if ( is_wp_error( $payload ) || is_wp_error( $identity ) || count( $payload ) !== 3 || 'certloom-whole-restore-receipts-v1' !== ( $payload['purpose'] ?? null ) || ! self::target_matches( $payload['target'] ?? null, $identity ) || ! is_array( $payload['entries'] ?? null ) || count( $payload['entries'] ) > 20 ) { return $this->invalid(); }
		foreach ( $payload['entries'] as $owner => $receipt ) { if ( ! self::valid_receipt( $receipt ) || $receipt['owner_token'] !== $owner ) { return $this->invalid(); } }
		return $payload['entries'];
	}
	private function save_receipt( array $j ) {
		return DomainMaintenanceLock::run_internal( function () use ( $j ) { return ( new Transaction() )->run( function () use ( $j ) {
			global $wpdb; $wpdb->last_error = ''; $old = $wpdb->get_row( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name=%s FOR UPDATE', $wpdb->options, self::RECEIPTS ), ARRAY_A ); if ( '' !== (string) $wpdb->last_error ) { return $this->invalid(); }
			$entries = $this->receipts(); if ( is_wp_error( $entries ) ) { return $entries; } $entries[$j['owner_token']] = $j['receipt']; while ( count( $entries ) > 20 ) { array_shift( $entries ); }
			$sealed = SecretVault::seal( array( 'purpose' => 'certloom-whole-restore-receipts-v1', 'target' => $j['target'], 'entries' => $entries ) ); if ( is_wp_error( $sealed ) ) { return $sealed; }
			$wpdb->last_error = ''; $data = array( 'option_value' => $sealed, 'autoload' => 'off' ); $n = $old ? $wpdb->update( $wpdb->options, $data, array( 'option_name' => self::RECEIPTS ) ) : $wpdb->insert( $wpdb->options, array( 'option_name' => self::RECEIPTS ) + $data ); DatabaseOptions::invalidate( array( self::RECEIPTS ) ); return 1 === $n && '' === (string) $wpdb->last_error ? true : $this->invalid();
		} ); } );
	}
	private function invalid() { return new \WP_Error( 'certloom_whole_restore_workspace_invalid', __( 'Certloom could not confirm complete recovery. Its previous state and private journal remain available; finish recovery before changing certificates.', 'certloom' ) ); }
}
