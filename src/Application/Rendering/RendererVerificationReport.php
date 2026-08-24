<?php
namespace Certloom\Application\Rendering;

use InvalidArgumentException;

/** Strict normalized result produced by Certloom's independent PDF-04 verifier. */
final class RendererVerificationReport {
	const SCHEMA = 'certloom-renderer-bundle-verification';
	const VERSION = 5;
	const CROSS_FORMAT_MAX_MAE = 1.0;
	const CROSS_FORMAT_MAX_CHANGED = 0.02;
	const GOLDEN_MIN_PRECISION = 0.92;
	const GOLDEN_MIN_RECALL = 0.92;

	/** @return array<string,mixed> */
	public function normalize( array $report ) {
		$keys = array( 'schema', 'version', 'verified', 'qualification', 'qualifies_pdf04', 'bundle_manifest_sha256', 'fixture_manifest_sha256', 'render_plan_manifest_sha256', 'verifier_environment_sha256', 'candidate_provenance_rechecked', 'artifact_receipts_rechecked', 'deterministic_runs', 'pdf_geometry', 'png_geometry', 'jpeg_geometry', 'qr_pdf_decode', 'qr_png_decode', 'qr_jpeg_decode', 'pdf_png_visual_parity', 'png_jpeg_visual_parity', 'golden_visual_parity', 'primitive_capability_coverage', 'renderers', 'fixtures', 'parity', 'golden_parity' );
		$this->assert_shape( $report, $keys, $keys, 'renderer verification report' );
		if ( self::SCHEMA !== (string) $report['schema'] || ! is_int( $report['version'] ) || self::VERSION !== $report['version'] ) { throw new InvalidArgumentException( 'Renderer verification report schema/version is unsupported.' ); }
		$qualification = (string) $report['qualification'];
		if ( ! in_array( $qualification, array( 'development-reference', 'production-candidate' ), true ) ) { throw new InvalidArgumentException( 'Renderer verification report qualification is unsupported.' ); }
		if ( true !== $report['verified'] ) { throw new InvalidArgumentException( 'Renderer verification report must represent a successful independent verification.' ); }
		$qualifies = true === $report['qualifies_pdf04'];
		if ( ( 'production-candidate' === $qualification ) !== $qualifies ) { throw new InvalidArgumentException( 'Renderer verification report qualification result is inconsistent.' ); }
		$hashes = array();
		foreach ( array( 'bundle_manifest_sha256', 'fixture_manifest_sha256', 'render_plan_manifest_sha256', 'verifier_environment_sha256' ) as $key ) {
			$value = strtolower( trim( (string) $report[ $key ] ) );
			if ( ! $this->is_sha256( $value ) ) { throw new InvalidArgumentException( 'Renderer verification report contains an invalid SHA-256 binding.' ); }
			$hashes[ $key ] = $value;
		}
		$proof_keys = array( 'candidate_provenance_rechecked', 'artifact_receipts_rechecked', 'deterministic_runs', 'pdf_geometry', 'png_geometry', 'jpeg_geometry', 'qr_pdf_decode', 'qr_png_decode', 'qr_jpeg_decode', 'pdf_png_visual_parity', 'png_jpeg_visual_parity', 'golden_visual_parity', 'primitive_capability_coverage' );
		$proof = array();
		foreach ( $proof_keys as $key ) {
			if ( ! is_bool( $report[ $key ] ) ) { throw new InvalidArgumentException( 'Renderer verification report proof flags must be booleans.' ); }
			$proof[ $key ] = $report[ $key ];
		}
		if ( 'production-candidate' === $qualification ) {
			foreach ( $proof as $value ) { if ( true !== $value ) { throw new InvalidArgumentException( 'Production renderer verification must re-prove every mandatory bundle property.' ); } }
		} else {
			if ( true === $proof['candidate_provenance_rechecked'] || true === $proof['artifact_receipts_rechecked'] ) { throw new InvalidArgumentException( 'Development references may not claim production provenance/receipt verification.' ); }
			foreach ( array( 'deterministic_runs', 'pdf_geometry', 'png_geometry', 'jpeg_geometry', 'qr_pdf_decode', 'qr_png_decode', 'qr_jpeg_decode', 'pdf_png_visual_parity', 'png_jpeg_visual_parity', 'golden_visual_parity', 'primitive_capability_coverage' ) as $key ) { if ( true !== $proof[ $key ] ) { throw new InvalidArgumentException( 'Development verifier references must still prove visual/deterministic checks.' ); } }
		}
		$renderers = $this->normalize_renderers( $report['renderers'] );
		$fixtures = $this->normalize_fixtures( $report['fixtures'] );
		$parity = $this->normalize_parity( $report['parity'] );
		$golden_parity = $this->normalize_golden_parity( $report['golden_parity'] );
		return array_merge( array( 'schema' => self::SCHEMA, 'version' => self::VERSION, 'verified' => true, 'qualification' => $qualification, 'qualifies_pdf04' => $qualifies ), $hashes, $proof, array( 'renderers' => $renderers, 'fixtures' => $fixtures, 'parity' => $parity, 'golden_parity' => $golden_parity ) );
	}

	/** @return string */
	public function hash( array $report ) { return ( new CanonicalJson() )->hash( $this->normalize( $report ) ); }

