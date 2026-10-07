<?php
namespace Certloom\Infrastructure\Backup;

use Certloom\Application\Backup\WholeStateBackupProvenance;
use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Infrastructure\Data\DatabaseOptions;
use Certloom\Infrastructure\Database\Transaction;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Local, sealed recovery root. Never captured/imported and never created by a read or verification. */
final class WholeStateProvenanceRepository {
	const OPTION = 'certloom_recovery_provenance_v1';
	const PURPOSE = 'certloom-recovery-root-v1';
	const MAX_BYTES = 4096;

	/** Explicit capture setup only; scheduled capture uses an already provisioned root. */
	public function ensure( $actor_id ) {
		if ( ! is_int( $actor_id ) || $actor_id < 1 || get_current_user_id() !== $actor_id || ! current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_DATA_TOOLS ) ) { return new \WP_Error( 'certloom_recovery_root_forbidden', __( 'You do not have permission to prepare Certloom recovery.', 'certloom' ), array( 'status' => 403 ) ); }
		$result = ( new Transaction() )->run( function () {
			global $wpdb;
			$wpdb->last_error = ''; $row = $wpdb->get_row( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name=%s FOR UPDATE', $wpdb->options, self::OPTION ), ARRAY_A );
			if ( '' !== (string) $wpdb->last_error ) { return $this->invalid(); }
			if ( null !== $row ) { return $this->identity(); }
			try { $id = bin2hex( random_bytes( 16 ) ); $key_hex = bin2hex( random_bytes( 32 ) ); } catch ( \Throwable $error ) { return $this->invalid(); }
			$blog_id = (int) get_current_blog_id();
			$sealed = SecretVault::seal( array( 'purpose' => self::PURPOSE, 'installation_id' => $id, 'blog_id' => $blog_id, 'key_hex' => $key_hex ) );
			if ( is_wp_error( $sealed ) ) { return $this->invalid(); }
			try { $storage = ( new CanonicalJson() )->encode( array( 'schema_version' => 1, 'installation_id' => $id, 'blog_id' => $blog_id, 'sealed_key' => $sealed ) ); } catch ( \Throwable $error ) { return $this->invalid(); }
			$wpdb->last_error = ''; $inserted = $wpdb->insert( $wpdb->options, array( 'option_name' => self::OPTION, 'option_value' => $storage, 'autoload' => 'no' ), array( '%s', '%s', '%s' ) );
			if ( 1 !== $inserted || '' !== (string) $wpdb->last_error ) { return $this->invalid(); }
			$saved = $this->read();
			if ( is_wp_error( $saved ) || ! hash_equals( $id, $saved['installation_id'] ) || ! hash_equals( $key_hex, bin2hex( $saved['key'] ) ) ) { return $this->invalid(); }
			return array( 'installation_id' => $id, 'blog_id' => $blog_id );
		} );
		DatabaseOptions::invalidate( array( self::OPTION ) );
		return $result;
	}

	public function identity() {
		$root = $this->read();
		return is_wp_error( $root ) ? $root : array( 'installation_id' => $root['installation_id'], 'blog_id' => $root['blog_id'] );
	}

	public function sign( array $manifest ) {
		$root = $this->read(); if ( is_wp_error( $root ) ) { return $root; }
		return ( new WholeStateBackupProvenance() )->sign( $manifest, $root['key'], $root['installation_id'], $root['blog_id'] );
	}

	/** Even authentic provenance grants neither object/account mappings nor permission to resume work. */
	public function verify( array $manifest ) {
		$root = $this->read(); if ( is_wp_error( $root ) ) { return $root; }
		$valid = ( new WholeStateBackupProvenance() )->verify( $manifest, $root['key'], $root['installation_id'], $root['blog_id'] );
		return is_wp_error( $valid ) ? $valid : array( 'provenance_verified' => true, 'ownership_verified' => false, 'restore_ready' => false, 'installation_id' => $root['installation_id'], 'blog_id' => $root['blog_id'] );
	}

