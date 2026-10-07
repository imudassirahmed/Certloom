<?php
namespace Certloom\Application\Restore;

use Certloom\Application\Backup\WholeStateDeliveryRecord;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Read-only SQL semantics; neither captured jobs nor external-send permission are restored. */
final class WholeStateDeliveryRowValidator {
	const HOLD_CODE = 'certloom_restored_delivery_review';
	public function validate( $entity, array $row ) {
		$record = ( new WholeStateDeliveryRecord() )->from_row( $entity, $row ); if ( is_wp_error( $record ) ) { return $record; }
		foreach ( array( 'series_id', 'version_id' ) as $key ) { if ( ! WholeStateDeliveryRecord::id( $row[$key] ) ) { return $this->invalid(); } }
		foreach ( array( 'delivery_key', 'job_key', 'attempt_token' ) as $key ) { if ( null !== $row[$key] && ( ! is_string( $row[$key] ) || ! preg_match( '/^[a-f0-9]{32}$/D', $row[$key] ) ) ) { return $this->invalid(); } }
		if ( null === $row['delivery_key'] ) { return $this->invalid(); }
		foreach ( array( 'created_at', 'updated_at', 'attempted_at', 'accepted_at', 'failed_at' ) as $key ) { if ( null !== $row[$key] && ! WholeStateOptionRows::date( $row[$key], 'Y-m-d H:i:s' ) ) { return $this->invalid(); } }
		if ( null === $row['created_at'] || null === $row['updated_at'] ) { return $this->invalid(); }
		$states = array( 'preparing', 'queued', 'sending', 'failed', 'uncertain', 'paused', 'email_deliveries' === $entity ? 'sent' : 'succeeded' );
		if ( ! in_array( $row['status'], $states, true ) || ( null !== $row['failure_code'] && ( strlen( $row['failure_code'] ) > 64 || sanitize_key( $row['failure_code'] ) !== $row['failure_code'] ) ) ) { return $this->invalid(); }
		$hashes = 'email_deliveries' === $entity ? array( 'destination_sha256', 'subject_sha256', 'html_sha256', 'text_sha256', 'composition_sha256' ) : array( 'dedupe_key', 'mapping_sha256', 'configuration_sha256', 'recipient_sha256' );
		foreach ( $hashes as $key ) { if ( ! is_string( $row[$key] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $row[$key] ) ) { return $this->invalid(); } }
		if ( 'email_deliveries' === $entity ) {
			if ( ! in_array( $row['delivery_kind'], array( 'issued', 'resend', 'test' ), true ) || ! WholeStateDeliveryRecord::id( $row['template_version'] ) || ! is_string( $row['destination_mask'] ) || strlen( $row['destination_mask'] ) > 320 ) { return $this->invalid(); }
		} elseif ( ! is_string( $row['destination'] ) || '' === $row['destination'] || strlen( $row['destination'] ) > 32 || sanitize_key( $row['destination'] ) !== $row['destination'] || ( null !== $row['external_reference'] && strlen( $row['external_reference'] ) > 191 ) ) { return $this->invalid(); }
		if ( in_array( $row['status'], array( 'sending', 'sent', 'succeeded' ), true ) && ( null === $row['attempt_token'] || null === $row['attempted_at'] ) ) { return $this->invalid(); }
		if ( in_array( $row['status'], array( 'sent', 'succeeded' ), true ) && null === $row['accepted_at'] ) { return $this->invalid(); }
		if ( null !== $row['evidence_json'] ) {
			$value = json_decode( $row['evidence_json'], true, 32 ); $nodes = 0; if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $value ) || ! $this->safe_json( $value, 0, $nodes ) ) { return $this->invalid(); }
		}
		if ( 'paused' === $row['status'] && ( self::HOLD_CODE !== $row['failure_code'] || null !== $row['job_key'] || ! $this->restore_evidence( $value['certloom_restore'] ?? null ) ) ) { return $this->invalid(); }
		return true;
	}
	/** Preserve terminal receipts and attempts. All unfinished executions become explicit review holds. */
	public function project( $entity, array $row, $archive_sha256 ) {
		$valid = $this->validate( $entity, $row ); if ( is_wp_error( $valid ) || ! is_string( $archive_sha256 ) || ! preg_match( '/^[a-f0-9]{64}$/D', $archive_sha256 ) ) { return $this->invalid(); }
		if ( in_array( $row['status'], array( 'sent', 'succeeded', 'uncertain', 'paused' ), true ) ) { return $row; }
		$evidence = null === $row['evidence_json'] ? array() : json_decode( $row['evidence_json'], true, 32 );
		if ( isset( $evidence['certloom_restore'] ) ) { return $this->invalid(); }
		$evidence['certloom_restore'] = array( 'schema_version' => 1, 'archive_sha256' => $archive_sha256, 'original_status' => $row['status'], 'original_job_key' => $row['job_key'], 'original_failure_code' => $row['failure_code'] );
		$json = wp_json_encode( $evidence, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); if ( ! is_string( $json ) || strlen( $json ) > WholeStateDeliveryRecord::MAX_ROW_BYTES ) { return $this->invalid(); }
		$row['status'] = 'sending' === $row['status'] ? 'uncertain' : 'paused'; $row['job_key'] = null; $row['failure_code'] = self::HOLD_CODE; $row['evidence_json'] = $json;
		return is_wp_error( $this->validate( $entity, $row ) ) || is_wp_error( ( new WholeStateDeliveryRecord() )->from_row( $entity, $row ) ) ? $this->invalid() : $row;
	}
	private function restore_evidence( $value ) {
		if ( ! is_array( $value ) || count( $value ) !== 5 || array_diff( array_keys( $value ), array( 'schema_version', 'archive_sha256', 'original_status', 'original_job_key', 'original_failure_code' ) ) || 1 !== ( $value['schema_version'] ?? null ) || ! is_string( $value['archive_sha256'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $value['archive_sha256'] ) || ! in_array( $value['original_status'] ?? null, array( 'preparing', 'queued', 'sending', 'failed' ), true ) ) { return false; }
		if ( null !== $value['original_job_key'] && ( ! is_string( $value['original_job_key'] ) || ! preg_match( '/^[a-f0-9]{32}$/D', $value['original_job_key'] ) ) ) { return false; }
		return null === $value['original_failure_code'] || ( is_string( $value['original_failure_code'] ) && strlen( $value['original_failure_code'] ) <= 64 && sanitize_key( $value['original_failure_code'] ) === $value['original_failure_code'] );
	}
	private function safe_json( $value, $depth, &$nodes ) { if ( ++$nodes > 20000 || $depth > 24 ) { return false; } if ( is_array( $value ) ) { foreach ( $value as $item ) { if ( ! $this->safe_json( $item, $depth + 1, $nodes ) ) { return false; } } return true; } return null === $value || is_string( $value ) || is_bool( $value ) || is_int( $value ) || ( is_float( $value ) && is_finite( $value ) ); }
	private function invalid() { return new \WP_Error( 'certloom_whole_delivery_semantics_invalid', __( 'A recovery delivery contains invalid receipt evidence. No send permission was restored.', 'certloom' ) ); }
}