	private function normalize_renderers( $renderers ) {
		$formats = RendererAcceptanceContract::required_formats();
		$this->assert_shape( $renderers, $formats, $formats, 'renderer verification report renderers' );
		$out = array();
		foreach ( $formats as $format ) {
			$item = $renderers[ $format ];
			$this->assert_shape( $item, array( 'id', 'version', 'candidate_manifest_sha256' ), array( 'id', 'version', 'candidate_manifest_sha256' ), 'renderer verification identity' );
			$id = strtolower( trim( (string) $item['id'] ) ); $version = trim( (string) $item['version'] ); $hash = strtolower( trim( (string) $item['candidate_manifest_sha256'] ) );
			if ( ! preg_match( '/^[a-z][a-z0-9._-]{0,63}$/', $id ) || '' === $version || strlen( $version ) > 64 || ! $this->is_sha256( $hash ) ) { throw new InvalidArgumentException( 'Renderer verification identity is invalid.' ); }
			$out[ $format ] = array( 'id' => $id, 'version' => $version, 'candidate_manifest_sha256' => $hash );
		}
		return $out;
	}

	private function normalize_fixtures( $fixtures ) {
		$required = RendererAcceptanceContract::required_fixtures();
		$this->assert_shape( $fixtures, $required, $required, 'renderer verification fixtures' );
		$out = array();
		foreach ( $required as $name ) {
			$item = $fixtures[ $name ];
			$keys = array( 'render_plan_sha256', 'pdf_sha256', 'png_sha256', 'jpeg_sha256' );
			$this->assert_shape( $item, $keys, $keys, 'renderer verification fixture' );
			$row = array();
			foreach ( $keys as $key ) { $value = strtolower( trim( (string) $item[ $key ] ) ); if ( ! $this->is_sha256( $value ) ) { throw new InvalidArgumentException( 'Renderer verification fixture contains an invalid SHA-256.' ); } $row[ $key ] = $value; }
			$out[ $name ] = $row;
		}
		return $out;
	}

	private function normalize_parity( $parity ) {
		$required = RendererAcceptanceContract::required_fixtures();
		$this->assert_shape( $parity, $required, $required, 'renderer verification parity' );
		$out = array();
		foreach ( $required as $name ) {
			$item = $parity[ $name ];
			$this->assert_shape( $item, array( 'pdf_png', 'png_jpeg' ), array( 'pdf_png', 'png_jpeg' ), 'renderer verification parity row' );
			$out[ $name ] = array(
				'pdf_png' => $this->normalize_cross_format_metric( $item['pdf_png'] ),
				'png_jpeg' => $this->normalize_cross_format_metric( $item['png_jpeg'] ),
			);
		}
		return $out;
	}

	private function normalize_cross_format_metric( $item ) {
		$keys = array( 'mean_abs_error', 'fraction_channels_delta_gt_10' );
		$this->assert_shape( $item, $keys, $keys, 'renderer verification cross-format parity metric' );
		$mae = $item['mean_abs_error']; $changed = $item['fraction_channels_delta_gt_10'];
		if ( ! is_numeric( $mae ) || ! is_numeric( $changed ) || ! is_finite( (float) $mae ) || ! is_finite( (float) $changed ) || (float) $mae < 0 || (float) $changed < 0 || (float) $mae > self::CROSS_FORMAT_MAX_MAE || (float) $changed > self::CROSS_FORMAT_MAX_CHANGED ) { throw new InvalidArgumentException( 'Renderer verification cross-format parity exceeds the Certloom acceptance threshold.' ); }
		return array( 'mean_abs_error' => (float) $mae, 'fraction_channels_delta_gt_10' => (float) $changed );
	}

	private function normalize_golden_parity( $parity ) {
		$required = RendererAcceptanceContract::required_fixtures();
		$this->assert_shape( $parity, $required, $required, 'renderer golden parity' );
		$out = array();
		foreach ( $required as $name ) {
			$item = $parity[ $name ];
			$this->assert_shape( $item, array( 'pdf', 'png', 'jpeg' ), array( 'pdf', 'png', 'jpeg' ), 'renderer golden parity row' );
			$out[ $name ] = array();
			foreach ( array( 'pdf', 'png', 'jpeg' ) as $format ) {
				$metric = $item[ $format ];
				$this->assert_shape( $metric, array( 'foreground_precision', 'foreground_recall' ), array( 'foreground_precision', 'foreground_recall' ), 'renderer golden parity metric' );
				$precision = $metric['foreground_precision']; $recall = $metric['foreground_recall'];
				if ( ! is_numeric( $precision ) || ! is_numeric( $recall ) || ! is_finite( (float) $precision ) || ! is_finite( (float) $recall ) || (float) $precision < self::GOLDEN_MIN_PRECISION || (float) $recall < self::GOLDEN_MIN_RECALL || (float) $precision > 1 || (float) $recall > 1 ) { throw new InvalidArgumentException( 'Renderer output does not satisfy the frozen golden structural-parity threshold.' ); }
				$out[ $name ][ $format ] = array( 'foreground_precision' => (float) $precision, 'foreground_recall' => (float) $recall );
			}
		}
		return $out;
	}

	private function assert_shape( $value, array $allowed, array $required, $label ) {
		if ( ! is_array( $value ) ) { throw new InvalidArgumentException( $label . ' must be an object.' ); }
		$unknown = array_diff( array_keys( $value ), $allowed ); if ( ! empty( $unknown ) ) { throw new InvalidArgumentException( $label . ' contains an unknown property.' ); }
		foreach ( $required as $key ) { if ( ! array_key_exists( $key, $value ) ) { throw new InvalidArgumentException( $label . ' is missing a required property.' ); } }
	}
	private function is_sha256( $value ) { return 1 === preg_match( '/^[0-9a-f]{64}$/', (string) $value ); }
}
