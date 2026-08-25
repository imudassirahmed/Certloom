<?php
namespace Certloom\Infrastructure\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Additive next-generation Certloom domain schema manager.
 *
 * The existing certificate table remains untouched until the dedicated
 * migration milestone. New tables can therefore be created, verified and
 * exercised without changing public verification truth for an existing site.
 */
final class SchemaManager {
	const SCHEMA_VERSION         = '15';
	const SCHEMA_OPTION          = 'certloom_domain_schema_version';
	const HEALTH_OPTION          = 'certloom_domain_schema_health';
	const LOCK_OPTION            = 'certloom_domain_schema_lock';
	const HEALTH_TTL             = DAY_IN_SECONDS;
	const LOCK_TTL               = 5 * MINUTE_IN_SECONDS;

	/** @var array<string,string> */
	private static $tables = array(
		'recipients'          => 'certloom_recipients',
		'programs'            => 'certloom_programs',
		'issuers'             => 'certloom_issuers',
		'signers'             => 'certloom_signers',
		'batches'             => 'certloom_batches',
		'batch_recipients'    => 'certloom_batch_recipients',
		'signer_assignments'  => 'certloom_signer_assignments',
		'templates'           => 'certloom_templates',
		'template_revisions'  => 'certloom_template_revisions',
		'template_assignments'=> 'certloom_template_assignments',
		'credential_series'   => 'certloom_credential_series',
		'credential_versions' => 'certloom_credential_versions',
		'credential_numbers'  => 'certloom_credential_numbers',
		'credential_events'   => 'certloom_credential_events',
		'jobs'                => 'certloom_jobs',
		'import_rows'         => 'certloom_import_rows',
		'restore_map'         => 'certloom_restore_map',
	);

	/**
	 * @param string $name Logical table name.
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;
		if ( ! isset( self::$tables[ $name ] ) ) {
			throw new \InvalidArgumentException( 'Unknown Certloom table: ' . $name );
		}
		return $wpdb->prefix . self::$tables[ $name ];
	}

	/**
	 * Returns the exact persisted columns for a Certloom-owned table contract.
	 * Restore/import infrastructure uses this whitelist instead of trusting keys
	 * from an uploaded backup payload.
	 *
	 * @param string $name Logical table name.
	 * @return string[]
	 */
	public static function column_names( $name ) {
		$contracts = self::contracts();
		if ( ! isset( $contracts[ $name ]['columns'] ) ) {
			throw new \InvalidArgumentException( 'Unknown Certloom table contract: ' . $name );
		}
		return array_keys( $contracts[ $name ]['columns'] );
	}

	/**
	 * Creates/upgrades and verifies the additive domain tables.
	 *
	 * @return true|\WP_Error
	 */
	public static function activate() {
		$lock = self::acquire_lock();
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		$result = self::activate_under_lock();
		$released = self::release_lock( $lock );
		if ( is_wp_error( $released ) ) {
			if ( is_wp_error( $result ) ) {
				return new \WP_Error(
					'certloom_domain_schema_failure_cleanup_failed',
					sprintf(
						/* translators: 1: schema error, 2: lock cleanup error. */
						__( 'Certloom domain schema work failed (%1$s) and its schema-maintenance lock could not be released safely (%2$s). Retry maintenance after the lease expires or is repaired.', 'certloom' ),
						$result->get_error_message(),
						$released->get_error_message()
					)
				);
			}
			return $released;
		}
		return $result;
	}

	/** @return true|\WP_Error */
	private static function activate_under_lock() {
		$created = self::create_or_update_tables();
		if ( is_wp_error( $created ) ) {
			return $created;
		}

		$migrated = self::run_migrations( (string) get_option( self::SCHEMA_OPTION, '0' ) );
		if ( is_wp_error( $migrated ) ) {
			return $migrated;
		}

		$health = self::schema_health();
		if ( is_wp_error( $health ) ) {
			return $health;
		}

		$saved = update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
		if ( ! $saved && self::SCHEMA_VERSION !== (string) get_option( self::SCHEMA_OPTION, '0' ) ) {
			return new \WP_Error( 'certloom_domain_schema_marker_failed', __( 'Certloom created its new domain tables but could not save the schema marker.', 'certloom' ) );
		}

		if ( ! self::save_health_marker() ) {
			return new \WP_Error( 'certloom_domain_schema_health_marker_failed', __( 'Certloom verified its new domain tables but could not save the health marker.', 'certloom' ) );
		}

		return true;
	}

	/**
	 * Runs schema work only in maintenance-capable requests.
	 *
	 * @return true|\WP_Error
	 */
	public static function maybe_upgrade() {
		if ( ! self::can_run_schema_work() ) {
			return true;
		}

		$current = self::SCHEMA_VERSION === (string) get_option( self::SCHEMA_OPTION, '0' );
		if ( $current && self::health_marker_is_fresh() ) {
			return true;
		}

		return self::activate();
	}

	/** @return bool */
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
	 * Migration runner intentionally contains no data rewrite yet. Schema version 2
	 * adds the legacy-expired compatibility column/index through the explicit
	 * table contract/dbDelta path. Future semantic/data migrations must be
	 * ordered and idempotent here rather than hidden in generic repair loops.
	 *
	 * @param string $installed Installed domain schema version.
	 * @return true|\WP_Error
	 */
	private static function run_migrations( $installed ) {
		if ( version_compare( (string) $installed, '4', '<' ) ) {
			$backfilled = self::backfill_credential_numbers();
			if ( is_wp_error( $backfilled ) ) { return $backfilled; }
			$changed = self::migrate_version_display_number_index();
			if ( is_wp_error( $changed ) ) { return $changed; }
		}
		return true;
	}

