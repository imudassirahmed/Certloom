<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only, privacy-conscious Certloom runtime diagnostics.
 *
 * This report deliberately excludes site URLs, filesystem paths, database
 * names, user data, certificate values and installed-plugin inventories. It
 * performs read-only probes and never provisions the gated next domain.
 */
class Ecapla_CertVerify_System_Status {

	/** @return array<int,array<string,mixed>> */
	public static function get_checks() {
		global $wpdb;

		$checks = array();
		$checks[] = self::check( 'certloom_version', __( 'Certloom version', 'certloom' ), 'good', ECAPLA_CERTVERIFY_VERSION );
		$checks[] = self::check( 'wordpress_version', __( 'WordPress version', 'certloom' ), 'good', self::wordpress_version() );
		$checks[] = self::check( 'php_version', __( 'PHP version', 'certloom' ), version_compare( PHP_VERSION, '7.4', '>=' ) ? 'good' : 'bad', PHP_VERSION );
		$checks[] = self::check( 'database_server', __( 'Database server', 'certloom' ), 'good', self::database_server_version( $wpdb ) );
		$checks[] = self::database_proof_check();
		$checks[] = self::domain_acceptance_proof_check();
		$checks[] = self::legacy_migration_preflight_check();
		$staging_rehearsal = self::staging_migration_rehearsal_check();
		if ( null !== $staging_rehearsal ) { $checks[] = $staging_rehearsal; }
		$staging_restore = self::staging_restore_rehearsal_check();
		if ( null !== $staging_restore ) { $checks[] = $staging_restore; }
		$staging_cutover = self::staging_cutover_rehearsal_check();
		if ( null !== $staging_cutover ) { $checks[] = $staging_cutover; }
		$checks[] = self::legacy_table_check( $wpdb );
		$checks[] = self::authority_check();
		$checks[] = self::check(
			'domain_feature_gates',
			__( 'Next-domain feature gates', 'certloom' ),
			self::all_domain_gates_off() ? 'good' : 'warning',
			self::domain_gate_summary(),
			self::all_domain_gates_off()
				? __( 'Normalized-domain release authority is closed, so ordinary activation does not require those protected tables.', 'certloom' )
				: __( 'One or more development gates are enabled. The next-domain schema becomes a required dependency.', 'certloom' )
		);
		$checks[] = self::maintenance_check();
		$checks[] = self::domain_maintenance_check();
		$checks[] = self::domain_schema_lock_check();
		$checks[] = self::local_qr_check();
		$checks[] = self::local_barcode_check();
		$checks[] = self::verification_analytics_check();
		$checks[] = self::renderer_authority_check();
		$checks[] = self::extension_check( 'mbstring', __( 'PHP mbstring', 'certloom' ), __( 'Required by the current mPDF production-renderer benchmark candidate; not required by the current verifier or QR/barcode presentation.', 'certloom' ) );
		$checks[] = self::extension_check( 'gd', __( 'PHP GD', 'certloom' ), __( 'Required by the current mPDF production-renderer benchmark candidate and expected by future raster output; not required by the current verifier or QR/barcode presentation.', 'certloom' ) );
		$checks[] = self::check(
			'cron',
			__( 'WordPress Cron', 'certloom' ),
			defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'warning' : 'good',
			defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? __( 'Built-in trigger disabled', 'certloom' ) : __( 'Built-in trigger enabled', 'certloom' ),
			defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON
				? __( 'This is fine when a real server cron calls wp-cron.php. Future queued issuance/email jobs must have a reliable trigger.', 'certloom' )
				: __( 'Future local queues can use WordPress Cron unless the site later switches to a real server cron.', 'certloom' )
		);
		$checks[] = self::check(
			'pretty_permalinks',
			__( 'Pretty permalinks', 'certloom' ),
			'' !== (string) get_option( 'permalink_structure', '' ) ? 'good' : 'warning',
			'' !== (string) get_option( 'permalink_structure', '' ) ? __( 'Enabled', 'certloom' ) : __( 'Plain permalinks', 'certloom' ),
			__( 'Exact verification works either way. Phase 2 clean verification/profile routes use readable paths when pretty permalinks are enabled and query-var fallbacks otherwise.', 'certloom' )
		);
		$checks[] = self::check(
			'https',
			__( 'HTTPS request', 'certloom' ),
			function_exists( 'is_ssl' ) && is_ssl() ? 'good' : 'warning',
			function_exists( 'is_ssl' ) && is_ssl() ? __( 'Yes', 'certloom' ) : __( 'No / not detected', 'certloom' ),
			__( 'HTTPS is strongly recommended for public credential verification and future authenticated APIs.', 'certloom' )
		);
		$checks[] = self::check( 'multisite', __( 'WordPress mode', 'certloom' ), 'good', is_multisite() ? __( 'Multisite', 'certloom' ) : __( 'Single site', 'certloom' ) );
		$checks[] = self::check( 'memory_limit', __( 'PHP memory limit', 'certloom' ), self::memory_status(), (string) ini_get( 'memory_limit' ), __( 'Large CSV, backup and future rendering workloads benefit from adequate memory, but Certloom continues to batch heavy operations.', 'certloom' ) );

		return $checks;
	}

