<?php
/** Real raster bytes and the real artifact service; release authority stays closed. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
require __DIR__ . '/bootstrap-autoload.php';
class WP_Error { private $code; public function __construct( $code, $message, $data = null ) { $this->code = $code; } public function get_error_code() { return $this->code; } }
function __( $value ) { return $value; } function absint( $value ) { return abs( (int) $value ); }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_-]/i', '', $value ) ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
function esc_html( $value ) { return esc_attr( $value ); } function esc_attr__( $value ) { return esc_attr( $value ); }
function esc_html__( $value ) { return esc_html( $value ); }
function sanitize_html_class( $value ) { return preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $value ); }
use Certloom\Application\Contract\LocalAssetResolverInterface;
use Certloom\Application\Contract\CredentialQueryRepositoryInterface;
use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Application\Rendering\CredentialArtifactService;
use Certloom\Application\Rendering\LayoutAssetManifest;
use Certloom\Application\Rendering\LayoutValidator;
use Certloom\Application\Rendering\SnapshotAssetResolver;
use Certloom\Domain\Credential\PublicCredentialId;
final class IssuedMediaResolver implements LocalAssetResolverInterface {
	public $path; public $reads = 0;
	public function resolve( $reference ) { $this->reads++; return array( 'path' => $this->path, 'sha256' => hash_file( 'sha256', $this->path ), 'size' => filesize( $this->path ), 'mime' => 'image/png' ); }
}
final class IssuedMediaQuery implements CredentialQueryRepositoryInterface { public $row; public function find_current_by_public_id( $id ) { return $this->row; } }
function issued_media_png( $path, $color ) { $image = imagecreatetruecolor( 2, 2 ); imagefill( $image, 0, 0, $color ); if ( ! imagepng( $image, $path ) ) { throw new RuntimeException( 'Cannot write owned image.' ); } clearstatcache( true, $path ); }
$checks = 0; $failures = 0;
function issued_media_check( $ok, $label ) { global $checks, $failures; $checks++; if ( ! $ok ) { $failures++; } echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . "\n"; }
function issued_media_refused( callable $callback ) { try { $callback(); return false; } catch ( InvalidArgumentException $error ) { return true; } }
$path = tempnam( sys_get_temp_dir(), 'certloom-issued-media-' ); if ( ! $path ) { throw new RuntimeException( 'Owned image fixture unavailable.' ); }
try {
	issued_media_png( $path, 0x112233 ); $original = file_get_contents( $path );
	$assets = new IssuedMediaResolver(); $assets->path = $path; $catalog = new LayoutAssetManifest(); $json = new CanonicalJson();
	$layout = json_decode( file_get_contents( __DIR__ . '/fixtures/rendering/english.json' ), true );
	$image = array( 'id' => 'f1111111-1111-4111-8111-111111111111', 'type' => 'image', 'x_mm' => 10, 'y_mm' => 10, 'width_mm' => 10, 'height_mm' => 10, 'z' => 10, 'rotation_deg' => 0, 'opacity' => 1, 'asset' => 'attachment:7', 'fit' => 'contain' );
	$image['z'] = max( array_column( $layout['elements'], 'z' ) ) + 1;
	$layout['elements'][] = $image; $layout = ( new LayoutValidator() )->normalize( $layout );
	$merge = array( 'recipient.name' => 'Media test' ); $manifest = $catalog->capture( $layout, $merge, $assets );
	issued_media_check( 1 === count( $manifest['assets'] ) && ! isset( $manifest['assets']['attachment:7']['path'] ), 'issuance media fingerprint stores verified bytes and dimensions without host paths' );
	$dynamic = array( 'elements' => array( array( 'type' => 'logo', 'source' => 'issuer.logo' ), array( 'type' => 'image', 'source' => 'issuer.seal' ), array( 'type' => 'signature', 'source' => 'signer.signature' ), $image ) );
	$dynamic_manifest = $catalog->capture( $dynamic, array( 'issuer.logo_asset' => 'attachment:7', 'issuer.seal_asset' => 'attachment:8', 'signer.signature_asset' => 'attachment:9' ), $assets );
	issued_media_check( array( 'attachment:7', 'attachment:8', 'attachment:9' ) === array_keys( $dynamic_manifest['assets'] ), 'fixed images and issuer/signer sources share one deduplicated media contract' );
	issued_media_check( issued_media_refused( static function () use ( $catalog, $dynamic, $assets ) { $catalog->capture( $dynamic, array(), $assets ); } ), 'missing required issuer/signer media is refused before issuance' );
	$query = new IssuedMediaQuery(); $id = ( new PublicCredentialId() )->generate();
	$query->row = array( 'version_id' => 1, 'series_id' => 1, 'public_id' => $id, 'recipient_id' => 1, 'display_number' => 'MEDIA-1', 'visibility' => 'public', 'issuance_state' => 'issued', 'administrative_state' => 'active', 'issued_at' => '2026-01-01', 'snapshot' => array( 'presentation' => array( 'mode' => 'design' ), 'template' => array( 'layout' => $layout, 'layout_sha256' => $json->hash( $layout ) ), 'merge' => array( 'values' => $merge, 'sha256' => $json->hash( $merge ) ), 'media' => $manifest, 'credential' => array( 'public_id' => $id, 'series_id' => 1, 'display_number' => 'MEDIA-1', 'verification_url' => 'https://example.test/credential/' . $id ) ) );
	$service = new CredentialArtifactService( $query, static function () { return array( 'id' => 1 ); }, null, $assets );
	issued_media_check( 'certloom_download_renderer_unavailable' === $service->availability( $id, 1, true )['reason'], 'unchanged issued media passes preparation and respects the closed renderer gate' );
	$document = ( new Certloom\Application\Rendering\LayoutResolver() )->resolve( $layout, $merge, 'https://example.test/credential/' . $id, new SnapshotAssetResolver( $assets, $manifest ) );
	$fonts = ( new Certloom\Application\Rendering\BrowserPreviewFontManifest() )->for_document( $document );
	$plan = ( new Certloom\Application\Rendering\RenderPlanBuilder() )->build( $document, array( 'font_manifest' => $fonts ) );
	$preview = new Certloom\Infrastructure\Rendering\RenderPlanHtmlPreview();
	issued_media_check( false !== strpos( $preview->present( $plan )['html'], 'data:image/png;base64,' ), 'browser preview embeds the verified original image' );
	issued_media_png( $path, 0x445566 );
	issued_media_check( strlen( $original ) === filesize( $path ), 'race fixture replaces an image without changing its byte length' );
	$changed_preview = $preview->present( $plan );
	issued_media_check( false === strpos( $changed_preview['html'], 'data:image/png;base64,' ) && ! empty( $changed_preview['warnings'] ), 'browser preview rechecks byte identity after planning and refuses same-length replacement' );
	issued_media_check( 'certloom_download_design_unavailable' === $service->availability( $id, 1, true )['reason'], 'same attachment ID with replaced image bytes cannot change an issued download' );
	file_put_contents( $path, $original ); clearstatcache( true, $path );
	issued_media_check( 'certloom_download_renderer_unavailable' === $service->availability( $id, 1, true )['reason'], 'restoring exact original bytes restores media availability' );
	unset( $query->row['snapshot']['media'] );
	issued_media_check( 'certloom_download_design_unavailable' === $service->availability( $id, 1, true )['reason'], 'older media-bearing snapshots cannot infer provenance from current attachments' );
	$query->row['snapshot']['media'] = $manifest; $query->row['snapshot']['media']['assets']['attachment:7']['size']++;
	issued_media_check( 'certloom_download_design_unavailable' === $service->availability( $id, 1, true )['reason'], 'tampered media metadata fails its own snapshot digest' );
	$bad = $manifest; $bad['assets']['attachment:7']['size'] = (string) $bad['assets']['attachment:7']['size']; $bad['sha256'] = $json->hash( $bad['assets'] );
	issued_media_check( issued_media_refused( static function () use ( $assets, $bad ) { new SnapshotAssetResolver( $assets, $bad ); } ), 'coerced media bounds are refused even with a recomputed digest' );
	$reads = $assets->reads;
	issued_media_check( 'certloom_download_not_found' === $service->availability( $id, 0, true )['reason'] && $reads === $assets->reads, 'unauthenticated requests never resolve private media' );
} finally { unlink( $path ); }
echo "\n$checks checks, $failures failures\n"; exit( $failures ? 1 : 0 );
