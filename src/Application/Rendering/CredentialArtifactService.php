<?php
namespace Certloom\Application\Rendering;

use Certloom\Application\Contract\CredentialQueryRepositoryInterface;
use Certloom\Application\Contract\LocalAssetResolverInterface;
use Certloom\Application\VisibilityPolicy;
use Certloom\Domain\Credential\PublicCredentialId;
use Certloom\Domain\Credential\StatusService;
use Certloom\Infrastructure\Repository\CredentialRepository;
use Certloom\Infrastructure\Repository\RecipientRepository;
use Certloom\Infrastructure\Rendering\LocalQrEncoder;
use Certloom\Infrastructure\Rendering\LocalCode128Encoder;
use Certloom\Infrastructure\Rendering\ProductionRendererRuntime;
use Certloom\Infrastructure\Rendering\WordPressLocalAssetResolver;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Current, valid credentials only; render immutable issuance data without writes. */
final class CredentialArtifactService {
	private $credentials;
	private $recipient_lookup;
	private $runtime;
	private $assets;
	private $resolver;
	private $historical_assets;

	public function __construct( ?CredentialQueryRepositoryInterface $credentials = null, ?callable $recipient_lookup = null, ?ProductionRendererRuntime $runtime = null, ?LocalAssetResolverInterface $assets = null ) {
		$this->credentials = $credentials ?: new CredentialRepository();
		$this->recipient_lookup = $recipient_lookup ?: array( new RecipientRepository(), 'find_by_wp_user_id' );
		$this->runtime = $runtime ?: new ProductionRendererRuntime();
		$this->assets = $assets ?: new WordPressLocalAssetResolver();
		$this->historical_assets = null === $assets ? array( new \Certloom\Infrastructure\Backup\WholeStateAssetRepository(), 'image' ) : null;
		$this->resolver = new LayoutResolver( null, null, null, new LocalQrEncoder(), new LocalCode128Encoder() );
	}

