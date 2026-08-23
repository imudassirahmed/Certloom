<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central guest visibility and action policy for the currently authoritative legacy registry.
 *
 * This mirrors the normalized-domain visibility vocabulary where it can be represented
 * honestly on legacy records. Browser controls never create permission; renderers and
 * future artifact/share endpoints must ask this policy server-side.
 */
final class Ecapla_CertVerify_Public_Policy {
	const VISIBILITY_PUBLIC              = 'public';
	const VISIBILITY_VERIFICATION_SUMMARY = 'verification_summary';
	const VISIBILITY_PRIVATE             = 'private';

	const MODE_DENY    = 'deny';
	const MODE_SUMMARY = 'summary';
	const MODE_PROFILE = 'profile';

	/** @return array<string,string> */
	public static function visibility_options() {
		return array(
			self::VISIBILITY_PUBLIC               => __( 'Public profile', 'certloom' ),
			self::VISIBILITY_VERIFICATION_SUMMARY => __( 'Verification only', 'certloom' ),
			self::VISIBILITY_PRIVATE              => __( 'Private', 'certloom' ),
		);
	}

	/** @return string */
	public static function visibility( array $certificate ) {
		$custom = isset( $certificate['custom_fields'] ) && is_array( $certificate['custom_fields'] ) ? $certificate['custom_fields'] : array();
		if ( ! array_key_exists( '__certloom_profile_visibility', $custom ) ) { return self::VISIBILITY_PUBLIC; }
		$value = sanitize_key( (string) $custom['__certloom_profile_visibility'] );
		return array_key_exists( $value, self::visibility_options() ) ? $value : self::VISIBILITY_PRIVATE;
	}

	/** @return string */
	public static function guest_mode( array $certificate ) {
		switch ( self::visibility( $certificate ) ) {
			case self::VISIBILITY_PUBLIC:
				return self::MODE_PROFILE;
			case self::VISIBILITY_VERIFICATION_SUMMARY:
				return self::MODE_SUMMARY;
			case self::VISIBILITY_PRIVATE:
			default:
				return self::MODE_DENY;
		}
	}

	/** @return bool */
	public static function allows_context( array $certificate, $context ) {
		$mode    = self::guest_mode( $certificate );
		$context = sanitize_key( (string) $context );
		if ( 'profile' === $context ) {
			return self::MODE_PROFILE === $mode;
		}
		return self::MODE_DENY !== $mode;
	}

	/**
	 * A credential protected by the optional secondary proof cannot expose a
	 * standalone profile link because proof material is deliberately POST-only.
	 * The canonical verification URL remains safe to copy/encode in QR and will
	 * ask for the proof again.
	 *
	 * @return bool
	 */
	public static function requires_secondary_proof( array $certificate ) {
		$custom = isset( $certificate['custom_fields'] ) && is_array( $certificate['custom_fields'] ) ? $certificate['custom_fields'] : array();
		return ! empty( $custom['__certloom_verification_code_hash'] );
	}

	/**
	 * Returns only currently implemented, non-mutating actions that are permitted
	 * for this credential/context. Future artifact/share/claim endpoints must add
	 * their own server authorization before adding a browser action.
	 *
	 * @return array<int,array<string,string>>
	 */
	public static function allows_action( $action, array $certificate, $context = 'verification' ) {
		$action = sanitize_key( (string) $action );
		if ( ! self::allows_context( $certificate, $context ) ) { return false; }
		if ( 'copy' === $action || 'print' === $action ) { return true; }
		if ( 'profile' === $action ) {
			return ! self::requires_secondary_proof( $certificate )
				&& self::MODE_PROFILE === self::guest_mode( $certificate )
				&& 'profile' !== sanitize_key( (string) $context );
		}
		/* PDF/image/share/claim and unknown actions fail closed until dedicated server authorization ships. */
		return false;
	}

	public static function actions( array $certificate, $context, $verification_url, $profile_url ) {
		if ( ! self::allows_context( $certificate, $context ) ) {
			return array();
		}
		$actions = array();
		if ( self::allows_action( 'copy', $certificate, $context ) && '' !== (string) $verification_url ) {
			$actions[] = array( 'type' => 'copy', 'label' => __( 'Copy verification link', 'certloom' ), 'url' => (string) $verification_url );
		}
		if ( self::allows_action( 'profile', $certificate, $context ) && '' !== (string) $profile_url ) {
			$actions[] = array( 'type' => 'profile', 'label' => __( 'View credential profile', 'certloom' ), 'url' => (string) $profile_url );
		}
		if ( self::allows_action( 'print', $certificate, $context ) ) { $actions[] = array( 'type' => 'print', 'label' => __( 'Print', 'certloom' ), 'url' => '' ); }
		return $actions;
	}
}
