<?php
namespace Certloom\Infrastructure\Integration;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Server-owned Certloom Program → Credly badge-template mappings. */
final class CredlyMappingStore {
	const OPTION = 'certloom_credly_mappings_v1';
	const MAX_MAPPINGS = 500;

	/** @return array<string,array<string,mixed>> */
	public function all() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) || 1 !== absint( $stored['schema_version'] ?? 0 ) || ! is_array( $stored['mappings'] ?? null ) ) { return array(); }
		$out = array();
		foreach ( $stored['mappings'] as $row ) {
			if ( ! is_array( $row ) ) { continue; }
			$normalized = $this->normalize( $row, false );
			if ( is_wp_error( $normalized ) ) { continue; }
			$out[ (string) $normalized['program_id'] ] = $normalized;
			if ( count( $out ) >= self::MAX_MAPPINGS ) { break; }
		}
		ksort( $out, SORT_NUMERIC );
		return $out;
	}

	/** @return array<string,mixed>|null */
	public function find( $program_id ) { $all = $this->all(); $key = (string) absint( $program_id ); return isset( $all[ $key ] ) ? $all[ $key ] : null; }

	/** @return true|\WP_Error */
	public function save( array $row ) {
		$row = $this->normalize( $row, true );
		if ( is_wp_error( $row ) ) { return $row; }
		$all = $this->all();
		$key = (string) $row['program_id'];
		if ( ! isset( $all[ $key ] ) && count( $all ) >= self::MAX_MAPPINGS ) { return new \WP_Error( 'certloom_credly_mapping_limit', __( 'Credly mappings reached the Certloom safety limit. Remove an unused mapping before adding another.', 'certloom' ) ); }
		$all[ $key ] = $row;
		ksort( $all, SORT_NUMERIC );
		return $this->persist( $all );
	}

	/** @return true|\WP_Error */
	public function delete( $program_id ) { $all = $this->all(); unset( $all[ (string) absint( $program_id ) ] ); return $this->persist( $all ); }

	/** @return string|\WP_Error */
	public function fingerprint( array $row ) {
		$row = $this->normalize( $row, false );
		if ( is_wp_error( $row ) ) { return $row; }
		$data = array(
			'program_id' => absint( $row['program_id'] ),
			'badge_template_id' => (string) $row['badge_template_id'],
			'suppress_badge_notification_email' => ! empty( $row['suppress_badge_notification_email'] ),
			'active' => ! empty( $row['active'] ),
		);
		return hash( 'sha256', wp_json_encode( $data, JSON_UNESCAPED_SLASHES ) );
	}

	/** @return array<string,mixed>|\WP_Error */
	private function normalize( array $row, $touch ) {
		$program_id = absint( $row['program_id'] ?? 0 );
		$template = trim( (string) ( $row['badge_template_id'] ?? '' ) );
		if ( $program_id < 1 ) { return new \WP_Error( 'certloom_credly_program_invalid', __( 'Choose a valid Certloom Program for the Credly mapping.', 'certloom' ) ); }
		if ( '' === $template || strlen( $template ) > 191 || 1 !== preg_match( '/^[A-Za-z0-9._:-]+$/', $template ) ) { return new \WP_Error( 'certloom_credly_template_invalid', __( 'Enter the Credly badge template ID exactly as shown in your issuer account.', 'certloom' ) ); }
		$out = array(
			'program_id' => $program_id,
			'badge_template_id' => $template,
			'suppress_badge_notification_email' => ! empty( $row['suppress_badge_notification_email'] ),
			'active' => ! array_key_exists( 'active', $row ) || ! empty( $row['active'] ),
			'updated_at' => trim( (string) ( $row['updated_at'] ?? '' ) ),
		);
		if ( $touch || ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $out['updated_at'] ) ) { $out['updated_at'] = current_time( 'mysql', true ); }
		return $out;
	}

	/** @return true|\WP_Error */
	private function persist( array $mappings ) {
		$payload = array( 'schema_version' => 1, 'mappings' => $mappings );
		$saved = update_option( self::OPTION, $payload, false );
		return $saved || $payload === get_option( self::OPTION, null ) ? true : new \WP_Error( 'certloom_credly_mapping_save_failed', __( 'Certloom could not save the Credly mapping.', 'certloom' ) );
	}
}
