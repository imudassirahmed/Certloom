<?php
namespace Certloom\Application;

/**
 * Central public visibility policy for native Credential Series.
 *
 * The policy deliberately distinguishes "can this credential be verified by a
 * guest?" from later richer profile/portal permissions. Public adapters must
 * delegate here instead of reproducing visibility checks independently.
 */
final class VisibilityPolicy {
	const MODE_DENY    = 'deny';
	const MODE_SUMMARY = 'summary';
	const MODE_PROFILE = 'profile';

	/** @return string[] */
	public static function persisted_values() {
		return array( 'public', 'verification_summary', 'unlisted', 'recipient_only', 'organization_member', 'private' );
	}

	/** @return string */
	public function guest_mode( $visibility ) {
		$visibility = strtolower( trim( (string) $visibility ) );
		switch ( $visibility ) {
			case 'public':
				return self::MODE_PROFILE;
			case 'verification_summary':
			case 'unlisted':
				return self::MODE_SUMMARY;
			case 'recipient_only':
			case 'organization_member':
			case 'private':
			default:
				return self::MODE_DENY;
		}
	}

	/** @return bool */
	public function allows_guest_verification( $visibility ) {
		return self::MODE_DENY !== $this->guest_mode( $visibility );
	}
}
