<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Administrator-triggered, isolated database capability proof.
 *
 * The proof never reads or writes certificate records and never provisions the
 * gated Credential Series domain. It creates one reserved scratch table,
 * exercises the database guarantees Certloom will eventually require, and
 * removes that scratch table before returning.
 */
class Ecapla_CertVerify_Database_Proof {

	const LOCK_OPTION   = 'certloom_database_proof_lock';
	const LAST_OPTION   = 'certloom_database_proof_last';
	const RESULT_SCHEMA = 'certloom-database-proof';
	const RESULT_VERSION = 1;
	const LOCK_TTL      = 2 * MINUTE_IN_SECONDS;

	/**
	 * Runs and stores the isolated proof.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public static function run() {
		global $wpdb;

		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return new WP_Error( 'certloom_database_proof_unavailable', __( 'Certloom could not access the WordPress database connection for the isolated proof.', 'certloom' ) );
		}

		$maintenance = self::maintenance_available();
		if ( is_wp_error( $maintenance ) ) {
			return $maintenance;
		}

		$owner = self::acquire_lock();
		if ( is_wp_error( $owner ) ) {
			return $owner;
		}

		$result = self::new_result();
		$table  = self::table_name();
		$old_suppress = method_exists( $wpdb, 'suppress_errors' ) ? $wpdb->suppress_errors( true ) : null;

		try {
			$preclean = self::drop_scratch_table( $table );
			if ( is_wp_error( $preclean ) ) {
				self::add_check( $result, 'scratch_preclean', 'fail', __( 'Scratch workspace cleanup', 'certloom' ), __( 'Certloom could not clear an abandoned database-proof workspace.', 'certloom' ) );
			} else {
				self::add_check( $result, 'scratch_preclean', 'pass', __( 'Scratch workspace cleanup', 'certloom' ), __( 'No abandoned database-proof workspace remains.', 'certloom' ) );
			}

			if ( ! self::has_failures( $result ) ) {
				self::run_schema_checks( $result, $table );
			}
			if ( ! self::has_failures( $result ) ) {
				self::run_transaction_checks( $result, $table );
			}
			if ( ! self::has_failures( $result ) ) {
				self::run_row_lock_check( $result, $table );
			}
		} catch ( Throwable $error ) {
			self::add_check( $result, 'unexpected', 'fail', __( 'Database proof execution', 'certloom' ), __( 'The isolated database proof encountered an unexpected error and stopped safely.', 'certloom' ) );
		}

		$cleanup = self::drop_scratch_table( $table );
		if ( is_wp_error( $cleanup ) ) {
			self::add_check( $result, 'scratch_cleanup', 'fail', __( 'Scratch workspace removal', 'certloom' ), __( 'The database proof finished, but its empty scratch table could not be removed. Run the proof again after database maintenance.', 'certloom' ) );
		} else {
			self::add_check( $result, 'scratch_cleanup', 'pass', __( 'Scratch workspace removal', 'certloom' ), __( 'The isolated scratch table was removed.', 'certloom' ) );
		}

		if ( method_exists( $wpdb, 'suppress_errors' ) && null !== $old_suppress ) {
			$wpdb->suppress_errors( (bool) $old_suppress );
		}

		$release = self::release_lock( $owner );
		if ( is_wp_error( $release ) ) {
			self::add_check( $result, 'proof_lock_cleanup', 'fail', __( 'Proof lock cleanup', 'certloom' ), __( 'The proof completed but its short-lived execution lock could not be released safely.', 'certloom' ) );
		} else {
			self::add_check( $result, 'proof_lock_cleanup', 'pass', __( 'Proof lock cleanup', 'certloom' ), __( 'The proof execution lock was released.', 'certloom' ) );
		}

		$result['overall'] = self::overall_status( $result );
		$result['checked_at'] = time();
		$saved = update_option( self::LAST_OPTION, $result, false );
		if ( ! $saved && $result !== get_option( self::LAST_OPTION, null ) ) {
			return new WP_Error( 'certloom_database_proof_result_save_failed', __( 'The database proof ran, but WordPress could not save its non-sensitive result. No certificate data was changed.', 'certloom' ) );
		}

		return $result;
	}

	/** @return array<string,mixed>|null */
	public static function get_last_result() {
		$result = get_option( self::LAST_OPTION, null );
		if ( ! is_array( $result ) || self::RESULT_SCHEMA !== (string) ( $result['schema'] ?? '' ) || self::RESULT_VERSION !== (int) ( $result['version'] ?? 0 ) || ! isset( $result['checks'] ) || ! is_array( $result['checks'] ) ) {
			return null;
		}
		$overall = sanitize_key( (string) ( $result['overall'] ?? '' ) );
		$version = sanitize_text_field( (string) ( $result['plugin_version'] ?? '' ) );
		$checked_at = absint( $result['checked_at'] ?? 0 );
		if ( ! in_array( $overall, array( 'passed', 'incomplete', 'failed' ), true ) || '' === $version || ! $checked_at || empty( $result['checks'] ) ) {
			return null;
		}
		foreach ( $result['checks'] as $check ) {
			if ( ! is_array( $check ) || '' === sanitize_key( (string) ( $check['id'] ?? '' ) ) || ! in_array( (string) ( $check['status'] ?? '' ), array( 'pass', 'warning', 'fail' ), true ) || '' === trim( (string) ( $check['label'] ?? '' ) ) || '' === trim( (string) ( $check['message'] ?? '' ) ) ) {
				return null;
			}
		}
		if ( self::overall_status( $result ) !== $overall ) {
			return null;
		}
		return $result;
	}

