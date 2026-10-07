<?php
/** UX-CORE-04 Credly post-issuance destination contract. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', __DIR__ . '/fake-wp/' );
define( 'CERTLOOM_DOMAIN_WRITE_PATHS_READY', false );
define( 'CERTLOOM_DOMAIN_ADMIN_PREVIEW', false );
define( 'CERTLOOM_DOMAIN_DATA_TOOLS_READY', false );
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code, $message, $data;
		public function __construct( $code = '', $message = '', $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; }
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
		public function get_error_data() { return $this->data; }
	}
}
$GLOBALS['cr_options'] = array();
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function __( $v, $d = null ) { return $v; }
function absint( $v ) { return abs( (int) $v ); }
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $v ) ); }
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function sanitize_email( $v ) { return filter_var( trim( (string) $v ), FILTER_VALIDATE_EMAIL ) ? trim( (string) $v ) : ''; }
function current_time( $f, $gmt = false ) { return 'timestamp' === $f ? 1788192000 : '2026-08-31 16:00:00'; }
function wp_json_encode( $v, $flags = 0 ) { return json_encode( $v, $flags ); }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['cr_options'] ) ? $GLOBALS['cr_options'][ $k ] : $d; }
function update_option( $k, $v, $autoload = null ) { $old = get_option( $k, null ); $GLOBALS['cr_options'][ $k ] = $v; return $old !== $v; }
function delete_option( $k ) { unset( $GLOBALS['cr_options'][ $k ] ); return true; }
function wp_salt( $scheme = 'auth' ) { return str_repeat( 'S', 64 ) . $scheme; }
function wp_parse_url( $url ) { return parse_url( $url ); }
function wp_remote_retrieve_response_code( $response ) { return is_array( $response ) ? (int) ( $response['response']['code'] ?? 0 ) : 0; }
function wp_remote_retrieve_body( $response ) { return is_array( $response ) ? (string) ( $response['body'] ?? '' ) : ''; }
function add_action() {}
require_once __DIR__ . '/bootstrap-autoload.php';

use Certloom\Infrastructure\Integration\CredlyClient;
use Certloom\Infrastructure\Integration\CredlyConnectionRepository;
use Certloom\Infrastructure\Integration\CredlyMappingStore;

$pass = 0; $fail = 0;
function cr( $ok, $label ) { global $pass, $fail; if ( $ok ) { $pass++; echo "PASS  $label\n"; } else { $fail++; echo "FAIL  $label\n"; } }
function crerr( $value, $code ) { return is_wp_error( $value ) && $code === $value->get_error_code(); }

$conn = new CredlyConnectionRepository();
cr( empty( $conn->status()['configured'] ), 'Credly begins disconnected' );
cr( crerr( $conn->save( 'not-a-uuid', str_repeat( 't', 32 ), 'sandbox' ), 'certloom_credly_organization_invalid' ), 'invalid organization ID fails closed' );
cr( crerr( $conn->save( '123e4567-e89b-42d3-a456-426614174000', 'short', 'sandbox' ), 'certloom_credly_token_invalid' ), 'short authorization token fails closed' );
$token = 'credly_token_' . str_repeat( 'a', 32 );
cr( true === $conn->save( '123e4567-e89b-42d3-a456-426614174000', $token, 'sandbox' ), 'authorized Credly connection saves' );
$stored = get_option( CredlyConnectionRepository::OPTION, array() );
cr( false === strpos( json_encode( $stored ), $token ) && ! empty( $stored['sealed_token'] ), 'raw Credly token is never stored in WordPress options' );
$status = $conn->status();
cr( ! empty( $status['configured'] ) && 'sandbox' === $status['environment'], 'saved connection reports configured environment' );
$opened = $conn->connection();
cr( is_array( $opened ) && $token === ( $opened['authorization_token'] ?? '' ), 'encrypted token opens only inside connection boundary' );
$fingerprint = (string) ( $opened['configuration_sha256'] ?? '' );
$rotated_token = 'credly_token_' . str_repeat( 'b', 32 );
cr( true === $conn->save( '123e4567-e89b-42d3-a456-426614174000', $rotated_token, 'sandbox' ), 'Credly token can be rotated' );
$opened2 = $conn->connection();
cr( $fingerprint === ( $opened2['configuration_sha256'] ?? '' ), 'token rotation does not manufacture a new destination identity' );
cr( true === $conn->save( '123e4567-e89b-42d3-a456-426614174000', $rotated_token, 'production' ), 'environment can be changed explicitly' );
cr( $fingerprint !== ( $conn->connection()['configuration_sha256'] ?? '' ), 'environment change creates a new reviewed destination identity' );

$map = new CredlyMappingStore();
cr( true === $map->save( array( 'program_id' => 7, 'badge_template_id' => 'template-123', 'suppress_badge_notification_email' => true, 'active' => true ) ), 'Program to Credly badge mapping persists' );
$found = $map->find( 7 );
cr( is_array( $found ) && 'template-123' === ( $found['badge_template_id'] ?? '' ) && ! empty( $found['suppress_badge_notification_email'] ), 'Credly mapping round-trips exact destination policy' );
$map_sha = $map->fingerprint( $found );
cr( is_string( $map_sha ) && 64 === strlen( $map_sha ), 'Credly mapping has deterministic SHA-256 review identity' );
cr( crerr( $map->save( array( 'program_id' => 0, 'badge_template_id' => 'template-123' ) ), 'certloom_credly_program_invalid' ), 'mapping requires Program' );
cr( crerr( $map->save( array( 'program_id' => 8, 'badge_template_id' => 'bad template id' ) ), 'certloom_credly_template_invalid' ), 'unsafe badge-template identity fails closed' );

$captured = array();
$transport = static function( $url, $args ) use ( &$captured ) {
	$captured = array( 'url' => $url, 'args' => $args );
	return array( 'response' => array( 'code' => 201 ), 'body' => json_encode( array( 'data' => array( 'id' => 'badge-abc-123', 'state' => 'pending' ) ) ) );
};
$client = new CredlyClient( $transport );
$result = $client->issue_badge(
	array( 'organization_id' => '123e4567-e89b-42d3-a456-426614174000', 'authorization_token' => $rotated_token, 'environment' => 'production' ),
	array( 'badge_template_id' => 'template-123', 'recipient_email' => 'person@example.test', 'issued_at' => '2026-08-31', 'expires_at' => null, 'issued_to_first_name' => 'Person', 'issued_to_last_name' => 'Example', 'issuer_earner_id' => 'student-1', 'suppress_badge_notification_email' => true )
);
cr( is_array( $result ) && 'badge-abc-123' === ( $result['external_reference'] ?? '' ) && 'pending' === ( $result['state'] ?? '' ), 'wrapped Credly v1 success response is parsed correctly' );
cr( 'https://api.credly.com/v1/organizations/123e4567-e89b-42d3-a456-426614174000/badges' === ( $captured['url'] ?? '' ), 'Credly issue request uses fixed organization badge endpoint' );
cr( 0 === (int) ( $captured['args']['redirection'] ?? -1 ) && 262144 === (int) ( $captured['args']['limit_response_size'] ?? 0 ), 'Credly transport disables redirects and bounds response size' );
cr( 0 === strpos( (string) ( $captured['args']['headers']['Authorization'] ?? '' ), 'Basic ' ), 'Credly authorization uses reviewed Basic-token boundary' );
$sent = json_decode( (string) ( $captured['args']['body'] ?? '' ), true );
cr( is_array( $sent ) && 'person@example.test' === ( $sent['recipient_email'] ?? '' ) && ! empty( $sent['suppress_badge_notification_email'] ), 'Credly request contains only bounded issued-badge fields' );

$uncertain_client = new CredlyClient( static function() { return new WP_Error( 'http_failed', 'network' ); } );
$uncertain = $uncertain_client->issue_badge( array( 'organization_id' => '123e4567-e89b-42d3-a456-426614174000', 'authorization_token' => $rotated_token, 'environment' => 'production' ), array( 'badge_template_id' => 'template-123', 'recipient_email' => 'person@example.test', 'issued_at' => '2026-08-31' ) );
cr( crerr( $uncertain, 'certloom_credly_transport_uncertain' ) && ! empty( $uncertain->get_error_data()['uncertain'] ), 'network failure is uncertain and cannot be blindly retried' );
$server_client = new CredlyClient( static function() { return array( 'response' => array( 'code' => 503 ), 'body' => json_encode( array( 'data' => array( 'message' => 'Unavailable' ) ) ) ); } );
$server = $server_client->issue_badge( array( 'organization_id' => '123e4567-e89b-42d3-a456-426614174000', 'authorization_token' => $rotated_token, 'environment' => 'production' ), array( 'badge_template_id' => 'template-123', 'recipient_email' => 'person@example.test', 'issued_at' => '2026-08-31' ) );
cr( crerr( $server, 'certloom_credly_response_uncertain' ) && ! empty( $server->get_error_data()['uncertain'] ), 'Credly server failure is treated as uncertain after external side effect may have occurred' );
$reject_client = new CredlyClient( static function() { return array( 'response' => array( 'code' => 422 ), 'body' => json_encode( array( 'data' => array( 'message' => 'Validation failed' ) ) ) ); } );
$rejected = $reject_client->issue_badge( array( 'organization_id' => '123e4567-e89b-42d3-a456-426614174000', 'authorization_token' => $rotated_token, 'environment' => 'production' ), array( 'badge_template_id' => 'template-123', 'recipient_email' => 'person@example.test', 'issued_at' => '2026-08-31' ) );
cr( crerr( $rejected, 'certloom_credly_request_rejected' ) && empty( $rejected->get_error_data()['uncertain'] ), 'definite Credly validation rejection is recorded as safe-to-review failure' );

$root = dirname( __DIR__ );
$listener  = file_get_contents( $root . '/src/Infrastructure/Integration/CredlyPublishingListener.php' );
$handler   = file_get_contents( $root . '/src/Application/Integration/CredlyPublishJobHandler.php' );
$delivery  = file_get_contents( $root . '/src/Infrastructure/Repository/DestinationDeliveryRepository.php' );
$schema    = file_get_contents( $root . '/src/Infrastructure/Database/SchemaManager.php' );
$runtime   = file_get_contents( $root . '/src/Infrastructure/Job/JobRuntime.php' );
$jobs      = file_get_contents( $root . '/src/Infrastructure/Job/JobRepository.php' );
$view      = file_get_contents( $root . '/admin/views/integrations.php' );
$auto_view = file_get_contents( $root . '/admin/views/automations.php' );
$admin     = file_get_contents( $root . '/src/Infrastructure/Admin/CredlyIntegrationAdmin.php' );
$plugin    = file_get_contents( $root . '/certloom.php' ) . file_get_contents( $root . '/src/Plugin.php' );
$uninstall = file_get_contents( $root . '/uninstall.php' );
$accept    = file_get_contents( $root . '/src/Infrastructure/Diagnostics/DomainAcceptanceProof.php' );
$stage     = file_get_contents( $root . '/src/Infrastructure/Diagnostics/StagingMigrationRehearsal.php' );
$preflight = file_get_contents( $root . '/src/Infrastructure/Diagnostics/LegacyMigrationPreflightProof.php' );

cr( false !== strpos( $listener, "add_action( 'certloom_credential_issued'" ), 'Credly starts only after canonical credential issuance commits' );
cr( false !== strpos( $listener, "'destination' => 'credly'" ) && false !== strpos( $listener, "JobTypes::CREDLY_PUBLISH" ), 'successful issuance creates one durable Credly destination job' );
cr( false !== strpos( $listener, "hash( 'sha256', 'credly|'" ) && false !== strpos( $schema, 'UNIQUE KEY dedupe_key' ), 'Credly delivery is deterministically deduplicated per immutable Version and destination mapping' );
cr( false !== strpos( $handler, 'STATUS_UNCERTAIN' ) && false !== strpos( $handler, 'Review the Credly account before trying again.' ), 'uncertain Credly delivery is terminal until reviewed' );
cr( false !== strpos( $jobs, 'certloom_credly_publish_uncertain' ) && false !== strpos( $runtime, 'certloom_credly_retry_uncertain_forbidden' ), 'interrupted Credly side effects cannot auto-replay' );
cr( 19 <= (int) \Certloom\Infrastructure\Database\SchemaManager::SCHEMA_VERSION && false !== strpos( $schema, "'destination_deliveries'" ) && false !== strpos( $schema, 'UNIQUE KEY dedupe_key' ), 'schema includes durable deduplicated destination delivery evidence' );
cr( false !== strpos( $accept, "'destination_deliveries'" ) && false !== strpos( $stage, "'destination_deliveries'" ) && false !== strpos( $preflight, "'destination_deliveries'" ), 'real-host and migration diagnostics include the destination-delivery table' );
cr( false !== strpos( $admin, 'CAP_MANAGE_INTEGRATIONS' ) && false !== strpos( $admin, 'check_admin_referer' ), 'Credly admin mutations are capability and nonce protected' );
cr( false !== strpos( $view, "'Connect Credly'" ) && false !== strpos( $view, "'Program → Credly badge'" ) && false !== strpos( $view, "'Credly delivery history'" ), 'Credly has a real connected publishing UI rather than a placeholder' );
cr( false === strpos( $view, "'Connector next'" ), 'obsolete Credly placeholder is removed' );
cr( false !== strpos( $auto_view, "'A person qualifies'" ) && false !== strpos( $auto_view, "'Certloom issues'" ) && false !== strpos( $auto_view, "'Automation runs'" ) && false !== strpos( $auto_view, 'Reusable message content and branding are managed separately in Email designs.' ), 'Automations presents qualification → issuance → follow-up without becoming reusable Email Design authority' );
cr( false !== strpos( $plugin, 'CredlyIntegrationAdmin()' ) && false !== strpos( $plugin, 'CredlyPublishingListener' ), 'plugin boot registers Credly admin and post-issuance runtime boundaries' );
cr( false === strpos( $listener, 'Ecapla_CertVerify_Database' ) && false === strpos( $listener, 'get_user_by' ), 'Credly never creates credential truth or guesses Recipient identity' );
cr( false !== strpos( $uninstall, "'certloom_credly_connection_v1'" ) && false !== strpos( $uninstall, "'certloom_credly_mappings_v1'" ) && false !== strpos( $uninstall, "'certloom_destination_deliveries'" ), 'uninstall removes Credly secrets, mappings and operational delivery history' );
cr( ! file_exists( $root . '/resources/domain/accepted.json' ) && ! file_exists( $root . '/resources/renderer/accepted.json' ), 'Credly implementation manufactures no DB or renderer authority' );
cr( true === $conn->disconnect() && empty( $conn->status()['configured'] ), 'Credly disconnect removes encrypted connection state' );
cr( true === $map->delete( 7 ) && null === $map->find( 7 ), 'Credly mapping deletes cleanly' );

echo "\n{$pass} UX-CORE-04 Credly destination checks, {$fail} failures\n";
exit( $fail ? 1 : 0 );
