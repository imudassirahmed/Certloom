<?php
namespace Certloom\Application\Restore;

use Certloom\Domain\Issuance\IssuanceRequestState;
use Certloom\Domain\Recipient\FieldSubmissionScope;
use Certloom\Application\Issuance\IssuanceEvidence;
use Certloom\Application\Issuance\IssuanceIntake;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Validates workflow records before restoring them or accepting private mutations. */
final class RecipientWorkflowRowValidator {
	public function validate( $entity, array $row ) {
		foreach ( $row as $value ) {
			if ( null !== $value && ! is_string( $value ) && ! is_int( $value ) ) { return $this->invalid(); }
		}
		foreach ( array( 'id', 'recipient_id', 'program_id', 'revision', 'evidence_revision', 'request_id', 'request_revision' ) as $column ) {
			if ( array_key_exists( $column, $row ) && ! $this->integer( $row[ $column ], 1 ) ) { return $this->invalid(); }
		}
		foreach ( array( 'batch_id', 'registration_id', 'submitted_by', 'decided_by', 'actor_user_id', 'series_id', 'version_id' ) as $column ) {
			if ( isset( $row[ $column ] ) && ! $this->integer( $row[ $column ], 1 ) ) { return $this->invalid(); }
		}
		foreach ( array( 'public_ref' => 32, 'instance_key' => 64, 'intake_key' => 64, 'payload_sha256' => 64, 'scope_key' => 64, 'policy_sha256' => 64, 'mapping_sha256' => 64, 'review_sha256' => 64, 'event_key' => 64 ) as $column => $length ) {
			if ( array_key_exists( $column, $row ) && null !== $row[ $column ] && 1 !== preg_match( '/^[a-f0-9]{' . $length . '}$/D', (string) $row[ $column ] ) ) { return $this->invalid(); }
		}
		foreach ( array( 'source_type', 'field_key', 'reason_code' ) as $column ) {
			if ( isset( $row[ $column ] ) && ( '' === $row[ $column ] || strlen( $row[ $column ] ) > 64 || sanitize_key( $row[ $column ] ) !== $row[ $column ] ) ) { return $this->invalid(); }
		}
		if ( isset( $row['source_ref'] ) && strlen( $row['source_ref'] ) > 191 ) { return $this->invalid(); }
		foreach ( array( 'fields_json', 'intake_json', 'evidence_json', 'blockers_json', 'meta_json' ) as $column ) {
			if ( ! array_key_exists( $column, $row ) || null === $row[ $column ] ) { continue; }
			if ( strlen( $row[ $column ] ) > 1024 * 1024 ) { return $this->invalid(); }
			$value = json_decode( $row[ $column ], true, 32 );
			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $value ) ) { return $this->invalid(); }
			if ( 'fields_json' === $column ) {
				if ( count( $value ) > 128 ) { return $this->invalid(); }
				foreach ( $value as $key => $field_value ) {
					if ( ! is_string( $key ) || '' === $key || strlen( $key ) > 64 || sanitize_key( $key ) !== $key || ! is_string( $field_value ) || strlen( $field_value ) > 8192 ) { return $this->invalid(); }
				}
			}
		}
		switch ( $entity ) {
			case 'program_registrations':
				if ( ! in_array( $row['status'], array( 'active', 'completed', 'cancelled' ), true ) ) { return $this->invalid(); }
				break;
			case 'field_submissions':
				if ( ! in_array( $row['scope_kind'], array( 'recipient', 'registration' ), true ) || ! $this->integer( $row['scope_id'], 1 ) || ! in_array( $row['status'], array( 'pending', 'approved', 'rejected', 'superseded', 'cancelled' ), true ) || strlen( $row['candidate_value'] ) > 8192 ) { return $this->invalid(); }
				if ( ( 'pending' === $row['status'] ? '1' !== (string) $row['open_slot'] : null !== $row['open_slot'] ) || ( 'recipient' === $row['scope_kind'] && (string) $row['recipient_id'] !== (string) $row['scope_id'] ) ) { return $this->invalid(); }
				if ( ! hash_equals( FieldSubmissionScope::key( $row['recipient_id'], $row['scope_kind'], $row['scope_id'], $row['field_key'] ), $row['scope_key'] ) ) { return $this->invalid(); }
				break;
			case 'issuance_requests':
				if ( ! in_array( $row['status'], IssuanceRequestState::all(), true ) || ! in_array( $row['restore_state'], array( 'local', 'paused', 'reconciled' ), true ) || ! $this->integer( $row['attempt_count'], 0 ) ) { return $this->invalid(); }
				if ( ( null === $row['series_id'] ) !== ( null === $row['version_id'] ) || ( 'issued' === $row['status'] && null === $row['version_id'] ) ) { return $this->invalid(); }
				$intake = new IssuanceIntake();
				if ( ! $intake->is_erased( $row ) ) {
					if ( is_wp_error( $intake->from_row( $row ) ) || is_wp_error( ( new IssuanceEvidence() )->normalize( json_decode( (string) $row['evidence_json'], true ) ) ) ) { return $this->invalid(); }
				}
				if ( null !== $row['blockers_json'] ) {
					$blockers = json_decode( $row['blockers_json'], true );
					if ( count( $blockers ) > 128 || ( $blockers && array_keys( $blockers ) !== range( 0, count( $blockers ) - 1 ) ) ) { return $this->invalid(); }
					foreach ( $blockers as $blocker ) {
						if ( ! is_array( $blocker ) || array_diff( array_keys( $blocker ), array( 'code', 'field_key' ) ) ) { return $this->invalid(); }
						foreach ( $blocker as $value ) { if ( ! is_string( $value ) || '' === $value || strlen( $value ) > 64 || sanitize_key( $value ) !== $value ) { return $this->invalid(); } }
						if ( ! isset( $blocker['code'] ) ) { return $this->invalid(); }
					}
				}
				break;
			case 'issuance_request_events':
				if ( '' === $row['event_type'] || strlen( $row['event_type'] ) > 64 || 1 !== preg_match( '/^[a-z][a-z0-9_.-]*$/D', $row['event_type'] ) ) { return $this->invalid(); }
				if ( \Certloom\Application\Recipient\RecipientNoticeRecord::TYPE === $row['event_type'] ) {
					$meta = json_decode( (string) $row['meta_json'], true );
					if ( is_wp_error( ( new \Certloom\Application\Recipient\RecipientNoticeRecord() )->validate( $meta ) ) || 'notice_' . $meta['state'] !== $row['reason_code'] || null === $row['event_key'] ) { return $this->invalid(); }
					if ( 'throttle' !== $meta['state'] && ! hash_equals( \Certloom\Application\Recipient\RecipientNoticeRecord::event_key( $meta['notice_key'], $meta['state'] ), $row['event_key'] ) ) { return $this->invalid(); }
				}
				if ( 'request.notice_erased' === $row['event_type'] && ( null !== $row['meta_json'] || null !== $row['actor_user_id'] || 'notice_erased' !== $row['reason_code'] ) ) { return $this->invalid(); }
				break;
			default: return $this->invalid();
		}
		return true;
	}

	private function integer( $value, $minimum ) {
		return ( is_int( $value ) || is_string( $value ) ) && 1 === preg_match( '/^(?:0|[1-9][0-9]*)$/D', (string) $value ) && strlen( (string) $value ) <= 19 && (float) $value <= PHP_INT_MAX && (int) $value >= $minimum;
	}

	private function invalid() {
		return new \WP_Error( 'certloom_domain_restore_workflow_invalid', __( 'A backup contains invalid recipient workflow data.', 'certloom' ) );
	}
}
