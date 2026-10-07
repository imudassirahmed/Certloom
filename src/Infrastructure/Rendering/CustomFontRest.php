<?php
namespace Certloom\Infrastructure\Rendering;

use Certloom\Application\Rendering\CustomFontLibrary;
use Certloom\Application\Rendering\FontAssetInspector;
use Certloom\Application\Backup\WholeStateOptionValue;
use Certloom\Application\Backup\WholeStateOptionRecord;
use Certloom\Infrastructure\Backup\WholeStateAssetRepository;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Exact bytes for indexed public design fonts, including recovered originals. */
final class CustomFontRest {
	const ROUTE = '/certloom/v1/design-fonts/';
	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_filter( 'rest_pre_serve_request', array( $this, 'serve' ), 10, 4 );
	}
	public function routes() {
		register_rest_route( 'certloom/v1', '/design-fonts/(?P<sha256>[a-f0-9]{64})\.(?P<format>ttf|otf)', array( 'methods' => \WP_REST_Server::READABLE, 'permission_callback' => '__return_true', 'callback' => array( $this, 'read' ) ) );
	}
	/** Font files were already public under uploads; other private assets are never addressable. */
	public function read( $request ) {
		global $wpdb;
		$sha = $request->get_param( 'sha256' ); $format = $request->get_param( 'format' );
		if ( ! is_string( $sha ) || ! preg_match( '/^[a-f0-9]{64}$/D', $sha ) || ! in_array( $format, array( 'ttf', 'otf' ), true ) ) { return $this->unavailable(); }
		$wpdb->last_error = '';
		$stored = $wpdb->get_row( $wpdb->prepare( 'SELECT CASE WHEN OCTET_LENGTH(option_value)<=%d THEN option_value ELSE NULL END AS storage FROM %i WHERE option_name=%s', 2097152, $wpdb->options, CustomFontLibrary::OPTION ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $stored ) || ! is_string( $stored['storage'] ?? null ) ) { return $this->unavailable(); }
		$record = ( new WholeStateOptionRecord() )->from_storage( $stored['storage'] );
		if ( is_wp_error( $record ) ) { return $this->unavailable(); }
		$decoded = ( new WholeStateOptionValue() )->decode( $record );
		if ( is_wp_error( $decoded ) || ! is_array( $decoded['value'] ) || count( $decoded['value'] ) > 5000 ) { return $this->unavailable(); }
		$families = $decoded['value'];
		$indexed = false; $faces_seen = 0;
		foreach ( $families as $key => $family ) {
			if ( ! is_string( $key ) || ! preg_match( '/^custom-[a-f0-9]{16}$/D', $key ) || ! is_array( $family ) || ! is_array( $family['faces'] ?? null ) ) { return $this->unavailable(); }
			foreach ( $family['faces'] as $variant => $face ) {
				if ( ++$faces_seen > 5000 || ! is_string( $variant ) || ! preg_match( '/^[1-9]00-(?:normal|italic)$/D', $variant ) || ! is_array( $face ) ) { return $this->unavailable(); }
				if ( ( $face['sha256'] ?? null ) === $sha && ( $face['format'] ?? null ) === $format && ( $face['file'] ?? null ) === $sha . '.' . $format ) { $indexed = true; }
			}
		}
		if ( ! $indexed ) { return $this->unavailable(); }
		$asset = ( new WholeStateAssetRepository() )->font( $sha, $format );
		return is_wp_error( $asset ) ? $this->unavailable() : new VerifiedDesignFontResponse( $asset );
	}
	public function serve( $served, $response, $request, $server ) {
		if ( $served || ! $response instanceof VerifiedDesignFontResponse || 0 !== strpos( $request->get_route(), self::ROUTE ) ) { return $served; }
		$asset = $response->asset();
		try {
			$verified = ( new FontAssetInspector() )->inspect( $asset );
			$handle = @fopen( $verified['path'], 'rb' );
			if ( ! $handle ) { throw new \RuntimeException( 'Font is unavailable.' ); }
			try { $bytes = stream_get_contents( $handle, $verified['size'] + 1 ); } finally { fclose( $handle ); }
			if ( ! is_string( $bytes ) || strlen( $bytes ) !== $verified['size'] || ! hash_equals( $verified['sha256'], hash( 'sha256', $bytes ) ) ) { throw new \RuntimeException( 'Font changed.' ); }
			if ( 'HEAD' !== $request->get_method() ) { echo $bytes; } // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Independently verified binary font, never HTML.
		} catch ( \Throwable $error ) {
			if ( ! headers_sent() ) { status_header( 409 ); header( 'Content-Type: application/json; charset=UTF-8' ); header( 'Cache-Control: no-store' ); header_remove( 'Content-Length' ); }
			echo wp_json_encode( array( 'code' => 'certloom_design_font_unavailable', 'message' => __( 'This exact design font is unavailable.', 'certloom' ) ) );
		}
		return true;
	}
	private function unavailable() { return new \WP_Error( 'certloom_design_font_unavailable', __( 'This exact design font is unavailable.', 'certloom' ), array( 'status' => 404 ) ); }
}
