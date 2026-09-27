<?php
namespace Certloom\Application\Templates;

use Certloom\Application\Design\PortableAssetManifest;
use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Application\Rendering\LayoutValidator;
use Certloom\Domain\Rendering\LayoutSchema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Portable, renderer-independent Certloom certificate-design package contract. */
final class TemplatePackage {
	const FORMAT = 'certloom-template';
	const VERSION = 2;
	const LEGACY_VERSION = 1;
	const MAX_BYTES = 18 * 1024 * 1024;

	/** @var LayoutValidator */
	private $layouts;
	/** @var CanonicalJson */
	private $json;
	/** @var PortableAssetManifest */
	private $assets;

	public function __construct( ?LayoutValidator $layouts = null, ?CanonicalJson $json = null, ?PortableAssetManifest $assets = null ) {
		$this->layouts = $layouts ?: new LayoutValidator();
		$this->json    = $json ?: new CanonicalJson();
		$this->assets  = $assets ?: new PortableAssetManifest();
	}

	/**
	 * @param array<string,mixed> $template
	 * @param array<string,mixed> $revision
	 * @param array<string,mixed> $assets
	 * @return array<string,mixed>|\WP_Error
	 */
	public function build( array $template, array $revision, $plugin_version, array $assets = array() ) {
		if ( empty( $template['machine_key'] ) || empty( $template['name'] ) || empty( $revision['layout'] ) || ! is_array( $revision['layout'] ) ) {
			return new \WP_Error( 'certloom_template_export_incomplete', __( 'The design is missing data required for export.', 'certloom' ) );
		}

		$machine_key = (string) $template['machine_key'];
		$name        = trim( (string) $template['name'] );
		$description = (string) ( $template['description'] ?? '' );
		if (
			sanitize_key( $machine_key ) !== $machine_key ||
			'' === $machine_key ||
			strlen( $machine_key ) > 191 ||
			'' === $name ||
			$this->length( $name ) > 255 ||
			$this->length( $description ) > 20000
		) {
			return new \WP_Error( 'certloom_template_export_identity_invalid', __( 'The design identity is invalid and cannot be exported.', 'certloom' ) );
		}

		$assets = $this->assets->validate( $assets );
		if ( is_wp_error( $assets ) ) {
			return $assets;
		}

		try {
			$layout = $this->layouts->normalize( $revision['layout'] );
			$hash   = $this->json->hash( $layout );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'certloom_template_export_layout_invalid', __( 'The design layout is invalid and cannot be exported.', 'certloom' ) );
		}

		$reference_check = $this->validate_portable_asset_references( $layout, $assets );
		if ( is_wp_error( $reference_check ) ) {
			return $reference_check;
		}

		$recommended_profiles = TemplateRecommendationProfiles::normalize( isset( $template['recommended_profiles'] ) && is_array( $template['recommended_profiles'] ) ? $template['recommended_profiles'] : array() );

		$identity = array(
			'machine_key' => $machine_key,
			'name'        => $name,
			'description' => $description,
		);
		if ( ! empty( $recommended_profiles ) ) { $identity['recommended_profiles'] = $recommended_profiles; }

