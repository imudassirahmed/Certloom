<?php
namespace Certloom\Application\Backup;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Authenticates envelope origin only. It cannot establish target-account ownership or restore readiness. */
final class WholeStateBackupProvenance {
	const CONTEXT = 'certloom-whole-state-provenance-v1';

	public function sign( array $manifest, $key, $installation_id, $blog_id ) {
		$message = $this->message( $manifest, $key, $installation_id, $blog_id );
		if ( is_wp_error( $message ) ) { return $message; }
		$manifest['provenance']['tag'] = hash_hmac( 'sha256', $message, $key );
		return $manifest;
	}

	public function verify( array $manifest, $key, $installation_id, $blog_id ) {
		$message = $this->message( $manifest, $key, $installation_id, $blog_id );
		if ( is_wp_error( $message ) ) { return $message; }
		return hash_equals( hash_hmac( 'sha256', $message, $key ), $manifest['provenance']['tag'] ) ? true : $this->invalid();
	}

	private function message( array $manifest, $key, $installation_id, $blog_id ) {
		if ( ! is_string( $key ) || 32 !== strlen( $key ) || ! is_string( $installation_id ) || 1 !== preg_match( '/^[a-f0-9]{32}$/D', $installation_id ) || ! is_int( $blog_id ) || $blog_id < 1 ) { return $this->invalid(); }
		$format = new WholeStateBackupFormat(); $valid = $format->validate_manifest( $manifest );
		if ( is_wp_error( $valid ) || ! hash_equals( $installation_id, $valid['source']['installation_id'] ) || $blog_id !== $valid['source']['blog_id'] ) { return $this->invalid(); }
		// A fixed tag placeholder avoids self-reference while binding every other manifest field.
		$valid['provenance']['tag'] = str_repeat( '0', 64 ); $bytes = $format->encode_manifest( $valid );
		return is_wp_error( $bytes ) ? $bytes : self::CONTEXT . "\n" . $bytes;
	}

	private function invalid() { return new \WP_Error( 'certloom_whole_backup_provenance_invalid', __( 'This whole-state backup could not be authenticated with this installation\'s recovery key. Account and object ownership still require review.', 'certloom' ) ); }
}
