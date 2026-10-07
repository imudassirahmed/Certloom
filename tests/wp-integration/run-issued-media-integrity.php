<?php
/** Real WordPress attachment + stored snapshot + bundled engines, with rollback-only records. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
$root = getenv( 'CERTLOOM_TEST_WP_ROOT' ); $backup = getenv( 'CERTLOOM_TEST_DB_BACKUP' );
if ( 'C:\\Users\\Admin\\Local Sites\\ecapla-test-wp-site\\app\\public' !== $root || ! $backup || ! is_file( $backup ) || filesize( $backup ) < 1024 ) { throw new RuntimeException( 'Exact disposable Local root and database snapshot required.' ); }
define( 'DISABLE_WP_CRON', true ); if ( ! defined( 'WP_HTTP_BLOCK_EXTERNAL' ) ) { define( 'WP_HTTP_BLOCK_EXTERNAL', true ); }
require $root . '/wp-load.php';
if ( 'local' !== wp_get_environment_type() || 'http://ecapla.local' !== untrailingslashit( home_url() ) || 'local' !== DB_NAME || 10006 !== (int) $wpdb->get_var( 'SELECT @@port' ) ) { throw new RuntimeException( 'Refusing another site/database.' ); }
use Certloom\Application\Contract\ArtifactAuthorityGateInterface;
use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Application\Rendering\CredentialArtifactService;
use Certloom\Application\Rendering\LayoutAssetManifest;
use Certloom\Application\Rendering\LayoutValidator;
use Certloom\Application\Rendering\RendererRegistry;
use Certloom\Infrastructure\Database\Transaction;
use Certloom\Infrastructure\Repository\CredentialRepository;
use Certloom\Infrastructure\Repository\RecipientRepository;
use Certloom\Infrastructure\Rendering\ProductionRendererRuntime;
use Certloom\Infrastructure\Rendering\WordPressLocalAssetResolver;
final class IssuedMediaProbeAuthority implements ArtifactAuthorityGateInterface {
	public function artifact_status( $format, RendererRegistry $registry ) { return array( 'accepted' => true, 'reason' => 'artifact_authorized' ); }
	public function can_render( $format, RendererRegistry $registry ) { return true; }
}
$checks = 0; $attachment = 0; $path = tempnam( sys_get_temp_dir(), 'certloom-live-media-' );
if ( ! $path ) { throw new RuntimeException( 'Cannot create owned media fixture.' ); }
function live_media_check( $ok, $label ) { global $checks; $checks++; if ( ! $ok ) { throw new RuntimeException( $label ); } echo "PASS  $label\n"; }
function live_media_ok( $value, $label ) { live_media_check( ! is_wp_error( $value ), $label . ( is_wp_error( $value ) ? ': ' . $value->get_error_code() : '' ) ); return $value; }
function live_media_digest() {
	global $wpdb;
	$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'certloom_' ) . '%' ) ); $rows = array();
	foreach ( $tables as $table ) { $entries = array_map( 'serialize', $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i', $table ), ARRAY_A ) ); sort( $entries, SORT_STRING ); $rows[ $table ] = $entries; }
	$rows['generation'] = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name=%s', $wpdb->options, Certloom\Infrastructure\Data\DomainGeneration::OPTION ) );
	return hash( 'sha256', serialize( $rows ) );
}
$before = live_media_digest(); $previous_user = get_current_user_id(); $admin = get_user_by( 'login', 'admin' );
if ( ! $admin ) { throw new RuntimeException( 'Existing administrator required.' ); }
wp_set_current_user( $admin->ID ); $actor = get_current_user_id();
try {
	$image = imagecreatetruecolor( 2, 2 ); imagefill( $image, 0, 0, 0x112233 ); imagepng( $image, $path );
	$result = ( new Transaction() )->run( static function () use ( $path, $actor, $image, &$attachment ) {
		try {
		global $wpdb;
		$attachment = live_media_ok( wp_insert_attachment( array( 'post_title' => 'Certloom rollback-only media integrity probe', 'post_mime_type' => 'image/png', 'post_status' => 'inherit' ), false, 0, true ), 'Create rollback-only WordPress attachment' );
		// WordPress unslashes post metadata; preserve literal Windows separators.
		live_media_check( (bool) update_attached_file( $attachment, wp_slash( $path ) ), 'Bind exact owned local test file' );
		live_media_check( realpath( get_attached_file( $attachment, true ) ) === realpath( $path ), 'WordPress resolves the exact owned file' );
		$repository = new CredentialRepository(); $recipients = new RecipientRepository();
		$recipient = live_media_ok( $recipients->find_by_wp_user_id( $actor ), 'Read explicit recipient ownership' );
		$recipient_id = is_array( $recipient ) ? $recipient['id'] : live_media_ok( $recipients->create( array( 'display_name' => 'Media integrity probe', 'wp_user_id' => $actor ) ), 'Create rollback-only linked recipient' );
		$series_id = live_media_ok( $repository->create_series( array( 'recipient_id' => $recipient_id, 'visibility' => 'recipient_only' ) ), 'Create rollback-only credential' );
		$series = live_media_ok( $repository->find_series( $series_id ), 'Read generated public identity' ); $id = $series['public_id'];
		$layout = json_decode( file_get_contents( dirname( __DIR__ ) . '/fixtures/rendering/english.json' ), true );
		$layout['elements'][] = array( 'id' => 'f1111111-1111-4111-8111-111111111111', 'type' => 'image', 'x_mm' => 10, 'y_mm' => 10, 'width_mm' => 12, 'height_mm' => 12, 'z' => 11, 'rotation_deg' => 0, 'opacity' => 1, 'fit' => 'contain', 'asset' => 'attachment:' . $attachment );
		$layout = ( new LayoutValidator() )->normalize( $layout ); $merge = array( 'recipient.name' => 'Media integrity probe' ); $json = new CanonicalJson();
		$media = ( new LayoutAssetManifest() )->capture( $layout, $merge, new WordPressLocalAssetResolver() );
		$number = 'MEDIA-PROBE-' . bin2hex( random_bytes( 6 ) );
		$snapshot = array( 'presentation' => array( 'mode' => 'design' ), 'template' => array( 'layout' => $layout, 'layout_sha256' => $json->hash( $layout ) ), 'merge' => array( 'values' => $merge, 'sha256' => $json->hash( $merge ) ), 'media' => $media, 'credential' => array( 'series_id' => $series_id, 'public_id' => $id, 'display_number' => $number, 'verification_url' => home_url( '/credential/' . $id ) ) );
		$version = live_media_ok( $repository->create_version( $series_id, array( 'display_number' => $number, 'issuance_state' => 'issued', 'issued_at' => current_time( 'mysql', true ), 'snapshot' => $snapshot, 'source_type' => 'manual_admin' ) ), 'Store immutable snapshot with verified media' );
		live_media_ok( $repository->set_current_version( $series_id, $version ), 'Set current fixture Version' );
		$service = new CredentialArtifactService( $repository, null, new ProductionRendererRuntime( new IssuedMediaProbeAuthority() ) );
		foreach ( array( 'pdf', 'png', 'jpeg' ) as $format ) {
			$artifact = live_media_ok( $service->download( $id, $format, $version, $actor, false ), 'Real ' . strtoupper( $format ) . ' supports unchanged issued WordPress media' );
			live_media_check( $artifact['receipt']['sha256'] === hash( 'sha256', $artifact['bytes'] ), 'Receipt binds complete media-bearing artifact bytes' );
		}
		// Fault-injection mutations are confined to the transaction's owned fixture.
		$private_snapshot = $snapshot; $private_snapshot['recipient'] = array( 'display_name' => 'Public privacy probe' );
		$private_snapshot['template']['layout']['elements'][0]['token'] = 'recipient.email';
		$private_snapshot['template']['layout_sha256'] = $json->hash( $private_snapshot['template']['layout'] );
		$private_snapshot['merge']['values'] = array( 'recipient.email' => 'PRIVATE-CANVAS@example.test' );
		$private_snapshot['merge']['sha256'] = $json->hash( $private_snapshot['merge']['values'] );
		$private_snapshot['fields'] = array( 'contract' => array( 'fields' => array( array( 'key' => 'email', 'label' => 'Email', 'type' => 'text', 'public_exposure' => 'private', 'sensitivity' => 'personal' ) ) ), 'values' => array( 'email' => 'PRIVATE-CANVAS@example.test' ) );
		live_media_check( 1 === $wpdb->query( $wpdb->prepare( 'UPDATE %i SET visibility=%s WHERE id=%d', Certloom\Infrastructure\Database\SchemaManager::table( 'credential_series' ), 'public', $series_id ) ), 'Set owned fixture to a public profile' );
		live_media_check( 1 === $wpdb->query( $wpdb->prepare( 'UPDATE %i SET snapshot_json=%s WHERE id=%d', Certloom\Infrastructure\Database\SchemaManager::table( 'credential_versions' ), wp_json_encode( $private_snapshot ), $version ) ), 'Store private-field fault fixture' );
		wp_set_current_user( 0 );
		$html = Ecapla_CertVerify_Render::normalized_credential_profile( $id );
		live_media_check( false !== strpos( $html, 'Public privacy probe' ), 'Actual WordPress guest profile reads the stored public credential' );
		live_media_check( false === strpos( $html, 'PRIVATE-CANVAS@example.test' ), 'Guest profile HTML does not expose a private field through its certificate canvas' );
		wp_set_current_user( $actor );
		live_media_check( 1 === $wpdb->query( $wpdb->prepare( 'UPDATE %i SET visibility=%s WHERE id=%d', Certloom\Infrastructure\Database\SchemaManager::table( 'credential_series' ), 'recipient_only', $series_id ) ) && 1 === $wpdb->query( $wpdb->prepare( 'UPDATE %i SET snapshot_json=%s WHERE id=%d', Certloom\Infrastructure\Database\SchemaManager::table( 'credential_versions' ), wp_json_encode( $snapshot ), $version ) ), 'Restore owned media fixture before download refusal checks' );
		imagefill( $image, 0, 0, 0x445566 ); imagepng( $image, $path ); clearstatcache( true, $path );
		foreach ( array( 'pdf', 'png', 'jpeg' ) as $format ) {
			$error = $service->download( $id, $format, $version, $actor, false );
			live_media_check( is_wp_error( $error ) && 'certloom_download_design_unavailable' === $error->get_error_code(), 'Replaced attachment bytes are refused for ' . strtoupper( $format ) );
		}
		return new WP_Error( 'issued_media_probe_rollback', 'Intentional rollback of all database fixtures.' );
		} catch ( Throwable $error ) { return new WP_Error( 'issued_media_probe_failure', 'Test fixture failure: ' . $error->getMessage() ); }
	} );
	live_media_check( is_wp_error( $result ) && 'issued_media_probe_rollback' === $result->get_error_code(), 'Fixture transaction rolled back: ' . ( is_wp_error( $result ) ? $result->get_error_message() . ' ' . wp_json_encode( $result->get_error_data() ) : 'unexpected commit' ) );
	live_media_check( $before === live_media_digest(), 'All native rows and generation preserved' );
	live_media_check( 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ID=%d', $wpdb->posts, $attachment ) ) && 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE post_id=%d', $wpdb->postmeta, $attachment ) ), 'WordPress attachment and metadata also rolled back' );
	live_media_check( ! is_file( CERTLOOM_PATH . 'resources/renderer/accepted.json' ), 'CLI-only authority seam did not create release authority' );
} finally {
	if ( $attachment ) { clean_post_cache( $attachment ); wp_cache_delete( $attachment, 'post_meta' ); }
	wp_set_current_user( $previous_user ); if ( is_file( $path ) ) { unlink( $path ); }
}
echo "$checks live checks, 0 failures\n";
