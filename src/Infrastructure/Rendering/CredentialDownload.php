<?php
namespace Certloom\Infrastructure\Rendering;

use Certloom\Application\Rendering\CredentialArtifactService;
use Certloom\Domain\Credential\PublicCredentialId;
use Certloom\Infrastructure\Verification\PublicVerificationGate;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Authenticated, nonce-bound attachment downloads; never persist certificate bytes. */
final class CredentialDownload {
	const ACTION = 'certloom_download_credential';
	private $service;

	public function __construct( ?CredentialArtifactService $service = null ) { $this->service = $service ?: new CredentialArtifactService(); }
	public function register() {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( $this, 'handle' ) );
	}

	/** Availability is rechecked on every request; URLs confer no ownership. */
	public function links( $public_id ) {
		$public_id = ( new PublicCredentialId() )->normalize( $public_id );
		$result = $this->service->availability( $public_id, get_current_user_id(), self::can_manage() );
		$links = array();
		foreach ( (array) ( $result['formats'] ?? array() ) as $format ) {
			$version = (int) ( $result['version_id'] ?? 0 );
			$url = add_query_arg( array( 'action' => self::ACTION, 'credential' => $public_id, 'format' => $format, 'version' => $version ), admin_url( 'admin-post.php' ) );
			$links[] = array( 'format' => $format, 'url' => add_query_arg( '_wpnonce', wp_create_nonce( self::nonce_action( $public_id, $format, $version ) ), $url ) );
		}
		return array( 'links' => $links, 'reason' => $result['reason'] ?? 'unavailable', 'message' => self::message( $result['reason'] ?? '' ) );
	}

	public function handle() {
		if ( ! is_user_logged_in() ) { $this->fail( new \WP_Error( 'certloom_download_not_found', __( 'This credential is unavailable for download.', 'certloom' ), array( 'status' => 404 ) ) ); }
		$public_id = isset( $_GET['credential'] ) && is_string( $_GET['credential'] ) ? ( new PublicCredentialId() )->normalize( wp_unslash( $_GET['credential'] ) ) : '';
		$format = isset( $_GET['format'] ) && is_string( $_GET['format'] ) ? sanitize_key( wp_unslash( $_GET['format'] ) ) : '';
		$version = isset( $_GET['version'] ) && is_scalar( $_GET['version'] ) ? absint( $_GET['version'] ) : 0;
		$nonce = isset( $_GET['_wpnonce'] ) && is_string( $_GET['_wpnonce'] ) ? wp_unslash( $_GET['_wpnonce'] ) : '';
		if ( ! wp_verify_nonce( $nonce, self::nonce_action( $public_id, $format, $version ) ) ) {
			$this->fail( new \WP_Error( 'certloom_download_nonce_invalid', __( 'This download link has expired. Refresh the credential page and try again.', 'certloom' ), array( 'status' => 403 ) ) );
		}
		if ( ! ( new PublicVerificationGate() )->is_ready() ) {
			$this->fail( new \WP_Error( 'certloom_download_store_unavailable', __( 'Credential downloads are temporarily unavailable.', 'certloom' ), array( 'status' => 503 ) ) );
		}
		$result = $this->service->download( $public_id, $format, $version, get_current_user_id(), self::can_manage() );
		if ( is_wp_error( $result ) ) { $this->fail( $result ); }
		if ( ! ( new PublicVerificationGate() )->is_ready() || headers_sent() ) {
			$this->fail( new \WP_Error( 'certloom_download_delivery_unavailable', __( 'The download could not be delivered. Refresh the page and try again.', 'certloom' ), array( 'status' => 503 ) ) );
		}
		nocache_headers();
		header( 'Cache-Control: private, no-store, max-age=0' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Type: ' . $result['mime'] );
		header( 'Content-Disposition: attachment; filename="' . $result['filename'] . '"' );
		header( 'Content-Length: ' . strlen( $result['bytes'] ) );
		echo $result['bytes']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Inspected binary attachment, never HTML.
		exit;
	}

	public static function nonce_action( $public_id, $format, $version ) { return self::ACTION . ':' . $public_id . ':' . $format . ':' . (int) $version; }
	private static function can_manage() { return current_user_can( \Ecapla_CertVerify_Permissions::CAP_VIEW_CREDENTIALS ); }
	private function fail( $error ) {
		$data = (array) $error->get_error_data();
		nocache_headers();
		wp_die( esc_html( $error->get_error_message() ), esc_html__( 'Certificate download', 'certloom' ), array( 'response' => (int) ( $data['status'] ?? 503 ), 'back_link' => true ) );
	}
	private static function message( $reason ) {
		switch ( $reason ) {
			case 'available': return '';
			case 'certloom_download_no_design': return __( 'This credential has no downloadable certificate design.', 'certloom' );
			case 'certloom_download_inactive': return __( 'Downloads are available for currently valid credentials.', 'certloom' );
			case 'certloom_download_design_unavailable': return __( 'The saved design, font or local image is unavailable. Contact the issuer.', 'certloom' );
			default: return __( 'Certificate downloads are unavailable in this release. Online verification remains available.', 'certloom' );
		}
	}
}
