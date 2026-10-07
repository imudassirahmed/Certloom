<?php
/** Real Local storage + bundled engines. All database fixtures roll back. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
$root = getenv( 'CERTLOOM_TEST_WP_ROOT' ); $backup = getenv( 'CERTLOOM_TEST_DB_BACKUP' );
if ( $root !== 'C:\\Users\\Admin\\Local Sites\\ecapla-test-wp-site\\app\\public' || ! $backup || ! is_file( $backup ) || filesize( $backup ) < 1024 ) { throw new RuntimeException( 'Exact Local root and recovery snapshot required.' ); }
define( 'DISABLE_WP_CRON', true ); require $root . '/wp-load.php';
if ( wp_get_environment_type() !== 'local' || untrailingslashit( home_url() ) !== 'http://ecapla.local' || DB_NAME !== 'local' || (int) $wpdb->get_var( 'SELECT @@port' ) !== 10006 ) { throw new RuntimeException( 'Refusing another site.' ); }

use Certloom\Application\Contract\ArtifactAuthorityGateInterface;
use Certloom\Application\Contract\CredentialQueryRepositoryInterface;
use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Application\Rendering\CredentialArtifactService;
use Certloom\Application\Rendering\LayoutValidator;
use Certloom\Application\Rendering\RendererRegistry;
use Certloom\Infrastructure\Data\DatabaseOptions;
use Certloom\Infrastructure\Data\DomainGeneration;
use Certloom\Infrastructure\Database\Transaction;
use Certloom\Infrastructure\Database\SchemaManager;
use Certloom\Infrastructure\Repository\CredentialRepository;
use Certloom\Infrastructure\Repository\RecipientRepository;
use Certloom\Infrastructure\Rendering\CredentialDownload;
use Certloom\Infrastructure\Rendering\ProductionRendererRuntime;

// This CLI-only injection exercises delivery plumbing, never release authority.
final class DownloadProbeAuthority implements ArtifactAuthorityGateInterface {
	public function artifact_status( $format, RendererRegistry $registry ) { return array( 'accepted' => true, 'reason' => 'artifact_authorized' ); }
	public function can_render( $format, RendererRegistry $registry ) { return true; }
}
final class DownloadProbeQuery implements CredentialQueryRepositoryInterface {
	private $repository; private $mutate; private $reads = 0;
	public function __construct( $repository, callable $mutate ) { $this->repository = $repository; $this->mutate = $mutate; }
	public function find_current_by_public_id( $id ) { if ( ++$this->reads === 2 ) { call_user_func( $this->mutate ); } return $this->repository->find_current_by_public_id( $id ); }
}
$checks = 0; $failure = '';
function download_check( $ok, $message ) { global $checks, $failure; ++$checks; if ( ! $ok ) { $failure = $message; throw new RuntimeException( $message ); } echo "PASS  $message\n"; }
function download_ok( $value, $message ) { download_check( ! is_wp_error( $value ), $message . ( is_wp_error( $value ) ? ': ' . $value->get_error_code() : '' ) ); return $value; }
function download_error( $value, $code, $message ) { download_check( is_wp_error( $value ) && $value->get_error_code() === 'certloom_download_' . $code, $message ); }
function download_digest() {
	global $wpdb;
	$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'certloom_' ) . '%' ) );
	$all = array();
	foreach ( $tables as $table ) { $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i', $table ), ARRAY_A ); download_check( '' === $wpdb->last_error, 'Read table for preservation check' ); $rows = array_map( 'serialize', $rows ); sort( $rows, SORT_STRING ); $all[ $table ] = $rows; }
	$all['generation'] = DatabaseOptions::read( array( DomainGeneration::OPTION ) );
	return hash( 'sha256', serialize( $all ) );
}
wp_set_current_user( get_user_by( 'login', 'admin' )->ID ); $actor = get_current_user_id();
$before = download_digest();
$result = ( new Transaction() )->run( static function () use ( $actor ) {
	global $wpdb;
	$repository = new CredentialRepository(); $recipients = new RecipientRepository();
	$recipient = download_ok( $recipients->find_by_wp_user_id( $actor ), 'Read explicit WordPress recipient link' );
	$recipient_id = is_array( $recipient ) ? $recipient['id'] : download_ok( $recipients->create( array( 'display_name' => 'Download rollback fixture', 'wp_user_id' => $actor ) ), 'Create rollback-only linked recipient' );
	$series_id = download_ok( $repository->create_series( array( 'recipient_id' => $recipient_id, 'visibility' => 'recipient_only' ) ), 'Create rollback-only private-to-owner credential' );
	$series = download_ok( $repository->find_series( $series_id ), 'Read new Series' );
	$id = $series['public_id']; $number = 'DOWNLOAD-PROBE-' . bin2hex( random_bytes( 6 ) );
	$layout = json_decode( file_get_contents( dirname( __DIR__ ) . '/fixtures/rendering/english.json' ), true );
	$merge = array( 'recipient.name' => 'Download rollback fixture' ); $json = new CanonicalJson();
	$snapshot = array(
		'presentation' => array( 'mode' => 'design' ),
		'template' => array( 'layout' => $layout, 'layout_sha256' => ( new LayoutValidator() )->hash( $layout ) ),
		'credential' => array( 'public_id' => $id, 'series_id' => $series_id, 'display_number' => $number, 'verification_url' => home_url( '/credential/' . $id ) ),
		'merge' => array( 'values' => $merge, 'sha256' => $json->hash( $merge ) ),
	);
	$version_id = download_ok( $repository->create_version( $series_id, array( 'display_number' => $number, 'issuance_state' => 'issued', 'source_type' => 'manual_admin', 'issued_at' => current_time( 'mysql', true ), 'snapshot' => $snapshot ) ), 'Create immutable issuance snapshot' );
	download_ok( $repository->set_current_version( $series_id, $version_id ), 'Point Series to issued Version' );
	$runtime = new ProductionRendererRuntime( new DownloadProbeAuthority() );
	$service = new CredentialArtifactService( $repository, null, $runtime );
	$closed = new CredentialArtifactService( $repository );
	download_error( $closed->download( $id, 'pdf', $version_id, $actor, true ), 'renderer_unavailable', 'Default release gate remains closed with bundled engines' );
	download_error( $service->download( $id, 'pdf', $version_id, 0, false ), 'not_found', 'Anonymous download hides credential existence' );
	download_error( $service->download( $id, 'pdf', $version_id, 2147483000, false ), 'not_found', 'Unlinked account cannot download another recipient credential' );
	download_error( $service->download( $id, 'svg', $version_id, $actor, true ), 'format_invalid', 'Unsupported formats cannot reach engines' );
	download_error( $service->download( $id, 'pdf', $version_id + 1, $actor, true ), 'version_changed', 'Stale Version link cannot select historical or renewed Version' );
	$availability = $service->availability( $id, $actor, false );
	download_check( array( 'pdf', 'png', 'jpeg' ) === $availability['formats'], 'Explicitly linked owner receives all three available formats' );
	foreach ( array( 'pdf', 'png', 'jpeg' ) as $format ) {
		$artifact = download_ok( $service->download( $id, $format, $version_id, $actor, false ), 'Generate real ' . strtoupper( $format ) . ' from stored snapshot' );
		download_check( strlen( $artifact['bytes'] ) > 1000 && $artifact['receipt']['sha256'] === hash( 'sha256', $artifact['bytes'] ), 'Artifact receipt binds exact download bytes' );
		download_check( preg_match( '/^certloom-[a-z0-9]+-v[0-9]+\.(pdf|png|jpg)$/', $artifact['filename'] ), 'Attachment filename contains only server-controlled identity' );
	}
	$links = ( new CredentialDownload( $service ) )->links( ( new \Certloom\Domain\Credential\PublicCredentialId() )->format( $id ) );
	download_check( 3 === count( $links['links'] ), 'Receipt/portal offer available downloads' );
	foreach ( $links['links'] as $link ) {
		parse_str( parse_url( $link['url'], PHP_URL_QUERY ), $query );
		download_check( ! empty( $query['_wpnonce'] ) && false !== wp_verify_nonce( $query['_wpnonce'], CredentialDownload::nonce_action( $id, $query['format'], $version_id ) ), 'Raw URL has valid actor/credential/Version/format-bound nonce' );
		download_check( false === wp_verify_nonce( $query['_wpnonce'], CredentialDownload::nonce_action( $id, $query['format'], $version_id + 1 ) ), 'Nonce cannot authorize another Version' );
	}
	$race = new DownloadProbeQuery( $repository, static function () use ( $repository, $series_id ) { download_ok( $repository->set_administrative_state( $series_id, 'active', 'revoked' ), 'Inject real revocation before delivery recheck' ); } );
	download_error( ( new CredentialArtifactService( $race, null, $runtime ) )->download( $id, 'png', $version_id, $actor, false ), 'inactive', 'Revocation during generation prevents byte delivery' );
	download_ok( $repository->set_administrative_state( $series_id, 'revoked', 'active' ), 'Restore fixture lifecycle inside rollback scope' );
	$wpdb->query( $wpdb->prepare( 'UPDATE %i SET visibility=%s WHERE id=%d', SchemaManager::table( 'credential_series' ), 'private', $series_id ) );
	download_error( $service->download( $id, 'png', $version_id, $actor, false ), 'not_found', 'Private credentials remain administrator-only' );
	download_ok( $service->download( $id, 'png', $version_id, $actor, true ), 'Credential viewer can download private credential' );
	$snapshot['merge']['values']['recipient.name'] = '\u7530\u4e2d';
	$snapshot['merge']['values']['recipient.name'] = json_decode( '"' . $snapshot['merge']['values']['recipient.name'] . '"' );
	$snapshot['merge']['sha256'] = $json->hash( $snapshot['merge']['values'] );
	$wpdb->query( $wpdb->prepare( 'UPDATE %i SET snapshot_json=%s WHERE id=%d', SchemaManager::table( 'credential_versions' ), wp_json_encode( $snapshot ), $version_id ) );
	download_error( $service->download( $id, 'png', $version_id, $actor, true ), 'design_unavailable', 'Actual missing CJK glyphs fail instead of generating missing-character boxes' );
	$available = $service->availability( $id, $actor, true );
	download_check( empty( $available['formats'] ) && 'certloom_download_design_unavailable' === $available['reason'], 'UI suppresses downloads whose exact font cannot represent the saved name' );
	$snapshot['merge']['values']['recipient.name'] = 'Tampered after hashing';
	$wpdb->query( $wpdb->prepare( 'UPDATE %i SET snapshot_json=%s WHERE id=%d', SchemaManager::table( 'credential_versions' ), wp_json_encode( $snapshot ), $version_id ) );
	download_error( $service->download( $id, 'png', $version_id, $actor, true ), 'design_unavailable', 'Stored merge corruption fails before rendering' );
	return new WP_Error( 'download_probe_rollback', 'Intentionally roll back all test fixtures.' );
} );
download_check( is_wp_error( $result ) && 'download_probe_rollback' === $result->get_error_code(), 'Fixture transaction rolled back cleanly: ' . $failure );
download_check( $before === download_digest(), 'All native table rows and generation marker preserved' );
download_check( ! file_exists( CERTLOOM_PATH . 'resources/renderer/accepted.json' ), 'No release authority installed by test injection' );
echo "\n{$checks} live download checks, 0 failures\n";
