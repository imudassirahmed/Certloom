<?php
namespace Certloom\Application\Rendering;

use InvalidArgumentException;

/**
 * Strict, portable evidence contract for the external PDF-04 acceptance gate.
 *
 * This does not make a renderer pass. It prevents a partial smoke test from
 * being mislabeled as full production acceptance when evidence is imported
 * from a disposable renderer environment later.
 */
final class RendererAcceptanceContract {
	const SCHEMA = 'certloom-renderer-acceptance';
	const VERSION = 1;

	/** @return string[] */
	public static function required_fixtures() {
		return array( 'english', 'urdu-rtl', 'mixed-script', 'long-name-shrink', 'rotation', 'qr' );
	}

	/** @return string[] */
	public static function required_checks() {
		return array(
			'pdf_format',
			'png_format',
			'pdf_page_geometry',
			'png_page_geometry',
			'exact_font_bytes',
			'unicode_rtl',
			'mixed_bidi',
			'long_name_overflow',
			'arbitrary_rotation_17_5',
			'qr_raster_decode',
			'pdf_png_visual_parity',
			'local_assets_only',
			'no_remote_fetch',
			'missing_dependencies_fail_soft',
			'deterministic_regeneration',
		);
	}

	/** @return array<string,mixed> */
	public function normalize( array $evidence ) {
		$allowed = array( 'schema', 'version', 'plugin_version', 'fixture_manifest_sha256', 'renderers', 'checks', 'fixtures' );
		$this->assert_shape( $evidence, $allowed, $allowed, 'renderer acceptance evidence' );
		if ( self::SCHEMA !== (string) $evidence['schema'] || ! is_int( $evidence['version'] ) || self::VERSION !== $evidence['version'] ) {
			throw new InvalidArgumentException( 'Renderer acceptance evidence schema/version is unsupported.' );
		}
		$plugin_version = trim( (string) $evidence['plugin_version'] );
		if ( ! preg_match( '/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][A-Za-z0-9.-]+)?$/', $plugin_version ) ) {
			throw new InvalidArgumentException( 'Renderer acceptance evidence has an invalid plugin version.' );
		}
		$fixture_hash = strtolower( trim( (string) $evidence['fixture_manifest_sha256'] ) );
		if ( ! $this->is_sha256( $fixture_hash ) ) {
			throw new InvalidArgumentException( 'Renderer acceptance evidence must bind the golden fixture manifest by SHA-256.' );
		}

		$renderers = $this->normalize_renderers( $evidence['renderers'] );
		$checks = $this->normalize_checks( $evidence['checks'] );
		$fixtures = $this->normalize_fixtures( $evidence['fixtures'] );

		return array(
			'schema' => self::SCHEMA,
			'version' => self::VERSION,
			'plugin_version' => $plugin_version,
			'fixture_manifest_sha256' => $fixture_hash,
			'renderers' => $renderers,
			'checks' => $checks,
			'fixtures' => $fixtures,
		);
	}

	/** @return string */
	public function hash( array $evidence ) {
		return ( new CanonicalJson() )->hash( $this->normalize( $evidence ) );
	}

	/** @return array<string,array<string,string>> */
	private function normalize_renderers( $renderers ) {
		if ( ! is_array( $renderers ) ) {
			throw new InvalidArgumentException( 'Renderer acceptance evidence must identify PDF and PNG renderers.' );
		}
		$this->assert_shape( $renderers, array( 'pdf', 'png' ), array( 'pdf', 'png' ), 'renderer acceptance renderers' );
		$out = array();
		foreach ( array( 'pdf', 'png' ) as $format ) {
			$item = $renderers[ $format ];
			if ( ! is_array( $item ) ) {
				throw new InvalidArgumentException( 'Renderer acceptance renderer identity is malformed.' );
			}
			$this->assert_shape( $item, array( 'id', 'version', 'context_hash' ), array( 'id', 'version', 'context_hash' ), 'renderer identity' );
			$id = strtolower( trim( (string) $item['id'] ) );
			$version = trim( (string) $item['version'] );
			$context_hash = strtolower( trim( (string) $item['context_hash'] ) );
			if ( ! preg_match( '/^[a-z][a-z0-9._-]{0,63}$/', $id ) || '' === $version || strlen( $version ) > 64 || ! $this->is_sha256( $context_hash ) ) {
				throw new InvalidArgumentException( 'Renderer acceptance renderer identity does not satisfy the stable renderer contract.' );
			}
			$out[ $format ] = array( 'id' => $id, 'version' => $version, 'context_hash' => $context_hash );
		}
		return $out;
	}

	/** @return array<string,bool> */
	private function normalize_checks( $checks ) {
		if ( ! is_array( $checks ) ) {
			throw new InvalidArgumentException( 'Renderer acceptance checks must be an object.' );
		}
		$required = self::required_checks();
		$this->assert_shape( $checks, $required, $required, 'renderer acceptance checks' );
		$out = array();
		foreach ( $required as $key ) {
			if ( true !== $checks[ $key ] ) {
				throw new InvalidArgumentException( 'Renderer acceptance requires every mandatory check to pass.' );
			}
			$out[ $key ] = true;
		}
		return $out;
	}

	/** @return array<string,array<string,string>> */
	private function normalize_fixtures( $fixtures ) {
		if ( ! is_array( $fixtures ) ) {
			throw new InvalidArgumentException( 'Renderer acceptance fixtures must be an object.' );
		}
		$required = self::required_fixtures();
		$this->assert_shape( $fixtures, $required, $required, 'renderer acceptance fixtures' );
		$out = array();
		foreach ( $required as $name ) {
			$item = $fixtures[ $name ];
			if ( ! is_array( $item ) ) {
				throw new InvalidArgumentException( 'Renderer acceptance fixture result is malformed.' );
			}
			$this->assert_shape( $item, array( 'pdf_sha256', 'png_sha256' ), array( 'pdf_sha256', 'png_sha256' ), 'renderer fixture result' );
			$pdf = strtolower( trim( (string) $item['pdf_sha256'] ) );
			$png = strtolower( trim( (string) $item['png_sha256'] ) );
			if ( ! $this->is_sha256( $pdf ) || ! $this->is_sha256( $png ) ) {
				throw new InvalidArgumentException( 'Renderer acceptance fixture artifacts must be bound by SHA-256.' );
			}
			$out[ $name ] = array( 'pdf_sha256' => $pdf, 'png_sha256' => $png );
		}
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