	/** Plain-text support report containing only non-sensitive runtime facts. */
	public static function get_text_report() {
		$lines = array( 'Certloom system status' );
		foreach ( self::get_checks() as $check ) {
			$lines[] = sprintf( '[%s] %s: %s%s', strtoupper( (string) $check['status'] ), (string) $check['label'], (string) $check['value'], empty( $check['note'] ) ? '' : ' — ' . (string) $check['note'] );
		}
		return implode( "\n", $lines );
	}

	private static function check( $id, $label, $status, $value, $note = '' ) {
		return array(
			'id'     => sanitize_key( (string) $id ),
			'label'  => (string) $label,
			'status' => in_array( $status, array( 'good', 'warning', 'bad' ), true ) ? $status : 'warning',
			'value'  => (string) $value,
			'note'   => (string) $note,
		);
	}



	private static function local_qr_check() {
		if ( ! class_exists( 'Ecapla_CertVerify_QR' ) ) {
			return self::check( 'local_qr', __( 'Local verification QR', 'certloom' ), 'bad', __( 'Unavailable', 'certloom' ), __( 'The local QR presentation service is not loaded.', 'certloom' ) );
		}
		$health = Ecapla_CertVerify_QR::health();
		$enabled = Ecapla_CertVerify_QR::enabled();
		if ( empty( $health['available'] ) ) {
			return self::check( 'local_qr', __( 'Local verification QR', 'certloom' ), 'bad', __( 'Encoder failed self-check', 'certloom' ), __( 'Certloom cannot safely present QR codes until the bundled local encoder passes both standard and high-error-correction matrix self-checks.', 'certloom' ) );
		}
		$logo_state = isset( $health['logo_state'] ) ? (string) $health['logo_state'] : 'disabled';
		$status = 'unavailable' === $logo_state ? 'warning' : 'good';
		$value = $enabled ? __( 'Enabled', 'certloom' ) : __( 'Available — hidden by setting', 'certloom' );
		$note = sprintf(
			/* translators: 1: encoder ID, 2: encoder version. */
			__( 'Encoder %1$s v%2$s runs locally; no remote QR service is used. Public QR encodes the canonical verification URL plus Certloom\'s privacy-safe QR source attribution marker.', 'certloom' ),
			(string) $health['id'],
			(string) $health['version']
		);
		if ( 'ready' === $logo_state ) {
			$note .= ' ' . __( 'The local Site Icon passed the bounded QR-logo asset check and logo QR uses high error correction.', 'certloom' );
		} elseif ( 'unavailable' === $logo_state ) {
			$note .= ' ' . __( 'QR center-logo mode is enabled, but the Site Icon is missing or not a safe local PNG/JPEG; Certloom will fall back to the plain QR.', 'certloom' );
		}
		return self::check( 'local_qr', __( 'Local verification QR', 'certloom' ), $status, $value, $note );
	}

	private static function local_barcode_check() {
		if ( ! class_exists( 'Ecapla_CertVerify_Barcode' ) ) {
			return self::check( 'local_code128', __( 'Local Code 128 barcode', 'certloom' ), 'bad', __( 'Unavailable', 'certloom' ), __( 'The local Code 128 presentation service is not loaded.', 'certloom' ) );
		}
		$health = Ecapla_CertVerify_Barcode::health();
		if ( empty( $health['available'] ) ) {
			return self::check( 'local_code128', __( 'Local Code 128 barcode', 'certloom' ), 'bad', __( 'Encoder failed self-check', 'certloom' ), __( 'Certloom cannot present Code 128 until the bundled local encoder passes its structural self-check.', 'certloom' ) );
		}
		$value = Ecapla_CertVerify_Barcode::enabled() ? __( 'Enabled', 'certloom' ) : __( 'Available — hidden by setting', 'certloom' );
		$note = sprintf(
			/* translators: 1: encoder ID, 2: encoder version. */
			__( 'Encoder %1$s v%2$s runs locally and represents the existing human certificate number only; it adds no barcode lookup endpoint or alternate credential identity.', 'certloom' ),
			(string) $health['id'],
			(string) $health['version']
		);
		return self::check( 'local_code128', __( 'Local Code 128 barcode', 'certloom' ), 'good', $value, $note );
	}


