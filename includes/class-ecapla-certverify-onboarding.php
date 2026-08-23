<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Premium, non-blocking first-run setup state. */
class Ecapla_CertVerify_Onboarding {
	const OPTION_KEY = 'certverify_onboarding';

	public static function defaults() {
		return array(
			'organization_name'   => '',
			'verification_page_id' => 0,
			'appearance_reviewed'  => false,
			'verification_tested'   => false,
			'completed_at'         => '',
			'version'              => 1,
		);
	}

	public static function get_state() {
		$saved = get_option( self::OPTION_KEY, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$state = wp_parse_args( $saved, self::defaults() );
		$state['organization_name']   = self::limit_text( sanitize_text_field( (string) $state['organization_name'] ), 200 );
		$state['verification_page_id'] = absint( $state['verification_page_id'] );
		$state['appearance_reviewed']  = ! empty( $state['appearance_reviewed'] );
		$state['verification_tested']   = ! empty( $state['verification_tested'] );
		$state['completed_at']         = sanitize_text_field( (string) $state['completed_at'] );
		$state['version']              = 1;
		return $state;
	}

	/** @return true|WP_Error */
	public static function save_profile( $organization_name, $verification_page_id, $appearance_reviewed = null, $verification_tested = null ) {
		$allowed = Ecapla_CertVerify_Backup::assert_writes_allowed();
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		$name = self::limit_text( sanitize_text_field( (string) $organization_name ), 200 );
		if ( '' === trim( $name ) ) {
			return new WP_Error( 'ecapla_certverify_onboarding_org_required', __( 'Enter the organization name shown in your setup profile.', 'certloom' ) );
		}
		$page_id = absint( $verification_page_id );
		$page = $page_id ? get_post( $page_id ) : null;
		if ( $page_id && ( ! $page || 'page' !== get_post_type( $page_id ) ) ) {
			return new WP_Error( 'ecapla_certverify_onboarding_page_invalid', __( 'Choose a valid WordPress page for certificate verification.', 'certloom' ) );
		}
		$state                         = self::get_state();
		$state['organization_name']    = $name;
		$state['verification_page_id'] = $page_id;
		if ( null !== $appearance_reviewed ) {
			$state['appearance_reviewed'] = (bool) $appearance_reviewed;
		}
		if ( null !== $verification_tested ) {
			$state['verification_tested'] = (bool) $verification_tested;
		}
		return self::persist( $state );
	}

	/** @return int|WP_Error */
	public static function create_verification_page() {
		$allowed = Ecapla_CertVerify_Backup::assert_writes_allowed();
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		$state = self::get_state();
		if ( $state['verification_page_id'] && get_post( $state['verification_page_id'] ) ) {
			return $state['verification_page_id'];
		}
		$page_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => __( 'Verify a Certificate', 'certloom' ),
				'post_content' => '[certloom]',
			),
			true
		);
		if ( is_wp_error( $page_id ) ) {
			return $page_id;
		}
		$state['verification_page_id'] = absint( $page_id );
		$saved = self::persist( $state );
		if ( is_wp_error( $saved ) ) {
			$removed = wp_delete_post( absint( $page_id ), true );
			if ( ! $removed ) {
				return new WP_Error( 'ecapla_certverify_onboarding_page_cleanup_failed', __( 'The verification page was created, but Certloom could not save its setup link or remove the unlinked page. Review Pages before continuing.', 'certloom' ) );
			}
			return $saved;
		}
		return absint( $page_id );
	}

	/** @return true|WP_Error */
	public static function complete() {
		$state = self::get_state();
		if ( '' === trim( $state['organization_name'] ) ) {
			return new WP_Error( 'ecapla_certverify_onboarding_incomplete', __( 'Add your organization name before completing setup.', 'certloom' ) );
		}
		if ( ! $state['verification_page_id'] || ! get_post( $state['verification_page_id'] ) ) {
			return new WP_Error( 'ecapla_certverify_onboarding_incomplete', __( 'Choose or create a verification page before completing setup.', 'certloom' ) );
		}
		$state['completed_at'] = current_time( 'mysql' );
		return self::persist( $state );
	}

	public static function is_complete() {
		return '' !== (string) self::get_state()['completed_at'];
	}

	/** @return array|WP_Error */
	public static function get_summary() {
		$state = self::get_state();
		$count = Ecapla_CertVerify_Database::get_registry_count();
		if ( is_wp_error( $count ) ) {
			return $count;
		}
		$fields = Ecapla_CertVerify_Fields::get_fields();
		$page   = $state['verification_page_id'] ? get_post( $state['verification_page_id'] ) : null;
		$sample_cert_number = '';
		if ( absint( $count ) > 0 ) {
			$sample = Ecapla_CertVerify_Database::get_list( array( 'per_page' => 1, 'page' => 1 ) );
			if ( is_wp_error( $sample ) ) {
				return $sample;
			}
			if ( ! empty( $sample['items'][0]['cert_number'] ) ) {
				$sample_cert_number = (string) $sample['items'][0]['cert_number'];
			}
		}
		$progress_flags = array(
			'' !== trim( $state['organization_name'] ),
			count( $fields ) > 0,
			(bool) $page,
			absint( $count ) > 0,
			! empty( $state['verification_tested'] ),
			! empty( $state['appearance_reviewed'] ),
		);
		return array(
			'state'                => $state,
			'organization_ready'   => $progress_flags[0],
			'fields_ready'         => $progress_flags[1],
			'page_ready'           => $progress_flags[2],
			'registry_ready'       => $progress_flags[3],
			'verification_tested'  => $progress_flags[4],
			'appearance_ready'     => $progress_flags[5],
			'completed'            => '' !== (string) $state['completed_at'],
			'certificate_count'    => absint( $count ),
			'field_count'          => count( $fields ),
			'sample_cert_number'   => $sample_cert_number,
			'progress_completed'   => count( array_filter( $progress_flags ) ),
			'progress_total'       => count( $progress_flags ),
		);
	}

	/** @return true|WP_Error */
	private static function persist( $state ) {
		$state = wp_parse_args( is_array( $state ) ? $state : array(), self::defaults() );
		$state['version'] = 1;
		$stored = get_option( self::OPTION_KEY, array() );
		if ( $state === $stored ) {
			return true;
		}
		$generation = Ecapla_CertVerify_Backup::reserve_mutation_generation();
		if ( is_wp_error( $generation ) ) {
			return $generation;
		}
		$saved = update_option( self::OPTION_KEY, $state, false );
		if ( ! $saved && $state !== get_option( self::OPTION_KEY, array() ) ) {
			return new WP_Error( 'ecapla_certverify_onboarding_save_failed', __( 'Certloom could not save the setup progress.', 'certloom' ) );
		}
		return true;
	}

	private static function limit_text( $value, $limit ) {
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $limit ) : substr( $value, 0, $limit );
	}
}
