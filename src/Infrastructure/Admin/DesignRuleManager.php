<?php
namespace Certloom\Infrastructure\Admin;

use Certloom\Infrastructure\Email\EmailDesignAssignmentRepository;
use Certloom\Infrastructure\Email\EmailDesignPurposes;
use Certloom\Infrastructure\Email\EmailTemplateCatalogRepository;
use Certloom\Infrastructure\Repository\BatchRepository;
use Certloom\Infrastructure\Repository\IssuerRepository;
use Certloom\Infrastructure\Repository\ProgramRepository;
use Certloom\Infrastructure\Repository\TemplateRepository;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * One mutation/read boundary for the admin Design Rules experience.
 * Certificate and email rules remain independent; this class only coordinates
 * their UI-facing preflight and rollback behavior.
 */
final class DesignRuleManager {
	const MODE_NO_CHANGE = 'no_change';
	const MODE_INHERIT   = 'inherit';
	const MODE_DESIGN    = 'design';
	const MAX_TARGETS    = 500;

	/** @var TemplateRepository */ private $templates;
	/** @var EmailTemplateCatalogRepository */ private $emails;
	/** @var EmailDesignAssignmentRepository */ private $email_rules;
	/** @var IssuerRepository */ private $issuers;
	/** @var ProgramRepository */ private $programs;
	/** @var BatchRepository */ private $batches;
	/** @var DesignHierarchyConfigurationGuard */ private $guard;

