<?php
namespace Certloom\Infrastructure\Email;

use Certloom\Application\Design\PortableAssetManifest;
use Certloom\Infrastructure\Media\PortableDesignAssets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Portable, assignment-free package for one reusable Certloom email design. */
final class EmailDesignPackage {
	const FORMAT = 'certloom-email-design';
	const VERSION = 2;
	const LEGACY_VERSION = 1;
	const MAX_BYTES = 18 * 1024 * 1024;

	/** @var EmailDesignCompiler */
	private $compiler;
	/** @var PortableAssetManifest */
	private $assets;

	public function __construct( ?EmailDesignCompiler $compiler = null, ?PortableAssetManifest $assets = null ) {
		$this->compiler = $compiler ?: new EmailDesignCompiler();
		$this->assets   = $assets ?: new PortableAssetManifest();
	}

	/** @return array<string,mixed>|\WP_Error */
	public function build( array $design, $plugin_version = '' ) {
		if ( ! empty( $design['attachments'] ) ) {
			return new \WP_Error( 'certloom_email_design_package_static_attachments', __( 'Static delivery attachments are site-local. Remove them before exporting this email design.', 'certloom' ) );
		}
		$blocks = $this->compiler->sanitize_blocks( $design['blocks'] ?? array() );
		$theme = $this->compiler->normalize_theme( $design['theme'] ?? array() );
		$portable = ( new PortableDesignAssets( $this->assets ) )->prepare_email_export( $blocks, $theme );
		if ( is_wp_error( $portable ) ) {
			return $portable;
		}

		$payload = array(
			'format'         => self::FORMAT,
			'format_version' => self::VERSION,
			'created_by'     => array(
				'product'        => 'Certloom',
				'plugin_version' => sanitize_text_field( (string) $plugin_version ),
			),
			'design'         => array(
				'name'      => (string) ( $design['name'] ?? '' ),
				'purpose'   => sanitize_key( (string) ( $design['purpose'] ?? '' ) ),
				'preheader' => (string) ( $design['preheader'] ?? '' ),
				'subject'   => (string) ( $design['subject'] ?? '' ),
				'text_body' => (string) ( $design['text_body'] ?? '' ),
				'blocks'    => $portable['blocks'],
				'theme'     => $portable['theme'],
			),
			'assets'         => $portable['assets'],
		);
		$payload['sha256'] = $this->hash(
			array(
				'design' => $payload['design'],
				'assets' => $payload['assets'],
			)
		);
		return $payload;
	}

