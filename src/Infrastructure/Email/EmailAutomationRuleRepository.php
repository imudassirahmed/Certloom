<?php
namespace Certloom\Infrastructure\Email;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Server-owned rules that run only after canonical credential issuance. */
final class EmailAutomationRuleRepository {
	const OPTION    = 'certloom_email_automation_rules_v1';
	const MAX_RULES = 100;

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
		$saved = update_option( self::OPTION, array_values( $all ), false );
		return $saved || array_values( $all ) === get_option( self::OPTION, array() )
			? (string) $normalized['id']
			: new \WP_Error( 'certloom_email_rule_save_failed', __( 'Certloom could not save the email automation.', 'certloom' ) );
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
		$saved = update_option( self::OPTION, $all, false );
		return $saved || $all === get_option( self::OPTION, array() )
			? true
			: new \WP_Error( 'certloom_email_rule_delete_failed', __( 'Certloom could not remove the email automation.', 'certloom' ) );
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
		$template_id = sanitize_key( (string) ( $rule['template_id'] ?? EmailTemplateCatalogRepository::DEFAULT_ID ) );
		$template    = ( new EmailTemplateCatalogRepository() )->get( $template_id );
		if ( ! is_array( $template ) || empty( $template['active'] ) ) {
			return new \WP_Error( 'certloom_email_rule_template_missing', __( 'Choose an available email template for this automation.', 'certloom' ) );
		}
		$source_type = sanitize_key( (string) ( $rule['source_type'] ?? 'any' ) );
		$allowed     = array( 'any', 'manual_admin', 'bulk_admin', 'tutor_lms', 'learndash', 'woocommerce', 'coursera', 'webhook' );
		if ( ! in_array( $source_type, $allowed, true ) ) {
			return new \WP_Error( 'certloom_email_rule_source_invalid', __( 'Choose a supported issuance source.', 'certloom' ) );
		}
		return array(
			'id'          => $id,
			'name'        => $name,
			'active'      => empty( $rule['active'] ) ? 0 : 1,
			'trigger'     => 'credential_issued',
			'issuer_id'   => absint( $rule['issuer_id'] ?? 0 ),
			'program_id'  => absint( $rule['program_id'] ?? 0 ),
			'batch_id'    => absint( $rule['batch_id'] ?? 0 ),
			'template_id' => $template_id,
			'source_type' => $source_type,
			'updated_at'  => current_time( 'mysql', true ),
		);
	}
}
