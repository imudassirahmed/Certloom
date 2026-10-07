<?php
namespace Certloom\Application\Issuance;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Optional operational policy. No recipient answer, source authority or certificate expiry. */
final class PendingRequestPolicy {
	const KEY = 'pending_request_policy';
	const NOTICE_SINCE_KEY = 'pending_request_notice_since';
	public static function notice_since_defaults() { return array( 'completion' => null, 'cancellation' => null ); }
	public function read_notice_since( $lock = false ) {
		$stored = \Certloom\Infrastructure\Data\DatabaseOptions::read( array( 'certverify_settings' ), $lock );
		if ( is_wp_error( $stored ) ) { return $stored; }
		$settings = $stored['certverify_settings']['value'];
		return $this->normalize_notice_since( is_array( $settings ) ? ( $settings[ self::NOTICE_SINCE_KEY ] ?? self::notice_since_defaults() ) : self::notice_since_defaults() );
	}
	public function normalize_notice_since( $value ) {
		if ( ! is_array( $value ) || count( $value ) !== 2 || array_diff( array_keys( $value ), array( 'completion', 'cancellation' ) ) ) { return $this->invalid(); }
		foreach ( $value as $date ) { if ( null === $date ) { continue; } try { $parsed = is_string( $date ) && strlen( $date ) === 19 ? \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $date, new \DateTimeZone( 'UTC' ) ) : false; } catch ( \Throwable $error ) { return $this->invalid(); } if ( ! $parsed || $parsed->format( 'Y-m-d H:i:s' ) !== $date ) { return $this->invalid(); } }
		return $value;
	}
	public static function defaults() { return array( 'version' => 1, 'reminder_days' => 0, 'reminder_limit' => 3, 'completion_notice' => false, 'cancellation_notice' => false, 'expiry_days' => 0 ); }
	public function normalize( $value ) {
		$defaults = self::defaults();
		if ( ! is_array( $value ) || count( $value ) !== count( $defaults ) || array_diff( array_keys( $value ), array_keys( $defaults ) ) || 1 !== $value['version'] ) { return $this->invalid(); }
		foreach ( array( 'reminder_days' => 90, 'reminder_limit' => 3, 'expiry_days' => 3650 ) as $key => $maximum ) {
			if ( ! is_int( $value[ $key ] ) || $value[ $key ] < 0 || $value[ $key ] > $maximum ) { return $this->invalid(); }
		}
		if ( ! is_bool( $value['completion_notice'] ) || ! is_bool( $value['cancellation_notice'] ) || ( $value['reminder_days'] && ! $value['reminder_limit'] ) ) { return $this->invalid(); }
		return $value;
	}
	public function read( $lock = false ) {
		$stored = \Certloom\Infrastructure\Data\DatabaseOptions::read( array( 'certverify_settings' ), $lock );
		if ( is_wp_error( $stored ) ) { return $stored; }
		$settings = $stored['certverify_settings']['value'];
		if ( $stored['certverify_settings']['exists'] && ! is_array( $settings ) ) { return $this->invalid(); }
		return $this->normalize( $settings[ self::KEY ] ?? self::defaults() );
	}
	public function expired( array $row, array $policy, $now ) {
		return $policy['expiry_days'] > 0 && 'local' === $row['restore_state'] && ! in_array( $row['status'], array( 'issued', 'cancelled' ), true ) && $row['created_at'] <= gmdate( 'Y-m-d H:i:s', $now - $policy['expiry_days'] * DAY_IN_SECONDS );
	}
	public function save( $actor, array $value, $expected_sha256 ) {
		if ( ! $actor || ! user_can( $actor, \Ecapla_CertVerify_Permissions::CAP_MANAGE_SETTINGS ) ) { return new \WP_Error( 'certloom_request_policy_forbidden', __( 'You cannot change certificate request settings.', 'certloom' ), array( 'status' => 403 ) ); }
		$value = $this->normalize( $value ); if ( is_wp_error( $value ) ) { return $value; }
		return ( new \Certloom\Infrastructure\Data\DurableOptionMutation() )->change( 'certverify_settings', function ( $raw ) use ( $value, $expected_sha256 ) {
			$settings = null === $raw ? array() : maybe_unserialize( $raw );
			if ( ! is_array( $settings ) ) { return $this->invalid(); }
			$current = $this->normalize( $settings[ self::KEY ] ?? self::defaults() );
			if ( is_wp_error( $current ) ) { return $current; }
			if ( ! is_string( $expected_sha256 ) || ! hash_equals( $this->fingerprint( $current ), $expected_sha256 ) ) { return new \WP_Error( 'certloom_request_policy_conflict', __( 'These request settings changed in another session. Refresh before saving.', 'certloom' ), array( 'status' => 409 ) ); }
			$since = $this->normalize_notice_since( $settings[ self::NOTICE_SINCE_KEY ] ?? self::notice_since_defaults() );
			if ( is_wp_error( $since ) ) { return $since; }
			foreach ( array( 'completion', 'cancellation' ) as $kind ) {
				if ( ! $value[ $kind . '_notice' ] ) { $since[ $kind ] = null; }
				elseif ( ! $current[ $kind . '_notice' ] || null === $since[ $kind ] ) { $since[ $kind ] = gmdate( 'Y-m-d H:i:s' ); }
			}
			$settings[ self::NOTICE_SINCE_KEY ] = $since;
			$settings[ self::KEY ] = $value;
			return maybe_serialize( $settings );
		} );
	}
	public function fingerprint( array $policy ) { return ( new \Certloom\Application\Rendering\CanonicalJson() )->hash( $policy ); }
	private function invalid() { return new \WP_Error( 'certloom_request_policy_invalid', __( 'Choose valid reminder and request expiry settings.', 'certloom' ), array( 'status' => 400 ) ); }
}
