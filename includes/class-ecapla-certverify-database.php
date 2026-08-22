<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Certloom owns a dedicated custom table; WordPress has no higher-level CRUD API for these records.

/**
 * Certificate table creation, migrations, and database access.
 */
class Ecapla_CertVerify_Database {

	const SCHEMA_OPTION              = 'certverify_schema_version';
	const LEGACY_REVIEW_OPTION       = 'certverify_legacy_expired_review_count';
	const V130_REVIEW_NOTICE_OPTION  = 'certverify_130_status_review_required';
	const LEGACY_REVIEW_RETRY_OPTION = 'certverify_legacy_review_refresh_needed';
	const HEALTH_OPTION              = 'certverify_schema_health';
	const MIGRATION_LOCK_OPTION      = 'certverify_schema_migration_lock';
	const HASH_CURSOR_OPTION         = 'certverify_hash_migration_cursor';
	const HASH_COMPLETE_OPTION       = 'certverify_hash_migration_complete';
	const ORPHAN_CURSOR_OPTION       = 'certverify_orphan_migration_cursor';
	const ORPHAN_COMPLETE_OPTION     = 'certverify_orphan_migration_complete';
	const HEALTH_TTL                 = DAY_IN_SECONDS;
	const MIGRATION_LOCK_TTL         = 5 * MINUTE_IN_SECONDS;

	/** @var int Batch nesting depth for deferred secondary recounts. */
	private static $batch_depth = 0;