	/** The caller derives identity/capability from WordPress, never request fields. */
	public function availability( $public_id, $user_id, $can_manage ) {
		$prepared = $this->prepare( $public_id, $user_id, $can_manage );
		if ( is_wp_error( $prepared ) ) { return array( 'formats' => array(), 'reason' => $prepared->get_error_code() ); }
		$formats = array();
		foreach ( array( 'pdf', 'png', 'jpeg' ) as $format ) {
			$status = $this->runtime->status( $format );
			if ( ! empty( $status['accepted'] ) && 'artifact_authorized' === ( $status['reason'] ?? '' ) ) { $formats[] = $format; }
		}
		if ( empty( $formats ) ) { return array( 'formats' => array(), 'reason' => 'certloom_download_renderer_unavailable' ); }
		try {
			$this->runtime->assert_supported( $prepared['document'] );
		} catch ( \Throwable $error ) { return array( 'formats' => array(), 'reason' => 'certloom_download_design_unavailable' ); }
		return array( 'formats' => $formats, 'reason' => 'available', 'version_id' => $prepared['row']['version_id'] );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function download( $public_id, $format, $expected_version_id, $user_id, $can_manage ) {
		if ( ! in_array( $format, array( 'pdf', 'png', 'jpeg' ), true ) ) { return $this->error( 'format_invalid', __( 'Choose PDF, PNG or JPEG.', 'certloom' ), 400 ); }
		$prepared = $this->prepare( $public_id, $user_id, $can_manage );
		if ( is_wp_error( $prepared ) ) { return $prepared; }
		if ( ! $expected_version_id || (int) $expected_version_id !== (int) $prepared['row']['version_id'] ) {
			return $this->error( 'version_changed', __( 'This credential has changed. Refresh the page before downloading.', 'certloom' ), 409 );
		}
		$status = $this->runtime->status( $format );
		if ( empty( $status['accepted'] ) || 'artifact_authorized' !== ( $status['reason'] ?? '' ) ) {
			return $this->error( 'renderer_unavailable', __( 'Certificate downloads are unavailable in this release. Online verification remains available.', 'certloom' ), 503 );
		}
		try {
			$this->runtime->assert_supported( $prepared['document'] );
		} catch ( \Throwable $error ) { return $this->error( 'design_unavailable', __( 'The saved certificate design or its exact fonts are unavailable. Contact the issuer.', 'certloom' ), 409 ); }
		try {
			$result = $this->runtime->components()['coordinator']->render( $format, $prepared['layout_hash'], $prepared['document'] );
		} catch ( \Throwable $error ) { return $this->error( 'render_failed', __( 'The certificate could not be generated. Its saved credential details have not changed.', 'certloom' ), 503 ); }
		// Re-check after expensive work: revocation, relinking, renewal or privacy changes deny delivery.
		$current = $this->prepare( $public_id, $user_id, $can_manage );
		if ( is_wp_error( $current ) ) { return $current; }
		if ( $current['identity'] !== $prepared['identity'] ) { return $this->error( 'version_changed', __( 'This credential has changed. Refresh the page before downloading.', 'certloom' ), 409 ); }
		$extension = 'jpeg' === $format ? 'jpg' : $format;
		$result['filename'] = 'certloom-' . strtolower( (string) $prepared['row']['public_id'] ) . '-v' . (int) $prepared['row']['version_id'] . '.' . $extension;
		$result['mime'] = array( 'pdf' => 'application/pdf', 'png' => 'image/png', 'jpeg' => 'image/jpeg' )[ $format ];
		return $result;
	}

	/** Authentication precedes layout/media resolution and all renderer work. */
	private function prepare( $public_id, $user_id, $can_manage ) {
		$ids = new PublicCredentialId();
		$public_id = $ids->normalize( $public_id );
		if ( ! $user_id || ! $ids->is_valid( $public_id ) ) { return $this->not_found(); }
		$row = $this->credentials->find_current_by_public_id( $public_id );
		if ( is_wp_error( $row ) ) { return $this->error( 'store_unavailable', __( 'Credential downloads are temporarily unavailable.', 'certloom' ), 503 ); }
		if ( ! is_array( $row ) || empty( $row['version_id'] ) ) { return $this->not_found(); }
		if ( ! $can_manage ) {
			$recipient = call_user_func( $this->recipient_lookup, (int) $user_id );
			if ( is_wp_error( $recipient ) ) { return $this->error( 'store_unavailable', __( 'Credential downloads are temporarily unavailable.', 'certloom' ), 503 ); }
			if ( ! is_array( $recipient ) || empty( $recipient['id'] ) || (int) $recipient['id'] !== (int) ( $row['recipient_id'] ?? 0 ) || ! ( new VisibilityPolicy() )->allows_recipient_portal( $row['visibility'] ?? '' ) ) { return $this->not_found(); }
		}
		try { $status = ( new StatusService() )->evaluate( $row ); }
		catch ( \Throwable $error ) { return $this->error( 'state_invalid', __( 'This credential cannot be downloaded safely.', 'certloom' ), 409 ); }
		if ( ! $status->is_valid() ) { return $this->error( 'inactive', __( 'Downloads are available only for currently valid credentials. Use verification to see the current status.', 'certloom' ), 409 ); }
		$snapshot = is_array( $row['snapshot'] ?? null ) ? $row['snapshot'] : array();
		if ( 'design' !== ( $snapshot['presentation']['mode'] ?? '' ) ) { return $this->error( 'no_design', __( 'This credential was issued without a downloadable certificate design.', 'certloom' ), 409 ); }
		try {
			$template = (array) ( $snapshot['template'] ?? array() );
			$merge = (array) ( $snapshot['merge']['values'] ?? array() );
			$layout = ( new LayoutValidator() )->normalize( (array) ( $template['layout'] ?? array() ) );
			$json = new CanonicalJson();
			$hash = $json->hash( $layout );
			if ( ! $json->matches_hash( $layout, (string) ( $template['layout_sha256'] ?? '' ) ) || ! $json->matches_hash( $merge, (string) ( $snapshot['merge']['sha256'] ?? '' ) ) ) { throw new \RuntimeException( 'Snapshot integrity mismatch.' ); }
			$credential = (array) ( $snapshot['credential'] ?? array() );
			if ( (string) ( $credential['public_id'] ?? '' ) !== $public_id || (int) ( $credential['series_id'] ?? 0 ) !== (int) ( $row['series_id'] ?? 0 ) || (string) ( $credential['display_number'] ?? '' ) !== (string) ( $row['display_number'] ?? '' ) ) { throw new \RuntimeException( 'Snapshot identity mismatch.' ); }
			$url = (string) ( $credential['verification_url'] ?? '' );
			if ( ! preg_match( '#^https?://#i', $url ) ) { throw new \RuntimeException( 'Snapshot verification URL unavailable.' ); }
			$assets = new SnapshotAssetResolver( $this->assets, $snapshot['media'] ?? null, $this->historical_assets );
			$document = $this->resolver->resolve( $template['layout'], $merge, $url, $assets );
			$identity = $json->hash( array( 'version' => $row['version_id'], 'recipient' => $row['recipient_id'], 'visibility' => $row['visibility'], 'snapshot' => $snapshot, 'document' => $document ) );
		} catch ( \Throwable $error ) { return $this->error( 'design_unavailable', __( 'The saved certificate design or its local assets are unavailable. Contact the issuer.', 'certloom' ), 409 ); }
		return array( 'row' => $row, 'document' => $document, 'layout_hash' => $hash, 'identity' => $identity );
	}

	private function not_found() { return $this->error( 'not_found', __( 'This credential is unavailable for download.', 'certloom' ), 404 ); }
	private function error( $code, $message, $status ) { return new \WP_Error( 'certloom_download_' . $code, $message, array( 'status' => $status ) ); }
}
