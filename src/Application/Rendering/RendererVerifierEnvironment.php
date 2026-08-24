<?php
namespace Certloom\Application\Rendering;

use InvalidArgumentException;

/**
 * Portable provenance for the independent source-side PDF-04 verifier toolchain.
 *
 * Renderer candidates are not allowed to choose or rewrite this environment.
 * The verifier captures it after artifact generation so acceptance evidence can
 * identify the exact scripts, PHP/Python runtimes, rasterizer and QR/image
 * libraries that independently rechecked a production-candidate bundle.
 */
final class RendererVerifierEnvironment {
	const SCHEMA = 'certloom-renderer-verifier-environment';
	const VERSION = 2;

	/** @return array<string,mixed> */
	public function normalize( array $manifest ) {
		$keys = array( 'schema', 'version', 'plugin_version', 'runtime', 'tools', 'packages', 'scripts' );
		$this->assert_shape( $manifest, $keys, $keys, 'renderer verifier environment' );
		if ( self::SCHEMA !== (string) $manifest['schema'] || ! is_int( $manifest['version'] ) || self::VERSION !== $manifest['version'] ) {
			throw new InvalidArgumentException( 'Renderer verifier environment schema/version is unsupported.' );
		}
		$plugin_version = trim( (string) $manifest['plugin_version'] );
		if ( ! preg_match( '/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][A-Za-z0-9.-]+)?$/', $plugin_version ) ) {
			throw new InvalidArgumentException( 'Renderer verifier environment has an invalid plugin version.' );
		}

		$runtime_keys = array( 'python_implementation', 'python_version', 'php_version', 'platform' );
		$this->assert_shape( $manifest['runtime'], $runtime_keys, $runtime_keys, 'renderer verifier runtime' );
		$runtime = array();
		foreach ( $runtime_keys as $key ) { $runtime[ $key ] = $this->bounded_text( $manifest['runtime'][ $key ], 128, 'Renderer verifier runtime value is invalid.' ); }

		$tool_keys = array( 'pdfinfo', 'pdftocairo' );
		$this->assert_shape( $manifest['tools'], $tool_keys, $tool_keys, 'renderer verifier tools' );
		$tools = array();
		foreach ( $tool_keys as $key ) { $tools[ $key ] = $this->bounded_text( $manifest['tools'][ $key ], 256, 'Renderer verifier tool version is invalid.' ); }

		$package_keys = array( 'numpy', 'opencv', 'pillow' );
		$this->assert_shape( $manifest['packages'], $package_keys, $package_keys, 'renderer verifier packages' );
		$packages = array();
		foreach ( $package_keys as $key ) { $packages[ $key ] = $this->bounded_text( $manifest['packages'][ $key ], 128, 'Renderer verifier package version is invalid.' ); }

		$script_keys = array( 'verify_bundle_sha256', 'capture_environment_sha256', 'validate_candidate_sha256', 'verify_receipt_sha256', 'validate_environment_sha256', 'validate_report_sha256', 'verify_entrypoint_sha256' );
		$this->assert_shape( $manifest['scripts'], $script_keys, $script_keys, 'renderer verifier scripts' );
		$scripts = array();
		foreach ( $script_keys as $key ) {
			$value = strtolower( trim( (string) $manifest['scripts'][ $key ] ) );
			if ( ! $this->is_sha256( $value ) ) { throw new InvalidArgumentException( 'Renderer verifier scripts must be bound by SHA-256.' ); }
			$scripts[ $key ] = $value;
		}

		return array(
			'schema' => self::SCHEMA,
			'version' => self::VERSION,
			'plugin_version' => $plugin_version,
			'runtime' => $runtime,
			'tools' => $tools,
			'packages' => $packages,
			'scripts' => $scripts,
		);
	}

	/** @return string */
	public function hash( array $manifest ) { return ( new CanonicalJson() )->hash( $this->normalize( $manifest ) ); }

	/** @return string */
	private function bounded_text( $value, $max, $message ) {
		$value = trim( (string) $value );
		if ( '' === $value || strlen( $value ) > (int) $max || preg_match( '/[\x00-\x1F\x7F]/', $value ) ) { throw new InvalidArgumentException( $message ); }
		return $value;
	}

	/** @return void */
	private function assert_shape( $value, array $allowed, array $required, $label ) {
		if ( ! is_array( $value ) ) { throw new InvalidArgumentException( $label . ' must be an object.' ); }
		$unknown = array_diff( array_keys( $value ), $allowed );
		if ( ! empty( $unknown ) ) { throw new InvalidArgumentException( $label . ' contains an unknown property.' ); }
		foreach ( $required as $key ) { if ( ! array_key_exists( $key, $value ) ) { throw new InvalidArgumentException( $label . ' is missing a required property.' ); } }
	}

	/** @return bool */
	private function is_sha256( $value ) { return 1 === preg_match( '/^[0-9a-f]{64}$/', (string) $value ); }
}
