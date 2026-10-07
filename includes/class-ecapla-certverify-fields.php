<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Configurable certificate field schema.
 */
class Ecapla_CertVerify_Fields {

	const OPTION_KEY                = 'certverify_fields';
	const RETIRED_OPTION_KEY        = 'certverify_retired_fields';
	const NORMALIZATION_RETRY_OPTION = 'certverify_field_schema_repair_needed';
	/** Pure preflight: a recovery catalog must already have valid current semantics. */
	public static function validate_recovery_value( $value, $retired = false ) {
		if ( ! is_array( $value ) || count( $value ) > 5000 || ( ! $retired && array_values( $value ) !== $value ) ) { return new WP_Error( 'certloom_whole_fields_invalid', __( 'Repair the saved field catalog before restoring it.', 'certloom' ) ); }
		try { $normalized = $retired ? self::normalize_retired_fields( $value ) : self::normalize_fields( $value ); } catch ( Throwable $error ) { return new WP_Error( 'certloom_whole_fields_invalid', __( 'The saved field catalog contains unsupported values.', 'certloom' ) ); }
		if ( ! \Certloom\Application\Restore\WholeStateOptionRows::same( $value, $normalized ) ) { return new WP_Error( 'certloom_whole_fields_invalid', __( 'The saved field catalog needs normalization before it can be restored safely.', 'certloom' ) ); }
		foreach ( $value as $field ) { if ( $retired && ! \Certloom\Application\Restore\WholeStateOptionRows::date( $field['retired_at'], 'Y-m-d H:i:s' ) ) { return new WP_Error( 'certloom_whole_fields_invalid', __( 'A retired field has an invalid retirement date.', 'certloom' ) ); } }
		$policies = self::validate_collection_policies( $value ); return is_wp_error( $policies ) ? $policies : $value;
	}

	/**
	 * Runtime caches. The field schema is read many times per request (renderer,
	 * REST response, validator, importer), so it is resolved once.
	 *
	 * @var array|null
	 */
	private static $fields_cache = null;

	/** @var array|null */
	private static $retired_cache = null;

	/** Clears request-local schema caches after a rollback or restore. */
	public static function reset_runtime_cache() {
		self::$fields_cache  = null;
		self::$retired_cache = null;
	}

	/**
	 * Whether this request may persist a normalized schema.
	 *
	 * The schema is always normalized in memory, but writing that result back is
	 * limited to administrative contexts. A public page view or an unauthenticated
	 * REST verification must never trigger an option write, both to avoid
	 * needless writes on cached traffic and to avoid concurrent requests racing
	 * to save the same value.
	 *
	 * @return bool
	 */
	private static function can_persist_schema() {
		if ( class_exists( 'Ecapla_CertVerify_Backup' ) && is_wp_error( Ecapla_CertVerify_Backup::assert_writes_allowed() ) ) {
			return false;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return true;
		}
		return is_admin();
	}

	public static function get_fields() {
		if ( null !== self::$fields_cache ) {
			return self::$fields_cache;
		}

		$stored     = get_option( self::OPTION_KEY );
		$missing    = ! is_array( $stored ) || empty( $stored );
		$source     = $missing ? self::get_default_fields() : $stored;
		$normalized = self::normalize_fields( $source );

		if ( ( $missing || $normalized !== array_values( (array) $source ) ) && ! is_wp_error( self::validate_collection_policies( $normalized ) ) && self::can_persist_schema() ) {
			\Certloom\Infrastructure\Data\LegacyWriteGuard::run( static function () use ( $normalized ) {
				$generation = Ecapla_CertVerify_Backup::reserve_mutation_generation();
				$result = is_wp_error( $generation ) ? false : update_option( self::OPTION_KEY, $normalized );
				if ( is_wp_error( $generation ) || ! \Certloom\Infrastructure\Data\LegacyWriteGuard::verify_option( self::OPTION_KEY, $normalized ) ) {
					update_option( self::NORMALIZATION_RETRY_OPTION, 1, false );
				} else {
					delete_option( self::NORMALIZATION_RETRY_OPTION );
				}
				return true;
			} );
		}

		self::$fields_cache = $normalized;
		return self::$fields_cache;
	}

