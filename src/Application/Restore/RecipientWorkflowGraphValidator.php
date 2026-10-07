<?php
namespace Certloom\Application\Restore;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Tracks only graph identities, never recipient values, during streamed preflight. */
final class RecipientWorkflowGraphValidator {
	private $entities = array();
	private $unique = array();

	public function observe( $entity, array $row ) {
		$identity = array_intersect_key( $row, array_flip( array( 'id', 'recipient_id', 'program_id', 'batch_id', 'series_id', 'public_ref', 'revision' ) ) );
		$this->entities[ $entity ][ (int) $row['id'] ] = $identity;
	}

	public function validate( $entity, array $row ) {
		$recipient = (int) ( $row['recipient_id'] ?? 0 );
		$program = (int) ( $row['program_id'] ?? 0 );
		$batch = (int) ( $row['batch_id'] ?? 0 );
		if ( $recipient && ! isset( $this->entities['recipients'][ $recipient ] ) ) { return $this->invalid(); }
		if ( $program && ! isset( $this->entities['programs'][ $program ] ) ) { return $this->invalid(); }
		if ( $batch && ( ! isset( $this->entities['batches'][ $batch ] ) || (int) $this->entities['batches'][ $batch ]['program_id'] !== $program ) ) { return $this->invalid(); }
		$keys = array();
		switch ( $entity ) {
			case 'program_registrations':
				$keys[] = 'instance:' . $recipient . ':' . $program . ':' . $row['instance_key'];
				break;
			case 'field_submissions':
				if ( 'registration' === $row['scope_kind'] ) {
					$registration = $this->entities['program_registrations'][ (int) $row['scope_id'] ] ?? null;
					if ( ! is_array( $registration ) || (int) $registration['recipient_id'] !== $recipient ) { return $this->invalid(); }
				}
				$keys[] = 'public:' . $row['public_ref'];
				if ( null !== $row['open_slot'] ) { $keys[] = 'open:' . $row['scope_key']; }
				break;
			case 'issuance_requests':
				if ( ! empty( $row['registration_id'] ) ) {
					$registration = $this->entities['program_registrations'][ (int) $row['registration_id'] ] ?? null;
					if ( ! is_array( $registration ) || (int) $registration['recipient_id'] !== $recipient || (int) $registration['program_id'] !== $program || (int) $registration['batch_id'] !== $batch ) { return $this->invalid(); }
				}
				if ( ! empty( $row['version_id'] ) ) {
					$series = $this->entities['credential_series'][ (int) $row['series_id'] ] ?? null;
					$version = $this->entities['credential_versions'][ (int) $row['version_id'] ] ?? null;
					if ( ! is_array( $series ) || ! is_array( $version ) || (int) $version['series_id'] !== (int) $row['series_id'] || (int) $series['recipient_id'] !== $recipient || (int) $series['program_id'] !== $program ) { return $this->invalid(); }
				}
				$keys = array( 'public:' . $row['public_ref'], 'intake:' . $row['intake_key'] );
				break;
			case 'issuance_request_events':
				if ( ! isset( $this->entities['issuance_requests'][ (int) $row['request_id'] ] ) ) { return $this->invalid(); }
				if ( \Certloom\Application\Recipient\RecipientNoticeRecord::TYPE === $row['event_type'] ) {
					$request = $this->entities['issuance_requests'][ (int) $row['request_id'] ]; $meta = json_decode( (string) $row['meta_json'], true );
					if ( is_wp_error( ( new \Certloom\Application\Recipient\RecipientNoticeRecord() )->validate( $meta ) ) || ! isset( $request['public_ref'] ) || ! hash_equals( \Certloom\Application\Recipient\RecipientNoticeRecord::key( $request['public_ref'], $meta['kind'], $meta['slot'] ), $meta['notice_key'] ) || (int) $row['request_revision'] > (int) $request['revision'] ) { return $this->invalid(); }
					if ( 'throttle' === $meta['state'] && ! hash_equals( \Certloom\Application\Recipient\RecipientNoticeRecord::day_key( $request['recipient_id'], substr( $row['created_at'], 0, 10 ) ), $row['event_key'] ) ) { return $this->invalid(); }
				}
				$keys[] = 'public:' . $row['public_ref'];
				if ( null !== $row['event_key'] ) { $keys[] = 'source:' . $row['request_id'] . ':' . $row['event_key']; }
				break;
		}
		foreach ( $keys as $key ) {
			if ( isset( $this->unique[ $entity ][ $key ] ) ) { return $this->invalid(); }
			$this->unique[ $entity ][ $key ] = true;
		}
		return true;
	}

	private function invalid() {
		return new \WP_Error( 'certloom_domain_restore_workflow_relationship_invalid', __( 'The backup has duplicate or inconsistent recipient workflow relationships.', 'certloom' ) );
	}
}