	private static function verification_analytics_check() {
		if ( ! class_exists( 'Ecapla_CertVerify_Analytics' ) ) {
			return self::check( 'verification_analytics', __( 'Verification analytics', 'certloom' ), 'warning', __( 'Unavailable', 'certloom' ), __( 'Verification remains authoritative, but ANL-01 aggregate reporting is unavailable.', 'certloom' ) );
		}
		$health = Ecapla_CertVerify_Analytics::health();
		if ( empty( $health['ready'] ) ) {
			return self::check( 'verification_analytics', __( 'Verification analytics', 'certloom' ), 'warning', __( 'Storage not ready', 'certloom' ), __( 'Verification continues normally. Visit Certloom administration to retry the privacy-minimized aggregate analytics table setup.', 'certloom' ) );
		}
		if ( empty( $health['retention_scheduled'] ) ) {
			return self::check( 'verification_analytics', __( 'Verification analytics', 'certloom' ), 'warning', __( 'Ready — retention task missing', 'certloom' ), __( 'Aggregate analytics are available, but the 730-day cleanup event is not scheduled. Deactivate/reactivate Certloom or retry setup before relying on the retention ceiling.', 'certloom' ) );
		}
		return self::check( 'verification_analytics', __( 'Verification analytics', 'certloom' ), 'good', __( 'Ready', 'certloom' ), __( 'Daily aggregate counters are stored locally with a 730-day retention ceiling; raw IP addresses and User-Agents are not stored.', 'certloom' ) );
	}

	private static function renderer_authority_check() {
		$class = '\\Certloom\Infrastructure\Rendering\RendererAuthorityGate';
		if ( ! class_exists( $class ) ) {
			return self::check( 'renderer_authority', __( 'Production renderer authority', 'certloom' ), 'bad', __( 'Authority gate unavailable', 'certloom' ), __( 'Certloom cannot unlock renderer-backed product authority without its release-bound authority gate.', 'certloom' ) );
		}
		$status = ( new $class() )->manifest_status();
		if ( ! empty( $status['accepted'] ) ) {
			return self::check( 'renderer_authority', __( 'Production renderer authority', 'certloom' ), 'good', __( 'Accepted for this release', 'certloom' ), __( 'This Certloom release contains a valid release-bound PDF-04 authority manifest. Per-host artifact execution still requires the exact accepted renderer candidates and their runtime dependencies.', 'certloom' ) );
		}
		$reason = sanitize_key( (string) ( $status['reason'] ?? 'unknown' ) );
		if ( 'manifest_missing' === $reason ) {
			return self::check( 'renderer_authority', __( 'Production renderer authority', 'certloom' ), 'warning', __( 'Not accepted in this release', 'certloom' ), __( 'Expected while PDF-04 is open. Template publication and production artifact actions remain fail-closed; no WordPress option can bypass this release-bound gate.', 'certloom' ) );
		}
		return self::check( 'renderer_authority', __( 'Production renderer authority', 'certloom' ), 'bad', __( 'Invalid / mismatched authority', 'certloom' ), __( 'A bundled renderer authority record exists but could not be validated for this Certloom release. Renderer-backed product authority must remain disabled.', 'certloom' ) );
	}