	public static function save_fields( $fields ) {
		$changed = false;
		$result = \Certloom\Infrastructure\Data\LegacyWriteGuard::run( static function () use ( $fields, &$changed ) {
			if ( is_wp_error( Ecapla_CertVerify_Backup::assert_writes_allowed() ) ) {
				return false;
			}
			$fields  = array_values( self::normalize_fields( is_array( $fields ) ? $fields : array() ) );
			if ( is_wp_error( self::validate_collection_policies( $fields ) ) ) { return false; }
			$name_catalog = array(); foreach ( $fields as $definition ) { $name_catalog[ $definition['key'] ] = $definition; }
			if ( is_wp_error( ( new \Certloom\Application\Recipient\CertificateNamePolicy() )->current( $name_catalog ) ) ) { return false; }
			$before  = get_option( self::OPTION_KEY, array() );
			if ( $fields === $before ) {
				self::$fields_cache = $fields;
				return true;
			}
			$generation = Ecapla_CertVerify_Backup::reserve_mutation_generation();
			if ( is_wp_error( $generation ) ) {
				return false;
			}
			$result  = update_option( self::OPTION_KEY, $fields );
			$stored  = get_option( self::OPTION_KEY, array() );
			$success = \Certloom\Infrastructure\Data\LegacyWriteGuard::verify_option( self::OPTION_KEY, $fields );

			self::$fields_cache = $success
				? $fields
				: self::normalize_fields( is_array( $stored ) && ! empty( $stored ) ? $stored : self::get_default_fields() );
			if ( $success ) {
				$signal = ( new \Certloom\Infrastructure\Recipient\PendingConfigurationQueue() )->changed();
				if ( is_wp_error( $signal ) ) { return $signal; }
				$changed = true;
			}
			return $success;
		} );
		if ( ! is_wp_error( $result ) && $changed ) { do_action( 'certloom_pending_configuration_changed' ); }
		return ! is_wp_error( $result ) && true === $result;
	}

