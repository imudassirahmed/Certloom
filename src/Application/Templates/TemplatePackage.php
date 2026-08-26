<?php
namespace Certloom\Application\Templates;

use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Application\Rendering\LayoutValidator;
use Certloom\Domain\Rendering\LayoutSchema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Portable, renderer-independent Certloom template package contract. */
final class TemplatePackage {
	const FORMAT = 'certloom-template';
	const VERSION = 1;
	const MAX_BYTES = 2 * 1024 * 1024;

	/** @var LayoutValidator */
	private $layouts;
	/** @var CanonicalJson */
	private $json;

	public function __construct( ?LayoutValidator $layouts = null, ?CanonicalJson $json = null ) {
		$this->layouts = $layouts ?: new LayoutValidator();
		$this->json    = $json ?: new CanonicalJson();
	}

	/** @return array<string,mixed>|\WP_Error */
	public function build( array $template, array $revision, $plugin_version ) {
		if ( empty( $template['machine_key'] ) || empty( $template['name'] ) || empty( $revision['layout'] ) || ! is_array( $revision['layout'] ) ) {
			return new \WP_Error( 'certloom_template_export_incomplete', __( 'The template is missing data required for export.', 'certloom' ) );
		}
		$machine_key = (string) $template['machine_key'];
		$name = trim( (string) $template['name'] );
		$description = (string) ( $template['description'] ?? '' );
		if ( sanitize_key( $machine_key ) !== $machine_key || '' === $machine_key || strlen( $machine_key ) > 191 || '' === $name || $this->length( $name ) > 255 || $this->length( $description ) > 20000 ) {
			return new \WP_Error( 'certloom_template_export_identity_invalid', __( 'The template identity is invalid and cannot be exported.', 'certloom' ) );
		}
		try {
			$layout = $this->layouts->normalize( $revision['layout'] );
			$hash   = $this->json->hash( $layout );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'certloom_template_export_layout_invalid', __( 'The template layout is invalid and cannot be exported.', 'certloom' ) );
		}
		return array(
			'format'         => self::FORMAT,
			'format_version' => self::VERSION,
			'plugin_version' => (string) $plugin_version,
			'template'       => array(
				'machine_key' => $machine_key,
				'name'        => $name,
				'description' => $description,
			),
			'revision'       => array(
				'layout_schema'         => LayoutSchema::NAME,
				'layout_schema_version' => (int) $layout['_meta']['schema_version'],
				'layout_sha256'         => $hash,
				'layout'                => $layout,
			),
		);
	}

	/** @return string|\WP_Error */
	public function encode( array $package ) {
		try {
			$json = $this->json->encode( $package );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'certloom_template_export_encode_failed', __( 'Certloom could not encode the template package safely.', 'certloom' ) );
		}
		return strlen( $json ) > self::MAX_BYTES
			? new \WP_Error( 'certloom_template_export_too_large', __( 'The template package exceeds Certloom’s safe export limit.', 'certloom' ) )
			: $json;
	}

	/** @return array<string,mixed>|\WP_Error */
	public function decode( $json ) {
		$json = (string) $json;
		if ( '' === trim( $json ) || strlen( $json ) > self::MAX_BYTES ) {
			return new \WP_Error( 'certloom_template_import_size_invalid', __( 'The template package is empty or exceeds Certloom’s safe import limit.', 'certloom' ) );
		}
		$package = json_decode( $json, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $package ) ) {
			return new \WP_Error( 'certloom_template_import_json_invalid', __( 'The template package is not valid JSON.', 'certloom' ) );
		}
		$keys = array_keys( $package ); sort( $keys );
		if ( array( 'format', 'format_version', 'plugin_version', 'revision', 'template' ) !== $keys
			|| self::FORMAT !== (string) ( $package['format'] ?? '' )
			|| self::VERSION !== absint( $package['format_version'] ?? 0 )
			|| ! is_array( $package['template'] ?? null )
			|| ! is_array( $package['revision'] ?? null ) ) {
			return new \WP_Error( 'certloom_template_import_format_invalid', __( 'This is not a supported Certloom template package.', 'certloom' ) );
		}
		$template = $package['template'];
		$revision = $package['revision'];
		$tkeys = array_keys( $template ); sort( $tkeys );
		$rkeys = array_keys( $revision ); sort( $rkeys );
		if ( array( 'description', 'machine_key', 'name' ) !== $tkeys
			|| array( 'layout', 'layout_schema', 'layout_schema_version', 'layout_sha256' ) !== $rkeys ) {
			return new \WP_Error( 'certloom_template_import_contract_invalid', __( 'The template package contains unsupported or missing fields.', 'certloom' ) );
		}
		$machine_key = sanitize_key( (string) $template['machine_key'] );
		$name        = trim( (string) $template['name'] );
		$description = (string) $template['description'];
		if ( '' === $machine_key || $machine_key !== (string) $template['machine_key'] || strlen( $machine_key ) > 191 || '' === $name || $this->length( $name ) > 255 || $this->length( $description ) > 20000 ) {
			return new \WP_Error( 'certloom_template_import_identity_invalid', __( 'The template package contains an invalid template identity.', 'certloom' ) );
		}
		$package_schema_version = absint( $revision['layout_schema_version'] );
		if ( LayoutSchema::NAME !== (string) $revision['layout_schema'] || ! LayoutSchema::supports_version( $package_schema_version ) || ! is_array( $revision['layout'] ) || ! preg_match( '/^[a-f0-9]{64}$/', strtolower( (string) $revision['layout_sha256'] ) ) ) {
			return new \WP_Error( 'certloom_template_import_revision_invalid', __( 'The template package contains an unsupported revision contract.', 'certloom' ) );
		}
		try {
			$layout = $this->layouts->normalize( $revision['layout'] );
			$hash   = $this->json->hash( $layout );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'certloom_template_import_layout_invalid', __( 'The imported template layout does not satisfy Certloom’s canonical layout contract.', 'certloom' ) );
		}
		if ( LayoutSchema::NAME !== (string) $layout['_meta']['schema'] || $package_schema_version !== (int) $layout['_meta']['schema_version'] ) {
			return new \WP_Error( 'certloom_template_import_schema_mismatch', __( 'The imported template package metadata does not match the canonical layout schema version.', 'certloom' ) );
		}
		if ( ! hash_equals( strtolower( (string) $revision['layout_sha256'] ), $hash ) ) {
			return new \WP_Error( 'certloom_template_import_hash_mismatch', __( 'The imported template layout does not match its recorded integrity hash.', 'certloom' ) );
		}
		return array(
			'machine_key' => $machine_key,
			'name' => $name,
			'description' => $description,
			'layout' => $layout,
			'layout_sha256' => $hash,
		);
	}

	private function length( $value ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $value, 'UTF-8' ) : strlen( (string) $value );
	}
}
