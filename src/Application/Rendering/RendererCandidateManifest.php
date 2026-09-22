<?php
namespace Certloom\Application\Rendering;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use InvalidArgumentException;

/**
 * Portable provenance/security profile for a renderer candidate evaluated by PDF-04.
 *
 * This is release-engineering evidence, not a runtime license or remote trust token.
 * It ensures a renderer benchmark identifies the exact isolated implementation being
 * tested and states the non-negotiable Certloom execution/security posture.
 */
final class RendererCandidateManifest {
	const SCHEMA = 'certloom-renderer-candidate';
	const VERSION = 2;

	/** @return array<string,mixed> */
	public function normalize( array $manifest ) {
		$allowed = array( 'schema', 'version', 'renderer_id', 'renderer_version', 'formats', 'implementation', 'security', 'requirements' );
		$this->assert_shape( $manifest, $allowed, $allowed, 'renderer candidate manifest' );
		if ( self::SCHEMA !== (string) $manifest['schema'] || ! is_int( $manifest['version'] ) || self::VERSION !== $manifest['version'] ) {
			throw new InvalidArgumentException( 'Renderer candidate manifest schema/version is unsupported.' );
		}
		$id = strtolower( trim( (string) $manifest['renderer_id'] ) );
		$version = trim( (string) $manifest['renderer_version'] );
		if ( ! preg_match( '/^[a-z][a-z0-9._-]{0,63}$/', $id ) || '' === $version || strlen( $version ) > 64 ) {
			throw new InvalidArgumentException( 'Renderer candidate identity does not satisfy the stable renderer contract.' );
		}
		$formats = $this->normalize_formats( $manifest['formats'] );
		$implementation = $this->normalize_implementation( $manifest['implementation'] );
		$security = $this->normalize_security( $manifest['security'] );
		$requirements = $this->normalize_requirements( $manifest['requirements'] );

		return array(
			'schema' => self::SCHEMA,
			'version' => self::VERSION,
			'renderer_id' => $id,
			'renderer_version' => $version,
			'formats' => $formats,
			'implementation' => $implementation,
			'security' => $security,
			'requirements' => $requirements,
		);
	}

	/** @return string */
	public function hash( array $manifest ) {
		return ( new CanonicalJson() )->hash( $this->normalize( $manifest ) );
	}

	/** @return string[] */
	private function normalize_formats( $formats ) {
		if ( ! is_array( $formats ) || empty( $formats ) ) {
			throw new InvalidArgumentException( 'Renderer candidate must declare at least one artifact format.' );
		}
		$out = array();
		foreach ( $formats as $format ) {
			$format = strtolower( trim( (string) $format ) );
			if ( ! in_array( $format, array( 'pdf', 'png', 'jpeg' ), true ) || in_array( $format, $out, true ) ) {
				throw new InvalidArgumentException( 'Renderer candidate formats are invalid or duplicated.' );
			}
			$out[] = $format;
		}
		sort( $out, SORT_STRING );
		return $out;
	}

	/** @return array<string,string> */
	private function normalize_implementation( $value ) {
		if ( ! is_array( $value ) ) {
			throw new InvalidArgumentException( 'Renderer candidate implementation provenance is required.' );
		}
		$keys = array( 'package', 'package_version', 'provenance_mode', 'namespace_prefix', 'source_sha256', 'dependency_lock_sha256', 'license' );
		$this->assert_shape( $value, $keys, $keys, 'renderer candidate implementation' );
		$package = strtolower( trim( (string) $value['package'] ) );
		$package_version = trim( (string) $value['package_version'] );
		$mode = strtolower( trim( (string) $value['provenance_mode'] ) );
		$prefix = trim( (string) $value['namespace_prefix'] );
		$source = strtolower( trim( (string) $value['source_sha256'] ) );
		$lock = strtolower( trim( (string) $value['dependency_lock_sha256'] ) );
		$license = trim( (string) $value['license'] );
		if ( ! preg_match( '/^[a-z0-9][a-z0-9._-]*\/[a-z0-9][a-z0-9._-]*$/', $package ) || '' === $package_version || strlen( $package_version ) > 64 ) {
			throw new InvalidArgumentException( 'Renderer candidate package identity is invalid.' );
		}
		if ( ! in_array( $mode, array( 'prefixed-package', 'certloom-native' ), true ) ) {
			throw new InvalidArgumentException( 'Renderer candidate provenance mode is unsupported.' );
		}
		if ( ! preg_match( '/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*\\\\?$/', $prefix ) ) {
			throw new InvalidArgumentException( 'Renderer candidate namespace prefix is invalid.' );
		}
		if ( 'prefixed-package' === $mode && 0 !== strpos( $prefix, 'CertloomVendor\\' ) ) {
			throw new InvalidArgumentException( 'Prefixed renderer candidates must use an isolated CertloomVendor namespace prefix.' );
		}
		if ( 'certloom-native' === $mode && 0 !== strpos( $prefix, 'Certloom\\' ) ) {
			throw new InvalidArgumentException( 'Certloom-native renderer candidates must identify their real Certloom namespace.' );
		}
		if ( ! $this->is_sha256( $source ) || ! $this->is_sha256( $lock ) || ! preg_match( '/^[A-Za-z0-9.+-]{1,64}$/', $license ) ) {
			throw new InvalidArgumentException( 'Renderer candidate provenance hashes/license are invalid.' );
		}
		return array(
			'package' => $package,
			'package_version' => $package_version,
			'provenance_mode' => $mode,
			'namespace_prefix' => rtrim( $prefix, '\\' ) . '\\',
			'source_sha256' => $source,
			'dependency_lock_sha256' => $lock,
			'license' => $license,
		);
	}

