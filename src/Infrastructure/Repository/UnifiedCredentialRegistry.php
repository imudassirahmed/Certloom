<?php
namespace Certloom\Infrastructure\Repository;

use Certloom\Domain\Credential\StatusService;

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
	public function list_page( $search = '', $page = 1, $per_page = 50, $status = 'all', $program_id = 0, $orderby = 'recent', $order = 'DESC' ) {
		$page       = max( 1, absint( $page ) );
		$per_page   = max( 1, min( 100, absint( $per_page ) ) );
		$program_id = absint( $program_id );
		$status     = sanitize_key( (string) $status );
		$search     = trim( (string) $search );
		$orderby    = $this->normalize_orderby( $orderby );
		$order      = 'ASC' === strtoupper( (string) $order ) ? 'ASC' : 'DESC';
		$needed     = $page * $per_page;
		$domain_authoritative = $this->domain_authoritative();

		$current = $this->current_window( $search, $status, $program_id, $needed, $domain_authoritative ? '' : 'certloom_legacy', $orderby, $order );
		if ( is_wp_error( $current ) ) { return $current; }
		$current_items = array();
		foreach ( (array) ( $current['items'] ?? array() ) as $row ) {
			$origin_source = (string) ( $row['origin_source_type'] ?? $row['source_type'] ?? '' );
			$origin_ref    = (string) ( $row['origin_source_ref'] ?? $row['source_ref'] ?? '' );
			$migrated_historical = 'certloom_legacy' === $origin_source;
			$row['_credential_source']       = 'current';
			$row['_credential_source_label'] = $migrated_historical ? __( 'Legacy', 'certloom' ) : __( 'Credential', 'certloom' );
			$row['_credential_issuance_source_key'] = $this->issuance_source_key( $origin_source, $origin_ref );
			$row['_credential_issuance_source_label'] = $this->issuance_source_label( $origin_source, $origin_ref );
			$row['_credential_historical_origin'] = $migrated_historical;
			$row['_credential_sort_at']      = $this->sort_value( (string) ( $row['series_updated_at'] ?? $row['version_updated_at'] ?? '' ), (string) ( $row['issued_at'] ?? '' ) );
			$row['entry_created_at']          = (string) ( $row['series_created_at'] ?? '' );
			$row['_credential_sort_value']   = $this->row_sort_value( $row, $orderby, false );
			$current_items[]                  = $row;
		}

		$legacy = array( 'items' => array(), 'total' => 0 );
		if ( ! $domain_authoritative && 0 === $program_id && $this->legacy_supports_status( $status ) && class_exists( 'Ecapla_CertVerify_Database' ) ) {
			$legacy = $this->historical_window( $search, $status, $needed, $orderby, $order );
			if ( is_wp_error( $legacy ) ) { return $legacy; }
		}

		$legacy_items = array();
		foreach ( (array) ( $legacy['items'] ?? array() ) as $row ) {
			$status_code = class_exists( 'Ecapla_CertVerify_Database' ) ? (string) \Ecapla_CertVerify_Database::effective_status( $row ) : sanitize_key( (string) ( $row['status'] ?? '' ) );
			$legacy_item = array(
				'_credential_source'       => 'historical',
				'_credential_source_label' => __( 'Legacy', 'certloom' ),
				'_credential_issuance_source_key' => 'legacy',
				'_credential_issuance_source_label' => __( 'Legacy import', 'certloom' ),
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
				'entry_created_at'         => (string) ( $row['created_at'] ?? '' ),
				'version_number'           => 0,
				'legacy_status_code'       => $this->normalized_legacy_status( $status_code ),
				'legacy_row'               => $row,
			);
			$legacy_item['_credential_sort_value'] = $this->row_sort_value( $legacy_item, $orderby, true );
			$legacy_items[] = $legacy_item;
		}

		$items = array_merge( $current_items, $legacy_items );
		$self = $this;
		usort(
			$items,
			static function ( $left, $right ) use ( $self, $orderby, $order ) {
				if ( 'recent' === $orderby ) {
					$comparison = (int) ( $right['_credential_sort_at'] ?? 0 ) <=> (int) ( $left['_credential_sort_at'] ?? 0 );
				} else {
					$comparison = $self->compare_sort_values( $left['_credential_sort_value'] ?? '', $right['_credential_sort_value'] ?? '', $orderby );
					if ( 'DESC' === $order && 'expiry_date' !== $orderby ) { $comparison *= -1; }
					if ( 'DESC' === $order && 'expiry_date' === $orderby && 0 === ( (int) ( $left['_credential_sort_value'][0] ?? 1 ) <=> (int) ( $right['_credential_sort_value'][0] ?? 1 ) ) ) { $comparison *= -1; }
				}
				if ( 0 === $comparison ) {
					$left_key  = (string) ( $left['_credential_source'] ?? '' ) . ':' . (string) ( $left['series_id'] ?? $left['legacy_id'] ?? 0 );
					$right_key = (string) ( $right['_credential_source'] ?? '' ) . ':' . (string) ( $right['series_id'] ?? $right['legacy_id'] ?? 0 );
					$comparison = strcmp( $left_key, $right_key );
					if ( 'DESC' === $order || 'recent' === $orderby ) { $comparison *= -1; }
				}
				return $comparison;
			}
		);

		$offset = ( $page - 1 ) * $per_page;
		return array(
			'items' => array_slice( $items, $offset, $per_page ),
			'total' => absint( $current['total'] ?? 0 ) + absint( $legacy['total'] ?? 0 ),
		);
	}

	/** @return array{items:array<int,array<string,mixed>>,total:int}|\WP_Error */
	private function current_window( $search, $status, $program_id, $needed, $exclude_source_type = '', $orderby = 'recent', $order = 'DESC' ) {
		$items = array(); $total = null; $page = 1; $chunk = 100;
		do {
			$result = $this->credentials->list_management_page( $search, $page, $chunk, $status, $program_id, $exclude_source_type, $orderby, $order );
			if ( is_wp_error( $result ) ) { return $result; }
			if ( null === $total ) { $total = absint( $result['total'] ?? 0 ); }
			$rows = (array) ( $result['items'] ?? array() );
			$items = array_merge( $items, $rows );
			$page++;
		} while ( count( $items ) < $needed && count( $items ) < $total && ! empty( $rows ) );
		return array( 'items' => array_slice( $items, 0, $needed ), 'total' => absint( $total ) );
	}

	/** @return array{items:array<int,array<string,mixed>>,total:int}|\WP_Error */
	private function historical_window( $search, $status, $needed, $orderby = 'recent', $order = 'DESC' ) {
		$items = array(); $total = null; $page = 1; $chunk = 500;
		$legacy_orderby = array(
			'credential' => 'cert_number',
			'recipient' => 'holder_name',
			'issue_date' => 'issue_date',
			'expiry_date' => 'expiry_date',
			'status' => 'status',
			'added' => 'created_at',
		);
		do {
			$args = array( 'search' => $search, 'search_mode' => 'contains', 'orderby' => $legacy_orderby[ $orderby ] ?? 'created_at', 'order' => 'ASC' === $order ? 'ASC' : 'DESC', 'per_page' => $chunk, 'page' => $page );
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

	/** @return string */
	private function issuance_source_key( $source_type, $source_ref ) {
		$source_type = sanitize_key( (string) $source_type );
		$source_ref  = trim( (string) $source_ref );
		if ( 'lms_integration' === $source_type && preg_match( '/^([a-z][a-z0-9_-]*):/', $source_ref, $m ) ) { return sanitize_key( $m[1] ); }
		$map = array( 'manual_admin' => 'manual', 'bulk_admin' => 'bulk', 'certloom_csv' => 'csv', 'certloom_legacy' => 'legacy' );
		return isset( $map[ $source_type ] ) ? $map[ $source_type ] : ( '' !== $source_type ? $source_type : 'unknown' );
	}

	/** @return string */
	private function issuance_source_label( $source_type, $source_ref ) {
		$key = $this->issuance_source_key( $source_type, $source_ref );
		$labels = array(
			'tutor_lms' => __( 'Tutor LMS', 'certloom' ),
			'learndash' => __( 'LearnDash', 'certloom' ),
			'woocommerce' => __( 'WooCommerce', 'certloom' ),
			'coursera' => __( 'Coursera', 'certloom' ),
			'webhook' => __( 'Webhook / API', 'certloom' ),
			'manual' => __( 'Manual', 'certloom' ),
			'bulk' => __( 'Bulk', 'certloom' ),
			'csv' => __( 'CSV import', 'certloom' ),
			'legacy' => __( 'Legacy import', 'certloom' ),
		);
		if ( isset( $labels[ $key ] ) ) { return $labels[ $key ]; }
		if ( 'unknown' === $key ) { return __( 'Unknown source', 'certloom' ); }
		$human = ucwords( str_replace( array( '_', '-' ), ' ', $key ) );
		$human = preg_replace( '/\bLms\b/', 'LMS', $human );
		$human = preg_replace( '/\bApi\b/', 'API', $human );
		return trim( (string) $human );
	}

	/** @return string */
	private function normalize_orderby( $orderby ) {
		$orderby = sanitize_key( (string) $orderby );
		return in_array( $orderby, array( 'recent', 'credential', 'recipient', 'program', 'issue_date', 'expiry_date', 'status', 'version', 'added' ), true ) ? $orderby : 'recent';
	}

	/** @return mixed */
	private function row_sort_value( array $row, $orderby, $historical ) {
		switch ( $orderby ) {
			case 'credential': return strtolower( trim( (string) ( $row['display_number'] ?? $row['public_id'] ?? '' ) ) );
			case 'recipient': return strtolower( trim( (string) ( $row['display_name'] ?? '' ) ) );
			case 'program': return strtolower( trim( (string) ( $row['program_title'] ?? '' ) ) . "\0" . trim( (string) ( $row['batch_name'] ?? '' ) ) );
			case 'issue_date': return $this->sortable_date( (string) ( $row['issued_at'] ?? '' ) );
			case 'expiry_date': return $this->sortable_expiry( (string) ( $row['expires_at'] ?? '' ) );
			case 'status':
				if ( $historical ) { return strtolower( (string) ( $row['legacy_status_code'] ?? '' ) ); }
				try {
					$result = ( new StatusService() )->evaluate( array(
						'issuance_state' => (string) ( $row['issuance_state'] ?? '' ),
						'administrative_state' => (string) ( $row['administrative_state'] ?? '' ),
						'legacy_expired' => ! empty( $row['legacy_expired'] ),
						'valid_from' => $row['valid_from'] ?? null,
						'expires_at' => $row['expires_at'] ?? null,
						'compliance_state' => (string) ( $row['compliance_state'] ?? '' ),
					) );
					return strtolower( (string) $result->code() );
				} catch ( \Throwable $error ) { return ''; }
			case 'version': return absint( $row['version_number'] ?? 0 );
			case 'added': return $this->sortable_date( (string) ( $row['entry_created_at'] ?? '' ) );
		}
		return (int) ( $row['_credential_sort_at'] ?? 0 );
	}

	/** @return int */
	private function sortable_date( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) { return 0; }
		$time = strtotime( $value . ( false === strpos( $value, ':' ) ? ' 00:00:00' : '' ) . ' UTC' );
		return false === $time ? 0 : (int) $time;
	}

	/** No-expiry values stay after dated expiries. @return array{0:int,1:int} */
	private function sortable_expiry( $value ) {
		$value = trim( (string) $value );
		return '' === $value ? array( 1, 0 ) : array( 0, $this->sortable_date( $value ) );
	}

	/** @return int */
	private function compare_sort_values( $left, $right, $orderby ) {
		if ( 'expiry_date' === $orderby ) {
			$left = is_array( $left ) ? $left : array( 1, 0 );
			$right = is_array( $right ) ? $right : array( 1, 0 );
			$null_compare = (int) ( $left[0] ?? 1 ) <=> (int) ( $right[0] ?? 1 );
			return 0 !== $null_compare ? $null_compare : ( (int) ( $left[1] ?? 0 ) <=> (int) ( $right[1] ?? 0 ) );
		}
		if ( in_array( $orderby, array( 'issue_date', 'version', 'added' ), true ) ) { return (int) $left <=> (int) $right; }
		return strnatcasecmp( (string) $left, (string) $right );
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
		$row['_credential_source_label'] = __( 'Legacy', 'certloom' );
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
