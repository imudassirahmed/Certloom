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
	const ERROR_CORRECTION      = 'M';
	const LOGO_ERROR_CORRECTION = 'H';
	const QUIET_ZONE            = 4;
	const DEFAULT_SIZE          = 176;

	/** @var array<string,string|WP_Error> */
	private static $svg_cache = array();
	/** @var array<string,array<string,mixed>|WP_Error> */
	private static $model_cache = array();

	/** @return bool */
	public static function enabled() {
		return ! class_exists( 'Ecapla_CertVerify_Settings' ) || (bool) Ecapla_CertVerify_Settings::get( 'verification_qr_enabled', true );
	}

	/** @return bool */
	public static function logo_enabled() {
		return class_exists( 'Ecapla_CertVerify_Settings' ) && (bool) Ecapla_CertVerify_Settings::get( 'verification_qr_logo_enabled', false );
	}

	/** @return array<string,mixed>|WP_Error */
	public static function public_payload( $verification_url ) {
		if ( ! self::enabled() ) {
			return new WP_Error( 'ecapla_certverify_qr_disabled', __( 'Verification QR is disabled.', 'certloom' ) );
		}
		$logo_asset = self::configured_logo_asset();
		$correction = is_wp_error( $logo_asset ) || null === $logo_asset ? self::ERROR_CORRECTION : self::LOGO_ERROR_CORRECTION;
		$model = self::model( $verification_url, $correction );
		if ( is_wp_error( $model ) ) { return $model; }

		$logo = null;
		if ( is_array( $logo_asset ) ) {
			$candidate = self::logo_model( $logo_asset, (int) $model['modules'] );
			if ( ! is_wp_error( $candidate ) ) {
				$logo = $candidate;
			}
		}
		return array(
			'url'              => (string) $verification_url,
			'path'             => (string) $model['path'],
			'view_size'        => (int) $model['view_size'],
			'modules'          => (int) $model['modules'],
			'error_correction' => $correction,
			'logo'             => $logo,
			'label'            => null === $logo ? __( 'Verification QR code', 'certloom' ) : __( 'Verification QR code with organization mark', 'certloom' ),
			'caption'          => __( 'Scan to open this credential verification page.', 'certloom' ),
		);
	}

	/** @return string */
	public static function figure_html( $verification_url, $prefix = 'cv' ) {
		$payload = self::public_payload( $verification_url );
		if ( is_wp_error( $payload ) ) { return ''; }
		$prefix = sanitize_html_class( (string) $prefix );
		$svg = self::svg_markup( (string) $payload['path'], (int) $payload['view_size'], self::DEFAULT_SIZE, $payload['logo'] );
		return '<figure class="' . esc_attr( $prefix ) . '-verification-qr certloom-verification-qr"><div class="certloom-verification-qr-code" role="img" aria-label="' . esc_attr( $payload['label'] ) . '">' . $svg . '</div><figcaption>' . esc_html( $payload['caption'] ) . '</figcaption></figure>';
	}

	/**
	 * Returns the exact server SVG. The optional override is development/test
	 * infrastructure only: null uses configured Site Icon, false forces plain.
	 *
	 * @return string|WP_Error
	 */
	public static function svg( $verification_url, $size = self::DEFAULT_SIZE, $logo_override = null ) {
		$url = self::valid_url( $verification_url );
		if ( is_wp_error( $url ) ) { return $url; }
		$size = max( 96, min( 512, absint( $size ) ) );
		$logo_asset = false === $logo_override ? null : ( is_array( $logo_override ) ? $logo_override : self::configured_logo_asset() );
		if ( is_wp_error( $logo_asset ) ) { $logo_asset = null; }
		$correction = is_array( $logo_asset ) ? self::LOGO_ERROR_CORRECTION : self::ERROR_CORRECTION;
		$logo_hash = is_array( $logo_asset ) && ! empty( $logo_asset['sha256'] ) ? (string) $logo_asset['sha256'] : 'plain';
		$key  = hash( 'sha256', 'svg|' . $size . '|' . $correction . '|' . $logo_hash . '|' . $url );
		if ( array_key_exists( $key, self::$svg_cache ) ) { return self::$svg_cache[ $key ]; }
		$model = self::model( $url, $correction );
		$logo = is_wp_error( $model ) || ! is_array( $logo_asset ) ? null : self::logo_model( $logo_asset, (int) $model['modules'] );
		if ( is_wp_error( $logo ) ) { $logo = null; }
		$svg = is_wp_error( $model ) ? $model : self::svg_markup( (string) $model['path'], (int) $model['view_size'], $size, $logo );
		self::$svg_cache[ $key ] = $svg;
		return $svg;
	}

	/** @return array<string,mixed>|WP_Error */
	private static function model( $verification_url, $error_correction = self::ERROR_CORRECTION ) {
		$url = self::valid_url( $verification_url );
		if ( is_wp_error( $url ) ) { return $url; }
		$error_correction = strtoupper( trim( (string) $error_correction ) );
		if ( ! in_array( $error_correction, array( 'L', 'M', 'Q', 'H' ), true ) ) {
			return new WP_Error( 'ecapla_certverify_qr_error_correction', __( 'The QR error-correction mode is invalid.', 'certloom' ) );
		}
		$key = hash( 'sha256', $error_correction . '|' . $url );
		if ( array_key_exists( $key, self::$model_cache ) ) { return self::$model_cache[ $key ]; }
		try {
			$encoder = new \Certloom\Infrastructure\Rendering\LocalQrEncoder();
			$matrix  = $encoder->encode( $url, $error_correction );
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
			$matrix_h = $encoder->encode( 'https://example.invalid/certloom/verify/HEALTH-CHECK/', self::LOGO_ERROR_CORRECTION );
			$size    = is_array( $matrix ) ? count( $matrix ) : 0;
			$size_h  = is_array( $matrix_h ) ? count( $matrix_h ) : 0;
			$valid   = $size >= 21 && $size <= 177 && 0 === ( $size - 21 ) % 4 && $size_h >= 21 && $size_h <= 177 && 0 === ( $size_h - 21 ) % 4;
			$logo = self::logo_enabled() ? self::configured_logo_asset() : null;
			return array(
				'available'  => $valid,
				'id'         => $encoder->id(),
				'version'    => $encoder->version(),
				'modules'    => $size,
				'logo_state' => self::logo_enabled() ? ( is_array( $logo ) ? 'ready' : 'unavailable' ) : 'disabled',
			);
		} catch ( \Throwable $error ) {
			return array( 'available' => false, 'id' => '', 'version' => '', 'modules' => 0, 'logo_state' => self::logo_enabled() ? 'unavailable' : 'disabled' );
		}
	}

	/** @return array<string,mixed>|WP_Error|null */
	private static function configured_logo_asset() {
		if ( ! self::logo_enabled() ) {
			return null;
		}
		if ( ! class_exists( 'Ecapla_CertVerify_QR_Logo' ) ) {
			return new WP_Error( 'ecapla_certverify_qr_logo_unavailable', __( 'The QR logo resolver is unavailable.', 'certloom' ) );
		}
		return Ecapla_CertVerify_QR_Logo::asset();
	}

	/** @return array<string,mixed>|WP_Error */
	private static function logo_model( array $asset, $modules ) {
		$modules = absint( $modules );
		$data_uri = isset( $asset['data_uri'] ) ? (string) $asset['data_uri'] : '';
		if ( $modules < 29 || $modules > 177 || strlen( $data_uri ) > 800000 || 1 !== preg_match( '#^data:image/(?:png|jpeg);base64,[A-Za-z0-9+/=]+$#D', $data_uri ) ) {
			return new WP_Error( 'ecapla_certverify_qr_logo_unsafe', __( 'The QR center logo cannot be applied safely to this symbol.', 'certloom' ) );
		}
		$cutout = max( 5, min( 11, (int) round( $modules * 0.11 ) ) );
		if ( 0 === $cutout % 2 ) { $cutout--; }
		$cutout = max( 5, $cutout );
		$x = self::QUIET_ZONE + (int) floor( ( $modules - $cutout ) / 2 );
		$y = $x;
		$inset = 1;
		return array(
			'data_uri' => $data_uri,
			'x'        => $x,
			'y'        => $y,
			'size'     => $cutout,
			'image_x'  => $x + $inset,
			'image_y'  => $y + $inset,
			'image_size' => max( 1, $cutout - ( 2 * $inset ) ),
		);
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
	private static function svg_markup( $path, $view, $size, $logo = null ) {
		$path = (string) $path; $view = absint( $view ); $size = absint( $size );
		if ( $view < 29 || $view > 185 || $size < 96 || ! preg_match( '/^[M0-9 hvz-]+$/', $path ) ) { throw new \InvalidArgumentException( 'QR SVG model is invalid.' ); }
		$overlay = '';
		if ( is_array( $logo ) ) {
			$uri = isset( $logo['data_uri'] ) ? (string) $logo['data_uri'] : '';
			$x = isset( $logo['x'] ) ? absint( $logo['x'] ) : 0;
			$y = isset( $logo['y'] ) ? absint( $logo['y'] ) : 0;
			$logo_size = isset( $logo['size'] ) ? absint( $logo['size'] ) : 0;
			$image_x = isset( $logo['image_x'] ) ? absint( $logo['image_x'] ) : 0;
			$image_y = isset( $logo['image_y'] ) ? absint( $logo['image_y'] ) : 0;
			$image_size = isset( $logo['image_size'] ) ? absint( $logo['image_size'] ) : 0;
			if ( $logo_size >= 5 && $image_size >= 1 && $x + $logo_size <= $view && $y + $logo_size <= $view && 1 === preg_match( '#^data:image/(?:png|jpeg);base64,[A-Za-z0-9+/=]+$#D', $uri ) ) {
				$overlay = '<rect class="certloom-qr-logo-background" x="' . $x . '" y="' . $y . '" width="' . $logo_size . '" height="' . $logo_size . '" rx="1" fill="#fff"/><image class="certloom-qr-logo-image" href="' . esc_attr( $uri ) . '" x="' . $image_x . '" y="' . $image_y . '" width="' . $image_size . '" height="' . $image_size . '" preserveAspectRatio="xMidYMid meet"/>';
			}
		}
		return '<svg class="certloom-qr-svg" xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '" viewBox="0 0 ' . $view . ' ' . $view . '" aria-hidden="true" focusable="false" shape-rendering="crispEdges"><rect width="' . $view . '" height="' . $view . '" fill="#fff"/><path d="' . $path . '" fill="#111"/>' . $overlay . '</svg>';
	}
}
