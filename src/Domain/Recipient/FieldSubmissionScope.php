<?php
namespace Certloom\Domain\Recipient;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Stable indexed identity for one recipient-owned field in one context. */
final class FieldSubmissionScope {
	public static function key( $recipient_id, $kind, $scope_id, $field_key ) {
		return hash( 'sha256', implode( ':', array( 'certloom-field-scope-v1', (int) $recipient_id, (string) $kind, (int) $scope_id, (string) $field_key ) ) );
	}
}