	private static function database_proof_check() {
		if ( ! class_exists( 'Ecapla_CertVerify_Database_Proof' ) ) {
			return self::check( 'database_proof', __( 'Isolated database proof', 'certloom' ), 'warning', __( 'Not available', 'certloom' ), __( 'The administrator-triggered database acceptance probe is unavailable.', 'certloom' ) );
		}
		$result = Ecapla_CertVerify_Database_Proof::get_last_result();
		if ( ! is_array( $result ) ) {
			return self::check( 'database_proof', __( 'Isolated database proof', 'certloom' ), 'warning', __( 'Not run', 'certloom' ), __( 'An administrator can run the isolated proof below. It uses a temporary Certloom scratch table and never reads certificate data.', 'certloom' ) );
		}
		$state = Ecapla_CertVerify_Database_Proof::evidence_state();
		$overall = sanitize_key( (string) ( $result['overall'] ?? 'incomplete' ) );
		$counts  = array( 'pass' => 0, 'warning' => 0, 'fail' => 0 );
		foreach ( (array) ( $result['checks'] ?? array() ) as $proof_check ) {
			$status = is_array( $proof_check ) ? sanitize_key( (string) ( $proof_check['status'] ?? '' ) ) : '';
			if ( isset( $counts[ $status ] ) ) { $counts[ $status ]++; }
		}
		$status = 'passed' === $state ? 'good' : ( 'failed' === $state ? 'bad' : 'warning' );
		$value  = 'passed' === $state ? __( 'Passed', 'certloom' ) : ( 'failed' === $state ? __( 'Failed', 'certloom' ) : ( 'stale' === $state ? __( 'Passed previously — run again', 'certloom' ) : __( 'Incomplete', 'certloom' ) ) );
		$note = 'stale' === $state
			? sprintf( /* translators: %1$d: pass; %2$d: warning; %3$d: fail. */ __( '%1$d passed, %2$d warnings, %3$d failed in the stored proof, but the evidence is stale for this Certloom version or older than seven days. Run it again before relying on it.', 'certloom' ), (int) $counts['pass'], (int) $counts['warning'], (int) $counts['fail'] )
			: sprintf( /* translators: %1$d: pass; %2$d: warning; %3$d: fail. */ __( '%1$d passed, %2$d warnings, %3$d failed. This proof uses only an isolated scratch table; the full future-domain acceptance suite is still required before cutover.', 'certloom' ), (int) $counts['pass'], (int) $counts['warning'], (int) $counts['fail'] );
		if ( 'failed' !== $overall && 'passed' !== $overall && 'stale' !== $state ) { $status = 'warning'; }
		return self::check( 'database_proof', __( 'Isolated database proof', 'certloom' ), $status, $value, $note );
	}

	private static function domain_acceptance_proof_check() {
		$class = '\\Certloom\\Infrastructure\\Diagnostics\\DomainAcceptanceProof';
		if ( ! class_exists( $class ) ) {
			return self::check( 'domain_acceptance_proof', __( 'Gated-domain acceptance proof', 'certloom' ), 'warning', __( 'Not available', 'certloom' ) );
		}
		$result = $class::get_last_result();
		if ( ! is_array( $result ) ) {
			return self::check( 'domain_acceptance_proof', __( 'Gated-domain acceptance proof', 'certloom' ), 'warning', __( 'Not run', 'certloom' ), __( 'After a fresh passing isolated database proof, an administrator can exercise the real additive Credential Series schema/repositories with rollback-only synthetic data.', 'certloom' ) );
		}
		$state = $class::evidence_state();
		$counts = array( 'pass' => 0, 'warning' => 0, 'fail' => 0 );
		foreach ( (array) ( $result['checks'] ?? array() ) as $proof_check ) {
			$status = is_array( $proof_check ) ? sanitize_key( (string) ( $proof_check['status'] ?? '' ) ) : '';
			if ( isset( $counts[ $status ] ) ) { $counts[ $status ]++; }
		}
		$status = 'passed' === $state ? 'good' : ( 'failed' === $state ? 'bad' : 'warning' );
		$value = 'passed' === $state ? __( 'Passed', 'certloom' ) : ( 'failed' === $state ? __( 'Failed', 'certloom' ) : ( 'stale' === $state ? __( 'Passed previously — run again', 'certloom' ) : __( 'Incomplete', 'certloom' ) ) );
		$note = sprintf( /* translators: %1$d: pass; %2$d: warning; %3$d: fail. */ __( '%1$d passed, %2$d warnings, %3$d failed. This proof provisions the additive future-domain tables but rolls back all synthetic credential rows; live legacy migration/restore/cutover remain separate gates.', 'certloom' ), (int) $counts['pass'], (int) $counts['warning'], (int) $counts['fail'] );
		return self::check( 'domain_acceptance_proof', __( 'Gated-domain acceptance proof', 'certloom' ), $status, $value, $note );
	}

