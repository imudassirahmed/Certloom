<?php
namespace Certloom\Infrastructure\Restore;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use Certloom\Application\Restore\DomainRestoreConfigurationMerger;
use Certloom\Application\Contract\TransactionInterface;
use Certloom\Infrastructure\Backup\DomainBackupRepository;
use Certloom\Infrastructure\Database\Transaction;
use Certloom\Infrastructure\Data\DomainMaintenanceLock;
use Certloom\Infrastructure\Data\MaintenanceMutex;

/**
 * Conservative, resumable Merge restore into a second private target graph.
 *
 * Live data is first copied unchanged. Backup entities are then reconciled in
 * dependency order using stable identities and a durable source-ID map. Current
 * native credential history wins on identity collisions; backup-only graphs are
 * added. The merged graph is atomically promoted only after relationship checks.
 */
final class DomainRestoreMergeService {
	/** @var DomainRestoreWorkspace */
	private $workspace;
	/** @var DomainRestoreMapRepository */
	private $maps;
	/** @var DomainBackupRepository */
	private $backup;
	/** @var DomainRestoreConfigurationMerger */
	private $configuration_merger;
	/** @var TransactionInterface */
	private $transaction;

	public function __construct(
		?DomainRestoreWorkspace $workspace = null,
		?DomainRestoreMapRepository $maps = null,
		?DomainBackupRepository $backup = null,
		?DomainRestoreConfigurationMerger $configuration_merger = null,
		?TransactionInterface $transaction = null
	) {
		$this->workspace            = $workspace ?: new DomainRestoreWorkspace();
		$this->maps                 = $maps ?: new DomainRestoreMapRepository();
		$this->backup               = $backup ?: new DomainBackupRepository();
		$this->configuration_merger = $configuration_merger ?: new DomainRestoreConfigurationMerger();
		$this->transaction          = $transaction ?: new Transaction();
	}

