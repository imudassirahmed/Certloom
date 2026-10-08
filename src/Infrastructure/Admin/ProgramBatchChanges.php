<?php
namespace Certloom\Infrastructure\Admin;

use Certloom\Infrastructure\Database\Transaction;
use Certloom\Infrastructure\Repository\BatchRepository;
use Certloom\Infrastructure\Repository\TemplateRepository;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Bounded, atomic batch changes owned by the Program form's transaction. */
final class ProgramBatchChanges {

	const LIMIT = 25;

	public static function revision( array $entity, $assignment ) {
		return hash( 'sha256', wp_json_encode( array( $entity, $assignment ) ) );
	}

	/** @return array|\WP_Error */
	public static function decode( $json ) {
		if ( ! is_string( $json ) || strlen( $json ) > 131072 ) { return self::invalid(); }
		$items = json_decode( $json, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $items ) || array_values( $items ) !== $items || count( $items ) > self::LIMIT ) { return self::invalid(); }
		$seen = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['id'], $item['patch'] ) || ! is_int( $item['id'] ) || $item['id'] < 0 || ! is_array( $item['patch'] ) ) { return self::invalid(); }
			$key = $item['id'] ? (string) $item['id'] : ( $item['key'] ?? '' );
			if ( ! is_string( $key ) || ! preg_match( '/^[a-zA-Z0-9_-]{1,80}$/D', $key ) || isset( $seen[ $key ] ) ) { return self::invalid(); }
			$seen[ $key ] = true;
			foreach ( $item['patch'] as $name => $value ) {
				if ( ! in_array( $name, array( 'name', 'code', 'start_date', 'end_date', 'active', 'instructor_signer_id', 'batch_template_id', 'batch_field_requirement' ), true ) ) { return self::invalid(); }
				if ( 'batch_field_requirement' === $name ) {
					if ( ! is_array( $value ) || count( $value ) > 50 ) { return self::invalid(); }
					foreach ( $value as $field => $rule ) { if ( ! is_string( $field ) || sanitize_key( $field ) !== $field || ! is_string( $rule ) || ! in_array( $rule, array( '', 'optional', 'required', 'exclude' ), true ) ) { return self::invalid(); } }
				} elseif ( ! is_scalar( $value ) || strlen( (string) $value ) > 1024 ) { return self::invalid(); }
				if ( in_array( $name, array( 'batch_template_id', 'instructor_signer_id' ), true ) && ! preg_match( '/^\d*$/D', (string) $value ) ) { return self::invalid(); }
				if ( 'active' === $name && ! in_array( $value, array( true, false, 0, 1, '0', '1' ), true ) ) { return self::invalid(); }
			}
			if ( $item['id'] && ( ! is_string( $item['revision'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $item['revision'] ) ) ) { return self::invalid(); }
		}
		return $items;
	}

	/** Program is already locked and saved; all changes roll back with that owning command. */
	public function persist( array $program, array $items ) {
		if ( ! Transaction::owns_current() ) { return new \WP_Error( 'certloom_program_transaction_required', __( 'Reopen the Program before saving batch changes.', 'certloom' ) ); }
		if ( ! $items ) { return true; }
		if ( ! current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_BATCHES ) || ! current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_PROGRAMS ) ) { return new \WP_Error( 'certloom_program_batches_forbidden', __( 'You do not have permission to change these batches.', 'certloom' ) ); }
		// Stable lock order avoids opposing bulk edits acquiring batches in different orders.
		usort( $items, static function ( $a, $b ) { return $a['id'] <=> $b['id']; } );
		$repo = new BatchRepository();
		$templates = new TemplateRepository();
		$admin = new DomainStructureAdmin();
		foreach ( $items as $item ) {
			$existing = $item['id'] ? $repo->find( $item['id'], true ) : null;
			if ( is_wp_error( $existing ) ) { return $existing; }
			if ( $item['id'] && ( ! is_array( $existing ) || (int) $existing['program_id'] !== (int) $program['id'] ) ) { return new \WP_Error( 'certloom_batch_program_context_mismatch', __( 'A selected batch no longer belongs to this Program.', 'certloom' ) ); }
			$assignment = $item['id'] ? $templates->get_assignment( 'batch', $item['id'], true ) : null;
			if ( is_wp_error( $assignment ) ) { return $assignment; }
			if ( $existing && ! hash_equals( self::revision( $existing, $assignment ), $item['revision'] ) ) { return new \WP_Error( 'certloom_program_batch_conflict', __( 'A batch changed in another window. Reload the Program and review your changes before saving.', 'certloom' ) ); }
			$result = $admin->save_workspace_batch( $program, $existing, $item['patch'] );
			if ( is_wp_error( $result ) ) { return $result; }
		}
		return true;
	}

	private static function invalid() { return new \WP_Error( 'certloom_program_batch_changes_invalid', __( 'Batch changes could not be read. Reopen the Program and change at most 25 batches at a time.', 'certloom' ) ); }
}
