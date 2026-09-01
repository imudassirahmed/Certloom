<?php
namespace Certloom\Infrastructure\Rendering;

use Certloom\Application\Rendering\RendererRegistry;
use Certloom\Application\Contract\ArtifactAuthorityGateInterface;
use Certloom\Application\Rendering\RendererReleaseManifest;
use Certloom\Application\Rendering\RuntimePayloadFingerprint;

/**
 * Fail-closed runtime gate between external PDF-04 proof and product authority.
 * Authority is derived from a release-bundled immutable manifest plus the exact
 * registered renderer identities; no WordPress option or request parameter can
 * enable it.
 */
final class RendererAuthorityGate implements ArtifactAuthorityGateInterface {
	const DEFAULT_RELATIVE_PATH = 'resources/renderer/accepted.json';
	const MAX_MANIFEST_BYTES = 65536;

	private $manifest_contract;
	private $plugin_root;
	private $plugin_version;
	private $relative_path;
	private $capabilities;
	private $runtime_payloads;
	private $runtime_payload_cache;

	public function __construct( ?RendererReleaseManifest $manifest_contract = null, $plugin_root = null, $plugin_version = null, $relative_path = null, ?RendererCapabilityDetector $capabilities = null, ?RuntimePayloadFingerprint $runtime_payloads = null ) {
		$this->manifest_contract = $manifest_contract ?: new RendererReleaseManifest();
		$this->plugin_root = rtrim( (string) ( null === $plugin_root && defined( 'CERTLOOM_PATH' ) ? CERTLOOM_PATH : $plugin_root ), '/\\' );
		$this->plugin_version = (string) ( null === $plugin_version && defined( 'CERTLOOM_VERSION' ) ? CERTLOOM_VERSION : $plugin_version );
		$this->relative_path = ltrim( (string) ( null === $relative_path ? self::DEFAULT_RELATIVE_PATH : $relative_path ), '/\\' );
		$this->capabilities = $capabilities ?: new RendererCapabilityDetector();
		$this->runtime_payloads = $runtime_payloads ?: new RuntimePayloadFingerprint();
		$this->runtime_payload_cache = null;
	}

	/** @return array<string,mixed> */
	public function manifest_status() {
		if ( $this->qa_ungated() ) { return array( 'accepted' => true, 'reason' => 'qa_ungated', 'message' => 'QA staging derivative bypasses renderer release authority in memory; no production acceptance manifest is present.', 'manifest' => array( 'authority' => array( 'template_publication' => true, 'artifact_formats' => array( 'pdf', 'png', 'jpeg' ) ), 'renderers' => array() ), 'manifest_sha256' => '' ); }
		try {
			$manifest = $this->load_manifest();
		} catch ( \Throwable $error ) {
			return array( 'accepted' => false, 'reason' => 'manifest_invalid', 'message' => $error->getMessage(), 'manifest' => null, 'manifest_sha256' => '' );
		}
		if ( null === $manifest ) {
			return array( 'accepted' => false, 'reason' => 'manifest_missing', 'message' => 'No accepted renderer release manifest is bundled with this Certloom release.', 'manifest' => null, 'manifest_sha256' => '' );
		}
		if ( '' === $this->plugin_version || ! hash_equals( (string) $manifest['plugin_version'], $this->plugin_version ) ) {
			return array( 'accepted' => false, 'reason' => 'plugin_version_mismatch', 'message' => 'Renderer release authority belongs to a different Certloom version.', 'manifest' => $manifest, 'manifest_sha256' => $this->manifest_contract->hash( $manifest ) );
		}
		try {
			$runtime_payload = $this->runtime_payload();
		} catch ( \Throwable $error ) {
			return array( 'accepted' => false, 'reason' => 'runtime_payload_invalid', 'message' => $error->getMessage(), 'manifest' => $manifest, 'manifest_sha256' => $this->manifest_contract->hash( $manifest ) );
		}
		if ( $runtime_payload !== $manifest['runtime_payload'] ) {
			return array( 'accepted' => false, 'reason' => 'runtime_payload_mismatch', 'message' => 'Renderer release authority belongs to different Certloom production bytes.', 'manifest' => $manifest, 'manifest_sha256' => $this->manifest_contract->hash( $manifest ), 'runtime_payload' => $runtime_payload );
		}
		return array( 'accepted' => true, 'reason' => 'manifest_valid', 'message' => 'Accepted renderer release manifest is valid for this exact Certloom production payload.', 'manifest' => $manifest, 'manifest_sha256' => $this->manifest_contract->hash( $manifest ), 'runtime_payload' => $runtime_payload );
	}

