<?php
namespace Certloom\Application\Rendering;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Site-local custom font library for Studio authoring, browser preview and outgoing email web-font use.
 *
 * Files are content-addressed and never fetched remotely. Certificate artifact
 * renderers remain fail-closed unless the same exact face is separately present
 * in the authorized renderer FontManifest.
 */
final class CustomFontLibrary {
	const OPTION = 'certloom_custom_font_library_v1';
	const SUBDIR = 'certloom-fonts';
	const MAX_BYTES = 8388608;

	/** @return array<string,array<string,mixed>> */
	public function families() {
		if ( ! function_exists( 'get_option' ) ) { return array(); }
		$value = get_option( self::OPTION, array() );
		if ( ! is_array( $value ) ) { return array(); }
		$out = array();
		foreach ( $value as $key => $family ) {
			$key = strtolower( trim( (string) $key ) );
			if ( ! preg_match( '/^custom-[a-f0-9]{16}$/', $key ) || ! is_array( $family ) ) { continue; }
			$label = trim( wp_strip_all_tags( (string) ( $family['label'] ?? '' ) ) );
			$faces = isset( $family['faces'] ) && is_array( $family['faces'] ) ? $family['faces'] : array();
			if ( '' === $label || empty( $faces ) ) { continue; }
			$clean_faces = array();
			foreach ( $faces as $variant => $face ) {
				if ( ! preg_match( '/^(?:[1-9]00)-(?:normal|italic)$/', (string) $variant ) || ! is_array( $face ) ) { continue; }
				$hash = strtolower( (string) ( $face['sha256'] ?? '' ) );
				$format = strtolower( (string) ( $face['format'] ?? '' ) );
				$file = basename( (string) ( $face['file'] ?? '' ) );
				if ( ! preg_match( '/^[a-f0-9]{64}$/', $hash ) || ! in_array( $format, array( 'ttf', 'otf' ), true ) || $file !== $hash . '.' . $format ) { continue; }
				$clean_faces[ $variant ] = array( 'sha256' => $hash, 'format' => $format, 'file' => $file );
			}
			if ( ! empty( $clean_faces ) ) { $out[ $key ] = array( 'label' => $label, 'faces' => $clean_faces ); }
		}
		ksort( $out, SORT_STRING );
		return $out;
	}

	/** @return array<string,array<string,mixed>> */
	public function designer_definitions() {
		if ( ! function_exists( 'wp_upload_dir' ) ) { return array(); }
		$uploads = wp_upload_dir();
		$baseurl = rtrim( (string) ( $uploads['baseurl'] ?? '' ), '/' ) . '/' . self::SUBDIR . '/';
		$out = array();
		foreach ( $this->families() as $key => $family ) {
			$css_family = 'CertloomCustom_' . substr( $key, 7 );
			$faces = array();
			foreach ( $family['faces'] as $variant => $face ) {
				list( $weight, $style ) = explode( '-', $variant, 2 );
				$faces[] = array(
					'weight' => (int) $weight,
					'style' => $style,
					'format' => (string) $face['format'],
					'sha256' => (string) $face['sha256'],
					'url' => esc_url_raw( $baseurl . rawurlencode( (string) $face['file'] ) ),
				);
			}
			$out[ $key ] = array(
				'label' => (string) $family['label'],
				'css' => '"' . $css_family . '",Arial,sans-serif',
				'group' => 'Custom fonts',
				'local_names' => array(),
				'custom' => true,
				'preview_family' => $css_family,
				'faces' => $faces,
			);
		}
		return $out;
	}

	/** @return array<string,mixed>|\WP_Error */
	public function store_upload( array $file, $family_label, $weight = 400, $style = 'normal' ) {
		$family_label = trim( wp_strip_all_tags( (string) $family_label ) );
		$weight = (int) $weight;
		$style = 'italic' === strtolower( (string) $style ) ? 'italic' : 'normal';
		if ( '' === $family_label || strlen( $family_label ) > 96 ) { return new \WP_Error( 'certloom_font_label', __( 'Enter a custom font family name.', 'certloom' ) ); }
		if ( ! in_array( $weight, range( 100, 900, 100 ), true ) ) { return new \WP_Error( 'certloom_font_weight', __( 'Choose a valid font weight.', 'certloom' ) ); }
		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) { return new \WP_Error( 'certloom_font_upload', __( 'The custom font upload was not received safely.', 'certloom' ) ); }
		$size = filesize( $file['tmp_name'] );
		if ( false === $size || $size < 12 || $size > self::MAX_BYTES ) { return new \WP_Error( 'certloom_font_size', __( 'Custom font files must be smaller than 8 MB.', 'certloom' ) ); }
		$head = file_get_contents( $file['tmp_name'], false, null, 0, 4 );
		$format = "OTTO" === $head ? 'otf' : ( "\x00\x01\x00\x00" === $head || 'true' === $head || 'typ1' === $head ? 'ttf' : '' );
		if ( '' === $format ) { return new \WP_Error( 'certloom_font_format', __( 'Upload a valid TTF or OTF font file.', 'certloom' ) ); }
		$hash = hash_file( 'sha256', $file['tmp_name'] );
		if ( ! is_string( $hash ) || ! preg_match( '/^[a-f0-9]{64}$/', $hash ) ) { return new \WP_Error( 'certloom_font_hash', __( 'The custom font could not be verified.', 'certloom' ) ); }
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) { return new \WP_Error( 'certloom_font_storage', __( 'WordPress font storage is unavailable.', 'certloom' ) ); }
		$dir = rtrim( (string) $uploads['basedir'], '/\\' ) . DIRECTORY_SEPARATOR . self::SUBDIR;
		if ( ! wp_mkdir_p( $dir ) || ! is_dir( $dir ) ) { return new \WP_Error( 'certloom_font_storage', __( 'Certloom could not create local font storage.', 'certloom' ) ); }
		$name = $hash . '.' . $format;
		$dest = $dir . DIRECTORY_SEPARATOR . $name;
		if ( ! is_file( $dest ) && ! @move_uploaded_file( $file['tmp_name'], $dest ) ) { return new \WP_Error( 'certloom_font_move', __( 'Certloom could not store the custom font.', 'certloom' ) ); }
		@chmod( $dest, 0644 );
		$normalized_label = function_exists( 'mb_strtolower' ) ? mb_strtolower( $family_label, 'UTF-8' ) : strtolower( $family_label );
		$family_key = 'custom-' . substr( hash( 'sha256', $normalized_label ), 0, 16 );
		$all = $this->families();
		$current = isset( $all[ $family_key ] ) ? $all[ $family_key ] : array( 'label' => $family_label, 'faces' => array() );
		$current['label'] = $family_label;
		$current['faces'][ $weight . '-' . $style ] = array( 'sha256' => $hash, 'format' => $format, 'file' => $name );
		$all[ $family_key ] = $current;
		update_option( self::OPTION, $all, false );
		$defs = $this->designer_definitions();
		return isset( $defs[ $family_key ] ) ? array( 'key' => $family_key, 'definition' => $defs[ $family_key ] ) : new \WP_Error( 'certloom_font_store', __( 'The custom font could not be indexed.', 'certloom' ) );
	}
}
