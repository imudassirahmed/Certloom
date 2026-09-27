<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Ecapla_CertVerify_Public_Policy' ) ) {
	require_once dirname( __FILE__ ) . '/class-ecapla-certverify-public-policy.php';
}

/**
 * Transport-neutral public presentation for the currently authoritative legacy registry.
 *
 * Shortcode, Gutenberg, Elementor, REST and clean/profile routes must consume this
 * class rather than independently deciding which fields, status metadata or
 * public actions are safe to expose.
 */
class Ecapla_CertVerify_Public_Presentation {

	/** @var array<string,mixed> Request-local lookup cache. */
	private static $lookup_cache = array();

	/** @var array<string,bool> Request-local analytics deduplication. */
	private static $analytics_recorded = array();

	/**
	 * Looks up and presents one legacy certificate.
	 *
	 * @param string $number Certificate number.
	 * @param bool   $rate_limit Whether public rate limiting should be applied.
	 * @return array|WP_Error Presentation array, not-found result, or fail-closed error.
	 */
	public static function verify( $number, $rate_limit = true, $context = 'verification', $secondary_code = '', $source = 'manual' ) {
		if ( ! is_scalar( $number ) ) { return array( 'found' => false ); }
		$number = trim( sanitize_text_field( (string) $number ) );
		$source = class_exists( 'Ecapla_CertVerify_Analytics' ) ? Ecapla_CertVerify_Analytics::normalize_source( $source ) : 'manual';
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $number ) : strlen( $number );
		if ( '' === $number || $length > 100 ) {
			self::record_analytics( 'invalid', $source, 0, $number, $rate_limit, $context );
			return array( 'found' => false );
		}
		if ( function_exists( 'ecapla_certverify_verification_is_available' ) && ! ecapla_certverify_verification_is_available() ) {
			return new WP_Error( 'ecapla_certverify_verification_unavailable', __( 'Certificate verification is temporarily unavailable. Please try again later.', 'certloom' ) );
		}

		$key = Ecapla_CertVerify_Database::normalize_certificate_number( $number );
		if ( ! array_key_exists( $key, self::$lookup_cache ) ) {
			if ( $rate_limit && Ecapla_CertVerify_REST::is_rate_limited( 'legacy_verify' ) ) {
				self::$lookup_cache[ $key ] = new WP_Error( 'ecapla_certverify_rate_limited', __( 'Too many verification requests. Please wait a minute and try again.', 'certloom' ) );
			} else {
				self::$lookup_cache[ $key ] = Ecapla_CertVerify_Database::get_by_cert_number( $number );
			}
		}

		$certificate = self::$lookup_cache[ $key ];
		if ( is_wp_error( $certificate ) ) {
			return $certificate;
		}
		if ( ! is_array( $certificate ) ) {
			$native = self::verify_native_number( $number, $context );
			if ( is_wp_error( $native ) || ! empty( $native['found'] ) ) {
				return $native;
			}
			if ( class_exists( 'Ecapla_CertVerify_Secondary_Code' ) ) {
				Ecapla_CertVerify_Secondary_Code::equalize_public_miss( $secondary_code );
			}
			self::record_analytics( 'failed', $source, 0, $key, $rate_limit, $context );
			return array( 'found' => false );
		}

		$custom = isset( $certificate['custom_fields'] ) && is_array( $certificate['custom_fields'] ) ? $certificate['custom_fields'] : array();
		$stored_proof = isset( $custom['__certloom_verification_code_hash'] ) ? trim( (string) $custom['__certloom_verification_code_hash'] ) : '';
		if ( '' !== $stored_proof && ! class_exists( 'Ecapla_CertVerify_Secondary_Code' ) ) {
			return new WP_Error( 'ecapla_certverify_secondary_proof_unavailable', __( 'Certificate verification is temporarily unavailable. Please try again later.', 'certloom' ) );
		}

		/* A configured secondary code is only an additional proof for this exact row. */
		$has_secondary = class_exists( 'Ecapla_CertVerify_Secondary_Code' ) && Ecapla_CertVerify_Secondary_Code::has_code( $certificate );
		if ( $has_secondary ) {
			if ( ! Ecapla_CertVerify_Secondary_Code::matches( $certificate, $secondary_code ) ) {
				self::record_analytics( 'failed', $source, 0, $key, $rate_limit, $context );
				return array( 'found' => false );
			}
		} elseif ( class_exists( 'Ecapla_CertVerify_Secondary_Code' ) && '' !== trim( (string) $secondary_code ) ) {
			/* Match bcrypt work for private/unprotected rows when callers supplied a valid-shaped proof. */
			Ecapla_CertVerify_Secondary_Code::equalize_public_miss( $secondary_code );
		}