	/** @return array<string,mixed> */
	public function publication_status() {
		$status = $this->manifest_status();
		if ( empty( $status['accepted'] ) || ! is_array( $status['manifest'] ) ) { return $status; }
		if ( true !== ( $status['manifest']['authority']['template_publication'] ?? false ) ) {
			return $this->failed_runtime( $status, 'template_publication_not_authorized', 'This Certloom release does not authorize Template publication.' );
		}
		$status['reason'] = 'publication_authorized';
		$status['message'] = 'This Certloom release contains accepted production-renderer authority for Template publication.';
		return $status;
	}

	/** @return array<string,mixed> */
	public function artifact_status( $format, RendererRegistry $registry ) {
		$format = strtolower( trim( (string) $format ) );
		if ( $this->qa_ungated() ) { $ok = in_array( $format, array( 'pdf', 'png', 'jpeg' ), true ); return array( 'accepted' => $ok, 'reason' => $ok ? 'artifact_authorized' : 'artifact_format_not_authorized', 'message' => 'QA staging derivative bypasses renderer release authority in memory.', 'manifest' => null, 'manifest_sha256' => '', 'format' => $format ); }
		$status = $this->manifest_status();
		if ( empty( $status['accepted'] ) || ! is_array( $status['manifest'] ) ) { return $status; }
		$allowed = array_values( (array) ( $status['manifest']['authority']['artifact_formats'] ?? array() ) );
		if ( ! in_array( $format, $allowed, true ) || ! in_array( $format, array( 'pdf', 'png', 'jpeg' ), true ) ) {
			return $this->failed_runtime( $status, 'artifact_format_not_authorized', 'This Certloom release does not authorize the requested artifact format.' );
		}
		if ( ! isset( $status['manifest']['renderers'][ $format ] ) || ! is_array( $status['manifest']['renderers'][ $format ] ) ) {
			return $this->failed_runtime( $status, 'renderer_authority_missing', 'This Certloom release does not bind an accepted renderer for ' . strtoupper( $format ) . '.' );
		}
		return $this->inspect_authorized_renderer( $format, $registry, $status );
	}

	/** @return array<string,mixed> */
	public function runtime_status( RendererRegistry $registry ) {
		if ( $this->qa_ungated() ) { return array( 'accepted' => true, 'reason' => 'runtime_authorized', 'message' => 'QA staging derivative bypasses renderer release authority in memory.', 'manifest' => null, 'manifest_sha256' => '' ); }
		$status = $this->manifest_status();
		if ( empty( $status['accepted'] ) || ! is_array( $status['manifest'] ) ) { return $status; }
		foreach ( array_values( (array) ( $status['manifest']['authority']['artifact_formats'] ?? array() ) ) as $format ) {
			$checked = $this->inspect_authorized_renderer( $format, $registry, $status );
			if ( empty( $checked['accepted'] ) ) { return $checked; }
		}
		$status['accepted'] = true;
		$status['reason'] = 'runtime_authorized';
		$status['message'] = 'This Certloom release contains matching accepted renderer candidates and all authorized runtime requirements are available.';
		return $status;
	}

	/** @return bool */
	public function can_publish_templates() {
		$status = $this->publication_status();
		return ! empty( $status['accepted'] ) && 'publication_authorized' === (string) $status['reason'];
	}

	/** @return bool */
	public function can_render( $format, RendererRegistry $registry ) {
		$status = $this->artifact_status( $format, $registry );
		return ! empty( $status['accepted'] ) && 'artifact_authorized' === (string) $status['reason'];
	}