	/** @return string|\WP_Error */
	public function encode( array $package ) {
		$json = wp_json_encode( $package, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) ) {
			return new \WP_Error( 'certloom_email_design_package_encode', __( 'Certloom could not encode the email design package safely.', 'certloom' ) );
		}
		if ( strlen( $json ) > self::MAX_BYTES ) {
			return new \WP_Error( 'certloom_email_design_package_size', __( 'The email design package exceeds Certloom’s safe export limit.', 'certloom' ) );
		}
		return $json;
	}

	/** @return array<string,mixed>|\WP_Error */
	public function decode( $json ) {
		$json = (string) $json;
		if ( '' === trim( $json ) || strlen( $json ) > self::MAX_BYTES ) {
			return new \WP_Error( 'certloom_email_design_package_size', __( 'Choose a Certloom email design package within the supported size limit.', 'certloom' ) );
		}

		$decoded = json_decode( $json, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
			return new \WP_Error( 'certloom_email_design_package_json', __( 'This file is not a valid Certloom email design package.', 'certloom' ) );
		}
		if ( self::FORMAT !== (string) ( $decoded['format'] ?? '' ) ) {
			return new \WP_Error( 'certloom_email_design_package_version', __( 'This email design package uses an unsupported Certloom format.', 'certloom' ) );
		}

		$version = absint( $decoded['format_version'] ?? 0 );
		if ( self::LEGACY_VERSION === $version ) {
			return $this->decode_v1( $decoded );
		}
		if ( self::VERSION !== $version ) {
			return new \WP_Error( 'certloom_email_design_package_version', __( 'This email design package uses an unsupported Certloom format.', 'certloom' ) );
		}
		return $this->decode_v2( $decoded );
	}

	/** @return array<string,mixed>|\WP_Error */
	private function decode_v2( array $decoded ) {
		$keys = array_keys( $decoded );
		sort( $keys, SORT_STRING );
		if ( array( 'assets', 'created_by', 'design', 'format', 'format_version', 'sha256' ) !== $keys ) {
			return new \WP_Error( 'certloom_email_design_package_contract', __( 'This email design package contains unsupported or missing fields.', 'certloom' ) );
		}
		if ( ! is_array( $decoded['design'] ?? null ) || ! is_array( $decoded['assets'] ?? null ) || ! is_array( $decoded['created_by'] ?? null ) ) {
			return new \WP_Error( 'certloom_email_design_package_contract', __( 'This email design package contains unsupported or missing fields.', 'certloom' ) );
		}

		$assets = $this->assets->validate( $decoded['assets'] );
		if ( is_wp_error( $assets ) ) {
			return $assets;
		}

		$recorded_hash = strtolower( (string) ( $decoded['sha256'] ?? '' ) );
		$expected_hash = $this->hash( array( 'design' => $decoded['design'], 'assets' => $assets ) );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $recorded_hash ) || ! hash_equals( $recorded_hash, $expected_hash ) ) {
			return new \WP_Error( 'certloom_email_design_package_integrity', __( 'This email design package failed its integrity check.', 'certloom' ) );
		}

		$design = $this->normalize_design_contract( $decoded['design'], true );
		if ( is_wp_error( $design ) ) {
			return $design;
		}

		$references = $this->email_asset_references( $design['blocks'], $design['theme'] );
		$asset_keys = array_keys( $assets );
		sort( $references, SORT_STRING );
		sort( $asset_keys, SORT_STRING );
		if ( $references !== $asset_keys ) {
			return new \WP_Error(
				'certloom_email_design_package_asset_mismatch',
				__( 'The email design package media manifest does not exactly match the local images used by the design.', 'certloom' )
			);
		}

		$design['assets'] = $assets;
		return $design;
	}

	/** @return array<string,mixed>|\WP_Error */
	private function decode_v1( array $decoded ) {
		$keys = array_keys( $decoded );
		sort( $keys, SORT_STRING );
		if ( array( 'created_by', 'design', 'format', 'format_version', 'sha256' ) !== $keys || ! is_array( $decoded['design'] ?? null ) ) {
			return new \WP_Error( 'certloom_email_design_package_contract', __( 'This email design package contains unsupported or missing fields.', 'certloom' ) );
		}

		$recorded_hash = strtolower( (string) ( $decoded['sha256'] ?? '' ) );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $recorded_hash ) || ! hash_equals( $recorded_hash, $this->hash( $decoded['design'] ) ) ) {
			return new \WP_Error( 'certloom_email_design_package_integrity', __( 'This email design package failed its integrity check.', 'certloom' ) );
		}

		$design = $this->normalize_design_contract( $decoded['design'], false );
		if ( is_wp_error( $design ) ) {
			return $design;
		}
		foreach ( $design['blocks'] as $block ) {
			if ( 'image' === (string) ( $block['type'] ?? '' ) ) {
				return new \WP_Error(
					'certloom_email_design_package_legacy_media',
					__( 'This older email design package contains an image reference that cannot be proven portable. Re-export the design with the current Certloom version and import that new package instead.', 'certloom' )
				);
			}
		}
		$design['assets'] = array();
		return $design;
	}

	/**
	 * @param array<string,mixed> $design
	 * @return array<string,mixed>|\WP_Error
	 */
	private function normalize_design_contract( array $design, $allow_package_assets ) {
		$keys = array_keys( $design );
		sort( $keys, SORT_STRING );
		if ( array( 'blocks', 'name', 'preheader', 'purpose', 'subject', 'text_body', 'theme' ) !== $keys ) {
			return new \WP_Error( 'certloom_email_design_package_contract', __( 'This email design package contains unsupported or missing design fields.', 'certloom' ) );
		}

		$name    = trim( sanitize_text_field( (string) ( $design['name'] ?? '' ) ) );
		$purpose = sanitize_key( (string) ( $design['purpose'] ?? '' ) );
		if ( '' === $name || ! EmailDesignPurposes::is_valid( $purpose ) ) {
			return new \WP_Error( 'certloom_email_design_package_content', __( 'This email design package is missing required design information.', 'certloom' ) );
		}

		$blocks = $allow_package_assets
			? $this->sanitize_portable_blocks( $design['blocks'] ?? array() )
			: $this->compiler->sanitize_blocks( $design['blocks'] ?? array() );
		if ( is_wp_error( $blocks ) ) {
			return $blocks;
		}
		if ( empty( $blocks ) ) {
			return new \WP_Error( 'certloom_email_design_package_blocks', __( 'This email design package does not contain usable content blocks.', 'certloom' ) );
		}

		$theme_input = is_array( $design['theme'] ?? null ) ? $design['theme'] : array();
		$portable_theme_assets = array();
		foreach ( array( 'background_image', 'content_background_image' ) as $key ) { if ( $allow_package_assets && 1 === preg_match( '/^asset:(media-[a-f0-9]{24})$/', (string) ( $theme_input[ $key ] ?? '' ) ) ) { $portable_theme_assets[ $key ] = (string) $theme_input[ $key ]; $theme_input[ $key ] = ''; } }
		$normalized_theme = $this->compiler->normalize_theme( $theme_input );
		foreach ( $portable_theme_assets as $key => $value ) { $normalized_theme[ $key ] = $value; }

		return array(
			'name'      => $name,
			'purpose'   => $purpose,
			'preheader' => sanitize_text_field( (string) ( $design['preheader'] ?? '' ) ),
			'subject'   => sanitize_text_field( (string) ( $design['subject'] ?? '' ) ),
			'text_body' => sanitize_textarea_field( (string) ( $design['text_body'] ?? '' ) ),
			'blocks'    => $blocks,
			'theme'     => $normalized_theme,
			'active'    => 0,
			'source'    => 'import',
		);
	}

	/** @return array<int,array<string,mixed>>|\WP_Error */
	private function sanitize_portable_blocks( $blocks ) {
		$blocks = is_array( $blocks ) ? array_slice( $blocks, 0, EmailDesignCompiler::MAX_BLOCKS ) : array();
		$out = array();
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) { continue; }
			$asset_map = array();
			$placeholder = $this->replace_portable_asset_refs( $block, $asset_map );
			$sanitized = $this->compiler->sanitize_blocks( array( $placeholder ) );
			if ( empty( $sanitized ) ) { continue; }
			$out[] = $this->restore_portable_asset_refs( $sanitized[0], $asset_map );
		}
		return $out;
	}

	/** @return mixed */
	private function replace_portable_asset_refs( $value, array &$asset_map ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) { $out[ $key ] = $this->replace_portable_asset_refs( $item, $asset_map ); }
			return $out;
		}
		if ( is_string( $value ) && 1 === preg_match( '/^asset:(media-[a-f0-9]{24})$/', $value, $match ) ) {
			$placeholder = 'https://certloom.invalid/portable-image/' . $match[1];
			$asset_map[ $placeholder ] = $value;
			return $placeholder;
		}
		return $value;
	}

	/** @return mixed */
	private function restore_portable_asset_refs( $value, array $asset_map ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) { $out[ $key ] = $this->restore_portable_asset_refs( $item, $asset_map ); }
			return $out;
		}
		return is_string( $value ) && isset( $asset_map[ $value ] ) ? $asset_map[ $value ] : $value;
	}

	/** @return string[] */
	private function email_asset_references( array $blocks, array $theme = array() ) {
		$references = array();
		$collect    = null;
		$collect    = static function( $value ) use ( &$references, &$collect ) {
			if ( is_array( $value ) ) { foreach ( $value as $item ) { $collect( $item ); } return; }
			if ( is_string( $value ) && 1 === preg_match( '/^asset:(media-[a-f0-9]{24})$/', $value, $match ) ) { $references[ $match[1] ] = true; }
		};
		$collect( $blocks );
		$collect( $theme['background_image'] ?? '' );
		$collect( $theme['content_background_image'] ?? '' );
		return array_keys( $references );
	}

	private function hash( array $value ) {
		$value = $this->sort_recursive( $value );
		$json  = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return hash( 'sha256', is_string( $json ) ? $json : '' );
	}

	private function sort_recursive( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( ! $is_list ) {
			ksort( $value, SORT_STRING );
		}
		foreach ( $value as $key => $item ) {
			$value[ $key ] = $this->sort_recursive( $item );
		}
		return $value;
	}
}
