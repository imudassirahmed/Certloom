<?php
namespace Certloom\Infrastructure\Verification;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Commercial entitlement adapter. Release/domain acceptance is not a license. */
final class ProVerificationAccess {
	public static function enabled() {
		// A Pro companion supplies a server-validated entitlement. Never accept
		// attributes, request parameters, editor capability or a stored UI setting.
		try {
			return true === apply_filters( 'certloom_pro_feature_enabled', false, 'verification_studio' );
		} catch ( \Throwable $error ) {
			// A failed commercial adapter must never take public verification down.
			return false;
		}
	}
}
