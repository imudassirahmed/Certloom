<?php
namespace Certloom\Infrastructure\Restore;

use Certloom\Application\Restore\DomainRestoreConfigurationMerger;
use Certloom\Application\Contract\TransactionInterface;
use Certloom\Infrastructure\Backup\DomainBackupRepository;
use Certloom\Infrastructure\Database\Transaction;

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
		DomainRestoreWorkspace $workspace = null,
		DomainRestoreMapRepository $maps = null,
		DomainBackupRepository $backup = null,
		DomainRestoreConfigurationMerger $configuration_merger = null,
		TransactionInterface $transaction = null
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
		$this->maps->clear( (string) $state['workspace']['owner_token'] );
		return true;
	}

	/** @return array<string,mixed>|\WP_Error */
	private function finalize( array $state ) {
		$owner = (string) $state['workspace']['owner_token'];
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
		return $this->transaction->run( function () use ( $workspace, $entity, $row, $hash ) {
			return $this->merge_row_in_transaction( $workspace, $entity, $row, $hash );
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
				}
				return $target_id ? $this->record_map( $owner, $entity, $source_id, $hash, $target_id, 'existing' ) : $this->insert_and_map( $owner, $entity, $source_id, $hash, $target_table, $row );

			case 'recipients':
				$target_id = ! empty( $row['wp_user_id'] ) ? $this->lookup_id( $target_table, 'wp_user_id', absint( $row['wp_user_id'] ) ) : 0;
				if ( ! $target_id ) {
					$target_id = $this->infer_series_dependency( $workspace, 'recipient_id', $source_id );
					if ( is_wp_error( $target_id ) ) { return $target_id; }
				}
				return $target_id ? $this->record_map( $owner, $entity, $source_id, $hash, $target_id, 'existing' ) : $this->insert_and_map( $owner, $entity, $source_id, $hash, $target_table, $row );

			case 'credential_series':
				$recipient_id = $this->mapped_required( $owner, 'recipients', $row['recipient_id'] ?? 0 );
				$program_id   = $this->mapped_nullable( $owner, 'programs', $row['program_id'] ?? null );
				$issuer_id    = $this->mapped_nullable( $owner, 'issuers', $row['issuer_id'] ?? null );
				foreach ( array( $recipient_id, $program_id, $issuer_id ) as $mapped ) { if ( is_wp_error( $mapped ) ) { return $mapped; } }
				$existing = $this->lookup_row( $target_table, 'public_id', (string) $row['public_id'] );
				if ( is_wp_error( $existing ) ) { return $existing; }
				if ( is_array( $existing ) ) {
					if ( absint( $existing['recipient_id'] ?? 0 ) !== absint( $recipient_id ) || absint( $existing['program_id'] ?? 0 ) !== absint( $program_id ) || absint( $existing['issuer_id'] ?? 0 ) !== absint( $issuer_id ) ) {
						return new \WP_Error( 'certloom_domain_restore_merge_series_conflict', __( 'A backup credential public ID already belongs to a different recipient/program/issuer relationship.', 'certloom' ) );
					}
					return $this->record_map( $owner, $entity, $source_id, $hash, absint( $existing['id'] ), 'existing', array( 'preserve_current_history' => true ) );
				}
				$row['recipient_id'] = $recipient_id;
				$row['program_id'] = $program_id;
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
		}

		return new \WP_Error( 'certloom_domain_restore_merge_entity_invalid', __( 'Merge restore encountered an unsupported entity type.', 'certloom' ) );
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

	private function lookup_version_source( $table, $source_type, $source_key ) {
		global $wpdb;
		$wpdb->last_error = '';
		$id = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE source_type = %s AND source_idempotency_key = %s LIMIT 1', $table, $source_type, $source_key ) );
		return '' === (string) $wpdb->last_error ? absint( $id ) : 0;
	}

	/** @return true|\WP_Error */
	private function update_target_pointer( array $workspace, $entity, $target_id, $column, $value ) {
		global $wpdb;
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
