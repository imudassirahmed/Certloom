<?php
namespace Certloom\Application\Rendering;

use InvalidArgumentException;

/**
 * Release-bound authority record derived only from a fully imported PDF-04
 * acceptance set. This is shipped with an accepted Certloom release; it is
 * never a mutable WordPress option or an administrator-controlled toggle.
 */
final class RendererReleaseManifest {
	const SCHEMA = 'certloom-renderer-release-authority';
	const VERSION = 3;

	/** @return array<string,mixed> */
	public function from_acceptance( array $evidence, array $runtime_payload ) {
		$contract = new RendererAcceptanceContract();
		$accepted = $contract->normalize( $evidence );
		if ( 'production-candidate' !== (string) $accepted['qualification'] ) {
			throw new InvalidArgumentException( 'Renderer release authority requires production-candidate acceptance evidence.' );
		}
		$payload = ( new RuntimePayloadFingerprint() )->normalize( $runtime_payload );
		return $this->normalize( array(
			'schema' => self::SCHEMA,
			'version' => self::VERSION,
			'plugin_version' => (string) $accepted['plugin_version'],
			'runtime_payload' => $payload,
			'acceptance' => array(
				'contract_version' => RendererAcceptanceContract::VERSION,
				'acceptance_sha256' => $contract->hash( $accepted ),
				'verification_report_sha256' => (string) $accepted['verification_report_sha256'],
				'verifier_environment_sha256' => (string) $accepted['verifier_environment_sha256'],
				'fixture_manifest_sha256' => (string) $accepted['fixture_manifest_sha256'],
				'render_plan_manifest_sha256' => (string) $accepted['render_plan_manifest_sha256'],
			),
			'renderers' => array(
				'pdf' => $this->renderer_identity( $accepted['renderers']['pdf'] ),
				'png' => $this->renderer_identity( $accepted['renderers']['png'] ),
				'jpeg' => $this->renderer_identity( $accepted['renderers']['jpeg'] ),
			),
			'authority' => array(
				'template_publication' => true,
				'artifact_formats' => array( 'pdf', 'png', 'jpeg' ),
			),
		) );
	}

	/** @return array<string,mixed> */
	public function normalize( array $manifest ) {
		$this->assert_shape( $manifest, array( 'schema', 'version', 'plugin_version', 'runtime_payload', 'acceptance', 'renderers', 'authority' ), array( 'schema', 'version', 'plugin_version', 'runtime_payload', 'acceptance', 'renderers', 'authority' ), 'renderer release manifest' );
		if ( self::SCHEMA !== (string) $manifest['schema'] || ! is_int( $manifest['version'] ) || self::VERSION !== $manifest['version'] ) {
			throw new InvalidArgumentException( 'Renderer release manifest schema/version is unsupported.' );
		}
		$plugin_version = trim( (string) $manifest['plugin_version'] );
		if ( ! preg_match( '/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][A-Za-z0-9.-]+)?$/', $plugin_version ) || strlen( $plugin_version ) > 64 ) {
			throw new InvalidArgumentException( 'Renderer release manifest plugin version is invalid.' );
		}
		$runtime_payload = ( new RuntimePayloadFingerprint() )->normalize( $manifest['runtime_payload'] );
		$acceptance = $this->normalize_acceptance( $manifest['acceptance'] );
		$renderers = $this->normalize_renderers( $manifest['renderers'] );
		$authority = $this->normalize_authority( $manifest['authority'] );
		return array(
			'schema' => self::SCHEMA,
			'version' => self::VERSION,
			'plugin_version' => $plugin_version,
			'runtime_payload' => $runtime_payload,
			'acceptance' => $acceptance,
			'renderers' => $renderers,
			'authority' => $authority,
		);
	}

	/** @return string */
	public function hash( array $manifest ) {
		return ( new CanonicalJson() )->hash( $this->normalize( $manifest ) );
	}

