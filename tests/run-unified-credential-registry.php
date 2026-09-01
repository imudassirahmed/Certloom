<?php
/** UX-CORE-04 — one Credentials registry adapter across current + historical storage. */
namespace {
	define( 'ABSPATH', __DIR__ . '/fake-wp/' );
	class WP_Error {
		private $code; private $message;
		public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
		public function get_error_message() { return $this->message; }
	}
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	function absint( $value ) { return abs( (int) $value ); }
	function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }
	function __( $text ) { return $text; }

	$GLOBALS['certloom_current_rows'] = array();
	for ( $i = 1; $i <= 140; $i++ ) {
		$day = 1 + ( ( 140 - $i ) % 28 );
		$GLOBALS['certloom_current_rows'][] = array(
			'series_id' => $i,
			'public_id' => 'pub-' . $i,
			'display_number' => 'CUR-' . $i,
			'display_name' => 'Current Recipient ' . $i,
			'recipient_email' => 'current' . $i . '@example.test',
			'program_id' => 7,
			'program_title' => 'Current Program',
			'batch_name' => '',
			'issued_at' => sprintf( '2026-08-%02d', $day ),
			'expires_at' => '',
			'version_number' => 1,
			'series_updated_at' => sprintf( '2026-08-%02d 12:%02d:00', $day, $i % 60 ),
			'status_code' => ( 5 === $i ? 'revoked' : 'valid' ),
			'source_type' => ( 6 === $i ? 'certloom_legacy' : 'manual' ),
		);
	}
	usort( $GLOBALS['certloom_current_rows'], static function ( $a, $b ) { return strcmp( $b['series_updated_at'], $a['series_updated_at'] ); } );

	$GLOBALS['certloom_legacy_rows'] = array();
	for ( $i = 1; $i <= 35; $i++ ) {
		$day = 1 + ( ( 35 - $i ) % 28 );
		$GLOBALS['certloom_legacy_rows'][] = array(
			'id' => $i,
			'cert_number' => 'OLD-' . $i,
			'holder_name' => 'Historical Recipient ' . $i,
			'status' => ( 3 === $i ? 'withdrawn' : 'valid' ),
			'issue_date' => sprintf( '2026-08-%02d', $day ),
			'expiry_date' => '',
			'created_at' => sprintf( '2026-08-%02d 18:%02d:00', $day, $i % 60 ),
		);
	}
	usort( $GLOBALS['certloom_legacy_rows'], static function ( $a, $b ) { return strcmp( $b['created_at'], $a['created_at'] ); } );

	class Ecapla_CertVerify_Database {
		public static function get_list( $args ) {
			$rows = $GLOBALS['certloom_legacy_rows'];
			$search = trim( (string) ( $args['search'] ?? '' ) );
			if ( '' !== $search ) { $rows = array_values( array_filter( $rows, static function ( $row ) use ( $search ) { return false !== stripos( $row['cert_number'], $search ) || false !== stripos( $row['holder_name'], $search ); } ) ); }
			$status = (string) ( $args['status'] ?? '' );
			if ( '' !== $status ) { $rows = array_values( array_filter( $rows, static function ( $row ) use ( $status ) { return self::effective_status( $row ) === $status; } ) ); }
			$total = count( $rows );
			$per = max( 1, (int) ( $args['per_page'] ?? 20 ) ); $page = max( 1, (int) ( $args['page'] ?? 1 ) );
			return array( 'items' => array_slice( $rows, ( $page - 1 ) * $per, $per ), 'total' => $total );
		}
		public static function effective_status( $row ) { return (string) ( $row['status'] ?? 'valid' ); }
		public static function get_dashboard_metrics() { return array( 'total' => 35, 'valid' => 34, 'suspended' => 0, 'withdrawn' => 1, 'expired' => 0 ); }
		public static function get_by_id( $id ) { foreach ( $GLOBALS['certloom_legacy_rows'] as $row ) { if ( (int) $row['id'] === (int) $id ) { return $row; } } return null; }
	}
}

