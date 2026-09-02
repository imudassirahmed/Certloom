<?php
namespace Certloom\Infrastructure\Repository;

use Certloom\Application\Data\SafeJson;
use Certloom\Application\Fields\ScopedFieldContract;
use Certloom\Infrastructure\Data\DomainGeneration;
use Certloom\Infrastructure\Database\SchemaManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Persistence and bounded membership management for normalized cohorts/batches. */
final class BatchRepository {
	/** @param array<string,mixed> $data @return int|\WP_Error */
	public function create( array $data ) {
		$validated = $this->normalize_write( $data );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$now                     = current_time( 'mysql', true );
		$validated['created_at'] = $now;
		$validated['updated_at'] = $now;

		$generation = ( new DomainGeneration() )->mark_before_mutation();
		if ( is_wp_error( $generation ) ) {
			return $generation;
		}

		global $wpdb;
		$wpdb->last_error = '';
		$result            = $wpdb->insert( SchemaManager::table( 'batches' ), $validated );
		if ( false === $result || '' !== (string) $wpdb->last_error ) {
			if ( false !== stripos( (string) $wpdb->last_error, 'duplicate' ) ) {
				return new \WP_Error( 'certloom_batch_code_duplicate', __( 'That batch code is already in use.', 'certloom' ) );
			}
			return new \WP_Error( 'certloom_batch_create_failed', __( 'Certloom could not create the batch.', 'certloom' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/** @param int $id @param array<string,mixed> $data @return true|\WP_Error */
	public function update( $id, array $data ) {
		$id = absint( $id );
		if ( ! $id ) {
			return new \WP_Error( 'certloom_batch_id_required', __( 'A valid batch is required.', 'certloom' ) );
		}

		$existing = $this->find( $id );
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}
		if ( ! is_array( $existing ) ) {
			return new \WP_Error( 'certloom_batch_missing', __( 'The batch no longer exists.', 'certloom' ) );
		}

		$validated = $this->normalize_write( array_merge( $existing, $data ) );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		$validated['updated_at'] = current_time( 'mysql', true );

		$generation = ( new DomainGeneration() )->mark_before_mutation();
		if ( is_wp_error( $generation ) ) {
			return $generation;
		}

		global $wpdb;
		$wpdb->last_error = '';
		$result            = $wpdb->update( SchemaManager::table( 'batches' ), $validated, array( 'id' => $id ) );
		if ( false === $result || '' !== (string) $wpdb->last_error ) {
			if ( false !== stripos( (string) $wpdb->last_error, 'duplicate' ) ) {
				return new \WP_Error( 'certloom_batch_code_duplicate', __( 'That batch code is already in use.', 'certloom' ) );
			}
			return new \WP_Error( 'certloom_batch_update_failed', __( 'Certloom could not update the batch.', 'certloom' ) );
		}

		return true;
	}

	/** @return array<string,mixed>|null|\WP_Error */
	public function find( $id ) {
		global $wpdb;
		$wpdb->last_error = '';
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', SchemaManager::table( 'batches' ), absint( $id ) ),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_batch_read_failed', __( 'Certloom could not read the batch.', 'certloom' ) );
		}
		return is_array( $row ) ? $this->decode( $row ) : null;
	}

	/**
	 * Bounded FLD-02 dependency scan for one Program. This intentionally avoids
	 * loading every Batch into memory when validating a Program field-contract
	 * change. Callers continue with the last returned id until fewer than limit.
	 *
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	public function list_for_program_after( $program_id, $after_id = 0, $limit = 100 ) {
		$program_id = absint( $program_id );
		$after_id = absint( $after_id );
		$limit = max( 1, min( 100, absint( $limit ) ) );
		if ( ! $program_id ) { return new \WP_Error( 'certloom_batch_program_required', __( 'A valid Program is required to inspect Batch dependencies.', 'certloom' ) ); }
		global $wpdb;
		$wpdb->last_error = '';
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE program_id=%d AND id>%d ORDER BY id ASC LIMIT %d', SchemaManager::table( 'batches' ), $program_id, $after_id, $limit ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) { return new \WP_Error( 'certloom_batch_dependency_read_failed', __( 'Certloom could not verify dependent Batches before changing the Program credential-field contract.', 'certloom' ) ); }
		$out = array();
		foreach ( $rows as $row ) { $decoded = $this->decode( $row ); if ( is_wp_error( $decoded ) ) { return $decoded; } $out[] = $decoded; }
		return $out;
	}


	/**
	 * Bounded relationship lookup for Program-aware admin selectors.
	 *
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	public function search_active_for_program( $program_id, $search = '', $limit = 20 ) {
		$program_id = absint( $program_id );
		$search = trim( (string) $search );
		$limit = max( 1, min( 20, absint( $limit ) ) );
		if ( ! $program_id ) { return array(); }
		global $wpdb;
		$where = 'program_id=%d AND active=1';
		$args = array( SchemaManager::table( 'batches' ), $program_id );
		if ( '' !== $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$where .= ' AND (name LIKE %s OR code LIKE %s)';
			$args[] = $like; $args[] = $like;
		}
		$args[] = $limit;
		$wpdb->last_error = '';
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE ' . $where . ' ORDER BY name ASC,id ASC LIMIT %d', $args ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) { return new \WP_Error( 'certloom_batch_lookup_failed', __( 'Certloom could not search Batches for this Program.', 'certloom' ) ); }
		$out = array();
		foreach ( $rows as $row ) { $decoded = $this->decode( $row ); if ( is_wp_error( $decoded ) ) { return $decoded; } $out[] = $decoded; }
		return $out;
	}


	/**
	 * Bounded display labels for integration mapping summaries without N+1 reads.
	 *
	 * @param array<int,int|string> $ids
	 * @return array<int,string>|\WP_Error
	 */
	public function labels_by_ids( array $ids ) {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		if ( empty( $ids ) ) { return array(); }
		if ( count( $ids ) > 2500 ) { return new \WP_Error( 'certloom_batch_label_limit', __( 'Too many Batch labels were requested at once.', 'certloom' ) ); }
		global $wpdb;
		$out = array();
		foreach ( array_chunk( $ids, 200 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			$args = array_merge( array( SchemaManager::table( 'batches' ) ), $chunk );
			$wpdb->last_error = '';
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id,name,code FROM %i WHERE id IN (' . $placeholders . ')', $args ), ARRAY_A );
			if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) { return new \WP_Error( 'certloom_batch_label_read_failed', __( 'Certloom could not load Batch labels for integration mappings.', 'certloom' ) ); }
			foreach ( $rows as $row ) {
				$id = absint( $row['id'] ?? 0 ); if ( ! $id ) { continue; }
				$label = (string) ( $row['name'] ?? '' );
				if ( ! empty( $row['code'] ) ) { $label .= ' · ' . (string) $row['code']; }
				$out[ $id ] = $label;
			}
		}
		return $out;
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
			$where[] = '(b.name LIKE %s OR b.code LIKE %s OR p.title LIKE %s)';
			$args    = array_merge( $args, array( $like, $like, $like ) );
		}
		if ( 'active' === $status ) {
			$where[] = 'b.active = 1';
		} elseif ( 'inactive' === $status ) {
			$where[] = 'b.active = 0';
		}

		$where_sql = $where ? ' WHERE ' . implode( ' AND ', $where ) : '';
		$b         = SchemaManager::table( 'batches' );
		$p         = SchemaManager::table( 'programs' );
		$s         = SchemaManager::table( 'signers' );
		$br        = SchemaManager::table( 'batch_recipients' );
		$cs        = SchemaManager::table( 'credential_series' );
		$from      = ' FROM %i b INNER JOIN %i p ON p.id=b.program_id LEFT JOIN %i sg ON sg.id=b.instructor_signer_id';

		$wpdb->last_error = '';
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*)' . $from . $where_sql,
				array_merge( array( $b, $p, $s ), $args )
			)
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_batch_list_failed', __( 'Certloom could not load batches.', 'certloom' ) );
		}

		$sql = 'SELECT b.*, p.title AS program_title, sg.display_name AS instructor_signer_name, (SELECT COUNT(*) FROM %i x WHERE x.batch_id=b.id) AS recipient_count, (SELECT COUNT(*) FROM %i c WHERE c.batch_id=b.id) AS credential_count' . $from . $where_sql . ' ORDER BY b.id DESC LIMIT %d OFFSET %d';
		$wpdb->last_error = '';
		$items = $wpdb->get_results(
			$wpdb->prepare(
				$sql,
				array_merge( array( $br, $cs, $b, $p, $s ), $args, array( $per_page, $offset ) )
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error || ! is_array( $items ) ) {
			return new \WP_Error( 'certloom_batch_list_failed', __( 'Certloom could not load batches.', 'certloom' ) );
		}

		foreach ( $items as &$item ) {
			$decoded = $this->decode( $item );
			if ( is_wp_error( $decoded ) ) {
				return $decoded;
			}
			$item                    = $decoded;
			$item['recipient_count'] = absint( $item['recipient_count'] ?? 0 );
			$item['credential_count'] = absint( $item['credential_count'] ?? 0 );
		}
		unset( $item );

		return array( 'items' => $items, 'total' => $total );
	}

	/**
	 * Backward-compatible bounded member list used by older internal callers.
	 *
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	public function members( $batch_id, $limit = 100 ) {
		$page = $this->members_page( $batch_id, '', 1, $limit );
		return is_wp_error( $page ) ? $page : $page['items'];
	}

	/** @return array{items:array<int,array<string,mixed>>,total:int}|\WP_Error */
	public function members_page( $batch_id, $search = '', $page = 1, $per_page = 20 ) {
		$batch_id = absint( $batch_id );
		if ( ! $batch_id ) {
			return new \WP_Error( 'certloom_batch_id_required', __( 'A valid batch is required.', 'certloom' ) );
		}

		global $wpdb;
		$page     = max( 1, absint( $page ) );
		$per_page = max( 1, min( 100, absint( $per_page ) ) );
		$offset   = ( $page - 1 ) * $per_page;
		$search   = trim( (string) $search );
		$where    = ' WHERE br.batch_id=%d';
		$args     = array( $batch_id );
		if ( '' !== $search ) {
			$like   = '%' . $wpdb->esc_like( $search ) . '%';
			$where .= ' AND (r.display_name LIKE %s OR r.email LIKE %s OR r.external_id LIKE %s)';
			$args[] = $like;
			$args[] = $like;
			$args[] = $like;
		}

		$br = SchemaManager::table( 'batch_recipients' );
		$r  = SchemaManager::table( 'recipients' );
		$from = ' FROM %i br INNER JOIN %i r ON r.id=br.recipient_id';

		$wpdb->last_error = '';
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*)' . $from . $where,
				array_merge( array( $br, $r ), $args )
			)
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_batch_members_read_failed', __( 'Certloom could not load batch recipients.', 'certloom' ) );
		}

		$wpdb->last_error = '';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT r.id, r.display_name, r.email, r.external_id, br.created_at' . $from . $where . ' ORDER BY r.display_name ASC, r.id ASC LIMIT %d OFFSET %d',
				array_merge( array( $br, $r ), $args, array( $per_page, $offset ) )
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
			return new \WP_Error( 'certloom_batch_members_read_failed', __( 'Certloom could not load batch recipients.', 'certloom' ) );
		}

		foreach ( $rows as &$row ) {
			$row['id'] = absint( $row['id'] ?? 0 );
		}
		unset( $row );

		return array( 'items' => $rows, 'total' => $total );
	}

	/** @return true|\WP_Error */
	public function add_recipient( $batch_id, $recipient_id ) {
		$batch_id     = absint( $batch_id );
		$recipient_id = absint( $recipient_id );
		if ( ! $batch_id || ! $recipient_id ) {
			return new \WP_Error( 'certloom_batch_member_invalid', __( 'Choose a valid batch and recipient.', 'certloom' ) );
		}

		foreach ( array( 'batches' => $batch_id, 'recipients' => $recipient_id ) as $entity => $id ) {
			$exists = $this->exists( $entity, $id );
			if ( is_wp_error( $exists ) ) {
				return $exists;
			}
			if ( ! $exists ) {
				return new \WP_Error( 'certloom_batch_member_missing', __( 'The batch or recipient no longer exists.', 'certloom' ) );
			}
		}

		$member = $this->membership_exists( $batch_id, $recipient_id );
		if ( is_wp_error( $member ) ) {
			return $member;
		}
		if ( $member ) {
			return true;
		}

		$generation = ( new DomainGeneration() )->mark_before_mutation();
		if ( is_wp_error( $generation ) ) {
			return $generation;
		}

		global $wpdb;
		$wpdb->last_error = '';
		$result = $wpdb->insert(
			SchemaManager::table( 'batch_recipients' ),
			array(
				'batch_id'     => $batch_id,
				'recipient_id' => $recipient_id,
				'created_at'   => current_time( 'mysql', true ),
			)
		);
		if ( false === $result || '' !== (string) $wpdb->last_error ) {
			// A concurrent identical add is harmless; prove it exists before reporting success.
			if ( false !== stripos( (string) $wpdb->last_error, 'duplicate' ) ) {
				$exists = $this->membership_exists( $batch_id, $recipient_id );
				if ( true === $exists ) {
					return true;
				}
			}
			return new \WP_Error( 'certloom_batch_member_add_failed', __( 'Certloom could not add the recipient to the batch.', 'certloom' ) );
		}

		return true;
	}

	/** @return true|\WP_Error */
	public function remove_recipient( $batch_id, $recipient_id ) {
		$batch_id     = absint( $batch_id );
		$recipient_id = absint( $recipient_id );
		if ( ! $batch_id || ! $recipient_id ) {
			return new \WP_Error( 'certloom_batch_member_invalid', __( 'Choose a valid batch and recipient.', 'certloom' ) );
		}

		$member = $this->membership_exists( $batch_id, $recipient_id );
		if ( is_wp_error( $member ) ) {
			return $member;
		}
		if ( ! $member ) {
			return true;
		}

		$generation = ( new DomainGeneration() )->mark_before_mutation();
		if ( is_wp_error( $generation ) ) {
			return $generation;
		}

		global $wpdb;
		$wpdb->last_error = '';
		$result            = $wpdb->delete( SchemaManager::table( 'batch_recipients' ), array( 'batch_id' => $batch_id, 'recipient_id' => $recipient_id ) );
		return false === $result || '' !== (string) $wpdb->last_error
			? new \WP_Error( 'certloom_batch_member_remove_failed', __( 'Certloom could not remove the recipient from the batch.', 'certloom' ) )
			: true;
	}

	/** @return array<string,int>|\WP_Error */
	public function summary() {
		global $wpdb;
		$b  = SchemaManager::table( 'batches' );
		$br = SchemaManager::table( 'batch_recipients' );
		$cs = SchemaManager::table( 'credential_series' );

		$wpdb->last_error = '';
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT COUNT(*) total, SUM(CASE WHEN active=1 THEN 1 ELSE 0 END) active FROM %i', $b ),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error || ! is_array( $row ) ) {
			return new \WP_Error( 'certloom_batch_summary_failed', __( 'Certloom could not summarize batches.', 'certloom' ) );
		}

		$wpdb->last_error = '';
		$members = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $br ) );
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_batch_summary_failed', __( 'Certloom could not summarize batches.', 'certloom' ) );
		}

		$wpdb->last_error = '';
		$with_credentials = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT batch_id) FROM %i WHERE batch_id IS NOT NULL', $cs ) );
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_batch_summary_failed', __( 'Certloom could not summarize batches.', 'certloom' ) );
		}

		return array(
			'total'            => absint( $row['total'] ?? 0 ),
			'active'           => absint( $row['active'] ?? 0 ),
			'members'          => absint( $members ),
			'with_credentials' => absint( $with_credentials ),
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	private function normalize_write( array $data ) {
		$name       = trim( (string) ( $data['name'] ?? '' ) );
		$code       = trim( (string) ( $data['code'] ?? '' ) );
		$program_id = absint( $data['program_id'] ?? 0 );
		if ( '' === $name || $this->text_length( $name ) > 255 ) {
			return new \WP_Error( 'certloom_batch_name_invalid', __( 'Batch name is required and must be 255 characters or fewer.', 'certloom' ) );
		}
		if ( ! $program_id ) {
			return new \WP_Error( 'certloom_batch_program_invalid', __( 'Choose an existing program for the batch.', 'certloom' ) );
		}
		$program_exists = $this->exists( 'programs', $program_id );
		if ( is_wp_error( $program_exists ) ) {
			return $program_exists;
		}
		if ( ! $program_exists ) {
			return new \WP_Error( 'certloom_batch_program_invalid', __( 'Choose an existing program for the batch.', 'certloom' ) );
		}
		if ( '' === $code || $this->text_length( $code ) > 100 ) {
			return new \WP_Error( 'certloom_batch_code_invalid', __( 'Batch code is required and must be 100 characters or fewer.', 'certloom' ) );
		}

		$start = $this->date_value( $data['start_date'] ?? null );
		if ( is_wp_error( $start ) ) {
			return $start;
		}
		$end = $this->date_value( $data['end_date'] ?? null );
		if ( is_wp_error( $end ) ) {
			return $end;
		}
		if ( $start && $end && $end < $start ) {
			return new \WP_Error( 'certloom_batch_date_order_invalid', __( 'Batch end date cannot be earlier than its start date.', 'certloom' ) );
		}

		$signer_id = ! empty( $data['instructor_signer_id'] ) ? absint( $data['instructor_signer_id'] ) : null;
		if ( $signer_id ) {
			$signer_exists = $this->exists( 'signers', $signer_id );
			if ( is_wp_error( $signer_exists ) ) {
				return $signer_exists;
			}
			if ( ! $signer_exists ) {
				return new \WP_Error( 'certloom_batch_instructor_signer_missing', __( 'The selected instructor signer does not exist.', 'certloom' ) );
			}
		}

		$meta    = isset( $data['metadata'] ) && is_array( $data['metadata'] ) ? $data['metadata'] : array();
		$encoded = ( new SafeJson() )->encode_array( $meta, 'certloom_batch_metadata_invalid', __( 'Batch metadata could not be encoded safely.', 'certloom' ) );
		if ( is_wp_error( $encoded ) ) {
			return $encoded;
		}

		$instructor = trim( (string) ( $data['instructor_name'] ?? '' ) );
		if ( $this->text_length( $instructor ) > 255 ) {
			return new \WP_Error( 'certloom_batch_instructor_invalid', __( 'Instructor name must be 255 characters or fewer.', 'certloom' ) );
		}

		return array(
			'program_id'          => $program_id,
			'code'                => $code,
			'name'                => $name,
			'start_date'          => $start,
			'end_date'            => $end,
			'instructor_name'     => '' === $instructor ? null : $instructor,
			'instructor_signer_id' => $signer_id,
			'active'              => ! empty( $data['active'] ) ? 1 : 0,
			'metadata_json'       => $encoded,
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	private function decode( array $row ) {
		$row['id']                   = absint( $row['id'] ?? 0 );
		$row['program_id']           = absint( $row['program_id'] ?? 0 );
		$row['instructor_signer_id'] = ! empty( $row['instructor_signer_id'] ) ? absint( $row['instructor_signer_id'] ) : null;
		$row['active']               = ! empty( $row['active'] );
		if ( array_key_exists( 'metadata_json', $row ) ) {
			$meta = ( new SafeJson() )->decode_array(
				$row['metadata_json'] ?? null,
				'certloom_batch_metadata_corrupt',
				__( 'Stored batch metadata is invalid JSON.', 'certloom' )
			);
			if ( is_wp_error( $meta ) ) {
				return $meta;
			}
			$catalog = class_exists( '\\Ecapla_CertVerify_Fields' ) ? \Ecapla_CertVerify_Fields::get_field_map() : array();
			$field_contract = ( new ScopedFieldContract() )->from_batch_metadata( $meta, $catalog );
			if ( is_wp_error( $field_contract ) ) { return $field_contract; }
			unset( $meta[ ScopedFieldContract::STORAGE_KEY ] );
			$row['metadata'] = $meta;
			$row['field_contract'] = $field_contract;
			unset( $row['metadata_json'] );
		}
		return $row;
	}

	/** @return bool|\WP_Error */
	private function exists( $entity, $id ) {
		global $wpdb;
		$wpdb->last_error = '';
		$found = $wpdb->get_var(
			$wpdb->prepare( 'SELECT id FROM %i WHERE id=%d LIMIT 1', SchemaManager::table( $entity ), absint( $id ) )
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_batch_reference_read_failed', __( 'Certloom could not validate a related batch record.', 'certloom' ) );
		}
		return ! empty( $found );
	}

	/** @return bool|\WP_Error */
	private function membership_exists( $batch_id, $recipient_id ) {
		global $wpdb;
		$wpdb->last_error = '';
		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT recipient_id FROM %i WHERE batch_id=%d AND recipient_id=%d LIMIT 1',
				SchemaManager::table( 'batch_recipients' ),
				absint( $batch_id ),
				absint( $recipient_id )
			)
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_batch_member_read_failed', __( 'Certloom could not validate batch membership.', 'certloom' ) );
		}
		return ! empty( $found );
	}

	/** @return string|null|\WP_Error */
	private function date_value( $value ) {
		$value = null === $value ? '' : trim( (string) $value );
		if ( '' === $value ) {
			return null;
		}
		$date   = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value, new \DateTimeZone( 'UTC' ) );
		$errors = \DateTimeImmutable::getLastErrors();
		if ( false === $date || ( is_array( $errors ) && ( ! empty( $errors['warning_count'] ) || ! empty( $errors['error_count'] ) ) ) || $date->format( 'Y-m-d' ) !== $value ) {
			return new \WP_Error( 'certloom_batch_date_invalid', __( 'Batch dates must use YYYY-MM-DD.', 'certloom' ) );
		}
		return $value;
	}

	/** @return int */
	private function text_length( $value ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $value, 'UTF-8' ) : strlen( (string) $value );
	}
}
