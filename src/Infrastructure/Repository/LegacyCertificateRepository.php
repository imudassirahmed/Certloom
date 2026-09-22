<?php
namespace Certloom\Infrastructure\Repository;

use Certloom\Application\Contract\LegacySourceSnapshotInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Read-only bounded access to the existing Certloom 1.5 certificate registry. */
final class LegacyCertificateRepository implements LegacySourceSnapshotInterface {
	/** @return array<string,int>|\WP_Error */
	public function snapshot() {
		global $wpdb;
		$table = \Ecapla_CertVerify_Database::table_name();
		$wpdb->last_error = '';
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) AS row_count, COALESCE(MAX(id),0) AS max_id FROM %i', $table ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( '' !== (string) $wpdb->last_error || ! is_array( $row ) ) {
			return new \WP_Error( 'certloom_legacy_snapshot_failed', __( 'Certloom could not snapshot the legacy certificate registry.', 'certloom' ) );
		}
		return array( 'count' => absint( $row['row_count'] ?? 0 ), 'max_id' => absint( $row['max_id'] ?? 0 ), 'generation' => (string) \Ecapla_CertVerify_Backup::current_generation() );
	}

	/**
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	public function read_batch( $after_id, $max_id, $limit = 250 ) {
		global $wpdb;
		$after_id = absint( $after_id );
		$max_id = absint( $max_id );
		$limit = max( 1, min( 1000, absint( $limit ) ) );
		$wpdb->last_error = '';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, cert_number, status, status_source, holder_name, issue_date, expiry_date, custom_fields, created_at, updated_at FROM %i WHERE id > %d AND id <= %d ORDER BY id ASC LIMIT %d',
				\Ecapla_CertVerify_Database::table_name(),
				$after_id,
				$max_id,
				$limit
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
			return new \WP_Error( 'certloom_legacy_batch_read_failed', __( 'Certloom could not read the next legacy migration batch.', 'certloom' ) );
		}
		return $rows;
	}
}