		$presented = self::present_certificate( $certificate, $context );
		if ( ! empty( $presented['found'] ) ) {
			$presented['secondary_proof'] = $has_secondary ? 'verified' : 'not_required';
			$status_key = isset( $presented['status']['key'] ) ? sanitize_key( (string) $presented['status']['key'] ) : '';
			$outcome = 'valid' === $status_key ? 'success' : ( 'expired' === $status_key ? 'expired' : ( 'revoked' === $status_key ? 'revoked' : 'failed' ) );
			self::record_analytics( $outcome, $source, max( 0, (int) ( $certificate['id'] ?? 0 ) ), $key, $rate_limit, $context );
		} else {
			self::record_analytics( 'failed', $source, 0, $key, $rate_limit, $context );
		}
		return $presented;
	}

	/** Resolve numbers issued by the normalized workflow through its own visibility policy. */
	private static function verify_native_number( $number, $context ) {
		if ( ! class_exists( '\\Certloom\\Infrastructure\\Repository\\CredentialRepository' ) ) {
			return array( 'found' => false );
		}
		$gate = new \Certloom\Infrastructure\Verification\PublicVerificationGate();
		if ( ! $gate->is_ready() ) {
			return array( 'found' => false );
		}
		$repo = new \Certloom\Infrastructure\Repository\CredentialRepository();
		$series = $repo->find_series_by_display_number( $number );
		if ( is_wp_error( $series ) ) { return $series; }
		if ( ! is_array( $series ) ) { return array( 'found' => false ); }
		$result = ( new \Certloom\Application\CredentialVerificationService( $repo ) )->verify_public_id( (string) ( $series['public_id'] ?? '' ) );
		if ( is_wp_error( $result ) ) { return $result; }
		$payload = ( new \Certloom\Application\PublicCredentialPresenter() )->present( $result, $context );
		if ( empty( $payload['found'] ) ) { return array( 'found' => false ); }
		$url = ( new \Certloom\Infrastructure\Issuance\NormalizedPublicCredentialUrl() )->verification_url( $result->public_id() );
		$url = is_wp_error( $url ) ? '' : (string) $url;
		$fields = array();
		foreach ( array(
			__( 'Certificate number', 'certloom' ) => (string) ( $payload['certificate_number'] ?? '' ),
			__( 'Issued', 'certloom' ) => (string) ( $payload['issued_at'] ?? '' ),
			__( 'Expires', 'certloom' ) => (string) ( $payload['expires_at'] ?? '' ),
		) as $label => $value ) {
			if ( '' !== $value ) { $fields[] = array( 'label' => $label, 'value' => $value, 'is_status' => false ); }
		}
		$fields = array_merge( $fields, (array) ( $payload['fields'] ?? array() ) );
		$profile = is_array( $payload['profile'] ?? null ) ? $payload['profile'] : array();
		$status = is_array( $payload['status'] ?? null ) ? $payload['status'] : array();
		$actions = '' === $url ? array() : array(
			array( 'type' => 'copy', 'label' => __( 'Copy link', 'certloom' ), 'url' => $url ),
			array( 'type' => 'profile', 'label' => __( 'View credential profile', 'certloom' ), 'url' => $url ),
		);
		return array(
			'found' => true,
			'certificate_number' => (string) ( $payload['certificate_number'] ?? '' ),
			'fields' => $fields,
			'status' => array(
				'key' => sanitize_key( (string) ( $status['code'] ?? 'unknown' ) ),
				'label' => self::native_status_label( $status ),
				'description' => (string) ( $status['description'] ?? '' ),
			),
			'issuer' => (string) ( $profile['issuer_name'] ?? '' ),
			'verified_at' => current_time( 'mysql' ),
			'verification_url' => $url,
			'profile_url' => $url,
			'visibility' => ! empty( $payload['profile_visible'] ) ? 'public' : 'verification_summary',
			'actions' => $actions,
			'labels' => array(
				'issued_by' => __( 'Issued by', 'certloom' ),
				'verified' => __( 'Verified', 'certloom' ),
			),
		);
	}

	/** @return string */
	private static function native_status_label( array $status ) {
		$labels = array(
			'valid' => __( 'Valid credential', 'certloom' ),
			'expired' => __( 'Expired credential', 'certloom' ),
			'revoked' => __( 'Revoked credential', 'certloom' ),
			'suspended' => __( 'Suspended credential', 'certloom' ),
			'not_yet_valid' => __( 'Not yet valid', 'certloom' ),
			'pending_review' => __( 'Pending review', 'certloom' ),
			'suspended_noncompliant' => __( 'Requirements not met', 'certloom' ),
		);
		$code = sanitize_key( (string) ( $status['code'] ?? '' ) );
		return $labels[ $code ] ?? __( 'Credential status', 'certloom' );
	}

	/**
	 * Shapes one already-read legacy row into the shared guest-safe view model.
	 *
	 * @param array $certificate Decoded legacy record.
	 * @return array<string,mixed>
	 */
	public static function present_certificate( array $certificate, $context = 'verification' ) {
		$context   = 'profile' === sanitize_key( (string) $context ) ? 'profile' : 'verification';
		if ( ! Ecapla_CertVerify_Public_Policy::allows_context( $certificate, $context ) ) { return array( 'found' => false ); }
		$number    = isset( $certificate['cert_number'] ) ? (string) $certificate['cert_number'] : '';
		$effective = Ecapla_CertVerify_Database::effective_status( $certificate );
		$statuses  = Ecapla_CertVerify_Fields::get_statuses();
		$status    = isset( $statuses[ $effective ] ) ? $statuses[ $effective ] : array(
			'label' => ucfirst( sanitize_key( $effective ) ),
			'desc'  => '',
		);
		$state     = class_exists( 'Ecapla_CertVerify_Onboarding' ) ? Ecapla_CertVerify_Onboarding::get_state() : array();
		$issuer    = isset( $state['organization_name'] ) ? trim( (string) $state['organization_name'] ) : '';
		$verify    = class_exists( 'Ecapla_CertVerify_Public_Router' ) ? Ecapla_CertVerify_Public_Router::verification_url( $number ) : '';
		$requires_secondary = false;
		if ( method_exists( 'Ecapla_CertVerify_Public_Policy', 'requires_secondary_proof' ) ) {
			$requires_secondary = Ecapla_CertVerify_Public_Policy::requires_secondary_proof( $certificate );
		} else {
			/* Compatibility for independently loaded/minimal policy adapters. */
			$requires_secondary = ! empty( $custom['__certloom_verification_code_hash'] );
		}
		$profile = Ecapla_CertVerify_Public_Policy::MODE_PROFILE === Ecapla_CertVerify_Public_Policy::guest_mode( $certificate )
			&& ! $requires_secondary
			&& class_exists( 'Ecapla_CertVerify_Public_Router' ) ? Ecapla_CertVerify_Public_Router::profile_url( $number ) : '';

		return array(
			'found'              => true,
			'certificate_number' => $number,
			'fields'             => self::fields_for_output( $certificate, $context ),
			'status'             => array(
				'key'         => sanitize_key( $effective ),
				'label'       => isset( $status['label'] ) ? (string) $status['label'] : ucfirst( sanitize_key( $effective ) ),
				'description' => isset( $status['desc'] ) ? (string) $status['desc'] : '',
			),
			'issuer'             => $issuer,
			'verified_at'        => self::verification_timestamp(),
			'verification_url'   => $verify,
			'profile_url'        => $profile,
			'visibility'         => Ecapla_CertVerify_Public_Policy::visibility( $certificate ),
			'actions'            => Ecapla_CertVerify_Public_Policy::actions( $certificate, $context, $verify, $profile ),
			'labels'             => array(
				'issued_by' => __( 'Issued by', 'certloom' ),
				'verified'  => __( 'Verified', 'certloom' ),
			),
		);
	}

	/** @return array<int,array<string,mixed>> */
	public static function fields_for_output( array $certificate, $context = 'verification' ) {
		$output = array();
		foreach ( Ecapla_CertVerify_Fields::get_visible_fields( $context ) as $field ) {
			$raw = ! empty( $field['column'] )
				? ( isset( $certificate[ $field['key'] ] ) ? $certificate[ $field['key'] ] : '' )
				: ( isset( $certificate['custom_fields'][ $field['key'] ] ) ? $certificate['custom_fields'][ $field['key'] ] : '' );

			if ( 'status' === $field['type'] ) {
				$effective = Ecapla_CertVerify_Database::effective_status( $certificate );
				$statuses  = Ecapla_CertVerify_Fields::get_statuses();
				$output[]  = array(
					'label'      => $field['label'],
					'value'      => isset( $statuses[ $effective ] ) ? $statuses[ $effective ]['label'] : ucfirst( $effective ),
					'is_status'  => true,
					'status_key' => $effective,
				);
				continue;
			}

			if ( 'date' === $field['type'] && $raw ) {
				$raw = Ecapla_CertVerify_Validator::format_date( $raw );
			}
			$output[] = array(
				'label'     => $field['label'],
				'value'     => (string) $raw,
				'is_status' => false,
			);
		}
		return $output;
	}

	/** Public verification analytics are best-effort and never change verification truth. */
	private static function record_analytics( $outcome, $source, $credential_id, $lookup_key, $rate_limit, $context ) {
		if ( ! $rate_limit || 'verification' !== sanitize_key( (string) $context ) || ! class_exists( 'Ecapla_CertVerify_Analytics' ) ) {
			return;
		}
		$dedupe = hash( 'sha256', sanitize_key( (string) $outcome ) . '|' . sanitize_key( (string) $source ) . '|' . max( 0, (int) $credential_id ) . '|' . (string) $lookup_key );
		if ( isset( self::$analytics_recorded[ $dedupe ] ) ) {
			return;
		}
		self::$analytics_recorded[ $dedupe ] = true;
		Ecapla_CertVerify_Analytics::record( $outcome, $source, $credential_id );
	}

	private static function verification_timestamp() {
		if ( function_exists( 'current_time' ) ) {
			$timestamp = current_time( 'timestamp', true );
			if ( is_numeric( $timestamp ) ) {
				return gmdate( 'c', (int) $timestamp );
			}
		}
		return gmdate( 'c' );
	}
}
