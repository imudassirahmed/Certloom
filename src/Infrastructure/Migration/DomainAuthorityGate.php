<?php
namespace Certloom\Infrastructure\Migration;

use Certloom\Application\Migration\DomainReleaseManifest;
use Certloom\Application\Rendering\RuntimePayloadFingerprint;

/**
 * Fail-closed runtime gate for normalized-domain release authority.
 * No WordPress option, request, or legacy constant can enable this gate.
 */
final class DomainAuthorityGate {
	const DEFAULT_RELATIVE_PATH = 'resources/domain/accepted.json';
	const MAX_MANIFEST_BYTES = 65536;

	private $manifest_contract;
	private $plugin_root;
	private $plugin_version;
	private $relative_path;
	private $runtime_payloads;
	private $runtime_payload_cache;

	public function __construct( ?DomainReleaseManifest $manifest_contract = null, $plugin_root = null, $plugin_version = null, $relative_path = null, ?RuntimePayloadFingerprint $runtime_payloads = null ) {
		$this->manifest_contract = $manifest_contract ?: new DomainReleaseManifest();
		$this->plugin_root = rtrim( (string) ( null === $plugin_root && defined( 'CERTLOOM_PATH' ) ? CERTLOOM_PATH : $plugin_root ), '/\\' );
		$this->plugin_version = (string) ( null === $plugin_version && defined( 'CERTLOOM_VERSION' ) ? CERTLOOM_VERSION : $plugin_version );
		$this->relative_path = ltrim( (string) ( null === $relative_path ? self::DEFAULT_RELATIVE_PATH : $relative_path ), '/\\' );
		$this->runtime_payloads = $runtime_payloads ?: new RuntimePayloadFingerprint();
		$this->runtime_payload_cache = null;
	}

	/** @return array<string,mixed> */
	public function manifest_status() {
		try { $manifest = $this->load_manifest(); }
		catch ( \Throwable $error ) { return array( 'accepted'=>false, 'reason'=>'manifest_invalid', 'message'=>$error->getMessage(), 'manifest'=>null, 'manifest_sha256'=>'' ); }
		if ( null === $manifest ) { return array( 'accepted'=>false, 'reason'=>'manifest_missing', 'message'=>'No accepted normalized-domain release manifest is bundled with this Certloom release.', 'manifest'=>null, 'manifest_sha256'=>'' ); }
		$hash = $this->manifest_contract->hash( $manifest );
		if ( '' === $this->plugin_version || ! hash_equals( (string) $manifest['plugin_version'], $this->plugin_version ) ) { return array( 'accepted'=>false, 'reason'=>'plugin_version_mismatch', 'message'=>'Normalized-domain release authority belongs to a different Certloom version.', 'manifest'=>$manifest, 'manifest_sha256'=>$hash ); }
		try { $payload = $this->runtime_payload(); }
		catch ( \Throwable $error ) { return array( 'accepted'=>false, 'reason'=>'runtime_payload_invalid', 'message'=>$error->getMessage(), 'manifest'=>$manifest, 'manifest_sha256'=>$hash ); }
		if ( $payload !== $manifest['runtime_payload'] ) { return array( 'accepted'=>false, 'reason'=>'runtime_payload_mismatch', 'message'=>'Normalized-domain release authority belongs to different Certloom production bytes.', 'manifest'=>$manifest, 'manifest_sha256'=>$hash, 'runtime_payload'=>$payload ); }
		return array( 'accepted'=>true, 'reason'=>'manifest_valid', 'message'=>'Accepted normalized-domain release authority is valid for this exact Certloom production payload.', 'manifest'=>$manifest, 'manifest_sha256'=>$hash, 'runtime_payload'=>$payload );
	}

	/** @return array<string,mixed> */
	public function scope_status( $scope ) {
		$scope = strtolower( trim( (string) $scope ) );
		if ( ! in_array( $scope, array( 'write_paths', 'admin_preview', 'data_tools' ), true ) ) { return array( 'accepted'=>false, 'reason'=>'scope_invalid', 'message'=>'Unknown normalized-domain authority scope.', 'manifest'=>null, 'manifest_sha256'=>'' ); }
		$status = $this->manifest_status();
		if ( empty( $status['accepted'] ) || ! is_array( $status['manifest'] ) ) return $status;
		if ( true !== ( $status['manifest']['authority'][ $scope ] ?? false ) ) { $status['accepted']=false;$status['reason']='scope_not_authorized';$status['message']='This Certloom release does not authorize the requested normalized-domain scope.';return $status; }
		$status['reason'] = 'scope_authorized';
		$status['message'] = 'This exact Certloom release authorizes the requested normalized-domain scope.';
		$status['scope'] = $scope;
		return $status;
	}

	public function allows( $scope ) { $status=$this->scope_status($scope); return !empty($status['accepted']) && 'scope_authorized' === (string)$status['reason']; }

	/** @return array<string,mixed> */
	private function runtime_payload() { if ( is_array($this->runtime_payload_cache) ) return $this->runtime_payload_cache; $this->runtime_payload_cache=$this->runtime_payloads->fingerprint($this->plugin_root); return $this->runtime_payload_cache; }

	/** @return array<string,mixed>|null */
	private function load_manifest() {
		if ( '' === $this->plugin_root || '' === $this->relative_path ) return null;
		$root = realpath( $this->plugin_root );
		if ( false === $root || ! is_dir($root) ) throw new \RuntimeException('Certloom plugin root is unavailable for normalized-domain authority inspection.');
		$path = $this->plugin_root . DIRECTORY_SEPARATOR . str_replace( array('/','\\'), DIRECTORY_SEPARATOR, $this->relative_path );
		if ( ! file_exists($path) ) return null;
		if ( is_link($path) ) throw new \RuntimeException('Normalized-domain authority manifest may not be a symbolic link.');
		$real = realpath($path); $prefix=rtrim($root,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
		if ( false===$real || 0!==strpos($real,$prefix) || !is_file($real) || !is_readable($real) ) throw new \RuntimeException('Normalized-domain authority manifest must be a readable regular file inside the Certloom plugin root.');
		$size=filesize($real); if(false===$size||$size<2||$size>self::MAX_MANIFEST_BYTES)throw new \RuntimeException('Normalized-domain authority manifest size is invalid.');
		$bytes=file_get_contents($real); if(!is_string($bytes)||strlen($bytes)!==$size)throw new \RuntimeException('Normalized-domain authority manifest could not be read exactly.');
		$decoded=json_decode($bytes,true); if(!is_array($decoded)||JSON_ERROR_NONE!==json_last_error())throw new \RuntimeException('Normalized-domain authority manifest JSON is invalid.');
		return $this->manifest_contract->normalize($decoded);
	}
}
