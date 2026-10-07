<?php
namespace Certloom\Application\Backup;

use Certloom\Application\Rendering\CanonicalJson;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Authoring option stream; native designs/revisions remain in the unchanged domain component. */
final class WholeStateAuthoringFormat {
	const FORMAT = 'certloom-whole-state-authoring';
	const VERSION = 1;
	const MAX_BYTES = 8388608;
	const MAX_LINE_BYTES = 3145728;

	public function from_snapshots( array $fixed, array $recommendations ) {
		$valid_fixed = ( new WholeStateConfigurationFormat() )->from_snapshot( $fixed );
		if ( is_wp_error( $valid_fixed ) ) { return $valid_fixed; }
		$valid_dynamic = ( new WholeStateTemplateRecommendationInventory() )->validate_snapshot( $recommendations );
		if ( is_wp_error( $valid_dynamic ) ) { return $valid_dynamic; }
		$records = array_intersect_key( $fixed['options'], array_flip( WholeStateOptionInventory::keys( 'authoring' ) ) ) + $recommendations['options'];
		$valid = $this->validate_records( $records );
		return is_wp_error( $valid ) ? $valid : $records;
	}

	public function encode( array $records ) {
		$valid = $this->validate_records( $records );
		if ( is_wp_error( $valid ) ) { return $valid; }
		ksort( $records, SORT_STRING );
		$header = array( 'type' => 'manifest', 'format' => self::FORMAT, 'format_version' => self::VERSION, 'inventory_version' => WholeStateOptionInventory::VERSION, 'option_count' => count( $records ) );
		$manifest = $this->line( $header );
		if ( is_wp_error( $manifest ) ) { return $manifest; }
		$lines = array( $manifest ); $length = strlen( $manifest );
		foreach ( $records as $name => $record ) {
			$line = $this->line( array( 'type' => 'option', 'name' => $name, 'data' => $record ) );
			if ( is_wp_error( $line ) || strlen( $line ) > self::MAX_BYTES - $length ) { return $this->invalid(); }
			$lines[] = $line; $length += strlen( $line );
		}
		$fingerprint = ( new WholeStateOptionRecord() )->fingerprint_set( $records, array_keys( $records ) );
		if ( is_wp_error( $fingerprint ) ) { return $fingerprint; }
		$footer = $this->line( array( 'type' => 'integrity', 'option_count' => count( $records ), 'fingerprint' => $fingerprint ) );
		if ( is_wp_error( $footer ) || strlen( $footer ) > self::MAX_BYTES - $length ) { return $this->invalid(); }
		$lines[] = $footer;
		return implode( '', $lines );
	}

	public function decode( $bytes ) {
		if ( ! is_string( $bytes ) || '' === $bytes || strlen( $bytes ) > self::MAX_BYTES || "\n" !== substr( $bytes, -1 ) ) { return $this->invalid(); }
		$lines = explode( "\n", substr( $bytes, 0, -1 ) );
		if ( count( $lines ) < 4 || count( $lines ) > WholeStateTemplateRecommendationInventory::MAX_OPTIONS + 4 ) { return $this->invalid(); }
		$header = $this->decode_line( array_shift( $lines ) ); $footer = $this->decode_line( array_pop( $lines ) );
		if ( is_wp_error( $header ) || is_wp_error( $footer ) || ! $this->keys( $header, array( 'type', 'format', 'format_version', 'inventory_version', 'option_count' ) ) || 'manifest' !== $header['type'] || self::FORMAT !== $header['format'] || self::VERSION !== $header['format_version'] || WholeStateOptionInventory::VERSION !== $header['inventory_version'] || ! is_int( $header['option_count'] ) || count( $lines ) !== $header['option_count'] || ! $this->keys( $footer, array( 'type', 'option_count', 'fingerprint' ) ) || 'integrity' !== $footer['type'] || $footer['option_count'] !== $header['option_count'] || ! is_string( $footer['fingerprint'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $footer['fingerprint'] ) ) { return $this->invalid(); }
		$records = array(); $previous = '';
		foreach ( $lines as $line ) {
			$row = $this->decode_line( $line );
			if ( is_wp_error( $row ) || ! $this->keys( $row, array( 'type', 'name', 'data' ) ) || 'option' !== $row['type'] || ! is_string( $row['name'] ) || strcmp( $previous, $row['name'] ) >= 0 ) { return $this->invalid(); }
			$records[ $row['name'] ] = $row['data']; $previous = $row['name'];
		}
		$valid = $this->validate_records( $records );
		if ( is_wp_error( $valid ) ) { return $valid; }
		$fingerprint = ( new WholeStateOptionRecord() )->fingerprint_set( $records, array_keys( $records ) );
		return is_wp_error( $fingerprint ) || ! hash_equals( $footer['fingerprint'], $fingerprint ) ? $this->invalid() : $records;
	}

	private function validate_records( array $records ) {
		$fixed = WholeStateOptionInventory::keys( 'authoring' );
		if ( array_diff( $fixed, array_keys( $records ) ) || count( $records ) > WholeStateTemplateRecommendationInventory::MAX_OPTIONS + count( $fixed ) ) { return $this->invalid(); }
		$dynamic = array_diff_key( $records, array_flip( $fixed ) );
		$valid = ( new WholeStateTemplateRecommendationInventory() )->validate_records( $dynamic );
		return is_wp_error( $valid ) ? $valid : ( new WholeStateOptionRecord() )->validate_set( $records, array_keys( $records ) );
	}

	private function line( array $row ) {
		try { $bytes = ( new CanonicalJson() )->encode( $row ) . "\n"; } catch ( \Throwable $error ) { return $this->invalid(); }
		return strlen( $bytes ) <= self::MAX_LINE_BYTES ? $bytes : $this->invalid();
	}
	private function decode_line( $line ) {
		if ( strlen( $line ) + 1 > self::MAX_LINE_BYTES ) { return $this->invalid(); }
		$row = json_decode( $line, true, 8 );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $row ) ) { return $this->invalid(); }
		$canonical = $this->line( $row );
		return is_wp_error( $canonical ) || ! hash_equals( $canonical, $line . "\n" ) ? $this->invalid() : $row;
	}
	private function keys( array $row, array $keys ) { return count( $row ) === count( $keys ) && ! array_diff( array_keys( $row ), $keys ); }
	private function invalid() { return new \WP_Error( 'certloom_whole_authoring_invalid', __( 'This whole-state backup has incomplete or unsupported certificate authoring storage.', 'certloom' ) ); }
}
