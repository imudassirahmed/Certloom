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

		if ( ( $missing || $normalized !== array_values( (array) $source ) ) && self::can_persist_schema() ) {
			$generation = Ecapla_CertVerify_Backup::reserve_mutation_generation();
			$result = is_wp_error( $generation ) ? false : update_option( self::OPTION_KEY, $normalized );
			if ( is_wp_error( $generation ) || ( ! $result && $normalized !== get_option( self::OPTION_KEY, array() ) ) ) {
				update_option( self::NORMALIZATION_RETRY_OPTION, 1, false );
			} else {
				delete_option( self::NORMALIZATION_RETRY_OPTION );
			}
		}

		self::$fields_cache = $normalized;
		return self::$fields_cache;
	}

	public static function save_fields( $fields ) {
		if ( is_wp_error( Ecapla_CertVerify_Backup::assert_writes_allowed() ) ) {
			return false;
		}
		$fields  = array_values( self::normalize_fields( is_array( $fields ) ? $fields : array() ) );
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
		$success = $result || $fields === $stored;

		self::$fields_cache = $success
			? $fields
			: self::normalize_fields( is_array( $stored ) && ! empty( $stored ) ? $stored : self::get_default_fields() );

		return $success;
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
			$clean[ $key ] = array(
				'key'        => $key,
				'label'      => $label,
				'type'       => $type,
				'retired_at' => isset( $field['retired_at'] ) ? sanitize_text_field( $field['retired_at'] ) : '',
			);
		}

		if ( $clean !== $stored && self::can_persist_schema() ) {
			$generation = Ecapla_CertVerify_Backup::reserve_mutation_generation();
			$result = is_wp_error( $generation ) ? false : update_option( self::RETIRED_OPTION_KEY, $clean, false );
			if ( is_wp_error( $generation ) || ( ! $result && $clean !== get_option( self::RETIRED_OPTION_KEY, array() ) ) ) {
				update_option( self::NORMALIZATION_RETRY_OPTION, 1, false );
			} else {
				delete_option( self::NORMALIZATION_RETRY_OPTION );
			}
		}

		self::$retired_cache = $clean;
		return $clean;
	}

	/**
	 * Stores a normalized retired-field registry.
	 *
	 * @param array $fields Retired field definitions keyed by field key.
	 * @return bool
	 */
	public static function save_retired_fields( $fields ) {
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
			$clean[ $key ] = array(
				'key'        => $key,
				'label'      => isset( $field['label'] ) ? sanitize_text_field( $field['label'] ) : ucwords( str_replace( array( '-', '_' ), ' ', $key ) ),
				'type'       => $type,
				'retired_at' => isset( $field['retired_at'] ) ? sanitize_text_field( $field['retired_at'] ) : current_time( 'mysql' ),
			);
		}
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
		$success = $result || $clean === $stored;

		self::$retired_cache = $success ? $clean : $stored;
		return $success;
	}

	/**
	 * Permanently reserves a removed custom-field key.
	 *
	 * @param array $field Active field definition.
	 * @return bool
	 */
	public static function retire_field( $field ) {
		if ( ! is_array( $field ) || empty( $field['key'] ) || ! empty( $field['core'] ) ) {
			return false;
		}
		$key     = sanitize_key( $field['key'] );
		$retired = self::get_retired_fields();
		$retired[ $key ] = array(
			'key'        => $key,
			'label'      => isset( $field['label'] ) ? sanitize_text_field( $field['label'] ) : $key,
			'type'       => isset( $field['type'] ) ? sanitize_key( $field['type'] ) : 'text',
			'retired_at' => current_time( 'mysql' ),
		);
		return self::save_retired_fields( $retired );
	}

	/**
	 * Removes a definition from the retired registry after restoration or purge.
	 *
	 * @param string $key Field key.
	 * @return bool
	 */
	public static function remove_retired_field( $key ) {
		$key     = sanitize_key( $key );
		$retired = self::get_retired_fields();
		if ( ! isset( $retired[ $key ] ) ) {
			return false;
		}
		unset( $retired[ $key ] );
		return self::save_retired_fields( $retired );
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

	public static function get_visible_fields() {
		return array_values(
			array_filter(
				self::get_fields(),
				function ( $field ) {
					return ! empty( $field['show_on_frontend'] );
				}
			)
		);
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
			self::field( 'cert_number', __( 'Certificate number', 'certloom' ), 'text', 'cert_number', true, true, 0, true ),
			self::field( 'status', __( 'Current status', 'certloom' ), 'status', 'status', true, true, 1, true ),
			self::field( 'holder_name', __( 'Certificate holder', 'certloom' ), 'text', 'holder_name', true, false, 2, false ),
			self::field( 'standard', __( 'Standard', 'certloom' ), 'text', null, false, false, 3, false ),
			self::field( 'scope', __( 'Scope', 'certloom' ), 'textarea', null, false, false, 4, false ),
			self::field( 'issue_date', __( 'Issue date', 'certloom' ), 'date', 'issue_date', true, false, 5, false ),
			self::field( 'expiry_date', __( 'Expiry date', 'certloom' ), 'date', 'expiry_date', true, false, 6, false ),
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

	private static function field( $key, $label, $type, $column, $core, $protected, $order, $show_on_frontend = false ) {
		return array(
			'key'               => $key,
			'label'             => $label,
			'type'              => $type,
			'column'            => $column,
			'core'              => $core,
			'protected'         => $protected,
			'locked_visibility' => false,
			'show_on_frontend'  => (bool) $show_on_frontend,
			'order'             => $order,
		);
	}

	private static function normalize_fields( $fields ) {
		$defaults    = self::get_default_fields();
		$default_map = array();
		foreach ( $defaults as $default ) {
			$default_map[ $default['key'] ] = $default;
		}

		$allowed_types = array( 'text', 'textarea', 'date', 'number' );
		$normalized    = array();
		$seen          = array();

		foreach ( array_values( (array) $fields ) as $index => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$key = isset( $field['key'] ) ? sanitize_key( $field['key'] ) : '';
			if ( '' === $key || isset( $seen[ $key ] ) ) {
				continue;
			}

			if ( isset( $default_map[ $key ] ) ) {
				$clean                     = $default_map[ $key ];
				$label                     = isset( $field['label'] ) && '' !== trim( (string) $field['label'] ) ? sanitize_text_field( $field['label'] ) : $clean['label'];
				$clean['label']            = function_exists( 'mb_substr' ) ? mb_substr( $label, 0, 100 ) : substr( $label, 0, 100 );
				$clean['show_on_frontend'] = ! empty( $field['show_on_frontend'] );
				$clean['order']            = isset( $field['order'] ) ? absint( $field['order'] ) : $index;
			} else {
				$type  = isset( $field['type'] ) ? sanitize_key( $field['type'] ) : 'text';
				$type  = in_array( $type, $allowed_types, true ) ? $type : 'text';
				$label = isset( $field['label'] ) ? sanitize_text_field( $field['label'] ) : '';
				if ( '' === $label ) {
					$label = ucwords( str_replace( array( '-', '_' ), ' ', $key ) );
				}
				$label = function_exists( 'mb_substr' ) ? mb_substr( $label, 0, 100 ) : substr( $label, 0, 100 );
				$clean = array(
					'key'               => $key,
					'label'             => $label,
					'type'              => $type,
					'column'            => null,
					'core'              => false,
					'protected'         => false,
					'locked_visibility' => false,
					'show_on_frontend'  => ! empty( $field['show_on_frontend'] ),
					'order'             => isset( $field['order'] ) ? absint( $field['order'] ) : $index,
				);
			}

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

}
