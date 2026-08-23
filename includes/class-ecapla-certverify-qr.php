<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared public QR presentation for the authoritative legacy verifier.
 *
 * The QR encodes only Certloom's canonical verification URL. It never contains
 * a secondary proof, private field, recipient identifier, or alternate
 * credential identity. The same LocalQrEncoder implements QrEncoderInterface
 * for future renderer adapters.
 */
final class Ecapla_CertVerify_QR {
	const ERROR_CORRECTION = 'M';
	const QUIET_ZONE       = 4;
	const DEFAULT_SIZE     = 176;

	/** @var array<string,string|WP_Error> */
	private static $svg_cache = array();
	/** @var array<string,array<string,mixed>|WP_Error> */
	private static $model_cache = array();

	/** @return bool */
	public static function enabled() {
		return ! class_exists( 'Ecapla_CertVerify_Settings' ) || (bool) Ecapla_CertVerify_Settings::get( 'verification_qr_enabled', true );
	}

	/** @return array<string,mixed>|WP_Error */
	public static function public_payload( $verification_url ) {
		if ( ! self::enabled() ) {
			return new WP_Error( 'ecapla_certverify_qr_disabled', __( 'Verification QR is disabled.', 'certloom' ) );
		}
		$model = self::model( $verification_url );
		if ( is_wp_error( $model ) ) { return $model; }
		return array(
			'url'       => (string) $verification_url,
			'path'      => (string) $model['path'],
			'view_size' => (int) $model['view_size'],
			'modules'   => (int) $model['modules'],
			'label'     => __( 'Verification QR code', 'certloom' ),
			'caption'   => __( 'Scan to open this credential verification page.', 'certloom' ),
		);
	}

	/** @return string */
	public static function figure_html( $verification_url, $prefix = 'cv' ) {
		$payload = self::public_payload( $verification_url );
		if ( is_wp_error( $payload ) ) { return ''; }
		$prefix = sanitize_html_class( (string) $prefix );
		$svg = self::svg_markup( (string) $payload['path'], (int) $payload['view_size'], self::DEFAULT_SIZE );
		return '<figure class="' . esc_attr( $prefix ) . '-verification-qr certloom-verification-qr"><div class="certloom-verification-qr-code" role="img" aria-label="' . esc_attr( $payload['label'] ) . '">' . $svg . '</div><figcaption>' . esc_html( $payload['caption'] ) . '</figcaption></figure>';
	}

	/** @return string|WP_Error */
	public static function svg( $verification_url, $size = self::DEFAULT_SIZE ) {
		$url = self::valid_url( $verification_url );
		if ( is_wp_error( $url ) ) { return $url; }
		$size = max( 96, min( 512, absint( $size ) ) );
		$key  = hash( 'sha256', 'svg|' . $size . '|' . $url );
		if ( array_key_exists( $key, self::$svg_cache ) ) { return self::$svg_cache[ $key ]; }
		$model = self::model( $url );
		$svg = is_wp_error( $model ) ? $model : self::svg_markup( (string) $model['path'], (int) $model['view_size'], $size );
		self::$svg_cache[ $key ] = $svg;
		return $svg;
	}

	/** @return array<string,mixed>|WP_Error */
	private static function model( $verification_url ) {
		$url = self::valid_url( $verification_url );
		if ( is_wp_error( $url ) ) { return $url; }
		$key = hash( 'sha256', self::ERROR_CORRECTION . '|' . $url );
		if ( array_key_exists( $key, self::$model_cache ) ) { return self::$model_cache[ $key ]; }
		try {
			$encoder = new \Certloom\Infrastructure\Rendering\LocalQrEncoder();
			$matrix  = $encoder->encode( $url, self::ERROR_CORRECTION );
			$model   = self::matrix_model( $matrix );
		} catch ( \Throwable $error ) {
			$model = new WP_Error( 'ecapla_certverify_qr_encode_failed', __( 'Certloom could not generate the local verification QR code.', 'certloom' ) );
		}
		self::$model_cache[ $key ] = $model;
		return $model;
	}

	/** @return array<string,mixed> */
	public static function health() {
		try {
			$encoder = new \Certloom\Infrastructure\Rendering\LocalQrEncoder();
			$matrix  = $encoder->encode( 'https://example.invalid/certloom/verify/HEALTH-CHECK/', self::ERROR_CORRECTION );
			$size    = is_array( $matrix ) ? count( $matrix ) : 0;
			$valid   = $size >= 21 && $size <= 177 && 0 === ( $size - 21 ) % 4;
			return array(
				'available' => $valid,
				'id'        => $encoder->id(),
				'version'   => $encoder->version(),
				'modules'   => $size,
			);
		} catch ( \Throwable $error ) {
			return array( 'available' => false, 'id' => '', 'version' => '', 'modules' => 0 );
		}
	}

	/** @return string|WP_Error */
	private static function valid_url( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value || strlen( $value ) > 2048 || false === filter_var( $value, FILTER_VALIDATE_URL ) || ! preg_match( '#^https?://#i', $value ) ) {
			return new WP_Error( 'ecapla_certverify_qr_invalid_url', __( 'A valid verification URL is required for the QR code.', 'certloom' ) );
		}
		$parts = wp_parse_url( $value );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) ) {
			return new WP_Error( 'ecapla_certverify_qr_invalid_url', __( 'The verification URL cannot be encoded safely.', 'certloom' ) );
		}
		return $value;
	}

	/** @param array<int,array<int,int>> $matrix @return array<string,int|string> */
	private static function matrix_model( array $matrix ) {
		$modules = count( $matrix );
		if ( $modules < 21 || $modules > 177 || 0 !== ( $modules - 21 ) % 4 ) { throw new \InvalidArgumentException( 'Invalid QR matrix size.' ); }
		$quiet = self::QUIET_ZONE; $view = $modules + ( 2 * $quiet ); $path = '';
		foreach ( array_values( $matrix ) as $y => $row ) {
			if ( ! is_array( $row ) || count( $row ) !== $modules ) { throw new \InvalidArgumentException( 'QR matrix must be square.' ); }
			$x = 0;
			while ( $x < $modules ) {
				if ( empty( $row[ $x ] ) ) { $x++; continue; }
				$start = $x; while ( $x < $modules && ! empty( $row[ $x ] ) ) { $x++; }
				$run = $x - $start;
				$path .= 'M' . ( $start + $quiet ) . ' ' . ( $y + $quiet ) . 'h' . $run . 'v1h-' . $run . 'z';
			}
		}
		if ( '' === $path || ! preg_match( '/^[M0-9 hvz-]+$/', $path ) ) { throw new \InvalidArgumentException( 'QR path could not be generated safely.' ); }
		return array( 'path' => $path, 'view_size' => $view, 'modules' => $modules );
	}

	/** @return string */
	private static function svg_markup( $path, $view, $size ) {
		$path = (string) $path; $view = absint( $view ); $size = absint( $size );
		if ( $view < 29 || $view > 185 || $size < 96 || ! preg_match( '/^[M0-9 hvz-]+$/', $path ) ) { throw new \InvalidArgumentException( 'QR SVG model is invalid.' ); }
		return '<svg class="certloom-qr-svg" xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '" viewBox="0 0 ' . $view . ' ' . $view . '" aria-hidden="true" focusable="false" shape-rendering="crispEdges"><rect width="' . $view . '" height="' . $view . '" fill="#fff"/><path d="' . $path . '" fill="#111"/></svg>';
	}

}