	private static function legacy_migration_preflight_check() {
		$class = '\\Certloom\\Infrastructure\\Diagnostics\\LegacyMigrationPreflightProof';
		if ( ! class_exists( $class ) ) {
			return self::check( 'legacy_migration_preflight', __( 'Populated migration preflight', 'certloom' ), 'warning', __( 'Not available', 'certloom' ) );
		}
		$result = $class::get_last_result();
		if ( ! is_array( $result ) ) {
			return self::check( 'legacy_migration_preflight', __( 'Populated migration preflight', 'certloom' ), 'warning', __( 'Not run', 'certloom' ), __( 'After the gated-domain proof passes, Certloom can read-only scan every live 1.5 row through the final migration mapper before any official migration begins.', 'certloom' ) );
		}
		$state = $class::evidence_state();
		$status = 'passed' === $state ? 'good' : ( 'failed' === $state ? 'bad' : 'warning' );
		$value = 'passed' === $state ? __( 'Passed', 'certloom' ) : ( 'failed' === $state ? __( 'Failed', 'certloom' ) : ( 'stale' === $state ? __( 'Stale — run again', 'certloom' ) : __( 'In progress', 'certloom' ) ) );
		$note = sprintf(
			/* translators: 1: mapped rows, 2: source rows, 3: rows requiring review. */
			__( '%1$d of %2$d live rows mapped; %3$d require review. The proof is read-only and official migration/cutover remain separate gates.', 'certloom' ),
			absint( $result['total'] ?? 0 ),
			absint( $result['source_count'] ?? 0 ),
			absint( $result['errors'] ?? 0 )
		);
		return self::check( 'legacy_migration_preflight', __( 'Populated migration preflight', 'certloom' ), $status, $value, $note );
	}


	private static function staging_migration_rehearsal_check() {
		$class = '\\Certloom\\Infrastructure\\Diagnostics\\StagingMigrationRehearsal';
		if ( ! class_exists( $class ) || ! $class::environment_allowed() ) {
			return null;
		}
		if ( ! $class::explicitly_enabled() ) {
			return self::check( 'staging_migration_rehearsal', __( 'Staging migration commit rehearsal', 'certloom' ), 'warning', __( 'Opt-in required', 'certloom' ), __( 'This non-production environment can run the real populated migration rehearsal after the explicit wp-config opt-in is enabled.', 'certloom' ) );
		}
		$result = $class::get_last_result();
		if ( ! is_array( $result ) ) {
			return self::check( 'staging_migration_rehearsal', __( 'Staging migration commit rehearsal', 'certloom' ), 'warning', __( 'Not run', 'certloom' ), __( 'After fresh prerequisite proofs pass, this staging/local-only action can exercise the production migration coordinator without switching credential authority.', 'certloom' ) );
		}
		$state = $class::evidence_state();
		$status = 'passed' === $state ? 'good' : ( 'failed' === $state ? 'bad' : 'warning' );
		$value = 'passed' === $state ? __( 'Passed', 'certloom' ) : ( 'failed' === $state ? __( 'Failed', 'certloom' ) : ( 'stale' === $state ? __( 'Stale — review staging copy', 'certloom' ) : __( 'In progress', 'certloom' ) ) );
		$note = sprintf(
			/* translators: 1: frozen legacy rows, 2: target parity rows, 3: idempotency rows. */
			__( '%1$d frozen legacy rows; %2$d target rows parity-verified; %3$d rows replayed idempotently. Credential authority remains unchanged.', 'certloom' ),
			absint( $result['source_count'] ?? 0 ),
			absint( $result['verified'] ?? 0 ),
			absint( $result['idempotency_checked'] ?? 0 )
		);
		return self::check( 'staging_migration_rehearsal', __( 'Staging migration commit rehearsal', 'certloom' ), $status, $value, $note );
	}


