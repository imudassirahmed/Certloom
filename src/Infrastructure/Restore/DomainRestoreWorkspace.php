<?php
namespace Certloom\Infrastructure\Restore;

use Certloom\Application\Contract\DomainRestoreWorkspaceInterface;
use Certloom\Infrastructure\Backup\DomainBackupRepository;
use Certloom\Infrastructure\Data\DomainGeneration;
use Certloom\Infrastructure\Data\DomainMaintenanceLock;
use Certloom\Infrastructure\Database\SchemaManager;

/**
 * MySQL-backed private restore workspace for complete domain replacement.
 *
 * Every restored entity is first loaded into a same-schema staging table. A
 * single multi-table RENAME promotes the complete graph atomically while the
 * old graph remains available under rollback names until configuration and the
 * commit journal are durably saved.
 */
final class DomainRestoreWorkspace implements DomainRestoreWorkspaceInterface {
	const BUILD_OPTION     = 'certloom_domain_restore_build';
	const JOURNAL_OPTION   = 'certloom_domain_restore_journal';
	const RECEIPTS_OPTION  = 'certloom_domain_restore_receipts';
	const BUILD_TTL        = 3600;
	const RECEIPT_TTL      = 604800; // Seven days is ample for a crashed job row to retry.
	const MAX_RECEIPTS     = 20;

	/** @var DomainMaintenanceLock */
	private $maintenance;

	/** @var DomainGeneration */
	private $generation;

	/** @var DomainRestoreMapRepository */
	private $restore_maps;

	public function __construct( ?DomainMaintenanceLock $maintenance = null, ?DomainGeneration $generation = null, ?DomainRestoreMapRepository $restore_maps = null ) {
		$this->maintenance  = $maintenance ?: new DomainMaintenanceLock();
		$this->generation   = $generation ?: new DomainGeneration();
		$this->restore_maps = $restore_maps ?: new DomainRestoreMapRepository();
	}

	/** @return array<string,mixed>|\WP_Error */
	public function create( $owner_token ) {
		$owner_token = $this->owner_token( $owner_token );
		if ( is_wp_error( $owner_token ) ) {
			return $owner_token;
		}

		$recovered = $this->recover_interrupted_commit();
		if ( is_wp_error( $recovered ) ) {
			return $recovered;
		}

		$locked = $this->maintenance->acquire( $owner_token, 'domain_restore', self::BUILD_TTL );
		if ( is_wp_error( $locked ) ) {
			return $locked;
		}

		$workspace = $this->workspace( $owner_token );
		$current   = get_option( self::BUILD_OPTION, array() );
		if ( is_array( $current ) && isset( $current['owner_token'] ) && hash_equals( $owner_token, (string) $current['owner_token'] ) ) {
			if ( $this->workspace_exists( $workspace ) ) {
				if ( ! $this->save_build_marker( $workspace ) ) {
					return $this->release_after_error(
						$owner_token,
						new \WP_Error( 'certloom_domain_restore_build_marker_failed', __( 'Certloom could not refresh the restore workspace marker.', 'certloom' ) )
					);
				}
				return $workspace;
			}
			$cleaned = $this->drop_workspace_kinds( $workspace, array( 'stage', 'merge' ) );
			if ( is_wp_error( $cleaned ) ) {
				return $this->release_after_error( $owner_token, $cleaned );
			}
			$maps = $this->restore_maps->clear( $owner_token );
			if ( is_wp_error( $maps ) ) {
				return $this->release_after_error( $owner_token, $maps );
			}
		}

		foreach ( DomainBackupRepository::entity_types() as $entity ) {
			$live  = SchemaManager::table( $entity );
			$stage = $this->table_name( $workspace, 'stage', $entity );
			if ( ! $this->create_like( $stage, $live ) ) {
				$cleanup = $this->drop_workspace_tables( $workspace, 'stage' );
				$error = is_wp_error( $cleanup )
					? $cleanup
					: new \WP_Error( 'certloom_domain_restore_stage_create_failed', __( 'Certloom could not prepare all private restore tables safely.', 'certloom' ) );
				return $this->release_after_error( $owner_token, $error );
			}
		}

		if ( ! $this->save_build_marker( $workspace ) ) {
			$cleanup = $this->drop_workspace_tables( $workspace, 'stage' );
			$error = is_wp_error( $cleanup )
				? $cleanup
				: new \WP_Error( 'certloom_domain_restore_build_marker_failed', __( 'Certloom could not save the restore workspace marker. No live data was changed.', 'certloom' ) );
			return $this->release_after_error( $owner_token, $error );
		}

		return $workspace;
	}

	/** @return true|\WP_Error */
	public function insert_row( array $workspace, $entity, array $row ) {
		global $wpdb;
		$valid = $this->validate_workspace( $workspace );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		if ( ! in_array( $entity, DomainBackupRepository::entity_types(), true ) ) {
			return new \WP_Error( 'certloom_domain_restore_entity_invalid', __( 'Certloom refused an unsupported restore entity.', 'certloom' ) );
		}

		$allowed = SchemaManager::column_names( $entity );
		$keys    = array_keys( $row );
		sort( $allowed );
		sort( $keys );
		if ( $allowed !== $keys ) {
			return new \WP_Error( 'certloom_domain_restore_columns_invalid', __( 'The restore record does not match the expected Certloom schema.', 'certloom' ) );
		}
		$id = absint( $row['id'] ?? 0 );
		if ( $id < 1 ) {
			return new \WP_Error( 'certloom_domain_restore_id_invalid', __( 'The restore record contains an invalid internal identifier.', 'certloom' ) );
		}

		$table = $this->table_name( $workspace, 'stage', $entity );
		$wpdb->last_error = '';
		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $id ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_domain_restore_stage_read_failed', __( 'Certloom could not inspect its private restore workspace.', 'certloom' ) );
		}
		if ( is_array( $existing ) ) {
			return $this->rows_equal( $row, $existing )
				? true
				: new \WP_Error( 'certloom_domain_restore_retry_conflict', __( 'A retried restore slice disagrees with data already staged for the same record.', 'certloom' ) );
		}