	/**
	 * Returns the current readiness state of the stored isolated proof.
	 *
	 * This centralizes version/time freshness so System Status and stronger
	 * acceptance proofs cannot accidentally use different trust rules.
	 *
	 * @return string passed|failed|incomplete|stale|not_run
	 */
	public static function evidence_state() {
		$result = self::get_last_result();
		if ( ! is_array( $result ) ) {
			return 'not_run';
		}

		$overall = sanitize_key( (string) ( $result['overall'] ?? 'incomplete' ) );
		if ( 'passed' !== $overall ) {
			return in_array( $overall, array( 'failed', 'incomplete' ), true ) ? $overall : 'incomplete';
		}

		$proof_version   = sanitize_text_field( (string) ( $result['plugin_version'] ?? '' ) );
		$current_version = defined( 'ECAPLA_CERTVERIFY_VERSION' ) ? (string) ECAPLA_CERTVERIFY_VERSION : '';
		$checked_at      = absint( $result['checked_at'] ?? 0 );
		if ( '' === $proof_version || '' === $current_version || ! hash_equals( $current_version, $proof_version ) ) {
			return 'stale';
		}
		if ( ! $checked_at || $checked_at > ( time() + 300 ) || ( time() - $checked_at ) > ( 7 * DAY_IN_SECONDS ) ) {
			return 'stale';
		}
		return 'passed';
	}

	/** @return bool */
	public static function is_current_passed() {
		return 'passed' === self::evidence_state();
	}

	/** Returns a privacy-safe text report for support and acceptance evidence. */
	public static function get_text_report( $result = null ) {
		$result = is_array( $result ) ? $result : self::get_last_result();
		if ( ! is_array( $result ) ) {
			return __( 'Certloom database proof: not run.', 'certloom' );
		}
		$lines = array( sprintf( 'Certloom database proof: %s', strtoupper( sanitize_key( (string) ( $result['overall'] ?? 'incomplete' ) ) ) ) );
		if ( ! empty( $result['plugin_version'] ) ) {
			$lines[] = sprintf( 'Certloom version: %s', sanitize_text_field( (string) $result['plugin_version'] ) );
		}
		if ( ! empty( $result['checked_at'] ) ) {
			$lines[] = sprintf( 'Checked UTC: %s', gmdate( 'Y-m-d H:i:s', absint( $result['checked_at'] ) ) );
		}
		foreach ( (array) $result['checks'] as $check ) {
			if ( ! is_array( $check ) ) {
				continue;
			}
			$lines[] = sprintf( '[%s] %s: %s', strtoupper( sanitize_key( (string) ( $check['status'] ?? 'warning' ) ) ), (string) ( $check['label'] ?? '' ), (string) ( $check['message'] ?? '' ) );
		}
		return implode( "\n", $lines );
	}