	/** Raw JSON only: an archive or option filter cannot inject a replacement root or serialized object. */
	private function read() {
		global $wpdb;
		$wpdb->last_error = ''; $row = $wpdb->get_row( $wpdb->prepare( 'SELECT option_name, CASE WHEN OCTET_LENGTH(option_value)<=%d THEN option_value ELSE NULL END AS storage FROM %i WHERE option_name=%s', self::MAX_BYTES, $wpdb->options, self::OPTION ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error ) { return $this->invalid(); }
		if ( null === $row ) { return new \WP_Error( 'certloom_recovery_root_missing', __( 'This installation has no recovery key for authenticating this backup. Cross-site account and object references require explicit review.', 'certloom' ) ); }
		if ( ! is_array( $row ) || self::OPTION !== ( $row['option_name'] ?? null ) || ! is_string( $row['storage'] ?? null ) ) { return $this->invalid(); }
		$root = json_decode( $row['storage'], true, 8 ); $keys = array( 'schema_version', 'installation_id', 'blog_id', 'sealed_key' );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $root ) || count( $root ) !== count( $keys ) || array_diff( array_keys( $root ), $keys ) || 1 !== $root['schema_version'] || ! is_string( $root['installation_id'] ) || 1 !== preg_match( '/^[a-f0-9]{32}$/D', $root['installation_id'] ) || ! is_int( $root['blog_id'] ) || $root['blog_id'] < 1 || (int) get_current_blog_id() !== $root['blog_id'] || ! is_string( $root['sealed_key'] ) || '' === $root['sealed_key'] ) { return $this->invalid(); }
		try { $canonical = ( new CanonicalJson() )->encode( $root ); } catch ( \Throwable $error ) { return $this->invalid(); }
		if ( ! hash_equals( $canonical, $row['storage'] ) ) { return $this->invalid(); }
		// wp_salt() can generate missing fallback options. Verification must only use existing material.
		if ( ! $this->salts_ready() ) { return $this->invalid(); }
		try { $secret = SecretVault::open( $root['sealed_key'] ); } catch ( \Throwable $error ) { return $this->invalid(); }
		$keys = array( 'purpose', 'installation_id', 'blog_id', 'key_hex' );
		if ( is_wp_error( $secret ) || ! is_array( $secret ) || count( $secret ) !== count( $keys ) || array_diff( array_keys( $secret ), $keys ) || self::PURPOSE !== $secret['purpose'] || $root['installation_id'] !== $secret['installation_id'] || $root['blog_id'] !== $secret['blog_id'] || ! is_string( $secret['key_hex'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $secret['key_hex'] ) ) { return $this->invalid(); }
		return array( 'installation_id' => $root['installation_id'], 'blog_id' => $root['blog_id'], 'key' => hex2bin( $secret['key_hex'] ) );
	}

	private function salts_ready() {
		$counts = array();
		foreach ( array( 'AUTH', 'SECURE_AUTH', 'LOGGED_IN', 'NONCE', 'SECRET' ) as $scheme ) {
			foreach ( array( 'KEY', 'SALT' ) as $type ) {
				$name = $scheme . '_' . $type;
				if ( defined( $name ) ) { $value = constant( $name ); if ( ! is_string( $value ) ) { return false; } $counts[ $value ] = ( $counts[ $value ] ?? 0 ) + 1; }
			}
		}
		$counts['put your unique phrase here'] = 2; $counts[ __( 'put your unique phrase here' ) ] = 2;
		$constant = static function ( $name ) use ( $counts ) { if ( ! defined( $name ) ) { return null; } $value = constant( $name ); return is_string( $value ) && strlen( $value ) >= 32 && 1 === ( $counts[ $value ] ?? 0 ) ? $value : null; };
		foreach ( array( 'auth', 'secure_auth' ) as $scheme ) {
			foreach ( array( 'key', 'salt' ) as $type ) {
				$value = $constant( strtoupper( $scheme . '_' . $type ) );
				if ( null === $value && ( 'key' === $type || 'auth' === $scheme ) ) { $value = $constant( 'SECRET_' . strtoupper( $type ) ); }
				if ( null === $value ) { $value = get_site_option( $scheme . '_' . $type, false ); }
				if ( ! is_string( $value ) || strlen( $value ) < 32 ) { return false; }
			}
		}
		return true;
	}

	private function invalid() { return new \WP_Error( 'certloom_recovery_root_invalid', __( 'Certloom could not verify its local recovery key. It has not replaced the key or granted restore authority.', 'certloom' ) ); }
}