	/**
	 * Returns custom-field definitions that were removed from the active schema.
	 * Retired keys remain reserved so historical JSON values can never be exposed
	 * accidentally by creating another field with the same generated key.
	 *
	 * @return array<string,array>
	 */
	public static function get_retired_fields() {
		if ( null !== self::$retired_cache ) {
			return self::$retired_cache;
		}

		$stored = get_option( self::RETIRED_OPTION_KEY, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$clean = self::normalize_retired_fields( $stored );

		if ( $clean !== $stored && ! is_wp_error( self::validate_collection_policies( $clean ) ) && self::can_persist_schema() ) {
			\Certloom\Infrastructure\Data\LegacyWriteGuard::run( static function () use ( $clean ) {
				$generation = Ecapla_CertVerify_Backup::reserve_mutation_generation();
				$result = is_wp_error( $generation ) ? false : update_option( self::RETIRED_OPTION_KEY, $clean, false );
				if ( is_wp_error( $generation ) || ! \Certloom\Infrastructure\Data\LegacyWriteGuard::verify_option( self::RETIRED_OPTION_KEY, $clean ) ) {
					update_option( self::NORMALIZATION_RETRY_OPTION, 1, false );
				} else {
					delete_option( self::NORMALIZATION_RETRY_OPTION );
				}
				return true;
			} );
		}

		self::$retired_cache = $clean;
		return $clean;
	}

	private static function normalize_retired_fields( array $stored ) {
		$clean  = array();

		foreach ( $stored as $key => $field ) {
			$key = sanitize_key( $key );
			if ( '' === $key || ! is_array( $field ) ) {
				continue;
			}
			$type = isset( $field['type'] ) ? sanitize_key( $field['type'] ) : 'text';
			if ( ! in_array( $type, array( 'text', 'textarea', 'date', 'number' ), true ) ) {
				$type = 'text';
			}
			$label = isset( $field['label'] ) ? sanitize_text_field( $field['label'] ) : $key;
			$sensitivity = isset( $field['sensitivity'] ) ? sanitize_key( (string) $field['sensitivity'] ) : 'personal';
			if ( ! array_key_exists( $sensitivity, self::get_sensitivity_options() ) ) { $sensitivity = 'personal'; }
			$clean[ $key ] = array(
				'key'        => $key,
				'label'      => $label,
				'type'       => $type,
				'sensitivity'=> $sensitivity,
				'retired_at' => isset( $field['retired_at'] ) ? sanitize_text_field( $field['retired_at'] ) : '',
			);
			if ( array_key_exists( \Certloom\Application\Fields\FieldCollectionPolicy::KEY, $field ) ) {
				$clean[ $key ]['public_exposure'] = $field['public_exposure'] ?? 'private';
				$clean[ $key ] = self::with_collection_policy( $clean[ $key ], $field );
			}
		}

		return $clean;
	}

	/** Use a database locking read without falling back to an older option-cache snapshot. */
	public static function use_configuration_snapshot( $fields, $retired ) {
		self::$fields_cache = self::normalize_fields( is_array( $fields ) && $fields ? $fields : self::get_default_fields() );
		self::$retired_cache = self::normalize_retired_fields( is_array( $retired ) ? $retired : array() );
		return self::validate_collection_policies( self::$fields_cache );
	}

	/**
	 * Stores a normalized retired-field registry.
	 *
	 * @param array $fields Retired field definitions keyed by field key.
	 * @return bool
	 */
	public static function save_retired_fields( $fields ) {
		$result = \Certloom\Infrastructure\Data\LegacyWriteGuard::run( static function () use ( $fields ) {
			if ( is_wp_error( Ecapla_CertVerify_Backup::assert_writes_allowed() ) ) {
				return false;
			}
			$clean = array();
			foreach ( (array) $fields as $key => $field ) {
				$key = sanitize_key( $key );
				if ( '' === $key || ! is_array( $field ) ) {
					continue;
				}
				$type = isset( $field['type'] ) ? sanitize_key( $field['type'] ) : 'text';
				if ( ! in_array( $type, array( 'text', 'textarea', 'date', 'number' ), true ) ) {
					$type = 'text';
				}
				$sensitivity = isset( $field['sensitivity'] ) ? sanitize_key( (string) $field['sensitivity'] ) : 'personal';
				if ( ! array_key_exists( $sensitivity, self::get_sensitivity_options() ) ) { $sensitivity = 'personal'; }
				$clean[ $key ] = array(
					'key'        => $key,
					'label'      => isset( $field['label'] ) ? sanitize_text_field( $field['label'] ) : ucwords( str_replace( array( '-', '_' ), ' ', $key ) ),
					'type'       => $type,
					'sensitivity'=> $sensitivity,
					'retired_at' => isset( $field['retired_at'] ) ? sanitize_text_field( $field['retired_at'] ) : current_time( 'mysql' ),
				);
				if ( array_key_exists( \Certloom\Application\Fields\FieldCollectionPolicy::KEY, $field ) ) {
					$clean[ $key ]['public_exposure'] = $field['public_exposure'] ?? 'private';
					$clean[ $key ] = self::with_collection_policy( $clean[ $key ], $field );
				}
			}
			if ( is_wp_error( self::validate_collection_policies( $clean ) ) ) { return false; }
			$before = get_option( self::RETIRED_OPTION_KEY, array() );
			$before = is_array( $before ) ? $before : array();
			if ( $clean === $before ) {
				self::$retired_cache = $clean;
				return true;
			}
			$generation = Ecapla_CertVerify_Backup::reserve_mutation_generation();
			if ( is_wp_error( $generation ) ) {
				return false;
			}
			$result  = update_option( self::RETIRED_OPTION_KEY, $clean, false );
			$stored  = get_option( self::RETIRED_OPTION_KEY, array() );
			$stored  = is_array( $stored ) ? $stored : array();
			$success = \Certloom\Infrastructure\Data\LegacyWriteGuard::verify_option( self::RETIRED_OPTION_KEY, $clean );

			self::$retired_cache = $success ? $clean : $stored;
			return $success;
		} );
		return ! is_wp_error( $result ) && true === $result;
	}

	/**
	 * Permanently reserves a removed custom-field key.
	 *
	 * @param array $field Active field definition.
	 * @return bool
	 */
	public static function retire_field( $field ) {
		$result = \Certloom\Infrastructure\Data\LegacyWriteGuard::run( static function () use ( $field ) {
			if ( ! is_array( $field ) || empty( $field['key'] ) || ! empty( $field['core'] ) ) {
				return false;
			}
			$key     = sanitize_key( $field['key'] );
			$retired = self::get_retired_fields();
			$retired[ $key ] = array(
				'key'        => $key,
				'label'      => isset( $field['label'] ) ? sanitize_text_field( $field['label'] ) : $key,
				'type'       => isset( $field['type'] ) ? sanitize_key( $field['type'] ) : 'text',
				'sensitivity'=> isset( $field['sensitivity'] ) ? sanitize_key( (string) $field['sensitivity'] ) : 'personal',
				'retired_at' => current_time( 'mysql' ),
			);
			if ( array_key_exists( \Certloom\Application\Fields\FieldCollectionPolicy::KEY, $field ) ) {
				$retired[ $key ]['public_exposure'] = $field['public_exposure'] ?? 'private';
				$retired[ $key ] = self::with_collection_policy( $retired[ $key ], $field );
			}
			return self::save_retired_fields( $retired );
		} );
		return ! is_wp_error( $result ) && true === $result;
	}

	/**
	 * Removes a definition from the retired registry after restoration or purge.
	 *
	 * @param string $key Field key.
	 * @return bool
	 */
	public static function remove_retired_field( $key ) {
		$result = \Certloom\Infrastructure\Data\LegacyWriteGuard::run( static function () use ( $key ) {
			$key     = sanitize_key( $key );
			$retired = self::get_retired_fields();
			if ( ! isset( $retired[ $key ] ) ) {
				return false;
			}
			unset( $retired[ $key ] );
			return self::save_retired_fields( $retired );
		} );
		return ! is_wp_error( $result ) && true === $result;
	}

	/**
	 * Returns active and retired keys that automatic key generation must avoid.
	 *
	 * @return array
	 */
	public static function get_reserved_keys() {
		return array_values( array_unique( array_merge( array_keys( self::get_field_map() ), array_keys( self::get_retired_fields() ) ) ) );
	}

	/**
	 * Retries a previously failed automatic schema normalization.
	 *
	 * @return true|WP_Error
	 */
	public static function maybe_repair_schema() {
		if ( class_exists( 'Ecapla_CertVerify_Backup' ) && Ecapla_CertVerify_Backup::is_restore_in_progress() ) {
			return true;
		}
		if ( ! get_option( self::NORMALIZATION_RETRY_OPTION, false ) ) {
			return true;
		}

		self::reset_runtime_cache();
		$fields  = self::normalize_fields( (array) get_option( self::OPTION_KEY, array() ) );
		$retired = self::get_retired_fields();
		if ( ! self::save_fields( $fields ) || ! self::save_retired_fields( $retired ) ) {
			update_option( self::NORMALIZATION_RETRY_OPTION, 1, false );
			return new WP_Error( 'ecapla_certverify_field_schema_repair_failed', __( 'The certificate field schema could not be repaired. Review database write access before editing certificates.', 'certloom' ) );
		}
		delete_option( self::NORMALIZATION_RETRY_OPTION );
		return true;
	}

	/**
	 * Resets only core field labels to translations for the current site locale.
	 * Custom labels and all ordering/visibility choices are preserved.
	 *
	 * @return bool
	 */
	public static function retranslate_core_labels() {
		$defaults = array();
		foreach ( self::get_default_fields() as $default ) {
			$defaults[ $default['key'] ] = $default['label'];
		}
		$fields = self::get_fields();
		foreach ( $fields as &$field ) {
			if ( ! empty( $field['core'] ) && isset( $defaults[ $field['key'] ] ) ) {
				$field['label'] = $defaults[ $field['key'] ];
			}
		}
		unset( $field );
		return self::save_fields( $fields );
	}

	/**
	 * Returns field definitions safe for public verification output.
	 * Locked-private fields are excluded even if a stale/corrupt stored option
	 * attempts to enable them.
	 *
	 * @return array
	 */
	public static function get_visible_fields( $context = 'verification' ) {
		$context = 'profile' === sanitize_key( (string) $context ) ? 'profile' : 'verification';
		return array_values(
			array_filter(
				self::get_fields(),
				function ( $field ) use ( $context ) {
					if ( ! empty( $field['locked_visibility'] ) || 'internal' === ( $field['sensitivity'] ?? '' ) ) {
						return false;
					}
					$exposure = isset( $field['public_exposure'] ) ? sanitize_key( (string) $field['public_exposure'] ) : ( ! empty( $field['show_on_frontend'] ) ? 'both' : 'private' );
					return 'both' === $exposure || $context === $exposure;
				}
			)
		);
	}

	/** @return array<string,string> */
	public static function get_sensitivity_options() {
		return array(
			'public_safe' => __( 'Public-safe', 'certloom' ),
			'personal'    => __( 'Personal', 'certloom' ),
			'sensitive'   => __( 'Sensitive', 'certloom' ),
			'internal'    => __( 'Internal', 'certloom' ),
		);
	}

	/** @return array<string,string> */
	public static function get_public_exposure_options() {
		return array(
			'private'      => __( 'Private', 'certloom' ),
			'verification' => __( 'Verification only', 'certloom' ),
			'profile'      => __( 'Profile only', 'certloom' ),
			'both'         => __( 'Verification + profile', 'certloom' ),
		);
	}



	/** Fields safe to expose through normal schema management and CSV exchange. */
	public static function get_manageable_fields() {
		return array_values( array_filter( self::get_fields(), static function ( $field ) { return empty( $field['system_only'] ); } ) );
	}

	/** Alias documenting that system-only proof material never enters CSV import/export. */
	public static function get_exchange_fields() {
		return self::get_manageable_fields();
	}

	/**
	 * Field definitions eligible for FLD-02 Program/Batch requirements.
	 * Identity/status/date facts that Certloom always owns are intentionally not
	 * configurable as scoped extras; protected recipient profile fields, Grade,
	 * and administrator-created custom fields may be assigned.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_scopable_fields() {
		return array_values( array_filter( self::get_manageable_fields(), static function ( $field ) {
			if ( ! class_exists( '\\Certloom\\Application\\Fields\\ScopedFieldContract' ) ) {
				return false;
			}
			return \Certloom\Application\Fields\ScopedFieldContract::is_scopable_definition( $field );
		} ) );
	}

	public static function get_field( $key ) {
		$map = self::get_field_map();
		return isset( $map[ $key ] ) ? $map[ $key ] : null;
	}

	public static function get_field_map() {
		$map = array();
		foreach ( self::get_fields() as $field ) {
			$map[ $field['key'] ] = $field;
		}
		return $map;
	}

	public static function is_required_core_field( $key ) {
		return in_array( $key, array( 'cert_number', 'status' ), true );
	}

	public static function get_default_fields() {
		return array(
			self::field( 'cert_number', __( 'Certificate number', 'certloom' ), 'text', 'cert_number', true, true, 0, true, false, 'public_safe', 'both' ),
			self::field( 'status', __( 'Current status', 'certloom' ), 'status', 'status', true, true, 1, true, false, 'public_safe', 'both' ),
			self::field( 'holder_name', __( 'Certificate holder', 'certloom' ), 'text', 'holder_name', true, false, 2, false, false, 'personal', 'private' ),
			self::field( 'standard', __( 'Standard', 'certloom' ), 'text', null, false, false, 3, false, false, 'public_safe', 'private' ),
			self::field( 'scope', __( 'Scope', 'certloom' ), 'textarea', null, false, false, 4, false, false, 'sensitive', 'private' ),
			self::field( 'issue_date', __( 'Issue date', 'certloom' ), 'date', 'issue_date', true, false, 5, false, false, 'public_safe', 'private' ),
			self::field( 'expiry_date', __( 'Expiry date', 'certloom' ), 'date', 'expiry_date', true, false, 6, false, false, 'public_safe', 'private' ),
			/*
			 * UX-C01A: profile/exchange fields that the current registry can persist
			 * losslessly inside its existing versioned custom_fields authority. These
			 * keys are protected core fields rather than arbitrary custom fields so
			 * import/export, backup/restore and legacy->domain migration can rely on
			 * stable semantics without a legacy-table schema fork.
			 */
			self::field( 'first_name', __( 'First name', 'certloom' ), 'text', null, true, true, 90, false, false, 'personal', 'private' ),
			self::field( 'middle_name', __( 'Middle name', 'certloom' ), 'text', null, true, true, 91, false, false, 'personal', 'private' ),
			self::field( 'last_name', __( 'Last name', 'certloom' ), 'text', null, true, true, 92, false, false, 'personal', 'private' ),
			self::field( 'email', __( 'Email', 'certloom' ), 'text', null, true, true, 93, false, false, 'personal', 'private' ),
			self::field( 'external_id', __( 'External ID', 'certloom' ), 'text', null, true, true, 94, false, false, 'personal', 'private' ),
			self::field( 'grade', __( 'Grade', 'certloom' ), 'text', null, true, true, 95, false, false, 'personal', 'private' ),
			// Appended at high default orders so existing installations keep their established field order.
			self::field( 'status_reason', __( 'Status reason', 'certloom' ), 'textarea', null, true, true, 100, false, false, 'sensitive', 'private' ),
			self::field( 'internal_notes', __( 'Internal notes', 'certloom' ), 'textarea', null, true, true, 101, false, true, 'internal', 'private' ),
			self::field( '__certloom_profile_visibility', __( 'Public credential visibility', 'certloom' ), 'visibility', null, true, true, 102, false, true, 'internal', 'private' ),
			self::field( '__certloom_verification_code_hash', __( 'Secondary verification proof', 'certloom' ), 'text', null, true, true, 103, false, true, 'internal', 'private', true ),
		);
	}

	/**
	 * Returns statuses shown in normal certificate-editing controls.
	 * Expired is normally date-calculated; explicit historical restoration remains
	 * supported by the lower-level validated legacy-data path.
	 *
	 * @return array
	 */
	public static function get_editable_statuses() {
		$statuses = self::get_statuses();
		unset( $statuses['expired'] );
		return $statuses;
	}

	public static function get_statuses() {
		$statuses = array(
			'valid'     => array(
				'label' => __( 'Valid', 'certloom' ),
				'color' => 'green',
				'desc'  => __( 'The certificate is current and may be accepted as evidence of active certification.', 'certloom' ),
			),
			'suspended' => array(
				'label' => __( 'Suspended', 'certloom' ),
				'color' => 'amber',
				'desc'  => __( 'Certification is temporarily suspended and should not be treated as current evidence.', 'certloom' ),
			),
			'withdrawn' => array(
				'label' => __( 'Withdrawn', 'certloom' ),
				'color' => 'red',
				'desc'  => __( 'The certificate has been withdrawn and is no longer valid.', 'certloom' ),
			),
			'expired'   => array(
				'label' => __( 'Expired', 'certloom' ),
				'color' => 'gray',
				'desc'  => __( 'The certificate has passed its expiry date and does not represent current certification.', 'certloom' ),
			),
		);

		$overrides = class_exists( 'Ecapla_CertVerify_Settings' ) ? Ecapla_CertVerify_Settings::get( 'status_desc', array() ) : array();
		if ( is_array( $overrides ) ) {
			foreach ( $overrides as $key => $desc ) {
				if ( isset( $statuses[ $key ] ) && '' !== trim( (string) $desc ) ) {
					$statuses[ $key ]['desc'] = sanitize_textarea_field( $desc );
				}
			}
		}
		return $statuses;
	}

	private static function field( $key, $label, $type, $column, $core, $protected, $order, $show_on_frontend = false, $locked_visibility = false, $sensitivity = 'personal', $public_exposure = null, $system_only = false ) {
		$exposure = null === $public_exposure ? ( $show_on_frontend ? 'both' : 'private' ) : sanitize_key( (string) $public_exposure );
		if ( $locked_visibility || 'internal' === $sensitivity ) {
			$exposure = 'private';
		}
		return array(
			'key'               => $key,
			'label'             => $label,
			'type'              => $type,
			'column'            => $column,
			'core'              => $core,
			'protected'         => $protected,
			'locked_visibility' => (bool) $locked_visibility,
			'sensitivity'       => sanitize_key( (string) $sensitivity ),
			'public_exposure'   => $exposure,
			'show_on_frontend'  => 'private' !== $exposure,
			'order'             => $order,
			'system_only'       => (bool) $system_only,
		);
	}

	private static function normalize_fields( $fields ) {
		$defaults    = self::get_default_fields();
		$default_map = array();
		foreach ( $defaults as $default ) {
			$default_map[ $default['key'] ] = $default;
		}

		$allowed_types = array( 'text', 'textarea', 'date', 'number' );
		$sensitivity_options = array_keys( self::get_sensitivity_options() );
		$exposure_options    = array_keys( self::get_public_exposure_options() );
		$normalized    = array();
		$seen          = array();

		foreach ( array_values( (array) $fields ) as $index => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$key = isset( $field['key'] ) ? sanitize_key( $field['key'] ) : '';
			if ( '' === $key || isset( $seen[ $key ] ) || ( 0 === strpos( $key, '__certloom_' ) && ! isset( $default_map[ $key ] ) ) ) {
				continue;
			}

			if ( isset( $default_map[ $key ] ) ) {
				$clean                     = $default_map[ $key ];
				$label                     = isset( $field['label'] ) && '' !== trim( (string) $field['label'] ) ? sanitize_text_field( $field['label'] ) : $clean['label'];
				$clean['label']            = function_exists( 'mb_substr' ) ? mb_substr( $label, 0, 100 ) : substr( $label, 0, 100 );
				$sensitivity = isset( $field['sensitivity'] ) ? sanitize_key( (string) $field['sensitivity'] ) : $clean['sensitivity'];
				$clean['sensitivity'] = in_array( $sensitivity, $sensitivity_options, true ) ? $sensitivity : $clean['sensitivity'];
				if ( self::is_required_core_field( $key ) ) { $clean['sensitivity'] = 'public_safe'; }
				$legacy_public = ! empty( $field['show_on_frontend'] );
				$exposure = isset( $field['public_exposure'] ) ? sanitize_key( (string) $field['public_exposure'] ) : ( $legacy_public ? 'both' : 'private' );
				if ( ! in_array( $exposure, $exposure_options, true ) ) { $exposure = $legacy_public ? 'both' : 'private'; }
				if ( ! empty( $clean['locked_visibility'] ) || 'internal' === $clean['sensitivity'] ) { $exposure = 'private'; }
				$clean['public_exposure'] = $exposure;
				$clean['show_on_frontend'] = 'private' !== $exposure;
				$clean['order']            = isset( $field['order'] ) ? absint( $field['order'] ) : $index;
			} else {
				$type  = isset( $field['type'] ) ? sanitize_key( $field['type'] ) : 'text';
				$type  = in_array( $type, $allowed_types, true ) ? $type : 'text';
				$label = isset( $field['label'] ) ? sanitize_text_field( $field['label'] ) : '';
				if ( '' === $label ) {
					$label = ucwords( str_replace( array( '-', '_' ), ' ', $key ) );
				}
				$label = function_exists( 'mb_substr' ) ? mb_substr( $label, 0, 100 ) : substr( $label, 0, 100 );
				$sensitivity = isset( $field['sensitivity'] ) ? sanitize_key( (string) $field['sensitivity'] ) : 'personal';
				$sensitivity = in_array( $sensitivity, $sensitivity_options, true ) ? $sensitivity : 'personal';
				$legacy_public = ! empty( $field['show_on_frontend'] );
				$exposure = isset( $field['public_exposure'] ) ? sanitize_key( (string) $field['public_exposure'] ) : ( $legacy_public ? 'both' : 'private' );
				$exposure = in_array( $exposure, $exposure_options, true ) ? $exposure : ( $legacy_public ? 'both' : 'private' );
				if ( 'internal' === $sensitivity ) { $exposure = 'private'; }
				$clean = array(
					'key'               => $key,
					'label'             => $label,
					'type'              => $type,
					'column'            => null,
					'core'              => false,
					'protected'         => false,
					'locked_visibility' => false,
					'sensitivity'       => $sensitivity,
					'public_exposure'   => $exposure,
					'show_on_frontend'  => 'private' !== $exposure,
					'order'              => isset( $field['order'] ) ? absint( $field['order'] ) : $index,
					'system_only'        => false,
				);
			}

			$clean = self::with_collection_policy( $clean, $field );
			$seen[ $key ] = true;
			$normalized[] = $clean;
		}

		foreach ( $defaults as $default ) {
			if ( ! empty( $default['core'] ) && empty( $seen[ $default['key'] ] ) ) {
				$normalized[] = $default;
			}
		}

		usort(
			$normalized,
			function ( $a, $b ) {
				return (int) $a['order'] <=> (int) $b['order'];
			}
		);

		foreach ( $normalized as $index => &$field ) {
			$field['order'] = $index;
		}
		unset( $field );

		return array_values( $normalized );
	}

	/** Preserve explicit policies; corrupt stored policies remain visible to strict readers. */
	private static function with_collection_policy( array $clean, array $source ) {
		$key = \Certloom\Application\Fields\FieldCollectionPolicy::KEY;
		if ( ! array_key_exists( $key, $source ) ) { return $clean; }
		$policy = ( new \Certloom\Application\Fields\FieldCollectionPolicy() )->normalize( $source[ $key ], $clean );
		$clean[ $key ] = is_wp_error( $policy ) ? $source[ $key ] : $policy;
		return $clean;
	}

	/** @return true|WP_Error */
	public static function validate_collection_policies( array $fields ) {
		$policies = new \Certloom\Application\Fields\FieldCollectionPolicy();
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) || ! array_key_exists( \Certloom\Application\Fields\FieldCollectionPolicy::KEY, $field ) ) { continue; }
			$policy = $policies->for_definition( $field );
			if ( is_wp_error( $policy ) ) { return $policy; }
		}
		return true;
	}

}
