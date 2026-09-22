<?php
namespace Certloom\Infrastructure\Repository;

use Certloom\Application\Data\SafeJson;
use Certloom\Infrastructure\Data\DomainGeneration;
use Certloom\Infrastructure\Database\SchemaManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Signer profiles plus deterministic global/issuer/program/batch assignment hierarchy. */
final class SignerRepository {
	const SCOPES = array( 'global', 'issuer', 'program', 'batch' );
	const ROLES  = array( 'signer', 'instructor' );

	/** @param array<string,mixed> $data @return int|\WP_Error */
	public function create( array $data ) {
		$row = $this->normalize_profile( $data );
		if ( is_wp_error( $row ) ) {
			return $row;
		}

		$now               = current_time( 'mysql', true );
		$row['created_at'] = $now;
		$row['updated_at'] = $now;

		$generation = ( new DomainGeneration() )->mark_before_mutation();
		if ( is_wp_error( $generation ) ) {
			return $generation;
		}

		global $wpdb;
		$wpdb->last_error = '';
		$result            = $wpdb->insert( SchemaManager::table( 'signers' ), $row );
		if ( false === $result || '' !== (string) $wpdb->last_error ) {
			if ( false !== stripos( (string) $wpdb->last_error, 'duplicate' ) ) {
				return new \WP_Error( 'certloom_signer_slug_duplicate', __( 'That signer key is already in use.', 'certloom' ) );
			}
			return new \WP_Error( 'certloom_signer_create_failed', __( 'Certloom could not create the signer profile.', 'certloom' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/** @param int $id @param array<string,mixed> $data @return true|\WP_Error */
	public function update( $id, array $data ) {
		$id = absint( $id );
		if ( ! $id ) {
			return new \WP_Error( 'certloom_signer_id_required', __( 'A valid signer is required.', 'certloom' ) );
		}

		$existing = $this->find( $id );
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}
		if ( ! is_array( $existing ) ) {
			return new \WP_Error( 'certloom_signer_missing', __( 'The signer no longer exists.', 'certloom' ) );
		}

		$row = $this->normalize_profile( array_merge( $existing, $data ) );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$row['updated_at'] = current_time( 'mysql', true );

		$generation = ( new DomainGeneration() )->mark_before_mutation();
		if ( is_wp_error( $generation ) ) {
			return $generation;
		}

		global $wpdb;
		$wpdb->last_error = '';
		$result            = $wpdb->update( SchemaManager::table( 'signers' ), $row, array( 'id' => $id ) );
		if ( false === $result || '' !== (string) $wpdb->last_error ) {
			if ( false !== stripos( (string) $wpdb->last_error, 'duplicate' ) ) {
				return new \WP_Error( 'certloom_signer_slug_duplicate', __( 'That signer key is already in use.', 'certloom' ) );
			}
			return new \WP_Error( 'certloom_signer_update_failed', __( 'Certloom could not update the signer profile.', 'certloom' ) );
		}

		return true;
	}

	/** @return array<string,mixed>|null|\WP_Error */
	public function find( $id ) {
		global $wpdb;
		$wpdb->last_error = '';
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', SchemaManager::table( 'signers' ), absint( $id ) ),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_signer_read_failed', __( 'Certloom could not read the signer profile.', 'certloom' ) );
		}
		return is_array( $row ) ? $this->decode( $row ) : null;
	}

	/** @return array{items:array<int,array<string,mixed>>,total:int}|\WP_Error */
	public function list_page( $search = '', $page = 1, $per_page = 20, $status = 'all' ) {
		global $wpdb;
		$page     = max( 1, absint( $page ) );
		$per_page = max( 1, min( 100, absint( $per_page ) ) );
		$offset   = ( $page - 1 ) * $per_page;
		$search   = trim( (string) $search );
		$status   = in_array( $status, array( 'all', 'active', 'inactive' ), true ) ? $status : 'all';
		$where    = array();
		$args     = array();

		if ( '' !== $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = '(s.display_name LIKE %s OR s.slug LIKE %s OR s.title LIKE %s OR s.email LIKE %s)';
			$args    = array( $like, $like, $like, $like );
		}
		if ( 'active' === $status ) {
			$where[] = 's.active=1';
		} elseif ( 'inactive' === $status ) {
			$where[] = 's.active=0';
		}
		$where_sql = $where ? ' WHERE ' . implode( ' AND ', $where ) : '';

		$s = SchemaManager::table( 'signers' );
		$a = SchemaManager::table( 'signer_assignments' );
		$wpdb->last_error = '';
		$total = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i s' . $where_sql, array_merge( array( $s ), $args ) )
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_signer_list_failed', __( 'Certloom could not load signer profiles.', 'certloom' ) );
		}

		$wpdb->last_error = '';
		$items = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT s.*, (SELECT COUNT(*) FROM %i a WHERE a.signer_id=s.id) assignment_count FROM %i s' . $where_sql . ' ORDER BY s.id DESC LIMIT %d OFFSET %d',
				array_merge( array( $a, $s ), $args, array( $per_page, $offset ) )
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error || ! is_array( $items ) ) {
			return new \WP_Error( 'certloom_signer_list_failed', __( 'Certloom could not load signer profiles.', 'certloom' ) );
		}

