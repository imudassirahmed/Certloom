<?php
namespace Certloom\Infrastructure\Job;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use Certloom\Application\Contract\JobRepositoryInterface;
use Certloom\Application\Data\SafeJson;
use Certloom\Domain\Job\JobStates;
use Certloom\Domain\Job\JobTypes;
use Certloom\Infrastructure\Repository\EmailDeliveryRepository;
use Certloom\Infrastructure\Repository\DestinationDeliveryRepository;
use Certloom\Infrastructure\Database\SchemaManager;

/** Durable local persistence/locking for resumable Certloom jobs. */
final class JobRepository implements JobRepositoryInterface {
	/** @return string|\WP_Error */
	public function create( array $data ) {
		// Admit new work through the same InnoDB fence as credential commands.
		// Existing recovery jobs can still claim/save their own progress; new
		// issuance or delivery jobs cannot slip into a reviewed replacement.
		return ( new \Certloom\Infrastructure\Database\Transaction() )->run( function () use ( $data ) {
			return $this->create_row( $data );
		} );
	}

	private function create_row( array $data ) {
		global $wpdb;
		try {
			$job_key = bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'certloom_job_key_failed', __( 'Certloom could not generate a secure job identifier.', 'certloom' ) );
		}
		$payload = self::encode_json( $data['payload'] ?? array(), 'certloom_job_payload_invalid' );
		if ( is_wp_error( $payload ) ) { return $payload; }
		$now = current_time( 'mysql', true );
		$row = array(
			'job_key'          => $job_key,
			'job_type'         => sanitize_key( (string) ( $data['job_type'] ?? '' ) ),
			'status'           => JobStates::QUEUED,
			'owner_user_id'    => ! empty( $data['owner_user_id'] ) ? absint( $data['owner_user_id'] ) : null,
			'parent_job_key'   => self::nullable_job_key( $data['parent_job_key'] ?? null ),
			'phase'            => null,
			'progress_cursor'  => 0,
			'total'            => null === ( $data['total'] ?? null ) ? null : absint( $data['total'] ),
			'selection_max_id' => null === ( $data['selection_max_id'] ?? null ) ? null : absint( $data['selection_max_id'] ),
			'payload_json'     => $payload,
			'result_json'      => null,
			'error_json'       => null,
			'attempts'         => 0,
			'recovery_count'   => 0,
			'available_at'     => $now,
			'locked_by'        => null,
			'locked_until'     => null,
			'created_at'       => $now,
			'updated_at'       => $now,
			'finished_at'      => null,
		);
		if ( '' === $row['job_type'] ) {
			return new \WP_Error( 'certloom_job_type_required', __( 'A Certloom job type is required.', 'certloom' ) );
		}
		if ( is_wp_error( $row['parent_job_key'] ) ) {
			return $row['parent_job_key'];
		}
		$wpdb->last_error = '';
		$inserted = $wpdb->insert( SchemaManager::table( 'jobs' ), $row );
		return 1 !== $inserted || '' !== (string) $wpdb->last_error
			? new \WP_Error( 'certloom_job_create_failed', __( 'Certloom could not create the background job.', 'certloom' ) )
			: $job_key;
	}

	/** @return array<string,mixed>|null|\WP_Error */
	public function find( $job_key ) {
		global $wpdb;
		$job_key = self::job_key( $job_key );
		if ( is_wp_error( $job_key ) ) { return $job_key; }
		$wpdb->last_error = '';
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE job_key = %s', SchemaManager::table( 'jobs' ), $job_key ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_job_read_failed', __( 'Certloom could not read the background job.', 'certloom' ) );
		}
		return is_array( $row ) ? self::decode( $row ) : null;
	}

	/** @return array<string,mixed>|\WP_Error */
	public function claim( $job_key, $worker_token, $lock_seconds ) {
		global $wpdb;
		$job_key = self::job_key( $job_key );
		if ( is_wp_error( $job_key ) ) { return $job_key; }
		$now       = current_time( 'mysql', true );
		$lock_until = gmdate( 'Y-m-d H:i:s', time() + max( 30, absint( $lock_seconds ) ) );
		$wpdb->last_error = '';
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = %s, locked_by = %s, locked_until = %s, attempts = attempts + 1, updated_at = %s WHERE job_key = %s AND status = %s AND (available_at IS NULL OR available_at <= %s) AND (locked_until IS NULL OR locked_until < %s)",
				SchemaManager::table( 'jobs' ), JobStates::RUNNING, $worker_token, $lock_until, $now, $job_key, JobStates::QUEUED, $now, $now
			)
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_job_claim_failed', __( 'Certloom could not claim the background job.', 'certloom' ) );
		}
		if ( 1 !== (int) $updated ) {
			return new \WP_Error( 'certloom_job_not_claimable', __( 'That Certloom job is not currently available to this worker.', 'certloom' ) );
		}
		$job = $this->find( $job_key );
		return is_array( $job ) ? $job : new \WP_Error( 'certloom_job_claim_read_failed', __( 'Certloom claimed the job but could not reload its state.', 'certloom' ) );
	}


	/** @return array<int,array<string,mixed>>|\WP_Error */
	public function find_due_queued( $limit = 20 ) {
		global $wpdb;

		$limit = max( 1, min( 100, absint( $limit ) ) );
		$now   = current_time( 'mysql', true );
		$wpdb->last_error = '';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE status = %s AND (available_at IS NULL OR available_at <= %s) AND (locked_until IS NULL OR locked_until < %s) ORDER BY id ASC LIMIT %d',
				SchemaManager::table( 'jobs' ),
				JobStates::QUEUED,
				$now,
				$now,
				$limit
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
			return new \WP_Error( 'certloom_job_due_read_failed', __( 'Certloom could not inspect its queued background jobs.', 'certloom' ) );
		}
		$out = array();
		foreach ( $rows as $row ) {
			$decoded = self::decode( $row );
			if ( is_wp_error( $decoded ) ) { return $decoded; }
			$out[] = $decoded;
		}
		return $out;
	}

	/**
	 * Requeues expired worker leases so a PHP crash cannot strand a job forever.
	 *
	 * Handlers must be slice-idempotent because an expired worker may have
	 * completed external/file work immediately before its process terminated.
	 *
	 * @return int|\WP_Error Number of recovered jobs.
	 */
	public function recover_stale_running( $limit = 20 ) {
		global $wpdb;

		$limit = max( 1, min( 100, absint( $limit ) ) );
		$now   = current_time( 'mysql', true );
		$wpdb->last_error = '';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT job_key, job_type, payload_json, recovery_count FROM %i WHERE status = %s AND (locked_until IS NULL OR locked_until < %s) ORDER BY id ASC LIMIT %d',
				SchemaManager::table( 'jobs' ),
				JobStates::RUNNING,
				$now,
				$limit
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
			return new \WP_Error( 'certloom_job_stale_read_failed', __( 'Certloom could not inspect interrupted background jobs.', 'certloom' ) );
		}

		$handled = 0;
		foreach ( $rows as $row ) {
			$job_key        = (string) ( $row['job_key'] ?? '' );
			$job_type       = (string) ( $row['job_type'] ?? '' );
			$recovery_count = absint( $row['recovery_count'] ?? 0 );
			$wpdb->last_error = '';

			if ( in_array( $job_type, array( JobTypes::EMAIL_DELIVERY, JobTypes::CREDLY_PUBLISH ), true ) ) {
				$recovered = $this->recover_external_delivery( $job_key, $job_type, $now );
				if ( is_wp_error( $recovered ) ) { return $recovered; }
				$handled += $recovered;
				continue;
			}
			if ( $recovery_count >= 4 ) {
				$error = self::encode_json(
					array(
						'code'    => 'certloom_job_worker_recovery_exhausted',
						'message' => __( 'Certloom stopped this job after repeated interrupted worker leases. Review the job before retrying it.', 'certloom' ),
					),
					'certloom_job_error_invalid'
				);
				if ( is_wp_error( $error ) ) {
					return $error;
				}
				$updated = $wpdb->query(
					$wpdb->prepare(
						'UPDATE %i SET status = %s, recovery_count = recovery_count + 1, error_json = %s, locked_by = NULL, locked_until = NULL, updated_at = %s, finished_at = %s WHERE job_key = %s AND status = %s AND (locked_until IS NULL OR locked_until < %s)',
						SchemaManager::table( 'jobs' ),
						JobStates::FAILED,
						$error,
						$now,
						$now,
						$job_key,
						JobStates::RUNNING,
						$now
					)
				);
			} else {
				$updated = $wpdb->query(
					$wpdb->prepare(
						'UPDATE %i SET status = %s, recovery_count = recovery_count + 1, available_at = %s, locked_by = NULL, locked_until = NULL, updated_at = %s WHERE job_key = %s AND status = %s AND (locked_until IS NULL OR locked_until < %s)',
						SchemaManager::table( 'jobs' ),
						JobStates::QUEUED,
						$now,
						$now,
						$job_key,
						JobStates::RUNNING,
						$now
					)
				);
			}

			if ( '' !== (string) $wpdb->last_error ) {
				return new \WP_Error( 'certloom_job_stale_recovery_failed', __( 'Certloom could not recover an interrupted background job.', 'certloom' ) );
			}
			$handled += 1 === (int) $updated ? 1 : 0;
		}
		return $handled;
	}

	/** Recheck the expired lease under a lock; delivery and job close together. */
	private function recover_external_delivery( $job_key, $job_type, $now ) {
		return ( new \Certloom\Infrastructure\Database\Transaction() )->run( static function () use ( $job_key, $job_type, $now ) {
			global $wpdb;
			$wpdb->last_error = '';
			$job = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE job_key = %s FOR UPDATE', SchemaManager::table( 'jobs' ), $job_key ), ARRAY_A );
			if ( '' !== (string) $wpdb->last_error ) { return new \WP_Error( 'certloom_job_stale_read_failed', __( 'Certloom could not confirm the interrupted delivery lease.', 'certloom' ) ); }
			if ( ! is_array( $job ) || JobStates::RUNNING !== $job['status'] || $job_type !== $job['job_type'] || ( null !== $job['locked_until'] && $job['locked_until'] >= $now ) ) { return 0; }
			$payload = json_decode( (string) $job['payload_json'], true );
			$key = is_array( $payload ) ? (string) ( $payload['delivery_key'] ?? '' ) : '';
			$is_email = JobTypes::EMAIL_DELIVERY === $job_type;
			$type = $is_email ? 'email_deliveries' : 'destination_deliveries';
			$delivery = null;
			if ( 1 === preg_match( '/^[a-f0-9]{32}$/D', $key ) ) {
				$wpdb->last_error = '';
				$delivery = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE delivery_key = %s FOR UPDATE', SchemaManager::table( $type ), $key ), ARRAY_A );
				if ( '' !== (string) $wpdb->last_error ) { return new \WP_Error( 'certloom_job_stale_read_failed', __( 'Certloom could not confirm interrupted delivery evidence.', 'certloom' ) ); }
			}
			$accepted = is_array( $delivery ) && ( $is_email ? 'sent' : DestinationDeliveryRepository::STATUS_SUCCEEDED ) === $delivery['status'];
			if ( ! $accepted && is_array( $delivery ) ) {
				$evidence = array( 'recovery' => 'interrupted_worker' );
				if ( is_string( $delivery['attempt_token'] ) && 1 === preg_match( '/^[a-f0-9]{32}$/D', $delivery['attempt_token'] ) ) { $evidence['attempt_token_sha256'] = hash( 'sha256', $delivery['attempt_token'] ); }
				$repository = $is_email ? new EmailDeliveryRepository() : new DestinationDeliveryRepository();
				$recorded = $repository->mark_failed( $key, $is_email ? 'delivery_uncertain' : 'publication_uncertain', $evidence, true );
				if ( is_wp_error( $recorded ) ) { return $recorded; }
			}
			$result = $accepted ? self::encode_json( array( 'delivery_key' => $key, 'status' => $is_email ? 'sent' : 'succeeded', 'recovered_after_persisted_acceptance' => true ), 'certloom_job_result_invalid' ) : null;
			$error = $accepted ? null : self::encode_json( array( 'code' => $is_email ? 'certloom_email_delivery_uncertain' : 'certloom_credly_publish_uncertain', 'message' => __( 'This external delivery worker was interrupted. Certloom will not replay it automatically; review the delivery before sending again.', 'certloom' ) ), 'certloom_job_error_invalid' );
			if ( is_wp_error( $result ) ) { return $result; }
			if ( is_wp_error( $error ) ) { return $error; }
			$marked = ( new \Certloom\Infrastructure\Data\DomainGeneration() )->mark_before_mutation();
			if ( is_wp_error( $marked ) ) { return $marked; }
			$wpdb->last_error = '';
			$updated = $wpdb->update( SchemaManager::table( 'jobs' ), array( 'status' => $accepted ? JobStates::SUCCEEDED : JobStates::FAILED, 'recovery_count' => (int) $job['recovery_count'] + 1, 'result_json' => $result, 'error_json' => $error, 'locked_by' => null, 'locked_until' => null, 'updated_at' => $now, 'finished_at' => $now ), array( 'job_key' => $job_key, 'status' => JobStates::RUNNING, 'locked_by' => $job['locked_by'], 'locked_until' => $job['locked_until'] ) );
			return 1 === $updated && '' === (string) $wpdb->last_error ? 1 : new \WP_Error( 'certloom_job_stale_recovery_failed', __( 'Certloom could not close the interrupted delivery job safely.', 'certloom' ) );
		} );
	}

	/** @return true|\WP_Error */
	public function save_progress( $job_key, $worker_token, array $progress ) {
		global $wpdb;
		$job_key = self::job_key( $job_key );
		if ( is_wp_error( $job_key ) ) { return $job_key; }
		$row = array(
			'progress_cursor' => absint( $progress['cursor'] ?? 0 ),
			'updated_at'   => current_time( 'mysql', true ),
			'locked_until' => gmdate( 'Y-m-d H:i:s', time() + max( 30, absint( $progress['lock_seconds'] ?? 120 ) ) ),
		);
		if ( array_key_exists( 'phase', $progress ) ) {
			$row['phase'] = null === $progress['phase'] || '' === (string) $progress['phase'] ? null : sanitize_key( (string) $progress['phase'] );
		}
		if ( array_key_exists( 'result', $progress ) ) {
			$encoded = self::encode_json( $progress['result'], 'certloom_job_result_invalid' );
			if ( is_wp_error( $encoded ) ) { return $encoded; }
			$row['result_json'] = $encoded;
		}
		$wpdb->last_error = '';
		$updated = $wpdb->update( SchemaManager::table( 'jobs' ), $row, array( 'job_key' => $job_key, 'status' => JobStates::RUNNING, 'locked_by' => $worker_token ) );
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_job_progress_failed', __( 'Certloom could not save job progress.', 'certloom' ) );
		}
		return 1 === (int) $updated || 0 === (int) $updated
			? true
			: new \WP_Error( 'certloom_job_progress_conflict', __( 'The Certloom job is no longer owned by this worker.', 'certloom' ) );
	}

	/** @return true|\WP_Error */
	public function transition( $job_key, $expected_state, $new_state, array $changes = array(), $worker_token = null ) {
		global $wpdb;
		$job_key = self::job_key( $job_key );
		if ( is_wp_error( $job_key ) ) { return $job_key; }
		$row = array(
			'status'       => (string) $new_state,
			'updated_at'   => current_time( 'mysql', true ),
			'locked_by'    => null,
			'locked_until' => null,
		);
		if ( JobStates::QUEUED === $new_state ) {
			$row['available_at'] = current_time( 'mysql', true );
			$row['finished_at']  = null;
		} elseif ( JobStates::is_terminal( $new_state ) ) {
			$row['finished_at'] = current_time( 'mysql', true );
		}
		if ( array_key_exists( 'result', $changes ) ) {
			$encoded = self::encode_json( $changes['result'], 'certloom_job_result_invalid' );
			if ( is_wp_error( $encoded ) ) { return $encoded; }
			$row['result_json'] = $encoded;
		}
		if ( array_key_exists( 'recovery_count', $changes ) ) {
			$row['recovery_count'] = absint( $changes['recovery_count'] );
		}
		if ( array_key_exists( 'error', $changes ) ) {
			if ( null === $changes['error'] ) {
				$row['error_json'] = null;
			} else {
				$encoded = self::encode_json( $changes['error'], 'certloom_job_error_invalid' );
				if ( is_wp_error( $encoded ) ) { return $encoded; }
				$row['error_json'] = $encoded;
			}
		}

		$where = array( 'job_key' => $job_key, 'status' => (string) $expected_state );
		if ( null !== $worker_token ) { $where['locked_by'] = $worker_token; }
		$wpdb->last_error = '';
		$updated = $wpdb->update( SchemaManager::table( 'jobs' ), $row, $where );
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_job_transition_failed', __( 'Certloom could not update the background job state.', 'certloom' ) );
		}
		return 1 === (int) $updated
			? true
			: new \WP_Error( 'certloom_job_transition_conflict', __( 'The Certloom job changed before this transition could be applied.', 'certloom' ) );
	}


	/**
	 * Returns recent jobs for protected admin/status surfaces.
	 *
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	public function recent( $limit = 50 ) {
		global $wpdb;

		$limit = max( 1, min( 100, absint( $limit ) ) );
		$wpdb->last_error = '';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i ORDER BY id DESC LIMIT %d',
				SchemaManager::table( 'jobs' ),
				$limit
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
			return new \WP_Error( 'certloom_job_recent_read_failed', __( 'Certloom could not read recent background jobs.', 'certloom' ) );
		}
		$out = array();
		foreach ( $rows as $row ) {
			$decoded = self::decode( $row );
			if ( is_wp_error( $decoded ) ) { return $decoded; }
			$out[] = $decoded;
		}
		return $out;
	}


	/**
	 * Returns one bounded page of protected admin job history.
	 *
	 * @return array{items:array<int,array<string,mixed>>,total:int,page:int,per_page:int}|\WP_Error
	 */
	public function list_page( $page = 1, $per_page = 50, array $types = array() ) {
		global $wpdb;
		if ( count( $types ) > count( \Certloom\Domain\Job\JobTypes::values() ) || array_diff( $types, \Certloom\Domain\Job\JobTypes::values() ) ) { return new \WP_Error( 'certloom_job_history_filter_invalid', __( 'Choose a supported background job type.', 'certloom' ) ); }
		$types = array_values( array_unique( $types ) );
		$page = max( 1, absint( $page ) );
		$per_page = max( 1, min( 100, absint( $per_page ) ) );
		$offset = ( $page - 1 ) * $per_page;
		$table = SchemaManager::table( 'jobs' );
		$where = $types ? ' WHERE job_type IN (' . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ')' : '';
		$wpdb->last_error = '';
		$total = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i' . $where, array_merge( array( $table ), $types ) ) );
		if ( '' !== (string) $wpdb->last_error ) { return new \WP_Error( 'certloom_job_recent_read_failed', __( 'Certloom could not read background job history.', 'certloom' ) ); }
		$wpdb->last_error = '';
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i' . $where . ' ORDER BY id DESC LIMIT %d OFFSET %d', array_merge( array( $table ), $types, array( $per_page, $offset ) ) ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) { return new \WP_Error( 'certloom_job_recent_read_failed', __( 'Certloom could not read background job history.', 'certloom' ) ); }
		$out = array();
		foreach ( $rows as $row ) { $decoded = self::decode( $row ); if ( is_wp_error( $decoded ) ) { return $decoded; } $out[] = $decoded; }
		return array( 'items' => $out, 'total' => absint( $total ), 'page' => $page, 'per_page' => $per_page );
	}

	/**
	 * Finds bounded terminal jobs eligible for retention cleanup.
	 *
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	public function find_terminal_before( $before_mysql, $limit = 25 ) {
		global $wpdb;

		$before_mysql = trim( (string) $before_mysql );
		$limit        = max( 1, min( 100, absint( $limit ) ) );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $before_mysql ) ) {
			return new \WP_Error( 'certloom_job_retention_date_invalid', __( 'The Certloom job-retention boundary is invalid.', 'certloom' ) );
		}

		$wpdb->last_error = '';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE status IN (%s,%s,%s,%s) AND finished_at IS NOT NULL AND finished_at < %s ORDER BY id ASC LIMIT %d',
				SchemaManager::table( 'jobs' ),
				JobStates::SUCCEEDED,
				JobStates::PARTIALLY_SUCCEEDED,
				JobStates::FAILED,
				JobStates::CANCELLED,
				$before_mysql,
				$limit
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
			return new \WP_Error( 'certloom_job_retention_read_failed', __( 'Certloom could not inspect finished background jobs for cleanup.', 'certloom' ) );
		}
		$out = array();
		foreach ( $rows as $row ) {
			$decoded = self::decode( $row );
			if ( is_wp_error( $decoded ) ) { return $decoded; }
			$out[] = $decoded;
		}
		return $out;
	}

	/** @return bool|\WP_Error */
	public function has_children( $job_key ) {
		global $wpdb;

		$job_key = self::job_key( $job_key );
		if ( is_wp_error( $job_key ) ) {
			return $job_key;
		}
		$wpdb->last_error = '';
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE parent_job_key = %s',
				SchemaManager::table( 'jobs' ),
				$job_key
			)
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_job_child_read_failed', __( 'Certloom could not inspect dependent background jobs.', 'certloom' ) );
		}
		return (int) $count > 0;
	}

	/**
	 * Deletes one finished job after its dependent operational state is cleaned.
	 *
	 * @return true|\WP_Error
	 */
	public function delete_terminal( $job_key ) {
		global $wpdb;

		$job_key = self::job_key( $job_key );
		if ( is_wp_error( $job_key ) ) {
			return $job_key;
		}
		$wpdb->last_error = '';
		$deleted = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE job_key = %s AND status IN (%s,%s,%s,%s)',
				SchemaManager::table( 'jobs' ),
				$job_key,
				JobStates::SUCCEEDED,
				JobStates::PARTIALLY_SUCCEEDED,
				JobStates::FAILED,
				JobStates::CANCELLED
			)
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_job_retention_delete_failed', __( 'Certloom could not remove an expired finished background job.', 'certloom' ) );
		}
		return false !== $deleted;
	}


	/** @return string|\WP_Error */
	private static function job_key( $value ) {
		$value = strtolower( trim( (string) $value ) );
		return preg_match( '/^[a-f0-9]{32}$/', $value )
			? $value
			: new \WP_Error( 'certloom_job_key_invalid', __( 'The Certloom job identifier is invalid.', 'certloom' ) );
	}

	/** @return string|null|\WP_Error */
	private static function nullable_job_key( $value ) {
		if ( null === $value || '' === trim( (string) $value ) ) {
			return null;
		}
		return self::job_key( $value );
	}

	/** @return string|\WP_Error */
	private static function encode_json( $value, $code ) {
		$json = wp_json_encode( $value );
		return false === $json
			? new \WP_Error( $code, __( 'Certloom could not encode the job state safely.', 'certloom' ) )
			: $json;
	}

	/** @return array<string,mixed>|\WP_Error */
	private static function decode( array $row ) {
		$persisted_cursor = array_key_exists( 'progress_cursor', $row ) ? $row['progress_cursor'] : ( $row['cursor'] ?? null );
		$row['cursor'] = null === $persisted_cursor ? null : absint( $persisted_cursor );
		unset( $row['progress_cursor'] );
		foreach ( array( 'id', 'owner_user_id', 'total', 'selection_max_id', 'attempts', 'recovery_count' ) as $key ) {
			$row[ $key ] = null === ( $row[ $key ] ?? null ) ? null : absint( $row[ $key ] );
		}
		foreach ( array( 'payload_json' => 'payload', 'result_json' => 'result', 'error_json' => 'error' ) as $source => $target ) {
			$decoded = ( new SafeJson() )->decode_array( $row[ $source ] ?? null, 'certloom_job_json_corrupt', __( 'Stored background job state is invalid JSON.', 'certloom' ) );
			if ( is_wp_error( $decoded ) ) { return $decoded; }
			$row[ $target ] = $decoded;
			unset( $row[ $source ] );
		}
		return $row;
	}
}
