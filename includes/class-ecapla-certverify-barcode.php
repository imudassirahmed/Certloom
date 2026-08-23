<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Local Code 128 presentation for the already-authoritative human certificate
 * number. This is a representation only: it adds no barcode lookup endpoint,
 * token, route, or alternate credential identity.
 */
final class Ecapla_CertVerify_Barcode {
	const QUIET_ZONE    = 10;
	const BAR_HEIGHT    = 40;
	const DEFAULT_WIDTH = 440;

	/** @var array<string,array<string,mixed>|WP_Error> */
	private static $model_cache = array();

	/** @return bool */
	public static function enabled() {
		return class_exists( 'Ecapla_CertVerify_Settings' ) && (bool) Ecapla_CertVerify_Settings::get( 'verification_barcode_enabled', false );
	}

	/** @return array<string,mixed>|WP_Error */
	public static function public_payload( $certificate_number ) {
		if ( ! self::enabled() ) {
			return new WP_Error( 'ecapla_certverify_barcode_disabled', __( 'Verification barcode is disabled.', 'certloom' ) );
		}
		$model = self::model( $certificate_number );
		if ( is_wp_error( $model ) ) { return $model; }
		return array(
			'value'       => (string) $certificate_number,
			'path'        => (string) $model['path'],
			'view_width'  => (int) $model['view_width'],
			'view_height'  => self::BAR_HEIGHT,
			'display_width'=> self::display_width( (int) $model['view_width'] ),
			'modules'      => (int) $model['modules'],
			'label'       => __( 'Code 128 certificate barcode', 'certloom' ),
			'caption'     => __( 'Code 128 representation of the certificate number.', 'certloom' ),
		);
	}

	/** @return string */
	public static function figure_html( $certificate_number, $prefix = 'cv' ) {
		$payload = self::public_payload( $certificate_number );
		if ( is_wp_error( $payload ) ) { return ''; }
		$prefix = sanitize_html_class( (string) $prefix );
		$svg = self::svg_markup( $payload['path'], $payload['view_width'], self::BAR_HEIGHT, (int) $payload['display_width'] );
		return '<figure class="' . esc_attr( $prefix ) . '-verification-barcode certloom-verification-barcode"><div class="certloom-verification-barcode-code" role="img" aria-label="' . esc_attr( $payload['label'] ) . '">' . $svg . '</div><figcaption>' . esc_html( $payload['caption'] ) . ' <code>' . esc_html( (string) $certificate_number ) . '</code></figcaption></figure>';
	}

	/** @return string|WP_Error */
	public static function svg( $certificate_number, $width = self::DEFAULT_WIDTH ) {
		$model = self::model( $certificate_number );
		if ( is_wp_error( $model ) ) { return $model; }
		$width = max( 220, min( 1600, absint( $width ) ) );
		return self::svg_markup( $model['path'], $model['view_width'], self::BAR_HEIGHT, $width );
	}

	/** @return array<string,mixed> */
	public static function health() {
		try {
			$encoder = new \Certloom\Infrastructure\Rendering\LocalCode128Encoder();
			$modules = $encoder->encode( 'CERT-2026-000001' );
			return array(
				'available' => is_array( $modules ) && count( $modules ) >= 46,
				'id'        => $encoder->id(),
				'version'   => $encoder->version(),
				'modules'   => count( $modules ),
			);
		} catch ( \Throwable $error ) {
			return array( 'available' => false, 'id' => '', 'version' => '', 'modules' => 0 );
		}
	}

	/** @return array<string,mixed>|WP_Error */
	private static function model( $certificate_number ) {
		$value = trim( (string) $certificate_number );
		if ( '' === $value || strlen( $value ) > 100 || 1 !== preg_match( '/^[\x20-\x7E]+$/D', $value ) ) {
			return new WP_Error( 'ecapla_certverify_barcode_unsupported', __( 'This certificate number cannot be represented safely as Code 128.', 'certloom' ) );
		}
		$key = hash( 'sha256', $value );
		if ( array_key_exists( $key, self::$model_cache ) ) { return self::$model_cache[ $key ]; }
		try {
			$encoder = new \Certloom\Infrastructure\Rendering\LocalCode128Encoder();
			$modules = $encoder->encode( $value );
			$model = self::module_model( $modules );
		} catch ( \Throwable $error ) {
			$model = new WP_Error( 'ecapla_certverify_barcode_encode_failed', __( 'Certloom could not generate the local Code 128 barcode.', 'certloom' ) );
		}
		self::$model_cache[ $key ] = $model;
		return $model;
	}

	/** @param array<int,int> $modules @return array<string,mixed> */
	private static function module_model( array $modules ) {
		$count = count( $modules );
		if ( $count < 46 || $count > 760 ) {
			throw new \InvalidArgumentException( 'Code 128 module count is outside the supported range.' );
		}
		$path = '';
		$x = 0;
		while ( $x < $count ) {
			$value = $modules[ $x ];
			if ( 0 !== $value && 1 !== $value ) {
				throw new \InvalidArgumentException( 'Code 128 modules must be binary.' );
			}
			if ( 0 === $value ) { $x++; continue; }
			$start = $x;
			while ( $x < $count && 1 === $modules[ $x ] ) { $x++; }
			$run = $x - $start;
			$path .= 'M' . ( $start + self::QUIET_ZONE ) . ' 0h' . $run . 'v' . self::BAR_HEIGHT . 'h-' . $run . 'z';
		}
		if ( '' === $path || 1 !== preg_match( '/^[M0-9 hvz-]+$/D', $path ) ) {
			throw new \InvalidArgumentException( 'Code 128 SVG path could not be generated safely.' );
		}
		return array(
			'path'       => $path,
			'view_width' => $count + ( 2 * self::QUIET_ZONE ),
			'modules'    => $count,
		);
	}

	/** @return int */
	private static function display_width( $view_width ) {
		return max( self::DEFAULT_WIDTH, min( 1600, absint( $view_width ) * 2 ) );
	}

	/** @return string */
	private static function svg_markup( $path, $view_width, $view_height, $width = self::DEFAULT_WIDTH ) {
		$path = (string) $path;
		$view_width = absint( $view_width );
		$view_height = absint( $view_height );
		$width = absint( $width );
		if ( $view_width < 66 || $view_width > 780 || self::BAR_HEIGHT !== $view_height || $width < 220 || 1 !== preg_match( '/^[M0-9 hvz-]+$/D', $path ) ) {
			throw new \InvalidArgumentException( 'Code 128 SVG model is invalid.' );
		}
		return '<svg class="certloom-barcode-svg" xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="88" viewBox="0 0 ' . $view_width . ' ' . $view_height . '" preserveAspectRatio="none" aria-hidden="true" focusable="false" shape-rendering="crispEdges"><rect width="' . $view_width . '" height="' . $view_height . '" fill="#fff"/><path d="' . $path . '" fill="#111"/></svg>';
	}
}
