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
	const VERSION = 9;

	/** @return string[] */
	public static function legacy_fixtures() { return array( 'english', 'urdu-rtl', 'mixed-script', 'long-name-shrink', 'rotation', 'qr', 'primitives' ); }

	/** @return string[] */
	public static function schema4_fixtures() { return array( 'schema4-rich-text', 'schema4-signer', 'schema4-table', 'schema4-data-table' ); }

	/** @return string[] */
	public static function required_fixtures() { return array_merge( self::legacy_fixtures(), self::schema4_fixtures() ); }

	/** @return string[] */
	public static function required_formats() {
		return array( 'pdf', 'png', 'jpeg' );
	}

	/** @return string[] */
	public static function required_checks() {
		return array(
			'pdf_format',
			'png_format',
			'jpeg_format',
			'pdf_page_geometry',
			'png_page_geometry',
			'jpeg_page_geometry',
			'exact_font_bytes',
			'unicode_rtl',
			'mixed_bidi',
			'long_name_overflow',
			'arbitrary_rotation_17_5',
			'qr_pdf_decode',
			'qr_png_decode',
			'qr_jpeg_decode',
			'pdf_png_visual_parity',
			'pdf_png_foreground_parity',
			'png_jpeg_visual_parity',
			'golden_visual_parity',
			'current_oracle_v2',
			'primitive_capability_coverage',
			'schema4_rich_text',
			'schema4_signer_signature',
			'schema4_fixed_table',
			'schema4_data_table',
			'local_assets_only',
			'no_remote_fetch',
			'missing_dependencies_fail_soft',
			'deterministic_regeneration',
			'shared_render_plan',
			'independent_verifier_environment_bound',
			'candidate_provenance_rechecked',
			'artifact_receipts_rechecked',
		);
	}

	/** @return array<string,mixed> */
	public function normalize( array $evidence ) {
		$allowed = array( 'schema', 'version', 'qualification', 'plugin_version', 'fixture_manifest_sha256', 'render_plan_manifest_sha256', 'current_oracle_manifest_sha256', 'bundle_manifest_sha256', 'verification_report_sha256', 'verifier_environment_sha256', 'renderers', 'checks', 'fixtures' );
		$this->assert_shape( $evidence, $allowed, $allowed, 'renderer acceptance evidence' );
		if ( self::SCHEMA !== (string) $evidence['schema'] || ! is_int( $evidence['version'] ) || self::VERSION !== $evidence['version'] ) {
			throw new InvalidArgumentException( 'Renderer acceptance evidence schema/version is unsupported.' );
		}
		if ( 'production-candidate' !== (string) $evidence['qualification'] ) {
			throw new InvalidArgumentException( 'Renderer acceptance evidence must come from a production-candidate artifact bundle.' );
		}
		$plugin_version = trim( (string) $evidence['plugin_version'] );
		if ( ! preg_match( '/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][A-Za-z0-9.-]+)?$/', $plugin_version ) ) {
			throw new InvalidArgumentException( 'Renderer acceptance evidence has an invalid plugin version.' );
		}
		$fixture_hash = strtolower( trim( (string) $evidence['fixture_manifest_sha256'] ) );
		if ( ! $this->is_sha256( $fixture_hash ) ) {
			throw new InvalidArgumentException( 'Renderer acceptance evidence must bind the golden fixture manifest by SHA-256.' );
		}

		$render_plan_manifest_hash = strtolower( trim( (string) $evidence['render_plan_manifest_sha256'] ) );
		if ( ! $this->is_sha256( $render_plan_manifest_hash ) ) {
			throw new InvalidArgumentException( 'Renderer acceptance evidence must bind the shared render-plan manifest by SHA-256.' );
		}

		$current_oracle_manifest_hash = strtolower( trim( (string) $evidence['current_oracle_manifest_sha256'] ) );
		if ( ! $this->is_sha256( $current_oracle_manifest_hash ) ) {
			throw new InvalidArgumentException( 'Renderer acceptance evidence must bind the versioned current renderer oracle by SHA-256.' );
		}

		$bundle_manifest_hash = strtolower( trim( (string) $evidence['bundle_manifest_sha256'] ) );
		if ( ! $this->is_sha256( $bundle_manifest_hash ) ) {
			throw new InvalidArgumentException( 'Renderer acceptance evidence must bind an independently verified artifact bundle manifest by SHA-256.' );
		}

		$verification_report_hash = strtolower( trim( (string) $evidence['verification_report_sha256'] ) );
		if ( ! $this->is_sha256( $verification_report_hash ) ) {
			throw new InvalidArgumentException( 'Renderer acceptance evidence must bind the independent verifier report by SHA-256.' );
		}

		$verifier_environment_hash = strtolower( trim( (string) $evidence['verifier_environment_sha256'] ) );
		if ( ! $this->is_sha256( $verifier_environment_hash ) ) {
			throw new InvalidArgumentException( 'Renderer acceptance evidence must bind the independent verifier environment by SHA-256.' );
		}

		$renderers = $this->normalize_renderers( $evidence['renderers'] );
		$checks = $this->normalize_checks( $evidence['checks'] );
		$fixtures = $this->normalize_fixtures( $evidence['fixtures'] );

		return array(
			'schema' => self::SCHEMA,
			'version' => self::VERSION,
			'qualification' => 'production-candidate',
			'plugin_version' => $plugin_version,
			'fixture_manifest_sha256' => $fixture_hash,
			'render_plan_manifest_sha256' => $render_plan_manifest_hash,
			'current_oracle_manifest_sha256' => $current_oracle_manifest_hash,
			'bundle_manifest_sha256' => $bundle_manifest_hash,
			'verification_report_sha256' => $verification_report_hash,
			'verifier_environment_sha256' => $verifier_environment_hash,
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
			throw new InvalidArgumentException( 'Renderer acceptance evidence must identify PDF, PNG and JPEG renderers.' );
		}
		$formats = self::required_formats();
		$this->assert_shape( $renderers, $formats, $formats, 'renderer acceptance renderers' );
		$out = array();
		foreach ( $formats as $format ) {
			$item = $renderers[ $format ];
			if ( ! is_array( $item ) ) {
				throw new InvalidArgumentException( 'Renderer acceptance renderer identity is malformed.' );
			}
			$this->assert_shape( $item, array( 'id', 'version', 'context_hash', 'candidate_manifest_sha256' ), array( 'id', 'version', 'context_hash', 'candidate_manifest_sha256' ), 'renderer identity' );
			$id = strtolower( trim( (string) $item['id'] ) );
			$version = trim( (string) $item['version'] );
			$context_hash = strtolower( trim( (string) $item['context_hash'] ) );
			$candidate_manifest_hash = strtolower( trim( (string) $item['candidate_manifest_sha256'] ) );
			if ( ! preg_match( '/^[a-z][a-z0-9._-]{0,63}$/', $id ) || '' === $version || strlen( $version ) > 64 || ! $this->is_sha256( $context_hash ) || ! $this->is_sha256( $candidate_manifest_hash ) ) {
				throw new InvalidArgumentException( 'Renderer acceptance renderer identity does not satisfy the stable renderer contract.' );
			}
			$out[ $format ] = array( 'id' => $id, 'version' => $version, 'context_hash' => $context_hash, 'candidate_manifest_sha256' => $candidate_manifest_hash );
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
			$keys = array( 'render_plan_sha256', 'pdf_sha256', 'png_sha256', 'jpeg_sha256' );
			$this->assert_shape( $item, $keys, $keys, 'renderer fixture result' );
			$row = array();
			foreach ( $keys as $key ) {
				$value = strtolower( trim( (string) $item[ $key ] ) );
				if ( ! $this->is_sha256( $value ) ) {
					throw new InvalidArgumentException( 'Renderer acceptance fixture plans/artifacts must be bound by SHA-256.' );
				}
				$row[ $key ] = $value;
			}
			$out[ $name ] = $row;
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