	/** @var bool Whether a batch write requires one legacy review recount. */
	private static $review_refresh_pending = false;

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . ECAPLA_CERTVERIFY_TABLE;
	}

	/**
	 * Creates or upgrades the Certloom table and persists the schema marker.
	 *
	 * @return true|WP_Error
	 */
	public static function activate() {
		if ( class_exists( 'Ecapla_CertVerify_Backup' ) && Ecapla_CertVerify_Backup::is_restore_in_progress() ) {
			return true;
		}
		$lock = self::acquire_migration_lock();
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		$result   = self::activate_under_lock();
		$released = self::release_migration_lock( $lock );
		if ( is_wp_error( $released ) ) {
			if ( is_wp_error( $result ) ) {
				return new WP_Error(
					'ecapla_certverify_schema_failure_cleanup_failed',
					__( 'Certloom database maintenance failed and its migration lock could not be released safely. Retry after the lock lease expires or is repaired.', 'certloom' ),
					array( 'operation_error' => $result->get_error_code(), 'cleanup_error' => $released->get_error_code() )
				);
			}
			return $released;
		}
		return $result;
	}

	/** Runs the legacy schema/migration operation while the caller owns the migration lock. */
	private static function activate_under_lock() {
		$installed   = (string) get_option( self::SCHEMA_OPTION, get_option( 'certverify_db_version', '0' ) );
		$table_setup = self::create_or_update_table();
		if ( is_wp_error( $table_setup ) ) {
			return $table_setup;
		}

		$migrated = self::run_migrations( $installed );
		if ( is_wp_error( $migrated ) ) {
			return $migrated;
		}

		$health = self::schema_health();
		if ( is_wp_error( $health ) ) {
			return $health;
		}

		if ( ! self::persist_schema_versions() ) {
			return new WP_Error( 'ecapla_certverify_schema_marker_failed', __( 'Certloom completed its database setup but could not save the schema version.', 'certloom' ) );
		}

		if ( self::migrations_are_complete() ) {
			if ( ! self::save_health_marker() ) {
				return new WP_Error( 'ecapla_certverify_schema_health_marker_failed', __( 'Certloom verified its database structure but could not save the health marker.', 'certloom' ) );
			}
		} else {
			delete_option( self::HEALTH_OPTION );
			self::schedule_migration_continuation();
		}
		return true;
	}

	/**
	 * Whether this request is allowed to run schema work.
	 *
	 * Migrations run dbDelta and batched table scans. Restricting them to admin,
	 * cron, and WP-CLI requests keeps that work off public page views and stops
	 * concurrent front-end traffic from racing on the same ALTER during the short
	 * window after an update. Reads stay safe in the meantime because
	 * decode_row() supplies a status source for any row that predates the column.
	 *
	 * @return bool
	 */
	private static function can_run_schema_work() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return true;
		}
		return is_admin();
	}

	/**
	 * Runs idempotent schema and data upgrades on normal plugin updates.
	 *
	 * @return true|WP_Error
	 */
	public static function maybe_upgrade() {
		if ( class_exists( 'Ecapla_CertVerify_Backup' ) && Ecapla_CertVerify_Backup::is_restore_in_progress() ) {
			return true;
		}
		if ( ! self::can_run_schema_work() ) {
			return true;
		}

		$installed = (string) get_option( self::SCHEMA_OPTION, get_option( 'certverify_db_version', '0' ) );
		$current   = version_compare( $installed, ECAPLA_CERTVERIFY_SCHEMA_VERSION, '>=' );
		$healthy   = $current && self::health_marker_is_fresh();

		if ( $healthy ) {
			if ( ECAPLA_CERTVERIFY_SCHEMA_VERSION !== (string) get_option( 'certverify_db_version', '0' ) && ! self::persist_schema_versions() ) {
				return new WP_Error( 'ecapla_certverify_schema_marker_failed', __( 'Certloom could not synchronize its database schema markers.', 'certloom' ) );
			}
			if ( null === get_option( self::LEGACY_REVIEW_OPTION, null ) || get_option( self::LEGACY_REVIEW_RETRY_OPTION, false ) ) {
				$review_count = self::refresh_legacy_expired_review_count();
				if ( is_wp_error( $review_count ) ) {
					return $review_count;
				}
				delete_option( self::LEGACY_REVIEW_RETRY_OPTION );
			}
			return true;
		}

		return self::activate();
	}

	/**
	 * @param string $installed Previously stored schema version.
	 * @return true|WP_Error
	 */
	private static function run_migrations( $installed ) {
		if ( version_compare( (string) $installed, '1.3.3', '<' ) ) {
			$migrated = self::preserve_legacy_expired_statuses();
			if ( is_wp_error( $migrated ) ) {
				return $migrated;
			}
			if ( '1.3.0' === (string) $installed ) {
				$saved = update_option( self::V130_REVIEW_NOTICE_OPTION, true, false );
				if ( ! $saved && true !== (bool) get_option( self::V130_REVIEW_NOTICE_OPTION, false ) ) {
					return new WP_Error( 'ecapla_certverify_review_notice_save_failed', __( 'Certloom could not save the required historical-status review notice.', 'certloom' ) );
				}
			}
		}

		if ( ! get_option( self::ORPHAN_COMPLETE_OPTION, false ) ) {
			$discovered = self::discover_orphaned_custom_field_keys();
			if ( false === $discovered ) {
				return new WP_Error( 'ecapla_certverify_field_discovery_failed', __( 'Certloom could not complete the retired-field migration.', 'certloom' ) );
			}
		}

		if ( ! get_option( self::HASH_COMPLETE_OPTION, false ) ) {
			$hashes = self::backfill_certificate_hashes();
			if ( is_wp_error( $hashes ) ) {
				return $hashes;
			}
		}
		return true;
	}

	/**
	 * Persists both current and backward-compatible schema markers.
	 *
	 * @return bool
	 */
	private static function persist_schema_versions() {
		/*
		 * The backward-compatible marker is written first. The current schema
		 * marker is the completion flag, so a partial write remains retryable.
		 */
		$legacy_saved = update_option( 'certverify_db_version', ECAPLA_CERTVERIFY_SCHEMA_VERSION, false );
		$legacy_ok    = $legacy_saved || ECAPLA_CERTVERIFY_SCHEMA_VERSION === (string) get_option( 'certverify_db_version', '0' );
		if ( ! $legacy_ok ) {
			return false;
		}

		$current_saved = update_option( self::SCHEMA_OPTION, ECAPLA_CERTVERIFY_SCHEMA_VERSION, false );
		$current_ok    = $current_saved || ECAPLA_CERTVERIFY_SCHEMA_VERSION === (string) get_option( self::SCHEMA_OPTION, '0' );
		return $current_ok;
	}

	private static function create_or_update_table() {
		global $wpdb;

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();
		$sql             = "CREATE TABLE $table (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			cert_number VARCHAR(100) NOT NULL,
			cert_number_hash CHAR(64) NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'valid',
			status_source VARCHAR(20) NOT NULL DEFAULT 'manual',
			holder_name VARCHAR(255) NOT NULL DEFAULT '',
			issue_date DATE NULL,
			expiry_date DATE NULL,
			custom_fields LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY cert_number (cert_number),
			UNIQUE KEY cert_number_hash (cert_number_hash),
			KEY status (status),
			KEY status_source (status_source),
			KEY holder_name (holder_name(191)),
			KEY expiry_date (expiry_date)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$wpdb->last_error = '';
		dbDelta( $sql );
		if ( '' !== (string) $wpdb->last_error ) {
			return new WP_Error( 'ecapla_certverify_table_setup_failed', __( 'Certloom could not create or update its certificate database table.', 'certloom' ) );
		}

		delete_option( self::HEALTH_OPTION );
		return true;
	}

	/**
	 * Verifies the table structure independently of the saved schema marker.
	 *
	 * @return true|WP_Error
	 */
	private static function schema_health() {
		global $wpdb;
		$table = self::table_name();

		$wpdb->last_error = '';
		$columns = $wpdb->get_results(
			$wpdb->prepare( 'SHOW FULL COLUMNS FROM %i', $table ),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error || ! is_array( $columns ) || empty( $columns ) ) {
			return new WP_Error( 'ecapla_certverify_table_setup_failed', __( 'Certloom could not create or verify its certificate database table.', 'certloom' ) );
		}

		$found = array();
		foreach ( $columns as $column ) {
			if ( isset( $column['Field'] ) ) {
				$found[ sanitize_key( $column['Field'] ) ] = $column;
			}
		}

		$definitions = array(
			'id'               => array( 'type' => '/^bigint(?:\(20\))? unsigned$/', 'null' => 'NO', 'extra' => 'auto_increment' ),
			'cert_number'      => array( 'type' => '/^varchar\(100\)$/', 'null' => 'NO' ),
			'cert_number_hash' => array( 'type' => '/^char\(64\)$/', 'null' => 'YES' ),
			'status'           => array( 'type' => '/^varchar\(20\)$/', 'null' => 'NO', 'default' => 'valid' ),
			'status_source'    => array( 'type' => '/^varchar\(20\)$/', 'null' => 'NO', 'default' => 'manual' ),
			'holder_name'      => array( 'type' => '/^varchar\(255\)$/', 'null' => 'NO', 'default' => '' ),
			'issue_date'       => array( 'type' => '/^date$/', 'null' => 'YES' ),
			'expiry_date'      => array( 'type' => '/^date$/', 'null' => 'YES' ),
			'custom_fields'    => array( 'type' => '/^longtext$/', 'null' => 'YES' ),
			'created_at'       => array( 'type' => '/^datetime$/', 'null' => 'NO' ),
			'updated_at'       => array( 'type' => '/^datetime$/', 'null' => 'NO' ),
		);

		foreach ( $definitions as $name => $expected ) {
			if ( ! isset( $found[ $name ] ) ) {
				return new WP_Error( 'ecapla_certverify_table_columns_missing', __( 'Certloom could not verify all required certificate database columns.', 'certloom' ) );
			}
			$column = $found[ $name ];
			$type   = strtolower( isset( $column['Type'] ) ? (string) $column['Type'] : '' );
			if ( ! preg_match( $expected['type'], $type ) || $expected['null'] !== strtoupper( isset( $column['Null'] ) ? (string) $column['Null'] : '' ) ) {
				return new WP_Error( 'ecapla_certverify_table_definition_mismatch', __( 'Certloom detected an incompatible certificate database column definition.', 'certloom' ) );
			}
			if ( array_key_exists( 'default', $expected ) && (string) $expected['default'] !== (string) ( $column['Default'] ?? '' ) ) {
				return new WP_Error( 'ecapla_certverify_table_definition_mismatch', __( 'Certloom detected an incompatible certificate database column default.', 'certloom' ) );
			}
			if ( isset( $expected['extra'] ) && false === strpos( strtolower( (string) ( $column['Extra'] ?? '' ) ), $expected['extra'] ) ) {
				return new WP_Error( 'ecapla_certverify_table_definition_mismatch', __( 'Certloom detected an incompatible certificate database identity column.', 'certloom' ) );
			}
		}

		$wpdb->last_error = '';
		$indexes = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', $table ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $indexes ) || empty( $indexes ) ) {
			return new WP_Error( 'ecapla_certverify_table_indexes_missing', __( 'Certloom could not verify its certificate database indexes.', 'certloom' ) );
		}

		$found_indexes = array();
		foreach ( $indexes as $index ) {
			if ( isset( $index['Key_name'], $index['Column_name'] ) ) {
				$key = sanitize_key( $index['Key_name'] );
				$seq = isset( $index['Seq_in_index'] ) ? max( 1, absint( $index['Seq_in_index'] ) ) : 1;
				$found_indexes[ $key ]['columns'][ $seq ] = sanitize_key( $index['Column_name'] );
				$found_indexes[ $key ]['unique'] = isset( $index['Non_unique'] ) && 0 === (int) $index['Non_unique'];
				$found_indexes[ $key ]['sub_part'][ $seq ] = isset( $index['Sub_part'] ) && null !== $index['Sub_part'] ? absint( $index['Sub_part'] ) : null;
			}
		}

		$required_indexes = array(
			'primary'          => array( 'columns' => array( 1 => 'id' ), 'unique' => true ),
			'cert_number'      => array( 'columns' => array( 1 => 'cert_number' ), 'unique' => true ),
			'cert_number_hash' => array( 'columns' => array( 1 => 'cert_number_hash' ), 'unique' => true ),
			'status'           => array( 'columns' => array( 1 => 'status' ), 'unique' => false ),
			'status_source'    => array( 'columns' => array( 1 => 'status_source' ), 'unique' => false ),
			'holder_name'      => array( 'columns' => array( 1 => 'holder_name' ), 'unique' => false ),
			'expiry_date'      => array( 'columns' => array( 1 => 'expiry_date' ), 'unique' => false ),
		);

		foreach ( $required_indexes as $name => $expected ) {
			if ( ! isset( $found_indexes[ $name ] ) || $expected['columns'] !== ( $found_indexes[ $name ]['columns'] ?? array() ) || (bool) $expected['unique'] !== (bool) ( $found_indexes[ $name ]['unique'] ?? false ) ) {
				return new WP_Error( 'ecapla_certverify_table_indexes_missing', __( 'Certloom could not verify all required certificate database indexes.', 'certloom' ) );
			}
		}
		if ( ! empty( $found_indexes['cert_number']['sub_part'][1] ) || ! empty( $found_indexes['cert_number_hash']['sub_part'][1] ) ) {
			return new WP_Error( 'ecapla_certverify_certificate_index_incomplete', __( 'Certloom detected an incomplete certificate-number index.', 'certloom' ) );
		}

		return true;
	}

	/**
	 * Uses an atomic option insert as a short migration lock.
	 *
	 * @return true|WP_Error
	 */
	private static function acquire_migration_lock() {
		global $wpdb;
		$token = wp_generate_password( 32, false, false );
		$now   = time();
		$value = $token . '|' . $now;
		if ( add_option( self::MIGRATION_LOCK_OPTION, $value, '', false ) ) {
			return $token;
		}
		$stored    = (string) get_option( self::MIGRATION_LOCK_OPTION, '' );
		$parts     = explode( '|', $stored, 2 );
		$locked_at = isset( $parts[1] ) ? absint( $parts[1] ) : 0;
		if ( $locked_at && ( $now - $locked_at ) < self::MIGRATION_LOCK_TTL ) {
			return new WP_Error( 'ecapla_certverify_schema_locked', __( 'Certloom database maintenance is already running. Try again shortly.', 'certloom' ) );
		}
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s',
				$wpdb->options,
				$value,
				self::MIGRATION_LOCK_OPTION,
				$stored
			)
		);
		wp_cache_delete( self::MIGRATION_LOCK_OPTION, 'options' );
		return 1 === (int) $updated ? $token : new WP_Error( 'ecapla_certverify_schema_locked', __( 'Certloom could not obtain its database maintenance lock.', 'certloom' ) );
	}

	private static function release_migration_lock( $token ) {
		global $wpdb;
		$token  = sanitize_text_field( (string) $token );
		$stored = (string) get_option( self::MIGRATION_LOCK_OPTION, '' );
		if ( '' === $token ) {
			return new WP_Error( 'ecapla_certverify_schema_lock_token_invalid', __( 'Certloom refused to release an invalid database-maintenance lock token.', 'certloom' ) );
		}
		if ( '' === $stored ) {
			return true;
		}
		if ( 0 !== strpos( $stored, $token . '|' ) ) {
			return new WP_Error( 'ecapla_certverify_schema_lock_owner_changed', __( 'Certloom refused to release a database-maintenance lock that is no longer owned by this request.', 'certloom' ) );
		}
		$wpdb->last_error = '';
		$deleted = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE option_name = %s AND option_value = %s',
				$wpdb->options,
				self::MIGRATION_LOCK_OPTION,
				$stored
			)
		);
		wp_cache_delete( self::MIGRATION_LOCK_OPTION, 'options' );
		if ( 1 === (int) $deleted || '' === (string) get_option( self::MIGRATION_LOCK_OPTION, '' ) ) {
			return true;
		}
		return new WP_Error( 'ecapla_certverify_schema_lock_release_failed', __( 'Certloom completed database maintenance but could not release its migration lock safely. Retry after the lease expires or is repaired.', 'certloom' ) );
	}

	private static function health_marker_is_fresh() {
		$health = get_option( self::HEALTH_OPTION, array() );
		return self::migrations_are_complete()
			&& is_array( $health )
			&& ECAPLA_CERTVERIFY_SCHEMA_VERSION === (string) ( $health['version'] ?? '' )
			&& isset( $health['checked_at'] )
			&& ( time() - absint( $health['checked_at'] ) ) < self::HEALTH_TTL;
	}

	public static function migrations_are_complete() {
		return (bool) get_option( self::HASH_COMPLETE_OPTION, false )
			&& (bool) get_option( self::ORPHAN_COMPLETE_OPTION, false );
	}

	private static function save_health_marker() {
		$value = array( 'version' => ECAPLA_CERTVERIFY_SCHEMA_VERSION, 'checked_at' => time() );
		$saved = update_option( self::HEALTH_OPTION, $value, false );
		return $saved || $value === get_option( self::HEALTH_OPTION, array() );
	}

	/**
	 * Populates the case-insensitive certificate hash used for indexed lookups.
	 *
	 * @return int|WP_Error
	 */
	private static function backfill_certificate_hashes() {
		global $wpdb;
		$table   = self::table_name();
		$last_id = absint( get_option( self::HASH_CURSOR_OPTION, 0 ) );
		$changed = 0;
		$rows    = $wpdb->get_results(
			$wpdb->prepare( 'SELECT id, cert_number FROM %i WHERE id > %d AND (cert_number_hash IS NULL OR cert_number_hash = %s) ORDER BY id ASC LIMIT %d', $table, $last_id, '', 250 ),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
			return self::read_error();
		}
		foreach ( $rows as $row ) {
			$last_id = max( $last_id, absint( $row['id'] ) );
			$result  = $wpdb->update( $table, array( 'cert_number_hash' => self::certificate_hash( $row['cert_number'] ) ), array( 'id' => $last_id ) );
			if ( false === $result ) {
				if ( false !== stripos( (string) $wpdb->last_error, 'duplicate' ) ) {
					return new WP_Error( 'ecapla_certverify_case_duplicate_numbers', __( 'Certloom found certificate numbers that differ only by letter case. Resolve those duplicates before the update can finish.', 'certloom' ) );
				}
				return self::database_error( 'update' );
			}
			$changed += (int) $result;
		}
		$saved = update_option( self::HASH_CURSOR_OPTION, $last_id, false );
		if ( ! $saved && $last_id !== absint( get_option( self::HASH_CURSOR_OPTION, 0 ) ) ) {
			return new WP_Error( 'ecapla_certverify_hash_cursor_failed', __( 'Certloom could not save certificate migration progress.', 'certloom' ) );
		}
		if ( count( $rows ) < 250 ) {
			$remaining = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE cert_number_hash IS NULL OR cert_number_hash = %s', $table, '' ) );
			if ( '' !== (string) $wpdb->last_error || null === $remaining ) {
				return self::read_error();
			}
			if ( 0 === (int) $remaining ) {
				$done = update_option( self::HASH_COMPLETE_OPTION, true, false );
				if ( ! $done && true !== (bool) get_option( self::HASH_COMPLETE_OPTION, false ) ) {
					return new WP_Error( 'ecapla_certverify_hash_completion_failed', __( 'Certloom completed certificate migration but could not save its completion state.', 'certloom' ) );
				}
				delete_option( self::HASH_CURSOR_OPTION );
			} else {
				update_option( self::HASH_CURSOR_OPTION, 0, false );
			}
		}
		return $changed;
	}


	/** Schedules the next bounded migration batch without blocking public traffic. */
	private static function schedule_migration_continuation() {
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_single_event' ) ) {
			return;
		}
		if ( ! wp_next_scheduled( 'ecapla_certverify_continue_migrations' ) ) {
			wp_schedule_single_event( time() + 10, 'ecapla_certverify_continue_migrations' );
		}
	}

	public static function normalize_certificate_number( $cert_number ) {
		$value = trim( sanitize_text_field( (string) $cert_number ) );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
	}

	public static function certificate_hash( $cert_number ) {
		return hash( 'sha256', self::normalize_certificate_number( $cert_number ) );
	}

	/**
	 * Marks ambiguous stored Expired records as legacy instead of promoting them
	 * to Valid. They remain Expired until an administrator deliberately reviews
	 * their workflow status.
	 *
	 * @return int|WP_Error Number of legacy rows awaiting review, or a database error.
	 */
	public static function preserve_legacy_expired_statuses() {
		$allowed = Ecapla_CertVerify_Backup::assert_writes_allowed();
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		global $wpdb;
		$table = self::table_name();

		$marked = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status_source = %s WHERE status = %s AND status_source <> %s",
				$table,
				'legacy',
				'expired',
				'legacy'
			)
		);
		if ( false === $marked ) {
			return new WP_Error( 'ecapla_certverify_legacy_status_migration_failed', __( 'Certloom could not preserve historical Expired certificate states.', 'certloom' ) );
		}

		$normalized = $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status_source = %s WHERE status <> %s AND status_source <> %s",
				$table,
				'manual',
				'expired',
				'manual'
			)
		);
		if ( false === $normalized ) {
			return new WP_Error( 'ecapla_certverify_legacy_status_migration_failed', __( 'Certloom could not normalize certificate status-source data.', 'certloom' ) );
		}

		return self::refresh_legacy_expired_review_count();
	}

	/**
	 * Backward-compatible alias retained for older integrations and retired cron
	 * callbacks. It now preserves ambiguous rows rather than rewriting them.
	 *
	 * @return int|false
	 */
	public static function normalize_legacy_expired_statuses() {
		return self::preserve_legacy_expired_statuses();
	}

	/**
	 * @return int|WP_Error
	 */
	public static function insert( $data ) {
		global $wpdb;

		$allowed = Ecapla_CertVerify_Backup::assert_writes_allowed();
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$legacy_write = ( new \Certloom\Application\Migration\LegacyRegistryWriteGuard() )->assert_allowed();
		if ( is_wp_error( $legacy_write ) ) {
			return $legacy_write;
		}

		$clean = Ecapla_CertVerify_Validator::certificate_data( $data, false );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		$now               = current_time( 'mysql' );
		$row               = self::prepare_row( $clean );
		$row['created_at'] = isset( $data['created_at'] ) && self::valid_mysql_datetime( $data['created_at'] ) ? $data['created_at'] : $now;
		$row['updated_at'] = isset( $data['updated_at'] ) && self::valid_mysql_datetime( $data['updated_at'] ) ? $data['updated_at'] : $now;

		$generation = self::reserve_live_mutation_generation();
		if ( is_wp_error( $generation ) ) {
			return $generation;
		}

		$wpdb->last_error = '';
		$inserted = $wpdb->insert( self::table_name(), $row );
		if ( false === $inserted ) {
			if ( false !== stripos( (string) $wpdb->last_error, 'duplicate' ) ) {
				return new WP_Error( 'ecapla_certverify_duplicate_number', __( 'That certificate number is already in use. Letter case is ignored.', 'certloom' ) );
			}
			return self::database_error( 'insert' );
		}

		self::refresh_legacy_expired_review_count_after_write();
		return (int) $wpdb->insert_id;
	}

	/**
	 * Applies a partial update while preserving every key that was not submitted.
	 *
	 * @return true|WP_Error
	 */
	public static function update( $id, $data ) {
		global $wpdb;

		$allowed = Ecapla_CertVerify_Backup::assert_writes_allowed();
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$legacy_write = ( new \Certloom\Application\Migration\LegacyRegistryWriteGuard() )->assert_allowed();
		if ( is_wp_error( $legacy_write ) ) {
			return $legacy_write;
		}

		$id       = absint( $id );
		$existing = self::get_by_id( $id );
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}
		if ( ! $existing ) {
			return new WP_Error( 'ecapla_certverify_not_found', __( 'The certificate could not be found.', 'certloom' ) );
		}

		$clean = Ecapla_CertVerify_Validator::certificate_data( $data, true );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}


		$merged = $existing;
		foreach ( array( 'cert_number', 'status', 'status_source', 'holder_name', 'issue_date', 'expiry_date' ) as $key ) {
			if ( array_key_exists( $key, $clean ) ) {
				$merged[ $key ] = $clean[ $key ];
			}
		}

		if ( array_key_exists( 'custom_fields', $clean ) ) {
			$existing_custom         = is_array( $existing['custom_fields'] ) ? $existing['custom_fields'] : array();
			$merged['custom_fields'] = array_merge( $existing_custom, $clean['custom_fields'] );
		}

		$date_range = Ecapla_CertVerify_Validator::date_range( $merged['issue_date'], $merged['expiry_date'] );
		if ( is_wp_error( $date_range ) ) {
			return $date_range;
		}

		$row               = self::prepare_row( $merged );
		$row['updated_at'] = isset( $data['updated_at'] ) && self::valid_mysql_datetime( $data['updated_at'] ) ? $data['updated_at'] : current_time( 'mysql' );
		$generation        = self::reserve_live_mutation_generation();
		if ( is_wp_error( $generation ) ) {
			return $generation;
		}
		$wpdb->last_error  = '';
		$updated           = $wpdb->update( self::table_name(), $row, array( 'id' => $id ) );

		if ( false === $updated ) {
			if ( false !== stripos( (string) $wpdb->last_error, 'duplicate' ) ) {
				return new WP_Error( 'ecapla_certverify_duplicate_number', __( 'That certificate number is already in use. Letter case is ignored.', 'certloom' ) );
			}
			return self::database_error( 'update' );
		}

		self::refresh_legacy_expired_review_count_after_write();
		return true;
	}

	/**
	 * Discovers historical JSON keys that no longer have an active field
	 * definition and places them in the retired registry. This closes the
	 * upgrade gap for fields removed before the registry existed.
	 *
	 * @return int|false Number of newly registered keys, or false on failure.
	 */
	public static function discover_orphaned_custom_field_keys() {
		global $wpdb;
		$table      = self::table_name();
		$active     = array_keys( Ecapla_CertVerify_Fields::get_field_map() );
		$retired    = Ecapla_CertVerify_Fields::get_retired_fields();
		$known      = array_fill_keys( array_merge( $active, array_keys( $retired ) ), true );
		$last_id    = absint( get_option( self::ORPHAN_CURSOR_OPTION, 0 ) );
		$batch_size = 200;
		$discovered = 0;
		$rows       = $wpdb->get_results( $wpdb->prepare( 'SELECT id, custom_fields FROM %i WHERE id > %d ORDER BY id ASC LIMIT %d', $table, $last_id, $batch_size ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
			return false;
		}
		$generation_reserved = false;
		foreach ( $rows as $row ) {
			$last_id = max( $last_id, absint( $row['id'] ) );
			$custom  = ! empty( $row['custom_fields'] ) ? json_decode( $row['custom_fields'], true ) : array();
			if ( ! is_array( $custom ) ) {
				continue;
			}
			foreach ( array_keys( $custom ) as $raw_key ) {
				$key = sanitize_key( $raw_key );
				if ( '' === $key || isset( $known[ $key ] ) ) {
					continue;
				}
				$retired[ $key ] = array( 'key' => $key, 'label' => ucwords( str_replace( array( '-', '_' ), ' ', $key ) ), 'type' => 'text', 'retired_at' => current_time( 'mysql' ) );
				$known[ $key ] = true;
				$discovered++;
			}
		}
		if ( $discovered > 0 && ! Ecapla_CertVerify_Fields::save_retired_fields( $retired ) ) {
			return false;
		}
		$saved = update_option( self::ORPHAN_CURSOR_OPTION, $last_id, false );
		if ( ! $saved && $last_id !== absint( get_option( self::ORPHAN_CURSOR_OPTION, 0 ) ) ) {
			return false;
		}
		if ( count( $rows ) < $batch_size ) {
			$done = update_option( self::ORPHAN_COMPLETE_OPTION, true, false );
			if ( ! $done && true !== (bool) get_option( self::ORPHAN_COMPLETE_OPTION, false ) ) {
				return false;
			}
			delete_option( self::ORPHAN_CURSOR_OPTION );
		}
		return $discovered;
	}

	/**
	 * Permanently removes one retired custom-field key from stored certificate JSON.
	 * The operation is deliberately explicit and batched because deletion cannot be
	 * reversed once the database has been updated.
	 *
	 * @param string $field_key Retired custom-field key.
	 * @return int|WP_Error Number of certificate rows changed.
	 */
	public static function purge_custom_field_values( $field_key ) {
		global $wpdb;

		$allowed = Ecapla_CertVerify_Backup::assert_writes_allowed();
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$legacy_write = ( new \Certloom\Application\Migration\LegacyRegistryWriteGuard() )->assert_allowed();
		if ( is_wp_error( $legacy_write ) ) {
			return $legacy_write;
		}

		$field_key = sanitize_key( $field_key );
		if ( '' === $field_key ) {
			return new WP_Error( 'ecapla_certverify_invalid_field', __( 'The retired field key is invalid.', 'certloom' ) );
		}

		$state_key  = self::purge_state_option_key( $field_key );
		$state      = get_option( $state_key, array() );
		$state      = is_array( $state ) && isset( $state['field_key'] ) && $field_key === sanitize_key( $state['field_key'] ) ? $state : array();
		$table      = self::table_name();
		$last_id    = isset( $state['last_id'] ) ? absint( $state['last_id'] ) : 0;
		$changed    = isset( $state['changed'] ) ? absint( $state['changed'] ) : 0;
		$batch_size = 200;

		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT id, custom_fields FROM %i WHERE id > %d ORDER BY id ASC LIMIT %d', $table, $last_id, $batch_size ),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
			return self::purge_error(
				'ecapla_certverify_purge_read_failed',
				__( 'Stored certificate values could not be read. Run Purge again to continue from the last saved batch.', 'certloom' ),
				$field_key,
				$last_id,
				$changed
			);
		}

		foreach ( $rows as $row ) {
			$row_id = absint( $row['id'] );
			$custom = ! empty( $row['custom_fields'] ) ? json_decode( $row['custom_fields'], true ) : array();
			if ( is_array( $custom ) && array_key_exists( $field_key, $custom ) ) {
				if ( ! $generation_reserved ) {
					$generation = self::reserve_live_mutation_generation();
					if ( is_wp_error( $generation ) ) {
						return $generation;
					}
					$generation_reserved = true;
				}
				unset( $custom[ $field_key ] );
				$result = $wpdb->update(
					$table,
					array( 'custom_fields' => wp_json_encode( $custom ), 'updated_at' => current_time( 'mysql' ) ),
					array( 'id' => $row_id )
				);
				if ( false === $result ) {
					return self::purge_error( 'ecapla_certverify_purge_write_failed', __( 'Some stored values were deleted before a database error. Run Purge again to continue safely.', 'certloom' ), $field_key, $last_id, $changed );
				}
				$changed += (int) ( $result > 0 );
			}
			$last_id = max( $last_id, $row_id );
		}

		$complete = count( $rows ) < $batch_size;
		if ( $complete ) {
			delete_option( $state_key );
		} elseif ( ! self::save_purge_state( $state_key, $field_key, $last_id, $changed ) ) {
			return new WP_Error( 'ecapla_certverify_purge_progress_failed', __( 'Stored values were processed, but Certloom could not save purge progress.', 'certloom' ), array( 'changed' => $changed, 'last_id' => $last_id ) );
		}

		return array( 'changed' => $changed, 'complete' => $complete, 'last_id' => $last_id );
	}

	/**
	 * Clears any saved resumable purge cursor after a retired key is removed.
	 *
	 * @param string $field_key Retired field key.
	 */
	public static function clear_custom_field_purge_state( $field_key ) {
		delete_option( self::purge_state_option_key( sanitize_key( $field_key ) ) );
	}

	private static function purge_state_option_key( $field_key ) {
		return 'certverify_purge_' . md5( (string) $field_key );
	}

	private static function save_purge_state( $state_key, $field_key, $last_id, $changed ) {
		$state = array(
			'field_key' => sanitize_key( $field_key ),
			'last_id'   => absint( $last_id ),
			'changed'   => absint( $changed ),
			'updated_at' => current_time( 'mysql' ),
		);
		$saved = update_option( $state_key, $state, false );
		return $saved || $state === get_option( $state_key, array() );
	}

	private static function purge_error( $code, $message, $field_key, $last_id, $changed ) {
		$state_key = self::purge_state_option_key( $field_key );
		self::save_purge_state( $state_key, $field_key, $last_id, $changed );
		return new WP_Error(
			$code,
			$message,
			array( 'changed' => absint( $changed ), 'last_id' => absint( $last_id ) )
		);
	}

	/**
	 * Deletes one certificate while distinguishing a missing record from a
	 * database failure.
	 *
	 * @param int $id Certificate ID.
	 * @return true|WP_Error
	 */
	public static function delete( $id ) {
		global $wpdb;
		$allowed = Ecapla_CertVerify_Backup::assert_writes_allowed();
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$legacy_write = ( new \Certloom\Application\Migration\LegacyRegistryWriteGuard() )->assert_allowed();
		if ( is_wp_error( $legacy_write ) ) {
			return $legacy_write;
		}
		$id = absint( $id );
		if ( ! $id ) {
			return new WP_Error( 'ecapla_certverify_invalid_id', __( 'The certificate deletion request was invalid.', 'certloom' ) );
		}
		$generation = self::reserve_live_mutation_generation();
		if ( is_wp_error( $generation ) ) {
			return $generation;
		}
		$deleted = $wpdb->delete( self::table_name(), array( 'id' => $id ) );
		if ( false === $deleted ) {
			return new WP_Error( 'ecapla_certverify_delete_failed', __( 'The certificate could not be deleted because of a database error.', 'certloom' ) );
		}
		if ( 0 === $deleted ) {
			return new WP_Error( 'ecapla_certverify_not_found', __( 'The certificate could not be deleted because it no longer exists.', 'certloom' ) );
		}
		self::refresh_legacy_expired_review_count_after_write();
		return true;
	}

	/**
	 * @return int|WP_Error Number of deleted rows or a database error.
	 */
	public static function delete_many( $ids ) {
		global $wpdb;

		$allowed = Ecapla_CertVerify_Backup::assert_writes_allowed();
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$legacy_write = ( new \Certloom\Application\Migration\LegacyRegistryWriteGuard() )->assert_allowed();
		if ( is_wp_error( $legacy_write ) ) {
			return $legacy_write;
		}

		$ids = array_values( array_filter( array_unique( array_map( 'absint', (array) $ids ) ) ) );
		if ( empty( $ids ) ) {
			return 0;
		}
		$generation = self::reserve_live_mutation_generation();
		if ( is_wp_error( $generation ) ) {
			return $generation;
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$query_args   = array_merge( array( self::table_name() ), $ids );
		$delete_sql   = $wpdb->prepare(
			"DELETE FROM %i WHERE id IN ($placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholder list is generated from a sanitized integer array.
			$query_args
		);
		$result       = $wpdb->query( $delete_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Prepared immediately above with an identifier placeholder and integer placeholders.
		if ( false === $result ) {
			return new WP_Error( 'ecapla_certverify_bulk_delete_failed', __( 'The selected certificates could not be deleted because of a database error.', 'certloom' ) );
		}
		self::refresh_legacy_expired_review_count_after_write();
		return (int) $result;
	}

	/**
	 * Reserves a new live-registry generation before a customer-visible mutation.
	 *
	 * Advancing the marker first is intentionally conservative: a later database
	 * write may still fail, but a real registry change can never succeed while the
	 * migration/cutover generation marker silently remains at its old boundary.
	 * Internal complete-restore writes manage one generation transition at their
	 * own atomic completion boundary and therefore skip this per-row reservation.
	 *
	 * @return true|WP_Error
	 */
	private static function reserve_live_mutation_generation() {
		return Ecapla_CertVerify_Backup::reserve_mutation_generation();
	}

	/**
	 * Looks up one certificate by internal ID.
	 *
	 * @param int $id Certificate ID.
	 * @return array|null|WP_Error Decoded record, no matching row, or a database error.
	 */
	public static function get_by_id( $id ) {
		global $wpdb;
		$table = self::table_name();
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, absint( $id ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared inline.
		if ( '' !== (string) $wpdb->last_error ) {
			return self::read_error();
		}
		return self::decode_row( $row );
	}

	/**
	 * Performs a whole-value certificate lookup while deliberately ignoring letter
	 * case. No wildcard matching or partial identifier matching is used.
	 *
	 * @param string $cert_number Certificate number.
	 * @return array|null|WP_Error Decoded record, no matching row, or a database error.
	 */
	public static function get_by_cert_number( $cert_number ) {
		global $wpdb;
		$table       = self::table_name();
		$cert_number = trim( sanitize_text_field( (string) $cert_number ) );
		$length      = function_exists( 'mb_strlen' ) ? mb_strlen( $cert_number ) : strlen( $cert_number );
		if ( '' === $cert_number || $length > 100 ) {
			return null;
		}
		if ( get_option( self::HASH_COMPLETE_OPTION, false ) ) {
			$wpdb->last_error = '';
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE cert_number_hash = %s LIMIT 1', $table, self::certificate_hash( $cert_number ) ), ARRAY_A );
			if ( $row ) {
				return self::decode_row( $row );
			}
			if ( '' !== (string) $wpdb->last_error ) {
				delete_option( self::HASH_COMPLETE_OPTION );
				delete_option( self::HEALTH_OPTION );
			}
		}
		$wpdb->last_error = '';
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE cert_number = %s LIMIT 1', $table, $cert_number ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error ) {
			return self::read_error();
		}
		if ( $row ) {
			return self::decode_row( $row );
		}
		$wpdb->last_error = '';
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE LOWER(cert_number) = LOWER(%s) LIMIT 1', $table, $cert_number ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error ) {
			return self::read_error();
		}
		return self::decode_row( $row );
	}

	/**
	 * Reads a bounded export/backup batch without OFFSET or a count query.
	 *
	 * @param int $after_id Last exported ID.
	 * @param int $limit Batch size.
	 * @return array|WP_Error
	 */
	public static function get_export_batch( $after_id = 0, $limit = 500 ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id > %d ORDER BY id ASC LIMIT %d', self::table_name(), absint( $after_id ), min( 500, max( 1, absint( $limit ) ) ) ),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
			return self::read_error();
		}
		return array_map( array( __CLASS__, 'decode_row' ), $rows );
	}

	/**
	 * Resolves existing certificates for a set of numbers in bounded indexed queries.
	 *
	 * @param array $numbers Certificate numbers.
	 * @return array<string,int>|WP_Error Hash-to-ID map.
	 */
	public static function get_existing_number_map( $numbers ) {
		$records = self::get_records_by_numbers( $numbers );
		if ( is_wp_error( $records ) ) {
			return $records;
		}
		$found = array();
		foreach ( $records as $record ) {
			$found[ self::certificate_hash( $record['cert_number'] ) ] = absint( $record['id'] );
		}
		return $found;
	}

	/**
	 * Resolves complete existing records for a set of certificate numbers using
	 * bounded indexed hash queries. The returned keys are normalized numbers.
	 *
	 * @param array $numbers Certificate numbers.
	 * @return array<string,array>|WP_Error
	 */
	public static function get_records_by_numbers( $numbers ) {
		return self::get_records_by_numbers_from_table( self::table_name(), $numbers );
	}

	/**
	 * Restores a complete trusted backup record, including retired custom values
	 * and original timestamps.
	 *
	 * @param array $data Backup record.
	 * @param int   $existing_id Existing record ID to replace, or zero.
	 * @return int|true|WP_Error
	 */
	public static function restore_record( $data, $existing_id = 0 ) {
		global $wpdb;
		$data = is_array( $data ) ? $data : array();
		$core = $data;
		unset( $core['custom_fields'], $core['id'], $core['cert_number_hash'] );
		$clean = Ecapla_CertVerify_Validator::certificate_data( $core, false );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		$definitions = Ecapla_CertVerify_Fields::get_field_map();
		foreach ( Ecapla_CertVerify_Fields::get_retired_fields() as $key => $field ) {
			$definitions[ $key ] = $field;
		}
		$clean['custom_fields'] = array();
		foreach ( (array) ( $data['custom_fields'] ?? array() ) as $key => $value ) {
			$key = sanitize_key( $key );
			if ( '' === $key || ! isset( $definitions[ $key ] ) || ! empty( $definitions[ $key ]['column'] ) ) {
				continue;
			}
			$value = Ecapla_CertVerify_Validator::custom_field_value( $value, $definitions[ $key ] );
			if ( is_wp_error( $value ) ) {
				return $value;
			}
			$clean['custom_fields'][ $key ] = $value;
		}

		$now               = current_time( 'mysql' );
		$row               = self::prepare_row( $clean );
		$row['created_at'] = isset( $data['created_at'] ) && self::valid_mysql_datetime( $data['created_at'] ) ? $data['created_at'] : $now;
		$row['updated_at'] = isset( $data['updated_at'] ) && self::valid_mysql_datetime( $data['updated_at'] ) ? $data['updated_at'] : $now;
		$wpdb->last_error  = '';

		if ( $existing_id ) {
			$result = $wpdb->update( self::table_name(), $row, array( 'id' => absint( $existing_id ) ) );
		} else {
			$result = $wpdb->insert( self::table_name(), $row );
		}
		if ( false === $result ) {
			if ( false !== stripos( (string) $wpdb->last_error, 'duplicate' ) ) {
				return new WP_Error( 'ecapla_certverify_duplicate_number', __( 'That certificate number is already in use. Letter case is ignored.', 'certloom' ) );
			}
			return self::database_error( $existing_id ? 'update' : 'insert' );
		}
		self::refresh_legacy_expired_review_count_after_write();
		return $existing_id ? true : (int) $wpdb->insert_id;
	}


	/** Returns a bounded state signature used to detect concurrent live changes. */
	public static function get_registry_snapshot() {
		global $wpdb;
		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A live aggregate fingerprint is required to detect changes that would make a backup or restore snapshot inconsistent.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS record_count, COALESCE(MAX(id), 0) AS max_id, COALESCE(MAX(updated_at), '') AS max_updated_at, COALESCE(BIT_XOR(CRC32(CONCAT_WS('|', id, cert_number, COALESCE(cert_number_hash, ''), status, status_source, holder_name, COALESCE(issue_date, ''), COALESCE(expiry_date, ''), COALESCE(custom_fields, ''), created_at, updated_at))), 0) AS checksum FROM %i",
				self::table_name()
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error || ! is_array( $row ) ) {
			return self::read_error();
		}
		return array(
			'record_count'   => absint( $row['record_count'] ?? 0 ),
			'max_id'         => absint( $row['max_id'] ?? 0 ),
			'max_updated_at' => sanitize_text_field( (string) ( $row['max_updated_at'] ?? '' ) ),
			'checksum'       => sanitize_text_field( (string) ( $row['checksum'] ?? '0' ) ),
		);
	}

	public static function get_registry_count() {
		$snapshot = self::get_registry_snapshot();
		return is_wp_error( $snapshot ) ? $snapshot : absint( $snapshot['record_count'] );
	}

	public static function create_restore_staging_table( $suffix, $copy_current = true ) {
		global $wpdb;
		$source  = self::table_name();
		$staging = $wpdb->prefix . 'certverify_stage_' . substr( preg_replace( '/[^a-z0-9]/i', '', (string) $suffix ), 0, 12 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- A private staging table is required for atomic complete-backup restoration.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $staging ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Creates a same-schema private staging table for an administrator-confirmed restore.
		$created = $wpdb->query( $wpdb->prepare( 'CREATE TABLE %i LIKE %i', $staging, $source ) );
		$copied  = false !== $created && ! $copy_current;
		if ( false !== $created && $copy_current ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Merge mode copies the current registry into the private staging table before backup records are applied.
			$copied = $wpdb->query( $wpdb->prepare( 'INSERT INTO %i SELECT * FROM %i', $staging, $source ) );
		}
		if ( false === $created || false === $copied ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Removes an incomplete private staging table after preparation failure.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $staging ) );
			return new WP_Error( 'ecapla_certverify_restore_stage_failed', __( 'Certloom could not prepare a safe restore workspace.', 'certloom' ) );
		}
		return $staging;
	}

	public static function drop_restore_table( $table ) {
		global $wpdb;
		if ( 0 !== strpos( (string) $table, $wpdb->prefix . 'certverify_' ) ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Deletes only a validated Certloom staging, rollback, or failed-restore table.
		return false !== $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
	}

	public static function get_records_by_numbers_from_table( $table, $numbers ) {
		global $wpdb;
		if ( 0 !== strpos( (string) $table, $wpdb->prefix . 'certverify_' ) ) {
			return new WP_Error( 'ecapla_certverify_restore_table_invalid', __( 'The temporary restore table is invalid.', 'certloom' ) );
		}
		$clean = array();
		foreach ( (array) $numbers as $number ) {
			$number = trim( sanitize_text_field( (string) $number ) );
			if ( '' !== $number ) {
				$clean[ self::normalize_certificate_number( $number ) ] = $number;
			}
		}
		$found = array();
		foreach ( array_chunk( array_keys( $clean ), 300 ) as $normalized_chunk ) {
			$hashes       = array_map( array( __CLASS__, 'certificate_hash' ), $normalized_chunk );
			$placeholders = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );
			$args         = array_merge( array( $table ), $hashes );
			$sql          = $wpdb->prepare( "SELECT * FROM %i WHERE cert_number_hash IN ($placeholders)", $args ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed placeholder list.
			$rows         = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.
			if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
				return self::read_error();
			}
			foreach ( $rows as $row ) {
				$found[ self::normalize_certificate_number( $row['cert_number'] ) ] = self::decode_row( $row );
			}

			$missing = array_values( array_diff( $normalized_chunk, array_keys( $found ) ) );
			if ( empty( $missing ) ) {
				continue;
			}
			$originals    = array_map( static function ( $key ) use ( $clean ) { return $clean[ $key ]; }, $missing );
			$placeholders = implode( ',', array_fill( 0, count( $originals ), '%s' ) );
			$args         = array_merge( array( $table ), $originals );
			$sql          = $wpdb->prepare( "SELECT * FROM %i WHERE cert_number IN ($placeholders)", $args ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed placeholder list.
			$rows         = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.
			if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
				return self::read_error();
			}
			foreach ( $rows as $row ) {
				$found[ self::normalize_certificate_number( $row['cert_number'] ) ] = self::decode_row( $row );
			}

			$still_missing = array_values( array_diff( $normalized_chunk, array_keys( $found ) ) );
			if ( empty( $still_missing ) ) {
				continue;
			}
			$placeholders = implode( ',', array_fill( 0, count( $still_missing ), '%s' ) );
			$args         = array_merge( array( $table ), $still_missing );
			$sql          = $wpdb->prepare( "SELECT * FROM %i WHERE LOWER(cert_number) IN ($placeholders)", $args ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Bounded compatibility lookup during an incomplete hash migration or unusual binary collation.
			$rows         = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared immediately above.
			if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
				return self::read_error();
			}
			foreach ( $rows as $row ) {
				$found[ self::normalize_certificate_number( $row['cert_number'] ) ] = self::decode_row( $row );
			}
		}
		return $found;
	}

	public static function restore_record_to_table( $table, $data, $existing_id, $definitions ) {
		global $wpdb;
		if ( 0 !== strpos( (string) $table, $wpdb->prefix . 'certverify_' ) ) {
			return new WP_Error( 'ecapla_certverify_restore_table_invalid', __( 'The temporary restore table is invalid.', 'certloom' ) );
		}
		$core = is_array( $data ) ? $data : array();
		unset( $core['custom_fields'], $core['id'], $core['cert_number_hash'] );
		$clean = Ecapla_CertVerify_Validator::certificate_data( $core, false );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}
		$clean['custom_fields'] = array();
		foreach ( (array) ( $data['custom_fields'] ?? array() ) as $key => $value ) {
			$key = sanitize_key( $key );
			if ( '' === $key || ! isset( $definitions[ $key ] ) || ! empty( $definitions[ $key ]['column'] ) ) {
				continue;
			}
			$value = Ecapla_CertVerify_Validator::custom_field_value( $value, $definitions[ $key ] );
			if ( is_wp_error( $value ) ) {
				return $value;
			}
			$clean['custom_fields'][ $key ] = $value;
		}
		$now = current_time( 'mysql' );
		$row = self::prepare_row( $clean );
		$row['created_at'] = isset( $data['created_at'] ) && self::valid_mysql_datetime( $data['created_at'] ) ? $data['created_at'] : $now;
		$row['updated_at'] = isset( $data['updated_at'] ) && self::valid_mysql_datetime( $data['updated_at'] ) ? $data['updated_at'] : $now;
		$result = $existing_id ? $wpdb->update( $table, $row, array( 'id' => absint( $existing_id ) ) ) : $wpdb->insert( $table, $row );
		return false === $result ? self::database_error( $existing_id ? 'update' : 'insert' ) : true;
	}

	public static function swap_restore_table( $staging, $suffix ) {
		global $wpdb;
		$active   = self::table_name();
		$rollback = $wpdb->prefix . 'certverify_rollback_' . substr( preg_replace( '/[^a-z0-9]/i', '', (string) $suffix ), 0, 12 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Clears only the generated Certloom rollback table name before an atomic restore swap.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $rollback ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- MySQL's atomic RENAME TABLE operation activates the fully prepared registry and preserves rollback data.
		$result = $wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i, %i TO %i', $active, $rollback, $staging, $active ) );
		return false === $result ? new WP_Error( 'ecapla_certverify_restore_swap_failed', __( 'Certloom could not activate the prepared backup safely.', 'certloom' ) ) : $rollback;
	}

	public static function rollback_restore_table( $rollback, $suffix ) {
		global $wpdb;
		$active = self::table_name();
		$failed = $wpdb->prefix . 'certverify_failed_' . substr( preg_replace( '/[^a-z0-9]/i', '', (string) $suffix ), 0, 12 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Clears only the generated failed-restore table name before rollback.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $failed ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Atomically restores the prior registry after a failed configuration commit.
		$result = $wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i, %i TO %i', $active, $failed, $rollback, $active ) );
		if ( false !== $result ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Removes the failed replacement only after the prior registry has been restored.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $failed ) );
		}
		return false !== $result;
	}

	/**
	 * @return array{items: array, total: int}|WP_Error List result or a database error.
	 */
	public static function get_list( $args = array() ) {
		global $wpdb;
		$table = self::table_name();
		$args  = wp_parse_args(
			$args,
			array(
				'search'         => '',
				'search_mode'    => 'prefix',
				'status'         => '',
				'legacy_expired' => false,
				'orderby'        => 'created_at',
				'order'          => 'DESC',
				'per_page'       => 20,
				'page'           => 1,
			)
		);

		$where  = array( '1=1' );
		$search = trim( sanitize_text_field( (string) $args['search'] ) );
		$search = function_exists( 'mb_substr' ) ? mb_substr( $search, 0, 100 ) : substr( $search, 0, 100 );
		if ( '' !== $search ) {
			$search_mode = sanitize_key( (string) $args['search_mode'] );
			if ( 'exact' === $search_mode ) {
				$where[] = $wpdb->prepare(
					'(cert_number_hash = %s OR holder_name = %s)',
					self::certificate_hash( $search ),
					$search
				);
			} else {
				$like = 'contains' === $search_mode
					? '%' . $wpdb->esc_like( $search ) . '%'
					: $wpdb->esc_like( $search ) . '%';
				$where[] = $wpdb->prepare( '(cert_number LIKE %s OR holder_name LIKE %s)', $like, $like );
			}
		}

		if ( ! empty( $args['legacy_expired'] ) ) {
			$where[] = $wpdb->prepare( '(status = %s OR status_source = %s)', 'expired', 'legacy' );
		} else {
			$status = sanitize_key( (string) $args['status'] );
			if ( 'expired' === $status ) {
				$where[] = $wpdb->prepare(
					'(status = %s OR status_source = %s OR (status NOT IN (%s, %s) AND expiry_date IS NOT NULL AND expiry_date < %s))',
					'expired',
					'legacy',
					'suspended',
					'withdrawn',
					current_time( 'Y-m-d' )
				);
			} elseif ( 'valid' === $status ) {
				$where[] = $wpdb->prepare(
					'(status <> %s AND status_source <> %s AND status NOT IN (%s, %s) AND (expiry_date IS NULL OR expiry_date >= %s))',
					'expired',
					'legacy',
					'suspended',
					'withdrawn',
					current_time( 'Y-m-d' )
				);
			} elseif ( in_array( $status, array( 'suspended', 'withdrawn' ), true ) ) {
				$where[] = $wpdb->prepare( 'status = %s', $status );
			}
		}

		$allowed_orderby = array( 'cert_number', 'holder_name', 'status', 'issue_date', 'expiry_date', 'created_at' );
		$orderby          = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
		$order            = 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';
		$order_expression = $orderby;
		if ( 'status' === $orderby ) {
			$order_expression = $wpdb->prepare(
				"CASE
					WHEN status = 'suspended' THEN 'suspended'
					WHEN status = 'withdrawn' THEN 'withdrawn'
					WHEN status = 'expired' OR status_source = 'legacy' THEN 'expired'
					WHEN expiry_date IS NOT NULL AND expiry_date < %s THEN 'expired'
					ELSE 'valid'
				END",
				current_time( 'Y-m-d' )
			);
		}

		$where_sql = implode( ' AND ', $where );
		$per_page  = min( 500, max( 1, absint( $args['per_page'] ) ) );
		$offset    = ( max( 1, absint( $args['page'] ) ) - 1 ) * $per_page;

		/*
		 * The WHERE fragments above are individually prepared. The ORDER column and
		 * direction are strict allow-list values. Preparing the identifier and paging
		 * fragments separately avoids feeding already-prepared LIKE values through a
		 * second prepare() call.
		 */
		$select_sql   = $wpdb->prepare( 'SELECT * FROM %i', $table );
		$paging_sql   = $wpdb->prepare( ' LIMIT %d OFFSET %d', $per_page, $offset );
		$prepared_sql = $select_sql . ' WHERE ' . $where_sql . ' ORDER BY ' . $order_expression . ' ' . $order . ', id ' . $order . $paging_sql;
		$rows         = $wpdb->get_results( $prepared_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Composed only from separately prepared clauses and strict allow-list identifiers above.
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
			return self::read_error();
		}

		$prepared_count_sql = $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) . ' WHERE ' . $where_sql;
		$total_raw          = $wpdb->get_var( $prepared_count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Composed only from a prepared table identifier and individually prepared WHERE clauses.
		if ( '' !== (string) $wpdb->last_error || null === $total_raw ) {
			return self::read_error();
		}

		return array(
			'items' => array_map( array( __CLASS__, 'decode_row' ), $rows ),
			'total' => (int) $total_raw,
		);
	}

	/** Starts a batch that defers secondary legacy-review recounts. */
	public static function begin_batch() {
		self::$batch_depth++;
	}

	/**
	 * Ends a write batch and performs one deferred recount.
	 *
	 * @return true|WP_Error
	 */
	public static function end_batch() {
		self::$batch_depth = max( 0, self::$batch_depth - 1 );
		if ( 0 === self::$batch_depth && self::$review_refresh_pending ) {
			self::$review_refresh_pending = false;
			$refreshed = self::refresh_legacy_expired_review_count();
			if ( is_wp_error( $refreshed ) ) {
				update_option( self::LEGACY_REVIEW_RETRY_OPTION, true, false );
				return $refreshed;
			}
			delete_option( self::LEGACY_REVIEW_RETRY_OPTION );
		}
		return true;
	}

	/**
	 * Backward-compatible method retained for older integrations and cron events.
	 * It now identifies legacy rows without writing a derived date state.
	 */
	public static function expire_valid_certificates( $today = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return self::preserve_legacy_expired_statuses();
	}

	/**
	 * Returns the status visible to administrators and visitors.
	 */
	public static function effective_status( $certificate ) {
		$status = isset( $certificate['status'] ) ? sanitize_key( $certificate['status'] ) : 'valid';
		if ( in_array( $status, array( 'suspended', 'withdrawn' ), true ) ) {
			return $status;
		}
		if ( self::is_legacy_expired( $certificate ) ) {
			return 'expired';
		}
		if ( ! empty( $certificate['expiry_date'] ) && $certificate['expiry_date'] < current_time( 'Y-m-d' ) ) {
			return 'expired';
		}
		return 'valid';
	}

	/**
	 * Explains where the effective status came from.
	 *
	 * @return string manual|date|legacy
	 */
	public static function effective_status_source( $certificate ) {
		$status = isset( $certificate['status'] ) ? sanitize_key( $certificate['status'] ) : 'valid';
		if ( in_array( $status, array( 'suspended', 'withdrawn' ), true ) ) {
			return 'manual';
		}
		if ( self::is_legacy_expired( $certificate ) ) {
			return 'legacy';
		}
		if ( ! empty( $certificate['expiry_date'] ) && $certificate['expiry_date'] < current_time( 'Y-m-d' ) ) {
			return 'date';
		}
		return 'manual';
	}

	/**
	 * Returns a portable stored workflow value for CSV exports. Ambiguous legacy
	 * Expired records remain Expired so export/re-import cannot silently validate
	 * them. Date-derived expiration exports as Valid plus its expiry date.
	 */
	public static function workflow_status( $certificate ) {
		$status = isset( $certificate['status'] ) ? sanitize_key( $certificate['status'] ) : 'valid';
		if ( in_array( $status, array( 'suspended', 'withdrawn' ), true ) ) {
			return $status;
		}
		return self::is_legacy_expired( $certificate ) ? 'expired' : 'valid';
	}

	public static function is_legacy_expired( $certificate ) {
		$status = isset( $certificate['status'] ) ? sanitize_key( $certificate['status'] ) : '';
		$source = isset( $certificate['status_source'] ) ? sanitize_key( $certificate['status_source'] ) : '';
		return 'expired' === $status || 'legacy' === $source;
	}

	/**
	 * @return int|WP_Error Number of ambiguous legacy Expired records or a database error.
	 */
	public static function count_legacy_expired() {
		global $wpdb;
		$table = self::table_name();
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE status = %s OR status_source = %s",
				$table,
				'expired',
				'legacy'
			)
		);
		if ( '' !== (string) $wpdb->last_error || null === $count ) {
			return self::read_error();
		}
		return (int) $count;
	}

	/**
	 * @return int|WP_Error Current review count or a database error.
	 */
	public static function refresh_legacy_expired_review_count() {
		$count = self::count_legacy_expired();
		if ( is_wp_error( $count ) ) {
			return $count;
		}
		$saved = update_option( self::LEGACY_REVIEW_OPTION, $count, false );
		if ( ! $saved && $count !== (int) get_option( self::LEGACY_REVIEW_OPTION, -1 ) ) {
			return new WP_Error( 'ecapla_certverify_review_count_save_failed', __( 'Certloom could not save the legacy Expired review count.', 'certloom' ) );
		}
		return $count;
	}


	/**
	 * Keeps a successful certificate write successful while scheduling a durable
	 * retry when the secondary legacy-review counter cannot be refreshed.
	 */
	private static function refresh_legacy_expired_review_count_after_write() {
		if ( self::$batch_depth > 0 ) {
			self::$review_refresh_pending = true;
			return;
		}
		$refreshed = self::refresh_legacy_expired_review_count();
		if ( is_wp_error( $refreshed ) ) {
			update_option( self::LEGACY_REVIEW_RETRY_OPTION, true, false );
			return;
		}
		delete_option( self::LEGACY_REVIEW_RETRY_OPTION );
	}

	private static function prepare_row( $data ) {
		$status = isset( $data['status'] ) ? sanitize_key( $data['status'] ) : 'valid';
		$source = 'expired' === $status ? 'legacy' : 'manual';

		return array(
			'cert_number'      => $data['cert_number'],
			'cert_number_hash' => self::certificate_hash( $data['cert_number'] ),
			'status'           => $status,
			'status_source' => $source,
			'holder_name'   => $data['holder_name'],
			'issue_date'    => empty( $data['issue_date'] ) ? null : $data['issue_date'],
			'expiry_date'   => empty( $data['expiry_date'] ) ? null : $data['expiry_date'],
			'custom_fields' => wp_json_encode( isset( $data['custom_fields'] ) && is_array( $data['custom_fields'] ) ? $data['custom_fields'] : array() ),
		);
	}

	private static function valid_mysql_datetime( $value ) {
		$value = (string) $value;
		$date  = DateTime::createFromFormat( 'Y-m-d H:i:s', $value );
		return $date && $date->format( 'Y-m-d H:i:s' ) === $value;
	}

	private static function decode_row( $row ) {
		if ( ! $row ) {
			return null;
		}
		$decoded              = ! empty( $row['custom_fields'] ) ? json_decode( $row['custom_fields'], true ) : array();
		$row['custom_fields'] = is_array( $decoded ) ? $decoded : array();
		if ( empty( $row['status_source'] ) ) {
			$row['status_source'] = isset( $row['status'] ) && 'expired' === $row['status'] ? 'legacy' : 'manual';
		}
		return $row;
	}

	/**
	 * Creates a generic database-read error without exposing SQL details.
	 *
	 * @return WP_Error
	 */
	private static function read_error() {
		return new WP_Error(
			'ecapla_certverify_database_read_failed',
			__( 'Certificate data is temporarily unavailable because the database could not be read.', 'certloom' )
		);
	}

	private static function database_error( $operation ) {
		global $wpdb;
		$duplicate = false !== stripos( (string) $wpdb->last_error, 'duplicate' );
		if ( $duplicate ) {
			return new WP_Error( 'ecapla_certverify_duplicate_number', __( 'That certificate number is already in use. Letter case is ignored.', 'certloom' ) );
		}
		return new WP_Error(
			'ecapla_certverify_database_error',
			'insert' === $operation ? __( 'The certificate could not be saved because of a database error.', 'certloom' ) : __( 'The certificate could not be updated because of a database error.', 'certloom' )
		);
	}
}

// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