	private static function staging_restore_rehearsal_check() {
		$class = '\\Certloom\\Infrastructure\\Diagnostics\\StagingRestoreRehearsal';
		if ( ! class_exists( $class ) || ! $class::environment_allowed() ) {
			return null;
		}
		if ( ! $class::explicitly_enabled() ) {
			return self::check( 'staging_restore_rehearsal', __( 'Staging backup/restore rehearsal', 'certloom' ), 'warning', __( 'Opt-in required', 'certloom' ), __( 'This non-production environment can exercise populated Replace, Merge and forced rollback only after the explicit wp-config opt-in is enabled.', 'certloom' ) );
		}
		$result = $class::get_last_result();
		if ( ! is_array( $result ) ) {
			return self::check( 'staging_restore_rehearsal', __( 'Staging backup/restore rehearsal', 'certloom' ), 'warning', __( 'Not run', 'certloom' ), __( 'After the populated staging migration rehearsal passes, this staging/local-only action can exercise real domain backup, Replace, Merge and rollback without changing credential authority.', 'certloom' ) );
		}
		$state = $class::evidence_state();
		$status = 'passed' === $state ? 'good' : ( 'failed' === $state ? 'bad' : 'warning' );
		$value = 'passed' === $state ? __( 'Passed', 'certloom' ) : ( 'failed' === $state ? __( 'Failed', 'certloom' ) : ( 'stale' === $state ? __( 'Stale — review staging copy', 'certloom' ) : __( 'In progress', 'certloom' ) ) );
		$note = sprintf(
			/* translators: 1: baseline records, 2: backup records. */
			__( '%1$d baseline future-domain records; %2$d backup records. Replace, identical Merge and forced rollback must all reproduce the same complete graph. Credential authority remains unchanged.', 'certloom' ),
			absint( $result['baseline_records'] ?? 0 ),
			absint( $result['backup_records'] ?? 0 )
		);
		return self::check( 'staging_restore_rehearsal', __( 'Staging backup/restore rehearsal', 'certloom' ), $status, $value, $note );
	}


	private static function staging_cutover_rehearsal_check() {
		$class = '\Certloom\Infrastructure\Diagnostics\StagingCutoverRehearsal';
		if ( ! class_exists( $class ) || ! $class::environment_allowed() ) {
			return null;
		}
		if ( ! $class::explicitly_enabled() ) {
			return self::check( 'staging_cutover_rehearsal', __( 'Staging final cutover rehearsal', 'certloom' ), 'warning', __( 'Opt-in required', 'certloom' ), __( 'This non-production environment can temporarily exercise and roll back the real authority cutover after the explicit wp-config opt-in is enabled.', 'certloom' ) );
		}
		$result = $class::get_last_result();
		if ( ! is_array( $result ) ) {
			return self::check( 'staging_cutover_rehearsal', __( 'Staging final cutover rehearsal', 'certloom' ), 'warning', __( 'Not run', 'certloom' ), __( 'After populated migration and restore rehearsals pass, this staging/local-only action can exercise the production cutover coordinator, public verification gate and rollback-to-legacy baseline.', 'certloom' ) );
		}
		$state = $class::evidence_state();
		$status = 'passed' === $state ? 'good' : ( 'failed' === $state ? 'bad' : 'warning' );
		$value = 'passed' === $state ? __( 'Passed', 'certloom' ) : ( 'failed' === $state ? __( 'Failed', 'certloom' ) : ( 'stale' === $state ? __( 'Stale — review staging copy', 'certloom' ) : __( 'In progress', 'certloom' ) ) );
		$note = sprintf(
			/* translators: 1: frozen legacy rows, 2: future-domain records fingerprinted. */
			__( '%1$d frozen legacy rows; %2$d future-domain records fingerprinted. Cutover receipt, legacy read-only guard, Series verification readiness, rollback to legacy, and graph parity must all pass.', 'certloom' ),
			absint( $result['source_count'] ?? 0 ),
			absint( $result['baseline_records'] ?? 0 )
		);
		return self::check( 'staging_cutover_rehearsal', __( 'Staging final cutover rehearsal', 'certloom' ), $status, $value, $note );
	}

	private static function authority_check() {
		$value = get_option( 'certloom_domain_write_authority', null );
		if ( null === $value || false === $value || '' === $value || 'legacy' === $value ) {
			return self::check( 'authority', __( 'Credential authority', 'certloom' ), 'good', __( 'Proven 1.5 registry (expected)', 'certloom' ), __( 'The live 1.5 registry remains authoritative while the next domain is still gated.', 'certloom' ) );
		}
		if ( 'domain' === $value ) {
			return self::check( 'authority', __( 'Credential authority', 'certloom' ), 'warning', __( 'Next domain', 'certloom' ), __( 'The next domain is marked authoritative. Its committed cutover receipt and runtime gates must also be valid.', 'certloom' ) );
		}
		return self::check( 'authority', __( 'Credential authority', 'certloom' ), 'bad', __( 'Corrupt protected state', 'certloom' ), __( 'Certloom intentionally blocks legacy writes and Series public verification when the authority option is non-empty but invalid. Repair it only from known-good migration/cutover recovery evidence.', 'certloom' ) );
	}

