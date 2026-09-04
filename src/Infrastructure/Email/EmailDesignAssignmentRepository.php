<?php
namespace Certloom\Infrastructure\Email;

use Certloom\Infrastructure\Repository\BatchRepository;
use Certloom\Infrastructure\Repository\IssuerRepository;
use Certloom\Infrastructure\Repository\ProgramRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Independent Global → Issuer → Program → Batch inheritance for email designs. */
final class EmailDesignAssignmentRepository {
	const OPTION = 'certloom_email_design_assignments_v1';
	const MAX_ASSIGNMENTS = 5000;

	/** @return array<int,array<string,mixed>> */
	public function all() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) { return array(); }
		$out = array();
		foreach ( array_slice( $stored, 0, self::MAX_ASSIGNMENTS ) as $row ) {
			if ( ! is_array( $row ) ) { continue; }
			$normalized = $this->normalize( $row, false );
			if ( ! is_wp_error( $normalized ) ) { $out[] = $normalized; }
		}
		return array_values( $out );
	}

	/** @return true|\WP_Error */
	public function save( $scope_type, $scope_id, $purpose, $design_id ) {
		$row = $this->normalize( array( 'scope_type' => $scope_type, 'scope_id' => $scope_id, 'purpose' => $purpose, 'design_id' => $design_id ), true );
		if ( is_wp_error( $row ) ) { return $row; }
		$rows = $this->all();
		$found = false;
		foreach ( $rows as $index => $existing ) {
			if ( $this->same_key( $existing, $row ) ) { $rows[ $index ] = $row; $found = true; break; }
		}
		if ( ! $found ) {
			if ( count( $rows ) >= self::MAX_ASSIGNMENTS ) { return new \WP_Error( 'certloom_email_assignment_limit', __( 'Certloom has reached the email design rule limit for this site.', 'certloom' ) ); }
			$rows[] = $row;
		}
		return $this->persist( $rows );
	}

	/** @return true|\WP_Error */
	public function remove( $scope_type, $scope_id, $purpose ) {
		$scope_type = sanitize_key( (string) $scope_type );
		if ( ! in_array( $scope_type, array( 'global', 'issuer', 'program', 'batch' ), true ) ) {
			return new \WP_Error( 'certloom_email_assignment_scope_invalid', __( 'Choose Global, Issuer, Program or Batch for email design rules.', 'certloom' ) );
		}
		if ( ! EmailDesignPurposes::is_valid( $purpose ) ) {
			return new \WP_Error( 'certloom_email_assignment_purpose_invalid', __( 'Choose a valid email message purpose before changing the design rule.', 'certloom' ) );
		}
		$scope_id = 'global' === $scope_type ? 0 : absint( $scope_id );
		if ( 'global' !== $scope_type && ! $scope_id ) {
			return new \WP_Error( 'certloom_email_assignment_target_required', __( 'Choose the record whose email design rule should be restored.', 'certloom' ) );
		}
		$purpose = sanitize_key( (string) $purpose );
		$rows = array_values( array_filter( $this->all(), static function( array $row ) use ( $scope_type, $scope_id, $purpose ) {
			return ! ( $scope_type === $row['scope_type'] && $scope_id === absint( $row['scope_id'] ) && $purpose === $row['purpose'] );
		} ) );
		return $this->persist( $rows );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function resolve( $issuer_id, $program_id, $batch_id, $purpose = EmailDesignPurposes::ISSUED ) {
		return $this->resolve_internal( $issuer_id, $program_id, $batch_id, $purpose, null );
	}


	/** @return array<string,mixed>|null|\WP_Error */
	public function get( $scope_type, $scope_id, $purpose ) {
		$scope_type = sanitize_key( (string) $scope_type );
		$scope_id = 'global' === $scope_type ? 0 : absint( $scope_id );
		$purpose = sanitize_key( (string) $purpose );
		if ( ! in_array( $scope_type, array( 'global', 'issuer', 'program', 'batch' ), true ) || ! EmailDesignPurposes::is_valid( $purpose ) ) {
			return new \WP_Error( 'certloom_email_assignment_key_invalid', __( 'The email design rule key is invalid.', 'certloom' ) );
		}
		foreach ( $this->all() as $row ) {
			if ( $scope_type === (string) $row['scope_type'] && $scope_id === absint( $row['scope_id'] ) && $purpose === (string) $row['purpose'] ) { return $row; }
		}
		return null;
	}

	/** Resolve as if one direct rule were absent, for inheritance previews and preflight. @return array<string,mixed>|\WP_Error */
	public function resolve_excluding( $issuer_id, $program_id, $batch_id, $purpose, $scope_type, $scope_id ) {
		$scope_type = sanitize_key( (string) $scope_type );
		$scope_id = 'global' === $scope_type ? 0 : absint( $scope_id );
		return $this->resolve_internal( $issuer_id, $program_id, $batch_id, $purpose, array( $scope_type, $scope_id ) );
	}

	/** @return int */
	public function usage_count( $design_id ) {
		$design_id = sanitize_key( (string) $design_id ); $count = 0;
		foreach ( $this->all() as $row ) { if ( $design_id === (string) $row['design_id'] ) { $count++; } }
		return $count;
	}

	/** @return array<string,mixed>|\WP_Error */
	private function resolve_internal( $issuer_id, $program_id, $batch_id, $purpose, ?array $excluded ) {
		if ( ! EmailDesignPurposes::is_valid( $purpose ) ) { return new \WP_Error( 'certloom_email_assignment_purpose_invalid', __( 'The requested email message purpose is not supported.', 'certloom' ) ); }
		$purpose = sanitize_key( (string) $purpose );
		$by_key = array();
		foreach ( $this->all() as $row ) { $by_key[ $this->key( $row['scope_type'], $row['scope_id'], $row['purpose'] ) ] = $row; }
		$candidates = array();
		if ( absint( $batch_id ) ) { $candidates[] = array( 'batch', absint( $batch_id ) ); }
		if ( absint( $program_id ) ) { $candidates[] = array( 'program', absint( $program_id ) ); }
		if ( absint( $issuer_id ) ) { $candidates[] = array( 'issuer', absint( $issuer_id ) ); }
		$candidates[] = array( 'global', 0 );
		$catalog = new EmailTemplateCatalogRepository();
		foreach ( $candidates as $candidate ) {
			if ( is_array( $excluded ) && (string) $candidate[0] === (string) $excluded[0] && absint( $candidate[1] ) === absint( $excluded[1] ) ) { continue; }
			$key = $this->key( $candidate[0], $candidate[1], $purpose );
			if ( ! isset( $by_key[ $key ] ) ) { continue; }
			$row = $by_key[ $key ];
			$design = $catalog->get( (string) $row['design_id'] );
			if ( ! is_array( $design ) || empty( $design['active'] ) || $purpose !== (string) ( $design['purpose'] ?? '' ) ) {
				return new \WP_Error( 'certloom_email_assignment_broken', __( 'The selected email design rule points to an unavailable or incompatible design. Restore inheritance or choose another design before sending.', 'certloom' ) );
			}
			return array( 'design' => $design, 'scope_type' => $candidate[0], 'scope_id' => $candidate[1], 'purpose' => $purpose );
		}
		if ( EmailDesignPurposes::ISSUED === $purpose ) {
			$default = $catalog->get( EmailTemplateCatalogRepository::DEFAULT_ID );
			if ( is_array( $default ) && ! empty( $default['active'] ) && EmailDesignPurposes::ISSUED === (string) ( $default['purpose'] ?? EmailDesignPurposes::ISSUED ) ) {
				return array( 'design' => $default, 'scope_type' => 'catalog_default', 'scope_id' => 0, 'purpose' => $purpose );
			}
		}
		return new \WP_Error( 'certloom_email_design_unresolved', __( 'No published email design is available for this message purpose. Publish a compatible design, then choose it in Design Rules.', 'certloom' ) );
	}

	/** @return bool */
	public function design_is_assigned( $design_id ) {
		$design_id = sanitize_key( (string) $design_id );
		foreach ( $this->all() as $row ) { if ( $design_id === (string) $row['design_id'] ) { return true; } }
		return false;
	}

	/** @return array<string,mixed>|\WP_Error */
	private function normalize( array $row, $validate_target ) {
		$scope = sanitize_key( (string) ( $row['scope_type'] ?? '' ) );
		if ( ! in_array( $scope, array( 'global', 'issuer', 'program', 'batch' ), true ) ) {
			return new \WP_Error( 'certloom_email_assignment_scope_invalid', __( 'Choose Global, Issuer, Program or Batch for email design rules.', 'certloom' ) );
		}
		$id = 'global' === $scope ? 0 : absint( $row['scope_id'] ?? 0 );
		if ( 'global' !== $scope && ! $id ) {
			return new \WP_Error( 'certloom_email_assignment_target_required', __( 'Choose the record that should use this email design.', 'certloom' ) );
		}

		$raw_purpose = sanitize_key( (string) ( $row['purpose'] ?? '' ) );
		if ( ! EmailDesignPurposes::is_valid( $raw_purpose ) ) {
			return new \WP_Error( 'certloom_email_assignment_purpose_invalid', __( 'Choose a valid email message purpose for this design rule.', 'certloom' ) );
		}
		$purpose   = $raw_purpose;
		$design_id = sanitize_key( (string) ( $row['design_id'] ?? '' ) );
		if ( '' === $design_id ) {
			return new \WP_Error( 'certloom_email_assignment_design_required', __( 'Choose an email design for this design rule.', 'certloom' ) );
		}

		// Reads preserve structurally valid rules even if their referenced design or
		// target has since disappeared. resolve() must see those broken explicit
		// choices and fail closed instead of silently falling through to a parent.
		if ( $validate_target ) {
			$design = ( new EmailTemplateCatalogRepository() )->get( $design_id );
			if ( ! is_array( $design ) || empty( $design['active'] ) || $purpose !== (string) ( $design['purpose'] ?? '' ) ) {
				return new \WP_Error( 'certloom_email_assignment_design_invalid', __( 'Choose an active email design made for the same message purpose.', 'certloom' ) );
			}
			if ( 'global' !== $scope ) {
				$target = 'issuer' === $scope ? ( new IssuerRepository() )->find( $id ) : ( 'program' === $scope ? ( new ProgramRepository() )->find( $id ) : ( new BatchRepository() )->find( $id ) );
				if ( is_wp_error( $target ) ) { return $target; }
				if ( ! is_array( $target ) ) { return new \WP_Error( 'certloom_email_assignment_target_missing', __( 'The selected inheritance target no longer exists.', 'certloom' ) ); }
			}
		}

		$updated_at = (string) ( $row['updated_at'] ?? '' );
		if ( $validate_target || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $updated_at ) ) {
			$updated_at = current_time( 'mysql', true );
		}
		return array( 'scope_type' => $scope, 'scope_id' => $id, 'purpose' => $purpose, 'design_id' => $design_id, 'updated_at' => $updated_at );
	}

	/** @return true|\WP_Error */
	private function persist( array $rows ) {
		$rows = array_values( $rows );
		$saved = update_option( self::OPTION, $rows, false );
		return $saved || $rows === get_option( self::OPTION, array() ) ? true : new \WP_Error( 'certloom_email_assignment_save_failed', __( 'Certloom could not save the email design rule.', 'certloom' ) );
	}

	private function key( $scope, $id, $purpose ) { return sanitize_key( (string) $purpose ) . '|' . sanitize_key( (string) $scope ) . '|' . absint( $id ); }
	private function same_key( array $a, array $b ) { return $this->key( $a['scope_type'], $a['scope_id'], $a['purpose'] ) === $this->key( $b['scope_type'], $b['scope_id'], $b['purpose'] ); }
}