namespace Certloom\Application\Migration {
	class LegacyMigrationState {}
	class DomainCutoverState {
		public function is_domain_authoritative() { return ! empty( $GLOBALS['certloom_domain_authoritative'] ); }
		public function receipt_matches_completed_migration( $migration = null ) { return ! empty( $GLOBALS['certloom_domain_authoritative'] ); }
	}
}

namespace Certloom\Infrastructure\Repository {
	class CredentialRepository {
		public function list_management_page( $search = '', $page = 1, $per_page = 50, $status = 'all', $program_id = 0, $exclude_source_type = '' ) {
			$rows = $GLOBALS['certloom_current_rows'];
			$search = trim( (string) $search );
			if ( '' !== $search ) { $rows = array_values( array_filter( $rows, static function ( $row ) use ( $search ) { return false !== stripos( $row['display_number'], $search ) || false !== stripos( $row['display_name'], $search ); } ) ); }
			if ( 'all' !== $status ) { $rows = array_values( array_filter( $rows, static function ( $row ) use ( $status ) { return (string) $row['status_code'] === (string) $status; } ) ); }
			if ( $program_id ) { $rows = array_values( array_filter( $rows, static function ( $row ) use ( $program_id ) { return (int) $row['program_id'] === (int) $program_id; } ) ); }
			if ( '' !== (string) $exclude_source_type ) { $rows = array_values( array_filter( $rows, static function ( $row ) use ( $exclude_source_type ) { return (string) ( $row['source_type'] ?? '' ) !== (string) $exclude_source_type; } ) ); }
			$total = count( $rows ); $page = max( 1, (int) $page ); $per_page = max( 1, (int) $per_page );
			return array( 'items' => array_slice( $rows, ( $page - 1 ) * $per_page, $per_page ), 'total' => $total );
		}
		public function management_summary( $exclude_source_type = '' ) { return '' !== (string) $exclude_source_type ? array( 'total' => 139, 'valid' => 138, 'suspended' => 0, 'revoked' => 1, 'expired' => 0, 'not_yet_valid' => 0, 'pending_review' => 0, 'noncompliant' => 0, 'voided' => 0, 'not_issued' => 0 ) : array( 'total' => 140, 'valid' => 139, 'suspended' => 0, 'revoked' => 1, 'expired' => 0, 'not_yet_valid' => 0, 'pending_review' => 0, 'noncompliant' => 0, 'voided' => 0, 'not_issued' => 0 ); }
	}
}

namespace {
	require dirname( __DIR__ ) . '/src/Infrastructure/Repository/UnifiedCredentialRegistry.php';
	$pass = 0; $fail = 0;
	function ucr( $ok, $label ) { global $pass, $fail; if ( $ok ) { $pass++; echo "PASS  {$label}\n"; } else { $fail++; echo "FAIL  {$label}\n"; } }
	$registry = new \Certloom\Infrastructure\Repository\UnifiedCredentialRegistry();

	$first = $registry->list_page( '', 1, 12, 'all', 0 );
	ucr( ! is_wp_error( $first ) && 174 === (int) $first['total'] && 12 === count( $first['items'] ), 'Unified Credentials total and first page include current + historical storage' );
	$times = array_column( $first['items'], '_credential_sort_at' ); $sorted = $times; rsort( $sorted, SORT_NUMERIC );
	ucr( $times === $sorted, 'Unified Credentials are merged in one descending chronology' );
	ucr( count( array_unique( array_column( $first['items'], '_credential_source' ) ) ) > 1, 'Current and Historical records can appear together on the same page' );
	ucr( in_array( 'Historical', array_column( $first['items'], '_credential_source_label' ), true ) && in_array( 'Current', array_column( $first['items'], '_credential_source_label' ), true ), 'Merged rows carry explicit Current/Historical labels' );

