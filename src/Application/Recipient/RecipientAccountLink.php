<?php
namespace Certloom\Application\Recipient;

use Certloom\Application\Rendering\CanonicalJson;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Private recipient metadata; it conveys no account ownership on its own. */
final class RecipientAccountLink {
	const KEY = '_certloom_account_link';
	const HISTORY_LIMIT = 20;
	public function revision( array $recipient ) {
		return ( new CanonicalJson() )->hash( $recipient );
	}
	public function normalize( $value ) {
		if ( ! is_array( $value ) || array_diff( array_keys( $value ), array( 'schema_version', 'generation', 'name_authority', 'history', 'restored_account_identity_sha256' ) ) || 1 !== ( $value['schema_version'] ?? null ) || ! is_string( $value['generation'] ?? null ) || 1 !== preg_match( '/^[a-f0-9]{32}$/D', $value['generation'] ) || ! in_array( $value['name_authority'] ?? null, array( 'wordpress', 'organization' ), true ) || ! is_array( $value['history'] ?? null ) || count( $value['history'] ) > self::HISTORY_LIMIT || ( array_key_exists( 'restored_account_identity_sha256', $value ) && ( ! is_string( $value['restored_account_identity_sha256'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value['restored_account_identity_sha256'] ) ) ) ) { return $this->invalid(); }
		foreach ( $value['history'] as $event ) {
			if ( ! is_array( $event ) || count( $event ) !== 3 || array_diff( array_keys( $event ), array( 'action', 'at', 'actor_user_id' ) ) || ! in_array( $event['action'] ?? null, array( 'linked', 'unlinked', 'account_deleted' ), true ) || ! is_int( $event['actor_user_id'] ?? null ) || $event['actor_user_id'] < 0 || ! is_string( $event['at'] ?? null ) ) { return $this->invalid(); }
			$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $event['at'], new \DateTimeZone( 'UTC' ) );
			if ( ! $date || $date->format( 'Y-m-d H:i:s' ) !== $event['at'] ) { return $this->invalid(); }
		}
		return $value;
	}
	public function changed( array $custom, $action, $actor_id ) {
		$history = array();
		if ( array_key_exists( self::KEY, $custom ) ) {
			$current = $this->normalize( $custom[ self::KEY ] );
			if ( is_wp_error( $current ) ) { return $current; }
			$history = $current['history'];
		}
		$history[] = array( 'action' => $action, 'at' => current_time( 'mysql', true ), 'actor_user_id' => (int) $actor_id );
		try { $generation = bin2hex( random_bytes( 16 ) ); } catch ( \Throwable $error ) { return $this->invalid(); }
		$custom[ self::KEY ] = array( 'schema_version' => 1, 'generation' => $generation, 'name_authority' => 'organization', 'history' => array_slice( $history, -self::HISTORY_LIMIT ) );
		return $custom;
	}
	private function invalid() { return new \WP_Error( 'certloom_recipient_account_metadata_invalid', __( 'The account connection history needs review before changing this recipient.', 'certloom' ) ); }
}
