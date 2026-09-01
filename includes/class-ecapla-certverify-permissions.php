<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Role and capability management.
 */
class Ecapla_CertVerify_Permissions {

	const MANAGER_ROLE              = 'certverify_manager';
	const CAPABILITY_SCHEMA_OPTION  = 'certverify_capability_schema_version';
	const CAPABILITY_SCHEMA_VERSION = '1.6.4';
	const MANAGER_PRESERVED_OPTION  = 'certverify_manager_role_preserved';
	const UNINSTALL_CLEANUP_OPTION  = 'certverify_uninstall_role_cleanup_failed';
	const HEALTH_OPTION             = 'certverify_capability_health';
	const HEALTH_TTL                = DAY_IN_SECONDS;

	const CAP_VIEW_CREDENTIALS     = 'certloom_view_credentials';
	const CAP_MANAGE_CREDENTIALS   = 'certloom_manage_credentials';
	const CAP_ISSUE_CREDENTIALS    = 'certloom_issue_credentials';
	const CAP_MANAGE_RECIPIENTS    = 'certloom_manage_recipients';
	const CAP_MANAGE_PROGRAMS      = 'certloom_manage_programs';
	const CAP_MANAGE_ISSUERS       = 'certloom_manage_issuers';
	const CAP_MANAGE_BATCHES       = 'certloom_manage_batches';
	const CAP_MANAGE_SIGNERS       = 'certloom_manage_signers';
	const CAP_MANAGE_TEMPLATES     = 'certloom_manage_templates';
	const CAP_VIEW_ANALYTICS       = 'certloom_view_analytics';
	const CAP_MANAGE_INTEGRATIONS  = 'certloom_manage_integrations';
	const CAP_MANAGE_SETTINGS      = 'certloom_manage_settings';
	const CAP_MANAGE_DATA_TOOLS    = 'certloom_manage_data_tools';

	/** @return array<int,string> */
	public static function all_capabilities() {
		return array(
			self::CAP_VIEW_CREDENTIALS,
			self::CAP_MANAGE_CREDENTIALS,
			self::CAP_ISSUE_CREDENTIALS,
			self::CAP_MANAGE_RECIPIENTS,
			self::CAP_MANAGE_PROGRAMS,
			self::CAP_MANAGE_ISSUERS,
			self::CAP_MANAGE_BATCHES,
			self::CAP_MANAGE_SIGNERS,
			self::CAP_MANAGE_TEMPLATES,
			self::CAP_VIEW_ANALYTICS,
			self::CAP_MANAGE_INTEGRATIONS,
			self::CAP_MANAGE_SETTINGS,
			self::CAP_MANAGE_DATA_TOOLS,
		);
	}