	/** @return array<string,mixed> */
	private static function new_result() {
		return array(
			'schema'          => self::RESULT_SCHEMA,
			'version'         => self::RESULT_VERSION,
			'plugin_version'  => defined( 'ECAPLA_CERTVERIFY_VERSION' ) ? (string) ECAPLA_CERTVERIFY_VERSION : '',
			'overall'         => 'incomplete',
			'checked_at'      => 0,
			'checks'          => array(),
		);
	}

	private static function run_schema_checks( &$result, $table ) {
		global $wpdb;
		if ( ! function_exists( 'dbDelta' ) ) {
			$upgrade = trailingslashit( ABSPATH ) . 'wp-admin/includes/upgrade.php';
			if ( ! is_readable( $upgrade ) ) {
				self::add_check( $result, 'dbdelta', 'fail', __( 'WordPress dbDelta()', 'certloom' ), __( 'WordPress database-upgrade helpers are unavailable.', 'certloom' ) );
				return;
			}
			require_once $upgrade;
		}
		if ( ! function_exists( 'dbDelta' ) ) {
			self::add_check( $result, 'dbdelta', 'fail', __( 'WordPress dbDelta()', 'certloom' ), __( 'WordPress dbDelta() could not be loaded.', 'certloom' ) );
			return;
		}

		$charset = method_exists( $wpdb, 'get_charset_collate' ) ? $wpdb->get_charset_collate() : '';
		$sql = "CREATE TABLE $table (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			probe_key VARCHAR(64) NOT NULL,
			probe_value VARCHAR(191) NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY probe_key (probe_key),
			KEY probe_value (probe_value)
		) ENGINE=InnoDB $charset;";
		$wpdb->last_error = '';
		dbDelta( $sql );
		if ( '' !== (string) $wpdb->last_error ) {
			self::add_check( $result, 'dbdelta', 'fail', __( 'WordPress dbDelta()', 'certloom' ), __( 'dbDelta() could not create the isolated proof table.', 'certloom' ) );
			return;
		}

		$status = self::table_status( $table );
		if ( is_wp_error( $status ) ) {
			self::add_check( $result, 'dbdelta', 'fail', __( 'WordPress dbDelta()', 'certloom' ), __( 'The isolated proof table was not readable after dbDelta().', 'certloom' ) );
			return;
		}
		self::add_check( $result, 'dbdelta', 'pass', __( 'WordPress dbDelta()', 'certloom' ), __( 'WordPress created the isolated proof table successfully.', 'certloom' ) );

		$engine = strtolower( (string) ( $status['Engine'] ?? '' ) );
		self::add_check(
			$result,
			'innodb',
			'innodb' === $engine ? 'pass' : 'fail',
			__( 'InnoDB storage engine', 'certloom' ),
			'innodb' === $engine ? __( 'The proof table uses InnoDB.', 'certloom' ) : __( 'The host did not create the proof table with InnoDB, so Certloom cannot rely on transactional credential updates.', 'certloom' )
		);

		$indexes = self::index_status( $table );
		if ( is_wp_error( $indexes ) ) {
			self::add_check( $result, 'indexes', 'fail', __( 'Database indexes', 'certloom' ), __( 'Certloom could not inspect the proof-table indexes.', 'certloom' ) );
			return;
		}
		$primary = false;
		$unique  = false;
		$lookup  = false;
		foreach ( $indexes as $index ) {
			$name = (string) ( $index['Key_name'] ?? '' );
			if ( 'PRIMARY' === $name ) {
				$primary = true;
			}
			if ( 'probe_key' === $name && 0 === (int) ( $index['Non_unique'] ?? 1 ) ) {
				$unique = true;
			}
			if ( 'probe_value' === $name ) {
				$lookup = true;
			}
		}
		self::add_check( $result, 'indexes', $primary && $unique && $lookup ? 'pass' : 'fail', __( 'Database indexes', 'certloom' ), $primary && $unique && $lookup ? __( 'Primary, unique, and lookup indexes were created as requested.', 'certloom' ) : __( 'One or more required proof-table indexes are missing.', 'certloom' ) );

