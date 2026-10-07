<?php
namespace Certloom\Application\Rendering;

use Certloom\Infrastructure\Data\DatabaseOptions;
use Certloom\Infrastructure\Data\MaintenanceMutex;
use Certloom\Infrastructure\Database\Transaction;

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
	public function validate_recovery_value( $value ) {
		if ( ! is_array( $value ) || count( $value ) > 5000 || ! \Certloom\Application\Restore\WholeStateOptionRows::same( $value, $this->normalize_families( $value ) ) ) { return new \WP_Error( 'certloom_whole_font_catalog_invalid', __( 'Repair the saved custom font catalog before restoring it.', 'certloom' ) ); }
		$faces = 0; foreach ( $value as $family ) { $faces += count( $family['faces'] ); if ( strlen( $family['label'] ) > 96 || $faces > 5000 ) { return new \WP_Error( 'certloom_whole_font_catalog_invalid', __( 'The saved custom font catalog exceeds the recovery limits.', 'certloom' ) ); } }
		return $value;
	}

	/** @return array<string,array<string,mixed>> */
	public function families() {
		if ( ! function_exists( 'get_option' ) ) { return array(); }
		return $this->normalize_families( get_option( self::OPTION, array() ) );
	}

	private function normalize_families( $value ) {
		if ( ! is_array( $value ) ) { return array(); }
		$out = array();
		foreach ( $value as $key => $family ) {
			$key = strtolower( trim( (string) $key ) );
			if ( ! preg_match( '/^custom-[a-f0-9]{16}$/', $key ) || ! is_array( $family ) || ! is_string( $family['label'] ?? null ) ) { continue; }
			$label = trim( wp_strip_all_tags( (string) ( $family['label'] ?? '' ) ) );
			$faces = isset( $family['faces'] ) && is_array( $family['faces'] ) ? $family['faces'] : array();
			if ( '' === $label || empty( $faces ) ) { continue; }
			$clean_faces = array();
			foreach ( $faces as $variant => $face ) {
				if ( ! preg_match( '/^(?:[1-9]00)-(?:normal|italic)$/', (string) $variant ) || ! is_array( $face ) ) { continue; }
				if ( ! is_string( $face['sha256'] ?? null ) || ! is_string( $face['format'] ?? null ) || ! is_string( $face['file'] ?? null ) ) { continue; }
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
		if ( function_exists( 'rest_url' ) ) { $baseurl = rest_url( 'certloom/v1/design-fonts/' ); }
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
		$style = strtolower( (string) $style );
		if ( '' === $family_label || strlen( $family_label ) > 96 ) { return new \WP_Error( 'certloom_font_label', __( 'Enter a custom font family name.', 'certloom' ) ); }
		if ( ! in_array( $weight, range( 100, 900, 100 ), true ) ) { return new \WP_Error( 'certloom_font_weight', __( 'Choose a valid font weight.', 'certloom' ) ); }
		if ( ! in_array( $style, array( 'normal', 'italic' ), true ) ) { return new \WP_Error( 'certloom_font_style', __( 'Choose normal or italic font style.', 'certloom' ) ); }
		if ( ! isset( $file['error'], $file['tmp_name'] ) || UPLOAD_ERR_OK !== $file['error'] || ! is_string( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) { return new \WP_Error( 'certloom_font_upload', __( 'The custom font upload was not received safely.', 'certloom' ) ); }
		$size = @filesize( $file['tmp_name'] );
		if ( false === $size || $size < 12 || $size > self::MAX_BYTES ) { return new \WP_Error( 'certloom_font_size', __( 'Custom font files must be smaller than 8 MB.', 'certloom' ) ); }
		$head = @file_get_contents( $file['tmp_name'], false, null, 0, 4 );
		$format = "OTTO" === $head ? 'otf' : ( "\x00\x01\x00\x00" === $head || 'true' === $head ? 'ttf' : '' );
		if ( '' === $format ) { return new \WP_Error( 'certloom_font_format', __( 'Upload a valid TTF or OTF font file.', 'certloom' ) ); }
		$hash = @hash_file( 'sha256', $file['tmp_name'] );
		if ( ! is_string( $hash ) || ! preg_match( '/^[a-f0-9]{64}$/', $hash ) ) { return new \WP_Error( 'certloom_font_hash', __( 'The custom font could not be verified.', 'certloom' ) ); }
		try {
			( new FontAssetInspector() )->inspect( array( 'path' => $file['tmp_name'], 'sha256' => $hash, 'format' => $format, 'size' => $size ) );
			// Parse the Unicode map even when the font has no glyph for U+0000.
			( new FontGlyphCoverage() )->has_glyph( $file['tmp_name'], 0 );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'certloom_font_format', __( 'This font has invalid or unsupported font tables. Upload a Unicode TTF or OTF font.', 'certloom' ) );
		}
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) { return new \WP_Error( 'certloom_font_storage', __( 'WordPress font storage is unavailable.', 'certloom' ) ); }
		$dir = rtrim( (string) $uploads['basedir'], '/\\' ) . DIRECTORY_SEPARATOR . self::SUBDIR;
		if ( is_link( $dir ) || ! wp_mkdir_p( $dir ) || ! is_dir( $dir ) || dirname( (string) realpath( $dir ) ) !== realpath( $uploads['basedir'] ) ) { return new \WP_Error( 'certloom_font_storage', __( 'Certloom could not create local font storage.', 'certloom' ) ); }
		$name = $hash . '.' . $format;
		$dest = $dir . DIRECTORY_SEPARATOR . $name;
		$normalized_label = function_exists( 'mb_strtolower' ) ? mb_strtolower( $family_label, 'UTF-8' ) : strtolower( $family_label );
		$family_key = 'custom-' . substr( hash( 'sha256', $normalized_label ), 0, 16 );
		$result = MaintenanceMutex::run( function () use ( $file, $family_key, $family_label, $weight, $style, $hash, $format, $name, $dest, $dir, $size ) {
			return ( new Transaction() )->run( function () use ( $file, $family_key, $family_label, $weight, $style, $hash, $format, $name, $dest, $dir, $size ) {
				$stored = DatabaseOptions::read( array( self::OPTION ) );
				if ( is_wp_error( $stored ) ) { return $stored; }
				$raw = $stored[ self::OPTION ]['exists'] ? $stored[ self::OPTION ]['value'] : array();
				$all = $this->normalize_families( $raw );
				$canonical_raw = $raw; if ( is_array( $canonical_raw ) ) { ksort( $canonical_raw, SORT_STRING ); }
				if ( ! is_array( $raw ) || $all !== $canonical_raw ) { return new \WP_Error( 'certloom_font_index_invalid', __( 'The saved font library needs administrator review before another upload.', 'certloom' ) ); }
				$current = isset( $all[ $family_key ] ) ? $all[ $family_key ] : array( 'label' => $family_label, 'faces' => array() );
				$variant = $weight . '-' . $style;
				if ( isset( $current['faces'][ $variant ] ) && $current['faces'][ $variant ]['sha256'] !== $hash ) { return new \WP_Error( 'certloom_font_face_exists', __( 'This family already has that weight and style. Use a new family name to preserve designs using the original font.', 'certloom' ) ); }
				if ( is_link( $dest ) || ( file_exists( $dest ) && ( ! is_file( $dest ) || @hash_file( 'sha256', $dest ) !== $hash ) ) ) { return new \WP_Error( 'certloom_font_storage_integrity', __( 'The stored font does not match its content identity. Ask an administrator to check font storage.', 'certloom' ) ); }
				if ( ! is_file( $dest ) && ! @move_uploaded_file( $file['tmp_name'], $dest ) ) { return new \WP_Error( 'certloom_font_move', __( 'Certloom could not store the custom font.', 'certloom' ) ); }
				clearstatcache( true, $dest );
				if ( dirname( (string) realpath( $dest ) ) !== realpath( $dir ) || @filesize( $dest ) !== $size || @hash_file( 'sha256', $dest ) !== $hash ) { return new \WP_Error( 'certloom_font_storage_integrity', __( 'The stored font does not match its content identity. Ask an administrator to check font storage.', 'certloom' ) ); }
				@chmod( $dest, 0644 );
				$current['faces'][ $variant ] = array( 'sha256' => $hash, 'format' => $format, 'file' => $name );
				$all[ $family_key ] = $current;
				DatabaseOptions::invalidate( array( self::OPTION ) );
				if ( $all !== $raw && ! update_option( self::OPTION, $all, false ) ) { return new \WP_Error( 'certloom_font_store', __( 'The custom font could not be indexed. Retry the upload.', 'certloom' ) ); }
				$confirmed = DatabaseOptions::read( array( self::OPTION ) );
				return ! is_wp_error( $confirmed ) && $confirmed[ self::OPTION ]['exists'] && $confirmed[ self::OPTION ]['value'] === $all ? true : new \WP_Error( 'certloom_font_store', __( 'The custom font could not be indexed. Retry the upload.', 'certloom' ) );
			} );
		} );
		DatabaseOptions::invalidate( array( self::OPTION ) );
		if ( is_wp_error( $result ) ) { return $result; }
		$defs = $this->designer_definitions();
		return isset( $defs[ $family_key ] ) ? array( 'key' => $family_key, 'definition' => $defs[ $family_key ] ) : new \WP_Error( 'certloom_font_store', __( 'The custom font could not be indexed.', 'certloom' ) );
	}
}
