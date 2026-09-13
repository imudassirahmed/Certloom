<?php
namespace Certloom\Application\Templates;

use Certloom\Application\Rendering\CanonicalJson;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ZIP-backed portable file format for Certloom certificate templates.
 *
 * `.certloom` carries one template. `.certloom-pack` carries multiple templates.
 * The canonical TemplatePackage contract remains the validation authority; this
 * class only separates JSON/layout metadata from binary assets for portability.
 */
final class TemplateArchive {
	const SINGLE_FORMAT = 'certloom-template-file';
	const PACK_FORMAT   = 'certloom-template-pack';
	const VERSION       = 1;
	const MAX_ARCHIVE_BYTES   = 32 * 1024 * 1024;
	const MAX_EXTRACTED_BYTES = 64 * 1024 * 1024;
	const MAX_ENTRY_BYTES     = 18 * 1024 * 1024;
	const MAX_FILES           = 160;

	/** @var CanonicalJson */
	private $json;

	public function __construct( ?CanonicalJson $json = null ) {
		$this->json = $json ?: new CanonicalJson();
	}

	/** @return true|\WP_Error */
	public function write_single( array $package, $path ) {
		$normalized = $this->normalize_package( $package );
		if ( is_wp_error( $normalized ) ) { return $normalized; }
		return $this->write_archive( $path, array( array( 'prefix' => '', 'package' => $normalized ) ), false );
	}

	/** @return true|\WP_Error */
	public function write_pack( array $packages, $path, $plugin_version = '' ) {
		if ( empty( $packages ) || count( $packages ) > 64 ) {
			return new \WP_Error( 'certloom_template_pack_count_invalid', __( 'Choose between 1 and 64 certificate templates for a Certloom package.', 'certloom' ) );
		}
		$items = array();
		$seen = array();
		foreach ( array_values( $packages ) as $index => $package ) {
			if ( ! is_array( $package ) ) { return new \WP_Error( 'certloom_template_pack_contract_invalid', __( 'A template package could not be prepared for export.', 'certloom' ) ); }
			$normalized = $this->normalize_package( $package );
			if ( is_wp_error( $normalized ) ) { return $normalized; }
			$key = sanitize_key( (string) ( $normalized['template']['machine_key'] ?? '' ) );
			if ( '' === $key ) { $key = 'template-' . ( $index + 1 ); }
			$base = $key; $suffix = 2;
			while ( isset( $seen[ $key ] ) ) { $key = $base . '-' . $suffix; $suffix++; }
			$seen[ $key ] = true;
			$items[] = array( 'prefix' => 'templates/' . $key . '/', 'key' => $key, 'package' => $normalized );
		}
		return $this->write_archive( $path, $items, true, (string) $plugin_version );
	}