	/** @return array<string,mixed> */
	private function normalize_acceptance( $value ) {
		if ( ! is_array( $value ) ) { throw new InvalidArgumentException( 'Renderer release acceptance identity is required.' ); }
		$keys = array( 'contract_version', 'acceptance_sha256', 'verification_report_sha256', 'verifier_environment_sha256', 'fixture_manifest_sha256', 'render_plan_manifest_sha256' );
		$this->assert_shape( $value, $keys, $keys, 'renderer release acceptance identity' );
		if ( ! is_int( $value['contract_version'] ) || RendererAcceptanceContract::VERSION !== $value['contract_version'] ) {
			throw new InvalidArgumentException( 'Renderer release manifest targets a stale acceptance contract.' );
		}
		$out = array( 'contract_version' => RendererAcceptanceContract::VERSION );
		foreach ( array_slice( $keys, 1 ) as $key ) {
			$hash = strtolower( trim( (string) $value[ $key ] ) );
			if ( ! $this->is_sha256( $hash ) ) { throw new InvalidArgumentException( 'Renderer release acceptance hash is invalid.' ); }
			$out[ $key ] = $hash;
		}
		return $out;
	}

	/** @return array<string,mixed> */
	private function normalize_renderers( $value ) {
		if ( ! is_array( $value ) ) { throw new InvalidArgumentException( 'Renderer release identities are required.' ); }
		$formats = RendererAcceptanceContract::required_formats();
		$this->assert_shape( $value, $formats, $formats, 'renderer release identities' );
		return array( 'pdf' => $this->normalize_renderer( $value['pdf'] ), 'png' => $this->normalize_renderer( $value['png'] ), 'jpeg' => $this->normalize_renderer( $value['jpeg'] ) );
	}

	/** @return array<string,string> */
	private function normalize_renderer( $value ) {
		if ( ! is_array( $value ) ) { throw new InvalidArgumentException( 'Renderer release identity is malformed.' ); }
		$keys = array( 'id', 'version', 'candidate_manifest_sha256' );
		$this->assert_shape( $value, $keys, $keys, 'renderer release identity' );
		$id = strtolower( trim( (string) $value['id'] ) );
		$version = trim( (string) $value['version'] );
		$hash = strtolower( trim( (string) $value['candidate_manifest_sha256'] ) );
		if ( ! preg_match( '/^[a-z][a-z0-9._-]{0,63}$/', $id ) || '' === $version || strlen( $version ) > 64 || ! $this->is_sha256( $hash ) ) {
			throw new InvalidArgumentException( 'Renderer release identity is invalid.' );
		}
		return array( 'id' => $id, 'version' => $version, 'candidate_manifest_sha256' => $hash );
	}

	/** @return array<string,mixed> */
	private function normalize_authority( $value ) {
		if ( ! is_array( $value ) ) { throw new InvalidArgumentException( 'Renderer release authority scope is required.' ); }
		$this->assert_shape( $value, array( 'template_publication', 'artifact_formats' ), array( 'template_publication', 'artifact_formats' ), 'renderer release authority scope' );
		if ( true !== $value['template_publication'] || ! is_array( $value['artifact_formats'] ) ) {
			throw new InvalidArgumentException( 'Renderer release authority scope is invalid.' );
		}
		$formats = array_values( array_unique( array_map( function ( $format ) { return strtolower( trim( (string) $format ) ); }, $value['artifact_formats'] ) ) );
		sort( $formats, SORT_STRING );
		if ( array( 'jpeg', 'pdf', 'png' ) !== $formats ) {
			throw new InvalidArgumentException( 'Renderer release authority must prove PDF, PNG and JPEG before Template publication.' );
		}
		return array( 'template_publication' => true, 'artifact_formats' => $formats );
	}

	/** @return array<string,string> */
	private function renderer_identity( array $value ) {
		return array(
			'id' => (string) $value['id'],
			'version' => (string) $value['version'],
			'candidate_manifest_sha256' => (string) $value['candidate_manifest_sha256'],
		);
	}

	/** @return void */
	private function assert_shape( array $value, array $allowed, array $required, $label ) {
		if ( array_diff( array_keys( $value ), $allowed ) ) { throw new InvalidArgumentException( $label . ' contains an unknown property.' ); }
		foreach ( $required as $key ) { if ( ! array_key_exists( $key, $value ) ) { throw new InvalidArgumentException( $label . ' is missing a required property.' ); } }
	}

	/** @return bool */
	private function is_sha256( $value ) { return 1 === preg_match( '/^[0-9a-f]{64}$/', (string) $value ); }
}
