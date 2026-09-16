<?php
namespace Certloom\Application\Restore;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Conservative configuration policy for Merge restore.
 *
 * Current settings and active fields always win. Backup-only custom-field
 * definitions are retained as retired keys so historical snapshot data can
 * never be accidentally exposed later by reusing a former machine key.
 */
final class DomainRestoreConfigurationMerger {
	/** @return array<string,mixed>|\WP_Error */
	public function merge( array $current, array $backup ) {
		foreach ( array( 'certverify_fields', 'certverify_retired_fields', 'certverify_settings' ) as $key ) {
			if ( ! isset( $current[ $key ] ) || ! is_array( $current[ $key ] ) || ! array_key_exists( 'exists', $current[ $key ] ) || ! array_key_exists( 'value', $current[ $key ] ) ) {
				return new \WP_Error( 'certloom_domain_restore_merge_configuration_invalid', __( 'Current Certloom configuration is incomplete for Merge restore.', 'certloom' ) );
			}
			if ( ! isset( $backup[ $key ] ) || ! is_array( $backup[ $key ] ) || ! array_key_exists( 'exists', $backup[ $key ] ) || ! array_key_exists( 'value', $backup[ $key ] ) ) {
				return new \WP_Error( 'certloom_domain_restore_merge_configuration_invalid', __( 'Backup Certloom configuration is incomplete for Merge restore.', 'certloom' ) );
			}
		}

		$active  = $this->active_field_map( $current['certverify_fields']['value'] ?? array() );
		$retired = $this->retired_field_map( $current['certverify_retired_fields']['value'] ?? array() );
		$known   = array_fill_keys( array_merge( array_keys( $active ), array_keys( $retired ) ), true );

		foreach ( $this->backup_field_definitions( $backup ) as $key => $field ) {
			if ( isset( $known[ $key ] ) || ! empty( $field['core'] ) || ! empty( $field['column'] ) ) {
				continue;
			}
			$retired[ $key ] = array(
				'key'        => $key,
				'label'      => isset( $field['label'] ) ? sanitize_text_field( (string) $field['label'] ) : $key,
				'type'       => $this->field_type( $field['type'] ?? 'text' ),
				'retired_at' => isset( $field['retired_at'] ) && '' !== trim( (string) $field['retired_at'] )
					? sanitize_text_field( (string) $field['retired_at'] )
					: current_time( 'mysql', true ),
			);
			$known[ $key ] = true;
		}

		$merged = $current;
		$merged['certverify_retired_fields'] = array(
			'exists' => true,
			'value'  => $retired,
		);
		return $merged;
	}

	/** @return array<string,array<string,mixed>> */
	private function active_field_map( $value ) {
		$map = array();
		foreach ( (array) $value as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$key = sanitize_key( (string) ( $field['key'] ?? '' ) );
			if ( '' !== $key ) {
				$map[ $key ] = $field;
			}
		}
		return $map;
	}

	/** @return array<string,array<string,mixed>> */
	private function retired_field_map( $value ) {
		$map = array();
		foreach ( (array) $value as $key => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$normalized = sanitize_key( is_string( $key ) ? $key : (string) ( $field['key'] ?? '' ) );
			if ( '' !== $normalized ) {
				$field['key'] = $normalized;
				$map[ $normalized ] = $field;
			}
		}
		return $map;
	}

	/** @return array<string,array<string,mixed>> */
	private function backup_field_definitions( array $backup ) {
		$all = array();
		foreach ( (array) ( $backup['certverify_fields']['value'] ?? array() ) as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$key = sanitize_key( (string) ( $field['key'] ?? '' ) );
			if ( '' !== $key ) {
				$all[ $key ] = $field;
			}
		}
		foreach ( (array) ( $backup['certverify_retired_fields']['value'] ?? array() ) as $key => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$key = sanitize_key( is_string( $key ) ? $key : (string) ( $field['key'] ?? '' ) );
			if ( '' !== $key ) {
				$field['key'] = $key;
				$all[ $key ] = $field;
			}
		}
		return $all;
	}

	private function field_type( $value ) {
		$value = sanitize_key( (string) $value );
		return in_array( $value, array( 'text', 'textarea', 'date', 'number' ), true ) ? $value : 'text';
	}
}
