<?php
namespace Certloom\Application\Issuance;

use Certloom\Application\Contract\JobHandlerInterface;
use Certloom\Application\Job\JobSliceResult;
use Certloom\Infrastructure\Database\SchemaManager;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Ten primary-key rows per slice. Restored/erased work remains held. */
final class PendingLifecycleJobHandler implements JobHandlerInterface {
	public function handle( array $job, $worker_token ) {
		global $wpdb;
		$p = $job['payload'] ?? null; $cursor = (int) ( $job['cursor'] ?? 0 );
		if ( ! is_array( $p ) || count( $p ) !== 3 || array_diff( array_keys( $p ), array( 'schema_version', 'day', 'max_id' ) ) || 1 !== $p['schema_version'] || ! is_int( $p['max_id'] ) || $p['max_id'] < 1 || $cursor < 0 || $cursor > $p['max_id'] || ! is_string( $p['day'] ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $p['day'] ) || $p['day'] > gmdate( 'Y-m-d' ) ) { return new \WP_Error( 'certloom_pending_lifecycle_invalid', __( 'The request lifecycle selection needs review.', 'certloom' ) ); }
		$day = \DateTimeImmutable::createFromFormat( '!Y-m-d', $p['day'], new \DateTimeZone( 'UTC' ) );
		if ( ! $day || $day->format( 'Y-m-d' ) !== $p['day'] ) { return new \WP_Error( 'certloom_pending_lifecycle_invalid', __( 'The request lifecycle selection needs review.', 'certloom' ) ); }
		$wpdb->last_error = '';
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i FORCE INDEX (PRIMARY) WHERE id>%d AND id<=%d ORDER BY id LIMIT 11', SchemaManager::table( 'issuance_requests' ), $cursor, $p['max_id'] ) );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $ids ) ) { return new \WP_Error( 'certloom_pending_lifecycle_read_failed', __( 'The request lifecycle selection could not be read safely.', 'certloom' ) ); }
		$result = array( 'processed' => (int) ( $job['result']['processed'] ?? 0 ), 'notices_queued' => (int) ( $job['result']['notices_queued'] ?? 0 ) );
		foreach ( array_slice( $ids, 0, 10 ) as $id ) {
			$row = ( new PendingIssuanceService() )->expire( (int) $id ); if ( is_wp_error( $row ) ) { return $row; }
			$notice = ( new \Certloom\Application\Recipient\RecipientNoticeService() )->queue( (int) $id ); if ( is_wp_error( $notice ) ) { return $notice; }
			++$result['processed']; if ( is_string( $notice ) ) { ++$result['notices_queued']; }
			$cursor = (int) $id;
		}
		return count( $ids ) > 10 ? JobSliceResult::progress( $cursor, 'request_lifecycle', $result ) : JobSliceResult::succeeded( $result, $cursor );
	}
}
