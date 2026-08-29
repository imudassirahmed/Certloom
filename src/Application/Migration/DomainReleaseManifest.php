<?php
namespace Certloom\Application\Migration;

use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Application\Rendering\RuntimePayloadFingerprint;
use InvalidArgumentException;

/** Immutable release authority derived only from a fully imported DBAUTH set. */
final class DomainReleaseManifest {
	const SCHEMA = 'certloom-domain-release-authority';
	const VERSION = 1;

	private $canonical;
	private $payloads;
	public function __construct( ?CanonicalJson $canonical = null, ?RuntimePayloadFingerprint $payloads = null ) {
		$this->canonical = $canonical ?: new CanonicalJson();
		$this->payloads = $payloads ?: new RuntimePayloadFingerprint();
	}

	/** @return array<string,mixed> */
	public function from_imported_acceptance( array $imported ) {
		if ( ! isset( $imported['bundle'], $imported['index'], $imported['acceptance_sha256'], $imported['index_sha256'] ) || ! is_array( $imported['bundle'] ) || ! is_array( $imported['index'] ) ) {
			throw new InvalidArgumentException( 'Imported normalized-domain acceptance evidence is incomplete.' );
		}
		$bundle = $imported['bundle'];
		return $this->normalize( array(
			'schema' => self::SCHEMA,
			'version' => self::VERSION,
			'plugin_version' => (string) $bundle['plugin_version'],
			'runtime_payload' => $bundle['runtime_payload'],
			'acceptance' => array(
				'bundle_version' => DomainAcceptanceImporter::BUNDLE_VERSION,
				'acceptance_sha256' => (string) $imported['acceptance_sha256'],
				'acceptance_index_sha256' => (string) $imported['index_sha256'],
				'environment_sha256' => (string) $bundle['environment_sha256'],
				'domain_state_sha256' => (string) $bundle['domain_state_sha256'],
			),
			'authority' => array(
				'write_paths' => true,
				'admin_preview' => true,
				'data_tools' => true,
			),
		) );
	}

	/** @return array<string,mixed> */
	public function normalize( array $manifest ) {
		$this->assert_exact_keys( $manifest, array( 'schema', 'version', 'plugin_version', 'runtime_payload', 'acceptance', 'authority' ), 'normalized-domain release manifest' );
		if ( self::SCHEMA !== (string) $manifest['schema'] || ! is_int( $manifest['version'] ) || self::VERSION !== $manifest['version'] ) { throw new InvalidArgumentException( 'Normalized-domain release manifest schema/version is unsupported.' ); }
		$plugin_version = trim( (string) $manifest['plugin_version'] );
		if ( ! preg_match( '/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][A-Za-z0-9.-]+)?$/', $plugin_version ) || strlen( $plugin_version ) > 64 ) { throw new InvalidArgumentException( 'Normalized-domain release manifest Certloom version is invalid.' ); }
		$payload = $this->payloads->normalize( $manifest['runtime_payload'] );
		if ( ! is_array( $manifest['acceptance'] ) ) { throw new InvalidArgumentException( 'Normalized-domain release acceptance identity is required.' ); }
		$this->assert_exact_keys( $manifest['acceptance'], array( 'bundle_version', 'acceptance_sha256', 'acceptance_index_sha256', 'environment_sha256', 'domain_state_sha256' ), 'normalized-domain release acceptance identity' );
		if ( ! is_int( $manifest['acceptance']['bundle_version'] ) || DomainAcceptanceImporter::BUNDLE_VERSION !== $manifest['acceptance']['bundle_version'] ) { throw new InvalidArgumentException( 'Normalized-domain release manifest targets a stale acceptance bundle.' ); }
		$acceptance = array( 'bundle_version' => DomainAcceptanceImporter::BUNDLE_VERSION );
		foreach ( array( 'acceptance_sha256', 'acceptance_index_sha256', 'environment_sha256', 'domain_state_sha256' ) as $key ) { $acceptance[ $key ] = $this->sha256( $manifest['acceptance'][ $key ] ); }
		if ( ! is_array( $manifest['authority'] ) ) { throw new InvalidArgumentException( 'Normalized-domain release authority scope is required.' ); }
		$this->assert_exact_keys( $manifest['authority'], array( 'write_paths', 'admin_preview', 'data_tools' ), 'normalized-domain release authority scope' );
		foreach ( array( 'write_paths', 'admin_preview', 'data_tools' ) as $key ) { if ( true !== $manifest['authority'][ $key ] ) { throw new InvalidArgumentException( 'Normalized-domain release authority must prove all reviewed scopes together.' ); } }
		return array(
			'schema' => self::SCHEMA,
			'version' => self::VERSION,
			'plugin_version' => $plugin_version,
			'runtime_payload' => $payload,
			'acceptance' => $acceptance,
			'authority' => array( 'write_paths' => true, 'admin_preview' => true, 'data_tools' => true ),
		);
	}

	public function hash( array $manifest ) { return $this->canonical->hash( $this->normalize( $manifest ) ); }
	public function encode( array $manifest ) { return $this->canonical->encode( $this->normalize( $manifest ) ); }
	private function sha256( $value ) { $value = strtolower( trim( (string) $value ) ); if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $value ) ) throw new InvalidArgumentException( 'Normalized-domain release authority hash is invalid.' ); return $value; }
	private function assert_exact_keys( array $value, array $keys, $label ) { $a=array_keys($value);sort($a,SORT_STRING);$b=$keys;sort($b,SORT_STRING);if($a!==$b)throw new InvalidArgumentException($label.' has an invalid property set.'); }
}