		$wpdb->last_error = '';
		$inserted = $wpdb->insert( $table, $row );
		return false === $inserted || '' !== (string) $wpdb->last_error
			? new \WP_Error( 'certloom_domain_restore_stage_insert_failed', __( 'Certloom could not stage a validated restore record. No live data was changed.', 'certloom' ) )
			: true;
	}

	/** @return true|\WP_Error */
	public function validate_relationships( array $workspace ) {
		return $this->validate_relationships_for_kind( $workspace, 'stage' );
	}

	/** @return true|\WP_Error */
	private function validate_relationships_for_kind( array $workspace, $kind ) {
		global $wpdb;
		$valid = $this->validate_workspace( $workspace );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$t = array();
		foreach ( DomainBackupRepository::entity_types() as $entity ) {
			$t[ $entity ] = $this->table_name( $workspace, $kind, $entity );
		}
		$checks = array(
			'program_issuer_missing' => array( "SELECT COUNT(*) FROM %i p LEFT JOIN %i i ON i.id=p.issuer_id WHERE p.issuer_id IS NOT NULL AND i.id IS NULL", array( $t['programs'], $t['issuers'] ) ),
			'batch_program_missing' => array( "SELECT COUNT(*) FROM %i b LEFT JOIN %i p ON p.id=b.program_id WHERE p.id IS NULL", array( $t['batches'], $t['programs'] ) ),
			'batch_signer_missing' => array( "SELECT COUNT(*) FROM %i b LEFT JOIN %i s ON s.id=b.instructor_signer_id WHERE b.instructor_signer_id IS NOT NULL AND s.id IS NULL", array( $t['batches'], $t['signers'] ) ),
			'batch_member_batch_missing' => array( "SELECT COUNT(*) FROM %i m LEFT JOIN %i b ON b.id=m.batch_id WHERE b.id IS NULL", array( $t['batch_recipients'], $t['batches'] ) ),
			'batch_member_recipient_missing' => array( "SELECT COUNT(*) FROM %i m LEFT JOIN %i r ON r.id=m.recipient_id WHERE r.id IS NULL", array( $t['batch_recipients'], $t['recipients'] ) ),
			'signer_assignment_signer_missing' => array( "SELECT COUNT(*) FROM %i a LEFT JOIN %i s ON s.id=a.signer_id WHERE s.id IS NULL", array( $t['signer_assignments'], $t['signers'] ) ),
			'signer_assignment_issuer_missing' => array( "SELECT COUNT(*) FROM %i a LEFT JOIN %i i ON i.id=a.scope_id WHERE a.scope_type='issuer' AND i.id IS NULL", array( $t['signer_assignments'], $t['issuers'] ) ),
			'signer_assignment_program_missing' => array( "SELECT COUNT(*) FROM %i a LEFT JOIN %i p ON p.id=a.scope_id WHERE a.scope_type='program' AND p.id IS NULL", array( $t['signer_assignments'], $t['programs'] ) ),
			'signer_assignment_batch_missing' => array( "SELECT COUNT(*) FROM %i a LEFT JOIN %i b ON b.id=a.scope_id WHERE a.scope_type='batch' AND b.id IS NULL", array( $t['signer_assignments'], $t['batches'] ) ),
			'template_current_revision_invalid' => array( "SELECT COUNT(*) FROM %i t LEFT JOIN %i r ON r.id=t.current_revision_id AND r.template_id=t.id WHERE t.current_revision_id IS NOT NULL AND r.id IS NULL", array( $t['templates'], $t['template_revisions'] ) ),
			'template_published_revision_invalid' => array( "SELECT COUNT(*) FROM %i t LEFT JOIN %i r ON r.id=t.published_revision_id AND r.template_id=t.id WHERE t.published_revision_id IS NOT NULL AND r.id IS NULL", array( $t['templates'], $t['template_revisions'] ) ),
			'template_revision_template_missing' => array( "SELECT COUNT(*) FROM %i r LEFT JOIN %i t ON t.id=r.template_id WHERE t.id IS NULL", array( $t['template_revisions'], $t['templates'] ) ),
			'template_revision_parent_invalid' => array( "SELECT COUNT(*) FROM %i r LEFT JOIN %i p ON p.id=r.parent_revision_id AND p.template_id=r.template_id WHERE r.parent_revision_id IS NOT NULL AND (p.id IS NULL OR p.id=r.id)", array( $t['template_revisions'], $t['template_revisions'] ) ),
			'template_assignment_template_missing' => array( "SELECT COUNT(*) FROM %i a LEFT JOIN %i t ON t.id=a.template_id WHERE t.id IS NULL", array( $t['template_assignments'], $t['templates'] ) ),
			'template_assignment_issuer_missing' => array( "SELECT COUNT(*) FROM %i a LEFT JOIN %i i ON i.id=a.scope_id WHERE a.scope_type='issuer' AND i.id IS NULL", array( $t['template_assignments'], $t['issuers'] ) ),
			'template_assignment_program_missing' => array( "SELECT COUNT(*) FROM %i a LEFT JOIN %i p ON p.id=a.scope_id WHERE a.scope_type='program' AND p.id IS NULL", array( $t['template_assignments'], $t['programs'] ) ),
			'template_assignment_batch_missing' => array( "SELECT COUNT(*) FROM %i a LEFT JOIN %i b ON b.id=a.scope_id WHERE a.scope_type='batch' AND b.id IS NULL", array( $t['template_assignments'], $t['batches'] ) ),
			'series_recipient_missing' => array( "SELECT COUNT(*) FROM %i s LEFT JOIN %i r ON r.id=s.recipient_id WHERE r.id IS NULL", array( $t['credential_series'], $t['recipients'] ) ),
			'series_program_missing' => array( "SELECT COUNT(*) FROM %i s LEFT JOIN %i p ON p.id=s.program_id WHERE s.program_id IS NOT NULL AND p.id IS NULL", array( $t['credential_series'], $t['programs'] ) ),
			'series_batch_missing' => array( "SELECT COUNT(*) FROM %i s LEFT JOIN %i b ON b.id=s.batch_id WHERE s.batch_id IS NOT NULL AND b.id IS NULL", array( $t['credential_series'], $t['batches'] ) ),
			'series_issuer_missing' => array( "SELECT COUNT(*) FROM %i s LEFT JOIN %i i ON i.id=s.issuer_id WHERE s.issuer_id IS NOT NULL AND i.id IS NULL", array( $t['credential_series'], $t['issuers'] ) ),
			'number_series_missing' => array( "SELECT COUNT(*) FROM %i n LEFT JOIN %i s ON s.id=n.series_id WHERE s.id IS NULL", array( $t['credential_numbers'], $t['credential_series'] ) ),
			'version_series_missing' => array( "SELECT COUNT(*) FROM %i v LEFT JOIN %i s ON s.id=v.series_id WHERE s.id IS NULL", array( $t['credential_versions'], $t['credential_series'] ) ),
			'version_number_assignment_missing' => array( "SELECT COUNT(*) FROM %i v LEFT JOIN %i n ON n.display_number=v.display_number AND n.series_id=v.series_id WHERE n.id IS NULL", array( $t['credential_versions'], $t['credential_numbers'] ) ),
			'current_version_invalid' => array( "SELECT COUNT(*) FROM %i s LEFT JOIN %i v ON v.id=s.current_version_id AND v.series_id=s.id WHERE s.current_version_id IS NOT NULL AND v.id IS NULL", array( $t['credential_series'], $t['credential_versions'] ) ),
			'number_first_version_invalid' => array( "SELECT COUNT(*) FROM %i n LEFT JOIN %i v ON v.id=n.first_version_id AND v.series_id=n.series_id WHERE n.first_version_id IS NOT NULL AND v.id IS NULL", array( $t['credential_numbers'], $t['credential_versions'] ) ),
			'event_series_missing' => array( "SELECT COUNT(*) FROM %i e LEFT JOIN %i s ON s.id=e.series_id WHERE s.id IS NULL", array( $t['credential_events'], $t['credential_series'] ) ),
			'event_version_invalid' => array( "SELECT COUNT(*) FROM %i e LEFT JOIN %i v ON v.id=e.version_id AND v.series_id=e.series_id WHERE e.version_id IS NOT NULL AND v.id IS NULL", array( $t['credential_events'], $t['credential_versions'] ) ),
		);

		foreach ( $checks as $code => $check ) {
			$wpdb->last_error = '';
			$count = $wpdb->get_var( $wpdb->prepare( $check[0], $check[1] ) );
			if ( '' !== (string) $wpdb->last_error ) {
				return new \WP_Error( 'certloom_domain_restore_relationship_check_failed', __( 'Certloom could not validate the staged credential relationships.', 'certloom' ) );
			}
			if ( (int) $count > 0 ) {
				return new \WP_Error( 'certloom_domain_restore_' . $code, __( 'The staged Certloom backup contains broken credential relationships and cannot be activated.', 'certloom' ), array( 'count' => (int) $count ) );
			}
		}
		return true;
	}

	/** @return true|\WP_Error */
	public function touch( array $workspace ) {
		$valid = $this->validate_workspace( $workspace );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$lock = $this->maintenance->touch( $workspace['owner_token'], 'domain_restore', self::BUILD_TTL );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}
		return $this->save_build_marker( $workspace )
			? true
			: new \WP_Error( 'certloom_domain_restore_build_marker_failed', __( 'Certloom could not refresh the restore workspace marker.', 'certloom' ) );
	}

	/** @return true|\WP_Error */
	public function abandon( array $workspace ) {
		$valid = $this->validate_workspace( $workspace );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$cleaned = $this->drop_workspace_kinds( $workspace, array( 'stage', 'merge' ) );
		if ( is_wp_error( $cleaned ) ) {
			return $cleaned;
		}
		$maps = $this->restore_maps->clear( $workspace['owner_token'] );
		if ( is_wp_error( $maps ) ) {
			return $maps;
		}
		$released = $this->maintenance->release( $workspace['owner_token'] );
		if ( is_wp_error( $released ) ) {
			return $released;
		}
		$marker_deleted = $this->delete_build_marker( $workspace['owner_token'] );
		return is_wp_error( $marker_deleted ) ? $marker_deleted : true;
	}

	/** @return true|\WP_Error */
	public function commit_replace( array $workspace, array $configuration, array $receipt = array() ) {
		$receipt['mode'] = 'replace';
		return $this->commit_workspace( $workspace, 'stage', $configuration, $receipt );
	}

	/** @return true|\WP_Error */
	public function commit_merge( array $workspace, array $configuration, array $receipt = array() ) {
		$receipt['mode'] = 'merge';
		return $this->commit_workspace( $workspace, 'merge', $configuration, $receipt );
	}

	/**
	 * Creates same-schema private tables that will become the complete merged
	 * target graph. The current live graph is copied into them separately in
	 * bounded slices before backup-only records are reconciled.
	 *
	 * @return true|\WP_Error
	 */
	public function create_merge_target( array $workspace ) {
		$valid = $this->validate_workspace( $workspace );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		foreach ( DomainBackupRepository::entity_types() as $entity ) {
			$target = $this->table_name( $workspace, 'merge', $entity );
			$live   = SchemaManager::table( $entity );
			if ( ! $this->create_like( $target, $live ) ) {
				$cleanup = $this->drop_workspace_tables( $workspace, 'merge' );
				return is_wp_error( $cleanup )
					? $cleanup
					: new \WP_Error( 'certloom_domain_restore_merge_create_failed', __( 'Certloom could not prepare the private Merge restore target tables.', 'certloom' ) );
			}
		}
		return true;
	}

	/** @return array<string,int>|\WP_Error */
	public function copy_live_batch( array $workspace, $entity, $after_id, $limit = 200 ) {
		global $wpdb;
		$valid = $this->validate_workspace( $workspace );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		if ( ! in_array( $entity, DomainBackupRepository::entity_types(), true ) ) {
			return new \WP_Error( 'certloom_domain_restore_entity_invalid', __( 'Certloom refused an unsupported Merge restore entity.', 'certloom' ) );
		}
		$limit  = max( 1, min( 500, absint( $limit ) ) );
		$after  = absint( $after_id );
		$live   = SchemaManager::table( $entity );
		$target = $this->table_name( $workspace, 'merge', $entity );
		$wpdb->last_error = '';
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE id > %d ORDER BY id ASC LIMIT %d', $live, $after, $limit ) );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $ids ) ) {
			return new \WP_Error( 'certloom_domain_restore_merge_copy_read_failed', __( 'Certloom could not read the current credential data while preparing Merge restore.', 'certloom' ) );
		}
		if ( empty( $ids ) ) {
			return array( 'processed' => 0, 'last_id' => $after, 'complete' => 1 );
		}
		$max_id = max( array_map( 'absint', $ids ) );
		$wpdb->last_error = '';
		$inserted = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i SELECT * FROM %i WHERE id > %d AND id <= %d ORDER BY id ASC',
				$target,
				$live,
				$after,
				$max_id
			)
		);
		if ( false === $inserted || '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_domain_restore_merge_copy_failed', __( 'Certloom could not copy current credential data into its private Merge restore workspace.', 'certloom' ) );
		}
		return array( 'processed' => count( $ids ), 'last_id' => $max_id, 'complete' => count( $ids ) < $limit ? 1 : 0 );
	}

	/** @return array<int,array<string,mixed>>|\WP_Error */
	public function read_stage_batch( array $workspace, $entity, $after_id, $limit = 200 ) {
		global $wpdb;
		$valid = $this->validate_workspace( $workspace );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		if ( ! in_array( $entity, DomainBackupRepository::entity_types(), true ) ) {
			return new \WP_Error( 'certloom_domain_restore_entity_invalid', __( 'Certloom refused an unsupported Merge restore entity.', 'certloom' ) );
		}
		$limit = max( 1, min( 500, absint( $limit ) ) );
		$wpdb->last_error = '';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id > %d ORDER BY id ASC LIMIT %d',
				$this->table_name( $workspace, 'stage', $entity ),
				absint( $after_id ),
				$limit
			),
			ARRAY_A
		);
		return '' !== (string) $wpdb->last_error || ! is_array( $rows )
			? new \WP_Error( 'certloom_domain_restore_merge_stage_read_failed', __( 'Certloom could not read its validated Merge restore staging records.', 'certloom' ) )
			: $rows;
	}

	/** @return string|\WP_Error */
	public function workspace_table( array $workspace, $kind, $entity ) {
		$valid = $this->validate_workspace( $workspace );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		if ( ! in_array( $kind, array( 'stage', 'merge', 'rollback' ), true ) || ! in_array( $entity, DomainBackupRepository::entity_types(), true ) ) {
			return new \WP_Error( 'certloom_domain_restore_workspace_table_invalid', __( 'Certloom refused an invalid private restore table request.', 'certloom' ) );
		}
		return $this->table_name( $workspace, $kind, $entity );
	}

	/** @return true|\WP_Error */
	public function validate_merge_relationships( array $workspace ) {
		return $this->validate_relationships_for_kind( $workspace, 'merge' );
	}

	/** @return true|\WP_Error */
	private function commit_workspace( array $workspace, $source_kind, array $configuration, array $receipt = array() ) {
		$owner = $this->owner_token( $workspace['owner_token'] ?? '' );
		if ( is_wp_error( $owner ) ) {
			return $owner;
		}
		$existing_receipt = $this->completion_receipt( $owner );
		if ( is_wp_error( $existing_receipt ) ) {
			return $existing_receipt;
		}
		if ( is_array( $existing_receipt ) ) {
			$valid = $this->validate_workspace( $workspace );
			return is_wp_error( $valid ) ? $valid : $this->cleanup_committed_workspace( $workspace );
		}

		$valid = $this->validate_workspace( $workspace );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$relationships = $this->validate_relationships_for_kind( $workspace, $source_kind );
		if ( is_wp_error( $relationships ) ) {
			return $relationships;
		}
		$touched = $this->touch( $workspace );
		if ( is_wp_error( $touched ) ) {
			return $touched;
		}

		$normalized_receipt = $this->normalize_receipt( $receipt );
		if ( is_wp_error( $normalized_receipt ) ) {
			return $normalized_receipt;
		}
		$old_configuration = $this->configuration_snapshot();
		$old_generation    = $this->generation->current();
		$journal = array(
			'state'             => 'prepared',
			'owner_token'       => $workspace['owner_token'],
			'suffix'            => $workspace['suffix'],
			'old_configuration' => $old_configuration,
			'old_generation'    => $old_generation,
			'receipt'           => $normalized_receipt,
			'source_kind'       => $source_kind,
			'created_at'        => time(),
		);
		if ( ! $this->save_journal( $journal ) ) {
			return new \WP_Error( 'certloom_domain_restore_journal_failed', __( 'Certloom could not save the restore rollback journal, so no live data was changed.', 'certloom' ) );
		}

		$swapped = $this->swap_workspace( $workspace, $source_kind );
		if ( is_wp_error( $swapped ) ) {
			$journal_deleted = $this->delete_journal_if_owned( $workspace['owner_token'] );
			if ( is_wp_error( $journal_deleted ) ) {
				return new \WP_Error(
					'certloom_domain_restore_swap_cleanup_failed',
					sprintf(
						__( 'Certloom could not swap the restore workspace (%1$s), and it also could not clear the owner-matched rollback journal safely (%2$s). No authority was switched; review restore recovery state before retrying.', 'certloom' ),
						$swapped->get_error_message(),
						$journal_deleted->get_error_message()
					)
				);
			}
			return $swapped;
		}
		$journal['state'] = 'swapped';
		if ( ! $this->save_journal( $journal ) ) {
			return $this->rollback_after_failure( $workspace, $old_configuration, $old_generation, 'certloom_domain_restore_journal_failed', $source_kind );
		}

		$config_saved = DomainMaintenanceLock::run_internal( function () use ( $configuration ) {
			return $this->apply_configuration( $configuration );
		} );
		if ( is_wp_error( $config_saved ) ) {
			return $this->rollback_after_failure( $workspace, $old_configuration, $old_generation, 'certloom_domain_restore_configuration_failed', $source_kind );
		}
		$generation_saved = DomainMaintenanceLock::run_internal( function () {
			return $this->generation->mark_before_mutation();
		} );
		if ( is_wp_error( $generation_saved ) ) {
			return $this->rollback_after_failure( $workspace, $old_configuration, $old_generation, 'certloom_domain_restore_generation_failed', $source_kind );
		}

		$journal['state'] = 'committed';
		if ( ! $this->save_journal( $journal ) ) {
			return $this->rollback_after_failure( $workspace, $old_configuration, $old_generation, 'certloom_domain_restore_commit_failed', $source_kind );
		}

		$receipt_saved = $this->save_completion_receipt( $workspace['owner_token'], (array) $journal['receipt'] );
		if ( is_wp_error( $receipt_saved ) ) {
			return $this->rollback_after_failure( $workspace, $old_configuration, $old_generation, 'certloom_domain_restore_receipt_failed', $source_kind );
		}

		return $this->cleanup_committed_workspace( $workspace );
	}

	/** @return true|\WP_Error */
	public function recover_interrupted_commit() {
		$journal = get_option( self::JOURNAL_OPTION, array() );
		if ( ! is_array( $journal ) || empty( $journal['owner_token'] ) || empty( $journal['suffix'] ) ) {
			return $this->recover_stale_build();
		}
		$workspace = array(
			'owner_token' => (string) $journal['owner_token'],
			'suffix'      => (string) $journal['suffix'],
		);
		$valid = $this->validate_workspace( $workspace );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$state = sanitize_key( (string) ( $journal['state'] ?? 'prepared' ) );
		$journal_source_kind = (string) ( $journal['source_kind'] ?? 'stage' );
		$source_kind = in_array( $journal_source_kind, array( 'stage', 'merge' ), true ) ? $journal_source_kind : 'stage';
		if ( 'committed' === $state ) {
			$receipt_saved = $this->save_completion_receipt( $workspace['owner_token'], (array) ( $journal['receipt'] ?? array() ) );
			if ( is_wp_error( $receipt_saved ) ) {
				return $receipt_saved;
			}
			return $this->cleanup_committed_workspace( $workspace );
		}
		if ( 'swapped' === $state || $this->rollback_workspace_exists( $workspace ) ) {
			$rolled = $this->rollback_swap( $workspace, $source_kind );
			if ( is_wp_error( $rolled ) ) {
				return $rolled;
			}
		}
		$restored = DomainMaintenanceLock::run_internal( function () use ( $journal ) {
			$config = $this->restore_configuration_snapshot( (array) ( $journal['old_configuration'] ?? array() ) );
			if ( is_wp_error( $config ) ) {
				return $config;
			}
			return $this->restore_generation( (string) ( $journal['old_generation'] ?? '0' ) );
		} );
		if ( is_wp_error( $restored ) ) {
			return $restored;
		}
		$cleaned = $this->drop_workspace_kinds( $workspace, array( 'stage', 'merge' ) );
		if ( is_wp_error( $cleaned ) ) {
			/* Keep the journal/build marker and maintenance lock so cleanup can retry safely. */
			return $cleaned;
		}
		$released = $this->maintenance->release( $workspace['owner_token'] );
		if ( is_wp_error( $released ) ) {
			/* Keep the journal/build marker so recovery can retry cleanup idempotently. */
			return $released;
		}
		$journal_deleted = $this->delete_journal_if_owned( $workspace['owner_token'] );
		if ( is_wp_error( $journal_deleted ) ) { return $journal_deleted; }
		$marker_deleted = $this->delete_build_marker( $workspace['owner_token'] );
		if ( is_wp_error( $marker_deleted ) ) { return $marker_deleted; }
		return true;
	}


	/** @return array<string,mixed>|null|\WP_Error */
	public function completion_receipt( $owner_token ) {
		$owner_token = $this->owner_token( $owner_token );
		if ( is_wp_error( $owner_token ) ) {
			return $owner_token;
		}
		$receipts = get_option( self::RECEIPTS_OPTION, array() );
		if ( ! is_array( $receipts ) || ! isset( $receipts[ $owner_token ] ) || ! is_array( $receipts[ $owner_token ] ) ) {
			return null;
		}
		$receipt = $receipts[ $owner_token ];
		$committed_at = absint( $receipt['committed_at'] ?? 0 );
		if ( ! $committed_at || $committed_at > time() + 300 ) {
			return new \WP_Error( 'certloom_domain_restore_receipt_corrupt', __( 'Certloom found an invalid restore completion receipt and refused to trust it.', 'certloom' ) );
		}
		if ( ( time() - $committed_at ) > self::RECEIPT_TTL ) {
			return null;
		}
		$validated = $this->normalize_receipt( $receipt );
		if ( is_wp_error( $validated ) || '' === (string) ( $receipt['generation'] ?? '' ) ) {
			return is_wp_error( $validated )
				? new \WP_Error( 'certloom_domain_restore_receipt_corrupt', __( 'Certloom found an inconsistent restore completion receipt and refused to trust it.', 'certloom' ) )
				: new \WP_Error( 'certloom_domain_restore_receipt_corrupt', __( 'Certloom found an incomplete restore completion receipt and refused to trust it.', 'certloom' ) );
		}
		$validated['committed_at'] = $committed_at;
		$validated['generation'] = (string) $receipt['generation'];
		return $validated;
	}

	/** @return array<string,mixed> */
	private function workspace( $owner_token ) {
		return array( 'owner_token' => $owner_token, 'suffix' => substr( $owner_token, 0, 12 ) );
	}

	/** @return true|\WP_Error */
	private function validate_workspace( array $workspace ) {
		$owner = $this->owner_token( $workspace['owner_token'] ?? '' );
		if ( is_wp_error( $owner ) ) {
			return $owner;
		}
		$suffix = strtolower( trim( (string) ( $workspace['suffix'] ?? '' ) ) );
		return substr( $owner, 0, 12 ) === $suffix
			? true
			: new \WP_Error( 'certloom_domain_restore_workspace_invalid', __( 'The Certloom restore workspace identity is invalid.', 'certloom' ) );
	}

	/** @return string|\WP_Error */
	private function owner_token( $token ) {
		$token = strtolower( trim( (string) $token ) );
		return preg_match( '/^[a-f0-9]{32}$/', $token )
			? $token
			: new \WP_Error( 'certloom_domain_restore_owner_invalid', __( 'The Certloom restore owner token is invalid.', 'certloom' ) );
	}

	/** @return string */
	private function table_name( array $workspace, $kind, $entity ) {
		global $wpdb;
		$codes = array(
			'issuers' => 'i', 'programs' => 'p', 'recipients' => 'r',
			'signers' => 'g', 'batches' => 'b', 'batch_recipients' => 'm', 'signer_assignments' => 'a',
			'templates' => 't', 'template_revisions' => 'u', 'template_assignments' => 'y',
			'credential_series' => 's', 'credential_numbers' => 'n',
			'credential_versions' => 'v', 'credential_events' => 'e',
		);
		$prefix = 'rollback' === $kind ? 'certloom_rb_' : ( 'merge' === $kind ? 'certloom_rm_' : 'certloom_rs_' );
		return $wpdb->prefix . $prefix . $workspace['suffix'] . '_' . $codes[ $entity ];
	}

	/** @return bool */
	private function create_like( $target, $source ) {
		global $wpdb;
		$wpdb->last_error = '';
		$dropped = $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $target ) );
		if ( false === $dropped || '' !== (string) $wpdb->last_error ) {
			return false;
		}
		$wpdb->last_error = '';
		$created = $wpdb->query( $wpdb->prepare( 'CREATE TABLE %i LIKE %i', $target, $source ) );
		return false !== $created && '' === (string) $wpdb->last_error;
	}

	/** @return bool */
	private function table_exists( $table ) {
		global $wpdb;
		$wpdb->last_error = '';
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		return '' === (string) $wpdb->last_error && (string) $found === (string) $table;
	}

	/** @return bool */
	private function rollback_workspace_exists( array $workspace ) {
		foreach ( DomainBackupRepository::entity_types() as $entity ) {
			if ( ! $this->table_exists( $this->table_name( $workspace, 'rollback', $entity ) ) ) {
				return false;
			}
		}
		return true;
	}

	/** @return bool */
	private function workspace_exists( array $workspace ) {
		foreach ( DomainBackupRepository::entity_types() as $entity ) {
			if ( ! $this->table_exists( $this->table_name( $workspace, 'stage', $entity ) ) ) {
				return false;
			}
		}
		return true;
	}

	/** @return true|\WP_Error */
	private function drop_workspace_tables( array $workspace, $kind ) {
		global $wpdb;
		if ( ! in_array( $kind, array( 'stage', 'merge', 'rollback' ), true ) ) {
			return new \WP_Error( 'certloom_domain_restore_cleanup_kind_invalid', __( 'Certloom refused an invalid restore-workspace cleanup request.', 'certloom' ) );
		}
		foreach ( DomainBackupRepository::entity_types() as $entity ) {
			$table = $this->table_name( $workspace, $kind, $entity );
			$wpdb->last_error = '';
			$dropped = $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
			if ( false === $dropped || '' !== (string) $wpdb->last_error ) {
				return new \WP_Error( 'certloom_domain_restore_workspace_cleanup_failed', __( 'Certloom could not remove a private restore workspace table safely.', 'certloom' ) );
			}
		}
		return true;
	}

	/** @return true|\WP_Error */
	private function drop_workspace_kinds( array $workspace, array $kinds ) {
		foreach ( $kinds as $kind ) {
			$dropped = $this->drop_workspace_tables( $workspace, $kind );
			if ( is_wp_error( $dropped ) ) {
				return $dropped;
			}
		}
		return true;
	}

	/** @return true|\WP_Error */
	private function swap_workspace( array $workspace, $source_kind = 'stage' ) {
		global $wpdb;
		$cleared = $this->drop_workspace_tables( $workspace, 'rollback' );
		if ( is_wp_error( $cleared ) ) {
			return $cleared;
		}
		$parts = array();
		$args  = array();
		foreach ( DomainBackupRepository::entity_types() as $entity ) {
			$live     = SchemaManager::table( $entity );
			$stage    = $this->table_name( $workspace, $source_kind, $entity );
			$rollback = $this->table_name( $workspace, 'rollback', $entity );
			$parts[] = '%i TO %i, %i TO %i';
			array_push( $args, $live, $rollback, $stage, $live );
		}
		$wpdb->last_error = '';
		$sql = $wpdb->prepare( 'RENAME TABLE ' . implode( ', ', $parts ), $args );
		$result = $wpdb->query( $sql );
		return false === $result || '' !== (string) $wpdb->last_error
			? new \WP_Error( 'certloom_domain_restore_swap_failed', __( 'Certloom could not atomically activate the prepared restore workspace.', 'certloom' ) )
			: true;
	}

	/** @return true|\WP_Error */
	private function rollback_swap( array $workspace, $source_kind = 'stage' ) {
		global $wpdb;
		$cleared = $this->drop_workspace_tables( $workspace, $source_kind );
		if ( is_wp_error( $cleared ) ) {
			return $cleared;
		}
		$parts = array();
		$args  = array();
		foreach ( DomainBackupRepository::entity_types() as $entity ) {
			$live     = SchemaManager::table( $entity );
			$rollback = $this->table_name( $workspace, 'rollback', $entity );
			$failed   = $this->table_name( $workspace, $source_kind, $entity );
			if ( ! $this->table_exists( $rollback ) ) {
				return new \WP_Error( 'certloom_domain_restore_rollback_missing', __( 'Certloom cannot find all rollback tables required to recover the previous credential data.', 'certloom' ) );
			}
			$parts[] = '%i TO %i, %i TO %i';
			array_push( $args, $live, $failed, $rollback, $live );
		}
		$wpdb->last_error = '';
		$result = $wpdb->query( $wpdb->prepare( 'RENAME TABLE ' . implode( ', ', $parts ), $args ) );
		if ( false === $result || '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_domain_restore_rollback_failed', __( 'Certloom could not automatically restore the previous credential tables. Restore the site database backup before continuing.', 'certloom' ) );
		}
		return true;
	}

	/** @return true|\WP_Error */
	private function rollback_after_failure( array $workspace, array $old_configuration, $old_generation, $error_code, $source_kind = 'stage' ) {
		$rolled = $this->rollback_swap( $workspace, $source_kind );
		if ( is_wp_error( $rolled ) ) {
			return $rolled;
		}
		$restored = DomainMaintenanceLock::run_internal( function () use ( $old_configuration, $old_generation ) {
			$config = $this->restore_configuration_snapshot( $old_configuration );
			if ( is_wp_error( $config ) ) {
				return $config;
			}
			return $this->restore_generation( $old_generation );
		} );
		if ( is_wp_error( $restored ) ) {
			return new \WP_Error( 'certloom_domain_restore_rollback_incomplete', __( 'Certloom restored the previous credential tables but could not fully restore configuration metadata. Restore the site database backup before continuing.', 'certloom' ) );
		}
		$cleaned = $this->drop_workspace_kinds( $workspace, array( 'stage', 'merge' ) );
		if ( is_wp_error( $cleaned ) ) {
			/* Preserve journal/build/maintenance ownership so cleanup can be retried. */
			return new \WP_Error( 'certloom_domain_restore_rollback_workspace_cleanup_failed', __( 'Certloom restored the previous credential data, but could not remove its private failed-restore workspace. Credential writes remain paused until recovery cleanup succeeds.', 'certloom' ) );
		}
		$released = $this->maintenance->release( $workspace['owner_token'] );
		if ( is_wp_error( $released ) ) {
			/* Preserve the journal/build marker so a later recovery can retry cleanup. */
			return new \WP_Error(
				'certloom_domain_restore_rollback_cleanup_failed',
				__( 'Certloom restored the previous credential data, but could not release the maintenance lock safely. Credential writes remain paused until recovery cleanup succeeds.', 'certloom' )
			);
		}
		$journal_deleted = $this->delete_journal_if_owned( $workspace['owner_token'] );
		$receipt_deleted = $this->delete_completion_receipt( $workspace['owner_token'] );
		$marker_deleted = $this->delete_build_marker( $workspace['owner_token'] );
		if ( is_wp_error( $journal_deleted ) || is_wp_error( $receipt_deleted ) || is_wp_error( $marker_deleted ) ) {
			return new \WP_Error( 'certloom_domain_restore_rollback_tracking_cleanup_failed', __( 'Certloom restored the previous credential data, but could not clear all restore tracking evidence safely. Review recovery status before continuing.', 'certloom' ) );
		}
		return new \WP_Error( $error_code, __( 'Certloom could not safely commit the restore, so the previous credential data and configuration were restored.', 'certloom' ) );
	}

	/**
	 * Idempotent cleanup after a durable completion receipt exists. A transient
	 * release failure can therefore be repaired by replaying the completed job
	 * without re-promoting credential tables. Journal deletion is owner-matched
	 * so an old completion replay cannot remove a newer restore's recovery state.
	 *
	 * @return true|\WP_Error
	 */
	private function cleanup_committed_workspace( array $workspace ) {
		$cleaned = $this->drop_workspace_kinds( $workspace, array( 'rollback', 'stage', 'merge' ) );
		if ( is_wp_error( $cleaned ) ) {
			/* Keep journal/build/maintenance evidence until private-table cleanup is confirmed. */
			return $cleaned;
		}

		/* Never release a newer maintenance operation owned by somebody else. */
		if ( $this->maintenance->owned_active( $workspace['owner_token'] ) ) {
			$released = $this->maintenance->release( $workspace['owner_token'] );
			if ( is_wp_error( $released ) ) {
				/* Keep recovery markers until the owner-matched release is confirmed. */
				return $released;
			}
		}
		$journal_deleted = $this->delete_journal_if_owned( $workspace['owner_token'] );
		if ( is_wp_error( $journal_deleted ) ) { return $journal_deleted; }
		$marker_deleted = $this->delete_build_marker( $workspace['owner_token'] );
		return is_wp_error( $marker_deleted ) ? $marker_deleted : true;
	}

	/** @return true|\WP_Error */
	private function delete_journal_if_owned( $owner_token ) {
		$journal = get_option( self::JOURNAL_OPTION, array() );
		if ( ! is_array( $journal ) || ! isset( $journal['owner_token'] ) || ! hash_equals( (string) $owner_token, (string) $journal['owner_token'] ) ) {
			return true;
		}
		delete_option( self::JOURNAL_OPTION );
		$after = get_option( self::JOURNAL_OPTION, null );
		if ( null === $after || ! is_array( $after ) || ! isset( $after['owner_token'] ) || ! hash_equals( (string) $owner_token, (string) $after['owner_token'] ) ) {
			return true;
		}
		return new \WP_Error( 'certloom_domain_restore_journal_cleanup_failed', __( 'Certloom could not remove its restore recovery journal safely.', 'certloom' ) );
	}

	/** Returns the original failure only when the restore maintenance lock was safely released. */
	private function release_after_error( $owner_token, \WP_Error $error ) {
		$released = $this->maintenance->release( $owner_token );
		if ( ! is_wp_error( $released ) ) {
			return $error;
		}
		return new \WP_Error(
			'certloom_domain_restore_cleanup_failed',
			sprintf(
				__( 'Certloom restore failed (%1$s), and its maintenance lock could not be released safely (%2$s). Credential writes remain paused until recovery cleanup succeeds.', 'certloom' ),
				$error->get_error_message(),
				$released->get_error_message()
			)
		);
	}

	/** @return array<string,mixed> */
	private function configuration_snapshot() {
		$values = array();
		foreach ( DomainBackupRepository::configuration_keys() as $key ) {
			$sentinel = array( '__certloom_missing_option__' => $key );
			$value = get_option( $key, $sentinel );
			$values[ $key ] = $value === $sentinel ? array( 'exists' => false, 'value' => null ) : array( 'exists' => true, 'value' => $value );
		}
		return $values;
	}

	/** @return true|\WP_Error */
	private function apply_configuration( array $configuration ) {
		foreach ( DomainBackupRepository::configuration_keys() as $key ) {
			if ( ! isset( $configuration[ $key ] ) || ! is_array( $configuration[ $key ] ) || ! array_key_exists( 'exists', $configuration[ $key ] ) || ! array_key_exists( 'value', $configuration[ $key ] ) ) {
				return new \WP_Error( 'certloom_domain_restore_configuration_invalid', __( 'The restore configuration is incomplete.', 'certloom' ) );
			}
			if ( ! empty( $configuration[ $key ]['exists'] ) ) {
				update_option( $key, $configuration[ $key ]['value'], false );
				$result = $configuration[ $key ]['value'] === get_option( $key, null );
			} else {
				$sentinel = array( '__certloom_restore_missing__' => $key );
				delete_option( $key );
				$result = $sentinel === get_option( $key, $sentinel );
			}
			if ( ! $result ) {
				return new \WP_Error( 'certloom_domain_restore_configuration_save_failed', __( 'Certloom could not save restored configuration.', 'certloom' ) );
			}
		}
		return true;
	}

	/** @return true|\WP_Error */
	private function restore_configuration_snapshot( array $snapshot ) {
		return $this->apply_configuration( $snapshot );
	}

	/** @return true|\WP_Error */
	private function restore_generation( $value ) {
		update_option( DomainGeneration::OPTION, (string) $value, false );
		return hash_equals( (string) $value, (string) get_option( DomainGeneration::OPTION, '' ) )
			? true
			: new \WP_Error( 'certloom_domain_restore_generation_restore_failed', __( 'Certloom could not restore its previous data-generation marker.', 'certloom' ) );
	}

	/** @return bool */
	private function rows_equal( array $expected, array $actual ) {
		foreach ( $expected as $key => $value ) {
			if ( ! array_key_exists( $key, $actual ) ) {
				return false;
			}
			if ( null === $value ) {
				if ( null !== $actual[ $key ] ) {
					return false;
				}
				continue;
			}
			if ( (string) $value !== (string) $actual[ $key ] ) {
				return false;
			}
		}
		return true;
	}


	/** @return array<string,mixed>|\WP_Error */
	private function normalize_receipt( array $receipt ) {
		$mode = sanitize_key( (string) ( $receipt['mode'] ?? '' ) );
		if ( ! in_array( $mode, array( 'replace', 'merge' ), true ) ) {
			return new \WP_Error( 'certloom_domain_restore_receipt_invalid', __( 'The restore completion receipt has an invalid mode.', 'certloom' ) );
		}
		$count_keys = 'merge' === $mode
			? array( 'inserted', 'existing', 'skipped' )
			: DomainBackupRepository::entity_types();
		$counts = array();
		foreach ( $count_keys as $key ) {
			if ( ! isset( $receipt['counts'] ) || ! is_array( $receipt['counts'] ) || ! array_key_exists( $key, $receipt['counts'] ) ) {
				return new \WP_Error( 'certloom_domain_restore_receipt_invalid', __( 'The restore completion receipt is missing required count evidence.', 'certloom' ) );
			}
			$counts[ $key ] = absint( $receipt['counts'][ $key ] );
		}
		$records = absint( $receipt['records'] ?? 0 );
		if ( array_sum( $counts ) !== $records ) {
			return new \WP_Error( 'certloom_domain_restore_receipt_invalid', __( 'The restore completion receipt record total does not match its count evidence.', 'certloom' ) );
		}
		return array( 'mode' => $mode, 'records' => $records, 'counts' => $counts );
	}

	/** @return true|\WP_Error */
	private function save_completion_receipt( $owner_token, array $receipt ) {
		$owner_token = $this->owner_token( $owner_token );
		if ( is_wp_error( $owner_token ) ) {
			return $owner_token;
		}

		$now      = time();
		$receipts = get_option( self::RECEIPTS_OPTION, array() );
		$receipts = is_array( $receipts ) ? $receipts : array();
		foreach ( $receipts as $key => $stored ) {
			if ( ! is_array( $stored ) || ( $now - absint( $stored['committed_at'] ?? 0 ) ) > self::RECEIPT_TTL ) {
				unset( $receipts[ $key ] );
			}
		}
		$receipt = $this->normalize_receipt( $receipt );
		if ( is_wp_error( $receipt ) ) { return $receipt; }
		$receipt['committed_at'] = $now;
		$receipt['generation']   = $this->generation->current();
		$receipts[ $owner_token ] = $receipt;

		if ( count( $receipts ) > self::MAX_RECEIPTS ) {
			uasort( $receipts, static function ( $left, $right ) {
				return absint( $left['committed_at'] ?? 0 ) <=> absint( $right['committed_at'] ?? 0 );
			} );
			while ( count( $receipts ) > self::MAX_RECEIPTS ) {
				array_shift( $receipts );
			}
		}

		update_option( self::RECEIPTS_OPTION, $receipts, false );
		return $receipts === get_option( self::RECEIPTS_OPTION, array() )
			? true
			: new \WP_Error( 'certloom_domain_restore_receipt_failed', __( 'Certloom could not save the durable restore completion receipt exactly as committed.', 'certloom' ) );
	}

	/** @return true|\WP_Error */
	private function delete_completion_receipt( $owner_token ) {
		$receipts = get_option( self::RECEIPTS_OPTION, array() );
		if ( ! is_array( $receipts ) || ! isset( $receipts[ $owner_token ] ) ) {
			return true;
		}
		unset( $receipts[ $owner_token ] );
		if ( empty( $receipts ) ) {
			delete_option( self::RECEIPTS_OPTION );
			$stored = get_option( self::RECEIPTS_OPTION, null );
			return null === $stored
				? true
				: new \WP_Error( 'certloom_domain_restore_receipt_cleanup_failed', __( 'Certloom could not remove a rolled-back restore completion receipt safely.', 'certloom' ) );
		}
		update_option( self::RECEIPTS_OPTION, $receipts, false );
		return $receipts === get_option( self::RECEIPTS_OPTION, array() )
			? true
			: new \WP_Error( 'certloom_domain_restore_receipt_cleanup_failed', __( 'Certloom could not update restore completion receipts after rollback.', 'certloom' ) );
	}

	/** @return bool */
	private function save_build_marker( array $workspace ) {
		$value = array( 'owner_token' => $workspace['owner_token'], 'suffix' => $workspace['suffix'], 'updated_at' => time() );
		update_option( self::BUILD_OPTION, $value, false );
		return $value === get_option( self::BUILD_OPTION, array() );
	}

	/** @return true|\WP_Error */
	private function delete_build_marker( $owner_token ) {
		$current = get_option( self::BUILD_OPTION, array() );
		if ( ! is_array( $current ) || ! isset( $current['owner_token'] ) || ! hash_equals( (string) $owner_token, (string) $current['owner_token'] ) ) {
			return true;
		}
		delete_option( self::BUILD_OPTION );
		$after = get_option( self::BUILD_OPTION, null );
		if ( null === $after || ! is_array( $after ) || ! isset( $after['owner_token'] ) || ! hash_equals( (string) $owner_token, (string) $after['owner_token'] ) ) {
			return true;
		}
		return new \WP_Error( 'certloom_domain_restore_build_marker_cleanup_failed', __( 'Certloom could not remove its restore workspace marker safely.', 'certloom' ) );
	}

	/** @return true|\WP_Error */
	private function recover_stale_build() {
		$current = get_option( self::BUILD_OPTION, array() );
		if ( ! is_array( $current ) || empty( $current['owner_token'] ) || empty( $current['suffix'] ) ) {
			return true;
		}
		if ( ( time() - absint( $current['updated_at'] ?? 0 ) ) < self::BUILD_TTL ) {
			return true;
		}
		$workspace = array( 'owner_token' => (string) $current['owner_token'], 'suffix' => (string) $current['suffix'] );
		$valid = $this->validate_workspace( $workspace );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$cleaned = $this->drop_workspace_kinds( $workspace, array( 'stage', 'merge' ) );
		if ( is_wp_error( $cleaned ) ) {
			return $cleaned;
		}
		$maps = $this->restore_maps->clear( $workspace['owner_token'] );
		if ( is_wp_error( $maps ) ) {
			return $maps;
		}
		$released = $this->maintenance->release( $workspace['owner_token'] );
		if ( is_wp_error( $released ) ) {
			return $released;
		}
		$marker_deleted = $this->delete_build_marker( $workspace['owner_token'] );
		return is_wp_error( $marker_deleted ) ? $marker_deleted : true;
	}

	/** @return bool */
	private function save_journal( array $journal ) {
		update_option( self::JOURNAL_OPTION, $journal, false );
		return $journal === get_option( self::JOURNAL_OPTION, array() );
	}
}