	public function __construct() {
		$this->templates = new TemplateRepository();
		$this->emails = new EmailTemplateCatalogRepository();
		$this->email_rules = new EmailDesignAssignmentRepository();
		$this->issuers = new IssuerRepository();
		$this->programs = new ProgramRepository();
		$this->batches = new BatchRepository();
		$this->guard = new DesignHierarchyConfigurationGuard( $this->templates, $this->batches );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function target( $scope_type, $scope_id = 0 ) {
		$scope_type = sanitize_key( (string) $scope_type );
		$scope_id = 'global' === $scope_type ? 0 : absint( $scope_id );
		if ( 'global' === $scope_type ) {
			return array( 'scope_type' => 'global', 'scope_id' => 0, 'label' => __( 'Global defaults', 'certloom' ), 'issuer_id' => 0, 'program_id' => 0, 'batch_id' => 0 );
		}
		if ( 'issuer' === $scope_type ) {
			$row = $this->issuers->find( $scope_id );
			if ( is_wp_error( $row ) ) { return $row; }
			if ( ! is_array( $row ) ) { return new \WP_Error( 'certloom_design_rule_issuer_missing', __( 'The selected Issuer no longer exists.', 'certloom' ) ); }
			return array( 'scope_type' => 'issuer', 'scope_id' => $scope_id, 'label' => (string) ( $row['name'] ?? '' ), 'issuer_id' => $scope_id, 'program_id' => 0, 'batch_id' => 0, 'issuer' => $row );
		}
		if ( 'program' === $scope_type ) {
			$row = $this->programs->find( $scope_id );
			if ( is_wp_error( $row ) ) { return $row; }
			if ( ! is_array( $row ) ) { return new \WP_Error( 'certloom_design_rule_program_missing', __( 'The selected Program no longer exists.', 'certloom' ) ); }
			return array( 'scope_type' => 'program', 'scope_id' => $scope_id, 'label' => (string) ( $row['title'] ?? '' ), 'issuer_id' => absint( $row['issuer_id'] ?? 0 ), 'program_id' => $scope_id, 'batch_id' => 0, 'program' => $row );
		}
		if ( 'batch' === $scope_type ) {
			$batch = $this->batches->find( $scope_id );
			if ( is_wp_error( $batch ) ) { return $batch; }
			if ( ! is_array( $batch ) ) { return new \WP_Error( 'certloom_design_rule_batch_missing', __( 'The selected Batch no longer exists.', 'certloom' ) ); }
			$program = $this->programs->find( absint( $batch['program_id'] ?? 0 ) );
			if ( is_wp_error( $program ) ) { return $program; }
			if ( ! is_array( $program ) ) { return new \WP_Error( 'certloom_design_rule_batch_program_missing', __( 'The selected Batch no longer has a valid Program.', 'certloom' ) ); }
			return array( 'scope_type' => 'batch', 'scope_id' => $scope_id, 'label' => (string) ( $batch['name'] ?? '' ), 'issuer_id' => absint( $program['issuer_id'] ?? 0 ), 'program_id' => absint( $program['id'] ?? 0 ), 'batch_id' => $scope_id, 'program' => $program, 'batch' => $batch );
		}
		return new \WP_Error( 'certloom_design_rule_scope_invalid', __( 'Choose Global, Issuer, Program or Batch.', 'certloom' ) );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function state( $scope_type, $scope_id = 0, $purpose = EmailDesignPurposes::ISSUED ) {
		$target = $this->target( $scope_type, $scope_id );
		if ( is_wp_error( $target ) ) { return $target; }
		$direct_certificate = $this->templates->get_assignment( $target['scope_type'], $target['scope_id'] );
		if ( is_wp_error( $direct_certificate ) ) { return $direct_certificate; }
		$effective_certificate = $this->templates->resolve_published_assignment_details( $target );
		$direct_email = $this->email_rules->get( $target['scope_type'], $target['scope_id'], $purpose );
		if ( is_wp_error( $direct_email ) ) { return $direct_email; }
		$effective_email = $this->email_rules->resolve( $target['issuer_id'], $target['program_id'], $target['batch_id'], $purpose );
		return array(
			'target' => $target,
			'certificate_direct' => $direct_certificate,
			'certificate_effective' => $effective_certificate,
			'email_direct' => $direct_email,
			'email_effective' => $effective_email,
		);
	}

	/** @return true|\WP_Error */
	public function apply_many( array $targets, $certificate_mode, $certificate_id, $email_mode, $email_id, $purpose ) {
		$certificate_mode = $this->mode( $certificate_mode );
		$email_mode = $this->mode( $email_mode );
		$certificate_id = absint( $certificate_id );
		$email_id = sanitize_key( (string) $email_id );
		$purpose = sanitize_key( (string) $purpose );
		if ( is_wp_error( $certificate_mode ) ) { return $certificate_mode; }
		if ( is_wp_error( $email_mode ) ) { return $email_mode; }
		if ( ! EmailDesignPurposes::is_valid( $purpose ) ) { return new \WP_Error( 'certloom_design_rule_purpose_invalid', __( 'Choose a valid email message purpose.', 'certloom' ) ); }
		if ( self::MODE_DESIGN === $certificate_mode && ! $certificate_id ) { return new \WP_Error( 'certloom_design_rule_certificate_required', __( 'Choose a published certificate design.', 'certloom' ) ); }
		if ( self::MODE_DESIGN === $email_mode && '' === $email_id ) { return new \WP_Error( 'certloom_design_rule_email_required', __( 'Choose a published email design.', 'certloom' ) ); }
		if ( self::MODE_NO_CHANGE === $certificate_mode && self::MODE_NO_CHANGE === $email_mode ) { return new \WP_Error( 'certloom_design_rule_no_changes', __( 'Choose at least one certificate or email design change to apply.', 'certloom' ) ); }
		if ( count( $targets ) > self::MAX_TARGETS ) {
			return new \WP_Error(
				'certloom_design_rule_target_limit',
				sprintf(
					/* translators: %d: maximum number of records in one bulk design-rule change. */
					__( 'Apply design changes to no more than %d records at once.', 'certloom' ),
					self::MAX_TARGETS
				)
			);
		}

		// Validate one selected email design once. Target-specific work belongs in
		// the target preflight loop below; repeating catalog validation for every
		// selected record only adds noise and work to large bulk changes.
		if ( self::MODE_DESIGN === $email_mode ) {
			$design = $this->emails->get( $email_id );
			if ( ! is_array( $design ) || empty( $design['active'] ) || $purpose !== (string) ( $design['purpose'] ?? '' ) ) {
				return new \WP_Error( 'certloom_design_rule_email_invalid', __( 'Choose a published email design for the selected message purpose.', 'certloom' ) );
			}
		}

		$normalized = array();
		$seen = array();
		foreach ( $targets as $raw ) {
			if ( ! is_array( $raw ) ) { continue; }
			$target = $this->target( $raw['scope_type'] ?? '', $raw['scope_id'] ?? 0 );
			if ( is_wp_error( $target ) ) { return $target; }
			$key = (string) $target['scope_type'] . ':' . absint( $target['scope_id'] );
			if ( isset( $seen[ $key ] ) ) { continue; }
			$seen[ $key ] = true;
			$normalized[] = $target;
		}
		if ( empty( $normalized ) ) { return new \WP_Error( 'certloom_design_rule_targets_required', __( 'Select at least one Issuer, Program or Batch.', 'certloom' ) ); }

		// Preflight every target before taking snapshots or writing anything. This
		// is where field compatibility and integration dependencies are proved.
		foreach ( $normalized as $target ) {
			$preflight = $this->preflight_certificate( $target, self::MODE_DESIGN === $certificate_mode ? $certificate_id : 0, $certificate_mode );
			if ( is_wp_error( $preflight ) ) { return $preflight; }
		}

		// Snapshot the complete selection before the first mutation. A read error
		// must stop the operation here; otherwise rollback could not be trusted.
		$snapshots = array();
		foreach ( $normalized as $target ) {
			$key = (string) $target['scope_type'] . ':' . absint( $target['scope_id'] );
			$certificate = $this->templates->get_assignment( $target['scope_type'], $target['scope_id'] );
			if ( is_wp_error( $certificate ) ) { return $certificate; }
			$email = $this->email_rules->get( $target['scope_type'], $target['scope_id'], $purpose );
			if ( is_wp_error( $email ) ) { return $email; }
			$snapshots[ $key ] = array( 'certificate' => $certificate, 'email' => $email );
		}

		$applied = array();
		foreach ( $normalized as $target ) {
			$result = $this->apply_one( $target, $certificate_mode, $certificate_id, $email_mode, $email_id, $purpose );
			if ( is_wp_error( $result ) ) {
				// The current target may have completed its certificate mutation before
				// an email mutation failed, so include it in the rollback set.
				$rollback = $this->rollback( array_reverse( array_merge( $applied, array( $target ) ) ), $snapshots, $purpose );
				if ( is_wp_error( $rollback ) ) {
					return new \WP_Error(
						'certloom_design_rule_rollback_incomplete',
						sprintf(
							/* translators: 1: original save error. 2: rollback error. */
							__( 'Certloom could not complete the design-rule change and could not fully restore the previous state. Original error: %1$s Restoration error: %2$s Review the affected records before trying again.', 'certloom' ),
							$result->get_error_message(),
							$rollback->get_error_message()
						)
					);
				}
				return $result;
			}
			$applied[] = $target;
		}
		return true;
	}

	/** @return array<string,mixed>|\WP_Error|null */
	public function certificate_after( array $target, $mode, $design_id = 0 ) {
		$mode = $this->mode( $mode ); if ( is_wp_error( $mode ) ) { return $mode; }
		if ( self::MODE_DESIGN === $mode ) {
			$template = $this->templates->find_published( absint( $design_id ) );
			return is_array( $template ) ? array( 'template' => $template, 'scope_type' => 'selected', 'scope_id' => 0 ) : $template;
		}
		if ( self::MODE_INHERIT === $mode ) {
			return $this->templates->resolve_published_assignment_details_excluding( $target, $target['scope_type'], $target['scope_id'] );
		}
		return $this->templates->resolve_published_assignment_details( $target );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function email_after( array $target, $mode, $design_id, $purpose ) {
		$mode = $this->mode( $mode ); if ( is_wp_error( $mode ) ) { return $mode; }
		$purpose = sanitize_key( (string) $purpose );
		if ( ! EmailDesignPurposes::is_valid( $purpose ) ) {
			return new \WP_Error( 'certloom_design_rule_purpose_invalid', __( 'Choose a valid email message purpose.', 'certloom' ) );
		}
		if ( self::MODE_DESIGN === $mode ) {
			$design = $this->emails->get( $design_id );
			if ( ! is_array( $design ) || empty( $design['active'] ) || $purpose !== (string) ( $design['purpose'] ?? '' ) ) {
				return new \WP_Error( 'certloom_design_rule_email_invalid', __( 'Choose a published email design for the selected message purpose.', 'certloom' ) );
			}
			return array( 'design' => $design, 'scope_type' => 'selected', 'scope_id' => 0, 'purpose' => $purpose );
		}
		if ( self::MODE_INHERIT === $mode ) { return $this->email_rules->resolve_excluding( $target['issuer_id'], $target['program_id'], $target['batch_id'], $purpose, $target['scope_type'], $target['scope_id'] ); }
		return $this->email_rules->resolve( $target['issuer_id'], $target['program_id'], $target['batch_id'], $purpose );
	}

	/** @return true|\WP_Error */
	private function apply_one( array $target, $certificate_mode, $certificate_id, $email_mode, $email_id, $purpose ) {
		if ( self::MODE_NO_CHANGE !== $certificate_mode ) {
			$existing = $this->templates->get_assignment( $target['scope_type'], $target['scope_id'] );
			if ( is_wp_error( $existing ) ) { return $existing; }
			$result = self::MODE_DESIGN === $certificate_mode
				? $this->templates->assign( $certificate_id, $target['scope_type'], $target['scope_id'], 100, is_array( $existing ) ? absint( $existing['id'] ?? 0 ) : 0, is_array( $existing ) ? (string) ( $existing['updated_at'] ?? '' ) : '' )
				: $this->templates->clear_assignment( $target['scope_type'], $target['scope_id'], is_array( $existing ) ? absint( $existing['id'] ?? 0 ) : 0, is_array( $existing ) ? (string) ( $existing['updated_at'] ?? '' ) : '' );
			if ( is_wp_error( $result ) ) { return $result; }
		}
		if ( self::MODE_NO_CHANGE !== $email_mode ) {
			$result = self::MODE_DESIGN === $email_mode ? $this->email_rules->save( $target['scope_type'], $target['scope_id'], $purpose, $email_id ) : $this->email_rules->remove( $target['scope_type'], $target['scope_id'], $purpose );
			if ( is_wp_error( $result ) ) { return $result; }
		}
		return true;
	}

	/** @return true|\WP_Error */
	private function rollback( array $targets, array $snapshots, $purpose ) {
		$errors = array();
		foreach ( $targets as $target ) {
			$key = (string) $target['scope_type'] . ':' . absint( $target['scope_id'] );
			$snapshot = $snapshots[ $key ] ?? null;
			if ( ! is_array( $snapshot ) ) {
				$errors[] = __( 'A rollback snapshot was unavailable.', 'certloom' );
				continue;
			}

			$current = $this->templates->get_assignment( $target['scope_type'], $target['scope_id'] );
			if ( is_wp_error( $current ) ) {
				$errors[] = $current->get_error_message();
			} else {
				$old = $snapshot['certificate'] ?? null;
				if ( is_array( $old ) && ! empty( $old['active'] ) ) {
					$restored = $this->templates->assign(
						absint( $old['template_id'] ?? 0 ),
						$target['scope_type'],
						$target['scope_id'],
						absint( $old['priority'] ?? 100 ),
						is_array( $current ) ? absint( $current['id'] ?? 0 ) : 0,
						is_array( $current ) ? (string) ( $current['updated_at'] ?? '' ) : ''
					);
				} else {
					$restored = $this->templates->clear_assignment(
						$target['scope_type'],
						$target['scope_id'],
						is_array( $current ) ? absint( $current['id'] ?? 0 ) : 0,
						is_array( $current ) ? (string) ( $current['updated_at'] ?? '' ) : ''
					);
				}
				if ( is_wp_error( $restored ) ) { $errors[] = $restored->get_error_message(); }
			}

			$old_email = $snapshot['email'] ?? null;
			$email_restored = is_array( $old_email )
				? $this->email_rules->save( $target['scope_type'], $target['scope_id'], $purpose, (string) ( $old_email['design_id'] ?? '' ) )
				: $this->email_rules->remove( $target['scope_type'], $target['scope_id'], $purpose );
			if ( is_wp_error( $email_restored ) ) { $errors[] = $email_restored->get_error_message(); }
		}
		if ( empty( $errors ) ) { return true; }
		return new \WP_Error( 'certloom_design_rule_rollback_failed', implode( ' ', array_values( array_unique( $errors ) ) ) );
	}

	/** @return true|\WP_Error */
	private function preflight_certificate( array $target, $template_id, $mode ) {
		if ( self::MODE_NO_CHANGE === $mode ) { return true; }
		if ( 'global' === $target['scope_type'] ) { return $this->guard->validate_global( $template_id ); }
		if ( 'issuer' === $target['scope_type'] ) { return $this->guard->validate_issuer( (array) $target['issuer'], $template_id ); }
		if ( 'program' === $target['scope_type'] ) { return $this->guard->validate_program( (array) $target['program'], $template_id ); }
		return $this->guard->validate_batch( (array) $target['program'], (array) $target['batch'], $template_id );
	}

	/** @return string|\WP_Error */
	private function mode( $mode ) {
		$mode = sanitize_key( (string) $mode );
		return in_array( $mode, array( self::MODE_NO_CHANGE, self::MODE_INHERIT, self::MODE_DESIGN ), true ) ? $mode : new \WP_Error( 'certloom_design_rule_mode_invalid', __( 'Choose whether to keep, inherit or set a design.', 'certloom' ) );
	}
}