	private static function maintenance_check() {
		$missing = new stdClass();
		$lock    = get_option( Ecapla_CertVerify_Backup::MAINTENANCE_LOCK_OPTION, $missing );
		if ( $missing === $lock || false === $lock || '' === $lock || array() === $lock ) {
			return self::check( 'maintenance', __( 'Certificate writes', 'certloom' ), 'good', __( 'Available', 'certloom' ) );
		}
		if ( ! is_array( $lock ) || empty( $lock['token_hash'] ) || empty( $lock['expires_at'] ) ) {
			return self::check( 'maintenance', __( 'Certificate writes', 'certloom' ), 'bad', __( 'Blocked by corrupt maintenance state', 'certloom' ), __( 'Certloom intentionally fails closed when a non-empty maintenance lock cannot be parsed. A legitimate restore/recovery operation can repair the lock safely.', 'certloom' ) );
		}
		if ( absint( $lock['expires_at'] ) > time() ) {
			return self::check( 'maintenance', __( 'Certificate writes', 'certloom' ), 'warning', __( 'Paused by restore/maintenance', 'certloom' ), __( 'A Certloom restore or maintenance operation currently owns the live write boundary.', 'certloom' ) );
		}
		return self::check( 'maintenance', __( 'Certificate writes', 'certloom' ), 'warning', __( 'Stale maintenance lease detected', 'certloom' ), __( 'This read-only report does not delete stale state. The next legitimate Certloom maintenance/write check will handle owner-safe expiry cleanup.', 'certloom' ) );
	}


	private static function domain_maintenance_check() {
		$stored = (string) get_option( 'certloom_domain_maintenance_lock', '' );
		if ( '' === $stored ) {
			return self::check( 'domain_maintenance', __( 'Next-domain maintenance', 'certloom' ), 'good', _x( 'Inactive', 'maintenance status', 'certloom' ), __( 'No gated credential-domain restore/cutover maintenance lease is present.', 'certloom' ) );
		}
		$parts = explode( '|', $stored, 4 );
		if ( count( $parts ) >= 4 ) {
			$owner = (string) $parts[0];
			$locked_at = absint( $parts[1] );
			$expires_at = absint( $parts[2] );
		} elseif ( 3 === count( $parts ) ) {
			$owner = (string) $parts[0];
			$locked_at = absint( $parts[1] );
			$expires_at = $locked_at ? $locked_at + 2100 : 0;
		} else {
			$owner = '';
			$locked_at = 0;
			$expires_at = 0;
		}
		if ( ! preg_match( '/^[a-f0-9]{32}$/i', $owner ) || ! $locked_at || ! $expires_at || $expires_at < $locked_at ) {
			return self::check( 'domain_maintenance', __( 'Next-domain maintenance', 'certloom' ), 'bad', __( 'Corrupt protected state', 'certloom' ), __( 'A non-empty next-domain maintenance value could not be validated. Future-domain writes/cutover must remain fail-closed until a legitimate maintenance worker repairs it.', 'certloom' ) );
		}
		return $expires_at > time()
			? self::check( 'domain_maintenance', __( 'Next-domain maintenance', 'certloom' ), 'warning', __( 'Active protected lease', 'certloom' ), __( 'A gated credential-domain maintenance operation currently owns its write boundary.', 'certloom' ) )
			: self::check( 'domain_maintenance', __( 'Next-domain maintenance', 'certloom' ), 'warning', __( 'Stale protected lease', 'certloom' ), __( 'The lease has expired. This read-only status screen does not repair or delete it.', 'certloom' ) );
	}

