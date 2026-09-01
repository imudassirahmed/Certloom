<?php
namespace Certloom\Infrastructure\Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only presentation adapter that makes normalized Credentials and the
 * historical registry appear as one customer-facing Credentials collection.
 *
 * Historical rows remain in their proven legacy table and keep their existing
 * public verification/editing authority. This adapter never migrates, rewrites
 * or pretends that those rows are normalized Credential Series.
 */
final class UnifiedCredentialRegistry {
	/** @var CredentialRepository */
	private $credentials;

	public function __construct( ?CredentialRepository $credentials = null ) {
		$this->credentials = $credentials ?: new CredentialRepository();
	}

	/** @return array{items:array<int,array<string,mixed>>,total:int}|\WP_Error */
	public function list_page( $search = '', $page = 1, $per_page = 50, $status = 'all', $program_id = 0 ) {
		$page       = max( 1, absint( $page ) );
		$per_page   = max( 1, min( 100, absint( $per_page ) ) );
		$program_id = absint( $program_id );
		$status     = sanitize_key( (string) $status );
		$search     = trim( (string) $search );
		$needed     = $page * $per_page;
		$domain_authoritative = $this->domain_authoritative();

		$current = $this->current_window( $search, $status, $program_id, $needed, $domain_authoritative ? '' : 'certloom_legacy' );
		if ( is_wp_error( $current ) ) { return $current; }
		$current_items = array();
		foreach ( (array) ( $current['items'] ?? array() ) as $row ) {
			$migrated_historical = 'certloom_legacy' === (string) ( $row['source_type'] ?? '' );
			$row['_credential_source']       = 'current';
			$row['_credential_source_label'] = $migrated_historical ? __( 'Historical', 'certloom' ) : __( 'Current', 'certloom' );
			$row['_credential_historical_origin'] = $migrated_historical;
			$row['_credential_sort_at']      = $this->sort_value( (string) ( $row['series_updated_at'] ?? $row['version_updated_at'] ?? '' ), (string) ( $row['issued_at'] ?? '' ) );
			$current_items[]                  = $row;
		}

		$legacy = array( 'items' => array(), 'total' => 0 );
		if ( ! $domain_authoritative && 0 === $program_id && $this->legacy_supports_status( $status ) && class_exists( 'Ecapla_CertVerify_Database' ) ) {
			$legacy = $this->historical_window( $search, $status, $needed );
			if ( is_wp_error( $legacy ) ) { return $legacy; }
		}

		$legacy_items = array();
		foreach ( (array) ( $legacy['items'] ?? array() ) as $row ) {
			$status_code = class_exists( 'Ecapla_CertVerify_Database' ) ? (string) \Ecapla_CertVerify_Database::effective_status( $row ) : sanitize_key( (string) ( $row['status'] ?? '' ) );
			$legacy_items[] = array(
				'_credential_source'       => 'historical',
				'_credential_source_label' => __( 'Historical', 'certloom' ),
				'_credential_sort_at'      => $this->sort_value( (string) ( $row['created_at'] ?? '' ), (string) ( $row['issue_date'] ?? '' ) ),
				'legacy_id'                => absint( $row['id'] ?? 0 ),
				'series_id'                => 0,
				'public_id'                => '',
				'display_number'           => (string) ( $row['cert_number'] ?? '' ),
				'display_name'             => (string) ( $row['holder_name'] ?? '' ),
				'recipient_email'          => '',
				'program_title'            => '',
				'batch_name'               => '',
				'issued_at'                => (string) ( $row['issue_date'] ?? '' ),
				'expires_at'               => (string) ( $row['expiry_date'] ?? '' ),
				'version_number'           => 0,
				'legacy_status_code'       => $this->normalized_legacy_status( $status_code ),
				'legacy_row'               => $row,
			);
		}

		$items = array_merge( $current_items, $legacy_items );
		usort(
			$items,
			static function ( $left, $right ) {
				$left_time  = (int) ( $left['_credential_sort_at'] ?? 0 );
				$right_time = (int) ( $right['_credential_sort_at'] ?? 0 );
				if ( $left_time === $right_time ) {
					$left_key  = (string) ( $left['_credential_source'] ?? '' ) . ':' . (string) ( $left['series_id'] ?? $left['legacy_id'] ?? 0 );
					$right_key = (string) ( $right['_credential_source'] ?? '' ) . ':' . (string) ( $right['series_id'] ?? $right['legacy_id'] ?? 0 );
					return strcmp( $right_key, $left_key );
				}
				return $right_time <=> $left_time;
			}
		);

		$offset = ( $page - 1 ) * $per_page;
		return array(
			'items' => array_slice( $items, $offset, $per_page ),
			'total' => absint( $current['total'] ?? 0 ) + absint( $legacy['total'] ?? 0 ),
		);
	}