	/** @return array<string,mixed> */
	private function inspect_authorized_renderer( $format, RendererRegistry $registry, array $status ) {
		try {
			$renderer = $registry->for_format( $format );
		} catch ( \Throwable $error ) {
			return $this->failed_runtime( $status, 'renderer_missing', 'Accepted renderer is not registered for ' . strtoupper( $format ) . '.' );
		}
		$expected = $status['manifest']['renderers'][ $format ];
		try {
			$id = (string) $renderer->id();
			$version = (string) $renderer->version();
			$context = (array) $renderer->context();
		} catch ( \Throwable $error ) {
			return $this->failed_runtime( $status, 'renderer_identity_error', 'Accepted renderer identity/context could not be inspected.' );
		}
		$candidate_hash = isset( $context['candidate_manifest_sha256'] ) ? strtolower( trim( (string) $context['candidate_manifest_sha256'] ) ) : '';
		if ( ! hash_equals( (string) $expected['id'], strtolower( trim( $id ) ) ) || ! hash_equals( (string) $expected['version'], trim( $version ) ) || ! hash_equals( (string) $expected['candidate_manifest_sha256'], $candidate_hash ) ) {
			return $this->failed_runtime( $status, 'renderer_identity_mismatch', 'Registered renderer does not match the exact PDF-04 accepted candidate.' );
		}
		$runtime = $this->capabilities->inspect( $renderer );
		if ( empty( $runtime['available'] ) ) {
			return $this->failed_runtime( $status, 'renderer_runtime_unavailable', 'Accepted renderer runtime requirements are unavailable: ' . implode( ', ', (array) $runtime['missing'] ) );
		}
		$status['accepted'] = true;
		$status['reason'] = 'artifact_authorized';
		$status['message'] = 'The exact accepted ' . strtoupper( $format ) . ' renderer is available on this host.';
		$status['format'] = $format;
		return $status;
	}



	/** @return bool */
	private function qa_ungated() { return defined( 'CERTLOOM_QA_UNGATED' ) && true === CERTLOOM_QA_UNGATED; }

	/** @return array<string,mixed> */
	private function runtime_payload() {
		if ( is_array( $this->runtime_payload_cache ) ) { return $this->runtime_payload_cache; }
		$this->runtime_payload_cache = $this->runtime_payloads->fingerprint( $this->plugin_root );
		return $this->runtime_payload_cache;
	}

	/** @return array<string,mixed>|null */
	private function load_manifest() {
		if ( '' === $this->plugin_root || '' === $this->relative_path ) { return null; }
		$root = realpath( $this->plugin_root );
		if ( false === $root || ! is_dir( $root ) ) { throw new \RuntimeException( 'Certloom plugin root is unavailable for renderer authority inspection.' ); }
		$path = $this->plugin_root . DIRECTORY_SEPARATOR . str_replace( array( '/', '\\' ), DIRECTORY_SEPARATOR, $this->relative_path );
		if ( ! file_exists( $path ) ) { return null; }
		if ( is_link( $path ) ) { throw new \RuntimeException( 'Renderer authority manifest may not be a symbolic link.' ); }
		$real = realpath( $path );
		$prefix = rtrim( $root, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
		if ( false === $real || 0 !== strpos( $real, $prefix ) || ! is_file( $real ) || ! is_readable( $real ) ) { throw new \RuntimeException( 'Renderer authority manifest must be a readable regular file inside the Certloom plugin root.' ); }
		$size = filesize( $real );
		if ( false === $size || $size < 2 || $size > self::MAX_MANIFEST_BYTES ) { throw new \RuntimeException( 'Renderer authority manifest size is invalid.' ); }
		$bytes = file_get_contents( $real );
		if ( ! is_string( $bytes ) || strlen( $bytes ) !== (int) $size ) { throw new \RuntimeException( 'Renderer authority manifest could not be read completely.' ); }
		$data = json_decode( $bytes, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) { throw new \RuntimeException( 'Renderer authority manifest JSON is invalid.' ); }
		return $this->manifest_contract->normalize( $data );
	}

	/** @return array<string,mixed> */
	private function failed_runtime( array $status, $reason, $message ) {
		$status['accepted'] = false;
		$status['reason'] = (string) $reason;
		$status['message'] = (string) $message;
		return $status;
	}
}