	/** Backfills the number-assignment registry before the old Version-unique index is relaxed. @return true|\WP_Error */
	private static function backfill_credential_numbers() {
		global $wpdb;
		$numbers = self::table( 'credential_numbers' );
		$versions = self::table( 'credential_versions' );
		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time versioned migration between Certloom-owned tables.
		$result = $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (display_number, series_id, first_version_id, created_at, retired_at) SELECT display_number, series_id, id, created_at, NULL FROM %i WHERE display_number <> %s',
				$numbers,
				$versions,
				''
			)
		);
		return false === $result || '' !== (string) $wpdb->last_error
			? new \WP_Error( 'certloom_domain_number_backfill_failed', __( 'Certloom could not backfill its credential-number assignments.', 'certloom' ) )
			: true;
	}

	/**
	 * Version 4 moves global certificate-number uniqueness out of immutable
	 * Versions and into credential_numbers so one Series may reuse its human
	 * number across correction Versions without permitting another Series to
	 * claim the same number.
	 *
	 * @return true|\WP_Error
	 */
	private static function migrate_version_display_number_index() {
		global $wpdb;
		$table = self::table( 'credential_versions' );
		$wpdb->last_error = '';
		$indexes = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $table, 'display_number' ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'certloom_domain_number_index_read_failed', __( 'Certloom could not inspect its credential-number index.', 'certloom' ) );
		}
		$needs_change = false;
		foreach ( (array) $indexes as $index ) {
			if ( isset( $index['Non_unique'] ) && 0 === (int) $index['Non_unique'] ) { $needs_change = true; break; }
		}
		if ( ! $needs_change ) { return true; }
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Explicit versioned Certloom-owned index migration.
		$changed = $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP INDEX display_number, ADD KEY display_number (display_number)', $table ) );
		return false === $changed || '' !== (string) $wpdb->last_error
			? new \WP_Error( 'certloom_domain_number_index_migration_failed', __( 'Certloom could not migrate its credential-number index safely.', 'certloom' ) )
			: true;
	}

	/** @return true|\WP_Error */
	private static function create_or_update_tables() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		$tables          = array();

		$recipients = self::table( 'recipients' );
		$tables['recipients'] = "CREATE TABLE $recipients (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			display_name VARCHAR(255) NOT NULL,
			first_name VARCHAR(191) NULL,
			middle_name VARCHAR(191) NULL,
			last_name VARCHAR(191) NULL,
			email VARCHAR(320) NULL,
			privacy_identity_hashes LONGTEXT NULL,
			privacy_anonymized_at DATETIME NULL,
			wp_user_id BIGINT UNSIGNED NULL,
			external_id VARCHAR(191) NULL,
			custom_fields LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY wp_user_id (wp_user_id),
			KEY email (email(191)),
			KEY external_id (external_id)
		) ENGINE=InnoDB $charset_collate;";

		$issuers = self::table( 'issuers' );
		$tables['issuers'] = "CREATE TABLE $issuers (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(255) NOT NULL,
			slug VARCHAR(191) NOT NULL,
			logo_attachment_id BIGINT UNSIGNED NULL,
			seal_attachment_id BIGINT UNSIGNED NULL,
			settings_json LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY name (name(191))
		) ENGINE=InnoDB $charset_collate;";

		$programs = self::table( 'programs' );
		$tables['programs'] = "CREATE TABLE $programs (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			issuer_id BIGINT UNSIGNED NULL,
			code VARCHAR(100) NULL,
			title VARCHAR(255) NOT NULL,
			description LONGTEXT NULL,
			validity_days INT UNSIGNED NULL,
			active TINYINT(1) NOT NULL DEFAULT 1,
			metadata_json LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY code (code),
			KEY issuer_id (issuer_id),
			KEY active (active),
			KEY title (title(191))
		) ENGINE=InnoDB $charset_collate;";

		$signers = self::table( 'signers' );
		$tables['signers'] = "CREATE TABLE $signers (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			display_name VARCHAR(255) NOT NULL,
			slug VARCHAR(191) NOT NULL,
			title VARCHAR(191) NULL,
			email VARCHAR(320) NULL,
			signature_attachment_id BIGINT UNSIGNED NULL,
			active TINYINT(1) NOT NULL DEFAULT 1,
			metadata_json LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY active (active),
			KEY display_name (display_name(191)),
			KEY email (email(191))
		) ENGINE=InnoDB $charset_collate;";

		$batches = self::table( 'batches' );
		$tables['batches'] = "CREATE TABLE $batches (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			program_id BIGINT UNSIGNED NOT NULL,
			code VARCHAR(100) NOT NULL,
			name VARCHAR(255) NOT NULL,
			start_date DATE NULL,
			end_date DATE NULL,
			instructor_name VARCHAR(255) NULL,
			instructor_signer_id BIGINT UNSIGNED NULL,
			active TINYINT(1) NOT NULL DEFAULT 1,
			metadata_json LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY code (code),
			KEY program_id (program_id),
			KEY instructor_signer_id (instructor_signer_id),
			KEY active (active),
			KEY start_date (start_date),
			KEY end_date (end_date),
			KEY name (name(191))
		) ENGINE=InnoDB $charset_collate;";

		$batch_recipients = self::table( 'batch_recipients' );
		$tables['batch_recipients'] = "CREATE TABLE $batch_recipients (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			batch_id BIGINT UNSIGNED NOT NULL,
			recipient_id BIGINT UNSIGNED NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY batch_recipient (batch_id,recipient_id),
			KEY recipient_id (recipient_id)
		) ENGINE=InnoDB $charset_collate;";

		$signer_assignments = self::table( 'signer_assignments' );
		$tables['signer_assignments'] = "CREATE TABLE $signer_assignments (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			signer_id BIGINT UNSIGNED NOT NULL,
			scope_type VARCHAR(20) NOT NULL,
			scope_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			role_type VARCHAR(20) NOT NULL DEFAULT 'signer',
			priority INT UNSIGNED NOT NULL DEFAULT 100,
			active TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY signer_scope (signer_id,scope_type,scope_id,role_type),
			KEY scope_lookup (scope_type,scope_id,active,priority),
			KEY signer_id (signer_id),
			KEY role_type (role_type)
		) ENGINE=InnoDB $charset_collate;";

		$templates = self::table( 'templates' );
		$tables['templates'] = "CREATE TABLE $templates (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			machine_key VARCHAR(191) NOT NULL,
			name VARCHAR(255) NOT NULL,
			description LONGTEXT NULL,
			current_revision_id BIGINT UNSIGNED NULL,
			published_revision_id BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			archived_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY machine_key (machine_key),
			KEY current_revision_id (current_revision_id),
			KEY published_revision_id (published_revision_id),
			KEY archived_at (archived_at),
			KEY name (name(191))
		) ENGINE=InnoDB $charset_collate;";

		$template_revisions = self::table( 'template_revisions' );
		$tables['template_revisions'] = "CREATE TABLE $template_revisions (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			template_id BIGINT UNSIGNED NOT NULL,
			revision_number INT UNSIGNED NOT NULL,
			parent_revision_id BIGINT UNSIGNED NULL,
			layout_schema VARCHAR(64) NOT NULL,
			layout_schema_version INT UNSIGNED NOT NULL,
			layout_json LONGTEXT NOT NULL,
			layout_sha256 CHAR(64) NOT NULL,
			source_type VARCHAR(20) NOT NULL DEFAULT 'manual',
			created_by_user_id BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY template_revision (template_id,revision_number),
			KEY parent_revision_id (parent_revision_id),
			KEY layout_sha256 (layout_sha256),
			KEY source_type (source_type),
			KEY created_by_user_id (created_by_user_id)
		) ENGINE=InnoDB $charset_collate;";

		$template_assignments = self::table( 'template_assignments' );
		$tables['template_assignments'] = "CREATE TABLE $template_assignments (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			template_id BIGINT UNSIGNED NOT NULL,
			scope_type VARCHAR(20) NOT NULL,
			scope_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			priority INT UNSIGNED NOT NULL DEFAULT 100,
			active TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY scope_assignment (scope_type,scope_id),
			KEY template_id (template_id),
			KEY scope_lookup (scope_type,scope_id,active,priority)
		) ENGINE=InnoDB $charset_collate;";

		$series = self::table( 'credential_series' );
		$tables['credential_series'] = "CREATE TABLE $series (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			public_id CHAR(12) NOT NULL,
			recipient_id BIGINT UNSIGNED NOT NULL,
			program_id BIGINT UNSIGNED NULL,
			batch_id BIGINT UNSIGNED NULL,
			issuer_id BIGINT UNSIGNED NULL,
			current_version_id BIGINT UNSIGNED NULL,
			administrative_state VARCHAR(20) NOT NULL DEFAULT 'active',
			compliance_state VARCHAR(20) NOT NULL DEFAULT 'not_applicable',
			visibility VARCHAR(32) NOT NULL DEFAULT 'verification_summary',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			closed_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY public_id (public_id),
			KEY recipient_id (recipient_id),
			KEY program_id (program_id),
			KEY batch_id (batch_id),
			KEY issuer_id (issuer_id),
			KEY current_version_id (current_version_id),
			KEY administrative_state (administrative_state),
			KEY compliance_state (compliance_state),
			KEY visibility (visibility)
		) ENGINE=InnoDB $charset_collate;";

		$versions = self::table( 'credential_versions' );
		$tables['credential_versions'] = "CREATE TABLE $versions (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			series_id BIGINT UNSIGNED NOT NULL,
			version_number INT UNSIGNED NOT NULL,
			previous_version_id BIGINT UNSIGNED NULL,
			display_number VARCHAR(100) NOT NULL,
			issuance_state VARCHAR(20) NOT NULL DEFAULT 'draft',
			legacy_expired TINYINT(1) NOT NULL DEFAULT 0,
			valid_from DATE NULL,
			issued_at DATETIME NULL,
			expires_at DATE NULL,
			grade VARCHAR(100) NULL,
			source_type VARCHAR(64) NULL,
			source_ref VARCHAR(191) NULL,
			source_idempotency_key CHAR(64) NULL,
			snapshot_json LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY series_version (series_id,version_number),
			KEY previous_version_id (previous_version_id),
			KEY display_number (display_number),
			UNIQUE KEY source_idempotency (source_type,source_idempotency_key),
			KEY issuance_state (issuance_state),
			KEY legacy_expired (legacy_expired),
			KEY expires_at (expires_at),
			KEY issued_at (issued_at)
		) ENGINE=InnoDB $charset_collate;";

		$numbers = self::table( 'credential_numbers' );
		$tables['credential_numbers'] = "CREATE TABLE $numbers (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			display_number VARCHAR(100) NOT NULL,
			series_id BIGINT UNSIGNED NOT NULL,
			first_version_id BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			retired_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY display_number (display_number),
			KEY series_id (series_id),
			KEY first_version_id (first_version_id),
			KEY retired_at (retired_at)
		) ENGINE=InnoDB $charset_collate;";

		$events = self::table( 'credential_events' );
		$tables['credential_events'] = "CREATE TABLE $events (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			series_id BIGINT UNSIGNED NOT NULL,
			version_id BIGINT UNSIGNED NULL,
			event_type VARCHAR(64) NOT NULL,
			actor_user_id BIGINT UNSIGNED NULL,
			old_state_json LONGTEXT NULL,
			new_state_json LONGTEXT NULL,
			reason_code VARCHAR(64) NULL,
			public_reason TEXT NULL,
			internal_note TEXT NULL,
			meta_json LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY series_created (series_id,created_at),
			KEY version_id (version_id),
			KEY event_type (event_type),
			KEY actor_user_id (actor_user_id)
		) ENGINE=InnoDB $charset_collate;";


		$jobs = self::table( 'jobs' );
		$tables['jobs'] = "CREATE TABLE $jobs (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			job_key CHAR(32) NOT NULL,
			job_type VARCHAR(64) NOT NULL,
			status VARCHAR(32) NOT NULL DEFAULT 'queued',
			owner_user_id BIGINT UNSIGNED NULL,
			parent_job_key CHAR(32) NULL,
			phase VARCHAR(64) NULL,
			cursor BIGINT UNSIGNED NOT NULL DEFAULT 0,
			total BIGINT UNSIGNED NULL,
			selection_max_id BIGINT UNSIGNED NULL,
			payload_json LONGTEXT NULL,
			result_json LONGTEXT NULL,
			error_json LONGTEXT NULL,
			attempts INT UNSIGNED NOT NULL DEFAULT 0,
			recovery_count INT UNSIGNED NOT NULL DEFAULT 0,
			available_at DATETIME NULL,
			locked_by CHAR(32) NULL,
			locked_until DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			finished_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY job_key (job_key),
			KEY status_available (status,available_at),
			KEY owner_status (owner_user_id,status),
			KEY type_status (job_type,status),
			KEY parent_job_key (parent_job_key),
			KEY locked_until (locked_until)
		) ENGINE=InnoDB $charset_collate;";

		$import_rows = self::table( 'import_rows' );
		$tables['import_rows'] = "CREATE TABLE $import_rows (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			job_key CHAR(32) NOT NULL,
			row_number BIGINT UNSIGNED NOT NULL,
			display_number VARCHAR(100) NOT NULL,
			plan_hash CHAR(64) NOT NULL,
			plan_json LONGTEXT NOT NULL,
			existing_series_id BIGINT UNSIGNED NULL,
			outcome VARCHAR(20) NOT NULL DEFAULT 'ready',
			commit_outcome VARCHAR(20) NULL,
			target_series_id BIGINT UNSIGNED NULL,
			target_version_id BIGINT UNSIGNED NULL,
			committed_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY job_row (job_key,row_number),
			UNIQUE KEY job_display (job_key,display_number),
			KEY job_outcome (job_key,outcome),
			KEY job_commit (job_key,commit_outcome),
			KEY existing_series_id (existing_series_id)
		) ENGINE=InnoDB $charset_collate;";

		$restore_map = self::table( 'restore_map' );
		$tables['restore_map'] = "CREATE TABLE $restore_map (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			owner_token CHAR(32) NOT NULL,
			entity VARCHAR(32) NOT NULL,
			source_id BIGINT UNSIGNED NOT NULL,
			source_hash CHAR(64) NOT NULL,
			target_id BIGINT UNSIGNED NULL,
			action VARCHAR(20) NOT NULL,
			meta_json LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY owner_entity_source (owner_token,entity,source_id),
			KEY owner_entity_action (owner_token,entity,action),
			KEY owner_target (owner_token,entity,target_id)
		) ENGINE=InnoDB $charset_collate;";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		foreach ( $tables as $logical_name => $sql ) {
			$wpdb->last_error = '';
			dbDelta( $sql );
			if ( '' !== (string) $wpdb->last_error ) {
				delete_option( self::HEALTH_OPTION );
				return new \WP_Error(
					'certloom_domain_table_setup_failed',
					__( 'Certloom could not create or update one of its new domain tables.', 'certloom' ),
					array( 'stage' => 'dbdelta', 'object' => sanitize_key( (string) $logical_name ) )
				);
			}
		}

		delete_option( self::HEALTH_OPTION );
		return true;
	}

	/**
	 * Verifies required columns and indexes independently of the saved version.
	 *
	 * @return true|\WP_Error
	 */
	private static function schema_health() {
		global $wpdb;
		foreach ( self::contracts() as $logical_name => $contract ) {
			$table = self::table( $logical_name );

			/* Transactions, row locks, and atomic lifecycle commands require InnoDB. */
			$wpdb->last_error = '';
			$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $table ) ), ARRAY_A );
			if ( '' !== (string) $wpdb->last_error || ! is_array( $status ) || 'innodb' !== strtolower( (string) ( $status['Engine'] ?? '' ) ) ) {
				return new \WP_Error( 'certloom_domain_engine_unsupported', __( 'Certloom requires InnoDB for its credential-domain transaction and row-lock guarantees.', 'certloom' ), array( 'stage' => 'engine', 'object' => sanitize_key( (string) $logical_name ) ) );
			}

			$wpdb->last_error = '';
			$columns = $wpdb->get_results( $wpdb->prepare( 'SHOW FULL COLUMNS FROM %i', $table ), ARRAY_A );
			if ( '' !== (string) $wpdb->last_error || ! is_array( $columns ) || empty( $columns ) ) {
				return new \WP_Error( 'certloom_domain_table_missing', __( 'Certloom could not verify one of its required domain tables.', 'certloom' ), array( 'stage' => 'columns', 'object' => sanitize_key( (string) $logical_name ) ) );
			}

			$found_columns = array();
			foreach ( $columns as $column ) {
				if ( isset( $column['Field'] ) ) {
					$found_columns[ strtolower( (string) $column['Field'] ) ] = $column;
				}
			}

			foreach ( $contract['columns'] as $name => $expected ) {
				if ( ! isset( $found_columns[ $name ] ) ) {
					return new \WP_Error( 'certloom_domain_columns_missing', __( 'Certloom detected a missing domain-table column.', 'certloom' ), array( 'stage' => 'columns', 'object' => sanitize_key( (string) $logical_name ), 'field' => sanitize_key( (string) $name ) ) );
				}
				$column = $found_columns[ $name ];
				$type   = strtolower( (string) ( $column['Type'] ?? '' ) );
				$null   = strtoupper( (string) ( $column['Null'] ?? '' ) );
				if ( ! preg_match( $expected['type'], $type ) || $expected['null'] !== $null ) {
					return new \WP_Error( 'certloom_domain_column_mismatch', __( 'Certloom detected an incompatible domain-table column definition.', 'certloom' ), array( 'stage' => 'columns', 'object' => sanitize_key( (string) $logical_name ), 'field' => sanitize_key( (string) $name ) ) );
				}
				if ( array_key_exists( 'default', $expected ) && (string) $expected['default'] !== (string) ( $column['Default'] ?? '' ) ) {
					return new \WP_Error( 'certloom_domain_column_mismatch', __( 'Certloom detected an incompatible domain-table column default.', 'certloom' ), array( 'stage' => 'columns', 'object' => sanitize_key( (string) $logical_name ), 'field' => sanitize_key( (string) $name ) ) );
				}
				if ( isset( $expected['extra'] ) && false === strpos( strtolower( (string) ( $column['Extra'] ?? '' ) ), $expected['extra'] ) ) {
					return new \WP_Error( 'certloom_domain_column_mismatch', __( 'Certloom detected an incompatible domain-table identity column.', 'certloom' ), array( 'stage' => 'columns', 'object' => sanitize_key( (string) $logical_name ), 'field' => sanitize_key( (string) $name ) ) );
				}
			}

			$wpdb->last_error = '';
			$indexes = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', $table ), ARRAY_A );
			if ( '' !== (string) $wpdb->last_error || ! is_array( $indexes ) || empty( $indexes ) ) {
				return new \WP_Error( 'certloom_domain_indexes_missing', __( 'Certloom could not verify its required domain-table indexes.', 'certloom' ), array( 'stage' => 'indexes', 'object' => sanitize_key( (string) $logical_name ) ) );
			}

			$found_indexes = array();
			foreach ( $indexes as $index ) {
				if ( ! isset( $index['Key_name'], $index['Column_name'] ) ) {
					continue;
				}
				$key = strtolower( (string) $index['Key_name'] );
				$seq = max( 1, absint( $index['Seq_in_index'] ?? 1 ) );
				$found_indexes[ $key ]['columns'][ $seq ] = strtolower( (string) $index['Column_name'] );
				$found_indexes[ $key ]['unique'] = isset( $index['Non_unique'] ) && 0 === (int) $index['Non_unique'];
			}

			foreach ( $contract['indexes'] as $name => $expected ) {
				$name = strtolower( $name );
				if ( ! isset( $found_indexes[ $name ] ) ) {
					return new \WP_Error( 'certloom_domain_indexes_missing', __( 'Certloom detected a missing required domain-table index.', 'certloom' ), array( 'stage' => 'indexes', 'object' => sanitize_key( (string) $logical_name ), 'index' => sanitize_key( (string) $name ) ) );
				}
				ksort( $found_indexes[ $name ]['columns'] );
				if ( array_values( $expected['columns'] ) !== array_values( $found_indexes[ $name ]['columns'] ) || (bool) $expected['unique'] !== (bool) $found_indexes[ $name ]['unique'] ) {
					return new \WP_Error( 'certloom_domain_index_mismatch', __( 'Certloom detected an incompatible required domain-table index.', 'certloom' ), array( 'stage' => 'indexes', 'object' => sanitize_key( (string) $logical_name ), 'index' => sanitize_key( (string) $name ) ) );
				}
			}
		}

		return true;
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private static function contracts() {
		$bigu = '/^bigint(?:\(20\))? unsigned$/';
		$dt   = '/^datetime$/';
		$date = '/^date$/';
		$lt   = '/^(?:longtext|text)$/';
		return array(
			'recipients' => array(
				'columns' => array(
					'id' => array( 'type' => $bigu, 'null' => 'NO', 'extra' => 'auto_increment' ),
					'display_name' => array( 'type' => '/^varchar\(255\)$/', 'null' => 'NO' ),
					'first_name' => array( 'type' => '/^varchar\(191\)$/', 'null' => 'YES' ),
					'middle_name' => array( 'type' => '/^varchar\(191\)$/', 'null' => 'YES' ),
					'last_name' => array( 'type' => '/^varchar\(191\)$/', 'null' => 'YES' ),
					'email' => array( 'type' => '/^varchar\(320\)$/', 'null' => 'YES' ),
					'privacy_identity_hashes' => array( 'type' => $lt, 'null' => 'YES' ),
					'privacy_anonymized_at' => array( 'type' => $dt, 'null' => 'YES' ),
					'wp_user_id' => array( 'type' => $bigu, 'null' => 'YES' ),
					'external_id' => array( 'type' => '/^varchar\(191\)$/', 'null' => 'YES' ),
					'custom_fields' => array( 'type' => $lt, 'null' => 'YES' ),
					'created_at' => array( 'type' => $dt, 'null' => 'NO' ),
					'updated_at' => array( 'type' => $dt, 'null' => 'NO' ),
				),
				'indexes' => array(
					'PRIMARY' => array( 'columns' => array( 'id' ), 'unique' => true ),
					'wp_user_id' => array( 'columns' => array( 'wp_user_id' ), 'unique' => true ),
					'email' => array( 'columns' => array( 'email' ), 'unique' => false ),
					'external_id' => array( 'columns' => array( 'external_id' ), 'unique' => false ),
				),
			),
			'programs' => array(
				'columns' => array(
					'id' => array( 'type' => $bigu, 'null' => 'NO', 'extra' => 'auto_increment' ),
					'issuer_id' => array( 'type' => $bigu, 'null' => 'YES' ),
					'code' => array( 'type' => '/^varchar\(100\)$/', 'null' => 'YES' ),
					'title' => array( 'type' => '/^varchar\(255\)$/', 'null' => 'NO' ),
					'description' => array( 'type' => $lt, 'null' => 'YES' ),
					'validity_days' => array( 'type' => '/^int(?:\(10\))? unsigned$/', 'null' => 'YES' ),
					'active' => array( 'type' => '/^tinyint(?:\(1\))?$/', 'null' => 'NO', 'default' => '1' ),
					'metadata_json' => array( 'type' => $lt, 'null' => 'YES' ),
					'created_at' => array( 'type' => $dt, 'null' => 'NO' ),
					'updated_at' => array( 'type' => $dt, 'null' => 'NO' ),
				),
				'indexes' => array(
					'PRIMARY' => array( 'columns' => array( 'id' ), 'unique' => true ),
					'code' => array( 'columns' => array( 'code' ), 'unique' => true ),
					'issuer_id' => array( 'columns' => array( 'issuer_id' ), 'unique' => false ),
					'active' => array( 'columns' => array( 'active' ), 'unique' => false ),
					'title' => array( 'columns' => array( 'title' ), 'unique' => false ),
				),
			),
			'issuers' => array(
				'columns' => array(
					'id' => array( 'type' => $bigu, 'null' => 'NO', 'extra' => 'auto_increment' ),
					'name' => array( 'type' => '/^varchar\(255\)$/', 'null' => 'NO' ),
					'slug' => array( 'type' => '/^varchar\(191\)$/', 'null' => 'NO' ),
					'logo_attachment_id' => array( 'type' => $bigu, 'null' => 'YES' ),
					'seal_attachment_id' => array( 'type' => $bigu, 'null' => 'YES' ),
					'settings_json' => array( 'type' => $lt, 'null' => 'YES' ),
					'created_at' => array( 'type' => $dt, 'null' => 'NO' ),
					'updated_at' => array( 'type' => $dt, 'null' => 'NO' ),
				),
				'indexes' => array(
					'PRIMARY' => array( 'columns' => array( 'id' ), 'unique' => true ),
					'slug' => array( 'columns' => array( 'slug' ), 'unique' => true ),
					'name' => array( 'columns' => array( 'name' ), 'unique' => false ),
				),
			),
			'signers' => array(
				'columns' => array(
					'id' => array( 'type' => $bigu, 'null' => 'NO', 'extra' => 'auto_increment' ),
					'display_name' => array( 'type' => '/^varchar\(255\)$/', 'null' => 'NO' ),
					'slug' => array( 'type' => '/^varchar\(191\)$/', 'null' => 'NO' ),
					'title' => array( 'type' => '/^varchar\(191\)$/', 'null' => 'YES' ),
					'email' => array( 'type' => '/^varchar\(320\)$/', 'null' => 'YES' ),
					'signature_attachment_id' => array( 'type' => $bigu, 'null' => 'YES' ),
					'active' => array( 'type' => '/^tinyint(?:\(1\))?$/', 'null' => 'NO', 'default' => '1' ),
					'metadata_json' => array( 'type' => $lt, 'null' => 'YES' ),
					'created_at' => array( 'type' => $dt, 'null' => 'NO' ),
					'updated_at' => array( 'type' => $dt, 'null' => 'NO' ),
				),
				'indexes' => array(
					'PRIMARY' => array( 'columns' => array( 'id' ), 'unique' => true ),
					'slug' => array( 'columns' => array( 'slug' ), 'unique' => true ),
					'active' => array( 'columns' => array( 'active' ), 'unique' => false ),
					'display_name' => array( 'columns' => array( 'display_name' ), 'unique' => false ),
					'email' => array( 'columns' => array( 'email' ), 'unique' => false ),
				),
			),
			'batches' => array(
				'columns' => array(
					'id' => array( 'type' => $bigu, 'null' => 'NO', 'extra' => 'auto_increment' ),
					'program_id' => array( 'type' => $bigu, 'null' => 'NO' ),
					'code' => array( 'type' => '/^varchar\(100\)$/', 'null' => 'NO' ),
					'name' => array( 'type' => '/^varchar\(255\)$/', 'null' => 'NO' ),
					'start_date' => array( 'type' => $date, 'null' => 'YES' ),
					'end_date' => array( 'type' => $date, 'null' => 'YES' ),
					'instructor_name' => array( 'type' => '/^varchar\(255\)$/', 'null' => 'YES' ),
					'instructor_signer_id' => array( 'type' => $bigu, 'null' => 'YES' ),
					'active' => array( 'type' => '/^tinyint(?:\(1\))?$/', 'null' => 'NO', 'default' => '1' ),
					'metadata_json' => array( 'type' => $lt, 'null' => 'YES' ),
					'created_at' => array( 'type' => $dt, 'null' => 'NO' ),
					'updated_at' => array( 'type' => $dt, 'null' => 'NO' ),
				),
				'indexes' => array(
					'PRIMARY' => array( 'columns' => array( 'id' ), 'unique' => true ),
					'code' => array( 'columns' => array( 'code' ), 'unique' => true ),
					'program_id' => array( 'columns' => array( 'program_id' ), 'unique' => false ),
					'instructor_signer_id' => array( 'columns' => array( 'instructor_signer_id' ), 'unique' => false ),
					'active' => array( 'columns' => array( 'active' ), 'unique' => false ),
					'start_date' => array( 'columns' => array( 'start_date' ), 'unique' => false ),
					'end_date' => array( 'columns' => array( 'end_date' ), 'unique' => false ),
					'name' => array( 'columns' => array( 'name' ), 'unique' => false ),
				),
			),
			'batch_recipients' => array(
				'columns' => array(
					'id' => array( 'type' => $bigu, 'null' => 'NO', 'extra' => 'auto_increment' ),
					'batch_id' => array( 'type' => $bigu, 'null' => 'NO' ),
					'recipient_id' => array( 'type' => $bigu, 'null' => 'NO' ),
					'created_at' => array( 'type' => $dt, 'null' => 'NO' ),
				),
				'indexes' => array(
					'PRIMARY' => array( 'columns' => array( 'id' ), 'unique' => true ),
					'batch_recipient' => array( 'columns' => array( 'batch_id', 'recipient_id' ), 'unique' => true ),
					'recipient_id' => array( 'columns' => array( 'recipient_id' ), 'unique' => false ),
				),
			),
			'signer_assignments' => array(
				'columns' => array(
					'id' => array( 'type' => $bigu, 'null' => 'NO', 'extra' => 'auto_increment' ),
					'signer_id' => array( 'type' => $bigu, 'null' => 'NO' ),
					'scope_type' => array( 'type' => '/^varchar\(20\)$/', 'null' => 'NO' ),
					'scope_id' => array( 'type' => $bigu, 'null' => 'NO', 'default' => '0' ),
					'role_type' => array( 'type' => '/^varchar\(20\)$/', 'null' => 'NO', 'default' => 'signer' ),
					'priority' => array( 'type' => '/^int(?:\(10\))? unsigned$/', 'null' => 'NO', 'default' => '100' ),
					'active' => array( 'type' => '/^tinyint(?:\(1\))?$/', 'null' => 'NO', 'default' => '1' ),
					'created_at' => array( 'type' => $dt, 'null' => 'NO' ),
					'updated_at' => array( 'type' => $dt, 'null' => 'NO' ),
				),
				'indexes' => array(
					'PRIMARY' => array( 'columns' => array( 'id' ), 'unique' => true ),
					'signer_scope' => array( 'columns' => array( 'signer_id', 'scope_type', 'scope_id', 'role_type' ), 'unique' => true ),
					'scope_lookup' => array( 'columns' => array( 'scope_type', 'scope_id', 'active', 'priority' ), 'unique' => false ),
					'signer_id' => array( 'columns' => array( 'signer_id' ), 'unique' => false ),
					'role_type' => array( 'columns' => array( 'role_type' ), 'unique' => false ),
				),
			),
			'templates' => array(
				'columns' => array(
					'id' => array( 'type' => $bigu, 'null' => 'NO', 'extra' => 'auto_increment' ),
					'machine_key' => array( 'type' => '/^varchar\(191\)$/', 'null' => 'NO' ),
					'name' => array( 'type' => '/^varchar\(255\)$/', 'null' => 'NO' ),
					'description' => array( 'type' => $lt, 'null' => 'YES' ),
					'current_revision_id' => array( 'type' => $bigu, 'null' => 'YES' ),
					'published_revision_id' => array( 'type' => $bigu, 'null' => 'YES' ),
					'created_at' => array( 'type' => $dt, 'null' => 'NO' ),
					'updated_at' => array( 'type' => $dt, 'null' => 'NO' ),
					'archived_at' => array( 'type' => $dt, 'null' => 'YES' ),
				),
				'indexes' => array(
					'PRIMARY' => array( 'columns' => array( 'id' ), 'unique' => true ),
					'machine_key' => array( 'columns' => array( 'machine_key' ), 'unique' => true ),
					'current_revision_id' => array( 'columns' => array( 'current_revision_id' ), 'unique' => false ),
					'published_revision_id' => array( 'columns' => array( 'published_revision_id' ), 'unique' => false ),
					'archived_at' => array( 'columns' => array( 'archived_at' ), 'unique' => false ),
					'name' => array( 'columns' => array( 'name' ), 'unique' => false ),
				),
			),
			'template_revisions' => array(
				'columns' => array(
					'id' => array( 'type' => $bigu, 'null' => 'NO', 'extra' => 'auto_increment' ),
					'template_id' => array( 'type' => $bigu, 'null' => 'NO' ),
					'revision_number' => array( 'type' => '/^int(?:\(10\))? unsigned$/', 'null' => 'NO' ),
					'parent_revision_id' => array( 'type' => $bigu, 'null' => 'YES' ),
					'layout_schema' => array( 'type' => '/^varchar\(64\)$/', 'null' => 'NO' ),
					'layout_schema_version' => array( 'type' => '/^int(?:\(10\))? unsigned$/', 'null' => 'NO' ),
					'layout_json' => array( 'type' => $lt, 'null' => 'NO' ),
					'layout_sha256' => array( 'type' => '/^char\(64\)$/', 'null' => 'NO' ),
					'source_type' => array( 'type' => '/^varchar\(20\)$/', 'null' => 'NO', 'default' => 'manual' ),
					'created_by_user_id' => array( 'type' => $bigu, 'null' => 'YES' ),
					'created_at' => array( 'type' => $dt, 'null' => 'NO' ),
				),
				'indexes' => array(
					'PRIMARY' => array( 'columns' => array( 'id' ), 'unique' => true ),
					'template_revision' => array( 'columns' => array( 'template_id', 'revision_number' ), 'unique' => true ),
					'parent_revision_id' => array( 'columns' => array( 'parent_revision_id' ), 'unique' => false ),
					'layout_sha256' => array( 'columns' => array( 'layout_sha256' ), 'unique' => false ),
					'source_type' => array( 'columns' => array( 'source_type' ), 'unique' => false ),
					'created_by_user_id' => array( 'columns' => array( 'created_by_user_id' ), 'unique' => false ),
				),
			),
			'template_assignments' => array(
				'columns' => array(
					'id' => array( 'type' => $bigu, 'null' => 'NO', 'extra' => 'auto_increment' ),
					'template_id' => array( 'type' => $bigu, 'null' => 'NO' ),
					'scope_type' => array( 'type' => '/^varchar\(20\)$/', 'null' => 'NO' ),
					'scope_id' => array( 'type' => $bigu, 'null' => 'NO', 'default' => '0' ),
					'priority' => array( 'type' => '/^int(?:\(10\))? unsigned$/', 'null' => 'NO', 'default' => '100' ),
					'active' => array( 'type' => '/^tinyint(?:\(1\))?$/', 'null' => 'NO', 'default' => '1' ),
					'created_at' => array( 'type' => $dt, 'null' => 'NO' ),
					'updated_at' => array( 'type' => $dt, 'null' => 'NO' ),
				),
				'indexes' => array(
					'PRIMARY' => array( 'columns' => array( 'id' ), 'unique' => true ),
					'scope_assignment' => array( 'columns' => array( 'scope_type', 'scope_id' ), 'unique' => true ),
					'template_id' => array( 'columns' => array( 'template_id' ), 'unique' => false ),
					'scope_lookup' => array( 'columns' => array( 'scope_type', 'scope_id', 'active', 'priority' ), 'unique' => false ),
				),
			),
			'credential_series' => array(
				'columns' => array(
					'id' => array( 'type' => $bigu, 'null' => 'NO', 'extra' => 'auto_increment' ),
					'public_id' => array( 'type' => '/^char\(12\)$/', 'null' => 'NO' ),
					'recipient_id' => array( 'type' => $bigu, 'null' => 'NO' ),
					'program_id' => array( 'type' => $bigu, 'null' => 'YES' ),
					'batch_id' => array( 'type' => $bigu, 'null' => 'YES' ),
					'issuer_id' => array( 'type' => $bigu, 'null' => 'YES' ),
					'current_version_id' => array( 'type' => $bigu, 'null' => 'YES' ),
					'administrative_state' => array( 'type' => '/^varchar\(20\)$/', 'null' => 'NO', 'default' => 'active' ),
					'compliance_state' => array( 'type' => '/^varchar\(20\)$/', 'null' => 'NO', 'default' => 'not_applicable' ),
					'visibility' => array( 'type' => '/^varchar\(32\)$/', 'null' => 'NO', 'default' => 'verification_summary' ),
					'created_at' => array( 'type' => $dt, 'null' => 'NO' ),
					'updated_at' => array( 'type' => $dt, 'null' => 'NO' ),
					'closed_at' => array( 'type' => $dt, 'null' => 'YES' ),
				),
				'indexes' => array(
					'PRIMARY' => array( 'columns' => array( 'id' ), 'unique' => true ),
					'public_id' => array( 'columns' => array( 'public_id' ), 'unique' => true ),
					'recipient_id' => array( 'columns' => array( 'recipient_id' ), 'unique' => false ),
					'program_id' => array( 'columns' => array( 'program_id' ), 'unique' => false ),
					'batch_id' => array( 'columns' => array( 'batch_id' ), 'unique' => false ),
					'issuer_id' => array( 'columns' => array( 'issuer_id' ), 'unique' => false ),
					'current_version_id' => array( 'columns' => array( 'current_version_id' ), 'unique' => false ),
					'administrative_state' => array( 'columns' => array( 'administrative_state' ), 'unique' => false ),
					'compliance_state' => array( 'columns' => array( 'compliance_state' ), 'unique' => false ),
					'visibility' => array( 'columns' => array( 'visibility' ), 'unique' => false ),
				),
			),
			'credential_versions' => array(
				'columns' => array(
					'id' => array( 'type' => $bigu, 'null' => 'NO', 'extra' => 'auto_increment' ),
					'series_id' => array( 'type' => $bigu, 'null' => 'NO' ),
					'version_number' => array( 'type' => '/^int(?:\(10\))? unsigned$/', 'null' => 'NO' ),
					'previous_version_id' => array( 'type' => $bigu, 'null' => 'YES' ),
					'display_number' => array( 'type' => '/^varchar\(100\)$/', 'null' => 'NO' ),
					'issuance_state' => array( 'type' => '/^varchar\(20\)$/', 'null' => 'NO', 'default' => 'draft' ),
					'legacy_expired' => array( 'type' => '/^tinyint(?:\(1\))?$/', 'null' => 'NO', 'default' => '0' ),
					'valid_from' => array( 'type' => $date, 'null' => 'YES' ),
					'issued_at' => array( 'type' => $dt, 'null' => 'YES' ),
					'expires_at' => array( 'type' => $date, 'null' => 'YES' ),
					'grade' => array( 'type' => '/^varchar\(100\)$/', 'null' => 'YES' ),
					'source_type' => array( 'type' => '/^varchar\(64\)$/', 'null' => 'YES' ),
					'source_ref' => array( 'type' => '/^varchar\(191\)$/', 'null' => 'YES' ),
					'source_idempotency_key' => array( 'type' => '/^char\(64\)$/', 'null' => 'YES' ),
					'snapshot_json' => array( 'type' => $lt, 'null' => 'YES' ),
					'created_at' => array( 'type' => $dt, 'null' => 'NO' ),
					'updated_at' => array( 'type' => $dt, 'null' => 'NO' ),
				),
				'indexes' => array(
					'PRIMARY' => array( 'columns' => array( 'id' ), 'unique' => true ),
					'series_version' => array( 'columns' => array( 'series_id', 'version_number' ), 'unique' => true ),
					'previous_version_id' => array( 'columns' => array( 'previous_version_id' ), 'unique' => false ),
					'display_number' => array( 'columns' => array( 'display_number' ), 'unique' => false ),
					'source_idempotency' => array( 'columns' => array( 'source_type', 'source_idempotency_key' ), 'unique' => true ),
					'issuance_state' => array( 'columns' => array( 'issuance_state' ), 'unique' => false ),
					'legacy_expired' => array( 'columns' => array( 'legacy_expired' ), 'unique' => false ),
					'expires_at' => array( 'columns' => array( 'expires_at' ), 'unique' => false ),
					'issued_at' => array( 'columns' => array( 'issued_at' ), 'unique' => false ),
				),
			),
			'credential_numbers' => array(
				'columns' => array(
					'id' => array( 'type' => $bigu, 'null' => 'NO', 'extra' => 'auto_increment' ),
					'display_number' => array( 'type' => '/^varchar\(100\)$/', 'null' => 'NO' ),
					'series_id' => array( 'type' => $bigu, 'null' => 'NO' ),
					'first_version_id' => array( 'type' => $bigu, 'null' => 'YES' ),
					'created_at' => array( 'type' => $dt, 'null' => 'NO' ),
					'retired_at' => array( 'type' => $dt, 'null' => 'YES' ),
				),
				'indexes' => array(
					'PRIMARY' => array( 'columns' => array( 'id' ), 'unique' => true ),
					'display_number' => array( 'columns' => array( 'display_number' ), 'unique' => true ),
					'series_id' => array( 'columns' => array( 'series_id' ), 'unique' => false ),
					'first_version_id' => array( 'columns' => array( 'first_version_id' ), 'unique' => false ),
					'retired_at' => array( 'columns' => array( 'retired_at' ), 'unique' => false ),
				),
			),
			'credential_events' => array(
				'columns' => array(
					'id' => array( 'type' => $bigu, 'null' => 'NO', 'extra' => 'auto_increment' ),
					'series_id' => array( 'type' => $bigu, 'null' => 'NO' ),
					'version_id' => array( 'type' => $bigu, 'null' => 'YES' ),
					'event_type' => array( 'type' => '/^varchar\(64\)$/', 'null' => 'NO' ),
					'actor_user_id' => array( 'type' => $bigu, 'null' => 'YES' ),
					'old_state_json' => array( 'type' => $lt, 'null' => 'YES' ),
					'new_state_json' => array( 'type' => $lt, 'null' => 'YES' ),
					'reason_code' => array( 'type' => '/^varchar\(64\)$/', 'null' => 'YES' ),
					'public_reason' => array( 'type' => $lt, 'null' => 'YES' ),
					'internal_note' => array( 'type' => $lt, 'null' => 'YES' ),
					'meta_json' => array( 'type' => $lt, 'null' => 'YES' ),
					'created_at' => array( 'type' => $dt, 'null' => 'NO' ),
				),
				'indexes' => array(
					'PRIMARY' => array( 'columns' => array( 'id' ), 'unique' => true ),
					'series_created' => array( 'columns' => array( 'series_id', 'created_at' ), 'unique' => false ),
					'version_id' => array( 'columns' => array( 'version_id' ), 'unique' => false ),
					'event_type' => array( 'columns' => array( 'event_type' ), 'unique' => false ),
					'actor_user_id' => array( 'columns' => array( 'actor_user_id' ), 'unique' => false ),
				),
			),
			'jobs' => array(
				'columns' => array(
					'id' => array( 'type' => $bigu, 'null' => 'NO', 'extra' => 'auto_increment' ),
					'job_key' => array( 'type' => '/^char\(32\)$/', 'null' => 'NO' ),
					'job_type' => array( 'type' => '/^varchar\(64\)$/', 'null' => 'NO' ),
					'status' => array( 'type' => '/^varchar\(32\)$/', 'null' => 'NO', 'default' => 'queued' ),
					'owner_user_id' => array( 'type' => $bigu, 'null' => 'YES' ),
					'parent_job_key' => array( 'type' => '/^char\(32\)$/', 'null' => 'YES' ),
					'phase' => array( 'type' => '/^varchar\(64\)$/', 'null' => 'YES' ),
					'cursor' => array( 'type' => $bigu, 'null' => 'NO', 'default' => '0' ),
					'total' => array( 'type' => $bigu, 'null' => 'YES' ),
					'selection_max_id' => array( 'type' => $bigu, 'null' => 'YES' ),
					'payload_json' => array( 'type' => $lt, 'null' => 'YES' ),
					'result_json' => array( 'type' => $lt, 'null' => 'YES' ),
					'error_json' => array( 'type' => $lt, 'null' => 'YES' ),
					'attempts' => array( 'type' => '/^int(?:\(10\))? unsigned$/', 'null' => 'NO', 'default' => '0' ),
					'recovery_count' => array( 'type' => '/^int(?:\(10\))? unsigned$/', 'null' => 'NO', 'default' => '0' ),
					'available_at' => array( 'type' => $dt, 'null' => 'YES' ),
					'locked_by' => array( 'type' => '/^char\(32\)$/', 'null' => 'YES' ),
					'locked_until' => array( 'type' => $dt, 'null' => 'YES' ),
					'created_at' => array( 'type' => $dt, 'null' => 'NO' ),
					'updated_at' => array( 'type' => $dt, 'null' => 'NO' ),
					'finished_at' => array( 'type' => $dt, 'null' => 'YES' ),
				),
				'indexes' => array(
					'PRIMARY' => array( 'columns' => array( 'id' ), 'unique' => true ),
					'job_key' => array( 'columns' => array( 'job_key' ), 'unique' => true ),
					'status_available' => array( 'columns' => array( 'status', 'available_at' ), 'unique' => false ),
					'owner_status' => array( 'columns' => array( 'owner_user_id', 'status' ), 'unique' => false ),
					'type_status' => array( 'columns' => array( 'job_type', 'status' ), 'unique' => false ),
					'parent_job_key' => array( 'columns' => array( 'parent_job_key' ), 'unique' => false ),
					'locked_until' => array( 'columns' => array( 'locked_until' ), 'unique' => false ),
				),
			),

			'import_rows' => array(
				'columns' => array(
					'id' => array( 'type' => $bigu, 'null' => 'NO', 'extra' => 'auto_increment' ),
					'job_key' => array( 'type' => '/^char\(32\)$/', 'null' => 'NO' ),
					'row_number' => array( 'type' => $bigu, 'null' => 'NO' ),
					'display_number' => array( 'type' => '/^varchar\(100\)$/', 'null' => 'NO' ),
					'plan_hash' => array( 'type' => '/^char\(64\)$/', 'null' => 'NO' ),
					'plan_json' => array( 'type' => $lt, 'null' => 'NO' ),
					'existing_series_id' => array( 'type' => $bigu, 'null' => 'YES' ),
					'outcome' => array( 'type' => '/^varchar\(20\)$/', 'null' => 'NO', 'default' => 'ready' ),
					'commit_outcome' => array( 'type' => '/^varchar\(20\)$/', 'null' => 'YES' ),
					'target_series_id' => array( 'type' => $bigu, 'null' => 'YES' ),
					'target_version_id' => array( 'type' => $bigu, 'null' => 'YES' ),
					'committed_at' => array( 'type' => $dt, 'null' => 'YES' ),
					'created_at' => array( 'type' => $dt, 'null' => 'NO' ),
				),
				'indexes' => array(
					'PRIMARY' => array( 'columns' => array( 'id' ), 'unique' => true ),
					'job_row' => array( 'columns' => array( 'job_key', 'row_number' ), 'unique' => true ),
					'job_display' => array( 'columns' => array( 'job_key', 'display_number' ), 'unique' => true ),
					'job_outcome' => array( 'columns' => array( 'job_key', 'outcome' ), 'unique' => false ),
					'job_commit' => array( 'columns' => array( 'job_key', 'commit_outcome' ), 'unique' => false ),
					'existing_series_id' => array( 'columns' => array( 'existing_series_id' ), 'unique' => false ),
				),
			),

			'restore_map' => array(
				'columns' => array(
					'id' => array( 'type' => $bigu, 'null' => 'NO', 'extra' => 'auto_increment' ),
					'owner_token' => array( 'type' => '/^char\(32\)$/', 'null' => 'NO' ),
					'entity' => array( 'type' => '/^varchar\(32\)$/', 'null' => 'NO' ),
					'source_id' => array( 'type' => $bigu, 'null' => 'NO' ),
					'source_hash' => array( 'type' => '/^char\(64\)$/', 'null' => 'NO' ),
					'target_id' => array( 'type' => $bigu, 'null' => 'YES' ),
					'action' => array( 'type' => '/^varchar\(20\)$/', 'null' => 'NO' ),
					'meta_json' => array( 'type' => $lt, 'null' => 'YES' ),
					'created_at' => array( 'type' => $dt, 'null' => 'NO' ),
				),
				'indexes' => array(
					'PRIMARY' => array( 'columns' => array( 'id' ), 'unique' => true ),
					'owner_entity_source' => array( 'columns' => array( 'owner_token', 'entity', 'source_id' ), 'unique' => true ),
					'owner_entity_action' => array( 'columns' => array( 'owner_token', 'entity', 'action' ), 'unique' => false ),
					'owner_target' => array( 'columns' => array( 'owner_token', 'entity', 'target_id' ), 'unique' => false ),
				),
			),


		);
	}

	/**
	 * Performs an immediate structural/engine verification instead of trusting
	 * the cached health marker. Cutover and other one-way authority changes use
	 * this method so a stale option can never stand in for current database truth.
	 *
	 * @param bool $refresh_marker Whether to refresh the successful health marker.
	 * @return true|\WP_Error
	 */
	public static function verify_health_now( $refresh_marker = true ) {
		$health = self::schema_health();
		if ( is_wp_error( $health ) ) {
			return $health;
		}
		if ( $refresh_marker && ! self::save_health_marker() ) {
			return new \WP_Error( 'certloom_domain_schema_health_marker_failed', __( 'Certloom verified its new domain tables but could not save the health marker.', 'certloom' ) );
		}
		return true;
	}

	/** @return bool */
	public static function health_marker_is_fresh() {
		$marker = get_option( self::HEALTH_OPTION, array() );
		if ( ! is_array( $marker ) || self::SCHEMA_VERSION !== (string) ( $marker['version'] ?? '' ) ) {
			return false;
		}
		$checked_at = absint( $marker['checked_at'] ?? 0 );
		return $checked_at > 0 && ( time() - $checked_at ) < self::HEALTH_TTL;
	}

	/** @return bool */
	private static function save_health_marker() {
		$value = array( 'version' => self::SCHEMA_VERSION, 'checked_at' => time() );
		$saved = update_option( self::HEALTH_OPTION, $value, false );
		return $saved || $value === get_option( self::HEALTH_OPTION, array() );
	}

	/** @return string|\WP_Error */
	private static function acquire_lock() {
		global $wpdb;
		$token = wp_generate_password( 32, false, false );
		$now   = time();
		$value = $token . '|' . $now;
		if ( add_option( self::LOCK_OPTION, $value, '', false ) ) {
			return $token;
		}

		$stored = (string) get_option( self::LOCK_OPTION, '' );
		$parts = explode( '|', $stored, 2 );
		$locked_at = isset( $parts[1] ) ? absint( $parts[1] ) : 0;
		if ( $locked_at && ( $now - $locked_at ) < self::LOCK_TTL ) {
			return new \WP_Error( 'certloom_domain_schema_locked', __( 'Certloom domain maintenance is already running. Try again shortly.', 'certloom' ) );
		}

		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s',
				$wpdb->options,
				$value,
				self::LOCK_OPTION,
				$stored
			)
		);
		wp_cache_delete( self::LOCK_OPTION, 'options' );
		return 1 === (int) $updated ? $token : new \WP_Error( 'certloom_domain_schema_locked', __( 'Certloom could not obtain its domain-maintenance lock.', 'certloom' ) );
	}

	/** @return true|\WP_Error */
	private static function release_lock( $token ) {
		global $wpdb;
		if ( ! is_string( $token ) || '' === $token ) {
			return new \WP_Error( 'certloom_domain_schema_lock_token_invalid', __( 'Certloom could not verify its schema-maintenance lock owner during cleanup.', 'certloom' ) );
		}
		$stored = (string) get_option( self::LOCK_OPTION, '' );
		if ( '' === $stored ) {
			return true;
		}
		if ( 0 !== strpos( $stored, $token . '|' ) ) {
			return new \WP_Error( 'certloom_domain_schema_lock_owner_changed', __( 'Certloom refused to release a schema-maintenance lock that is no longer owned by this worker.', 'certloom' ) );
		}
		$wpdb->last_error = '';
		$deleted = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE option_name = %s AND option_value = %s',
				$wpdb->options,
				self::LOCK_OPTION,
				$stored
			)
		);
		wp_cache_delete( self::LOCK_OPTION, 'options' );
		return ( 1 === (int) $deleted || '' === (string) get_option( self::LOCK_OPTION, '' ) ) && '' === (string) $wpdb->last_error
			? true
			: new \WP_Error( 'certloom_domain_schema_lock_release_failed', __( 'Certloom completed schema maintenance but could not release its schema lock safely. Retry after the lease expires or is repaired.', 'certloom' ) );
	}
}
