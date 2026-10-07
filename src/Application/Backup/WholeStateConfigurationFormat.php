<?php
namespace Certloom\Application\Backup;

use Certloom\Application\Rendering\CanonicalJson;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Configuration component transport. Semantic and ownership preflight remain mandatory before any import. */
final class WholeStateConfigurationFormat {
	const FORMAT = 'certloom-whole-state-configuration';
	const VERSION = 1;

	public function from_snapshot( array $snapshot ) {
		$keys = array( 'inventory_version', 'options', 'fingerprint' );
		if ( count( $snapshot ) !== count( $keys ) || array_diff( array_keys( $snapshot ), $keys ) || WholeStateOptionInventory::VERSION !== $snapshot['inventory_version'] || ! is_string( $snapshot['fingerprint'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $snapshot['fingerprint'] ) ) { return $this->invalid(); }
		$records = ( new WholeStateOptionRecord() )->validate_set( $snapshot['options'] ?? null, WholeStateOptionInventory::keys() );
		if ( is_wp_error( $records ) ) { return $records; }
		$fingerprint = ( new WholeStateOptionRecord() )->fingerprint( $records );
		if ( is_wp_error( $fingerprint ) || ! hash_equals( $snapshot['fingerprint'], $fingerprint ) ) { return $this->invalid(); }
		return array( 'format' => self::FORMAT, 'format_version' => self::VERSION, 'inventory_version' => WholeStateOptionInventory::VERSION, 'options' => array_intersect_key( $records, array_flip( WholeStateOptionInventory::keys( 'configuration' ) ) ) );
	}

	public function encode( array $component ) {
		$valid = $this->validate( $component );
		if ( is_wp_error( $valid ) ) { return $valid; }
		try { $bytes = ( new CanonicalJson() )->encode( $valid ) . "\n"; } catch ( \Throwable $error ) { return $this->invalid(); }
		return strlen( $bytes ) <= WholeStateBackupFormat::member_limit( 'data/configuration.json' ) ? $bytes : $this->invalid();
	}

	public function decode( $bytes ) {
		if ( ! is_string( $bytes ) || strlen( $bytes ) > WholeStateBackupFormat::member_limit( 'data/configuration.json' ) ) { return $this->invalid(); }
		$component = json_decode( $bytes, true, 8 );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $component ) ) { return $this->invalid(); }
		$canonical = $this->encode( $component );
		return is_wp_error( $canonical ) || ! hash_equals( $canonical, $bytes ) ? $this->invalid() : $component;
	}

	private function validate( array $component ) {
		$keys = array( 'format', 'format_version', 'inventory_version', 'options' );
		if ( count( $component ) !== count( $keys ) || array_diff( array_keys( $component ), $keys ) || self::FORMAT !== $component['format'] || self::VERSION !== $component['format_version'] || WholeStateOptionInventory::VERSION !== $component['inventory_version'] ) { return $this->invalid(); }
		$records = ( new WholeStateOptionRecord() )->validate_set( $component['options'], WholeStateOptionInventory::keys( 'configuration' ) );
		return is_wp_error( $records ) ? $records : $component;
	}

	private function invalid() { return new \WP_Error( 'certloom_whole_configuration_invalid', __( 'This whole-state backup has incomplete or unsupported configuration storage.', 'certloom' ) ); }
}
