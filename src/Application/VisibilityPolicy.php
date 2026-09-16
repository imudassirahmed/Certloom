<?php
namespace Certloom\Application;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
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

	/** @return string[] */
	public static function recipient_portal_values() {
		return array( 'public', 'verification_summary', 'unlisted', 'recipient_only' );
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

	/**
	 * Public sharing is never broader than guest verification. Unlisted remains
	 * shareable because possession of the canonical URL is its access model;
	 * recipient/org/private credentials stay fail-closed.
	 *
	 * @return bool
	 */
	public function allows_public_sharing( $visibility ) {
		return $this->allows_guest_verification( $visibility );
	}

	/**
	 * Whether the owning linked Recipient may see the credential in My Credentials.
	 *
	 * `private` remains administrator-only and `organization_member` remains closed
	 * until an accepted organization-membership identity contract exists. This is
	 * intentionally stricter than simply treating every non-public credential as
	 * visible to a logged-in user.
	 *
	 * @return bool
	 */
	public function allows_recipient_portal( $visibility ) {
		$visibility = strtolower( trim( (string) $visibility ) );
		return in_array( $visibility, self::recipient_portal_values(), true );
	}
}