	/** @return array<string,mixed>|\WP_Error */
	public function start( array $restore_state ) {
		if ( empty( $restore_state['complete'] ) || 'ready' !== (string) ( $restore_state['phase'] ?? '' ) || empty( $restore_state['workspace'] ) || ! is_array( $restore_state['workspace'] ) ) {
			return new \WP_Error( 'certloom_domain_restore_merge_not_ready', __( 'Merge restore can begin only after the backup has completed staging and validation.', 'certloom' ) );
		}
		$created = $this->workspace->create_merge_target( $restore_state['workspace'] );
		if ( is_wp_error( $created ) ) {
			$abandoned = $this->workspace->abandon( $restore_state['workspace'] );
			return is_wp_error( $abandoned ) ? $abandoned : $created;
		}
		return array(
			'workspace'            => $restore_state['workspace'],
			'backup_configuration' => (array) ( $restore_state['configuration'] ?? array() ),
			'phase'                => 'copy_live',
			'entity_index'         => 0,
			'cursor'               => 0,
			'complete'             => false,
			'counts'               => array( 'inserted' => 0, 'existing' => 0, 'skipped' => 0 ),
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	public function continue_merge( array $state, $limit = 100 ) {
		$valid = $this->validate_state( $state );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		if ( ! empty( $state['complete'] ) ) {
			return $state;
		}
		$limit = max( 1, min( 500, absint( $limit ) ) );
		$entities = DomainBackupRepository::entity_types();

		if ( 'copy_live' === $state['phase'] ) {
			if ( $state['entity_index'] >= count( $entities ) ) {
				$state['phase'] = 'merge_backup';
				$state['entity_index'] = 0;
				$state['cursor'] = 0;
				return $state;
			}
			$entity = $entities[ $state['entity_index'] ];
			$copied = $this->workspace->copy_live_batch( $state['workspace'], $entity, $state['cursor'], $limit );
			if ( is_wp_error( $copied ) ) {
				return $copied;
			}
			$state['cursor'] = absint( $copied['last_id'] ?? $state['cursor'] );
			if ( ! empty( $copied['complete'] ) ) {
				$state['entity_index']++;
				$state['cursor'] = 0;
			}
			return $state;
		}

		if ( 'merge_backup' === $state['phase'] ) {
			if ( $state['entity_index'] >= count( $entities ) ) {
				$finalized = $this->finalize( $state );
				return $finalized;
			}
			$entity = $entities[ $state['entity_index'] ];
			$rows = $this->workspace->read_stage_batch( $state['workspace'], $entity, $state['cursor'], $limit );
			if ( is_wp_error( $rows ) ) {
				return $rows;
			}
			foreach ( $rows as $row ) {
				$merged = $this->merge_row( $state['workspace'], $entity, $row );
				if ( is_wp_error( $merged ) ) {
					return $merged;
				}
				$action = (string) ( $merged['action'] ?? '' );
				if ( isset( $state['counts'][ $action ] ) ) {
					$state['counts'][ $action ]++;
				}
				$state['cursor'] = absint( $row['id'] ?? $state['cursor'] );
			}
			if ( count( $rows ) < $limit ) {
				$state['entity_index']++;
				$state['cursor'] = 0;
			}
			return $state;
		}

		return new \WP_Error( 'certloom_domain_restore_merge_phase_invalid', __( 'The Merge restore entered an unsupported phase.', 'certloom' ) );
	}

	/** @return true|\WP_Error */
	public function commit( array $state ) {
		$valid = $this->validate_state( $state );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		if ( empty( $state['complete'] ) || 'ready' !== (string) $state['phase'] || empty( $state['merged_configuration'] ) ) {
			return new \WP_Error( 'certloom_domain_restore_merge_not_ready', __( 'The Merge restore target is not ready to commit.', 'certloom' ) );
		}
		$result = $this->workspace->commit_merge(
			$state['workspace'],
			(array) $state['merged_configuration'],
			array( 'mode' => 'merge', 'records' => array_sum( (array) $state['counts'] ), 'counts' => (array) $state['counts'] )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$cleared = $this->maps->clear( (string) $state['workspace']['owner_token'] );
		if ( is_wp_error( $cleared ) ) {
			return new \WP_Error(
				'certloom_domain_restore_merge_map_cleanup_failed',
				sprintf(
					/* translators: %s: cleanup error. */ __( 'The Merge restore was committed, but Certloom could not clear its private mapping state safely (%s). Retry the completed restore cleanup; credential data will not be promoted again.', 'certloom' ),
					$cleared->get_error_message()
				)
			);
		}
		return true;
	}

	/** @return array<string,mixed>|\WP_Error */
	private function finalize( array $state ) {
		$owner = (string) $state['workspace']['owner_token'];
		$templates = $this->maps->by_action( $owner, 'templates', 'inserted' );
		if ( is_wp_error( $templates ) ) { return $templates; }
		foreach ( $templates as $mapping ) {
			foreach ( array( 'source_current_revision_id' => 'current_revision_id', 'source_published_revision_id' => 'published_revision_id' ) as $meta_key => $column ) {
				$source_revision = absint( $mapping['meta'][ $meta_key ] ?? 0 );
				if ( $source_revision < 1 ) { continue; }
				$revision = $this->maps->get( $owner, 'template_revisions', $source_revision );
				if ( is_wp_error( $revision ) || ! is_array( $revision ) || empty( $revision['target_id'] ) ) {
					return is_wp_error( $revision ) ? $revision : new \WP_Error( 'certloom_domain_restore_merge_template_revision_missing', __( 'Merge restore could not map a template revision pointer safely.', 'certloom' ) );
				}
				$updated = $this->update_target_pointer( $state['workspace'], 'templates', absint( $mapping['target_id'] ), $column, absint( $revision['target_id'] ) );
				if ( is_wp_error( $updated ) ) { return $updated; }
			}
		}

		$series = $this->maps->by_action( $owner, 'credential_series', 'inserted' );
		if ( is_wp_error( $series ) ) {
			return $series;
		}
		foreach ( $series as $mapping ) {
			$source_current = absint( $mapping['meta']['source_current_version_id'] ?? 0 );
			if ( $source_current < 1 ) {
				continue;
			}
			$version = $this->maps->get( $owner, 'credential_versions', $source_current );
			if ( is_wp_error( $version ) || ! is_array( $version ) || empty( $version['target_id'] ) ) {
				return is_wp_error( $version ) ? $version : new \WP_Error( 'certloom_domain_restore_merge_current_version_missing', __( 'Merge restore could not map a Credential Series current Version safely.', 'certloom' ) );
			}
			$updated = $this->update_target_pointer( $state['workspace'], 'credential_series', absint( $mapping['target_id'] ), 'current_version_id', absint( $version['target_id'] ) );
			if ( is_wp_error( $updated ) ) {
				return $updated;
			}
		}
		$numbers = $this->maps->by_action( $owner, 'credential_numbers', 'inserted' );
		if ( is_wp_error( $numbers ) ) {
			return $numbers;
		}
		foreach ( $numbers as $mapping ) {
			$source_first = absint( $mapping['meta']['source_first_version_id'] ?? 0 );
			if ( $source_first < 1 ) {
				continue;
			}
			$version = $this->maps->get( $owner, 'credential_versions', $source_first );
			if ( is_wp_error( $version ) || ! is_array( $version ) || empty( $version['target_id'] ) ) {
				return is_wp_error( $version ) ? $version : new \WP_Error( 'certloom_domain_restore_merge_first_version_missing', __( 'Merge restore could not map a certificate number first Version safely.', 'certloom' ) );
			}
			$updated = $this->update_target_pointer( $state['workspace'], 'credential_numbers', absint( $mapping['target_id'] ), 'first_version_id', absint( $version['target_id'] ) );
			if ( is_wp_error( $updated ) ) {
				return $updated;
			}
		}

		$relationships = $this->workspace->validate_merge_relationships( $state['workspace'] );
		if ( is_wp_error( $relationships ) ) {
			return $relationships;
		}
		$current = $this->backup->configuration_snapshot();
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		$merged_configuration = $this->configuration_merger->merge( (array) $current['values'], (array) $state['backup_configuration'] );
		if ( is_wp_error( $merged_configuration ) ) {
			return $merged_configuration;
		}
		$state['merged_configuration'] = $merged_configuration;
		$state['phase'] = 'ready';
		$state['complete'] = true;
		return $state;
	}

	/** @return array<string,mixed>|\WP_Error */
	private function merge_row( array $workspace, $entity, array $row ) {
		global $wpdb;
		if ( 'recipients' === $entity && ! empty( $row['wp_user_id'] ) ) { return \Certloom\Application\Restore\DomainRestoreRowValidator::unverified_account_links(); }
		$owner     = (string) $workspace['owner_token'];
		$source_id = absint( $row['id'] ?? 0 );
		$hash      = $this->row_hash( $row );
		if ( $source_id < 1 || is_wp_error( $hash ) ) {
			return is_wp_error( $hash ) ? $hash : new \WP_Error( 'certloom_domain_restore_merge_source_invalid', __( 'Merge restore encountered an invalid source record.', 'certloom' ) );
		}
		$existing_map = $this->maps->get( $owner, $entity, $source_id );
		if ( is_wp_error( $existing_map ) ) {
			return $existing_map;
		}
		if ( is_array( $existing_map ) ) {
			return hash_equals( $hash, (string) $existing_map['source_hash'] )
				? $existing_map
				: new \WP_Error( 'certloom_domain_restore_merge_source_changed', __( 'A Merge restore source record changed after it was already mapped.', 'certloom' ) );
		}

		/*
		 * A mapped row and its durable source-ID map entry must commit together. Use
		 * Certloom's checked transaction boundary rather than issuing unchecked SQL
		 * transaction-control statements locally.
		 */
		return MaintenanceMutex::run( function () use ( $workspace, $entity, $row, $hash ) {
			if ( ! ( new DomainMaintenanceLock() )->owned_active( $workspace['owner_token'] ?? '' ) ) {
				return new \WP_Error( 'certloom_domain_maintenance_owner_mismatch', __( 'This worker no longer owns the Certloom maintenance lock.', 'certloom' ) );
			}
			return DomainMaintenanceLock::run_internal( function () use ( $workspace, $entity, $row, $hash ) {
				return $this->transaction->run( function () use ( $workspace, $entity, $row, $hash ) {
					return $this->merge_row_in_transaction( $workspace, $entity, $row, $hash );
				} );
			} );
		} );
	}

	/** @return array<string,mixed>|\WP_Error */
	private function merge_row_in_transaction( array $workspace, $entity, array $row, $hash ) {
		$owner = (string) $workspace['owner_token'];
		$source_id = absint( $row['id'] );
		$target_table = $this->workspace->workspace_table( $workspace, 'merge', $entity );
		if ( is_wp_error( $target_table ) ) {
			return $target_table;
		}

		switch ( $entity ) {
			case 'issuers':
				$target_id = $this->lookup_id( $target_table, 'slug', (string) $row['slug'] );
				return $target_id ? $this->record_map( $owner, $entity, $source_id, $hash, $target_id, 'existing' ) : $this->insert_and_map( $owner, $entity, $source_id, $hash, $target_table, $row );

			case 'programs':
				$row['issuer_id'] = $this->mapped_nullable( $owner, 'issuers', $row['issuer_id'] ?? null );
				if ( is_wp_error( $row['issuer_id'] ) ) { return $row['issuer_id']; }
				$code = trim( (string) ( $row['code'] ?? '' ) );
				$target_id = '' !== $code ? $this->lookup_id( $target_table, 'code', $code ) : 0;
				if ( ! $target_id && '' === $code ) {
					$target_id = $this->infer_series_dependency( $workspace, 'program_id', $source_id );
					if ( is_wp_error( $target_id ) ) { return $target_id; }
					if ( ! $target_id ) {
						// A program without a code or credential reference has no portable
						// identity. A same-ID, identical row is safe to reuse when merging a
						// backup back into its original site; never match by title alone.
						$existing = $this->lookup_row( $target_table, 'id', $source_id );
						if ( is_wp_error( $existing ) ) { return $existing; }
						if ( is_array( $existing ) && $this->rows_equal( $row, $existing ) ) { $target_id = $source_id; }
					}
				}
				return $target_id ? $this->record_map( $owner, $entity, $source_id, $hash, $target_id, 'existing' ) : $this->insert_and_map( $owner, $entity, $source_id, $hash, $target_table, $row );

			case 'recipients':
				$target_id = ! empty( $row['wp_user_id'] ) ? $this->lookup_id( $target_table, 'wp_user_id', absint( $row['wp_user_id'] ) ) : 0;
				if ( ! $target_id ) {
					$target_id = $this->infer_series_dependency( $workspace, 'recipient_id', $source_id );
					if ( is_wp_error( $target_id ) ) { return $target_id; }
				}
				if ( ! $target_id ) {
					// An unissued recipient may have neither a WordPress user nor a
					// credential identity. Reuse an exact same-ID row on a same-site
					// round trip; names and shared email addresses are not identities.
					$existing = $this->lookup_row( $target_table, 'id', $source_id );
					if ( is_wp_error( $existing ) ) { return $existing; }
					if ( is_array( $existing ) && $this->rows_equal( $row, $existing ) ) { $target_id = $source_id; }
				}
				return $target_id ? $this->record_map( $owner, $entity, $source_id, $hash, $target_id, 'existing' ) : $this->insert_and_map( $owner, $entity, $source_id, $hash, $target_table, $row );

			case 'signers':
				$target_id = $this->lookup_id( $target_table, 'slug', (string) $row['slug'] );
				return $target_id ? $this->record_map( $owner, $entity, $source_id, $hash, $target_id, 'existing' ) : $this->insert_and_map( $owner, $entity, $source_id, $hash, $target_table, $row );

			case 'batches':
				$program_id = $this->mapped_required( $owner, 'programs', $row['program_id'] ?? 0 );
				$signer_id = $this->mapped_nullable( $owner, 'signers', $row['instructor_signer_id'] ?? null );
				if ( is_wp_error( $program_id ) || is_wp_error( $signer_id ) ) { return is_wp_error( $program_id ) ? $program_id : $signer_id; }
				$target_id = $this->lookup_id( $target_table, 'code', (string) $row['code'] );
				if ( $target_id ) { return $this->record_map( $owner, $entity, $source_id, $hash, $target_id, 'existing' ); }
				$row['program_id'] = $program_id;
				$row['instructor_signer_id'] = $signer_id;
				return $this->insert_and_map( $owner, $entity, $source_id, $hash, $target_table, $row );

			case 'batch_recipients':
				$batch_id = $this->mapped_required( $owner, 'batches', $row['batch_id'] ?? 0 );
				$recipient_id = $this->mapped_required( $owner, 'recipients', $row['recipient_id'] ?? 0 );
				if ( is_wp_error( $batch_id ) || is_wp_error( $recipient_id ) ) { return is_wp_error( $batch_id ) ? $batch_id : $recipient_id; }
				$target_id = $this->lookup_composite_id( $target_table, array( 'batch_id' => $batch_id, 'recipient_id' => $recipient_id ) );
				if ( is_wp_error( $target_id ) ) { return $target_id; }
				if ( $target_id ) { return $this->record_map( $owner, $entity, $source_id, $hash, $target_id, 'existing' ); }
				$row['batch_id'] = $batch_id; $row['recipient_id'] = $recipient_id;
				return $this->insert_and_map( $owner, $entity, $source_id, $hash, $target_table, $row );

			case 'signer_assignments':
				$signer_id = $this->mapped_required( $owner, 'signers', $row['signer_id'] ?? 0 );
				if ( is_wp_error( $signer_id ) ) { return $signer_id; }
				$scope_type = (string) $row['scope_type'];
				$scope_entity = array( 'issuer' => 'issuers', 'program' => 'programs', 'batch' => 'batches' );
				$scope_id = 'global' === $scope_type ? 0 : $this->mapped_required( $owner, $scope_entity[ $scope_type ] ?? '', $row['scope_id'] ?? 0 );
				if ( is_wp_error( $scope_id ) ) { return $scope_id; }
				$target_id = $this->lookup_composite_id( $target_table, array( 'signer_id' => $signer_id, 'scope_type' => $scope_type, 'scope_id' => $scope_id, 'role_type' => (string) $row['role_type'] ) );
				if ( is_wp_error( $target_id ) ) { return $target_id; }
				if ( $target_id ) { return $this->record_map( $owner, $entity, $source_id, $hash, $target_id, 'existing' ); }
				$row['signer_id'] = $signer_id; $row['scope_id'] = $scope_id;
				return $this->insert_and_map( $owner, $entity, $source_id, $hash, $target_table, $row );

			case 'templates':
				$target_id = $this->lookup_id( $target_table, 'machine_key', (string) $row['machine_key'] );
				if ( $target_id ) { return $this->record_map( $owner, $entity, $source_id, $hash, $target_id, 'existing', array( 'preserve_current_history' => true ) ); }
				$source_current = absint( $row['current_revision_id'] ?? 0 );
				$source_published = absint( $row['published_revision_id'] ?? 0 );
				$row['current_revision_id'] = null; $row['published_revision_id'] = null;
				return $this->insert_and_map( $owner, $entity, $source_id, $hash, $target_table, $row, array( 'source_current_revision_id' => $source_current, 'source_published_revision_id' => $source_published ) );

			case 'template_revisions':
				$template = $this->maps->get( $owner, 'templates', absint( $row['template_id'] ?? 0 ) );
				if ( is_wp_error( $template ) || ! is_array( $template ) || empty( $template['target_id'] ) ) {
					return is_wp_error( $template ) ? $template : new \WP_Error( 'certloom_domain_restore_merge_template_mapping_missing', __( 'Merge restore could not map a template revision to its template.', 'certloom' ) );
				}
				if ( 'existing' === (string) $template['action'] ) { return $this->record_map( $owner, $entity, $source_id, $hash, null, 'skipped', array( 'reason' => 'existing_template_history_preserved' ) ); }
				$row['template_id'] = absint( $template['target_id'] );
				if ( ! empty( $row['parent_revision_id'] ) ) {
					$parent = $this->maps->get( $owner, 'template_revisions', absint( $row['parent_revision_id'] ) );
					if ( is_wp_error( $parent ) || ! is_array( $parent ) || empty( $parent['target_id'] ) ) { return is_wp_error( $parent ) ? $parent : new \WP_Error( 'certloom_domain_restore_merge_template_parent_missing', __( 'Merge restore could not map a template parent revision safely.', 'certloom' ) ); }
					$row['parent_revision_id'] = absint( $parent['target_id'] );
				}
				return $this->insert_and_map( $owner, $entity, $source_id, $hash, $target_table, $row );

			case 'template_assignments':
				$template_id = $this->mapped_required( $owner, 'templates', $row['template_id'] ?? 0 );
				if ( is_wp_error( $template_id ) ) { return $template_id; }
				$scope_type = (string) $row['scope_type'];
				$scope_entity = array( 'issuer' => 'issuers', 'program' => 'programs', 'batch' => 'batches' );
				$scope_id = 'global' === $scope_type ? 0 : $this->mapped_required( $owner, $scope_entity[ $scope_type ] ?? '', $row['scope_id'] ?? 0 );
				if ( is_wp_error( $scope_id ) ) { return $scope_id; }
				$target_id = $this->lookup_composite_id( $target_table, array( 'scope_type' => $scope_type, 'scope_id' => $scope_id ) );
				if ( is_wp_error( $target_id ) ) { return $target_id; }
				if ( $target_id ) { return $this->record_map( $owner, $entity, $source_id, $hash, $target_id, 'existing' ); }
				$row['template_id'] = $template_id; $row['scope_id'] = $scope_id;
				return $this->insert_and_map( $owner, $entity, $source_id, $hash, $target_table, $row );

			case 'credential_series':
				$recipient_id = $this->mapped_required( $owner, 'recipients', $row['recipient_id'] ?? 0 );
				$program_id   = $this->mapped_nullable( $owner, 'programs', $row['program_id'] ?? null );
				$batch_id     = $this->mapped_nullable( $owner, 'batches', $row['batch_id'] ?? null );
				$issuer_id    = $this->mapped_nullable( $owner, 'issuers', $row['issuer_id'] ?? null );
				foreach ( array( $recipient_id, $program_id, $batch_id, $issuer_id ) as $mapped ) { if ( is_wp_error( $mapped ) ) { return $mapped; } }
				$existing = $this->lookup_row( $target_table, 'public_id', (string) $row['public_id'] );
				if ( is_wp_error( $existing ) ) { return $existing; }
				if ( is_array( $existing ) ) {
					if ( absint( $existing['recipient_id'] ?? 0 ) !== absint( $recipient_id ) || absint( $existing['program_id'] ?? 0 ) !== absint( $program_id ) || absint( $existing['batch_id'] ?? 0 ) !== absint( $batch_id ) || absint( $existing['issuer_id'] ?? 0 ) !== absint( $issuer_id ) ) {
						return new \WP_Error( 'certloom_domain_restore_merge_series_conflict', __( 'A backup credential public ID already belongs to a different recipient/program/batch/issuer relationship.', 'certloom' ) );
					}
					return $this->record_map( $owner, $entity, $source_id, $hash, absint( $existing['id'] ), 'existing', array( 'preserve_current_history' => true ) );
				}
				$row['recipient_id'] = $recipient_id;
				$row['program_id'] = $program_id;
				$row['batch_id'] = $batch_id;
				$row['issuer_id'] = $issuer_id;
				$source_current = absint( $row['current_version_id'] ?? 0 );
				$row['current_version_id'] = null;
				return $this->insert_and_map( $owner, $entity, $source_id, $hash, $target_table, $row, array( 'source_current_version_id' => $source_current ) );

			case 'credential_numbers':
				$series = $this->maps->get( $owner, 'credential_series', absint( $row['series_id'] ?? 0 ) );
				if ( is_wp_error( $series ) || ! is_array( $series ) || empty( $series['target_id'] ) ) {
					return is_wp_error( $series ) ? $series : new \WP_Error( 'certloom_domain_restore_merge_series_mapping_missing', __( 'Merge restore could not map a certificate number to its Credential Series.', 'certloom' ) );
				}
				$existing = $this->lookup_row( $target_table, 'display_number', (string) $row['display_number'] );
				if ( is_wp_error( $existing ) ) { return $existing; }
				if ( 'existing' === (string) $series['action'] ) {
					if ( is_array( $existing ) && absint( $existing['series_id'] ?? 0 ) !== absint( $series['target_id'] ) ) {
						return new \WP_Error( 'certloom_domain_restore_merge_number_conflict', __( 'A backup human certificate number is already owned by a different Credential Series.', 'certloom' ) );
					}
					return $this->record_map( $owner, $entity, $source_id, $hash, is_array( $existing ) ? absint( $existing['id'] ) : null, is_array( $existing ) ? 'existing' : 'skipped' );
				}
				if ( is_array( $existing ) ) {
					return new \WP_Error( 'certloom_domain_restore_merge_number_conflict', __( 'A backup human certificate number is already owned by current Certloom data.', 'certloom' ) );
				}
				$row['series_id'] = absint( $series['target_id'] );
				$source_first = absint( $row['first_version_id'] ?? 0 );
				$row['first_version_id'] = null;
				return $this->insert_and_map( $owner, $entity, $source_id, $hash, $target_table, $row, array( 'source_first_version_id' => $source_first ) );

			case 'credential_versions':
				$series = $this->maps->get( $owner, 'credential_series', absint( $row['series_id'] ?? 0 ) );
				if ( is_wp_error( $series ) || ! is_array( $series ) || empty( $series['target_id'] ) ) {
					return is_wp_error( $series ) ? $series : new \WP_Error( 'certloom_domain_restore_merge_series_mapping_missing', __( 'Merge restore could not map a Credential Version to its Series.', 'certloom' ) );
				}
				if ( 'existing' === (string) $series['action'] ) {
					return $this->record_map( $owner, $entity, $source_id, $hash, null, 'skipped', array( 'reason' => 'existing_series_history_preserved' ) );
				}
				$row['series_id'] = absint( $series['target_id'] );
				if ( ! empty( $row['source_type'] ) && ! empty( $row['source_idempotency_key'] ) ) {
					$collision = $this->lookup_version_source( $target_table, (string) $row['source_type'], (string) $row['source_idempotency_key'] );
					if ( $collision ) {
						return new \WP_Error( 'certloom_domain_restore_merge_idempotency_conflict', __( 'A backup Credential Version source idempotency key already belongs to current Certloom data.', 'certloom' ) );
					}
				}
				return $this->insert_and_map( $owner, $entity, $source_id, $hash, $target_table, $row );

			case 'credential_events':
				$series = $this->maps->get( $owner, 'credential_series', absint( $row['series_id'] ?? 0 ) );
				if ( is_wp_error( $series ) || ! is_array( $series ) || empty( $series['target_id'] ) ) {
					return is_wp_error( $series ) ? $series : new \WP_Error( 'certloom_domain_restore_merge_series_mapping_missing', __( 'Merge restore could not map a credential event to its Series.', 'certloom' ) );
				}
				if ( 'existing' === (string) $series['action'] ) {
					return $this->record_map( $owner, $entity, $source_id, $hash, null, 'skipped', array( 'reason' => 'existing_series_history_preserved' ) );
				}
				$row['series_id'] = absint( $series['target_id'] );
				if ( ! empty( $row['version_id'] ) ) {
					$version = $this->maps->get( $owner, 'credential_versions', absint( $row['version_id'] ) );
					if ( is_wp_error( $version ) || ! is_array( $version ) || empty( $version['target_id'] ) ) {
						return is_wp_error( $version ) ? $version : new \WP_Error( 'certloom_domain_restore_merge_version_mapping_missing', __( 'Merge restore could not map a credential event Version safely.', 'certloom' ) );
					}
					$row['version_id'] = absint( $version['target_id'] );
				}
				return $this->insert_and_map( $owner, $entity, $source_id, $hash, $target_table, $row );

			case 'program_registrations':
			case 'field_submissions':
			case 'issuance_requests':
			case 'issuance_request_events':
				return $this->merge_workflow_row( $workspace, $entity, $source_id, $hash, $target_table, $row );
		}

		return new \WP_Error( 'certloom_domain_restore_merge_entity_invalid', __( 'Merge restore encountered an unsupported entity type.', 'certloom' ) );
	}

	/** Restore workflow identities conservatively; current local decisions always win. */
	private function merge_workflow_row( array $workspace, $entity, $source_id, $hash, $table, array $row ) {
		$owner = (string) $workspace['owner_token'];
		$source_request_id = $row['request_id'] ?? null;
		$row = \Certloom\Application\Restore\RecipientWorkflowRestorePolicy::prepare( $entity, $row );
		foreach ( array( 'recipient_id' => 'recipients', 'program_id' => 'programs', 'batch_id' => 'batches', 'registration_id' => 'program_registrations', 'series_id' => 'credential_series', 'request_id' => 'issuance_requests' ) as $column => $parent ) {
			if ( ! array_key_exists( $column, $row ) ) { continue; }
			$row[ $column ] = $this->mapped_nullable( $owner, $parent, $row[ $column ] );
			if ( is_wp_error( $row[ $column ] ) ) { return $row[ $column ]; }
		}
		if ( 'field_submissions' === $entity ) {
			$row['scope_id'] = 'recipient' === $row['scope_kind'] ? $row['recipient_id'] : $this->mapped_required( $owner, 'program_registrations', $row['scope_id'] );
			if ( is_wp_error( $row['scope_id'] ) ) { return $row['scope_id']; }
			$row['scope_key'] = \Certloom\Domain\Recipient\FieldSubmissionScope::key( $row['recipient_id'], $row['scope_kind'], $row['scope_id'], $row['field_key'] );
		}
		if ( 'program_registrations' === $entity ) {
			$existing = $this->lookup_composite_id( $table, array( 'recipient_id' => $row['recipient_id'], 'program_id' => $row['program_id'], 'instance_key' => $row['instance_key'] ) );
			if ( is_wp_error( $existing ) ) { return $existing; }
			if ( $existing ) {
				$current = $this->lookup_row( $table, 'id', $existing );
				if ( is_wp_error( $current ) ) { return $current; }
				if ( ! is_array( $current ) || (string) $current['batch_id'] !== (string) $row['batch_id'] || (string) $current['source_type'] !== (string) $row['source_type'] || (string) $current['source_ref'] !== (string) $row['source_ref'] ) { return $this->workflow_conflict(); }
			}
		} else {
			$existing_row = $this->lookup_row( $table, 'public_ref', $row['public_ref'] );
			if ( is_wp_error( $existing_row ) ) { return $existing_row; }
			$existing = is_array( $existing_row ) ? absint( $existing_row['id'] ) : 0;
			if ( $existing ) {
				foreach ( array( 'recipient_id', 'program_id', 'batch_id', 'registration_id', 'request_id', 'intake_key', 'payload_sha256', 'field_key', 'scope_kind', 'scope_id' ) as $column ) {
					if ( isset( $row[ $column ] ) && (string) $row[ $column ] !== (string) $existing_row[ $column ] ) { return $this->workflow_conflict(); }
				}
			}
		}
		if ( 'issuance_requests' === $entity ) {
			$intake = $this->lookup_row( $table, 'intake_key', $row['intake_key'] );
			if ( is_wp_error( $intake ) ) { return $intake; }
			if ( is_array( $intake ) ) {
				if ( $existing && $existing !== (int) $intake['id'] ) { return $this->workflow_conflict(); }
				if ( ! hash_equals( $row['payload_sha256'], (string) $intake['payload_sha256'] ) || (int) $row['recipient_id'] !== (int) $intake['recipient_id'] || (int) $row['program_id'] !== (int) $intake['program_id'] ) { return $this->workflow_conflict(); }
				$existing = (int) $intake['id'];
			}
		}
		if ( $existing ) { return $this->record_map( $owner, $entity, $source_id, $hash, $existing, 'existing' ); }
		if ( 'field_submissions' === $entity ) {
			if ( null !== $row['open_slot'] ) {
				$open = $this->lookup_composite_id( $table, array( 'scope_key' => $row['scope_key'], 'open_slot' => 1 ) );
				if ( is_wp_error( $open ) ) { return $open; }
				if ( $open ) { $row['status'] = 'superseded'; $row['open_slot'] = null; }
			}
		}
		if ( 'issuance_requests' === $entity && null !== $row['version_id'] ) {
			$version = $this->mapped_request_version( $workspace, $row['version_id'], $row['series_id'] );
			if ( is_wp_error( $version ) ) { return $version; }
			$row['version_id'] = $version;
		}
		if ( 'issuance_request_events' === $entity ) {
			$request = $this->maps->get( $owner, 'issuance_requests', $source_request_id );
			if ( is_wp_error( $request ) ) { return $request; }
			if ( is_array( $request ) && 'existing' === $request['action'] ) {
				return $this->record_map( $owner, $entity, (int) $row['id'], $hash, null, 'skipped', array( 'reason' => 'existing_request_history_preserved' ) );
			}
		}
		return $this->insert_and_map( $owner, $entity, (int) $row['id'], $hash, $table, $row );
	}

	private function mapped_request_version( array $workspace, $source_id, $series_id ) {
		$mapped = $this->maps->get( $workspace['owner_token'], 'credential_versions', $source_id );
		if ( is_wp_error( $mapped ) ) { return $mapped; }
		if ( ! empty( $mapped['target_id'] ) ) { return (int) $mapped['target_id']; }
		$source_table = $this->workspace->workspace_table( $workspace, 'stage', 'credential_versions' );
		$target_table = $this->workspace->workspace_table( $workspace, 'merge', 'credential_versions' );
		if ( is_wp_error( $source_table ) || is_wp_error( $target_table ) ) { return is_wp_error( $source_table ) ? $source_table : $target_table; }
		$source = $this->lookup_row( $source_table, 'id', $source_id );
		if ( is_wp_error( $source ) ) { return $source; }
		if ( ! is_array( $source ) ) { return $this->workflow_conflict(); }
		$id = $this->lookup_composite_id( $target_table, array( 'series_id' => $series_id, 'version_number' => $source['version_number'] ) );
		if ( is_wp_error( $id ) ) { return $id; }
		$target = $id ? $this->lookup_row( $target_table, 'id', $id ) : null;
		if ( is_wp_error( $target ) ) { return $target; }
		if ( ! is_array( $target ) || (string) $source['display_number'] !== (string) $target['display_number'] || (string) $source['snapshot_json'] !== (string) $target['snapshot_json'] || (string) $source['source_idempotency_key'] !== (string) $target['source_idempotency_key'] ) { return $this->workflow_conflict(); }
		return $id;
	}

	private function workflow_conflict() {
		return new \WP_Error( 'certloom_domain_restore_merge_workflow_conflict', __( 'Current recipient workflow identities conflict with this backup. No workflow decisions were overwritten.', 'certloom' ) );
	}

	/** @return array<string,mixed>|\WP_Error */
	private function insert_and_map( $owner, $entity, $source_id, $hash, $table, array $row, array $meta = array() ) {
		global $wpdb;
		unset( $row['id'] );
		$wpdb->last_error = '';
		$inserted = $wpdb->insert( $table, $row );
		if ( false === $inserted || '' !== (string) $wpdb->last_error || absint( $wpdb->insert_id ) < 1 ) {
			return new \WP_Error( 'certloom_domain_restore_merge_insert_failed', __( 'Certloom could not add a backup-only record to its private Merge restore target.', 'certloom' ) );
		}
		return $this->record_map( $owner, $entity, $source_id, $hash, absint( $wpdb->insert_id ), 'inserted', $meta );
	}

	/** @return array<string,mixed>|\WP_Error */
	private function record_map( $owner, $entity, $source_id, $hash, $target_id, $action, array $meta = array() ) {
		$saved = $this->maps->save( $owner, $entity, $source_id, $hash, $target_id, $action, $meta );
		return is_wp_error( $saved ) ? $saved : array( 'source_hash' => $hash, 'target_id' => $target_id ?: null, 'action' => $action, 'meta' => $meta );
	}

	/** @return int|\WP_Error */
	private function mapped_required( $owner, $entity, $source_id ) {
		$mapping = $this->maps->get( $owner, $entity, absint( $source_id ) );
		return is_wp_error( $mapping ) || ! is_array( $mapping ) || empty( $mapping['target_id'] )
			? ( is_wp_error( $mapping ) ? $mapping : new \WP_Error( 'certloom_domain_restore_merge_dependency_missing', __( 'Merge restore is missing a required dependency mapping.', 'certloom' ) ) )
			: absint( $mapping['target_id'] );
	}

	/** @return int|null|\WP_Error */
	private function mapped_nullable( $owner, $entity, $source_id ) {
		if ( null === $source_id || absint( $source_id ) < 1 ) {
			return null;
		}
		return $this->mapped_required( $owner, $entity, $source_id );
	}

	/** @return int|\WP_Error */
	private function infer_series_dependency( array $workspace, $column, $source_id ) {
		global $wpdb;
		if ( ! in_array( $column, array( 'recipient_id', 'program_id' ), true ) ) {
			return new \WP_Error( 'certloom_domain_restore_merge_inference_invalid', __( 'Certloom refused an invalid Merge restore dependency inference.', 'certloom' ) );
		}
		$backup_series = $this->workspace->workspace_table( $workspace, 'stage', 'credential_series' );
		$target_series = $this->workspace->workspace_table( $workspace, 'merge', 'credential_series' );
		if ( is_wp_error( $backup_series ) || is_wp_error( $target_series ) ) {
			return is_wp_error( $backup_series ) ? $backup_series : $target_series;
		}
		$wpdb->last_error = '';
		$values = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT t.$column FROM %i b INNER JOIN %i t ON t.public_id = b.public_id WHERE b.$column = %d LIMIT 2",
				$backup_series,
				$target_series,
				absint( $source_id )
			)
		);
		if ( '' !== (string) $wpdb->last_error || ! is_array( $values ) ) {
			return new \WP_Error( 'certloom_domain_restore_merge_inference_failed', __( 'Certloom could not infer a safe existing Merge restore dependency.', 'certloom' ) );
		}
		$values = array_values( array_unique( array_filter( array_map( 'absint', $values ) ) ) );
		if ( count( $values ) > 1 ) {
			return new \WP_Error( 'certloom_domain_restore_merge_dependency_conflict', __( 'Existing credentials disagree about the identity of a backup dependency, so Merge restore was stopped.', 'certloom' ) );
		}
		return empty( $values ) ? 0 : $values[0];
	}

	/** @return int|\WP_Error */
	private function lookup_composite_id( $table, array $pairs ) {
		global $wpdb;
		$allowed = array( 'batch_id', 'recipient_id', 'signer_id', 'scope_type', 'scope_id', 'role_type', 'program_id', 'instance_key', 'scope_key', 'open_slot', 'series_id', 'version_number' );
		$where = array(); $args = array( $table );
		foreach ( $pairs as $column => $value ) {
			if ( ! in_array( $column, $allowed, true ) ) { return new \WP_Error( 'certloom_domain_restore_merge_lookup_invalid', __( 'Certloom refused an unsafe Merge restore identity lookup.', 'certloom' ) ); }
			if ( is_int( $value ) ) { $where[] = "$column = %d"; $args[] = $value; } else { $where[] = "$column = %s"; $args[] = (string) $value; }
		}
		$wpdb->last_error = '';
		$id = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE ' . implode( ' AND ', $where ) . ' LIMIT 1', $args ) );
		return '' !== (string) $wpdb->last_error ? new \WP_Error( 'certloom_domain_restore_merge_lookup_failed', __( 'Certloom could not inspect current relationship identities while preparing Merge restore.', 'certloom' ) ) : absint( $id );
	}

	/** @return int */
	private function lookup_id( $table, $column, $value ) {
		global $wpdb;
		$wpdb->last_error = '';
		$id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE $column = %s LIMIT 1", $table, (string) $value ) );
		return '' === (string) $wpdb->last_error ? absint( $id ) : 0;
	}

	/** @return array<string,mixed>|null|\WP_Error */
	private function lookup_row( $table, $column, $value ) {
		global $wpdb;
		$wpdb->last_error = '';
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE $column = %s LIMIT 1", $table, (string) $value ), ARRAY_A );
		return '' !== (string) $wpdb->last_error
			? new \WP_Error( 'certloom_domain_restore_merge_lookup_failed', __( 'Certloom could not inspect current identity records while preparing Merge restore.', 'certloom' ) )
			: ( is_array( $row ) ? $row : null );
	}

	/** Compare database scalar rows after mapped parent IDs have been applied. */
	private function rows_equal( array $source, array $target ) {
		foreach ( $source as $column => $value ) {
			if ( ! array_key_exists( $column, $target ) ) { return false; }
			if ( null === $value || null === $target[ $column ] ) {
				if ( $value !== $target[ $column ] ) { return false; }
			} elseif ( (string) $value !== (string) $target[ $column ] ) {
				return false;
			}
		}
		return true;
	}

	private function lookup_version_source( $table, $source_type, $source_key ) {
		global $wpdb;
		$wpdb->last_error = '';
		$id = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE source_type = %s AND source_idempotency_key = %s LIMIT 1', $table, $source_type, $source_key ) );
		return '' === (string) $wpdb->last_error ? absint( $id ) : 0;
	}

	/** @return true|\WP_Error */
	private function update_target_pointer( array $workspace, $entity, $target_id, $column, $value ) {
		global $wpdb;
		$allowed = array(
			'credential_series' => array( 'current_version_id' ),
			'credential_numbers' => array( 'first_version_id' ),
			'templates' => array( 'current_revision_id', 'published_revision_id' ),
		);
		if ( ! isset( $allowed[ $entity ] ) || ! in_array( $column, $allowed[ $entity ], true ) ) {
			return new \WP_Error( 'certloom_domain_restore_merge_pointer_invalid', __( 'Certloom refused an unsupported Merge restore pointer update.', 'certloom' ) );
		}
		$table = $this->workspace->workspace_table( $workspace, 'merge', $entity );
		if ( is_wp_error( $table ) ) {
			return $table;
		}
		$wpdb->last_error = '';
		$updated = $wpdb->update( $table, array( $column => absint( $value ) ), array( 'id' => absint( $target_id ) ) );
		return false === $updated || '' !== (string) $wpdb->last_error
			? new \WP_Error( 'certloom_domain_restore_merge_pointer_failed', __( 'Certloom could not finalize Merge restore credential pointers safely.', 'certloom' ) )
			: true;
	}

	/** @return string|\WP_Error */
	private function row_hash( array $row ) {
		ksort( $row );
		$json = wp_json_encode( $row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false === $json ? new \WP_Error( 'certloom_domain_restore_merge_hash_failed', __( 'Certloom could not fingerprint a Merge restore source record.', 'certloom' ) ) : hash( 'sha256', $json );
	}

	/** @return true|\WP_Error */
	private function validate_state( array $state ) {
		foreach ( array( 'workspace', 'backup_configuration', 'phase', 'entity_index', 'cursor', 'complete', 'counts' ) as $key ) {
			if ( ! array_key_exists( $key, $state ) ) {
				return new \WP_Error( 'certloom_domain_restore_merge_state_invalid', __( 'The resumable Merge restore state is incomplete.', 'certloom' ) );
			}
		}
		return true;
	}
}