	private static function domain_schema_lock_check() {
		$stored = (string) get_option( 'certloom_domain_schema_lock', '' );
		if ( '' === $stored ) {
			return self::check( 'domain_schema_lock', __( 'Next-domain schema maintenance', 'certloom' ), 'good', _x( 'Inactive', 'maintenance status', 'certloom' ), __( 'No gated credential-domain schema-maintenance lock is present.', 'certloom' ) );
		}
		$parts = explode( '|', $stored, 2 );
		$token = isset( $parts[0] ) ? (string) $parts[0] : '';
		$locked_at = isset( $parts[1] ) ? absint( $parts[1] ) : 0;
		if ( ! preg_match( '/^[a-z0-9]{32}$/i', $token ) || ! $locked_at ) {
			return self::check( 'domain_schema_lock', __( 'Next-domain schema maintenance', 'certloom' ), 'bad', __( 'Corrupt protected state', 'certloom' ), __( 'A non-empty schema-maintenance lock could not be validated. Future-domain schema work must remain fail-closed until repaired by its owner-safe maintenance path.', 'certloom' ) );
		}
		return ( time() - $locked_at ) < ( 5 * MINUTE_IN_SECONDS )
			? self::check( 'domain_schema_lock', __( 'Next-domain schema maintenance', 'certloom' ), 'warning', __( 'Active protected lock', 'certloom' ), __( 'Gated credential-domain schema maintenance is currently marked in progress.', 'certloom' ) )
			: self::check( 'domain_schema_lock', __( 'Next-domain schema maintenance', 'certloom' ), 'warning', __( 'Stale protected lock', 'certloom' ), __( 'The schema-maintenance lock is stale. This read-only status screen does not repair or delete it.', 'certloom' ) );
	}

	private static function extension_check( $extension, $label, $note ) {
		$loaded = extension_loaded( $extension );
		return self::check( 'extension_' . $extension, $label, $loaded ? 'good' : 'warning', $loaded ? __( 'Available', 'certloom' ) : __( 'Not available', 'certloom' ), $note );
	}

	private static function wordpress_version() {
		global $wp_version;
		return isset( $wp_version ) && '' !== (string) $wp_version ? (string) $wp_version : ( function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'version' ) : __( 'Unknown', 'certloom' ) );
	}

	private static function database_server_version( $wpdb ) {
		if ( is_object( $wpdb ) && method_exists( $wpdb, 'db_server_info' ) ) {
			$info = (string) $wpdb->db_server_info();
			if ( '' !== trim( $info ) ) {
				return sanitize_text_field( $info );
			}
		}
		if ( is_object( $wpdb ) && method_exists( $wpdb, 'db_version' ) ) {
			return sanitize_text_field( (string) $wpdb->db_version() );
		}
		return __( 'Unknown', 'certloom' );
	}

	private static function legacy_table_check( $wpdb ) {
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_row' ) ) {
			return self::check( 'legacy_registry', __( 'Live certificate table', 'certloom' ), 'warning', __( 'Could not inspect', 'certloom' ) );
		}
		$table = Ecapla_CertVerify_Database::table_name();
		$wpdb->last_error = '';
		$row = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $table ) ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $row ) ) {
			return self::check( 'legacy_registry', __( 'Live certificate table', 'certloom' ), 'bad', __( 'Missing / unreadable', 'certloom' ), __( 'The proven registry table must be available for current certificate management and verification.', 'certloom' ) );
		}
		$engine = sanitize_text_field( (string) ( $row['Engine'] ?? '' ) );
		return self::check( 'legacy_registry', __( 'Live certificate table', 'certloom' ), 'good', $engine ? sprintf( /* translators: %s: database storage engine. */ __( 'Available (%s)', 'certloom' ), $engine ) : __( 'Available', 'certloom' ) );
	}

	private static function all_domain_gates_off() {
		return ! \Certloom\Infrastructure\Migration\DomainFeatureGate::any_ready();
	}

	private static function domain_gate_summary() {
		$scopes = \Certloom\Infrastructure\Migration\DomainFeatureGate::scopes();
		$enabled = array();
		if ( ! empty( $scopes['write_paths'] ) ) { $enabled[] = 'write'; }
		if ( ! empty( $scopes['admin_preview'] ) ) { $enabled[] = 'admin'; }
		if ( ! empty( $scopes['data_tools'] ) ) { $enabled[] = 'data-tools'; }
		return $enabled ? sprintf( /* translators: %s: comma separated accepted normalized-domain release scopes. */ __( 'Accepted: %s', 'certloom' ), implode( ', ', $enabled ) ) : __( 'Closed (expected)', 'certloom' );
	}

	private static function memory_status() {
		$raw = (string) ini_get( 'memory_limit' );
		if ( '-1' === trim( $raw ) ) {
			return 'good';
		}
		$bytes = function_exists( 'wp_convert_hr_to_bytes' ) ? wp_convert_hr_to_bytes( $raw ) : 0;
		return $bytes > 0 && $bytes < 128 * MB_IN_BYTES ? 'warning' : 'good';
	}
}