	/**
	 * @return array{type:string,packages:array<int,array<string,mixed>>}|\WP_Error
	 */
	public function read( $path ) {
		$path = (string) $path;
		if ( '' === $path || ! is_file( $path ) || ! is_readable( $path ) || is_link( $path ) ) {
			return new \WP_Error( 'certloom_template_archive_missing', __( 'The selected Certloom template file could not be read.', 'certloom' ) );
		}
		$size = filesize( $path );
		if ( false === $size || $size < 1 || $size > self::MAX_ARCHIVE_BYTES ) {
			return new \WP_Error( 'certloom_template_archive_size', __( 'The Certloom template file is empty or exceeds the safe import limit.', 'certloom' ) );
		}
		if ( ! class_exists( '\\PharData' ) ) {
			return new \WP_Error( 'certloom_template_archive_unavailable', __( 'This server cannot open Certloom template archives because PHP PharData is unavailable.', 'certloom' ) );
		}
		try {
			$archive = new \PharData( $path );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'certloom_template_archive_invalid', __( 'This is not a valid Certloom template archive.', 'certloom' ) );
		}
		$file_count = 0;
		$extracted_bytes = 0;
		try {
			$iterator = new \RecursiveIteratorIterator( $archive );
			foreach ( $iterator as $entry ) {
				if ( $entry->isDir() ) { continue; }
				$file_count++;
				if ( $file_count > self::MAX_FILES ) { return new \WP_Error( 'certloom_template_archive_files', __( 'This template archive contains too many files.', 'certloom' ) ); }
				$entry_size = (int) $entry->getSize();
				if ( $entry_size < 0 || $entry_size > self::MAX_ENTRY_BYTES ) {
					return new \WP_Error( 'certloom_template_archive_entry_size', __( 'A file inside this Certloom template archive exceeds the safe extraction limit.', 'certloom' ) );
				}
				$extracted_bytes += $entry_size;
				if ( $extracted_bytes > self::MAX_EXTRACTED_BYTES ) {
					return new \WP_Error( 'certloom_template_archive_extracted_size', __( 'This Certloom template archive expands beyond the safe extraction limit.', 'certloom' ) );
				}
			}
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'certloom_template_archive_invalid', __( 'Certloom could not inspect this template archive safely.', 'certloom' ) );
		}

		$pack_json = $this->entry_content( $archive, 'pack.json' );
		if ( ! is_wp_error( $pack_json ) && '' !== $pack_json ) {
			$pack = json_decode( $pack_json, true );
			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $pack ) || self::PACK_FORMAT !== (string) ( $pack['format'] ?? '' ) || self::VERSION !== absint( $pack['format_version'] ?? 0 ) || ! is_array( $pack['templates'] ?? null ) ) {
				return new \WP_Error( 'certloom_template_pack_invalid', __( 'This is not a supported Certloom template package.', 'certloom' ) );
			}
			if ( empty( $pack['templates'] ) || count( $pack['templates'] ) > 64 ) {
				return new \WP_Error( 'certloom_template_pack_count_invalid', __( 'This Certloom template package contains an unsupported number of templates.', 'certloom' ) );
			}
			$packages = array();
			foreach ( $pack['templates'] as $item ) {
				if ( ! is_array( $item ) || empty( $item['path'] ) || ! is_string( $item['path'] ) ) {
					return new \WP_Error( 'certloom_template_pack_invalid', __( 'This Certloom template package has an invalid template index.', 'certloom' ) );
				}
				$prefix = trim( str_replace( '\\', '/', (string) $item['path'] ), '/' ) . '/';
				if ( false !== strpos( $prefix, '../' ) || 0 !== strpos( $prefix, 'templates/' ) ) {
					return new \WP_Error( 'certloom_template_pack_path_invalid', __( 'This Certloom template package contains an unsafe internal path.', 'certloom' ) );
				}
				$decoded = $this->read_single_from_archive( $archive, $prefix );
				if ( is_wp_error( $decoded ) ) { return $decoded; }
				$packages[] = $decoded;
			}
			return array( 'type' => 'pack', 'packages' => $packages );
		}

		$single = $this->read_single_from_archive( $archive, '' );
		if ( is_wp_error( $single ) ) { return $single; }
		return array( 'type' => 'single', 'packages' => array( $single ) );
	}

	/** @return true|\WP_Error */
	private function write_archive( $path, array $items, $pack, $plugin_version = '' ) {
		if ( ! class_exists( '\\PharData' ) ) {
			return new \WP_Error( 'certloom_template_archive_unavailable', __( 'This server cannot create Certloom template archives because PHP PharData is unavailable.', 'certloom' ) );
		}
		$path = (string) $path;
		if ( '' === $path ) { return new \WP_Error( 'certloom_template_archive_path', __( 'Certloom could not create a temporary template archive.', 'certloom' ) ); }
		if ( file_exists( $path ) ) { @unlink( $path ); }
		try {
			$archive = new \PharData( $path, 0, null, \Phar::ZIP );
			if ( $pack ) {
				$index = array();
				foreach ( $items as $item ) {
					$index[] = array(
						'key' => (string) $item['key'],
						'path' => rtrim( (string) $item['prefix'], '/' ),
						'name' => (string) ( $item['package']['template']['name'] ?? '' ),
					);
				}
				$pack_manifest = array(
					'format' => self::PACK_FORMAT,
					'format_version' => self::VERSION,
					'plugin_version' => (string) $plugin_version,
					'templates' => $index,
				);
				$archive['pack.json'] = $this->json->encode( $pack_manifest );
			}
			foreach ( $items as $item ) {
				$result = $this->write_package_entries( $archive, (string) $item['prefix'], $item['package'] );
				if ( is_wp_error( $result ) ) { unset( $archive ); @unlink( $path ); return $result; }
			}
			unset( $archive );
		} catch ( \Throwable $error ) {
			@unlink( $path );
			return new \WP_Error( 'certloom_template_archive_write_failed', __( 'Certloom could not create the portable template archive.', 'certloom' ) );
		}
		$size = is_file( $path ) ? filesize( $path ) : false;
		if ( false === $size || $size < 1 || $size > self::MAX_ARCHIVE_BYTES ) {
			@unlink( $path );
			return new \WP_Error( 'certloom_template_archive_size', __( 'The generated Certloom template archive exceeds the safe file-size limit.', 'certloom' ) );
		}
		return true;
	}

	/** @return true|\WP_Error */
	private function write_package_entries( \PharData $archive, $prefix, array $package ) {
		$assets = is_array( $package['assets'] ?? null ) ? $package['assets'] : array();
		$asset_manifest = array();
		foreach ( $assets as $key => $asset ) {
			if ( ! is_array( $asset ) ) { return new \WP_Error( 'certloom_template_archive_asset_invalid', __( 'A template image could not be added to the portable archive.', 'certloom' ) ); }
			$bytes = base64_decode( (string) ( $asset['data'] ?? '' ), true );
			if ( false === $bytes ) { return new \WP_Error( 'certloom_template_archive_asset_invalid', __( 'A template image failed portable archive encoding.', 'certloom' ) ); }
			$filename = basename( (string) ( $asset['filename'] ?? '' ) );
			$extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
			$asset_path = 'assets/' . (string) $key . '.' . $extension;
			$archive[ $prefix . $asset_path ] = $bytes;
			$asset_manifest[ (string) $key ] = array(
				'filename' => $filename,
				'mime' => (string) ( $asset['mime'] ?? '' ),
				'sha256' => (string) ( $asset['sha256'] ?? '' ),
				'size' => absint( $asset['size'] ?? 0 ),
				'path' => $asset_path,
			);
		}
		ksort( $asset_manifest, SORT_STRING );
		$manifest = array(
			'format' => self::SINGLE_FORMAT,
			'format_version' => self::VERSION,
			'package_format' => (string) ( $package['format'] ?? '' ),
			'package_format_version' => absint( $package['format_version'] ?? 0 ),
			'plugin_version' => (string) ( $package['plugin_version'] ?? '' ),
			'template' => $package['template'],
			'revision' => array(
				'layout_schema' => (string) ( $package['revision']['layout_schema'] ?? '' ),
				'layout_schema_version' => absint( $package['revision']['layout_schema_version'] ?? 0 ),
				'layout_sha256' => (string) ( $package['revision']['layout_sha256'] ?? '' ),
			),
			'assets' => $asset_manifest,
		);
		$archive[ $prefix . 'manifest.json' ] = $this->json->encode( $manifest );
		$archive[ $prefix . 'design.json' ] = $this->json->encode( $package['revision']['layout'] );
		return true;
	}

	/** @return array<string,mixed>|\WP_Error */
	private function read_single_from_archive( \PharData $archive, $prefix ) {
		$manifest_json = $this->entry_content( $archive, $prefix . 'manifest.json' );
		if ( is_wp_error( $manifest_json ) || '' === $manifest_json ) { return new \WP_Error( 'certloom_template_archive_manifest_missing', __( 'This Certloom template archive is missing its manifest.', 'certloom' ) ); }
		$design_json = $this->entry_content( $archive, $prefix . 'design.json' );
		if ( is_wp_error( $design_json ) || '' === $design_json ) { return new \WP_Error( 'certloom_template_archive_design_missing', __( 'This Certloom template archive is missing its design document.', 'certloom' ) ); }
		$manifest = json_decode( $manifest_json, true );
		$layout = json_decode( $design_json, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $manifest ) || ! is_array( $layout ) ) {
			return new \WP_Error( 'certloom_template_archive_json_invalid', __( 'This Certloom template archive contains invalid metadata.', 'certloom' ) );
		}
		if ( self::SINGLE_FORMAT !== (string) ( $manifest['format'] ?? '' ) || self::VERSION !== absint( $manifest['format_version'] ?? 0 ) || TemplatePackage::FORMAT !== (string) ( $manifest['package_format'] ?? '' ) || TemplatePackage::VERSION !== absint( $manifest['package_format_version'] ?? 0 ) || ! is_array( $manifest['template'] ?? null ) || ! is_array( $manifest['revision'] ?? null ) || ! is_array( $manifest['assets'] ?? null ) ) {
			return new \WP_Error( 'certloom_template_archive_contract_invalid', __( 'This is not a supported Certloom template file.', 'certloom' ) );
		}
		$assets = array();
		foreach ( $manifest['assets'] as $key => $asset ) {
			if ( ! is_array( $asset ) || empty( $asset['path'] ) ) { return new \WP_Error( 'certloom_template_archive_asset_invalid', __( 'This Certloom template archive contains invalid image metadata.', 'certloom' ) ); }
			$relative = str_replace( '\\', '/', (string) $asset['path'] );
			if ( 0 !== strpos( $relative, 'assets/' ) || false !== strpos( $relative, '../' ) ) { return new \WP_Error( 'certloom_template_archive_asset_path', __( 'This Certloom template archive contains an unsafe image path.', 'certloom' ) ); }
			$bytes = $this->entry_content( $archive, $prefix . $relative );
			if ( is_wp_error( $bytes ) || '' === $bytes ) { return new \WP_Error( 'certloom_template_archive_asset_missing', __( 'An image referenced by this Certloom template is missing from the archive.', 'certloom' ) ); }
			$assets[ (string) $key ] = array(
				'filename' => basename( (string) ( $asset['filename'] ?? '' ) ),
				'mime' => (string) ( $asset['mime'] ?? '' ),
				'sha256' => (string) ( $asset['sha256'] ?? '' ),
				'size' => absint( $asset['size'] ?? 0 ),
				'data' => base64_encode( $bytes ),
			);
		}
		$package = array(
			'format' => TemplatePackage::FORMAT,
			'format_version' => TemplatePackage::VERSION,
			'plugin_version' => (string) ( $manifest['plugin_version'] ?? '' ),
			'template' => $manifest['template'],
			'revision' => array(
				'layout_schema' => (string) ( $manifest['revision']['layout_schema'] ?? '' ),
				'layout_schema_version' => absint( $manifest['revision']['layout_schema_version'] ?? 0 ),
				'layout_sha256' => (string) ( $manifest['revision']['layout_sha256'] ?? '' ),
				'layout' => $layout,
			),
			'assets' => $assets,
		);
		return $this->normalize_package( $package );
	}

	/** @return array<string,mixed>|\WP_Error */
	private function normalize_package( array $package ) {
		$encoded = ( new TemplatePackage() )->encode( $package );
		if ( is_wp_error( $encoded ) ) { return $encoded; }
		$decoded = ( new TemplatePackage() )->decode( $encoded );
		if ( is_wp_error( $decoded ) ) { return $decoded; }
		// Rebuild the exact package after decode validation so callers retain plugin metadata.
		return $package;
	}

	/** @return string|\WP_Error */
	private function entry_content( \PharData $archive, $name ) {
		$name = str_replace( '\\', '/', (string) $name );
		if ( '' === $name || 0 === strpos( $name, '/' ) || false !== strpos( $name, '../' ) || false !== strpos( $name, "\0" ) ) {
			return new \WP_Error( 'certloom_template_archive_path_invalid', __( 'This Certloom template archive contains an unsafe internal path.', 'certloom' ) );
		}
		try {
			if ( ! isset( $archive[ $name ] ) || $archive[ $name ]->isDir() ) { return ''; }
			return (string) $archive[ $name ]->getContent();
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'certloom_template_archive_read_failed', __( 'Certloom could not read a file inside this template archive.', 'certloom' ) );
		}
	}
}