	$deep = $registry->list_page( '', 12, 10, 'all', 0 );
	ucr( ! is_wp_error( $deep ) && 10 === count( $deep['items'] ), 'Deep merged pagination is complete beyond the first repository chunk' );
	$all_expected = array();
	foreach ( $GLOBALS['certloom_current_rows'] as $row ) { if ( 'certloom_legacy' === (string) ( $row['source_type'] ?? '' ) ) { continue; } $all_expected[] = array( 'key' => 'current:' . $row['series_id'], 'time' => strtotime( $row['series_updated_at'] . ' UTC' ) ); }
	foreach ( $GLOBALS['certloom_legacy_rows'] as $row ) { $all_expected[] = array( 'key' => 'historical:' . $row['id'], 'time' => strtotime( $row['created_at'] . ' UTC' ) ); }
	usort( $all_expected, static function ( $a, $b ) { return $b['time'] <=> $a['time'] ?: strcmp( $b['key'], $a['key'] ); } );
	$expected_keys = array_column( array_slice( $all_expected, 110, 10 ), 'key' );
	$actual_keys = array_map( static function ( $row ) { return $row['_credential_source'] . ':' . ( $row['_credential_source'] === 'historical' ? $row['legacy_id'] : $row['series_id'] ); }, $deep['items'] );
	ucr( $expected_keys === $actual_keys, 'Deep pagination uses the exact globally merged chronology rather than concatenated pages' );

	$program = $registry->list_page( '', 1, 20, 'all', 7 );
	ucr( ! is_wp_error( $program ) && 139 === (int) $program['total'] && ! in_array( 'historical', array_column( $program['items'], '_credential_source' ), true ), 'Program filter does not invent historical Program relationships' );
	$revoked = $registry->list_page( '', 1, 20, 'revoked', 0 );
	ucr( ! is_wp_error( $revoked ) && 2 === (int) $revoked['total'], 'Revoked filter merges normalized revoked with historical withdrawn semantics' );
	ucr( in_array( 'revoked', array_column( $revoked['items'], 'legacy_status_code' ), true ), 'Historical withdrawn status is presented as Revoked in canonical Credentials vocabulary' );
	$summary = $registry->summary();
	ucr( ! is_wp_error( $summary ) && 174 === (int) $summary['total'] && 172 === (int) $summary['valid'] && 2 === (int) $summary['revoked'], 'Dashboard summary merges historical totals without exposing a second registry' );
	$historical = $registry->find_historical( 3 );
	ucr( is_array( $historical ) && 'historical' === $historical['_credential_source'] && 'revoked' === $historical['legacy_status_code'], 'Historical detail lookup remains read-only and uses canonical status vocabulary' );

	$GLOBALS['certloom_domain_authoritative'] = true;
	$post_cutover = $registry->list_page( '', 1, 175, 'all', 0 );
	ucr( ! is_wp_error( $post_cutover ) && 140 === (int) $post_cutover['total'], 'After official cutover the duplicate historical storage is omitted and migrated Series become the sole rows' );
	$migrated = array_values( array_filter( $post_cutover['items'], static function ( $row ) { return 6 === (int) ( $row['series_id'] ?? 0 ); } ) );
	ucr( 1 === count( $migrated ) && 'current' === $migrated[0]['_credential_source'] && 'Historical' === $migrated[0]['_credential_source_label'], 'Migrated Series stay fully manageable while retaining the Historical customer label after cutover' );
	$GLOBALS['certloom_domain_authoritative'] = false;

	$source = file_get_contents( dirname( __DIR__ ) . '/src/Infrastructure/Repository/UnifiedCredentialRegistry.php' );
	ucr( false === strpos( $source, '500-row' ) && false === strpos( $source, 'array_slice( $items, 0, 500' ), 'Unified adapter has no artificial 500-record merge ceiling' );

	printf( "\n%d UX-CORE-04 unified-registry checks, %d failures\n", $pass + $fail, $fail );
	exit( $fail ? 1 : 0 );
}