	/** @return array<string,mixed> */
	private function normalize_security( $value ) {
		if ( ! is_array( $value ) ) {
			throw new InvalidArgumentException( 'Renderer candidate security profile is required.' );
		}
		$keys = array( 'execution_mode', 'input_schema', 'network', 'processes', 'remote_assets' );
		$this->assert_shape( $value, $keys, $keys, 'renderer candidate security profile' );
		if ( 'php-inprocess' !== (string) $value['execution_mode'] || 'certloom-render-plan:1' !== (string) $value['input_schema'] || 'none' !== (string) $value['network'] || 'none' !== (string) $value['processes'] || false !== $value['remote_assets'] ) {
			throw new InvalidArgumentException( 'Renderer candidate violates the Certloom local in-process/no-network execution policy.' );
		}
		return array(
			'execution_mode' => 'php-inprocess',
			'input_schema' => 'certloom-render-plan:1',
			'network' => 'none',
			'processes' => 'none',
			'remote_assets' => false,
		);
	}

	/** @return array<string,mixed> */
	private function normalize_requirements( $value ) {
		if ( ! is_array( $value ) ) {
			throw new InvalidArgumentException( 'Renderer candidate runtime requirements are required.' );
		}
		$allowed = array( 'php_min', 'extensions', 'classes', 'functions' );
		$this->assert_shape( $value, $allowed, array( 'php_min', 'extensions', 'classes', 'functions' ), 'renderer candidate requirements' );
		$php_min = trim( (string) $value['php_min'] );
		if ( ! preg_match( '/^[0-9]+\.[0-9]+(?:\.[0-9]+)?$/', $php_min ) ) {
			throw new InvalidArgumentException( 'Renderer candidate minimum PHP version is invalid.' );
		}
		return array(
			'php_min' => $php_min,
			'extensions' => $this->normalize_names( $value['extensions'], '/^[a-z0-9_.-]{1,64}$/' ),
			'classes' => $this->normalize_names( $value['classes'], '/^[A-Za-z_][A-Za-z0-9_\\\\]{0,255}$/' ),
			'functions' => $this->normalize_names( $value['functions'], '/^[A-Za-z_][A-Za-z0-9_]{0,127}$/' ),
		);
	}

	/** @return string[] */
	private function normalize_names( $values, $pattern ) {
		if ( ! is_array( $values ) ) {
			throw new InvalidArgumentException( 'Renderer candidate requirement list is malformed.' );
		}
		$out = array();
		foreach ( $values as $value ) {
			$value = trim( (string) $value );
			if ( ! preg_match( $pattern, $value ) || in_array( $value, $out, true ) ) {
				throw new InvalidArgumentException( 'Renderer candidate requirement is invalid or duplicated.' );
			}
			$out[] = $value;
		}
		sort( $out, SORT_STRING );
		return $out;
	}

	/** @return void */
	private function assert_shape( array $value, array $allowed, array $required, $label ) {
		$unknown = array_diff( array_keys( $value ), $allowed );
		if ( ! empty( $unknown ) ) {
			throw new InvalidArgumentException( $label . ' contains an unknown property.' );
		}
		foreach ( $required as $key ) {
			if ( ! array_key_exists( $key, $value ) ) {
				throw new InvalidArgumentException( $label . ' is missing a required property.' );
			}
		}
	}

	/** @return bool */
	private function is_sha256( $value ) {
		return 1 === preg_match( '/^[0-9a-f]{64}$/', (string) $value );
	}
}