		return array(
			'format'         => self::FORMAT,
			'format_version' => self::VERSION,
			'plugin_version' => (string) $plugin_version,
			'template'       => $identity,
			'revision'       => array(
				'layout_schema'         => LayoutSchema::NAME,
				'layout_schema_version' => (int) $layout['_meta']['schema_version'],
				'layout_sha256'         => $hash,
				'layout'                => $layout,
			),
			'assets'         => $assets,
		);
	}

	/** @return string|\WP_Error */
	public function encode( array $package ) {
		try {
			$json = $this->json->encode( $package );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'certloom_template_export_encode_failed', __( 'Certloom could not encode the design package safely.', 'certloom' ) );
		}
		return strlen( $json ) > self::MAX_BYTES
			? new \WP_Error( 'certloom_template_export_too_large', __( 'The design package exceeds Certloom’s safe export limit.', 'certloom' ) )
			: $json;
	}

	/** @return array<string,mixed>|\WP_Error */
	public function decode( $json ) {
		$json = (string) $json;
		if ( '' === trim( $json ) || strlen( $json ) > self::MAX_BYTES ) {
			return new \WP_Error( 'certloom_template_import_size_invalid', __( 'The design package is empty or exceeds Certloom’s safe import limit.', 'certloom' ) );
		}

		$package = json_decode( $json, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $package ) ) {
			return new \WP_Error( 'certloom_template_import_json_invalid', __( 'The design package is not valid JSON.', 'certloom' ) );
		}

		$version = absint( $package['format_version'] ?? 0 );
		if ( self::LEGACY_VERSION === $version ) {
			return $this->decode_v1( $package );
		}
		if ( self::VERSION !== $version ) {
			return new \WP_Error( 'certloom_template_import_format_invalid', __( 'This is not a supported Certloom certificate design package.', 'certloom' ) );
		}

		$keys = array_keys( $package );
		sort( $keys, SORT_STRING );
		if (
			array( 'assets', 'format', 'format_version', 'plugin_version', 'revision', 'template' ) !== $keys ||
			self::FORMAT !== (string) ( $package['format'] ?? '' ) ||
			! is_array( $package['template'] ?? null ) ||
			! is_array( $package['revision'] ?? null ) ||
			! is_array( $package['assets'] ?? null )
		) {
			return new \WP_Error( 'certloom_template_import_format_invalid', __( 'This is not a supported Certloom certificate design package.', 'certloom' ) );
		}

		$assets = $this->assets->validate( $package['assets'] );
		if ( is_wp_error( $assets ) ) {
			return $assets;
		}

		$decoded = $this->decode_identity_and_revision( $package['template'], $package['revision'] );
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}

		$reference_check = $this->validate_portable_asset_references( $decoded['layout'], $assets );
		if ( is_wp_error( $reference_check ) ) {
			return $reference_check;
		}
		$decoded['assets'] = $assets;
		return $decoded;
	}

	/** @return array<string,mixed>|\WP_Error */
	private function decode_v1( array $package ) {
		$keys = array_keys( $package );
		sort( $keys, SORT_STRING );
		if (
			array( 'format', 'format_version', 'plugin_version', 'revision', 'template' ) !== $keys ||
			self::FORMAT !== (string) ( $package['format'] ?? '' ) ||
			! is_array( $package['template'] ?? null ) ||
			! is_array( $package['revision'] ?? null )
		) {
			return new \WP_Error( 'certloom_template_import_format_invalid', __( 'This is not a supported Certloom certificate design package.', 'certloom' ) );
		}

		$decoded = $this->decode_identity_and_revision( $package['template'], $package['revision'] );
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}
		if ( $this->layout_has_asset_reference( $decoded['layout'] ) ) {
			return new \WP_Error(
				'certloom_template_import_legacy_media',
				__( 'This older certificate design package contains site-local media that cannot be moved safely. Re-export the design with the current Certloom version and import that new package instead.', 'certloom' )
			);
		}
		$decoded['assets'] = array();
		return $decoded;
	}

	/**
	 * @param array<string,mixed> $template
	 * @param array<string,mixed> $revision
	 * @return array<string,mixed>|\WP_Error
	 */
	private function decode_identity_and_revision( array $template, array $revision ) {
		$tkeys = array_keys( $template );
		$rkeys = array_keys( $revision );
		sort( $tkeys, SORT_STRING );
		sort( $rkeys, SORT_STRING );
		$valid_template_keys = array(
			array( 'description', 'machine_key', 'name' ),
			array( 'description', 'machine_key', 'name', 'recommended_profiles' ),
		);
		if (
			! in_array( $tkeys, $valid_template_keys, true ) ||
			array( 'layout', 'layout_schema', 'layout_schema_version', 'layout_sha256' ) !== $rkeys
		) {
			return new \WP_Error( 'certloom_template_import_contract_invalid', __( 'The design package contains unsupported or missing fields.', 'certloom' ) );
		}

		$machine_key = sanitize_key( (string) $template['machine_key'] );
		$name        = trim( (string) $template['name'] );
		$description = (string) $template['description'];
		if (
			'' === $machine_key ||
			$machine_key !== (string) $template['machine_key'] ||
			strlen( $machine_key ) > 191 ||
			'' === $name ||
			$this->length( $name ) > 255 ||
			$this->length( $description ) > 20000
		) {
			return new \WP_Error( 'certloom_template_import_identity_invalid', __( 'The design package contains an invalid design identity.', 'certloom' ) );
		}

		$package_schema_version = absint( $revision['layout_schema_version'] );
		if (
			LayoutSchema::NAME !== (string) $revision['layout_schema'] ||
			! LayoutSchema::supports_version( $package_schema_version ) ||
			! is_array( $revision['layout'] ) ||
			! preg_match( '/^[a-f0-9]{64}$/', strtolower( (string) $revision['layout_sha256'] ) )
		) {
			return new \WP_Error( 'certloom_template_import_revision_invalid', __( 'The design package contains an unsupported revision contract.', 'certloom' ) );
		}

		try {
			$layout = $this->layouts->normalize( $revision['layout'] );
			$hash   = $this->json->hash( $layout );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'certloom_template_import_layout_invalid', __( 'The imported design layout does not satisfy Certloom’s canonical layout contract.', 'certloom' ) );
		}
		if ( LayoutSchema::NAME !== (string) $layout['_meta']['schema'] || $package_schema_version !== (int) $layout['_meta']['schema_version'] ) {
			return new \WP_Error( 'certloom_template_import_schema_mismatch', __( 'The imported design package metadata does not match the canonical layout schema version.', 'certloom' ) );
		}
		if ( ! $this->json->matches_hash( $layout, $revision['layout_sha256'] ) ) {
			return new \WP_Error( 'certloom_template_import_hash_mismatch', __( 'The imported design layout does not match its recorded integrity hash.', 'certloom' ) );
		}

		return array(
			'machine_key'   => $machine_key,
			'name'          => $name,
			'description'   => $description,
			'recommended_profiles' => TemplateRecommendationProfiles::normalize( isset( $template['recommended_profiles'] ) && is_array( $template['recommended_profiles'] ) ? $template['recommended_profiles'] : array() ),
			'layout'        => $layout,
			'layout_sha256' => $hash,
		);
	}

	/** @return true|\WP_Error */
	private function validate_portable_asset_references( array $layout, array $assets ) {
		$references = array();
		$this->collect_asset_references( $layout, $references );
		$asset_keys = array_keys( $assets );
		$ref_keys   = array_keys( $references );
		sort( $asset_keys, SORT_STRING );
		sort( $ref_keys, SORT_STRING );
		if ( $asset_keys !== $ref_keys ) {
			return new \WP_Error(
				'certloom_template_package_asset_mismatch',
				__( 'The certificate design package media manifest does not exactly match the images used by its layout.', 'certloom' )
			);
		}
		return true;
	}

	/** @param array<string,bool> $references */
	private function collect_asset_references( array $value, array &$references ) {
		foreach ( $value as $key => $item ) {
			if ( 'asset' === (string) $key && is_string( $item ) ) {
				if ( 1 === preg_match( '/^asset:(media-[a-f0-9]{24})$/', $item, $match ) ) {
					$references[ $match[1] ] = true;
					continue;
				}
				if ( 1 === preg_match( '/^attachment:[1-9][0-9]*$/', $item ) || 0 === strpos( $item, 'asset:' ) ) {
					$references[ '__unsupported__' ] = true;
				}
			}
			if ( is_array( $item ) ) {
				$this->collect_asset_references( $item, $references );
			}
		}
	}

	private function layout_has_asset_reference( array $layout ) {
		$references = array();
		$this->collect_asset_references( $layout, $references );
		return ! empty( $references );
	}

	private function length( $value ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $value, 'UTF-8' ) : strlen( (string) $value );
	}
}
