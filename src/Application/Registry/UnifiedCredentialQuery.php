<?php
namespace Certloom\Application\Registry;

use Certloom\Infrastructure\Database\SchemaManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One credential list across both storage generations.
 *
 * The legacy 1.5 registry and the normalized Series/Version domain are two
 * separate tables. Administrators should not have to know that, so this
 * builds a single sorted, filtered, paginated list over both.
 *
 * Sorting and pagination happen inside a UNION ALL over a thin projection
 * rather than by loading both sources into PHP. That keeps the query flat
 * on installs with tens of thousands of records, where a merge-in-memory
 * approach would exhaust the request.
 *
 * This class never writes. It is safe while the normalized domain is still
 * behind its release gate, and it does not depend on that gate: when the
 * domain tables are absent it simply returns the legacy side.
 */
final class UnifiedCredentialQuery {
	const ORIGIN_LEGACY = 'legacy';
	const ORIGIN_DOMAIN = 'domain';

	const MAX_PER_PAGE = 200;

	/** @var array<int,string> */
	private static $sortable = array( 'created_at', 'display_number', 'holder_name', 'issue_date', 'expiry_date' );

	/**
	 * @param array<string,mixed> $args
	 * @return array{items:array<int,array<string,mixed>>,total:int,page:int,per_page:int,pages:int,sources:array<string,int>}|\WP_Error
	 */
	public function list_page( array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'search'      => '',
				'status'      => '',
				'origin'      => '',
				'issue_from'  => '',
				'issue_to'    => '',
				'expiry_from' => '',
				'expiry_to'   => '',
				'orderby'     => 'created_at',
				'order'       => 'DESC',
				'per_page'    => 20,
				'page'        => 1,
			)
		);

		$orderby  = in_array( (string) $args['orderby'], self::$sortable, true ) ? (string) $args['orderby'] : 'created_at';
		$order    = 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';
		$per_page = min( self::MAX_PER_PAGE, max( 1, absint( $args['per_page'] ) ) );
		$page     = max( 1, absint( $args['page'] ) );
		$offset   = ( $page - 1 ) * $per_page;
		$origin   = sanitize_key( (string) $args['origin'] );

		$parts  = array();
		$params = array();

		if ( self::ORIGIN_DOMAIN !== $origin ) {
			$legacy = $this->legacy_select( $args );
			if ( is_wp_error( $legacy ) ) {
				return $legacy;
			}
			if ( is_array( $legacy ) ) {
				$parts[]  = $legacy['sql'];
				$params   = array_merge( $params, $legacy['params'] );
			}
		}

		if ( self::ORIGIN_LEGACY !== $origin && $this->domain_tables_present() ) {
			$domain = $this->domain_select( $args );
			if ( is_wp_error( $domain ) ) {
				return $domain;
			}
			if ( is_array( $domain ) ) {
				$parts[] = $domain['sql'];
				$params  = array_merge( $params, $domain['params'] );
			}
		}

		if ( ! $parts ) {
			return array( 'items' => array(), 'total' => 0, 'page' => 1, 'per_page' => $per_page, 'pages' => 0, 'sources' => array() );
		}

		$union = '(' . implode( ') UNION ALL (', $parts ) . ')';

		/*
		 * COUNT and the page share one projection, so a record can never
		 * appear in the total but not on any page.
		 */
		$count_sql = "SELECT COUNT(*) FROM ( $union ) AS certloom_unified";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Every value is bound below; identifiers come from a fixed allowlist.
		$total = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, $params ) : $count_sql );
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_unified_count_failed', __( 'Certloom could not count the combined credential list.', 'certloom' ) );
		}

		$source_sql = "SELECT origin, COUNT(*) AS n FROM ( $union ) AS certloom_unified GROUP BY origin";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- See above.
		$source_rows = $wpdb->get_results( $params ? $wpdb->prepare( $source_sql, $params ) : $source_sql, ARRAY_A );
		$sources = array();
		foreach ( (array) $source_rows as $row ) {
			$sources[ (string) $row['origin'] ] = (int) $row['n'];
		}

		/*
		 * origin is the tiebreaker so that two records sharing a sort value
		 * keep a stable order between pages.
		 */
		$page_sql    = "SELECT * FROM ( $union ) AS certloom_unified ORDER BY $orderby $order, origin ASC, source_id $order LIMIT %d OFFSET %d";
		$page_params = array_merge( $params, array( $per_page, $offset ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- See above.
		$rows = $wpdb->get_results( $wpdb->prepare( $page_sql, $page_params ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_unified_page_failed', __( 'Certloom could not read the combined credential list.', 'certloom' ) );
		}

		return array(
			'items'    => array_map( array( $this, 'normalize_row' ), (array) $rows ),
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'pages'    => (int) ceil( $total / $per_page ),
			'sources'  => $sources,
		);
	}

	/**
	 * Legacy 1.5 registry projected into the shared shape.
	 *
	 * @param array<string,mixed> $args
	 * @return array{sql:string,params:array<int,mixed>}|null|\WP_Error
	 */
	private function legacy_select( array $args ) {
		global $wpdb;

		$table = \Ecapla_CertVerify_Database::table_name();
		$where  = array( '1=1' );
		$params = array();

		$search = $this->search_term( $args['search'] );
		if ( '' !== $search ) {
			$where[]  = '(l.cert_number LIKE %s OR l.holder_name LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$params[] = $like;
			$params[] = $like;
		}

		$status = sanitize_key( (string) $args['status'] );
		if ( '' !== $status ) {
			/*
			 * Legacy stores four statuses. Domain verdicts that have no legacy
			 * equivalent must exclude the legacy side entirely rather than
			 * silently matching nothing meaningful.
			 */
			$map = array(
				'valid'     => 'valid',
				'expired'   => 'expired',
				'suspended' => 'suspended',
				'withdrawn' => 'withdrawn',
				'revoked'   => 'withdrawn',
			);
			if ( ! isset( $map[ $status ] ) ) {
				return null;
			}
			$where[]  = 'l.status = %s';
			$params[] = $map[ $status ];
		}

		foreach ( array( 'issue_from' => array( 'l.issue_date', '>=' ), 'issue_to' => array( 'l.issue_date', '<=' ), 'expiry_from' => array( 'l.expiry_date', '>=' ), 'expiry_to' => array( 'l.expiry_date', '<=' ) ) as $key => $clause ) {
			$date = $this->date( $args[ $key ] );
			if ( '' !== $date ) {
				$where[]  = $clause[0] . ' ' . $clause[1] . ' %s';
				$params[] = $date;
			}
		}

		$sql = "SELECT
				'" . self::ORIGIN_LEGACY . "' AS origin,
				l.id AS source_id,
				l.cert_number AS display_number,
				'' AS public_id,
				l.holder_name AS holder_name,
				'' AS recipient_email,
				l.status AS status_key,
				l.status_source AS status_source,
				l.issue_date AS issue_date,
				l.expiry_date AS expiry_date,
				l.created_at AS created_at,
				0 AS version_number,
				NULL AS program_id,
				NULL AS batch_id
			FROM $table AS l
			WHERE " . implode( ' AND ', $where );

		return array( 'sql' => $sql, 'params' => $params );
	}

	/**
	 * Normalized Series/Version domain projected into the same shape.
	 *
	 * Only the current Version of each Series is listed; historical Versions
	 * belong to the credential workspace, not the registry list.
	 *
	 * @param array<string,mixed> $args
	 * @return array{sql:string,params:array<int,mixed>}|null|\WP_Error
	 */
	private function domain_select( array $args ) {
		global $wpdb;

		$series     = SchemaManager::table( 'credential_series' );
		$versions   = SchemaManager::table( 'credential_versions' );
		$recipients = SchemaManager::table( 'recipients' );

		$where  = array( 's.current_version_id IS NOT NULL' );
		$params = array();

		$search = $this->search_term( $args['search'] );
		if ( '' !== $search ) {
			$where[]  = '(v.display_number LIKE %s OR r.display_name LIKE %s OR r.email LIKE %s OR s.public_id LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$params   = array_merge( $params, array( $like, $like, $like, $like ) );
		}

		$status = sanitize_key( (string) $args['status'] );
		if ( '' !== $status ) {
			$clause = $this->domain_status_clause( $status );
			if ( null === $clause ) {
				return null;
			}
			$where[] = $clause['sql'];
			$params  = array_merge( $params, $clause['params'] );
		}

		foreach ( array( 'issue_from' => array( 'v.issued_at', '>=' ), 'issue_to' => array( 'v.issued_at', '<=' ), 'expiry_from' => array( 'v.expires_at', '>=' ), 'expiry_to' => array( 'v.expires_at', '<=' ) ) as $key => $clause ) {
			$date = $this->date( $args[ $key ] );
			if ( '' !== $date ) {
				$where[]  = $clause[0] . ' ' . $clause[1] . ' %s';
				$params[] = 'issued_at' === substr( $clause[0], 2 ) && 'issue_to' === $key ? $date . ' 23:59:59' : $date;
			}
		}

		/*
		 * status_key is derived in SQL only far enough to sort and filter.
		 * The authoritative verdict still comes from StatusService when a row
		 * is rendered, so this never becomes a second status implementation.
		 */
		$sql = "SELECT
				'" . self::ORIGIN_DOMAIN . "' AS origin,
				s.id AS source_id,
				v.display_number AS display_number,
				s.public_id AS public_id,
				r.display_name AS holder_name,
				COALESCE(r.email, '') AS recipient_email,
				CASE
					WHEN s.administrative_state = 'revoked' THEN 'revoked'
					WHEN s.administrative_state = 'suspended' THEN 'suspended'
					WHEN v.issuance_state = 'voided' THEN 'voided'
					WHEN v.issuance_state <> 'issued' THEN 'not_issued'
					WHEN v.expires_at IS NOT NULL AND v.expires_at < CURDATE() THEN 'expired'
					WHEN v.valid_from IS NOT NULL AND v.valid_from > CURDATE() THEN 'not_yet_valid'
					WHEN s.compliance_state = 'pending' THEN 'pending_review'
					WHEN s.compliance_state = 'noncompliant' THEN 'suspended_noncompliant'
					ELSE 'valid'
				END AS status_key,
				'domain' AS status_source,
				DATE(v.issued_at) AS issue_date,
				v.expires_at AS expiry_date,
				s.created_at AS created_at,
				v.version_number AS version_number,
				s.program_id AS program_id,
				s.batch_id AS batch_id
			FROM $series AS s
			INNER JOIN $versions AS v ON v.id = s.current_version_id
			LEFT JOIN $recipients AS r ON r.id = s.recipient_id
			WHERE " . implode( ' AND ', $where );

		return array( 'sql' => $sql, 'params' => $params );
	}

	/**
	 * @param string $status
	 * @return array{sql:string,params:array<int,mixed>}|null
	 */
	private function domain_status_clause( $status ) {
		switch ( $status ) {
			case 'revoked':
			case 'withdrawn':
				return array( 'sql' => "s.administrative_state = 'revoked'", 'params' => array() );
			case 'suspended':
				return array( 'sql' => "s.administrative_state = 'suspended'", 'params' => array() );
			case 'voided':
				return array( 'sql' => "v.issuance_state = 'voided'", 'params' => array() );
			case 'not_issued':
				return array( 'sql' => "v.issuance_state NOT IN ('issued','voided')", 'params' => array() );
			case 'expired':
				return array( 'sql' => "s.administrative_state = 'active' AND v.issuance_state = 'issued' AND v.expires_at IS NOT NULL AND v.expires_at < CURDATE()", 'params' => array() );
			case 'not_yet_valid':
				return array( 'sql' => "s.administrative_state = 'active' AND v.issuance_state = 'issued' AND v.valid_from IS NOT NULL AND v.valid_from > CURDATE()", 'params' => array() );
			case 'pending_review':
				return array( 'sql' => "s.compliance_state = 'pending'", 'params' => array() );
			case 'valid':
				return array( 'sql' => "s.administrative_state = 'active' AND v.issuance_state = 'issued' AND (v.expires_at IS NULL OR v.expires_at >= CURDATE()) AND (v.valid_from IS NULL OR v.valid_from <= CURDATE()) AND s.compliance_state NOT IN ('pending','noncompliant')", 'params' => array() );
			default:
				return null;
		}
	}

	/** @return bool */
	private function domain_tables_present() {
		global $wpdb;
		foreach ( array( 'credential_series', 'credential_versions', 'recipients' ) as $name ) {
			try {
				$table = SchemaManager::table( $name );
			} catch ( \Throwable $error ) {
				return false;
			}
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				return false;
			}
		}
		return true;
	}

	/** @return string */
	private function search_term( $raw ) {
		$search = trim( sanitize_text_field( (string) $raw ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $search, 0, 100 ) : substr( $search, 0, 100 );
	}

	/** @return string */
	private function date( $raw ) {
		$value = trim( (string) $raw );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function normalize_row( array $row ) {
		$origin = self::ORIGIN_DOMAIN === (string) ( $row['origin'] ?? '' ) ? self::ORIGIN_DOMAIN : self::ORIGIN_LEGACY;
		return array(
			'origin'          => $origin,
			'is_legacy'       => self::ORIGIN_LEGACY === $origin,
			'source_id'       => absint( $row['source_id'] ?? 0 ),
			'display_number'  => (string) ( $row['display_number'] ?? '' ),
			'public_id'       => (string) ( $row['public_id'] ?? '' ),
			'holder_name'     => (string) ( $row['holder_name'] ?? '' ),
			'recipient_email' => (string) ( $row['recipient_email'] ?? '' ),
			'status_key'      => (string) ( $row['status_key'] ?? 'valid' ),
			'status_source'   => (string) ( $row['status_source'] ?? '' ),
			'issue_date'      => (string) ( $row['issue_date'] ?? '' ),
			'expiry_date'     => (string) ( $row['expiry_date'] ?? '' ),
			'created_at'      => (string) ( $row['created_at'] ?? '' ),
			'version_number'  => absint( $row['version_number'] ?? 0 ),
			'program_id'      => absint( $row['program_id'] ?? 0 ),
			'batch_id'        => absint( $row['batch_id'] ?? 0 ),
		);
	}
}
