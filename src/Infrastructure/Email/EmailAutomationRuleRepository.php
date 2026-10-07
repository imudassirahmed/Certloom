<?php
namespace Certloom\Infrastructure\Email;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Server-owned rules that run only after canonical credential issuance. */
final class EmailAutomationRuleRepository {
	const OPTION    = 'certloom_email_automation_rules_v1';
	const MAX_RULES = 100;
	public function validate_recovery_value( $value ) {
		return \Certloom\Application\Restore\WholeStateOptionRows::validate_list( $value, self::MAX_RULES, function ( $row ) { return $this->normalize( $row, false ); }, array( 'id' ) );
	}

	/** @return array<int,array<string,mixed>> */
	public function all() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}
		$out = array();
		foreach ( array_slice( $stored, 0, self::MAX_RULES ) as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			$normalized = $this->normalize( $rule, false );
			if ( ! is_wp_error( $normalized ) ) {
				$out[] = $normalized;
			}
		}
		return array_values( $out );
	}

	/** @return array<int,array<string,mixed>> */
	public function matching( $issuer_id, $program_id, $batch_id, $source_type ) {
		$issuer_id   = absint( $issuer_id );
		$program_id  = absint( $program_id );
		$batch_id    = absint( $batch_id );
		$source_type = sanitize_key( (string) $source_type );
		return array_values(
			array_filter(
				$this->all(),
				static function ( array $rule ) use ( $issuer_id, $program_id, $batch_id, $source_type ) {
					if ( empty( $rule['active'] ) ) { return false; }
					if ( absint( $rule['issuer_id'] ?? 0 ) > 0 && absint( $rule['issuer_id'] ) !== $issuer_id ) { return false; }
					if ( absint( $rule['program_id'] ?? 0 ) > 0 && absint( $rule['program_id'] ) !== $program_id ) { return false; }
					if ( absint( $rule['batch_id'] ?? 0 ) > 0 && absint( $rule['batch_id'] ) !== $batch_id ) { return false; }
					return 'any' === (string) $rule['source_type'] || (string) $rule['source_type'] === $source_type;
				}
			)
		);
	}


	/** @return array<string,mixed>|null */
	public function best_matching( $issuer_id, $program_id, $batch_id, $source_type ) {
		$matches = $this->matching( $issuer_id, $program_id, $batch_id, $source_type );
		if ( empty( $matches ) ) { return null; }
		usort( $matches, static function( array $a, array $b ) use ( $source_type ) {
			$score = static function( array $rule ) use ( $source_type ) {
				return ( absint( $rule['batch_id'] ?? 0 ) ? 16 : 0 )
					+ ( absint( $rule['program_id'] ?? 0 ) ? 8 : 0 )
					+ ( absint( $rule['issuer_id'] ?? 0 ) ? 4 : 0 )
					+ ( 'any' !== (string) ( $rule['source_type'] ?? 'any' ) && (string) $rule['source_type'] === sanitize_key( (string) $source_type ) ? 2 : 0 );
			};
			$delta = $score( $b ) - $score( $a );
			return 0 !== $delta ? $delta : strcmp( (string) ( $a['id'] ?? '' ), (string) ( $b['id'] ?? '' ) );
		} );
		return $matches[0];
	}

	/** @return string|\WP_Error */
	public function save( array $rule ) {
		$normalized = $this->normalize( $rule, true );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}
		$all   = $this->all();
		$found = false;
		foreach ( $all as $index => $existing ) {
			if ( (string) $existing['id'] === (string) $normalized['id'] ) {
				$all[ $index ] = $normalized;
				$found = true;
				break;
			}
		}
		if ( ! $found ) {
			if ( count( $all ) >= self::MAX_RULES ) {
				return new \WP_Error( 'certloom_email_rule_limit', __( 'Certloom supports up to 100 email automation rules per site.', 'certloom' ) );
			}
			$all[] = $normalized;
		}
		$saved = ( new \Certloom\Infrastructure\Data\DurableOptionMutation() )->set( self::OPTION, array_values( $all ) );
		return is_wp_error( $saved ) ? $saved : (string) $normalized['id'];
	}

	/** Change only the delivery state of an existing rule. @return string|\WP_Error */
	public function set_active( $rule_id, $active ) {
		$rule_id = sanitize_key( (string) $rule_id );
		foreach ( $this->all() as $rule ) {
			if ( $rule_id === (string) $rule['id'] ) {
				$rule['active'] = (bool) $active;
				return $this->save( $rule );
			}
		}
		return new \WP_Error( 'certloom_email_rule_missing', __( 'That automation no longer exists.', 'certloom' ) );
	}

	/** @return true|\WP_Error */
	public function delete( $rule_id ) {
		$rule_id = sanitize_key( (string) $rule_id );
		$all = array_values(
			array_filter(
				$this->all(),
				static function ( array $rule ) use ( $rule_id ) {
					return $rule_id !== (string) $rule['id'];
				}
			)
		);
		return ( new \Certloom\Infrastructure\Data\DurableOptionMutation() )->set( self::OPTION, $all );
	}

	/** @return array<string,mixed>|\WP_Error */
	private function normalize( array $rule, $generate_id ) {
		$id = sanitize_key( (string) ( $rule['id'] ?? '' ) );
		if ( '' === $id && $generate_id ) {
			$id = sanitize_key( 'rule-' . gmdate( 'Ymd-His' ) . '-' . strtolower( wp_generate_password( 6, false, false ) ) );
		}
		if ( '' === $id ) {
			return new \WP_Error( 'certloom_email_rule_id_invalid', __( 'The automation identity is invalid.', 'certloom' ) );
		}
		$name = trim( sanitize_text_field( (string) ( $rule['name'] ?? '' ) ) );
		if ( '' === $name || strlen( $name ) > 120 ) {
			return new \WP_Error( 'certloom_email_rule_name_invalid', __( 'Give the automation a name between 1 and 120 characters.', 'certloom' ) );
		}
		$legacy_template_id = sanitize_key( (string) ( $rule['legacy_template_id'] ?? $rule['template_id'] ?? '' ) );
		$source_type = sanitize_key( (string) ( $rule['source_type'] ?? 'any' ) );
		$allowed     = array( 'any', 'manual_admin', 'bulk_admin', 'tutor_lms', 'learndash', 'woocommerce', 'coursera', 'webhook' );
		if ( ! in_array( $source_type, $allowed, true ) ) {
			return new \WP_Error( 'certloom_email_rule_source_invalid', __( 'Choose a supported issuance source.', 'certloom' ) );
		}
		$updated_at = trim( (string) ( $rule['updated_at'] ?? '' ) );
		if ( $generate_id || ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $updated_at ) ) {
			$updated_at = current_time( 'mysql', true );
		}
		return array(
			'id'          => $id,
			'name'        => $name,
			'active'      => empty( $rule['active'] ) ? 0 : 1,
			'trigger'     => 'credential_issued',
			'issuer_id'   => absint( $rule['issuer_id'] ?? 0 ),
			'program_id'  => absint( $rule['program_id'] ?? 0 ),
			'batch_id'    => absint( $rule['batch_id'] ?? 0 ),
			'legacy_template_id' => $legacy_template_id,
			'source_type' => $source_type,
			'updated_at'  => $updated_at,
		);
	}
}