		if ( self::has_failures( $result ) ) {
			return;
		}

		$wpdb->last_error = '';
		$first = $wpdb->insert( $table, array( 'probe_key' => 'unique-proof', 'probe_value' => 'first', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
		if ( false === $first || '' !== (string) $wpdb->last_error ) {
			self::add_check( $result, 'unique_constraint', 'fail', __( 'Unique constraint enforcement', 'certloom' ), __( 'Certloom could not insert the first isolated uniqueness row.', 'certloom' ) );
			return;
		}
		$wpdb->last_error = '';
		$duplicate = $wpdb->insert( $table, array( 'probe_key' => 'unique-proof', 'probe_value' => 'duplicate', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
		$duplicate_rejected = false === $duplicate && '' !== (string) $wpdb->last_error;
		$wpdb->last_error = '';
		self::add_check( $result, 'unique_constraint', $duplicate_rejected ? 'pass' : 'fail', __( 'Unique constraint enforcement', 'certloom' ), $duplicate_rejected ? __( 'The database rejected a duplicate key as required.', 'certloom' ) : __( 'The database did not enforce the proof-table unique key as expected.', 'certloom' ) );
	}

	private static function run_transaction_checks( &$result, $table ) {
		global $wpdb;
		$wpdb->last_error = '';
		$cleared = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $table ) );
		if ( false === $cleared || '' !== (string) $wpdb->last_error ) {
			self::add_check( $result, 'transaction_setup', 'fail', __( 'Transaction test setup', 'certloom' ), __( 'Certloom could not clear its isolated transaction-test rows.', 'certloom' ) );
			return;
		}
		$started = self::control( $wpdb, 'START TRANSACTION' );
		if ( is_wp_error( $started ) ) {
			self::add_check( $result, 'rollback', 'fail', __( 'Transaction rollback', 'certloom' ), __( 'The database would not start a transaction.', 'certloom' ) );
			return;
		}
		$inserted = $wpdb->insert( $table, array( 'probe_key' => 'rollback-proof', 'probe_value' => 'rollback', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
		if ( false === $inserted || '' !== (string) $wpdb->last_error ) {
			$cleanup = self::control( $wpdb, 'ROLLBACK' );
			self::add_check( $result, 'rollback', 'fail', __( 'Transaction rollback', 'certloom' ), __( 'The transaction test could not write its isolated row.', 'certloom' ) );
			if ( is_wp_error( $cleanup ) ) {
				self::add_check( $result, 'transaction_cleanup', 'fail', __( 'Transaction cleanup', 'certloom' ), __( 'The failed rollback test could not confirm transaction cleanup.', 'certloom' ) );
			}
			return;
		}
		$rolled = self::control( $wpdb, 'ROLLBACK' );
		$count = is_wp_error( $rolled ) ? null : self::count_key( $wpdb, $table, 'rollback-proof' );
		self::add_check( $result, 'rollback', ! is_wp_error( $rolled ) && 0 === $count ? 'pass' : 'fail', __( 'Transaction rollback', 'certloom' ), ! is_wp_error( $rolled ) && 0 === $count ? __( 'A rolled-back write disappeared as required.', 'certloom' ) : __( 'The host did not prove a clean transactional rollback.', 'certloom' ) );
		if ( self::has_failures( $result ) ) {
			return;
		}

		$started = self::control( $wpdb, 'START TRANSACTION' );
		if ( is_wp_error( $started ) ) {
			self::add_check( $result, 'commit', 'fail', __( 'Transaction commit', 'certloom' ), __( 'The database would not start the commit test transaction.', 'certloom' ) );
			return;
		}
		$wpdb->last_error = '';
		$inserted = $wpdb->insert( $table, array( 'probe_key' => 'commit-proof', 'probe_value' => 'commit', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
		if ( false === $inserted || '' !== (string) $wpdb->last_error ) {
			$cleanup = self::control( $wpdb, 'ROLLBACK' );
			self::add_check( $result, 'commit', 'fail', __( 'Transaction commit', 'certloom' ), __( 'The commit test could not write its isolated row.', 'certloom' ) );
			if ( is_wp_error( $cleanup ) ) {
				self::add_check( $result, 'transaction_cleanup', 'fail', __( 'Transaction cleanup', 'certloom' ), __( 'The failed commit test could not confirm transaction cleanup.', 'certloom' ) );
			}
			return;
		}
		$committed = self::control( $wpdb, 'COMMIT' );
		if ( is_wp_error( $committed ) ) {
			$cleanup = self::control( $wpdb, 'ROLLBACK' );
			self::add_check( $result, 'commit', 'fail', __( 'Transaction commit', 'certloom' ), __( 'The host did not confirm the isolated transaction commit.', 'certloom' ) );
			if ( is_wp_error( $cleanup ) ) {
				self::add_check( $result, 'transaction_cleanup', 'fail', __( 'Transaction cleanup', 'certloom' ), __( 'After commit uncertainty, the proof could not confirm rollback either.', 'certloom' ) );
			}
			return;
		}
		$count = self::count_key( $wpdb, $table, 'commit-proof' );
		self::add_check( $result, 'commit', 1 === $count ? 'pass' : 'fail', __( 'Transaction commit', 'certloom' ), 1 === $count ? __( 'A committed write remained visible as required.', 'certloom' ) : __( 'The host did not prove a clean transactional commit.', 'certloom' ) );
	}

	private static function run_row_lock_check( &$result, $table ) {
		global $wpdb;
		if ( ! class_exists( 'wpdb' ) || ! defined( 'DB_USER' ) || ! defined( 'DB_PASSWORD' ) || ! defined( 'DB_NAME' ) || ! defined( 'DB_HOST' ) ) {
			self::add_check( $result, 'row_lock', 'warning', __( 'Concurrent row locking', 'certloom' ), __( 'A second independent WordPress database connection was unavailable, so row-lock contention still needs the external acceptance suite.', 'certloom' ) );
			return;
		}

		$wpdb->last_error = '';
		$cleared = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE probe_key = %s', $table, 'lock-proof' ) );
		if ( false === $cleared || '' !== (string) $wpdb->last_error ) {
			self::add_check( $result, 'row_lock', 'fail', __( 'Concurrent row locking', 'certloom' ), __( 'Certloom could not prepare a clean isolated row-lock workspace.', 'certloom' ) );
			return;
		}
		$inserted = $wpdb->insert( $table, array( 'probe_key' => 'lock-proof', 'probe_value' => 'lock', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
		if ( false === $inserted || '' !== (string) $wpdb->last_error ) {
			self::add_check( $result, 'row_lock', 'fail', __( 'Concurrent row locking', 'certloom' ), __( 'Certloom could not prepare the isolated row-lock test.', 'certloom' ) );
			return;
		}

		$secondary = null;
		$primary_started = false;
		$secondary_started = false;
		try {
			$secondary = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
			if ( method_exists( $secondary, 'suppress_errors' ) ) {
				$secondary->suppress_errors( true );
			}
			$secondary->last_error = '';
			$preflight = $secondary->get_var( $secondary->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
			if ( null === $preflight && '' !== (string) $secondary->last_error ) {
				self::add_check( $result, 'row_lock', 'warning', __( 'Concurrent row locking', 'certloom' ), __( 'The host did not allow the proof to open a second database connection. The external row-lock acceptance test remains required.', 'certloom' ) );
				return;
			}
			$timeout = self::control( $secondary, 'SET SESSION innodb_lock_wait_timeout = 1' );
			if ( is_wp_error( $timeout ) ) {
				self::add_check( $result, 'row_lock', 'warning', __( 'Concurrent row locking', 'certloom' ), __( 'The host would not allow the isolated lock-wait timeout needed for a safe in-request contention proof.', 'certloom' ) );
				return;
			}

			$primary = self::control( $wpdb, 'START TRANSACTION' );
			if ( is_wp_error( $primary ) ) {
				self::add_check( $result, 'row_lock', 'fail', __( 'Concurrent row locking', 'certloom' ), __( 'The primary connection could not start the row-lock transaction.', 'certloom' ) );
				return;
			}
			$primary_started = true;
			$wpdb->last_error = '';
			$locked = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE probe_key = %s FOR UPDATE', $table, 'lock-proof' ) );
			if ( null === $locked || '' !== (string) $wpdb->last_error ) {
				self::add_check( $result, 'row_lock', 'fail', __( 'Concurrent row locking', 'certloom' ), __( 'The primary connection could not acquire the proof row lock.', 'certloom' ) );
				return;
			}

			$secondary_begin = self::control( $secondary, 'START TRANSACTION' );
			if ( is_wp_error( $secondary_begin ) ) {
				self::add_check( $result, 'row_lock', 'warning', __( 'Concurrent row locking', 'certloom' ), __( 'The second connection could not start the contention transaction.', 'certloom' ) );
				return;
			}
			$secondary_started = true;
			$secondary->last_error = '';
			$contended = $secondary->get_var( $secondary->prepare( 'SELECT id FROM %i WHERE probe_key = %s FOR UPDATE', $table, 'lock-proof' ) );
			$error = strtolower( (string) $secondary->last_error );
			$secondary_rollback = self::control( $secondary, 'ROLLBACK' );
			if ( is_wp_error( $secondary_rollback ) ) {
				self::add_check( $result, 'row_lock_secondary_cleanup', 'fail', __( 'Secondary row-lock cleanup', 'certloom' ), __( 'The second proof connection could not confirm transaction rollback.', 'certloom' ) );
				return;
			}
			$secondary_started = false;
			if ( null === $contended && ( false !== strpos( $error, 'lock wait timeout' ) || false !== strpos( $error, '1205' ) ) ) {
				self::add_check( $result, 'row_lock', 'pass', __( 'Concurrent row locking', 'certloom' ), __( 'A second database connection was blocked by the first connection’s row lock as required.', 'certloom' ) );
			} elseif ( null !== $contended && '' === $error ) {
				self::add_check( $result, 'row_lock', 'fail', __( 'Concurrent row locking', 'certloom' ), __( 'The second connection bypassed a row lock that Certloom requires for safe concurrent credential updates.', 'certloom' ) );
			} else {
				self::add_check( $result, 'row_lock', 'warning', __( 'Concurrent row locking', 'certloom' ), __( 'The contention probe could not distinguish a lock timeout from another host-specific database restriction. Run the external acceptance suite before cutover.', 'certloom' ) );
			}
		} catch ( Throwable $error ) {
			self::add_check( $result, 'row_lock', 'warning', __( 'Concurrent row locking', 'certloom' ), __( 'The host could not complete the isolated second-connection contention probe. The external row-lock acceptance test remains required.', 'certloom' ) );
		} finally {
			if ( $secondary_started && is_object( $secondary ) ) {
				$rolled_secondary = self::control( $secondary, 'ROLLBACK' );
				if ( is_wp_error( $rolled_secondary ) ) {
					self::add_check( $result, 'row_lock_secondary_cleanup', 'fail', __( 'Secondary row-lock cleanup', 'certloom' ), __( 'The second proof connection could not confirm transaction rollback.', 'certloom' ) );
				}
			}
			if ( $primary_started ) {
				$rolled = self::control( $wpdb, 'ROLLBACK' );
				if ( is_wp_error( $rolled ) ) {
					self::add_check( $result, 'row_lock_primary_cleanup', 'fail', __( 'Row-lock transaction cleanup', 'certloom' ), __( 'The primary proof transaction could not confirm rollback.', 'certloom' ) );
				}
			}
			if ( is_object( $secondary ) && method_exists( $secondary, 'close' ) ) {
				$secondary->close();
			}
		}
	}

	/** @return true|WP_Error */
	private static function maintenance_available() {
		$legacy = get_option( Ecapla_CertVerify_Backup::MAINTENANCE_LOCK_OPTION, false );
		if ( false !== $legacy && '' !== $legacy && array() !== $legacy ) {
			if ( ! is_array( $legacy ) || empty( $legacy['token_hash'] ) || empty( $legacy['expires_at'] ) ) {
				return new WP_Error( 'certloom_database_proof_maintenance_corrupt', __( 'The isolated database proof will not run while Certloom has corrupt restore-maintenance state.', 'certloom' ) );
			}
			if ( absint( $legacy['expires_at'] ) > time() ) {
				return new WP_Error( 'certloom_database_proof_maintenance_active', __( 'Run the isolated database proof after the active Certloom restore/maintenance operation finishes.', 'certloom' ) );
			}
		}

		$domain = (string) get_option( 'certloom_domain_maintenance_lock', '' );
		if ( '' !== $domain ) {
			$parts = explode( '|', $domain, 4 );
			$owner = isset( $parts[0] ) ? (string) $parts[0] : '';
			$locked_at = isset( $parts[1] ) ? absint( $parts[1] ) : 0;
			if ( count( $parts ) >= 4 ) {
				$expires_at = absint( $parts[2] );
			} elseif ( 3 === count( $parts ) ) {
				$expires_at = $locked_at ? $locked_at + 2100 : 0;
			} else {
				$expires_at = 0;
			}
			if ( ! preg_match( '/^[a-f0-9]{32}$/i', $owner ) || ! $locked_at || ! $expires_at || $expires_at < $locked_at ) {
				return new WP_Error( 'certloom_database_proof_domain_maintenance_corrupt', __( 'The isolated database proof will not run while the gated credential domain has corrupt maintenance state.', 'certloom' ) );
			}
			if ( $expires_at > time() ) {
				return new WP_Error( 'certloom_database_proof_domain_maintenance_active', __( 'Run the isolated database proof after the active gated-domain maintenance operation finishes.', 'certloom' ) );
			}
		}

		$schema = (string) get_option( 'certloom_domain_schema_lock', '' );
		if ( '' !== $schema ) {
			$parts = explode( '|', $schema, 2 );
			$token = isset( $parts[0] ) ? (string) $parts[0] : '';
			$locked_at = isset( $parts[1] ) ? absint( $parts[1] ) : 0;
			if ( ! preg_match( '/^[a-z0-9]{32}$/i', $token ) || ! $locked_at ) {
				return new WP_Error( 'certloom_database_proof_schema_maintenance_corrupt', __( 'The isolated database proof will not run while the gated credential domain has corrupt schema-maintenance state.', 'certloom' ) );
			}
			if ( ( time() - $locked_at ) < ( 5 * MINUTE_IN_SECONDS ) ) {
				return new WP_Error( 'certloom_database_proof_schema_maintenance_active', __( 'Run the isolated database proof after the active gated-domain schema maintenance finishes.', 'certloom' ) );
			}
		}
		return true;
	}

	/** @return string|WP_Error */
	private static function acquire_lock() {
		$existing = get_option( self::LOCK_OPTION, null );
		if ( is_array( $existing ) && ! empty( $existing['expires_at'] ) && absint( $existing['expires_at'] ) > time() ) {
			return new WP_Error( 'certloom_database_proof_busy', __( 'Another Certloom database proof is already running.', 'certloom' ) );
		}
		if ( null !== $existing && false !== $existing ) {
			$deleted = delete_option( self::LOCK_OPTION );
			if ( ! $deleted && false !== get_option( self::LOCK_OPTION, false ) ) {
				return new WP_Error( 'certloom_database_proof_stale_lock', __( 'Certloom could not clear an expired database-proof lock.', 'certloom' ) );
			}
		}
		try {
			$owner = bin2hex( random_bytes( 24 ) );
		} catch ( Throwable $error ) {
			return new WP_Error( 'certloom_database_proof_random_failed', __( 'Certloom could not prepare a secure database-proof owner token.', 'certloom' ) );
		}
		$value = array( 'token_hash' => hash( 'sha256', $owner ), 'expires_at' => time() + self::LOCK_TTL );
		if ( ! add_option( self::LOCK_OPTION, $value, '', false ) ) {
			return new WP_Error( 'certloom_database_proof_lock_failed', __( 'Certloom could not acquire the database-proof execution lock.', 'certloom' ) );
		}
		return $owner;
	}

	/** @return true|WP_Error */
	private static function release_lock( $owner ) {
		$current = get_option( self::LOCK_OPTION, null );
		$expected = hash( 'sha256', (string) $owner );
		if ( ! is_array( $current ) || empty( $current['token_hash'] ) || ! hash_equals( (string) $current['token_hash'], $expected ) ) {
			return new WP_Error( 'certloom_database_proof_lock_owner_mismatch', __( 'Certloom refused to release a database-proof lock owned by another process.', 'certloom' ) );
		}
		$deleted = delete_option( self::LOCK_OPTION );
		return $deleted || false === get_option( self::LOCK_OPTION, false ) ? true : new WP_Error( 'certloom_database_proof_lock_release_failed', __( 'Certloom could not release the database-proof lock safely.', 'certloom' ) );
	}

	private static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'certloom_db_proof';
	}

	/** @return true|WP_Error */
	private static function drop_scratch_table( $table ) {
		global $wpdb;
		$expected = self::table_name();
		if ( ! is_string( $table ) || $expected !== $table ) {
			return new WP_Error( 'certloom_database_proof_table_invalid', __( 'Certloom refused an invalid database-proof workspace.', 'certloom' ) );
		}
		$wpdb->last_error = '';
		$dropped = $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Administrator-triggered isolated proof table only.
		return false === $dropped || '' !== (string) $wpdb->last_error ? new WP_Error( 'certloom_database_proof_drop_failed', __( 'Certloom could not remove its isolated database-proof table.', 'certloom' ) ) : true;
	}

	/** @return array<string,mixed>|WP_Error */
	private static function table_status( $table ) {
		global $wpdb;
		$wpdb->last_error = '';
		$row = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $table ) ), ARRAY_A );
		return '' !== (string) $wpdb->last_error || ! is_array( $row ) ? new WP_Error( 'certloom_database_proof_table_read_failed', __( 'Certloom could not inspect its proof table.', 'certloom' ) ) : $row;
	}

	/** @return array<int,array<string,mixed>>|WP_Error */
	private static function index_status( $table ) {
		global $wpdb;
		$wpdb->last_error = '';
		$rows = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', $table ), ARRAY_A );
		return '' !== (string) $wpdb->last_error || ! is_array( $rows ) ? new WP_Error( 'certloom_database_proof_index_read_failed', __( 'Certloom could not inspect its proof-table indexes.', 'certloom' ) ) : $rows;
	}

	private static function count_key( $connection, $table, $key ) {
		$connection->last_error = '';
		$value = $connection->get_var( $connection->prepare( 'SELECT COUNT(*) FROM %i WHERE probe_key = %s', $table, $key ) );
		return '' !== (string) $connection->last_error || null === $value ? null : (int) $value;
	}

	/** @return true|WP_Error */
	private static function control( $connection, $sql ) {
		$connection->last_error = '';
		$value = $connection->query( (string) $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Isolated transaction/session-control proof.
		return false === $value || '' !== (string) $connection->last_error ? new WP_Error( 'certloom_database_proof_control_failed', __( 'The database rejected an isolated proof control statement.', 'certloom' ) ) : true;
	}

	private static function add_check( &$result, $id, $status, $label, $message ) {
		$result['checks'][] = array(
			'id'      => sanitize_key( (string) $id ),
			'status'  => in_array( $status, array( 'pass', 'warning', 'fail' ), true ) ? $status : 'warning',
			'label'   => sanitize_text_field( (string) $label ),
			'message' => sanitize_text_field( (string) $message ),
		);
	}

	private static function has_failures( $result ) {
		foreach ( (array) ( $result['checks'] ?? array() ) as $check ) {
			if ( is_array( $check ) && 'fail' === (string) ( $check['status'] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}

	private static function overall_status( $result ) {
		$warning = false;
		foreach ( (array) ( $result['checks'] ?? array() ) as $check ) {
			$status = is_array( $check ) ? (string) ( $check['status'] ?? '' ) : '';
			if ( 'fail' === $status ) {
				return 'failed';
			}
			if ( 'warning' === $status ) {
				$warning = true;
			}
		}
		return $warning ? 'incomplete' : 'passed';
	}
}