	/**
	 * Roles that already had the old broad Certloom capability keep equivalent
	 * access when granular capabilities are introduced. Later settings can narrow
	 * those permissions deliberately; upgrades never surprise existing managers.
	 */
	private static function migrate_legacy_role_access() {
		$wp_roles = wp_roles();
		$stored = get_option( $wp_roles->role_key, array() );
		if ( ! is_array( $stored ) ) {
			return false;
		}
		foreach ( $stored as $role_key => $role ) {
			if ( empty( $role['capabilities'][ ECAPLA_CERTVERIFY_CAP ] ) ) {
				continue;
			}
			foreach ( self::all_capabilities() as $capability ) {
				if ( ! self::set_role_capability( $role_key, $capability, true ) ) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * Applies capability setup during activation.
	 *
	 * @return bool
	 */
	public static function activate() {
		return self::maybe_upgrade_capabilities( true );
	}

	/**
	 * Runs bounded role health and versioned capability migrations.
	 *
	 * Administrator access is a mandatory recovery capability and is verified
	 * against the persisted roles option even when the migration marker is current.
	 * Other roles retain administrator-selected access, except a plugin-owned
	 * Certificate Manager role deliberately preserved during uninstall: reinstall
	 * restores that role's minimum safe capabilities before clearing the marker.
	 *
	 * @param bool $activation Whether this is an activation/reinstall pass. Retained for backward compatibility.
	 * @return bool
	 */
	public static function maybe_upgrade_capabilities( $activation = false ) {
		$installed = (string) get_option( self::CAPABILITY_SCHEMA_OPTION, '0' );
		if ( ! $activation
			&& self::CAPABILITY_SCHEMA_VERSION === $installed
			&& ! get_option( self::UNINSTALL_CLEANUP_OPTION, false )
			&& ! get_option( self::MANAGER_PRESERVED_OPTION, false )
			&& self::health_marker_is_fresh()
			&& self::administrator_has_required_capabilities() ) {
			return true;
		}

		if ( get_option( self::UNINSTALL_CLEANUP_OPTION, false ) && ! self::repair_failed_uninstall_cleanup() ) {
			return false;
		}
		if ( ! self::set_role_capability( 'administrator', ECAPLA_CERTVERIFY_CAP, true ) ) {
			return false;
		}
		if ( ! self::migrate_legacy_role_access() ) {
			return false;
		}

		$manager_exists    = self::stored_role_exists( self::MANAGER_ROLE );
		$manager_preserved = (bool) get_option( self::MANAGER_PRESERVED_OPTION, false );
		if ( $manager_exists ) {
			if ( ! self::set_role_capability( self::MANAGER_ROLE, 'read', true )
				|| ! self::set_role_capability( self::MANAGER_ROLE, 'upload_files', false ) ) {
				return false;
			}
			foreach ( self::all_capabilities() as $capability ) {
				if ( ! self::set_role_capability( self::MANAGER_ROLE, $capability, true ) ) {
					return false;
				}
			}
			if ( $manager_preserved ) {
				if ( ! self::set_role_capability( self::MANAGER_ROLE, ECAPLA_CERTVERIFY_CAP, true ) ) {
					return false;
				}
				if ( ! delete_option( self::MANAGER_PRESERVED_OPTION ) && false !== get_option( self::MANAGER_PRESERVED_OPTION, false ) ) {
					return false;
				}
			}
		}

		if ( version_compare( $installed, self::CAPABILITY_SCHEMA_VERSION, '<' ) ) {
			$saved = update_option( self::CAPABILITY_SCHEMA_OPTION, self::CAPABILITY_SCHEMA_VERSION, false );
			if ( ! $saved && self::CAPABILITY_SCHEMA_VERSION !== (string) get_option( self::CAPABILITY_SCHEMA_OPTION, '0' ) ) {
				return false;
			}
		}
		return self::save_health_marker();
	}

	/**
	 * Backward-compatible alias.
	 *
	 * @return bool
	 */
	public static function maybe_repair_roles() {
		return self::maybe_upgrade_capabilities();
	}

	public static function current_user_can_manage() {
		return current_user_can( ECAPLA_CERTVERIFY_CAP );
	}

	public static function current_user_can( $capability ) {
		$capability = sanitize_key( (string) $capability );
		return '' !== $capability && current_user_can( $capability );
	}

	public static function get_all_roles() {
		return wp_roles()->roles;
	}

	public static function role_has_capability( $role_key ) {
		return self::stored_role_has_capability( sanitize_key( $role_key ), ECAPLA_CERTVERIFY_CAP );
	}

	public static function grant_role( $role_key ) {
		delete_option( self::HEALTH_OPTION );
		$role_key = sanitize_key( $role_key );
		if ( ! self::set_role_capability( $role_key, ECAPLA_CERTVERIFY_CAP, true ) ) {
			return false;
		}
		foreach ( self::all_capabilities() as $capability ) {
			if ( ! self::set_role_capability( $role_key, $capability, true ) ) { return false; }
		}
		return true;
	}

	public static function revoke_role( $role_key ) {
		delete_option( self::HEALTH_OPTION );
		$role_key = sanitize_key( $role_key );
		if ( 'administrator' === $role_key ) {
			return false;
		}
		if ( ! self::set_role_capability( $role_key, ECAPLA_CERTVERIFY_CAP, false ) ) {
			return false;
		}
		foreach ( self::all_capabilities() as $capability ) {
			if ( ! self::set_role_capability( $role_key, $capability, false ) ) { return false; }
		}
		return true;
	}

	/**
	 * Creates or repairs the plugin-owned Certificate Manager role.
	 *
	 * @return WP_Role|false
	 */
	public static function create_manager_role() {
		delete_option( self::HEALTH_OPTION );
		if ( ! self::stored_role_exists( self::MANAGER_ROLE ) ) {
			add_role(
				self::MANAGER_ROLE,
				__( 'Certificate Manager', 'certloom' ),
				array(
					'read'                 => true,
					ECAPLA_CERTVERIFY_CAP => true,
				)
			);
			self::reload_roles_from_storage();
		}

		if ( ! self::set_role_capability( self::MANAGER_ROLE, 'read', true )
			|| ! self::set_role_capability( self::MANAGER_ROLE, ECAPLA_CERTVERIFY_CAP, true )
			|| ! self::set_role_capability( self::MANAGER_ROLE, 'upload_files', false ) ) {
			return false;
		}
		foreach ( self::all_capabilities() as $capability ) {
			if ( ! self::set_role_capability( self::MANAGER_ROLE, $capability, true ) ) {
				return false;
			}
		}

		delete_option( self::MANAGER_PRESERVED_OPTION );
		return get_role( self::MANAGER_ROLE );
	}

	/**
	 * Records that the plugin-owned manager role survived uninstall because users
	 * still held it. Reinstall uses this marker to restore only that role.
	 *
	 * @return bool
	 */
	public static function mark_manager_role_preserved() {
		delete_option( self::HEALTH_OPTION );
		$saved = update_option( self::MANAGER_PRESERVED_OPTION, true, false );
		return $saved || true === (bool) get_option( self::MANAGER_PRESERVED_OPTION, false );
	}

	/**
	 * Atomically changes one capability in the persisted WordPress roles option.
	 *
	 * @param string $role_key Role slug.
	 * @param string $capability Capability name.
	 * @param bool   $grant Whether to grant or revoke.
	 * @return bool
	 */
	private static function set_role_capability( $role_key, $capability, $grant ) {
		$role_key   = sanitize_key( $role_key );
		$capability = sanitize_key( $capability );
		$wp_roles   = wp_roles();
		$stored     = get_option( $wp_roles->role_key, array() );

		if ( '' === $role_key || '' === $capability || ! is_array( $stored ) || ! isset( $stored[ $role_key ]['capabilities'] ) || ! is_array( $stored[ $role_key ]['capabilities'] ) ) {
			return false;
		}

		$current = ! empty( $stored[ $role_key ]['capabilities'][ $capability ] );
		if ( (bool) $grant === $current ) {
			self::reload_roles_from_storage();
			return true;
		}

		if ( $grant ) {
			$stored[ $role_key ]['capabilities'][ $capability ] = true;
		} else {
			unset( $stored[ $role_key ]['capabilities'][ $capability ] );
		}

		$saved = update_option( $wp_roles->role_key, $stored );
		$ok    = $saved || (bool) $grant === self::stored_role_has_capability( $role_key, $capability );
		self::reload_roles_from_storage();
		return $ok && (bool) $grant === self::stored_role_has_capability( $role_key, $capability );
	}

	/** Removes capabilities that a prior uninstall could not persist. */
	private static function repair_failed_uninstall_cleanup() {
		$wp_roles = wp_roles();
		$stored   = get_option( $wp_roles->role_key, array() );
		if ( ! is_array( $stored ) ) {
			return false;
		}
		foreach ( $stored as &$role ) {
			if ( isset( $role['capabilities'] ) && is_array( $role['capabilities'] ) ) {
				unset( $role['capabilities'][ ECAPLA_CERTVERIFY_CAP ] );
				foreach ( self::all_capabilities() as $capability ) {
					unset( $role['capabilities'][ $capability ] );
				}
			}
		}
		unset( $role );
		$saved = update_option( $wp_roles->role_key, $stored );
		if ( ! $saved && $stored !== get_option( $wp_roles->role_key, array() ) ) {
			return false;
		}
		if ( ! delete_option( self::UNINSTALL_CLEANUP_OPTION ) && false !== get_option( self::UNINSTALL_CLEANUP_OPTION, false ) ) {
			return false;
		}
		self::reload_roles_from_storage();
		return true;
	}

	private static function stored_role_exists( $role_key ) {
		$wp_roles = wp_roles();
		$stored   = get_option( $wp_roles->role_key, array() );
		return is_array( $stored ) && isset( $stored[ sanitize_key( $role_key ) ] );
	}

	private static function stored_role_has_capability( $role_key, $capability ) {
		$wp_roles = wp_roles();
		$stored   = get_option( $wp_roles->role_key, array() );
		return is_array( $stored )
			&& ! empty( $stored[ sanitize_key( $role_key ) ]['capabilities'][ sanitize_key( $capability ) ] );
	}



	private static function administrator_has_required_capabilities() {
		if ( ! self::stored_role_has_capability( 'administrator', ECAPLA_CERTVERIFY_CAP ) ) {
			return false;
		}
		foreach ( self::all_capabilities() as $capability ) {
			if ( ! self::stored_role_has_capability( 'administrator', $capability ) ) {
				return false;
			}
		}
		return true;
	}

	private static function health_marker_is_fresh() {
		$health = get_option( self::HEALTH_OPTION, array() );
		return is_array( $health )
			&& self::CAPABILITY_SCHEMA_VERSION === (string) ( $health['version'] ?? '' )
			&& isset( $health['checked_at'] )
			&& ( time() - absint( $health['checked_at'] ) ) < self::HEALTH_TTL;
	}

	private static function save_health_marker() {
		$value = array( 'version' => self::CAPABILITY_SCHEMA_VERSION, 'checked_at' => time() );
		$saved = update_option( self::HEALTH_OPTION, $value, false );
		return $saved || $value === get_option( self::HEALTH_OPTION, array() );
	}

	private static function reload_roles_from_storage() {
		$wp_roles = wp_roles();
		if ( method_exists( $wp_roles, 'for_site' ) ) {
			$wp_roles->for_site( get_current_blog_id() );
		} elseif ( method_exists( $wp_roles, 'reinit' ) ) {
			$wp_roles->reinit();
		}
	}
}
