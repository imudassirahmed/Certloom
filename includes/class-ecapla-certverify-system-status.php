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
		$checks[] = self::legacy_table_check( $wpdb );
		$checks[] = self::check(
			'authority',
			__( 'Credential authority', 'certloom' ),
			self::domain_authority_active() ? 'warning' : 'good',
			self::domain_authority_active() ? __( 'Next domain', 'certloom' ) : __( 'Proven 1.5 registry (expected)', 'certloom' ),
			self::domain_authority_active()
				? __( 'The next domain is marked authoritative. Its committed cutover receipt and runtime gates must also be valid.', 'certloom' )
				: __( 'The live 1.5 registry remains authoritative while the next domain is still gated.', 'certloom' )
		);
		$checks[] = self::check(
			'domain_feature_gates',
			__( 'Next-domain feature gates', 'certloom' ),
			self::all_domain_gates_off() ? 'good' : 'warning',
			self::domain_gate_summary(),
			self::all_domain_gates_off()
				? __( 'All future-domain feature gates are off, so ordinary activation does not require those unused tables.', 'certloom' )
				: __( 'One or more development gates are enabled. The next-domain schema becomes a required dependency.', 'certloom' )
		);
		$checks[] = self::maintenance_check();
		$checks[] = self::extension_check( 'mbstring', __( 'PHP mbstring', 'certloom' ), __( 'Required by the planned production Unicode certificate renderer; not required by the current verifier.', 'certloom' ) );
		$checks[] = self::extension_check( 'gd', __( 'PHP GD', 'certloom' ), __( 'Expected by the planned raster/PDF rendering stack; not required by the current verifier.', 'certloom' ) );
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
			__( 'Current exact verification works either way; future clean credential routes are best with pretty permalinks.', 'certloom' )
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

	private static function domain_authority_active() {
		return 'domain' === sanitize_key( (string) get_option( 'certloom_domain_write_authority', 'legacy' ) );
	}

	private static function all_domain_gates_off() {
		return ! ( defined( 'CERTLOOM_DOMAIN_WRITE_PATHS_READY' ) && CERTLOOM_DOMAIN_WRITE_PATHS_READY )
			&& ! ( defined( 'CERTLOOM_DOMAIN_ADMIN_PREVIEW' ) && CERTLOOM_DOMAIN_ADMIN_PREVIEW )
			&& ! ( defined( 'CERTLOOM_DOMAIN_DATA_TOOLS_READY' ) && CERTLOOM_DOMAIN_DATA_TOOLS_READY );
	}

	private static function domain_gate_summary() {
		$enabled = array();
		if ( defined( 'CERTLOOM_DOMAIN_WRITE_PATHS_READY' ) && CERTLOOM_DOMAIN_WRITE_PATHS_READY ) { $enabled[] = 'write'; }
		if ( defined( 'CERTLOOM_DOMAIN_ADMIN_PREVIEW' ) && CERTLOOM_DOMAIN_ADMIN_PREVIEW ) { $enabled[] = 'admin'; }
		if ( defined( 'CERTLOOM_DOMAIN_DATA_TOOLS_READY' ) && CERTLOOM_DOMAIN_DATA_TOOLS_READY ) { $enabled[] = 'data-tools'; }
		return $enabled ? sprintf( /* translators: %s: comma separated internal development gates. */ __( 'Enabled: %s', 'certloom' ), implode( ', ', $enabled ) ) : __( 'All off (expected)', 'certloom' );
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