	/** @return array{items:array<int,array<string,mixed>>,total:int}|\WP_Error */
	private function current_window( $search, $status, $program_id, $needed, $exclude_source_type = '' ) {
		$items = array(); $total = null; $page = 1; $chunk = 100;
		do {
			$result = $this->credentials->list_management_page( $search, $page, $chunk, $status, $program_id, $exclude_source_type );
			if ( is_wp_error( $result ) ) { return $result; }
			if ( null === $total ) { $total = absint( $result['total'] ?? 0 ); }
			$rows = (array) ( $result['items'] ?? array() );
			$items = array_merge( $items, $rows );
			$page++;
		} while ( count( $items ) < $needed && count( $items ) < $total && ! empty( $rows ) );
		return array( 'items' => array_slice( $items, 0, $needed ), 'total' => absint( $total ) );
	}

	/** @return array{items:array<int,array<string,mixed>>,total:int}|\WP_Error */
	private function historical_window( $search, $status, $needed ) {
		$items = array(); $total = null; $page = 1; $chunk = 500;
		do {
			$args = array( 'search' => $search, 'search_mode' => 'contains', 'orderby' => 'created_at', 'order' => 'DESC', 'per_page' => $chunk, 'page' => $page );
			$legacy_status = $this->legacy_status( $status );
			if ( '' !== $legacy_status ) { $args['status'] = $legacy_status; }
			$result = \Ecapla_CertVerify_Database::get_list( $args );
			if ( is_wp_error( $result ) ) { return $result; }
			if ( null === $total ) { $total = absint( $result['total'] ?? 0 ); }
			$rows = (array) ( $result['items'] ?? array() );
			$items = array_merge( $items, $rows );
			$page++;
		} while ( count( $items ) < $needed && count( $items ) < $total && ! empty( $rows ) );
		return array( 'items' => array_slice( $items, 0, $needed ), 'total' => absint( $total ) );
	}

	/** @return array<string,int>|\WP_Error */
	public function summary() {
		$domain_authoritative = $this->domain_authoritative();
		$current = $this->credentials->management_summary( $domain_authoritative ? '' : 'certloom_legacy' );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		$legacy = ! $domain_authoritative && class_exists( 'Ecapla_CertVerify_Database' ) ? \Ecapla_CertVerify_Database::get_dashboard_metrics() : array();
		if ( is_wp_error( $legacy ) ) {
			return $legacy;
		}
		$out = $current;
		$out['total']     = absint( $current['total'] ?? 0 ) + absint( $legacy['total'] ?? 0 );
		$out['valid']     = absint( $current['valid'] ?? 0 ) + absint( $legacy['valid'] ?? 0 );
		$out['suspended'] = absint( $current['suspended'] ?? 0 ) + absint( $legacy['suspended'] ?? 0 );
		$out['revoked']   = absint( $current['revoked'] ?? 0 ) + absint( $legacy['withdrawn'] ?? 0 );
		$out['expired']   = absint( $current['expired'] ?? 0 ) + absint( $legacy['expired'] ?? 0 );
		return $out;
	}

	/** @return array<string,mixed>|null|\WP_Error */
	public function find_historical( $legacy_id ) {
		if ( ! class_exists( 'Ecapla_CertVerify_Database' ) ) {
			return null;
		}
		$row = \Ecapla_CertVerify_Database::get_by_id( absint( $legacy_id ) );
		if ( is_wp_error( $row ) || ! is_array( $row ) ) {
			return $row;
		}
		$row['_credential_source']       = 'historical';
		$row['_credential_source_label'] = __( 'Historical', 'certloom' );
		$row['legacy_status_code']       = $this->normalized_legacy_status( (string) \Ecapla_CertVerify_Database::effective_status( $row ) );
		return $row;
	}


	/** True only after the official migration/cutover receipt makes Series authoritative. */
	private function domain_authoritative() {
		if ( ! class_exists( '\Certloom\Application\Migration\DomainCutoverState' ) || ! class_exists( '\Certloom\Application\Migration\LegacyMigrationState' ) ) { return false; }
		$cutover = new \Certloom\Application\Migration\DomainCutoverState();
		$migration = new \Certloom\Application\Migration\LegacyMigrationState();
		return $cutover->is_domain_authoritative() && $cutover->receipt_matches_completed_migration( $migration );
	}

	private function legacy_supports_status( $status ) {
		return in_array( $status, array( 'all', 'valid', 'suspended', 'revoked', 'expired' ), true );
	}

	private function legacy_status( $status ) {
		return 'revoked' === $status ? 'withdrawn' : ( 'all' === $status ? '' : $status );
	}

	private function normalized_legacy_status( $status ) {
		return 'withdrawn' === $status ? 'revoked' : sanitize_key( $status );
	}

	private function sort_value( $primary, $fallback ) {
		$value = '' !== trim( $primary ) ? $primary : $fallback;
		$time  = '' !== trim( $value ) ? strtotime( $value . ( false === strpos( $value, ':' ) ? ' 00:00:00' : '' ) . ' UTC' ) : false;
		return false === $time ? 0 : (int) $time;
	}
}