		foreach ( $items as &$item ) {
			$decoded = $this->decode( $item );
			if ( is_wp_error( $decoded ) ) {
				return $decoded;
			}
			$item                     = $decoded;
			$item['assignment_count'] = absint( $item['assignment_count'] ?? 0 );
		}
		unset( $item );

		return array( 'items' => $items, 'total' => $total );
	}

	/** @return array<string,int>|\WP_Error */
	public function summary() {
		global $wpdb;
		$s = SchemaManager::table( 'signers' );
		$a = SchemaManager::table( 'signer_assignments' );

		$wpdb->last_error = '';
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT COUNT(*) total, SUM(CASE WHEN active=1 THEN 1 ELSE 0 END) active FROM %i', $s ),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error || ! is_array( $row ) ) {
			return new \WP_Error( 'certloom_signer_summary_failed', __( 'Certloom could not summarize signer profiles.', 'certloom' ) );
		}

		$wpdb->last_error = '';
		$assignments = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE active=1', $a ) );
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_signer_summary_failed', __( 'Certloom could not summarize signer profiles.', 'certloom' ) );
		}

		return array(
			'total'       => absint( $row['total'] ?? 0 ),
			'active'      => absint( $row['active'] ?? 0 ),
			'assignments' => absint( $assignments ),
		);
	}

	/** @return int|\WP_Error */
	public function add_assignment( $signer_id, $scope_type, $scope_id, $role_type = 'signer', $priority = 100 ) {
		$signer_id  = absint( $signer_id );
		$scope_type = sanitize_key( (string) $scope_type );
		$role_type  = sanitize_key( (string) $role_type );
		$scope_id   = absint( $scope_id );
		$priority   = min( 1000, absint( $priority ) );
		if ( ! $signer_id ) {
			return new \WP_Error( 'certloom_signer_assignment_signer_missing', __( 'The signer profile does not exist.', 'certloom' ) );
		}

		$signer = $this->find( $signer_id );
		if ( is_wp_error( $signer ) ) {
			return $signer;
		}
		if ( ! is_array( $signer ) ) {
			return new \WP_Error( 'certloom_signer_assignment_signer_missing', __( 'The signer profile does not exist.', 'certloom' ) );
		}
		if ( ! in_array( $scope_type, self::SCOPES, true ) || ! in_array( $role_type, self::ROLES, true ) ) {
			return new \WP_Error( 'certloom_signer_assignment_invalid', __( 'Choose a valid signer scope and role.', 'certloom' ) );
		}

		if ( 'global' === $scope_type ) {
			$scope_id = 0;
		} else {
			if ( ! $scope_id ) {
				return new \WP_Error( 'certloom_signer_assignment_scope_missing', __( 'The selected signer scope no longer exists.', 'certloom' ) );
			}
			$scope_exists = $this->scope_exists( $scope_type, $scope_id );
			if ( is_wp_error( $scope_exists ) ) {
				return $scope_exists;
			}
			if ( ! $scope_exists ) {
				return new \WP_Error( 'certloom_signer_assignment_scope_missing', __( 'The selected signer scope no longer exists.', 'certloom' ) );
			}
		}

		$duplicate = $this->assignment_exists( $signer_id, $scope_type, $scope_id, $role_type );
		if ( is_wp_error( $duplicate ) ) {
			return $duplicate;
		}
		if ( $duplicate ) {
			return new \WP_Error( 'certloom_signer_assignment_duplicate', __( 'That signer already has the same scope and role assignment.', 'certloom' ) );
		}

		$now        = current_time( 'mysql', true );
		$generation = ( new DomainGeneration() )->mark_before_mutation();
		if ( is_wp_error( $generation ) ) {
			return $generation;
		}

		global $wpdb;
		$wpdb->last_error = '';
		$result = $wpdb->insert(
			SchemaManager::table( 'signer_assignments' ),
			array(
				'signer_id'  => $signer_id,
				'scope_type' => $scope_type,
				'scope_id'   => $scope_id,
				'role_type'  => $role_type,
				'priority'   => $priority,
				'active'     => 1,
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		if ( false === $result || '' !== (string) $wpdb->last_error ) {
			if ( false !== stripos( (string) $wpdb->last_error, 'duplicate' ) ) {
				return new \WP_Error( 'certloom_signer_assignment_duplicate', __( 'That signer already has the same scope and role assignment.', 'certloom' ) );
			}
			return new \WP_Error( 'certloom_signer_assignment_create_failed', __( 'Certloom could not create the signer assignment.', 'certloom' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Idempotently ensures one assignment exists. Used by legacy compatibility
	 * projections (for example Batch.instructor_signer_id) so signer_assignments
	 * remains the canonical relationship authority without manufacturing duplicates.
	 *
	 * @return int|\WP_Error Assignment id.
	 */
	public function ensure_assignment( $signer_id, $scope_type, $scope_id, $role_type = 'signer', $priority = 100 ) {
		$signer_id = absint( $signer_id );
		$scope_type = sanitize_key( (string) $scope_type );
		$scope_id = absint( $scope_id );
		$role_type = sanitize_key( (string) $role_type );
		global $wpdb;
		$wpdb->last_error = '';
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE signer_id=%d AND scope_type=%s AND scope_id=%d AND role_type=%s LIMIT 1',
				SchemaManager::table( 'signer_assignments' ), $signer_id, $scope_type, $scope_id, $role_type
			)
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_signer_assignment_read_failed', __( 'Certloom could not validate the signer assignment.', 'certloom' ) );
		}
		if ( $existing ) { return absint( $existing ); }
		return $this->add_assignment( $signer_id, $scope_type, $scope_id, $role_type, $priority );
	}

	/**
	 * Synchronizes the compatibility projection for a single primary scope/role
	 * assignment. Priority zero is reserved for this projection; additional
	 * manually managed signers at other priorities remain untouched.
	 *
	 * Passing signer id zero removes only the priority-zero projection.
	 *
	 * @return true|\WP_Error
	 */
	public function sync_primary_assignment( $signer_id, $scope_type, $scope_id, $role_type = 'signer' ) {
		$signer_id  = absint( $signer_id );
		$scope_type = sanitize_key( (string) $scope_type );
		$scope_id   = absint( $scope_id );
		$role_type  = sanitize_key( (string) $role_type );

		if ( ! in_array( $scope_type, self::SCOPES, true ) || ! in_array( $role_type, self::ROLES, true ) ) {
			return new \WP_Error( 'certloom_signer_assignment_invalid', __( 'Choose a valid signer scope and role.', 'certloom' ) );
		}
		if ( 'global' === $scope_type ) {
			$scope_id = 0;
		} elseif ( ! $scope_id ) {
			return new \WP_Error( 'certloom_signer_assignment_scope_missing', __( 'The selected signer scope no longer exists.', 'certloom' ) );
		} else {
			$scope_exists = $this->scope_exists( $scope_type, $scope_id );
			if ( is_wp_error( $scope_exists ) ) { return $scope_exists; }
			if ( ! $scope_exists ) {
				return new \WP_Error( 'certloom_signer_assignment_scope_missing', __( 'The selected signer scope no longer exists.', 'certloom' ) );
			}
		}

		if ( $signer_id ) {
			$signer = $this->find( $signer_id );
			if ( is_wp_error( $signer ) ) { return $signer; }
			if ( ! is_array( $signer ) ) {
				return new \WP_Error( 'certloom_signer_assignment_signer_missing', __( 'The signer profile does not exist.', 'certloom' ) );
			}
		}

		$generation = ( new DomainGeneration() )->mark_before_mutation();
		if ( is_wp_error( $generation ) ) { return $generation; }

		global $wpdb;
		$table = SchemaManager::table( 'signer_assignments' );
		$wpdb->last_error = '';

		// Remove only the projection slot. Other instructor/signature assignments
		// remain independent and keep their authored priority.
		if ( $signer_id ) {
			$result = $wpdb->query(
				$wpdb->prepare(
					'DELETE FROM %i WHERE scope_type=%s AND scope_id=%d AND role_type=%s AND priority=0 AND signer_id<>%d',
					$table, $scope_type, $scope_id, $role_type, $signer_id
				)
			);
		} else {
			$result = $wpdb->query(
				$wpdb->prepare(
					'DELETE FROM %i WHERE scope_type=%s AND scope_id=%d AND role_type=%s AND priority=0',
					$table, $scope_type, $scope_id, $role_type
				)
			);
		}
		if ( false === $result || '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_signer_assignment_delete_failed', __( 'Certloom could not synchronize the signer assignment.', 'certloom' ) );
		}
		if ( ! $signer_id ) { return true; }

		$wpdb->last_error = '';
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE signer_id=%d AND scope_type=%s AND scope_id=%d AND role_type=%s LIMIT 1',
				$table, $signer_id, $scope_type, $scope_id, $role_type
			)
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_signer_assignment_read_failed', __( 'Certloom could not validate the signer assignment.', 'certloom' ) );
		}

		$now = current_time( 'mysql', true );
		if ( $existing ) {
			$wpdb->last_error = '';
			$updated = $wpdb->update(
				$table,
				array( 'priority' => 0, 'active' => 1, 'updated_at' => $now ),
				array( 'id' => absint( $existing ) )
			);
			if ( false === $updated || '' !== (string) $wpdb->last_error ) {
				return new \WP_Error( 'certloom_signer_assignment_update_failed', __( 'Certloom could not synchronize the signer assignment.', 'certloom' ) );
			}
			return true;
		}

		$wpdb->last_error = '';
		$inserted = $wpdb->insert(
			$table,
			array(
				'signer_id' => $signer_id,
				'scope_type' => $scope_type,
				'scope_id' => $scope_id,
				'role_type' => $role_type,
				'priority' => 0,
				'active' => 1,
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		if ( false === $inserted || '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_signer_assignment_create_failed', __( 'Certloom could not synchronize the signer assignment.', 'certloom' ) );
		}
		return true;
	}

	/**
	 * Converts a historical free-text instructor label into one reusable Signer.
	 * Exact-name reuse is deliberate: legacy labels did not carry another stable
	 * identity. New records should select a Signer profile explicitly.
	 *
	 * @return int|\WP_Error
	 */
	public function find_or_create_lightweight_instructor( $display_name ) {
		$display_name = trim( (string) $display_name );
		if ( '' === $display_name || $this->length( $display_name ) > 255 ) {
			return new \WP_Error( 'certloom_signer_name_invalid', __( 'Instructor name is required and must be 255 characters or fewer.', 'certloom' ) );
		}
		global $wpdb;
		$wpdb->last_error = '';
		$existing = $wpdb->get_var(
			$wpdb->prepare( 'SELECT id FROM %i WHERE display_name=%s ORDER BY active DESC,id ASC LIMIT 1', SchemaManager::table( 'signers' ), $display_name )
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_signer_read_failed', __( 'Certloom could not search signer profiles.', 'certloom' ) );
		}
		if ( $existing ) { return absint( $existing ); }

		$base = sanitize_key( sanitize_title( $display_name ) );
		if ( '' === $base ) { $base = 'instructor'; }
		$base = substr( $base, 0, 170 );
		for ( $attempt = 0; $attempt < 25; $attempt++ ) {
			$slug = 0 === $attempt ? $base : $base . '-' . ( $attempt + 1 );
			$created = $this->create(
				array(
					'display_name' => $display_name,
					'slug' => $slug,
					'active' => 1,
					'metadata' => array( 'legacy_lightweight_instructor' => true ),
				)
			);
			if ( ! is_wp_error( $created ) ) { return absint( $created ); }
			if ( 'certloom_signer_slug_duplicate' !== $created->get_error_code() ) { return $created; }
		}
		return new \WP_Error( 'certloom_signer_slug_duplicate', __( 'Certloom could not allocate a unique signer key for the legacy instructor.', 'certloom' ) );
	}

	/** @return true|\WP_Error */
	public function remove_assignment( $signer_id, $assignment_id ) {
		$signer_id    = absint( $signer_id );
		$assignment_id = absint( $assignment_id );
		if ( ! $signer_id || ! $assignment_id ) {
			return new \WP_Error( 'certloom_signer_assignment_invalid', __( 'Choose a valid signer assignment.', 'certloom' ) );
		}

		$exists = $this->assignment_id_exists( $signer_id, $assignment_id );
		if ( is_wp_error( $exists ) ) {
			return $exists;
		}
		if ( ! $exists ) {
			return true;
		}

		$generation = ( new DomainGeneration() )->mark_before_mutation();
		if ( is_wp_error( $generation ) ) {
			return $generation;
		}

		global $wpdb;
		$wpdb->last_error = '';
		$result            = $wpdb->delete( SchemaManager::table( 'signer_assignments' ), array( 'id' => $assignment_id, 'signer_id' => $signer_id ) );
		return false === $result || '' !== (string) $wpdb->last_error
			? new \WP_Error( 'certloom_signer_assignment_delete_failed', __( 'Certloom could not remove the signer assignment.', 'certloom' ) )
			: true;
	}

	/** @return array<int,array<string,mixed>>|\WP_Error */
	public function assignments( $signer_id ) {
		global $wpdb;
		$wpdb->last_error = '';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE signer_id=%d ORDER BY priority ASC,id ASC',
				SchemaManager::table( 'signer_assignments' ),
				absint( $signer_id )
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
			return new \WP_Error( 'certloom_signer_assignments_read_failed', __( 'Certloom could not load signer assignments.', 'certloom' ) );
		}

		foreach ( $rows as &$row ) {
			$row['id']        = absint( $row['id'] );
			$row['signer_id'] = absint( $row['signer_id'] );
			$row['scope_id']  = absint( $row['scope_id'] );
			$row['priority']  = absint( $row['priority'] );
			$row['active']    = ! empty( $row['active'] );
			$label            = $this->scope_label( $row['scope_type'], $row['scope_id'] );
			if ( is_wp_error( $label ) ) {
				return $label;
			}
			$row['scope_label'] = $label;
		}
		unset( $row );

		return $rows;
	}

	/**
	 * Resolves active signer candidates by most-specific scope, then priority.
	 * The supplied hierarchy is normalized first so contradictory Batch/Program/
	 * Issuer combinations cannot accidentally mix signer policies.
	 *
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	public function resolve_hierarchy( $issuer_id = 0, $program_id = 0, $batch_id = 0, $role_type = 'signer' ) {
		$role_type = sanitize_key( (string) $role_type );
		if ( ! in_array( $role_type, self::ROLES, true ) ) {
			return new \WP_Error( 'certloom_signer_role_invalid', __( 'Choose a valid signer role.', 'certloom' ) );
		}

		$context = $this->normalize_context( $issuer_id, $program_id, $batch_id );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		$issuer_id  = $context['issuer_id'];
		$program_id = $context['program_id'];
		$batch_id   = $context['batch_id'];

		$conditions = array( "(a.scope_type='global' AND a.scope_id=0)" );
		$args       = array();
		if ( $issuer_id ) {
			$conditions[] = "(a.scope_type='issuer' AND a.scope_id=%d)";
			$args[]       = $issuer_id;
		}
		if ( $program_id ) {
			$conditions[] = "(a.scope_type='program' AND a.scope_id=%d)";
			$args[]       = $program_id;
		}
		if ( $batch_id ) {
			$conditions[] = "(a.scope_type='batch' AND a.scope_id=%d)";
			$args[]       = $batch_id;
		}

		global $wpdb;
		$a   = SchemaManager::table( 'signer_assignments' );
		$s   = SchemaManager::table( 'signers' );
		$sql = 'SELECT a.*, s.display_name, s.slug, s.title, s.email, s.signature_attachment_id, s.updated_at AS signer_updated_at FROM %i a INNER JOIN %i s ON s.id=a.signer_id WHERE a.active=1 AND s.active=1 AND a.role_type=%s AND (' . implode( ' OR ', $conditions ) . ") ORDER BY CASE a.scope_type WHEN 'batch' THEN 1 WHEN 'program' THEN 2 WHEN 'issuer' THEN 3 ELSE 4 END, a.priority ASC, a.id ASC";
		$wpdb->last_error = '';
		$rows = $wpdb->get_results(
			$wpdb->prepare( $sql, array_merge( array( $a, $s, $role_type ), $args ) ),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
			return new \WP_Error( 'certloom_signer_hierarchy_read_failed', __( 'Certloom could not resolve the signer hierarchy.', 'certloom' ) );
		}

		foreach ( $rows as &$row ) {
			$row['id']                      = absint( $row['id'] ?? 0 );
			$row['signer_id']               = absint( $row['signer_id'] ?? 0 );
			$row['scope_id']                = absint( $row['scope_id'] ?? 0 );
			$row['priority']                = absint( $row['priority'] ?? 0 );
			$row['signature_attachment_id'] = ! empty( $row['signature_attachment_id'] ) ? absint( $row['signature_attachment_id'] ) : null;
		}
		unset( $row );

		return $rows;
	}

	/** @return array{issuer_id:int,program_id:int,batch_id:int}|\WP_Error */
	private function normalize_context( $issuer_id, $program_id, $batch_id ) {
		$issuer_id  = absint( $issuer_id );
		$program_id = absint( $program_id );
		$batch_id   = absint( $batch_id );

		global $wpdb;
		if ( $batch_id ) {
			$wpdb->last_error = '';
			$batch_program = $wpdb->get_var(
				$wpdb->prepare( 'SELECT program_id FROM %i WHERE id=%d LIMIT 1', SchemaManager::table( 'batches' ), $batch_id )
			);
			if ( '' !== (string) $wpdb->last_error ) {
				return new \WP_Error( 'certloom_signer_context_read_failed', __( 'Certloom could not validate the signer hierarchy context.', 'certloom' ) );
			}
			if ( ! $batch_program ) {
				return new \WP_Error( 'certloom_signer_context_batch_missing', __( 'The selected signer Batch no longer exists.', 'certloom' ) );
			}
			$batch_program = absint( $batch_program );
			if ( $program_id && $program_id !== $batch_program ) {
				return new \WP_Error( 'certloom_signer_context_mismatch', __( 'The selected Batch does not belong to the supplied Program.', 'certloom' ) );
			}
			$program_id = $batch_program;
		}

		if ( $program_id ) {
			$wpdb->last_error = '';
			$program_issuer = $wpdb->get_var(
				$wpdb->prepare( 'SELECT issuer_id FROM %i WHERE id=%d LIMIT 1', SchemaManager::table( 'programs' ), $program_id )
			);
			if ( '' !== (string) $wpdb->last_error ) {
				return new \WP_Error( 'certloom_signer_context_read_failed', __( 'Certloom could not validate the signer hierarchy context.', 'certloom' ) );
			}
			// get_var() cannot distinguish a missing row from a NULL issuer, so prove Program existence when needed.
			$wpdb->last_error = '';
			$program_exists = $wpdb->get_var(
				$wpdb->prepare( 'SELECT id FROM %i WHERE id=%d LIMIT 1', SchemaManager::table( 'programs' ), $program_id )
			);
			if ( '' !== (string) $wpdb->last_error ) {
				return new \WP_Error( 'certloom_signer_context_read_failed', __( 'Certloom could not validate the signer hierarchy context.', 'certloom' ) );
			}
			if ( ! $program_exists ) {
				return new \WP_Error( 'certloom_signer_context_program_missing', __( 'The selected signer Program no longer exists.', 'certloom' ) );
			}
			$program_issuer = absint( $program_issuer );
			if ( $program_issuer && $issuer_id && $issuer_id !== $program_issuer ) {
				return new \WP_Error( 'certloom_signer_context_mismatch', __( 'The selected Program does not belong to the supplied Issuer.', 'certloom' ) );
			}
			if ( $program_issuer ) {
				$issuer_id = $program_issuer;
			}
		}

		if ( $issuer_id ) {
			$exists = $this->scope_exists( 'issuer', $issuer_id );
			if ( is_wp_error( $exists ) ) {
				return $exists;
			}
			if ( ! $exists ) {
				return new \WP_Error( 'certloom_signer_context_issuer_missing', __( 'The selected signer Issuer no longer exists.', 'certloom' ) );
			}
		}

		return array(
			'issuer_id'  => $issuer_id,
			'program_id' => $program_id,
			'batch_id'   => $batch_id,
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	private function normalize_profile( array $data ) {
		$name  = trim( (string) ( $data['display_name'] ?? '' ) );
		$slug  = sanitize_key( (string) ( $data['slug'] ?? '' ) );
		$title = trim( (string) ( $data['title'] ?? '' ) );
		$email = trim( (string) ( $data['email'] ?? '' ) );
		if ( '' === $name || $this->length( $name ) > 255 ) {
			return new \WP_Error( 'certloom_signer_name_invalid', __( 'Signer name is required and must be 255 characters or fewer.', 'certloom' ) );
		}
		if ( '' === $slug || $this->length( $slug ) > 191 ) {
			return new \WP_Error( 'certloom_signer_slug_invalid', __( 'Signer key is required and must be 191 characters or fewer.', 'certloom' ) );
		}
		if ( $this->length( $title ) > 191 ) {
			return new \WP_Error( 'certloom_signer_title_invalid', __( 'Signer title must be 191 characters or fewer.', 'certloom' ) );
		}
		if ( '' !== $email && false === filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			return new \WP_Error( 'certloom_signer_email_invalid', __( 'Enter a valid signer email address or leave it blank.', 'certloom' ) );
		}

		$meta    = isset( $data['metadata'] ) && is_array( $data['metadata'] ) ? $data['metadata'] : array();
		$encoded = ( new SafeJson() )->encode_array( $meta, 'certloom_signer_metadata_invalid', __( 'Signer metadata could not be encoded safely.', 'certloom' ) );
		if ( is_wp_error( $encoded ) ) {
			return $encoded;
		}

		return array(
			'display_name'            => $name,
			'slug'                    => $slug,
			'title'                   => '' === $title ? null : $title,
			'email'                   => '' === $email ? null : $email,
			'signature_attachment_id' => ! empty( $data['signature_attachment_id'] ) ? absint( $data['signature_attachment_id'] ) : null,
			'active'                  => ! empty( $data['active'] ) ? 1 : 0,
			'metadata_json'           => $encoded,
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	private function decode( array $row ) {
		$row['id']                      = absint( $row['id'] ?? 0 );
		$row['signature_attachment_id'] = ! empty( $row['signature_attachment_id'] ) ? absint( $row['signature_attachment_id'] ) : null;
		$row['active']                  = ! empty( $row['active'] );
		if ( array_key_exists( 'metadata_json', $row ) ) {
			$meta = ( new SafeJson() )->decode_array(
				$row['metadata_json'] ?? null,
				'certloom_signer_metadata_corrupt',
				__( 'Stored signer metadata is invalid JSON.', 'certloom' )
			);
			if ( is_wp_error( $meta ) ) {
				return $meta;
			}
			$row['metadata'] = $meta;
			unset( $row['metadata_json'] );
		}
		return $row;
	}

	/** @return bool|\WP_Error */
	private function scope_exists( $scope, $id ) {
		$map = array( 'issuer' => 'issuers', 'program' => 'programs', 'batch' => 'batches' );
		if ( ! isset( $map[ $scope ] ) ) {
			return false;
		}
		global $wpdb;
		$wpdb->last_error = '';
		$found = $wpdb->get_var(
			$wpdb->prepare( 'SELECT id FROM %i WHERE id=%d LIMIT 1', SchemaManager::table( $map[ $scope ] ), absint( $id ) )
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_signer_scope_read_failed', __( 'Certloom could not validate the signer scope.', 'certloom' ) );
		}
		return ! empty( $found );
	}

	/** @return bool|\WP_Error */
	private function assignment_exists( $signer_id, $scope_type, $scope_id, $role_type ) {
		global $wpdb;
		$wpdb->last_error = '';
		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE signer_id=%d AND scope_type=%s AND scope_id=%d AND role_type=%s LIMIT 1',
				SchemaManager::table( 'signer_assignments' ),
				absint( $signer_id ),
				(string) $scope_type,
				absint( $scope_id ),
				(string) $role_type
			)
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_signer_assignment_read_failed', __( 'Certloom could not validate the signer assignment.', 'certloom' ) );
		}
		return ! empty( $found );
	}

	/** @return bool|\WP_Error */
	private function assignment_id_exists( $signer_id, $assignment_id ) {
		global $wpdb;
		$wpdb->last_error = '';
		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE id=%d AND signer_id=%d LIMIT 1',
				SchemaManager::table( 'signer_assignments' ),
				absint( $assignment_id ),
				absint( $signer_id )
			)
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_signer_assignment_read_failed', __( 'Certloom could not validate the signer assignment.', 'certloom' ) );
		}
		return ! empty( $found );
	}

	/** @return string|\WP_Error */
	private function scope_label( $scope, $id ) {
		if ( 'global' === $scope ) {
			return __( 'Global', 'certloom' );
		}
		$map = array(
			'issuer'  => array( 'issuers', 'name' ),
			'program' => array( 'programs', 'title' ),
			'batch'   => array( 'batches', 'name' ),
		);
		if ( ! isset( $map[ $scope ] ) ) {
			return new \WP_Error( 'certloom_signer_scope_invalid', __( 'Stored signer assignment scope is invalid.', 'certloom' ) );
		}

		global $wpdb;
		$wpdb->last_error = '';
		$value = $wpdb->get_var(
			$wpdb->prepare( 'SELECT ' . $map[ $scope ][1] . ' FROM %i WHERE id=%d', SchemaManager::table( $map[ $scope ][0] ), absint( $id ) )
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_signer_scope_read_failed', __( 'Certloom could not load a signer assignment scope.', 'certloom' ) );
		}
		if ( null === $value || '' === (string) $value ) {
			return new \WP_Error( 'certloom_signer_scope_missing', __( 'A stored signer assignment points to a missing scope.', 'certloom' ) );
		}
		return (string) $value;
	}

	/** @return int */
	private function length( $value ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $value, 'UTF-8' ) : strlen( (string) $value );
	}
}
