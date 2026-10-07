<?php
namespace Certloom\Application\Backup;

use Certloom\Application\Rendering\CanonicalJson;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Outer recovery envelope. Existing domain/legacy formats and hashes stay intact. */
final class WholeStateBackupFormat {
	const FORMAT = 'certloom-whole-state-backup';
	const VERSION = 1;
	const MAX_ARCHIVE_BYTES = 1073741824;
	const MAX_TOTAL_BYTES = 1073741824;
	const MAX_MEMBER_BYTES = 536870912;
	const MAX_MANIFEST_BYTES = 524288;
	const MAX_MEMBERS = 2054;

	public static function scope( $references_version = 2 ) {
		return array( 'domain' => 5, 'legacy' => 4, 'configuration' => 1, 'authoring' => 1, 'deliveries' => 1, 'references' => $references_version, 'assets' => $references_version );
	}

	public static function required_members() {
		return array( 'data/authoring.jsonl', 'data/configuration.json', 'data/deliveries.jsonl', 'data/domain.cvbackup', 'data/legacy.cvbackup', 'data/references.jsonl' );
	}

	public static function member_limit( $name ) {
		if ( 'manifest.json' === $name ) { return self::MAX_MANIFEST_BYTES; }
		if ( in_array( $name, array( 'data/configuration.json', 'data/authoring.jsonl' ), true ) ) { return 8388608; }
		if ( is_string( $name ) && 0 === strpos( $name, 'assets/' ) ) { return 67108864; }
		return self::MAX_MEMBER_BYTES;
	}

	public static function member_name( $name ) {
		return is_string( $name ) && ( in_array( $name, self::required_members(), true ) || 1 === preg_match( '/^assets\/[a-f0-9]{64}$/D', $name ) );
	}

	public function validate_manifest( $manifest ) {
		if ( ! is_array( $manifest ) || ! $this->keys( $manifest, array( 'format', 'format_version', 'created_at', 'plugin_version', 'scope', 'source', 'provenance', 'members' ) ) || self::FORMAT !== $manifest['format'] || self::VERSION !== $manifest['format_version'] || ! is_string( $manifest['plugin_version'] ) || strlen( $manifest['plugin_version'] ) > 80 || 1 !== preg_match( '/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+.][a-zA-Z0-9.-]+)?$/D', $manifest['plugin_version'] ) ) { return $this->invalid(); }
		$date = is_string( $manifest['created_at'] ) ? \DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:s\Z', $manifest['created_at'], new \DateTimeZone( 'UTC' ) ) : false;
		if ( ! $date || $date->format( 'Y-m-d\TH:i:s\Z' ) !== $manifest['created_at'] || ! is_array( $manifest['scope'] ) || ! $this->keys( $manifest['scope'], array_keys( self::scope() ) ) ) { return $this->invalid(); }
		if ( ! in_array( $manifest['scope']['references'], array( 1, 2 ), true ) ) { return $this->invalid(); }
		foreach ( self::scope( $manifest['scope']['references'] ) as $key => $version ) { if ( $manifest['scope'][ $key ] !== $version ) { return $this->invalid(); } }
		$source = $manifest['source'];
		if ( ! is_array( $source ) || ! $this->keys( $source, array( 'installation_id', 'export_id', 'blog_id', 'home_url', 'generation' ) ) || ! $this->hex( $source['installation_id'], 32 ) || ! $this->hex( $source['export_id'], 32 ) || ! is_int( $source['blog_id'] ) || $source['blog_id'] < 1 || ! is_string( $source['home_url'] ) || strlen( $source['home_url'] ) > 2048 || ! is_string( $source['generation'] ) || '' === $source['generation'] || strlen( $source['generation'] ) > 128 || 1 !== preg_match( '/^[a-zA-Z0-9.|_-]+$/D', $source['generation'] ) ) { return $this->invalid(); }
		$url = parse_url( $source['home_url'] );
		if ( ! is_array( $url ) || ! in_array( $url['scheme'] ?? null, array( 'http', 'https' ), true ) || empty( $url['host'] ) || array_intersect( array_keys( $url ), array( 'user', 'pass', 'query', 'fragment' ) ) || preg_match( '/[\x00-\x20\x7f]/', $source['home_url'] ) ) { return $this->invalid(); }
		$provenance = $manifest['provenance'];
		if ( ! is_array( $provenance ) || ! $this->keys( $provenance, array( 'algorithm', 'tag' ) ) || 'site-hmac-sha256' !== $provenance['algorithm'] || ! $this->hex( $provenance['tag'], 64 ) ) { return $this->invalid(); }
		$members = $manifest['members'];
		if ( ! is_array( $members ) || count( $members ) < count( self::required_members() ) || count( $members ) > self::MAX_MEMBERS || array_diff( self::required_members(), array_keys( $members ) ) ) { return $this->invalid(); }
		$total = 0;
		foreach ( $members as $name => $member ) {
			if ( ! self::member_name( $name ) || ! is_array( $member ) || ! $this->keys( $member, array( 'bytes', 'sha256' ) ) || ! is_int( $member['bytes'] ) || $member['bytes'] < 1 || $member['bytes'] > self::member_limit( $name ) || ! $this->hex( $member['sha256'], 64 ) || $member['bytes'] > self::MAX_TOTAL_BYTES - $total ) { return $this->invalid(); }
			if ( 0 === strpos( $name, 'assets/' ) && substr( $name, 7 ) !== $member['sha256'] ) { return $this->invalid(); }
			$total += $member['bytes'];
		}
		return $manifest;
	}

	/** Canonical bytes reject duplicate JSON keys and alternative representations. */
	public function encode_manifest( array $manifest ) {
		$valid = $this->validate_manifest( $manifest ); if ( is_wp_error( $valid ) ) { return $valid; }
		try { $bytes = ( new CanonicalJson() )->encode( $valid ) . "\n"; } catch ( \Throwable $error ) { return $this->invalid(); }
		return strlen( $bytes ) <= self::MAX_MANIFEST_BYTES ? $bytes : $this->invalid();
	}

	public function decode_manifest( $bytes ) {
		if ( ! is_string( $bytes ) || strlen( $bytes ) > self::MAX_MANIFEST_BYTES ) { return $this->invalid(); }
		$manifest = json_decode( $bytes, true, 16 );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $manifest ) ) { return $this->invalid(); }
		$canonical = $this->encode_manifest( $manifest );
		return is_wp_error( $canonical ) || ! hash_equals( $canonical, $bytes ) ? $this->invalid() : $manifest;
	}

	private function keys( array $value, array $keys ) { return count( $value ) === count( $keys ) && ! array_diff( array_keys( $value ), $keys ); }
	private function hex( $value, $length ) { return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{' . $length . '}$/D', $value ); }
	private function invalid() { return new \WP_Error( 'certloom_whole_backup_manifest_invalid', __( 'This whole-state backup has an invalid or unsupported scope manifest.', 'certloom' ) ); }
}
